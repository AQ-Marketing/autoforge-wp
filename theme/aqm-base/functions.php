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

add_action('wp_enqueue_scripts', function () {
	$css = get_theme_file_path('assets/css/main.css');
	wp_enqueue_style(
		'aqm-base',
		get_theme_file_uri('assets/css/main.css'),
		[],
		file_exists($css) ? (string) filemtime($css) : null
	);

	$js = get_theme_file_path('assets/js/site.js');
	wp_enqueue_script(
		'aqm-base',
		get_theme_file_uri('assets/js/site.js'),
		[],
		file_exists($js) ? (string) filemtime($js) : null,
		['in_footer' => true, 'strategy' => 'defer']
	);
});
