<?php
class Obie_Events_CPT
{
    public static $slug = 'event';

    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('init', array(__CLASS__, 'register_taxonomies'));
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post', array(__CLASS__, 'save_meta_box_data'));
    }

    public static function register_post_type()
    {
        $labels = array(
            'name'               => 'Events',
            'singular_name'      => 'Event',
            'menu_name'          => 'Events',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Event',
            'edit_item'          => 'Edit Event',
            'new_item'           => 'New Event',
            'view_item'          => 'View Event',
            'search_items'       => 'Search Events',
            'not_found'          => 'No events found',
            'not_found_in_trash' => 'No events found in Trash',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => self::$slug),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array('title', 'editor', 'thumbnail', 'excerpt'),
        );

        register_post_type(self::$slug, $args);

        flush_rewrite_rules();
    }

    public static function register_taxonomies()
    {
        $labels = array(
            'name'              => 'Event Categories',
            'singular_name'     => 'Event Category',
            'search_items'      => 'Search Event Categories',
            'all_items'         => 'All Event Categories',
            'parent_item'       => 'Parent Event Category',
            'parent_item_colon' => 'Parent Event Category:',
            'edit_item'         => 'Edit Event Category',
            'update_item'       => 'Update Event Category',
            'add_new_item'      => 'Add New Event Category',
            'new_item_name'     => 'New Event Category Name',
            'menu_name'         => 'Categories',
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array('slug' => 'event-category'),
        );

        register_taxonomy('event_category', array(self::$slug), $args);
    }

    public static function add_meta_boxes()
    {
        add_meta_box(
            'event_details',
            'Event Details',
            array(__CLASS__, 'render_meta_box'),
            self::$slug,
            'normal',
            'high'
        );

        // Add SureCart sync status meta box when SureCart is the payment provider
        if (get_option('obie_events_payment_provider') === 'surecart') {
            add_meta_box(
                'event_surecart_sync',
                'SureCart Integration',
                array(__CLASS__, 'render_surecart_meta_box'),
                self::$slug,
                'side',
                'default'
            );
        }
    }

    public static function render_surecart_meta_box($post)
    {
        $sc_product_ids = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_product_ids', true) ?: array();
        $sc_price_ids   = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'sc_price_ids', true) ?: array();
        $ticket_types   = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);
        $by_tickets     = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_by_tickets', true);

        if (!$by_tickets || empty($ticket_types)) {
            echo '<p>Enable tickets and save the event to sync with SureCart.</p>';
            return;
        }

        $has_synced = !empty($sc_product_ids) && !empty($sc_price_ids);
        $status_class = $has_synced ? 'color: #059669;' : 'color: #dc2626;';
        $status_text  = $has_synced ? 'Synced' : 'Not synced';

        echo '<p><strong>Status:</strong> <span style="' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span></p>';

        if ($has_synced) {
            echo '<table class="widefat fixed" style="font-size: 12px;">';
            echo '<thead><tr><th>Ticket</th><th>SureCart ID</th></tr></thead><tbody>';
            foreach ($ticket_types as $index => $ticket) {
                $synced = isset($sc_product_ids[$index]) && isset($sc_price_ids[$index]);
                echo '<tr>';
                echo '<td>' . esc_html($ticket['name']) . '</td>';
                echo '<td>' . ($synced ? '<span style="color:#059669;">OK</span>' : '<span style="color:#dc2626;">Missing</span>') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<p class="description">Products sync automatically when you save the event. SureCart checkout will use these synced products.</p>';
    }

    public static function render_meta_box($post)
    {
        wp_nonce_field('event_details', 'event_details_nonce');

        $with_capacity = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_with_capacity', true);
        $max_capacity = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_max_capacity', true);
        $by_tickets = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_by_tickets', true);
        $ticket_types = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);
        // Obtener roles de WP
        global $wp_roles;
        $roles = $wp_roles->roles;
        // Recuperar fecha, hora de inicio, hora final y todo el día
        $event_date = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
        $event_start_time = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', true);
        $event_end_time = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_end_time', true);
        $event_all_day = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_all_day', true);
        $event_type = get_post_meta($post->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_type', true);
?>
        <table class="form-table">
            <tr>
                <th><label for="event_date">Event Date</label></th>
                <td><input type="date" id="event_date" name="event_date" value="<?php echo esc_attr($event_date); ?>" /></td>
            </tr>
            <tr>
                <th><label for="event_type">Event Type</label></th>
                <td>
                    <select id="event_type" name="event_type">
                        <option value="in_person" <?php selected($event_type, 'in_person'); ?>>In Person</option>
                        <option value="virtual" <?php selected($event_type, 'virtual'); ?>>Virtual</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="event_all_day">
                        <input type="checkbox" id="event_all_day" name="event_all_day" value="1" <?php checked($event_all_day, '1'); ?> />
                        All Day Event
                    </label></th>
                <td></td>
            </tr>
            <tr id="event_start_time_row" style="display: <?php echo ($event_all_day == '1') ? 'none' : 'table-row'; ?>;">
                <th><label for="event_start_time">Start Time</label></th>
                <td><input type="time" id="event_start_time" name="event_start_time" value="<?php echo esc_attr($event_start_time); ?>" /></td>
            </tr>
            <tr id="event_end_time_row" style="display: <?php echo ($event_all_day == '1') ? 'none' : 'table-row'; ?>;">
                <th><label for="event_end_time">End Time</label></th>
                <td><input type="time" id="event_end_time" name="event_end_time" value="<?php echo esc_attr($event_end_time); ?>" /></td>
            </tr>
            <script>
                jQuery(document).ready(function($) {
                    $('#event_all_day').change(function() {
                        if (this.checked) {
                            $('#event_start_time_row').hide();
                            $('#event_end_time_row').hide();
                        } else {
                            $('#event_start_time_row').show();
                            $('#event_end_time_row').show();
                        }
                    });
                });
            </script>
            <tr>
                <th>
                    <label for="with_capacity">
                        <input type="checkbox" id="with_capacity" name="with_capacity" value="1" <?php checked($with_capacity, '1'); ?> />
                        Event Capacity
                    </label>
                </th>
                <td>
                    <div id="with_capacity_container" style="display: <?php echo $with_capacity ? 'block' : 'none'; ?>;">
                        <input type="text" id="max_capacity" name="max_capacity" value="<?php echo esc_attr($max_capacity); ?>" placeholder="Max Capacity" class="regular-text" />
                    </div>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="by_tickets">
                        <input type="checkbox" id="by_tickets" name="by_tickets" value="1" <?php checked($by_tickets, '1'); ?> />
                        Event Tickets
                    </label>
                </th>
                <td>
                    <div id="ticket_types" style="display: <?php echo $by_tickets ? 'block' : 'none'; ?>;">
                        <table class="widefat fixed" style="width: auto;">
                            <thead>
                                <tr>
                                    <th>Ticket Name</th>
                                    <th>Ticket Price</th>
                                    <th>Allowed Roles</th>
                                    <th>Expiration Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="ticket_type_container">
                                <?php if (!empty($ticket_types)) { ?>
                                    <?php foreach ($ticket_types as $index => $ticket) { ?>
                                        <tr class="ticket_type">
                                            <td>
                                                <input type="text" name="ticket_type_name[]" value="<?php echo esc_attr($ticket['name']); ?>" placeholder="Ticket Name" />
                                            </td>
                                            <td>
                                                <input type="number" name="ticket_type_price[]" value="<?php echo esc_attr($ticket['price']); ?>" placeholder="Ticket Price" step="0.01" min="0" />
                                            </td>
                                            <td>
                                                <select name="ticket_type_roles[<?php echo $index; ?>][]" multiple style="min-width:120px;">
                                                    <?php foreach ($roles as $role_key => $role) { ?>
                                                        <option value="<?php echo esc_attr($role_key); ?>" <?php if (!empty($ticket['roles']) && in_array($role_key, $ticket['roles'])) echo 'selected'; ?>><?php echo esc_html($role['name']); ?></option>
                                                    <?php } ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="date" name="ticket_type_expiration[]" value="<?php echo !empty($ticket['expiration']) ? esc_attr($ticket['expiration']) : ''; ?>" />
                                            </td>
                                            <td>
                                                <button type="button" class="remove_ticket_type button">Remove</button>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                        <button type="button" class="button button-primary" id="add_ticket_type">Add Ticket Type</button>
                    </div>
                </td>
            </tr>
        </table>
        <script>
            jQuery(document).ready(function($) {
                $('#by_tickets').change(function() {
                    $('#ticket_types').toggle(this.checked);
                });

                $('#with_capacity').change(function() {
                    $('#with_capacity_container').toggle(this.checked);
                });

                $('#add_ticket_type').click(function() {
                    var rolesOptions = '';
                    <?php foreach ($roles as $role_key => $role) { ?>
                        rolesOptions += '<option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role['name']); ?></option>';
                    <?php } ?>
                    var html =
                        '<tr class="ticket_type">' +
                        '<td><input type="text" name="ticket_type_name[]" placeholder="Ticket Name" /></td>' +
                        '<td><input type="number" name="ticket_type_price[]" placeholder="Ticket Price" step="0.01" min="0" /></td>' +
                        '<td><select name="ticket_type_roles[' + ($('#ticket_type_container tr').length) + '][]" multiple style="min-width:120px;">' + rolesOptions + '</select></td>' +
                        '<td><input type="date" name="ticket_type_expiration[]" /></td>' +
                        '<td><button type="button" class="button remove_ticket_type">Remove</button></td>' +
                        '</tr>';
                    $('#ticket_type_container').append(html);
                });

                $(document).on('click', '.remove_ticket_type', function() {
                    $(this).parent().parent().remove();
                });
            });
        </script> <?php
                }

                public static function save_meta_box_data($post_id)
                {
                    if (!isset($_POST['event_details_nonce']) || !wp_verify_nonce($_POST['event_details_nonce'], 'event_details')) {
                        return;
                    }

                    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                        return;
                    }

                    if (!current_user_can('edit_post', $post_id)) {
                        return;
                    }

                    // Guardar fecha, hora de inicio, hora final y todo el día
                    $event_date = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : '';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', $event_date);
                    $event_start_time = isset($_POST['event_start_time']) ? sanitize_text_field($_POST['event_start_time']) : '';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', $event_start_time);
                    $event_end_time = isset($_POST['event_end_time']) ? sanitize_text_field($_POST['event_end_time']) : '';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_end_time', $event_end_time);
                    $event_all_day = isset($_POST['event_all_day']) ? '1' : '0';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_all_day', $event_all_day);

                    $with_capacity = isset($_POST['with_capacity']) ? '1' : '0';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_with_capacity', $with_capacity);

                    if (isset($_POST['max_capacity'])) {
                        update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_max_capacity', absint($_POST['max_capacity']));
                    }

                    $by_tickets = isset($_POST['by_tickets']) ? '1' : '0';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_by_tickets', $by_tickets);

                    $ticket_types = array();
                    if ($by_tickets && isset($_POST['ticket_type_name']) && isset($_POST['ticket_type_price'])) {
                        $names = $_POST['ticket_type_name'];
                        $prices = $_POST['ticket_type_price'];
                        $roles = isset($_POST['ticket_type_roles']) ? $_POST['ticket_type_roles'] : array();
                        $expirations = isset($_POST['ticket_type_expiration']) ? $_POST['ticket_type_expiration'] : array();
                        for ($i = 0; $i < count($names); $i++) {
                            if (!empty($names[$i]) && is_numeric($prices[$i])) {
                                $ticket_types[] = array(
                                    'name' => sanitize_text_field($names[$i]),
                                    'price' => floatval($prices[$i]),
                                    'roles' => isset($roles[$i]) ? array_map('sanitize_text_field', (array)$roles[$i]) : array(),
                                    'expiration' => isset($expirations[$i]) ? sanitize_text_field($expirations[$i]) : ''
                                );
                            }
                        }
                    }
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', $ticket_types);

                    $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : 'in_person';
                    update_post_meta($post_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_type', $event_type);
                }
            }
