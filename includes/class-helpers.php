<?php
/**
 * ZymEvents Helpers
 *
 * Static utility class providing common helper functions for the ZymEvents plugin.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZymEvents_Helpers {

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
     * Format a price amount with currency symbol and/or code based on plugin settings.
     *
     * @param float $amount        The price amount to format.
     * @param bool  $include_total Whether to prepend "Total: " to the output.
     * @return string The formatted price string.
     */
    public static function format_price( $amount, $include_total = false ) {
        $currency      = get_option( 'zymevents_currency', 'USD' );
        $show_currency = get_option( 'zymevents_display_currency', '0' );
        $show_symbol   = get_option( 'zymevents_display_currency_sign', '0' );

        // Format the number with 2 decimal places.
        $formatted_price = number_format( (float) $amount, 2 );

        $price_string = '';

        // Add "Total:" prefix if requested.
        if ( $include_total ) {
            $price_string .= 'Total: ';
        }

        // Add currency symbol before price if enabled.
        if ( $show_symbol === '1' && isset( self::$currency_symbols[ $currency ] ) ) {
            $price_string .= self::$currency_symbols[ $currency ];
        }

        // Add the formatted price.
        $price_string .= $formatted_price;

        // Add currency code after price if enabled.
        if ( $show_currency === '1' ) {
            $price_string .= ' ' . $currency;
        }

        return $price_string;
    }

    /**
     * Get currency configuration array for JavaScript localization.
     *
     * @return array Currency settings including code, symbols, and display flags.
     */
    public static function get_currency_config() {
        $currency = get_option( 'zymevents_currency', 'USD' );

        return array(
            'currency'       => $currency,
            'showCurrency'   => get_option( 'zymevents_display_currency', '0' ),
            'showSymbol'     => get_option( 'zymevents_display_currency_sign', '0' ),
            'currencySymbol' => isset( self::$currency_symbols[ $currency ] )
                ? self::$currency_symbols[ $currency ]
                : '',
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
     * Generate iCalendar (.ics) file content for an event.
     *
     * @param WP_Post $event The event post object.
     * @return string The iCalendar file content.
     */
    public static function generate_ics_content( $event ) {
        $event_date       = get_post_meta( $event->ID, '_zymevents_event_date', true );
        $event_start_time = get_post_meta( $event->ID, '_zymevents_event_start_time', true );
        $event_end_time   = get_post_meta( $event->ID, '_zymevents_event_end_time', true );
        $event_all_day    = get_post_meta( $event->ID, '_zymevents_event_all_day', true );

        $summary     = $event->post_title;
        $description = wp_strip_all_tags( $event->post_content );
        $url         = get_permalink( $event->ID );
        $uid         = $event->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
        $now         = gmdate( 'Ymd\THis\Z' );

        // Escape special iCal characters.
        $summary     = self::escape_ical_text( $summary );
        $description = self::escape_ical_text( $description );

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//ZymEvents//ZymEvents//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$now}\r\n";

        if ( $event_all_day === '1' || empty( $event_start_time ) ) {
            // All-day event: use DATE value type.
            $dtstart = str_replace( '-', '', $event_date );
            $ics .= "DTSTART;VALUE=DATE:{$dtstart}\r\n";
            // All-day events end on the next day in iCal spec.
            $next_day = gmdate( 'Ymd', strtotime( $event_date . ' +1 day' ) );
            $ics .= "DTEND;VALUE=DATE:{$next_day}\r\n";
        } else {
            // Timed event.
            $start_dt = $event_date . ' ' . $event_start_time;
            $ics .= 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) ) . "\r\n";

            if ( ! empty( $event_end_time ) ) {
                $end_dt = $event_date . ' ' . $event_end_time;
                $ics .= 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $end_dt ) ) . "\r\n";
            }
        }

        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
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
        $event_date       = get_post_meta( $event->ID, '_zymevents_event_date', true );
        $event_start_time = get_post_meta( $event->ID, '_zymevents_event_start_time', true );
        $event_end_time   = get_post_meta( $event->ID, '_zymevents_event_end_time', true );
        $event_all_day    = get_post_meta( $event->ID, '_zymevents_event_all_day', true );

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
     * Get the active payment provider from plugin settings.
     *
     * @return string The active payment provider slug (default: 'none').
     */
    public static function get_payment_provider() {
        return get_option( 'zymevents_payment_provider', 'none' );
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
