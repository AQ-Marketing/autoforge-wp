<?php
/**
 * AQ SEO Agent — autonomous SEO + AI-search monitor with emailed reports.
 *
 * An unattended agent that, on a schedule (weekly / every 2 weeks / monthly),
 * pulls live search data for this site from DataForSEO, compares it against the
 * previous run, turns the numbers into a plain-English report + prioritized
 * action plan, and emails it to the owner via WordPress's own mailer (wp_mail).
 *
 * What it measures each run (all optional / cost-controlled):
 *  - Domain overview: how many keywords the site ranks for + estimated traffic.
 *  - Tracked keywords: the site's Google position for a short, editable list,
 *    plus whether a local pack / AI Overview shows for that search.
 *  - Search volume: monthly searches for the tracked keywords (prioritization).
 *  - AI-search visibility: asks Perplexity (live web search) a real buyer
 *    question and checks whether THIS business is named in the answer.
 *
 * Data source: DataForSEO REST (HTTP Basic). Credentials come from
 * AutoForge → Integrations (AQ_Integrations::dataforseo()). The narrative is
 * written by the same OpenAI key the AI Assistant uses (AQ_Assistant::api_key());
 * with no key it falls back to a clear rules-based summary, so the email always
 * sends. No third-party services beyond the APIs the owner already configured.
 *
 * Self-contained: own WP-Cron schedules, own option store, manage_options-gated
 * settings screen under AutoForge → SEO Agent, and a "Send a report now" button.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_SEO_Agent {

	const CAP     = 'manage_options';
	const OPTION  = 'aq_seo_agent';       // settings
	const HOOK    = 'aq_seo_agent_run';   // cron event
	const LAST    = 'aq_seo_agent_last';  // last full report (html + data)
	const HISTORY = 'aq_seo_agent_history'; // compact snapshots for trend/diff

	const API_BASE   = 'https://api.dataforseo.com/v3';
	const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
	const OPENAI_MODEL = 'gpt-4o-mini';
	const MAX_TRACKED  = 15;   // hard cap on keywords scanned per run (cost guard)
	const HISTORY_KEEP = 12;   // snapshots retained

	public static function register(): void {
		add_filter('cron_schedules', [__CLASS__, 'add_schedules']);
		add_action('init', [__CLASS__, 'maybe_schedule']);
		add_action(self::HOOK, [__CLASS__, 'run_scan']);
		add_action('admin_menu', [__CLASS__, 'menu'], 23);
		add_action('admin_post_aq_seo_agent_save', [__CLASS__, 'save_settings']);
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	/* ============================================================
	 * Settings
	 * ============================================================ */

	public static function defaults(): array {
		return [
			'enabled'    => false,
			'frequency'  => 'biweekly',                 // weekly | biweekly | monthly
			'recipients' => (string) get_option('admin_email'),
			'location'   => self::default_location(),   // region-level, e.g. "Massachusetts,United States"
			'keywords'   => self::default_keywords(),   // array of strings
			'track_serp' => true,                       // per-keyword Google position
			'track_ai'   => true,                        // AI-assistant (Perplexity) visibility
			'ai_prompt'  => self::default_ai_prompt(),
		];
	}

	public static function opts(): array {
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		$out = array_merge(self::defaults(), $o);
		if (!is_array($out['keywords'])) {
			$out['keywords'] = self::default_keywords();
		}
		return $out;
	}

	public static function is_enabled(): bool {
		return !empty(self::opts()['enabled']);
	}

	/** DataForSEO credentials present? */
	public static function dataforseo_ready(): bool {
		if (!class_exists('AQ_Integrations')) {
			return false;
		}
		$c = AQ_Integrations::dataforseo();
		return $c['login'] !== '' && $c['password'] !== '';
	}

	public static function openai_ready(): bool {
		return class_exists('AQ_Assistant') && AQ_Assistant::api_key() !== '';
	}

	/* ---- default seeds (client-agnostic: derived from site config) ---- */

	private static function default_location(): string {
		$region = function_exists('aq_site') ? (string) (aq_site('address.region') ?: '') : '';
		$name   = self::state_name($region);
		return $name !== '' ? $name . ',United States' : 'United States';
	}

	private static function default_keywords(): array {
		$kw = [];
		// Build default keywords from site config.
		$industry = (string) (aq_site('industry') ?: '');
		$name     = (string) (aq_site('name') ?: '');
		if ($industry !== '') {
			$kw[] = $industry;
			$kw[] = $industry . ' near me';
		} elseif ($name !== '') {
			$kw[] = $name;
		}
		$towns = function_exists('aq_site') ? aq_site('towns') : null;
		if (is_array($towns) && isset($towns[0]['name'])) {
			$kw[] = $towns[0]['name'] . ' ' . ($industry ?: 'services');
		}
		$regions = function_exists('aq_site') ? aq_site('regions') : null;
		if (is_array($regions) && isset($regions[0])) {
			$kw[] = $regions[0] . ' ' . ($industry ?: 'services');
		}
		array_push($kw, 'radon testing', 'mold inspection', 'septic inspection');
		return array_values(array_unique($kw));
	}

	private static function default_ai_prompt(): string {
		$town   = 'this area';
		$region = '';
		if (function_exists('aq_site')) {
			$towns = aq_site('towns');
			if (is_array($towns) && isset($towns[0]['name'])) {
				$town = $towns[0]['name'];
			}
			$regions = aq_site('regions');
			if (is_array($regions) && isset($regions[0])) {
				$region = (string) $regions[0];
			}
		}
		$where = $region !== '' ? "$town and the $region area" : $town;
		$ind = (string) (aq_site('industry') ?: 'service providers');
		return "Who are the best {$ind} near $where? List the top companies by name and say why you recommend each.";
	}

	/** Take the country portion (after the last comma) of a region-level location. */
	private static function country_of(string $location): string {
		$parts = array_map('trim', explode(',', $location));
		return $parts[count($parts) - 1] ?: 'United States';
	}

	private static function state_name(string $abbr): string {
		$map = [
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
			'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia',
			'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
			'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
			'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri',
			'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
			'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
			'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
			'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
			'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
			'DC' => 'District of Columbia',
		];
		return $map[strtoupper(trim($abbr))] ?? '';
	}

	/* ============================================================
	 * Scheduling
	 * ============================================================ */

	public static function add_schedules($schedules) {
		if (!is_array($schedules)) {
			$schedules = [];
		}
		$schedules['aq_weekly']   = ['interval' => 7 * DAY_IN_SECONDS,  'display' => 'Once a week (AutoForge)'];
		$schedules['aq_biweekly'] = ['interval' => 14 * DAY_IN_SECONDS, 'display' => 'Every two weeks (AutoForge)'];
		$schedules['aq_monthly']  = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Once a month (AutoForge)'];
		return $schedules;
	}

	private static function interval_for(string $freq): string {
		switch ($freq) {
			case 'weekly':  return 'aq_weekly';
			case 'monthly': return 'aq_monthly';
			case 'biweekly':
			default:        return 'aq_biweekly';
		}
	}

	/** Ensure the cron event matches the current settings (idempotent; runs on init). */
	public static function maybe_schedule(): void {
		$o = self::opts();
		$next = wp_next_scheduled(self::HOOK);
		if (empty($o['enabled'])) {
			if ($next) {
				wp_clear_scheduled_hook(self::HOOK);
			}
			return;
		}
		$want = self::interval_for((string) $o['frequency']);
		if (!$next) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, $want, self::HOOK);
			return;
		}
		// Reschedule if the chosen interval changed.
		$event = wp_get_scheduled_event(self::HOOK);
		if ($event && isset($event->schedule) && $event->schedule !== $want) {
			self::reschedule($want);
		}
	}

	private static function reschedule(string $interval): void {
		wp_clear_scheduled_hook(self::HOOK);
		wp_schedule_event(time() + HOUR_IN_SECONDS, $interval, self::HOOK);
	}

	/** Called from the plugin deactivation hook. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook(self::HOOK);
	}

	public static function next_run_ts(): int {
		return (int) wp_next_scheduled(self::HOOK);
	}

	/* ============================================================
	 * The scan (cron callback + manual trigger)
	 * ============================================================ */

	/**
	 * Run a full scan: gather data, diff vs last run, build + email the report.
	 * @return array{ok:bool, status:string, sent:bool, message:string}
	 */
	public static function run_scan(bool $manual = false): array {
		$o = self::opts();
		if (!self::dataforseo_ready()) {
			$res = ['ok' => false, 'status' => 'no_credentials', 'sent' => false, 'message' => 'DataForSEO credentials are not set under AutoForge → Integrations.'];
			self::record_run($res);
			return $res;
		}

		$target   = self::target_domain();
		$location = (string) $o['location'];
		$country  = self::country_of($location);
		$keywords = array_slice(array_values(array_filter(array_map('trim', (array) $o['keywords']))), 0, self::MAX_TRACKED);

		$data = [
			'time'      => time(),
			'target'    => $target,
			'location'  => $location,
			'overview'  => self::fetch_domain_overview($target, $country),
			'ranked'    => self::fetch_ranked_keywords($target, $country, 25),
			'tracked'   => [],
			'volumes'   => [],
			'ai'        => null,
			'errors'    => [],
		];

		if (!empty($o['track_serp']) && $keywords) {
			$data['volumes'] = self::fetch_search_volumes($keywords, $location);
			foreach ($keywords as $kw) {
				$data['tracked'][$kw] = self::fetch_serp_position($kw, $location, $target);
			}
		}

		if (!empty($o['track_ai'])) {
			$data['ai'] = self::fetch_ai_visibility((string) $o['ai_prompt'], $target);
		}

		// Build compact snapshot + diff against the previous one.
		$snapshot = self::build_snapshot($data);
		$history  = get_option(self::HISTORY, []);
		$history  = is_array($history) ? $history : [];
		$previous = !empty($history) ? end($history) : null;
		$diff     = self::diff_snapshots(is_array($previous) ? $previous : null, $snapshot);

		// Narrative + HTML email.
		$html    = self::build_report_html($data, $snapshot, $diff);
		$subject = 'Your SEO & AI-search report — ' . wp_date('M j, Y');
		$sent    = self::send_email($html, $subject, (string) $o['recipients']);

		// Persist.
		$history[] = $snapshot;
		if (count($history) > self::HISTORY_KEEP) {
			$history = array_slice($history, -self::HISTORY_KEEP);
		}
		update_option(self::HISTORY, $history, false);
		update_option(self::LAST, [
			'time'    => $data['time'],
			'subject' => $subject,
			'html'    => $html,
			'manual'  => $manual,
		], false);

		$res = ['ok' => true, 'status' => $sent ? 'sent' : 'built_not_sent', 'sent' => $sent,
			'message' => $sent ? 'Report sent to ' . esc_html((string) $o['recipients']) : 'Report built but the email could not be sent (check WordPress email delivery).'];
		self::record_run($res);
		return $res;
	}

	private static function record_run(array $res): void {
		$o = self::opts();
		$o['last_run']    = time();
		$o['last_status'] = (string) $res['status'];
		update_option(self::OPTION, $o, false);
	}

	private static function target_domain(): string {
		$url = function_exists('aq_site') ? (string) (aq_site('url') ?: '') : '';
		if ($url === '') {
			$url = (string) home_url();
		}
		return self::normalize_domain($url);
	}

	private static function normalize_domain(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);
		$host = $host ?: $url;
		$host = preg_replace('#^https?://#i', '', (string) $host);
		$host = preg_replace('#^www\.#i', '', (string) $host);
		return strtolower(trim((string) $host, "/ \t\n"));
	}

	/* ============================================================
	 * DataForSEO calls
	 * ============================================================ */

	/** POST a single task to a DataForSEO live endpoint. Returns result[0] array or null. */
	private static function dfs_post(string $path, array $task, int $timeout = 30) {
		$c = AQ_Integrations::dataforseo();
		$resp = wp_remote_post(self::API_BASE . $path, [
			'timeout' => $timeout,
			'headers' => [
				'content-type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode($c['login'] . ':' . $c['password']),
			],
			'body' => wp_json_encode([$task], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
		if (is_wp_error($resp)) {
			return null;
		}
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if (!is_array($data) || (int) ($data['status_code'] ?? 0) !== 20000) {
			return null;
		}
		$t = $data['tasks'][0] ?? null;
		if (!is_array($t) || (int) ($t['status_code'] ?? 0) !== 20000) {
			return null;
		}
		return is_array($t['result'] ?? null) ? $t['result'] : null;
	}

	/** Organic ranking distribution + estimated traffic for the whole domain. */
	private static function fetch_domain_overview(string $target, string $country): ?array {
		$result = self::dfs_post('/dataforseo_labs/google/domain_rank_overview/live', [
			'target'        => $target,
			'location_name' => $country,
			'language_code' => 'en',
		]);
		// Metrics may sit at result[0].metrics.organic or result[0].items[0].metrics.organic.
		$row     = is_array($result) ? ($result[0] ?? null) : null;
		$metrics = is_array($row) ? ($row['metrics']['organic'] ?? ($row['items'][0]['metrics']['organic'] ?? null)) : null;
		if (!is_array($metrics)) {
			return ['count' => 0, 'etv' => 0.0, 'pos_1_3' => 0, 'pos_4_10' => 0, 'pos_11_100' => 0];
		}
		return [
			'count'      => (int) ($metrics['count'] ?? 0),
			'etv'        => (float) ($metrics['etv'] ?? 0),
			'pos_1_3'    => (int) ($metrics['pos_1'] ?? 0) + (int) ($metrics['pos_2_3'] ?? 0),
			'pos_4_10'   => (int) ($metrics['pos_4_10'] ?? 0),
			'pos_11_100' => (int) ($metrics['pos_11_20'] ?? 0) + (int) ($metrics['pos_21_30'] ?? 0)
				+ (int) ($metrics['pos_31_40'] ?? 0) + (int) ($metrics['pos_41_50'] ?? 0)
				+ (int) ($metrics['pos_51_60'] ?? 0) + (int) ($metrics['pos_61_70'] ?? 0)
				+ (int) ($metrics['pos_71_80'] ?? 0) + (int) ($metrics['pos_81_90'] ?? 0)
				+ (int) ($metrics['pos_91_100'] ?? 0),
		];
	}

	/** Top keywords the domain currently ranks for (name + position + volume). */
	private static function fetch_ranked_keywords(string $target, string $country, int $limit): array {
		$result = self::dfs_post('/dataforseo_labs/google/ranked_keywords/live', [
			'target'        => $target,
			'location_name' => $country,
			'language_code' => 'en',
			'limit'         => $limit,
			'order_by'      => ['ranked_serp_element.serp_item.rank_group,asc'],
		]);
		$items = is_array($result) ? ($result[0]['items'] ?? []) : [];
		$out = [];
		foreach ((array) $items as $it) {
			$kw   = $it['keyword_data']['keyword'] ?? null;
			$rank = $it['ranked_serp_element']['serp_item']['rank_group'] ?? null;
			$vol  = $it['keyword_data']['keyword_info']['search_volume'] ?? null;
			if ($kw === null) {
				continue;
			}
			$out[] = ['keyword' => (string) $kw, 'rank' => $rank !== null ? (int) $rank : null, 'volume' => (int) $vol];
		}
		return $out;
	}

	/** Monthly search volume for a set of keywords (region-level). */
	private static function fetch_search_volumes(array $keywords, string $location): array {
		$result = self::dfs_post('/keywords_data/google_ads/search_volume/live', [
			'keywords'      => array_values($keywords),
			'location_name' => $location,
			'language_code' => 'en',
		], 45);
		$out = [];
		foreach ((array) ($result ?? []) as $row) {
			if (isset($row['keyword'])) {
				$out[strtolower((string) $row['keyword'])] = (int) ($row['search_volume'] ?? 0);
			}
		}
		return $out;
	}

	/**
	 * The site's Google position for one keyword in the local market, plus
	 * whether a local pack / AI Overview is present for that search.
	 */
	private static function fetch_serp_position(string $keyword, string $location, string $target): array {
		$result = self::dfs_post('/serp/google/organic/live/advanced', [
			'keyword'       => $keyword,
			'location_name' => $location,
			'language_code' => 'en',
			'depth'         => 30,
		], 30);
		$out = ['rank' => null, 'local_rank' => null, 'ai_overview' => false, 'found' => $result !== null];
		$items = is_array($result) ? ($result[0]['items'] ?? []) : [];
		foreach ((array) $items as $it) {
			$type = (string) ($it['type'] ?? '');
			if ($type === 'ai_overview') {
				$out['ai_overview'] = true;
			}
			if ($out['rank'] === null && $type === 'organic'
				&& self::normalize_domain((string) ($it['domain'] ?? '')) === $target) {
				$out['rank'] = (int) ($it['rank_group'] ?? 0);
			}
			if ($out['local_rank'] === null && $type === 'local_pack'
				&& self::normalize_domain((string) ($it['domain'] ?? '')) === $target) {
				$out['local_rank'] = (int) ($it['rank_group'] ?? 0);
			}
		}
		return $out;
	}

	/** Ask Perplexity (live web search) a buyer question; detect if this business is named. */
	private static function fetch_ai_visibility(string $prompt, string $target): ?array {
		$result = self::dfs_post('/ai_optimization/perplexity/llm_responses/live', [
			'user_prompt' => $prompt,
			'model_name'  => 'sonar-pro',
			'web_search'  => true,
		], 60);
		$item = is_array($result) ? ($result[0]['items'][0] ?? null) : null;
		if (!is_array($item)) {
			return null;
		}
		$text = '';
		$cited = [];
		foreach ((array) ($item['sections'] ?? []) as $sec) {
			$text .= ' ' . (string) ($sec['text'] ?? '');
			foreach ((array) ($sec['annotations'] ?? []) as $a) {
				if (!empty($a['url'])) {
					$cited[] = self::normalize_domain((string) $a['url']);
				}
			}
		}
		$brand = function_exists('aq_site') ? (string) (aq_site('name') ?: '') : '';
		$brandHit = $brand !== '' && stripos($text, $brand) !== false;
		$domainHit = stripos($text, $target) !== false || in_array($target, $cited, true);
		return [
			'engine'    => 'Perplexity (sonar-pro)',
			'prompt'    => $prompt,
			'mentioned' => $brandHit || $domainHit,
			'cited'     => array_values(array_unique(array_filter($cited))),
		];
	}

	/* ============================================================
	 * Snapshot + diff
	 * ============================================================ */

	private static function build_snapshot(array $data): array {
		$tracked = [];
		foreach ((array) $data['tracked'] as $kw => $t) {
			$tracked[$kw] = [
				'rank'        => $t['rank'] ?? null,
				'local_rank'  => $t['local_rank'] ?? null,
				'ai_overview' => !empty($t['ai_overview']),
				'volume'      => (int) ($data['volumes'][strtolower($kw)] ?? 0),
			];
		}
		return [
			'time'         => (int) $data['time'],
			'ranked_count' => (int) (($data['overview']['count'] ?? 0)),
			'etv'          => (float) (($data['overview']['etv'] ?? 0)),
			'tracked'      => $tracked,
			'ai_mentioned' => is_array($data['ai']) ? (bool) $data['ai']['mentioned'] : null,
		];
	}

	private static function diff_snapshots(?array $prev, array $cur): array {
		if (!$prev) {
			return ['first_run' => true, 'moves' => [], 'ranked_delta' => 0, 'etv_delta' => 0.0, 'ai_change' => null];
		}
		$moves = [];
		foreach ($cur['tracked'] as $kw => $c) {
			$p = $prev['tracked'][$kw] ?? null;
			$pr = $p['rank'] ?? null;
			$cr = $c['rank'] ?? null;
			if ($pr === $cr) {
				continue;
			}
			$moves[] = ['keyword' => $kw, 'from' => $pr, 'to' => $cr];
		}
		return [
			'first_run'    => false,
			'moves'        => $moves,
			'ranked_delta' => (int) $cur['ranked_count'] - (int) ($prev['ranked_count'] ?? 0),
			'etv_delta'    => (float) $cur['etv'] - (float) ($prev['etv'] ?? 0),
			'ai_change'    => ($prev['ai_mentioned'] ?? null) === ($cur['ai_mentioned'] ?? null)
				? null : ['from' => $prev['ai_mentioned'] ?? null, 'to' => $cur['ai_mentioned'] ?? null],
		];
	}

	/* ============================================================
	 * Report (narrative + HTML email)
	 * ============================================================ */

	private static function build_report_html(array $data, array $snapshot, array $diff): string {
		$brand = function_exists('aq_site') ? (string) (aq_site('shortName') ?: aq_site('name')) : get_bloginfo('name');
		$narrative = self::ai_narrative($data, $snapshot, $diff);
		if ($narrative === '') {
			$narrative = self::rules_narrative($data, $snapshot, $diff);
		}
		$table = self::data_table_html($data, $diff);

		$accent = '#c8102e';
		$ink    = '#0d1014';
		ob_start();
		?>
<div style="background:#f4f6f8;padding:24px 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:<?php echo $ink; ?>;">
  <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e6e8eb;">
    <div style="background:<?php echo $ink; ?>;padding:22px 26px;color:#fff;">
      <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9aa3ad;">SEO &amp; AI-Search Report</div>
      <div style="font-size:20px;font-weight:700;margin-top:3px;"><?php echo esc_html((string) $brand); ?></div>
      <div style="font-size:13px;color:#c9cfd6;margin-top:4px;"><?php echo esc_html(wp_date('F j, Y')); ?> · prepared automatically by AutoForge</div>
    </div>
    <div style="padding:24px 26px;font-size:15px;line-height:1.6;">
      <?php echo $narrative; // sanitized in ai_narrative / rules_narrative ?>
    </div>
    <div style="padding:4px 26px 8px;">
      <div style="font-size:13px;font-weight:700;color:<?php echo $accent; ?>;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">The numbers</div>
      <?php echo $table; ?>
    </div>
    <div style="padding:18px 26px 26px;color:#8a94a1;font-size:12px;line-height:1.5;border-top:1px solid #eef1f5;margin-top:8px;">
      This report was generated automatically from live Google &amp; AI-search data (DataForSEO). Manage frequency, recipients and tracked keywords under <strong>AutoForge → SEO Agent</strong>.
    </div>
  </div>
</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function data_table_html(array $data, array $diff): string {
		$rows = '';
		$cell = 'padding:7px 10px;border-bottom:1px solid #eef1f5;font-size:13px;';
		$head = 'padding:7px 10px;border-bottom:2px solid #e6e8eb;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5b6471;text-align:left;';

		$ov = $data['overview'] ?? [];
		$rows .= '<tr><td style="' . $cell . '">Keywords ranking on Google</td><td style="' . $cell . 'text-align:right;font-weight:700;">'
			. (int) ($ov['count'] ?? 0) . self::delta_badge((int) ($diff['ranked_delta'] ?? 0)) . '</td></tr>';
		$rows .= '<tr><td style="' . $cell . '">Est. monthly organic visits</td><td style="' . $cell . 'text-align:right;font-weight:700;">'
			. number_format((float) ($ov['etv'] ?? 0), 1) . '</td></tr>';

		foreach ((array) $data['tracked'] as $kw => $t) {
			$pos = $t['rank'] !== null ? '#' . (int) $t['rank'] : '<span style="color:#a30d25;">not in top 30</span>';
			if (!empty($t['local_rank'])) {
				$pos .= ' <span style="color:#1a8f4f;">· map #' . (int) $t['local_rank'] . '</span>';
			}
			$vol = (int) ($data['volumes'][strtolower((string) $kw)] ?? 0);
			$ai  = !empty($t['ai_overview']) ? ' 🤖' : '';
			$rows .= '<tr><td style="' . $cell . '">' . esc_html((string) $kw) . $ai
				. ($vol ? ' <span style="color:#8a94a1;">(' . number_format($vol) . '/mo)</span>' : '')
				. '</td><td style="' . $cell . 'text-align:right;">' . $pos . '</td></tr>';
		}

		if (is_array($data['ai'])) {
			$mentioned = !empty($data['ai']['mentioned']);
			$rows .= '<tr><td style="' . $cell . '">Named by AI assistant (Perplexity)</td><td style="' . $cell . 'text-align:right;font-weight:700;color:'
				. ($mentioned ? '#1a8f4f' : '#a30d25') . ';">' . ($mentioned ? 'Yes' : 'No') . '</td></tr>';
		}

		return '<table style="width:100%;border-collapse:collapse;"><thead><tr><th style="' . $head . '">Metric</th><th style="' . $head . 'text-align:right;">Now</th></tr></thead><tbody>'
			. $rows . '</tbody></table><div style="font-size:11px;color:#8a94a1;margin-top:6px;">🤖 = Google shows an AI Overview for this search. "map #" = position in the local map pack.</div>';
	}

	private static function delta_badge(int $delta): string {
		if ($delta === 0) {
			return '';
		}
		$up = $delta > 0;
		$col = $up ? '#1a8f4f' : '#a30d25';
		return ' <span style="font-size:11px;color:' . $col . ';">(' . ($up ? '+' : '') . $delta . ')</span>';
	}

	/** OpenAI-written, plain-English narrative + plan. Returns '' if no key / failure. */
	private static function ai_narrative(array $data, array $snapshot, array $diff): string {
		if (!self::openai_ready()) {
			return '';
		}
		$brand = function_exists('aq_site') ? (string) (aq_site('name') ?: '') : get_bloginfo('name');
		$context = wp_json_encode([
			'business'  => $brand,
			'location'  => $data['location'] ?? '',
			'overview'  => $data['overview'] ?? null,
			'tracked'   => $data['tracked'] ?? [],
			'volumes'   => $data['volumes'] ?? [],
			'ranked_top'=> array_slice((array) ($data['ranked'] ?? []), 0, 15),
			'ai_search' => $data['ai'] ?? null,
			'changes'   => $diff,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

		$system = implode("\n", [
			'You are a friendly SEO analyst writing a short email to the owner of a local service business. The owner is NOT technical.',
			'Explain what the data means in plain English (define any term you must use), be honest about weak spots, and end with a clearly prioritized, do-this-next action plan.',
			'Focus on the levers that matter for a local service business: Google Business Profile / map pack, local keyword rankings, on-page content & meta, and being named by AI assistants (AI search).',
			'Rules: no hype, no invented numbers — use only the data provided. Keep it under ~350 words.',
			'Return ONLY HTML using these tags: <p>, <strong>, <h3>, <ul>, <li>. Start with a one-line plain summary in a <p>. Use one <h3>What this means</h3> section and one <h3>Do this next</h3> ordered list of 3–6 concrete steps. No <html>/<body>, no markdown, no backticks.',
		]);

		$payload = [
			'model'      => self::OPENAI_MODEL,
			'max_tokens' => 1200,
			'messages'   => [
				['role' => 'system', 'content' => $system],
				['role' => 'user',   'content' => "Here is this run's data (JSON). Write the email.\n\n" . $context],
			],
		];
		$resp = wp_remote_post(self::OPENAI_URL, [
			'timeout' => 60,
			'headers' => ['content-type' => 'application/json', 'Authorization' => 'Bearer ' . AQ_Assistant::api_key()],
			'body'    => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
		if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
			return '';
		}
		$d = json_decode((string) wp_remote_retrieve_body($resp), true);
		$html = (string) ($d['choices'][0]['message']['content'] ?? '');
		$html = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $html));
		if ($html === '') {
			return '';
		}
		return wp_kses($html, [
			'p' => [], 'strong' => [], 'em' => [], 'h3' => [], 'ul' => [], 'ol' => [], 'li' => [], 'br' => [],
		]);
	}

	/** Deterministic fallback narrative when no OpenAI key is configured. */
	private static function rules_narrative(array $data, array $snapshot, array $diff): string {
		$count = (int) ($data['overview']['count'] ?? 0);
		$notRanking = [];
		foreach ((array) $data['tracked'] as $kw => $t) {
			if (($t['rank'] ?? null) === null) {
				$notRanking[] = $kw;
			}
		}
		$aiNamed = is_array($data['ai']) ? !empty($data['ai']['mentioned']) : null;

		$p = '<p><strong>Summary:</strong> ';
		if ($count === 0) {
			$p .= 'Your site is not yet ranking for keywords on Google, so almost no one is finding you through search. The good news: every step below is a chance to move up.';
		} else {
			$p .= 'Your site ranks for about ' . $count . ' keyword' . ($count === 1 ? '' : 's') . ' on Google. Below is where you stand and what to do next.';
		}
		$p .= '</p>';

		$p .= '<h3>What this means</h3><ul>';
		if ($notRanking) {
			$p .= '<li>You are <strong>not in the top 30</strong> Google results for: ' . esc_html(implode(', ', array_slice($notRanking, 0, 8))) . '. These are searches your customers use.</li>';
		}
		if ($aiNamed === false) {
			$p .= '<li>When an AI assistant (Perplexity) was asked who the best local inspectors are, <strong>your business was not named</strong> — it listed directories and competitors instead.</li>';
		} elseif ($aiNamed === true) {
			$p .= '<li>Good news — an AI assistant <strong>named your business</strong> when asked about local inspectors.</li>';
		}
		$p .= '<li>For a local service business, the biggest wins come from a complete Google Business Profile, reviews, local landing pages, and clear on-page content.</li>';
		$p .= '</ul>';

		$p .= '<h3>Do this next</h3><ul>'
			. '<li>Confirm the site is indexable (not set to “discourage search engines”) and submitted in Google Search Console.</li>'
			. '<li>Claim &amp; fully complete your Google Business Profile; ask recent clients for reviews.</li>'
			. '<li>Make sure every page has a unique title tag and meta description with your town + service.</li>'
			. '<li>Add/expand FAQ and blog content answering real buyer questions (inspection cost, what’s included, local rules).</li>'
			. '<li>Add LocalBusiness structured data so Google and AI tools can read your name, area, and services.</li>'
			. '</ul>';

		return $p; // all literals / escaped above
	}

	private static function send_email(string $html, string $subject, string $recipients): bool {
		$to = array_filter(array_map('trim', preg_split('/[,;]+/', $recipients)));
		$to = array_filter($to, 'is_email');
		if (!$to) {
			$to = [(string) get_option('admin_email')];
		}
		$type = static function () { return 'text/html'; };
		add_filter('wp_mail_content_type', $type);
		$ok = wp_mail($to, $subject, $html);
		remove_filter('wp_mail_content_type', $type);
		return (bool) $ok;
	}

	/* ============================================================
	 * Admin screen
	 * ============================================================ */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'SEO Agent', 'SEO Agent', self::CAP, 'aq-seo-agent', [__CLASS__, 'render']);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$o        = self::opts();
		$dfs      = self::dataforseo_ready();
		$ai       = self::openai_ready();
		$last     = get_option(self::LAST, []);
		$next     = self::next_run_ts();
		$int_url  = admin_url('admin.php?page=aq-integrations');

		AQ_Admin_Hub::open('SEO Agent', 'An automatic assistant that checks your Google and AI-search visibility and emails you a plain-English report with a plan.', 'aq-seo-agent');
		?>
		<style>
			.aq-sa-field { margin-bottom:16px; max-width:640px; }
			.aq-sa-field label.aq-sa-lbl { display:block; font-weight:600; color:#0d1014; margin-bottom:5px; }
			.aq-sa-field input[type=text], .aq-sa-field input[type=email], .aq-sa-field select, .aq-sa-field textarea {
				width:100%; padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; font-family:inherit; }
			.aq-sa-field textarea { min-height:120px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; line-height:1.6; }
			.aq-sa-hint { font-size:12px; color:#5b6471; margin:5px 0 0; }
			.aq-sa-check { display:flex; align-items:flex-start; gap:9px; margin:10px 0; font-size:13px; max-width:640px; }
			.aq-sa-check input { margin-top:2px; }
			.aq-sa-status { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:4px; }
		</style>

		<?php if (isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>SEO Agent settings saved.</p></div>
		<?php endif; ?>
		<?php if (!$dfs) : ?>
			<div class="notice notice-warning inline"><p><strong>Almost there:</strong> the agent needs your DataForSEO login under <a href="<?php echo esc_url($int_url); ?>">AutoForge → Integrations</a> before it can run.</p></div>
		<?php endif; ?>
		<?php if (!$ai) : ?>
			<div class="notice notice-info inline"><p>No OpenAI key found — reports will still send using a clear built-in summary. Add a key under <a href="<?php echo esc_url($int_url); ?>">Integrations</a> for a friendlier, AI-written write-up.</p></div>
		<?php endif; ?>

		<div class="aq-cards" style="margin-bottom:6px;">
			<?php
			self::card('Agent', $o['enabled'] ? 'On' : 'Off', $o['enabled'] ? self::freq_label((string) $o['frequency']) : 'Currently paused');
			self::card('Next report', $next ? wp_date('M j', $next) : '—', $next ? wp_date('g:i a', $next) : 'Turn the agent on to schedule');
			self::card('Last run', !empty($o['last_run']) ? human_time_diff((int) $o['last_run']) . ' ago' : 'Never', !empty($o['last_status']) ? self::status_label((string) $o['last_status']) : 'No runs yet');
			self::card('Data source', $dfs ? 'Connected' : 'Not set', 'DataForSEO');
			?>
		</div>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
			<input type="hidden" name="action" value="aq_seo_agent_save">
			<?php wp_nonce_field('aq_seo_agent_save'); ?>

			<div class="aq-panel">
				<h2 style="margin-top:0;">Schedule &amp; delivery</h2>
				<label class="aq-sa-check"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($o['enabled'])); ?>>
					<span><strong>Run automatically.</strong> When on, the agent checks your search visibility and emails the report on the schedule below.</span></label>

				<div class="aq-sa-field">
					<label class="aq-sa-lbl" for="aq-sa-freq">How often</label>
					<select name="frequency" id="aq-sa-freq">
						<option value="weekly" <?php selected($o['frequency'], 'weekly'); ?>>Once a week</option>
						<option value="biweekly" <?php selected($o['frequency'], 'biweekly'); ?>>Every 2 weeks (recommended)</option>
						<option value="monthly" <?php selected($o['frequency'], 'monthly'); ?>>Once a month</option>
					</select>
					<p class="aq-sa-hint">Local rankings move slowly, so <strong>every 2 weeks</strong> is the sweet spot — frequent enough to catch changes, without wasting API credits on noise. Choose weekly only during an active push.</p>
				</div>

				<div class="aq-sa-field">
					<label class="aq-sa-lbl" for="aq-sa-recip">Email the report to</label>
					<input type="text" id="aq-sa-recip" name="recipients" value="<?php echo esc_attr((string) $o['recipients']); ?>" placeholder="you@example.com, partner@example.com">
					<p class="aq-sa-hint">One or more addresses, separated by commas. Sent through WordPress's own email.</p>
				</div>
			</div>

			<div class="aq-panel">
				<h2 style="margin-top:0;">What to check</h2>
				<div class="aq-sa-field">
					<label class="aq-sa-lbl" for="aq-sa-loc">Your market (region)</label>
					<input type="text" id="aq-sa-loc" name="location" value="<?php echo esc_attr((string) $o['location']); ?>" placeholder="Massachusetts,United States">
					<p class="aq-sa-hint">Where your customers search. Use <code>State,United States</code> (e.g. <code>Massachusetts,United States</code>) for local results.</p>
				</div>
				<div class="aq-sa-field">
					<label class="aq-sa-lbl" for="aq-sa-kw">Keywords to track</label>
					<textarea id="aq-sa-kw" name="keywords" spellcheck="false"><?php echo esc_textarea(implode("\n", (array) $o['keywords'])); ?></textarea>
					<p class="aq-sa-hint">One search phrase per line — the things customers type into Google. Up to <?php echo (int) self::MAX_TRACKED; ?> are checked each run.</p>
				</div>
				<label class="aq-sa-check"><input type="checkbox" name="track_serp" value="1" <?php checked(!empty($o['track_serp'])); ?>>
					<span><strong>Check Google rankings</strong> for those keywords (and whether a map pack / AI Overview appears).</span></label>
				<label class="aq-sa-check"><input type="checkbox" name="track_ai" value="1" <?php checked(!empty($o['track_ai'])); ?>>
					<span><strong>Check AI-search visibility</strong> — asks Perplexity a real buyer question and reports whether your business is named.</span></label>
				<div class="aq-sa-field" style="margin-top:10px;">
					<label class="aq-sa-lbl" for="aq-sa-aip">AI-search question</label>
					<input type="text" id="aq-sa-aip" name="ai_prompt" value="<?php echo esc_attr((string) $o['ai_prompt']); ?>">
					<p class="aq-sa-hint">The question the AI assistant is asked. Keep it like a real customer would phrase it.</p>
				</div>
			</div>

			<p>
				<?php submit_button('Save settings', 'primary', 'submit', false); ?>
				<button type="button" class="button" id="aq-sa-run" style="margin-left:8px;" <?php disabled(!$dfs); ?>>Send a report now</button>
				<span id="aq-sa-run-msg" style="margin-left:10px;font-size:13px;color:#5b6471;"></span>
			</p>
			<p class="aq-sa-hint">“Send a report now” runs a live check immediately — it can take up to a minute. Use it to preview what the scheduled email will look like.</p>
		</form>

		<?php if (!empty($last['html'])) : ?>
			<div class="aq-panel">
				<h2 style="margin-top:0;">Last report <span class="aq-pill"><?php echo esc_html(!empty($last['time']) ? wp_date('M j, Y · g:i a', (int) $last['time']) : ''); ?></span></h2>
				<div style="border:1px solid #e6e8eb;border-radius:12px;overflow:hidden;max-height:560px;overflow-y:auto;">
					<?php echo wp_kses_post((string) $last['html']); ?>
				</div>
			</div>
		<?php endif; ?>

		<script>
		(function () {
			var url = <?php echo wp_json_encode(esc_url_raw(rest_url('aq/v1/seo-agent/run'))); ?>;
			var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
			var btn = document.getElementById('aq-sa-run'), msg = document.getElementById('aq-sa-run-msg');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				btn.disabled = true; msg.style.color = '#5b6471'; msg.textContent = 'Running a live check… this can take up to a minute.';
				fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						btn.disabled = false;
						var ok = d && d.ok;
						msg.style.color = ok ? '#1a8f4f' : '#a30d25';
						msg.textContent = (ok ? '✓ ' : '✕ ') + ((d && d.message) || (ok ? 'Done.' : 'Failed.'));
						if (ok) { setTimeout(function () { location.reload(); }, 1200); }
					})
					.catch(function (e) { btn.disabled = false; msg.style.color = '#a30d25'; msg.textContent = '✕ ' + e.message; });
			});
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	/** Small status card matching AQ_Admin_Hub's .aq-card markup. */
	private static function card(string $label, string $num, string $sub = ''): void {
		echo '<div class="aq-card"><p class="aq-card__label">' . esc_html($label) . '</p><div class="aq-card__num">' . esc_html($num) . '</div>';
		if ($sub !== '') {
			echo '<div class="aq-card__sub">' . esc_html($sub) . '</div>';
		}
		echo '</div>';
	}

	private static function freq_label(string $f): string {
		return ['weekly' => 'Weekly', 'biweekly' => 'Every 2 weeks', 'monthly' => 'Monthly'][$f] ?? 'Every 2 weeks';
	}

	private static function status_label(string $s): string {
		return [
			'sent'            => 'Report emailed',
			'built_not_sent'  => 'Built, email failed',
			'no_credentials'  => 'Missing DataForSEO login',
		][$s] ?? ucfirst(str_replace('_', ' ', $s));
	}

	public static function save_settings(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_seo_agent_save')) {
			wp_die('Not allowed.');
		}
		$o = self::opts();
		$o['enabled']    = !empty($_POST['enabled']);
		$freq            = isset($_POST['frequency']) ? sanitize_key((string) wp_unslash($_POST['frequency'])) : 'biweekly';
		$o['frequency']  = in_array($freq, ['weekly', 'biweekly', 'monthly'], true) ? $freq : 'biweekly';
		$o['recipients'] = isset($_POST['recipients']) ? sanitize_text_field((string) wp_unslash($_POST['recipients'])) : '';
		$o['location']   = isset($_POST['location']) ? sanitize_text_field((string) wp_unslash($_POST['location'])) : 'United States';
		$o['ai_prompt']  = isset($_POST['ai_prompt']) ? sanitize_text_field((string) wp_unslash($_POST['ai_prompt'])) : self::default_ai_prompt();
		$o['track_serp'] = !empty($_POST['track_serp']);
		$o['track_ai']   = !empty($_POST['track_ai']);

		$raw = isset($_POST['keywords']) ? (string) wp_unslash($_POST['keywords']) : '';
		$kw  = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)));
		$kw  = array_map('sanitize_text_field', array_values(array_unique($kw)));
		$o['keywords'] = array_slice($kw, 0, self::MAX_TRACKED) ?: self::default_keywords();

		update_option(self::OPTION, $o, false);
		// Re-align the cron schedule with the saved settings.
		wp_clear_scheduled_hook(self::HOOK);
		self::maybe_schedule();

		wp_safe_redirect(add_query_arg(['page' => 'aq-seo-agent', 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	/* ============================================================
	 * REST — "Send a report now"
	 * ============================================================ */

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/seo-agent/run', [
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can(self::CAP); },
			'callback'            => [__CLASS__, 'rest_run'],
		]);
	}

	public static function rest_run(WP_REST_Request $req) {
		if (!self::dataforseo_ready()) {
			return rest_ensure_response(['ok' => false, 'message' => 'Add your DataForSEO login under AutoForge → Integrations first.']);
		}
		@set_time_limit(180);
		$res = self::run_scan(true);
		return rest_ensure_response($res);
	}
}
