<?php
/**
 * AQM Base — client-agnostic stub theme.
 *
 * Rendering (sections, header/footer chrome, the visual builder, image sizes,
 * the LCP hero preload) lives in the AutoForge plugin (AQ_Renderer), which
 * takes over via the template_include hook. This theme's only job is to enqueue
 * the per-client compiled assets that the content import delivers into
 * assets/css/main.css and assets/js/site.js.
 *
 * The PHP here is identical on every client; only the compiled CSS differs.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Soft fallback when the AutoForge plugin is inactive: pages keep loading
 * (with blank business values) instead of fataling. The plugin's own aq_site()
 * wins when active because plugins load before the theme.
 */
if (!function_exists('aq_site')) {
	function aq_site(?string $path = null) {
		return null;
	}
}

/**
 * AQM design block pack — registers this theme's interior-page section types into
 * the AutoForge engine via the aq_field_schema / aq_layout_labels / aq_field_order
 * filters (markup in render-sections/). Required at theme load so the filters are
 * in place before acf/init and the visual editor read the registries.
 */
require_once __DIR__ . '/blocks/aqm-blocks.php';
require_once __DIR__ . '/admin-blog-settings.php';

add_action('wp_enqueue_scripts', function () {
	$css = get_theme_file_path('assets/css/main.css');
	wp_enqueue_style(
		'aqm-base',
		get_theme_file_uri('assets/css/main.css'),
		[],
		file_exists($css) ? (string) filemtime($css) : null
	);

	// Self-hosted JS libraries for the animated sections.
	// Registered + enqueued only when their files are present, so on clients
	// that don't ship the vendor bundle this whole block is a no-op. three.js
	// is loaded site-wide for now; perf could later split it to body.about only.
	$vendor = [
		'gsap'              => ['file' => 'gsap.min.js',          'deps' => []],
		'gsap-scrolltrigger'=> ['file' => 'ScrollTrigger.min.js', 'deps' => ['gsap']],
		'three'             => ['file' => 'three.min.js',          'deps' => []],
	];
	$site_deps = [];
	foreach ($vendor as $handle => $lib) {
		$path = get_theme_file_path('assets/vendor/' . $lib['file']);
		if (!file_exists($path)) {
			continue;
		}
		wp_enqueue_script(
			$handle,
			get_theme_file_uri('assets/vendor/' . $lib['file']),
			$lib['deps'],
			(string) filemtime($path),
			['in_footer' => true]
		);
		$site_deps[] = $handle;
	}

	$js = get_theme_file_path('assets/js/site.js');
	wp_enqueue_script(
		'aqm-base',
		get_theme_file_uri('assets/js/site.js'),
		$site_deps,
		file_exists($js) ? (string) filemtime($js) : null,
		['in_footer' => true, 'strategy' => 'defer']
	);
});

/**
 * Blog templates are per-design. The engine (plugin) routes single posts and
 * archives to its OWN templates (priority 50), which use a different design
 * system. This theme ships AQM-styled blog templates and overrides that routing
 * for posts + archives ONLY, by hooking template_include at a later priority.
 * Pages still flow through the plugin's section renderer untouched, so nothing
 * else changes. Honors AQ_RENDER_DISABLE and leaves admin/REST/feed alone.
 */
add_filter('template_include', function ($template) {
	if (defined('AQ_RENDER_DISABLE') && AQ_RENDER_DISABLE) {
		return $template;
	}
	if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
		return $template;
	}
	if (is_singular('post')) {
		$t = __DIR__ . '/render-templates/single-post.php';
		return is_readable($t) ? $t : $template;
	}
	if (is_home() || is_category() || is_tag() || is_author() || is_date() || is_search()) {
		$t = __DIR__ . '/render-templates/archive.php';
		return is_readable($t) ? $t : $template;
	}
	return $template;
}, 60);
