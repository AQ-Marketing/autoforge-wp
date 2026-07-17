<?php
/**
 * AQ Chatbot — pick this site's chatbot from a dropdown. Nothing to configure:
 * the chatbot API base URL and the agency key are baked into the plugin, so the
 * only thing an editor does is choose a chatbot and save.
 *
 * Baked-in config (not shown in the UI):
 *   - API base URL  → self::DEFAULT_API (override with the AQ_CHATBOT_API_BASE
 *                     constant only for self-hosted/dev).
 *   - Agency key    → the AQ_AGENCY_PLUGIN_KEY constant (defined once for the
 *                     platform — in the plugin build or wp-config). It matches
 *                     AGENCY_PLUGIN_KEY on the chatbot server and is only ever
 *                     sent server-to-server over HTTPS. Because it gates a
 *                     low-sensitivity list (chatbot slugs are already public in
 *                     every embed) it's safe to ship in the plugin.
 *
 * The dropdown is populated server-side (admin screen only, cached), so visitor
 * page loads do zero extra work — the front end just gets the two async embed
 * tags.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Chatbot {

	const CAP         = 'manage_options';
	const SLUG        = 'aq-chatbot';
	const OPTION      = 'aq_chatbot';   // { tenant_slug, enabled }
	const LIST_TTL    = 300;            // cache the chatbot list 5 min
	const DEFAULT_API = 'https://api-production-3701.up.railway.app';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 24);
		add_action('admin_post_aq_chatbot_save', [__CLASS__, 'save']);
		add_action('wp_footer', [__CLASS__, 'print_embed'], 20);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Chatbot', 'Chatbot', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/* ---------------- baked-in config ---------------- */

	/** Chatbot API base — baked constant; overridable only via constant. */
	public static function api_base(): string {
		$base = defined('AQ_CHATBOT_API_BASE') && AQ_CHATBOT_API_BASE ? (string) AQ_CHATBOT_API_BASE : self::DEFAULT_API;
		return untrailingslashit($base);
	}

	/** Agency key — baked into the platform via the AQ_AGENCY_PLUGIN_KEY constant. */
	public static function key(): string {
		return defined('AQ_AGENCY_PLUGIN_KEY') && AQ_AGENCY_PLUGIN_KEY ? (string) AQ_AGENCY_PLUGIN_KEY : '';
	}

	/* ---------------- settings ---------------- */

	public static function get_settings(): array {
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		return array_merge(['tenant_slug' => '', 'enabled' => false], $o);
	}

	/* ---------------- chatbot list (server-side, cached) ---------------- */

	/**
	 * Fetch { slug, name } chatbots from the key-guarded API endpoint.
	 * Returns ['ok'=>bool, 'list'=>[[slug,name]], 'error'=>string]. Cached.
	 */
	public static function fetch_chatbots(bool $force = false): array {
		$api = self::api_base();

		$cache_key = 'aq_chatbot_list_' . md5($api);
		if (!$force) {
			$cached = get_transient($cache_key);
			if (is_array($cached)) {
				return $cached;
			}
		}

		// The endpoint is public (returns only names/slugs). Send the agency key
		// only if one is configured, so a future keyed setup keeps working.
		$headers = ['Accept' => 'application/json'];
		$key = self::key();
		if ($key !== '') { $headers['X-Agency-Key'] = $key; }
		$resp = wp_remote_get($api . '/api/plugin/chatbots', [
			'timeout' => 15,
			'headers' => $headers,
		]);

		if (is_wp_error($resp)) {
			$out = ['ok' => false, 'list' => [], 'error' => 'unreachable'];
			set_transient($cache_key, $out, 30);
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200 || !is_array($body) || !isset($body['chatbots']) || !is_array($body['chatbots'])) {
			$err = $code === 401 ? 'bad_key' : ($code === 503 ? 'server_not_configured' : 'bad_response');
			$out = ['ok' => false, 'list' => [], 'error' => $err];
			set_transient($cache_key, $out, 30);
			return $out;
		}

		$list = [];
		foreach ($body['chatbots'] as $c) {
			$slug = isset($c['slug']) ? preg_replace('/[^a-z0-9-]/', '', strtolower((string) $c['slug'])) : '';
			$name = isset($c['name']) ? sanitize_text_field((string) $c['name']) : '';
			if ($slug !== '') {
				$list[] = ['slug' => $slug, 'name' => $name !== '' ? $name : $slug];
			}
		}
		$out = ['ok' => true, 'list' => $list, 'error' => ''];
		set_transient($cache_key, $out, self::LIST_TTL);
		return $out;
	}

	private static function bust_list_cache(): void {
		delete_transient('aq_chatbot_list_' . md5(self::api_base()));
	}

	/* ---------------- front-end embed ---------------- */

	public static function print_embed(): void {
		if (is_admin()) {
			return;
		}
		$s    = self::get_settings();
		$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($s['tenant_slug'] ?? '')));
		if (empty($s['enabled']) || $slug === '') {
			return;
		}
		$config = ['apiBaseUrl' => self::api_base(), 'tenantSlug' => $slug];
		echo "\n<!-- Agency Chatbot (AutoForge) -->\n";
		echo '<script>window.AgencyChatbotConfig = ' . wp_json_encode($config) . ';</script>' . "\n";
		echo '<script src="' . esc_url(self::api_base() . '/widget.js') . '" async></script>' . "\n";
	}

	/* ---------------- admin screen ---------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$s      = self::get_settings();
		$fetch  = self::fetch_chatbots(isset($_GET['aq_refresh']));
		$errors = [
			'no_key'                => 'The chatbot integration key isn\'t set in this build yet.',
			'unreachable'           => 'Could not reach the chatbot service. Try again shortly.',
			'bad_key'               => 'The chatbot service rejected the built-in key. It may need to be re-synced.',
			'server_not_configured' => 'The chatbot service isn\'t finished setting up yet.',
			'bad_response'          => 'The chatbot service returned an unexpected response.',
		];

		AQ_Admin_Hub::open('Chatbot', 'Choose which chatbot appears on this site.', self::SLUG);
		?>
		<style>
			.aq-cb-field{margin-bottom:16px;max-width:520px}
			.aq-cb-field label{display:block;font-weight:600;color:#0d1014;margin-bottom:6px}
			.aq-cb-field select{width:100%;padding:9px 12px;border:1px solid #c9cfd6;border-radius:8px;font-size:14px;color:#0d1014}
			.aq-cb-hint{font-size:12px;color:#5b6471;margin:6px 0 0}
			.aq-cb-banner{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px}
			.aq-cb-banner--ok{background:#eaf0ea;color:#1a6f3f;border:1px solid #b9dcc4}
			.aq-cb-banner--warn{background:#fdf1dd;color:#7a4e0a;border:1px solid #f4d088}
			.aq-cb-toggle{display:flex;align-items:center;gap:.5em;font-weight:600;color:#0d1014}
		</style>

		<?php if (isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>

		<?php if (!$fetch['ok']) : ?>
			<div class="aq-cb-banner aq-cb-banner--warn"><?php echo esc_html($errors[$fetch['error']] ?? 'Chatbot list is unavailable right now.'); ?></div>
		<?php elseif (!empty($s['enabled']) && $s['tenant_slug'] !== '') : ?>
			<div class="aq-cb-banner aq-cb-banner--ok"><strong>Live on this site.</strong></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_chatbot_save">
			<?php wp_nonce_field('aq_chatbot_save'); ?>

			<div class="aq-panel">
				<div class="aq-cb-field">
					<label for="aq-cb-slug">Chatbot <?php echo AQ_Admin_Hub::tip('Which chat assistant appears on your site to answer visitor questions. Choose None to show none.'); ?></label>
					<select id="aq-cb-slug" name="tenant_slug" <?php disabled(!$fetch['ok']); ?>>
						<option value="">— None —</option>
						<?php foreach ($fetch['list'] as $c) : ?>
							<option value="<?php echo esc_attr($c['slug']); ?>" <?php selected($s['tenant_slug'], $c['slug']); ?>><?php echo esc_html($c['name']); ?></option>
						<?php endforeach; ?>
						<?php // keep a saved-but-missing slug selectable so it isn't silently dropped ?>
						<?php if ($s['tenant_slug'] !== '' && !in_array($s['tenant_slug'], array_column($fetch['list'], 'slug'), true)) : ?>
							<option value="<?php echo esc_attr($s['tenant_slug']); ?>" selected><?php echo esc_html($s['tenant_slug']); ?></option>
						<?php endif; ?>
					</select>
					<?php if ($fetch['ok']) : ?>
						<p class="aq-cb-hint"><?php echo count($fetch['list']); ?> available · <a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'aq_refresh' => '1'], admin_url('admin.php'))); ?>">Refresh</a></p>
					<?php endif; ?>
				</div>

				<div class="aq-cb-field">
					<label class="aq-cb-toggle"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Show this chatbot on the site</label>
				</div>
			</div>

			<?php submit_button('Save'); ?>
		</form>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_chatbot_save')) {
			wp_die('Not allowed.');
		}
		$in   = wp_unslash($_POST);
		$slug = isset($in['tenant_slug']) ? preg_replace('/[^a-z0-9-]/', '', strtolower((string) $in['tenant_slug'])) : '';
		update_option(self::OPTION, [
			'tenant_slug' => $slug,
			'enabled'     => !empty($in['enabled']),
		], false);
		self::bust_list_cache();
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}
}
