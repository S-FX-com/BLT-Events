<?php
/**
 * BLT Events - Front-End Single Event View
 *
 * Wraps single event content with the event layout: a meta bar (date,
 * time, location), the description, a sidebar with add-to-calendar and
 * share actions, and the registration form. Rendered through the
 * the_content filter so it works inside any theme's single template.
 *
 * Themes/plugins can add sidebar content (e.g. presenters) via the
 * blt_events_single_sidebar action, and disable the whole wrapper with
 * the blt_events_render_single filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Single_Event {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 10 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		// Public .ics download for a single event.
		add_action( 'admin_post_blt_event_ics', array( __CLASS__, 'download_ics' ) );
		add_action( 'admin_post_nopriv_blt_event_ics', array( __CLASS__, 'download_ics' ) );
	}

	public static function register_assets() {
		wp_register_style(
			'blt-events-single',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
			array(),
			BLT_EVENTS_VERSION
		);

		if ( is_singular( 'event' ) ) {
			wp_enqueue_style( 'blt-events-single' );
		}
	}

	/**
	 * Serve the event's calendar invite as an .ics download.
	 */
	public static function download_ics() {
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$event    = $event_id ? get_post( $event_id ) : null;

		if ( ! $event || $event->post_type !== 'event' || $event->post_status !== 'publish' ) {
			wp_die( esc_html__( 'Event not found.', 'blt-events' ), '', array( 'response' => 404 ) );
		}

		$filename = sanitize_file_name( $event->post_name ?: 'event-' . $event->ID ) . '.ics';

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo BLT_Events_Helpers::generate_ics_content( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Wrap the event description with the single event layout.
	 */
	public static function filter_content( $content ) {
		if ( ! is_singular( 'event' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$event = get_post();
		if ( ! $event ) {
			return $content;
		}

		/**
		 * Filter whether BLT Events renders the single event layout.
		 * Return false to leave the content untouched (e.g. when a page
		 * builder template handles the layout).
		 */
		if ( ! apply_filters( 'blt_events_render_single', true, $event ) ) {
			return $content;
		}

		// Guard against nested the_content calls (page builders, excerpts).
		static $rendering = false;
		if ( $rendering ) {
			return $content;
		}
		$rendering = true;

		$html = self::render_single( $event, $content );

		$rendering = false;

		return $html;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	private static function render_single( $event, $content ) {
		$event_id = $event->ID;

		$date_format = get_option( 'blt_events_date_format', 'F j, Y' );
		$time_format = get_option( 'time_format', 'g:i A' );

		$event_date = get_post_meta( $event_id, '_blt_event_date', true );
		$end_date   = get_post_meta( $event_id, '_blt_event_end_date', true );
		$start_time = get_post_meta( $event_id, '_blt_event_start_time', true );
		$end_time   = get_post_meta( $event_id, '_blt_event_end_time', true );
		$all_day    = get_post_meta( $event_id, '_blt_event_all_day', true ) === '1';
		$multi_day  = get_post_meta( $event_id, '_blt_multi_day', true ) === '1';
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';
		$venue      = get_post_meta( $event_id, '_blt_event_venue', true );
		$location   = get_post_meta( $event_id, '_blt_event_location', true );
		$online_url = get_post_meta( $event_id, '_blt_event_online_url', true );

		$days = array();
		if ( $multi_day ) {
			$days_raw = get_post_meta( $event_id, '_blt_event_days', true );
			$decoded  = is_string( $days_raw ) ? json_decode( $days_raw, true ) : $days_raw;
			$days     = is_array( $decoded ) ? $decoded : array();
		}

		// Date label.
		$date_label = $event_date ? date_i18n( $date_format, strtotime( $event_date ) ) : '';
		if ( $multi_day && $end_date && $end_date !== $event_date ) {
			$date_label .= ' – ' . date_i18n( $date_format, strtotime( $end_date ) );
		}

		// Time label.
		if ( $all_day ) {
			$time_label = __( 'All Day', 'blt-events' );
		} elseif ( $start_time && $event_date ) {
			$time_label = date_i18n( $time_format, strtotime( $event_date . ' ' . $start_time ) );
			if ( $end_time ) {
				$end_time_date = ( $multi_day && $end_date ) ? $end_date : $event_date;
				$time_label   .= ' – ' . date_i18n( $time_format, strtotime( $end_time_date . ' ' . $end_time ) );
			}
		} else {
			$time_label = '';
		}

		// Location lines.
		$location_lines = array();
		if ( in_array( $event_type, array( 'in-person', 'hybrid' ), true ) ) {
			if ( $venue ) {
				$location_lines[] = $venue;
			}
			if ( $location ) {
				$location_lines[] = $location;
			}
		}
		$is_online = in_array( $event_type, array( 'online', 'hybrid' ), true );

		$google_url = BLT_Events_Helpers::get_google_calendar_url( $event );
		$ics_url    = admin_url( 'admin-post.php?action=blt_event_ics&event_id=' . $event_id );
		$permalink  = get_permalink( $event_id );

		ob_start();
		?>
		<div class="blt-single-event">
			<div class="blt-single-layout">
				<div class="blt-single-main">
					<div class="blt-event-meta-bar">
						<?php if ( $date_label ) : ?>
							<div class="blt-meta-item">
								<span class="blt-meta-icon" aria-hidden="true"><?php echo self::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span class="blt-meta-text"><?php echo esc_html( $date_label ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( $time_label ) : ?>
							<div class="blt-meta-item">
								<span class="blt-meta-icon" aria-hidden="true"><?php echo self::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span class="blt-meta-text"><?php echo esc_html( $time_label ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $location_lines ) ) : ?>
							<div class="blt-meta-item">
								<span class="blt-meta-icon" aria-hidden="true"><?php echo self::icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span class="blt-meta-text">
									<?php foreach ( $location_lines as $line ) : ?>
										<span class="blt-meta-line"><?php echo esc_html( $line ); ?></span>
									<?php endforeach; ?>
								</span>
							</div>
						<?php elseif ( $is_online ) : ?>
							<div class="blt-meta-item">
								<span class="blt-meta-icon" aria-hidden="true"><?php echo self::icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span class="blt-meta-text"><?php esc_html_e( 'Online Event', 'blt-events' ); ?></span>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $multi_day && count( $days ) > 1 ) : ?>
						<div class="blt-event-schedule">
							<h3><?php esc_html_e( 'Schedule', 'blt-events' ); ?></h3>
							<ul>
								<?php foreach ( $days as $day ) : ?>
									<?php
									$day_date = $day['date'] ?? '';
									if ( ! $day_date ) {
										continue;
									}
									$day_start = $day['start'] ?? '';
									$day_end   = $day['end'] ?? '';

									$day_time = $day_start === ''
										? __( 'All Day', 'blt-events' )
										: date_i18n( $time_format, strtotime( $day_date . ' ' . $day_start ) )
											. ( $day_end ? ' – ' . date_i18n( $time_format, strtotime( $day_date . ' ' . $day_end ) ) : '' );
									?>
									<li>
										<span class="blt-schedule-date"><?php echo esc_html( date_i18n( $date_format, strtotime( $day_date ) ) ); ?></span>
										<span class="blt-schedule-time"><?php echo esc_html( $day_time ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<div class="blt-event-description">
						<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-filtered post content. ?>
					</div>
				</div>

				<aside class="blt-single-sidebar">
					<div class="blt-sidebar-actions">
						<a class="blt-cal-button" href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener noreferrer">
							+ <?php esc_html_e( 'Add to Google Calendar', 'blt-events' ); ?>
						</a>
						<a class="blt-cal-button" href="<?php echo esc_url( $ics_url ); ?>">
							+ <?php esc_html_e( 'iCal / Outlook export', 'blt-events' ); ?>
						</a>
					</div>

					<?php
					/**
					 * Extra sidebar content for the single event view —
					 * presenters, sponsors, related links, and so on.
					 *
					 * @param int $event_id The event post ID.
					 */
					do_action( 'blt_events_single_sidebar', $event_id );
					?>

					<div class="blt-share">
						<h4><?php esc_html_e( 'Share', 'blt-events' ); ?></h4>
						<div class="blt-share-buttons">
							<a href="<?php echo esc_url( 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $permalink ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Share on LinkedIn', 'blt-events' ); ?>">in</a>
							<a href="<?php echo esc_url( 'https://twitter.com/intent/tweet?url=' . rawurlencode( $permalink ) . '&text=' . rawurlencode( $event->post_title ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Share on X', 'blt-events' ); ?>">&#120143;</a>
							<a href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $permalink ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Share on Facebook', 'blt-events' ); ?>">f</a>
						</div>
					</div>
				</aside>
			</div>

			<?php if ( ! has_shortcode( $event->post_content, 'blt_event_registration' ) ) : ?>
				<div class="blt-single-registration" id="blt-register">
					<h3><?php esc_html_e( 'Event Registration', 'blt-events' ); ?></h3>
					<?php echo BLT_Events_Registration_Shortcode::render( array( 'event_id' => $event_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline SVG icons (stroke follows currentColor so the theme's text
	 * color applies).
	 */
	private static function icon( $name ) {
		$icons = array(
			'calendar' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
			'clock'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
			'pin'      => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
			'globe'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
		);

		return $icons[ $name ] ?? '';
	}
}
