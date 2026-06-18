<?php
/**
 * WP core sitemap tuning (no Yoast/RankMath):
 *  - drop the users + taxonomies sub-sitemaps (no public authors/categories)
 *  - exclude pages that must stay out of the index:
 *      • noindex pages (thank-you, contact, schedule, legal, reviews…)
 *      • pages whose canonical points elsewhere — the city × service pages
 *        canonicalize to their parent service page, so they must not appear
 *        in the sitemap and compete with the canonical service page.
 */

class AQ_Sitemap {

	public static function register(): void {
		add_filter('wp_sitemaps_add_provider', [__CLASS__, 'drop_providers'], 10, 2);
		add_filter('wp_sitemaps_posts_query_args', [__CLASS__, 'exclude_pages'], 10, 2);
		add_action('template_redirect', [__CLASS__, 'redirect_legacy'], 0);
	}

	/**
	 * 301 legacy sitemap-index URLs to WordPress core's sitemap.
	 *
	 * The previous (Astro) site published /sitemap-index.xml; WP core serves
	 * /wp-sitemap.xml instead (core already redirects /sitemap.xml there too),
	 * so the old index URLs 404 to an HTML page — a search engine that still has
	 * the old URL submitted then sees a broken sitemap. Redirect them to the
	 * real XML so the submission keeps resolving. Priority 0 so it runs before
	 * AQ_Renderer's template_include router and the 404 page never renders.
	 */
	public static function redirect_legacy(): void {
		$path = strtolower((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
		$path = '/' . trim($path, '/');
		if ($path === '/sitemap-index.xml' || $path === '/sitemap_index.xml') {
			wp_safe_redirect(home_url('/wp-sitemap.xml'), 301);
			exit;
		}
	}

	public static function drop_providers($provider, $name) {
		if (in_array($name, ['users', 'taxonomies'], true)) {
			return false;
		}
		return $provider;
	}

	public static function exclude_pages($args, $post_type) {
		if ($post_type !== 'page') {
			return $args;
		}
		$exclude = self::excluded_ids();
		if ($exclude) {
			$args['post__not_in'] = array_merge($args['post__not_in'] ?? [], $exclude);
		}
		return $args;
	}

	/** Page IDs that should be omitted from the sitemap. */
	private static function excluded_ids(): array {
		$ids = get_posts([
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		]);
		$exclude = [];
		foreach ($ids as $pid) {
			if (self::is_noindex((int) $pid) || self::is_city_service((int) $pid)) {
				$exclude[] = (int) $pid;
			}
		}
		return $exclude;
	}

	/**
	 * Read noindex straight from post meta (ACF stores the value under the
	 * field name) — robust regardless of whether ACF has fully initialised
	 * during the sitemap request.
	 */
	private static function is_noindex(int $pid): bool {
		return (bool) get_post_meta($pid, 'seo_noindex', true);
	}

	/**
	 * City × service pages live two levels under the `service-area` page
	 * (/service-area/{city}/{service}/). They canonicalize to the parent
	 * service page, so they must stay out of the sitemap. Detected by page
	 * ancestry — deterministic, unlike comparing canonical vs permalink.
	 */
	private static function is_city_service(int $pid): bool {
		$ancestors = get_post_ancestors($pid);
		return count($ancestors) === 2
			&& get_post_field('post_name', (int) end($ancestors)) === 'service-area';
	}
}
