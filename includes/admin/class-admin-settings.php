<?php
/**
 * BLT Events - Admin Settings Page
 *
 * Tabbed settings screen: General, Payments, Emails, Integrations, and a
 * Shortcodes reference. Each tab posts to its own settings group so saving
 * one tab never resets options that live on another tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Admin_Settings {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Tab slugs => labels. The Shortcodes tab is a read-only reference and
	 * has no settings group.
	 */
	public static function tabs() {
		return array(
			'general'      => __( 'General', 'blt-events' ),
			'payments'     => __( 'Payments', 'blt-events' ),
			'emails'       => __( 'Emails', 'blt-events' ),
			'integrations' => __( 'Integrations', 'blt-events' ),
			'shortcodes'   => __( 'Shortcodes', 'blt-events' ),
		);
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * URL of a settings tab.
	 */
	public static function tab_url( $tab ) {
		return admin_url( 'edit.php?post_type=event&page=blt-events-settings&tab=' . $tab );
	}

	public static function register_settings() {
		// --- General ---
		register_setting( 'blt_events_settings_general', 'blt_events_date_format', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings_general', 'blt_events_currency', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_currency' ),
		) );
		register_setting( 'blt_events_settings_general', 'blt_events_display_currency', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );
		register_setting( 'blt_events_settings_general', 'blt_events_display_currency_sign', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );

		// --- Payments ---
		register_setting( 'blt_events_settings_payments', 'blt_events_payment_provider', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_payment_provider' ),
		) );

		// Stripe (secrets keep their stored value when submitted blank)
		register_setting( 'blt_events_settings_payments', 'blt_events_stripe_secret_key', array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_secret( $value, 'blt_events_stripe_secret_key' );
			},
		) );
		register_setting( 'blt_events_settings_payments', 'blt_events_stripe_publishable_key', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings_payments', 'blt_events_stripe_webhook_secret', array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_secret( $value, 'blt_events_stripe_webhook_secret' );
			},
		) );

		// SureCart
		register_setting( 'blt_events_settings_payments', 'blt_events_surecart_api_token', array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_secret( $value, 'blt_events_surecart_api_token' );
			},
		) );
		register_setting( 'blt_events_settings_payments', 'blt_events_surecart_checkout_url', array(
			'sanitize_callback' => 'esc_url_raw',
		) );

		// --- Emails ---
		register_setting( 'blt_events_settings_emails', 'blt_events_email_template_registration', array(
			'sanitize_callback' => 'wp_kses_post',
		) );
		register_setting( 'blt_events_settings_emails', 'blt_events_email_template_reminder_24h', array(
			'sanitize_callback' => 'wp_kses_post',
		) );
		register_setting( 'blt_events_settings_emails', 'blt_events_email_template_reminder_1h', array(
			'sanitize_callback' => 'wp_kses_post',
		) );
		register_setting( 'blt_events_settings_emails', 'blt_events_email_subject_registration', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings_emails', 'blt_events_email_subject_reminder_24h', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'blt_events_settings_emails', 'blt_events_email_subject_reminder_1h', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		// --- Integrations: FluentCRM add-on ---
		foreach ( array(
			'blt_events_fluentcrm_list_id',
			'blt_events_fluentcrm_registration_tag',
			'blt_events_fluentcrm_confirmed_tag',
			'blt_events_fluentcrm_refunded_tag',
		) as $fluentcrm_option ) {
			register_setting( 'blt_events_settings_integrations', $fluentcrm_option, array(
				'sanitize_callback' => 'absint',
			) );
		}

		// --- Integrations: meeting provider credentials (one option per field) ---
		if ( class_exists( 'BLT_Events_Meeting_Providers' ) ) {
			foreach ( BLT_Events_Meeting_Providers::all() as $provider ) {
				foreach ( $provider->credential_fields() as $field ) {
					$option_name = 'blt_events_' . $field['key'];
					if ( ! empty( $field['secret'] ) ) {
						register_setting( 'blt_events_settings_integrations', $option_name, array(
							'sanitize_callback' => function ( $value ) use ( $option_name ) {
								return self::sanitize_secret( $value, $option_name );
							},
						) );
					} else {
						register_setting( 'blt_events_settings_integrations', $option_name, array(
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

	/* --------------------------------------------------------------------
	 * Shared render helpers
	 * ------------------------------------------------------------------ */

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
	 * Toggle switch bound to a "1"/"0" checkbox option.
	 */
	private static function render_toggle( $option_name, $label, $description = '' ) {
		?>
		<label class="blt-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>" value="1" <?php checked( get_option( $option_name ), '1' ); ?> />
			<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
			<span class="blt-toggle-text">
				<span class="blt-toggle-label"><?php echo esc_html( $label ); ?></span>
				<?php if ( $description ) : ?>
					<span class="blt-toggle-desc"><?php echo esc_html( $description ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * A labelled field row inside a card.
	 *
	 * @param string   $label
	 * @param callable $control  Echoes the control markup.
	 * @param string   $description
	 */
	private static function render_field( $label, $control, $description = '' ) {
		?>
		<div class="blt-field">
			<div class="blt-field-label"><?php echo esc_html( $label ); ?></div>
			<div class="blt-field-control">
				<?php $control(); ?>
				<?php if ( $description ) : ?>
					<p class="blt-field-desc"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_status_badge( $on, $on_label, $off_label ) {
		printf(
			'<span class="blt-badge %1$s">%2$s</span>',
			$on ? 'blt-badge-on' : 'blt-badge-off',
			esc_html( $on ? $on_label : $off_label )
		);
	}

	private static function render_save_button() {
		?>
		<div class="blt-settings-footer">
			<?php submit_button( __( 'Save Changes', 'blt-events' ), 'primary blt-save-button', 'submit', false ); ?>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Page shell
	 * ------------------------------------------------------------------ */

	public static function render_settings_page() {
		$current = self::current_tab();
		?>
		<div class="wrap blt-ui blt-events-settings">
			<div class="blt-admin-page-header">
				<h1><?php esc_html_e( 'BLT Events', 'blt-events' ); ?> <span class="blt-admin-page-header-sub"><?php esc_html_e( 'Settings', 'blt-events' ); ?></span></h1>
			</div>

			<?php settings_errors(); ?>

			<nav class="blt-settings-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'blt-events' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>" class="blt-settings-tab <?php echo $slug === $current ? 'is-active' : ''; ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="blt-settings-body">
				<?php
				switch ( $current ) {
					case 'payments':
						self::render_tab_payments();
						break;
					case 'emails':
						self::render_tab_emails();
						break;
					case 'integrations':
						self::render_tab_integrations();
						break;
					case 'shortcodes':
						self::render_tab_shortcodes();
						break;
					default:
						self::render_tab_general();
				}
				?>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Tab: General
	 * ------------------------------------------------------------------ */

	private static function render_tab_general() {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_general' ); ?>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Date & Time', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'How event dates are displayed across calendars, event pages, and emails.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'Date Format', 'blt-events' ),
						function () {
							?>
							<input type="text" name="blt_events_date_format" value="<?php echo esc_attr( get_option( 'blt_events_date_format', 'F j, Y' ) ); ?>" class="regular-text" />
							<?php
						},
						__( 'PHP date format string (e.g., F j, Y).', 'blt-events' )
					);
					?>
				</div>
			</div>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Currency', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'The currency used for ticket prices and how it is shown to visitors.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'Currency', 'blt-events' ),
						function () {
							$currencies = array(
								'USD' => __( 'US Dollar (USD)', 'blt-events' ),
								'EUR' => __( 'Euro (EUR)', 'blt-events' ),
								'GBP' => __( 'British Pound (GBP)', 'blt-events' ),
								'SAR' => __( 'Saudi Riyal (SAR)', 'blt-events' ),
								'AED' => __( 'UAE Dirham (AED)', 'blt-events' ),
							);
							$selected = get_option( 'blt_events_currency', 'USD' );
							echo '<select name="blt_events_currency">';
							foreach ( $currencies as $code => $name ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $selected, $code, false ), esc_html( $name ) );
							}
							echo '</select>';
						}
					);

					self::render_field(
						__( 'Currency Display', 'blt-events' ),
						function () {
							?>
							<div class="blt-toggle-stack">
								<?php
								self::render_toggle( 'blt_events_display_currency', __( 'Show currency code', 'blt-events' ), __( 'Appends the code after prices, e.g. 25.00 USD.', 'blt-events' ) );
								self::render_toggle( 'blt_events_display_currency_sign', __( 'Show currency symbol', 'blt-events' ), __( 'Prefixes prices with the symbol, e.g. $25.00.', 'blt-events' ) );
								?>
							</div>
							<?php
						}
					);
					?>
				</div>
			</div>

			<?php self::render_save_button(); ?>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Tab: Payments
	 * ------------------------------------------------------------------ */

	private static function render_tab_payments() {
		$payment_provider = get_option( 'blt_events_payment_provider', 'none' );

		$providers = array(
			'none'       => array(
				'name' => __( 'None', 'blt-events' ),
				'desc' => __( 'Free events only — no checkout.', 'blt-events' ),
			),
			'stripe'     => array(
				'name' => __( 'Stripe', 'blt-events' ),
				'desc' => __( 'Card payments through Stripe Checkout.', 'blt-events' ),
			),
			'surecart'   => array(
				'name' => __( 'SureCart', 'blt-events' ),
				'desc' => __( 'Checkout through your SureCart store.', 'blt-events' ),
			),
			'fluentcart' => array(
				'name' => __( 'FluentCart', 'blt-events' ),
				'desc' => __( 'On-site checkout with FluentCart.', 'blt-events' ),
			),
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_payments' ); ?>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Payment Provider', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Choose which payment provider handles paid event registrations.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<div class="blt-select-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Payment provider', 'blt-events' ); ?>">
						<?php foreach ( $providers as $value => $provider ) : ?>
							<label class="blt-select-card <?php echo $payment_provider === $value ? 'is-selected' : ''; ?>">
								<input type="radio" name="blt_events_payment_provider" value="<?php echo esc_attr( $value ); ?>" <?php checked( $payment_provider, $value ); ?> />
								<span class="blt-select-card-check" aria-hidden="true"></span>
								<span class="blt-select-card-name"><?php echo esc_html( $provider['name'] ); ?></span>
								<span class="blt-select-card-desc"><?php echo esc_html( $provider['desc'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- Stripe -->
			<div class="blt-card blt-provider-panel" data-provider="stripe" <?php echo $payment_provider !== 'stripe' ? 'style="display:none;"' : ''; ?>>
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Stripe', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'API keys from your Stripe dashboard. Secret values are stored but never displayed back.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field( __( 'Secret Key', 'blt-events' ), function () {
						self::render_secret_field( 'blt_events_stripe_secret_key' );
					} );
					self::render_field( __( 'Publishable Key', 'blt-events' ), function () {
						?>
						<input type="text" name="blt_events_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'blt_events_stripe_publishable_key' ) ); ?>" class="regular-text" />
						<?php
					} );
					self::render_field( __( 'Webhook Secret', 'blt-events' ), function () {
						self::render_secret_field( 'blt_events_stripe_webhook_secret' );
					} );
					?>
				</div>
			</div>

			<!-- SureCart -->
			<div class="blt-card blt-provider-panel" data-provider="surecart" <?php echo $payment_provider !== 'surecart' ? 'style="display:none;"' : ''; ?>>
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'SureCart', 'blt-events' ); ?></h2>
					<?php
					$sc_active     = class_exists( 'BLT_Events_SureCart_Integration' ) && BLT_Events_SureCart_Integration::is_surecart_plugin_active();
					$sc_configured = class_exists( 'BLT_Events_SureCart_Integration' ) && BLT_Events_SureCart_Integration::is_configured();
					?>
					<div class="blt-card-header-badges">
						<span class="blt-badge-labelled"><?php esc_html_e( 'Plugin', 'blt-events' ); ?> <?php self::render_status_badge( $sc_active, __( 'Active', 'blt-events' ), __( 'Not Detected', 'blt-events' ) ); ?></span>
						<span class="blt-badge-labelled"><?php esc_html_e( 'API', 'blt-events' ); ?> <?php self::render_status_badge( $sc_configured, __( 'Connected', 'blt-events' ), __( 'Not Connected', 'blt-events' ) ); ?></span>
					</div>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'API Token', 'blt-events' ),
						function () {
							self::render_secret_field( 'blt_events_surecart_api_token' );
						},
						__( 'Your SureCart secret API token. If the SureCart plugin is installed and connected, this can be left blank.', 'blt-events' )
					);
					self::render_field(
						__( 'Checkout Page URL', 'blt-events' ),
						function () {
							?>
							<input type="url" name="blt_events_surecart_checkout_url" value="<?php echo esc_attr( get_option( 'blt_events_surecart_checkout_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/checkout' ) ); ?>" />
							<?php
						},
						__( 'The URL of your SureCart checkout page. Leave blank to use default.', 'blt-events' )
					);
					?>
				</div>
			</div>

			<!-- FluentCart -->
			<div class="blt-card blt-provider-panel" data-provider="fluentcart" <?php echo $payment_provider !== 'fluentcart' ? 'style="display:none;"' : ''; ?>>
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'FluentCart', 'blt-events' ); ?></h2>
					<?php $fc_active = class_exists( 'BLT_Events_FluentCart_Integration' ) && BLT_Events_FluentCart_Integration::is_fluentcart_plugin_active(); ?>
					<div class="blt-card-header-badges">
						<span class="blt-badge-labelled"><?php esc_html_e( 'Plugin', 'blt-events' ); ?> <?php self::render_status_badge( $fc_active, __( 'Active', 'blt-events' ), __( 'Not Detected', 'blt-events' ) ); ?></span>
					</div>
				</div>
				<div class="blt-card-body">
					<p class="blt-field-desc"><?php echo wp_kses_post( sprintf( __( 'FluentCart runs on this site, so no API keys are needed. Event ticket types are synced to FluentCart products automatically when an event is saved, and checkout uses FluentCart\'s instant checkout. Install FluentCart from %s if it is not detected.', 'blt-events' ), '<a href="https://fluentcart.com" target="_blank" rel="noopener noreferrer">fluentcart.com</a>' ) ); ?></p>
				</div>
			</div>

			<?php self::render_save_button(); ?>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Tab: Emails
	 * ------------------------------------------------------------------ */

	private static function render_tab_emails() {
		$variables = array( '{customer_name}', '{event_name}', '{event_date}', '{event_time}', '{event_url}' );

		$emails = array(
			array(
				'title'        => __( 'Registration Confirmation', 'blt-events' ),
				'desc'         => __( 'Sent immediately after a successful registration.', 'blt-events' ),
				'subject_key'  => 'blt_events_email_subject_registration',
				'subject_def'  => __( 'Registration confirmation for {event_name}', 'blt-events' ),
				'body_key'     => 'blt_events_email_template_registration',
				'body_def'     => __( 'Hello {customer_name}, your registration for {event_name} on {event_date} at {event_time} has been confirmed.', 'blt-events' ),
			),
			array(
				'title'        => __( '24-Hour Reminder', 'blt-events' ),
				'desc'         => __( 'Sent to attendees one day before the event starts.', 'blt-events' ),
				'subject_key'  => 'blt_events_email_subject_reminder_24h',
				'subject_def'  => __( 'Reminder: {event_name} is tomorrow', 'blt-events' ),
				'body_key'     => 'blt_events_email_template_reminder_24h',
				'body_def'     => __( 'Hello {customer_name}, your event {event_name} is tomorrow ({event_date}) at {event_time}.', 'blt-events' ),
			),
			array(
				'title'        => __( '1-Hour Reminder', 'blt-events' ),
				'desc'         => __( 'Sent to attendees one hour before the event starts.', 'blt-events' ),
				'subject_key'  => 'blt_events_email_subject_reminder_1h',
				'subject_def'  => __( 'Reminder: {event_name} starts in 1 hour', 'blt-events' ),
				'body_key'     => 'blt_events_email_template_reminder_1h',
				'body_def'     => __( 'Hello {customer_name}, your event {event_name} starts in 1 hour at {event_time}.', 'blt-events' ),
			),
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_emails' ); ?>

			<div class="blt-callout">
				<strong><?php esc_html_e( 'Template variables', 'blt-events' ); ?></strong>
				<span><?php esc_html_e( 'Use these placeholders in any subject or body — they are replaced per attendee when the email is sent:', 'blt-events' ); ?></span>
				<span class="blt-chips">
					<?php foreach ( $variables as $variable ) : ?>
						<code class="blt-chip"><?php echo esc_html( $variable ); ?></code>
					<?php endforeach; ?>
				</span>
			</div>

			<?php foreach ( $emails as $email ) : ?>
				<div class="blt-card">
					<div class="blt-card-header">
						<h2><?php echo esc_html( $email['title'] ); ?></h2>
						<p><?php echo esc_html( $email['desc'] ); ?></p>
					</div>
					<div class="blt-card-body">
						<?php
						self::render_field( __( 'Subject', 'blt-events' ), function () use ( $email ) {
							?>
							<input type="text" name="<?php echo esc_attr( $email['subject_key'] ); ?>" value="<?php echo esc_attr( get_option( $email['subject_key'], $email['subject_def'] ) ); ?>" class="large-text" />
							<?php
						} );
						self::render_field( __( 'Body', 'blt-events' ), function () use ( $email ) {
							wp_editor(
								get_option( $email['body_key'], $email['body_def'] ),
								$email['body_key'],
								array(
									'textarea_name' => $email['body_key'],
									'textarea_rows' => 6,
								)
							);
						} );
						?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php self::render_save_button(); ?>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Tab: Integrations
	 * ------------------------------------------------------------------ */

	private static function render_tab_integrations() {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_integrations' ); ?>

			<div class="blt-callout">
				<strong><?php esc_html_e( 'Online Meeting Integrations', 'blt-events' ); ?></strong>
				<span><?php esc_html_e( 'Connect the platforms you use. Once a provider is connected, online and hybrid events can auto-create a meeting room from the event editor and drop the join link in automatically.', 'blt-events' ); ?></span>
			</div>

			<?php self::render_meeting_provider_cards(); ?>
			<?php self::render_fluentcrm_card(); ?>

			<?php self::render_save_button(); ?>
		</form>
		<?php
	}

	private static function render_meeting_provider_cards() {
		if ( ! class_exists( 'BLT_Events_Meeting_Providers' ) ) {
			return;
		}

		foreach ( BLT_Events_Meeting_Providers::all() as $provider ) {
			$connected = $provider->is_connected();
			$slug      = $provider->slug();
			?>
			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php echo esc_html( $provider->name() ); ?></h2>
					<div class="blt-card-header-badges">
						<?php self::render_status_badge( $connected, __( 'Connected', 'blt-events' ), __( 'Not connected', 'blt-events' ) ); ?>
						<?php if ( $provider->is_oauth() ) : ?>
							<?php if ( $connected ) : ?>
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
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
				<div class="blt-card-body">
					<?php
					foreach ( $provider->credential_fields() as $field ) {
						$option_name = 'blt_events_' . $field['key'];
						self::render_field(
							$field['label'],
							function () use ( $field, $option_name ) {
								if ( ! empty( $field['secret'] ) ) {
									self::render_secret_field( $option_name );
								} else {
									?>
									<input type="text" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( get_option( $option_name, '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" />
									<?php
								}
							},
							$field['description'] ?? ''
						);
					}

					if ( $provider->is_oauth() ) {
						self::render_field(
							__( 'Redirect URI', 'blt-events' ),
							function () use ( $provider ) {
								?>
								<code class="blt-redirect-uri"><?php echo esc_html( $provider->callback_url() ); ?></code>
								<?php
							},
							__( 'Register this exact URL as an allowed redirect URI in the provider\'s developer console.', 'blt-events' )
						);

						if ( ! $connected && ! $provider->is_configured() ) {
							?>
							<p class="blt-field-desc"><?php esc_html_e( 'Enter and save the client credentials above to enable connecting.', 'blt-events' ); ?></p>
							<?php
						} elseif ( ! $connected ) {
							?>
							<p class="blt-field-desc"><?php esc_html_e( 'Save the client credentials first, then connect.', 'blt-events' ); ?></p>
							<?php
						}
					}
					?>
				</div>
			</div>
			<?php
		}
	}

	private static function render_fluentcrm_card() {
		$fluentcrm_active = defined( 'FLUENTCRM' );
		?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'FluentCRM', 'blt-events' ); ?></h2>
				<div class="blt-card-header-badges">
					<?php self::render_status_badge( $fluentcrm_active, __( 'Active', 'blt-events' ), __( 'Not Detected', 'blt-events' ) ); ?>
				</div>
			</div>
			<div class="blt-card-body">
				<?php if ( ! $fluentcrm_active ) : ?>
					<p class="blt-field-desc"><?php esc_html_e( 'Install and activate FluentCRM to sync registrants to lists and tags automatically.', 'blt-events' ); ?></p>
				<?php else : ?>
					<?php
					$fluentcrm_fields = array(
						'blt_events_fluentcrm_list_id'          => __( 'Default List ID', 'blt-events' ),
						'blt_events_fluentcrm_registration_tag' => __( 'Registration Tag ID', 'blt-events' ),
						'blt_events_fluentcrm_confirmed_tag'    => __( 'Confirmed Tag ID', 'blt-events' ),
						'blt_events_fluentcrm_refunded_tag'     => __( 'Refunded Tag ID', 'blt-events' ),
					);
					foreach ( $fluentcrm_fields as $option_name => $label ) {
						self::render_field( $label, function () use ( $option_name ) {
							?>
							<input type="number" min="0" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( get_option( $option_name, '' ) ); ?>" class="small-text" />
							<?php
						} );
					}
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Tab: Shortcodes (read-only reference)
	 * ------------------------------------------------------------------ */

	private static function render_tab_shortcodes() {
		$shortcodes = array(
			array(
				'tag'     => '[blt_events_calendar]',
				'title'   => __( 'Events Calendar', 'blt-events' ),
				'desc'    => __( 'Displays your published events. Three layouts are available: a list of event cards, a card grid, and a full month calendar with previous/next navigation.', 'blt-events' ),
				'example' => '[blt_events_calendar view="calendar" switcher="yes"]',
				'atts'    => array(
					array( 'view', 'list', __( 'Layout to render: "list" (event cards in a vertical list), "grid" (card grid), or "calendar" (month grid with navigation).', 'blt-events' ) ),
					array( 'category', '—', __( 'Limit to one or more event category slugs, comma-separated (e.g. category="webinars,meetups").', 'blt-events' ) ),
					array( 'limit', '12', __( 'Maximum number of events to show in list/grid views (1–100). The calendar view always shows the whole month.', 'blt-events' ) ),
					array( 'past', 'no', __( 'Set to "yes" to include past events in list/grid views.', 'blt-events' ) ),
					array( 'switcher', 'no', __( 'Set to "yes" to show a List / Grid / Month view switcher above the events, letting visitors flip between layouts.', 'blt-events' ) ),
				),
			),
			array(
				'tag'     => '[blt_event_registration]',
				'title'   => __( 'Registration Form', 'blt-events' ),
				'desc'    => __( 'Renders the registration form for an event, including ticket selection, attendee fields, coupons, and payment. On a single event page it picks up the event automatically.', 'blt-events' ),
				'example' => '[blt_event_registration event_id="123"]',
				'atts'    => array(
					array( 'event_id', __( 'current event', 'blt-events' ), __( 'The ID of the event to register for. Optional inside a single event page.', 'blt-events' ) ),
				),
			),
		);
		?>
		<div class="blt-callout">
			<strong><?php esc_html_e( 'Shortcode reference', 'blt-events' ); ?></strong>
			<span><?php esc_html_e( 'Paste any of these shortcodes into a page, post, or block to display BLT Events content on the front end.', 'blt-events' ); ?></span>
		</div>

		<?php foreach ( $shortcodes as $shortcode ) : ?>
			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php echo esc_html( $shortcode['title'] ); ?></h2>
					<div class="blt-card-header-badges">
						<button type="button" class="button blt-copy-shortcode" data-shortcode="<?php echo esc_attr( $shortcode['tag'] ); ?>" data-copied-label="<?php esc_attr_e( 'Copied!', 'blt-events' ); ?>">
							<?php esc_html_e( 'Copy shortcode', 'blt-events' ); ?>
						</button>
					</div>
				</div>
				<div class="blt-card-body">
					<p class="blt-shortcode-tag"><code><?php echo esc_html( $shortcode['tag'] ); ?></code></p>
					<p class="blt-field-desc"><?php echo esc_html( $shortcode['desc'] ); ?></p>

					<table class="blt-atts-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Attribute', 'blt-events' ); ?></th>
								<th><?php esc_html_e( 'Default', 'blt-events' ); ?></th>
								<th><?php esc_html_e( 'Description', 'blt-events' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $shortcode['atts'] as $att ) : ?>
								<tr>
									<td><code><?php echo esc_html( $att[0] ); ?></code></td>
									<td><code><?php echo esc_html( $att[1] ); ?></code></td>
									<td><?php echo esc_html( $att[2] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="blt-shortcode-example">
						<span class="blt-shortcode-example-label"><?php esc_html_e( 'Example', 'blt-events' ); ?></span>
						<code><?php echo esc_html( $shortcode['example'] ); ?></code>
						<button type="button" class="button-link blt-copy-shortcode" data-shortcode="<?php echo esc_attr( $shortcode['example'] ); ?>" data-copied-label="<?php esc_attr_e( 'Copied!', 'blt-events' ); ?>"><?php esc_html_e( 'Copy', 'blt-events' ); ?></button>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		<?php
	}
}
