<?php
/**
 * Plugin activation: create custom DB tables and seed default data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Activator {

	public static function activate() {
		self::migrate_from_cmt();
		self::create_tables();
		self::seed_default_fieldset();
		self::set_default_options();
		self::grant_capabilities();
		flush_rewrite_rules();
	}

	/**
	 * Grant the plugin's management capability to administrators.
	 * Other roles can be granted 'manage_blt_events' via a role editor.
	 */
	private static function grant_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( BLT_Events_Helpers::MANAGE_CAP ) ) {
			$role->add_cap( BLT_Events_Helpers::MANAGE_CAP );
		}
	}

	/**
	 * One-time migration from the legacy "CMT Events" v2 namespace.
	 *
	 * Renames tables (cmt_* → blt_*), copies options (cmt_events_* → blt_events_*),
	 * rewrites post_meta keys (_cmt_* → _blt_*), and re-slugs the coupon CPT
	 * (cmt_coupon → blt_coupon). Guarded by an option flag so it only runs once.
	 */
	private static function migrate_from_cmt() {
		if ( get_option( 'blt_events_migrated_from_cmt' ) ) {
			return;
		}

		global $wpdb;
		$prefix = $wpdb->prefix;

		// 1. Rename tables if the legacy names exist and the new ones do not.
		$rename_map = array(
			'cmt_fieldsets'     => 'blt_fieldsets',
			'cmt_registrations' => 'blt_registrations',
			'cmt_attendees'     => 'blt_attendees',
		);
		foreach ( $rename_map as $old => $new ) {
			$old_table = $prefix . $old;
			$new_table = $prefix . $new;
			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;
			if ( $old_exists && ! $new_exists ) {
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
			}
		}

		// 2. Copy option values from cmt_events_* → blt_events_* (only if not yet set).
		$option_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cmt_events_%'" );
		foreach ( $option_names as $old_name ) {
			$new_name = 'blt_events_' . substr( $old_name, strlen( 'cmt_events_' ) );
			if ( get_option( $new_name, null ) === null ) {
				update_option( $new_name, get_option( $old_name ) );
			}
		}

		// 3. Rewrite post_meta keys (_cmt_* → _blt_*) in bulk.
		$wpdb->query(
			"UPDATE {$wpdb->postmeta} SET meta_key = CONCAT('_blt_', SUBSTRING(meta_key, 6))
			 WHERE meta_key LIKE '\\_cmt\\_%'"
		);

		// 4. Re-slug the legacy coupon CPT.
		$wpdb->update( $wpdb->posts, array( 'post_type' => 'blt_coupon' ), array( 'post_type' => 'cmt_coupon' ) );

		// 5. Update the default fieldset slug if it still uses cmt-standard.
		$fieldsets_table = $prefix . 'blt_fieldsets';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fieldsets_table ) ) === $fieldsets_table ) {
			$wpdb->update(
				$fieldsets_table,
				array( 'slug' => 'blt-standard', 'name' => 'BLT Standard' ),
				array( 'slug' => 'cmt-standard' )
			);
		}

		update_option( 'blt_events_migrated_from_cmt', time() );
	}

	// ----- Tables -----

	private static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Fieldsets table
		$table_fieldsets = $wpdb->prefix . 'blt_fieldsets';
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
		$table_registrations = $wpdb->prefix . 'blt_registrations';
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
		$table_attendees = $wpdb->prefix . 'blt_attendees';
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

		update_option( 'blt_events_db_version', BLT_EVENTS_DB_VERSION );
	}

	// ----- Default fieldset -----

	/**
	 * Backfill for existing installs: if no default fieldset row exists
	 * (deleted, or the plugin was updated without re-activation), seed the
	 * standard one so users can always see and edit what the default is.
	 */
	public static function ensure_default_fieldset() {
		if ( ! class_exists( 'BLT_Events_Fieldsets_DB' ) ) {
			return;
		}

		$db = new BLT_Events_Fieldsets_DB();
		if ( $db->get_default() ) {
			return;
		}

		self::seed_default_fieldset();
	}

	private static function seed_default_fieldset() {
		global $wpdb;
		$table = $wpdb->prefix . 'blt_fieldsets';

		$exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE slug = 'blt-standard' OR is_default = 1" );
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
			'name'           => 'BLT Standard',
			'slug'           => 'blt-standard',
			'description'    => 'Default registration fieldset for BLT Association events.',
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
			'blt_events_payment_provider' => 'none',
			'blt_events_currency'         => 'USD',
			'blt_events_date_format'      => 'F j, Y',
		);

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key ) === false ) {
				update_option( $key, $value );
			}
		}
	}
}
