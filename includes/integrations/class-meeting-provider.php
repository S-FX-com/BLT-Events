<?php
/**
 * BLT Events - Abstract Meeting Provider
 *
 * Base class for online-meeting integrations (Zoom, Microsoft Teams,
 * GoTo, ClickMeeting). A provider knows how to authenticate with its
 * platform, auto-create a meeting/webinar room for an event, and — where
 * the platform supports it — cross-register an attendee into that room.
 *
 * Providers are stateless value objects: credentials and tokens live in
 * WordPress options/transients, not on the instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BLT_Events_Meeting_Provider {

	/**
	 * Machine slug, e.g. 'zoom'. Used in option names, meta, and URLs.
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Human-readable name, e.g. 'Zoom'.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Authentication style, one of: 'credentials' (server-side keys),
	 * 'oauth' (authorization-code redirect flow), 'api_key'.
	 *
	 * @return string
	 */
	abstract public function auth_type();

	/**
	 * Credential option fields shown on the settings screen.
	 *
	 * Each entry: array(
	 *   'key'         => option name (string),
	 *   'label'       => field label (string),
	 *   'secret'      => bool (render as password, never echo stored value),
	 *   'placeholder' => string (optional),
	 *   'description' => string (optional),
	 * )
	 *
	 * @return array
	 */
	public function credential_fields() {
		return array();
	}

	/**
	 * Whether the provider has the minimum credentials entered.
	 *
	 * @return bool
	 */
	abstract public function is_configured();

	/**
	 * Whether the provider is actually usable right now. For OAuth providers
	 * this means a stored refresh token exists in addition to credentials.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->is_configured();
	}

	/**
	 * Whether attendees can be cross-registered into rooms on this platform.
	 *
	 * @return bool
	 */
	public function supports_registration() {
		return false;
	}

	/**
	 * Whether the platform distinguishes webinars from meetings, so the event
	 * editor can offer a room-type choice.
	 *
	 * @return bool
	 */
	public function supports_webinars() {
		return false;
	}

	/**
	 * Create a room for an event.
	 *
	 * @param int   $event_id The event post ID.
	 * @param array $args     Normalized room args: topic, agenda, start_utc
	 *                        (DateTimeImmutable in UTC or null), duration_min
	 *                        (int), timezone (valid IANA string), type
	 *                        ('meeting'|'webinar').
	 * @return array|WP_Error On success: array with keys id (string),
	 *                        join_url (string), type (string), and optionally
	 *                        registration_id. WP_Error on failure.
	 */
	abstract public function create_room( $event_id, array $args );

	/**
	 * Cross-register an attendee into a previously created room.
	 *
	 * @param array $room   The stored room record (id, type, join_url, …).
	 * @param array $person Attendee: first_name, last_name, email.
	 * @return array|true|WP_Error
	 */
	public function register_attendee( array $room, array $person ) {
		return new WP_Error( 'unsupported', __( 'This provider does not support attendee registration.', 'blt-events' ) );
	}

	/* --------------------------------------------------------------------
	 * OAuth (authorization-code) helpers — overridden by OAuth providers.
	 * ------------------------------------------------------------------ */

	/**
	 * @return bool
	 */
	public function is_oauth() {
		return $this->auth_type() === 'oauth';
	}

	/**
	 * Build the provider authorization URL to redirect the admin to.
	 *
	 * @param string $state Opaque CSRF/state token.
	 * @return string
	 */
	public function get_authorize_url( $state ) {
		return '';
	}

	/**
	 * Handle the OAuth redirect back: exchange the code for tokens and
	 * persist them.
	 *
	 * @param string $code Authorization code from the provider.
	 * @return true|WP_Error
	 */
	public function handle_oauth_callback( $code ) {
		return new WP_Error( 'unsupported', __( 'This provider does not use OAuth.', 'blt-events' ) );
	}

	/**
	 * Forget stored tokens / disconnect the provider.
	 */
	public function disconnect() {}

	/* --------------------------------------------------------------------
	 * Shared utilities.
	 * ------------------------------------------------------------------ */

	/**
	 * The stable OAuth redirect URI for this provider (what the admin must
	 * register with the platform). Routed through admin-post.php.
	 *
	 * @return string
	 */
	public function callback_url() {
		return add_query_arg(
			array(
				'action'   => 'blt_events_meeting_callback',
				'provider' => $this->slug(),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Perform an HTTP request and return the decoded JSON body, or a WP_Error
	 * carrying the provider's error message on a >=400 response.
	 *
	 * @param string $url
	 * @param array  $args wp_remote_request args (method, headers, body, …).
	 * @return array|WP_Error
	 */
	protected function http( $url, array $args ) {
		$args = wp_parse_args( $args, array( 'timeout' => 30 ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( $code >= 400 ) {
			return new WP_Error(
				$this->slug() . '_http_error',
				$this->extract_error_message( $body, $code ),
				array( 'status' => $code, 'body' => $body )
			);
		}

		// Some endpoints (e.g. 204 No Content) legitimately return no body.
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Best-effort extraction of a human-readable error message from a decoded
	 * error body across the various provider shapes.
	 *
	 * @param mixed $body
	 * @param int   $code
	 * @return string
	 */
	protected function extract_error_message( $body, $code ) {
		if ( is_array( $body ) ) {
			foreach ( array( 'message', 'error_description', 'errorMessage', 'description' ) as $key ) {
				if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
					return $body[ $key ];
				}
			}
			if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
				return $body['error']['message'];
			}
			if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
				return $body['error'];
			}
		}

		/* translators: %1$s: provider name, %2$d: HTTP status code. */
		return sprintf( __( '%1$s returned an error (HTTP %2$d).', 'blt-events' ), $this->name(), (int) $code );
	}

	/**
	 * Read a stored option scoped to this integration.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function get_option( $key, $default = '' ) {
		return get_option( 'blt_events_' . $key, $default );
	}
}
