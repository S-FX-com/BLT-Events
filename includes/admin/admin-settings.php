<?php
class Obie_Events_Admin_Settings
{
	public static function init()
	{
		add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
	}

	public static function add_settings_page()
	{
		add_submenu_page(
			'edit.php?post_type=event',
			'Event Settings',
			'Settings',
			'manage_options',
			'obie-events-settings',
			array(__CLASS__, 'render_settings_page')
		);
	}

	public static function register_settings()
	{
		// Payment provider
		register_setting('obie_events_settings', 'obie_events_payment_provider');

		// Stripe settings
		register_setting('obie_events_settings', 'obie_events_stripe_secret_key');
		register_setting('obie_events_settings', 'obie_events_stripe_publishable_key');
		register_setting('obie_events_settings', 'obie_events_stripe_webhook_secret');

		// SureCart settings
		register_setting('obie_events_settings', 'obie_events_surecart_api_token');
		register_setting('obie_events_settings', 'obie_events_surecart_checkout_url');

		// Display settings
		register_setting('obie_events_settings', 'obie_events_date_format');
		register_setting('obie_events_settings', 'obie_events_currency');
		register_setting('obie_events_settings', 'obie_events_display_currency');
		register_setting('obie_events_settings', 'obie_events_display_currency_sign');

		// Email settings
		register_setting('obie_events_settings', 'obie_events_email_template_registration');
		register_setting('obie_events_settings', 'obie_events_email_template_reminder_24h');
		register_setting('obie_events_settings', 'obie_events_email_template_reminder_1h');
		register_setting('obie_events_settings', 'obie_events_email_subject_registration');
		register_setting('obie_events_settings', 'obie_events_email_subject_reminder_24h');
		register_setting('obie_events_settings', 'obie_events_email_subject_reminder_1h');

		if (get_option('obie_events_payment_provider') === false) {
			update_option('obie_events_payment_provider', 'stripe');
		}

		if (get_option('obie_events_date_format') === false) {
			update_option('obie_events_date_format', 'Y-m-d');
		}

		if (get_option('obie_events_currency') === false) {
			update_option('obie_events_currency', 'USD');
		}

		if (get_option('obie_events_display_currency') === false) {
			update_option('obie_events_display_currency', '1');
		}

		if (get_option('obie_events_display_currency_sign') === false) {
			update_option('obie_events_display_currency_sign', '1');
		}
	}

