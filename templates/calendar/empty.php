<?php
/**
 * Empty state for the list and grid views.
 *
 * Override: your-theme/blt-events/calendar/empty.php
 *
 * @var string $view    list | grid
 * @var string $message
 * @var string $search  The visitor's search term, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-events-empty blt-events-empty--<?php echo esc_attr( $view ); ?>">
	<p><?php echo esc_html( $message ); ?></p>
</div>
