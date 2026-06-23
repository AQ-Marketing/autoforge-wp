<?php
/**
 * Plugin Name: AutoForge
 * Plugin URI: https://aqmarketing.com
 * Description: Client-agnostic WordPress platform — one plugin owns front-end rendering (structured sections, header/footer, the visual builder), site config (NAP/license), SEO meta + titles, JSON-LD, ACF section schema, robots, JSON content sync, and the embedded Boost performance module. Every site is driven entirely from its own data; the theme is a near-empty stub.
 * Version: 0.2.15
 * Requires PHP: 8.0
 * Author: AQ Marketing
 * Text Domain: aq-core
 */

if (!defined('ABSPATH')) {
	exit;
}

define('AQ_CORE_DIR', plugin_dir_path(__FILE__));
define('AQ_CORE_FILE', __FILE__);
define('AQ_CORE_VERSION', '0.2.15');

/**
 * Site-wide noindex posture, mirroring the Astro PUBLIC_NOINDEX behavior.
 * Resolution order (see aq_noindex_active()):
 *   1. `AQ_NOINDEX` constant in wp-config.php — a HARD override that locks the
 *      setting (use on staging/local to guarantee the site stays out of Google
 *      regardless of the database). When present, the dashboard control is
 *      shown but disabled.
 *   2. The `aq_noindex` option — the normal control, toggled from
 *      AutoForge → Performance → Search engine indexing.
 *   3. Default TRUE — an unconfigured install stays out of Google (staging-safe).
 */
if (!function_exists('aq_noindex_active')) {
function aq_noindex_active(): bool {
	if (defined('AQ_NOINDEX')) {
		return (bool) AQ_NOINDEX;
	}
	return (bool) get_option('aq_noindex', true);
}
}

if (!function_exists('aq_site')) {
function aq_site(?string $path = null) {
	static $cfg = null;
	if ($cfg === null) {
		// AQ_Site_Config overlays the editable wp_option on top of the file
		// defaults (Locations screen). Falls back to the raw file if called
		// before the class is loaded (defensive — requires run before hooks).
		$cfg = class_exists('AQ_Site_Config')
			? AQ_Site_Config::get()
			: require AQ_CORE_DIR . 'config/site.php';
	}
	if ($path === null) {
		return $cfg;
	}
	$val = $cfg;
	foreach (explode('.', $path) as $key) {
		if (!is_array($val) || !array_key_exists($key, $val)) {
			return null;
		}
		$val = $val[$key];
	}
	return $val;
}
}

if (!function_exists('aq_str')) {
/**
 * Scalar-safe config leaf accessor. Returns aq_site($path) coerced to a string,
 * or $default when the stored value is missing or a non-scalar (array/object).
 *
 * Render parts feed config values straight into esc_html()/esc_url()/esc_attr().
 * A mis-authored brand.json that writes a leaf (e.g. headerCta.label) as an
 * array would otherwise reach esc_html() and throw a TypeError mid-render —
 * blanking the page. Use aq_str() for any config value printed into markup.
 */
function aq_str(?string $path, string $default = ''): string {
	$v = $path === null ? null : aq_site($path);
	return is_scalar($v) ? (string) $v : $default;
}
}

require_once AQ_CORE_DIR . 'includes/class-site-config.php'; // load first: aq_site() overlay
require_once AQ_CORE_DIR . 'includes/class-cleanup.php';
require_once AQ_CORE_DIR . 'includes/class-comments.php';
require_once AQ_CORE_DIR . 'includes/class-seo-meta.php';
require_once AQ_CORE_DIR . 'includes/class-jsonld.php';
require_once AQ_CORE_DIR . 'includes/class-robots.php';
require_once AQ_CORE_DIR . 'includes/class-sitemap.php';
require_once AQ_CORE_DIR . 'includes/class-llms.php';
require_once AQ_CORE_DIR . 'includes/class-redirects.php';
require_once AQ_CORE_DIR . 'includes/class-content-sync.php';
require_once AQ_CORE_DIR . 'includes/class-admin-hub.php';
require_once AQ_CORE_DIR . 'includes/class-seo-manager.php';
require_once AQ_CORE_DIR . 'includes/class-locations.php';
require_once AQ_CORE_DIR . 'includes/class-performance.php';
require_once AQ_CORE_DIR . 'includes/class-editor.php';
require_once AQ_CORE_DIR . 'includes/class-assistant.php';
require_once AQ_CORE_DIR . 'includes/class-integrations.php';
require_once AQ_CORE_DIR . 'includes/class-importer.php';
require_once AQ_CORE_DIR . 'includes/class-global-styles.php';
require_once AQ_CORE_DIR . 'includes/class-navigation.php';
require_once AQ_CORE_DIR . 'includes/class-tracking.php';
require_once AQ_CORE_DIR . 'includes/class-page-folders.php';
require_once AQ_CORE_DIR . 'includes/class-seo-agent.php';
require_once AQ_CORE_DIR . 'includes/class-updater.php';
require_once AQ_CORE_DIR . 'includes/class-body-class.php';
require_once AQ_CORE_DIR . 'render/class-renderer.php';

