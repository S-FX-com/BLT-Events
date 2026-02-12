<?php
/**
 * Plugin activation: create custom DB tables and seed default data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMT_Events_Activator {

	public static function activate() {
		self::create_tables();
		self::seed_default_fieldset();
		self::set_default_options();
		flush_rewrite_rules();
	}

	// ----- Tables -----

	private static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Fieldsets table
		$table_fieldsets = $wpdb->prefix . 'cmt_fieldsets';
		$sql_fieldsets = "CREATE TABLE {$table_fieldsets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			description text,
			fields longtext NOT NULL,
			consent_fields longtext,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		// Registrations table
		$table_registrations = $wpdb->prefix . 'cmt_registrations';
		$sql_registrations = "CREATE TABLE {$table_registrations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			group_id char(36) DEFAULT NULL,
			customer_name varchar(255) NOT NULL,
			customer_email varchar(255) NOT NULL,
			customer_phone varchar(50) DEFAULT NULL,
			attendee_count int NOT NULL DEFAULT 1,
			custom_fields longtext,
			total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			discount_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'USD',
			coupon_id bigint(20) unsigned DEFAULT NULL,
			coupon_data longtext,
			payment_provider varchar(50) DEFAULT NULL,
			payment_id varchar(255) DEFAULT NULL,
			payment_date datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_event_id (event_id),
			KEY idx_group_id (group_id),
			KEY idx_email (customer_email),
			KEY idx_status (status),
			KEY idx_payment_id (payment_id)
		) {$charset};";

		// Attendees table (multi-attendee support)
		$table_attendees = $wpdb->prefix . 'cmt_attendees';
		$sql_attendees = "CREATE TABLE {$table_attendees} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			attendee_name varchar(255) NOT NULL,
			attendee_email varchar(255) DEFAULT NULL,
			attendee_phone varchar(50) DEFAULT NULL,
			ticket_type varchar(255) DEFAULT NULL,
			ticket_price decimal(10,2) NOT NULL DEFAULT 0.00,
			custom_fields longtext,
			check_in_status varchar(20) NOT NULL DEFAULT 'not_checked_in',
			check_in_time datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_registration_id (registration_id),
			KEY idx_event_id (event_id),
			KEY idx_email (attendee_email)
		) {$charset};";

		dbDelta( $sql_fieldsets );
		dbDelta( $sql_registrations );
		dbDelta( $sql_attendees );

		update_option( 'cmt_events_db_version', CMT_EVENTS_DB_VERSION );
	}

	// ----- Default fieldset -----

	private static function seed_default_fieldset() {
		global $wpdb;
		$table = $wpdb->prefix . 'cmt_fieldsets';

		$exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE slug = 'cmt-standard'" );
		if ( $exists ) {
			return;
		}

		$fields = array(
			array(
				'key'         => 'title',
				'type'        => 'select',
				'label'       => 'Title',
				'required'    => false,
				'width'       => 'third',
				'order'       => 0,
				'options'     => array( 'Mr', 'Ms', 'Mrs', 'Dr' ),
				'allow_other' => true,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'first_name',
				'type'        => 'text',
				'label'       => 'First Name',
				'required'    => true,
				'width'       => 'half',
				'order'       => 1,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'last_name',
				'type'        => 'text',
				'label'       => 'Last Name',
				'required'    => true,
				'width'       => 'half',
				'order'       => 2,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'email',
				'type'        => 'email',
				'label'       => 'Email',
				'required'    => true,
				'width'       => 'full',
				'order'       => 3,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'mobile_number',
				'type'        => 'tel',
				'label'       => 'Mobile Number',
				'required'    => true,
				'width'       => 'full',
				'order'       => 4,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => 'Include country code',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'organization',
				'type'        => 'text',
				'label'       => 'Organization / Company',
				'required'    => true,
				'width'       => 'full',
				'order'       => 5,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'job_title',
				'type'        => 'text',
				'label'       => 'Job Title / Role',
				'required'    => true,
				'width'       => 'full',
				'order'       => 6,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'professional_credentials',
				'type'        => 'select',
				'label'       => 'Professional Credentials',
				'required'    => false,
				'width'       => 'full',
				'order'       => 7,
				'options'     => array( 'CMT', 'CFA', 'CAIA', 'CFP', 'MSTA', 'None' ),
				'allow_other' => true,
				'placeholder' => '',
				'validation'  => array(),
				'conditional' => array(),
			),
			array(
				'key'         => 'linkedin_website',
				'type'        => 'url',
				'label'       => 'LinkedIn / Website',
				'required'    => false,
				'width'       => 'full',
				'order'       => 8,
				'options'     => array(),
				'allow_other' => false,
				'placeholder' => 'https://',
				'validation'  => array(),
				'conditional' => array(),
			),
		);

		$consent_fields = array(
			array(
				'key'      => 'terms_privacy',
				'label'    => 'I accept the <a href="/terms" target="_blank">Terms of Service</a> and <a href="/privacy" target="_blank">Privacy Policy</a>.',
				'required' => true,
			),
			array(
				'key'      => 'data_consent',
				'label'    => 'I consent to the collection and processing of my personal data in accordance with GDPR / PDPL.',
				'required' => true,
			),
			array(
				'key'      => 'marketing_optin',
				'label'    => 'I would like to receive updates and marketing communications.',
				'required' => false,
			),
		);

		$wpdb->insert( $table, array(
			'name'           => 'CMT Standard',
			'slug'           => 'cmt-standard',
			'description'    => 'Default registration fieldset for CMT Association events.',
			'fields'         => wp_json_encode( $fields ),
			'consent_fields' => wp_json_encode( $consent_fields ),
			'is_default'     => 1,
			'status'         => 'active',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		));
	}

	// ----- Default options -----

	private static function set_default_options() {
		$defaults = array(
			'cmt_events_payment_provider' => 'none',
			'cmt_events_currency'         => 'USD',
			'cmt_events_date_format'      => 'F j, Y',
		);

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key ) === false ) {
				update_option( $key, $value );
			}
		}
	}
}
