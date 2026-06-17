<?php
/**
 * Document head + opening chrome — the get_header() replacement. Moved out of
 * the theme so rendering is plugin-owned. Emits <html>/<head>, wp_head(), the
 * (data-driven) web-font link, the site header, and opens <main>.
 *
 * Fonts are client data: aq_site('fonts.googleCss') holds the full Google
 * Fonts CSS2 URL for the brand. When unset (e.g. a client that self-hosts
 * fonts in its compiled CSS) the link is simply omitted.
 */

if (!defined('ABSPATH')) {
	exit;
}

$aq_fonts = function_exists('aq_site') ? aq_site('fonts.googleCss') : null;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php wp_head(); ?>
<?php if ($aq_fonts) : ?>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link
	rel="stylesheet"
	href="<?php echo esc_url($aq_fonts); ?>"
	media="print"
	onload="this.media='all'"
/>
<noscript>
	<link rel="stylesheet" href="<?php echo esc_url($aq_fonts); ?>" />
</noscript>
<?php endif; ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a href="#main" class="skip-to-content">Skip to content</a>
<?php AQ_Renderer::part('site-header'); ?>
<main id="main">
