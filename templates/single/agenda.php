<?php
/**
 * Collapsible agenda / schedule.
 *
 * Override: your-theme/blt-events/single/agenda.php
 *
 * @var array $agenda Rows with label, start, end, time (formatted).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $agenda ) ) {
	return;
}
?>
<details class="blt-event__agenda" open>
	<summary class="blt-event__agenda-summary">
		<span class="blt-event__section-title"><?php esc_html_e( 'Event Schedule', 'blt-events' ); ?></span>
		<span class="blt-event__agenda-chevron" aria-hidden="true">&#9662;</span>
	</summary>
	<ul class="blt-event__agenda-list">
		<?php foreach ( $agenda as $item ) : ?>
			<li class="blt-event__agenda-item">
				<?php if ( $item['time'] ) : ?>
					<span class="blt-event__agenda-time"><?php echo esc_html( $item['time'] ); ?></span>
				<?php endif; ?>
				<span class="blt-event__agenda-label"><?php echo esc_html( $item['label'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
</details>
