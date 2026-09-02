<?php
/**
 * AQ Ranking Audit — an OPTIONAL, SUPPLEMENTARY search-ranking signal for the
 * on-site SEO guardian (AQ_Assistant).
 *
 * What it is (and is NOT):
 *  - A background audit (WP-Cron) refreshes the site's Google ranking positions
 *    for its own target keywords once every 14 days and CACHES the result in the
 *    `aq_ranking_snapshot` option. It is never fetched live on every chat.
 *  - The guardian consults the cache ONLY when a proposed edit is
 *    ranking-relevant (a heading, the SEO title, the meta description, or copy
 *    that carries a target keyword) — and only the rows for THAT page's target
 *    keywords, never the whole site.
 *  - Rankings can only break a tie between otherwise-acceptable wordings: protect
 *    phrasing that holds a strong position, be more flexible where the page ranks
 *    poorly. They NEVER override the audit/plan/brief and are NEVER on their own a
 *    reason to approve or block. The deterministic AQ_Assistant_Rules do not see
 *    them at all.
 *
 * Data comes from DataForSEO Labs (ranked_keywords), read with the same encrypted
 * credential store every other integration uses (AQ_Integrations). No secret ever
 * lives in code.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Ranking_Audit {

	/** Snapshot is considered stale (and eligible for refresh) at this age. */
	const TTL_DAYS = 14;

	/** Cached snapshot option (not autoloaded — only read when a ranking-relevant edit happens). */
	const OPTION = 'aq_ranking_snapshot';

	/** WP-Cron hook + custom 14-day recurrence name. */
	const HOOK      = 'aq_ranking_audit_event';
	const RECURRENCE = 'aq_14days';

	/* ============================================================
	 * Registration + scheduling
	 * ============================================================ */

	public static function register(): void {
		add_filter('cron_schedules', [__CLASS__, 'add_schedule']);
		add_action(self::HOOK, [__CLASS__, 'run_scan']);
		add_action('init', [__CLASS__, 'ensure_scheduled'], 20);
	}

	/** Register the 14-day recurrence used by the audit event. */
	public static function add_schedule($schedules) {
		if (!is_array($schedules)) {
			$schedules = [];
		}
		if (!isset($schedules[self::RECURRENCE])) {
			$schedules[self::RECURRENCE] = ['interval' => 14 * DAY_IN_SECONDS, 'display' => 'Every 14 days (AutoForge rankings)'];
		}
		return $schedules;
	}

	/**
	 * Idempotent, light — runs on init. Schedule the recurring audit when
	 * DataForSEO credentials exist and it isn't scheduled; unschedule it when the
	 * credentials go away so a credential-less site carries no dead cron.
	 */
	public static function ensure_scheduled(): void {
		$has  = self::has_credentials();
		$next = wp_next_scheduled(self::HOOK);
		if ($has) {
			if (!$next) {
				wp_schedule_event(time() + HOUR_IN_SECONDS, self::RECURRENCE, self::HOOK);
			}
			return;
		}
		if ($next) {
			wp_clear_scheduled_hook(self::HOOK);
		}
	}

	/** Called from the plugin deactivation hook. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook(self::HOOK);
	}

	/* ============================================================
	 * Snapshot accessors + freshness (pure helpers below)
	 * ============================================================ */

	/** The cached snapshot, or null if none has ever been stored. */
	public static function snapshot(): ?array {
		$snap = get_option(self::OPTION, null);
		return is_array($snap) ? $snap : null;
	}

	/** Whole-day age of the cached snapshot, or null when there is none. */
	public static function age_days(): ?int {
		$snap = self::snapshot();
		if (!$snap || empty($snap['generated_at'])) {
			return null;
		}
		return self::age_days_from((int) $snap['generated_at'], time());
	}

	/** True when there is no snapshot OR it has reached the TTL. */
	public static function is_stale(): bool {
		$snap = self::snapshot();
		if (!$snap || empty($snap['generated_at'])) {
			return true;
		}
		return self::is_stale_from((int) $snap['generated_at'], time());
	}

	/* ---- pure freshness math (unit-tested without WordPress) ---- */

	public static function age_days_from(int $generated_at, int $now): int {
		if ($generated_at <= 0) {
			return 0;
		}
		return (int) floor(max(0, $now - $generated_at) / 86400);
	}

	public static function is_stale_from(int $generated_at, int $now): bool {
		if ($generated_at <= 0) {
			return true;
		}
		return self::age_days_from($generated_at, $now) >= self::TTL_DAYS;
	}

	/* ============================================================
	 * Target keywords (from the per-page content-intent plans)
	 * ============================================================ */

	/**
	 * Every published page's target keywords: its primary_intent plus each
	 * secondary_keywords[] entry. Deduped case-insensitively (original casing of
	 * the first occurrence kept), trimmed, empties dropped.
	 * @return array<int,string>
	 */
	public static function target_keywords(): array {
		$pages = get_posts([
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		]);
		$lists = [];
		foreach ((array) $pages as $pid) {
			$lists[] = self::plan_keywords((int) $pid);
		}
		return self::dedupe_keywords($lists);
	}

	/** This page's own target keywords (primary_intent + secondary_keywords[]). */
	private static function plan_keywords(int $post_id): array {
		$intent = json_decode((string) get_post_meta($post_id, '_aq_content_intent', true), true);
		if (!is_array($intent)) {
			return [];
		}
		$out = [];
		$primary = trim((string) ($intent['primary_intent'] ?? ''));
		if ($primary !== '') {
			$out[] = $primary;
		}
		foreach ((array) ($intent['secondary_keywords'] ?? []) as $kw) {
			$kw = trim((string) $kw);
			if ($kw !== '') {
				$out[] = $kw;
			}
		}
		return $out;
	}

	/**
	 * Merge several keyword lists, trim, drop empties, dedupe case-insensitively
	 * keeping the first occurrence's casing. Pure — testable without WordPress.
	 * @param array<int,array<int,string>> $lists
	 * @return array<int,string>
	 */
	public static function dedupe_keywords(array $lists): array {
		$seen = [];
		$out  = [];
		foreach ($lists as $list) {
			foreach ((array) $list as $kw) {
				$kw = trim((string) $kw);
				if ($kw === '') {
					continue;
				}
				$key = function_exists('mb_strtolower') ? mb_strtolower($kw) : strtolower($kw);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$out[] = $kw;
			}
		}
		return $out;
	}

	/* ============================================================
	 * Rows for one page (what the guardian actually consults)
	 * ============================================================ */

	/**
	 * The cached snapshot rows whose keyword matches one of THIS page's target
	 * keywords (primary_intent + secondary_keywords[]), case-insensitively.
	 * Returns [] when there is no snapshot.
	 * @return array<int,array{keyword:string,position:int|null,volume:int|null,url:string}>
	 */
	public static function rows_for_page(int $post_id): array {
		$snap = self::snapshot();
		if (!$snap || empty($snap['rows']) || !is_array($snap['rows'])) {
			return [];
		}
		return self::filter_rows($snap['rows'], self::plan_keywords($post_id));
	}

	/**
	 * Keep only the snapshot rows whose 'keyword' matches one of $keywords
	 * (case-insensitive, trimmed). Pure — testable without WordPress.
	 * @param array<int,array> $rows
	 * @param array<int,string> $keywords
	 * @return array<int,array>
	 */
	public static function filter_rows(array $rows, array $keywords): array {
		if (!$keywords) {
			return [];
		}
		$want = [];
		foreach ($keywords as $kw) {
			$kw = trim((string) $kw);
			if ($kw === '') {
				continue;
			}
			$want[function_exists('mb_strtolower') ? mb_strtolower($kw) : strtolower($kw)] = true;
		}
		if (!$want) {
			return [];
		}
		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$k = trim((string) ($row['keyword'] ?? ''));
			if ($k === '') {
				continue;
			}
			$key = function_exists('mb_strtolower') ? mb_strtolower($k) : strtolower($k);
			if (isset($want[$key])) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/* ============================================================
	 * Credentials
	 * ============================================================ */

	/** True when both DataForSEO login + password are set (via constant or the encrypted store). */
	public static function has_credentials(): bool {
		if (!class_exists('AQ_Integrations')) {
			return false;
		}
		$cred = AQ_Integrations::dataforseo();
		return (string) ($cred['login'] ?? '') !== '' && (string) ($cred['password'] ?? '') !== '';
	}

	/* ============================================================
	 * The scan (cron callback + manual trigger)
	 * ============================================================ */

	/**
	 * Refresh the ranking snapshot from DataForSEO. Never overwrites a good
	 * snapshot on a transport/HTTP failure.
	 * @return array{ok:bool,error?:string,count?:int,ranked?:int}
	 */
	public static function run_scan(): array {
		if (!self::has_credentials()) {
			return ['ok' => false, 'error' => 'no DataForSEO credentials'];
		}

		$domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		$domain = preg_replace('/^www\./i', '', $domain);
		if ($domain === '') {
			return ['ok' => false, 'error' => 'could not determine site domain'];
		}

		$location = (int) apply_filters('aq_ranking_location_code', 2840); // United States
		$language = (string) apply_filters('aq_ranking_language_code', 'en');

		$cred = AQ_Integrations::dataforseo();
		$body = [[
			'target'        => $domain,
			'location_code' => $location,
			'language_code' => $language,
			'limit'         => 700,
		]];

		$resp = wp_remote_post('https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live', [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode(((string) $cred['login']) . ':' . ((string) $cred['password'])),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode($body),
		]);

		if (is_wp_error($resp)) {
			// Do NOT overwrite a good snapshot on a transport error.
			return ['ok' => false, 'error' => 'transport: ' . $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code !== 200) {
			return ['ok' => false, 'error' => 'HTTP ' . $code];
		}
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if (!is_array($data)) {
			return ['ok' => false, 'error' => 'unreadable response'];
		}

		$items  = $data['tasks'][0]['result'][0]['items'] ?? null;
		if (!is_array($items)) {
			$msg = (string) ($data['tasks'][0]['status_message'] ?? 'no result items');
			return ['ok' => false, 'error' => $msg];
		}

		// keyword(lowercased) => [position, volume, url]
		$lookup = [];
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$kw = trim((string) ($item['keyword_data']['keyword'] ?? ''));
			if ($kw === '') {
				continue;
			}
			$vol    = $item['keyword_data']['keyword_info']['search_volume'] ?? null;
			$serp   = $item['ranked_serp_element']['serp_item'] ?? [];
			$rank   = $serp['rank_absolute'] ?? ($serp['rank_group'] ?? null);
			$url    = (string) ($serp['url'] ?? ($serp['relative_url'] ?? ''));
			$key    = function_exists('mb_strtolower') ? mb_strtolower($kw) : strtolower($kw);
			$lookup[$key] = [
				'position' => is_numeric($rank) ? (int) $rank : null,
				'volume'   => is_numeric($vol) ? (int) $vol : null,
				'url'      => $url,
			];
		}

		$rows   = [];
		$ranked = 0;
		foreach (self::target_keywords() as $kw) {
			$key = function_exists('mb_strtolower') ? mb_strtolower($kw) : strtolower($kw);
			$hit = $lookup[$key] ?? null;
			if ($hit && $hit['position'] !== null) {
				$ranked++;
			}
			$rows[] = [
				'keyword'  => $kw,
				'position' => $hit['position'] ?? null,
				'volume'   => $hit['volume'] ?? null,
				'url'      => $hit['url'] ?? '',
			];
		}

		update_option(self::OPTION, [
			'generated_at'  => time(),
			'location_code' => $location,
			'language_code' => $language,
			'rows'          => $rows,
			'source'        => 'dataforseo',
			'error'         => '',
		], false);

		return ['ok' => true, 'count' => count($rows), 'ranked' => $ranked];
	}

	/**
	 * The lazy "pulled as needed" top-up: if the snapshot is stale and credentials
	 * exist, queue a near-term one-off run and spawn cron. Never blocks the
	 * request; fires at most once per request.
	 */
	public static function refresh_async(): void {
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;
		if (!self::is_stale() || !self::has_credentials()) {
			return;
		}
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + 1, self::HOOK);
		}
		if (function_exists('spawn_cron')) {
			spawn_cron();
		}
	}
}
