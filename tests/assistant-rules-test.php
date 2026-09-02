<?php
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Content_SEO_Gate')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-content-seo-gate.php'; }
if (!class_exists('AQ_Assistant_Rules')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-assistant-rules.php'; }

// A helper to build a minimal ctx.
function ctx(array $over = []): array {
	$base = [
		'before_sections' => [['type' => 'hero', 'heading' => 'House Washing in Merrimack NH', 'sub' => 'We clean homes across Southern New Hampshire, soft-wash, no damage. Call ACME today for a free estimate on your house washing project in Merrimack.']],
		'after_sections'  => [['type' => 'hero', 'heading' => 'House Washing in Merrimack NH', 'sub' => 'We clean homes across Southern New Hampshire, soft-wash, no damage. Call ACME today for a free estimate on your house washing project in Merrimack.']],
		'plan'  => ['primary_intent' => 'house washing merrimack nh', 'role' => 'service', 'canonical_path' => '/house-pressure-washing/', 'secondary_keywords' => ['soft wash'], 'entities' => ['ACME', 'Merrimack'], 'target_words' => 30],
		'field' => ['kind' => 'text', 'name' => 'heading', 'before' => 'House Washing in Merrimack NH', 'after' => 'House Washing in Merrimack NH'],
		'page'  => ['path' => '/house-pressure-washing/', 'seo_title' => 'House Washing in Merrimack, NH', 'seo_description' => str_repeat('a', 130)],
		'brand' => ['name' => 'ACME', 'phone' => '(603) 883-6900'],
		'inventory' => [],
	];
	return array_replace_recursive($base, $over);
}

t('no change → safe, no findings', function () {
	$r = AQ_Assistant_Rules::evaluate(ctx());
	eq('safe', $r['verdict']);
	eq(0, count($r['findings']));
});

t('R1: primary keyword removed from the page → blocked', function () {
	$c = ctx([
		'after_sections' => [['type' => 'hero', 'heading' => 'We clean everything', 'sub' => 'Homes across the area, no damage, call us today.']],
		'field' => ['name' => 'heading', 'before' => 'House Washing in Merrimack NH', 'after' => 'We clean everything'],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R1'));
});

t('R2: a secondary keyword dropped → adjusted (caution)', function () {
	$c = ctx([
		'after_sections' => [['type' => 'hero', 'heading' => 'House Washing in Merrimack NH', 'sub' => 'We clean homes across Southern New Hampshire, no damage. Call ACME today for a free estimate on your house washing project in Merrimack.']],
	]); // dropped "soft-wash" / "soft wash"
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('adjusted', $r['verdict']);
	ok(self_has($r, 'R2'));
});

t('R3: a protected entity removed from the edited field → blocked', function () {
	$c = ctx([
		'field' => ['kind' => 'text', 'name' => 'sub', 'before' => 'Call ACME today in Merrimack', 'after' => 'Call us today'],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R3'));
});

t('R3: phone number removed → blocked', function () {
	$c = ctx([
		'field' => ['kind' => 'text', 'name' => 'cta', 'before' => 'Call (603) 883-6900 now', 'after' => 'Call us now'],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R3'));
});

t('R4: a link removed from richtext → blocked; adding a link is fine', function () {
	$c = ctx([
		'field' => ['kind' => 'richtext', 'name' => 'body', 'before' => 'See our <a href="/roof-cleaning/">roof cleaning</a> and <a href="/contact/">contact</a>.', 'after' => 'See our roof cleaning and <a href="/contact/">contact</a>.'],
	]);
	eq('blocked', AQ_Assistant_Rules::evaluate($c)['verdict']);
	$c2 = ctx([
		'field' => ['kind' => 'richtext', 'name' => 'body', 'before' => 'See <a href="/contact/">contact</a>.', 'after' => 'See <a href="/contact/">contact</a> and <a href="/roof-cleaning/">roof</a>.'],
	]);
	eq('safe', AQ_Assistant_Rules::evaluate($c2)['verdict']);
});

t('R4: a link destination changed → blocked', function () {
	$c = ctx([
		'field' => ['kind' => 'richtext', 'name' => 'body', 'before' => '<a href="/roof-cleaning/">roof</a>', 'after' => '<a href="/gutters/">roof</a>'],
	]);
	eq('blocked', AQ_Assistant_Rules::evaluate($c)['verdict']);
});

t('R5: emptied heading → blocked', function () {
	$c = ctx([
		'after_sections' => [['type' => 'hero', 'heading' => '', 'sub' => 'We clean homes across Southern New Hampshire, soft-wash, no damage. Call ACME today for a free estimate on your house washing project in Merrimack.']],
		'field' => ['name' => 'heading', 'before' => 'House Washing in Merrimack NH', 'after' => ''],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R5'));
});

t('R5: page shortened by >25% → blocked', function () {
	$c = ctx([
		'after_sections' => [['type' => 'hero', 'heading' => 'House Washing in Merrimack NH soft wash', 'sub' => 'Homes, no damage.']],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R5'));
});

t('R6: em dash added → adjusted (caution)', function () {
	$c = ctx([
		'field' => ['kind' => 'text', 'name' => 'sub', 'before' => 'House washing, soft wash, Merrimack', 'after' => 'House washing — soft wash — Merrimack ACME'],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('adjusted', $r['verdict']);
	ok(self_has($r, 'R6'));
});

t('R8: seo.title too long → caution; primary keyword missing from title → blocked', function () {
	$long = ctx([
		'field' => ['kind' => 'seo.title', 'name' => 'seo_title', 'before' => 'House Washing in Merrimack, NH', 'after' => 'House Washing in Merrimack NH by the best local pressure washing company around'],
	]);
	eq('adjusted', AQ_Assistant_Rules::evaluate($long)['verdict']); // keeps the primary tokens, just too long
	$gone = ctx([
		'field' => ['kind' => 'seo.title', 'name' => 'seo_title', 'before' => 'House Washing in Merrimack, NH', 'after' => 'Exterior Cleaning Services'],
	]);
	$r = AQ_Assistant_Rules::evaluate($gone);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R8'));
});

t('R7: a duplicate title against the inventory → blocked', function () {
	$c = ctx([
		'field' => ['kind' => 'seo.title', 'name' => 'seo_title', 'before' => 'House Washing in Merrimack, NH', 'after' => 'Roof Cleaning NH house washing merrimack nh'],
		'page'  => ['path' => '/house-pressure-washing/', 'seo_title' => 'Roof Cleaning NH house washing merrimack nh', 'seo_description' => str_repeat('a', 130)],
		'inventory' => [[
			'path' => '/roof-cleaning/', 'canonical' => '', 'title' => 'Roof Cleaning NH house washing merrimack nh', 'h1' => 'Roof Cleaning', 'content' => 'roof cleaning services',
			'intent' => ['primary_intent' => 'roof cleaning nh', 'role' => 'service', 'service' => 'roof', 'market' => 'NH', 'funnel' => 'commercial', 'canonical_path' => '/roof-cleaning/', 'differentiators' => ['a', 'b']],
		]],
	]);
	$r = AQ_Assistant_Rules::evaluate($c);
	eq('blocked', $r['verdict']);
	ok(self_has($r, 'R7') || self_has($r, 'R8'));
});

t('verdict(): worst finding wins', function () {
	eq('safe', AQ_Assistant_Rules::verdict([]));
	eq('adjusted', AQ_Assistant_Rules::verdict([['severity' => 'caution']]));
	eq('blocked', AQ_Assistant_Rules::verdict([['severity' => 'caution'], ['severity' => 'block']]));
});

function self_has(array $r, string $rule): bool {
	foreach ($r['findings'] as $f) { if (($f['rule'] ?? '') === $rule) { return true; } }
	return false;
}

exit(aq_tests_done());
