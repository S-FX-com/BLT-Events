<?php
/**
 * BLT Events Helpers
 *
 * Static utility class providing common helper functions for the BLT Events plugin.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Helpers {

	/**
	 * The custom capability for managing BLT Events admin screens/data.
	 * Granted to administrators on activation; grant it to other roles
	 * (e.g. shop managers) to give them access without manage_options.
	 */
	const MANAGE_CAP = 'manage_blt_events';

	/* ------------------------------------------------------------------
	 * Currency
	 * ---------------------------------------------------------------- */

	/**
	 * The currency code. Always USD — BLT only ever transacts in US Dollars,
	 * and this is not configurable by design.
	 *
	 * @return string
	 */
	public static function get_currency_code() {
		return 'USD';
	}

	/**
	 * The currency symbol. Always the US Dollar sign. See get_currency_code().
	 *
	 * @return string
	 */
	public static function get_currency_symbol() {
		return '$';
	}

	/**
	 * Whether prices are shown with the currency symbol. Defaults to on so a
	 * fresh install prints "$25.00" rather than a bare "25.00".
	 *
	 * @return bool
	 */
	public static function show_currency_symbol() {
		return '1' === (string) get_option( 'blt_events_display_currency_sign', '1' );
	}

	/**
	 * Whether prices are followed by the currency code ("25.00 USD").
	 *
	 * @return bool
	 */
	public static function show_currency_code() {
		return '1' === (string) get_option( 'blt_events_display_currency', '0' );
	}

	/**
	 * Format a price amount with currency symbol and/or code based on plugin settings.
	 *
	 * @param float $amount        The price amount to format.
	 * @param bool  $include_total Whether to prepend "Total: " to the output.
	 * @return string The formatted price string.
	 */
	public static function format_price( $amount, $include_total = false ) {
		// Format the number with 2 decimal places.
		$formatted_price = number_format( (float) $amount, 2 );

		$price_string = '';

		// Add "Total:" prefix if requested.
		if ( $include_total ) {
			$price_string .= __( 'Total:', 'blt-events' ) . ' ';
		}

		// Add currency symbol before price if enabled.
		if ( self::show_currency_symbol() ) {
			$price_string .= self::get_currency_symbol();
		}

		// Add the formatted price.
		$price_string .= $formatted_price;

		// Add currency code after price if enabled.
		if ( self::show_currency_code() ) {
			$price_string .= ' ' . self::get_currency_code();
		}

		/**
		 * Filter a formatted price string.
		 *
		 * @param string $price_string  The formatted price.
		 * @param float  $amount        The raw amount.
		 * @param bool   $include_total Whether the "Total:" prefix was requested.
		 */
		return apply_filters( 'blt_events_format_price', $price_string, (float) $amount, $include_total );
	}

	/**
	 * Get currency configuration array for JavaScript localization.
	 *
	 * @return array Currency settings including code, symbols, and display flags.
	 */
	public static function get_currency_config() {
		return array(
			'currency'       => self::get_currency_code(),
			'totalLabel'     => __( 'Total:', 'blt-events' ),
			'showCurrency'   => self::show_currency_code() ? '1' : '0',
			'showSymbol'     => self::show_currency_symbol() ? '1' : '0',
			'currencySymbol' => self::get_currency_symbol(),
		);
	}

	/* ------------------------------------------------------------------
	 * Identifiers, discounts, sanitizers
	 * ---------------------------------------------------------------- */

	/**
	 * Generate a UUID v4 string for use as a group identifier.
	 *
	 * @return string A UUID v4 string (e.g. "550e8400-e29b-41d4-a716-446655440000").
	 */
	public static function generate_group_id() {
		$data = random_bytes( 16 );

		// Set version to 0100 (UUID v4).
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		// Set bits 6-7 to 10 (RFC 4122 variant).
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Calculate group discount for a registration.
	 *
	 * @param float  $unit_price     The price per attendee.
	 * @param int    $quantity        The number of attendees.
	 * @param string $discount_rules  JSON-encoded discount rules object with keys:
	 *                                enabled (bool), min_attendees (int),
	 *                                type ("percentage"|"flat"), amount (float).
	 * @return array Associative array with keys: subtotal, discount, total.
	 */
	public static function calculate_group_discount( $unit_price, $quantity, $discount_rules ) {
		$subtotal = (float) $unit_price * (int) $quantity;
		$discount = 0.0;

		$rules = is_string( $discount_rules ) ? json_decode( $discount_rules, true ) : $discount_rules;

		if (
			is_array( $rules )
			&& ! empty( $rules['enabled'] )
			&& (int) $quantity >= (int) $rules['min_attendees']
		) {
			$rule_amount = isset( $rules['amount'] ) ? (float) $rules['amount'] : 0.0;
			$rule_type   = isset( $rules['type'] ) ? $rules['type'] : '';

			if ( $rule_type === 'percentage' ) {
				$discount = $subtotal * ( $rule_amount / 100 );
			} elseif ( $rule_type === 'flat' ) {
				$discount = $rule_amount;
			}

			// Discount cannot exceed subtotal.
			$discount = min( $discount, $subtotal );
		}

		$total = $subtotal - $discount;

		return array(
			'subtotal' => round( $subtotal, 2 ),
			'discount' => round( $discount, 2 ),
			'total'    => round( $total, 2 ),
		);
	}

	/**
	 * Sanitize a phone number, stripping all non-numeric characters except a leading +.
	 *
	 * @param string $phone The raw phone number input.
	 * @return string The sanitized phone number.
	 */
	public static function sanitize_phone( $phone ) {
		$phone = trim( (string) $phone );

		if ( empty( $phone ) ) {
			return '';
		}

		$has_plus = ( substr( $phone, 0, 1 ) === '+' );
		$cleaned  = preg_replace( '/[^0-9]/', '', $phone );

		if ( $has_plus ) {
			$cleaned = '+' . $cleaned;
		}

		return $cleaned;
	}

	/**
	 * The visitor's IP address, proxy-aware.
	 *
	 * Behind Cloudflare or a reverse proxy REMOTE_ADDR is the proxy, so every
	 * visitor would share one rate-limit bucket and the whole site would be
	 * throttled after a handful of registrations. Forwarding headers are
	 * consulted first, in order, and the first syntactically valid address
	 * wins.
	 *
	 * @return string IP address, or '' when none is available.
	 */
	public static function client_ip() {
		/**
		 * Filter the request headers trusted to carry the real client IP, in
		 * order of preference. Return an empty array to use REMOTE_ADDR only.
		 *
		 * @param string[] $headers $_SERVER keys.
		 */
		$headers = apply_filters( 'blt_events_trusted_ip_headers', array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
		) );

		foreach ( (array) $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			$first = trim( explode( ',', $raw )[0] );

			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/* ------------------------------------------------------------------
	 * Registration statuses
	 * ---------------------------------------------------------------- */

	/**
	 * Every registration status the plugin knows, slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function registration_statuses() {
		/**
		 * Filter the registration statuses.
		 *
		 * Add-ons may add their own; the four built-in ones cannot be removed
		 * because core flows write them.
		 *
		 * @param array $statuses Slug => label.
		 */
		$statuses = apply_filters( 'blt_events_registration_statuses', array(
			'pending'    => __( 'Pending', 'blt-events' ),
			'confirmed'  => __( 'Confirmed', 'blt-events' ),
			'cancelled'  => __( 'Cancelled', 'blt-events' ),
			'refunded'   => __( 'Refunded', 'blt-events' ),
		) );

		return is_array( $statuses ) ? $statuses : array();
	}

	/**
	 * Human label for a status slug.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {
		$statuses = self::registration_statuses();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * Statuses that hold a seat. Cancelled and refunded registrations do not
	 * count towards capacity.
	 *
	 * @return string[]
	 */
	public static function seat_holding_statuses() {
		/**
		 * Filter which statuses occupy capacity.
		 *
		 * @param string[] $statuses
		 */
		return (array) apply_filters( 'blt_events_seat_holding_statuses', array( 'pending', 'confirmed' ) );
	}

	/* ------------------------------------------------------------------
	 * Dates and times (timezone-aware)
	 * ---------------------------------------------------------------- */

	/**
	 * Build a DateTimeImmutable in the site's timezone from the Y-m-d and
	 * H:i strings the plugin stores.
	 *
	 * WordPress runs PHP in UTC, so strtotime( '2026-05-14 14:00' ) would
	 * silently be read as 14:00 UTC. Everything that has to be exact
	 * (calendar invites, structured data, reminders) goes through here.
	 *
	 * @param string $date Y-m-d.
	 * @param string $time H:i, or '' for midnight.
	 * @return DateTimeImmutable|null Null when the date is unusable.
	 */
	public static function site_datetime( $date, $time = '' ) {
		$date = trim( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		$time = trim( (string) $time );
		if ( ! preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $time ) ) {
			$time = '00:00';
		}

		try {
			return new DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * The event's start as a DateTimeImmutable in the site timezone.
	 *
	 * @param int $event_id The event post ID.
	 * @return DateTimeImmutable|null
	 */
	public static function event_start( $event_id ) {
		$date    = get_post_meta( $event_id, '_blt_event_date', true );
		$all_day = get_post_meta( $event_id, '_blt_event_all_day', true ) === '1';
		$time    = $all_day ? '' : get_post_meta( $event_id, '_blt_event_start_time', true );

		return self::site_datetime( $date, $time );
	}

	/**
	 * The event's end as a DateTimeImmutable in the site timezone, or null
	 * when no end is set. Multi-day events end on their last day.
	 *
	 * @param int $event_id The event post ID.
	 * @return DateTimeImmutable|null
	 */
	public static function event_end( $event_id ) {
		$date     = get_post_meta( $event_id, '_blt_event_date', true );
		$end_date = get_post_meta( $event_id, '_blt_event_end_date', true ) ?: $date;
		$all_day  = get_post_meta( $event_id, '_blt_event_all_day', true ) === '1';
		$end_time = get_post_meta( $event_id, '_blt_event_end_time', true );

		if ( $all_day ) {
			// All-day: the end is the start of the following day.
			$last = self::site_datetime( $end_date, '' );
			return $last ? $last->modify( '+1 day' ) : null;
		}

		if ( ! $end_time ) {
			return null;
		}

		return self::site_datetime( $end_date, $end_time );
	}

	/**
	 * Whether an event is all-day.
	 *
	 * @param int $event_id The event post ID.
	 * @return bool
	 */
	public static function event_is_all_day( $event_id ) {
		return get_post_meta( $event_id, '_blt_event_all_day', true ) === '1'
			|| '' === (string) get_post_meta( $event_id, '_blt_event_start_time', true );
	}

	/**
	 * The configured display format for event dates.
	 *
	 * @return string PHP date format.
	 */
	public static function date_format() {
		return (string) get_option( 'blt_events_date_format', 'F j, Y' ) ?: 'F j, Y';
	}

	/**
	 * The site's time format.
	 *
	 * @return string PHP date format.
	 */
	public static function time_format() {
		return (string) get_option( 'time_format', 'g:i a' ) ?: 'g:i a';
	}

	/**
	 * Format a stored Y-m-d date for display.
	 *
	 * @param string $date   Y-m-d.
	 * @param string $format Optional PHP date format; defaults to the plugin setting.
	 * @return string
	 */
	public static function format_date( $date, $format = '' ) {
		$dt = self::site_datetime( $date );
		if ( ! $dt ) {
			return '';
		}

		return wp_date( $format ?: self::date_format(), $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Format a stored H:i time (on a given date) for display.
	 *
	 * @param string $date Y-m-d (needed for DST-correct output).
	 * @param string $time H:i.
	 * @return string
	 */
	public static function format_time( $date, $time ) {
		if ( '' === trim( (string) $time ) ) {
			return '';
		}

		$dt = self::site_datetime( $date ?: current_time( 'Y-m-d' ), $time );
		if ( ! $dt ) {
			return '';
		}

		return wp_date( self::time_format(), $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Display label for an event's date, collapsing multi-day ranges.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function event_date_label( $event_id ) {
		$date     = get_post_meta( $event_id, '_blt_event_date', true );
		$end_date = get_post_meta( $event_id, '_blt_event_end_date', true );

		$label = self::format_date( $date );
		if ( $end_date && $end_date !== $date ) {
			$label .= ' – ' . self::format_date( $end_date );
		}

		/**
		 * Filter the displayed date label of an event.
		 *
		 * @param string $label    Formatted label.
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_event_date_label', $label, $event_id );
	}

	/**
	 * Display label for an event's time ("All Day", "10:00 am – 11:30 am").
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function event_time_label( $event_id ) {
		$date     = get_post_meta( $event_id, '_blt_event_date', true );
		$end_date = get_post_meta( $event_id, '_blt_event_end_date', true ) ?: $date;
		$start    = get_post_meta( $event_id, '_blt_event_start_time', true );
		$end      = get_post_meta( $event_id, '_blt_event_end_time', true );

		if ( get_post_meta( $event_id, '_blt_event_all_day', true ) === '1' ) {
			$label = __( 'All Day', 'blt-events' );
		} elseif ( $start && $date ) {
			$label = self::format_time( $date, $start );
			if ( $end ) {
				$label .= ' – ' . self::format_time( $end_date, $end );
			}
		} else {
			$label = '';
		}

		/**
		 * Filter the displayed time label of an event.
		 *
		 * @param string $label    Formatted label.
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_event_time_label', $label, $event_id );
	}

	/* ------------------------------------------------------------------
	 * Location
	 * ---------------------------------------------------------------- */

	/**
	 * Human-readable location for an event: the physical venue/address,
	 * the online join link, or both for hybrid events.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function get_event_location_string( $event_id ) {
		$venue      = get_post_meta( $event_id, '_blt_event_venue', true );
		$location   = get_post_meta( $event_id, '_blt_event_location', true );
		$online_url = get_post_meta( $event_id, '_blt_event_online_url', true );
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';

		$location_parts = array();
		if ( in_array( $event_type, array( 'in-person', 'hybrid' ), true ) ) {
			$location_parts[] = trim( $venue . ( $venue && $location ? ', ' : '' ) . $location );
		}
		if ( in_array( $event_type, array( 'online', 'hybrid' ), true ) && $online_url ) {
			$location_parts[] = $online_url;
		}

		$location_string = implode( ' / ', array_filter( $location_parts ) );
		if ( $location_string === '' && $event_type === 'online' ) {
			$location_string = __( 'Online', 'blt-events' );
		}

		return $location_string;
	}

	/**
	 * The physical address only (venue + street), or '' for online events.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function get_event_address( $event_id ) {
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';
		if ( ! in_array( $event_type, array( 'in-person', 'hybrid' ), true ) ) {
			return '';
		}

		$venue    = get_post_meta( $event_id, '_blt_event_venue', true );
		$location = get_post_meta( $event_id, '_blt_event_location', true );

		return trim( $venue . ( $venue && $location ? ', ' : '' ) . $location );
	}

	/* ------------------------------------------------------------------
	 * Calendar invites
	 * ---------------------------------------------------------------- */

	/**
	 * Default calendar invite description template (Settings > Emails >
	 * Calendar Invite). Placeholders are replaced per event.
	 */
	public static function default_calendar_invite_template() {
		return __(
			"You are registered for {event_name}.\n\nDate: {event_date}\nTime: {event_time}\nLocation: {event_location}\n\nEvent details: {event_url}",
			'blt-events'
		);
	}

	/**
	 * Build the calendar invite description for an event from the
	 * customizable template, falling back to basic event details.
	 *
	 * @param WP_Post $event The event post object.
	 * @return string Plain-text description for the ICS DESCRIPTION field.
	 */
	public static function get_calendar_invite_description( $event ) {
		$template = get_option( 'blt_events_calendar_invite_description', '' );
		if ( trim( (string) $template ) === '' ) {
			$template = self::default_calendar_invite_template();
		}

		$replacements = array(
			'{event_name}'     => $event->post_title,
			'{event_date}'     => self::event_date_label( $event->ID ),
			'{event_time}'     => self::event_time_label( $event->ID ),
			'{event_location}' => self::get_event_location_string( $event->ID ),
			'{event_url}'      => get_permalink( $event->ID ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Generate iCalendar (.ics) file content for an event.
	 *
	 * Timed events are written in UTC (Z suffix) after converting from the
	 * site timezone, so a 14:00 event in New York lands at 14:00 New York in
	 * every calendar client. All-day events use DATE values.
	 *
	 * @param WP_Post $event The event post object.
	 * @return string The iCalendar file content.
	 */
	public static function generate_ics_content( $event ) {
		$event_date     = get_post_meta( $event->ID, '_blt_event_date', true );
		$event_end_date = get_post_meta( $event->ID, '_blt_event_end_date', true ) ?: $event_date;
		$all_day        = self::event_is_all_day( $event->ID );

		$summary     = self::escape_ical_text( $event->post_title );
		$description = self::escape_ical_text( self::get_calendar_invite_description( $event ) );
		$url         = get_permalink( $event->ID );
		$uid         = $event->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		$now         = gmdate( 'Ymd\THis\Z' );
		$utc         = new DateTimeZone( 'UTC' );

		$ics  = "BEGIN:VCALENDAR\r\n";
		$ics .= "VERSION:2.0\r\n";
		$ics .= "PRODID:-//BLT Events//BLT Events Plugin//EN\r\n";
		$ics .= "CALSCALE:GREGORIAN\r\n";
		$ics .= "METHOD:PUBLISH\r\n";
		$ics .= "BEGIN:VEVENT\r\n";
		$ics .= "UID:{$uid}\r\n";
		$ics .= "DTSTAMP:{$now}\r\n";

		if ( $all_day ) {
			$start = self::site_datetime( $event_date );
			$end   = self::site_datetime( $event_end_date );
			if ( $start ) {
				$ics .= 'DTSTART;VALUE=DATE:' . $start->format( 'Ymd' ) . "\r\n";
			}
			if ( $end ) {
				// All-day events end on the day after their last day in iCal spec.
				$ics .= 'DTEND;VALUE=DATE:' . $end->modify( '+1 day' )->format( 'Ymd' ) . "\r\n";
			}
		} else {
			$start = self::event_start( $event->ID );
			$end   = self::event_end( $event->ID );
			if ( $start ) {
				$ics .= 'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . "\r\n";
			}
			if ( $end ) {
				$ics .= 'DTEND:' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . "\r\n";
			}
		}

		$ics .= "SUMMARY:{$summary}\r\n";
		$ics .= "DESCRIPTION:{$description}\r\n";

		$ics_location = self::escape_ical_text( self::get_event_location_string( $event->ID ) );
		if ( $ics_location !== '' ) {
			$ics .= "LOCATION:{$ics_location}\r\n";
		}

		$ics .= "URL:{$url}\r\n";
		$ics .= "END:VEVENT\r\n";
		$ics .= "END:VCALENDAR\r\n";

		/**
		 * Filter the generated .ics content.
		 *
		 * @param string  $ics   The iCalendar text.
		 * @param WP_Post $event The event.
		 */
		return apply_filters( 'blt_events_ics_content', $ics, $event );
	}

	/**
	 * Build a Google Calendar add-event URL for an event.
	 *
	 * @param WP_Post $event The event post object.
	 * @return string The Google Calendar URL.
	 */
	public static function get_google_calendar_url( $event ) {
		$event_date     = get_post_meta( $event->ID, '_blt_event_date', true );
		$event_end_date = get_post_meta( $event->ID, '_blt_event_end_date', true ) ?: $event_date;
		$all_day        = self::event_is_all_day( $event->ID );
		$utc            = new DateTimeZone( 'UTC' );

		$params = array(
			'action'  => 'TEMPLATE',
			'text'    => $event->post_title,
			'details' => wp_strip_all_tags( $event->post_content ),
		);

		$location = self::get_event_location_string( $event->ID );
		if ( $location ) {
			$params['location'] = $location;
		}

		if ( $all_day ) {
			$start = self::site_datetime( $event_date );
			$end   = self::site_datetime( $event_end_date );
			if ( $start && $end ) {
				$params['dates'] = $start->format( 'Ymd' ) . '/' . $end->modify( '+1 day' )->format( 'Ymd' );
			}
		} else {
			$start = self::event_start( $event->ID );
			$end   = self::event_end( $event->ID );
			if ( $start ) {
				// Default to 1 hour duration.
				$end = $end ?: $start->modify( '+1 hour' );
				$params['dates'] = $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . '/' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			}
		}

		$url = 'https://calendar.google.com/calendar/render?' . http_build_query( $params );

		/**
		 * Filter the Google Calendar link for an event.
		 *
		 * @param string  $url   The URL.
		 * @param WP_Post $event The event.
		 */
		return apply_filters( 'blt_events_google_calendar_url', $url, $event );
	}

	/**
	 * Public .ics download URL for an event.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function get_ics_url( $event_id ) {
		return add_query_arg(
			array(
				'action'   => 'blt_event_ics',
				'event_id' => absint( $event_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/* ------------------------------------------------------------------
	 * Tickets and registration windows
	 * ---------------------------------------------------------------- */

	/**
	 * Get an event's ticket types as an array (stored as JSON post meta).
	 *
	 * @param int $event_id The event post ID.
	 * @return array Ticket type arrays keyed by their original index.
	 */
	public static function get_ticket_types( $event_id ) {
		$raw   = get_post_meta( $event_id, '_blt_ticket_types', true );
		$types = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Get the ticket types currently purchasable by the current visitor,
	 * i.e. inside their sale window and not restricted to a role the
	 * visitor doesn't have. Original indexes are preserved because
	 * payment integrations map products/prices by ticket index.
	 *
	 * @param int $event_id The event post ID.
	 * @return array Available ticket type arrays keyed by original index.
	 */
	public static function available_ticket_types( $event_id ) {
		return array_filter( self::get_ticket_types( $event_id ), array( __CLASS__, 'ticket_is_available' ) );
	}

	/**
	 * Whether a ticket type is currently available to the current visitor.
	 *
	 * A ticket is unavailable before its sale start, after its sale end,
	 * or when it is restricted to roles the current user doesn't have.
	 *
	 * @param array $ticket Ticket type array.
	 * @return bool
	 */
	public static function ticket_is_available( $ticket ) {
		$now = current_time( 'Y-m-d H:i' );

		if ( ! empty( $ticket['sale_start_date'] ) ) {
			$start = $ticket['sale_start_date'] . ' ' . ( ! empty( $ticket['sale_start_time'] ) ? $ticket['sale_start_time'] : '00:00' );
			if ( $now < $start ) {
				return false;
			}
		}

		if ( ! empty( $ticket['sale_end_date'] ) ) {
			$end = $ticket['sale_end_date'] . ' ' . ( ! empty( $ticket['sale_end_time'] ) ? $ticket['sale_end_time'] : '23:59' );
			if ( $now > $end ) {
				return false;
			}
		}

		$roles = isset( $ticket['roles'] ) && is_array( $ticket['roles'] ) ? $ticket['roles'] : array();
		if ( ! empty( $roles ) ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user = wp_get_current_user();
			if ( ! array_intersect( $roles, (array) $user->roles ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lowest and highest ticket prices of an event.
	 *
	 * @param int $event_id The event post ID.
	 * @return array{min:float,max:float,has_paid:bool,count:int}
	 */
	public static function ticket_price_range( $event_id ) {
		$min      = null;
		$max      = 0.0;
		$has_paid = false;
		$tickets  = self::get_ticket_types( $event_id );

		foreach ( $tickets as $ticket ) {
			$price = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0.0;
			$min   = ( null === $min ) ? $price : min( $min, $price );
			$max   = max( $max, $price );
			if ( $price > 0 ) {
				$has_paid = true;
			}
		}

		return array(
			'min'      => (float) ( null === $min ? 0 : $min ),
			'max'      => $max,
			'has_paid' => $has_paid,
			'count'    => count( $tickets ),
		);
	}

	/**
	 * Whether an event's registration cutoff has passed. Events without a
	 * cutoff date never pass; a cutoff date without a time closes at the
	 * end of that day.
	 *
	 * @param int $event_id The event post ID.
	 * @return bool
	 */
	public static function registration_cutoff_passed( $event_id ) {
		$date = get_post_meta( $event_id, '_blt_registration_cutoff_date', true );
		if ( ! $date ) {
			return false;
		}

		$time = get_post_meta( $event_id, '_blt_registration_cutoff_time', true );

		return current_time( 'Y-m-d H:i' ) > $date . ' ' . ( $time ?: '23:59' );
	}

	/**
	 * Seats still available on an event, or null when capacity is unlimited.
	 *
	 * @param int $event_id The event post ID.
	 * @return int|null
	 */
	public static function spots_left( $event_id ) {
		$capacity = (int) get_post_meta( $event_id, '_blt_capacity', true );
		if ( $capacity <= 0 ) {
			return null;
		}

		$reg_db = new BLT_Events_Registrations_DB();

		return max( 0, $capacity - $reg_db->get_event_registration_count( $event_id ) );
	}

	/**
	 * Whether the event is sold out (capacity reached).
	 *
	 * @param int $event_id The event post ID.
	 * @return bool
	 */
	public static function is_sold_out( $event_id ) {
		$left = self::spots_left( $event_id );

		return null !== $left && $left <= 0;
	}

	/* ------------------------------------------------------------------
	 * Capabilities and settings
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the current user can manage BLT Events.
	 *
	 * Falls back to manage_options so administrators are never locked out
	 * on sites where the plugin was updated without re-activation.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::MANAGE_CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Capability string to use when registering admin menu pages for the
	 * current request.
	 *
	 * @return string
	 */
	public static function menu_capability() {
		return current_user_can( self::MANAGE_CAP ) ? self::MANAGE_CAP : 'manage_options';
	}

	/**
	 * The site-wide default payment provider.
	 *
	 * Delegates to the provider registry so the settings option is validated
	 * in exactly one place. Prefer get_event_payment_provider() anywhere an
	 * event is in scope: an event may override the default.
	 *
	 * @return string The default payment provider slug (default: 'none').
	 */
	public static function get_payment_provider() {
		return BLT_Events_Payment_Providers::get_default();
	}

	/**
	 * The payment provider a given event checks out through.
	 *
	 * @param int $event_id The event post ID.
	 * @return string Provider slug, or 'none'.
	 */
	public static function get_event_payment_provider( $event_id ) {
		return BLT_Events_Payment_Providers::get_event_provider( $event_id );
	}

	/**
	 * URL of the events listing page: the page selected in Settings, or the
	 * event post type archive as a fallback. Empty string when neither
	 * resolves.
	 *
	 * @return string
	 */
	public static function events_page_url() {
		$page_id = (int) get_option( 'blt_events_events_page_id', 0 );

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		$archive = get_post_type_archive_link( 'event' );

		return $archive ? $archive : '';
	}

	/**
	 * Escape text for iCalendar format.
	 *
	 * @param string $text The text to escape.
	 * @return string The escaped text.
	 */
	public static function escape_ical_text( $text ) {
		$text = (string) $text;
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( ',', '\\,', $text );
		$text = str_replace( ';', '\\;', $text );
		$text = str_replace( "\r\n", '\\n', $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\r", '\\n', $text );
		return $text;
	}
}
