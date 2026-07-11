<?php
/**
 * BLT Events - Zoom Integration
 *
 * Creates Zoom meetings and webinars for online events using a
 * Server-to-Server OAuth app (Account ID + Client ID + Client Secret).
 * No per-user redirect flow is required.
 *
 * @see https://developers.zoom.us/docs/internal-apps/s2s-oauth/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Zoom_Integration extends BLT_Events_Meeting_Provider {

	const API_BASE   = 'https://api.zoom.us/v2';
	const TOKEN_URL  = 'https://zoom.us/oauth/token';
	const TOKEN_TRANSIENT = 'blt_events_zoom_access_token';

	public function slug() {
		return 'zoom';
	}

	public function name() {
		return 'Zoom';
	}

	public function auth_type() {
		return 'credentials';
	}

	public function supports_webinars() {
		return true;
	}

	public function credential_fields() {
		return array(
			array(
				'key'         => 'zoom_account_id',
				'label'       => __( 'Account ID', 'blt-events' ),
				'secret'      => false,
				'description' => __( 'From your Zoom Server-to-Server OAuth app.', 'blt-events' ),
			),
			array(
				'key'    => 'zoom_client_id',
				'label'  => __( 'Client ID', 'blt-events' ),
				'secret' => false,
			),
			array(
				'key'    => 'zoom_client_secret',
				'label'  => __( 'Client Secret', 'blt-events' ),
				'secret' => true,
			),
			array(
				'key'         => 'zoom_host',
				'label'       => __( 'Host User', 'blt-events' ),
				'secret'      => false,
				'placeholder' => 'you@example.com',
				'description' => __( 'The Zoom user (email or user ID) who will host created meetings. Must be a Licensed user; webinars require the Webinar add-on.', 'blt-events' ),
			),
		);
	}

	public function is_configured() {
		return $this->get_option( 'zoom_account_id' )
			&& $this->get_option( 'zoom_client_id' )
			&& $this->get_option( 'zoom_client_secret' )
			&& $this->get_option( 'zoom_host' );
	}

	/**
	 * Fetch (and cache) a Server-to-Server OAuth access token.
	 *
	 * @return string|WP_Error
	 */
	protected function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$account_id = $this->get_option( 'zoom_account_id' );
		$client_id  = $this->get_option( 'zoom_client_id' );
		$secret     = $this->get_option( 'zoom_client_secret' );

		$body = $this->http(
			add_query_arg(
				array( 'grant_type' => 'account_credentials', 'account_id' => $account_id ),
				self::TOKEN_URL
			),
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'zoom_no_token', __( 'Zoom did not return an access token. Check the Account ID, Client ID and Secret.', 'blt-events' ) );
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	public function create_room( $event_id, array $args ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$is_webinar = ( $args['type'] ?? 'meeting' ) === 'webinar';
		$host       = rawurlencode( $this->get_option( 'zoom_host' ) );
		$endpoint   = self::API_BASE . '/users/' . $host . ( $is_webinar ? '/webinars' : '/meetings' );

		$payload = array(
			'topic'    => $this->clip( $args['topic'], 200 ) ?: __( 'Event', 'blt-events' ),
			'type'     => $is_webinar ? 5 : 2, // scheduled
			'duration' => (int) ( $args['duration_min'] ?? 60 ),
			'agenda'   => $this->clip( $args['agenda'] ?? '', 2000 ),
		);

		// Zoom reads start_time as GMT when it carries a trailing Z.
		if ( ! empty( $args['start_utc'] ) && $args['start_utc'] instanceof DateTimeInterface ) {
			$payload['start_time'] = $args['start_utc']->format( 'Y-m-d\TH:i:s\Z' );
		}

		$body = $this->http( $endpoint, array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return array(
			'id'       => (string) ( $body['id'] ?? '' ),
			'join_url' => (string) ( $body['join_url'] ?? '' ),
			'type'     => $is_webinar ? 'webinar' : 'meeting',
		);
	}

	/**
	 * @param string $value
	 * @param int    $max
	 * @return string
	 */
	protected function clip( $value, $max ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	public function disconnect() {
		delete_transient( self::TOKEN_TRANSIENT );
		foreach ( array( 'zoom_account_id', 'zoom_client_id', 'zoom_client_secret', 'zoom_host' ) as $opt ) {
			delete_option( 'blt_events_' . $opt );
		}
	}
}
