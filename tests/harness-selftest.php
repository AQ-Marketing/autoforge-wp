<?php
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';

t('eq passes on identical values', function () { eq(1, 1); eq('a', 'a'); eq([1, 'b' => 2], [1, 'b' => 2]); });
t('eq is strict', function () { $threw = false; try { eq(1, '1'); } catch (\RuntimeException $e) { $threw = true; } ok($threw, 'eq(1, "1") should throw'); });
t('ok throws on falsy', function () { $threw = false; try { ok(0, 'zero'); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'zero'); } ok($threw); });
t('option shims round-trip (standalone) or real options exist (WP)', function () {
	update_option('aq_selftest_opt', ['x' => 1], false);
	eq(['x' => 1], get_option('aq_selftest_opt'));
	delete_option('aq_selftest_opt');
	eq(false, get_option('aq_selftest_opt'));
});
t('WP_Error shim behaves like the real one', function () {
	$e = new WP_Error('code_a', 'message a');
	ok(is_wp_error($e)); eq('code_a', $e->get_error_code()); eq('message a', $e->get_error_message());
});
exit(aq_tests_done());
