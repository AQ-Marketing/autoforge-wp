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
}
