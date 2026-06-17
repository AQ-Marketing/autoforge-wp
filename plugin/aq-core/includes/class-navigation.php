<?php
/**
 * AutoForge — Navigation editor (tab: aq-navigation).
 *
 * Edits the header primary menu and the footer link columns + social, all of
 * which live in the `aq_site_config` overlay (config/site.php defaults). Writes
 * go through AQ_Site_Config::update() so they ride on top of the file defaults
 * and feed aq_site() in parts/site-header.php + parts/site-footer.php.
 *
 * The three mega-menu panels (services / specialty / areas) are fixed in the
 * header template; a nav item can be pointed at one of them via the Type select,
 * but new panels can't be invented here.
 *
 * REST: POST aq/v1/site-nav → validate + save. Gated on manage_options + the WP
 * REST nonce. Vanilla JS, no build step. SQLite-safe (option get/update only).
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Navigation {

	const CAP = 'manage_options';

	/** Mega-menu panels a nav item may open (fixed in the header template). */
	private static function panels(): array {
		return [
			''          => 'Normal link',
			'services'  => 'Services mega-menu',
			'specialty' => 'Specialty mega-menu',
			'areas'     => 'Service-area mega-menu',
		];
	}

	/* ============================ register ============================ */

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/site-nav', [
			'methods'             => 'POST',
			'permission_callback' => static fn() => current_user_can(self::CAP),
			'callback'            => [__CLASS__, 'rest_save'],
		]);
	}

	/* ============================ REST save ============================ */

	public static function rest_save(WP_REST_Request $req) {
		if (!class_exists('AQ_Site_Config')) {
			return new WP_Error('aq_no_config', 'Site config unavailable.', ['status' => 500]);
		}
		$body = $req->get_json_params();
		if (!is_array($body)) {
			$body = $req->get_params();
		}
		if (!is_array($body)) {
			return new WP_Error('aq_bad_body', 'Invalid request body.', ['status' => 400]);
		}
		$patch = self::sanitize($body);
		AQ_Site_Config::update($patch);
		return rest_ensure_response(['ok' => true, 'saved' => $patch]);
	}

	/** A relative path or absolute URL; '#' (and blanks) pass through as '#'. */
	private static function url(string $v): string {
		$v = trim($v);
		if ($v === '' || $v === '#') {
			return '#';
		}
		return esc_url_raw($v);
	}

	/** Sanitize a list of {label, href} rows; drop empties. */
	private static function links($raw): array {
		$out = [];
		if (is_array($raw)) {
			foreach ($raw as $row) {
				if (!is_array($row)) {
					continue;
				}
				$label = sanitize_text_field((string) ($row['label'] ?? ''));
				if ($label === '') {
					continue;
				}
				$out[] = ['label' => $label, 'href' => self::url((string) ($row['href'] ?? '#'))];
			}
		}
		return array_values($out);
	}

	/** Whitelist + sanitize the incoming nav/footer payload. */
	private static function sanitize(array $in): array {
		$patch  = [];
		$panels = array_keys(self::panels());

		if (isset($in['nav']) && is_array($in['nav'])) {
			$nav = [];
			foreach ($in['nav'] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$label = sanitize_text_field((string) ($row['label'] ?? ''));
				if ($label === '') {
					continue;
				}
				$item  = ['href' => self::url((string) ($row['href'] ?? '#')), 'label' => $label];
				$panel = (string) ($row['panel'] ?? '');
				if ($panel !== '' && in_array($panel, $panels, true)) {
					$item['panel'] = $panel;
					$item['id']    = 'nav-' . $panel;
				}
				$nav[] = $item;
			}
			$patch['nav'] = array_values($nav);
		}

		if (isset($in['footer']) && is_array($in['footer'])) {
			$f = [];
			foreach (['company', 'inspections'] as $col) {
				if (isset($in['footer'][$col]) && is_array($in['footer'][$col])) {
					$f[$col] = [
						'heading' => sanitize_text_field((string) ($in['footer'][$col]['heading'] ?? '')),
						'links'   => self::links($in['footer'][$col]['links'] ?? []),
					];
				}
			}
			if (array_key_exists('legal', $in['footer'])) {
				$f['legal'] = self::links($in['footer']['legal']);
			}
			if (isset($in['footer']['social']) && is_array($in['footer']['social'])) {
				$f['social'] = [
					'facebook'  => self::url((string) ($in['footer']['social']['facebook'] ?? '#')),
					'instagram' => self::url((string) ($in['footer']['social']['instagram'] ?? '#')),
				];
			}
			if ($f) {
				$patch['footer'] = $f;
			}
		}

		return $patch;
	}

	/* ============================ render ============================ */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'aq-core'));
		}

		$cfg    = class_exists('AQ_Site_Config') ? AQ_Site_Config::get() : (function_exists('aq_site') ? (array) aq_site() : []);
		$nav    = is_array($cfg['nav'] ?? null) ? array_values($cfg['nav']) : [];
		$footer = is_array($cfg['footer'] ?? null) ? $cfg['footer'] : [];
		$company= is_array($footer['company'] ?? null) ? $footer['company'] : [];
		$insp   = is_array($footer['inspections'] ?? null) ? $footer['inspections'] : [];
		$legal  = is_array($footer['legal'] ?? null) ? array_values($footer['legal']) : [];
		$social = is_array($footer['social'] ?? null) ? $footer['social'] : [];

		$nonce = wp_create_nonce('wp_rest');
		$rest  = esc_url_raw(rest_url('aq/v1/site-nav'));

		AQ_Admin_Hub::open('Navigation', 'Edit the header menu and footer links. Changes go live on every page.', 'aq-navigation');
		self::style();

		echo '<div id="aq-nav-notice" class="aq-nav-notice" style="display:none;"></div>';
		echo '<form id="aq-nav-form" onsubmit="return false;">';

		/* ---------------- Header menu ---------------- */
		echo '<div class="aq-panel"><h2>Header menu</h2>';
		echo '<p class="aq-nav-help">The top navigation. Set a row&rsquo;s <strong>Type</strong> to open one of the three mega-menus (Services / Specialty / Areas) instead of being a plain link.</p>';
		echo '<table class="aq-table"><thead><tr><th style="width:30px;">#</th><th>Label</th><th>Link</th><th style="width:190px;">Type</th><th style="width:96px;">Order</th><th style="width:46px;"></th></tr></thead>';
		echo '<tbody id="aq-nav-rows">';
		foreach ($nav as $item) {
			echo self::nav_row_html((string) ($item['label'] ?? ''), (string) ($item['href'] ?? ''), (string) ($item['panel'] ?? ''));
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-add">+ Add menu item</button></p>';
		echo '</div>';

		/* ---------------- Footer columns ---------------- */
		echo '<div class="aq-nav-twocol">';
		self::footer_col('company', 'Footer — Company column', (string) ($company['heading'] ?? 'Company'), is_array($company['links'] ?? null) ? $company['links'] : []);
		self::footer_col('inspections', 'Footer — Inspections column', (string) ($insp['heading'] ?? 'Inspections'), is_array($insp['links'] ?? null) ? $insp['links'] : []);
		echo '</div>';

		/* ---------------- Legal + social ---------------- */
		echo '<div class="aq-nav-twocol">';

		echo '<div class="aq-panel"><h2>Footer — Legal links</h2>';
		echo '<p class="aq-nav-help">The small links in the footer&rsquo;s bottom bar.</p>';
		echo '<table class="aq-table"><thead><tr><th style="width:30px;">#</th><th>Label</th><th>Link</th><th style="width:96px;">Order</th><th style="width:46px;"></th></tr></thead>';
		echo '<tbody id="aq-nav-legal">';
		foreach ($legal as $l) {
			echo self::link_row_html((string) ($l['label'] ?? ''), (string) ($l['href'] ?? ''));
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost aq-nav-addlink" data-tbody="aq-nav-legal">+ Add link</button></p>';
		echo '</div>';

		echo '<div class="aq-panel"><h2>Footer — Social</h2>';
		echo '<p class="aq-nav-help">Profile URLs for the footer icons. Use <code>#</code> to leave a link inactive.</p>';
		echo '<div class="aq-nav-grid">';
		self::text('social_facebook', 'Facebook URL', (string) ($social['facebook'] ?? '#'));
		self::text('social_instagram', 'Instagram URL', (string) ($social['instagram'] ?? '#'));
		echo '</div></div>';

		echo '</div>'; // twocol

		echo '<div class="aq-nav-savebar">';
		echo '<button type="button" class="aq-btn" id="aq-nav-save">Save navigation</button>';
		echo '<span class="aq-nav-saving" id="aq-nav-saving" style="display:none;">Saving…</span>';
		echo '</div>';

		echo '</form>';

		self::script($rest, $nonce);
		AQ_Admin_Hub::close();
	}

	/* ---------------- render helpers ---------------- */

	private static function footer_col(string $key, string $title, string $heading, array $links): void {
		echo '<div class="aq-panel"><h2>' . esc_html($title) . '</h2>';
		echo '<label class="aq-nav-field"><span class="aq-nav-label">Column heading</span>';
		printf('<input type="text" class="aq-nav-input" data-key="footer.%s.heading" value="%s" /></label>', esc_attr($key), esc_attr($heading));
		echo '<table class="aq-table" style="margin-top:14px;"><thead><tr><th style="width:30px;">#</th><th>Label</th><th>Link</th><th style="width:96px;">Order</th><th style="width:46px;"></th></tr></thead>';
		printf('<tbody id="aq-nav-%s">', esc_attr($key));
		foreach (array_values($links) as $l) {
			echo self::link_row_html((string) ($l['label'] ?? ''), (string) ($l['href'] ?? ''));
		}
		echo '</tbody></table>';
		printf('<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost aq-nav-addlink" data-tbody="aq-nav-%s">+ Add link</button></p>', esc_attr($key));
		echo '</div>';
	}

	private static function text(string $key, string $label, string $value): void {
		printf(
			'<label class="aq-nav-field"><span class="aq-nav-label">%s</span><input type="text" class="aq-nav-input" data-key="%s" value="%s" /></label>',
			esc_html($label),
			esc_attr($key),
			esc_attr($value)
		);
	}

	private static function order_cell(): string {
		return '<td class="aq-nav-order">'
			. '<button type="button" class="aq-iconbtn aq-nav-up" title="Move up">&uarr;</button>'
			. '<button type="button" class="aq-iconbtn aq-nav-down" title="Move down">&darr;</button></td>'
			. '<td><button type="button" class="aq-iconbtn aq-iconbtn--del aq-nav-del" title="Remove">&times;</button></td>';
	}

	private static function link_row_html(string $label, string $href): string {
		return '<tr class="aq-nav-row">'
			. '<td class="aq-nav-idx">&bull;</td>'
			. '<td><input type="text" class="aq-nav-input aq-nav-label-i" value="' . esc_attr($label) . '" placeholder="Link text" /></td>'
			. '<td><input type="text" class="aq-nav-input aq-nav-href-i" value="' . esc_attr($href) . '" placeholder="/path/ or https://" /></td>'
			. self::order_cell()
			. '</tr>';
	}

	private static function nav_row_html(string $label, string $href, string $panel): string {
		$opts = '';
		foreach (self::panels() as $val => $name) {
			$opts .= '<option value="' . esc_attr($val) . '"' . selected($panel, $val, false) . '>' . esc_html($name) . '</option>';
		}
		return '<tr class="aq-nav-row">'
			. '<td class="aq-nav-idx">&bull;</td>'
			. '<td><input type="text" class="aq-nav-input aq-nav-label-i" value="' . esc_attr($label) . '" placeholder="Menu label" /></td>'
			. '<td><input type="text" class="aq-nav-input aq-nav-href-i" value="' . esc_attr($href) . '" placeholder="/path/" /></td>'
			. '<td><select class="aq-nav-input aq-nav-panel-i">' . $opts . '</select></td>'
			. self::order_cell()
			. '</tr>';
	}

	private static function style(): void {
		?>
		<style>
			.aq-hub .aq-nav-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
			.aq-hub .aq-nav-field { display:flex; flex-direction:column; gap:5px; }
			.aq-hub .aq-nav-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; }
			.aq-hub .aq-nav-input { width:100%; padding:8px 10px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; background:#fff; }
			.aq-hub .aq-nav-input:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 3px rgba(200,16,46,.18); }
			.aq-hub .aq-nav-help { font-size:12px; color:#5b6471; margin:0 0 16px; }
			.aq-hub .aq-nav-help code { background:#eef1f5; padding:1px 5px; border-radius:4px; font-size:11px; }
			.aq-hub .aq-nav-idx { color:#8a94a1; font-weight:700; text-align:center; }
			.aq-hub .aq-nav-order { white-space:nowrap; }
			.aq-hub .aq-iconbtn { background:#fff; border:1px solid #c9cfd6; color:#15191f; width:28px; height:28px; border-radius:7px; cursor:pointer; font-size:14px; line-height:1; padding:0; }
			.aq-hub .aq-iconbtn:hover { background:#f4f6fc; }
			.aq-hub .aq-iconbtn--del { color:#a30d25; border-color:#e6c4c4; }
			.aq-hub .aq-iconbtn--del:hover { background:#fbe7e7; }
			.aq-hub .aq-nav-twocol { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
			@media (max-width:1100px){ .aq-hub .aq-nav-twocol { grid-template-columns:1fr; } }
			.aq-hub .aq-nav-savebar { position:sticky; bottom:0; margin-top:22px; padding:16px 0; display:flex; align-items:center; gap:14px; }
			.aq-hub .aq-nav-saving { font-size:13px; color:#5b6471; }
			.aq-hub .aq-nav-notice { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; }
			.aq-hub .aq-nav-notice--ok { background:#eaf0ea; color:#1a8f4f; border:1px solid #bfe0c8; }
			.aq-hub .aq-nav-notice--err { background:#fbe7e7; color:#a30d25; border:1px solid #e6c4c4; }
		</style>
		<?php
	}

	private static function script(string $rest, string $nonce): void {
		$blank_link = self::link_row_html('', '');
		$blank_nav  = self::nav_row_html('', '', '');
		?>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode($rest); ?>, NONCE = <?php echo wp_json_encode($nonce); ?>;
			var BLANK_LINK = <?php echo wp_json_encode($blank_link); ?>, BLANK_NAV = <?php echo wp_json_encode($blank_nav); ?>;
			function $(s, c) { return (c || document).querySelector(s); }
			function $all(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

			function renumber(tbody) {
				$all('.aq-nav-row', tbody).forEach(function (tr, i) {
					var idx = $('.aq-nav-idx', tr); if (idx) { idx.textContent = (i + 1); }
				});
			}
			function wireRow(tr) {
				var tbody = tr.parentNode;
				var del = $('.aq-nav-del', tr), up = $('.aq-nav-up', tr), down = $('.aq-nav-down', tr);
				if (del) del.addEventListener('click', function () { tbody.removeChild(tr); renumber(tbody); });
				if (up) up.addEventListener('click', function () { var p = tr.previousElementSibling; if (p) { tbody.insertBefore(tr, p); renumber(tbody); } });
				if (down) down.addEventListener('click', function () { var n = tr.nextElementSibling; if (n) { tbody.insertBefore(n, tr); renumber(tbody); } });
			}
			function addRow(tbody, html, focusFirst) {
				var tmp = document.createElement('tbody');
				tmp.innerHTML = html.trim();
				var tr = tmp.querySelector('tr');
				tbody.appendChild(tr);
				wireRow(tr);
				renumber(tbody);
				if (focusFirst) { var f = $('.aq-nav-label-i', tr); if (f) f.focus(); }
			}

			$all('#aq-nav-form tbody').forEach(function (tb) { $all('.aq-nav-row', tb).forEach(wireRow); renumber(tb); });

			var addNav = $('#aq-nav-add');
			if (addNav) addNav.addEventListener('click', function () { addRow($('#aq-nav-rows'), BLANK_NAV, true); });
			$all('.aq-nav-addlink').forEach(function (btn) {
				btn.addEventListener('click', function () { var tb = document.getElementById(btn.getAttribute('data-tbody')); if (tb) addRow(tb, BLANK_LINK, true); });
			});

			function rowsFrom(id) {
				return $all('.aq-nav-row', document.getElementById(id)).map(function (tr) {
					return { label: (($('.aq-nav-label-i', tr) || {}).value || '').trim(), href: (($('.aq-nav-href-i', tr) || {}).value || '').trim() };
				}).filter(function (r) { return r.label !== ''; });
			}

			function setDeep(obj, dotted, val) {
				var parts = dotted.split('.'), node = obj;
				for (var i = 0; i < parts.length - 1; i++) { if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) node[parts[i]] = {}; node = node[parts[i]]; }
				node[parts[parts.length - 1]] = val;
			}

			function collect() {
				var p = {};
				// header nav (with panel select)
				p.nav = $all('.aq-nav-row', document.getElementById('aq-nav-rows')).map(function (tr) {
					return {
						label: (($('.aq-nav-label-i', tr) || {}).value || '').trim(),
						href: (($('.aq-nav-href-i', tr) || {}).value || '').trim(),
						panel: (($('.aq-nav-panel-i', tr) || {}).value || '')
					};
				}).filter(function (r) { return r.label !== ''; });
				// footer columns
				p.footer = { company: { links: rowsFrom('aq-nav-company') }, inspections: { links: rowsFrom('aq-nav-inspections') }, legal: rowsFrom('aq-nav-legal'), social: {} };
				// headings + social via data-key inputs
				$all('.aq-nav-input[data-key]').forEach(function (inp) { setDeep(p, inp.getAttribute('data-key'), inp.value.trim()); });
				return p;
			}

			function notice(msg, ok) {
				var el = $('#aq-nav-notice'); if (!el) return;
				el.textContent = msg;
				el.className = 'aq-nav-notice ' + (ok ? 'aq-nav-notice--ok' : 'aq-nav-notice--err');
				el.style.display = 'block';
				if (ok) { clearTimeout(notice._t); notice._t = setTimeout(function () { el.style.display = 'none'; }, 4000); }
			}

			var saveBtn = $('#aq-nav-save'), saving = $('#aq-nav-saving');
			if (saveBtn) saveBtn.addEventListener('click', function () {
				saveBtn.disabled = true; if (saving) saving.style.display = 'inline';
				fetch(REST, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify(collect()) })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						if (res.ok && res.body && res.body.ok) {
							var n = (res.body.saved && res.body.saved.nav) ? res.body.saved.nav.length : 0;
							notice('Saved. ' + n + ' header item' + (n === 1 ? '' : 's') + ' and the footer links are live.', true);
						} else { notice('Save failed: ' + ((res.body && (res.body.message || res.body.code)) || 'unknown error'), false); }
					})
					.catch(function (e) { notice('Save failed: ' + e.message, false); })
					.then(function () { saveBtn.disabled = false; if (saving) saving.style.display = 'none'; });
			});
		})();
		</script>
		<?php
	}
}
