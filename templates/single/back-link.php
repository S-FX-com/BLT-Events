<?php
/**
 * "Back to events" button, laid over the top-left corner of the hero image
 * (or in the normal flow when the event has no image).
 *
 * Override: your-theme/blt-events/single/back-link.php
 *
 * @var bool   $show_back
 * @var string $events_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $show_back || ! $events_url ) {
	return;
}
?>
<a class="blt-event__back" href="<?php echo esc_url( $events_url ); ?>">
	<?php echo BLT_Events_Templates::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
	<?php esc_html_e( 'Back to events', 'blt-events' ); ?>
</a>
