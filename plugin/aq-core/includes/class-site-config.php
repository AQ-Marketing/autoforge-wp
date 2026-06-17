<?php
/**
 * Site-config overlay.
 *
 * The file at config/site.php is the baked-in default (NAP, towns, counties,
 * regions, geo, hours, …). This class lets the admin override any of those
 * values at runtime via the `aq_site_config` wp_option WITHOUT editing the
 * file — the option is deep-merged ON TOP of the file array, so any key the
 * option does not set still falls back to the file default.
 *
 * aq_site() (in aq-core.php) calls AQ_Site_Config::get() so all ~35 existing
 * call sites transparently pick up overrides; when the option is empty the
 * merged result is byte-for-byte the file defaults.
 *
 * SQLite-safe: pure get_option/update_option, no raw SQL.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Site_Config {

	const OPTION = 'aq_site_config';

	/** Reads config/site.php fresh each call (the values are tiny). */
	private static function file_defaults(): array {
		$cfg = require AQ_CORE_DIR . 'config/site.php';
		return is_array($cfg) ? $cfg : [];
	}

	/**
	 * The saved overlay option (always an array, never autoloaded).
	 */
	private static function overlay(): array {
		$opt = get_option(self::OPTION, []);
		return is_array($opt) ? $opt : [];
	}

	/**
	 * Deep-merge $override OVER $base.
	 *
	 * Rules:
	 *  - Associative arrays merge key-by-key (override wins on present keys,
	 *    base survives where override is silent).
	 *  - List-style arrays (sequential int keys — e.g. towns, counties,
	 *    regions) are REPLACED wholesale by the override when the override
	 *    provides that key, so removing/reordering rows actually takes effect.
	 *    When the override omits the key, the base list survives intact.
	 */
	public static function deep_merge(array $base, array $override): array {
		foreach ($override as $key => $val) {
			if (
				is_array($val) &&
				isset($base[$key]) && is_array($base[$key]) &&
				self::is_assoc($val) && self::is_assoc($base[$key])
			) {
				$base[$key] = self::deep_merge($base[$key], $val);
			} else {
				// Scalars, lists, and new keys: override replaces base.
				$base[$key] = $val;
			}
		}
		return $base;
	}

	/** True for associative arrays (and empty arrays); false for 0..n lists. */
	private static function is_assoc(array $arr): bool {
		if ($arr === []) {
			return true; // treat empty as assoc so empty override merges, not replaces
		}
		return array_keys($arr) !== range(0, count($arr) - 1);
	}

	/**
	 * The effective, merged config: option overlaid on file defaults.
	 * Safe when the option is missing/empty — returns the file defaults.
	 */
	public static function get(): array {
		return self::deep_merge(self::file_defaults(), self::overlay());
	}

	/**
	 * Merge $patch into the SAVED overlay and persist.
	 *
	 * Note: $patch is merged over the existing overlay (not the file), so
	 * partial saves accumulate. The Locations screen always POSTs the full
	 * editable subset, which is the common path. autoload=false keeps this
	 * off every page load.
	 *
	 * @return bool update_option result (true on change, false if unchanged).
	 */
	public static function update(array $patch): bool {
		$merged = self::deep_merge(self::overlay(), $patch);
		return update_option(self::OPTION, $merged, false);
	}

	/** Replace the entire overlay (used when the editor sends the full set). */
	public static function replace(array $overlay): bool {
		return update_option(self::OPTION, $overlay, false);
	}
}
