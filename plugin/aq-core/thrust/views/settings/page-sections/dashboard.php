<?php
/**
 * Dashboard section template.
 *
 * Boost edition: trimmed to the self-contained essentials — the status banner
 * and the Quick Actions (cache purge) panel. The upstream My Account/license
 * block, the FAQ documentation links, the "Ask support" / Rocketeers block and
 * the documentation box (all pointing at wp-rocket.me) are removed. The
 * WordPress action hooks are preserved so other Boost modules can still inject
 * dashboard content.
 *
 * @since 3.0
 *
 * @param array {
 *     Section arguments.
 *
 *     @type string $id    Page section identifier.
 *     @type string $title Page section title.
 * }
 */

defined( 'ABSPATH' ) || exit;

$rocket_boxes            = get_user_meta( get_current_user_id(), 'rocket_boxes', true );
$rocket_box_is_dismissed = in_array( 'rocket_activation_notice', (array) $rocket_boxes, true );

$rocket_hero_title       = __( 'Boost is active', 'rocket' );
$rocket_title            = esc_html__( 'Page caching and the global edge network are handled by Pressable.', 'rocket' )
	. '<br>'
	. esc_html__( 'Boost manages file optimization, media, preloading, and the database.', 'rocket' );
$rocket_hero_description = esc_html__( 'Everything on this page runs entirely on this site — no license, no external services.', 'rocket' )
	. '<br>'
	. esc_html__( 'Use the Quick Actions panel to purge the cache after major changes.', 'rocket' );
?>
<div id="<?php echo esc_attr( $data['id'] ); ?>" class="wpr-Page">
	<div class="wpr-sectionHeader">
		<h2 class="wpr-title1 wpr-icon-home"><?php echo esc_html( $data['title'] ); ?></h2>
	</div>

	<?php if ( ! $rocket_box_is_dismissed ) : ?>
	<div class="wpr-notice">
		<div class="wpr-notice-container">
			<div class="wpr-notice-supTitle"><?php echo esc_html( $rocket_hero_title ); ?></div>
			<h2 class="wpr-notice-title">
				<?php echo wp_kses( $rocket_title, [ 'br' => [] ] ); ?>
			</h2>
			<div class="wpr-notice-description">
				<?php echo wp_kses( $rocket_hero_description, [ 'br' => [] ] ); ?>
			</div>
			<a id="wpr-congratulations-notice" class="wpr-notice-close wpr-icon-close rocket-dismiss" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rocket_ignore&box=rocket_activation_notice' ), 'rocket_ignore_rocket_activation_notice' ) ); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice', 'rocket' ); ?></span></a>
		</div>
	</div>
	<?php endif; ?>
	<?php
		/**
		 * Fires before displaying the dashboard tab content
		 *
		 * @since 3.7.4
		 */
		do_action( 'rocket_before_dashboard_content' );
	?>
	<div class="wpr-Page-row">
		<div class="wpr-Page-col">
			<?php
			/**
			 * Fires after the account data section on the dashboard
			 *
			 * @since 3.5
			 */
			do_action( 'rocket_dashboard_after_account_data' );
			?>
		</div>

		<div class="wpr-Page-col wpr-Page-col--fixed">
			<?php
			/**
			 * Fires in the dashboard sidebar
			 */
			do_action( 'rocket_dashboard_sidebar' );

			$this->render_part( 'quick-actions' );
			?>
		</div>
	</div>
</div>
