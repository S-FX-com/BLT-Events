<?php
declare( strict_types=1 );

/**
 * @covers BLT_Events_Fieldsets
 */
class FieldsetsTest extends BLT_Events_TestCase {

	private function basic_fields(): array {
		return array(
			array( 'key' => 'first_name', 'type' => 'text', 'label' => 'First Name', 'required' => true ),
			array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'key' => 'age', 'type' => 'number', 'label' => 'Age', 'required' => false, 'validation' => array( 'min' => 18, 'max' => 99 ) ),
		);
	}

	public function test_strict_validation_reports_missing_required(): void {
		$fieldset = $this->fieldset( $this->basic_fields() );

		$result = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'email' => 'a@b.co' ), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'First Name is required.', $result->get_error_messages() );
	}

	public function test_lenient_validation_records_missing_instead_of_failing(): void {
		$fieldset = $this->fieldset( $this->basic_fields() );

		$result = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'email' => 'a@b.co' ), false );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'First Name' ), $result['_missing_required'] );
		$this->assertSame( 'a@b.co', $result['email'] );
	}

	public function test_invalid_email_is_rejected(): void {
		$fieldset = $this->fieldset( $this->basic_fields() );

		$result = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'first_name' => 'Ana', 'email' => 'nope' ), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'Email must be a valid email address.', $result->get_error_messages() );
	}

	public function test_number_rules_are_enforced(): void {
		$fieldset = $this->fieldset( $this->basic_fields() );

		$too_young = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'first_name' => 'Ana', 'email' => 'a@b.co', 'age' => '12' ), true );
		$this->assertInstanceOf( WP_Error::class, $too_young );
		$this->assertContains( 'Age must be at least 18.', $too_young->get_error_messages() );

		$ok = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'first_name' => 'Ana', 'email' => 'a@b.co', 'age' => '42' ), true );
		$this->assertIsArray( $ok );
		$this->assertSame( 42.0, $ok['age'] );
	}

	public function test_pattern_and_length_rules(): void {
		$fieldset = $this->fieldset( array(
			array( 'key' => 'code', 'type' => 'text', 'label' => 'Code', 'required' => true, 'validation' => array( 'pattern' => '[A-Z]{2}[0-9]{3}', 'message' => 'Use two letters and three digits.' ) ),
			array( 'key' => 'nick', 'type' => 'text', 'label' => 'Nick', 'validation' => array( 'max_length' => 3 ) ),
		) );

		$bad = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'code' => 'ab12', 'nick' => 'toolong' ), true );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertContains( 'Use two letters and three digits.', $bad->get_error_messages() );
		$this->assertContains( 'Nick must be at most 3 characters.', $bad->get_error_messages() );

		$good = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'code' => 'AB123', 'nick' => 'ok' ), true );
		$this->assertIsArray( $good );
	}

	public function test_conditional_field_is_not_required_when_hidden(): void {
		$fieldset = $this->fieldset( array(
			array( 'key' => 'attending', 'type' => 'radio', 'label' => 'Attending', 'options' => array( 'Yes', 'No' ), 'required' => true ),
			array( 'key' => 'dietary', 'type' => 'text', 'label' => 'Dietary needs', 'required' => true, 'conditional' => array( 'field' => 'attending', 'operator' => 'is', 'value' => 'Yes' ) ),
		) );

		$hidden = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'attending' => 'No' ), true );
		$this->assertIsArray( $hidden );
		$this->assertSame( '', $hidden['dietary'] );

		$shown = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'attending' => 'Yes' ), true );
		$this->assertInstanceOf( WP_Error::class, $shown );
		$this->assertContains( 'Dietary needs is required.', $shown->get_error_messages() );
	}

	public function test_choice_fields_only_accept_offered_options(): void {
		$fieldset = $this->fieldset( array(
			array( 'key' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => array( 'S', 'M', 'L' ) ),
			array( 'key' => 'days', 'type' => 'checkbox_group', 'label' => 'Days', 'options' => array( 'Mon', 'Tue' ), 'required' => true ),
		) );

		$bad = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'size' => 'XXL', 'days' => array( 'Mon' ) ), true );
		$this->assertInstanceOf( WP_Error::class, $bad );

		$good = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'size' => 'M', 'days' => array( 'Mon', 'Sun' ) ), true );
		$this->assertIsArray( $good );
		$this->assertSame( 'M', $good['size'] );
		// Unknown options are dropped silently from a group.
		$this->assertSame( array( 'Mon' ), $good['days'] );
	}

	public function test_other_option_reads_companion_field(): void {
		$fieldset = $this->fieldset( array(
			array( 'key' => 'title', 'type' => 'select', 'label' => 'Title', 'options' => array( 'Mr', 'Ms' ), 'allow_other' => true ),
		) );

		$result = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'title' => '__other__', 'title__other' => 'Dr' ), true );

		$this->assertIsArray( $result );
		$this->assertSame( 'Dr', $result['title'] );
	}

	public function test_required_consent_is_enforced(): void {
		$fieldset = $this->fieldset(
			array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ) ),
			array( array( 'key' => 'terms', 'label' => 'I accept the <a href="#">terms</a>', 'required' => true ) )
		);

		$missing = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'email' => 'a@b.co' ), true );
		$this->assertInstanceOf( WP_Error::class, $missing );

		$given = BLT_Events_Fieldsets::validate_submission( $fieldset, array( 'email' => 'a@b.co', 'consent_terms' => '1' ), true );
		$this->assertIsArray( $given );
		$this->assertTrue( $given['_consents']['terms'] );
	}

	public function test_sanitize_field_definition_normalizes_builder_input(): void {
		$clean = BLT_Events_Fieldsets::sanitize_field_definition( array(
			'label'       => 'T-Shirt Size',
			'type'        => 'select',
			'options_str' => ' S, M ,L,, ',
			'width'       => 'weird',
			'required'    => '1',
			'validation'  => array( 'min_length' => '2', 'pattern' => '(' ),
			'conditional' => array( 'field' => 'attending', 'operator' => 'is', 'value' => 'Yes' ),
		), 3 );

		$this->assertSame( 't_shirt_size', $clean['key'] );
		$this->assertSame( array( 'S', 'M', 'L' ), $clean['options'] );
		$this->assertSame( 'full', $clean['width'] );
		$this->assertTrue( $clean['required'] );
		$this->assertSame( 3, $clean['order'] );
		$this->assertSame( 2, $clean['validation']['min_length'] );
		// An uncompilable regex is dropped rather than stored.
		$this->assertArrayNotHasKey( 'pattern', $clean['validation'] );
		$this->assertSame( 'attending', $clean['conditional']['field'] );
	}

	public function test_unknown_type_falls_back_to_text(): void {
		$clean = BLT_Events_Fieldsets::sanitize_field_definition( array( 'label' => 'X', 'type' => 'file' ) );

		$this->assertSame( 'text', $clean['type'] );
	}

	public function test_render_field_marks_conditional_wrapper(): void {
		$html = BLT_Events_Fieldsets::render_field( array(
			'key'         => 'dietary',
			'type'        => 'text',
			'label'       => 'Dietary',
			'conditional' => array( 'field' => 'attending', 'operator' => 'is', 'value' => 'Yes' ),
		) );

		$this->assertStringContainsString( 'blt-field-conditional', $html );
		$this->assertStringContainsString( 'data-blt-condition=', $html );
		$this->assertStringContainsString( 'name="dietary"', $html );
	}

	public function test_render_field_with_prefix_nests_name(): void {
		$html = BLT_Events_Fieldsets::render_field( array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ), '', 'attendees[0]' );

		$this->assertStringContainsString( 'name="attendees[0][name]"', $html );
	}
}
