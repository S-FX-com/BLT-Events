<?php
/**
 * Registrations database class.
 *
 * Handles CRUD operations for the zymevents_registrations table.
 *
 * @package ZymEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZymEvents_Registrations_DB extends ZymEvents_DB {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'zymevents_registrations' );
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
	 * Check if an email is already registered for a specific event.
	 *
	 * Used for duplicate registration prevention.
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
				   AND status != %s",
				$email,
				$event_id,
				'cancelled'
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get the total number of attendees registered for an event.
	 *
	 * Sums the attendee_count column for all non-cancelled registrations.
	 * Used for capacity checks.
	 *
	 * @param int $event_id The event post ID.
	 * @return int Total attendee count.
	 */
	public function get_event_registration_count( $event_id ) {
		global $wpdb;

		$event_id = absint( $event_id );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( attendee_count ), 0 )
				 FROM {$this->table_name}
				 WHERE event_id = %d
				   AND status != %s",
				$event_id,
				'cancelled'
			)
		);

		return (int) $count;
	}
}
