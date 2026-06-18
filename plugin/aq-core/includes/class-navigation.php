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
				$item  = ['href' => self::url((string) ($row['href'] ?? '#')), 'label' => $label];
				$panel = (string) ($row['panel'] ?? '');
				if ($panel !== '' && in_array($panel, $panels, true)) {
					// Auto-filled dropdown (services/specialty/areas).
					$item['panel'] = $panel;
					$item['id']    = 'nav-' . $panel;
				} else {
					// Manual dropdown: sub-links the editor added (+ optional promo).
					$kids = self::children($row['children'] ?? []);
					if ($kids) {
						$item['children'] = $kids;
						$promo = self::promo($row['promo'] ?? []);
						if ($promo) {
							$item['promo'] = $promo;
						}
						$ll = sanitize_text_field((string) ($row['linkLabel'] ?? ''));
						if ($ll !== '') {
							$item['linkLabel'] = $ll;
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
		echo '<p class="aq-nav-help">Drag the <span class="aq-grip-hint">&#x2807;</span> handle to reorder. To turn an item into a dropdown, set its <strong>Dropdown</strong> to &ldquo;Sub-links I add&rdquo; and add links beneath it &mdash; or point it at your Services, Specialty, or Service-area lists to fill the dropdown automatically.</p>';
		echo '<div id="aq-nav-items" class="aq-nav-items">';
		foreach ($nav as $item) {
			echo self::item_card_html(is_array($item) ? $item : []);
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

	/** One sub-link row inside a manual dropdown. */
	private static function child_row_html(string $label = '', string $href = '', string $tagline = ''): string {
		return '<div class="aq-nav-child">'
			. '<button type="button" class="aq-grip aq-cgrip" title="Drag to reorder" aria-label="Drag to reorder" tabindex="-1">&#x2807;</button>'
			. '<input type="text" class="aq-nav-input aq-c-label" value="' . esc_attr($label) . '" placeholder="Sub-link text" />'
			. '<input type="text" class="aq-nav-input aq-c-href" value="' . esc_attr($href) . '" placeholder="/path/" />'
			. '<input type="text" class="aq-nav-input aq-c-tag" value="' . esc_attr($tagline) . '" placeholder="Tagline (optional)" />'
			. '<button type="button" class="aq-iconbtn aq-iconbtn--del aq-cdel" title="Remove sub-link">&times;</button>'
			. '</div>';
	}

	/** One top-level menu item card (header row + collapsible children/promo). */
	private static function item_card_html(array $item): string {
		$label = (string) ($item['label'] ?? '');
		$href  = (string) ($item['href'] ?? '');

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

		$children_html = '';
		foreach ($kids as $c) {
			if (!is_array($c)) {
				continue;
			}
			$children_html .= self::child_row_html((string) ($c['label'] ?? ''), (string) ($c['href'] ?? ''), (string) ($c['tagline'] ?? ''));
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
			<div class="aq-nav-children"<?php echo $mode === 'manual' ? '' : ' hidden'; ?>>
				<p class="aq-children-help">Sub-links shown in this dropdown. Drag to reorder.</p>
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
			.aq-hub .aq-nav-child { display:flex; align-items:center; gap:8px; padding-left:14px; }
			.aq-hub .aq-nav-child.aq-dragging { opacity:.55; }
			.aq-hub .aq-nav-child .aq-c-label { flex:1.1 1 0; }
			.aq-hub .aq-nav-child .aq-c-href { flex:1.2 1 0; }
			.aq-hub .aq-nav-child .aq-c-tag { flex:1.4 1 0; }
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
		?>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode($rest); ?>, NONCE = <?php echo wp_json_encode($nonce); ?>;
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

			function wireChild(row) {
				var del = $('.aq-cdel', row);
				if (del) del.addEventListener('click', function () { var p = row.parentNode; p.removeChild(row); });
			}

			function wireItem(card) {
				var head = $('.aq-nav-itemhead', card);
				var del = $('.aq-item-del', card), up = $('.aq-item-up', card), down = $('.aq-item-down', card);
				var mode = $('.aq-i-mode', card);
				var children = $('.aq-nav-children', card);
				var childlist = $('.aq-nav-childlist', card);
				var addchild = $('.aq-addchild', card);

				if (del) del.addEventListener('click', function () { itemsRoot.removeChild(card); renumberItems(); });
				if (up) up.addEventListener('click', function () { var p = card.previousElementSibling; if (p) { itemsRoot.insertBefore(card, p); renumberItems(); } });
				if (down) down.addEventListener('click', function () { var n = card.nextElementSibling; if (n) { itemsRoot.insertBefore(n, card); renumberItems(); } });

				function applyMode() {
					var m = mode ? mode.value : '';
					card.setAttribute('data-mode', m);
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
					if (mode === 'services' || mode === 'specialty' || mode === 'areas') {
						item.panel = mode; item.id = 'nav-' + mode;
					} else if (mode === 'manual') {
						var kids = $all('.aq-nav-child', card).map(function (cr) {
							return { label: val('.aq-c-label', cr), href: val('.aq-c-href', cr), tagline: val('.aq-c-tag', cr) };
						}).filter(function (k) { return k.label !== ''; });
						if (kids.length) {
							item.children = kids;
							var ll = val('.aq-i-linklabel', card);
							if (ll) item.linkLabel = ll;
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
		})();
		</script>
		<?php
	}
}
