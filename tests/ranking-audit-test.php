<?php
/**
 * Unit tests for AQ_Ranking_Audit's PURE helpers (no WordPress bootstrap):
 *   - freshness math:  age_days_from() / is_stale_from()
 *   - keyword dedup:   dedupe_keywords()
 *   - page filtering:  filter_rows()
 * The option/post-reading methods delegate to exactly these helpers.
 */
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Ranking_Audit')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-ranking-audit.php'; }

$DAY = 86400;
$NOW = 1_700_000_000; // a fixed "now"

/* ---------------- freshness math ---------------- */

t('age_days_from: fresh snapshot is 0 days old', function () use ($NOW) {
	eq(0, AQ_Ranking_Audit::age_days_from($NOW, $NOW));
});

t('age_days_from: floors to whole days', function () use ($NOW, $DAY) {
	eq(3, AQ_Ranking_Audit::age_days_from($NOW - (3 * $DAY) - 500, $NOW)); // 3 days + a bit
	eq(14, AQ_Ranking_Audit::age_days_from($NOW - (14 * $DAY), $NOW));
});

t('is_stale_from: fresh (day 0) is NOT stale', function () use ($NOW) {
	eq(false, AQ_Ranking_Audit::is_stale_from($NOW, $NOW));
});

t('is_stale_from: 13 days old is NOT stale', function () use ($NOW, $DAY) {
	eq(false, AQ_Ranking_Audit::is_stale_from($NOW - (13 * $DAY), $NOW));
});

t('is_stale_from: 14 days old IS stale (>= TTL)', function () use ($NOW, $DAY) {
	eq(true, AQ_Ranking_Audit::is_stale_from($NOW - (14 * $DAY), $NOW));
});

t('is_stale_from: 15 days old IS stale', function () use ($NOW, $DAY) {
	eq(true, AQ_Ranking_Audit::is_stale_from($NOW - (15 * $DAY), $NOW));
});

t('is_stale_from: missing/zero generated_at IS stale', function () {
	eq(true, AQ_Ranking_Audit::is_stale_from(0, 1_700_000_000));
	eq(0, AQ_Ranking_Audit::age_days_from(0, 1_700_000_000));
});

/* ---------------- keyword dedup ---------------- */

t('dedupe_keywords: case-insensitive dedup, first casing kept', function () {
	$out = AQ_Ranking_Audit::dedupe_keywords([
		['House Washing', 'soft wash'],
		['house washing', 'Soft Wash', 'Roof Cleaning'],
	]);
	eq(['House Washing', 'soft wash', 'Roof Cleaning'], $out);
});

t('dedupe_keywords: trims and drops empties', function () {
	$out = AQ_Ranking_Audit::dedupe_keywords([
		['  Deck Cleaning  ', '', '   '],
		['deck cleaning', 'Fleet Washing'],
	]);
	eq(['Deck Cleaning', 'Fleet Washing'], $out);
});

t('dedupe_keywords: empty input → empty list', function () {
	eq([], AQ_Ranking_Audit::dedupe_keywords([]));
	eq([], AQ_Ranking_Audit::dedupe_keywords([[], ['', '  ']]));
});

/* ---------------- rows_for_page filtering ---------------- */

$rows = [
	['keyword' => 'house washing merrimack nh', 'position' => 4, 'volume' => 210, 'url' => '/house-pressure-washing/'],
	['keyword' => 'Soft Wash', 'position' => null, 'volume' => 90, 'url' => ''],
	['keyword' => 'roof cleaning nh', 'position' => 8, 'volume' => 140, 'url' => '/roof-cleaning/'],
];

t('filter_rows: case-insensitive keyword match', function () use ($rows) {
	$out = AQ_Ranking_Audit::filter_rows($rows, ['HOUSE WASHING MERRIMACK NH', 'soft wash']);
	eq(2, count($out));
	eq('house washing merrimack nh', $out[0]['keyword']);
	eq('Soft Wash', $out[1]['keyword']);
});

t('filter_rows: non-matching keywords are excluded', function () use ($rows) {
	$out = AQ_Ranking_Audit::filter_rows($rows, ['gutter cleaning']);
	eq([], $out);
});

t('filter_rows: empty snapshot rows → empty', function () {
	eq([], AQ_Ranking_Audit::filter_rows([], ['house washing merrimack nh']));
});

t('filter_rows: empty keyword list → empty (never the whole site)', function () use ($rows) {
	eq([], AQ_Ranking_Audit::filter_rows($rows, []));
	eq([], AQ_Ranking_Audit::filter_rows($rows, ['', '  ']));
});

exit(aq_tests_done());
