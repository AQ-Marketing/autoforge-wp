<?php
/**
 * Locations admin screen (AutoForge → Locations, tab `aq-locations`).
 *
 * Edits the business NAP, service-area towns, counties, and regions. All
 * writes go into the `aq_site_config` overlay option via AQ_Site_Config so
 * they ride on top of config/site.php and feed aq_site() everywhere —
 * including city × service page generation and JSON-LD areaServed.
 *
 * REST:
 *   GET  aq/v1/site-config   → merged config (file defaults + overlay)
 *   POST aq/v1/site-config   → validate + save overlay
 * Both gated on manage_options + the WP REST nonce (X-WP-Nonce).
 *
 * Vanilla JS only (no build step): repeatable town rows + fetch save.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Locations {

	const CAP = 'manage_options';

	/* ----------------------------------------------------------------- */
	/* REST                                                              */
	/* ----------------------------------------------------------------- */

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function rest_routes(): void {
		$perm = function () { return current_user_can(self::CAP); };

		register_rest_route('aq/v1', '/site-config', [
			[
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => [__CLASS__, 'rest_get'],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => [__CLASS__, 'rest_save'],
			],
		]);
	}

	/** GET → the full merged config. */
	public static function rest_get() {
		if (!class_exists('AQ_Site_Config')) {
			return new WP_Error('aq_no_config', 'Site config unavailable.', ['status' => 500]);
		}
		return rest_ensure_response(['ok' => true, 'config' => AQ_Site_Config::get()]);
	}

	/**
	 * POST → validate the editable subset and persist it as the overlay.
	 *
	 * We only accept the keys the screen edits (NAP + towns/counties/regions),
	 * sanitize each, and merge over the current overlay so unrelated overrides
	 * (if any) survive. Everything else in site.php still flows from the file.
	 */
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

		$patch = self::sanitize_patch($body);

		AQ_Site_Config::update($patch);

		return rest_ensure_response([
			'ok'     => true,
			'saved'  => $patch,
			'config' => AQ_Site_Config::get(),
		]);
	}

	/** Whitelist + sanitize the incoming config into a safe patch array. */
	private static function sanitize_patch(array $in): array {
		$patch = [];

		// Top-level scalar NAP fields.
		foreach (['name', 'shortName', 'tagline', 'phone', 'phoneTel', 'email', 'url'] as $k) {
			if (array_key_exists($k, $in)) {
				$val = is_scalar($in[$k]) ? (string) $in[$k] : '';
				$patch[$k] = ($k === 'email') ? sanitize_email($val) : sanitize_text_field($val);
			}
		}

		// Address (associative).
		if (isset($in['address']) && is_array($in['address'])) {
			$addr = [];
			foreach (['street', 'locality', 'region', 'postalCode', 'country'] as $k) {
				if (array_key_exists($k, $in['address'])) {
					$addr[$k] = sanitize_text_field((string) (is_scalar($in['address'][$k]) ? $in['address'][$k] : ''));
				}
			}
			if ($addr) {
				$patch['address'] = $addr;
			}
		}

		// Towns — list of {slug,name,county}. Reindexed so it overwrites cleanly.
		if (isset($in['towns']) && is_array($in['towns'])) {
			$towns = [];
			foreach ($in['towns'] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$name = sanitize_text_field((string) ($row['name'] ?? ''));
				if ($name === '') {
					continue; // skip empty rows
				}
				$slug = (string) ($row['slug'] ?? '');
				$slug = $slug !== '' ? sanitize_title($slug) : sanitize_title($name);
				$towns[] = [
					'slug'   => $slug,
					'name'   => $name,
					'county' => sanitize_text_field((string) ($row['county'] ?? '')),
				];
			}
			$patch['towns'] = array_values($towns);

			// Keep the LocalBusiness JSON-LD areaServed list (config key 'areas',
			// a "Town, ST" string list read by AQ_JsonLd) in sync with the towns.
			$patch['areas'] = array_map(static function ($t) {
				return $t['name'] . ', MA';
			}, $patch['towns']);
		}

		// Counties + regions — plain string lists.
		foreach (['counties', 'regions'] as $listKey) {
			if (isset($in[$listKey]) && is_array($in[$listKey])) {
				$list = [];
				foreach ($in[$listKey] as $item) {
					$s = sanitize_text_field((string) (is_scalar($item) ? $item : ''));
					if ($s !== '') {
						$list[] = $s;
					}
				}
				$patch[$listKey] = array_values($list);
			}
		}

		return $patch;
	}

	/* ----------------------------------------------------------------- */
	/* Screen                                                            */
	/* ----------------------------------------------------------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('You do not have permission to access this screen.', 'aq-core'));
		}

		$cfg = class_exists('AQ_Site_Config') ? AQ_Site_Config::get() : (function_exists('aq_site') ? (array) aq_site() : []);
		$cfg = is_array($cfg) ? $cfg : [];

		$nap     = self::pick($cfg, ['name' => '', 'shortName' => '', 'phone' => '', 'phoneTel' => '', 'email' => '']);
		$address = is_array($cfg['address'] ?? null) ? $cfg['address'] : [];
		$towns   = is_array($cfg['towns'] ?? null) ? array_values($cfg['towns']) : [];
		$counties= is_array($cfg['counties'] ?? null) ? array_values($cfg['counties']) : [];
		$regions = is_array($cfg['regions'] ?? null) ? array_values($cfg['regions']) : [];

		$nonce   = wp_create_nonce('wp_rest');
		$rest_url= esc_url_raw(rest_url('aq/v1/site-config'));

		AQ_Admin_Hub::open('Locations', 'Manage service-area towns, counties, regions and business info (NAP).', 'aq-locations');

		self::style();

		echo '<div id="aq-loc-notice" class="aq-loc-notice" style="display:none;"></div>';

		echo '<form id="aq-loc-form" onsubmit="return false;">';

		/* ---------------- NAP ---------------- */
		echo '<div class="aq-panel"><h2>Business info (NAP)</h2>';
		echo '<p class="aq-loc-help">Name, address &amp; phone — used in the site header/footer, SEO meta and LocalBusiness JSON-LD. Keep this matching the Google Business Profile exactly.</p>';
		echo '<div class="aq-loc-grid">';
		self::text('name', 'Legal name', (string) ($nap['name'] ?? ''));
		self::text('shortName', 'Short / brand name', (string) ($nap['shortName'] ?? ''));
		self::text('phone', 'Phone (display)', (string) ($nap['phone'] ?? ''), 'e.g. (413) 522-8004');
		self::text('phoneTel', 'Phone (tel: link)', (string) ($nap['phoneTel'] ?? ''), 'e.g. +14135228004');
		self::text('email', 'Email', (string) ($nap['email'] ?? ''), '', 'email');
		echo '</div>';

		echo '<h3 class="aq-loc-subhead">Address</h3>';
		echo '<div class="aq-loc-grid">';
		self::text('address.street', 'Street', (string) ($address['street'] ?? ''));
		self::text('address.locality', 'City / town', (string) ($address['locality'] ?? ''));
		self::text('address.region', 'State', (string) ($address['region'] ?? ''));
		self::text('address.postalCode', 'ZIP', (string) ($address['postalCode'] ?? ''));
		self::text('address.country', 'Country', (string) ($address['country'] ?? ''));
		echo '</div></div>';

		/* ---------------- Towns ---------------- */
		echo '<div class="aq-panel"><h2>Service-area towns</h2>';
		echo '<p class="aq-loc-help"><strong>Heads up:</strong> the site&rsquo;s service-area menus (header/footer) and the JSON-LD <code>areaServed</code> list read these rows. (Adding a town here does not auto-create its pages.)</p>';
		echo '<table class="aq-table aq-loc-towns"><thead><tr>';
		echo '<th style="width:34px;">#</th><th>Town name</th><th>Slug</th><th>County</th><th style="width:120px;">Order</th><th style="width:60px;"></th>';
		echo '</tr></thead><tbody id="aq-loc-town-rows">';
		if ($towns) {
			foreach ($towns as $t) {
				echo self::town_row_html(
					(string) ($t['name'] ?? ''),
					(string) ($t['slug'] ?? ''),
					(string) ($t['county'] ?? '')
				);
			}
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:12px;"><button type="button" class="aq-btn aq-btn--ghost" id="aq-loc-add-town">+ Add town</button></p>';
		echo '</div>';

		/* ---------------- Counties + Regions ---------------- */
		echo '<div class="aq-loc-twocol">';

		echo '<div class="aq-panel"><h2>Counties</h2>';
		echo '<p class="aq-loc-help">One per line.</p>';
		echo '<div id="aq-loc-counties" class="aq-loc-list"></div>';
		echo '<p style="margin-top:10px;"><button type="button" class="aq-btn aq-btn--ghost aq-loc-add-list" data-list="counties">+ Add county</button></p>';
		echo '</div>';

		echo '<div class="aq-panel"><h2>Regions</h2>';
		echo '<p class="aq-loc-help">Marketing region labels (e.g. a metro area or named region).</p>';
		echo '<div id="aq-loc-regions" class="aq-loc-list"></div>';
		echo '<p style="margin-top:10px;"><button type="button" class="aq-btn aq-btn--ghost aq-loc-add-list" data-list="regions">+ Add region</button></p>';
		echo '</div>';

		echo '</div>'; // twocol

		/* ---------------- Save bar ---------------- */
		echo '<div class="aq-loc-savebar">';
		echo '<button type="button" class="aq-btn" id="aq-loc-save">Save all changes</button>';
		echo '<span class="aq-loc-saving" id="aq-loc-saving" style="display:none;">Saving…</span>';
		echo '</div>';

		echo '</form>';

		// Bootstrap data + behaviour. Lists are rendered client-side from JSON
		// so add/remove is uniform; town rows are server-rendered + cloneable.
		self::script($rest_url, $nonce, $counties, $regions);

		AQ_Admin_Hub::close();
	}

	/* ----------------------------------------------------------------- */
	/* Render helpers                                                    */
	/* ----------------------------------------------------------------- */

	private static function pick(array $cfg, array $defaults): array {
		$out = $defaults;
		foreach ($defaults as $k => $_) {
			if (isset($cfg[$k]) && is_scalar($cfg[$k])) {
				$out[$k] = (string) $cfg[$k];
			}
		}
		return $out;
	}

	private static function text(string $key, string $label, string $value, string $placeholder = '', string $type = 'text'): void {
		$id = 'aq-loc-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
		printf(
			'<label class="aq-loc-field"><span class="aq-loc-label">%s</span>'
			. '<input type="%s" id="%s" class="aq-loc-input" data-key="%s" value="%s" placeholder="%s" /></label>',
			esc_html($label),
			esc_attr($type),
			esc_attr($id),
			esc_attr($key),
			esc_attr($value),
			esc_attr($placeholder)
		);
	}

	/** A single editable town row (cloned client-side for new rows). */
	private static function town_row_html(string $name, string $slug, string $county): string {
		ob_start();
		?>
		<tr class="aq-loc-town">
			<td class="aq-loc-idx">&bull;</td>
			<td><input type="text" class="aq-loc-input aq-town-name" value="<?php echo esc_attr($name); ?>" placeholder="Town name" /></td>
			<td><input type="text" class="aq-loc-input aq-town-slug" value="<?php echo esc_attr($slug); ?>" placeholder="auto from name" /></td>
			<td><input type="text" class="aq-loc-input aq-town-county" value="<?php echo esc_attr($county); ?>" placeholder="County" /></td>
			<td class="aq-loc-order">
				<button type="button" class="aq-iconbtn aq-town-up" title="Move up">&uarr;</button>
				<button type="button" class="aq-iconbtn aq-town-down" title="Move down">&darr;</button>
			</td>
			<td><button type="button" class="aq-iconbtn aq-iconbtn--del aq-town-del" title="Remove">&times;</button></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	private static function style(): void {
		?>
		<style>
			.aq-hub .aq-loc-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
			.aq-hub .aq-loc-field { display:flex; flex-direction:column; gap:5px; }
			.aq-hub .aq-loc-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#5b6471; font-weight:600; }
			.aq-hub .aq-loc-input { width:100%; padding:8px 10px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; background:#fff; }
			.aq-hub .aq-loc-input:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 3px rgba(200,16,46,.18); }
			.aq-hub .aq-loc-subhead { font-family:Poppins,Inter,system-ui,sans-serif; font-size:14px; color:#0d1014; margin:20px 0 12px; }
			.aq-hub .aq-loc-help { font-size:12px; color:#5b6471; margin:0 0 16px; }
			.aq-hub .aq-loc-help code { background:#eef1f5; padding:1px 5px; border-radius:4px; font-size:11px; }
			.aq-hub .aq-loc-towns td { vertical-align:middle; }
			.aq-hub .aq-loc-idx { color:#8a94a1; font-weight:700; text-align:center; }
			.aq-hub .aq-loc-order { white-space:nowrap; }
			.aq-hub .aq-iconbtn { background:#fff; border:1px solid #c9cfd6; color:#15191f; width:28px; height:28px; border-radius:7px; cursor:pointer; font-size:14px; line-height:1; padding:0; }
			.aq-hub .aq-iconbtn:hover { background:#f4f6fc; }
			.aq-hub .aq-iconbtn--del { color:#a30d25; border-color:#e6c4c4; }
			.aq-hub .aq-iconbtn--del:hover { background:#fbe7e7; }
			.aq-hub .aq-loc-twocol { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
			@media (max-width:900px){ .aq-hub .aq-loc-twocol { grid-template-columns:1fr; } }
			.aq-hub .aq-loc-listrow { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
			.aq-hub .aq-loc-listrow .aq-loc-input { flex:1; }
			.aq-hub .aq-loc-savebar { position:sticky; bottom:0; margin-top:22px; padding:16px 0; display:flex; align-items:center; gap:14px; }
			.aq-hub .aq-loc-saving { font-size:13px; color:#5b6471; }
			.aq-hub .aq-loc-notice { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; }
			.aq-hub .aq-loc-notice--ok { background:#eaf0ea; color:#1a8f4f; border:1px solid #bfe0c8; }
			.aq-hub .aq-loc-notice--err { background:#fbe7e7; color:#a30d25; border:1px solid #e6c4c4; }
		</style>
		<?php
	}

	private static function script(string $rest_url, string $nonce, array $counties, array $regions): void {
		// Encode the row template once so JS can clone fresh blank rows.
		$blank_row = self::town_row_html('', '', '');
		?>
		<script>
		(function () {
			var REST  = <?php echo wp_json_encode($rest_url); ?>;
			var NONCE = <?php echo wp_json_encode($nonce); ?>;
			var BLANK_ROW = <?php echo wp_json_encode($blank_row); ?>;
			var COUNTIES = <?php echo wp_json_encode(array_values($counties)); ?>;
			var REGIONS  = <?php echo wp_json_encode(array_values($regions)); ?>;

			function $(sel, ctx) { return (ctx || document).querySelector(sel); }
			function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

			function slugify(s) {
				return String(s).toLowerCase().trim()
					.replace(/[^a-z0-9]+/g, '-')
					.replace(/^-+|-+$/g, '');
			}

			/* ---- town rows ---- */
			var tbody = $('#aq-loc-town-rows');

			function renumber() {
				$all('.aq-loc-town', tbody).forEach(function (tr, i) {
					var idx = $('.aq-loc-idx', tr);
					if (idx) { idx.textContent = (i + 1); }
				});
			}

			function wireRow(tr) {
				var del  = $('.aq-town-del', tr);
				var up   = $('.aq-town-up', tr);
				var down = $('.aq-town-down', tr);
				var name = $('.aq-town-name', tr);
				var slug = $('.aq-town-slug', tr);

				if (del)  del.addEventListener('click', function () { tr.parentNode.removeChild(tr); renumber(); });
				if (up)   up.addEventListener('click', function () {
					var prev = tr.previousElementSibling;
					if (prev) { tr.parentNode.insertBefore(tr, prev); renumber(); }
				});
				if (down) down.addEventListener('click', function () {
					var next = tr.nextElementSibling;
					if (next) { tr.parentNode.insertBefore(next, tr); renumber(); }
				});
				// Auto-fill slug from name when slug is left blank.
				if (name && slug) {
					name.addEventListener('blur', function () {
						if (!slug.value.trim()) { slug.value = slugify(name.value); }
					});
				}
			}

			$all('.aq-loc-town', tbody).forEach(wireRow);
			renumber();

			var addTown = $('#aq-loc-add-town');
			if (addTown) addTown.addEventListener('click', function () {
				var tmp = document.createElement('tbody');
				tmp.innerHTML = BLANK_ROW.trim();
				var tr = tmp.querySelector('tr');
				tbody.appendChild(tr);
				wireRow(tr);
				renumber();
				var n = $('.aq-town-name', tr); if (n) n.focus();
			});

			/* ---- simple string lists (counties / regions) ---- */
			function listContainer(key) { return $(key === 'counties' ? '#aq-loc-counties' : '#aq-loc-regions'); }

			function makeListRow(key, value) {
				var row = document.createElement('div');
				row.className = 'aq-loc-listrow';
				var input = document.createElement('input');
				input.type = 'text';
				input.className = 'aq-loc-input aq-list-input';
				input.value = value || '';
				input.placeholder = (key === 'counties') ? 'County, ST' : 'Region name';
				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'aq-iconbtn aq-iconbtn--del';
				del.title = 'Remove';
				del.innerHTML = '&times;';
				del.addEventListener('click', function () { row.parentNode.removeChild(row); });
				row.appendChild(input);
				row.appendChild(del);
				return row;
			}

			function renderList(key, values) {
				var c = listContainer(key);
				if (!c) return;
				c.innerHTML = '';
				(values || []).forEach(function (v) { c.appendChild(makeListRow(key, v)); });
			}

			renderList('counties', COUNTIES);
			renderList('regions', REGIONS);

			$all('.aq-loc-add-list').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var key = btn.getAttribute('data-list');
					var c = listContainer(key);
					if (!c) return;
					var row = makeListRow(key, '');
					c.appendChild(row);
					var inp = $('.aq-list-input', row); if (inp) inp.focus();
				});
			});

			/* ---- collect + save ---- */
			function setDeep(obj, dotted, val) {
				var parts = dotted.split('.');
				var node = obj;
				for (var i = 0; i < parts.length - 1; i++) {
					if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) { node[parts[i]] = {}; }
					node = node[parts[i]];
				}
				node[parts[parts.length - 1]] = val;
			}

			function collect() {
				var payload = {};
				// scalar/address fields carry data-key (may be dotted)
				$all('.aq-loc-input[data-key]').forEach(function (inp) {
					setDeep(payload, inp.getAttribute('data-key'), inp.value.trim());
				});
				// towns
				payload.towns = $all('.aq-loc-town', tbody).map(function (tr) {
					var name = ($('.aq-town-name', tr) || {}).value || '';
					var slug = ($('.aq-town-slug', tr) || {}).value || '';
					var county = ($('.aq-town-county', tr) || {}).value || '';
					return { name: name.trim(), slug: slug.trim(), county: county.trim() };
				}).filter(function (t) { return t.name !== ''; });
				// lists
				payload.counties = $all('#aq-loc-counties .aq-list-input').map(function (i) { return i.value.trim(); }).filter(Boolean);
				payload.regions  = $all('#aq-loc-regions .aq-list-input').map(function (i) { return i.value.trim(); }).filter(Boolean);
				return payload;
			}

			function notice(msg, ok) {
				var el = $('#aq-loc-notice');
				if (!el) return;
				el.textContent = msg;
				el.className = 'aq-loc-notice ' + (ok ? 'aq-loc-notice--ok' : 'aq-loc-notice--err');
				el.style.display = 'block';
				if (ok) { window.clearTimeout(notice._t); notice._t = window.setTimeout(function () { el.style.display = 'none'; }, 4000); }
			}

			var saveBtn = $('#aq-loc-save');
			var saving  = $('#aq-loc-saving');
			if (saveBtn) saveBtn.addEventListener('click', function () {
				var payload = collect();
				saveBtn.disabled = true;
				if (saving) saving.style.display = 'inline';
				fetch(REST, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body: JSON.stringify(payload)
				}).then(function (r) {
					return r.json().then(function (j) { return { ok: r.ok, body: j }; });
				}).then(function (res) {
					if (res.ok && res.body && res.body.ok) {
						notice('Saved. ' + (res.body.saved && res.body.saved.towns ? res.body.saved.towns.length : 0) + ' towns stored.', true);
					} else {
						notice('Save failed: ' + ((res.body && (res.body.message || res.body.code)) || 'unknown error'), false);
					}
				}).catch(function (e) {
					notice('Save failed: ' + e.message, false);
				}).then(function () {
					saveBtn.disabled = false;
					if (saving) saving.style.display = 'none';
				});
			});
		})();
		</script>
		<?php
	}
}
