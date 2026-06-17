<?php
/**
 * Rendering helpers — moved out of the per-client theme so the engine lives in
 * the plugin (the theme is now a near-empty stub). Every function is
 * function_exists-guarded so a theme that still defines its own copy (during a
 * migration) does not fatal; the plugin loads first, so its definition wins.
 *
 *   ka_picture()        — <img> with WP-native srcset from the media library
 *   ka_picture_field()  — convenience wrapper for ACF image fields
 *   ka_is_editing()     — true inside the AQ visual-editor canvas
 *   ka_field_attr()     — element-level edit marker (empty string on live site)
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('ka_picture')) {
	/**
	 * Render an image from the media library.
	 *
	 * @param int   $attachment_id WP attachment post ID.
	 * @param array $opts {
	 *   size:          string WP image size name. Default 'full'.
	 *   sizes:         string HTML sizes attribute. Default "(min-width: 1024px) 50vw, 100vw".
	 *   class:         string Class list for the <img>.
	 *   loading:       string "lazy" (default) or "eager".
	 *   fetchpriority: string e.g. "high" for the LCP hero image.
	 *   alt:           string optional alt override; falls back to the
	 *                  attachment's own alt text when blank.
	 * }
	 */
	function ka_picture(int $attachment_id, array $opts = []): string {
		if (!$attachment_id) {
			return '';
		}

		$size  = $opts['size'] ?? 'full';
		$sizes = $opts['sizes'] ?? '(min-width: 1024px) 50vw, 100vw';

		$attr = ['sizes' => $sizes];

		if (!empty($opts['class'])) {
			$attr['class'] = $opts['class'];
		}

		// Optional alt override; when absent, wp_get_attachment_image uses the
		// attachment's own alt text. Only set when explicitly provided.
		if (isset($opts['alt']) && $opts['alt'] !== '') {
			$attr['alt'] = $opts['alt'];
		}

		$attr['loading'] = $opts['loading'] ?? 'lazy';

		if ($attr['loading'] === 'lazy') {
			$attr['decoding'] = 'async';
		}

		if (!empty($opts['fetchpriority'])) {
			$attr['fetchpriority'] = $opts['fetchpriority'];
		}

		return wp_get_attachment_image($attachment_id, $size, false, $attr);
	}
}

if (!function_exists('ka_picture_field')) {
	/**
	 * Convenience wrapper for ACF image fields.
	 *
	 * Accepts an attachment ID (int — ACF return format "id"), an ACF image
	 * array (return format "array", keyed on 'ID'), or null/empty.
	 */
	function ka_picture_field($image, array $opts = []): string {
		$id = 0;
		if (is_numeric($image) && (int) $image > 0) {
			$id = (int) $image;
		} elseif (is_array($image) && !empty($image['ID'])) {
			$id = (int) $image['ID'];
		}
		if (!$id) {
			return '';
		}
		return ka_picture($id, $opts);
	}
}

if (!function_exists('ka_is_editing')) {
	/**
	 * Is the page being rendered inside the AQ visual-editor canvas? Mirrors the
	 * marker flag set by AQ_Editor::maybe_canvas(). Cached per request.
	 */
	function ka_is_editing(): bool {
		static $on = null;
		if ($on === null) {
			$on = (bool) apply_filters('aq_render_section_markers', false);
		}
		return $on;
	}
}

if (!function_exists('ka_field_attr')) {
	/**
	 * Element-level edit marker for the visual editor. Echo this inside an opening
	 * tag to mark the element that renders a given field, so clicking it in the
	 * canvas jumps the inspector to that field. Returns an EMPTY string on the live
	 * front end (markers off) → zero production markup, pixel parity preserved.
	 *
	 * Top-level field:   <h2<?php echo ka_field_attr('heading'); ?>>
	 * Repeater item:     <article<?php echo ka_field_attr('cards', $i); ?>>
	 * Repeater subfield: <h3<?php echo ka_field_attr('title'); ?>>
	 */
	function ka_field_attr(string $field, ?int $rindex = null): string {
		if (!ka_is_editing()) {
			return '';
		}
		$attr = ' data-aq-field="' . esc_attr($field) . '"';
		if ($rindex !== null) {
			$attr .= ' data-aq-rindex="' . (int) $rindex . '"';
		}
		return $attr;
	}
}

if (!function_exists('ka_reading_time')) {
	/**
	 * Estimated reading time in whole minutes for a post (200 wpm). Used in the
	 * single-article meta line. Always at least 1.
	 */
	function ka_reading_time(int $post_id): int {
		$content = (string) get_post_field('post_content', $post_id);
		$words   = str_word_count(wp_strip_all_tags($content));
		return max(1, (int) round($words / 200));
	}
}

if (!function_exists('ka_external_links_new_tab')) {
	/**
	 * SEO/UX: any link in post content pointing to a DIFFERENT host opens in a new
	 * tab with rel="noopener noreferrer"; internal links are left untouched. Runs
	 * after wpautop (priority 20) on rendered content only — never alters the
	 * stored post, so the JSON round-trip stays clean.
	 */
	function ka_external_links_new_tab(string $html): string {
		if ($html === '' || stripos($html, '<a ') === false) {
			return $html;
		}
		$home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

		return (string) preg_replace_callback('/<a\b([^>]*)>/i', function ($m) use ($home_host) {
			$attrs = $m[1];
			if (!preg_match('/href\s*=\s*("|\')(https?:\/\/[^"\']+)\1/i', $attrs, $h)) {
				return $m[0];
			}
			$link_host = (string) wp_parse_url($h[2], PHP_URL_HOST);
			if ($link_host === '' || strcasecmp($link_host, $home_host) === 0) {
				return $m[0];
			}
			$new = $attrs;
			if (!preg_match('/\btarget\s*=/i', $new)) {
				$new .= ' target="_blank"';
			}
			if (preg_match('/\brel\s*=\s*("|\')(.*?)\1/i', $new, $r)) {
				$rel = $r[2];
				foreach (['noopener', 'noreferrer'] as $tok) {
					if (stripos($rel, $tok) === false) {
						$rel .= ' ' . $tok;
					}
				}
				$new = preg_replace('/\brel\s*=\s*("|\').*?\1/i', 'rel="' . esc_attr(trim($rel)) . '"', $new);
			} else {
				$new .= ' rel="noopener noreferrer"';
			}
			return '<a' . $new . '>';
		}, $html);
	}
	add_filter('the_content', 'ka_external_links_new_tab', 20);
}
