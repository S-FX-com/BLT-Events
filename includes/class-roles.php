<?php
/**
 * BLT Events - Roles and capabilities
 *
 * The event post type has its own capabilities (edit_blt_events, ...)
 * instead of borrowing the ones for posts, so a site can decide who
 * manages events without also handing out access to the blog. Standard
 * roles get the same access they had before (administrators and editors
 * everything, authors their own events), and a dedicated "Event Manager"
 * role bundles the event capabilities with the plugin's admin screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Roles {

	const MANAGER_ROLE = 'blt_event_manager';

	/**
	 * Every capability the event post type maps to (map_meta_cap => true).
	 *
	 * @return string[]
	 */
	public static function event_caps() {
		return array(
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
	}

	/**
	 * Capabilities per built-in role. Mirrors what each role can do with posts.
	 *
	 * @return array<string,string[]>
	 */
	public static function role_caps() {
		$all = self::event_caps();

		$author = array(
			'edit_blt_event',
			'read_blt_event',
			'delete_blt_event',
			'edit_blt_events',
			'publish_blt_events',
			'delete_blt_events',
			'delete_published_blt_events',
			'edit_published_blt_events',
			'create_blt_events',
		);

		$contributor = array(
			'edit_blt_event',
			'read_blt_event',
			'delete_blt_event',
			'edit_blt_events',
			'delete_blt_events',
			'create_blt_events',
		);

		$map = array(
			'administrator' => array_merge( $all, array( BLT_Events_Helpers::MANAGE_CAP ) ),
			'editor'        => array_merge( $all, array( BLT_Events_Helpers::MANAGE_CAP ) ),
			'author'        => $author,
			'contributor'   => $contributor,
		);

		/**
		 * Filter the event capabilities granted to each role on install/upgrade.
		 *
		 * @param array $map Role slug => capability list.
		 */
		return apply_filters( 'blt_events_role_caps', $map );
	}

	/**
	 * Grant capabilities and create the Event Manager role. Safe to run
	 * repeatedly (activation, upgrade).
	 */
	public static function install() {
		foreach ( self::role_caps() as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		$manager_caps = array_fill_keys( array_merge( self::event_caps(), array(
			BLT_Events_Helpers::MANAGE_CAP,
			'read',
			'upload_files',
			'manage_categories',
		) ), true );

		$manager = get_role( self::MANAGER_ROLE );
		if ( ! $manager ) {
			add_role( self::MANAGER_ROLE, __( 'Event Manager', 'blt-events' ), $manager_caps );
		} else {
			foreach ( $manager_caps as $cap => $grant ) {
				if ( ! $manager->has_cap( $cap ) ) {
					$manager->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove everything install() added. Used by uninstall.php.
	 */
	public static function remove() {
		$caps = array_merge( self::event_caps(), array( BLT_Events_Helpers::MANAGE_CAP ) );

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		remove_role( self::MANAGER_ROLE );
	}
}
