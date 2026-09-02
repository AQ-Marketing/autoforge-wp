# Alt Text Generator (aq-core v0.3.49) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every image that lands in an AutoForge site's media library with an empty alt gets an honest, short alt text written by Claude in the background, plus a one-click backfill for the existing library — shipped as aq-core **v0.3.49** together with the shared `AQ_Claude` client upgrades the v0.3.50 site assistant needs.

**Architecture:** A new `AQ_Alt_Text` class (`plugin/aq-core/includes/class-alt-text.php`) hooks `wp_generate_attachment_metadata` to *enqueue* eligible images (never generate inline), a WP-Cron runner + `spawn_cron()` fallback processes the queue in bounded batches, and a REST route + WP-CLI command drive a manual "Generate missing alt text" backfill. Pure logic (eligibility, prompt, parsing, source-file choice, backoff, daily cap) lives in static methods with no WordPress calls so it is unit-testable without WordPress; the WordPress-bound layer (queue option, meta writes, cron, REST, admin screen) wraps it. `AQ_Claude` gains `image_block()`, prompt caching, `effort`, raw `content` + `usage` in the result, `refusal` handling and Opus 5 server-side fallbacks, via a `build_request()` / `parse_response()` split that makes the wire format testable.

**Tech Stack:** PHP 8.0+ WordPress plugin (no Composer, no SDK — plain `wp_remote_post` to the Anthropic Messages API), WP-Cron, WP REST API (`aq/v1`), WP-CLI, vanilla JS admin screen in the existing `AQ_Admin_Hub` style. Tooling: `npm run lint` (php-parser syntax check — there is **no PHP binary on the dev machine**), `npm run build:release`, a dependency-free PHP mini test runner executed either locally (if `php` is installed) or on staging via `wp eval-file`.

**Spec:** `docs/superpowers/specs/2026-09-01-alt-text-generator-design.md` (read it first).
**Worktree / branch:** `C:\Users\justi\Apps\Work\AutoForge-WP-alt-text` on `feat/alt-text` (cut from `origin/main` @ v0.3.48). Never build in the main clone (`C:\Users\justi\Apps\Work\AutoForge WP` — stale branch with uncommitted WIP).
**Do not commit secrets or client credentials into this repo — it is PUBLIC.** Staging host/creds live in the project memory, not here.

---

## File map

| File | Responsibility |
|---|---|
| `tests/lib/mini-test.php` (new) | 40-line test runner: `t()`, `eq()`, `ok()`, `aq_tests_done()` |
| `tests/lib/wp-shims.php` (new) | WordPress stand-ins defined only when WP isn't loaded (`ABSPATH`, `WP_Error`, options, cron, sanitizers) |
| `tests/claude-test.php` (new) | Unit tests for the `AQ_Claude` upgrades |
| `tests/alt-text-test.php` (new) | Unit tests for `AQ_Alt_Text` pure logic + queue/daily-cap primitives |
| `plugin/aq-core/includes/class-claude.php` (modify) | Shared Claude client: Opus 5 default, `image_block()`, `build_request()`, `parse_response()`, caching/effort/fallbacks/refusal |
| `plugin/aq-core/includes/class-alt-text.php` (new) | The feature: eligibility, prompt, queue, cron, generation, persistence, REST, CLI, admin screen |
| `plugin/aq-core/includes/class-admin-hub.php` (modify `nav()`) | Adds the **Media** entry to the Content group |
| `plugin/aq-core/includes/class-help.php` (modify `render()`) | Adds the **Media / alt text** Help topic |
| `plugin/aq-core/aq-core.php` (modify) | `require_once` + `AQ_Alt_Text::register()`; version → 0.3.49 |
| `package.json` (modify) | version → 0.3.49 |
| `~/.claude/skills/wordpress/reference/pre-launch-checklist.md` (outside repo) | New §3 rule + changelog v6 |

`tests/` sits at the repo root, outside `plugin/aq-core/`, so `build-release.mjs` never ships it and `lint-php.mjs` (which walks only `plugin/aq-core`) never lints it.

---

### Task 0: Bootstrap the worktree

**Files:** none (environment only)

- [ ] **Step 1: Install dev dependencies in the worktree** (it has no `node_modules`)

Run (PowerShell or Git Bash, in `C:\Users\justi\Apps\Work\AutoForge-WP-alt-text`):
```bash
npm ci
```
Expected: installs `archiver`, `php-parser`, `tailwindcss`; no errors.

- [ ] **Step 2: Confirm the lint baseline passes before any change**

