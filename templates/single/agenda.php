<?php
/**
 * Agenda: an accordion with one item per session. The time is the item's
 * title and the session its content; the first item starts open.
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

// One accordion per event, so opening a session closes the others (where
// the browser supports <details name>); the first session starts open.
$blt_events_group = 'blt-agenda-' . (int) $event_id;
$blt_events_first = true;
?>
<section class="blt-event__section blt-event__agenda" aria-labelledby="<?php echo esc_attr( $blt_events_group ); ?>-title">
	<h2 class="blt-event__section-title" id="<?php echo esc_attr( $blt_events_group ); ?>-title"><?php esc_html_e( 'Agenda', 'blt-events' ); ?></h2>
	<div class="blt-event__agenda-list">
		<?php foreach ( $agenda as $item ) : ?>
			<?php
			// Time is the accordion title and the session its content. An
			// untimed session is its own title, with nothing to expand.
			$has_time = '' !== (string) $item['time'];
			$heading  = $has_time ? $item['time'] : $item['label'];
			$content  = $has_time ? (string) $item['label'] : '';
			?>
			<?php if ( '' !== $content ) : ?>
				<details class="blt-event__agenda-item" name="<?php echo esc_attr( $blt_events_group ); ?>"<?php echo $blt_events_first ? ' open' : ''; ?>>
					<summary class="blt-event__agenda-summary">
						<h3 class="blt-event__agenda-time"><?php echo esc_html( $heading ); ?></h3>
						<span class="blt-event__agenda-chevron"><?php echo BLT_Events_Templates::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					</summary>
					<div class="blt-event__agenda-content">
						<p class="blt-event__agenda-label"><?php echo esc_html( $content ); ?></p>
					</div>
				</details>
				<?php $blt_events_first = false; ?>
			<?php else : ?>
				<div class="blt-event__agenda-item blt-event__agenda-item--static">
					<h3 class="blt-event__agenda-time"><?php echo esc_html( $heading ); ?></h3>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</section>
