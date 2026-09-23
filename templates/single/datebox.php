<?php
/**
 * Date at the top of the event card: the day number in a framed box, then
 * "Event Date" and the full date. The time and calendar links are listed
 * below it (single/info.php).
 *
 * Override: your-theme/blt-events/single/datebox.php
 *
 * @var string $date_label
 * @var string $time_label
 * @var string $day
 * @var string $event_date Start date, Y-m-d.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( '' === $date_label && '' === $time_label ) {
	return;
}
?>
<div class="blt-event__datebox">
	<?php if ( '' !== $day ) : ?>
		<span class="blt-event__datebox-day" aria-hidden="true">
			<span class="blt-event__datebox-day-inner"><?php echo esc_html( $day ); ?></span>
		</span>
	<?php endif; ?>
	<span class="blt-event__datebox-meta">
		<span class="blt-event__datebox-label"><?php esc_html_e( 'Event Date', 'blt-events' ); ?></span>
		<?php if ( '' !== $date_label ) : ?>
			<time class="blt-event__datebox-value"<?php echo ! empty( $event_date ) ? ' datetime="' . esc_attr( $event_date ) . '"' : ''; ?>><?php echo esc_html( $date_label ); ?></time>
		<?php else : ?>
			<span class="blt-event__datebox-value"><?php echo esc_html( $time_label ); ?></span>
		<?php endif; ?>
	</span>
</div>
