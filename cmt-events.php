<?php
/**
 * Plugin Name: ZymEvents
 * Plugin URI:  https://s-fx.com
 * Description: A comprehensive event registration system with configurable forms, multi-attendee support, and payment gateway integration.
 * Version:     2.0.0
 * Author:      S-FX.COM
 * Author URI:  https://s-fx.com
 * License:     GPL2
 * Text Domain: zymevents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'ZYMEVENTS_VERSION', '2.0.0' );
define( 'ZYMEVENTS_DB_VERSION', '1.0' );
define( 'ZYMEVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZYMEVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZYMEVENTS_PLUGIN_FILE', __FILE__ );
define( 'ZYMEVENTS_PREFIX', '_zymevents_' );
define( 'ZYMEVENTS_TEXT_DOMAIN', 'zymevents' );

// Stripe Connect platform client ID.
// Override in wp-config.php: define( 'ZYMEVENTS_STRIPE_CLIENT_ID', 'ca_XXXXXXXXXX' );
if ( ! defined( 'ZYMEVENTS_STRIPE_CLIENT_ID' ) ) {
	define( 'ZYMEVENTS_STRIPE_CLIENT_ID', '' );
}

// ---------- Autoloader ----------
spl_autoload_register( function ( $class ) {
	$prefix = 'ZymEvents_';
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
		$file = ZYMEVENTS_PLUGIN_DIR . $map[ $relative ];
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
});

// ---------- Activation / Deactivation ----------
register_activation_hook( __FILE__, array( 'ZymEvents_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
});

// ---------- Boot ----------
function zymevents_init() {
	// Data layer is loaded on-demand via autoloader.

	// CPTs
	ZymEvents_Event_CPT::init();
	ZymEvents_Coupon_CPT::init();

	// Business logic
	ZymEvents_Fieldsets::init();
	ZymEvents_Registrations::init();
	ZymEvents_Coupons::init();

	// Payment
	ZymEvents_Stripe_Handler::init();
	ZymEvents_SureCart_Integration::init();

	// Admin
	if ( is_admin() ) {
		ZymEvents_Admin::init();
		ZymEvents_Admin_Settings::init();
		ZymEvents_Event_Metabox::init();
		ZymEvents_Fieldset_Builder::init();
		ZymEvents_Registrations_List::init();
	}

	// Shortcodes
	ZymEvents_Registration_Shortcode::init();
	ZymEvents_Calendar_Shortcode::init();

	// REST API
	ZymEvents_REST_Registrations::init();
	ZymEvents_REST_Fieldsets::init();

	// FluentCRM add-on (only when FluentCRM is active)
	if ( defined( 'FLUENTCRM' ) ) {
		ZymEvents_FluentCRM_Addon::init();
	}
}
add_action( 'plugins_loaded', 'zymevents_init' );

// ---------- Assets ----------
function zymevents_enqueue_public_assets() {
	wp_enqueue_style(
		'zymevents',
		ZYMEVENTS_PLUGIN_URL . 'assets/css/zymevents.css',
		array(),
		ZYMEVENTS_VERSION
	);

	wp_enqueue_script(
		'zymevents',
		ZYMEVENTS_PLUGIN_URL . 'assets/js/zymevents.js',
		array( 'jquery' ),
		ZYMEVENTS_VERSION,
		true
	);

	wp_localize_script( 'zymevents', 'zymEventsData', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'restUrl'  => rest_url( 'zymevents/v1/' ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'currency' => ZymEvents_Helpers::get_currency_config(),
	));
}
add_action( 'wp_enqueue_scripts', 'zymevents_enqueue_public_assets' );

function zymevents_enqueue_admin_assets( $hook ) {
	wp_enqueue_style(
		'zymevents-admin',
		ZYMEVENTS_PLUGIN_URL . 'assets/css/admin.css',
		array(),
		ZYMEVENTS_VERSION
	);

	wp_enqueue_script(
		'zymevents-admin',
		ZYMEVENTS_PLUGIN_URL . 'assets/js/admin.js',
		array( 'jquery' ),
		ZYMEVENTS_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'zymevents_enqueue_admin_assets' );
