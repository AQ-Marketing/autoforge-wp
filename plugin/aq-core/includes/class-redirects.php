<?php
/**
 * Legacy-URL redirects (exact + regex patterns), 404 logging, CSV import/export.
 *
 * The engine ships with NO rules — they are PER-CLIENT data in the `aq_redirects`
 * option (a list of rule arrays). A dashboard screen (class-redirects-admin.php)
 * manages them; any legacy brand.json exact map (aq_site('redirects')) is migrated
 * in on first run. Pure PHP, no third-party redirect plugin.
 *
 * Handler order (template_redirect): exact rules fire always; pattern rules fire
 * ONLY when the request would 404 (so a broad regex can never hijack a live page);
 * unmatched 404s are recorded in a capped log.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Redirects {

	const OPTION  = 'aq_redirects';
	const LOG     = 'aq_redirect_log';
	const LOG_CAP = 500;

	public static function register(): void {
		add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 1);
		add_action('init', [__CLASS__, 'maybe_migrate']);
	}

	/* ---------------- data ---------------- */

	/** @return array<int,array{source:string,target:string,code:int,match:string,enabled:bool,notes:string}> */
	public static function rules(): array {
		$rules = get_option(self::OPTION, null);
		if (!is_array($rules)) {
			return [];
		}
		$out = [];
		foreach ($rules as $r) {
			if (!is_array($r) || empty($r['source']) || !isset($r['target'])) {
				continue;
			}
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
	 * map exists at aq_site('redirects'), seed the option from it. Sets the option
	 * (even to []) so this never runs twice.
	 */
	public static function maybe_migrate(): void {
		if (get_option(self::OPTION, null) !== null) {
			return;
		}
		$legacy = function_exists('aq_site') ? aq_site('redirects') : null;
		if (!is_array($legacy) || !$legacy) {
			update_option(self::OPTION, [], false);
			return;
		}
		$rules = [];
		foreach ($legacy as $from => $to) {
			if (!is_string($from) || !is_string($to) || $from === '') {
				continue;
			}
			$rules[] = ['source' => self::norm($from), 'target' => $to, 'code' => 301, 'match' => 'exact', 'enabled' => true, 'notes' => 'migrated from brand.json'];
		}
		update_option(self::OPTION, $rules, false);
	}

	/* ---------------- handler ---------------- */

	public static function maybe_redirect(): void {
		$reqpath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		$path    = self::norm($reqpath);
		if ($path === '/') {
			return; // never redirect home
		}
		$rules = self::rules();
		if (!$rules) {
			return;
		}

		// 1) exact rules — deliberate 1:1 moves; fire regardless of whether a page exists.
		foreach ($rules as $r) {
			if (!$r['enabled'] || $r['match'] !== 'exact') {
				continue;
			}
			if (self::norm($r['source']) === $path) {
				self::go($r['target'], $r['code'], $path);
			}
		}

		// 2) pattern rules — ONLY on would-be-404s, so a broad regex can't hijack a live page.
		if (is_404()) {
			foreach ($rules as $r) {
				if (!$r['enabled'] || $r['match'] !== 'pattern') {
					continue;
				}
				$re = self::delimit($r['source']);
				$ok = @preg_match($re, $reqpath);
				if ($ok === false) {
					continue; // invalid regex: skip, never fatal
				}
				if ($ok === 1) {
					$target = @preg_replace($re, $r['target'], $reqpath);
					if (is_string($target) && $target !== '') {
						self::go($target, $r['code'], $path);
					}
				}
			}
			// 3) still unmatched → log the dead link.
			self::log_404($path);
		}
	}

	/** Normalize a path to a single leading + trailing slash, lowercased, no query. */
	private static function norm(string $p): string {
		$p = (string) parse_url($p, PHP_URL_PATH);
		$p = '/' . trim($p, '/');
		if ($p !== '/') {
			$p .= '/';
		}
		return strtolower($p);
	}

	/** Wrap a raw regex body in delimiters if the author didn't supply them. */
	private static function delimit(string $re): string {
		$re = trim($re);
		if ($re === '') {
			return '/(?!)/';
		}
		$d = $re[0];
		if (in_array($d, ['/', '#', '~', '@'], true) && strrpos($re, $d) > 0) {
			return $re . 'i'; // already delimited
		}
		return '#' . $re . '#i';
	}

	/** Redirect helper with self-redirect + same-host (open-redirect) guards. */
	private static function go(string $target, int $code, string $reqpath): void {
		$target = trim($target);
		if ($target === '') {
			return;
		}
		if (preg_match('#^https?://#i', $target)) {
			$host = parse_url($target, PHP_URL_HOST);
			if ($host && strcasecmp($host, (string) parse_url(home_url(), PHP_URL_HOST)) !== 0) {
				return; // off-site target: refuse (open-redirect guard)
			}
			$url   = $target;
			$tpath = self::norm((string) parse_url($target, PHP_URL_PATH));
		} else {
			$tpath = self::norm($target);
			$url   = home_url('/' . ltrim($target, '/'));
		}
		if ($tpath === $reqpath) {
			return; // no self-redirect / loop
		}
		wp_safe_redirect($url, $code);
		exit;
	}

	/* ---------------- 404 log ---------------- */

	public static function log_404(string $path): void {
		$log = get_option(self::LOG, []);
		if (!is_array($log)) {
			$log = [];
		}
		$today = gmdate('Y-m-d');
		if (isset($log[$path]) && is_array($log[$path])) {
			$log[$path]['hits'] = (int) ($log[$path]['hits'] ?? 0) + 1;
			$log[$path]['last'] = $today;
		} else {
			$log[$path] = ['hits' => 1, 'first' => $today, 'last' => $today];
		}
		if (count($log) > self::LOG_CAP) {
			uasort($log, fn($a, $b) => strcmp((string) ($a['last'] ?? ''), (string) ($b['last'] ?? '')));
			$log = array_slice($log, count($log) - self::LOG_CAP, null, true);
		}
		update_option(self::LOG, $log, false);
	}

	public static function log(): array {
		$log = get_option(self::LOG, []);
		return is_array($log) ? $log : [];
	}

	public static function clear_log(): void {
		update_option(self::LOG, [], false);
	}

	/* ---------------- CSV import / export ---------------- */

	/**
	 * Parse unified CSV text → rules; MERGE by source into the existing set
	 * (update on match, append when new). Never wipes unlisted rules.
	 *
	 * @return array{added:int,updated:int,skipped:int}
	 */
	public static function import_csv(string $csv): array {
		$rules = self::rules();
		$index = [];
		foreach ($rules as $i => $r) {
			$key = $r['match'] . '|' . ($r['match'] === 'exact' ? self::norm($r['source']) : $r['source']);
			$index[$key] = $i;
		}
		$added = $updated = $skipped = 0;
		$lines = preg_split('/\r\n|\r|\n/', $csv);
		$first = true;
		foreach ((array) $lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$cols = str_getcsv($line);
			if ($first) {
				$first = false;
				if (strtolower(trim((string) ($cols[0] ?? ''))) === 'source') {
					continue; // header row
				}
			}
			$source = trim((string) ($cols[0] ?? ''));
			$target = trim((string) ($cols[1] ?? ''));
			if ($source === '' || $source[0] === '#' || $target === '') {
				$skipped++;
				continue;
			}
			$code  = ((int) ($cols[2] ?? 301)) === 302 ? 302 : 301;
			$match = strtolower(trim((string) ($cols[3] ?? 'exact'))) === 'pattern' ? 'pattern' : 'exact';
			$notes = trim((string) ($cols[4] ?? ''));
			$src   = $match === 'exact' ? self::norm($source) : $source;
			$row   = ['source' => $src, 'target' => $target, 'code' => $code, 'match' => $match, 'enabled' => true, 'notes' => $notes];
			$key   = $match . '|' . $src;
			if (isset($index[$key])) {
				$rules[$index[$key]] = $row;
				$updated++;
			} else {
				$index[$key] = count($rules);
				$rules[]     = $row;
				$added++;
			}
		}
		self::save_rules($rules);
		return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
	}

	public static function export_csv(): string {
		$out = "source,target,code,match,notes\n";
		foreach (self::rules() as $r) {
			$out .= implode(',', array_map([__CLASS__, 'csv_cell'], [$r['source'], $r['target'], $r['code'], $r['match'], $r['notes']])) . "\n";
		}
		return $out;
	}

	private static function csv_cell($v): string {
		$v = (string) $v;
		return (strpbrk($v, ",\"\n") !== false) ? '"' . str_replace('"', '""', $v) . '"' : $v;
	}
}
