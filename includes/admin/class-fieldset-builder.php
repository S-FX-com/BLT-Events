<?php
/**
 * BLT Events - Fieldset Builder Admin Page
 *
 * Provides a drag-and-drop interface for creating and editing registration fieldsets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Fieldset_Builder {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_blt_save_fieldset', array( __CLASS__, 'ajax_save_fieldset' ) );
		add_action( 'wp_ajax_blt_delete_fieldset', array( __CLASS__, 'ajax_delete_fieldset' ) );
	}

	public static function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'blt-fieldsets' ) === false ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_style(
			'blt-fieldset-builder',
			BLT_EVENTS_PLUGIN_URL . 'assets/css/fieldset-builder.css',
			array(),
			BLT_EVENTS_VERSION
		);

		wp_enqueue_script(
			'blt-fieldset-builder',
			BLT_EVENTS_PLUGIN_URL . 'assets/js/fieldset-builder.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			BLT_EVENTS_VERSION,
			true
		);

		wp_localize_script( 'blt-fieldset-builder', 'bltFieldsetData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'blt_fieldset_nonce' ),
		) );
	}

	public static function render_page() {
		$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$fieldset   = null;
		$fields     = array();
		$consent_fields = array();

		if ( $editing_id ) {
			$fieldset       = BLT_Events_Fieldsets::get_fieldset( $editing_id );
			$fields         = BLT_Events_Fieldsets::get_fields( $fieldset );
			$consent_fields = BLT_Events_Fieldsets::get_consent_fields( $fieldset );
		}

		$all_fieldsets = BLT_Events_Fieldsets::get_active_fieldsets();
		?>
		<div class="wrap blt-fieldset-builder">
			<h1>Registration Fieldsets</h1>

			<!-- Fieldset List -->
			<div class="blt-fieldset-list" id="blt-fieldset-list">
				<h2>Existing Fieldsets</h2>
				<table class="widefat">
					<thead>
						<tr>
							<th>Name</th>
							<th>Slug</th>
							<th>Fields</th>
							<th>Default</th>
							<th>Actions</th>
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
									<td><?php echo $fs->is_default ? 'Yes' : ''; ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-fieldsets&edit=' . $fs->id ) ); ?>" class="button button-small">Edit</a>
										<?php if ( ! $fs->is_default ) : ?>
											<button type="button" class="button button-small button-link-delete blt-delete-fieldset" data-id="<?php echo esc_attr( $fs->id ); ?>">Delete</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="5">No fieldsets found.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Fieldset Editor -->
			<div class="blt-fieldset-editor" id="blt-fieldset-editor">
				<h2><?php echo $editing_id ? 'Edit Fieldset' : 'Create New Fieldset'; ?></h2>
				<form id="blt-fieldset-form" method="post">
					<input type="hidden" name="fieldset_id" value="<?php echo esc_attr( $editing_id ); ?>" />

					<table class="form-table">
						<tr>
							<th><label for="fieldset_name">Name</label></th>
							<td><input type="text" id="fieldset_name" name="fieldset_name" value="<?php echo esc_attr( $fieldset ? $fieldset->name : '' ); ?>" class="regular-text" required /></td>
						</tr>
						<tr>
							<th><label for="fieldset_slug">Slug</label></th>
							<td><input type="text" id="fieldset_slug" name="fieldset_slug" value="<?php echo esc_attr( $fieldset ? $fieldset->slug : '' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="fieldset_description">Description</label></th>
							<td><textarea id="fieldset_description" name="fieldset_description" class="large-text" rows="2"><?php echo esc_textarea( $fieldset ? $fieldset->description : '' ); ?></textarea></td>
						</tr>
					</table>

					<h3>Registration Fields</h3>
					<p class="description">Drag and drop to reorder fields. Fields with a red asterisk (*) are required.</p>

					<div id="blt-fields-sortable" class="blt-fields-list">
						<?php foreach ( $fields as $i => $field ) : ?>
							<div class="blt-field-item" data-index="<?php echo (int) $i; ?>">
								<div class="blt-field-header">
									<span class="blt-field-drag dashicons dashicons-move"></span>
									<span class="blt-field-label"><?php echo esc_html( $field['label'] ); ?></span>
									<span class="blt-field-type"><?php echo esc_html( $field['type'] ); ?></span>
									<button type="button" class="blt-field-toggle dashicons dashicons-arrow-down-alt2"></button>
									<button type="button" class="blt-field-remove dashicons dashicons-trash"></button>
								</div>
								<div class="blt-field-settings" style="display:none;">
									<input type="hidden" name="fields[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" />
									<label>Label: <input type="text" name="fields[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" /></label>
									<label>Type:
										<select name="fields[<?php echo (int) $i; ?>][type]">
											<?php foreach ( array( 'text', 'email', 'tel', 'url', 'number', 'date', 'select', 'textarea', 'checkbox' ) as $t ) : ?>
												<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field['type'], $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Width:
										<select name="fields[<?php echo (int) $i; ?>][width]">
											<option value="full" <?php selected( $field['width'] ?? 'full', 'full' ); ?>>Full</option>
											<option value="half" <?php selected( $field['width'] ?? '', 'half' ); ?>>Half</option>
											<option value="third" <?php selected( $field['width'] ?? '', 'third' ); ?>>Third</option>
										</select>
									</label>
									<label><input type="checkbox" name="fields[<?php echo (int) $i; ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> /> Required</label>
									<label>Placeholder: <input type="text" name="fields[<?php echo (int) $i; ?>][placeholder]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" /></label>
									<label>Options (comma-separated): <input type="text" name="fields[<?php echo (int) $i; ?>][options_str]" value="<?php echo esc_attr( implode( ', ', $field['options'] ?? array() ) ); ?>" /></label>
									<label><input type="checkbox" name="fields[<?php echo (int) $i; ?>][allow_other]" value="1" <?php checked( ! empty( $field['allow_other'] ) ); ?> /> Allow "Other" option</label>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<p><button type="button" class="button" id="blt-add-field">+ Add Field</button></p>

					<h3>Consent Fields</h3>
					<div id="blt-consent-fields">
						<?php foreach ( $consent_fields as $ci => $cf ) : ?>
							<div class="blt-consent-item">
								<label>Key: <input type="text" name="consent[<?php echo $ci; ?>][key]" value="<?php echo esc_attr( $cf['key'] ); ?>" /></label>
								<label>Label (HTML): <input type="text" name="consent[<?php echo $ci; ?>][label]" value="<?php echo esc_attr( $cf['label'] ); ?>" class="large-text" /></label>
								<label><input type="checkbox" name="consent[<?php echo $ci; ?>][required]" value="1" <?php checked( ! empty( $cf['required'] ) ); ?> /> Required</label>
								<button type="button" class="button button-link-delete blt-remove-consent">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>
					<p><button type="button" class="button" id="blt-add-consent">+ Add Consent Field</button></p>

					<p class="submit">
						<button type="submit" class="button button-primary" id="blt-save-fieldset">Save Fieldset</button>
						<?php if ( ! $editing_id ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-fieldsets' ) ); ?>" class="button">Cancel</a>
						<?php endif; ?>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	public static function ajax_save_fieldset() {
		check_ajax_referer( 'blt_fieldset_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$id   = absint( $_POST['fieldset_id'] ?? 0 );
		$name = sanitize_text_field( $_POST['fieldset_name'] ?? '' );
		$slug = sanitize_title( $_POST['fieldset_slug'] ?? '' );
		$desc = sanitize_textarea_field( $_POST['fieldset_description'] ?? '' );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Fieldset name is required.' ) );
		}

		if ( empty( $slug ) ) {
			$slug = sanitize_title( $name );
		}

		// Parse fields
		$fields = array();
		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			$order = 0;
			foreach ( $_POST['fields'] as $f ) {
				$options = array();
				if ( ! empty( $f['options_str'] ) ) {
					$options = array_map( 'trim', explode( ',', $f['options_str'] ) );
					$options = array_filter( $options );
				}

				$fields[] = array(
					'key'         => sanitize_key( $f['key'] ?? sanitize_title( $f['label'] ?? 'field_' . $order ) ),
					'type'        => sanitize_text_field( $f['type'] ?? 'text' ),
					'label'       => sanitize_text_field( $f['label'] ?? '' ),
					'required'    => ! empty( $f['required'] ),
					'width'       => sanitize_text_field( $f['width'] ?? 'full' ),
					'order'       => $order,
					'options'     => $options,
					'allow_other' => ! empty( $f['allow_other'] ),
					'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
					'validation'  => array(),
					'conditional' => array(),
				);
				$order++;
			}
		}

		// Parse consent fields
		$consent = array();
		if ( isset( $_POST['consent'] ) && is_array( $_POST['consent'] ) ) {
			foreach ( $_POST['consent'] as $c ) {
				$consent[] = array(
					'key'      => sanitize_key( $c['key'] ?? '' ),
					'label'    => wp_kses_post( $c['label'] ?? '' ),
					'required' => ! empty( $c['required'] ),
				);
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
			wp_send_json_error( array( 'message' => 'Failed to save fieldset.' ) );
		}

		wp_send_json_success( array(
			'message' => 'Fieldset saved successfully.',
			'id'      => $id ? $id : $result,
		) );
	}

	public static function ajax_delete_fieldset() {
		check_ajax_referer( 'blt_fieldset_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$id = absint( $_POST['fieldset_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid fieldset.' ) );
		}

		$result = BLT_Events_Fieldsets::delete_fieldset( $id );
		if ( $result === false ) {
			wp_send_json_error( array( 'message' => 'Failed to delete fieldset.' ) );
		}

		wp_send_json_success( array( 'message' => 'Fieldset deleted.' ) );
	}
}
