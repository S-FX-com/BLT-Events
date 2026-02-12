<?php
/**
 * CMT Events - Fieldsets Business Logic
 *
 * Manages fieldset operations: rendering form fields, validating submitted data,
 * and providing the fieldset for a specific event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMT_Events_Fieldsets {

	private static $db;

	public static function init() {
		self::$db = new CMT_Events_Fieldsets_DB();
	}

	/**
	 * Get the fieldset assigned to an event, or the default fieldset.
	 */
	public static function get_event_fieldset( $event_id ) {
		$fieldset_id = get_post_meta( $event_id, '_cmt_fieldset_id', true );

		if ( $fieldset_id ) {
			$fieldset = self::$db->get( absint( $fieldset_id ) );
			if ( $fieldset ) {
				return $fieldset;
			}
		}

		return self::$db->get_default();
	}

	/**
	 * Decode the fields JSON from a fieldset row.
	 * Returns an array of field definition arrays.
	 */
	public static function get_fields( $fieldset ) {
		if ( ! $fieldset || empty( $fieldset->fields ) ) {
			return array();
		}
		$fields = json_decode( $fieldset->fields, true );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Decode the consent_fields JSON from a fieldset row.
	 */
	public static function get_consent_fields( $fieldset ) {
		if ( ! $fieldset || empty( $fieldset->consent_fields ) ) {
			return array();
		}
		$fields = json_decode( $fieldset->consent_fields, true );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Render a single form field based on its definition array.
	 * Returns HTML string.
	 */
	public static function render_field( $field, $value = '', $prefix = '' ) {
		$key      = sanitize_key( $field['key'] );
		$name     = $prefix ? $prefix . '[' . $key . ']' : $key;
		$id       = $prefix ? $prefix . '_' . $key : $key;
		$type     = $field['type'] ?? 'text';
		$label    = $field['label'] ?? '';
		$required = ! empty( $field['required'] );
		$placeholder = $field['placeholder'] ?? '';
		$width    = $field['width'] ?? 'full';
		$options  = $field['options'] ?? array();
		$allow_other = ! empty( $field['allow_other'] );

		$width_class = 'cmt-field-' . $width; // full, half, third
		$req_attr    = $required ? ' required' : '';
		$req_star    = $required ? ' <span class="cmt-required">*</span>' : '';

		$html = '<div class="cmt-field-wrap ' . esc_attr( $width_class ) . '">';
		$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_star . '</label>';

		switch ( $type ) {
			case 'select':
				$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . '>';
				$html .= '<option value="">— Select —</option>';
				foreach ( $options as $opt ) {
					$selected = ( $value === $opt ) ? ' selected' : '';
					$html .= '<option value="' . esc_attr( $opt ) . '"' . $selected . '>' . esc_html( $opt ) . '</option>';
				}
				if ( $allow_other ) {
					$is_other = ( $value && ! in_array( $value, $options, true ) );
					$html .= '<option value="__other__"' . ( $is_other ? ' selected' : '' ) . '>Other...</option>';
				}
				$html .= '</select>';
				if ( $allow_other ) {
					$other_val = ( $value && ! in_array( $value, $options, true ) ) ? $value : '';
					$html .= '<input type="text" class="cmt-other-input" name="' . esc_attr( $name ) . '__other" value="' . esc_attr( $other_val ) . '" placeholder="Please specify" style="' . ( $other_val ? '' : 'display:none;' ) . '" />';
				}
				break;

			case 'textarea':
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . $req_attr . '>' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'checkbox':
				$checked = $value ? ' checked' : '';
				$html .= '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . $checked . $req_attr . ' />';
				break;

			default: // text, email, tel, url, number, date
				$html .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . $req_attr . ' />';
				break;
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Validate submitted form data against a fieldset definition.
	 *
	 * @return array|WP_Error Sanitized data array on success, WP_Error on failure.
	 */
	public static function validate_submission( $fieldset, $posted_data ) {
		$fields = self::get_fields( $fieldset );
		$consent_fields = self::get_consent_fields( $fieldset );
		$errors = array();
		$clean  = array();

		foreach ( $fields as $field ) {
			$key   = $field['key'];
			$value = isset( $posted_data[ $key ] ) ? trim( $posted_data[ $key ] ) : '';

			// Handle "other" select values
			if ( $field['type'] === 'select' && ! empty( $field['allow_other'] ) && $value === '__other__' ) {
				$other_key = $key . '__other';
				$value = isset( $posted_data[ $other_key ] ) ? trim( $posted_data[ $other_key ] ) : '';
			}

			// Required check
			if ( ! empty( $field['required'] ) && $value === '' ) {
				$errors[] = sprintf( '%s is required.', $field['label'] );
				continue;
			}

			// Type-specific validation
			switch ( $field['type'] ) {
				case 'email':
					if ( $value && ! is_email( $value ) ) {
						$errors[] = sprintf( '%s must be a valid email address.', $field['label'] );
					} else {
						$value = sanitize_email( $value );
					}
					break;
				case 'url':
					if ( $value ) {
						$value = esc_url_raw( $value );
					}
					break;
				case 'tel':
					$value = CMT_Events_Helpers::sanitize_phone( $value );
					break;
				case 'number':
					$value = is_numeric( $value ) ? floatval( $value ) : '';
					break;
				default:
					$value = sanitize_text_field( $value );
					break;
			}

			$clean[ $key ] = $value;
		}

		// Validate consent fields
		$clean['_consents'] = array();
		foreach ( $consent_fields as $cf ) {
			$ck = 'consent_' . $cf['key'];
			$cv = ! empty( $posted_data[ $ck ] );

			if ( ! empty( $cf['required'] ) && ! $cv ) {
				$errors[] = sprintf( 'You must accept: %s', wp_strip_all_tags( $cf['label'] ) );
			}

			$clean['_consents'][ $cf['key'] ] = $cv;
		}

		if ( ! empty( $errors ) ) {
			$wp_error = new WP_Error();
			foreach ( $errors as $msg ) {
				$wp_error->add( 'validation_error', $msg );
			}
			return $wp_error;
		}

		return $clean;
	}

	/**
	 * Get all active fieldsets (for admin dropdowns).
	 */
	public static function get_active_fieldsets() {
		return self::$db->get_active();
	}

	/**
	 * Get a fieldset by ID.
	 */
	public static function get_fieldset( $id ) {
		return self::$db->get( absint( $id ) );
	}

	/**
	 * Save a fieldset (insert or update).
	 */
	public static function save_fieldset( $data ) {
		if ( ! empty( $data['id'] ) ) {
			return self::$db->update( $data['id'], $data );
		}
		return self::$db->insert( $data );
	}

	/**
	 * Delete a fieldset.
	 */
	public static function delete_fieldset( $id ) {
		return self::$db->delete( absint( $id ) );
	}
}
