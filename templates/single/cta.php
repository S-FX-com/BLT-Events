<?php
/**
 * Full-width register button at the bottom of the event card. It jumps to
 * the registration form, or reads "Registration closed" / "Sold out".
 *
 * Override: your-theme/blt-events/single/cta.php
 *
 * @var string   $cta_label
 * @var string   $cta_url
 * @var bool     $registration_open
 * @var bool     $is_sold_out
 * @var bool     $has_paid
 * @var string   $price_from  Formatted lowest paid price (shown in single/info.php).
 * @var int|null $spots_left
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-event__cta">
	<?php if ( ! $registration_open ) : ?>
		<span class="blt-event__cta-button is-disabled" aria-disabled="true"><?php esc_html_e( 'Registration closed', 'blt-events' ); ?></span>
	<?php elseif ( $is_sold_out ) : ?>
		<a class="blt-event__cta-button is-soldout" href="<?php echo esc_url( $cta_url ); ?>"><?php esc_html_e( 'Sold out', 'blt-events' ); ?></a>
	<?php else : ?>
		<a class="blt-event__cta-button" href="<?php echo esc_url( $cta_url ); ?>">
			<?php echo esc_html( $cta_label ); ?>
			<?php echo BLT_Events_Templates::icon( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
		</a>
	<?php endif; ?>
</div>
