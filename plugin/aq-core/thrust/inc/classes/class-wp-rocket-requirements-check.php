<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class to check if the current WordPress and PHP versions meet our requirements
 *
 * @since 3.0
 * @author Remy Perona
 */
class WP_Rocket_Requirements_Check {
	/**
	 * Plugin Name
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin filepath
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Plugin previous version
	 *
	 * @var string
	 */
	private $plugin_last_version;

	/**
	 * Required WordPress version
	 *
	 * @var string
	 */
	private $wp_version;

	/**
	 * Required PHP version
	 *
	 * @var string
	 */
	private $php_version;

	/**
	 * WP Rocket options
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor
	 *
	 * @since 3.0
	 * @author Remy Perona
	 *
	 * @param array $args {
	 *     Arguments to populate the class properties.
	 *
	 *     @type string $plugin_name Plugin name.
	 *     @type string $wp_version  Required WordPress version.
	 *     @type string $php_version Required PHP version.
	 *     @type string $plugin_file Plugin filepath.
	 * }
	 */
	public function __construct( $args ) {
		foreach ( [ 'plugin_name', 'plugin_file', 'plugin_version', 'plugin_last_version', 'wp_version', 'php_version' ] as $setting ) {
			if ( isset( $args[ $setting ] ) ) {
				$this->$setting = $args[ $setting ];
			}
		}

		$this->plugin_last_version = version_compare( PHP_VERSION, '5.3' ) >= 0 ? $this->plugin_last_version : '2.10.12';
		$this->options             = get_option( 'wp_rocket_settings' );
	}

	/**
	 * Checks if all requirements are ok, if not, display a notice and the rollback
	 *
	 * @since 3.0
	 * @author Remy Perona
	 *
	 * @return bool
	 */
	public function check() {
		if ( ! $this->php_passes() || ! $this->wp_passes() ) {

			add_action( 'admin_notices', [ $this, 'notice' ] );
			add_action( 'admin_post_rocket_rollback', [ $this, 'rollback' ] );
			add_filter( 'http_request_args', [ $this, 'add_own_ua' ], 10, 2 );

			return false;
		}

		return true;
	}

	/**
	 * Checks if the current PHP version is equal or superior to the required PHP version
	 *
	 * @since 3.0
	 * @author Remy Perona
	 *
	 * @return bool
	 */
	private function php_passes() {
		return version_compare( PHP_VERSION, $this->php_version ) >= 0;
	}

	/**
	 * Checks if the current WordPress version is equal or superior to the required PHP version
	 *
	 * @since 3.0
	 * @author Remy Perona
	 *
	 * @return bool
	 */
	private function wp_passes() {
		global $wp_version;

		return version_compare( $wp_version, $this->wp_version ) >= 0;
	}

	/**
	 * Warns if PHP or WP version are less than the defined values and offer rollback.
	 *
	 * @since 3.0 Updated minimum PHP version to 5.4 and minimum WordPress version to 4.2
	 * @since 3.0 Moved to class
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Translators: %1$s = Plugin name, %2$s = Plugin version.
		$message = '<p>' . sprintf( __( 'To function properly, %1$s %2$s requires at least:', 'rocket' ), $this->plugin_name, $this->plugin_version ) . '</p><ul>';

		if ( ! $this->php_passes() ) {
			// Translators: %1$s = PHP version required.
			$message .= '<li>' . sprintf( __( 'PHP %1$s. To use this WP Rocket version, please ask your web host how to upgrade your server to PHP %1$s or higher.', 'rocket' ), $this->php_version ) . '</li>';
		}

		if ( ! $this->wp_passes() ) {
			// Translators: %1$s = WordPress version required.
			$message .= '<li>' . sprintf( __( 'WordPress %1$s. To use this WP Rocket version, please upgrade WordPress to version %1$s or higher.', 'rocket' ), $this->wp_version ) . '</li>';
		}

		$message .= '</ul><p>' . __( 'If you are not able to upgrade, you can rollback to the previous version by using the button below.', 'rocket' ) . '</p><p><a href="' . wp_nonce_url( admin_url( 'admin-post.php?action=rocket_rollback' ), 'rocket_rollback' ) . '" class="button">' .
		// Translators: %s = Previous plugin version.
		sprintf( __( 'Re-install version %s', 'rocket' ), $this->plugin_last_version )
		. '</a></p>';

		echo '<div class="notice notice-error">' . wp_kses_post( $message ) . '</div>';
	}

	/**
	 * Do the rollback
	 *
	 * @since 3.0
	 * @author Remy Perona
	 */
	public function rollback() {
		check_ajax_referer( 'rocket_rollback' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		// Boost: version rollback downloaded the previous build from api.wp-rocket.me
		// with the license key — removed. Roll back by deploying an older build instead.
		wp_die(
			esc_html__( 'Version rollback is not available in Boost by AQM. Deploy a previous build of the aq-core plugin instead.', 'rocket' ),
			esc_html__( 'Boost by AQM', 'rocket' ),
			[
				'response' => 200,
			]
		);
	}

	/**
	 * Filters the User Agent when doing a request to WP Rocket server
	 *
	 * @since 3.0
	 * @author Remy Perona
	 *
	 * @param array  $request   Array of arguments associated with the request.
	 * @param string $url       URL requested.
	 */
	public function add_own_ua( $request, $url ) {
		// Boost: never decorate requests with license/site identity.
		return $request;
	}
}
