<?php
class Obie_Events_Registrations
{
    public static $nonce = OBIE_EVENTS_PLUGIN_PREFIX . 'registration_nonce';

    public static function init()
    {
        add_action('wp_ajax_obie_event_registration', array(__CLASS__, 'event_registration'));
        add_action('wp_ajax_nopriv_obie_event_registration', array(__CLASS__, 'event_registration'));
        add_action('obie_events_send_reminder_24h', array(__CLASS__, 'send_reminder_24h'));
        add_action('obie_events_send_reminder_1h', array(__CLASS__, 'send_reminder_1h'));
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
            // Enviar email de confirmación
            $event = get_post($event_id);
            $event_name = $event ? $event->post_title : '';
            $event_date = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
            $event_time = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', true);
            $event_url = get_permalink($event_id);

            $variables = array(
                '{customer_name}' => $name,
                '{event_name}'   => $event_name,
                '{event_date}'   => $event_date,
                '{event_time}'   => $event_time,
                '{event_url}'    => $event_url,
            );

            $subject = get_option('obie_events_email_subject_registration', 'Confirmación de registro para {event_name}');
            $body = get_option('obie_events_email_template_registration', 'Hola {customer_name}, tu registro para el evento {event_name} el {event_date} a las {event_time} ha sido recibido.');

            $subject = strtr($subject, $variables);
            $body = strtr($body, $variables);

            wp_mail($email, $subject, $body);

            // Programar recordatorio 24h antes
            if ($event_date && $event_time) {
                $event_datetime = strtotime($event_date . ' ' . $event_time);
                $reminder_24h = $event_datetime - 24 * 3600;
                $reminder_1h = $event_datetime - 3600;
                if ($reminder_24h > time()) {
                    wp_schedule_single_event($reminder_24h, 'obie_events_send_reminder_24h', array($registration_id));
                }
                if ($reminder_1h > time()) {
                    wp_schedule_single_event($reminder_1h, 'obie_events_send_reminder_1h', array($registration_id));
                }
            }

            wp_send_json_success('Registration successful');
        } else {
            wp_send_json_error('Failed to save registration');
        }
    }

    // Función para enviar recordatorio 24h antes
    public static function send_reminder_24h($registration_id)
    {
        self::send_reminder($registration_id, '24h');
    }

    // Función para enviar recordatorio 1h antes
    public static function send_reminder_1h($registration_id)
    {
        self::send_reminder($registration_id, '1h');
    }

    // Lógica común para enviar recordatorio
    private static function send_reminder($registration_id, $type)
    {
        $event_id = get_post_meta($registration_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_id', true);
        $name = get_post_meta($registration_id, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name', true);
        $email = get_post_meta($registration_id, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email', true);
        $event = get_post($event_id);
        $event_name = $event ? $event->post_title : '';
        $event_date = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
        $event_time = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', true);
        $event_url = get_permalink($event_id);
        $variables = array(
            '{customer_name}' => $name,
            '{event_name}'   => $event_name,
            '{event_date}'   => $event_date,
            '{event_time}'   => $event_time,
            '{event_url}'    => $event_url,
        );
        if ($type === '24h') {
            $subject = get_option('obie_events_email_subject_reminder_24h', 'Recordatorio: tu evento {event_name} es mañana');
            $body = get_option('obie_events_email_template_reminder_24h', 'Hola {customer_name}, te recordamos que el evento {event_name} es mañana ({event_date}) a las {event_time}.');
        } else {
            $subject = get_option('obie_events_email_subject_reminder_1h', 'Recordatorio: tu evento {event_name} comienza en 1 hora');
            $body = get_option('obie_events_email_template_reminder_1h', 'Hola {customer_name}, tu evento {event_name} comienza en 1 hora, a las {event_time}.');
        }
        $subject = strtr($subject, $variables);
        $body = strtr($body, $variables);
        wp_mail($email, $subject, $body);
    }
}
