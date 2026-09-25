<?php
/**
 * Plugin Name:       BLT Events
 * Plugin URI:        https://github.com/S-FX-com/BLT-Events
 * Description:       Event registration for WordPress: calendar and list views, ticket types, configurable registration forms, multi-attendee bookings, waitlists, reminders, Stripe, SureCart and FluentCart checkout, and Zoom, Teams, GoTo and ClickMeeting rooms.
 * Version:           2.4.5
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            S-FX.com
 * Author URI:        https://www.s-fx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-events
 * Domain Path:       /languages
 * Update URI:        https://github.com/S-FX-com/BLT-Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'BLT_EVENTS_VERSION', '2.4.5' );
// Bumped whenever install()/upgrade work has to run on sites updated
// without re-activation (schema, roles, cron, seeded options).
define( 'BLT_EVENTS_DB_VERSION', '1.2' );
define( 'BLT_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'BLT_EVENTS_PREFIX', '_blt_' );
define( 'BLT_EVENTS_TEXT_DOMAIN', 'blt-events' );

// ---------- BLT family layer ----------
// Shared connection settings, the BLT mark, and the family update policy.
// Registered during load (not on a hook) so the registry is complete before
// the library boots on plugins_loaded, and before the update policy below
// needs BLT_Family_Updates.
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/blt-family/bootstrap.php';

blt_family_register(
	BLT_EVENTS_PLUGIN_FILE,
	array(
		'name'    => 'BLT Events',
		'slug'    => 'blt-events',
		'version' => BLT_EVENTS_VERSION,
		// A relative URL, not a bare slug: this page is a submenu of
		// edit.php?post_type=event, and WordPress dispatches a submenu callback
		// through its parent — admin.php?page=blt-events-settings would not reach it.
		'menu'    => 'edit.php?post_type=event&page=blt-events-settings',
		'groups'  => array( 'stripe', 'surecart', 'microsoft', 'google' ),
	)
);

// ---------- Update checker ----------
// Serves updates from GitHub releases (zip asset built by .github/workflows/release.yml).
require_once BLT_EVENTS_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

// The 24 is required: a checker built with a 0 check period registers no
// scheduler hooks at all and cannot be revived afterwards.
$blt_events_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/S-FX-com/BLT-Events/',
	__FILE__,
	'blt-events',
	24
);
$blt_events_update_checker->getVcsApi()->enableReleaseAssets();

// Family update policy: at most one automatic check per day, anchored to
// 00:00 site time, with manual checks always allowed immediately.
BLT_Family_Updates::apply(
	$blt_events_update_checker,
	array(
		'basename'  => plugin_basename( __FILE__ ),
		'icons_url' => BLT_EVENTS_PLUGIN_URL . 'assets/img/',
	)
);

// ---------- Autoloader ----------
spl_autoload_register( function ( $class ) {
	$prefix = 'BLT_Events_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$relative = strtolower( str_replace( '_', '-', $relative ) );

	$map = array(
		// Foundation
		'activator'                => 'includes/class-activator.php',
		'helpers'                  => 'includes/class-helpers.php',
		'templates'                => 'includes/class-templates.php',
		'roles'                    => 'includes/class-roles.php',
		'emails'                   => 'includes/class-emails.php',
		'reminders'                => 'includes/class-reminders.php',
		// DB
		'db'                       => 'includes/db/class-db.php',
		'fieldsets-db'             => 'includes/db/class-fieldsets-db.php',
		'registrations-db'         => 'includes/db/class-registrations-db.php',
		'attendees-db'             => 'includes/db/class-attendees-db.php',
		// CPT
		'event-cpt'                => 'includes/cpt/class-event-cpt.php',
		'event-meta'               => 'includes/cpt/class-event-meta.php',
		'coupon-cpt'               => 'includes/cpt/class-coupon-cpt.php',
		// Business logic
		'fieldsets'                => 'includes/class-fieldsets.php',
		'registrations'            => 'includes/class-registrations.php',
		'coupons'                  => 'includes/class-coupons.php',
		// Payment
		'payment-provider'         => 'includes/payment/class-payment-provider.php',
		'payment-providers'        => 'includes/payment/class-payment-providers.php',
		'stripe-handler'           => 'includes/payment/class-stripe-handler.php',
		'surecart-integration'     => 'includes/payment/class-surecart-integration.php',
		'fluentcart-integration'   => 'includes/payment/class-fluentcart-integration.php',
		// Meeting integrations
		'meeting-provider'         => 'includes/integrations/class-meeting-provider.php',
		'meeting-providers'        => 'includes/integrations/class-meeting-providers.php',
		'zoom-integration'         => 'includes/integrations/class-zoom-integration.php',
		'teams-integration'        => 'includes/integrations/class-teams-integration.php',
		'goto-integration'         => 'includes/integrations/class-goto-integration.php',
		'clickmeeting-integration' => 'includes/integrations/class-clickmeeting-integration.php',
		// Admin
		'admin'                    => 'includes/admin/class-admin.php',
		'admin-settings'           => 'includes/admin/class-admin-settings.php',
		'setup'                    => 'includes/admin/class-setup.php',
		'event-metabox'            => 'includes/admin/class-event-metabox.php',
		'fieldset-builder'         => 'includes/admin/class-fieldset-builder.php',
		'registrations-list'       => 'includes/admin/class-registrations-list.php',
		'event-migration'          => 'includes/admin/class-event-migration.php',
		// Shortcodes
		'registration-shortcode'   => 'includes/shortcodes/class-registration-shortcode.php',
		'calendar-shortcode'       => 'includes/shortcodes/class-calendar-shortcode.php',
		// Front end
		'appearance'               => 'includes/frontend/class-appearance.php',
		'single-event'             => 'includes/frontend/class-single-event.php',
		'presenters'               => 'includes/frontend/class-presenters.php',
		'schema'                   => 'includes/frontend/class-schema.php',
		'archive'                  => 'includes/frontend/class-archive.php',
		'blocks'                   => 'includes/frontend/class-blocks.php',
		// REST API
		'rest-registrations'       => 'includes/api/class-rest-registrations.php',
		'rest-fieldsets'           => 'includes/api/class-rest-fieldsets.php',
		// Add-ons
		'fluentcrm-addon'          => 'includes/addons/fluentcrm/class-fluentcrm-addon.php',
	);

	if ( isset( $map[ $relative ] ) ) {
		$file = BLT_EVENTS_PLUGIN_DIR . $map[ $relative ];
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
});

// ---------- Activation / Deactivation ----------
register_activation_hook( __FILE__, array( 'BLT_Events_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BLT_Events_Activator', 'deactivate' ) );
add_action( 'wp_initialize_site', array( 'BLT_Events_Activator', 'initialize_site' ), 20 );

// ---------- i18n ----------
function blt_events_load_textdomain() {
	load_plugin_textdomain( 'blt-events', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'blt_events_load_textdomain' );

// ---------- Boot ----------
function blt_events_init() {
	// Schema, roles, cron and seeded options for sites updated without
	// re-activation. One option read when nothing has changed.
	BLT_Events_Activator::maybe_upgrade();

	// CPTs
	BLT_Events_Event_CPT::init();
	BLT_Events_Event_Meta::init();
	BLT_Events_Coupon_CPT::init();

	// Business logic
	BLT_Events_Fieldsets::init();
	BLT_Events_Registrations::init();
	BLT_Events_Coupons::init();
	BLT_Events_Emails::init();
	BLT_Events_Reminders::init();

	// Payment. Every enabled provider boots, not just the site default, so
	// orders and refunds keep resolving for events that check out elsewhere.
	BLT_Events_Payment_Providers::init();

	// A saved event, or a change to either payment setting, can change which
	// providers are in use on the front end. Priority 25 puts the flush after
	// the metabox has written the event's provider (10) and after the provider
	// product syncs (20), so it never re-caches the pre-save answer.
	add_action( 'save_post_event', array( 'BLT_Events_Payment_Provider', 'flush_usage_cache' ), 25 );
	add_action( 'save_post_event', array( 'BLT_Events_Setup', 'flush_cache' ), 25 );
	add_action( 'update_option_' . BLT_Events_Payment_Providers::OPTION_DEFAULT, array( 'BLT_Events_Payment_Provider', 'flush_usage_cache' ) );
	add_action( 'update_option_' . BLT_Events_Payment_Providers::OPTION_ENABLED, array( 'BLT_Events_Payment_Provider', 'flush_usage_cache' ) );

	// Meeting integrations (settings, OAuth routes, room creation, attendee sync)
	BLT_Events_Meeting_Providers::init();

	// Admin
	if ( is_admin() ) {
		BLT_Events_Admin::init();
		BLT_Events_Admin_Settings::init();
		BLT_Events_Setup::init();
		BLT_Events_Event_Metabox::init();
		BLT_Events_Fieldset_Builder::init();
		BLT_Events_Registrations_List::init();
		BLT_Events_Event_Migration::init();
	}

	// Shortcodes and blocks
	BLT_Events_Registration_Shortcode::init();
	BLT_Events_Calendar_Shortcode::init();
	BLT_Events_Blocks::init();

	// Front-end styling mode and design tokens (registers the layer every
	// other plugin stylesheet depends on, so it boots before them).
	BLT_Events_Appearance::init();

	// Front-end single event view, archive, structured data
	BLT_Events_Single_Event::init();
	BLT_Events_Presenters::init();
	BLT_Events_Schema::init();
	BLT_Events_Archive::init();

	// REST API
	BLT_Events_REST_Registrations::init();
	BLT_Events_REST_Fieldsets::init();

	// FluentCRM add-on (only when FluentCRM is active)
	if ( defined( 'FLUENTCRM' ) ) {
		BLT_Events_FluentCRM_Addon::init();
	}

	/**
	 * Fires once the plugin has booted. Add-ons hook here.
	 */
	do_action( 'blt_events_loaded' );
}
add_action( 'plugins_loaded', 'blt_events_init' );

