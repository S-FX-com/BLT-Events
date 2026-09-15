<?php
/**
 * Price summary + call to action that jumps to the registration panel.
 *
 * Override: your-theme/blt-events/single/cta.php
 *
 * @var bool     $has_paid
 * @var string   $price_from  Formatted lowest paid price.
 * @var string   $cta_label
 * @var string   $cta_url
 * @var bool     $registration_open
 * @var bool     $is_sold_out
 * @var int|null $spots_left
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-event__card blt-event__cta">
	<div class="blt-event__cta-price">
		<?php if ( $has_paid && $price_from ) : ?>
			<span class="blt-event__cta-from"><?php esc_html_e( 'FROM', 'blt-events' ); ?></span>
			<span class="blt-event__cta-amount"><?php echo esc_html( $price_from ); ?></span>
		<?php else : ?>
			<span class="blt-event__cta-amount"><?php esc_html_e( 'Free', 'blt-events' ); ?></span>
		<?php endif; ?>
	</div>
	<?php if ( ! $registration_open ) : ?>
		<span class="blt-event__cta-button is-disabled" aria-disabled="true"><?php esc_html_e( 'Registration closed', 'blt-events' ); ?></span>
	<?php elseif ( $is_sold_out ) : ?>
		<a class="blt-event__cta-button is-soldout" href="<?php echo esc_url( $cta_url ); ?>"><?php esc_html_e( 'Sold out', 'blt-events' ); ?></a>
	<?php else : ?>
		<a class="blt-event__cta-button" href="<?php echo esc_url( $cta_url ); ?>">
			<?php echo esc_html( $cta_label ); ?> <span aria-hidden="true">&rarr;</span>
		</a>
	<?php endif; ?>
</div>
