<?php
/**
 * Address card with optional map, for in-person and hybrid events.
 *
 * Override: your-theme/blt-events/single/address.php
 *
 * @var bool   $is_physical
 * @var string $address
 * @var string $map_src
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $is_physical || '' === $address ) {
	return;
}
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
