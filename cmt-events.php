<?php
/**
 * Plugin Name: CMT Events
 * Plugin URI:  https://s-fx.com
 * Description: A comprehensive event registration system with configurable forms, multi-attendee support, and payment gateway integration.
 * Version:     2.0.0
 * Author:      S-FX.COM
 * Author URI:  https://s-fx.com
 * License:     GPL2
 * Text Domain: cmt-events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'CMT_EVENTS_VERSION', '2.0.0' );
define( 'CMT_EVENTS_DB_VERSION', '1.0' );
define( 'CMT_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMT_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CMT_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'CMT_EVENTS_PREFIX', '_cmt_' );
define( 'CMT_EVENTS_TEXT_DOMAIN', 'cmt-events' );

// ---------- Autoloader ----------
spl_autoload_register( function ( $class ) {
	$prefix = 'CMT_Events_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$relative = strtolower( str_replace( '_', '-', $relative ) );

	$map = array(
		// Foundation
		'activator'              => 'includes/class-activator.php',
		'helpers'                => 'includes/class-helpers.php',
		// DB
		'db'                     => 'includes/db/class-db.php',
		'fieldsets-db'           => 'includes/db/class-fieldsets-db.php',
		'registrations-db'       => 'includes/db/class-registrations-db.php',
		'attendees-db'           => 'includes/db/class-attendees-db.php',
		// CPT
		'event-cpt'              => 'includes/cpt/class-event-cpt.php',
		'coupon-cpt'             => 'includes/cpt/class-coupon-cpt.php',
		// Business logic
		'fieldsets'              => 'includes/class-fieldsets.php',
		'registrations'          => 'includes/class-registrations.php',
		'coupons'                => 'includes/class-coupons.php',
		// Payment
		'payment-provider'       => 'includes/payment/class-payment-provider.php',
		'stripe-handler'         => 'includes/payment/class-stripe-handler.php',
		'surecart-integration'   => 'includes/payment/class-surecart-integration.php',
		// Admin
		'admin'                  => 'includes/admin/class-admin.php',
		'admin-settings'         => 'includes/admin/class-admin-settings.php',
		'event-metabox'          => 'includes/admin/class-event-metabox.php',
		'fieldset-builder'       => 'includes/admin/class-fieldset-builder.php',
		'registrations-list'     => 'includes/admin/class-registrations-list.php',
		// Shortcodes
		'registration-shortcode' => 'includes/shortcodes/class-registration-shortcode.php',
		'calendar-shortcode'     => 'includes/shortcodes/class-calendar-shortcode.php',
		// REST API
		'rest-registrations'     => 'includes/api/class-rest-registrations.php',
		'rest-fieldsets'         => 'includes/api/class-rest-fieldsets.php',
		// Add-ons
		'fluentcrm-addon'        => 'includes/addons/fluentcrm/class-fluentcrm-addon.php',
	);

	if ( isset( $map[ $relative ] ) ) {
		$file = CMT_EVENTS_PLUGIN_DIR . $map[ $relative ];
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
});

// ---------- Activation / Deactivation ----------
register_activation_hook( __FILE__, array( 'CMT_Events_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
});

// ---------- Boot ----------
function cmt_events_init() {
	// Data layer is loaded on-demand via autoloader.

	// CPTs
	CMT_Events_Event_CPT::init();
	CMT_Events_Coupon_CPT::init();

	// Business logic
	CMT_Events_Fieldsets::init();
	CMT_Events_Registrations::init();
	CMT_Events_Coupons::init();

	// Payment
	CMT_Events_Stripe_Handler::init();
	CMT_Events_SureCart_Integration::init();

	// Admin
	if ( is_admin() ) {
		CMT_Events_Admin::init();
		CMT_Events_Admin_Settings::init();
		CMT_Events_Event_Metabox::init();
		CMT_Events_Fieldset_Builder::init();
		CMT_Events_Registrations_List::init();
	}

	// Shortcodes
	CMT_Events_Registration_Shortcode::init();
	CMT_Events_Calendar_Shortcode::init();

	// REST API
	CMT_Events_REST_Registrations::init();
	CMT_Events_REST_Fieldsets::init();

	// FluentCRM add-on (only when FluentCRM is active)
	if ( defined( 'FLUENTCRM' ) ) {
		CMT_Events_FluentCRM_Addon::init();
	}
}
add_action( 'plugins_loaded', 'cmt_events_init' );

// ---------- Assets ----------
function cmt_events_enqueue_public_assets() {
	wp_enqueue_style(
		'cmt-events',
		CMT_EVENTS_PLUGIN_URL . 'assets/css/cmt-events.css',
		array(),
		CMT_EVENTS_VERSION
	);

	wp_enqueue_script(
		'cmt-events',
		CMT_EVENTS_PLUGIN_URL . 'assets/js/cmt-events.js',
		array( 'jquery' ),
		CMT_EVENTS_VERSION,
		true
	);

	wp_localize_script( 'cmt-events', 'cmtEventsData', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'restUrl'  => rest_url( 'cmt-events/v1/' ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'currency' => CMT_Events_Helpers::get_currency_config(),
	));
}
add_action( 'wp_enqueue_scripts', 'cmt_events_enqueue_public_assets' );

function cmt_events_enqueue_admin_assets( $hook ) {
	wp_enqueue_style(
		'cmt-events-admin',
		CMT_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
		array(),
		CMT_EVENTS_VERSION
	);

	wp_enqueue_script(
		'cmt-events-admin',
		CMT_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
		array( 'jquery' ),
		CMT_EVENTS_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'cmt_events_enqueue_admin_assets' );
