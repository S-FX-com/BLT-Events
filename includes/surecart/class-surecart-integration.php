<?php
/**
 * SureCart Integration for Obie Events
 *
 * Syncs event tickets as SureCart products/prices and handles
 * purchase confirmation to create event registrations automatically.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Obie_Events_SureCart
{
    private static $api_base = 'https://api.surecart.com/v1/';

    public static function init()
    {
        if (get_option('obie_events_payment_provider') !== 'surecart') {
            return;
        }

        // Sync products when events are saved (priority 20 = after event meta is saved)
        add_action('save_post_event', array(__CLASS__, 'sync_event_products'), 20, 1);

        // Handle SureCart checkout confirmation
        add_action('surecart/checkout_confirmed', array(__CLASS__, 'handle_checkout_confirmed'), 10, 2);

        // Handle purchase lifecycle events
        add_action('surecart/purchase_revoked', array(__CLASS__, 'handle_purchase_revoked'), 10, 1);

        // Admin notice if SureCart plugin is not active
        add_action('admin_notices', array(__CLASS__, 'check_surecart_active'));
    }

    /**
     * Check if SureCart plugin is active and show admin notice if not.
     */
    public static function check_surecart_active()
    {
        if (!self::is_surecart_plugin_active()) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Obie Events:</strong> SureCart is selected as the payment provider but the SureCart plugin is not active. ';
            echo 'Please install and activate SureCart, or switch to Stripe in <a href="' . esc_url(admin_url('edit.php?post_type=event&page=obie-events-settings')) . '">Event Settings</a>.';
            echo '</p></div>';
        }
    }

    /**
     * Check if the SureCart plugin is installed and active.
     */
    public static function is_surecart_plugin_active()
    {
        return class_exists('SureCart') || defined('SURECART_PLUGIN_FILE') || is_plugin_active('surecart/surecart.php');
    }

    /**
     * Get the SureCart API token from settings.
     */
    private static function get_api_token()
    {
        $token = get_option('obie_events_surecart_api_token');
        if (!empty($token)) {
            return $token;
        }

        // Try SureCart's own stored token
        $sc_token = get_option('surecart_api_token');
        if (!empty($sc_token)) {
            return $sc_token;
        }

        return false;
    }

    /**
     * Make an authenticated request to the SureCart REST API.
     */
    private static function api_request($endpoint, $method = 'GET', $data = array())
    {
        $token = self::get_api_token();
        if (!$token) {
            return new WP_Error('no_api_token', 'SureCart API token is not configured.');
        }

        $url = self::$api_base . ltrim($endpoint, '/');

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        );

        if (!empty($data) && in_array($method, array('POST', 'PATCH', 'PUT'), true)) {
            $args['body'] = wp_json_encode($data);
        }

        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('Obie Events SureCart API Error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $message = isset($body['message']) ? $body['message'] : 'SureCart API request failed (HTTP ' . $code . ')';
            error_log('Obie Events SureCart API Error [' . $code . ']: ' . $message);
            return new WP_Error('surecart_api_error', $message, array('status' => $code, 'body' => $body));
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    // Product & Price Synchronization
    // -------------------------------------------------------------------------

    /**
     * Sync event ticket types to SureCart products and prices.
     * Called on save_post_event after meta has been saved.
     */
    public static function sync_event_products($post_id)
    {
        if (get_post_type($post_id) !== 'event') {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!self::get_api_token()) {
            return;
        }

        $by_tickets = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_by_tickets', true);

        // Get existing SureCart IDs
        $sc_product_ids = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_product_ids', true) ?: array();
        $sc_price_ids   = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_price_ids', true) ?: array();

        // If tickets are disabled, archive all synced products
        if (!$by_tickets) {
            self::archive_products($sc_product_ids);
            delete_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_product_ids');
            delete_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_price_ids');
            return;
        }

        $ticket_types = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);
        if (empty($ticket_types)) {
            return;
        }

        $event_title   = get_the_title($post_id);
        $event_date    = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
        $currency      = strtolower(get_option('obie_events_currency', 'usd'));

        $new_product_ids = array();
        $new_price_ids   = array();

        foreach ($ticket_types as $index => $ticket) {
            $product_name = $event_title . ' — ' . $ticket['name'];
            $price_amount = intval(round(floatval($ticket['price']) * 100));

            $product_metadata = array(
                'obie_event_id'     => (string) $post_id,
                'obie_ticket_index' => (string) $index,
                'obie_ticket_name'  => $ticket['name'],
            );
            if ($event_date) {
                $product_metadata['obie_event_date'] = $event_date;
            }

            $existing_product_id = isset($sc_product_ids[$index]) ? $sc_product_ids[$index] : null;
            $existing_price_id   = isset($sc_price_ids[$index]) ? $sc_price_ids[$index] : null;

            // Create or update the product
            $product_id = self::sync_single_product(
                $existing_product_id,
                $product_name,
                $product_metadata
            );

            if (!$product_id) {
                continue;
            }

            $new_product_ids[$index] = $product_id;

            // Create or update the price
            $price_id = self::sync_single_price(
                $product_id,
                $existing_price_id,
                $price_amount,
                $currency,
                $ticket['name']
            );

            if ($price_id) {
                $new_price_ids[$index] = $price_id;
            }
        }

        // Archive products that were removed (ticket types deleted)
        foreach ($sc_product_ids as $index => $old_product_id) {
            if (!isset($new_product_ids[$index])) {
                self::api_request('products/' . $old_product_id, 'PATCH', array(
                    'archived' => true,
                ));
            }
        }

        update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_product_ids', $new_product_ids);
        update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_price_ids', $new_price_ids);
    }

    /**
     * Create or update a single SureCart product.
     */
    private static function sync_single_product($existing_id, $name, $metadata)
    {
        if ($existing_id) {
            $result = self::api_request('products/' . $existing_id, 'PATCH', array(
                'name'     => $name,
                'metadata' => $metadata,
            ));

            if (!is_wp_error($result) && isset($result['id'])) {
                return $result['id'];
            }

            // If update failed (product deleted in SureCart), create new
            error_log('Obie Events: Failed to update SureCart product ' . $existing_id . ', creating new.');
        }

        $result = self::api_request('products', 'POST', array(
            'name'     => $name,
            'metadata' => $metadata,
        ));

        if (is_wp_error($result)) {
            error_log('Obie Events: Failed to create SureCart product: ' . $result->get_error_message());
            return null;
        }

        return isset($result['id']) ? $result['id'] : null;
    }

    /**
     * Create or update a single SureCart price.
     * SureCart prices are immutable in amount, so if the price changed
     * we archive the old one and create a new one.
     */
    private static function sync_single_price($product_id, $existing_price_id, $amount, $currency, $name = '')
    {
        if ($existing_price_id) {
            // Check if the amount has changed
            $old_price = self::api_request('prices/' . $existing_price_id);

            if (!is_wp_error($old_price) && isset($old_price['amount'])) {
                if ((int) $old_price['amount'] === $amount) {
                    return $existing_price_id; // No change needed
                }

                // Amount changed — archive old price
                self::api_request('prices/' . $existing_price_id, 'PATCH', array(
                    'archived' => true,
                ));
            }
        }

        // Create new price
        $price_data = array(
            'product'  => $product_id,
            'amount'   => $amount,
            'currency' => $currency,
        );

        if ($name) {
            $price_data['name'] = $name;
        }

        $result = self::api_request('prices', 'POST', $price_data);

        if (is_wp_error($result)) {
            error_log('Obie Events: Failed to create SureCart price: ' . $result->get_error_message());
            return null;
        }

        return isset($result['id']) ? $result['id'] : null;
    }

    /**
     * Archive a set of SureCart products.
     */
    private static function archive_products($product_ids)
    {
        if (empty($product_ids) || !is_array($product_ids)) {
            return;
        }

        foreach ($product_ids as $product_id) {
            self::api_request('products/' . $product_id, 'PATCH', array(
                'archived' => true,
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Checkout URL Builder
    // -------------------------------------------------------------------------

    /**
     * Get the SureCart checkout page URL.
     */
    public static function get_checkout_url()
    {
        // Check user-configured URL first
        $url = get_option('obie_events_surecart_checkout_url', '');
        if (!empty($url)) {
            return $url;
        }

        // Try SureCart's PHP helper
        if (class_exists('SureCart')) {
            try {
                $sc_url = \SureCart::pages()->url('checkout');
                if ($sc_url) {
                    return $sc_url;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }

        return home_url('/checkout');
    }

    /**
     * Build a SureCart checkout URL with line items.
     *
     * @param array $line_items Array of ['price_id' => string, 'quantity' => int]
     * @return string The checkout URL
     */
    public static function build_checkout_url($line_items)
    {
        $base_url = self::get_checkout_url();

        $params = array();
        foreach ($line_items as $i => $item) {
            $params['line_items[' . $i . '][price_id]'] = $item['price_id'];
            $params['line_items[' . $i . '][quantity]'] = $item['quantity'];
        }

        return add_query_arg($params, $base_url);
    }

    // -------------------------------------------------------------------------
    // Purchase Confirmation Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle SureCart checkout confirmation.
     * Creates event registrations for any purchased event ticket products.
     */
    public static function handle_checkout_confirmed($checkout, $request = null)
    {
        // Extract customer info from checkout
        $customer_email = '';
        $customer_name  = '';

        if (isset($checkout->email)) {
            $customer_email = $checkout->email;
        } elseif (isset($checkout->customer->email)) {
            $customer_email = $checkout->customer->email;
        }

        if (isset($checkout->name)) {
            $customer_name = $checkout->name;
        } elseif (isset($checkout->customer->name)) {
            $customer_name = $checkout->customer->name;
        } elseif (isset($checkout->first_name)) {
            $name_parts = array_filter(array(
                isset($checkout->first_name) ? $checkout->first_name : '',
                isset($checkout->last_name) ? $checkout->last_name : '',
            ));
            $customer_name = implode(' ', $name_parts);
        }

        // Handle both object and array access patterns
        $checkout_data = is_object($checkout) ? (array) $checkout : $checkout;
        $checkout_id   = isset($checkout_data['id']) ? $checkout_data['id'] : '';
        $total_amount  = isset($checkout_data['total_amount']) ? $checkout_data['total_amount'] : 0;

        if (empty($customer_email)) {
            $customer_email = isset($checkout_data['email']) ? $checkout_data['email'] : '';
        }
        if (empty($customer_name)) {
            $customer_name = isset($checkout_data['name']) ? $checkout_data['name'] : '';
        }

        // Get line items — try expanded data first, then API call
        $line_items = self::get_checkout_line_items($checkout_data);
        if (empty($line_items)) {
            return;
        }

        // Group purchased items by event ID
        $events_tickets = array();

        foreach ($line_items as $line_item) {
            $product_data = self::resolve_product_from_line_item($line_item);
            if (!$product_data) {
                continue;
            }

            $metadata = isset($product_data['metadata']) ? $product_data['metadata'] : array();
            if (empty($metadata['obie_event_id'])) {
                continue; // Not an event ticket product
            }

            $event_id     = intval($metadata['obie_event_id']);
            $ticket_index = isset($metadata['obie_ticket_index']) ? intval($metadata['obie_ticket_index']) : 0;
            $ticket_name  = isset($metadata['obie_ticket_name']) ? $metadata['obie_ticket_name'] : '';

            $quantity = isset($line_item['quantity']) ? intval($line_item['quantity']) : 1;

            // Resolve price
            $unit_price = 0;
            if (isset($line_item['price']['amount'])) {
                $unit_price = $line_item['price']['amount'] / 100;
            } elseif (isset($line_item['amount'])) {
                $unit_price = $line_item['amount'] / 100;
            }

            if (!isset($events_tickets[$event_id])) {
                $events_tickets[$event_id] = array();
            }

            $events_tickets[$event_id][] = array(
                'name'     => $ticket_name,
                'quantity' => $quantity,
                'price'    => $unit_price,
            );
        }

        // Create registrations for each event
        foreach ($events_tickets as $event_id => $tickets) {
            $amount_paid = 0;
            foreach ($tickets as $ticket) {
                $amount_paid += $ticket['price'] * $ticket['quantity'];
            }

            $payment_data = array(
                'id'      => $checkout_id,
                'amount'  => intval($amount_paid * 100),
                'created' => time(),
            );

            if (empty($customer_name)) {
                $customer_name = $customer_email;
            }

            Obie_Events_Registrations::process_registration(
                $event_id,
                $customer_name,
                $customer_email,
                $tickets,
                null,   // coupon (handled by SureCart)
                0,      // amount_saved
                $payment_data
            );
        }
    }

    /**
     * Handle SureCart purchase revocation (cancellation/refund).
     * Updates the registration status to cancelled.
     */
    public static function handle_purchase_revoked($purchase)
    {
        $purchase_data = is_object($purchase) ? (array) $purchase : $purchase;

        // Try to get product metadata to find the event
        $product = isset($purchase_data['product']) ? $purchase_data['product'] : null;
        if (!$product) {
            return;
        }

        $product_data = is_object($product) ? (array) $product : $product;
        $metadata     = isset($product_data['metadata']) ? $product_data['metadata'] : array();

        if (empty($metadata['obie_event_id'])) {
            return;
        }

        $event_id = intval($metadata['obie_event_id']);
        $checkout_id = isset($purchase_data['checkout']) ? $purchase_data['checkout'] : '';

        if (is_array($checkout_id) || is_object($checkout_id)) {
            $checkout_id = isset($checkout_id['id']) ? $checkout_id['id'] : '';
        }

        if (empty($checkout_id)) {
            return;
        }

        // Find the registration by payment intent ID (checkout ID)
        $registrations = get_posts(array(
            'post_type'  => 'event_registration',
            'meta_query' => array(
                array(
                    'key'   => OBIE_EVENTS_PLUGIN_PREFIX . 'payment_intent_id',
                    'value' => $checkout_id,
                ),
                array(
                    'key'   => OBIE_EVENTS_PLUGIN_PREFIX . 'event_id',
                    'value' => $event_id,
                ),
            ),
            'posts_per_page' => 1,
        ));

        if (!empty($registrations)) {
            update_post_meta(
                $registrations[0]->ID,
                OBIE_EVENTS_PLUGIN_PREFIX . 'status',
                'cancelled'
            );
        }
    }

    /**
     * Extract line items from checkout data.
     */
    private static function get_checkout_line_items($checkout_data)
    {
        // Try expanded line_items first
        if (!empty($checkout_data['line_items'])) {
            $items = $checkout_data['line_items'];
            if (isset($items['data'])) {
                return $items['data'];
            }
            return $items;
        }

        // Try to fetch line items via API using checkout ID
        if (!empty($checkout_data['id'])) {
            $result = self::api_request(
                'checkouts/' . $checkout_data['id'],
                'GET',
                array('expand[]' => 'line_items,line_item.price,price.product')
            );

            if (!is_wp_error($result) && !empty($result['line_items'])) {
                $items = $result['line_items'];
                return isset($items['data']) ? $items['data'] : $items;
            }
        }

        return array();
    }

    /**
     * Resolve the product data from a line item.
     */
    private static function resolve_product_from_line_item($line_item)
    {
        // Check for expanded product data in line_item → price → product
        if (isset($line_item['price']['product']) && is_array($line_item['price']['product'])) {
            return $line_item['price']['product'];
        }

        // Check for product directly on line_item
        if (isset($line_item['product']) && is_array($line_item['product'])) {
            return $line_item['product'];
        }

        // Resolve product ID and fetch via API
        $product_id = null;

        if (isset($line_item['price']['product']) && is_string($line_item['price']['product'])) {
            $product_id = $line_item['price']['product'];
        } elseif (isset($line_item['product']) && is_string($line_item['product'])) {
            $product_id = $line_item['product'];
        } elseif (isset($line_item['price_id']) || isset($line_item['price'])) {
            // Fetch price to get product
            $price_id = isset($line_item['price_id']) ? $line_item['price_id'] : null;
            if (!$price_id && isset($line_item['price']) && is_string($line_item['price'])) {
                $price_id = $line_item['price'];
            }
            if ($price_id) {
                $price = self::api_request('prices/' . $price_id, 'GET', array('expand[]' => 'product'));
                if (!is_wp_error($price) && isset($price['product'])) {
                    if (is_array($price['product'])) {
                        return $price['product'];
                    }
                    $product_id = $price['product'];
                }
            }
        }

        if ($product_id) {
            $product = self::api_request('products/' . $product_id);
            if (!is_wp_error($product)) {
                return $product;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Utility Methods
    // -------------------------------------------------------------------------

    /**
     * Check if SureCart is properly configured and ready to use.
     */
    public static function is_configured()
    {
        return (
            get_option('obie_events_payment_provider') === 'surecart'
            && self::get_api_token()
        );
    }

    /**
     * Get the SureCart price IDs for an event's tickets.
     */
    public static function get_event_price_ids($event_id)
    {
        return get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_price_ids', true) ?: array();
    }

    /**
     * Get the SureCart product IDs for an event's tickets.
     */
    public static function get_event_product_ids($event_id)
    {
        return get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_product_ids', true) ?: array();
    }

    /**
     * Manually trigger a product sync for an event.
     * Useful for re-syncing after configuration changes.
     */
    public static function resync_event($event_id)
    {
        self::sync_event_products($event_id);
    }
}
