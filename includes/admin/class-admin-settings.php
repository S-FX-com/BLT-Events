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

		// Meeting integration credentials (one option per provider field).
		if ( class_exists( 'BLT_Events_Meeting_Providers' ) ) {
			foreach ( BLT_Events_Meeting_Providers::all() as $provider ) {
				foreach ( $provider->credential_fields() as $field ) {
					$option_name = 'blt_events_' . $field['key'];
					if ( ! empty( $field['secret'] ) ) {
						register_setting( 'blt_events_settings', $option_name, array(
							'sanitize_callback' => function ( $value ) use ( $option_name ) {
								return self::sanitize_secret( $value, $option_name );
							},
						) );
					} else {
						register_setting( 'blt_events_settings', $option_name, array(
							'sanitize_callback' => 'sanitize_text_field',
						) );
					}
				}
			}
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

	/**
	 * Render the "Online Meeting Integrations" settings block: one card per
	 * provider with its credentials, connection status, and (for OAuth
	 * providers) a Connect/Disconnect control.
	 */
	private static function render_integrations_section() {
		if ( ! class_exists( 'BLT_Events_Meeting_Providers' ) ) {
			return;
		}
		?>
		<tr><th colspan="2"><h2 class="title" id="integrations"><?php esc_html_e( 'Online Meeting Integrations', 'blt-events' ); ?></h2></th></tr>
		<tr>
			<td colspan="2">
				<p class="description"><?php esc_html_e( 'Connect the platforms you use. Once a provider is connected, online and hybrid events can auto-create a meeting room from the event editor and drop the join link in automatically.', 'blt-events' ); ?></p>
			</td>
		</tr>
		<?php
		foreach ( BLT_Events_Meeting_Providers::all() as $provider ) {
			$connected = $provider->is_connected();
			?>
			<tr>
				<th colspan="2">
					<h3 class="blt-integration-title">
						<?php echo esc_html( $provider->name() ); ?>
						<?php if ( $connected ) : ?>
							<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Connected', 'blt-events' ); ?></span>
						<?php else : ?>
							<span class="blt-badge blt-badge-off"><?php esc_html_e( 'Not connected', 'blt-events' ); ?></span>
						<?php endif; ?>
					</h3>
				</th>
			</tr>
			<?php
			foreach ( $provider->credential_fields() as $field ) {
				self::render_integration_field( $field );
			}

			if ( $provider->is_oauth() ) {
				self::render_oauth_controls( $provider );
			}
		}
	}

	/**
	 * Render a single provider credential field row (text or secret).
	 *
	 * @param array $field
	 */
	private static function render_integration_field( $field ) {
		$option_name = 'blt_events_' . $field['key'];
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
			<td>
				<?php if ( ! empty( $field['secret'] ) ) : ?>
					<?php self::render_secret_field( $option_name ); ?>
				<?php else : ?>
					<input type="text" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( get_option( $option_name, '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $field['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the redirect URI and Connect/Disconnect control for an OAuth
	 * provider.
	 *
	 * @param BLT_Events_Meeting_Provider $provider
	 */
	private static function render_oauth_controls( $provider ) {
		$slug = $provider->slug();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Redirect URI', 'blt-events' ); ?></th>
			<td>
				<code class="blt-redirect-uri"><?php echo esc_html( $provider->callback_url() ); ?></code>
				<p class="description"><?php esc_html_e( 'Register this exact URL as an allowed redirect URI in the provider\'s developer console.', 'blt-events' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Connection', 'blt-events' ); ?></th>
			<td>
				<?php if ( $provider->is_connected() ) : ?>
					<?php
					$disconnect = wp_nonce_url(
						admin_url( 'admin-post.php?action=blt_events_meeting_disconnect&provider=' . $slug ),
						'blt_events_meeting_disconnect_' . $slug
					);
					?>
					<a href="<?php echo esc_url( $disconnect ); ?>" class="button"><?php esc_html_e( 'Disconnect', 'blt-events' ); ?></a>
				<?php elseif ( $provider->is_configured() ) : ?>
					<?php
					$connect = wp_nonce_url(
						admin_url( 'admin-post.php?action=blt_events_meeting_connect&provider=' . $slug ),
						'blt_events_meeting_connect_' . $slug
					);
					?>
					<a href="<?php echo esc_url( $connect ); ?>" class="button button-primary"><?php esc_html_e( 'Connect', 'blt-events' ); ?></a>
					<p class="description"><?php esc_html_e( 'Save the client credentials first, then connect.', 'blt-events' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Enter and save the client credentials above to enable connecting.', 'blt-events' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function render_settings_page() {
		$payment_provider = get_option( 'blt_events_payment_provider', 'none' );
		?>
		<div class="wrap blt-events-settings">
			<h1><?php esc_html_e( 'BLT Events Settings', 'blt-events' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'blt_events_settings' ); ?>

				<!-- Payment Provider -->
				<table class="form-table">
					<tr><th colspan="2"><h2 class="title"><?php esc_html_e( 'Payment Provider', 'blt-events' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Active Provider', 'blt-events' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="blt_events_payment_provider" value="none" <?php checked( $payment_provider, 'none' ); ?> /> <?php esc_html_e( 'None (Free events only)', 'blt-events' ); ?></label><br>
								<label><input type="radio" name="blt_events_payment_provider" value="stripe" <?php checked( $payment_provider, 'stripe' ); ?> /> <?php esc_html_e( 'Stripe', 'blt-events' ); ?></label><br>
								<label><input type="radio" name="blt_events_payment_provider" value="surecart" <?php checked( $payment_provider, 'surecart' ); ?> /> <?php esc_html_e( 'SureCart', 'blt-events' ); ?></label><br>
								<label><input type="radio" name="blt_events_payment_provider" value="fluentcart" <?php checked( $payment_provider, 'fluentcart' ); ?> /> <?php esc_html_e( 'FluentCart', 'blt-events' ); ?></label>
								<p class="description"><?php esc_html_e( 'Choose which payment provider to use for paid event registrations.', 'blt-events' ); ?></p>
							</fieldset>
						</td>
					</tr>

					<!-- Stripe Settings -->
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title"><?php esc_html_e( 'Stripe Settings', 'blt-events' ); ?></h2></th>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Secret Key', 'blt-events' ); ?></th>
						<td><?php self::render_secret_field( 'blt_events_stripe_secret_key' ); ?></td>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Publishable Key', 'blt-events' ); ?></th>
						<td><input type="text" name="blt_events_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'blt_events_stripe_publishable_key' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr class="blt-provider-stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Webhook Secret', 'blt-events' ); ?></th>
						<td><?php self::render_secret_field( 'blt_events_stripe_webhook_secret' ); ?></td>
					</tr>

					<!-- SureCart Settings -->
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title"><?php esc_html_e( 'SureCart Settings', 'blt-events' ); ?></h2></th>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'API Token', 'blt-events' ); ?></th>
						<td>
							<?php self::render_secret_field( 'blt_events_surecart_api_token' ); ?>
							<p class="description"><?php esc_html_e( 'Your SureCart secret API token. If the SureCart plugin is installed and connected, this can be left blank.', 'blt-events' ); ?></p>
						</td>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Checkout Page URL', 'blt-events' ); ?></th>
						<td>
							<input type="url" name="blt_events_surecart_checkout_url" value="<?php echo esc_attr( get_option( 'blt_events_surecart_checkout_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/checkout' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The URL of your SureCart checkout page. Leave blank to use default.', 'blt-events' ); ?></p>
						</td>
					</tr>
					<tr class="blt-provider-surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Integration Status', 'blt-events' ); ?></th>
						<td>
							<?php
							$sc_active     = class_exists( 'BLT_Events_SureCart_Integration' ) && BLT_Events_SureCart_Integration::is_surecart_plugin_active();
							$sc_configured = class_exists( 'BLT_Events_SureCart_Integration' ) && BLT_Events_SureCart_Integration::is_configured();
							?>
							<p><?php esc_html_e( 'SureCart Plugin:', 'blt-events' ); ?> <?php echo $sc_active ? '<span style="color:#059669;font-weight:600;">' . esc_html__( 'Active', 'blt-events' ) . '</span>' : '<span style="color:#dc2626;font-weight:600;">' . esc_html__( 'Not Detected', 'blt-events' ) . '</span>'; ?></p>
							<p><?php esc_html_e( 'API Connection:', 'blt-events' ); ?> <?php echo $sc_configured ? '<span style="color:#059669;font-weight:600;">' . esc_html__( 'Connected', 'blt-events' ) . '</span>' : '<span style="color:#dc2626;font-weight:600;">' . esc_html__( 'Not Connected', 'blt-events' ) . '</span>'; ?></p>
						</td>
					</tr>

					<!-- FluentCart Settings -->
					<tr class="blt-provider-fluentcart" <?php echo $payment_provider !== 'fluentcart' ? 'style="display:none;"' : ''; ?>>
						<th colspan="2"><h2 class="title"><?php esc_html_e( 'FluentCart Settings', 'blt-events' ); ?></h2></th>
					</tr>
					<tr class="blt-provider-fluentcart" <?php echo $payment_provider !== 'fluentcart' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Integration Status', 'blt-events' ); ?></th>
						<td>
							<?php
							$fc_active = class_exists( 'BLT_Events_FluentCart_Integration' ) && BLT_Events_FluentCart_Integration::is_fluentcart_plugin_active();
							?>
							<p><?php esc_html_e( 'FluentCart Plugin:', 'blt-events' ); ?> <?php echo $fc_active ? '<span style="color:#059669;font-weight:600;">' . esc_html__( 'Active', 'blt-events' ) . '</span>' : '<span style="color:#dc2626;font-weight:600;">' . esc_html__( 'Not Detected', 'blt-events' ) . '</span>'; ?></p>
							<p class="description"><?php echo wp_kses_post( sprintf( __( 'FluentCart runs on this site, so no API keys are needed. Event ticket types are synced to FluentCart products automatically when an event is saved, and checkout uses FluentCart\'s instant checkout. Install FluentCart from %s if it is not detected.', 'blt-events' ), '<a href="https://fluentcart.com" target="_blank" rel="noopener noreferrer">fluentcart.com</a>' ) ); ?></p>
						</td>
					</tr>

					<!-- Online Meeting Integrations -->
					<?php self::render_integrations_section(); ?>

					<!-- Display Settings -->
					<tr><th colspan="2"><h2 class="title"><?php esc_html_e( 'Display Settings', 'blt-events' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Date Format', 'blt-events' ); ?></th>
						<td>
							<input type="text" name="blt_events_date_format" value="<?php echo esc_attr( get_option( 'blt_events_date_format', 'F j, Y' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'PHP date format string (e.g., F j, Y).', 'blt-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Currency', 'blt-events' ); ?></th>
						<td>
							<select name="blt_events_currency">
								<?php
								$currencies = array( 'USD' => __( 'US Dollar (USD)', 'blt-events' ), 'EUR' => __( 'Euro (EUR)', 'blt-events' ), 'GBP' => __( 'British Pound (GBP)', 'blt-events' ), 'SAR' => __( 'Saudi Riyal (SAR)', 'blt-events' ), 'AED' => __( 'UAE Dirham (AED)', 'blt-events' ) );
								$selected   = get_option( 'blt_events_currency', 'USD' );
								foreach ( $currencies as $code => $name ) {
									printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $selected, $code, false ), esc_html( $name ) );
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Currency Display', 'blt-events' ); ?></th>
						<td>
							<label><input type="checkbox" name="blt_events_display_currency" value="1" <?php checked( get_option( 'blt_events_display_currency' ), '1' ); ?> /> <?php esc_html_e( 'Show currency code (e.g., USD)', 'blt-events' ); ?></label><br><br>
							<label><input type="checkbox" name="blt_events_display_currency_sign" value="1" <?php checked( get_option( 'blt_events_display_currency_sign' ), '1' ); ?> /> <?php esc_html_e( 'Show currency symbol (e.g., $)', 'blt-events' ); ?></label>
						</td>
					</tr>

					<!-- FluentCRM Integration -->
					<?php if ( defined( 'FLUENTCRM' ) ) : ?>
					<tr><th colspan="2"><h2 class="title"><?php esc_html_e( 'FluentCRM Integration', 'blt-events' ); ?></h2></th></tr>
					<?php
					$fluentcrm_fields = array(
						'blt_events_fluentcrm_list_id'          => __( 'Default List ID', 'blt-events' ),
						'blt_events_fluentcrm_registration_tag' => __( 'Registration Tag ID', 'blt-events' ),
						'blt_events_fluentcrm_confirmed_tag'    => __( 'Confirmed Tag ID', 'blt-events' ),
						'blt_events_fluentcrm_refunded_tag'     => __( 'Refunded Tag ID', 'blt-events' ),
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
					<tr><th colspan="2"><h2 class="title"><?php esc_html_e( 'Email Templates', 'blt-events' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Registration Subject', 'blt-events' ); ?></th>
						<td>
							<input type="text" name="blt_events_email_subject_registration" value="<?php echo esc_attr( get_option( 'blt_events_email_subject_registration', __( 'Registration confirmation for {event_name}', 'blt-events' ) ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Variables: {customer_name}, {event_name}, {event_date}, {event_time}, {event_url}', 'blt-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Registration Body', 'blt-events' ); ?></th>
						<td>
							<?php
							wp_editor(
								get_option( 'blt_events_email_template_registration', __( 'Hello {customer_name}, your registration for {event_name} on {event_date} at {event_time} has been confirmed.', 'blt-events' ) ),
								'blt_events_email_template_registration',
								array( 'textarea_name' => 'blt_events_email_template_registration', 'textarea_rows' => 6 )
							);
							?>
							<p class="description"><?php esc_html_e( 'Variables: {customer_name}, {event_name}, {event_date}, {event_time}, {event_url}', 'blt-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '24h Reminder Subject', 'blt-events' ); ?></th>
						<td><input type="text" name="blt_events_email_subject_reminder_24h" value="<?php echo esc_attr( get_option( 'blt_events_email_subject_reminder_24h', __( 'Reminder: {event_name} is tomorrow', 'blt-events' ) ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '24h Reminder Body', 'blt-events' ); ?></th>
						<td>
							<?php
							wp_editor(
								get_option( 'blt_events_email_template_reminder_24h', __( 'Hello {customer_name}, your event {event_name} is tomorrow ({event_date}) at {event_time}.', 'blt-events' ) ),
								'blt_events_email_template_reminder_24h',
								array( 'textarea_name' => 'blt_events_email_template_reminder_24h', 'textarea_rows' => 6 )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '1h Reminder Subject', 'blt-events' ); ?></th>
						<td><input type="text" name="blt_events_email_subject_reminder_1h" value="<?php echo esc_attr( get_option( 'blt_events_email_subject_reminder_1h', __( 'Reminder: {event_name} starts in 1 hour', 'blt-events' ) ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '1h Reminder Body', 'blt-events' ); ?></th>
						<td>
							<?php
							wp_editor(
								get_option( 'blt_events_email_template_reminder_1h', __( 'Hello {customer_name}, your event {event_name} starts in 1 hour at {event_time}.', 'blt-events' ) ),
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
