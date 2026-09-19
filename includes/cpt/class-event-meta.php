<?php
/**
 * BLT Events - Event meta in the REST API
 *
 * The event post type has show_in_rest, but until now none of its meta did,
 * so Query Loop blocks, headless front ends and integrations saw a title
 * and nothing else. This registers the public meta keys and adds a
 * computed `blt_event` field with the formatted, ready-to-display values.
 *
 * The online join link is deliberately not exposed: it is shown only to
 * confirmed registrants.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Event_Meta {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 11 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_field' ) );
	}

	/**
	 * Meta keys exposed to the REST API, with their schema type.
	 *
	 * @return array<string,array>
	 */
	public static function keys() {
		$keys = array(
			'_blt_event_date'               => array( 'type' => 'string', 'description' => __( 'Start date (Y-m-d).', 'blt-events' ) ),
			'_blt_event_end_date'           => array( 'type' => 'string', 'description' => __( 'End date for multi-day events (Y-m-d).', 'blt-events' ) ),
			'_blt_event_start_time'         => array( 'type' => 'string', 'description' => __( 'Start time (H:i).', 'blt-events' ) ),
			'_blt_event_end_time'           => array( 'type' => 'string', 'description' => __( 'End time (H:i).', 'blt-events' ) ),
			'_blt_event_all_day'            => array( 'type' => 'string', 'description' => __( '"1" for all-day events.', 'blt-events' ) ),
			'_blt_multi_day'                => array( 'type' => 'string', 'description' => __( '"1" for multi-day events.', 'blt-events' ) ),
			'_blt_event_type'               => array( 'type' => 'string', 'description' => __( 'online, in-person or hybrid.', 'blt-events' ) ),
			'_blt_event_venue'              => array( 'type' => 'string', 'description' => __( 'Venue name.', 'blt-events' ) ),
			'_blt_event_location'           => array( 'type' => 'string', 'description' => __( 'Street address.', 'blt-events' ) ),
			'_blt_event_latitude'           => array( 'type' => 'string', 'description' => __( 'Latitude.', 'blt-events' ) ),
			'_blt_event_longitude'          => array( 'type' => 'string', 'description' => __( 'Longitude.', 'blt-events' ) ),
			'_blt_capacity'                 => array( 'type' => 'string', 'description' => __( 'Capacity (0 = unlimited).', 'blt-events' ) ),
			'_blt_registration_open'        => array( 'type' => 'string', 'description' => __( '"1" when registration is open.', 'blt-events' ) ),
			'_blt_registration_cutoff_date' => array( 'type' => 'string', 'description' => __( 'Registration cutoff date.', 'blt-events' ) ),
			'_blt_require_approval'         => array( 'type' => 'string', 'description' => __( '"1" when registrations need approval.', 'blt-events' ) ),
			'_blt_featured'                 => array( 'type' => 'string', 'description' => __( '"1" for featured events.', 'blt-events' ) ),
			'_blt_hide_from_calendar'       => array( 'type' => 'string', 'description' => __( '"1" to hide from listings.', 'blt-events' ) ),
			'_blt_ticket_types'             => array( 'type' => 'string', 'description' => __( 'Ticket types (JSON).', 'blt-events' ) ),
		);

		/**
		 * Filter the event meta keys exposed to the REST API.
		 *
		 * @param array $keys Key => array( type, description ).
		 */
		return apply_filters( 'blt_events_rest_meta_keys', $keys );
	}

	public static function register_meta() {
		foreach ( self::keys() as $key => $def ) {
			register_post_meta( 'event', $key, array(
				'type'              => $def['type'],
				'description'       => $def['description'] ?? '',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'auth_callback'     => function () {
					return current_user_can( 'edit_blt_events' ) || current_user_can( 'edit_posts' );
				},
			) );
		}
	}

	public static function sanitize( $value ) {
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	public static function register_rest_field() {
		register_rest_field( 'event', 'blt_event', array(
			'get_callback' => function ( $post ) {
				return self::summary( (int) $post['id'] );
			},
			'schema'       => array(
				'description' => __( 'Formatted event data.', 'blt-events' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit', 'embed' ),
				'readonly'    => true,
			),
		) );
	}

	/**
	 * Ready-to-display event data.
	 *
	 * @param int $event_id The event post ID.
	 * @return array
	 */
	public static function summary( $event_id ) {
		$start = BLT_Events_Helpers::event_start( $event_id );
		$end   = BLT_Events_Helpers::event_end( $event_id );
		$range = BLT_Events_Helpers::ticket_price_range( $event_id );

		$tickets = array();
		foreach ( BLT_Events_Helpers::get_ticket_types( $event_id ) as $index => $ticket ) {
			$tickets[] = array(
				'index'       => (int) $index,
				'name'        => $ticket['name'] ?? '',
				'price'       => (float) ( $ticket['price'] ?? 0 ),
				'description' => $ticket['description'] ?? '',
				'available'   => BLT_Events_Helpers::ticket_is_available( $ticket ),
			);
		}

		$data = array(
			'start'             => $start ? $start->format( DATE_ATOM ) : null,
			'end'               => $end ? $end->format( DATE_ATOM ) : null,
			'all_day'           => BLT_Events_Helpers::event_is_all_day( $event_id ),
			'date_label'        => BLT_Events_Helpers::event_date_label( $event_id ),
			'time_label'        => BLT_Events_Helpers::event_time_label( $event_id ),
			'type'              => get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person',
			'location'          => BLT_Events_Helpers::get_event_location_string( $event_id ),
			'address'           => BLT_Events_Helpers::get_event_address( $event_id ),
			'permalink'         => get_permalink( $event_id ),
			'registration_open' => get_post_meta( $event_id, '_blt_registration_open', true ) === '1' && ! BLT_Events_Helpers::registration_cutoff_passed( $event_id ),
			'sold_out'          => BLT_Events_Helpers::is_sold_out( $event_id ),
			'spots_left'        => BLT_Events_Helpers::spots_left( $event_id ),
			'capacity'          => (int) get_post_meta( $event_id, '_blt_capacity', true ),
			'featured'          => get_post_meta( $event_id, '_blt_featured', true ) === '1',
			'price_from'        => $range['has_paid'] ? $range['min'] : 0,
			'price_label'       => class_exists( 'BLT_Events_Calendar_Shortcode' ) ? BLT_Events_Calendar_Shortcode::price_label( $event_id ) : '',
			'is_free'           => ! $range['has_paid'],
			'tickets'           => $tickets,
			'ics_url'           => BLT_Events_Helpers::get_ics_url( $event_id ),
			'categories'        => wp_get_post_terms( $event_id, 'event_category', array( 'fields' => 'names' ) ),
		);

		/**
		 * Filter the computed `blt_event` REST field.
		 *
		 * @param array $data     Field data.
		 * @param int   $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_rest_summary', $data, $event_id );
	}
}
