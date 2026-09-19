<?php
/**
 * Plugin activation, upgrades and defaults.
 *
 * Activation creates the custom tables, seeds the default fieldset, grants
 * capabilities and schedules the reminder task. The same routine runs as an
 * upgrade whenever the stored database version differs from the plugin's,
 * because updates delivered through the update checker never re-run the
 * activation hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Activator {

	const OPTION_DB_VERSION = 'blt_events_db_version';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Deactivation hook: stop the cron task, keep every bit of data.
	 */
	public static function deactivate() {
		if ( class_exists( 'BLT_Events_Reminders' ) ) {
			BLT_Events_Reminders::unschedule();
		}
		flush_rewrite_rules();
	}

	/**
	 * A new site on a network where the plugin is network-active gets its
	 * tables straight away.
	 *
	 * @param WP_Site $site The new site.
	 */
	public static function initialize_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( BLT_EVENTS_PLUGIN_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Run the upgrade routine when the plugin was updated without being
	 * re-activated. Cheap: one option read per request.
	 */
	public static function maybe_upgrade() {
		$stored = (string) get_option( self::OPTION_DB_VERSION, '' );

		if ( $stored === BLT_EVENTS_DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Install or upgrade the current site. Idempotent.
	 */
	public static function install() {
		$stored     = (string) get_option( self::OPTION_DB_VERSION, '' );
		$is_upgrade = '' !== $stored || self::is_installed();

		self::migrate_from_cmt();
		self::create_tables();
		self::remove_waitlist_data();
		self::seed_default_fieldset();
		self::set_default_options( $is_upgrade );
		self::grant_capabilities();

		if ( class_exists( 'BLT_Events_Reminders' ) ) {
			BLT_Events_Reminders::schedule();
		}

		update_option( self::OPTION_DB_VERSION, BLT_EVENTS_DB_VERSION );

		// Rewrite rules can only be flushed once the post type is registered;
		// on plugins_loaded it is not yet, so defer to init.
		if ( did_action( 'init' ) ) {
			flush_rewrite_rules();
		} else {
			add_action( 'init', 'flush_rewrite_rules', 99 );
		}

		/**
		 * Fires after the plugin installed or upgraded the current site.
		 *
		 * @param string $from_version Previously stored DB version ('' on a fresh install).
		 * @param bool   $is_upgrade   Whether data already existed.
		 */
		do_action( 'blt_events_installed', $stored, $is_upgrade );
	}

	/**
	 * Whether the registrations table already exists (an installed site
	 * that predates the version option).
	 */
	private static function is_installed() {
		global $wpdb;

		$table = $wpdb->prefix . 'blt_registrations';

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Remove data stored exclusively for the retired waitlist feature.
	 *
	 * Registrations that were already confirmed through the waitlist remain
	 * valid registrations. Their retired payment provider and marker are
	 * normalized so no waitlist-specific data remains after an upgrade.
	 */
	private static function remove_waitlist_data() {
		global $wpdb;

		$registrations_table = $wpdb->prefix . 'blt_registrations';
		$attendees_table     = $wpdb->prefix . 'blt_attendees';
		$registration_ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$registrations_table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'waitlisted'
			)
		);

		foreach ( $registration_ids as $registration_id ) {
			$wpdb->delete( $attendees_table, array( 'registration_id' => (int) $registration_id ), array( '%d' ) );
		}
		$wpdb->delete( $registrations_table, array( 'status' => 'waitlisted' ), array( '%s' ) );

		$legacy_registrations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, custom_fields FROM {$registrations_table} WHERE payment_provider = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'waitlist'
			)
		);
		foreach ( $legacy_registrations as $registration ) {
			$custom_fields = json_decode( $registration->custom_fields, true );
			if ( ! is_array( $custom_fields ) || ! array_key_exists( '_waitlist', $custom_fields ) ) {
				continue;
			}

			unset( $custom_fields['_waitlist'] );
			$wpdb->update(
				$registrations_table,
				array( 'custom_fields' => wp_json_encode( $custom_fields ) ),
				array( 'id' => (int) $registration->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
		$wpdb->update(
			$registrations_table,
			array( 'payment_provider' => 'free' ),
			array( 'payment_provider' => 'waitlist' ),
			array( '%s' ),
			array( '%s' )
		);

		delete_post_meta_by_key( '_blt_waitlist_enabled' );
		delete_option( 'blt_events_email_waitlist_enabled' );
		delete_option( 'blt_events_email_subject_waitlist' );
		delete_option( 'blt_events_email_template_waitlist' );
	}

	/**
	 * Grant the plugin's capabilities to the standard roles and create the
	 * Event Manager role.
	 */
	private static function grant_capabilities() {
		if ( class_exists( 'BLT_Events_Roles' ) ) {
			BLT_Events_Roles::install();
			return;
		}

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
			$old_table  = $prefix . $old;
			$new_table  = $prefix . $new;
			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;
			if ( $old_exists && ! $new_exists ) {
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		$sql_fieldsets   = "CREATE TABLE {$table_fieldsets} (
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
		$sql_registrations   = "CREATE TABLE {$table_registrations} (
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
			KEY idx_payment_id (payment_id),
			KEY idx_event_status (event_id, status)
		) {$charset};";

		// Attendees table (multi-attendee support)
		$table_attendees = $wpdb->prefix . 'blt_attendees';
		$sql_attendees   = "CREATE TABLE {$table_attendees} (
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

	/**
	 * The fields a fresh install starts with: a generic name/email/phone
	 * form. Sites that need more pick a preset in the builder or add fields.
	 *
	 * @return array
	 */
	public static function default_fields() {
		$fields = array(
			array(
				'key'      => 'first_name',
				'type'     => 'text',
				'label'    => __( 'First Name', 'blt-events' ),
				'required' => true,
				'width'    => 'half',
				'order'    => 0,
			),
			array(
				'key'      => 'last_name',
				'type'     => 'text',
				'label'    => __( 'Last Name', 'blt-events' ),
				'required' => true,
				'width'    => 'half',
				'order'    => 1,
			),
			array(
				'key'      => 'email',
				'type'     => 'email',
				'label'    => __( 'Email', 'blt-events' ),
				'required' => true,
				'width'    => 'full',
				'order'    => 2,
			),
			array(
				'key'         => 'mobile_number',
				'type'        => 'tel',
				'label'       => __( 'Phone', 'blt-events' ),
				'required'    => false,
				'width'       => 'full',
				'order'       => 3,
				'placeholder' => __( 'Include country code', 'blt-events' ),
			),
		);

		/**
		 * Filter the fields of the default fieldset seeded on a fresh install.
		 *
		 * @param array $fields Field definitions.
		 */
		$fields = apply_filters( 'blt_events_default_fieldset_fields', $fields );

		if ( class_exists( 'BLT_Events_Fieldsets' ) ) {
			$fields = array_map( array( 'BLT_Events_Fieldsets', 'normalize_field' ), (array) $fields );
		}

		return $fields;
	}

	private static function seed_default_fieldset() {
		global $wpdb;
		$table = $wpdb->prefix . 'blt_fieldsets';

		$exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_default = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			return;
		}

		$consent_fields = class_exists( 'BLT_Events_Fieldsets' )
			? BLT_Events_Fieldsets::default_consent_fields()
			: array();

		$slug = 'default';
		$db   = class_exists( 'BLT_Events_Fieldsets_DB' ) ? new BLT_Events_Fieldsets_DB() : null;
		if ( $db && $db->get_by_slug( $slug ) ) {
			$slug = 'default-' . wp_generate_password( 4, false, false );
		}

		$wpdb->insert( $table, array(
			'name'           => __( 'Default Registration Form', 'blt-events' ),
			'slug'           => $slug,
			'description'    => __( 'Name, email and phone. Used by every event that does not pick a fieldset of its own.', 'blt-events' ),
			'fields'         => wp_json_encode( self::default_fields() ),
			'consent_fields' => wp_json_encode( $consent_fields ),
			'is_default'     => 1,
			'status'         => 'active',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		) );
	}

	// ----- Default options -----

	/**
	 * Seed options that have never been saved.
	 *
	 * A fresh install gets the plug-and-play defaults. An upgrade seeds the
	 * value that preserves what the site was already doing, so an update
	 * never silently changes a live page; the admin can opt in afterwards.
	 *
	 * @param bool $is_upgrade Whether the plugin was already installed.
	 */
	private static function set_default_options( $is_upgrade ) {
		$defaults = array(
			'blt_events_payment_provider'          => 'none',
			'blt_events_date_format'               => 'F j, Y',
			'blt_events_display_currency_sign'     => '1',
			'blt_events_schema_enabled'            => '1',
			'blt_events_email_pending_enabled'     => '1',
			'blt_events_single_show_featured'      => '1',
			'blt_events_single_show_back'          => '1',
			'blt_events_single_show_calendar_links' => '1',
			'blt_events_single_show_title'         => '1',
			'blt_events_archive_mode'              => $is_upgrade ? 'theme' : 'plugin',
			'blt_events_reminder_24h_enabled'      => $is_upgrade ? '0' : '1',
			'blt_events_reminder_1h_enabled'       => $is_upgrade ? '0' : '1',
			'blt_events_admin_notify_enabled'      => $is_upgrade ? '0' : '1',
			'blt_events_email_wrapper_enabled'     => $is_upgrade ? '0' : '1',
		);

		/**
		 * Filter the options seeded on install/upgrade.
		 *
		 * @param array $defaults   Option name => value.
		 * @param bool  $is_upgrade Whether the plugin was already installed.
		 */
		$defaults = apply_filters( 'blt_events_default_options', $defaults, $is_upgrade );

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key, null ) === null ) {
				update_option( $key, $value );
			}
		}
	}
}
