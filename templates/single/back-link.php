<?php
/**
 * "All events" back link.
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
<a class="blt-event__back" href="<?php echo esc_url( $events_url ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'All events', 'blt-events' ); ?></a>
