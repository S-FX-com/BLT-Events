<?php
/**
 * Registrations database class.
 *
 * Handles CRUD operations for the blt_registrations table.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Registrations_DB extends BLT_Events_DB {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'blt_registrations' );
	}

	/**
	 * Get registrations for a specific event with pagination.
	 *
	 * @param int   $event_id The event post ID.
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $orderby Column to order by. Default 'created_at'.
	 *     @type string $order   ASC or DESC. Default 'DESC'.
	 *     @type int    $limit   Number of rows. Default 20.
	 *     @type int    $offset  Rows to skip. Default 0.
	 *     @type string $status  Filter by registration status. Default empty (all).
	 * }
	 * @return array Array of registration objects.
	 */
	public function get_by_event( $event_id, $args = array() ) {
		$event_id = absint( $event_id );

		$defaults = array(
			'orderby' => 'created_at',
			'order'   => 'DESC',
			'limit'   => 20,
			'offset'  => 0,
			'status'  => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array(
			array(
				'column' => 'event_id',
				'value'  => $event_id,
			),
		);

		if ( ! empty( $args['status'] ) ) {
			$where[] = array(
				'column' => 'status',
				'value'  => sanitize_text_field( $args['status'] ),
			);
		}

		return $this->get_all( array(
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
			'limit'   => $args['limit'],
			'offset'  => $args['offset'],
			'where'   => $where,
		) );
	}

	/**
	 * Get all registrations for an email address.
	 *
	 * @param string $email The customer email.
	 * @return array Array of registration objects.
	 */
	public function get_by_email( $email ) {
		global $wpdb;

		$email = sanitize_email( $email );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE customer_email = %s ORDER BY created_at DESC",
				$email
			)
		);
	}

	/**
	 * Get all registrations in a group.
	 *
	 * @param string $group_id The group UUID.
	 * @return array Array of registration objects.
	 */
	public function get_by_group( $group_id ) {
		global $wpdb;

		$group_id = sanitize_text_field( $group_id );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE group_id = %s ORDER BY created_at ASC",
				$group_id
			)
		);
	}

	/**
	 * Whether an email has a confirmed registration for an event. Used to
	 * decide whether to reveal the online meeting link to a visitor.
	 *
	 * @param string $email    The customer email.
	 * @param int    $event_id The event post ID.
	 * @return bool
	 */
	public function email_confirmed_for_event( $email, $event_id ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name}
				 WHERE customer_email = %s AND event_id = %d AND status = %s",
				sanitize_email( $email ),
				absint( $event_id ),
				'confirmed'
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Check if an email is already registered for a specific event.
	 *
	 * Used for duplicate registration prevention. Cancelled and refunded
	 * registrations do not count: that person may legitimately register
	 * again. A waitlisted registration does count, so nobody joins the
	 * waitlist twice.
	 *
	 * @param string $email    The customer email.
	 * @param int    $event_id The event post ID.
	 * @return bool True if a registration exists, false otherwise.
	 */
	public function email_registered_for_event( $email, $event_id ) {
		global $wpdb;

		$email    = sanitize_email( $email );
		$event_id = absint( $event_id );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name}
				 WHERE customer_email = %s
				   AND event_id = %d
				   AND status NOT IN ( %s, %s )",
				$email,
				$event_id,
				'cancelled',
				'refunded'
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get the registrations recorded against one processor payment.
	 *
	 * Off-site checkouts create registrations from webhooks, which retry.
	 * Callers use this to stay idempotent: a retried SureCart confirmation
	 * or a replayed FluentCart `order_paid_done` must not register the same
	 * buyer twice.
	 *
	 * @param string $provider   Payment provider slug.
	 * @param string $payment_id Provider-side payment/order/checkout ID.
	 * @param int    $event_id   Optional event to narrow to. One order can
	 *                           carry tickets for several events.
	 * @return array Registration rows (empty when none).
	 */
	public function get_by_payment( $provider, $payment_id, $event_id = 0 ) {
		global $wpdb;

		$payment_id = sanitize_text_field( $payment_id );
		if ( '' === $payment_id ) {
			return array();
		}

		$sql    = "SELECT * FROM {$this->table_name} WHERE payment_provider = %s AND payment_id = %s";
		$params = array( sanitize_text_field( $provider ), $payment_id );

		if ( $event_id ) {
			$sql     .= ' AND event_id = %d';
			$params[] = absint( $event_id );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Registration counts per status for an event.
	 *
	 * @param int $event_id The event post ID.
	 * @return array Map of status => registration count.
	 */
	public function count_by_status( $event_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total
				 FROM {$this->table_name}
				 WHERE event_id = %d
				 GROUP BY status",
				absint( $event_id )
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Get the total number of attendees holding a seat on an event.
	 *
	 * Sums the attendee_count column for registrations in a seat-holding
	 * status (pending or confirmed by default). Cancelled, refunded and
	 * waitlisted registrations do not occupy capacity.
	 *
	 * @param int $event_id The event post ID.
	 * @return int Total attendee count.
	 */
	public function get_event_registration_count( $event_id ) {
		global $wpdb;

		$event_id = absint( $event_id );
		$statuses = BLT_Events_Helpers::seat_holding_statuses();

		if ( empty( $statuses ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $event_id ), array_values( $statuses ) );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( attendee_count ), 0 )
				 FROM {$this->table_name}
				 WHERE event_id = %d
				   AND status IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return (int) $count;
	}

	/**
	 * Waitlisted registrations for an event, oldest first.
	 *
	 * @param int $event_id The event post ID.
	 * @param int $limit    Max rows.
	 * @return array
	 */
	public function get_waitlist( $event_id, $limit = 100 ) {
		return $this->get_all( array(
			'orderby' => 'created_at',
			'order'   => 'ASC',
			'limit'   => $limit,
			'where'   => array(
				array( 'column' => 'event_id', 'value' => absint( $event_id ) ),
				array( 'column' => 'status', 'value' => 'waitlisted' ),
			),
		) );
	}

	/**
	 * Number of waitlisted registrations for an event.
	 *
	 * @param int $event_id The event post ID.
	 * @return int
	 */
	public function count_waitlisted( $event_id ) {
		return $this->count( array(
			array( 'column' => 'event_id', 'value' => absint( $event_id ) ),
			array( 'column' => 'status', 'value' => 'waitlisted' ),
		) );
	}
}
