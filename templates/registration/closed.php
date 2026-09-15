<?php
/**
 * Shown instead of the form when registration is not possible.
 *
 * Override: your-theme/blt-events/registration/closed.php
 *
 * @var string $reason   not_open | cutoff | sold_out | no_tickets | syncing
 * @var string $message
 * @var int    $event_id
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-registration-closed blt-registration-closed--<?php echo esc_attr( $reason ); ?>" data-reason="<?php echo esc_attr( $reason ); ?>">
	<p><?php echo esc_html( $message ); ?></p>
</div>
