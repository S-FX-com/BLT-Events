<?php
/**
 * One card of the events grid view.
 *
 * Override: your-theme/blt-events/calendar/grid-card.php
 *
 * @var int    $event_id
 * @var string $permalink
 * @var string $title
 * @var string $excerpt
 * @var string $thumbnail      <img> HTML or ''.
 * @var array  $when
 * @var string $date_month     "Jul"
 * @var string $date_day       "4"
 * @var string $formatted_date Full date label.
 * @var string $time_display   Time label or ''.
 * @var string $venue
 * @var string $event_type     online | in-person | hybrid
 * @var string $type_label
 * @var string $price_label
 * @var bool   $is_free
 * @var bool   $is_featured
 * @var string $cta_label
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-event-card<?php echo $is_featured ? ' is-featured' : ''; ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<?php if ( $thumbnail ) : ?>
		<div class="blt-event-image">
			<a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true"><?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML. ?></a>
		</div>
	<?php endif; ?>

	<div class="blt-event-content">
		<?php if ( $date_day ) : ?>
			<div class="blt-event-date-badge" aria-hidden="true">
				<span class="blt-date-month"><?php echo esc_html( $date_month ); ?></span>
				<span class="blt-date-day"><?php echo esc_html( $date_day ); ?></span>
			</div>
		<?php endif; ?>

		<div class="blt-event-details">
			<?php if ( $is_featured ) : ?>
				<span class="blt-featured-badge"><?php esc_html_e( 'Featured', 'blt-events' ); ?></span>
			<?php endif; ?>

			<h3 class="blt-event-title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>

			<div class="blt-event-meta">
				<?php if ( $formatted_date ) : ?>
					<span class="blt-meta-date"><?php echo esc_html( $formatted_date ); ?></span>
				<?php endif; ?>
				<?php if ( $time_display ) : ?>
					<span class="blt-meta-time"><?php echo esc_html( $time_display ); ?></span>
				<?php endif; ?>
				<?php if ( $venue ) : ?>
					<span class="blt-meta-venue"><?php echo esc_html( $venue ); ?></span>
				<?php endif; ?>
				<?php if ( $type_label ) : ?>
					<span class="blt-meta-type blt-meta-type--<?php echo esc_attr( $event_type ); ?>"><?php echo esc_html( $type_label ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $excerpt ) : ?>
				<p class="blt-event-excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>

			<div class="blt-event-footer">
				<span class="blt-event-price<?php echo $is_free ? ' blt-free' : ''; ?>"><?php echo esc_html( $price_label ); ?></span>
				<a href="<?php echo esc_url( $permalink ); ?>" class="blt-btn-register"><?php echo esc_html( $cta_label ); ?></a>
			</div>
		</div>
	</div>
</div>
