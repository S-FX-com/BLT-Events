<?php
/**
 * BLT Events - Calendar Shortcode
 *
 * [blt_events_calendar] - Renders events in list or grid view.
 * Usage: [blt_events_calendar view="list" category="" limit="12"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Calendar_Shortcode {

	public static function init() {
		add_shortcode( 'blt_events_calendar', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'view'     => 'list',
			'category' => '',
			'limit'    => 12,
			'past'     => 'no',
		), $atts );

		// Clamp limit so shortcode input can't trigger an unbounded query.
		$limit = intval( $atts['limit'] );
		if ( $limit < 1 || $limit > 100 ) {
			$limit = 12;
		}

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_key'       => '_blt_event_date',
			'meta_type'      => 'DATE',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		// Filter out past events by default
		if ( $atts['past'] !== 'yes' ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_blt_event_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			);
		}

		// Category filter
		if ( ! empty( $atts['category'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'event_category',
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
				),
			);
		}

		$query = new WP_Query( $args );

		wp_enqueue_style( 'blt-events' );
		wp_enqueue_style( 'blt-events-calendar', BLT_EVENTS_PLUGIN_URL . 'assets/css/calendar.css', array(), BLT_EVENTS_VERSION );

		ob_start();

		if ( ! $query->have_posts() ) {
			echo '<div class="blt-events-empty"><p>' . esc_html__( 'No upcoming events found.', 'blt-events' ) . '</p></div>';
			wp_reset_postdata();
			return ob_get_clean();
		}

		$view_class = $atts['view'] === 'grid' ? 'blt-events-grid' : 'blt-events-list';
		?>
		<div class="blt-events-calendar <?php echo esc_attr( $view_class ); ?>">
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<?php
				$event_id    = get_the_ID();
				$event_date  = get_post_meta( $event_id, '_blt_event_date', true );
				$start_time  = get_post_meta( $event_id, '_blt_event_start_time', true );
				$end_time    = get_post_meta( $event_id, '_blt_event_end_time', true );
				$all_day     = get_post_meta( $event_id, '_blt_event_all_day', true );
				$venue       = get_post_meta( $event_id, '_blt_event_venue', true );
				$event_type  = get_post_meta( $event_id, '_blt_event_type', true );
				$ticket_raw  = get_post_meta( $event_id, '_blt_ticket_types', true );
				$tickets     = is_string( $ticket_raw ) ? json_decode( $ticket_raw, true ) : $ticket_raw;

				$date_format = get_option( 'blt_events_date_format', 'F j, Y' );
				$formatted_date = ! empty( $event_date ) ? date_i18n( $date_format, strtotime( $event_date ) ) : '';

				$time_display = '';
				if ( $all_day === '1' ) {
					$time_display = __( 'All Day', 'blt-events' );
				} elseif ( $start_time && $event_date ) {
					$time_display = date_i18n( get_option( 'time_format', 'g:i A' ), strtotime( $event_date . ' ' . $start_time ) );
					if ( $end_time ) {
						$time_display .= ' - ' . date_i18n( get_option( 'time_format', 'g:i A' ), strtotime( $event_date . ' ' . $end_time ) );
					}
				}

				// Price range
				$min_price = PHP_INT_MAX;
				$max_price = 0;
				if ( is_array( $tickets ) ) {
					foreach ( $tickets as $t ) {
						$p = (float) ( $t['price'] ?? 0 );
						$min_price = min( $min_price, $p );
						$max_price = max( $max_price, $p );
					}
				}
				if ( $min_price === PHP_INT_MAX ) {
					$min_price = 0;
				}
				?>
				<div class="blt-event-card">
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="blt-event-image">
							<a href="<?php the_permalink(); ?>">
								<?php the_post_thumbnail( 'medium' ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div class="blt-event-content">
						<div class="blt-event-date-badge">
							<span class="blt-date-month"><?php echo esc_html( date_i18n( 'M', strtotime( $event_date ) ) ); ?></span>
							<span class="blt-date-day"><?php echo esc_html( date_i18n( 'j', strtotime( $event_date ) ) ); ?></span>
						</div>

						<div class="blt-event-details">
							<h3 class="blt-event-title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h3>

							<div class="blt-event-meta">
								<?php if ( $formatted_date ) : ?>
									<span class="blt-meta-date"><?php echo esc_html( $formatted_date ); ?></span>
								<?php endif; ?>
								<?php if ( $time_display ) : ?>
									<span class="blt-meta-time"><?php echo esc_html( $time_display ); ?></span>
								<?php endif; ?>
								<?php if ( $venue ) : ?>
									<span class="blt-meta-venue"><?php echo esc_html( $venue ); ?></span>
								<?php endif; ?>
								<?php if ( $event_type ) : ?>
									<span class="blt-meta-type"><?php echo esc_html( ucfirst( $event_type ) ); ?></span>
								<?php endif; ?>
							</div>

							<?php if ( has_excerpt() ) : ?>
								<p class="blt-event-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
							<?php endif; ?>

							<div class="blt-event-footer">
								<?php if ( $max_price > 0 ) : ?>
									<span class="blt-event-price">
										<?php if ( $min_price == $max_price ) : ?>
											<?php echo esc_html( BLT_Events_Helpers::format_price( $min_price ) ); ?>
										<?php elseif ( $min_price == 0 ) : ?>
											<?php echo esc_html( sprintf( __( 'Free - %s', 'blt-events' ), BLT_Events_Helpers::format_price( $max_price ) ) ); ?>
										<?php else : ?>
											<?php echo esc_html( BLT_Events_Helpers::format_price( $min_price ) ); ?> - <?php echo esc_html( BLT_Events_Helpers::format_price( $max_price ) ); ?>
										<?php endif; ?>
									</span>
								<?php else : ?>
									<span class="blt-event-price blt-free"><?php esc_html_e( 'Free', 'blt-events' ); ?></span>
								<?php endif; ?>

								<a href="<?php the_permalink(); ?>" class="blt-btn-register"><?php esc_html_e( 'Register', 'blt-events' ); ?></a>
							</div>
						</div>
					</div>
				</div>
			<?php endwhile; ?>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}
}
