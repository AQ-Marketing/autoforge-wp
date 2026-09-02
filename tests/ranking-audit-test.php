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

/* ---------------- normalize_page_path (GSC pages) ---------------- */

t('normalize_page_path: full URL → path with leading+trailing slash', function () {
	eq('/roof-cleaning/', AQ_Ranking_Audit::normalize_page_path('https://example.com/roof-cleaning/'));
	eq('/roof-cleaning/', AQ_Ranking_Audit::normalize_page_path('https://example.com/roof-cleaning'));
});

t('normalize_page_path: already-path stays; adds leading+trailing slash', function () {
	eq('/deck/', AQ_Ranking_Audit::normalize_page_path('/deck/'));
	eq('/deck/', AQ_Ranking_Audit::normalize_page_path('deck'));
	eq('/deck/', AQ_Ranking_Audit::normalize_page_path('/deck'));
});

t('normalize_page_path: root and empty → "/"', function () {
	eq('/', AQ_Ranking_Audit::normalize_page_path('https://example.com/'));
	eq('/', AQ_Ranking_Audit::normalize_page_path('https://example.com'));
	eq('/', AQ_Ranking_Audit::normalize_page_path(''));
	eq('/', AQ_Ranking_Audit::normalize_page_path('/'));
});

t('normalize_page_path: strips query/fragment', function () {
	eq('/services/', AQ_Ranking_Audit::normalize_page_path('https://example.com/services/?utm=1'));
	eq('/services/', AQ_Ranking_Audit::normalize_page_path('/services/#top'));
});

/* ---------------- merge_rows (GSC preferred + DFS volume + extras) ---------------- */

t('merge_rows: GSC exact match attaches position/impressions and keeps DFS volume', function () {
	$dfs = [['keyword' => 'house washing', 'position' => 4, 'volume' => 210, 'url' => '/hpw/']];
	$gsc = [['page' => '/hpw/', 'query' => 'house washing', 'position' => 3.2, 'impressions' => 120, 'clicks' => 5]];
	$out = AQ_Ranking_Audit::merge_rows($dfs, $gsc, ['house washing']);
	eq(1, count($out));
	eq('house washing', $out[0]['keyword']);
	eq(3.2, $out[0]['gsc_position']);
	eq(120, $out[0]['impressions']);
	eq(5, $out[0]['clicks']);
	eq(4, $out[0]['dfs_position']);
	eq(210, $out[0]['volume']);
	eq('/hpw/', $out[0]['url']);
});

t('merge_rows: contains-match works (query contains the keyword)', function () {
	$gsc = [['page' => '/x/', 'query' => 'best soft wash near me', 'position' => 7.5, 'impressions' => 40, 'clicks' => 1]];
	$out = AQ_Ranking_Audit::merge_rows([], $gsc, ['soft wash']);
	eq(1, count($out));
	eq(7.5, $out[0]['gsc_position']);
	eq(40, $out[0]['impressions']);
	eq(null, $out[0]['volume']);
	eq(null, $out[0]['dfs_position']);
});

t('merge_rows: target keyword with no GSC keeps DFS volume, null gsc_position', function () {
	$dfs = [['keyword' => 'roof cleaning', 'position' => 8, 'volume' => 140, 'url' => '/roof/']];
	$out = AQ_Ranking_Audit::merge_rows($dfs, [], ['roof cleaning']);
	eq(1, count($out));
	eq(null, $out[0]['gsc_position']);
	eq(null, $out[0]['impressions']);
	eq(140, $out[0]['volume']);
	eq(8, $out[0]['dfs_position']);
});

t('merge_rows: extras = top-3 GSC by impressions, excluding target duplicates', function () {
	$gsc = [
		['page' => '/hpw/', 'query' => 'house washing',    'position' => 3.0, 'impressions' => 100, 'clicks' => 4], // target (covered)
		['page' => '/hpw/', 'query' => 'house washing nh', 'position' => 5.0, 'impressions' => 200, 'clicks' => 9], // contains target → excluded
		['page' => '/hpw/', 'query' => 'driveway cleaning','position' => 6.0, 'impressions' => 90,  'clicks' => 2],
		['page' => '/hpw/', 'query' => 'deck cleaning',    'position' => 7.0, 'impressions' => 80,  'clicks' => 1],
		['page' => '/hpw/', 'query' => 'patio washing',    'position' => 8.0, 'impressions' => 70,  'clicks' => 0],
		['page' => '/hpw/', 'query' => 'fence washing',    'position' => 9.0, 'impressions' => 60,  'clicks' => 0],
	];
	$out = AQ_Ranking_Audit::merge_rows([], $gsc, ['house washing']);
	// 1 target row + 3 extras
	eq(4, count($out));
	eq('house washing', $out[0]['keyword']);
	$extras = array_slice($out, 1);
	eq(3, count($extras));
	eq('driveway cleaning', $extras[0]['keyword']); // highest impressions among non-target
	eq('deck cleaning', $extras[1]['keyword']);
	eq('patio washing', $extras[2]['keyword']);
	foreach ($extras as $e) {
		ok(!empty($e['observed']), 'extra rows are marked observed');
		eq(null, $e['volume']);
		eq(null, $e['dfs_position']);
		ok($e['keyword'] !== 'house washing nh', 'target-duplicate query is excluded from extras');
		ok($e['keyword'] !== 'fence washing', 'only the top-3 impression queries are kept');
	}
});

t('merge_rows: empty inputs → []', function () {
	eq([], AQ_Ranking_Audit::merge_rows([], [], []));
});

exit(aq_tests_done());
