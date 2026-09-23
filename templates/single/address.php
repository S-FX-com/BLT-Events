<?php
/**
 * Location section with the venue map, in the main column. Shown for
 * in-person and hybrid events when a map is available; the address itself
 * is always listed in the event card.
 *
 * Override: your-theme/blt-events/single/address.php
 *
 * @var int    $event_id
 * @var bool   $is_physical
 * @var string $address
 * @var string $map_src     Map iframe URL, or '' when maps are off or the venue has no coordinates.
 * @var bool   $card_info   Set by the current single-event.php, which lists the address in single/info.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $is_physical || '' === $address ) {
	return;
}

// A theme copy of the pre-2.5 single-event.php puts this part in its
// sidebar and has no facts list: keep the address card it expects.
if ( empty( $card_info ) ) :
	?>
	<div class="blt-event__card blt-event__address">
		<h3 class="blt-event__card-title"><?php esc_html_e( 'Address', 'blt-events' ); ?></h3>
		<p class="blt-event__address-text"><?php echo esc_html( $address ); ?></p>
		<?php if ( $map_src ) : ?>
			<div class="blt-event__map">
				<iframe title="<?php esc_attr_e( 'Event location map', 'blt-events' ); ?>" src="<?php echo esc_url( $map_src ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;

if ( ! $map_src ) {
	return;
}
?>
<section class="blt-event__section blt-event__address" aria-labelledby="blt-event-location-<?php echo esc_attr( $event_id ); ?>">
	<h2 class="blt-event__section-title" id="blt-event-location-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Location', 'blt-events' ); ?></h2>
	<p class="blt-event__address-text"><?php echo esc_html( $address ); ?></p>
	<div class="blt-event__map">
		<iframe title="<?php esc_attr_e( 'Event location map', 'blt-events' ); ?>" src="<?php echo esc_url( $map_src ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
	</div>
</section>
