<?php
/**
 * Presenters card in the single event sidebar.
 *
 * Override: your-theme/blt-events/single/presenters.php
 *
 * @var int    $event_id
 * @var array  $presenters Rows with name, role, bio, photo, url.
 * @var string $label      "Presenter" or "Presenters".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $presenters ) ) {
	return;
}
?>
<div class="blt-event__card blt-event__presenters">
	<h3 class="blt-event__card-title"><?php echo esc_html( $label ); ?></h3>
	<ul class="blt-event__presenter-list">
		<?php foreach ( $presenters as $p ) : ?>
			<li class="blt-event__presenter">
				<?php if ( $p['photo'] ) : ?>
					<img class="blt-event__presenter-photo" src="<?php echo esc_url( $p['photo'] ); ?>" alt="<?php echo esc_attr( $p['name'] ); ?>" loading="lazy" />
				<?php endif; ?>
				<span class="blt-event__presenter-meta">
					<?php if ( $p['url'] ) : ?>
						<a class="blt-event__presenter-name" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
					<?php else : ?>
						<span class="blt-event__presenter-name"><?php echo esc_html( $p['name'] ); ?></span>
					<?php endif; ?>
					<?php if ( $p['role'] ) : ?>
						<span class="blt-event__presenter-role"><?php echo esc_html( $p['role'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $p['bio'] ) ) : ?>
						<span class="blt-event__presenter-bio"><?php echo esc_html( $p['bio'] ); ?></span>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
