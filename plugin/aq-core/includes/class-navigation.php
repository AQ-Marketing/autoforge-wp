<?php
/**
 * AutoForge — Navigation editor (tab: aq-navigation).
 *
 * Edits the header primary menu and the footer link columns + social, all of
 * which live in the `aq_site_config` overlay (config/site.php defaults). Writes
 * go through AQ_Site_Config::update() so they ride on top of the file defaults
 * and feed aq_site() in parts/site-header.php + parts/site-footer.php.
 *
 * Header menu = a drag-to-reorder list of items. An item can be a plain link, a
 * dropdown filled with sub-links the editor adds (rendered as a rich panel with
 * an optional promo box), or a dropdown auto-filled from one of the three fixed
 * sources (Services / Specialty / Service areas). No "type" jargon: each item
 * just has a friendly "Dropdown" choice.
 *
 * Data shape per nav item:
 *   plain  → { label, href }
 *   auto   → { label, href, panel:'services'|'specialty'|'areas', id:'nav-…' }
 *   manual → { label, href, children:[{label,href,tagline?}], promo?, linkLabel? }
 *
 * REST: POST aq/v1/site-nav → validate + save. Gated on manage_options + the WP
 * REST nonce. Vanilla JS, no build step. SQLite-safe (option get/update only).
 *
 * Export/Import: the editor can download the current nav + footer as a JSON file
 * (for editing or hand-off) and import one back. Import POSTs to the same
 * /site-nav route, so the identical sanitization runs; both are client-side with
 * no extra endpoint. Because nav/footer link lists are sequential arrays,
 * AQ_Site_Config::update() replaces them wholesale, so an import cleanly drops
 * rows the file omits.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Navigation {

	const CAP = 'manage_options';

	/** Auto-fill sources a dropdown may point at (fixed in the header template). */
	private static function panels(): array {
		return ['services', 'specialty', 'areas'];
	}

	/** Friendly "Dropdown" choices shown in the editor (value => label). */
	private static function dropdown_options(): array {
		return [
			''          => 'No dropdown (link only)',
			'manual'    => 'Sub-links I add',
			'services'  => 'My Services',
			'specialty' => 'My Specialty',
			'areas'     => 'My Service areas',
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

	/** Sanitize a manual dropdown's sub-links {label, href, tagline?}; drop empties. */
	private static function children($raw): array {
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
				$kid = ['label' => $label, 'href' => self::url((string) ($row['href'] ?? '#'))];
				$tag = sanitize_text_field((string) ($row['tagline'] ?? ''));
				if ($tag !== '') {
					$kid['tagline'] = $tag;
				}
				$icon = self::svg_icon((string) ($row['icon'] ?? ''));
				if ($icon !== '') {
					$kid['icon'] = $icon;
				}
				$out[] = $kid;
			}
		}
		return array_values($out);
	}

	/** Sanitize a manual dropdown's optional promo card; [] when entirely blank. */
	private static function promo($raw): array {
		if (!is_array($raw)) {
			return [];
		}
		$p = [
			'eyebrow'   => sanitize_text_field((string) ($raw['eyebrow'] ?? '')),
			'text'      => sanitize_textarea_field((string) ($raw['text'] ?? '')),
			'ctaLabel'  => sanitize_text_field((string) ($raw['ctaLabel'] ?? '')),
			'ctaHref'   => self::url((string) ($raw['ctaHref'] ?? '#')),
			'cta2Label' => sanitize_text_field((string) ($raw['cta2Label'] ?? '')),
			'cta2Href'  => self::url((string) ($raw['cta2Href'] ?? '#')),
		];
		// Drop the href defaults so an all-blank promo collapses to [] (hidden).
		$hasText = $p['eyebrow'] !== '' || $p['text'] !== '' || $p['ctaLabel'] !== '' || $p['cta2Label'] !== '';
		if (!$hasText) {
			return [];
		}
		if ($p['ctaLabel'] === '') {
			unset($p['ctaLabel'], $p['ctaHref']);
		}
		if ($p['cta2Label'] === '') {
			unset($p['cta2Label'], $p['cta2Href']);
		}
		return $p;
	}

	/** Whitelist + sanitize the incoming nav/footer payload. */
	private static function sanitize(array $in): array {
		$patch  = [];
		$panels = self::panels();

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
				$item   = ['href' => self::url((string) ($row['href'] ?? '#')), 'label' => $label];
				$panel  = (string) ($row['panel'] ?? '');
				$hideVA = !empty($row['hideViewAll']); // default: show the "View all" link
				if ($panel !== '' && in_array($panel, $panels, true)) {
					// Auto-filled dropdown (services/specialty/areas).
					$item['panel'] = $panel;
					$item['id']    = 'nav-' . $panel;
					if ($hideVA) {
						$item['hideViewAll'] = true;
					}
				} else {
					// Manual dropdown: sub-links the editor added, plus panel design
					// (columns, icon style, heading) + optional promo.
					$kids = self::children($row['children'] ?? []);
					if ($kids) {
						$item['children'] = $kids;
						$cols          = (int) ($row['cols'] ?? 2);
						$item['cols']  = max(1, min(4, $cols));
						$style         = (string) ($row['iconStyle'] ?? 'outline');
						$item['iconStyle'] = in_array($style, ['outline', 'filled'], true) ? $style : 'outline';
						$heading = sanitize_text_field((string) ($row['heading'] ?? ''));
						if ($heading !== '') {
							$item['heading'] = $heading;
						}
						$promo = self::promo($row['promo'] ?? []);
						if ($promo) {
							$item['promo'] = $promo;
						}
						$ll = sanitize_text_field((string) ($row['linkLabel'] ?? ''));
						if ($ll !== '') {
							$item['linkLabel'] = $ll;
						}
						if ($hideVA) {
							$item['hideViewAll'] = true;
						}
					}
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

		// Mega-menu panel contents (Services/Specialty items + Areas), so a file
		// import can carry the dropdown panels — icons and all — not just the
		// top-level menu. These feed the auto panels in parts/site-header.php.
		if (isset($in['megamenu']) && is_array($in['megamenu'])) {
			$patch['megamenu'] = self::megamenu($in['megamenu']);
		}
		if (isset($in['towns']) && is_array($in['towns'])) {
			$patch['towns'] = self::towns($in['towns']);
		}

		return $patch;
	}

	/** Sanitize the Services/Specialty/Areas mega-menu panel config. */
	private static function megamenu($raw): array {
		$out = [];
		foreach (['services', 'specialty', 'areas'] as $key) {
			if (!isset($raw[$key]) || !is_array($raw[$key])) {
				continue;
			}
			$p     = $raw[$key];
			$panel = [
				'base'        => self::url((string) ($p['base'] ?? '')),
				'heading'     => sanitize_text_field((string) ($p['heading'] ?? '')),
				'viewAllHref' => self::url((string) ($p['viewAllHref'] ?? '')),
				'promo'       => self::panel_promo($p['promo'] ?? []),
			];
			// Services/Specialty carry an item list; Areas is filled from towns.
			if ($key !== 'areas') {
				$items = [];
				if (is_array($p['items'] ?? null)) {
					foreach ($p['items'] as $it) {
						if (!is_array($it)) {
							continue;
						}
						$slug  = sanitize_title((string) ($it['slug'] ?? ''));
						$label = sanitize_text_field((string) ($it['label'] ?? ''));
						if ($slug === '' && $label === '') {
							continue;
						}
						$item = ['slug' => $slug, 'label' => $label];
						$tag  = sanitize_text_field((string) ($it['tagline'] ?? ''));
						if ($tag !== '') {
							$item['tagline'] = $tag;
						}
						$icon = self::svg_icon((string) ($it['icon'] ?? ''));
						if ($icon !== '') {
							$item['icon'] = $icon;
						}
						$items[] = $item;
					}
				}
				$panel['items'] = array_values($items);
			}
			$out[$key] = $panel;
		}
		return $out;
	}

	/** Mega-menu promo card: eyebrow + text only (the buttons are template-fixed). */
	private static function panel_promo($raw): array {
		if (!is_array($raw)) {
			return [];
		}
		$p = [
			'eyebrow' => sanitize_text_field((string) ($raw['eyebrow'] ?? '')),
			'text'    => sanitize_textarea_field((string) ($raw['text'] ?? '')),
		];
		if ($p['eyebrow'] === '' && $p['text'] === '') {
			return [];
		}
		return $p;
	}

	/**
	 * Allow ONLY safe inline-SVG shape elements for a menu icon. The icon string
	 * is echoed raw inside an <svg> wrapper in the template, so this wp_kses pass
	 * is the security boundary — it strips <script>, event handlers, and anything
	 * that isn't a geometric shape.
	 */
	private static function svg_icon(string $svg): string {
		$svg = trim($svg);
		if ($svg === '') {
			return '';
		}
		$allowed = [
			'path'     => ['d' => true, 'fill-rule' => true, 'clip-rule' => true, 'transform' => true],
			'circle'   => ['cx' => true, 'cy' => true, 'r' => true],
			'ellipse'  => ['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true],
			'rect'     => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true],
			'line'     => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true],
			'polyline' => ['points' => true],
			'polygon'  => ['points' => true],
			'g'        => ['transform' => true],
		];
		return trim(wp_kses($svg, $allowed));
	}

	/** Sanitize the Areas town list: {slug, name, county}. */
	private static function towns($raw): array {
		$out = [];
		if (is_array($raw)) {
			foreach ($raw as $t) {
				if (!is_array($t)) {
					continue;
				}
				$slug = sanitize_title((string) ($t['slug'] ?? ''));
				$name = sanitize_text_field((string) ($t['name'] ?? ''));
				if ($slug === '' && $name === '') {
					continue;
				}
				$row    = ['slug' => $slug, 'name' => $name];
				$county = sanitize_text_field((string) ($t['county'] ?? ''));
				if ($county !== '') {
					$row['county'] = $county;
				}
				$out[] = $row;
			}
		}
		return array_values($out);
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

		/* ---------------- Export / Import ---------------- */
		echo '<div class="aq-panel aq-nav-io">';
		echo '<h2>Export / Import</h2>';
		echo '<p class="aq-nav-help">Download the menu and footer below as a JSON file you can edit or hand off, then import that file here to apply it. <strong>Importing replaces</strong> the header menu and footer links currently shown &mdash; it takes effect as soon as you confirm.</p>';
		echo '<div class="aq-nav-iobtns">';
		echo '<button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-export">Export to file</button>';
		echo '<button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-import-btn">Import from file&hellip;</button>';
		echo '<input type="file" id="aq-nav-import-file" accept="application/json,.json" hidden />';
		echo '</div>';
		echo '</div>';

		/* ---------------- Header menu ---------------- */
		echo '<div class="aq-panel"><h2>Header menu</h2>';
		echo '<p class="aq-nav-help">Drag the <span class="aq-grip-hint">&#x2807;</span> handle to reorder. To turn an item into a dropdown, set its <strong>Dropdown</strong> to &ldquo;Sub-links I add&rdquo; and add links beneath it &mdash; or point it at your Services, Specialty, or Service-area lists to fill the dropdown automatically.</p>';
		echo '<div id="aq-nav-items" class="aq-nav-items">';
		foreach ($nav as $item) {
			echo self::item_card_html(self::editor_item(is_array($item) ? $item : [], $cfg));
		}
		echo '</div>';
		echo '<p style="margin-top:14px;"><button type="button" class="aq-btn aq-btn--ghost" id="aq-nav-add">+ Add menu item</button></p>';
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
		self::text('footer.social.facebook', 'Facebook URL', (string) ($social['facebook'] ?? '#'));
		self::text('footer.social.instagram', 'Instagram URL', (string) ($social['instagram'] ?? '#'));
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

	/** Wrap inner SVG markup in the site's 24×24 stroke <svg> (for previews). */
	private static function svg_wrap(string $inner): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
	}

	/** One sub-link row inside a manual dropdown (icon picker + label/href/tagline). */
	private static function child_row_html(string $label = '', string $href = '', string $tagline = '', string $icon = ''): string {
		$preview = $icon !== '' ? self::svg_wrap($icon) : '+';
		return '<div class="aq-nav-child">'
			. '<button type="button" class="aq-grip aq-cgrip" title="Drag to reorder" aria-label="Drag to reorder" tabindex="-1">&#x2807;</button>'
			. '<button type="button" class="aq-c-iconbtn" title="Choose icon" aria-label="Choose icon">' . $preview . '</button>'
			. '<input type="hidden" class="aq-c-icon" value="' . esc_attr($icon) . '" />'
			. '<input type="text" class="aq-nav-input aq-c-label" value="' . esc_attr($label) . '" placeholder="Sub-link text" />'
			. '<input type="text" class="aq-nav-input aq-c-href" value="' . esc_attr($href) . '" placeholder="/path/" />'
			. '<input type="text" class="aq-nav-input aq-c-tag" value="' . esc_attr($tagline) . '" placeholder="Tagline (optional)" />'
			. '<button type="button" class="aq-iconbtn aq-iconbtn--del aq-cdel" title="Remove sub-link">&times;</button>'
			. '</div>';
	}

	/**
	 * For the editor only: expand an auto panel (services/specialty/areas) into an
	 * editable manual dropdown, seeded from the saved mega-menu config + towns, so
	 * every panel is fully editable (drag-drop, icons, columns). If there is nothing
	 * to seed yet, the auto item is returned unchanged so it isn't lost.
	 */
	private static function editor_item(array $item, array $cfg): array {
		$panel = (string) ($item['panel'] ?? '');
		if ($panel === '' || !in_array($panel, self::panels(), true)) {
			return $item;
		}
		$mm       = is_array($cfg['megamenu'][$panel] ?? null) ? $cfg['megamenu'][$panel] : [];
		$base     = (string) ($mm['base'] ?? '/');
		$children = [];
		if ($panel === 'areas') {
			$region = (string) ($cfg['address']['region'] ?? '');
			$pin    = '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>';
			foreach ((array) ($cfg['towns'] ?? []) as $t) {
				if (!is_array($t)) {
					continue;
				}
				$children[] = [
					'label'   => (string) ($t['name'] ?? '') . ($region !== '' ? ', ' . $region : ''),
					'href'    => $base . (string) ($t['slug'] ?? '') . '/',
					'tagline' => (($t['county'] ?? '') !== '') ? (string) $t['county'] . ' County' : '',
					'icon'    => $pin,
				];
			}
			$cols = 3;
		} else {
			foreach ((array) ($mm['items'] ?? []) as $s) {
				if (!is_array($s)) {
					continue;
				}
				$children[] = [
					'label'   => (string) ($s['label'] ?? ''),
					'href'    => $base . (string) ($s['slug'] ?? '') . '/',
					'tagline' => (string) ($s['tagline'] ?? ''),
					'icon'    => (string) ($s['icon'] ?? ''),
				];
			}
			$cols = 2;
		}
		if (!$children) {
			return $item; // nothing seeded yet — keep it auto so it isn't dropped
		}
		$out = [
			'label'     => (string) ($item['label'] ?? ''),
			'href'      => (string) ($item['href'] ?? $base),
			'children'  => $children,
			'cols'      => $cols,
			'iconStyle' => 'outline',
			'heading'   => (string) ($mm['heading'] ?? ''),
			'linkLabel' => 'View all',
			'promo'     => is_array($mm['promo'] ?? null) ? $mm['promo'] : [],
		];
		if (!empty($item['hideViewAll'])) {
			$out['hideViewAll'] = true;
		}
		return $out;
	}

	/** One top-level menu item card (header row + collapsible children/promo). */
	private static function item_card_html(array $item): string {
		$label  = (string) ($item['label'] ?? '');
		$href   = (string) ($item['href'] ?? '');
		$showVA = empty($item['hideViewAll']); // toggle defaults ON (show "View all")

		// Decide the friendly "Dropdown" mode this item is currently in.
		$panel = (string) ($item['panel'] ?? '');
		$kids  = is_array($item['children'] ?? null) ? array_values($item['children']) : [];
		if ($panel !== '' && in_array($panel, self::panels(), true)) {
			$mode = $panel;
		} elseif ($kids) {
			$mode = 'manual';
		} else {
			$mode = '';
		}

		$opts = '';
		foreach (self::dropdown_options() as $val => $name) {
			$opts .= '<option value="' . esc_attr($val) . '"' . selected($mode, $val, false) . '>' . esc_html($name) . '</option>';
		}

		$promo  = is_array($item['promo'] ?? null) ? $item['promo'] : [];
		$ll     = (string) ($item['linkLabel'] ?? '');
		$hasPro = (($promo['eyebrow'] ?? '') !== '') || (($promo['text'] ?? '') !== '') || (($promo['ctaLabel'] ?? '') !== '') || (($promo['cta2Label'] ?? '') !== '');

		// Panel design controls (columns / icon style / heading).
		$cols    = max(1, min(4, (int) ($item['cols'] ?? 2)));
		$istyle  = (($item['iconStyle'] ?? 'outline') === 'filled') ? 'filled' : 'outline';
		$heading = (string) ($item['heading'] ?? '');
		$cols_opts = '';
		foreach ([1, 2, 3, 4] as $n) {
			$cols_opts .= '<option value="' . $n . '"' . selected($cols, $n, false) . '>' . $n . '</option>';
		}
		$style_opts = '<option value="outline"' . selected($istyle, 'outline', false) . '>Outline</option>'
			. '<option value="filled"' . selected($istyle, 'filled', false) . '>Filled</option>';

		$children_html = '';
		foreach ($kids as $c) {
			if (!is_array($c)) {
				continue;
			}
			$children_html .= self::child_row_html((string) ($c['label'] ?? ''), (string) ($c['href'] ?? ''), (string) ($c['tagline'] ?? ''), (string) ($c['icon'] ?? ''));
		}

		ob_start();
		?>
		<div class="aq-nav-item" data-mode="<?php echo esc_attr($mode); ?>">
			<div class="aq-nav-itemhead">
				<button type="button" class="aq-grip" title="Drag to reorder" aria-label="Drag to reorder" tabindex="-1">&#x2807;</button>
				<span class="aq-nav-num"></span>
				<div class="aq-nav-itemfields">
					<input type="text" class="aq-nav-input aq-i-label" value="<?php echo esc_attr($label); ?>" placeholder="Menu label" />
					<input type="text" class="aq-nav-input aq-i-href" value="<?php echo esc_attr($href); ?>" placeholder="/path/ or https://" />
					<label class="aq-i-modewrap"><span class="aq-i-modelabel">Dropdown</span>
						<select class="aq-nav-input aq-i-mode"><?php echo $opts; ?></select>
					</label>
				</div>
				<div class="aq-nav-itemactions">
					<button type="button" class="aq-iconbtn aq-item-up" title="Move up">&uarr;</button>
					<button type="button" class="aq-iconbtn aq-item-down" title="Move down">&darr;</button>
					<button type="button" class="aq-iconbtn aq-iconbtn--del aq-item-del" title="Remove item">&times;</button>
				</div>
			</div>
			<div class="aq-nav-itemopts"<?php echo $mode === '' ? ' hidden' : ''; ?>>
				<label class="aq-toggle"><input type="checkbox" class="aq-i-viewall"<?php echo $showVA ? ' checked' : ''; ?> /> <span>Show the &ldquo;View all&rdquo; link at the top of this dropdown</span></label>
			</div>
			<div class="aq-nav-children"<?php echo $mode === 'manual' ? '' : ' hidden'; ?>>
				<div class="aq-nav-paneldesign">
					<label class="aq-nav-field aq-pd-sm"><span class="aq-nav-label">Columns</span><select class="aq-nav-input aq-i-cols"><?php echo $cols_opts; ?></select></label>
					<label class="aq-nav-field aq-pd-sm"><span class="aq-nav-label">Icon style</span><select class="aq-nav-input aq-i-iconstyle"><?php echo $style_opts; ?></select></label>
					<label class="aq-nav-field"><span class="aq-nav-label">Panel heading (optional)</span><input type="text" class="aq-nav-input aq-i-heading" value="<?php echo esc_attr($heading); ?>" placeholder="Defaults to the menu label" /></label>
				</div>
				<p class="aq-children-help">Sub-links shown in this dropdown. Drag the <span class="aq-grip-hint">&#x2807;</span> handle to reorder; click the icon tile to choose an icon.</p>
				<div class="aq-nav-childlist"><?php echo $children_html; ?></div>
				<label class="aq-nav-field aq-linklabel-field"><span class="aq-nav-label">&ldquo;View all&rdquo; link text (top-right of the dropdown)</span><input type="text" class="aq-nav-input aq-i-linklabel" value="<?php echo esc_attr($ll); ?>" placeholder="View all" /></label>
				<button type="button" class="aq-btn aq-btn--ghost aq-addchild">+ Add sub-link</button>
				<details class="aq-promo"<?php echo $hasPro ? ' open' : ''; ?>>
					<summary>Promo box (optional)</summary>
					<div class="aq-promo-grid">
						<label class="aq-nav-field"><span class="aq-nav-label">Eyebrow</span><input type="text" class="aq-nav-input aq-p-eyebrow" value="<?php echo esc_attr((string) ($promo['eyebrow'] ?? '')); ?>" placeholder="e.g. Ready to schedule?" /></label>
						<label class="aq-nav-field aq-promo-text"><span class="aq-nav-label">Text</span><textarea class="aq-nav-input aq-p-text" rows="2" placeholder="A sentence of supporting copy."><?php echo esc_textarea((string) ($promo['text'] ?? '')); ?></textarea></label>
						<label class="aq-nav-field"><span class="aq-nav-label">Button label</span><input type="text" class="aq-nav-input aq-p-ctaLabel" value="<?php echo esc_attr((string) ($promo['ctaLabel'] ?? '')); ?>" placeholder="e.g. Read Reviews" /></label>
						<label class="aq-nav-field"><span class="aq-nav-label">Button link</span><input type="text" class="aq-nav-input aq-p-ctaHref" value="<?php echo esc_attr((string) ($promo['ctaHref'] ?? '')); ?>" placeholder="/reviews/" /></label>
						<label class="aq-nav-field"><span class="aq-nav-label">2nd link label</span><input type="text" class="aq-nav-input aq-p-cta2Label" value="<?php echo esc_attr((string) ($promo['cta2Label'] ?? '')); ?>" placeholder="e.g. Visit the blog →" /></label>
						<label class="aq-nav-field"><span class="aq-nav-label">2nd link URL</span><input type="text" class="aq-nav-input aq-p-cta2Href" value="<?php echo esc_attr((string) ($promo['cta2Href'] ?? '')); ?>" placeholder="/blog/" /></label>
					</div>
				</details>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function style(): void {
		?>
		<style>
			.aq-hub .aq-nav-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
			.aq-hub .aq-nav-field { display:flex; flex-direction:column; gap:5px; }
			.aq-hub .aq-nav-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; }
			.aq-hub .aq-nav-input { width:100%; padding:8px 10px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; background:#fff; }
			.aq-hub textarea.aq-nav-input { resize:vertical; min-height:38px; font-family:inherit; }
			.aq-hub .aq-nav-input:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 3px rgba(200,16,46,.18); }
			.aq-hub .aq-nav-help { font-size:12px; color:#5b6471; margin:0 0 16px; }
			.aq-hub .aq-nav-help code { background:#eef1f5; padding:1px 5px; border-radius:4px; font-size:11px; }
			.aq-hub .aq-grip-hint { color:#8a94a1; }
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
			.aq-hub .aq-nav-io .aq-nav-help { margin-bottom:12px; }
			.aq-hub .aq-nav-iobtns { display:flex; gap:10px; flex-wrap:wrap; }

			/* ---- Header menu items (drag-to-reorder parent/child) ---- */
			.aq-hub .aq-nav-items { display:flex; flex-direction:column; gap:10px; }
			.aq-hub .aq-nav-item { border:1px solid #d7dce3; border-radius:10px; background:#fbfcfe; }
			.aq-hub .aq-nav-item.aq-dragging { opacity:.55; box-shadow:0 8px 22px rgba(13,16,20,.14); }
			.aq-hub .aq-nav-item.aq-dragover { border-color:#c8102e; }
			.aq-hub .aq-nav-itemhead { display:flex; align-items:center; gap:10px; padding:10px 12px; }
			.aq-hub .aq-grip { cursor:grab; background:transparent; border:0; color:#9aa3af; font-size:16px; line-height:1; padding:2px 4px; border-radius:6px; flex:0 0 auto; }
			.aq-hub .aq-grip:hover { color:#5b6471; background:#eef1f5; }
			.aq-hub .aq-grip:active { cursor:grabbing; }
			.aq-hub .aq-nav-num { width:20px; text-align:center; color:#8a94a1; font-weight:700; font-size:12px; flex:0 0 auto; }
			.aq-hub .aq-nav-itemfields { display:grid; grid-template-columns:1.3fr 1.6fr minmax(170px,0.9fr); gap:10px; flex:1 1 auto; align-items:center; }
			@media (max-width:900px){ .aq-hub .aq-nav-itemfields { grid-template-columns:1fr; } }
			.aq-hub .aq-i-modewrap { display:flex; align-items:center; gap:6px; }
			.aq-hub .aq-i-modelabel { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; white-space:nowrap; }
			.aq-hub .aq-nav-itemactions { display:flex; gap:6px; flex:0 0 auto; }
			.aq-hub .aq-nav-children { border-top:1px dashed #d7dce3; margin:0 12px; padding:12px 0 14px; }
			.aq-hub .aq-children-help { font-size:11px; color:#8a94a1; margin:0 0 8px; }
			.aq-hub .aq-nav-childlist { display:flex; flex-direction:column; gap:8px; margin-bottom:10px; }
			.aq-hub .aq-linklabel-field { max-width:440px; margin:10px 0 4px; }
			.aq-hub .aq-nav-child { display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding-left:14px; }
			.aq-hub .aq-nav-child.aq-dragging { opacity:.55; }
			/* flex-basis (not 0) so each box keeps a usable width and a long
			   tagline pushes the row to wrap instead of squeezing the inputs. */
			.aq-hub .aq-nav-child .aq-c-label { flex:1 1 140px; min-width:120px; }
			.aq-hub .aq-nav-child .aq-c-href { flex:1 1 160px; min-width:120px; }
			.aq-hub .aq-nav-child .aq-c-tag { flex:2 1 240px; min-width:160px; }
			.aq-hub .aq-nav-itemopts { padding:0 12px 12px 46px; }
			.aq-hub .aq-toggle { display:inline-flex; align-items:center; gap:8px; font-size:12px; color:#5b6471; cursor:pointer; }
			.aq-hub .aq-toggle input { width:16px; height:16px; margin:0; accent-color:#c8102e; flex:0 0 auto; }
			/* Panel design controls + per-link icon picker */
			.aq-hub .aq-nav-paneldesign { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin:2px 0 14px; }
			.aq-hub .aq-nav-paneldesign .aq-pd-sm { flex:0 0 120px; }
			.aq-hub .aq-nav-paneldesign .aq-nav-field { flex:1 1 200px; }
			.aq-hub .aq-c-iconbtn { flex:0 0 auto; width:34px; height:34px; border:1px solid #c9cfd6; border-radius:8px; background:#fff; color:#5b6471; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:18px; line-height:1; padding:0; }
			.aq-hub .aq-c-iconbtn:hover { border-color:#c8102e; color:#0d1014; }
			.aq-hub .aq-c-iconbtn svg { width:20px; height:20px; }
			.aq-iconpop { position:absolute; z-index:100000; width:300px; max-width:92vw; background:#fff; border:1px solid #c9cfd6; border-radius:10px; box-shadow:0 14px 40px rgba(13,16,20,.18); padding:12px; }
			.aq-iconpop-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:6px; margin-bottom:10px; }
			.aq-iconpop-sw { aspect-ratio:1; border:1px solid #e2e8f0; border-radius:8px; background:#fff; color:#334155; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; }
			.aq-iconpop-sw:hover { border-color:#c8102e; color:#c8102e; background:#fff5f6; }
			.aq-iconpop-sw svg { width:20px; height:20px; }
			.aq-iconpop-none { font-size:16px; color:#8a94a1; }
			.aq-iconpop-svg { width:100%; box-sizing:border-box; border:1px solid #c9cfd6; border-radius:8px; padding:6px 8px; font-family:monospace; font-size:11px; resize:vertical; }
			.aq-iconpop-apply { margin-top:8px; }
			.aq-hub .aq-promo { margin-top:10px; }
			.aq-hub .aq-promo > summary { cursor:pointer; font-size:12px; font-weight:600; color:#5b6471; padding:4px 0; }
			.aq-hub .aq-promo-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px; }
			.aq-hub .aq-promo-grid .aq-promo-text { grid-column:1 / -1; }
			@media (max-width:760px){ .aq-hub .aq-promo-grid { grid-template-columns:1fr; } }
		</style>
		<?php
	}

	private static function script(string $rest, string $nonce): void {
		$blank_link  = self::link_row_html('', '');
		$blank_item  = self::item_card_html([]);
		$blank_child = self::child_row_html();
		// Saved mega-menu config rides along in Export (so a round-trip never drops
		// it) and Import can carry an edited set. The curated icon set (inner SVG,
		// <svg> wrapper stripped) feeds the per-link icon picker.
		$cfg    = class_exists('AQ_Site_Config') ? AQ_Site_Config::get() : (function_exists('aq_site') ? (array) aq_site() : []);
		$panels = [
			'megamenu' => is_array($cfg['megamenu'] ?? null) ? $cfg['megamenu'] : [],
			'towns'    => is_array($cfg['towns'] ?? null) ? array_values($cfg['towns']) : [],
		];
		$icon_set = [];
		if (class_exists('AQ_Editor') && method_exists('AQ_Editor', 'icon_library')) {
			foreach (AQ_Editor::icon_library() as $name => $svg) {
				$icon_set[(string) $name] = trim((string) preg_replace('#</?svg[^>]*>#i', '', (string) $svg));
			}
		}
		?>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode($rest); ?>, NONCE = <?php echo wp_json_encode($nonce); ?>;
			var PANELS = <?php echo wp_json_encode($panels); ?>;
			var ICONS = <?php echo wp_json_encode($icon_set); ?>;
			var BLANK_LINK = <?php echo wp_json_encode($blank_link); ?>;
			var BLANK_ITEM = <?php echo wp_json_encode($blank_item); ?>;
			var BLANK_CHILD = <?php echo wp_json_encode($blank_child); ?>;
			function $(s, c) { return (c || document).querySelector(s); }
			function $all(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
			function val(s, c) { var el = $(s, c); return el ? (el.value || '').trim() : ''; }

			/* ---------- Footer tables (unchanged: bullet rows + up/down/del) ---------- */
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
			$all('.aq-nav-addlink').forEach(function (btn) {
				btn.addEventListener('click', function () { var tb = document.getElementById(btn.getAttribute('data-tbody')); if (tb) addRow(tb, BLANK_LINK, true); });
			});

			/* ---------- Header menu: drag-to-reorder parent/child ---------- */
			var itemsRoot = $('#aq-nav-items');

			function renumberItems() {
				$all('.aq-nav-item', itemsRoot).forEach(function (card, i) {
					var n = $('.aq-nav-num', card); if (n) n.textContent = (i + 1);
				});
			}

			// Grip-gated HTML5 drag-reorder, scoped to one list (rows never leave it).
			function makeSortable(list, rowSel, gripSel, onDrop) {
				if (!list || list.__sortable) return; list.__sortable = true;
				var drag = null;
				list.addEventListener('mousedown', function (e) {
					var g = e.target.closest(gripSel);
					if (!g || !list.contains(g)) return;
					var row = g.closest(rowSel);
					if (row && row.parentNode === list) row.setAttribute('draggable', 'true');
				});
				list.addEventListener('dragstart', function (e) {
					var row = e.target.closest(rowSel);
					if (!row || row.parentNode !== list || row.getAttribute('draggable') !== 'true') { e.preventDefault(); return; }
					drag = row; row.classList.add('aq-dragging');
					if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', ''); } catch (_) {} }
				});
				list.addEventListener('dragover', function (e) {
					if (!drag) return; e.preventDefault();
					var over = e.target.closest(rowSel);
					if (!over || over === drag || over.parentNode !== list) return;
					var r = over.getBoundingClientRect();
					var after = (e.clientY - r.top) > r.height / 2;
					list.insertBefore(drag, after ? over.nextSibling : over);
				});
				list.addEventListener('drop', function (e) { if (drag) e.preventDefault(); });
				list.addEventListener('dragend', function () {
					if (!drag) return;
					drag.classList.remove('aq-dragging'); drag.removeAttribute('draggable'); drag = null;
					if (onDrop) onDrop();
				});
				// Clear a stray draggable flag from a grip click that never dragged.
				document.addEventListener('mouseup', function () {
					$all(rowSel + '[draggable]', list).forEach(function (r) { if (!r.classList.contains('aq-dragging')) r.removeAttribute('draggable'); });
				});
			}

			/* ---------- Icon picker (curated set + custom SVG) ---------- */
			function svgWrap(inner) {
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>';
			}
			var iconPop = null, iconTarget = null;
			function buildIconPop() {
				iconPop = document.createElement('div');
				iconPop.className = 'aq-iconpop';
				iconPop.hidden = true;
				var grid = document.createElement('div');
				grid.className = 'aq-iconpop-grid';
				var none = document.createElement('button');
				none.type = 'button'; none.className = 'aq-iconpop-sw aq-iconpop-none'; none.title = 'No icon'; none.textContent = '—';
				none.addEventListener('click', function () { setIcon(''); });
				grid.appendChild(none);
				Object.keys(ICONS).forEach(function (name) {
					var b = document.createElement('button');
					b.type = 'button'; b.className = 'aq-iconpop-sw'; b.title = name;
					b.innerHTML = svgWrap(ICONS[name]);
					b.addEventListener('click', function () { setIcon(ICONS[name]); });
					grid.appendChild(b);
				});
				iconPop.appendChild(grid);
				var ta = document.createElement('textarea');
				ta.className = 'aq-iconpop-svg'; ta.rows = 2; ta.placeholder = 'Paste custom SVG (advanced)';
				var apply = document.createElement('button');
				apply.type = 'button'; apply.className = 'aq-btn aq-btn--ghost aq-iconpop-apply'; apply.textContent = 'Use this SVG';
				apply.addEventListener('click', function () { var ta2 = $('.aq-iconpop-svg', iconPop); setIcon(ta2 ? ta2.value : ''); });
				iconPop.appendChild(ta); iconPop.appendChild(apply);
				document.body.appendChild(iconPop);
			}
			function setIcon(svg) {
				if (!iconTarget) return;
				svg = (svg || '').replace(/<\/?svg[^>]*>/gi, '').trim(); // normalize to inner markup
				var hid = $('.aq-c-icon', iconTarget), btn = $('.aq-c-iconbtn', iconTarget);
				if (hid) hid.value = svg;
				if (btn) btn.innerHTML = svg ? svgWrap(svg) : '+';
				iconPop.hidden = true; iconTarget = null;
			}
			function openIconPicker(row, btn) {
				if (!iconPop) buildIconPop();
				iconTarget = row;
				var ta = $('.aq-iconpop-svg', iconPop); if (ta) ta.value = '';
				var r = btn.getBoundingClientRect();
				iconPop.style.top = (window.pageYOffset + r.bottom + 6) + 'px';
				iconPop.style.left = Math.max(8, window.pageXOffset + r.left) + 'px';
				iconPop.hidden = false;
			}
			document.addEventListener('click', function (e) {
				if (iconPop && !iconPop.hidden && !iconPop.contains(e.target) && !e.target.closest('.aq-c-iconbtn')) { iconPop.hidden = true; iconTarget = null; }
			});

			function wireChild(row) {
				var del = $('.aq-cdel', row);
				if (del) del.addEventListener('click', function () { var p = row.parentNode; p.removeChild(row); });
				var ib = $('.aq-c-iconbtn', row);
				if (ib) ib.addEventListener('click', function (e) { e.stopPropagation(); openIconPicker(row, ib); });
			}

			function wireItem(card) {
				var head = $('.aq-nav-itemhead', card);
				var del = $('.aq-item-del', card), up = $('.aq-item-up', card), down = $('.aq-item-down', card);
				var mode = $('.aq-i-mode', card);
				var opts = $('.aq-nav-itemopts', card);
				var children = $('.aq-nav-children', card);
				var childlist = $('.aq-nav-childlist', card);
				var addchild = $('.aq-addchild', card);

				if (del) del.addEventListener('click', function () { itemsRoot.removeChild(card); renumberItems(); });
				if (up) up.addEventListener('click', function () { var p = card.previousElementSibling; if (p) { itemsRoot.insertBefore(card, p); renumberItems(); } });
				if (down) down.addEventListener('click', function () { var n = card.nextElementSibling; if (n) { itemsRoot.insertBefore(n, card); renumberItems(); } });

				function applyMode() {
					var m = mode ? mode.value : '';
					card.setAttribute('data-mode', m);
					if (opts) opts.hidden = (m === ''); // "View all" toggle shows for any dropdown
					if (m === 'manual') {
						children.hidden = false;
						if (childlist && !$('.aq-nav-child', childlist)) addChild(childlist, true);
					} else {
						children.hidden = true;
					}
				}
				if (mode) mode.addEventListener('change', applyMode);

				if (addchild) addchild.addEventListener('click', function () { addChild(childlist, true); });
				$all('.aq-nav-child', card).forEach(wireChild);
				if (childlist) makeSortable(childlist, '.aq-nav-child', '.aq-cgrip', null);
			}

			function addChild(childlist, focus) {
				if (!childlist) return;
				var tmp = document.createElement('div');
				tmp.innerHTML = BLANK_CHILD.trim();
				var row = tmp.firstElementChild;
				childlist.appendChild(row);
				wireChild(row);
				if (focus) { var f = $('.aq-c-label', row); if (f) f.focus(); }
			}

			function addItem(focus) {
				var tmp = document.createElement('div');
				tmp.innerHTML = BLANK_ITEM.trim();
				var card = tmp.firstElementChild;
				itemsRoot.appendChild(card);
				wireItem(card);
				renumberItems();
				if (focus) { var f = $('.aq-i-label', card); if (f) f.focus(); }
			}

			if (itemsRoot) {
				$all('.aq-nav-item', itemsRoot).forEach(wireItem);
				makeSortable(itemsRoot, '.aq-nav-item', '.aq-grip', renumberItems);
				renumberItems();
				var addBtn = $('#aq-nav-add');
				if (addBtn) addBtn.addEventListener('click', function () { addItem(true); });
			}

			/* ---------- Collect + save ---------- */
			function rowsFrom(id) {
				return $all('.aq-nav-row', document.getElementById(id)).map(function (tr) {
					return { label: (($('.aq-nav-label-i', tr) || {}).value || '').trim(), href: (($('.aq-nav-href-i', tr) || {}).value || '').trim() };
				}).filter(function (r) { return r.label !== ''; });
			}

			function setDeep(obj, dotted, v) {
				var parts = dotted.split('.'), node = obj;
				for (var i = 0; i < parts.length - 1; i++) { if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) node[parts[i]] = {}; node = node[parts[i]]; }
				node[parts[parts.length - 1]] = v;
			}

			function collect() {
				var p = {};
				// Header menu: one object per item card.
				p.nav = $all('.aq-nav-item', itemsRoot).map(function (card) {
					var item = { label: val('.aq-i-label', card), href: val('.aq-i-href', card) };
					var mode = val('.aq-i-mode', card);
					var vaEl = $('.aq-i-viewall', card);
					var hideVA = vaEl ? !vaEl.checked : false; // unchecked = hide "View all"
					if (mode === 'services' || mode === 'specialty' || mode === 'areas') {
						item.panel = mode; item.id = 'nav-' + mode;
						if (hideVA) item.hideViewAll = true;
					} else if (mode === 'manual') {
						var kids = $all('.aq-nav-child', card).map(function (cr) {
							var ic = $('.aq-c-icon', cr);
							return { label: val('.aq-c-label', cr), href: val('.aq-c-href', cr), tagline: val('.aq-c-tag', cr), icon: ic ? (ic.value || '') : '' };
						}).filter(function (k) { return k.label !== ''; });
						if (kids.length) {
							item.children = kids;
							item.cols = parseInt(val('.aq-i-cols', card), 10) || 2;
							item.iconStyle = val('.aq-i-iconstyle', card) || 'outline';
							var hd = val('.aq-i-heading', card);
							if (hd) item.heading = hd;
							var ll = val('.aq-i-linklabel', card);
							if (ll) item.linkLabel = ll;
							if (hideVA) item.hideViewAll = true;
							item.promo = {
								eyebrow: val('.aq-p-eyebrow', card), text: val('.aq-p-text', card),
								ctaLabel: val('.aq-p-ctaLabel', card), ctaHref: val('.aq-p-ctaHref', card),
								cta2Label: val('.aq-p-cta2Label', card), cta2Href: val('.aq-p-cta2Href', card)
							};
						}
					}
					return item;
				}).filter(function (it) { return it.label !== ''; });

				// Footer columns (unchanged).
				p.footer = { company: { links: rowsFrom('aq-nav-company') }, inspections: { links: rowsFrom('aq-nav-inspections') }, legal: rowsFrom('aq-nav-legal'), social: {} };
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
					_format: 'autoforge-navigation',
					_version: 1,
					_exported: new Date().toISOString(),
					_site: location.hostname || '',
					nav: data.nav || [],
					footer: data.footer || {},
					megamenu: PANELS.megamenu || {},
					towns: PANELS.towns || []
				};
				var host = (location.hostname || 'site').replace(/[^a-z0-9.\-]/gi, '');
				var stamp = new Date().toISOString().slice(0, 10);
				download('navigation-' + host + '-' + stamp + '.json', JSON.stringify(payload, null, 2));
				notice('Exported the current menu and footer to a JSON file.', true);
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
						if (Array.isArray(data.nav)) payload.nav = data.nav;
						if (data.footer && typeof data.footer === 'object') payload.footer = data.footer;
						if (data.megamenu && typeof data.megamenu === 'object') payload.megamenu = data.megamenu;
						if (Array.isArray(data.towns)) payload.towns = data.towns;
						if (!payload.nav && !payload.footer && !payload.megamenu && !payload.towns) { notice('Import failed: no menu, footer, or dropdown data found in that file.', false); return; }
						var n = payload.nav ? payload.nav.length : 0;
						if (!window.confirm('Import will replace your header menu, footer, and dropdown panel contents' + (n ? ' (' + n + ' menu item' + (n === 1 ? '' : 's') + ')' : '') + '. Continue?')) return;
						if (saving) saving.style.display = 'inline';
						fetch(REST, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify(payload) })
							.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
							.then(function (res) {
								if (res.ok && res.body && res.body.ok) {
									notice('Imported. Reloading to show the new menu…', true);
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
