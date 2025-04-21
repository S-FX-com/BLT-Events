<?php
class Obie_Events_Stripe
{
    private static $api_base = 'https://api.stripe.com/v1/';

    public static function init()
    {
        add_action('wp_ajax_obie_create_payment_intent', array(__CLASS__, 'create_payment_intent'));
        add_action('wp_ajax_nopriv_obie_create_payment_intent', array(__CLASS__, 'create_payment_intent'));
        add_action('rest_api_init', array(__CLASS__, 'register_webhook_endpoint'));
    }

    private static function make_request($endpoint, $method = 'POST', $body = array())
    {
        $secret_key = get_option('obie_events_stripe_secret_key');

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
        );

        $response = wp_remote_request(self::$api_base . $endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public static function create_payment_intent()
    {
		check_ajax_referer(Obie_Events_Reservations::$nonce, Obie_Events_Reservations::$nonce);
		
        try {
			$event_id = intval($_POST['event_id']);
			$name = sanitize_text_field($_POST['customer_name']);
        	$email = sanitize_email($_POST['customer_email']);
			$tickets = !empty($_POST['tickets']) ? json_decode(stripslashes($_POST['tickets']), true) : [];
			
            if (empty($event_id) || empty($name) || empty($email) || empty($tickets)) {
                throw new Exception('Missing required data');
            }

            // Calcular el monto total
            $ticket_types = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);
			$selected_tickets = [];
			$total_amount = 0;
            foreach ($tickets as $ticket) {
                $ticket_type = $ticket_types[$ticket['index']];
                $total_amount += $ticket_type['price'] * $ticket['quantity'] * 100;
				$selected_tickets[] = [
					"name" => $ticket_type['name'],
					"quantity" => $ticket['quantity'],
					"price" => $ticket_type['price'],
				];
            }

            // Crear el Payment Intent
            $body = array(
                'amount'   => $total_amount,
                'currency' => get_option('obie_events_currency'),
                'metadata' => array(
                    'event_id' => $event_id,
					'customer_name' => $name,
					'customer_email' => $email,
                    'tickets'  => json_encode($selected_tickets)
                )
            );

            $payment_intent = self::make_request('payment_intents', 'POST', $body);

            wp_send_json_success([
                'clientSecret' => $payment_intent['client_secret']
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['error' => $e->getMessage()]);
        }
    }

    public static function register_webhook_endpoint()
    {
        register_rest_route('obie-events/v1', '/stripe-webhook', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    public static function handle_webhook($request)
    {
        try {
            $webhook_secret = get_option('obie_events_stripe_webhook_secret');
            $payload = $request->get_body();
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

            $event = self::construct_event($payload, $sig_header, $webhook_secret);

            switch ($event['type']) {
                case 'payment_intent.succeeded':
                    self::handle_successful_payment($event['data']['object']);
                    break;
            }

            return new WP_REST_Response(['status' => 'success'], 200);
        } catch (Exception $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 400);
        }
    }

    private static function construct_event($payload, $sig_header, $secret)
    {
        $timestamp = 0;
        $signatures = [];

        // Extraer el timestamp y las firmas del Stripe-Signature header
        foreach (explode(',', $sig_header) as $part) {
            list($key, $value) = explode('=', $part, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (!$timestamp || empty($signatures)) {
            throw new Exception('Invalid Stripe-Signature header format.');
        }

        // Construir el string que Stripe firma: "timestamp.payload"
        $signed_payload = $timestamp . '.' . $payload;

        // Generar la firma esperada
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);

        // Verificar si alguna de las firmas recibidas coincide con la esperada
        $valid = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected_signature, $sig)) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            throw new Exception('Invalid signature.');
        }

        return json_decode($payload, true);
    }

    private static function handle_successful_payment($payment_intent)
    {
        $event_id = $payment_intent['metadata']['event_id'];
		$name = $payment_intent['metadata']['customer_name'];
		$email = $payment_intent['metadata']['customer_email'];
		$tickets = json_decode($payment_intent['metadata']['tickets'], true);

        Obie_Events_Reservations::process_reservation($event_id, $name, $email, $tickets, $payment_intent);
    }
}
