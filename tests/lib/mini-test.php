<?php
/**
 * Tiny dependency-free test runner for aq-core unit tests.
 *
 *   Standalone:  php tests/<name>-test.php
 *   Inside WP:   wp eval-file tests/<name>-test.php   (real WordPress functions; shims are skipped)
 *
 * t() registers + runs one test; eq()/ok() assert; aq_tests_done() prints the
 * summary and returns the process exit code (1 when anything failed).
 */
$GLOBALS['aq_tests'] = ['pass' => 0, 'fail' => 0];

function t(string $name, callable $fn): void {
	try {
		$fn();
		$GLOBALS['aq_tests']['pass']++;
		echo "  ok   {$name}\n";
	} catch (\Throwable $e) {
		$GLOBALS['aq_tests']['fail']++;
		echo "  FAIL {$name}\n       " . $e->getMessage() . "\n";
	}
}

function eq($expected, $actual, string $msg = ''): void {
	if ($expected !== $actual) {
		throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function ok($cond, string $msg = 'expected a truthy value'): void {
	if (!$cond) {
		throw new \RuntimeException($msg);
	}
}

function aq_tests_done(): int {
	$r = $GLOBALS['aq_tests'];
	echo "\n{$r['pass']} passed, {$r['fail']} failed\n";
	return $r['fail'] > 0 ? 1 : 0;
}
