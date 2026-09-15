<?php
/**
 * BLT Events - Fieldset Builder Admin Page
 *
 * Provides a drag-and-drop interface for creating and editing registration
 * fieldsets: field types, widths, validation rules, conditional logic,
 * data mappings and consent checkboxes. New fieldsets can start from a
 * preset.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Fieldset_Builder {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_blt_save_fieldset', array( __CLASS__, 'ajax_save_fieldset' ) );
		add_action( 'wp_ajax_blt_delete_fieldset', array( __CLASS__, 'ajax_delete_fieldset' ) );
		add_action( 'wp_ajax_blt_duplicate_fieldset', array( __CLASS__, 'ajax_duplicate_fieldset' ) );
	}

	public static function enqueue_scripts( $hook ) {
		if ( 'event_page_blt-fieldsets' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_style(
			'blt-fieldset-builder',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/fieldset-builder.css',
			array( 'blt-events-admin' ),
			BLT_EVENTS_VERSION
		);

		wp_enqueue_script(
			'blt-fieldset-builder',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/fieldset-builder.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			BLT_EVENTS_VERSION,
			true
		);

		$types_with_options = array();
		foreach ( BLT_Events_Fieldsets::field_types() as $slug => $def ) {
			if ( ! empty( $def['has_options'] ) ) {
				$types_with_options[] = $slug;
			}
		}

		wp_localize_script( 'blt-fieldset-builder', 'bltFieldsetData', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'blt_fieldset_nonce' ),
			'presets'          => BLT_Events_Fieldsets::presets(),
			'typesWithOptions' => $types_with_options,
			'i18n'             => array(
				'newField'      => __( 'New Field', 'blt-events' ),
				'removeField'   => __( 'Remove this field?', 'blt-events' ),
				'deleteConfirm' => __( 'Are you sure you want to delete this fieldset?', 'blt-events' ),
				'saveFailed'    => __( 'Failed to save fieldset. Please try again.', 'blt-events' ),
				'error'         => __( 'Error:', 'blt-events' ),
				'applyPreset'   => __( 'Replace the current fields with this preset?', 'blt-events' ),
				'none'          => __( '— None —', 'blt-events' ),
				'saving'        => __( 'Saving…', 'blt-events' ),
				'save'          => __( 'Save Fieldset', 'blt-events' ),
			),
		) );
	}

	public static function render_page() {
		// Make sure the default fieldset exists so users can see what the
		// default registration fields actually are.
		if ( class_exists( 'BLT_Events_Activator' ) ) {
			BLT_Events_Activator::ensure_default_fieldset();
		}

		$editing_id     = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fieldset       = null;
		$fields         = array();
		$consent_fields = array();

		if ( $editing_id ) {
			$fieldset       = BLT_Events_Fieldsets::get_fieldset( $editing_id );
			$fields         = BLT_Events_Fieldsets::get_fields( $fieldset );
			$consent_fields = BLT_Events_Fieldsets::get_consent_fields( $fieldset );
		}

		$all_fieldsets = BLT_Events_Fieldsets::get_active_fieldsets();
		$presets       = BLT_Events_Fieldsets::presets();
		?>
		<div class="wrap blt-ui blt-fieldset-builder">
			<div class="blt-admin-page-header">
				<h1><?php esc_html_e( 'Registration Fieldsets', 'blt-events' ); ?></h1>
				<?php if ( $editing_id ) : ?>
					<div class="blt-admin-page-actions">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-fieldsets' ) ); ?>" class="button"><?php esc_html_e( 'Create New Fieldset', 'blt-events' ); ?></a>
					</div>
				<?php endif; ?>
			</div>

			<!-- Fieldset List -->
			<div class="blt-card" id="blt-fieldset-list">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Existing Fieldsets', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Reusable sets of registration fields. Assign one to an event from the event editor; events without one use the default.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'blt-events' ); ?></th>
								<th><?php esc_html_e( 'Slug', 'blt-events' ); ?></th>
								<th><?php esc_html_e( 'Fields', 'blt-events' ); ?></th>
								<th><?php esc_html_e( 'Default', 'blt-events' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'blt-events' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $all_fieldsets ) ) : ?>
								<?php foreach ( $all_fieldsets as $fs ) : ?>
									<?php $fs_fields = json_decode( $fs->fields, true ); ?>
									<tr>
										<td><strong><?php echo esc_html( $fs->name ); ?></strong></td>
										<td><code><?php echo esc_html( $fs->slug ); ?></code></td>
										<td><?php echo is_array( $fs_fields ) ? count( $fs_fields ) : 0; ?></td>
										<td><?php echo $fs->is_default ? '<span class="blt-badge blt-badge-on">' . esc_html__( 'Default', 'blt-events' ) . '</span>' : ''; ?></td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-fieldsets&edit=' . $fs->id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'blt-events' ); ?></a>
											<button type="button" class="button button-small blt-duplicate-fieldset" data-id="<?php echo esc_attr( $fs->id ); ?>"><?php esc_html_e( 'Duplicate', 'blt-events' ); ?></button>
											<?php if ( ! $fs->is_default ) : ?>
												<button type="button" class="button button-small button-link-delete blt-delete-fieldset" data-id="<?php echo esc_attr( $fs->id ); ?>"><?php esc_html_e( 'Delete', 'blt-events' ); ?></button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No fieldsets found.', 'blt-events' ); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Fieldset Editor -->
			<div class="blt-card" id="blt-fieldset-editor">
				<div class="blt-card-header">
					<h2><?php echo $editing_id ? esc_html__( 'Edit Fieldset', 'blt-events' ) : esc_html__( 'Create New Fieldset', 'blt-events' ); ?></h2>
					<?php if ( ! $editing_id ) : ?>
						<p><?php esc_html_e( 'Start from a preset or a blank form, then add, reorder and configure the fields attendees fill in.', 'blt-events' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="blt-card-body">
					<form id="blt-fieldset-form" method="post">
						<input type="hidden" name="fieldset_id" value="<?php echo esc_attr( $editing_id ); ?>" />

						<?php if ( ! $editing_id ) : ?>
							<div class="blt-field">
								<div class="blt-field-label"><label for="blt-preset"><?php esc_html_e( 'Start from', 'blt-events' ); ?></label></div>
								<div>
									<div class="blt-select-cards blt-preset-cards" role="radiogroup">
										<?php $first = true; foreach ( $presets as $slug => $preset ) : ?>
											<label class="blt-select-card <?php echo $first ? 'is-selected' : ''; ?>">
												<input type="radio" name="blt_preset" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $first ); ?> data-blt-preset />
												<span class="blt-select-card-check" aria-hidden="true"></span>
												<span class="blt-select-card-name"><?php echo esc_html( $preset['label'] ); ?></span>
												<span class="blt-select-card-desc"><?php echo esc_html( $preset['description'] ); ?></span>
											</label>
										<?php $first = false; endforeach; ?>
									</div>
								</div>
							</div>
						<?php endif; ?>

						<div class="blt-field">
							<div class="blt-field-label"><label for="fieldset_name"><?php esc_html_e( 'Name', 'blt-events' ); ?></label></div>
							<div><input type="text" id="fieldset_name" name="fieldset_name" value="<?php echo esc_attr( $fieldset ? $fieldset->name : '' ); ?>" class="regular-text" required /></div>
						</div>
						<div class="blt-field">
							<div class="blt-field-label"><label for="fieldset_slug"><?php esc_html_e( 'Slug', 'blt-events' ); ?></label></div>
							<div>
								<input type="text" id="fieldset_slug" name="fieldset_slug" value="<?php echo esc_attr( $fieldset ? $fieldset->slug : '' ); ?>" class="regular-text" />
								<p class="blt-field-desc"><?php esc_html_e( 'Leave blank to generate from the name.', 'blt-events' ); ?></p>
							</div>
						</div>
						<div class="blt-field">
							<div class="blt-field-label"><label for="fieldset_description"><?php esc_html_e( 'Description', 'blt-events' ); ?></label></div>
							<div><textarea id="fieldset_description" name="fieldset_description" class="large-text" rows="2"><?php echo esc_textarea( $fieldset ? $fieldset->description : '' ); ?></textarea></div>
						</div>
						<?php if ( $fieldset && ! $fieldset->is_default ) : ?>
							<div class="blt-field">
								<div class="blt-field-label"><?php esc_html_e( 'Default', 'blt-events' ); ?></div>
								<div>
									<label class="blt-toggle">
										<input type="checkbox" name="fieldset_make_default" value="1" />
										<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
										<span class="blt-toggle-text">
											<span class="blt-toggle-label"><?php esc_html_e( 'Make this the default fieldset', 'blt-events' ); ?></span>
											<span class="blt-toggle-desc"><?php esc_html_e( 'Used by every event that does not pick a fieldset of its own.', 'blt-events' ); ?></span>
										</span>
									</label>
								</div>
							</div>
						<?php endif; ?>

						<h3 class="blt-section-title"><?php esc_html_e( 'Registration Fields', 'blt-events' ); ?></h3>
						<p class="blt-field-desc"><?php esc_html_e( 'Drag and drop to reorder fields. Expand a field to set its type, width, validation rules, conditional logic and data mappings.', 'blt-events' ); ?></p>

						<div id="blt-fields-sortable" class="blt-fields-list">
							<?php foreach ( $fields as $i => $field ) : ?>
								<?php self::render_field_item( $i, $field ); ?>
							<?php endforeach; ?>
						</div>

						<datalist id="blt-user-field-suggestions">
							<option value="first_name"></option>
							<option value="last_name"></option>
							<option value="user_email"></option>
							<option value="display_name"></option>
							<option value="nickname"></option>
							<option value="description"></option>
							<option value="user_url"></option>
							<option value="phone"></option>
							<option value="billing_phone"></option>
							<option value="billing_company"></option>
						</datalist>

						<p><button type="button" class="button" id="blt-add-field">+ <?php esc_html_e( 'Add Field', 'blt-events' ); ?></button></p>

						<h3 class="blt-section-title"><?php esc_html_e( 'Consent Fields', 'blt-events' ); ?></h3>
						<p class="blt-field-desc"><?php esc_html_e( 'Checkboxes shown before the submit button. The label may contain links (HTML).', 'blt-events' ); ?></p>
						<div id="blt-consent-fields">
							<?php foreach ( $consent_fields as $ci => $cf ) : ?>
								<?php self::render_consent_item( $ci, $cf ); ?>
							<?php endforeach; ?>
						</div>
						<p><button type="button" class="button" id="blt-add-consent">+ <?php esc_html_e( 'Add Consent Field', 'blt-events' ); ?></button></p>

						<p class="submit">
							<button type="submit" class="button button-primary" id="blt-save-fieldset"><?php esc_html_e( 'Save Fieldset', 'blt-events' ); ?></button>
							<?php if ( ! $editing_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-fieldsets' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'blt-events' ); ?></a>
							<?php endif; ?>
						</p>
					</form>

					<script type="text/html" id="tmpl-blt-field-item">
						<?php self::render_field_item( '__i__', array( 'label' => __( 'New Field', 'blt-events' ), 'key' => '' ), true ); ?>
					</script>
					<script type="text/html" id="tmpl-blt-consent-item">
						<?php self::render_consent_item( '__i__', array( 'key' => '', 'label' => '', 'required' => true ) ); ?>
					</script>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * One field row in the builder. Also used as the JS template with
	 * $i = '__i__'.
	 *
	 * @param int|string $i        Index.
	 * @param array      $field    Field definition.
	 * @param bool       $expanded Whether the settings panel starts open.
	 */
	private static function render_field_item( $i, $field, $expanded = false ) {
		$field = BLT_Events_Fieldsets::normalize_field( $field );
		$types = BLT_Events_Fieldsets::field_types();
		$def   = BLT_Events_Fieldsets::field_type( $field['type'] );
		$rules = $field['validation'];
		$cond  = $field['conditional'];
		$name  = 'fields[' . $i . ']';
		$ops   = BLT_Events_Fieldsets::condition_operators();

		$is_html = 'html' === $field['type'];
		?>
		<div class="blt-field-item<?php echo $expanded ? ' is-expanded' : ''; ?>" data-index="<?php echo esc_attr( $i ); ?>" data-type="<?php echo esc_attr( $field['type'] ); ?>">
			<div class="blt-field-header">
				<span class="blt-field-drag dashicons dashicons-move" aria-hidden="true"></span>
				<span class="blt-fitem-label"><?php echo esc_html( $field['label'] ); ?></span>
				<code class="blt-fitem-key"><?php echo esc_html( $field['key'] ); ?></code>
				<span class="blt-field-type"><?php echo esc_html( $def['label'] ); ?></span>
				<?php if ( ! empty( $field['required'] ) ) : ?><span class="blt-fitem-required" title="<?php esc_attr_e( 'Required', 'blt-events' ); ?>">*</span><?php endif; ?>
				<button type="button" class="blt-field-toggle dashicons dashicons-arrow-down-alt2" aria-label="<?php esc_attr_e( 'Toggle field settings', 'blt-events' ); ?>"></button>
				<button type="button" class="blt-field-remove dashicons dashicons-trash" aria-label="<?php esc_attr_e( 'Remove field', 'blt-events' ); ?>"></button>
			</div>
			<div class="blt-field-settings" <?php echo $expanded ? '' : 'style="display:none;"'; ?>>
				<div class="blt-fs-group">
					<label><?php esc_html_e( 'Label', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" data-blt-fs="label" /></label>
					<label><?php esc_html_e( 'Key', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>" class="code" data-blt-fs="key" pattern="[a-z0-9_]*" placeholder="<?php esc_attr_e( 'auto', 'blt-events' ); ?>" /></label>
					<label><?php esc_html_e( 'Type', 'blt-events' ); ?>
						<select name="<?php echo esc_attr( $name ); ?>[type]" data-blt-fs="type">
							<?php foreach ( $types as $slug => $type_def ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $field['type'], $slug ); ?>><?php echo esc_html( $type_def['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Width', 'blt-events' ); ?>
						<select name="<?php echo esc_attr( $name ); ?>[width]" data-blt-fs="width">
							<option value="full" <?php selected( $field['width'], 'full' ); ?>><?php esc_html_e( 'Full', 'blt-events' ); ?></option>
							<option value="half" <?php selected( $field['width'], 'half' ); ?>><?php esc_html_e( 'Half', 'blt-events' ); ?></option>
							<option value="third" <?php selected( $field['width'], 'third' ); ?>><?php esc_html_e( 'Third', 'blt-events' ); ?></option>
						</select>
					</label>
					<label class="blt-fs-required"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> data-blt-fs="required" /> <?php esc_html_e( 'Required', 'blt-events' ); ?></label>
				</div>

				<div class="blt-fs-group">
					<label class="blt-fs-placeholder"><?php esc_html_e( 'Placeholder', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $field['placeholder'] ); ?>" data-blt-fs="placeholder" /></label>
					<label class="blt-fs-default"><?php esc_html_e( 'Default value', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[default]" value="<?php echo esc_attr( $field['default'] ); ?>" data-blt-fs="default" /></label>
					<label class="blt-fs-description blt-fs-wide"><?php esc_html_e( 'Help text', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[description]" value="<?php echo esc_attr( $field['description'] ); ?>" data-blt-fs="description" /></label>
				</div>

				<div class="blt-fs-group blt-fs-options" <?php echo $def['has_options'] ? '' : 'style="display:none;"'; ?>>
					<label class="blt-fs-wide"><?php esc_html_e( 'Options (comma-separated)', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[options_str]" value="<?php echo esc_attr( implode( ', ', $field['options'] ) ); ?>" data-blt-fs="options_str" /></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_other]" value="1" <?php checked( ! empty( $field['allow_other'] ) ); ?> data-blt-fs="allow_other" /> <?php esc_html_e( 'Allow "Other" option', 'blt-events' ); ?></label>
				</div>

				<div class="blt-fs-group blt-fs-content" <?php echo $is_html ? '' : 'style="display:none;"'; ?>>
					<label class="blt-fs-wide blt-fs-block"><?php esc_html_e( 'Content (HTML allowed)', 'blt-events' ); ?>
						<textarea name="<?php echo esc_attr( $name ); ?>[content]" rows="3" data-blt-fs="content"><?php echo esc_textarea( $field['content'] ); ?></textarea>
					</label>
				</div>

				<div class="blt-fs-group blt-fs-validation">
					<span class="blt-map-heading"><?php esc_html_e( 'Validation', 'blt-events' ); ?></span>
					<label class="blt-fs-len"><?php esc_html_e( 'Min length', 'blt-events' ); ?> <input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[validation][min_length]" value="<?php echo esc_attr( $rules['min_length'] ?? '' ); ?>" class="small-text" /></label>
					<label class="blt-fs-len"><?php esc_html_e( 'Max length', 'blt-events' ); ?> <input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[validation][max_length]" value="<?php echo esc_attr( $rules['max_length'] ?? '' ); ?>" class="small-text" /></label>
					<label class="blt-fs-num"><?php esc_html_e( 'Min value', 'blt-events' ); ?> <input type="number" step="any" name="<?php echo esc_attr( $name ); ?>[validation][min]" value="<?php echo esc_attr( $rules['min'] ?? '' ); ?>" class="small-text" /></label>
					<label class="blt-fs-num"><?php esc_html_e( 'Max value', 'blt-events' ); ?> <input type="number" step="any" name="<?php echo esc_attr( $name ); ?>[validation][max]" value="<?php echo esc_attr( $rules['max'] ?? '' ); ?>" class="small-text" /></label>
					<label class="blt-fs-pattern"><?php esc_html_e( 'Pattern (regex)', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[validation][pattern]" value="<?php echo esc_attr( $rules['pattern'] ?? '' ); ?>" class="code" placeholder="[A-Z]{2}[0-9]{4}" /></label>
					<label class="blt-fs-wide"><?php esc_html_e( 'Error message', 'blt-events' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[validation][message]" value="<?php echo esc_attr( $rules['message'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Shown when a rule fails (optional)', 'blt-events' ); ?>" /></label>
				</div>

				<div class="blt-fs-group blt-fs-conditional">
					<span class="blt-map-heading"><?php esc_html_e( 'Conditional logic', 'blt-events' ); ?></span>
					<label><?php esc_html_e( 'Show this field when', 'blt-events' ); ?>
						<select name="<?php echo esc_attr( $name ); ?>[conditional][field]" data-blt-fs="cond-field" data-current="<?php echo esc_attr( $cond['field'] ?? '' ); ?>">
							<option value=""><?php esc_html_e( '— Always —', 'blt-events' ); ?></option>
						</select>
					</label>
					<label>
						<select name="<?php echo esc_attr( $name ); ?>[conditional][operator]" data-blt-fs="cond-op">
							<?php foreach ( $ops as $op => $op_label ) : ?>
								<option value="<?php echo esc_attr( $op ); ?>" <?php selected( $cond['operator'] ?? 'is', $op ); ?>><?php echo esc_html( $op_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="blt-fs-cond-value"><input type="text" name="<?php echo esc_attr( $name ); ?>[conditional][value]" value="<?php echo esc_attr( $cond['value'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'value', 'blt-events' ); ?>" data-blt-fs="cond-value" /></label>
				</div>

				<div class="blt-map-group">
					<span class="blt-map-heading"><?php esc_html_e( 'Data Mapping', 'blt-events' ); ?></span>
					<label><?php esc_html_e( 'User profile field:', 'blt-events' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[map_user]" value="<?php echo esc_attr( $field['map_user'] ); ?>" list="blt-user-field-suggestions" placeholder="first_name" />
					</label>
					<label><?php esc_html_e( 'ACF field:', 'blt-events' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[map_acf]" value="<?php echo esc_attr( $field['map_acf'] ); ?>" placeholder="<?php esc_attr_e( 'ACF field name', 'blt-events' ); ?>" />
					</label>
					<label><?php esc_html_e( 'FluentCRM field:', 'blt-events' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[map_fluentcrm]" value="<?php echo esc_attr( $field['map_fluentcrm'] ); ?>" placeholder="<?php esc_attr_e( 'contact field or custom field slug', 'blt-events' ); ?>" />
					</label>
					<span class="blt-map-help"><?php esc_html_e( 'Prefills from the logged-in user\'s profile (WP user field/meta or ACF user field) and pushes the submitted value to the mapped FluentCRM contact field after registration.', 'blt-events' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_consent_item( $ci, $cf ) {
		?>
		<div class="blt-consent-item">
			<label><?php esc_html_e( 'Key:', 'blt-events' ); ?> <input type="text" name="consent[<?php echo esc_attr( $ci ); ?>][key]" value="<?php echo esc_attr( $cf['key'] ?? '' ); ?>" class="code" /></label>
			<label><?php esc_html_e( 'Label (HTML):', 'blt-events' ); ?> <input type="text" name="consent[<?php echo esc_attr( $ci ); ?>][label]" value="<?php echo esc_attr( $cf['label'] ?? '' ); ?>" class="large-text" /></label>
			<label><input type="checkbox" name="consent[<?php echo esc_attr( $ci ); ?>][required]" value="1" <?php checked( ! empty( $cf['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'blt-events' ); ?></label>
			<button type="button" class="button button-link-delete blt-remove-consent" aria-label="<?php esc_attr_e( 'Remove consent field', 'blt-events' ); ?>">&times;</button>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	public static function ajax_save_fieldset() {
		check_ajax_referer( 'blt_fieldset_nonce', 'nonce' );

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'blt-events' ) ) );
		}

		$id   = absint( $_POST['fieldset_id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['fieldset_name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['fieldset_slug'] ?? '' ) );
		$desc = sanitize_textarea_field( wp_unslash( $_POST['fieldset_description'] ?? '' ) );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Fieldset name is required.', 'blt-events' ) ) );
		}

		if ( empty( $slug ) ) {
			$slug = sanitize_title( $name );
		}

		// Parse fields
		$fields = array();
		$keys   = array();
		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			$order = 0;
			foreach ( wp_unslash( $_POST['fields'] ) as $f ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by sanitize_field_definition().
				$clean = BLT_Events_Fieldsets::sanitize_field_definition( $f, $order );
				if ( ! $clean ) {
					continue;
				}

				// Keys must be unique within a fieldset.
				$base = $clean['key'];
				$n    = 2;
				while ( in_array( $clean['key'], $keys, true ) ) {
					$clean['key'] = $base . '_' . $n++;
				}
				$keys[] = $clean['key'];

				$fields[] = $clean;
				$order++;
			}
		}

		// Parse consent fields
		$consent = array();
		if ( isset( $_POST['consent'] ) && is_array( $_POST['consent'] ) ) {
			$ci = 0;
			foreach ( wp_unslash( $_POST['consent'] ) as $c ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by sanitize_consent_definition().
				$clean = BLT_Events_Fieldsets::sanitize_consent_definition( $c, $ci++ );
				if ( $clean && '' !== $clean['label'] ) {
					$consent[] = $clean;
				}
			}
		}

		$data = array(
			'name'           => $name,
			'slug'           => $slug,
			'description'    => $desc,
			'fields'         => wp_json_encode( $fields ),
			'consent_fields' => wp_json_encode( $consent ),
			'status'         => 'active',
		);

		if ( $id ) {
			$data['id'] = $id;
		}

		$result = BLT_Events_Fieldsets::save_fieldset( $data );

		if ( $result === false ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save fieldset.', 'blt-events' ) ) );
		}

		$saved_id = $id ? $id : (int) $result;

		if ( ! empty( $_POST['fieldset_make_default'] ) && $saved_id ) {
			self::make_default( $saved_id );
		}

		wp_send_json_success( array(
			'message' => __( 'Fieldset saved successfully.', 'blt-events' ),
			'id'      => $saved_id,
		) );
	}

	/**
	 * Move the default flag to one fieldset.
	 */
	private static function make_default( $fieldset_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'blt_fieldsets';

		$wpdb->update( $table, array( 'is_default' => 0 ), array( 'is_default' => 1 ) );
		$wpdb->update( $table, array( 'is_default' => 1 ), array( 'id' => absint( $fieldset_id ) ) );
	}

	public static function ajax_delete_fieldset() {
		check_ajax_referer( 'blt_fieldset_nonce', 'nonce' );

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'blt-events' ) ) );
		}

		$id = absint( $_POST['fieldset_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid fieldset.', 'blt-events' ) ) );
		}

		$existing = BLT_Events_Fieldsets::get_fieldset( $id );
		if ( $existing && $existing->is_default ) {
			wp_send_json_error( array( 'message' => __( 'The default fieldset cannot be deleted. Make another fieldset the default first.', 'blt-events' ) ) );
		}

		$result = BLT_Events_Fieldsets::delete_fieldset( $id );
		if ( $result === false ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete fieldset.', 'blt-events' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Fieldset deleted.', 'blt-events' ) ) );
	}

	public static function ajax_duplicate_fieldset() {
		check_ajax_referer( 'blt_fieldset_nonce', 'nonce' );

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'blt-events' ) ) );
		}

		$id       = absint( $_POST['fieldset_id'] ?? 0 );
		$existing = $id ? BLT_Events_Fieldsets::get_fieldset( $id ) : null;
		if ( ! $existing ) {
			wp_send_json_error( array( 'message' => __( 'Invalid fieldset.', 'blt-events' ) ) );
		}

		$slug = $existing->slug . '-copy';
		$db   = new BLT_Events_Fieldsets_DB();
		$n    = 2;
		while ( $db->get_by_slug( $slug ) ) {
			$slug = $existing->slug . '-copy-' . $n++;
		}

		$new_id = BLT_Events_Fieldsets::save_fieldset( array(
			/* translators: %s: original fieldset name. */
			'name'           => sprintf( __( '%s (copy)', 'blt-events' ), $existing->name ),
			'slug'           => $slug,
			'description'    => $existing->description,
			'fields'         => $existing->fields,
			'consent_fields' => $existing->consent_fields,
			'status'         => 'active',
		) );

		if ( ! $new_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to duplicate fieldset.', 'blt-events' ) ) );
		}

		wp_send_json_success( array( 'id' => (int) $new_id ) );
	}
}
