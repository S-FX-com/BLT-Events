<?php
/**
 * BLT Events - ClickMeeting Integration
 *
 * Creates ClickMeeting rooms (meetings or webinars) for online events and
 * cross-registers confirmed attendees into the room's registration form.
 * Authenticates with a single static API key sent as the X-Api-Key header.
 *
 * @see https://dev.clickmeeting.com/api-guide/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_ClickMeeting_Integration extends BLT_Events_Meeting_Provider {

	const API_BASE = 'https://api.clickmeeting.com/v1';

	public function slug() {
		return 'clickmeeting';
	}

	public function name() {
		return 'ClickMeeting';
	}

	public function auth_type() {
		return 'api_key';
	}

	public function supports_webinars() {
		return true;
	}

	public function supports_registration() {
		return true;
	}

	public function credential_fields() {
		return array(
			array(
				'key'         => 'clickmeeting_api_key',
				'label'       => __( 'API Key', 'blt-events' ),
				'secret'      => true,
				'description' => __( 'Generated in your ClickMeeting account under Account panel → Settings. Confirmed attendees are registered into created rooms automatically.', 'blt-events' ),
			),
		);
	}

	public function is_configured() {
		return (bool) $this->get_option( 'clickmeeting_api_key' );
	}

	/**
	 * @param string $endpoint  Path under the API base, e.g. "conferences".
	 * @param string $method
	 * @param array  $body      Form fields.
	 * @return array|WP_Error
	 */
	protected function api( $endpoint, $method = 'GET', array $body = array() ) {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'X-Api-Key' => $this->get_option( 'clickmeeting_api_key' ),
				'Accept'    => 'application/json',
			),
		);
		// Sent form-encoded (ClickMeeting expects application/x-www-form-urlencoded).
		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		}

		return $this->http( self::API_BASE . '/' . ltrim( $endpoint, '/' ), $args );
	}

	public function create_room( $event_id, array $args ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'clickmeeting_not_configured', __( 'ClickMeeting API key is not set.', 'blt-events' ) );
		}

		$body = array(
			'name'        => $this->clip( $args['topic'], 100 ) ?: __( 'Event', 'blt-events' ),
			'room_type'   => ( $args['type'] ?? 'meeting' ) === 'webinar' ? 'webinar' : 'meeting',
			'access_type' => 1, // open
			// Turn on the registration form so attendees can be cross-registered.
			'registration' => array( 'enabled' => 1 ),
		);

		if ( ! empty( $args['start_utc'] ) && $args['start_utc'] instanceof DateTimeInterface ) {
			$tz    = $this->room_timezone( $args['timezone'] ?? 'UTC' );
			$local = $args['start_utc']->setTimezone( $tz );

			$body['permanent_room'] = 0;
			$body['starts_at']      = $local->format( 'Y-m-d H:i:s' );
			$body['duration']       = $this->duration_hhmm( (int) ( $args['duration_min'] ?? 60 ) );
			$body['timezone']       = $tz->getName();
		} else {
			// No scheduled time: fall back to an always-open permanent room.
			$body['permanent_room'] = 1;
		}

		$resp = $this->api( 'conferences', 'POST', $body );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$room = $resp['room'] ?? $resp;

		return array(
			'id'       => (string) ( $room['id'] ?? '' ),
			'join_url' => (string) ( $room['room_url'] ?? '' ),
			'type'     => $body['room_type'],
		);
	}

	public function register_attendee( array $room, array $person ) {
		$room_id = $room['id'] ?? '';
		if ( ! $room_id ) {
			return new WP_Error( 'clickmeeting_no_room', __( 'No ClickMeeting room id.', 'blt-events' ) );
		}

		// Default registration field IDs: 1=first name, 2=last name, 3=email.
		$body = array(
			'registration' => array(
				1 => $person['first_name'] ?? '',
				2 => $person['last_name'] ?? '',
				3 => $person['email'] ?? '',
			),
		);

		return $this->api( 'conferences/' . rawurlencode( $room_id ) . '/registration', 'POST', $body );
	}

	public function disconnect() {
		delete_option( 'blt_events_clickmeeting_api_key' );
	}

	/**
	 * A DateTimeZone ClickMeeting will accept (IANA name, else UTC).
	 *
	 * @param string $iana
	 * @return DateTimeZone
	 */
	protected function room_timezone( $iana ) {
		try {
			return new DateTimeZone( $iana );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Minutes → "H:MM" duration string.
	 *
	 * @param int $minutes
	 * @return string
	 */
	protected function duration_hhmm( $minutes ) {
		$minutes = max( 1, $minutes );
		return sprintf( '%d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	protected function clip( $value, $max ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
