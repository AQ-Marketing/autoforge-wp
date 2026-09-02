<?php
/**
 * Unit tests for AQ_Gallery's PURE helpers (no WordPress bootstrap):
 *   - sort_images()         manual/title/filename/date_desc/date_asc/unknown
 *   - cat_slug()            lowercase, punctuation → hyphen, collapse repeats
 *   - distinct_categories() present categories, ordered by the section list
 *   - sanitize_gallery()    clamps columns/order_by/lightbox/filters/images/cats
 * The aq_gallery renderer + builder delegate their decisions to exactly these.
 *
 *   php tests/gallery-test.php
 */
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Gallery')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-gallery.php'; }

/** Build a comparable id-list so ordering assertions read clearly. */
function ids(array $items): array { return array_map(static fn($i) => $i['id'], $items); }

$sample = [
	['id' => 1, 'title' => 'Charlie', 'filename' => 'zebra.webp', 'date' => '2024-01-10 09:00:00'],
	['id' => 2, 'title' => 'alpha',   'filename' => 'apple.webp', 'date' => '2026-05-01 12:00:00'],
	['id' => 3, 'title' => 'Bravo',   'filename' => 'mango.webp', 'date' => '2025-03-15 08:30:00'],
];

/* ---- sort_images ---- */
t('sort_images(): manual preserves stored order', function () use ($sample) {
	eq([1, 2, 3], ids(AQ_Gallery::sort_images($sample, 'manual')));
});
t('sort_images(): unknown mode falls back to manual', function () use ($sample) {
	eq([1, 2, 3], ids(AQ_Gallery::sort_images($sample, 'bogus')));
});
t('sort_images(): title is case-insensitive A→Z', function () use ($sample) {
	eq([2, 3, 1], ids(AQ_Gallery::sort_images($sample, 'title'))); // alpha, Bravo, Charlie
});
t('sort_images(): filename A→Z', function () use ($sample) {
	eq([2, 3, 1], ids(AQ_Gallery::sort_images($sample, 'filename'))); // apple, mango, zebra
});
t('sort_images(): date_desc newest first', function () use ($sample) {
	eq([2, 3, 1], ids(AQ_Gallery::sort_images($sample, 'date_desc'))); // 2026, 2025, 2024
});
t('sort_images(): date_asc oldest first', function () use ($sample) {
	eq([1, 3, 2], ids(AQ_Gallery::sort_images($sample, 'date_asc')));
});
t('sort_images(): random keeps every item (same multiset)', function () use ($sample) {
	$got = ids(AQ_Gallery::sort_images($sample, 'random'));
	sort($got);
	eq([1, 2, 3], $got);
});

/* ---- cat_slug ---- */
t('cat_slug(): lowercases, spaces/punct → single hyphen, trims', function () {
	eq('house', AQ_Gallery::cat_slug('House'));
	eq('house', AQ_Gallery::cat_slug('  House  '));
	eq('house-deck', AQ_Gallery::cat_slug('House & Deck'));
	eq('house-deck', AQ_Gallery::cat_slug('House   Deck'));
	eq('roof-cleaning', AQ_Gallery::cat_slug('Roof—Cleaning!!'));
	eq('', AQ_Gallery::cat_slug('   '));
});

/* ---- distinct_categories ---- */
t('distinct_categories(): ordered by the section list, absent ones dropped', function () {
	$items = [
		['id' => 1, 'category' => 'House'],
		['id' => 2, 'category' => 'Roof'],
		['id' => 3, 'category' => 'House'],
		['id' => 4, 'category' => 'Deck'],
	];
	eq(['House', 'Roof', 'Deck'], AQ_Gallery::distinct_categories($items, ['House', 'Roof', 'Deck', 'Fence']));
});
t('distinct_categories(): extras (not in the list) appended in first-seen order', function () {
	$items = [
		['id' => 1, 'category' => 'House'],
		['id' => 2, 'category' => 'Patio'],
	];
	eq(['House', 'Patio'], AQ_Gallery::distinct_categories($items, ['House', 'Roof']));
});
t('distinct_categories(): de-dupes by slug (House == house)', function () {
	$items = [
		['id' => 1, 'category' => 'House'],
		['id' => 2, 'category' => 'house'],
	];
	eq(['House'], AQ_Gallery::distinct_categories($items, ['House']));
});
t('distinct_categories(): empty when no image carries a category', function () {
	$items = [['id' => 1, 'category' => ''], ['id' => 2]];
	eq([], AQ_Gallery::distinct_categories($items, ['House', 'Roof']));
});

