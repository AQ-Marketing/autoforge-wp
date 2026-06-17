<?php
/**
 * Closing chrome — the get_footer() replacement. Closes <main>, emits the site
 * footer and sticky call bar, runs wp_footer(), and closes the document.
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
</main>
<?php AQ_Renderer::part('site-footer'); ?>
<?php AQ_Renderer::part('sticky-call-bar'); ?>
<?php wp_footer(); ?>
</body>
</html>
