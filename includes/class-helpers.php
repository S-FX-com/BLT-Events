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
     * Currency symbol mapping.
     *
     * @var array
     */
    private static $currency_symbols = array(
        'USD' => '$',
        'EUR' => "\u{20AC}",
        'GBP' => "\u{00A3}",
        'SAR' => "\u{FDFC}",
        'AED' => "\u{062F}.\u{0625}",
    );

    /**
     * The currency code shown to visitors: the custom override when set,
     * otherwise the selected preset currency.
     *
     * @return string
     */
    public static function get_currency_code() {
        $custom = trim( (string) get_option( 'blt_events_currency_code_custom', '' ) );
        return $custom !== '' ? $custom : get_option( 'blt_events_currency', 'USD' );
    }

    /**
     * The currency symbol shown to visitors: the custom override when set,
     * otherwise the symbol mapped from the selected preset currency.
     *
     * @return string
     */
    public static function get_currency_symbol() {
        $custom = trim( (string) get_option( 'blt_events_currency_symbol_custom', '' ) );
        if ( $custom !== '' ) {
            return $custom;
        }

        $currency = get_option( 'blt_events_currency', 'USD' );
        return self::$currency_symbols[ $currency ] ?? '';
    }

    /**
     * Format a price amount with currency symbol and/or code based on plugin settings.
     *
     * @param float $amount        The price amount to format.
     * @param bool  $include_total Whether to prepend "Total: " to the output.
     * @return string The formatted price string.
     */
    public static function format_price( $amount, $include_total = false ) {
        $show_currency = get_option( 'blt_events_display_currency', '0' );
        $show_symbol   = get_option( 'blt_events_display_currency_sign', '0' );

        // Format the number with 2 decimal places.
        $formatted_price = number_format( (float) $amount, 2 );

        $price_string = '';

        // Add "Total:" prefix if requested.
        if ( $include_total ) {
            $price_string .= __( 'Total:', 'blt-events' ) . ' ';
        }

        // Add currency symbol before price if enabled.
        if ( $show_symbol === '1' ) {
            $price_string .= self::get_currency_symbol();
        }

        // Add the formatted price.
        $price_string .= $formatted_price;

        // Add currency code after price if enabled.
        if ( $show_currency === '1' ) {
            $price_string .= ' ' . self::get_currency_code();
        }

        return $price_string;
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
            'showCurrency'   => get_option( 'blt_events_display_currency', '0' ),
            'showSymbol'     => get_option( 'blt_events_display_currency_sign', '0' ),
            'currencySymbol' => self::get_currency_symbol(),
            'currencySymbols' => self::$currency_symbols,
        );
    }

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
        $phone = trim( $phone );

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

        $event_date  = get_post_meta( $event->ID, '_blt_event_date', true );
        $start_time  = get_post_meta( $event->ID, '_blt_event_start_time', true );
        $all_day     = get_post_meta( $event->ID, '_blt_event_all_day', true ) === '1';

        $date_format = get_option( 'blt_events_date_format', 'F j, Y' );
        $time_format = get_option( 'time_format', 'g:i A' );

        $formatted_date = $event_date ? date_i18n( $date_format, strtotime( $event_date ) ) : '';
        $formatted_time = $all_day
            ? __( 'All Day', 'blt-events' )
            : ( $start_time && $event_date ? date_i18n( $time_format, strtotime( $event_date . ' ' . $start_time ) ) : '' );

        $location_string = self::get_event_location_string( $event->ID );

        $replacements = array(
            '{event_name}'     => $event->post_title,
            '{event_date}'     => $formatted_date,
            '{event_time}'     => $formatted_time,
            '{event_location}' => $location_string,
            '{event_url}'      => get_permalink( $event->ID ),
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Generate iCalendar (.ics) file content for an event.
     *
     * @param WP_Post $event The event post object.
     * @return string The iCalendar file content.
     */
    public static function generate_ics_content( $event ) {
        $event_date       = get_post_meta( $event->ID, '_blt_event_date', true );
        $event_start_time = get_post_meta( $event->ID, '_blt_event_start_time', true );
        $event_end_time   = get_post_meta( $event->ID, '_blt_event_end_time', true );
        $event_all_day    = get_post_meta( $event->ID, '_blt_event_all_day', true );

        $summary     = $event->post_title;
        $description = self::get_calendar_invite_description( $event );
        $url         = get_permalink( $event->ID );
        $uid         = $event->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
        $now         = gmdate( 'Ymd\THis\Z' );

        // Escape special iCal characters.
        $summary     = self::escape_ical_text( $summary );
        $description = self::escape_ical_text( $description );

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//BLT Events//BLT Events Plugin//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$now}\r\n";

        // Multi-day events end on their last scheduled day.
        $event_end_date = get_post_meta( $event->ID, '_blt_event_end_date', true ) ?: $event_date;

        if ( $event_all_day === '1' || empty( $event_start_time ) ) {
            // All-day event: use DATE value type.
            $dtstart = str_replace( '-', '', $event_date );
            $ics .= "DTSTART;VALUE=DATE:{$dtstart}\r\n";
            // All-day events end on the day after their last day in iCal spec.
            $next_day = gmdate( 'Ymd', strtotime( $event_end_date . ' +1 day' ) );
            $ics .= "DTEND;VALUE=DATE:{$next_day}\r\n";
        } else {
            // Timed event.
            $start_dt = $event_date . ' ' . $event_start_time;
            $ics .= 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) ) . "\r\n";

            if ( ! empty( $event_end_time ) ) {
                $end_dt = $event_end_date . ' ' . $event_end_time;
                $ics .= 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $end_dt ) ) . "\r\n";
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

        return $ics;
    }

    /**
     * Build a Google Calendar add-event URL for an event.
     *
     * @param WP_Post $event The event post object.
     * @return string The Google Calendar URL.
     */
    public static function get_google_calendar_url( $event ) {
        $event_date       = get_post_meta( $event->ID, '_blt_event_date', true );
        $event_start_time = get_post_meta( $event->ID, '_blt_event_start_time', true );
        $event_end_time   = get_post_meta( $event->ID, '_blt_event_end_time', true );
        $event_all_day    = get_post_meta( $event->ID, '_blt_event_all_day', true );

        $title   = $event->post_title;
        $details = wp_strip_all_tags( $event->post_content );

        $base_url = 'https://calendar.google.com/calendar/render';

        $params = array(
            'action'  => 'TEMPLATE',
            'text'    => $title,
            'details' => $details,
        );

        $date_clean = str_replace( '-', '', $event_date );

        if ( $event_all_day === '1' || empty( $event_start_time ) ) {
            // All-day event format.
            $next_day       = gmdate( 'Ymd', strtotime( $event_date . ' +1 day' ) );
            $params['dates'] = $date_clean . '/' . $next_day;
        } else {
            $start_dt = gmdate( 'Ymd\THis\Z', strtotime( $event_date . ' ' . $event_start_time ) );

            if ( ! empty( $event_end_time ) ) {
                $end_dt = gmdate( 'Ymd\THis\Z', strtotime( $event_date . ' ' . $event_end_time ) );
            } else {
                // Default to 1 hour duration.
                $end_dt = gmdate( 'Ymd\THis\Z', strtotime( $event_date . ' ' . $event_start_time . ' +1 hour' ) );
            }

            $params['dates'] = $start_dt . '/' . $end_dt;
        }

        return $base_url . '?' . http_build_query( $params );
    }

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
     * The custom capability for managing BLT Events admin screens/data.
     * Granted to administrators on activation; grant it to other roles
     * (e.g. shop managers) to give them access without manage_options.
     */
    const MANAGE_CAP = 'manage_blt_events';

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
     * Get the active payment provider from plugin settings.
     *
     * @return string The active payment provider slug (default: 'none').
     */
    public static function get_payment_provider() {
        return get_option( 'blt_events_payment_provider', 'none' );
    }

    /**
     * Escape text for iCalendar format.
     *
     * @param string $text The text to escape.
     * @return string The escaped text.
     */
    private static function escape_ical_text( $text ) {
        $text = str_replace( '\\', '\\\\', $text );
        $text = str_replace( ',', '\\,', $text );
        $text = str_replace( ';', '\\;', $text );
        $text = str_replace( "\r\n", '\\n', $text );
        $text = str_replace( "\n", '\\n', $text );
        $text = str_replace( "\r", '\\n', $text );
        return $text;
    }
}
