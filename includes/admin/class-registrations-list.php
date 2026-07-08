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
		$statuses = array(
			'confirmed' => '#059669',
			'pending'   => '#d97706',
			'cancelled' => '#dc2626',
			'refunded'  => '#6b7280',
		);
		$color = $statuses[ $item->status ] ?? '#6b7280';
		return '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( ucfirst( $item->status ) ) . '</span>';
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Registrations', 'blt-events' ); ?></h1>

			<form method="get">
				<input type="hidden" name="post_type" value="event" />
				<input type="hidden" name="page" value="blt-registrations" />
				<?php $table->search_box( __( 'Search', 'blt-events' ), 'search_registration' ); ?>
				<?php $table->display(); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=blt_export_registrations&event_id=' . absint( $_GET['event_id'] ?? 0 ) . '&_wpnonce=' . wp_create_nonce( 'blt_export' ) ) ); ?>" class="button">
					<?php esc_html_e( 'Export CSV', 'blt-events' ); ?>
				</a>
			</p>
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
