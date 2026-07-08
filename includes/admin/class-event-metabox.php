<?php
/**
 * BLT Events - Event Meta Boxes
 *
 * Adds meta boxes to the event editor: date/time, tickets, capacity,
 * fieldset selection, group discounts, and registration overview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Event_Metabox {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_event', array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function add_meta_boxes() {
		add_meta_box(
			'blt_event_details',
			'Event Details',
			array( __CLASS__, 'render_details_box' ),
			'event',
			'normal',
			'high'
		);

		add_meta_box(
			'blt_event_tickets',
			'Ticket Types',
			array( __CLASS__, 'render_tickets_box' ),
			'event',
			'normal',
			'high'
		);

		add_meta_box(
			'blt_event_registration_config',
			'Registration Configuration',
			array( __CLASS__, 'render_registration_config_box' ),
			'event',
			'normal',
			'default'
		);

		add_meta_box(
			'blt_event_registrations_summary',
			'Registrations Summary',
			array( __CLASS__, 'render_registrations_summary_box' ),
			'event',
			'side',
			'default'
		);
	}

	public static function render_details_box( $post ) {
		wp_nonce_field( 'blt_event_details', 'blt_event_details_nonce' );
		$prefix = BLT_EVENTS_PREFIX;

		$event_date       = get_post_meta( $post->ID, $prefix . 'event_date', true );
		$event_end_date   = get_post_meta( $post->ID, $prefix . 'event_end_date', true );
		$event_start_time = get_post_meta( $post->ID, $prefix . 'event_start_time', true );
		$event_end_time   = get_post_meta( $post->ID, $prefix . 'event_end_time', true );
		$event_all_day    = get_post_meta( $post->ID, $prefix . 'event_all_day', true );
		$event_location   = get_post_meta( $post->ID, $prefix . 'event_location', true );
		$event_venue      = get_post_meta( $post->ID, $prefix . 'event_venue', true );
		$event_online_url = get_post_meta( $post->ID, $prefix . 'event_online_url', true );
		$event_type       = get_post_meta( $post->ID, $prefix . 'event_type', true ) ?: 'in-person';
		?>
		<table class="form-table blt-event-details">
			<tr>
				<th><label for="event_date">Start Date</label></th>
				<td><input type="date" id="event_date" name="event_date" value="<?php echo esc_attr( $event_date ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="event_end_date">End Date</label></th>
				<td>
					<input type="date" id="event_end_date" name="event_end_date" value="<?php echo esc_attr( $event_end_date ); ?>" />
					<p class="description">Leave blank for single-day events.</p>
				</td>
			</tr>
			<tr>
				<th><label>All Day?</label></th>
				<td><label><input type="checkbox" name="event_all_day" value="1" <?php checked( $event_all_day, '1' ); ?> /> This is an all-day event</label></td>
			</tr>
			<tr class="blt-time-row" <?php echo $event_all_day === '1' ? 'style="display:none;"' : ''; ?>>
				<th><label for="event_start_time">Start Time</label></th>
				<td><input type="time" id="event_start_time" name="event_start_time" value="<?php echo esc_attr( $event_start_time ); ?>" /></td>
			</tr>
			<tr class="blt-time-row" <?php echo $event_all_day === '1' ? 'style="display:none;"' : ''; ?>>
				<th><label for="event_end_time">End Time</label></th>
				<td><input type="time" id="event_end_time" name="event_end_time" value="<?php echo esc_attr( $event_end_time ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="event_type">Event Type</label></th>
				<td>
					<select id="event_type" name="event_type">
						<option value="in-person" <?php selected( $event_type, 'in-person' ); ?>>In-Person</option>
						<option value="online" <?php selected( $event_type, 'online' ); ?>>Online</option>
						<option value="hybrid" <?php selected( $event_type, 'hybrid' ); ?>>Hybrid</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="event_venue">Venue</label></th>
				<td><input type="text" id="event_venue" name="event_venue" value="<?php echo esc_attr( $event_venue ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="event_location">Location / Address</label></th>
				<td><textarea id="event_location" name="event_location" class="large-text" rows="2"><?php echo esc_textarea( $event_location ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="event_online_url">Online URL</label></th>
				<td><input type="url" id="event_online_url" name="event_online_url" value="<?php echo esc_attr( $event_online_url ); ?>" class="regular-text" placeholder="https://" /></td>
			</tr>
		</table>

		<script>
		jQuery(document).ready(function($){
			$('input[name="event_all_day"]').on('change', function() {
				$('.blt-time-row').toggle(!this.checked);
			});
		});
		</script>
		<?php
	}

	public static function render_tickets_box( $post ) {
		$prefix = BLT_EVENTS_PREFIX;
		$ticket_types_raw = get_post_meta( $post->ID, $prefix . 'ticket_types', true );
		$ticket_types = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		if ( ! is_array( $ticket_types ) ) {
			$ticket_types = array( array( 'name' => 'General Admission', 'price' => '0', 'description' => '' ) );
		}
		?>
		<div id="blt-ticket-types">
			<p class="description">Define the ticket types available for this event. Set price to 0 for free tickets.</p>
			<table class="widefat blt-tickets-table" id="blt-tickets-table">
				<thead>
					<tr>
						<th style="width:30%">Name</th>
						<th style="width:15%">Price</th>
						<th style="width:40%">Description</th>
						<th style="width:15%">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
					<tr class="blt-ticket-row">
						<td><input type="text" name="ticket_types[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $ticket['name'] ?? '' ); ?>" class="widefat" required /></td>
						<td><input type="number" name="ticket_types[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( $ticket['price'] ?? '0' ); ?>" step="0.01" min="0" class="widefat" /></td>
						<td><input type="text" name="ticket_types[<?php echo (int) $i; ?>][description]" value="<?php echo esc_attr( $ticket['description'] ?? '' ); ?>" class="widefat" /></td>
						<td><button type="button" class="button blt-remove-ticket">&times; Remove</button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button button-secondary" id="blt-add-ticket">+ Add Ticket Type</button></p>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var ticketIndex = <?php echo count( $ticket_types ); ?>;

			$('#blt-add-ticket').on('click', function() {
				var row = '<tr class="blt-ticket-row">' +
					'<td><input type="text" name="ticket_types[' + ticketIndex + '][name]" class="widefat" required /></td>' +
					'<td><input type="number" name="ticket_types[' + ticketIndex + '][price]" value="0" step="0.01" min="0" class="widefat" /></td>' +
					'<td><input type="text" name="ticket_types[' + ticketIndex + '][description]" class="widefat" /></td>' +
					'<td><button type="button" class="button blt-remove-ticket">&times; Remove</button></td>' +
					'</tr>';
				$('#blt-tickets-table tbody').append(row);
				ticketIndex++;
			});

			$(document).on('click', '.blt-remove-ticket', function() {
				if ($('.blt-ticket-row').length > 1) {
					$(this).closest('tr').remove();
				}
			});
		});
		</script>
		<?php
	}

	public static function render_registration_config_box( $post ) {
		$prefix = BLT_EVENTS_PREFIX;
		$capacity        = get_post_meta( $post->ID, $prefix . 'capacity', true );
		$fieldset_id     = get_post_meta( $post->ID, $prefix . 'fieldset_id', true );
		$registration_open = get_post_meta( $post->ID, $prefix . 'registration_open', true );
		$group_discount  = get_post_meta( $post->ID, $prefix . 'group_discount', true );

		$gd = is_string( $group_discount ) ? json_decode( $group_discount, true ) : $group_discount;
		if ( ! is_array( $gd ) ) {
			$gd = array( 'enabled' => false, 'min_attendees' => 5, 'type' => 'percentage', 'amount' => 10 );
		}

		$fieldsets = array();
		if ( class_exists( 'BLT_Events_Fieldsets' ) ) {
			$fieldsets = BLT_Events_Fieldsets::get_active_fieldsets();
		}
		?>
		<table class="form-table">
			<tr>
				<th><label>Registration</label></th>
				<td>
					<label><input type="checkbox" name="registration_open" value="1" <?php checked( $registration_open, '1' ); ?> /> Registration is open</label>
				</td>
			</tr>
			<tr>
				<th><label for="capacity">Capacity</label></th>
				<td>
					<input type="number" id="capacity" name="capacity" value="<?php echo esc_attr( $capacity ); ?>" min="0" class="small-text" />
					<p class="description">Maximum number of attendees. Leave at 0 for unlimited.</p>
				</td>
			</tr>
			<tr>
				<th><label for="fieldset_id">Registration Fieldset</label></th>
				<td>
					<select id="fieldset_id" name="fieldset_id">
						<option value="">— Default Fieldset —</option>
						<?php if ( is_array( $fieldsets ) ) : ?>
							<?php foreach ( $fieldsets as $fs ) : ?>
								<option value="<?php echo esc_attr( $fs->id ); ?>" <?php selected( $fieldset_id, $fs->id ); ?>>
									<?php echo esc_html( $fs->name ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label>Group Discount</label></th>
				<td>
					<label><input type="checkbox" name="group_discount_enabled" value="1" <?php checked( ! empty( $gd['enabled'] ) ); ?> /> Enable group discount</label>
					<div class="blt-group-discount-settings" <?php echo empty( $gd['enabled'] ) ? 'style="display:none;"' : ''; ?>>
						<br>
						<label>Min. Attendees: <input type="number" name="group_discount_min" value="<?php echo esc_attr( $gd['min_attendees'] ?? 5 ); ?>" min="2" class="small-text" /></label><br><br>
						<label>Type:
							<select name="group_discount_type">
								<option value="percentage" <?php selected( $gd['type'] ?? '', 'percentage' ); ?>>Percentage</option>
								<option value="flat" <?php selected( $gd['type'] ?? '', 'flat' ); ?>>Fixed Amount</option>
							</select>
						</label><br><br>
						<label>Amount: <input type="number" name="group_discount_amount" value="<?php echo esc_attr( $gd['amount'] ?? 10 ); ?>" step="0.01" min="0" class="small-text" /></label>
					</div>
				</td>
			</tr>
		</table>

		<script>
		jQuery(document).ready(function($) {
			$('input[name="group_discount_enabled"]').on('change', function() {
				$('.blt-group-discount-settings').toggle(this.checked);
			});
		});
		</script>
		<?php
	}

	public static function render_registrations_summary_box( $post ) {
		$reg_db = new BLT_Events_Registrations_DB();
		$total  = $reg_db->count( array( array( 'column' => 'event_id', 'value' => $post->ID ) ) );
		$confirmed = $reg_db->count( array(
			array( 'column' => 'event_id', 'value' => $post->ID ),
			array( 'column' => 'status', 'value' => 'confirmed' ),
		) );
		$pending = $reg_db->count( array(
			array( 'column' => 'event_id', 'value' => $post->ID ),
			array( 'column' => 'status', 'value' => 'pending' ),
		) );
		$capacity = (int) get_post_meta( $post->ID, BLT_EVENTS_PREFIX . 'capacity', true );
		?>
		<div class="blt-registrations-summary">
			<p><strong>Total Registrations:</strong> <?php echo intval( $total ); ?></p>
			<p><strong>Confirmed:</strong> <?php echo intval( $confirmed ); ?></p>
			<p><strong>Pending:</strong> <?php echo intval( $pending ); ?></p>
			<?php if ( $capacity > 0 ) : ?>
				<p><strong>Capacity:</strong> <?php echo intval( $confirmed ) . ' / ' . intval( $capacity ); ?></p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-registrations&event_id=' . $post->ID ) ); ?>" class="button">
					View All Registrations
				</a>
			</p>
		</div>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['blt_event_details_nonce'] ) || ! wp_verify_nonce( $_POST['blt_event_details_nonce'], 'blt_event_details' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$prefix = BLT_EVENTS_PREFIX;

		// Event details
		update_post_meta( $post_id, $prefix . 'event_date', sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) ) );
		update_post_meta( $post_id, $prefix . 'event_end_date', sanitize_text_field( $_POST['event_end_date'] ?? '' ) );
		update_post_meta( $post_id, $prefix . 'event_start_time', sanitize_text_field( $_POST['event_start_time'] ?? '' ) );
		update_post_meta( $post_id, $prefix . 'event_end_time', sanitize_text_field( $_POST['event_end_time'] ?? '' ) );
		update_post_meta( $post_id, $prefix . 'event_all_day', isset( $_POST['event_all_day'] ) ? '1' : '0' );
		update_post_meta( $post_id, $prefix . 'event_type', in_array( $_POST['event_type'] ?? '', array( 'in-person', 'online', 'hybrid' ), true ) ? $_POST['event_type'] : 'in-person' );
		update_post_meta( $post_id, $prefix . 'event_venue', sanitize_text_field( wp_unslash( $_POST['event_venue'] ?? '' ) ) );
		update_post_meta( $post_id, $prefix . 'event_location', sanitize_textarea_field( wp_unslash( $_POST['event_location'] ?? '' ) ) );
		update_post_meta( $post_id, $prefix . 'event_online_url', esc_url_raw( $_POST['event_online_url'] ?? '' ) );

		// Ticket types
		$ticket_types = array();
		if ( isset( $_POST['ticket_types'] ) && is_array( $_POST['ticket_types'] ) ) {
			foreach ( $_POST['ticket_types'] as $ticket ) {
				if ( ! empty( $ticket['name'] ) ) {
					$ticket_types[] = array(
						'name'        => sanitize_text_field( wp_unslash( $ticket['name'] ) ),
						'price'       => floatval( $ticket['price'] ?? 0 ),
						'description' => sanitize_text_field( wp_unslash( $ticket['description'] ?? '' ) ),
					);
				}
			}
		}
		update_post_meta( $post_id, $prefix . 'ticket_types', wp_json_encode( $ticket_types ) );

		// Registration config
		update_post_meta( $post_id, $prefix . 'registration_open', isset( $_POST['registration_open'] ) ? '1' : '0' );
		update_post_meta( $post_id, $prefix . 'capacity', absint( $_POST['capacity'] ?? 0 ) );
		update_post_meta( $post_id, $prefix . 'fieldset_id', absint( $_POST['fieldset_id'] ?? 0 ) );

		// Group discount
		$group_discount = array(
			'enabled'       => ! empty( $_POST['group_discount_enabled'] ),
			'min_attendees' => absint( $_POST['group_discount_min'] ?? 5 ),
			'type'          => in_array( $_POST['group_discount_type'] ?? '', array( 'percentage', 'flat' ), true ) ? $_POST['group_discount_type'] : 'percentage',
			'amount'        => floatval( $_POST['group_discount_amount'] ?? 10 ),
		);
		update_post_meta( $post_id, $prefix . 'group_discount', wp_json_encode( $group_discount ) );
	}
}
