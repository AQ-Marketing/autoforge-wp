<?php
/**
 * AQ_Body_Class — section-presence-driven body classes.
 *
 * The compiled per-client front-end (site.js) gates its choreography on body
 * classes so a single client-agnostic bundle stays inert on pages that don't
 * need it:
 *   • home.js runs only when <body> has the `home` class.
 *   • about.js (the three.js hero) runs only when <body> has the `about` class.
 *
 * WordPress core adds `home` only to the front page. That's too narrow: any
 * page can be authored with animated sections, and the three.js hero can live
 * on any page. So we derive these classes from the page's actual section types
 * (client-agnostic — no hardcoded slugs):
 *   • If ANY section is an ANIMATED type → add `home`.
 *   • If a `network_hero` section is present → add `about`.
 *
 * Sections are read the same way AQ_Renderer reads them: the ACF
 * flexible-content field `sections`, each row typed by `acf_fc_layout`.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Body_Class {

	/**
	 * Section layout types whose presence means the page needs the `home`
	 * choreography. `network_hero` is in this set (it is animated) AND
	 * additionally triggers the `about` class below.
	 */
	private const ANIMATED = [
		'stat_split',
		'problem_panel',
		'sticky_steps',
		'service_showcase',
		'proof_story',
		'spotlight_grid',
		'chip_marquee',
		'compare_table',
		'scrub_quote',
		'logo_marquee',
		'network_hero',
	];

	public static function register(): void {
		add_filter('body_class', [__CLASS__, 'filter'], 10, 2);
	}

	/**
	 * @param string[] $classes The classes WordPress is about to print.
	 * @return string[]
	 */
	public static function filter($classes, $css_class = ''): array {
		$classes = (array) $classes;

		// Only singular views have an authored section stack. Archives, search,
		// 404 etc. carry no sections — leave them untouched.
		if (!is_singular() || !function_exists('get_field')) {
			return $classes;
		}

		$post_id = (int) get_queried_object_id();
		if ($post_id <= 0) {
			return $classes;
		}

		// Same retrieval the renderer uses: ACF flexible-content field 'sections',
		// each row typed by 'acf_fc_layout'.
		$sections = get_field('sections', $post_id);
		if (!is_array($sections) || empty($sections)) {
			return $classes;
		}

		$has_animated = false;
		$has_network_hero = false;

		foreach ($sections as $section) {
			$layout = is_array($section) ? (string) ($section['acf_fc_layout'] ?? '') : '';
			if ($layout === '') {
				continue;
			}
			if (in_array($layout, self::ANIMATED, true)) {
				$has_animated = true;
			}
			if ($layout === 'network_hero') {
				$has_network_hero = true;
			}
		}

		// Add `home` for any animated page (core already adds it on the front
		// page; guard so we never duplicate it).
		if ($has_animated && !in_array('home', $classes, true)) {
			$classes[] = 'home';
		}

		// Add `about` whenever the three.js network hero is present.
		if ($has_network_hero && !in_array('about', $classes, true)) {
			$classes[] = 'about';
		}

		return $classes;
	}
}
