<?php
/**
 * Weather widget — a sticky, expand/collapse floating panel that pulls a live
 * forecast (Open-Meteo, keyless/free) for the client's SERVICE AREA and turns
 * the coming week's conditions into a reason-to-book CTA. Client-agnostic
 * engine feature; per-client data lives in aq_site('weather') (see
 * config/site.php). Rendered from body-close.php behind aq_site('weather.enabled').
 *
 * Fully self-contained: markup + styles + behavior are all here, because the
 * engine enqueues no front-end assets of its own and some sites (e.g. those on a
 * custom mu-plugin bundle) dequeue the theme's — inlining is the one delivery
 * method that works identically on every client.
 *
 * Location is resolved SERVER-SIDE: the service-area lat/lon by default; on a
 * geo/location page whose title or slug matches a `townCoords` key, that town's
 * forecast instead. No visitor geolocation, no PII — the only coordinates that
 * ever reach Open-Meteo are the business's own.
 *
 * Fail-quiet: the root ships with the `hidden` attribute and is only revealed by
 * JS once a forecast actually loads; any error (or no JS) leaves it hidden, so a
 * bad-weather-API day can never break a client page. (Intentional display:none
 * under JS control — not a stranded opacity:0 reveal.)
 */

if (!defined('ABSPATH')) {
	exit;
}

$w = (array) (aq_site('weather') ?: []);

/* ---- Resolve location: service-area default, town override on geo pages ---- */
$loc   = (array) ($w['location'] ?? []);
$lat   = isset($loc['lat']) && $loc['lat'] !== '' ? $loc['lat'] : null;
$lon   = isset($loc['lon']) && $loc['lon'] !== '' ? $loc['lon'] : null;
$label = (string) ($loc['label'] ?? '');

// Fall back to the top-level business geo + locality when `location` is omitted.
if ($lat === null || $lon === null) {
	$geo = (array) (aq_site('geo') ?: []);
	$lat = isset($geo['latitude']) && $geo['latitude'] !== '' ? $geo['latitude'] : $lat;
	$lon = isset($geo['longitude']) && $geo['longitude'] !== '' ? $geo['longitude'] : $lon;
}
if ($label === '') {
	$label = (string) (aq_site('address.locality') ?: aq_site('shortName') ?: aq_site('name') ?: '');
}

// Geo-page override: match the current page against the town list (town name),
// case-insensitively, against its title and slug. First hit wins, so order the
// list specific-first (e.g. "North Andover" before "Andover"). Accepts both the
// list form [ ['town'=>'Andover','lat'=>..,'lon'=>..], … ] and the legacy map
// form [ 'Andover' => ['lat'=>..,'lon'=>..], … ].
$town_coords = (array) ($w['townCoords'] ?? []);
if ($town_coords && function_exists('is_singular') && is_singular()) {
	$obj = get_queried_object();
	if ($obj instanceof WP_Post) {
		$hay = strtolower(trim(($obj->post_title ?? '') . ' ' . ($obj->post_name ?? '')));
		if ($hay !== '') {
			foreach ($town_coords as $key => $c) {
				if (!is_array($c)) {
					continue;
				}
				$town = isset($c['town']) ? (string) $c['town'] : (string) $key;
				if ($town === '' || !isset($c['lat'], $c['lon'])) {
					continue;
				}
				if (strpos($hay, strtolower($town)) !== false) {
					$lat   = $c['lat'];
					$lon   = $c['lon'];
					$label = $town;
					break;
				}
			}
		}
	}
}

// Fail-quiet: without coordinates there is nothing to show.
if ($lat === null || $lon === null) {
	return;
}

/* ---- Config for the client-side script (no PII — coords + copy only) ---- */
$position = in_array(($w['position'] ?? ''), ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true)
	? $w['position'] : 'bottom-right';
$units    = (($w['units'] ?? '') === 'celsius') ? 'celsius' : 'fahrenheit';
$days     = max(1, min(7, (int) ($w['days'] ?? 5)));
$heading  = (string) ($w['heading'] ?? 'Your local forecast');
$intro    = (string) ($w['intro'] ?? '');

