<?php
/**
 * Speakers (the event's presenters) inside the event card: a round photo,
 * name and role for each. A speaker with a bio gets a "Read full bio" button
 * that opens it in a <dialog> (assets/js/single-event.js), so several
 * speakers don't stretch the card. Rendered from the blt_events_single_sidebar
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

$presenters = array_values( $presenters );
?>
<div class="blt-event__presenters">
	<h3 class="blt-event__presenters-title"><?php echo esc_html( $label ); ?></h3>
	<ul class="blt-event__presenter-list" role="list">
		<?php foreach ( $presenters as $i => $p ) : ?>
			<?php $blt_events_initial = function_exists( 'mb_substr' ) ? mb_substr( (string) $p['name'], 0, 1 ) : substr( (string) $p['name'], 0, 1 ); ?>
			<li class="blt-event__presenter">
				<?php if ( ! empty( $p['photo'] ) ) : ?>
					<img class="blt-event__presenter-photo" src="<?php echo esc_url( $p['photo'] ); ?>" alt="" loading="lazy" width="60" height="60" />
				<?php else : ?>
					<span class="blt-event__presenter-photo blt-event__presenter-photo--initial" aria-hidden="true"><?php echo esc_html( $blt_events_initial ); ?></span>
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
					<?php if ( ! empty( $p['bio'] ) ) : ?>
						<button type="button" class="blt-event__presenter-more" data-blt-bio="blt-event-bio-<?php echo esc_attr( $event_id . '-' . $i ); ?>" aria-haspopup="dialog">
							<?php esc_html_e( 'Read full bio', 'blt-events' ); ?>
						</button>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php foreach ( $presenters as $i => $p ) : ?>
		<?php
		if ( empty( $p['bio'] ) ) {
			continue;
		}
		$blt_events_bio_id  = 'blt-event-bio-' . $event_id . '-' . $i;
		$blt_events_initial = function_exists( 'mb_substr' ) ? mb_substr( (string) $p['name'], 0, 1 ) : substr( (string) $p['name'], 0, 1 );
		?>
		<dialog class="blt-bio-dialog" id="<?php echo esc_attr( $blt_events_bio_id ); ?>" aria-labelledby="<?php echo esc_attr( $blt_events_bio_id ); ?>-name">
			<div class="blt-bio-dialog__inner">
				<div class="blt-bio-dialog__head">
					<?php if ( ! empty( $p['photo'] ) ) : ?>
						<img class="blt-event__presenter-photo" src="<?php echo esc_url( $p['photo'] ); ?>" alt="" loading="lazy" width="72" height="72" />
					<?php else : ?>
						<span class="blt-event__presenter-photo blt-event__presenter-photo--initial" aria-hidden="true"><?php echo esc_html( $blt_events_initial ); ?></span>
					<?php endif; ?>
					<div class="blt-bio-dialog__heading">
						<h2 class="blt-bio-dialog__name" id="<?php echo esc_attr( $blt_events_bio_id ); ?>-name">
							<?php if ( ! empty( $p['url'] ) ) : ?>
								<a href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $p['name'] ); ?>
							<?php endif; ?>
						</h2>
						<?php if ( ! empty( $p['role'] ) ) : ?>
							<p class="blt-bio-dialog__role"><?php echo esc_html( $p['role'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<div class="blt-bio-dialog__body">
					<?php echo wp_kses_post( wpautop( esc_html( $p['bio'] ) ) ); ?>
				</div>
			</div>
			<form method="dialog">
				<button type="submit" class="blt-lightbox__btn blt-lightbox__close" aria-label="<?php esc_attr_e( 'Close', 'blt-events' ); ?>">&times;</button>
			</form>
		</dialog>
	<?php endforeach; ?>
</div>
