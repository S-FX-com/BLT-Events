<?php
/**
 * ZymEvents - Admin Settings Page
 *
 * Handles plugin settings: payment provider, Stripe Connect, SureCart, display, and email templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZymEvents_Admin_Settings {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_settings() {
		// Payment provider
		register_setting( 'zymevents_settings', 'zymevents_payment_provider' );

		// Stripe (manual keys — used when not connected via OAuth)
		register_setting( 'zymevents_settings', 'zymevents_stripe_secret_key' );
		register_setting( 'zymevents_settings', 'zymevents_stripe_publishable_key' );
		register_setting( 'zymevents_settings', 'zymevents_stripe_webhook_secret' );

		// SureCart
		register_setting( 'zymevents_settings', 'zymevents_surecart_api_token' );
		register_setting( 'zymevents_settings', 'zymevents_surecart_checkout_url' );

		// Display
		register_setting( 'zymevents_settings', 'zymevents_date_format' );
		register_setting( 'zymevents_settings', 'zymevents_currency' );
		register_setting( 'zymevents_settings', 'zymevents_display_currency' );
		register_setting( 'zymevents_settings', 'zymevents_display_currency_sign' );

		// Email
		register_setting( 'zymevents_settings', 'zymevents_email_template_registration' );
		register_setting( 'zymevents_settings', 'zymevents_email_template_reminder_24h' );
		register_setting( 'zymevents_settings', 'zymevents_email_template_reminder_1h' );
		register_setting( 'zymevents_settings', 'zymevents_email_subject_registration' );
		register_setting( 'zymevents_settings', 'zymevents_email_subject_reminder_24h' );
		register_setting( 'zymevents_settings', 'zymevents_email_subject_reminder_1h' );
	}

	public static function render_settings_page() {
		$payment_provider = get_option( 'zymevents_payment_provider', 'none' );
		$is_connected     = class_exists( 'ZymEvents_Stripe_Handler' ) && ZymEvents_Stripe_Handler::is_connected();
		$connect_account  = get_option( 'zymevents_stripe_connect_account_id', '' );
		$connect_livemode = get_option( 'zymevents_stripe_connect_livemode', '0' );
		$has_client_id    = defined( 'ZYMEVENTS_STRIPE_CLIENT_ID' ) && ! empty( ZYMEVENTS_STRIPE_CLIENT_ID );

		// Display admin notices for Connect flow.
		if ( ! empty( $_GET['zymevents_connect_success'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Stripe connected successfully!</strong> Your Stripe account is now linked to ZymEvents.</p></div>';
		} elseif ( ! empty( $_GET['zymevents_disconnected'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>Stripe account disconnected.</p></div>';
		} elseif ( ! empty( $_GET['zymevents_connect_error'] ) ) {
			$err = esc_html( sanitize_text_field( $_GET['zymevents_connect_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p><strong>Stripe connection failed:</strong> ' . $err . '</p></div>';
		}
		?>
		<div class="wrap zymevents-settings">
			<h1>ZymEvents Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'zymevents_settings' ); ?>

				<!-- ==================== Payment Provider ==================== -->
				<table class="form-table">
					<tr><th colspan="2"><h2 class="title">Payment Provider</h2></th></tr>
					<tr>
						<th scope="row">Active Provider</th>
						<td>
							<fieldset>
								<label><input type="radio" name="zymevents_payment_provider" value="none" <?php checked( $payment_provider, 'none' ); ?> /> None (Free events only)</label><br>
								<label><input type="radio" name="zymevents_payment_provider" value="stripe" <?php checked( $payment_provider, 'stripe' ); ?> /> Stripe</label><br>
								<label><input type="radio" name="zymevents_payment_provider" value="surecart" <?php checked( $payment_provider, 'surecart' ); ?> /> SureCart</label>
								<p class="description">Choose which payment provider to use for paid event registrations.</p>
							</fieldset>
						</td>
					</tr>

					<!-- ==================== Stripe Settings ==================== -->
					<tr class="zymevents-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title">Stripe Settings</h2></th>
					</tr>

					<!-- Stripe Connect status / button -->
					<tr class="zymevents-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Stripe Connect</th>
						<td>
							<?php if ( $is_connected ) : ?>
								<div class="zymevents-stripe-connected" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
									<span style="color:#059669;font-weight:600;">&#10003; Connected</span>
									<span style="color:#6b7280;font-size:13px;">
										Account: <code><?php echo esc_html( $connect_account ); ?></code>
										&nbsp;|&nbsp;
										Mode: <strong><?php echo $connect_livemode === '1' ? 'Live' : 'Test'; ?></strong>
									</span>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
										<?php wp_nonce_field( 'zymevents_stripe_disconnect_action' ); ?>
										<input type="hidden" name="action" value="zymevents_stripe_disconnect">
										<button type="submit" class="button button-secondary" onclick="return confirm('Disconnect your Stripe account?');">Disconnect</button>
									</form>
								</div>
								<p class="description">Your Stripe account is connected. Keys are managed automatically.</p>
							<?php elseif ( $has_client_id ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
									<?php wp_nonce_field( 'zymevents_stripe_connect_action' ); ?>
									<input type="hidden" name="action" value="zymevents_stripe_connect">
									<button type="submit" class="button" style="background:#635bff;color:#fff;border-color:#635bff;font-weight:600;padding:6px 16px;">
										<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:6px;" width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z"/></svg>
										Connect with Stripe
									</button>
								</form>
								<p class="description">One-click connect: authorize ZymEvents to accept payments via your Stripe account.</p>
							<?php else : ?>
								<p style="color:#b45309;">
									<strong>Stripe Connect not configured.</strong><br>
									To enable one-click Connect, define <code>ZYMEVENTS_STRIPE_CLIENT_ID</code> in <code>wp-config.php</code>.<br>
									You can still enter your API keys manually below.
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Manual keys (hidden when connected via OAuth) -->
					<?php $manual_style = ( $is_connected ? ' style="display:none;"' : '' ); ?>
					<tr class="zymevents-provider-stripe zymevents-stripe-manual-keys" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : $manual_style; ?>>
						<th scope="row">Secret Key</th>
						<td>
							<input type="password" name="zymevents_stripe_secret_key" value="<?php echo esc_attr( get_option( 'zymevents_stripe_secret_key' ) ); ?>" class="regular-text" placeholder="sk_live_..." />
							<p class="description">Your Stripe secret key. Not required when connected via Stripe Connect above.</p>
						</td>
					</tr>
					<tr class="zymevents-provider-stripe zymevents-stripe-manual-keys" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : $manual_style; ?>>
						<th scope="row">Publishable Key</th>
						<td>
							<input type="text" name="zymevents_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'zymevents_stripe_publishable_key' ) ); ?>" class="regular-text" placeholder="pk_live_..." />
						</td>
					</tr>
					<tr class="zymevents-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Webhook Secret</th>
						<td>
							<input type="password" name="zymevents_stripe_webhook_secret" value="<?php echo esc_attr( get_option( 'zymevents_stripe_webhook_secret' ) ); ?>" class="regular-text" placeholder="whsec_..." />
							<p class="description">
								Webhook endpoint: <code><?php echo esc_url( rest_url( 'zymevents/v1/stripe-webhook' ) ); ?></code>
							</p>
						</td>
					</tr>

					<!-- ==================== SureCart Settings ==================== -->
					<tr class="zymevents-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title">SureCart Settings</h2></th>
					</tr>
					<tr class="zymevents-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">API Token</th>
						<td>
							<input type="password" name="zymevents_surecart_api_token" value="<?php echo esc_attr( get_option( 'zymevents_surecart_api_token' ) ); ?>" class="regular-text" />
							<p class="description">Your SureCart secret API token. If the SureCart plugin is installed and connected, this can be left blank.</p>
						</td>
					</tr>
					<tr class="zymevents-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Checkout Page URL</th>
						<td>
							<input type="url" name="zymevents_surecart_checkout_url" value="<?php echo esc_attr( get_option( 'zymevents_surecart_checkout_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/checkout' ) ); ?>" />
							<p class="description">The URL of your SureCart checkout page. Leave blank to use default (<code>/checkout</code>).</p>
						</td>
					</tr>
					<tr class="zymevents-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Integration Status</th>
						<td>
							<?php
							$sc_active     = class_exists( 'ZymEvents_SureCart_Integration' ) && ZymEvents_SureCart_Integration::is_surecart_plugin_active();
							$sc_configured = class_exists( 'ZymEvents_SureCart_Integration' ) && ZymEvents_SureCart_Integration::is_configured();
							?>
							<p>SureCart Plugin: <?php echo $sc_active ? '<span style="color:#059669;font-weight:600;">Active</span>' : '<span style="color:#dc2626;font-weight:600;">Not Detected</span>'; ?></p>
							<p>API Connection: <?php echo $sc_configured ? '<span style="color:#059669;font-weight:600;">Connected</span>' : '<span style="color:#dc2626;font-weight:600;">Not Connected</span>'; ?></p>
						</td>
					</tr>

					<!-- ==================== Display Settings ==================== -->
					<tr><th colspan="2"><h2 class="title">Display Settings</h2></th></tr>
					<tr>
						<th scope="row">Date Format</th>
						<td>
							<input type="text" name="zymevents_date_format" value="<?php echo esc_attr( get_option( 'zymevents_date_format', 'F j, Y' ) ); ?>" class="regular-text" />
							<p class="description">PHP date format string (e.g., F j, Y).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Currency</th>
						<td>
							<select name="zymevents_currency">
								<?php
								$currencies = array( 'USD' => 'US Dollar (USD)', 'EUR' => 'Euro (EUR)', 'GBP' => 'British Pound (GBP)', 'SAR' => 'Saudi Riyal (SAR)', 'AED' => 'UAE Dirham (AED)' );
								$selected   = get_option( 'zymevents_currency', 'USD' );
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
							<label><input type="checkbox" name="zymevents_display_currency" value="1" <?php checked( get_option( 'zymevents_display_currency' ), '1' ); ?> /> Show currency code (e.g., USD)</label><br><br>
							<label><input type="checkbox" name="zymevents_display_currency_sign" value="1" <?php checked( get_option( 'zymevents_display_currency_sign' ), '1' ); ?> /> Show currency symbol (e.g., $)</label>
						</td>
					</tr>

					<!-- ==================== Email Templates ==================== -->
					<tr><th colspan="2"><h2 class="title">Email Templates</h2></th></tr>
					<tr>
						<th scope="row">Registration Subject</th>
						<td>
							<input type="text" name="zymevents_email_subject_registration" value="<?php echo esc_attr( get_option( 'zymevents_email_subject_registration', 'Registration confirmation for {event_name}' ) ); ?>" class="regular-text" />
							<p class="description">Variables: {customer_name}, {event_name}, {event_date}, {event_time}, {event_url}</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Registration Body</th>
						<td>
							<?php
							wp_editor(
								get_option( 'zymevents_email_template_registration', 'Hello {customer_name}, your registration for {event_name} on {event_date} at {event_time} has been confirmed.' ),
								'zymevents_email_template_registration',
								array( 'textarea_name' => 'zymevents_email_template_registration', 'textarea_rows' => 6 )
							);
							?>
							<p class="description">Variables: {customer_name}, {event_name}, {event_date}, {event_time}, {event_url}</p>
						</td>
					</tr>
					<tr>
						<th scope="row">24h Reminder Subject</th>
						<td><input type="text" name="zymevents_email_subject_reminder_24h" value="<?php echo esc_attr( get_option( 'zymevents_email_subject_reminder_24h', 'Reminder: {event_name} is tomorrow' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row">24h Reminder Body</th>
						<td>
							<?php
							wp_editor(
								get_option( 'zymevents_email_template_reminder_24h', 'Hello {customer_name}, your event {event_name} is tomorrow ({event_date}) at {event_time}.' ),
								'zymevents_email_template_reminder_24h',
								array( 'textarea_name' => 'zymevents_email_template_reminder_24h', 'textarea_rows' => 6 )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">1h Reminder Subject</th>
						<td><input type="text" name="zymevents_email_subject_reminder_1h" value="<?php echo esc_attr( get_option( 'zymevents_email_subject_reminder_1h', 'Reminder: {event_name} starts in 1 hour' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row">1h Reminder Body</th>
						<td>
							<?php
							wp_editor(
								get_option( 'zymevents_email_template_reminder_1h', 'Hello {customer_name}, your event {event_name} starts in 1 hour at {event_time}.' ),
								'zymevents_email_template_reminder_1h',
								array( 'textarea_name' => 'zymevents_email_template_reminder_1h', 'textarea_rows' => 6 )
							);
							?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<script>
			jQuery(document).ready(function($) {
				$('input[name="zymevents_payment_provider"]').on('change', function() {
					var provider = $(this).val();
					$('.zymevents-provider-stripe, .zymevents-provider-surecart').hide();
					if (provider !== 'none') {
						$('.zymevents-provider-' + provider).show();
					}
				});
			});
			</script>
		</div>
		<?php
	}
}
