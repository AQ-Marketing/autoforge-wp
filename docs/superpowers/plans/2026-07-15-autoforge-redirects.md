# AutoForge Redirects Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox (`- [ ]`) syntax. This is a PHP/WordPress plugin deployed to a live Pressable staging server — there is no PHP unit-test harness in this repo, so "tests" are `php -l` lint + logged-out HTTP verification against staging (the project's established verification method).

**Goal:** Expand the AutoForge engine's redirect module into a full, dashboard-managed feature (exact + regex redirects, CSV import/export, 404 log) and load AQM's real redirect map so AQM is cutover-ready.

**Architecture:** Client-agnostic engine code in `plugin/aq-core`; per-client rule data in `aq_redirects` + `aq_redirect_log` wp_options. Handler runs on `template_redirect`: exact rules fire always; regex "pattern" rules fire only on would-be-404s (so they can't hijack live pages); unmatched 404s are logged. Dashboard screen follows the existing `AQ_Legal` screen pattern.

**Tech Stack:** PHP 8, WordPress, wp-cli, Pressable (Nginx), paramiko for SSH/SFTP deploy.

---

## File structure

- **Modify** `plugin/aq-core/includes/class-redirects.php` — expand from exact-only to the full handler (exact + pattern + 404 log + guards + legacy migration) and the data layer (`rules()`, `save_rules()`, `log_404()`, `import_csv()`, `export_csv()`).
- **Create** `plugin/aq-core/includes/class-redirects-admin.php` — the AutoForge → Redirects dashboard screen (simple table, Advanced patterns, import/export, recent 404s).
- **Modify** `plugin/aq-core/aq-core.php` — `require_once` + `::register()` the new admin class.
- **Modify** `plugin/aq-core/includes/class-admin-hub.php` — add `'aq-redirects' => 'Redirects'` to the `tabs()` list.
- **Create (scratchpad, not repo)** `aqm-redirects.csv` — AQM's unified redirect map for import.

Data contract (a rule): `['source'=>string,'target'=>string,'code'=>301|302,'match'=>'exact'|'pattern','enabled'=>bool,'notes'=>string]`. Stored as a 0-indexed list in option `aq_redirects`. 404 log: option `aq_redirect_log` = `[ path => ['hits'=>int,'first'=>'Y-m-d','last'=>'Y-m-d'] ]`, capped 500.

---

## Task 1: Expand the handler + data layer (`class-redirects.php`)

**Files:** Modify `plugin/aq-core/includes/class-redirects.php`

- [ ] **Step 1: Replace the file** with the full implementation below.

```php
<?php
/**
 * Legacy-URL redirects (exact + regex patterns), 404 logging, CSV import/export.
 *
 * Engine ships with NO rules — they are PER-CLIENT data in the `aq_redirects`
 * option (list of rule arrays). A dashboard screen (class-redirects-admin.php)
 * manages them; the AutoForge importer / brand.json exact map is migrated in on
 * first run. Pure PHP, no third-party redirect plugin.
 *
 * Handler order (template_redirect): exact rules fire always; pattern rules fire
 * ONLY when the request would 404 (so a broad regex can never hijack a live page);
 * unmatched 404s are logged.
 */

if (!defined('ABSPATH')) { exit; }

class AQ_Redirects {

	const OPTION = 'aq_redirects';
	const LOG    = 'aq_redirect_log';
	const LOG_CAP = 500;

	public static function register(): void {
		add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 1);
		add_action('init', [__CLASS__, 'maybe_migrate']);
	}

	/* ---------------- data ---------------- */

	/** @return array<int,array{source:string,target:string,code:int,match:string,enabled:bool,notes:string}> */
	public static function rules(): array {
		$rules = get_option(self::OPTION, null);
		if (!is_array($rules)) { return []; }
		$out = [];
		foreach ($rules as $r) {
			if (!is_array($r) || empty($r['source']) || !isset($r['target'])) { continue; }
			$out[] = [
				'source'  => (string) $r['source'],
				'target'  => (string) $r['target'],
				'code'    => ((int) ($r['code'] ?? 301)) === 302 ? 302 : 301,
				'match'   => ($r['match'] ?? 'exact') === 'pattern' ? 'pattern' : 'exact',
				'enabled' => !isset($r['enabled']) || !empty($r['enabled']),
				'notes'   => (string) ($r['notes'] ?? ''),
			];
		}
		return $out;
	}

	public static function save_rules(array $rules): bool {
		return update_option(self::OPTION, array_values($rules), false);
	}

	/**
	 * One-time migration: if aq_redirects is unset but a legacy brand.json exact
	 * map exists at aq_site('redirects'), seed the option from it.
	 */
	public static function maybe_migrate(): void {
		if (get_option(self::OPTION, null) !== null) { return; }
		$legacy = function_exists('aq_site') ? aq_site('redirects') : null;
		if (!is_array($legacy) || !$legacy) { update_option(self::OPTION, [], false); return; }
		$rules = [];
		foreach ($legacy as $from => $to) {
			if (!is_string($from) || !is_string($to) || $from === '') { continue; }
			$rules[] = ['source'=>self::norm($from),'target'=>$to,'code'=>301,'match'=>'exact','enabled'=>true,'notes'=>'migrated from brand.json'];
		}
		update_option(self::OPTION, $rules, false);
	}

	/* ---------------- handler ---------------- */

	public static function maybe_redirect(): void {
		$reqpath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		$path    = self::norm($reqpath);
		if ($path === '/') { return; } // never redirect home
		$rules = self::rules();
		if (!$rules) { return; }

		// 1) exact rules (fire regardless of whether a page exists)
		foreach ($rules as $r) {
			if (!$r['enabled'] || $r['match'] !== 'exact') { continue; }
			if (self::norm($r['source']) === $path) {
				self::go($r['target'], $r['code'], $path);
			}
		}

		// 2) pattern rules — only on would-be-404s
		if (is_404()) {
			foreach ($rules as $r) {
				if (!$r['enabled'] || $r['match'] !== 'pattern') { continue; }
				$re = self::delimit($r['source']);
				$ok = @preg_match($re, $reqpath);
				if ($ok === false) { continue; } // invalid regex: skip
				if ($ok === 1) {
					$target = @preg_replace($re, $r['target'], $reqpath);
					if (is_string($target) && $target !== '') { self::go($target, $r['code'], $path); }
				}
			}
			// 3) still 404 → log
			self::log_404($path);
		}
	}

	/** Normalize a path to a single leading + trailing slash, lowercased, no query. */
	private static function norm(string $p): string {
		$p = (string) parse_url($p, PHP_URL_PATH);
		$p = '/' . trim($p, '/');
		if ($p !== '/') { $p .= '/'; }
		return strtolower($p);
	}

	/** Wrap a raw regex body in delimiters if the author didn't. */
	private static function delimit(string $re): string {
		$re = trim($re);
		if ($re === '') { return '/(?!)/'; }
		$d = $re[0];
		// already delimited (e.g. ^…$ authors usually omit delimiters) — detect a
		// matching trailing delimiter among common ones.
		if (in_array($d, ['/', '#', '~', '@'], true) && strrpos($re, $d) > 0) { return $re . 'i'; }
		return '#' . $re . '#i';
	}

	/** Redirect helper with self-redirect + same-host guards. */
	private static function go(string $target, int $code, string $reqpath): void {
		$target = trim($target);
		if ($target === '') { return; }
		// absolute URL: only allow same host (open-redirect guard)
		if (preg_match('#^https?://#i', $target)) {
			$host = parse_url($target, PHP_URL_HOST);
			if ($host && strcasecmp($host, (string) parse_url(home_url(), PHP_URL_HOST)) !== 0) { return; }
			$url = $target;
			$tpath = self::norm((string) parse_url($target, PHP_URL_PATH));
		} else {
			$tpath = self::norm($target);
			$url = home_url('/' . ltrim($target, '/'));
		}
		if ($tpath === $reqpath) { return; } // no self-redirect / loop
		wp_safe_redirect($url, $code);
		exit;
	}

	/* ---------------- 404 log ---------------- */

	public static function log_404(string $path): void {
		$log = get_option(self::LOG, []);
		if (!is_array($log)) { $log = []; }
		$today = gmdate('Y-m-d');
		if (isset($log[$path]) && is_array($log[$path])) {
			$log[$path]['hits'] = (int) ($log[$path]['hits'] ?? 0) + 1;
			$log[$path]['last'] = $today;
		} else {
			$log[$path] = ['hits'=>1,'first'=>$today,'last'=>$today];
		}
		if (count($log) > self::LOG_CAP) {
			uasort($log, fn($a,$b) => strcmp((string)($a['last']??''), (string)($b['last']??'')));
			$log = array_slice($log, count($log) - self::LOG_CAP, null, true);
		}
		update_option(self::LOG, $log, false);
	}

	public static function log(): array {
		$log = get_option(self::LOG, []);
		return is_array($log) ? $log : [];
	}

	public static function clear_log(): void { update_option(self::LOG, [], false); }

	/* ---------------- CSV import / export ---------------- */

	/** Parse unified CSV text → rules; merge by source into existing. Returns [added,updated,skipped]. */
	public static function import_csv(string $csv): array {
		$rules = self::rules();
		$index = [];
		foreach ($rules as $i => $r) { $index[$r['match'].'|'.self::norm($r['source'])] = $i; }
		$added=$updated=$skipped=0;
		$lines = preg_split('/\r\n|\r|\n/', $csv);
		$first = true;
		foreach ($lines as $line) {
			if (trim($line) === '') { continue; }
			$cols = str_getcsv($line);
			if ($first) { $first=false; if (strtolower(trim($cols[0]??'')) === 'source') { continue; } }
			$source = trim((string)($cols[0] ?? ''));
			$target = trim((string)($cols[1] ?? ''));
			if ($source === '' || $source[0] === '#' || $target === '') { $skipped++; continue; }
			$code  = ((int)($cols[2] ?? 301)) === 302 ? 302 : 301;
			$match = strtolower(trim((string)($cols[3] ?? 'exact'))) === 'pattern' ? 'pattern' : 'exact';
			$notes = trim((string)($cols[4] ?? ''));
			$key = $match.'|'.($match==='exact'? self::norm($source) : $source);
			$row = ['source'=>$match==='exact'? self::norm($source):$source,'target'=>$target,'code'=>$code,'match'=>$match,'enabled'=>true,'notes'=>$notes];
			$k2  = $match.'|'.($match==='exact'? self::norm($source):$source);
			if (isset($index[$k2])) { $rules[$index[$k2]] = $row; $updated++; }
			else { $index[$k2] = count($rules); $rules[] = $row; $added++; }
		}
		self::save_rules($rules);
		return ['added'=>$added,'updated'=>$updated,'skipped'=>$skipped];
	}

	public static function export_csv(): string {
		$out = "source,target,code,match,notes\n";
		foreach (self::rules() as $r) {
			$out .= implode(',', array_map([__CLASS__,'csv_cell'], [$r['source'],$r['target'],$r['code'],$r['match'],$r['notes']])) . "\n";
		}
		return $out;
	}

	private static function csv_cell($v): string {
		$v = (string) $v;
		return (strpbrk($v, ",\"\n") !== false) ? '"' . str_replace('"','""',$v) . '"' : $v;
	}
}
```

- [ ] **Step 2: Lint** (on server after upload, Task 5): `php -l includes/class-redirects.php` → "No syntax errors detected".

---

## Task 2: Dashboard screen (`class-redirects-admin.php`)

**Files:** Create `plugin/aq-core/includes/class-redirects-admin.php`

- [ ] **Step 1: Create the file** (mirrors `AQ_Legal`: `manage_options`, `admin_post_*` save, `AQ_Admin_Hub::open/close`). Full code:

```php
<?php
/**
 * AutoForge → Redirects screen. Simple (exact) redirects for everyone; regex
 * "pattern" rules under a collapsed Advanced area; CSV import/export; recent
 * 404s with one-click add. All rule data lives in AQ_Redirects (aq_redirects
 * option); this is the UI only.
 */
if (!defined('ABSPATH')) { exit; }

class AQ_Redirects_Admin {
	const CAP  = 'manage_options';
	const SLUG = 'aq-redirects';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 27);
		add_action('admin_post_aq_redirects_save',   [__CLASS__, 'save']);
		add_action('admin_post_aq_redirects_import', [__CLASS__, 'import']);
		add_action('admin_post_aq_redirects_export', [__CLASS__, 'export']);
		add_action('admin_post_aq_redirects_clearlog',[__CLASS__, 'clear_log']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Redirects', 'Redirects', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/* ---- save (rebuilds the full rule set from the posted tables) ---- */
	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_save')) { wp_die('Not allowed.'); }
		$in = wp_unslash($_POST);
		$rules = [];
		foreach ((array)($in['exact'] ?? []) as $r)   { $rules[] = self::row($r, 'exact'); }
		foreach ((array)($in['pattern'] ?? []) as $r) { $rules[] = self::row($r, 'pattern'); }
		$rules = array_values(array_filter($rules));
		AQ_Redirects::save_rules($rules);
		self::back('saved');
	}

	private static function row($r, string $match) {
		if (!is_array($r)) { return null; }
		$source = trim((string)($r['source'] ?? ''));
		$target = trim((string)($r['target'] ?? ''));
		if ($source === '' || $target === '') { return null; }
		return [
			'source'  => $source,
			'target'  => $target,
			'code'    => ((int)($r['code'] ?? 301)) === 302 ? 302 : 301,
			'match'   => $match,
			'enabled' => !empty($r['enabled']),
			'notes'   => sanitize_text_field((string)($r['notes'] ?? '')),
		];
	}

	/* ---- import ---- */
	public static function import(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_import')) { wp_die('Not allowed.'); }
		if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) { self::back('noimport'); }
		$csv = (string) file_get_contents($_FILES['csv']['tmp_name']);
		if (strlen($csv) > 2*1024*1024) { self::back('toobig'); } // 2MB cap
		$res = AQ_Redirects::import_csv($csv);
		self::back('imported', $res);
	}

	/* ---- export ---- */
	public static function export(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_export')) { wp_die('Not allowed.'); }
		$csv = AQ_Redirects::export_csv();
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="redirects-' . gmdate('Ymd') . '.csv"');
		echo $csv; exit;
	}

	public static function clear_log(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_clearlog')) { wp_die('Not allowed.'); }
		AQ_Redirects::clear_log();
		self::back('logcleared');
	}

	private static function back(string $flag, array $extra = []): void {
		wp_safe_redirect(add_query_arg(array_merge(['page'=>self::SLUG,'msg'=>$flag], $extra), admin_url('admin.php')));
		exit;
	}

	/* ---- render ---- */
	public static function render(): void {
		if (!current_user_can(self::CAP)) { return; }
		$rules   = AQ_Redirects::rules();
		$exact   = array_values(array_filter($rules, fn($r)=>$r['match']==='exact'));
		$pattern = array_values(array_filter($rules, fn($r)=>$r['match']==='pattern'));
		$log     = AQ_Redirects::log();
		uasort($log, fn($a,$b)=> (int)($b['hits']??0) <=> (int)($a['hits']??0));
		AQ_Admin_Hub::open('Redirects', 'Send old or removed URLs to the right page. Simple redirects are one-to-one; patterns (Advanced) match many URLs at once.', self::SLUG);
		self::notice();
		self::styles();

		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		echo '<input type="hidden" name="action" value="aq_redirects_save">';
		wp_nonce_field('aq_redirects_save');

		// Simple (exact)
		echo '<div class="aq-panel"><h2>Simple redirects</h2>';
		echo '<p class="aq-rd-hint">One old address &rarr; one new address. Leave the site off with the toggle to pause a rule without deleting it.</p>';
		self::table('exact', $exact, false);
		echo '</div>';

		// Advanced (pattern)
		echo '<details class="aq-panel"'.($pattern?' open':'').'><summary><strong>Advanced &mdash; pattern rules</strong> ('.count($pattern).')</summary>';
		echo '<p class="aq-rd-hint aq-rd-warn">Pattern rules use regular expressions and match many URLs at once. They only fire when a page would otherwise be "not found," so they can\'t break a live page &mdash; but a wrong pattern can send the wrong traffic. Usually loaded via Import.</p>';
		self::table('pattern', $pattern, true);
		echo '</details>';

		submit_button('Save redirects');
		echo '</form>';

		// Import / Export
		echo '<div class="aq-panel"><h2>Import / Export</h2>';
		echo '<p class="aq-rd-hint">CSV columns: <code>source, target, code, match, notes</code>. Import <strong>merges</strong> by source (updates matches, adds new) &mdash; it never wipes existing rules.</p>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="display:inline-block;margin-right:20px">';
		echo '<input type="hidden" name="action" value="aq_redirects_import">'; wp_nonce_field('aq_redirects_import');
		echo '<input type="file" name="csv" accept=".csv" required> <button class="aq-btn">Import CSV</button></form>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">';
		echo '<input type="hidden" name="action" value="aq_redirects_export">'; wp_nonce_field('aq_redirects_export');
		echo '<button class="aq-btn aq-btn--ghost">Export CSV</button></form>';
		echo '</div>';

		// Recent 404s
		echo '<div class="aq-panel"><h2>Recent dead links (404s)</h2>';
		if (!$log) { echo '<p class="aq-rd-hint">No unmatched 404s recorded yet. URLs visitors hit that have no page and no redirect will show up here.</p>'; }
		else {
			echo '<table class="aq-table"><thead><tr><th>Path</th><th>Hits</th><th>Last seen</th><th></th></tr></thead><tbody>';
			$n=0; foreach ($log as $path=>$m) { if ($n++>=100) break;
				$pre = 'exact['.(count($exact)).']';
				echo '<tr><td><code>'.esc_html($path).'</code></td><td>'.(int)($m['hits']??0).'</td><td>'.esc_html((string)($m['last']??'')).'</td>';
				echo '<td><a class="aq-btn aq-btn--ghost aq-rd-fill" data-src="'.esc_attr($path).'" href="#">Add a redirect</a></td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px">';
			echo '<input type="hidden" name="action" value="aq_redirects_clearlog">'; wp_nonce_field('aq_redirects_clearlog');
			echo '<button class="aq-btn aq-btn--ghost">Clear this log</button></form>';
		}
		echo '</div>';
		self::script();
		AQ_Admin_Hub::close();
	}

	/** Editable table of rows for one match type; JS adds/removes rows. */
	private static function table(string $kind, array $rows, bool $pattern): void {
		echo '<table class="aq-table aq-rd-table" data-kind="'.esc_attr($kind).'"><thead><tr>';
		echo '<th>'.($pattern?'Pattern (regex)':'Old URL').'</th><th>'.($pattern?'Target / replacement':'New URL').'</th><th>Type</th><th>On</th><th>Notes</th><th></th></tr></thead><tbody>';
		if (!$rows) { $rows = []; }
		$i=0; foreach ($rows as $r) { self::tr($kind, $i++, $r); }
		echo '</tbody></table>';
		echo '<p><button type="button" class="button button-secondary aq-rd-add" data-kind="'.esc_attr($kind).'">+ Add '.($pattern?'pattern':'redirect').'</button></p>';
		// row template
		echo '<template class="aq-rd-tpl-'.esc_attr($kind).'">';
		self::tr($kind, '__i__', ['source'=>'','target'=>'','code'=>301,'match'=>$kind,'enabled'=>true,'notes'=>'']);
		echo '</template>';
	}

	private static function tr(string $kind, $i, array $r): void {
		$n = $kind.'['.$i.']';
		echo '<tr>';
		echo '<td><input type="text" name="'.esc_attr($n).'[source]" value="'.esc_attr($r['source']).'" placeholder="'.($kind==='pattern'?'^/old-.*-ma/?$':'/old-page/').'" style="width:100%"></td>';
		echo '<td><input type="text" name="'.esc_attr($n).'[target]" value="'.esc_attr($r['target']).'" placeholder="/new-page/" style="width:100%"></td>';
		echo '<td><select name="'.esc_attr($n).'[code]"><option value="301"'.selected((int)$r['code'],301,false).'>301</option><option value="302"'.selected((int)$r['code'],302,false).'>302</option></select></td>';
		echo '<td style="text-align:center"><input type="checkbox" name="'.esc_attr($n).'[enabled]" value="1" '.checked(!empty($r['enabled']),true,false).'></td>';
		echo '<td><input type="text" name="'.esc_attr($n).'[notes]" value="'.esc_attr($r['notes']).'" style="width:100%"></td>';
		echo '<td><button type="button" class="button-link aq-rd-del" title="Remove">&times;</button></td>';
		echo '</tr>';
	}

	private static function notice(): void {
		$msg = isset($_GET['msg']) ? sanitize_key((string)$_GET['msg']) : '';
		if (!$msg) return;
		$map = ['saved'=>'Redirects saved.','imported'=>'Import complete: '.(int)($_GET['added']??0).' added, '.(int)($_GET['updated']??0).' updated, '.(int)($_GET['skipped']??0).' skipped.','logcleared'=>'404 log cleared.','noimport'=>'No file uploaded.','toobig'=>'That CSV is over the 2MB limit.'];
		$t = $map[$msg] ?? '';
		if ($t) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($t).'</p></div>';
	}

	private static function styles(): void {
		echo '<style>.aq-rd-hint{color:#5b6471;font-size:13px;margin:0 0 12px}.aq-rd-warn{background:#fdf1dd;border:1px solid #f0d9a8;border-radius:8px;padding:8px 12px;color:#7a5310}.aq-rd-table td,.aq-rd-table th{font-size:13px}.aq-rd-table input,.aq-rd-table select{padding:5px 8px;border:1px solid #c9cfd6;border-radius:6px;font-size:13px}details.aq-panel summary{cursor:pointer;font-size:15px}details.aq-panel summary strong{font-family:Poppins,Inter,sans-serif}.aq-rd-del{color:#a30d25;font-size:18px;text-decoration:none;cursor:pointer}</style>';
	}

	private static function script(): void {
		?><script>(function(){
		document.querySelectorAll('.aq-rd-add').forEach(function(btn){btn.addEventListener('click',function(){
			var kind=btn.getAttribute('data-kind');var tbody=document.querySelector('.aq-rd-table[data-kind="'+kind+'"] tbody');
			var tpl=document.querySelector('.aq-rd-tpl-'+kind);if(!tbody||!tpl)return;
			var i=Date.now();var html=tpl.innerHTML.replace(/__i__/g,String(i));
			var tr=document.createElement('tbody');tr.innerHTML=html;tbody.appendChild(tr.firstElementChild);});});
		document.addEventListener('click',function(e){if(e.target.classList&&e.target.classList.contains('aq-rd-del')){e.preventDefault();var tr=e.target.closest('tr');if(tr)tr.remove();}});
		document.querySelectorAll('.aq-rd-fill').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();
			var src=a.getAttribute('data-src');var tbody=document.querySelector('.aq-rd-table[data-kind="exact"] tbody');var tpl=document.querySelector('.aq-rd-tpl-exact');if(!tbody||!tpl)return;
			var i=Date.now();var tr=document.createElement('tbody');tr.innerHTML=tpl.innerHTML.replace(/__i__/g,String(i));var row=tr.firstElementChild;
			row.querySelector('input[name$="[source]"]').value=src;tbody.appendChild(row);row.scrollIntoView({behavior:'smooth',block:'center'});
			row.querySelector('input[name$="[target]"]').focus();});});
		})();</script><?php
	}
}
```

---

## Task 3: Wire the admin class into the engine

**Files:** Modify `plugin/aq-core/aq-core.php`

- [ ] **Step 1:** After the `class-redirects.php` require (line ~80) add:
  `require_once AQ_CORE_DIR . 'includes/class-redirects-admin.php';`
- [ ] **Step 2:** After `AQ_Redirects::register();` (line ~128) add:
  `AQ_Redirects_Admin::register();`

## Task 4: Add the Redirects tab

**Files:** Modify `plugin/aq-core/includes/class-admin-hub.php` (`tabs()` array, ~line 143)

- [ ] **Step 1:** Add `'aq-redirects' => 'Redirects',` after the `'aq-footer'` entry (logical grouping with structural screens).

## Task 5: Deploy engine files to staging + lint

- [ ] **Step 1:** Back up the two server files: `cp includes/class-redirects.php includes/class-redirects.php.bak-20260715`.
- [ ] **Step 2:** SFTP-upload the 4 files to `wp-content/plugins/aq-core/…` (paths mirror repo).
- [ ] **Step 3:** `php -l` each uploaded PHP file on the server → "No syntax errors detected".
- [ ] **Step 4:** Purge caches: `wp eval 'AQ_Performance::purge_caches();'; wp cache flush`.
- [ ] **Step 5:** Confirm migration ran: `wp option get aq_redirects` returns an array (possibly empty `[]`).

## Task 6: Build + import AQM's real map

- [ ] **Step 1:** Generate `aqm-redirects.csv` (scratchpad) from `redirects/redirect-map.csv` (match=exact) + `redirects/redirect-rules-regex.txt` (match=pattern, order preserved) + the 3 dropped-post rules (§4 of spec).
- [ ] **Step 2:** Import via wp-cli on the server: `wp eval 'echo json_encode(AQ_Redirects::import_csv(file_get_contents("aqm-redirects.csv")));'`.
- [ ] **Step 3:** Confirm count: `wp eval 'echo count(AQ_Redirects::rules());'` → ~64 (45 exact + 16 pattern + 3 posts).

## Task 7: Verify on staging (logged-out HTTP)

- [ ] **Step 1:** Re-run the redirect probe (`testredir.py`) — expect 301s now, to correct targets:
  - `/about-aq-marketing/` → `/about/`; `/website-design-development/` → `/services/web-design/`
  - `/web-design-woburn-ma/` → `/locations/woburn-ma/`; `/web-design-natick-ma/` → `/services/web-design/`
  - `/digital-marketing-company-natick-ma/` → `/`; `/industries-we-service/law-firm-web-design-woburn-ma/` → `/industries/law-firms/`
  - `/project/x/` → `/about/`; `/demystifying-your-google-ppc-campaign-costs/` → `/services/google-ads/`
- [ ] **Step 2:** Confirm a live page is untouched: `/services/local-seo/` → 200.
- [ ] **Step 3:** Confirm no redirect loops (each target itself returns 200, not another 301 to itself).
- [ ] **Step 4:** Open AutoForge → Redirects in wp-admin; confirm the table shows the imported rules and the screen renders.

## Task 8: Mirror + show Justin

- [ ] **Step 1:** Ensure the 4 repo files match what's on the server (they're the source).
- [ ] **Step 2:** Summarize for Justin with the verification results + the dashboard URL. Do NOT commit to git or cut a release unless Justin asks.
