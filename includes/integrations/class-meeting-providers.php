<?php
/**
 * BLT Events - Meeting Providers Manager
 *
 * Registry and dispatcher for online-meeting integrations. Owns the OAuth
 * connect/callback/disconnect routes, auto-creates a room when an online
 * (or hybrid) event is saved, and cross-registers attendees into the room
 * for providers that support it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Meeting_Providers {

	/**
	 * @var BLT_Events_Meeting_Provider[]|null Cached provider instances keyed by slug.
	 */
	protected static $providers = null;

	public static function init() {
		// OAuth routes (admin-post, logged-in admins only).
		add_action( 'admin_post_blt_events_meeting_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_blt_events_meeting_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_blt_events_meeting_disconnect', array( __CLASS__, 'handle_disconnect' ) );

		// Cross-register attendees once a registration is confirmed.
		add_action( 'blt_registration_created', array( __CLASS__, 'sync_on_created' ), 30, 2 );
		add_action( 'blt_registration_confirmed', array( __CLASS__, 'sync_on_confirmed' ), 30, 1 );

		// Surface room-creation problems to the admin.
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
	}

	/**
	 * All registered provider instances, keyed by slug.
	 *
	 * @return BLT_Events_Meeting_Provider[]
	 */
	public static function all() {
		if ( self::$providers === null ) {
			$classes = array(
				'BLT_Events_Zoom_Integration',
				'BLT_Events_Teams_Integration',
				'BLT_Events_GoTo_Integration',
				'BLT_Events_ClickMeeting_Integration',
			);

			self::$providers = array();
			foreach ( $classes as $class ) {
				if ( class_exists( $class ) ) {
					$provider = new $class();
					self::$providers[ $provider->slug() ] = $provider;
				}
			}
		}

		return self::$providers;
	}

	/**
	 * @param string $slug
	 * @return BLT_Events_Meeting_Provider|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Providers that are connected and ready to create rooms.
	 *
	 * @return BLT_Events_Meeting_Provider[]
	 */
	public static function connected() {
		return array_filter( self::all(), function ( $p ) {
			return $p->is_connected();
		} );
	}

	/* --------------------------------------------------------------------
	 * OAuth connect / callback / disconnect.
	 * ------------------------------------------------------------------ */

	public static function handle_connect() {
		$slug = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';
		check_admin_referer( 'blt_events_meeting_connect_' . $slug );

		if ( ! current_user_can( BLT_Events_Helpers::menu_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-events' ) );
		}

		$provider = self::get( $slug );
		if ( ! $provider || ! $provider->is_oauth() ) {
			self::redirect_to_settings( 'error', __( 'Unknown provider.', 'blt-events' ) );
		}

		if ( ! $provider->is_configured() ) {
			self::redirect_to_settings( 'error', __( 'Enter and save the client credentials before connecting.', 'blt-events' ) );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'blt_events_oauth_state_' . $slug, $state, 15 * MINUTE_IN_SECONDS );

		$url = $provider->get_authorize_url( $state );
		if ( ! $url ) {
			self::redirect_to_settings( 'error', __( 'Could not build the authorization URL.', 'blt-events' ) );
		}

		wp_redirect( $url );
		exit;
	}

	public static function handle_callback() {
		$slug = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';

		if ( ! current_user_can( BLT_Events_Helpers::menu_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-events' ) );
		}

		$provider = self::get( $slug );
		if ( ! $provider || ! $provider->is_oauth() ) {
			self::redirect_to_settings( 'error', __( 'Unknown provider.', 'blt-events' ) );
		}

		// Provider-reported errors (user declined, etc.).
		if ( ! empty( $_GET['error'] ) ) {
			self::redirect_to_settings( 'error', sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) ) );
		}

		$expected = get_transient( 'blt_events_oauth_state_' . $slug );
		$received = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		delete_transient( 'blt_events_oauth_state_' . $slug );

		if ( ! $expected || ! hash_equals( (string) $expected, $received ) ) {
			self::redirect_to_settings( 'error', __( 'The connection request expired or could not be verified. Please try again.', 'blt-events' ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			self::redirect_to_settings( 'error', __( 'No authorization code was returned.', 'blt-events' ) );
		}

		$result = $provider->handle_oauth_callback( $code );
		if ( is_wp_error( $result ) ) {
			self::redirect_to_settings( 'error', $result->get_error_message() );
		}

		/* translators: %s: provider name. */
		self::redirect_to_settings( 'success', sprintf( __( 'Connected to %s.', 'blt-events' ), $provider->name() ) );
	}

	public static function handle_disconnect() {
		$slug = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';
		check_admin_referer( 'blt_events_meeting_disconnect_' . $slug );

		if ( ! current_user_can( BLT_Events_Helpers::menu_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-events' ) );
		}

		$provider = self::get( $slug );
		if ( $provider ) {
			$provider->disconnect();
			/* translators: %s: provider name. */
			self::redirect_to_settings( 'success', sprintf( __( 'Disconnected from %s.', 'blt-events' ), $provider->name() ) );
		}

		self::redirect_to_settings( 'error', __( 'Unknown provider.', 'blt-events' ) );
	}

	protected static function redirect_to_settings( $status, $message ) {
		set_transient( 'blt_events_meeting_notice_' . get_current_user_id(), array( 'status' => $status, 'message' => $message ), 60 );
		wp_redirect( admin_url( 'edit.php?post_type=event&page=blt-events-settings&tab=integrations' ) );
		exit;
	}

	public static function render_admin_notices() {
		$notice = get_transient( 'blt_events_meeting_notice_' . get_current_user_id() );
		if ( ! $notice || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( 'blt_events_meeting_notice_' . get_current_user_id() );

		$class = $notice['status'] === 'success' ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/* --------------------------------------------------------------------
	 * Room creation on event save.
	 * ------------------------------------------------------------------ */

	/**
	 * Called from the event metabox save. Creates (or leaves alone) the online
	 * meeting room for an event based on the submitted meeting fields, and
	 * returns the join URL when a usable room exists.
	 *
	 * @param int   $event_id
	 * @param array $post   The (unslashed) submitted meeting fields.
	 * @param string $event_type Saved event type.
	 * @return string|null Join URL to store as the event online URL, or null.
	 */
	public static function maybe_create_room_on_save( $event_id, array $post, $event_type ) {
		$is_online = in_array( $event_type, array( 'online', 'hybrid' ), true );

		$enabled = $is_online && ! empty( $post['meeting_auto_create'] );
		update_post_meta( $event_id, '_blt_meeting_auto', $enabled ? '1' : '0' );

		if ( ! $enabled ) {
			return null;
		}

		$slug     = sanitize_key( $post['meeting_provider'] ?? '' );
		$provider = self::get( $slug );
		if ( ! $provider || ! $provider->is_connected() ) {
			self::store_notice( sprintf(
				/* translators: %s: provider slug. */
				__( 'Could not create the online meeting room: the selected provider (%s) is not connected.', 'blt-events' ),
				$slug ?: '—'
			) );
			return null;
		}

		$type = 'meeting';
		if ( $provider->supports_webinars() && ( $post['meeting_type'] ?? '' ) === 'webinar' ) {
			$type = 'webinar';
		}

		update_post_meta( $event_id, '_blt_meeting_provider', $slug );
		update_post_meta( $event_id, '_blt_meeting_type', $type );

		// Reuse the existing room unless the provider/type changed or the admin
		// asked to recreate it, so re-saving an event does not spawn duplicates.
		$existing = self::get_room( $event_id );
		$recreate = ! empty( $post['meeting_recreate'] );

		if ( $existing && ! $recreate && ( $existing['provider'] ?? '' ) === $slug && ( $existing['type'] ?? '' ) === $type ) {
			return $existing['join_url'] ?? null;
		}

		$args   = self::build_room_args( $event_id, $type );
		$result = $provider->create_room( $event_id, $args );

		if ( is_wp_error( $result ) ) {
			self::store_notice( sprintf(
				/* translators: 1: provider name, 2: error message. */
				__( 'Could not create the %1$s room: %2$s', 'blt-events' ),
				$provider->name(),
				$result->get_error_message()
			) );
			return $existing['join_url'] ?? null;
		}

		$room = array(
			'provider'   => $slug,
			'type'       => $type,
			'id'         => (string) ( $result['id'] ?? '' ),
			'join_url'   => (string) ( $result['join_url'] ?? '' ),
			'created_at' => current_time( 'mysql' ),
		);
		update_post_meta( $event_id, '_blt_meeting_room', wp_json_encode( $room ) );

		return $room['join_url'] ?: null;
	}

	/**
	 * Build the normalized room args from an event's stored date/time meta.
	 *
	 * @param int    $event_id
	 * @param string $type
	 * @return array
	 */
	protected static function build_room_args( $event_id, $type ) {
		$prefix     = BLT_EVENTS_PREFIX;
		$date       = get_post_meta( $event_id, $prefix . 'event_date', true );
		$start_time = get_post_meta( $event_id, $prefix . 'event_start_time', true );
		$end_time   = get_post_meta( $event_id, $prefix . 'event_end_time', true );
		$all_day    = get_post_meta( $event_id, $prefix . 'event_all_day', true ) === '1';

		$tz      = wp_timezone();
		$tz_name = $tz->getName();
		// APIs that require IANA names reject fixed offsets like "+05:00".
		$iana = strpos( $tz_name, '/' ) !== false ? $tz_name : 'UTC';

		$start_utc    = null;
		$duration_min = 60;

		if ( $date ) {
			$clock = ( ! $all_day && $start_time ) ? $start_time : '09:00';
			try {
				$start = new DateTimeImmutable( $date . ' ' . $clock, $tz );
				$start_utc = $start->setTimezone( new DateTimeZone( 'UTC' ) );

				if ( ! $all_day && $start_time && $end_time ) {
					$end  = new DateTimeImmutable( $date . ' ' . $end_time, $tz );
					$diff = ( $end->getTimestamp() - $start->getTimestamp() ) / 60;
					if ( $diff > 0 ) {
						$duration_min = (int) round( $diff );
					}
				}
			} catch ( Exception $e ) {
				$start_utc = null;
			}
		}

		return array(
			'topic'        => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES ),
			'agenda'       => wp_strip_all_tags( get_post_field( 'post_excerpt', $event_id ) ),
			'start_utc'    => $start_utc,
			'duration_min' => $duration_min,
			'timezone'     => $iana,
			'type'         => $type,
		);
	}

	/**
	 * @param int $event_id
	 * @return array|null Decoded room record.
	 */
	public static function get_room( $event_id ) {
		$raw  = get_post_meta( $event_id, '_blt_meeting_room', true );
		$room = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $room ) ? $room : null;
	}

	protected static function store_notice( $message ) {
		set_transient( 'blt_events_meeting_notice_' . get_current_user_id(), array( 'status' => 'error', 'message' => $message ), 60 );
	}

	/* --------------------------------------------------------------------
	 * Attendee cross-registration.
	 * ------------------------------------------------------------------ */

	public static function sync_on_created( $registration_id, $result ) {
		if ( ( $result['status'] ?? '' ) === 'confirmed' ) {
			self::cross_register( $registration_id );
		}
	}

	public static function sync_on_confirmed( $registration_id ) {
		self::cross_register( $registration_id );
	}

	/**
	 * Register every attendee on a confirmed registration into the event's
	 * online meeting room, for providers that support it.
	 *
	 * @param int $registration_id
	 */
	public static function cross_register( $registration_id ) {
		$reg_db = new BLT_Events_Registrations_DB();
		$reg    = $reg_db->get( $registration_id );
		if ( ! $reg || $reg->status !== 'confirmed' ) {
			return;
		}

		$custom = json_decode( $reg->custom_fields, true );
		$custom = is_array( $custom ) ? $custom : array();
		if ( ! empty( $custom['_blt_meeting_synced'] ) ) {
			return; // Already pushed.
		}

		$room = self::get_room( $reg->event_id );
		if ( ! $room || empty( $room['id'] ) ) {
			return;
		}

		$provider = self::get( $room['provider'] ?? '' );
		if ( ! $provider || ! $provider->is_connected() || ! $provider->supports_registration() ) {
			return;
		}

		$att_db    = new BLT_Events_Attendees_DB();
		$attendees = $att_db->get_by_registration( $registration_id );
		if ( empty( $attendees ) ) {
			$attendees = array( (object) array(
				'attendee_name'  => $reg->customer_name,
				'attendee_email' => $reg->customer_email,
			) );
		}

		$any = false;
		foreach ( $attendees as $att ) {
			$email = sanitize_email( $att->attendee_email ?? '' );
			if ( ! $email ) {
				continue;
			}
			list( $first, $last ) = self::split_name( $att->attendee_name ?? '' );

			$res = $provider->register_attendee( $room, array(
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
			) );

			if ( ! is_wp_error( $res ) ) {
				$any = true;
			}
		}

		if ( $any ) {
			$custom['_blt_meeting_synced'] = current_time( 'mysql' );
			$reg_db->update( $registration_id, array( 'custom_fields' => wp_json_encode( $custom ) ) );
		}
	}

	/**
	 * Split a full name into [first, last]. A single token yields an empty
	 * last name.
	 *
	 * @param string $name
	 * @return array
	 */
	protected static function split_name( $name ) {
		$name  = trim( (string) $name );
		if ( $name === '' ) {
			return array( 'Guest', '' );
		}
		$parts = preg_split( '/\s+/', $name, 2 );
		return array( $parts[0], $parts[1] ?? '' );
	}
}