// ---------- Assets ----------
/**
 * Whether the current front-end request needs the plugin's assets:
 * event singles/archives, or content containing one of the shortcodes or
 * blocks. Use the blt_events_enqueue_assets filter to force-load them on
 * pages where the shortcode is rendered outside post content (widgets,
 * page-builder templates).
 */
function blt_events_should_enqueue_assets() {
	if ( is_singular( 'event' ) || is_post_type_archive( 'event' ) || is_tax( 'event_category' ) ) {
		return true;
	}

	if ( is_singular() ) {
		$post = get_post();
		if ( $post && (
			has_shortcode( $post->post_content, 'blt_event_registration' )
			|| has_shortcode( $post->post_content, 'blt_events_calendar' )
			|| has_block( 'blt-events/calendar', $post )
			|| has_block( 'blt-events/registration-form', $post )
		) ) {
			return true;
		}
	}

	return (bool) apply_filters( 'blt_events_enqueue_assets', false );
}

function blt_events_enqueue_public_assets() {
	// Always register so shortcodes rendered outside post content
	// (widgets, page-builder templates) can late-enqueue by handle.
	// The token layer is a dependency, not an assumption: it guarantees the
	// custom properties exist before this stylesheet reads them, whichever
	// view happens to enqueue first.
	wp_register_style(
		'blt-events',
		BLT_EVENTS_PLUGIN_URL . 'assets/css/blt-events.css',
		BLT_Events_Appearance::style_deps(),
		BLT_EVENTS_VERSION
	);

	wp_register_script(
		'blt-events',
		BLT_EVENTS_PLUGIN_URL . 'assets/js/blt-events.js',
		array( 'jquery' ),
		BLT_EVENTS_VERSION,
		true
	);

	wp_localize_script( 'blt-events', 'bltEventsData', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'restUrl'  => rest_url( 'blt-events/v1/' ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'currency' => BLT_Events_Helpers::get_currency_config(),
	));

	if ( blt_events_should_enqueue_assets() ) {
		if ( BLT_Events_Appearance::styles_enabled() ) {
			wp_enqueue_style( 'blt-events' );
		}
		wp_enqueue_script( 'blt-events' );
	}
}
add_action( 'wp_enqueue_scripts', 'blt_events_enqueue_public_assets' );

