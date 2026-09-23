<?php
/**
 * SureCart checkout: ticket selection + redirect.
 *
 * Override: your-theme/blt-events/registration/surecart.php
 *
 * @var int     $event_id
 * @var WP_Post $event
 * @var array   $ticket_types Purchasable tickets keyed by original index.
 * @var array   $price_ids    SureCart price IDs keyed by ticket index.
 * @var bool    $ready        Products synced and API connected.
 * @var string  $message      Shown when not ready.
 * @var string  $member_login "Log in for Member Rates" prompt HTML, or ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-surecart-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<?php if ( ! $ready ) : ?>
		<div class="blt-sync-notice">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php else : ?>
		<?php echo $member_login ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered from registration/member-login.php. ?>
		<div class="blt-ticket-selection">
			<h3><?php esc_html_e( 'Select Tickets', 'blt-events' ); ?></h3>
			<?php foreach ( $ticket_types as $i => $ticket ) : ?>
				<?php if ( empty( $price_ids[ $i ] ) ) { continue; } ?>
				<div class="blt-ticket-type">
					<span class="blt-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></span>
					<?php if ( ! empty( $ticket['description'] ) ) : ?>
						<span class="blt-ticket-desc"><?php echo esc_html( $ticket['description'] ); ?></span>
					<?php endif; ?>
					<span class="blt-ticket-price"><?php echo (float) $ticket['price'] > 0 ? esc_html( BLT_Events_Helpers::format_price( $ticket['price'] ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
					<div class="blt-quantity-controls">
						<button type="button" class="blt-qty-btn minus-btn" aria-label="<?php esc_attr_e( 'Decrease quantity', 'blt-events' ); ?>">&minus;</button>
						<input type="number" class="sc-ticket-quantity" value="0" min="0"
							data-price="<?php echo esc_attr( $ticket['price'] ); ?>"
							data-price-id="<?php echo esc_attr( $price_ids[ $i ] ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket name. */ __( 'Quantity for %s', 'blt-events' ), $ticket['name'] ) ); ?>" />
						<button type="button" class="blt-qty-btn plus-btn" aria-label="<?php esc_attr_e( 'Increase quantity', 'blt-events' ); ?>">+</button>
					</div>
				</div>
			<?php endforeach; ?>
			<div class="blt-total-amount" aria-live="polite"><?php echo esc_html( BLT_Events_Helpers::format_price( 0, true ) ); ?></div>
		</div>

		<div class="blt-checkout-actions">
			<button type="button" id="blt-sc-checkout-btn" class="blt-submit-btn" disabled>
				<?php esc_html_e( 'Proceed to Checkout', 'blt-events' ); ?>
			</button>
			<p class="blt-checkout-note"><?php esc_html_e( 'You will be redirected to the secure checkout page.', 'blt-events' ); ?></p>
		</div>

		<div id="blt-sc-message" class="blt-checkout-message" role="status" aria-live="polite" style="display:none;"></div>
	<?php endif; ?>
</div>