Run:
```bash
npm run lint
```
Expected: last line reports `0` new errors (baseline failures inside `thrust/` are pre-recorded in `migration/lint-php.baseline.json` and don't count).

- [ ] **Step 3 (optional but recommended — needs Justin's OK, it installs software): install a PHP CLI so tests run locally**

Run:
```bash
winget install --id PHP.PHP.8.3 -e
```
Then open a new shell and confirm `php -v` prints 8.3.x. If Justin declines, skip: every test task below also gives the `wp eval-file` command to run the same tests on the ACME staging site (Task 10).

---

### Task 1: Dependency-free test harness

**Files:**
- Create: `tests/lib/mini-test.php`
- Create: `tests/lib/wp-shims.php`
- Create: `tests/harness-selftest.php`

- [ ] **Step 1: Write the runner**

`tests/lib/mini-test.php`:
```php
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
```

- [ ] **Step 2: Write the WordPress shims**

`tests/lib/wp-shims.php`:
```php
<?php
/**
 * Minimal stand-ins for the WordPress functions/classes the unit-tested code
 * touches. Every definition is guarded, so under `wp eval-file` (WordPress
 * loaded) this file defines NOTHING and the real functions are used.
 */
if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__, 2) . '/');
}
if (!class_exists('WP_Error')) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return $thing instanceof WP_Error; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($s) { $s = strip_tags((string) $s); $s = preg_replace('/[\r\n\t ]+/', ' ', $s); return trim($s); }
}
if (!function_exists('apply_filters')) { function apply_filters($hook, $value) { return $value; } }
if (!function_exists('get_option')) {
	$GLOBALS['aq_shim_options'] = [];
	function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['aq_shim_options']) ? $GLOBALS['aq_shim_options'][$key] : $default; }
	function update_option($key, $value, $autoload = null) { $GLOBALS['aq_shim_options'][$key] = $value; return true; }
	function delete_option($key) { unset($GLOBALS['aq_shim_options'][$key]); return true; }
}
if (!function_exists('wp_next_scheduled')) {
	$GLOBALS['aq_shim_cron'] = [];
	function wp_next_scheduled($hook, $args = []) { return $GLOBALS['aq_shim_cron'][$hook] ?? false; }
	function wp_schedule_single_event($timestamp, $hook, $args = []) { $GLOBALS['aq_shim_cron'][$hook] = $timestamp; return true; }
}
```

- [ ] **Step 3: Write a self-test that proves the harness reports pass/fail correctly**

`tests/harness-selftest.php`:
```php
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
```

- [ ] **Step 4: Run it**

Run (locally if PHP exists; otherwise defer to Task 10 Step 4 and continue):
```bash
php tests/harness-selftest.php
```
Expected output ends with `5 passed, 0 failed` and exit code 0.

- [ ] **Step 5: Commit**

```bash
git add tests/lib/mini-test.php tests/lib/wp-shims.php tests/harness-selftest.php
git commit -m "test: add dependency-free PHP mini test runner + WordPress shims"
```

---

### Task 2: `AQ_Claude` — Opus 5 default + `image_block()`

**Files:**
- Modify: `plugin/aq-core/includes/class-claude.php:36-45` (`MODEL` + `models()`), add `image_block()` after `resolve_model()` (line ~84)
- Test: `tests/claude-test.php`

- [ ] **Step 1: Write the failing tests**

`tests/claude-test.php`:
```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/claude-test.php`
Expected: the first test FAILS (`expected 'claude-opus-5', got 'claude-opus-4-8'`) and the `image_block` tests FAIL with `Call to undefined method AQ_Claude::image_block()`. (No PHP locally → proceed; Task 10 runs the suite on staging.)

- [ ] **Step 3: Implement — replace the `MODEL` constant and `models()`, add constants + `image_block()`**

In `plugin/aq-core/includes/class-claude.php`, replace lines 36–45 (`const MODEL …` through the end of `models()`) with:
```php
	const MODEL      = 'claude-opus-5'; // default — newest, most capable Claude model

	/** Beta header that enables server-side refusal fallbacks on Opus 5. */
	const FALLBACK_BETA   = 'server-side-fallback-2026-07-01';
	/** Image inputs the Messages API accepts, and its per-image size limit (5 MB). */
	const IMAGE_MIMES     = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
	const IMAGE_MAX_BYTES = 5242880;
	/** Valid output_config.effort levels. */
	const EFFORTS         = ['low', 'medium', 'high', 'xhigh', 'max'];

	/** Claude models offered in the admin model pickers (default first). */
	public static function models(): array {
		return [
			'claude-opus-5'    => 'Claude Opus 5 (newest, most capable)',
			'claude-opus-4-8'  => 'Claude Opus 4.8',
			'claude-sonnet-5'  => 'Claude Sonnet 5 (faster, lower cost)',
			'claude-haiku-4-5' => 'Claude Haiku 4.5 (fastest, cheapest)',
		];
	}
```
Then add, directly after `resolve_model()` (after its closing brace):
```php
	/**
	 * Build a base64 image content block for the Messages API from a local file,
	 * or null when the file is unreadable, not a supported image, or over the
	 * 5 MB per-image limit. Pass $mime when known (WordPress knows it); otherwise
	 * it is sniffed with getimagesize() and finally the file extension.
	 */
	public static function image_block(string $path, string $mime = ''): ?array {
		if ($path === '' || !is_file($path) || !is_readable($path)) {
			return null;
		}
		$size = filesize($path);
		if ($size === false || $size <= 0 || $size > self::IMAGE_MAX_BYTES) {
			return null;
		}
		if ($mime === '') {
			$info = @getimagesize($path);
			$mime = is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : '';
			if ($mime === '') {
				$map  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
				$mime = $map[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? '';
			}
		}
		if (!in_array($mime, self::IMAGE_MIMES, true)) {
			return null;
		}
		$data = file_get_contents($path);
		if ($data === false) {
			return null;
		}
		return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)]];
	}
```

- [ ] **Step 4: Run tests + lint**

Run: `php tests/claude-test.php` → Expected: `4 passed, 0 failed`.
Run: `npm run lint` → Expected: 0 new errors.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/includes/class-claude.php tests/claude-test.php
git commit -m "feat(claude): Opus 5 default model + image_block() for vision input"
```

---

### Task 3: `AQ_Claude` — `build_request()` / `parse_response()` with caching, effort, fallbacks, refusal, usage

**Files:**
- Modify: `plugin/aq-core/includes/class-claude.php` — replace `message()` (currently lines ~86–175 in the original file; after Task 2 the numbers shift)
- Test: `tests/claude-test.php` (append)

- [ ] **Step 1: Append failing tests** (insert before the cleanup/`exit` lines at the bottom of `tests/claude-test.php`)

```php
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
```

- [ ] **Step 2: Run to verify they fail**

Run: `php tests/claude-test.php` → Expected: 6 new FAILs (`undefined method build_request` / `parse_response`).

- [ ] **Step 3: Replace `message()` with the split implementation**

In `class-claude.php`, delete the whole existing `message()` method (from its docblock `/** Send one Messages API request…` through its closing brace) and insert:
```php
	/**
	 * Assemble endpoint + headers + JSON payload for one Messages API call. Pure
	 * (no HTTP) so the wire format is unit-testable.
	 *
	 * @param array $args {
	 *   @type string|array $system       System prompt (string, or an array of content blocks).
	 *   @type bool         $cache_system Wrap a string system prompt in one cached text block
	 *                                    (cache_control ephemeral) — use for a stable prefix.
	 *   @type array        $messages     [['role'=>'user','content'=>string|array], ...] (required).
	 *   @type array        $tools        Anthropic tool defs (optional).
	 *   @type array        $tool_choice  e.g. ['type'=>'auto'] or ['type'=>'tool','name'=>...] (optional).
	 *   @type string       $effort       low|medium|high|xhigh|max → output_config.effort (optional).
	 *   @type array        $thinking     Passed through as-is (optional; omit for the model default).
	 *   @type int          $max_tokens   Output cap (default 8000; keep <=16000 to avoid HTTP timeouts).
	 *   @type string       $model        Model id (default self::MODEL).
	 * }
	 * @return array{endpoint:string,headers:array,payload:array}
	 */
	public static function build_request(array $args): array {
		$model = self::resolve_model((string) ($args['model'] ?? self::MODEL));

		if (self::using_proxy()) {
			$endpoint = self::proxy_url() . '/v1/messages';
			$headers  = ['content-type' => 'application/json', 'authorization' => 'Bearer ' . self::proxy_token()];
		} else {
			$endpoint = self::ENDPOINT;
			$headers  = ['content-type' => 'application/json', 'x-api-key' => self::api_key(), 'anthropic-version' => self::API_VER];
		}

		$payload = [
			'model'      => $model,
			'max_tokens' => (int) ($args['max_tokens'] ?? 8000),
			'messages'   => array_values((array) ($args['messages'] ?? [])),
		];

		$system = $args['system'] ?? '';
		if (is_array($system) && $system) {
			$payload['system'] = array_values($system);
		} elseif (is_string($system) && $system !== '') {
			$payload['system'] = !empty($args['cache_system'])
				? [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]]
				: $system;
		}
		if (!empty($args['tools'])) {
			$payload['tools']       = array_values((array) $args['tools']);
			$payload['tool_choice'] = is_array($args['tool_choice'] ?? null) ? $args['tool_choice'] : ['type' => 'auto'];
		}
		if (!empty($args['effort']) && in_array((string) $args['effort'], self::EFFORTS, true)) {
			$payload['output_config'] = ['effort' => (string) $args['effort']];
		}
		if (isset($args['thinking']) && is_array($args['thinking'])) {
			$payload['thinking'] = $args['thinking'];
		}
		// Opus 5: let Anthropic re-run a safety-classifier refusal on a fallback
		// model server-side instead of handing us a bare refusal.
		if ($model === 'claude-opus-5') {
			$headers['anthropic-beta'] = self::FALLBACK_BETA;
			$payload['fallbacks']      = 'default';
		}
		return ['endpoint' => $endpoint, 'headers' => $headers, 'payload' => $payload];
	}

	/**
	 * Normalize a decoded Messages API response. Concatenates text blocks, exposes
	 * the FIRST tool_use as tool_name/tool_input, keeps the raw content blocks
	 * (needed to echo thinking blocks back on a follow-up turn) and the usage
	 * counters. A `refusal` stop reason is returned as a WP_Error so no caller can
	 * mistake it for a result.
	 *
	 * @return array{ok:bool,text:string,tool_name:string,tool_input:?array,stop_reason:string,content:array,usage:array}|WP_Error
	 */
	public static function parse_response(array $data) {
		$stop = (string) ($data['stop_reason'] ?? '');
		if ($stop === 'refusal') {
			return new WP_Error('aq_refusal', 'The AI declined this request, so nothing was changed.', ['status' => 422]);
		}
		$text = ''; $tool_name = ''; $tool_input = null; $content = [];
		foreach ((array) ($data['content'] ?? []) as $block) {
			if (!is_array($block)) {
				continue;
			}
			$content[] = $block;
			$type = (string) ($block['type'] ?? '');
			if ($type === 'text') {
				$text .= (string) ($block['text'] ?? '');
			} elseif ($type === 'tool_use' && $tool_input === null) {
				$tool_name  = (string) ($block['name'] ?? '');
				$tool_input = is_array($block['input'] ?? null) ? $block['input'] : [];
			}
		}
		$u = is_array($data['usage'] ?? null) ? $data['usage'] : [];
		return [
			'ok'          => true,
			'text'        => trim($text),
			'tool_name'   => $tool_name,
			'tool_input'  => $tool_input,
			'stop_reason' => $stop,
			'content'     => $content,
			'usage'       => [
				'input_tokens'                => (int) ($u['input_tokens'] ?? 0),
				'output_tokens'               => (int) ($u['output_tokens'] ?? 0),
				'cache_read_input_tokens'     => (int) ($u['cache_read_input_tokens'] ?? 0),
				'cache_creation_input_tokens' => (int) ($u['cache_creation_input_tokens'] ?? 0),
			],
		];
	}

	/**
	 * Send one Messages API request (see build_request() for $args, plus
	 * `timeout` HTTP seconds, default 120). Returns parse_response()'s array on
	 * success or a WP_Error.
	 */
	public static function message(array $args) {
		if (!self::using_proxy() && self::api_key() === '') {
			return new WP_Error('aq_no_key', 'No Claude API key configured. Add one under AutoForge → Integrations, or point this site at a key proxy.', ['status' => 400]);
		}
		$req  = self::build_request($args);
		$resp = wp_remote_post($req['endpoint'], [
			'timeout' => (int) ($args['timeout'] ?? 120),
			'headers' => $req['headers'],
			'body'    => wp_json_encode($req['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
		if (is_wp_error($resp)) {
			return new WP_Error('aq_http', 'Could not reach the AI service: ' . $resp->get_error_message(), ['status' => 502]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200 || !is_array($data)) {
			$msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
			return new WP_Error('aq_api', 'AI service error: ' . $msg, ['status' => 502]);
		}
		return self::parse_response($data);
	}
```
Also update the class docblock (top of file, the "Wire contract" comment) by adding one line under `- reply:`: `Also returned: raw content[] (for thinking-block replay) and usage counters; stop_reason "refusal" → WP_Error aq_refusal.` And update `KEY PROXY` note: `The anthropic-beta header (Opus 5 fallbacks) is sent to the proxy too — the proxy must forward it.`

- [ ] **Step 4: Run tests + lint**

Run: `php tests/claude-test.php` → Expected: `10 passed, 0 failed`.
Run: `npm run lint` → Expected: 0 new errors.
Sanity: `grep -n "tool_input" plugin/aq-core/includes/class-editor-review.php plugin/aq-core/includes/class-seo-agent.php` — existing callers read `['tool_input']` / `['text']`, both still present. No changes needed there.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/includes/class-claude.php tests/claude-test.php
git commit -m "feat(claude): split build_request/parse_response; prompt caching, effort, Opus 5 fallbacks, refusal + usage"
```

---

### Task 4: `AQ_Alt_Text` — pure logic (eligibility, source file, prompt, tool, parsing)

**Files:**
- Create: `plugin/aq-core/includes/class-alt-text.php`
- Test: `tests/alt-text-test.php`

- [ ] **Step 1: Write the failing tests**

`tests/alt-text-test.php`:
```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/alt-text-test.php` → Expected: fatal `Failed opening required '.../class-alt-text.php'` (file doesn't exist yet).

- [ ] **Step 3: Create the class with constants + the pure methods**

`plugin/aq-core/includes/class-alt-text.php`:
```php
<?php
/**
 * AQ Alt Text — writes honest alt text for library images, automatically.
 *
 * Every image that arrives in the media library with an EMPTY alt (wp-admin
 * upload, the builder's media picker, `wp aq images`, the AutoForge → Import
 * sideload) is queued and, within about a minute, described by Claude in the
 * background. Alt text a human wrote is never touched. A dashboard button
 * (AutoForge → Media) backfills the existing library in batches, and
 * `wp aq alt-text` does the same from WP-CLI.
 *
 * Shape:
 *   - wp_generate_attachment_metadata (filter, pri 20) → enqueue()   [never generates inline]
 *   - WP-Cron event aq_alt_text_run → process_queue()  + spawn_cron() fallback on admin_init
 *   - POST aq/v1/alt-text/run  (manage_options) → process_missing()/process_queue() in small batches
 *   - wp aq alt-text [--missing] [--limit=<n>] [--dry-run]
 *
 * Pure logic (eligibility, source-file choice, prompt, parsing, backoff, daily
 * cap arithmetic) has no WordPress calls so it is unit-testable; everything
 * WordPress-bound lives in the "WordPress layer" section below.
 *
 * Options (all autoload=false): aq_alt_text {enabled, model, daily_cap},
 * aq_alt_queue {id => {queued_at, attempts, next_at}}, aq_alt_daily {day, count},
 * aq_alt_last_run (unix ts). Attachment meta: _wp_attachment_image_alt (the alt),
 * _aq_alt_source=ai, _aq_alt_at, _aq_alt_model, _aq_alt_confidence,
 * _aq_alt_decorative=1 (never re-queued), _aq_alt_fail=<reason>, _aq_alt_skip=<reason>.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Alt_Text {

	const CAP      = 'manage_options';
	const SLUG     = 'aq-media';
	const OPTION   = 'aq_alt_text';
	const QUEUE    = 'aq_alt_queue';
	const DAILY    = 'aq_alt_daily';
	const LAST_RUN = 'aq_alt_last_run';
	const HOOK     = 'aq_alt_text_run';

	const MIMES        = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
	const MAX_BYTES    = 5242880; // 5 MB — the API's per-image limit
	const MAX_ATTEMPTS = 3;
	const CRON_BATCH   = 8;
	const CRON_BUDGET  = 40;   // seconds per cron pass
	const REST_BATCH   = 5;
	const REST_BUDGET  = 25;   // seconds per dashboard batch (stays under typical 60 s PHP limits)
	const ALT_MAX_LEN  = 200;

	/* ============================================================
	 * Pure logic — no WordPress calls (unit-tested in tests/alt-text-test.php)
	 * ============================================================ */

	public static function eligible_mime(string $mime): bool {
		return in_array(strtolower(trim($mime)), self::MIMES, true);
	}

	/** Generate only when enabled, a supported image, alt empty, and not marked decorative. */
	public static function should_generate(string $mime, string $current_alt, bool $decorative_marked, bool $enabled): bool {
		return $enabled && self::eligible_mime($mime) && trim($current_alt) === '' && !$decorative_marked;
	}

	/**
	 * Choose the file to send: a downsized sub-size first (large → medium_large →
	 * the engine's ka-1280/ka-768), else the original when it is ≤ 5 MB.
	 * $meta is wp_get_attachment_metadata(); $dir the upload directory of the
	 * original; $original the original's absolute path.
	 *
	 * @return array{path:string,mime:string}|null
	 */
	public static function pick_source(array $meta, string $dir, string $original): ?array {
		$sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];
		foreach (['large', 'medium_large', 'ka-1280', 'ka-768'] as $name) {
			if (empty($sizes[$name]['file'])) {
				continue;
			}
			$path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $sizes[$name]['file'];
			if (is_file($path) && filesize($path) <= self::MAX_BYTES) {
				return ['path' => $path, 'mime' => (string) ($sizes[$name]['mime-type'] ?? '')];
			}
		}
		if ($original !== '' && is_file($original) && filesize($original) <= self::MAX_BYTES) {
			return ['path' => $original, 'mime' => ''];
		}
		return null;
	}

	/** The rules Claude follows — mirrors the page-complete Gate A8 alt-text bar. */
	public static function system_prompt(): string {
		return implode("\n", [
			'You write alt text for images on a local business website.',
			'Rules:',
			'- Describe only what is visible. One sentence, at most 125 characters, sentence case, no trailing period needed.',
			'- Never start with "Image of", "Picture of", "Photo of" or "A photo of"; never repeat the file name.',
			'- No marketing claims, superlatives, awards, numbers or years unless they are visibly printed in the image.',
			'- Mention a town, service or brand name only when it is genuinely part of what the image shows (lettering on a truck, a storefront sign). Never add them as keywords.',
			'- If the image is purely decorative (a texture, gradient, divider, abstract shape or icon-like ornament), set decorative to true and leave alt empty.',
			'- Plain words a screen-reader user would find useful. No em dash characters.',
			'Respond only by calling the set_alt_text tool.',
		]);
	}

	/**
	 * The per-image user message: context lines (omitted when empty) + the ask.
	 * $ctx keys: business, industry, location, towns[], filename, page.
	 */
	public static function user_text(array $ctx): string {
		$lines = ['Write the alt text for the attached image.', 'Background context only (do not add anything that is not visible in the image):'];
		$biz = trim((string) ($ctx['business'] ?? ''));
		if ($biz !== '') {
			$ind = trim((string) ($ctx['industry'] ?? ''));
			$loc = trim((string) ($ctx['location'] ?? ''));
			$lines[] = '- Business: ' . $biz . ($ind !== '' ? ' (' . $ind . ')' : '') . ($loc !== '' ? ', ' . $loc : '');
		}
		$towns = array_values(array_filter(array_map('strval', (array) ($ctx['towns'] ?? []))));
		if ($towns) {
			$lines[] = '- Nearby towns: ' . implode(', ', $towns);
		}
		if (trim((string) ($ctx['filename'] ?? '')) !== '') {
			$lines[] = '- File name: ' . trim((string) $ctx['filename']);
		}
		if (trim((string) ($ctx['page'] ?? '')) !== '') {
			$lines[] = '- Used on page: ' . trim((string) $ctx['page']);
		}
		$lines[] = 'Call set_alt_text.';
		return implode("\n", $lines);
	}

	/** The forced tool — structured output, no free-text parsing. */
	public static function tool_def(): array {
		return AQ_Claude::tool(
			'set_alt_text',
			'Record the alt text for the attached image.',
			[
				'alt'        => ['type' => 'string', 'description' => 'The alt text (one sentence, ≤125 characters), or an empty string when the image is decorative.'],
				'decorative' => ['type' => 'boolean', 'description' => 'True only for purely decorative images (texture, gradient, divider, ornament).'],
				'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low'], 'description' => 'How sure you are the description is accurate.'],
			],
			['alt', 'decorative']
		);
	}

	/** Clean a model-written alt: no tags, no "Image of…", no dashes, sentence case, ≤ 200 chars. */
	public static function normalize_alt(string $alt): string {
		$alt = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($alt)));
		$alt = preg_replace('/^(?:an?\s+)?(?:image|picture|photo|photograph|screenshot)\s+(?:of|showing)\s+/iu', '', $alt);
		$alt = str_replace(['—', '–'], ',', $alt); // client copy rule: no em/en dashes
		$alt = preg_replace('/\s+,/', ',', $alt);
		$alt = trim($alt, " \t\n\r\0\x0B.");
		if ($alt === '') {
			return '';
		}
		$alt = mb_strtoupper(mb_substr($alt, 0, 1)) . mb_substr($alt, 1);
		if (mb_strlen($alt) > self::ALT_MAX_LEN) {
			$alt = mb_substr($alt, 0, self::ALT_MAX_LEN); // hard cap (no rtrim: keeps length exactly ≤ 200)
		}
		return $alt;
	}

	/**
	 * Validate the tool call. Returns null when unusable (no tool input, or an
	 * empty alt on a non-decorative image).
	 *
	 * @return array{alt:string,decorative:bool,confidence:string}|null
	 */
	public static function parse_result($tool_input): ?array {
		if (!is_array($tool_input)) {
			return null;
		}
		$decorative = !empty($tool_input['decorative']) && $tool_input['decorative'] !== 'false';
		$alt        = self::normalize_alt((string) ($tool_input['alt'] ?? ''));
		$conf       = strtolower((string) ($tool_input['confidence'] ?? 'medium'));
		if (!in_array($conf, ['high', 'medium', 'low'], true)) {
			$conf = 'medium';
		}
		if (!$decorative && $alt === '') {
			return null;
		}
		return ['alt' => $decorative ? '' : $alt, 'decorative' => $decorative, 'confidence' => $conf];
	}

	/** Retry delay (seconds) after the Nth failed attempt: 2 min, 10 min, then 1 h. */
	public static function backoff_for(int $attempts): int {
		return [1 => 120, 2 => 600][$attempts] ?? 3600;
	}
}
```

- [ ] **Step 4: Run tests + lint**

Run: `php tests/alt-text-test.php` → Expected: `8 passed, 0 failed`.
Run: `npm run lint` → Expected: 0 new errors.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/includes/class-alt-text.php tests/alt-text-test.php
git commit -m "feat(alt-text): pure core — eligibility, source choice, prompt, tool, parsing"
```

---

### Task 5: `AQ_Alt_Text` — settings, queue, scheduling, daily cap

**Files:**
- Modify: `plugin/aq-core/includes/class-alt-text.php` (append methods inside the class)
- Test: `tests/alt-text-test.php` (append)

- [ ] **Step 1: Append failing tests** (before the cleanup/`exit` lines)

```php
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
```

- [ ] **Step 2: Run to verify they fail**

Run: `php tests/alt-text-test.php` → Expected: 5 new FAILs (`undefined method AQ_Alt_Text::settings()` …).

- [ ] **Step 3: Add the settings/queue/daily methods** (inside the class, after `backoff_for()`)

```php
	/* ============================================================
	 * Settings
	 * ============================================================ */

	public static function defaults(): array {
		return ['enabled' => true, 'model' => 'claude-opus-5', 'daily_cap' => 300];
	}

	public static function settings(): array {
		$o = get_option(self::OPTION, []);
		return array_merge(self::defaults(), is_array($o) ? $o : []);
	}

	/** Is a Claude key / key proxy configured? (Backfill works even when auto mode is off.) */
	public static function claude_ready(): bool {
		return class_exists('AQ_Claude') && AQ_Claude::is_ready();
	}

	/** Auto-generate on new uploads? Setting on AND Claude ready. */
	public static function enabled(): bool {
		return !empty(self::settings()['enabled']) && self::claude_ready();
	}

	public static function model(): string {
		return AQ_Claude::resolve_model((string) self::settings()['model']);
	}

	/* ============================================================
	 * Queue (option-backed) + scheduling
	 * ============================================================ */

	/** @return array<int,array{queued_at:int,attempts:int,next_at:int}> */
	public static function queue(): array {
		$q = get_option(self::QUEUE, []);
		return is_array($q) ? $q : [];
	}

	private static function save_queue(array $q): void {
		if ($q) {
			update_option(self::QUEUE, $q, false);
		} else {
			delete_option(self::QUEUE);
		}
	}

	public static function enqueue(int $id): void {
		if ($id <= 0) {
			return;
		}
		$q = self::queue();
		if (isset($q[$id])) {
			return;
		}
		$q[$id] = ['queued_at' => time(), 'attempts' => 0, 'next_at' => time()];
		self::save_queue($q);
		self::schedule(30);
	}

	/** Schedule the runner once (no-op when already pending). */
	public static function schedule(int $delay = 30): void {
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + max(5, $delay), self::HOOK);
		}
	}

	/** Record one failed attempt for $id; drop the entry after MAX_ATTEMPTS. Pure on the array. */
	public static function mark_failure(array $q, int $id): array {
		if (!isset($q[$id])) {
			return $q;
		}
		$q[$id]['attempts'] = (int) ($q[$id]['attempts'] ?? 0) + 1;
		if ($q[$id]['attempts'] >= self::MAX_ATTEMPTS) {
			unset($q[$id]);
		} else {
			$q[$id]['next_at'] = time() + self::backoff_for((int) $q[$id]['attempts']);
		}
		return $q;
	}

	/** Attachment ids whose retry time has passed, oldest queued first. */
	public static function due_ids(array $q): array {
		$due = array_filter($q, function ($e) { return (int) ($e['next_at'] ?? 0) <= time(); });
		uasort($due, function ($a, $b) { return (int) ($a['queued_at'] ?? 0) <=> (int) ($b['queued_at'] ?? 0); });
		return array_map('intval', array_keys($due));
	}

	/* ============================================================
	 * Daily cap
	 * ============================================================ */

	/** @return array{day:string,count:int} — resets automatically on a new UTC day. */
	public static function daily(): array {
		$d     = get_option(self::DAILY, []);
		$today = gmdate('Y-m-d');
		if (!is_array($d) || (string) ($d['day'] ?? '') !== $today) {
			return ['day' => $today, 'count' => 0];
		}
		return ['day' => $today, 'count' => (int) ($d['count'] ?? 0)];
	}

	public static function daily_remaining(): int {
		return max(0, (int) self::settings()['daily_cap'] - self::daily()['count']);
	}

	public static function daily_bump(): void {
		$d = self::daily();
		$d['count']++;
		update_option(self::DAILY, $d, false);
	}

	/** Seconds until shortly after the next UTC midnight (when the cap resets). */
	public static function seconds_until_tomorrow(): int {
		return max(60, (int) strtotime('tomorrow 00:05 UTC') - time());
	}
```

- [ ] **Step 4: Run tests + lint**

Run: `php tests/alt-text-test.php` → Expected: `13 passed, 0 failed`.
Run: `npm run lint` → Expected: 0 new errors.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/includes/class-alt-text.php tests/alt-text-test.php
git commit -m "feat(alt-text): settings, option-backed queue with backoff, cron scheduling, daily cap"
```

---

### Task 6: `AQ_Alt_Text` — WordPress layer: context, generation, persistence, batch runners, hooks

**Files:**
- Modify: `plugin/aq-core/includes/class-alt-text.php` (append inside the class)

No unit test here (every method calls WordPress + the network); verified live in Task 10 Steps 5–9.

- [ ] **Step 1: Add the WordPress layer** (inside the class, after `seconds_until_tomorrow()`)

```php
	/* ============================================================
	 * WordPress layer — context, generation, persistence
	 * ============================================================ */

	/** Light, client-agnostic context for the prompt (all from site data). */
	public static function context_for(int $id): array {
		$file = (string) get_attached_file($id);
		$stem = preg_replace('/\.[a-z0-9]+$/i', '', basename($file));
		$stem = preg_replace('/-\d+x\d+$/', '', (string) $stem);              // WP size suffix
		$stem = preg_replace('/-(scaled|e\d{10,})$/i', '', (string) $stem);   // -scaled / edit hashes
		$site = function_exists('aq_site');
		$towns = [];
		foreach ((array) ($site ? aq_site('towns') : []) as $t) {
			if (!empty($t['name'])) {
				$towns[] = (string) $t['name'];
			}
			if (count($towns) >= 5) {
				break;
			}
		}
		$parent = (int) get_post_field('post_parent', $id);
		return [
			'business' => $site ? (string) aq_site('name') : '',
			'industry' => $site ? (string) aq_site('industry') : '',
			'location' => $site ? trim((string) aq_site('address.locality') . ', ' . (string) aq_site('address.region'), ', ') : '',
			'towns'    => $towns,
			'filename' => trim((string) preg_replace('/[-_]+/', ' ', (string) $stem)),
			'page'     => $parent ? (string) get_the_title($parent) : '',
		];
	}

	/**
	 * Describe one attachment and persist the alt. Never overwrites a non-empty alt.
	 *
	 * @return array{ok:bool,status:string,alt?:string,reason?:string}
	 *   status: written | decorative | skipped | failed | deferred
	 */
	public static function generate(int $id): array {
		$mime = (string) get_post_mime_type($id);
		if (!self::eligible_mime($mime)) {
			if ($id > 0 && get_post_type($id) === 'attachment') {
				update_post_meta($id, '_aq_alt_skip', 'unsupported type');
			}
			return ['ok' => false, 'status' => 'skipped', 'reason' => 'unsupported type'];
		}
		$alt  = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
		$deco = (bool) get_post_meta($id, '_aq_alt_decorative', true);
		if (!self::should_generate($mime, $alt, $deco, true)) {
			return ['ok' => false, 'status' => 'skipped', 'reason' => $deco ? 'marked decorative' : 'already has alt text'];
		}
		if (!self::claude_ready()) {
			return ['ok' => false, 'status' => 'failed', 'reason' => 'Claude not configured'];
		}
		if (self::daily_remaining() <= 0) {
			return ['ok' => false, 'status' => 'deferred', 'reason' => 'daily cap reached'];
		}

		$meta   = wp_get_attachment_metadata($id);
		$file   = (string) get_attached_file($id);
		$source = self::pick_source(is_array($meta) ? $meta : [], dirname($file), $file);
		if ($source === null) {
			update_post_meta($id, '_aq_alt_skip', 'no image file under 5 MB');
			return ['ok' => false, 'status' => 'skipped', 'reason' => 'no image file under 5 MB'];
		}
		$image = AQ_Claude::image_block($source['path'], $source['mime'] !== '' ? $source['mime'] : $mime);
		if ($image === null) {
			update_post_meta($id, '_aq_alt_skip', 'unreadable image file');
			return ['ok' => false, 'status' => 'skipped', 'reason' => 'unreadable image file'];
		}

		$model = self::model();
		$res   = AQ_Claude::message([
			'model'       => $model,
			'max_tokens'  => 300,
			'timeout'     => 45,
			'system'      => self::system_prompt(),
			'messages'    => [['role' => 'user', 'content' => [$image, ['type' => 'text', 'text' => self::user_text(self::context_for($id))]]]],
			'tools'       => [self::tool_def()],
			'tool_choice' => ['type' => 'tool', 'name' => 'set_alt_text'],
		]);
		if (is_wp_error($res)) {
			return ['ok' => false, 'status' => 'failed', 'reason' => $res->get_error_message()];
		}
		$parsed = self::parse_result($res['tool_input']);
		if ($parsed === null) {
			return ['ok' => false, 'status' => 'failed', 'reason' => 'no usable alt text returned'];
		}
		self::daily_bump();

		// A human may have typed an alt while this sat in the queue — re-check right before writing.
		if (trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)) !== '') {
			return ['ok' => false, 'status' => 'skipped', 'reason' => 'already has alt text'];
		}
		if ($parsed['decorative']) {
			update_post_meta($id, '_wp_attachment_image_alt', '');
			update_post_meta($id, '_aq_alt_decorative', 1);
		} else {
			update_post_meta($id, '_wp_attachment_image_alt', $parsed['alt']);
		}
		update_post_meta($id, '_aq_alt_source', 'ai');
		update_post_meta($id, '_aq_alt_at', time());
		update_post_meta($id, '_aq_alt_model', $model);
		update_post_meta($id, '_aq_alt_confidence', $parsed['confidence']);
		delete_post_meta($id, '_aq_alt_fail');
		delete_post_meta($id, '_aq_alt_skip');
		return ['ok' => true, 'status' => $parsed['decorative'] ? 'decorative' : 'written', 'alt' => $parsed['alt']];
	}

	/**
	 * Work the queue: up to $limit items inside $budget seconds. Failures back off
	 * (3 strikes → _aq_alt_fail + dropped); a daily-cap hit stops the pass.
	 *
	 * @return array{processed:int,remaining:int,deferred:bool,results:array}
	 */
	public static function process_queue(int $limit, float $budget): array {
		$start = microtime(true);
		$q     = self::queue();
		$out   = ['processed' => 0, 'remaining' => 0, 'deferred' => false, 'results' => []];
		foreach (self::due_ids($q) as $id) {
			if ($out['processed'] >= $limit || (microtime(true) - $start) > $budget) {
				break;
			}
			$r = self::generate($id);
			$out['results'][] = ['id' => $id] + $r;
			$out['processed']++;
			if ($r['status'] === 'deferred') {
				$out['deferred'] = true;
				break;
			}
			if ($r['status'] === 'failed') {
				$before = isset($q[$id]) ? (int) $q[$id]['attempts'] : 0;
				$q = self::mark_failure($q, $id);
				if (!isset($q[$id]) && $before + 1 >= self::MAX_ATTEMPTS) {
					update_post_meta($id, '_aq_alt_fail', (string) ($r['reason'] ?? 'failed'));
				}
			} else {
				unset($q[$id]);
			}
		}
		self::save_queue($q);
		$out['remaining'] = count($q);
		return $out;
	}

	/** Attachment ids with no alt that are still worth trying (not decorative/failed/skipped). */
	public static function missing_ids(int $limit): array {
		$q = new WP_Query([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::MIMES,
			'fields'         => 'ids',
			'posts_per_page' => max(1, $limit),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'relation' => 'OR',
					['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
					['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
				],
				['key' => '_aq_alt_decorative', 'compare' => 'NOT EXISTS'],
				['key' => '_aq_alt_fail', 'compare' => 'NOT EXISTS'],
				['key' => '_aq_alt_skip', 'compare' => 'NOT EXISTS'],
			],
		]);
		return array_map('intval', (array) $q->posts);
	}

	/**
	 * Backfill pass over images missing alt text. In this mode a failure marks the
	 * attachment (_aq_alt_fail) immediately so the loop can never spin on one bad
	 * image; "Retry failed" on the Media screen clears those markers.
	 */
	public static function process_missing(int $limit, float $budget): array {
		$start = microtime(true);
		$out   = ['processed' => 0, 'remaining' => 0, 'deferred' => false, 'results' => []];
		foreach (self::missing_ids($limit) as $id) {
			if ((microtime(true) - $start) > $budget) {
				break;
			}
			$r = self::generate($id);
			$out['results'][] = ['id' => $id] + $r;
			$out['processed']++;
			if ($r['status'] === 'deferred') {
				$out['deferred'] = true;
				break;
			}
			if ($r['status'] === 'failed') {
				update_post_meta($id, '_aq_alt_fail', (string) ($r['reason'] ?? 'failed'));
			}
		}
		$c = self::counts();
		$out['remaining'] = max(0, $c['missing'] - $c['failed'] - $c['skipped']);
		return $out;
	}

	/** Library counts for the Media screen. */
	public static function counts(): array {
		global $wpdb;
		$in = "'" . implode("','", array_map('esc_sql', self::MIMES)) . "'";
		$total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ($in)");
		$missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'
			 LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_aq_alt_decorative'
			 WHERE p.post_type='attachment' AND p.post_mime_type IN ($in) AND (a.meta_value IS NULL OR a.meta_value='') AND d.post_id IS NULL"
		);
		$by_meta = function (string $key) use ($wpdb, $in): int {
			return (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND p.post_type='attachment' AND p.post_mime_type IN ($in)", $key
			));
		};
		return [
			'total'      => $total,
			'missing'    => $missing,
			'ai'         => $by_meta('_aq_alt_source'),
			'decorative' => $by_meta('_aq_alt_decorative'),
			'failed'     => $by_meta('_aq_alt_fail'),
			'skipped'    => $by_meta('_aq_alt_skip'),
			'queued'     => count(self::queue()),
		];
	}

	/* ============================================================
	 * Hooks — arrival, cron runner, spawn fallback
	 * ============================================================ */

	/** wp_generate_attachment_metadata filter: enqueue eligible images; return $metadata untouched. */
	public static function on_metadata($metadata, $attachment_id, $context = 'create') {
		$id = (int) $attachment_id;
		if ($id > 0 && self::enabled()) {
			$mime = (string) get_post_mime_type($id);
			$alt  = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
			$deco = (bool) get_post_meta($id, '_aq_alt_decorative', true);
			if (self::should_generate($mime, $alt, $deco, true)) {
				self::enqueue($id);
			}
		}
		return $metadata;
	}

	/** Cron runner: one bounded pass; reschedules itself while work remains. */
	public static function run_cron(): void {
		update_option(self::LAST_RUN, time(), false);
		$r = self::process_queue(self::CRON_BATCH, self::CRON_BUDGET);
		if ($r['remaining'] > 0) {
			self::schedule($r['deferred'] ? self::seconds_until_tomorrow() : 60);
		}
	}

	/** admin_init: if the queue is stale (no pass in 90 s), nudge WP-Cron ourselves. */
	public static function maybe_spawn(): void {
		if (!self::queue()) {
			return;
		}
		if (time() - (int) get_option(self::LAST_RUN, 0) < 90) {
			return;
		}
		self::schedule(5);
		if (function_exists('spawn_cron')) {
			spawn_cron();
		}
	}
```

- [ ] **Step 2: Lint**

Run: `npm run lint` → Expected: 0 new errors.

- [ ] **Step 3: Commit**

```bash
git add plugin/aq-core/includes/class-alt-text.php
git commit -m "feat(alt-text): context, generation + persistence, queue/backfill runners, arrival hook, cron + spawn fallback"
```

---

### Task 7: `AQ_Alt_Text` — REST routes + WP-CLI command

**Files:**
- Modify: `plugin/aq-core/includes/class-alt-text.php` (append inside the class)

- [ ] **Step 1: Add REST + CLI** (inside the class, after `maybe_spawn()`)

```php
	/* ============================================================
	 * REST (aq/v1) — drives the Media screen button
	 * ============================================================ */

	public static function rest_routes(): void {
		$can = function () { return current_user_can(self::CAP); };
		register_rest_route('aq/v1', '/alt-text/run', [
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_run'],
		]);
		register_rest_route('aq/v1', '/alt-text/status', [
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_status'],
		]);
	}

	/**
	 * POST /alt-text/run  { mode: 'missing'|'queue'|'retry', limit?: 1..10 }
	 * One bounded batch; the screen's JS loops until remaining = 0 or deferred.
	 */
	public static function rest_run(WP_REST_Request $req) {
		if (!self::claude_ready()) {
			return new WP_Error('aq_no_key', 'Claude is not configured. Add a key under AutoForge → Integrations.', ['status' => 400]);
		}
		@set_time_limit(0);
		$body  = $req->get_json_params();
		$mode  = (string) ($body['mode'] ?? 'missing');
		$limit = min(10, max(1, (int) ($body['limit'] ?? self::REST_BATCH)));
		if ($mode === 'retry') {
			delete_metadata('post', 0, '_aq_alt_fail', '', true);
			$mode = 'missing';
		}
		$r = $mode === 'queue' ? self::process_queue($limit, self::REST_BUDGET) : self::process_missing($limit, self::REST_BUDGET);
		$r['ok']              = true;
		$r['daily_remaining'] = self::daily_remaining();
		$r['results']         = array_map([__CLASS__, 'result_row'], $r['results']);
		return rest_ensure_response($r);
	}

	public static function rest_status() {
		return rest_ensure_response([
			'ok'              => true,
			'counts'          => self::counts(),
			'settings'        => self::settings(),
			'claude_ready'    => self::claude_ready(),
			'daily_remaining' => self::daily_remaining(),
		]);
	}

	/** Enrich a generate() result for display. */
	public static function result_row(array $row): array {
		$id = (int) ($row['id'] ?? 0);
		$row['filename'] = $id ? basename((string) get_attached_file($id)) : '';
		$row['thumb']    = $id ? (string) wp_get_attachment_image_url($id, 'thumbnail') : '';
		$row['edit_url'] = $id ? (string) get_edit_post_link($id, 'raw') : '';
		return $row;
	}

	/* ============================================================
	 * WP-CLI:  wp aq alt-text [--missing] [--limit=<n>] [--dry-run]
	 * ============================================================ */

	public static function cli(array $args, array $assoc): void {
		$limit   = max(1, (int) ($assoc['limit'] ?? 50));
		$dry     = !empty($assoc['dry-run']);
		$missing = !empty($assoc['missing']);
		if (!self::claude_ready()) {
			\WP_CLI::error('Claude is not configured (AutoForge → Integrations).');
		}
		if ($dry) {
			$ids = $missing ? self::missing_ids($limit) : self::due_ids(self::queue());
			foreach ($ids as $id) {
				\WP_CLI::log($id . "\t" . basename((string) get_attached_file((int) $id)));
			}
			\WP_CLI::success(count($ids) . ' candidate(s), nothing written (dry run).');
			return;
		}
		$r = $missing ? self::process_missing($limit, 600) : self::process_queue($limit, 600);
		foreach ($r['results'] as $row) {
			\WP_CLI::log(sprintf('%-6d %-10s %s', $row['id'], $row['status'], (string) ($row['alt'] ?? ($row['reason'] ?? ''))));
		}
		\WP_CLI::success(sprintf('%d processed, %d remaining%s.', $r['processed'], $r['remaining'], $r['deferred'] ? ' (daily cap reached)' : ''));
	}
```

- [ ] **Step 2: Lint**

Run: `npm run lint` → Expected: 0 new errors.

- [ ] **Step 3: Commit**

```bash
git add plugin/aq-core/includes/class-alt-text.php
git commit -m "feat(alt-text): REST run/status routes + wp aq alt-text command"
```

---

### Task 8: Admin screen (AutoForge → Media), hub nav entry, Help topic

**Files:**
- Modify: `plugin/aq-core/includes/class-alt-text.php` (append `register()`, `menu()`, `render()`, `save()`)
- Modify: `plugin/aq-core/includes/class-admin-hub.php:102-105` (`nav()` Content group)
- Modify: `plugin/aq-core/includes/class-help.php:92-96` (after the Styles topic)

- [ ] **Step 1: Add `register()`, `menu()`, `render()`, `save()`** (inside the class — put `register()` right after the constants so it is the first method; `menu/render/save` at the end)

`register()` (after the `const ALT_MAX_LEN` line):
```php
	public static function register(): void {
		add_filter('wp_generate_attachment_metadata', [__CLASS__, 'on_metadata'], 20, 3);
		add_action(self::HOOK, [__CLASS__, 'run_cron']);
		add_action('admin_init', [__CLASS__, 'maybe_spawn']);
		add_action('admin_menu', [__CLASS__, 'menu'], 25);
		add_action('admin_post_aq_alt_text_save', [__CLASS__, 'save']);
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
			\WP_CLI::add_command('aq alt-text', [__CLASS__, 'cli']);
		}
	}
```

`menu()`, `render()`, `save()` (end of class):
```php
	/* ============================================================
	 * Admin screen — AutoForge → Media
	 * ============================================================ */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Media', 'Media', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$s      = self::settings();
		$c      = self::counts();
		$ready  = self::claude_ready();
		$models = AQ_Claude::models();
		$int    = admin_url('admin.php?page=aq-integrations');
		$eligible_remaining = max(0, $c['missing'] - $c['failed'] - $c['skipped']);

		// Last 25 AI-written alts, newest first.
		$recent = get_posts([
			'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 25,
			'meta_key' => '_aq_alt_at', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true,
		]);

		AQ_Admin_Hub::open('Media', 'Alt text for your images, written automatically and never over a description a person typed.', self::SLUG);
		?>
		<style>
			.aq-alt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
			.aq-alt-stat{background:#fff;border:1px solid #e6e8eb;border-radius:12px;padding:14px 16px}
			.aq-alt-stat b{display:block;font-size:24px;line-height:1.1;color:#0d1014}
			.aq-alt-stat span{font-size:12px;color:#5b6471}
			.aq-alt-field{margin-bottom:16px;max-width:520px}
			.aq-alt-field label{display:block;font-weight:600;color:#0d1014;margin-bottom:6px}
			.aq-alt-field select,.aq-alt-field input[type=number]{width:100%;padding:9px 12px;border:1px solid #c9cfd6;border-radius:8px;font-size:14px;color:#0d1014}
			.aq-alt-toggle{display:flex;align-items:center;gap:.5em;font-weight:600;color:#0d1014}
			.aq-alt-hint{font-size:12px;color:#5b6471;margin:6px 0 0}
			.aq-alt-banner{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px}
			.aq-alt-banner--ok{background:#eaf0ea;color:#1a6f3f;border:1px solid #b9dcc4}
			.aq-alt-banner--warn{background:#fdf1dd;color:#7a4e0a;border:1px solid #f4d088}
			.aq-alt-progress{height:8px;background:#e6e8eb;border-radius:4px;overflow:hidden;margin:10px 0;max-width:520px}
			.aq-alt-progress i{display:block;height:100%;width:0;background:#1a6f3f;transition:width .3s}
			.aq-alt-results{list-style:none;margin:8px 0 0;padding:0;max-width:720px;font-size:13px}
			.aq-alt-results li{display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid #eef1f5}
			.aq-alt-results img{width:40px;height:40px;object-fit:cover;border-radius:6px;background:#eef1f5}
			.aq-alt-results .st{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5b6471;min-width:72px}
			table.aq-alt-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e6e8eb;border-radius:12px;overflow:hidden}
			table.aq-alt-table th,table.aq-alt-table td{text-align:left;padding:8px 12px;border-bottom:1px solid #eef1f5;vertical-align:middle}
			table.aq-alt-table img{width:44px;height:44px;object-fit:cover;border-radius:6px}
		</style>

		<?php if (isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>

		<?php if (!$ready) : ?>
			<div class="aq-alt-banner aq-alt-banner--warn">Claude isn't connected, so alt text can't be written yet. Add a Claude key under <a href="<?php echo esc_url($int); ?>">Integrations</a>.</div>
		<?php elseif (!empty($s['enabled'])) : ?>
			<div class="aq-alt-banner aq-alt-banner--ok"><strong>On.</strong> New images get alt text automatically within about a minute of upload.</div>
		<?php endif; ?>

		<div class="aq-alt-grid">
			<div class="aq-alt-stat"><b><?php echo (int) $c['total']; ?></b><span>images in library</span></div>
			<div class="aq-alt-stat"><b id="aq-alt-missing"><?php echo (int) $c['missing']; ?></b><span>missing alt text</span></div>
			<div class="aq-alt-stat"><b id="aq-alt-ai"><?php echo (int) $c['ai']; ?></b><span>written by AutoForge</span></div>
			<div class="aq-alt-stat"><b><?php echo (int) $c['decorative']; ?></b><span>marked decorative</span></div>
			<?php if ($c['failed'] || $c['queued']) : ?>
				<div class="aq-alt-stat"><b><?php echo (int) $c['failed']; ?></b><span>failed · <?php echo (int) $c['queued']; ?> queued</span></div>
			<?php endif; ?>
		</div>

		<div class="aq-panel">
			<h2 style="margin-top:0">Fill in missing alt text <?php echo AQ_Admin_Hub::tip('Looks at each image that has no alt text and writes a short, plain description of what is visible. Images that already have alt text are left alone.'); ?></h2>
			<p class="aq-alt-hint" style="margin:0 0 10px"><?php echo (int) $eligible_remaining; ?> image(s) can be filled in now · <?php echo (int) self::daily_remaining(); ?> left in today's allowance.</p>
			<p>
				<button type="button" class="aq-btn" id="aq-alt-run" <?php disabled(!$ready || $eligible_remaining === 0); ?>>Generate missing alt text</button>
				<?php if ($c['failed']) : ?>
					<button type="button" class="aq-btn aq-btn--ghost" id="aq-alt-retry" <?php disabled(!$ready); ?>>Retry <?php echo (int) $c['failed']; ?> failed</button>
				<?php endif; ?>
				<span id="aq-alt-status" style="margin-left:10px;font-size:13px;color:#5b6471"></span>
			</p>
			<div class="aq-alt-progress" id="aq-alt-bar" style="display:none"><i></i></div>
			<ul class="aq-alt-results" id="aq-alt-results"></ul>
		</div>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_alt_text_save">
			<?php wp_nonce_field('aq_alt_text_save'); ?>
			<div class="aq-panel">
				<div class="aq-alt-field">
					<label class="aq-alt-toggle"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Write alt text for new uploads automatically <?php echo AQ_Admin_Hub::tip('When on, every new image with no alt text is described in the background shortly after it is uploaded or imported.'); ?></label>
				</div>
				<div class="aq-alt-field">
					<label for="aq-alt-model">Model <?php echo AQ_Admin_Hub::tip('Which Claude model writes the descriptions. Opus gives the best descriptions; Haiku is much cheaper and usually fine for alt text.'); ?></label>
					<select id="aq-alt-model" name="model">
						<?php foreach ($models as $id => $label) : ?>
							<option value="<?php echo esc_attr($id); ?>" <?php selected($s['model'], $id); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="aq-alt-field">
					<label for="aq-alt-cap">Daily allowance <?php echo AQ_Admin_Hub::tip('Maximum number of images described per day. Anything beyond it simply waits for tomorrow — a safety cap on cost.'); ?></label>
					<input type="number" id="aq-alt-cap" name="daily_cap" min="1" max="5000" value="<?php echo (int) $s['daily_cap']; ?>">
				</div>
			</div>
			<?php submit_button('Save'); ?>
		</form>

		<?php if ($recent) : ?>
			<h2>Recently written</h2>
			<table class="aq-alt-table">
				<thead><tr><th></th><th>Alt text</th><th>Confidence</th><th>When</th><th></th></tr></thead>
				<tbody>
				<?php foreach ($recent as $p) :
					$alt  = (string) get_post_meta($p->ID, '_wp_attachment_image_alt', true);
					$deco = (bool) get_post_meta($p->ID, '_aq_alt_decorative', true);
					$at   = (int) get_post_meta($p->ID, '_aq_alt_at', true); ?>
					<tr>
						<td><?php echo wp_get_attachment_image($p->ID, 'thumbnail'); ?></td>
						<td><?php echo $deco ? '<em>Decorative (empty alt)</em>' : esc_html($alt); ?></td>
						<td><?php echo esc_html((string) get_post_meta($p->ID, '_aq_alt_confidence', true)); ?></td>
						<td><?php echo $at ? esc_html(human_time_diff($at) . ' ago') : ''; ?></td>
						<td><a href="<?php echo esc_url((string) get_edit_post_link($p->ID, 'raw')); ?>">Edit</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<script>
		(function () {
			var url = '<?php echo esc_url_raw(rest_url('aq/v1/alt-text/run')); ?>';
			var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
			var run = document.getElementById('aq-alt-run'), retry = document.getElementById('aq-alt-retry');
			var st = document.getElementById('aq-alt-status'), bar = document.getElementById('aq-alt-bar'), list = document.getElementById('aq-alt-results');
			var missingEl = document.getElementById('aq-alt-missing'), aiEl = document.getElementById('aq-alt-ai');
			if (!run) { return; }
			function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
			function start(mode) {
				var total = -1, done = 0, passes = 0;
				run.disabled = true; if (retry) { retry.disabled = true; }
				list.innerHTML = ''; bar.style.display = 'block'; bar.firstChild.style.width = '0%';
				st.style.color = '#5b6471'; st.textContent = 'Writing alt text…';
				function fail(msg) { run.disabled = false; if (retry) { retry.disabled = false; } st.textContent = '✕ ' + msg; st.style.color = '#d63638'; }
				function step() {
					passes++;
					if (passes > 500) { fail('Stopped after 500 batches — click again to continue.'); return; }
					fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ mode: mode, limit: 5 }) })
						.then(function (r) { return r.json().then(function (d) { return { httpOk: r.ok, d: d || {} }; }); })
						.then(function (res) {
							var d = res.d;
							if (!res.httpOk || d.ok !== true) { fail(d.message || d.code || 'Request failed.'); return; }
							mode = 'missing'; // a retry pass clears markers once, then continues as a normal backfill
							(d.results || []).forEach(function (r) {
								var li = document.createElement('li');
								li.innerHTML = (r.thumb ? '<img src="' + esc(r.thumb) + '" alt="">' : '<span style="width:40px"></span>')
									+ '<span class="st">' + esc(r.status) + '</span>'
									+ '<span>' + esc(r.status === 'written' ? r.alt : (r.status === 'decorative' ? 'Decorative, empty alt' : (r.reason || ''))) + (r.filename ? ' <small style="color:#5b6471">(' + esc(r.filename) + ')</small>' : '') + '</span>';
								list.insertBefore(li, list.firstChild);
								if (r.status === 'written' || r.status === 'decorative') { done++; }
							});
							if (total < 0) { total = done + (d.remaining || 0); }
							var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
							bar.firstChild.style.width = pct + '%';
							if (missingEl) { missingEl.textContent = Math.max(0, parseInt(missingEl.textContent, 10) - (d.results || []).filter(function (r) { return r.status === 'written' || r.status === 'decorative'; }).length); }
							if (aiEl) { aiEl.textContent = parseInt(aiEl.textContent, 10) + (d.results || []).filter(function (r) { return r.status === 'written' || r.status === 'decorative'; }).length; }
							if (d.deferred) { run.disabled = false; st.textContent = '⏸ Daily allowance reached — ' + done + ' done today; the rest continue tomorrow.'; st.style.color = '#b26a00'; return; }
							if ((d.remaining || 0) > 0 && (d.processed || 0) > 0) { st.textContent = 'Writing alt text… ' + done + ' done, ' + d.remaining + ' to go'; step(); return; }
							run.disabled = (d.remaining || 0) === 0; if (retry) { retry.disabled = false; }
							st.textContent = '✓ Done — ' + done + ' image(s) described.'; st.style.color = '#1a8f4f';
						})
						.catch(function (e) { fail('Interrupted (' + e.message + '). Click again to continue — finished work is saved.'); });
				}
				step();
			}
			run.addEventListener('click', function () { start('missing'); });
			if (retry) { retry.addEventListener('click', function () { start('retry'); }); }
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_alt_text_save')) {
			wp_die('Not allowed.');
		}
		$in    = wp_unslash($_POST);
		$model = (string) ($in['model'] ?? '');
		update_option(self::OPTION, [
			'enabled'   => !empty($in['enabled']),
			'model'     => array_key_exists($model, AQ_Claude::models()) ? $model : 'claude-opus-5',
			'daily_cap' => min(5000, max(1, (int) ($in['daily_cap'] ?? 300))),
		], false);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}
```

- [ ] **Step 2: Add the hub nav entry**

In `plugin/aq-core/includes/class-admin-hub.php`, the Content group of `nav()` currently reads:
```php
			['type' => 'group', 'label' => 'Content', 'icon' => 'edit', 'items' => [
				'aq-pages' => 'Pages', 'aq-styles' => 'Styles', 'aq-navigation' => 'Navigation',
				'aq-footer' => 'Footer', 'aq-logo' => 'Logo', 'aq-legal' => 'Legal Pages',
			]],
```
Change it to:
```php
			['type' => 'group', 'label' => 'Content', 'icon' => 'edit', 'items' => [
				'aq-pages' => 'Pages', 'aq-media' => 'Media', 'aq-styles' => 'Styles', 'aq-navigation' => 'Navigation',
				'aq-footer' => 'Footer', 'aq-logo' => 'Logo', 'aq-legal' => 'Legal Pages',
			]],
```

- [ ] **Step 3: Add the Help topic**

In `plugin/aq-core/includes/class-help.php`, directly after the `Styles` topic block (the `self::topic('Styles', …); ?>` call, ~line 96) and before `<div class="aq-help__group">Getting found on Google</div>`, insert:
```php
			<?php self::topic('Media', 'Alt text for your images — written automatically, never over what a person typed.', '
				<p><strong>Alt text</strong> is the short description attached to an image that screen readers read aloud and Google uses to
				understand the picture. Every image should have one; most uploads don\'t.</p>
				<ul>
					<li>When <strong>Write alt text automatically</strong> is on, any new image that arrives without alt text is described in the
					background within about a minute — whether it was uploaded here, picked in the page editor, or imported with the site.</li>
					<li><strong>Generate missing alt text</strong> works through images already in your library that have none. It runs in small
					batches; you can leave the page and come back.</li>
					<li>AutoForge only ever fills an <em>empty</em> alt. If you (or anyone) typed a description, it is never changed.</li>
					<li>Purely decorative images (textures, dividers) are marked decorative and deliberately left with an empty alt — that is
					the correct, accessible choice.</li>
					<li>To fix a description, open the image (the <strong>Edit</strong> link in "Recently written") and change its alt text there.</li>
				</ul>
				<div class="aq-help__tip"><strong>Needs:</strong> a Claude key under Integrations. The daily allowance caps how many images are described per day so cost can never run away.</div>
			'); ?>
```

- [ ] **Step 4: Lint**

Run: `npm run lint` → Expected: 0 new errors.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/includes/class-alt-text.php plugin/aq-core/includes/class-admin-hub.php plugin/aq-core/includes/class-help.php
git commit -m "feat(alt-text): AutoForge → Media screen with backfill, settings, recent list; hub nav + Help topic"
```

---

### Task 9: Wire into the engine, bump to 0.3.49, build

**Files:**
- Modify: `plugin/aq-core/aq-core.php:6`, `:18`, the `require_once` block (~line 100), the `::register()` block (~line 156)
- Modify: `package.json:3`

- [ ] **Step 1: Require + register**

In `plugin/aq-core/aq-core.php`, after the line
`require_once AQ_CORE_DIR . 'includes/class-claude.php';`
add:
```php
require_once AQ_CORE_DIR . 'includes/class-alt-text.php';
```
and after the line `AQ_Integrations::register();` add:
```php
AQ_Alt_Text::register();
```

- [ ] **Step 2: Version bump (three places)**

`plugin/aq-core/aq-core.php` line 6: ` * Version: 0.3.49`
`plugin/aq-core/aq-core.php` line 18: `define('AQ_CORE_VERSION', '0.3.49');`
`package.json` line 3: `"version": "0.3.49",`

Verify:
```bash
grep -n "0.3.49" plugin/aq-core/aq-core.php package.json
```
Expected: exactly three matching lines.

- [ ] **Step 3: Lint + build**

```bash
npm run lint
npm run build:release
```
Expected: lint 0 new errors; build prints the two zips, including `dist/aq-core-0.3.49.zip`. Confirm the zip contains the new class and no tests:
```bash
unzip -l dist/aq-core-0.3.49.zip | grep -E "class-alt-text|tests/" 
```
Expected: one line for `aq-core/includes/class-alt-text.php`, nothing for `tests/`.

- [ ] **Step 4: Commit**

```bash
git add plugin/aq-core/aq-core.php package.json
git commit -m "Release prep v0.3.49 — automatic alt text + AQ_Claude upgrades"
```

---

### Task 10: Deploy to ACME staging, run the test suite there, verify live

**Context:** ACME staging is Pressable site **1761035** (host, SFTP creds and the WP-CLI MCP channel are in the project memory `acme-live-deploy-workflow` / `project-acme-pressure-washing` — never copy them here). WP-CLI runs through the Pressable MCP (`execute_tool` → `run_site_wpcli_commands`, `site_id` 1761035, commands are async; write output to `wp-content/uploads/_x.txt` and `curl` it). WP root on the server is `/srv/htdocs`. Hand-deployed files at **0.3.49 are stable**: the updater only offers GitHub's latest release (0.3.48), so it will not revert them.

- [ ] **Step 1: Push the branch (needs Justin's OK if not already given — the repo is public)**

```bash
git push -u origin feat/alt-text
git rev-parse HEAD
```
Note the commit hash `<COMMIT>`.

- [ ] **Step 2: Deploy the five changed plugin files from the pushed commit**

Run via WP-CLI on the site (one `wp eval`; replace `<COMMIT>`):
```bash
wp eval 'foreach (["includes/class-alt-text.php","includes/class-claude.php","includes/class-admin-hub.php","includes/class-help.php","aq-core.php"] as $f) { $r = wp_remote_get("https://raw.githubusercontent.com/AQ-Marketing/autoforge-wp/<COMMIT>/plugin/aq-core/$f", ["timeout"=>30]); $b = wp_remote_retrieve_body($r); if (wp_remote_retrieve_response_code($r) === 200 && strlen($b) > 100) { file_put_contents(WP_PLUGIN_DIR . "/aq-core/$f", $b); echo "$f ok\n"; } else { echo "$f FAILED\n"; } }'
wp plugin list --name=aq-core --fields=name,version,status
wp eval 'echo class_exists("AQ_Alt_Text") ? "AQ_Alt_Text loaded\n" : "MISSING\n";'
wp cache flush
```
Expected: five `ok` lines; version `0.3.49`, status `active`; `AQ_Alt_Text loaded`.

- [ ] **Step 3: Confirm the WordPress-side syntax is sound**

```bash
wp eval 'echo AQ_CORE_VERSION, " ", AQ_Claude::MODEL, "\n";'
```
Expected: `0.3.49 claude-opus-5`. (A parse error in any deployed file would have fataled `wp` itself — that is the signal to fix and redeploy.)

- [ ] **Step 4: Run the unit tests on the server**

```bash
wp eval 'mkdir(WP_CONTENT_DIR . "/aq-tests/lib", 0755, true); foreach (["lib/mini-test.php","lib/wp-shims.php","harness-selftest.php","claude-test.php","alt-text-test.php"] as $f) { $r = wp_remote_get("https://raw.githubusercontent.com/AQ-Marketing/autoforge-wp/<COMMIT>/tests/$f", ["timeout"=>30]); file_put_contents(WP_CONTENT_DIR . "/aq-tests/$f", wp_remote_retrieve_body($r)); }'
wp eval-file wp-content/aq-tests/harness-selftest.php
wp eval-file wp-content/aq-tests/claude-test.php
wp eval-file wp-content/aq-tests/alt-text-test.php
```
Expected: `5 passed, 0 failed`, `10 passed, 0 failed`, `13 passed, 0 failed`. Then remove the test files:
```bash
wp eval 'array_map("unlink", glob(WP_CONTENT_DIR . "/aq-tests/lib/*")); array_map("unlink", glob(WP_CONTENT_DIR . "/aq-tests/*.php")); rmdir(WP_CONTENT_DIR . "/aq-tests/lib"); rmdir(WP_CONTENT_DIR . "/aq-tests");'
```

- [ ] **Step 5: Front-end parity (logged-out HTML unchanged)**

Before Step 2 you should have captured baselines; if not, compare against production-equivalent expectations: the feature adds no front-end hooks, so:
```bash
curl -s https://<staging-host>/ -o /tmp/home-after.html
curl -s https://<staging-host>/house-pressure-washing/ -o /tmp/svc-after.html
grep -c "aq-alt\|alt-text" /tmp/home-after.html /tmp/svc-after.html
```
Expected: `0` matches in both (no alt-text markup on the front end).

- [ ] **Step 6: Media screen renders**

In the browser (logged in as admin): AutoForge → Content → **Media**. Expected: banner "On." (Claude key is set on ACME), counts populated (ACME has ~75 images; most "missing alt text"), the **Generate missing alt text** button enabled, model = Opus 5, allowance 300.

- [ ] **Step 7: Upload path → automatic alt**

```bash
wp media import https://<staging-host>/wp-content/uploads/<an-existing-real-photo>.webp --title="alt-test-upload" --porcelain
```
(returns the new attachment ID `<ID>`). Then:
```bash
wp option get aq_alt_queue --format=json
wp cron event run aq_alt_text_run
wp post meta get <ID> _wp_attachment_image_alt
wp post meta get <ID> _aq_alt_source
```
Expected: the queue lists `<ID>` right after import; after the cron run the alt is a sensible ≤125-char description and `_aq_alt_source` = `ai`. Delete the test attachment afterwards: `wp post delete <ID> --force`.

- [ ] **Step 8: Backfill via the button**

Click **Generate missing alt text**. Expected: progress bar advances in batches of 5, each row shows thumbnail + status + alt; finishes with "✓ Done — N image(s) described"; the "missing alt text" stat drops to 0 (or to the count of skipped/failed). Reload → "Recently written" lists them with Edit links. Spot-check 5 alts against the actual photos: honest, no "Image of", no keyword stuffing, no dashes.

- [ ] **Step 9: Human alt is preserved; decorative handling**

```bash
wp post meta update <SOME_ID> _wp_attachment_image_alt "Custom human alt"
wp aq alt-text --missing --limit=100
wp post meta get <SOME_ID> _wp_attachment_image_alt
```
Expected: still `Custom human alt`. Upload a plain gradient/texture PNG via wp-admin → after a minute `_aq_alt_decorative` = 1 and alt is empty.

- [ ] **Step 10: Negative path**

Temporarily clear the Claude key (Integrations → clear) → the Media screen shows the "Claude isn't connected" banner, the button is disabled, and `wp media import …` of a new image adds nothing to `aq_alt_queue`. Restore the key.

- [ ] **Step 11: Rendered alt on the site**

```bash
curl -s https://<staging-host>/house-pressure-washing/ | grep -o '<img[^>]*alt="[^"]*"' | head -5
```
Expected: section images now carry the generated alt text (previously `alt=""`).

- [ ] **Step 12: Record results**

Append a "Staging verification 2026-09-xx" note (what was tested, counts, any surprises — e.g. observed cron latency) to the spec's §10 Open items or a short `docs/superpowers/plans/2026-09-01-alt-text-generator.md` footnote, and commit:
```bash
git add docs/superpowers/plans/2026-09-01-alt-text-generator.md
git commit -m "docs: alt-text staging verification notes"
```

---

### Task 11: Release v0.3.49

- [ ] **Step 1: Rebase onto the latest main and rebuild**

```bash
git fetch origin
git rebase origin/main
npm run lint
npm run build:release
```
Expected: clean rebase (or resolve trivially), lint 0 new errors, `dist/aq-core-0.3.49.zip` rebuilt. If `origin/main` moved past 0.3.48 to a higher number, bump to the next free version in the three spots and rebuild before continuing.

- [ ] **Step 2: Merge fast-forward to main and push** (`main` is not checked out in any worktree, so it can be switched here)

```bash
git push -f origin feat/alt-text
git switch main
git merge --ff-only feat/alt-text
git push origin main
```

- [ ] **Step 3: Cut the GitHub release with the plugin zip**

```bash
gh release create v0.3.49 dist/aq-core-0.3.49.zip --title "v0.3.49 — Automatic alt text" --notes "Automatic alt text for library images (fill-empty-only, background queue, one-click backfill on AutoForge → Media, wp aq alt-text). Shared AQ_Claude client: Opus 5 default (SEO Agent narrative now uses Opus 5), image input, prompt caching, effort, refusal handling, Opus 5 server-side fallbacks."
gh release view v0.3.49 --json tagName,assets --jq '.tagName, .assets[].name'
```
Expected: `v0.3.49` and `aq-core-0.3.49.zip`. Every site now sees the update in its dashboard.

- [ ] **Step 4: Switch back to the feature branch and record the release in the project memory**

```bash
git switch feat/alt-text
```
Update the memory file `autoforge-assistant-alt-text-build.md` (in the Claude project memory directory): "v0.3.49 released <date>; ACME staging backfilled; next = rebase feat/site-assistant onto main".

---

### Task 12: `/wordpress` skill — pre-launch checklist rule (outside the repo)

**Files:**
- Modify: `C:\Users\justi\.claude\skills\wordpress\reference\pre-launch-checklist.md` — §3 Content integrity + the Rule changelog

- [ ] **Step 1: Add the rule to §3** (after the "All images via the media library" bullet)

```markdown
- [ ] **Every image has alt text** — after the media import, open AutoForge → Content → Media and click **Generate missing alt text** until "missing alt text" reads 0 (excluding images deliberately marked decorative). Spot-check five: honest description of what is visible, ≤125 chars, no "Image of…", no keyword stuffing. Alt a human wrote is never overwritten. (aq-core ≥ 0.3.49; needs the site's Claude key under Integrations.)
```

- [ ] **Step 2: Add the changelog entry** (top of the "Rule changelog" list, newest first)

```markdown
- **v6 (2026-09-xx)** — **§3** adds **"Every image has alt text"**: run AutoForge → Media → *Generate missing alt text* after the media import and confirm 0 missing (engine ≥ 0.3.49 writes alt automatically for new uploads; the button backfills the imported library).
```
Replace `xx` with the day the rule lands.

- [ ] **Step 3: Verify the file still parses as the skill expects**

Run:
```bash
grep -n "v6 (2026-09" "C:/Users/justi/.claude/skills/wordpress/reference/pre-launch-checklist.md"
grep -n "Every image has alt text" "C:/Users/justi/.claude/skills/wordpress/reference/pre-launch-checklist.md"
```
Expected: one line each.

---

## Self-review against the spec

- §4.1 trigger + eligibility → Task 6 `on_metadata()`, Task 4 `eligible_mime()/should_generate()` ✔
- §4.2 queue, cron, spawn fallback, REST batch, WP-CLI, missing-mode enumeration → Tasks 5, 6, 7 ✔
- §4.3 image payload (large → medium_large → original ≤5 MB, base64), context, rules, forced tool, model/max_tokens/timeout → Tasks 4, 6 (`pick_source`, `system_prompt`, `user_text`, `tool_def`, `generate`) ✔
- §4.4 storage + markers, write-only-when-empty re-check → Task 6 `generate()` ✔
- §4.5 settings + daily counter → Task 5 ✔
- §4.6 `AQ_Claude` upgrades 1–7 → Tasks 2–3 (models/MODEL, `image_block`, `cache_system`, `effort`, `content`+`usage`, refusal WP_Error, Opus 5 fallbacks header + `fallbacks:'default'`, proxy header note) ✔
- §5 Media screen (status, counts, toggle, model, cap, button with progress + results, recent table, Edit links), nav entry, Help topic → Task 8 ✔ (Retry failed added; supports §7's failure table)
- §6 security (permission callbacks, no front-end output, key server-side) → Tasks 7, 8; parity check Task 10 Step 5 ✔
- §7 failure handling (no key, API error/refusal backoff ×3, cap deferral, unsupported skip, human alt race) → Tasks 5, 6 ✔
- §8 verification 1–9 → Task 10 Steps 3–11 ✔
- §9 rollout → Tasks 9, 11, 12 ✔
- Placeholder scan: no TBD/TODO; every code step has full code. Type consistency: `generate()` statuses `written|decorative|skipped|failed|deferred` used identically in `process_queue`, `process_missing`, `cli`, and the screen JS; `counts()` keys `total|missing|ai|decorative|failed|skipped|queued` match `render()`; `settings()` keys `enabled|model|daily_cap` match `save()`.

---

## Staging verification note (2026-09-01, ACME 1761035, commit 3c5c7d1)

Deployed the 5 changed files from `feat/alt-text` @ `3c5c7d1` to ACME staging via the Pressable WP-CLI channel and verified everything that does not require a Claude key:

- Plugin loads clean: `VERSION=0.3.49 MODEL=claude-opus-5 ALT=loaded HOOK=wired` (no fatal — all 5 files parse under real WordPress/PHP).
- **Unit suite on the real host** (`wp eval-file`, plugin classes already loaded, real WP functions, real mbstring): harness **5/5**, claude **10/10**, alt-text **13/13** — all `complete` (the harness exits nonzero on any failure, which would flip status to `failed`).
- `counts()` SQL correct: **total=277, missing=223**, ai=0, decorative=0, queued=0.
- `settings()` defaults: model `claude-opus-5`, cap 300.
- **Front-end parity:** `/house-pressure-washing/` returns 200 with **0** alt-text feature markup; its 15 existing alts untouched (feature adds no front-end hooks).
- **No-key negative path (Step 10) confirmed:** ACME staging has **no Anthropic key** → `claude_ready=no`, `enabled=no`; `wp aq alt-text` errors cleanly ("Claude is not configured"); nothing enqueued. The feature degrades exactly as designed.
- Test files removed from the server afterward (`cleaned`).

**Still to verify (blocked on a credential):** the real image→alt generation (Step 7 upload→alt, Step 8 backfill of the 223, Step 9 decorative). These need an Anthropic key set on ACME staging (AutoForge → Integrations, or the `AQ_ANTHROPIC_KEY` wp-config constant). An API key is a credential Claude must not enter — Justin sets it, then the backfill + a test upload confirm end-to-end generation before the fleet release.

---

# Addendum: OpenAI (ChatGPT) as an alternative alt-text provider (2026-09-01)

Justin asked for a **ChatGPT model option** for alt text. Fold into the same v0.3.49
branch/release (not yet cut). The alt-text prompt (`system_prompt`, `user_text`) and result
shape (`parse_result` takes `{alt,decorative,confidence}`) are already provider-neutral, so
this is a contained addition: a second client (`AQ_OpenAI`), an OpenAI key in Integrations,
and provider routing in `AQ_Alt_Text`. Claude stays the default; the SEO Agent / editor gate
stay Claude-only. Default OpenAI model **`gpt-4o-mini`**, with `gpt-4o` also offered; the list
is filterable (`aq_openai_models`). OpenAI call is hand-written `wp_remote_post` to Chat
Completions with JSON-schema structured output (the claude-api skill is Anthropic-only).

## Task A: `AQ_OpenAI` client + Integrations field + wiring
New `plugin/aq-core/includes/class-openai.php` (models() default gpt-4o-mini + gpt-4o,
filterable; api_key() via AQ_Integrations::get('openai_key') or AQ_OPENAI_KEY; is_ready();
resolve_model(); build_payload() pure — model + vision image_url data-URI + json_schema
strict + max_completion_tokens:300; parse_response() pure → {alt,decorative,confidence} |
WP_Error, handles refusal + non-JSON; describe_image() reads file → data URI → POST → parse;
test()). Integrations: add the `openai` service (label "OpenAI (ChatGPT)", field openai_key,
constant AQ_OPENAI_KEY), openai_key() accessor, and an `openai` case in rest_test() calling
AQ_OpenAI::test(). aq-core.php: require class-openai.php after class-claude.php (no register).
Tests tests/openai-test.php (build_payload, parse_response, models, resolve_model) → 6 passed.
**Full verbatim code for this task is in the committed spec discussion / build subagent prompt.**

## Task B: route AQ_Alt_Text by provider
Add all_models() (Claude + OpenAI), provider($model) ("claude" prefix → claude else openai),
model() (validate against all_models, unknown → claude-opus-5), provider_ready($model),
ready(). enabled() gates on ready(). generate() computes $model early, checks
provider_ready($model), and dispatches: openai → AQ_OpenAI::describe_image(...), claude →
existing AQ_Claude flow; both normalize to $desc (assoc|WP_Error) → parse_result. Tests add
provider()/all_models()/model() cases → 16 passed.

## Task C: Media screen + settings provider-aware
render(): $model/$prov/$ready/$models from the provider helpers; not-ready banner names the
provider and says "or pick a different model below"; model <select> lists all_models(); a
hint line under it. save() validates against all_models(). rest_run()/cli() gate on ready()
with a provider-named message. Help topic mentions both keys.

## Task D: staging re-verify + docs
Redeploy (add class-openai.php to the deploy list), confirm class_exists("AQ_OpenAI"),
all_models() lists both, no-key path clean; run all four suites on host (5/10/16/6). Full
generation still needs a provider key on staging. Append a verification note; commit; push.
