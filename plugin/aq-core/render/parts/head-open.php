<?php
/**
 * Document head + opening chrome — the get_header() replacement. Moved out of
 * the theme so rendering is plugin-owned. Emits <html>/<head>, wp_head(), the
 * (data-driven) web-font link, the site header, and opens <main>.
 *
 * Fonts are client data: aq_site('fonts.googleCss') holds the full Google
 * Fonts CSS2 URL for the brand. When unset (e.g. a client that self-hosts
 * fonts in its compiled CSS) the link is simply omitted.
 *
 * The font CSS is applied render-blocking (not via the old media="print" /
 * onload async hack) so @font-face is known before first paint — otherwise
 * text paints in a fallback face and visibly "pops" to the brand font once
 * the CSS lands. preconnect + preload keep that blocking cost near-zero.
 * We force display=swap so the brand font ALWAYS wins once it loads (brief
 * fallback, then swap) — never a permanent fallback face. (display=optional
 * was tried but it drops the brand font entirely for the first, uncached view,
 * which reads as "wrong font"; swap + preload is the right brand-fidelity call.)
 */

if (!defined('ABSPATH')) {
	exit;
}

$aq_fonts = function_exists('aq_site') ? aq_site('fonts.googleCss') : null;

// Normalise the font-display strategy to "swap" so the brand font reliably
// applies once loaded (fallback shown only briefly). Rewrite an existing
// display= value or append one if the brand URL omitted it.
if ($aq_fonts) {
	if (preg_match('/([?&])display=[^&]*/', $aq_fonts)) {
		$aq_fonts = preg_replace('/([?&])display=[^&]*/', '$1display=swap', $aq_fonts);
	} else {
		$aq_fonts .= (strpos($aq_fonts, '?') === false ? '?' : '&') . 'display=swap';
	}
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<?php wp_head(); ?>
<?php if (function_exists('is_admin_bar_showing') && is_admin_bar_showing()) : ?>
<style id="aq-admin-bar-offset">
/* HARD RULE: the logged-in WP admin bar must never cover the page. WP core only
 * pushes the document flow down (html{margin-top}); it does NOT move position:
 * fixed/sticky chrome, so a fixed site header slides under the toolbar. Offset
 * that chrome by the toolbar's real height. Emitted only when the bar is showing,
 * so logged-out parity / Core Web Vitals are untouched. The bar is fixed at 32px
 * (>=783px) and 46px (601-782px); at <=600px WP makes it position:absolute so it
 * scrolls away and no offset is needed. Per-design fixed dropdowns/panels anchored
 * below the header should add var(--wp-admin-bar-h,0px) to their own top calc. */
body.admin-bar{--wp-admin-bar-h:32px}
@media screen and (max-width:782px){body.admin-bar{--wp-admin-bar-h:46px}}
@media screen and (max-width:600px){body.admin-bar{--wp-admin-bar-h:0px}}
body.admin-bar > header,
body.admin-bar .site-header,
body.admin-bar #hdr,
body.admin-bar [data-aq-fixed-top]{top:var(--wp-admin-bar-h)}
</style>
<?php endif; ?>
<?php if ($aq_fonts) : ?>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link rel="preload" as="style" href="<?php echo esc_url($aq_fonts); ?>" />
<link rel="stylesheet" href="<?php echo esc_url($aq_fonts); ?>" />
<?php endif; ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a href="#main" class="skip-to-content">Skip to content</a>
<?php AQ_Renderer::part('site-header'); ?>
<main id="main">
