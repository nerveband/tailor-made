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
                'name'               => 'TT Events',
                'singular_name'      => 'TT Event',
                'menu_name'          => 'TT Events',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Event',
                'edit_item'          => 'Edit Event',
                'view_item'          => 'View Event',
                'all_items'          => 'All Events',
                'search_items'       => 'Search Events',
                'not_found'          => 'No events found.',
                'not_found_in_trash' => 'No events found in Trash.',
            ),
            'public'              => true,
            'has_archive'         => true,
            'show_in_rest'        => true,
            'show_in_menu'        => false,
            'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
            'rewrite'             => array( 'slug' => 'events' ),
            'menu_icon'           => 'dashicons-tickets-alt',
            'capability_type'     => 'post',
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
        ) );
    }

    /**
     * @return array
     */
    public static function meta_keys() {
        return array(
            '_tt_event_id',
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
