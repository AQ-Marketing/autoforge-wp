<?php
/**
 * AQ Integrations — secure store + admin tab for third-party API credentials.
 *
 * Holds the Claude (Anthropic) API key and DataForSEO login/password (grouped
 * per service, each with its own inline "Test" button). The Claude key powers
 * every AI feature in the plugin (SEO Agent, form-email editor).
 *
 * Security model:
 *  - manage_options only (view, save, test).
 *  - Each secret can be set via a wp-config constant (AQ_ANTHROPIC_KEY,
 *    AQ_DATAFORSEO_LOGIN, AQ_DATAFORSEO_PASSWORD) — most secure, never in the DB.
 *  - DB-stored values are AES-256-CBC encrypted with a key derived from the
 *    site's wp-config salts (wp_salt), so a DB dump alone cannot reveal them.
 *  - The option is NOT autoloaded and NOT exposed via REST. The stored secret is
 *    never echoed to HTML (only a masked ••••last4 hint) and never sent to JS.
 *    "Test" runs server-side and returns pass/fail only.
 *
 * Consumers read credentials server-side via AQ_Integrations::anthropic_key() and
 * AQ_Integrations::dataforseo().
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Integrations {

	const CAP    = 'manage_options';
	const OPTION = 'aq_integrations';

	/** Services, each with its own field(s) + Test button. */
	private static function integrations(): array {
		return [
			'anthropic' => [
				'label'  => 'Claude (Anthropic)',
				'desc'   => 'Powers every AI feature: the SEO Agent write-up and the form-email editor.',
				'fields' => [
					'anthropic_key' => ['label' => 'API key', 'constant' => 'AQ_ANTHROPIC_KEY', 'hint' => 'Starts with "sk-ant-". Create one at console.anthropic.com.', 'tip' => 'Secret key that lets the site use Claude for its AI features.'],
				],
			],
			'openai' => [
				'label'  => 'OpenAI (ChatGPT)',
				'desc'   => 'Optional alternative provider for automatic image alt text (AutoForge → Media). Claude is used unless a Media page model from OpenAI is selected.',
				'fields' => [
					'openai_key' => ['label' => 'API key', 'constant' => 'AQ_OPENAI_KEY', 'hint' => 'Starts with "sk-". Create one at platform.openai.com.', 'tip' => 'Secret key that lets the site use OpenAI (ChatGPT) for image alt text.'],
				],
			],
			'dataforseo' => [
				'label'  => 'DataForSEO',
				'desc'   => 'Keyword & SERP data API (HTTP Basic auth: login + password).',
				'fields' => [
					'dataforseo_login'    => ['label' => 'Login (username / email)', 'constant' => 'AQ_DATAFORSEO_LOGIN', 'hint' => 'Your DataForSEO API login (the email/login from your dashboard). Shown here so you can confirm it — use Hide if you prefer.', 'visible' => true, 'tip' => 'The login or email for your DataForSEO account.'],
					'dataforseo_password' => ['label' => 'Password', 'constant' => 'AQ_DATAFORSEO_PASSWORD', 'hint' => 'Your DataForSEO API password (the API password, not your account password). Kept hidden.', 'tip' => 'Your DataForSEO API password, not your account password.'],
				],
			],
			'gsc' => [
				'label'  => 'Google Search Console',
				'desc'   => 'Connect your agency Google account once, then pick this site\'s property. Feeds the site\'s REAL Google performance (average position, impressions, clicks) into the same 14-day ranking audit as DataForSEO.',
				'fields' => [
					'gsc_oauth_client_id'     => ['label' => 'Google OAuth client ID', 'constant' => 'AQ_GSC_OAUTH_CLIENT_ID', 'hint' => 'The client_id from your Google OAuth client (ends in .apps.googleusercontent.com). Same value for every site.', 'visible' => true, 'tip' => 'Identifies your agency Google app. Not secret.'],
					'gsc_oauth_client_secret' => ['label' => 'Google OAuth client secret', 'constant' => 'AQ_GSC_OAUTH_CLIENT_SECRET', 'hint' => 'The client_secret from your Google OAuth client. Kept encrypted; paste to replace.', 'tip' => 'Secret that pairs with the client ID. Kept encrypted.'],
					'gsc_oauth_refresh_token' => ['label' => 'Google OAuth refresh token', 'constant' => 'AQ_GSC_OAUTH_REFRESH_TOKEN', 'hint' => 'The read-only (webmasters.readonly) refresh token for your agency Google account — this is what lets the site list your properties. Kept encrypted; paste to replace.', 'tip' => 'Read-only Google login token. Kept encrypted; never shown again.'],
					'gsc_client_email' => ['label' => 'Service account email', 'constant' => 'AQ_GSC_CLIENT_EMAIL', 'hint' => 'The client_email from the service-account JSON, e.g. name@project.iam.gserviceaccount.com. Add this account as a user of the Search Console property.', 'visible' => true, 'tip' => 'The service-account email that reads your Search Console data.'],
					'gsc_private_key'  => ['label' => 'Service account private key (PEM)', 'constant' => 'AQ_GSC_PRIVATE_KEY', 'hint' => 'The private_key value from the service-account JSON — the whole -----BEGIN PRIVATE KEY----- … -----END PRIVATE KEY----- block, newlines and all. Kept encrypted; paste to replace.', 'multiline' => true, 'tip' => 'The private key from the service-account JSON. Multi-line; paste it exactly.'],
					'gsc_site_url'     => ['label' => 'Search Console property', 'constant' => 'AQ_GSC_SITE_URL', 'hint' => 'Pick the property this site should read. The list is pulled live from your connected Google account — paste the OAuth login above and Save first, then choose here.', 'choices' => 'gsc_sites', 'tip' => 'The Search Console property to read — chosen from a live list, not typed.'],
				],
			],
			'github' => [
				'label'  => 'GitHub',
				'desc'   => 'Used by the Import tool to pull a site from a private GitHub repo. Public repos need no token.',
				'fields' => [
					'github_token' => ['label' => 'Personal access token', 'constant' => 'AQ_GITHUB_TOKEN', 'hint' => 'A fine-grained or classic PAT with read access to the repo. Leave empty if you only import public repos.', 'tip' => 'A GitHub token that lets the Import tool read a private repo.'],
				],
			],
		];
	}

	/** Flat field map (field => def), derived from the grouped integrations. */
	private static function fields(): array {
		$out = [];
		foreach (self::integrations() as $ig) {
			foreach ($ig['fields'] as $key => $def) {
				$out[$key] = $def;
			}
		}
		return $out;
	}

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 21);
		add_action('admin_post_aq_integrations_save', [__CLASS__, 'save_settings']);
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	/* ---------------- accessors (server-side) ---------------- */

	public static function get(string $field): string {
		$def = self::fields()[$field] ?? null;
		if ($def && !empty($def['constant']) && defined($def['constant']) && constant($def['constant'])) {
			return (string) constant($def['constant']);
		}
		$opts   = get_option(self::OPTION, []);
		$stored = is_array($opts) ? (string) ($opts[$field] ?? '') : '';
		return self::decrypt($stored);
	}

	public static function has(string $field): bool {
		return self::get($field) !== '';
	}

	/** True when the value is locked by a wp-config constant (DB field ignored). */
	public static function is_constant(string $field): bool {
		$def = self::fields()[$field] ?? null;
		return $def && !empty($def['constant']) && defined($def['constant']) && constant($def['constant']);
	}

	public static function anthropic_key(): string {
		return self::get('anthropic_key');
	}

	public static function openai_key(): string {
		return self::get('openai_key');
	}

	/** ['login' => ..., 'password' => ...] for DataForSEO HTTP Basic auth. */
	public static function dataforseo(): array {
		return ['login' => self::get('dataforseo_login'), 'password' => self::get('dataforseo_password')];
	}

	/** ['client_email' => ..., 'private_key' => ..., 'site_url' => ...] for the GSC service account. */
	public static function gsc(): array {
		return [
			'client_email' => self::get('gsc_client_email'),
			'private_key'  => self::get('gsc_private_key'),
			'site_url'     => self::get('gsc_site_url'),
		];
	}

	public static function github_token(): string {
		return self::get('github_token');
	}

	/* ---------------- encryption at rest ---------------- */

	private static function enc_key(): string {
		// 32-byte key derived from the wp-config salts — never stored in the DB.
		return hash('sha256', wp_salt('secure_auth') . '|aq-integrations', true);
	}

	private static function encrypt(string $plain): string {
		if ($plain === '') {
			return '';
		}
		if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
			return 'b64:' . base64_encode($plain); // openssl unavailable — see admin warning
		}
		$iv     = openssl_random_pseudo_bytes(16);
		$cipher = openssl_encrypt($plain, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			return 'b64:' . base64_encode($plain);
		}
		return 'enc:' . base64_encode($iv . $cipher);
	}

	private static function decrypt(string $stored): string {
		if ($stored === '') {
			return '';
		}
		if (strpos($stored, 'enc:') === 0) {
			if (!function_exists('openssl_decrypt')) {
				return '';
			}
			$raw = base64_decode(substr($stored, 4), true);
			if ($raw === false || strlen($raw) <= 16) {
				return '';
			}
			$plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
			return $plain === false ? '' : $plain;
		}
		if (strpos($stored, 'b64:') === 0) {
			return (string) base64_decode(substr($stored, 4));
		}
		return $stored; // legacy plaintext (pre-encryption)
	}

	private static function mask(string $value): string {
		$len = strlen($value);
		if ($len === 0) {
			return '';
		}
		return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', 8) . substr($value, -4);
	}

	/* ---------------- settings screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Integrations', 'Integrations', self::CAP, 'aq-integrations', [__CLASS__, 'render']);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		// "Refresh list" on the GSC property picker: drop the cached sites.list so
		// this render re-pulls it live. Cap-gated above; only clears a transient.
		if (isset($_GET['refresh_gsc']) && class_exists('AQ_Ranking_Audit')) {
			AQ_Ranking_Audit::flush_gsc_sites_cache();
		}
		$no_openssl = !function_exists('openssl_encrypt');
		AQ_Admin_Hub::open('Integrations', 'Connect third-party services. Keys are encrypted at rest and never shown to the public.', 'aq-integrations');
		?>
		<style>
			.aq-int-field { margin-bottom: 16px; }
			.aq-int-field label { display:block; font-weight:600; color:#0d1014; margin-bottom:5px; }
			.aq-int-field input[type=password], .aq-int-field input[type=text] {
				width:100%; max-width:480px; padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; }
			.aq-int-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
			.aq-int-locked { color:#9a6212; font-weight:600; font-size:12px; }
			.aq-int-clear { font-size:12px; color:#5b6471; display:inline-flex; align-items:center; gap:5px; }
			.aq-int-hint { font-size:12px; color:#5b6471; margin:5px 0 0; }
			.aq-int-svc__head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; flex-wrap:wrap; }
			.aq-int-test { display:flex; align-items:center; gap:10px; margin-top:6px; padding-top:12px; border-top:1px solid #eef1f5; }
			.aq-int-test-msg { font-size:12px; }
		</style>
		<?php if (isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Integrations saved.</p></div>
		<?php endif; ?>
		<?php if ($no_openssl) : ?>
			<div class="notice notice-warning inline"><p><strong>Heads up:</strong> PHP's OpenSSL extension isn't available, so secrets are stored base64-encoded (obfuscated, not encrypted). For full encryption-at-rest, enable OpenSSL or define the keys as constants in <code>wp-config.php</code>.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
			<input type="hidden" name="action" value="aq_integrations_save">
			<?php wp_nonce_field('aq_integrations_save'); ?>
			<?php foreach (self::integrations() as $svc => $ig) : ?>
				<div class="aq-panel">
					<div class="aq-int-svc__head">
						<h2 style="margin:0;"><?php echo esc_html($ig['label']); ?></h2>
					</div>
					<?php
						// GSC: when the agency Google login is connected (constants set via the
						// private mu-plugin), hide the service-account credential fields — that
						// account already sees every property, so only the picker is needed.
						$gsc_oauth = ($svc === 'gsc') && class_exists('AQ_Ranking_Audit') && AQ_Ranking_Audit::has_gsc_oauth();
						?>
						<p class="aq-int-hint" style="margin:4px 0 14px;"><?php echo esc_html($gsc_oauth ? 'Feeds the site\'s REAL Google performance (average position, impressions, clicks) into the 14-day ranking audit.' : $ig['desc']); ?></p>
						<?php if ($gsc_oauth) : ?>
							<p style="margin:0 0 14px;color:#1a8f4f;font-weight:600;font-size:13px;">✓ Connected via your agency Google account — pick this site's property below.</p>
						<?php endif; ?>
					<?php foreach ($ig['fields'] as $key => $def) :
						if ($key === 'gsc_client_email' || $key === 'gsc_private_key') {
							continue; // legacy service-account path — settable via wp-config constant only, not shown in the UI
						}
						$locked  = self::is_constant($key);
						$cur     = self::get($key);
						$set     = $cur !== '';
						$visible = !empty($def['visible']);
						?>
						<div class="aq-int-field">
							<label for="aq-int-<?php echo esc_attr($key); ?>"><?php echo esc_html($def['label']); ?><?php echo AQ_Admin_Hub::tip($def['tip'] ?? ''); ?></label>
							<div class="aq-int-row">
								<?php if (!empty($def['choices']) && $def['choices'] === 'gsc_sites') :
									// Live property dropdown, populated by the service account's sites.list.
									$list       = class_exists('AQ_Ranking_Audit') ? AQ_Ranking_Audit::gsc_sites() : ['ok' => false, 'error' => 'Ranking audit unavailable.'];
									$sites      = !empty($list['ok']) ? (array) $list['sites'] : [];
									$urls       = array_column($sites, 'url');
									$host       = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
									$host       = preg_replace('/^www\./', '', $host);
									$suggested  = ($sites && class_exists('AQ_Ranking_Audit')) ? AQ_Ranking_Audit::suggest_gsc_property($host, $urls) : null;
									$refreshUrl = esc_url(add_query_arg(['page' => 'aq-integrations', 'refresh_gsc' => '1']));
									?>
									<?php if ($locked) : ?>
										<input type="text" id="aq-int-<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($cur); ?>" disabled>
									<?php elseif (!empty($list['ok'])) : ?>
										<select id="aq-int-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" style="width:100%;max-width:480px;padding:8px 11px;border:1px solid #c9cfd6;border-radius:8px;font-size:13px;color:#0d1014;">
											<option value="">— Select a property —</option>
											<?php if ($cur !== '' && !in_array($cur, $urls, true)) : ?>
												<option value="<?php echo esc_attr($cur); ?>" selected><?php echo esc_html($cur); ?> (current — not in list)</option>
											<?php endif; ?>
											<?php foreach ($sites as $s) : ?>
												<option value="<?php echo esc_attr($s['url']); ?>" <?php selected($cur, $s['url']); ?>><?php echo esc_html($s['url']); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="button" class="button aq-int-refresh" data-href="<?php echo $refreshUrl; ?>">Refresh list</button>
									<?php else : // connected creds missing or the list call failed — hand-typed fallback ?>
										<input type="text" id="aq-int-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" autocomplete="off" value="<?php echo esc_attr($cur); ?>" placeholder="sc-domain:example.com or https://example.com/">
									<?php endif; ?>
								<?php elseif (!empty($def['multiline'])) : // multi-line secret (PEM) — textarea, value never prefilled ?>
										<textarea
											id="aq-int-<?php echo esc_attr($key); ?>"
											name="<?php echo esc_attr($key); ?>"
											autocomplete="off"
											rows="4"
											spellcheck="false"
											style="width:100%;max-width:480px;padding:8px 11px;border:1px solid #c9cfd6;border-radius:8px;font-size:12px;font-family:monospace;color:#0d1014;"
											placeholder="<?php echo $set ? 'Saved — leave blank to keep, or paste to replace' : 'Not set'; ?>"
											<?php disabled($locked); ?>></textarea>
									<?php elseif ($visible) : // username/email — shown by default, with a Hide/Show toggle ?>
									<input
										type="text"
										id="aq-int-<?php echo esc_attr($key); ?>"
										name="<?php echo esc_attr($key); ?>"
										autocomplete="off"
										value="<?php echo esc_attr($cur); ?>"
										placeholder="Not set"
										<?php disabled($locked); ?>>
									<?php if (!$locked) : ?>
										<button type="button" class="button aq-int-eye" data-target="aq-int-<?php echo esc_attr($key); ?>">Hide</button>
									<?php endif; ?>
								<?php else : ?>
									<input
										type="password"
										id="aq-int-<?php echo esc_attr($key); ?>"
										name="<?php echo esc_attr($key); ?>"
										autocomplete="new-password"
										placeholder="<?php echo $set ? esc_attr(self::mask($cur)) : 'Not set'; ?>"
										<?php disabled($locked); ?>>
								<?php endif; ?>
								<?php if ($locked) : ?>
									<span class="aq-int-locked">Locked by wp-config constant <code><?php echo esc_html($def['constant']); ?></code></span>
								<?php elseif ($set) : ?>
									<label class="aq-int-clear"><input type="checkbox" name="clear_<?php echo esc_attr($key); ?>" value="1"> Remove</label>
								<?php endif; ?>
							</div>
							<p class="aq-int-hint"><?php echo esc_html($def['hint']); ?></p>
							<?php if (!empty($def['choices']) && $def['choices'] === 'gsc_sites' && !$locked && class_exists('AQ_Ranking_Audit')) : ?>
								<?php if (!empty($list['ok']) && $suggested && $suggested !== $cur) : ?>
									<p class="aq-int-hint">Suggested for this site: <code><?php echo esc_html($suggested); ?></code>
										<button type="button" class="button-link aq-int-use-suggested" data-target="aq-int-<?php echo esc_attr($key); ?>" data-value="<?php echo esc_attr($suggested); ?>" style="margin-left:6px;">Use this</button></p>
								<?php elseif (empty($list['ok']) && AQ_Ranking_Audit::has_gsc_connection()) : ?>
									<p class="aq-int-hint" style="color:#a30d25;">Couldn't load the property list: <?php echo esc_html((string) ($list['error'] ?? 'unknown error')); ?> — you can type the property manually above, then Save.</p>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<div class="aq-int-test">
						<button type="button" class="aq-btn aq-btn--ghost" data-aq-test="<?php echo esc_attr($svc); ?>" style="background:#eef1f5;color:#15191f;">Test connection</button>
						<span class="aq-int-test-msg" id="aq-int-test-<?php echo esc_attr($svc); ?>" role="status" aria-live="polite">Tests the saved credentials.</span>
					</div>
				</div>
			<?php endforeach; ?>
			<p class="aq-int-hint" style="margin:0 0 14px;">Leave a field blank to keep the saved value. You can also define <code>AQ_ANTHROPIC_KEY</code>, <code>AQ_DATAFORSEO_LOGIN</code>, and <code>AQ_DATAFORSEO_PASSWORD</code> in <code>wp-config.php</code> to keep keys out of the database entirely. Save before testing.</p>
			<?php submit_button('Save integrations'); ?>
		</form>

		<script>
		(function () {
			var root = '<?php echo esc_url_raw(rest_url('aq/v1/integrations/test')); ?>';
			var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
			document.querySelectorAll('[data-aq-test]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var svc = btn.getAttribute('data-aq-test');
					var msg = document.getElementById('aq-int-test-' + svc);
					btn.disabled = true; msg.textContent = 'Testing…'; msg.style.color = '#5b6471';
					fetch(root + '/' + svc, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							btn.disabled = false;
							var ok = d && d.ok;
							msg.textContent = (ok ? '✓ ' : '✕ ') + ((d && d.message) || (ok ? 'Connected' : 'Failed'));
							msg.style.color = ok ? '#1a8f4f' : '#a30d25';
						})
						.catch(function (e) { btn.disabled = false; msg.textContent = '✕ ' + e.message; msg.style.color = '#a30d25'; });
				});
			});
			document.querySelectorAll('.aq-int-eye').forEach(function (b) {
				b.addEventListener('click', function () {
					var inp = document.getElementById(b.getAttribute('data-target'));
					if (!inp) { return; }
					if (inp.type === 'password') { inp.type = 'text'; b.textContent = 'Hide'; }
					else { inp.type = 'password'; b.textContent = 'Show'; }
				});
			});
			// GSC property picker: apply the suggested property, and refresh the live list.
			document.querySelectorAll('.aq-int-use-suggested').forEach(function (b) {
				b.addEventListener('click', function () {
					var sel = document.getElementById(b.getAttribute('data-target'));
					if (sel) { sel.value = b.getAttribute('data-value'); }
				});
			});
			document.querySelectorAll('.aq-int-refresh').forEach(function (b) {
				b.addEventListener('click', function () { window.location.href = b.getAttribute('data-href'); });
			});
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save_settings(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_integrations_save')) {
			wp_die('Not allowed.');
		}
		$opts = get_option(self::OPTION, []);
		if (!is_array($opts)) {
			$opts = [];
		}
		foreach (self::fields() as $key => $def) {
			if (self::is_constant($key)) {
				continue; // managed in wp-config; ignore the DB field
			}
			if (!empty($_POST['clear_' . $key])) {
				unset($opts[$key]);
				continue;
			}
			$val = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
			if ($val !== '') {
				// Multi-line secrets (a PEM private key) must keep their newlines —
				// sanitize_text_field would collapse them and corrupt the key.
				$clean = !empty($def['multiline']) ? sanitize_textarea_field($val) : sanitize_text_field($val);
				$opts[$key] = self::encrypt($clean);
			}
		}
		update_option(self::OPTION, $opts, false); // autoload=false: never on the public page load
		// Service-account creds may have changed — drop the cached property list so
		// the dropdown re-pulls against the new credentials on the next view.
		if (class_exists('AQ_Ranking_Audit')) {
			AQ_Ranking_Audit::flush_gsc_sites_cache();
		}
		wp_safe_redirect(add_query_arg(['page' => 'aq-integrations', 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	/* ---------------- connection tests (server-side) ---------------- */

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/integrations/test/(?P<svc>[a-z_]+)', [
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can(self::CAP); },
			'callback'            => [__CLASS__, 'rest_test'],
		]);
	}

	public static function rest_test(WP_REST_Request $req) {
		$svc = (string) $req['svc'];
		if ($svc === 'anthropic') {
			return rest_ensure_response(class_exists('AQ_Claude')
				? AQ_Claude::test()
				: ['ok' => false, 'message' => 'Claude client unavailable.']);
		}
		if ($svc === 'openai') {
			return rest_ensure_response(class_exists('AQ_OpenAI')
				? AQ_OpenAI::test()
				: ['ok' => false, 'message' => 'OpenAI client unavailable.']);
		}
		if ($svc === 'dataforseo') {
			$cred = self::dataforseo();
			if ($cred['login'] === '' || $cred['password'] === '') {
				return rest_ensure_response(['ok' => false, 'message' => 'DataForSEO login and password required.']);
			}
			$resp = wp_remote_get('https://api.dataforseo.com/v3/appendix/user_data', [
				'timeout' => 20,
				'headers' => ['Authorization' => 'Basic ' . base64_encode($cred['login'] . ':' . $cred['password'])],
			]);
			return rest_ensure_response(self::eval_http($resp, 'DataForSEO'));
		}
		if ($svc === 'gsc') {
			if (!class_exists('AQ_Ranking_Audit')) {
				return rest_ensure_response(['ok' => false, 'message' => 'Ranking audit unavailable.']);
			}
			if (!AQ_Ranking_Audit::has_gsc_connection()) {
				return rest_ensure_response(['ok' => false, 'message' => 'Paste your Google OAuth login (client ID, secret, refresh token) and Save first.']);
			}
			// Authorize AND list — a live property count is far more useful than a bare "authorized".
			$list = AQ_Ranking_Audit::gsc_sites(true); // fresh: reflect the just-saved login
			if (empty($list['ok'])) {
				return rest_ensure_response(['ok' => false, 'message' => 'Google rejected the login: ' . (string) ($list['error'] ?? 'unknown error')]);
			}
			$n = count((array) $list['sites']);
			return rest_ensure_response(['ok' => true, 'message' => $n === 0
				? 'Connected, but this Google account can see 0 Search Console properties. Confirm the account has access, then Refresh the list.'
				: 'Connected — ' . $n . ' propert' . ($n === 1 ? 'y' : 'ies') . ' available. Pick this site\'s property from the dropdown, then Save.']);
		}
		if ($svc === 'github') {
			$token = self::get('github_token');
			if ($token === '') {
				return rest_ensure_response(['ok' => false, 'message' => 'No GitHub token saved (only needed for private repos).']);
			}
			$resp = wp_remote_get('https://api.github.com/user', [
				'timeout' => 20,
				'headers' => ['Authorization' => 'Bearer ' . $token, 'User-Agent' => 'aq-core', 'Accept' => 'application/vnd.github+json'],
			]);
			return rest_ensure_response(self::eval_http($resp, 'GitHub'));
		}
		return new WP_Error('aq_bad_svc', 'Unknown service.', ['status' => 404]);
	}

	private static function eval_http($resp, string $name): array {
		if (is_wp_error($resp)) {
			return ['ok' => false, 'message' => $name . ' unreachable: ' . $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code === 200) {
			return ['ok' => true, 'message' => $name . ' connected.'];
		}
		if ($code === 401 || $code === 403) {
			return ['ok' => false, 'message' => $name . ' rejected the credentials (HTTP ' . $code . ').'];
		}
		return ['ok' => false, 'message' => $name . ' returned HTTP ' . $code . '.'];
	}
}
