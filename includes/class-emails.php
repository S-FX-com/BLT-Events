<?php
/**
 * BLT Events - Emails
 *
 * One place that knows how every email the plugin sends is built: the
 * template catalogue (subject/body options and their defaults), the
 * placeholder replacements, the From headers, the optional HTML wrapper,
 * and the hooks that let a site change any of it.
 *
 * Sending is driven by registration lifecycle actions:
 *
 *   blt_registration_created    -> confirmation, pending or waitlist email
 *                                  (by status) + admin notification
 *   blt_registration_confirmed  -> confirmation email (manual approval,
 *                                  waitlist promotion, delayed payment)
 *
 * Reminders are sent by BLT_Events_Reminders through send().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Emails {

	public static function init() {
		add_action( 'blt_registration_created', array( __CLASS__, 'on_created' ), 10, 2 );
		add_action( 'blt_registration_confirmed', array( __CLASS__, 'on_confirmed' ), 10, 1 );
		add_action( 'blt_events_waitlist_spot_opened', array( __CLASS__, 'on_spot_opened' ), 10, 2 );
	}

	/* ------------------------------------------------------------------
	 * Catalogue
	 * ---------------------------------------------------------------- */

	/**
	 * Every email the plugin can send.
	 *
	 * Keys: title, desc, subject_key, subject_def, body_key, body_def,
	 * to ('customer'|'admin'), enabled_key (optional toggle option),
	 * enabled_default, attach_ics (bool).
	 *
	 * @return array<string,array>
	 */
	public static function templates() {
		$templates = array(
			'registration' => array(
				'title'           => __( 'Registration Confirmation', 'blt-events' ),
				'desc'            => __( 'Sent when a registration is confirmed: immediately for free and paid registrations, or once an admin approves a pending one.', 'blt-events' ),
				'subject_key'     => 'blt_events_email_subject_registration',
				'subject_def'     => __( 'Registration confirmation for {event_name}', 'blt-events' ),
				'body_key'        => 'blt_events_email_template_registration',
				'body_def'        => __( "Hello {customer_name},\n\nYour registration for {event_name} on {event_date} at {event_time} has been confirmed.\n\n{tickets}\n\nLocation: {event_location}\nEvent details: {event_url}", 'blt-events' ),
				'to'              => 'customer',
				'attach_ics'      => true,
			),
			'pending' => array(
				'title'           => __( 'Pending Approval', 'blt-events' ),
				'desc'            => __( 'Sent when a registration is received but held for review (manual approval, or a payment that needs checking).', 'blt-events' ),
				'subject_key'     => 'blt_events_email_subject_pending',
				'subject_def'     => __( 'We received your registration for {event_name}', 'blt-events' ),
				'body_key'        => 'blt_events_email_template_pending',
				'body_def'        => __( "Hello {customer_name},\n\nThanks for registering for {event_name} on {event_date}. Your registration is being reviewed and you will receive a confirmation as soon as it is approved.", 'blt-events' ),
				'to'              => 'customer',
				'enabled_key'     => 'blt_events_email_pending_enabled',
				'enabled_default' => '1',
			),
			'waitlist' => array(
				'title'           => __( 'Waitlist Confirmation', 'blt-events' ),
				'desc'            => __( 'Sent when someone joins the waitlist of a sold-out event.', 'blt-events' ),
				'subject_key'     => 'blt_events_email_subject_waitlist',
				'subject_def'     => __( 'You are on the waitlist for {event_name}', 'blt-events' ),
				'body_key'        => 'blt_events_email_template_waitlist',
				'body_def'        => __( "Hello {customer_name},\n\n{event_name} on {event_date} is currently full. You have been added to the waitlist and we will email you if a spot opens up.", 'blt-events' ),
				'to'              => 'customer',
				'enabled_key'     => 'blt_events_email_waitlist_enabled',
				'enabled_default' => '1',
			),
			'reminder_24h' => array(
				'title'           => __( '24-Hour Reminder', 'blt-events' ),
				'desc'            => __( 'Sent to confirmed attendees one day before the event starts.', 'blt-events' ),
				'subject_key'     => 'blt_events_email_subject_reminder_24h',
				'subject_def'     => __( 'Reminder: {event_name} is tomorrow', 'blt-events' ),
				'body_key'        => 'blt_events_email_template_reminder_24h',
				'body_def'        => __( "Hello {customer_name},\n\nYour event {event_name} is tomorrow ({event_date}) at {event_time}.\n\nLocation: {event_location}\n{event_online_url}", 'blt-events' ),
				'to'              => 'customer',
				'enabled_key'     => 'blt_events_reminder_24h_enabled',
				'enabled_default' => '1',
			),
			'reminder_1h' => array(
				'title'           => __( '1-Hour Reminder', 'blt-events' ),
				'desc'            => __( 'Sent to confirmed attendees one hour before the event starts.', 'blt-events' ),
				'subject_key'     => 'blt_events_email_subject_reminder_1h',
				'subject_def'     => __( 'Reminder: {event_name} starts in 1 hour', 'blt-events' ),
				'body_key'        => 'blt_events_email_template_reminder_1h',
				'body_def'        => __( "Hello {customer_name},\n\nYour event {event_name} starts in 1 hour at {event_time}.\n\nLocation: {event_location}\n{event_online_url}", 'blt-events' ),
				'to'              => 'customer',
				'enabled_key'     => 'blt_events_reminder_1h_enabled',
				'enabled_default' => '1',
			),
			'admin_new' => array(
				'title'           => __( 'New Registration (admin)', 'blt-events' ),
				'desc'            => __( 'Sent to the site administrator (or the address below) whenever a registration comes in.', 'blt-events' ),
				'subject_key'     => 'blt_events_email_subject_admin_new',
				'subject_def'     => __( '[{site_name}] New registration: {event_name}', 'blt-events' ),
				'body_key'        => 'blt_events_email_template_admin_new',
				'body_def'        => __( "{customer_name} ({customer_email}) registered for {event_name} on {event_date}.\n\nStatus: {status}\n{tickets}\nTotal: {total}\n\nManage registrations: {admin_url}", 'blt-events' ),
				'to'              => 'admin',
				'enabled_key'     => 'blt_events_admin_notify_enabled',
				'enabled_default' => '1',
			),
		);

		/**
		 * Filter the email catalogue. Add-ons can register their own email
		 * types here; each gets a card on Settings > Emails automatically.
		 *
		 * @param array $templates Type => definition.
		 */
		return apply_filters( 'blt_events_email_templates', $templates );
	}

	/**
	 * The placeholders available in every template, with descriptions.
	 *
	 * @return array<string,string>
	 */
	public static function placeholders() {
		return apply_filters( 'blt_events_email_placeholders', array(
			'{customer_name}'       => __( 'Full name of the person who registered', 'blt-events' ),
			'{customer_first_name}' => __( 'First name', 'blt-events' ),
			'{customer_email}'      => __( 'Email address', 'blt-events' ),
			'{event_name}'          => __( 'Event title', 'blt-events' ),
			'{event_date}'          => __( 'Formatted event date', 'blt-events' ),
			'{event_time}'          => __( 'Formatted start (and end) time', 'blt-events' ),
			'{event_location}'      => __( 'Venue, address and/or online link', 'blt-events' ),
			'{event_online_url}'    => __( 'Join link (confirmed attendees only)', 'blt-events' ),
			'{event_url}'           => __( 'Link to the event page', 'blt-events' ),
			'{tickets}'             => __( 'List of tickets on the registration', 'blt-events' ),
			'{total}'               => __( 'Order total', 'blt-events' ),
			'{amount_paid}'         => __( 'Amount actually paid', 'blt-events' ),
			'{registration_id}'     => __( 'Registration number', 'blt-events' ),
			'{status}'              => __( 'Registration status', 'blt-events' ),
			'{ics_url}'             => __( 'Link to download the calendar invite', 'blt-events' ),
			'{site_name}'           => __( 'Site title', 'blt-events' ),
			'{site_url}'            => __( 'Site URL', 'blt-events' ),
			'{admin_url}'           => __( 'Link to the registrations screen (admin emails)', 'blt-events' ),
		) );
	}

	/**
	 * Whether an email type is switched on.
	 *
	 * @param string $type Email type.
	 * @return bool
	 */
	public static function is_enabled( $type ) {
		$templates = self::templates();
		if ( ! isset( $templates[ $type ] ) ) {
			return false;
		}

		$def = $templates[ $type ];
		if ( empty( $def['enabled_key'] ) ) {
			return true;
		}

		return '1' === (string) get_option( $def['enabled_key'], $def['enabled_default'] ?? '1' );
	}

	/* ------------------------------------------------------------------
	 * Lifecycle listeners
	 * ---------------------------------------------------------------- */

	public static function on_created( $registration_id, $result ) {
		$reg = self::get_registration( $registration_id );
		if ( ! $reg ) {
			return;
		}

		switch ( $reg->status ) {
			case 'confirmed':
				self::send( 'registration', $reg );
				break;
			case 'waitlisted':
				self::send( 'waitlist', $reg );
				break;
			case 'pending':
				self::send( 'pending', $reg );
				break;
		}

		self::send( 'admin_new', $reg );
	}

	public static function on_confirmed( $registration_id ) {
		$reg = self::get_registration( $registration_id );
		if ( ! $reg ) {
			return;
		}

		self::send( 'registration', $reg );
	}

	/**
	 * A seat freed up on an event with a waitlist: tell the admin.
	 *
	 * @param int $event_id       The event post ID.
	 * @param int $waitlist_count How many people are waiting.
	 */
	public static function on_spot_opened( $event_id, $waitlist_count ) {
		if ( ! self::is_enabled( 'admin_new' ) ) {
			return;
		}

		$event = get_post( $event_id );
		if ( ! $event ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: event title. */
			__( '[%1$s] A spot opened up on %2$s', 'blt-events' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$event->post_title
		);
		$body = sprintf(
			/* translators: 1: number of people waiting, 2: event title, 3: admin URL. */
			_n(
				'%1$d person is on the waitlist for %2$s. Confirm them from the registrations screen: %3$s',
				'%1$d people are on the waitlist for %2$s. Confirm them from the registrations screen: %3$s',
				$waitlist_count,
				'blt-events'
			),
			(int) $waitlist_count,
			$event->post_title,
			self::admin_registrations_url( $event_id, 'waitlisted' )
		);

		self::mail( self::admin_recipient(), $subject, $body, array(), 'waitlist_spot_opened', null );
	}

	/* ------------------------------------------------------------------
	 * Sending
	 * ---------------------------------------------------------------- */

	/**
	 * Send one of the catalogue emails for a registration.
	 *
	 * @param string $type  Email type (a key of templates()).
	 * @param object $reg   Registration row.
	 * @param array  $extra Extra placeholder replacements.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send( $type, $reg, $extra = array() ) {
		$templates = self::templates();
		if ( ! isset( $templates[ $type ] ) || ! is_object( $reg ) ) {
			return false;
		}

		if ( ! self::is_enabled( $type ) ) {
			return false;
		}

		$def   = $templates[ $type ];
		$event = get_post( $reg->event_id );
		if ( ! $event ) {
			return false;
		}

		/**
		 * Short-circuit an email. Return false to suppress it.
		 *
		 * @param bool   $send Whether to send.
		 * @param string $type Email type.
		 * @param object $reg  Registration row.
		 */
		if ( ! apply_filters( 'blt_events_should_send_email', true, $type, $reg ) ) {
			return false;
		}

		$replacements = array_merge( self::replacements( $reg, $event, $type ), (array) $extra );

		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), (string) get_option( $def['subject_key'], $def['subject_def'] ) );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), (string) get_option( $def['body_key'], $def['body_def'] ) );

		$to = ( 'admin' === ( $def['to'] ?? 'customer' ) ) ? self::admin_recipient() : $reg->customer_email;

		$attachments = array();
		$ics_path    = '';
		if ( ! empty( $def['attach_ics'] ) && get_option( 'blt_events_calendar_invite_enabled', '1' ) === '1' ) {
			$ics_path = self::write_ics( $event );
			if ( $ics_path ) {
				$attachments[] = $ics_path;
			}
		}

		$sent = self::mail( $to, $subject, $body, $attachments, $type, $reg );

		if ( $ics_path ) {
			wp_delete_file( $ics_path );
		}

		return $sent;
	}

	/**
	 * Low-level send: applies From headers, the HTML wrapper and the filters,
	 * then hands off to wp_mail().
	 *
	 * @param string|string[] $to          Recipient(s).
	 * @param string          $subject     Subject after replacements.
	 * @param string          $body        Body after replacements (may contain HTML).
	 * @param array           $attachments File paths.
	 * @param string          $type        Email type, for the filters.
	 * @param object|null     $reg         Registration row, when there is one.
	 * @return bool
	 */
	private static function mail( $to, $subject, $body, $attachments, $type, $reg ) {
		/** @var string|string[] $to */
		$to = apply_filters( 'blt_events_email_recipient', $to, $type, $reg );
		if ( empty( $to ) ) {
			return false;
		}

		$subject = apply_filters( 'blt_events_email_subject', $subject, $type, $reg );
		$body    = apply_filters( 'blt_events_email_body', $body, $type, $reg );
		$html    = self::wrap( wpautop( $body ), $subject, $type, $reg );
		$html    = apply_filters( 'blt_events_email_html', $html, $type, $reg );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$from_name  = trim( (string) get_option( 'blt_events_email_from_name', '' ) );
		$from_email = sanitize_email( (string) get_option( 'blt_events_email_from_address', '' ) );
		if ( $from_email ) {
			$headers[] = 'From: ' . ( $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
		} elseif ( $from_name ) {
			// Name only: keep the server's default address, but label it.
			$headers[] = 'From: ' . $from_name . ' <' . self::default_from_address() . '>';
		}

		$reply_to = sanitize_email( (string) get_option( 'blt_events_email_reply_to', '' ) );
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$headers     = apply_filters( 'blt_events_email_headers', $headers, $type, $reg );
		$attachments = apply_filters( 'blt_events_email_attachments', $attachments, $type, $reg );

		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );

		/**
		 * Fires after an email was handed to wp_mail().
		 *
		 * @param bool        $sent    Whether wp_mail() returned true.
		 * @param string      $type    Email type.
		 * @param object|null $reg     Registration row.
		 * @param string      $subject Subject line.
		 */
		do_action( 'blt_events_email_sent', $sent, $type, $reg, $subject );

		return (bool) $sent;
	}

	/**
	 * Optionally wrap the body in the HTML shell template.
	 */
	private static function wrap( $body_html, $subject, $type, $reg ) {
		if ( '1' !== (string) get_option( 'blt_events_email_wrapper_enabled', '1' ) ) {
			return $body_html;
		}

		$wrapped = BLT_Events_Templates::render( 'emails/wrapper.php', array(
			'body'         => $body_html,
			'subject'      => $subject,
			'type'         => $type,
			'registration' => $reg,
			'site_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'     => home_url( '/' ),
			'accent'       => BLT_Events_Appearance::get_primary() ?: '#6366f1',
		) );

		return '' !== trim( $wrapped ) ? $wrapped : $body_html;
	}

	/* ------------------------------------------------------------------
	 * Replacements
	 * ---------------------------------------------------------------- */

	/**
	 * Placeholder => value for a registration and its event.
	 *
	 * @param object  $reg   Registration row.
	 * @param WP_Post $event The event.
	 * @param string  $type  Email type (some placeholders depend on it).
	 * @return array<string,string>
	 */
	public static function replacements( $reg, $event, $type = '' ) {
		$custom = json_decode( (string) $reg->custom_fields, true );
		$custom = is_array( $custom ) ? $custom : array();

		$first_name = $custom['first_name'] ?? '';
		if ( '' === $first_name && $reg->customer_name ) {
			$first_name = explode( ' ', trim( $reg->customer_name ) )[0];
		}

		// The join link is private: only a confirmed registrant gets it.
		$online_url = '';
		if ( 'confirmed' === $reg->status ) {
			$online_url = (string) get_post_meta( $event->ID, '_blt_event_online_url', true );
		}

		$replacements = array(
			'{customer_name}'       => $reg->customer_name,
			'{customer_first_name}' => $first_name,
			'{customer_email}'      => $reg->customer_email,
			'{event_name}'          => $event->post_title,
			'{event_date}'          => BLT_Events_Helpers::event_date_label( $event->ID ),
			'{event_time}'          => BLT_Events_Helpers::event_time_label( $event->ID ),
			'{event_location}'      => BLT_Events_Helpers::get_event_location_string( $event->ID ),
			'{event_online_url}'    => $online_url,
			'{event_url}'           => get_permalink( $event->ID ),
			'{tickets}'             => self::tickets_summary( $reg ),
			'{total}'               => BLT_Events_Helpers::format_price( $reg->total_amount - $reg->discount_amount ),
			'{amount_paid}'         => BLT_Events_Helpers::format_price( $reg->amount_paid ),
			'{registration_id}'     => (string) $reg->id,
			'{status}'              => BLT_Events_Helpers::status_label( $reg->status ),
			'{ics_url}'             => BLT_Events_Helpers::get_ics_url( $event->ID ),
			'{site_name}'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'            => home_url( '/' ),
			'{admin_url}'           => self::admin_registrations_url( $event->ID ),
		);

		/**
		 * Filter the placeholder replacements for an email.
		 *
		 * Add your own `{placeholder}` here and it works in every template.
		 *
		 * @param array   $replacements Placeholder => value.
		 * @param object  $reg          Registration row.
		 * @param WP_Post $event        The event.
		 * @param string  $type         Email type.
		 */
		return apply_filters( 'blt_events_email_replacements', $replacements, $reg, $event, $type );
	}

	/**
	 * "2 × General Admission ($50.00)" lines for the tickets on a registration.
	 */
	private static function tickets_summary( $reg ) {
		$att_db    = new BLT_Events_Attendees_DB();
		$attendees = $att_db->get_by_registration( $reg->id );

		$counts = array();
		foreach ( $attendees as $att ) {
			$name = $att->ticket_type ?: __( 'Ticket', 'blt-events' );
			if ( ! isset( $counts[ $name ] ) ) {
				$counts[ $name ] = array( 'qty' => 0, 'price' => (float) $att->ticket_price );
			}
			$counts[ $name ]['qty']++;
		}

		if ( empty( $counts ) ) {
			return sprintf(
				/* translators: %d: number of attendees. */
				_n( '%d attendee', '%d attendees', (int) $reg->attendee_count, 'blt-events' ),
				(int) $reg->attendee_count
			);
		}

		$lines = array();
		foreach ( $counts as $name => $row ) {
			$lines[] = sprintf(
				'%1$d × %2$s (%3$s)',
				$row['qty'],
				$name,
				$row['price'] > 0 ? BLT_Events_Helpers::format_price( $row['price'] ) : __( 'Free', 'blt-events' )
			);
		}

		return implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------
	 * Utilities
	 * ---------------------------------------------------------------- */

	private static function get_registration( $registration_id ) {
		$db = new BLT_Events_Registrations_DB();
		return $db->get( absint( $registration_id ) );
	}

	/**
	 * Where admin notifications go: the configured address, else the site
	 * admin email. Several addresses may be comma-separated.
	 *
	 * @return string[]
	 */
	public static function admin_recipient() {
		$configured = (string) get_option( 'blt_events_admin_notify_email', '' );
		$addresses  = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $configured ) ) ) );

		if ( empty( $addresses ) ) {
			$addresses = array( get_option( 'admin_email' ) );
		}

		return array_values( $addresses );
	}

	public static function admin_registrations_url( $event_id = 0, $status = '' ) {
		$args = array(
			'post_type' => 'event',
			'page'      => 'blt-registrations',
		);
		if ( $event_id ) {
			$args['event_id'] = (int) $event_id;
		}
		if ( $status ) {
			$args['status'] = $status;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Mirrors wp_mail()'s own default sender, for a "From: Name <addr>" header
	 * when only a name is configured.
	 */
	private static function default_from_address() {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 'www.' === substr( (string) $sitename, 0, 4 ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}

	/**
	 * Write the event's .ics to a temp file for attaching.
	 *
	 * @return string Path, or '' on failure.
	 */
	private static function write_ics( $event ) {
		$content = BLT_Events_Helpers::generate_ics_content( $event );
		$path    = get_temp_dir() . 'blt-event-' . $event->ID . '-' . wp_generate_password( 8, false ) . '.ics';

		if ( file_put_contents( $path, $content ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return '';
		}

		return $path;
	}
}
