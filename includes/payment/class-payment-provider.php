<?php
/**
 * CMT Events - Abstract Payment Provider
 *
 * Defines the interface that all payment providers must implement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CMT_Events_Payment_Provider {

	abstract public static function init();

	/**
	 * Check if this provider is configured and ready to use.
	 */
	abstract public static function is_configured();

	/**
	 * Check if this provider is the currently active provider.
	 */
	public static function is_active_provider( $slug ) {
		return CMT_Events_Helpers::get_payment_provider() === $slug;
	}
}
