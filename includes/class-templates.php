<?php
/**
 * BLT Events - Template loader
 *
 * Every piece of front-end HTML the plugin prints lives in a file under
 * `templates/`, and a theme can replace any of them by copying the file to
 * `wp-content/themes/<theme>/blt-events/<same path>`. Child themes win over
 * parent themes, and both win over the plugin.
 *
 *   templates/single-event.php          -> your-theme/blt-events/single-event.php
 *   templates/calendar/list-item.php    -> your-theme/blt-events/calendar/list-item.php
 *
 * Templates receive their data in an `$args` array, and each key is also
 * available as a local variable of the same name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Templates {

	/**
	 * Directory name a theme uses to hold overrides.
	 */
	const THEME_DIR = 'blt-events';

	/**
	 * Resolve a template name to a file path.
	 *
	 * @param string $template Relative template path, e.g. 'calendar/list-item.php'.
	 * @return string Absolute path, or '' when nothing matches.
	 */
	public static function locate( $template ) {
		$template = ltrim( str_replace( '\\', '/', (string) $template ), '/' );

		// No directory traversal out of the template roots.
		if ( '' === $template || false !== strpos( $template, '..' ) ) {
			return '';
		}

		/**
		 * Filter the directories searched for a template, in priority order.
		 *
		 * @param string[] $paths    Absolute directory paths with trailing slash.
		 * @param string   $template The relative template path being located.
		 */
		$paths = apply_filters( 'blt_events_template_paths', array(
			trailingslashit( get_stylesheet_directory() ) . self::THEME_DIR . '/',
			trailingslashit( get_template_directory() ) . self::THEME_DIR . '/',
			BLT_EVENTS_PLUGIN_DIR . 'templates/',
		), $template );

		$located = '';
		foreach ( array_unique( (array) $paths ) as $dir ) {
			if ( file_exists( $dir . $template ) ) {
				$located = $dir . $template;
				break;
			}
		}

		/**
		 * Filter the located template file.
		 *
		 * @param string $located  Absolute path ('' when none was found).
		 * @param string $template The relative template path requested.
		 */
		return (string) apply_filters( 'blt_events_locate_template', $located, $template );
	}

	/**
	 * Include a template, exposing $args and its keys as variables.
	 *
	 * @param string $template Relative template path.
	 * @param array  $args     Data for the template.
	 */
	public static function include_template( $template, $args = array() ) {
		$file = self::locate( $template );

		if ( '' === $file ) {
			return;
		}

		/**
		 * Filter the data passed to a template.
		 *
		 * @param array  $args     Template data.
		 * @param string $template The relative template path.
		 */
		$args = (array) apply_filters( 'blt_events_template_args', $args, $template );

		/**
		 * Fires before a template is included.
		 *
		 * @param string $template The relative template path.
		 * @param array  $args     Template data.
		 */
		do_action( 'blt_events_before_template', $template, $args );

		self::load( $file, $args );

		/**
		 * Fires after a template is included.
		 *
		 * @param string $template The relative template path.
		 * @param array  $args     Template data.
		 */
		do_action( 'blt_events_after_template', $template, $args );
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string $template Relative template path.
	 * @param array  $args     Data for the template.
	 * @return string
	 */
	public static function render( $template, $args = array() ) {
		ob_start();
		self::include_template( $template, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Include the file in an isolated scope.
	 *
	 * @param string $file Absolute file path.
	 * @param array  $args Template data.
	 */
	private static function load( $file, array $args ) {
		// Reserved names cannot be shadowed by template data.
		unset( $args['file'], $args['this'] );

		foreach ( $args as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key ) ) {
				$$key = $value; // phpcs:ignore Squiz.PHP.DisallowVariableVariables
			}
		}

		include $file;
	}

	/**
	 * Whether a theme is overriding a given template.
	 *
	 * @param string $template Relative template path.
	 * @return bool
	 */
	public static function is_overridden( $template ) {
		$file = self::locate( $template );

		return '' !== $file && 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( BLT_EVENTS_PLUGIN_DIR . 'templates/' ) );
	}
}
