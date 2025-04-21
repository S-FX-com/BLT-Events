<?php

class Obie_Events_Coupons
{
    public static $cookie_name = OBIE_EVENTS_PLUGIN_PREFIX . "coupon";
    public static $nonce = OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_nonce';

    public static function init()
    {
        add_action('wp_ajax_obie_validate_coupon', array(__CLASS__, 'validate_coupon'));
        add_action('wp_ajax_nopriv_obie_validate_coupon', array(__CLASS__, 'validate_coupon'));

        add_action('wp_ajax_obie_remove_coupon', array(__CLASS__, 'remove_coupon'));
        add_action('wp_ajax_nopriv_obie_remove_coupon', array(__CLASS__, 'remove_coupon'));
    }

    public static function validate_coupon() {
        check_ajax_referer(self::$nonce, self::$nonce);

        $coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field($_POST['coupon_code']) : '';

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
            wp_send_json_error(array('message' => __('Coupon not found.', OBIE_EVENTS_PLUGIN_PATH)));
        }

        $coupon = $coupons[0];
        $coupon_name = $coupon->post_title;
        $coupon_id = $coupon->ID;
        $expiration_date = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_expiration_date', true);
        $usage_limit = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_usage_limit', true);
        $total_uses = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_total_uses', true);

        // Check if coupon is expired
        if (!empty($expiration_date) && strtotime($expiration_date) < current_time('timestamp')) {
            wp_send_json_error(array('message' => __('Coupon has expired.', OBIE_EVENTS_PLUGIN_PATH)));
        }
        
        // Verify if the coupon has reached its limit of use
        if ($total_uses && $usage_limit && $total_uses >= $usage_limit) {
            wp_send_json_error(array('message' => __('Coupon usage limit reached.', OBIE_EVENTS_PLUGIN_PATH)));
        }

        // Get coupon details
        $discount_type = get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'discount_type', true);
        $amount = (float) get_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'amount', true);

        $message = sprintf(
            __('Coupon applied: %s discount', OBIE_EVENTS_PLUGIN_PATH),
            $discount_type === 'percentage' ? $amount . '%' : '$' . $amount
        );

        $coupon = array(
            "discount_type" => $discount_type,
            "amount" => $amount,
            "coupon_code" => $coupon_code,
            "coupon_name" => $coupon_name
        );

        wp_send_json_success(array('message' => $message, 'coupon' => $coupon));
    }

    public static function apply_coupon($coupon_id, $reservation_id, $amount_saved, $customer_name)
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
            'customer_name' => $customer_name,
            'amount_saved' => $amount_saved
        );
        update_post_meta($coupon_id, OBIE_EVENTS_PLUGIN_PREFIX . 'usage_history', $usage_history);
        
        // Store coupon info with reservation
        update_post_meta($reservation_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_id', $coupon_id);
        update_post_meta($reservation_id, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_amount_saved', $amount_saved);
        
        return true;
    }
}
