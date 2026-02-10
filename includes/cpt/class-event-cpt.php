<?php
/**
 * CMT Events - Event Custom Post Type
 *
 * Registers the "event" custom post type and the "event_category" taxonomy.
 * Meta boxes are handled separately in the event metabox class.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMT_Events_Event_CPT {

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
    }

    /**
     * Register the event custom post type.
     */
    public static function register_post_type() {
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
            'rewrite'            => array( 'slug' => self::$slug ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        );

        register_post_type( self::$slug, $args );
    }

    /**
     * Register the event_category hierarchical taxonomy on the event post type.
     */
    public static function register_taxonomies() {
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
            'rewrite'           => array( 'slug' => 'event-category' ),
        );

        register_taxonomy( 'event_category', array( self::$slug ), $args );
    }
}
