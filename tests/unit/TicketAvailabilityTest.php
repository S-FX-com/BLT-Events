<?php
declare( strict_types=1 );

use Brain\Monkey\Functions;

/**
 * Sale windows, role (member) restrictions and the member rates a
 * logged-out visitor could unlock by logging in.
 *
 * @covers BLT_Events_Helpers
 */
class TicketAvailabilityTest extends BLT_Events_TestCase {

	private function day( string $modifier ): string {
		return ( new \DateTimeImmutable( $modifier, new \DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
	}

	private function log_in_as( array $roles ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => $roles ) );
	}

	private function event_tickets( array $tickets ): void {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $tickets ) {
			return '_blt_ticket_types' === $key ? json_encode( $tickets ) : '';
		} );
	}

	public function test_sale_state_follows_the_window(): void {
		$this->assertSame( 'on_sale', BLT_Events_Helpers::ticket_sale_state( array() ) );
		$this->assertSame( 'upcoming', BLT_Events_Helpers::ticket_sale_state( array( 'sale_start_date' => $this->day( '+2 days' ) ) ) );
		$this->assertSame( 'ended', BLT_Events_Helpers::ticket_sale_state( array( 'sale_end_date' => $this->day( '-2 days' ) ) ) );
		$this->assertSame(
			'on_sale',
			BLT_Events_Helpers::ticket_sale_state( array( 'sale_start_date' => $this->day( '-1 day' ), 'sale_end_date' => $this->day( '+1 day' ) ) )
		);
	}

	public function test_sale_end_date_without_time_lasts_the_whole_day(): void {
		$this->assertSame( 'on_sale', BLT_Events_Helpers::ticket_sale_state( array( 'sale_end_date' => $this->day( 'today' ) ) ) );
	}

	public function test_unrestricted_ticket_is_open_to_guests(): void {
		$ticket = array( 'name' => 'General', 'roles' => array() );

		$this->assertFalse( BLT_Events_Helpers::ticket_is_members_only( $ticket ) );
		$this->assertTrue( BLT_Events_Helpers::ticket_role_allowed( $ticket ) );
		$this->assertTrue( BLT_Events_Helpers::ticket_is_available( $ticket ) );
	}

	public function test_member_ticket_needs_a_matching_role(): void {
		$ticket = array( 'name' => 'Member', 'roles' => array( 'subscriber' ) );

		$this->assertTrue( BLT_Events_Helpers::ticket_is_members_only( $ticket ) );
		$this->assertFalse( BLT_Events_Helpers::ticket_is_available( $ticket ), 'Guests cannot buy member rates.' );

		$this->log_in_as( array( 'editor' ) );
		$this->assertFalse( BLT_Events_Helpers::ticket_is_available( $ticket ), 'Logged in without the role.' );

		$this->log_in_as( array( 'subscriber' ) );
		$this->assertTrue( BLT_Events_Helpers::ticket_is_available( $ticket ) );
	}

	public function test_member_ticket_outside_its_window_is_unavailable_even_with_the_role(): void {
		$this->log_in_as( array( 'subscriber' ) );

		$ticket = array( 'roles' => array( 'subscriber' ), 'sale_end_date' => $this->day( '-3 days' ) );

		$this->assertTrue( BLT_Events_Helpers::ticket_role_allowed( $ticket ) );
		$this->assertFalse( BLT_Events_Helpers::ticket_is_available( $ticket ) );
	}

	public function test_guests_see_member_rates_they_could_unlock(): void {
		$this->event_tickets( array(
			array( 'name' => 'General', 'price' => 75 ),
			array( 'name' => 'Member', 'price' => 45, 'roles' => array( 'subscriber' ) ),
			array( 'name' => 'Member early bird', 'price' => 35, 'roles' => array( 'subscriber' ), 'sale_end_date' => $this->day( '-5 days' ) ),
		) );

		$member = BLT_Events_Helpers::member_ticket_types( 12 );

		$this->assertSame( array( 1 ), array_keys( $member ), 'Only the member rate on sale now, keyed by its original index.' );
		$this->assertSame( array( 0 ), array_keys( BLT_Events_Helpers::available_ticket_types( 12 ) ) );
	}

	public function test_logged_in_visitors_are_never_offered_the_login_prompt(): void {
		$this->event_tickets( array(
			array( 'name' => 'Member', 'price' => 45, 'roles' => array( 'subscriber' ) ),
		) );
		$this->log_in_as( array( 'editor' ) );

		$this->assertSame( array(), BLT_Events_Helpers::member_ticket_types( 12 ) );
	}
}
