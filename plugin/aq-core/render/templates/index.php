<?php
/**
 * Generic fallback template (plugin-owned) — 404, search, archives, home. Every
 * real route is a page (page.php) or post (single.php); this keeps the chrome
 * consistent for everything else and renders the loop content when present.
 */

if (!defined('ABSPATH')) {
	exit;
}

AQ_Renderer::head_open();

if (have_posts()) {
	while (have_posts()) {
		the_post();
		the_content();
	}
}

AQ_Renderer::body_close();
