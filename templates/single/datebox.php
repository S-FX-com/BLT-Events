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
 * @var string $event_date    Start date, Y-m-d.
 * @var bool   $show_calendar
 * @var string $ics_url
 * @var string $google_url
 * @var bool   $card_info     Set by the current single-event.php, which lists the time and calendar links in single/info.php.
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
<?php
// A theme copy of the pre-2.5 single-event.php has no facts list, so the
// time and calendar links stay here for it.
if ( empty( $card_info ) ) :
	?>
	<?php if ( '' !== $time_label && '' !== $date_label ) : ?>
		<p class="blt-event__datebox-time"><?php echo esc_html( $time_label ); ?></p>
	<?php endif; ?>
	<?php if ( $show_calendar && '' !== $date_label ) : ?>
		<p class="blt-event__calendar-links">
			<a class="blt-event__calendar-link" href="<?php echo esc_url( $ics_url ); ?>"><?php esc_html_e( 'Add to calendar', 'blt-events' ); ?></a>
			<span aria-hidden="true">&middot;</span>
			<a class="blt-event__calendar-link" href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'blt-events' ); ?></a>
		</p>
	<?php endif; ?>
<?php endif; ?>
