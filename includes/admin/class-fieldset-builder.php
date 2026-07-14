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
		<div class="wrap blt-ui blt-fieldset-builder">
			<div class="blt-admin-page-header">
				<h1><?php esc_html_e( 'Registration Fieldsets', 'blt-events' ); ?></h1>
			</div>

			<!-- Fieldset List -->
			<div class="blt-card" id="blt-fieldset-list">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Existing Fieldsets', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Reusable sets of registration fields. Assign one to an event from the event editor.', 'blt-events' ); ?></p>
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
				</div>
				<div class="blt-card-body">
					<form id="blt-fieldset-form" method="post">
						<input type="hidden" name="fieldset_id" value="<?php echo esc_attr( $editing_id ); ?>" />

						<div class="blt-field">
							<div class="blt-field-label"><label for="fieldset_name"><?php esc_html_e( 'Name', 'blt-events' ); ?></label></div>
							<div><input type="text" id="fieldset_name" name="fieldset_name" value="<?php echo esc_attr( $fieldset ? $fieldset->name : '' ); ?>" class="regular-text" required /></div>
						</div>
						<div class="blt-field">
							<div class="blt-field-label"><label for="fieldset_slug"><?php esc_html_e( 'Slug', 'blt-events' ); ?></label></div>
							<div><input type="text" id="fieldset_slug" name="fieldset_slug" value="<?php echo esc_attr( $fieldset ? $fieldset->slug : '' ); ?>" class="regular-text" /></div>
						</div>
						<div class="blt-field">
							<div class="blt-field-label"><label for="fieldset_description"><?php esc_html_e( 'Description', 'blt-events' ); ?></label></div>
							<div><textarea id="fieldset_description" name="fieldset_description" class="large-text" rows="2"><?php echo esc_textarea( $fieldset ? $fieldset->description : '' ); ?></textarea></div>
						</div>

						<h3 class="blt-section-title"><?php esc_html_e( 'Registration Fields', 'blt-events' ); ?></h3>
						<p class="blt-field-desc"><?php esc_html_e( 'Drag and drop to reorder fields. Fields with a red asterisk (*) are required.', 'blt-events' ); ?></p>

						<div id="blt-fields-sortable" class="blt-fields-list">
							<?php foreach ( $fields as $i => $field ) : ?>
								<div class="blt-field-item" data-index="<?php echo (int) $i; ?>">
									<div class="blt-field-header">
										<span class="blt-field-drag dashicons dashicons-move"></span>
										<span class="blt-fitem-label"><?php echo esc_html( $field['label'] ); ?></span>
										<span class="blt-field-type"><?php echo esc_html( $field['type'] ); ?></span>
										<button type="button" class="blt-field-toggle dashicons dashicons-arrow-down-alt2"></button>
										<button type="button" class="blt-field-remove dashicons dashicons-trash"></button>
									</div>
									<div class="blt-field-settings" style="display:none;">
										<input type="hidden" name="fields[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" />
										<label><?php esc_html_e( 'Label:', 'blt-events' ); ?> <input type="text" name="fields[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" /></label>
										<label><?php esc_html_e( 'Type:', 'blt-events' ); ?>
											<select name="fields[<?php echo (int) $i; ?>][type]">
												<?php foreach ( array( 'text', 'email', 'tel', 'url', 'number', 'date', 'select', 'textarea', 'checkbox' ) as $t ) : ?>
													<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field['type'], $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label><?php esc_html_e( 'Width:', 'blt-events' ); ?>
											<select name="fields[<?php echo (int) $i; ?>][width]">
												<option value="full" <?php selected( $field['width'] ?? 'full', 'full' ); ?>><?php esc_html_e( 'Full', 'blt-events' ); ?></option>
												<option value="half" <?php selected( $field['width'] ?? '', 'half' ); ?>><?php esc_html_e( 'Half', 'blt-events' ); ?></option>
												<option value="third" <?php selected( $field['width'] ?? '', 'third' ); ?>><?php esc_html_e( 'Third', 'blt-events' ); ?></option>
											</select>
										</label>
										<label><input type="checkbox" name="fields[<?php echo (int) $i; ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'blt-events' ); ?></label>
										<label><?php esc_html_e( 'Placeholder:', 'blt-events' ); ?> <input type="text" name="fields[<?php echo (int) $i; ?>][placeholder]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" /></label>
										<label><?php esc_html_e( 'Options (comma-separated):', 'blt-events' ); ?> <input type="text" name="fields[<?php echo (int) $i; ?>][options_str]" value="<?php echo esc_attr( implode( ', ', $field['options'] ?? array() ) ); ?>" /></label>
										<label><input type="checkbox" name="fields[<?php echo (int) $i; ?>][allow_other]" value="1" <?php checked( ! empty( $field['allow_other'] ) ); ?> /> <?php esc_html_e( 'Allow "Other" option', 'blt-events' ); ?></label>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<p><button type="button" class="button" id="blt-add-field">+ <?php esc_html_e( 'Add Field', 'blt-events' ); ?></button></p>

						<h3 class="blt-section-title"><?php esc_html_e( 'Consent Fields', 'blt-events' ); ?></h3>
						<div id="blt-consent-fields">
							<?php foreach ( $consent_fields as $ci => $cf ) : ?>
								<div class="blt-consent-item">
									<label><?php esc_html_e( 'Key:', 'blt-events' ); ?> <input type="text" name="consent[<?php echo $ci; ?>][key]" value="<?php echo esc_attr( $cf['key'] ); ?>" /></label>
									<label><?php esc_html_e( 'Label (HTML):', 'blt-events' ); ?> <input type="text" name="consent[<?php echo $ci; ?>][label]" value="<?php echo esc_attr( $cf['label'] ); ?>" class="large-text" /></label>
									<label><input type="checkbox" name="consent[<?php echo $ci; ?>][required]" value="1" <?php checked( ! empty( $cf['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'blt-events' ); ?></label>
									<button type="button" class="button button-link-delete blt-remove-consent">&times;</button>
								</div>
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
				</div>
			</div>
		</div>
		<?php
	}

	public static function ajax_save_fieldset() {
		check_ajax_referer( 'blt_fieldset_nonce', 'nonce' );

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'blt-events' ) ) );
		}

		$id   = absint( $_POST['fieldset_id'] ?? 0 );
		$name = sanitize_text_field( $_POST['fieldset_name'] ?? '' );
		$slug = sanitize_title( $_POST['fieldset_slug'] ?? '' );
		$desc = sanitize_textarea_field( $_POST['fieldset_description'] ?? '' );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Fieldset name is required.', 'blt-events' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Failed to save fieldset.', 'blt-events' ) ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Fieldset saved successfully.', 'blt-events' ),
			'id'      => $id ? $id : $result,
		) );
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

		$result = BLT_Events_Fieldsets::delete_fieldset( $id );
		if ( $result === false ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete fieldset.', 'blt-events' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Fieldset deleted.', 'blt-events' ) ) );
	}
}
