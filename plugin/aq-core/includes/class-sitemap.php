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
