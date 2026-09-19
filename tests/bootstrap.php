<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit suite runs without WordPress or a database: Brain\Monkey stubs
 * the WordPress functions each test needs, and the handful of functions
 * every file touches at load time are defined here.
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'BLT_EVENTS_PLUGIN_DIR' ) ) {
	define( 'BLT_EVENTS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'BLT_EVENTS_PLUGIN_URL' ) ) {
	define( 'BLT_EVENTS_PLUGIN_URL', 'https://example.test/wp-content/plugins/blt-events/' );
}
if ( ! defined( 'BLT_EVENTS_VERSION' ) ) {
	define( 'BLT_EVENTS_VERSION', 'tests' );
}
if ( ! defined( 'BLT_EVENTS_PREFIX' ) ) {
	define( 'BLT_EVENTS_PREFIX', '_blt_' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

// Minimal WordPress classes the code under test instantiates.
require_once __DIR__ . '/stubs/class-wp-error.php';

// The classes under test.
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/class-helpers.php';
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/class-fieldsets.php';
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/class-templates.php';
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/frontend/class-appearance.php';
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/admin/class-event-migration.php';

/**
 * Base test case: boots Brain\Monkey and stubs the functions almost every
 * code path calls (translation, escaping, basic sanitizers), with behaviour
 * close enough to core for unit assertions.
 */
abstract class BLT_Events_TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		// Translation: identity.
		\Brain\Monkey\Functions\when( '__' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( '_x' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'esc_html__' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'esc_attr__' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( '_n' )->alias( function ( $single, $plural, $number ) {
			return 1 === (int) $number ? $single : $plural;
		} );

		// Escaping: identity is enough to assert on structure.
		\Brain\Monkey\Functions\when( 'esc_html' )->alias( function ( $v ) {
			return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		} );
		\Brain\Monkey\Functions\when( 'esc_attr' )->alias( function ( $v ) {
			return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		} );
		\Brain\Monkey\Functions\when( 'esc_textarea' )->alias( function ( $v ) {
			return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		} );
		\Brain\Monkey\Functions\when( 'esc_url' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
		} );
		\Brain\Monkey\Functions\when( 'wp_kses_post' )->returnArg( 1 );
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( function ( $text ) {
			return trim( strip_tags( (string) $text ) );
		} );
		\Brain\Monkey\Functions\when( 'wpautop' )->alias( function ( $text ) {
			return '<p>' . str_replace( "\n\n", '</p><p>', trim( (string) $text ) ) . '</p>';
		} );

		// Sanitizers.
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias( function ( $v ) {
			return trim( strip_tags( (string) $v ) );
		} );
		\Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias( function ( $v ) {
			return trim( strip_tags( (string) $v ) );
		} );
		\Brain\Monkey\Functions\when( 'sanitize_key' )->alias( function ( $v ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) );
		} );
		\Brain\Monkey\Functions\when( 'sanitize_html_class' )->alias( function ( $v ) {
			return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v );
		} );
		\Brain\Monkey\Functions\when( 'sanitize_title' )->alias( function ( $v ) {
			return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' );
		} );
		\Brain\Monkey\Functions\when( 'sanitize_email' )->alias( function ( $v ) {
			return filter_var( trim( (string) $v ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $v ) : '';
		} );
		\Brain\Monkey\Functions\when( 'is_email' )->alias( function ( $v ) {
			return (bool) filter_var( (string) $v, FILTER_VALIDATE_EMAIL );
		} );
		\Brain\Monkey\Functions\when( 'absint' )->alias( function ( $v ) {
			return abs( (int) $v );
		} );
		\Brain\Monkey\Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );

		// Hooks: filters return their first argument unless a test says otherwise.
		\Brain\Monkey\Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $value;
		} );
		\Brain\Monkey\Functions\when( 'do_action' )->justReturn( null );

		// Site state defaults; tests override with expect()/when().
		\Brain\Monkey\Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return $default;
		} );
		\Brain\Monkey\Functions\when( 'is_user_logged_in' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'wp_timezone' )->alias( function () {
			return new \DateTimeZone( 'America/New_York' );
		} );
		\Brain\Monkey\Functions\when( 'wp_date' )->alias( function ( $format, $timestamp = null, $timezone = null ) {
			$dt = new \DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
			return $dt->setTimezone( $timezone ?: new \DateTimeZone( 'America/New_York' ) )->format( $format );
		} );
		\Brain\Monkey\Functions\when( 'current_time' )->alias( function ( $type ) {
			return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/New_York' ) ) )->format( 'mysql' === $type ? 'Y-m-d H:i:s' : $type );
		} );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A fake fieldset row.
	 */
	protected function fieldset( array $fields, array $consents = array() ): object {
		return (object) array(
			'id'             => 1,
			'name'           => 'Test',
			'slug'           => 'test',
			'fields'         => json_encode( $fields ),
			'consent_fields' => json_encode( $consents ),
			'is_default'     => 1,
			'status'         => 'active',
		);
	}
}
