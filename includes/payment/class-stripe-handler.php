<?php
/**
 * ZymEvents - Stripe Payment Handler
 *
 * Handles Stripe Payment Intents creation, webhook processing,
 * payment confirmation, and Stripe Connect OAuth for event registrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZymEvents_Stripe_Handler extends ZymEvents_Payment_Provider {

	public static function init() {
		// Always register Connect OAuth endpoints (needed regardless of active provider).
		add_action( 'admin_action_zymevents_stripe_connect', array( __CLASS__, 'handle_connect_redirect' ) );
		add_action( 'admin_action_zymevents_stripe_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_connect_callback' ) );

		if ( ! self::is_active_provider( 'stripe' ) ) {
			return;
		}

		// AJAX endpoints
		add_action( 'wp_ajax_zymevents_create_payment_intent', array( __CLASS__, 'ajax_create_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_zymevents_create_payment_intent', array( __CLASS__, 'ajax_create_payment_intent' ) );

		add_action( 'wp_ajax_zymevents_confirm_stripe_payment', array( __CLASS__, 'ajax_confirm_payment' ) );
		add_action( 'wp_ajax_nopriv_zymevents_confirm_stripe_payment', array( __CLASS__, 'ajax_confirm_payment' ) );

		// Webhook handler
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_endpoint' ) );

		// Enqueue Stripe JS
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	public static function is_configured() {
		return ! empty( self::get_secret_key() )
			&& ! empty( self::get_publishable_key() );
	}

	/**
	 * Check if a Stripe account is connected via OAuth.
	 */
	public static function is_connected() {
		return ! empty( get_option( 'zymevents_stripe_connect_account_id', '' ) );
	}

	/**
	 * Get the active secret key (Connect token takes priority over manual key).
	 */
	public static function get_secret_key() {
		$connect_key = get_option( 'zymevents_stripe_connect_secret_key', '' );
		if ( ! empty( $connect_key ) ) {
			return $connect_key;
		}
		return get_option( 'zymevents_stripe_secret_key', '' );
	}

	/**
	 * Get the active publishable key (Connect token takes priority over manual key).
	 */
	public static function get_publishable_key() {
		$connect_key = get_option( 'zymevents_stripe_connect_publishable_key', '' );
		if ( ! empty( $connect_key ) ) {
			return $connect_key;
		}
		return get_option( 'zymevents_stripe_publishable_key', '' );
	}

	// ------------------------------------------------------------------
	// Stripe Connect OAuth
	// ------------------------------------------------------------------

	/**
	 * Build the Stripe Connect OAuth authorization URL.
	 */
	public static function get_connect_url() {
		$client_id = defined( 'ZYMEVENTS_STRIPE_CLIENT_ID' ) ? ZYMEVENTS_STRIPE_CLIENT_ID : '';

		if ( empty( $client_id ) ) {
			return '';
		}

		$redirect_uri = admin_url( 'admin.php?page=zymevents-settings&zymevents_stripe_return=1' );

		$params = array(
			'response_type' => 'code',
			'client_id'     => $client_id,
			'scope'         => 'read_write',
			'redirect_uri'  => $redirect_uri,
			'state'         => wp_create_nonce( 'zymevents_stripe_connect' ),
		);

		return 'https://connect.stripe.com/oauth/authorize?' . http_build_query( $params );
	}

	/**
	 * Handle the "Connect with Stripe" button click (redirect to Stripe OAuth).
	 */
	public static function handle_connect_redirect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		check_admin_referer( 'zymevents_stripe_connect_action' );

		$url = self::get_connect_url();
		if ( empty( $url ) ) {
			wp_redirect( admin_url( 'admin.php?page=zymevents-settings&zymevents_connect_error=no_client_id' ) );
			exit;
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Handle the OAuth callback from Stripe (runs on admin_init).
	 */
	public static function handle_connect_callback() {
		if ( empty( $_GET['zymevents_stripe_return'] ) || empty( $_GET['code'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = sanitize_text_field( $_GET['state'] ?? '' );
		if ( ! wp_verify_nonce( $state, 'zymevents_stripe_connect' ) ) {
			wp_redirect( admin_url( 'admin.php?page=zymevents-settings&zymevents_connect_error=invalid_state' ) );
			exit;
		}

		$code      = sanitize_text_field( $_GET['code'] );
		$client_id = defined( 'ZYMEVENTS_STRIPE_CLIENT_ID' ) ? ZYMEVENTS_STRIPE_CLIENT_ID : '';

		// Exchange authorization code for access token.
		$response = wp_remote_post( 'https://connect.stripe.com/oauth/token', array(
			'body' => array(
				'client_secret' => $client_id,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			wp_redirect( admin_url( 'admin.php?page=zymevents-settings&zymevents_connect_error=request_failed' ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			$err = urlencode( $body['error_description'] ?? $body['error'] );
			wp_redirect( admin_url( 'admin.php?page=zymevents-settings&zymevents_connect_error=' . $err ) );
			exit;
		}

		// Store the connected account credentials.
		update_option( 'zymevents_stripe_connect_account_id',      sanitize_text_field( $body['stripe_user_id'] ?? '' ) );
		update_option( 'zymevents_stripe_connect_secret_key',      sanitize_text_field( $body['access_token'] ?? '' ) );
		update_option( 'zymevents_stripe_connect_publishable_key', sanitize_text_field( $body['stripe_publishable_key'] ?? '' ) );
		update_option( 'zymevents_stripe_connect_livemode',        ! empty( $body['livemode'] ) ? '1' : '0' );

		wp_redirect( admin_url( 'admin.php?page=zymevents-settings&zymevents_connect_success=1' ) );
		exit;
	}

	/**
	 * Handle Stripe account disconnect.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		check_admin_referer( 'zymevents_stripe_disconnect_action' );

		delete_option( 'zymevents_stripe_connect_account_id' );
		delete_option( 'zymevents_stripe_connect_secret_key' );
		delete_option( 'zymevents_stripe_connect_publishable_key' );
		delete_option( 'zymevents_stripe_connect_livemode' );

		wp_redirect( admin_url( 'admin.php?page=zymevents-settings&zymevents_disconnected=1' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Scripts
	// ------------------------------------------------------------------

	public static function enqueue_scripts() {
		if ( ! self::is_active_provider( 'stripe' ) || ! self::is_configured() ) {
			return;
		}

		wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
		wp_register_script(
			'zymevents-payment',
			ZYMEVENTS_PLUGIN_URL . 'assets/js/payment.js',
			array( 'jquery', 'stripe-js' ),
			ZYMEVENTS_VERSION,
			true
		);

		wp_localize_script( 'zymevents-payment', 'zymStripeData', array(
			'publishableKey' => self::get_publishable_key(),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'zymevents_stripe_nonce' ),
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Payment Intent
	// ------------------------------------------------------------------

	/**
	 * AJAX: Create a Stripe Payment Intent.
	 */
	public static function ajax_create_payment_intent() {
		check_ajax_referer( 'zymevents_stripe_nonce', 'nonce' );

		$event_id = absint( $_POST['event_id'] ?? 0 );
		$amount   = floatval( $_POST['amount'] ?? 0 );
		$currency = strtolower( get_option( 'zymevents_currency', 'USD' ) );

		if ( ! $event_id || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid payment parameters.' ) );
		}

		$amount_cents = intval( round( $amount * 100 ) );

		$payload = array(
			'amount'   => $amount_cents,
			'currency' => $currency,
			'metadata' => array(
				'event_id' => $event_id,
				'source'   => 'zymevents',
			),
		);

		// Attach to connected account if available.
		$account_id = get_option( 'zymevents_stripe_connect_account_id', '' );
		if ( ! empty( $account_id ) ) {
			$payload['on_behalf_of'] = $account_id;
		}

		$response = self::api_request( 'payment_intents', $payload );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		wp_send_json_success( array(
			'clientSecret' => $response['client_secret'],
			'intentId'     => $response['id'],
		) );
	}

	// ------------------------------------------------------------------
	// AJAX: Confirm Payment
	// ------------------------------------------------------------------

	/**
	 * AJAX: Confirm payment and create registration.
	 */
	public static function ajax_confirm_payment() {
		check_ajax_referer( 'zymevents_stripe_nonce', 'nonce' );

		$event_id  = absint( $_POST['event_id'] ?? 0 );
		$intent_id = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );

		if ( ! $event_id || ! $intent_id ) {
			wp_send_json_error( array( 'message' => 'Missing payment data.' ) );
		}

		$intent = self::api_request( 'payment_intents/' . $intent_id, array(), 'GET' );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => 'Could not verify payment.' ) );
		}

		if ( $intent['status'] !== 'succeeded' ) {
			wp_send_json_error( array( 'message' => 'Payment has not been completed.' ) );
		}

		$payment = array(
			'provider'     => 'stripe',
			'payment_id'   => $intent_id,
			'payment_date' => current_time( 'mysql' ),
			'amount_paid'  => $intent['amount'] / 100,
		);

		$result = ZymEvents_Registrations::process_registration( $event_id, $_POST, $payment );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'         => 'Payment confirmed! Registration complete.',
			'registration_id' => $result['registration_id'],
		) );
	}

	// ------------------------------------------------------------------
	// Webhooks
	// ------------------------------------------------------------------

	/**
	 * Register the Stripe webhook REST endpoint.
	 */
	public static function register_webhook_endpoint() {
		register_rest_route( 'zymevents/v1', '/stripe-webhook', array(
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
		$secret  = get_option( 'zymevents_stripe_webhook_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'Webhook secret not configured.' ), 400 );
		}

		if ( ! self::verify_webhook_signature( $payload, $sig, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );

		if ( ! $event || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		switch ( $event['type'] ) {
			case 'payment_intent.succeeded':
				// Handled by frontend confirm flow.
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

		$reg_db = new ZymEvents_Registrations_DB();
		$table  = $reg_db->get_table_name();

		$registration = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE payment_id = %s AND payment_provider = %s LIMIT 1",
				$payment_intent_id,
				'stripe'
			)
		);

		if ( $registration ) {
			ZymEvents_Registrations::update_status( $registration->id, 'refunded' );
			do_action( 'zymevents_registration_refunded', $registration->id );
		}
	}

	// ------------------------------------------------------------------
	// Stripe API
	// ------------------------------------------------------------------

	/**
	 * Make a Stripe API request.
	 */
	private static function api_request( $endpoint, $data = array(), $method = 'POST' ) {
		$url = 'https://api.stripe.com/v1/' . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::get_secret_key(),
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

		// Tolerance: 5 minutes.
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
