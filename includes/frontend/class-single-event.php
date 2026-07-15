<?php
/**
 * BLT Events - Front-End Single Event View
 *
 * Renders the single event layout via the_content: featured image across
 * the top, then a two-column body — main column (category, title,
 * description, collapsible agenda, registration) and a sidebar (date box,
 * register/buy CTA, address or virtual link, map).
 *
 * Markup follows BEM under the `blt-event` block so it folds cleanly into
 * a theme or utility framework (e.g. ACSS). The plugin's own visual styles
 * are self-contained in single-event.css and can be switched off in
 * Settings > General (the "Single Event Styles" toggle), leaving only the
 * structural BEM classes for the site to style.
 *
 * Themes/plugins can add sidebar content (e.g. presenters) via the
 * blt_events_single_sidebar action, and disable the wrapper entirely with
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

	/**
	 * Whether the plugin's self-contained single-event styling should load.
	 */
	public static function styles_enabled() {
		return get_option( 'blt_events_single_styles', '1' ) === '1';
	}

	public static function register_assets() {
		wp_register_style(
			'blt-events-single',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
			array(),
			BLT_EVENTS_VERSION
		);

		if ( is_singular( 'event' ) && self::styles_enabled() ) {
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
		$time_format = get_option( 'time_format', 'g:i a' );

		$event_date = get_post_meta( $event_id, '_blt_event_date', true );
		$end_date   = get_post_meta( $event_id, '_blt_event_end_date', true );
		$start_time = get_post_meta( $event_id, '_blt_event_start_time', true );
		$end_time   = get_post_meta( $event_id, '_blt_event_end_time', true );
		$all_day    = get_post_meta( $event_id, '_blt_event_all_day', true ) === '1';
		$multi_day  = get_post_meta( $event_id, '_blt_multi_day', true ) === '1';
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';

		$date_label = $event_date ? date_i18n( $date_format, strtotime( $event_date ) ) : '';
		if ( $multi_day && $end_date && $end_date !== $event_date ) {
			$date_label .= ' – ' . date_i18n( $date_format, strtotime( $end_date ) );
		}

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

		$has_shortcode = has_shortcode( $event->post_content, 'blt_event_registration' );

		ob_start();
		?>
		<div class="blt-event">
			<?php if ( has_post_thumbnail( $event_id ) ) : ?>
				<div class="blt-event__featured">
					<?php echo get_the_post_thumbnail( $event_id, 'large', array( 'class' => 'blt-event__featured-img' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>

			<div class="blt-event__layout">
				<div class="blt-event__main">
					<?php
					$events_url = BLT_Events_Helpers::events_page_url();
					if ( $events_url ) :
						?>
						<a class="blt-event__back" href="<?php echo esc_url( $events_url ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'All events', 'blt-events' ); ?></a>
					<?php endif; ?>

					<?php self::render_categories( $event_id ); ?>

					<h1 class="blt-event__title"><?php echo esc_html( get_the_title( $event_id ) ); ?></h1>

					<div class="blt-event__description">
						<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-filtered post content. ?>
					</div>

					<?php self::render_agenda( $event_id, $time_format ); ?>

					<?php if ( ! $has_shortcode ) : ?>
						<div class="blt-event__registration" id="blt-event-registration">
							<h2 class="blt-event__section-title"><?php esc_html_e( 'Register', 'blt-events' ); ?></h2>
							<?php echo BLT_Events_Registration_Shortcode::render( array( 'event_id' => $event_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endif; ?>
				</div>

				<aside class="blt-event__sidebar">
					<?php
					self::render_datebox( $event_id, $date_label, $time_label );
					self::render_cta( $event_id );
					self::render_location( $event_id, $event_type );

					/**
					 * Extra sidebar content — presenters, sponsors, etc.
					 *
					 * @param int $event_id
					 */
					do_action( 'blt_events_single_sidebar', $event_id );
					?>
				</aside>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_categories( $event_id ) {
		$terms = get_the_terms( $event_id, 'event_category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}
		?>
		<div class="blt-event__categories">
			<?php foreach ( $terms as $term ) : ?>
				<a class="blt-event__category" href="<?php echo esc_url( (string) get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Collapsible agenda, rendered only when enabled and populated.
	 */
	private static function render_agenda( $event_id, $time_format ) {
		if ( get_post_meta( $event_id, '_blt_agenda_enabled', true ) !== '1' ) {
			return;
		}

		$raw   = get_post_meta( $event_id, '_blt_agenda', true );
		$items = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}
		?>
		<details class="blt-event__agenda" open>
			<summary class="blt-event__agenda-summary">
				<span class="blt-event__section-title"><?php esc_html_e( 'Event Schedule', 'blt-events' ); ?></span>
				<span class="blt-event__agenda-chevron" aria-hidden="true">&#9662;</span>
			</summary>
			<ul class="blt-event__agenda-list">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$label = $item['label'] ?? '';
					$start = $item['start'] ?? '';
					$end   = $item['end'] ?? '';
					if ( $label === '' && $start === '' ) {
						continue;
					}

					$time = '';
					if ( $start !== '' ) {
						$time = date_i18n( $time_format, strtotime( '2000-01-01 ' . $start ) );
						if ( $end !== '' ) {
							$time .= ' - ' . date_i18n( $time_format, strtotime( '2000-01-01 ' . $end ) );
						}
					}
					?>
					<li class="blt-event__agenda-item">
						<?php if ( $time ) : ?>
							<span class="blt-event__agenda-time"><?php echo esc_html( $time ); ?></span>
						<?php endif; ?>
						<span class="blt-event__agenda-label"><?php echo esc_html( $label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function render_datebox( $event_id, $date_label, $time_label ) {
		$event_date = get_post_meta( $event_id, '_blt_event_date', true );
		$day        = $event_date ? date_i18n( 'j', strtotime( $event_date ) ) : '';
		?>
		<div class="blt-event__card blt-event__datebox">
			<div class="blt-event__datebox-head">
				<?php if ( $day !== '' ) : ?>
					<span class="blt-event__datebox-day"><?php echo esc_html( $day ); ?></span>
				<?php endif; ?>
				<span class="blt-event__datebox-meta">
					<span class="blt-event__datebox-label"><?php esc_html_e( 'Event Date', 'blt-events' ); ?></span>
					<span class="blt-event__datebox-value"><?php echo esc_html( $date_label ); ?></span>
				</span>
			</div>
			<?php if ( $time_label ) : ?>
				<p class="blt-event__datebox-time"><?php echo esc_html( $time_label ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Price summary + a CTA that jumps to the registration panel.
	 */
	private static function render_cta( $event_id ) {
		$tickets = BLT_Events_Helpers::get_ticket_types( $event_id );

		$min_price = null;
		$has_paid  = false;
		foreach ( $tickets as $ticket ) {
			$price = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
			if ( $price > 0 ) {
				$has_paid  = true;
				$min_price = ( $min_price === null ) ? $price : min( $min_price, $price );
			}
		}

		$cta_label = $has_paid ? __( 'Buy tickets', 'blt-events' ) : __( 'Register', 'blt-events' );
		?>
		<div class="blt-event__card blt-event__cta">
			<div class="blt-event__cta-price">
				<?php if ( $has_paid && $min_price !== null ) : ?>
					<span class="blt-event__cta-from"><?php esc_html_e( 'FROM', 'blt-events' ); ?></span>
					<span class="blt-event__cta-amount"><?php echo esc_html( BLT_Events_Helpers::format_price( $min_price ) ); ?></span>
				<?php else : ?>
					<span class="blt-event__cta-amount"><?php esc_html_e( 'Free', 'blt-events' ); ?></span>
				<?php endif; ?>
			</div>
			<a class="blt-event__cta-button" href="#blt-event-registration">
				<?php echo esc_html( $cta_label ); ?> <span aria-hidden="true">&rarr;</span>
			</a>
		</div>
		<?php
	}

	/**
	 * Address block for physical events, or a "Virtual" block for online
	 * events. For online/hybrid events, the actual join link is revealed
	 * only to a logged-in visitor with a confirmed registration.
	 */
	private static function render_location( $event_id, $event_type ) {
		$is_online   = in_array( $event_type, array( 'online', 'hybrid' ), true );
		$is_physical = in_array( $event_type, array( 'in-person', 'hybrid' ), true );

		$venue    = get_post_meta( $event_id, '_blt_event_venue', true );
		$location = get_post_meta( $event_id, '_blt_event_location', true );

		if ( $is_physical && ( $venue || $location ) ) {
			$address = trim( $venue . ( $venue && $location ? ', ' : '' ) . $location );
			?>
			<div class="blt-event__card blt-event__address">
				<h3 class="blt-event__card-title"><?php esc_html_e( 'Address', 'blt-events' ); ?></h3>
				<p class="blt-event__address-text"><?php echo esc_html( $address ); ?></p>
				<?php self::render_map( $event_id ); ?>
			</div>
			<?php
		}

		if ( $is_online ) {
			self::render_virtual( $event_id );
		}
	}

	/**
	 * "Virtual" block. Shows the join link to confirmed registrants,
	 * otherwise a note that the link is shared after registration.
	 */
	private static function render_virtual( $event_id ) {
		$online_url = get_post_meta( $event_id, '_blt_event_online_url', true );
		$can_see    = false;

		if ( $online_url && is_user_logged_in() && class_exists( 'BLT_Events_Registrations_DB' ) ) {
			$reg_db  = new BLT_Events_Registrations_DB();
			$can_see = $reg_db->email_confirmed_for_event( wp_get_current_user()->user_email, $event_id );
		}
		?>
		<div class="blt-event__card blt-event__virtual">
			<h3 class="blt-event__card-title"><?php esc_html_e( 'Virtual', 'blt-events' ); ?></h3>
			<?php if ( $can_see ) : ?>
				<p class="blt-event__virtual-text"><?php esc_html_e( 'You are registered. Join here:', 'blt-events' ); ?></p>
				<a class="blt-event__virtual-link" href="<?php echo esc_url( $online_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $online_url ); ?></a>
			<?php else : ?>
				<p class="blt-event__virtual-text"><?php esc_html_e( 'This is a virtual event. The join link is sent to attendees and shown here once your registration is confirmed.', 'blt-events' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the venue map using the provider chosen in Settings. When maps
	 * are off, or the chosen provider's requirements aren't met, nothing is
	 * output — the venue name and address above it still show.
	 */
	private static function render_map( $event_id ) {
		$provider = get_option( 'blt_events_map_provider', 'osm' );
		if ( 'none' === $provider ) {
			return;
		}

		$src = '';

		if ( 'google' === $provider ) {
			$src = self::google_map_src( $event_id );
		} elseif ( 'osm' === $provider ) {
			$src = self::osm_map_src( $event_id );
		}

		if ( '' === $src ) {
			return;
		}
		?>
		<div class="blt-event__map">
			<iframe title="<?php esc_attr_e( 'Event location map', 'blt-events' ); ?>" src="<?php echo esc_url( $src ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
		</div>
		<?php
	}

	/**
	 * OpenStreetMap embed src (needs coordinates).
	 */
	private static function osm_map_src( $event_id ) {
		$lat = get_post_meta( $event_id, '_blt_event_latitude', true );
		$lng = get_post_meta( $event_id, '_blt_event_longitude', true );

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return '';
		}

		$lat  = (float) $lat;
		$lng  = (float) $lng;
		$d    = 0.01; // Bounding-box padding (~1km).
		$bbox = sprintf( '%f,%f,%f,%f', $lng - $d, $lat - $d, $lng + $d, $lat + $d );

		return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode( $bbox ) . '&layer=mapnik&marker=' . rawurlencode( $lat . ',' . $lng );
	}

	/**
	 * Google Maps Embed API src. Uses coordinates when present, otherwise
	 * the venue/address string, so it works even without geocoding. Returns
	 * '' when no API key is configured.
	 */
	private static function google_map_src( $event_id ) {
		$key = trim( (string) get_option( 'blt_events_google_maps_api_key', '' ) );
		if ( '' === $key ) {
			return '';
		}

		$lat = get_post_meta( $event_id, '_blt_event_latitude', true );
		$lng = get_post_meta( $event_id, '_blt_event_longitude', true );

		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$query = $lat . ',' . $lng;
		} else {
			$query = BLT_Events_Helpers::get_event_location_string( $event_id );
		}

		if ( '' === trim( (string) $query ) ) {
			return '';
		}

		return 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode( $key ) . '&q=' . rawurlencode( $query );
	}
}
