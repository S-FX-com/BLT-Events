<?php
/**
 * BLT Events - Event Custom Post Type
 *
 * Registers the "event" custom post type and the "event_category" taxonomy.
 * Meta boxes are handled separately in the event metabox class.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLT_Events_Event_CPT {

    /**
     * Post type slug.
     *
     * @var string
     */
    public static $slug = 'event';

    /**
     * Initialize hooks for the event CPT.
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
        add_action( 'admin_head', array( __CLASS__, 'print_menu_icon_style' ) );
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );

        // Admin list table columns.
        add_filter( 'manage_' . self::$slug . '_posts_columns', array( __CLASS__, 'set_admin_columns' ) );
        add_action( 'manage_' . self::$slug . '_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
        add_filter( 'manage_edit-' . self::$slug . '_sortable_columns', array( __CLASS__, 'sortable_admin_columns' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'handle_admin_column_sorting' ) );

        // Admin list table filters: Event Categories, Type and Status
        // dropdowns, and the "All dates" dropdown re-pointed at the event
        // start date instead of the post's publish date.
        add_action( 'restrict_manage_posts', array( __CLASS__, 'render_admin_filters' ), 10, 2 );
        add_action( 'pre_get_posts', array( __CLASS__, 'handle_admin_filters' ) );
        add_filter( 'months_dropdown_results', array( __CLASS__, 'months_dropdown_by_event_date' ), 10, 2 );
    }

    /**
     * Light the BLT mark up on hover and while the Events section is open, the
     * way core does for a dashicon menu item.
     *
     * This plugin's top-level menu is the CPT's own, so WordPress builds its id
     * as `menu-posts-event` rather than the `toplevel_page_<slug>` that
     * add_menu_page() produces — hence the _for_id() variant of the shared
     * helper.
     *
     * @return void
     */
    public static function print_menu_icon_style() {
        if ( ! class_exists( 'BLT_Family_Brand' ) ) {
            return;
        }

        BLT_Family_Brand::print_menu_icon_style_for_id( 'menu-posts-' . self::$slug );
    }

    /**
     * Register the event custom post type.
     */
    public static function register_post_type() {
        $labels = array(
            'name'               => __( 'Events', 'blt-events' ),
            'singular_name'      => __( 'Event', 'blt-events' ),
            'menu_name'          => __( 'Events', 'blt-events' ),
            'add_new'            => __( 'Add New', 'blt-events' ),
            'add_new_item'       => __( 'Add New Event', 'blt-events' ),
            'edit_item'          => __( 'Edit Event', 'blt-events' ),
            'new_item'           => __( 'New Event', 'blt-events' ),
            'view_item'          => __( 'View Event', 'blt-events' ),
            'search_items'       => __( 'Search Events', 'blt-events' ),
            'not_found'          => __( 'No events found', 'blt-events' ),
            'not_found_in_trash' => __( 'No events found in Trash', 'blt-events' ),
        );

        /**
         * Filter the arguments used to register the event post type.
         *
         * @param array $args register_post_type() arguments.
         */
        $args = apply_filters( 'blt_events_post_type_args', array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => self::$slug ),
            // Own capabilities (edit_blt_events, ...) so who manages events is
            // decided independently of who writes posts. Granted to the
            // standard roles on activation/upgrade; see BLT_Events_Roles.
            'capability_type'    => array( 'blt_event', 'blt_events' ),
            'map_meta_cap'       => true,
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            // The BLT mark, so every plugin in the family carries the same icon
            // in the admin menu. Admin-only: the icon is a data URI built by
            // reading a bundled file, and nothing on the front end renders it.
            // Falls back to the dashicon when the family library is absent.
            'menu_icon'          => ( is_admin() && class_exists( 'BLT_Family_Brand' ) )
                ? BLT_Family_Brand::menu_icon( BLT_EVENTS_PLUGIN_DIR, 'dashicons-calendar-alt' )
                : 'dashicons-calendar-alt',
            // No excerpt: the Event Description card is the one place event
            // copy is written. Excerpts saved earlier are still read.
            'supports'           => array( 'title', 'editor', 'thumbnail' ),
            // Expose to the block editor, core REST API, and Query Loop blocks.
            'show_in_rest'       => true,
        ) );

        register_post_type( self::$slug, $args );
    }

    /**
     * Register the event_category hierarchical taxonomy on the event post type.
     */
    public static function register_taxonomies() {
        $labels = array(
            'name'              => __( 'Event Categories', 'blt-events' ),
            'singular_name'     => __( 'Event Category', 'blt-events' ),
            'search_items'      => __( 'Search Event Categories', 'blt-events' ),
            'all_items'         => __( 'All Event Categories', 'blt-events' ),
            'parent_item'       => __( 'Parent Event Category', 'blt-events' ),
            'parent_item_colon' => __( 'Parent Event Category:', 'blt-events' ),
            'edit_item'         => __( 'Edit Event Category', 'blt-events' ),
            'update_item'       => __( 'Update Event Category', 'blt-events' ),
            'add_new_item'      => __( 'Add New Event Category', 'blt-events' ),
            'new_item_name'     => __( 'New Event Category Name', 'blt-events' ),
            'menu_name'         => __( 'Categories', 'blt-events' ),
        );

        /**
         * Filter the arguments used to register the event_category taxonomy.
         *
         * @param array $args register_taxonomy() arguments.
         */
        $args = apply_filters( 'blt_events_taxonomy_args', array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'event-category' ),
            'show_in_rest'      => true,
            'capabilities'      => array(
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_blt_events',
            ),
        ) );

        register_taxonomy( 'event_category', array( self::$slug ), $args );
    }

    /**
     * Force the event post type onto the classic editor. The Add/Edit
     * Event screen is built from metaboxes designed for that layout;
     * show_in_rest stays enabled so the REST API and Query Loop blocks
     * keep working on the front end.
     */
    public static function disable_block_editor( $use_block_editor, $post_type ) {
        if ( self::$slug === $post_type ) {
            return false;
        }

        return $use_block_editor;
    }

    /* ------------------------------------------------------------------
     * Admin list table columns
     * ------------------------------------------------------------------ */

    /**
     * Columns for the Events list: Title, Categories, Type, Attendees
     * (count linked to the attendee list), Start Date.
     */
    public static function set_admin_columns( $columns ) {
        $new = array();

        $new['cb']    = $columns['cb'] ?? '<input type="checkbox" />';
        $new['title'] = $columns['title'] ?? __( 'Title', 'blt-events' );

        // Keep the taxonomy column WordPress registers via show_admin_column
        // so its quick filtering keeps working, just controlling its position.
        if ( isset( $columns['taxonomy-event_category'] ) ) {
            $new['taxonomy-event_category'] = $columns['taxonomy-event_category'];
        }

        $new['blt_event_type'] = __( 'Type', 'blt-events' );
        $new['blt_status']     = __( 'Status', 'blt-events' );
        $new['blt_attendees']  = __( 'Attendees', 'blt-events' );
        $new['blt_event_date'] = __( 'Start Date', 'blt-events' );

        return $new;
    }

    /**
     * Whether an event's start date is today or later, site time. The
     * single source of truth for "Upcoming" across the admin list, its
     * Status filter, and this column — matches the same >= today
     * comparison the front-end calendar's default (non-past) listing uses.
     *
     * @param int $post_id Event post ID.
     * @return bool
     */
    private static function is_upcoming( $post_id ) {
        $event_date = get_post_meta( $post_id, '_blt_event_date', true );

        return $event_date && $event_date >= current_time( 'Y-m-d' );
    }

    public static function render_admin_column( $column, $post_id ) {
        switch ( $column ) {
            case 'blt_event_type':
                $type   = get_post_meta( $post_id, '_blt_event_type', true ) ?: 'in-person';
                $labels = array(
                    'online'    => __( 'Online', 'blt-events' ),
                    'in-person' => __( 'In-Person', 'blt-events' ),
                    'hybrid'    => __( 'Hybrid', 'blt-events' ),
                );
                $classes = array(
                    'online'    => 'blt-badge-type-online',
                    'in-person' => 'blt-badge-type-in-person',
                    'hybrid'    => 'blt-badge-type-hybrid',
                );
                printf(
                    '<span class="blt-badge %1$s">%2$s</span>',
                    esc_attr( $classes[ $type ] ?? 'blt-badge-type-in-person' ),
                    esc_html( $labels[ $type ] ?? $labels['in-person'] )
                );
                break;

            case 'blt_status':
                $upcoming = self::is_upcoming( $post_id );
                printf(
                    '<span class="blt-badge blt-badge-solid %1$s">%2$s</span>',
                    esc_attr( $upcoming ? 'blt-badge-status-upcoming' : 'blt-badge-status-expired' ),
                    esc_html( $upcoming ? __( 'Upcoming', 'blt-events' ) : __( 'Expired', 'blt-events' ) )
                );
                break;

            case 'blt_attendees':
                $reg_db = new BLT_Events_Registrations_DB();
                $count  = $reg_db->get_event_registration_count( $post_id );

                if ( $count < 1 ) {
                    echo '<span class="blt-text-muted">&mdash;</span>';
                    break;
                }

                $attendees_url = admin_url( 'edit.php?post_type=event&page=blt-registrations&event_id=' . $post_id );

                $capacity_raw = get_post_meta( $post_id, '_blt_capacity', true );
                $capacity     = (int) $capacity_raw;

                printf(
                    '<a href="%1$s" class="blt-attendee-count">%2$s</a><br /><span class="blt-attendee-capacity">%3$s</span>',
                    esc_url( $attendees_url ),
                    esc_html( number_format_i18n( $count ) ),
                    $capacity > 0
                        ? esc_html( sprintf(
                            /* translators: %s: percentage of capacity filled. */
                            __( '(%s%%)', 'blt-events' ),
                            number_format_i18n( round( ( $count / $capacity ) * 100 ) )
                        ) )
                        : esc_html__( 'Unlimited', 'blt-events' )
                );
                break;

            case 'blt_event_date':
                $event_date = get_post_meta( $post_id, '_blt_event_date', true );
                if ( ! $event_date ) {
                    echo '<span class="blt-text-muted">&mdash;</span>';
                    break;
                }
                $format = get_option( 'blt_events_date_format', 'F j, Y' );
                echo esc_html( date_i18n( $format, strtotime( $event_date ) ) );
                break;
        }
    }

    public static function sortable_admin_columns( $columns ) {
        $columns['blt_event_date'] = 'blt_event_date';
        return $columns;
    }

    /**
     * Sort by event start date: by default (no column explicitly clicked),
     * and whenever the Start Date column header is clicked. Descending in
     * both cases — upcoming events first (soonest future last within that
     * group), then past events, oldest at the very end. Events without a
     * date sort together at the end via NOT EXISTS.
     */
    public static function handle_admin_column_sorting( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( $query->get( 'post_type' ) !== self::$slug ) {
            return;
        }

        $orderby = $query->get( 'orderby' );
        if ( '' !== $orderby && 'blt_event_date' !== $orderby ) {
            return;
        }

        $query->set( 'meta_query', array(
            'relation'       => 'OR',
            'blt_date'       => array(
                'key'  => '_blt_event_date',
                'type' => 'DATE',
            ),
            'blt_date_empty' => array(
                'key'     => '_blt_event_date',
                'compare' => 'NOT EXISTS',
            ),
        ) );
        $query->set( 'orderby', array( 'blt_date' => $query->get( 'order' ) ?: 'DESC' ) );
    }

    /* ------------------------------------------------------------------
     * Admin list table filters
     * ------------------------------------------------------------------ */

    /**
     * Event Categories, Type and Status dropdowns above the list table.
     * Event Categories relies on WordPress's own handling of a registered
     * taxonomy's query var (same mechanism as core's own taxonomy filters);
     * Type and Status are handled in handle_admin_filters() below.
     */
    public static function render_admin_filters( $post_type, $which ) {
        if ( self::$slug !== $post_type || 'top' !== $which ) {
            return;
        }

        wp_dropdown_categories( array(
            'taxonomy'        => 'event_category',
            'name'            => 'event_category',
            'value_field'     => 'slug',
            'show_option_all' => __( 'All Event Categories', 'blt-events' ),
            'hide_empty'      => false,
            'selected'        => sanitize_key( $_GET['event_category'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ) );

        $type_labels  = array(
            'online'    => __( 'Online', 'blt-events' ),
            'in-person' => __( 'In-Person', 'blt-events' ),
            'hybrid'    => __( 'Hybrid', 'blt-events' ),
        );
        $current_type = sanitize_key( $_GET['blt_event_type_filter'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <select name="blt_event_type_filter">
            <option value=""><?php esc_html_e( 'All Types', 'blt-events' ); ?></option>
            <?php foreach ( $type_labels as $slug => $label ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        $current_status = sanitize_key( $_GET['blt_status_filter'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <select name="blt_status_filter">
            <option value=""><?php esc_html_e( 'All Statuses', 'blt-events' ); ?></option>
            <option value="upcoming" <?php selected( $current_status, 'upcoming' ); ?>><?php esc_html_e( 'Upcoming', 'blt-events' ); ?></option>
            <option value="expired" <?php selected( $current_status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'blt-events' ); ?></option>
        </select>
        <?php
    }

    /**
     * Apply the Type and Status filters, and re-point the "All dates"
     * dropdown at the event start date instead of the post's publish date.
     * Runs after handle_admin_column_sorting() (registered first, so it
     * fires first at the same default priority): any meta_query it already
     * set is nested as its own AND'd group rather than overwritten, since
     * a flat merge would incorrectly fold its internal OR relation into
     * these filters.
     */
    public static function handle_admin_filters( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( $query->get( 'post_type' ) !== self::$slug ) {
            return;
        }

        $clauses = array();

        $type = sanitize_key( $_GET['blt_event_type_filter'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( in_array( $type, array( 'online', 'in-person', 'hybrid' ), true ) ) {
            $clauses[] = array(
                'key'   => '_blt_event_type',
                'value' => $type,
            );
        }

        // Same >= today boundary as is_upcoming() and the front-end
        // calendar's default (non-past) listing.
        $status = sanitize_key( $_GET['blt_status_filter'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'upcoming' === $status ) {
            $clauses[] = array(
                'key'     => '_blt_event_date',
                'value'   => current_time( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            );
        } elseif ( 'expired' === $status ) {
            $clauses[] = array(
                'key'     => '_blt_event_date',
                'value'   => current_time( 'Y-m-d' ),
                'compare' => '<',
                'type'    => 'DATE',
            );
        }

        // WordPress's own "All dates" dropdown filters post_date (the
        // publish date); clear it and filter by the event's own start
        // date instead. The dropdown's own options come from
        // months_dropdown_by_event_date() below, so the two stay in sync.
        $m = $query->get( 'm' );
        if ( ! empty( $m ) ) {
            $query->set( 'm', '' );

            $digits = preg_replace( '/\D/', '', (string) $m );
            $start  = null;

            if ( 6 === strlen( $digits ) ) {
                $start = substr( $digits, 0, 4 ) . '-' . substr( $digits, 4, 2 ) . '-01';
                $end   = gmdate( 'Y-m-d', strtotime( $start . ' +1 month' ) );
            } elseif ( 4 === strlen( $digits ) ) {
                $start = $digits . '-01-01';
                $end   = ( (int) $digits + 1 ) . '-01-01';
            }

            if ( $start ) {
                $clauses[] = array(
                    'key'     => '_blt_event_date',
                    'value'   => array( $start, $end ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                );
            }
        }

        if ( empty( $clauses ) ) {
            return;
        }

        $existing = (array) $query->get( 'meta_query' );

        if ( ! empty( $existing ) ) {
            $meta_query = array_merge(
                array(
                    'relation' => 'AND',
                    $existing,
                ),
                $clauses
            );
        } elseif ( count( $clauses ) > 1 ) {
            $meta_query = array_merge( array( 'relation' => 'AND' ), $clauses );
        } else {
            $meta_query = $clauses;
        }

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Populate the "All dates" dropdown from event start dates instead of
     * post publish dates.
     *
     * @param array  $months    Result rows with year/month columns.
     * @param string $post_type The post type being listed.
     * @return array
     */
    public static function months_dropdown_by_event_date( $months, $post_type ) {
        if ( self::$slug !== $post_type ) {
            return $months;
        }

        global $wpdb;

        // Same as WP core's own months_dropdown_results default (a direct,
        // uncached aggregate query) — there's no WP API for "distinct
        // year/month across a meta value", and this only runs on the
        // admin list screen.
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT DISTINCT YEAR( pm.meta_value ) AS year, MONTH( pm.meta_value ) AS month
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_blt_event_date'
               AND pm.meta_value != ''
               AND p.post_type = %s
               AND p.post_status NOT IN ( 'trash', 'auto-draft' )
             ORDER BY pm.meta_value DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $post_type
        ) );
    }
}