/**
 * The admin screens this plugin owns, by hook suffix. Matching against the
 * exact list (rather than a "blt-" prefix) keeps this plugin's admin.css off
 * the screens of the other BLT plugins on the same site.
 *
 * @return string[]
 */
function blt_events_admin_hooks() {
	return (array) apply_filters( 'blt_events_admin_hooks', array(
		'event_page_blt-registrations',
		'event_page_blt-fieldsets',
		'event_page_blt-events-settings',
		'event_page_blt-migration',
	) );
}

function blt_events_enqueue_admin_assets( $hook ) {
	// Only load on the plugin's own screens: event/coupon editors and
	// list tables, plus the plugin's submenu pages.
	$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$post_type = $screen->post_type ?? '';

	if ( ! in_array( $post_type, array( 'event', 'blt_coupon' ), true ) && ! in_array( $hook, blt_events_admin_hooks(), true ) ) {
		return;
	}

	// Shared component library (cards, fields, toggles, badges) used by
	// every custom admin screen the plugin adds; see DESIGN-SYSTEM.md.
	wp_enqueue_style(
		'blt-events-design-system',
		BLT_EVENTS_PLUGIN_URL . 'assets/css/blt-design-system.css',
		array(),
		BLT_EVENTS_VERSION
	);

	wp_enqueue_style(
		'blt-events-admin',
		BLT_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
		array( 'blt-events-design-system' ),
		BLT_EVENTS_VERSION
	);

	wp_enqueue_script(
		'blt-events-admin',
		BLT_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
		array( 'jquery' ),
		BLT_EVENTS_VERSION,
		true
	);

	// The Settings screen gets its own tabbed, card-based UI.
	if ( 'event_page_blt-events-settings' === $hook ) {
		wp_enqueue_style(
			'blt-events-settings',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/settings.css',
			array( 'blt-events-admin' ),
			BLT_EVENTS_VERSION
		);

		wp_enqueue_script(
			'blt-events-settings',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/settings.js',
			array( 'jquery' ),
			BLT_EVENTS_VERSION,
			true
		);
	}

	// The Add/Edit Event screen gets its own card-based editor UI.
	if ( $screen && 'post' === $screen->base && 'event' === $post_type ) {
		// Presenter photos and sponsor logos use the WordPress media library.
		wp_enqueue_media();

		wp_enqueue_style(
			'blt-events-event-editor',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/event-editor.css',
			array( 'dashicons', 'blt-events-admin' ),
			BLT_EVENTS_VERSION
		);

		wp_enqueue_script(
			'blt-events-event-editor',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/event-editor.js',
			// jquery-ui-sortable reorders sponsor logos by dragging.
			array( 'jquery', 'jquery-ui-sortable' ),
			BLT_EVENTS_VERSION,
			true
		);

		$currency = BLT_Events_Helpers::get_currency_config();
		wp_localize_script( 'blt-events-event-editor', 'bltEventEditor', array(
			'paid'           => __( 'Paid', 'blt-events' ),
			'free'           => __( 'Free', 'blt-events' ),
			'currencySymbol' => $currency['currencySymbol'] ?: '$',
			'allDayNotice'   => __( 'Time fields are hidden because this is an all-day event.', 'blt-events' ),
			'noEndNotice'    => __( 'End date and end time are hidden because no end time is set.', 'blt-events' ),
			'mapPlaceholder' => __( 'Map preview after address is entered', 'blt-events' ),
			'mapTitle'       => __( 'Venue map preview', 'blt-events' ),
			'sponsorTitle'   => __( 'Select sponsor logos', 'blt-events' ),
			'sponsorButton'  => __( 'Add to sponsors', 'blt-events' ),
		) );
	}
}
add_action( 'admin_enqueue_scripts', 'blt_events_enqueue_admin_assets' );
