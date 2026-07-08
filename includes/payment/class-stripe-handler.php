<?php
/**
 * BLT Events - Stripe Payment Handler
 *
 * Handles Stripe Payment Intents creation, webhook processing,
 * and payment confirmation for event registrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Stripe_Handler extends BLT_Events_Payment_Provider {

	private static $secret_key;

	public static function init() {
		if ( ! self::is_active_provider( 'stripe' ) ) {
			return;
		}

		self::$secret_key = get_option( 'blt_events_stripe_secret_key', '' );

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
		return ! empty( get_option( 'blt_events_stripe_secret_key', '' ) )
			&& ! empty( get_option( 'blt_events_stripe_publishable_key', '' ) );
	}

	public static function enqueue_scripts() {
		if ( ! self::is_active_provider( 'stripe' ) || ! self::is_configured() ) {
			return;
		}

		wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
		wp_register_script(
			'blt-events-payment',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/payment.js',
			array( 'jquery', 'stripe-js' ),
			BLT_EVENTS_VERSION,
			true
		);

		wp_localize_script( 'blt-events-payment', 'bltStripeData', array(
			'publishableKey' => get_option( 'blt_events_stripe_publishable_key', '' ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'blt_stripe_nonce' ),
		) );
	}

	/**
	 * AJAX: Create a Stripe Payment Intent.
	 */
	public static function ajax_create_payment_intent() {
		check_ajax_referer( 'blt_stripe_nonce', 'nonce' );

		$event_id = absint( $_POST['event_id'] ?? 0 );
		$currency = strtolower( get_option( 'blt_events_currency', 'USD' ) );

		if ( ! $event_id || get_post_type( $event_id ) !== 'event' || get_post_status( $event_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => 'Invalid payment parameters.' ) );
		}

		// The amount is always recomputed server-side from the event's
		// ticket prices, quantities, and coupon — never from the client.
		$pricing      = BLT_Events_Registrations::calculate_order_total( $event_id, wp_unslash( $_POST ) );
		$amount_cents = intval( round( $pricing['total'] * 100 ) );

		if ( $amount_cents <= 0 ) {
			wp_send_json_error( array( 'message' => 'There is nothing to pay for this selection.' ) );
		}

		$response = self::api_request( 'payment_intents', array(
			'amount'   => $amount_cents,
			'currency' => $currency,
			'metadata' => array(
				'event_id' => $event_id,
				'source'   => 'blt_events',
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

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
		$intent_id = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );

		if ( ! $event_id || ! $intent_id ) {
			wp_send_json_error( array( 'message' => 'Missing payment data.' ) );
		}

		// Verify payment with Stripe
		$intent = self::api_request( 'payment_intents/' . $intent_id, array(), 'GET' );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => 'Could not verify payment.' ) );
		}

		if ( $intent['status'] !== 'succeeded' ) {
			wp_send_json_error( array( 'message' => 'Payment has not been completed.' ) );
		}

		// The intent must be one of ours, for this event.
		$meta = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		if ( ( $meta['source'] ?? '' ) !== 'blt_events' || absint( $meta['event_id'] ?? 0 ) !== $event_id ) {
			wp_send_json_error( array( 'message' => 'Payment does not match this event.' ) );
		}

		// Recompute the expected total server-side and require the charge
		// to cover it, so a cheaper intent cannot confirm a pricier order.
		$form_data      = wp_unslash( $_POST );
		$pricing        = BLT_Events_Registrations::calculate_order_total( $event_id, $form_data );
		$expected_cents = intval( round( $pricing['total'] * 100 ) );

		if ( (int) $intent['amount'] < $expected_cents ) {
			wp_send_json_error( array( 'message' => 'Payment amount does not match the order total. Please contact support.' ) );
		}

		$payment = array(
			'provider'     => 'stripe',
			'payment_id'   => $intent_id,
			'payment_date' => current_time( 'mysql' ),
			'amount_paid'  => $intent['amount'] / 100,
		);

		$result = BLT_Events_Registrations::process_registration( $event_id, $form_data, $payment );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'         => 'Payment confirmed! Registration complete.',
			'registration_id' => $result['registration_id'],
		) );
	}

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
			return new WP_REST_Response( array( 'error' => 'Webhook secret not configured.' ), 400 );
		}

		// Verify webhook signature
		if ( ! self::verify_webhook_signature( $payload, $sig, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );

		if ( ! $event || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		switch ( $event['type'] ) {
			case 'payment_intent.succeeded':
				// Payment confirmed via webhook — handled by frontend confirm flow
				break;

			case 'charge.refunded':
				$charge    = $event['data']['object'];
				$intent_id = $charge['payment_intent'] ?? '';
				if ( $intent_id ) {
					self::handle_refund( $intent_id );
				}
				break;
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Handle a Stripe refund.
	 */
	private static function handle_refund( $payment_intent_id ) {
		global $wpdb;

		$reg_db = new BLT_Events_Registrations_DB();
		$table  = $reg_db->get_table_name();

		$registration = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE payment_id = %s AND payment_provider = %s LIMIT 1",
				$payment_intent_id,
				'stripe'
			)
		);

		if ( $registration ) {
			BLT_Events_Registrations::update_status( $registration->id, 'refunded' );
			do_action( 'blt_registration_refunded', $registration->id );
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

		if ( $code >= 400 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Stripe API error.';
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

		$elements = explode( ',', $sig_header );
		$timestamp = null;
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
		if ( abs( time() - $timestamp ) > 300 ) {
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
