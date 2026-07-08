<?php
/**
 * BLT Events - Registration Shortcode
 *
 * [blt_event_registration] - Renders the event registration form.
 * Usage: [blt_event_registration event_id="123"]
 * If no event_id, uses current post if it's an event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Registration_Shortcode {

	public static function init() {
		add_shortcode( 'blt_event_registration', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'event_id' => 0,
		), $atts );

		$event_id = absint( $atts['event_id'] );
		if ( ! $event_id ) {
			$event_id = get_the_ID();
		}

		// Backstop for shortcodes rendered outside post content (widgets,
		// page builders) where the has_shortcode() detection can't see them.
		wp_enqueue_style( 'blt-events' );
		wp_enqueue_script( 'blt-events' );

		if ( ! $event_id || get_post_type( $event_id ) !== 'event' ) {
			return '<p class="blt-error">' . esc_html__( 'No valid event specified.', 'blt-events' ) . '</p>';
		}

		$event = get_post( $event_id );
		if ( ! $event || $event->post_status !== 'publish' ) {
			return '<p class="blt-error">' . esc_html__( 'Event not found.', 'blt-events' ) . '</p>';
		}

		// Check if registration is open
		$registration_open = get_post_meta( $event_id, '_blt_registration_open', true );
		if ( $registration_open !== '1' ) {
			return '<div class="blt-registration-closed"><p>' . esc_html__( 'Registration is currently closed for this event.', 'blt-events' ) . '</p></div>';
		}

		// Check capacity
		$capacity = (int) get_post_meta( $event_id, '_blt_capacity', true );
		if ( $capacity > 0 ) {
			$reg_db = new BLT_Events_Registrations_DB();
			$current_count = $reg_db->get_event_registration_count( $event_id );
			if ( $current_count >= $capacity ) {
				return '<div class="blt-registration-closed"><p>' . esc_html__( 'This event is sold out.', 'blt-events' ) . '</p></div>';
			}
		}

		$provider = BLT_Events_Helpers::get_payment_provider();

		// Route to appropriate renderer
		if ( $provider === 'surecart' ) {
			return self::render_surecart_form( $event_id, $event );
		}

		if ( $provider === 'fluentcart' ) {
			return self::render_fluentcart_form( $event_id, $event );
		}

		return self::render_standard_form( $event_id, $event, $provider );
	}

	/**
	 * Render the standard registration form (Stripe or free).
	 */
	private static function render_standard_form( $event_id, $event, $provider ) {
		$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $event_id );
		$fields   = BLT_Events_Fieldsets::get_fields( $fieldset );
		$consent_fields = BLT_Events_Fieldsets::get_consent_fields( $fieldset );

		$ticket_types_raw = get_post_meta( $event_id, '_blt_ticket_types', true );
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
		wp_enqueue_script( 'blt-events-registration', BLT_EVENTS_PLUGIN_URL . 'assets/js/registration-form.js', array( 'jquery' ), BLT_EVENTS_VERSION, true );
		wp_localize_script( 'blt-events-registration', 'bltRegData', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'blt_registration_nonce' ),
			'eventId'  => $event_id,
			'currency' => BLT_Events_Helpers::get_currency_config(),
			'provider' => $provider,
		) );

		if ( $provider === 'stripe' && $has_paid_tickets ) {
			wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
			wp_enqueue_script( 'blt-events-payment' );
		}

		ob_start();
		?>
		<div class="blt-registration-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
			<form id="blt-registration-form" method="post" novalidate>
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'blt_registration_nonce' ) ); ?>" />

				<!-- Ticket Selection -->
				<?php if ( ! empty( $ticket_types ) ) : ?>
				<div class="blt-ticket-selection">
					<h3><?php esc_html_e( 'Select Tickets', 'blt-events' ); ?></h3>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
					<div class="blt-ticket-type">
						<span class="blt-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></span>
						<?php if ( ! empty( $ticket['description'] ) ) : ?>
							<span class="blt-ticket-desc"><?php echo esc_html( $ticket['description'] ); ?></span>
						<?php endif; ?>
						<span class="blt-ticket-price"><?php echo (float) $ticket['price'] > 0 ? esc_html( BLT_Events_Helpers::format_price( $ticket['price'] ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
						<div class="blt-quantity-controls">
							<button type="button" class="blt-qty-btn minus-btn">&minus;</button>
							<input type="number" name="ticket_quantity_<?php echo (int) $i; ?>" class="blt-ticket-quantity" value="0" min="0" data-price="<?php echo esc_attr( $ticket['price'] ); ?>" data-index="<?php echo (int) $i; ?>" />
							<button type="button" class="blt-qty-btn plus-btn">+</button>
						</div>
					</div>
					<?php endforeach; ?>
					<div class="blt-total-amount"><?php esc_html_e( 'Total:', 'blt-events' ); ?> <?php echo esc_html( BLT_Events_Helpers::format_price( 0 ) ); ?></div>
				</div>
				<?php endif; ?>

				<!-- Registration Fields -->
				<div class="blt-fields-section">
					<h3><?php esc_html_e( 'Your Details', 'blt-events' ); ?></h3>
					<div class="blt-fields-grid">
						<?php foreach ( $fields as $field ) : ?>
							<?php echo BLT_Events_Fieldsets::render_field( $field ); ?>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Coupon Code -->
				<?php if ( $has_paid_tickets ) : ?>
				<div class="blt-coupon-section">
					<label for="coupon_code"><?php esc_html_e( 'Coupon Code', 'blt-events' ); ?></label>
					<div class="blt-coupon-input">
						<input type="text" id="coupon_code" name="coupon_code" placeholder="<?php echo esc_attr__( 'Enter coupon code', 'blt-events' ); ?>" />
						<button type="button" id="blt-apply-coupon" class="blt-btn-secondary"><?php esc_html_e( 'Apply', 'blt-events' ); ?></button>
					</div>
					<div id="blt-coupon-message" style="display:none;"></div>
				</div>
				<?php endif; ?>

				<!-- Consent Fields -->
				<?php if ( ! empty( $consent_fields ) ) : ?>
				<div class="blt-consent-section">
					<?php foreach ( $consent_fields as $cf ) : ?>
					<div class="blt-consent-field">
						<label>
							<input type="checkbox" name="consent_<?php echo esc_attr( $cf['key'] ); ?>" value="1" <?php echo ! empty( $cf['required'] ) ? 'required' : ''; ?> />
							<?php echo wp_kses_post( $cf['label'] ); ?>
							<?php if ( ! empty( $cf['required'] ) ) : ?><span class="blt-required">*</span><?php endif; ?>
						</label>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<!-- Stripe Card Element (for paid events with Stripe) -->
				<?php if ( $provider === 'stripe' && $has_paid_tickets ) : ?>
				<div class="blt-payment-section" id="blt-payment-section" style="display:none;">
					<h3><?php esc_html_e( 'Payment', 'blt-events' ); ?></h3>
					<div id="blt-card-element"></div>
					<div id="blt-card-errors" role="alert"></div>
				</div>
				<?php endif; ?>

				<!-- Messages -->
				<div id="blt-form-messages" style="display:none;"></div>

				<!-- Submit -->
				<div class="blt-form-actions">
					<button type="submit" class="blt-submit-btn" id="blt-submit-btn" disabled>
						<?php esc_html_e( 'Select tickets to continue', 'blt-events' ); ?>
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
		$ticket_types_raw = get_post_meta( $event_id, '_blt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		$ticket_types     = is_array( $ticket_types ) ? $ticket_types : array();

		$price_ids = get_post_meta( $event_id, '_blt_sc_price_ids', true ) ?: array();

		// Check if products are synced
		$all_synced = true;
		foreach ( $ticket_types as $i => $t ) {
			if ( empty( $price_ids[ $i ] ) ) {
				$all_synced = false;
				break;
			}
		}

		wp_enqueue_script( 'blt-events-surecart-checkout' );

		ob_start();
		?>
		<div class="blt-surecart-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
			<?php if ( ! $all_synced ) : ?>
				<div class="blt-sync-notice">
					<p><?php esc_html_e( 'Tickets for this event are being set up. Please check back shortly.', 'blt-events' ); ?></p>
				</div>
			<?php else : ?>
				<div class="blt-ticket-selection">
					<h3><?php esc_html_e( 'Select Tickets', 'blt-events' ); ?></h3>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
						<?php if ( empty( $price_ids[ $i ] ) ) continue; ?>
						<div class="blt-ticket-type">
							<span class="blt-ticket-name"><?php echo esc_html( $ticket['name'] ); ?></span>
							<span class="blt-ticket-price"><?php echo (float) $ticket['price'] > 0 ? esc_html( BLT_Events_Helpers::format_price( $ticket['price'] ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
							<div class="blt-quantity-controls">
								<button type="button" class="blt-qty-btn minus-btn">&minus;</button>
								<input type="number" class="sc-ticket-quantity" value="0" min="0"
									data-price="<?php echo esc_attr( $ticket['price'] ); ?>"
									data-price-id="<?php echo esc_attr( $price_ids[ $i ] ); ?>" />
								<button type="button" class="blt-qty-btn plus-btn">+</button>
							</div>
						</div>
					<?php endforeach; ?>
					<div class="blt-total-amount"><?php esc_html_e( 'Total:', 'blt-events' ); ?> <?php echo esc_html( BLT_Events_Helpers::format_price( 0 ) ); ?></div>
				</div>

				<div class="blt-checkout-actions">
					<button type="button" id="blt-sc-checkout-btn" class="blt-submit-btn" disabled>
						<?php esc_html_e( 'Proceed to Checkout', 'blt-events' ); ?>
					</button>
					<p class="blt-checkout-note"><?php esc_html_e( 'You will be redirected to the secure checkout page.', 'blt-events' ); ?></p>
				</div>

				<div id="blt-sc-message" class="blt-checkout-message" style="display:none;"></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the FluentCart checkout form (ticket selection + redirect
	 * to FluentCart instant checkout).
	 */
	private static function render_fluentcart_form( $event_id, $event ) {
		$ticket_types_raw = get_post_meta( $event_id, '_blt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		$ticket_types     = is_array( $ticket_types ) ? $ticket_types : array();

		$variation_ids = get_post_meta( $event_id, '_blt_fc_variation_ids', true );
		$variation_ids = is_array( $variation_ids ) ? $variation_ids : array();

		// Check whether every ticket type has a synced FluentCart variation.
		$all_synced = ! empty( $ticket_types );
		foreach ( $ticket_types as $i => $t ) {
			if ( empty( $variation_ids[ $i ] ) ) {
				$all_synced = false;
				break;
			}
		}

		wp_enqueue_script( 'blt-events-fluentcart-checkout' );

		ob_start();
		?>
		<div class="blt-fluentcart-form" data-event-id="<?php echo esc_attr( $event_id ); ?>">
			<?php if ( ! $all_synced || ! BLT_Events_FluentCart_Integration::is_configured() ) : ?>
				<div class="blt-sync-notice">
					<p><?php esc_html_e( 'Tickets for this event are being set up. Please check back shortly.', 'blt-events' ); ?></p>
				</div>
			<?php else : ?>
				<div class="blt-ticket-selection">
					<h3><?php esc_html_e( 'Select Tickets', 'blt-events' ); ?></h3>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
						<?php if ( empty( $variation_ids[ $i ] ) ) continue; ?>
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

					<div class="blt-total-amount"><?php echo esc_html( BLT_Events_Helpers::format_price( 0, true ) ); ?></div>
				</div>

				<div class="blt-checkout-actions">
					<button type="button" class="blt-fc-checkout-btn blt-submit-btn" disabled>
						<?php esc_html_e( 'Proceed to Checkout', 'blt-events' ); ?>
					</button>
					<p class="blt-checkout-note"><?php esc_html_e( 'You will be redirected to the secure checkout page.', 'blt-events' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
