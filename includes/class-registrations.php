<?php
/**
 * BLT Events - Registrations Business Logic
 *
 * Processes new registrations, handles multi-attendee logic,
 * coupon application, confirmation emails, and AJAX endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Registrations {

	private static $reg_db;
	private static $att_db;

	public static function init() {
		self::$reg_db = new BLT_Events_Registrations_DB();
		self::$att_db = new BLT_Events_Attendees_DB();

		// AJAX endpoints for free/direct registrations
		add_action( 'wp_ajax_blt_register', array( __CLASS__, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_blt_register', array( __CLASS__, 'ajax_register' ) );

		// AJAX endpoint for coupon validation
		add_action( 'wp_ajax_blt_validate_coupon', array( __CLASS__, 'ajax_validate_coupon' ) );
		add_action( 'wp_ajax_nopriv_blt_validate_coupon', array( __CLASS__, 'ajax_validate_coupon' ) );

		// Hooks
		add_action( 'blt_registration_created', array( __CLASS__, 'send_confirmation_email' ), 10, 2 );
	}

	/**
	 * AJAX handler for direct registration (free events / no payment gateway).
	 */
	public static function ajax_register() {
		check_ajax_referer( 'blt_registration_nonce', 'nonce' );

		if ( ! self::check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Too many registration attempts. Please try again in a few minutes.', 'blt-events' ) ) );
		}

		$event_id = absint( $_POST['event_id'] ?? 0 );
		if ( ! $event_id || get_post_type( $event_id ) !== 'event' || get_post_status( $event_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'blt-events' ) ) );
		}

		if ( get_post_meta( $event_id, '_blt_registration_open', true ) !== '1' ) {
			wp_send_json_error( array( 'message' => __( 'Registration is closed for this event.', 'blt-events' ) ) );
		}

		$result = self::process_registration( $event_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'         => __( 'Registration successful!', 'blt-events' ),
			'registration_id' => $result['registration_id'],
			'group_id'        => $result['group_id'],
		) );
	}

	/**
	 * Simple per-IP rate limit for the public registration endpoint,
	 * limiting database-flooding and email spam. The nonce alone does not
	 * throttle, since it is rendered to every visitor.
	 *
	 * @return bool True when the request is within the limit.
	 */
	private static function check_rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! $ip ) {
			return true;
		}

		/**
		 * Filter the maximum registrations allowed per IP per 10 minutes.
		 * Return 0 to disable rate limiting.
		 */
		$max = (int) apply_filters( 'blt_events_registration_rate_limit', 10 );
		if ( $max <= 0 ) {
			return true;
		}

		$key   = 'blt_reg_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Process a new registration.
	 *
	 * Two callers with very different trust models share this method:
	 *
	 *  - The on-site form (AJAX / Stripe), where nothing has been charged yet.
	 *    Every guard is fatal: rejecting the submission costs the visitor
	 *    nothing but a re-try.
	 *  - An off-site checkout webhook (SureCart, FluentCart), where the buyer
	 *    has already been charged. Here a fatal guard would mean money taken
	 *    with no registration and nothing surfaced to anyone, so the guards
	 *    instead record the registration as `pending`, attach the reason, and
	 *    fire `blt_registration_needs_review` for an admin to resolve.
	 *
	 * @param int   $event_id The event post ID.
	 * @param array $data     Submitted form data.
	 * @param array $payment  Optional payment data. Recognised keys: `provider`,
	 *                        `payment_id`, `payment_date`, `amount_paid`, and
	 *                        `line_items` (an array of `index`/`quantity` pairs
	 *                        resolved from the order by the provider).
	 * @return array|WP_Error Registration result array or WP_Error.
	 */
	public static function process_registration( $event_id, $data, $payment = array() ) {
		// Money already captured off-site: from here on, no guard may drop the
		// order on the floor.
		$captured = ! empty( $payment['payment_id'] );
		$review   = array();

		// The registration cutoff is also enforced server-side so stale or
		// hand-crafted submissions can't slip in after it passes.
		if ( BLT_Events_Helpers::registration_cutoff_passed( $event_id ) ) {
			if ( ! $captured ) {
				return new WP_Error( 'registration_closed', __( 'Registration for this event has closed.', 'blt-events' ) );
			}
			$review[] = __( 'Payment was taken after the registration cutoff had passed.', 'blt-events' );
		}

		// Get event fieldset and validate
		$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $event_id );
		if ( ! $fieldset ) {
			if ( ! $captured ) {
				return new WP_Error( 'no_fieldset', __( 'No fieldset configured for this event.', 'blt-events' ) );
			}
			$validated = self::minimal_registrant_data( $data );
			$review[]  = __( 'No fieldset is configured for this event, so only the buyer name and email were captured.', 'blt-events' );
		} else {
			// Validate primary registrant data. An off-site checkout only ever
			// returns a name and an email, so required fields it could not have
			// collected are flagged rather than treated as a failed submission.
			$validated = BLT_Events_Fieldsets::validate_submission( $fieldset, $data, ! $captured );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			if ( ! empty( $validated['_missing_required'] ) ) {
				$review[] = sprintf(
					/* translators: %s: comma-separated list of field labels. */
					__( 'Still to be collected from the attendee: %s.', 'blt-events' ),
					implode( ', ', $validated['_missing_required'] )
				);
			}
		}

		// What was actually bought. For an off-site checkout the order is the
		// authoritative record, so the provider resolves the line items and the
		// prices are read back from event meta here.
		$ticket_data = ( isset( $payment['line_items'] ) && is_array( $payment['line_items'] ) )
			? self::ticket_selections_from_line_items( $event_id, $payment['line_items'] )
			: self::parse_ticket_selections( $event_id, $data );

		$total_attendees = $ticket_data['total_quantity'];

		// Check capacity (under an advisory lock so concurrent submissions
		// cannot oversell the last spots).
		global $wpdb;
		$capacity  = (int) get_post_meta( $event_id, '_blt_capacity', true );
		$lock_name = 'blt_events_reg_' . $event_id;
		$locked    = false;

		if ( $capacity > 0 ) {
			$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

			$current_count = self::$reg_db->get_event_registration_count( $event_id );
			if ( ( $current_count + $total_attendees ) > $capacity ) {
				if ( ! $captured ) {
					if ( $locked ) {
						$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
					}
					return new WP_Error( 'capacity_exceeded', __( 'Sorry, there are not enough spots available.', 'blt-events' ) );
				}
				$review[] = __( 'The event was already at capacity when this payment completed; it may need a refund or a raised capacity.', 'blt-events' );
			}
		}

		// Duplicate check
		$email = $validated['email'] ?? '';
		if ( $email && self::$reg_db->email_registered_for_event( $email, $event_id ) ) {
			if ( ! $captured ) {
				if ( $locked ) {
					$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
				}
				return new WP_Error( 'duplicate_registration', __( 'This email is already registered for this event.', 'blt-events' ) );
			}
			$review[] = __( 'This email already had a registration for this event when the payment completed.', 'blt-events' );
		}

		// Calculate pricing
		$pricing = self::calculate_total( $event_id, $ticket_data, $data );

		// What the processor actually captured always wins over what the
		// plugin recomputes: the provider may have applied its own coupon,
		// tax or currency rounding that this side knows nothing about.
		$amount_paid = isset( $payment['amount_paid'] )
			? round( (float) $payment['amount_paid'], 2 )
			: $pricing['total'];

		// Keep the stored figures coherent, so subtotal minus discount always
		// equals what was paid. A cart-side coupon this plugin never saw would
		// otherwise show as a full-price order with a zero discount.
		$discount = $pricing['discount'];
		if ( $captured && $amount_paid < $pricing['subtotal'] ) {
			$discount = round( $pricing['subtotal'] - $amount_paid, 2 );
		}

		// Build customer name
		$customer_name = trim( ( $validated['first_name'] ?? '' ) . ' ' . ( $validated['last_name'] ?? '' ) );

		// Group ID for multi-attendee
		$group_id = $total_attendees > 1 ? BLT_Events_Helpers::generate_group_id() : null;

		// Determine status
		$is_free = $pricing['total'] <= 0 && $amount_paid <= 0;
		$status  = $is_free ? 'confirmed' : 'pending';

		if ( $captured ) {
			$status = 'confirmed';
		}

		// Events requiring manual approval hold every registration as
		// pending until an admin confirms it.
		if ( get_post_meta( $event_id, '_blt_require_approval', true ) === '1' ) {
			$status = 'pending';
		}

		// Anything flagged above is held back from the confirmed count until
		// an admin has looked at it.
		if ( ! empty( $review ) ) {
			$status = 'pending';
			$validated['_review'] = $review;
		}

		// Insert registration
		$reg_data = array(
			'event_id'        => $event_id,
			'group_id'        => $group_id,
			'customer_name'   => $customer_name,
			'customer_email'  => $email,
			'customer_phone'  => $validated['mobile_number'] ?? '',
			'attendee_count'  => $total_attendees,
			'custom_fields'   => wp_json_encode( $validated ),
			'total_amount'    => $pricing['subtotal'],
			'discount_amount' => $discount,
			'amount_paid'     => $amount_paid,
			'currency'        => BLT_Events_Helpers::get_currency_code(),
			'coupon_id'       => $pricing['coupon_id'] ?? null,
			'coupon_data'     => ! empty( $pricing['coupon_data'] ) ? wp_json_encode( $pricing['coupon_data'] ) : null,
			'payment_provider' => $payment['provider'] ?? ( $is_free ? 'free' : BLT_Events_Helpers::get_event_payment_provider( $event_id ) ),
			'payment_id'      => $payment['payment_id'] ?? null,
			'payment_date'    => $payment['payment_date'] ?? ( $is_free ? current_time( 'mysql' ) : null ),
			'status'          => $status,
		);

		$registration_id = self::$reg_db->insert( $reg_data );

		if ( $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		if ( ! $registration_id ) {
			return new WP_Error( 'db_error', __( 'Failed to create registration.', 'blt-events' ) );
		}

		// Insert attendees
		$attendees_data = self::build_attendees_data( $data, $ticket_data, $validated );
		self::$att_db->bulk_insert( $registration_id, $event_id, $attendees_data );

		// Update coupon usage
		if ( ! empty( $pricing['coupon_id'] ) ) {
			BLT_Events_Coupons::record_usage( $pricing['coupon_id'], $registration_id, $pricing['discount'] );
		}

		$result = array(
			'registration_id' => $registration_id,
			'group_id'        => $group_id,
			'total'           => $pricing['total'],
			'amount_paid'     => $amount_paid,
			'status'          => $status,
			'review'          => $review,
		);

		do_action( 'blt_registration_created', $registration_id, $result );

		if ( ! empty( $review ) ) {
			/**
			 * Fires when a registration was recorded but needs an admin to
			 * look at it — typically a completed off-site payment that failed
			 * one of the normal registration guards.
			 *
			 * @param int   $registration_id The new registration ID.
			 * @param array $review          Human-readable reasons.
			 * @param array $result          The registration result array.
			 */
			do_action( 'blt_registration_needs_review', $registration_id, $review, $result );
		}

		return $result;
	}

	/**
	 * Fallback registrant data for a captured payment on an event with no
	 * fieldset at all. Mirrors the keys the rest of the pipeline reads.
	 */
	private static function minimal_registrant_data( $data ) {
		return array(
			'first_name'    => sanitize_text_field( $data['first_name'] ?? '' ),
			'last_name'     => sanitize_text_field( $data['last_name'] ?? '' ),
			'email'         => sanitize_email( $data['email'] ?? '' ),
			'mobile_number' => BLT_Events_Helpers::sanitize_phone( $data['mobile_number'] ?? '' ),
			'_consents'     => array(),
		);
	}

	/**
	 * Build ticket selections from provider-resolved order line items.
	 *
	 * Deliberately reads the full ticket list rather than
	 * `available_ticket_types()`: the sale-window and role filters exist to
	 * gate what a visitor may put in a cart, and by the time a webhook runs
	 * there is no visitor session to evaluate roles against. Filtering here
	 * would silently drop legitimate member-only purchases and undercount a
	 * sale that closed between checkout and confirmation. Names and prices
	 * still come from event meta, never from the request.
	 *
	 * @param int   $event_id   The event post ID.
	 * @param array $line_items Array of arrays with `index` and `quantity`.
	 * @return array Ticket data in the shape parse_ticket_selections() returns.
	 */
	private static function ticket_selections_from_line_items( $event_id, $line_items ) {
		$ticket_types = BLT_Events_Helpers::get_ticket_types( $event_id );

		$selections     = array();
		$total_quantity = 0;
		$total_price    = 0;

		foreach ( $line_items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['index'] ) ) {
				continue;
			}

			$index = (int) $item['index'];
			if ( ! isset( $ticket_types[ $index ] ) ) {
				continue;
			}

			$ticket   = $ticket_types[ $index ];
			$quantity = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$price    = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;

			$selections[] = array(
				'index'    => $index,
				'name'     => $ticket['name'] ?? __( 'Ticket', 'blt-events' ),
				'price'    => $price,
				'quantity' => $quantity,
			);

			$total_quantity += $quantity;
			$total_price    += $price * $quantity;
		}

		// Fallback: single attendee if the order carried nothing we recognise.
		if ( 0 === $total_quantity ) {
			$total_quantity = 1;
		}

		return array(
			'selections'     => $selections,
			'total_quantity' => $total_quantity,
			'subtotal'       => $total_price,
		);
	}

	/**
	 * Calculate the authoritative order total for an event from submitted
	 * form data (ticket quantities + coupon code). Used by payment
	 * providers so charge amounts are never trusted from the client.
	 *
	 * @param int   $event_id The event post ID.
	 * @param array $data     Submitted form data (unslashed).
	 * @return array Pricing array: subtotal, discount, total, coupon_id, coupon_data.
	 */
	public static function calculate_order_total( $event_id, $data ) {
		$ticket_data = self::parse_ticket_selections( $event_id, $data );
		return self::calculate_total( $event_id, $ticket_data, $data );
	}

	/**
	 * Parse ticket type selections from form data.
	 */
	private static function parse_ticket_selections( $event_id, $data ) {
		// Only tickets inside their sale window and open to the current
		// visitor's role count; quantities submitted for hidden tickets
		// are ignored.
		$ticket_types = BLT_Events_Helpers::available_ticket_types( $event_id );

		$selections     = array();
		$total_quantity = 0;
		$total_price    = 0;

		foreach ( $ticket_types as $i => $ticket ) {
			$qty_key  = 'ticket_quantity_' . $i;
			$quantity = isset( $data[ $qty_key ] ) ? absint( $data[ $qty_key ] ) : 0;

			if ( $quantity > 0 ) {
				$price = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
				$selections[] = array(
					'index'    => $i,
					'name'     => $ticket['name'] ?? 'Ticket',
					'price'    => $price,
					'quantity' => $quantity,
				);
				$total_quantity += $quantity;
				$total_price    += $price * $quantity;
			}
		}

		// Fallback: single attendee if nothing selected
		if ( $total_quantity === 0 ) {
			$total_quantity = 1;
		}

		return array(
			'selections'     => $selections,
			'total_quantity'  => $total_quantity,
			'subtotal'        => $total_price,
		);
	}

	/**
	 * Calculate total price including group discounts and coupons.
	 */
	private static function calculate_total( $event_id, $ticket_data, $form_data ) {
		$subtotal  = $ticket_data['subtotal'];
		$discount  = 0;
		$coupon_id = null;
		$coupon_data = null;

		// Apply group discount
		$group_rules = get_post_meta( $event_id, '_blt_group_discount', true );
		if ( $group_rules ) {
			$group_calc = BLT_Events_Helpers::calculate_group_discount(
				$subtotal / max( $ticket_data['total_quantity'], 1 ),
				$ticket_data['total_quantity'],
				$group_rules
			);
			$discount += $group_calc['discount'];
		}

		// Apply coupon
		$coupon_code = isset( $form_data['coupon_code'] ) ? strtoupper( trim( $form_data['coupon_code'] ) ) : '';
		if ( $coupon_code ) {
			$coupon_result = BLT_Events_Coupons::validate_coupon( $coupon_code, $event_id, $ticket_data['total_quantity'] );
			if ( ! is_wp_error( $coupon_result ) ) {
				$coupon_discount = BLT_Events_Coupons::calculate_discount( $coupon_result, $subtotal - $discount );
				$discount  += $coupon_discount;
				$coupon_id  = $coupon_result->ID;
				$coupon_data = array(
					'code'     => $coupon_code,
					'type'     => get_post_meta( $coupon_result->ID, '_blt_discount_type', true ),
					'amount'   => get_post_meta( $coupon_result->ID, '_blt_amount', true ),
					'saved'    => $coupon_discount,
				);
			}
		}

		$total = max( 0, $subtotal - $discount );

		return array(
			'subtotal'    => round( $subtotal, 2 ),
			'discount'    => round( $discount, 2 ),
			'total'       => round( $total, 2 ),
			'coupon_id'   => $coupon_id,
			'coupon_data' => $coupon_data,
		);
	}

	/**
	 * Build attendee data arrays for bulk insertion.
	 */
	private static function build_attendees_data( $data, $ticket_data, $primary_validated ) {
		$attendees = array();

		// Primary registrant is always first attendee
		$attendees[] = array(
			'attendee_name'  => trim( ( $primary_validated['first_name'] ?? '' ) . ' ' . ( $primary_validated['last_name'] ?? '' ) ),
			'attendee_email' => $primary_validated['email'] ?? '',
			'attendee_phone' => $primary_validated['mobile_number'] ?? '',
			'ticket_type'    => ! empty( $ticket_data['selections'] ) ? $ticket_data['selections'][0]['name'] : null,
			'ticket_price'   => ! empty( $ticket_data['selections'] ) ? $ticket_data['selections'][0]['price'] : 0,
			'custom_fields'  => wp_json_encode( $primary_validated ),
		);

		// Server-side prices per ticket type name, so attendee rows never
		// store client-supplied prices.
		$ticket_prices = array();
		foreach ( $ticket_data['selections'] as $selection ) {
			$ticket_prices[ $selection['name'] ] = $selection['price'];
		}

		// Additional attendees from form data (bounded by the validated
		// total quantity so one request cannot flood the attendees table).
		if ( isset( $data['attendees'] ) && is_array( $data['attendees'] ) ) {
			$max_additional = max( 0, (int) $ticket_data['total_quantity'] - 1 );
			$additional     = array_slice( array_values( $data['attendees'] ), 0, $max_additional );

			foreach ( $additional as $att ) {
				if ( ! is_array( $att ) ) {
					continue;
				}

				$ticket_type = sanitize_text_field( $att['ticket_type'] ?? '' );

				$attendees[] = array(
					'attendee_name'  => sanitize_text_field( $att['name'] ?? '' ),
					'attendee_email' => sanitize_email( $att['email'] ?? '' ),
					'attendee_phone' => BLT_Events_Helpers::sanitize_phone( $att['phone'] ?? '' ),
					'ticket_type'    => $ticket_type,
					'ticket_price'   => isset( $ticket_prices[ $ticket_type ] ) ? (float) $ticket_prices[ $ticket_type ] : 0,
					'custom_fields'  => isset( $att['custom_fields'] ) ? wp_json_encode( $att['custom_fields'] ) : null,
				);
			}
		}

		// Seat map: one entry per purchased seat, in selection order, so a seat
		// can be labelled with the ticket type it was actually sold under.
		$seats = array();
		foreach ( $ticket_data['selections'] as $selection ) {
			for ( $n = 0; $n < (int) $selection['quantity']; $n++ ) {
				$seats[] = $selection;
			}
		}

		// An off-site checkout buys N seats in one transaction without ever
		// collecting the other attendees' details, and an on-site group booking
		// may simply leave some blank. Fill the remainder with placeholder rows
		// so the attendee list always matches attendee_count and the gaps are
		// visible to whoever has to chase them.
		for ( $i = count( $attendees ); $i < (int) $ticket_data['total_quantity']; $i++ ) {
			$seat = isset( $seats[ $i ] ) ? $seats[ $i ] : null;

			$attendees[] = array(
				'attendee_name'  => '',
				'attendee_email' => '',
				'attendee_phone' => '',
				'ticket_type'    => $seat ? $seat['name'] : null,
				'ticket_price'   => $seat ? $seat['price'] : 0,
				'custom_fields'  => null,
			);
		}

		return $attendees;
	}

	/**
	 * AJAX handler for coupon validation.
	 */
	public static function ajax_validate_coupon() {
		check_ajax_referer( 'blt_registration_nonce', 'nonce' );

		$code     = strtoupper( sanitize_text_field( $_POST['coupon_code'] ?? '' ) );
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$quantity = absint( $_POST['quantity'] ?? 1 );

		$result = BLT_Events_Coupons::validate_coupon( $code, $event_id, $quantity );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$type   = get_post_meta( $result->ID, '_blt_discount_type', true );
		$amount = get_post_meta( $result->ID, '_blt_amount', true );

		wp_send_json_success( array(
			'code'   => $code,
			'type'   => $type,
			'amount' => $amount,
			'label'  => $type === 'percentage' ? sprintf( __( '%s%% off', 'blt-events' ), $amount ) : sprintf( __( '$%s off', 'blt-events' ), number_format( (float) $amount, 2 ) ),
		) );
	}

	/**
	 * Send confirmation email after registration.
	 */
	public static function send_confirmation_email( $registration_id, $result ) {
		$reg = self::$reg_db->get( $registration_id );
		if ( ! $reg || $reg->status !== 'confirmed' ) {
			return;
		}

		$event = get_post( $reg->event_id );
		if ( ! $event ) {
			return;
		}

		$subject_template = get_option( 'blt_events_email_subject_registration', __( 'Registration confirmation for {event_name}', 'blt-events' ) );
		$body_template    = get_option( 'blt_events_email_template_registration', __( 'Hello {customer_name}, your registration for {event_name} on {event_date} at {event_time} has been confirmed.', 'blt-events' ) );

		$event_date = get_post_meta( $event->ID, '_blt_event_date', true );
		$event_time = get_post_meta( $event->ID, '_blt_event_start_time', true );

		$replacements = array(
			'{customer_name}'  => $reg->customer_name,
			'{event_name}'     => $event->post_title,
			'{event_date}'     => $event_date,
			'{event_time}'     => $event_time,
			'{event_location}' => BLT_Events_Helpers::get_event_location_string( $event->ID ),
			'{event_url}'      => get_permalink( $event->ID ),
		);

		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_template );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_template );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Attach a calendar invite (.ics) when enabled in Settings > Emails.
		$attachments = array();
		$ics_path    = '';
		if ( get_option( 'blt_events_calendar_invite_enabled', '1' ) === '1' ) {
			$ics_content = BLT_Events_Helpers::generate_ics_content( $event );
			$ics_path    = get_temp_dir() . 'blt-event-' . $event->ID . '-' . wp_generate_password( 8, false ) . '.ics';

			if ( file_put_contents( $ics_path, $ics_content ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$attachments[] = $ics_path;
			} else {
				$ics_path = '';
			}
		}

		wp_mail( $reg->customer_email, $subject, wpautop( $body ), $headers, $attachments );

		if ( $ics_path !== '' ) {
			wp_delete_file( $ics_path );
		}
	}

	/**
	 * Get a registration by ID.
	 */
	public static function get_registration( $id ) {
		return self::$reg_db->get( absint( $id ) );
	}

	/**
	 * Get attendees for a registration.
	 */
	public static function get_attendees( $registration_id ) {
		return self::$att_db->get_by_registration( absint( $registration_id ) );
	}

	/**
	 * Update registration status.
	 */
	public static function update_status( $registration_id, $status ) {
		$allowed = array( 'pending', 'confirmed', 'cancelled', 'refunded' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}
		return self::$reg_db->update( $registration_id, array( 'status' => $status ) );
	}

	/**
	 * Confirm a pending registration (e.g., after payment).
	 */
	public static function confirm_registration( $registration_id, $payment_data = array() ) {
		$data = array( 'status' => 'confirmed' );

		if ( ! empty( $payment_data['payment_id'] ) ) {
			$data['payment_id'] = sanitize_text_field( $payment_data['payment_id'] );
		}
		if ( ! empty( $payment_data['payment_date'] ) ) {
			$data['payment_date'] = sanitize_text_field( $payment_data['payment_date'] );
		}
		if ( ! empty( $payment_data['amount_paid'] ) ) {
			$data['amount_paid'] = floatval( $payment_data['amount_paid'] );
		}
		if ( ! empty( $payment_data['provider'] ) ) {
			$data['payment_provider'] = sanitize_text_field( $payment_data['provider'] );
		}

		$result = self::$reg_db->update( $registration_id, $data );

		if ( $result !== false ) {
			do_action( 'blt_registration_confirmed', $registration_id );
		}

		return $result;
	}
}
