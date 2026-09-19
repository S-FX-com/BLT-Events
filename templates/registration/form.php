<?php
/**
 * Registration form (Stripe or free events).
 *
 * Override: your-theme/blt-events/registration/form.php
 *
 * @var int     $event_id
 * @var WP_Post $event
 * @var string  $provider          Payment provider slug ('none', 'stripe').
 * @var array   $fields            Registrant field definitions.
 * @var array   $consent_fields    Consent checkbox definitions.
 * @var array   $ticket_types      Purchasable tickets, keyed by original index.
 * @var bool    $has_paid_tickets
 * @var bool    $stepped           Tickets -> details wizard.
 * @var bool    $collect_attendees Ask for each additional attendee's details.
 * @var array   $attendee_fields   Field definitions for additional attendees.
 * @var string  $nonce
 * @var int|null $spots_left       Seats left, or null when unlimited.
 * @var bool    $show_coupon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-registration-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<form id="blt-registration-form" class="blt-reg<?php echo $stepped ? ' blt-reg--stepped' : ''; ?>" method="post" novalidate <?php echo $stepped ? 'data-stepped="1"' : ''; ?> data-collect-attendees="<?php echo $collect_attendees ? '1' : '0'; ?>">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

		<?php if ( $stepped ) : ?>
		<ol class="blt-reg__progress" aria-hidden="true">
			<li class="blt-reg__progress-step is-current" data-step-label="1"><?php esc_html_e( 'Select tickets', 'blt-events' ); ?></li>
			<li class="blt-reg__progress-step" data-step-label="2"><?php esc_html_e( 'Attendee details', 'blt-events' ); ?></li>
			<li class="blt-reg__progress-step" data-step-label="3"><?php esc_html_e( 'Review & payment', 'blt-events' ); ?></li>
		</ol>
		<?php endif; ?>

		<?php if ( ! empty( $ticket_types ) ) : ?>
		<div class="blt-reg__step blt-ticket-selection" data-step="tickets">
			<h3 class="blt-reg__step-title"><?php esc_html_e( 'Select Tickets', 'blt-events' ); ?></h3>
			<?php if ( null !== $spots_left && $spots_left <= 10 ) : ?>
				<p class="blt-spots-left">
					<?php
					printf(
						/* translators: %d: number of spots left. */
						esc_html( _n( 'Only %d spot left', 'Only %d spots left', $spots_left, 'blt-events' ) ),
						(int) $spots_left
					);
					?>
				</p>
			<?php endif; ?>
			<div class="blt-ticket-header" aria-hidden="true">
				<span><?php esc_html_e( 'Ticket Type', 'blt-events' ); ?></span>
				<span><?php esc_html_e( 'Description', 'blt-events' ); ?></span>
				<span><?php esc_html_e( 'Price', 'blt-events' ); ?></span>
				<span><?php esc_html_e( 'Quantity', 'blt-events' ); ?></span>
			</div>
			<?php foreach ( $ticket_types as $i => $ticket ) : ?>
			<div class="blt-ticket-type" data-ticket-index="<?php echo (int) $i; ?>">
				<span class="blt-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></span>
				<?php if ( ! empty( $ticket['description'] ) ) : ?>
					<span class="blt-ticket-desc"><?php echo esc_html( $ticket['description'] ); ?></span>
				<?php endif; ?>
				<span class="blt-ticket-price"><?php echo (float) $ticket['price'] > 0 ? esc_html( BLT_Events_Helpers::format_price( $ticket['price'] ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
				<div class="blt-quantity-controls">
					<button type="button" class="blt-qty-btn minus-btn" aria-label="<?php esc_attr_e( 'Decrease quantity', 'blt-events' ); ?>">&minus;</button>
					<input type="number" name="ticket_quantity_<?php echo (int) $i; ?>" class="blt-ticket-quantity" value="0" min="0" data-price="<?php echo esc_attr( $ticket['price'] ); ?>" data-index="<?php echo (int) $i; ?>" data-name="<?php echo esc_attr( $ticket['name'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket name. */ __( 'Quantity for %s', 'blt-events' ), $ticket['name'] ) ); ?>" />
					<button type="button" class="blt-qty-btn plus-btn" aria-label="<?php esc_attr_e( 'Increase quantity', 'blt-events' ); ?>">+</button>
				</div>
			</div>
			<?php endforeach; ?>
			<div class="blt-total-amount" aria-live="polite"><?php echo esc_html( BLT_Events_Helpers::format_price( 0, true ) ); ?></div>
		</div>
		<?php endif; ?>

		<div class="blt-reg__step" data-step="details">
			<div class="blt-fields-section">
				<h3 class="blt-reg__step-title"><?php esc_html_e( 'Your Details', 'blt-events' ); ?></h3>
				<div class="blt-fields-grid">
					<?php foreach ( $fields as $field ) : ?>
						<?php echo BLT_Events_Fieldsets::render_field( $field, BLT_Events_Fieldsets::prefill_value( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field(). ?>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( $collect_attendees ) : ?>
			<div class="blt-attendees-section" data-blt-attendees hidden>
				<h3 class="blt-reg__step-title"><?php esc_html_e( 'Additional Attendees', 'blt-events' ); ?></h3>
				<p class="blt-field-desc"><?php esc_html_e( 'You are attendee 1. Tell us who the other tickets are for.', 'blt-events' ); ?></p>
				<div class="blt-attendees-list" data-blt-attendees-list></div>
				<script type="text/html" id="tmpl-blt-attendee">
					<?php
					BLT_Events_Templates::include_template( 'registration/attendee.php', array(
						'index'  => '__i__',
						'fields' => $attendee_fields,
					) );
					?>
				</script>
			</div>
			<?php endif; ?>

			<?php if ( $show_coupon ) : ?>
			<div class="blt-coupon-section">
				<label for="coupon_code"><?php esc_html_e( 'Coupon Code', 'blt-events' ); ?></label>
				<div class="blt-coupon-input">
					<input type="text" id="coupon_code" name="coupon_code" placeholder="<?php echo esc_attr__( 'Enter coupon code', 'blt-events' ); ?>" autocomplete="off" />
					<button type="button" id="blt-apply-coupon" class="blt-btn-secondary"><?php esc_html_e( 'Apply', 'blt-events' ); ?></button>
				</div>
				<div id="blt-coupon-message" role="status" aria-live="polite" hidden></div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $consent_fields ) ) : ?>
			<div class="blt-consent-section">
				<?php foreach ( $consent_fields as $cf ) : ?>
				<div class="blt-consent-field">
					<label>
						<input type="checkbox" name="consent_<?php echo esc_attr( $cf['key'] ); ?>" value="1" <?php echo ! empty( $cf['required'] ) ? 'required' : ''; ?> />
						<span><?php echo wp_kses_post( $cf['label'] ); ?><?php if ( ! empty( $cf['required'] ) ) : ?> <span class="blt-required" aria-hidden="true">*</span><?php endif; ?></span>
					</label>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( ! $stepped ) : ?>
			<div id="blt-form-messages" role="status" aria-live="polite" hidden></div>
			<div class="blt-form-actions">
				<button type="submit" class="blt-submit-btn" id="blt-submit-btn">
					<?php esc_html_e( 'Register — Free', 'blt-events' ); ?>
				</button>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( $stepped ) : ?>
		<div class="blt-reg__step blt-reg__review" data-step="review">
			<h3 class="blt-reg__step-title"><?php esc_html_e( 'Review & payment', 'blt-events' ); ?></h3>
			<div class="blt-reg__review-summary" aria-live="polite">
				<p class="blt-reg__review-empty"><?php esc_html_e( 'Your selected tickets will appear here.', 'blt-events' ); ?></p>
				<ul class="blt-reg__review-tickets"></ul>
				<p class="blt-reg__review-total"></p>
			</div>
			<?php if ( $provider === 'stripe' && $has_paid_tickets ) : ?>
			<div class="blt-payment-section" id="blt-payment-section" hidden>
				<h3><?php esc_html_e( 'Payment', 'blt-events' ); ?></h3>
				<div id="blt-card-element"></div>
				<div id="blt-card-errors" role="alert"></div>
			</div>
			<?php endif; ?>

			<div id="blt-form-messages" role="status" aria-live="polite" hidden></div>

			<div class="blt-form-actions">
				<button type="submit" class="blt-submit-btn" id="blt-submit-btn" <?php echo $stepped ? 'disabled' : ''; ?>>
					<?php echo $stepped ? esc_html__( 'Select tickets to continue', 'blt-events' ) : esc_html__( 'Register — Free', 'blt-events' ); ?>
				</button>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $stepped ) : ?>
		<div class="blt-reg__nav">
			<button type="button" class="blt-reg__back" hidden><?php esc_html_e( 'Back', 'blt-events' ); ?></button>
			<button type="button" class="blt-reg__next"><?php esc_html_e( 'Next', 'blt-events' ); ?></button>
		</div>
		<?php endif; ?>
	</form>
</div>
