<?php
/**
 * Single event layout, rendered in place of the event's content.
 *
 * Hero: the featured image (16:9) with a "Back to events" button over it.
 * Body: a 2:1 grid. The main column holds the category chips, title,
 * description, agenda, sponsors, location map and registration; the event
 * card beside it (sticky on wide screens, lifted over the hero image) holds
 * the date, the event facts, the speakers and the register button.
 *
 * Override: your-theme/blt-events/single-event.php
 * The parts under templates/single/ can be overridden one by one instead.
 *
 * Available variables (see BLT_Events_Single_Event::view_data()):
 * @var WP_Post $event
 * @var int     $event_id
 * @var string  $description        Filtered post content.
 * @var string  $title
 * @var string  $event_type         online | in-person | hybrid
 * @var bool    $is_online
 * @var bool    $is_physical
 * @var bool    $show_title
 * @var bool    $show_featured
 * @var bool    $has_image          The featured image is shown (derived from show_featured and featured_image).
 * @var bool    $show_back
 * @var bool    $show_calendar
 * @var string  $featured_image     <img> HTML.
 * @var string  $events_url
 * @var array   $categories         WP_Term[]
 * @var string  $date_label
 * @var string  $time_label
 * @var string  $day
 * @var string  $event_date         Start date, Y-m-d.
 * @var string  $excerpt            A stored excerpt (events no longer have an Excerpt box), or ''.
 * @var string  $ics_url
 * @var string  $google_url
 * @var array   $agenda             label, start, end, time
 * @var array   $sponsors           id, url, full, alt
 * @var bool    $has_shortcode      The description already contains the form.
 * @var bool    $registration_open
 * @var bool    $has_paid
 * @var string  $price_from
 * @var string  $cta_label
 * @var string  $cta_url
 * @var int|null $spots_left
 * @var bool    $is_sold_out
 * @var string  $address
 * @var string  $map_src
 * @var string  $online_url
 * @var bool    $can_see_online_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_image = ! empty( $has_image );
$has_hero  = $has_image || ( $show_back && $events_url );

// Tells the parts they sit in this layout, where single/info.php lists the
// time, venue, price and calendar links. A theme copy of the pre-2.5 root
// template doesn't set it, so datebox.php, cta.php and address.php keep
// printing those facts themselves there.
$args['card_info'] = true;

// Captured so it can keep its place at the top of the page when the card
// moves up under the title on narrow screens.
ob_start();
/**
 * Fires at the top of the main column.
 *
 * @param int $event_id
 */
do_action( 'blt_events_single_before_main', $event_id );
$blt_events_before_main = trim( (string) ob_get_clean() );
?>
<div class="blt-event blt-event--<?php echo esc_attr( $event_type ); ?> <?php echo $has_image ? 'blt-event--has-image' : 'blt-event--no-image'; ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<?php if ( $has_hero ) : ?>
		<div class="blt-event__hero<?php echo $has_image ? '' : ' blt-event__hero--plain'; ?>">
			<?php BLT_Events_Templates::include_template( 'single/back-link.php', $args ); ?>
			<?php if ( $has_image ) : ?>
				<figure class="blt-event__hero-media">
					<?php echo $featured_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML. ?>
				</figure>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="blt-event__layout">
		<div class="blt-event__main">
			<?php if ( '' !== $blt_events_before_main ) : ?>
				<div class="blt-event__main-start"><?php echo $blt_events_before_main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of the blt_events_single_before_main action. ?></div>
			<?php endif; ?>
			<?php
			BLT_Events_Templates::include_template( 'single/categories.php', $args );
			BLT_Events_Templates::include_template( 'single/title.php', $args );
			BLT_Events_Templates::include_template( 'single/description.php', $args );
			BLT_Events_Templates::include_template( 'single/agenda.php', $args );
			BLT_Events_Templates::include_template( 'single/sponsors.php', $args );
			BLT_Events_Templates::include_template( 'single/address.php', $args );
			BLT_Events_Templates::include_template( 'single/registration.php', $args );

			/**
			 * Fires at the bottom of the main column.
			 *
			 * @param int $event_id
			 */
			do_action( 'blt_events_single_after_main', $event_id );
			?>
		</div>

		<aside class="blt-event__sidebar" aria-labelledby="blt-event-card-title-<?php echo esc_attr( $event_id ); ?>">
			<div class="blt-event__card blt-event__summary">
				<h2 class="blt-event__sr-only screen-reader-text" id="blt-event-card-title-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Event details', 'blt-events' ); ?></h2>
				<?php
				/**
				 * Fires at the top of the event card, before the date.
				 *
				 * @param int $event_id
				 */
				do_action( 'blt_events_single_before_sidebar', $event_id );

				BLT_Events_Templates::include_template( 'single/datebox.php', $args );
				BLT_Events_Templates::include_template( 'single/excerpt.php', $args );
				BLT_Events_Templates::include_template( 'single/info.php', $args );

				/**
				 * Extra content inside the event card, above the register
				 * button. The speakers (presenters) render here.
				 *
				 * @param int $event_id
				 */
				do_action( 'blt_events_single_sidebar', $event_id );

				BLT_Events_Templates::include_template( 'single/cta.php', $args );
				?>
			</div>
		</aside>
	</div>
</div>
