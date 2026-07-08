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
		// Payment provider (whitelisted)
		register_setting( 'blt_events_settings', 'blt_events_payment_provider', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_payment_provider' ),
		) );

		// Stripe (secrets keep their stored value when submitted blank)
		register_setting( 'blt_events_settings', 'blt_events_stripe_secret_key', array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_secret( $value, 'blt_events_stripe_secret_key' );
			},
		) );
		register_setting( 'blt_events_settings', 'blt_events_stripe_publishable_key', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings', 'blt_events_stripe_webhook_secret', array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_secret( $value, 'blt_events_stripe_webhook_secret' );
			},
		) );

		// SureCart
		register_setting( 'blt_events_settings', 'blt_events_surecart_api_token', array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_secret( $value, 'blt_events_surecart_api_token' );
			},
		) );
		register_setting( 'blt_events_settings', 'blt_events_surecart_checkout_url', array(
			'sanitize_callback' => 'esc_url_raw',
		) );

		// Display
		register_setting( 'blt_events_settings', 'blt_events_date_format', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings', 'blt_events_currency', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_currency' ),
		) );
		register_setting( 'blt_events_settings', 'blt_events_display_currency', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );
		register_setting( 'blt_events_settings', 'blt_events_display_currency_sign', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );

		// Email
		register_setting( 'blt_events_settings', 'blt_events_email_template_registration', array(
			'sanitize_callback' => 'wp_kses_post',
		) );
		register_setting( 'blt_events_settings', 'blt_events_email_template_reminder_24h', array(
			'sanitize_callback' => 'wp_kses_post',
		) );
		register_setting( 'blt_events_settings', 'blt_events_email_template_reminder_1h', array(
			'sanitize_callback' => 'wp_kses_post',
		) );
		register_setting( 'blt_events_settings', 'blt_events_email_subject_registration', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings', 'blt_events_email_subject_reminder_24h', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings', 'blt_events_email_subject_reminder_1h', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		// FluentCRM add-on
		foreach ( array(
			'blt_events_fluentcrm_list_id',
			'blt_events_fluentcrm_registration_tag',
			'blt_events_fluentcrm_confirmed_tag',
			'blt_events_fluentcrm_refunded_tag',
		) as $fluentcrm_option ) {
			register_setting( 'blt_events_settings', $fluentcrm_option, array(
				'sanitize_callback' => 'absint',
			) );
		}
	}

	public static function sanitize_payment_provider( $value ) {
		$allowed = array( 'none', 'stripe', 'surecart', 'fluentcart' );
		return in_array( $value, $allowed, true ) ? $value : 'none';
	}

	public static function sanitize_currency( $value ) {
		$allowed = array( 'USD', 'EUR', 'GBP', 'SAR', 'AED' );
		return in_array( $value, $allowed, true ) ? $value : 'USD';
	}

	public static function sanitize_checkbox( $value ) {
		return $value === '1' ? '1' : '0';
	}

	/**
	 * Keep the previously stored secret when the field is submitted blank,
	 * so secrets never need to be rendered back into the page.
	 */
	public static function sanitize_secret( $value, $option_name ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( $value === '' ) {
			return get_option( $option_name, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Render a secret input: never echoes the stored value.
	 */
	private static function render_secret_field( $option_name ) {
		$has_value = get_option( $option_name, '' ) !== '';
		printf(
			'<input type="password" name="%1$s" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( $option_name ),
			$has_value
				? esc_attr__( 'Saved — leave blank to keep current value', 'blt-events' )
				: esc_attr__( 'Not set', 'blt-events' )
		);
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
								<label><input type="radio" name="blt_events_payment_provider" value="surecart" <?php checked( $payment_provider, 'surecart' ); ?> /> SureCart</label><br>
								<label><input type="radio" name="blt_events_payment_provider" value="fluentcart" <?php checked( $payment_provider, 'fluentcart' ); ?> /> FluentCart</label>
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
						<td><?php self::render_secret_field( 'blt_events_stripe_secret_key' ); ?></td>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Publishable Key</th>
						<td><input type="text" name="blt_events_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'blt_events_stripe_publishable_key' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Webhook Secret</th>
						<td><?php self::render_secret_field( 'blt_events_stripe_webhook_secret' ); ?></td>
					</tr>

					<!-- SureCart Settings -->
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title">SureCart Settings</h2></th>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">API Token</th>
						<td>
							<?php self::render_secret_field( 'blt_events_surecart_api_token' ); ?>
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

					<!-- FluentCart Settings -->
					<tr class="blt-provider-fluentcart" <?php echo $payment_provider !== 'fluentcart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title">FluentCart Settings</h2></th>
					</tr>
					<tr class="blt-provider-fluentcart" <?php echo $payment_provider !== 'fluentcart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row">Integration Status</th>
						<td>
							<?php
							$fc_active = class_exists( 'BLT_Events_FluentCart_Integration' ) && BLT_Events_FluentCart_Integration::is_fluentcart_plugin_active();
							?>
							<p>FluentCart Plugin: <?php echo $fc_active ? '<span style="color:#059669;font-weight:600;">Active</span>' : '<span style="color:#dc2626;font-weight:600;">Not Detected</span>'; ?></p>
							<p class="description">FluentCart runs on this site, so no API keys are needed. Event ticket types are synced to FluentCart products automatically when an event is saved, and checkout uses FluentCart's instant checkout. Install FluentCart from <a href="https://fluentcart.com" target="_blank" rel="noopener noreferrer">fluentcart.com</a> if it is not detected.</p>
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

					<!-- FluentCRM Integration -->
					<?php if ( defined( 'FLUENTCRM' ) ) : ?>
					<tr><th colspan="2"><h2 class="title">FluentCRM Integration</h2></th></tr>
					<?php
					$fluentcrm_fields = array(
						'blt_events_fluentcrm_list_id'          => 'Default List ID',
						'blt_events_fluentcrm_registration_tag' => 'Registration Tag ID',
						'blt_events_fluentcrm_confirmed_tag'    => 'Confirmed Tag ID',
						'blt_events_fluentcrm_refunded_tag'     => 'Refunded Tag ID',
					);
					foreach ( $fluentcrm_fields as $option_name => $label ) :
						$value = get_option( $option_name, '' );
						?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><input type="number" min="0" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="small-text" /></td>
					</tr>
					<?php endforeach; ?>
					<?php endif; ?>

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
					$('.blt-provider-stripe, .blt-provider-surecart, .blt-provider-fluentcart').hide();
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
