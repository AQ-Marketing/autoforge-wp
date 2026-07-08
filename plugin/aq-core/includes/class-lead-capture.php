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

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		add_action('admin_menu', [__CLASS__, 'menu'], 25);
		add_action('admin_post_aq_forms_save', [__CLASS__, 'save']);
		add_action('admin_post_aq_forms_test', [__CLASS__, 'send_test']);
		add_action('phpmailer_init', [__CLASS__, 'apply_smtp']);
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
			'test_recipient' => (string) get_option('admin_email'),
			'smtp_host'      => '',
			'smtp_port'      => 465,
			'smtp_secure'    => 'ssl',
			'smtp_user'      => '',
			'smtp_from'      => '',
			'smtp_from_name' => '',
			'ghl_location'   => '',
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

	private static function ghl_request(string $method, string $path, array $body) {
		return wp_remote_request(self::GHL_API . $path, [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . self::ghl_token(),
				'Version'       => self::GHL_VER,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => wp_json_encode($body),
		]);
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
	private static function email_theme(): array {
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
		];
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
		$title = $cfg['notify_subject'] !== '' ? $cfg['notify_subject'] : 'New website form submission';
		$when  = function_exists('wp_date') ? wp_date('F j, Y \a\t g:i a') : date('F j, Y');
		$site  = self::site_name();
		$host  = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		$phone = (string) (function_exists('aq_site') ? aq_site('phone') : '');

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
			'rows' => $rows, 'banner' => $banner, 'foot' => $foot,
		];
	}

	/** The engine's built-in, brand-derived template (used when no custom one is saved). */
	public static function default_email_template(): string {
		return '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:0;background:{{soft}};">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{soft}};padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid {{line}};border-radius:14px;overflow:hidden;">'
			. '<tr><td align="center" style="padding:24px 28px;background:{{header_bg}};"><span style="font-family:{{font}};font-size:22px;font-weight:800;letter-spacing:-.01em;color:{{header_fg}};">{{site}}</span></td></tr>'
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

	/** Final HTML body for the lead-notification email: custom template if saved, else the built-in one. */
	public static function lead_email_html(array $f, bool $is_test = false): string {
		$custom   = (string) (self::get_settings()['email_template'] ?? '');
		$template = trim($custom) !== '' ? $custom : self::default_email_template();
		return self::render_email($template, self::email_tokens($f, $is_test));
	}

	/** Email the lead to the configured To/BCC with the branded template. */
	private static function notify(array $f): bool {
		$cfg     = self::get_settings();
		$to      = $cfg['notify_to'] !== '' ? $cfg['notify_to'] : (string) get_option('admin_email');
		$subject = $cfg['notify_subject'] !== '' ? $cfg['notify_subject'] : 'Website form submission';
		if ($to === '') {
			return false;
		}
		$headers = ['Content-Type: text/html; charset=UTF-8'];
		if ($cfg['notify_bcc'] !== '') {
			$headers[] = 'Bcc: ' . $cfg['notify_bcc'];
		}
		if (($f['email'] ?? '') !== '' && is_email($f['email'])) {
			$headers[] = 'Reply-To: ' . $f['email'];
		}
		return (bool) wp_mail($to, $subject, self::lead_email_html($f, false), $headers);
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

	private static function clean_emails($v): string {
		$out = [];
		foreach (preg_split('/[,;]+/', (string) $v) as $e) {
			$e = sanitize_email(trim($e));
			if ($e !== '' && is_email($e)) { $out[] = $e; }
		}
		return implode(', ', $out);
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
				<div class="aq-forms-field" style="margin-bottom:0"><label>Subject</label><input type="text" name="notify_subject" value="<?php echo esc_attr($cfg['notify_subject']); ?>" placeholder="Website form submission"></div>
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

		<?php
		$is_default   = trim((string) ($cfg['email_template'] ?? '')) === '' && !get_transient('aq_forms_email_draft');
		$preview_html = self::render_email(self::editor_template(), self::email_tokens(self::preview_mock(), true));
		?>
		<div class="aq-forms-card" style="max-width:900px">
			<h2>Notification email design</h2>
			<p class="aq-forms-hint">This is the email you receive on every submission. <?php echo $is_default ? '<span class="aq-badge aq-badge--off">Built-in design</span>' : '<span class="aq-badge aq-badge--ok">Custom design</span>'; ?> The design is set in code per site, matched to the website &mdash; contact your developer to change it.</p>
			<h3 style="margin:20px 0 8px;font-size:14px;">Current design <span style="font-weight:400;color:#5b6471">(sample data)</span></h3>
			<iframe title="Email preview" style="width:100%;max-width:640px;height:480px;border:1px solid #dcdfe3;border-radius:10px;background:#fff" srcdoc="<?php echo esc_attr($preview_html); ?>"></iframe>
		</div>

		<div class="aq-forms-card">
			<h2>Send a test email</h2>
			<p class="aq-forms-hint">Send a styled preview of the notification email (sample data) to any address. Does <strong>not</strong> touch your CRM. Uses your SMTP settings if configured.</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="action" value="aq_forms_test">
				<?php wp_nonce_field('aq_forms_test'); ?>
				<input type="email" name="test_recipient" value="<?php echo esc_attr($cfg['test_recipient']); ?>" placeholder="you@example.com" required style="width:320px;max-width:100%;padding:8px 11px;border:1px solid #c9cfd6;border-radius:8px;font-size:13px">
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
		]);
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

		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	public static function send_test(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_forms_test')) {
			wp_die('Not allowed.');
		}
		$to = sanitize_email(wp_unslash($_POST['test_recipient'] ?? ''));
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
		$subject = '[TEST] ' . ($cfg['notify_subject'] !== '' ? $cfg['notify_subject'] : 'Website form submission');
		$ok = ($to !== '') && (bool) wp_mail($to, $subject, self::lead_email_html($mock, true), ['Content-Type: text/html; charset=UTF-8']);
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
