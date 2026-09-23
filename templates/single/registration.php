<?php
/**
 * Registration panel at the bottom of the main column.
 *
 * Override: your-theme/blt-events/single/registration.php
 *
 * @var int  $event_id
 * @var bool $has_shortcode When the description already holds the form/block, nothing is added here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $has_shortcode ) {
	return;
}
?>
<div class="blt-event__section blt-event__registration" id="blt-event-registration">
	<h2 class="blt-event__section-title"><?php esc_html_e( 'Register', 'blt-events' ); ?></h2>
	<?php echo BLT_Events_Registration_Shortcode::render( array( 'event_id' => $event_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in templates. ?>
</div>