/**
 * On (re)activation: ensure the post permalink structure and rebuild rewrite
 * rules, so blog-post URLs resolve immediately. Without this, reinstalling /
 * reactivating the plugin leaves stale rewrite rules and posts 404 until a
 * manual Settings → Permalinks → Save. Mirrors AQ_Content_Sync's permalink set.
 */
register_activation_hook(__FILE__, static function () {
	if (get_option('permalink_structure') !== '/%postname%/') {
		update_option('permalink_structure', '/%postname%/');
	}
	flush_rewrite_rules();
});

// Clear the SEO Agent's scheduled scan when the plugin is deactivated.
register_deactivation_hook(__FILE__, ['AQ_SEO_Agent', 'deactivate']);

AQ_Cleanup::register();
AQ_Comments::register();
AQ_SEO_Meta::register();
AQ_JsonLd::register();
AQ_Robots::register();
AQ_Sitemap::register();
AQ_LLMs::register();
AQ_Redirects::register();
AQ_Content_Sync::register();
AQ_Admin_Hub::register();
AQ_SEO_Manager::register();
AQ_Locations::register();
AQ_Performance::register();
AQ_Editor::register();
AQ_Assistant::register();
AQ_Integrations::register();
AQ_Importer::register();
AQ_Global_Styles::register();
AQ_Navigation::register();
AQ_Tracking::register();
AQ_Page_Folders::register();
AQ_SEO_Agent::register();
AQ_Updater::register();
AQ_Body_Class::register();
AQ_Renderer::register();

/**
 * Boost by AQM — embedded, self-contained performance module (Pressable edition).
 * Lives in thrust/. The whole bootstrap is wrapped so a module failure degrades
 * to an admin notice instead
 * of taking the site down (parse/Error throwables from required files are
 * catchable in PHP 7+).
 */
