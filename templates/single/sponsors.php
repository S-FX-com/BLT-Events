<?php
/**
 * Sponsor logo row.
 *
 * Override: your-theme/blt-events/single/sponsors.php
 *
 * @var array $sponsors Rows with image_id, src, url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $sponsors ) ) {
	return;
}
?>
<div class="blt-event__sponsors">
	<h2 class="blt-event__section-title"><?php esc_html_e( 'Sponsors', 'blt-events' ); ?></h2>
	<div class="blt-event__sponsor-list">
		<?php foreach ( $sponsors as $sponsor ) : ?>
			<?php if ( $sponsor['url'] ) : ?>
				<a class="blt-event__sponsor" href="<?php echo esc_url( $sponsor['url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url( $sponsor['src'] ); ?>" alt="" loading="lazy" />
				</a>
			<?php else : ?>
				<span class="blt-event__sponsor">
					<img src="<?php echo esc_url( $sponsor['src'] ); ?>" alt="" loading="lazy" />
				</span>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</div>
