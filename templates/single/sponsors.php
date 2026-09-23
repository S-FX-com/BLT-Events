<?php
/**
 * Sponsors section in the main column: a grid of square logo tiles. A logo
 * with a sponsor link opens the sponsor's site; one without opens larger in
 * the lightbox (assets/js/single-event.js).
 *
 * Override: your-theme/blt-events/single/sponsors.php
 *
 * @var int   $event_id
 * @var array $sponsors Rows with id (attachment ID), url (may be ''), full (image URL), alt.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $sponsors ) ) {
	return;
}
?>
<section class="blt-event__section blt-event__sponsors" aria-labelledby="blt-event-sponsors-<?php echo esc_attr( $event_id ); ?>">
	<h2 class="blt-event__section-title" id="blt-event-sponsors-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Sponsors', 'blt-events' ); ?></h2>
	<ul class="blt-event__sponsor-grid" role="list">
		<?php foreach ( $sponsors as $blt_events_sponsor ) : ?>
			<?php
			$blt_events_img = wp_get_attachment_image(
				(int) $blt_events_sponsor['id'],
				'medium',
				false,
				array(
					'class'   => 'blt-event__sponsor-img',
					'alt'     => (string) $blt_events_sponsor['alt'],
					'loading' => 'lazy',
				)
			);
			if ( ! $blt_events_img ) {
				$blt_events_img = '<img class="blt-event__sponsor-img" src="' . esc_url( $blt_events_sponsor['full'] ) . '" alt="' . esc_attr( $blt_events_sponsor['alt'] ) . '" loading="lazy" />';
			}
			?>
			<li class="blt-event__sponsor">
				<?php if ( ! empty( $blt_events_sponsor['url'] ) ) : ?>
					<a class="blt-event__sponsor-link" href="<?php echo esc_url( $blt_events_sponsor['url'] ); ?>" target="_blank" rel="noopener sponsored">
						<?php echo $blt_events_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML or escaped above. ?>
						<span class="blt-event__sr-only"><?php esc_html_e( '(opens in a new tab)', 'blt-events' ); ?></span>
					</a>
				<?php else : ?>
					<a class="blt-event__sponsor-link" href="<?php echo esc_url( $blt_events_sponsor['full'] ); ?>" data-blt-lightbox="sponsors-<?php echo esc_attr( $event_id ); ?>">
						<?php echo $blt_events_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML or escaped above. ?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
