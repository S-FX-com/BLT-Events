<?php
/**
 * BLT Events - FluentCRM Add-on
 *
 * Syncs event registrations with FluentCRM contacts and lists.
 * Only loaded when FluentCRM is active (FLUENTCRM constant defined).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_FluentCRM_Addon {

	public static function init() {
		if ( ! defined( 'FLUENTCRM' ) ) {
			return;
		}

		// Sync on registration
		add_action( 'blt_registration_created', array( __CLASS__, 'sync_contact' ), 20, 2 );
		add_action( 'blt_registration_confirmed', array( __CLASS__, 'tag_confirmed' ), 20, 1 );
		add_action( 'blt_registration_refunded', array( __CLASS__, 'tag_refunded' ), 20, 1 );

		// Admin settings
		add_filter( 'blt_events_settings_sections', array( __CLASS__, 'add_settings_section' ) );
	}

	/**
	 * Create or update a FluentCRM contact when a registration is created.
	 */
	public static function sync_contact( $registration_id, $result ) {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		$reg_db = new BLT_Events_Registrations_DB();
		$reg    = $reg_db->get( $registration_id );

		if ( ! $reg ) {
			return;
		}

		$custom_fields = json_decode( $reg->custom_fields, true );

		/**
		 * Filter the FluentCRM subscription status for new registrants.
		 *
		 * Defaults to 'pending' (double opt-in) so registrants are not
		 * subscribed to marketing lists without confirmation. Return
		 * 'subscribed' to skip double opt-in.
		 *
		 * @param string $status          The contact status.
		 * @param object $reg             The registration record.
		 */
		$status = apply_filters( 'blt_events_fluentcrm_contact_status', 'pending', $reg );

		$contact_data = array(
			'email'      => $reg->customer_email,
			'first_name' => $custom_fields['first_name'] ?? '',
			'last_name'  => $custom_fields['last_name'] ?? '',
			'phone'      => $reg->customer_phone ?? '',
			'status'     => $status,
		);

		// Apply the fieldset's per-field FluentCRM mappings: mapped values
		// land on core contact columns when the slug matches one, otherwise
		// they're collected as custom field values.
		$mapped_custom_fields = array();
		$core_contact_columns = array(
			'prefix', 'first_name', 'last_name', 'email', 'phone', 'timezone',
			'address_line_1', 'address_line_2', 'city', 'state', 'postal_code',
			'country', 'date_of_birth',
		);

		if ( class_exists( 'BLT_Events_Fieldsets' ) && is_array( $custom_fields ) ) {
			$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $reg->event_id );
			foreach ( BLT_Events_Fieldsets::get_fields( $fieldset ) as $field ) {
				$slug  = $field['map_fluentcrm'] ?? '';
				$value = $custom_fields[ $field['key'] ] ?? '';

				if ( $slug === '' || $value === '' || ! is_scalar( $value ) ) {
					continue;
				}

				if ( in_array( $slug, $core_contact_columns, true ) ) {
					$contact_data[ $slug ] = (string) $value;
				} else {
					$mapped_custom_fields[ $slug ] = (string) $value;
				}
			}
		}

		$api = FluentCrmApi( 'contacts' );

		// Create or update contact
		$contact = $api->createOrUpdate( $contact_data );

		if ( ! $contact ) {
			return;
		}

		// Add to event-specific list if configured
		$list_id = get_option( 'blt_events_fluentcrm_list_id', '' );
		if ( $list_id ) {
			$contact->attachLists( array( $list_id ) );
		}

		// Add registration tag
		$tag_id = get_option( 'blt_events_fluentcrm_registration_tag', '' );
		if ( $tag_id ) {
			$contact->attachTags( array( $tag_id ) );
		}

		// Add event-specific tag
		$event = get_post( $reg->event_id );
		if ( $event ) {
			$event_tag = get_post_meta( $reg->event_id, '_blt_fluentcrm_tag_id', true );
			if ( $event_tag ) {
				$contact->attachTags( array( $event_tag ) );
			}
		}

		// Store custom meta: legacy zero-config defaults first, then the
		// fieldset's explicit FluentCRM mappings (which win on conflict).
		if ( method_exists( $contact, 'updateCustomFieldsValues' ) ) {
			$meta = array();

			if ( ! empty( $custom_fields['organization'] ) ) {
				$meta['company'] = $custom_fields['organization'];
			}
			if ( ! empty( $custom_fields['job_title'] ) ) {
				$meta['job_title'] = $custom_fields['job_title'];
			}

			$meta = array_merge( $meta, $mapped_custom_fields );

			if ( ! empty( $meta ) ) {
				$contact->updateCustomFieldsValues( $meta );
			}
		}

		// Store the FluentCRM contact ID on the registration
		$reg_db->update( $registration_id, array(
			'custom_fields' => wp_json_encode( array_merge(
				$custom_fields ?: array(),
				array( '_fluentcrm_contact_id' => $contact->id )
			) ),
		) );
	}

	/**
	 * Tag a contact as confirmed when payment is received.
	 */
	public static function tag_confirmed( $registration_id ) {
		$tag_id = get_option( 'blt_events_fluentcrm_confirmed_tag', '' );
		if ( $tag_id ) {
			self::apply_tag_to_registration( $registration_id, $tag_id );
		}
	}

	/**
	 * Tag a contact as refunded.
	 */
	public static function tag_refunded( $registration_id ) {
		$tag_id = get_option( 'blt_events_fluentcrm_refunded_tag', '' );
		if ( $tag_id ) {
			self::apply_tag_to_registration( $registration_id, $tag_id );
		}

		// Remove confirmed tag
		$confirmed_tag = get_option( 'blt_events_fluentcrm_confirmed_tag', '' );
		if ( $confirmed_tag ) {
			self::remove_tag_from_registration( $registration_id, $confirmed_tag );
		}
	}

	/**
	 * Apply a tag to the FluentCRM contact linked to a registration.
	 */
	private static function apply_tag_to_registration( $registration_id, $tag_id ) {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		$reg_db = new BLT_Events_Registrations_DB();
		$reg    = $reg_db->get( $registration_id );

		if ( ! $reg ) {
			return;
		}

		$api     = FluentCrmApi( 'contacts' );
		$contact = $api->getContact( $reg->customer_email );

		if ( $contact ) {
			$contact->attachTags( array( $tag_id ) );
		}
	}

	/**
	 * Remove a tag from the FluentCRM contact linked to a registration.
	 */
	private static function remove_tag_from_registration( $registration_id, $tag_id ) {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		$reg_db = new BLT_Events_Registrations_DB();
		$reg    = $reg_db->get( $registration_id );

		if ( ! $reg ) {
			return;
		}

		$api     = FluentCrmApi( 'contacts' );
		$contact = $api->getContact( $reg->customer_email );

		if ( $contact ) {
			$contact->detachTags( array( $tag_id ) );
		}
	}

	/**
	 * Add FluentCRM settings section to plugin settings.
	 */
	public static function add_settings_section( $sections ) {
		$sections['fluentcrm'] = array(
			'title'  => 'FluentCRM Integration',
			'fields' => array(
				'blt_events_fluentcrm_list_id'          => 'Default List ID',
				'blt_events_fluentcrm_registration_tag' => 'Registration Tag ID',
				'blt_events_fluentcrm_confirmed_tag'    => 'Confirmed Tag ID',
				'blt_events_fluentcrm_refunded_tag'     => 'Refunded Tag ID',
			),
		);
		return $sections;
	}
}
