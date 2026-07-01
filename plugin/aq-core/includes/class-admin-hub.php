<?php
/**
 * AutoForge — the agency hub in wp-admin.
 *
 * Phase C foundation: registers the top-level "AutoForge" menu + screens.
 * Overview, Pages, and a first-pass Editor (live preview + section list) are
 * live; SEO / Locations / Performance are stubs that fill in next. Server-
 * rendered PHP for now; the React visual editor mounts into the Editor screen
 * in Phase D. Gated on manage_options until the dedicated aq_agency cap lands.
 */

class AQ_Admin_Hub {

	const CAP  = 'manage_options';
	const SLUG = 'aq-dashboard';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_menu', [__CLASS__, 'hide_boost_from_settings'], 999);
		add_action('wp_loaded', [__CLASS__, 'hide_boost_from_admin_bar']);
	}

	public static function menu(): void {
		add_menu_page('AutoForge', 'AutoForge', self::CAP, self::SLUG, [__CLASS__, 'render_overview'], 'dashicons-admin-home', 3);
		add_submenu_page(self::SLUG, 'Overview', 'Overview', self::CAP, self::SLUG, [__CLASS__, 'render_overview']);
		add_submenu_page(self::SLUG, 'Pages', 'Pages', self::CAP, 'aq-pages', [__CLASS__, 'render_pages']);
		add_submenu_page(self::SLUG, 'SEO', 'SEO', self::CAP, 'aq-seo', ['AQ_SEO_Manager', 'render']);
		add_submenu_page(self::SLUG, 'Locations', 'Locations', self::CAP, 'aq-locations', ['AQ_Locations', 'render']);
		add_submenu_page(self::SLUG, 'Navigation', 'Navigation', self::CAP, 'aq-navigation', ['AQ_Navigation', 'render']);
		add_submenu_page(self::SLUG, 'Performance', 'Performance', self::CAP, 'aq-performance', ['AQ_Performance', 'render']);
		// Boost (the performance module) is hidden from the WP Settings menu + the
		// admin bar (see hide_boost_from_*) so it lives ONLY here in the AQM
		// Dashboard. The submenu slug carries a .php target, so WordPress renders it
		// as a direct link to the still-registered options-general.php?page=boost page.
		add_submenu_page(self::SLUG, 'Boost', 'Boost', self::CAP, 'options-general.php?page=boost');
		// The Editor is rendered inside the Pages screen (aq-pages&page_id=N) so it
		// is always a properly-authorized admin page — no hidden/removed submenu.
	}

	public static function hide_boost_from_settings(): void {
		// Boost (the performance module) lives only in the AutoForge. Hide its
		// Settings → Boost menu item; the page stays reachable via the AutoForge
		// → Boost link (remove_submenu_page keeps the page registered + accessible).
		$slug = defined('WP_ROCKET_PLUGIN_SLUG') ? WP_ROCKET_PLUGIN_SLUG : 'boost';
		remove_submenu_page('options-general.php', $slug);
	}

	public static function hide_boost_from_admin_bar(): void {
		// The Boost admin-bar menu is added by rocket_admin_bar() at PHP_INT_MAX - 10.
		// Remove it so Boost is reachable only from the AutoForge.
		remove_action('admin_bar_menu', 'rocket_admin_bar', PHP_INT_MAX - 10);
	}

	/* ---------------- shared chrome ---------------- */

	private static function styles(): void {
		?>
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap">
		<style>
			.aq-hub { margin: 20px 20px 40px 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0d1014; }
			.aq-hub h1, .aq-hub h2, .aq-hub h3, .aq-hub h4 { font-family: Poppins, Inter, system-ui, sans-serif; }
			.aq-hub * { box-sizing: border-box; }
			.aq-hub__head { display: flex; align-items: center; justify-content: space-between; gap: 16px; background: linear-gradient(120deg, #0d1014, #15191f); color: #fff; border-radius: 14px; padding: 22px 26px; }
			.aq-hub__head h1 { font-family: Poppins, Inter, system-ui, sans-serif; font-size: 22px; margin: 0 0 2px; color: #fff; }
			.aq-hub__head p { margin: 0; color: #c9cfd6; font-size: 13px; }
			.aq-hub__brandtag { display:inline-flex; align-items:center; gap:8px; background:rgba(200,16,46,.18); color:#ff4d68; border:1px solid rgba(255,77,104,.40); padding:6px 12px; border-radius:999px; font-size:12px; font-weight:600; }
			.aq-hub__tabs { display:flex; gap:6px; margin:18px 0 22px; flex-wrap:wrap; }
			.aq-hub__tab { text-decoration:none; padding:8px 14px; border-radius:999px; font-size:13px; font-weight:600; color:#5b6471; background:#fff; border:1px solid #e6e8eb; }
			.aq-hub__tab--active { background:#c8102e; color:#fff; border-color:#c8102e; }
			.aq-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:16px; }
			.aq-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:18px 20px; box-shadow:0 1px 2px rgba(13,16,20,.04); }
			.aq-card__label { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; margin:0 0 8px; }
			.aq-card__num { font-family:Poppins, Inter, system-ui, sans-serif; font-size:30px; font-weight:700; line-height:1; color:#0d1014; }
			.aq-card__sub { font-size:12px; color:#5b6471; margin-top:8px; }
			.aq-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; }
			.aq-badge--ok { background:#eaf0ea; color:#1a8f4f; } .aq-badge--warn { background:#fdf1dd; color:#9a6212; } .aq-badge--off { background:#fbe7e7; color:#a30d25; }
			.aq-panel { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-top:20px; }
			.aq-panel h2 { font-family:Poppins, Inter, system-ui, sans-serif; font-size:17px; margin:0 0 14px; color:#0d1014; }
			.aq-table { width:100%; border-collapse:collapse; font-size:13px; }
			.aq-table th { text-align:left; color:#5b6471; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; padding:8px 10px; border-bottom:2px solid #eef1f5; }
			.aq-table td { padding:10px; border-bottom:1px solid #eef1f5; vertical-align:middle; }
			.aq-table tr:hover td { background:#fafbfc; }
			.aq-pages__bar { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
			/* .aq-hub prefix raises specificity above WordPress' input[type=search] rule, which would otherwise win on padding and push text over the icon. */
			.aq-hub .aq-search { box-sizing:border-box; flex:1; min-width:220px; max-width:440px; height:38px; padding:9px 14px 9px 40px; border:1px solid #c9cfd6; border-radius:10px; font-size:13px; line-height:1.4; color:#0d1014; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235b6471' stroke-width='2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M21 21l-4.3-4.3'/%3E%3C/svg%3E") no-repeat 15px center; }
			.aq-hub .aq-search:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 3px rgba(200,16,46,.18); }
			.aq-search__empty { color:#5b6471; font-size:13px; padding:16px 4px; }
			.aq-btn { display:inline-block; text-decoration:none; background:#c8102e; color:#fff; font-weight:700; font-size:12px; padding:6px 14px; border-radius:8px; border:0; cursor:pointer; }
			.aq-btn--ghost { background:#fff; color:#15191f; border:1px solid #c9cfd6; }
			.aq-pill { font-size:11px; padding:2px 8px; border-radius:999px; background:#eef1f5; color:#5b6471; font-weight:600; }
			.aq-soon { text-align:center; padding:60px 20px; color:#5b6471; }
			.aq-soon__icon { font-size:42px; opacity:.5; }
		</style>
		<?php
	}

	private static function head(string $title, string $sub): void {
		?>
		<div class="aq-hub__head">
			<div>
				<h1><?php echo esc_html($title); ?></h1>
				<p><?php echo esc_html($sub); ?></p>
			</div>
			<span class="aq-hub__brandtag">★ <?php echo esc_html((string) (aq_site('shortName') ?: 'AutoForge')); ?></span>
		</div>
		<?php
	}

	private static function tabs(string $current): void {
		$tabs = [
			'aq-dashboard'  => 'Overview',
			'aq-pages'      => 'Pages',
			'aq-styles'     => 'Styles',
			'aq-seo'        => 'SEO',
			'aq-seo-agent'  => 'SEO Agent',
			'aq-locations'  => 'Locations',
			'aq-navigation' => 'Navigation',
			'aq-logo'       => 'Logo',
			'aq-performance'=> 'Performance',
			'aq-forms'      => 'Forms',
			'aq-tracking'   => 'Tracking',
			'aq-integrations'=> 'Integrations',
			'aq-import'     => 'Import',
			'options-general.php?page=boost' => 'Boost',
		];
		echo '<div class="aq-hub__tabs">';
		foreach ($tabs as $slug => $label) {
			// Slugs that carry their own .php target (e.g. the Boost settings page)
			// are linked directly; the rest hang off admin.php?page=.
			$url = strpos($slug, '.php') !== false ? admin_url($slug) : admin_url('admin.php?page=' . $slug);
			$cls = 'aq-hub__tab' . ($slug === $current ? ' aq-hub__tab--active' : '');
			printf('<a class="%s" href="%s">%s</a>', esc_attr($cls), esc_url($url), esc_html($label));
		}
		echo '</div>';
	}

	/**
	 * Shared screen chrome for sub-screens (SEO, Locations, Performance, …).
	 * open() prints the wrap + styles + branded head + tab nav; close() ends it.
	 */
	public static function open(string $title, string $sub, string $active_tab = ''): void {
		echo '<div class="wrap aq-hub">';
		self::styles();
		self::head($title, $sub);
		if ($active_tab !== '') {
			self::tabs($active_tab);
		}
		// Anchor for WordPress' admin-notice relocation: notices are auto-moved to
		// just before .wp-header-end, so they land here (below the header + tabs)
		// instead of being hoisted into the navy banner after its <h1>.
		echo '<hr class="wp-header-end" style="visibility:hidden;height:0;margin:0;border:0;padding:0;">';
	}

	public static function close(): void {
		echo '</div>';
	}

	/* ---------------- data ---------------- */

	/** Returns [published, draft, structured, raw, seoComplete] across pages. */
	private static function page_stats(): array {
		$pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);
		$out = ['published' => 0, 'draft' => 0, 'structured' => 0, 'raw' => 0, 'seo' => 0, 'total' => count($pages)];
		$have_acf = function_exists('get_field');
		foreach ($pages as $p) {
			$p->post_status === 'draft' ? $out['draft']++ : $out['published']++;
			if (!$have_acf) {
				continue;
			}
			$sections = get_field('sections', $p->ID);
			if (is_array($sections) && $sections) {
				$first = $sections[0]['acf_fc_layout'] ?? '';
				if ($first === 'raw_html' && count($sections) === 1) {
					$out['raw']++;
				} else {
					$out['structured']++;
				}
			}
			$t = (string) get_field('seo_title', $p->ID);
			$d = (string) get_field('seo_description', $p->ID);
			if ($t !== '' && $d !== '') {
				$out['seo']++;
			}
		}
		return $out;
	}

	/* ---------------- screens ---------------- */

	public static function render_overview(): void {
		$s = self::page_stats();
		$seo_pct  = $s['total'] ? round($s['seo'] / $s['total'] * 100) : 0;
		$conv_pct = $s['total'] ? round($s['structured'] / $s['total'] * 100) : 0;
		$towns = is_array(aq_site('towns')) ? count(aq_site('towns')) : 0;
		$boost = defined('WP_ROCKET_VERSION');
		echo '<div class="wrap aq-hub">';
		self::styles();
		self::head('Agency Dashboard', 'Manage content, SEO, locations and performance for the site.');
		self::tabs('aq-dashboard');
		echo '<div class="aq-cards">';
		self::card('Published Pages', (string) $s['published'], $s['draft'] . ' draft');
		self::card('Editable (structured)', $s['structured'] . ' / ' . $s['total'], $conv_pct . '% converted from raw HTML');
		self::card('SEO Complete', $seo_pct . '%', $s['seo'] . ' of ' . $s['total'] . ' pages have title + description');
		self::card('Service Areas', (string) $towns, 'towns in site config');
		self::card_html('Performance', $boost
			? '<span class="aq-badge aq-badge--ok">Boost active</span>'
			: '<span class="aq-badge aq-badge--off">Boost off</span>', 'Performance module — see the Boost tab');
		echo '</div>';

		echo '<div class="aq-panel"><h2>Quick actions</h2>';
		echo '<p><a class="aq-btn" href="' . esc_url(admin_url('admin.php?page=aq-pages')) . '">Manage pages &amp; editor</a> ';
		echo '<a class="aq-btn aq-btn--ghost" href="' . esc_url(admin_url('options-general.php?page=boost')) . '">Open Boost settings</a></p>';
		echo '<p style="color:#5b6471;font-size:13px;margin-top:14px;">Next up: the visual page editor (live preview + click-to-edit), SEO manager, and the AI assistant.</p>';
		echo '</div>';
		echo '</div>';
	}

	public static function render_pages(): void {
		// Editor mode: aq-pages&page_id=N renders the editor for that page.
		if (isset($_GET['page_id'])) {
			self::render_editor();
			return;
		}
		$pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => ['publish', 'draft'], 'orderby' => 'title', 'order' => 'ASC']);
		$have_acf = function_exists('get_field');
		echo '<div class="wrap aq-hub">';
		self::styles();
		self::head('Pages', 'Open a page in the editor to manage its content sections.');
		self::tabs('aq-pages');
		if (class_exists('AQ_Page_Folders')) { AQ_Page_Folders::styles(); }
		echo '<div class="aq-panel aq-pages-layout">';
		if (class_exists('AQ_Page_Folders')) { echo AQ_Page_Folders::sidebar_html(); }
		echo '<div class="aq-pages-main">';
		echo '<div class="aq-pages__bar">';
		echo '<h2 style="margin:0;">' . count($pages) . ' pages</h2>';
		echo '<input type="search" id="aq-page-search" class="aq-search" placeholder="Search pages by title or URL…" autocomplete="off" autofocus aria-label="Search pages" data-total="' . count($pages) . '">';
		echo '</div>';
		echo '<table class="aq-table" id="aq-pages-table"><thead><tr><th>Title</th><th>Path</th><th>Sections</th><th>Status</th><th>Folder</th><th></th></tr></thead><tbody>';
		$folder_map = class_exists('AQ_Page_Folders') ? AQ_Page_Folders::map() : [];
		foreach ($pages as $p) {
			$count = 0; $kind = '—';
			if ($have_acf) {
				$sections = get_field('sections', $p->ID);
				if (is_array($sections)) {
					$count = count($sections);
					$first = $sections[0]['acf_fc_layout'] ?? '';
					$kind = ($first === 'raw_html' && $count === 1) ? 'Raw HTML' : 'Structured';
				}
			}
			$path = parse_url((string) get_permalink($p), PHP_URL_PATH) ?: '/';
			$editor = admin_url('admin.php?page=aq-pages&page_id=' . $p->ID);
			$haystack = strtolower(get_the_title($p) . ' ' . $path);
			echo '<tr data-aq-page="' . (int) $p->ID . '" data-aq-folder="' . esc_attr((string) ($folder_map[(string) $p->ID] ?? '')) . '" data-aq-search="' . esc_attr($haystack) . '">';
			echo '<td><strong>' . esc_html(get_the_title($p)) . '</strong></td>';
			echo '<td><code style="font-size:12px;color:#5b6471;">' . esc_html($path) . '</code></td>';
			echo '<td>' . esc_html((string) $count) . ' <span class="aq-pill">' . esc_html($kind) . '</span></td>';
			echo '<td>' . esc_html(ucfirst($p->post_status)) . '</td>';
			echo '<td class="aq-folder-cell">' . (class_exists('AQ_Page_Folders') ? AQ_Page_Folders::row_select_html((int) $p->ID, (string) ($folder_map[(string) $p->ID] ?? '')) : '') . '</td>';
			echo '<td><a class="aq-btn" href="' . esc_url($editor) . '">Open editor</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="aq-search__empty" id="aq-pages-empty" style="display:none;">No pages match your search.</p>';
		echo '</div></div></div>';
		if (class_exists('AQ_Page_Folders')) { AQ_Page_Folders::script(); }
	}

	public static function render_editor(): void {
		$id = isset($_GET['page_id']) ? (int) $_GET['page_id'] : 0;
		if (!$id || !class_exists('AQ_Editor')) {
			echo '<div class="wrap"><p>Pick a page from <a href="' . esc_url(admin_url('admin.php?page=aq-pages')) . '">Pages</a>.</p></div>';
			return;
		}
		// Full-screen builder: hide the wp-admin chrome so the editor owns the viewport.
		?>
		<style>
			#adminmenumain, #wpfooter, #wpadminbar, .update-nag, .notice, #screen-meta, #screen-meta-links { display:none !important; }
			#wpcontent, #wpbody, #wpbody-content { margin:0 !important; padding:0 !important; float:none !important; }
			html.wp-toolbar { padding-top:0 !important; }
			#wpbody-content > .wrap, #wpbody-content > h1:first-child { display:none; }
			#aq-builder-root { position:fixed; inset:0; z-index:99990; background:#f7f9fa; }
		</style>
		<?php
		AQ_Editor::render_builder($id);
	}

	public static function render_soon(): void {
		$screen = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
		$map = ['aq-seo' => ['SEO Manager', 'Edit titles, descriptions, canonicals and keywords across all pages.'],
			'aq-locations' => ['Locations', 'Manage service-area towns, counties and business info.'],
			'aq-performance' => ['Performance', 'PageSpeed scores, Core Web Vitals and cache controls.']];
		[$title, $sub] = $map[$screen] ?? ['Coming soon', ''];
		echo '<div class="wrap aq-hub">';
		self::styles();
		self::head($title, $sub);
		self::tabs($screen);
		echo '<div class="aq-panel"><div class="aq-soon"><div class="aq-soon__icon dashicons dashicons-hammer"></div><p style="margin-top:10px;font-weight:600;">This screen is being built.</p><p>Tracked in the Phase 2 plan — wiring up next.</p></div></div></div>';
	}

	/* ---------------- card helpers ---------------- */

	private static function card(string $label, string $num, string $sub = ''): void {
		echo '<div class="aq-card"><p class="aq-card__label">' . esc_html($label) . '</p><div class="aq-card__num">' . esc_html($num) . '</div>';
		if ($sub !== '') {
			echo '<div class="aq-card__sub">' . esc_html($sub) . '</div>';
		}
		echo '</div>';
	}

	private static function card_html(string $label, string $html, string $sub = ''): void {
		echo '<div class="aq-card"><p class="aq-card__label">' . esc_html($label) . '</p><div class="aq-card__num">' . wp_kses_post($html) . '</div>';
		if ($sub !== '') {
			echo '<div class="aq-card__sub">' . esc_html($sub) . '</div>';
		}
		echo '</div>';
	}
}
