<?php
/**
 * Plugin Name: Tailor Made
 * Plugin URI: https://github.com/wavedepth/tailor-made
 * Description: Unofficial Ticket Tailor full API integration for WordPress. Syncs events, ticket types, and more into WordPress for use with Bricks Builder dynamic data.
 * Version: 1.0.0
 * Author: wavedepth
 * Author URI: https://wavedepth.com
 * License: GPL-2.0-or-later
 * Text Domain: tailor-made
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TAILOR_MADE_VERSION', '1.0.0' );
define( 'TAILOR_MADE_FILE', __FILE__ );
define( 'TAILOR_MADE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAILOR_MADE_URL', plugin_dir_url( __FILE__ ) );

// Core classes
require_once TAILOR_MADE_DIR . 'includes/class-api-client.php';
require_once TAILOR_MADE_DIR . 'includes/class-cpt.php';
require_once TAILOR_MADE_DIR . 'includes/class-sync-engine.php';
require_once TAILOR_MADE_DIR . 'includes/class-admin.php';
require_once TAILOR_MADE_DIR . 'includes/class-bricks-provider.php';
require_once TAILOR_MADE_DIR . 'includes/class-github-updater.php';

/**
 * Initialize plugin.
 */
function tailor_made_init() {
    Tailor_Made_CPT::register();
    Tailor_Made_Admin::init();
    Tailor_Made_Bricks_Provider::init();
    Tailor_Made_GitHub_Updater::init();
}
add_action( 'init', 'tailor_made_init' );

/**
 * Schedule cron on activation.
 */
function tailor_made_activate() {
    Tailor_Made_CPT::register();
    flush_rewrite_rules();

    if ( ! wp_next_scheduled( 'tailor_made_sync_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'tailor_made_sync_cron' );
    }
}
register_activation_hook( __FILE__, 'tailor_made_activate' );

/**
 * Clear cron on deactivation.
 */
function tailor_made_deactivate() {
    wp_clear_scheduled_hook( 'tailor_made_sync_cron' );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tailor_made_deactivate' );

/**
 * Cron handler.
 */
add_action( 'tailor_made_sync_cron', function () {
    $engine = new Tailor_Made_Sync_Engine();
    $engine->sync_all();
} );
