<?php
/**
 * Deterministic regression tests for AQ_Content_SEO_Gate.
 *
 * Run locally when PHP is available, or set AQ_GATE_FILE to the deployed
 * class path when exercising the test on a controlled Pressable environment.
 */

define('ABSPATH', __DIR__ . '/');

$gate_file = getenv('AQ_GATE_FILE') ?: dirname(__DIR__) . '/includes/class-content-seo-gate.php';
if (!is_file($gate_file)) {
	throw new RuntimeException("Gate class file missing: {$gate_file}");
}
require_once $gate_file;

function gate_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function finding_codes(array $result): array {
	return array_values(array_unique(array_map(static fn(array $finding): string => (string) $finding['code'], $result['findings'] ?? [])));
}

$inventory = [
	[
		'path' => '/service-area/medford-ma/',
		'canonical' => 'https://example.test/service-area/medford-ma/',
		'title' => 'House Cleaning in Medford, MA',
		'h1' => 'House cleaning in Medford for busy households',
		'content' => str_repeat('weekly house cleaning for busy households near town center with dependable home care ', 8),
		'intent' => ['primary_intent' => 'house cleaning service in Medford, MA', 'role' => 'local-service', 'service' => 'house-cleaning', 'market' => 'Medford, MA', 'funnel' => 'commercial', 'canonical_path' => '/service-area/medford-ma/', 'differentiators' => ['Medford household context', 'renter service context']],
	],
];

$valid_intent = ['primary_intent' => 'house cleaning service in Arlington, MA', 'role' => 'local-service', 'service' => 'house-cleaning', 'market' => 'Arlington, MA', 'funnel' => 'commercial', 'canonical_path' => '/service-area/arlington-ma/', 'differentiators' => ['Arlington household context', 'move-related cleaning guidance']];
$distinct = [[
	'path' => '/service-area/arlington-ma/',
	'canonical' => 'https://example.test/service-area/arlington-ma/',
	'title' => 'House Cleaning & Maid Service in Arlington, MA',
	'h1' => 'Arlington household cleaning that fits your week',
	'content' => 'Recurring home cleaning, deep cleaning, and move-related help for Arlington households.',
]];

$pass = AQ_Content_SEO_Gate::evaluate($distinct, $inventory, ['/service-area/arlington-ma/' => $valid_intent], [], []);
gate_assert($pass['ok'] === true, 'Expected distinct service page to pass.');

$missing = AQ_Content_SEO_Gate::evaluate($distinct, $inventory, [], [], []);
gate_assert(in_array('missing_intent', finding_codes($missing), true), 'Expected missing intent to block.');

$duplicate_title = $distinct;
$duplicate_title[0]['title'] = 'House Cleaning in Medford, MA';
$duplicate_title[0]['h1'] = 'House cleaning in Medford for busy households';
$duplicate = AQ_Content_SEO_Gate::evaluate($duplicate_title, $inventory, ['/service-area/arlington-ma/' => $valid_intent], [], []);
gate_assert(in_array('duplicate_title', finding_codes($duplicate), true), 'Expected duplicate title to block.');
gate_assert(in_array('duplicate_h1', finding_codes($duplicate), true), 'Expected duplicate H1 to block.');

$overlap = $distinct;
$overlap[0]['content'] = $inventory[0]['content'];
$overlap_result = AQ_Content_SEO_Gate::evaluate($overlap, $inventory, ['/service-area/arlington-ma/' => $valid_intent], [], []);
gate_assert(in_array('high_content_overlap', finding_codes($overlap_result), true), 'Expected high same-role overlap to block.');

$expired = [['id' => 'expired-1', 'code' => 'high_content_overlap', 'path' => '/service-area/arlington-ma/', 'approved_by' => 'Justin', 'expires_on' => '2020-01-01']];
$expired_result = AQ_Content_SEO_Gate::evaluate($overlap, $inventory, ['/service-area/arlington-ma/' => $valid_intent], $expired, ['expired-1']);
gate_assert(in_array('high_content_overlap', finding_codes($expired_result), true), 'Expected expired exception to remain blocked.');

