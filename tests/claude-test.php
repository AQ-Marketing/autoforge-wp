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

@unlink($tmp . '/one.png'); @unlink($tmp . '/notes.txt'); @unlink($big); @rmdir($tmp);
exit(aq_tests_done());
