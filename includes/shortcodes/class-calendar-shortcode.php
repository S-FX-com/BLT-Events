<?php
/**
 * BLT Events - Calendar Shortcode
 *
 * [blt_events_calendar] - Renders events as a list, a card grid, or a full
 * month calendar with previous/next navigation.
 *
 * Usage:
 *   [blt_events_calendar view="list" category="" limit="12" past="no" switcher="no"]
 *   view="list"     - vertical list of event cards (default)
 *   view="grid"     - card grid
 *   view="calendar" - month grid with prev/next month navigation
 *   switcher="yes"  - show a List / Month toggle so visitors can flip views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Calendar_Shortcode {

	public static function init() {
		add_shortcode( 'blt_events_calendar', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'view'     => 'list',
			'category' => '',
			'limit'    => 12,
			'past'     => 'no',
			'switcher' => 'no',
		), $atts );

		$view = self::resolve_view( $atts );

		wp_enqueue_style( 'blt-events' );
		wp_enqueue_style( 'blt-events-calendar', BLT_EVENTS_PLUGIN_URL . 'assets/css/calendar.css', array(), BLT_EVENTS_VERSION );

		if ( 'calendar' === $view ) {
			return self::render_month_view( $atts );
		}

		return self::render_list_view( $atts, $view );
	}

	/**
	 * Resolve the active view: the shortcode attribute, optionally overridden
	 * by the visitor via ?blt_view= when the switcher is enabled.
	 */
	private static function resolve_view( $atts ) {
		$allowed = array( 'list', 'grid', 'calendar' );

		$view = strtolower( $atts['view'] );
		if ( 'month' === $view ) {
			$view = 'calendar';
		}
		if ( ! in_array( $view, $allowed, true ) ) {
			$view = 'list';
		}

		if ( 'yes' === $atts['switcher'] && isset( $_GET['blt_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_key( wp_unslash( $_GET['blt_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $requested, $allowed, true ) ) {
				$view = $requested;
			}
		}

		return $view;
	}

	/**
	 * Base meta query: events marked "Hide from Calendar" stay accessible via
	 * direct link but never appear in calendar listings.
	 */
	private static function visibility_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_blt_hide_from_calendar',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_blt_hide_from_calendar',
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/**
	 * Optional event_category tax query from the shortcode's category attribute.
	 */
	private static function maybe_add_category_filter( &$args, $atts ) {
		if ( ! empty( $atts['category'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'event_category',
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
				),
			);
		}
	}

	/**
	 * View switcher (List | Grid | Month) shown when switcher="yes".
	 */
	private static function render_switcher( $active ) {
		$base = remove_query_arg( array( 'blt_view', 'blt_month' ) );

		$views = array(
			'list'     => __( 'List', 'blt-events' ),
			'grid'     => __( 'Grid', 'blt-events' ),
			'calendar' => __( 'Month', 'blt-events' ),
		);
		?>
		<div class="blt-view-switcher" role="group" aria-label="<?php esc_attr_e( 'Change events view', 'blt-events' ); ?>">
			<?php foreach ( $views as $view => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'blt_view', $view, $base ) ); ?>" class="blt-view-switch <?php echo $active === $view ? 'is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * List / Grid views
	 * ------------------------------------------------------------------ */

	private static function render_list_view( $atts, $view ) {
		// Clamp limit so shortcode input can't trigger an unbounded query.
		$limit = intval( $atts['limit'] );
		if ( $limit < 1 || $limit > 100 ) {
			$limit = 12;
		}

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_key'       => '_blt_event_date',
			'meta_type'      => 'DATE',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( self::visibility_meta_query() ),
		);

		// Filter out past events by default
		if ( $atts['past'] !== 'yes' ) {
			$args['meta_query'][] = array(
				'key'     => '_blt_event_date',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		self::maybe_add_category_filter( $args, $atts );

		$query = new WP_Query( $args );

		ob_start();

		if ( 'yes' === $atts['switcher'] ) {
			self::render_switcher( $view );
		}

		if ( ! $query->have_posts() ) {
			echo '<div class="blt-events-empty"><p>' . esc_html__( 'No upcoming events found.', 'blt-events' ) . '</p></div>';
			wp_reset_postdata();
			return ob_get_clean();
		}

		$view_class = $view === 'grid' ? 'blt-events-grid' : 'blt-events-list';
		?>
		<div class="blt-events-calendar <?php echo esc_attr( $view_class ); ?>">
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<?php
				$event_id    = get_the_ID();
				$event_date  = get_post_meta( $event_id, '_blt_event_date', true );
				$start_time  = get_post_meta( $event_id, '_blt_event_start_time', true );
				$end_time    = get_post_meta( $event_id, '_blt_event_end_time', true );
				$all_day     = get_post_meta( $event_id, '_blt_event_all_day', true );
				$venue       = get_post_meta( $event_id, '_blt_event_venue', true );
				$event_type  = get_post_meta( $event_id, '_blt_event_type', true );
				$ticket_raw  = get_post_meta( $event_id, '_blt_ticket_types', true );
				$tickets     = is_string( $ticket_raw ) ? json_decode( $ticket_raw, true ) : $ticket_raw;

				$date_format = get_option( 'blt_events_date_format', 'F j, Y' );
				$formatted_date = ! empty( $event_date ) ? date_i18n( $date_format, strtotime( $event_date ) ) : '';

				$time_display = '';
				if ( $all_day === '1' ) {
					$time_display = __( 'All Day', 'blt-events' );
				} elseif ( $start_time && $event_date ) {
					$time_display = date_i18n( get_option( 'time_format', 'g:i A' ), strtotime( $event_date . ' ' . $start_time ) );
					if ( $end_time ) {
						$time_display .= ' - ' . date_i18n( get_option( 'time_format', 'g:i A' ), strtotime( $event_date . ' ' . $end_time ) );
					}
				}

				// Price range
				$min_price = PHP_INT_MAX;
				$max_price = 0;
				if ( is_array( $tickets ) ) {
					foreach ( $tickets as $t ) {
						$p = (float) ( $t['price'] ?? 0 );
						$min_price = min( $min_price, $p );
						$max_price = max( $max_price, $p );
					}
				}
				if ( $min_price === PHP_INT_MAX ) {
					$min_price = 0;
				}
				?>
				<div class="blt-event-card">
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="blt-event-image">
							<a href="<?php the_permalink(); ?>">
								<?php the_post_thumbnail( 'medium' ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div class="blt-event-content">
						<div class="blt-event-date-badge">
							<span class="blt-date-month"><?php echo esc_html( date_i18n( 'M', strtotime( $event_date ) ) ); ?></span>
							<span class="blt-date-day"><?php echo esc_html( date_i18n( 'j', strtotime( $event_date ) ) ); ?></span>
						</div>

						<div class="blt-event-details">
							<h3 class="blt-event-title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h3>

							<div class="blt-event-meta">
								<?php if ( $formatted_date ) : ?>
									<span class="blt-meta-date"><?php echo esc_html( $formatted_date ); ?></span>
								<?php endif; ?>
								<?php if ( $time_display ) : ?>
									<span class="blt-meta-time"><?php echo esc_html( $time_display ); ?></span>
								<?php endif; ?>
								<?php if ( $venue ) : ?>
									<span class="blt-meta-venue"><?php echo esc_html( $venue ); ?></span>
								<?php endif; ?>
								<?php if ( $event_type ) : ?>
									<span class="blt-meta-type"><?php echo esc_html( ucfirst( $event_type ) ); ?></span>
								<?php endif; ?>
							</div>

							<?php if ( has_excerpt() ) : ?>
								<p class="blt-event-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
							<?php endif; ?>

							<div class="blt-event-footer">
								<?php if ( $max_price > 0 ) : ?>
									<span class="blt-event-price">
										<?php if ( $min_price == $max_price ) : ?>
											<?php echo esc_html( BLT_Events_Helpers::format_price( $min_price ) ); ?>
										<?php elseif ( $min_price == 0 ) : ?>
											<?php echo esc_html( sprintf( __( 'Free - %s', 'blt-events' ), BLT_Events_Helpers::format_price( $max_price ) ) ); ?>
										<?php else : ?>
											<?php echo esc_html( BLT_Events_Helpers::format_price( $min_price ) ); ?> - <?php echo esc_html( BLT_Events_Helpers::format_price( $max_price ) ); ?>
										<?php endif; ?>
									</span>
								<?php else : ?>
									<span class="blt-event-price blt-free"><?php esc_html_e( 'Free', 'blt-events' ); ?></span>
								<?php endif; ?>

								<a href="<?php the_permalink(); ?>" class="blt-btn-register"><?php esc_html_e( 'Register', 'blt-events' ); ?></a>
							</div>
						</div>
					</div>
				</div>
			<?php endwhile; ?>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * Month calendar view
	 * ------------------------------------------------------------------ */

	private static function render_month_view( $atts ) {
		// Requested month via ?blt_month=YYYY-MM, clamped to +/- 10 years so
		// crawlers can't page into infinity.
		$month = isset( $_GET['blt_month'] ) ? sanitize_text_field( wp_unslash( $_GET['blt_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}

		$current_year = (int) current_time( 'Y' );
		$year         = (int) substr( $month, 0, 4 );
		if ( $year < $current_year - 10 || $year > $current_year + 10 ) {
			$month = current_time( 'Y-m' );
		}

		$first_ts      = strtotime( $month . '-01' );
		$days_in_month = (int) gmdate( 't', $first_ts );
		$first_day     = $month . '-01';
		$last_day      = $month . '-' . str_pad( (string) $days_in_month, 2, '0', STR_PAD_LEFT );
		$today         = current_time( 'Y-m-d' );

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'meta_key'       => '_blt_event_date',
			'meta_type'      => 'DATE',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				self::visibility_meta_query(),
				array(
					'key'     => '_blt_event_date',
					'value'   => array( $first_day, $last_day ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		);

		self::maybe_add_category_filter( $args, $atts );

		$query = new WP_Query( $args );

		// Bucket events by day, sorted by start time within the day.
		$by_day      = array();
		$time_format = get_option( 'time_format', 'g:i A' );

		while ( $query->have_posts() ) {
			$query->the_post();
			$event_id   = get_the_ID();
			$event_date = get_post_meta( $event_id, '_blt_event_date', true );
			$start_time = get_post_meta( $event_id, '_blt_event_start_time', true );
			$all_day    = get_post_meta( $event_id, '_blt_event_all_day', true ) === '1';

			if ( empty( $event_date ) ) {
				continue;
			}

			// Multi-day events appear on each of their scheduled days.
			$schedule = array( array( 'date' => $event_date, 'start' => $all_day ? '' : $start_time ) );
			if ( get_post_meta( $event_id, '_blt_multi_day', true ) === '1' ) {
				$days_raw = get_post_meta( $event_id, '_blt_event_days', true );
				$days     = is_string( $days_raw ) ? json_decode( $days_raw, true ) : $days_raw;
				if ( is_array( $days ) && ! empty( $days ) ) {
					$schedule = $days;
				}
			}

			foreach ( $schedule as $day ) {
				$day_date = $day['date'] ?? '';
				if ( ! $day_date || $day_date < $first_day || $day_date > $last_day ) {
					continue;
				}

				$day_start  = $day['start'] ?? '';
				$time_label = $day_start === ''
					? __( 'All Day', 'blt-events' )
					: date_i18n( $time_format, strtotime( $day_date . ' ' . $day_start ) );

				$by_day[ $day_date ][] = array(
					'title'    => get_the_title(),
					'url'      => get_permalink(),
					'time'     => $time_label,
					'sort_key' => $day_start === '' ? '00:00' : $day_start,
				);
			}
		}
		wp_reset_postdata();

		foreach ( $by_day as &$day_events ) {
			usort( $day_events, function ( $a, $b ) {
				return strcmp( $a['sort_key'], $b['sort_key'] );
			} );
		}
		unset( $day_events );

		// Month navigation URLs (query-string based so the shortcode works on
		// any page without extra rewrite rules).
		$base       = remove_query_arg( 'blt_month' );
		$prev_month = gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) );
		$next_month = gmdate( 'Y-m', strtotime( $month . '-01 +1 month' ) );
		$is_current = $month === current_time( 'Y-m' );

		// Week layout honours the site's "Week Starts On" setting.
		global $wp_locale;
		$start_of_week = (int) get_option( 'start_of_week', 0 );
		$lead_days     = ( (int) gmdate( 'w', $first_ts ) - $start_of_week + 7 ) % 7;

		ob_start();

		if ( 'yes' === $atts['switcher'] ) {
			self::render_switcher( 'calendar' );
		}
		?>
		<div class="blt-events-calendar blt-events-month">
			<div class="blt-cal-header">
				<div class="blt-cal-nav">
					<a class="blt-cal-nav-btn" href="<?php echo esc_url( add_query_arg( 'blt_month', $prev_month, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'blt-events' ); ?>">&lsaquo;</a>
					<a class="blt-cal-nav-btn" href="<?php echo esc_url( add_query_arg( 'blt_month', $next_month, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'blt-events' ); ?>">&rsaquo;</a>
					<?php if ( ! $is_current ) : ?>
						<a class="blt-cal-today" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'This Month', 'blt-events' ); ?></a>
					<?php endif; ?>
				</div>
				<h2 class="blt-cal-title"><?php echo esc_html( date_i18n( 'F Y', $first_ts ) ); ?></h2>
			</div>

			<div class="blt-cal-grid" role="grid">
				<?php for ( $i = 0; $i < 7; $i++ ) : ?>
					<?php $weekday = $wp_locale->get_weekday( ( $start_of_week + $i ) % 7 ); ?>
					<div class="blt-cal-dow" role="columnheader" aria-label="<?php echo esc_attr( $weekday ); ?>">
						<?php echo esc_html( $wp_locale->get_weekday_initial( $weekday ) ); ?>
					</div>
				<?php endfor; ?>

				<?php
				// Leading cells from the previous month (day numbers only).
				$prev_month_days = (int) gmdate( 't', strtotime( $month . '-01 -1 month' ) );
				for ( $i = $lead_days - 1; $i >= 0; $i-- ) {
					printf( '<div class="blt-cal-day is-other-month"><span class="blt-cal-daynum">%d</span></div>', (int) ( $prev_month_days - $i ) );
				}

				for ( $day = 1; $day <= $days_in_month; $day++ ) {
					$date       = $month . '-' . str_pad( (string) $day, 2, '0', STR_PAD_LEFT );
					$is_today   = $date === $today;
					$day_events = $by_day[ $date ] ?? array();
					?>
					<div class="blt-cal-day <?php echo $is_today ? 'is-today' : ''; ?> <?php echo $day_events ? 'has-events' : ''; ?>" role="gridcell">
						<span class="blt-cal-daynum"><?php echo (int) $day; ?></span>
						<?php if ( $day_events ) : ?>
							<ul class="blt-cal-events">
								<?php foreach ( $day_events as $event ) : ?>
									<li class="blt-cal-event">
										<a href="<?php echo esc_url( $event['url'] ); ?>">
											<?php if ( $event['time'] ) : ?>
												<span class="blt-cal-event-time"><?php echo esc_html( $event['time'] ); ?></span>
											<?php endif; ?>
											<span class="blt-cal-event-title"><?php echo esc_html( $event['title'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
					<?php
				}

				// Trailing cells to complete the final week.
				$total_cells = $lead_days + $days_in_month;
				$trailing    = ( 7 - ( $total_cells % 7 ) ) % 7;
				for ( $i = 1; $i <= $trailing; $i++ ) {
					printf( '<div class="blt-cal-day is-other-month"><span class="blt-cal-daynum">%d</span></div>', (int) $i );
				}
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