$batch_duplicate = [
	$distinct[0],
	[
		'path' => '/service-area/arlington-copy/',
		'canonical' => 'https://example.test/service-area/arlington-copy/',
		'title' => 'Arlington recurring cleaning help',
		'h1' => 'Arlington household support',
		'content' => $distinct[0]['content'],
	],
];
$batch_intents = [
	'/service-area/arlington-ma/' => $valid_intent,
	'/service-area/arlington-copy/' => array_merge($valid_intent, [
		'primary_intent' => 'recurring cleaning in Arlington, MA',
		'canonical_path' => '/service-area/arlington-copy/',
		'differentiators' => ['Recurring household care', 'Arlington scheduling context'],
	]),
];
$batch_result = AQ_Content_SEO_Gate::evaluate($batch_duplicate, [], $batch_intents, [], []);
gate_assert(in_array('high_content_overlap', finding_codes($batch_result), true), 'Expected two same-batch near-duplicate candidates to block.');

$implicit_canonical_inventory = [AQ_Content_SEO_Gate::row_from_content_item([
	'path' => '/existing-page/',
	'effective_canonical' => 'https://example.test/existing-page/',
	'title' => 'Existing page',
	'h1' => 'Existing page heading',
	'body' => 'Existing page body is intentionally different.',
], ['primary_intent' => 'existing informational page', 'role' => 'guide', 'service' => 'general', 'market' => 'Boston, MA', 'funnel' => 'informational', 'canonical_path' => '/existing-page/', 'differentiators' => ['Existing topic', 'Reference context']])];

$implicit_canonical_intent = ['primary_intent' => 'new informational page', 'role' => 'guide', 'service' => 'general', 'market' => 'Boston, MA', 'funnel' => 'informational', 'canonical_path' => '/new-page/', 'differentiators' => ['New topic', 'Different context']];
$implicit_canonical_candidate = [AQ_Content_SEO_Gate::row_from_content_item([
	'path' => '/new-page/',
	'seo' => ['canonical' => 'https://example.test/existing-page/'],
	'title' => 'New page',
	'h1' => 'New page heading',
	'body' => 'New page body is intentionally different.',
], $implicit_canonical_intent)];
$implicit_canonical_result = AQ_Content_SEO_Gate::evaluate($implicit_canonical_candidate, $implicit_canonical_inventory, ['/new-page/' => $implicit_canonical_intent], [], []);
gate_assert(in_array('duplicate_canonical', finding_codes($implicit_canonical_result), true), 'Expected an explicit canonical to match an existing page default canonical.');

$unscoped_exception = [[
	'id' => 'overlap-other-page', 'code' => 'high_content_overlap', 'path' => '/service-area/arlington-ma/',
	'related_path' => '/some-other-page/', 'approved_by' => 'Justin', 'approved_at' => '2026-08-03', 'expires_on' => '2026-12-31',
]];
$unscoped_result = AQ_Content_SEO_Gate::evaluate($overlap, $inventory, ['/service-area/arlington-ma/' => $valid_intent], $unscoped_exception, ['overlap-other-page']);
gate_assert(in_array('high_content_overlap', finding_codes($unscoped_result), true), 'Expected an exception scoped to another related page to remain blocked.');

$malformed_expiration = [[
	'id' => 'malformed-expiration', 'code' => 'high_content_overlap', 'path' => '/service-area/arlington-ma/',
	'related_path' => '/service-area/medford-ma/', 'approved_by' => 'Justin', 'approved_at' => '2026-08-03', 'expires_on' => '9999-not-a-date',
]];
$malformed_expiration_result = AQ_Content_SEO_Gate::evaluate($overlap, $inventory, ['/service-area/arlington-ma/' => $valid_intent], $malformed_expiration, ['malformed-expiration']);
gate_assert(in_array('high_content_overlap', finding_codes($malformed_expiration_result), true), 'Expected malformed exception expiration to remain blocked.');

if (getenv('AQ_SYNC_FILE')) {
	class WP_REST_Request {
		private $body;
		public function __construct(array $body) { $this->body = $body; }
		public function get_json_params() { return $this->body; }
	}
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
	}
	function home_url($path = '') { return 'https://example.test' . $path; }
	function get_posts() { throw new RuntimeException('REST validation reached the SEO gate instead of rejecting the invalid batch.'); }
	require_once getenv('AQ_SYNC_FILE');
	$rest_response = AQ_Content_Sync::rest_import(new WP_REST_Request([
		'items' => [
			['path' => 123],
			['path' => '/would-be-valid/', 'title' => 'Would be valid'],
		],
	]));
	gate_assert($rest_response->status === 422, 'Expected a REST batch containing an invalid item to reject with 422.');
	gate_assert(($rest_response->data['written'] ?? null) === 0, 'Expected an invalid REST batch to write zero items.');
	gate_assert(count((array) ($rest_response->data['log'] ?? [])) === 1, 'Expected an invalid REST batch to stop before the SEO gate or any write attempt.');
}

echo "PASS: content SEO gate deterministic cases passed\n";
