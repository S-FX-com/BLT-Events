<?php
/**
 * BLT Events - Microsoft Teams Integration
 *
 * Creates Teams meetings for online events via Microsoft Graph using an
 * app-only (client credentials) Entra ID application. The join link is
 * returned; attendee cross-registration is not supported on this path.
 *
 * @see https://learn.microsoft.com/en-us/graph/api/application-post-onlinemeetings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Teams_Integration extends BLT_Events_Meeting_Provider {

	const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
	const TOKEN_TRANSIENT = 'blt_events_teams_access_token';

	public function slug() {
		return 'teams';
	}

	public function name() {
		return 'Microsoft Teams';
	}

	public function auth_type() {
		return 'credentials';
	}

	public function credential_fields() {
		return array(
			array(
				'key'    => 'teams_tenant_id',
				'label'  => __( 'Directory (tenant) ID', 'blt-events' ),
				'secret' => false,
			),
			array(
				'key'    => 'teams_client_id',
				'label'  => __( 'Application (client) ID', 'blt-events' ),
				'secret' => false,
			),
			array(
				'key'    => 'teams_client_secret',
				'label'  => __( 'Client Secret', 'blt-events' ),
				'secret' => true,
			),
			array(
				'key'         => 'teams_organizer',
				'label'       => __( 'Organizer User ID', 'blt-events' ),
				'secret'      => false,
				'placeholder' => '00000000-0000-0000-0000-000000000000',
				'description' => __( 'Entra object ID (or UPN) of the licensed user who will host meetings. The app needs OnlineMeetings.ReadWrite.All plus a Teams application access policy granting access to this user.', 'blt-events' ),
			),
		);
	}

	public function is_configured() {
		return $this->get_option( 'teams_tenant_id' )
			&& $this->get_option( 'teams_client_id' )
			&& $this->get_option( 'teams_client_secret' )
			&& $this->get_option( 'teams_organizer' );
	}

	/**
	 * @return string|WP_Error
	 */
	protected function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$tenant = rawurlencode( $this->get_option( 'teams_tenant_id' ) );

		$body = $this->http(
			'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
			array(
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->get_option( 'teams_client_id' ),
					'client_secret' => $this->get_option( 'teams_client_secret' ),
					'scope'         => 'https://graph.microsoft.com/.default',
				),
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'teams_no_token', __( 'Microsoft did not return an access token. Check the tenant ID, client ID and secret.', 'blt-events' ) );
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

		$payload = array(
			'subject' => $args['topic'] ?: __( 'Event', 'blt-events' ),
		);

		if ( ! empty( $args['start_utc'] ) && $args['start_utc'] instanceof DateTimeInterface ) {
			$start = $args['start_utc'];
			$end   = $start->add( new DateInterval( 'PT' . max( 1, (int) ( $args['duration_min'] ?? 60 ) ) . 'M' ) );
			$payload['startDateTime'] = $start->format( 'Y-m-d\TH:i:s\Z' );
			$payload['endDateTime']   = $end->format( 'Y-m-d\TH:i:s\Z' );
		}

		$organizer = rawurlencode( $this->get_option( 'teams_organizer' ) );

		$body = $this->http(
			self::GRAPH_BASE . '/users/' . $organizer . '/onlineMeetings',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return array(
			'id'       => (string) ( $body['id'] ?? '' ),
			'join_url' => (string) ( $body['joinWebUrl'] ?? '' ),
			'type'     => 'meeting',
		);
	}

	public function disconnect() {
		delete_transient( self::TOKEN_TRANSIENT );
		foreach ( array( 'teams_tenant_id', 'teams_client_id', 'teams_client_secret', 'teams_organizer' ) as $opt ) {
			delete_option( 'blt_events_' . $opt );
		}
	}
}
