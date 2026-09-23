<?php
/**
 * BLT Events - Import events from supported event plugins.
 *
 * Source APIs are intentionally optional. The importer reads documented post
 * types and meta directly so an unavailable (or subsequently removed) source
 * plugin can never cause a fatal error.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Event_Migration {

	const ACTION         = 'blt_events_migrate';
	const NONCE_ACTION   = 'blt_events_migrate_events';
	const SOURCE_META    = '_blt_migration_source';
	const SOURCE_ID_META = '_blt_migration_source_id';
	const ORGANIZER_META = '_blt_migration_organizer';
	const RESULTS_PREFIX = 'blt_events_migration_';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Supported sources and their active post type.
	 *
	 * Detection deliberately happens from registered post types rather than
	 * source classes or functions: it is reliable across free/pro editions and
	 * keeps this plugin from loading a source plugin's PHP files itself.
	 *
	 * @return array<string,array<string,string|bool|int>>
	 */
	public static function sources() {
		$sources = array(
			'mec'     => array(
				'label'        => __( 'Modern Events Calendar', 'blt-events' ),
				'post_type'    => 'mec-events',
				'plugin_names' => array( 'Modern Events Calendar', 'MEC' ),
			),
			'tec'     => array(
				'label'        => __( 'The Events Calendar', 'blt-events' ),
				'post_type'    => 'tribe_events',
				'plugin_names' => array( 'The Events Calendar' ),
			),
			'eventin' => array(
				'label'        => __( 'Eventin', 'blt-events' ),
				'post_type'    => 'etn',
				'plugin_names' => array( 'Eventin' ),
			),
		);

		foreach ( $sources as $slug => $source ) {
			$sources[ $slug ]['detected']  = post_type_exists( $source['post_type'] );
			$sources[ $slug ]['installed'] = self::plugin_installed( $source['plugin_names'] );
			$sources[ $slug ]['count']     = self::source_count( $source['post_type'] );
		}

		/**
		 * Filters sources shown by the migration page.
		 *
		 * Source add-ons can provide an adapter by filtering the source list and
		 * handling blt_events_migration_normalize_event.
		 *
		 * @param array $sources Source definitions keyed by slug.
		 */
		return apply_filters( 'blt_events_migration_sources', $sources );
	}

	private static function plugin_installed( $names ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			return false;
		}
		foreach ( get_plugins() as $plugin ) {
			$name = $plugin['Name'] ?? '';
			foreach ( $names as $expected ) {
				if ( false !== stripos( $name, $expected ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function source_count( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return 0;
		}

		$counts = wp_count_posts( $post_type );
		if ( ! $counts || ! is_object( $counts ) ) {
			return 0;
		}

		$total = 0;
		foreach ( get_object_vars( $counts ) as $count ) {
			$total += (int) $count;
		}
		return $total;
	}

	public static function render_page() {
		if ( ! current_user_can( BLT_Events_Helpers::menu_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate events.', 'blt-events' ) );
		}

		$sources  = self::sources();
		$detected = array_filter(
			$sources,
			function ( $source ) {
				return ! empty( $source['detected'] );
			}
		);
		$result   = self::get_result();
		?>
		<div class="wrap blt-ui blt-events-migration">
			<div class="blt-admin-page-header">
				<h1><?php esc_html_e( 'BLT Events', 'blt-events' ); ?> <span class="blt-admin-page-header-sub"><?php esc_html_e( 'Migration', 'blt-events' ); ?></span></h1>
			</div>

			<?php self::render_result( $result ); ?>

			<div class="blt-callout">
				<strong><?php esc_html_e( 'Import safely', 'blt-events' ); ?></strong>
				<span><?php esc_html_e( 'Preview first to see what would be created. Imports never alter source events and skip source events that were already imported.', 'blt-events' ); ?></span>
			</div>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Supported event plugins', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Activate a supported plugin to make its events available here. This screen does not deactivate or delete anything from the source plugin.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php if ( empty( $detected ) ) : ?>
						<p class="blt-field-desc"><?php esc_html_e( 'No supported event plugins are active. Activate Modern Events Calendar, The Events Calendar, or Eventin, then return to this page.', 'blt-events' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
							<div class="blt-toggle-stack">
								<?php foreach ( $sources as $slug => $source ) : ?>
									<label class="blt-toggle">
										<input type="checkbox" name="sources[]" value="<?php echo esc_attr( $slug ); ?>" <?php disabled( empty( $source['detected'] ) ); ?> <?php checked( ! empty( $source['detected'] ) ); ?> />
										<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
										<span class="blt-toggle-text">
											<span class="blt-toggle-label"><?php echo esc_html( $source['label'] ); ?> <?php self::status_badge( ! empty( $source['detected'] ), ! empty( $source['installed'] ), $source['count'] ?? 0 ); ?></span>
											<span class="blt-toggle-desc">
												<?php
												echo ! empty( $source['detected'] )
													? esc_html(
														sprintf(
															/* translators: %s: number of events. */
															_n( '%s source event found.', '%s source events found.', (int) $source['count'], 'blt-events' ),
															number_format_i18n( (int) $source['count'] )
														)
													)
													: ( ! empty( $source['installed'] )
														? esc_html__( 'Installed but inactive. Activate it to import its events.', 'blt-events' )
														: esc_html__( 'Not installed.', 'blt-events' ) );
												?>
											</span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="blt-field-desc"><?php esc_html_e( 'Categories and tags become BLT Event Categories. Venue, organizer, images and ticket details are carried over when the source provides them. BLT Events does not support recurring event rules or existing registrations, so those remain in the source plugin.', 'blt-events' ); ?></p>
							<div class="blt-settings-footer">
								<button type="submit" class="button" name="dry_run" value="1"><?php esc_html_e( 'Preview Import', 'blt-events' ); ?></button>
								<button type="submit" class="button button-primary blt-save-button" name="dry_run" value="0"><?php esc_html_e( 'Import Events', 'blt-events' ); ?></button>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function status_badge( $detected, $installed, $count ) {
		/* translators: %d: number of source events. */
		$count_label = sprintf( _n( '%d event', '%d events', (int) $count, 'blt-events' ), (int) $count );
		printf(
			'<span class="blt-badge %1$s">%2$s</span>',
			$detected ? 'blt-badge-on' : 'blt-badge-off',
			esc_html( $detected ? $count_label : ( $installed ? __( 'Inactive', 'blt-events' ) : __( 'Not installed', 'blt-events' ) ) )
		);
	}

	public static function handle_import() {
		if ( ! current_user_can( BLT_Events_Helpers::menu_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate events.', 'blt-events' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$requested = isset( $_POST['sources'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sources'] ) ) : array();
		$dry_run   = isset( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];
		$sources   = self::sources();
		$result    = array(
			'dry_run'  => $dry_run,
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'preview'  => array(),
		);

		foreach ( $requested as $slug ) {
			if ( empty( $sources[ $slug ] ) || empty( $sources[ $slug ]['detected'] ) ) {
				continue;
			}
			$result = self::migrate_source( $slug, $sources[ $slug ], $dry_run, $result );
		}

		$key = wp_generate_uuid4();
		set_transient( self::RESULTS_PREFIX . $key, $result, MINUTE_IN_SECONDS * 10 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'     => 'event',
					'page'          => 'blt-migration',
					'blt-migration' => $key,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	private static function migrate_source( $slug, $source, $dry_run, $result ) {
		$events = get_posts(
			array(
				'post_type'      => $source['post_type'],
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $events as $event ) {
			$event_id = (int) $event->ID;
			if ( self::already_imported( $slug, $event_id ) ) {
				++$result['skipped'];
				continue;
			}

			$data = self::normalize_event( $slug, $event );
			if ( empty( $data['date'] ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: event title, 2: source plugin. */
					__( '%1$s was skipped because %2$s has no usable start date.', 'blt-events' ),
					$event->post_title ? $event->post_title : '#' . $event_id,
					$source['label']
				);
				continue;
			}

			if ( $dry_run ) {
				++$result['imported'];
				$result['preview'][] = $event->post_title ? $event->post_title : '#' . $event_id;
				continue;
			}

			$new_id = self::insert_event( $slug, $event, $data );
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				$result['errors'][] = sprintf(
					/* translators: %s: event title. */
					__( '%s could not be imported.', 'blt-events' ),
					$event->post_title ? $event->post_title : '#' . $event_id
				);
				continue;
			}
			++$result['imported'];
		}

		return $result;
	}

	private static function already_imported( $source, $source_id ) {
		$matches = get_posts(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to make imports idempotent.
					'relation' => 'AND',
					array(
						'key'   => self::SOURCE_META,
						'value' => $source,
					),
					array(
						'key'   => self::SOURCE_ID_META,
						'value' => (string) $source_id,
					),
				),
			)
		);
		return ! empty( $matches );
	}

	/**
	 * Convert source meta into BLT Events' documented event-meta shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function normalize_event( $source, $event ) {
		if ( 'tec' === $source ) {
			$data = self::normalize_tec_event( $event );
		} elseif ( 'eventin' === $source ) {
			$data = self::normalize_eventin_event( $event );
		} else {
			$data = self::normalize_mec_event( $event );
		}

		/**
		 * Filters a normalized source event before it is previewed/imported.
		 *
		 * @param array   $data   Destination event data.
		 * @param string  $source Source slug.
		 * @param WP_Post $event  Source event.
		 */
		return apply_filters( 'blt_events_migration_normalize_event', $data, $source, $event );
	}

	private static function normalize_tec_event( $event ) {
		$start   = self::date_parts( get_post_meta( $event->ID, '_EventStartDate', true ) );
		$end     = self::date_parts( get_post_meta( $event->ID, '_EventEndDate', true ) );
		$all_day = self::truthy( get_post_meta( $event->ID, '_EventAllDay', true ) );
		$venue   = self::tec_venue( (int) get_post_meta( $event->ID, '_EventVenueID', true ) );
		$url     = esc_url_raw( get_post_meta( $event->ID, '_EventVirtualURL', true ) );
		if ( ! $url && self::truthy( get_post_meta( $event->ID, '_EventVirtual', true ) ) ) {
			$url = esc_url_raw( get_post_meta( $event->ID, '_EventURL', true ) );
		}

		return array(
			'date'         => $start['date'],
			'start_time'   => $all_day ? '' : $start['time'],
			'end_date'     => $end['date'],
			'end_time'     => $all_day ? '' : $end['time'],
			'all_day'      => $all_day,
			'venue'        => $venue['name'],
			'location'     => $venue['address'],
			'latitude'     => $venue['latitude'],
			'longitude'    => $venue['longitude'],
			'online_url'   => $url,
			'event_type'   => $venue['name'] || $venue['address'] ? 'in-person' : ( $url ? 'online' : 'in-person' ),
			'organizer'    => self::tec_organizer( (int) get_post_meta( $event->ID, '_EventOrganizerID', true ) ),
			'terms'        => self::terms_for( $event->ID, array( 'tribe_events_cat', 'post_tag' ) ),
			'tickets'      => self::tec_tickets( $event->ID ),
			'capacity'     => absint( get_post_meta( $event->ID, '_EventCapacity', true ) ),
			'thumbnail_id' => get_post_thumbnail_id( $event->ID ),
		);
	}

	private static function normalize_mec_event( $event ) {
		$start_date = self::mec_date( get_post_meta( $event->ID, 'mec_start_date', true ) );
		$end_date   = self::mec_date( get_post_meta( $event->ID, 'mec_end_date', true ) );
		$all_day    = self::truthy( get_post_meta( $event->ID, 'mec_allday', true ) );
		$location   = self::mec_location( (int) get_post_meta( $event->ID, 'mec_location_id', true ) );
		$virtual    = esc_url_raw( get_post_meta( $event->ID, 'mec_virtual_link', true ) );
		if ( ! $virtual ) {
			$virtual = esc_url_raw( get_post_meta( $event->ID, 'mec_zoom_link', true ) );
		}

		return array(
			'date'         => $start_date,
			'start_time'   => $all_day ? '' : self::mec_time( $event->ID, 'start' ),
			'end_date'     => $end_date,
			'end_time'     => $all_day ? '' : self::mec_time( $event->ID, 'end' ),
			'all_day'      => $all_day,
			'venue'        => $location['name'],
			'location'     => $location['address'],
			'latitude'     => $location['latitude'],
			'longitude'    => $location['longitude'],
			'online_url'   => $virtual,
			'event_type'   => $location['name'] || $location['address'] ? ( $virtual ? 'hybrid' : 'in-person' ) : ( $virtual ? 'online' : 'in-person' ),
			'organizer'    => self::mec_organizer( (int) get_post_meta( $event->ID, 'mec_organizer_id', true ) ),
			'terms'        => self::terms_for( $event->ID, array( 'mec_category', 'mec_tag', 'post_tag' ) ),
			'tickets'      => self::mec_tickets( $event->ID ),
			'capacity'     => absint( get_post_meta( $event->ID, 'mec_capacity', true ) ),
			'thumbnail_id' => get_post_thumbnail_id( $event->ID ),
		);
	}

	private static function normalize_eventin_event( $event ) {
		$start_date = self::mec_date( get_post_meta( $event->ID, 'etn_start_date', true ) );
		$end_date   = self::mec_date( get_post_meta( $event->ID, 'etn_end_date', true ) );
		$start_time = self::eventin_time( get_post_meta( $event->ID, 'etn_start_time', true ) );
		$end_time   = self::eventin_time( get_post_meta( $event->ID, 'etn_end_time', true ) );
		$location   = self::eventin_location( $event->ID );
		$type       = self::eventin_type( get_post_meta( $event->ID, 'etn_event_location_type', true ) );
		$online_url = self::eventin_online_url( $event->ID );

		if ( ! $end_date ) {
			$end_date = $start_date;
		}
		if ( ! $online_url && 'in-person' !== $type && $location['name'] ) {
			$type = 'hybrid';
		}

		return array(
			'date'                     => $start_date,
			'start_time'               => $start_time,
			'end_date'                 => $end_date,
			'end_time'                 => $end_time,
			'all_day'                  => '' === $start_time,
			'venue'                    => $location['name'],
			'location'                 => $location['address'],
			'latitude'                 => $location['latitude'],
			'longitude'                => $location['longitude'],
			'online_url'               => $online_url,
			'event_type'               => $type,
			'organizer'                => self::eventin_organizer( $event->ID ),
			'terms'                    => self::terms_for( $event->ID, array( 'etn_category', 'etn_tag' ) ),
			'tickets'                  => self::eventin_tickets( $event->ID ),
			'capacity'                 => absint( get_post_meta( $event->ID, 'etn_total_avaiilable_tickets', true ) ),
			'thumbnail_id'             => self::eventin_thumbnail_id( $event->ID ),
			'registration_cutoff_date' => self::mec_date( get_post_meta( $event->ID, 'etn_registration_deadline', true ) ),
		);
	}

	private static function insert_event( $source, $event, $data ) {
		$content = $event->post_content;
		if ( ! empty( $data['organizer'] ) ) {
			$content .= "\n\n" . self::organizer_markup( $data['organizer'] );
		}
		$event_days = self::multi_day_schedule( $data );

		$new_id = wp_insert_post(
			array(
				'post_type'    => 'event',
				'post_status'  => in_array( $event->post_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $event->post_status : 'draft',
				'post_title'   => $event->post_title,
				'post_content' => $content,
				'post_excerpt' => $event->post_excerpt,
				'post_author'  => $event->post_author,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$meta = array(
			'_blt_event_date'        => $data['date'],
			'_blt_event_end_date'    => $data['end_date'],
			'_blt_event_start_time'  => $data['start_time'],
			'_blt_event_end_time'    => $data['end_time'],
			'_blt_event_all_day'     => $data['all_day'] ? '1' : '0',
			'_blt_event_no_end_time' => $data['end_time'] ? '0' : '1',
			'_blt_multi_day'         => $data['end_date'] && $data['end_date'] !== $data['date'] ? '1' : '0',
			'_blt_event_days'        => $event_days ? wp_json_encode( $event_days ) : '',
			'_blt_event_type'        => $data['event_type'],
			'_blt_event_venue'       => $data['venue'],
			'_blt_event_location'    => $data['location'],
			'_blt_event_latitude'    => $data['latitude'],
			'_blt_event_longitude'   => $data['longitude'],
			'_blt_event_online_url'  => $data['online_url'],
			'_blt_capacity'          => $data['capacity'],
			'_blt_registration_open' => ! empty( $data['tickets'] ) ? '1' : '0',
			'_blt_ticket_types'      => wp_json_encode( $data['tickets'] ),
			self::SOURCE_META        => $source,
			self::SOURCE_ID_META     => (string) $event->ID,
			self::ORGANIZER_META     => wp_json_encode( $data['organizer'] ),
		);
		// update_post_meta() unslashes; slash first so the JSON values (and any
		// backslash in a venue or address) are stored exactly as built.
		foreach ( $meta as $key => $value ) {
			update_post_meta( $new_id, $key, wp_slash( $value ) );
		}
		if ( ! empty( $data['registration_cutoff_date'] ) ) {
			update_post_meta( $new_id, '_blt_registration_cutoff_date', $data['registration_cutoff_date'] );
		}
		if ( ! empty( $data['thumbnail_id'] ) ) {
			set_post_thumbnail( $new_id, (int) $data['thumbnail_id'] );
		}
		if ( ! empty( $data['terms'] ) ) {
			wp_set_object_terms( $new_id, $data['terms'], 'event_category', false );
		}

		/**
		 * Fires after an event was imported.
		 *
		 * @param int     $new_id New BLT Events ID.
		 * @param string  $source Source slug.
		 * @param WP_Post $event  Source event.
		 * @param array   $data   Normalized data.
		 */
		do_action( 'blt_events_migration_imported', $new_id, $source, $event, $data );
		return $new_id;
	}

	/**
	 * Expand a date span into the per-day schedule used by BLT's month view.
	 *
	 * The destination deliberately limits manually entered schedules to 30
	 * dates; applying the same limit here avoids writing a shape the editor
	 * cannot safely preserve.
	 */
	private static function multi_day_schedule( $data ) {
		if ( empty( $data['end_date'] ) || $data['end_date'] <= $data['date'] ) {
			return array();
		}

		try {
			$day = new DateTimeImmutable( $data['date'] );
			$end = new DateTimeImmutable( $data['end_date'] );
		} catch ( Exception $e ) {
			return array();
		}

		$days      = array();
		$day_count = 0;
		while ( $day <= $end && $day_count < 30 ) {
			$date   = $day->format( 'Y-m-d' );
			$days[] = array(
				'date'  => $date,
				'start' => $date === $data['date'] ? $data['start_time'] : '',
				'end'   => $date === $data['end_date'] ? $data['end_time'] : '',
			);
			++$day_count;
			$day = $day->modify( '+1 day' );
		}

		return $days;
	}

	private static function date_parts( $value ) {
		$date = self::mec_date( $value );
		$time = '';
		if ( $date && preg_match( '/(?:T|\s)([0-2]\d:[0-5]\d)/', (string) $value, $match ) ) {
			$time = $match[1];
		}
		return array(
			'date' => $date,
			'time' => $time,
		);
	}

	private static function mec_date( $value ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $value, $match ) ) {
			return $match[0];
		}
		return '';
	}

	private static function mec_time( $event_id, $side ) {
		$hour   = get_post_meta( $event_id, 'mec_' . $side . '_time_hour', true );
		$minute = get_post_meta( $event_id, 'mec_' . $side . '_time_minutes', true );
		$ampm   = strtolower( get_post_meta( $event_id, 'mec_' . $side . '_time_ampm', true ) );
		if ( ! is_numeric( $hour ) || ! is_numeric( $minute ) ) {
			return '';
		}
		$hour = (int) $hour;
		if ( in_array( $ampm, array( 'am', 'pm' ), true ) ) {
			$hour = $hour % 12 + ( 'pm' === $ampm ? 12 : 0 );
		}
		return $hour >= 0 && $hour <= 23 && (int) $minute <= 59 ? sprintf( '%02d:%02d', $hour, (int) $minute ) : '';
	}

	private static function eventin_time( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	private static function eventin_type( $value ) {
		$value = sanitize_key( $value );
		if ( 'online' === $value ) {
			return 'online';
		}
		if ( 'hybrid' === $value ) {
			return 'hybrid';
		}
		return 'in-person';
	}

	private static function eventin_location( $event_id ) {
		$location = get_post_meta( $event_id, 'etn_event_location', true );
		if ( ! $location ) {
			$location = get_post_meta( $event_id, 'etn_event_location_list', true );
		}
		$location = self::eventin_record( $location );

		if ( is_string( $location ) ) {
			return array(
				'name'      => sanitize_text_field( $location ),
				'address'   => '',
				'latitude'  => '',
				'longitude' => '',
			);
		}
		if ( ! is_array( $location ) ) {
			return array(
				'name'      => '',
				'address'   => '',
				'latitude'  => '',
				'longitude' => '',
			);
		}

		$name      = self::eventin_value( $location, array( 'name', 'location_name', 'venue', 'title' ) );
		$address   = self::eventin_value( $location, array( 'address', 'location_address', 'full_address' ) );
		$latitude  = self::eventin_value( $location, array( 'lat', 'latitude' ) );
		$longitude = self::eventin_value( $location, array( 'lng', 'longitude', 'lon' ) );

		return array(
			'name'      => $name,
			'address'   => $address,
			'latitude'  => is_numeric( $latitude ) ? $latitude : '',
			'longitude' => is_numeric( $longitude ) ? $longitude : '',
		);
	}

	private static function eventin_online_url( $event_id ) {
		foreach ( array( 'etn_google_meet_link', 'etn_zoom_join_url', 'etn_zoom_link' ) as $key ) {
			$url = esc_url_raw( get_post_meta( $event_id, $key, true ) );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	private static function eventin_organizer( $event_id ) {
		$organizer = self::eventin_record( get_post_meta( $event_id, 'etn_event_organizer', true ) );
		if ( is_numeric( $organizer ) && get_post( (int) $organizer ) ) {
			return array( 'name' => get_the_title( (int) $organizer ) );
		}
		if ( ! is_array( $organizer ) ) {
			return array();
		}

		$result = array();
		$fields = array(
			'name'    => array( 'name', 'organizer_name', 'etn_organizer_name', 'title' ),
			'email'   => array( 'email', 'organizer_email', 'etn_organizer_email' ),
			'phone'   => array( 'phone', 'organizer_phone', 'etn_organizer_phone' ),
			'website' => array( 'website', 'url', 'company_url' ),
		);
		foreach ( $fields as $destination => $keys ) {
			$value = self::eventin_value( $organizer, $keys );
			if ( $value ) {
				$result[ $destination ] = $value;
			}
		}
		return $result;
	}

	private static function eventin_thumbnail_id( $event_id ) {
		$thumbnail_id = get_post_thumbnail_id( $event_id );
		if ( $thumbnail_id ) {
			return $thumbnail_id;
		}
		foreach ( array( 'etn_banner', 'etn_event_logo' ) as $key ) {
			$thumbnail_id = absint( get_post_meta( $event_id, $key, true ) );
			if ( $thumbnail_id ) {
				return $thumbnail_id;
			}
		}
		return 0;
	}

	private static function eventin_record( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				return $value;
			}
			if ( is_array( $item ) || is_scalar( $item ) ) {
				return $item;
			}
		}
		return array();
	}

	private static function eventin_value( $data, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return sanitize_text_field( $data[ $key ] );
			}
		}
		return '';
	}

	private static function tec_venue( $venue_id ) {
		return self::location_from_post( $venue_id, array( '_VenueAddress', '_VenueCity', '_VenueProvince', '_VenueZip', '_VenueCountry' ), '_VenueLatitude', '_VenueLongitude' );
	}

	private static function mec_location( $location_id ) {
		$location = get_term( $location_id, 'mec_location' );
		if ( ! $location || is_wp_error( $location ) ) {
			return array(
				'name'      => '',
				'address'   => '',
				'latitude'  => '',
				'longitude' => '',
			);
		}
		$latitude  = get_term_meta( $location_id, 'latitude', true );
		$longitude = get_term_meta( $location_id, 'longitude', true );
		return array(
			'name'      => sanitize_text_field( $location->name ),
			'address'   => sanitize_text_field( get_term_meta( $location_id, 'address', true ) ),
			'latitude'  => is_numeric( $latitude ) ? (string) $latitude : '',
			'longitude' => is_numeric( $longitude ) ? (string) $longitude : '',
		);
	}

	private static function location_from_post( $id, $address_keys, $lat_key, $lng_key ) {
		if ( ! $id || ! get_post( $id ) ) {
			return array(
				'name'      => '',
				'address'   => '',
				'latitude'  => '',
				'longitude' => '',
			);
		}
		$address = array();
		foreach ( $address_keys as $key ) {
			$value = sanitize_text_field( get_post_meta( $id, $key, true ) );
			if ( $value ) {
				$address[] = $value;
			}
		}
		$latitude  = get_post_meta( $id, $lat_key, true );
		$longitude = get_post_meta( $id, $lng_key, true );
		return array(
			'name'      => get_the_title( $id ),
			'address'   => implode( ', ', array_unique( $address ) ),
			'latitude'  => is_numeric( $latitude ) ? (string) $latitude : '',
			'longitude' => is_numeric( $longitude ) ? (string) $longitude : '',
		);
	}

	private static function tec_organizer( $organizer_id ) {
		return self::organizer_from_post( $organizer_id, array( '_OrganizerPhone', '_OrganizerEmail', '_OrganizerWebsite' ) );
	}

	private static function mec_organizer( $organizer_id ) {
		$organizer = get_term( $organizer_id, 'mec_organizer' );
		if ( ! $organizer || is_wp_error( $organizer ) ) {
			return array();
		}
		$result = array( 'name' => sanitize_text_field( $organizer->name ) );
		foreach ( array( 'tel', 'email', 'url' ) as $key ) {
			$value = get_term_meta( $organizer_id, $key, true );
			if ( $value ) {
				$result[ $key ] = sanitize_text_field( $value );
			}
		}
		return $result;
	}

	private static function organizer_from_post( $id, $keys ) {
		if ( ! $id || ! get_post( $id ) ) {
			return array();
		}
		$organizer = array( 'name' => get_the_title( $id ) );
		foreach ( $keys as $key ) {
			$value = get_post_meta( $id, $key, true );
			if ( $value ) {
				$organizer[ $key ] = sanitize_text_field( $value );
			}
		}
		return $organizer;
	}

	private static function organizer_markup( $organizer ) {
		$parts = array_filter( array_map( 'sanitize_text_field', $organizer ) );
		return empty( $parts ) ? '' : '<p><strong>' . esc_html__( 'Organizer:', 'blt-events' ) . '</strong> ' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	}

	private static function terms_for( $event_id, $taxonomies ) {
		$terms = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$found = wp_get_post_terms( $event_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $found ) ) {
				$terms = array_merge( $terms, $found );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $terms ) ) ) );
	}

	private static function tec_tickets( $event_id ) {
		if ( ! function_exists( 'tribe_tickets_get_event_tickets' ) ) {
			return array();
		}
		$tickets = tribe_tickets_get_event_tickets( $event_id );
		return self::tickets_from_source( is_array( $tickets ) ? $tickets : array() );
	}

	private static function mec_tickets( $event_id ) {
		$tickets = get_post_meta( $event_id, 'mec_tickets', true );
		return self::tickets_from_source( is_array( $tickets ) ? $tickets : array() );
	}

	private static function eventin_tickets( $event_id ) {
		$tickets = get_post_meta( $event_id, 'etn_ticket_variations', true );
		return self::tickets_from_source( is_array( $tickets ) ? $tickets : array() );
	}

	/**
	 * Convert the common ticket properties exposed by TEC and MEC.
	 *
	 * @param array<int,array|object> $tickets Source ticket data.
	 * @return array<int,array<string,string|float>>
	 */
	public static function tickets_from_source( $tickets ) {
		$normalized = array();
		foreach ( $tickets as $ticket ) {
			$ticket = is_object( $ticket ) ? get_object_vars( $ticket ) : $ticket;
			if ( ! is_array( $ticket ) ) {
				continue;
			}
			$name = $ticket['name'] ?? $ticket['title'] ?? $ticket['ticket_name'] ?? $ticket['etn_ticket_name'] ?? '';
			if ( ! $name ) {
				continue;
			}
			$normalized[] = array(
				'name'            => sanitize_text_field( $name ),
				'price'           => (float) ( $ticket['price'] ?? $ticket['cost'] ?? $ticket['etn_ticket_price'] ?? 0 ),
				'description'     => sanitize_text_field( $ticket['description'] ?? $ticket['etn_ticket_description'] ?? '' ),
				'sale_start_date' => self::mec_date( $ticket['start_date'] ?? $ticket['ticket_start_date'] ?? $ticket['etn_ticket_start_date'] ?? $ticket['start'] ?? '' ),
				'sale_start_time' => self::ticket_time( $ticket, 'start' ),
				'sale_end_date'   => self::mec_date( $ticket['end_date'] ?? $ticket['ticket_end_date'] ?? $ticket['etn_ticket_end_date'] ?? $ticket['end'] ?? '' ),
				'sale_end_time'   => self::ticket_time( $ticket, 'end' ),
				'roles'           => array(),
			);
		}
		return $normalized;
	}

	private static function ticket_time( $ticket, $side ) {
		$date = $ticket[ $side . '_date' ] ?? $ticket[ 'ticket_' . $side . '_date' ] ?? $ticket[ 'etn_ticket_' . $side . '_date' ] ?? $ticket[ $side ] ?? '';
		$time = self::date_parts( $date )['time'];
		if ( $time ) {
			return $time;
		}
		$time = self::eventin_time( $ticket[ $side . '_time' ] ?? $ticket[ 'ticket_' . $side . '_time' ] ?? $ticket[ 'etn_ticket_' . $side . '_time' ] ?? '' );
		if ( $time ) {
			return $time;
		}
		$hour   = $ticket[ $side . '_time_hour' ] ?? $ticket[ 'ticket_' . $side . '_time_hour' ] ?? $ticket[ 'etn_ticket_' . $side . '_time_hour' ] ?? '';
		$minute = $ticket[ $side . '_time_minutes' ] ?? $ticket[ 'ticket_' . $side . '_time_minute' ] ?? $ticket[ 'etn_ticket_' . $side . '_time_minute' ] ?? '';
		$ampm   = strtolower( $ticket[ $side . '_time_ampm' ] ?? $ticket[ 'ticket_' . $side . '_time_ampm' ] ?? $ticket[ 'etn_ticket_' . $side . '_time_ampm' ] ?? '' );
		if ( ! is_numeric( $hour ) || ! is_numeric( $minute ) ) {
			return '';
		}
		$hour = (int) $hour;
		if ( in_array( $ampm, array( 'am', 'pm' ), true ) ) {
			$hour = $hour % 12 + ( 'pm' === $ampm ? 12 : 0 );
		}
		return $hour >= 0 && $hour <= 23 && (int) $minute <= 59 ? sprintf( '%02d:%02d', $hour, (int) $minute ) : '';
	}

	private static function truthy( $value ) {
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private static function get_result() {
		$key = isset( $_GET['blt-migration'] ) ? sanitize_text_field( wp_unslash( $_GET['blt-migration'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $key ) {
			return array();
		}
		$result = get_transient( self::RESULTS_PREFIX . $key );
		delete_transient( self::RESULTS_PREFIX . $key );
		return is_array( $result ) ? $result : array();
	}

	private static function render_result( $result ) {
		if ( empty( $result ) ) {
			return;
		}
		$action = ! empty( $result['dry_run'] ) ? __( 'Preview', 'blt-events' ) : __( 'Import', 'blt-events' );
		?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			printf(
				/* translators: 1: import action, 2: imported event count, 3: skipped event count. */
				esc_html__( '%1$s complete: %2$s event(s) %3$s, %4$s already-imported event(s) skipped.', 'blt-events' ),
				esc_html( $action ),
				esc_html( number_format_i18n( (int) $result['imported'] ) ),
				! empty( $result['dry_run'] ) ? esc_html__( 'would be imported', 'blt-events' ) : esc_html__( 'imported', 'blt-events' ),
				esc_html( number_format_i18n( (int) $result['skipped'] ) )
			);
			?>
		</p></div>
		<?php if ( ! empty( $result['preview'] ) ) : ?>
			<div class="blt-card"><div class="blt-card-body"><strong><?php esc_html_e( 'Events that would be imported', 'blt-events' ); ?></strong><ul>
				<?php foreach ( array_slice( $result['preview'], 0, 50 ) as $title ) : ?>
					<li><?php echo esc_html( $title ); ?></li>
				<?php endforeach; ?>
			</ul></div></div>
		<?php endif; ?>
		<?php if ( ! empty( $result['errors'] ) ) : ?>
			<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Skipped events', 'blt-events' ); ?></strong></p><ul>
				<?php foreach ( array_slice( $result['errors'], 0, 50 ) as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul></div>
		<?php endif; ?>
		<?php
	}
}
