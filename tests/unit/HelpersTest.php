<?php
declare( strict_types=1 );

use Brain\Monkey\Functions;

/**
 * @covers BLT_Events_Helpers
 */
class HelpersTest extends BLT_Events_TestCase {

	public function test_group_discount_percentage_applies_at_threshold(): void {
		$rules = json_encode( array( 'enabled' => true, 'min_attendees' => 5, 'type' => 'percentage', 'amount' => 10 ) );

		$result = BLT_Events_Helpers::calculate_group_discount( 20, 5, $rules );

		$this->assertSame( 100.0, $result['subtotal'] );
		$this->assertSame( 10.0, $result['discount'] );
		$this->assertSame( 90.0, $result['total'] );
	}

	public function test_group_discount_not_applied_below_threshold(): void {
		$rules = array( 'enabled' => true, 'min_attendees' => 5, 'type' => 'flat', 'amount' => 30 );

		$result = BLT_Events_Helpers::calculate_group_discount( 20, 4, $rules );

		$this->assertSame( 0.0, $result['discount'] );
		$this->assertSame( 80.0, $result['total'] );
	}

	public function test_flat_group_discount_cannot_exceed_subtotal(): void {
		$rules = array( 'enabled' => true, 'min_attendees' => 2, 'type' => 'flat', 'amount' => 500 );

		$result = BLT_Events_Helpers::calculate_group_discount( 10, 2, $rules );

		$this->assertSame( 20.0, $result['discount'] );
		$this->assertSame( 0.0, $result['total'] );
	}

	public function test_sanitize_phone_keeps_leading_plus_only(): void {
		$this->assertSame( '+15551234567', BLT_Events_Helpers::sanitize_phone( '+1 (555) 123-4567' ) );
		$this->assertSame( '5551234567', BLT_Events_Helpers::sanitize_phone( '555.123.4567 ext' ) );
		$this->assertSame( '', BLT_Events_Helpers::sanitize_phone( '   ' ) );
	}

	public function test_registration_statuses_include_supported_statuses(): void {
		$this->assertSame(
			array( 'pending', 'confirmed', 'cancelled', 'refunded' ),
			array_keys( BLT_Events_Helpers::registration_statuses() )
		);
	}

	public function test_site_datetime_uses_site_timezone(): void {
		$dt = BLT_Events_Helpers::site_datetime( '2026-05-14', '14:00' );

		$this->assertInstanceOf( DateTimeImmutable::class, $dt );
		$this->assertSame( 'America/New_York', $dt->getTimezone()->getName() );
		// 14:00 in New York (EDT, UTC-4) is 18:00 UTC.
		$this->assertSame( '2026-05-14T18:00:00+00:00', $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) );
	}

	public function test_site_datetime_rejects_garbage(): void {
		$this->assertNull( BLT_Events_Helpers::site_datetime( 'tomorrow' ) );
		$this->assertNull( BLT_Events_Helpers::site_datetime( '' ) );
	}

	public function test_ics_start_is_converted_to_utc(): void {
		$meta = array(
			'_blt_event_date'       => '2026-05-14',
			'_blt_event_start_time' => '14:00',
			'_blt_event_end_time'   => '15:30',
			'_blt_event_all_day'    => '0',
			'_blt_event_end_date'   => '',
			'_blt_event_type'       => 'in-person',
			'_blt_event_venue'      => 'Grand Hall',
			'_blt_event_location'   => '1 Main St',
			'_blt_event_online_url' => '',
		);

		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/event/launch/' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$event = (object) array( 'ID' => 7, 'post_title' => 'Launch, Day 1', 'post_content' => '' );

		$ics = BLT_Events_Helpers::generate_ics_content( $event );

		$this->assertStringContainsString( 'DTSTART:20260514T180000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20260514T193000Z', $ics );
		$this->assertStringContainsString( 'SUMMARY:Launch\\, Day 1', $ics );
		$this->assertStringContainsString( 'LOCATION:Grand Hall\\, 1 Main St', $ics );
	}

	/**
	 * Stub an online or hybrid event whose join link must stay private.
	 */
	private function online_event( string $type ): object {
		$meta = array(
			'_blt_event_date'       => '2026-05-14',
			'_blt_event_start_time' => '14:00',
			'_blt_event_end_time'   => '15:30',
			'_blt_event_all_day'    => '0',
			'_blt_event_end_date'   => '',
			'_blt_event_type'       => $type,
			'_blt_event_venue'      => 'Grand Hall',
			'_blt_event_location'   => '1 Main St',
			'_blt_event_online_url' => 'https://zoom.us/j/123456789?pwd=secret',
		);

		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/event/launch/' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return $default;
		} );

		return (object) array( 'ID' => 7, 'post_title' => 'Launch', 'post_content' => 'Join us.' );
	}

	public function test_public_ics_leaves_out_the_join_link(): void {
		$event = $this->online_event( 'online' );

		$ics = BLT_Events_Helpers::generate_ics_content( $event );

		$this->assertStringNotContainsString( 'zoom.us', $ics );
		$this->assertStringContainsString( 'LOCATION:Online', $ics );
	}

	public function test_google_calendar_link_leaves_out_the_join_link(): void {
		$online = BLT_Events_Helpers::get_google_calendar_url( $this->online_event( 'online' ) );
		$hybrid = BLT_Events_Helpers::get_google_calendar_url( $this->online_event( 'hybrid' ) );

		$this->assertStringNotContainsString( 'zoom.us', $online );
		$this->assertStringNotContainsString( 'zoom.us', $hybrid );
		$this->assertStringContainsString( 'location=Online', $online );
		$this->assertStringContainsString( 'location=' . urlencode( 'Grand Hall, 1 Main St / Online' ), $hybrid );
	}

	public function test_registrant_ics_can_carry_the_join_link(): void {
		$event = $this->online_event( 'hybrid' );

		$ics = BLT_Events_Helpers::generate_ics_content( $event, true );

		$this->assertStringContainsString( 'LOCATION:Grand Hall\\, 1 Main St / https://zoom.us/j/123456789?pwd=secret', $ics );
	}

	public function test_client_ip_prefers_cloudflare_header(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';

		$this->assertSame( '203.0.113.9', BLT_Events_Helpers::client_ip() );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 198.51.100.2';

		// An invalid first hop falls through to REMOTE_ADDR rather than trusting garbage.
		$this->assertSame( '10.0.0.1', BLT_Events_Helpers::client_ip() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_format_price_defaults_to_symbol_on(): void {
		$this->assertSame( '$25.00', BLT_Events_Helpers::format_price( 25 ) );
		$this->assertSame( 'Total: $0.00', BLT_Events_Helpers::format_price( 0, true ) );
	}

	public function test_escape_ical_text(): void {
		$this->assertSame( 'a\\,b\\;c\\\\d\\ne', BLT_Events_Helpers::escape_ical_text( "a,b;c\\d\ne" ) );
	}
}
