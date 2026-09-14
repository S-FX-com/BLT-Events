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
		// Enabled, not "selected". Purchase and refund listeners must stay
		// registered for as long as the site holds live SureCart orders, even
		// after new events have moved to another provider.
		if ( ! self::is_enabled_provider( 'surecart' ) ) {
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
		if ( ! self::has_events_using( 'surecart' ) ) {
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

		// Only sync events that actually check out through SureCart. With more
		// than one provider enabled, syncing every event would create a
		// SureCart product for tickets that are sold somewhere else entirely.
		if ( ! self::is_active_provider( 'surecart', $post_id ) ) {
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
	 *
	 * One checkout can carry several ticket types, and several events. The
	 * purchases are therefore grouped per event so each event produces exactly
	 * one registration holding every seat bought for it — rather than one
	 * registration per line, which split a single booking into unrelated rows
	 * and tripped the duplicate-email guard on the second one.
	 */
	public static function handle_checkout_confirmed( $checkout, $request = null ) {
		if ( empty( $checkout->purchases->data ) ) {
			return;
		}

		$checkout_id = isset( $checkout->id ) ? (string) $checkout->id : '';
		if ( '' === $checkout_id ) {
			return;
		}

		// Group the checkout's lines by event.
		$orders = array();

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

			// Find the event and ticket index behind this SureCart price.
			$match = self::find_ticket_by_price_id( $price_id );
			if ( ! $match ) {
				continue; // Not a BLT Events price.
			}

			$event_id = $match['event_id'];
			$quantity = isset( $purchase->quantity ) ? max( 1, (int) $purchase->quantity ) : 1;
			$unit     = isset( $purchase->price->amount ) ? (float) $purchase->price->amount / 100 : 0;

			if ( ! isset( $orders[ $event_id ] ) ) {
				$orders[ $event_id ] = array(
					'line_items' => array(),
					'amount'     => 0,
				);
			}

			$orders[ $event_id ]['line_items'][] = array(
				'index'    => $match['index'],
				'quantity' => $quantity,
			);
			$orders[ $event_id ]['amount'] += $unit * $quantity;
		}

		if ( empty( $orders ) ) {
			return;
		}

		// Buyer details. SureCart collects a single name field.
		$customer_name  = '';
		$customer_email = '';
		if ( isset( $checkout->customer ) ) {
			$customer_name  = $checkout->customer->name ?? '';
			$customer_email = $checkout->customer->email ?? '';
		} elseif ( isset( $checkout->email ) ) {
			$customer_email = $checkout->email;
			$customer_name  = $checkout->name ?? $customer_email;
		}

		$name_parts = self::split_name( $customer_name );
		$reg_db     = new BLT_Events_Registrations_DB();

		foreach ( $orders as $event_id => $order ) {
			// Idempotency: SureCart can confirm the same checkout more than
			// once (retries, a manual re-send from the dashboard). Without
			// this the buyer is registered again on every replay.
			if ( ! empty( $reg_db->get_by_payment( 'surecart', $checkout_id, $event_id ) ) ) {
				continue;
			}

			$data = array(
				'event_id'   => $event_id,
				'first_name' => $name_parts['first'],
				'last_name'  => $name_parts['last'],
				'email'      => $customer_email,
			);

			$payment = array(
				'provider'     => 'surecart',
				'payment_id'   => $checkout_id,
				'payment_date' => current_time( 'mysql' ),
				'amount_paid'  => round( $order['amount'], 2 ),
				'line_items'   => $order['line_items'],
			);

			$result = BLT_Events_Registrations::process_registration( $event_id, $data, $payment );

			if ( is_wp_error( $result ) ) {
				// A paid order that produced no registration must never fail
				// silently — process_registration() only returns an error here
				// for genuinely unrecoverable cases, such as a failed insert.
				self::log_failed_registration( $checkout_id, $event_id, $result );
			}
		}
	}

	/**
	 * Handle SureCart purchase revocation (refund).
	 */
	public static function handle_purchase_revoked( $purchase ) {
		if ( empty( $purchase->id ) ) {
			return;
		}

		$reg_db  = new BLT_Events_Registrations_DB();
		$payment = (string) ( $purchase->checkout_id ?? $purchase->id );

		foreach ( $reg_db->get_by_payment( 'surecart', $payment ) as $reg ) {
			if ( 'refunded' === $reg->status ) {
				continue;
			}

			BLT_Events_Registrations::update_status( $reg->id, 'refunded' );
			do_action( 'blt_registration_refunded', $reg->id );
		}
	}

	/**
	 * Split a single display name into first and last.
	 *
	 * SureCart stores one name field; the registrations table and every export
	 * built on it expect the two separately.
	 *
	 * @param string $name Full name.
	 * @return array{first:string,last:string}
	 */
	private static function split_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );

		if ( '' === $name ) {
			return array( 'first' => '', 'last' => '' );
		}

		$pos = strrpos( $name, ' ' );
		if ( false === $pos ) {
			return array( 'first' => $name, 'last' => '' );
		}

		return array(
			'first' => substr( $name, 0, $pos ),
			'last'  => substr( $name, $pos + 1 ),
		);
	}

	/**
	 * Record a paid SureCart order that could not be turned into a
	 * registration, so it is recoverable rather than lost.
	 *
	 * @param string   $checkout_id The SureCart checkout ID.
	 * @param int      $event_id    The event post ID.
	 * @param WP_Error $error       The failure.
	 */
	private static function log_failed_registration( $checkout_id, $event_id, $error ) {
		$message = sprintf(
			'BLT Events: SureCart checkout %1$s was paid but no registration could be created for event %2$d - %3$s',
			$checkout_id,
			$event_id,
			$error->get_error_message()
		);

		error_log( $message );

		/**
		 * Fires when a completed off-site payment produced no registration.
		 *
		 * @param string   $provider   Provider slug.
		 * @param string   $payment_id Provider-side payment ID.
		 * @param int      $event_id   The event post ID.
		 * @param WP_Error $error      The failure.
		 */
		do_action( 'blt_events_payment_orphaned', 'surecart', $checkout_id, $event_id, $error );
	}

	/**
	 * Find the event and ticket index behind a SureCart price ID.
	 *
	 * @param string $price_id SureCart price ID.
	 * @return array{event_id:int,index:int}|null
	 */
	public static function find_ticket_by_price_id( $price_id ) {
		// Candidate lookup via LIKE, then exact in-PHP verification so a
		// price ID that is a substring of another can never match the
		// wrong event.
		$event_ids = get_posts( array(
			'post_type'      => 'event',
			'posts_per_page' => 100,
			'post_status'    => array( 'publish', 'private', 'draft' ),
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

			if ( ! is_array( $price_ids ) ) {
				continue;
			}

			$index = array_search( (string) $price_id, array_map( 'strval', $price_ids ), true );

			if ( false !== $index ) {
				return array(
					'event_id' => (int) $event_id,
					'index'    => (int) $index,
				);
			}
		}

		return null;
	}

	/**
	 * Find an event by its SureCart price ID.
	 *
	 * @deprecated Use find_ticket_by_price_id(), which also returns the ticket
	 *             index the order line maps to. Kept for add-ons.
	 *
	 * @param string $price_id SureCart price ID.
	 * @return int Event post ID, or 0.
	 */
	public static function find_event_by_price_id( $price_id ) {
		$match = self::find_ticket_by_price_id( $price_id );

		return $match ? $match['event_id'] : 0;
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
	 *
	 * Resolution order, each rung only consulted once the previous one comes
	 * back empty: this plugin's own option, the SureCart plugin's own stored
	 * token, then the shared BLT family store. Nothing here ever writes a
	 * resolved value back into an option.
	 *
	 * The shared store is deliberately LAST, below SureCart's own token. The
	 * Payments tab tells admins they can leave this plugin's field blank when
	 * the SureCart plugin is installed and connected, so "own option empty,
	 * SureCart's token in use" is a supported, working configuration. Putting
	 * the shared rung above it would silently switch such a site onto a
	 * different token the moment the owner opted this plugin into the shared
	 * group — with no visible change, because the Connected badge resolves
	 * through the same method.
	 */
	private static function get_api_token() {
		$token = get_option( 'blt_events_surecart_api_token', '' );

		// Fallback: try getting token from SureCart plugin if installed
		if ( empty( $token ) && self::is_surecart_plugin_active() ) {
			$token = get_option( 'surecart_api_token', '' );
		}

		// Shared BLT family store (opt-in, off by default).
		if ( empty( $token ) && class_exists( 'BLT_Family' ) ) {
			$token = BLT_Family::get( 'blt-events', 'surecart', 'api_token' );
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
