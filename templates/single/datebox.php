<?php
/**
 * Sidebar date box with "Add to calendar" links.
 *
 * Override: your-theme/blt-events/single/datebox.php
 *
 * @var string $day
 * @var string $date_label
 * @var string $time_label
 * @var bool   $show_calendar
 * @var string $ics_url
 * @var string $google_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( '' === $date_label && '' === $time_label ) {
	return;
}
?>
<div class="blt-event__card blt-event__datebox">
	<div class="blt-event__datebox-head">
		<?php if ( $day !== '' ) : ?>
			<span class="blt-event__datebox-day" aria-hidden="true"><?php echo esc_html( $day ); ?></span>
		<?php endif; ?>
		<span class="blt-event__datebox-meta">
			<span class="blt-event__datebox-label"><?php esc_html_e( 'Event Date', 'blt-events' ); ?></span>
			<span class="blt-event__datebox-value"><?php echo esc_html( $date_label ); ?></span>
		</span>
	</div>
	<?php if ( $time_label ) : ?>
		<p class="blt-event__datebox-time"><?php echo esc_html( $time_label ); ?></p>
	<?php endif; ?>
	<?php if ( $show_calendar && $date_label ) : ?>
		<p class="blt-event__calendar-links">
			<a class="blt-event__calendar-link" href="<?php echo esc_url( $ics_url ); ?>"><?php esc_html_e( 'Add to calendar', 'blt-events' ); ?></a>
			<span aria-hidden="true">·</span>
			<a class="blt-event__calendar-link" href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'blt-events' ); ?></a>
		</p>
	<?php endif; ?>
</div>
