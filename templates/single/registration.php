<?php
/**
 * Registration panel at the bottom of the main column.
 *
 * Override: your-theme/blt-events/single/registration.php
 *
 * @var int    $event_id
 * @var bool   $has_shortcode When the description already holds the form/block, nothing is added here.
 * @var string $external_registration_url Set when a third-party site/microsite handles registration — nothing is added here either.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $has_shortcode || ! empty( $external_registration_url ) ) {
	return;
}
?>
<section class="blt-event__section blt-event__registration" id="blt-event-registration" aria-labelledby="blt-event-registration-<?php echo esc_attr( $event_id ); ?>-title">
	<h2 class="blt-event__section-title" id="blt-event-registration-<?php echo esc_attr( $event_id ); ?>-title"><?php esc_html_e( 'Register', 'blt-events' ); ?></h2>
	<?php echo BLT_Events_Registration_Shortcode::render( array( 'event_id' => $event_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in templates. ?>
</section>
