<?php
/**
 * BLT Events - Registrations Business Logic
 *
 * Processes new registrations, handles multi-attendee logic, coupon
 * application, status transitions, and the public AJAX endpoints. Emails
 * are sent by BLT_Events_Emails, which listens to the actions fired here.
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

	}

	/* ------------------------------------------------------------------
	 * Public AJAX endpoints
	 * ---------------------------------------------------------------- */

	/**
	 * AJAX handler for direct registration (free events / no payment gateway).
	 *
	 * This endpoint never takes money, so it refuses any selection that
	 * costs something: paid orders go through the payment provider's own
	 * flow, which hands process_registration() a captured payment.
	 */
	public static function ajax_register() {
		check_ajax_referer( 'blt_registration_nonce', 'nonce' );

		$data  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
		$email = sanitize_email( $data['email'] ?? '' );

		if ( ! self::check_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many registration attempts. Please try again in a few minutes.', 'blt-events' ) ) );
		}

		$event_id = absint( $data['event_id'] ?? 0 );
		if ( ! $event_id || get_post_type( $event_id ) !== 'event' || get_post_status( $event_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'blt-events' ) ) );
		}

		if ( get_post_meta( $event_id, '_blt_registration_open', true ) !== '1' ) {
			wp_send_json_error( array( 'message' => __( 'Registration is closed for this event.', 'blt-events' ) ) );
		}

		// Payment gate. The amount is recomputed from event meta, never from
		// the request, so a paid ticket cannot be smuggled through here.
		$pricing = self::calculate_order_total( $event_id, $data );
		if ( $pricing['total'] > 0 ) {
			wp_send_json_error( array( 'message' => __( 'This selection requires payment. Please complete the checkout to register.', 'blt-events' ) ) );
		}

		$result = self::process_registration( $event_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		wp_send_json_success( array(
			'message'         => self::success_message( $result['status'] ),
			'status'          => $result['status'],
			'registration_id' => $result['registration_id'],
			'group_id'        => $result['group_id'],
		) );
	}

	/**
	 * AJAX handler for coupon validation.
	 */
	public static function ajax_validate_coupon() {
		check_ajax_referer( 'blt_registration_nonce', 'nonce' );

		// Coupon codes are guessable; keep brute-force attempts slow.
		if ( ! self::check_rate_limit( '', 'coupon', 30 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many attempts. Please try again in a few minutes.', 'blt-events' ) ) );
		}

		$code     = strtoupper( sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) ) );
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
			'label'  => $type === 'percentage'
				/* translators: %s: percentage. */
				? sprintf( __( '%s%% off', 'blt-events' ), $amount )
				/* translators: %s: formatted amount. */
				: sprintf( __( '%s off', 'blt-events' ), BLT_Events_Helpers::format_price( (float) $amount ) ),
		) );
	}

	/**
	 * The visitor-facing message for a registration outcome.
	 *
	 * @param string $status Registration status.
	 * @return string
	 */
	public static function success_message( $status ) {
		switch ( $status ) {
			case 'pending':
				$message = __( 'Your registration has been received and is awaiting approval. We will email you once it is confirmed.', 'blt-events' );
				break;
			default:
				$message = __( 'Registration successful! A confirmation email is on its way.', 'blt-events' );
		}

		/**
		 * Filter the message shown after a successful submission.
		 *
		 * @param string $message The message.
		 * @param string $status  The registration status.
		 */
		return apply_filters( 'blt_events_registration_success_message', $message, $status );
	}

	/**
	 * Per-IP and per-email rate limit for the public endpoints.
	 *
	 * The nonce alone does not throttle, since it is rendered to every
	 * visitor. IPs are read proxy-aware (see BLT_Events_Helpers::client_ip)
	 * so a Cloudflare-fronted site does not put every visitor in one bucket.
	 *
	 * @param string $email  Optional email to throttle as well.
	 * @param string $bucket Named bucket, so coupons and registrations count separately.
	 * @param int    $max    Default maximum per 10 minutes.
	 * @return bool True when the request is within the limit.
	 */
	private static function check_rate_limit( $email = '', $bucket = 'register', $max = 10 ) {
		/**
		 * Filter the maximum requests allowed per IP per 10 minutes.
		 * Return 0 to disable rate limiting.
		 *
		 * @param int    $max    Maximum.
		 * @param string $bucket 'register' or 'coupon'.
		 */
		$max = (int) apply_filters( 'blt_events_registration_rate_limit', $max, $bucket );
		if ( $max <= 0 ) {
			return true;
		}

		$ip = BLT_Events_Helpers::client_ip();
		if ( $ip && ! self::bump_counter( 'blt_rl_' . $bucket . '_' . md5( $ip ), $max ) ) {
			return false;
		}

		if ( $email ) {
			/**
			 * Filter the maximum submissions per email address per 10 minutes.
			 *
			 * @param int $max Maximum.
			 */
			$email_max = (int) apply_filters( 'blt_events_registration_email_rate_limit', 5 );
			if ( $email_max > 0 && ! self::bump_counter( 'blt_rl_' . $bucket . '_e_' . md5( strtolower( $email ) ), $email_max ) ) {
				return false;
			}
		}

		return true;
	}

	private static function bump_counter( $key, $max ) {
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Core registration flow
	 * ---------------------------------------------------------------- */

	/**
	 * Process a new registration.
	 *
	 * Two callers with very different trust models share this method:
	 *
	 *  - The on-site form (AJAX / Stripe), where nothing has been charged yet.
	 *    Every guard is fatal: rejecting the submission costs the visitor
	 *    nothing but a re-try.
	 *  - An off-site checkout webhook (SureCart, FluentCart, Stripe webhook),
	 *    where the buyer has already been charged. Here a fatal guard would
	 *    mean money taken with no registration and nothing surfaced to anyone,
	 *    so the guards instead record the registration as `pending`, attach
	 *    the reason, and fire `blt_registration_needs_review` for an admin.
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

		/**
		 * Filter the submitted data before it is validated.
		 *
		 * @param array $data     Submitted data.
		 * @param int   $event_id The event post ID.
		 * @param array $payment  Payment data (empty for on-site submissions).
		 */
		$data = apply_filters( 'blt_events_registration_data', $data, $event_id, $payment );

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

		// An event that sells tickets needs at least one picked. Without this
		// the fallback "1 attendee, subtotal 0" would register anyone for free
		// on a paid event by simply omitting the quantities.
		if ( ! empty( $ticket_data['none_selected'] ) ) {
			if ( ! $captured ) {
				return new WP_Error( 'no_tickets_selected', __( 'Please select at least one ticket to continue.', 'blt-events' ) );
			}
			$review[] = __( 'The order carried no ticket the plugin recognises; one seat was recorded.', 'blt-events' );
		}

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
					self::release_lock( $locked, $lock_name );

					return new WP_Error( 'capacity_exceeded', __( 'Sorry, there are not enough spots available.', 'blt-events' ) );
				}
				$review[] = __( 'The event was already at capacity when this payment completed; it may need a refund or a raised capacity.', 'blt-events' );
			}
		}

		// Duplicate check
		$email = $validated['email'] ?? '';
		if ( $email && self::$reg_db->email_registered_for_event( $email, $event_id ) ) {
			if ( ! $captured ) {
				self::release_lock( $locked, $lock_name );
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

		/**
		 * Filter the status a new registration is stored with.
		 *
		 * @param string $status   'confirmed' or 'pending'.
		 * @param int    $event_id The event post ID.
		 * @param array  $payment  Payment data.
		 */
		$status = apply_filters( 'blt_events_new_registration_status', $status, $event_id, $payment );

		// Insert registration
		$reg_data = array(
			'event_id'         => $event_id,
			'group_id'         => $group_id,
			'customer_name'    => $customer_name,
			'customer_email'   => $email,
			'customer_phone'   => $validated['mobile_number'] ?? ( $validated['phone'] ?? '' ),
			'attendee_count'   => $total_attendees,
			'custom_fields'    => wp_json_encode( $validated ),
			'total_amount'     => $pricing['subtotal'],
			'discount_amount'  => $discount,
			'amount_paid'      => $amount_paid,
			'currency'         => BLT_Events_Helpers::get_currency_code(),
			'coupon_id'        => $pricing['coupon_id'] ?? null,
			'coupon_data'      => ! empty( $pricing['coupon_data'] ) ? wp_json_encode( $pricing['coupon_data'] ) : null,
			'payment_provider' => $payment['provider'] ?? ( $is_free ? 'free' : BLT_Events_Helpers::get_event_payment_provider( $event_id ) ),
			'payment_id'       => $payment['payment_id'] ?? null,
			'payment_date'     => $payment['payment_date'] ?? ( $is_free ? current_time( 'mysql' ) : null ),
			'status'           => $status,
		);

		/**
		 * Filter the row about to be inserted into the registrations table.
		 *
		 * @param array $reg_data  Column => value.
		 * @param array $validated Validated form data.
		 * @param int   $event_id  The event post ID.
		 */
		$reg_data = apply_filters( 'blt_events_registration_insert_data', $reg_data, $validated, $event_id );

		$registration_id = self::$reg_db->insert( $reg_data );

		self::release_lock( $locked, $lock_name );

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

		/**
		 * Fires after a registration (and its attendees) has been stored.
		 *
		 * @param int   $registration_id The new registration ID.
		 * @param array $result          Result array: registration_id, group_id, total, amount_paid, status, review.
		 */
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

	private static function release_lock( $locked, $lock_name ) {
		if ( $locked ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
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

		$none_selected = ( 0 === $total_quantity && ! empty( $ticket_types ) );

		// Fallback: single attendee if the order carried nothing we recognise.
		if ( 0 === $total_quantity ) {
			$total_quantity = 1;
		}

		return array(
			'selections'     => $selections,
			'total_quantity' => $total_quantity,
			'subtotal'       => $total_price,
			'none_selected'  => $none_selected,
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
	 * The ticket selections (index/quantity pairs) a form submission carries,
	 * for providers that need to remember them independently of a session.
	 *
	 * @param int   $event_id The event post ID.
	 * @param array $data     Submitted form data (unslashed).
	 * @return array List of array( 'index' => int, 'quantity' => int ).
	 */
	public static function line_items_from_form( $event_id, $data ) {
		$ticket_data = self::parse_ticket_selections( $event_id, $data );
		$items       = array();

		foreach ( $ticket_data['selections'] as $selection ) {
			$items[] = array(
				'index'    => (int) $selection['index'],
				'quantity' => (int) $selection['quantity'],
			);
		}

		return $items;
	}

	/**
	 * Parse ticket type selections from form data.
	 */
	private static function parse_ticket_selections( $event_id, $data ) {
		// Only tickets inside their sale window and open to the current
		// visitor's role count; quantities submitted for hidden tickets
		// are ignored.
		$ticket_types = BLT_Events_Helpers::available_ticket_types( $event_id );
		$has_tickets  = ! empty( BLT_Events_Helpers::get_ticket_types( $event_id ) );

		$selections     = array();
		$total_quantity = 0;
		$total_price    = 0;

		foreach ( $ticket_types as $i => $ticket ) {
			$qty_key  = 'ticket_quantity_' . $i;
			$quantity = isset( $data[ $qty_key ] ) ? absint( $data[ $qty_key ] ) : 0;

			/**
			 * Filter the maximum quantity of one ticket type per registration.
			 *
			 * @param int   $max      Default 50.
			 * @param array $ticket   Ticket definition.
			 * @param int   $event_id The event post ID.
			 */
			$max_qty  = max( 1, (int) apply_filters( 'blt_events_max_ticket_quantity', 50, $ticket, $event_id ) );
			$quantity = min( $quantity, $max_qty );

			if ( $quantity > 0 ) {
				$price = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
				$selections[] = array(
					'index'    => $i,
					'name'     => $ticket['name'] ?? __( 'Ticket', 'blt-events' ),
					'price'    => $price,
					'quantity' => $quantity,
				);
				$total_quantity += $quantity;
				$total_price    += $price * $quantity;
			}
		}

		// Only an event with no ticket types at all is a plain "one person
		// signs up" form. When tickets exist and none was picked, the caller
		// decides (on-site: reject; webhook: flag for review).
		$none_selected = ( 0 === $total_quantity && $has_tickets );

		if ( 0 === $total_quantity ) {
			$total_quantity = 1;
		}

		return array(
			'selections'     => $selections,
			'total_quantity' => $total_quantity,
			'subtotal'       => $total_price,
			'none_selected'  => $none_selected,
		);
	}

	/**
	 * Calculate total price including group discounts and coupons.
	 */
	private static function calculate_total( $event_id, $ticket_data, $form_data ) {
		$subtotal    = $ticket_data['subtotal'];
		$discount    = 0;
		$coupon_id   = null;
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
		$coupon_code = isset( $form_data['coupon_code'] ) ? strtoupper( trim( (string) $form_data['coupon_code'] ) ) : '';
		if ( $coupon_code ) {
			$coupon_result = BLT_Events_Coupons::validate_coupon( $coupon_code, $event_id, $ticket_data['total_quantity'] );
			if ( ! is_wp_error( $coupon_result ) ) {
				$coupon_discount = BLT_Events_Coupons::calculate_discount( $coupon_result, $subtotal - $discount );
				$discount   += $coupon_discount;
				$coupon_id   = $coupon_result->ID;
				$coupon_data = array(
					'code'   => $coupon_code,
					'type'   => get_post_meta( $coupon_result->ID, '_blt_discount_type', true ),
					'amount' => get_post_meta( $coupon_result->ID, '_blt_amount', true ),
					'saved'  => $coupon_discount,
				);
			}
		}

		$total = max( 0, $subtotal - $discount );

		$pricing = array(
			'subtotal'    => round( $subtotal, 2 ),
			'discount'    => round( $discount, 2 ),
			'total'       => round( $total, 2 ),
			'coupon_id'   => $coupon_id,
			'coupon_data' => $coupon_data,
		);

		/**
		 * Filter the computed pricing of an order.
		 *
		 * @param array $pricing     subtotal, discount, total, coupon_id, coupon_data.
		 * @param int   $event_id    The event post ID.
		 * @param array $ticket_data Parsed ticket selections.
		 * @param array $form_data   Submitted data.
		 */
		return apply_filters( 'blt_events_order_pricing', $pricing, $event_id, $ticket_data, $form_data );
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
			'attendee_phone' => $primary_validated['mobile_number'] ?? ( $primary_validated['phone'] ?? '' ),
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

		// Seat map: one entry per purchased seat, in selection order, so a seat
		// can be labelled with the ticket type it was actually sold under.
		$seats = array();
		foreach ( $ticket_data['selections'] as $selection ) {
			for ( $n = 0; $n < (int) $selection['quantity']; $n++ ) {
				$seats[] = $selection;
			}
		}

		// Additional attendees from form data (bounded by the validated
		// total quantity so one request cannot flood the attendees table).
		if ( isset( $data['attendees'] ) && is_array( $data['attendees'] ) ) {
			$max_additional = max( 0, (int) $ticket_data['total_quantity'] - 1 );
			$additional     = array_slice( array_values( $data['attendees'] ), 0, $max_additional );

			foreach ( $additional as $n => $att ) {
				if ( ! is_array( $att ) ) {
					continue;
				}

				$ticket_type = sanitize_text_field( $att['ticket_type'] ?? '' );

				// A ticket type the order does not contain falls back to the
				// seat this attendee occupies.
				if ( '' === $ticket_type || ! isset( $ticket_prices[ $ticket_type ] ) ) {
					$seat        = $seats[ $n + 1 ] ?? null;
					$ticket_type = $seat ? $seat['name'] : '';
				}

				$custom = array();
				if ( isset( $att['custom_fields'] ) && is_array( $att['custom_fields'] ) ) {
					foreach ( $att['custom_fields'] as $k => $v ) {
						$custom[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
					}
				}

				// The checkout asks for first and last name; older forms and
				// custom attendee fields may still post a single name.
				$attendee_name = sanitize_text_field( $att['name'] ?? '' );
				if ( '' === $attendee_name ) {
					$attendee_name = trim( sanitize_text_field( $att['first_name'] ?? '' ) . ' ' . sanitize_text_field( $att['last_name'] ?? '' ) );
				}

				$attendees[] = array(
					'attendee_name'  => $attendee_name,
					'attendee_email' => sanitize_email( $att['email'] ?? '' ),
					'attendee_phone' => BLT_Events_Helpers::sanitize_phone( $att['phone'] ?? '' ),
					'ticket_type'    => $ticket_type,
					'ticket_price'   => isset( $ticket_prices[ $ticket_type ] ) ? (float) $ticket_prices[ $ticket_type ] : 0,
					'custom_fields'  => $custom ? wp_json_encode( $custom ) : null,
				);
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

		/**
		 * Filter the attendee rows about to be inserted for a registration.
		 *
		 * @param array $attendees        Rows.
		 * @param array $data             Submitted data.
		 * @param array $ticket_data      Parsed ticket selections.
		 * @param array $primary_validated Validated primary registrant data.
		 */
		return apply_filters( 'blt_events_attendees_data', $attendees, $data, $ticket_data, $primary_validated );
	}

	/* ------------------------------------------------------------------
	 * Emails (kept for backwards compatibility; see BLT_Events_Emails)
	 * ---------------------------------------------------------------- */

	/**
	 * Send the confirmation email for a registration.
	 *
	 * @deprecated 2.4.0 Use BLT_Events_Emails::send( 'registration', $registration ).
	 */
	public static function send_confirmation_email( $registration_id, $result = array() ) {
		$reg = self::$reg_db->get( absint( $registration_id ) );
		if ( ! $reg || $reg->status !== 'confirmed' ) {
			return;
		}

		BLT_Events_Emails::send( 'registration', $reg );
	}

	/* ------------------------------------------------------------------
	 * Reads and status transitions
	 * ---------------------------------------------------------------- */

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
	 *
	 * Every status change in the plugin goes through here so the lifecycle
	 * actions fire exactly once per transition, whoever triggered it: the
	 * bulk action on the Registrations screen, the REST API, a refund
	 * webhook, or code. The actions are what send the confirmation email,
	 * tag the CRM contact and cross-register attendees into meeting rooms.
	 *
	 * @param int    $registration_id The registration ID.
	 * @param string $status          New status slug.
	 * @return int|false Rows updated (0 when nothing changed), or false on error.
	 */
	public static function update_status( $registration_id, $status ) {
		$registration_id = absint( $registration_id );
		$status          = sanitize_key( $status );

		if ( ! array_key_exists( $status, BLT_Events_Helpers::registration_statuses() ) ) {
			return false;
		}

		$reg = self::$reg_db->get( $registration_id );
		if ( ! $reg ) {
			return false;
		}

		$old_status = (string) $reg->status;
		if ( $old_status === $status ) {
			return 0;
		}

		$result = self::$reg_db->update( $registration_id, array( 'status' => $status ) );
		if ( false === $result ) {
			return false;
		}

		self::fire_transition_hooks( $registration_id, $status, $old_status, $reg->event_id );

		return $result;
	}

	/**
	 * Confirm a pending registration (e.g., after payment).
	 */
	public static function confirm_registration( $registration_id, $payment_data = array() ) {
		$registration_id = absint( $registration_id );
		$reg             = self::$reg_db->get( $registration_id );
		if ( ! $reg ) {
			return false;
		}

		$data = array();

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

		if ( ! empty( $data ) && false === self::$reg_db->update( $registration_id, $data ) ) {
			return false;
		}

		if ( 'confirmed' === $reg->status ) {
			return 0;
		}

		$result = self::$reg_db->update( $registration_id, array( 'status' => 'confirmed' ) );
		if ( false === $result ) {
			return false;
		}

		self::fire_transition_hooks( $registration_id, 'confirmed', (string) $reg->status, $reg->event_id );

		return $result;
	}

	/**
	 * Fire the lifecycle actions for a status change.
	 */
	private static function fire_transition_hooks( $registration_id, $status, $old_status, $event_id ) {
		/**
		 * Fires on every registration status change.
		 *
		 * @param int    $registration_id The registration ID.
		 * @param string $status          New status.
		 * @param string $old_status      Previous status.
		 */
		do_action( 'blt_registration_status_changed', $registration_id, $status, $old_status );

		switch ( $status ) {
			case 'confirmed':
				/**
				 * Fires when a registration becomes confirmed.
				 *
				 * @param int $registration_id The registration ID.
				 */
				do_action( 'blt_registration_confirmed', $registration_id );
				break;
			case 'cancelled':
				/**
				 * Fires when a registration is cancelled.
				 *
				 * @param int $registration_id The registration ID.
				 */
				do_action( 'blt_registration_cancelled', $registration_id );
				break;
			case 'refunded':
				/**
				 * Fires when a registration is refunded.
				 *
				 * @param int $registration_id The registration ID.
				 */
				do_action( 'blt_registration_refunded', $registration_id );
				break;
		}

	}
}
