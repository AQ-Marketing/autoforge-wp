<?php
/**
 * AutoForge — SEO Manager screen (tab: aq-seo).
 *
 * A single-table editor for the SEO meta of every published page: title,
 * meta description, canonical and the noindex toggle. Lengths are measured
 * against the RENDERED title (brand suffix deduped the same way as
 * AQ_SEO_Meta::title) and the rendered description, with live ≤60 / ≤160
 * badges. Edits POST per row to the REST endpoint and update the ACF fields.
 *
 * Conventions: server-side render for the table (fast, no extra round-trip);
 * REST only for saves. No raw SQL — get_posts + get_field / update_field.
 * SQLite-safe. Secrets never reach the browser. Vanilla JS, inlined.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_SEO_Manager {

	const CAP        = 'manage_options';
	const TITLE_MAX  = 60;
	const DESC_MAX   = 160;

	/* ============================ register ============================ */

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/seo/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'rest_save'],
			'permission_callback' => static fn() => current_user_can(self::CAP),
			'args'                => [
				'id' => [
					'validate_callback' => static fn($v) => is_numeric($v),
				],
			],
		]);
	}

	/* ============================ helpers ============================ */

	/** Brand short name, used for the rendered-title dedup rule. */
	private static function brand(): string {
		$short = function_exists('aq_site') ? (string) aq_site('shortName') : '';
		return $short !== '' ? $short : 'Your Business';
	}

	/**
	 * Rendered title = stored title with the brand suffix appended only if the
	 * stored value does not already contain it. Mirrors AQ_SEO_Meta::title so
	 * the length we measure here matches what actually ships in <title>.
	 */
	private static function rendered_title(string $stored): string {
		$stored = trim($stored);
		if ($stored === '') {
			return '';
		}
		$brand = self::brand();
		return str_contains($stored, $brand) ? $stored : $stored . ' | ' . $brand;
	}

	private static function rendered_title_len(string $stored): int {
		return self::mb_len(self::rendered_title($stored));
	}

	private static function mb_len(string $s): int {
		return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
	}

	/** Length badge HTML for a measured length against a max. */
	private static function len_badge(int $len, int $max): string {
		$cls = $len === 0 ? 'aq-badge--warn' : ($len > $max ? 'aq-badge--off' : 'aq-badge--ok');
		return '<span class="aq-badge ' . $cls . '">' . (int) $len . '/' . (int) $max . '</span>';
	}

	/** Safe ACF read that tolerates ACF being absent. */
	private static function get(string $field, int $id): string {
		if (!function_exists('get_field')) {
			return '';
		}
		$v = get_field($field, $id);
		if (is_bool($v)) {
			return $v ? '1' : '';
		}
		return is_scalar($v) ? (string) $v : '';
	}

	/* ============================ REST save ============================ */

	/**
	 * POST aq/v1/seo/{id}. Accepts any subset of seo_title, seo_description,
	 * seo_canonical, seo_noindex. Validates lengths (warn, never block) and
	 * writes via update_field. Returns the new rendered lengths + any warnings.
	 */
	public static function rest_save(\WP_REST_Request $req) {
		$id   = (int) $req['id'];
		$post = get_post($id);
		if (!$post || $post->post_type !== 'page') {
			return new WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
		}
		if (!function_exists('update_field')) {
			return new WP_REST_Response(['ok' => false, 'error' => 'acf_missing'], 500);
		}

		$body = $req->get_json_params();
		if (!is_array($body)) {
			$body = $req->get_params();
		}

		$violations = [];

		if (array_key_exists('seo_title', $body)) {
			$title = sanitize_text_field((string) $body['seo_title']);
			update_field('field_aq_seo_seo_title', $title, $id);
			if (self::rendered_title_len($title) > self::TITLE_MAX) {
				$violations[] = 'Rendered title exceeds ' . self::TITLE_MAX . ' characters.';
			}
		}

		if (array_key_exists('seo_description', $body)) {
			$desc = sanitize_textarea_field((string) $body['seo_description']);
			update_field('field_aq_seo_seo_description', $desc, $id);
			if (self::mb_len($desc) > self::DESC_MAX) {
				$violations[] = 'Meta description exceeds ' . self::DESC_MAX . ' characters.';
			}
		}

		if (array_key_exists('seo_canonical', $body)) {
			$canon = esc_url_raw(trim((string) $body['seo_canonical']));
			update_field('field_aq_seo_seo_canonical', $canon, $id);
		}

		if (array_key_exists('seo_noindex', $body)) {
			$noindex = filter_var($body['seo_noindex'], FILTER_VALIDATE_BOOLEAN);
			update_field('field_aq_seo_seo_noindex', $noindex, $id);
		}

		// Recompute from the freshly-stored values so the client mirrors truth.
		$cur_title = self::get('seo_title', $id);
		$cur_desc  = self::get('seo_description', $id);

		return new WP_REST_Response([
			'ok'               => true,
			'renderedTitleLen' => self::rendered_title_len($cur_title),
			'renderedTitle'    => self::rendered_title($cur_title),
			'descLen'          => self::mb_len($cur_desc),
			'noindex'          => self::get('seo_noindex', $id) !== '',
			'violations'       => $violations,
		], 200);
	}

	/* ============================ render ============================ */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'aq-core'));
		}

		AQ_Admin_Hub::open(
			'SEO Manager',
			'Edit titles, descriptions, canonicals and indexing across all pages.',
			'aq-seo'
		);

		$pages = get_posts([
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		]);

		// ---- stats ----
		$total       = count($pages);
		$complete    = 0; // title + description both present
		$over_limit  = 0; // rendered title > 60 OR description > 160
		$noindexed   = 0;

		$rows = [];
		foreach ($pages as $p) {
			$title  = self::get('seo_title', $p->ID);
			$desc   = self::get('seo_description', $p->ID);
			$canon  = self::get('seo_canonical', $p->ID);
			$noidx  = self::get('seo_noindex', $p->ID) !== '';

			$t_len = self::rendered_title_len($title);
			$d_len = self::mb_len($desc);

			if ($title !== '' && $desc !== '') {
				$complete++;
			}
			if ($t_len > self::TITLE_MAX || $d_len > self::DESC_MAX) {
				$over_limit++;
			}
			if ($noidx) {
				$noindexed++;
			}

			$rows[] = [
				'id'    => $p->ID,
				'name'  => get_the_title($p) ?: '(untitled)',
				'edit'  => get_edit_post_link($p->ID, ''),
				'path'  => parse_url((string) get_permalink($p), PHP_URL_PATH) ?: '/',
				'title' => $title,
				'desc'  => $desc,
				'canon' => $canon,
				'noidx' => $noidx,
				'tlen'  => $t_len,
				'dlen'  => $d_len,
			];
		}

		$nonce = wp_create_nonce('wp_rest');
		$base  = esc_url_raw(rest_url('aq/v1/seo/'));

		// ---- screen-specific styles (scoped under .aq-hub) ----
		?>
		<style>
			.aq-hub .aq-seo-input { width:100%; font-size:12px; padding:6px 8px; border:1px solid #e6e8eb; border-radius:7px; color:#0d1014; background:#fff; font-family:inherit; }
			.aq-hub .aq-seo-input:focus { outline:none; border-color:#c8102e; box-shadow:0 0 0 2px rgba(200,16,46,.18); }
			.aq-hub textarea.aq-seo-input { resize:vertical; min-height:38px; line-height:1.4; }
			.aq-hub .aq-seo-row td { vertical-align:top; }
			.aq-hub .aq-seo-cell-title { min-width:240px; }
			.aq-hub .aq-seo-cell-desc  { min-width:280px; }
			.aq-hub .aq-seo-cell-canon { min-width:200px; }
			.aq-hub .aq-seo-meta { display:flex; align-items:center; gap:8px; margin-top:6px; }
			.aq-hub .aq-seo-pagename { font-weight:700; color:#0d1014; text-decoration:none; }
			.aq-hub .aq-seo-pagename:hover { color:#c8102e; }
			.aq-hub .aq-seo-pathcode { display:block; font-size:11px; color:#5b6471; margin-top:3px; word-break:break-all; }
			.aq-hub .aq-seo-save { white-space:nowrap; }
			.aq-hub .aq-seo-status { font-size:11px; font-weight:700; margin-left:8px; }
			.aq-hub .aq-seo-status--ok   { color:#1a8f4f; }
			.aq-hub .aq-seo-status--err  { color:#a30d25; }
			.aq-hub .aq-seo-status--busy { color:#9a6212; }
			.aq-hub .aq-seo-noindex { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#5b6471; cursor:pointer; }
			.aq-hub .aq-seo-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
			.aq-hub .aq-seo-search { max-width:280px; }
			.aq-hub .aq-seo-dirty td { background:#fffaf0 !important; }
		</style>

		<div class="aq-cards">
			<div class="aq-card">
				<p class="aq-card__label">SEO Complete</p>
				<div class="aq-card__num"><?php echo (int) $complete; ?> / <?php echo (int) $total; ?></div>
				<div class="aq-card__sub">pages with title + description</div>
			</div>
			<div class="aq-card">
				<p class="aq-card__label">Over Limit</p>
				<div class="aq-card__num"><?php echo (int) $over_limit; ?></div>
				<div class="aq-card__sub">title &gt; <?php echo self::TITLE_MAX; ?> or description &gt; <?php echo self::DESC_MAX; ?></div>
			</div>
			<div class="aq-card">
				<p class="aq-card__label">Noindexed</p>
				<div class="aq-card__num"><?php echo (int) $noindexed; ?></div>
				<div class="aq-card__sub">excluded from search engines</div>
			</div>
			<div class="aq-card">
				<p class="aq-card__label">Total Pages</p>
				<div class="aq-card__num"><?php echo (int) $total; ?></div>
				<div class="aq-card__sub">published</div>
			</div>
		</div>

		<div class="aq-panel">
			<h2>All pages</h2>
			<div class="aq-seo-toolbar">
				<input type="search" id="aq-seo-search" class="aq-seo-input aq-seo-search" placeholder="Filter by title or path…" autocomplete="off">
				<span class="aq-pill">Rendered title appends &ldquo;| <?php echo esc_html(self::brand()); ?>&rdquo; only when missing</span>
			</div>
			<?php if (!function_exists('get_field')) : ?>
				<p><span class="aq-badge aq-badge--off">ACF unavailable</span> SEO fields cannot be read or saved until ACF Pro is active.</p>
			<?php endif; ?>
			<table class="aq-table" id="aq-seo-table">
				<thead>
					<tr>
						<th>Page</th>
						<th class="aq-seo-cell-title">SEO Title</th>
						<th class="aq-seo-cell-desc">Meta Description</th>
						<th class="aq-seo-cell-canon">Canonical</th>
						<th>
							<label class="aq-seo-noindex" title="Check or uncheck noindex for every shown page (saves each immediately)">
								<input type="checkbox" id="aq-seo-noindex-all">
								<span>Noindex</span>
							</label>
						</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($rows as $r) : ?>
					<tr class="aq-seo-row" data-id="<?php echo (int) $r['id']; ?>"
						data-search="<?php echo esc_attr(strtolower($r['name'] . ' ' . $r['path'])); ?>">
						<td>
							<?php if ($r['edit']) : ?>
								<a class="aq-seo-pagename" href="<?php echo esc_url($r['edit']); ?>"><?php echo esc_html($r['name']); ?></a>
							<?php else : ?>
								<span class="aq-seo-pagename"><?php echo esc_html($r['name']); ?></span>
							<?php endif; ?>
							<code class="aq-seo-pathcode"><?php echo esc_html($r['path']); ?></code>
						</td>
						<td class="aq-seo-cell-title">
							<input type="text" class="aq-seo-input" data-field="seo_title"
								value="<?php echo esc_attr($r['title']); ?>" placeholder="Page title (brand auto-appended)">
							<div class="aq-seo-meta">
								<span class="aq-seo-tbadge"><?php echo self::len_badge($r['tlen'], self::TITLE_MAX); ?></span>
							</div>
						</td>
						<td class="aq-seo-cell-desc">
							<textarea class="aq-seo-input" data-field="seo_description" rows="2"
								placeholder="Meta description (≤<?php echo self::DESC_MAX; ?>)"><?php echo esc_textarea($r['desc']); ?></textarea>
							<div class="aq-seo-meta">
								<span class="aq-seo-dbadge"><?php echo self::len_badge($r['dlen'], self::DESC_MAX); ?></span>
							</div>
						</td>
						<td class="aq-seo-cell-canon">
							<input type="text" class="aq-seo-input" data-field="seo_canonical"
								value="<?php echo esc_attr($r['canon']); ?>" placeholder="https://…">
						</td>
						<td>
							<label class="aq-seo-noindex">
								<input type="checkbox" data-field="seo_noindex" <?php checked($r['noidx']); ?>>
								<span>noindex</span>
							</label>
						</td>
						<td>
							<button type="button" class="aq-btn aq-seo-save">Save</button>
							<span class="aq-seo-status" aria-live="polite"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$rows) : ?>
					<tr><td colspan="6" style="color:#5b6471;">No published pages found.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<script>
		(function () {
			var REST  = <?php echo wp_json_encode($base); ?>;
			var NONCE = <?php echo wp_json_encode($nonce); ?>;
			var TITLE_MAX = <?php echo (int) self::TITLE_MAX; ?>;
			var DESC_MAX  = <?php echo (int) self::DESC_MAX; ?>;
			var BRAND = <?php echo wp_json_encode(self::brand()); ?>;

			function renderedTitleLen(stored) {
				stored = (stored || '').trim();
				if (!stored) { return 0; }
				var full = stored.indexOf(BRAND) !== -1 ? stored : stored + ' | ' + BRAND;
				return Array.from(full).length; // count code points, matches mb_strlen
			}
			function chars(s) { return Array.from(s || '').length; }

			function badge(len, max) {
				var cls = len === 0 ? 'aq-badge--warn' : (len > max ? 'aq-badge--off' : 'aq-badge--ok');
				var b = document.createElement('span');
				b.className = 'aq-badge ' + cls;
				b.textContent = len + '/' + max;
				return b;
			}
			function setBadge(holder, len, max) {
				if (!holder) { return; }
				holder.innerHTML = '';
				holder.appendChild(badge(len, max));
			}

			var table = document.getElementById('aq-seo-table');
			if (!table) { return; }

			// ---- Select-all for the Noindex column (acts on shown rows only) ----
			var allBox = document.getElementById('aq-seo-noindex-all');
			function aqVisibleRows() {
				return Array.prototype.slice.call(table.querySelectorAll('.aq-seo-row'))
					.filter(function (r) { return r.style.display !== 'none'; });
			}
			function aqSyncAllBox() {
				if (!allBox) { return; }
				var boxes = aqVisibleRows()
					.map(function (r) { return r.querySelector('[data-field="seo_noindex"]'); })
					.filter(Boolean);
				var on = boxes.filter(function (b) { return b.checked; }).length;
				allBox.checked = boxes.length > 0 && on === boxes.length;
				allBox.indeterminate = on > 0 && on < boxes.length;
			}
			if (allBox) {
				allBox.addEventListener('change', function () {
					var on = allBox.checked;
					aqVisibleRows().forEach(function (row) {
						var cb = row.querySelector('[data-field="seo_noindex"]');
						if (cb && cb.checked !== on) {
							cb.checked = on;
							row.classList.add('aq-seo-dirty');
							save(row); // persist each changed row through the per-row endpoint
						}
					});
					allBox.indeterminate = false;
				});
			}

			// Live length badges as the user types.
			table.addEventListener('input', function (e) {
				var el = e.target;
				if (!el.classList || !el.classList.contains('aq-seo-input')) { return; }
				var row = el.closest('.aq-seo-row');
				if (!row) { return; }
				row.classList.add('aq-seo-dirty');
				var field = el.getAttribute('data-field');
				if (field === 'seo_title') {
					setBadge(row.querySelector('.aq-seo-tbadge'), renderedTitleLen(el.value), TITLE_MAX);
				} else if (field === 'seo_description') {
					setBadge(row.querySelector('.aq-seo-dbadge'), chars(el.value), DESC_MAX);
				}
			});

			// Mark dirty when the noindex checkbox changes.
			table.addEventListener('change', function (e) {
				if (e.target && e.target.getAttribute && e.target.getAttribute('data-field') === 'seo_noindex') {
					var row = e.target.closest('.aq-seo-row');
					if (row) { row.classList.add('aq-seo-dirty'); }
					aqSyncAllBox();
				}
			});

			function collect(row) {
				var payload = {};
				row.querySelectorAll('[data-field]').forEach(function (el) {
					var f = el.getAttribute('data-field');
					if (f === 'seo_noindex') {
						payload[f] = !!el.checked;
					} else {
						payload[f] = el.value;
					}
				});
				return payload;
			}

			function status(row, text, kind) {
				var s = row.querySelector('.aq-seo-status');
				if (!s) { return; }
				s.textContent = text;
				s.className = 'aq-seo-status' + (kind ? ' aq-seo-status--' + kind : '');
			}

			function save(row) {
				var id = row.getAttribute('data-id');
				var btn = row.querySelector('.aq-seo-save');
				if (btn) { btn.disabled = true; }
				status(row, 'Saving…', 'busy');

				fetch(REST + encodeURIComponent(id), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
					body: JSON.stringify(collect(row))
				})
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
				.then(function (res) {
					if (btn) { btn.disabled = false; }
					if (!res.ok || !res.body || !res.body.ok) {
						var msg = (res.body && res.body.error) ? res.body.error : 'Save failed';
						status(row, '✕ ' + msg, 'err');
						return;
					}
					var b = res.body;
					setBadge(row.querySelector('.aq-seo-tbadge'), b.renderedTitleLen, TITLE_MAX);
					setBadge(row.querySelector('.aq-seo-dbadge'), b.descLen, DESC_MAX);
					row.classList.remove('aq-seo-dirty');
					if (b.violations && b.violations.length) {
						status(row, '⚠ Saved · ' + b.violations[0], 'busy');
					} else {
						status(row, '✓ Saved', 'ok');
					}
					setTimeout(function () {
						var s = row.querySelector('.aq-seo-status');
						if (s && s.classList.contains('aq-seo-status--ok')) { s.textContent = ''; s.className = 'aq-seo-status'; }
					}, 2500);
				})
				.catch(function () {
					if (btn) { btn.disabled = false; }
					status(row, '✕ Network error', 'err');
				});
			}

			// Save on button click.
			table.addEventListener('click', function (e) {
				var btn = e.target.closest ? e.target.closest('.aq-seo-save') : null;
				if (!btn) { return; }
				var row = btn.closest('.aq-seo-row');
				if (row) { save(row); }
			});

			// Save on blur of any edited field (only if the row is dirty).
			table.addEventListener('focusout', function (e) {
				var el = e.target;
				if (!el.getAttribute || !el.getAttribute('data-field')) { return; }
				var row = el.closest('.aq-seo-row');
				if (!row || !row.classList.contains('aq-seo-dirty')) { return; }
				// Defer so focus can land inside the same row without double-saving.
				setTimeout(function () {
					if (row.contains(document.activeElement)) { return; }
					if (row.classList.contains('aq-seo-dirty')) { save(row); }
				}, 120);
			});

			// Client-side filter.
			var search = document.getElementById('aq-seo-search');
			if (search) {
				search.addEventListener('input', function () {
					var q = this.value.trim().toLowerCase();
					table.querySelectorAll('.aq-seo-row').forEach(function (row) {
						var hay = row.getAttribute('data-search') || '';
						row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
					});
					aqSyncAllBox();
				});
			}

			aqSyncAllBox(); // reflect the initial noindex state in the header checkbox
		})();
		</script>
		<?php

		AQ_Admin_Hub::close();
	}
}
