<?php
/**
 * Single event layout, rendered in place of the event's content.
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
 * @var bool    $show_back
 * @var bool    $show_calendar
 * @var string  $featured_image     <img> HTML.
 * @var string  $events_url
 * @var array   $categories         WP_Term[]
 * @var string  $date_label
 * @var string  $time_label
 * @var string  $day
 * @var string  $ics_url
 * @var string  $google_url
 * @var array   $agenda             label, start, end, time
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
?>
<div class="blt-event blt-event--<?php echo esc_attr( $event_type ); ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<?php if ( $show_featured && $featured_image ) : ?>
		<div class="blt-event__featured">
			<?php echo $featured_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML. ?>
		</div>
	<?php endif; ?>

	<div class="blt-event__layout">
		<div class="blt-event__main">
			<?php
			/**
			 * Fires at the top of the main column.
			 *
			 * @param int $event_id
			 */
			do_action( 'blt_events_single_before_main', $event_id );

			BLT_Events_Templates::include_template( 'single/back-link.php', $args );
			BLT_Events_Templates::include_template( 'single/categories.php', $args );
			BLT_Events_Templates::include_template( 'single/title.php', $args );
			BLT_Events_Templates::include_template( 'single/description.php', $args );
			BLT_Events_Templates::include_template( 'single/agenda.php', $args );
			BLT_Events_Templates::include_template( 'single/registration.php', $args );

			/**
			 * Fires at the bottom of the main column.
			 *
			 * @param int $event_id
			 */
			do_action( 'blt_events_single_after_main', $event_id );
			?>
		</div>

		<aside class="blt-event__sidebar">
			<?php
			/**
			 * Fires at the top of the sidebar, before the date box.
			 *
			 * @param int $event_id
			 */
			do_action( 'blt_events_single_before_sidebar', $event_id );

			BLT_Events_Templates::include_template( 'single/datebox.php', $args );
			BLT_Events_Templates::include_template( 'single/cta.php', $args );
			BLT_Events_Templates::include_template( 'single/address.php', $args );
			BLT_Events_Templates::include_template( 'single/virtual.php', $args );

			/**
			 * Extra sidebar content — presenters, sponsors, etc.
			 *
			 * @param int $event_id
			 */
			do_action( 'blt_events_single_sidebar', $event_id );
			?>
		</aside>
	</div>
</div>
