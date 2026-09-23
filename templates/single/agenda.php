<?php
/**
 * Agenda / schedule: one native <details> per item, the time as the
 * always-visible clickable header and the session label as the content
 * revealed on expand. All items share a `name`, so opening one closes
 * whichever other one was open — a real accordion, not independent toggles.
 *
 * Override: your-theme/blt-events/single/agenda.php
 *
 * @var int   $event_id
 * @var array $agenda   Rows with label, start, end, time (formatted).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $agenda ) ) {
	return;
}
?>
<div class="blt-event__agenda">
	<h2 class="blt-event__section-title"><?php esc_html_e( 'Agenda', 'blt-events' ); ?></h2>
	<div class="blt-event__agenda-list">
		<?php foreach ( $agenda as $i => $item ) : ?>
			<?php
			// The time is the always-visible header; an untimed item (times are
			// optional) falls back to the label there instead of an empty header.
			$has_time     = '' !== $item['time'];
			$summary_text = $has_time ? $item['time'] : $item['label'];
			?>
			<details class="blt-event__agenda-item" name="blt-agenda-<?php echo esc_attr( $event_id ); ?>" <?php echo 0 === $i ? 'open' : ''; ?>>
				<summary class="blt-event__agenda-summary">
					<span class="blt-event__agenda-time"><?php echo esc_html( $summary_text ); ?></span>
					<span class="blt-event__agenda-chevron"><?php echo BLT_Events_Helpers::chevron_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				</summary>
				<?php if ( $has_time && '' !== $item['label'] ) : ?>
					<p class="blt-event__agenda-label"><?php echo esc_html( $item['label'] ); ?></p>
				<?php endif; ?>
			</details>
		<?php endforeach; ?>
	</div>
</div>
