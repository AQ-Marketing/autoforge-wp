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
			$alt = mb_substr($alt, 0, self::ALT_MAX_LEN);
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
}