$data = [
	'lat'          => (float) $lat,
	'lon'          => (float) $lon,
	'label'        => $label,
	'units'        => $units,
	'days'         => $days,
	'refreshHours' => max(0, (int) ($w['refreshHours'] ?? 3)),
	'startOpen'    => (bool) ($w['startOpen'] ?? false),
	'rules'        => array_values((array) ($w['rules'] ?? [])),
	'fallback'     => (array) ($w['fallbackCta'] ?? []),
];

/* ---- Optional brand colors → inline custom properties (else CSS falls back) ---- */
$theme      = (array) ($w['theme'] ?? []);
$style_vars = '';
$map        = ['accent' => '--aqw-accent', 'accentInk' => '--aqw-accent-ink', 'panel' => '--aqw-panel', 'ink' => '--aqw-ink', 'pulse' => '--aqw-pulse'];
foreach ($map as $k => $var) {
	if (!empty($theme[$k])) {
		$style_vars .= $var . ':' . $theme[$k] . ';';
	}
}
?>
<div id="aq-weather" class="aqw" data-pos="<?php echo esc_attr($position); ?>" data-open="false" role="region" aria-label="<?php echo esc_attr($heading); ?>" hidden<?php echo $style_vars ? ' style="' . esc_attr($style_vars) . '"' : ''; ?>>
	<script type="application/json" class="aqw-data"><?php echo wp_json_encode($data); ?></script>

	<!-- Collapsed pill -->
	<button type="button" class="aqw-pill" aria-expanded="false" aria-controls="aqw-panel">
		<span class="aqw-pill-ico" aria-hidden="true"></span>
		<span class="aqw-pill-temp">--&deg;</span>
		<span class="aqw-pill-msg"></span>
		<svg class="aqw-pill-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
	</button>

	<!-- Expanded panel -->
	<div id="aqw-panel" class="aqw-panel" role="dialog" aria-label="<?php echo esc_attr($heading); ?>">
		<div class="aqw-head">
			<div class="aqw-head-txt">
				<p class="aqw-eyebrow"><?php echo esc_html($label); ?></p>
				<p class="aqw-title"><?php echo esc_html($heading); ?></p>
			</div>
			<button type="button" class="aqw-close" aria-label="Collapse forecast">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
			</button>
		</div>

		<div class="aqw-now">
			<span class="aqw-now-ico" aria-hidden="true"></span>
			<div class="aqw-now-txt">
				<span class="aqw-now-temp">--&deg;</span>
				<span class="aqw-now-cond">&nbsp;</span>
			</div>
		</div>

		<div class="aqw-strip" aria-label="Daily forecast"></div>

		<?php if ($intro !== '') : ?><p class="aqw-intro"><?php echo esc_html($intro); ?></p><?php endif; ?>

		<div class="aqw-sell" hidden>
			<p class="aqw-sell-title"></p>
			<p class="aqw-sell-text"></p>
			<a class="aqw-sell-cta" href="#">Get a free estimate</a>
		</div>

		<p class="aqw-credit">Weather by <a href="https://open-meteo.com/" target="_blank" rel="noopener nofollow">Open&#8209;Meteo</a></p>
	</div>
</div>

