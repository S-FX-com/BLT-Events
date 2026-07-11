<?php
/**
 * BLT Events - GoTo Integration (GoToWebinar / GoToMeeting)
 *
 * Creates GoToWebinar webinars or GoToMeeting meetings for online events.
 * Uses OAuth 2.0 authorization-code: the admin registers an OAuth client on
 * the GoTo Developer Center, connects once, and the plugin stores a refresh
 * token. The attendee join/registration link is returned; attendee
 * cross-registration is not performed on this path.
 *
 * @see https://developer.goto.com/guides/Authentication/03_HOW_accessToken
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_GoTo_Integration extends BLT_Events_Meeting_Provider {

	const AUTH_URL    = 'https://authentication.logmeininc.com/oauth/authorize';
	const TOKEN_URL   = 'https://authentication.logmeininc.com/oauth/token';
	const API_BASE    = 'https://api.getgo.com';
	const TOKEN_TRANSIENT = 'blt_events_goto_access_token';

	public function slug() {
		return 'goto';
	}

	public function name() {
		return 'GoTo';
	}

	public function auth_type() {
		return 'oauth';
	}

	public function supports_webinars() {
		return true;
	}

	public function credential_fields() {
		return array(
			array(
				'key'    => 'goto_client_id',
				'label'  => __( 'Client ID', 'blt-events' ),
				'secret' => false,
			),
			array(
				'key'    => 'goto_client_secret',
				'label'  => __( 'Client Secret', 'blt-events' ),
				'secret' => true,
			),
		);
	}

	public function is_configured() {
		return $this->get_option( 'goto_client_id' ) && $this->get_option( 'goto_client_secret' );
	}

	public function is_connected() {
		return $this->is_configured() && $this->get_option( 'goto_refresh_token' );
	}

	/* ------------------------------ OAuth ------------------------------ */

	public function get_authorize_url( $state ) {
		return add_query_arg(
			array(
				'client_id'     => rawurlencode( $this->get_option( 'goto_client_id' ) ),
				'response_type' => 'code',
				'redirect_uri'  => rawurlencode( $this->callback_url() ),
				'state'         => rawurlencode( $state ),
			),
			self::AUTH_URL
		);
	}

	public function handle_oauth_callback( $code ) {
		$body = $this->token_request( array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $this->callback_url(),
		) );

		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['refresh_token'] ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'goto_no_token', __( 'GoTo did not return the expected tokens.', 'blt-events' ) );
		}

		update_option( 'blt_events_goto_refresh_token', $body['refresh_token'], false );
		$this->cache_access_token( $body );

		$key = $this->fetch_organizer_key( $body['access_token'] );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		update_option( 'blt_events_goto_organizer_key', $key, false );

		return true;
	}

	public function disconnect() {
		delete_transient( self::TOKEN_TRANSIENT );
		delete_option( 'blt_events_goto_refresh_token' );
		delete_option( 'blt_events_goto_organizer_key' );
	}

	/**
	 * POST the token endpoint with HTTP Basic auth and a form-encoded body.
	 *
	 * @param array $params
	 * @return array|WP_Error
	 */
	protected function token_request( array $params ) {
		$client_id = $this->get_option( 'goto_client_id' );
		$secret    = $this->get_option( 'goto_client_secret' );

		return $this->http( self::TOKEN_URL, array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			),
			'body'    => $params,
		) );
	}

	protected function cache_access_token( array $body ) {
		if ( empty( $body['access_token'] ) ) {
			return;
		}
		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

		// GoTo may issue a rotated refresh token.
		if ( ! empty( $body['refresh_token'] ) ) {
			update_option( 'blt_events_goto_refresh_token', $body['refresh_token'], false );
		}
	}

	/**
	 * @return string|WP_Error
	 */
	protected function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$refresh = $this->get_option( 'goto_refresh_token' );
		if ( ! $refresh ) {
			return new WP_Error( 'goto_not_connected', __( 'GoTo is not connected.', 'blt-events' ) );
		}

		$body = $this->token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
		) );

		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'goto_no_token', __( 'GoTo did not return an access token. Try reconnecting.', 'blt-events' ) );
		}

		$this->cache_access_token( $body );

		return $body['access_token'];
	}

	/**
	 * Resolve the organizer key for the connected user (SCIM id).
	 *
	 * @param string $token
	 * @return string|WP_Error
	 */
	protected function fetch_organizer_key( $token ) {
		$me = $this->http( self::API_BASE . '/identity/v1/Users/me', array(
			'method'  => 'GET',
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $me ) ) {
			return $me;
		}
		if ( empty( $me['id'] ) ) {
			return new WP_Error( 'goto_no_organizer', __( 'Could not determine the GoTo organizer key.', 'blt-events' ) );
		}

		return (string) $me['id'];
	}

	/* --------------------------- Room creation --------------------------- */

	public function create_room( $event_id, array $args ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return ( $args['type'] ?? 'meeting' ) === 'webinar'
			? $this->create_webinar( $token, $args )
			: $this->create_meeting( $token, $args );
	}

	protected function create_webinar( $token, array $args ) {
		$organizer = $this->get_option( 'goto_organizer_key' );
		if ( ! $organizer ) {
			return new WP_Error( 'goto_no_organizer', __( 'Missing GoTo organizer key. Please reconnect GoTo.', 'blt-events' ) );
		}

		list( $start, $end ) = $this->window( $args );
		if ( ! $start ) {
			return new WP_Error( 'goto_no_time', __( 'A start date and time are required to create a GoTo webinar.', 'blt-events' ) );
		}

		$payload = array(
			'subject'     => $this->clip( $args['topic'], 128 ) ?: __( 'Event', 'blt-events' ),
			'description' => $this->clip( $args['agenda'] ?? '', 2048 ),
			'times'       => array( array( 'startTime' => $start, 'endTime' => $end ) ),
			'timeZone'    => $args['timezone'] ?? 'UTC',
			'type'        => 'single_session',
		);

		$body = $this->http(
			self::API_BASE . '/G2W/rest/v2/organizers/' . rawurlencode( $organizer ) . '/webinars',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$key = (string) ( $body['webinarKey'] ?? '' );
		if ( ! $key ) {
			return new WP_Error( 'goto_no_key', __( 'GoTo did not return a webinar key.', 'blt-events' ) );
		}

		return array(
			'id'       => $key,
			'join_url' => 'https://attendee.gotowebinar.com/register/' . rawurlencode( $key ),
			'type'     => 'webinar',
		);
	}

	protected function create_meeting( $token, array $args ) {
		list( $start, $end ) = $this->window( $args );
		if ( ! $start ) {
			return new WP_Error( 'goto_no_time', __( 'A start date and time are required to create a GoTo meeting.', 'blt-events' ) );
		}

		$payload = array(
			'subject'          => $this->clip( $args['topic'], 100 ) ?: __( 'Event', 'blt-events' ),
			'starttime'        => $start,
			'endtime'          => $end,
			'passwordrequired' => false,
			'conferencecallinfo' => 'Hybrid',
			'timezonekey'      => '',
			'meetingtype'      => 'scheduled',
		);

		$body = $this->http( self::API_BASE . '/G2M/rest/v2/meetings', array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// GoToMeeting returns a single-element JSON array.
		$meeting = isset( $body[0] ) && is_array( $body[0] ) ? $body[0] : $body;

		return array(
			'id'       => (string) ( $meeting['meetingid'] ?? '' ),
			'join_url' => (string) ( $meeting['joinURL'] ?? '' ),
			'type'     => 'meeting',
		);
	}

	/**
	 * Return [startISO, endISO] in UTC (Z), or [null, null] when no start time.
	 *
	 * @param array $args
	 * @return array
	 */
	protected function window( array $args ) {
		if ( empty( $args['start_utc'] ) || ! $args['start_utc'] instanceof DateTimeInterface ) {
			return array( null, null );
		}
		$start = $args['start_utc'];
		$end   = $start->add( new DateInterval( 'PT' . max( 1, (int) ( $args['duration_min'] ?? 60 ) ) . 'M' ) );

		return array(
			$start->format( 'Y-m-d\TH:i:s\Z' ),
			$end->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	protected function clip( $value, $max ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
