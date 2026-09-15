<?php
/**
 * BLT Events - Block editor blocks
 *
 * Two dynamic blocks that wrap the shortcodes, so a site editor can drop
 * the calendar or a registration form into any page from the inserter with
 * real controls instead of remembering shortcode attributes:
 *
 *   blt-events/calendar          -> [blt_events_calendar]
 *   blt-events/registration-form -> [blt_event_registration]
 *
 * Both render server-side, so their output is identical to the shortcodes
 * and picks up the same templates, tokens and hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Blocks {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'block_categories_all', array( __CLASS__, 'category' ) );
	}

	public static function category( $categories ) {
		foreach ( $categories as $category ) {
			if ( 'blt-events' === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}

		array_unshift( $categories, array(
			'slug'  => 'blt-events',
			'title' => __( 'BLT Events', 'blt-events' ),
			'icon'  => 'calendar-alt',
		) );

		return $categories;
	}

	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'blt-events-blocks',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-data' ),
			BLT_EVENTS_VERSION,
			true
		);

		wp_set_script_translations( 'blt-events-blocks', 'blt-events', BLT_EVENTS_PLUGIN_DIR . 'languages' );

		wp_localize_script( 'blt-events-blocks', 'bltEventsBlocks', array(
			'categories' => self::category_options(),
		) );

		// The front-end stylesheets, so the editor preview looks like the site.
		wp_register_style(
			'blt-events-blocks-editor',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/blocks-editor.css',
			array(),
			BLT_EVENTS_VERSION
		);

		register_block_type( 'blt-events/calendar', array(
			'api_version'     => 2,
			'title'           => __( 'Events Calendar', 'blt-events' ),
			'description'     => __( 'Your events as a list, a card grid or a month calendar.', 'blt-events' ),
			'category'        => 'blt-events',
			'icon'            => 'calendar-alt',
			'keywords'        => array( 'events', 'calendar', 'blt' ),
			'editor_script'   => 'blt-events-blocks',
			'editor_style'    => 'blt-events-blocks-editor',
			'render_callback' => array( __CLASS__, 'render_calendar' ),
			'supports'        => array(
				'align' => array( 'wide', 'full' ),
				'html'  => false,
			),
			'attributes'      => array(
				'view'     => array( 'type' => 'string', 'default' => 'list' ),
				'category' => array( 'type' => 'string', 'default' => '' ),
				'limit'    => array( 'type' => 'number', 'default' => 12 ),
				'past'     => array( 'type' => 'boolean', 'default' => false ),
				'switcher' => array( 'type' => 'boolean', 'default' => false ),
				'featured' => array( 'type' => 'boolean', 'default' => false ),
				'align'    => array( 'type' => 'string' ),
			),
		) );

		register_block_type( 'blt-events/registration-form', array(
			'api_version'     => 2,
			'title'           => __( 'Event Registration Form', 'blt-events' ),
			'description'     => __( 'The registration form for one event: tickets, attendee details, coupons and payment.', 'blt-events' ),
			'category'        => 'blt-events',
			'icon'            => 'tickets-alt',
			'keywords'        => array( 'registration', 'tickets', 'event', 'blt' ),
			'editor_script'   => 'blt-events-blocks',
			'editor_style'    => 'blt-events-blocks-editor',
			'render_callback' => array( __CLASS__, 'render_registration' ),
			'supports'        => array( 'html' => false ),
			'attributes'      => array(
				'eventId' => array( 'type' => 'number', 'default' => 0 ),
			),
		) );
	}

	/**
	 * Event categories for the block inspector.
	 */
	private static function category_options() {
		$terms = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
			'number'     => 200,
		) );

		$options = array( array( 'value' => '', 'label' => __( 'All categories', 'blt-events' ) ) );

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[] = array( 'value' => $term->slug, 'label' => $term->name );
			}
		}

		return $options;
	}

	public static function render_calendar( $attributes ) {
		$atts = array(
			'view'     => sanitize_key( $attributes['view'] ?? 'list' ),
			'category' => sanitize_text_field( $attributes['category'] ?? '' ),
			'limit'    => (int) ( $attributes['limit'] ?? 12 ),
			'past'     => ! empty( $attributes['past'] ) ? 'yes' : 'no',
			'switcher' => ! empty( $attributes['switcher'] ) ? 'yes' : 'no',
			'featured' => ! empty( $attributes['featured'] ) ? 'yes' : 'no',
		);

		$classes = array( 'wp-block-blt-events-calendar' );
		if ( ! empty( $attributes['align'] ) ) {
			$classes[] = 'align' . sanitize_html_class( $attributes['align'] );
		}

		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . BLT_Events_Calendar_Shortcode::render( $atts ) . '</div>';
	}

	public static function render_registration( $attributes ) {
		$event_id = absint( $attributes['eventId'] ?? 0 );

		if ( ! $event_id && ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			return '<div class="wp-block-blt-events-registration-form blt-block-placeholder"><p>' . esc_html__( 'Choose an event in the block settings.', 'blt-events' ) . '</p></div>';
		}

		return '<div class="wp-block-blt-events-registration-form">' . BLT_Events_Registration_Shortcode::render( array( 'event_id' => $event_id ) ) . '</div>';
	}
}
