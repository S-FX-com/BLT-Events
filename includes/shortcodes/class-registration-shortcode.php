<?php
/**
 * CMT Events - Registration Shortcode
 *
 * [cmt_event_registration] - Renders the event registration form.
 * Usage: [cmt_event_registration event_id="123"]
 * If no event_id, uses current post if it's an event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMT_Events_Registration_Shortcode {

	public static function init() {
		add_shortcode( 'cmt_event_registration', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'event_id' => 0,
		), $atts );

		$event_id = absint( $atts['event_id'] );
		if ( ! $event_id ) {
			$event_id = get_the_ID();
		}

		if ( ! $event_id || get_post_type( $event_id ) !== 'event' ) {
			return '<p class="cmt-error">No valid event specified.</p>';
		}

		$event = get_post( $event_id );
		if ( ! $event || $event->post_status !== 'publish' ) {
			return '<p class="cmt-error">Event not found.</p>';
		}

		// Check if registration is open
		$registration_open = get_post_meta( $event_id, '_cmt_registration_open', true );
		if ( $registration_open !== '1' ) {
			return '<div class="cmt-registration-closed"><p>Registration is currently closed for this event.</p></div>';
		}

		// Check capacity
		$capacity = (int) get_post_meta( $event_id, '_cmt_capacity', true );
		if ( $capacity > 0 ) {
			$reg_db = new CMT_Events_Registrations_DB();
			$current_count = $reg_db->get_event_registration_count( $event_id );
			if ( $current_count >= $capacity ) {
				return '<div class="cmt-registration-closed"><p>This event is sold out.</p></div>';
			}
		}

		$provider = CMT_Events_Helpers::get_payment_provider();

		// Route to appropriate renderer
		if ( $provider === 'surecart' ) {
			return self::render_surecart_form( $event_id, $event );
		}

		return self::render_standard_form( $event_id, $event, $provider );
	}

	/**
	 * Render the standard registration form (Stripe or free).
	 */
	private static function render_standard_form( $event_id, $event, $provider ) {
		$fieldset = CMT_Events_Fieldsets::get_event_fieldset( $event_id );
		$fields   = CMT_Events_Fieldsets::get_fields( $fieldset );
		$consent_fields = CMT_Events_Fieldsets::get_consent_fields( $fieldset );

		$ticket_types_raw = get_post_meta( $event_id, '_cmt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		$ticket_types     = is_array( $ticket_types ) ? $ticket_types : array();

		$has_paid_tickets = false;
		foreach ( $ticket_types as $t ) {
			if ( isset( $t['price'] ) && (float) $t['price'] > 0 ) {
				$has_paid_tickets = true;
				break;
			}
		}

		// Enqueue scripts
		wp_enqueue_script( 'cmt-events-registration', CMT_EVENTS_PLUGIN_URL . 'assets/js/registration-form.js', array( 'jquery' ), CMT_EVENTS_VERSION, true );
		wp_localize_script( 'cmt-events-registration', 'cmtRegData', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cmt_registration_nonce' ),
			'eventId'  => $event_id,
			'currency' => CMT_Events_Helpers::get_currency_config(),
			'provider' => $provider,
		) );

		if ( $provider === 'stripe' && $has_paid_tickets ) {
			wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
			wp_enqueue_script( 'cmt-events-payment' );
		}

		ob_start();
		?>
		<div class="cmt-registration-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
			<form id="cmt-registration-form" method="post" novalidate>
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'cmt_registration_nonce' ) ); ?>" />

				<!-- Ticket Selection -->
				<?php if ( ! empty( $ticket_types ) ) : ?>
				<div class="cmt-ticket-selection">
					<h3>Select Tickets</h3>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
					<div class="cmt-ticket-type">
						<span class="cmt-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></span>
						<?php if ( ! empty( $ticket['description'] ) ) : ?>
							<span class="cmt-ticket-desc"><?php echo esc_html( $ticket['description'] ); ?></span>
						<?php endif; ?>
						<span class="cmt-ticket-price"><?php echo (float) $ticket['price'] > 0 ? esc_html( CMT_Events_Helpers::format_price( $ticket['price'] ) ) : 'Free'; ?></span>
						<div class="cmt-quantity-controls">
							<button type="button" class="cmt-qty-btn minus-btn">&minus;</button>
							<input type="number" name="ticket_quantity_<?php echo $i; ?>" class="cmt-ticket-quantity" value="0" min="0" data-price="<?php echo esc_attr( $ticket['price'] ); ?>" data-index="<?php echo $i; ?>" />
							<button type="button" class="cmt-qty-btn plus-btn">+</button>
						</div>
					</div>
					<?php endforeach; ?>
					<div class="cmt-total-amount">Total: <?php echo esc_html( CMT_Events_Helpers::format_price( 0 ) ); ?></div>
				</div>
				<?php endif; ?>

				<!-- Registration Fields -->
				<div class="cmt-fields-section">
					<h3>Your Details</h3>
					<div class="cmt-fields-grid">
						<?php foreach ( $fields as $field ) : ?>
							<?php echo CMT_Events_Fieldsets::render_field( $field ); ?>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Coupon Code -->
				<?php if ( $has_paid_tickets ) : ?>
				<div class="cmt-coupon-section">
					<label for="coupon_code">Coupon Code</label>
					<div class="cmt-coupon-input">
						<input type="text" id="coupon_code" name="coupon_code" placeholder="Enter coupon code" />
						<button type="button" id="cmt-apply-coupon" class="cmt-btn-secondary">Apply</button>
					</div>
					<div id="cmt-coupon-message" style="display:none;"></div>
				</div>
				<?php endif; ?>

				<!-- Consent Fields -->
				<?php if ( ! empty( $consent_fields ) ) : ?>
				<div class="cmt-consent-section">
					<?php foreach ( $consent_fields as $cf ) : ?>
					<div class="cmt-consent-field">
						<label>
							<input type="checkbox" name="consent_<?php echo esc_attr( $cf['key'] ); ?>" value="1" <?php echo ! empty( $cf['required'] ) ? 'required' : ''; ?> />
							<?php echo wp_kses_post( $cf['label'] ); ?>
							<?php if ( ! empty( $cf['required'] ) ) : ?><span class="cmt-required">*</span><?php endif; ?>
						</label>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<!-- Stripe Card Element (for paid events with Stripe) -->
				<?php if ( $provider === 'stripe' && $has_paid_tickets ) : ?>
				<div class="cmt-payment-section" id="cmt-payment-section" style="display:none;">
					<h3>Payment</h3>
					<div id="cmt-card-element"></div>
					<div id="cmt-card-errors" role="alert"></div>
				</div>
				<?php endif; ?>

				<!-- Messages -->
				<div id="cmt-form-messages" style="display:none;"></div>

				<!-- Submit -->
				<div class="cmt-form-actions">
					<button type="submit" class="cmt-submit-btn" id="cmt-submit-btn" disabled>
						Select tickets to continue
					</button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the SureCart checkout form (ticket selection + redirect).
	 */
	private static function render_surecart_form( $event_id, $event ) {
		$ticket_types_raw = get_post_meta( $event_id, '_cmt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		$ticket_types     = is_array( $ticket_types ) ? $ticket_types : array();

		$price_ids = get_post_meta( $event_id, '_cmt_sc_price_ids', true ) ?: array();

		// Check if products are synced
		$all_synced = true;
		foreach ( $ticket_types as $i => $t ) {
			if ( empty( $price_ids[ $i ] ) ) {
				$all_synced = false;
				break;
			}
		}

		wp_enqueue_script( 'cmt-events-surecart-checkout' );

		ob_start();
		?>
		<div class="cmt-surecart-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
			<?php if ( ! $all_synced ) : ?>
				<div class="cmt-sync-notice">
					<p>Tickets for this event are being set up. Please check back shortly.</p>
				</div>
			<?php else : ?>
				<div class="cmt-ticket-selection">
					<h3>Select Tickets</h3>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
						<?php if ( empty( $price_ids[ $i ] ) ) continue; ?>
						<div class="cmt-ticket-type">
							<span class="cmt-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></span>
							<span class="cmt-ticket-price"><?php echo (float) $ticket['price'] > 0 ? esc_html( CMT_Events_Helpers::format_price( $ticket['price'] ) ) : 'Free'; ?></span>
							<div class="cmt-quantity-controls">
								<button type="button" class="cmt-qty-btn minus-btn">&minus;</button>
								<input type="number" class="sc-ticket-quantity" value="0" min="0"
									data-price="<?php echo esc_attr( $ticket['price'] ); ?>"
									data-price-id="<?php echo esc_attr( $price_ids[ $i ] ); ?>" />
								<button type="button" class="cmt-qty-btn plus-btn">+</button>
							</div>
						</div>
					<?php endforeach; ?>
					<div class="cmt-total-amount">Total: <?php echo esc_html( CMT_Events_Helpers::format_price( 0 ) ); ?></div>
				</div>

				<div class="cmt-checkout-actions">
					<button type="button" id="cmt-sc-checkout-btn" class="cmt-submit-btn" disabled>
						Proceed to Checkout
					</button>
					<p class="cmt-checkout-note">You will be redirected to the secure checkout page.</p>
				</div>

				<div id="cmt-sc-message" class="cmt-checkout-message" style="display:none;"></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
