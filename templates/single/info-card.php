<?php
/**
 * Sidebar info card: date, excerpt, address, speakers and the register CTA,
 * consolidated into one sticky card instead of four separate ones.
 *
 * Override: your-theme/blt-events/single/info-card.php
 *
 * @var string   $day
 * @var string   $date_label
 * @var string   $time_label
 * @var bool     $show_calendar
 * @var string   $ics_url
 * @var string   $google_url
 * @var string   $excerpt
 * @var bool     $is_physical
 * @var string   $address
 * @var string   $map_src
 * @var array    $presenters       Rows with name, role, bio, photo, url.
 * @var string   $presenters_label "Speaker" or "Speakers".
 * @var bool     $has_paid
 * @var string   $price_from
 * @var string   $cta_label
 * @var string   $cta_url
 * @var bool     $registration_open
 * @var bool     $is_sold_out
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-event__card blt-event__info-card">
	<?php if ( '' !== $day || '' !== $date_label ) : ?>
		<div class="blt-event__datebox-head">
			<?php if ( '' !== $day ) : ?>
				<span class="blt-event__datebox-day" aria-hidden="true"><span><?php echo esc_html( $day ); ?></span></span>
			<?php endif; ?>
			<span class="blt-event__datebox-meta">
				<span class="blt-event__datebox-label"><?php esc_html_e( 'Event Date', 'blt-events' ); ?></span>
				<span class="blt-event__datebox-value"><?php echo esc_html( $date_label ); ?></span>
			</span>
		</div>
		<?php if ( $show_calendar && '' !== $date_label ) : ?>
			<p class="blt-event__calendar-links">
				<a class="blt-event__calendar-link" href="<?php echo esc_url( $ics_url ); ?>"><?php esc_html_e( 'Add to calendar', 'blt-events' ); ?></a>
				<span aria-hidden="true">·</span>
				<a class="blt-event__calendar-link" href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'blt-events' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( '' !== $excerpt ) : ?>
		<p class="blt-event__excerpt"><?php echo esc_html( $excerpt ); ?></p>
	<?php endif; ?>

	<?php if ( ( $is_physical && '' !== $address ) || '' !== $time_label ) : ?>
		<ul class="blt-event__info-list">
			<?php if ( $is_physical && '' !== $address ) : ?>
				<li class="blt-event__info-item">
					<?php echo BLT_Events_Helpers::pin_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span><?php echo esc_html( $address ); ?></span>
				</li>
			<?php endif; ?>
			<?php if ( '' !== $time_label ) : ?>
				<li class="blt-event__info-item">
					<?php echo BLT_Events_Helpers::clock_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span><?php echo esc_html( $time_label ); ?></span>
				</li>
			<?php endif; ?>
		</ul>
		<?php if ( $is_physical && $map_src ) : ?>
			<div class="blt-event__map">
				<iframe title="<?php esc_attr_e( 'Event location map', 'blt-events' ); ?>" src="<?php echo esc_url( $map_src ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $presenters ) ) : ?>
		<div class="blt-event__speakers">
			<h4 class="blt-event__speakers-title"><?php echo esc_html( $presenters_label ); ?>:</h4>
			<ul class="blt-event__speaker-list">
				<?php foreach ( $presenters as $p ) : ?>
					<li class="blt-event__speaker">
						<?php if ( $p['photo'] ) : ?>
							<img class="blt-event__speaker-photo" src="<?php echo esc_url( $p['photo'] ); ?>" alt="<?php echo esc_attr( $p['name'] ); ?>" loading="lazy" />
						<?php else : ?>
							<span class="blt-event__speaker-photo blt-event__speaker-photo--placeholder" aria-hidden="true">
								<?php echo BLT_Events_Helpers::person_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							</span>
						<?php endif; ?>
						<span class="blt-event__speaker-meta">
							<?php if ( $p['url'] ) : ?>
								<a class="blt-event__speaker-name" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
							<?php else : ?>
								<span class="blt-event__speaker-name"><?php echo esc_html( $p['name'] ); ?></span>
							<?php endif; ?>
							<?php if ( $p['role'] ) : ?>
								<span class="blt-event__speaker-role"><?php echo esc_html( $p['role'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $p['bio'] ) ) : ?>
								<span class="blt-event__speaker-bio"><?php echo esc_html( $p['bio'] ); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $has_paid && $price_from ) : ?>
		<p class="blt-event__cta-price">
			<span class="blt-event__cta-from"><?php esc_html_e( 'FROM', 'blt-events' ); ?></span>
			<span class="blt-event__cta-amount"><?php echo esc_html( $price_from ); ?></span>
		</p>
	<?php else : ?>
		<p class="blt-event__cta-price">
			<span class="blt-event__cta-amount"><?php esc_html_e( 'Free', 'blt-events' ); ?></span>
		</p>
	<?php endif; ?>

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
