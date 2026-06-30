<?php
/**
 * AQ Assistant — prompt-driven page editing, powered by ChatGPT (OpenAI).
 *
 * A chat panel inside the visual builder. The editor describes a change in
 * plain English; this module sends the page's current sections + the section
 * field schema to OpenAI's Chat Completions API, which replies with either a
 * text answer or a proposed NEW sections array via the `update_page` function
 * call. The proposal is validated against the known layouts/fields and returned
 * to the builder, which loads it into the working copy for review — it is NEVER
 * auto-saved. The user reviews and clicks Save, going through the one true write
 * path (AQ_Content_Sync), so the same validation + round-trip rules apply.
 *
 * Key handling: the OpenAI key comes from AutoForge → Integrations
 * (AQ_Integrations::openai_key(), or the AQ_OPENAI_KEY wp-config constant). It is
 * used server-side only and never sent to the browser. Capability-gated on
 * manage_options. No third-party SDK: a plain wp_remote_post to the OpenAI API,
 * consistent with the lean, self-contained plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Assistant {

	const CAP      = 'manage_options';
	const OPTION   = 'aq_assistant';
	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
	const MODEL    = 'gpt-4o';

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		add_action('admin_menu', [__CLASS__, 'menu'], 20);
		add_action('admin_post_aq_assistant_save', [__CLASS__, 'save_settings']);
		// Floating assistant on every admin page (the full-screen builder has its
		// own in-canvas drawer, so it is skipped there to avoid two assistants).
		add_action('admin_footer', [__CLASS__, 'render_global']);
	}

	/* ---------------- config ---------------- */

	private static function opts(): array {
		$o = get_option(self::OPTION, []);
		return is_array($o) ? $o : [];
	}

	/** Selectable OpenAI models for the assistant. */
	private static function models(): array {
		return [
			'gpt-4o'      => 'GPT-4o (recommended)',
			'gpt-4o-mini' => 'GPT-4o mini (faster, cheaper)',
		];
	}

	/** The OpenAI API key, sourced from the Integrations store (constant or DB). */
	public static function api_key(): string {
		if (class_exists('AQ_Integrations')) {
			$k = AQ_Integrations::openai_key();
			if ($k !== '') {
				return $k;
			}
		}
		return defined('AQ_OPENAI_KEY') && AQ_OPENAI_KEY ? (string) AQ_OPENAI_KEY : '';
	}

	public static function model(): string {
		$m = (string) (self::opts()['model'] ?? '');
		return $m !== '' ? $m : self::MODEL;
	}

	public static function is_configured(): bool {
		return self::api_key() !== '';
	}

	/* ---------------- settings screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'AI Assistant', 'AI Assistant', self::CAP, 'aq-assistant', [__CLASS__, 'render_settings']);
	}

	public static function render_settings(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$model   = self::model();
		$has_key = self::is_configured();
		$notice  = isset($_GET['updated']) ? 'Settings saved.' : '';
		$int_url = admin_url('admin.php?page=aq-integrations');
		?>
		<div class="wrap">
			<h1>AI Assistant</h1>
			<?php if ($notice) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
			<p>The in-editor chatbot that edits pages from plain-English prompts. Powered by <strong>ChatGPT (OpenAI)</strong>.</p>
			<?php if (!$has_key) : ?>
				<div class="notice notice-warning inline"><p>No OpenAI API key found. Add one under <a href="<?php echo esc_url($int_url); ?>">AutoForge → Integrations</a>, then the assistant turns on.</p></div>
			<?php else : ?>
				<div class="notice notice-info inline"><p>Using your OpenAI key from <a href="<?php echo esc_url($int_url); ?>">Integrations</a>. The key is read server-side only.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="aq_assistant_save">
				<?php wp_nonce_field('aq_assistant_save'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aq-as-model">Model</label></th>
						<td>
							<select name="model" id="aq-as-model">
								<?php foreach (self::models() as $id => $label) : ?>
									<option value="<?php echo esc_attr($id); ?>" <?php selected($model, $id); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Which OpenAI model powers the assistant.</p>
						</td>
					</tr>
				</table>
				<?php submit_button('Save settings'); ?>
			</form>
			<h2 style="margin-top:24px;">Connection</h2>
			<p>
				<button type="button" class="button" id="aq-as-test">Test connection</button>
				<span id="aq-as-test-msg" style="margin-left:8px;font-size:13px;"></span>
			</p>
			<script>
			(function () {
				var url = '<?php echo esc_url_raw(rest_url('aq/v1/assistant/test')); ?>';
				var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
				var btn = document.getElementById('aq-as-test'), msg = document.getElementById('aq-as-test-msg');
				if (!btn) { return; }
				btn.addEventListener('click', function () {
					btn.disabled = true; msg.textContent = 'Testing…'; msg.style.color = '#646970';
					fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							btn.disabled = false;
							var ok = d && d.ok;
							msg.textContent = (ok ? '✓ ' : '✕ ') + ((d && d.message) || (ok ? 'Connected' : 'Failed'));
							msg.style.color = ok ? '#1a8f4f' : '#a30d25';
						})
						.catch(function (e) { btn.disabled = false; msg.textContent = '✕ ' + e.message; msg.style.color = '#a30d25'; });
				});
			})();
			</script>
		</div>
		<?php
	}

	/* ---------------- global floating assistant (every admin page) ---------------- */

	public static function render_global(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		// The full-screen page builder (aq-pages&page_id=N) has its own in-canvas
		// assistant drawer — don't stack a second one on top of it.
		if (isset($_GET['page'], $_GET['page_id']) && $_GET['page'] === 'aq-pages') {
			return;
		}
		$configured = self::is_configured();
		$rest  = esc_url_raw(rest_url('aq/v1/assistant/global'));
		$nonce = wp_create_nonce('wp_rest');
		$int   = esc_url(admin_url('admin.php?page=aq-integrations'));
		?>
		<style>
			#aq-ga { position:fixed; right:22px; bottom:22px; z-index:99998; font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
			#aq-ga-btn { width:54px; height:54px; border-radius:50%; border:0; cursor:pointer; background:#c8102e; color:#fff;
				box-shadow:0 6px 20px rgba(13,16,20,.28); display:flex; align-items:center; justify-content:center; transition:transform .15s ease,background .15s ease; }
			#aq-ga-btn:hover { background:#a30d25; transform:translateY(-1px); }
			#aq-ga-btn svg { width:25px; height:25px; }
			#aq-ga-panel { position:absolute; right:0; bottom:66px; width:370px; max-width:calc(100vw - 44px); height:520px; max-height:calc(100vh - 130px);
				background:#fff; border:1px solid #e6e8eb; border-radius:16px; box-shadow:0 18px 50px rgba(13,16,20,.30); display:flex; flex-direction:column; overflow:hidden; }
			#aq-ga-panel[hidden] { display:none; }
			.aq-ga-head { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:13px 16px; background:linear-gradient(120deg,#0d1014,#15191f); color:#fff; }
			.aq-ga-head strong { font-family:Poppins,Inter,system-ui,sans-serif; font-size:14px; font-weight:600; }
			.aq-ga-head .aq-ga-sub { font-size:11px; color:#c9cfd6; }
			.aq-ga-x { border:0; background:transparent; color:#c9cfd6; font-size:20px; line-height:1; cursor:pointer; padding:2px 4px; }
			.aq-ga-x:hover { color:#fff; }
			.aq-ga-log { flex:1 1 auto; overflow-y:auto; padding:14px; display:flex; flex-direction:column; gap:10px; background:#f7f9fa; }
			.aq-ga-msg { font-size:13px; line-height:1.5; padding:9px 12px; border-radius:12px; max-width:90%; white-space:pre-wrap; }
			.aq-ga-msg--bot { background:#fff; border:1px solid #e6e8eb; color:#0d1014; align-self:flex-start; }
			.aq-ga-msg--me { background:#0d1014; color:#fff; align-self:flex-end; }
			.aq-ga-open { align-self:flex-start; display:inline-block; margin-top:-2px; text-decoration:none; background:#c8102e; color:#fff; font-size:12px; font-weight:700; padding:7px 13px; border-radius:8px; }
			.aq-ga-open:hover { background:#a30d25; color:#fff; }
			.aq-ga-note { font-size:12px; color:#a30d25; background:#fbe7e7; border-top:1px solid #e6c4c4; padding:9px 14px; }
			.aq-ga-note a { color:#a30d25; font-weight:600; }
			.aq-ga-form { display:flex; gap:8px; padding:11px; border-top:1px solid #e6e8eb; background:#fff; }
			.aq-ga-input { flex:1; resize:none; height:42px; max-height:120px; padding:10px 12px; border:1px solid #c9cfd6; border-radius:10px; font-size:13px; color:#0d1014; font-family:inherit; }
			.aq-ga-input:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 3px rgba(200,16,46,.18); }
			.aq-ga-send { border:0; background:#c8102e; color:#fff; font-weight:700; font-size:13px; padding:0 15px; border-radius:10px; cursor:pointer; }
			.aq-ga-send:disabled { background:#8a94a1; cursor:default; }
		</style>
		<div id="aq-ga">
			<div id="aq-ga-panel" hidden role="dialog" aria-label="AI assistant">
				<div class="aq-ga-head">
					<span><strong>AI Assistant</strong><br><span class="aq-ga-sub">Ask about the site or what to change</span></span>
					<button type="button" class="aq-ga-x" id="aq-ga-close" aria-label="Close">&times;</button>
				</div>
				<div class="aq-ga-log" id="aq-ga-log">
					<div class="aq-ga-msg aq-ga-msg--bot">Hi! Ask me anything about the site, or tell me what you'd like to change and I'll take you straight to the right page to edit it.</div>
				</div>
				<?php if (!$configured) : ?>
				<div class="aq-ga-note">Add your OpenAI key under <a href="<?php echo $int; ?>">Integrations</a> to turn this on.</div>
				<?php endif; ?>
				<form class="aq-ga-form" id="aq-ga-form">
					<textarea class="aq-ga-input" id="aq-ga-input" placeholder="<?php echo $configured ? 'Ask me anything…' : 'Add an OpenAI key to enable'; ?>" <?php disabled(!$configured); ?>></textarea>
					<button type="submit" class="aq-ga-send" id="aq-ga-send" <?php disabled(!$configured); ?>>Send</button>
				</form>
			</div>
			<button type="button" id="aq-ga-btn" aria-label="Open AI assistant" aria-expanded="false">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
			</button>
		</div>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode($rest); ?>, NONCE = <?php echo wp_json_encode($nonce); ?>;
			var root = document.getElementById('aq-ga');
			if (!root) { return; }
			var btn = document.getElementById('aq-ga-btn'),
				panel = document.getElementById('aq-ga-panel'),
				closeB = document.getElementById('aq-ga-close'),
				log = document.getElementById('aq-ga-log'),
				form = document.getElementById('aq-ga-form'),
				input = document.getElementById('aq-ga-input'),
				send = document.getElementById('aq-ga-send');

			function toggle(open) {
				var show = (open === undefined) ? panel.hasAttribute('hidden') : open;
				if (show) { panel.removeAttribute('hidden'); btn.setAttribute('aria-expanded', 'true'); if (input && !input.disabled) { input.focus(); } }
				else { panel.setAttribute('hidden', ''); btn.setAttribute('aria-expanded', 'false'); }
			}
			btn.addEventListener('click', function () { toggle(); });
			if (closeB) { closeB.addEventListener('click', function () { toggle(false); }); }

			function bubble(text, who) {
				var d = document.createElement('div');
				d.className = 'aq-ga-msg aq-ga-msg--' + (who === 'me' ? 'me' : 'bot');
				d.textContent = text;
				log.appendChild(d);
				log.scrollTop = log.scrollHeight;
				return d;
			}
			function openLink(o) {
				var a = document.createElement('a');
				a.className = 'aq-ga-open';
				a.href = o.url;
				a.textContent = 'Open “' + o.title + '” in editor →';
				log.appendChild(a);
				log.scrollTop = log.scrollHeight;
			}

			if (form) {
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (!input || input.disabled) { return; }
					var msg = input.value.trim();
					if (!msg) { return; }
					bubble(msg, 'me');
					input.value = '';
					send.disabled = true; input.disabled = true;
					var thinking = bubble('…', 'bot');
					fetch(REST, {
						method: 'POST', credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
						body: JSON.stringify({ message: msg })
					}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						thinking.parentNode.removeChild(thinking);
						if (res.ok && res.body && res.body.ok) {
							bubble(res.body.reply || 'Done.', 'bot');
							if (res.body.open && res.body.open.url) { openLink(res.body.open); }
						} else {
							bubble('Sorry — ' + ((res.body && (res.body.message || res.body.code)) || 'something went wrong.'), 'bot');
						}
					}).catch(function (err) {
						thinking.parentNode.removeChild(thinking);
						bubble('Sorry — ' + err.message, 'bot');
					}).then(function () {
						send.disabled = false; input.disabled = false; input.focus();
					});
				});
				if (input) {
					input.addEventListener('keydown', function (e) {
						if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.dispatchEvent(new Event('submit', { cancelable: true })); }
					});
				}
			}
		})();
		</script>
		<?php
	}

	public static function save_settings(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_assistant_save')) {
			wp_die('Not allowed.');
		}
		$opts        = self::opts();
		$model_in    = isset($_POST['model']) ? sanitize_text_field((string) wp_unslash($_POST['model'])) : '';
		$opts['model'] = array_key_exists($model_in, self::models()) ? $model_in : self::MODEL;
		unset($opts['api_key']); // legacy: the key now lives in Integrations (OpenAI)
		update_option(self::OPTION, $opts, false);
		wp_safe_redirect(add_query_arg(['page' => 'aq-assistant', 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	/* ---------------- REST ---------------- */

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/editor/assistant', [
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can(self::CAP); },
			'callback'            => [__CLASS__, 'rest_chat'],
		]);
		register_rest_route('aq/v1', '/assistant/test', [
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can(self::CAP); },
			'callback'            => [__CLASS__, 'rest_test'],
		]);
		// Global assistant (the floating chat on every admin page). Answers
		// site questions and routes the user into a page's editor to make changes.
		register_rest_route('aq/v1', '/assistant/global', [
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can(self::CAP); },
			'callback'            => [__CLASS__, 'rest_global'],
		]);
	}

	public static function rest_global(WP_REST_Request $req) {
		if (!self::is_configured()) {
			return new WP_Error('aq_no_key', 'No OpenAI API key configured. Add one under AutoForge → Integrations.', ['status' => 400]);
		}
		$body    = $req->get_json_params();
		$message = trim((string) ($body['message'] ?? ''));
		if ($message === '') {
			return new WP_Error('aq_empty', 'Message is empty.', ['status' => 400]);
		}
		if (mb_strlen($message) > 4000) {
			return new WP_Error('aq_too_long', 'Message is too long.', ['status' => 400]);
		}
		$result = self::call_openai_global($message);
		if (is_wp_error($result)) {
			return $result;
		}
		return rest_ensure_response($result);
	}

	/**
	 * Resolve a page from a free-text query (title or URL path) to its editor.
	 * Returns ['page_id','title','url'] or null.
	 */
	private static function resolve_page(string $query): ?array {
		$query = trim($query);
		if ($query === '') {
			return null;
		}
		$pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);
		$q     = strtolower($query);
		$qpath = '/' . trim((string) (parse_url($query, PHP_URL_PATH) ?: $query), '/') . '/';
		$best  = null;
		foreach ($pages as $pg) {
			$title = strtolower(get_the_title($pg));
			$path  = parse_url((string) get_permalink($pg), PHP_URL_PATH) ?: '/';
			if ($path === $qpath || $title === $q) {
				$best = $pg;
				break; // exact match wins
			}
			if ($best === null && ($title !== '' && strpos($title, $q) !== false)) {
				$best = $pg; // first fuzzy title match
			}
		}
		if (!$best) {
			return null;
		}
		return [
			'page_id' => $best->ID,
			'title'   => get_the_title($best),
			'url'     => admin_url('admin.php?page=aq-pages&page_id=' . $best->ID),
		];
	}

	public static function rest_chat(WP_REST_Request $req) {
		if (!self::is_configured()) {
			return new WP_Error('aq_no_key', 'No OpenAI API key configured. Add one under AutoForge → Integrations.', ['status' => 400]);
		}
		$body     = $req->get_json_params();
		$id       = (int) ($body['id'] ?? 0);
		$message  = trim((string) ($body['message'] ?? ''));
		$sections = is_array($body['sections'] ?? null) ? $body['sections'] : [];

		if ($message === '') {
			return new WP_Error('aq_empty', 'Message is empty.', ['status' => 400]);
		}
		if (mb_strlen($message) > 4000) {
			return new WP_Error('aq_too_long', 'Message is too long.', ['status' => 400]);
		}
		if (!current_user_can('edit_post', $id)) {
			return new WP_Error('aq_forbidden', 'You cannot edit this page.', ['status' => 403]);
		}

		$result = self::call_openai($message, $sections);
		if (is_wp_error($result)) {
			return $result;
		}
		return rest_ensure_response($result);
	}

	/** Validate the saved OpenAI key against the API (GET /v1/models — no token cost). */
	public static function rest_test(WP_REST_Request $req) {
		$key = self::api_key();
		if ($key === '') {
			return rest_ensure_response(['ok' => false, 'message' => 'No OpenAI key saved (set it under Integrations).']);
		}
		$resp = wp_remote_get('https://api.openai.com/v1/models', [
			'timeout' => 20,
			'headers' => ['Authorization' => 'Bearer ' . $key],
		]);
		if (is_wp_error($resp)) {
			return rest_ensure_response(['ok' => false, 'message' => 'OpenAI unreachable: ' . $resp->get_error_message()]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code === 200) {
			return rest_ensure_response(['ok' => true, 'message' => 'Connected (' . self::model() . ').']);
		}
		if ($code === 401 || $code === 403) {
			return rest_ensure_response(['ok' => false, 'message' => 'OpenAI rejected the key (HTTP ' . $code . ').']);
		}
		return rest_ensure_response(['ok' => false, 'message' => 'OpenAI returned HTTP ' . $code . '.']);
	}

	/* ---------------- OpenAI call ---------------- */

	private static function call_openai(string $message, array $sections) {
		$tool = [
			'type'     => 'function',
			'function' => [
				'name'        => 'update_page',
				'description' => 'Apply the requested change by returning the COMPLETE new ordered list of page sections. Use this whenever the user asks to add, edit, remove, reorder, or restyle content. Preserve every section and field the user did not ask to change. Only use section "type" values and field names from the provided schema.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'summary'  => ['type' => 'string', 'description' => 'One-sentence plain-English summary of what changed.'],
						'sections' => ['type' => 'array', 'description' => 'The full new sections array.', 'items' => ['type' => 'object']],
					],
					'required'   => ['summary', 'sections'],
				],
			],
		];

		$payload = [
			'model'       => self::model(),
			'max_tokens'  => 12000,
			'messages'    => [
				['role' => 'system', 'content' => self::system_prompt()],
				['role' => 'user',   'content' => self::user_prompt($message, $sections)],
			],
			'tools'       => [$tool],
			'tool_choice' => 'auto',
		];

		$resp = wp_remote_post(self::ENDPOINT, [
			'timeout' => 120,
			'headers' => [
				'content-type'  => 'application/json',
				'Authorization' => 'Bearer ' . self::api_key(),
			],
			'body'    => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);

		if (is_wp_error($resp)) {
			return new WP_Error('aq_http', 'Could not reach the AI service: ' . $resp->get_error_message(), ['status' => 502]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);

		if ($code !== 200 || !is_array($data)) {
			$msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
			return new WP_Error('aq_api', 'AI service error: ' . $msg, ['status' => 502]);
		}

		$choice   = (isset($data['choices'][0]['message']) && is_array($data['choices'][0]['message'])) ? $data['choices'][0]['message'] : [];
		$reply    = (isset($choice['content']) && is_string($choice['content'])) ? $choice['content'] : '';
		$proposal = null;
		$summary  = '';
		if (!empty($choice['tool_calls']) && is_array($choice['tool_calls'])) {
			foreach ($choice['tool_calls'] as $tc) {
				if (($tc['function']['name'] ?? '') === 'update_page') {
					$args = json_decode((string) ($tc['function']['arguments'] ?? ''), true);
					if (is_array($args)) {
						$summary  = (string) ($args['summary'] ?? '');
						$proposal = self::sanitize_sections($args['sections'] ?? null);
					}
					break;
				}
			}
		}

		$out = ['ok' => true, 'reply' => $reply !== '' ? $reply : ($summary ?: 'Done.')];
		if (is_array($proposal)) {
			$out['proposal'] = $proposal;
			$out['summary']  = $summary;
		}
		return $out;
	}

	/** Global assistant: text answers + an open_page_editor routing tool. */
	private static function call_openai_global(string $message) {
		$tool = [
			'type'     => 'function',
			'function' => [
				'name'        => 'open_page_editor',
				'description' => 'Call this when the user wants to create, edit, change, restyle, or remove content on a SPECIFIC page. Pass the page name or URL path so the user can open it in the visual editor (where changes are reviewed before saving).',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'page' => ['type' => 'string', 'description' => 'Page title or URL path to edit, e.g. "About" or "/about/".'],
						'note' => ['type' => 'string', 'description' => 'One short sentence telling the user what to do once the editor opens.'],
					],
					'required'   => ['page'],
				],
			],
		];

		$payload = [
			'model'       => self::model(),
			'max_tokens'  => 1200,
			'messages'    => [
				['role' => 'system', 'content' => self::global_system_prompt()],
				['role' => 'user',   'content' => $message],
			],
			'tools'       => [$tool],
			'tool_choice' => 'auto',
		];

		$resp = wp_remote_post(self::ENDPOINT, [
			'timeout' => 60,
			'headers' => [
				'content-type'  => 'application/json',
				'Authorization' => 'Bearer ' . self::api_key(),
			],
			'body'    => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);

		if (is_wp_error($resp)) {
			return new WP_Error('aq_http', 'Could not reach the AI service: ' . $resp->get_error_message(), ['status' => 502]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200 || !is_array($data)) {
			$msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
			return new WP_Error('aq_api', 'AI service error: ' . $msg, ['status' => 502]);
		}

		$choice = (isset($data['choices'][0]['message']) && is_array($data['choices'][0]['message'])) ? $data['choices'][0]['message'] : [];
		$reply  = (isset($choice['content']) && is_string($choice['content'])) ? trim($choice['content']) : '';
		$open   = null;
		if (!empty($choice['tool_calls']) && is_array($choice['tool_calls'])) {
			foreach ($choice['tool_calls'] as $tc) {
				if (($tc['function']['name'] ?? '') === 'open_page_editor') {
					$args = json_decode((string) ($tc['function']['arguments'] ?? ''), true);
					if (is_array($args)) {
						$open = self::resolve_page((string) ($args['page'] ?? ''));
						$note = trim((string) ($args['note'] ?? ''));
						if ($open) {
							$reply = $reply !== '' ? $reply : ($note !== '' ? $note : ('Open the ' . $open['title'] . ' page to make that change — you can review it before saving.'));
						} elseif ($reply === '') {
							$reply = 'I couldn\'t find a page matching "' . (string) ($args['page'] ?? '') . '". Try the exact page name or URL.';
						}
					}
					break;
				}
			}
		}

		$out = ['ok' => true, 'reply' => $reply !== '' ? $reply : 'Done.'];
		if ($open) {
			$out['open'] = $open;
		}
		return $out;
	}

	private static function global_system_prompt(): string {
		$site = function_exists('aq_site') ? (string) (aq_site('name') ?: 'this site') : 'this site';
		$base = implode("\n", [
			"You are the AI assistant for managing the {$site} website. You are available on every admin screen as a floating helper.",
			'You can: answer questions about the business and the site, explain how to do things in this dashboard, and help the user edit pages.',
			'To CHANGE content on a specific page, call open_page_editor with the page name or path. The user makes and reviews the actual edit in the visual builder there — you do NOT apply changes directly from this chat.',
			'Keep answers short, plain, and friendly. If something is not possible or you are unsure, say so. Never invent facts, prices, or certifications.',
		]);
		$k = self::knowledge();
		return $k !== '' ? $base . "\n\n" . $k : $base;
	}

	/** Keep only known layouts; ensure each carries a 'type' and a version. */
	private static function sanitize_sections($sections): ?array {
		if (!is_array($sections)) {
			return null;
		}
		$allowed = class_exists('AQ_Editor') ? array_keys(AQ_Editor::field_schema()) : [];
		$clean   = [];
		foreach ($sections as $s) {
			if (!is_array($s)) {
				continue;
			}
			$type = (string) ($s['type'] ?? '');
			if ($type === '' || ($allowed && !in_array($type, $allowed, true))) {
				continue;
			}
			if (!isset($s['v'])) {
				$s['v'] = 1;
			}
			// Drop any transient/client keys.
			foreach (array_keys($s) as $k) {
				if (is_string($k) && isset($k[0]) && $k[0] === '_') {
					unset($s[$k]);
				}
			}
			$clean[] = $s;
		}
		return $clean;
	}

	private static function system_prompt(): string {
		$site = function_exists('aq_site') ? (string) (aq_site('name') ?: 'this business') : 'this business';
		$base = implode("\n", [
			"You are the editing assistant inside a structured WordPress page builder for {$site}.",
			'A page is an ordered list of "sections". Each section has a "type" and a fixed set of fields. You edit ONLY through the structured fields — never raw HTML, never CSS, never new section types or field names.',
			'',
			'Rules:',
			'- When the user asks to change anything, call the update_page function with the COMPLETE new sections array (not a diff). Preserve every section and field they did not ask to change, in order.',
			'- Only use section "type" values and field names that appear in the SCHEMA. Do not invent fields. Repeater fields are arrays of row objects using the listed sub-fields.',
			'- For image fields, keep the existing filename unless the user names a different uploaded image. Never fabricate filenames.',
			'- Keep copy faithful to the business: accurate, professional, no invented certifications, prices, or claims.',
			'- If the request is a question or cannot be done with the available fields, reply in plain text WITHOUT calling the function, and say what is and is not possible.',
			'- Keep any summary to one short sentence.',
		]);
		$k = self::knowledge();
		return $k !== '' ? $base . "\n\n" . $k : $base;
	}

	/** Client + whole-site context so the assistant understands who/what it edits for. */
	private static function knowledge(): string {
		if (!function_exists('aq_site')) {
			return '';
		}
		$lines = ['CLIENT & SITE KNOWLEDGE (ground truth — do not contradict or invent beyond this):'];
		$name = (string) (aq_site('name') ?: '');
		if ($name !== '') {
			$tag = (string) (aq_site('tagline') ?: '');
			$lines[] = '- Business: ' . $name . ($tag !== '' ? ' — ' . $tag : '');
		}
		$phone = (string) (aq_site('phone') ?: '');
		if ($phone !== '') {
			$lines[] = '- Phone: ' . $phone;
		}
		$email = (string) (aq_site('email') ?: '');
		if ($email !== '') {
			$lines[] = '- Email: ' . $email;
		}
		$addr = aq_site('address');
		if (is_array($addr)) {
			$lines[] = '- Address: ' . trim(($addr['street'] ?? '') . ', ' . ($addr['locality'] ?? '') . ', ' . ($addr['region'] ?? '') . ' ' . ($addr['postalCode'] ?? ''), ', ');
		}
		$lic = (string) (aq_site('license.number') ?: '');
		if ($lic !== '') {
			$lines[] = '- License: MA #' . $lic;
		}
		$founded = (int) (aq_site('founded') ?: 0);
		if ($founded) {
			$lines[] = '- In business since ' . $founded . ' (~' . max(0, (int) gmdate('Y') - $founded) . ' years).';
		}
		$counties = aq_site('counties');
		if (is_array($counties) && $counties) {
			$lines[] = '- Counties served: ' . implode(', ', array_slice($counties, 0, 8));
		}
		$regions = aq_site('regions');
		if (is_array($regions) && $regions) {
			$lines[] = '- Regions: ' . implode(', ', array_slice($regions, 0, 6));
		}
		$pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
		$plist = [];
		foreach ($pages as $pg) {
			$path = parse_url((string) get_permalink($pg), PHP_URL_PATH) ?: '/';
			$plist[] = get_the_title($pg) . ' (' . $path . ')';
		}
		if ($plist) {
			$lines[] = '- All pages on this site: ' . implode('; ', $plist);
		}
		$lines[] = '- The site uses a fixed navy + gold brand enforced by the templates; you edit content and structure through fields, not visual styling.';
		return implode("\n", $lines);
	}

	private static function user_prompt(string $message, array $sections): string {
		$schema   = self::schema_summary();
		$schemaJson   = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		$sectionsJson = wp_json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		return "SCHEMA (allowed section types and their fields):\n{$schemaJson}\n\n"
			. "CURRENT PAGE SECTIONS (JSON):\n{$sectionsJson}\n\n"
			. "USER REQUEST:\n{$message}";
	}

	/** Compact {type: {fields:[...], repeaters:{name:[subfields]}}} from the editor schema. */
	private static function schema_summary(): array {
		if (!class_exists('AQ_Editor')) {
			return [];
		}
		$out = [];
		foreach (AQ_Editor::field_schema() as $type => $def) {
			$fields    = [];
			$repeaters = [];
			foreach ((array) ($def['fields'] ?? []) as $f) {
				$name = (string) ($f['name'] ?? '');
				if ($name === '') {
					continue;
				}
				if (($f['type'] ?? '') === 'repeater') {
					$subs = [];
					foreach ((array) ($f['subfields'] ?? []) as $sf) {
						if (!empty($sf['name'])) {
							$subs[] = $sf['name'];
						}
					}
					$repeaters[$name] = $subs;
				} else {
					$fields[] = $name;
				}
			}
			$entry = ['fields' => $fields];
			if ($repeaters) {
				$entry['repeaters'] = $repeaters;
			}
			$out[$type] = $entry;
		}
		return $out;
	}
}
