<?php
/**
 * BLT Events - REST API for Registrations
 *
 * Provides REST endpoints for registrations and attendees.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_REST_Registrations {

	private static $namespace = 'blt-events/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// Get registrations for an event
		register_rest_route( self::$namespace, '/events/(?P<event_id>\d+)/registrations', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_event_registrations' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'page'     => array( 'default' => 1, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'default' => 20, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'status'   => array( 'default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// Get single registration
		register_rest_route( self::$namespace, '/registrations/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_registration' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		// Update registration status
		register_rest_route( self::$namespace, '/registrations/(?P<id>\d+)/status', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'update_status' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'status' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// Check in attendee
		register_rest_route( self::$namespace, '/attendees/(?P<id>\d+)/check-in', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'check_in_attendee' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'status' => array( 'default' => 'checked_in', 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// ICS download
		register_rest_route( self::$namespace, '/events/(?P<event_id>\d+)/ics', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download_ics' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public static function get_event_registrations( $request ) {
		$event_id = $request->get_param( 'event_id' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( (int) $request->get_param( 'per_page' ), 100 ) );
		$status   = $request->get_param( 'status' );

		$reg_db = new BLT_Events_Registrations_DB();

		$args = array(
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'status' => $status,
		);

		$registrations = $reg_db->get_by_event( $event_id, $args );

		$where = array( array( 'column' => 'event_id', 'value' => $event_id ) );
		if ( $status ) {
			$where[] = array( 'column' => 'status', 'value' => $status );
		}
		$total = $reg_db->count( $where );

		return new WP_REST_Response( array(
			'data'  => $registrations,
			'total' => $total,
			'pages' => ceil( $total / $per_page ),
		), 200 );
	}

	public static function get_registration( $request ) {
		$id = $request->get_param( 'id' );

		$reg = BLT_Events_Registrations::get_registration( $id );
		if ( ! $reg ) {
			return new WP_Error( 'not_found', __( 'Registration not found.', 'blt-events' ), array( 'status' => 404 ) );
		}

		$attendees = BLT_Events_Registrations::get_attendees( $id );
		$event     = get_post( $reg->event_id );

		return new WP_REST_Response( array(
			'registration' => $reg,
			'attendees'    => $attendees,
			'event'        => $event ? array(
				'id'    => $event->ID,
				'title' => $event->post_title,
			) : null,
		), 200 );
	}

	public static function update_status( $request ) {
		$id     = $request->get_param( 'id' );
		$status = $request->get_param( 'status' );

		$result = BLT_Events_Registrations::update_status( $id, $status );

		if ( $result === false ) {
			return new WP_Error( 'update_failed', __( 'Failed to update registration status.', 'blt-events' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array(
			'message' => sprintf( __( 'Status updated to %s', 'blt-events' ), $status ),
			'id'      => $id,
			'status'  => $status,
		), 200 );
	}

	public static function check_in_attendee( $request ) {
		$id     = $request->get_param( 'id' );
		$status = $request->get_param( 'status' );

		$att_db = new BLT_Events_Attendees_DB();
		$result = $att_db->update_check_in( $id, $status );

		if ( $result === false ) {
			return new WP_Error( 'update_failed', __( 'Failed to update check-in status.', 'blt-events' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array(
			'message' => __( 'Check-in status updated.', 'blt-events' ),
			'id'      => $id,
			'status'  => $status,
		), 200 );
	}

	public static function download_ics( $request ) {
		$event_id = $request->get_param( 'event_id' );
		$event    = get_post( $event_id );

		if ( ! $event || $event->post_type !== 'event' || ! is_post_publicly_viewable( $event ) ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'blt-events' ), array( 'status' => 404 ) );
		}

		$ics_content = BLT_Events_Helpers::generate_ics_content( $event );

		$response = new WP_REST_Response( $ics_content );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_title( $event->post_title ) . '.ics"' );

		return $response;
	}

	public static function admin_permission_check( $request ) {
		return BLT_Events_Helpers::user_can_manage();
	}
}
