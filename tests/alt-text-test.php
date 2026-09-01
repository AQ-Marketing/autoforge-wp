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

foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);
exit(aq_tests_done());
