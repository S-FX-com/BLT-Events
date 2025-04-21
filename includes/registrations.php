<?php
class Obie_Events_Registrations
{
    public static $nonce = OBIE_EVENTS_PLUGIN_PREFIX . 'registration_nonce';

    public static function init()
    {
        add_action('wp_ajax_obie_event_registration', array(__CLASS__, 'event_registration'));
        add_action('wp_ajax_nopriv_obie_event_registration', array(__CLASS__, 'event_registration'));
    }

    public static function event_registration()
    {
		check_ajax_referer(self::$nonce, self::$nonce);
		
        $event_id = intval($_POST['event_id']);
        $name = sanitize_text_field($_POST['customer_name']);
        $email = sanitize_email($_POST['customer_email']);
		$tickets = !empty($_POST['tickets']) ? json_decode(stripslashes($_POST['tickets']), true) : [];

        if (empty($event_id) || empty($name) || empty($email)) {
            wp_send_json_error('Missing required fields');
        }
		
		$ticket_types = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);
		$selected_tickets = [];
        foreach ($tickets as $ticket) {
            $ticket_type = $ticket_types[$ticket['index']];
			$selected_tickets[] = [
				"name" => $ticket_type['name'],
				"quantity" => $ticket['quantity'],
				"price" => $ticket_type['price'],
			];
        }

        self::process_registration($event_id, $name, $email, $selected_tickets);
    }
	
	public static function process_registration($event_id, $name, $email, $tickets, $coupon = null, $amount_saved = 0, $payment_intent = null)
	{
        if (!empty($payment_intent)) {
			$payment_intent_id = $payment_intent['id'];
			$amount_paid = $payment_intent['amount'] / 100;
			$payment_date = $payment_intent['created'];

            $registration_data = array(
                OBIE_EVENTS_PLUGIN_PREFIX . 'event_id' => $event_id,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name' => $name,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email' => $email,
                OBIE_EVENTS_PLUGIN_PREFIX . 'tickets' => $tickets,
				OBIE_EVENTS_PLUGIN_PREFIX . 'payment_intent_id' => $payment_intent_id,
                OBIE_EVENTS_PLUGIN_PREFIX . 'amount_paid'      => $amount_paid,
				OBIE_EVENTS_PLUGIN_PREFIX . 'payment_date'     => $payment_date,
                OBIE_EVENTS_PLUGIN_PREFIX . 'status'           => 'registered'
            );
        } else {
            $registration_data = array(
                OBIE_EVENTS_PLUGIN_PREFIX . 'event_id' => $event_id,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name' => $name,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email' => $email,
				OBIE_EVENTS_PLUGIN_PREFIX . 'tickets' => $tickets,
                OBIE_EVENTS_PLUGIN_PREFIX . 'status' => 'registered',
            );
        }

        // Save registration data
        $registration_id = wp_insert_post(array(
            'post_type' => Obie_Events_Registrations_CPT::$slug,
            'post_title' =>  'Registration for Event #' . $event_id,
            'post_status' => 'publish',
			'meta_input' => $registration_data
        ));

        if (!empty($coupon)) {
            Obie_Events_Coupons::apply_coupon(
                $coupon['id'],
                $registration_id,
                $amount_saved,
                $name
            );
        }

        if ($registration_id) {
            wp_send_json_success('Registration successful');
        } else {
            wp_send_json_error('Failed to save registration');
        }
	}
}
