<?php
/**
 * AQ hCaptcha — fleet-wide spam protection for EVERY AutoForge lead form.
 *
 * Ships inside the engine (aq-core) so it can't be deactivated independently and
 * every site gets it on update — the "must-use" guarantee, without bundling the
 * third-party wordpress.org plugin (which doesn't know about our bespoke
 * `POST /wp-json/aqm/v1/contact` form anyway).
 *
 * How it attaches to "all forms" with zero per-template markup: a footer script
 * finds every lead form on the page (the `data-aq-lead` marker OR an action that
 * posts to the contact endpoint — the exact selector the test-fill + submit
 * handlers already use), injects an hCaptcha widget before the submit button, and
 * explicitly renders it. Because hCaptcha's hidden `h-captcha-response` field is
 * created INSIDE the form, the engine's existing FormData submit carries the token
 * up automatically — no change to the submit handler needed.
 *
 * Server side: AQ_Lead_Capture::handle() calls self::passes() before it processes
 * a submission, so verification is enforced once, centrally, for every current and
 * future form template on every site.
 *
 * KEYS: the site key is public (it appears in page HTML regardless) and lives in
 * the AutoForge → Forms setting `hcaptcha_site_key`. The secret is WRITE-ONLY
 * (never echoed back, non-autoloaded option) and can be pinned by the
 * `AQ_HCAPTCHA_SECRET` wp-config constant. Both are per-site config — NEVER
 * hardcoded in this engine (client-agnostic contract). The feature is dormant
 * until BOTH keys are present, so an un-configured site behaves exactly as before.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_HCaptcha {

	/**
	 * AQ Marketing's shared hCaptcha SITE key (public — it appears in page HTML on
	 * every site regardless). Baked in as the fleet default so hCaptcha is on by
	 * default the moment a site also has the secret; no per-site site-key entry.
	 * A per-site option or the AQ_HCAPTCHA_SITE_KEY constant still overrides it, and
	 * the `aq_hcaptcha_default_site_key` filter can change/blank it per install.
	 * The SECRET is deliberately NOT here — this repo is public, so the secret lives
	 * only in the write-only per-site option or the AQ_HCAPTCHA_SECRET constant.
	 */
	const DEFAULT_SITE_KEY = '57dbb09e-7bd5-45f8-8846-f1175f9fb33c';

	/** Write-only secret option (non-autoloaded). Overridden by the AQ_HCAPTCHA_SECRET constant. */
	const OPT_SECRET = 'aq_hcaptcha_secret';
	/** hCaptcha server-side verification endpoint. */
	const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';
	/** hCaptcha browser widget API. */
	const API_JS     = 'https://js.hcaptcha.com/1/api.js';

	public static function register(): void {
		// Print the widget-injection + API-loader script once, in the footer, after
		// the canonical lead-form submit handler (priority 6) so the form DOM is
		// present, and before the admin test-fill button (priority 20).
		add_action('wp_footer', [__CLASS__, 'print_widget'], 7);
	}

	/* ---------------- keys ---------------- */

	/**
	 * Public site key: wp-config constant wins, else the per-site Forms setting,
	 * else the fleet default (so every site has a working site key with no setup).
	 */
	public static function site_key(): string {
		if (defined('AQ_HCAPTCHA_SITE_KEY') && AQ_HCAPTCHA_SITE_KEY) {
			return (string) AQ_HCAPTCHA_SITE_KEY;
		}
		$set = (string) (AQ_Lead_Capture::get_settings()['hcaptcha_site_key'] ?? '');
		if ($set !== '') {
			return $set;
		}
		return (string) apply_filters('aq_hcaptcha_default_site_key', self::DEFAULT_SITE_KEY);
	}

	/** Secret key: wp-config constant wins, else the write-only option. */
	public static function secret(): string {
		if (defined('AQ_HCAPTCHA_SECRET') && AQ_HCAPTCHA_SECRET) {
			return (string) AQ_HCAPTCHA_SECRET;
		}
		return (string) get_option(self::OPT_SECRET, '');
	}

	public static function site_key_locked(): bool { return defined('AQ_HCAPTCHA_SITE_KEY') && (bool) AQ_HCAPTCHA_SITE_KEY; }
	public static function secret_locked(): bool    { return defined('AQ_HCAPTCHA_SECRET') && (bool) AQ_HCAPTCHA_SECRET; }
	public static function secret_saved(): bool     { return self::secret() !== ''; }

	/** Persist the write-only secret (trimmed). */
	public static function update_secret(string $val): void {
		$val = trim($val);
		if ($val !== '') {
			update_option(self::OPT_SECRET, $val, false);
		}
	}

	/** Remove the saved secret. */
	public static function clear_secret(): void {
		delete_option(self::OPT_SECRET);
	}

	/**
	 * Live only when BOTH keys are configured (filterable off with
	 * `aq_hcaptcha_enabled`). A half-configured site (site key but no secret, or
	 * vice-versa) stays dormant so we never show a widget that blocks nothing, nor
	 * enforce a check we can't render.
	 */
	public static function active(): bool {
		$on = self::site_key() !== '' && self::secret() !== '';
		return (bool) apply_filters('aq_hcaptcha_enabled', $on);
	}

	/**
	 * Logged-in admins bypass verification so the admin-only "Fill with test data"
	 * button (and internal QA) can submit without solving a challenge. Anonymous
	 * visitors — the ones that matter for spam — are always checked.
	 */
	public static function bypass(): bool {
		return is_user_logged_in() && current_user_can('manage_options');
	}

	/* ---------------- verification ---------------- */

	/**
	 * Gate a submission. True = allow (feature off, admin bypass, or token valid).
	 * Called by AQ_Lead_Capture::handle().
	 */
	public static function passes(string $token, string $remoteip = ''): bool {
		if (!self::active() || self::bypass()) {
			return true;
		}
		return self::verify($token, $remoteip);
	}

	/**
	 * Verify a token against hCaptcha. An EMPTY or explicitly-rejected token fails
	 * closed (blocked) — that's the spam case. But a transport error or malformed
	 * response fails OPEN (allowed): the engine's rule is never to lose a real lead
	 * because a third-party service was unreachable. Bots are still blocked whenever
	 * hCaptcha is up, which is ~all the time.
	 */
	public static function verify(string $token, string $remoteip = ''): bool {
		$token = trim($token);
		if ($token === '') {
			return false; // definite fail — no challenge solved
		}
		$secret = self::secret();
		if ($secret === '') {
			return true; // not configured; active() already guards, belt-and-suspenders
		}

		$body = ['secret' => $secret, 'response' => $token];
		$ip   = $remoteip !== '' ? $remoteip : self::client_ip();
		if ($ip !== '') {
			$body['remoteip'] = $ip;
		}
		$sk = self::site_key();
		if ($sk !== '') {
			$body['sitekey'] = $sk;
		}

		$resp = wp_remote_post(self::VERIFY_URL, ['timeout' => 10, 'body' => $body]);
		if (is_wp_error($resp)) {
			error_log('[aq-core] hCaptcha verify transport error: ' . $resp->get_error_message());
			return true; // fail open
		}
		$data = json_decode(wp_remote_retrieve_body($resp), true);
		if (!is_array($data) || !array_key_exists('success', $data)) {
			error_log('[aq-core] hCaptcha verify: unexpected response.');
			return true; // fail open on malformed response
		}
		return $data['success'] === true;
	}

	/** Best-effort client IP (honours a single trusted XFF hop, like the rate limiter). */
	private static function client_ip(): string {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$xff = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
			if ($xff !== '' && filter_var($xff, FILTER_VALIDATE_IP)) {
				$ip = $xff;
			}
		}
		return $ip;
	}

	/* ---------------- front-end widget ---------------- */

	/**
	 * Inject + render an hCaptcha widget into every lead form on the page, then load
	 * the hCaptcha API explicitly. Printed only when the feature is active and only
	 * loads the API when a lead form is actually present, so pages without forms pay
	 * nothing. The `.h-captcha` guard keeps it from double-injecting a form that
	 * already ships a widget.
	 */
	public static function print_widget(): void {
		if (is_admin() || !self::active()) {
			return;
		}
		$sitekey = self::site_key();
		?>
<script>(function(){
var forms=document.querySelectorAll('form[data-aq-lead],form[action*="aqm/v1/contact"],form[data-endpoint*="aqm/v1/contact"]');
if(!forms.length){return;}
var SITEKEY=<?php echo wp_json_encode($sitekey); ?>;
Array.prototype.forEach.call(forms,function(form){
if(form.querySelector('.h-captcha')){return;}
var box=document.createElement('div');
box.className='h-captcha';box.setAttribute('data-sitekey',SITEKEY);
box.style.cssText='margin:0 0 16px';
var btn=form.querySelector('[type=submit]');
if(btn&&btn.parentNode){btn.parentNode.insertBefore(box,btn);}else{form.appendChild(box);}
});
window.aqHCaptchaRender=function(){
if(!window.hcaptcha){return;}
Array.prototype.forEach.call(document.querySelectorAll('.h-captcha:not([data-aq-rendered])'),function(el){
try{window.hcaptcha.render(el,{sitekey:SITEKEY});el.setAttribute('data-aq-rendered','1');}catch(e){}
});
};
if(document.querySelector('script[data-aq-hcaptcha]')){return;}
var s=document.createElement('script');
s.src=<?php echo wp_json_encode(self::API_JS . '?render=explicit&onload=aqHCaptchaRender'); ?>;
s.async=true;s.defer=true;s.setAttribute('data-aq-hcaptcha','1');
document.head.appendChild(s);
})();</script>
		<?php
	}
}
