<?php
/**
 * BLT Events - Reminder emails
 *
 * A WP-Cron task that runs every 15 minutes and sends the 24-hour and
 * 1-hour reminders to confirmed attendees. Each reminder is sent at most
 * once per event: the send is recorded in post meta, so a late or skipped
 * cron run still sends exactly one reminder as soon as it catches up, and
 * never a second one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Reminders {

	const HOOK       = 'blt_events_send_reminders';
	const RECURRENCE = 'blt_events_quarter_hourly';

	/**
	 * Reminder type => seconds before the event start.
	 *
	 * @return array<string,int>
	 */
	public static function windows() {
		/**
		 * Filter the reminder windows. Keys are email types from
		 * BLT_Events_Emails::templates(); values are seconds before start.
		 *
		 * @param array $windows Type => seconds.
		 */
		return (array) apply_filters( 'blt_events_reminder_windows', array(
			'reminder_24h' => DAY_IN_SECONDS,
			'reminder_1h'  => HOUR_IN_SECONDS,
		) );
	}

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		// Self-heal: an update that arrived without re-activation has no
		// schedule yet.
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function add_schedule( $schedules ) {
		$schedules[ self::RECURRENCE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (BLT Events)', 'blt-events' ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::RECURRENCE, self::HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * The cron task.
	 */
	public static function run() {
		$windows = array_filter( self::windows(), function ( $type ) {
			return BLT_Events_Emails::is_enabled( $type );
		}, ARRAY_FILTER_USE_KEY );

		if ( empty( $windows ) ) {
			return;
		}

		$now = time();

		// Only events starting between yesterday and two days out can be
		// inside any window; the meta flags do the exact bookkeeping.
		$events = get_posts( array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_blt_event_date',
					'value'   => array(
						wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ),
						wp_date( 'Y-m-d', $now + 2 * DAY_IN_SECONDS ),
					),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		) );

		foreach ( $events as $event_id ) {
			$start = BLT_Events_Helpers::event_start( $event_id );
			if ( ! $start ) {
				continue;
			}

			$start_ts = $start->getTimestamp();

			foreach ( $windows as $type => $seconds ) {
				$flag = '_blt_' . $type . '_sent';

				if ( get_post_meta( $event_id, $flag, true ) ) {
					continue;
				}

				// Inside the window: from (start - seconds) up to the start.
				if ( $now < $start_ts - (int) $seconds || $now >= $start_ts ) {
					continue;
				}

				// Claim the flag first so two overlapping cron runs cannot
				// both send.
				if ( ! add_post_meta( $event_id, $flag, current_time( 'mysql' ), true ) ) {
					continue;
				}

				self::send_for_event( $event_id, $type );
			}
		}
	}

	/**
	 * Send one reminder type to every confirmed registration of an event.
	 *
	 * @param int    $event_id The event post ID.
	 * @param string $type     Email type.
	 * @return int Number of emails handed to wp_mail().
	 */
	public static function send_for_event( $event_id, $type ) {
		$reg_db = new BLT_Events_Registrations_DB();
		$sent   = 0;
		$offset = 0;

		do {
			$batch = $reg_db->get_by_event( $event_id, array(
				'status' => 'confirmed',
				'limit'  => 200,
				'offset' => $offset,
			) );

			foreach ( $batch as $reg ) {
				if ( ! is_email( $reg->customer_email ) ) {
					continue;
				}

				/**
				 * Filter whether a reminder goes to a given registration.
				 *
				 * @param bool   $send Whether to send.
				 * @param object $reg  Registration row.
				 * @param string $type Reminder type.
				 */
				if ( ! apply_filters( 'blt_events_send_reminder_to', true, $reg, $type ) ) {
					continue;
				}

				if ( BLT_Events_Emails::send( $type, $reg ) ) {
					$sent++;
				}
			}

			$offset += 200;
		} while ( count( $batch ) === 200 );

		/**
		 * Fires after a reminder run for one event.
		 *
		 * @param int    $event_id The event post ID.
		 * @param string $type     Reminder type.
		 * @param int    $sent     Emails sent.
		 */
		do_action( 'blt_events_reminders_sent', $event_id, $type, $sent );

		return $sent;
	}
}
