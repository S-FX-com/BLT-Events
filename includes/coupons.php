<?php

class Obie_Events_Coupons
{
    public static $cookie_name = OBIE_EVENTS_PLUGIN_PREFIX . "coupon";
    public static $nonce = OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_nonce';

    public static function init()
    {
        add_action('wp_ajax_obie_validate_coupon', array(__CLASS__, 'validate_coupon_ajax'));
        add_action('wp_ajax_nopriv_obie_validate_coupon', array(__CLASS__, 'validate_coupon_ajax'));
    }

    /**
     * Validate coupon code and return result
     * 
     * @param string $coupon_code The coupon code to validate
     * @return array An array with validation status and data
     *               ['success'] => 'true' or 'false'
     *               ['message'] => Error or success message
     *               ['coupon'] => Coupon data on success
     */
    public static function validate_coupon($coupon_code, $event_id) {
        $coupon_code = sanitize_text_field($coupon_code);
        $event_id = sanitize_text_field($event_id);
        
        $args = array(
            'post_type' => Obie_Events_Coupons_CPT::$slug, 
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_code',
                    'value' => $coupon_code,
                    'compare' => '='
                )
            )
        );

        $coupons = get_posts($args);
        if (empty($coupons)) {
            return array(
                'success' => false,
                'message' => __('Coupon not found.', OBIE_EVENTS_PLUGIN_PATH)
            );
        }

        $coupon = $coupons[0];
        $coupon_name = $coupon->post_title;
        $coupon_id = $coupon->ID;
        $expiration_date = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'expiration_date', true);
        $usage_limit = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'usage_limit', true);
        $total_uses = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'total_uses', true);

        // Check if coupon is expired
        if (!empty($expiration_date) && strtotime($expiration_date) < current_time('timestamp')) {
            return array(
                'success' => false,
                'message' => __('Coupon has expired.', OBIE_EVENTS_PLUGIN_PATH)
            );
        }
        
        // Verify if the coupon has reached its limit of use
        if ($total_uses && $usage_limit && $total_uses >= $usage_limit) {
            return array(
                'success' => false,
                'message' => __('Coupon usage limit reached.', OBIE_EVENTS_PLUGIN_PATH)
            );
        }

        // Verify if the coupon is applicable to the current event
        $applicable_events = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'applicable_events', true);
        if (is_array($applicable_events) && !empty($applicable_events) && !in_array('all', $applicable_events) && !in_array($event_id, $applicable_events)) {
            return array(
                'success' => false,
                'message' => __('Coupon is not applicable to current event.', OBIE_EVENTS_PLUGIN_PATH)
            );
        }

        // Check if the coupon is active
        $coupon_status = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'status', true);
        if ($coupon_status !== 'active') {
            return array(
                'success' => false,
                'message' => __('Coupon is not active.', OBIE_EVENTS_PLUGIN_PATH)
            );
        }

        // Get coupon details
        $discount_type = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'discount_type', true);
        $amount = (float) get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'amount', true);

        $coupon_data = array(
            "id" => $coupon_id,
            "discount_type" => $discount_type,
            "amount" => $amount,
            "coupon_code" => $coupon_code,
            "coupon_name" => $coupon_name
        );

        return array(
            'success' => true,
            'message' => __('Coupon applied', OBIE_EVENTS_PLUGIN_PATH),
            'coupon' => $coupon_data
        );
    }

    /**
     * AJAX handler for coupon validation
     */
    public static function validate_coupon_ajax() {
        check_ajax_referer(self::$nonce, self::$nonce);

        $coupon_code = isset($_POST['coupon_code']) ? $_POST['coupon_code'] : '';
        $event_id = intval($_POST['event_id']);

        $result = self::validate_coupon($coupon_code, $event_id);
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'coupon' => $result['coupon']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }

    public static function apply_coupon($coupon_id, $reservation_id, $amount_saved)
    {
        // Update coupon usage stats
        $total_uses = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'total_uses', true) ?: 0;
        $total_savings = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'total_savings', true) ?: 0;
        
        $total_uses++;
        $total_savings += $amount_saved;
        $last_used = time();
        
        update_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'total_uses', $total_uses);
        update_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'total_savings', $total_savings);
        update_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'last_used', $last_used);
        
        // Add to usage history
        $usage_history = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'usage_history', true) ?: array();
        $usage_history[] = array(
            'date' => $last_used,
            'reservation_id' => $reservation_id,
            'amount_saved' => $amount_saved
        );
        update_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'usage_history', $usage_history);
        
        // Store coupon info with reservation

        $discount_type = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'discount_type', true);
        $amount = (float) get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'amount', true);
        $coupon_name = get_the_title($coupon_id);
        $coupon_code = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_code', true);

        $coupon_data = array(
            "id" => $coupon_id,
            "discount_type" => $discount_type,
            "amount" => $amount,
            "coupon_name" => $coupon_name,
            "coupon_code" => $coupon_code
        );
        update_post_meta($reservation_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_applied', $coupon_data);
        
        return true;
    }
}