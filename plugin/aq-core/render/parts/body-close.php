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
<?php if (aq_site('stickyBar')) { AQ_Renderer::part('sticky-call-bar'); } // per-site toggle: stickyBar=false (or unset) hides it ?>
<?php if (aq_site('weather.enabled')) { AQ_Renderer::part('weather-widget'); } // per-site toggle: weather.enabled=true opts a site in ?>
<?php wp_footer(); ?>
</body>
</html>
