<?php
/**
 * BLT Events - Page builder dynamic tags
 *
 * Lets a page builder template print event data without a shortcode:
 *
 *   {blt_event_date}     - the event's start date, in the plugin's date format
 *   {blt_event_location} - the full address of an in-person event, or just
 *                          "Online" / "Hybrid" for those event types so a long
 *                          join URL never overflows the element it sits in
 *
 * The tags resolve against the post the builder is rendering (the current
 * event, or the event a query loop is on) and print nothing on any other post
 * type. The tag list and the value rules are builder-agnostic; each builder is
 * a thin adapter around them. Bricks is wired up below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Dynamic_Tags {

	/**
	 * Tag name (no braces) => the method that produces its value.
	 */
	const TAGS = array(
		'blt_event_date'     => 'event_date',
		'blt_event_location' => 'event_location',
	);

	public static function init() {
		// Bricks. Registered unconditionally: the theme loads after plugins, so
		// BRICKS_VERSION is not defined yet, and the filters never fire without it.
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'bricks_tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'bricks_render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'bricks_render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'bricks_render_content' ), 20, 3 );
	}

	/**
	 * Picker labels, by tag name.
	 *
	 * @return array<string,string>
	 */
	private static function labels() {
		return array(
			'blt_event_date'     => __( 'Event start date', 'blt-events' ),
			'blt_event_location' => __( 'Event location', 'blt-events' ),
		);
	}

	/**
	 * Plain-text value of a tag for a post.
	 *
	 * @param string $name Tag name, without braces.
	 * @param mixed  $post The post the builder is rendering (WP_Post, ID or
	 *                     null for the global post).
	 * @return string|null The value ('' when the post isn't an event or has
	 *                     nothing to show), or null when the tag isn't ours.
	 */
	public static function value( $name, $post = null ) {
		if ( ! isset( self::TAGS[ $name ] ) ) {
			return null;
		}

		// Builders pass a WP_Post, an ID or nothing; anything else falls back to
		// the global post rather than being mistaken for an ID.
		$post = get_post( is_object( $post ) || is_numeric( $post ) ? $post : null );
		if ( ! $post || 'event' !== $post->post_type ) {
			return '';
		}

		$value = (string) call_user_func( array( __CLASS__, self::TAGS[ $name ] ), $post->ID );

		/**
		 * Filter the text a builder dynamic tag prints for an event.
		 *
		 * @param string $value    Plain-text value (escaped by the builder adapter).
		 * @param string $name     Tag name without braces, e.g. blt_event_date.
		 * @param int    $event_id The event post ID.
		 */
		return (string) apply_filters( 'blt_events_dynamic_tag_value', $value, $name, $post->ID );
	}

	/**
	 * The event's start date in the plugin's date format. Deliberately not
	 * BLT_Events_Helpers::event_date_label(), which adds the end date of a
	 * multi-day event.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function event_date( $event_id ) {
		return BLT_Events_Helpers::format_date( (string) get_post_meta( $event_id, '_blt_event_date', true ) );
	}

	/**
	 * The street address for an in-person event; just the type label for
	 * online and hybrid events.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function event_location( $event_id ) {
		$type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';

		if ( 'online' === $type ) {
			return __( 'Online', 'blt-events' );
		}
		if ( 'hybrid' === $type ) {
			return __( 'Hybrid', 'blt-events' );
		}

		return BLT_Events_Helpers::get_event_address( $event_id );
	}

	/* --------------------------------------------------------------------
	 * Bricks
	 * ------------------------------------------------------------------ */

	/**
	 * Add the tags to Bricks' dynamic data picker.
	 *
	 * @param array $tags Registered tags.
	 * @return array
	 */
	public static function bricks_tags_list( $tags ) {
		foreach ( self::labels() as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => __( 'BLT Events', 'blt-events' ),
			);
		}

		return $tags;
	}

	/**
	 * Render one tag. Anything that isn't ours is handed back untouched so
	 * Bricks and other providers can resolve it.
	 *
	 * @param string $tag     Tag including braces, e.g. {blt_event_date}.
	 * @param mixed  $post    The post being rendered.
	 * @param string $context text | link | image | video | media.
	 * @return mixed
	 */
	public static function bricks_render_tag( $tag, $post = null, $context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$value = self::value( trim( $tag, '{}' ), $post );

		return null === $value ? $tag : esc_html( $value );
	}

	/**
	 * Replace our tags inside a longer string (text, rich text, headings).
	 *
	 * @param string $content Content that may hold tags.
	 * @param mixed  $post    The post being rendered.
	 * @param string $context text | link | image | video | media.
	 * @return string
	 */
	public static function bricks_render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		foreach ( array_keys( self::TAGS ) as $name ) {
			$token = '{' . $name . '}';

			if ( false !== strpos( $content, $token ) ) {
				$content = str_replace( $token, self::bricks_render_tag( $token, $post, $context ), $content );
			}
		}

		return $content;
	}
}
