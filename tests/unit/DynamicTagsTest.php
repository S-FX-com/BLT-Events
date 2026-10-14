<?php
declare( strict_types=1 );

use Brain\Monkey\Functions;

/**
 * @covers BLT_Events_Dynamic_Tags
 */
class DynamicTagsTest extends BLT_Events_TestCase {

	/** Meta for the event under test; each test overrides what it needs. */
	private array $meta = array();

	/** Post type of the post the builder is rendering. */
	private string $post_type = 'event';

	protected function setUp(): void {
		parent::setUp();

		$this->meta = array(
			'_blt_event_date'       => '2026-05-14',
			'_blt_event_end_date'   => '',
			'_blt_event_type'       => 'in-person',
			'_blt_event_venue'      => 'Grand Hall',
			'_blt_event_location'   => '1 Main St, Springfield',
			'_blt_event_online_url' => '',
		);

		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
			return $this->meta[ $key ] ?? '';
		} );
		Functions\when( 'get_post' )->alias( function () {
			return (object) array( 'ID' => 7, 'post_type' => $this->post_type );
		} );
	}

	public function test_date_is_the_start_date_even_for_a_multi_day_event(): void {
		$this->meta['_blt_event_end_date'] = '2026-05-16';

		$this->assertSame( 'May 14, 2026', BLT_Events_Dynamic_Tags::value( 'blt_event_date', 7 ) );
	}

	public function test_date_is_empty_when_the_event_has_none(): void {
		$this->meta['_blt_event_date'] = '';

		$this->assertSame( '', BLT_Events_Dynamic_Tags::value( 'blt_event_date', 7 ) );
	}

	public function test_in_person_location_is_the_full_address(): void {
		$this->assertSame( 'Grand Hall, 1 Main St, Springfield', BLT_Events_Dynamic_Tags::value( 'blt_event_location', 7 ) );
	}

	public function test_event_with_no_type_is_treated_as_in_person(): void {
		unset( $this->meta['_blt_event_type'] );

		$this->assertSame( 'Grand Hall, 1 Main St, Springfield', BLT_Events_Dynamic_Tags::value( 'blt_event_location', 7 ) );
	}

	public function test_online_location_hides_the_join_url(): void {
		$this->meta['_blt_event_type']       = 'online';
		$this->meta['_blt_event_online_url'] = 'https://zoom.us/j/123456789?pwd=a-very-long-passcode-that-would-overflow';

		$this->assertSame( 'Online', BLT_Events_Dynamic_Tags::value( 'blt_event_location', 7 ) );
	}

	public function test_hybrid_location_is_just_the_label(): void {
		$this->meta['_blt_event_type']       = 'hybrid';
		$this->meta['_blt_event_online_url'] = 'https://zoom.us/j/123456789';

		$this->assertSame( 'Hybrid', BLT_Events_Dynamic_Tags::value( 'blt_event_location', 7 ) );
	}

	public function test_other_post_types_print_nothing(): void {
		$this->post_type = 'page';

		$this->assertSame( '', BLT_Events_Dynamic_Tags::value( 'blt_event_date', 7 ) );
		$this->assertSame( '', BLT_Events_Dynamic_Tags::value( 'blt_event_location', 7 ) );
	}

	public function test_a_missing_post_prints_nothing(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->assertSame( '', BLT_Events_Dynamic_Tags::value( 'blt_event_date' ) );
	}

	public function test_unknown_tag_is_not_ours(): void {
		$this->assertNull( BLT_Events_Dynamic_Tags::value( 'post_title', 7 ) );
	}

	public function test_value_filter_can_change_the_output(): void {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
			return 'blt_events_dynamic_tag_value' === $tag ? strtoupper( $value ) . '|' . $args[0] . '|' . $args[1] : $value;
		} );

		$this->assertSame( 'HYBRID|blt_event_location|7', $this->withType( 'hybrid' ) );
	}

	public function test_bricks_picker_lists_both_tags_with_braces(): void {
		$tags = BLT_Events_Dynamic_Tags::bricks_tags_list( array( array( 'name' => '{post_title}', 'label' => 'Post title', 'group' => 'Post' ) ) );

		$this->assertSame(
			array( '{post_title}', '{blt_event_date}', '{blt_event_location}' ),
			array_column( $tags, 'name' )
		);
		$this->assertSame( 'BLT Events', $tags[1]['group'] );
		$this->assertSame( 'Event start date', $tags[1]['label'] );
		$this->assertSame( 'Event location', $tags[2]['label'] );
	}

	public function test_bricks_render_tag_resolves_ours_and_returns_others_untouched(): void {
		$this->assertSame( 'May 14, 2026', BLT_Events_Dynamic_Tags::bricks_render_tag( '{blt_event_date}', null ) );
		$this->assertSame( '{post_title}', BLT_Events_Dynamic_Tags::bricks_render_tag( '{post_title}', null ) );
		$this->assertSame( array( 'x' ), BLT_Events_Dynamic_Tags::bricks_render_tag( array( 'x' ), null ) );
	}

	public function test_bricks_render_content_replaces_tags_inside_text(): void {
		$content = 'On {blt_event_date} at {blt_event_location} — {post_title}';

		$this->assertSame(
			'On May 14, 2026 at Grand Hall, 1 Main St, Springfield — {post_title}',
			BLT_Events_Dynamic_Tags::bricks_render_content( $content, null )
		);
	}

	public function test_bricks_render_content_ignores_content_without_our_tags(): void {
		$this->assertSame( 'Plain {post_title}', BLT_Events_Dynamic_Tags::bricks_render_content( 'Plain {post_title}', null ) );
	}

	public function test_bricks_output_is_escaped(): void {
		$this->meta['_blt_event_venue'] = 'Smith & <b>Sons</b>';

		$this->assertSame(
			'Smith &amp; &lt;b&gt;Sons&lt;/b&gt;, 1 Main St, Springfield',
			BLT_Events_Dynamic_Tags::bricks_render_tag( '{blt_event_location}', null )
		);
	}

	private function withType( string $type ): string {
		$this->meta['_blt_event_type'] = $type;

		return (string) BLT_Events_Dynamic_Tags::value( 'blt_event_location', 7 );
	}
}
