<?php
/**
 * FluentCart checkout: ticket selection + redirect to instant checkout.
 *
 * Override: your-theme/blt-events/registration/fluentcart.php
 *
 * @var int     $event_id
 * @var WP_Post $event
 * @var array   $ticket_types  Purchasable tickets keyed by original index.
 * @var array   $variation_ids FluentCart variation IDs keyed by ticket index.
 * @var bool    $ready         Products synced and FluentCart active.
 * @var string  $message       Shown when not ready.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-fluentcart-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<?php if ( ! $ready ) : ?>
		<div class="blt-sync-notice">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php else : ?>
		<div class="blt-ticket-selection">
			<h3><?php esc_html_e( 'Select Tickets', 'blt-events' ); ?></h3>
			<?php foreach ( $ticket_types as $i => $ticket ) : ?>
				<?php if ( empty( $variation_ids[ $i ] ) ) { continue; } ?>
				<label class="blt-ticket-type">
					<input type="radio" name="blt-fc-ticket"
						value="<?php echo esc_attr( $variation_ids[ $i ] ); ?>"
						data-price="<?php echo esc_attr( isset( $ticket['price'] ) ? (float) $ticket['price'] : 0 ); ?>" />
					<span class="blt-ticket-name"><?php echo esc_html( isset( $ticket['name'] ) ? $ticket['name'] : __( 'Ticket', 'blt-events' ) ); ?></span>
					<span class="blt-ticket-price"><?php echo isset( $ticket['price'] ) && (float) $ticket['price'] > 0 ? esc_html( BLT_Events_Helpers::format_price( $ticket['price'] ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
				</label>
			<?php endforeach; ?>

			<div class="blt-quantity-controls">
				<label for="blt-fc-quantity-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Quantity', 'blt-events' ); ?></label>
				<input type="number" id="blt-fc-quantity-<?php echo esc_attr( $event_id ); ?>" class="blt-fc-quantity" value="1" min="1" />
			</div>

			<div class="blt-total-amount" aria-live="polite"><?php echo esc_html( BLT_Events_Helpers::format_price( 0, true ) ); ?></div>
		</div>

		<div class="blt-checkout-actions">
			<button type="button" class="blt-fc-checkout-btn blt-submit-btn" disabled>
				<?php esc_html_e( 'Proceed to Checkout', 'blt-events' ); ?>
			</button>
			<p class="blt-checkout-note"><?php esc_html_e( 'You will be redirected to the secure checkout page.', 'blt-events' ); ?></p>
		</div>
	<?php endif; ?>
</div>
