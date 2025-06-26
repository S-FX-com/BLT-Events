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

        add_shortcode('obie_events_calendar', array(__CLASS__, 'calendar_shortcode'));

        add_action('wp_ajax_obie_events_get_list', array(__CLASS__, 'ajax_get_events_list'));
        add_action('wp_ajax_nopriv_obie_events_get_list', array(__CLASS__, 'ajax_get_events_list'));
        add_action('wp_ajax_obie_events_get_month', array(__CLASS__, 'ajax_get_events_month'));
        add_action('wp_ajax_nopriv_obie_events_get_month', array(__CLASS__, 'ajax_get_events_month'));
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
?>
        <table class="form-table">
            <tr>
                <th><label for="event_date">Event Date</label></th>
                <td><input type="date" id="event_date" name="event_date" value="<?php echo esc_attr($event_date); ?>" /></td>
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
                }

                public static function calendar_shortcode($atts)
                {
                    $atts = shortcode_atts(array(
                        'view' => 'list', // 'list' o 'month'
                        'events_per_page' => 5
                    ), $atts, 'obie_events_calendar');

                    $view = sanitize_text_field($atts['view']);
                    $events_per_page = intval($atts['events_per_page']);

                    // Enqueue scripts específicos para el calendario
                    wp_enqueue_script('obie-events-calendar', OBIE_EVENTS_PLUGIN_URL . 'assets/js/calendar.js', array('jquery'), '1.0', true);
                    wp_localize_script('obie-events-calendar', 'obieCalendarData', array(
                        'ajaxUrl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('obie_events_calendar_nonce'),
                        'eventsPerPage' => $events_per_page
                    ));

                    ob_start(); ?>

        <div id="obie-events-calendar-container" class="obie-events-calendar">
            <!-- Vista Toggle -->
            <div class="calendar-view-toggle">
                <button type="button" class="view-toggle-btn <?php echo $view === 'list' ? 'active' : ''; ?>" data-view="list">
                    Lista
                </button>
                <button type="button" class="view-toggle-btn <?php echo $view === 'month' ? 'active' : ''; ?>" data-view="month">
                    Mes
                </button>
            </div>

            <!-- Vista Lista -->
            <div id="calendar-list-view" class="calendar-view <?php echo $view === 'list' ? 'active' : ''; ?>">
                <div class="list-navigation">
                    <button type="button" id="list-prev" class="nav-btn">« Anteriores</button>
                    <span class="list-info">Próximos eventos</span>
                    <button type="button" id="list-next" class="nav-btn">Siguientes »</button>
                </div>
                <div id="events-list-container" class="events-list">
                    <div class="loading">Cargando eventos...</div>
                </div>
            </div>

            <!-- Vista Mes -->
            <div id="calendar-month-view" class="calendar-view <?php echo $view === 'month' ? 'active' : ''; ?>">
                <div class="month-navigation">
                    <button type="button" id="month-prev" class="nav-btn">« Anterior</button>
                    <span id="current-month" class="month-title"><?php echo date('F Y'); ?></span>
                    <button type="button" id="month-next" class="nav-btn">Siguiente »</button>
                </div>
                <div id="calendar-grid" class="calendar-grid">
                    <div class="calendar-header">
                        <div class="day-header">Dom</div>
                        <div class="day-header">Lun</div>
                        <div class="day-header">Mar</div>
                        <div class="day-header">Mié</div>
                        <div class="day-header">Jue</div>
                        <div class="day-header">Vie</div>
                        <div class="day-header">Sáb</div>
                    </div>
                    <div id="calendar-days" class="calendar-days">
                        <div class="loading">Cargando calendario...</div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .obie-events-calendar {
                max-width: 1000px;
                margin: 0 auto;
                font-family: Arial, sans-serif;
            }

            .calendar-view-toggle {
                text-align: center;
                margin-bottom: 20px;
            }

            .view-toggle-btn {
                background: #f0f0f0;
                border: 1px solid #ddd;
                padding: 10px 20px;
                margin: 0 5px;
                cursor: pointer;
                border-radius: 5px;
                transition: all 0.3s ease;
            }

            .view-toggle-btn.active {
                background: #0073aa;
                color: white;
                border-color: #0073aa;
            }

            .calendar-view {
                display: none;
            }

            .calendar-view.active {
                display: block;
            }

            /* Vista Lista */
            .list-navigation,
            .month-navigation {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding: 0 10px;
            }

            .nav-btn {
                background: #0073aa;
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 5px;
                cursor: pointer;
                transition: background 0.3s ease;
            }

            .nav-btn:hover {
                background: #005a87;
            }

            .nav-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }

            .list-info,
            .month-title {
                font-size: 18px;
                font-weight: bold;
                color: #333;
            }

            .events-list {
                min-height: 200px;
            }

            .event-item {
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 15px;
                background: white;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: transform 0.2s ease;
            }

            .event-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            }

            .event-title {
                font-size: 18px;
                font-weight: bold;
                color: #0073aa;
                margin-bottom: 8px;
            }

            .event-title a {
                text-decoration: none;
                color: inherit;
            }

            .event-date {
                color: #666;
                font-size: 14px;
                margin-bottom: 5px;
            }

            .event-time {
                color: #888;
                font-size: 13px;
                margin-bottom: 10px;
            }

            .event-excerpt {
                color: #555;
                line-height: 1.5;
            }

            /* Vista Mes */
            .calendar-grid {
                border: 1px solid #ddd;
                border-radius: 8px;
                overflow: hidden;
                background: white;
            }

            .calendar-header {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                background: #f8f9fa;
                border-bottom: 1px solid #ddd;
            }

            .day-header {
                padding: 10px;
                text-align: center;
                font-weight: bold;
                color: #333;
                border-right: 1px solid #ddd;
            }

            .day-header:last-child {
                border-right: none;
            }

            .calendar-days {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                min-height: 400px;
            }

            .calendar-day {
                border-right: 1px solid #eee;
                border-bottom: 1px solid #eee;
                min-height: 80px;
                padding: 5px;
                position: relative;
                background: white;
            }

            .calendar-day:last-child {
                border-right: none;
            }

            .calendar-day.other-month {
                background: #f8f9fa;
                color: #ccc;
            }

            .calendar-day.today {
                background: #e7f3ff;
            }

            .day-number {
                font-weight: bold;
                margin-bottom: 5px;
            }

            .day-events {
                font-size: 11px;
            }

            .day-event {
                background: #0073aa;
                color: white;
                padding: 2px 4px;
                margin: 1px 0;
                border-radius: 3px;
                cursor: pointer;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .day-event:hover {
                background: #005a87;
            }

            .loading {
                text-align: center;
                padding: 40px;
                color: #666;
            }

            .no-events {
                text-align: center;
                padding: 40px;
                color: #999;
                font-style: italic;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .calendar-days {
                    min-height: 300px;
                }

                .calendar-day {
                    min-height: 60px;
                }

                .day-events {
                    font-size: 10px;
                }

                .list-navigation,
                .month-navigation {
                    flex-direction: column;
                    gap: 10px;
                }

                .nav-btn {
                    padding: 10px 20px;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                var currentPage = 0;
                var currentMonth = new Date().getMonth();
                var currentYear = new Date().getFullYear();
                var currentView = '<?php echo $view; ?>';
                var isLoading = false;

                // Cambio de vista
                $('.view-toggle-btn').on('click', function() {
                    var newView = $(this).data('view');
                    if (newView === currentView) return;

                    $('.view-toggle-btn').removeClass('active');
                    $(this).addClass('active');
                    $('.calendar-view').removeClass('active');
                    $('#calendar-' + newView + '-view').addClass('active');

                    currentView = newView;

                    if (newView === 'list') {
                        loadEventsList(0);
                    } else {
                        loadEventsMonth(currentMonth, currentYear);
                    }
                });

                // Navegación lista
                $('#list-prev').on('click', function() {
                    if (currentPage > 0) {
                        currentPage--;
                        loadEventsList(currentPage);
                    }
                });

                $('#list-next').on('click', function() {
                    currentPage++;
                    loadEventsList(currentPage);
                });

                // Navegación mes
                $('#month-prev').on('click', function() {
                    currentMonth--;
                    if (currentMonth < 0) {
                        currentMonth = 11;
                        currentYear--;
                    }
                    loadEventsMonth(currentMonth, currentYear);
                });

                $('#month-next').on('click', function() {
                    currentMonth++;
                    if (currentMonth > 11) {
                        currentMonth = 0;
                        currentYear++;
                    }
                    loadEventsMonth(currentMonth, currentYear);
                });

                // Cargar eventos iniciales
                if (currentView === 'list') {
                    loadEventsList(0);
                } else {
                    loadEventsMonth(currentMonth, currentYear);
                }

                function loadEventsList(page) {
                    if (isLoading) return;
                    isLoading = true;

                    $('#events-list-container').html('<div class="loading">Cargando eventos...</div>');
                    $('#list-prev').prop('disabled', true);
                    $('#list-next').prop('disabled', true);

                    $.ajax({
                        url: obieCalendarData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'obie_events_get_list',
                            page: page,
                            per_page: obieCalendarData.eventsPerPage,
                            nonce: obieCalendarData.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#events-list-container').html(response.data.html);
                                $('#list-prev').prop('disabled', page === 0);
                                $('#list-next').prop('disabled', !response.data.has_more);
                            } else {
                                $('#events-list-container').html('<div class="no-events">Error al cargar eventos</div>');
                            }
                        },
                        error: function() {
                            $('#events-list-container').html('<div class="no-events">Error al cargar eventos</div>');
                        },
                        complete: function() {
                            isLoading = false;
                        }
                    });
                }

                function loadEventsMonth(month, year) {
                    if (isLoading) return;
                    isLoading = true;

                    $('#calendar-days').html('<div class="loading">Cargando calendario...</div>');

                    var monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
                    ];
                    $('#current-month').text(monthNames[month] + ' ' + year);

                    $.ajax({
                        url: obieCalendarData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'obie_events_get_month',
                            month: month + 1,
                            year: year,
                            nonce: obieCalendarData.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#calendar-days').html(response.data.html);
                            } else {
                                $('#calendar-days').html('<div class="no-events">Error al cargar calendario</div>');
                            }
                        },
                        error: function() {
                            $('#calendar-days').html('<div class="no-events">Error al cargar calendario</div>');
                        },
                        complete: function() {
                            isLoading = false;
                        }
                    });
                }
            });
        </script>

        <?php
                    return ob_get_clean();
                }

                public static function ajax_get_events_list()
                {
                    if (!wp_verify_nonce($_POST['nonce'], 'obie_events_calendar_nonce')) {
                        wp_die('Security check failed');
                    }

                    $page = intval($_POST['page']);
                    $per_page = intval($_POST['per_page']) ?: 5;
                    $offset = $page * $per_page;

                    $args = array(
                        'post_type' => 'event',
                        'post_status' => 'publish',
                        'posts_per_page' => $per_page,
                        'offset' => $offset,
                        'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
                        'orderby' => 'meta_value',
                        'order' => 'ASC',
                        'meta_query' => array(
                            array(
                                'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
                                'value' => date('Y-m-d'),
                                'compare' => '>='
                            )
                        )
                    );

                    $events = get_posts($args);

                    // Verificar si hay más eventos
                    $check_args = $args;
                    $check_args['posts_per_page'] = 1;
                    $check_args['offset'] = $offset + $per_page;
                    $has_more = !empty(get_posts($check_args));

                    ob_start();

                    if (!empty($events)) {
                        foreach ($events as $event) {
                            $event_date = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
                            $event_start_time = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', true);
                            $event_end_time = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_end_time', true);
                            $event_all_day = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_all_day', true);

                            $formatted_date = date('l, j F Y', strtotime($event_date));
        ?>
                <div class="event-item">
                    <div class="event-title">
                        <a href="<?php echo get_permalink($event->ID); ?>">
                            <?php echo esc_html($event->post_title); ?>
                        </a>
                    </div>
                    <div class="event-date"><?php echo $formatted_date; ?></div>
                    <?php if (!$event_all_day && ($event_start_time || $event_end_time)) : ?>
                        <div class="event-time">
                            <?php
                                if ($event_start_time) echo date('g:i A', strtotime($event_start_time));
                                if ($event_start_time && $event_end_time) echo ' - ';
                                if ($event_end_time) echo date('g:i A', strtotime($event_end_time));
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($event->post_excerpt) : ?>
                        <div class="event-excerpt"><?php echo esc_html($event->post_excerpt); ?></div>
                    <?php endif; ?>
                </div>
<?php
                        }
                    } else {
                        echo '<div class="no-events">No se encontraron eventos próximos</div>';
                    }

                    $html = ob_get_clean();

                    wp_send_json_success(array(
                        'html' => $html,
                        'has_more' => $has_more
                    ));
                }

                public static function ajax_get_events_month()
                {
                    if (!wp_verify_nonce($_POST['nonce'], 'obie_events_calendar_nonce')) {
                        wp_die('Security check failed');
                    }

                    $month = intval($_POST['month']);
                    $year = intval($_POST['year']);

                    // Obtener eventos del mes
                    $start_date = sprintf('%04d-%02d-01', $year, $month);
                    $end_date = date('Y-m-t', strtotime($start_date));

                    $args = array(
                        'post_type' => 'event',
                        'post_status' => 'publish',
                        'posts_per_page' => -1,
                        'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
                        'orderby' => 'meta_value',
                        'order' => 'ASC',
                        'meta_query' => array(
                            array(
                                'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
                                'value' => array($start_date, $end_date),
                                'compare' => 'BETWEEN'
                            )
                        )
                    );

                    $events = get_posts($args);

                    // Agrupar eventos por día
                    $events_by_day = array();
                    foreach ($events as $event) {
                        $event_date = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
                        $day = date('j', strtotime($event_date));
                        if (!isset($events_by_day[$day])) {
                            $events_by_day[$day] = array();
                        }
                        $events_by_day[$day][] = $event;
                    }

                    // Generar calendario
                    $first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
                    $days_in_month = date('t', $first_day_of_month);
                    $first_day_of_week = date('w', $first_day_of_month);

                    // Días del mes anterior para completar la primera semana
                    $prev_month_days = $first_day_of_week;
                    $prev_month = $month == 1 ? 12 : $month - 1;
                    $prev_year = $month == 1 ? $year - 1 : $year;
                    $prev_month_last_day = date('t', mktime(0, 0, 0, $prev_month, 1, $prev_year));

                    ob_start();

                    // Días del mes anterior
                    for ($i = $prev_month_days - 1; $i >= 0; $i--) {
                        $day = $prev_month_last_day - $i;
                        echo '<div class="calendar-day other-month">';
                        echo '<div class="day-number">' . $day . '</div>';
                        echo '</div>';
                    }

                    // Días del mes actual
                    $today = date('Y-m-d');
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $is_today = ($current_date === $today);

                        echo '<div class="calendar-day' . ($is_today ? ' today' : '') . '">';
                        echo '<div class="day-number">' . $day . '</div>';

                        if (isset($events_by_day[$day])) {
                            echo '<div class="day-events">';
                            foreach ($events_by_day[$day] as $event) {
                                echo '<div class="day-event" title="' . esc_attr($event->post_title) . '">';
                                echo esc_html(substr($event->post_title, 0, 20) . (strlen($event->post_title) > 20 ? '...' : ''));
                                echo '</div>';
                            }
                            echo '</div>';
                        }

                        echo '</div>';
                    }

                    // Completar la última semana con días del mes siguiente
                    $total_cells = ceil(($days_in_month + $first_day_of_week) / 7) * 7;
                    $remaining_cells = $total_cells - ($days_in_month + $first_day_of_week);

                    for ($day = 1; $day <= $remaining_cells; $day++) {
                        echo '<div class="calendar-day other-month">';
                        echo '<div class="day-number">' . $day . '</div>';
                        echo '</div>';
                    }

                    $html = ob_get_clean();

                    wp_send_json_success(array(
                        'html' => $html
                    ));
                }
            }
