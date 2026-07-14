<?php
/**
 * BLT Events - Registrations List Table
 *
 * Admin page displaying all event registrations using WP_List_Table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BLT_Events_Registrations_List_Table extends WP_List_Table {

	private $reg_db;
	private $att_db;

	public function __construct() {
		parent::__construct( array(
			'singular' => 'registration',
			'plural'   => 'registrations',
			'ajax'     => false,
		) );
		$this->reg_db = new BLT_Events_Registrations_DB();
		$this->att_db = new BLT_Events_Attendees_DB();
	}

	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'customer_name'   => __( 'Customer', 'blt-events' ),
			'customer_email'  => __( 'Email', 'blt-events' ),
			'event'           => __( 'Event', 'blt-events' ),
			'attendee_count'  => __( 'Attendees', 'blt-events' ),
			'amount_paid'     => __( 'Amount', 'blt-events' ),
			'payment_provider' => __( 'Provider', 'blt-events' ),
			'status'          => __( 'Status', 'blt-events' ),
			'created_at'      => __( 'Date', 'blt-events' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'customer_name'  => array( 'customer_name', false ),
			'customer_email' => array( 'customer_email', false ),
			'amount_paid'    => array( 'amount_paid', false ),
			'status'         => array( 'status', false ),
			'created_at'     => array( 'created_at', true ),
		);
	}

	public function prepare_items() {
		global $wpdb;

		$this->process_bulk_action();

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$orderby      = sanitize_key( $_GET['orderby'] ?? 'created_at' );
		$order        = ( isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

		// Only allow ordering by real, sortable columns.
		$sortable_keys = array_keys( $this->get_sortable_columns() );
		if ( ! in_array( $orderby, $sortable_keys, true ) ) {
			$orderby = 'created_at';
		}

		$where = array();
		if ( ! empty( $_GET['event_id'] ) ) {
			$where[] = array( 'column' => 'event_id', 'value' => absint( $_GET['event_id'] ) );
		}
		if ( ! empty( $_GET['status'] ) ) {
			$where[] = array( 'column' => 'status', 'value' => sanitize_text_field( wp_unslash( $_GET['status'] ) ) );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$term    = sanitize_text_field( wp_unslash( $_GET['s'] ) );
			$where[] = array( 'column' => 'customer_email', 'value' => '%' . $wpdb->esc_like( $term ) . '%', 'compare' => 'LIKE' );
		}

		$total_items = $this->reg_db->count( $where );
		$this->items = $this->reg_db->get_all( array(
			'orderby' => $orderby,
			'order'   => $order,
			'limit'   => $per_page,
			'offset'  => ( $current_page - 1 ) * $per_page,
			'where'   => $where,
		) );

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '—';
	}

	public function column_cb( $item ) {
		return '<input type="checkbox" name="registration_ids[]" value="' . absint( $item->id ) . '" />';
	}

	public function column_customer_name( $item ) {
		return '<strong>' . esc_html( $item->customer_name ) . '</strong>';
	}

	public function column_event( $item ) {
		$event = get_post( $item->event_id );
		return $event ? '<a href="' . get_edit_post_link( $event->ID ) . '">' . esc_html( $event->post_title ) . '</a>' : '—';
	}

	public function column_amount_paid( $item ) {
		return BLT_Events_Helpers::format_price( $item->amount_paid );
	}

	public function column_payment_provider( $item ) {
		return esc_html( ucfirst( $item->payment_provider ?: 'N/A' ) );
	}

	public function column_status( $item ) {
		$known_statuses = array( 'confirmed', 'pending', 'cancelled', 'refunded' );
		$badge_status   = in_array( $item->status, $known_statuses, true ) ? $item->status : 'refunded';

		return sprintf(
			'<span class="blt-badge blt-badge-%1$s">%2$s</span>',
			esc_attr( $badge_status ),
			esc_html( ucfirst( $item->status ) )
		);
	}

	public function column_created_at( $item ) {
		$timestamp = strtotime( $item->created_at );
		if ( ! $timestamp ) {
			return '—';
		}
		return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$events = get_posts( array(
			'post_type'      => 'event',
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$current_event  = absint( $_GET['event_id'] ?? 0 );
		$current_status = sanitize_text_field( $_GET['status'] ?? '' );
		?>
		<div class="alignleft actions">
			<select name="event_id">
				<option value=""><?php esc_html_e( 'All Events', 'blt-events' ); ?></option>
				<?php foreach ( $events as $ev_id ) : ?>
					<option value="<?php echo esc_attr( $ev_id ); ?>" <?php selected( $current_event, $ev_id ); ?>>
						<?php echo esc_html( get_the_title( $ev_id ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'blt-events' ); ?></option>
				<?php foreach ( array( 'confirmed', 'pending', 'cancelled', 'refunded' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current_status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'blt-events' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function get_bulk_actions() {
		return array(
			'confirm' => __( 'Confirm', 'blt-events' ),
			'cancel'  => __( 'Cancel', 'blt-events' ),
		);
	}

	private function process_bulk_action() {
		$action = $this->current_action();
		if ( ! in_array( $action, array( 'confirm', 'cancel' ), true ) ) {
			return;
		}

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$ids = array_map( 'absint', (array) ( $_REQUEST['registration_ids'] ?? array() ) );
		$new_status = $action === 'confirm' ? 'confirmed' : 'cancelled';

		foreach ( array_filter( $ids ) as $id ) {
			BLT_Events_Registrations::update_status( $id, $new_status );
		}
	}
}

class BLT_Events_Registrations_List {

	public static function init() {
		add_action( 'wp_ajax_blt_export_registrations', array( __CLASS__, 'ajax_export_csv' ) );
	}

	public static function render_page() {
		$table = new BLT_Events_Registrations_List_Table();
		$table->prepare_items();

		$event_id = absint( $_GET['event_id'] ?? 0 );
		$event    = $event_id ? get_post( $event_id ) : null;
		if ( $event && $event->post_type !== 'event' ) {
			$event = null;
		}
		?>
		<div class="wrap blt-ui blt-events-registrations">
			<div class="blt-admin-page-header">
				<h1>
					<?php
					if ( $event ) {
						/* translators: %s: event title. */
						printf( esc_html__( 'Attendees for: %s', 'blt-events' ), esc_html( $event->post_title ) );
					} else {
						esc_html_e( 'Event Registrations', 'blt-events' );
					}
					?>
				</h1>
				<div class="blt-admin-page-actions">
					<?php if ( $event ) : ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=blt-registrations' ) ); ?>" class="button"><?php esc_html_e( 'All Registrations', 'blt-events' ); ?></a>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=blt_export_registrations&event_id=' . $event_id . '&_wpnonce=' . wp_create_nonce( 'blt_export' ) ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Export CSV', 'blt-events' ); ?>
					</a>
				</div>
			</div>

			<?php
			if ( $event ) {
				self::render_event_dashboard( $event );
			}
			?>

			<div class="blt-card">
				<div class="blt-card-body">
					<form method="get">
						<input type="hidden" name="post_type" value="event" />
						<input type="hidden" name="page" value="blt-registrations" />
						<?php $table->search_box( __( 'Search', 'blt-events' ), 'search_registration' ); ?>
						<?php $table->display(); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Dashboard card shown when the list is filtered to one event: basic
	 * event details, a ticket type breakdown with registration counts, and
	 * an attendance overview.
	 *
	 * @param WP_Post $event The event being viewed.
	 */
	private static function render_event_dashboard( $event ) {
		$reg_db = new BLT_Events_Registrations_DB();
		$att_db = new BLT_Events_Attendees_DB();

		$event_date  = get_post_meta( $event->ID, '_blt_event_date', true );
		$start_time  = get_post_meta( $event->ID, '_blt_event_start_time', true );
		$event_type  = get_post_meta( $event->ID, '_blt_event_type', true ) ?: 'in-person';
		$capacity    = (int) get_post_meta( $event->ID, '_blt_capacity', true );
		$venue       = BLT_Events_Helpers::get_event_location_string( $event->ID );
		$date_format = get_option( 'blt_events_date_format', 'F j, Y' );
		$time_format = get_option( 'time_format', 'g:i A' );

		$type_labels = array(
			'online'    => __( 'Online', 'blt-events' ),
			'in-person' => __( 'In-Person', 'blt-events' ),
			'hybrid'    => __( 'Hybrid', 'blt-events' ),
		);

		$ticket_types  = BLT_Events_Helpers::get_ticket_types( $event->ID );
		$ticket_counts = $att_db->count_by_ticket_type( $event->ID );
		$status_counts = $reg_db->count_by_status( $event->ID );

		$total_attendees = $att_db->count_for_event( $event->ID );
		$checked_in      = $att_db->count_checked_in( $event->ID );
		$checked_in_pct  = $total_attendees > 0 ? round( ( $checked_in / $total_attendees ) * 100 ) : 0;

		// Ticket rows: every configured ticket type (even with 0 sold), plus
		// any ticket names present in the data but no longer configured.
		$ticket_rows = array();
		foreach ( $ticket_types as $ticket ) {
			$name                 = $ticket['name'] ?? __( 'Ticket', 'blt-events' );
			$ticket_rows[ $name ] = array(
				'count' => $ticket_counts[ $name ] ?? 0,
				'price' => isset( $ticket['price'] ) ? (float) $ticket['price'] : null,
			);
			unset( $ticket_counts[ $name ] );
		}
		foreach ( $ticket_counts as $name => $count ) {
			$ticket_rows[ $name ] = array(
				'count' => $count,
				'price' => null,
			);
		}
		?>
		<div class="blt-card blt-event-dashboard">
			<div class="blt-card-body">
				<div class="blt-dashboard-grid">
					<div class="blt-dashboard-col">
						<h3><?php esc_html_e( 'Event Details', 'blt-events' ); ?></h3>
						<p class="blt-dash-row">
							<span class="blt-dash-label"><?php esc_html_e( 'Date', 'blt-events' ); ?></span>
							<span>
								<?php echo $event_date ? esc_html( date_i18n( $date_format, strtotime( $event_date ) ) ) : '&mdash;'; ?>
								<?php if ( $start_time && $event_date ) : ?>
									<span class="blt-text-muted"><?php echo esc_html( date_i18n( $time_format, strtotime( $event_date . ' ' . $start_time ) ) ); ?></span>
								<?php endif; ?>
							</span>
						</p>
						<p class="blt-dash-row">
							<span class="blt-dash-label"><?php esc_html_e( 'Type', 'blt-events' ); ?></span>
							<span class="blt-badge blt-badge-type-<?php echo esc_attr( $event_type ); ?>"><?php echo esc_html( $type_labels[ $event_type ] ?? $type_labels['in-person'] ); ?></span>
						</p>
						<?php if ( $venue ) : ?>
							<p class="blt-dash-row">
								<span class="blt-dash-label"><?php esc_html_e( 'Location', 'blt-events' ); ?></span>
								<span><?php echo esc_html( $venue ); ?></span>
							</p>
						<?php endif; ?>
						<p class="blt-dash-links">
							<a href="<?php echo esc_url( (string) get_edit_post_link( $event->ID ) ); ?>"><?php esc_html_e( 'Edit Event', 'blt-events' ); ?></a>
							<span aria-hidden="true">|</span>
							<a href="<?php echo esc_url( get_permalink( $event->ID ) ); ?>"><?php esc_html_e( 'View Event', 'blt-events' ); ?></a>
						</p>
					</div>

					<div class="blt-dashboard-col">
						<h3><?php esc_html_e( 'Ticket Overview', 'blt-events' ); ?></h3>
						<?php if ( empty( $ticket_rows ) ) : ?>
							<p class="blt-text-muted"><?php esc_html_e( 'No ticket types configured.', 'blt-events' ); ?></p>
						<?php else : ?>
							<?php foreach ( $ticket_rows as $name => $row ) : ?>
								<p class="blt-dash-row">
									<span>
										<?php echo esc_html( $name ); ?>
										<?php if ( $row['price'] !== null ) : ?>
											<span class="blt-text-muted"><?php echo $row['price'] > 0 ? esc_html( BLT_Events_Helpers::format_price( $row['price'] ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
										<?php endif; ?>
									</span>
									<strong><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></strong>
								</p>
							<?php endforeach; ?>
							<p class="blt-dash-row blt-dash-total">
								<span><?php esc_html_e( 'Total', 'blt-events' ); ?></span>
								<strong>
									<?php
									echo esc_html( number_format_i18n( $total_attendees ) );
									if ( $capacity > 0 ) {
										echo ' / ' . esc_html( number_format_i18n( $capacity ) );
									} else {
										echo ' ' . esc_html__( '(Unlimited)', 'blt-events' );
									}
									?>
								</strong>
							</p>
						<?php endif; ?>
					</div>

					<div class="blt-dashboard-col">
						<h3><?php esc_html_e( 'Attendance Overview', 'blt-events' ); ?></h3>
						<?php
						$status_labels = array(
							'confirmed' => __( 'Confirmed', 'blt-events' ),
							'pending'   => __( 'Pending', 'blt-events' ),
							'cancelled' => __( 'Cancelled', 'blt-events' ),
							'refunded'  => __( 'Refunded', 'blt-events' ),
						);
						foreach ( $status_labels as $status => $label ) :
							if ( empty( $status_counts[ $status ] ) ) {
								continue;
							}
							?>
							<p class="blt-dash-row">
								<span><?php echo esc_html( $label ); ?></span>
								<strong><?php echo esc_html( number_format_i18n( $status_counts[ $status ] ) ); ?></strong>
							</p>
						<?php endforeach; ?>
						<p class="blt-dash-row">
							<span><?php esc_html_e( 'Attendees', 'blt-events' ); ?></span>
							<strong><?php echo esc_html( number_format_i18n( $total_attendees ) ); ?></strong>
						</p>
						<p class="blt-dash-row blt-dash-total">
							<span><?php esc_html_e( 'Checked in', 'blt-events' ); ?></span>
							<strong><?php echo esc_html( sprintf( '%1$s (%2$s%%)', number_format_i18n( $checked_in ), number_format_i18n( $checked_in_pct ) ) ); ?></strong>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function ajax_export_csv() {
		if ( ! BLT_Events_Helpers::user_can_manage() || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'blt_export' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'blt-events' ) );
		}

		$reg_db   = new BLT_Events_Registrations_DB();
		$event_id = absint( $_GET['event_id'] ?? 0 );

		$where = array();
		if ( $event_id ) {
			$where[] = array( 'column' => 'event_id', 'value' => $event_id );
		}

		$registrations = $reg_db->get_all( array(
			'limit' => 10000,
			'where' => $where,
		) );

		$filename = 'registrations-' . ( $event_id ? $event_id . '-' : '' ) . wp_date( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( __( 'ID', 'blt-events' ), __( 'Event', 'blt-events' ), __( 'Name', 'blt-events' ), __( 'Email', 'blt-events' ), __( 'Phone', 'blt-events' ), __( 'Attendees', 'blt-events' ), __( 'Total', 'blt-events' ), __( 'Paid', 'blt-events' ), __( 'Provider', 'blt-events' ), __( 'Status', 'blt-events' ), __( 'Date', 'blt-events' ) ) );

		// Prime post caches once so event titles don't trigger a query per row.
		$event_ids = array_unique( wp_list_pluck( $registrations, 'event_id' ) );
		if ( $event_ids ) {
			_prime_post_caches( $event_ids, false, false );
		}

		foreach ( $registrations as $reg ) {
			$event = get_post( $reg->event_id );
			fputcsv( $output, array_map( array( __CLASS__, 'escape_csv_field' ), array(
				$reg->id,
				$event ? $event->post_title : $reg->event_id,
				$reg->customer_name,
				$reg->customer_email,
				$reg->customer_phone,
				$reg->attendee_count,
				$reg->total_amount,
				$reg->amount_paid,
				$reg->payment_provider,
				$reg->status,
				$reg->created_at,
			) ) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Neutralize spreadsheet formula injection: registrant-supplied values
	 * starting with =, +, -, @, tab, or CR would otherwise execute as
	 * formulas when the export is opened in Excel/LibreOffice.
	 */
	public static function escape_csv_field( $value ) {
		$value = (string) $value;

		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			$value = "'" . $value;
		}

		return $value;
	}
}
