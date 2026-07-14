<?php
/**
 * BLT Events - Event Meta Boxes
 *
 * Adds meta boxes to the event editor: date/time, event type & location,
 * tickets, registration configuration, additional options, and a
 * registration overview. Markup follows the card-based event editor
 * design (assets/css/event-editor.css + assets/js/event-editor.js).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Event_Metabox {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_event', array( __CLASS__, 'save_meta' ), 10, 2 );

		$boxes = array(
			'blt_event_details',
			'blt_event_type',
			'blt_event_tickets',
			'blt_event_registration_config',
			'blt_event_options',
			'blt_event_registrations_summary',
		);
		foreach ( $boxes as $box ) {
			add_filter( 'postbox_classes_event_' . $box, array( __CLASS__, 'add_postbox_class' ) );
		}
	}

	/**
	 * Tag the plugin's meta boxes so the event editor stylesheet can turn
	 * them into cards without touching other plugins' boxes.
	 */
	public static function add_postbox_class( $classes ) {
		$classes[] = 'blt-card';
		return $classes;
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
			'blt_event_type',
			__( 'Event Type', 'blt-events' ),
			array( __CLASS__, 'render_type_box' ),
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
			'blt_event_options',
			__( 'Additional Options', 'blt-events' ),
			array( __CLASS__, 'render_options_box' ),
			'event',
			'side',
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

	/**
	 * Render a toggle-switch row: title + description on the left, switch
	 * on the right.
	 *
	 * @param array $args {name, id, checked, title, desc, icon, class}
	 */
	private static function render_toggle_row( $args ) {
		$args = wp_parse_args( $args, array(
			'name'    => '',
			'id'      => '',
			'checked' => false,
			'title'   => '',
			'desc'    => '',
			'icon'    => '',
			'class'   => '',
		) );
		?>
		<div class="blt-toggle-row <?php echo esc_attr( $args['class'] ); ?>">
			<span class="blt-toggle-text">
				<?php if ( $args['icon'] ) : ?>
					<span class="dashicons <?php echo esc_attr( $args['icon'] ); ?>"></span>
				<?php endif; ?>
				<span>
					<span class="blt-toggle-title"><?php echo esc_html( $args['title'] ); ?></span>
					<?php if ( $args['desc'] ) : ?>
						<span class="blt-toggle-desc"><?php echo esc_html( $args['desc'] ); ?></span>
					<?php endif; ?>
				</span>
			</span>
			<span class="blt-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $args['name'] ); ?>" <?php echo $args['id'] ? 'id="' . esc_attr( $args['id'] ) . '"' : ''; ?> value="1" <?php checked( $args['checked'] ); ?> />
				<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
			</span>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------
	 * Event Details: dates, times, all day / no end time
	 * ---------------------------------------------------------------- */

	/**
	 * Small toggle switch rendered inline next to a field label.
	 */
	private static function render_inline_toggle( $name, $id, $checked, $label ) {
		?>
		<label class="blt-inline-toggle">
			<span><?php echo esc_html( $label ); ?></span>
			<span class="blt-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $checked ); ?> />
				<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
			</span>
		</label>
		<?php
	}

	public static function render_details_box( $post ) {
		wp_nonce_field( 'blt_event_details', 'blt_event_details_nonce' );
		$prefix = BLT_EVENTS_PREFIX;

		$event_date       = get_post_meta( $post->ID, $prefix . 'event_date', true );
		$event_start_time = get_post_meta( $post->ID, $prefix . 'event_start_time', true );
		$event_end_time   = get_post_meta( $post->ID, $prefix . 'event_end_time', true );
		$all_day          = get_post_meta( $post->ID, $prefix . 'event_all_day', true ) === '1';
		$no_end           = get_post_meta( $post->ID, $prefix . 'event_no_end_time', true ) === '1';
		$multi_day        = get_post_meta( $post->ID, $prefix . 'multi_day', true ) === '1';

		$days_raw = get_post_meta( $post->ID, $prefix . 'event_days', true );
		$days     = is_string( $days_raw ) ? json_decode( $days_raw, true ) : $days_raw;
		$days     = is_array( $days ) ? array_values( $days ) : array();

		// Seed the multi-day editor with something sensible when empty.
		if ( empty( $days ) ) {
			$days = array( array(
				'date'  => $event_date,
				'start' => $event_start_time,
				'end'   => $event_end_time,
			) );
		}
		?>
		<div class="blt-editor">
			<div class="blt-field blt-field-event-date">
				<div class="blt-label-row">
					<label class="blt-label" for="event_date"><?php esc_html_e( 'Event Date', 'blt-events' ); ?></label>
					<?php self::render_inline_toggle( 'event_multi_day', 'blt-multi-day', $multi_day, __( 'Multi-day Event', 'blt-events' ) ); ?>
				</div>
				<input type="date" class="blt-input" id="event_date" name="event_date" value="<?php echo esc_attr( $event_date ); ?>" <?php echo $multi_day ? 'style="display:none;"' : 'required'; ?> />
			</div>

			<div class="blt-grid-2 blt-single-day-times" <?php echo $multi_day ? 'style="display:none;"' : ''; ?>>
				<div class="blt-field blt-field-start-time">
					<div class="blt-label-row">
						<label class="blt-label" for="event_start_time"><?php esc_html_e( 'Start Time', 'blt-events' ); ?></label>
						<?php self::render_inline_toggle( 'event_all_day', 'blt-all-day', $all_day, __( 'All Day', 'blt-events' ) ); ?>
					</div>
					<input type="time" class="blt-input" id="event_start_time" name="event_start_time" value="<?php echo esc_attr( $event_start_time ); ?>" <?php echo $all_day ? 'style="display:none;"' : ''; ?> />
				</div>
				<div class="blt-field blt-field-end-time">
					<div class="blt-label-row">
						<label class="blt-label" for="event_end_time"><?php esc_html_e( 'End Time', 'blt-events' ); ?></label>
						<?php self::render_inline_toggle( 'event_no_end_time', 'blt-no-end-time', $no_end, __( 'No End Time', 'blt-events' ) ); ?>
					</div>
					<input type="time" class="blt-input" id="event_end_time" name="event_end_time" value="<?php echo esc_attr( $event_end_time ); ?>" <?php echo ( $no_end || $all_day ) ? 'style="display:none;"' : ''; ?> />
				</div>
			</div>

			<div class="blt-multi-day-panel" id="blt-multi-day-panel" <?php echo $multi_day ? '' : 'style="display:none;"'; ?>>
				<div class="blt-day-rows-header" aria-hidden="true">
					<span><?php esc_html_e( 'Date', 'blt-events' ); ?></span>
					<span><?php esc_html_e( 'Start Time', 'blt-events' ); ?></span>
					<span><?php esc_html_e( 'End Time', 'blt-events' ); ?></span>
					<span></span>
				</div>
				<div id="blt-day-rows">
					<?php foreach ( $days as $i => $day ) : ?>
						<div class="blt-day-row">
							<input type="date" class="blt-input" name="event_days[<?php echo (int) $i; ?>][date]" value="<?php echo esc_attr( $day['date'] ?? '' ); ?>" />
							<input type="time" class="blt-input" name="event_days[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr( $day['start'] ?? '' ); ?>" />
							<input type="time" class="blt-input" name="event_days[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr( $day['end'] ?? '' ); ?>" />
							<button type="button" class="blt-day-remove dashicons dashicons-trash" aria-label="<?php esc_attr_e( 'Remove day', 'blt-events' ); ?>"></button>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" class="blt-btn-dashed" id="blt-add-day">+ <?php esc_html_e( 'Add Day', 'blt-events' ); ?></button>
				<p class="blt-help"><?php esc_html_e( 'Each day gets its own start and end time. Leave a day\'s start time blank to make that day all-day. Days are sorted by date automatically on save.', 'blt-events' ); ?></p>
			</div>

			<div id="blt-details-notice" class="blt-notice" <?php echo ( ! $multi_day && ( $all_day || $no_end ) ) ? '' : 'style="display:none;"'; ?>>
				<span class="dashicons dashicons-info-outline"></span>
				<span class="blt-notice-text"><?php echo $all_day ? esc_html__( 'Time fields are hidden because this is an all-day event.', 'blt-events' ) : esc_html__( 'The end time is hidden because no end time is set.', 'blt-events' ); ?></span>
			</div>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------
	 * Event Type: online / in-person / hybrid + location & meeting
	 * ---------------------------------------------------------------- */

	public static function render_type_box( $post ) {
		$prefix = BLT_EVENTS_PREFIX;

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

		$types = array(
			'online'    => array( 'label' => __( 'Online', 'blt-events' ), 'desc' => __( 'Virtual / webinar', 'blt-events' ), 'icon' => 'dashicons-admin-site-alt3' ),
			'in-person' => array( 'label' => __( 'In-Person', 'blt-events' ), 'desc' => __( 'Physical venue', 'blt-events' ), 'icon' => 'dashicons-location' ),
			'hybrid'    => array( 'label' => __( 'Hybrid', 'blt-events' ), 'desc' => __( 'Online & in-person', 'blt-events' ), 'icon' => 'dashicons-admin-page' ),
		);

		$has_coords = is_numeric( $event_latitude ) && is_numeric( $event_longitude );
		?>
		<div class="blt-editor">
			<div class="blt-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Event Type', 'blt-events' ); ?>">
				<?php foreach ( $types as $value => $type ) : ?>
					<label class="blt-segment<?php echo $event_type === $value ? ' is-active' : ''; ?>">
						<input type="radio" name="event_type" value="<?php echo esc_attr( $value ); ?>" <?php checked( $event_type, $value ); ?> />
						<span class="dashicons <?php echo esc_attr( $type['icon'] ); ?>"></span>
						<span class="blt-segment-label"><?php echo esc_html( $type['label'] ); ?></span>
						<span class="blt-segment-desc"><?php echo esc_html( $type['desc'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="blt-panel blt-panel-online" <?php echo $is_online ? '' : 'style="display:none;"'; ?>>
				<div class="blt-panel-title">
					<span class="dashicons dashicons-video-alt3"></span>
					<span><?php esc_html_e( 'Online Meeting', 'blt-events' ); ?></span>
				</div>

				<div class="blt-field">
					<label class="blt-label" for="event_online_url"><?php esc_html_e( 'Registration / Webinar Link', 'blt-events' ); ?></label>
					<input type="url" class="blt-input" id="event_online_url" name="event_online_url" value="<?php echo esc_attr( $event_online_url ); ?>" placeholder="https://zoom.us/j/…" />
					<p class="blt-help"><?php esc_html_e( 'The Zoom, Teams, webinar, or registration link attendees use to join the event online. Auto-created rooms fill this in for you.', 'blt-events' ); ?></p>
				</div>

				<?php if ( empty( $connected_providers ) ) : ?>
					<p class="blt-help">
						<?php
						printf(
							/* translators: %s: settings page URL. */
							wp_kses( __( 'Connect Zoom, Microsoft Teams, GoTo, or ClickMeeting under <a href="%s">Settings</a> to auto-create a meeting room here.', 'blt-events' ), array( 'a' => array( 'href' => array() ) ) ),
							esc_url( admin_url( 'edit.php?post_type=event&page=blt-events-settings&tab=integrations' ) )
						);
						?>
					</p>
				<?php else : ?>
					<?php
					self::render_toggle_row( array(
						'name'    => 'meeting_auto_create',
						'id'      => 'blt-meeting-auto',
						'checked' => $meeting_auto,
						'title'   => __( 'Auto-create Room', 'blt-events' ),
						'desc'    => __( 'Automatically create an online meeting room for this event', 'blt-events' ),
						'class'   => 'blt-toggle-row-bordered',
					) );
					?>
					<div class="blt-meeting-options" <?php echo $is_online && $meeting_auto ? '' : 'style="display:none;"'; ?>>
						<div class="blt-grid-2">
							<div class="blt-field">
								<label class="blt-label" for="meeting_provider"><?php esc_html_e( 'Meeting Provider', 'blt-events' ); ?></label>
								<select class="blt-input" id="meeting_provider" name="meeting_provider">
									<?php foreach ( $connected_providers as $p ) : ?>
										<option value="<?php echo esc_attr( $p->slug() ); ?>" data-webinars="<?php echo $p->supports_webinars() ? '1' : '0'; ?>" <?php selected( $meeting_provider, $p->slug() ); ?>>
											<?php echo esc_html( $p->name() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="blt-field blt-meeting-type-row">
								<label class="blt-label" for="meeting_type"><?php esc_html_e( 'Room Type', 'blt-events' ); ?></label>
								<select class="blt-input" id="meeting_type" name="meeting_type">
									<option value="meeting" <?php selected( $meeting_type, 'meeting' ); ?>><?php esc_html_e( 'Meeting', 'blt-events' ); ?></option>
									<option value="webinar" <?php selected( $meeting_type, 'webinar' ); ?>><?php esc_html_e( 'Webinar', 'blt-events' ); ?></option>
								</select>
							</div>
						</div>

						<?php if ( $meeting_room && ! empty( $meeting_room['join_url'] ) ) : ?>
							<div class="blt-field">
								<span class="blt-label"><?php esc_html_e( 'Created Room', 'blt-events' ); ?></span>
								<a href="<?php echo esc_url( $meeting_room['join_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $meeting_room['join_url'] ); ?></a>
								<p><label><input type="checkbox" name="meeting_recreate" value="1" /> <?php esc_html_e( 'Recreate the room on save (generates a new room and link)', 'blt-events' ); ?></label></p>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="blt-panel blt-panel-venue" <?php echo $is_physical ? '' : 'style="display:none;"'; ?>>
				<div class="blt-panel-title">
					<span class="dashicons dashicons-location"></span>
					<span><?php esc_html_e( 'Venue & Location', 'blt-events' ); ?></span>
				</div>

				<div class="blt-field">
					<label class="blt-label" for="event_venue"><?php esc_html_e( 'Venue Name', 'blt-events' ); ?></label>
					<input type="text" class="blt-input" id="event_venue" name="event_venue" value="<?php echo esc_attr( $event_venue ); ?>" placeholder="<?php esc_attr_e( 'e.g. Grand Hotel Ballroom', 'blt-events' ); ?>" />
					<p class="blt-help"><?php esc_html_e( 'The name of the place where the event is held.', 'blt-events' ); ?></p>
				</div>

				<div class="blt-field">
					<label class="blt-label" for="event_location"><?php esc_html_e( 'Street Address', 'blt-events' ); ?></label>
					<div class="blt-address-autocomplete">
						<input type="text" class="blt-input" id="event_location" name="event_location" value="<?php echo esc_attr( $event_location ); ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-owns="blt-address-suggestions" placeholder="<?php esc_attr_e( 'Start typing an address…', 'blt-events' ); ?>" />
						<input type="hidden" id="event_latitude" name="event_latitude" value="<?php echo esc_attr( $event_latitude ); ?>" />
						<input type="hidden" id="event_longitude" name="event_longitude" value="<?php echo esc_attr( $event_longitude ); ?>" />
						<ul id="blt-address-suggestions" class="blt-address-suggestions" role="listbox" style="display:none;"></ul>
					</div>
					<p class="blt-help"><?php esc_html_e( 'Suggestions are provided by OpenStreetMap as you type. Picking one also stores the venue coordinates.', 'blt-events' ); ?></p>
				</div>

				<div id="blt-map-preview" class="blt-map-preview">
					<?php if ( $has_coords ) : ?>
						<iframe title="<?php esc_attr_e( 'Venue map preview', 'blt-events' ); ?>" src="<?php echo esc_url( self::map_embed_url( $event_latitude, $event_longitude ) ); ?>" loading="lazy"></iframe>
					<?php else : ?>
						<span class="blt-map-placeholder">
							<span class="dashicons dashicons-location"></span>
							<?php esc_html_e( 'Map preview after address is entered', 'blt-events' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * OpenStreetMap embed URL for a coordinate pair.
	 */
	private static function map_embed_url( $lat, $lon ) {
		$lat  = (float) $lat;
		$lon  = (float) $lon;
		$bbox = implode( ',', array( $lon - 0.005, $lat - 0.003, $lon + 0.005, $lat + 0.003 ) );

		return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode( $bbox ) . '&layer=mapnik&marker=' . rawurlencode( $lat . ',' . $lon );
	}

	/* ----------------------------------------------------------------
	 * Ticket Types
	 * ---------------------------------------------------------------- */

	public static function render_tickets_box( $post ) {
		$ticket_types = BLT_Events_Helpers::get_ticket_types( $post->ID );

		if ( empty( $ticket_types ) && get_post_status( $post ) === 'auto-draft' ) {
			$ticket_types = array( array( 'name' => __( 'General Admission', 'blt-events' ), 'price' => '0', 'description' => '' ) );
		}

		$roles = array();
		foreach ( wp_roles()->get_names() as $slug => $label ) {
			$roles[ $slug ] = translate_user_role( $label );
		}
		?>
		<div class="blt-editor" id="blt-ticket-types">
			<p class="blt-help blt-tickets-intro"><?php esc_html_e( 'Define ticket types for this event. Set price to 0 for free tickets. Sale dates control when each ticket is available for purchase.', 'blt-events' ); ?></p>

			<div id="blt-tickets-empty" class="blt-tickets-empty" <?php echo empty( $ticket_types ) ? '' : 'style="display:none;"'; ?>>
				<span class="dashicons dashicons-groups"></span>
				<p><?php esc_html_e( 'No ticket types added yet', 'blt-events' ); ?></p>
				<button type="button" class="button button-primary blt-add-ticket">+ <?php esc_html_e( 'Add Ticket Type', 'blt-events' ); ?></button>
			</div>

			<div id="blt-tickets-list" class="blt-tickets-list" data-next-index="<?php echo (int) count( $ticket_types ); ?>">
				<?php foreach ( array_values( $ticket_types ) as $i => $ticket ) : ?>
					<?php self::render_ticket_row( $i, $ticket, $roles ); ?>
				<?php endforeach; ?>
			</div>

			<p id="blt-add-ticket-wrap" <?php echo empty( $ticket_types ) ? 'style="display:none;"' : ''; ?>>
				<button type="button" class="blt-add-ticket blt-btn-dashed">+ <?php esc_html_e( 'Add Ticket Type', 'blt-events' ); ?></button>
			</p>

			<script type="text/html" id="tmpl-blt-ticket">
				<?php self::render_ticket_row( '__i__', array( 'name' => __( 'New Ticket', 'blt-events' ), 'price' => '0' ), $roles, true ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Render one ticket row (collapsible card). Also used as the JS
	 * template for new rows with $i = '__i__'.
	 */
	private static function render_ticket_row( $i, $ticket, $roles, $expanded = false ) {
		$name         = $ticket['name'] ?? '';
		$price        = $ticket['price'] ?? '0';
		$is_paid      = (float) $price > 0;
		$ticket_roles = isset( $ticket['roles'] ) && is_array( $ticket['roles'] ) ? $ticket['roles'] : array();
		$restricted   = ! empty( $ticket_roles );

		$config = BLT_Events_Helpers::get_currency_config();
		$symbol = $config['currencySymbol'] ?: '$';
		?>
		<div class="blt-ticket<?php echo $expanded ? ' is-expanded' : ''; ?>">
			<div class="blt-ticket-head">
				<span class="blt-ticket-badge <?php echo $is_paid ? 'is-paid' : 'is-free'; ?>">
					<span class="dashicons <?php echo $is_paid ? 'dashicons-money-alt' : 'dashicons-yes-alt'; ?>"></span>
					<span class="blt-ticket-badge-text"><?php echo $is_paid ? esc_html__( 'Paid', 'blt-events' ) : esc_html__( 'Free', 'blt-events' ); ?></span>
				</span>
				<input type="text" class="blt-ticket-name-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Ticket name', 'blt-events' ); ?>" required />
				<span class="blt-ticket-price-summary"><?php echo esc_html( $symbol . number_format( (float) $price, 2 ) ); ?></span>
				<span class="blt-ticket-lock dashicons dashicons-lock" title="<?php esc_attr_e( 'Restricted by role', 'blt-events' ); ?>" <?php echo $restricted ? '' : 'style="display:none;"'; ?>></span>
				<button type="button" class="blt-ticket-toggle" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Expand ticket settings', 'blt-events' ); ?>">
					<span class="dashicons <?php echo $expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'; ?>"></span>
				</button>
				<button type="button" class="blt-ticket-remove" aria-label="<?php esc_attr_e( 'Delete ticket', 'blt-events' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>

			<div class="blt-ticket-body">
				<div class="blt-grid-2">
					<div class="blt-field">
						<label class="blt-label"><?php esc_html_e( 'Price', 'blt-events' ); ?></label>
						<div class="blt-input-prefix">
							<span class="blt-input-prefix-symbol"><?php echo esc_html( $symbol ); ?></span>
							<input type="number" class="blt-input blt-ticket-price-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][price]" value="<?php echo esc_attr( $price ); ?>" step="0.01" min="0" placeholder="0.00" />
						</div>
						<p class="blt-help"><?php esc_html_e( 'Set to 0 for a free ticket', 'blt-events' ); ?></p>
					</div>
					<div class="blt-field">
						<label class="blt-label"><?php esc_html_e( 'Description', 'blt-events' ); ?></label>
						<input type="text" class="blt-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][description]" value="<?php echo esc_attr( $ticket['description'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Short description (optional)', 'blt-events' ); ?>" />
					</div>
					<div class="blt-field">
						<label class="blt-label"><?php esc_html_e( 'Sale Start Date', 'blt-events' ); ?></label>
						<input type="date" class="blt-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][sale_start_date]" value="<?php echo esc_attr( $ticket['sale_start_date'] ?? '' ); ?>" />
					</div>
					<div class="blt-field">
						<label class="blt-label"><?php esc_html_e( 'Sale Start Time', 'blt-events' ); ?></label>
						<input type="time" class="blt-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][sale_start_time]" value="<?php echo esc_attr( $ticket['sale_start_time'] ?? '' ); ?>" />
					</div>
					<div class="blt-field">
						<label class="blt-label"><?php esc_html_e( 'Sale End Date', 'blt-events' ); ?></label>
						<input type="date" class="blt-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][sale_end_date]" value="<?php echo esc_attr( $ticket['sale_end_date'] ?? '' ); ?>" />
					</div>
					<div class="blt-field">
						<label class="blt-label"><?php esc_html_e( 'Sale End Time', 'blt-events' ); ?></label>
						<input type="time" class="blt-input" name="ticket_types[<?php echo esc_attr( $i ); ?>][sale_end_time]" value="<?php echo esc_attr( $ticket['sale_end_time'] ?? '' ); ?>" />
					</div>
				</div>
				<p class="blt-help"><?php esc_html_e( 'Sale dates are optional. Leave blank to keep the ticket on sale whenever registration is open.', 'blt-events' ); ?></p>

				<div class="blt-ticket-restrict-section">
					<?php
					self::render_toggle_row( array(
						'name'    => 'ticket_types[' . $i . '][restrict]',
						'checked' => $restricted,
						'title'   => __( 'Restrict by Role', 'blt-events' ),
						'desc'    => __( 'Limit ticket visibility to specific user roles', 'blt-events' ),
						'class'   => 'blt-ticket-restrict',
					) );
					?>
					<div class="blt-ticket-roles" <?php echo $restricted ? '' : 'style="display:none;"'; ?>>
						<span class="blt-label"><?php esc_html_e( 'Allowed Roles', 'blt-events' ); ?></span>
						<div class="blt-role-chips">
							<?php foreach ( $roles as $slug => $label ) : ?>
								<label class="blt-role-chip">
									<input type="checkbox" name="ticket_types[<?php echo esc_attr( $i ); ?>][roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $ticket_roles, true ) ); ?> />
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------
	 * Registration Configuration
	 * ---------------------------------------------------------------- */

	public static function render_registration_config_box( $post ) {
		$prefix            = BLT_EVENTS_PREFIX;
		$capacity          = (int) get_post_meta( $post->ID, $prefix . 'capacity', true );
		$fieldset_id       = get_post_meta( $post->ID, $prefix . 'fieldset_id', true );
		$registration_open = get_post_meta( $post->ID, $prefix . 'registration_open', true ) === '1';
		$cutoff_date       = get_post_meta( $post->ID, $prefix . 'registration_cutoff_date', true );
		$cutoff_time       = get_post_meta( $post->ID, $prefix . 'registration_cutoff_time', true );
		$waitlist          = get_post_meta( $post->ID, $prefix . 'waitlist_enabled', true ) === '1';
		$require_approval  = get_post_meta( $post->ID, $prefix . 'require_approval', true ) === '1';
		$group_discount    = get_post_meta( $post->ID, $prefix . 'group_discount', true );

		$unlimited = $capacity === 0;

		$gd = is_string( $group_discount ) ? json_decode( $group_discount, true ) : $group_discount;
		if ( ! is_array( $gd ) ) {
			$gd = array( 'enabled' => false, 'min_attendees' => 5, 'type' => 'percentage', 'amount' => 10 );
		}

		$fieldsets = array();
		if ( class_exists( 'BLT_Events_Fieldsets' ) ) {
			$fieldsets = BLT_Events_Fieldsets::get_active_fieldsets();
		}
		?>
		<div class="blt-editor">
			<?php
			self::render_toggle_row( array(
				'name'    => 'registration_open',
				'id'      => 'blt-registration-open',
				'checked' => $registration_open,
				'title'   => __( 'Registration Open', 'blt-events' ),
				'desc'    => __( 'Allow attendees to register for this event', 'blt-events' ),
			) );
			?>

			<div id="blt-reg-config-fields" <?php echo $registration_open ? '' : 'style="display:none;"'; ?>>
				<div class="blt-config-section">
					<div class="blt-config-section-head">
						<span class="blt-label"><?php esc_html_e( 'Capacity', 'blt-events' ); ?></span>
						<label class="blt-inline-toggle">
							<span><?php esc_html_e( 'Unlimited', 'blt-events' ); ?></span>
							<span class="blt-toggle">
								<input type="checkbox" id="blt-capacity-unlimited" name="capacity_unlimited" value="1" <?php checked( $unlimited ); ?> />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							</span>
						</label>
					</div>
					<div id="blt-capacity-field" <?php echo $unlimited ? 'style="display:none;"' : ''; ?>>
						<input type="number" class="blt-input" id="capacity" name="capacity" value="<?php echo esc_attr( $unlimited ? '' : $capacity ); ?>" min="0" placeholder="<?php esc_attr_e( 'e.g. 100', 'blt-events' ); ?>" />
						<p class="blt-help"><?php esc_html_e( 'Maximum registrations allowed.', 'blt-events' ); ?></p>
					</div>
					<p class="blt-help" id="blt-capacity-unlimited-help" <?php echo $unlimited ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'This event has unlimited capacity. Toggle off to set a maximum.', 'blt-events' ); ?></p>
				</div>

				<div class="blt-config-section">
					<span class="blt-label"><?php esc_html_e( 'Registration Cutoff', 'blt-events' ); ?></span>
					<div class="blt-grid-2">
						<div class="blt-field">
							<label class="blt-label" for="registration_cutoff_date"><?php esc_html_e( 'Cutoff Date', 'blt-events' ); ?></label>
							<input type="date" class="blt-input" id="registration_cutoff_date" name="registration_cutoff_date" value="<?php echo esc_attr( $cutoff_date ); ?>" />
						</div>
						<div class="blt-field">
							<label class="blt-label" for="registration_cutoff_time"><?php esc_html_e( 'Cutoff Time', 'blt-events' ); ?></label>
							<input type="time" class="blt-input" id="registration_cutoff_time" name="registration_cutoff_time" value="<?php echo esc_attr( $cutoff_time ); ?>" />
						</div>
					</div>
					<p class="blt-help"><?php esc_html_e( 'Registration closes at this date and time. Leave blank for no automatic cutoff.', 'blt-events' ); ?></p>
				</div>

				<div class="blt-config-section">
					<?php
					self::render_toggle_row( array(
						'name'    => 'waitlist_enabled',
						'checked' => $waitlist,
						'title'   => __( 'Enable Waitlist', 'blt-events' ),
						'desc'    => __( 'Allow people to join a waitlist when capacity is full', 'blt-events' ),
					) );
					?>
				</div>

				<div class="blt-config-section">
					<?php
					self::render_toggle_row( array(
						'name'    => 'require_approval',
						'checked' => $require_approval,
						'title'   => __( 'Require Manual Approval', 'blt-events' ),
						'desc'    => __( 'Registrations require admin review before confirmation', 'blt-events' ),
					) );
					?>
				</div>

				<div class="blt-config-section">
					<div class="blt-field">
						<label class="blt-label" for="fieldset_id"><?php esc_html_e( 'Registration Fieldset', 'blt-events' ); ?></label>
						<select class="blt-input" id="fieldset_id" name="fieldset_id">
							<option value=""><?php esc_html_e( '— Default Fieldset —', 'blt-events' ); ?></option>
							<?php if ( is_array( $fieldsets ) ) : ?>
								<?php foreach ( $fieldsets as $fs ) : ?>
									<option value="<?php echo esc_attr( $fs->id ); ?>" <?php selected( $fieldset_id, $fs->id ); ?>>
										<?php echo esc_html( $fs->name ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="blt-help"><?php esc_html_e( 'The set of form fields attendees fill in when registering.', 'blt-events' ); ?></p>
					</div>
				</div>

				<div class="blt-config-section">
					<?php
					self::render_toggle_row( array(
						'name'    => 'group_discount_enabled',
						'id'      => 'blt-group-discount-enabled',
						'checked' => ! empty( $gd['enabled'] ),
						'title'   => __( 'Group Discount', 'blt-events' ),
						'desc'    => __( 'Discount registrations above a minimum group size', 'blt-events' ),
					) );
					?>
					<div class="blt-group-discount-settings" <?php echo empty( $gd['enabled'] ) ? 'style="display:none;"' : ''; ?>>
						<div class="blt-grid-3">
							<div class="blt-field">
								<label class="blt-label" for="group_discount_min"><?php esc_html_e( 'Min. Attendees', 'blt-events' ); ?></label>
								<input type="number" class="blt-input" id="group_discount_min" name="group_discount_min" value="<?php echo esc_attr( $gd['min_attendees'] ?? 5 ); ?>" min="2" />
							</div>
							<div class="blt-field">
								<label class="blt-label" for="group_discount_type"><?php esc_html_e( 'Type', 'blt-events' ); ?></label>
								<select class="blt-input" id="group_discount_type" name="group_discount_type">
									<option value="percentage" <?php selected( $gd['type'] ?? '', 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'blt-events' ); ?></option>
									<option value="flat" <?php selected( $gd['type'] ?? '', 'flat' ); ?>><?php esc_html_e( 'Fixed Amount', 'blt-events' ); ?></option>
								</select>
							</div>
							<div class="blt-field">
								<label class="blt-label" for="group_discount_amount"><?php esc_html_e( 'Amount', 'blt-events' ); ?></label>
								<input type="number" class="blt-input" id="group_discount_amount" name="group_discount_amount" value="<?php echo esc_attr( $gd['amount'] ?? 10 ); ?>" step="0.01" min="0" />
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------
	 * Additional Options (sidebar)
	 * ---------------------------------------------------------------- */

	public static function render_options_box( $post ) {
		$prefix   = BLT_EVENTS_PREFIX;
		$featured = get_post_meta( $post->ID, $prefix . 'featured', true ) === '1';
		$hidden   = get_post_meta( $post->ID, $prefix . 'hide_from_calendar', true ) === '1';
		?>
		<div class="blt-editor blt-editor-side">
			<?php
			self::render_toggle_row( array(
				'name'    => 'event_featured',
				'checked' => $featured,
				'title'   => __( 'Featured Event', 'blt-events' ),
				'desc'    => __( 'Highlight in featured listings', 'blt-events' ),
				'icon'    => $featured ? 'dashicons-star-filled' : 'dashicons-star-empty',
				'class'   => 'blt-featured-toggle',
			) );

			self::render_toggle_row( array(
				'name'    => 'event_hide_from_calendar',
				'checked' => $hidden,
				'title'   => __( 'Hide from Calendar', 'blt-events' ),
				'desc'    => __( 'Only accessible via direct link', 'blt-events' ),
				'icon'    => 'dashicons-hidden',
				'class'   => 'blt-toggle-row-bordered',
			) );
			?>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------
	 * Registrations Summary (sidebar)
	 * ---------------------------------------------------------------- */

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
		<div class="blt-editor blt-editor-side blt-registrations-summary">
			<div class="blt-stat-row"><span><?php esc_html_e( 'Total Registrations', 'blt-events' ); ?></span><strong><?php echo intval( $total ); ?></strong></div>
			<div class="blt-stat-row"><span><?php esc_html_e( 'Confirmed', 'blt-events' ); ?></span><strong><?php echo intval( $confirmed ); ?></strong></div>
			<div class="blt-stat-row"><span><?php esc_html_e( 'Pending', 'blt-events' ); ?></span><strong><?php echo intval( $pending ); ?></strong></div>
			<?php if ( $capacity > 0 ) : ?>
				<div class="blt-stat-row"><span><?php esc_html_e( 'Capacity', 'blt-events' ); ?></span><strong><?php echo intval( $confirmed ) . ' / ' . intval( $capacity ); ?></strong></div>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-registrations&event_id=' . $post->ID ) ); ?>" class="button">
					<?php esc_html_e( 'View All Registrations', 'blt-events' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------
	 * Save
	 * ---------------------------------------------------------------- */

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

		// Event details. Multi-day events store a per-day schedule and keep
		// the single date/time metas in sync (first day start, last day end)
		// so calendars, sorting, ICS, and emails keep working unchanged.
		$multi_day = isset( $_POST['event_multi_day'] );
		$days      = array();

		if ( $multi_day && ! empty( $_POST['event_days'] ) && is_array( $_POST['event_days'] ) ) {
			foreach ( $_POST['event_days'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$day_date = self::sanitize_date( $row['date'] ?? '' );
				if ( ! $day_date ) {
					continue;
				}
				$days[] = array(
					'date'  => $day_date,
					'start' => self::sanitize_time( $row['start'] ?? '' ),
					'end'   => self::sanitize_time( $row['end'] ?? '' ),
				);
			}

			usort( $days, function ( $a, $b ) {
				return strcmp( $a['date'] . ' ' . $a['start'], $b['date'] . ' ' . $b['start'] );
			} );
			$days = array_slice( $days, 0, 30 );
		}

		if ( $multi_day && ! empty( $days ) ) {
			$first = $days[0];
			$last  = $days[ count( $days ) - 1 ];

			update_post_meta( $post_id, $prefix . 'multi_day', '1' );
			update_post_meta( $post_id, $prefix . 'event_days', wp_json_encode( $days ) );
			update_post_meta( $post_id, $prefix . 'event_date', $first['date'] );
			update_post_meta( $post_id, $prefix . 'event_end_date', $last['date'] !== $first['date'] ? $last['date'] : '' );
			update_post_meta( $post_id, $prefix . 'event_start_time', $first['start'] );
			update_post_meta( $post_id, $prefix . 'event_end_time', $last['end'] );
			update_post_meta( $post_id, $prefix . 'event_all_day', $first['start'] === '' ? '1' : '0' );
			update_post_meta( $post_id, $prefix . 'event_no_end_time', '0' );
		} else {
			$event_date = self::sanitize_date( $_POST['event_date'] ?? '' );
			$all_day    = isset( $_POST['event_all_day'] );
			$no_end     = isset( $_POST['event_no_end_time'] );
			$start_time = $all_day ? '' : self::sanitize_time( $_POST['event_start_time'] ?? '' );
			$end_time   = ( $all_day || $no_end ) ? '' : self::sanitize_time( $_POST['event_end_time'] ?? '' );

			update_post_meta( $post_id, $prefix . 'multi_day', '0' );
			update_post_meta( $post_id, $prefix . 'event_days', '' );
			update_post_meta( $post_id, $prefix . 'event_date', $event_date );
			update_post_meta( $post_id, $prefix . 'event_end_date', '' );
			update_post_meta( $post_id, $prefix . 'event_start_time', $start_time );
			update_post_meta( $post_id, $prefix . 'event_end_time', $end_time );
			update_post_meta( $post_id, $prefix . 'event_all_day', $all_day ? '1' : '0' );
			update_post_meta( $post_id, $prefix . 'event_no_end_time', $no_end ? '1' : '0' );
		}

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
		$valid_roles  = array_keys( wp_roles()->get_names() );
		$ticket_types = array();
		if ( isset( $_POST['ticket_types'] ) && is_array( $_POST['ticket_types'] ) ) {
			foreach ( $_POST['ticket_types'] as $ticket ) {
				if ( empty( $ticket['name'] ) ) {
					continue;
				}

				$sale_start_date = self::sanitize_date( $ticket['sale_start_date'] ?? '' );
				$sale_start_time = $sale_start_date ? self::sanitize_time( $ticket['sale_start_time'] ?? '' ) : '';
				$sale_end_date   = self::sanitize_date( $ticket['sale_end_date'] ?? '' );
				$sale_end_time   = $sale_end_date ? self::sanitize_time( $ticket['sale_end_time'] ?? '' ) : '';

				// A sale window that ends before it starts is invalid; drop the end.
				if ( $sale_start_date && $sale_end_date ) {
					$window_start = $sale_start_date . ' ' . ( $sale_start_time ?: '00:00' );
					$window_end   = $sale_end_date . ' ' . ( $sale_end_time ?: '23:59' );
					if ( $window_end < $window_start ) {
						$sale_end_date = '';
						$sale_end_time = '';
					}
				}

				$roles = array();
				if ( ! empty( $ticket['restrict'] ) && ! empty( $ticket['roles'] ) && is_array( $ticket['roles'] ) ) {
					foreach ( $ticket['roles'] as $role ) {
						$role = sanitize_key( $role );
						if ( in_array( $role, $valid_roles, true ) ) {
							$roles[] = $role;
						}
					}
				}

				$ticket_types[] = array(
					'name'            => sanitize_text_field( wp_unslash( $ticket['name'] ) ),
					'price'           => floatval( $ticket['price'] ?? 0 ),
					'description'     => sanitize_text_field( wp_unslash( $ticket['description'] ?? '' ) ),
					'sale_start_date' => $sale_start_date,
					'sale_start_time' => $sale_start_time,
					'sale_end_date'   => $sale_end_date,
					'sale_end_time'   => $sale_end_time,
					'roles'           => $roles,
				);
			}
		}
		update_post_meta( $post_id, $prefix . 'ticket_types', wp_json_encode( $ticket_types ) );

		// Registration config
		update_post_meta( $post_id, $prefix . 'registration_open', isset( $_POST['registration_open'] ) ? '1' : '0' );

		$capacity = isset( $_POST['capacity_unlimited'] ) ? 0 : absint( $_POST['capacity'] ?? 0 );
		update_post_meta( $post_id, $prefix . 'capacity', $capacity );
		update_post_meta( $post_id, $prefix . 'fieldset_id', absint( $_POST['fieldset_id'] ?? 0 ) );

		$cutoff_date = self::sanitize_date( $_POST['registration_cutoff_date'] ?? '' );
		$cutoff_time = $cutoff_date ? self::sanitize_time( $_POST['registration_cutoff_time'] ?? '' ) : '';
		update_post_meta( $post_id, $prefix . 'registration_cutoff_date', $cutoff_date );
		update_post_meta( $post_id, $prefix . 'registration_cutoff_time', $cutoff_time );

		update_post_meta( $post_id, $prefix . 'waitlist_enabled', isset( $_POST['waitlist_enabled'] ) ? '1' : '0' );
		update_post_meta( $post_id, $prefix . 'require_approval', isset( $_POST['require_approval'] ) ? '1' : '0' );

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

		// Additional options
		update_post_meta( $post_id, $prefix . 'featured', isset( $_POST['event_featured'] ) ? '1' : '0' );
		update_post_meta( $post_id, $prefix . 'hide_from_calendar', isset( $_POST['event_hide_from_calendar'] ) ? '1' : '0' );

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

	/**
	 * Keep only well-formed Y-m-d values from date inputs.
	 */
	private static function sanitize_date( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Keep only well-formed H:i values from time inputs.
	 */
	private static function sanitize_time( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}
}
