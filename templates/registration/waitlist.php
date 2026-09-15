<?php
/**
 * Waitlist sign-up, shown when a sold-out event has its waitlist enabled.
 *
 * Override: your-theme/blt-events/registration/waitlist.php
 *
 * @var int     $event_id
 * @var WP_Post $event
 * @var string  $nonce
 * @var int     $max_quantity
 * @var int     $waiting      People already on the waitlist.
 * @var string  $message      The "sold out" message.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-registration-form blt-waitlist" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<div class="blt-registration-closed blt-registration-closed--sold_out">
		<p><?php echo esc_html( $message ); ?></p>
	</div>

	<form id="blt-waitlist-form" class="blt-reg blt-reg--waitlist" method="post" novalidate>
		<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

		<h3 class="blt-reg__step-title"><?php esc_html_e( 'Join the waitlist', 'blt-events' ); ?></h3>
		<p class="blt-field-desc"><?php esc_html_e( 'Leave your details and we will email you if a spot opens up.', 'blt-events' ); ?></p>

		<div class="blt-fields-grid">
			<div class="blt-field-wrap blt-field-half">
				<label for="blt-wl-first-name"><?php esc_html_e( 'First Name', 'blt-events' ); ?> <span class="blt-required" aria-hidden="true">*</span></label>
				<input type="text" id="blt-wl-first-name" name="first_name" required />
			</div>
			<div class="blt-field-wrap blt-field-half">
				<label for="blt-wl-last-name"><?php esc_html_e( 'Last Name', 'blt-events' ); ?></label>
				<input type="text" id="blt-wl-last-name" name="last_name" />
			</div>
			<div class="blt-field-wrap blt-field-full">
				<label for="blt-wl-email"><?php esc_html_e( 'Email', 'blt-events' ); ?> <span class="blt-required" aria-hidden="true">*</span></label>
				<input type="email" id="blt-wl-email" name="email" required />
			</div>
			<?php if ( $max_quantity > 1 ) : ?>
			<div class="blt-field-wrap blt-field-full">
				<label for="blt-wl-quantity"><?php esc_html_e( 'How many seats?', 'blt-events' ); ?></label>
				<select id="blt-wl-quantity" name="quantity">
					<?php for ( $n = 1; $n <= $max_quantity; $n++ ) : ?>
						<option value="<?php echo (int) $n; ?>"><?php echo (int) $n; ?></option>
					<?php endfor; ?>
				</select>
			</div>
			<?php endif; ?>
		</div>

		<div id="blt-form-messages" role="status" aria-live="polite" hidden></div>

		<div class="blt-form-actions">
			<button type="submit" class="blt-submit-btn" id="blt-waitlist-submit"><?php esc_html_e( 'Join the waitlist', 'blt-events' ); ?></button>
		</div>
	</form>
</div>
