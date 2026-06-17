<?php
/**
 * Comments are disabled site-wide. AutoForge sites are brochure/marketing
 * sites with no comment threads, so every comment surface is switched off:
 * front-end (nothing open, existing threads render empty), comment/trackback
 * support on all post types, the comments REST routes, the comment feeds, the
 * X-Pingback header, and the admin chrome (Comments menu, Discussion settings,
 * dashboard Activity comments, admin-bar bubble). This class is the single
 * source of truth for "no comments" — it supersedes the partial logic that
 * used to live in AQ_Cleanup.
 */

class AQ_Comments {

	public static function register(): void {
		// Front-end behavior: nothing is ever open, and any pre-existing
		// thread renders as zero comments.
		add_filter('comments_open', '__return_false', 20);
		add_filter('pings_open', '__return_false', 20);
		add_filter('comments_array', '__return_empty_array', 20);

		// Drop the X-Pingback response header outright.
		add_filter('wp_headers', [__CLASS__, 'remove_pingback_header']);

		// Pull comment + trackback support off every post type.
		add_action('init', [__CLASS__, 'remove_post_type_support'], 100);

		// Take the comments routes out of the REST API.
		add_filter('rest_endpoints', [__CLASS__, 'remove_rest_routes']);

		// Comment feeds (/comments/feed/ and per-post comment feeds) are live
		// endpoints even with the <head> discovery links stripped — send them home
		// so no comment XML is ever served.
		add_action('template_redirect', [__CLASS__, 'block_comment_feeds'], 1);

		// Remove the "Comments" bubble from the admin bar (front and back).
		add_action('admin_bar_menu', [__CLASS__, 'remove_admin_bar_node'], 999);

		// Admin-only chrome.
		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'block_comment_screens']);
			add_action('admin_menu', [__CLASS__, 'remove_admin_menu']);
			add_action('wp_dashboard_setup', [__CLASS__, 'remove_dashboard_widget']);
			// Empty every comment query in wp-admin. The dashboard "Activity"
			// widget lists recent/pending comments via a direct get_comments()
			// call that the front-end comments_array filter never touches, so
			// suppress it (and any other admin comment list) at the query layer.
			add_filter('comments_pre_query', [__CLASS__, 'empty_admin_comments'], 10, 2);
		}
	}

	/** Strip the X-Pingback header WordPress adds on singular views. */
	public static function remove_pingback_header($headers) {
		if (is_array($headers)) {
			unset($headers['X-Pingback']);
		}
		return $headers;
	}

	/** Remove comment/trackback support from every registered post type. */
	public static function remove_post_type_support(): void {
		foreach (get_post_types() as $type) {
			if (post_type_supports($type, 'comments')) {
				remove_post_type_support($type, 'comments');
			}
			if (post_type_supports($type, 'trackbacks')) {
				remove_post_type_support($type, 'trackbacks');
			}
		}
	}

	/** Unregister every /wp/v2/comments REST route. */
	public static function remove_rest_routes($endpoints) {
		if (!is_array($endpoints)) {
			return $endpoints;
		}
		foreach (array_keys($endpoints) as $route) {
			if (strpos($route, '/wp/v2/comments') === 0) {
				unset($endpoints[$route]);
			}
		}
		return $endpoints;
	}

	/** Remove the admin-bar comments node. */
	public static function remove_admin_bar_node($bar): void {
		if (is_object($bar) && method_exists($bar, 'remove_node')) {
			$bar->remove_node('comments');
		}
	}

	/** Bounce direct hits on the comment management screens to the dashboard. */
	public static function block_comment_screens(): void {
		global $pagenow;
		if (in_array($pagenow, ['edit-comments.php', 'options-discussion.php'], true)) {
			wp_safe_redirect(admin_url());
			exit;
		}
	}

	/** Drop the Comments menu and the Discussion settings submenu. */
	public static function remove_admin_menu(): void {
		remove_menu_page('edit-comments.php');
		remove_submenu_page('options-general.php', 'options-discussion.php');
	}

	/** Drop the legacy (pre-3.8) standalone "Recent Comments" dashboard widget. */
	public static function remove_dashboard_widget(): void {
		remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
	}

	/**
	 * Short-circuit admin comment queries to an empty result. Count queries get
	 * 0; everything else gets an empty list. Keeps the Activity widget and any
	 * admin comment listing blank without disturbing front-end queries.
	 */
	public static function empty_admin_comments($results, $query) {
		return !empty($query->query_vars['count']) ? 0 : [];
	}

	/** Comment feeds serve no purpose with comments off — redirect them home. */
	public static function block_comment_feeds(): void {
		if (is_comment_feed()) {
			wp_safe_redirect(home_url('/'), 301);
			exit;
		}
	}
}
