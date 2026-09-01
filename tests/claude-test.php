<?php
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Claude')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-claude.php'; }

// 1×1 transparent PNG (67 bytes) as a real image fixture.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aq-claude-test-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/one.png', $png);
file_put_contents($tmp . '/notes.txt', 'not an image');
$big = $tmp . '/big.jpg';
$fh = fopen($big, 'w'); fseek($fh, AQ_Claude::IMAGE_MAX_BYTES); fwrite($fh, '0'); fclose($fh); // 5 MB + 1 byte, sparse

t('models() lists Opus 5 first and it is the default', function () {
	$m = AQ_Claude::models();
	eq('claude-opus-5', array_key_first($m));
	eq('claude-opus-5', AQ_Claude::MODEL);
	eq('claude-opus-5', AQ_Claude::resolve_model('not-a-model'));
	eq('claude-haiku-4-5', AQ_Claude::resolve_model('claude-haiku-4-5'));
});
t('image_block() returns a base64 image content block for a readable PNG', function () use ($tmp, $png) {
	$b = AQ_Claude::image_block($tmp . '/one.png');
	eq('image', $b['type']);
	eq('base64', $b['source']['type']);
	eq('image/png', $b['source']['media_type']);
	eq(base64_encode($png), $b['source']['data']);
});
t('image_block() honours an explicit mime and rejects unsupported ones', function () use ($tmp) {
	eq('image/webp', AQ_Claude::image_block($tmp . '/one.png', 'image/webp')['source']['media_type']);
	eq(null, AQ_Claude::image_block($tmp . '/one.png', 'image/svg+xml'));
	eq(null, AQ_Claude::image_block($tmp . '/notes.txt'));
});
t('image_block() returns null for missing or oversize files', function () use ($tmp, $big) {
	eq(null, AQ_Claude::image_block($tmp . '/does-not-exist.png'));
	eq(null, AQ_Claude::image_block($big, 'image/jpeg'));
});

t('build_request(): plain string system, defaults, tools imply tool_choice auto', function () {
	$r = AQ_Claude::build_request(['system' => 'You are helpful.', 'messages' => [['role' => 'user', 'content' => 'hi']], 'tools' => [['name' => 'x', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => []]]]]);
	eq(AQ_Claude::ENDPOINT, $r['endpoint']);
	eq('You are helpful.', $r['payload']['system']);
	eq(8000, $r['payload']['max_tokens']);
	eq(['type' => 'auto'], $r['payload']['tool_choice']);
	eq('2023-06-01', $r['headers']['anthropic-version']);
});
t('build_request(): cache_system wraps the system prompt in a cached text block', function () {
	$r = AQ_Claude::build_request(['system' => 'stable prefix', 'cache_system' => true, 'messages' => []]);
	eq([['type' => 'text', 'text' => 'stable prefix', 'cache_control' => ['type' => 'ephemeral']]], $r['payload']['system']);
});
t('build_request(): effort becomes output_config.effort; invalid values are dropped', function () {
	eq(['effort' => 'high'], AQ_Claude::build_request(['messages' => [], 'effort' => 'high'])['payload']['output_config']);
	ok(!isset(AQ_Claude::build_request(['messages' => [], 'effort' => 'turbo'])['payload']['output_config']));
});
t('build_request(): Opus 5 gets the fallback beta header + fallbacks:default; other models do not', function () {
	$o = AQ_Claude::build_request(['messages' => [], 'model' => 'claude-opus-5']);
	eq(AQ_Claude::FALLBACK_BETA, $o['headers']['anthropic-beta']);
	eq('default', $o['payload']['fallbacks']);
	$s = AQ_Claude::build_request(['messages' => [], 'model' => 'claude-sonnet-5']);
	ok(!isset($s['headers']['anthropic-beta'])); ok(!isset($s['payload']['fallbacks']));
});
t('parse_response(): text + first tool_use + usage + raw content', function () {
	$res = AQ_Claude::parse_response([
		'stop_reason' => 'tool_use',
		'content' => [['type' => 'text', 'text' => 'Hello '], ['type' => 'tool_use', 'name' => 'set_alt_text', 'input' => ['alt' => 'x']], ['type' => 'tool_use', 'name' => 'second', 'input' => []]],
		'usage' => ['input_tokens' => 12, 'output_tokens' => 3, 'cache_read_input_tokens' => 10],
	]);
	eq(true, $res['ok']); eq('Hello', $res['text']); eq('set_alt_text', $res['tool_name']); eq(['alt' => 'x'], $res['tool_input']);
	eq('tool_use', $res['stop_reason']); eq(3, count($res['content']));
	eq(['input_tokens' => 12, 'output_tokens' => 3, 'cache_read_input_tokens' => 10, 'cache_creation_input_tokens' => 0], $res['usage']);
});
t('parse_response(): a refusal is a WP_Error, never a success', function () {
	$res = AQ_Claude::parse_response(['stop_reason' => 'refusal', 'content' => []]);
	ok(is_wp_error($res)); eq('aq_refusal', $res->get_error_code());
});

@unlink($tmp . '/one.png'); @unlink($tmp . '/notes.txt'); @unlink($big); @rmdir($tmp);
exit(aq_tests_done());