/* ---- sanitize_gallery ---- */
t('sanitize_gallery(): defaults when input is empty', function () {
	$g = AQ_Gallery::sanitize_gallery([]);
	eq(3, $g['columns']);
	eq('md', $g['gap']);
	eq('manual', $g['order_by']);
	eq(true, $g['lightbox']);
	eq(false, $g['filters_enabled']);
	eq([], $g['images']);
	eq([], $g['categories']);
});
t('sanitize_gallery(): columns clamp to 2..5', function () {
	eq(2, AQ_Gallery::sanitize_gallery(['columns' => 1])['columns']);
	eq(5, AQ_Gallery::sanitize_gallery(['columns' => 9])['columns']);
	eq(4, AQ_Gallery::sanitize_gallery(['columns' => '4'])['columns']);
});
t('sanitize_gallery(): order_by whitelisted, unknown → manual', function () {
	eq('date_desc', AQ_Gallery::sanitize_gallery(['order_by' => 'date_desc'])['order_by']);
	eq('manual', AQ_Gallery::sanitize_gallery(['order_by' => 'hax'])['order_by']);
});
t('sanitize_gallery(): lightbox + filters_enabled coerce to bool', function () {
	eq(false, AQ_Gallery::sanitize_gallery(['lightbox' => '0'])['lightbox']);
	eq(true, AQ_Gallery::sanitize_gallery(['lightbox' => '1'])['lightbox']);
	eq(true, AQ_Gallery::sanitize_gallery(['filters_enabled' => 'true'])['filters_enabled']);
	eq(false, AQ_Gallery::sanitize_gallery(['filters_enabled' => 0])['filters_enabled']);
});
t('sanitize_gallery(): gap accepts named + pixel, else md', function () {
	eq('lg', AQ_Gallery::sanitize_gallery(['gap' => 'lg'])['gap']);
	eq('24px', AQ_Gallery::sanitize_gallery(['gap' => '24'])['gap']);
	eq('24px', AQ_Gallery::sanitize_gallery(['gap' => '24px'])['gap']);
	eq('64px', AQ_Gallery::sanitize_gallery(['gap' => '900'])['gap']); // clamped
	eq('md', AQ_Gallery::sanitize_gallery(['gap' => 'weird'])['gap']);
});
t('sanitize_gallery(): images keep +int ids, optional caption, trimmed category; junk dropped', function () {
	$g = AQ_Gallery::sanitize_gallery(['images' => [
		['id' => 12, 'caption' => '  Front  ', 'category' => '  House '],
		['id' => '7', 'category' => 'Roof'],
		['id' => 0, 'caption' => 'skip'],   // non-positive id → dropped
		['id' => -3],                        // negative → dropped
		'garbage',                           // not an array → dropped
		['caption' => 'no id'],              // missing id → dropped
	]]);
	eq(2, count($g['images']));
	eq(['id' => 12, 'caption' => 'Front', 'category' => 'House'], $g['images'][0]);
	eq(['id' => 7, 'category' => 'Roof'], $g['images'][1]); // empty caption dropped, category kept
});
t('sanitize_gallery(): categories trimmed, non-empty, de-duped by slug', function () {
	$g = AQ_Gallery::sanitize_gallery(['categories' => ['House', '  Roof ', 'house', '', 'Deck']]);
	eq(['House', 'Roof', 'Deck'], $g['categories']);
});
t('sanitize_gallery(): categories also accept {label} repeater rows', function () {
	$g = AQ_Gallery::sanitize_gallery(['categories' => [['label' => 'House'], ['label' => 'Roof'], ['label' => '']]]);
	eq(['House', 'Roof'], $g['categories']);
});

exit(aq_tests_done());
