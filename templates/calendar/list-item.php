<?php
/**
 * One row of the events list view.
 *
 * Override: your-theme/blt-events/calendar/list-item.php
 *
 * @var int    $event_id
 * @var string $permalink
 * @var string $title
 * @var string $excerpt
 * @var array  $when           date, end_date, start, end, all_day.
 * @var string $datetime_label "July 4 @ 8:00 am - 1:00 pm"
 * @var string $day            Day of month.
 * @var string $weekday        Short weekday, upper-case.
 * @var string $venue
 * @var string $thumbnail      <img> HTML or ''.
 * @var bool   $is_featured
 * @var string $pin_icon       Inline SVG.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="blt-list-event<?php echo $is_featured ? ' is-featured' : ''; ?>" href="<?php echo esc_url( $permalink ); ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<div class="blt-list-date" aria-hidden="true">
		<span class="blt-list-day"><?php echo esc_html( $day ); ?></span>
		<span class="blt-list-weekday"><?php echo esc_html( $weekday ); ?></span>
	</div>

	<div class="blt-list-body">
		<div class="blt-list-info">
			<p class="blt-list-datetime">
				<?php echo esc_html( $datetime_label ); ?>
				<?php if ( $is_featured ) : ?>
					<span class="blt-featured-badge"><?php esc_html_e( 'Featured', 'blt-events' ); ?></span>
				<?php endif; ?>
			</p>
			<h3 class="blt-list-title"><?php echo esc_html( $title ); ?></h3>
			<?php if ( $excerpt ) : ?>
				<p class="blt-list-excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $venue ) : ?>
			<ul class="blt-list-meta">
				<li class="blt-list-meta-item">
					<?php echo $pin_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span><?php echo esc_html( $venue ); ?></span>
				</li>
			</ul>
		<?php endif; ?>
	</div>

	<?php if ( $thumbnail ) : ?>
		<span class="blt-list-image" aria-hidden="true"><?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML. ?></span>
	<?php endif; ?>
</a>
