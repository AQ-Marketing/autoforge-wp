<?php
/**
 * The Claude Code round-trip: pages live as JSON files in the repo
 * (content/pages/<url-path>.json) and sync to WordPress via WP-CLI.
 *
 *   wp aq import <file-or-dir>     repo JSON → pages (canonical direction)
 *   wp aq export <dir> [--path=/]  pages → repo JSON (reconcile wp-admin edits)
 *
 * JSON shape (see content/schema/page.schema.json):
 * {
 *   "path": "/services/buyer-home-inspection/",
 *   "title": "Buyer Home Inspection",
 *   "status": "publish",
 *   "seo": { "title", "description", "canonical", "noindex", "ogImage", "services": [...] },
 *   "sections": [ { "type": "hero", "v": 1, ...fields } ]
 * }
 */

class AQ_Content_Sync {

	public static function register(): void {
		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('aq', __CLASS__);
		}

		/*
		 * REST import — the canonical round-trip over HTTP (Studio's WP-CLI
		 * only runs inside its app window, and Pressable has no shell here).
		 * Accepts one page/post JSON object, an array of them, or {items:[…]},
		 * and upserts each. Capability-gated; authenticate with an Application
		 * Password. This is the proper home for what used to be a code snippet.
		 */
		add_action('rest_api_init', function () {
			register_rest_route('aq/v1', '/import', [
				'methods'             => 'POST',
				'permission_callback' => function () { return current_user_can('aq_agency'); },
				'callback'            => [__CLASS__, 'rest_import'],
			]);

			/*
			 * REST export — the live->files reconcile transport. Pressable has no
			 * shell, so `wp aq export` can't run there; this returns canonical
			 * (normalized) page JSON over HTTP for migration/pull-from-staging.mjs
			 * to write back into content/pages/*.json. aq_agency-gated, read-only.
			 */
			register_rest_route('aq/v1', '/export', [
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can('aq_agency'); },
				'callback'            => [__CLASS__, 'rest_export'],
			]);
		});

		/*
		 * The import endpoint is gated on the narrow `aq_agency` capability
		 * rather than the broad `edit_pages` (held by every Editor). Grant it to
		 * administrators only, via a filter — no persisted role change, fully
		 * reversible, and it keeps the trusted SFTP/Application-Password deploy
		 * working (that user is an admin). NOTE: this endpoint is for trusted
		 * agency deploys of vetted repo JSON only. AI/editor proposals must NOT
		 * POST client JSON here — they go through the propose->approve review
		 * path so unverified content can never be written directly.
		 */
		add_filter('user_has_cap', function ($allcaps) {
			if (!empty($allcaps['manage_options'])) {
				$allcaps['aq_agency'] = true;
			}
			return $allcaps;
		});

		/*
		 * Local-dev HTTP trigger: when wp-config defines AQ_AUTOIMPORT_DIR
		 * (never in production), hitting any URL with ?aq_import=1 imports
		 * the whole content dir and prints a plain-text report. Studio's
		 * WP-CLI only runs inside its app window, so this gives scripts and
		 * Claude Code a way to sync without the GUI.
		 */
		if (defined('AQ_AUTOIMPORT_DIR')) {
			add_action('init', function () {
				if (!isset($_GET['aq_import'])) {
					return;
				}
				header('Content-Type: text/plain; charset=utf-8');
				try {
					// ?aq_file=<name.json> imports just that one file (fast dev
					// iteration); otherwise the whole content dir is imported.
					$target = AQ_AUTOIMPORT_DIR;
					if (!empty($_GET['aq_file'])) {
						$one = basename((string) wp_unslash($_GET['aq_file']));
						$target = rtrim(AQ_AUTOIMPORT_DIR, '/\\') . '/' . $one;
					}
					$log = self::import_path($target);
					echo implode("\n", $log) . "\nDONE\n";
				} catch (\Throwable $e) {
					http_response_code(500);
					echo 'IMPORT FAILED: ' . $e->getMessage() . "\n";
				}
				exit;
			}, 1);
		}
	}

	/**
	 * Import a JSON file or directory. Returns log lines; throws on failure.
	 * Shared by the WP-CLI command and the local-dev HTTP trigger.
	 */
	public static function import_path(string $target, bool $dry = false): array {
		$files = is_dir($target) ? self::find_json($target) : [$target];
		if (!$files) {
			throw new \RuntimeException("No JSON files found at {$target}");
		}

		if (get_option('permalink_structure') !== '/%postname%/') {
			update_option('permalink_structure', '/%postname%/');
			flush_rewrite_rules();
		}

		$log = [];
		foreach ($files as $file) {
			$data = json_decode((string) file_get_contents($file), true);
			if (!is_array($data) || empty($data['path'])) {
				$log[] = "SKIP {$file}: invalid JSON or missing \"path\"";
				continue;
			}
			if ($dry) {
				$log[] = "OK (dry run): {$data['path']}";
				continue;
			}
			// Skip pages whose live content already matches the repo — the same
			// canonical comparison `reconcile` uses. Keeps re-imports incremental:
			// only new/changed pages are written. Posts always upsert (cheap, and
			// serialize_page is page-shaped).
			if (($data['type'] ?? 'page') === 'page') {
				$existing = self::page_by_path($data['path']);
				if ($existing) {
					// serialize_page() never emits a top-level `type` for pages, so drop
					// it from the repo side before comparing — otherwise a file that
					// carries "type":"page" would never match and re-import every pass.
					$repo = self::normalize_page($data);
					unset($repo['type']);
					if (wp_json_encode(self::serialize_page($existing)) === wp_json_encode($repo)) {
						$log[] = "Unchanged {$data['path']}";
						continue;
					}
				}
			}
			$id = self::upsert_page($data);
			$log[] = "Imported {$data['path']} -> post {$id}";
		}
		return $log;
	}

	/**
	 * Import page JSON file(s) into WordPress.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : A page JSON file or a directory of them (recursive).
	 *
	 * [--dry-run]
	 * : Parse and validate only.
	 */
	public function import(array $args, array $assoc): void {
		try {
			$log = self::import_path($args[0], !empty($assoc['dry-run']));
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
			return;
		}
		foreach ($log as $line) {
			\WP_CLI::log($line);
		}
		\WP_CLI::success(count($log) . ' pages processed.');
	}

	/**
	 * Import images into the WordPress media library.
	 *
	 * Skips files that already exist in the library (matched by filename).
	 * Only imports the base source files — WordPress generates sub-sizes
	 * (ka-480, ka-768, ka-1280) automatically on upload.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Directory containing image files (jpg, png, webp).
	 *
	 * [--dry-run]
	 * : List files that would be imported without importing.
	 */
	public function images(array $args, array $assoc): void {
		$dir = rtrim($args[0], '/\\');
		if (!is_dir($dir)) {
			\WP_CLI::error("Not a directory: {$dir}");
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$dry     = !empty($assoc['dry-run']);
		$exts    = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
		$skipped = 0;
		$imported = 0;

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($it as $file) {
			if (!$file->isFile()) continue;
			$ext = strtolower($file->getExtension());
			if (!in_array($ext, $exts, true)) continue;

			$basename = $file->getBasename();

			// Skip variant files (e.g. home-hero-480.webp) — only import originals
			if (preg_match('/-\d{3,4}\.(webp|avif|jpg|jpeg|png)$/i', $basename)) {
				continue;
			}
			// Skip AVIF files — WP doesn't handle AVIF as source; import WebP/JPG/PNG
			if ($ext === 'avif') {
				continue;
			}

			$existing = self::resolve_image($basename);
			if ($existing) {
				$skipped++;
				continue;
			}

			if ($dry) {
				\WP_CLI::log("Would import: {$basename}");
				$imported++;
				continue;
			}

			$tmp = wp_tempnam($basename);
			copy($file->getPathname(), $tmp);

			$file_array = [
				'name'     => $basename,
				'tmp_name' => $tmp,
			];

			$att_id = media_handle_sideload($file_array, 0);
			if (is_wp_error($att_id)) {
				\WP_CLI::warning("Failed: {$basename} — " . $att_id->get_error_message());
				@unlink($tmp);
				continue;
			}

			$imported++;
			\WP_CLI::log("Imported: {$basename} → attachment {$att_id}");
		}

		\WP_CLI::success("{$imported} imported, {$skipped} already existed.");
	}

	/**
	 * Export pages to JSON files.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Output directory (repo content/pages).
	 *
	 * [--path=<url-path>]
	 * : Export a single page by URL path instead of all pages.
	 */
	public function export(array $args, array $assoc): void {
		$dir = rtrim($args[0], '/\\');
		if (!is_dir($dir)) {
			\WP_CLI::error("Not a directory: {$dir}");
		}

		$pages = !empty($assoc['path'])
			? array_filter([self::page_by_path($assoc['path'])])
			: get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish']);

		foreach ($pages as $page) {
			$data = self::serialize_page($page);
			// Repo filenames are FLAT, using "--" for path separators
			// (services--buyer-home-inspection.json), not nested directories.
			$rel  = $data['path'] === '/' ? 'index' : str_replace('/', '--', trim($data['path'], '/'));
			$out  = $dir . '/' . $rel . '.json';
			wp_mkdir_p(dirname($out));
			file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			\WP_CLI::log("Exported {$data['path']} → {$out}");
		}
		\WP_CLI::success(count($pages) . ' pages exported.');
	}

	/**
	 * Verify the export round-trip is stable against the repo JSON.
	 *
	 * For every page file, compares the NORMALIZED live serialize() output to
	 * the NORMALIZED repo JSON. A clean run proves the editor/AI/export write
	 * path will not corrupt content/pages/*.json. This is the in-WordPress twin
	 * of migration/verify-normalize.mjs — that one only sees the files; this one
	 * exercises REAL ACF, so it is the authoritative gate. Run it on staging
	 * (where the media library is populated) for an image-accurate result.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : The repo content/pages directory to compare against.
	 *
	 * [--drop-images]
	 * : Ignore image fields (use locally on Studio, whose media library is empty
	 *   so every image resolves to "" and would otherwise show as a diff).
	 *
	 * [--verbose]
	 * : Print the differing JSON for each mismatching page.
	 */
	public function roundtrip(array $args, array $assoc): void {
		$dir = rtrim($args[0] ?? '', '/\\');
		if (!is_dir($dir)) {
			\WP_CLI::error("Not a directory: {$dir}");
		}
		$drop    = !empty($assoc['drop-images']);
		$verbose = !empty($assoc['verbose']);
		$ok = 0;
		$bad = 0;
		$missing = 0;
		foreach (self::find_json($dir) as $file) {
			$data = json_decode((string) file_get_contents($file), true);
			if (!is_array($data) || empty($data['path']) || ($data['type'] ?? 'page') !== 'page') {
				continue;
			}
			$page = self::page_by_path($data['path']);
			if (!$page) {
				$missing++;
				\WP_CLI::warning("No page for {$data['path']}");
				continue;
			}
			$live = self::serialize_page($page);           // already normalized
			$repo = self::normalize_page($data);
			if ($drop) {
				$live = self::strip_images($live);
				$repo = self::strip_images($repo);
			}
			$ljson = wp_json_encode($live);
			$rjson = wp_json_encode($repo);
			if ($ljson === $rjson) {
				$ok++;
			} else {
				$bad++;
				\WP_CLI::log("DIFF {$data['path']}");
				if ($verbose) {
					\WP_CLI::log("  live: {$ljson}");
					\WP_CLI::log("  repo: {$rjson}");
				}
			}
		}
		\WP_CLI::log("ok={$ok} diff={$bad} missing={$missing}");
		if ($bad) {
			\WP_CLI::error("{$bad} page(s) do not round-trip cleanly — the write path is NOT safe to enable.");
		}
		\WP_CLI::success('All pages round-trip cleanly.');
	}

	/** Drop image fields from a normalized page (for --drop-images comparison). */
	private static function strip_images(array $page): array {
		foreach (($page['sections'] ?? []) as $i => $sec) {
			unset($sec['image']);
			$page['sections'][$i] = $sec;
		}
		return $page;
	}

	/**
	 * REST callback: POST /wp-json/aq/v1/import
	 * Body may be a single JSON object, a bare array, or {items:[…]}.
	 * Does NOT touch the permalink structure (unlike the WP-CLI path) so it
	 * is safe to run against a live site whose post permalinks include /blog/.
	 */
	public static function rest_import(\WP_REST_Request $req) {
		$body = $req->get_json_params();
		if (is_array($body) && isset($body['items']) && is_array($body['items'])) {
			$items = $body['items'];
		} elseif (is_array($body) && isset($body['path'])) {
			$items = [$body];
		} elseif (is_array($body) && isset($body[0])) {
			$items = $body;
		} else {
			$items = [];
		}

		$log = [];
		$written = 0;
		foreach ($items as $data) {
			$errors = self::validate_item($data);
			if ($errors) {
				$label = (is_array($data) && !empty($data['path'])) ? $data['path'] : '(no path)';
				$log[] = "REJECT {$label}: " . implode('; ', $errors);
				continue;
			}
			try {
				$id = self::upsert_page($data);
				$written++;
				$log[] = "OK {$data['path']} -> {$id}";
			} catch (\Throwable $e) {
				$log[] = "FAIL {$data['path']}: " . $e->getMessage();
			}
		}
		return new \WP_REST_Response(
			['ok' => true, 'received' => count($items), 'written' => $written, 'log' => $log],
			200
		);
	}

	/**
	 * REST callback: GET /wp-json/aq/v1/export[?path=/x/]
	 * Returns canonical (normalized) page JSON for one page (?path=) or all
	 * published pages. The live->files reconcile transport for hosts without a
	 * shell; migration/pull-from-staging.mjs writes the result back to the repo.
	 */
	public static function rest_export(\WP_REST_Request $req) {
		$path = (string) $req->get_param('path');
		if ($path !== '') {
			$page  = self::page_by_path($path);
			$pages = $page ? [$page] : [];
		} else {
			$pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish']);
		}
		$out = [];
		foreach ($pages as $page) {
			$out[] = self::serialize_page($page); // already normalized
		}
		return new \WP_REST_Response(['ok' => true, 'count' => count($out), 'pages' => $out], 200);
	}

	/**
	 * Public write path for the visual editor: persist a sections array (JSON
	 * shape, each row keyed by "type") to a page's ACF flexible content. Reuses
	 * apply_sections so the editor and the import path share one mapping
	 * (type→layout, single-image filename→attachment ID).
	 */
	public static function update_sections(int $id, array $sections): void {
		self::apply_sections($id, $sections);
	}

	/**
	 * Public read path for the visual editor: return a page's sections in the
	 * canonical JSON shape (acf_fc_layout→type, single image ID→filename) so the
	 * editor edits the same structure the repo JSON uses.
	 */
	public static function read_sections(int $id): array {
		if (!function_exists('get_field')) {
			return [];
		}
		$out = [];
		foreach ((array) get_field('sections', $id) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$type = $row['acf_fc_layout'] ?? '';
			unset($row['acf_fc_layout']);
			if (!empty($row['image']) && is_numeric($row['image'])) {
				$row['image'] = self::serialize_image((int) $row['image']);
			}
			$out[] = array_merge(['type' => $type], $row);
		}
		return $out;
	}

	/* ---------------- internals ---------------- */

	/** Abort an import: WP-CLI prints+exits; HTTP/REST throws (caught by caller). */
	private static function fail(string $msg): void {
		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::error($msg);
		}
		throw new \RuntimeException($msg);
	}

	private static function find_json(string $dir): array {
		$found = [];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if ($f->isFile() && strtolower($f->getExtension()) === 'json') {
				$found[] = $f->getPathname();
			}
		}
		sort($found); // parents (shorter paths) tend to sort first; resolve_parent creates stubs anyway
		return $found;
	}

	private static function page_by_path(string $path) {
		if ($path === '/' || $path === '') {
			$front = (int) get_option('page_on_front');
			return $front ? get_post($front) : null;
		}
		return get_page_by_path(trim($path, '/'), OBJECT, 'page');
	}

	/**
	 * Validate an incoming page/post item before any write. Returns a list of
	 * human-readable errors (empty = valid). Rejects malformed shapes and
	 * unknown section types so a write path can never persist arbitrary data
	 * (defence in depth — upsert_page also sanitizes via normalize_page).
	 */
	private static function validate_item($data): array {
		if (!is_array($data)) {
			return ['not an object'];
		}
		$errors = [];
		if (empty($data['path']) || !is_string($data['path'])) {
			$errors[] = 'missing or non-string "path"';
		}
		$type = $data['type'] ?? 'page';
		if (!in_array($type, ['page', 'post'], true)) {
			$errors[] = "invalid type \"{$type}\"";
		}
		$status = $data['status'] ?? 'publish';
		if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
			$errors[] = "invalid status \"{$status}\"";
		}
		if (isset($data['seo']) && !is_array($data['seo'])) {
			$errors[] = '"seo" must be an object';
		}
		if (isset($data['sections'])) {
			if (!is_array($data['sections'])) {
				$errors[] = '"sections" must be an array';
			} else {
				$known = self::field_spec()['sections'] ?? [];
				foreach ($data['sections'] as $i => $row) {
					if (!is_array($row) || empty($row['type'])) {
						$errors[] = "section #{$i} missing type";
					} elseif ($known && !isset($known[$row['type']])) {
						$errors[] = "section #{$i} unknown type \"{$row['type']}\"";
					}
				}
			}
		}
		return $errors;
	}

	private static function upsert_page(array $data): int {
		// Sanitize: reduce client-supplied JSON to known top-level keys, known
		// section types, and registered fields only (unknown keys dropped),
		// using the same canonicalizer the export path uses so import and
		// export agree. Never persist raw client JSON.
		$data = self::normalize_page($data);
		if (($data['type'] ?? 'page') === 'post') {
			return self::upsert_post($data);
		}

		$path     = '/' . trim((string) $data['path'], '/') . '/';
		$is_front = $path === '//' || trim((string) $data['path'], '/') === '';

		if ($is_front) {
			$slug   = 'home';
			$parent = 0;
		} else {
			$segments = explode('/', trim($path, '/'));
			$slug     = array_pop($segments);
			$parent   = self::resolve_parent($segments);
		}

		$existing = $is_front
			? self::page_by_path('/')
			: get_page_by_path(trim($path, '/'), OBJECT, 'page');

		$postarr = [
			'post_type'   => 'page',
			'post_status' => $data['status'] ?? 'publish',
			'post_title'  => (string) ($data['title'] ?? $slug),
			'post_name'   => $slug,
			'post_parent' => $parent,
		];

		if ($existing) {
			$postarr['ID'] = $existing->ID;
			$id = wp_update_post($postarr, true);
		} else {
			$id = wp_insert_post($postarr, true);
		}
		if (is_wp_error($id)) {
			self::fail("Failed to save {$path}: " . $id->get_error_message());
		}

		if ($is_front) {
			update_option('show_on_front', 'page');
			update_option('page_on_front', $id);
		}

		self::apply_seo($id, (array) ($data['seo'] ?? []));
		self::apply_sections($id, (array) ($data['sections'] ?? []));

		return (int) $id;
	}

	/**
	 * Upsert a blog post (type:"post"). Unlike pages, the article body lives
	 * in post_content (rendered by single.php inside the prose-content wrapper)
	 * and date/category/excerpt/featured-image are native post fields. The SEO
	 * ACF fields still apply (the SEO field group covers posts).
	 */
	private static function upsert_post(array $data): int {
		$slug = basename(rtrim((string) $data['path'], '/'));

		$found    = get_posts([
			'name'        => $slug,
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
		]);
		$existing = $found ? $found[0] : null;

		$postarr = [
			'post_type'    => 'post',
			'post_status'  => $data['status'] ?? 'publish',
			'post_title'   => (string) ($data['title'] ?? $slug),
			'post_name'    => $slug,
			'post_content' => (string) ($data['body'] ?? ''),
			'post_excerpt' => (string) ($data['excerpt'] ?? ''),
		];

		if (!empty($data['date'])) {
			$ts = strtotime((string) $data['date']);
			if ($ts) {
				$postarr['post_date']     = gmdate('Y-m-d H:i:s', $ts);
				$postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
			}
		}

		if ($existing) {
			$postarr['ID'] = $existing->ID;
			$id = wp_update_post($postarr, true);
		} else {
			$id = wp_insert_post($postarr, true);
		}
		if (is_wp_error($id)) {
			self::fail("Failed to save post {$slug}: " . $id->get_error_message());
		}
		$id = (int) $id;

		// Category — create on demand, then set as the post's sole category.
		if (!empty($data['category'])) {
			$cat   = $data['category'];
			$name  = is_array($cat) ? (string) ($cat['name'] ?? ($cat['slug'] ?? '')) : (string) $cat;
			$cslug = is_array($cat) ? (string) ($cat['slug'] ?? sanitize_title($name)) : sanitize_title((string) $cat);
			$term  = get_term_by('slug', $cslug, 'category');
			if (!$term) {
				$res = wp_insert_term($name !== '' ? $name : $cslug, 'category', ['slug' => $cslug]);
				$tid = is_wp_error($res) ? 0 : (int) $res['term_id'];
			} else {
				$tid = (int) $term->term_id;
			}
			if ($tid) {
				wp_set_post_terms($id, [$tid], 'category', false);
			}
		}

		// Featured image from the media library (filename → attachment ID).
		if (!empty($data['hero'])) {
			$hero = $data['hero'];
			$file = is_array($hero) ? (string) ($hero['file'] ?? '') : (string) $hero;
			$att  = self::resolve_image($file);
			if ($att) {
				set_post_thumbnail($id, $att);
				if (is_array($hero) && !empty($hero['alt'])) {
					update_post_meta($att, '_wp_attachment_image_alt', (string) $hero['alt']);
				}
			}
		}

		self::apply_seo($id, (array) ($data['seo'] ?? []));

		return $id;
	}

	private static function resolve_parent(array $segments): int {
		$parent = 0;
		$walked = '';
		foreach ($segments as $seg) {
			$walked = ltrim($walked . '/' . $seg, '/');
			$page = get_page_by_path($walked, OBJECT, 'page');
			if (!$page) {
				$pid = wp_insert_post([
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => ucwords(str_replace('-', ' ', $seg)),
					'post_name'   => $seg,
					'post_parent' => $parent,
				], true);
				if (is_wp_error($pid)) {
					self::fail("Failed creating parent stub {$walked}: " . $pid->get_error_message());
				}
				$parent = (int) $pid;
			} else {
				$parent = $page->ID;
			}
		}
		return $parent;
	}

	private static function apply_seo(int $id, array $seo): void {
		if (!function_exists('update_field')) {
			return;
		}
		update_field('field_aq_seo_seo_title', (string) ($seo['title'] ?? ''), $id);
		update_field('field_aq_seo_seo_description', (string) ($seo['description'] ?? ''), $id);
		update_field('field_aq_seo_seo_canonical', (string) ($seo['canonical'] ?? ''), $id);
		update_field('field_aq_seo_seo_noindex', !empty($seo['noindex']), $id);
		update_field('field_aq_seo_seo_og_image', (string) ($seo['ogImage'] ?? ''), $id);

		$services = [];
		foreach ((array) ($seo['services'] ?? []) as $svc) {
			$services[] = [
				'name'         => (string) ($svc['name'] ?? ''),
				'description'  => (string) ($svc['description'] ?? ''),
				'url'          => (string) ($svc['url'] ?? ''),
				'service_type' => (string) ($svc['serviceType'] ?? ($svc['service_type'] ?? '')),
			];
		}
		update_field('field_aq_seo_jsonld_services', $services, $id);
	}

	private static function apply_sections(int $id, array $sections): void {
		if (!function_exists('update_field')) {
			return;
		}
		$rows = [];
		foreach ($sections as $section) {
			if (empty($section['type'])) {
				continue;
			}
			$row = $section;
			unset($row['type'], $row['v']);
			$row['acf_fc_layout'] = $section['type'];

			if (isset($row['image']) && is_string($row['image'])) {
				$row['image'] = self::resolve_image($row['image']);
			}

			$rows[] = $row;
		}
		// Clear ALL prior flexible-content meta first so a re-import never leaves
		// orphaned sub-field meta behind (a section that changed shape/type/index
		// between imports otherwise keeps stale nested repeater rows — e.g. an
		// empty paragraph or a leftover intro). delete_field() alone does not
		// recurse into nested repeater meta, so wipe every sections_* key.
		// Fetch every meta key for the post and filter in PHP — LIKE with an
		// esc_like() backslash escape is a MySQL-ism SQLite (Studio) ignores.
		global $wpdb;
		$keys = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d",
			$id
		));
		foreach ((array) $keys as $k) {
			if ($k === 'sections' || $k === '_sections'
				|| strpos($k, 'sections_') === 0 || strpos($k, '_sections_') === 0) {
				delete_post_meta($id, $k);
			}
		}
		update_field('field_aq_sections', $rows, $id);
	}

	/**
	 * Resolve an image filename (e.g. "home-hero.webp") to a media library
	 * attachment ID. Searches by filename in the attachment metadata. Returns
	 * 0 if not found — the template gracefully renders nothing.
	 */
	private static function resolve_image(string $filename): int {
		if (!$filename) {
			return 0;
		}

		// An editor/AI-set image stores its media-library attachment ID directly
		// (the picker returns an ID) — the robust, unambiguous reference. Repo
		// JSON uses a portable basename, resolved below.
		if (is_numeric($filename)) {
			return (int) $filename;
		}

		$base = basename($filename);
		global $wpdb;
		// Match attachments whose _wp_attached_file basename EXACTLY equals $base:
		// stored without a folder (meta_value = base) or inside a year/month or
		// site folder (meta_value LIKE '%/base'). Anchored on the trailing
		// segment so "hero.webp" can't bind "my-hero.webp" or "hero.webp.bak"
		// (the old unanchored '%base%' could). Fetch two rows to DETECT — and
		// refuse — an ambiguous match rather than silently picking the wrong
		// image (a pixel-parity break).
		$rows = $wpdb->get_col($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_wp_attached_file'
			   AND (meta_value = %s OR meta_value LIKE %s)
			 LIMIT 2",
			$base,
			'%/' . $wpdb->esc_like($base)
		));
		if (count($rows) === 1) {
			return (int) $rows[0];
		}
		if (count($rows) > 1) {
			error_log("AQ_Content_Sync::resolve_image: ambiguous filename \"{$base}\" matches multiple attachments — refusing to guess. Store the attachment ID instead.");
		}
		return 0;
	}

	/**
	 * Serialize an attachment ID back to a portable filename for export.
	 * Returns the basename of the attached file (e.g. "home-hero.webp").
	 */
	private static function serialize_image(int $id): string {
		$file = get_post_meta($id, '_wp_attached_file', true);
		return $file ? basename($file) : '';
	}

	/**
	 * Resolve an image filename to display info for the editor's media picker:
	 * the attachment id, a thumbnail URL, and a larger preview URL. Returns
	 * empty strings (id 0) when the filename has no matching attachment.
	 */
	public static function image_info(string $filename): array {
		$id = self::resolve_image($filename);
		if (!$id) {
			return ['id' => 0, 'url' => '', 'thumb' => ''];
		}
		return [
			'id'    => $id,
			'url'   => (string) wp_get_attachment_image_url($id, 'large'),
			'thumb' => (string) wp_get_attachment_image_url($id, 'thumbnail'),
		];
	}

	private static function serialize_page(WP_Post $page): array {
		$front = (int) get_option('page_on_front') === $page->ID;
		$path  = $front ? '/' : (parse_url((string) get_permalink($page), PHP_URL_PATH) ?: '/');

		$seo = [
			'title'       => (string) get_field('seo_title', $page->ID),
			'description' => (string) get_field('seo_description', $page->ID),
			'canonical'   => (string) get_field('seo_canonical', $page->ID),
			'noindex'     => (bool) get_field('seo_noindex', $page->ID),
			'ogImage'     => (string) get_field('seo_og_image', $page->ID),
		];
		$services = get_field('jsonld_services', $page->ID);
		if (is_array($services) && $services) {
			$seo['services'] = array_map(fn($s) => [
				'name'        => $s['name'] ?? '',
				'description' => $s['description'] ?? '',
				'url'         => $s['url'] ?? '',
				'serviceType' => $s['service_type'] ?? '',
			], $services);
		}

		$sections = [];
		foreach ((array) get_field('sections', $page->ID) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$type = $row['acf_fc_layout'] ?? '';
			unset($row['acf_fc_layout']);

			if (!empty($row['image']) && is_numeric($row['image'])) {
				$row['image'] = self::serialize_image((int) $row['image']);
			}

			$sections[] = array_merge(['type' => $type], $row);
		}

		// Canonicalize before returning: ACF hands back sub-fields in
		// registration order and includes every declared field even when empty,
		// which would otherwise rewrite the curated content/pages JSON on every
		// export. normalize_page() reorders to the field-order spec, drops empty
		// values, casts bool/int fields, and stamps the schema version.
		return self::normalize_page([
			'path'     => $path,
			'title'    => $page->post_title,
			'status'   => $page->post_status,
			'seo'      => $seo,
			'sections' => $sections,
		]);
	}

	/* ---------------- round-trip normalizer ---------------- */

	/**
	 * Canonical field-order spec (config/field-order.json), cached. This is the
	 * SAME file the JS tooling (migration/lib/normalize-page.mjs) reads, so the
	 * PHP runtime and the repo scripts canonicalize identically.
	 */
	private static function field_spec(): array {
		static $spec = null;
		if ($spec === null) {
			$path = dirname(__DIR__) . '/config/field-order.json';
			$raw  = is_readable($path) ? (string) file_get_contents($path) : '';
			$spec = $raw ? (json_decode($raw, true) ?: []) : [];
		}
		return $spec;
	}

	/** Does this value carry information worth keeping? (mirrors JS isEmpty) */
	private static function nz($v): bool {
		if ($v === null || $v === '' || $v === false) {
			return false;
		}
		if (is_int($v) || is_float($v)) {
			return $v != 0;
		}
		if (is_array($v)) {
			return count($v) > 0;
		}
		return true;
	}

	/** Is an array a 0..n-1 integer-keyed list (vs an associative map)? */
	private static function is_list(array $a): bool {
		$i = 0;
		foreach ($a as $k => $_) {
			if ($k !== $i++) {
				return false;
			}
		}
		return true;
	}

	private static function cast_bool($v): bool {
		return $v === true || $v === 1 || $v === '1';
	}

	private static function cast_int($v) {
		if (is_int($v) || is_float($v)) {
			return $v;
		}
		if (is_string($v) && trim($v) !== '' && is_numeric($v)) {
			return $v + 0;
		}
		return $v;
	}

	/** Recursively drop empty values, preserving key order. */
	private static function clean_value($v) {
		if (is_array($v)) {
			$list = self::is_list($v);
			$out  = [];
			foreach ($v as $k => $vv) {
				$cv = self::clean_value($vv);
				if (self::nz($cv)) {
					$out[$k] = $cv;
				}
			}
			return $list ? array_values($out) : $out;
		}
		return $v;
	}

	/** Order an object's keys by $order, then extras alphabetically; drop empties. */
	private static function order_object($obj, array $order): array {
		$out = [];
		if (!is_array($obj)) {
			return $out;
		}
		foreach ($order as $k) {
			if (array_key_exists($k, $obj)) {
				$cv = self::clean_value($obj[$k]);
				if (self::nz($cv)) {
					$out[$k] = $cv;
				}
			}
		}
		$extra = array_keys($obj);
		sort($extra);
		foreach ($extra as $k) {
			if (in_array($k, $order, true) || array_key_exists($k, $out)) {
				continue;
			}
			$cv = self::clean_value($obj[$k]);
			if (self::nz($cv)) {
				$out[$k] = $cv;
			}
		}
		return $out;
	}

	private static function normalize_seo($seo): array {
		$spec    = self::field_spec();
		$order   = $spec['seo'] ?? [];
		$seoBool = $spec['seoBool'] ?? [];
		$out     = [];
		if (!is_array($seo)) {
			return $out;
		}
		foreach ($order as $k) {
			if (!array_key_exists($k, $seo)) {
				continue;
			}
			if ($k === 'services') {
				$arr = [];
				foreach ((array) $seo['services'] as $svc) {
					$row = self::order_object($svc, $spec['seoService'] ?? []);
					if (self::nz($row)) {
						$arr[] = $row;
					}
				}
				if ($arr) {
					$out['services'] = $arr;
				}
			} elseif (in_array($k, $seoBool, true)) {
				if (self::cast_bool($seo[$k])) {
					$out[$k] = true;
				}
			} else {
				$cv = self::clean_value($seo[$k]);
				if (self::nz($cv)) {
					$out[$k] = $cv;
				}
			}
		}
		$extra = array_keys($seo);
		sort($extra);
		foreach ($extra as $k) {
			if (in_array($k, $order, true) || array_key_exists($k, $out)) {
				continue;
			}
			$cv = self::clean_value($seo[$k]);
			if (self::nz($cv)) {
				$out[$k] = $cv;
			}
		}
		return $out;
	}

	private static function normalize_section($section): ?array {
		if (!is_array($section) || !self::nz($section['type'] ?? null)) {
			return null;
		}
		$spec = self::field_spec();
		$type = $section['type'];
		$defs = $spec['sections'][$type] ?? null;
		$out  = ['type' => $type];
		$v    = $section['v'] ?? null;
		$out['v'] = ($v !== null && $v !== '') ? $v : ($defs['v'] ?? 1);

		if (!$defs) {
			$keys = array_keys($section);
			sort($keys);
			foreach ($keys as $k) {
				if ($k === 'type' || $k === 'v') {
					continue;
				}
				$cv = self::clean_value($section[$k]);
				if (self::nz($cv)) {
					$out[$k] = $cv;
				}
			}
			return $out;
		}

		$repeaters = $defs['repeaters'] ?? [];
		$bools     = $defs['bool'] ?? [];
		$ints      = $defs['int'] ?? [];
		foreach (($defs['fields'] ?? []) as $f) {
			if (!array_key_exists($f, $section)) {
				continue;
			}
			if (isset($repeaters[$f])) {
				$rows = [];
				foreach ((array) $section[$f] as $row) {
					$r = self::order_object($row, $repeaters[$f]);
					if (self::nz($r)) {
						$rows[] = $r;
					}
				}
				$val = $rows;
			} elseif (in_array($f, $bools, true)) {
				$val = self::cast_bool($section[$f]);
			} elseif (in_array($f, $ints, true)) {
				$val = self::cast_int(self::clean_value($section[$f]));
			} else {
				$val = self::clean_value($section[$f]);
			}
			if (self::nz($val)) {
				$out[$f] = $val;
			}
		}

		$keys = array_keys($section);
		sort($keys);
		foreach ($keys as $k) {
			if ($k === 'type' || $k === 'v'
				|| in_array($k, $defs['fields'] ?? [], true)
				|| array_key_exists($k, $out)) {
				continue;
			}
			$cv = self::clean_value($section[$k]);
			if (self::nz($cv)) {
				$out[$k] = $cv;
			}
		}
		return $out;
	}

	/**
	 * Map any page-shaped array to the one canonical form (stable key order,
	 * empty values dropped, bool/int fields cast, schema version stamped).
	 * Applied to both sides of the round-trip so reconcile/export is stable.
	 * Mirrors migration/lib/normalize-page.mjs exactly.
	 */
	public static function normalize_page(array $data): array {
		$spec = self::field_spec();
		$top  = $spec['topLevel'] ?? [];
		// Fail safe: if the spec file is missing/unreadable, do NOT canonicalize
		// (which would otherwise drop every key) — pass the data through intact.
		if (!$top) {
			return $data;
		}
		$out = [];
		foreach ($top as $k) {
			if (!array_key_exists($k, $data)) {
				continue;
			}
			if ($k === 'seo') {
				$out['seo'] = self::normalize_seo($data['seo']);
			} elseif ($k === 'sections') {
				$secs = [];
				foreach ((array) $data['sections'] as $s) {
					$ns = self::normalize_section($s);
					if ($ns !== null) {
						$secs[] = $ns;
					}
				}
				$out['sections'] = $secs;
			} else {
				$out[$k] = self::clean_value($data[$k]);
			}
		}
		$keys = array_keys($data);
		sort($keys);
		foreach ($keys as $k) {
			if (in_array($k, $top, true) || array_key_exists($k, $out)) {
				continue;
			}
			$out[$k] = self::clean_value($data[$k]);
		}
		return $out;
	}
}
