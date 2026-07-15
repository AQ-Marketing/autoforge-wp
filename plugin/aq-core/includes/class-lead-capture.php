<?php
/**
 * AQ Lead Capture — the engine's handler for `POST /wp-json/aqm/v1/contact`,
 * the endpoint every lead-capture form section posts to, PLUS the AutoForge ->
 * Forms admin screen.
 *
 * Every site gets the same full lead pipeline (generalised from the So Clean
 * build):
 *   - lead-notification email with configurable To / BCC / Subject, sent as a
 *     branded HTML message on EVERY submission;
 *   - optional SMTP delivery (host/port/encryption/login/from) for reliable mail;
 *   - optional push to GoHighLevel (contact upsert + message-as-note) when a
 *     Private Integration Token + Location ID are configured;
 *   - a "send a test email" button and an admin-only "fill with test data" button;
 *   - honeypot + same-origin + field-length + rate-limit abuse protection.
 *
 * SECRETS (SMTP password, GHL token) are stored WRITE-ONLY: never echoed back to
 * the browser, saved to non-autoloaded options, and overridable by wp-config
 * constants (AQ_SMTP_PASS, AQ_GHL_TOKEN, AQ_GHL_LOCATION_ID) which win over the
 * DB value. Success of EITHER channel (GHL or email) means the lead was captured,
 * so a CRM outage never loses a lead.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Lead_Capture {

	const CAP        = 'manage_options';
	const OPTION     = 'aq_forms';       // non-secret settings
	const OPT_SMTP   = 'aq_smtp_pass';   // write-only secret (non-autoloaded)
	const OPT_GHL    = 'aq_ghl_token';   // write-only secret (non-autoloaded)
	const SLUG       = 'aq-forms';
	const GHL_API    = 'https://services.leadconnectorhq.com';
	const GHL_VER    = '2021-07-28';

	/** How long a captured value persists, in seconds — 90 days. */
	const TRACK_COOKIE_TTL    = 7776000;
	/** GHL custom-field index cache lifetime, in seconds — 6 hours. */
	const TRACK_MAP_TTL       = 21600;
	/** Daily cron hook that refreshes the location's custom-field list. */
	const CRON_FIELD_SYNC     = 'aq_ghl_field_sync';
	/** Option storing the last field-sync result: ['time' => ts, 'count' => n]. */
	const OPT_FIELD_SYNC_META = 'aq_ghl_field_sync_meta';

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		add_action('admin_menu', [__CLASS__, 'menu'], 25);
		add_action('admin_post_aq_forms_save', [__CLASS__, 'save']);
		add_action('admin_post_aq_forms_test', [__CLASS__, 'send_test']);
		add_action('admin_post_aq_forms_sync_fields', [__CLASS__, 'manual_sync']);
		add_action('phpmailer_init', [__CLASS__, 'apply_smtp']);
		// Capture ad/UTM attribution into first-party cookies on the front end.
		// Printed client-side (in the footer) so it survives full-page caching and
		// never depends on any individual site's form markup.
		add_action('wp_footer', [__CLASS__, 'print_tracking_capture'], 5);
		// Admin-only "Fill with test data" button. Injected globally in the footer so
		// it lands on EVERY lead form (any current or future form template) without
		// per-template markup — the whole engine, every site. Gated server-side on
		// manage_options + the Forms setting, so its markup/mock data never reach
		// anonymous visitors at all.
		add_action('wp_footer', [__CLASS__, 'print_test_fill'], 20);
		// Canonical lead-form submit + thank-you redirect for the whole fleet. Printed
		// once in the footer; binds any form carrying the `data-aq-lead` marker (opt-in,
		// so a site whose theme still self-handles submission can't double-submit). This
		// is THE single place form success behavior lives — every site built with the
		// engine redirects to the same thank-you page on success. See print_lead_form_handler().
		add_action('wp_footer', [__CLASS__, 'print_lead_form_handler'], 6);
		// Daily refresh of the GHL custom-field list so newly-added fields are
		// picked up automatically. The cron callback + schedule reconciliation.
		add_action(self::CRON_FIELD_SYNC, [__CLASS__, 'cron_sync_fields']);
		add_action('init', [__CLASS__, 'reconcile_field_sync_schedule']);
		// Media library picker for the email-logo field on the Forms screen.
		add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
	}

	/** Enqueue the WP media library on the Forms screen (for the email-logo picker). */
	public static function admin_assets(string $hook): void {
		if (strpos($hook, self::SLUG) !== false) {
			wp_enqueue_media();
		}
	}

	/* ---------------- URL attribution capture ---------------- */

	/** Single first-party cookie that stores captured URL params as JSON. */
	const TRACK_COOKIE = 'aq_trk';

	/** Normalize a param/field token for matching: lowercase, non-alnum → underscore. */
	private static function norm_param(string $s): string {
		$s = strtolower(trim($s));
		$s = preg_replace('/[^a-z0-9]+/', '_', $s);
		return trim((string) $s, '_');
	}

	/**
	 * Print the front-end capture snippet. On every page load it merges EVERY URL
	 * query parameter into one first-party JSON cookie (a fresh non-empty value in
	 * the URL wins; otherwise the stored value persists). The cookie rides along
	 * with the same-origin fetch the form makes, so the REST handler reads it
	 * server side — no hidden fields, no per-site JS, cache-proof.
	 */
	public static function print_tracking_capture(): void {
		if (is_admin() || !apply_filters('aq_lead_tracking_enabled', true)) {
			return;
		}
		$cfg = [
			'cookie' => self::TRACK_COOKIE,
			'maxAge' => self::TRACK_COOKIE_TTL,
		];
		?>
<script>(function(){try{
var C=<?php echo wp_json_encode($cfg); ?>;
var m=document.cookie.match(new RegExp('(?:^|; )'+C.cookie+'=([^;]*)')),store={};
if(m){try{store=JSON.parse(decodeURIComponent(m[1]))||{};}catch(e){store={};}}
var q=new URLSearchParams(location.search),changed=false;
q.forEach(function(v,k){
if(!/^[A-Za-z0-9_\-]{1,40}$/.test(k))return;
v=(v||'').trim();if(!v||v.length>500)return;
if(store[k]!==v){store[k]=v;changed=true;}
});
if(changed){var s=JSON.stringify(store);if(s.length<=3500){
document.cookie=C.cookie+'='+encodeURIComponent(s)+';path=/;max-age='+C.maxAge+';SameSite=Lax'+(location.protocol==='https:'?';Secure':'');
}}
}catch(e){}})();</script>
		<?php
	}

	/**
	 * Resolve the site-wide thank-you redirect target: the Forms `thankyou_url`
	 * setting, root-relative paths kept as-is (so they work on any host). Empty
	 * when unset — the handler then falls back to an inline success message rather
	 * than inventing a URL that might 404. A form's own `data-thankyou` attribute
	 * takes precedence over this in the client script.
	 */
	private static function thankyou_target(): string {
		$ty = trim((string) (self::get_settings()['thankyou_url'] ?? ''));
		if ($ty === '') {
			return '';
		}
		return $ty[0] === '/' ? $ty : esc_url_raw($ty);
	}

	/**
	 * Print the engine's canonical lead-form handler once in the footer. It binds
	 * every form carrying the `data-aq-lead` marker (opt-in — never double-binds a
	 * theme that still self-handles), POSTs the form to the lead endpoint, and on
	 * success sends the visitor to the thank-you page. Redirect precedence:
	 * the form's own `data-thankyou` attribute → the Forms `thankyou_url` setting →
	 * (neither set) an inline success message. On failure the submit button
	 * re-enables and an inline error shows. Honeypot + native validation respected.
	 *
	 * This makes "submit → thank-you page" identical on every site built with the
	 * engine, instead of each theme reimplementing (and drifting on) success behavior.
	 */
	public static function print_lead_form_handler(): void {
		if (is_admin()) {
			return;
		}
		$cfg = [
			'thankyou' => self::thankyou_target(),
			'ok'       => (string) apply_filters('aq_lead_success_message', "Thanks — your request is in. We'll be in touch shortly."),
			'err'      => (string) apply_filters('aq_lead_error_message', "Sorry — that didn't go through. Please try again, or call us directly."),
		];
		?>
<script>(function(){
var CFG=<?php echo wp_json_encode($cfg); ?>;
function bind(form){
if(form.dataset.aqLeadBound)return;form.dataset.aqLeadBound='1';
form.addEventListener('submit',function(e){
e.preventDefault();
var hp=form.querySelector('[name="company_hp"],[name="company_url"]');
if(hp&&hp.value)return; // honeypot tripped — drop silently
if(form.checkValidity&&!form.checkValidity()){if(form.reportValidity)form.reportValidity();return;}
var btn=form.querySelector('[type=submit]'),label=btn?btn.textContent:'';
if(btn){btn.disabled=true;btn.textContent='Sending…';}
var msg=form.querySelector('.form-msg,.form-err,[role=status]');
if(msg){msg.hidden=true;msg.className=(msg.className||'').replace(/\berr\b/,'').trim();}
var action=form.getAttribute('action')||form.getAttribute('data-endpoint')||'';
var nonceEl=form.querySelector('input[name="_wpnonce"]'),nonce=nonceEl?nonceEl.value:'';
var headers={'Accept':'application/json'};if(nonce)headers['X-WP-Nonce']=nonce;
fetch(action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:headers})
.then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};}).catch(function(){return{ok:r.ok,j:null};});})
.then(function(res){
if(res.ok&&(!res.j||res.j.ok!==false)){
var ty=form.getAttribute('data-thankyou')||CFG.thankyou;
if(ty){window.location.assign(ty);return;}
var done=form.parentNode&&form.parentNode.querySelector('.form-done,.js-contact-form-done');
if(done){form.hidden=true;done.hidden=false;if(done.classList)done.classList.remove('hidden');}
else{var d=document.createElement('div');d.className='form-done';d.setAttribute('role','status');d.textContent=CFG.ok;if(form.parentNode)form.parentNode.replaceChild(d,form);}
return;
}
throw new Error('bad_status');
}).catch(function(){
if(btn){btn.disabled=false;btn.textContent=label;}
if(msg){msg.textContent=CFG.err;msg.hidden=false;msg.className=((msg.className||'')+' err').trim();}
else{window.alert(CFG.err);}
});
});
}
function run(){var f=document.querySelectorAll('form[data-aq-lead]');Array.prototype.forEach.call(f,bind);}
if(document.readyState!=='loading')run();else document.addEventListener('DOMContentLoaded',run);
})();</script>
		<?php
	}

	/**
	 * Print the admin-only "Fill with test data" button + its behavior, once per
	 * page, in the footer. Server-gated on manage_options + the Forms `test_button`
	 * setting, so nothing is emitted for anonymous visitors.
	 *
	 * The script finds EVERY lead form on the page — any <form> that posts to the
	 * engine's contact endpoint, matched by its `action` or `data-endpoint` — and
	 * prepends a button that fills the form's fields with the configured test data.
	 * Field matching is by input `name` (with common aliases), so it works on the
	 * engine's `contact_form` section, any client's bespoke form section, and any
	 * future form template, with zero per-template markup. Forms that already carry
	 * their own test-fill button are skipped, so there's never a duplicate.
	 */
	public static function print_test_fill(): void {
		if (is_admin() || !current_user_can(self::CAP)) {
			return;
		}
		$cfg = self::get_settings();
		if (empty($cfg['test_button'])) {
			return;
		}
		// Mock values: the configured test-fill fields, plus safe defaults for the
		// address/website fields the engine form asks for. Kept generic so it fills
		// whatever fields a given form actually has.
		$name  = trim((string) ($cfg['test_name'] ?? 'Test Tester'));
		$parts = preg_split('/\s+/', $name, 2);
		$data  = [
			'name'      => $name,
			'firstName' => $parts[0] ?? 'Test',
			'lastName'  => $parts[1] ?? 'Tester',
			'email'     => (string) ($cfg['test_email'] ?? 'test@example.com'),
			'phone'     => (string) ($cfg['test_phone'] ?? '(555) 123-4567'),
			'business'  => (string) ($cfg['test_business'] ?? 'Test Company'),
			'website'   => 'https://example.com',
			'address'   => '123 Test Street',
			'city'      => 'Woburn',
			'state'     => 'MA',
			'zip'       => '01801',
			'message'   => (string) ($cfg['test_message'] ?? 'TEST submission — please ignore.'),
		];
		?>
<script>(function(){
var DATA=<?php echo wp_json_encode($data); ?>;
// name attr -> DATA key, with common aliases across form templates.
var MAP={
first_name:'firstName',firstname:'firstName',fname:'firstName',
last_name:'lastName',lastname:'lastName',lname:'lastName',
name:'name',fullname:'name',your_name:'name',
email:'email',email_address:'email',
phone:'phone',tel:'phone',telephone:'phone',phone_number:'phone',
company:'business',business:'business',business_name:'business',organization:'business',
website:'website',url:'website',
address:'address',street:'address',address_line1:'address','address-line1':'address',
city:'city',state:'state',region:'state',
zip:'zip',postal_code:'zip',postalcode:'zip',postcode:'zip',
message:'message',comments:'message',note:'message',notes:'message',details:'message'
};
var HONEYPOT=/^(company_hp|company_url|hp)$/i;
function norm(n){return (n||'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');}
function fill(form){
var radios={},checks={};
Array.prototype.forEach.call(form.elements,function(el){
var name=el.name||'';if(!name||HONEYPOT.test(name))return;
var tag=(el.tagName||'').toLowerCase(),type=(el.type||'').toLowerCase();
if(type==='hidden'||type==='submit'||type==='button')return;
if(type==='radio'){(radios[name]=radios[name]||[]).push(el);return;}
if(type==='checkbox'){(checks[name]=checks[name]||[]).push(el);return;}
if(tag==='select'){
if(el.selectedIndex<=0){for(var i=0;i<el.options.length;i++){if(el.options[i].value||i>0){el.selectedIndex=i;break;}}}
el.dispatchEvent(new Event('change',{bubbles:true}));return;
}
// text / email / tel / url / textarea
var key=MAP[norm(name)];
var val=key?DATA[key]:'';
if(!val&&el.required)val=(type==='email')?'test@example.com':(type==='url')?'https://example.com':'Test';
if(val&&!el.value){el.value=val;el.dispatchEvent(new Event('input',{bubbles:true}));}
});
// radios: pick the first option in each group
Object.keys(radios).forEach(function(k){var g=radios[k];if(!g.some(function(r){return r.checked;})){g[0].checked=true;g[0].dispatchEvent(new Event('change',{bubbles:true}));}});
// checkboxes: consent-type -> check; other groups -> check the first
Object.keys(checks).forEach(function(k){var g=checks[k];
if(/consent|agree|terms|privacy|opt/i.test(k)){g.forEach(function(c){c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}));});}
else if(!g.some(function(c){return c.checked;})){g[0].checked=true;g[0].dispatchEvent(new Event('change',{bubbles:true}));}
});
}
function decorate(form){
if(form.dataset.aqTestfill)return;
if(form.querySelector('[data-aq-testfill],[data-testfill],.lead-testfill,#contactFormTestFill'))return; // form ships its own button
form.dataset.aqTestfill='1';
var btn=document.createElement('button');
btn.type='button';btn.setAttribute('data-aq-testfill','1');
btn.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Fill with test data (admin only)';
btn.style.cssText='display:block;width:100%;margin:0 0 16px;padding:9px 14px;background:#0d1014;color:#fff;border:1px dashed #4b5563;border-radius:6px;font:600 13px/1.2 system-ui,-apple-system,sans-serif;cursor:pointer';
btn.addEventListener('click',function(){fill(form);});
form.insertBefore(btn,form.firstChild);
}
function run(){
var forms=document.querySelectorAll('form[action*="aqm/v1/contact"],form[data-endpoint*="aqm/v1/contact"]');
Array.prototype.forEach.call(forms,decorate);
}
if(document.readyState!=='loading')run();else document.addEventListener('DOMContentLoaded',run);
})();</script>
		<?php
	}

	/**
	 * Resolve the captured attribution values for a submission: the JSON cookie
	 * overlaid with the current request's own $_GET (so a submit on the ad-landing
	 * page works even before the cookie round-trips). Every value is sanitized and
	 * length-capped; merge-token-like junk is dropped.
	 *
	 * @return array<string,string> param => value (only non-empty).
	 */
	private static function captured_tracking(WP_REST_Request $req): array {
		$raw = [];
		if (isset($_COOKIE[self::TRACK_COOKIE])) {
			$decoded = json_decode(urldecode((string) wp_unslash($_COOKIE[self::TRACK_COOKIE])), true);
			if (is_array($decoded)) { $raw = $decoded; }
		}
		if (!empty($_GET) && is_array($_GET)) {
			foreach (wp_unslash($_GET) as $k => $v) {
				if (is_string($k) && !is_array($v)) { $raw[$k] = $v; }
			}
		}

		$out = [];
		foreach ($raw as $k => $v) {
			if (!is_string($k) || !preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $k)) { continue; }
			$v = sanitize_text_field((string) $v);
			if ($v === '' || mb_strlen($v) > 500) { continue; }
			// Never forward unresolved merge tokens / shortcodes.
			if (preg_match('/^\{\{.*\}\}$/', $v) || preg_match('/^\[.*\]$/', $v)) { continue; }
			$out[$k] = $v;
		}
		return $out;
	}

	/**
	 * Any submitted field that is NOT a standard/known field or a system/tracking
	 * key, collected in submission order. Sanitized + capped — untrusted input.
	 *
	 * @return array<string,string>
	 */
	private static function capture_custom(WP_REST_Request $req): array {
		$reserved = ['first_name', 'firstname', 'last_name', 'lastname', 'name', 'email', 'phone', 'message', 'service', 'company', 'business', 'website', 'address', 'city', 'state', 'zip', 'postal_code', 'postalcode', 'source', 'consent', '_wpnonce', 'action', 'company_hp', 'company_url', 'hp', 'rest_route'];
		$out = [];
		foreach ((array) $req->get_params() as $k => $v) {
			if (count($out) >= 20) {
				break;
			}
			if (!is_string($k)) {
				continue;
			}
			$key = preg_replace('/[^a-z0-9_-]/', '', strtolower($k));
			if ($key === '' || in_array($key, $reserved, true)) {
				continue;
			}
			if (strpos($key, 'utm_') === 0 || in_array($key, ['gclid', 'fbclid', 'msclkid'], true)) {
				continue; // tracking handled separately (captured_tracking)
			}
			if (is_array($v)) {
				$v = implode(', ', array_map('sanitize_text_field', array_map('strval', $v)));
			} else {
				$v = sanitize_textarea_field((string) $v);
			}
			$v = trim($v);
			if ($v === '') {
				continue;
			}
			if (mb_strlen($v) > 2000) {
				$v = mb_substr($v, 0, 2000);
			}
			$out[$key] = $v;
		}
		return $out;
	}

	/** Whether the engine's own route should register. A client integration can
	 *  force it off (e.g. a bespoke handler wants the route). */
	public static function enabled(): bool {
		return apply_filters('aq_lead_capture_enabled', true);
	}

	/* ---------------- settings ---------------- */

	/** Non-secret settings, every key present with a sensible default. */
	public static function get_settings(): array {
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		return array_merge([
			'thankyou_url'   => '',
			'test_button'    => true,
			'notify_to'      => '',
			'notify_bcc'     => '',
			'notify_subject' => '',
			'email_template' => '',
			'test_recipient' => 'robert@aqmarketing.com, justin@aqmarketing.com',
			'smtp_host'      => '',
			'smtp_port'      => 465,
			'smtp_secure'    => 'ssl',
			'smtp_user'      => '',
			'smtp_from'      => '',
			'smtp_from_name' => '',
			'ghl_location'   => '',
			'email_logo'     => '', // logo image URL shown in the notification email header
			'email_logo_w'   => 0,  // measured display width (px), capped to fit the header
			'email_logo_h'   => 0,  // measured display height (px)
			// Editable email styling (AutoForge → Forms). Blank/'' = brand-derived default.
			'email_header_bg'    => '',
			'email_header_fg'    => '',
			'email_accent'       => '',
			'email_border_color' => '',
			'email_bg'           => '',
			'email_radius'       => '', // '' = default 14px
			'field_sync_daily' => true, // auto-check GHL for new custom fields each morning
			// legacy test-fill fields (kept for the admin test button JS)
			'test_name'      => 'Test Tester',
			'test_email'     => 'test@example.com',
			'test_phone'     => '(555) 123-4567',
			'test_business'  => 'Test Company',
			'test_message'   => 'TEST submission — please ignore.',
		], $o);
	}

	/** SMTP password: wp-config constant wins, else the write-only option. */
	public static function smtp_pass(): string {
		if (defined('AQ_SMTP_PASS') && AQ_SMTP_PASS) {
			return (string) AQ_SMTP_PASS;
		}
		return (string) get_option(self::OPT_SMTP, '');
	}

	/** GHL Private Integration Token: wp-config constant wins, else write-only option. */
	public static function ghl_token(): string {
		if (defined('AQ_GHL_TOKEN') && AQ_GHL_TOKEN) {
			return (string) AQ_GHL_TOKEN;
		}
		return (string) get_option(self::OPT_GHL, '');
	}

	/** GHL Location ID: wp-config constant wins, else the (non-secret) setting. */
	public static function ghl_location(): string {
		if (defined('AQ_GHL_LOCATION_ID') && AQ_GHL_LOCATION_ID) {
			return (string) AQ_GHL_LOCATION_ID;
		}
		return (string) (self::get_settings()['ghl_location'] ?? '');
	}

	public static function smtp_locked(): bool { return defined('AQ_SMTP_PASS') && (bool) AQ_SMTP_PASS; }
	public static function ghl_locked(): bool  { return defined('AQ_GHL_TOKEN') && (bool) AQ_GHL_TOKEN; }

	public static function smtp_ready(): bool {
		$c = self::get_settings();
		return $c['smtp_host'] !== '' && $c['smtp_user'] !== '' && self::smtp_pass() !== '';
	}
	public static function ghl_ready(): bool {
		return self::ghl_token() !== '' && self::ghl_location() !== '';
	}

	/* ---------------- SMTP ---------------- */

	/** Route site mail through the configured SMTP mailbox when fully set up. */
	public static function apply_smtp($phpmailer): void {
		if (!self::smtp_ready()) {
			return;
		}
		$c = self::get_settings();
		$phpmailer->isSMTP();
		$phpmailer->Host       = $c['smtp_host'];
		$phpmailer->Port       = (int) $c['smtp_port'] ?: 465;
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $c['smtp_user'];
		$phpmailer->Password   = self::smtp_pass();
		$phpmailer->SMTPSecure = in_array($c['smtp_secure'], ['ssl', 'tls'], true) ? $c['smtp_secure'] : '';
		$from = $c['smtp_from'] !== '' ? $c['smtp_from'] : $c['smtp_user'];
		$phpmailer->From     = $from;
		$phpmailer->FromName = $c['smtp_from_name'] !== '' ? $c['smtp_from_name'] : self::site_name();
		$phpmailer->Sender   = $from;
	}

	private static function site_name(): string {
		return (string) (function_exists('aq_site') ? (aq_site('name') ?: get_bloginfo('name')) : get_bloginfo('name'));
	}

	/* ---------------- REST + lead handling ---------------- */

	public static function rest_routes(): void {
		if (!self::enabled()) {
			return;
		}
		register_rest_route('aqm/v1', '/contact', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // public form; guarded below
			'callback'            => [__CLASS__, 'handle'],
		]);
	}

	/** First non-empty param across several possible field names. */
	private static function pick(WP_REST_Request $req, array $names): string {
		foreach ($names as $n) {
			$v = $req->get_param($n);
			if (is_string($v) && trim($v) !== '') {
				return trim($v);
			}
		}
		return '';
	}

	public static function handle(WP_REST_Request $req) {
		$ok   = static function () { return new WP_REST_Response(['ok' => true], 200); };
		$deny = static function (int $c, string $e) { return new WP_REST_Response(['ok' => false, 'error' => $e], $c); };

		// 1) Honeypot — drop silently (fake success).
		if (self::pick($req, ['company_hp', 'company_url']) !== '') {
			return $ok();
		}
		// 2) Same-origin: drop a mismatched Origin/Referer; allow a missing one.
		if (apply_filters('aq_lead_check_origin', true)) {
			$home = (string) wp_parse_url(home_url(), PHP_URL_HOST);
			$src  = !empty($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN']
				: (!empty($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');
			if ($src !== '') {
				$sh = (string) wp_parse_url($src, PHP_URL_HOST);
				if ($sh !== '' && strcasecmp($sh, $home) !== 0) {
					return $ok();
				}
			}
		}
		// 3) Rate limit (per-IP interval + window + global ceiling).
		$rl = self::rate_limit();
		if ($rl !== true) {
			return $deny(429, 'rate_limited');
		}

		$first   = sanitize_text_field(self::pick($req, ['first_name', 'firstName']));
		$last    = sanitize_text_field(self::pick($req, ['last_name', 'lastName']));
		$name    = sanitize_text_field(self::pick($req, ['name']));
		$email   = sanitize_email(self::pick($req, ['email']));
		$phone   = sanitize_text_field(self::pick($req, ['phone']));
		$message = sanitize_textarea_field(self::pick($req, ['message']));
		$service = sanitize_text_field(self::pick($req, ['service']));
		$company = sanitize_text_field(self::pick($req, ['company', 'business']));
		$website = esc_url_raw(self::pick($req, ['website']));
		$address = sanitize_text_field(self::pick($req, ['address']));
		$city    = sanitize_text_field(self::pick($req, ['city']));
		$state   = sanitize_text_field(self::pick($req, ['state']));
		$zip     = sanitize_text_field(self::pick($req, ['zip', 'postal_code', 'postalCode']));
		$source  = sanitize_text_field(self::pick($req, ['source'])) ?: 'Website form';

		if ($first === '' && $name !== '') {
			$parts = preg_split('/\s+/', $name, 2);
			$first = $parts[0] ?? '';
			$last  = $parts[1] ?? '';
		}

		if ($email === '' && $phone === '') {
			return $deny(422, 'missing_contact');
		}
		if ($email !== '' && !is_email($email)) {
			return $deny(422, 'invalid_email');
		}

		$f = compact('first', 'last', 'email', 'phone', 'company', 'website', 'address', 'city', 'state', 'zip', 'service', 'message', 'source');

		// Ad/UTM attribution captured from the landing URL (cookie / body / query).
		// Carried on $f so both the CRM push and the notification email can use it.
		$f['tracking'] = self::captured_tracking($req);

		// Custom fields: any submitted field beyond the standard set, sanitized + capped.
		$f['custom'] = self::capture_custom($req);

		// CRM push (best-effort) — never lose the lead if GHL is down; email fires too.
		$ghl_ok = false;
		if (self::ghl_ready()) {
			$res = self::push_to_ghl($f);
			if ($res === true) {
				$ghl_ok = true;
			} else {
				error_log('[aq-core] GHL lead push failed (' . $res . ').');
			}
		}
		$mail_ok = self::notify($f);

		return ($ghl_ok || $mail_ok) ? $ok() : $deny(502, 'unprocessable');
	}

	/** Per-IP min interval + rolling window + global flood ceiling. Returns true or false. */
	private static function rate_limit(): bool {
		if (!apply_filters('aq_lead_rate_limit', true)) {
			return true;
		}
		$ip     = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$xff = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
			if ($xff !== '' && filter_var($xff, FILTER_VALIDATE_IP)) { $ip = $xff; }
		}
		$bucket  = $ip !== '' ? substr(hash('sha256', $ip . '|' . wp_salt()), 0, 32) : 'noip';
		$min_gap = (int) apply_filters('aq_lead_min_interval', 15);
		$win     = (int) apply_filters('aq_lead_window', 600);
		$max_ip  = (int) apply_filters('aq_lead_max_per_window', 5);
		$g_win   = (int) apply_filters('aq_lead_global_window', 60);
		$g_max   = (int) apply_filters('aq_lead_global_max', 60);

		$gap_key = 'aq_lead_gap_' . $bucket;
		$cnt_key = 'aq_lead_cnt_' . $bucket;
		$g_key   = 'aq_lead_global';
		$cnt   = (int) get_transient($cnt_key);
		$g_cnt = (int) get_transient($g_key);

		if (($min_gap > 0 && get_transient($gap_key))
			|| ($max_ip > 0 && $cnt >= $max_ip)
			|| ($g_max > 0 && $g_cnt >= $g_max)) {
			return false;
		}
		if ($min_gap > 0) { set_transient($gap_key, 1, $min_gap); }
		set_transient($cnt_key, $cnt + 1, $win);
		set_transient($g_key, $g_cnt + 1, $g_win);
		return true;
	}

	/* ---------------- GoHighLevel ---------------- */

	/** Upsert the contact + attach the message as a note. Returns true or an error string. */
	private static function push_to_ghl(array $f) {
		$tags = ['Website Lead'];
		if ($f['service'] !== '') { $tags[] = $f['service']; }

		$payload = array_filter([
			'locationId'  => self::ghl_location(),
			'firstName'   => $f['first'],
			'lastName'    => $f['last'],
			'email'       => $f['email'],
			'phone'       => $f['phone'],
			'companyName' => $f['company'],
			'website'     => $f['website'],
			'address1'    => $f['address'],
			'city'        => $f['city'],
			'state'       => $f['state'],
			'postalCode'  => $f['zip'],
			'source'      => $f['source'],
			'tags'        => $tags,
		], static function ($v) { return $v !== '' && $v !== null && $v !== []; });

		// Attach captured ad/UTM attribution to matching GHL custom fields.
		$custom = self::ghl_custom_fields(is_array($f['tracking'] ?? null) ? $f['tracking'] : []);
		if (!empty($custom)) {
			$payload['customFields'] = $custom;
		}

		$resp = self::ghl_request('POST', '/contacts/upsert', $payload);
		if (is_wp_error($resp)) {
			return $resp->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code < 200 || $code >= 300) {
			return 'HTTP ' . $code . ' ' . wp_remote_retrieve_body($resp);
		}
		$body       = json_decode(wp_remote_retrieve_body($resp), true);
		$contact_id = $body['contact']['id'] ?? ($body['id'] ?? '');
		if ($f['message'] !== '' && $contact_id) {
			self::ghl_request('POST', '/contacts/' . rawurlencode($contact_id) . '/notes', ['body' => $f['message']]);
		}
		return true;
	}

	private static function ghl_request(string $method, string $path, array $body = []) {
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . self::ghl_token(),
				'Version'       => self::GHL_VER,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];
		// Only send a JSON body on write methods; a GET with a body trips some hosts.
		if (strtoupper($method) !== 'GET') {
			$args['body'] = wp_json_encode($body);
		}
		return wp_remote_request(self::GHL_API . $path, $args);
	}

	/* ---------------- GHL custom-field mapping (ad/UTM attribution) ---------------- */

	/**
	 * Build the `customFields` payload for a submission — DETECT & FILL ONLY.
	 *
	 * We pull the location's entire custom-field list from GHL and fill EVERY field
	 * that matches a captured URL variable (by field name or GHL fieldKey, however
	 * the account named it). We never create fields — GHL is the source of truth for
	 * what exists; the ads team / admin sets fields up there, and whatever they add
	 * is picked up automatically (immediately via the manual check, or by the next
	 * daily sync). Nothing is gclid-specific — gclid/utm are just examples.
	 *
	 * @param array<string,string> $captured param => value (all captured URL vars).
	 * @return array<int,array{id:string,value:string}>
	 */
	private static function ghl_custom_fields(array $captured): array {
		$captured = array_filter($captured, static function ($v) { return is_string($v) && trim($v) !== ''; });
		if (empty($captured)) {
			return [];
		}
		$index = self::ghl_field_index(); // [normalized name|fieldKey => field id] for ALL fields
		if (empty($index)) {
			return [];
		}
		$out  = [];
		$used = [];
		foreach ($captured as $param => $value) {
			$np = self::norm_param($param);
			if ($np === '') { continue; }
			// A matching field may be named plainly ("gclid"/"Campaign ID"), prefixed
			// "AQM - ", or carry the auto-generated `contact.` fieldKey — try each form.
			// Fill EVERY distinct field that matches, not just the first: a location
			// may carry duplicate fields for the same variable (e.g. "AQM - utm_source"
			// AND "UTM Source"), and we want all of them populated. `$used` keys off the
			// field id so we never write the same field twice.
			foreach ([$np, 'aqm_' . $np, 'contact_' . $np, 'contact_aqm_' . $np] as $c) {
				if (!empty($index[$c]) && empty($used[$index[$c]])) {
					$out[] = ['id' => $index[$c], 'value' => (string) $value];
					$used[$index[$c]] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Normalized index of the location's custom fields: [normalized name|key => id].
	 * Each field is registered under both its name and its fieldKey so a param can
	 * match either. Cached for TRACK_MAP_TTL; the last good index is also kept in a
	 * non-autoloaded option and reused if the API is unreachable. Pass $force to
	 * bypass the cache and re-fetch (used by the daily cron and manual check).
	 *
	 * @return array<string,string>
	 */
	private static function ghl_field_index(bool $force = false): array {
		$loc = self::ghl_location();
		if ($loc === '' || self::ghl_token() === '') {
			return [];
		}
		if (!$force) {
			$cached = get_transient('aq_ghl_field_idx_' . md5($loc));
			if (is_array($cached)) {
				return $cached;
			}
		}
		$fields = self::ghl_fetch_custom_fields($loc);
		if ($fields === null) {
			$fallback = get_option('aq_ghl_field_idx_opt_' . md5($loc), []);
			return is_array($fallback) ? $fallback : [];
		}
		$index = self::build_field_index($fields);
		self::store_field_index($loc, $index);
		return $index;
	}

	/** Turn a raw GHL custom-field list into [normalized name|fieldKey => id]. */
	private static function build_field_index(array $fields): array {
		$index = [];
		foreach ($fields as $field) {
			$id = (string) ($field['id'] ?? '');
			if ($id === '') { continue; }
			foreach ([$field['name'] ?? '', $field['fieldKey'] ?? ''] as $token) {
				$n = self::norm_param((string) $token);
				if ($n !== '' && !isset($index[$n])) { $index[$n] = $id; }
			}
		}
		return $index;
	}

	/** Persist the field index to both the short-lived cache and the durable fallback. */
	private static function store_field_index(string $loc, array $index): void {
		set_transient('aq_ghl_field_idx_' . md5($loc), $index, self::TRACK_MAP_TTL);
		update_option('aq_ghl_field_idx_opt_' . md5($loc), $index, false);
	}

	/* ---------------- custom-field discovery (daily cron + manual) ---------------- */

	/**
	 * Force-refresh the location's custom-field list from GHL and record the result.
	 * Returns [ok, count, error]. `count` is the number of distinct custom fields
	 * now known. Safe to call any time; degrades to an error result if GHL is
	 * unreachable or not connected (never throws).
	 *
	 * @return array{ok:bool,count:int,error:string}
	 */
	public static function sync_fields(): array {
		$loc = self::ghl_location();
		if ($loc === '' || self::ghl_token() === '') {
			return ['ok' => false, 'count' => 0, 'error' => 'GHL is not connected.'];
		}
		$fields = self::ghl_fetch_custom_fields($loc);
		if ($fields === null) {
			return ['ok' => false, 'count' => 0, 'error' => 'Could not reach GoHighLevel.'];
		}
		$index = self::build_field_index($fields);
		self::store_field_index($loc, $index);
		$count = count(array_unique(array_values($index)));
		update_option(self::OPT_FIELD_SYNC_META, ['time' => time(), 'count' => $count], false);
		return ['ok' => true, 'count' => $count, 'error' => ''];
	}

	/** Daily cron callback — refresh the field list so new GHL fields are picked up. */
	public static function cron_sync_fields(): void {
		if (self::ghl_ready()) {
			self::sync_fields();
		}
	}

	/**
	 * Keep the daily-sync cron in step with the setting + connection state. Schedules
	 * a daily event (first run next morning, site time) when the option is on and GHL
	 * is connected; clears it otherwise. Runs cheaply on every `init`.
	 */
	public static function reconcile_field_sync_schedule(): void {
		$want      = !empty(self::get_settings()['field_sync_daily']) && self::ghl_ready();
		$scheduled = wp_next_scheduled(self::CRON_FIELD_SYNC);
		if ($want && !$scheduled) {
			wp_schedule_event(self::next_morning_ts(), 'daily', self::CRON_FIELD_SYNC);
		} elseif (!$want && $scheduled) {
			wp_unschedule_event($scheduled, self::CRON_FIELD_SYNC);
		}
	}

	/** Timestamp for the next 6:00 in the site's timezone. */
	private static function next_morning_ts(): int {
		try {
			$tz   = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
			$now  = new DateTime('now', $tz);
			$next = new DateTime('today 06:00', $tz);
			if ($next <= $now) {
				$next->modify('+1 day');
			}
			return $next->getTimestamp();
		} catch (Exception $e) {
			return time() + DAY_IN_SECONDS;
		}
	}

	/** Admin "Check for new custom fields now" button handler. */
	public static function manual_sync(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_forms_sync_fields')) {
			wp_die('Not allowed.');
		}
		$res = self::sync_fields();
		wp_safe_redirect(add_query_arg(
			['page' => self::SLUG, 'synced' => $res['ok'] ? (string) $res['count'] : 'err'],
			admin_url('admin.php')
		));
		exit;
	}

	/**
	 * Fetch all custom fields for a location. Returns a list of field arrays, or
	 * null on transport/HTTP error (so the caller can fall back to cache).
	 *
	 * @return array<int,array>|null
	 */
	private static function ghl_fetch_custom_fields(string $loc): ?array {
		$resp = self::ghl_request('GET', '/locations/' . rawurlencode($loc) . '/customFields');
		if (is_wp_error($resp)) {
			error_log('[aq-core] GHL custom-field fetch failed (' . $resp->get_error_message() . ').');
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code < 200 || $code >= 300) {
			error_log('[aq-core] GHL custom-field fetch HTTP ' . $code . '.');
			return null;
		}
		$body = json_decode(wp_remote_retrieve_body($resp), true);
		if (!is_array($body)) {
			return null;
		}
		if (isset($body['customFields']) && is_array($body['customFields'])) {
			return $body['customFields'];
		}
		if (isset($body['fields']) && is_array($body['fields'])) {
			return $body['fields'];
		}
		return is_array($body) ? $body : null;
	}

	/* ---------------- notification email ---------------- */

	/**
	 * Palette + font for the notification email, DERIVED FROM THE CLIENT'S BRAND
	 * so the message matches the live site (no hardcoded colors — client-agnostic):
	 *   - accent  = brand `themeColor` (buttons/links/eyebrow/rule), else neutral blue
	 *   - header  = dark band + reversed wordmark when `headerStyle` is 'dark', else light
	 *   - font    = the brand's Google font (first family), with an email-safe fallback
	 * Override any of it per client with the `aq_lead_email_theme` filter.
	 */
	private static function email_theme(bool $overlay = true): array {
		$accent = '';
		if (function_exists('aq_site')) {
			$tc = strtolower(trim((string) aq_site('themeColor')));
			if (preg_match('/^#[0-9a-f]{6}$/', $tc)) { $accent = $tc; }
		}
		$dark = function_exists('aq_site') && aq_site('headerStyle') === 'dark';
		$theme = [
			'accent'    => $accent !== '' ? $accent : '#2563eb',
			'ink'       => '#0f172a', // headings + field values
			'muted'     => '#6b7280', // labels + meta
			'line'      => '#e6e9ee', // hairlines / card border
			'soft'      => '#f5f7fa', // page + footer background
			'header_bg' => $dark ? '#0f172a' : '#ffffff',
			'header_fg' => $dark ? '#ffffff' : '#0f172a',
			'font'      => self::email_font(),
			'radius'    => 14,
		];
		// Admin overlay (AutoForge → Forms → Email styling). Blank/invalid = keep derived.
		// $overlay=false returns the pure brand-derived defaults (used by the live preview).
		if ($overlay) {
			$cfg = self::get_settings();
			$hex = static fn ($v) => (is_string($v) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($v))) ? trim($v) : '';
			if ($c = $hex($cfg['email_header_bg'] ?? ''))    { $theme['header_bg'] = $c; }
			if ($c = $hex($cfg['email_header_fg'] ?? ''))    { $theme['header_fg'] = $c; }
			if ($c = $hex($cfg['email_accent'] ?? ''))       { $theme['accent']    = $c; }
			if ($c = $hex($cfg['email_border_color'] ?? '')) { $theme['line']      = $c; }
			if ($c = $hex($cfg['email_bg'] ?? ''))           { $theme['soft']      = $c; }
			$theme['radius'] = (($cfg['email_radius'] ?? '') === '') ? 14 : max(0, min(28, (int) $cfg['email_radius']));
		}

		return array_merge($theme, (array) apply_filters('aq_lead_email_theme', $theme));
	}

	/** Email-safe font stack, led by the brand's Google font family when detectable. */
	private static function email_font(): string {
		$lead = '';
		if (function_exists('aq_site')) {
			$css = str_replace('+', ' ', (string) aq_site('fonts.googleCss'));
			if ($css !== '' && preg_match('/[?&]family=([A-Za-z0-9\- ]+)/', $css, $m)) {
				$fam = trim($m[1]);
				if ($fam !== '') { $lead = "'" . $fam . "', "; }
			}
		}
		return $lead . 'Arial, Helvetica, sans-serif';
	}

	/**
	 * Set the notification-email logo URL and remember its display size. Public so
	 * both the admin save path and programmatic callers (wp eval) can use it.
	 */
	public static function set_email_logo(string $url): void {
		$url = esc_url_raw(trim($url));
		$opt = get_option(self::OPTION, []);
		if (!is_array($opt)) { $opt = []; }
		$opt['email_logo'] = $url;
		[$opt['email_logo_w'], $opt['email_logo_h']] = self::measure_logo($url);
		update_option(self::OPTION, $opt, false);
	}

	/**
	 * Measure a logo image and return the [width, height] it should DISPLAY at in
	 * the email — the natural size scaled down to fit within 200×64, never up.
	 * Returns [0, 0] when the image can't be measured (caller falls back to CSS caps).
	 *
	 * @return array{0:int,1:int}
	 */
	private static function measure_logo(string $url): array {
		if ($url === '') { return [0, 0]; }
		$size = false;
		$path = self::url_to_path($url);
		if ($path !== '' && is_readable($path)) {
			$size = @getimagesize($path);
		}
		if (!$size && ini_get('allow_url_fopen')) {
			$size = @getimagesize($url); // remote fallback; best-effort
		}
		if (!is_array($size) || empty($size[0]) || empty($size[1])) {
			return [0, 0];
		}
		$w = (int) $size[0];
		$h = (int) $size[1];
		$scale = min(200 / $w, 64 / $h, 1); // fit within 200 wide × 64 tall, don't upscale
		return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
	}

	/** Map a local uploads URL to its filesystem path (empty if not a local upload). */
	private static function url_to_path(string $url): string {
		$up = wp_upload_dir();
		if (!empty($up['baseurl']) && !empty($up['basedir']) && strpos($url, $up['baseurl']) === 0) {
			return $up['basedir'] . substr($url, strlen($up['baseurl']));
		}
		return '';
	}

	/**
	 * Placeholder values injected into the email template. Every value is already
	 * escaped/safe; the template is admin-authored HTML. Tokens: {{site}} {{host}}
	 * {{when}} {{title}} {{accent}} {{ink}} {{muted}} {{line}} {{soft}}
	 * {{header_bg}} {{header_fg}} {{font}} {{phone}} {{home_url}} {{rows}}
	 * {{banner}} {{foot}}.
	 */
	public static function email_tokens(array $f, bool $is_test = false): array {
		$t     = self::email_theme();
		$font  = $t['font']; $accent = $t['accent']; $ink = $t['ink']; $muted = $t['muted']; $line = $t['line'];
		$cfg   = self::get_settings();
		// Merge-tag the body title the same way the subject is (fill_subject_tokens),
		// so {name}/{city}/{state} etc. resolve in the email H1 too — not just the
		// subject line. Without this the raw template shows literally in the body.
		$title = $cfg['notify_subject'] !== '' ? self::fill_subject_tokens($cfg['notify_subject'], $f) : 'New website form submission';
		$when  = function_exists('wp_date') ? wp_date('F j, Y \a\t g:i a') : date('F j, Y');
		$site  = self::site_name();
		$host  = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		$phone = (string) (function_exists('aq_site') ? aq_site('phone') : '');

		// Header lockup: the configured email logo image if set, else the site name
		// as a text wordmark (the built-in default). Email-safe sizing: explicit
		// width/height attributes (measured at save time, capped to fit the header)
		// so Outlook — which ignores max-width — renders it at the right size too;
		// max-width:100% keeps it inside narrow mobile clients.
		$logo = trim((string) ($cfg['email_logo'] ?? ''));
		$lw   = (int) ($cfg['email_logo_w'] ?? 0);
		$lh   = (int) ($cfg['email_logo_h'] ?? 0);
		// Prefer the inline CID (embedded image) when one is prepared for this send;
		// it renders without the client fetching the origin URL. esc_url() strips the
		// cid: scheme, so only URL fallbacks go through esc_url().
		$src = is_array(self::$embed_logo) ? 'cid:' . self::$embed_logo['cid'] : esc_url($logo);
		if ($logo !== '' && $lw > 0 && $lh > 0) {
			$logo_html = '<img src="' . esc_attr($src) . '" alt="' . esc_attr($site) . '" width="' . $lw . '" height="' . $lh . '" style="display:block;width:' . $lw . 'px;height:' . $lh . 'px;max-width:100%;margin:0 auto;border:0;">';
		} elseif ($logo !== '') {
			// Dimensions unknown (e.g. remote image we couldn't measure) — fall back
			// to CSS caps that keep it neat in modern clients.
			$logo_html = '<img src="' . esc_attr($src) . '" alt="' . esc_attr($site) . '" style="display:block;width:auto;height:auto;max-width:200px;max-height:64px;margin:0 auto;border:0;">';
		} else {
			$logo_html = '<span style="font-family:' . $font . ';font-size:22px;font-weight:800;letter-spacing:-.01em;color:' . $t['header_fg'] . ';">' . esc_html($site) . '</span>';
		}

		$rows = '';
		$map = ['first' => 'First name', 'last' => 'Last name', 'email' => 'Email', 'phone' => 'Phone', 'company' => 'Company', 'website' => 'Website', 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'zip' => 'Zip', 'service' => 'Service', 'message' => 'Message', 'source' => 'Source'];
		foreach ($map as $k => $label) {
			$v = (string) ($f[$k] ?? '');
			if ($v === '') { continue; }
			$val = ($k === 'message') ? nl2br(esc_html($v)) : esc_html($v);
			$rows .= '<tr>'
				. '<td style="padding:11px 16px 11px 0;border-bottom:1px solid ' . $line . ';color:' . $muted . ';font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;vertical-align:top;width:120px;font-family:' . $font . '">' . esc_html($label) . '</td>'
				. '<td style="padding:11px 0;border-bottom:1px solid ' . $line . ';color:' . $ink . ';font-size:15px;line-height:1.5;vertical-align:top;font-family:' . $font . '">' . $val . '</td>'
				. '</tr>';
		}
		// Custom fields (any extra submitted field) — one labeled row each, in order.
		$custom = isset($f['custom']) && is_array($f['custom']) ? $f['custom'] : [];
		foreach ($custom as $ck => $cv) {
			$cv = (string) $cv;
			if ($cv === '') { continue; }
			$label = ucfirst(str_replace(['_', '-'], ' ', (string) $ck));
			$rows .= '<tr>'
				. '<td style="padding:11px 16px 11px 0;border-bottom:1px solid ' . $line . ';color:' . $muted . ';font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;vertical-align:top;width:120px;font-family:' . $font . '">' . esc_html($label) . '</td>'
				. '<td style="padding:11px 0;border-bottom:1px solid ' . $line . ';color:' . $ink . ';font-size:15px;line-height:1.5;vertical-align:top;font-family:' . $font . '">' . nl2br(esc_html($cv)) . '</td>'
				. '</tr>';
		}
		// Ad / campaign attribution (gclid, utm_*, …) captured from the landing URL.
		$tracking = isset($f['tracking']) && is_array($f['tracking']) ? $f['tracking'] : [];
		foreach ($tracking as $tk => $tv) {
			$tv = (string) $tv;
			if ($tv === '') { continue; }
			$rows .= '<tr>'
				. '<td style="padding:11px 16px 11px 0;border-bottom:1px solid ' . $line . ';color:' . $muted . ';font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;vertical-align:top;width:120px;font-family:' . $font . '">' . esc_html($tk) . '</td>'
				. '<td style="padding:11px 0;border-bottom:1px solid ' . $line . ';color:' . $ink . ';font-size:15px;line-height:1.5;vertical-align:top;word-break:break-all;font-family:' . $font . '">' . esc_html($tv) . '</td>'
				. '</tr>';
		}
		$banner = $is_test
			? '<div style="background:#fff7e6;color:#7a4e0a;border:1px solid #f4d088;border-radius:8px;padding:10px 14px;margin:0 0 18px;font-size:13px;font-family:' . $font . '"><strong>Test email</strong> &mdash; a design preview of the website form notification. No real customer submitted this.</div>'
			: '';
		$foot = esc_html($site) . ($phone !== '' ? ' &nbsp;&middot;&nbsp; ' . esc_html($phone) : '')
			. ' &nbsp;&middot;&nbsp; <a href="' . esc_url(home_url('/')) . '" style="color:' . $accent . ';text-decoration:none;">' . esc_html($host) . '</a>';

		return [
			'site' => esc_html($site), 'host' => esc_html($host), 'when' => esc_html($when),
			'title' => esc_html($title), 'phone' => esc_html($phone), 'home_url' => esc_url(home_url('/')),
			'accent' => $accent, 'ink' => $ink, 'muted' => $muted, 'line' => $line, 'soft' => $t['soft'],
			'header_bg' => $t['header_bg'], 'header_fg' => $t['header_fg'], 'font' => $font,
			'radius' => (int) ($t['radius'] ?? 14),
			'logo' => $logo_html,
			'rows' => $rows, 'banner' => $banner, 'foot' => $foot,
		];
	}

	/** The engine's built-in, brand-derived template (used when no custom one is saved). */
	public static function default_email_template(): string {
		return '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:0;background:{{soft}};">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{soft}};padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid {{line}};border-radius:{{radius}}px;overflow:hidden;">'
			. '<tr><td align="center" style="padding:24px 28px;background:{{header_bg}};">{{logo}}</td></tr>'
			. '<tr><td style="height:4px;background:{{accent}};font-size:0;line-height:0;">&nbsp;</td></tr>'
			. '<tr><td style="padding:26px 30px 6px;">{{banner}}'
			. '<p style="margin:0 0 4px;color:{{accent}};font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;font-family:{{font}};">New website enquiry</p>'
			. '<h1 style="margin:0 0 6px;color:{{ink}};font-size:22px;font-weight:800;font-family:{{font}};">{{title}}</h1>'
			. '<p style="margin:0 0 16px;color:{{muted}};font-size:13px;font-family:{{font}};">Submitted from {{host}} on {{when}}.</p></td></tr>'
			. '<tr><td style="padding:0 30px 26px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">{{rows}}</table></td></tr>'
			. '<tr><td align="center" style="background:{{soft}};padding:18px 30px;border-top:1px solid {{line}};"><p style="margin:0;color:{{muted}};font-size:12px;font-family:{{font}};">{{foot}}</p></td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Substitute {{tokens}} into a template. Unknown tokens are left as-is. */
	public static function render_email(string $template, array $tokens): string {
		$search  = [];
		$replace = [];
		foreach ($tokens as $k => $v) {
			$search[]  = '{{' . $k . '}}';
			$replace[] = (string) $v;
		}
		return str_replace($search, $replace, $template);
	}

	/**
	 * The logo to embed in the CURRENT email as an inline CID attachment, or null.
	 * Set by lead_email_html() (which the token reader consults) and consumed by
	 * wp_mail_with_logo(). Embedding the image inline means the client never has to
	 * fetch an external URL — so it renders even where an image proxy (e.g. HEY's
	 * gopher) can't reach the origin CDN.
	 *
	 * @var array|null
	 */
	private static $embed_logo = null;

	/** Content-ID used for the inline logo. */
	const LOGO_CID = 'aqmlogo';

	/**
	 * Resolve the configured email logo into something embeddable: a local file
	 * path when it lives in this site's uploads, otherwise its fetched bytes.
	 * Returns null when there's no logo or it can't be obtained (caller then falls
	 * back to a plain <img src="url">).
	 *
	 * @return array|null
	 */
	private static function prepare_logo_embed(): ?array {
		$url = trim((string) (self::get_settings()['email_logo'] ?? ''));
		if ($url === '') {
			return null;
		}
		$name = basename((string) wp_parse_url($url, PHP_URL_PATH)) ?: 'logo';
		$path = self::url_to_path($url);
		if ($path !== '' && is_readable($path)) {
			return ['cid' => self::LOGO_CID, 'path' => $path, 'name' => $name];
		}
		$resp = wp_remote_get($url, ['timeout' => 10]);
		if (!is_wp_error($resp)) {
			$code  = (int) wp_remote_retrieve_response_code($resp);
			$bytes = wp_remote_retrieve_body($resp);
			if ($code >= 200 && $code < 300 && $bytes !== '') {
				$type = wp_remote_retrieve_header($resp, 'content-type');
				return ['cid' => self::LOGO_CID, 'bytes' => $bytes, 'type' => $type ?: 'image/jpeg', 'name' => $name];
			}
		}
		return null;
	}

	/** Final HTML body for the lead-notification email: custom template if saved, else the built-in one. */
	public static function lead_email_html(array $f, bool $is_test = false): string {
		// Decide the inline-logo embed for this send BEFORE rendering, so the
		// {{logo}} token can point at cid: when embedding is possible.
		self::$embed_logo = self::prepare_logo_embed();
		$custom   = (string) (self::get_settings()['email_template'] ?? '');
		$template = trim($custom) !== '' ? $custom : self::default_email_template();
		return self::render_email($template, self::email_tokens($f, $is_test));
	}

	/**
	 * wp_mail() wrapper that attaches the prepared logo as an inline CID image for
	 * this one send (scoped: the phpmailer_init hook is added, then removed).
	 */
	private static function wp_mail_with_logo(string $to, string $subject, string $html, array $headers): bool {
		$embed = self::$embed_logo;
		$cb = null;
		if (is_array($embed)) {
			$cb = static function ($phpmailer) use ($embed) {
				try {
					if (!empty($embed['path'])) {
						$phpmailer->addEmbeddedImage($embed['path'], $embed['cid'], $embed['name'] ?? 'logo');
					} elseif (!empty($embed['bytes'])) {
						$phpmailer->addStringEmbeddedImage($embed['bytes'], $embed['cid'], $embed['name'] ?? 'logo', 'base64', $embed['type'] ?? 'image/jpeg');
					}
				} catch (\Throwable $e) {
					// Never let an embed problem block the send — the <img> just
					// falls back to the (possibly proxy-blocked) URL.
				}
			};
			add_action('phpmailer_init', $cb);
		}
		$ok = wp_mail($to, $subject, $html, $headers);
		if ($cb) {
			remove_action('phpmailer_init', $cb);
		}
		return (bool) $ok;
	}

	/** Build + send a lead/notification email with the inline logo embedded. */
	public static function send_lead_email(array $f, bool $is_test, string $to, string $subject, array $extra_headers = []): bool {
		$html    = self::lead_email_html($f, $is_test); // sets self::$embed_logo
		$headers = array_merge(['Content-Type: text/html; charset=UTF-8'], $extra_headers);
		return self::wp_mail_with_logo($to, $subject, $html, $headers);
	}

	/** Email the lead to the configured To/BCC with the branded template. */
	private static function notify(array $f): bool {
		$cfg     = self::get_settings();
		$to      = $cfg['notify_to'] !== '' ? $cfg['notify_to'] : (string) get_option('admin_email');
		$subject = self::fill_subject_tokens($cfg['notify_subject'] !== '' ? $cfg['notify_subject'] : 'Website form submission', $f);
		if ($to === '') {
			return false;
		}
		$extra = [];
		if ($cfg['notify_bcc'] !== '') {
			$extra[] = 'Bcc: ' . $cfg['notify_bcc'];
		}
		if (($f['email'] ?? '') !== '' && is_email($f['email'])) {
			$extra[] = 'Reply-To: ' . $f['email'];
		}
		return self::send_lead_email($f, false, $to, $subject, $extra);
	}

	/* ---------------- helpers ---------------- */

	private static function clean_url($v): string {
		$v = trim((string) $v);
		if ($v === '') { return ''; }
		if ($v[0] === '/') {
			$v = '/' . ltrim(preg_replace('#[^A-Za-z0-9\-_/]#', '', $v), '/');
			return $v === '/' ? '' : $v;
		}
		return esc_url_raw($v);
	}

	/** A 3- or 6-digit hex color (with leading #), or '' when blank/invalid. */
	private static function clean_hex($v): string {
		$v = trim((string) $v);
		return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? $v : '';
	}

	private static function clean_emails($v): string {
		$out = [];
		foreach (preg_split('/[,;]+/', (string) $v) as $e) {
			$e = sanitize_email(trim($e));
			if ($e !== '' && is_email($e)) { $out[] = $e; }
		}
		return implode(', ', $out);
	}

	/**
	 * Fill {merge_tags} in the notification subject from a submission's fields.
	 * Supported tags: {name} {first} {last} {email} {phone} {company} {website}
	 * {address} {city} {state} {zip} {service} {source}. Unknown tags are left
	 * as-is; empty ones drop out (with dangling separators tidied). The result
	 * is a single header-safe line (no CR/LF).
	 *
	 * @param array<string,mixed> $f Submission fields (already sanitized).
	 */
	private static function fill_subject_tokens(string $tpl, array $f): string {
		if (strpos($tpl, '{') === false) {
			return $tpl;
		}
		$name = trim(((string) ($f['first'] ?? '')) . ' ' . ((string) ($f['last'] ?? '')));
		if ($name === '') { $name = (string) ($f['name'] ?? ''); }
		$map = [
			'name'    => $name,
			'first'   => (string) ($f['first'] ?? ''),
			'last'    => (string) ($f['last'] ?? ''),
			'email'   => (string) ($f['email'] ?? ''),
			'phone'   => (string) ($f['phone'] ?? ''),
			'company' => (string) ($f['company'] ?? ''),
			'website' => (string) ($f['website'] ?? ''),
			'address' => (string) ($f['address'] ?? ''),
			'city'    => (string) ($f['city'] ?? ''),
			'state'   => (string) ($f['state'] ?? ''),
			'zip'     => (string) ($f['zip'] ?? ''),
			'service' => (string) ($f['service'] ?? ''),
			'source'  => (string) ($f['source'] ?? ''),
		];
		// Merge custom fields so {facility_type}, {frequency}, … resolve too; the
		// standard tokens above keep priority on their names.
		if (isset($f['custom']) && is_array($f['custom'])) {
			foreach ($f['custom'] as $ck => $cv) {
				$ck = strtolower((string) $ck);
				if (!array_key_exists($ck, $map)) { $map[$ck] = (string) $cv; }
			}
		}
		$out = preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', static function ($m) use ($map) {
			$k = strtolower($m[1]);
			return array_key_exists($k, $map) ? $map[$k] : $m[0];
		}, $tpl);
		// Single header line: collapse whitespace, drop separators left dangling
		// by empty tags (e.g. "Lead: Ada -  ," → "Lead: Ada").
		$out = preg_replace('/\s+/', ' ', (string) $out);
		$out = preg_replace('/\s*[-–,:]\s*(?=([-–,:]|$))/u', '', $out);
		$out = trim((string) $out, " \t-–,:");
		return $out !== '' ? $out : 'Website form submission';
	}

	/* ---------------- admin screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Forms', 'Forms', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$cfg         = self::get_settings();
		$smtp_ready  = self::smtp_ready();
		$smtp_pass   = self::smtp_pass() !== '';
		$smtp_locked = self::smtp_locked();
		$ghl_ready   = self::ghl_ready();
		$ghl_token   = self::ghl_token() !== '';
		$ghl_locked  = self::ghl_locked();

		AQ_Admin_Hub::open('Forms', 'Where leads go after a form is submitted — email, SMTP delivery, and your CRM (GHL).', self::SLUG);
		?>
		<style>
			.aq-forms-card { background:#fff; border:1px solid #dcdfe3; border-radius:10px; padding:18px 20px; margin:0 0 18px; max-width:660px; }
			.aq-forms-card h2 { margin:0 0 6px; font-size:15px; }
			.aq-forms-card p.aq-forms-hint { margin:0 0 14px; color:#5b6471; font-size:13px; }
			.aq-forms-card input[type=text], .aq-forms-card input[type=email], .aq-forms-card input[type=password], .aq-forms-card input[type=number], .aq-forms-card select, .aq-forms-card textarea { padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; }
			.aq-forms-card input[type=text], .aq-forms-card input[type=email], .aq-forms-card input[type=password] { width:100%; max-width:420px; }
			.aq-forms-field { margin-bottom:14px; }
			.aq-forms-field label { display:block; font-weight:600; color:#0d1014; margin-bottom:5px; font-size:13px; }
			.aq-forms-row { display:flex; gap:12px; flex-wrap:wrap; }
			.aq-badge { display:inline-block; border-radius:999px; padding:2px 10px; font-size:12px; font-weight:600; }
			.aq-badge--ok { background:#eaf0ea; color:#1a6f3f; border:1px solid #b9dcc4; }
			.aq-badge--off { background:#fdf1dd; color:#7a4e0a; border:1px solid #f4d088; }
		</style>
		<?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Form settings saved.</p></div><?php endif; ?>
		<?php if (isset($_GET['tested'])) : ?>
			<?php if ($_GET['tested'] === '1') : ?><div class="notice notice-success is-dismissible"><p>Test email sent to <strong><?php echo esc_html($cfg['test_recipient']); ?></strong>. Check the inbox (and spam).</p></div>
			<?php else : ?><div class="notice notice-error is-dismissible"><p>Test email could not be sent — check the recipient address.</p></div><?php endif; ?>
		<?php endif; ?>
		<?php if (isset($_GET['synced'])) : ?>
			<?php if ($_GET['synced'] === 'err') : ?><div class="notice notice-error is-dismissible"><p>Couldn&rsquo;t check GoHighLevel for custom fields — confirm the connection and try again.</p></div>
			<?php else : ?><div class="notice notice-success is-dismissible"><p>Custom-field list refreshed — <strong><?php echo (int) $_GET['synced']; ?></strong> field<?php echo ((int) $_GET['synced'] === 1) ? '' : 's'; ?> detected in your GoHighLevel location.</p></div><?php endif; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_forms_save">
			<?php wp_nonce_field('aq_forms_save'); ?>

			<div class="aq-forms-card">
				<h2>Thank-you redirect</h2>
				<p class="aq-forms-hint">After a successful submission, visitors are sent to this page. Use a site path like <code>/thank-you/</code> or a full web address. Leave blank to keep the inline success message.</p>
				<input type="text" name="thankyou_url" value="<?php echo esc_attr($cfg['thankyou_url']); ?>" placeholder="/thank-you/">
			</div>

			<div class="aq-forms-card">
				<h2>Lead notification email</h2>
				<p class="aq-forms-hint">Every submission is emailed here, in addition to your CRM. Separate multiple addresses with commas.</p>
				<div class="aq-forms-field"><label>Send to</label><input type="text" name="notify_to" value="<?php echo esc_attr($cfg['notify_to']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"></div>
				<div class="aq-forms-field"><label>BCC</label><input type="text" name="notify_bcc" value="<?php echo esc_attr($cfg['notify_bcc']); ?>"></div>
				<div class="aq-forms-field" style="margin-bottom:0"><label>Subject</label><input type="text" name="notify_subject" value="<?php echo esc_attr($cfg['notify_subject']); ?>" placeholder="Website form submission"><p class="aq-forms-hint" style="margin:6px 0 0">Insert submitted details with merge tags: <code>{name}</code> <code>{first}</code> <code>{last}</code> <code>{email}</code> <code>{phone}</code> <code>{company}</code> <code>{city}</code> <code>{state}</code> <code>{zip}</code> <code>{service}</code> <code>{source}</code>. Example: <code>New lead: {name} &mdash; {city}, {state}</code>. Empty tags drop out automatically.</p></div>
			</div>

			<div class="aq-forms-card">
				<h2>Email logo</h2>
				<p class="aq-forms-hint">Shown at the top of every admin form-submission email. Leave blank to use the site name as text. A wide PNG or JPG works best &mdash; it&rsquo;s sized automatically to fit the header (up to 200&times;64px).</p>
				<div class="aq-forms-field" style="margin-bottom:10px"><input type="text" id="aq-email-logo-url" name="email_logo" value="<?php echo esc_attr($cfg['email_logo']); ?>" placeholder="https://&hellip;/logo.png" style="width:100%;max-width:520px"></div>
				<p style="margin:0 0 12px">
					<button type="button" class="button button-secondary" id="aq-email-logo-pick">Select / upload image</button>
					<button type="button" class="button-link" id="aq-email-logo-clear" style="margin-left:10px;color:#b32d2e">Clear</button>
				</p>
				<img id="aq-email-logo-preview" src="<?php echo esc_url($cfg['email_logo']); ?>" alt="" style="max-width:220px;max-height:80px;height:auto;border:1px solid #e6e9ee;border-radius:8px;padding:10px;background:#fff;<?php echo $cfg['email_logo'] ? '' : 'display:none'; ?>">
				<script>
				jQuery(function($){
					var frame;
					$('#aq-email-logo-pick').on('click',function(e){e.preventDefault();
						if(frame){frame.open();return;}
						frame=wp.media({title:'Select email logo',button:{text:'Use this image'},library:{type:'image'},multiple:false});
						frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();var url=a.url;$('#aq-email-logo-url').val(url);$('#aq-email-logo-preview').attr('src',url).show();document.getElementById('aq-email-logo-url').dispatchEvent(new Event('input',{bubbles:true}));});
						frame.open();
					});
					$('#aq-email-logo-clear').on('click',function(e){e.preventDefault();$('#aq-email-logo-url').val('');$('#aq-email-logo-preview').hide().attr('src','');document.getElementById('aq-email-logo-url').dispatchEvent(new Event('input',{bubbles:true}));});
				});
				</script>
			</div>

			<div class="aq-forms-card">
				<h2>Email styling</h2>
				<p class="aq-forms-hint">Customize the notification email. Leave a field blank to use your brand colors.</p>
				<div class="aq-forms-row">
					<?php
					$aq_email_colors = [
						'email_header_bg'    => 'Header background',
						'email_header_fg'    => 'Header text / logo',
						'email_accent'       => 'Accent (bar + links)',
						'email_border_color' => 'Border color',
						'email_bg'           => 'Background',
					];
					foreach ($aq_email_colors as $ck => $clabel) :
						$cv = (string) ($cfg[$ck] ?? '');
					?>
					<div class="aq-forms-field" style="margin-bottom:10px">
						<label><?php echo esc_html($clabel); ?></label>
						<span style="display:inline-flex;align-items:center;gap:8px">
							<input type="color" value="<?php echo esc_attr($cv !== '' ? $cv : '#ffffff'); ?>" data-hex-for="<?php echo esc_attr($ck); ?>" style="width:40px;height:32px;padding:0;border:1px solid #c9cfd6;border-radius:6px;cursor:pointer">
							<input type="text" name="<?php echo esc_attr($ck); ?>" id="aqf-<?php echo esc_attr($ck); ?>" value="<?php echo esc_attr($cv); ?>" placeholder="brand default" style="width:130px" pattern="#?[0-9A-Fa-f]{3,6}">
						</span>
					</div>
					<?php endforeach; ?>
					<div class="aq-forms-field" style="margin-bottom:10px">
						<label>Corner radius (px)</label>
						<input type="number" name="email_radius" min="0" max="28" value="<?php echo esc_attr((string) ($cfg['email_radius'] ?? '')); ?>" placeholder="14" style="width:90px">
					</div>
				</div>
				<script>
				(function(){
					document.querySelectorAll('input[type=color][data-hex-for]').forEach(function(pick){
						var txt=document.getElementById('aqf-'+pick.getAttribute('data-hex-for'));
						if(!txt)return;
						pick.addEventListener('input',function(){txt.value=pick.value;});
						txt.addEventListener('input',function(){ if(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(txt.value)) pick.value=txt.value; });
					});
				})();
				</script>
			</div>

			<div class="aq-forms-card">
				<h2>Push to GoHighLevel (CRM)</h2>
				<p class="aq-forms-hint">
					<?php echo $ghl_ready ? '<span class="aq-badge aq-badge--ok">Connected</span>' : '<span class="aq-badge aq-badge--off">Not connected</span>'; ?>
					&nbsp; Submissions upsert a contact into GHL and attach the message as a note. Needs a Location ID and a Private Integration Token.
				</p>
				<div class="aq-forms-field"><label>Location ID</label><input type="text" name="ghl_location" value="<?php echo esc_attr($cfg['ghl_location']); ?>" placeholder="e.g. Abc123..."></div>
				<div class="aq-forms-field" style="margin-bottom:0">
					<label>Private Integration Token (PIT)</label>
					<?php if ($ghl_locked) : ?>
						<p class="aq-forms-hint" style="margin:0"><span class="aq-badge aq-badge--ok">Locked</span> Set by the <code>AQ_GHL_TOKEN</code> constant in wp-config.php.</p>
					<?php else : ?>
						<input type="password" name="ghl_token" value="" autocomplete="off" placeholder="<?php echo $ghl_token ? '•••••••••• (saved — leave blank to keep)' : 'Paste the PIT'; ?>">
						<?php if ($ghl_token) : ?><label style="display:flex;align-items:center;gap:7px;font-weight:400;margin-top:8px;font-size:13px"><input type="checkbox" name="ghl_token_clear" value="1"> Remove the saved token</label><?php endif; ?>
						<p class="aq-forms-hint" style="margin:6px 0 0">Stored write-only — never shown again after saving.</p>
					<?php endif; ?>
				</div>
				<hr style="border:none;border-top:1px solid #e6e9ee;margin:16px 0">
				<div class="aq-forms-field" style="margin-bottom:0">
					<label style="display:flex;align-items:flex-start;gap:10px;font-weight:400;line-height:1.5">
						<input type="checkbox" name="field_sync_daily" value="1" <?php checked(!empty($cfg['field_sync_daily'])); ?> style="margin-top:3px">
						<span><strong>Auto-check for new custom fields each morning.</strong> Every URL variable a visitor arrives with (gclid, utm_*, campaign IDs, anything) is matched to a matching custom field in your GoHighLevel location and filled in on submit. This daily check picks up any fields you add in GHL so they start filling automatically.</span>
					</label>
					<?php
					$sync_meta = get_option(self::OPT_FIELD_SYNC_META, []);
					if (is_array($sync_meta) && !empty($sync_meta['time'])) :
						$when = function_exists('wp_date') ? wp_date('M j, Y \a\t g:i a', (int) $sync_meta['time']) : date('M j, Y', (int) $sync_meta['time']);
						?>
						<p class="aq-forms-hint" style="margin:8px 0 0 30px">Last checked <?php echo esc_html($when); ?> — <strong><?php echo (int) ($sync_meta['count'] ?? 0); ?></strong> custom field<?php echo ((int) ($sync_meta['count'] ?? 0) === 1) ? '' : 's'; ?> detected.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="aq-forms-card">
				<h2>Email delivery (SMTP)</h2>
				<p class="aq-forms-hint">
					<?php echo $smtp_ready ? '<span class="aq-badge aq-badge--ok">Active — via ' . esc_html($cfg['smtp_host']) . '</span>' : '<span class="aq-badge aq-badge--off">Not active — using default mail</span>'; ?>
					&nbsp; Send site email through an authenticated mailbox for reliable delivery. Leave the login blank to keep default mail.
				</p>
				<div class="aq-forms-row">
					<div class="aq-forms-field" style="flex:1 1 260px"><label>SMTP host</label><input type="text" name="smtp_host" value="<?php echo esc_attr($cfg['smtp_host']); ?>" placeholder="smtp.titan.email" style="width:100%;max-width:none"></div>
					<div class="aq-forms-field" style="width:90px"><label>Port</label><input type="number" name="smtp_port" value="<?php echo (int) $cfg['smtp_port']; ?>" style="width:100%"></div>
					<div class="aq-forms-field" style="width:130px"><label>Encryption</label>
						<select name="smtp_secure" style="width:100%">
							<option value="ssl" <?php selected($cfg['smtp_secure'], 'ssl'); ?>>SSL (465)</option>
							<option value="tls" <?php selected($cfg['smtp_secure'], 'tls'); ?>>TLS (587)</option>
							<option value="" <?php selected($cfg['smtp_secure'], ''); ?>>None</option>
						</select>
					</div>
				</div>
				<div class="aq-forms-field"><label>Mailbox login (username &amp; default From)</label><input type="email" name="smtp_user" value="<?php echo esc_attr($cfg['smtp_user']); ?>" placeholder="website@example.com"></div>
				<div class="aq-forms-row">
					<div class="aq-forms-field" style="flex:1 1 200px"><label>From address <span style="font-weight:400;color:#888">(optional)</span></label><input type="email" name="smtp_from" value="<?php echo esc_attr($cfg['smtp_from']); ?>" placeholder="(same as login)" style="width:100%;max-width:none"></div>
					<div class="aq-forms-field" style="flex:1 1 200px"><label>From name</label><input type="text" name="smtp_from_name" value="<?php echo esc_attr($cfg['smtp_from_name']); ?>" placeholder="<?php echo esc_attr(self::site_name()); ?>" style="width:100%;max-width:none"></div>
				</div>
				<div class="aq-forms-field" style="margin-bottom:0">
					<label>Mailbox password</label>
					<?php if ($smtp_locked) : ?>
						<p class="aq-forms-hint" style="margin:0"><span class="aq-badge aq-badge--ok">Locked</span> Set by the <code>AQ_SMTP_PASS</code> constant in wp-config.php.</p>
					<?php else : ?>
						<input type="password" name="smtp_pass" value="" autocomplete="new-password" placeholder="<?php echo $smtp_pass ? '•••••••••• (saved — leave blank to keep)' : 'Mailbox password'; ?>">
						<?php if ($smtp_pass) : ?><label style="display:flex;align-items:center;gap:7px;font-weight:400;margin-top:8px;font-size:13px"><input type="checkbox" name="smtp_pass_clear" value="1"> Remove the saved password</label><?php endif; ?>
						<p class="aq-forms-hint" style="margin:6px 0 0">Stored write-only — never shown again after saving.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="aq-forms-card">
				<h2>Admin test button</h2>
				<label style="display:flex;align-items:flex-start;gap:10px;line-height:1.5">
					<input type="checkbox" name="test_button" value="1" <?php checked($cfg['test_button']); ?> style="margin-top:3px">
					<span>Show a &ldquo;Fill with test data&rdquo; button on lead forms &mdash; <strong>only visible to logged-in admins</strong>. Click it to fill the form with test details.</span>
				</label>
			</div>

			<?php submit_button('Save form settings'); ?>
		</form>

		<?php if ($ghl_ready) : ?>
		<div class="aq-forms-card">
			<h2>Custom fields in GoHighLevel</h2>
			<p class="aq-forms-hint">The plugin reads the custom fields in your GHL location and fills any that match an incoming URL variable. Added a new field in GHL? Check now to pick it up immediately instead of waiting for the daily refresh.</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0">
				<input type="hidden" name="action" value="aq_forms_sync_fields">
				<?php wp_nonce_field('aq_forms_sync_fields'); ?>
				<button type="submit" class="button button-secondary">Check for new custom fields now</button>
			</form>
		</div>
		<?php endif; ?>

		<?php
		$is_default   = trim((string) ($cfg['email_template'] ?? '')) === '' && !get_transient('aq_forms_email_draft');
		$preview_tpl      = self::editor_template();
		$preview_tokens   = self::email_tokens(self::preview_mock(), true);
		$preview_defaults = self::email_theme(false); // pure brand-derived, for blank-control fallback
		$preview_html     = self::render_email($preview_tpl, $preview_tokens);
		?>
		<div class="aq-forms-card" style="max-width:900px">
			<h2>Notification email design</h2>
			<p class="aq-forms-hint">This is the email you receive on every submission. <?php echo $is_default ? '<span class="aq-badge aq-badge--off">Built-in design</span>' : '<span class="aq-badge aq-badge--ok">Custom design</span>'; ?> Adjust the colors and corner radius in <strong>Email styling</strong> above &mdash; the preview updates as you type. Save form settings to keep your changes.</p>
			<h3 style="margin:20px 0 8px;font-size:14px;">Live preview <span style="font-weight:400;color:#5b6471">(sample data)</span></h3>
			<iframe id="aq-email-preview" title="Email preview" style="width:100%;max-width:640px;height:480px;border:1px solid #dcdfe3;border-radius:10px;background:#fff" srcdoc="<?php echo esc_attr($preview_html); ?>"></iframe>
		</div>
		<script>
		(function(){
			var frame=document.getElementById('aq-email-preview');
			if(!frame)return;
			var TPL=<?php echo wp_json_encode($preview_tpl); ?>;
			var TOK=<?php echo wp_json_encode($preview_tokens); ?>;
			var DEF=<?php echo wp_json_encode([
				'header_bg'=>$preview_defaults['header_bg'], 'header_fg'=>$preview_defaults['header_fg'],
				'accent'=>$preview_defaults['accent'], 'line'=>$preview_defaults['line'],
				'soft'=>$preview_defaults['soft'], 'radius'=>(int) $preview_defaults['radius'],
				'font'=>$preview_defaults['font'],
			]); ?>;
			var SITE=<?php echo wp_json_encode(self::site_name()); ?>;
			var MAP={email_header_bg:'header_bg',email_header_fg:'header_fg',email_accent:'accent',email_border_color:'line',email_bg:'soft'};
			function hx(v){return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test((v||'').trim())?v.trim():'';}
			function render(){
				var t={}; for(var k in TOK){t[k]=TOK[k];}
				Object.keys(MAP).forEach(function(name){
					var el=document.querySelector('[name="'+name+'"]');
					t[MAP[name]]=(el?hx(el.value):'')||DEF[MAP[name]];
				});
				var r=document.querySelector('[name="email_radius"]');
				t.radius=(r&&r.value!=='')?Math.max(0,Math.min(28,parseInt(r.value,10)||0)):DEF.radius;
				// Rebuild the logo token live: image if a URL is set, else the site-name
				// wordmark tinted with the (live) header text color.
				var lu=(document.querySelector('[name="email_logo"]')||{}).value; lu=(lu||'').trim();
				var esc=function(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');};
				if(lu){ t.logo='<img src="'+esc(lu)+'" alt="'+esc(SITE)+'" style="display:block;width:auto;height:auto;max-width:200px;max-height:64px;margin:0 auto;border:0;">'; }
				else{ t.logo='<span style="font-family:'+DEF.font+';font-size:22px;font-weight:800;letter-spacing:-.01em;color:'+t.header_fg+';">'+esc(SITE)+'</span>'; }
				var html=TPL;
				Object.keys(t).forEach(function(k){ html=html.split('{{'+k+'}}').join(String(t[k])); });
				frame.srcdoc=html;
			}
			['email_header_bg','email_header_fg','email_accent','email_border_color','email_bg','email_radius','email_logo'].forEach(function(name){
				var el=document.querySelector('[name="'+name+'"]');
				if(el){el.addEventListener('input',render);el.addEventListener('change',render);}
			});
			document.querySelectorAll('input[type=color][data-hex-for]').forEach(function(p){ p.addEventListener('input',render); });
		})();
		</script>

		<div class="aq-forms-card">
			<h2>Send a test email</h2>
			<p class="aq-forms-hint">Send a styled preview of the notification email (sample data) to any address. Does <strong>not</strong> touch your CRM. Uses your SMTP settings if configured.</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="action" value="aq_forms_test">
				<?php wp_nonce_field('aq_forms_test'); ?>
				<input type="text" name="test_recipient" value="<?php echo esc_attr($cfg['test_recipient']); ?>" placeholder="you@example.com, another@example.com" required style="width:420px;max-width:100%;padding:8px 11px;border:1px solid #c9cfd6;border-radius:8px;font-size:13px">
				<button type="submit" class="button button-secondary">Send test email</button>
			</form>
		</div>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_forms_save')) {
			wp_die('Not allowed.');
		}
		$in     = wp_unslash($_POST);
		$secure = in_array($in['smtp_secure'] ?? '', ['ssl', 'tls', ''], true) ? (string) $in['smtp_secure'] : 'ssl';
		$port   = (int) ($in['smtp_port'] ?? 465);
		if ($port < 1 || $port > 65535) { $port = 465; }

		// Merge onto existing so we never wipe the legacy test-fill fields.
		$existing = self::get_settings();
		$merged   = array_merge($existing, [
			'thankyou_url'   => self::clean_url($in['thankyou_url'] ?? ''),
			'test_button'    => !empty($in['test_button']),
			'notify_to'      => self::clean_emails($in['notify_to'] ?? ''),
			'notify_bcc'     => self::clean_emails($in['notify_bcc'] ?? ''),
			'notify_subject' => sanitize_text_field($in['notify_subject'] ?? ''),
			'smtp_host'      => sanitize_text_field($in['smtp_host'] ?? ''),
			'smtp_port'      => $port,
			'smtp_secure'    => $secure,
			'smtp_user'      => sanitize_email($in['smtp_user'] ?? ''),
			'smtp_from'      => sanitize_email($in['smtp_from'] ?? ''),
			'smtp_from_name' => sanitize_text_field($in['smtp_from_name'] ?? ''),
			'ghl_location'   => sanitize_text_field($in['ghl_location'] ?? ''),
			'email_logo'     => esc_url_raw(trim((string) ($in['email_logo'] ?? ''))),
			'field_sync_daily' => !empty($in['field_sync_daily']),
			'email_header_bg'    => self::clean_hex($in['email_header_bg'] ?? ''),
			'email_header_fg'    => self::clean_hex($in['email_header_fg'] ?? ''),
			'email_accent'       => self::clean_hex($in['email_accent'] ?? ''),
			'email_border_color' => self::clean_hex($in['email_border_color'] ?? ''),
			'email_bg'           => self::clean_hex($in['email_bg'] ?? ''),
			'email_radius'       => (($in['email_radius'] ?? '') === '') ? '' : (string) max(0, min(28, (int) $in['email_radius'])),
		]);
		// Measure the (possibly new) email logo so the email can size it to fit.
		[$merged['email_logo_w'], $merged['email_logo_h']] = self::measure_logo($merged['email_logo']);
		// The email template is managed in code per site (set_email_template), not here.
		// Only touch it if this form actually submitted the field (legacy path);
		// otherwise a Forms save would silently wipe a code-authored template.
		if (isset($in['email_template'])) {
			// Stored raw by design — manage_options + nonce gated; wp_kses would strip
			// the doctype/table/inline-style structure email clients require.
			$merged['email_template'] = trim((string) wp_unslash($in['email_template']));
			delete_transient('aq_forms_email_draft'); // saved copy wins; drop any AI draft
		}
		update_option(self::OPTION, $merged, false);

		// Write-only secrets: update only when a value was entered; clear on request.
		// Google/GHL tokens are alphanumeric with - and _; strip anything else defensively.
		if (!self::ghl_locked()) {
			if (!empty($in['ghl_token_clear'])) {
				delete_option(self::OPT_GHL);
			} elseif (($t = trim((string) ($in['ghl_token'] ?? ''))) !== '') {
				update_option(self::OPT_GHL, preg_replace('/[^A-Za-z0-9._\-]/', '', $t), false);
			}
		}
		if (!self::smtp_locked()) {
			if (!empty($in['smtp_pass_clear'])) {
				delete_option(self::OPT_SMTP);
			} elseif (($p = (string) ($in['smtp_pass'] ?? '')) !== '') {
				update_option(self::OPT_SMTP, $p, false);
			}
		}

		// Bring the daily field-sync cron in line with the (possibly changed) toggle
		// and connection state now that both settings and token are saved.
		self::reconcile_field_sync_schedule();

		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	public static function send_test(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_forms_test')) {
			wp_die('Not allowed.');
		}
		// Accept one or more comma/semicolon-separated addresses.
		$to = self::clean_emails(wp_unslash($_POST['test_recipient'] ?? ''));
		if ($to === '') { $to = self::clean_emails(self::get_settings()['test_recipient']); }
		if ($to === '') { $to = (string) get_option('admin_email'); }
		$opt = get_option(self::OPTION, []);
		if (is_array($opt)) { $opt['test_recipient'] = $to; update_option(self::OPTION, $opt, false); }

		$cfg  = self::get_settings();
		$mock = [
			'first' => 'Justin', 'last' => 'Casey', 'email' => 'justin@aqmarketing.com',
			'phone' => '(781) 555-0123', 'company' => 'AQ Marketing (TEST)',
			'address' => '123 Test Street', 'city' => 'Woburn', 'state' => 'MA', 'zip' => '01801',
			'service' => 'Test service', 'message' => "This is a test of the website-form email design.\nA real submission's Reply-To is the visitor's email.", 'source' => 'Admin test',
		];
		$subject = '[TEST] ' . self::fill_subject_tokens($cfg['notify_subject'] !== '' ? $cfg['notify_subject'] : 'Website form submission', $mock);
		$ok = ($to !== '') && self::send_lead_email($mock, true, $to, $subject);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tested' => $ok ? '1' : '0'], admin_url('admin.php')));
		exit;
	}

	/* ---------------- email design helpers (rendering + per-site template) ---------------- */

	/** Sample lead used to render the live preview of the email design. */
	private static function preview_mock(): array {
		return [
			'first' => 'Jordan', 'last' => 'Rivera', 'email' => 'jordan@example.com',
			'phone' => '(781) 555-0142', 'company' => 'Rivera & Co.',
			'city' => 'Woburn', 'state' => 'MA', 'service' => 'New website',
			'message' => "Saw your site and would love a quote.\nBest time to reach me is mornings.", 'source' => 'Website form',
		];
	}

	/** The template currently shown in the preview: AI draft, else saved, else the built-in default. */
	public static function editor_template(): string {
		$draft = get_transient('aq_forms_email_draft');
		if (is_string($draft) && trim($draft) !== '') {
			return $draft;
		}
		$saved = (string) (self::get_settings()['email_template'] ?? '');
		return trim($saved) !== '' ? $saved : self::default_email_template();
	}

	/** Persist a custom email template (empty string reverts to the built-in design). */
	public static function set_email_template(string $html): void {
		$existing = self::get_settings();
		$existing['email_template'] = trim($html);
		update_option(self::OPTION, $existing, false);
		delete_transient('aq_forms_email_draft'); // saved copy wins; drop any AI draft
	}

	/** Whether a custom (non-built-in) template is currently saved. */
	public static function has_custom_template(): bool {
		return trim((string) (self::get_settings()['email_template'] ?? '')) !== '';
	}

}
