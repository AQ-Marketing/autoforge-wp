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
