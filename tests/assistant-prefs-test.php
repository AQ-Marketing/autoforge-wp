<?php
/**
 * Unit tests for AQ_Assistant::sanitize_prefs — the pure launcher-position
 * sanitizer used by the POST /assistant/prefs handler. No WordPress bootstrap;
 * the class file loads with only the shims + ABSPATH defined.
 *
 *   php tests/assistant-prefs-test.php
 */
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Assistant')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-assistant.php'; }

t('valid input passes through unchanged', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'left', 'x' => 120, 'y' => 340]);
	eq(['side' => 'left', 'x' => 120, 'y' => 340], $r);
});

t('valid right side passes through', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'right', 'x' => 0, 'y' => 2000]);
	eq(['side' => 'right', 'x' => 0, 'y' => 2000], $r);
});

t('bad side falls back to the default (right)', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'top', 'x' => 40, 'y' => 40]);
	eq('right', $r['side']);
});

t('missing side falls back to the default (right)', function () {
	$r = AQ_Assistant::sanitize_prefs(['x' => 40, 'y' => 40]);
	eq('right', $r['side']);
});

t('out-of-range x/y are clamped to 0..2000', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'left', 'x' => 99999, 'y' => -50]);
	eq(2000, $r['x']);
	eq(0, $r['y']);
});

t('non-numeric x/y fall back to the default margin (20)', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'right', 'x' => 'abc', 'y' => null]);
	eq(20, $r['x']);
	eq(20, $r['y']);
});

t('numeric string x/y are accepted and cast to int', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'left', 'x' => '150', 'y' => '75.9']);
	eq(150, $r['x']);
	eq(75, $r['y']);
});

t('boolean x is treated as non-numeric → default', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'right', 'x' => true, 'y' => 30]);
	eq(20, $r['x']);
	eq(30, $r['y']);
});

t('unknown keys are dropped', function () {
	$r = AQ_Assistant::sanitize_prefs(['side' => 'left', 'x' => 10, 'y' => 10, 'evil' => 'x', 'z' => 5]);
	eq(['side', 'x', 'y'], array_keys($r));
});

t('empty input → full defaults', function () {
	$r = AQ_Assistant::sanitize_prefs([]);
	eq(['side' => 'right', 'x' => 20, 'y' => 20], $r);
});

exit(aq_tests_done());