	public static function render_settings_page()
	{
?>
		<div class="wrap ovie_events">
			<h1>Obie Events Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields('obie_events_settings');
				$payment_provider = get_option('obie_events_payment_provider', 'stripe');
				?>
				<table class="form-table">
					<!-- Payment Provider Section -->
					<tr>
						<th colspan="2">
							<h2 class="title">Payment Provider</h2>
						</th>
					</tr>
					<tr>
						<th scope="row">Active Provider</th>
						<td>
							<fieldset>
								<label>
									<input type="radio"
										name="obie_events_payment_provider"
										value="stripe"
										<?php checked($payment_provider, 'stripe'); ?> />
									Stripe
								</label>
								<br>
								<label>
									<input type="radio"
										name="obie_events_payment_provider"
										value="surecart"
										<?php checked($payment_provider, 'surecart'); ?> />
									SureCart
								</label>
								<p class="description">
									Choose which payment provider to use for event ticket purchases.
									<strong>SureCart</strong> provides a unified shopping cart experience and is recommended if you use SureCart for your store.
								</p>
							</fieldset>
						</td>
					</tr>

					<!-- Stripe Settings Section -->
					<tr class="obie-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2">
							<h2 class="title">Stripe Settings</h2>
						</th>
					</tr>
					<tr class="obie-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Secret Key</th>
						<td>
							<input type="text"
								name="obie_events_stripe_secret_key"
								value="<?php echo esc_attr(get_option('obie_events_stripe_secret_key')); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr class="obie-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Publishable Key</th>
						<td>
							<input type="text"
								name="obie_events_stripe_publishable_key"
								value="<?php echo esc_attr(get_option('obie_events_stripe_publishable_key')); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr class="obie-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Webhook Secret</th>
						<td>
							<input type="text"
								name="obie_events_stripe_webhook_secret"
								value="<?php echo esc_attr(get_option('obie_events_stripe_webhook_secret')); ?>"
								class="regular-text" />
						</td>
					</tr>

					<!-- SureCart Settings Section -->
					<tr class="obie-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2">
							<h2 class="title">SureCart Settings</h2>
						</th>
					</tr>
					<tr class="obie-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">API Token</th>
						<td>
							<input type="password"
								name="obie_events_surecart_api_token"
								value="<?php echo esc_attr(get_option('obie_events_surecart_api_token')); ?>"
								class="regular-text" />
							<p class="description">
								Your SureCart secret API token. Find it in your
								<a href="https://app.surecart.com/dashboard" target="_blank" rel="noopener">SureCart dashboard</a>
								under API settings. If the SureCart plugin is installed and connected, this can be left blank.
							</p>
						</td>
					</tr>
					<tr class="obie-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Checkout Page URL</th>
						<td>
							<input type="url"
								name="obie_events_surecart_checkout_url"
								value="<?php echo esc_attr(get_option('obie_events_surecart_checkout_url')); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr(home_url('/checkout')); ?>" />
							<p class="description">
								The URL of your SureCart checkout page. Leave blank to auto-detect from SureCart settings.
							</p>
						</td>
					</tr>
					<tr class="obie-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Integration Status</th>
						<td>
							<?php
							$sc_active = class_exists('Obie_Events_SureCart') && Obie_Events_SureCart::is_surecart_plugin_active();
							$sc_configured = class_exists('Obie_Events_SureCart') && Obie_Events_SureCart::is_configured();
							?>
							<p>
								SureCart Plugin:
								<?php if ($sc_active) : ?>
									<span style="color: #059669; font-weight: 600;">Active</span>
								<?php else : ?>
									<span style="color: #dc2626; font-weight: 600;">Not Detected</span>
									— <a href="<?php echo esc_url(admin_url('plugin-install.php?s=surecart&tab=search&type=term')); ?>">Install SureCart</a>
								<?php endif; ?>
							</p>
							<p>
								API Connection:
								<?php if ($sc_configured) : ?>
									<span style="color: #059669; font-weight: 600;">Connected</span>
								<?php else : ?>
									<span style="color: #dc2626; font-weight: 600;">Not Connected</span>
									— Enter your API token above or connect the SureCart plugin.
								<?php endif; ?>
							</p>
							<p class="description">
								When SureCart is active, event tickets are automatically synced as SureCart products.
								Customers check out through SureCart's checkout page, and registrations are created automatically upon purchase.
								Coupons and discounts should be managed through SureCart's admin panel.
							</p>
						</td>
					</tr>

					<!-- Display Settings Section -->
					<tr>
						<th colspan="2">
							<h2 class="title">Display Settings</h2>
						</th>
					</tr>
					<tr>
						<th scope="row">Date Format</th>
						<td>
							<input type="text"
								name="obie_events_date_format"
								value="<?php echo esc_attr(get_option('obie_events_date_format')); ?>"
								class="regular-text" />
							<p class="description">Format for displaying dates (e.g., Y-m-d)</p>
						</td>
					</tr>

					<!-- Currency Settings Section -->
					<tr>
						<th colspan="2">
							<h2 class="title">Currency Settings</h2>
						</th>
					</tr>
					<tr>
						<th scope="row">Currency</th>
						<td>
							<select name="obie_events_currency" class="regular-text">
								<?php
								$currencies = array(
									'USD' => 'US Dollar (USD)',
								);
								$selected_currency = get_option('obie_events_currency', 'USD');
								foreach ($currencies as $code => $name) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($code),
										selected($selected_currency, $code, false),
										esc_html($name)
									);
								}
								?>
							</select>
							<p class="description">Select the currency for your events payments</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Currency Display</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox"
										name="obie_events_display_currency"
										value="1"
										<?php checked(get_option('obie_events_display_currency'), '1'); ?> />
									Show currency code
								</label>
								<p class="description">Display the currency code (e.g., USD) after prices</p>

								<br><br>

								<label>
									<input type="checkbox"
										name="obie_events_display_currency_sign"
										value="1"
										<?php checked(get_option('obie_events_display_currency_sign'), '1'); ?> />
									Show currency symbol
								</label>
								<p class="description">Display the currency symbol (e.g., $) before prices</p>
							</fieldset>
						</td>
					</tr>

					<!-- Email Templates Section -->
					<tr>
						<th colspan="2">
							<h2 class="title">Email Templates</h2>
						</th>
					</tr>
					<tr>
						<th scope="row">Registration confirmation subject</th>
						<td>
							<input type="text" name="obie_events_email_subject_registration" value="<?php echo esc_attr(get_option('obie_events_email_subject_registration', 'Registration confirmation for {event_name}')); ?>" class="regular-text" />
							<p class="description">Available variables: {customer_name}, {event_name}, {event_date} (date), {event_time} (time), {event_url} (event link)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Registration confirmation</th>
						<td>
							<?php
							wp_editor(
								get_option('obie_events_email_template_registration', 'Hello {customer_name}, your registration for the event {event_name} on {event_date} at {event_time} has been received.'),
								'obie_events_email_template_registration',
								array('textarea_name' => 'obie_events_email_template_registration', 'textarea_rows' => 6)
							);
							?>
							<p class="description">Available variables: {customer_name}, {event_name}, {event_date} (date), {event_time} (time), {event_url} (event link)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">24h reminder subject</th>
						<td>
							<input type="text" name="obie_events_email_subject_reminder_24h" value="<?php echo esc_attr(get_option('obie_events_email_subject_reminder_24h', 'Reminder: your event {event_name} is tomorrow')); ?>" class="regular-text" />
							<p class="description">Available variables: {customer_name}, {event_name}, {event_date} (date), {event_time} (time), {event_url} (event link)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">24h reminder</th>
						<td>
							<?php
							wp_editor(
								get_option('obie_events_email_template_reminder_24h', 'Hello {customer_name}, this is a reminder that your event {event_name} is tomorrow ({event_date}) at {event_time}.'),
								'obie_events_email_template_reminder_24h',
								array('textarea_name' => 'obie_events_email_template_reminder_24h', 'textarea_rows' => 6)
							);
							?>
							<p class="description">Available variables: {customer_name}, {event_name}, {event_date} (date), {event_time} (time), {event_url} (event link)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">1h reminder subject</th>
						<td>
							<input type="text" name="obie_events_email_subject_reminder_1h" value="<?php echo esc_attr(get_option('obie_events_email_subject_reminder_1h', 'Reminder: your event {event_name} starts in 1 hour')); ?>" class="regular-text" />
							<p class="description">Available variables: {customer_name}, {event_name}, {event_date} (date), {event_time} (time), {event_url} (event link)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">1h reminder</th>
						<td>
							<?php
							wp_editor(
								get_option('obie_events_email_template_reminder_1h', 'Hello {customer_name}, your event {event_name} starts in 1 hour, at {event_time}.'),
								'obie_events_email_template_reminder_1h',
								array('textarea_name' => 'obie_events_email_template_reminder_1h', 'textarea_rows' => 6)
							);
							?>
							<p class="description">Available variables: {customer_name}, {event_name}, {event_date} (date), {event_time} (time), {event_url} (event link)</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<script>
			jQuery(document).ready(function($) {
				$('input[name="obie_events_payment_provider"]').on('change', function() {
					var provider = $(this).val();
					$('.obie-provider-stripe, .obie-provider-surecart').hide();
					$('.obie-provider-' + provider).show();
				});
			});
			</script>
		</div>
<?php
	}
}
