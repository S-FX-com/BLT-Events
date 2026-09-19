<?php
/**
 * BLT Events - Calendar Shortcode
 *
 * [blt_events_calendar] - Renders events as a list, a card grid, or a full
 * month calendar with previous/next navigation.
 *
 * Usage:
 *   [blt_events_calendar view="list" category="" limit="12" past="no" switcher="no" featured="no"]
 *   view="list"     - vertical list of event cards (default)
 *   view="grid"     - card grid
 *   view="calendar" - month grid with prev/next month navigation
 *   switcher="yes"  - show a List / Grid / Month toggle so visitors can flip views
 *   featured="yes"  - only events marked "Featured"
 *
 * Item markup lives in templates/calendar/ and can be overridden from the
 * theme; queries can be adjusted with the blt_events_calendar_query_args
 * filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Calendar_Shortcode {

	public static function init() {
		add_shortcode( 'blt_events_calendar', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_blt_filter_events', array( __CLASS__, 'ajax_filter_events' ) );
		add_action( 'wp_ajax_nopriv_blt_filter_events', array( __CLASS__, 'ajax_filter_events' ) );
	}

	/**
	 * Shortcode attribute defaults.
	 *
	 * @return array
	 */
	public static function default_atts() {
		return array(
			'view'     => 'list',
			'category' => '',
			'limit'    => 12,
			'past'     => 'no',
			'switcher' => 'no',
			'featured' => 'no',
		);
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( self::default_atts(), (array) $atts, 'blt_events_calendar' );

		$view = self::resolve_view( $atts );

		if ( BLT_Events_Appearance::styles_enabled() ) {
			wp_enqueue_style( 'blt-events' );
			wp_enqueue_style(
				'blt-events-calendar',
				BLT_EVENTS_PLUGIN_URL . 'assets/css/calendar.css',
				BLT_Events_Appearance::style_deps(),
				BLT_EVENTS_VERSION
			);
		}
		wp_enqueue_script( 'blt-events' );
		wp_enqueue_script(
			'blt-events-calendar',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/calendar.js',
			array( 'jquery', 'blt-events' ),
			BLT_EVENTS_VERSION,
			true
		);
		wp_localize_script( 'blt-events-calendar', 'bltCalendarData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'blt_calendar_filter' ),
		) );

		if ( 'calendar' === $view ) {
			$html = self::render_month_view( $atts );
		} else {
			$html = self::render_list_view( $atts, $view );
		}

		/**
		 * Filter the rendered calendar/listing HTML.
		 *
		 * @param string $html Rendered HTML.
		 * @param string $view list | grid | calendar
		 * @param array  $atts Shortcode attributes.
		 */
		return apply_filters( 'blt_events_calendar_html', $html, $view, $atts );
	}

	/**
	 * Resolve the active view: the shortcode attribute, optionally overridden
	 * by the visitor via ?blt_view= when the switcher is enabled.
	 */
	private static function resolve_view( $atts ) {
		$allowed = array( 'list', 'grid', 'calendar' );

		$view = strtolower( (string) $atts['view'] );
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
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'event_category',
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
				),
			);
		}
	}

	/**
	 * Optional "featured only" meta clause.
	 */
	private static function maybe_add_featured_filter( &$args, $atts ) {
		if ( 'yes' === ( $atts['featured'] ?? 'no' ) ) {
			$args['meta_query'][] = array(
				'key'   => '_blt_featured',
				'value' => '1',
			);
		}
	}

	/**
	 * Final WP_Query arguments for a view, after the site's filters.
	 */
	private static function query_args( $args, $view, $atts ) {
		/**
		 * Filter the WP_Query arguments behind a calendar view.
		 *
		 * @param array  $args WP_Query args.
		 * @param string $view list | grid | calendar
		 * @param array  $atts Shortcode attributes.
		 */
		return apply_filters( 'blt_events_calendar_query_args', $args, $view, $atts );
	}

	/**
	 * Whether an event is marked featured.
	 */
	public static function is_featured( $event_id ) {
		return get_post_meta( $event_id, '_blt_featured', true ) === '1';
	}

	/**
	 * View switcher (List | Grid | Month) shown when switcher="yes".
	 */
	private static function render_switcher( $active ) {
		$base = remove_query_arg( array( 'blt_view', 'blt_month', 'blt_paged' ) );

		$views = self::view_labels();
		?>
		<div class="blt-view-switcher" role="group" aria-label="<?php esc_attr_e( 'Change events view', 'blt-events' ); ?>">
			<?php foreach ( $views as $view => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'blt_view', $view, $base ) ); ?>" class="blt-view-switch <?php echo $active === $view ? 'is-active' : ''; ?>" <?php echo $active === $view ? 'aria-current="true"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function view_labels() {
		/**
		 * Filter the view switcher labels.
		 *
		 * @param array $views Slug => label.
		 */
		return apply_filters( 'blt_events_calendar_views', array(
			'list'     => __( 'List', 'blt-events' ),
			'grid'     => __( 'Grid', 'blt-events' ),
			'calendar' => __( 'Month', 'blt-events' ),
		) );
	}

	/* --------------------------------------------------------------------
	 * List / Grid views
	 * ------------------------------------------------------------------ */

	private static function render_list_view( $atts, $view ) {
		if ( 'grid' === $view ) {
			return self::render_grid_view( $atts );
		}

		return self::render_events_list( $atts );
	}

	/**
	 * The month/day/time meta for an event, resolved once.
	 *
	 * @return array{date:string,end_date:string,start:string,end:string,all_day:bool}
	 */
	private static function event_when( $event_id ) {
		return array(
			'date'     => get_post_meta( $event_id, '_blt_event_date', true ),
			'end_date' => get_post_meta( $event_id, '_blt_event_end_date', true ),
			'start'    => get_post_meta( $event_id, '_blt_event_start_time', true ),
			'end'      => get_post_meta( $event_id, '_blt_event_end_time', true ),
			'all_day'  => get_post_meta( $event_id, '_blt_event_all_day', true ) === '1',
		);
	}

	/**
	 * Build the "July 04 @ 8:00 am - 1:00 pm" style date/time line for the
	 * list view, collapsing multi-day and all-day events sensibly.
	 */
	private static function list_datetime_label( $when, $event_id = 0 ) {
		if ( empty( $when['date'] ) ) {
			return '';
		}

		/**
		 * Filter the date format used for the day part of list rows.
		 *
		 * @param string $format PHP date format. Default 'F j'.
		 */
		$day_format = apply_filters( 'blt_events_list_date_format', 'F j' );

		/**
		 * Filter the separator between date and time in list rows.
		 *
		 * @param string $separator Default ' @ '.
		 */
		$separator = apply_filters( 'blt_events_list_time_separator', ' @ ' );

		$day_label = BLT_Events_Helpers::format_date( $when['date'], $day_format );

		// Multi-day: "July 4 - July 6".
		if ( ! empty( $when['end_date'] ) && $when['end_date'] !== $when['date'] ) {
			$label = $day_label . ' - ' . BLT_Events_Helpers::format_date( $when['end_date'], $day_format );
		} elseif ( $when['all_day'] || empty( $when['start'] ) ) {
			$label = $day_label . $separator . __( 'All Day', 'blt-events' );
		} else {
			$label = $day_label . $separator . BLT_Events_Helpers::format_time( $when['date'], $when['start'] );
			if ( ! empty( $when['end'] ) ) {
				$label .= ' - ' . BLT_Events_Helpers::format_time( $when['date'], $when['end'] );
			}
		}

		/**
		 * Filter the date/time line of a list row.
		 *
		 * @param string $label    The label.
		 * @param array  $when     date, end_date, start, end, all_day.
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_list_datetime_label', $label, $when, $event_id );
	}

	private static function sanitize_limit( $atts ) {
		$limit = intval( $atts['limit'] );
		if ( $limit < 1 || $limit > 100 ) {
			$limit = 12;
		}
		return $limit;
	}

	/**
	 * Restrict the list toolbar to its supported date windows.
	 */
	private static function sanitize_range( $range ) {
		$range = sanitize_key( (string) $range );

		return in_array( $range, array( 'today', 'week', 'month' ), true ) ? $range : 'today';
	}

	/**
	 * Add the date-window constraint used by the list toolbar.
	 */
	private static function maybe_add_range_filter( &$args, $range ) {
		$today = current_time( 'Y-m-d' );

		if ( 'today' === $range ) {
			$args['meta_query'][] = array(
				'key'     => '_blt_event_date',
				'value'   => $today,
				'compare' => '=',
				'type'    => 'DATE',
			);
			return;
		}

		$timestamp = current_time( 'timestamp' );
		$start     = 'week' === $range
			? wp_date( 'Y-m-d', strtotime( 'monday this week', $timestamp ), wp_timezone() )
			: wp_date( 'Y-m-01', $timestamp, wp_timezone() );
		$end       = 'week' === $range
			? wp_date( 'Y-m-d', strtotime( 'sunday this week', $timestamp ), wp_timezone() )
			: wp_date( 'Y-m-t', $timestamp, wp_timezone() );

		$args['meta_query'][] = array(
			'key'     => '_blt_event_date',
			'value'   => array( $start, $end ),
			'compare' => 'BETWEEN',
			'type'    => 'DATE',
		);
	}

	/**
	 * Return updated list results while the visitor searches or changes range.
	 */
	public static function ajax_filter_events() {
		check_ajax_referer( 'blt_calendar_filter', 'nonce' );

		$atts = self::default_atts();
		foreach ( array( 'category', 'featured', 'past' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$atts[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		if ( isset( $_POST['limit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$atts['limit'] = absint( $_POST['limit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$range  = self::sanitize_range( isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'today' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$paged  = isset( $_POST['paged'] ) ? max( 1, absint( wp_unslash( $_POST['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		wp_send_json_success( array(
			'html' => self::render_list_results( $atts, self::sanitize_limit( $atts ), $paged, $search, $range ),
		) );
	}

	/**
	 * The events list view: a search/navigation toolbar above events grouped
	 * under month headers, each row showing the day, date/time, title,
	 * excerpt, location, and featured image.
	 */
	private static function render_events_list( $atts ) {
		$limit  = self::sanitize_limit( $atts );
		$paged  = max( 1, (int) ( $_GET['blt_paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['blt_search'] ) ? sanitize_text_field( wp_unslash( $_GET['blt_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range  = self::sanitize_range( isset( $_GET['blt_range'] ) ? wp_unslash( $_GET['blt_range'] ) : 'today' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return self::render_list_results( $atts, $limit, $paged, $search, $range, true );
	}

	/**
	 * Render the complete list surface or just its result region for AJAX.
	 */
	private static function render_list_results( $atts, $limit, $paged, $search, $range, $with_toolbar = false ) {

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'paged'          => $paged,
			'meta_key'       => '_blt_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_type'      => 'DATE',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array( self::visibility_meta_query() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( $search !== '' ) {
			$args['s'] = $search;
		}

		if ( $atts['past'] !== 'yes' ) {
			$args['meta_query'][] = array(
				'key'     => '_blt_event_date',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}
		self::maybe_add_range_filter( $args, $range );

		self::maybe_add_category_filter( $args, $atts );
		self::maybe_add_featured_filter( $args, $atts );

		$query = new WP_Query( self::query_args( $args, 'list', $atts ) );

		ob_start();
		?>
		<?php if ( $with_toolbar ) : ?>
		<div class="blt-events-calendar blt-events-listing" data-category="<?php echo esc_attr( $atts['category'] ); ?>" data-featured="<?php echo esc_attr( $atts['featured'] ); ?>" data-past="<?php echo esc_attr( $atts['past'] ); ?>" data-limit="<?php echo esc_attr( $limit ); ?>">
			<?php self::render_list_toolbar( $atts, $query, $paged, $search, $range ); ?>
			<div class="blt-list-results" aria-live="polite">
		<?php endif; ?>

			<?php if ( ! $query->have_posts() ) : ?>
				<?php
				BLT_Events_Templates::include_template( 'calendar/empty.php', array(
					'view'    => 'list',
					'message' => $search !== '' ? __( 'No events match your search.', 'blt-events' ) : __( 'No upcoming events found.', 'blt-events' ),
					'search'  => $search,
				) );
				?>
			<?php else : ?>
				<div class="blt-list-events">
					<?php
					$current_month = '';
					while ( $query->have_posts() ) :
						$query->the_post();
						$event_id = get_the_ID();
						$when     = self::event_when( $event_id );

						if ( empty( $when['date'] ) ) {
							continue;
						}

						$month_key = BLT_Events_Helpers::format_date( $when['date'], 'Y-m' );
						if ( $month_key !== $current_month ) {
							$current_month = $month_key;
							?>
							<div class="blt-list-month">
								<h2><?php echo esc_html( BLT_Events_Helpers::format_date( $when['date'], 'F Y' ) ); ?></h2>
							</div>
							<?php
						}

						BLT_Events_Templates::include_template( 'calendar/list-item.php', array(
							'event_id'       => $event_id,
							'permalink'      => get_permalink(),
							'title'          => get_the_title(),
							'excerpt'        => has_excerpt() ? get_the_excerpt() : '',
							'when'           => $when,
							'datetime_label' => self::list_datetime_label( $when, $event_id ),
							'day'            => BLT_Events_Helpers::format_date( $when['date'], 'j' ),
							'weekday'        => strtoupper( BLT_Events_Helpers::format_date( $when['date'], 'D' ) ),
							'venue'          => BLT_Events_Helpers::get_event_location_string( $event_id ),
							'thumbnail'      => has_post_thumbnail() ? get_the_post_thumbnail( $event_id, 'medium_large' ) : '',
							'is_featured'    => self::is_featured( $event_id ),
							'pin_icon'       => self::pin_icon(),
						) );
					endwhile;
					?>
				</div>
			<?php endif; ?>
		<?php if ( $with_toolbar ) : ?>
			</div>
		</div>
		<?php endif; ?>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Toolbar for the list view: previous/next paging, "Today", an optional
	 * view menu, and a keyword search box.
	 */
	private static function render_list_toolbar( $atts, $query, $paged, $search, $range ) {
		$base       = remove_query_arg( array( 'blt_paged', 'blt_search', 'blt_range', 'blt_view', 'blt_month' ) );
		$max_pages  = (int) $query->max_num_pages;

		$context   = add_query_arg( 'blt_range', $range, $base );
		$context   = $search !== '' ? add_query_arg( 'blt_search', $search, $context ) : $context;
		$prev_url  = $paged > 1 ? add_query_arg( 'blt_paged', $paged - 1, $context ) : '';
		$next_url  = $paged < $max_pages ? add_query_arg( 'blt_paged', $paged + 1, $context ) : '';
		?>
		<div class="blt-list-toolbar">
			<div class="blt-list-nav">
				<a class="blt-list-navbtn <?php echo $prev_url ? '' : 'is-disabled'; ?>" href="<?php echo esc_url( $prev_url ?: '#' ); ?>" aria-label="<?php esc_attr_e( 'Previous events', 'blt-events' ); ?>"<?php echo $prev_url ? '' : ' aria-disabled="true"'; ?>>&lsaquo;</a>
				<a class="blt-list-navbtn <?php echo $next_url ? '' : 'is-disabled'; ?>" href="<?php echo esc_url( $next_url ?: '#' ); ?>" aria-label="<?php esc_attr_e( 'Next events', 'blt-events' ); ?>"<?php echo $next_url ? '' : ' aria-disabled="true"'; ?>>&rsaquo;</a>
				<label class="blt-list-range">
					<span class="screen-reader-text"><?php esc_html_e( 'Event date range', 'blt-events' ); ?></span>
					<select name="blt_range">
						<option value="today" <?php selected( $range, 'today' ); ?>><?php esc_html_e( 'Today', 'blt-events' ); ?></option>
						<option value="week" <?php selected( $range, 'week' ); ?>><?php esc_html_e( 'This Week', 'blt-events' ); ?></option>
						<option value="month" <?php selected( $range, 'month' ); ?>><?php esc_html_e( 'This Month', 'blt-events' ); ?></option>
					</select>
				</label>
				<?php if ( 'yes' === $atts['switcher'] ) : ?>
					<?php self::render_view_menu( 'list' ); ?>
				<?php endif; ?>
			</div>

			<form class="blt-list-search" method="get" role="search">
				<?php
				// Preserve non-paging query context across the search submit.
				foreach ( array( 'page_id', 'p', 'blt_view' ) as $keep ) {
					if ( isset( $_GET[ $keep ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $keep ), esc_attr( sanitize_text_field( wp_unslash( $_GET[ $keep ] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					}
				}
				?>
				<input type="hidden" name="blt_range" value="<?php echo esc_attr( $range ); ?>" />
				<label class="blt-list-search-field">
					<?php echo self::search_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Search for events', 'blt-events' ); ?></span>
					<input type="search" name="blt_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search for events', 'blt-events' ); ?>" />
				</label>
				<button type="submit" class="blt-list-find"><?php esc_html_e( 'Find events', 'blt-events' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * A compact "view" dropdown (List / Grid / Month) used inside the list
	 * toolbar. Uses a native <details> element so it needs no JavaScript.
	 */
	private static function render_view_menu( $active ) {
		$base  = remove_query_arg( array( 'blt_view', 'blt_month', 'blt_paged' ) );
		$views = self::view_labels();
		?>
		<details class="blt-view-menu">
			<summary aria-label="<?php esc_attr_e( 'Change view', 'blt-events' ); ?>"><span class="blt-view-menu-chevron" aria-hidden="true">&#9662;</span></summary>
			<ul>
				<?php foreach ( $views as $view => $label ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( 'blt_view', $view, $base ) ); ?>" class="<?php echo $active === $view ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function pin_icon() {
		return '<svg class="blt-list-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
	}

	private static function search_icon() {
		return '<svg class="blt-list-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
	}

	/**
	 * Human price label for a card: "Free", "$25.00", "Free - $50.00", "$25.00 - $50.00".
	 */
	public static function price_label( $event_id ) {
		$range = BLT_Events_Helpers::ticket_price_range( $event_id );

		if ( $range['max'] <= 0 ) {
			$label = __( 'Free', 'blt-events' );
		} elseif ( $range['min'] === $range['max'] ) {
			$label = BLT_Events_Helpers::format_price( $range['min'] );
		} elseif ( $range['min'] <= 0 ) {
			/* translators: %s: highest ticket price. */
			$label = sprintf( __( 'Free - %s', 'blt-events' ), BLT_Events_Helpers::format_price( $range['max'] ) );
		} else {
			$label = BLT_Events_Helpers::format_price( $range['min'] ) . ' - ' . BLT_Events_Helpers::format_price( $range['max'] );
		}

		/**
		 * Filter the price label shown on event cards.
		 *
		 * @param string $label    The label.
		 * @param array  $range    min, max, has_paid, count.
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_price_label', $label, $range, $event_id );
	}

	/**
	 * The card-grid view.
	 */
	private static function render_grid_view( $atts ) {
		$limit = self::sanitize_limit( $atts );

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_key'       => '_blt_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_type'      => 'DATE',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( self::visibility_meta_query() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( $atts['past'] !== 'yes' ) {
			$args['meta_query'][] = array(
				'key'     => '_blt_event_date',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		self::maybe_add_category_filter( $args, $atts );
		self::maybe_add_featured_filter( $args, $atts );

		$query = new WP_Query( self::query_args( $args, 'grid', $atts ) );

		ob_start();

		if ( 'yes' === $atts['switcher'] ) {
			self::render_switcher( 'grid' );
		}

		if ( ! $query->have_posts() ) {
			BLT_Events_Templates::include_template( 'calendar/empty.php', array(
				'view'    => 'grid',
				'message' => __( 'No upcoming events found.', 'blt-events' ),
				'search'  => '',
			) );
			wp_reset_postdata();
			return ob_get_clean();
		}

		$type_labels = array(
			'online'    => __( 'Online', 'blt-events' ),
			'in-person' => __( 'In-Person', 'blt-events' ),
			'hybrid'    => __( 'Hybrid', 'blt-events' ),
		);
		?>
		<div class="blt-events-calendar blt-events-grid">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$event_id   = get_the_ID();
				$when       = self::event_when( $event_id );
				$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';
				$range      = BLT_Events_Helpers::ticket_price_range( $event_id );

				BLT_Events_Templates::include_template( 'calendar/grid-card.php', array(
					'event_id'       => $event_id,
					'permalink'      => get_permalink(),
					'title'          => get_the_title(),
					'excerpt'        => has_excerpt() ? get_the_excerpt() : '',
					'thumbnail'      => has_post_thumbnail() ? get_the_post_thumbnail( $event_id, 'medium' ) : '',
					'when'           => $when,
					'date_month'     => $when['date'] ? BLT_Events_Helpers::format_date( $when['date'], 'M' ) : '',
					'date_day'       => $when['date'] ? BLT_Events_Helpers::format_date( $when['date'], 'j' ) : '',
					'formatted_date' => BLT_Events_Helpers::event_date_label( $event_id ),
					'time_display'   => BLT_Events_Helpers::event_time_label( $event_id ),
					'venue'          => get_post_meta( $event_id, '_blt_event_venue', true ),
					'event_type'     => $event_type,
					'type_label'     => $type_labels[ $event_type ] ?? $type_labels['in-person'],
					'price_label'    => self::price_label( $event_id ),
					'is_free'        => $range['max'] <= 0,
					'is_featured'    => self::is_featured( $event_id ),
					'cta_label'      => $range['has_paid'] ? __( 'Buy tickets', 'blt-events' ) : __( 'Register', 'blt-events' ),
				) );
			endwhile;
			?>
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
			'meta_key'       => '_blt_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_type'      => 'DATE',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				self::visibility_meta_query(),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_blt_event_date',
						'value'   => array( $first_day, $last_day ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
					// Multi-day events that started before this month but run into it.
					array(
						'key'     => '_blt_event_end_date',
						'value'   => array( $first_day, $last_day ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			),
		);

		self::maybe_add_category_filter( $args, $atts );
		self::maybe_add_featured_filter( $args, $atts );

		$query = new WP_Query( self::query_args( $args, 'calendar', $atts ) );

		// Bucket events by day, sorted by start time within the day.
		$by_day = array();

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
					: BLT_Events_Helpers::format_time( $day_date, $day_start );

				$by_day[ $day_date ][] = array(
					'event_id'    => $event_id,
					'title'       => get_the_title(),
					'url'         => get_permalink(),
					'time'        => $time_label,
					'sort_key'    => $day_start === '' ? '00:00' : $day_start,
					'is_featured' => self::is_featured( $event_id ),
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
									<?php BLT_Events_Templates::include_template( 'calendar/month-event.php', $event ); ?>
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
