<?php
/**
 * BLT Events - Registration Shortcode
 *
 * [blt_event_registration] - Renders the event registration form.
 * Usage: [blt_event_registration event_id="123"]
 * If no event_id, uses current post if it's an event.
 *
 * All markup comes from templates under templates/registration/ and can be
 * overridden from the theme (see BLT_Events_Templates).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Registration_Shortcode {

	public static function init() {
		add_shortcode( 'blt_event_registration', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'event_id' => 0,
		), $atts, 'blt_event_registration' );

		$event_id = absint( $atts['event_id'] );
		if ( ! $event_id ) {
			$event_id = get_the_ID();
		}

		// Backstop for shortcodes rendered outside post content (widgets,
		// page builders) where the has_shortcode() detection can't see them.
		if ( BLT_Events_Appearance::styles_enabled() ) {
			wp_enqueue_style( 'blt-events' );
		}
		wp_enqueue_script( 'blt-events' );

		if ( ! $event_id || get_post_type( $event_id ) !== 'event' ) {
			return '<p class="blt-error">' . esc_html__( 'No valid event specified.', 'blt-events' ) . '</p>';
		}

		$event = get_post( $event_id );
		if ( ! $event || $event->post_status !== 'publish' ) {
			return '<p class="blt-error">' . esc_html__( 'Event not found.', 'blt-events' ) . '</p>';
		}

		$html = self::render_for_event( $event );

		/**
		 * Filter the complete registration block for an event.
		 *
		 * @param string  $html  Rendered HTML.
		 * @param WP_Post $event The event.
		 */
		return apply_filters( 'blt_events_registration_html', $html, $event );
	}

	/**
	 * The registration block for a valid, published event.
	 */
	private static function render_for_event( $event ) {
		$event_id = $event->ID;

		// Check if registration is open
		if ( get_post_meta( $event_id, '_blt_registration_open', true ) !== '1' ) {
			return self::closed( 'not_open', $event_id );
		}

		// Check registration cutoff
		if ( BLT_Events_Helpers::registration_cutoff_passed( $event_id ) ) {
			return self::closed( 'cutoff', $event_id );
		}

		// Check capacity
		if ( BLT_Events_Helpers::is_sold_out( $event_id ) ) {
			return self::closed( 'sold_out', $event_id );
		}

		// Per event, not per site: with several providers enabled, two events
		// on the same site can check out through different processors.
		$provider = BLT_Events_Helpers::get_event_payment_provider( $event_id );

		// A free event has nothing to check out, so it never needs an
		// external processor's redirect flow even if one is configured
		// site-wide — otherwise a free event silently inherits whatever
		// checkout state (or lack of one) that processor happens to be in.
		$has_paid_tickets = BLT_Events_Helpers::ticket_price_range( $event_id )['has_paid'];

		ob_start();

		/**
		 * Fires before the registration form of an event.
		 *
		 * @param int    $event_id The event post ID.
		 * @param string $provider Payment provider slug.
		 */
		do_action( 'blt_events_before_registration_form', $event_id, $provider );

		// Route to appropriate renderer
		if ( $has_paid_tickets && $provider === 'surecart' ) {
			echo self::render_surecart_form( $event_id, $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( $has_paid_tickets && $provider === 'fluentcart' ) {
			echo self::render_fluentcart_form( $event_id, $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo self::render_standard_form( $event_id, $event, $provider ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Fires after the registration form of an event.
		 *
		 * @param int    $event_id The event post ID.
		 * @param string $provider Payment provider slug.
		 */
		do_action( 'blt_events_after_registration_form', $event_id, $provider );

		return ob_get_clean();
	}

	/**
	 * The "registration closed" notice for one reason.
	 *
	 * @param string $reason   not_open | cutoff | sold_out | no_tickets | syncing
	 * @param int    $event_id The event post ID.
	 * @return string
	 */
	public static function closed( $reason, $event_id ) {
		return BLT_Events_Templates::render( 'registration/closed.php', array(
			'reason'   => $reason,
			'message'  => self::closed_message( $reason, $event_id ),
			'event_id' => $event_id,
		) );
	}

	/**
	 * Visitor-facing text for a closed registration.
	 *
	 * @param string $reason   Reason slug.
	 * @param int    $event_id The event post ID.
	 * @return string
	 */
	public static function closed_message( $reason, $event_id ) {
		$messages = array(
			'not_open'   => __( 'Registration is currently closed for this event.', 'blt-events' ),
			'cutoff'     => __( 'Registration for this event has closed.', 'blt-events' ),
			'sold_out'   => __( 'This event is sold out.', 'blt-events' ),
			'no_tickets' => __( 'Tickets are not currently on sale for this event.', 'blt-events' ),
			'syncing'    => __( 'Tickets for this event are being set up. Please check back shortly.', 'blt-events' ),
		);

		$message = $messages[ $reason ] ?? $messages['not_open'];

		/**
		 * Filter the message shown when registration is not possible.
		 *
		 * @param string $message  The message.
		 * @param string $reason   not_open | cutoff | sold_out | no_tickets | syncing
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_registration_closed_message', $message, $reason, $event_id );
	}

	/**
	 * Localized data shared by the registration scripts.
	 */
	private static function localize( $event_id, $provider ) {
		$group_discount = get_post_meta( $event_id, '_blt_group_discount', true );
		$group_discount = is_string( $group_discount ) ? json_decode( $group_discount, true ) : $group_discount;

		wp_localize_script( 'blt-events-registration', 'bltRegData', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'blt_registration_nonce' ),
			'eventId'       => $event_id,
			'currency'      => BLT_Events_Helpers::get_currency_config(),
			'provider'      => $provider,
			// Mirrors the server-side rules so the summary shows what will
			// be charged; the server still recomputes the amount.
			'groupDiscount' => is_array( $group_discount ) ? $group_discount : null,
			'i18n'          => array(
				'next'             => __( 'Next', 'blt-events' ),
				'back'             => __( 'Back', 'blt-events' ),
				'selectTickets'    => __( 'Please select at least one ticket to continue.', 'blt-events' ),
				'stepOf'           => __( 'Step %1$d of %2$d', 'blt-events' ),
				'registerPay'      => __( 'Register & Pay', 'blt-events' ),
				'registerFree'     => __( 'Register — Free', 'blt-events' ),
				'selectToContinue' => __( 'Select tickets to continue', 'blt-events' ),
				'toReview'         => __( 'Continue to review & payment', 'blt-events' ),
				'completePayment'  => __( 'Complete payment', 'blt-events' ),
				'completeFree'     => __( 'Complete registration', 'blt-events' ),
				'registering'      => __( 'Registering…', 'blt-events' ),
				'complete'         => __( 'Registration Complete', 'blt-events' ),
				'completeTitle'    => __( 'You are registered', 'blt-events' ),
				'pendingTitle'     => __( 'Registration received', 'blt-events' ),
				'couponApplied'    => __( 'Coupon applied: %s', 'blt-events' ),
				'genericError'     => __( 'An error occurred. Please try again.', 'blt-events' ),
				/* translators: %d: attendee number. */
				'attendeeN'        => __( 'Attendee %d', 'blt-events' ),
				/* translators: 1: attendee number, 2: number of attendees. */
				'nOfTotal'         => __( '%1$d of %2$d', 'blt-events' ),
				/* translators: %d: number of tickets. */
				'ticketOne'        => __( '%d ticket', 'blt-events' ),
				/* translators: %d: number of tickets. */
				'ticketMany'       => __( '%d tickets', 'blt-events' ),
				'free'             => __( 'Free', 'blt-events' ),
				'noName'           => __( 'Name not given', 'blt-events' ),
				'chooseAtLeast'    => __( 'Please choose at least one option.', 'blt-events' ),
			),
		) );
	}

	/**
	 * Render the standard registration form (Stripe or free): a three-step
	 * checkout of Registration (tickets), Attendee details and Review &
	 * payment. The payment step is dropped whenever nothing is owed.
	 */
	private static function render_standard_form( $event_id, $event, $provider ) {
		$fieldset       = BLT_Events_Fieldsets::get_event_fieldset( $event_id );
		$fields         = BLT_Events_Fieldsets::get_fields( $fieldset );
		$consent_fields = BLT_Events_Fieldsets::get_consent_fields( $fieldset );

		// Only tickets inside their sale window and open to the current
		// visitor's role can be bought; original indexes are preserved.
		$all_ticket_types = BLT_Events_Helpers::get_ticket_types( $event_id );
		$ticket_types     = BLT_Events_Helpers::available_ticket_types( $event_id );
		$member_tickets   = BLT_Events_Helpers::member_ticket_types( $event_id );

		// Nothing on sale, and nothing a login would unlock either.
		if ( ! empty( $all_ticket_types ) && empty( $ticket_types ) && empty( $member_tickets ) ) {
			return self::closed( 'no_tickets', $event_id );
		}

		$has_paid_tickets = false;
		foreach ( $ticket_types as $t ) {
			if ( isset( $t['price'] ) && (float) $t['price'] > 0 ) {
				$has_paid_tickets = true;
				break;
			}
		}

		// Enqueue scripts
		wp_enqueue_script( 'blt-events-registration', BLT_EVENTS_PLUGIN_URL . 'assets/js/registration-form.js', array( 'jquery', 'blt-events' ), BLT_EVENTS_VERSION, true );
		self::localize( $event_id, $provider );
		wp_enqueue_script( 'blt-events-registration-steps', BLT_EVENTS_PLUGIN_URL . 'assets/js/registration-steps.js', array( 'jquery', 'blt-events-registration' ), BLT_EVENTS_VERSION, true );

		// Card fields only when Stripe can actually take the payment; an
		// event pointed at an unconfigured Stripe gets the server's
		// "requires payment" message instead of a card form that never loads.
		$takes_payment = 'stripe' === $provider && $has_paid_tickets && BLT_Events_Payment_Providers::is_available( 'stripe' );
		if ( $takes_payment ) {
			wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_enqueue_script( 'blt-events-payment' );
		}

		// Events without ticket types skip straight to the details step.
		$stepped           = ! empty( $all_ticket_types );
		$collect_attendees = get_post_meta( $event_id, '_blt_collect_attendees', true ) === '1' && $stepped;

		/**
		 * Filter the fields collected for each additional attendee.
		 *
		 * Keys map to the attendees table: name (or first_name + last_name,
		 * joined into the name), email, phone. Any other key is stored in
		 * the attendee's custom_fields.
		 *
		 * @param array $fields   Field definitions (see BLT_Events_Fieldsets::normalize_field).
		 * @param int   $event_id The event post ID.
		 */
		$attendee_fields = apply_filters( 'blt_events_attendee_fields', array(
			array( 'key' => 'first_name', 'type' => 'text', 'label' => __( 'First Name', 'blt-events' ), 'required' => true, 'width' => 'half' ),
			array( 'key' => 'last_name', 'type' => 'text', 'label' => __( 'Last Name', 'blt-events' ), 'required' => true, 'width' => 'half' ),
			array( 'key' => 'email', 'type' => 'email', 'label' => __( 'Email address', 'blt-events' ), 'required' => false, 'width' => 'half' ),
			array( 'key' => 'phone', 'type' => 'tel', 'label' => __( 'Phone', 'blt-events' ), 'required' => false, 'width' => 'half' ),
		), $event_id );

		$args = array(
			'event_id'          => $event_id,
			'event'             => $event,
			'provider'          => $provider,
			'fields'            => $fields,
			'consent_fields'    => $consent_fields,
			'ticket_types'      => $ticket_types,
			'ticket_rows'       => self::ticket_rows( $event_id ),
			'has_paid_tickets'  => $has_paid_tickets,
			'takes_payment'     => $takes_payment,
			'stepped'           => $stepped,
			'collect_attendees' => $collect_attendees,
			'attendee_fields'   => array_map( array( 'BLT_Events_Fieldsets', 'normalize_field' ), (array) $attendee_fields ),
			'nonce'             => wp_create_nonce( 'blt_registration_nonce' ),
			'spots_left'        => BLT_Events_Helpers::spots_left( $event_id ),
			'show_coupon'       => $has_paid_tickets,
			'member_login_url'  => empty( $member_tickets ) ? '' : self::member_login_url( $event_id ),
			'summary'           => self::event_summary( $event ),
			'help_email'        => self::help_email( $event_id ),
			'secure'            => is_ssl(),
		);

		/**
		 * Filter the data handed to the registration form template.
		 *
		 * @param array $args Template data.
		 * @param int   $event_id The event post ID.
		 */
		$args = apply_filters( 'blt_events_registration_form_args', $args, $event_id );

		return BLT_Events_Templates::render( 'registration/form.php', $args );
	}

	/**
	 * Every ticket type the Registration step lists, with its state.
	 *
	 * - available: can be bought now.
	 * - members:   a member rate on sale now; the visitor is logged out, so
	 *              it shows locked with a login link.
	 * - ended / upcoming: outside its sale window; shown disabled.
	 *
	 * Member rates are never advertised to a logged-in visitor without the
	 * role, and are only shown to logged-out visitors while on sale.
	 *
	 * @param int $event_id The event post ID.
	 * @return array Rows keyed by original ticket index: ticket, state, note, max.
	 */
	public static function ticket_rows( $event_id ) {
		$spots_left = BLT_Events_Helpers::spots_left( $event_id );
		$rows       = array();

		foreach ( BLT_Events_Helpers::get_ticket_types( $event_id ) as $i => $ticket ) {
			$sale = BLT_Events_Helpers::ticket_sale_state( $ticket );
			$note = '';

			if ( ! BLT_Events_Helpers::ticket_role_allowed( $ticket ) ) {
				if ( is_user_logged_in() || 'on_sale' !== $sale ) {
					continue;
				}
				$state = 'members';
				$note  = __( 'Members only', 'blt-events' );
			} elseif ( 'on_sale' === $sale ) {
				$state = 'available';
			} elseif ( 'ended' === $sale ) {
				$state = 'ended';
				$note  = __( 'Sales ended', 'blt-events' );
			} else {
				$state = 'upcoming';
				$note  = sprintf(
					/* translators: %s: date the ticket goes on sale. */
					__( 'On sale %s', 'blt-events' ),
					BLT_Events_Helpers::format_date( $ticket['sale_start_date'] )
				);
			}

			/** This filter is documented in includes/class-registrations.php */
			$max = max( 1, (int) apply_filters( 'blt_events_max_ticket_quantity', 50, $ticket, $event_id ) );
			if ( null !== $spots_left ) {
				$max = min( $max, max( 1, $spots_left ) );
			}

			$rows[ $i ] = array(
				'ticket' => $ticket,
				'state'  => $state,
				'note'   => $note,
				'max'    => $max,
			);
		}

		/**
		 * Filter the ticket rows listed on the Registration step.
		 *
		 * Remove rows to hide them (for example, unset every row whose state
		 * is 'ended' to stop showing tickets whose sale has closed).
		 *
		 * @param array $rows     Rows keyed by ticket index: ticket, state (available|members|ended|upcoming), note, max.
		 * @param int   $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_registration_ticket_rows', $rows, $event_id );
	}

	/**
	 * Where "Log in for Member Rates" sends a logged-out visitor: the login
	 * screen, returning to the registration form they came from.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function member_login_url( $event_id ) {
		$return = is_singular() ? get_permalink( get_queried_object_id() ) : get_permalink( $event_id );
		$return = $return ? $return . '#blt-registration-' . absint( $event_id ) : '';

		/**
		 * Filter the "Log in for Member Rates" link, e.g. to point at a
		 * membership plugin's own login page.
		 *
		 * @param string $url      Login URL.
		 * @param string $return   Where the visitor should come back to.
		 * @param int    $event_id The event post ID.
		 */
		return apply_filters( 'blt_events_member_login_url', wp_login_url( $return ), $return, $event_id );
	}

	/**
	 * The "Log in for Member Rates" prompt, or '' when the visitor has no
	 * member rate to unlock.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	public static function member_login_prompt( $event_id ) {
		if ( empty( BLT_Events_Helpers::member_ticket_types( $event_id ) ) ) {
			return '';
		}

		return BLT_Events_Templates::render( 'registration/member-login.php', array(
			'event_id'  => $event_id,
			'login_url' => self::member_login_url( $event_id ),
		) );
	}

	/**
	 * Event facts for the checkout's "Event summary" card.
	 *
	 * The online join link is deliberately left out: it is only shown to
	 * confirmed registrants.
	 *
	 * @param WP_Post $event The event.
	 * @return array title, image (HTML), date_label, time_label, location.
	 */
	private static function event_summary( $event ) {
		$event_id   = $event->ID;
		$event_type = get_post_meta( $event_id, '_blt_event_type', true ) ?: 'in-person';
		$address    = BLT_Events_Helpers::get_event_address( $event_id );

		if ( 'online' === $event_type ) {
			$location = __( 'Online', 'blt-events' );
		} elseif ( 'hybrid' === $event_type ) {
			$location = $address
				/* translators: %s: venue and address. */
				? sprintf( __( '%s, and online', 'blt-events' ), $address )
				: __( 'In person and online', 'blt-events' );
		} else {
			$location = $address;
		}

		$summary = array(
			'title'      => get_the_title( $event ),
			'image'      => has_post_thumbnail( $event ) ? get_the_post_thumbnail( $event, 'thumbnail', array( 'class' => 'blt-summary__img', 'alt' => '' ) ) : '',
			'date_label' => BLT_Events_Helpers::event_date_label( $event_id ),
			'time_label' => BLT_Events_Helpers::event_time_label( $event_id ),
			'location'   => $location,
		);

		/**
		 * Filter the event facts shown in the checkout's summary card.
		 *
		 * @param array   $summary title, image, date_label, time_label, location.
		 * @param WP_Post $event   The event.
		 */
		return apply_filters( 'blt_events_registration_summary', $summary, $event );
	}

	/**
	 * Address shown under "Need help?" in the checkout: the Reply-To set
	 * under Settings > Emails, else the From address. Never falls back to
	 * the site admin email, which may not be meant for the public.
	 *
	 * @param int $event_id The event post ID.
	 * @return string Email address, or '' to hide the help box.
	 */
	private static function help_email( $event_id ) {
		$email = sanitize_email( (string) get_option( 'blt_events_email_reply_to', '' ) );
		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'blt_events_email_from_address', '' ) );
		}

		/**
		 * Filter the contact address shown in the checkout's help box.
		 * Return '' to hide the box.
		 *
		 * @param string $email    Email address.
		 * @param int    $event_id The event post ID.
		 */
		return (string) apply_filters( 'blt_events_registration_help_email', $email, $event_id );
	}

	/**
	 * Render the SureCart checkout form (ticket selection + redirect).
	 */
	private static function render_surecart_form( $event_id, $event ) {
		$all_ticket_types = BLT_Events_Helpers::get_ticket_types( $event_id );
		$ticket_types     = BLT_Events_Helpers::available_ticket_types( $event_id );

		if ( ! empty( $all_ticket_types ) && empty( $ticket_types ) ) {
			return self::member_login_prompt( $event_id ) . self::closed( 'no_tickets', $event_id );
		}

		$price_ids = get_post_meta( $event_id, '_blt_sc_price_ids', true ) ?: array();

		// Check if products are synced (all tickets sync, even ones the
		// current visitor can't see).
		$all_synced = true;
		foreach ( $all_ticket_types as $i => $t ) {
			if ( empty( $price_ids[ $i ] ) ) {
				$all_synced = false;
				break;
			}
		}

		wp_enqueue_script( 'blt-events-surecart-checkout' );

		return BLT_Events_Templates::render( 'registration/surecart.php', array(
			'event_id'     => $event_id,
			'event'        => $event,
			'ticket_types' => $ticket_types,
			'price_ids'    => $price_ids,
			'ready'        => $all_synced && BLT_Events_SureCart_Integration::is_configured(),
			'message'      => self::closed_message( 'syncing', $event_id ),
			'member_login' => self::member_login_prompt( $event_id ),
		) );
	}

	/**
	 * Render the FluentCart checkout form (ticket selection + redirect
	 * to FluentCart instant checkout).
	 */
	private static function render_fluentcart_form( $event_id, $event ) {
		$all_ticket_types = BLT_Events_Helpers::get_ticket_types( $event_id );
		$ticket_types     = BLT_Events_Helpers::available_ticket_types( $event_id );

		if ( ! empty( $all_ticket_types ) && empty( $ticket_types ) ) {
			return self::member_login_prompt( $event_id ) . self::closed( 'no_tickets', $event_id );
		}

		$variation_ids = get_post_meta( $event_id, '_blt_fc_variation_ids', true );
		$variation_ids = is_array( $variation_ids ) ? $variation_ids : array();

		// Check whether every ticket type has a synced FluentCart variation
		// (all tickets sync, even ones the current visitor can't see).
		$all_synced = ! empty( $all_ticket_types );
		foreach ( $all_ticket_types as $i => $t ) {
			if ( empty( $variation_ids[ $i ] ) ) {
				$all_synced = false;
				break;
			}
		}

		wp_enqueue_script( 'blt-events-fluentcart-checkout' );

		return BLT_Events_Templates::render( 'registration/fluentcart.php', array(
			'event_id'      => $event_id,
			'event'         => $event,
			'ticket_types'  => $ticket_types,
			'variation_ids' => $variation_ids,
			'ready'         => $all_synced && BLT_Events_FluentCart_Integration::is_configured(),
			'message'       => self::closed_message( 'syncing', $event_id ),
			'member_login'  => self::member_login_prompt( $event_id ),
		) );
	}
}
