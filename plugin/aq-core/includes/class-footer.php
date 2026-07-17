<?php
/**
 * AutoForge — Footer editor (tab: aq-footer).
 *
 * Edits everything that renders in the site footer + sticky call bar: the
 * footer/sticky-bar CTA button, the footer contact-column heading, the
 * Company/Inspections link columns, the legal links bar, and the social-icon
 * repeater. All of it lives in the `aq_site_config` overlay (config/site.php
 * defaults); writes go through AQ_Site_Config::update() so they ride on top
 * of the file defaults and feed aq_site() in parts/site-footer.php. Split out
 * of AQ_Navigation (which keeps the header menu + header CTA + blog/shared
 * chrome labels) so footer editing has its own screen.
 *
 * REST: POST aq/v1/site-footer → validate + save. Gated on manage_options +
 * the WP REST nonce. Vanilla JS, no build step. SQLite-safe (option
 * get/update only).
 *
 * Export/Import: download the current footer config as a JSON file (for
 * editing or hand-off) and import one back. Import POSTs to the same
 * /site-footer route, so the identical sanitization runs.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Footer {

	const CAP = 'manage_options';

	/* ============================ register ============================ */

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/site-footer', [
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

	/** Sanitize a list of {network, url, target} footer social-icon rows; drop unknown networks + empty URLs. */
	private static function social_links($raw): array {
		$out     = [];
		$allowed = array_keys(aq_social_networks());
		if (is_array($raw)) {
			foreach ($raw as $row) {
				if (!is_array($row)) {
					continue;
				}
				$network = (string) ($row['network'] ?? '');
				if (!in_array($network, $allowed, true)) {
					continue;
				}
				$url = self::url((string) ($row['url'] ?? '#'));
				if ($url === '#') {
					continue;
				}
				$target = ((string) ($row['target'] ?? '_blank')) === '_self' ? '_self' : '_blank';
				$out[] = ['network' => $network, 'url' => $url, 'target' => $target];
			}
		}
		return array_values($out);
	}

	/**
	 * Read stored footer.social for the editor form. Accepts the current
	 * {network, url, target} row list, and migrates the old fixed
	 * {facebook, instagram} shape (pre social-icon-repeater) so existing sites
	 * don't lose their links the first time this screen loads after an update.
	 */
	private static function social_rows_for_editing($raw): array {
		if (!is_array($raw)) {
			return [];
		}
		if (array_key_exists('facebook', $raw) || array_key_exists('instagram', $raw)) {
			$rows = [];
			foreach (['facebook', 'instagram'] as $network) {
				$url = (string) ($raw[$network] ?? '#');
				if ($url !== '' && $url !== '#') {
					$rows[] = ['network' => $network, 'url' => $url, 'target' => '_blank'];
				}
			}
			return $rows;
		}
		return array_values($raw);
	}

	/** Whitelist + sanitize the incoming footer payload. */
	private static function sanitize(array $in): array {
		$patch = [];

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
				$f['social'] = self::social_links($in['footer']['social']);
			}
			if (isset($in['footer']['contact']) && is_array($in['footer']['contact'])) {
				$f['contact'] = [
					'heading' => sanitize_text_field((string) ($in['footer']['contact']['heading'] ?? 'Contact Us')),
				];
			}
			if ($f) {
				$patch['footer'] = $f;
			}
		}

		if (isset($in['footerCta']) && is_array($in['footerCta'])) {
			$patch['footerCta'] = [
				'label' => sanitize_text_field((string) ($in['footerCta']['label'] ?? '')),
				'href'  => self::url((string) ($in['footerCta']['href'] ?? '/schedule/')),
			];
		}

		if (isset($in['stickyBar']) && is_array($in['stickyBar'])) {
			$patch['stickyBar'] = [
				'label' => sanitize_text_field((string) ($in['stickyBar']['label'] ?? '')),
			];
		}

		return $patch;
	}

	/* ============================ render ============================ */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'aq-core'));
		}

		$cfg       = class_exists('AQ_Site_Config') ? AQ_Site_Config::get() : (function_exists('aq_site') ? (array) aq_site() : []);
		$footer    = is_array($cfg['footer'] ?? null) ? $cfg['footer'] : [];
		$company   = is_array($footer['company'] ?? null) ? $footer['company'] : [];
		$insp      = is_array($footer['inspections'] ?? null) ? $footer['inspections'] : [];
		$legal     = is_array($footer['legal'] ?? null) ? array_values($footer['legal']) : [];
		$social    = self::social_rows_for_editing($footer['social'] ?? []);
		$f_contact = is_array($footer['contact'] ?? null) ? $footer['contact'] : [];
		$fcta      = is_array($cfg['footerCta'] ?? null) ? $cfg['footerCta'] : [];
		$sbar      = is_array($cfg['stickyBar'] ?? null) ? $cfg['stickyBar'] : [];

		$nonce = wp_create_nonce('wp_rest');
		$rest  = esc_url_raw(rest_url('aq/v1/site-footer'));

		AQ_Admin_Hub::open('Footer', 'Edit the footer link columns, legal links, social icons, and footer/sticky-bar CTA. Changes go live on every page.', 'aq-footer');
		self::style();

		echo '<div id="aq-nav-notice" class="aq-nav-notice" style="display:none;"></div>';
		echo '<form id="aq-nav-form" onsubmit="return false;">';

		/* ---------------- Export / Import ---------------- */
		echo '<div class="aq-panel aq-nav-io">';
		echo '<h2>Export / Import</h2>';
		echo '<p class="aq-nav-help">Download the footer config below as a JSON file you can edit or hand off, then import that file here to apply it. <strong>Importing replaces</strong> the footer links + CTA currently shown &mdash; it takes effect as soon as you confirm.</p>';
		echo '<div class="aq-nav-iobtns">';
		echo '<button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-export">Export to file</button>';
		echo '<button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-import-btn">Import from file&hellip;</button>';
		echo '<input type="file" id="aq-nav-import-file" accept="application/json,.json" hidden />';
		echo '</div>';
		echo '</div>';

		/* ---------------- Footer CTA + Sticky call bar ---------------- */
		echo '<div class="aq-nav-twocol">';
		echo '<div class="aq-panel"><h2>Footer CTA button</h2>';
		echo '<p class="aq-nav-help">The call-to-action in the footer and sticky call bar.</p>';
		echo '<div class="aq-nav-grid">';
		self::text('footerCta.label', 'Button text', (string) ($fcta['label'] ?? 'Request a Call Back'), 'The words on the footer and sticky-bar button, e.g. "Request a Call Back".');
		self::text('footerCta.href',  'Button link', (string) ($fcta['href'] ?? '/schedule/'), 'Where the button sends visitors — a path like /schedule/ or a full web address.');
		echo '</div></div>';
		echo '<div class="aq-panel"><h2>Sticky call bar</h2>';
		echo '<p class="aq-nav-help">The bar fixed to the bottom of the screen. Button text and link come from the Footer CTA.</p>';
		echo '<div class="aq-nav-grid">';
		self::text('stickyBar.label', 'Prompt text', (string) ($sbar['label'] ?? 'Questions? Call us:'), 'Short message shown on the sticky bottom bar, before the call button.');
		echo '</div></div>';
		echo '</div>';

		/* ---------------- Footer — Contact column ---------------- */
		echo '<div class="aq-panel"><h2>Footer — Contact column</h2>';
		echo '<p class="aq-nav-help">The heading above the phone/address block in the footer. Contact details come from Locations.</p>';
		echo '<div class="aq-nav-grid">';
		self::text('footer.contact.heading', 'Column heading', (string) ($f_contact['heading'] ?? 'Contact Us'));
		echo '</div></div>';

		/* ---------------- Footer columns ---------------- */
		echo '<div class="aq-nav-twocol">';
		self::footer_col('company', 'Footer — Company column', (string) ($company['heading'] ?? 'Company'), is_array($company['links'] ?? null) ? $company['links'] : []);
		self::footer_col('inspections', 'Footer — Inspections column', (string) ($insp['heading'] ?? 'Inspections'), is_array($insp['links'] ?? null) ? $insp['links'] : []);
		echo '</div>';

		/* ---------------- Legal + social ---------------- */
		echo '<div class="aq-nav-twocol">';

		echo '<div class="aq-panel"><h2>Footer — Legal links</h2>';
		echo '<p class="aq-nav-help">The small links in the footer&rsquo;s bottom bar.</p>';
		echo '<table class="aq-table"><thead><tr><th style="width:30px;">#</th><th>Label</th><th>Link' . AQ_Admin_Hub::tip('Each link\'s destination — a path like /privacy/ or a full web address.') . '</th><th style="width:96px;">Order</th><th style="width:46px;"></th></tr></thead>';
		echo '<tbody id="aq-nav-legal">';
		foreach ($legal as $l) {
			echo self::link_row_html((string) ($l['label'] ?? ''), (string) ($l['href'] ?? ''));
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost aq-nav-addlink" data-tbody="aq-nav-legal">+ Add link</button></p>';
		echo '</div>';

		echo '<div class="aq-panel"><h2>Footer — Social</h2>';
		echo '<p class="aq-nav-help">The icons in the footer. Add one row per profile, choose the network, paste its URL, and pick whether it opens in the same tab or a new one.</p>';
		echo '<table class="aq-table"><thead><tr><th style="width:30px;">#</th><th style="width:160px;">Network</th><th>Profile URL</th><th style="width:120px;">Opens in</th><th style="width:96px;">Order</th><th style="width:46px;"></th></tr></thead>';
		echo '<tbody id="aq-nav-social">';
		foreach ($social as $s) {
			echo self::social_row_html((string) ($s['network'] ?? ''), (string) ($s['url'] ?? ''), (string) ($s['target'] ?? '_blank'));
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-social-add">+ Add social icon</button></p>';
		echo '</div>';

		echo '</div>'; // twocol

		echo '<div class="aq-nav-savebar">';
		echo '<button type="button" class="aq-btn" id="aq-nav-save">Save footer</button>';
		echo '<span class="aq-nav-saving" id="aq-nav-saving" style="display:none;">Saving…</span>';
		echo '</div>';

		echo '</form>';

		self::script($rest, $nonce);
		AQ_Admin_Hub::close();
	}

	/* ---------------- render helpers ---------------- */

	private static function footer_col(string $key, string $title, string $heading, array $links): void {
		echo '<div class="aq-panel"><h2>' . esc_html($title) . '</h2>';
		echo '<label class="aq-nav-field"><span class="aq-nav-label">Column heading' . AQ_Admin_Hub::tip('The title shown above this group of footer links.') . '</span>';
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

	private static function text(string $key, string $label, string $value, string $help = ''): void {
		printf(
			'<label class="aq-nav-field"><span class="aq-nav-label">%s%s</span><input type="text" class="aq-nav-input" data-key="%s" value="%s" /></label>',
			esc_html($label),
			$help !== '' ? AQ_Admin_Hub::tip($help) : '',
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

	private static function social_network_options(string $selected): string {
		$opts = '<option value="">— choose —</option>';
		foreach (aq_social_networks() as $key => $net) {
			$opts .= '<option value="' . esc_attr($key) . '"' . selected($selected, $key, false) . '>' . esc_html($net['label']) . '</option>';
		}
		return $opts;
	}

	private static function social_target_options(string $selected): string {
		$opts = '';
		foreach (['_blank' => 'New tab', '_self' => 'Same tab'] as $val => $label) {
			$opts .= '<option value="' . esc_attr($val) . '"' . selected($selected, $val, false) . '>' . esc_html($label) . '</option>';
		}
		return $opts;
	}

	/** One footer social-icon row: network select (drives the icon) + URL + tab target. */
	private static function social_row_html(string $network, string $url, string $target = '_blank'): string {
		$preview = $network !== '' ? '<span class="aq-social-preview">' . aq_social_icon_svg($network) . '</span>' : '';
		return '<tr class="aq-nav-row">'
			. '<td class="aq-nav-idx">&bull;</td>'
			. '<td><div class="aq-social-select-wrap">' . $preview . '<select class="aq-nav-input aq-nav-network-i">' . self::social_network_options($network) . '</select></div></td>'
			. '<td><input type="text" class="aq-nav-input aq-nav-href-i" value="' . esc_attr($url) . '" placeholder="https://facebook.com/…" /></td>'
			. '<td><select class="aq-nav-input aq-nav-target-i">' . self::social_target_options($target) . '</select></td>'
			. self::order_cell()
			. '</tr>';
	}

	private static function style(): void {
		?>
		<style>
			.aq-hub .aq-nav-io .aq-nav-iobtns { display:flex; gap:10px; flex-wrap:wrap; }
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
			.aq-hub .aq-social-select-wrap { display:flex; align-items:center; gap:8px; }
			.aq-hub .aq-social-preview { display:flex; align-items:center; justify-content:center; width:22px; height:22px; flex-shrink:0; color:#c8102e; }
			.aq-hub .aq-social-select-wrap select { flex:1; min-width:0; }
			.aq-hub .aq-nav-savebar { position:sticky; bottom:0; margin-top:22px; padding:16px 0; display:flex; align-items:center; gap:14px; }
			.aq-hub .aq-nav-saving { font-size:13px; color:#5b6471; }
			.aq-hub .aq-nav-notice { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; }
			.aq-hub .aq-nav-notice--ok { background:#eaf0ea; color:#1a8f4f; border:1px solid #bfe0c8; }
			.aq-hub .aq-nav-notice--err { background:#fbe7e7; color:#a30d25; border:1px solid #e6c4c4; }
		</style>
		<?php
	}

	private static function script(string $rest, string $nonce): void {
		$blank_link   = self::link_row_html('', '');
		$blank_social = self::social_row_html('', '', '_blank');
		$social_icons = [];
		foreach (aq_social_networks() as $key => $net) {
			$social_icons[$key] = aq_social_icon_svg($key);
		}
		?>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode($rest); ?>, NONCE = <?php echo wp_json_encode($nonce); ?>;
			var BLANK_LINK = <?php echo wp_json_encode($blank_link); ?>;
			var BLANK_SOCIAL = <?php echo wp_json_encode($blank_social); ?>;
			var SOCIAL_ICONS = <?php echo wp_json_encode($social_icons); ?>;
			function $(s, c) { return (c || document).querySelector(s); }
			function $all(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

			/* ---------- Footer tables: bullet rows + up/down/del ---------- */
			function renumber(tbody) {
				$all('.aq-nav-row', tbody).forEach(function (tr, i) {
					var idx = $('.aq-nav-idx', tr); if (idx) { idx.textContent = (i + 1); }
				});
			}
			function updateSocialPreview(sel) {
				var wrap = sel.closest('.aq-social-select-wrap');
				if (!wrap) return;
				var preview = wrap.querySelector('.aq-social-preview');
				var svg = SOCIAL_ICONS[sel.value] || '';
				if (!preview) {
					preview = document.createElement('span');
					preview.className = 'aq-social-preview';
					wrap.insertBefore(preview, wrap.firstChild);
				}
				preview.innerHTML = svg;
			}
			function wireRow(tr) {
				var tbody = tr.parentNode;
				var del = $('.aq-nav-del', tr), up = $('.aq-nav-up', tr), down = $('.aq-nav-down', tr);
				if (del) del.addEventListener('click', function () { tbody.removeChild(tr); renumber(tbody); });
				if (up) up.addEventListener('click', function () { var p = tr.previousElementSibling; if (p) { tbody.insertBefore(tr, p); renumber(tbody); } });
				if (down) down.addEventListener('click', function () { var n = tr.nextElementSibling; if (n) { tbody.insertBefore(n, tr); renumber(tbody); } });
				var networkSel = $('.aq-nav-network-i', tr);
				if (networkSel) networkSel.addEventListener('change', function () { updateSocialPreview(networkSel); });
			}
			function addRow(tbody, html, focusFirst) {
				var tmp = document.createElement('tbody');
				tmp.innerHTML = html.trim();
				var tr = tmp.querySelector('tr');
				tbody.appendChild(tr);
				wireRow(tr);
				renumber(tbody);
				if (focusFirst) { var f = $('.aq-nav-label-i', tr) || $('.aq-nav-network-i', tr); if (f) f.focus(); }
			}
			$all('#aq-nav-form tbody').forEach(function (tb) { $all('.aq-nav-row', tb).forEach(wireRow); renumber(tb); });
			$all('.aq-nav-addlink').forEach(function (btn) {
				btn.addEventListener('click', function () { var tb = document.getElementById(btn.getAttribute('data-tbody')); if (tb) addRow(tb, BLANK_LINK, true); });
			});
			var addSocial = $('#aq-nav-social-add');
			if (addSocial) addSocial.addEventListener('click', function () { addRow($('#aq-nav-social'), BLANK_SOCIAL, true); });

			/* ---------- Collect + save ---------- */
			function rowsFrom(id) {
				return $all('.aq-nav-row', document.getElementById(id)).map(function (tr) {
					return { label: (($('.aq-nav-label-i', tr) || {}).value || '').trim(), href: (($('.aq-nav-href-i', tr) || {}).value || '').trim() };
				}).filter(function (r) { return r.label !== ''; });
			}
			function socialRowsFrom(id) {
				return $all('.aq-nav-row', document.getElementById(id)).map(function (tr) {
					return {
						network: (($('.aq-nav-network-i', tr) || {}).value || ''),
						url: (($('.aq-nav-href-i', tr) || {}).value || '').trim(),
						target: (($('.aq-nav-target-i', tr) || {}).value || '_blank')
					};
				}).filter(function (r) { return r.network !== '' && r.url !== ''; });
			}

			function setDeep(obj, dotted, v) {
				var parts = dotted.split('.'), node = obj;
				for (var i = 0; i < parts.length - 1; i++) { if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) node[parts[i]] = {}; node = node[parts[i]]; }
				node[parts[parts.length - 1]] = v;
			}

			function collect() {
				var p = { footer: { company: { links: rowsFrom('aq-nav-company') }, inspections: { links: rowsFrom('aq-nav-inspections') }, legal: rowsFrom('aq-nav-legal'), social: socialRowsFrom('aq-nav-social') } };
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
							notice('Saved. The footer is live.', true);
						} else { notice('Save failed: ' + ((res.body && (res.body.message || res.body.code)) || 'unknown error'), false); }
					})
					.catch(function (e) { notice('Save failed: ' + e.message, false); })
					.then(function () { saveBtn.disabled = false; if (saving) saving.style.display = 'none'; });
			});

			/* ---------- Export / Import to a JSON file ---------- */
			function download(filename, text) {
				var blob = new Blob([text], { type: 'application/json' });
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url; a.download = filename;
				document.body.appendChild(a); a.click(); document.body.removeChild(a);
				setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
			}

			var exportBtn = $('#aq-nav-export');
			if (exportBtn) exportBtn.addEventListener('click', function () {
				var data = collect();
				var payload = {
					_format: 'autoforge-footer',
					_version: 1,
					_exported: new Date().toISOString(),
					_site: location.hostname || '',
					footer: data.footer || {},
					footerCta: data.footerCta || {},
					stickyBar: data.stickyBar || {}
				};
				var host = (location.hostname || 'site').replace(/[^a-z0-9.\-]/gi, '');
				var stamp = new Date().toISOString().slice(0, 10);
				download('footer-' + host + '-' + stamp + '.json', JSON.stringify(payload, null, 2));
				notice('Exported the current footer config to a JSON file.', true);
			});

			var importBtn = $('#aq-nav-import-btn'), importFile = $('#aq-nav-import-file');
			if (importBtn && importFile) {
				importBtn.addEventListener('click', function () { importFile.value = ''; importFile.click(); });
				importFile.addEventListener('change', function () {
					var file = importFile.files && importFile.files[0];
					if (!file) return;
					var reader = new FileReader();
					reader.onload = function () {
						var data;
						try { data = JSON.parse(String(reader.result)); }
						catch (e) { notice('Import failed: that file is not valid JSON.', false); return; }
						if (!data || typeof data !== 'object') { notice('Import failed: unexpected file contents.', false); return; }
						var payload = {};
						if (data.footer && typeof data.footer === 'object') payload.footer = data.footer;
						if (data.footerCta && typeof data.footerCta === 'object') payload.footerCta = data.footerCta;
						if (data.stickyBar && typeof data.stickyBar === 'object') payload.stickyBar = data.stickyBar;
						if (!payload.footer && !payload.footerCta && !payload.stickyBar) { notice('Import failed: no footer data found in that file.', false); return; }
						if (!window.confirm('Import will replace your footer links, social icons, and CTA. Continue?')) return;
						if (saving) saving.style.display = 'inline';
						fetch(REST, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify(payload) })
							.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
							.then(function (res) {
								if (res.ok && res.body && res.body.ok) {
									notice('Imported. Reloading to show the new footer…', true);
									setTimeout(function () { location.reload(); }, 700);
								} else {
									notice('Import failed: ' + ((res.body && (res.body.message || res.body.code)) || 'unknown error'), false);
									if (saving) saving.style.display = 'none';
								}
							})
							.catch(function (e) { notice('Import failed: ' + e.message, false); if (saving) saving.style.display = 'none'; });
					};
					reader.readAsText(file);
				});
			}
		})();
		</script>
		<?php
	}
}
