<?php
/**
 * BLT Events - Event archive (/event/ and /event-category/...)
 *
 * WordPress gives the event post type an archive, but a theme's generic
 * archive template knows nothing about dates, venues or tickets, so out of
 * the box the archive looked like a blog index. Three modes, chosen in
 * Settings > General:
 *
 *   plugin   - (default on new installs) the plugin renders the archive
 *              itself: its own template on classic themes, a registered
 *              block template on block themes. A theme that ships
 *              archive-event.php keeps winning, as WordPress intends.
 *   redirect - send visitors to the Events page chosen in Settings.
 *   theme    - leave it to the theme (the pre-2.4 behaviour).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Archive {

	const OPTION = 'blt_events_archive_mode';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 20 );
		add_action( 'init', array( __CLASS__, 'register_block_template' ), 20 );
		add_filter( 'blt_events_calendar_query_args', array( __CLASS__, 'scope_to_term' ), 5, 3 );
	}

	/**
	 * The active mode: plugin | redirect | theme.
	 */
	public static function mode() {
		$mode = (string) get_option( self::OPTION, 'plugin' );
		return in_array( $mode, array( 'plugin', 'redirect', 'theme' ), true ) ? $mode : 'plugin';
	}

	public static function sanitize_mode( $value ) {
		return in_array( $value, array( 'plugin', 'redirect', 'theme' ), true ) ? $value : 'plugin';
	}

	private static function is_event_archive() {
		return is_post_type_archive( 'event' ) || is_tax( 'event_category' );
	}

	/**
	 * Redirect mode: the archive becomes the Events page.
	 */
	public static function maybe_redirect() {
		if ( 'redirect' !== self::mode() || ! self::is_event_archive() ) {
			return;
		}

		$page_id = (int) get_option( 'blt_events_events_page_id', 0 );
		$url     = $page_id > 0 ? get_permalink( $page_id ) : '';

		if ( ! $url ) {
			return;
		}

		// Category archives keep their scope via the shortcode's category attribute.
		if ( is_tax( 'event_category' ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$url = add_query_arg( 'blt_category', $term->slug, $url );
			}
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}

	/**
	 * Plugin mode on classic themes: use the bundled archive template unless
	 * the theme provides its own.
	 */
	public static function template_include( $template ) {
		if ( 'plugin' !== self::mode() || ! self::is_event_archive() ) {
			return $template;
		}

		// Block themes get the registered block template instead.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return $template;
		}

		// The theme has a dedicated event archive template: respect it.
		$basename = basename( (string) $template );
		if ( 0 === strpos( $basename, 'archive-event' ) || 0 === strpos( $basename, 'taxonomy-event_category' ) ) {
			return $template;
		}

		$plugin_template = BLT_Events_Templates::locate( 'archive-event.php' );

		return $plugin_template ? $plugin_template : $template;
	}

	/**
	 * Plugin mode on block themes (WordPress 6.7+): a block template for the
	 * archive that the site editor can also customise.
	 */
	public static function register_block_template() {
		if ( 'plugin' !== self::mode() || ! function_exists( 'register_block_template' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return;
		}

		$content = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
			. '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->'
			. '<!-- wp:term-description /-->'
			. '<!-- wp:blt-events/calendar {"view":"list","switcher":true} /-->'
			. '</main><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';

		register_block_template( 'blt-events//archive-event', array(
			'title'       => __( 'Event Archive', 'blt-events' ),
			'description' => __( 'Lists events with the BLT Events calendar. Used for the event archive and event category pages.', 'blt-events' ),
			'content'     => $content,
			'post_types'  => array( 'event' ),
		) );

		add_filter( 'archive_template_hierarchy', array( __CLASS__, 'block_template_hierarchy' ) );
		add_filter( 'taxonomy_template_hierarchy', array( __CLASS__, 'block_template_hierarchy' ) );
	}

	/**
	 * Put the registered block template in front of the theme's generic
	 * archive template, behind any event-specific one the theme ships.
	 */
	public static function block_template_hierarchy( $templates ) {
		if ( ! self::is_event_archive() ) {
			return $templates;
		}

		$out = array();
		foreach ( $templates as $t ) {
			$out[] = $t;
			// Insert right after the most specific candidates.
			if ( 0 === strpos( $t, 'archive-event' ) || 0 === strpos( $t, 'taxonomy-event_category' ) ) {
				$out[] = 'archive-event';
			}
		}

		if ( ! in_array( 'archive-event', $out, true ) ) {
			array_unshift( $out, 'archive-event' );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * On an event category archive, a calendar without its own category
	 * attribute lists only that category. Also honours ?blt_category= set
	 * by the redirect mode.
	 */
	public static function scope_to_term( $args, $view, $atts ) {
		if ( ! empty( $atts['category'] ) || ! empty( $args['tax_query'] ) ) {
			return $args;
		}

		$slug = '';
		if ( is_tax( 'event_category' ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$slug = $term->slug;
			}
		} elseif ( isset( $_GET['blt_category'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( $_GET['blt_category'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $slug ) {
			return $args;
		}

		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => 'event_category',
				'field'    => 'slug',
				'terms'    => array( $slug ),
			),
		);

		return $args;
	}
}
