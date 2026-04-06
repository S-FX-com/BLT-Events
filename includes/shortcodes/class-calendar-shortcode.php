<?php
/**
 * ZymEvents - Calendar Shortcode
 *
 * [zymevents_calendar] - Renders events in list or grid view.
 * Usage: [zymevents_calendar view="list" category="" limit="12"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZymEvents_Calendar_Shortcode {

	public static function init() {
		add_shortcode( 'zymevents_calendar', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'view'     => 'list',
			'category' => '',
			'limit'    => 12,
			'past'     => 'no',
		), $atts );

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => intval( $atts['limit'] ),
			'meta_key'       => '_zymevents_event_date',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		);

		// Filter out past events by default
		if ( $atts['past'] !== 'yes' ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_zymevents_event_date',
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

		wp_enqueue_style( 'zymevents-calendar', ZYMEVENTS_PLUGIN_URL . 'assets/css/calendar.css', array(), ZYMEVENTS_VERSION );

		ob_start();

		if ( ! $query->have_posts() ) {
			echo '<div class="zymevents-empty"><p>No upcoming events found.</p></div>';
			wp_reset_postdata();
			return ob_get_clean();
		}

		$view_class = $atts['view'] === 'grid' ? 'zymevents-grid' : 'zymevents-list';
		?>
		<div class="zymevents-calendar <?php echo esc_attr( $view_class ); ?>">
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<?php
				$event_id    = get_the_ID();
				$event_date  = get_post_meta( $event_id, '_zymevents_event_date', true );
				$start_time  = get_post_meta( $event_id, '_zymevents_event_start_time', true );
				$end_time    = get_post_meta( $event_id, '_zymevents_event_end_time', true );
				$all_day     = get_post_meta( $event_id, '_zymevents_event_all_day', true );
				$venue       = get_post_meta( $event_id, '_zymevents_event_venue', true );
				$event_type  = get_post_meta( $event_id, '_zymevents_event_type', true );
				$ticket_raw  = get_post_meta( $event_id, '_zymevents_ticket_types', true );
				$tickets     = is_string( $ticket_raw ) ? json_decode( $ticket_raw, true ) : $ticket_raw;

				$date_format = get_option( 'zymevents_date_format', 'F j, Y' );
				$formatted_date = ! empty( $event_date ) ? date_i18n( $date_format, strtotime( $event_date ) ) : '';

				$time_display = '';
				if ( $all_day === '1' ) {
					$time_display = 'All Day';
				} elseif ( $start_time ) {
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
				<div class="cmt-event-card">
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="cmt-event-image">
							<a href="<?php the_permalink(); ?>">
								<?php the_post_thumbnail( 'medium' ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div class="cmt-event-content">
						<div class="cmt-event-date-badge">
							<span class="cmt-date-month"><?php echo esc_html( date_i18n( 'M', strtotime( $event_date ) ) ); ?></span>
							<span class="cmt-date-day"><?php echo esc_html( date_i18n( 'j', strtotime( $event_date ) ) ); ?></span>
						</div>

						<div class="cmt-event-details">
							<h3 class="cmt-event-title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h3>

							<div class="cmt-event-meta">
								<?php if ( $formatted_date ) : ?>
									<span class="cmt-meta-date"><?php echo esc_html( $formatted_date ); ?></span>
								<?php endif; ?>
								<?php if ( $time_display ) : ?>
									<span class="cmt-meta-time"><?php echo esc_html( $time_display ); ?></span>
								<?php endif; ?>
								<?php if ( $venue ) : ?>
									<span class="cmt-meta-venue"><?php echo esc_html( $venue ); ?></span>
								<?php endif; ?>
								<?php if ( $event_type ) : ?>
									<span class="cmt-meta-type"><?php echo esc_html( ucfirst( $event_type ) ); ?></span>
								<?php endif; ?>
							</div>

							<?php if ( has_excerpt() ) : ?>
								<p class="cmt-event-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
							<?php endif; ?>

							<div class="cmt-event-footer">
								<?php if ( $max_price > 0 ) : ?>
									<span class="cmt-event-price">
										<?php if ( $min_price == $max_price ) : ?>
											<?php echo esc_html( ZymEvents_Helpers::format_price( $min_price ) ); ?>
										<?php elseif ( $min_price == 0 ) : ?>
											Free - <?php echo esc_html( ZymEvents_Helpers::format_price( $max_price ) ); ?>
										<?php else : ?>
											<?php echo esc_html( ZymEvents_Helpers::format_price( $min_price ) ); ?> - <?php echo esc_html( ZymEvents_Helpers::format_price( $max_price ) ); ?>
										<?php endif; ?>
									</span>
								<?php else : ?>
									<span class="cmt-event-price cmt-free">Free</span>
								<?php endif; ?>

								<a href="<?php the_permalink(); ?>" class="cmt-btn-register">Register</a>
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
