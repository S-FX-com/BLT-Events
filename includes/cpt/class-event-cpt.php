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
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );

        // Admin list table columns.
        add_filter( 'manage_' . self::$slug . '_posts_columns', array( __CLASS__, 'set_admin_columns' ) );
        add_action( 'manage_' . self::$slug . '_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
        add_filter( 'manage_edit-' . self::$slug . '_sortable_columns', array( __CLASS__, 'sortable_admin_columns' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'handle_admin_column_sorting' ) );
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

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => self::$slug ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
            // Expose to the block editor, core REST API, and Query Loop blocks.
            'show_in_rest'       => true,
        );

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

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'event-category' ),
            'show_in_rest'      => true,
        );

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
        $new['blt_attendees']  = __( 'Attendees', 'blt-events' );
        $new['blt_event_date'] = __( 'Start Date', 'blt-events' );

        return $new;
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
     * Sort by event start date when the Start Date column header is clicked.
     * Events without a date sort together at the end via NOT EXISTS.
     */
    public static function handle_admin_column_sorting( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( $query->get( 'post_type' ) !== self::$slug || $query->get( 'orderby' ) !== 'blt_event_date' ) {
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
}
