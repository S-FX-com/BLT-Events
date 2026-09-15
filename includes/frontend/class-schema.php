<?php
/**
 * BLT Events - Structured data
 *
 * Prints a schema.org Event JSON-LD block in the head of every single event
 * page, so search engines can show rich results (date, location, price)
 * without any configuration. Switch it off in Settings > General or adjust
 * the output with the blt_events_json_ld filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Schema {

	const OPTION = 'blt_events_schema_enabled';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_json_ld' ), 5 );
	}

	public static function is_enabled() {
		return '1' === (string) get_option( self::OPTION, '1' );
	}

	public static function print_json_ld() {
		if ( ! is_singular( 'event' ) || ! self::is_enabled() ) {
			return;
		}

		$event = get_queried_object();
		if ( ! $event instanceof WP_Post ) {
			return;
		}

		$data = self::build( $event );
		if ( empty( $data ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded.
	}

	/**
	 * The schema.org Event array for an event post.
	 *
	 * @param WP_Post $event The event.
	 * @return array
	 */
	public static function build( $event ) {
		$event_id   = $event->ID;
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';
		$start      = BLT_Events_Helpers::event_start( $event_id );
		$end        = BLT_Events_Helpers::event_end( $event_id );
		$all_day    = BLT_Events_Helpers::event_is_all_day( $event_id );

		if ( ! $start ) {
			return array();
		}

		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Event',
			'name'        => wp_strip_all_tags( get_the_title( $event ) ),
			'url'         => get_permalink( $event ),
			'startDate'   => $all_day ? $start->format( 'Y-m-d' ) : $start->format( DATE_ATOM ),
			'eventStatus' => 'https://schema.org/EventScheduled',
		);

		if ( $end ) {
			$data['endDate'] = $all_day ? $end->modify( '-1 day' )->format( 'Y-m-d' ) : $end->format( DATE_ATOM );
		}

		$excerpt = has_excerpt( $event ) ? get_the_excerpt( $event ) : wp_trim_words( wp_strip_all_tags( $event->post_content ), 55 );
		if ( $excerpt ) {
			$data['description'] = $excerpt;
		}

		if ( has_post_thumbnail( $event_id ) ) {
			$image = wp_get_attachment_image_url( get_post_thumbnail_id( $event_id ), 'large' );
			if ( $image ) {
				$data['image'] = array( $image );
			}
		}

		// Attendance mode and location(s).
		$modes = array(
			'online'    => 'https://schema.org/OnlineEventAttendanceMode',
			'in-person' => 'https://schema.org/OfflineEventAttendanceMode',
			'hybrid'    => 'https://schema.org/MixedEventAttendanceMode',
		);
		$data['eventAttendanceMode'] = $modes[ $event_type ] ?? $modes['in-person'];

		$locations = array();

		if ( in_array( $event_type, array( 'in-person', 'hybrid' ), true ) ) {
			$venue   = get_post_meta( $event_id, '_blt_event_venue', true );
			$street  = get_post_meta( $event_id, '_blt_event_location', true );
			$lat     = get_post_meta( $event_id, '_blt_event_latitude', true );
			$lng     = get_post_meta( $event_id, '_blt_event_longitude', true );
			$place   = array( '@type' => 'Place' );

			if ( $venue ) {
				$place['name'] = $venue;
			}
			if ( $street ) {
				$place['address'] = $street;
			}
			if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
				$place['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $lat,
					'longitude' => (float) $lng,
				);
			}

			if ( count( $place ) > 1 ) {
				$locations[] = $place;
			}
		}

		if ( in_array( $event_type, array( 'online', 'hybrid' ), true ) ) {
			// The join link is private; the event page is the public URL.
			$locations[] = array(
				'@type' => 'VirtualLocation',
				'url'   => get_permalink( $event ),
			);
		}

		if ( 1 === count( $locations ) ) {
			$data['location'] = $locations[0];
		} elseif ( $locations ) {
			$data['location'] = $locations;
		}

		// Offers, one per ticket type.
		$offers    = array();
		$sold_out  = BLT_Events_Helpers::is_sold_out( $event_id );
		$open      = get_post_meta( $event_id, '_blt_registration_open', true ) === '1' && ! BLT_Events_Helpers::registration_cutoff_passed( $event_id );

		foreach ( BLT_Events_Helpers::get_ticket_types( $event_id ) as $ticket ) {
			$offer = array(
				'@type'         => 'Offer',
				'name'          => $ticket['name'] ?? __( 'Ticket', 'blt-events' ),
				'price'         => number_format( (float) ( $ticket['price'] ?? 0 ), 2, '.', '' ),
				'priceCurrency' => BLT_Events_Helpers::get_currency_code(),
				'url'           => get_permalink( $event ) . '#blt-event-registration',
				'availability'  => ( $open && ! $sold_out ) ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
			);

			if ( ! empty( $ticket['sale_start_date'] ) ) {
				$from = BLT_Events_Helpers::site_datetime( $ticket['sale_start_date'], $ticket['sale_start_time'] ?? '' );
				if ( $from ) {
					$offer['validFrom'] = $from->format( DATE_ATOM );
				}
			}
			if ( ! empty( $ticket['sale_end_date'] ) ) {
				$to = BLT_Events_Helpers::site_datetime( $ticket['sale_end_date'], $ticket['sale_end_time'] ?? '23:59' );
				if ( $to ) {
					$offer['validThrough'] = $to->format( DATE_ATOM );
				}
			}

			$offers[] = $offer;
		}

		if ( $offers ) {
			$data['offers'] = $offers;
		} elseif ( $open ) {
			$data['isAccessibleForFree'] = true;
		}

		$data['organizer'] = array(
			'@type' => 'Organization',
			'name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'   => home_url( '/' ),
		);

		if ( class_exists( 'BLT_Events_Presenters' ) ) {
			$performers = array();
			foreach ( BLT_Events_Presenters::for_event( $event_id ) as $presenter ) {
				$performer = array(
					'@type' => 'Person',
					'name'  => $presenter['name'],
				);
				if ( ! empty( $presenter['role'] ) ) {
					$performer['jobTitle'] = $presenter['role'];
				}
				if ( ! empty( $presenter['url'] ) ) {
					$performer['url'] = $presenter['url'];
				}
				if ( ! empty( $presenter['photo'] ) ) {
					$performer['image'] = $presenter['photo'];
				}
				$performers[] = $performer;
			}
			if ( $performers ) {
				$data['performer'] = $performers;
			}
		}

		/**
		 * Filter the schema.org Event data printed on a single event page.
		 * Return an empty array to print nothing for this event.
		 *
		 * @param array   $data  JSON-LD data.
		 * @param WP_Post $event The event.
		 */
		return (array) apply_filters( 'blt_events_json_ld', $data, $event );
	}
}
