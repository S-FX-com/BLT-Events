<?php
/**
 * BLT Events - Abstract Payment Provider
 *
 * Defines the interface that all payment providers must implement.
 *
 * See BLT_Events_Payment_Providers for how providers are enabled and how one
 * is resolved for a given event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BLT_Events_Payment_Provider {

	/**
	 * Register the provider's hooks. Called once per request for every
	 * enabled provider, so an implementation must be safe to boot even when
	 * no event currently checks out through it.
	 */
	abstract public static function init();

	/**
	 * Check if this provider is configured and ready to use.
	 */
	abstract public static function is_configured();

	/**
	 * Whether this provider is the one a checkout would run through.
	 *
	 * With an event ID, answers for that event (honouring its override).
	 * Without one, answers for the site default.
	 *
	 * This gates rendering a checkout — never the registration of order or
	 * refund listeners, which must stay live for every enabled provider.
	 *
	 * @param string $slug     Provider slug.
	 * @param int    $event_id Optional event post ID.
	 * @return bool
	 */
	public static function is_active_provider( $slug, $event_id = 0 ) {
		if ( $event_id ) {
			return BLT_Events_Payment_Providers::get_event_provider( $event_id ) === $slug;
		}

		return BLT_Events_Payment_Providers::get_default() === $slug;
	}

	/**
	 * Whether this provider is switched on for the site.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_enabled_provider( $slug ) {
		return BLT_Events_Payment_Providers::is_enabled( $slug );
	}

	/**
	 * Whether any event on this site checks out through the given provider —
	 * the site default, or an explicit per-event override.
	 *
	 * Used to decide whether front-end checkout assets are worth registering.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function has_events_using( $slug ) {
		if ( BLT_Events_Payment_Providers::get_default() === $slug ) {
			return true;
		}

		$cache_key = 'blt_events_provider_in_use_' . $slug;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		// Unpublished statuses count too: an admin previewing a draft event
		// still needs that event's checkout script registered, and the script
		// is only registered when this returns true.
		$in_use = (bool) get_posts( array(
			'post_type'      => 'event',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => BLT_Events_Payment_Providers::EVENT_META,
					'value' => $slug,
				),
			),
		) );

		set_transient( $cache_key, $in_use ? '1' : '0', HOUR_IN_SECONDS );

		return $in_use;
	}

	/**
	 * Drop the cached has_events_using() answers. Called when an event is
	 * saved, since that is the only thing that can change them.
	 */
	public static function flush_usage_cache() {
		foreach ( BLT_Events_Payment_Providers::get_slugs() as $slug ) {
			delete_transient( 'blt_events_provider_in_use_' . $slug );
		}
	}
}
