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
}
