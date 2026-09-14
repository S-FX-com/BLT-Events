<?php
/**
 * BLT Events - Front-end appearance
 *
 * Owns how the plugin looks on the front end: which stylesheets load, and
 * the token overrides an admin has chosen.
 *
 * Three modes, so the same plugin suits a site with no design system and a
 * site that already has a strong one:
 *
 *   full     - the plugin's own look, out of the box. Nothing to configure.
 *   skeleton - layout and structure stay, but every colour, radius, shadow
 *              and font is re-pointed at the site's framework variables
 *              (ACSS and friends). See blt-events-skeleton.css.
 *   off      - no front-end CSS at all. The BEM markup is left for the site
 *              to style from scratch.
 *
 * Every stylesheet resolves its values through the token layer, so the
 * per-site overrides below are a handful of custom properties printed
 * inline — never a competing rule that has to out-specify anything.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Appearance {

	const OPTION_MODE    = 'blt_events_style_mode';
	const OPTION_PRIMARY = 'blt_events_style_primary';
	const OPTION_RADIUS  = 'blt_events_style_radius';
	const OPTION_FONT    = 'blt_events_style_font';
	const OPTION_WIDTH   = 'blt_events_style_width';

	/**
	 * Handle every plugin stylesheet depends on. Depending on the token layer
	 * is what guarantees the tokens are defined before anything reads them,
	 * whichever stylesheet happens to be enqueued first.
	 */
	const TOKENS_HANDLE = 'blt-events-tokens';

	public static function init() {
		// Priority 5: the token layer has to be registered before the other
		// asset callbacks (default priority) declare it as a dependency.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_styles' ), 5 );
	}

	/**
	 * The active styling mode.
	 *
	 * @return string One of 'full', 'skeleton', 'off'.
	 */
	public static function get_mode() {
		$mode = get_option( self::OPTION_MODE, '' );

		if ( in_array( $mode, array( 'full', 'skeleton', 'off' ), true ) ) {
			return $mode;
		}

		// Sites upgrading from the old single-event-only toggle: honour what
		// they already chose rather than silently restyling their event pages.
		// Only an explicit '0' means off — an empty or missing legacy option
		// is "never configured", which is the same as the 'full' default.
		if ( '0' === (string) get_option( 'blt_events_single_styles', '1' ) ) {
			return 'off';
		}

		return 'full';
	}

	/**
	 * Whether the plugin should output any front-end CSS at all.
	 */
	public static function styles_enabled() {
		return 'off' !== self::get_mode();
	}

	/**
	 * The handle a plugin stylesheet should depend on to get the tokens.
	 *
	 * In skeleton mode this is the overlay, which itself depends on the token
	 * file — so one dependency pulls in both, in the right order. Nothing
	 * loads until a view actually asks for it.
	 *
	 * @return string Handle, or '' when styling is off.
	 */
	public static function layer_handle() {
		if ( ! self::styles_enabled() ) {
			return '';
		}

		return 'skeleton' === self::get_mode() ? 'blt-events-skeleton' : self::TOKENS_HANDLE;
	}

	/**
	 * Register the token layer, skeleton overlay and per-site overrides.
	 *
	 * Registers rather than enqueues: the individual views enqueue what they
	 * need, and pulling the layer in as a dependency comes free — so a page
	 * with no events content ships none of this.
	 */
	public static function register_styles() {
		if ( ! self::styles_enabled() ) {
			return;
		}

		wp_register_style(
			self::TOKENS_HANDLE,
			BLT_EVENTS_PLUGIN_URL . 'assets/css/blt-events-tokens.css',
			array(),
			BLT_EVENTS_VERSION
		);

		if ( 'skeleton' === self::get_mode() ) {
			wp_register_style(
				'blt-events-skeleton',
				BLT_EVENTS_PLUGIN_URL . 'assets/css/blt-events-skeleton.css',
				array( self::TOKENS_HANDLE ),
				BLT_EVENTS_VERSION
			);
		}

		$overrides = self::build_override_css();
		if ( '' !== $overrides ) {
			// Attached to whichever layer loads last, so the admin's choices
			// win over both the defaults and the skeleton chains.
			wp_add_inline_style( self::layer_handle(), $overrides );
		}
	}

	/**
	 * The dependency array a plugin stylesheet should declare.
	 *
	 * @return string[]
	 */
	public static function style_deps() {
		$handle = self::layer_handle();

		return '' === $handle ? array() : array( $handle );
	}

	/**
	 * Build the `:root` block for whatever the admin overrode.
	 *
	 * Only tokens that were actually set are printed, so an untouched site
	 * ships no inline CSS at all.
	 *
	 * @return string CSS, or '' when nothing is overridden.
	 */
	public static function build_override_css() {
		$rules = array();

		$primary = self::get_primary();
		if ( '' !== $primary ) {
			$rules['--blt-e-primary'] = $primary;
			// Derived so a single colour choice stays coherent: the hover and
			// tint would otherwise still be the old indigo.
			$rules['--blt-e-primary-hover'] = self::shade( $primary, -0.15 );
			$rules['--blt-e-primary-tint']  = self::mix_with_white( $primary, 0.92 );
			$rules['--blt-e-focus-ring']    = self::to_rgba( $primary, 0.3 );
			$rules['--blt-e-focus-glow']    = self::to_rgba( $primary, 0.1 );
			$rules['--blt-e-on-primary']    = self::readable_on( $primary );
		}

		$radius = (int) get_option( self::OPTION_RADIUS, '' );
		if ( $radius > 0 ) {
			$rules['--blt-e-radius-sm'] = max( 0, $radius - 4 ) . 'px';
			$rules['--blt-e-radius']    = $radius . 'px';
			$rules['--blt-e-radius-lg'] = ( $radius + 2 ) . 'px';
		} elseif ( '0' === (string) get_option( self::OPTION_RADIUS, '' ) ) {
			// An explicit zero is a real choice (square corners), not "unset".
			$rules['--blt-e-radius-sm'] = '0';
			$rules['--blt-e-radius']    = '0';
			$rules['--blt-e-radius-lg'] = '0';
		}

		if ( 'theme' === get_option( self::OPTION_FONT, '' ) ) {
			$rules['--blt-e-font'] = 'inherit';
		}

		$width = (int) get_option( self::OPTION_WIDTH, 0 );
		if ( $width > 0 ) {
			$rules['--blt-e-content-width'] = $width . 'px';
		}

		/**
		 * Filter the front-end token overrides.
		 *
		 * Add or replace any `--blt-e-*` token from a theme or snippet without
		 * writing CSS: return `array( '--blt-e-primary' => '#c00' )`.
		 *
		 * @param array  $rules Token name => CSS value.
		 * @param string $mode  The active styling mode.
		 */
		$rules = apply_filters( 'blt_events_style_tokens', $rules, self::get_mode() );

		if ( empty( $rules ) ) {
			return '';
		}

		$css = '';
		foreach ( $rules as $token => $value ) {
			// Tokens are plugin-controlled names; values are sanitized on save
			// and again here, since the filter above can introduce new ones.
			$token = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $token ) );
			$value = self::sanitize_css_value( $value );

			if ( '' === $token || '' === $value ) {
				continue;
			}

			$css .= "\t{$token}: {$value};\n";
		}

		return '' === $css ? '' : ":root {\n{$css}}\n";
	}

	/**
	 * The configured primary colour, or '' when the default is in use.
	 *
	 * @return string
	 */
	public static function get_primary() {
		$value = (string) get_option( self::OPTION_PRIMARY, '' );

		return self::is_hex( $value ) ? strtolower( $value ) : '';
	}

	/* ------------------------------------------------------------------
	 * Sanitizers
	 * ---------------------------------------------------------------- */

	public static function sanitize_mode( $value ) {
		return in_array( $value, array( 'full', 'skeleton', 'off' ), true ) ? $value : 'full';
	}

	public static function sanitize_hex( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$hex = sanitize_hex_color( $value );

		return $hex ? strtolower( $hex ) : '';
	}

	public static function sanitize_radius( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return (string) max( 0, min( 60, (int) $value ) );
	}

	public static function sanitize_font( $value ) {
		return 'theme' === $value ? 'theme' : '';
	}

	public static function sanitize_width( $value ) {
		$value = (int) $value;

		if ( $value <= 0 ) {
			return '';
		}

		return (string) max( 480, min( 2400, $value ) );
	}

	/**
	 * Allow only the shapes the token values can legitimately take, so a
	 * filtered-in value can never close the declaration and inject a rule.
	 */
	private static function sanitize_css_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || strlen( $value ) > 200 ) {
			return '';
		}

		if ( preg_match( '/[;{}<>@\\\\]/', $value ) || false !== stripos( $value, 'url(' ) ) {
			return '';
		}

		return $value;
	}

	/* ------------------------------------------------------------------
	 * Colour helpers
	 *
	 * Derived shades are computed in PHP rather than with CSS color-mix()
	 * so the output works in every browser the plugin supports.
	 * ---------------------------------------------------------------- */

	private static function is_hex( $value ) {
		return (bool) preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $value );
	}

	/**
	 * Expand #abc to #aabbcc and return the three channels.
	 *
	 * @return int[]|null
	 */
	private static function rgb( $hex ) {
		if ( ! self::is_hex( $hex ) ) {
			return null;
		}

		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Lighten (positive amount) or darken (negative) a hex colour.
	 */
	private static function shade( $hex, $amount ) {
		$rgb = self::rgb( $hex );

		if ( ! $rgb ) {
			return $hex;
		}

		$out = '#';
		foreach ( $rgb as $channel ) {
			$shifted = $amount < 0
				? $channel * ( 1 + $amount )
				: $channel + ( 255 - $channel ) * $amount;

			$out .= str_pad( dechex( (int) round( max( 0, min( 255, $shifted ) ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return $out;
	}

	/**
	 * Blend a colour towards white. $ratio 0.92 means 92% white.
	 */
	private static function mix_with_white( $hex, $ratio ) {
		return self::shade( $hex, $ratio );
	}

	private static function to_rgba( $hex, $alpha ) {
		$rgb = self::rgb( $hex );

		if ( ! $rgb ) {
			return '';
		}

		return sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], $alpha );
	}

	/**
	 * Black or white, whichever stays legible on the given background.
	 *
	 * Uses the WCAG relative-luminance threshold rather than a naive
	 * average, so a saturated yellow correctly gets dark text.
	 */
	private static function readable_on( $hex ) {
		$rgb = self::rgb( $hex );

		if ( ! $rgb ) {
			return '#ffffff';
		}

		$channels = array();
		foreach ( $rgb as $channel ) {
			$c = $channel / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return $luminance > 0.45 ? '#111827' : '#ffffff';
	}
}
