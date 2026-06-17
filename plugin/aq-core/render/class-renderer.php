<?php
/**
 * AQ_Renderer — the plugin owns front-end rendering (the "Breakdance model").
 *
 * WordPress still needs an active theme, but it is now a near-empty stub
 * (theme/aqm-base). All real rendering — the section loop, the section
 * templates, the site header/footer chrome, and the LCP hero preload — lives
 * here in the plugin. AQ_Renderer hooks `template_include` and serves its own
 * page/single/index templates, which emit the chrome and run the section loop.
 *
 * Kill switch: define('AQ_RENDER_DISABLE', true) in wp-config.php to hand
 * rendering back to the active theme's own templates (mirrors AQ_BOOST_DISABLE).
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Renderer {

	public static function register(): void {
		// Helpers + the LCP preload hook must exist before any template runs.
		require_once AQ_CORE_DIR . 'render/helpers.php';
		require_once AQ_CORE_DIR . 'render/hero-preload.php';

		if (!self::enabled()) {
			return;
		}

		// Image sizes + theme supports are now plugin-owned so any active theme
		// (including the bare aqm-base stub) gets the breakpoints ka_picture and
		// the hero preload depend on. Matches the Astro site's breakpoints.
		add_action('after_setup_theme', function () {
			add_theme_support('title-tag');
			add_theme_support('post-thumbnails');
			add_image_size('ka-480', 480, 9999);
			add_image_size('ka-768', 768, 9999);
			add_image_size('ka-1280', 1280, 9999);
		});

		// Take over rendering. Priority 50 so it runs after most theme filters.
		add_filter('template_include', [__CLASS__, 'route'], 50);
	}

	/**
	 * Rendering is on unless explicitly disabled in wp-config.php. When off,
	 * `template_include` is never filtered and WordPress falls back to the
	 * active theme's own page/single/index templates.
	 */
	public static function enabled(): bool {
		if (defined('AQ_RENDER_DISABLE') && AQ_RENDER_DISABLE) {
			return false;
		}
		return (bool) apply_filters('aq_render_enabled', true);
	}

	/**
	 * Route each front-end request to a plugin-side template. Admin, REST, and
	 * feeds are left alone. 404 / search / archive / home all fall through to
	 * the generic index template.
	 */
	public static function route($template) {
		if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
			return $template;
		}
		if (is_singular('post')) {
			return AQ_CORE_DIR . 'render/templates/single.php';
		}
		if (is_singular()) {
			return AQ_CORE_DIR . 'render/templates/page.php';
		}
		return AQ_CORE_DIR . 'render/templates/index.php';
	}

	/** get_header() replacement — the chrome moved out of the theme. */
	public static function head_open(): void {
		include AQ_CORE_DIR . 'render/parts/head-open.php';
	}

	/** get_footer() replacement. */
	public static function body_close(): void {
		include AQ_CORE_DIR . 'render/parts/body-close.php';
	}

	/**
	 * Include a plugin render part by basename, optionally passing $args (the
	 * same contract get_template_part()'s third parameter provided — the part
	 * reads $args['…']). Used for site chrome and for sub-parts like post-card.
	 */
	public static function part(string $name, array $args = []): void {
		$file = AQ_CORE_DIR . 'render/parts/' . $name . '.php';
		if (is_readable($file)) {
			self::include_section($file, $args);
		}
	}

	/**
	 * Render a page's ACF flexible-content sections.
	 * Layout name `why_overview` maps to render/sections/why-overview.php.
	 *
	 * In the visual-editor canvas (aq-core sets the aq_render_section_markers filter),
	 * tag each section's first rendered element with data-aq-section="N" so
	 * click-to-select can map a clicked node back to its section. Off by
	 * default → zero production markup.
	 */
	public static function render_sections(int $post_id): void {
		if (!function_exists('get_field')) {
			return;
		}
		$sections = get_field('sections', $post_id);
		if (!is_array($sections)) {
			return;
		}
		$mark = apply_filters('aq_render_section_markers', false);
		foreach ($sections as $i => $section) {
			$layout = str_replace('_', '-', (string) ($section['acf_fc_layout'] ?? ''));
			if ($layout === '') {
				continue;
			}
			$file = AQ_CORE_DIR . 'render/sections/' . $layout . '.php';
			if (!is_readable($file)) {
				continue;
			}
			if (!$mark) {
				self::include_section($file, ['s' => $section]);
				continue;
			}
			ob_start();
			self::include_section($file, ['s' => $section]);
			$html = (string) ob_get_clean();
			// Inject the marker attribute into the first opening tag of the output.
			$attr = ' data-aq-section="' . (int) $i . '" data-aq-layout="' . esc_attr((string) ($section['acf_fc_layout'] ?? '')) . '"';
			$html = preg_replace('/<([a-zA-Z][a-zA-Z0-9-]*)(\s|>)/', '<$1' . $attr . '$2', $html, 1);
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — section template already escapes; we only inserted a data attr
		}
	}

	/**
	 * Include a section template in an isolated scope. Section templates read
	 * $args['s'] (the same contract get_template_part() provided), so we expose
	 * exactly that and nothing else from the renderer's internals.
	 */
	private static function include_section(string $__aq_file, array $args): void {
		include $__aq_file;
	}
}
