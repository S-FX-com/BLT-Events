<?php
/**
 * Attendees database class.
 *
 * Handles CRUD operations for the cmt_attendees table.
 *
 * @package CMT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMT_Events_Attendees_DB extends CMT_Events_DB {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'cmt_attendees' );
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
	 * Bulk insert multiple attendees for a registration.
	 *
	 * Each entry in $attendees_data should be an associative array with keys
	 * matching the cmt_attendees columns (attendee_name, attendee_email, etc.).
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
