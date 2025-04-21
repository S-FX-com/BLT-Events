<?php
class Obie_Events_Registrations_CPT
{
    public static $slug = 'event_registration';

    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post_' . self::$slug, array(__CLASS__, 'save_post'));
        add_action('manage_' . self::$slug . '_posts_columns', array(__CLASS__, 'set_custom_columns'));
        add_action('manage_' . self::$slug . '_posts_custom_column', array(__CLASS__, 'custom_column'), 10, 2);
        add_filter('manage_edit-' . self::$slug . '_sortable_columns', array(__CLASS__, 'sortable_columns'));
    }

    public static function register_post_type()
    {
        $labels = array(
            'name'               => 'Registrations',
            'singular_name'      => 'Registration',
            'menu_name'          => 'Registrations',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Registration',
            'edit_item'          => 'Edit Registration',
            'new_item'           => 'New Registration',
            'view_item'          => 'View Registration',
            'search_items'       => 'Search Registrations',
            'not_found'          => 'No registrations found',
            'not_found_in_trash' => 'No registrations found in Trash',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=' . Obie_Events_CPT::$slug,
            'show_in_admin_bar'  => true,
            'menu_position'      => null,
            'capability_type'    => 'post',
            'hierarchical'       => false,
            'supports'           => array('title'),
            'has_archive'        => false,
            'rewrite'           => false,
            'query_var'         => false,
        );

        register_post_type(self::$slug, $args);

        flush_rewrite_rules();
    }

    public static function add_meta_boxes()
    {
        add_meta_box(
            'registration_details',
            'Registration Details',
            array(__CLASS__, 'render_details_meta_box'),
            self::$slug,
            'normal',
            'high'
        );

        add_meta_box(
            'payment_details',
            'Payment Details',
            array(__CLASS__, 'render_payment_meta_box'),
            self::$slug,
            'normal',
            'high'
        );
    }

    public static function render_details_meta_box($post)
    {
        wp_nonce_field('registration_details', 'registration_details_nonce');

        $event_id = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_id', true);
        $customer_name = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name', true);
        $customer_email = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email', true);
        $tickets = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'tickets', true);
        $status = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'status', true);
        $coupon_applied = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'coupon_applied', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="event_id">Event</label></th>
                <td>
                    <?php
                    if ($event_id) {
                        echo '<a href="' . get_edit_post_link($event_id) . '">' . get_the_title($event_id) . '</a>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="customer_name">Customer Name</label></th>
                <td>
                    <input type="text" id="customer_name" name="customer_name"
                        value="<?php echo esc_attr($customer_name); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="customer_email">Customer Email</label></th>
                <td>
                    <input type="email" id="customer_email" name="customer_email"
                        value="<?php echo esc_attr($customer_email); ?>" class="regular-text" />
                </td>
            </tr>
            <?php if (!empty($tickets)) { ?>
                <tr>
                    <th><label>Tickets</label></th>
                    <td>
                        <?php
                        echo '<table class="widefat fixed" style="width: auto;">';
                        echo '<thead><tr><th>Ticket Type</th><th>Quantity</th><th>Price</th><th>Total</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($tickets as $ticket) {
                            echo '<tr>';
                            echo '<td>' . esc_html($ticket['name']) . '</td>';
                            echo '<td>' . intval($ticket['quantity']) . '</td>';
                            echo '<td>$' . number_format($ticket['price'], 2) . '</td>';
                            echo '<td>$' . number_format($ticket['price'] * intval($ticket['quantity']), 2) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        ?>
                    </td>
                </tr>
            <?php } ?>
            <?php if (!empty($coupon_applied)) { ?>
                <tr>
                    <th><label>Coupon Applied</label></th>
                    <td>
                        <?php
                        echo '<table class="widefat fixed" style="width: auto;">';
                        echo '<tr><th>Coupon Name</th><td>' . esc_html($coupon_applied['coupon_name']) . '</td></tr>';
                        echo '<tr><th>Coupon Code</th><td>' . esc_html($coupon_applied['coupon_code']) . '</td></tr>';
                        echo '<tr><th>Discount Type</th><td>' . (($coupon_applied['discount_type'] == 'percentage') ? 'Percentage' : 'Fixed Amount') . '</td></tr>';
                        echo '<tr><th>Amount</th><td>' . ($coupon_applied['discount_type'] == 'percentage' ? esc_html($coupon_applied['amount']) . '%' : '$' . number_format($coupon_applied['amount'], 2)) . '</td></tr>';
                        if (isset($coupon_applied['id'])) {
                            echo '<tr><th>Coupon ID</th><td><a href="' . get_edit_post_link($coupon_applied['id']) . '">' . esc_html($coupon_applied['id']) . '</a></td></tr>';
                        }
                        echo '</table>';
                        ?>
                    </td>
                </tr>
            <?php } ?>
            <tr>
                <th><label for="status">Status</label></th>
                <td>
                    <select id="status" name="status">
                        <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                        <option value="registered" <?php selected($status, 'registered'); ?>>Registered</option>
                        <option value="cancelled" <?php selected($status, 'cancelled'); ?>>Cancelled</option>
                    </select>
                </td>
            </tr>
        </table> <?php 
    }

    public static function render_payment_meta_box($post)
    {
        wp_nonce_field('payment_details', 'payment_details_nonce');

        $payment_intent_id = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'payment_intent_id', true) ?: "No payment processed";
        $amount_paid = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'amount_paid', true) ?: 0;
        $payment_date = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'payment_date', true) ?: 0;

        ?>
        <table class="form-table">
            <tr>
                <th><label>Payment Intent ID</label></th>
                <td><?php echo esc_html($payment_intent_id); ?></td>
            </tr>
            <tr>
                <th><label>Amount Paid</label></th>
                <td>$<?php echo number_format($amount_paid, 2); ?></td>
            </tr>
            <tr>
                <th><label>Payment Date</label></th>
                <td><?php echo $payment_date ? date(get_option('obie_events_date_format') . ' H:i:s', $payment_date) : ''; ?></td>
            </tr>
        </table> <?php
    }

    public static function set_custom_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Registration ID');
        $new_columns['event'] = __('Event');
        $new_columns['customer'] = __('Customer');
        $new_columns['amount'] = __('Amount');
        $new_columns['status'] = __('Status');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public static function custom_column($column, $post_id)
    {
        switch ($column) {
            case 'event':
                $event_id = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_id', true);
                if ($event_id) {
                    echo '<a href="' . get_edit_post_link($event_id) . '">' . get_the_title($event_id) . '</a>';
                }
                break;

            case 'customer':
                $customer_name = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name', true);
                $customer_email = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email', true);
                echo esc_html($customer_name) . '<br/>';
                echo '<small>' . esc_html($customer_email) . '</small>';
                break;

            case 'amount':
                $amount_paid = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'amount_paid', true) ?: 0;
                echo '$' . number_format($amount_paid, 2);
                break;

            case 'status':
                $status = get_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'status', true);
                $status_class = 'status-' . $status;
                echo '<span class="' . esc_attr($status_class) . '">' . esc_html(ucfirst($status)) . '</span>';
                break;
        }
    }

    public static function sortable_columns($columns)
    {
        $columns['event'] = 'event';
        $columns['amount'] = 'amount';
        $columns['status'] = 'status';
        return $columns;
    }

    public static function save_post($post_id)
    {
        if (
            !isset($_POST['registration_details_nonce']) ||
            !wp_verify_nonce($_POST['registration_details_nonce'], 'registration_details')
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Guardar los campos editables
        if (isset($_POST['customer_name'])) {
            update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_name', sanitize_text_field($_POST['customer_name']));
        }

        if (isset($_POST['customer_email'])) {
            update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'customer_email', sanitize_email($_POST['customer_email']));
        }

        if (isset($_POST['status'])) {
            update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'status', sanitize_text_field($_POST['status']));
        }
    }
}
