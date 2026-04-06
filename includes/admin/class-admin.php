<?php
/**
 * ZymEvents - Admin Menu Manager
 *
 * Registers admin menu and submenu items under the Events CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZymEvents_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_pages' ) );
	}

	public static function add_admin_pages() {
		// Registrations submenu
		add_submenu_page(
			'edit.php?post_type=event',
			'Registrations',
			'Registrations',
			'manage_options',
			'zymevents-registrations',
			array( 'ZymEvents_Registrations_List', 'render_page' )
		);

		// Fieldset Builder submenu
		add_submenu_page(
			'edit.php?post_type=event',
			'Fieldsets',
			'Fieldsets',
			'manage_options',
			'zymevents-fieldsets',
			array( 'ZymEvents_Fieldset_Builder', 'render_page' )
		);

		// Settings submenu
		add_submenu_page(
			'edit.php?post_type=event',
			'Settings',
			'Settings',
			'manage_options',
			'zymevents-settings',
			array( 'ZymEvents_Admin_Settings', 'render_settings_page' )
		);
	}
}
