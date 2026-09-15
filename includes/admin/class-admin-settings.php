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
			'appearance'   => __( 'Appearance', 'blt-events' ),
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
		// Currency is fixed at USD and is not configurable. blt_events_currency,
		// blt_events_currency_code_custom and blt_events_currency_symbol_custom are
		// legacy options: deliberately NOT registered, so options.php cannot write
		// them and any stale DB value is simply ignored. Only the two display
		// toggles below remain — they control whether the code and symbol are shown
		// at all, not which currency is used.
		register_setting( 'blt_events_settings_general', 'blt_events_display_currency', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );
		register_setting( 'blt_events_settings_general', 'blt_events_display_currency_sign', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );
		// blt_events_single_styles is deliberately NOT registered any more. It
		// is a legacy option, read only to seed the new styling mode on an
		// upgrade. Leaving it in this group would be actively harmful: the
		// field no longer renders, and options.php writes null for every
		// registered option missing from the POST — so saving the General tab
		// would clear it and read back as "styles off".
		register_setting( 'blt_events_settings_general', 'blt_events_events_page_id', array(
			'sanitize_callback' => 'absint',
		) );
		register_setting( 'blt_events_settings_general', 'blt_events_map_provider', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_map_provider' ),
		) );
		register_setting( 'blt_events_settings_general', 'blt_events_google_maps_api_key', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		// --- Appearance ---
		register_setting( 'blt_events_settings_appearance', BLT_Events_Appearance::OPTION_MODE, array(
			'sanitize_callback' => array( 'BLT_Events_Appearance', 'sanitize_mode' ),
			'default'           => 'full',
		) );
		register_setting( 'blt_events_settings_appearance', BLT_Events_Appearance::OPTION_PRIMARY, array(
			'sanitize_callback' => array( 'BLT_Events_Appearance', 'sanitize_hex' ),
			'default'           => '',
		) );
		register_setting( 'blt_events_settings_appearance', BLT_Events_Appearance::OPTION_RADIUS, array(
			'sanitize_callback' => array( 'BLT_Events_Appearance', 'sanitize_radius' ),
			'default'           => '',
		) );
		register_setting( 'blt_events_settings_appearance', BLT_Events_Appearance::OPTION_FONT, array(
			'sanitize_callback' => array( 'BLT_Events_Appearance', 'sanitize_font' ),
			'default'           => '',
		) );
		register_setting( 'blt_events_settings_appearance', BLT_Events_Appearance::OPTION_WIDTH, array(
			'sanitize_callback' => array( 'BLT_Events_Appearance', 'sanitize_width' ),
			'default'           => '',
		) );

		// --- Payments ---
		register_setting( 'blt_events_settings_payments', 'blt_events_payment_provider', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_payment_provider' ),
		) );
		register_setting( 'blt_events_settings_payments', BLT_Events_Payment_Providers::OPTION_ENABLED, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_enabled_providers' ),
			'default'           => array(),
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
		register_setting( 'blt_events_settings_emails', 'blt_events_calendar_invite_enabled', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
		) );
		register_setting( 'blt_events_settings_emails', 'blt_events_calendar_invite_description', array(
			'sanitize_callback' => 'sanitize_textarea_field',
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

		// --- Integrations: presenter CPT connection ---
		register_setting( 'blt_events_settings_integrations', 'blt_events_presenter_post_type', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_presenter_post_type' ),
		) );
		foreach ( array(
			'blt_events_presenter_map_role',
			'blt_events_presenter_map_bio',
			'blt_events_presenter_map_photo',
		) as $presenter_option ) {
			register_setting( 'blt_events_settings_integrations', $presenter_option, array(
				'sanitize_callback' => 'sanitize_key',
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
		return BLT_Events_Payment_Providers::exists( $value ) ? $value : 'none';
	}

	/**
	 * Keep only slugs the plugin actually knows how to drive.
	 *
	 * The submitted list is authoritative, including when it is empty — that is
	 * how an admin switches every provider off. get_enabled() re-adds the site
	 * default afterwards, so a site can never end up with a default it has
	 * disabled.
	 */
	public static function sanitize_enabled_providers( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_intersect(
			array_map( 'sanitize_text_field', $value ),
			BLT_Events_Payment_Providers::get_slugs()
		) );
	}

	public static function sanitize_checkbox( $value ) {
		return $value === '1' ? '1' : '0';
	}

	public static function sanitize_map_provider( $value ) {
		$allowed = array( 'none', 'osm', 'google' );
		return in_array( $value, $allowed, true ) ? $value : 'osm';
	}

	/**
	 * Only allow connecting to a real, registered public post type (and
	 * never the plugin's own event/coupon types).
	 */
	public static function sanitize_presenter_post_type( $value ) {
		$value = sanitize_key( $value );

		if ( $value === '' || in_array( $value, array( 'event', 'blt_coupon' ), true ) ) {
			return '';
		}

		return post_type_exists( $value ) ? $value : '';
	}


	/**
	 * Keep the previously stored secret when the field is submitted blank,
	 * so secrets never need to be rendered back into the page.
	 *
	 * Blank-means-keep leaves no way to empty a secret, which matters now that
	 * an empty local value is what lets a shared BLT credential apply: a site
	 * with a key already saved could otherwise never migrate to a shared one.
	 * render_secret_field() therefore prints a companion "clear" checkbox, and
	 * blank + that box ticked is the only way to erase the value.
	 *
	 * Reading $_POST here is safe: this runs as a register_setting()
	 * sanitize_callback, so options.php has already checked the nonce and the
	 * user's capability, and the checkbox is part of the same submission.
	 */
	public static function sanitize_secret( $value, $option_name ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( $value === '' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce before sanitizing.
			$clear = ! empty( $_POST[ $option_name . '_clear' ] );

			return $clear ? '' : get_option( $option_name, '' );
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

		if ( ! $has_value ) {
			return;
		}

		// The only way to empty a secret, since a blank field means "keep it".
		// Needed to hand a credential over to the shared BLT store: this
		// plugin's own value always wins, so the shared one applies only once
		// nothing is stored here.
		printf(
			'<p class="blt-field-desc"><label><input type="checkbox" name="%1$s_clear" value="1" /> %2$s</label></p>',
			esc_attr( $option_name ),
			esc_html__( 'Clear the saved value', 'blt-events' )
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

	/**
	 * The "Check for Updates" action shown in the page header.
	 *
	 * BLT Events updates from its own GitHub releases. Under the shared family
	 * policy the automatic check runs once a day, anchored to 00:00 site time;
	 * this link is the manual path and runs immediately, bypassing that floor.
	 * plugin-update-checker's own handler verifies the nonce and the user's
	 * capability, then redirects to the Plugins screen with the result notice.
	 */
	private static function render_update_action() {
		if ( ! class_exists( 'BLT_Family_Updates' ) ) {
			return;
		}

		// The checker instance is the global the main plugin file builds.
		$checker    = isset( $GLOBALS['blt_events_update_checker'] ) ? $GLOBALS['blt_events_update_checker'] : null;
		$last_check = $checker ? BLT_Family_Updates::last_check_time( $checker ) : 0;
		?>
		<div class="blt-admin-page-actions">
			<?php if ( $last_check > 0 ) : ?>
				<span class="blt-admin-page-header-meta">
					<?php
					printf(
						/* translators: %s: human-readable time difference, e.g. "3 hours". */
						esc_html__( 'Last checked %s ago', 'blt-events' ),
						esc_html( human_time_diff( $last_check ) )
					);
					?>
				</span>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( BLT_Family_Updates::check_now_url( 'blt-events' ) ); ?>">
				<?php esc_html_e( 'Check for Updates', 'blt-events' ); ?>
			</a>
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
				<?php self::render_update_action(); ?>
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
					case 'appearance':
						self::render_tab_appearance();
						break;
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
		self::render_setup_feedback();
		BLT_Events_Setup::render_card();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_general' ); ?>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Events Page', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'The page that lists your events (usually one holding the [blt_events_calendar] shortcode). Used for "back to events" links.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'Events Page', 'blt-events' ),
						function () {
							wp_dropdown_pages( array(
								'name'              => 'blt_events_events_page_id',
								'id'                => 'blt_events_events_page_id',
								'selected'          => (int) get_option( 'blt_events_events_page_id', 0 ),
								'show_option_none'  => __( '— None —', 'blt-events' ),
								'option_none_value' => 0,
							) );
						},
						__( 'Leave as “None” to use the default event archive.', 'blt-events' )
					);
					?>
				</div>
			</div>

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
					<p><?php esc_html_e( 'Prices are always in US Dollars (USD). These settings only control how the currency is shown to visitors.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
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

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Maps', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'The map shown on in-person event pages. With maps off (or no coordinates), the venue name and address still display.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'Map Provider', 'blt-events' ),
						function () {
							$providers = array(
								'osm'    => __( 'OpenStreetMap (no key required)', 'blt-events' ),
								'google' => __( 'Google Maps', 'blt-events' ),
								'none'   => __( 'No map (address only)', 'blt-events' ),
							);
							$selected = get_option( 'blt_events_map_provider', 'osm' );
							echo '<select name="blt_events_map_provider">';
							foreach ( $providers as $value => $label ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
							}
							echo '</select>';
						}
					);

					self::render_field(
						__( 'Google Maps API Key', 'blt-events' ),
						function () {
							?>
							<input type="text" name="blt_events_google_maps_api_key" value="<?php echo esc_attr( get_option( 'blt_events_google_maps_api_key', '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php
						},
						__( 'Required only for the Google Maps provider. Needs the "Maps Embed API" enabled on the key.', 'blt-events' )
					);
					?>
				</div>
			</div>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Front-End Styling', 'blt-events' ); ?></h2>
				</div>
				<div class="blt-card-body">
					<p class="blt-field-desc">
						<?php
						printf(
							/* translators: %s: link to the Appearance tab. */
							esc_html__( 'Styling now covers the whole front end, not just the event page. It moved to %s.', 'blt-events' ),
							'<a href="' . esc_url( self::tab_url( 'appearance' ) ) . '">' . esc_html__( 'the Appearance tab', 'blt-events' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>

			<?php self::render_save_button(); ?>
		</form>
		<?php
	}

	/**
	 * Result of a one-click setup action, on the redirect back.
	 */
	private static function render_setup_feedback() {
		$result = isset( $_GET['blt-setup'] ) ? sanitize_key( wp_unslash( $_GET['blt-setup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $result ) {
			return;
		}

		$messages = array(
			'page-created' => array( 'success', __( 'Events page created and selected below.', 'blt-events' ) ),
			'page-failed'  => array( 'error', __( 'The events page could not be created. Add a page with the [blt_events_calendar] shortcode yourself, then select it below.', 'blt-events' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/* --------------------------------------------------------------------
	 * Tab: Appearance
	 * ------------------------------------------------------------------ */

	private static function render_tab_appearance() {
		$mode    = BLT_Events_Appearance::get_mode();
		$primary = BLT_Events_Appearance::get_primary();
		$radius  = (string) get_option( BLT_Events_Appearance::OPTION_RADIUS, '' );
		$font    = (string) get_option( BLT_Events_Appearance::OPTION_FONT, '' );
		$width   = (string) get_option( BLT_Events_Appearance::OPTION_WIDTH, '' );

		$modes = array(
			'full'     => array(
				'name' => __( 'Styled', 'blt-events' ),
				'desc' => __( 'The plugin brings its own design. Nothing to set up — it looks finished on any theme.', 'blt-events' ),
			),
			'skeleton' => array(
				'name' => __( 'Skeleton', 'blt-events' ),
				'desc' => __( 'Keeps the layout, but takes colours, radii, shadows and fonts from your framework (ACSS, your theme).', 'blt-events' ),
			),
			'off'      => array(
				'name' => __( 'No CSS', 'blt-events' ),
				'desc' => __( 'Loads no front-end stylesheet at all. You style the BEM markup entirely yourself.', 'blt-events' ),
			),
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_appearance' ); ?>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Styling Mode', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'How much of its own design the plugin brings to the front end. This applies everywhere: the calendar, the single event page and the registration form.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<div class="blt-select-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Styling mode', 'blt-events' ); ?>">
						<?php foreach ( $modes as $value => $option ) : ?>
							<label class="blt-select-card <?php echo $mode === $value ? 'is-selected' : ''; ?>">
								<input type="radio" name="<?php echo esc_attr( BLT_Events_Appearance::OPTION_MODE ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?> data-blt-style-mode />
								<span class="blt-select-card-check" aria-hidden="true"></span>
								<span class="blt-select-card-name"><?php echo esc_html( $option['name'] ); ?></span>
								<span class="blt-select-card-desc"><?php echo esc_html( $option['desc'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<p class="blt-field-desc">
						<?php esc_html_e( 'Every value the plugin draws with is a CSS custom property prefixed --blt-e-. Whichever mode you pick, you can redefine any of them from your own stylesheet — no !important needed.', 'blt-events' ); ?>
					</p>
				</div>
			</div>

			<div class="blt-card" data-blt-style-panel <?php echo 'off' === $mode ? 'style="display:none;"' : ''; ?>>
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Overrides', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Leave a field blank to keep what the mode above already gives you. Anything set here wins over both.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'Accent Colour', 'blt-events' ),
						function () use ( $primary ) {
							?>
							<input
								type="text"
								name="<?php echo esc_attr( BLT_Events_Appearance::OPTION_PRIMARY ); ?>"
								value="<?php echo esc_attr( $primary ); ?>"
								class="regular-text blt-color-field"
								placeholder="#6366f1"
								data-blt-preview-primary />
							<?php
						},
						__( 'Used for buttons, links and highlights. Hover, tint and focus states are derived from it automatically, including a readable text colour on top.', 'blt-events' )
					);

					self::render_field(
						__( 'Corner Radius', 'blt-events' ),
						function () use ( $radius ) {
							?>
							<input
								type="number"
								name="<?php echo esc_attr( BLT_Events_Appearance::OPTION_RADIUS ); ?>"
								value="<?php echo esc_attr( $radius ); ?>"
								class="small-text"
								min="0"
								max="60"
								step="1"
								placeholder="10"
								data-blt-preview-radius />
							<span class="blt-unit">px</span>
							<?php
						},
						__( 'Applies to cards, inputs and buttons. Enter 0 for square corners.', 'blt-events' )
					);

					self::render_field(
						__( 'Typeface', 'blt-events' ),
						function () use ( $font ) {
							?>
							<select name="<?php echo esc_attr( BLT_Events_Appearance::OPTION_FONT ); ?>" class="blt-input">
								<option value="" <?php selected( $font, '' ); ?>><?php esc_html_e( 'Plugin system stack', 'blt-events' ); ?></option>
								<option value="theme" <?php selected( $font, 'theme' ); ?>><?php esc_html_e( 'Inherit from the theme', 'blt-events' ); ?></option>
							</select>
							<?php
						},
						__( 'Skeleton mode already inherits the theme font; this forces it in the other modes too.', 'blt-events' )
					);

					self::render_field(
						__( 'Content Width', 'blt-events' ),
						function () use ( $width ) {
							?>
							<input
								type="number"
								name="<?php echo esc_attr( BLT_Events_Appearance::OPTION_WIDTH ); ?>"
								value="<?php echo esc_attr( $width ); ?>"
								class="small-text"
								min="480"
								max="2400"
								step="10"
								placeholder="1200" />
							<span class="blt-unit">px</span>
							<?php
						},
						__( 'Maximum width of the calendar and event layouts. Leave blank to follow the theme.', 'blt-events' )
					);
					?>
				</div>
			</div>

			<div class="blt-card" data-blt-style-panel <?php echo 'off' === $mode ? 'style="display:none;"' : ''; ?>>
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Preview', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'An event card and a register button, drawn with the tokens above. Updates as you type; save to apply it to the site.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<div class="blt-style-preview" data-blt-preview>
						<div class="blt-style-preview__card">
							<span class="blt-style-preview__tag"><?php esc_html_e( 'Webinar', 'blt-events' ); ?></span>
							<h4 class="blt-style-preview__title"><?php esc_html_e( 'Quarterly Product Briefing', 'blt-events' ); ?></h4>
							<p class="blt-style-preview__meta"><?php esc_html_e( 'Thursday 14 May · 10:00 – 11:30', 'blt-events' ); ?></p>
							<button type="button" class="blt-style-preview__btn" disabled><?php esc_html_e( 'Register', 'blt-events' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Going Further', 'blt-events' ); ?></h2>
				</div>
				<div class="blt-card-body">
					<p class="blt-field-desc"><?php esc_html_e( 'To change anything not listed above, redefine the token in your own stylesheet or a code snippet. Yours loads after the plugin\'s, so it wins:', 'blt-events' ); ?></p>
					<pre class="blt-code-block">:root {
	--blt-e-primary: var(--action);
	--blt-e-radius: var(--radius-m);
	--blt-e-font: var(--body-font);
}</pre>
					<p class="blt-field-desc">
						<?php
						printf(
							/* translators: %s: the tokens stylesheet filename. */
							esc_html__( 'The full list of tokens, with comments, is in %s.', 'blt-events' ),
							'<code>assets/css/blt-events-tokens.css</code>'
						);
						?>
					</p>
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
		$payment_provider = BLT_Events_Payment_Providers::get_default();
		$enabled          = BLT_Events_Payment_Providers::get_enabled();
		$registry         = BLT_Events_Payment_Providers::get_registry();

		$providers = array(
			'none' => array(
				'name' => __( 'None', 'blt-events' ),
				'desc' => __( 'Free events only — no checkout.', 'blt-events' ),
			),
		);

		foreach ( $registry as $slug => $provider ) {
			$providers[ $slug ] = array(
				'name' => $provider['label'],
				'desc' => $provider['description'],
			);
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'blt_events_settings_payments' ); ?>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Payment Processors', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Switch on every processor this site uses. More than one can run at a time — each event can then choose which of them it checks out through.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					// An empty array is a meaningful submission (everything off),
					// but browsers omit unchecked boxes entirely. Without this the
					// option would keep its old value instead of being cleared.
					?>
					<input type="hidden" name="<?php echo esc_attr( BLT_Events_Payment_Providers::OPTION_ENABLED ); ?>[]" value="" />

					<div class="blt-toggle-stack">
						<?php foreach ( $registry as $slug => $provider ) :
							$class      = $provider['class'];
							$configured = class_exists( $class ) && call_user_func( array( $class, 'is_configured' ) );
							?>
							<label class="blt-toggle">
								<input
									type="checkbox"
									name="<?php echo esc_attr( BLT_Events_Payment_Providers::OPTION_ENABLED ); ?>[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $enabled, true ) ); ?> />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text">
									<span class="blt-toggle-label">
										<?php echo esc_html( $provider['label'] ); ?>
										<?php self::render_status_badge( $configured, __( 'Configured', 'blt-events' ), __( 'Not configured', 'blt-events' ) ); ?>
									</span>
									<span class="blt-toggle-desc"><?php echo esc_html( $provider['description'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>

					<p class="blt-field-desc">
						<?php esc_html_e( 'Switching a processor off stops new events from using it and hides its checkout. Its existing orders keep resolving, so refunds on past purchases still reach the right registration.', 'blt-events' ); ?>
					</p>
				</div>
			</div>

			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Default Processor', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'Used by every event that does not pick one of its own, on the Registration & Tickets panel of the event editor.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<div class="blt-select-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Default payment processor', 'blt-events' ); ?>">
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
			<div class="blt-card blt-provider-panel" data-provider="stripe" <?php echo ! in_array( 'stripe', $enabled, true ) ? 'style="display:none;"' : ''; ?>>
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
			<div class="blt-card blt-provider-panel" data-provider="surecart" <?php echo ! in_array( 'surecart', $enabled, true ) ? 'style="display:none;"' : ''; ?>>
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
			<div class="blt-card blt-provider-panel" data-provider="fluentcart" <?php echo ! in_array( 'fluentcart', $enabled, true ) ? 'style="display:none;"' : ''; ?>>
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
		$variables = array( '{customer_name}', '{event_name}', '{event_date}', '{event_time}', '{event_location}', '{event_url}' );

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

			<!-- Calendar Invite -->
			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Calendar Invite', 'blt-events' ); ?></h2>
					<p><?php esc_html_e( 'The .ics calendar invite attached to confirmation emails and offered on event pages. The description below is what appears inside the invite.', 'blt-events' ); ?></p>
				</div>
				<div class="blt-card-body">
					<?php
					self::render_field(
						__( 'Attach to Emails', 'blt-events' ),
						function () {
							?>
							<label class="blt-toggle">
								<input type="checkbox" name="blt_events_calendar_invite_enabled" value="1" <?php checked( get_option( 'blt_events_calendar_invite_enabled', '1' ), '1' ); ?> />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text">
									<span class="blt-toggle-label"><?php esc_html_e( 'Attach a calendar invite (.ics) to registration confirmation emails', 'blt-events' ); ?></span>
								</span>
							</label>
							<?php
						}
					);

					self::render_field(
						__( 'Invite Description', 'blt-events' ),
						function () {
							$value = get_option( 'blt_events_calendar_invite_description', '' );
							if ( trim( (string) $value ) === '' ) {
								$value = BLT_Events_Helpers::default_calendar_invite_template();
							}
							?>
							<textarea name="blt_events_calendar_invite_description" rows="7" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
							<?php
						},
						__( 'Plain text shown inside the calendar entry. Variables: {event_name}, {event_date}, {event_time}, {event_location}, {event_url}', 'blt-events' )
					);
					?>
				</div>
			</div>

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
			<?php self::render_presenters_card(); ?>
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

	private static function render_presenters_card() {
		$connected = get_option( 'blt_events_presenter_post_type', '' );

		// Public, UI-enabled custom post types the site could connect to.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['event'], $post_types['blt_coupon'], $post_types['attachment'] );
		?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Presenters', 'blt-events' ); ?></h2>
				<p><?php esc_html_e( 'Optionally connect an existing "presenter" post type (e.g. an ACF-driven Speakers CPT). When connected, events pick presenters from it instead of re-entering them. Leave unset to use the built-in presenter fields on each event.', 'blt-events' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php
				self::render_field(
					__( 'Presenter Post Type', 'blt-events' ),
					function () use ( $post_types, $connected ) {
						echo '<select name="blt_events_presenter_post_type">';
						printf( '<option value="">%s</option>', esc_html__( '— Use built-in presenter fields —', 'blt-events' ) );
						foreach ( $post_types as $slug => $obj ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( $connected, $slug, false ), esc_html( $obj->labels->singular_name . ' (' . $slug . ')' ) );
						}
						echo '</select>';
					},
					__( 'Choose the post type that stores your presenters/speakers.', 'blt-events' )
				);

				self::render_field(
					__( 'Role / Title Field', 'blt-events' ),
					function () {
						?>
						<input type="text" name="blt_events_presenter_map_role" value="<?php echo esc_attr( get_option( 'blt_events_presenter_map_role', '' ) ); ?>" class="regular-text" placeholder="job_title" />
						<?php
					},
					__( 'ACF/meta field key on the presenter for their role or title. Optional.', 'blt-events' )
				);

				self::render_field(
					__( 'Bio Field', 'blt-events' ),
					function () {
						?>
						<input type="text" name="blt_events_presenter_map_bio" value="<?php echo esc_attr( get_option( 'blt_events_presenter_map_bio', '' ) ); ?>" class="regular-text" placeholder="bio" />
						<?php
					},
					__( 'ACF/meta field key for the bio. Optional — falls back to the excerpt.', 'blt-events' )
				);

				self::render_field(
					__( 'Photo Field', 'blt-events' ),
					function () {
						?>
						<input type="text" name="blt_events_presenter_map_photo" value="<?php echo esc_attr( get_option( 'blt_events_presenter_map_photo', '' ) ); ?>" class="regular-text" placeholder="headshot" />
						<?php
					},
					__( 'ACF image field key for the photo. Optional — falls back to the featured image.', 'blt-events' )
				);
				?>
			</div>
		</div>
		<?php
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

		<?php self::render_shortcode_builder(); ?>

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

	/**
	 * Interactive builder for the calendar shortcode.
	 *
	 * The reference table below it documents every attribute, but reading a
	 * table and then hand-typing a shortcode is where typos come from. The
	 * builder composes the string from real controls — including the site's
	 * actual event categories — and only emits attributes that differ from
	 * their defaults, so the result stays as short as it can be.
	 */
	private static function render_shortcode_builder() {
		$categories = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
			'number'     => 100,
		) );

		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		?>
		<div class="blt-card blt-sc-builder" data-blt-builder>
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Build a Calendar Shortcode', 'blt-events' ); ?></h2>
				<p><?php esc_html_e( 'Pick the options you want and copy the result. Anything left at its default is omitted.', 'blt-events' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php
				self::render_field(
					__( 'Layout', 'blt-events' ),
					function () {
						?>
						<select class="blt-input" data-blt-att="view" data-blt-default="list">
							<option value="list"><?php esc_html_e( 'List of event cards', 'blt-events' ); ?></option>
							<option value="grid"><?php esc_html_e( 'Card grid', 'blt-events' ); ?></option>
							<option value="calendar"><?php esc_html_e( 'Month calendar', 'blt-events' ); ?></option>
						</select>
						<?php
					}
				);

				self::render_field(
					__( 'Category', 'blt-events' ),
					function () use ( $categories ) {
						?>
						<select class="blt-input" data-blt-att="category" data-blt-default="">
							<option value=""><?php esc_html_e( 'All categories', 'blt-events' ); ?></option>
							<?php foreach ( $categories as $term ) : ?>
								<option value="<?php echo esc_attr( $term->slug ); ?>">
									<?php echo esc_html( $term->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( empty( $categories ) ) : ?>
							<p class="blt-help"><?php esc_html_e( 'No event categories exist yet.', 'blt-events' ); ?></p>
						<?php endif; ?>
						<?php
					}
				);

				self::render_field(
					__( 'Maximum Events', 'blt-events' ),
					function () {
						?>
						<input type="number" class="small-text" min="1" max="100" step="1" value="12" data-blt-att="limit" data-blt-default="12" />
						<p class="blt-help"><?php esc_html_e( 'Ignored by the month calendar, which always shows the whole month.', 'blt-events' ); ?></p>
						<?php
					}
				);

				self::render_field(
					__( 'Options', 'blt-events' ),
					function () {
						?>
						<div class="blt-toggle-stack">
							<label class="blt-toggle">
								<input type="checkbox" data-blt-att="switcher" data-blt-default="no" data-blt-on="yes" />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text">
									<span class="blt-toggle-label"><?php esc_html_e( 'View switcher', 'blt-events' ); ?></span>
									<span class="blt-toggle-desc"><?php esc_html_e( 'Lets visitors flip between list, grid and month themselves.', 'blt-events' ); ?></span>
								</span>
							</label>
							<label class="blt-toggle">
								<input type="checkbox" data-blt-att="past" data-blt-default="no" data-blt-on="yes" />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text">
									<span class="blt-toggle-label"><?php esc_html_e( 'Include past events', 'blt-events' ); ?></span>
									<span class="blt-toggle-desc"><?php esc_html_e( 'Off by default, so only upcoming events show.', 'blt-events' ); ?></span>
								</span>
							</label>
						</div>
						<?php
					}
				);
				?>

				<div class="blt-sc-result">
					<code data-blt-builder-output>[blt_events_calendar]</code>
					<button
						type="button"
						class="button button-primary blt-copy-shortcode"
						data-blt-builder-copy
						data-shortcode="[blt_events_calendar]"
						data-copied-label="<?php esc_attr_e( 'Copied!', 'blt-events' ); ?>">
						<?php esc_html_e( 'Copy', 'blt-events' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}
