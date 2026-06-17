<?php
/**
 * AQ_Updater — GitHub-release auto-update for the AutoForge plugin.
 *
 * Surfaces the normal WordPress "update available" button by comparing the
 * plugin's header Version to the latest GitHub release of the PRODUCT repo, and
 * points the one-click updater at the release's .zip asset.
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

	/**
	 * Built-in product repo the updater checks for new releases. Override per
	 * site with the AQ_UPDATE_REPO constant (wp-config), the aq_update_repo
	 * option, or the aq_update_repo filter.
	 */
	const DEFAULT_REPO = 'AQ-Marketing/autoforge-wp';

	public static function register(): void {
		add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
		add_filter('plugins_api', [__CLASS__, 'plugins_api'], 10, 3);
		add_filter('upgrader_source_selection', [__CLASS__, 'fix_source_dir'], 10, 4);
		add_filter('http_request_args', [__CLASS__, 'auth_download'], 10, 2);
		// Drop the cached release lookup right after any plugin update completes.
		add_action('upgrader_process_complete', [__CLASS__, 'flush_cache'], 10, 0);
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
	 * Fetch (and cache) the latest GitHub release for the product repo.
	 * Returns ['version','zip','html','body','asset_api'] or null.
	 */
	public static function latest_release(): ?array {
		$repo = self::repo();
		if ($repo === '') {
			return null;
		}
		$cached = get_transient(self::CACHE_KEY);
		if (is_array($cached) && ($cached['repo'] ?? '') === $repo) {
			return $cached['release'];
		}

		$resp = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			['timeout' => 15, 'headers' => self::api_headers()]
		);
		$release = null;
		if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
			$data = json_decode(wp_remote_retrieve_body($resp), true);
			if (is_array($data) && !empty($data['tag_name'])) {
				$version = ltrim((string) $data['tag_name'], 'vV');
				// Prefer an attached .zip asset (built with the correct folder
				// name); fall back to the source zipball (fix_source_dir renames).
				$zip = (string) ($data['zipball_url'] ?? '');
				$asset_api = '';
				foreach (($data['assets'] ?? []) as $asset) {
					if (substr((string) ($asset['name'] ?? ''), -4) === '.zip') {
						$zip       = (string) $asset['browser_download_url'];
						$asset_api = (string) ($asset['url'] ?? ''); // api.github.com/.../assets/{id} (for private auth)
						break;
					}
				}
				$release = [
					'version'   => $version,
					'zip'       => $zip,
					'asset_api' => $asset_api,
					'html'      => (string) ($data['html_url'] ?? ''),
					'body'      => (string) ($data['body'] ?? ''),
				];
			}
		}

		set_transient(self::CACHE_KEY, ['repo' => $repo, 'release' => $release], self::CACHE_TTL);
		return $release;
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

	private static function api_headers(): array {
		$h = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'aq-core'];
		$token = self::token();
		if ($token !== '') {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}
}