if (!defined('AQ_BOOST_DISABLE') || !AQ_BOOST_DISABLE) {
	try {
		require_once AQ_CORE_DIR . 'thrust/wp-rocket.php';
	} catch (\Throwable $aq_boost_error) {
		add_action('admin_notices', function () use ($aq_boost_error) {
			echo '<div class="notice notice-error"><p><strong>AQ Core:</strong> the Boost performance module failed to load and has been skipped. '
				. esc_html($aq_boost_error->getMessage()) . '</p></div>';
		});
	}

	/**
	 * Boost runs as an embedded module, so WP Rocket's activation routine — which
	 * normally grants the administrator the custom `rocket_manage_options`
	 * capability — never fires. Without it, Settings → Boost and the admin-bar
	 * cache controls return "Sorry, you are not allowed to access this page."
	 * Map every Boost capability onto anyone who can manage_options: no DB writes,
	 * effective on the current request, and cleanly revoked if this filter is removed.
	 */
	add_filter('user_has_cap', function ($allcaps) {
		if (!empty($allcaps['manage_options'])) {
			foreach ([
				'rocket_manage_options', 'rocket_purge_cache', 'rocket_purge_posts',
				'rocket_purge_terms', 'rocket_purge_users', 'rocket_purge_cloudflare_cache',
				'rocket_purge_sucuri_cache', 'rocket_preload_cache',
				'rocket_regenerate_critical_css', 'rocket_remove_unused_css',
			] as $aq_boost_cap) {
				$allcaps[$aq_boost_cap] = true;
			}
		}
		return $allcaps;
	});

	/**
	 * Boost is the self-contained Pressable edition — trim the settings nav to
	 * only what is functional on Pressable. Runs late so it also removes anything
	 * the PluginFamily controller re-adds.
	 *
	 * Two groups are removed:
	 *
	 * 1. Upstream PROMO sections that point at third-party services — Image
	 *    Optimization/Imagify, Tutorials videos, "Our Plugins" (WP Media), Add-ons.
	 *
	 * 2. PAGE-CACHE / CDN sections that are inert or counter-productive on
	 *    Pressable, whose docs are explicit here:
	 *      • 'advanced_cache' ("Advanced Rules") — Cache Lifespan + every
	 *        page-cache rule (never-cache URLs, cache cookies/query strings,
	 *        purge URLs). Pressable serves pages from Batcache + Edge Cache and
	 *        the embedded Boost already disables WP Rocket's own page cache
	 *        (see ThirdParty\Hostings\Pressable: no advanced-cache.php, no
	 *        WP_CACHE, no caching files), so the whole tab is dead UI. Pressable
	 *        cache is purged via WP Admin → Settings → Edge Cache.
	 *      • 'page_cdn' ("CDN") — Pressable bundles a 28-PoP Edge Cache CDN and
	 *        explicitly recommends AGAINST stacking a third-party CDN on top of
	 *        it; Boost already registers Pressable's own CDN cname internally.
	 *        Exposing the CDN tab only invites a conflicting/​slower setup.
	 *
	 * Left intact: File Optimization, Media (lazyload/fonts), Preload (link/font
	 * preloading), Database, Heartbeat — all host-agnostic and useful here.
	 */
	add_filter('rocket_settings_menu_navigation', function ($navigation) {
		unset(
			// 1. promo / third-party
			$navigation['imagify'],
			$navigation['tutorials'],
			$navigation['plugins'],
			$navigation['addons'],
			// 2. page-cache + CDN: non-functional / discouraged on Pressable
			$navigation['advanced_cache'],
			$navigation['page_cdn']
		);
		return $navigation;
	}, 100);

	/**
	 * Safety net for residual brand text: some upstream option descriptions and
	 * notices still read "WP Rocket", "RocketCDN", "Rocket Analytics" or
	 * "Rocketeers". Rewrite the visible output of the 'rocket' text-domain so
	 * nothing user-facing references the upstream product. This filters
	 * TRANSLATED STRINGS ONLY — code identifiers, hook names and option keys are
	 * never touched, and lowercase technical strings (e.g. the docs.wp-rocket.me
	 * URLs, which contain no capital "Rocket") are skipped by the guard below.
	 *
	 * Replacement order matters: longer/more specific tokens are listed before
	 * the bare "Rocket" catch-all so "RocketCDN" → "Boost CDN" (not "BoostCDN")
	 * and "Rocketeers" → "the Boost team" (not "Boosteers").
	 */
	$aq_boost_brandwash = function ($translation, $text = '', $a = '', $b = '') {
		$domain = func_num_args() >= 4 ? $b : $a; // gettext: 3rd arg; gettext_with_context: 4th arg
		if ($domain === 'rocket' && is_string($translation) && strpos($translation, 'Rocket') !== false) {
			$translation = str_replace(
				['WP Rocket', 'RocketCDN', 'Rocketeers', 'Rocketeer', 'Rocket'],
				['Boost',     'Boost CDN', 'the Boost team', 'the Boost team', 'Boost'],
				$translation
			);
		}
		return $translation;
	};
	add_filter('gettext', $aq_boost_brandwash, 20, 3);
	add_filter('gettext_with_context', $aq_boost_brandwash, 20, 4);
}

// ACF section schema (field groups registered in PHP — diffable, repo-owned).
add_action('acf/init', function () {
	require_once AQ_CORE_DIR . 'includes/fields/sections.php';
});

// Warn loudly if ACF is missing — sections cannot render without it.
add_action('admin_notices', function () {
	if (!function_exists('acf_add_local_field_group')) {
		echo '<div class="notice notice-error"><p><strong>AQ Core:</strong> ACF Pro (or Secure Custom Fields) is not active. Page sections will not render or import.</p></div>';
	}
});

/**
 * Future AI-alignment outputs (llms.txt, markdown exports, entity JSON)
 * hook in here — deferred to the agency platform phase.
 */
add_action('init', function () {
	do_action('aq_core_machine_outputs');
}, 20);
