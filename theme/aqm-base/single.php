<?php
/**
 * Fallback — see index.php. The AutoForge plugin owns post rendering; this
 * only runs when the plugin is inactive.
 */
if (!defined('ABSPATH')) {
	exit;
}
require __DIR__ . '/index.php';
