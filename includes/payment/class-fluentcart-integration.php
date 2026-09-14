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
		// Enabled, not "selected". Order and refund listeners must stay
		// registered for as long as the site holds live FluentCart orders,
		// even after new events have moved to another provider.
		if ( ! self::is_enabled_provider( 'fluentcart' ) ) {
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
		add_action( 'fluent_cart/order_partially_refunded', array( __CLASS__, 'handle_order_partially_refunded' ), 10, 1 );

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
		if ( ! self::has_events_using( 'fluentcart' ) ) {
			return;
		}

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

		// Only sync events that actually check out through FluentCart. With
		// more than one provider enabled, syncing every event would create a
		// FluentCart product for tickets that are sold somewhere else.
		if ( ! self::is_active_provider( 'fluentcart', $post_id ) ) {
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
	 * Order lines are grouped per event so each event produces exactly one
	 * registration holding every seat bought for it, rather than one
	 * registration per line.
	 *
	 * @param array $data Contains 'order', 'transaction', 'customer'.
	 */
	public static function handle_order_paid( $data ) {
		$order    = is_array( $data ) && isset( $data['order'] ) ? $data['order'] : null;
		$customer = is_array( $data ) && isset( $data['customer'] ) ? $data['customer'] : null;

		if ( ! $order || empty( $order->id ) ) {
			return;
		}

		$items = self::get_order_items( $order );
		if ( empty( $items ) ) {
			return;
		}

		$order_id = (string) $order->id;
		$orders   = array();

		foreach ( $items as $item ) {
			$variation_id = self::get_item_variation_id( $item );
			if ( ! $variation_id ) {
				continue;
			}

			$match = self::find_ticket_by_variation_id( $variation_id );
			if ( ! $match ) {
				continue; // Not a BLT Events product.
			}

			$event_id = $match['event_id'];
			$quantity = isset( $item->quantity ) ? max( 1, (int) $item->quantity ) : 1;

			// FluentCart stores money in cents.
			$line_total = 0;
			if ( isset( $item->line_total ) ) {
				$line_total = (float) $item->line_total / 100;
			} elseif ( isset( $item->item_price ) ) {
				$line_total = ( (float) $item->item_price * $quantity ) / 100;
			}

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
			$orders[ $event_id ]['amount'] += $line_total;
		}

		if ( empty( $orders ) ) {
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

		$reg_db = new BLT_Events_Registrations_DB();

		foreach ( $orders as $event_id => $order_data ) {
			// Idempotency: these handlers run through Action Scheduler, which
			// retries. Without this a retried job registers the buyer again.
			if ( ! empty( $reg_db->get_by_payment( 'fluentcart', $order_id, $event_id ) ) ) {
				continue;
			}

			$reg_data = array(
				'event_id'   => $event_id,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $customer_email,
			);

			$payment = array(
				'provider'     => 'fluentcart',
				'payment_id'   => $order_id,
				'payment_date' => current_time( 'mysql' ),
				'amount_paid'  => round( $order_data['amount'], 2 ),
				'line_items'   => $order_data['line_items'],
			);

			$result = BLT_Events_Registrations::process_registration( $event_id, $reg_data, $payment );

			if ( is_wp_error( $result ) ) {
				// A paid order that produced no registration must never fail
				// silently.
				error_log( sprintf(
					'BLT Events: FluentCart order %1$s was paid but no registration could be created for event %2$d - %3$s',
					$order_id,
					$event_id,
					$result->get_error_message()
				) );

				/** This action is documented in includes/payment/class-surecart-integration.php */
				do_action( 'blt_events_payment_orphaned', 'fluentcart', $order_id, $event_id, $result );
			}
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

		$reg_db = new BLT_Events_Registrations_DB();

		foreach ( $reg_db->get_by_payment( 'fluentcart', (string) $order->id ) as $reg ) {
			if ( 'refunded' === $reg->status ) {
				continue;
			}

			BLT_Events_Registrations::update_status( $reg->id, 'refunded' );
			do_action( 'blt_registration_refunded', $reg->id );
		}
	}

	/**
	 * Handle a partially refunded FluentCart order.
	 *
	 * A partial refund is not a cancellation: some seats may still be valid,
	 * and only a human can say which. The registration therefore keeps its
	 * status and is flagged for review instead of being silently voided.
	 *
	 * @param array $data Contains 'order', 'refunded_amount', 'transaction'.
	 */
	public static function handle_order_partially_refunded( $data ) {
		$order = is_array( $data ) && isset( $data['order'] ) ? $data['order'] : null;

		if ( ! $order || empty( $order->id ) ) {
			return;
		}

		$refunded = isset( $data['refunded_amount'] ) ? (float) $data['refunded_amount'] / 100 : 0;
		$reg_db   = new BLT_Events_Registrations_DB();

		foreach ( $reg_db->get_by_payment( 'fluentcart', (string) $order->id ) as $reg ) {
			/**
			 * Fires when part of a registration's payment has been refunded.
			 *
			 * @param int   $registration_id The registration ID.
			 * @param float $refunded_amount The amount refunded, in the store currency.
			 * @param mixed $order           The FluentCart order.
			 */
			do_action( 'blt_registration_partially_refunded', $reg->id, $refunded, $order );
		}
	}

	/**
	 * Find the event and ticket index behind a FluentCart variation ID.
	 *
	 * Narrows to candidate events with a meta_query first, then verifies in
	 * PHP: the variation IDs live in a serialized array, so a LIKE can match
	 * on a substring (12 inside 123) and must never be trusted on its own.
	 *
	 * The previous implementation loaded every event on the site and read its
	 * meta one row at a time — on a calendar with a few hundred events that
	 * was hundreds of uncached queries per order line.
	 *
	 * @param int $variation_id FluentCart product variation ID.
	 * @return array{event_id:int,index:int}|null
	 */
	public static function find_ticket_by_variation_id( $variation_id ) {
		$variation_id = (int) $variation_id;

		if ( $variation_id <= 0 ) {
			return null;
		}

		$event_ids = get_posts( array(
			'post_type'      => 'event',
			'posts_per_page' => 100,
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_blt_fc_variation_ids',
					'value'   => (string) $variation_id,
					'compare' => 'LIKE',
				),
			),
		) );

		foreach ( $event_ids as $event_id ) {
			$variation_ids = get_post_meta( $event_id, '_blt_fc_variation_ids', true );

			if ( ! is_array( $variation_ids ) ) {
				continue;
			}

			$index = array_search( $variation_id, array_map( 'intval', $variation_ids ), true );

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
	 * Find an event by a FluentCart variation ID stored in event meta.
	 *
	 * @deprecated Use find_ticket_by_variation_id(), which also returns the
	 *             ticket index the order line maps to. Kept for add-ons.
	 *
	 * @param int $variation_id FluentCart product variation ID.
	 * @return int Event post ID, or 0.
	 */
	public static function find_event_by_variation_id( $variation_id ) {
		$match = self::find_ticket_by_variation_id( $variation_id );

		return $match ? $match['event_id'] : 0;
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
