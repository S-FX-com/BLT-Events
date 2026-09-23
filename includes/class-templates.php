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

	/**
	 * A small decorative line icon (24px grid, stroked in currentColor) for
	 * templates. The markup is a fixed string, safe to echo unescaped.
	 *
	 * @param string $name arrow-right | arrow-left | calendar | clock | pin | lock | trash | edit | help | check | image | chevron | video | tag.
	 * @return string SVG markup, or '' for an unknown name.
	 */
	public static function icon( $name ) {
		$paths = array(
			'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'arrow-left'  => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
			'calendar'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'pin'         => '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
			'lock'        => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'trash'       => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
			'edit'        => '<path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/>',
			'help'        => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17.5v.01"/>',
			'check'       => '<path d="M5 12.5l4.5 4.5L19 7"/>',
			'image'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
			'chevron'     => '<path d="M6 9l6 6 6-6"/>',
			'video'       => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10.5l5-3v9l-5-3"/>',
			'tag'         => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="blt-icon blt-icon--' . $name . '" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
