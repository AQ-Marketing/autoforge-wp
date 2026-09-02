<?php
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_OpenAI')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-openai.php'; }

t('models() lists gpt-4o-mini first and it is the default', function () {
	$m = AQ_OpenAI::models();
	eq('gpt-4o-mini', array_key_first($m));
	eq('gpt-4o-mini', AQ_OpenAI::MODEL);
	ok(array_key_exists('gpt-4o', $m), 'gpt-4o offered too');
	eq('gpt-4o-mini', AQ_OpenAI::resolve_model('not-a-model'));
	eq('gpt-4o', AQ_OpenAI::resolve_model('gpt-4o'));
});
t('build_payload(): model, vision image_url, json_schema, max_completion_tokens', function () {
	$p = AQ_OpenAI::build_payload('gpt-4o-mini', 'data:image/png;base64,AAAA', 'SYS', 'USER');
	eq('gpt-4o-mini', $p['model']);
	eq(300, $p['max_completion_tokens']);
	ok(!isset($p['max_tokens']), 'uses max_completion_tokens, not the deprecated max_tokens');
	eq('SYS', $p['messages'][0]['content']);
	eq('USER', $p['messages'][1]['content'][0]['text']);
	eq('data:image/png;base64,AAAA', $p['messages'][1]['content'][1]['image_url']['url']);
	eq('json_schema', $p['response_format']['type']);
	eq(true, $p['response_format']['json_schema']['strict']);
	eq(['alt', 'decorative', 'confidence'], $p['response_format']['json_schema']['schema']['required']);
	eq(false, $p['response_format']['json_schema']['schema']['additionalProperties']);
});
t('parse_response(): valid JSON content -> assoc {alt,decorative,confidence}', function () {
	$r = AQ_OpenAI::parse_response(['choices' => [['message' => ['content' => '{"alt":"A red truck","decorative":false,"confidence":"high"}']]], 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 25]]);
	eq(['alt' => 'A red truck', 'decorative' => false, 'confidence' => 'high', '_usage' => ['in' => 1200, 'out' => 25]], $r);
});
t('parse_response(): decorative true survives; missing confidence -> medium; usage defaults to 0', function () {
	$r = AQ_OpenAI::parse_response(['choices' => [['message' => ['content' => '{"alt":"","decorative":true}']]]]);
	eq(true, $r['decorative']); eq('medium', $r['confidence']); eq(['in' => 0, 'out' => 0], $r['_usage']);
});
t('parse_response(): a refusal is a WP_Error aq_refusal', function () {
	$r = AQ_OpenAI::parse_response(['choices' => [['message' => ['refusal' => 'no', 'content' => null]]]]);
	ok(is_wp_error($r)); eq('aq_refusal', $r->get_error_code());
});
t('parse_response(): non-JSON content / no message -> WP_Error', function () {
	ok(is_wp_error(AQ_OpenAI::parse_response(['choices' => [['message' => ['content' => 'not json']]]])));
	ok(is_wp_error(AQ_OpenAI::parse_response(['choices' => []])));
});
exit(aq_tests_done());
