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
		
		$ticket_types = get_post_meta($event_id, '_event_ticket_types', true);
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
	
	public static function process_reservation($event_id, $name, $email, $tickets, $payment_intent = null)
	{
        if ($payment_intent && !empty($payment_intent)) {
			$payment_intent_id = $payment_intent['id'];
			$amount_paid = $payment_intent['amount'] / 100;
			$payment_date = $payment_intent['created'];

            $reservation_data = array(
                '_event_id' => $event_id,
                '_customer_name' => $name,
                '_customer_email' => $email,
                '_tickets' => $tickets,
				'_payment_intent_id' => $payment_intent_id,
                '_amount_paid'      => $amount_paid,
				'_payment_date'     => $payment_date,
                '_status'           => 'reserved'
            );
        } else {
            $reservation_data = array(
                '_event_id' => $event_id,
                '_customer_name' => $name,
                '_customer_email' => $email,
				'_tickets' => $tickets,
                '_status' => 'reserved',
            );
        }

        // Save reservation data
        $reservation_id = wp_insert_post(array(
            'post_type' => Obie_Events_Reservations_CPT::$slug,
            'post_title' =>  'Reservation for Event #' . $event_id,
            'post_status' => 'publish',
			'meta_input' => $reservation_data
        ));

        if ($reservation_id) {
            wp_send_json_success('Reservation successful');
        } else {
            wp_send_json_error('Failed to save reservation');
        }
	}
}
