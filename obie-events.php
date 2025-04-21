<?php
/*
Plugin Name: Obie Events
Plugin URI: edit.php?post_type=event&page=obie-events-settings
Description: A plugin to manage events, tickets, and registrations
Version: 1.0
Author: S-FX.COM
Author URI: https://s-fx.com
License: GPL2
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OBIE_EVENTS_VERSION', '1.0');
define('OBIE_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OBIE_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OBIE_EVENTS_PLUGIN_PATH', 'obie-events');
define('OBIE_EVENTS_PLUGIN_PREFIX', 'oe_');

// Include required files
$includes = [
    'includes/helpers.php',
    'includes/admin/admin-settings.php',
    'includes/cpt/events.php',
    'includes/cpt/registrations.php',
    'includes/cpt/coupons.php',
    'includes/shortcodes.php',
    'includes/registrations.php',
    'includes/coupons.php',
    'includes/payment/stripe-handler.php',
];

foreach ($includes as $file) {
    $file_path = OBIE_EVENTS_PLUGIN_DIR . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        error_log("Obie Events Plugin: Missing file - $file_path");
    }
}

// Initialize the plugin
function Obie_Events_Init()
{
    // Check if classes exist before initializing them
    if (class_exists('Obie_Events_Admin_Settings')) {
        Obie_Events_Admin_Settings::init();
    }

    if (class_exists('Obie_Events_CPT')) {
        Obie_Events_CPT::init();
    }

    if (class_exists('Obie_Events_Registrations_CPT')) {
        Obie_Events_Registrations_CPT::init();
    }

    if (class_exists('Obie_Events_Coupons_CPT')) {
        Obie_Events_Coupons_CPT::init();
    }

    if (class_exists('Obie_Events_Shortcodes')) {
        Obie_Events_Shortcodes::init();
    }

    if (class_exists('Obie_Events_Registrations')) {
        Obie_Events_Registrations::init();
    }

    if (class_exists('Obie_Events_Coupons')) {
        Obie_Events_Coupons::init();
    }

    if (class_exists('Obie_Events_Stripe')) {
        Obie_Events_Stripe::init();
    }
}

add_action('plugins_loaded', 'Obie_Events_Init');

function obie_events_enqueue_assets() 
{
    wp_enqueue_style('obie-events-styles', OBIE_EVENTS_PLUGIN_URL . 'assets/css/obie-events.css', array(), false);
    wp_enqueue_script('obie-events-js', OBIE_EVENTS_PLUGIN_URL . 'assets/js/obie-events.js', array('jquery'), false, true);

    wp_localize_script('obie-events-js', 'obieEventData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'currency' => get_option('obie_events_currency'),
        'showCurrency' => get_option('obie_events_display_currency'),
        'showSymbol' => get_option('obie_events_display_currency_sign'),
        'currencySymbol' => array('USD' => '$'),
    ));
}

add_action('wp_enqueue_scripts', 'obie_events_enqueue_assets');

function obie_events_enqueue_admin_assets($hook) 
{
    wp_enqueue_style('obie-events-admin-styles', OBIE_EVENTS_PLUGIN_URL . 'assets/css/admin.css', array(), false);
    wp_enqueue_script('obie-events-admin-js', OBIE_EVENTS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), false, true);
}

add_action('admin_enqueue_scripts', 'obie_events_enqueue_admin_assets');