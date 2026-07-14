<?php
/**
 * Attendees database class.
 *
 * Handles CRUD operations for the blt_attendees table.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Attendees_DB extends BLT_Events_DB {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'blt_attendees' );
	}

	/**
	 * Get all attendees for a registration.
	 *
	 * @param int $registration_id The registration row ID.
	 * @return array Array of attendee objects.
	 */
	public function get_by_registration( $registration_id ) {
		global $wpdb;

		$registration_id = absint( $registration_id );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE registration_id = %d ORDER BY id ASC",
				$registration_id
			)
		);
	}

	/**
	 * Get all attendees for an event.
	 *
	 * @param int $event_id The event post ID.
	 * @return array Array of attendee objects.
	 */
	public function get_by_event( $event_id ) {
		global $wpdb;

		$event_id = absint( $event_id );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE event_id = %d ORDER BY id ASC",
				$event_id
			)
		);
	}

	/**
	 * Attendee counts per ticket type for an event, excluding attendees
	 * whose registration was cancelled.
	 *
	 * @param int $event_id The event post ID.
	 * @return array Map of ticket type name => attendee count.
	 */
	public function count_by_ticket_type( $event_id ) {
		global $wpdb;

		$registrations_table = $wpdb->prefix . 'blt_registrations';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(a.ticket_type, ''), '—') AS ticket_type, COUNT(*) AS total
				 FROM {$this->table_name} a
				 INNER JOIN {$registrations_table} r ON r.id = a.registration_id
				 WHERE a.event_id = %d
				   AND r.status != 'cancelled'
				 GROUP BY COALESCE(NULLIF(a.ticket_type, ''), '—')
				 ORDER BY total DESC",
				absint( $event_id )
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->ticket_type ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Number of checked-in attendees for an event.
	 *
	 * @param int $event_id The event post ID.
	 * @return int
	 */
	public function count_checked_in( $event_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name}
				 WHERE event_id = %d AND check_in_status = 'checked_in'",
				absint( $event_id )
			)
		);
	}

	/**
	 * Total attendees for an event, excluding cancelled registrations.
	 *
	 * @param int $event_id The event post ID.
	 * @return int
	 */
	public function count_for_event( $event_id ) {
		global $wpdb;

		$registrations_table = $wpdb->prefix . 'blt_registrations';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table_name} a
				 INNER JOIN {$registrations_table} r ON r.id = a.registration_id
				 WHERE a.event_id = %d AND r.status != 'cancelled'",
				absint( $event_id )
			)
		);
	}

	/**
	 * Bulk insert multiple attendees for a registration.
	 *
	 * Each entry in $attendees_data should be an associative array with keys
	 * matching the blt_attendees columns (attendee_name, attendee_email, etc.).
	 *
	 * @param int   $registration_id The registration row ID.
	 * @param int   $event_id        The event post ID.
	 * @param array $attendees_data  Array of attendee data arrays.
	 * @return array Array of inserted attendee IDs.
	 */
	public function bulk_insert( $registration_id, $event_id, $attendees_data ) {
		global $wpdb;

		$registration_id = absint( $registration_id );
		$event_id        = absint( $event_id );
		$inserted_ids    = array();

		if ( empty( $attendees_data ) || ! is_array( $attendees_data ) ) {
			return $inserted_ids;
		}

		foreach ( $attendees_data as $attendee ) {
			$row = array(
				'registration_id' => $registration_id,
				'event_id'        => $event_id,
				'attendee_name'   => isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '',
				'attendee_email'  => isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '',
				'attendee_phone'  => isset( $attendee['attendee_phone'] ) ? sanitize_text_field( $attendee['attendee_phone'] ) : '',
				'ticket_type'     => isset( $attendee['ticket_type'] ) ? sanitize_text_field( $attendee['ticket_type'] ) : null,
				'ticket_price'    => isset( $attendee['ticket_price'] ) ? floatval( $attendee['ticket_price'] ) : 0.00,
				'custom_fields'   => isset( $attendee['custom_fields'] ) ? $attendee['custom_fields'] : null,
				'created_at'      => current_time( 'mysql' ),
			);

			$result = $wpdb->insert( $this->table_name, $row );

			if ( false !== $result ) {
				$inserted_ids[] = $wpdb->insert_id;
			}
		}

		return $inserted_ids;
	}

	/**
	 * Update the check-in status of an attendee.
	 *
	 * @param int    $attendee_id The attendee row ID.
	 * @param string $status      Check-in status: 'checked_in' or 'not_checked_in'.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public function update_check_in( $attendee_id, $status ) {
		global $wpdb;

		$attendee_id    = absint( $attendee_id );
		$allowed_status = array( 'checked_in', 'not_checked_in' );
		$status         = in_array( $status, $allowed_status, true ) ? $status : 'not_checked_in';

		$data = array(
			'check_in_status' => $status,
		);

		// Set or clear the check-in timestamp.
		if ( 'checked_in' === $status ) {
			$data['check_in_time'] = current_time( 'mysql' );
		} else {
			$data['check_in_time'] = null;
		}

		return $wpdb->update(
			$this->table_name,
			$data,
			array( $this->primary_key => $attendee_id ),
			null,
			array( '%d' )
		);
	}
}
