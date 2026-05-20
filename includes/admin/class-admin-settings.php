<?php
/**
 * BLT Events - Admin Settings Page
 *
 * Handles plugin settings: payment provider, Stripe, SureCart, display, and email templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Admin_Settings {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_settings() {
		// Payment provider
		register_setting( 'blt_events_settings', 'blt_events_payment_provider' );

		// Stripe
		register_setting( 'blt_events_settings', 'blt_events_stripe_secret_key' );
		register_setting( 'blt_events_settings', 'blt_events_stripe_publishable_key' );
		register_setting( 'blt_events_settings', 'blt_events_stripe_webhook_secret' );

		// SureCart
		register_setting( 'blt_events_settings', 'blt_events_surecart_api_token' );
		register_setting( 'blt_events_settings', 'blt_events_surecart_checkout_url' );

		// Display
		register_setting( 'blt_events_settings', 'blt_events_date_format' );
		register_setting( 'blt_events_settings', 'blt_events_currency' );
		register_setting( 'blt_events_settings', 'blt_events_display_currency' );
		register_setting( 'blt_events_settings', 'blt_events_display_currency_sign' );

		// Email
		register_setting( 'blt_events_settings', 'blt_events_email_template_registration' );
		register_setting( 'blt_events_settings', 'blt_events_email_template_reminder_24h' );
		register_setting( 'blt_events_settings', 'blt_events_email_template_reminder_1h' );
		register_setting( 'blt_events_settings', 'blt_events_email_subject_registration' );
		register_setting( 'blt_events_settings', 'blt_events_email_subject_reminder_24h' );
		register_setting( 'blt_events_settings', 'blt_events_email_subject_reminder_1h' );
	}

	public static function render_settings_page() {
		$payment_provider = get_option( 'blt_events_payment_provider', 'none' );
		?>
		<div class="wrap blt-events-settings">
			<h1>BLT Events Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'blt_events_settings' ); ?>

				<!-- Payment Provider -->
				<table class="form-table">
					<tr><th colspan="2"><h2 class="title">Payment Provider</h2></th></tr>
					<tr>
						<th scope="row">Active Provider</th>
						<td>
							<fieldset>
								<label><input type="radio" name="blt_events_payment_provider" value="none" <?php checked( $payment_provider, 'none' ); ?> /> None (Free events only)</label><br>
								<label><input type="radio" name="blt_events_payment_provider" value="stripe" <?php checked( $payment_provider, 'stripe' ); ?> /> Stripe</label><br>
								<label><input type="radio" name="blt_events_payment_provider" value="surecart" <?php checked( $payment_provider, 'surecart' ); ?> /> SureCart</label>
								<p class="description">Choose which payment provider to use for paid event registrations.</p>
							</fieldset>
						</td>
					</tr>

					<!-- Stripe Settings -->
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title">Stripe Settings</h2></th>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Secret Key</th>
						<td><input type="password" name="blt_events_stripe_secret_key" value="<?php echo esc_attr( get_option( 'blt_events_stripe_secret_key' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Publishable Key</th>
						<td><input type="text" name="blt_events_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'blt_events_stripe_publishable_key' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Webhook Secret</th>
						<td><input type="password" name="blt_events_stripe_webhook_secret" value="<?php echo esc_attr( get_option( 'blt_events_stripe_webhook_secret' ) ); ?>" class="regular-text" /></td>
					</tr>

					<!-- SureCart Settings -->
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title">SureCart Settings</h2></th>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">API Token</th>
						<td>
							<input type="password" name="blt_events_surecart_api_token" value="<?php echo esc_attr( get_option( 'blt_events_surecart_api_token' ) ); ?>" class="regular-text" />
							<p class="description">Your SureCart secret API token. If the SureCart plugin is installed and connected, this can be left blank.</p>
						</td>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Checkout Page URL</th>
						<td>
							<input type="url" name="blt_events_surecart_checkout_url" value="<?php echo esc_attr( get_option( 'blt_events_surecart_checkout_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/checkout' ) ); ?>" />
							<p class="description">The URL of your SureCart checkout page. Leave blank to use default.</p>
						</td>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Integration Status</th>
						<td>
							<?php
							$sc_active     = class_exists( 'BLT_Events_SureCart_Integration' ) && BLT_Events_SureCart_Integration::is_surecart_plugin_active();
							$sc_configured = class_exists( 'BLT_Events_SureCart_Integration' ) && BLT_Events_SureCart_Integration::is_configured();
							?>
							<p>SureCart Plugin: <?php echo $sc_active ? '<span style="color:#059669;font-weight:600;">Active</span>' : '<span style="color:#dc2626;font-weight:600;">Not Detected</span>'; ?></p>
							<p>API Connection: <?php echo $sc_configured ? '<span style="color:#059669;font-weight:600;">Connected</span>' : '<span style="color:#dc2626;font-weight:600;">Not Connected</span>'; ?></p>
						</td>
					</tr>

					<!-- Display Settings -->
					<tr><th colspan="2"><h2 class="title">Display Settings</h2></th></tr>
					<tr>
						<th scope="row">Date Format</th>
						<td>
							<input type="text" name="blt_events_date_format" value="<?php echo esc_attr( get_option( 'blt_events_date_format', 'F j, Y' ) ); ?>" class="regular-text" />
							<p class="description">PHP date format string (e.g., F j, Y).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Currency</th>
						<td>
							<select name="blt_events_currency">
								<?php
								$currencies = array( 'USD' => 'US Dollar (USD)', 'EUR' => 'Euro (EUR)', 'GBP' => 'British Pound (GBP)', 'SAR' => 'Saudi Riyal (SAR)', 'AED' => 'UAE Dirham (AED)' );
								$selected   = get_option( 'blt_events_currency', 'USD' );
								foreach ( $currencies as $code => $name ) {
									printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $selected, $code, false ), esc_html( $name ) );
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Currency Display</th>
						<td>
							<label><input type="checkbox" name="blt_events_display_currency" value="1" <?php checked( get_option( 'blt_events_display_currency' ), '1' ); ?> /> Show currency code (e.g., USD)</label><br><br>
							<label><input type="checkbox" name="blt_events_display_currency_sign" value="1" <?php checked( get_option( 'blt_events_display_currency_sign' ), '1' ); ?> /> Show currency symbol (e.g., $)</label>
						</td>
					</tr>

					<!-- Email Templates -->
					<tr><th colspan="2"><h2 class="title">Email Templates</h2></th></tr>
					<tr>
						<th scope="row">Registration Subject</th>
						<td>
							<input type="text" name="blt_events_email_subject_registration" value="<?php echo esc_attr( get_option( 'blt_events_email_subject_registration', 'Registration confirmation for {event_name}' ) ); ?>" class="regular-text" />
							<p class="description">Variables: {customer_name}, {event_name}, {event_date}, {event_time}, {event_url}</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Registration Body</th>
						<td>
							<?php
							wp_editor(
								get_option( 'blt_events_email_template_registration', 'Hello {customer_name}, your registration for {event_name} on {event_date} at {event_time} has been confirmed.' ),
								'blt_events_email_template_registration',
								array( 'textarea_name' => 'blt_events_email_template_registration', 'textarea_rows' => 6 )
							);
							?>
							<p class="description">Variables: {customer_name}, {event_name}, {event_date}, {event_time}, {event_url}</p>
						</td>
					</tr>
					<tr>
						<th scope="row">24h Reminder Subject</th>
						<td><input type="text" name="blt_events_email_subject_reminder_24h" value="<?php echo esc_attr( get_option( 'blt_events_email_subject_reminder_24h', 'Reminder: {event_name} is tomorrow' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row">24h Reminder Body</th>
						<td>
							<?php
							wp_editor(
								get_option( 'blt_events_email_template_reminder_24h', 'Hello {customer_name}, your event {event_name} is tomorrow ({event_date}) at {event_time}.' ),
								'blt_events_email_template_reminder_24h',
								array( 'textarea_name' => 'blt_events_email_template_reminder_24h', 'textarea_rows' => 6 )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">1h Reminder Subject</th>
						<td><input type="text" name="blt_events_email_subject_reminder_1h" value="<?php echo esc_attr( get_option( 'blt_events_email_subject_reminder_1h', 'Reminder: {event_name} starts in 1 hour' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row">1h Reminder Body</th>
						<td>
							<?php
							wp_editor(
								get_option( 'blt_events_email_template_reminder_1h', 'Hello {customer_name}, your event {event_name} starts in 1 hour at {event_time}.' ),
								'blt_events_email_template_reminder_1h',
								array( 'textarea_name' => 'blt_events_email_template_reminder_1h', 'textarea_rows' => 6 )
							);
							?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<script>
			jQuery(document).ready(function($) {
				$('input[name="blt_events_payment_provider"]').on('change', function() {
					var provider = $(this).val();
					$('.blt-provider-stripe, .blt-provider-surecart').hide();
					if (provider !== 'none') {
						$('.blt-provider-' + provider).show();
					}
				});
			});
			</script>
		</div>
		<?php
	}
}
