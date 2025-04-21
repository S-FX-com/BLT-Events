<?php
class Obie_Events_Shortcodes
{
	public static function init()
	{
		add_shortcode('Obie_Event_Registration', array(__CLASS__, 'registration_shortcode'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
	}

	public static function enqueue_scripts()
	{
		wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
		wp_enqueue_script('obie-events-payment', OBIE_EVENTS_PLUGIN_URL . 'assets/js/payment.js', array('jquery', 'obie-events-js', 'stripe-js'), '1.0', true);

		wp_localize_script('obie-events-payment', 'obieEventPaymentData', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'stripeKey' => get_option('obie_events_stripe_publishable_key')
		));
	}

	public static function registration_shortcode($atts)
	{
		$atts = shortcode_atts(array(
			'id' => get_the_ID(),
		), $atts, 'Obie_Event_Registration');

		$event_id = intval($atts['id']);
		$by_tickets = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_by_tickets', true);
		$ticket_types = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);

		ob_start(); ?>
		<form id="obie-events-registration-form" class="obie-events-registration-form">
			<input type="hidden" name="event_id" value="<?php echo $event_id; ?>" />
			<?php wp_nonce_field(Obie_Events_Registrations::$nonce, Obie_Events_Registrations::$nonce); ?>

			<div class="form-row">
				<label for="customer_name">Name</label>
				<input type="text" id="customer_name" name="customer_name" required />
			</div>

			<div class="form-row">
				<label for="customer_email">Email</label>
				<input type="email" id="customer_email" name="customer_email" required />
			</div>

			<?php if ($by_tickets && !empty($ticket_types)) : ?>
				<div class="ticket-selection">
					<h4>Select Tickets</h4>
					<?php foreach ($ticket_types as $index => $ticket) : ?>
						<div class="ticket-type">
							<span class="ticket-name"><?php echo esc_html($ticket['name']); ?></span>
							<div class="ticket-quantity-controls">
								<button type="button" class="quantity-btn minus-btn" data-index="<?php echo $index; ?>">-</button>
								<input type="number"
									name="ticket_quantity[]"
									data-index="<?php echo $index; ?>"
									data-price="<?php echo $ticket['price']; ?>"
									min="0"
									value="0"
									class="ticket-quantity"
									readonly />
								<button type="button" class="quantity-btn plus-btn" data-index="<?php echo $index; ?>">+</button>
							</div>
							<span class="ticket-price"><?php echo Obie_Events_Helper::format_price($ticket['price']); ?></span>
						</div>
					<?php endforeach; ?>

					<div class="coupon-section">
						<?php wp_nonce_field(Obie_Events_Coupons::$nonce, Obie_Events_Coupons::$nonce); ?>
						<div id="coupon-form" class="form-row coupon-row">
							<label for="coupon_code"><?php _e('Coupon Code', OBIE_EVENTS_PLUGIN_PATH); ?></label>
							<div class="coupon-input-group">
								<input type="text" id="coupon_code" name="coupon_code" placeholder="<?php _e('Enter coupon code', OBIE_EVENTS_PLUGIN_PATH); ?>" />
								<button type="button" id="obie-events-apply-coupon" class="coupon-button"><?php _e('Apply', OBIE_EVENTS_PLUGIN_PATH); ?></button>
							</div>
						</div>
						<div id="coupon-discount" class="coupon-discount" style="display: none;">
							<input type="hidden" name="applied_coupon" id="applied_coupon" value="" />
							<span class="coupon-discount-amount"></span>
							<button type="button" id="obie-events-remove-coupon" class="remove-coupon"><?php _e('Remove', OBIE_EVENTS_PLUGIN_PATH); ?></button>
						</div>
						<div id="coupon-message"></div>
					</div>

					<p class="total-amount">
						<?php echo Obie_Events_Helper::format_price(0, true); ?>
					</p>
				</div>

				<div id="card-element"></div>
				<div id="card-errors" role="alert"></div>
			<?php endif; ?>

			<button type="submit" id="obie-events-reserve-button" class="submit-button">
				Reserve now
			</button>
		</form>
		<?php return ob_get_clean();
	}
}
