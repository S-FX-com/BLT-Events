<?php
/**
 * Checkout sidebar: event summary, order totals and the primary action.
 * Shown beside the Attendee details and Review & payment steps.
 *
 * Override: your-theme/blt-events/registration/summary.php
 * The ticket lines and totals are filled in by registration-form.js.
 *
 * @var int    $event_id
 * @var array  $summary     title, image (HTML), date_label, time_label, location.
 * @var bool   $stepped     The event sells tickets.
 * @var bool   $show_coupon Show the coupon field.
 * @var string $help_email  Contact address for the help box, or ''.
 * @var bool   $secure      The page is served over HTTPS.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<aside class="blt-reg__aside" data-step-panel="details review" aria-label="<?php esc_attr_e( 'Order summary', 'blt-events' ); ?>">
	<div class="blt-reg__card blt-summary">
		<h4 class="blt-summary__title"><?php esc_html_e( 'Event summary', 'blt-events' ); ?></h4>

		<div class="blt-summary__event">
			<div class="blt-summary__thumb">
				<?php
				if ( ! empty( $summary['image'] ) ) {
					echo wp_kses_post( $summary['image'] );
				} else {
					echo BLT_Events_Templates::icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
				}
				?>
			</div>
			<div class="blt-summary__facts">
				<p class="blt-summary__name"><?php echo esc_html( $summary['title'] ); ?></p>
				<?php if ( ! empty( $summary['date_label'] ) || ! empty( $summary['time_label'] ) ) : ?>
				<p class="blt-summary__meta">
					<?php if ( ! empty( $summary['date_label'] ) ) : ?>
					<span><?php echo BLT_Events_Templates::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?> <?php echo esc_html( $summary['date_label'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $summary['time_label'] ) ) : ?>
					<span><?php echo BLT_Events_Templates::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?> <?php echo esc_html( $summary['time_label'] ); ?></span>
					<?php endif; ?>
				</p>
				<?php endif; ?>
				<?php if ( ! empty( $summary['location'] ) ) : ?>
				<p class="blt-summary__meta">
					<span><?php echo BLT_Events_Templates::icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?> <?php echo esc_html( $summary['location'] ); ?></span>
				</p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $stepped ) : ?>
		<div class="blt-summary__section" data-blt-summary-types hidden>
			<h5 class="blt-summary__label"><?php esc_html_e( 'Ticket type', 'blt-events' ); ?></h5>
			<ul class="blt-summary__lines" data-blt-summary-type-list></ul>
		</div>
		<div class="blt-summary__section" data-blt-summary-attendees hidden>
			<h5 class="blt-summary__label"><?php esc_html_e( 'Attendees', 'blt-events' ); ?></h5>
			<ul class="blt-summary__lines" data-blt-summary-attendee-list></ul>
		</div>
		<?php endif; ?>

		<?php if ( $show_coupon ) : ?>
		<div class="blt-summary__section blt-coupon-section">
			<label class="blt-summary__label" for="coupon_code"><?php esc_html_e( 'Coupon code', 'blt-events' ); ?></label>
			<div class="blt-coupon-input">
				<input type="text" id="coupon_code" name="coupon_code" placeholder="<?php esc_attr_e( 'Enter coupon code', 'blt-events' ); ?>" autocomplete="off" />
				<button type="button" id="blt-apply-coupon" class="blt-btn-secondary"><?php esc_html_e( 'Apply', 'blt-events' ); ?></button>
			</div>
			<div id="blt-coupon-message" role="status" aria-live="polite" hidden></div>
		</div>
		<?php endif; ?>

		<dl class="blt-summary__totals">
			<?php if ( $stepped ) : ?>
			<div class="blt-summary__row">
				<dt><?php esc_html_e( 'Subtotal', 'blt-events' ); ?></dt>
				<dd data-blt-summary-subtotal></dd>
			</div>
			<div class="blt-summary__row" data-blt-summary-discount-row hidden>
				<dt><?php esc_html_e( 'Discount', 'blt-events' ); ?></dt>
				<dd data-blt-summary-discount></dd>
			</div>
			<?php endif; ?>
			<div class="blt-summary__row blt-summary__row--total">
				<dt><?php esc_html_e( 'Total', 'blt-events' ); ?></dt>
				<dd data-blt-summary-total><?php esc_html_e( 'Free', 'blt-events' ); ?></dd>
			</div>
		</dl>

		<div id="blt-form-messages" role="status" aria-live="polite" hidden></div>

		<button type="submit" class="blt-reg__btn blt-reg__btn--primary blt-submit-btn" id="blt-submit-btn">
			<span data-blt-submit-label><?php esc_html_e( 'Complete registration', 'blt-events' ); ?></span>
			<?php echo BLT_Events_Templates::icon( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
		</button>

		<?php if ( $secure ) : ?>
		<p class="blt-summary__secure">
			<?php echo BLT_Events_Templates::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
			<?php esc_html_e( 'Your information is secure and encrypted.', 'blt-events' ); ?>
		</p>
		<?php endif; ?>
	</div>

	<?php if ( $help_email ) : ?>
	<div class="blt-reg__card blt-help">
		<span class="blt-help__icon"><?php echo BLT_Events_Templates::icon( 'help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
		<div>
			<p class="blt-help__title"><?php esc_html_e( 'Need help?', 'blt-events' ); ?></p>
			<p class="blt-help__text">
				<?php
				printf(
					/* translators: %s: contact email address, linked. */
					esc_html__( 'Our team can help at %s', 'blt-events' ),
					'<a href="' . esc_url( 'mailto:' . $help_email ) . '">' . esc_html( $help_email ) . '</a>'
				);
				?>
			</p>
		</div>
	</div>
	<?php endif; ?>
</aside>
