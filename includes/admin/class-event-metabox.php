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
			__( 'Event Details', 'blt-events' ),
			array( __CLASS__, 'render_details_box' ),
			'event',
			'normal',
			'high'
		);

		add_meta_box(
			'blt_event_tickets',
			__( 'Ticket Types', 'blt-events' ),
			array( __CLASS__, 'render_tickets_box' ),
			'event',
			'normal',
			'high'
		);

		add_meta_box(
			'blt_event_registration_config',
			__( 'Registration Configuration', 'blt-events' ),
			array( __CLASS__, 'render_registration_config_box' ),
			'event',
			'normal',
			'default'
		);

		add_meta_box(
			'blt_event_registrations_summary',
			__( 'Registrations Summary', 'blt-events' ),
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
		$event_latitude   = get_post_meta( $post->ID, $prefix . 'event_latitude', true );
		$event_longitude  = get_post_meta( $post->ID, $prefix . 'event_longitude', true );
		$event_type       = get_post_meta( $post->ID, $prefix . 'event_type', true ) ?: 'in-person';

		$is_physical = in_array( $event_type, array( 'in-person', 'hybrid' ), true );
		$is_online   = in_array( $event_type, array( 'online', 'hybrid' ), true );

		$has_meetings        = class_exists( 'BLT_Events_Meeting_Providers' );
		$connected_providers = $has_meetings ? BLT_Events_Meeting_Providers::connected() : array();
		$meeting_auto        = get_post_meta( $post->ID, $prefix . 'meeting_auto', true ) === '1';
		$meeting_provider    = get_post_meta( $post->ID, $prefix . 'meeting_provider', true );
		$meeting_type        = get_post_meta( $post->ID, $prefix . 'meeting_type', true ) ?: 'meeting';
		$meeting_room        = $has_meetings ? BLT_Events_Meeting_Providers::get_room( $post->ID ) : null;
		?>
		<table class="form-table blt-event-details">
			<tr>
				<th><label for="event_date"><?php esc_html_e( 'Start Date', 'blt-events' ); ?></label></th>
				<td><input type="date" id="event_date" name="event_date" value="<?php echo esc_attr( $event_date ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="event_end_date"><?php esc_html_e( 'End Date', 'blt-events' ); ?></label></th>
				<td>
					<input type="date" id="event_end_date" name="event_end_date" value="<?php echo esc_attr( $event_end_date ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank for single-day events.', 'blt-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'All Day?', 'blt-events' ); ?></label></th>
				<td><label><input type="checkbox" name="event_all_day" value="1" <?php checked( $event_all_day, '1' ); ?> /> <?php esc_html_e( 'This is an all-day event', 'blt-events' ); ?></label></td>
			</tr>
			<tr class="blt-time-row" <?php echo $event_all_day === '1' ? 'style="display:none;"' : ''; ?>>
				<th><label for="event_start_time"><?php esc_html_e( 'Start Time', 'blt-events' ); ?></label></th>
				<td><input type="time" id="event_start_time" name="event_start_time" value="<?php echo esc_attr( $event_start_time ); ?>" /></td>
			</tr>
			<tr class="blt-time-row" <?php echo $event_all_day === '1' ? 'style="display:none;"' : ''; ?>>
				<th><label for="event_end_time"><?php esc_html_e( 'End Time', 'blt-events' ); ?></label></th>
				<td><input type="time" id="event_end_time" name="event_end_time" value="<?php echo esc_attr( $event_end_time ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="event_type"><?php esc_html_e( 'Event Type', 'blt-events' ); ?></label></th>
				<td>
					<select id="event_type" name="event_type">
						<option value="in-person" <?php selected( $event_type, 'in-person' ); ?>><?php esc_html_e( 'In-Person', 'blt-events' ); ?></option>
						<option value="online" <?php selected( $event_type, 'online' ); ?>><?php esc_html_e( 'Online', 'blt-events' ); ?></option>
						<option value="hybrid" <?php selected( $event_type, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'blt-events' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'In-person events get venue and address fields, online events get a registration/webinar link, and hybrid events get both.', 'blt-events' ); ?></p>
				</td>
			</tr>
			<tr class="blt-field-physical" <?php echo $is_physical ? '' : 'style="display:none;"'; ?>>
				<th><label for="event_venue"><?php esc_html_e( 'Venue', 'blt-events' ); ?></label></th>
				<td>
					<input type="text" id="event_venue" name="event_venue" value="<?php echo esc_attr( $event_venue ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Grand Hotel Ballroom', 'blt-events' ); ?>" />
					<p class="description"><?php esc_html_e( 'The name of the place where the event is held.', 'blt-events' ); ?></p>
				</td>
			</tr>
			<tr class="blt-field-physical" <?php echo $is_physical ? '' : 'style="display:none;"'; ?>>
				<th><label for="event_location"><?php esc_html_e( 'Address', 'blt-events' ); ?></label></th>
				<td>
					<div class="blt-address-autocomplete">
						<input type="text" id="event_location" name="event_location" value="<?php echo esc_attr( $event_location ); ?>" class="large-text" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-owns="blt-address-suggestions" placeholder="<?php esc_attr_e( 'Start typing an address…', 'blt-events' ); ?>" />
						<input type="hidden" id="event_latitude" name="event_latitude" value="<?php echo esc_attr( $event_latitude ); ?>" />
						<input type="hidden" id="event_longitude" name="event_longitude" value="<?php echo esc_attr( $event_longitude ); ?>" />
						<ul id="blt-address-suggestions" class="blt-address-suggestions" role="listbox" style="display:none;"></ul>
					</div>
					<p class="description"><?php esc_html_e( 'Suggestions are provided by OpenStreetMap as you type. Picking one also stores the venue coordinates.', 'blt-events' ); ?></p>
				</td>
			</tr>
			<tr class="blt-field-online" <?php echo $is_online ? '' : 'style="display:none;"'; ?>>
				<th><label for="event_online_url"><?php esc_html_e( 'Registration / Webinar Link', 'blt-events' ); ?></label></th>
				<td>
					<input type="url" id="event_online_url" name="event_online_url" value="<?php echo esc_attr( $event_online_url ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'The Zoom, Teams, webinar, or registration link attendees use to join the event online. Auto-created rooms fill this in for you.', 'blt-events' ); ?></p>
				</td>
			</tr>
			<?php if ( empty( $connected_providers ) ) : ?>
				<tr class="blt-field-online" <?php echo $is_online ? '' : 'style="display:none;"'; ?>>
					<th><?php esc_html_e( 'Meeting Room', 'blt-events' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: %s: settings page URL. */
								wp_kses( __( 'Connect Zoom, Microsoft Teams, GoTo, or ClickMeeting under <a href="%s">Settings</a> to auto-create a meeting room here.', 'blt-events' ), array( 'a' => array( 'href' => array() ) ) ),
								esc_url( admin_url( 'edit.php?post_type=event&page=blt-events-settings#integrations' ) )
							);
							?>
						</p>
					</td>
				</tr>
			<?php else : ?>
				<tr class="blt-field-online" <?php echo $is_online ? '' : 'style="display:none;"'; ?>>
					<th><?php esc_html_e( 'Auto-create Room', 'blt-events' ); ?></th>
					<td>
						<label><input type="checkbox" id="blt-meeting-auto" name="meeting_auto_create" value="1" <?php checked( $meeting_auto ); ?> /> <?php esc_html_e( 'Automatically create an online meeting room for this event', 'blt-events' ); ?></label>
					</td>
				</tr>
				<tr class="blt-field-online blt-meeting-options" <?php echo $is_online && $meeting_auto ? '' : 'style="display:none;"'; ?>>
					<th><label for="meeting_provider"><?php esc_html_e( 'Provider', 'blt-events' ); ?></label></th>
					<td>
						<select id="meeting_provider" name="meeting_provider">
							<?php foreach ( $connected_providers as $p ) : ?>
								<option value="<?php echo esc_attr( $p->slug() ); ?>" data-webinars="<?php echo $p->supports_webinars() ? '1' : '0'; ?>" <?php selected( $meeting_provider, $p->slug() ); ?>>
									<?php echo esc_html( $p->name() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="blt-field-online blt-meeting-options blt-meeting-type-row" <?php echo $is_online && $meeting_auto ? '' : 'style="display:none;"'; ?>>
					<th><label for="meeting_type"><?php esc_html_e( 'Room Type', 'blt-events' ); ?></label></th>
					<td>
						<select id="meeting_type" name="meeting_type">
							<option value="meeting" <?php selected( $meeting_type, 'meeting' ); ?>><?php esc_html_e( 'Meeting', 'blt-events' ); ?></option>
							<option value="webinar" <?php selected( $meeting_type, 'webinar' ); ?>><?php esc_html_e( 'Webinar', 'blt-events' ); ?></option>
						</select>
					</td>
				</tr>
				<?php if ( $meeting_room && ! empty( $meeting_room['join_url'] ) ) : ?>
					<tr class="blt-field-online blt-meeting-options" <?php echo $is_online && $meeting_auto ? '' : 'style="display:none;"'; ?>>
						<th><?php esc_html_e( 'Created Room', 'blt-events' ); ?></th>
						<td>
							<a href="<?php echo esc_url( $meeting_room['join_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $meeting_room['join_url'] ); ?></a>
							<p><label><input type="checkbox" name="meeting_recreate" value="1" /> <?php esc_html_e( 'Recreate the room on save (generates a new room and link)', 'blt-events' ); ?></label></p>
						</td>
					</tr>
				<?php endif; ?>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function render_tickets_box( $post ) {
		$prefix = BLT_EVENTS_PREFIX;
		$ticket_types_raw = get_post_meta( $post->ID, $prefix . 'ticket_types', true );
		$ticket_types = is_string( $ticket_types_raw ) ? json_decode( $ticket_types_raw, true ) : $ticket_types_raw;
		if ( ! is_array( $ticket_types ) ) {
			$ticket_types = array( array( 'name' => __( 'General Admission', 'blt-events' ), 'price' => '0', 'description' => '' ) );
		}
		?>
		<div id="blt-ticket-types">
			<p class="description"><?php esc_html_e( 'Define the ticket types available for this event. Set price to 0 for free tickets.', 'blt-events' ); ?></p>
			<table class="widefat blt-tickets-table" id="blt-tickets-table">
				<thead>
					<tr>
						<th style="width:30%"><?php esc_html_e( 'Name', 'blt-events' ); ?></th>
						<th style="width:15%"><?php esc_html_e( 'Price', 'blt-events' ); ?></th>
						<th style="width:40%"><?php esc_html_e( 'Description', 'blt-events' ); ?></th>
						<th style="width:15%"><?php esc_html_e( 'Actions', 'blt-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ticket_types as $i => $ticket ) : ?>
					<tr class="blt-ticket-row">
						<td><input type="text" name="ticket_types[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $ticket['name'] ?? '' ); ?>" class="widefat" required /></td>
						<td><input type="number" name="ticket_types[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( $ticket['price'] ?? '0' ); ?>" step="0.01" min="0" class="widefat" /></td>
						<td><input type="text" name="ticket_types[<?php echo (int) $i; ?>][description]" value="<?php echo esc_attr( $ticket['description'] ?? '' ); ?>" class="widefat" /></td>
						<td><button type="button" class="button blt-remove-ticket">&times; <?php esc_html_e( 'Remove', 'blt-events' ); ?></button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button button-secondary" id="blt-add-ticket">+ <?php esc_html_e( 'Add Ticket Type', 'blt-events' ); ?></button></p>
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
				<th><label><?php esc_html_e( 'Registration', 'blt-events' ); ?></label></th>
				<td>
					<label><input type="checkbox" name="registration_open" value="1" <?php checked( $registration_open, '1' ); ?> /> <?php esc_html_e( 'Registration is open', 'blt-events' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="capacity"><?php esc_html_e( 'Capacity', 'blt-events' ); ?></label></th>
				<td>
					<input type="number" id="capacity" name="capacity" value="<?php echo esc_attr( $capacity ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( 'Maximum number of attendees. Leave at 0 for unlimited.', 'blt-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="fieldset_id"><?php esc_html_e( 'Registration Fieldset', 'blt-events' ); ?></label></th>
				<td>
					<select id="fieldset_id" name="fieldset_id">
						<option value=""><?php esc_html_e( '— Default Fieldset —', 'blt-events' ); ?></option>
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
				<th><label><?php esc_html_e( 'Group Discount', 'blt-events' ); ?></label></th>
				<td>
					<label><input type="checkbox" name="group_discount_enabled" value="1" <?php checked( ! empty( $gd['enabled'] ) ); ?> /> <?php esc_html_e( 'Enable group discount', 'blt-events' ); ?></label>
					<div class="blt-group-discount-settings" <?php echo empty( $gd['enabled'] ) ? 'style="display:none;"' : ''; ?>>
						<br>
						<label><?php esc_html_e( 'Min. Attendees:', 'blt-events' ); ?> <input type="number" name="group_discount_min" value="<?php echo esc_attr( $gd['min_attendees'] ?? 5 ); ?>" min="2" class="small-text" /></label><br><br>
						<label><?php esc_html_e( 'Type:', 'blt-events' ); ?>
							<select name="group_discount_type">
								<option value="percentage" <?php selected( $gd['type'] ?? '', 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'blt-events' ); ?></option>
								<option value="flat" <?php selected( $gd['type'] ?? '', 'flat' ); ?>><?php esc_html_e( 'Fixed Amount', 'blt-events' ); ?></option>
							</select>
						</label><br><br>
						<label><?php esc_html_e( 'Amount:', 'blt-events' ); ?> <input type="number" name="group_discount_amount" value="<?php echo esc_attr( $gd['amount'] ?? 10 ); ?>" step="0.01" min="0" class="small-text" /></label>
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
			<p><strong><?php esc_html_e( 'Total Registrations:', 'blt-events' ); ?></strong> <?php echo intval( $total ); ?></p>
			<p><strong><?php esc_html_e( 'Confirmed:', 'blt-events' ); ?></strong> <?php echo intval( $confirmed ); ?></p>
			<p><strong><?php esc_html_e( 'Pending:', 'blt-events' ); ?></strong> <?php echo intval( $pending ); ?></p>
			<?php if ( $capacity > 0 ) : ?>
				<p><strong><?php esc_html_e( 'Capacity:', 'blt-events' ); ?></strong> <?php echo intval( $confirmed ) . ' / ' . intval( $capacity ); ?></p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-registrations&event_id=' . $post->ID ) ); ?>" class="button">
					<?php esc_html_e( 'View All Registrations', 'blt-events' ); ?>
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
		$event_date = sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) );
		$event_end_date = sanitize_text_field( $_POST['event_end_date'] ?? '' );
		if ( $event_date && $event_end_date && $event_end_date < $event_date ) {
			// An end date before the start date is invalid; treat as single-day.
			$event_end_date = '';
		}

		$all_day    = isset( $_POST['event_all_day'] );
		$start_time = $all_day ? '' : sanitize_text_field( $_POST['event_start_time'] ?? '' );
		$end_time   = $all_day ? '' : sanitize_text_field( $_POST['event_end_time'] ?? '' );

		update_post_meta( $post_id, $prefix . 'event_date', $event_date );
		update_post_meta( $post_id, $prefix . 'event_end_date', $event_end_date );
		update_post_meta( $post_id, $prefix . 'event_start_time', $start_time );
		update_post_meta( $post_id, $prefix . 'event_end_time', $end_time );
		update_post_meta( $post_id, $prefix . 'event_all_day', $all_day ? '1' : '0' );

		$event_type = in_array( $_POST['event_type'] ?? '', array( 'in-person', 'online', 'hybrid' ), true ) ? $_POST['event_type'] : 'in-person';
		update_post_meta( $post_id, $prefix . 'event_type', $event_type );

		// Location fields only apply to the selected event type: physical
		// events keep venue/address/coordinates, online events keep the
		// registration/webinar link, hybrid events keep both.
		$is_physical = in_array( $event_type, array( 'in-person', 'hybrid' ), true );
		$is_online   = in_array( $event_type, array( 'online', 'hybrid' ), true );

		$venue      = $is_physical ? sanitize_text_field( wp_unslash( $_POST['event_venue'] ?? '' ) ) : '';
		$location   = $is_physical ? sanitize_text_field( wp_unslash( $_POST['event_location'] ?? '' ) ) : '';
		$online_url = $is_online ? esc_url_raw( $_POST['event_online_url'] ?? '' ) : '';

		$latitude  = $is_physical && $location !== '' ? trim( wp_unslash( $_POST['event_latitude'] ?? '' ) ) : '';
		$longitude = $is_physical && $location !== '' ? trim( wp_unslash( $_POST['event_longitude'] ?? '' ) ) : '';
		if ( ! is_numeric( $latitude ) || ! is_numeric( $longitude ) || abs( (float) $latitude ) > 90 || abs( (float) $longitude ) > 180 ) {
			$latitude  = '';
			$longitude = '';
		}

		update_post_meta( $post_id, $prefix . 'event_venue', $venue );
		update_post_meta( $post_id, $prefix . 'event_location', $location );
		update_post_meta( $post_id, $prefix . 'event_online_url', $online_url );
		update_post_meta( $post_id, $prefix . 'event_latitude', $latitude );
		update_post_meta( $post_id, $prefix . 'event_longitude', $longitude );

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
		$gd_type   = in_array( $_POST['group_discount_type'] ?? '', array( 'percentage', 'flat' ), true ) ? $_POST['group_discount_type'] : 'percentage';
		$gd_amount = floatval( $_POST['group_discount_amount'] ?? 10 );
		if ( 'percentage' === $gd_type ) {
			$gd_amount = min( $gd_amount, 100 );
		}
		$group_discount = array(
			'enabled'       => ! empty( $_POST['group_discount_enabled'] ),
			'min_attendees' => max( 2, absint( $_POST['group_discount_min'] ?? 5 ) ),
			'type'          => $gd_type,
			'amount'        => $gd_amount,
		);
		update_post_meta( $post_id, $prefix . 'group_discount', wp_json_encode( $group_discount ) );

		// Auto-create an online meeting room (Zoom/Teams/GoTo/ClickMeeting) and
		// fill the join link in. Runs after all date/time/location meta is saved
		// so the room reflects the event's current schedule.
		if ( class_exists( 'BLT_Events_Meeting_Providers' ) ) {
			$join_url = BLT_Events_Meeting_Providers::maybe_create_room_on_save( $post_id, wp_unslash( $_POST ), $event_type );
			if ( $join_url ) {
				update_post_meta( $post_id, $prefix . 'event_online_url', esc_url_raw( $join_url ) );
			}
		}
	}
}
