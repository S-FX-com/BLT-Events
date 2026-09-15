<?php
declare( strict_types=1 );

use Brain\Monkey\Functions;

/**
 * @covers BLT_Events_Appearance
 */
class AppearanceTest extends BLT_Events_TestCase {

	public function test_no_overrides_prints_nothing(): void {
		$this->assertSame( '', BLT_Events_Appearance::build_override_css() );
	}

	public function test_primary_colour_derives_hover_tint_and_text_colour(): void {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return 'blt_events_style_primary' === $name ? '#ffffff' : $default;
		} );

		$css = BLT_Events_Appearance::build_override_css();

		$this->assertStringContainsString( '--blt-e-primary: #ffffff;', $css );
		$this->assertStringContainsString( '--blt-e-primary-hover: #d9d9d9;', $css );
		// White is too light for white text: dark text is chosen.
		$this->assertStringContainsString( '--blt-e-on-primary: #111827;', $css );
	}

	public function test_filtered_tokens_are_sanitized(): void {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			if ( 'blt_events_style_tokens' === $tag ) {
				$value['--blt-e-radius']  = '4px';
				$value['--blt-e-evil']    = 'red; } body { display:none';
				$value['--blt-e-url']     = 'url(https://evil.test/x.png)';
			}
			return $value;
		} );

		$css = BLT_Events_Appearance::build_override_css();

		$this->assertStringContainsString( '--blt-e-radius: 4px;', $css );
		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertStringNotContainsString( 'url(', $css );
	}

	public function test_mode_falls_back_to_full(): void {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return 'blt_events_style_mode' === $name ? 'nonsense' : $default;
		} );

		$this->assertSame( 'full', BLT_Events_Appearance::get_mode() );
		$this->assertSame( 'full', BLT_Events_Appearance::sanitize_mode( 'nonsense' ) );
		$this->assertSame( 'skeleton', BLT_Events_Appearance::sanitize_mode( 'skeleton' ) );
	}
}
