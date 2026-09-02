<?php
/**
 * AQ Image Optimizer — shrinks oversized uploads in place, automatically.
 *
 * OFF BY DEFAULT. When an admin turns it on (AutoForge → Media), every image
 * that lands in the media library is, before its attachment is created:
 *   1. downscaled to a max width if it is wider (never upscaled),
 *   2. compressed at a visually-lossless quality,
 *   3. converted to WebP when the server can write WebP, and
 *   4. saved OVER the original file — the "shrink the master in place" model:
 *      the stored original becomes the capped-width WebP, so WordPress still has
 *      a valid master to generate its thumbnail sizes from and no giant orphan
 *      file is left behind.
 *
 * It hooks `wp_handle_upload` (fires after the file lands, before the attachment
 * row exists) so `_wp_attached_file` and every generated size derive from the
 * optimized master. It COEXISTS with AQ_Alt_Text, which acts later on
 * `wp_generate_attachment_metadata`: this class only rewrites the file on disk
 * and hands WordPress the new path; alt text is still written afterwards.
 *
 * Pure decision logic (which files to touch, target width, webp path, quality
 * clamp, settings sanitize) has no WordPress calls so it is unit-testable
 * (tests/image-optimizer-test.php); the real image work lives in the WordPress
 * layer below and is wrapped so ANY failure returns the upload untouched — an
 * upload must never be lost to this feature.
 *
 * Option (autoload=false): aq_image_opt {enabled, max_width, webp, quality, strip_meta}.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Image_Optimizer {

	const CAP    = 'manage_options';
	const OPTION = 'aq_image_opt';
	const SLUG   = 'aq-media'; // shares the Alt Text screen (AutoForge → Media)

	/** Raster types we rewrite. GIF (animation), SVG and everything else are skipped. */
	const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

	const DEFAULT_MAX_WIDTH = 1960;
	const DEFAULT_QUALITY   = 82;

	public static function register(): void {
		// Priority 10: run before AQ_Alt_Text (which acts on the LATER
		// wp_generate_attachment_metadata hook) so alt text is written against
		// the already-optimized master.
		add_filter('wp_handle_upload', [__CLASS__, 'on_upload'], 10, 2);
		add_action('admin_post_aq_image_opt_save', [__CLASS__, 'save']);
		// Rendered onto the existing AutoForge → Media screen (see AQ_Alt_Text::render).
		add_action('aq_media_admin_after', [__CLASS__, 'render_panel']);
		if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
			\WP_CLI::add_command('aq optimize_images', [__CLASS__, 'cli']);
		}
	}

	/* ============================================================
	 * Pure logic — no WordPress calls (unit-tested)
	 * ============================================================ */

	/**
	 * Width to downscale to: never larger than the original (no upscaling), never
	 * larger than the cap. A cap of 0 or less means "no cap" → keep the original.
	 */
	public static function target_width(int $orig, int $cap): int {
		if ($cap <= 0) {
			return $orig;
		}
		return $orig <= $cap ? $orig : $cap;
	}

	/** Process only when enabled AND the type is one of the raster kinds we handle. */
	public static function should_process(string $mime, bool $enabled): bool {
		return $enabled && in_array(strtolower(trim($mime)), self::MIMES, true);
	}

	/** Swap a .jpg/.jpeg/.png/.webp extension (case-insensitive) for .webp; dir untouched. */
	public static function webp_path(string $path): string {
		return (string) preg_replace('/\.(jpe?g|png|webp)$/i', '.webp', $path);
	}

	/** Clamp WebP/JPEG quality to 1..100; anything out of range (or 0) → the default 82. */
	public static function clamp_quality(int $q): int {
		return ($q >= 1 && $q <= 100) ? $q : self::DEFAULT_QUALITY;
	}

	/** The option defaults — the feature does nothing until `enabled` is turned on. */
	public static function defaults(): array {
		return [
			'enabled'    => false,
			'max_width'  => self::DEFAULT_MAX_WIDTH,
			'webp'       => true,
			'quality'    => self::DEFAULT_QUALITY,
			'strip_meta' => true,
		];
	}

	/**
	 * Clamp raw input to valid option values. Booleans use checkbox semantics
	 * (absent = false) so `enabled` can never be turned on by an incomplete POST;
	 * out-of-range numbers fall back to the defaults above.
	 */
	public static function sanitize_settings(array $in): array {
		$mw = (int) ($in['max_width'] ?? 0);
		return [
			'enabled'    => !empty($in['enabled']),
			'max_width'  => ($mw > 0) ? min(20000, $mw) : self::DEFAULT_MAX_WIDTH,
			'webp'       => !empty($in['webp']),
			'quality'    => self::clamp_quality((int) ($in['quality'] ?? 0)),
			'strip_meta' => !empty($in['strip_meta']),
		];
	}

	/** Rebuild a URL by swapping its final path segment for a new basename. */
	public static function swap_url(string $url, string $basename): string {
		$pos = strrpos($url, '/');
		return $pos === false ? $basename : substr($url, 0, $pos + 1) . $basename;
	}

	/* ============================================================
	 * Settings
	 * ============================================================ */

	public static function settings(): array {
		$o = get_option(self::OPTION, []);
		return array_merge(self::defaults(), is_array($o) ? $o : []);
	}

	public static function enabled(): bool {
		return !empty(self::settings()['enabled']);
	}

	/* ============================================================
	 * WordPress layer — the actual image work (wrapped; never fatal)
	 * ============================================================ */

	/** Normalize a path for equality checks (case-insensitive, forward slashes, realpath if it exists). */
	private static function norm_path(string $p): string {
		$r = realpath($p);
		return strtolower(str_replace('\\', '/', $r !== false ? $r : $p));
	}

	/**
	 * Resize + compress + (optionally) WebP-convert one file on disk, replacing
	 * the master in place. Returns the new ['file','type'] or null on any
	 * no-op/failure (caller keeps the original). Not pure — used by the upload
	 * hook and the backfill CLI.
	 *
	 * @return array{file:string,type:string,resized:bool,converted:bool}|null
	 */
	public static function process_file(string $file, string $type, int $cap, array $s): ?array {
		if ($file === '' || !is_file($file) || !function_exists('wp_get_image_editor')) {
			return null;
		}
		$editor = wp_get_image_editor($file);
		if (is_wp_error($editor)) {
			return null; // GD/Imagick could not read it — never break the upload
		}
		$size   = $editor->get_size();
		$orig_w = (int) (is_array($size) ? ($size['width'] ?? 0) : 0);
		$target = self::target_width($orig_w, $cap);

		$resized = false;
		if ($orig_w > 0 && $target < $orig_w) {
			$r = $editor->resize($target, null, false); // width cap, keep aspect, no crop
			if (is_wp_error($r)) {
				return null;
			}
			$resized = true;
		}
		// strip_meta: WP's editors re-encode from raw pixels here, which already
		// drops EXIF/most metadata — there is no separate keep-metadata path to gate.
		$editor->set_quality(self::clamp_quality((int) ($s['quality'] ?? self::DEFAULT_QUALITY)));

		$want_webp = !empty($s['webp'])
			&& function_exists('wp_image_editor_supports')
			&& wp_image_editor_supports(['methods' => ['save'], 'mime_type' => 'image/webp']);

		if ($want_webp) {
			$new_path = self::webp_path($file);
			$saved    = $editor->save($new_path, 'image/webp');
			if (is_wp_error($saved) || empty($saved['path'])) {
				return null;
			}
			$newfile   = (string) $saved['path'];
			$converted = self::norm_path($newfile) !== self::norm_path($file);
			// Delete ONLY the original we just replaced (an image in the uploads
			// dir), and only when the new WebP is a different file.
			if ($converted && is_file($file)) {
				@unlink($file);
			}
			return ['file' => $newfile, 'type' => 'image/webp', 'resized' => $resized, 'converted' => $converted];
		}

		// Fallback: WebP off or unsupported — re-save in the original format at the
		// capped width/quality, reusing the original path (type unchanged).
		$saved = $editor->save($file);
		if (is_wp_error($saved) || empty($saved['path'])) {
			return null;
		}
		return ['file' => (string) $saved['path'], 'type' => $type, 'resized' => $resized, 'converted' => false];
	}

	/**
	 * wp_handle_upload filter. Signature ($upload, $context) → modified
	 * ['file','url','type']. Any failure returns $upload unchanged.
	 */
	public static function on_upload($upload, $context = 'upload') {
		try {
			if (!is_array($upload) || empty($upload['file']) || empty($upload['type'])) {
				return $upload;
			}
			$s = self::settings();
			if (!self::should_process((string) $upload['type'], !empty($s['enabled']))) {
				return $upload;
			}
			// Per-context cap: a future gallery module can pass a smaller width for
			// its own uploads via this filter.
			$cap = (int) apply_filters('aq_image_upload_max_width', (int) $s['max_width'], $context);
			$res = self::process_file((string) $upload['file'], (string) $upload['type'], $cap, $s);
			if ($res === null) {
				return $upload;
			}
			return [
				'file' => $res['file'],
				'url'  => self::swap_url((string) $upload['url'], basename($res['file'])),
				'type' => $res['type'],
			];
		} catch (\Throwable $e) {
			return $upload; // an upload must never be lost to this feature
		}
	}

	/* ============================================================
	 * WP-CLI:  wp aq optimize_images [--dry-run] [--apply] [--max=<n>]
	 *
	 * DEFAULT (no flags) = DRY RUN — reports only, changes nothing. Only --apply
	 * mutates files and deletes replaced originals; this makes an accidental
	 * bulk-rewrite impossible.
	 * ============================================================ */

	public static function cli(array $args, array $assoc): void {
		$apply = !empty($assoc['apply']);
		$max   = max(1, (int) ($assoc['max'] ?? 100000));
		$s     = self::settings();
		$cap   = (int) apply_filters('aq_image_upload_max_width', (int) $s['max_width'], 'cli');

		$q = new WP_Query([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::MIMES,
			'fields'         => 'ids',
			'posts_per_page' => $max,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]);
		$ids = array_map('intval', (array) $q->posts);

		if (!$apply) {
			\WP_CLI::log('DRY RUN — nothing will be changed. Re-run with --apply to optimize + replace.');
		}
		\WP_CLI::log(sprintf('Cap %dpx · quality %d · webp %s · %d image(s) to scan.',
			$cap, self::clamp_quality((int) $s['quality']), !empty($s['webp']) ? 'on' : 'off', count($ids)));

		$before_total = 0;
		$after_total  = 0;
		$changed      = 0;
		$fmt = function (int $b): string { return number_format($b / 1024, 1) . ' KB'; };

		foreach ($ids as $id) {
			$file = (string) get_attached_file($id);
			if ($file === '' || !is_file($file)) {
				continue;
			}
			$before = (int) filesize($file);
			$before_total += $before;

			if (!$apply) {
				\WP_CLI::log(sprintf('%-6d %10s  %s', $id, $fmt($before), basename($file)));
				continue;
			}

			$type = (string) get_post_mime_type($id);
			$res  = self::process_file($file, $type, $cap, $s);
			if ($res === null) {
				\WP_CLI::log(sprintf('%-6d %10s  skipped (unreadable / no editor)  %s', $id, $fmt($before), basename($file)));
				continue;
			}
			$new = (string) $res['file'];
			update_attached_file($id, $new);
			if ($res['type'] !== $type) {
				wp_update_post(['ID' => $id, 'post_mime_type' => $res['type']]);
			}
			if (function_exists('wp_generate_attachment_metadata') && function_exists('wp_update_attachment_metadata')) {
				$meta = wp_generate_attachment_metadata($id, $new);
				if (is_array($meta) && $meta) {
					wp_update_attachment_metadata($id, $meta);
				}
			}
			$after = is_file($new) ? (int) filesize($new) : $before;
			$after_total += $after;
			$changed++;
			\WP_CLI::log(sprintf('%-6d %10s → %10s  %s%s', $id, $fmt($before), $fmt($after),
				basename($new), $res['converted'] ? '  (webp)' : ''));
		}

		if (!$apply) {
			\WP_CLI::success(sprintf('%d image(s) totalling %s would be optimized. Nothing changed (dry run).',
				count($ids), $fmt($before_total)));
			return;
		}
		$saved = max(0, $before_total - $after_total);
		\WP_CLI::success(sprintf('%d image(s) optimized. %s → %s (saved %s).',
			$changed, $fmt($before_total), $fmt($after_total), $fmt($saved)));
	}

	/* ============================================================
	 * Admin — a panel appended to the AutoForge → Media screen
	 * ============================================================ */

	public static function render_panel(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$s       = self::settings();
		$webp_ok = function_exists('wp_image_editor_supports')
			&& wp_image_editor_supports(['methods' => ['save'], 'mime_type' => 'image/webp']);
		?>
		<?php if (isset($_GET['aq_img_saved'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Image optimizer settings saved.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_image_opt_save">
			<?php wp_nonce_field('aq_image_opt_save'); ?>
			<div class="aq-panel">
				<h2 style="margin-top:0">Optimize images on upload <?php echo AQ_Admin_Hub::tip('When on, every image you upload is scaled down to your max width, compressed, and (where the server allows) converted to WebP. The oversized original is replaced in place so it does not sit on the server wasting space.'); ?></h2>
				<p class="aq-alt-hint" style="margin:0 0 14px">Off by default, and independent of alt text. Turning it on affects new uploads only — existing images are left alone unless you run the backfill from WP-CLI.</p>

				<div class="aq-alt-field">
					<label class="aq-alt-toggle"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Resize, compress &amp; convert new uploads automatically</label>
				</div>
				<div class="aq-alt-field">
					<label for="aq-img-maxw">Max width (pixels) <?php echo AQ_Admin_Hub::tip('Any upload wider than this is scaled down to it. Narrower images are never enlarged. 1960 suits full-width hero images on retina screens.'); ?></label>
					<input type="number" id="aq-img-maxw" name="max_width" min="200" max="20000" value="<?php echo (int) $s['max_width']; ?>">
				</div>
				<div class="aq-alt-field">
					<label class="aq-alt-toggle"><input type="checkbox" name="webp" value="1" <?php checked(!empty($s['webp'])); ?> <?php disabled(!$webp_ok); ?>> Convert to WebP<?php echo $webp_ok ? '' : ' <span class="aq-alt-hint" style="display:inline">(this server can\'t write WebP — images will be resized &amp; compressed in their original format instead)</span>'; ?></label>
				</div>
				<div class="aq-alt-field">
					<label for="aq-img-q">Quality (1–100) <?php echo AQ_Admin_Hub::tip('Compression quality. 82 is visually lossless for photos while cutting file size a lot. Lower means smaller files but more visible artefacts.'); ?></label>
					<input type="number" id="aq-img-q" name="quality" min="1" max="100" value="<?php echo (int) $s['quality']; ?>">
				</div>
				<div class="aq-alt-field">
					<label class="aq-alt-toggle"><input type="checkbox" name="strip_meta" value="1" <?php checked(!empty($s['strip_meta'])); ?>> Strip EXIF / camera metadata</label>
				</div>
			</div>
			<?php submit_button('Save image settings'); ?>
		</form>
		<?php
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_image_opt_save')) {
			wp_die('Not allowed.');
		}
		$in = wp_unslash($_POST);
		update_option(self::OPTION, self::sanitize_settings(is_array($in) ? $in : []), false);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'aq_img_saved' => '1'], admin_url('admin.php')));
		exit;
	}
}
