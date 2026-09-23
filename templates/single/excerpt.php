<?php
/**
 * Short summary in the event card. Events no longer have an Excerpt box, so
 * this only shows an excerpt saved before that change.
 *
 * Override: your-theme/blt-events/single/excerpt.php
 *
 * @var string $excerpt
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $excerpt ) ) {
	return;
}
?>
<p class="blt-event__excerpt"><?php echo esc_html( $excerpt ); ?></p>
