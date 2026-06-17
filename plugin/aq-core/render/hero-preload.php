<?php
/**
 * Preload the page's hero image so LCP starts downloading in parallel with the
 * HTML parse instead of waiting for CSS. The hero is the first ACF
 * flexible-content section whose layout is one of the hero variants (a
 * `breadcrumb` section may precede it on converted pages). Any exception is
 * swallowed so a bad lookup never breaks the front end.
 *
 * Moved out of the theme into the plugin: rendering is now plugin-owned, so the
 * LCP preload hook lives next to the renderer. Registers itself at include time.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wp_head', function () {
	if (!function_exists('get_field')) {
		return;
	}
	try {
		$post_id = get_queried_object_id();
		if (!$post_id) {
			return;
		}
		$sections = get_field('sections', $post_id);
		if (!is_array($sections)) {
			return;
		}
		$hero_layouts = ['hero', 'city_hero', 'specialty_hero'];
		$hero = null;
		foreach ($sections as $section) {
			if (is_array($section) && in_array(($section['acf_fc_layout'] ?? ''), $hero_layouts, true)) {
				$hero = $section;
				break;
			}
		}
		if (!$hero || empty($hero['image'])) {
			return;
		}
		$img = $hero['image'];
		$id  = 0;
		if (is_numeric($img)) {
			$id = (int) $img;
		} elseif (is_array($img) && !empty($img['ID'])) {
			$id = (int) $img['ID'];
		}
		if (!$id) {
			return;
		}
		$src = wp_get_attachment_image_url($id, 'ka-1280');
		if (!$src) {
			return;
		}
		$srcset = wp_get_attachment_image_srcset($id, 'ka-1280');
		$sizes  = '(max-width: 480px) 480px, (max-width: 768px) 768px, 1280px';
		echo '<link rel="preload" as="image" href="' . esc_url($src) . '"';
		if ($srcset) {
			echo ' imagesrcset="' . esc_attr($srcset) . '" imagesizes="' . esc_attr($sizes) . '"';
		}
		echo ' fetchpriority="high" />' . "\n";
	} catch (\Throwable $e) {
		// Swallow — preload is a perf hint, not a hard requirement.
	}
}, 5);
