<?php
/**
 * Tailor Made — Uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted via the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Always clean up: options, DB table, cron hooks.
$options_to_delete = array(
    'tailor_made_api_key',
    'tailor_made_sync_interval',
    'tailor_made_logging_enabled',
    'tailor_made_log_retention_days',
    'tailor_made_last_sync',
    'tailor_made_last_sync_result',
);

foreach ( $options_to_delete as $option ) {
    delete_option( $option );
}

// Drop the sync log table.
global $wpdb;
$table_name = $wpdb->prefix . 'tailor_made_sync_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'tailor_made_sync_cron' );
wp_clear_scheduled_hook( 'tailor_made_log_cleanup_cron' );

// Conditionally delete all tt_event posts and their meta.
$delete_events = get_option( 'tailor_made_delete_events_on_uninstall', 0 );

if ( $delete_events ) {
    $posts = get_posts( array(
        'post_type'   => 'tt_event',
        'numberposts' => -1,
        'post_status' => 'any',
        'fields'      => 'ids',
    ) );

    foreach ( $posts as $post_id ) {
        wp_delete_post( $post_id, true );
    }

    // Delete the roster page if events are being cleaned up.
    $roster_page_id = get_option( 'tailor_made_roster_page_id' );
    if ( $roster_page_id ) {
        wp_delete_post( $roster_page_id, true );
    }
}

// Clean up magic links option.
delete_option( 'tailor_made_roster_page_id' );

// Clean up the uninstall option itself last.
delete_option( 'tailor_made_delete_events_on_uninstall' );
