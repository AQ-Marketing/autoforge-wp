<?php
/**
 * AQ Admin Bar Fit — keep the site header clear of the WordPress admin bar.
 *
 * A header pinned with position:fixed/sticky and top:0 renders UNDERNEATH the
 * WP admin bar for logged-in users (32px on desktop, 46px at <=782px), hiding
 * the logo and nav behind it. WordPress offsets normal-flow content
 * (html{margin-top}) but a fixed/sticky element ignores that margin, so we push
 * the header itself down by the bar's height instead. This applies in the
 * sticky/scrolled state too, because the offset is on `top:`.
 *
 * Front-end, admin-bar-only: the CSS is emitted ONLY when is_admin_bar_showing()
 * — i.e. never for logged-out visitors — so public HTML stays byte-identical.
 * Because this lives in the shared plugin it fixes EVERY AutoForge site: it
 * targets the AutoForge header conventions (.site-header / nav.top) and offers a
 * [data-aq-fixed-header] opt-in plus a --wp-admin-bar-h custom property for any
 * bespoke header to reference.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Admin_Bar_Fit {

	public static function register(): void {
		// Late on wp_head so it prints after the theme/plugin stylesheets; the
		// selectors are admin-bar-scoped so they win on specificity regardless.
		add_action('wp_head', [__CLASS__, 'print_css'], 100);
	}

	public static function print_css(): void {
		if (!is_admin_bar_showing()) {
			return; // logged-out visitors: emit nothing, output stays identical
		}
		// Heights + breakpoint mirror WordPress core's own admin-bar CSS exactly
		// (32px desktop, 46px at <=782px). The consuming rule reads the variable,
		// so flipping the variable in the media query is enough to stay responsive.
		echo '<style id="aq-admin-bar-fit">'
			. ':root{--wp-admin-bar-h:32px}'
			. 'body.admin-bar .site-header,'
			. 'body.admin-bar header.site-header,'
			. 'body.admin-bar nav.top,'
			. 'body.admin-bar [data-aq-fixed-header]{top:var(--wp-admin-bar-h)!important}'
			. '@media screen and (max-width:782px){:root{--wp-admin-bar-h:46px}}'
			. '</style>' . "\n";
	}
}
