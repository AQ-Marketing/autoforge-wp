<?php
/**
 * AQ Lead Capture — the engine's generic handler for `POST /wp-json/aqm/v1/contact`,
 * the endpoint every lead-capture form section (aqm-contact, aqm-cta, etc.) posts
 * to. Honeypot + required-field validation, then emails the lead to the site
 * admin (the generic, client-agnostic fallback — a per-client mu-plugin may
 * swap in a CRM integration and skip registering its own route by checking
 * `class_exists('AQ_Lead_Capture')`, same pattern as AQ_Site_Config overrides).
 *
 * Also owns the AutoForge -> Forms screen: an optional thank-you-page redirect
 * after a successful submission, and an admin-only "fill with test data"
 * button so an admin can send a real test lead without typing one out. Both
 * are off-by-default-safe: no thank-you URL set = the old inline success
 * message; the test button is gated server-side on manage_options, so it
 * never reaches anonymous visitors' markup or JS at all.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Lead_Capture {

	const CAP    = 'manage_options';
	const OPTION = 'aq_forms';
	const SLUG   = 'aq-forms';

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		add_action('admin_menu', [__CLASS__, 'menu'], 25);
		add_action('admin_post_aq_forms_save', [__CLASS__, 'save']);
	}

	/** Whether this handler should register its route. A filter can force it off
	 *  (e.g. a client integration wants to take over without unloading the class). */
	public static function enabled(): bool {
		return apply_filters('aq_lead_capture_enabled', true);
	}

	/** Full settings array with every key present. */
	public static function get_settings(): array {
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		return array_merge([
			'thankyou_url'  => '',
			'test_button'   => true,
			'test_name'     => 'Test Tester',
			'test_email'    => 'test@example.com',
			'test_phone'    => '(555) 123-4567',
			'test_business' => 'Test Company',
			'test_message'  => 'TEST submission — please ignore.',
		], $o);
	}

	/* ---------------- REST ---------------- */

	public static function rest_routes(): void {
		if (!self::enabled()) {
			return;
		}
		register_rest_route('aqm/v1', '/contact', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // public lead form; guarded by honeypot + validation below
			'callback'            => [__CLASS__, 'handle'],
		]);
	}

	public static function handle(WP_REST_Request $req) {
		// Honeypot — bots fill hidden fields; pretend success and drop silently.
		if ((string) $req->get_param('company_hp') !== '') {
			return new WP_REST_Response(['ok' => true], 200);
		}

		$first    = sanitize_text_field((string) $req->get_param('firstName'));
		$last     = sanitize_text_field((string) $req->get_param('lastName'));
		$email    = sanitize_email((string) $req->get_param('email'));
		$phone    = sanitize_text_field((string) $req->get_param('phone'));
		$business = sanitize_text_field((string) $req->get_param('business'));
		$website  = sanitize_text_field((string) $req->get_param('website'));
		$service  = sanitize_text_field((string) $req->get_param('service'));
		$message  = sanitize_textarea_field((string) $req->get_param('message'));

		if ($first === '' || $last === '' || $business === '' || $email === '') {
			return new WP_REST_Response(['ok' => false, 'error' => 'missing_fields'], 422);
		}
		if (!is_email($email)) {
			return new WP_REST_Response(['ok' => false, 'error' => 'invalid_email'], 422);
		}

		$sent = self::email_admin(compact('first', 'last', 'email', 'phone', 'business', 'website', 'service', 'message'));

		return $sent
			? new WP_REST_Response(['ok' => true], 200)
			: new WP_REST_Response(['ok' => false, 'error' => 'unprocessable'], 502);
	}

	private static function email_admin(array $f): bool {
		$to = get_option('admin_email');
		if (!$to) {
			return false;
		}
		$site    = (string) (function_exists('aq_site') ? (aq_site('name') ?: get_bloginfo('name')) : get_bloginfo('name'));
		$subject = sprintf('[%s] New lead — %s %s', $site, $f['first'], $f['last']);
		$lines   = [
			"Name: {$f['first']} {$f['last']}",
			"Email: {$f['email']}",
			"Phone: {$f['phone']}",
			"Business: {$f['business']}",
			"Website: {$f['website']}",
			"Looking for: {$f['service']}",
			"",
			"Message:",
			$f['message'],
		];
		$headers = ['Content-Type: text/plain; charset=UTF-8'];
		if ($f['email'] !== '' && is_email($f['email'])) {
			$headers[] = 'Reply-To: ' . $f['email'];
		}
		return wp_mail($to, $subject, implode("\n", $lines), $headers);
	}

	/* ---------------- admin screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Forms', 'Forms', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/** Sanitize the thank-you destination: a site-relative path or a full URL; '' disables the redirect. */
	private static function clean_url($v): string {
		$v = trim((string) $v);
		if ($v === '') {
			return '';
		}
		if ($v[0] === '/') {
			$v = '/' . ltrim(preg_replace('#[^A-Za-z0-9\-_/]#', '', $v), '/');
			return $v === '/' ? '' : $v;
		}
		return esc_url_raw($v);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$cfg = self::get_settings();
		AQ_Admin_Hub::open('Forms', 'Control what happens after a lead form is submitted, and the admin-only test-fill button.', self::SLUG);
		?>
		<style>
			.aq-forms-card { background:#fff; border:1px solid #dcdfe3; border-radius:10px; padding:18px 20px; margin:0 0 18px; max-width:660px; }
			.aq-forms-card h2 { margin:0 0 6px; font-size:15px; }
			.aq-forms-card p.aq-forms-hint { margin:0 0 14px; color:#5b6471; font-size:13px; }
			.aq-forms-card input[type=text], .aq-forms-card input[type=email], .aq-forms-card textarea { width:100%; max-width:420px; padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; }
			.aq-forms-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:660px; }
			.aq-forms-field { margin-bottom:14px; }
			.aq-forms-field label { display:block; font-weight:600; color:#0d1014; margin-bottom:5px; font-size:13px; }
		</style>
		<?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Form settings saved.</p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_forms_save">
			<?php wp_nonce_field('aq_forms_save'); ?>

			<div class="aq-forms-card">
				<h2>Thank-you redirect</h2>
				<p class="aq-forms-hint">After a successful submission, visitors are sent to this page instead of seeing the inline success message. Use a site path like <code>/thank-you/</code> or a full web address. Leave blank to keep the inline message.</p>
				<input type="text" name="thankyou_url" value="<?php echo esc_attr($cfg['thankyou_url']); ?>" placeholder="/thank-you/">
			</div>

			<div class="aq-forms-card">
				<h2>Admin test button</h2>
				<label style="display:flex;align-items:flex-start;gap:10px;line-height:1.5;margin-bottom:16px">
					<input type="checkbox" name="test_button" value="1" <?php checked($cfg['test_button']); ?> style="margin-top:3px">
					<span>Show a &ldquo;Fill with test data&rdquo; button on lead forms. It is <strong>only visible to logged-in admins</strong> &mdash; the markup never reaches anonymous visitors. Click it to fill the form with the test details below.</span>
				</label>
				<div class="aq-forms-grid">
					<div class="aq-forms-field"><label>Test name</label><input type="text" name="test_name" value="<?php echo esc_attr($cfg['test_name']); ?>"></div>
					<div class="aq-forms-field"><label>Test email</label><input type="email" name="test_email" value="<?php echo esc_attr($cfg['test_email']); ?>"></div>
					<div class="aq-forms-field"><label>Test phone</label><input type="text" name="test_phone" value="<?php echo esc_attr($cfg['test_phone']); ?>"></div>
					<div class="aq-forms-field"><label>Test business</label><input type="text" name="test_business" value="<?php echo esc_attr($cfg['test_business']); ?>"></div>
				</div>
				<div class="aq-forms-field"><label>Test message</label><textarea name="test_message" rows="3"><?php echo esc_textarea($cfg['test_message']); ?></textarea></div>
			</div>

			<?php submit_button('Save form settings'); ?>
		</form>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_forms_save')) {
			wp_die('Not allowed.');
		}
		$in = wp_unslash($_POST);
		update_option(self::OPTION, [
			'thankyou_url'  => self::clean_url($in['thankyou_url'] ?? ''),
			'test_button'   => !empty($in['test_button']),
			'test_name'     => sanitize_text_field($in['test_name'] ?? ''),
			'test_email'    => sanitize_email($in['test_email'] ?? ''),
			'test_phone'    => sanitize_text_field($in['test_phone'] ?? ''),
			'test_business' => sanitize_text_field($in['test_business'] ?? ''),
			'test_message'  => sanitize_textarea_field($in['test_message'] ?? ''),
		], false);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}
}
