<?php
/**
 * AQ Importer — build this site from a GitHub repo authored for this theme/plugin.
 *
 * A repo carries the canonical content (content/pages/*.json) plus the image
 * files it references. Importing on a fresh install: download the repo, sideload
 * the referenced images into the WP media library (matched by filename, so the
 * page JSON's `image` values bind via AQ_Content_Sync::resolve_image), then run
 * the existing AQ_Content_Sync::import_path() over content/pages to build every
 * page. The live theme templates supply the design.
 *
 * Repo access: public repos download with no auth (codeload ZIP); private repos
 * use the GitHub token stored in Integrations (AQ_Integrations::github_token()
 * / AQ_GITHUB_TOKEN). Capability-gated on manage_options; only ever fetches from
 * github.com hosts (owner/repo are validated), so it can't be pointed elsewhere.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Importer {

	const CAP = 'manage_options';
	const OPT = 'aq_importer'; // remembers the last repo + branch (convenience only)

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 22);
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Import', 'Import', self::CAP, 'aq-import', [__CLASS__, 'render']);
	}

	/* ---------------- screen ---------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$opt    = get_option(self::OPT, []);
		$opt    = is_array($opt) ? $opt : [];
		$repo   = (string) ($opt['repo'] ?? '');
		$branch = (string) ($opt['branch'] ?? 'main');
		$token  = class_exists('AQ_Integrations') && AQ_Integrations::github_token() !== '';
		$int_url = admin_url('admin.php?page=aq-integrations');
		AQ_Admin_Hub::open('Import', 'Build this site from a GitHub repo authored for this theme — pages, content, and images.', 'aq-import');
		?>
		<style>
			.aq-imp-field { margin-bottom: 14px; }
			.aq-imp-field label { display:block; font-weight:600; color:#0d1014; margin-bottom:5px; }
			.aq-imp-field input { width:100%; max-width:520px; padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; }
			.aq-imp-field input[name=branch] { max-width:200px; }
			.aq-imp-log { margin-top:14px; background:#0f1426; color:#cfd6f5; border-radius:10px; padding:12px 14px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; line-height:1.6; max-height:340px; overflow:auto; white-space:pre-wrap; display:none; }
			.aq-imp-hint { font-size:12px; color:#5b6471; margin:6px 0 0; }
		</style>
		<div class="aq-panel">
			<h2>Import from GitHub</h2>
			<p class="aq-imp-hint" style="margin-top:0;">
				Point this at a repo authored for this theme (a <code>content/pages/</code> folder of page JSON plus the image files it references).
				It imports the images into your media library, then builds every page. Re-running updates existing pages by URL.
				<?php if ($token) : ?>A GitHub token is configured (private repos OK).<?php else : ?>No GitHub token set — works for public repos. For private repos, add a token under <a href="<?php echo esc_url($int_url); ?>">Integrations</a>.<?php endif; ?>
			</p>
			<div class="aq-imp-field">
				<label for="aq-imp-repo">Repository URL</label>
				<input type="text" id="aq-imp-repo" name="repo" placeholder="https://github.com/owner/repo" value="<?php echo esc_attr($repo); ?>" autocomplete="off">
			</div>
			<div class="aq-imp-field">
				<label for="aq-imp-branch">Branch</label>
				<input type="text" id="aq-imp-branch" name="branch" placeholder="main" value="<?php echo esc_attr($branch !== '' ? $branch : 'main'); ?>" autocomplete="off">
			</div>
			<p>
				<button type="button" class="aq-btn" id="aq-imp-run">Import site from repo</button>
				<span id="aq-imp-status" style="margin-left:10px;font-size:13px;color:#5b6471;"></span>
			</p>
			<p class="aq-imp-hint">Importing replaces page content from the repo. Run it on a fresh install, or to refresh content from the canonical source. Large repos can take a minute.</p>
			<pre class="aq-imp-log" id="aq-imp-log"></pre>
		</div>

		<script>
		(function () {
			var url   = '<?php echo esc_url_raw(rest_url('aq/v1/import-repo')); ?>';
			var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
			var btn   = document.getElementById('aq-imp-run');
			var st    = document.getElementById('aq-imp-status');
			var logEl = document.getElementById('aq-imp-log');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				var repo = document.getElementById('aq-imp-repo').value.trim();
				var branch = document.getElementById('aq-imp-branch').value.trim() || 'main';
				if (!repo) { st.textContent = 'Enter a repository URL.'; st.style.color = '#d63638'; return; }
				if (!window.confirm('Import this site from ' + repo + ' (' + branch + ')? This will create/update pages and import images.')) { return; }
				btn.disabled = true; st.textContent = 'Importing… this can take a minute.'; st.style.color = '#5b6471';
				logEl.style.display = 'none'; logEl.textContent = '';
				fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ repo: repo, branch: branch }) })
					.then(function (r) { return r.json().then(function (d) { return { httpOk: r.ok, status: r.status, d: d || {} }; }); })
					.then(function (res) {
						btn.disabled = false;
						var d = res.d;
						// A successful import returns { ok:true, ... }. A failure (e.g. a private
						// repo the token can't read) comes back as a WP_Error { code, message,
						// data:{status} } with a non-2xx status and NO ok flag — surface that
						// message instead of falsely reporting "0 pages, 0 images".
						if (!res.httpOk || d.ok !== true) {
							st.textContent = '✕ ' + (d.message || d.code || ('Import failed (HTTP ' + res.status + ').')); st.style.color = '#d63638';
						} else {
							st.textContent = '✓ ' + (d.pages || 0) + ' pages, ' + (d.images || 0) + ' images imported' + (d.skipped ? ', ' + d.skipped + ' images already present' : '') + '.';
							st.style.color = '#1a8f4f';
						}
						if (d && d.log && d.log.length) { logEl.style.display = 'block'; logEl.textContent = d.log.join('\n'); }
					})
					.catch(function (e) { btn.disabled = false; st.textContent = '✕ ' + e.message; st.style.color = '#d63638'; });
			});
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	/* ---------------- REST ---------------- */

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/import-repo', [
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can(self::CAP); },
			'callback'            => [__CLASS__, 'rest_run'],
		]);
	}

	public static function rest_run(WP_REST_Request $req) {
		if (!class_exists('AQ_Content_Sync')) {
			return new WP_Error('aq_no_sync', 'Content sync is unavailable.', ['status' => 500]);
		}
		@set_time_limit(0);

		$body   = $req->get_json_params();
		$repo_in = trim((string) ($body['repo'] ?? ''));
		$branch  = trim((string) ($body['branch'] ?? 'main'));
		if ($branch === '') {
			$branch = 'main';
		}

		// Parse owner/repo from a github.com URL; reject anything else (no SSRF).
		if (!preg_match('#github\.com[/:]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#i', $repo_in, $m)) {
			return new WP_Error('aq_bad_repo', 'Enter a GitHub repository URL like https://github.com/owner/repo.', ['status' => 400]);
		}
		$owner = $m[1];
		$repo  = $m[2];
		update_option(self::OPT, ['repo' => $repo_in, 'branch' => $branch], false);

		$log   = [];
		$token = class_exists('AQ_Integrations') ? AQ_Integrations::github_token() : '';

		// 1. Download the repo ZIP (token → private API zipball; else public codeload).
		if ($token !== '') {
			$zip_url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zipball/' . rawurlencode($branch);
			$headers = ['Authorization' => 'Bearer ' . $token, 'User-Agent' => 'aq-core', 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
		} else {
			$zip_url = 'https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zip/refs/heads/' . rawurlencode($branch);
			$headers = ['User-Agent' => 'aq-core'];
		}
		$resp = wp_remote_get($zip_url, ['timeout' => 180, 'redirection' => 5, 'headers' => $headers]);
		if (is_wp_error($resp)) {
			return new WP_Error('aq_dl', 'Could not download the repo: ' . $resp->get_error_message(), ['status' => 502]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code !== 200) {
			$b   = json_decode((string) wp_remote_retrieve_body($resp), true);
			$msg = is_array($b) && isset($b['message']) ? $b['message'] : ('HTTP ' . $code);
			return new WP_Error('aq_dl', 'GitHub download failed: ' . $msg . ($code === 404 ? ' (check the repo/branch, or add a token for a private repo).' : ''), ['status' => 502]);
		}
		$zip_bytes = wp_remote_retrieve_body($resp);
		if (strlen($zip_bytes) < 64) {
			return new WP_Error('aq_dl', 'Downloaded archive was empty.', ['status' => 502]);
		}

		// 2. Save + extract.
		if (!class_exists('ZipArchive')) {
			return new WP_Error('aq_zip', 'The PHP zip extension is required to import.', ['status' => 500]);
		}
		$base     = trailingslashit(get_temp_dir()) . 'aq-import-' . wp_generate_password(10, false, false);
		$zip_path = $base . '.zip';
		$dest     = $base;
		if (false === file_put_contents($zip_path, $zip_bytes)) {
			return new WP_Error('aq_io', 'Could not write the temporary archive.', ['status' => 500]);
		}
		unset($zip_bytes);
		wp_mkdir_p($dest);
		$za = new ZipArchive();
		if ($za->open($zip_path) !== true) {
			@unlink($zip_path);
			return new WP_Error('aq_zip', 'Could not open the downloaded archive.', ['status' => 500]);
		}
		$za->extractTo($dest);
		$za->close();
		@unlink($zip_path);

		// GitHub archives nest everything under one top-level folder.
		$root  = $dest;
		$dirs  = array_values(array_filter((array) glob($dest . '/*'), 'is_dir'));
		if (count($dirs) === 1) {
			$root = $dirs[0];
		}
		$pages_dir = $root . '/content/pages';
		if (!is_dir($pages_dir)) {
			self::rrmdir($dest);
			return new WP_Error('aq_struct', 'The repo has no content/pages directory — is this an aq-core content repo?', ['status' => 400]);
		}

		// 3. Sideload referenced images into the media library (before pages, so
		//    AQ_Content_Sync::resolve_image() can bind them by filename). Brand
		//    logo files (named in content/brand.json) aren't referenced in page
		//    JSON, so merge them in too — apply_brand() resolves them to IDs after.
		$referenced = self::referenced_images($pages_dir);
		foreach (self::brand_logo_files($root) as $lf) {
			$referenced[] = $lf;
		}
		$referenced = array_values(array_unique($referenced));
		$imgmap     = self::repo_image_map($root);
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$img_done = 0;
		$img_skip = 0;
		foreach ($referenced as $bname) {
			if (!isset($imgmap[$bname])) {
				$log[] = 'image not found in repo: ' . $bname;
				continue;
			}
			if (self::media_exists($bname)) {
				$img_skip++;
				continue;
			}
			$tmp = trailingslashit(get_temp_dir()) . wp_generate_password(6, false, false) . '-' . $bname;
			if (!@copy($imgmap[$bname], $tmp)) {
				$log[] = 'could not stage image: ' . $bname;
				continue;
			}
			$att = media_handle_sideload(['name' => $bname, 'tmp_name' => $tmp], 0);
			if (is_wp_error($att)) {
				@unlink($tmp);
				$log[] = 'image import failed (' . $bname . '): ' . $att->get_error_message();
				continue;
			}
			$img_done++;
		}
		$log[] = '— images: ' . $img_done . ' imported, ' . $img_skip . ' already present —';

		// 4. Build the pages from the canonical JSON.
		$pages = 0;
		try {
			$page_log = AQ_Content_Sync::import_path($pages_dir, false);
			foreach ((array) $page_log as $line) {
				$log[] = is_string($line) ? $line : wp_json_encode($line);
				if (is_string($line) && strpos($line, 'Imported ') === 0) {
					$pages++;
				}
			}
		} catch (\Throwable $e) {
			self::rrmdir($dest);
			return new WP_Error('aq_import', 'Page import error: ' . $e->getMessage(), ['status' => 500]);
		}

		// 4b. Seed the client's brand/site config (content/brand.json) into the
		//     aq_site_config option. Lives in the DB → safe across plugin updates.
		$brand_applied = self::apply_brand($root, $log);

		// 4c. Deliver the client's compiled CSS/JS into the active stub theme so
		//     the design ships per client (the theme PHP stays identical).
		$assets_applied = self::deliver_theme_assets($root, $log);

		// 5. Clean up.
		self::rrmdir($dest);

		return rest_ensure_response([
			'ok'      => true,
			'pages'   => $pages,
			'images'  => $img_done,
			'skipped' => $img_skip,
			'brand'   => $brand_applied,
			'assets'  => $assets_applied,
			'log'     => $log,
		]);
	}

	/**
	 * Read content/brand.json and replace the aq_site_config overlay with it.
	 * Logo files (logo.file / logo.fileDark) are resolved to media-library
	 * attachment IDs after image sideload. Returns true when applied.
	 */
	private static function apply_brand(string $root, array &$log): bool {
		$file = $root . '/content/brand.json';
		if (!is_readable($file) || !class_exists('AQ_Site_Config')) {
			return false;
		}
		$brand = json_decode((string) file_get_contents($file), true);
		if (!is_array($brand)) {
			$log[] = 'brand.json present but could not be parsed — skipped.';
			return false;
		}
		// Resolve logo filenames → attachment IDs (images already sideloaded above).
		if (class_exists('AQ_Content_Sync')) {
			foreach (['file' => 'id', 'fileDark' => 'idDark'] as $src => $dst) {
				$fname = $brand['logo'][$src] ?? '';
				if ($fname) {
					$info = AQ_Content_Sync::image_info(basename((string) $fname));
					if (!empty($info['id'])) {
						$brand['logo'][$dst] = (int) $info['id'];
					}
				}
			}
		}
		AQ_Site_Config::replace($brand);
		$log[] = '— brand config applied from content/brand.json —';
		return true;
	}

	/**
	 * Copy the client's compiled front-end assets from the repo into the active
	 * theme's assets/ dir. Looks for conventional drop locations in the content
	 * repo. Returns the number of asset files delivered.
	 */
	private static function deliver_theme_assets(string $root, array &$log): int {
		$targets = [
			// repo source (first existing wins) => theme-relative destination
			['css' => ['content/assets/main.css', 'dist/main.css', 'assets/css/main.css'], 'dest' => 'assets/css/main.css'],
			['js'  => ['content/assets/site.js', 'dist/site.js', 'assets/js/site.js'],     'dest' => 'assets/js/site.js'],
		];
		$theme_dir = get_stylesheet_directory();
		$done = 0;
		foreach ($targets as $t) {
			$sources = $t['css'] ?? $t['js'] ?? [];
			foreach ($sources as $rel) {
				$src = $root . '/' . $rel;
				if (is_readable($src)) {
					$dst = trailingslashit($theme_dir) . $t['dest'];
					wp_mkdir_p(dirname($dst));
					if (@copy($src, $dst)) {
						$done++;
						$log[] = 'delivered ' . $t['dest'] . ' from ' . $rel;
					} else {
						$log[] = 'could not write theme asset ' . $t['dest'] . ' (theme dir not writable?)';
					}
					break;
				}
			}
		}
		if ($done === 0) {
			$log[] = 'no compiled CSS/JS found in repo (looked in content/assets, dist, assets) — theme keeps existing assets.';
		}
		return $done;
	}

	/* ---------------- helpers ---------------- */

	/** Lowercase basenames of the logo files named in content/brand.json. */
	private static function brand_logo_files(string $root): array {
		$file = $root . '/content/brand.json';
		if (!is_readable($file)) {
			return [];
		}
		$brand = json_decode((string) file_get_contents($file), true);
		$out = [];
		foreach (['file', 'fileDark'] as $k) {
			$v = $brand['logo'][$k] ?? '';
			if (is_string($v) && $v !== '') {
				$out[] = strtolower(basename($v));
			}
		}
		return $out;
	}

	/** Distinct lowercase basenames of image files referenced in the page JSON. */
	private static function referenced_images(string $pages_dir): array {
		$out = [];
		foreach ((array) glob($pages_dir . '/*.json') as $jf) {
			$raw = (string) file_get_contents($jf);
			if (preg_match_all('/"([^"\\\\]+\.(?:jpe?g|png|webp|avif|gif))"/i', $raw, $mm)) {
				foreach ($mm[1] as $val) {
					$out[strtolower(basename($val))] = true;
				}
			}
		}
		return array_keys($out);
	}

	/** Map of lowercase filename => absolute path for every image file in the repo. */
	private static function repo_image_map(string $root): array {
		$map  = [];
		$exts = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];
		try {
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if ($f->isFile() && in_array(strtolower($f->getExtension()), $exts, true)) {
					$map[strtolower($f->getFilename())] = $f->getPathname();
				}
			}
		} catch (\Throwable $e) {
			// ignore — return whatever we collected
		}
		return $map;
	}

	/** True if a media-library attachment already exists for this filename. */
	private static function media_exists(string $basename): bool {
		if (!class_exists('AQ_Content_Sync')) {
			return false;
		}
		$info = AQ_Content_Sync::image_info($basename);
		return !empty($info['id']);
	}

	/** Recursively delete a temp directory. */
	private static function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $f) {
			$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($dir);
	}
}
