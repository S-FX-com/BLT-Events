<?php
/**
 * BLT Events - Fieldsets Business Logic
 *
 * Manages fieldset operations: the field type registry, rendering form
 * fields, validating submitted data (types, rules, conditional logic), and
 * providing the fieldset for a specific event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Fieldsets {

	private static $db;

	public static function init() {
		self::$db = new BLT_Events_Fieldsets_DB();
	}

	/* ------------------------------------------------------------------
	 * Field type registry
	 * ---------------------------------------------------------------- */

	/**
	 * Every field type the builder offers and the form knows how to render.
	 *
	 * Each entry:
	 *   label        (string)   Name shown in the builder.
	 *   has_options  (bool)     Uses the comma-separated options list.
	 *   stores_value (bool)     Submits and stores a value (false for static HTML).
	 *   multiple     (bool)     Value is an array (checkbox groups).
	 *   render       (callable) Optional: ($field, $value, $name, $id, $attrs) => control HTML.
	 *   sanitize     (callable) Optional: ($value, $field) => sanitized value or WP_Error.
	 *
	 * Add-ons register new types with the blt_events_field_types filter.
	 *
	 * @return array<string,array>
	 */
	public static function field_types() {
		$types = array(
			'text'           => array( 'label' => __( 'Text', 'blt-events' ) ),
			'email'          => array( 'label' => __( 'Email', 'blt-events' ) ),
			'tel'            => array( 'label' => __( 'Phone', 'blt-events' ) ),
			'url'            => array( 'label' => __( 'URL', 'blt-events' ) ),
			'number'         => array( 'label' => __( 'Number', 'blt-events' ) ),
			'date'           => array( 'label' => __( 'Date', 'blt-events' ) ),
			'textarea'       => array( 'label' => __( 'Paragraph text', 'blt-events' ) ),
			'select'         => array( 'label' => __( 'Dropdown', 'blt-events' ), 'has_options' => true ),
			'radio'          => array( 'label' => __( 'Radio buttons', 'blt-events' ), 'has_options' => true ),
			'checkbox'       => array( 'label' => __( 'Single checkbox', 'blt-events' ) ),
			'checkbox_group' => array( 'label' => __( 'Checkbox group', 'blt-events' ), 'has_options' => true, 'multiple' => true ),
			'country'        => array( 'label' => __( 'Country', 'blt-events' ) ),
			'hidden'         => array( 'label' => __( 'Hidden value', 'blt-events' ) ),
			'html'           => array( 'label' => __( 'Text block (no input)', 'blt-events' ), 'stores_value' => false ),
		);

		/**
		 * Filter the registered field types.
		 *
		 * @param array $types Slug => definition (see field_types()).
		 */
		$types = apply_filters( 'blt_events_field_types', $types );

		foreach ( $types as $slug => $def ) {
			$types[ $slug ] = wp_parse_args( (array) $def, array(
				'label'        => $slug,
				'has_options'  => false,
				'stores_value' => true,
				'multiple'     => false,
				'render'       => null,
				'sanitize'     => null,
			) );
		}

		return $types;
	}

	/**
	 * Definition of one field type, or the text type when unknown.
	 *
	 * @param string $type Type slug.
	 * @return array
	 */
	public static function field_type( $type ) {
		$types = self::field_types();
		return $types[ $type ] ?? $types['text'];
	}

	/**
	 * Conditional-logic operators, slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function condition_operators() {
		return array(
			'is'        => __( 'is', 'blt-events' ),
			'is_not'    => __( 'is not', 'blt-events' ),
			'contains'  => __( 'contains', 'blt-events' ),
			'not_empty' => __( 'is filled in', 'blt-events' ),
			'empty'     => __( 'is empty', 'blt-events' ),
		);
	}

	/**
	 * Fill in every key a field definition can have.
	 *
	 * @param array $field Raw field definition.
	 * @return array
	 */
	public static function normalize_field( $field ) {
		$field = wp_parse_args( (array) $field, array(
			'key'           => '',
			'type'          => 'text',
			'label'         => '',
			'required'      => false,
			'width'         => 'full',
			'order'         => 0,
			'options'       => array(),
			'allow_other'   => false,
			'placeholder'   => '',
			'description'   => '',
			'default'       => '',
			'content'       => '',
			'map_user'      => '',
			'map_acf'       => '',
			'map_fluentcrm' => '',
			'validation'    => array(),
			'conditional'   => array(),
		) );

		$field['options']     = is_array( $field['options'] ) ? array_values( $field['options'] ) : array();
		$field['validation']  = is_array( $field['validation'] ) ? $field['validation'] : array();
		$field['conditional'] = is_array( $field['conditional'] ) ? $field['conditional'] : array();

		return $field;
	}

	/**
	 * Sanitize a field definition coming from the builder or the REST API.
	 *
	 * @param array $raw   Raw definition (form or JSON).
	 * @param int   $order Position in the fieldset.
	 * @return array|null Clean definition, or null when unusable.
	 */
	public static function sanitize_field_definition( $raw, $order = 0 ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$types = self::field_types();
		$type  = sanitize_key( $raw['type'] ?? 'text' );
		if ( ! isset( $types[ $type ] ) ) {
			$type = 'text';
		}

		$label = sanitize_text_field( $raw['label'] ?? '' );
		$key   = sanitize_key( $raw['key'] ?? '' );
		if ( '' === $key ) {
			$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ?: 'field_' . $order ) ) );
		}
		if ( '' === $key ) {
			$key = 'field_' . $order;
		}

		// Options: array from JSON, or the builder's comma-separated string.
		$options = array();
		if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
			$options = $raw['options'];
		} elseif ( ! empty( $raw['options_str'] ) ) {
			$options = explode( ',', (string) $raw['options_str'] );
		}
		$options = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', array_map( 'strval', $options ) ) ), 'strlen' ) );

		$width = sanitize_key( $raw['width'] ?? 'full' );
		if ( ! in_array( $width, array( 'full', 'half', 'third' ), true ) ) {
			$width = 'full';
		}

		// Validation rules.
		$rules_in = isset( $raw['validation'] ) && is_array( $raw['validation'] ) ? $raw['validation'] : array();
		$rules    = array();
		foreach ( array( 'min_length', 'max_length' ) as $rule ) {
			if ( isset( $rules_in[ $rule ] ) && '' !== trim( (string) $rules_in[ $rule ] ) ) {
				$rules[ $rule ] = absint( $rules_in[ $rule ] );
			}
		}
		foreach ( array( 'min', 'max' ) as $rule ) {
			if ( isset( $rules_in[ $rule ] ) && '' !== trim( (string) $rules_in[ $rule ] ) && is_numeric( $rules_in[ $rule ] ) ) {
				$rules[ $rule ] = (float) $rules_in[ $rule ];
			}
		}
		if ( ! empty( $rules_in['pattern'] ) ) {
			$pattern = trim( (string) $rules_in['pattern'] );
			// Only keep a pattern PHP can actually compile.
			if ( '' !== $pattern && false !== @preg_match( '/' . str_replace( '/', '\/', $pattern ) . '/u', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$rules['pattern'] = $pattern;
			}
		}
		if ( ! empty( $rules_in['message'] ) ) {
			$rules['message'] = sanitize_text_field( $rules_in['message'] );
		}

		// Conditional logic.
		$cond_in     = isset( $raw['conditional'] ) && is_array( $raw['conditional'] ) ? $raw['conditional'] : array();
		$conditional = array();
		$cond_field  = sanitize_key( $cond_in['field'] ?? '' );
		$cond_op     = sanitize_key( $cond_in['operator'] ?? 'is' );
		if ( '' !== $cond_field && $cond_field !== $key && array_key_exists( $cond_op, self::condition_operators() ) ) {
			$conditional = array(
				'field'    => $cond_field,
				'operator' => $cond_op,
				'value'    => sanitize_text_field( $cond_in['value'] ?? '' ),
			);
		}

		$mapping = function ( $value ) {
			return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ), 0, 64 );
		};

		return array(
			'key'           => $key,
			'type'          => $type,
			'label'         => $label,
			'required'      => ! empty( $raw['required'] ),
			'width'         => $width,
			'order'         => (int) $order,
			'options'       => $options,
			'allow_other'   => ! empty( $raw['allow_other'] ) && $types[ $type ]['has_options'],
			'placeholder'   => sanitize_text_field( $raw['placeholder'] ?? '' ),
			'description'   => sanitize_text_field( $raw['description'] ?? '' ),
			'default'       => sanitize_text_field( $raw['default'] ?? '' ),
			'content'       => wp_kses_post( $raw['content'] ?? '' ),
			'map_user'      => $mapping( $raw['map_user'] ?? '' ),
			'map_acf'       => $mapping( $raw['map_acf'] ?? '' ),
			'map_fluentcrm' => $mapping( $raw['map_fluentcrm'] ?? '' ),
			'validation'    => $rules,
			'conditional'   => $conditional,
		);
	}

	/**
	 * Sanitize a consent field definition.
	 *
	 * @param array $raw Raw definition.
	 * @return array|null
	 */
	public static function sanitize_consent_definition( $raw, $index = 0 ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$key = sanitize_key( $raw['key'] ?? '' );
		if ( '' === $key ) {
			$key = 'consent_' . $index;
		}

		return array(
			'key'      => $key,
			'label'    => wp_kses_post( $raw['label'] ?? '' ),
			'required' => ! empty( $raw['required'] ),
		);
	}

	/* ------------------------------------------------------------------
	 * Presets
	 * ---------------------------------------------------------------- */

	/**
	 * Starting points offered when creating a new fieldset.
	 *
	 * @return array<string,array{label:string,description:string,fields:array,consent_fields:array}>
	 */
	public static function presets() {
		$basic = array(
			array( 'key' => 'first_name', 'type' => 'text', 'label' => __( 'First Name', 'blt-events' ), 'required' => true, 'width' => 'half' ),
			array( 'key' => 'last_name', 'type' => 'text', 'label' => __( 'Last Name', 'blt-events' ), 'required' => true, 'width' => 'half' ),
			array( 'key' => 'email', 'type' => 'email', 'label' => __( 'Email', 'blt-events' ), 'required' => true, 'width' => 'full' ),
			array( 'key' => 'mobile_number', 'type' => 'tel', 'label' => __( 'Phone', 'blt-events' ), 'required' => false, 'width' => 'full', 'placeholder' => __( 'Include country code', 'blt-events' ) ),
		);

		$professional = array_merge( $basic, array(
			array( 'key' => 'organization', 'type' => 'text', 'label' => __( 'Organization / Company', 'blt-events' ), 'required' => false, 'width' => 'half' ),
			array( 'key' => 'job_title', 'type' => 'text', 'label' => __( 'Job Title / Role', 'blt-events' ), 'required' => false, 'width' => 'half' ),
			array( 'key' => 'country', 'type' => 'country', 'label' => __( 'Country', 'blt-events' ), 'required' => false, 'width' => 'full' ),
			array( 'key' => 'linkedin_website', 'type' => 'url', 'label' => __( 'LinkedIn / Website', 'blt-events' ), 'required' => false, 'width' => 'full', 'placeholder' => 'https://' ),
		) );

		$consent = self::default_consent_fields();

		$presets = array(
			'basic'        => array(
				'label'          => __( 'Basic', 'blt-events' ),
				'description'    => __( 'Name, email and phone.', 'blt-events' ),
				'fields'         => $basic,
				'consent_fields' => $consent,
			),
			'professional' => array(
				'label'          => __( 'Professional', 'blt-events' ),
				'description'    => __( 'Basic fields plus organization, role, country and website.', 'blt-events' ),
				'fields'         => $professional,
				'consent_fields' => $consent,
			),
			'blank'        => array(
				'label'          => __( 'Blank', 'blt-events' ),
				'description'    => __( 'Start from nothing.', 'blt-events' ),
				'fields'         => array(),
				'consent_fields' => array(),
			),
		);

		/**
		 * Filter the fieldset presets offered in the builder.
		 *
		 * @param array $presets Slug => preset.
		 */
		$presets = apply_filters( 'blt_events_fieldset_presets', $presets );

		foreach ( $presets as $slug => $preset ) {
			$presets[ $slug ]['fields'] = array_map( array( __CLASS__, 'normalize_field' ), (array) ( $preset['fields'] ?? array() ) );
		}

		return $presets;
	}

	/**
	 * The consent checkboxes a fresh install starts with. Links point at
	 * the site's own privacy policy page when one is set.
	 *
	 * @return array
	 */
	public static function default_consent_fields() {
		$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';

		$terms_label = $privacy_url
			? sprintf(
				/* translators: %s: privacy policy URL. */
				__( 'I have read and accept the <a href="%s" target="_blank" rel="noopener">Privacy Policy</a>.', 'blt-events' ),
				esc_url( $privacy_url )
			)
			: __( 'I have read and accept the Privacy Policy.', 'blt-events' );

		$fields = array(
			array(
				'key'      => 'terms_privacy',
				'label'    => $terms_label,
				'required' => true,
			),
			array(
				'key'      => 'marketing_optin',
				'label'    => __( 'I would like to receive updates about future events.', 'blt-events' ),
				'required' => false,
			),
		);

		/**
		 * Filter the default consent fields seeded on a fresh install.
		 *
		 * @param array $fields Consent field definitions.
		 */
		return apply_filters( 'blt_events_default_consent_fields', $fields );
	}

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	/**
	 * Get the fieldset assigned to an event, or the default fieldset.
	 */
	public static function get_event_fieldset( $event_id ) {
		$fieldset_id = get_post_meta( $event_id, '_blt_fieldset_id', true );

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
	 * Returns an array of normalized field definition arrays.
	 */
	public static function get_fields( $fieldset ) {
		if ( ! $fieldset || empty( $fieldset->fields ) ) {
			return array();
		}
		$fields = json_decode( $fieldset->fields, true );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$fields = array_map( array( __CLASS__, 'normalize_field' ), $fields );

		/**
		 * Filter the fields of a fieldset as read from storage.
		 *
		 * @param array  $fields   Field definitions.
		 * @param object $fieldset The fieldset row.
		 */
		return apply_filters( 'blt_events_fieldset_fields', $fields, $fieldset );
	}

	/**
	 * Decode the consent_fields JSON from a fieldset row.
	 */
	public static function get_consent_fields( $fieldset ) {
		if ( ! $fieldset || empty( $fieldset->consent_fields ) ) {
			return array();
		}
		$fields = json_decode( $fieldset->consent_fields, true );
		$fields = is_array( $fields ) ? $fields : array();

		/**
		 * Filter the consent fields of a fieldset as read from storage.
		 *
		 * @param array  $fields   Consent definitions.
		 * @param object $fieldset The fieldset row.
		 */
		return apply_filters( 'blt_events_fieldset_consent_fields', $fields, $fieldset );
	}

	/**
	 * Resolve the prefill value for a field for the current logged-in user.
	 *
	 * Order of precedence:
	 *   1. The field's explicit user-profile mapping (map_user: core user
	 *      property or user meta key).
	 *   2. The field's ACF mapping (map_acf: ACF field name on the user).
	 *   3. Built-in fallbacks by field key (first_name, last_name, email,
	 *      phone) so the common fields work with zero configuration.
	 *   4. The field's own default value.
	 *
	 * @param array $field Field definition array.
	 * @return string Prefill value ('' when nothing matches).
	 */
	public static function prefill_value( $field ) {
		$field   = self::normalize_field( $field );
		$default = (string) $field['default'];

		if ( ! is_user_logged_in() ) {
			return (string) apply_filters( 'blt_events_prefill_value', $default, $field, null );
		}

		$user  = wp_get_current_user();
		$value = '';

		// 1. Explicit user profile / meta mapping.
		if ( ! empty( $field['map_user'] ) ) {
			$value = self::get_user_field( $user, $field['map_user'] );
		}

		// 2. ACF user field mapping.
		if ( $value === '' && ! empty( $field['map_acf'] ) && function_exists( 'get_field' ) ) {
			$acf_value = get_field( $field['map_acf'], 'user_' . $user->ID );
			if ( is_scalar( $acf_value ) ) {
				$value = (string) $acf_value;
			}
		}

		// 3. Zero-config fallbacks for the standard fields.
		if ( $value === '' ) {
			switch ( $field['key'] ) {
				case 'first_name':
					$value = $user->first_name;
					break;
				case 'last_name':
					$value = $user->last_name;
					break;
				case 'email':
					$value = $user->user_email;
					break;
				case 'phone':
				case 'mobile_number':
					$value = get_user_meta( $user->ID, 'phone', true )
						?: get_user_meta( $user->ID, 'billing_phone', true );
					break;
				case 'organization':
				case 'company':
					$value = get_user_meta( $user->ID, 'billing_company', true );
					break;
			}
		}

		if ( $value === '' ) {
			$value = $default;
		}

		/**
		 * Filter the prefilled value for a registration field.
		 *
		 * @param string       $value The resolved value.
		 * @param array        $field The field definition.
		 * @param WP_User|null $user  The current user, or null when logged out.
		 */
		return (string) apply_filters( 'blt_events_prefill_value', $value, $field, $user );
	}

	/**
	 * Read a core user property or user meta value by key.
	 *
	 * @param WP_User $user The user.
	 * @param string  $key  Core property (user_email, first_name, ...) or meta key.
	 * @return string
	 */
	private static function get_user_field( $user, $key ) {
		$core = array( 'user_email', 'user_url', 'user_login', 'display_name', 'first_name', 'last_name', 'nickname', 'description' );

		if ( in_array( $key, $core, true ) ) {
			return (string) $user->$key;
		}

		$meta = get_user_meta( $user->ID, $key, true );
		return is_scalar( $meta ) ? (string) $meta : '';
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/**
	 * Render a single form field based on its definition array.
	 * Returns HTML string.
	 *
	 * @param array        $field  Field definition.
	 * @param string|array $value  Current value.
	 * @param string       $prefix Optional name prefix for nested groups (e.g. attendees[0]).
	 * @return string
	 */
	public static function render_field( $field, $value = '', $prefix = '' ) {
		$field = self::normalize_field( $field );
		$def   = self::field_type( $field['type'] );

		$key         = sanitize_key( $field['key'] );
		$name        = $prefix ? $prefix . '[' . $key . ']' : $key;
		$id          = sanitize_html_class( ( $prefix ? preg_replace( '/[^a-zA-Z0-9_-]/', '_', $prefix ) . '_' : '' ) . $key );
		$type        = $field['type'];
		$label       = $field['label'];
		$required    = ! empty( $field['required'] );
		$placeholder = $field['placeholder'];
		$options     = $field['options'];
		$allow_other = ! empty( $field['allow_other'] );

		$classes = array( 'blt-field-wrap', 'blt-field-' . $field['width'], 'blt-field-type-' . sanitize_html_class( $type ) );
		$wrap_attrs = '';

		if ( ! empty( $field['conditional']['field'] ) ) {
			$classes[]   = 'blt-field-conditional';
			$wrap_attrs .= ' data-blt-condition="' . esc_attr( wp_json_encode( array(
				'field'    => $field['conditional']['field'],
				'operator' => $field['conditional']['operator'] ?? 'is',
				'value'    => (string) ( $field['conditional']['value'] ?? '' ),
				'prefix'   => $prefix,
			) ) ) . '"';
		}

		$req_attr = $required ? ' required' : '';
		$req_star = $required ? ' <span class="blt-required" aria-hidden="true">*</span>' : '';
		$attrs    = self::validation_attributes( $field );
		if ( '' !== $placeholder && ! in_array( $type, array( 'select', 'radio', 'checkbox', 'checkbox_group', 'country', 'hidden', 'html' ), true ) ) {
			$attrs .= ' placeholder="' . esc_attr( $placeholder ) . '"';
		}

		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-blt-field="' . esc_attr( $key ) . '"' . $wrap_attrs . '>';

		if ( is_callable( $def['render'] ) ) {
			$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_star . '</label>';
			$html .= (string) call_user_func( $def['render'], $field, $value, $name, $id, $attrs . $req_attr );
		} else {
			switch ( $type ) {
				case 'html':
					$content = '' !== trim( (string) $field['content'] ) ? $field['content'] : $label;
					$html   .= '<div class="blt-field-html">' . wp_kses_post( wpautop( $content ) ) . '</div>';
					break;

				case 'hidden':
					$hidden_value = '' !== (string) $value ? $value : $field['default'];
					$html .= '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $hidden_value ) . '" />';
					break;

				case 'select':
				case 'country':
					$choices = 'country' === $type ? self::countries() : array_combine( $options, $options );
					$html   .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_star . '</label>';
					$html   .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . $attrs . '>';
					$html   .= '<option value="">' . esc_html( $placeholder ?: __( '— Select —', 'blt-events' ) ) . '</option>';
					foreach ( $choices as $opt_value => $opt_label ) {
						$selected = ( (string) $value === (string) $opt_value ) ? ' selected' : '';
						$html    .= '<option value="' . esc_attr( $opt_value ) . '"' . $selected . '>' . esc_html( $opt_label ) . '</option>';
					}
					if ( $allow_other && 'select' === $type ) {
						$is_other = ( $value && ! in_array( $value, $options, true ) );
						$html    .= '<option value="__other__"' . ( $is_other ? ' selected' : '' ) . '>' . esc_html__( 'Other...', 'blt-events' ) . '</option>';
					}
					$html .= '</select>';
					if ( $allow_other && 'select' === $type ) {
						$other_val = ( $value && ! in_array( $value, $options, true ) ) ? $value : '';
						$html     .= '<input type="text" class="blt-other-input" name="' . esc_attr( $name ) . '__other" value="' . esc_attr( $other_val ) . '" placeholder="' . esc_attr__( 'Please specify', 'blt-events' ) . '" aria-label="' . esc_attr__( 'Please specify', 'blt-events' ) . '" style="' . ( $other_val ? '' : 'display:none;' ) . '" />';
					}
					break;

				case 'radio':
					$html .= '<fieldset class="blt-choice-group"><legend>' . esc_html( $label ) . $req_star . '</legend><div class="blt-choices">';
					foreach ( $options as $n => $opt ) {
						$opt_id  = $id . '_' . $n;
						$checked = ( (string) $value === (string) $opt ) ? ' checked' : '';
						$html   .= '<label class="blt-choice" for="' . esc_attr( $opt_id ) . '"><input type="radio" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt ) . '"' . $checked . ( $required ? ' required' : '' ) . ' /> <span>' . esc_html( $opt ) . '</span></label>';
					}
					if ( $allow_other ) {
						$is_other  = ( $value && ! in_array( $value, $options, true ) );
						$other_val = $is_other ? $value : '';
						$html     .= '<label class="blt-choice" for="' . esc_attr( $id ) . '_other"><input type="radio" id="' . esc_attr( $id ) . '_other" name="' . esc_attr( $name ) . '" value="__other__"' . ( $is_other ? ' checked' : '' ) . ' /> <span>' . esc_html__( 'Other...', 'blt-events' ) . '</span></label>';
						$html     .= '<input type="text" class="blt-other-input" name="' . esc_attr( $name ) . '__other" value="' . esc_attr( $other_val ) . '" placeholder="' . esc_attr__( 'Please specify', 'blt-events' ) . '" aria-label="' . esc_attr__( 'Please specify', 'blt-events' ) . '" style="' . ( $other_val ? '' : 'display:none;' ) . '" />';
					}
					$html .= '</div></fieldset>';
					break;

				case 'checkbox_group':
					$values = is_array( $value ) ? array_map( 'strval', $value ) : ( '' !== (string) $value ? array( (string) $value ) : array() );
					$html  .= '<fieldset class="blt-choice-group" data-blt-required="' . ( $required ? '1' : '0' ) . '"><legend>' . esc_html( $label ) . $req_star . '</legend><div class="blt-choices">';
					foreach ( $options as $n => $opt ) {
						$opt_id  = $id . '_' . $n;
						$checked = in_array( (string) $opt, $values, true ) ? ' checked' : '';
						$html   .= '<label class="blt-choice" for="' . esc_attr( $opt_id ) . '"><input type="checkbox" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $opt ) . '"' . $checked . ' /> <span>' . esc_html( $opt ) . '</span></label>';
					}
					$html .= '</div></fieldset>';
					break;

				case 'textarea':
					$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_star . '</label>';
					$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>';
					break;

				case 'checkbox':
					$checked = ! empty( $value ) ? ' checked' : '';
					$html   .= '<label class="blt-check-label" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . $checked . $req_attr . ' /> <span>' . esc_html( $label ) . $req_star . '</span></label>';
					break;

				default: // text, email, tel, url, number, date
					$input_type = in_array( $type, array( 'text', 'email', 'tel', 'url', 'number', 'date' ), true ) ? $type : 'text';
					$html      .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_star . '</label>';
					$html      .= '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $req_attr . $attrs . ' />';
					break;
			}
		}

		if ( '' !== $field['description'] && 'html' !== $type ) {
			$html .= '<p class="blt-field-desc">' . esc_html( $field['description'] ) . '</p>';
		}

		$html .= '</div>';

		/**
		 * Filter the rendered HTML of one registration field.
		 *
		 * @param string       $html   The field markup, wrapper included.
		 * @param array        $field  Field definition.
		 * @param string|array $value  Current value.
		 * @param string       $prefix Name prefix.
		 */
		return apply_filters( 'blt_events_render_field', $html, $field, $value, $prefix );
	}

	/**
	 * HTML5 validation attributes mirroring the field's server-side rules.
	 */
	private static function validation_attributes( $field ) {
		$rules = $field['validation'];
		$attrs = '';

		if ( isset( $rules['min_length'] ) && $rules['min_length'] > 0 ) {
			$attrs .= ' minlength="' . (int) $rules['min_length'] . '"';
		}
		if ( isset( $rules['max_length'] ) && $rules['max_length'] > 0 ) {
			$attrs .= ' maxlength="' . (int) $rules['max_length'] . '"';
		}
		if ( 'number' === $field['type'] ) {
			if ( isset( $rules['min'] ) ) {
				$attrs .= ' min="' . esc_attr( $rules['min'] ) . '"';
			}
			if ( isset( $rules['max'] ) ) {
				$attrs .= ' max="' . esc_attr( $rules['max'] ) . '"';
			}
			$attrs .= ' step="any"';
		}
		if ( ! empty( $rules['pattern'] ) && in_array( $field['type'], array( 'text', 'tel', 'url', 'email' ), true ) ) {
			$attrs .= ' pattern="' . esc_attr( $rules['pattern'] ) . '"';
		}
		if ( ! empty( $rules['message'] ) ) {
			$attrs .= ' title="' . esc_attr( $rules['message'] ) . '"';
		}

		return $attrs;
	}

	/* ------------------------------------------------------------------
	 * Validation
	 * ---------------------------------------------------------------- */

	/**
	 * Whether a field's conditional logic is satisfied by the submitted data.
	 *
	 * @param array $field       Field definition.
	 * @param array $posted_data Submitted data.
	 * @return bool True when the field is shown (or has no condition).
	 */
	public static function condition_met( $field, $posted_data ) {
		$field = self::normalize_field( $field );
		$cond  = $field['conditional'];

		if ( empty( $cond['field'] ) ) {
			return true;
		}

		$raw = $posted_data[ $cond['field'] ] ?? '';
		if ( is_array( $raw ) ) {
			$values = array_map( 'strval', $raw );
		} else {
			$values = ( '' !== trim( (string) $raw ) ) ? array( trim( (string) $raw ) ) : array();
		}

		$expected = (string) ( $cond['value'] ?? '' );
		$operator = $cond['operator'] ?? 'is';

		switch ( $operator ) {
			case 'is':
				return in_array( $expected, $values, true );
			case 'is_not':
				return ! in_array( $expected, $values, true );
			case 'contains':
				foreach ( $values as $v ) {
					if ( '' !== $expected && false !== stripos( $v, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'not_empty':
				return ! empty( $values );
			case 'empty':
				return empty( $values );
		}

		return true;
	}

	/**
	 * Apply a field's validation rules to a (non-empty) value.
	 *
	 * @param mixed $value Value after type sanitizing.
	 * @param array $field Field definition.
	 * @return true|WP_Error
	 */
	public static function validate_rules( $value, $field ) {
		$rules = $field['validation'];
		if ( empty( $rules ) || ( is_string( $value ) && '' === $value ) ) {
			return true;
		}

		$label   = $field['label'];
		$message = ! empty( $rules['message'] ) ? $rules['message'] : '';

		$fail = function ( $default ) use ( $message ) {
			return new WP_Error( 'validation_error', $message ?: $default );
		};

		if ( is_string( $value ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

			if ( ! empty( $rules['min_length'] ) && $length < (int) $rules['min_length'] ) {
				/* translators: 1: field label, 2: minimum length. */
				return $fail( sprintf( __( '%1$s must be at least %2$d characters.', 'blt-events' ), $label, (int) $rules['min_length'] ) );
			}
			if ( ! empty( $rules['max_length'] ) && $length > (int) $rules['max_length'] ) {
				/* translators: 1: field label, 2: maximum length. */
				return $fail( sprintf( __( '%1$s must be at most %2$d characters.', 'blt-events' ), $label, (int) $rules['max_length'] ) );
			}
			if ( ! empty( $rules['pattern'] ) ) {
				$regex = '/^(?:' . str_replace( '/', '\/', $rules['pattern'] ) . ')$/u';
				if ( ! @preg_match( $regex, $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					/* translators: %s: field label. */
					return $fail( sprintf( __( '%s is not in the expected format.', 'blt-events' ), $label ) );
				}
			}
		}

		if ( is_numeric( $value ) ) {
			if ( isset( $rules['min'] ) && (float) $value < (float) $rules['min'] ) {
				/* translators: 1: field label, 2: minimum value. */
				return $fail( sprintf( __( '%1$s must be at least %2$s.', 'blt-events' ), $label, $rules['min'] ) );
			}
			if ( isset( $rules['max'] ) && (float) $value > (float) $rules['max'] ) {
				/* translators: 1: field label, 2: maximum value. */
				return $fail( sprintf( __( '%1$s must be at most %2$s.', 'blt-events' ), $label, $rules['max'] ) );
			}
		}

		return true;
	}

	/**
	 * Validate submitted form data against a fieldset definition.
	 *
	 * @param object $fieldset    The fieldset row.
	 * @param array  $posted_data Submitted data (unslashed).
	 * @param bool   $strict      When false, missing required fields are recorded
	 *                            under `_missing_required` instead of failing the
	 *                            whole submission. Off-site checkouts (SureCart,
	 *                            FluentCart) collect only the buyer's name and
	 *                            email, so a fieldset with any other required
	 *                            field would otherwise reject every paid order
	 *                            after the money has already been taken. Type
	 *                            validation and sanitizing still apply either way.
	 * @return array|WP_Error Sanitized data array on success, WP_Error on failure.
	 */
	public static function validate_submission( $fieldset, $posted_data, $strict = true ) {
		$fields         = self::get_fields( $fieldset );
		$consent_fields = self::get_consent_fields( $fieldset );
		$errors         = array();
		$clean          = array();
		$missing        = array();

		foreach ( $fields as $field ) {
			$def = self::field_type( $field['type'] );
			$key = $field['key'];

			if ( empty( $def['stores_value'] ) ) {
				continue;
			}

			// A field hidden by conditional logic is neither required nor stored.
			if ( ! self::condition_met( $field, $posted_data ) ) {
				$clean[ $key ] = $def['multiple'] ? array() : '';
				continue;
			}

			$raw = $posted_data[ $key ] ?? '';

			if ( $def['multiple'] ) {
				$values = is_array( $raw ) ? array_map( 'strval', $raw ) : ( '' !== trim( (string) $raw ) ? array( (string) $raw ) : array() );
				// Only options the field actually offers.
				$value = array_values( array_intersect( array_map( 'trim', $values ), array_map( 'strval', $field['options'] ) ) );
				$empty = empty( $value );
			} else {
				$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';

				// Handle "other" values for choice fields.
				if ( in_array( $field['type'], array( 'select', 'radio' ), true ) && ! empty( $field['allow_other'] ) && $value === '__other__' ) {
					$other_raw = $posted_data[ $key . '__other' ] ?? '';
					$value     = is_scalar( $other_raw ) ? trim( (string) $other_raw ) : '';
				}

				$empty = ( '' === $value );
			}

			// Required check
			if ( ! empty( $field['required'] ) && $empty ) {
				if ( $strict ) {
					/* translators: %s: field label. */
					$errors[] = sprintf( __( '%s is required.', 'blt-events' ), $field['label'] );
					continue;
				}

				$missing[]     = $field['label'];
				$clean[ $key ] = $def['multiple'] ? array() : '';
				continue;
			}

			// Type-specific validation
			if ( is_callable( $def['sanitize'] ) ) {
				$value = call_user_func( $def['sanitize'], $value, $field );
				if ( is_wp_error( $value ) ) {
					$errors[] = $value->get_error_message();
					continue;
				}
			} elseif ( ! $def['multiple'] ) {
				switch ( $field['type'] ) {
					case 'email':
						if ( $value && ! is_email( $value ) ) {
							/* translators: %s: field label. */
							$errors[] = sprintf( __( '%s must be a valid email address.', 'blt-events' ), $field['label'] );
							continue 2;
						}
						$value = sanitize_email( $value );
						break;
					case 'url':
						if ( $value ) {
							$value = esc_url_raw( $value );
							if ( '' === $value ) {
								/* translators: %s: field label. */
								$errors[] = sprintf( __( '%s must be a valid URL.', 'blt-events' ), $field['label'] );
								continue 2;
							}
						}
						break;
					case 'tel':
						$value = BLT_Events_Helpers::sanitize_phone( $value );
						break;
					case 'number':
						if ( '' !== $value && ! is_numeric( $value ) ) {
							/* translators: %s: field label. */
							$errors[] = sprintf( __( '%s must be a number.', 'blt-events' ), $field['label'] );
							continue 2;
						}
						$value = '' === $value ? '' : (float) $value;
						break;
					case 'date':
						if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
							/* translators: %s: field label. */
							$errors[] = sprintf( __( '%s must be a valid date.', 'blt-events' ), $field['label'] );
							continue 2;
						}
						break;
					case 'select':
					case 'radio':
						$value = sanitize_text_field( $value );
						// Without "other", only offered options are accepted.
						if ( '' !== $value && empty( $field['allow_other'] ) && ! in_array( $value, array_map( 'strval', $field['options'] ), true ) ) {
							/* translators: %s: field label. */
							$errors[] = sprintf( __( '%s has an invalid selection.', 'blt-events' ), $field['label'] );
							continue 2;
						}
						break;
					case 'country':
						$value = sanitize_text_field( $value );
						if ( '' !== $value && ! isset( self::countries()[ $value ] ) ) {
							/* translators: %s: field label. */
							$errors[] = sprintf( __( '%s has an invalid selection.', 'blt-events' ), $field['label'] );
							continue 2;
						}
						break;
					case 'checkbox':
						$value = $value ? '1' : '';
						break;
					case 'textarea':
						$value = sanitize_textarea_field( $value );
						break;
					default:
						$value = sanitize_text_field( $value );
						break;
				}
			}

			$rule_check = self::validate_rules( $value, $field );
			if ( is_wp_error( $rule_check ) ) {
				$errors[] = $rule_check->get_error_message();
				continue;
			}

			$clean[ $key ] = $value;
		}

		// Validate consent fields
		$clean['_consents'] = array();
		foreach ( $consent_fields as $cf ) {
			$ck = 'consent_' . $cf['key'];
			$cv = ! empty( $posted_data[ $ck ] );

			if ( ! empty( $cf['required'] ) && ! $cv ) {
				if ( $strict ) {
					/* translators: %s: consent text. */
					$errors[] = sprintf( __( 'You must accept: %s', 'blt-events' ), wp_strip_all_tags( $cf['label'] ) );
				} else {
					$missing[] = wp_strip_all_tags( $cf['label'] );
				}
			}

			$clean['_consents'][ $cf['key'] ] = $cv;
		}

		/**
		 * Filter validation errors before they are returned. Add your own
		 * checks here (return additional messages) or clear false positives.
		 *
		 * @param string[] $errors      Error messages.
		 * @param array    $clean       Sanitized data so far.
		 * @param array    $posted_data Raw submitted data.
		 * @param object   $fieldset    The fieldset row.
		 */
		$errors = (array) apply_filters( 'blt_events_validation_errors', $errors, $clean, $posted_data, $fieldset );

		if ( ! empty( $errors ) ) {
			$wp_error = new WP_Error();
			foreach ( $errors as $msg ) {
				$wp_error->add( 'validation_error', $msg );
			}
			return $wp_error;
		}

		// Lenient mode: tell the caller what still has to be collected so the
		// registration can be flagged for follow-up rather than silently
		// stored as complete.
		if ( ! empty( $missing ) ) {
			$clean['_missing_required'] = $missing;
		}

		return $clean;
	}

	/* ------------------------------------------------------------------
	 * CRUD
	 * ---------------------------------------------------------------- */

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

	/* ------------------------------------------------------------------
	 * Countries
	 * ---------------------------------------------------------------- */

	/**
	 * ISO 3166-1 alpha-2 code => English name.
	 *
	 * @return array<string,string>
	 */
	public static function countries() {
		static $countries = null;

		if ( null === $countries ) {
			$countries = array(
				'AF' => 'Afghanistan', 'AX' => 'Åland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
				'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
				'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (DRC)', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CW' => 'Curaçao', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
				'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
				'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
				'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia',
				'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
				'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary',
				'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel', 'IT' => 'Italy',
				'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan',
				'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan',
				'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
				'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar',
				'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'KP' => 'North Korea', 'MK' => 'North Macedonia', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway',
				'OM' => 'Oman',
				'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico',
				'QA' => 'Qatar',
				'RE' => 'Réunion', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda',
				'BL' => 'Saint Barthélemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'São Tomé and Príncipe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SX' => 'Sint Maarten', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'KR' => 'South Korea', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
				'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu',
				'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
				'VU' => 'Vanuatu', 'VA' => 'Vatican City', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'VG' => 'Virgin Islands (British)', 'VI' => 'Virgin Islands (U.S.)',
				'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara',
				'YE' => 'Yemen',
				'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
			);

			/**
			 * Filter the country list used by the "country" field type.
			 *
			 * @param array $countries ISO code => name.
			 */
			$countries = apply_filters( 'blt_events_countries', $countries );
		}

		return $countries;
	}
}
