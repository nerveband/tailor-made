<?php
/**
 * Plugin Name: Tailor Made
 * Plugin URI: https://github.com/wavedepth/tailor-made
 * Description: Unofficial Ticket Tailor full API integration for WordPress. Syncs events, ticket types, and more into WordPress for use with Bricks Builder dynamic data.
 * Version: 2.0.0
 * Author: wavedepth
 * Author URI: https://wavedepth.com
 * License: GPL-2.0-or-later
 * Text Domain: tailor-made
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TAILOR_MADE_VERSION', '2.0.0' );
define( 'TAILOR_MADE_FILE', __FILE__ );
define( 'TAILOR_MADE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAILOR_MADE_URL', plugin_dir_url( __FILE__ ) );

// Core classes
require_once TAILOR_MADE_DIR . 'includes/class-api-client.php';
require_once TAILOR_MADE_DIR . 'includes/class-cpt.php';
require_once TAILOR_MADE_DIR . 'includes/class-sync-logger.php';
require_once TAILOR_MADE_DIR . 'includes/class-sync-engine.php';
require_once TAILOR_MADE_DIR . 'includes/class-admin.php';
require_once TAILOR_MADE_DIR . 'includes/class-bricks-provider.php';
require_once TAILOR_MADE_DIR . 'includes/class-shortcodes.php';
require_once TAILOR_MADE_DIR . 'includes/class-github-updater.php';
require_once TAILOR_MADE_DIR . 'includes/class-magic-links.php';
require_once TAILOR_MADE_DIR . 'includes/class-box-office-manager.php';

/**
 * Initialize plugin.
 */
function tailor_made_init() {
    Tailor_Made_CPT::register();
    Tailor_Made_CPT::register_taxonomy();
    Tailor_Made_Admin::init();
    Tailor_Made_Bricks_Provider::init();
    Tailor_Made_Shortcodes::init();
    Tailor_Made_Shortcodes::register_assets();
    Tailor_Made_GitHub_Updater::init();
    Tailor_Made_Magic_Links::init();
}
add_action( 'init', 'tailor_made_init' );

/**
 * Schedule cron on activation.
 */
function tailor_made_activate() {
    Tailor_Made_CPT::register();
    Tailor_Made_CPT::register_taxonomy();
    flush_rewrite_rules();

    // Create tables
    Tailor_Made_Sync_Logger::create_table();
    Tailor_Made_Box_Office_Manager::create_table();

    if ( ! wp_next_scheduled( 'tailor_made_sync_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'tailor_made_sync_cron' );
    }

    if ( ! wp_next_scheduled( 'tailor_made_log_cleanup_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'tailor_made_log_cleanup_cron' );
    }

    Tailor_Made_Magic_Links::maybe_create_roster_page();

    // Migrate single API key to box offices table
    tailor_made_maybe_migrate_single_key();
}
register_activation_hook( __FILE__, 'tailor_made_activate' );

/**
 * Clear cron on deactivation.
 */
function tailor_made_deactivate() {
    wp_clear_scheduled_hook( 'tailor_made_sync_cron' );
    wp_clear_scheduled_hook( 'tailor_made_log_cleanup_cron' );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tailor_made_deactivate' );

/**
 * Cron handler: sync events.
 */
add_action( 'tailor_made_sync_cron', function () {
    $engine = new Tailor_Made_Sync_Engine();
    $engine->sync_all_box_offices();
} );

/**
 * Migrate legacy single-API-key setup to multi-box-office.
 */
function tailor_made_maybe_migrate_single_key() {
    $old_key = get_option( 'tailor_made_api_key', '' );
    if ( empty( $old_key ) ) {
        return;
    }

    // Check if already migrated
    $existing = Tailor_Made_Box_Office_Manager::get_all();
    if ( ! empty( $existing ) ) {
        delete_option( 'tailor_made_api_key' );
        return;
    }

    // Ping the API to get box office name and currency
    $client   = new Tailor_Made_API_Client( $old_key );
    $overview = $client->overview();

    $name     = 'Default Box Office';
    $currency = 'usd';

    if ( ! is_wp_error( $overview ) ) {
        $name     = isset( $overview['box_office_name'] ) ? $overview['box_office_name'] : $name;
        $currency = isset( $overview['currency']['code'] ) ? $overview['currency']['code'] : $currency;
    }

    $box_office_id = Tailor_Made_Box_Office_Manager::add( $name, $old_key, $currency );

    if ( $box_office_id ) {
        $box_office = Tailor_Made_Box_Office_Manager::get( $box_office_id );

        $posts = get_posts( array(
            'post_type'   => 'tt_event',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            update_post_meta( $post_id, '_tt_box_office_id', $box_office_id );
            if ( $box_office ) {
                wp_set_object_terms( $post_id, $box_office->slug, 'tt_box_office' );
            }
        }

        delete_option( 'tailor_made_api_key' );
    }
}

/**
 * Cron handler: purge old log entries.
 */
add_action( 'tailor_made_log_cleanup_cron', function () {
    Tailor_Made_Sync_Logger::purge_old_entries();
} );
