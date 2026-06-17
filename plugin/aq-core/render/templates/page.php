<?php
/**
 * Page template (plugin-owned). All customer-facing pages render as ordered ACF
 * flexible-content sections (canonical data lives in content/pages/*.json and
 * is imported via `wp aq import`). Pages without section data fall back to the
 * classic editor content.
 */

if (!defined('ABSPATH')) {
	exit;
}

AQ_Renderer::head_open();

while (have_posts()) {
	the_post();
	$aq_sections = function_exists('get_field') ? get_field('sections') : null;
	if (is_array($aq_sections) && $aq_sections) {
		AQ_Renderer::render_sections(get_the_ID());
	} else {
		the_content();
	}
}

AQ_Renderer::body_close();
