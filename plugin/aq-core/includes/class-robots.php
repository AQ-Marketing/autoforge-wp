<?php
/**
 * robots.txt — mirrors the Astro repo's dynamic robots.txt.ts:
 * off production (staging/local — see aq_noindex_active()) the whole site is
 * disallowed; in production it allows everything except wp-admin and points
 * at the sitemap.
 */

class AQ_Robots {

	public static function register(): void {
		add_filter('robots_txt', [__CLASS__, 'output'], 10, 2);
	}

	public static function output($output, $public): string {
		$base = rtrim((string) aq_site('url'), '/');

		if (aq_noindex_active()) {
			return "User-agent: *\nDisallow: /\n";
		}

		return "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nDisallow: /wp-json/\n\nSitemap: {$base}/wp-sitemap.xml\n";
	}
}
