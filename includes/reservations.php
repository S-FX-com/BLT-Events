<?php
class Obie_Events_Reservations
{
    public static $nonce = OBIE_EVENTS_PLUGIN_PREFIX . 'reservation_nonce';

    public static function init()
    {
        add_action('wp_ajax_obie_event_reservation', array(__CLASS__, 'event_reservation'));
        add_action('wp_ajax_nopriv_obie_event_reservation', array(__CLASS__, 'event_reservation'));
    }

    public static function event_reservation()
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

        self::process_reservation($event_id, $name, $email, $selected_tickets);
    }
	
	public static function process_reservation($event_id, $name, $email, $tickets, $coupon = null, $amount_saved = 0, $payment_intent = null)
	{
        if (!empty($payment_intent)) {
			$payment_intent_id = $payment_intent['id'];
			$amount_paid = $payment_intent['amount'] / 100;
			$payment_date = $payment_intent['created'];

            $reservation_data = array(
                OBIE_EVENTS_PLUGIN_PREFIX . 'event_id' => $event_id,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name' => $name,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email' => $email,
                OBIE_EVENTS_PLUGIN_PREFIX . 'tickets' => $tickets,
				OBIE_EVENTS_PLUGIN_PREFIX . 'payment_intent_id' => $payment_intent_id,
                OBIE_EVENTS_PLUGIN_PREFIX . 'amount_paid'      => $amount_paid,
				OBIE_EVENTS_PLUGIN_PREFIX . 'payment_date'     => $payment_date,
                OBIE_EVENTS_PLUGIN_PREFIX . 'status'           => 'reserved'
            );
        } else {
            $reservation_data = array(
                OBIE_EVENTS_PLUGIN_PREFIX . 'event_id' => $event_id,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name' => $name,
                OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email' => $email,
				OBIE_EVENTS_PLUGIN_PREFIX . 'tickets' => $tickets,
                OBIE_EVENTS_PLUGIN_PREFIX . 'status' => 'reserved',
            );
        }

        // Save reservation data
        $reservation_id = wp_insert_post(array(
            'post_type' => Obie_Events_Reservations_CPT::$slug,
            'post_title' =>  'Reservation for Event #' . $event_id,
            'post_status' => 'publish',
			'meta_input' => $reservation_data
        ));

        if (!empty($coupon)) {
            Obie_Events_Coupons::apply_coupon(
                $coupon['id'],
                $reservation_id,
                $amount_saved,
                $name
            );
        }

        if ($reservation_id) {
            wp_send_json_success('Reservation successful');
        } else {
            wp_send_json_error('Failed to save reservation');
        }
	}
}
