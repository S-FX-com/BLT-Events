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
	 * @param int   $event_id The event post ID.
	 * @param array $data     Submitted form data.
	 * @param array $payment  Optional payment data (provider, id, amount, date).
	 * @return array|WP_Error Registration result array or WP_Error.
	 */
	public static function process_registration( $event_id, $data, $payment = array() ) {
		// Get event fieldset and validate
		$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $event_id );
		if ( ! $fieldset ) {
			return new WP_Error( 'no_fieldset', __( 'No fieldset configured for this event.', 'blt-events' ) );
		}

		// Validate primary registrant data
		$validated = BLT_Events_Fieldsets::validate_submission( $fieldset, $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Check capacity (under an advisory lock so concurrent submissions
		// cannot oversell the last spots).
		global $wpdb;
		$capacity = (int) get_post_meta( $event_id, '_blt_capacity', true );
		$ticket_data = self::parse_ticket_selections( $event_id, $data );
		$total_attendees = $ticket_data['total_quantity'];

		$lock_name = 'blt_events_reg_' . $event_id;
		$locked    = false;

		if ( $capacity > 0 ) {
			$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

			$current_count = self::$reg_db->get_event_registration_count( $event_id );
			if ( ( $current_count + $total_attendees ) > $capacity ) {
				if ( $locked ) {
					$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
				}
				return new WP_Error( 'capacity_exceeded', __( 'Sorry, there are not enough spots available.', 'blt-events' ) );
			}
		}

		// Duplicate check
		$email = $validated['email'] ?? '';
		if ( $email && self::$reg_db->email_registered_for_event( $email, $event_id ) ) {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
			return new WP_Error( 'duplicate_registration', __( 'This email is already registered for this event.', 'blt-events' ) );
		}

		// Calculate pricing
		$pricing = self::calculate_total( $event_id, $ticket_data, $data );

		// Build customer name
		$customer_name = trim( ( $validated['first_name'] ?? '' ) . ' ' . ( $validated['last_name'] ?? '' ) );

		// Group ID for multi-attendee
		$group_id = $total_attendees > 1 ? BLT_Events_Helpers::generate_group_id() : null;

		// Determine status
		$is_free = $pricing['total'] <= 0;
		$status  = $is_free ? 'confirmed' : 'pending';

		if ( ! empty( $payment['payment_id'] ) ) {
			$status = 'confirmed';
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
			'discount_amount' => $pricing['discount'],
			'amount_paid'     => $pricing['total'],
			'currency'        => get_option( 'blt_events_currency', 'USD' ),
			'coupon_id'       => $pricing['coupon_id'] ?? null,
			'coupon_data'     => ! empty( $pricing['coupon_data'] ) ? wp_json_encode( $pricing['coupon_data'] ) : null,
			'payment_provider' => $payment['provider'] ?? ( $is_free ? 'free' : BLT_Events_Helpers::get_payment_provider() ),
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
			'status'          => $status,
		);

		do_action( 'blt_registration_created', $registration_id, $result );

		return $result;
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
		$ticket_types_raw = get_post_meta( $event_id, '_blt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		$ticket_types     = is_array( $ticket_types ) ? $ticket_types : array();

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
			'{customer_name}' => $reg->customer_name,
			'{event_name}'    => $event->post_title,
			'{event_date}'    => $event_date,
			'{event_time}'    => $event_time,
			'{event_url}'     => get_permalink( $event->ID ),
		);

		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_template );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_template );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $reg->customer_email, $subject, wpautop( $body ), $headers );
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
