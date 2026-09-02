<?php
/**
 * AQ_Gallery — pure helpers for the client-agnostic `aq_gallery` section.
 *
 * These are deliberately WordPress-free so they can be unit-tested with no
 * bootstrap (see tests/gallery-test.php): render-time ordering, section-data
 * sanitizing, category slugging, and the tab-bar category list all live here.
 * The renderer (render/sections/aq-gallery.php) supplies attachment-derived
 * fields (title/filename/date/alt) into sort_images() so the sort stays pure.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Gallery {

	/** Allowed ordering modes. `manual` = the stored array order. */
	const ORDER_MODES = ['manual', 'title', 'date_desc', 'date_asc', 'filename', 'random'];

	/** Allowed named gaps (also accepts an "Npx" / "N" pixel value). */
	const GAPS = ['sm', 'md', 'lg'];

	/**
	 * Order a resolved image list. Items are arrays carrying id/title/filename/
	 * date/caption/category — the renderer fills those from attachment data so
	 * this function is pure and testable.
	 *   manual    → stored order (unchanged)
	 *   title     → attachment title, case-insensitive A→Z
	 *   filename  → basename, case-insensitive A→Z
	 *   date_desc → newest first (by the passed date string)
	 *   date_asc  → oldest first
	 *   random    → shuffled per request
	 *   unknown   → treated as manual
	 * PHP 8 sorts are stable, so equal keys keep their stored order.
	 */
	public static function sort_images(array $items, string $order_by): array {
		switch ($order_by) {
			case 'title':
				usort($items, static fn($a, $b) => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));
				break;
			case 'filename':
				usort($items, static fn($a, $b) => strcasecmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? '')));
				break;
			case 'date_asc':
				// WP dates are "Y-m-d H:i:s" — lexicographic compare == chronological.
				usort($items, static fn($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
				break;
			case 'date_desc':
				usort($items, static fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
				break;
			case 'random':
				shuffle($items);
				break;
			case 'manual':
			default:
				// preserve stored order
				break;
		}
		return $items;
	}

	/**
	 * Slugify a free category label for data-category / matching:
	 * lowercase, every run of non-alphanumerics → a single hyphen, trimmed.
	 * "House & Deck" → "house-deck"; "  Roof  " → "roof".
	 */
	public static function cat_slug(string $label): string {
		$s = strtolower(trim($label));
		$s = preg_replace('/[^a-z0-9]+/', '-', $s);
		return trim((string) $s, '-');
	}

	/**
	 * The categories actually present in $items, ordered by the section's
	 * $order list first (canonical label + order) then any extras in
	 * first-seen order. De-duplicated by slug so "House"/"house" collapse.
	 * Used to build the filter/tab bar.
	 */
	public static function distinct_categories(array $items, array $order): array {
		$present = []; // slug => first-seen item label
		foreach ($items as $it) {
			$cat = trim((string) (is_array($it) ? ($it['category'] ?? '') : ''));
			if ($cat === '') {
				continue;
			}
			$slug = self::cat_slug($cat);
			if ($slug === '' || isset($present[$slug])) {
				continue;
			}
			$present[$slug] = $cat;
		}
		$out  = [];
		$used = [];
		foreach ($order as $label) {
			$label = trim((string) $label);
			if ($label === '') {
				continue;
			}
			$slug = self::cat_slug($label);
			if ($slug === '' || isset($used[$slug])) {
				continue;
			}
			if (isset($present[$slug])) {
				$out[]        = $label; // canonical label from the section's list
				$used[$slug]  = true;
			}
		}
		foreach ($present as $slug => $label) {
			if (isset($used[$slug])) {
				continue;
			}
			$out[]       = $label; // extra category only used by an image
			$used[$slug] = true;
		}
		return $out;
	}

	/**
	 * Sanitize raw section data into the stored gallery shape:
	 *   columns          int clamped 2..5 (default 3)
	 *   gap              'sm'|'md'|'lg' or 'Npx' (default 'md')
	 *   order_by         whitelisted (default 'manual')
	 *   lightbox         bool (default true)
	 *   filters_enabled  bool (default false)
	 *   images           list of { id:+int, caption?:string, category:string }
	 *   categories       list of non-empty trimmed labels, de-duped by slug
	 */
	public static function sanitize_gallery(array $in): array {
		$columns = (int) ($in['columns'] ?? 3);
		$columns = max(2, min(5, $columns));

		$order_by = (string) ($in['order_by'] ?? 'manual');
		if (!in_array($order_by, self::ORDER_MODES, true)) {
			$order_by = 'manual';
		}

		$lightbox        = array_key_exists('lightbox', $in) ? self::to_bool($in['lightbox']) : true;
		$filters_enabled = array_key_exists('filters_enabled', $in) ? self::to_bool($in['filters_enabled']) : false;
		$gap             = self::sanitize_gap($in['gap'] ?? 'md');

		$images = [];
		foreach ((array) ($in['images'] ?? []) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue; // drop junk / empty rows
			}
			$item    = ['id' => $id];
			$caption = isset($row['caption']) ? trim((string) $row['caption']) : '';
			if ($caption !== '') {
				$item['caption'] = $caption;
			}
			// category is always present but may be an empty string.
			$item['category'] = isset($row['category']) ? trim((string) $row['category']) : '';
			$images[] = $item;
		}

		$categories = [];
		$seen       = [];
		foreach ((array) ($in['categories'] ?? []) as $c) {
			// Accept a bare string OR a { label: … } row (the builder repeater shape).
			$label = is_array($c) ? trim((string) ($c['label'] ?? '')) : trim((string) $c);
			if ($label === '') {
				continue;
			}
			$slug = self::cat_slug($label);
			if ($slug === '' || isset($seen[$slug])) {
				continue;
			}
			$seen[$slug]  = true;
			$categories[] = $label;
		}

		return [
			'columns'         => $columns,
			'gap'             => $gap,
			'order_by'        => $order_by,
			'lightbox'        => $lightbox,
			'filters_enabled' => $filters_enabled,
			'images'          => $images,
			'categories'      => $categories,
		];
	}

	/** Truthy-ish → bool (accepts 1/'1'/true/'true'/'on'/'yes'). */
	public static function to_bool($v): bool {
		if (is_bool($v)) {
			return $v;
		}
		if (is_int($v)) {
			return $v !== 0;
		}
		$s = strtolower(trim((string) $v));
		return in_array($s, ['1', 'true', 'on', 'yes'], true);
	}

	/** Named gap or an "Npx"/"N" pixel value clamped 0..64; default 'md'. */
	public static function sanitize_gap($g): string {
		$g = is_string($g) ? trim($g) : (string) $g;
		if (in_array($g, self::GAPS, true)) {
			return $g;
		}
		if (preg_match('/^(\d{1,3})(?:px)?$/', $g, $m)) {
			return max(0, min(64, (int) $m[1])) . 'px';
		}
		return 'md';
	}
}
