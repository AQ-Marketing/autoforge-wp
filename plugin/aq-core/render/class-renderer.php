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
			// Gallery display derivative for the aq_gallery section. Width-capped,
			// height free, no hard crop — the section's CSS handles the 4/3 frame.
			// The upload optimizer already caps masters at 1960; this just serves a
			// lighter 1600 derivative. Filterable so a site can tune it.
			add_image_size('aq_gallery', (int) apply_filters('aq_gallery_image_width', 1600), 0, false);
		});

		// Take over rendering. Priority 50 so it runs after most theme filters.
		add_filter('template_include', [__CLASS__, 'route'], 50);

		// Site-wide CTA tokens. Buffer the whole front-end page so the `{cta}` (label)
		// and `{cta_href}` (link) tokens resolve to the "Primary CTA" set on AutoForge →
		// Navigation, no matter where they appear — page sections OR header/footer/mega-
		// menu chrome. One global label + link, editable in one place, used by every
		// "Get your free audit"-style button.
		add_action('template_redirect', [__CLASS__, 'start_cta_buffer'], 1);

		// Plugin-owned scroll-reveal FAILSAFE. The theme's site.js drives the
		// entrance animation (adds `.reveal` = opacity:0, then `.reveal-in` when a
		// section scrolls in), but that theme asset is frozen per-site by the
		// updater — so a reliability fix there can't reach the fleet. This footer
		// script lives in the PLUGIN (updates with every release) and is purely
		// ADDITIVE: it only ever ADDS `.reveal-in` to stranded elements, so it
		// coexists with any version of the theme's reveal logic without competing
		// observers. Guarantees no content is ever left invisible.
		add_action('wp_footer', [__CLASS__, 'print_reveal_failsafe'], 99);
	}

	/**
	 * Print the reveal failsafe (see register()). Reveals the hero/first section
	 * immediately, honors prefers-reduced-motion, force-reveals anything still
	 * hidden ~1.2s after load, and re-reveals after a BFCache restore.
	 */
	public static function print_reveal_failsafe(): void {
		if (is_admin() || !apply_filters('aq_reveal_failsafe_enabled', true)) {
			return;
		}
		?>
<script>(function(){try{
var reduce=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
function revealAll(){var e=document.querySelectorAll('.reveal:not(.reveal-in)');for(var i=0;i<e.length;i++)e[i].classList.add('reveal-in');}
function revealHero(){var s=document.querySelector('section.hero,.hero')||document.querySelector('main > section, main > div > section');if(!s)return;if(s.classList.contains('reveal'))s.classList.add('reveal-in');var e=s.querySelectorAll('.reveal:not(.reveal-in)');for(var i=0;i<e.length;i++)e[i].classList.add('reveal-in');}
function run(){if(reduce){revealAll();return;}revealHero();setTimeout(revealAll,1200);}
if(document.readyState!=='loading')run();else document.addEventListener('DOMContentLoaded',run);
window.addEventListener('load',function(){setTimeout(revealAll,200);});
window.addEventListener('pageshow',function(ev){if(ev.persisted)revealAll();});
}catch(e){}})();</script>
		<?php
	}

	/**
	 * Begin buffering the front-end page so front-end CTA tokens can be resolved on
	 * the final HTML (see start()'s note). Skips admin, REST, and feeds. Mirrors the
	 * route() guard so it only wraps pages this renderer actually serves.
	 */
	public static function start_cta_buffer(): void {
		if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed() || !self::enabled()) {
			return;
		}
		ob_start([__CLASS__, 'resolve_cta_tokens']);
	}

	/**
	 * Resolve the site-wide Primary CTA tokens (AutoForge → Navigation) on the final
	 * page HTML:
	 *   {cta}      → the CTA button TEXT  (label)
	 *   {cta_href} → the CTA button LINK  (href)
	 * so a button written as `<a href="{cta_href}">{cta}</a>` shows the global
	 * wording AND points at the global link — both editable in one place. Each token
	 * falls back to the Header CTA's matching field so it never renders literally.
	 * The `{cta}` and `{cta_href}` strings never overlap (one ends at `}` right after
	 * `cta`, the other has `_href` first), so replacement order is irrelevant.
	 * Fast-pathed: does nothing unless a token is present. Runs on the whole page, so
	 * it covers chrome + sections.
	 *
	 * @param string $html Buffered page HTML.
	 * @return string
	 */
	public static function resolve_cta_tokens($html) {
		if (!is_string($html) || $html === '') {
			return $html;
		}
		$has_label = strpos($html, '{cta}') !== false;
		$has_href  = strpos($html, '{cta_href}') !== false;
		if (!$has_label && !$has_href) {
			return $html;
		}
		$primary = function_exists('aq_site') ? aq_site('primaryCta') : null;
		$header  = function_exists('aq_site') ? aq_site('headerCta') : null;
		$primary = is_array($primary) ? $primary : [];
		$header  = is_array($header) ? $header : [];

		if ($has_label) {
			$label = trim((string) ($primary['label'] ?? ''));
			if ($label === '') { $label = trim((string) ($header['label'] ?? '')); }
			$html = str_replace('{cta}', esc_html($label), $html);
		}
		if ($has_href) {
			$href = trim((string) ($primary['href'] ?? ''));
			if ($href === '') { $href = trim((string) ($header['href'] ?? '')); }
			if ($href === '') { $href = '/contact/'; }
			$html = str_replace('{cta_href}', esc_url($href), $html);
		}
		return $html;
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
		// Theme override: a site's theme may supply aq-parts/<name>.php to replace
		// engine chrome (header/footer) WITHOUT editing the shared plugin, so a
		// plugin update can never wipe a site's customizations. Falls back to the
		// engine's built-in part. Filter `aq_part_template` allows programmatic override.
		$tpl  = function_exists('locate_template') ? locate_template('aq-parts/' . $name . '.php') : '';
		$file = $tpl !== '' ? $tpl : AQ_CORE_DIR . 'render/parts/' . $name . '.php';
		$file = (string) apply_filters('aq_part_template', $file, $name);
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
			// Theme override: a site's theme may supply aq-sections/<layout>.php to
			// add or replace a section renderer WITHOUT editing the shared plugin —
			// so per-site custom sections survive plugin updates. Falls back to the
			// engine's built-in renderer. Filter `aq_section_template` for programmatic override.
			$tpl  = function_exists('locate_template') ? locate_template('aq-sections/' . $layout . '.php') : '';
			$file = $tpl !== '' ? $tpl : AQ_CORE_DIR . 'render/sections/' . $layout . '.php';
			$file = (string) apply_filters('aq_section_template', $file, $layout, $section);
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
