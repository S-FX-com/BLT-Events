<?php
/**
 * One event inside a day cell of the month view.
 *
 * Override: your-theme/blt-events/calendar/month-event.php
 *
 * @var int    $event_id
 * @var string $title
 * @var string $url
 * @var string $time        Time label ("All Day" or "10:00 am").
 * @var bool   $is_featured
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="blt-cal-event<?php echo $is_featured ? ' is-featured' : ''; ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<a href="<?php echo esc_url( $url ); ?>">
		<?php if ( $time ) : ?>
			<span class="blt-cal-event-time"><?php echo esc_html( $time ); ?></span>
		<?php endif; ?>
		<span class="blt-cal-event-title"><?php echo esc_html( $title ); ?></span>
	</a>
</li>
