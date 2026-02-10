<?php
/**
 * CMT Events - Registrations List Table
 *
 * Admin page displaying all event registrations using WP_List_Table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CMT_Events_Registrations_List_Table extends WP_List_Table {

	private $reg_db;
	private $att_db;

	public function __construct() {
		parent::__construct( array(
			'singular' => 'registration',
			'plural'   => 'registrations',
			'ajax'     => false,
		) );
		$this->reg_db = new CMT_Events_Registrations_DB();
		$this->att_db = new CMT_Events_Attendees_DB();
	}

	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'customer_name'   => 'Customer',
			'customer_email'  => 'Email',
			'event'           => 'Event',
			'attendee_count'  => 'Attendees',
			'amount_paid'     => 'Amount',
			'payment_provider' => 'Provider',
			'status'          => 'Status',
			'created_at'      => 'Date',
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
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$orderby      = sanitize_key( $_GET['orderby'] ?? 'created_at' );
		$order        = ( isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

		$where = array();
		if ( ! empty( $_GET['event_id'] ) ) {
			$where[] = array( 'column' => 'event_id', 'value' => absint( $_GET['event_id'] ) );
		}
		if ( ! empty( $_GET['status'] ) ) {
			$where[] = array( 'column' => 'status', 'value' => sanitize_text_field( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$where[] = array( 'column' => 'customer_email', 'value' => '%' . sanitize_text_field( $_GET['s'] ) . '%', 'compare' => 'LIKE' );
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
		return CMT_Events_Helpers::format_price( $item->amount_paid );
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
		return esc_html( date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at ) ) );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$events = get_posts( array( 'post_type' => 'event', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$current_event  = absint( $_GET['event_id'] ?? 0 );
		$current_status = sanitize_text_field( $_GET['status'] ?? '' );
		?>
		<div class="alignleft actions">
			<select name="event_id">
				<option value="">All Events</option>
				<?php foreach ( $events as $ev ) : ?>
					<option value="<?php echo esc_attr( $ev->ID ); ?>" <?php selected( $current_event, $ev->ID ); ?>>
						<?php echo esc_html( $ev->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value="">All Statuses</option>
				<?php foreach ( array( 'confirmed', 'pending', 'cancelled', 'refunded' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current_status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Filter', '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function get_bulk_actions() {
		return array(
			'confirm' => 'Confirm',
			'cancel'  => 'Cancel',
		);
	}
}

class CMT_Events_Registrations_List {

	public static function init() {
		add_action( 'wp_ajax_cmt_export_registrations', array( __CLASS__, 'ajax_export_csv' ) );
	}

	public static function render_page() {
		$table = new CMT_Events_Registrations_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1>Event Registrations</h1>

			<form method="get">
				<input type="hidden" name="post_type" value="event" />
				<input type="hidden" name="page" value="cmt-registrations" />
				<?php $table->search_box( 'Search', 'search_registration' ); ?>
				<?php $table->display(); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=cmt_export_registrations&event_id=' . absint( $_GET['event_id'] ?? 0 ) . '&_wpnonce=' . wp_create_nonce( 'cmt_export' ) ) ); ?>" class="button">
					Export CSV
				</a>
			</p>
		</div>
		<?php
	}

	public static function ajax_export_csv() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'cmt_export' ) ) {
			wp_die( 'Unauthorized' );
		}

		$reg_db   = new CMT_Events_Registrations_DB();
		$event_id = absint( $_GET['event_id'] ?? 0 );

		$where = array();
		if ( $event_id ) {
			$where[] = array( 'column' => 'event_id', 'value' => $event_id );
		}

		$registrations = $reg_db->get_all( array(
			'limit' => 10000,
			'where' => $where,
		) );

		$filename = 'registrations-' . ( $event_id ? $event_id . '-' : '' ) . date( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( 'ID', 'Event', 'Name', 'Email', 'Phone', 'Attendees', 'Total', 'Paid', 'Provider', 'Status', 'Date' ) );

		foreach ( $registrations as $reg ) {
			$event = get_post( $reg->event_id );
			fputcsv( $output, array(
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
			) );
		}

		fclose( $output );
		exit;
	}
}
