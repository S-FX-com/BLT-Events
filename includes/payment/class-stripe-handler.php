<?php
/**
 * BLT Events - Stripe Payment Handler
 *
 * Handles Stripe Payment Intents creation, webhook processing,
 * and payment confirmation for event registrations.
 *
 * Flow:
 *   1. The form posts to blt_create_payment_intent. The charge amount is
 *      recomputed server-side, an intent is created, and the submitted
 *      form is stashed against the intent ID (a short-lived transient).
 *   2. The browser confirms the card with Stripe.js.
 *   3. The browser posts to blt_confirm_stripe_payment, which verifies the
 *      intent with Stripe and creates the registration. This is idempotent:
 *      an intent that already produced a registration cannot produce a
 *      second one.
 *   4. If the browser never comes back (closed tab, network drop), the
 *      payment_intent.succeeded webhook recreates the registration from the
 *      stashed form, so a captured payment never ends up without one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Stripe_Handler extends BLT_Events_Payment_Provider {

	private static $secret_key;

	/**
	 * How long a stashed form waits for its payment to complete.
	 */
	const STASH_TTL = DAY_IN_SECONDS;

	public static function init() {
		// Enabled, not "selected": the AJAX and webhook endpoints below have to
		// keep answering for events that still check out through Stripe even
		// after the site default has moved to another provider.
		if ( ! self::is_enabled_provider( 'stripe' ) ) {
			return;
		}

		self::$secret_key = self::secret_key();

		// AJAX endpoints
		add_action( 'wp_ajax_blt_create_payment_intent', array( __CLASS__, 'ajax_create_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_blt_create_payment_intent', array( __CLASS__, 'ajax_create_payment_intent' ) );

		add_action( 'wp_ajax_blt_confirm_stripe_payment', array( __CLASS__, 'ajax_confirm_payment' ) );
		add_action( 'wp_ajax_nopriv_blt_confirm_stripe_payment', array( __CLASS__, 'ajax_confirm_payment' ) );

		// Webhook handler
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_endpoint' ) );

		// Enqueue Stripe JS
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	public static function is_configured() {
		return '' !== self::secret_key()
			&& '' !== self::publishable_key();
	}

	/**
	 * The Stripe secret key.
	 *
	 * Every read of the secret goes through here — init() (which caches it for
	 * api_request()) and is_configured() alike. If the two resolved it
	 * differently the settings screen could report "Not configured" on a site
	 * whose payments actually work, or the reverse.
	 *
	 * Precedence: this plugin's own option, then the shared BLT family store.
	 *
	 * @return string
	 */
	private static function secret_key() {
		$key = trim( (string) get_option( 'blt_events_stripe_secret_key', '' ) );

		if ( '' === $key && class_exists( 'BLT_Family' ) ) {
			$key = BLT_Family::get( 'blt-events', 'stripe', 'secret_key' );
		}

		return (string) $key;
	}

	/**
	 * The Stripe publishable key — the one that is safe to send to the browser.
	 *
	 * Precedence: this plugin's own option, then the shared BLT family store.
	 *
	 * @return string
	 */
	private static function publishable_key() {
		$key = trim( (string) get_option( 'blt_events_stripe_publishable_key', '' ) );

		if ( '' === $key && class_exists( 'BLT_Family' ) ) {
			$key = BLT_Family::get( 'blt-events', 'stripe', 'publishable_key' );
		}

		return (string) $key;
	}

	public static function enqueue_scripts() {
		// Register (not enqueue) whenever some event could check out through
		// Stripe; the registration shortcode enqueues by handle for the event
		// it is actually rendering.
		if ( ! self::is_configured() || ! self::has_events_using( 'stripe' ) ) {
			return;
		}

		wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_register_script(
			'blt-events-payment',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/payment.js',
			array( 'jquery', 'stripe-js' ),
			BLT_EVENTS_VERSION,
			true
		);

		wp_localize_script( 'blt-events-payment', 'bltStripeData', array(
			'publishableKey' => self::publishable_key(),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'blt_stripe_nonce' ),
			'i18n'           => array(
				'processing'      => __( 'Processing payment…', 'blt-events' ),
				'registerPay'     => __( 'Register & Pay', 'blt-events' ),
				'complete'        => __( 'Registration Complete', 'blt-events' ),
				'createFailed'    => __( 'Could not create payment. Please try again.', 'blt-events' ),
				'confirmFailed'   => __( 'Payment succeeded but registration failed. Please contact support and quote your payment reference.', 'blt-events' ),
				'cardIncomplete'  => __( 'Please enter your card details.', 'blt-events' ),
			),
		) );
	}

	/**
	 * AJAX: Create a Stripe Payment Intent.
	 */
	public static function ajax_create_payment_intent() {
		check_ajax_referer( 'blt_stripe_nonce', 'nonce' );

		$event_id = absint( $_POST['event_id'] ?? 0 );
		$currency = strtolower( BLT_Events_Helpers::get_currency_code() );

		if ( ! $event_id || get_post_type( $event_id ) !== 'event' || get_post_status( $event_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment parameters.', 'blt-events' ) ) );
		}

		if ( get_post_meta( $event_id, '_blt_registration_open', true ) !== '1' || BLT_Events_Helpers::registration_cutoff_passed( $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Registration is closed for this event.', 'blt-events' ) ) );
		}

		$form_data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by process_registration().

		// Validate the form before taking money, so a submission that would be
		// rejected afterwards never gets charged.
		$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $event_id );
		if ( $fieldset ) {
			$validated = BLT_Events_Fieldsets::validate_submission( $fieldset, $form_data, true );
			if ( is_wp_error( $validated ) ) {
				wp_send_json_error( array( 'message' => $validated->get_error_message() ) );
			}

			$email = $validated['email'] ?? '';
			$db    = new BLT_Events_Registrations_DB();
			if ( $email && $db->email_registered_for_event( $email, $event_id ) ) {
				wp_send_json_error( array( 'message' => __( 'This email is already registered for this event.', 'blt-events' ) ) );
			}
		}

		// The amount is always recomputed server-side from the event's
		// ticket prices, quantities, and coupon — never from the client.
		$pricing      = BLT_Events_Registrations::calculate_order_total( $event_id, $form_data );
		$amount_cents = intval( round( $pricing['total'] * 100 ) );

		if ( $amount_cents <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'There is nothing to pay for this selection.', 'blt-events' ) ) );
		}

		// Capacity check before charging: better to refuse now than refund later.
		$capacity = (int) get_post_meta( $event_id, '_blt_capacity', true );
		if ( $capacity > 0 ) {
			$line_items = BLT_Events_Registrations::line_items_from_form( $event_id, $form_data );
			$requested  = 0;
			foreach ( $line_items as $item ) {
				$requested += (int) $item['quantity'];
			}
			$left = BLT_Events_Helpers::spots_left( $event_id );
			if ( null !== $left && $requested > $left ) {
				wp_send_json_error( array( 'message' => __( 'Sorry, there are not enough spots available.', 'blt-events' ) ) );
			}
		}

		$response = self::api_request( 'payment_intents', array(
			'amount'   => $amount_cents,
			'currency' => $currency,
			'metadata' => array(
				'event_id' => $event_id,
				'source'   => 'blt_events',
				'site'     => home_url(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		// Remember the form against the intent so the webhook can finish the
		// registration if the browser never does.
		self::stash_form( $response['id'], $event_id, $form_data );

		wp_send_json_success( array(
			'clientSecret' => $response['client_secret'],
			'intentId'     => $response['id'],
			'amount'       => $amount_cents / 100,
		) );
	}

	/**
	 * AJAX: Confirm payment and create registration.
	 */
	public static function ajax_confirm_payment() {
		check_ajax_referer( 'blt_stripe_nonce', 'nonce' );

		$event_id  = absint( $_POST['event_id'] ?? 0 );
		$intent_id = sanitize_text_field( wp_unslash( $_POST['payment_intent_id'] ?? '' ) );

		if ( ! $event_id || ! $intent_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing payment data.', 'blt-events' ) ) );
		}

		// Idempotency: a payment intent pays for exactly one registration. A
		// retry after a network hiccup gets the existing one back; a replay
		// with different attendee details gets nothing new.
		$existing = self::registrations_for_intent( $intent_id, $event_id );
		if ( ! empty( $existing ) ) {
			wp_send_json_success( array(
				'message'         => BLT_Events_Registrations::success_message( $existing[0]->status ),
				'registration_id' => (int) $existing[0]->id,
				'status'          => $existing[0]->status,
			) );
		}

		// Verify payment with Stripe
		$intent = self::api_request( 'payment_intents/' . rawurlencode( $intent_id ), array(), 'GET' );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not verify payment.', 'blt-events' ) ) );
		}

		if ( ( $intent['status'] ?? '' ) !== 'succeeded' ) {
			wp_send_json_error( array( 'message' => __( 'Payment has not been completed.', 'blt-events' ) ) );
		}

		// The intent must be one of ours, for this event.
		if ( ! self::intent_matches_event( $intent, $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment does not match this event.', 'blt-events' ) ) );
		}

		// Recompute the expected total server-side and require the charge
		// to cover it, so a cheaper intent cannot confirm a pricier order.
		$form_data      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by process_registration().
		$pricing        = BLT_Events_Registrations::calculate_order_total( $event_id, $form_data );
		$expected_cents = intval( round( $pricing['total'] * 100 ) );

		if ( (int) $intent['amount'] < $expected_cents ) {
			wp_send_json_error( array( 'message' => __( 'Payment amount does not match the order total. Please contact support.', 'blt-events' ) ) );
		}

		$result = self::register_from_intent( $intent, $event_id, $form_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'         => BLT_Events_Registrations::success_message( $result['status'] ),
			'registration_id' => $result['registration_id'],
			'status'          => $result['status'],
		) );
	}

	/**
	 * Create the registration for a succeeded intent (shared by the AJAX
	 * confirm and the webhook).
	 *
	 * @param array $intent     Stripe PaymentIntent object.
	 * @param int   $event_id   The event post ID.
	 * @param array $form_data  Submitted form data.
	 * @param array $line_items Optional index/quantity pairs (webhook path,
	 *                          where no visitor session exists to evaluate
	 *                          role-restricted tickets against).
	 * @return array|WP_Error
	 */
	private static function register_from_intent( array $intent, $event_id, array $form_data, array $line_items = array() ) {
		$payment = array(
			'provider'     => 'stripe',
			'payment_id'   => $intent['id'],
			'payment_date' => current_time( 'mysql' ),
			'amount_paid'  => $intent['amount'] / 100,
		);

		if ( ! empty( $line_items ) ) {
			$payment['line_items'] = $line_items;
		}

		$result = BLT_Events_Registrations::process_registration( $event_id, $form_data, $payment );

		if ( ! is_wp_error( $result ) ) {
			self::forget_form( $intent['id'] );
		}

		return $result;
	}

	private static function intent_matches_event( array $intent, $event_id ) {
		$meta = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();

		return ( $meta['source'] ?? '' ) === 'blt_events' && absint( $meta['event_id'] ?? 0 ) === (int) $event_id;
	}

	private static function registrations_for_intent( $intent_id, $event_id = 0 ) {
		$reg_db = new BLT_Events_Registrations_DB();
		return $reg_db->get_by_payment( 'stripe', $intent_id, $event_id );
	}

	/* ------------------------------------------------------------------
	 * Form stash (intent id -> submitted form)
	 * ---------------------------------------------------------------- */

	private static function stash_key( $intent_id ) {
		return 'blt_stripe_form_' . md5( (string) $intent_id );
	}

	private static function stash_form( $intent_id, $event_id, array $form_data ) {
		// Never keep transport-only keys.
		unset( $form_data['action'], $form_data['nonce'], $form_data['payment_intent_id'] );

		set_transient( self::stash_key( $intent_id ), array(
			'event_id'   => (int) $event_id,
			'data'       => $form_data,
			// Resolved now, with the visitor's roles, so the webhook can
			// rebuild the exact same order later without a session.
			'line_items' => BLT_Events_Registrations::line_items_from_form( $event_id, $form_data ),
			'created'    => time(),
		), self::STASH_TTL );
	}

	private static function read_form( $intent_id ) {
		$stash = get_transient( self::stash_key( $intent_id ) );
		return is_array( $stash ) ? $stash : null;
	}

	private static function forget_form( $intent_id ) {
		delete_transient( self::stash_key( $intent_id ) );
	}

	/* ------------------------------------------------------------------
	 * Webhook
	 * ---------------------------------------------------------------- */

	/**
	 * Register the Stripe webhook REST endpoint.
	 */
	public static function register_webhook_endpoint() {
		register_rest_route( 'blt-events/v1', '/stripe-webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Handle incoming Stripe webhooks.
	 */
	public static function handle_webhook( $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( 'stripe-signature' );
		$secret  = get_option( 'blt_events_stripe_webhook_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Webhook secret not configured.', 'blt-events' ) ), 400 );
		}

		// Verify webhook signature
		if ( ! self::verify_webhook_signature( $payload, $sig, $secret ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Invalid signature.', 'blt-events' ) ), 400 );
		}

		$event = json_decode( $payload, true );

		if ( ! $event || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Invalid payload.', 'blt-events' ) ), 400 );
		}

		switch ( $event['type'] ) {
			case 'payment_intent.succeeded':
				$intent = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
				if ( ! empty( $intent['id'] ) ) {
					self::handle_intent_succeeded( $intent );
				}
				break;

			case 'charge.refunded':
				$charge    = $event['data']['object'];
				$intent_id = $charge['payment_intent'] ?? '';
				if ( $intent_id ) {
					self::handle_refund( $intent_id );
				}
				break;
		}

		/**
		 * Fires for every verified Stripe webhook event.
		 *
		 * @param array $event The decoded Stripe event.
		 */
		do_action( 'blt_events_stripe_webhook', $event );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * A payment completed. If the browser already created the registration
	 * this is a no-op; otherwise rebuild it from the stashed form.
	 */
	private static function handle_intent_succeeded( array $intent ) {
		$meta     = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$event_id = absint( $meta['event_id'] ?? 0 );

		if ( ( $meta['source'] ?? '' ) !== 'blt_events' || ! $event_id ) {
			return;
		}

		if ( ! empty( self::registrations_for_intent( $intent['id'], $event_id ) ) ) {
			return;
		}

		$stash = self::read_form( $intent['id'] );

		if ( ! $stash || (int) $stash['event_id'] !== $event_id ) {
			// Paid, but nothing to rebuild from. Surface it rather than lose it.
			error_log( sprintf( 'BLT Events: Stripe intent %1$s was paid for event %2$d but no form data was found to create the registration.', $intent['id'], $event_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			/** This action is documented in includes/payment/class-surecart-integration.php */
			do_action( 'blt_events_payment_orphaned', 'stripe', $intent['id'], $event_id, new WP_Error( 'no_form_data', __( 'No stashed form data for this payment.', 'blt-events' ) ) );
			return;
		}

		$result = self::register_from_intent( $intent, $event_id, (array) $stash['data'], (array) $stash['line_items'] );

		if ( is_wp_error( $result ) ) {
			error_log( sprintf( 'BLT Events: Stripe intent %1$s was paid but no registration could be created for event %2$d - %3$s', $intent['id'], $event_id, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			/** This action is documented in includes/payment/class-surecart-integration.php */
			do_action( 'blt_events_payment_orphaned', 'stripe', $intent['id'], $event_id, $result );
		}
	}

	/**
	 * Handle a Stripe refund.
	 */
	private static function handle_refund( $payment_intent_id ) {
		$reg_db = new BLT_Events_Registrations_DB();

		// One intent can cover more than one registration; the old LIMIT 1
		// left every registration after the first still marked as paid.
		foreach ( $reg_db->get_by_payment( 'stripe', $payment_intent_id ) as $registration ) {
			if ( 'refunded' === $registration->status ) {
				continue;
			}

			// update_status() fires blt_registration_refunded itself.
			BLT_Events_Registrations::update_status( $registration->id, 'refunded' );
		}
	}

	/**
	 * Make a Stripe API request.
	 */
	private static function api_request( $endpoint, $data = array(), $method = 'POST' ) {
		$url = 'https://api.stripe.com/v1/' . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::$secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'timeout' => 30,
		);

		if ( $method === 'POST' && ! empty( $data ) ) {
			$args['body'] = http_build_query( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Stripe API error.', 'blt-events' );
			return new WP_Error( 'stripe_error', $message );
		}

		return $body;
	}

	/**
	 * Verify Stripe webhook signature.
	 */
	private static function verify_webhook_signature( $payload, $sig_header, $secret ) {
		if ( empty( $sig_header ) ) {
			return false;
		}

		$elements   = explode( ',', $sig_header );
		$timestamp  = null;
		$signatures = array();

		foreach ( $elements as $element ) {
			$parts = explode( '=', $element, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			if ( $parts[0] === 't' ) {
				$timestamp = $parts[1];
			} elseif ( $parts[0] === 'v1' ) {
				$signatures[] = $parts[1];
			}
		}

		if ( ! $timestamp || empty( $signatures ) ) {
			return false;
		}

		// Tolerance: 5 minutes
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}

		return false;
	}
}
