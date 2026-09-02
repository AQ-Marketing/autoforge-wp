<?php
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Claude'))   { require dirname(__DIR__) . '/plugin/aq-core/includes/class-claude.php'; }
if (!class_exists('AQ_Alt_Text')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-alt-text.php'; }
if (!class_exists('AQ_OpenAI')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-openai.php'; }

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aq-alt-test-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/photo.jpg', 'ORIGINAL');
file_put_contents($tmp . '/photo-1024x683.jpg', 'LARGE');
file_put_contents($tmp . '/photo-768x512.jpg', 'MEDIUM_LARGE');

/* ---- eligibility ---- */
t('eligible_mime(): jpeg/png/gif/webp yes; svg/avif/pdf no', function () {
	foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $m) { ok(AQ_Alt_Text::eligible_mime($m), $m); }
	foreach (['image/svg+xml', 'image/avif', 'application/pdf', ''] as $m) { ok(!AQ_Alt_Text::eligible_mime($m), $m); }
});
t('should_generate(): only enabled + eligible + empty alt + not decorative', function () {
	eq(true,  AQ_Alt_Text::should_generate('image/jpeg', '', false, true));
	eq(true,  AQ_Alt_Text::should_generate('image/jpeg', '   ', false, true), 'whitespace alt counts as empty');
	eq(false, AQ_Alt_Text::should_generate('image/jpeg', 'A red truck', false, true), 'human alt is never overwritten');
	eq(false, AQ_Alt_Text::should_generate('image/jpeg', '', true, true), 'decorative marker blocks');
	eq(false, AQ_Alt_Text::should_generate('image/svg+xml', '', false, true), 'svg skipped');
	eq(false, AQ_Alt_Text::should_generate('image/jpeg', '', false, false), 'disabled');
});

/* ---- source file choice ---- */
t('pick_source(): prefers large, then medium_large, then the original', function () use ($tmp) {
	$meta = ['sizes' => ['large' => ['file' => 'photo-1024x683.jpg', 'mime-type' => 'image/jpeg'], 'medium_large' => ['file' => 'photo-768x512.jpg', 'mime-type' => 'image/jpeg']]];
	eq(['path' => $tmp . DIRECTORY_SEPARATOR . 'photo-1024x683.jpg', 'mime' => 'image/jpeg'], AQ_Alt_Text::pick_source($meta, $tmp, $tmp . '/photo.jpg'));
	unset($meta['sizes']['large']);
	eq($tmp . DIRECTORY_SEPARATOR . 'photo-768x512.jpg', AQ_Alt_Text::pick_source($meta, $tmp, $tmp . '/photo.jpg')['path']);
	eq(['path' => $tmp . '/photo.jpg', 'mime' => ''], AQ_Alt_Text::pick_source(['sizes' => []], $tmp, $tmp . '/photo.jpg'));
	eq(null, AQ_Alt_Text::pick_source(['sizes' => ['large' => ['file' => 'missing.jpg']]], $tmp, $tmp . '/nope.jpg'));
});

/* ---- prompt + tool ---- */
t('system_prompt() carries the non-negotiable rules', function () {
	$p = AQ_Alt_Text::system_prompt();
	foreach (['125 characters', 'Image of', 'decorative', 'em dash', 'set_alt_text', 'only what is visible'] as $needle) { ok(stripos($p, $needle) !== false, "missing: $needle"); }
});
t('user_text() lists only the context that exists', function () {
	$txt = AQ_Alt_Text::user_text(['business' => 'ACME Pressure Washing', 'industry' => 'pressure washing', 'location' => 'Merrimack, NH', 'towns' => ['Nashua', 'Bedford'], 'filename' => 'acme svc residential', 'page' => '']);
	ok(strpos($txt, 'ACME Pressure Washing (pressure washing), Merrimack, NH') !== false);
	ok(strpos($txt, 'Nashua, Bedford') !== false);
	ok(strpos($txt, 'acme svc residential') !== false);
	ok(strpos($txt, 'Used on page') === false, 'empty page line omitted');
	$bare = AQ_Alt_Text::user_text(['business' => '', 'industry' => '', 'location' => '', 'towns' => [], 'filename' => '', 'page' => '']);
	ok(strpos($bare, 'Business:') === false);
});
t('tool_def(): named set_alt_text, requires alt + decorative', function () {
	$d = AQ_Alt_Text::tool_def();
	eq('set_alt_text', $d['name']);
	eq(['alt', 'decorative'], $d['input_schema']['required']);
	eq(['high', 'medium', 'low'], $d['input_schema']['properties']['confidence']['enum']);
});

/* ---- normalization + parsing ---- */
t('normalize_alt(): strips "Image of" prefixes, dashes, trailing period; caps at 200', function () {
	eq('A red pickup truck parked beside a white farmhouse', AQ_Alt_Text::normalize_alt('Image of a red pickup truck parked beside a white farmhouse.'));
	eq('Photo booth', AQ_Alt_Text::normalize_alt('  photo   booth '), 'a leading word "photo" not followed by of/showing is kept');
	eq('Wet siding, freshly washed', AQ_Alt_Text::normalize_alt('Wet siding — freshly washed'));
	eq(200, mb_strlen(AQ_Alt_Text::normalize_alt(str_repeat('word ', 60))));
	eq('', AQ_Alt_Text::normalize_alt('   '));
});
t('parse_result(): decorative → empty alt; empty non-decorative → null; confidence normalized', function () {
	eq(['alt' => '', 'decorative' => true, 'confidence' => 'high'], AQ_Alt_Text::parse_result(['alt' => 'texture', 'decorative' => true, 'confidence' => 'high']));
	eq(['alt' => 'A crew washing a roof', 'decorative' => false, 'confidence' => 'medium'], AQ_Alt_Text::parse_result(['alt' => 'a crew washing a roof.', 'decorative' => false, 'confidence' => 'certain']));
	eq(null, AQ_Alt_Text::parse_result(['alt' => '', 'decorative' => false]));
	eq(null, AQ_Alt_Text::parse_result(null));
	eq(null, AQ_Alt_Text::parse_result('string'));
});

/* ---- settings / queue / daily cap (option-backed; shimmed standalone, real in WP) ---- */
$saved = ['q' => get_option(AQ_Alt_Text::QUEUE, null), 'd' => get_option(AQ_Alt_Text::DAILY, null), 's' => get_option(AQ_Alt_Text::OPTION, null)];
try {
	delete_option(AQ_Alt_Text::QUEUE); delete_option(AQ_Alt_Text::DAILY); delete_option(AQ_Alt_Text::OPTION);

	t('settings(): defaults merge over the stored option', function () {
		eq(['enabled' => true, 'model' => 'claude-opus-5', 'daily_cap' => 300], AQ_Alt_Text::settings());
		update_option(AQ_Alt_Text::OPTION, ['daily_cap' => 50], false);
		eq(50, AQ_Alt_Text::settings()['daily_cap']);
		eq(true, AQ_Alt_Text::settings()['enabled']);
	});
	t('enqueue(): stores an entry once, schedules the runner once', function () {
		AQ_Alt_Text::enqueue(101); AQ_Alt_Text::enqueue(101); AQ_Alt_Text::enqueue(102); AQ_Alt_Text::enqueue(0);
		$q = AQ_Alt_Text::queue();
		eq([101, 102], array_keys($q));
		eq(0, $q[101]['attempts']);
		ok((int) $q[101]['next_at'] <= time());
		ok(wp_next_scheduled(AQ_Alt_Text::HOOK) !== false, 'runner scheduled');
	});
	t('mark_failure(): bumps attempts with backoff, drops after MAX_ATTEMPTS', function () {
		$q = AQ_Alt_Text::queue();
		$q = AQ_Alt_Text::mark_failure($q, 101);
		eq(1, $q[101]['attempts']); ok($q[101]['next_at'] >= time() + 119);
		$q = AQ_Alt_Text::mark_failure($q, 101);
		eq(2, $q[101]['attempts']); ok($q[101]['next_at'] >= time() + 599);
		$q = AQ_Alt_Text::mark_failure($q, 101);
		ok(!isset($q[101]), 'third failure removes the entry');
		ok(isset($q[102]), 'other entries untouched');
	});
	t('due_ids(): only entries whose next_at has passed, oldest first', function () {
		$q = [7 => ['queued_at' => 200, 'attempts' => 0, 'next_at' => time() + 999], 5 => ['queued_at' => 100, 'attempts' => 0, 'next_at' => 0], 9 => ['queued_at' => 50, 'attempts' => 1, 'next_at' => time() - 1]];
		eq([9, 5], AQ_Alt_Text::due_ids($q));
	});
	t('daily cap: remaining = cap − today\'s count; a stale day resets', function () {
		update_option(AQ_Alt_Text::OPTION, ['daily_cap' => 3], false);
		delete_option(AQ_Alt_Text::DAILY);
		eq(3, AQ_Alt_Text::daily_remaining());
		AQ_Alt_Text::daily_bump(); AQ_Alt_Text::daily_bump();
		eq(1, AQ_Alt_Text::daily_remaining());
		update_option(AQ_Alt_Text::DAILY, ['day' => '2000-01-01', 'count' => 3], false);
		eq(3, AQ_Alt_Text::daily_remaining(), 'yesterday\'s count does not carry over');
	});
} finally {
	foreach (['q' => AQ_Alt_Text::QUEUE, 'd' => AQ_Alt_Text::DAILY, 's' => AQ_Alt_Text::OPTION] as $k => $opt) {
		if ($saved[$k] === null) { delete_option($opt); } else { update_option($opt, $saved[$k], false); }
	}
}

t('provider(): claude ids -> claude, everything else -> openai', function () {
	eq('claude', AQ_Alt_Text::provider('claude-opus-5'));
	eq('claude', AQ_Alt_Text::provider('claude-haiku-4-5'));
	eq('openai', AQ_Alt_Text::provider('gpt-4o-mini'));
	eq('openai', AQ_Alt_Text::provider('gpt-4o'));
	eq('openai', AQ_Alt_Text::provider('o4-mini'));
});
t('all_models(): includes both Claude and OpenAI ids', function () {
	$m = AQ_Alt_Text::all_models();
	ok(array_key_exists('claude-opus-5', $m));
	ok(array_key_exists('gpt-4o-mini', $m));
});
t('model(): a saved OpenAI id is honoured, an unknown id falls back to Claude', function () {
	$saved = get_option(AQ_Alt_Text::OPTION, null);
	update_option(AQ_Alt_Text::OPTION, ['model' => 'gpt-4o-mini'], false);
	eq('gpt-4o-mini', AQ_Alt_Text::model());
	update_option(AQ_Alt_Text::OPTION, ['model' => 'not-real'], false);
	eq('claude-opus-5', AQ_Alt_Text::model());
	if ($saved === null) { delete_option(AQ_Alt_Text::OPTION); } else { update_option(AQ_Alt_Text::OPTION, $saved, false); }
});
t('prices(): every offered model has an [in,out] price', function () {
	$p = AQ_Alt_Text::prices();
	foreach (['claude-opus-5', 'claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5', 'gpt-4o-mini', 'gpt-4o'] as $m) {
		ok(isset($p[$m][0], $p[$m][1]), "missing price for $m");
	}
});
t('cost_for(): input+output priced per 1M tokens; unknown model -> Opus tier', function () {
	// Haiku: $1/M in, $5/M out. 1,000,000 in + 200,000 out = $1.00 + $1.00 = $2.00
	eq(2.0, AQ_Alt_Text::cost_for('claude-haiku-4-5', ['in' => 1000000, 'out' => 200000]));
	// gpt-4o-mini: $0.15/M in, $0.60/M out. 2000 in + 40 out.
	eq(round((2000 * 0.15 + 40 * 0.60) / 1000000, 10), round(AQ_Alt_Text::cost_for('gpt-4o-mini', ['in' => 2000, 'out' => 40]), 10));
	// Unknown model falls back to Opus tier ($5/$25): 1,000,000 in = $5.00
	eq(5.0, AQ_Alt_Text::cost_for('made-up', ['in' => 1000000, 'out' => 0]));
	eq(0.0, AQ_Alt_Text::cost_for('claude-haiku-4-5', []));
});
t('cost_totals()/cost_bump()/cost_reset(): accumulate then clear', function () {
	$saved = get_option(AQ_Alt_Text::COST, null);
	AQ_Alt_Text::cost_reset();
	eq(['count' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0], AQ_Alt_Text::cost_totals());
	$c1 = AQ_Alt_Text::cost_bump('claude-haiku-4-5', ['in' => 1000, 'out' => 100]); // 0.001 + 0.0005 = 0.0015
	eq(round(0.0015, 10), round($c1, 10));
	AQ_Alt_Text::cost_bump('claude-haiku-4-5', ['in' => 1000, 'out' => 100]);
	$t = AQ_Alt_Text::cost_totals();
	eq(2, $t['count']); eq(2000, $t['in']); eq(200, $t['out']); eq(round(0.003, 10), round($t['cost'], 10));
	AQ_Alt_Text::cost_reset();
	eq(0, AQ_Alt_Text::cost_totals()['count']);
	if ($saved !== null) { update_option(AQ_Alt_Text::COST, $saved, false); }
});

foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);
exit(aq_tests_done());
