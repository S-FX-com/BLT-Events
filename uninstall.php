<?php
/**
 * BLT Events - Uninstall
 *
 * Runs when the plugin is deleted from the Plugins screen (never on
 * deactivation). Nothing is removed unless the site owner switched on
 * "Delete all plugin data when the plugin is deleted" in Settings > General,
 * so an accidental delete-and-reinstall keeps every registration.
 *
 * The shared BLT family option (blt_family_shared) is never touched: other
 * BLT plugins on the site still resolve credentials through it.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove every trace of the plugin from the current site.
 */
function blt_events_uninstall_site() {
	global $wpdb;

	if ( '1' !== (string) get_option( 'blt_events_delete_data_on_uninstall', '0' ) ) {
		return;
	}

	// Cron.
	$timestamp = wp_next_scheduled( 'blt_events_send_reminders' );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'blt_events_send_reminders' );
		$timestamp = wp_next_scheduled( 'blt_events_send_reminders' );
	}

	// Posts: events and coupons (post meta goes with them).
	$post_ids = get_posts( array(
		'post_type'      => array( 'event', 'blt_coupon' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	// Taxonomy terms.
	$terms = get_terms( array(
		'taxonomy'   => 'event_category',
		'hide_empty' => false,
		'fields'     => 'ids',
	) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, 'event_category' );
		}
	}

	// Custom tables.
	foreach ( array( 'blt_attendees', 'blt_registrations', 'blt_fieldsets' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// Options and transients. blt_family_shared is not prefixed blt_events_
	// and is deliberately left alone.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'blt\\_events\\_%'
		    OR option_name LIKE '\\_transient\\_blt\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_blt\\_%'
		    OR option_name LIKE '\\_site\\_transient\\_blt\\_%'
		    OR option_name LIKE '\\_site\\_transient\\_timeout\\_blt\\_%'"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Update-checker state for this plugin.
	delete_option( 'external_updates-blt-events' );
	delete_site_option( 'external_updates-blt-events' );

	// Capabilities and the Event Manager role.
	$caps = array(
		'manage_blt_events',
		'edit_blt_event',
		'read_blt_event',
		'delete_blt_event',
		'edit_blt_events',
		'edit_others_blt_events',
		'publish_blt_events',
		'read_private_blt_events',
		'delete_blt_events',
		'delete_private_blt_events',
		'delete_published_blt_events',
		'delete_others_blt_events',
		'edit_private_blt_events',
		'edit_published_blt_events',
		'create_blt_events',
	);
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}
	remove_role( 'blt_event_manager' );

	// Per-user flags: setup-card dismissal, editor layout version.
	delete_metadata( 'user', 0, 'blt_events_setup_dismissed', '', true );
	delete_metadata( 'user', 0, 'blt_events_editor_layout', '', true );
}

if ( is_multisite() ) {
	$blt_events_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $blt_events_site_ids as $blt_events_site_id ) {
		switch_to_blog( $blt_events_site_id );
		blt_events_uninstall_site();
		restore_current_blog();
	}
} else {
	blt_events_uninstall_site();
}
