<?php

declare( strict_types=1 );

final class EventMigrationTest extends BLT_Events_TestCase {

	public function test_ticket_mapping_preserves_supported_properties(): void {
		$tickets = BLT_Events_Event_Migration::tickets_from_source( array(
			array(
				'name'        => 'Early bird',
				'price'       => '25.50',
				'description' => 'Limited release',
				'start_date'  => '2026-05-01 09:30:00',
				'end_date'    => '2026-05-10 17:00:00',
			),
			(object) array(
				'title' => 'Free RSVP',
				'cost'  => 0,
			),
			array(
				'etn_ticket_name'        => 'Eventin admission',
				'etn_ticket_price'       => '18',
				'etn_ticket_description' => 'Door entry',
				'etn_ticket_start_date'  => '2026-06-01',
				'etn_ticket_start_time'  => '08:00',
				'etn_ticket_end_date'    => '2026-06-10',
				'etn_ticket_end_time'    => '20:00',
			),
			array( 'price' => 10 ),
		) );

		$this->assertSame( array(
			array(
				'name'            => 'Early bird',
				'price'           => 25.5,
				'description'     => 'Limited release',
				'sale_start_date' => '2026-05-01',
				'sale_start_time' => '09:30',
				'sale_end_date'   => '2026-05-10',
				'sale_end_time'   => '17:00',
				'roles'           => array(),
			),
			array(
				'name'            => 'Free RSVP',
				'price'           => 0.0,
				'description'     => '',
				'sale_start_date' => '',
				'sale_start_time' => '',
				'sale_end_date'   => '',
				'sale_end_time'   => '',
				'roles'           => array(),
			),
			array(
				'name'            => 'Eventin admission',
				'price'           => 18.0,
				'description'     => 'Door entry',
				'sale_start_date' => '2026-06-01',
				'sale_start_time' => '08:00',
				'sale_end_date'   => '2026-06-10',
				'sale_end_time'   => '20:00',
				'roles'           => array(),
			),
		), $tickets );
	}
}
