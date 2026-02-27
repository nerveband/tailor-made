<?php
/**
 * Register tt_event Custom Post Type.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_CPT {

    const POST_TYPE = 'tt_event';

    public static function register() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'               => __( 'TT Events', 'tailor-made' ),
                'singular_name'      => __( 'TT Event', 'tailor-made' ),
                'menu_name'          => __( 'TT Events', 'tailor-made' ),
                'add_new'            => __( 'Add New', 'tailor-made' ),
                'add_new_item'       => __( 'Add New Event', 'tailor-made' ),
                'edit_item'          => __( 'Edit Event', 'tailor-made' ),
                'view_item'          => __( 'View Event', 'tailor-made' ),
                'all_items'          => __( 'All Events', 'tailor-made' ),
                'search_items'       => __( 'Search Events', 'tailor-made' ),
                'not_found'          => __( 'No events found.', 'tailor-made' ),
                'not_found_in_trash' => __( 'No events found in Trash.', 'tailor-made' ),
            ),
            'public'              => true,
            'has_archive'         => false,
            'show_in_rest'        => true,
            'show_in_menu'        => false,
            'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
            'rewrite'             => array( 'slug' => 'events' ),
            'menu_icon'           => 'dashicons-tickets-alt',
            'capability_type'     => 'post',
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
        ) );

        // Restrict sensitive meta fields from REST API exposure
        $sensitive_fields = array(
            '_tt_revenue',
            '_tt_total_orders',
            '_tt_total_issued_tickets',
            '_tt_raw_json',
            '_tt_ticket_types',
        );
        foreach ( $sensitive_fields as $meta_key ) {
            register_post_meta( self::POST_TYPE, $meta_key, array(
                'show_in_rest' => false,
                'single'       => true,
                'type'         => 'string',
            ) );
        }
    }

    /**
     * Register the tt_box_office taxonomy.
     */
    public static function register_taxonomy() {
        register_taxonomy( 'tt_box_office', self::POST_TYPE, array(
            'labels' => array(
                'name'          => __( 'Box Offices', 'tailor-made' ),
                'singular_name' => __( 'Box Office', 'tailor-made' ),
                'menu_name'     => __( 'Box Offices', 'tailor-made' ),
            ),
            'public'            => true,
            'hierarchical'      => false,
            'show_ui'           => false,
            'show_in_menu'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => array( 'slug' => 'box-office' ),
        ) );
    }

    /**
     * @return array
     */
    public static function meta_keys() {
        return array(
            '_tt_event_id',
            '_tt_box_office_id',
            '_tt_event_series_id',
            '_tt_status',
            '_tt_currency',
            '_tt_start_date',
            '_tt_start_time',
            '_tt_start_formatted',
            '_tt_start_iso',
            '_tt_start_unix',
            '_tt_end_date',
            '_tt_end_time',
            '_tt_end_formatted',
            '_tt_end_iso',
            '_tt_end_unix',
            '_tt_timezone',
            '_tt_venue_name',
            '_tt_venue_country',
            '_tt_venue_postal_code',
            '_tt_image_header',
            '_tt_image_thumbnail',
            '_tt_checkout_url',
            '_tt_event_url',
            '_tt_call_to_action',
            '_tt_online_event',
            '_tt_private',
            '_tt_hidden',
            '_tt_tickets_available',
            '_tt_revenue',
            '_tt_total_orders',
            '_tt_total_issued_tickets',
            '_tt_ticket_types',
            '_tt_min_price',
            '_tt_max_price',
            '_tt_total_capacity',
            '_tt_tickets_remaining',
            '_tt_last_synced',
            '_tt_raw_json',
        );
    }
}
