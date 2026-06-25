<?php
/**
 * Legacy-URL 301 redirects.
 *
 * The engine ships with NO redirects — legacy-URL maps are PER-CLIENT data, not
 * engine code. A client migrating from old URLs supplies its own from→to pairs
 * as DATA via the content repo's brand.json (`redirects` key), which the
 * importer lands in the aq_site_config option; map() reads aq_site('redirects').
 * Applied on template_redirect; pure PHP, no Redirection plugin.
 */

class AQ_Redirects {

	public static function register(): void {
		add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 1);
	}

	public static function maybe_redirect(): void {
		$map = self::map();
		if (!$map) {
			return; // no redirects configured for this site
		}
		$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
		if ($path === '') {
			return; // home
		}
		$key = $path . '/';
		if (isset($map[$key])) {
			wp_redirect(home_url($map[$key]), 301);
			exit;
		}
	}

	/**
	 * from (with trailing slash) => to. Empty in the engine; per-client data
	 * delivered via brand.json → aq_site_config → aq_site('redirects').
	 */
	private static function map(): array {
		$map = function_exists('aq_site') ? aq_site('redirects') : null;
		return is_array($map) ? $map : [];
	}
}
