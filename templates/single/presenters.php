<?php
/**
 * Speakers (the event's presenters) inside the event card: a round photo,
 * name and role for each. Rendered from the blt_events_single_sidebar
 * action by BLT_Events_Presenters::render_sidebar().
 *
 * Override: your-theme/blt-events/single/presenters.php
 *
 * @var int    $event_id
 * @var array  $presenters Rows with name, role, bio, photo, url.
 * @var string $label      "Speaker" or "Speakers".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $presenters ) ) {
	return;
}
?>
<div class="blt-event__presenters">
	<h3 class="blt-event__presenters-title"><?php echo esc_html( $label ); ?></h3>
	<ul class="blt-event__presenter-list" role="list">
		<?php foreach ( $presenters as $p ) : ?>
			<li class="blt-event__presenter">
				<?php if ( ! empty( $p['photo'] ) ) : ?>
					<img class="blt-event__presenter-photo" src="<?php echo esc_url( $p['photo'] ); ?>" alt="" loading="lazy" width="60" height="60" />
				<?php else : ?>
					<span class="blt-event__presenter-photo blt-event__presenter-photo--initial" aria-hidden="true"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( (string) $p['name'], 0, 1 ) : substr( (string) $p['name'], 0, 1 ) ); ?></span>
				<?php endif; ?>
				<span class="blt-event__presenter-meta">
					<?php if ( ! empty( $p['url'] ) ) : ?>
						<a class="blt-event__presenter-name" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
					<?php else : ?>
						<span class="blt-event__presenter-name"><?php echo esc_html( $p['name'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $p['role'] ) ) : ?>
						<span class="blt-event__presenter-role"><?php echo esc_html( $p['role'] ); ?></span>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
