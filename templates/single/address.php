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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $is_physical || '' === $address || ! $map_src ) {
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
