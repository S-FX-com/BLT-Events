<?php
/**
 * BLT Events - Front-End Single Event View
 *
 * Renders the single event layout via the_content: a 16:9 featured image
 * with a "Back to events" button, then a two-column body. The main column
 * holds the category chips, title, description, agenda accordion, sponsors,
 * location map and registration; the sticky event card beside it holds the
 * date, the event facts (time, place, online, price, calendar links), the
 * speakers and the register button.
 *
 * The HTML lives in templates/single-event.php and templates/single/*.php,
 * so a theme can override any part (see BLT_Events_Templates). Markup
 * follows BEM under the `blt-event` block so it folds cleanly into a theme
 * or utility framework (e.g. ACSS).
 *
 * Settings > Appearance controls whether the plugin prints the title and
 * featured image, and the blt_events_single_show_* filters do the same
 * from code.
 *
 * Themes/plugins can add sidebar content (e.g. presenters) via the
 * blt_events_single_sidebar action, and disable the wrapper entirely with
 * the blt_events_render_single filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Single_Event {

	const OPTION_SHOW_TITLE    = 'blt_events_single_show_title';
	const OPTION_SHOW_FEATURED = 'blt_events_single_show_featured';
	const OPTION_SHOW_BACK     = 'blt_events_single_show_back';
	const OPTION_SHOW_CALENDAR = 'blt_events_single_show_calendar_links';

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 10 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		// Public .ics download for a single event.
		add_action( 'admin_post_blt_event_ics', array( __CLASS__, 'download_ics' ) );
		add_action( 'admin_post_nopriv_blt_event_ics', array( __CLASS__, 'download_ics' ) );

		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Whether the plugin's single-event styling should load.
	 *
	 * Delegates to the site-wide styling mode so the event page, the calendar
	 * and the registration form can never end up half-styled against each
	 * other. Kept as a method because themes and add-ons call it.
	 */
	public static function styles_enabled() {
		return BLT_Events_Appearance::styles_enabled();
	}

	public static function register_assets() {
		// Behaviour, not styling (the card's fit-to-screen sticky and the
		// sponsor lightbox), so it loads in every styling mode.
		wp_register_script(
			'blt-events-single',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/single-event.js',
			array(),
			BLT_EVENTS_VERSION,
			true
		);
		wp_localize_script( 'blt-events-single', 'bltEventsLightbox', array(
			'close'  => __( 'Close', 'blt-events' ),
			'prev'   => __( 'Previous image', 'blt-events' ),
			'next'   => __( 'Next image', 'blt-events' ),
			'viewer' => __( 'Image viewer', 'blt-events' ),
		) );

		if ( ! self::styles_enabled() ) {
			return;
		}

		wp_register_style(
			'blt-events-single',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
			BLT_Events_Appearance::style_deps(),
			BLT_EVENTS_VERSION
		);

		if ( is_singular( 'event' ) ) {
			wp_enqueue_style( 'blt-events-single' );
		}
	}

	public static function body_class( $classes ) {
		if ( is_singular( 'event' ) ) {
			$classes[] = 'blt-single-event';
			$classes[] = 'blt-event-type-' . sanitize_html_class( get_post_meta( get_the_ID(), '_blt_event_type', true ) ?: 'in-person' );
		}
		return $classes;
	}

	/**
	 * Serve the event's calendar invite as an .ics download.
	 */
	public static function download_ics() {
		$event_id = absint( $_GET['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	 * Display toggles
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the plugin prints its own H1 on the event page.
	 *
	 * On by default so the standalone event layout contains the event's
	 * essential heading. Sites that print a theme title above the content can
	 * still disable it in Appearance.
	 */
	public static function show_title( $event_id ) {
		$show = '1' === (string) get_option( self::OPTION_SHOW_TITLE, '1' );

		/**
		 * Filter whether the plugin prints the event title on the single page.
		 *
		 * @param bool $show
		 * @param int  $event_id
		 */
		return (bool) apply_filters( 'blt_events_single_show_title', $show, $event_id );
	}

	public static function show_featured( $event_id ) {
		$show = '1' === (string) get_option( self::OPTION_SHOW_FEATURED, '1' );

		/**
		 * Filter whether the plugin prints the featured image on the single page.
		 *
		 * @param bool $show
		 * @param int  $event_id
		 */
		return (bool) apply_filters( 'blt_events_single_show_featured', $show, $event_id );
	}

	public static function show_back_link( $event_id ) {
		$show = '1' === (string) get_option( self::OPTION_SHOW_BACK, '1' );

		/**
		 * Filter whether the "All events" back link is printed.
		 *
		 * @param bool $show
		 * @param int  $event_id
		 */
		return (bool) apply_filters( 'blt_events_single_show_back', $show, $event_id );
	}

	public static function show_calendar_links( $event_id ) {
		$show = '1' === (string) get_option( self::OPTION_SHOW_CALENDAR, '1' );

		/**
		 * Filter whether the "Add to calendar" links are printed in the date box.
		 *
		 * @param bool $show
		 * @param int  $event_id
		 */
		return (bool) apply_filters( 'blt_events_single_show_calendar_links', $show, $event_id );
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	/**
	 * Everything the single-event templates need, in one array.
	 *
	 * @param WP_Post $event   The event.
	 * @param string  $content Filtered post content (the description).
	 * @return array
	 */
	public static function view_data( $event, $content = '' ) {
		$event_id   = $event->ID;
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';
		$event_date = get_post_meta( $event_id, '_blt_event_date', true );
		$range      = BLT_Events_Helpers::ticket_price_range( $event_id );
		$online_url = get_post_meta( $event_id, '_blt_event_online_url', true );

		$can_see_link = false;
		if ( $online_url && is_user_logged_in() ) {
			$reg_db       = new BLT_Events_Registrations_DB();
			$can_see_link = $reg_db->email_confirmed_for_event( wp_get_current_user()->user_email, $event_id );
		}

		/**
		 * Filter whether the current visitor may see the online join link.
		 *
		 * @param bool $can_see  Default: logged in with a confirmed registration.
		 * @param int  $event_id The event post ID.
		 */
		$can_see_link = (bool) apply_filters( 'blt_events_can_see_online_url', $can_see_link, $event_id );

		$terms = get_the_terms( $event_id, 'event_category' );

		$has_shortcode = has_shortcode( $event->post_content, 'blt_event_registration' ) || has_block( 'blt-events/registration-form', $event );
		$show_featured = self::show_featured( $event_id ) && has_post_thumbnail( $event_id );
		$featured      = has_post_thumbnail( $event_id ) ? get_the_post_thumbnail( $event_id, 'large', array( 'class' => 'blt-event__featured-img' ) ) : '';

		$data = array(
			'event'              => $event,
			'event_id'           => $event_id,
			'description'        => $content,
			'title'              => get_the_title( $event_id ),
			'event_type'         => $event_type,
			'is_online'          => in_array( $event_type, array( 'online', 'hybrid' ), true ),
			'is_physical'        => in_array( $event_type, array( 'in-person', 'hybrid' ), true ),
			'show_title'         => self::show_title( $event_id ),
			'show_featured'      => $show_featured,
			'has_image'          => $show_featured && '' !== $featured,
			'show_back'          => self::show_back_link( $event_id ),
			'show_calendar'      => self::show_calendar_links( $event_id ),
			'featured_image'     => $featured,
			'events_url'         => BLT_Events_Helpers::events_page_url(),
			'categories'         => ( empty( $terms ) || is_wp_error( $terms ) ) ? array() : $terms,
			'date_label'         => BLT_Events_Helpers::event_date_label( $event_id ),
			'time_label'         => BLT_Events_Helpers::event_time_label( $event_id ),
			'day'                => $event_date ? BLT_Events_Helpers::format_date( $event_date, 'j' ) : '',
			'event_date'         => $event_date ? (string) $event_date : '',
			// Only an excerpt saved before events dropped the Excerpt box;
			// never core's auto-excerpt, which would repeat the description.
			'excerpt'            => trim( wp_strip_all_tags( (string) $event->post_excerpt ) ),
			'ics_url'            => BLT_Events_Helpers::get_ics_url( $event_id ),
			'google_url'         => BLT_Events_Helpers::get_google_calendar_url( $event ),
			'agenda'             => self::agenda_items( $event_id ),
			'sponsors'           => self::sponsor_items( $event_id ),
			'has_shortcode'      => $has_shortcode,
			'registration_open'  => get_post_meta( $event_id, '_blt_registration_open', true ) === '1',
			'has_paid'           => $range['has_paid'],
			'price_from'         => $range['has_paid'] ? BLT_Events_Helpers::format_price( self::lowest_paid_price( $event_id ) ) : '',
			'cta_label'          => apply_filters( 'blt_events_cta_label', $range['has_paid'] ? __( 'Buy tickets', 'blt-events' ) : __( 'Register', 'blt-events' ), $event_id, $range ),
			// When the form sits inside the description there is no
			// registration panel, so point at the form itself.
			'cta_url'            => $has_shortcode ? '#blt-registration-' . $event_id : '#blt-event-registration',
			'spots_left'         => BLT_Events_Helpers::spots_left( $event_id ),
			'is_sold_out'        => BLT_Events_Helpers::is_sold_out( $event_id ),
			'address'            => BLT_Events_Helpers::get_event_address( $event_id ),
			'map_src'            => self::map_src( $event_id ),
			'online_url'         => $online_url,
			'can_see_online_url' => $can_see_link,
		);

		/**
		 * Filter the data handed to the single event templates.
		 *
		 * @param array   $data  Template data.
		 * @param WP_Post $event The event.
		 */
		$data = apply_filters( 'blt_events_single_view_data', $data, $event );

		// Derived after the filter, so hiding the image there (show_featured
		// or featured_image) also drops the hero and the card overlap.
		$data['has_image'] = ! empty( $data['show_featured'] ) && ! empty( $data['featured_image'] );

		return $data;
	}

	/**
	 * Lowest non-zero ticket price.
	 */
	private static function lowest_paid_price( $event_id ) {
		$min = null;
		foreach ( BLT_Events_Helpers::get_ticket_types( $event_id ) as $ticket ) {
			$price = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
			if ( $price > 0 ) {
				$min = ( null === $min ) ? $price : min( $min, $price );
			}
		}
		return (float) $min;
	}

	/**
	 * Agenda rows with formatted times, or an empty array when disabled.
	 */
	private static function agenda_items( $event_id ) {
		if ( get_post_meta( $event_id, '_blt_agenda_enabled', true ) !== '1' ) {
			return array();
		}

		$raw   = get_post_meta( $event_id, '_blt_agenda', true );
		$items = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		$date = get_post_meta( $event_id, '_blt_event_date', true ) ?: current_time( 'Y-m-d' );
		$out  = array();

		foreach ( $items as $item ) {
			$label = $item['label'] ?? '';
			$start = $item['start'] ?? '';
			$end   = $item['end'] ?? '';
			if ( $label === '' && $start === '' ) {
				continue;
			}

			$time = '';
			if ( $start !== '' ) {
				$time = BLT_Events_Helpers::format_time( $date, $start );
				if ( $end !== '' ) {
					$time .= ' - ' . BLT_Events_Helpers::format_time( $date, $end );
				}
			}

			$out[] = array(
				'label' => $label,
				'start' => $start,
				'end'   => $end,
				'time'  => $time,
			);
		}

		return $out;
	}

	/**
	 * Sponsor logos for the event page, or an empty array when the section
	 * is switched off.
	 *
	 * @param int $event_id The event post ID.
	 * @return array Rows with id, url (sponsor link, may be ''), full (image URL), alt.
	 */
	private static function sponsor_items( $event_id ) {
		$items = array();

		if ( get_post_meta( $event_id, '_blt_sponsors_enabled', true ) === '1' ) {
			$raw  = get_post_meta( $event_id, '_blt_sponsors', true );
			$rows = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$image_id = absint( $row['image_id'] ?? 0 );
				$full     = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
				if ( ! $full ) {
					continue;
				}

				$alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );

				$items[] = array(
					'id'   => $image_id,
					'url'  => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
					'full' => $full,
					'alt'  => '' !== $alt ? $alt : get_the_title( $image_id ),
				);
			}
		}

		/**
		 * Filter the sponsor logos shown on an event page.
		 *
		 * Runs even when the section is off, so code can supply sponsors
		 * from elsewhere.
		 *
		 * @param array $items    Rows with id (attachment ID), url (link, may be ''), full (image URL), alt.
		 * @param int   $event_id The event post ID.
		 */
		return (array) apply_filters( 'blt_events_sponsors', $items, $event_id );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function render_single( $event, $content ) {
		wp_enqueue_script( 'blt-events-single' );

		return BLT_Events_Templates::render( 'single-event.php', self::view_data( $event, $content ) );
	}

	/**
	 * Map iframe src for the venue using the provider chosen in Settings.
	 * Empty when maps are off or the provider's requirements aren't met.
	 */
	private static function map_src( $event_id ) {
		if ( ! in_array( get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person', array( 'in-person', 'hybrid' ), true ) ) {
			return '';
		}

		$provider = get_option( 'blt_events_map_provider', 'osm' );
		if ( 'none' === $provider ) {
			return '';
		}

		$src = '';

		if ( 'google' === $provider ) {
			$src = self::google_map_src( $event_id );
		} elseif ( 'osm' === $provider ) {
			$src = self::osm_map_src( $event_id );
		}

		/**
		 * Filter the map iframe URL for an event.
		 *
		 * @param string $src      The URL, or '' for no map.
		 * @param int    $event_id The event post ID.
		 * @param string $provider osm | google | none
		 */
		return (string) apply_filters( 'blt_events_map_src', $src, $event_id, $provider );
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

		// Shared BLT family store (opt-in, off by default). This is a
		// referrer-restricted BROWSER key for the Maps Embed API — it ends up
		// in front-end HTML, which is what its HTTP-referrer restriction is
		// there for. It is deliberately NOT interchangeable with
		// google.youtube_api_key, which is an IP-restricted server key for the
		// YouTube Data API; never cross-feed the two.
		if ( '' === $key && class_exists( 'BLT_Family' ) ) {
			$key = trim( (string) BLT_Family::get( 'blt-events', 'google', 'maps_api_key' ) );
		}

		if ( '' === $key ) {
			return '';
		}

		$lat = get_post_meta( $event_id, '_blt_event_latitude', true );
		$lng = get_post_meta( $event_id, '_blt_event_longitude', true );

		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$query = $lat . ',' . $lng;
		} else {
			$query = BLT_Events_Helpers::get_event_address( $event_id );
		}

		if ( '' === trim( (string) $query ) ) {
			return '';
		}

		return 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode( $key ) . '&q=' . rawurlencode( $query );
	}
}
