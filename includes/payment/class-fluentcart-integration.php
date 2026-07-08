<?php
/**
 * BLT Events - FluentCart Payment Integration
 *
 * Syncs event ticket types as FluentCart products/variations,
 * builds instant-checkout URLs, and creates registrations when
 * FluentCart orders are paid (plus refund handling).
 *
 * Requires the FluentCart plugin (https://fluentcart.com) to be
 * installed and active on the same site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_FluentCart_Integration extends BLT_Events_Payment_Provider {

	public static function init() {
		if ( ! self::is_active_provider( 'fluentcart' ) ) {
			return;
		}

		// Sync products when an event is saved.
		add_action( 'save_post_event', array( __CLASS__, 'sync_event_products' ), 20, 1 );

		// Registration creation after a FluentCart order is paid
		// (fires asynchronously via Action Scheduler after payment confirmation).
		add_action( 'fluent_cart/order_paid_done', array( __CLASS__, 'handle_order_paid' ), 10, 1 );
		add_action( 'fluent_cart/order_placed_offline', array( __CLASS__, 'handle_order_paid' ), 10, 1 );

		// Refund handling.
		add_action( 'fluent_cart/order_fully_refunded', array( __CLASS__, 'handle_order_refunded' ), 10, 1 );

		// Checkout JS.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * The provider is "configured" when the FluentCart plugin is active.
	 * There is no API token: FluentCart runs on the same site.
	 */
	public static function is_configured() {
		return self::is_fluentcart_plugin_active();
	}

	public static function is_fluentcart_plugin_active() {
		return class_exists( '\FluentCart\App\Models\Product' )
			&& class_exists( '\FluentCart\App\Models\ProductVariation' );
	}

	public static function enqueue_scripts() {
		wp_register_script(
			'blt-events-fluentcart-checkout',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/fluentcart-checkout.js',
			array( 'jquery' ),
			BLT_EVENTS_VERSION,
			true
		);

		wp_localize_script( 'blt-events-fluentcart-checkout', 'bltFluentCartData', array(
			'checkoutBase' => self::get_instant_checkout_base(),
		) );
	}

	/**
	 * Sync an event's ticket types to FluentCart products/variations.
	 *
	 * Each event gets one FluentCart product; each ticket type becomes a
	 * variation of that product. Variation IDs are stored on the event so
	 * the checkout URL and order webhooks can be mapped back.
	 */
	public static function sync_event_products( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || get_post_type( $post_id ) !== 'event' ) {
			return;
		}

		if ( ! self::is_fluentcart_plugin_active() ) {
			return;
		}

		$ticket_types_raw = get_post_meta( $post_id, '_blt_ticket_types', true );
		$ticket_types     = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;

		if ( empty( $ticket_types ) || ! is_array( $ticket_types ) ) {
			return;
		}

		$event_title   = get_the_title( $post_id );
		$product_id    = (int) get_post_meta( $post_id, '_blt_fc_product_id', true );
		$variation_ids = get_post_meta( $post_id, '_blt_fc_variation_ids', true );
		$variation_ids = is_array( $variation_ids ) ? $variation_ids : array();

		try {
			// Create (or reuse) the FluentCart product for this event.
			if ( ! $product_id || get_post_type( $product_id ) !== 'fluent-products' ) {
				$product = \FluentCart\App\Models\Product::create( array(
					'post_title'   => $event_title,
					'post_content' => sprintf( __( 'Event tickets: %s', 'blt-events' ), $event_title ),
					'post_status'  => 'publish',
					'post_type'    => 'fluent-products',
				) );

				if ( empty( $product->ID ) ) {
					return;
				}

				$product_id = (int) $product->ID;
			}

			foreach ( $ticket_types as $i => $ticket ) {
				$ticket_name        = isset( $ticket['name'] ) ? (string) $ticket['name'] : 'Ticket';
				$ticket_price       = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
				$price_amount_cents = (int) round( $ticket_price * 100 );

				$variation = null;

				if ( ! empty( $variation_ids[ $i ] ) ) {
					$variation = \FluentCart\App\Models\ProductVariation::find( (int) $variation_ids[ $i ] );
				}

				if ( $variation ) {
					// Update title/price if they changed.
					if ( (int) $variation->item_price !== $price_amount_cents || $variation->variation_title !== $ticket_name ) {
						$variation->variation_title = $ticket_name;
						$variation->item_price      = $price_amount_cents;
						$variation->save();
					}
					continue;
				}

				$variation = \FluentCart\App\Models\ProductVariation::create( array(
					'post_id'          => $product_id,
					'variation_title'  => $ticket_name,
					'item_price'       => $price_amount_cents,
					'payment_type'     => 'onetime',
					'fulfillment_type' => 'digital',
					'item_status'      => 'active',
					'manage_stock'     => 'no',
					'other_info'       => array(
						'blt_event_id'     => $post_id,
						'blt_ticket_index' => $i,
					),
				) );

				if ( ! empty( $variation->id ) ) {
					$variation_ids[ $i ] = (int) $variation->id;
				}
			}
		} catch ( \Throwable $e ) {
			// FluentCart schema mismatch or DB error: log and bail without breaking the event save.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'BLT Events: FluentCart product sync failed - ' . $e->getMessage() );
			}
			return;
		}

		update_post_meta( $post_id, '_blt_fc_product_id', $product_id );
		update_post_meta( $post_id, '_blt_fc_variation_ids', $variation_ids );
	}

	/**
	 * Handle a paid (or offline-placed) FluentCart order.
	 *
	 * @param array $data Contains 'order', 'transaction', 'customer'.
	 */
	public static function handle_order_paid( $data ) {
		$order    = is_array( $data ) && isset( $data['order'] ) ? $data['order'] : null;
		$customer = is_array( $data ) && isset( $data['customer'] ) ? $data['customer'] : null;

		if ( ! $order ) {
			return;
		}

		$items = self::get_order_items( $order );
		if ( empty( $items ) ) {
			return;
		}

		$customer_email = '';
		$first_name     = '';
		$last_name      = '';

		if ( $customer ) {
			$customer_email = isset( $customer->email ) ? (string) $customer->email : '';
			$first_name     = isset( $customer->first_name ) ? (string) $customer->first_name : '';
			$last_name      = isset( $customer->last_name ) ? (string) $customer->last_name : '';
		}

		foreach ( $items as $item ) {
			$variation_id = self::get_item_variation_id( $item );
			if ( ! $variation_id ) {
				continue;
			}

			$event_id = self::find_event_by_variation_id( $variation_id );
			if ( ! $event_id ) {
				continue; // Not a BLT Events product.
			}

			$quantity   = isset( $item->quantity ) ? max( 1, (int) $item->quantity ) : 1;
			$line_total = 0;
			if ( isset( $item->line_total ) ) {
				$line_total = (float) $item->line_total / 100; // FluentCart stores cents.
			} elseif ( isset( $item->item_price ) ) {
				$line_total = ( (float) $item->item_price * $quantity ) / 100;
			}

			$reg_data = array(
				'event_id'   => $event_id,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $customer_email,
			);

			$payment = array(
				'provider'     => 'fluentcart',
				'payment_id'   => isset( $order->id ) ? (string) $order->id : '',
				'payment_date' => current_time( 'mysql' ),
				'amount_paid'  => $line_total,
			);

			BLT_Events_Registrations::process_registration( $event_id, $reg_data, $payment );
		}
	}

	/**
	 * Handle a fully refunded FluentCart order.
	 *
	 * @param array $data Contains 'order', 'refunded_amount', 'transaction'.
	 */
	public static function handle_order_refunded( $data ) {
		$order = is_array( $data ) && isset( $data['order'] ) ? $data['order'] : null;

		if ( ! $order || empty( $order->id ) ) {
			return;
		}

		global $wpdb;
		$reg_db = new BLT_Events_Registrations_DB();
		$table  = $reg_db->get_table_name();

		$registrations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE payment_provider = %s AND payment_id = %s",
				'fluentcart',
				(string) $order->id
			)
		);

		foreach ( $registrations as $reg ) {
			BLT_Events_Registrations::update_status( $reg->id, 'refunded' );
			do_action( 'blt_registration_refunded', $reg->id );
		}
	}

	/**
	 * Find an event by a FluentCart variation ID stored in event meta.
	 */
	public static function find_event_by_variation_id( $variation_id ) {
		$event_ids = get_posts( array(
			'post_type'      => 'event',
			'posts_per_page' => 500,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_key'       => '_blt_fc_variation_ids',
			'compare'        => 'EXISTS',
		) );

		foreach ( $event_ids as $event_id ) {
			$variation_ids = get_post_meta( $event_id, '_blt_fc_variation_ids', true );
			if ( is_array( $variation_ids ) && in_array( (int) $variation_id, array_map( 'intval', $variation_ids ), true ) ) {
				return (int) $event_id;
			}
		}

		return 0;
	}

	/**
	 * Build a FluentCart instant-checkout URL for a single variation.
	 *
	 * FluentCart's instant checkout takes one item per URL:
	 * https://example.com/?fluent-cart=instant_checkout&item_id={variation_id}&quantity={n}
	 */
	public static function build_checkout_url( $variation_id, $quantity = 1 ) {
		$url = add_query_arg(
			array(
				'fluent-cart' => 'instant_checkout',
				'item_id'     => (int) $variation_id,
				'quantity'    => max( 1, (int) $quantity ),
			),
			home_url( '/' )
		);

		/**
		 * Filter the FluentCart checkout URL for an event ticket.
		 *
		 * @param string $url          The instant-checkout URL.
		 * @param int    $variation_id The FluentCart variation ID.
		 * @param int    $quantity     The selected quantity.
		 */
		return apply_filters( 'blt_events_fluentcart_checkout_url', $url, $variation_id, $quantity );
	}

	/**
	 * Base URL used by the checkout JS.
	 */
	public static function get_instant_checkout_base() {
		return add_query_arg( array( 'fluent-cart' => 'instant_checkout' ), home_url( '/' ) );
	}

	/**
	 * Extract order items from a FluentCart Order model, tolerating
	 * relation-name differences between FluentCart versions.
	 */
	private static function get_order_items( $order ) {
		foreach ( array( 'order_items', 'items', 'orderItems' ) as $relation ) {
			if ( isset( $order->{$relation} ) && ! empty( $order->{$relation} ) ) {
				$items = $order->{$relation};
				return is_array( $items ) ? $items : $items->all();
			}
		}

		// Fallback: query the OrderItem model directly.
		if ( class_exists( '\FluentCart\App\Models\OrderItem' ) && ! empty( $order->id ) ) {
			$items = \FluentCart\App\Models\OrderItem::where( 'order_id', $order->id )->get();
			return $items ? $items->all() : array();
		}

		return array();
	}

	/**
	 * Extract the product variation ID from an order item, tolerating
	 * field-name differences between FluentCart versions.
	 */
	private static function get_item_variation_id( $item ) {
		foreach ( array( 'object_id', 'variation_id', 'item_id' ) as $field ) {
			if ( isset( $item->{$field} ) && (int) $item->{$field} > 0 ) {
				return (int) $item->{$field};
			}
		}

		return 0;
	}
}
