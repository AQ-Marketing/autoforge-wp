<?php
/**
 * SEO head output. Title pattern "{title} | {brand suffix}", description,
 * canonical, robots (per-page noindex OR site-wide AQ_NOINDEX), Open Graph,
 * Twitter, viewport/theme-color, favicons.
 *
 * Brand-specific values are client config (aq_site): the title suffix is
 * aq_site('seoSuffix') (falling back to aq_site('shortName')) and the
 * theme-color is aq_site('themeColor'). Per-page values come from ACF fields
 * (seo_title, seo_description, seo_canonical, seo_noindex, seo_og_image)
 * imported from the client's page JSON.
 */

class AQ_SEO_Meta {

	public static function register(): void {
		add_filter('pre_get_document_title', [__CLASS__, 'title'], 20);
		add_action('wp_head', [__CLASS__, 'print_head'], 1);
	}

	private static function field(string $name): ?string {
		if (!function_exists('get_field') || !is_singular()) {
			return null;
		}
		$v = get_field($name, get_queried_object_id());
		return is_string($v) && $v !== '' ? $v : (is_bool($v) ? ($v ? '1' : '') : null);
	}

	public static function title($default) {
		$title = self::field('seo_title');
		if (!$title) {
			$title = is_singular() ? get_the_title(get_queried_object_id()) : (string) $default;
		}
		// Prefer an explicit seoSuffix; fall back to the brand short name.
		$suffix = (string) (aq_site('seoSuffix') ?: aq_site('shortName'));
		if ($suffix === '') {
			return $title;
		}
		// Only append the brand if not already present.
		return str_contains($title, $suffix) ? $title : "{$title} | {$suffix}";
	}

	public static function print_head(): void {
		$base = rtrim((string) aq_site('url'), '/');

		$description = self::field('seo_description') ?? '';
		$canonical   = self::field('seo_canonical');
		if (!$canonical) {
			$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
			$canonical = $base . $path;
		}

		$page_noindex = false;
		if (function_exists('get_field') && is_singular()) {
			$page_noindex = (bool) get_field('seo_noindex', get_queried_object_id());
		}
		$noindex = $page_noindex || aq_noindex_active();

		$og_image = self::field('seo_og_image') ?: $base . '/og-default.jpg';
		if (!str_starts_with($og_image, 'http')) {
			$og_image = $base . $og_image;
		}

		$title = self::title('');

		echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
		echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
		if ($noindex) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		} else {
			echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1" />' . "\n";
		}

		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />' . "\n";
		$theme_color = (string) (aq_site('themeColor') ?: '#445b44');
		echo '<meta name="theme-color" content="' . esc_attr($theme_color) . '" />' . "\n";

		// Open Graph
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url($canonical) . '" />' . "\n";
		echo '<meta property="og:image" content="' . esc_url($og_image) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr((string) aq_site('shortName')) . '" />' . "\n";
		echo '<meta property="og:locale" content="en_US" />' . "\n";

		// Twitter
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url($og_image) . '" />' . "\n";

		// Favicons (copied from the Astro public/ folder into the site root)
		echo '<link rel="icon" type="image/svg+xml" href="/favicon.svg" />' . "\n";
		echo '<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />' . "\n";
	}
}
