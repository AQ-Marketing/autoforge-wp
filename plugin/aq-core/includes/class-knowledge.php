<?php
/**
 * AQ Knowledge — the per-site SEO "knowledge pack" the live-site assistant reasons
 * from, and the agency-only screen that maintains it (AutoForge → SEO → Knowledge).
 *
 * Two parts:
 *  - The brief (option `aq_knowledge` → { brief:markdown, source, updated_at, updated_by }):
 *    voice/tone, grounded facts, strategy, and cross-page rules. Written by the
 *    build from the /seo-audit report; editable here by the agency.
 *  - Per-page plan records: the extended `seo-intents.json` entry stored in each
 *    page's `_aq_content_intent` meta (primary_intent + secondary_keywords,
 *    entities, internal_links, target_words, intent_type, content_angle, …). The
 *    importer persists these; this screen lets the agency edit them.
 *
 * Only agency admins (manage_options AND an @{AQ_AGENCY_EMAIL_DOMAIN} email) can
 * edit — this is the deliberate "change the plan first" escape hatch: a client (or
 * an agency admin) cannot break the plan from the assistant, but the agency can
 * update the plan here, after which the same edit passes the guardian. Everyone
 * else sees it read-only.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Knowledge {

	const CAP    = 'manage_options';
	const SLUG   = 'aq-knowledge';
	const OPTION = 'aq_knowledge';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 22);
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Knowledge', 'Knowledge', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/* ---------------- brief store ---------------- */

	public static function all(): array {
		$o = get_option(self::OPTION, []);
		return is_array($o) ? $o : [];
	}

	public static function brief(): string {
		return (string) (self::all()['brief'] ?? '');
	}

	public static function has_brief(): bool {
		return trim(self::brief()) !== '';
	}

	public static function set_brief(string $markdown, string $source = 'screen'): void {
		$user = wp_get_current_user();
		update_option(self::OPTION, [
			'brief'      => $markdown,
			'source'     => $source,
			'updated_at' => time(),
			'updated_by' => $user && $user->exists() ? (string) $user->user_login : $source,
		], false);
	}

	/* ---------------- access ---------------- */

	/** Agency admins only: manage_options AND an email on the agency domain. */
	public static function can_edit(?int $user_id = null): bool {
		$user  = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
		$edit  = false;
		if ($user && $user->exists() && user_can($user, self::CAP)) {
			$domain = defined('AQ_AGENCY_EMAIL_DOMAIN') ? strtolower((string) AQ_AGENCY_EMAIL_DOMAIN) : 'aqmarketing.com';
			$email  = strtolower((string) $user->user_email);
			$suffix = '@' . $domain;
			$edit   = $domain !== '' && substr($email, -strlen($suffix)) === $suffix;
		}
		return (bool) apply_filters('aq_knowledge_can_edit', $edit, $user);
	}

	/* ---------------- per-page plan records ---------------- */

	/** The stored plan record (extended intent) for a page. */
	public static function page_plan(int $id): array {
		$r = json_decode((string) get_post_meta($id, '_aq_content_intent', true), true);
		return is_array($r) ? $r : [];
	}

	/** True when the record has the full 6 required keys + >=2 differentiators. */
	public static function is_full_plan(array $rec): bool {
		foreach (['primary_intent', 'role', 'service', 'market', 'funnel', 'canonical_path'] as $k) {
			if (trim((string) ($rec[$k] ?? '')) === '') { return false; }
		}
		$diff = array_filter(array_map('trim', (array) ($rec['differentiators'] ?? [])));
		return count($diff) >= 2;
	}

	/** Validate a plan record for a page path before saving. @return string '' = ok, else an error. */
	public static function validate_plan(array $rec, string $path): string {
		foreach (['primary_intent', 'role', 'service', 'market', 'funnel'] as $k) {
			if (trim((string) ($rec[$k] ?? '')) === '') { return 'The "' . $k . '" field is required.'; }
		}
		$canon = '/' . trim((string) ($rec['canonical_path'] ?? $path), '/') . '/';
		$want  = '/' . trim($path, '/') . '/';
		if ($canon === '//') { $canon = '/'; }
		if ($want === '//') { $want = '/'; }
		if ($canon !== $want) { return 'The canonical path must match this page (' . $want . ').'; }
		$diff = array_filter(array_map('trim', (array) ($rec['differentiators'] ?? [])));
		if (count($diff) < 2) { return 'Give at least two differentiators.'; }
		return '';
	}

	/* ---------------- REST ---------------- */

	public static function rest_routes(): void {
		$can = function () { return self::can_edit(); };
		register_rest_route('aq/v1', '/knowledge/brief', [
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_brief'],
		]);
		register_rest_route('aq/v1', '/knowledge/page/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_page'],
		]);
	}

	public static function rest_brief(WP_REST_Request $req) {
		$md = (string) ($req->get_json_params()['markdown'] ?? '');
		self::set_brief($md, 'screen');
		return rest_ensure_response(['ok' => true]);
	}

	public static function rest_page(WP_REST_Request $req) {
		$id   = (int) $req['id'];
		$post = $id ? get_post($id) : null;
		if (!$post || $post->post_type !== 'page') {
			return new WP_Error('aq_not_found', 'Page not found.', ['status' => 404]);
		}
		$rec  = (array) ($req->get_json_params()['record'] ?? []);
		$path = (string) (wp_parse_url(get_permalink($id), PHP_URL_PATH) ?: '/');
		$err  = self::validate_plan($rec, $path);
		if ($err !== '') {
			return rest_ensure_response(['ok' => false, 'message' => $err]);
		}
		update_post_meta($id, '_aq_content_intent', wp_json_encode($rec));
		return rest_ensure_response(['ok' => true]);
	}

	/* ---------------- markdown (tiny, safe) ---------------- */

	/** Minimal markdown → HTML for the brief: #/##/### headings, - lists, **bold**, [x](y). */
	public static function md_to_html(string $md): string {
		$md    = str_replace(["\r\n", "\r"], "\n", $md);
		$lines = explode("\n", $md);
		$html  = '';
		$inList = false;
		$esc = function ($s) { return esc_html($s); };
		$inline = function ($s) use ($esc) {
			$s = $esc($s);
			$s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
			$s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
				return '<a href="' . esc_url($m[2]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
			}, $s);
			return $s;
		};
		foreach ($lines as $line) {
			if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
				if ($inList) { $html .= '</ul>'; $inList = false; }
				$lvl = strlen($m[1]) + 1; // ## → h3
				$html .= '<h' . $lvl . '>' . $inline($m[2]) . '</h' . $lvl . '>';
			} elseif (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
				if (!$inList) { $html .= '<ul>'; $inList = true; }
				$html .= '<li>' . $inline($m[1]) . '</li>';
			} elseif (trim($line) === '') {
				if ($inList) { $html .= '</ul>'; $inList = false; }
			} else {
				if ($inList) { $html .= '</ul>'; $inList = false; }
				$html .= '<p>' . $inline($line) . '</p>';
			}
		}
		if ($inList) { $html .= '</ul>'; }
		return wp_kses_post($html);
	}

	/* ---------------- screen ---------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) { return; }
		$editable = self::can_edit();
		$meta     = self::all();
		$brief    = self::brief();
		$pages    = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC']);
		$full = 0;
		foreach ($pages as $p) { if (self::is_full_plan(self::page_plan($p->ID))) { $full++; } }

		AQ_Admin_Hub::open('Knowledge', 'The SEO plan and client knowledge the on-site assistant protects. Your AQ Marketing team maintains this.', self::SLUG);
		?>
		<style>
			.aq-kn-note{background:#fff;border:1px solid #e6e8eb;border-radius:12px;padding:14px 18px;margin:0 0 18px;font-size:13px;color:#3a4048}
			.aq-kn-cols{display:grid;grid-template-columns:1fr;gap:20px}
			.aq-kn-panel{background:#fff;border:1px solid #e6e8eb;border-radius:12px;padding:18px 20px}
			.aq-kn-panel h2{margin:0 0 10px;font-size:16px}
			.aq-kn-brief{font-size:13px;line-height:1.7;color:#2c3238}
			.aq-kn-brief h2,.aq-kn-brief h3,.aq-kn-brief h4{margin:16px 0 6px;font-size:14px;color:#0d1014}
			.aq-kn-brief ul{margin:6px 0;padding-left:20px}
			textarea.aq-kn-md{width:100%;min-height:280px;font:13px/1.6 ui-monospace,Consolas,monospace;border:1px solid #c9cfd6;border-radius:8px;padding:12px}
			table.aq-kn-tbl{width:100%;border-collapse:collapse;font-size:13px}
			table.aq-kn-tbl th,table.aq-kn-tbl td{text-align:left;padding:7px 10px;border-bottom:1px solid #eef1f5;vertical-align:top}
			table.aq-kn-tbl th{background:#f6f7f9;font-weight:600}
			.aq-kn-badge{display:inline-block;font-size:11px;padding:1px 7px;border-radius:10px}
			.aq-kn-badge--full{background:#eaf0ea;color:#1a6f3f}
			.aq-kn-badge--basic{background:#fdf1dd;color:#7a4e0a}
			.aq-kn-save{margin-top:10px}
		</style>

		<?php if (!$editable) : ?>
			<div class="aq-kn-note">You can view the SEO plan here. <strong>Your AQ Marketing team maintains it</strong> — if you need a change that the on-site assistant won't allow, ask them to update the plan for that page.</div>
		<?php endif; ?>

		<div class="aq-kn-cols">
			<div class="aq-kn-panel">
				<h2>Client brief</h2>
				<?php if ($brief === '' && !$editable) : ?>
					<p style="color:#5b6471;font-size:13px">No brief has been added for this site yet.</p>
				<?php endif; ?>
				<div class="aq-kn-brief" id="aq-kn-brief-rendered"><?php echo self::md_to_html($brief); // sanitized in md_to_html ?></div>
				<?php if ($editable) : ?>
					<details style="margin-top:12px"<?php echo $brief === '' ? ' open' : ''; ?>>
						<summary style="cursor:pointer;font-weight:600;font-size:13px">Edit brief (Markdown)</summary>
						<textarea class="aq-kn-md" id="aq-kn-md" placeholder="## Voice and tone&#10;...&#10;## Grounded facts&#10;...&#10;## Strategy&#10;...&#10;## Page rules&#10;..."><?php echo esc_textarea($brief); ?></textarea>
						<p class="aq-kn-save"><button type="button" class="aq-btn" id="aq-kn-save">Save brief</button> <span id="aq-kn-status" style="font-size:12px;color:#5b6471;margin-left:8px"></span></p>
					</details>
				<?php endif; ?>
				<?php if (!empty($meta['updated_at'])) : ?>
					<p style="font-size:12px;color:#8a9099;margin:10px 0 0">Updated <?php echo esc_html(human_time_diff((int) $meta['updated_at']) . ' ago'); ?><?php echo !empty($meta['updated_by']) ? ' by ' . esc_html($meta['updated_by']) : ''; ?>.</p>
				<?php endif; ?>
			</div>

			<div class="aq-kn-panel">
				<h2>Per-page plan <span style="font-weight:400;color:#5b6471;font-size:13px">(<?php echo (int) $full; ?> of <?php echo count($pages); ?> pages have a full plan row)</span></h2>
				<table class="aq-kn-tbl">
					<thead><tr><th>Page</th><th>Primary term</th><th>Role / market</th><th>Secondary · entities · links</th><th></th></tr></thead>
					<tbody>
					<?php foreach ($pages as $p) :
						$rec  = self::page_plan($p->ID);
						$full1 = self::is_full_plan($rec);
						$path = (string) (wp_parse_url(get_permalink($p->ID), PHP_URL_PATH) ?: '/'); ?>
						<tr>
							<td><strong><?php echo esc_html($p->post_title); ?></strong><br><span style="color:#8a9099"><?php echo esc_html($path); ?></span></td>
							<td><?php echo esc_html((string) ($rec['primary_intent'] ?? '—')); ?></td>
							<td><?php echo esc_html(trim((string) ($rec['role'] ?? '') . ' · ' . (string) ($rec['market'] ?? ''), ' ·')); ?></td>
							<td><?php echo (int) count((array) ($rec['secondary_keywords'] ?? [])); ?> · <?php echo (int) count((array) ($rec['entities'] ?? [])); ?> · <?php echo (int) count((array) ($rec['internal_links'] ?? [])); ?></td>
							<td><?php echo $full1 ? '<span class="aq-kn-badge aq-kn-badge--full">full</span>' : '<span class="aq-kn-badge aq-kn-badge--basic">basic</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="font-size:12px;color:#8a9099;margin:12px 0 0">Page plan rows come from the site's <code>seo-intents.json</code> at build time. To change one, edit that file and re-import, or ask your AQ Marketing team.</p>
			</div>
		</div>

		<?php if ($editable) : ?>
		<script>
		(function () {
			var btn = document.getElementById('aq-kn-save'); if (!btn) { return; }
			var url = '<?php echo esc_url_raw(rest_url('aq/v1/knowledge/brief')); ?>';
			var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
			btn.addEventListener('click', function () {
				var md = document.getElementById('aq-kn-md').value;
				var st = document.getElementById('aq-kn-status');
				btn.disabled = true; st.textContent = 'Saving…'; st.style.color = '#5b6471';
				fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ markdown: md }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						btn.disabled = false;
						if (d && d.ok) { st.textContent = '✓ Saved. Reload to see it rendered.'; st.style.color = '#1a8f4f'; }
						else { st.textContent = '✕ ' + ((d && d.message) || 'Save failed.'); st.style.color = '#d63638'; }
					})
					.catch(function (e) { btn.disabled = false; st.textContent = '✕ ' + e.message; st.style.color = '#d63638'; });
			});
		})();
		</script>
		<?php endif; ?>
		<?php
		AQ_Admin_Hub::close();
	}
}
