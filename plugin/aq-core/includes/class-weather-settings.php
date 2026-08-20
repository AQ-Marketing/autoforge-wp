<?php
/**
 * Weather widget admin screen (AutoForge → Weather, tab `aq-weather`).
 *
 * Edits the `weather` block of the site config — the sticky forecast widget that
 * turns the coming week's conditions into a reason-to-book CTA (rendered by
 * render/parts/weather-widget.php, gated on `weather.enabled`). All writes go
 * into the `aq_site_config` overlay via AQ_Site_Config, so a builder configures
 * the whole thing from wp-admin — no JSON, no code.
 *
 * REST:
 *   GET  aq/v1/weather-config  → the merged weather config
 *   POST aq/v1/weather-config  → validate + save the weather subtree
 * Both gated on manage_options + the WP REST nonce (X-WP-Nonce).
 *
 * Vanilla JS only (no build step): repeatable rule + town rows, color swatches,
 * fetch save. Mirrors AQ_Locations.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Weather_Settings {

	const CAP = 'manage_options';
	const SLUG = 'aq-weather';

	const POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
	const CONDITIONS = ['storm', 'rain', 'snow', 'freeze', 'heat', 'wind', 'clear'];

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 24);
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Weather', 'Weather', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/* ----------------------------------------------------------------- */
	/* REST                                                              */
	/* ----------------------------------------------------------------- */

	public static function rest_routes(): void {
		$perm = function () { return current_user_can(self::CAP); };
		register_rest_route('aq/v1', '/weather-config', [
			['methods' => 'GET',  'permission_callback' => $perm, 'callback' => [__CLASS__, 'rest_get']],
			['methods' => 'POST', 'permission_callback' => $perm, 'callback' => [__CLASS__, 'rest_save']],
		]);
	}

	public static function rest_get() {
		return rest_ensure_response(['ok' => true, 'weather' => self::current()]);
	}

	public static function rest_save(WP_REST_Request $req) {
		if (!class_exists('AQ_Site_Config')) {
			return new WP_Error('aq_no_config', 'Site config unavailable.', ['status' => 500]);
		}
		$body = $req->get_json_params();
		if (!is_array($body)) {
			$body = $req->get_params();
		}
		if (!is_array($body)) {
			return new WP_Error('aq_bad_body', 'Invalid request body.', ['status' => 400]);
		}
		$clean = self::sanitize($body);
		AQ_Site_Config::update(['weather' => $clean]);
		return rest_ensure_response(['ok' => true, 'weather' => $clean]);
	}

	/** The current merged weather config (with safe defaults for the form). */
	private static function current(): array {
		$w = function_exists('aq_site') ? aq_site('weather') : [];
		return is_array($w) ? $w : [];
	}

	/* ----------------------------------------------------------------- */
	/* Sanitize                                                          */
	/* ----------------------------------------------------------------- */

	private static function sanitize(array $in): array {
		$out = [];
		$out['enabled']  = !empty($in['enabled']);
		$out['position'] = in_array(($in['position'] ?? ''), self::POSITIONS, true) ? $in['position'] : 'bottom-right';
		$out['units']    = (($in['units'] ?? '') === 'celsius') ? 'celsius' : 'fahrenheit';
		$out['days']     = max(1, min(7, (int) ($in['days'] ?? 5)));
		$out['refreshHours'] = max(0, min(24, (int) ($in['refreshHours'] ?? 3)));
		$out['startOpen'] = !empty($in['startOpen']);
		$out['heading']  = sanitize_text_field((string) ($in['heading'] ?? ''));
		$out['intro']    = sanitize_text_field((string) ($in['intro'] ?? ''));

		$loc = is_array($in['location'] ?? null) ? $in['location'] : [];
		$out['location'] = [
			'label' => sanitize_text_field((string) ($loc['label'] ?? '')),
			'lat'   => self::num($loc['lat'] ?? null),
			'lon'   => self::num($loc['lon'] ?? null),
		];

		$theme = is_array($in['theme'] ?? null) ? $in['theme'] : [];
		$out['theme'] = [];
		foreach (['accent', 'accentInk', 'panel', 'ink', 'pulse'] as $k) {
			$out['theme'][$k] = self::color($theme[$k] ?? '');
		}

		// Selling rules — list of {when, priority, title, text, ctaLabel, ctaHref}.
		$out['rules'] = [];
		if (is_array($in['rules'] ?? null)) {
			foreach ($in['rules'] as $r) {
				if (!is_array($r)) {
					continue;
				}
				$when = in_array(($r['when'] ?? ''), self::CONDITIONS, true) ? $r['when'] : '';
				$title = sanitize_text_field((string) ($r['title'] ?? ''));
				if ($when === '' || $title === '') {
					continue; // skip incomplete rows
				}
				$out['rules'][] = [
					'when'     => $when,
					'priority' => (int) ($r['priority'] ?? 0),
					'title'    => $title,
					'text'     => sanitize_text_field((string) ($r['text'] ?? '')),
					'ctaLabel' => sanitize_text_field((string) ($r['ctaLabel'] ?? '')),
					'ctaHref'  => self::href($r['ctaHref'] ?? ''),
				];
			}
		}

		$fb = is_array($in['fallbackCta'] ?? null) ? $in['fallbackCta'] : [];
		$out['fallbackCta'] = [
			'title'    => sanitize_text_field((string) ($fb['title'] ?? '')),
			'text'     => sanitize_text_field((string) ($fb['text'] ?? '')),
			'ctaLabel' => sanitize_text_field((string) ($fb['ctaLabel'] ?? '')),
			'ctaHref'  => self::href($fb['ctaHref'] ?? ''),
		];

		// Town forecasts — list of {town, lat, lon}. Order preserved (specific-first).
		$out['townCoords'] = [];
		if (is_array($in['townCoords'] ?? null)) {
			foreach ($in['townCoords'] as $t) {
				if (!is_array($t)) {
					continue;
				}
				$town = sanitize_text_field((string) ($t['town'] ?? ''));
				$lat = self::num($t['lat'] ?? null);
				$lon = self::num($t['lon'] ?? null);
				if ($town === '' || $lat === null || $lon === null) {
					continue;
				}
				$out['townCoords'][] = ['town' => $town, 'lat' => $lat, 'lon' => $lon];
			}
		}

		return $out;
	}

	/** Float, or null when blank/non-numeric. */
	private static function num($v): ?float {
		if ($v === '' || $v === null) {
			return null;
		}
		return is_numeric($v) ? (float) $v : null;
	}

	/** A #hex colour, or '' when blank/invalid (so the CSS falls back to tokens). */
	private static function color($v): string {
		$v = is_scalar($v) ? trim((string) $v) : '';
		if ($v === '') {
			return '';
		}
		$hex = sanitize_hex_color($v);
		return is_string($hex) ? $hex : '';
	}

	/** A CTA target — allows relative paths (/contact/) and absolute URLs. */
	private static function href($v): string {
		$v = is_scalar($v) ? trim((string) $v) : '';
		if ($v === '') {
			return '';
		}
		if ($v[0] === '/') {
			return '/' . ltrim(sanitize_text_field($v), '/');
		}
		return esc_url_raw($v);
	}

	/* ----------------------------------------------------------------- */
	/* Screen                                                            */
	/* ----------------------------------------------------------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You do not have permission to access this screen.', 'aq-core'));
		}

		$w = self::current();
		$loc   = is_array($w['location'] ?? null) ? $w['location'] : [];
		$theme = is_array($w['theme'] ?? null) ? $w['theme'] : [];
		$rules = is_array($w['rules'] ?? null) ? array_values($w['rules']) : [];
		$fb    = is_array($w['fallbackCta'] ?? null) ? $w['fallbackCta'] : [];
		$towns = is_array($w['townCoords'] ?? null) ? array_values($w['townCoords']) : [];

		$nonce    = wp_create_nonce('wp_rest');
		$rest_url = esc_url_raw(rest_url('aq/v1/weather-config'));

		AQ_Admin_Hub::open('Weather', 'Configure the sticky forecast widget and the conditions that trigger each service CTA.', self::SLUG);
		self::style();

		echo '<div id="aq-wx-notice" class="aq-wx-notice" style="display:none;"></div>';
		echo '<form id="aq-wx-form" onsubmit="return false;">';

		/* ---- Display & behaviour ---- */
		echo '<div class="aq-panel"><h2>Display &amp; behaviour</h2>';
		echo '<div class="aq-wx-grid">';
		self::toggle('enabled', 'Show the weather widget', !empty($w['enabled']), 'Master on/off. When off the widget renders on no page.');
		self::toggle('startOpen', 'Start expanded', !empty($w['startOpen']), 'Show the full panel on first load instead of the small pill.');
		self::select('position', 'Position', (string) ($w['position'] ?? 'bottom-right'), [
			'bottom-right' => 'Bottom right', 'bottom-left' => 'Bottom left', 'top-right' => 'Top right', 'top-left' => 'Top left',
		], 'Corner of the screen. Keep it clear of a chat widget (usually bottom-right).');
		self::select('units', 'Units', (string) ($w['units'] ?? 'fahrenheit'), ['fahrenheit' => 'Fahrenheit (°F)', 'celsius' => 'Celsius (°C)']);
		self::number('days', 'Forecast days', (string) ($w['days'] ?? 5), '1', '7');
		self::number('refreshHours', 'Refresh (hours)', (string) ($w['refreshHours'] ?? 3), '0', '24', 'How long a visitor\'s browser caches the forecast before refetching.');
		self::text('heading', 'Panel heading', (string) ($w['heading'] ?? ''));
		self::text('intro', 'Intro line (optional)', (string) ($w['intro'] ?? ''));
		echo '</div></div>';

		/* ---- Default location ---- */
		echo '<div class="aq-panel"><h2>Default location (service area)</h2>';
		echo '<p class="aq-wx-help">The forecast shown on every page except the town pages below. Use the town&rsquo;s decimal latitude &amp; longitude (e.g. from Google Maps).</p>';
		echo '<div class="aq-wx-grid">';
		self::text('location.label', 'Label', (string) ($loc['label'] ?? ''), 'e.g. Merrimack Valley');
		self::number('location.lat', 'Latitude', self::numstr($loc['lat'] ?? null), '', '', 'Decimal, e.g. 42.726', 'any');
		self::number('location.lon', 'Longitude', self::numstr($loc['lon'] ?? null), '', '', 'Decimal, e.g. -71.191', 'any');
		echo '</div></div>';

		/* ---- Colours ---- */
		echo '<div class="aq-panel"><h2>Colours</h2>';
		echo '<p class="aq-wx-help">Leave blank to inherit the site&rsquo;s brand colours. <strong>Pulse</strong> is the attention ring around the pill — pick a high-contrast colour so it stands out.</p>';
		echo '<div class="aq-wx-grid">';
		self::color_field('theme.accent', 'Accent (icons, CTA)', (string) ($theme['accent'] ?? ''));
		self::color_field('theme.accentInk', 'CTA text', (string) ($theme['accentInk'] ?? ''));
		self::color_field('theme.pulse', 'Pulse ring', (string) ($theme['pulse'] ?? ''));
		self::color_field('theme.panel', 'Panel background', (string) ($theme['panel'] ?? ''));
		self::color_field('theme.ink', 'Text', (string) ($theme['ink'] ?? ''));
		echo '</div></div>';

		/* ---- Selling rules ---- */
		echo '<div class="aq-panel"><h2>Forecast &rarr; service rules</h2>';
		echo '<p class="aq-wx-help">When the coming week&rsquo;s forecast matches a condition, the widget shows that rule&rsquo;s message + CTA. The highest-priority matching rule wins.</p>';
		echo '<table class="aq-table aq-wx-rules"><thead><tr>';
		echo '<th style="width:120px;">When</th><th style="width:74px;">Priority' . AQ_Admin_Hub::tip('Higher number wins when more than one condition is in the forecast.') . '</th><th>Title</th><th>Message</th><th style="width:130px;">CTA label</th><th style="width:130px;">CTA link</th><th style="width:70px;">Order</th><th style="width:40px;"></th>';
		echo '</tr></thead><tbody id="aq-wx-rule-rows">';
		foreach ($rules as $r) {
			echo self::rule_row_html($r);
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost" id="aq-wx-add-rule">+ Add rule</button></p>';
		echo '</div>';

		/* ---- Fallback ---- */
		echo '<div class="aq-panel"><h2>Fallback message</h2>';
		echo '<p class="aq-wx-help">Shown on a calm week when no rule matches.</p>';
		echo '<div class="aq-wx-grid">';
		self::text('fallbackCta.title', 'Title', (string) ($fb['title'] ?? ''));
		self::text('fallbackCta.text', 'Message', (string) ($fb['text'] ?? ''));
		self::text('fallbackCta.ctaLabel', 'CTA label', (string) ($fb['ctaLabel'] ?? ''));
		self::text('fallbackCta.ctaHref', 'CTA link', (string) ($fb['ctaHref'] ?? ''), '/contact/');
		echo '</div></div>';

		/* ---- Town forecasts ---- */
		echo '<div class="aq-panel"><h2>Town forecasts (geo pages)</h2>';
		echo '<p class="aq-wx-help">On a page whose title or URL matches a town below, the widget shows that town&rsquo;s forecast instead of the default. Order specific-first (e.g. &ldquo;North Andover&rdquo; above &ldquo;Andover&rdquo;).</p>';
		echo '<table class="aq-table aq-wx-towns"><thead><tr>';
		echo '<th>Town (matches page title/URL)</th><th style="width:150px;">Latitude</th><th style="width:150px;">Longitude</th><th style="width:70px;">Order</th><th style="width:40px;"></th>';
		echo '</tr></thead><tbody id="aq-wx-town-rows">';
		foreach ($towns as $t) {
			echo self::town_row_html($t);
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost" id="aq-wx-add-town">+ Add town</button></p>';
		echo '</div>';

		/* ---- Save bar ---- */
		echo '<div class="aq-wx-savebar">';
		echo '<button type="button" class="aq-btn" id="aq-wx-save">Save weather settings</button>';
		echo '<span class="aq-wx-saving" id="aq-wx-saving" style="display:none;">Saving…</span>';
		echo '</div>';

		echo '</form>';

		self::script($rest_url, $nonce);
		AQ_Admin_Hub::close();
	}

	/* ---------------- field renderers ---------------- */

	private static function numstr($v): string {
		return ($v === null || $v === '') ? '' : (string) $v;
	}

	private static function fid(string $key): string {
		return 'aq-wx-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
	}

	private static function text(string $key, string $label, string $value, string $ph = '', string $help = ''): void {
		printf(
			'<label class="aq-wx-field"><span class="aq-wx-label">%s%s</span><input type="text" id="%s" class="aq-wx-input" data-key="%s" value="%s" placeholder="%s" /></label>',
			esc_html($label), $help !== '' ? AQ_Admin_Hub::tip($help) : '',
			esc_attr(self::fid($key)), esc_attr($key), esc_attr($value), esc_attr($ph)
		);
	}

	private static function number(string $key, string $label, string $value, string $min = '', string $max = '', string $help = '', string $step = '1'): void {
		printf(
			'<label class="aq-wx-field"><span class="aq-wx-label">%s%s</span><input type="number" id="%s" class="aq-wx-input" data-key="%s" value="%s"%s%s step="%s" /></label>',
			esc_html($label), $help !== '' ? AQ_Admin_Hub::tip($help) : '',
			esc_attr(self::fid($key)), esc_attr($key), esc_attr($value),
			$min !== '' ? ' min="' . esc_attr($min) . '"' : '',
			$max !== '' ? ' max="' . esc_attr($max) . '"' : '',
			esc_attr($step)
		);
	}

	private static function select(string $key, string $label, string $value, array $options, string $help = ''): void {
		$opts = '';
		foreach ($options as $val => $text) {
			$opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($val), selected($value, $val, false), esc_html($text));
		}
		printf(
			'<label class="aq-wx-field"><span class="aq-wx-label">%s%s</span><select id="%s" class="aq-wx-input" data-key="%s">%s</select></label>',
			esc_html($label), $help !== '' ? AQ_Admin_Hub::tip($help) : '',
			esc_attr(self::fid($key)), esc_attr($key), $opts
		);
	}

	private static function toggle(string $key, string $label, bool $on, string $help = ''): void {
		printf(
			'<label class="aq-wx-field aq-wx-toggle"><input type="checkbox" id="%s" class="aq-wx-check" data-key="%s"%s /><span class="aq-wx-label" style="text-transform:none;font-weight:600;">%s%s</span></label>',
			esc_attr(self::fid($key)), esc_attr($key), checked($on, true, false),
			esc_html($label), $help !== '' ? AQ_Admin_Hub::tip($help) : ''
		);
	}

	private static function color_field(string $key, string $label, string $value): void {
		printf(
			'<label class="aq-wx-field"><span class="aq-wx-label">%s</span><span class="aq-wx-color"><input type="text" id="%s" class="aq-wx-input aq-wx-colorinput" data-key="%s" value="%s" placeholder="#RRGGBB (blank = inherit)" /><span class="aq-wx-swatch" style="background:%s"></span></span></label>',
			esc_html($label), esc_attr(self::fid($key)), esc_attr($key), esc_attr($value), esc_attr($value !== '' ? $value : 'transparent')
		);
	}

	private static function rule_row_html(array $r = []): string {
		$when = (string) ($r['when'] ?? '');
		$opts = '<option value="">—</option>';
		$labels = ['storm' => 'Storms', 'rain' => 'Rain', 'snow' => 'Snow', 'freeze' => 'Freeze', 'heat' => 'Heat', 'wind' => 'High wind', 'clear' => 'Clear/mild'];
		foreach (self::CONDITIONS as $c) {
			$opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($c), selected($when, $c, false), esc_html($labels[$c] ?? $c));
		}
		ob_start();
		?>
		<tr class="aq-wx-rule">
			<td><select class="aq-wx-input aq-wx-r-when"><?php echo $opts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></td>
			<td><input type="number" class="aq-wx-input aq-wx-r-priority" value="<?php echo esc_attr((string) ($r['priority'] ?? '')); ?>" /></td>
			<td><input type="text" class="aq-wx-input aq-wx-r-title" value="<?php echo esc_attr((string) ($r['title'] ?? '')); ?>" placeholder="e.g. Storms rolling in" /></td>
			<td><input type="text" class="aq-wx-input aq-wx-r-text" value="<?php echo esc_attr((string) ($r['text'] ?? '')); ?>" placeholder="Short pitch…" /></td>
			<td><input type="text" class="aq-wx-input aq-wx-r-ctaLabel" value="<?php echo esc_attr((string) ($r['ctaLabel'] ?? '')); ?>" placeholder="Get a quote" /></td>
			<td><input type="text" class="aq-wx-input aq-wx-r-ctaHref" value="<?php echo esc_attr((string) ($r['ctaHref'] ?? '')); ?>" placeholder="/contact/" /></td>
			<td class="aq-wx-order"><button type="button" class="aq-iconbtn aq-wx-up" title="Move up">&uarr;</button><button type="button" class="aq-iconbtn aq-wx-down" title="Move down">&darr;</button></td>
			<td><button type="button" class="aq-iconbtn aq-iconbtn--del aq-wx-del" title="Remove">&times;</button></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	private static function town_row_html(array $t = []): string {
		ob_start();
		?>
		<tr class="aq-wx-town">
			<td><input type="text" class="aq-wx-input aq-wx-t-town" value="<?php echo esc_attr((string) ($t['town'] ?? '')); ?>" placeholder="e.g. North Andover" /></td>
			<td><input type="number" step="any" class="aq-wx-input aq-wx-t-lat" value="<?php echo esc_attr(self::numstr($t['lat'] ?? null)); ?>" placeholder="42.699" /></td>
			<td><input type="number" step="any" class="aq-wx-input aq-wx-t-lon" value="<?php echo esc_attr(self::numstr($t['lon'] ?? null)); ?>" placeholder="-71.135" /></td>
			<td class="aq-wx-order"><button type="button" class="aq-iconbtn aq-wx-up" title="Move up">&uarr;</button><button type="button" class="aq-iconbtn aq-wx-down" title="Move down">&darr;</button></td>
			<td><button type="button" class="aq-iconbtn aq-iconbtn--del aq-wx-del" title="Remove">&times;</button></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------- styles ---------------- */

	private static function style(): void {
		?>
		<style>
			.aq-hub .aq-wx-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
			.aq-hub .aq-wx-field { display:flex; flex-direction:column; gap:5px; }
			.aq-hub .aq-wx-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; }
			.aq-hub .aq-wx-input { width:100%; padding:8px 10px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; background:#fff; }
			.aq-hub .aq-wx-input:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 3px rgba(200,16,46,.18); }
			.aq-hub .aq-wx-help { font-size:12px; color:#5b6471; margin:0 0 16px; }
			.aq-hub .aq-wx-toggle { flex-direction:row; align-items:center; gap:9px; }
			.aq-hub .aq-wx-check { width:18px; height:18px; margin:0; flex:none; }
			.aq-hub .aq-wx-color { position:relative; display:flex; align-items:center; gap:8px; }
			.aq-hub .aq-wx-colorinput { flex:1; }
			.aq-hub .aq-wx-swatch { width:26px; height:26px; border-radius:7px; border:1px solid #c9cfd6; flex:none; background-image:linear-gradient(45deg,#eee 25%,transparent 25%,transparent 75%,#eee 75%),linear-gradient(45deg,#eee 25%,#fff 25%,#fff 75%,#eee 75%); background-size:10px 10px; background-position:0 0,5px 5px; }
			.aq-hub .aq-wx-rules td, .aq-hub .aq-wx-towns td { vertical-align:middle; padding:6px; }
			.aq-hub .aq-wx-rules .aq-wx-input, .aq-hub .aq-wx-towns .aq-wx-input { padding:6px 8px; font-size:12.5px; }
			.aq-hub .aq-wx-order { white-space:nowrap; }
			.aq-hub .aq-iconbtn { background:#fff; border:1px solid #c9cfd6; color:#15191f; width:28px; height:28px; border-radius:7px; cursor:pointer; font-size:14px; line-height:1; padding:0; }
			.aq-hub .aq-iconbtn:hover { background:#f4f6fc; }
			.aq-hub .aq-iconbtn--del { color:#a30d25; border-color:#e6c4c4; }
			.aq-hub .aq-iconbtn--del:hover { background:#fbe7e7; }
			.aq-hub .aq-wx-savebar { position:sticky; bottom:0; margin-top:22px; padding:16px 0; display:flex; align-items:center; gap:14px; background:linear-gradient(to top,#f0f0f1 60%,transparent); }
			.aq-hub .aq-wx-saving { font-size:13px; color:#5b6471; }
			.aq-hub .aq-wx-notice { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; }
			.aq-hub .aq-wx-notice--ok { background:#eaf0ea; color:#1a8f4f; border:1px solid #bfe0c8; }
			.aq-hub .aq-wx-notice--err { background:#fbe7e7; color:#a30d25; border:1px solid #e6c4c4; }
		</style>
		<?php
	}

	/* ---------------- behaviour ---------------- */

	private static function script(string $rest_url, string $nonce): void {
		$blank_rule = self::rule_row_html();
		$blank_town = self::town_row_html();
		?>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode($rest_url); ?>;
			var NONCE = <?php echo wp_json_encode($nonce); ?>;
			var BLANK_RULE = <?php echo wp_json_encode($blank_rule); ?>;
			var BLANK_TOWN = <?php echo wp_json_encode($blank_town); ?>;

			function $(s, c) { return (c || document).querySelector(s); }
			function $all(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

			/* colour swatches follow their input */
			$all('.aq-wx-colorinput').forEach(function (inp) {
				var sw = inp.parentNode.querySelector('.aq-wx-swatch');
				function sync() { sw.style.background = inp.value.trim() || 'transparent'; }
				inp.addEventListener('input', sync);
			});

			/* generic repeater wiring (reorder + delete) */
			function wireRow(tr) {
				var del = $('.aq-wx-del', tr), up = $('.aq-wx-up', tr), down = $('.aq-wx-down', tr);
				if (del) del.addEventListener('click', function () { tr.parentNode.removeChild(tr); });
				if (up) up.addEventListener('click', function () { var p = tr.previousElementSibling; if (p) tr.parentNode.insertBefore(tr, p); });
				if (down) down.addEventListener('click', function () { var n = tr.nextElementSibling; if (n) tr.parentNode.insertBefore(n, tr); });
			}
			function addRow(tbodySel, html, focusSel) {
				var tbody = $(tbodySel);
				var tmp = document.createElement('tbody');
				tmp.innerHTML = html.trim();
				var tr = tmp.querySelector('tr');
				tbody.appendChild(tr);
				wireRow(tr);
				var f = focusSel ? $(focusSel, tr) : null; if (f) f.focus();
			}
			$all('#aq-wx-rule-rows .aq-wx-rule').forEach(wireRow);
			$all('#aq-wx-town-rows .aq-wx-town').forEach(wireRow);
			var addRuleBtn = $('#aq-wx-add-rule'); if (addRuleBtn) addRuleBtn.addEventListener('click', function () { addRow('#aq-wx-rule-rows', BLANK_RULE, '.aq-wx-r-title'); });
			var addTownBtn = $('#aq-wx-add-town'); if (addTownBtn) addTownBtn.addEventListener('click', function () { addRow('#aq-wx-town-rows', BLANK_TOWN, '.aq-wx-t-town'); });

			/* collect + save */
			function setDeep(obj, dotted, val) {
				var parts = dotted.split('.'), node = obj;
				for (var i = 0; i < parts.length - 1; i++) {
					if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) node[parts[i]] = {};
					node = node[parts[i]];
				}
				node[parts[parts.length - 1]] = val;
			}
			function collect() {
				var p = {};
				$all('.aq-wx-input[data-key]').forEach(function (el) { setDeep(p, el.getAttribute('data-key'), (el.value || '').trim()); });
				$all('.aq-wx-check[data-key]').forEach(function (el) { setDeep(p, el.getAttribute('data-key'), !!el.checked); });
				p.rules = $all('#aq-wx-rule-rows .aq-wx-rule').map(function (tr) {
					return {
						when: ($('.aq-wx-r-when', tr) || {}).value || '',
						priority: ($('.aq-wx-r-priority', tr) || {}).value || '',
						title: (($('.aq-wx-r-title', tr) || {}).value || '').trim(),
						text: (($('.aq-wx-r-text', tr) || {}).value || '').trim(),
						ctaLabel: (($('.aq-wx-r-ctaLabel', tr) || {}).value || '').trim(),
						ctaHref: (($('.aq-wx-r-ctaHref', tr) || {}).value || '').trim()
					};
				}).filter(function (r) { return r.when && r.title; });
				p.townCoords = $all('#aq-wx-town-rows .aq-wx-town').map(function (tr) {
					return {
						town: (($('.aq-wx-t-town', tr) || {}).value || '').trim(),
						lat: (($('.aq-wx-t-lat', tr) || {}).value || '').trim(),
						lon: (($('.aq-wx-t-lon', tr) || {}).value || '').trim()
					};
				}).filter(function (t) { return t.town && t.lat !== '' && t.lon !== ''; });
				return p;
			}
			function notice(msg, ok) {
				var el = $('#aq-wx-notice'); if (!el) return;
				el.textContent = msg;
				el.className = 'aq-wx-notice ' + (ok ? 'aq-wx-notice--ok' : 'aq-wx-notice--err');
				el.style.display = 'block';
				if (ok) { clearTimeout(notice._t); notice._t = setTimeout(function () { el.style.display = 'none'; }, 4000); }
			}
			var saveBtn = $('#aq-wx-save'), saving = $('#aq-wx-saving');
			if (saveBtn) saveBtn.addEventListener('click', function () {
				var payload = collect();
				saveBtn.disabled = true; if (saving) saving.style.display = 'inline';
				fetch(REST, {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body: JSON.stringify(payload)
				}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
				.then(function (res) {
					if (res.ok && res.body && res.body.ok) {
						var n = (res.body.weather && res.body.weather.rules ? res.body.weather.rules.length : 0);
						notice('Saved. ' + n + ' rule' + (n === 1 ? '' : 's') + ' active.', true);
					} else {
						notice('Save failed: ' + ((res.body && (res.body.message || res.body.code)) || 'unknown error'), false);
					}
				}).catch(function (e) { notice('Save failed: ' + e.message, false); })
				.then(function () { saveBtn.disabled = false; if (saving) saving.style.display = 'none'; });
			});
		})();
		</script>
		<?php
	}
}
