<?php
/**
 * BLT Events - Registrations List Table
 *
 * Admin page displaying all event registrations using WP_List_Table, with
 * an event dashboard, bulk status changes, and CSV exports of registrations
 * and attendees.
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
			'cb'               => '<input type="checkbox" />',
			'customer_name'    => __( 'Customer', 'blt-events' ),
			'customer_email'   => __( 'Email', 'blt-events' ),
			'event'            => __( 'Event', 'blt-events' ),
			'attendee_count'   => __( 'Attendees', 'blt-events' ),
			'amount_paid'      => __( 'Amount', 'blt-events' ),
			'payment_provider' => __( 'Provider', 'blt-events' ),
			'status'           => __( 'Status', 'blt-events' ),
			'created_at'       => __( 'Date', 'blt-events' ),
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

		$per_page     = (int) apply_filters( 'blt_events_registrations_per_page', 20 );
		$current_page = $this->get_pagenum();
		$orderby      = sanitize_key( $_GET['orderby'] ?? 'created_at' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order        = ( isset( $_GET['order'] ) && strtoupper( sanitize_key( $_GET['order'] ) ) === 'ASC' ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Only allow ordering by real, sortable columns.
		$sortable_keys = array_keys( $this->get_sortable_columns() );
		if ( ! in_array( $orderby, $sortable_keys, true ) ) {
			$orderby = 'created_at';
		}

		$where  = array();
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['event_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$where[] = array( 'column' => 'event_id', 'value' => absint( $_GET['event_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 'trash' === $status ) {
			$where[] = array( 'column' => 'status', 'value' => 'trash' );
		} elseif ( array_key_exists( $status, BLT_Events_Helpers::registration_statuses() ) ) {
			$where[] = array( 'column' => 'status', 'value' => $status );
		} else {
			// The default "All" view never shows trashed registrations.
			$where[] = array( 'column' => 'status', 'value' => 'trash', 'compare' => '!=' );
		}
		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$term    = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		$out = '<strong>' . esc_html( $item->customer_name ) . '</strong>';

		if ( $item->customer_phone ) {
			$out .= '<br /><span class="blt-text-muted">' . esc_html( $item->customer_phone ) . '</span>';
		}

		$out .= $this->row_actions( self::row_action_links( $item ) );

		return $out;
	}

	/**
	 * Row-hover action links: Trash normally, Restore / Delete Permanently
	 * once already trashed — mirrors WordPress's own post list.
	 */
	private static function row_action_links( $item ) {
		$base = remove_query_arg( array( 'action', 'action2', 'id', '_wpnonce' ) );

		if ( 'trash' === $item->status ) {
			return array(
				'restore' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'restore', 'id' => $item->id ), $base ), 'bulk-registrations' ) ),
					esc_html__( 'Restore', 'blt-events' )
				),
				'delete'  => sprintf(
					'<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'id' => $item->id ), $base ), 'bulk-registrations' ) ),
					esc_attr( wp_json_encode( __( 'This registration will be permanently deleted. This action cannot be undone.', 'blt-events' ) ) ),
					esc_html__( 'Delete Permanently', 'blt-events' )
				),
			);
		}

		return array(
			'trash' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'trash', 'id' => $item->id ), $base ), 'bulk-registrations' ) ),
				esc_html__( 'Trash', 'blt-events' )
			),
		);
	}

	public function column_event( $item ) {
		$event = get_post( $item->event_id );
		return $event ? '<a href="' . esc_url( (string) get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>' : '—';
	}

	public function column_amount_paid( $item ) {
		return esc_html( BLT_Events_Helpers::format_price( $item->amount_paid ) );
	}

	public function column_payment_provider( $item ) {
		$labels = array(
			'free' => __( 'Free', 'blt-events' ),
			'none' => __( 'N/A', 'blt-events' ),
		);
		$slug = (string) ( $item->payment_provider ?: 'none' );

		if ( isset( $labels[ $slug ] ) ) {
			return esc_html( $labels[ $slug ] );
		}

		return esc_html( BLT_Events_Payment_Providers::get_label( $slug ) );
	}

	public function column_status( $item ) {
		$known = BLT_Events_Helpers::registration_statuses();
		$slug  = 'trash' === $item->status || array_key_exists( $item->status, $known ) ? $item->status : 'refunded';

		$out = sprintf(
			'<span class="blt-badge blt-badge-%1$s">%2$s</span>',
			esc_attr( $slug ),
			esc_html( BLT_Events_Helpers::status_label( $item->status ) )
		);

		// A completed off-site payment that tripped one of the registration
		// guards is held as pending with its reasons attached. Surfacing them
		// here is the whole point of recording it instead of rejecting it.
		foreach ( self::get_review_reasons( $item ) as $reason ) {
			$out .= '<div class="blt-review-reason">' . esc_html( $reason ) . '</div>';
		}

		return $out;
	}

	/**
	 * Reasons a registration was flagged for review, if any.
	 *
	 * @param object $item Registration row.
	 * @return string[]
	 */
	private static function get_review_reasons( $item ) {
		if ( empty( $item->custom_fields ) ) {
			return array();
		}

		$fields = json_decode( $item->custom_fields, true );

		if ( ! is_array( $fields ) || empty( $fields['_review'] ) || ! is_array( $fields['_review'] ) ) {
			return array();
		}

		return array_map( 'strval', $fields['_review'] );
	}

	public function column_created_at( $item ) {
		$timestamp = strtotime( $item->created_at );
		if ( ! $timestamp ) {
			return '—';
		}
		return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
	}

	/**
	 * The "All | Pending | Confirmed | ... | Trash" subsubsub links above
	 * the table. Trash only appears once something is actually in it, same
	 * as WordPress's own post list.
	 */
	public function get_views() {
		$current  = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_id = absint( $_GET['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// One grouped query for every status count on this screen, rather
		// than a separate COUNT(*) per view.
		$counts      = $this->reg_db->count_by_status( $event_id );
		$trash_count = $counts['trash'] ?? 0;
		$all_count   = array_sum( $counts ) - $trash_count;

		$base_url = remove_query_arg( array( 'status', 'paged' ) );
		$views    = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			'' === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All', 'blt-events' ),
			$all_count
		);

		foreach ( BLT_Events_Helpers::registration_statuses() as $slug => $label ) {
			$count = $counts[ $slug ] ?? 0;
			if ( ! $count ) {
				continue;
			}
			$views[ $slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $slug, $base_url ) ),
				$current === $slug ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		}

		if ( $trash_count ) {
			$views['trash'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', 'trash', $base_url ) ),
				'trash' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'Trash', 'blt-events' ),
				$trash_count
			);
		}

		return $views;
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
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
		) );
		$current_event  = absint( $_GET['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
			<?php // Keeps the active All/status/Trash view when the event filter is (re)submitted. ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>" />
			<?php submit_button( __( 'Filter', 'blt-events' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function get_bulk_actions() {
		if ( 'trash' === sanitize_key( $_GET['status'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/**
			 * Filter the bulk actions shown while viewing the Trash.
			 *
			 * @param array $actions Action slug => label.
			 */
			return apply_filters( 'blt_events_registration_bulk_actions_trash', array(
				'restore' => __( 'Restore', 'blt-events' ),
				'delete'  => __( 'Delete Permanently', 'blt-events' ),
			) );
		}

		/**
		 * Filter the bulk actions on the Registrations screen. Keys are
		 * target statuses, plus 'trash'.
		 *
		 * @param array $actions Action slug => label.
		 */
		return apply_filters( 'blt_events_registration_bulk_actions', array(
			'confirmed' => __( 'Confirm', 'blt-events' ),
			'pending'   => __( 'Mark as pending', 'blt-events' ),
			'cancelled' => __( 'Cancel', 'blt-events' ),
			'trash'     => __( 'Move to Trash', 'blt-events' ),
		) );
	}

	private function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action || ! array_key_exists( $action, $this->get_bulk_actions() ) ) {
			return;
		}

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// A row action link (Trash / Restore / Delete Permanently) passes a
		// single 'id'; the bulk dropdown passes the checked 'registration_ids'.
		$ids = isset( $_REQUEST['id'] )
			? array( absint( $_REQUEST['id'] ) )
			: array_map( 'absint', (array) ( $_REQUEST['registration_ids'] ?? array() ) );

		$changed = 0;

		foreach ( array_filter( $ids ) as $id ) {
			switch ( $action ) {
				case 'trash':
					$ok = BLT_Events_Registrations::trash( $id );
					break;
				case 'restore':
					$ok = BLT_Events_Registrations::restore( $id );
					break;
				case 'delete':
					$ok = BLT_Events_Registrations::delete_permanently( $id );
					break;
				default:
					$ok = BLT_Events_Registrations::update_status( $id, $action );
					break;
			}
			if ( $ok ) {
				$changed++;
			}
		}

		if ( $changed ) {
			add_action( 'admin_notices', function () use ( $changed, $action ) {
				$descriptions = array(
					'trash'   => __( 'moved to Trash', 'blt-events' ),
					'restore' => __( 'restored', 'blt-events' ),
					'delete'  => __( 'permanently deleted', 'blt-events' ),
				);
				$description = $descriptions[ $action ] ?? sprintf(
					/* translators: %s: status label. */
					__( 'marked as %s', 'blt-events' ),
					BLT_Events_Helpers::status_label( $action )
				);
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( sprintf(
						/* translators: 1: number of registrations, 2: what happened to them. */
						_n( '%1$d registration %2$s.', '%1$d registrations %2$s.', $changed, 'blt-events' ),
						$changed,
						$description
					) )
				);
			} );
		}
	}
}

class BLT_Events_Registrations_List {

	public static function init() {
		add_action( 'wp_ajax_blt_export_registrations', array( __CLASS__, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_blt_export_attendees', array( __CLASS__, 'ajax_export_attendees_csv' ) );
	}

	public static function render_page() {
		$table = new BLT_Events_Registrations_List_Table();
		$table->prepare_items();

		$event_id = absint( $_GET['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event    = $event_id ? get_post( $event_id ) : null;
		if ( $event && $event->post_type !== 'event' ) {
			$event = null;
		}

		$export_url = add_query_arg( array(
			'action'   => 'blt_export_registrations',
			'event_id' => $event_id,
			'_wpnonce' => wp_create_nonce( 'blt_export' ),
		), admin_url( 'admin-ajax.php' ) );

		$export_attendees_url = add_query_arg( array(
			'action'   => 'blt_export_attendees',
			'event_id' => $event_id,
			'_wpnonce' => wp_create_nonce( 'blt_export' ),
		), admin_url( 'admin-ajax.php' ) );
		?>
		<div class="wrap blt-ui blt-ui-wide blt-events-registrations">
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
					<a href="<?php echo esc_url( $export_attendees_url ); ?>" class="button"><?php esc_html_e( 'Export Attendees CSV', 'blt-events' ); ?></a>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary"><?php esc_html_e( 'Export Registrations CSV', 'blt-events' ); ?></a>
				</div>
			</div>

			<?php
			if ( $event ) {
				self::render_event_dashboard( $event );
			}
			?>

			<div class="blt-card">
				<div class="blt-card-body">
					<?php // WP_List_Table::display() never calls this itself — the page template has to. .blt-card's own overflow:hidden contains the subsubsub list's native float. ?>
					<?php $table->views(); ?>
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

		$event_type = get_post_meta( $event->ID, '_blt_event_type', true ) ?: 'in-person';
		$capacity   = (int) get_post_meta( $event->ID, '_blt_capacity', true );
		$venue      = BLT_Events_Helpers::get_event_location_string( $event->ID );

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
								<?php echo esc_html( BLT_Events_Helpers::event_date_label( $event->ID ) ?: '—' ); ?>
								<?php $time_label = BLT_Events_Helpers::event_time_label( $event->ID ); ?>
								<?php if ( $time_label ) : ?>
									<span class="blt-text-muted"><?php echo esc_html( $time_label ); ?></span>
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
						foreach ( BLT_Events_Helpers::registration_statuses() as $status => $label ) :
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

	/* ------------------------------------------------------------------
	 * CSV exports
	 * ---------------------------------------------------------------- */

	private static function authorize_export() {
		if ( ! BLT_Events_Helpers::user_can_manage() || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'blt_export' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'blt-events' ) );
		}
	}

	private static function send_csv_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	}

	/**
	 * Registrations CSV. When filtered to one event, every field of that
	 * event's fieldset becomes a column; otherwise custom fields are
	 * exported as one JSON column.
	 */
	public static function ajax_export_csv() {
		self::authorize_export();

		$reg_db   = new BLT_Events_Registrations_DB();
		$event_id = absint( $_GET['event_id'] ?? 0 );

		// Trashed registrations are hidden from the admin's default list by
		// design; an export is a record of real registrations, so it follows
		// the same exclusion rather than leaking discarded rows into it.
		$where = array( array( 'column' => 'status', 'value' => 'trash', 'compare' => '!=' ) );
		if ( $event_id ) {
			$where[] = array( 'column' => 'event_id', 'value' => $event_id );
		}

		$registrations = $reg_db->get_all( array(
			'limit' => 100000,
			'where' => $where,
		) );

		// Custom-field columns: the event's fieldset when exporting one event.
		$custom_columns = array();
		if ( $event_id && class_exists( 'BLT_Events_Fieldsets' ) ) {
			$fieldset = BLT_Events_Fieldsets::get_event_fieldset( $event_id );
			foreach ( BLT_Events_Fieldsets::get_fields( $fieldset ) as $field ) {
				$type = BLT_Events_Fieldsets::field_type( $field['type'] );
				if ( ! empty( $type['stores_value'] ) && ! in_array( $field['key'], array( 'first_name', 'last_name', 'email', 'mobile_number' ), true ) ) {
					$custom_columns[ $field['key'] ] = $field['label'];
				}
			}
		}

		$columns = array(
			'id'             => __( 'ID', 'blt-events' ),
			'event'          => __( 'Event', 'blt-events' ),
			'name'           => __( 'Name', 'blt-events' ),
			'email'          => __( 'Email', 'blt-events' ),
			'phone'          => __( 'Phone', 'blt-events' ),
			'attendees'      => __( 'Attendees', 'blt-events' ),
			'total'          => __( 'Total', 'blt-events' ),
			'discount'       => __( 'Discount', 'blt-events' ),
			'paid'           => __( 'Paid', 'blt-events' ),
			'coupon'         => __( 'Coupon', 'blt-events' ),
			'provider'       => __( 'Provider', 'blt-events' ),
			'payment_id'     => __( 'Payment ID', 'blt-events' ),
			'status'         => __( 'Status', 'blt-events' ),
			'date'           => __( 'Date', 'blt-events' ),
		);
		foreach ( $custom_columns as $key => $label ) {
			$columns[ 'field:' . $key ] = $label;
		}
		$columns['consents'] = __( 'Consents', 'blt-events' );
		if ( ! $event_id ) {
			$columns['custom_fields'] = __( 'Custom Fields (JSON)', 'blt-events' );
		}

		/**
		 * Filter the registrations CSV columns.
		 *
		 * @param array $columns  Column key => header label.
		 * @param int   $event_id The event being exported, or 0 for all.
		 */
		$columns = apply_filters( 'blt_events_csv_columns', $columns, $event_id );

		self::send_csv_headers( 'registrations-' . ( $event_id ? $event_id . '-' : '' ) . wp_date( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array_values( $columns ) );

		// Prime post caches once so event titles don't trigger a query per row.
		$event_ids = array_unique( wp_list_pluck( $registrations, 'event_id' ) );
		if ( $event_ids ) {
			_prime_post_caches( $event_ids, false, false );
		}

		foreach ( $registrations as $reg ) {
			$event  = get_post( $reg->event_id );
			$custom = json_decode( (string) $reg->custom_fields, true );
			$custom = is_array( $custom ) ? $custom : array();
			$coupon = json_decode( (string) $reg->coupon_data, true );

			$consents = array();
			foreach ( (array) ( $custom['_consents'] ?? array() ) as $key => $given ) {
				if ( $given ) {
					$consents[] = $key;
				}
			}

			$row = array(
				'id'         => $reg->id,
				'event'      => $event ? $event->post_title : $reg->event_id,
				'name'       => $reg->customer_name,
				'email'      => $reg->customer_email,
				'phone'      => $reg->customer_phone,
				'attendees'  => $reg->attendee_count,
				'total'      => $reg->total_amount,
				'discount'   => $reg->discount_amount,
				'paid'       => $reg->amount_paid,
				'coupon'     => is_array( $coupon ) ? ( $coupon['code'] ?? '' ) : '',
				'provider'   => $reg->payment_provider,
				'payment_id' => $reg->payment_id,
				'status'     => $reg->status,
				'date'       => $reg->created_at,
				'consents'   => implode( ', ', $consents ),
			);

			foreach ( $custom_columns as $key => $label ) {
				$value                  = $custom[ $key ] ?? '';
				$row[ 'field:' . $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}

			if ( ! $event_id ) {
				$public = array_filter( $custom, function ( $key ) {
					return 0 !== strpos( (string) $key, '_' );
				}, ARRAY_FILTER_USE_KEY );
				$row['custom_fields'] = wp_json_encode( $public );
			}

			/**
			 * Filter one row of the registrations CSV.
			 *
			 * @param array  $row      Column key => value.
			 * @param object $reg      Registration row.
			 * @param int    $event_id The event being exported, or 0 for all.
			 */
			$row = apply_filters( 'blt_events_csv_row', $row, $reg, $event_id );

			$ordered = array();
			foreach ( array_keys( $columns ) as $key ) {
				$ordered[] = $row[ $key ] ?? '';
			}

			fputcsv( $output, array_map( array( __CLASS__, 'escape_csv_field' ), $ordered ) );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Attendees CSV: one row per seat, for check-in lists and badges.
	 */
	public static function ajax_export_attendees_csv() {
		self::authorize_export();

		global $wpdb;

		$event_id = absint( $_GET['event_id'] ?? 0 );
		$att_db   = new BLT_Events_Attendees_DB();
		$reg_tbl  = $wpdb->prefix . 'blt_registrations';

		$sql    = "SELECT a.*, r.customer_name, r.customer_email, r.status AS registration_status
				   FROM {$att_db->get_table_name()} a
				   INNER JOIN {$reg_tbl} r ON r.id = a.registration_id";
		$params = array();
		if ( $event_id ) {
			$sql     .= ' WHERE a.event_id = %d';
			$params[] = $event_id;
		}
		$sql .= ' ORDER BY a.event_id ASC, a.registration_id ASC, a.id ASC LIMIT 100000';

		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$columns = array(
			'registration_id' => __( 'Registration ID', 'blt-events' ),
			'event'           => __( 'Event', 'blt-events' ),
			'attendee_name'   => __( 'Attendee', 'blt-events' ),
			'attendee_email'  => __( 'Email', 'blt-events' ),
			'attendee_phone'  => __( 'Phone', 'blt-events' ),
			'ticket'          => __( 'Ticket', 'blt-events' ),
			'price'           => __( 'Price', 'blt-events' ),
			'booked_by'       => __( 'Booked by', 'blt-events' ),
			'booked_by_email' => __( 'Booker email', 'blt-events' ),
			'status'          => __( 'Registration status', 'blt-events' ),
			'checked_in'      => __( 'Checked in', 'blt-events' ),
			'check_in_time'   => __( 'Check-in time', 'blt-events' ),
		);

		/**
		 * Filter the attendees CSV columns.
		 *
		 * @param array $columns  Column key => header label.
		 * @param int   $event_id The event being exported, or 0 for all.
		 */
		$columns = apply_filters( 'blt_events_attendees_csv_columns', $columns, $event_id );

		self::send_csv_headers( 'attendees-' . ( $event_id ? $event_id . '-' : '' ) . wp_date( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array_values( $columns ) );

		$event_ids = array_unique( wp_list_pluck( $rows, 'event_id' ) );
		if ( $event_ids ) {
			_prime_post_caches( $event_ids, false, false );
		}

		foreach ( $rows as $att ) {
			$event = get_post( $att->event_id );

			$row = array(
				'registration_id' => $att->registration_id,
				'event'           => $event ? $event->post_title : $att->event_id,
				'attendee_name'   => $att->attendee_name,
				'attendee_email'  => $att->attendee_email,
				'attendee_phone'  => $att->attendee_phone,
				'ticket'          => $att->ticket_type,
				'price'           => $att->ticket_price,
				'booked_by'       => $att->customer_name,
				'booked_by_email' => $att->customer_email,
				'status'          => $att->registration_status,
				'checked_in'      => 'checked_in' === $att->check_in_status ? __( 'Yes', 'blt-events' ) : __( 'No', 'blt-events' ),
				'check_in_time'   => $att->check_in_time,
			);

			/**
			 * Filter one row of the attendees CSV.
			 *
			 * @param array  $row      Column key => value.
			 * @param object $att      Attendee row (joined with its registration).
			 * @param int    $event_id The event being exported, or 0 for all.
			 */
			$row = apply_filters( 'blt_events_attendees_csv_row', $row, $att, $event_id );

			$ordered = array();
			foreach ( array_keys( $columns ) as $key ) {
				$ordered[] = $row[ $key ] ?? '';
			}

			fputcsv( $output, array_map( array( __CLASS__, 'escape_csv_field' ), $ordered ) );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
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
