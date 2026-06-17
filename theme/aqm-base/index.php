<?php
/**
 * Minimal fallback template. Normally the AutoForge plugin (AQ_Renderer)
 * takes over rendering via template_include and this file is never used. It
 * only runs if the plugin is inactive or AQ_RENDER_DISABLE is set — in which
 * case we still emit a valid document so the site degrades gracefully rather
 * than showing a blank page.
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main id="main">
<?php
if (have_posts()) {
	while (have_posts()) {
		the_post();
		the_content();
	}
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
