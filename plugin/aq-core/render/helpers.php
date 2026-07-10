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

if (!function_exists('ka_article_toc')) {
	/**
	 * Build a jump-link table of contents from a rendered post's H2 headings and
	 * return it alongside the (id-augmented) body HTML. Returned only when the
	 * article has 4+ H2 sections — short posts get nothing. Any H2 missing an id
	 * gets a slugified, de-duplicated one injected so the anchors resolve. The
	 * markup uses .article-toc (styled in the theme CSS); single.php renders it in
	 * a sticky sidebar.
	 *
	 * @return array{0:string,1:string} [toc_html, body_html]
	 */
	function ka_article_toc(string $html): array {
		if (!preg_match_all('/<h2\b([^>]*)>(.*?)<\/h2>/is', $html, $matches, PREG_SET_ORDER)) {
			return ['', $html];
		}
		$items = [];
		$used  = [];
		foreach ($matches as $h) {
			$label = trim(wp_strip_all_tags($h[2]));
			if ($label === '') {
				continue;
			}
			if (preg_match('/\bid=["\']([^"\']+)["\']/i', $h[1], $idm)) {
				$id = $idm[1];
			} else {
				$id = sanitize_title($label);
				if ($id === '') {
					continue;
				}
				$base = $id;
				$n    = 2;
				while (isset($used[$id])) {
					$id = $base . '-' . $n;
					$n++;
				}
				// Inject the generated id back into the body so the anchor works.
				$html = str_replace($h[0], '<h2' . $h[1] . ' id="' . esc_attr($id) . '">' . $h[2] . '</h2>', $html);
			}
			$used[$id] = true;
			$items[]   = ['id' => $id, 'label' => $label];
		}
		if (count($items) < 4) {
			return ['', $html];
		}
		$li = '';
		foreach ($items as $it) {
			$li .= '<li><a href="#' . esc_attr($it['id']) . '">' . esc_html($it['label']) . '</a></li>';
		}
		$toc_label = (function_exists('aq_site') ? aq_site('blog.tocLabel') : '') ?: 'In this article';
		$toc = '<nav class="article-toc" aria-label="Table of contents">'
			. '<p class="article-toc__label">' . esc_html($toc_label) . '</p>'
			. '<ul>' . $li . '</ul></nav>';
		return [$toc, $html];
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

if (!function_exists('aq_social_networks')) {
	/**
	 * Curated set of social/review networks for the footer social-icon repeater
	 * (AutoForge → Navigation). Each entry is the brand's own filled logo mark
	 * (not the generic 24-icon stroke library — brand marks are recognizable
	 * only in their own shape) in a 24x24 viewBox using currentColor, matching
	 * the site-footer.php badge treatment (circle background + fill icon).
	 */
	function aq_social_networks(): array {
		return [
			'facebook'  => ['label' => 'Facebook',  'svg' => '<path d="M22 12.07C22 6.51 17.52 2 12 2S2 6.51 2 12.07c0 5 3.66 9.15 8.44 9.93v-7.02H7.9v-2.91h2.54v-2.21c0-2.51 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.45 2.91h-2.33V22c4.78-.78 8.43-4.93 8.43-9.93z"/>'],
			'instagram' => ['label' => 'Instagram', 'svg' => '<path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.39C1.34 2.69.93 3.36.62 4.15.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.39 2.13.67.67 1.34 1.08 2.13 1.39.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.39.67-.67 1.08-1.34 1.39-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.39-2.13C21.31 1.34 20.64.93 19.85.62c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 12 18.16 6.16 6.16 0 0 0 12 5.84zm0 10.16A4 4 0 1 1 12 8a4 4 0 0 1 0 8zm6.41-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z"/>'],
			'youtube'   => ['label' => 'YouTube',   'svg' => '<path d="M23.5 6.19a3.02 3.02 0 00-2.12-2.14C19.51 3.5 12 3.5 12 3.5s-7.51 0-9.38.55A3.02 3.02 0 00.5 6.19 31.6 31.6 0 000 12a31.6 31.6 0 00.5 5.81 3.02 3.02 0 002.12 2.14c1.87.55 9.38.55 9.38.55s7.51 0 9.38-.55a3.02 3.02 0 002.12-2.14A31.6 31.6 0 0024 12a31.6 31.6 0 00-.5-5.81zM9.55 15.57V8.43L15.82 12z"/>'],
			'tiktok'    => ['label' => 'TikTok',    'svg' => '<path d="M16.6 5.82a4.28 4.28 0 01-2.36-2.32V2h-3.4v14.4a2.6 2.6 0 11-1.84-2.49v-3.5a5.9 5.9 0 00-.87-.07A6.09 6.09 0 108 22.4a6.02 6.02 0 006.09-6.02V9.01a7.63 7.63 0 004.4 1.4V6.98a4.24 4.24 0 01-1.89-1.16z"/>'],
			'linkedin'  => ['label' => 'LinkedIn',  'svg' => '<path d="M20.45 20.45h-3.55v-5.57c0-1.33-.03-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.94v5.67H9.36V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 110-4.12 2.06 2.06 0 010 4.12zM7.11 20.45H3.56V9h3.55v11.45z"/>'],
			'x'         => ['label' => 'X (Twitter)', 'svg' => '<path d="M18.9 2H22l-7.6 8.7L23.3 22h-6.9l-5.4-7-6.2 7H1.6l8.1-9.3L1 2h7.1l4.9 6.4zm-1.2 18h1.9L7.4 4H5.3z"/>'],
			'pinterest' => ['label' => 'Pinterest', 'svg' => '<path d="M12 2a10 10 0 00-3.65 19.31c-.05-.79-.1-2.02.02-2.89.11-.78 1.03-4.44 1.03-4.44s-.26-.53-.26-1.31c0-1.22.71-2.14 1.6-2.14.75 0 1.11.57 1.11 1.24 0 .76-.48 1.9-.73 2.96-.21.88.44 1.6 1.31 1.6 1.57 0 2.78-1.66 2.78-4.04 0-2.11-1.52-3.59-3.68-3.59-2.51 0-3.98 1.88-3.98 3.82 0 .76.29 1.57.65 2.01a.26.26 0 01.06.25c-.07.29-.22.88-.25 1-.04.16-.13.2-.31.12-1.14-.53-1.86-2.2-1.86-3.54 0-2.88 2.09-5.53 6.03-5.53 3.16 0 5.62 2.25 5.62 5.27 0 3.14-1.98 5.67-4.73 5.67-.92 0-1.79-.48-2.09-1.05l-.57 2.16c-.21.79-.77 1.79-1.14 2.39A10 10 0 1012 2z"/>'],
			'yelp'      => ['label' => 'Yelp',      'svg' => '<path d="M12.1 11.9c-.15-.08-6.05-2.87-6.32-2.98-.32-.13-.6-.02-.75.26-.08.15-.13.36-.16.62-.12 1.15.13 3.4.65 4.2.2.3.45.42.7.36.14-.03 6.2-2.32 6.35-2.4.24-.13.35-.35.3-.6a.63.63 0 00-.77-.46zm-1.35 2.34c-.13.24-5.51 3.8-5.72 3.9-.27.14-.44.36-.4.63.03.2.16.4.4.55.98.62 3.24 1.16 4.14 1.03.34-.05.55-.22.6-.5.03-.15-.9-4.9-1-5.16a.62.62 0 00-1.02-.45zM13.36 11c.05.27 3.9 5.6 4.08 5.83.2.26.47.36.72.24.18-.08.32-.27.4-.55.33-1.11.28-3.5-.28-4.5-.24-.42-.53-.6-.83-.5-.15.04-3.98 1.9-4.13 2.1-.19.24-.19.5.04.38zM13.1 9.66c.24-.12 4.5-2.98 4.63-3.16.18-.25.15-.55-.06-.78-.75-.85-2.66-1.87-3.6-1.85-.35 0-.6.15-.7.42-.05.14.65 5.15.73 5.4.09.28.34.35.6.2z"/><path d="M11 13.35c-.03-.28-.9-6.44-.98-6.9-.1-.55-.5-.83-1.06-.7-1.03.24-2.6 1.42-3 2.28-.19.4-.15.75.1.98.2.19 5.14 3.65 5.4 3.8.24.14.5.02.54-.46z"/>'],
			'google'    => ['label' => 'Google',    'svg' => '<path d="M21.6 12.23c0-.71-.06-1.4-.18-2.05H12v3.88h5.4a4.62 4.62 0 01-2 3.03v2.5h3.24c1.9-1.75 3-4.33 3-7.36z"/><path d="M12 22c2.7 0 4.97-.9 6.63-2.4l-3.24-2.5c-.9.6-2.05.96-3.4.96-2.6 0-4.8-1.76-5.6-4.13H3.06v2.6A10 10 0 0012 22z"/><path d="M6.4 13.93a6 6 0 010-3.86v-2.6H3.06a10 10 0 000 9.06z"/><path d="M12 6.1c1.47 0 2.8.5 3.83 1.5l2.87-2.87A9.6 9.6 0 0012 2a10 10 0 00-8.94 5.47l3.34 2.6C7.2 7.86 9.4 6.1 12 6.1z"/>'],
			'nextdoor'  => ['label' => 'Nextdoor',  'svg' => '<path d="M12 2C6.48 2 2 6.03 2 11c0 3.87 2.79 7.15 6.7 8.42-.09-.72-.17-1.82.04-2.6.19-.72 1.22-4.6 1.22-4.6s-.31-.63-.31-1.55c0-1.45.84-2.53 1.89-2.53.89 0 1.32.67 1.32 1.47 0 .9-.57 2.24-.87 3.49-.25 1.05.52 1.9 1.55 1.9 1.86 0 3.29-1.96 3.29-4.79 0-2.5-1.8-4.25-4.36-4.25-2.97 0-4.72 2.23-4.72 4.53 0 .9.34 1.86.77 2.38a.31.31 0 01.07.3l-.29 1.18c-.05.19-.16.24-.36.14C6.6 13.85 6 12.5 6 11c0-2.9 2.1-5.56 6.06-5.56 3.18 0 5.65 2.27 5.65 5.3 0 3.16-1.99 5.7-4.76 5.7a2.46 2.46 0 01-2.1-1.06l-.57 2.18c-.2.79-.6 1.6-.94 2.16.7.22 1.44.34 2.2.34.03 0 .06 0 .09-.01A10.5 10.5 0 0022 11c0-4.97-4.48-9-10-9z"/>'],
		];
	}
}

if (!function_exists('aq_social_icon_svg')) {
	/**
	 * Full <svg> markup for a footer social-icon network key. Empty string for
	 * an unknown/blank network (caller should skip rendering the link).
	 */
	function aq_social_icon_svg(string $network): string {
		$inner = (aq_social_networks()[$network]['svg'] ?? '');
		if ($inner === '') {
			return '';
		}
		return '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">' . $inner . '</svg>';
	}
}
