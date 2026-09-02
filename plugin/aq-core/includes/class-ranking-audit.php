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
 * Data comes from TWO sources merged into the one snapshot, both read with the same
 * encrypted credential store every other integration uses (AQ_Integrations):
 *  - Google Search Console (service-account JWT auth) = the site's REAL Google
 *    performance (average position, impressions, clicks) — preferred as ground
 *    truth, and the source of the "also showing for" observed queries.
 *  - DataForSEO Labs (ranked_keywords) = search volume, plus SERP position for
 *    target keywords the site does not yet appear for in GSC.
 * Both run inside the single 14-day `aq_ranking_audit_event` (no second cron). No
 * secret ever lives in code.
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
		$has  = self::has_credentials() || self::has_gsc_credentials();
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
	 * The merged per-page ranking rows the guardian consults: GSC ground-truth
	 * performance (average position, impressions, clicks) preferred, with DataForSEO
	 * volume + SERP position attached, plus up to 3 extra high-impression GSC
	 * queries the page actually shows for. Scoped to THIS page's target keywords and
	 * normalized path. Returns [] when there is no snapshot / nothing for the page.
	 * @return array<int,array{keyword:string,gsc_position:float|null,impressions:int|null,clicks:int|null,dfs_position:int|null,volume:int|null,url:string,observed?:bool}>
	 */
	public static function rows_for_page(int $post_id): array {
		$snap = self::snapshot();
		if (!$snap || !is_array($snap)) {
			return [];
		}
		$keywords = self::plan_keywords($post_id);

		$dfsRows = (isset($snap['rows']) && is_array($snap['rows'])) ? self::filter_rows($snap['rows'], $keywords) : [];

		// GSC rows for THIS page's normalized path.
		$gscRows = (isset($snap['gsc']['rows']) && is_array($snap['gsc']['rows'])) ? $snap['gsc']['rows'] : [];
		$path    = self::normalize_page_path((string) (wp_parse_url((string) get_permalink($post_id), PHP_URL_PATH) ?: '/'));
		$gscPage = [];
		foreach ($gscRows as $g) {
			if (is_array($g) && self::normalize_page_path((string) ($g['page'] ?? '')) === $path) {
				$gscPage[] = $g;
			}
		}

		return self::merge_rows($dfsRows, $gscPage, $keywords);
	}

	/**
	 * Merge DataForSEO keyword rows with a page's GSC query rows for a set of target
	 * keywords, GSC preferred as ground truth. Pure — unit-tested without WordPress.
	 *
	 * One row per target keyword (GSC position/impressions/clicks where a query
	 * matches — exact first, else a contains-either-way match — plus DFS volume +
	 * SERP position), THEN up to 3 EXTRA rows for the page's highest-impression GSC
	 * queries not already covered by a target keyword (marked observed=true).
	 * @param array<int,array> $dfsRows      snapshot DFS rows: {keyword,position,volume,url}
	 * @param array<int,array> $gscPageRows  this page's GSC rows: {page,query,position,impressions,clicks}
	 * @param array<int,string> $targetKeywords
	 * @return array<int,array>
	 */
	public static function merge_rows(array $dfsRows, array $gscPageRows, array $targetKeywords): array {
		$lc = static function ($s): string {
			$s = trim((string) $s);
			return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
		};

		// Index DFS rows by keyword (lowercased).
		$dfs = [];
		foreach ($dfsRows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$k = $lc($row['keyword'] ?? '');
			if ($k !== '') {
				$dfs[$k] = $row;
			}
		}

		$covered = []; // lowercased GSC query strings matched to a target keyword
		$out     = [];

		foreach ($targetKeywords as $kw) {
			$kw = trim((string) $kw);
			if ($kw === '') {
				continue;
			}
			$kwl = $lc($kw);

			// Best GSC match: exact query first, else contains either way.
			$match = null;
			foreach ($gscPageRows as $g) {
				if (is_array($g) && $lc($g['query'] ?? '') === $kwl) {
					$match = $g;
					break;
				}
			}
			if ($match === null) {
				foreach ($gscPageRows as $g) {
					if (!is_array($g)) {
						continue;
					}
					$q = $lc($g['query'] ?? '');
					if ($q !== '' && (strpos($q, $kwl) !== false || strpos($kwl, $q) !== false)) {
						$match = $g;
						break;
					}
				}
			}
			if ($match !== null) {
				$covered[$lc($match['query'] ?? '')] = true;
			}

			$dfsRow = $dfs[$kwl] ?? null;
			$out[]  = [
				'keyword'      => $kw,
				'gsc_position' => $match !== null ? (float) $match['position'] : null,
				'impressions'  => $match !== null ? (int) $match['impressions'] : null,
				'clicks'       => $match !== null ? (int) $match['clicks'] : null,
				'dfs_position' => ($dfsRow && ($dfsRow['position'] ?? null) !== null) ? (int) $dfsRow['position'] : null,
				'volume'       => ($dfsRow && ($dfsRow['volume'] ?? null) !== null) ? (int) $dfsRow['volume'] : null,
				'url'          => $match !== null ? (string) ($match['page'] ?? '') : ($dfsRow ? (string) ($dfsRow['url'] ?? '') : ''),
			];
		}

		// Extras: page's highest-impression GSC queries NOT covered by a target keyword.
		$extras = [];
		foreach ($gscPageRows as $g) {
			if (!is_array($g)) {
				continue;
			}
			$q = trim((string) ($g['query'] ?? ''));
			if ($q === '') {
				continue;
			}
			$ql = $lc($q);
			if (isset($covered[$ql])) {
				continue;
			}
			// Skip anything that matches a target keyword (avoid near-duplicate rows).
			$isTarget = false;
			foreach ($targetKeywords as $kw) {
				$kwl = $lc($kw);
				if ($kwl !== '' && ($ql === $kwl || strpos($ql, $kwl) !== false || strpos($kwl, $ql) !== false)) {
					$isTarget = true;
					break;
				}
			}
			if ($isTarget) {
				continue;
			}
			$extras[] = [
				'keyword'      => $q,
				'observed'     => true,
				'gsc_position' => (float) ($g['position'] ?? 0),
				'impressions'  => (int) ($g['impressions'] ?? 0),
				'clicks'       => (int) ($g['clicks'] ?? 0),
				'dfs_position' => null,
				'volume'       => null,
				'url'          => (string) ($g['page'] ?? ''),
			];
		}
		usort($extras, static function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });
		$extras = array_slice($extras, 0, 3);

		return array_merge($out, $extras);
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
	 * One 14-day audit, TWO merged sources. Runs the DataForSEO keyword scan and
	 * the Google Search Console scan, then stores ONE snapshot: the DFS rows keep
	 * their existing top-level shape, GSC lands in a `gsc` sub-block. A source that
	 * fails leaves the other source's data untouched (no healthy block is wiped),
	 * and a good snapshot is never overwritten when BOTH sources fail.
	 * @return array{ok:bool,error?:string,count?:int,ranked?:int,gsc_count?:int,dfs_error?:string,gsc_error?:string}
	 */
	public static function run_scan(): array {
		$hasDfs = self::has_credentials();
		$hasGsc = self::has_gsc_credentials();
		if (!$hasDfs && !$hasGsc) {
			return ['ok' => false, 'error' => 'no DataForSEO or GSC credentials'];
		}

		$snap  = self::snapshot() ?: [];
		if (!is_array($snap)) {
			$snap = [];
		}
		$anyOk   = false;
		$summary = ['ok' => false];

		/* ---- DataForSEO (search volume + where the site ranks in the SERP) ---- */
		$dfs = $hasDfs ? self::dataforseo_scan() : ['ok' => false, 'error' => 'no DataForSEO credentials'];
		if (!empty($dfs['ok'])) {
			$anyOk = true;
			$snap['location_code'] = $dfs['location_code'];
			$snap['language_code'] = $dfs['language_code'];
			$snap['rows']          = $dfs['rows'];
			$snap['source']        = 'dataforseo';
			$snap['error']         = '';
			$summary['count']  = count($dfs['rows']);
			$summary['ranked'] = (int) $dfs['ranked'];
		} elseif ($hasDfs) {
			$summary['dfs_error'] = (string) ($dfs['error'] ?? 'DataForSEO scan failed');
		}

		/* ---- Google Search Console (real Google performance = ground truth) ---- */
		$gsc = $hasGsc ? self::gsc_scan() : ['ok' => false, 'error' => 'no GSC credentials'];
		if (!empty($gsc['ok'])) {
			$anyOk = true;
			$snap['gsc'] = [
				'generated_at' => time(),
				'site_url'     => (string) $gsc['site_url'],
				'rows'         => $gsc['rows'],
				'error'        => '',
			];
			$summary['gsc_count'] = (int) $gsc['count'];
		} elseif ($hasGsc) {
			// Keep the prior GSC sub-block (rows, age, site) — just record the error.
			$prior = (isset($snap['gsc']) && is_array($snap['gsc'])) ? $snap['gsc'] : [];
			$prior['error'] = (string) ($gsc['error'] ?? 'GSC scan failed');
			$snap['gsc'] = $prior;
			$summary['gsc_error'] = (string) ($gsc['error'] ?? 'GSC scan failed');
		}

		if (!$anyOk) {
			// Both sources failed — never overwrite a good snapshot.
			$err = trim((string) ($summary['dfs_error'] ?? '') . ' ' . (string) ($summary['gsc_error'] ?? ''));
			return ['ok' => false, 'error' => $err !== '' ? $err : 'audit failed'];
		}

		$snap['generated_at'] = time();
		update_option(self::OPTION, $snap, false);

		$summary['ok'] = true;
		if (!isset($summary['count'])) {
			$summary['count'] = (isset($snap['rows']) && is_array($snap['rows'])) ? count($snap['rows']) : 0;
		}
		if (!isset($summary['ranked'])) {
			$summary['ranked'] = 0;
		}
		return $summary;
	}

	/**
	 * The DataForSEO half of the audit: ranked-keyword positions + search volume
	 * for the site's own target keywords. Returns rows WITHOUT writing the snapshot
	 * (the orchestrator run_scan() merges + stores). Never throws.
	 * @return array{ok:bool,error?:string,rows?:array,ranked?:int,location_code?:int,language_code?:string}
	 */
	private static function dataforseo_scan(): array {
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

		$items = $data['tasks'][0]['result'][0]['items'] ?? null;
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

		return [
			'ok'            => true,
			'rows'          => $rows,
			'ranked'        => $ranked,
			'location_code' => $location,
			'language_code' => $language,
		];
	}

	/* ============================================================
	 * Google Search Console (second source, service-account auth)
	 * ============================================================ */

	/** All three GSC fields present AND openssl_sign available to build the JWT. */
	public static function has_gsc_credentials(): bool {
		if (!class_exists('AQ_Integrations') || !function_exists('openssl_sign')) {
			return false;
		}
		$c = AQ_Integrations::gsc();
		return (string) ($c['client_email'] ?? '') !== ''
			&& (string) ($c['private_key'] ?? '') !== ''
			&& (string) ($c['site_url'] ?? '') !== '';
	}

	/** base64url (no padding) — for JWT segments. Pure. */
	private static function b64url(string $bin): string {
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	/**
	 * Mint a short-lived Google OAuth access token from the service account using
	 * the signed-JWT (server-to-server) flow — no interactive OAuth. Never throws.
	 * @return array{ok:bool,token:string,error:string}
	 */
	public static function gsc_access_token(): array {
		if (!self::has_gsc_credentials()) {
			return ['ok' => false, 'token' => '', 'error' => 'no GSC credentials'];
		}
		$c   = AQ_Integrations::gsc();
		$now = time();

		$header = ['alg' => 'RS256', 'typ' => 'JWT'];
		$claims = [
			'iss'   => (string) $c['client_email'],
			'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		];
		$signingInput = self::b64url((string) wp_json_encode($header)) . '.' . self::b64url((string) wp_json_encode($claims));

		$sig = '';
		if (!openssl_sign($signingInput, $sig, (string) $c['private_key'], OPENSSL_ALGO_SHA256)) {
			return ['ok' => false, 'token' => '', 'error' => 'could not sign the request (check the private key)'];
		}
		$jwt = $signingInput . '.' . self::b64url($sig);

		$resp = wp_remote_post('https://oauth2.googleapis.com/token', [
			'timeout' => 30,
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		]);
		if (is_wp_error($resp)) {
			return ['ok' => false, 'token' => '', 'error' => 'transport: ' . $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
			$err = is_array($data) ? (string) ($data['error_description'] ?? ($data['error'] ?? ('HTTP ' . $code))) : ('HTTP ' . $code);
			return ['ok' => false, 'token' => '', 'error' => $err];
		}
		return ['ok' => true, 'token' => (string) $data['access_token'], 'error' => ''];
	}

	/**
	 * Pull the site's real Search Console performance for the last 28 days,
	 * dimensioned by page + query. Returns normalized rows WITHOUT writing the
	 * snapshot; on any failure returns ok=false and touches nothing. Never throws.
	 * @return array{ok:bool,error?:string,rows?:array,count?:int,site_url?:string}
	 */
	public static function gsc_scan(): array {
		if (!self::has_gsc_credentials()) {
			return ['ok' => false, 'error' => 'no GSC credentials'];
		}
		$tok = self::gsc_access_token();
		if (empty($tok['ok'])) {
			return ['ok' => false, 'error' => 'auth: ' . ($tok['error'] !== '' ? $tok['error'] : 'no token')];
		}

		$c    = AQ_Integrations::gsc();
		$site = (string) $c['site_url'];
		$url  = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query';
		$body = [
			'startDate'  => gmdate('Y-m-d', time() - (28 * DAY_IN_SECONDS)),
			'endDate'    => gmdate('Y-m-d', time()),
			'dimensions' => ['page', 'query'],
			'rowLimit'   => 1000,
		];

		$resp = wp_remote_post($url, [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . $tok['token'],
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode($body),
		]);
		if (is_wp_error($resp)) {
			return ['ok' => false, 'error' => 'transport: ' . $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200) {
			$msg = is_array($data) ? (string) ($data['error']['message'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
			return ['ok' => false, 'error' => $msg];
		}
		if (!is_array($data)) {
			return ['ok' => false, 'error' => 'unreadable response'];
		}

		$rows = [];
		foreach ((array) ($data['rows'] ?? []) as $r) {
			if (!is_array($r)) {
				continue;
			}
			$keys  = (array) ($r['keys'] ?? []);
			$query = trim((string) ($keys[1] ?? ''));
			if ($query === '') {
				continue;
			}
			$rows[] = [
				'page'        => self::normalize_page_path((string) ($keys[0] ?? '')),
				'query'       => $query,
				'position'    => round((float) ($r['position'] ?? 0), 1),
				'impressions' => (int) ($r['impressions'] ?? 0),
				'clicks'      => (int) ($r['clicks'] ?? 0),
			];
		}

		return ['ok' => true, 'rows' => $rows, 'count' => count($rows), 'site_url' => $site];
	}

	/**
	 * Reduce a GSC page URL (or a bare path) to a normalized site path: no scheme
	 * or host, a leading AND trailing slash, root => "/". Pure — unit-tested.
	 */
	public static function normalize_page_path(string $urlOrPath): string {
		$s = trim($urlOrPath);
		if ($s === '') {
			return '/';
		}
		// Drop scheme + host when a full URL was given (GSC `page` rows are URLs).
		if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $s)) {
			$path = (string) parse_url($s, PHP_URL_PATH);
		} else {
			$path = $s;
		}
		// Strip any query / fragment that slipped through.
		$path = (string) preg_replace('/[?#].*$/', '', $path);
		if ($path === '') {
			return '/';
		}
		if ($path[0] !== '/') {
			$path = '/' . $path;
		}
		if (substr($path, -1) !== '/') {
			$path .= '/';
		}
		return $path;
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
		if (!self::is_stale() || (!self::has_credentials() && !self::has_gsc_credentials())) {
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
