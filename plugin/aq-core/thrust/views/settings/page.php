<?php
/**
 * Settings page template.
 *
 * @since 3.0
 *
 * @param array $data {
 *      @type string $slug WP Rocket slug.
 * }
 */

defined( 'ABSPATH' ) || exit;

settings_errors( $data['slug'] ); ?>
<div class="wpr-wrap wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Boost by AQM Settings', 'rocket' ); ?></h1>
	<div class="wpr-body">

		<header class="wpr-Header">
			<div class="wpr-Header-logo">
				<img src="<?php echo esc_url( WP_ROCKET_ASSETS_IMG_URL . 'aqm-logo-2026.svg' ); ?>" width="74" height="46" alt="Boost by AQM" class="wpr-Header-logo-desktop">
				<img src="<?php echo esc_url( WP_ROCKET_ASSETS_IMG_URL . 'aqm-logo-2026.svg' ); ?>" width="53" height="33" alt="Boost by AQM" class="wpr-Header-logo-mobile">
			</div>
			<div class="wpr-Header-nav">
				<?php $this->render_navigation(); ?>
			</div>
			<div class="wpr-Header-footer">
				<?php
				// translators: %s = Plugin version number.
				echo esc_html( sprintf( __( 'version %s', 'rocket' ), rocket_get_constant( 'WP_ROCKET_VERSION' ) ) );
				?>
			</div>
		</header>

		<section class="wpr-Content">
			<form action="options.php" method="POST" id="<?php echo esc_attr( $data['slug'] ); ?>_options">
				<?php settings_fields( $data['slug'] ); ?>
				<?php $this->render_form_sections(); ?>
				<?php $this->render_hidden_fields(); ?>
				<input type="submit" class="wpr-button" id="wpr-options-submit" value="<?php echo esc_attr( $data['btn_submit_text'] ); ?>">
			</form>
			<?php
			// Boost: no Imagify promo, no wp-rocket.me tutorials, no plugin-family upsell.
			$this->render_tools_section();
			?>
			<div class="wpr-Content-tips">
				<div class="wpr-radio wpr-radio--reverse wpr-radio--tips">
					<input type="checkbox" class="wpr-js-tips" id="wpr-js-tips" value="1" checked>
					<label for="wpr-js-tips">
						<span data-l10n-active="On"
							data-l10n-inactive="Off" class="wpr-radio-ui"></span>
						<?php esc_html_e( 'Show Sidebar', 'rocket' ); ?></label>
				</div>
			</div>
		</section>

		<aside class="wpr-Sidebar">
			<?php $this->render_part( 'sidebar' ); ?>
		</aside>
	</div>

	<?php // Boost: the Rocket Analytics opt-in popin is removed (no tracking exists). ?>
	<div class="wpr-Popin-overlay"></div>
	<?php
	/**
	 * Fires after the Settings page content
	 *
	 * @since 3.5
	 * @author Remy Perona
	 */
	do_action( 'rocket_settings_page_footer' );
	?>
</div>
