<?php
/**
 * BLT Events - Admin Menu Manager
 *
 * Registers admin menu and submenu items under the Events CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_pages' ) );
	}

	public static function add_admin_pages() {
		// Registrations submenu
		add_submenu_page(
			'edit.php?post_type=event',
			__( 'Registrations', 'blt-events' ),
			__( 'Registrations', 'blt-events' ),
			BLT_Events_Helpers::menu_capability(),
			'blt-registrations',
			array( 'BLT_Events_Registrations_List', 'render_page' )
		);

		// Fieldset Builder submenu
		add_submenu_page(
			'edit.php?post_type=event',
			__( 'Fieldsets', 'blt-events' ),
			__( 'Fieldsets', 'blt-events' ),
			BLT_Events_Helpers::menu_capability(),
			'blt-fieldsets',
			array( 'BLT_Events_Fieldset_Builder', 'render_page' )
		);

		// Settings submenu
		add_submenu_page(
			'edit.php?post_type=event',
			__( 'Settings', 'blt-events' ),
			__( 'Settings', 'blt-events' ),
			BLT_Events_Helpers::menu_capability(),
			'blt-events-settings',
			array( 'BLT_Events_Admin_Settings', 'render_settings_page' )
		);
	}
}
