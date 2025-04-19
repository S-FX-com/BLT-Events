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
		register_setting('obie_events_settings', 'obie_events_stripe_secret_key');
		register_setting('obie_events_settings', 'obie_events_stripe_publishable_key');
		register_setting('obie_events_settings', 'obie_events_stripe_webhook_secret');
		register_setting('obie_events_settings', 'obie_events_date_format');
		register_setting('obie_events_settings', 'obie_events_currency');
		register_setting('obie_events_settings', 'obie_events_display_currency');
		register_setting('obie_events_settings', 'obie_events_display_currency_sign');

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
				<?php settings_fields('obie_events_settings'); ?>
				<table class="form-table">
					<!-- Stripe Settings Section -->
					<tr>
						<th colspan="2">
							<h2 class="title">Stripe Settings</h2>
						</th>
					</tr>
					<tr>
						<th scope="row">Secret Key</th>
						<td>
							<input type="text"
								name="obie_events_stripe_secret_key"
								value="<?php echo esc_attr(get_option('obie_events_stripe_secret_key')); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">Publishable Key</th>
						<td>
							<input type="text"
								name="obie_events_stripe_publishable_key"
								value="<?php echo esc_attr(get_option('obie_events_stripe_publishable_key')); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">Webhook Secret</th>
						<td>
							<input type="text"
								name="obie_events_stripe_webhook_secret"
								value="<?php echo esc_attr(get_option('obie_events_stripe_webhook_secret')); ?>"
								class="regular-text" />
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
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
<?php
	}
}
