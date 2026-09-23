<?php
declare( strict_types=1 );

use Brain\Monkey\Functions;

/**
 * JSON post meta (ticket types, agenda, presenters, sponsors, ...) must
 * survive update_post_meta(), which unslashes whatever it is given.
 *
 * @covers BLT_Events_Helpers::update_json_meta
 */
class JsonMetaTest extends BLT_Events_TestCase {

	/** Core's wp_slash() for strings and arrays. */
	public static function slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'slash' ), $value );
		}
		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	/**
	 * Run update_json_meta() and return what the database would hold: the
	 * value update_post_meta() received, unslashed the way core does it.
	 */
	private function stored_value( array $rows ): string {
		$received = null;

		Functions\when( 'wp_slash' )->alias( array( __CLASS__, 'slash' ) );
		Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$received ) {
			$received = $value;
			return true;
		} );

		BLT_Events_Helpers::update_json_meta( 7, '_blt_ticket_types', $rows );

		$this->assertIsString( $received );

		// wp_unslash() on a string is stripslashes().
		return stripslashes( $received );
	}

	public function test_ticket_types_with_quotes_backslashes_and_unicode_round_trip(): void {
		$tickets = array(
			array(
				'name'        => 'Café "VIP" \\ pass',
				'description' => "Line one\nLine two — ünïcödé ✓",
				'price'       => 75,
			),
		);

		$decoded = json_decode( $this->stored_value( $tickets ), true );

		$this->assertSame( $tickets, $decoded );
	}

	public function test_unslashed_json_is_what_used_to_break(): void {
		// The old write path: update_post_meta( $id, $key, wp_json_encode( $rows ) ).
		$raw = json_encode( array( array( 'name' => 'Café "VIP" pass' ) ) );

		$this->assertNull( json_decode( stripslashes( $raw ), true ) );
	}

	public function test_no_json_meta_is_written_without_slashing(): void {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$bad   = array();

		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', $file->getPathname() );
			if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/lib/' ) || false !== strpos( $path, '/blt-family/' ) ) {
				continue;
			}

			preg_match_all( '/(?:update|add)_post_meta\s*\(([^;]*)\)\s*;/', (string) file_get_contents( $path ), $calls );
			foreach ( $calls[1] as $args ) {
				if ( false !== strpos( $args, 'json_encode' ) && false === strpos( $args, 'wp_slash' ) ) {
					$bad[] = substr( $path, strlen( $root ) + 1 ) . ': ' . trim( preg_replace( '/\s+/', ' ', $args ) );
				}
			}
		}

		$this->assertSame( array(), $bad, 'Use BLT_Events_Helpers::update_json_meta() for JSON meta.' );
	}
}
