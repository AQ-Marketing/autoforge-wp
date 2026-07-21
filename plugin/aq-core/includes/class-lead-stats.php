<?php
/**
 * AQ_Lead_Stats — form analytics for the AutoForge lead pipeline.
 *
 * Answers four questions for every AutoForge site, with as little machinery as
 * the job allows:
 *   1. Submissions over time      — daily counters (this class).
 *   2. Source / service / page    — tallied from the stored aq_lead records
 *                                    (AQ_Lead_Store), so no extra tracking.
 *   3. Delivery & spam health     — delivery flags on the records + block
 *                                    counters bumped from the submit handler.
 *   4. Conversion rate            — form VIEWS counted by a front-end beacon
 *                                    (works on cached pages) vs submissions.
 *
 * Storage model — a single non-autoloaded option keyed by day:
 *   aq_lead_stats = [ 'YYYY-MM-DD' => ['views'=>int,'submits'=>int,
 *                                      'captcha'=>int,'honeypot'=>int,'rate'=>int], ... ]
 * One row per day, pruned past RETAIN_DAYS, so the option stays tiny. These
 * counters are deliberately NOT tied to lead retention — the time series and
 * conversion rate survive even after old aq_lead records are purged. (The
 * source/service/page breakdowns DO come from records, so those only span what
 * retention keeps.)
 *
 * The counters use a read-modify-write; on the low-traffic small-business sites
 * this engine runs, the odd lost increment under concurrency is an acceptable
 * trade for zero schema/migration. Analytics tolerate approximate counts.
 *
 * @package aq-core
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Lead_Stats {

	const OPTION = 'aq_lead_stats';
	const CAP    = 'manage_options';
	const SLUG   = 'aq-form-analytics';

	/** Metrics tracked in the daily counter store. */
	const METRICS = ['views', 'submits', 'captcha', 'honeypot', 'rate'];

	/** How many days of daily counters to keep (older days are pruned on write). */
	const RETAIN_DAYS = 400;

	public static function register(): void {
		// Counters.
		add_action('aq_lead_captured', [__CLASS__, 'on_submit'], 10, 0);
		add_action('aq_lead_blocked', [__CLASS__, 'on_blocked'], 10, 1);

		// Front-end view beacon (conversion denominator).
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		add_action('wp_footer', [__CLASS__, 'print_view_beacon'], 7);

		// Admin dashboard.
		add_action('admin_menu', [__CLASS__, 'menu'], 26);
	}

	public static function enabled(): bool {
		return (bool) apply_filters('aq_lead_stats_enabled', true);
	}

	/* ---------------- counters ---------------- */

	private static function today(): string {
		return function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
	}

	/** Increment one metric for today by $n (best-effort, pruned). */
	public static function bump(string $metric, int $n = 1): void {
		if (!self::enabled() || !in_array($metric, self::METRICS, true) || $n < 1) {
			return;
		}
		$all = get_option(self::OPTION, []);
		if (!is_array($all)) { $all = []; }
		$day = self::today();
		if (!isset($all[$day]) || !is_array($all[$day])) { $all[$day] = []; }
		$all[$day][$metric] = (int) ($all[$day][$metric] ?? 0) + $n;

		// Prune days older than the retention window (keeps the option small).
		$cutoff = gmdate('Y-m-d', time() - self::RETAIN_DAYS * DAY_IN_SECONDS);
		foreach (array_keys($all) as $d) {
			if (!is_string($d) || $d < $cutoff) { unset($all[$d]); }
		}
		update_option(self::OPTION, $all, false);
	}

	public static function on_submit(): void {
		self::bump('submits');
	}

	public static function on_blocked(string $reason): void {
		$map = ['captcha' => 'captcha', 'honeypot' => 'honeypot', 'rate' => 'rate'];
		if (isset($map[$reason])) {
			self::bump($map[$reason]);
		}
	}

	/* ---------------- view beacon ---------------- */

	public static function rest_routes(): void {
		register_rest_route('aqm/v1', '/fv', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // public; aggregate-only, no data stored
			'callback'            => [__CLASS__, 'rest_view'],
		]);
	}

	/**
	 * Count one form view. Aggregate only — we store no visitor data, just bump a
	 * daily counter. Obvious bots (by UA) and logged-in users are skipped so the
	 * conversion denominator reflects real prospective visitors.
	 */
	public static function rest_view(WP_REST_Request $req) {
		if (is_user_logged_in()) {
			return new WP_REST_Response(['ok' => true, 'skipped' => 'user'], 200);
		}
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ($ua === '' || preg_match('/bot|crawl|spider|slurp|bingpreview|headless|monitor|pingdom|lighthouse|gtmetrix/i', $ua)) {
			return new WP_REST_Response(['ok' => true, 'skipped' => 'bot'], 200);
		}
		self::bump('views');
		return new WP_REST_Response(['ok' => true], 200);
	}

	/**
	 * Footer beacon: when a lead form is present, fire ONE view ping per page load.
	 * Uses navigator.sendBeacon so it never blocks the page and survives unload.
	 * Runs client-side, so it counts views even when the HTML was served from
	 * cache. Skipped for logged-in users (admin previews shouldn't inflate views).
	 *
	 * Detects our lead forms broadly: the engine's own forms carry data-aq-lead;
	 * any other/bespoke lead form can opt in with a data-aq-form marker or by
	 * posting to the aqm/v1/contact endpoint.
	 */
	public static function print_view_beacon(): void {
		if (is_user_logged_in() || !self::enabled()) {
			return;
		}
		$url = esc_url_raw(rest_url('aqm/v1/fv'));
		?>
<script>(function(){
try{
var sel='form[data-aq-lead],form[data-aq-form],form[action*="aqm/v1/contact"],form[data-endpoint*="aqm/v1/contact"]';
function ping(){
if(!document.querySelector(sel))return;
var u=<?php echo wp_json_encode($url); ?>;
try{
if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([],{type:'text/plain'}));}
else{fetch(u,{method:'POST',keepalive:true,credentials:'omit'});}
}catch(e){}
}
if(document.readyState!=='loading')ping();else document.addEventListener('DOMContentLoaded',ping);
}catch(e){}
})();</script>
		<?php
	}

	/* ---------------- queries ---------------- */

	/** All daily rows, sorted ascending by date. */
	private static function all_days(): array {
		$all = get_option(self::OPTION, []);
		if (!is_array($all)) { $all = []; }
		ksort($all);
		return $all;
	}

	/** Per-day series for the last $days days: [ ['date'=>'YYYY-MM-DD', metric=>int...], ... ]. */
	public static function series(int $days): array {
		$all = self::all_days();
		$out = [];
		for ($i = $days - 1; $i >= 0; $i--) {
			$d   = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
			$row = is_array($all[$d] ?? null) ? $all[$d] : [];
			$e   = ['date' => $d];
			foreach (self::METRICS as $m) { $e[$m] = (int) ($row[$m] ?? 0); }
			$out[] = $e;
		}
		return $out;
	}

	/** Summed totals across a series. */
	public static function totals(array $series): array {
		$t = array_fill_keys(self::METRICS, 0);
		foreach ($series as $row) {
			foreach (self::METRICS as $m) { $t[$m] += (int) ($row[$m] ?? 0); }
		}
		return $t;
	}

	/**
	 * Tally stored leads over the last $days days into breakdowns + delivery.
	 * Returns ['count'=>int,'capped'=>bool,'source'=>[], 'service'=>[], 'page'=>[],
	 *          'email_ok'=>int,'ghl_ok'=>int].
	 * Only spans what lead retention has kept.
	 */
	public static function lead_breakdowns(int $days, int $cap = 3000): array {
		$since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
		$ids = get_posts([
			'post_type'        => defined('AQ_Lead_Store::CPT') ? AQ_Lead_Store::CPT : 'aq_lead',
			'post_status'      => 'any',
			'date_query'       => [['column' => 'post_date', 'after' => $since]],
			'numberposts'      => $cap + 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'orderby'          => 'date',
			'order'            => 'DESC',
		]);
		$capped = count($ids) > $cap;
		if ($capped) { $ids = array_slice($ids, 0, $cap); }
		// Prime the meta cache so the get_post_meta calls below are in-memory.
		if ($ids) { update_meta_cache('post', $ids); }

		$source = []; $service = []; $page = [];
		$email_ok = 0; $ghl_ok = 0;
		foreach ($ids as $id) {
			$svc = (string) get_post_meta($id, '_aql_service', true);
			if ($svc !== '') { $service[$svc] = ($service[$svc] ?? 0) + 1; }

			$trk = json_decode((string) get_post_meta($id, '_aql_tracking', true), true);
			$us  = is_array($trk) ? trim((string) ($trk['utm_source'] ?? '')) : '';
			if ($us === '') {
				$ref = (string) get_post_meta($id, '_aql_referer', true);
				$us  = $ref !== '' ? (string) (wp_parse_url($ref, PHP_URL_HOST) ?: 'direct') : 'direct';
			}
			$source[$us] = ($source[$us] ?? 0) + 1;

			$ref = (string) get_post_meta($id, '_aql_referer', true);
			if ($ref !== '') {
				$path = (string) (wp_parse_url($ref, PHP_URL_PATH) ?: '/');
				$page[$path] = ($page[$path] ?? 0) + 1;
			}

			if ((int) get_post_meta($id, '_aql_delivered_email', true) === 1) { $email_ok++; }
			if ((int) get_post_meta($id, '_aql_delivered_ghl', true) === 1) { $ghl_ok++; }
		}
		arsort($source); arsort($service); arsort($page);
		return [
			'count'    => count($ids),
			'capped'   => $capped,
			'source'   => $source,
			'service'  => $service,
			'page'     => $page,
			'email_ok' => $email_ok,
			'ghl_ok'   => $ghl_ok,
		];
	}

	/* ---------------- admin dashboard ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Form Analytics', 'Analytics', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	private static function pct(int $num, int $den): string {
		if ($den <= 0) { return '—'; }
		return number_format(100 * $num / $den, 1) . '%';
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$ranges = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
		$days   = isset($_GET['range']) ? (int) $_GET['range'] : 30;
		if (!isset($ranges[$days])) { $days = 30; }

		$series = self::series($days);
		$tot    = self::totals($series);
		$bd     = self::lead_breakdowns($days);

		$conv = self::pct($tot['submits'], $tot['views']);
		$deliv_den = $bd['count'];
		$email_rate = self::pct($bd['email_ok'], $deliv_den);
		$ghl_rate   = self::pct($bd['ghl_ok'], $deliv_den);
		$blocked = $tot['captcha'] + $tot['honeypot'] + $tot['rate'];

		if (class_exists('AQ_Admin_Hub')) {
			AQ_Admin_Hub::open('Form Analytics', 'How your website forms are performing — submissions, sources, delivery and conversion.', self::SLUG);
		} else {
			echo '<div class="wrap"><h1>Form Analytics</h1>';
		}
		?>
		<style>
			.aqa-tiles{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 22px}
			.aqa-tile{background:#fff;border:1px solid #dcdfe3;border-radius:10px;padding:16px 18px;min-width:150px;flex:1}
			.aqa-tile .n{font-size:26px;font-weight:800;color:#0d1014;line-height:1.1}
			.aqa-tile .l{font-size:12px;color:#5b6471;margin-top:4px;text-transform:uppercase;letter-spacing:.05em;font-weight:600}
			.aqa-tile .s{font-size:12px;color:#5b6471;margin-top:6px}
			.aqa-card{background:#fff;border:1px solid #dcdfe3;border-radius:10px;padding:18px 20px;margin:0 0 18px;max-width:920px}
			.aqa-card h2{margin:0 0 14px;font-size:15px}
			.aqa-grid{display:flex;gap:18px;flex-wrap:wrap}
			.aqa-grid .aqa-card{flex:1;min-width:280px}
			.aqa-tbl{width:100%;border-collapse:collapse}
			.aqa-tbl th,.aqa-tbl td{text-align:left;padding:7px 8px;border-bottom:1px solid #eef0f2;font-size:13px}
			.aqa-tbl th{color:#5b6471;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
			.aqa-tbl td.n{text-align:right;font-variant-numeric:tabular-nums;width:64px;color:#0d1014;font-weight:600}
			.aqa-bar{height:6px;border-radius:3px;background:#c8102e;opacity:.85}
			.aqa-range a{margin-right:8px;text-decoration:none}
			.aqa-range a.on{font-weight:700;text-decoration:underline}
			.aqa-empty{color:#5b6471;font-size:13px;padding:8px 0}
		</style>

		<p class="aqa-range">
			<?php foreach ($ranges as $d => $label) : ?>
				<a class="<?php echo $d === $days ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'range' => $d], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
			<?php endforeach; ?>
		</p>

		<div class="aqa-tiles">
			<div class="aqa-tile"><div class="n"><?php echo (int) $tot['submits']; ?></div><div class="l">Submissions</div></div>
			<div class="aqa-tile"><div class="n"><?php echo (int) $tot['views']; ?></div><div class="l">Form views</div></div>
			<div class="aqa-tile"><div class="n"><?php echo esc_html($conv); ?></div><div class="l">Conversion</div><div class="s">submissions ÷ views</div></div>
			<div class="aqa-tile"><div class="n"><?php echo esc_html($email_rate); ?></div><div class="l">Email delivered</div><div class="s">CRM <?php echo esc_html($ghl_rate); ?></div></div>
			<div class="aqa-tile"><div class="n"><?php echo (int) $blocked; ?></div><div class="l">Spam blocked</div><div class="s"><?php echo (int) $tot['captcha']; ?> captcha · <?php echo (int) $tot['honeypot']; ?> trap · <?php echo (int) $tot['rate']; ?> rate</div></div>
		</div>

		<div class="aqa-card">
			<h2>Submissions &amp; views — <?php echo esc_html(strtolower($ranges[$days])); ?></h2>
			<?php echo self::chart_svg($series); ?>
		</div>

		<div class="aqa-grid">
			<div class="aqa-card">
				<h2>Top sources</h2>
				<?php echo self::breakdown_table($bd['source'], 'Source'); ?>
			</div>
			<div class="aqa-card">
				<h2>Services requested</h2>
				<?php echo self::breakdown_table($bd['service'], 'Service'); ?>
			</div>
			<div class="aqa-card">
				<h2>Pages that convert</h2>
				<?php echo self::breakdown_table($bd['page'], 'Page'); ?>
			</div>
		</div>

		<p class="aqa-empty">
			Submissions/views/spam are counted from the day this feature went live and kept for <?php echo (int) self::RETAIN_DAYS; ?> days. Source/service/page tallies come from stored submissions, so they span only what your Submissions retention setting keeps<?php echo $bd['capped'] ? ' (showing the most recent ' . number_format(3000) . ' in range)' : ''; ?>.
		</p>
		<?php
		if (class_exists('AQ_Admin_Hub')) { AQ_Admin_Hub::close(); } else { echo '</div>'; }
	}

	/** Simple dependency-free SVG: submissions bars + a views line, over the range. */
	private static function chart_svg(array $series): string {
		$n = count($series);
		if ($n === 0) { return '<p class="aqa-empty">No data yet.</p>'; }
		$maxV = 1; $maxS = 1;
		foreach ($series as $r) {
			$maxV = max($maxV, (int) $r['views']);
			$maxS = max($maxS, (int) $r['submits']);
		}
		$top = max($maxV, $maxS);
		$W = 880; $H = 200; $padB = 22; $padT = 8; $padL = 4;
		$plotH = $H - $padB - $padT;
		$bw = ($W - $padL * 2) / $n;
		$svg  = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" height="' . $H . '" role="img" style="max-width:100%;font-family:inherit">';
		// submissions bars
		foreach ($series as $i => $r) {
			$s = (int) $r['submits'];
			$h = $top > 0 ? ($s / $top) * $plotH : 0;
			$x = $padL + $i * $bw;
			$y = $padT + $plotH - $h;
			$svg .= '<rect x="' . round($x + $bw * 0.18, 1) . '" y="' . round($y, 1) . '" width="' . round($bw * 0.64, 1) . '" height="' . round($h, 1) . '" rx="1.5" fill="#c8102e" opacity="0.85"><title>' . esc_attr($r['date'] . ': ' . $s . ' submissions') . '</title></rect>';
		}
		// views line
		$pts = [];
		foreach ($series as $i => $r) {
			$v = (int) $r['views'];
			$x = $padL + $i * $bw + $bw / 2;
			$y = $padT + $plotH - ($top > 0 ? ($v / $top) * $plotH : 0);
			$pts[] = round($x, 1) . ',' . round($y, 1);
		}
		$svg .= '<polyline fill="none" stroke="#0a0c0f" stroke-width="1.5" stroke-opacity="0.55" points="' . implode(' ', $pts) . '" />';
		// baseline + end labels
		$svg .= '<line x1="' . $padL . '" y1="' . ($padT + $plotH) . '" x2="' . ($W - $padL) . '" y2="' . ($padT + $plotH) . '" stroke="#e2e5e9" stroke-width="1" />';
		$svg .= '<text x="' . $padL . '" y="' . ($H - 6) . '" font-size="10" fill="#8a929c">' . esc_html($series[0]['date']) . '</text>';
		$svg .= '<text x="' . ($W - $padL) . '" y="' . ($H - 6) . '" font-size="10" fill="#8a929c" text-anchor="end">' . esc_html($series[$n - 1]['date']) . '</text>';
		$svg .= '</svg>';
		$svg .= '<p class="aqa-empty" style="margin-top:4px"><span style="display:inline-block;width:10px;height:10px;background:#c8102e;opacity:.85;border-radius:2px;vertical-align:middle"></span> submissions &nbsp; <span style="display:inline-block;width:14px;height:0;border-top:2px solid #0a0c0f;opacity:.55;vertical-align:middle"></span> form views</p>';
		return $svg;
	}

	/** Top-N breakdown table with proportional bars. */
	private static function breakdown_table(array $data, string $label, int $topN = 8): string {
		if (!$data) {
			return '<p class="aqa-empty">No submissions in this range yet.</p>';
		}
		$data = array_slice($data, 0, $topN, true);
		$max  = max($data);
		$html = '<table class="aqa-tbl"><thead><tr><th>' . esc_html($label) . '</th><th class="n">Leads</th></tr></thead><tbody>';
		foreach ($data as $k => $v) {
			$w = $max > 0 ? max(3, (int) round(100 * $v / $max)) : 0;
			$disp = $k === '' ? '(none)' : $k;
			$html .= '<tr><td>' . esc_html($disp)
				. '<div class="aqa-bar" style="width:' . $w . '%;margin-top:4px"></div></td>'
				. '<td class="n">' . (int) $v . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}
}
