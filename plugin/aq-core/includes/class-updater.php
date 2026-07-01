<?php
/**
 * AQ_Updater — GitHub-release auto-update for the AutoForge plugin AND its
 * companion aqm-base stub theme.
 *
 * Surfaces the normal WordPress "update available" button by comparing the
 * plugin's header Version to the latest GitHub release of the PRODUCT repo, and
 * points the one-click updater at the release's .zip asset. The same release
 * also carries the theme zip (aqm-base-<version>.zip); this class surfaces the
 * theme update from that asset too, so the stub theme needs no update code of
 * its own (and the theme tracks its own version line, read from the asset name).
 *
 * This is DISTINCT from the content importer (AQ_Importer), which pulls a
 * client's CONTENT repo (pages/images/brand/CSS). The updater pulls new builds
 * of THIS plugin from the AutoForge product repo.
 *
 * Repo is configured (first match wins):
 *   1. define('AQ_UPDATE_REPO', 'owner/name') in wp-config.php
 *   2. the `aq_update_repo` option
 *   3. the `aq_update_repo` filter
 * Private repos reuse the GitHub PAT stored in AutoForge → Integrations.
 *
 * Per-client config (brand/design) lives in the DB (aq_site_config option) and
 * the client content repo — never in the plugin files — so an update never
 * clobbers a client's data.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Updater {

	const CACHE_KEY = 'aq_updater_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Stub-theme folder slug the companion theme installs/updates into. */
	const THEME_SLUG = 'aqm-base';

	/**
	 * Built-in product repo the updater checks for new releases. Override per
	 * site with the AQ_UPDATE_REPO constant (wp-config), the aq_update_repo
	 * option, or the aq_update_repo filter.
	 */
	const DEFAULT_REPO = 'AQ-Marketing/autoforge-wp';

	/**
	 * Per-client theme assets that a theme UPDATE must never overwrite. These are
	 * the compiled files a client delivers into the active companion theme (via the
	 * content importer / SFTP), NOT part of the shared product release. The release
	 * theme zip ships only the neutral stub, so a naive theme update would clobber a
	 * live site's real compiled CSS/JS with the stub — see theme_assets_stash().
	 */
	const PROTECTED_THEME_ASSETS = ['assets/css/main.css', 'assets/js/site.js'];

	/** Transient holding stashed per-client assets across a theme update. */
	const ASSET_STASH_KEY = 'aq_updater_theme_asset_stash';

	public static function register(): void {
		add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
		add_filter('pre_set_site_transient_update_themes', [__CLASS__, 'check_theme_update']);
		add_filter('plugins_api', [__CLASS__, 'plugins_api'], 10, 3);
		add_filter('upgrader_source_selection', [__CLASS__, 'fix_source_dir'], 10, 4);
		add_filter('http_request_args', [__CLASS__, 'auth_download'], 10, 2);
		// Drop the cached release lookup right after any plugin update completes.
		add_action('upgrader_process_complete', [__CLASS__, 'flush_cache'], 10, 0);
		// Preserve a client's compiled theme assets across a companion-theme update
		// (the release ships only the neutral stub, which would otherwise clobber
		// the live per-client build). Stash before, restore after.
		add_filter('upgrader_pre_install', [__CLASS__, 'theme_assets_stash'], 10, 2);
		add_filter('upgrader_post_install', [__CLASS__, 'theme_assets_restore'], 10, 3);
	}

	/** True when this upgrader run is updating our companion stub theme. */
	private static function is_theme_update($hook_extra): bool {
		return is_array($hook_extra)
			&& (($hook_extra['type'] ?? '') === 'theme')
			&& (($hook_extra['theme'] ?? '') === self::THEME_SLUG);
	}

	/**
	 * upgrader_pre_install: read the CURRENTLY-installed per-client theme assets and
	 * stash them in a transient, so theme_assets_restore() can put them back after
	 * WordPress replaces the whole theme folder with the release's neutral stub.
	 * Intentional asset changes are delivered by the content importer, never by a
	 * theme update — so preserving whatever the site currently serves is correct.
	 */
	public static function theme_assets_stash($response, $hook_extra) {
		if (is_wp_error($response) || !self::is_theme_update($hook_extra)) {
			return $response;
		}
		$dir    = trailingslashit(get_theme_root(self::THEME_SLUG) . '/' . self::THEME_SLUG);
		$stash  = [];
		foreach (self::PROTECTED_THEME_ASSETS as $rel) {
			$path = $dir . $rel;
			if (is_readable($path)) {
				$contents = @file_get_contents($path);
				if ($contents !== false && $contents !== '') {
					$stash[$rel] = $contents;
				}
			}
		}
		if ($stash) {
			set_transient(self::ASSET_STASH_KEY, $stash, 10 * MINUTE_IN_SECONDS);
		} else {
			delete_transient(self::ASSET_STASH_KEY);
		}
		return $response;
	}

	/**
	 * upgrader_post_install: write the stashed per-client assets back into the freshly
	 * installed theme, overwriting the stub the release shipped. No-op if nothing was
	 * stashed (e.g. a site that never had a compiled build).
	 */
	public static function theme_assets_restore($response, $hook_extra, $result) {
		if (is_wp_error($response) || !self::is_theme_update($hook_extra)) {
			return $response;
		}
		$stash = get_transient(self::ASSET_STASH_KEY);
		delete_transient(self::ASSET_STASH_KEY);
		if (!is_array($stash) || !$stash) {
			return $response;
		}
		$dest = isset($result['destination']) ? trailingslashit((string) $result['destination']) : '';
		if ($dest === '' || !is_dir($dest)) {
			return $response;
		}
		foreach ($stash as $rel => $contents) {
			// Only restore our allowlisted relative paths (defence-in-depth vs. a
			// tampered transient); never traverse outside the theme dir.
			if (!in_array($rel, self::PROTECTED_THEME_ASSETS, true)) {
				continue;
			}
			$path = $dest . $rel;
			wp_mkdir_p(dirname($path));
			@file_put_contents($path, $contents);
		}
		return $response;
	}

	/** "owner/name" of the product repo; defaults to self::DEFAULT_REPO. */
	public static function repo(): string {
		$repo = defined('AQ_UPDATE_REPO') ? (string) AQ_UPDATE_REPO : (string) get_option('aq_update_repo', self::DEFAULT_REPO);
		$repo = (string) apply_filters('aq_update_repo', $repo);
		return trim($repo, " /");
	}

	/** e.g. aq-core/aq-core.php */
	public static function basename(): string {
		return plugin_basename(defined('AQ_CORE_FILE') ? AQ_CORE_FILE : __FILE__);
	}

	/** Plugin folder slug, e.g. aq-core. */
	public static function slug(): string {
		return dirname(self::basename());
	}

	public static function current_version(): string {
		return defined('AQ_CORE_VERSION') ? (string) AQ_CORE_VERSION : '0.0.0';
	}

	public static function flush_cache(): void {
		delete_transient(self::CACHE_KEY);
	}

	/**
	 * True when WordPress is running a user-forced update check — i.e. the
	 * "Check again" button on Dashboard → Updates, which loads
	 * update-core.php?force-check=1. On a forced check we MUST bypass our own
	 * release cache and re-query GitHub; otherwise a release published inside the
	 * CACHE_TTL window stays invisible until the cache expires, and the only
	 * thing that clears the cache is completing an update you can't yet see.
	 */
	public static function is_forced_check(): bool {
		return !empty($_GET['force-check']); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Latest GitHub release for the product repo. Memoized per request, and
	 * cached across requests — but ONLY on success. A failed lookup is never
	 * persisted (the transient is cleared), so a transient GitHub error, a repo
	 * that was just made public, or a token that was just fixed recovers on the
	 * very next check instead of silently hiding updates for CACHE_TTL.
	 *
	 * A forced check (is_forced_check) skips the cached value and re-queries, then
	 * refreshes the cache — so "Check again" dependably surfaces a new release the
	 * moment it is published, instead of up to CACHE_TTL later.
	 *
	 * Returns ['version','zip','asset_api','theme_*','html','body'] or null.
	 */
	public static function latest_release(): ?array {
		static $memo = [];
		$repo = self::repo();
		if ($repo === '') {
			return null;
		}
		if (array_key_exists($repo, $memo)) {
			return $memo[$repo];
		}

		if (!self::is_forced_check()) {
			$cached = get_transient(self::CACHE_KEY);
			if (is_array($cached) && ($cached['repo'] ?? '') === $repo && !empty($cached['release'])) {
				return $memo[$repo] = $cached['release'];
			}
		}

		$release = self::fetch_release($repo);

		if ($release) {
			set_transient(self::CACHE_KEY, ['repo' => $repo, 'release' => $release], self::CACHE_TTL);
		} else {
			// Don't let a failed lookup persist and keep blocking update detection.
			delete_transient(self::CACHE_KEY);
		}
		return $memo[$repo] = $release;
	}

	/**
	 * One HTTP lookup + parse of /releases/latest. Our product repo is PUBLIC, so
	 * no auth is needed; we still send the configured token (for private-repo
	 * support), but if that token is REJECTED (401/403 — expired, revoked, wrong
	 * scope) we retry anonymously. That way a stale import-only token can never
	 * break updates for the public product repo.
	 */
	/** 'stable' (default) or 'beta'. Beta sites see pre-releases. */
	public static function channel(): string {
		$ch = defined('AQ_UPDATE_CHANNEL') ? strtolower((string) AQ_UPDATE_CHANNEL) : 'stable';
		return in_array($ch, ['stable', 'beta'], true) ? $ch : 'stable';
	}

	private static function fetch_release(string $repo): ?array {
		// Beta channel: list releases and pick the first (newest). Includes pre-releases.
		// Stable channel: /releases/latest (skips pre-releases automatically).
		$beta = self::channel() === 'beta';
		$url  = 'https://api.github.com/repos/' . $repo . '/releases' . ($beta ? '?per_page=5' : '/latest');
		$resp = wp_remote_get($url, ['timeout' => 15, 'headers' => self::api_headers()]);
		$code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);

		if (($code === 401 || $code === 403) && self::token() !== '') {
			$resp = wp_remote_get($url, ['timeout' => 15, 'headers' => self::api_headers(false)]);
			$code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
		}

		if ($code !== 200) {
			return null;
		}
		$body = json_decode(wp_remote_retrieve_body($resp), true);
		// Beta channel returns an array of releases; pick the first with assets.
		if ($beta && is_array($body) && isset($body[0])) {
			$data = null;
			foreach ($body as $rel) {
				if (!empty($rel['tag_name']) && !empty($rel['assets'])) {
					$data = $rel;
					break;
				}
			}
		} else {
			$data = $body;
		}
		if (!is_array($data) || empty($data['tag_name'])) {
			return null;
		}

		// Sort the release assets: the THEME zip is aqm-base-*.zip (its version is
		// read from the filename, since the theme tracks its own version line); the
		// PLUGIN zip is any other .zip. The plugin falls back to the source zipball
		// (fix_source_dir renames it). asset_api is api.github.com/.../assets/{id},
		// used for private-repo auth.
		$version         = ltrim((string) $data['tag_name'], 'vV');
		$zip             = (string) ($data['zipball_url'] ?? '');
		$asset_api       = '';
		$theme_zip       = '';
		$theme_asset_api = '';
		$theme_version   = '';
		foreach (($data['assets'] ?? []) as $asset) {
			$name = (string) ($asset['name'] ?? '');
			if (substr($name, -4) !== '.zip') {
				continue;
			}
			if (strpos($name, self::THEME_SLUG) === 0) {
				$theme_zip       = (string) ($asset['browser_download_url'] ?? '');
				$theme_asset_api = (string) ($asset['url'] ?? '');
				if (preg_match('/^' . preg_quote(self::THEME_SLUG, '/') . '-(v?[0-9][0-9A-Za-z.\-]*)\.zip$/', $name, $mm)) {
					$theme_version = ltrim($mm[1], 'vV');
				}
			} else {
				$zip       = (string) ($asset['browser_download_url'] ?? '');
				$asset_api = (string) ($asset['url'] ?? '');
			}
		}
		return [
			'version'         => $version,
			'zip'             => $zip,
			'asset_api'       => $asset_api,
			'theme_zip'       => $theme_zip,
			'theme_asset_api' => $theme_asset_api,
			'theme_version'   => $theme_version,
			'html'            => (string) ($data['html_url'] ?? ''),
			'body'            => (string) ($data['body'] ?? ''),
		];
	}

	public static function check_update($transient) {
		if (!is_object($transient) || empty($transient->checked)) {
			return $transient;
		}
		$release = self::latest_release();
		if (!$release || empty($release['version']) || empty($release['zip'])) {
			return $transient;
		}
		if (version_compare($release['version'], self::current_version(), '<=')) {
			return $transient;
		}

		$basename = self::basename();
		// For private repos with an asset API URL, route the download through the
		// API endpoint so auth_download() can attach the token + octet-stream.
		$package = (self::token() !== '' && !empty($release['asset_api'])) ? $release['asset_api'] : $release['zip'];

		$transient->response[$basename] = (object) [
			'slug'        => self::slug(),
			'plugin'      => $basename,
			'new_version' => $release['version'],
			'url'         => $release['html'],
			'package'     => $package,
		];
		return $transient;
	}

	/**
	 * Surface the companion stub theme's update from the SAME GitHub release.
	 * The theme version comes from the aqm-base-<version>.zip asset name (the
	 * theme has its own version line, independent of the plugin/release tag), so
	 * we compare it to the installed theme's Version. The theme asset zip already
	 * unzips to the correct aqm-base/ folder, so no source-dir fix is needed.
	 */
	public static function check_theme_update($transient) {
		if (!is_object($transient) || empty($transient->checked)) {
			return $transient;
		}
		$installed = isset($transient->checked[self::THEME_SLUG]) ? (string) $transient->checked[self::THEME_SLUG] : '';
		if ($installed === '') {
			return $transient; // companion theme not installed on this site
		}
		$release = self::latest_release();
		if (!$release || empty($release['theme_version']) || empty($release['theme_zip'])) {
			return $transient;
		}
		if (version_compare($release['theme_version'], $installed, '<=')) {
			return $transient;
		}
		// Private repos: route through the asset API URL so auth_download() can
		// attach the token + octet-stream Accept; public repos use the direct URL.
		$package = (self::token() !== '' && !empty($release['theme_asset_api'])) ? $release['theme_asset_api'] : $release['theme_zip'];

		$transient->response[self::THEME_SLUG] = [
			'theme'       => self::THEME_SLUG,
			'new_version' => $release['theme_version'],
			'url'         => $release['html'],
			'package'     => $package,
		];
		return $transient;
	}

	public static function plugins_api($result, $action, $args) {
		if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::slug()) {
			return $result;
		}
		$release = self::latest_release();
		if (!$release) {
			return $result;
		}
		return (object) [
			'name'          => 'AutoForge',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => 'AQ Marketing',
			'homepage'      => $release['html'],
			'download_link' => $release['zip'],
			'sections'      => [
				'changelog' => $release['body'] !== '' ? wpautop(wp_kses_post($release['body'])) : 'See the GitHub release notes.',
			],
		];
	}

	/**
	 * GitHub zipballs (and assets) unzip to a hashed/owner-prefixed folder. Rename
	 * the extracted directory back to the plugin slug so the update lands in the
	 * same folder and WordPress keeps recognizing the plugin.
	 */
	public static function fix_source_dir($source, $remote_source, $upgrader, $args = []) {
		if (empty($args['plugin']) || $args['plugin'] !== self::basename()) {
			return $source;
		}
		$slug = self::slug();
		$desired = trailingslashit($remote_source) . $slug;
		if (untrailingslashit($source) === untrailingslashit($desired)) {
			return $source;
		}
		global $wp_filesystem;
		if ($wp_filesystem && $wp_filesystem->move($source, $desired, true)) {
			return trailingslashit($desired);
		}
		return $source;
	}

	/**
	 * Attach auth + octet-stream Accept when downloading a release asset from the
	 * GitHub API (private repos). Public browser_download_url needs nothing.
	 */
	public static function auth_download($args, $url) {
		$token = self::token();
		if ($token === '' || strpos((string) $url, 'api.github.com/repos/') === false || strpos((string) $url, '/releases/assets/') === false) {
			return $args;
		}
		$args['headers'] = array_merge(
			is_array($args['headers'] ?? null) ? $args['headers'] : [],
			['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/octet-stream', 'User-Agent' => 'aq-core']
		);
		return $args;
	}

	private static function token(): string {
		return class_exists('AQ_Integrations') ? (string) AQ_Integrations::github_token() : '';
	}

	private static function api_headers(bool $auth = true): array {
		$h = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'aq-core'];
		$token = self::token();
		if ($auth && $token !== '') {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}
}
