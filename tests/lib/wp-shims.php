<?php
/**
 * Minimal stand-ins for the WordPress functions/classes the unit-tested code
 * touches. Every definition is guarded, so under `wp eval-file` (WordPress
 * loaded) this file defines NOTHING and the real functions are used.
 */
if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__, 2) . '/');
}
if (!class_exists('WP_Error')) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return $thing instanceof WP_Error; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($s) { $s = strip_tags((string) $s); $s = preg_replace('/[\r\n\t ]+/', ' ', $s); return trim($s); }
}
if (!function_exists('apply_filters')) { function apply_filters($hook, $value) { return $value; } }
if (!function_exists('get_option')) {
	$GLOBALS['aq_shim_options'] = [];
	function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['aq_shim_options']) ? $GLOBALS['aq_shim_options'][$key] : $default; }
	function update_option($key, $value, $autoload = null) { $GLOBALS['aq_shim_options'][$key] = $value; return true; }
	function delete_option($key) { unset($GLOBALS['aq_shim_options'][$key]); return true; }
}
if (!function_exists('wp_next_scheduled')) {
	$GLOBALS['aq_shim_cron'] = [];
	function wp_next_scheduled($hook, $args = []) { return $GLOBALS['aq_shim_cron'][$hook] ?? false; }
	function wp_schedule_single_event($timestamp, $hook, $args = []) { $GLOBALS['aq_shim_cron'][$hook] = $timestamp; return true; }
}
