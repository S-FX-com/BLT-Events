<?php
declare( strict_types=1 );

use Brain\Monkey\Functions;

/**
 * @covers BLT_Events_Templates
 */
class TemplatesTest extends BLT_Events_TestCase {

	private string $theme_dir;

	protected function setUp(): void {
		parent::setUp();

		$this->theme_dir = sys_get_temp_dir() . '/blt-events-theme-' . uniqid();
		mkdir( $this->theme_dir . '/blt-events/calendar', 0777, true );

		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->theme_dir );
		Functions\when( 'get_template_directory' )->justReturn( $this->theme_dir );
		Functions\when( 'trailingslashit' )->alias( function ( $path ) {
			return rtrim( $path, '/\\' ) . '/';
		} );
		Functions\when( 'wp_normalize_path' )->alias( function ( $path ) {
			return str_replace( '\\', '/', $path );
		} );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->theme_dir . '/blt-events/calendar/*' ) ?: array() );
		@rmdir( $this->theme_dir . '/blt-events/calendar' );
		@rmdir( $this->theme_dir . '/blt-events' );
		@rmdir( $this->theme_dir );
		parent::tearDown();
	}

	public function test_plugin_template_is_found_by_default(): void {
		$located = BLT_Events_Templates::locate( 'calendar/empty.php' );

		$this->assertStringEndsWith( 'templates/calendar/empty.php', str_replace( '\\', '/', $located ) );
		$this->assertFalse( BLT_Events_Templates::is_overridden( 'calendar/empty.php' ) );
	}

	public function test_theme_override_wins(): void {
		file_put_contents( $this->theme_dir . '/blt-events/calendar/empty.php', '<?php echo "THEME:" . $message;' );

		$this->assertTrue( BLT_Events_Templates::is_overridden( 'calendar/empty.php' ) );
		$this->assertSame( 'THEME:none', BLT_Events_Templates::render( 'calendar/empty.php', array( 'message' => 'none' ) ) );
	}

	public function test_traversal_is_refused(): void {
		$this->assertSame( '', BLT_Events_Templates::locate( '../blt-events.php' ) );
		$this->assertSame( '', BLT_Events_Templates::locate( '' ) );
	}

	public function test_args_become_variables(): void {
		file_put_contents( $this->theme_dir . '/blt-events/calendar/vars.php', '<?php echo $a . "|" . $args["b"];' );

		$this->assertSame( '1|2', BLT_Events_Templates::render( 'calendar/vars.php', array( 'a' => '1', 'b' => '2' ) ) );
	}
}
