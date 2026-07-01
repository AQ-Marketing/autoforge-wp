<?php
/**
 * AutoForge — Performance screen (tab 'aq-performance').
 *
 * Shows caching + optimization status (Boost performance module), object cache,
 * indexing posture (read-only — driven entirely by the host's environment type,
 * see aq_noindex_active() in aq-core.php), PHP/WP versions, a cache-clear
 * control, and an optional Google PageSpeed Insights (PSI) lab-metrics panel.
 *
 * Design notes:
 *  - Self-contained: no third-party SaaS beyond the user's own optional PSI key.
 *  - SQLite-safe: only get_option/update_option, no raw SQL.
 *  - The PSI API key is WRITE-ONLY from the browser's perspective. It is read
 *    from the AQ_PSI_KEY wp-config constant first (shared/locked across sites),
 *    else the wp_option 'aq_psi_key'; NEVER echoed back or localized to JS.
 *    All Google requests are made server-side via wp_remote_get.
 *  - All REST routes require manage_options + the WP REST nonce (X-WP-Nonce).
 *  - Vanilla JS only (inline <script>); shared chrome from AQ_Admin_Hub.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Performance {

	const CAP        = 'manage_options';
	const OPT_KEY    = 'aq_psi_key';        // stores the Google PSI API key (server-side only)
	const PSI_TTL    = 12 * HOUR_IN_SECONDS; // transient cache lifetime for PSI results

	/** PSI API key — the AQ_PSI_KEY wp-config constant first (shared/locked across sites), else the per-site option. */
	public static function psi_key(): string {
		return (defined('AQ_PSI_KEY') && AQ_PSI_KEY) ? (string) AQ_PSI_KEY : (string) get_option(self::OPT_KEY, '');
	}

	/** True when the PSI key is locked by the AQ_PSI_KEY wp-config constant. */
	public static function psi_locked(): bool {
		return defined('AQ_PSI_KEY') && (bool) AQ_PSI_KEY;
	}

	/* ---------------- registration ---------------- */

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function rest_routes(): void {
		$can = function () {
			return current_user_can(self::CAP);
		};

		register_rest_route('aq/v1', '/perf/clear-cache', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'rest_clear_cache'],
			'permission_callback' => $can,
		]);

		register_rest_route('aq/v1', '/perf/psi-key', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'rest_save_psi_key'],
			'permission_callback' => $can,
			'args'                => [
				'key' => ['type' => 'string', 'required' => false],
			],
		]);

		register_rest_route('aq/v1', '/perf/pagespeed', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'rest_pagespeed'],
			'permission_callback' => $can,
			'args'                => [
				'strategy' => ['type' => 'string', 'required' => false],
				'url'      => ['type' => 'string', 'required' => false],
				'fresh'    => ['type' => 'string', 'required' => false],
			],
		]);

	}

	/* ---------------- REST handlers ---------------- */

	/** POST /perf/clear-cache — clears the Boost caches if available. */
	public static function rest_clear_cache(WP_REST_Request $req) {
		$did = [];
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
			$did[] = 'page cache';
		}
		if (function_exists('rocket_clean_minify')) {
			rocket_clean_minify();
			$did[] = 'minified assets';
		}
		// Fall back to the WP object cache flush so the button always does something.
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
			$did[] = 'object cache';
		}

		if (!$did) {
			return new WP_REST_Response([
				'ok'      => false,
				'message' => 'No cache layer was available to clear.',
			], 200);
		}

		return new WP_REST_Response([
			'ok'      => true,
			'message' => 'Cleared: ' . implode(', ', $did) . '.',
		], 200);
	}

	/**
	 * POST /perf/psi-key — saves (or clears) the Google PSI API key.
	 * The key is stored server-side only. The response never echoes the value.
	 */
	public static function rest_save_psi_key(WP_REST_Request $req) {
		if (self::psi_locked()) {
			return new WP_REST_Response(['ok' => false, 'locked' => true, 'message' => 'PageSpeed key is locked by the AQ_PSI_KEY constant in wp-config.php — remove that line to edit it here.'], 200);
		}
		$raw = $req->get_param('key');
		$key = is_string($raw) ? trim($raw) : '';
		// Google API keys are alphanumeric with - and _; strip anything else defensively.
		$key = preg_replace('/[^A-Za-z0-9_\-]/', '', $key);

		if ($key === '') {
			delete_option(self::OPT_KEY);
			return new WP_REST_Response([
				'ok'      => true,
				'hasKey'  => false,
				'message' => 'PageSpeed API key cleared.',
			], 200);
		}

		update_option(self::OPT_KEY, $key, false); // not autoloaded
		return new WP_REST_Response([
			'ok'      => true,
			'hasKey'  => true,
			'message' => 'PageSpeed API key saved.',
		], 200);
	}

	/**
	 * GET /perf/pagespeed?strategy=mobile&url=<home> — runs PSI server-side.
	 * Returns a normalized {performance, metrics{}} payload. Cached 12h per
	 * url+strategy unless ?fresh=1 is passed.
	 */
	public static function rest_pagespeed(WP_REST_Request $req) {
		$key = self::psi_key();
		if ($key === '') {
			return new WP_REST_Response([
				'ok'      => false,
				'message' => 'No PageSpeed API key is configured.',
			], 200);
		}

		$strategy = strtolower((string) $req->get_param('strategy'));
		if ($strategy !== 'desktop') {
			$strategy = 'mobile';
		}

		$home = home_url('/');
		$url  = (string) $req->get_param('url');
		if ($url === '') {
			$url = $home;
		}
		// Only allow testing this site's own host over http(s). Compare the parsed
		// host exactly — a string prefix check would let a suffix domain like
		// "localhost.evil.com" slip past.
		$parts      = wp_parse_url($url);
		$home_parts = wp_parse_url($home);
		$scheme_ok  = in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
		$host_ok    = !empty($parts['host']) && !empty($home_parts['host'])
			&& strcasecmp($parts['host'], $home_parts['host']) === 0;
		if (!$scheme_ok || !$host_ok) {
			$url = $home;
		}
		$url = esc_url_raw($url);
		if ($url === '') {
			$url = $home;
		}

		$fresh      = (string) $req->get_param('fresh') === '1';
		$cache_slug = 'aq_psi_v2_' . md5($strategy . '|' . $url); // v2: full-report shape

		if (!$fresh) {
			$cached = get_transient($cache_slug);
			if (is_array($cached)) {
				$cached['cached'] = true;
				return new WP_REST_Response($cached, 200);
			}
		}

		$endpoint = add_query_arg([
			'url'      => $url,
			'strategy' => $strategy,
			'key'      => $key,
		], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');
		// All Lighthouse categories (PSI expects repeated `category` params).
		$endpoint .= '&category=performance&category=accessibility&category=best-practices&category=seo';

		$resp = wp_remote_get($endpoint, [
			'timeout' => 90,
			'headers' => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($resp)) {
			return new WP_REST_Response([
				'ok'      => false,
				'message' => 'Request to Google failed: ' . $resp->get_error_message(),
			], 200);
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = wp_remote_retrieve_body($resp);
		$data = json_decode($body, true);

		if ($code !== 200 || !is_array($data)) {
			$msg = 'PageSpeed API returned an error (HTTP ' . $code . ').';
			if (is_array($data) && isset($data['error']['message']) && is_string($data['error']['message'])) {
				$msg = (string) $data['error']['message'];
			}
			return new WP_REST_Response([
				'ok'      => false,
				'message' => $msg,
			], 200);
		}

		$out = self::normalize_psi($data, $strategy, $url);
		set_transient($cache_slug, $out, self::PSI_TTL);
		$out['cached'] = false;
		return new WP_REST_Response($out, 200);
	}

	/** Pulls the performance score + lab metrics out of the raw PSI payload (untrusted). */
	private static function normalize_psi(array $data, string $strategy, string $url): array {
		$lr     = is_array($data['lighthouseResult'] ?? null) ? $data['lighthouseResult'] : [];
		$cats   = is_array($lr['categories'] ?? null) ? $lr['categories'] : [];
		$audits = is_array($lr['audits'] ?? null) ? $lr['audits'] : [];

		$perf = null;
		if (isset($cats['performance']['score']) && is_numeric($cats['performance']['score'])) {
			$perf = (int) round(((float) $cats['performance']['score']) * 100);
		}

		// Lab metric audit ids -> display labels.
		$want = [
			'largest-contentful-paint' => 'LCP',
			'cumulative-layout-shift'  => 'CLS',
			'total-blocking-time'      => 'TBT', // INP proxy in lab data
			'interactive'              => 'INP', // best-effort if present
			'first-contentful-paint'   => 'FCP',
			'server-response-time'     => 'TTFB',
		];

		$metrics = [];
		foreach ($want as $id => $label) {
			if (!isset($audits[$id]) || !is_array($audits[$id])) {
				continue;
			}
			$a       = $audits[$id];
			$display = isset($a['displayValue']) && is_string($a['displayValue']) ? $a['displayValue'] : '';
			$score   = isset($a['score']) && is_numeric($a['score']) ? (float) $a['score'] : null;
			$rating  = $score === null ? 'na' : ($score >= 0.9 ? 'good' : ($score >= 0.5 ? 'avg' : 'poor'));
			$metrics[] = [
				'id'      => $id,
				'label'   => $label,
				'display' => $display,
				'rating'  => $rating,
			];
		}

		// All Lighthouse category scores (0–100).
		$score_keys = [
			'performance'    => 'Performance',
			'accessibility'  => 'Accessibility',
			'best-practices' => 'Best Practices',
			'seo'            => 'SEO',
		];
		$scores = [];
		foreach ($score_keys as $sk => $clabel) {
			if (isset($cats[$sk]['score']) && is_numeric($cats[$sk]['score'])) {
				$scores[$clabel] = (int) round(((float) $cats[$sk]['score']) * 100);
			}
		}

		// Actionable opportunities + diagnostics (audits with room to improve).
		$opps = [];
		foreach ($audits as $a) {
			if (!is_array($a)) {
				continue;
			}
			$mode = $a['scoreDisplayMode'] ?? '';
			if (!in_array($mode, ['numeric', 'metricSavings', 'binary'], true)) {
				continue;
			}
			if (!isset($a['score']) || !is_numeric($a['score'])) {
				continue;
			}
			$as = (float) $a['score'];
			if ($as >= 0.9) {
				continue; // already passing — not an opportunity
			}
			$title = isset($a['title']) && is_string($a['title']) ? $a['title'] : '';
			if ($title === '') {
				continue;
			}
			$opps[] = [
				'title'   => $title,
				'display' => isset($a['displayValue']) && is_string($a['displayValue']) ? $a['displayValue'] : '',
				'rating'  => $as >= 0.5 ? 'avg' : 'poor',
				'_score'  => $as,
			];
		}
		usort($opps, static function ($x, $y) { return $x['_score'] <=> $y['_score']; });
		$opps = array_slice($opps, 0, 12);
		foreach ($opps as &$o) {
			unset($o['_score']);
		}
		unset($o);

		return [
			'ok'            => true,
			'performance'   => $perf,
			'scores'        => $scores,
			'strategy'      => $strategy,
			'url'           => $url,
			'metrics'       => $metrics,
			'opportunities' => $opps,
			'fetchedAt'     => gmdate('c'),
		];
	}

	/* ---------------- screen ---------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'aq-core'));
		}
		AQ_Admin_Hub::open('Performance', 'Caching, optimization status, Core Web Vitals and site health.', 'aq-performance');

		$boost_active = defined('WP_ROCKET_VERSION');
		$boost_ver    = $boost_active ? (string) WP_ROCKET_VERSION : '';
		$obj_cache     = function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : false;
		$noindex       = aq_noindex_active();
		$env_type      = wp_get_environment_type();
		$php_ver       = PHP_VERSION;
		$wp_ver        = get_bloginfo('version');
		$has_psi_key   = self::psi_key() !== '';
		$psi_locked    = self::psi_locked();
		$home          = home_url('/');

		$nonce          = wp_create_nonce('wp_rest');
		$clear_url      = esc_url_raw(rest_url('aq/v1/perf/clear-cache'));
		$psi_key_url    = esc_url_raw(rest_url('aq/v1/perf/psi-key'));
		$pagespeed_base = esc_url_raw(rest_url('aq/v1/perf/pagespeed'));
		$boost_settings = admin_url('options-general.php?page=boost');
		$psi_get_key_url = 'https://developers.google.com/speed/docs/insights/v5/get-started';

		// Screen-specific styling (scoped under .aq-hub), reusing the brand palette.
		?>
		<style>
			.aq-hub .aq-perf-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
			.aq-hub .aq-perf-msg { font-size:13px; margin:12px 0 0; min-height:18px; }
			.aq-hub .aq-perf-msg--ok { color:#1a8f4f; }
			.aq-hub .aq-perf-msg--err { color:#a30d25; }
			.aq-hub .aq-perf-form { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:6px 0 0; }
			.aq-hub .aq-perf-form input[type=password], .aq-hub .aq-perf-form input[type=text] {
				flex:1 1 280px; max-width:420px; padding:7px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; }
			.aq-hub .aq-gauges { display:flex; flex-wrap:wrap; gap:24px; align-items:center; margin-top:8px; }
			.aq-hub .aq-gauge { position:relative; width:120px; height:120px; }
			.aq-hub .aq-gauge svg { transform:rotate(-90deg); display:block; }
			.aq-hub .aq-gauge__num { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
				font-family:Poppins, Inter, system-ui, sans-serif; font-size:30px; font-weight:700; }
			.aq-hub .aq-gauge__cap { text-align:center; font-size:12px; color:#5b6471; margin-top:6px; font-weight:600; }
			.aq-hub .aq-metric-rating { font-weight:700; }
			.aq-hub .aq-metric-rating--good { color:#1a8f4f; }
			.aq-hub .aq-metric-rating--avg  { color:#9a6212; }
			.aq-hub .aq-metric-rating--poor { color:#a30d25; }
			.aq-hub .aq-metric-rating--na   { color:#5b6471; }
			.aq-hub .aq-strat { display:inline-flex; gap:4px; background:#eef1f5; padding:3px; border-radius:999px; }
			.aq-hub .aq-strat button { border:0; background:transparent; padding:5px 12px; border-radius:999px; font-size:12px; font-weight:600; color:#5b6471; cursor:pointer; }
			.aq-hub .aq-strat button.is-active { background:#c8102e; color:#fff; }
			.aq-hub .aq-perf-muted { color:#5b6471; font-size:12px; }
		</style>

		<div class="aq-cards">
			<?php
			self::card_html(
				'Caching (Boost)',
				$boost_active
					? '<span class="aq-badge aq-badge--ok">Active</span>'
					: '<span class="aq-badge aq-badge--off">Off</span>',
				$boost_active ? 'Boost v' . esc_html($boost_ver) : 'Performance module not loaded'
			);
			self::card_html(
				'Object cache',
				$obj_cache
					? '<span class="aq-badge aq-badge--ok">Persistent</span>'
					: '<span class="aq-badge aq-badge--warn">Default</span>',
				$obj_cache ? 'External object cache in use' : 'No persistent object cache'
			);
			self::card_html(
				'Indexing',
				$noindex
					? '<span class="aq-badge aq-badge--warn">Noindex</span>'
					: '<span class="aq-badge aq-badge--ok">Indexable</span>',
				($noindex ? 'Search engines blocked' : 'Search engines allowed')
					. ' · environment: ' . esc_html($env_type)
			);
			self::card('PHP version', esc_html($php_ver), 'Server runtime');
			self::card('WordPress', esc_html((string) $wp_ver), 'Core version');
			?>
		</div>

		<div class="aq-panel">
			<h2>Search engine indexing</h2>
			<p class="aq-perf-muted" style="margin-top:0;">
				Indexing is <strong><?php echo $noindex ? 'BLOCKED (noindex)' : 'ALLOWED (indexable)'; ?></strong>,
				determined automatically by this site's hosting environment
				(currently reported as <code><?php echo esc_html($env_type); ?></code>).
				Only the literal environment <code>production</code> is indexable — every other environment
				(staging, development, local) stays blocked. There is no separate switch here:
				promote the site to production at the host level (e.g. Pressable's staging → production
				promotion) and this updates automatically.
			</p>
		</div>

		<div class="aq-panel">
			<h2>Caching</h2>
			<p class="aq-perf-muted" style="margin-top:0;">Clear the page cache and minified assets after publishing content or design changes.</p>
			<div class="aq-perf-actions">
				<button type="button" class="aq-btn" id="aq-clear-cache">Clear all caches</button>
				<a class="aq-btn aq-btn--ghost" href="<?php echo esc_url($boost_settings); ?>">Open Boost settings</a>
			</div>
			<p class="aq-perf-msg" id="aq-clear-msg" role="status" aria-live="polite"></p>
		</div>

		<div class="aq-panel">
			<h2>PageSpeed Insights</h2>
			<?php if ($has_psi_key) : ?>
				<p class="aq-perf-muted" style="margin-top:0;">
					API key <span class="aq-pill">key set</span> — run a lab test against the home page.
				</p>
				<div class="aq-perf-actions" style="margin-bottom:10px;">
					<span class="aq-strat" id="aq-strat" role="group" aria-label="Test device">
						<button type="button" data-strategy="mobile" class="is-active">Mobile</button>
						<button type="button" data-strategy="desktop">Desktop</button>
					</span>
					<button type="button" class="aq-btn" id="aq-run-psi">Run test</button>
				</div>
				<div id="aq-psi-result"></div>
				<p class="aq-perf-msg" id="aq-psi-msg" role="status" aria-live="polite"></p>

				<?php if ($psi_locked) : ?>
					<p class="aq-perf-muted" style="margin-top:12px;">Key is <strong>locked by the <code>AQ_PSI_KEY</code> constant</strong> in <code>wp-config.php</code> (shared across all sites) — edit it there.</p>
				<?php else : ?>
				<details style="margin-top:14px;">
					<summary class="aq-perf-muted" style="cursor:pointer;">Replace or remove API key</summary>
					<form class="aq-perf-form" id="aq-psi-key-form" style="margin-top:10px;">
						<input type="password" id="aq-psi-key" placeholder="Paste a new key (leave blank to remove)" autocomplete="off" />
						<button type="submit" class="aq-btn aq-btn--ghost">Save key</button>
					</form>
				</details>
				<?php endif; ?>
			<?php else : ?>
				<p class="aq-perf-muted" style="margin-top:0;">
					Add a free Google PageSpeed Insights API key to measure performance score, LCP, CLS, INP, FCP and TTFB.
				</p>
				<p>
					<a class="aq-btn aq-btn--ghost" href="<?php echo esc_url($psi_get_key_url); ?>" target="_blank" rel="noopener">Get a free PageSpeed API key ↗</a>
				</p>
				<form class="aq-perf-form" id="aq-psi-key-form">
					<input type="password" id="aq-psi-key" placeholder="Paste your PageSpeed API key" autocomplete="off" />
					<button type="submit" class="aq-btn">Save key</button>
				</form>
			<?php endif; ?>
			<p class="aq-perf-msg" id="aq-psi-key-msg" role="status" aria-live="polite"></p>
		</div>

		<script>
		(function () {
			var NONCE = <?php echo wp_json_encode($nonce); ?>;
			var URLS = {
				clear:     <?php echo wp_json_encode($clear_url); ?>,
				psiKey:    <?php echo wp_json_encode($psi_key_url); ?>,
				pagespeed: <?php echo wp_json_encode($pagespeed_base); ?>,
				home:      <?php echo wp_json_encode($home); ?>
			};
			var strategy = 'mobile';

			function headers() {
				return { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' };
			}
			function setMsg(el, text, ok) {
				if (!el) return;
				el.textContent = text || '';
				el.className = 'aq-perf-msg' + (text ? (ok ? ' aq-perf-msg--ok' : ' aq-perf-msg--err') : '');
			}
			function esc(s) {
				return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
					return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
				});
			}

			// ----- Clear caches -----
			var clearBtn = document.getElementById('aq-clear-cache');
			var clearMsg = document.getElementById('aq-clear-msg');
			if (clearBtn) {
				clearBtn.addEventListener('click', function () {
					clearBtn.disabled = true;
					setMsg(clearMsg, 'Clearing…', true);
					fetch(URLS.clear, { method: 'POST', headers: headers(), body: '{}' })
						.then(function (r) { return r.json(); })
						.then(function (d) { setMsg(clearMsg, (d && d.message) || 'Done.', !!(d && d.ok)); })
						.catch(function () { setMsg(clearMsg, 'Request failed.', false); })
						.then(function () { clearBtn.disabled = false; });
				});
			}

			// ----- Save / clear PSI key -----
			var keyForm = document.getElementById('aq-psi-key-form');
			var keyMsg  = document.getElementById('aq-psi-key-msg');
			if (keyForm) {
				keyForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var input = document.getElementById('aq-psi-key');
					var val = input ? input.value : '';
					setMsg(keyMsg, 'Saving…', true);
					fetch(URLS.psiKey, { method: 'POST', headers: headers(), body: JSON.stringify({ key: val }) })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							setMsg(keyMsg, (d && d.message) || 'Saved.', !!(d && d.ok));
							if (input) { input.value = ''; }
							if (d && d.ok) { setTimeout(function () { location.reload(); }, 700); }
						})
						.catch(function () { setMsg(keyMsg, 'Request failed.', false); });
				});
			}

			// ----- Strategy toggle -----
			var stratWrap = document.getElementById('aq-strat');
			if (stratWrap) {
				stratWrap.addEventListener('click', function (e) {
					var b = e.target.closest('button[data-strategy]');
					if (!b) return;
					strategy = b.getAttribute('data-strategy');
					var all = stratWrap.querySelectorAll('button');
					for (var i = 0; i < all.length; i++) { all[i].classList.remove('is-active'); }
					b.classList.add('is-active');
				});
			}

			// ----- Run PSI -----
			var runBtn = document.getElementById('aq-run-psi');
			var psiMsg = document.getElementById('aq-psi-msg');
			var psiRes = document.getElementById('aq-psi-result');

			function gaugeColor(score) {
				if (score == null) return '#5b6471';
				if (score >= 90) return '#1a8f4f';
				if (score >= 50) return '#9a6212';
				return '#a30d25';
			}
			function renderGauge(score) {
				var r = 52, c = 2 * Math.PI * r;
				var pct = score == null ? 0 : Math.max(0, Math.min(100, score));
				var off = c * (1 - pct / 100);
				var col = gaugeColor(score);
				return '<div><div class="aq-gauge">' +
					'<svg width="120" height="120" viewBox="0 0 120 120">' +
					'<circle cx="60" cy="60" r="' + r + '" fill="none" stroke="#eef1f5" stroke-width="10"/>' +
					'<circle cx="60" cy="60" r="' + r + '" fill="none" stroke="' + col + '" stroke-width="10" ' +
					'stroke-linecap="round" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '"/>' +
					'</svg>' +
					'<div class="aq-gauge__num" style="color:' + col + '">' + (score == null ? '—' : score) + '</div>' +
					'</div><div class="aq-gauge__cap">Performance</div></div>';
			}
			function renderMetrics(metrics) {
				if (!metrics || !metrics.length) return '';
				var rows = '';
				for (var i = 0; i < metrics.length; i++) {
					var m = metrics[i];
					var rate = (m.rating || 'na');
					rows += '<tr><td><strong>' + esc(m.label) + '</strong></td>' +
						'<td>' + esc(m.display || '—') + '</td>' +
						'<td><span class="aq-metric-rating aq-metric-rating--' + esc(rate) + '">' +
						esc(rate === 'na' ? '—' : rate.charAt(0).toUpperCase() + rate.slice(1)) + '</span></td></tr>';
				}
				return '<table class="aq-table" style="max-width:520px;"><thead><tr>' +
					'<th>Metric</th><th>Value</th><th>Rating</th></tr></thead><tbody>' + rows + '</tbody></table>';
			}

			if (runBtn) {
				runBtn.addEventListener('click', function () {
					runBtn.disabled = true;
					setMsg(psiMsg, 'Running PageSpeed test (this can take up to a minute)…', true);
					if (psiRes) { psiRes.innerHTML = ''; }
					var u = URLS.pagespeed + '?strategy=' + encodeURIComponent(strategy) +
						'&url=' + encodeURIComponent(URLS.home);
					fetch(u, { method: 'GET', headers: { 'X-WP-Nonce': NONCE } })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							if (!d || !d.ok) {
								setMsg(psiMsg, (d && d.message) || 'Test failed.', false);
								return;
							}
							var note = (d.cached ? 'Cached result.' : 'Fresh result.') +
								' Device: ' + esc(d.strategy || strategy) + '.';
							setMsg(psiMsg, note, true);
							function buildPsiReport(r) {
								var L = [];
								L.push('PageSpeed Insights — ' + (r.url || URLS.home));
								L.push('Device: ' + (r.strategy || strategy) + (r.cached ? ' (cached)' : '') + '  ·  ' + (r.fetchedAt || ''));
								L.push('');
								if (r.scores) {
									L.push('Scores (0-100):');
									Object.keys(r.scores).forEach(function (k) { L.push('- ' + k + ': ' + r.scores[k]); });
									L.push('');
								}
								if (r.metrics && r.metrics.length) {
									L.push('Lab metrics:');
									r.metrics.forEach(function (m) { L.push('- ' + m.label + ': ' + (m.display || '-') + ' (' + (m.rating || 'na') + ')'); });
									L.push('');
								}
								if (r.opportunities && r.opportunities.length) {
									L.push('Top opportunities & diagnostics:');
									r.opportunities.forEach(function (o) { L.push('- ' + o.title + (o.display ? ' - ' + o.display : '') + ' (' + (o.rating || 'na') + ')'); });
									L.push('');
								}
								L.push('Full report: https://pagespeed.web.dev/analysis?url=' + encodeURIComponent(r.url || URLS.home) + '&form_factor=' + (r.strategy || strategy));
								return L.join('\n');
							}
							if (psiRes) {
								var sc = d.scores || {}, scCards = '';
								Object.keys(sc).forEach(function (k) {
									if (k === 'Performance') { return; }
									var v = sc[k];
									scCards += '<div style="text-align:center;min-width:84px;">' +
										'<div style="font-family:Poppins,Inter,system-ui,sans-serif;font-size:26px;font-weight:700;color:' + gaugeColor(v) + '">' + (v == null ? '—' : v) + '</div>' +
										'<div class="aq-gauge__cap">' + esc(k) + '</div></div>';
								});
								var opps = d.opportunities || [], oppHtml = '';
								if (opps.length) {
									var orows = '';
									for (var j = 0; j < opps.length; j++) {
										var o = opps[j], orate = o.rating || 'na';
										orows += '<tr><td><strong>' + esc(o.title) + '</strong></td><td>' + esc(o.display || '—') + '</td>' +
											'<td><span class="aq-metric-rating aq-metric-rating--' + esc(orate) + '">' + esc(orate === 'na' ? '—' : orate.charAt(0).toUpperCase() + orate.slice(1)) + '</span></td></tr>';
									}
									oppHtml = '<h3 style="margin:18px 0 8px;font-size:14px;">Top opportunities &amp; diagnostics</h3>' +
										'<table class="aq-table" style="max-width:640px;"><thead><tr><th>Item</th><th>Value</th><th>Rating</th></tr></thead><tbody>' + orows + '</tbody></table>';
								}
								var psiLink = 'https://pagespeed.web.dev/analysis?url=' + encodeURIComponent(d.url || URLS.home) + '&form_factor=' + encodeURIComponent(d.strategy || strategy);
								psiRes.innerHTML =
									'<div class="aq-gauges">' + renderGauge(d.performance) + scCards + '</div>' +
									'<div style="margin-top:10px;">' + renderMetrics(d.metrics) + '</div>' +
									oppHtml +
									'<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">' +
									'<button type="button" class="aq-btn" id="aq-psi-copy">Copy full report</button>' +
									'<a class="aq-btn aq-btn--ghost" target="_blank" rel="noopener" href="' + esc(psiLink) + '">Open full report on PageSpeed Insights ↗</a>' +
									'</div>';
								var copyBtn = document.getElementById('aq-psi-copy');
								if (copyBtn) {
									copyBtn.addEventListener('click', function () {
										var txt = buildPsiReport(d);
										if (navigator.clipboard && navigator.clipboard.writeText) {
											navigator.clipboard.writeText(txt).then(function () {
												copyBtn.textContent = 'Copied ✓';
												setTimeout(function () { copyBtn.textContent = 'Copy full report'; }, 1600);
											}, function () { window.prompt('Copy this report:', txt); });
										} else {
											window.prompt('Copy this report:', txt);
										}
									});
								}
							}
						})
						.catch(function () { setMsg(psiMsg, 'Request failed.', false); })
						.then(function () { runBtn.disabled = false; });
				});
			}
		})();
		</script>
		<?php

		AQ_Admin_Hub::close();
	}

	/* ---------------- local card helpers (Admin_Hub's are private) ---------------- */

	private static function card(string $label, string $num, string $sub = ''): void {
		echo '<div class="aq-card"><p class="aq-card__label">' . esc_html($label) . '</p>';
		echo '<div class="aq-card__num">' . esc_html($num) . '</div>';
		if ($sub !== '') {
			echo '<div class="aq-card__sub">' . esc_html($sub) . '</div>';
		}
		echo '</div>';
	}

	/** $html is trusted markup we build above (badges); $sub is escaped. */
	private static function card_html(string $label, string $html, string $sub = ''): void {
		echo '<div class="aq-card"><p class="aq-card__label">' . esc_html($label) . '</p>';
		echo '<div class="aq-card__num">' . wp_kses_post($html) . '</div>';
		if ($sub !== '') {
			echo '<div class="aq-card__sub">' . wp_kses_post($sub) . '</div>';
		}
		echo '</div>';
	}
}
