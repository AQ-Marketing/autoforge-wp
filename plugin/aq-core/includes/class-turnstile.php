<?php
/**
 * AQ Turnstile — fleet-wide spam protection for EVERY AutoForge lead form.
 *
 * Cloudflare Turnstile replaces hCaptcha (2026-08) as the bot-protection layer.
 * Ships inside the engine (aq-core) so it can't be deactivated independently and
 * every site gets it on update — the "must-use" guarantee, without bundling a
 * third-party plugin (which wouldn't know about our bespoke
 * `POST /wp-json/aqm/v1/contact` form anyway).
 *
 * How it attaches to "all forms" with zero per-template markup: a footer script
 * finds every lead form on the page (the `data-aq-lead` marker OR an action that
 * posts to the contact endpoint — the exact selector the test-fill + submit
 * handlers already use), injects a Turnstile widget before the submit button, and
 * renders it explicitly. Turnstile's hidden `cf-turnstile-response` field is
 * created INSIDE the form, so the engine's existing FormData submit carries the
 * token up automatically — no change to the submit handler needed.
 *
 * Server side: AQ_Lead_Capture::handle() calls self::passes() before it processes
 * a submission, so verification is enforced once, centrally, for every current and
 * future form template on every site.
 *
 * KEYS: unlike hCaptcha there is NO universal fleet site key — a Turnstile widget
 * is created per Cloudflare account/domain, so BOTH the site key (public, in the
 * Forms setting `turnstile_site_key` or the AQ_TURNSTILE_SITE_KEY constant) and the
 * secret (write-only option / AQ_TURNSTILE_SECRET constant) are per-site config,
 * NEVER hardcoded here (client-agnostic + public-repo contract). The feature is
 * dormant until BOTH are present, so an un-configured site behaves exactly as
 * before. Cloudflare's documented TEST keys — site `1x00000000000000000000AA`
 * (always passes) + secret `1x0000000000000000000000000000000AA` — are handy for
 * staging before the real pair is issued.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Turnstile {

	/** Write-only secret option (non-autoloaded). Overridden by the AQ_TURNSTILE_SECRET constant. */
	const OPT_SECRET = 'aq_turnstile_secret';
	/** Cloudflare Turnstile server-side verification endpoint. */
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	/** Cloudflare Turnstile browser widget API. */
	const API_JS     = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	public static function register(): void {
		// Print the widget-injection + API-loader script once, in the footer, after
		// the canonical lead-form submit handler (priority 6) so the form DOM is
		// present, and before the admin test-fill button (priority 20).
		add_action('wp_footer', [__CLASS__, 'print_widget'], 7);
	}

	/* ---------------- keys ---------------- */

	/**
	 * Public site key: wp-config constant wins, else the per-site Forms setting.
	 * No fleet default (Turnstile widgets are per Cloudflare account) — a filter
	 * (`aq_turnstile_default_site_key`) can supply one for an agency-wide widget.
	 */
	public static function site_key(): string {
		if (defined('AQ_TURNSTILE_SITE_KEY') && AQ_TURNSTILE_SITE_KEY) {
			return (string) AQ_TURNSTILE_SITE_KEY;
		}
		$set = (string) (AQ_Lead_Capture::get_settings()['turnstile_site_key'] ?? '');
		if ($set !== '') {
			return $set;
		}
		return (string) apply_filters('aq_turnstile_default_site_key', '');
	}

	/** Secret key: wp-config constant wins, else the write-only option. */
	public static function secret(): string {
		if (defined('AQ_TURNSTILE_SECRET') && AQ_TURNSTILE_SECRET) {
			return (string) AQ_TURNSTILE_SECRET;
		}
		return (string) get_option(self::OPT_SECRET, '');
	}

	public static function site_key_locked(): bool { return defined('AQ_TURNSTILE_SITE_KEY') && (bool) AQ_TURNSTILE_SITE_KEY; }
	public static function secret_locked(): bool    { return defined('AQ_TURNSTILE_SECRET') && (bool) AQ_TURNSTILE_SECRET; }
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
	 * `aq_turnstile_enabled`). A half-configured site (site key but no secret, or
	 * vice-versa) stays dormant so we never show a widget that blocks nothing, nor
	 * enforce a check we can't render.
	 */
	public static function active(): bool {
		$on = self::site_key() !== '' && self::secret() !== '';
		return (bool) apply_filters('aq_turnstile_enabled', $on);
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
	 * Verify a token against Turnstile. An EMPTY or explicitly-rejected token fails
	 * closed (blocked) — that's the spam case. But a transport error or malformed
	 * response fails OPEN (allowed): the engine's rule is never to lose a real lead
	 * because a third-party service was unreachable. Bots are still blocked whenever
	 * Turnstile is up, which is ~all the time.
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

		$resp = wp_remote_post(self::VERIFY_URL, ['timeout' => 10, 'body' => $body]);
		if (is_wp_error($resp)) {
			error_log('[aq-core] Turnstile verify transport error: ' . $resp->get_error_message());
			return true; // fail open
		}
		$data = json_decode(wp_remote_retrieve_body($resp), true);
		if (!is_array($data) || !array_key_exists('success', $data)) {
			error_log('[aq-core] Turnstile verify: unexpected response.');
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
	 * Inject + render a Turnstile widget into every lead form on the page, then load
	 * the Turnstile API explicitly. Printed only when the feature is active and only
	 * loads the API when a lead form is actually present, so pages without forms pay
	 * nothing. The `.cf-turnstile` guard keeps it from double-injecting a form that
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
if(form.querySelector('.cf-turnstile')){return;}
var box=document.createElement('div');
box.className='cf-turnstile';box.setAttribute('data-sitekey',SITEKEY);
box.style.cssText='margin:0 0 16px';
var btn=form.querySelector('[type=submit]');
if(btn&&btn.parentNode){btn.parentNode.insertBefore(box,btn);}else{form.appendChild(box);}
});
window.aqTurnstileRender=function(){
if(!window.turnstile){return;}
Array.prototype.forEach.call(document.querySelectorAll('.cf-turnstile:not([data-aq-rendered])'),function(el){
try{window.turnstile.render(el,{sitekey:SITEKEY});el.setAttribute('data-aq-rendered','1');}catch(e){}
});
};
if(document.querySelector('script[data-aq-turnstile]')){return;}
var s=document.createElement('script');
s.src=<?php echo wp_json_encode(self::API_JS . '?render=explicit&onload=aqTurnstileRender'); ?>;
s.async=true;s.defer=true;s.setAttribute('data-aq-turnstile','1');
document.head.appendChild(s);
})();</script>
		<?php
	}
}
