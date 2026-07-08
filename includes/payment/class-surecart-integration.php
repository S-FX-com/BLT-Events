<?php
/**
 * BLT Events - SureCart Payment Integration
 *
 * Syncs event ticket types as SureCart products/prices,
 * builds checkout URLs, and handles purchase confirmation webhooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_SureCart_Integration extends BLT_Events_Payment_Provider {

	private static $api_base = 'https://api.surecart.com/v1/';

	public static function init() {
		if ( ! self::is_active_provider( 'surecart' ) ) {
			return;
		}

		// Sync products when an event is saved
		add_action( 'save_post_event', array( __CLASS__, 'sync_event_products' ), 20, 1 );

		// Handle SureCart purchase confirmation
		add_action( 'surecart/checkout_confirmed', array( __CLASS__, 'handle_checkout_confirmed' ), 10, 2 );
		add_action( 'surecart/purchase_revoked', array( __CLASS__, 'handle_purchase_revoked' ), 10, 1 );

		// Enqueue SureCart checkout JS
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	public static function is_configured() {
		return ! empty( self::get_api_token() );
	}

	public static function is_surecart_plugin_active() {
		return defined( 'SURECART_PLUGIN_FILE' ) || class_exists( '\\SureCart\\SureCart' );
	}

	public static function enqueue_scripts() {
		if ( ! self::is_active_provider( 'surecart' ) ) {
			return;
		}

		wp_register_script(
			'blt-events-surecart-checkout',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/surecart-checkout.js',
			array( 'jquery' ),
			BLT_EVENTS_VERSION,
			true
		);

		wp_localize_script( 'blt-events-surecart-checkout', 'bltSurecartData', array(
			'checkoutUrl' => self::get_checkout_url(),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'blt_registration_nonce' ),
		) );
	}

	/**
	 * Sync an event's ticket types to SureCart products and prices.
	 */
	public static function sync_event_products( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || get_post_type( $post_id ) !== 'event' ) {
			return;
		}

		if ( ! self::is_configured() ) {
			return;
		}

		$ticket_types_raw = get_post_meta( $post_id, '_blt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;

		if ( empty( $ticket_types ) || ! is_array( $ticket_types ) ) {
			return;
		}

		$event_title   = get_the_title( $post_id );
		$product_ids   = get_post_meta( $post_id, '_blt_sc_product_ids', true ) ?: array();
		$price_ids     = get_post_meta( $post_id, '_blt_sc_price_ids', true ) ?: array();

		foreach ( $ticket_types as $i => $ticket ) {
			$ticket_name  = $ticket['name'] ?? 'Ticket';
			$ticket_price = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
			$product_name = $event_title . ' — ' . $ticket_name;

			// Create or get existing product
			if ( empty( $product_ids[ $i ] ) ) {
				$product = self::api_request( 'products', array(
					'name'        => $product_name,
					'description' => sprintf( __( 'Event ticket: %s', 'blt-events' ), $event_title ),
					'recurring'   => false,
					'metadata'    => array(
						'blt_event_id'    => $post_id,
						'blt_ticket_index' => $i,
					),
				) );

				if ( ! is_wp_error( $product ) && isset( $product['id'] ) ) {
					$product_ids[ $i ] = $product['id'];
				} else {
					continue;
				}
			}

			// Create or update price
			$price_amount_cents = intval( round( $ticket_price * 100 ) );

			if ( ! empty( $price_ids[ $i ] ) ) {
				// SureCart prices are immutable; archive old and create new if price changed
				$existing_price = self::api_request( 'prices/' . $price_ids[ $i ], array(), 'GET' );

				if ( ! is_wp_error( $existing_price ) && isset( $existing_price['amount'] ) ) {
					if ( (int) $existing_price['amount'] === $price_amount_cents ) {
						continue; // Price unchanged
					}

					// Archive old price
					self::api_request( 'prices/' . $price_ids[ $i ], array( 'archived' => true ), 'PATCH' );
				}
			}

			// Create new price
			$price_data = array(
				'product'  => $product_ids[ $i ],
				'amount'   => $price_amount_cents,
				'currency' => strtolower( get_option( 'blt_events_currency', 'usd' ) ),
			);

			$price = self::api_request( 'prices', $price_data );

			if ( ! is_wp_error( $price ) && isset( $price['id'] ) ) {
				$price_ids[ $i ] = $price['id'];
			}
		}

		update_post_meta( $post_id, '_blt_sc_product_ids', $product_ids );
		update_post_meta( $post_id, '_blt_sc_price_ids', $price_ids );
	}

	/**
	 * Handle SureCart checkout confirmation.
	 */
	public static function handle_checkout_confirmed( $checkout, $request = null ) {
		if ( empty( $checkout->purchases->data ) ) {
			return;
		}

		foreach ( $checkout->purchases->data as $purchase ) {
			$price_id = '';
			if ( isset( $purchase->price->id ) ) {
				$price_id = $purchase->price->id;
			} elseif ( isset( $purchase->price ) && is_string( $purchase->price ) ) {
				$price_id = $purchase->price;
			}

			if ( empty( $price_id ) ) {
				continue;
			}

			// Find the event by SureCart price ID
			$event_id = self::find_event_by_price_id( $price_id );
			if ( ! $event_id ) {
				continue;
			}

			$quantity = isset( $purchase->quantity ) ? (int) $purchase->quantity : 1;
			$amount   = isset( $purchase->price->amount ) ? $purchase->price->amount / 100 : 0;

			// Build registration data
			$customer_name  = '';
			$customer_email = '';
			if ( isset( $checkout->customer ) ) {
				$customer_name  = $checkout->customer->name ?? '';
				$customer_email = $checkout->customer->email ?? '';
			} elseif ( isset( $checkout->email ) ) {
				$customer_email = $checkout->email;
				$customer_name  = $checkout->name ?? $customer_email;
			}

			$data = array(
				'event_id'   => $event_id,
				'first_name' => $customer_name,
				'last_name'  => '',
				'email'      => $customer_email,
			);

			$payment = array(
				'provider'     => 'surecart',
				'payment_id'   => $checkout->id ?? '',
				'payment_date' => current_time( 'mysql' ),
				'amount_paid'  => $amount * $quantity,
			);

			BLT_Events_Registrations::process_registration( $event_id, $data, $payment );
		}
	}

	/**
	 * Handle SureCart purchase revocation (refund).
	 */
	public static function handle_purchase_revoked( $purchase ) {
		if ( empty( $purchase->id ) ) {
			return;
		}

		global $wpdb;
		$reg_db = new BLT_Events_Registrations_DB();
		$table  = $reg_db->get_table_name();

		$registrations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE payment_provider = %s AND payment_id = %s",
				'surecart',
				(string) ( $purchase->checkout_id ?? $purchase->id )
			)
		);

		foreach ( $registrations as $reg ) {
			BLT_Events_Registrations::update_status( $reg->id, 'refunded' );
			do_action( 'blt_registration_refunded', $reg->id );
		}
	}

	/**
	 * Find an event by its SureCart price ID.
	 */
	public static function find_event_by_price_id( $price_id ) {
		// Candidate lookup via LIKE, then exact in-PHP verification so a
		// price ID that is a substring of another can never match the
		// wrong event.
		$event_ids = get_posts( array(
			'post_type'      => 'event',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_blt_sc_price_ids',
					'value'   => $price_id,
					'compare' => 'LIKE',
				),
			),
		) );

		foreach ( $event_ids as $event_id ) {
			$price_ids = get_post_meta( $event_id, '_blt_sc_price_ids', true );
			if ( is_array( $price_ids ) && in_array( (string) $price_id, array_map( 'strval', $price_ids ), true ) ) {
				return (int) $event_id;
			}
		}

		return 0;
	}

	/**
	 * Build a checkout URL for given line items.
	 */
	public static function build_checkout_url( $line_items ) {
		$base_url = self::get_checkout_url();
		$params   = array();

		foreach ( $line_items as $i => $item ) {
			$params[] = 'line_items[' . $i . '][price_id]=' . urlencode( $item['price_id'] );
			$params[] = 'line_items[' . $i . '][quantity]=' . urlencode( $item['quantity'] );
		}

		$separator = ( strpos( $base_url, '?' ) !== false ) ? '&' : '?';
		return $base_url . $separator . implode( '&', $params );
	}

	/**
	 * Get the SureCart API token.
	 */
	private static function get_api_token() {
		$token = get_option( 'blt_events_surecart_api_token', '' );

		// Fallback: try getting token from SureCart plugin if installed
		if ( empty( $token ) && self::is_surecart_plugin_active() ) {
			$token = get_option( 'surecart_api_token', '' );
		}

		return $token;
	}

	/**
	 * Get the SureCart checkout page URL.
	 */
	private static function get_checkout_url() {
		$url = get_option( 'blt_events_surecart_checkout_url', '' );

		if ( empty( $url ) ) {
			$url = home_url( '/checkout' );
		}

		return $url;
	}

	/**
	 * Make a SureCart API request.
	 */
	private static function api_request( $endpoint, $data = array(), $method = 'POST' ) {
		$token = self::get_api_token();

		if ( empty( $token ) ) {
			return new WP_Error( 'no_token', __( 'SureCart API token not configured.', 'blt-events' ) );
		}

		$url = self::$api_base . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		);

		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) && ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$message = isset( $body['message'] ) ? $body['message'] : __( 'SureCart API error.', 'blt-events' );
			return new WP_Error( 'surecart_error', $message );
		}

		return $body;
	}
}