<style>
#aq-weather.aqw{
	--aqw-accent: var(--accent, var(--brand-700, #0b5a42));
	--aqw-accent-ink: #fff;
	--aqw-panel: #fff;
	--aqw-ink: var(--ink, #1c1c1c);
	--aqw-mute: rgba(0,0,0,.55);
	--aqw-line: rgba(0,0,0,.10);
	--aqw-radius: 16px;
	--aqw-shadow: 0 18px 48px -18px rgba(0,0,0,.45);
	--aqw-pulse: var(--aqw-accent);   /* attention ring on the collapsed pill; override via weather.theme.pulse */
	position: fixed; z-index: 9998;
	font-family: inherit; line-height: 1.35;
	max-width: min(340px, calc(100vw - 1.5rem));
	transition: opacity .3s ease, transform .3s ease;
}
#aq-weather[hidden]{ display: none; }
/* Lowered: faded + click-through while the footer is in view, so the widget
   never sits on top of the copyright line at the bottom of the page. */
#aq-weather.aqw.aqw-lowered{ opacity: 0; transform: translateY(16px); pointer-events: none; }
#aq-weather.aqw[data-pos="bottom-right"]{ right: max(1rem, env(safe-area-inset-right)); bottom: calc(1rem + env(safe-area-inset-bottom)); }
#aq-weather.aqw[data-pos="bottom-left"] { left:  max(1rem, env(safe-area-inset-left));  bottom: calc(1rem + env(safe-area-inset-bottom)); }
#aq-weather.aqw[data-pos="top-right"]   { right: max(1rem, env(safe-area-inset-right)); top: 1rem; }
#aq-weather.aqw[data-pos="top-left"]    { left:  max(1rem, env(safe-area-inset-left));  top: 1rem; }
#aq-weather.aqw *{ box-sizing: border-box; }

/* Collapsed pill */
.aqw-pill{
	display: inline-flex; align-items: center; gap: .5rem;
	padding: .55rem .85rem; border: 0; cursor: pointer;
	background: var(--aqw-panel); color: var(--aqw-ink);
	border-radius: 999px; box-shadow: var(--aqw-shadow);
	font: inherit; font-weight: 600; font-size: .92rem;
	transition: transform .18s ease, box-shadow .18s ease;
	animation: aqw-pulse 2.2s cubic-bezier(.4,0,.6,1) infinite;
}
/* Pulsing attention ring — a colored halo that breathes outward from the pill. */
@keyframes aqw-pulse{
	0%   { box-shadow: var(--aqw-shadow), 0 0 0 0   color-mix(in srgb, var(--aqw-pulse) 70%, transparent); }
	70%  { box-shadow: var(--aqw-shadow), 0 0 0 14px color-mix(in srgb, var(--aqw-pulse) 0%,  transparent); }
	100% { box-shadow: var(--aqw-shadow), 0 0 0 0   color-mix(in srgb, var(--aqw-pulse) 0%,  transparent); }
}
.aqw-pill:hover{ transform: translateY(-2px); animation-play-state: paused; }
.aqw-pill:focus-visible{ outline: 2px solid var(--aqw-accent); outline-offset: 2px; }
.aqw-pill-ico{ width: 26px; height: 26px; display: inline-flex; color: var(--aqw-accent); flex: none; }
.aqw-pill-ico svg{ width: 100%; height: 100%; }
.aqw-pill-temp{ font-weight: 800; font-variant-numeric: tabular-nums; }
.aqw-pill-msg{ max-width: 12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--aqw-mute); font-weight: 600; font-size: .82rem; }
.aqw-pill-caret{ color: var(--aqw-mute); flex: none; }
@media (max-width: 480px){ .aqw-pill-msg{ display: none; } }

/* Panel */
.aqw-panel{
	display: none;
	width: min(340px, calc(100vw - 1.5rem));
	background: var(--aqw-panel); color: var(--aqw-ink);
	border-radius: var(--aqw-radius); box-shadow: var(--aqw-shadow);
	padding: 1rem 1rem 0.85rem; overflow: hidden;
}
#aq-weather.aqw[data-open="true"]  .aqw-panel{ display: block; }
#aq-weather.aqw[data-open="true"]  .aqw-pill{ display: none; }

.aqw-head{ display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
.aqw-eyebrow{ margin: 0; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; color: var(--aqw-accent); }
.aqw-title{ margin: .1rem 0 0; font-size: 1.02rem; font-weight: 700; }
.aqw-close{ border: 0; background: transparent; cursor: pointer; color: var(--aqw-mute); padding: .15rem; border-radius: 8px; flex: none; }
.aqw-close:hover{ color: var(--aqw-ink); background: rgba(0,0,0,.05); }
.aqw-close:focus-visible{ outline: 2px solid var(--aqw-accent); outline-offset: 2px; }

.aqw-now{ display: flex; align-items: center; gap: .7rem; margin: .75rem 0 .35rem; }
.aqw-now-ico{ width: 46px; height: 46px; color: var(--aqw-accent); flex: none; display: inline-flex; }
.aqw-now-ico svg{ width: 100%; height: 100%; }
.aqw-now-txt{ display: flex; flex-direction: column; }
.aqw-now-temp{ font-size: 1.9rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
.aqw-now-cond{ font-size: .85rem; color: var(--aqw-mute); font-weight: 600; }

.aqw-strip{ display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: .25rem; margin: .6rem 0 .2rem; padding-top: .6rem; border-top: 1px solid var(--aqw-line); }
.aqw-day{ display: flex; flex-direction: column; align-items: center; gap: .2rem; text-align: center; }
.aqw-day-name{ font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--aqw-mute); }
.aqw-day-ico{ width: 24px; height: 24px; color: var(--aqw-accent); }
.aqw-day-ico svg{ width: 100%; height: 100%; }
.aqw-day-hi{ font-size: .8rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.aqw-day-lo{ font-size: .72rem; color: var(--aqw-mute); font-variant-numeric: tabular-nums; }

.aqw-intro{ margin: .55rem 0 0; font-size: .82rem; color: var(--aqw-mute); }

.aqw-sell{ margin: .7rem 0 0; padding: .75rem .8rem; border-radius: 12px; background: color-mix(in srgb, var(--aqw-accent) 12%, transparent); }
.aqw-sell-title{ margin: 0; font-weight: 800; font-size: .9rem; }
.aqw-sell-text{ margin: .2rem 0 .55rem; font-size: .82rem; color: var(--aqw-ink); opacity: .85; }
.aqw-sell-cta{ display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: .6rem .9rem; border-radius: 10px; background: var(--aqw-accent); color: var(--aqw-accent-ink); font-weight: 800; font-size: .88rem; text-decoration: none; transition: filter .18s ease, transform .18s ease; }
.aqw-sell-cta:hover{ filter: brightness(.95); transform: translateY(-1px); }
.aqw-sell-cta:focus-visible{ outline: 2px solid var(--aqw-ink); outline-offset: 2px; }

.aqw-credit{ margin: .6rem 0 0; font-size: .66rem; color: var(--aqw-mute); text-align: right; }
.aqw-credit a{ color: inherit; text-decoration: underline; }

@media (prefers-reduced-motion: reduce){
	.aqw-pill, .aqw-sell-cta{ transition: none; }
	.aqw-pill{ animation: none; }
}
</style>

<script>
(function(){
	var root = document.getElementById('aq-weather');
	if (!root || root.dataset.aqwInit) { return; }
	root.dataset.aqwInit = '1';

	var dataEl = root.querySelector('.aqw-data');
	var cfg;
	try { cfg = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
	if (typeof cfg.lat !== 'number' || typeof cfg.lon !== 'number') { return; }

	var isF   = cfg.units !== 'celsius';
	var deg   = '°';
	var days  = Math.max(1, Math.min(7, cfg.days || 5));

	/* --- Weather-code → icon kind + label + base condition group --- */
	function codeInfo(code){
		var c = +code;
		if (c === 0) return { kind:'sun',    label:'Clear',            group:'clear' };
		if (c === 1) return { kind:'sun',    label:'Mainly clear',     group:'clear' };
		if (c === 2) return { kind:'pcloud', label:'Partly cloudy',    group:'clear' };
		if (c === 3) return { kind:'cloud',  label:'Overcast',         group:'cloud' };
		if (c === 45 || c === 48) return { kind:'fog',   label:'Fog',              group:'fog' };
		if (c >= 51 && c <= 55)   return { kind:'drizzle', label:'Drizzle',        group:'rain' };
		if (c === 56 || c === 57) return { kind:'sleet', label:'Freezing drizzle', group:'rain' };
		if (c >= 61 && c <= 65)   return { kind:'rain',  label:'Rain',             group:'rain' };
		if (c === 66 || c === 67) return { kind:'sleet', label:'Freezing rain',    group:'rain' };
		if (c >= 71 && c <= 77)   return { kind:'snow',  label:'Snow',             group:'snow' };
		if (c >= 80 && c <= 82)   return { kind:'rain',  label:'Rain showers',     group:'rain' };
		if (c === 85 || c === 86) return { kind:'snow',  label:'Snow showers',     group:'snow' };
		if (c === 95)             return { kind:'storm', label:'Thunderstorm',     group:'storm' };
		if (c === 96 || c === 99) return { kind:'storm', label:'Thunderstorm',     group:'storm' };
		return { kind:'cloud', label:'Cloudy', group:'cloud' };
	}

	/* --- Minimal inline SVG icon set --- */
	var P = 'stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"';
	var ICONS = {
		sun:    '<svg viewBox="0 0 24 24" '+P+'><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>',
		pcloud: '<svg viewBox="0 0 24 24" '+P+'><circle cx="8" cy="8" r="3.2"/><path d="M8 2.5V4M2.5 8H4M4.6 4.6l1 1M11.4 4.6l-1 1"/><path d="M17.5 19H8a4 4 0 010-8 5 5 0 019.6 1.4A3.3 3.3 0 0117.5 19z"/></svg>',
		cloud:  '<svg viewBox="0 0 24 24" '+P+'><path d="M17.5 19H7a4.5 4.5 0 010-9 6 6 0 0111.3 1.7A3.5 3.5 0 0117.5 19z"/></svg>',
		fog:    '<svg viewBox="0 0 24 24" '+P+'><path d="M16 14H6a4 4 0 010-8 5.5 5.5 0 0110.4 1.6A3 3 0 0116 14z"/><path d="M4 18h14M7 21h11"/></svg>',
		drizzle:'<svg viewBox="0 0 24 24" '+P+'><path d="M17 15H7a4 4 0 010-8 5.5 5.5 0 0110.4 1.6A3.2 3.2 0 0117 15z"/><path d="M9 19v1M13 19v1"/></svg>',
		rain:   '<svg viewBox="0 0 24 24" '+P+'><path d="M17 13H7a4 4 0 010-8 5.5 5.5 0 0110.4 1.6A3.2 3.2 0 0117 13z"/><path d="M8 17l-1 3M12 17l-1 3M16 17l-1 3"/></svg>',
		sleet:  '<svg viewBox="0 0 24 24" '+P+'><path d="M17 12H7a4 4 0 010-8 5.5 5.5 0 0110.4 1.6A3.2 3.2 0 0117 12z"/><path d="M8 16l-1 3M15 16l-1 3M11.5 16v3"/></svg>',
		snow:   '<svg viewBox="0 0 24 24" '+P+'><path d="M17 12H7a4 4 0 010-8 5.5 5.5 0 0110.4 1.6A3.2 3.2 0 0117 12z"/><path d="M8 17h.01M12 19h.01M16 17h.01M10 21h.01M14 21h.01"/></svg>',
		storm:  '<svg viewBox="0 0 24 24" '+P+'><path d="M17 12H7a4 4 0 010-8 5.5 5.5 0 0110.4 1.6A3.2 3.2 0 0117 12z"/><path d="M12 13l-2 4h3l-2 4"/></svg>',
		wind:   '<svg viewBox="0 0 24 24" '+P+'><path d="M3 8h11a2.5 2.5 0 10-2.5-2.5M3 12h16a2.5 2.5 0 11-2.5 2.5M3 16h9a2.5 2.5 0 11-2.5 2.5"/></svg>'
	};
	function icon(kind){ return ICONS[kind] || ICONS.cloud; }

	/* --- Thresholds (unit-aware) for temp/wind-derived groups --- */
	var FREEZE = isF ? 32  : 0;    // <= → freeze
	var HEAT   = isF ? 90  : 32;   // >= → heat
	var WIND   = 25;               // mph (we request mph) → high wind

	function dayGroups(code, tmax, tmin, wind){
		var g = {}, base = codeInfo(code).group;
		if (base === 'storm' || base === 'rain' || base === 'snow') { g[base] = 1; }
		if (typeof tmin === 'number' && tmin <= FREEZE) { g.freeze = 1; }
		if (typeof tmax === 'number' && tmax >= HEAT)   { g.heat   = 1; }
		if (typeof wind === 'number' && wind >= WIND)   { g.wind   = 1; }
		if (base === 'clear' && !g.freeze && !g.heat && !g.wind) { g.clear = 1; }
		return g;
	}

	/* --- Pick the highest-priority rule whose `when` appears in the window --- */
	function pickRule(present){
		var rules = (cfg.rules || []).slice().filter(function(r){ return r && r.when; });
		rules.sort(function(a,b){ return (+b.priority||0) - (+a.priority||0); });
		for (var i=0;i<rules.length;i++){ if (present[rules[i].when]) { return rules[i]; } }
		return null;
	}

	function dayName(iso){
		var p = String(iso).split('-'); // YYYY-MM-DD, parse local to avoid tz shift
		var d = new Date(+p[0], (+p[1]||1)-1, +p[2]||1);
		return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
	}
	function r(n){ return (n===null||n===undefined||isNaN(n)) ? '--' : Math.round(n); }

	/* --- Cache (localStorage, TTL = refreshHours) --- */
	var ckey = 'aqw:' + cfg.lat.toFixed(3) + ',' + cfg.lon.toFixed(3) + ':' + cfg.units + ':' + days;
	function cacheGet(){
		try{
			var raw = localStorage.getItem(ckey); if (!raw) return null;
			var o = JSON.parse(raw);
			var ttl = (cfg.refreshHours||3) * 3600 * 1000;
			if (!o.t || (Date.now() - o.t) > ttl) return null;
			return o.d;
		}catch(e){ return null; }
	}
	function cacheSet(d){ try{ localStorage.setItem(ckey, JSON.stringify({ t: Date.now(), d: d })); }catch(e){} }

	function fetchForecast(){
		var cached = cacheGet();
		if (cached) { return Promise.resolve(cached); }
		var url = 'https://api.open-meteo.com/v1/forecast'
			+ '?latitude=' + encodeURIComponent(cfg.lat)
			+ '&longitude=' + encodeURIComponent(cfg.lon)
			+ '&current=temperature_2m,weather_code'
			+ '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max'
			+ '&temperature_unit=' + (isF ? 'fahrenheit' : 'celsius')
			+ '&wind_speed_unit=mph&timezone=auto&forecast_days=' + days;
		var ctrl = ('AbortController' in window) ? new AbortController() : null;
		var to = ctrl ? setTimeout(function(){ ctrl.abort(); }, 8000) : null;
		return fetch(url, ctrl ? { signal: ctrl.signal } : undefined)
			.then(function(res){ if(!res.ok) throw new Error('http'); return res.json(); })
			.then(function(j){ if(to) clearTimeout(to); cacheSet(j); return j; });
	}

	/* --- Render into the (still-hidden) widget, then reveal --- */
	function render(j){
		var daily = j.daily || {}, cur = j.current || {};
		var codes = daily.weather_code || [], hi = daily.temperature_2m_max || [],
			lo = daily.temperature_2m_min || [], wind = daily.wind_speed_10m_max || [], time = daily.time || [];
		if (!time.length) throw new Error('empty');

		// Current
		var nowInfo = codeInfo(cur.weather_code);
		root.querySelector('.aqw-pill-ico').innerHTML = icon(nowInfo.kind);
		root.querySelector('.aqw-now-ico').innerHTML  = icon(nowInfo.kind);
		root.querySelector('.aqw-pill-temp').innerHTML = r(cur.temperature_2m) + deg;
		root.querySelector('.aqw-now-temp').innerHTML  = r(cur.temperature_2m) + deg;
		root.querySelector('.aqw-now-cond').textContent = nowInfo.label;

		// Daily strip
		var strip = root.querySelector('.aqw-strip'), out = '';
		for (var i=0;i<time.length && i<days;i++){
			var inf = codeInfo(codes[i]);
			out += '<div class="aqw-day"><span class="aqw-day-name">'+(i===0?'Today':dayName(time[i]))+'</span>'
				+ '<span class="aqw-day-ico">'+icon(inf.kind)+'</span>'
				+ '<span class="aqw-day-hi">'+r(hi[i])+deg+'</span>'
				+ '<span class="aqw-day-lo">'+r(lo[i])+deg+'</span></div>';
		}
		strip.innerHTML = out;

		// Rule engine over the window
		var present = {};
		for (var k=0;k<time.length && k<days;k++){
			var g = dayGroups(codes[k], hi[k], lo[k], wind[k]);
			for (var key in g){ if (g[key]) present[key] = 1; }
		}
		var rule = pickRule(present) || null;
		var sell = root.querySelector('.aqw-sell');
		var fb = cfg.fallback || {};
		var title = rule ? rule.title : (fb.title || '');
		var text  = rule ? rule.text  : (fb.text  || '');
		var cta   = rule ? rule.ctaLabel : (fb.ctaLabel || '');
		var href  = rule ? rule.ctaHref  : (fb.ctaHref  || '');
		if (title || text || cta){
			if (title){ sell.querySelector('.aqw-sell-title').textContent = title; }
			sell.querySelector('.aqw-sell-text').textContent = text || '';
			var a = sell.querySelector('.aqw-sell-cta');
			if (cta && href){ a.textContent = cta; a.setAttribute('href', href); a.style.display=''; }
			else { a.style.display='none'; }
			sell.hidden = false;
			// One-line teaser on the collapsed pill
			root.querySelector('.aqw-pill-msg').textContent = title || '';
		}

		root.hidden = false; // reveal only now that we have real data
	}

	/* --- Expand / collapse --- */
	var pill  = root.querySelector('.aqw-pill');
	var closeBtn = root.querySelector('.aqw-close');
	function setOpen(open){
		root.dataset.open = open ? 'true' : 'false';
		pill.setAttribute('aria-expanded', open ? 'true' : 'false');
		try{ localStorage.setItem('aqw:open', open ? '1' : '0'); }catch(e){}
	}
	pill.addEventListener('click', function(){ setOpen(true); });
	closeBtn.addEventListener('click', function(){ setOpen(false); });

	var startOpen = cfg.startOpen;
	try{ var s = localStorage.getItem('aqw:open'); if (s !== null) startOpen = (s === '1'); }catch(e){}
	setOpen(!!startOpen);

	/* Fade out while the footer is on screen so the widget never covers the
	   copyright line. Prefers watching the footer element; falls back to a
	   near-bottom scroll calc when there's no <footer>. */
	(function(){
		var lower = function(on){ root.classList.toggle('aqw-lowered', !!on); };
		var footer = document.querySelector('[data-aqw-stop], footer, .site-footer, #colophon');
		if (footer && 'IntersectionObserver' in window){
			new IntersectionObserver(function(entries){
				entries.forEach(function(e){ lower(e.isIntersecting); });
			}, { threshold: 0 }).observe(footer);
		} else {
			var onScroll = function(){
				var doc = document.documentElement;
				lower((window.innerHeight + window.scrollY) >= (doc.scrollHeight - 140));
			};
			addEventListener('scroll', onScroll, { passive: true });
			addEventListener('resize', onScroll, { passive: true });
			onScroll();
		}
	})();

	fetchForecast().then(render).catch(function(){ root.hidden = true; });
})();
</script>
