<?php
/**
 * BLT Events - Setup status
 *
 * A short checklist of the things that have to be true before the plugin is
 * actually usable on a fresh site, each with the one action that fixes it.
 *
 * The point is that a new install never leaves someone guessing why nothing
 * shows up. The two failures that account for almost all of it — no page
 * holding the calendar shortcode, and plain permalinks breaking event URLs —
 * are both invisible from the events list, so they get checked here and,
 * where possible, fixed in one click.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Setup {

	const NOTICE_DISMISSED = 'blt_events_setup_dismissed';
	const ACTION_CREATE    = 'blt_events_create_events_page';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_CREATE, array( __CLASS__, 'handle_create_events_page' ) );
		add_action( 'wp_ajax_blt_events_dismiss_setup', array( __CLASS__, 'ajax_dismiss' ) );
	}

	/**
	 * Run the checklist.
	 *
	 * @return array List of checks: key, label, done, help, action_url, action_label.
	 */
	public static function get_checks() {
		$checks = array();

		// 1. Pretty permalinks. Event URLs are rewrite-based, so on a plain
		// permalink site every single event 404s.
		$checks[] = array(
			'key'          => 'permalinks',
			'label'        => __( 'Pretty permalinks are on', 'blt-events' ),
			'done'         => '' !== get_option( 'permalink_structure' ),
			'help'         => __( 'Event pages use rewrite rules. With "Plain" permalinks every event URL returns a 404.', 'blt-events' ),
			'action_url'   => admin_url( 'options-permalink.php' ),
			'action_label' => __( 'Open Permalinks', 'blt-events' ),
		);

		// 2. A page that actually lists the events.
		$page_id   = (int) get_option( 'blt_events_events_page_id', 0 );
		$has_page  = $page_id > 0 && 'publish' === get_post_status( $page_id );
		$checks[]  = array(
			'key'          => 'events_page',
			'label'        => __( 'An events page exists', 'blt-events' ),
			'done'         => $has_page,
			'help'         => __( 'A published page holding the Events Calendar block or the [blt_events_calendar] shortcode. Visitors need somewhere to browse events, and "back to events" links point here.', 'blt-events' ),
			'action_url'   => $has_page
				? get_edit_post_link( $page_id, 'raw' )
				: wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CREATE ), self::ACTION_CREATE ),
			'action_label' => $has_page ? __( 'Edit the page', 'blt-events' ) : __( 'Create it for me', 'blt-events' ),
		);

		// 3. A fieldset to collect registrations with.
		$fieldset = class_exists( 'BLT_Events_Fieldsets' ) ? BLT_Events_Fieldsets::get_event_fieldset( 0 ) : null;
		$checks[] = array(
			'key'          => 'fieldset',
			'label'        => __( 'A registration form is set up', 'blt-events' ),
			'done'         => (bool) $fieldset,
			'help'         => __( 'The set of fields attendees fill in. A default one is created on activation; without it, registrations are rejected.', 'blt-events' ),
			'action_url'   => admin_url( 'edit.php?post_type=event&page=blt-fieldsets' ),
			'action_label' => __( 'Manage fieldsets', 'blt-events' ),
		);

		// 4. At least one event to show.
		$event_count = (int) wp_count_posts( 'event' )->publish;
		$checks[]    = array(
			'key'          => 'event',
			'label'        => __( 'At least one published event', 'blt-events' ),
			'done'         => $event_count > 0,
			'help'         => __( 'The calendar renders empty until something is published.', 'blt-events' ),
			'action_url'   => admin_url( 'post-new.php?post_type=event' ),
			'action_label' => __( 'Add an event', 'blt-events' ),
		);

		// 5. A payment processor, but only once something is actually being
		// sold. Nagging a free-events site about Stripe is noise.
		if ( self::has_paid_tickets() ) {
			$available = class_exists( 'BLT_Events_Payment_Providers' )
				? BLT_Events_Payment_Providers::get_available()
				: array();

			$checks[] = array(
				'key'          => 'payments',
				'label'        => __( 'A payment processor is connected', 'blt-events' ),
				'done'         => ! empty( $available ),
				'help'         => __( 'You have events with paid tickets, but no processor is both switched on and configured, so those tickets cannot be bought.', 'blt-events' ),
				'action_url'   => BLT_Events_Admin_Settings::tab_url( 'payments' ),
				'action_label' => __( 'Set up payments', 'blt-events' ),
			);
		}

		/**
		 * Filter the setup checklist.
		 *
		 * @param array $checks The checks, in display order.
		 */
		return apply_filters( 'blt_events_setup_checks', $checks );
	}

	/**
	 * Whether any published event sells a ticket above zero.
	 */
	private static function has_paid_tickets() {
		$cached = get_transient( 'blt_events_has_paid_tickets' );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$event_ids = get_posts( array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_blt_ticket_types',
					'compare' => 'EXISTS',
				),
			),
		) );

		$paid = false;
		foreach ( $event_ids as $event_id ) {
			foreach ( BLT_Events_Helpers::get_ticket_types( $event_id ) as $ticket ) {
				if ( isset( $ticket['price'] ) && (float) $ticket['price'] > 0 ) {
					$paid = true;
					break 2;
				}
			}
		}

		set_transient( 'blt_events_has_paid_tickets', $paid ? '1' : '0', HOUR_IN_SECONDS );

		return $paid;
	}

	/**
	 * How many checks are outstanding.
	 */
	public static function outstanding() {
		$count = 0;

		foreach ( self::get_checks() as $check ) {
			if ( empty( $check['done'] ) ) {
				$count++;
			}
		}

		return $count;
	}

	public static function is_complete() {
		return 0 === self::outstanding();
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/**
	 * The checklist card, rendered at the top of Settings > General.
	 */
	public static function render_card() {
		$checks      = self::get_checks();
		$outstanding = 0;

		foreach ( $checks as $check ) {
			if ( empty( $check['done'] ) ) {
				$outstanding++;
			}
		}
		?>
		<div class="blt-card blt-setup-card <?php echo $outstanding ? 'has-outstanding' : 'is-complete'; ?>">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Setup', 'blt-events' ); ?></h2>
				<p>
					<?php
					if ( $outstanding ) {
						printf(
							esc_html(
								/* translators: %d: number of outstanding setup steps. */
								_n(
									'%d step left before the plugin is ready for visitors.',
									'%d steps left before the plugin is ready for visitors.',
									$outstanding,
									'blt-events'
								)
							),
							(int) $outstanding
						);
					} else {
						esc_html_e( 'Everything is in place. Events are live for visitors.', 'blt-events' );
					}
					?>
				</p>
			</div>
			<div class="blt-card-body">
				<ul class="blt-setup-list">
					<?php foreach ( $checks as $check ) : ?>
						<li class="blt-setup-item <?php echo ! empty( $check['done'] ) ? 'is-done' : 'is-todo'; ?>">
							<span class="blt-setup-mark" aria-hidden="true"></span>
							<span class="blt-setup-text">
								<span class="blt-setup-label"><?php echo esc_html( $check['label'] ); ?></span>
								<?php if ( empty( $check['done'] ) && ! empty( $check['help'] ) ) : ?>
									<span class="blt-setup-help"><?php echo esc_html( $check['help'] ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( ! empty( $check['action_url'] ) ) : ?>
								<a class="button <?php echo empty( $check['done'] ) ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $check['action_url'] ); ?>">
									<?php echo esc_html( $check['action_label'] ); ?>
								</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * A one-off pointer to the checklist, until it is done or dismissed.
	 */
	public static function render_notice() {
		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			return;
		}

		if ( get_option( self::NOTICE_DISMISSED ) || self::is_complete() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Only on the plugin's own screens: a setup notice on an unrelated
		// admin page is the kind of thing that gets a plugin deactivated.
		if ( ! $screen || ( 'event' !== ( $screen->post_type ?? '' ) && false === strpos( $screen->id, 'blt-' ) ) ) {
			return;
		}

		// Not on the General tab itself, where the card is already visible.
		if ( isset( $_GET['page'], $_GET['tab'] ) && 'blt-events-settings' === $_GET['page'] && 'general' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$outstanding = self::outstanding();
		?>
		<div class="notice notice-info is-dismissible blt-setup-notice" data-blt-setup-notice>
			<p>
				<strong><?php esc_html_e( 'BLT Events', 'blt-events' ); ?></strong> —
				<?php
				printf(
					esc_html(
						/* translators: %d: number of outstanding setup steps. */
						_n(
							'%d setup step is still outstanding.',
							'%d setup steps are still outstanding.',
							$outstanding,
							'blt-events'
						)
					),
					(int) $outstanding
				);
				?>
				<a href="<?php echo esc_url( BLT_Events_Admin_Settings::tab_url( 'general' ) ); ?>">
					<?php esc_html_e( 'Finish setting up', 'blt-events' ); ?>
				</a>
			</p>
			<?php wp_nonce_field( 'blt_events_dismiss_setup', 'blt_setup_nonce' ); ?>
		</div>
		<script>
		( function () {
			var notice = document.querySelector( '[data-blt-setup-notice]' );
			if ( ! notice ) { return; }

			notice.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }

				var field = notice.querySelector( '#blt_setup_nonce' );
				var body = new FormData();
				body.append( 'action', 'blt_events_dismiss_setup' );
				body.append( 'nonce', field ? field.value : '' );

				window.fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} );
			} );
		} )();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	/**
	 * Create a published page holding the calendar shortcode, and point the
	 * Events Page setting at it.
	 */
	public static function handle_create_events_page() {
		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'blt-events' ), 403 );
		}

		check_admin_referer( self::ACTION_CREATE );

		$existing = (int) get_option( 'blt_events_events_page_id', 0 );

		// Guard against a double submit creating a second page.
		if ( $existing > 0 && 'publish' === get_post_status( $existing ) ) {
			wp_safe_redirect( BLT_Events_Admin_Settings::tab_url( 'general' ) );
			exit;
		}

		// The block when the site edits pages with blocks (so it shows up
		// with its controls in the editor), the shortcode otherwise.
		$content = function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'page' )
			? '<!-- wp:blt-events/calendar {"view":"list","switcher":true} /-->'
			: '[blt_events_calendar view="list" switcher="yes"]';

		/**
		 * Filter the content of the auto-created Events page.
		 *
		 * @param string $content Post content.
		 */
		$content = apply_filters( 'blt_events_events_page_content', $content );

		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Events', 'blt-events' ),
			'post_content' => $content,
		), true );

		if ( is_wp_error( $page_id ) ) {
			wp_safe_redirect( add_query_arg( 'blt-setup', 'page-failed', BLT_Events_Admin_Settings::tab_url( 'general' ) ) );
			exit;
		}

		update_option( 'blt_events_events_page_id', (int) $page_id );

		wp_safe_redirect( add_query_arg( 'blt-setup', 'page-created', BLT_Events_Admin_Settings::tab_url( 'general' ) ) );
		exit;
	}

	public static function ajax_dismiss() {
		check_ajax_referer( 'blt_events_dismiss_setup', 'nonce' );

		if ( ! BLT_Events_Helpers::user_can_manage() ) {
			wp_send_json_error();
		}

		update_option( self::NOTICE_DISMISSED, '1' );
		wp_send_json_success();
	}

	/**
	 * Drop the paid-tickets cache when an event changes, so the payments
	 * check reflects reality rather than the last hour.
	 */
	public static function flush_cache() {
		delete_transient( 'blt_events_has_paid_tickets' );
	}
}
