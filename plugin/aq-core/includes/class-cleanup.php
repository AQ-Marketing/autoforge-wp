<?php
/**
 * Strips WordPress's default head/markup bloat so output HTML stays as
 * clean as the Astro build: no emoji scripts, no block CSS, no
 * generator/oEmbed/feed tags, no auto-<p> mangling of stored HTML.
 */

class AQ_Cleanup {

	public static function register(): void {
		add_action('init', [__CLASS__, 'strip_head']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'dequeue_styles'], 100);
		add_filter('the_content', [__CLASS__, 'noop'], 0); // placeholder priority anchor
		add_filter('upload_mimes', [__CLASS__, 'allow_svg']);
		add_filter('wp_check_filetype_and_ext', [__CLASS__, 'fix_svg_filetype'], 10, 5);
		self::disable_texturizing();
		self::disable_features();
	}

	public static function noop($c) { return $c; }

	public static function strip_head(): void {
		// Emoji
		remove_action('wp_head', 'print_emoji_detection_script', 7);
		remove_action('wp_print_styles', 'print_emoji_styles');
		remove_action('admin_print_scripts', 'print_emoji_detection_script');
		remove_action('admin_print_styles', 'print_emoji_styles');
		add_filter('emoji_svg_url', '__return_false');

		// Generator / discovery cruft
		remove_action('wp_head', 'wp_generator');
		remove_action('wp_head', 'rsd_link');
		remove_action('wp_head', 'wlwmanifest_link');
		remove_action('wp_head', 'wp_shortlink_wp_head');
		remove_action('wp_head', 'feed_links', 2);
		remove_action('wp_head', 'feed_links_extra', 3);

		// oEmbed discovery + REST link header
		remove_action('wp_head', 'wp_oembed_add_discovery_links');
		remove_action('wp_head', 'wp_oembed_add_host_js');
		remove_action('wp_head', 'rest_output_link_wp_head');
		remove_action('template_redirect', 'rest_output_link_header', 11);

		// The SEO module owns the canonical tag.
		remove_action('wp_head', 'rel_canonical');

		// s.w.org dns-prefetch
		add_filter('wp_resource_hints', function ($hints, $relation) {
			if ($relation === 'dns-prefetch') {
				$hints = array_filter($hints, fn($h) => strpos((string) (is_array($h) ? ($h['href'] ?? '') : $h), 's.w.org') === false);
			}
			return $hints;
		}, 10, 2);
	}

	public static function dequeue_styles(): void {
		wp_dequeue_style('wp-block-library');
		wp_dequeue_style('wp-block-library-theme');
		wp_dequeue_style('global-styles');
		wp_dequeue_style('classic-theme-styles');
		wp_dequeue_style('core-block-supports');
		// Theme never enqueues jQuery; make sure nothing else drags it in on the front end.
		if (!is_admin()) {
			wp_dequeue_script('jquery');
		}
	}

	private static function disable_texturizing(): void {
		// Section HTML is stored exactly as authored; never reformat it.
		remove_filter('the_content', 'wpautop');
		remove_filter('the_content', 'wptexturize');
		remove_filter('the_excerpt', 'wpautop');
	}

	private static function disable_features(): void {
		// Global-styles inline CSS + SVG duotone filters
		remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
		remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
		remove_action('wp_footer', 'the_block_template_skip_link');

		// XML-RPC off
		add_filter('xmlrpc_enabled', '__return_false');

		// Comments are disabled site-wide by AQ_Comments (every surface, not
		// just the front end) — kept out of here so there's one owner.
	}

	public static function allow_svg(array $mimes): array {
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	}

	public static function fix_svg_filetype($data, $file, $filename, $mimes, $real_mime = '') {
		if (!empty($data['ext']) && !empty($data['type'])) {
			return $data;
		}
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if ($ext === 'svg' || $ext === 'svgz') {
			$data['ext']  = $ext;
			$data['type'] = 'image/svg+xml';
			$data['proper_filename'] = $filename;
		}
		return $data;
	}
}
