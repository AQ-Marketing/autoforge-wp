<?php
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Claude'))   { require dirname(__DIR__) . '/plugin/aq-core/includes/class-claude.php'; }
if (!class_exists('AQ_Alt_Text')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-alt-text.php'; }

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

foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);
exit(aq_tests_done());
