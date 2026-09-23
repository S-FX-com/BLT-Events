<?php
/**
 * The event facts in the card, one icon row each: time, venue, online
 * attendance (single/virtual.php), price and calendar links.
 *
 * Override: your-theme/blt-events/single/info.php
 *
 * @var array  $args          All single-event view data (passed on to single/virtual.php).
 * @var string $date_label
 * @var string $time_label
 * @var bool   $is_physical
 * @var bool   $is_online
 * @var string $address
 * @var bool   $has_paid
 * @var string $price_from    Formatted lowest paid price.
 * @var bool   $show_calendar
 * @var string $ics_url
 * @var string $google_url
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<ul class="blt-event__info" role="list">
	<?php if ( '' !== $time_label && '' !== $date_label ) : ?>
		<li class="blt-event__info-item blt-event__info-item--time">
			<span class="blt-event__info-icon"><?php echo BLT_Events_Templates::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
			<span class="blt-event__info-text"><?php echo esc_html( $time_label ); ?></span>
		</li>
	<?php endif; ?>

	<?php if ( $is_physical && '' !== $address ) : ?>
		<li class="blt-event__info-item blt-event__info-item--location">
			<span class="blt-event__info-icon"><?php echo BLT_Events_Templates::icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
			<span class="blt-event__info-text"><?php echo esc_html( $address ); ?></span>
		</li>
	<?php endif; ?>

	<?php if ( $is_online ) : ?>
		<li class="blt-event__info-item blt-event__info-item--online">
			<span class="blt-event__info-icon"><?php echo BLT_Events_Templates::icon( 'video' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
			<span class="blt-event__info-text"><?php BLT_Events_Templates::include_template( 'single/virtual.php', $args ); ?></span>
		</li>
	<?php endif; ?>

	<li class="blt-event__info-item blt-event__info-item--price">
		<span class="blt-event__info-icon"><?php echo BLT_Events_Templates::icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
		<span class="blt-event__info-text">
			<?php
			if ( $has_paid && $price_from ) {
				printf(
					/* translators: %s: lowest ticket price, formatted. */
					esc_html__( 'From %s', 'blt-events' ),
					'<strong class="blt-event__price">' . esc_html( $price_from ) . '</strong>'
				);
			} else {
				echo '<strong class="blt-event__price">' . esc_html__( 'Free', 'blt-events' ) . '</strong>';
			}
			?>
		</span>
	</li>

	<?php if ( $show_calendar && '' !== $date_label ) : ?>
		<li class="blt-event__info-item blt-event__info-item--calendar">
			<span class="blt-event__info-icon"><?php echo BLT_Events_Templates::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
			<span class="blt-event__info-text blt-event__calendar-links">
				<a class="blt-event__calendar-link" href="<?php echo esc_url( $ics_url ); ?>"><?php esc_html_e( 'Add to calendar', 'blt-events' ); ?></a>
				<span aria-hidden="true">&middot;</span>
				<a class="blt-event__calendar-link" href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'blt-events' ); ?></a>
			</span>
		</li>
	<?php endif; ?>
</ul>
