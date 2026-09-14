<?php
/**
 * BLT Events - Payment Provider Registry
 *
 * Resolves which payment providers are switched on for a site, and which one
 * a given event checks out through.
 *
 * The plugin used to resolve a single provider for the whole site. That made
 * two things impossible: running SureCart and FluentCart side by side, and
 * keeping a provider's webhooks alive after the site default moved elsewhere
 * (a refund on last month's SureCart orders would land on no listener at all).
 * So enablement and selection are now separate concerns:
 *
 *   - Enabled providers have their hooks registered and can be picked. More
 *     than one may be enabled at a time.
 *   - The site default is the provider an event uses when it says nothing.
 *   - An individual event may override the default with any enabled provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Payment_Providers {

	const OPTION_DEFAULT = 'blt_events_payment_provider';
	const OPTION_ENABLED = 'blt_events_enabled_providers';
	const EVENT_META     = '_blt_payment_provider';

	/**
	 * Every provider the plugin knows how to drive.
	 *
	 * @return array Slug => array( label, description, class ).
	 */
	public static function get_registry() {
		/**
		 * Filter the registered payment providers.
		 *
		 * Add-ons can register their own by appending a slug with a `class`
		 * that extends BLT_Events_Payment_Provider.
		 *
		 * @param array $providers Slug => array( label, description, class ).
		 */
		return apply_filters( 'blt_events_payment_providers', array(
			'stripe'     => array(
				'label'       => __( 'Stripe', 'blt-events' ),
				'description' => __( 'Card payments through Stripe, collected on your own registration form.', 'blt-events' ),
				'class'       => 'BLT_Events_Stripe_Handler',
			),
			'surecart'   => array(
				'label'       => __( 'SureCart', 'blt-events' ),
				'description' => __( 'Checkout through your SureCart store.', 'blt-events' ),
				'class'       => 'BLT_Events_SureCart_Integration',
			),
			'fluentcart' => array(
				'label'       => __( 'FluentCart', 'blt-events' ),
				'description' => __( 'On-site checkout with FluentCart.', 'blt-events' ),
				'class'       => 'BLT_Events_FluentCart_Integration',
			),
		) );
	}

	/**
	 * All known provider slugs.
	 *
	 * @return string[]
	 */
	public static function get_slugs() {
		return array_keys( self::get_registry() );
	}

	/**
	 * Whether a slug names a provider the plugin knows about.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return in_array( (string) $slug, self::get_slugs(), true );
	}

	/**
	 * The provider class for a slug, or '' when unknown.
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function get_class( $slug ) {
		$registry = self::get_registry();
		return isset( $registry[ $slug ]['class'] ) ? $registry[ $slug ]['class'] : '';
	}

	/**
	 * The human-readable label for a slug.
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function get_label( $slug ) {
		$registry = self::get_registry();

		if ( isset( $registry[ $slug ]['label'] ) ) {
			return $registry[ $slug ]['label'];
		}

		return 'none' === $slug ? __( 'No payment processor', 'blt-events' ) : (string) $slug;
	}

	/**
	 * The site-wide default provider: what an event uses when it has no
	 * override of its own. 'none' means registrations are free/manual.
	 *
	 * @return string
	 */
	public static function get_default() {
		$default = (string) get_option( self::OPTION_DEFAULT, 'none' );

		return self::exists( $default ) ? $default : 'none';
	}

	/**
	 * The providers switched on for this site.
	 *
	 * @return string[]
	 */
	public static function get_enabled() {
		$stored = get_option( self::OPTION_ENABLED, null );

		// Sites upgrading from the single-provider setting have no stored list.
		// Their one configured provider is the enabled set, so an upgrade
		// changes nothing until someone opts in to a second one.
		if ( null === $stored ) {
			$default = self::get_default();
			return 'none' === $default ? array() : array( $default );
		}

		$enabled = array_values( array_intersect( array_map( 'strval', (array) $stored ), self::get_slugs() ) );

		// The default is always usable, even if it was never ticked: otherwise
		// a mis-saved settings form could take a live site's checkout offline.
		$default = self::get_default();
		if ( 'none' !== $default && ! in_array( $default, $enabled, true ) ) {
			$enabled[] = $default;
		}

		return $enabled;
	}

	/**
	 * Whether a provider is switched on for this site.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_enabled( $slug ) {
		return in_array( (string) $slug, self::get_enabled(), true );
	}

	/**
	 * Enabled providers that are also fully configured, i.e. the ones that can
	 * actually take a payment right now.
	 *
	 * @return string[]
	 */
	public static function get_available() {
		$available = array();

		foreach ( self::get_enabled() as $slug ) {
			$class = self::get_class( $slug );

			if ( $class && class_exists( $class ) && call_user_func( array( $class, 'is_configured' ) ) ) {
				$available[] = $slug;
			}
		}

		return $available;
	}

	/**
	 * Whether a provider is enabled and configured.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_available( $slug ) {
		return in_array( (string) $slug, self::get_available(), true );
	}

	/**
	 * The provider an event checks out through.
	 *
	 * An event's own choice wins, but only while that provider is still
	 * enabled — otherwise switching a provider off would leave events pointing
	 * at a checkout with no hooks behind it.
	 *
	 * @param int $event_id The event post ID.
	 * @return string Provider slug, or 'none'.
	 */
	public static function get_event_provider( $event_id ) {
		$slug = (string) get_post_meta( absint( $event_id ), self::EVENT_META, true );

		if ( '' !== $slug && self::is_enabled( $slug ) ) {
			return $slug;
		}

		/**
		 * Filter the payment provider used for one event.
		 *
		 * @param string $slug     The resolved provider slug.
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_event_payment_provider', self::get_default(), absint( $event_id ) );
	}

	/**
	 * Boot every enabled provider.
	 *
	 * Enabled — not "selected". A provider's order and refund listeners have to
	 * stay registered for as long as it holds live orders, regardless of which
	 * provider new events default to.
	 */
	public static function init() {
		foreach ( self::get_enabled() as $slug ) {
			$class = self::get_class( $slug );

			if ( $class && class_exists( $class ) ) {
				call_user_func( array( $class, 'init' ) );
			}
		}
	}
}
