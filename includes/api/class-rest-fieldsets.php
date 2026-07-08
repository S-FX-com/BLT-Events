<?php
/**
 * BLT Events - REST API for Fieldsets
 *
 * Provides REST endpoints for managing registration fieldsets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_REST_Fieldsets {

	private static $namespace = 'blt-events/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// List all fieldsets
		register_rest_route( self::$namespace, '/fieldsets', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_fieldsets' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
		) );

		// Get single fieldset
		register_rest_route( self::$namespace, '/fieldsets/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_fieldset' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		// Create fieldset
		register_rest_route( self::$namespace, '/fieldsets', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_fieldset' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
		) );

		// Update fieldset
		register_rest_route( self::$namespace, '/fieldsets/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'update_fieldset' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		// Delete fieldset
		register_rest_route( self::$namespace, '/fieldsets/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_fieldset' ),
			'permission_callback' => array( __CLASS__, 'admin_permission_check' ),
			'args'                => array(
				'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		// Get event fieldset (public — used by frontend form)
		register_rest_route( self::$namespace, '/events/(?P<event_id>\d+)/fieldset', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_event_fieldset' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public static function get_fieldsets() {
		$fieldsets = BLT_Events_Fieldsets::get_active_fieldsets();

		$data = array();
		foreach ( $fieldsets as $fs ) {
			$data[] = self::prepare_fieldset( $fs );
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	public static function get_fieldset( $request ) {
		$id       = $request->get_param( 'id' );
		$fieldset = BLT_Events_Fieldsets::get_fieldset( $id );

		if ( ! $fieldset ) {
			return new WP_Error( 'not_found', __( 'Fieldset not found.', 'blt-events' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( self::prepare_fieldset( $fieldset ), 200 );
	}

	public static function create_fieldset( $request ) {
		$body = $request->get_json_params();

		$data = array(
			'name'           => sanitize_text_field( $body['name'] ?? '' ),
			'slug'           => sanitize_title( $body['slug'] ?? $body['name'] ?? '' ),
			'description'    => sanitize_textarea_field( $body['description'] ?? '' ),
			'fields'         => isset( $body['fields'] ) ? wp_json_encode( $body['fields'] ) : '[]',
			'consent_fields' => isset( $body['consent_fields'] ) ? wp_json_encode( $body['consent_fields'] ) : '[]',
			'status'         => 'active',
		);

		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Fieldset name is required.', 'blt-events' ), array( 'status' => 400 ) );
		}

		$id = BLT_Events_Fieldsets::save_fieldset( $data );

		if ( ! $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create fieldset.', 'blt-events' ), array( 'status' => 500 ) );
		}

		$fieldset = BLT_Events_Fieldsets::get_fieldset( $id );
		return new WP_REST_Response( self::prepare_fieldset( $fieldset ), 201 );
	}

	public static function update_fieldset( $request ) {
		$id   = $request->get_param( 'id' );
		$body = $request->get_json_params();

		$existing = BLT_Events_Fieldsets::get_fieldset( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Fieldset not found.', 'blt-events' ), array( 'status' => 404 ) );
		}

		$data = array( 'id' => $id );

		if ( isset( $body['name'] ) ) {
			$data['name'] = sanitize_text_field( $body['name'] );
		}
		if ( isset( $body['slug'] ) ) {
			$data['slug'] = sanitize_title( $body['slug'] );
		}
		if ( isset( $body['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $body['description'] );
		}
		if ( isset( $body['fields'] ) ) {
			$data['fields'] = wp_json_encode( $body['fields'] );
		}
		if ( isset( $body['consent_fields'] ) ) {
			$data['consent_fields'] = wp_json_encode( $body['consent_fields'] );
		}
		if ( isset( $body['status'] ) ) {
			$status = sanitize_text_field( $body['status'] );
			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return new WP_Error( 'invalid_status', __( 'Status must be "active" or "inactive".', 'blt-events' ), array( 'status' => 400 ) );
			}
			$data['status'] = $status;
		}

		$result = BLT_Events_Fieldsets::save_fieldset( $data );

		if ( $result === false ) {
			return new WP_Error( 'update_failed', __( 'Failed to update fieldset.', 'blt-events' ), array( 'status' => 500 ) );
		}

		$fieldset = BLT_Events_Fieldsets::get_fieldset( $id );
		return new WP_REST_Response( self::prepare_fieldset( $fieldset ), 200 );
	}

	public static function delete_fieldset( $request ) {
		$id = $request->get_param( 'id' );

		$existing = BLT_Events_Fieldsets::get_fieldset( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Fieldset not found.', 'blt-events' ), array( 'status' => 404 ) );
		}

		if ( $existing->is_default ) {
			return new WP_Error( 'cannot_delete', __( 'Cannot delete the default fieldset.', 'blt-events' ), array( 'status' => 403 ) );
		}

		$result = BLT_Events_Fieldsets::delete_fieldset( $id );

		if ( $result === false ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete fieldset.', 'blt-events' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'message' => __( 'Fieldset deleted.', 'blt-events' ), 'id' => $id ), 200 );
	}

	public static function get_event_fieldset( $request ) {
		$event_id = $request->get_param( 'event_id' );

		$event = get_post( $event_id );
		if ( ! $event || $event->post_type !== 'event' || ! is_post_publicly_viewable( $event ) ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'blt-events' ), array( 'status' => 404 ) );
		}

		$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $event_id );

		if ( ! $fieldset ) {
			return new WP_Error( 'no_fieldset', __( 'No fieldset configured.', 'blt-events' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( self::prepare_fieldset( $fieldset ), 200 );
	}

	private static function prepare_fieldset( $fieldset ) {
		return array(
			'id'             => (int) $fieldset->id,
			'name'           => $fieldset->name,
			'slug'           => $fieldset->slug,
			'description'    => $fieldset->description,
			'fields'         => json_decode( $fieldset->fields, true ) ?: array(),
			'consent_fields' => json_decode( $fieldset->consent_fields, true ) ?: array(),
			'is_default'     => (bool) $fieldset->is_default,
			'status'         => $fieldset->status,
			'created_at'     => $fieldset->created_at,
			'updated_at'     => $fieldset->updated_at,
		);
	}

	public static function admin_permission_check( $request ) {
		return BLT_Events_Helpers::user_can_manage();
	}
}
