<?php
/**
 * Fieldsets database class.
 *
 * Handles CRUD operations for the blt_fieldsets table.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Fieldsets_DB extends BLT_Events_DB {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'blt_fieldsets' );
	}

	/**
	 * Get the default fieldset.
	 *
	 * Returns the fieldset row where is_default = 1.
	 *
	 * @return object|null Fieldset object or null if none found.
	 */
	public function get_default() {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE is_default = %d LIMIT 1",
				1
			)
		);
	}

	/**
	 * Get a fieldset by its slug.
	 *
	 * @param string $slug The fieldset slug.
	 * @return object|null Fieldset object or null if not found.
	 */
	public function get_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		return $this->get_by( 'slug', $slug );
	}

	/**
	 * Get all active fieldsets.
	 *
	 * @return array Array of fieldset objects with status = 'active'.
	 */
	public function get_active() {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY name ASC",
				'active'
			)
		);
	}
}
