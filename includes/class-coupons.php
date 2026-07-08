<?php
/**
 * BLT Events - Coupons Business Logic
 *
 * Validates coupon codes, calculates discounts, and tracks usage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Coupons {

	public static function init() {
		// Nothing to hook currently; all methods are called statically.
	}

	/**
	 * Validate a coupon code for a specific event.
	 *
	 * @param string $code       The coupon code to validate.
	 * @param int    $event_id   The event post ID.
	 * @param int    $quantity   Number of attendees (for min-attendees check).
	 * @return WP_Post|WP_Error  The coupon post on success, WP_Error on failure.
	 */
	public static function validate_coupon( $code, $event_id = 0, $quantity = 1 ) {
		if ( empty( $code ) ) {
			return new WP_Error( 'empty_code', 'Please enter a coupon code.' );
		}

		// Find the coupon by code
		$coupons = get_posts( array(
			'post_type'      => BLT_Events_Coupon_CPT::$slug,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_blt_coupon_code',
					'value' => strtoupper( $code ),
				),
			),
		) );

		if ( empty( $coupons ) ) {
			return new WP_Error( 'invalid_code', 'Invalid coupon code.' );
		}

		$coupon = $coupons[0];
		$prefix = '_blt_';

		// Check status
		$status = get_post_meta( $coupon->ID, $prefix . 'status', true );
		if ( $status === 'inactive' ) {
			return new WP_Error( 'inactive', 'This coupon is no longer active.' );
		}

		// Check expiration
		$expiry = get_post_meta( $coupon->ID, $prefix . 'expiration_date', true );
		if ( ! empty( $expiry ) && $expiry < current_time( 'Y-m-d' ) ) {
			return new WP_Error( 'expired', 'This coupon has expired.' );
		}

		// Check usage limit
		$usage_limit = (int) get_post_meta( $coupon->ID, $prefix . 'usage_limit', true );
		$total_uses  = (int) get_post_meta( $coupon->ID, $prefix . 'total_uses', true );
		if ( $usage_limit > 0 && $total_uses >= $usage_limit ) {
			return new WP_Error( 'limit_reached', 'This coupon has reached its usage limit.' );
		}

		// Check event applicability
		$applicable_events = get_post_meta( $coupon->ID, $prefix . 'applicable_events', true );
		if ( is_array( $applicable_events ) && ! in_array( 'all', $applicable_events ) && $event_id > 0 ) {
			if ( ! in_array( (string) $event_id, $applicable_events ) && ! in_array( $event_id, $applicable_events ) ) {
				return new WP_Error( 'not_applicable', 'This coupon is not valid for this event.' );
			}
		}

		return $coupon;
	}

	/**
	 * Calculate the discount amount for a coupon.
	 *
	 * @param WP_Post $coupon   The coupon post object.
	 * @param float   $subtotal The subtotal to apply the discount to.
	 * @return float The discount amount.
	 */
	public static function calculate_discount( $coupon, $subtotal ) {
		$type   = get_post_meta( $coupon->ID, '_blt_discount_type', true );
		$amount = (float) get_post_meta( $coupon->ID, '_blt_amount', true );

		if ( $type === 'percentage' ) {
			$discount = $subtotal * ( $amount / 100 );
		} else {
			$discount = $amount;
		}

		return min( round( $discount, 2 ), $subtotal );
	}

	/**
	 * Record coupon usage after a registration.
	 *
	 * @param int   $coupon_id       The coupon post ID.
	 * @param int   $registration_id The registration ID.
	 * @param float $amount_saved    The discount amount applied.
	 */
	public static function record_usage( $coupon_id, $registration_id, $amount_saved ) {
		global $wpdb;
		$prefix = '_blt_';

		// Increment total uses atomically so concurrent registrations
		// cannot lose updates.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
			$coupon_id,
			$prefix . 'total_uses'
		) );

		if ( ! $updated ) {
			// Meta row doesn't exist yet.
			add_post_meta( $coupon_id, $prefix . 'total_uses', 1, true );
		}

		wp_cache_delete( $coupon_id, 'post_meta' );

		// Update total savings
		$total_savings = (float) get_post_meta( $coupon_id, $prefix . 'total_savings', true );
		update_post_meta( $coupon_id, $prefix . 'total_savings', $total_savings + $amount_saved );

		// Update last used
		update_post_meta( $coupon_id, $prefix . 'last_used', time() );

		// Add to usage history
		$history = get_post_meta( $coupon_id, $prefix . 'usage_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'registration_id' => $registration_id,
			'amount_saved'    => $amount_saved,
			'date'            => time(),
		);

		update_post_meta( $coupon_id, $prefix . 'usage_history', $history );
	}
}
