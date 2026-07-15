<?php
/**
 * AutoForge → Redirects screen. Simple (exact) redirects for everyone; regex
 * "pattern" rules under a collapsed Advanced area; CSV import/export; and a
 * "recent 404s" panel with one-click add. All rule data lives in AQ_Redirects
 * (the aq_redirects option); this class is the UI + save/import/export plumbing.
 *
 * Client-agnostic: no site owns this — rules are per-site data, exactly like
 * every other dashboard-editable value.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Redirects_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'aq-redirects';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 27);
		add_action('admin_post_aq_redirects_save',     [__CLASS__, 'save']);
		add_action('admin_post_aq_redirects_import',   [__CLASS__, 'import']);
		add_action('admin_post_aq_redirects_export',   [__CLASS__, 'export']);
		add_action('admin_post_aq_redirects_clearlog', [__CLASS__, 'clear_log']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Redirects', 'Redirects', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/* ---------------- save ---------------- */

	/** Rebuilds the full rule set from the posted exact + pattern tables. */
	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_save')) {
			wp_die('Not allowed.');
		}
		$in    = wp_unslash($_POST);
		$rules = [];
		foreach ((array) ($in['exact'] ?? []) as $r) {
			$row = self::row($r, 'exact');
			if ($row) {
				$rules[] = $row;
			}
		}
		foreach ((array) ($in['pattern'] ?? []) as $r) {
			$row = self::row($r, 'pattern');
			if ($row) {
				$rules[] = $row;
			}
		}
		AQ_Redirects::save_rules($rules);
		self::back('saved');
	}

	/** @return array|null */
	private static function row($r, string $match) {
		if (!is_array($r)) {
			return null;
		}
		$source = trim((string) ($r['source'] ?? ''));
		$target = trim((string) ($r['target'] ?? ''));
		if ($source === '' || $target === '') {
			return null;
		}
		return [
			'source'  => $source,
			'target'  => $target,
			'code'    => ((int) ($r['code'] ?? 301)) === 302 ? 302 : 301,
			'match'   => $match,
			'enabled' => !empty($r['enabled']),
			'notes'   => sanitize_text_field((string) ($r['notes'] ?? '')),
		];
	}

	/* ---------------- import ---------------- */

	public static function import(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_import')) {
			wp_die('Not allowed.');
		}
		if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
			self::back('noimport');
		}
		if ((int) ($_FILES['csv']['size'] ?? 0) > 2 * 1024 * 1024) {
			self::back('toobig'); // 2MB cap
		}
		$csv = (string) file_get_contents($_FILES['csv']['tmp_name']);
		$res = AQ_Redirects::import_csv($csv);
		self::back('imported', $res);
	}

	/* ---------------- export ---------------- */

	public static function export(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_export')) {
			wp_die('Not allowed.');
		}
		$csv = AQ_Redirects::export_csv();
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="redirects-' . gmdate('Ymd') . '.csv"');
		echo $csv;
		exit;
	}

	public static function clear_log(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_redirects_clearlog')) {
			wp_die('Not allowed.');
		}
		AQ_Redirects::clear_log();
		self::back('logcleared');
	}

	private static function back(string $flag, array $extra = []): void {
		wp_safe_redirect(add_query_arg(array_merge(['page' => self::SLUG, 'msg' => $flag], $extra), admin_url('admin.php')));
		exit;
	}

	/* ---------------- render ---------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$rules   = AQ_Redirects::rules();
		$exact   = array_values(array_filter($rules, fn($r) => $r['match'] === 'exact'));
		$pattern = array_values(array_filter($rules, fn($r) => $r['match'] === 'pattern'));
		$log     = AQ_Redirects::log();
		uasort($log, fn($a, $b) => (int) ($b['hits'] ?? 0) <=> (int) ($a['hits'] ?? 0));

		AQ_Admin_Hub::open('Redirects', 'Send old or removed URLs to the right page. Simple redirects are one-to-one; patterns (Advanced) match many URLs at once.', self::SLUG);
		self::notice();
		self::styles();

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="aq_redirects_save">';
		wp_nonce_field('aq_redirects_save');

		echo '<div class="aq-panel"><h2>Simple redirects</h2>';
		echo '<p class="aq-rd-hint">One old address &rarr; one new address. Use the <strong>On</strong> toggle to pause a rule without deleting it.</p>';
		self::table('exact', $exact, false);
		echo '</div>';

		echo '<details class="aq-panel"' . ($pattern ? ' open' : '') . '><summary><strong>Advanced &mdash; pattern rules</strong> (' . count($pattern) . ')</summary>';
		echo '<p class="aq-rd-hint aq-rd-warn">Pattern rules use regular expressions and match many URLs at once. They only fire when a page would otherwise be &ldquo;not found,&rdquo; so they can&rsquo;t break a live page &mdash; but a wrong pattern can send the wrong traffic. Usually loaded via Import.</p>';
		self::table('pattern', $pattern, true);
		echo '</details>';

		submit_button('Save redirects');
		echo '</form>';

		echo '<div class="aq-panel"><h2>Import / Export</h2>';
		echo '<p class="aq-rd-hint">CSV columns: <code>source, target, code, match, notes</code>. Import <strong>merges</strong> by source (updates matches, adds new) &mdash; it never wipes existing rules.</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="display:inline-block;margin-right:20px">';
		echo '<input type="hidden" name="action" value="aq_redirects_import">';
		wp_nonce_field('aq_redirects_import');
		echo '<input type="file" name="csv" accept=".csv,text/csv" required> <button class="aq-btn">Import CSV</button></form>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
		echo '<input type="hidden" name="action" value="aq_redirects_export">';
		wp_nonce_field('aq_redirects_export');
		echo '<button class="aq-btn aq-btn--ghost">Export CSV</button></form>';
		echo '</div>';

		echo '<div class="aq-panel"><h2>Recent dead links (404s)</h2>';
		if (!$log) {
			echo '<p class="aq-rd-hint">No unmatched 404s recorded yet. URLs visitors hit that have no page and no redirect will show up here.</p>';
		} else {
			echo '<table class="aq-table"><thead><tr><th>Path</th><th>Hits</th><th>Last seen</th><th></th></tr></thead><tbody>';
			$n = 0;
			foreach ($log as $path => $m) {
				if ($n++ >= 100) {
					break;
				}
				echo '<tr><td><code>' . esc_html((string) $path) . '</code></td><td>' . (int) ($m['hits'] ?? 0) . '</td><td>' . esc_html((string) ($m['last'] ?? '')) . '</td>';
				echo '<td><a class="aq-btn aq-btn--ghost aq-rd-fill" data-src="' . esc_attr((string) $path) . '" href="#">Add a redirect</a></td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
			echo '<input type="hidden" name="action" value="aq_redirects_clearlog">';
			wp_nonce_field('aq_redirects_clearlog');
			echo '<button class="aq-btn aq-btn--ghost">Clear this log</button></form>';
		}
		echo '</div>';

		self::script();
		AQ_Admin_Hub::close();
	}

	/** Editable table of rows for one match type; JS adds/removes rows. */
	private static function table(string $kind, array $rows, bool $pattern): void {
		echo '<table class="aq-table aq-rd-table" data-kind="' . esc_attr($kind) . '"><thead><tr>';
		echo '<th>' . ($pattern ? 'Pattern (regex)' : 'Old URL') . '</th><th>' . ($pattern ? 'Target / replacement' : 'New URL') . '</th><th>Type</th><th>On</th><th>Notes</th><th></th></tr></thead><tbody>';
		$i = 0;
		foreach ($rows as $r) {
			self::tr($kind, $i++, $r);
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button button-secondary aq-rd-add" data-kind="' . esc_attr($kind) . '">+ Add ' . ($pattern ? 'pattern' : 'redirect') . '</button></p>';
		echo '<template class="aq-rd-tpl-' . esc_attr($kind) . '">';
		self::tr($kind, '__i__', ['source' => '', 'target' => '', 'code' => 301, 'match' => $kind, 'enabled' => true, 'notes' => '']);
		echo '</template>';
	}

	private static function tr(string $kind, $i, array $r): void {
		$n = $kind . '[' . $i . ']';
		echo '<tr>';
		echo '<td><input type="text" name="' . esc_attr($n) . '[source]" value="' . esc_attr((string) $r['source']) . '" placeholder="' . ($kind === 'pattern' ? '^/old-.*-ma/?$' : '/old-page/') . '" style="width:100%"></td>';
		echo '<td><input type="text" name="' . esc_attr($n) . '[target]" value="' . esc_attr((string) $r['target']) . '" placeholder="/new-page/" style="width:100%"></td>';
		echo '<td><select name="' . esc_attr($n) . '[code]"><option value="301"' . selected((int) $r['code'], 301, false) . '>301</option><option value="302"' . selected((int) $r['code'], 302, false) . '>302</option></select></td>';
		echo '<td style="text-align:center"><input type="checkbox" name="' . esc_attr($n) . '[enabled]" value="1" ' . checked(!empty($r['enabled']), true, false) . '></td>';
		echo '<td><input type="text" name="' . esc_attr($n) . '[notes]" value="' . esc_attr((string) $r['notes']) . '" style="width:100%"></td>';
		echo '<td><button type="button" class="button-link aq-rd-del" title="Remove">&times;</button></td>';
		echo '</tr>';
	}

	private static function notice(): void {
		$msg = isset($_GET['msg']) ? sanitize_key((string) $_GET['msg']) : '';
		if (!$msg) {
			return;
		}
		$map = [
			'saved'      => 'Redirects saved.',
			'imported'   => 'Import complete: ' . (int) ($_GET['added'] ?? 0) . ' added, ' . (int) ($_GET['updated'] ?? 0) . ' updated, ' . (int) ($_GET['skipped'] ?? 0) . ' skipped.',
			'logcleared' => '404 log cleared.',
			'noimport'   => 'No file uploaded.',
			'toobig'     => 'That CSV is over the 2MB limit.',
		];
		$t = $map[$msg] ?? '';
		if ($t) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($t) . '</p></div>';
		}
	}

	private static function styles(): void {
		echo '<style>'
			. '.aq-rd-hint{color:#5b6471;font-size:13px;margin:0 0 12px}'
			. '.aq-rd-warn{background:#fdf1dd;border:1px solid #f0d9a8;border-radius:8px;padding:8px 12px;color:#7a5310}'
			. '.aq-rd-table td,.aq-rd-table th{font-size:13px}'
			. '.aq-rd-table input,.aq-rd-table select{padding:5px 8px;border:1px solid #c9cfd6;border-radius:6px;font-size:13px}'
			. '.aq-rd-table td select{width:100%;min-width:74px;padding-right:6px}'
			/* keep the Type / On / delete columns from being squeezed by the wide text inputs */
			. '.aq-rd-table th:nth-child(3),.aq-rd-table td:nth-child(3){width:88px}'
			. '.aq-rd-table th:nth-child(4),.aq-rd-table td:nth-child(4){width:48px;text-align:center}'
			. '.aq-rd-table th:nth-child(6),.aq-rd-table td:nth-child(6){width:32px}'
			. 'details.aq-panel summary{cursor:pointer;font-size:15px}'
			. 'details.aq-panel summary strong{font-family:Poppins,Inter,sans-serif}'
			. '.aq-rd-del{color:#a30d25;font-size:18px;text-decoration:none;cursor:pointer}'
			. '</style>';
	}

	private static function script(): void {
		?>
		<script>(function(){
			document.querySelectorAll('.aq-rd-add').forEach(function(btn){
				btn.addEventListener('click',function(){
					var kind=btn.getAttribute('data-kind');
					var tbody=document.querySelector('.aq-rd-table[data-kind="'+kind+'"] tbody');
					var tpl=document.querySelector('.aq-rd-tpl-'+kind);
					if(!tbody||!tpl)return;
					var i=Date.now();
					var host=document.createElement('tbody');
					host.innerHTML=tpl.innerHTML.replace(/__i__/g,String(i));
					tbody.appendChild(host.firstElementChild);
				});
			});
			document.addEventListener('click',function(e){
				if(e.target.classList&&e.target.classList.contains('aq-rd-del')){
					e.preventDefault();
					var tr=e.target.closest('tr');
					if(tr)tr.remove();
				}
			});
			document.querySelectorAll('.aq-rd-fill').forEach(function(a){
				a.addEventListener('click',function(e){
					e.preventDefault();
					var src=a.getAttribute('data-src');
					var tbody=document.querySelector('.aq-rd-table[data-kind="exact"] tbody');
					var tpl=document.querySelector('.aq-rd-tpl-exact');
					if(!tbody||!tpl)return;
					var i=Date.now();
					var host=document.createElement('tbody');
					host.innerHTML=tpl.innerHTML.replace(/__i__/g,String(i));
					var row=host.firstElementChild;
					row.querySelector('input[name$="[source]"]').value=src;
					tbody.appendChild(row);
					row.scrollIntoView({behavior:'smooth',block:'center'});
					row.querySelector('input[name$="[target]"]').focus();
				});
			});
		})();</script>
		<?php
	}
}
