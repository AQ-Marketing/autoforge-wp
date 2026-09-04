<?php
/**
 * AutoForge — the agency hub in wp-admin.
 *
 * Registers the top-level "AutoForge" menu and the shared screen chrome. Every
 * sub-screen (SEO, Forms, Analytics, …) renders inside AQ_Admin_Hub::open()/
 * close(), which now draws a grouped, collapsible ACCORDION SIDEBAR on the left
 * and the screen content on the right.
 *
 * Why an in-page sidebar instead of the WordPress submenu: WP admin submenus are
 * a flat list with no native grouping/accordion. Rather than fight core with
 * fragile JS, the plugin owns its own left-hand nav (see nav()/sidebar()) as the
 * primary, organized navigation. The WordPress submenu is left intact (removing
 * items from it also strips WP's capability grant, denying page access), so it
 * stays as a flat fallback while the accordion is the main way to get around.
 * Gated on manage_options until the dedicated aq_agency cap lands.
 */

class AQ_Admin_Hub {

	const CAP  = 'manage_options';
	const SLUG = 'aq-dashboard';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_menu', [__CLASS__, 'hide_boost_from_settings'], 999);
		add_action('wp_loaded', [__CLASS__, 'hide_boost_from_admin_bar']);
		add_action('admin_init', [__CLASS__, 'block_boost_page']);
		// Hide the flat WP submenu (CSS only, keeps page access); the accordion is the nav.
		add_action('admin_head', [__CLASS__, 'hide_wp_submenu']);
		// Make the top-level "AutoForge" menu item tappable on mobile/touch (see method).
		add_action('admin_footer', [__CLASS__, 'nav_mobile_fix_script']);
	}

	public static function menu(): void {
		add_menu_page('AutoForge', 'AutoForge', self::CAP, self::SLUG, [__CLASS__, 'render_overview'], 'dashicons-admin-home', 3);
		add_submenu_page(self::SLUG, 'Overview', 'Overview', self::CAP, self::SLUG, [__CLASS__, 'render_overview']);
		add_submenu_page(self::SLUG, 'Pages', 'Pages', self::CAP, 'aq-pages', [__CLASS__, 'render_pages']);
		add_submenu_page(self::SLUG, 'SEO', 'SEO', self::CAP, 'aq-seo', ['AQ_SEO_Manager', 'render']);
		add_submenu_page(self::SLUG, 'Locations/NAP', 'Locations/NAP', self::CAP, 'aq-locations', ['AQ_Locations', 'render']);
		add_submenu_page(self::SLUG, 'Navigation', 'Navigation', self::CAP, 'aq-navigation', ['AQ_Navigation', 'render']);
		add_submenu_page(self::SLUG, 'Footer', 'Footer', self::CAP, 'aq-footer', ['AQ_Footer', 'render']);
		add_submenu_page(self::SLUG, 'Performance', 'Performance', self::CAP, 'aq-performance', ['AQ_Performance', 'render']);
		// Boost (the performance module) has NO settings UI. It runs a single
		// code-locked config (see the $aq_boost_config block in aq-core.php), so
		// there is nothing to configure per-site. Its WP Rocket settings page is
		// hidden from the Settings menu + admin bar (hide_boost_from_*) and blocked
		// on direct access (block_boost_page). Cache clearing lives in the admin bar
		// and on the Performance screen.
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
		// Remove the upstream (WP Rocket-branded) menu; AQ adds its own clean
		// "Clear cache" node instead (see AQ_Performance::admin_bar).
		remove_action('admin_bar_menu', 'rocket_admin_bar', PHP_INT_MAX - 10);
	}

	/**
	 * Boost has no settings UI. Its WP Rocket settings page stays registered
	 * (so the engine's own hooks are intact), but we block human access to it:
	 * anyone hitting options-general.php?page={boost slug} is bounced to the
	 * AutoForge Performance screen. Config is code-locked in aq-core.php.
	 */
	public static function block_boost_page(): void {
		$slug = defined('WP_ROCKET_PLUGIN_SLUG') ? WP_ROCKET_PLUGIN_SLUG : 'boost';
		if (isset($_GET['page']) && $_GET['page'] === $slug) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect(admin_url('admin.php?page=aq-performance'));
			exit;
		}
	}

	/**
	 * Hide the flat WordPress submenu under "AutoForge". CSS-ONLY: removing items
	 * from the $submenu global also strips WP's capability grant (denies page
	 * access, as a prior version learned); hiding them visually keeps every page
	 * fully reachable. The top-level "AutoForge" link still opens Overview.
	 *
	 * Scoped to DESKTOP (min-width:783px). On touch/mobile WP core makes the first
	 * tap on a top-level item that has a submenu OPEN that submenu instead of
	 * following the link — so with the submenu hidden, tapping "AutoForge" did
	 * nothing. Below WP's 782px breakpoint we leave the native submenu visible so
	 * tap-to-open-then-tap-an-item always works; nav_mobile_fix_script() adds the
	 * nicer single-tap-navigates behaviour on top.
	 */
	public static function hide_wp_submenu(): void {
		echo '<style id="aq-hide-submenu">@media (min-width:783px){#toplevel_page_' . esc_attr(self::SLUG) . ' ul.wp-submenu{display:none!important;}}</style>';
	}

	/**
	 * Restore a working "AutoForge" menu tap on touch devices. WP core binds a
	 * delegated touch handler to `a.wp-has-submenu` that swallows the first tap
	 * (to reveal the — here hidden — submenu). Stripping that class from our
	 * top-level item makes a single tap simply navigate to Overview, where the
	 * in-page accordion (which already stacks responsively) takes over. Runs in the
	 * footer, after the menu markup exists; core's handler is delegated, so it stops
	 * matching the moment the class is gone. Pure DOM, dependency-free.
	 */
	public static function nav_mobile_fix_script(): void {
		?>
		<script id="aq-af-nav-fix">
		(function(){
			var li = document.getElementById('toplevel_page_<?php echo esc_js(self::SLUG); ?>');
			if(!li){ return; }
			li.classList.remove('wp-has-submenu');
			var a = li.querySelector('a');
			if(a){ a.classList.remove('wp-has-submenu'); a.removeAttribute('aria-haspopup'); }
		})();
		</script>
		<?php
	}

	/* ---------------- navigation model ---------------- */

	/**
	 * The single source of truth for the sidebar. Entries are either a standalone
	 * 'link' or a collapsible 'group' of items. Item values are a label string, or
	 * ['label'=>..,'soon'=>true] for a not-yet-built placeholder. Icons are
	 * dashicon names (without the "dashicons-" prefix).
	 */
	private static function nav(): array {
		return [
			['type' => 'link', 'slug' => 'aq-dashboard', 'label' => 'Overview', 'icon' => 'dashboard'],
			['type' => 'group', 'label' => 'Content', 'icon' => 'edit', 'items' => [
				'aq-pages' => 'Pages', 'aq-media' => 'Media', 'aq-styles' => 'Styles', 'aq-navigation' => 'Navigation',
				'aq-footer' => 'Footer', 'aq-logo' => 'Logo', 'aq-legal' => 'Legal Pages',
			]],
			['type' => 'group', 'label' => 'SEO', 'icon' => 'search', 'items' => [
				'aq-seo' => 'SEO', 'aq-knowledge' => 'Knowledge', 'aq-seo-agent' => 'SEO Agent', 'aq-redirects' => 'Redirects',
			]],
			['type' => 'group', 'label' => 'Leads & Forms', 'icon' => 'email-alt', 'items' => [
				'aq-forms' => 'Forms', 'aq-submissions' => 'Submissions',
			]],
			['type' => 'group', 'label' => 'Analytics', 'icon' => 'chart-bar', 'items' => [
				'aq-form-analytics' => 'Form Analytics',
				'site-analytics'    => ['label' => 'Site Analytics', 'soon' => true],
				'aq-tracking'       => 'Tracking',
			]],
			['type' => 'link', 'slug' => 'aq-locations', 'label' => 'Locations/NAP', 'icon' => 'location'],
			['type' => 'link', 'slug' => 'aq-chatbot', 'label' => 'Chatbot', 'icon' => 'format-chat'],
			['type' => 'link', 'slug' => 'aq-assistant', 'label' => 'Assistant', 'icon' => 'admin-comments'],
			['type' => 'group', 'label' => 'Settings', 'icon' => 'admin-generic', 'items' => [
				'aq-integrations' => 'Integrations',
				'aq-performance' => 'Performance', 'aq-help' => 'Help',
			]],
		];
	}

	/** admin.php?page= URL for a nav slug (or a direct .php target like the CPT list). */
	private static function nav_url(string $slug): string {
		return strpos($slug, '.php') !== false ? admin_url($slug) : admin_url('admin.php?page=' . $slug);
	}

	private static function sidebar(string $current): void {
		echo '<nav class="aq-hub__nav" aria-label="AutoForge sections">';
		foreach (self::nav() as $entry) {
			if (($entry['type'] ?? '') === 'link') {
				$active = $entry['slug'] === $current;
				printf(
					'<a class="aq-nav__link%s" href="%s"><span class="dashicons dashicons-%s" aria-hidden="true"></span>%s</a>',
					$active ? ' aq-nav__link--active' : '',
					esc_url(self::nav_url($entry['slug'])),
					esc_attr($entry['icon'] ?? 'marker'),
					esc_html($entry['label'])
				);
				continue;
			}
			// group
			$items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
			$has_active = array_key_exists($current, $items);
			$gid = sanitize_key($entry['label']);
			printf('<details class="aq-nav__group" data-g="%s"%s>', esc_attr($gid), $has_active ? ' open' : '');
			printf(
				'<summary class="aq-nav__summary"><span class="dashicons dashicons-%s" aria-hidden="true"></span><span class="aq-nav__glabel">%s</span><span class="aq-nav__chev dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>',
				esc_attr($entry['icon'] ?? 'marker'),
				esc_html($entry['label'])
			);
			echo '<div class="aq-nav__sub">';
			foreach ($items as $slug => $val) {
				if (is_array($val)) { // placeholder (e.g. "soon")
					printf('<span class="aq-nav__link aq-nav__sublink aq-nav__link--soon">%s<span class="aq-nav__soon">Soon</span></span>', esc_html($val['label'] ?? $slug));
					continue;
				}
				$active = $slug === $current;
				printf(
					'<a class="aq-nav__link aq-nav__sublink%s" href="%s">%s</a>',
					$active ? ' aq-nav__link--active' : '',
					esc_url(self::nav_url($slug)),
					esc_html($val)
				);
			}
			echo '</div></details>';
		}
		echo '</nav>';
		self::sidebar_script();
	}

	/** Single-open accordion: the active item's group is open on load; opening any
	 *  group collapses the others. The last-opened group is remembered (localStorage)
	 *  for pages not inside any group. Dependency-free. */
	private static function sidebar_script(): void {
		?>
		<script>
		(function(){
			try{
				var KEY='aqHubNavOpen', nav=document.querySelector('.aq-hub__nav');
				if(!nav) return;
				var groups=[].slice.call(nav.querySelectorAll('details.aq-nav__group'));
				function closeOthers(except){ groups.forEach(function(d){ if(d!==except && d.open) d.open=false; }); }
				var active=groups.filter(function(d){ return d.querySelector('.aq-nav__link--active'); })[0];
				if(active){ active.open=true; closeOthers(active); }
				else {
					var remembered=null; try{remembered=localStorage.getItem(KEY);}catch(e){}
					groups.forEach(function(d){ d.open = (d.getAttribute('data-g')===remembered); });
				}
				groups.forEach(function(d){
					d.addEventListener('toggle', function(){
						if(d.open){ closeOthers(d); try{localStorage.setItem(KEY, d.getAttribute('data-g'));}catch(e){} }
					});
				});
			}catch(e){}
		})();
		</script>
		<?php
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

			/* two-column layout: accordion sidebar + main content */
			.aq-hub__layout { display:flex; gap:22px; align-items:flex-start; margin-top:18px; }
			.aq-hub__main { flex:1; min-width:0; }
			.aq-hub__nav { flex:0 0 216px; width:216px; position:sticky; top:46px; background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:8px; box-shadow:0 1px 2px rgba(13,16,20,.04); }
			.aq-hub__nav .dashicons { font-size:17px; width:17px; height:17px; line-height:1; flex:0 0 auto; }
			.aq-nav__link { display:flex; align-items:center; gap:9px; padding:8px 11px; border-radius:9px; color:#3a424d; text-decoration:none; font-size:13px; font-weight:500; line-height:1.25; }
			.aq-nav__link:hover { background:#f4f6f8; color:#0d1014; }
			.aq-nav__link:focus { outline:0; box-shadow:0 0 0 2px rgba(200,16,46,.35); }
			.aq-nav__link--active, .aq-nav__link--active:hover { background:#c8102e; color:#fff; }
			.aq-nav__group { margin:1px 0; }
			.aq-nav__summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:9px; padding:8px 11px; border-radius:9px; color:#5b6471; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; user-select:none; }
			.aq-nav__summary::-webkit-details-marker { display:none; }
			.aq-nav__summary:hover { background:#f4f6f8; color:#0d1014; }
			.aq-nav__glabel { flex:1; }
			.aq-nav__chev { transition:transform .15s ease; color:#9aa2ad; font-size:15px !important; width:15px !important; height:15px !important; }
			.aq-nav__group[open] > .aq-nav__summary .aq-nav__chev { transform:rotate(180deg); }
			.aq-nav__sub { padding:2px 0 6px; }
			.aq-nav__sublink { padding-left:37px; font-size:12.5px; }
			.aq-nav__link--soon { color:#9aa2ad; cursor:default; justify-content:space-between; }
			.aq-nav__link--soon:hover { background:transparent; color:#9aa2ad; }
			.aq-nav__soon { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; background:#eef1f5; color:#8a929c; padding:2px 6px; border-radius:999px; }
			@media (max-width:960px){ .aq-hub__layout{ flex-direction:column; } .aq-hub__nav{ position:static; width:auto; flex-basis:auto; } }

			.aq-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:16px; }
			.aq-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:18px 20px; box-shadow:0 1px 2px rgba(13,16,20,.04); }
			.aq-card__label { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; margin:0 0 8px; }
			.aq-card__num { font-family:Poppins, Inter, system-ui, sans-serif; font-size:30px; font-weight:700; line-height:1; color:#0d1014; }
			.aq-card__sub { font-size:12px; color:#5b6471; margin-top:8px; }
			.aq-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; }
			.aq-badge--ok { background:#eaf0ea; color:#1a8f4f; } .aq-badge--warn { background:#fdf1dd; color:#9a6212; } .aq-badge--off { background:#fbe7e7; color:#a30d25; }
			.aq-panel { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-top:20px; }
			.aq-hub__main > .aq-panel:first-child { margin-top:0; }
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
			/* Field help tooltip (AQ_Admin_Hub::tip). Pure CSS — reveals on hover or
			   keyboard focus, no JS. The bubble resets text-transform/letter-spacing/
			   weight because tips sit inside uppercase, letter-spaced field labels. */
			.aq-hub .aq-tip { position:relative; display:inline-flex; vertical-align:middle; margin-left:6px; cursor:help; }
			.aq-hub .aq-tip__icon { display:inline-flex; align-items:center; justify-content:center; width:15px; height:15px; border-radius:50%; background:#c9cfd6; color:#fff; font-size:10px; font-weight:700; font-family:Inter,system-ui,sans-serif; line-height:1; }
			.aq-hub .aq-tip:hover .aq-tip__icon, .aq-hub .aq-tip:focus .aq-tip__icon { background:#c8102e; }
			.aq-hub .aq-tip:focus { outline:0; }
			.aq-hub .aq-tip:focus .aq-tip__icon { box-shadow:0 0 0 3px rgba(200,16,46,.25); }
			.aq-hub .aq-tip__bubble { position:absolute; bottom:calc(100% + 8px); left:50%; transform:translateX(-50%); width:max-content; max-width:260px; background:#0d1014; color:#fff; font-size:12px; font-weight:400; line-height:1.5; text-transform:none; letter-spacing:normal; text-align:left; padding:8px 11px; border-radius:8px; box-shadow:0 4px 14px rgba(13,16,20,.22); opacity:0; visibility:hidden; transition:opacity .12s ease; z-index:9999; pointer-events:none; white-space:normal; }
			.aq-hub .aq-tip__bubble::after { content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:5px solid transparent; border-top-color:#0d1014; }
			.aq-hub .aq-tip:hover .aq-tip__bubble, .aq-hub .aq-tip:focus .aq-tip__bubble { opacity:1; visibility:visible; }
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

	/**
	 * Shared screen chrome for sub-screens (SEO, Locations, Performance, …).
	 * open() prints the wrap + styles + branded head, then a two-column layout:
	 * the accordion sidebar (highlighting $active_tab) and an open <main> that the
	 * caller fills with content. close() ends main + layout + wrap.
	 */
	public static function open(string $title, string $sub, string $active_tab = ''): void {
		echo '<div class="wrap aq-hub">';
		self::styles();
		self::head($title, $sub);
		echo '<div class="aq-hub__layout">';
		self::sidebar($active_tab);
		echo '<div class="aq-hub__main">';
		// Anchor for WordPress' admin-notice relocation: notices are auto-moved to
		// just before .wp-header-end, so they land here (inside the main column,
		// below the header) instead of being hoisted into the navy banner.
		echo '<hr class="wp-header-end" style="visibility:hidden;height:0;margin:0;border:0;padding:0;">';
	}

	public static function close(): void {
		echo '</div></div></div>'; // .aq-hub__main, .aq-hub__layout, .wrap.aq-hub
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
		self::open('Agency Dashboard', 'Manage content, SEO, locations and performance for the site.', 'aq-dashboard');
		echo '<div class="aq-cards">';
		self::card('Published Pages', (string) $s['published'], $s['draft'] . ' draft');
		self::card('Editable (structured)', $s['structured'] . ' / ' . $s['total'], $conv_pct . '% converted from raw HTML');
		self::card('SEO Complete', $seo_pct . '%', $s['seo'] . ' of ' . $s['total'] . ' pages have title + description');
		self::card('Service Areas', (string) $towns, 'towns in site config');
		self::card_html('Performance', $boost
			? '<span class="aq-badge aq-badge--ok">Boost active</span>'
			: '<span class="aq-badge aq-badge--off">Boost off</span>', 'Performance module — code-locked; clear cache from the admin bar');
		echo '</div>';

		echo '<div class="aq-panel"><h2>Quick actions</h2>';
		echo '<p><a class="aq-btn" href="' . esc_url(admin_url('admin.php?page=aq-pages')) . '">Manage pages &amp; editor</a> ';
		echo '<a class="aq-btn aq-btn--ghost" href="' . esc_url(admin_url('admin.php?page=aq-performance')) . '">Performance &amp; cache</a></p>';
		echo '<p style="color:#5b6471;font-size:13px;margin-top:14px;">Next up: the visual page editor (live preview + click-to-edit) and SEO manager.</p>';
		echo '</div>';
		self::close();
	}

	public static function render_pages(): void {
		// Editor mode: aq-pages&page_id=N renders the editor for that page.
		if (isset($_GET['page_id'])) {
			self::render_editor();
			return;
		}
		$pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => ['publish', 'draft'], 'orderby' => 'title', 'order' => 'ASC']);
		$have_acf = function_exists('get_field');
		self::open('Pages', 'Open a page in the editor to manage its content sections.', 'aq-pages');
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
		echo '</div></div>'; // .aq-pages-main, .aq-panel
		self::close();
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
			'aq-locations' => ['Locations/NAP', 'Manage service-area towns, counties and business info.'],
			'aq-performance' => ['Performance', 'PageSpeed scores, Core Web Vitals and cache controls.']];
		[$title, $sub] = $map[$screen] ?? ['Coming soon', ''];
		self::open($title, $sub, $screen);
		echo '<div class="aq-panel"><div class="aq-soon"><div class="aq-soon__icon dashicons dashicons-hammer"></div><p style="margin-top:10px;font-weight:600;">This screen is being built.</p><p>Tracked in the Phase 2 plan — wiring up next.</p></div></div>';
		self::close();
	}

	/* ---------------- field help tooltip ---------------- */

	/**
	 * Inline help tooltip for a settings field — a small "?" badge that reveals a
	 * plain-English explanation on hover or keyboard focus (pure CSS, no JS; styled
	 * in styles()). Shared by every AutoForge screen so field help reads and behaves
	 * identically everywhere. Place it right after a field's label text:
	 *
	 *   <label>Send to <?php echo AQ_Admin_Hub::tip('Where lead emails go.'); ?></label>
	 *
	 * $text is plain text (any HTML is escaped). Returns '' when empty, so callers
	 * can pass an optional/absent help string without guarding.
	 *
	 * @param string $text Short plain-English explanation for a non-technical owner.
	 * @return string Safe-to-echo markup, or '' if no text.
	 */
	public static function tip(string $text): string {
		$text = trim($text);
		if ($text === '') {
			return '';
		}
		return '<span class="aq-tip" tabindex="0" role="note" aria-label="' . esc_attr($text) . '">'
			. '<span class="aq-tip__icon" aria-hidden="true">?</span>'
			. '<span class="aq-tip__bubble">' . esc_html($text) . '</span>'
			. '</span>';
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
