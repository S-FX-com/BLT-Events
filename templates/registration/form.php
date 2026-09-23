<?php
/**
 * Registration checkout (Stripe or free events).
 *
 * Three steps: Registration (tickets), Attendee details, and Review &
 * payment. Events without ticket types show the details step only, and the
 * payment step is skipped whenever the order total is zero.
 *
 * Override: your-theme/blt-events/registration/form.php
 * Keep the data-* hooks: assets/js/registration-steps.js and
 * registration-form.js drive the flow through them.
 *
 * @var int      $event_id
 * @var WP_Post  $event
 * @var string   $provider          Payment provider slug ('none', 'stripe').
 * @var array    $fields            Registrant field definitions.
 * @var array    $consent_fields    Consent checkbox definitions.
 * @var array    $ticket_types      Purchasable tickets, keyed by original index.
 * @var array    $ticket_rows       Every listed ticket, keyed by original index: ticket, state, note, max.
 * @var bool     $has_paid_tickets
 * @var bool     $takes_payment     Card payment is collected on this page (Stripe).
 * @var bool     $stepped           The event sells tickets (Registration step shown).
 * @var bool     $collect_attendees Ask for each additional attendee's details.
 * @var array    $attendee_fields   Field definitions for additional attendees.
 * @var string   $nonce
 * @var int|null $spots_left        Seats left, or null when unlimited.
 * @var bool     $show_coupon
 * @var string   $member_login_url  "Log in for Member Rates" link, or '' when there is nothing to unlock.
 * @var array    $summary           Event summary: title, image, date_label, time_label, location.
 * @var string   $help_email        Contact address for the help box, or ''.
 * @var bool     $secure            The page is served over HTTPS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$first_step = $stepped ? 'tickets' : 'details';
?>
<div class="blt-registration-form blt-checkout" id="blt-registration-<?php echo esc_attr( $event_id ); ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
	<form id="blt-registration-form" class="blt-reg<?php echo $stepped ? ' blt-reg--stepped' : ''; ?>" method="post" novalidate
		data-stepped="<?php echo $stepped ? '1' : '0'; ?>"
		data-collect-attendees="<?php echo $collect_attendees ? '1' : '0'; ?>"
		data-takes-payment="<?php echo $takes_payment ? '1' : '0'; ?>"
		data-current-step="<?php echo esc_attr( $first_step ); ?>"
		data-total="0">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

		<?php if ( $stepped ) : ?>
		<ol class="blt-reg__progress" aria-label="<?php esc_attr_e( 'Registration progress', 'blt-events' ); ?>">
			<li class="blt-reg__progress-step is-current" data-progress="tickets"><?php esc_html_e( 'Registration', 'blt-events' ); ?></li>
			<li class="blt-reg__progress-step" data-progress="details"><?php esc_html_e( 'Attendee details', 'blt-events' ); ?></li>
			<li class="blt-reg__progress-step" data-progress="review" <?php echo $takes_payment ? '' : 'hidden'; ?>><?php esc_html_e( 'Review & payment', 'blt-events' ); ?></li>
		</ol>
		<?php endif; ?>

		<?php /* ---------------------------------------------------- Step headers */ ?>
		<?php if ( $stepped ) : ?>
		<header class="blt-reg__header" data-step-panel="tickets">
			<h3 class="blt-reg__title"><?php esc_html_e( 'Registration', 'blt-events' ); ?></h3>
			<p class="blt-reg__subtitle"><?php esc_html_e( 'Choose the ticket type and quantity for this event', 'blt-events' ); ?></p>
		</header>
		<?php endif; ?>
		<header class="blt-reg__header" data-step-panel="details">
			<?php if ( $stepped ) : ?>
			<h3 class="blt-reg__title"><?php esc_html_e( 'Attendee details', 'blt-events' ); ?></h3>
			<p class="blt-reg__subtitle"><?php echo $collect_attendees ? esc_html__( 'Please provide the details for each attendee.', 'blt-events' ) : esc_html__( 'Please provide your details.', 'blt-events' ); ?></p>
			<?php else : ?>
			<h3 class="blt-reg__title"><?php esc_html_e( 'Your details', 'blt-events' ); ?></h3>
			<p class="blt-reg__subtitle"><?php esc_html_e( 'Please provide your details to register for this event.', 'blt-events' ); ?></p>
			<?php endif; ?>
		</header>
		<header class="blt-reg__header" data-step-panel="review">
			<h3 class="blt-reg__title"><?php esc_html_e( 'Review & payment', 'blt-events' ); ?></h3>
			<p class="blt-reg__subtitle"><?php esc_html_e( 'Please review your information and complete your payment to secure your tickets.', 'blt-events' ); ?></p>
		</header>

		<div class="blt-reg__body">
			<div class="blt-reg__main">

				<?php if ( $stepped ) : ?>
				<?php /* ------------------------------------------ Step 1: Registration */ ?>
				<section class="blt-reg__step blt-ticket-selection" data-step="tickets" data-step-panel="tickets">
					<?php
					if ( $member_login_url ) {
						BLT_Events_Templates::include_template( 'registration/member-login.php', array(
							'event_id'  => $event_id,
							'login_url' => $member_login_url,
						) );
					}
					?>

					<?php if ( null !== $spots_left && $spots_left <= 10 ) : ?>
						<p class="blt-spots-left">
							<?php
							printf(
								/* translators: %d: number of spots left. */
								esc_html( _n( 'Only %d spot left', 'Only %d spots left', $spots_left, 'blt-events' ) ),
								(int) $spots_left
							);
							?>
						</p>
					<?php endif; ?>

					<div class="blt-reg__card blt-ticket-table">
						<div class="blt-ticket-header" aria-hidden="true">
							<span><?php esc_html_e( 'Ticket type', 'blt-events' ); ?></span>
							<span><?php esc_html_e( 'Description', 'blt-events' ); ?></span>
							<span><?php esc_html_e( 'Price', 'blt-events' ); ?></span>
							<span><?php esc_html_e( 'Quantity', 'blt-events' ); ?></span>
							<span><?php esc_html_e( 'Total', 'blt-events' ); ?></span>
						</div>
						<?php foreach ( $ticket_rows as $i => $row ) : ?>
							<?php
							$ticket = $row['ticket'];
							$price  = isset( $ticket['price'] ) ? (float) $ticket['price'] : 0;
							?>
						<div class="blt-ticket-type blt-ticket-type--<?php echo esc_attr( $row['state'] ); ?>" data-ticket-index="<?php echo (int) $i; ?>">
							<span class="blt-ticket-name">
								<?php if ( 'members' === $row['state'] ) : ?>
									<?php echo BLT_Events_Templates::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
								<?php endif; ?>
								<?php echo esc_html( $ticket['name'] ); ?>
							</span>
							<span class="blt-ticket-desc"><?php echo esc_html( $ticket['description'] ?? '' ); ?></span>
							<span class="blt-ticket-price"><?php echo $price > 0 ? esc_html( BLT_Events_Helpers::format_price( $price ) ) : esc_html__( 'Free', 'blt-events' ); ?></span>
							<div class="blt-ticket-qty">
								<?php if ( 'available' === $row['state'] ) : ?>
								<div class="blt-quantity-controls">
									<button type="button" class="blt-qty-btn minus-btn" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket name. */ __( 'Remove one %s', 'blt-events' ), $ticket['name'] ) ); ?>">&minus;</button>
									<input type="number" name="ticket_quantity_<?php echo (int) $i; ?>" class="blt-ticket-quantity" value="0" min="0" max="<?php echo (int) $row['max']; ?>" inputmode="numeric" data-price="<?php echo esc_attr( $price ); ?>" data-index="<?php echo (int) $i; ?>" data-name="<?php echo esc_attr( $ticket['name'] ); ?>" data-description="<?php echo esc_attr( $ticket['description'] ?? '' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket name. */ __( 'Quantity for %s', 'blt-events' ), $ticket['name'] ) ); ?>" />
									<button type="button" class="blt-qty-btn plus-btn" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket name. */ __( 'Add one %s', 'blt-events' ), $ticket['name'] ) ); ?>">+</button>
								</div>
								<?php elseif ( 'members' === $row['state'] && $member_login_url ) : ?>
								<a class="blt-ticket-pill blt-ticket-pill--members" href="<?php echo esc_url( $member_login_url ); ?>"><?php echo esc_html( $row['note'] ); ?></a>
								<?php else : ?>
								<span class="blt-ticket-pill"><?php echo esc_html( $row['note'] ); ?></span>
								<?php endif; ?>
							</div>
							<span class="blt-ticket-line-total" data-line-total><?php echo 'available' === $row['state'] ? esc_html( BLT_Events_Helpers::format_price( 0 ) ) : '&ndash;'; ?></span>
						</div>
						<?php endforeach; ?>
					</div>

					<div class="blt-reg__tickets-footer">
						<dl class="blt-reg__tally">
							<div>
								<dt><?php esc_html_e( 'Selected tickets', 'blt-events' ); ?></dt>
								<dd data-blt-selected-count><?php echo esc_html( sprintf( /* translators: %d: number of tickets. */ __( '%d tickets', 'blt-events' ), 0 ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Subtotal', 'blt-events' ); ?></dt>
								<dd data-blt-subtotal><?php echo esc_html( BLT_Events_Helpers::format_price( 0 ) ); ?></dd>
							</div>
						</dl>
						<p class="blt-reg__step-error" data-blt-step-error role="alert" hidden></p>
						<button type="submit" class="blt-reg__btn blt-reg__btn--primary" data-blt-next>
							<?php esc_html_e( 'Continue to attendee details', 'blt-events' ); ?>
							<?php echo BLT_Events_Templates::icon( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
						</button>
					</div>
				</section>
				<?php endif; ?>

				<?php /* ---------------------------------------- Step 2: Attendee details */ ?>
				<section class="blt-reg__step blt-reg__details" data-step="details" data-step-panel="details">
					<div class="blt-reg__card blt-attendee-card" data-blt-attendee-card="primary" role="group" aria-labelledby="blt-attendee-primary-title-<?php echo esc_attr( $event_id ); ?>">
						<div class="blt-attendee-card__head">
							<h4 class="blt-attendee-card__title" id="blt-attendee-primary-title-<?php echo esc_attr( $event_id ); ?>">
								<?php echo $collect_attendees ? esc_html( sprintf( /* translators: %d: attendee number. */ __( 'Attendee %d', 'blt-events' ), 1 ) ) : esc_html__( 'Your details', 'blt-events' ); ?>
							</h4>
							<?php if ( $collect_attendees ) : ?>
							<span class="blt-attendee-card__ticket" data-blt-seat-ticket hidden></span>
							<span class="blt-attendee-card__price" data-blt-seat-price></span>
							<?php endif; ?>
						</div>
						<div class="blt-fields-grid">
							<?php foreach ( $fields as $field ) : ?>
								<?php echo BLT_Events_Fieldsets::render_field( $field, BLT_Events_Fieldsets::prefill_value( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field(). ?>
							<?php endforeach; ?>
						</div>
						<?php if ( $collect_attendees ) : ?>
						<div class="blt-attendee-card__foot" data-blt-seat-foot hidden>
							<span></span>
							<span class="blt-attendee-card__count" data-blt-seat-count></span>
						</div>
						<?php endif; ?>
					</div>

					<?php if ( $collect_attendees ) : ?>
					<div class="blt-attendees-list" data-blt-attendees-list></div>
					<script type="text/html" id="tmpl-blt-attendee">
						<?php
						BLT_Events_Templates::include_template( 'registration/attendee.php', array(
							'index'  => '__i__',
							'fields' => $attendee_fields,
						) );
						?>
					</script>
					<?php endif; ?>

					<?php if ( ! empty( $consent_fields ) ) : ?>
					<div class="blt-reg__card blt-consent-section">
						<?php foreach ( $consent_fields as $cf ) : ?>
						<div class="blt-consent-field">
							<label>
								<input type="checkbox" name="consent_<?php echo esc_attr( $cf['key'] ); ?>" value="1" <?php echo ! empty( $cf['required'] ) ? 'required' : ''; ?> />
								<span><?php echo wp_kses_post( $cf['label'] ); ?><?php if ( ! empty( $cf['required'] ) ) : ?> <span class="blt-required" aria-hidden="true">*</span><?php endif; ?></span>
							</label>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<?php if ( $stepped ) : ?>
					<button type="button" class="blt-reg__back-link" data-blt-go="tickets">
						<?php echo BLT_Events_Templates::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
						<?php esc_html_e( 'Back to Registration', 'blt-events' ); ?>
					</button>
					<?php endif; ?>
				</section>

				<?php /* ----------------------------------------- Step 3: Review & payment */ ?>
				<section class="blt-reg__step blt-reg__review" data-step="review" data-step-panel="review">
					<div class="blt-reg__card blt-review-card">
						<div class="blt-review-card__head">
							<h4 class="blt-review-card__title"><?php esc_html_e( 'Ticket summary', 'blt-events' ); ?></h4>
							<?php if ( $stepped ) : ?>
							<button type="button" class="blt-reg__edit" data-blt-go="tickets">
								<?php echo BLT_Events_Templates::icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
								<?php esc_html_e( 'Edit tickets', 'blt-events' ); ?>
							</button>
							<?php endif; ?>
						</div>
						<div class="blt-review-table">
							<div class="blt-review-table__row blt-review-table__row--head" aria-hidden="true">
								<span><?php esc_html_e( 'Ticket type', 'blt-events' ); ?></span>
								<span><?php esc_html_e( 'Price', 'blt-events' ); ?></span>
								<span><?php esc_html_e( 'Quantity', 'blt-events' ); ?></span>
								<span><?php esc_html_e( 'Total', 'blt-events' ); ?></span>
							</div>
							<div data-blt-review-tickets></div>
						</div>
						<dl class="blt-review-totals">
							<div>
								<dt><?php esc_html_e( 'Subtotal', 'blt-events' ); ?></dt>
								<dd data-blt-review-subtotal></dd>
							</div>
							<div data-blt-review-discount-row hidden>
								<dt><?php esc_html_e( 'Discount', 'blt-events' ); ?></dt>
								<dd data-blt-review-discount></dd>
							</div>
						</dl>
					</div>

					<div class="blt-reg__card blt-review-card">
						<div class="blt-review-card__head">
							<h4 class="blt-review-card__title"><?php esc_html_e( 'Attendee details', 'blt-events' ); ?></h4>
							<button type="button" class="blt-reg__edit" data-blt-go="details">
								<?php echo BLT_Events_Templates::icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
								<?php esc_html_e( 'Edit attendee details', 'blt-events' ); ?>
							</button>
						</div>
						<ol class="blt-review-attendees" data-blt-review-attendees></ol>
					</div>

					<?php if ( $takes_payment ) : ?>
					<div class="blt-reg__card blt-payment-section" id="blt-payment-section">
						<div class="blt-review-card__head">
							<h4 class="blt-review-card__title"><?php esc_html_e( 'Payment method', 'blt-events' ); ?></h4>
						</div>
						<div class="blt-payment">
							<div class="blt-payment__method">
								<span class="blt-payment__radio" aria-hidden="true"></span>
								<span class="blt-payment__method-text">
									<span class="blt-payment__method-label"><?php esc_html_e( 'Credit or debit card', 'blt-events' ); ?></span>
									<span class="blt-payment__brands" aria-hidden="true">
										<span class="blt-payment__brand">VISA</span>
										<span class="blt-payment__brand">Mastercard</span>
										<span class="blt-payment__brand">AMEX</span>
									</span>
								</span>
							</div>
							<div class="blt-payment__fields">
								<div class="blt-field-wrap blt-field-full">
									<label for="blt-cardholder-name-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Cardholder name', 'blt-events' ); ?> <span class="blt-required" aria-hidden="true">*</span></label>
									<?php // No name attribute: the card holder goes to Stripe, never to this site. ?>
									<input type="text" id="blt-cardholder-name-<?php echo esc_attr( $event_id ); ?>" class="blt-cardholder-name" data-blt-cardholder autocomplete="cc-name" required />
								</div>
								<div class="blt-field-wrap blt-field-full">
									<span class="blt-label" id="blt-card-number-label-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Card number', 'blt-events' ); ?> <span class="blt-required" aria-hidden="true">*</span></span>
									<div id="blt-card-number" class="blt-stripe-field" aria-labelledby="blt-card-number-label-<?php echo esc_attr( $event_id ); ?>"></div>
								</div>
								<div class="blt-field-wrap blt-field-half">
									<span class="blt-label" id="blt-card-expiry-label-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Expiration date', 'blt-events' ); ?> <span class="blt-required" aria-hidden="true">*</span></span>
									<div id="blt-card-expiry" class="blt-stripe-field" aria-labelledby="blt-card-expiry-label-<?php echo esc_attr( $event_id ); ?>"></div>
								</div>
								<div class="blt-field-wrap blt-field-half">
									<span class="blt-label" id="blt-card-cvc-label-<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'CVC', 'blt-events' ); ?> <span class="blt-required" aria-hidden="true">*</span></span>
									<div id="blt-card-cvc" class="blt-stripe-field" aria-labelledby="blt-card-cvc-label-<?php echo esc_attr( $event_id ); ?>"></div>
								</div>
							</div>
							<div id="blt-card-errors" role="alert"></div>
						</div>
					</div>
					<?php endif; ?>

					<button type="button" class="blt-reg__back-link" data-blt-go="details">
						<?php echo BLT_Events_Templates::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
						<?php esc_html_e( 'Back to Attendee details', 'blt-events' ); ?>
					</button>
				</section>

				<div class="blt-reg__card blt-reg__complete" data-blt-complete data-step-panel="complete" hidden>
					<span class="blt-reg__complete-icon"><?php echo BLT_Events_Templates::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<h3 class="blt-reg__title" data-blt-complete-title><?php esc_html_e( 'You are registered', 'blt-events' ); ?></h3>
					<p data-blt-complete-message></p>
				</div>
			</div>

			<?php
			BLT_Events_Templates::include_template( 'registration/summary.php', array(
				'event_id'    => $event_id,
				'summary'     => $summary,
				'stepped'     => $stepped,
				'show_coupon' => $show_coupon,
				'help_email'  => $help_email,
				'secure'      => $secure,
			) );
			?>
		</div>
	</form>
</div>
