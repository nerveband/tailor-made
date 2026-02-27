<?php
/**
 * Admin settings page, sync controls, and dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Admin {

    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'wp_ajax_tailor_made_sync', [ __CLASS__, 'ajax_sync' ] );
        add_action( 'wp_ajax_tailor_made_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_tailor_made_compare_events', [ __CLASS__, 'ajax_compare_events' ] );
        add_action( 'wp_ajax_tailor_made_clear_logs', [ __CLASS__, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_tailor_made_save_log_settings', [ __CLASS__, 'ajax_save_log_settings' ] );
        add_action( 'wp_ajax_tailor_made_generate_roster_link', [ __CLASS__, 'ajax_generate_roster_link' ] );
        add_action( 'wp_ajax_tailor_made_rotate_roster_link', [ __CLASS__, 'ajax_rotate_roster_link' ] );
        add_action( 'wp_ajax_tailor_made_revoke_roster_link', [ __CLASS__, 'ajax_revoke_roster_link' ] );

        // Box Office management AJAX.
        add_action( 'wp_ajax_tailor_made_add_box_office', [ __CLASS__, 'ajax_add_box_office' ] );
        add_action( 'wp_ajax_tailor_made_edit_box_office', [ __CLASS__, 'ajax_edit_box_office' ] );
        add_action( 'wp_ajax_tailor_made_delete_box_office', [ __CLASS__, 'ajax_delete_box_office' ] );
        add_action( 'wp_ajax_tailor_made_test_box_office', [ __CLASS__, 'ajax_test_box_office' ] );
        add_action( 'wp_ajax_tailor_made_toggle_box_office', [ __CLASS__, 'ajax_toggle_box_office' ] );
    }

    /**
     * Add admin menu.
     */
    public static function add_menu(): void {
        add_menu_page(
            'Tailor Made',
            'Tailor Made',
            'manage_options',
            'tailor-made',
            [ __CLASS__, 'render_page' ],
            'dashicons-tickets-alt',
            30
        );

        add_submenu_page(
            'tailor-made',
            'TT Events',
            'Events',
            'manage_options',
            'edit.php?post_type=tt_event'
        );
    }

    /**
     * Register settings.
     */
    public static function register_settings(): void {
        register_setting( 'tailor_made_settings', 'tailor_made_sync_interval', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ] );

        register_setting( 'tailor_made_settings', 'tailor_made_delete_events_on_uninstall', [
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );
    }

    /**
     * AJAX: Run sync now.
     */
    public static function ajax_sync(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $result = Tailor_Made_Sync_Engine::sync_all_box_offices();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: Test API connection.
     */
    public static function ajax_test_connection(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box_offices = Tailor_Made_Box_Office_Manager::get_all( 'active' );
        if ( empty( $box_offices ) ) {
            wp_send_json_error( 'No box offices configured.' );
        }

        $bo     = $box_offices[0];
        $client = new Tailor_Made_API_Client( $bo->api_key );
        $overview = $client->overview();

        if ( is_wp_error( $overview ) ) {
            wp_send_json_error( $overview->get_error_message() );
        }

        wp_send_json_success( array( 'ping' => true, 'overview' => $overview ) );
    }

    /**
     * AJAX: Compare TT events with WP posts.
     */
    public static function ajax_compare_events(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Collect TT events from all active box offices.
        $box_offices = Tailor_Made_Box_Office_Manager::get_all( 'active' );
        $all_tt_events = array();
        $tt_total = 0;

        if ( ! empty( $box_offices ) ) {
            foreach ( $box_offices as $bo ) {
                $client    = new Tailor_Made_API_Client( $bo->api_key );
                $tt_events = $client->get_events();

                if ( is_wp_error( $tt_events ) ) {
                    continue;
                }

                foreach ( $tt_events as $event ) {
                    $event['_bo_name'] = $bo->name;
                    $event['_bo_id']   = $bo->id;
                    $all_tt_events[]   = $event;
                }
                $tt_total += count( $tt_events );
            }
        } else {
            // Legacy fallback: single API key.
            $client    = new Tailor_Made_API_Client();
            $tt_events = $client->get_events();

            if ( is_wp_error( $tt_events ) ) {
                wp_send_json_error( 'API error: ' . $tt_events->get_error_message() );
            }

            foreach ( $tt_events as $event ) {
                $event['_bo_name'] = '';
                $all_tt_events[]   = $event;
            }
            $tt_total = count( $tt_events );
        }

        // Get all WP posts.
        $wp_posts = get_posts( array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => -1,
            'post_status' => 'any',
        ) );

        // Index WP posts by TT event ID.
        $wp_by_tt_id = array();
        foreach ( $wp_posts as $post ) {
            $tt_id = get_post_meta( $post->ID, '_tt_event_id', true );
            if ( $tt_id ) {
                $wp_by_tt_id[ $tt_id ] = $post;
            }
        }

        // Index TT events by ID.
        $tt_by_id = array();
        foreach ( $all_tt_events as $event ) {
            if ( isset( $event['id'] ) ) {
                $tt_by_id[ $event['id'] ] = $event;
            }
        }

        $rows = array();

        // TT events: check if synced in WP.
        foreach ( $all_tt_events as $event ) {
            $tt_id   = isset( $event['id'] ) ? $event['id'] : '';
            $name    = isset( $event['name'] ) ? $event['name'] : 'Untitled';
            $status  = isset( $event['status'] ) ? $event['status'] : 'unknown';
            $bo_name = isset( $event['_bo_name'] ) ? $event['_bo_name'] : '';

            if ( isset( $wp_by_tt_id[ $tt_id ] ) ) {
                $post        = $wp_by_tt_id[ $tt_id ];
                $last_synced = get_post_meta( $post->ID, '_tt_last_synced', true );
                $rows[] = array(
                    'name'        => $name,
                    'box_office'  => $bo_name,
                    'tt_status'   => $status,
                    'wp_status'   => $post->post_status,
                    'sync_status' => 'synced',
                    'last_synced' => $last_synced ? $last_synced : 'Unknown',
                );
            } else {
                $rows[] = array(
                    'name'        => $name,
                    'box_office'  => $bo_name,
                    'tt_status'   => $status,
                    'wp_status'   => "\xe2\x80\x94",
                    'sync_status' => 'pending',
                    'last_synced' => "\xe2\x80\x94",
                );
            }
        }

        // WP posts not in TT (orphans).
        foreach ( $wp_posts as $post ) {
            $tt_id = get_post_meta( $post->ID, '_tt_event_id', true );
            if ( $tt_id && ! isset( $tt_by_id[ $tt_id ] ) ) {
                $last_synced = get_post_meta( $post->ID, '_tt_last_synced', true );
                $rows[] = array(
                    'name'        => $post->post_title,
                    'box_office'  => '',
                    'tt_status'   => "\xe2\x80\x94",
                    'wp_status'   => $post->post_status,
                    'sync_status' => 'orphaned',
                    'last_synced' => $last_synced ? $last_synced : 'Unknown',
                );
            }
        }

        wp_send_json_success( array(
            'rows'     => $rows,
            'tt_count' => $tt_total,
            'wp_count' => count( $wp_posts ),
        ) );
    }

    /* ------------------------------------------------------------------
     * Box Office AJAX handlers
     * ----------------------------------------------------------------*/

    /**
     * AJAX: Add a new box office by testing its API key first.
     */
    public static function ajax_add_box_office(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API key is required.' );
        }

        // Test the API key by calling overview.
        $client   = new Tailor_Made_API_Client( $api_key );
        $overview = $client->overview();

        if ( is_wp_error( $overview ) ) {
            wp_send_json_error( 'API key test failed: ' . $overview->get_error_message() );
        }

        $name     = isset( $overview['box_office_name'] ) ? $overview['box_office_name'] : 'Box Office';
        $currency = 'usd';
        if ( isset( $overview['currency'] ) && isset( $overview['currency']['code'] ) ) {
            $currency = $overview['currency']['code'];
        }

        $insert_id = Tailor_Made_Box_Office_Manager::add( $name, $api_key, $currency );

        if ( false === $insert_id ) {
            wp_send_json_error( 'Failed to save box office. Check database.' );
        }

        wp_send_json_success( array(
            'id'       => $insert_id,
            'name'     => $name,
            'currency' => strtoupper( $currency ),
            'message'  => 'Box office "' . $name . '" added successfully.',
        ) );
    }

    /**
     * AJAX: Edit an existing box office (name and/or API key).
     */
    public static function ajax_edit_box_office(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box_office_id = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
        if ( ! $box_office_id ) {
            wp_send_json_error( 'Invalid box office ID.' );
        }

        $bo = Tailor_Made_Box_Office_Manager::get( $box_office_id );
        if ( ! $bo ) {
            wp_send_json_error( 'Box office not found.' );
        }

        $update_data = array();

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( ! empty( $name ) ) {
            $update_data['name'] = $name;
        }

        $new_api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( ! empty( $new_api_key ) ) {
            // Test the new key first.
            $client   = new Tailor_Made_API_Client( $new_api_key );
            $overview = $client->overview();

            if ( is_wp_error( $overview ) ) {
                wp_send_json_error( 'New API key test failed: ' . $overview->get_error_message() );
            }

            $update_data['api_key'] = $new_api_key;

            // Update currency from the new key's overview.
            if ( isset( $overview['currency'] ) && isset( $overview['currency']['code'] ) ) {
                $update_data['currency'] = $overview['currency']['code'];
            }
        }

        if ( empty( $update_data ) ) {
            wp_send_json_error( 'No changes provided.' );
        }

        $updated = Tailor_Made_Box_Office_Manager::update( $box_office_id, $update_data );

        if ( ! $updated ) {
            wp_send_json_error( 'Failed to update box office.' );
        }

        wp_send_json_success( array( 'message' => 'Box office updated.' ) );
    }

    /**
     * AJAX: Delete a box office.
     */
    public static function ajax_delete_box_office(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box_office_id = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
        if ( ! $box_office_id ) {
            wp_send_json_error( 'Invalid box office ID.' );
        }

        $delete_events = isset( $_POST['delete_events'] ) && '1' === $_POST['delete_events'];

        $deleted = Tailor_Made_Box_Office_Manager::delete( $box_office_id, $delete_events );

        if ( ! $deleted ) {
            wp_send_json_error( 'Failed to delete box office.' );
        }

        wp_send_json_success( array( 'message' => 'Box office deleted.' ) );
    }

    /**
     * AJAX: Test a specific box office's API connection.
     */
    public static function ajax_test_box_office(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box_office_id = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
        if ( ! $box_office_id ) {
            wp_send_json_error( 'Invalid box office ID.' );
        }

        $bo = Tailor_Made_Box_Office_Manager::get( $box_office_id );
        if ( ! $bo ) {
            wp_send_json_error( 'Box office not found.' );
        }

        $client   = new Tailor_Made_API_Client( $bo->api_key );
        $overview = $client->overview();

        if ( is_wp_error( $overview ) ) {
            wp_send_json_error( 'Connection failed: ' . $overview->get_error_message() );
        }

        wp_send_json_success( array(
            'message'  => 'Connected!',
            'overview' => $overview,
        ) );
    }

    /**
     * AJAX: Toggle a box office between active and paused.
     */
    public static function ajax_toggle_box_office(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box_office_id = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
        if ( ! $box_office_id ) {
            wp_send_json_error( 'Invalid box office ID.' );
        }

        $bo = Tailor_Made_Box_Office_Manager::get( $box_office_id );
        if ( ! $bo ) {
            wp_send_json_error( 'Box office not found.' );
        }

        $new_status = ( 'active' === $bo->status ) ? 'paused' : 'active';

        $updated = Tailor_Made_Box_Office_Manager::update( $box_office_id, array( 'status' => $new_status ) );

        if ( ! $updated ) {
            wp_send_json_error( 'Failed to toggle status.' );
        }

        wp_send_json_success( array(
            'status'  => $new_status,
            'message' => 'Box office is now ' . $new_status . '.',
        ) );
    }

    /**
     * AJAX: Clear all logs.
     */
    public static function ajax_clear_logs(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        Tailor_Made_Sync_Logger::clear_all();
        wp_send_json_success( 'Logs cleared.' );
    }

    /**
     * AJAX: Save log settings.
     */
    public static function ajax_save_log_settings(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $enabled   = isset( $_POST['logging_enabled'] ) && $_POST['logging_enabled'] === '1';
        $retention = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30;
        if ( $retention < 1 ) {
            $retention = 30;
        }

        update_option( 'tailor_made_logging_enabled', $enabled );
        update_option( 'tailor_made_log_retention_days', $retention );

        wp_send_json_success( 'Settings saved.' );
    }

    /**
     * Render the admin page with tabs.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tailor-made' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $valid_tabs = array( 'dashboard', 'how-to-use', 'how-sync-works', 'shortcodes', 'magic-links', 'sync-log', 'about' );
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'dashboard';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Tailor Made — Ticket Tailor Integration', 'tailor-made' ); ?></h1>

            <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=tailor-made&tab=dashboard"
                   class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Dashboard', 'tailor-made' ); ?>
                </a>
                <a href="?page=tailor-made&tab=how-to-use"
                   class="nav-tab <?php echo $active_tab === 'how-to-use' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'How To Use', 'tailor-made' ); ?>
                </a>
                <a href="?page=tailor-made&tab=how-sync-works"
                   class="nav-tab <?php echo $active_tab === 'how-sync-works' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'How Sync Works', 'tailor-made' ); ?>
                </a>
                <a href="?page=tailor-made&tab=shortcodes"
                   class="nav-tab <?php echo $active_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Shortcodes', 'tailor-made' ); ?>
                </a>
                <a href="?page=tailor-made&tab=magic-links"
                   class="nav-tab <?php echo $active_tab === 'magic-links' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Magic Links', 'tailor-made' ); ?>
                </a>
                <a href="?page=tailor-made&tab=sync-log"
                   class="nav-tab <?php echo $active_tab === 'sync-log' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Sync Log', 'tailor-made' ); ?>
                </a>
                <a href="?page=tailor-made&tab=about"
                   class="nav-tab <?php echo $active_tab === 'about' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'About', 'tailor-made' ); ?>
                </a>
            </nav>

            <?php
            switch ( $active_tab ) {
                case 'how-to-use':
                    self::render_tab_how_to_use();
                    break;
                case 'how-sync-works':
                    self::render_tab_how_sync_works();
                    break;
                case 'shortcodes':
                    self::render_tab_shortcodes();
                    break;
                case 'magic-links':
                    self::render_tab_magic_links();
                    break;
                case 'sync-log':
                    self::render_tab_sync_log();
                    break;
                case 'about':
                    self::render_tab_about();
                    break;
                default:
                    self::render_tab_dashboard();
                    break;
            }
            ?>
        </div>

        <style>
            .tm-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.4;
                text-transform: uppercase;
            }
            .tm-badge-info { background: #d1ecf1; color: #0c5460; }
            .tm-badge-warning { background: #fff3cd; color: #856404; }
            .tm-badge-error { background: #f8d7da; color: #721c24; }
            .tm-badge-synced { background: #d4edda; color: #155724; }
            .tm-badge-pending { background: #fff3cd; color: #856404; }
            .tm-badge-orphaned { background: #f8d7da; color: #721c24; }
            .tm-info-section { margin-bottom: 30px; }
            .tm-info-section h3 { margin-top: 0; border-bottom: 1px solid #ccd0d4; padding-bottom: 8px; }
            .tm-info-table { border-collapse: collapse; width: 100%; }
            .tm-info-table th, .tm-info-table td { padding: 8px 12px; border: 1px solid #ccd0d4; text-align: left; }
            .tm-info-table th { background: #f1f1f1; font-weight: 600; }
            .tm-info-table tr:nth-child(even) td { background: #f9f9f9; }
            .tm-compare-table { width: 100%; border-collapse: collapse; }
            .tm-compare-table th, .tm-compare-table td { padding: 8px 12px; border: 1px solid #ccd0d4; text-align: left; }
            .tm-compare-table th { background: #f1f1f1; }
            .tm-log-table { width: 100%; border-collapse: collapse; }
            .tm-log-table th, .tm-log-table td { padding: 6px 10px; border: 1px solid #ccd0d4; text-align: left; font-size: 13px; }
            .tm-log-table th { background: #f1f1f1; font-weight: 600; }
            .tm-log-filter { margin-bottom: 10px; padding: 8px 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; }
        </style>
        <?php
    }

    /**
     * Dashboard Tab
     */
    private static function render_tab_dashboard(): void {
        $box_offices = Tailor_Made_Box_Office_Manager::get_all();
        $last_sync   = get_option( 'tailor_made_last_sync', 'Never' );
        $last_result = get_option( 'tailor_made_last_sync_result', array() );
        $event_count = wp_count_posts( Tailor_Made_CPT::POST_TYPE );
        $total       = ( $event_count->publish ?? 0 ) + ( $event_count->draft ?? 0 );
        $next_cron   = wp_next_scheduled( 'tailor_made_sync_cron' );
        $nonce       = wp_create_nonce( 'tailor_made_nonce' );
        ?>

        <!-- Box Offices -->
        <div class="postbox" style="padding: 15px; margin-bottom: 20px;">
            <h2><?php esc_html_e( 'Box Offices', 'tailor-made' ); ?></h2>
            <p><?php esc_html_e( 'Manage your Ticket Tailor box offices. Each box office has its own API key and syncs independently.', 'tailor-made' ); ?></p>

            <?php if ( ! empty( $box_offices ) ) : ?>
            <table class="widefat striped" style="margin-bottom: 15px;" id="tm-bo-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Slug', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'API Key', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Currency', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Events', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Last Sync', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'tailor-made' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $box_offices as $bo ) :
                        $bo_event_count = Tailor_Made_Box_Office_Manager::get_event_count( $bo->id );
                        $masked_key     = Tailor_Made_Box_Office_Manager::mask_api_key( $bo->api_key );
                        $status_class   = ( 'active' === $bo->status ) ? 'tm-badge-synced' : 'tm-badge-warning';
                        $status_label   = ucfirst( $bo->status );
                    ?>
                    <tr id="tm-bo-row-<?php echo esc_attr( $bo->id ); ?>">
                        <td><strong><?php echo esc_html( $bo->name ); ?></strong></td>
                        <td><code><?php echo esc_html( $bo->slug ); ?></code></td>
                        <td><code style="font-size: 11px;"><?php echo esc_html( $masked_key ); ?></code></td>
                        <td><?php echo esc_html( strtoupper( $bo->currency ) ); ?></td>
                        <td><?php echo esc_html( $bo_event_count ); ?></td>
                        <td><?php echo esc_html( $bo->last_sync ? $bo->last_sync : 'Never' ); ?></td>
                        <td><span class="tm-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                        <td>
                            <button type="button" class="button button-small tm-bo-test" data-id="<?php echo esc_attr( $bo->id ); ?>"><?php esc_html_e( 'Test', 'tailor-made' ); ?></button>
                            <button type="button" class="button button-small tm-bo-toggle" data-id="<?php echo esc_attr( $bo->id ); ?>"><?php echo esc_html( 'active' === $bo->status ? 'Pause' : 'Activate' ); ?></button>
                            <button type="button" class="button button-small tm-bo-remove" style="color:#a00;" data-id="<?php echo esc_attr( $bo->id ); ?>" data-name="<?php echo esc_attr( $bo->name ); ?>"><?php esc_html_e( 'Remove', 'tailor-made' ); ?></button>
                            <span class="tm-bo-action-result" id="tm-bo-result-<?php echo esc_attr( $bo->id ); ?>" style="margin-left: 5px;"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p style="color: #666;"><em><?php esc_html_e( 'No box offices configured yet. Add one below to get started.', 'tailor-made' ); ?></em></p>
            <?php endif; ?>

            <!-- Add Box Office Form -->
            <div style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Add Box Office', 'tailor-made' ); ?></h3>
                <p class="description" style="margin-bottom: 10px;"><?php esc_html_e( 'Paste a Ticket Tailor API key (starts with sk_). The box office name and currency will be auto-detected.', 'tailor-made' ); ?></p>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <input type="password" id="tm-bo-add-key" class="regular-text" placeholder="sk_..." style="max-width: 400px;" />
                    <button type="button" class="button" onclick="
                        var el = document.getElementById('tm-bo-add-key');
                        el.type = el.type === 'password' ? 'text' : 'password';
                    "><?php esc_html_e( 'Show/Hide', 'tailor-made' ); ?></button>
                    <button type="button" class="button button-primary" id="tm-bo-add-btn"><?php esc_html_e( 'Test & Add', 'tailor-made' ); ?></button>
                    <span id="tm-bo-add-result"></span>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

            <!-- Sync Status -->
            <div class="postbox" style="padding: 15px;">
                <h2><?php esc_html_e( 'Sync Status', 'tailor-made' ); ?></h2>
                <table class="widefat" style="margin-bottom: 15px;">
                    <tr>
                        <td><strong><?php esc_html_e( 'Events in WordPress', 'tailor-made' ); ?></strong></td>
                        <td><?php echo esc_html( $total ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Last Sync', 'tailor-made' ); ?></strong></td>
                        <td><?php echo esc_html( $last_sync ); ?></td>
                    </tr>
                    <?php if ( ! empty( $last_result ) ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Last Result', 'tailor-made' ); ?></strong></td>
                        <td>
                            Created: <?php echo intval( $last_result['created'] ?? 0 ); ?>,
                            Updated: <?php echo intval( $last_result['updated'] ?? 0 ); ?>,
                            Deleted: <?php echo intval( $last_result['deleted'] ?? 0 ); ?>
                            <?php if ( ! empty( $last_result['errors'] ) ) : ?>
                                <br><span style="color:red;">Errors: <?php echo esc_html( implode( ', ', $last_result['errors'] ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $last_result['box_offices'] ) ) : ?>
                                <br><small style="color: #666;">
                                <?php
                                $parts = array();
                                foreach ( $last_result['box_offices'] as $slug => $bo_result ) {
                                    $parts[] = esc_html( $bo_result['name'] ) . ': +' . intval( $bo_result['created'] ) . ' /' . intval( $bo_result['updated'] ) . 'u /-' . intval( $bo_result['deleted'] );
                                }
                                echo implode( ' &nbsp;|&nbsp; ', $parts );
                                ?>
                                </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Next Auto-Sync', 'tailor-made' ); ?></strong></td>
                        <td>
                            <?php
                            if ( $next_cron ) {
                                echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'M j, Y g:i A' ) );
                            } else {
                                esc_html_e( 'Not scheduled', 'tailor-made' );
                            }
                            ?>
                        </td>
                    </tr>
                </table>

                <button type="button" class="button button-primary" id="tm-sync-btn"><?php esc_html_e( 'Sync All', 'tailor-made' ); ?></button>
                <span id="tm-sync-result" style="margin-left: 10px;"></span>
            </div>

            <!-- Settings -->
            <div class="postbox" style="padding: 15px;">
                <h2><?php esc_html_e( 'Settings', 'tailor-made' ); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'tailor_made_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Uninstall', 'tailor-made' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="tailor_made_delete_events_on_uninstall" value="1"
                                        <?php checked( get_option( 'tailor_made_delete_events_on_uninstall', 0 ) ); ?> />
                                    <?php esc_html_e( 'Delete all synced events when plugin is removed', 'tailor-made' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'When checked, deleting this plugin will also remove all TT Event posts and their metadata.', 'tailor-made' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'tailor-made' ) ); ?>
                </form>
            </div>

        </div>

        <!-- Event Comparison -->
        <div class="postbox" style="padding: 15px; margin-top: 20px;">
            <h2><?php esc_html_e( 'Event Comparison', 'tailor-made' ); ?></h2>
            <p><?php esc_html_e( 'Compare events in Ticket Tailor with WordPress to see sync status at a glance.', 'tailor-made' ); ?></p>
            <button type="button" class="button" id="tm-compare-btn"><?php esc_html_e( 'Load Comparison', 'tailor-made' ); ?></button>
            <span id="tm-compare-status" style="margin-left: 10px;"></span>

            <div id="tm-compare-summary" style="margin-top: 15px; display: none;">
                <p><strong>Ticket Tailor:</strong> <span id="tm-tt-count">0</span> events &nbsp;|&nbsp;
                   <strong>WordPress:</strong> <span id="tm-wp-count">0</span> events</p>
            </div>

            <div id="tm-compare-table-wrap" style="margin-top: 10px; display: none;">
                <table class="tm-compare-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Event Name', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Box Office', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'TT Status', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'WP Status', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Sync Status', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Last Synced', 'tailor-made' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="tm-compare-body"></tbody>
                </table>
            </div>
        </div>

        <script>
        (function() {
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function escHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
            }

            function ajaxPost(action, params, callback) {
                var body = 'action=' + action + '&nonce=' + nonce;
                if (params) {
                    Object.keys(params).forEach(function(k) {
                        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
                    });
                }
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                })
                .then(function(r) { return r.json(); })
                .then(callback)
                .catch(function(err) {
                    callback({ success: false, data: err.message || 'Network error' });
                });
            }

            /* --- Add Box Office --- */
            document.getElementById('tm-bo-add-btn').addEventListener('click', function() {
                var btn    = this;
                var input  = document.getElementById('tm-bo-add-key');
                var result = document.getElementById('tm-bo-add-result');
                var apiKey = input.value.trim();

                if (!apiKey) {
                    result.innerHTML = '<span style="color:red;">Please enter an API key.</span>';
                    return;
                }

                btn.disabled = true;
                result.textContent = 'Testing & adding...';

                ajaxPost('tailor_made_add_box_office', { api_key: apiKey }, function(data) {
                    btn.disabled = false;
                    if (data.success) {
                        result.innerHTML = '<span style="color:green;">&#10003; ' + escHtml(data.data.message) + '</span>';
                        input.value = '';
                        setTimeout(function() { location.reload(); }, 1200);
                    } else {
                        result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                    }
                });
            });

            /* --- Per-Box-Office: Test --- */
            document.querySelectorAll('.tm-bo-test').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-id');
                    var result = document.getElementById('tm-bo-result-' + id);
                    this.disabled = true;
                    result.textContent = 'Testing...';

                    ajaxPost('tailor_made_test_box_office', { box_office_id: id }, function(data) {
                        btn.disabled = false;
                        if (data.success) {
                            var ov = data.data.overview;
                            var info = ov ? escHtml(ov.box_office_name) + ' (' + escHtml(ov.currency.code.toUpperCase()) + ')' : '';
                            result.innerHTML = '<span style="color:green;">&#10003; ' + info + '</span>';
                        } else {
                            result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                        }
                    });
                });
            });

            /* --- Per-Box-Office: Toggle --- */
            document.querySelectorAll('.tm-bo-toggle').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-id');
                    var result = document.getElementById('tm-bo-result-' + id);
                    this.disabled = true;
                    result.textContent = 'Toggling...';

                    ajaxPost('tailor_made_toggle_box_office', { box_office_id: id }, function(data) {
                        btn.disabled = false;
                        if (data.success) {
                            result.innerHTML = '<span style="color:green;">&#10003; ' + escHtml(data.data.message) + '</span>';
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                        }
                    });
                });
            });

            /* --- Per-Box-Office: Remove --- */
            document.querySelectorAll('.tm-bo-remove').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-id');
                    var name   = this.getAttribute('data-name');
                    var result = document.getElementById('tm-bo-result-' + id);

                    var deleteEvents = confirm('Remove box office "' + name + '"?\n\nClick OK to remove.\n\nNote: associated events will be unassigned but kept. To also delete all events for this box office, click Cancel and use the alternative removal option.');
                    if (!deleteEvents) {
                        // Ask if they want to delete events too.
                        if (!confirm('Would you like to DELETE "' + name + '" AND all its synced events?\n\nThis cannot be undone.')) {
                            return;
                        }
                        deleteEvents = true;
                    } else {
                        deleteEvents = false;
                    }

                    this.disabled = true;
                    result.textContent = 'Removing...';

                    ajaxPost('tailor_made_delete_box_office', {
                        box_office_id: id,
                        delete_events: deleteEvents ? '1' : '0'
                    }, function(data) {
                        btn.disabled = false;
                        if (data.success) {
                            result.innerHTML = '<span style="color:green;">&#10003; ' + escHtml(data.data.message) + '</span>';
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                        }
                    });
                });
            });

            /* --- Sync All --- */
            document.getElementById('tm-sync-btn').addEventListener('click', function() {
                var btn = this;
                var result = document.getElementById('tm-sync-result');
                btn.disabled = true;
                result.textContent = 'Syncing all box offices...';

                ajaxPost('tailor_made_sync', {}, function(data) {
                    btn.disabled = false;
                    if (data.success) {
                        var d = data.data;
                        var msg = 'Done! Created: ' + escHtml(String(d.created)) +
                                  ', Updated: ' + escHtml(String(d.updated)) +
                                  ', Deleted: ' + escHtml(String(d.deleted));

                        // Per-box-office breakdown.
                        if (d.box_offices) {
                            var parts = [];
                            Object.keys(d.box_offices).forEach(function(slug) {
                                var bo = d.box_offices[slug];
                                parts.push(escHtml(bo.name) + ': +' + bo.created + ' /' + bo.updated + 'u /-' + bo.deleted);
                            });
                            if (parts.length > 0) {
                                msg += '<br><small>' + parts.join(' | ') + '</small>';
                            }
                        }

                        if (d.errors && d.errors.length > 0) {
                            msg += '<br><span style="color:red;">Errors: ' + escHtml(d.errors.join(', ')) + '</span>';
                        }

                        result.innerHTML = '<span style="color:green;">&#10003; ' + msg + '</span>';
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                    }
                });
            });

            /* --- Compare Events --- */
            document.getElementById('tm-compare-btn').addEventListener('click', function() {
                var btn = this;
                var status = document.getElementById('tm-compare-status');
                btn.disabled = true;
                status.textContent = 'Loading...';

                ajaxPost('tailor_made_compare_events', {}, function(data) {
                    btn.disabled = false;
                    status.textContent = '';

                    if (!data.success) {
                        status.innerHTML = '<span style="color:red;">' + escHtml(data.data || 'Failed') + '</span>';
                        return;
                    }

                    var d = data.data;
                    document.getElementById('tm-tt-count').textContent = d.tt_count;
                    document.getElementById('tm-wp-count').textContent = d.wp_count;
                    document.getElementById('tm-compare-summary').style.display = 'block';

                    var tbody = document.getElementById('tm-compare-body');
                    tbody.innerHTML = '';

                    if (d.rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6">No events found.</td></tr>';
                    } else {
                        d.rows.forEach(function(row) {
                            var badgeClass = 'tm-badge-' + row.sync_status;
                            var label = row.sync_status.charAt(0).toUpperCase() + row.sync_status.slice(1);
                            tbody.innerHTML += '<tr>' +
                                '<td>' + escHtml(row.name) + '</td>' +
                                '<td>' + escHtml(row.box_office || '') + '</td>' +
                                '<td>' + escHtml(row.tt_status) + '</td>' +
                                '<td>' + escHtml(row.wp_status) + '</td>' +
                                '<td><span class="tm-badge ' + badgeClass + '">' + label + '</span></td>' +
                                '<td>' + escHtml(row.last_synced) + '</td>' +
                                '</tr>';
                        });
                    }

                    document.getElementById('tm-compare-table-wrap').style.display = 'block';
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * How To Use Tab
     */
    private static function render_tab_how_to_use(): void {
        ?>
        <div class="postbox" style="padding: 20px; max-width: 960px;">

            <div class="tm-info-section">
                <h3>Overview</h3>
                <p>Tailor Made syncs your Ticket Tailor events into WordPress as a custom post type (<code>tt_event</code>).
                    Each event becomes a WordPress post with 34+ custom fields containing all the event data — dates, prices,
                    venue, images, ticket availability, and more.</p>
                <p>You display these events on your pages using <strong>Bricks Builder's Query Loop</strong> — a powerful
                    feature that lets you query posts and render them dynamically with any layout you design.</p>
                <div style="background: #e8f5e9; border: 1px solid #4caf50; border-radius: 4px; padding: 12px; margin-top: 10px;">
                    <strong>Not using Bricks?</strong> You can also display events with simple shortcodes — no page builder needed.
                    Just paste <code>[tt_events]</code> into any page or post. See the
                    <a href="?page=tailor-made&tab=shortcodes"><strong>Shortcodes</strong></a> tab for details and examples.
                    <br><br>
                    <strong>Need to share attendee info?</strong> Use <a href="?page=tailor-made&tab=magic-links"><strong>Magic Links</strong></a>
                    to generate shareable roster links for organizers and volunteers — no login required.
                </div>
            </div>

            <div class="tm-info-section">
                <h3>Step 1: Navigate to Your Page in Bricks</h3>
                <ol>
                    <li>Go to <strong>Pages</strong> in WordPress admin</li>
                    <li>Find the page where you want to show events (e.g. "Shamail")</li>
                    <li>Click <strong>Edit with Bricks</strong></li>
                </ol>
            </div>

            <div class="tm-info-section">
                <h3>Step 2: Create a Query Loop Container</h3>
                <ol>
                    <li>Add a <strong>Container</strong> or <strong>Block</strong> element where you want the events to appear</li>
                    <li>In the element settings, click the <strong>Query Loop</strong> icon (infinity symbol) or find it under the element's settings panel</li>
                    <li>Toggle <strong>Use Query Loop</strong> to ON</li>
                    <li>Set <strong>Post Type</strong> to <code>tt_event</code> (shown as "TT Events")</li>
                    <li>Set <strong>Posts Per Page</strong> to how many events you want to show</li>
                </ol>
                <div style="background: #f0f6fc; border: 1px solid #0366d6; border-radius: 4px; padding: 12px; margin-top: 10px;">
                    <strong>Important — Post Status:</strong> Events synced from Ticket Tailor with a status of
                    <code>draft</code> will have a WordPress status of <strong>Draft</strong>. By default, query loops only
                    show <strong>Published</strong> posts. To include draft events, add a custom query parameter:
                    <br><br>
                    In the query loop settings under <strong>Post Status</strong>, select both <code>publish</code> and <code>draft</code>.
                    <br><br>
                    Alternatively, once events are published in Ticket Tailor (status = <code>published</code>, <code>live</code>, or <code>past</code>),
                    they will automatically sync as WordPress "Published" posts and appear without any extra configuration.
                </div>
            </div>

            <div class="tm-info-section">
                <h3>Step 3: Filter Events by Keyword</h3>
                <p>Ticket Tailor does <strong>not</strong> provide categories or tags for events. To show only specific events
                    on a page, you filter by <strong>keyword in the event title</strong> or by <strong>custom field values</strong>.</p>

                <h4>Option A: Filter by Title Keyword (Search)</h4>
                <p>In the query loop settings, find the <strong>Search</strong> (<code>s</code>) parameter and enter a keyword.
                    For example, entering <code>Shamail</code> will only return events whose title contains "Shamail".</p>
                <table class="tm-info-table" style="max-width: 600px;">
                    <thead><tr><th>Query Parameter</th><th>Value</th><th>Result</th></tr></thead>
                    <tbody>
                        <tr><td><code>s</code> (Search)</td><td><code>Shamail</code></td><td>Shows "Shamail Studies Retreat"</td></tr>
                        <tr><td><code>s</code> (Search)</td><td><code>Camp</code></td><td>Shows "Ibn Abbas Youth Camp"</td></tr>
                        <tr><td><code>s</code> (Search)</td><td><code>Retreat</code></td><td>Shows "Shamail Studies Retreat" + "Wilderness Leadership Retreat"</td></tr>
                    </tbody>
                </table>
                <p><strong>Note:</strong> The search parameter (<code>s</code>) searches both the title and content. If you need exact
                    title matching only, use a Meta Query on <code>post_title</code> or use Option B below.</p>

                <h4>Option B: Filter by Custom Field (Meta Query)</h4>
                <p>You can filter events by any of the 34 meta fields. In the query loop settings, add a <strong>Meta Query</strong>:</p>
                <table class="tm-info-table" style="max-width: 700px;">
                    <thead><tr><th>Use Case</th><th>Meta Key</th><th>Compare</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td>Only online events</td><td><code>_tt_online_event</code></td><td><code>=</code></td><td><code>true</code></td></tr>
                        <tr><td>Events with available tickets</td><td><code>_tt_tickets_available</code></td><td><code>=</code></td><td><code>true</code></td></tr>
                        <tr><td>Events at a specific venue</td><td><code>_tt_venue_name</code></td><td><code>=</code></td><td><code>Your Venue Name</code></td></tr>
                        <tr><td>Events after a date</td><td><code>_tt_start_unix</code></td><td><code>>=</code></td><td><code>1750000000</code></td></tr>
                        <tr><td>Free events only</td><td><code>_tt_price_display</code></td><td><code>=</code></td><td><code>Free</code></td></tr>
                        <tr><td>Specific TT event</td><td><code>_tt_event_id</code></td><td><code>=</code></td><td><code>ev_7670163</code></td></tr>
                    </tbody>
                </table>

                <h4>Option C: Filter by Multiple Conditions</h4>
                <p>You can combine search + meta queries. For example: show only events matching "Retreat" that have tickets available.
                    Add multiple Meta Query rows and set the <strong>Relation</strong> to <code>AND</code> (all must match) or <code>OR</code> (any can match).</p>
            </div>

            <div class="tm-info-section">
                <h3>Step 4: Sort/Order Events</h3>
                <p>In the query loop settings, set <strong>Order By</strong> and <strong>Order</strong>:</p>
                <table class="tm-info-table" style="max-width: 600px;">
                    <thead><tr><th>Order By</th><th>Meta Key (if applicable)</th><th>Order</th><th>Result</th></tr></thead>
                    <tbody>
                        <tr><td><code>meta_value_num</code></td><td><code>_tt_start_unix</code></td><td>ASC</td><td>Soonest events first</td></tr>
                        <tr><td><code>meta_value_num</code></td><td><code>_tt_start_unix</code></td><td>DESC</td><td>Latest events first</td></tr>
                        <tr><td><code>title</code></td><td>—</td><td>ASC</td><td>Alphabetical A-Z</td></tr>
                        <tr><td><code>meta_value_num</code></td><td><code>_tt_min_price</code></td><td>ASC</td><td>Cheapest first</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>Step 5: Design the Event Card</h3>
                <p>Inside the query loop container, add child elements that use <strong>Dynamic Data</strong> to pull event information.
                    Click the dynamic data icon (lightning bolt) in any text or image field, then select the appropriate tag.</p>

                <h4>Common Dynamic Data Tags</h4>
                <table class="tm-info-table">
                    <thead><tr><th>What to Show</th><th>Element Type</th><th>Dynamic Data Tag</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td>Event name</td><td>Heading / Text</td><td><code>{post_title}</code></td><td>The event title</td></tr>
                        <tr><td>Event description</td><td>Rich Text</td><td><code>{post_content}</code></td><td>Full event description HTML</td></tr>
                        <tr><td>Featured image</td><td>Image</td><td><code>{featured_image}</code></td><td>Header image from TT</td></tr>
                        <tr><td>Start date (formatted)</td><td>Text</td><td><code>{cf__tt_start_formatted}</code></td><td>e.g. "Wed Jul 15, 2026 9:00 AM"</td></tr>
                        <tr><td>End date (formatted)</td><td>Text</td><td><code>{cf__tt_end_formatted}</code></td><td>e.g. "Thu Jul 16, 2026 5:00 PM"</td></tr>
                        <tr><td>Price range</td><td>Text</td><td><code>{cf__tt_price_display}</code></td><td>e.g. "$75 - $150" or "Free"</td></tr>
                        <tr><td>Venue name</td><td>Text</td><td><code>{cf__tt_venue_name}</code></td><td>Venue location</td></tr>
                        <tr><td>Checkout link</td><td>Button / Link</td><td><code>{cf__tt_checkout_url}</code></td><td>Direct TT checkout</td></tr>
                        <tr><td>Event page link</td><td>Button / Link</td><td><code>{cf__tt_event_url}</code></td><td>TT public event page</td></tr>
                        <tr><td>TT Status</td><td>Text</td><td><code>{cf__tt_status}</code></td><td>published, live, draft, past</td></tr>
                        <tr><td>Tickets remaining</td><td>Text</td><td><code>{cf__tt_tickets_remaining}</code></td><td>Number of tickets left</td></tr>
                        <tr><td>Total capacity</td><td>Text</td><td><code>{cf__tt_total_capacity}</code></td><td>Total ticket capacity</td></tr>
                        <tr><td>Is online?</td><td>Text</td><td><code>{cf__tt_online_event}</code></td><td>"true" or "false"</td></tr>
                        <tr><td>Timezone</td><td>Text</td><td><code>{cf__tt_timezone}</code></td><td>e.g. "America/New_York"</td></tr>
                    </tbody>
                </table>
                <p><strong>Prefix rule:</strong> All custom field tags start with <code>{cf_</code> followed by the meta key.
                    Since meta keys start with <code>_tt_</code>, the dynamic data tag is <code>{cf__tt_fieldname}</code> (note the double underscore).</p>
            </div>

            <div class="tm-info-section">
                <h3>Step 6: Add a "Buy Tickets" Button</h3>
                <ol>
                    <li>Inside the query loop, add a <strong>Button</strong> element</li>
                    <li>Set the button text (e.g. "Get Tickets" or use the dynamic tag <code>{cf__tt_call_to_action}</code>)</li>
                    <li>For the link URL, click the dynamic data icon and select <code>{cf__tt_checkout_url}</code></li>
                    <li>Set the link to open in a <strong>new tab</strong> so users don't leave your site</li>
                </ol>
            </div>

            <div class="tm-info-section">
                <h3>Complete Example: Events Section</h3>
                <p>Here's the recommended element structure for an events listing:</p>
                <pre style="background: #f1f1f1; padding: 15px; border-radius: 4px; font-size: 13px; line-height: 1.6; overflow-x: auto;">Section (full-width background)
  Container (max-width, centered)
    Heading — "Upcoming Events"
    Block (Query Loop: post_type=tt_event, posts_per_page=6)
      Block (event card — this repeats for each event)
        Image — {featured_image}
        Block (content wrapper)
          Heading — {post_title}
          Text — {cf__tt_start_formatted}
          Text — {cf__tt_price_display}
          Text — {cf__tt_venue_name}
          Button — "Get Tickets" → {cf__tt_checkout_url}</pre>
                <p>The Block with the query loop will automatically repeat its children once per event returned by the query.</p>
            </div>

            <div class="tm-info-section">
                <h3>Showing Events on Specific Pages</h3>
                <p>To show <strong>only certain events</strong> on a specific page (e.g. only "Shamail" events on the Shamail page):</p>
                <ol>
                    <li>Create the query loop as described above</li>
                    <li>In the query loop settings, set the <strong>Search (<code>s</code>)</strong> field to the keyword that matches your events (e.g. <code>Shamail</code>)</li>
                    <li>This will filter the query to only return events whose title or content contains that keyword</li>
                </ol>

                <div style="background: #fff8e1; border: 1px solid #ffc107; border-radius: 4px; padding: 12px; margin-top: 10px;">
                    <strong>About Categories:</strong> Ticket Tailor does not provide event categories or tags.
                    All filtering must be done by <strong>keyword matching</strong> (title search) or <strong>custom field values</strong>
                    (meta queries on venue, date, price, etc.). If you need category-like behavior, establish a naming convention
                    in Ticket Tailor (e.g. prefix event names with "Shamail:", "Camp:", "Retreat:") and filter by that keyword.
                </div>
            </div>

            <div class="tm-info-section">
                <h3>Available Meta Fields Reference</h3>
                <p>Every synced event has these custom fields available for display or filtering. Use with the <code>{cf_<em>key</em>}</code> dynamic data syntax in Bricks.</p>
                <table class="tm-info-table" style="font-size: 13px;">
                    <thead><tr><th>Meta Key</th><th>Dynamic Data Tag</th><th>Type</th><th>Example Value</th></tr></thead>
                    <tbody>
                        <tr><td><code>_tt_event_id</code></td><td><code>{cf__tt_event_id}</code></td><td>String</td><td>ev_7670163</td></tr>
                        <tr><td><code>_tt_status</code></td><td><code>{cf__tt_status}</code></td><td>String</td><td>published</td></tr>
                        <tr><td><code>_tt_start_formatted</code></td><td><code>{cf__tt_start_formatted}</code></td><td>String</td><td>Wed Jul 15, 2026 9:00 AM</td></tr>
                        <tr><td><code>_tt_end_formatted</code></td><td><code>{cf__tt_end_formatted}</code></td><td>String</td><td>Thu Jul 16, 2026 5:00 PM</td></tr>
                        <tr><td><code>_tt_start_date</code></td><td><code>{cf__tt_start_date}</code></td><td>String</td><td>2026-07-15</td></tr>
                        <tr><td><code>_tt_start_time</code></td><td><code>{cf__tt_start_time}</code></td><td>String</td><td>09:00</td></tr>
                        <tr><td><code>_tt_start_unix</code></td><td><code>{cf__tt_start_unix}</code></td><td>Number</td><td>1784120400</td></tr>
                        <tr><td><code>_tt_end_date</code></td><td><code>{cf__tt_end_date}</code></td><td>String</td><td>2026-07-16</td></tr>
                        <tr><td><code>_tt_end_time</code></td><td><code>{cf__tt_end_time}</code></td><td>String</td><td>17:00</td></tr>
                        <tr><td><code>_tt_venue_name</code></td><td><code>{cf__tt_venue_name}</code></td><td>String</td><td>Convention Center</td></tr>
                        <tr><td><code>_tt_venue_country</code></td><td><code>{cf__tt_venue_country}</code></td><td>String</td><td>US</td></tr>
                        <tr><td><code>_tt_price_display</code></td><td><code>{cf__tt_price_display}</code></td><td>String</td><td>$75 - $150</td></tr>
                        <tr><td><code>_tt_checkout_url</code></td><td><code>{cf__tt_checkout_url}</code></td><td>URL</td><td>https://tickettailor.com/checkout/...</td></tr>
                        <tr><td><code>_tt_event_url</code></td><td><code>{cf__tt_event_url}</code></td><td>URL</td><td>https://tickettailor.com/events/...</td></tr>
                        <tr><td><code>_tt_call_to_action</code></td><td><code>{cf__tt_call_to_action}</code></td><td>String</td><td>Buy Tickets</td></tr>
                        <tr><td><code>_tt_online_event</code></td><td><code>{cf__tt_online_event}</code></td><td>Boolean</td><td>false</td></tr>
                        <tr><td><code>_tt_tickets_available</code></td><td><code>{cf__tt_tickets_available}</code></td><td>Boolean</td><td>true</td></tr>
                        <tr><td><code>_tt_tickets_remaining</code></td><td><code>{cf__tt_tickets_remaining}</code></td><td>Number</td><td>42</td></tr>
                        <tr><td><code>_tt_total_capacity</code></td><td><code>{cf__tt_total_capacity}</code></td><td>Number</td><td>100</td></tr>
                        <tr><td><code>_tt_currency</code></td><td><code>{cf__tt_currency}</code></td><td>String</td><td>usd</td></tr>
                        <tr><td><code>_tt_image_header</code></td><td><code>{cf__tt_image_header}</code></td><td>URL</td><td>https://uploads.tickettailor...</td></tr>
                        <tr><td><code>_tt_image_thumbnail</code></td><td><code>{cf__tt_image_thumbnail}</code></td><td>URL</td><td>https://uploads.tickettailor...</td></tr>
                        <tr><td><code>_tt_timezone</code></td><td><code>{cf__tt_timezone}</code></td><td>String</td><td>America/New_York</td></tr>
                        <tr><td><code>_tt_last_synced</code></td><td><code>{cf__tt_last_synced}</code></td><td>Datetime</td><td>2026-02-17 07:57:30</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>Tips &amp; Troubleshooting</h3>
                <ul>
                    <li><strong>Events not showing?</strong> Check the post status. Draft events in TT sync as WP Drafts. Either publish the events in Ticket Tailor, or include <code>draft</code> in the query loop's post status filter.</li>
                    <li><strong>Wrong events showing?</strong> Double-check your search keyword or meta query values. Use the <strong>Dashboard &gt; Event Comparison</strong> tool to see which events exist.</li>
                    <li><strong>Images not appearing?</strong> The featured image is downloaded during sync. If the event was just synced, the image should be in the Media Library. Use <code>{featured_image}</code> in an Image element.</li>
                    <li><strong>Want to show all events site-wide?</strong> Create a query loop with post type <code>tt_event</code> and no search filter. Set <code>posts_per_page</code> to <code>-1</code> to show all.</li>
                    <li><strong>Pagination?</strong> Bricks query loops support pagination natively. Enable it in the query loop settings and add a Pagination element below the loop.</li>
                    <li><strong>Conditional display?</strong> Use Bricks' conditions feature to show/hide elements based on meta values — e.g., only show a "Sold Out" badge when <code>_tt_tickets_available</code> is <code>false</code>.</li>
                </ul>
            </div>

            <div class="tm-info-section">
                <h3>Where to Find Things</h3>
                <table class="tm-info-table" style="max-width: 700px;">
                    <thead><tr><th>What</th><th>Where</th></tr></thead>
                    <tbody>
                        <tr><td>All synced events</td><td><strong>Tailor Made &gt; Events</strong> in the admin sidebar</td></tr>
                        <tr><td>Sync settings &amp; status</td><td><strong>Tailor Made &gt; Dashboard</strong> tab</td></tr>
                        <tr><td>TT vs WP comparison</td><td><strong>Tailor Made &gt; Dashboard</strong> &gt; Load Comparison button</td></tr>
                        <tr><td>Sync internals</td><td><strong>Tailor Made &gt; How Sync Works</strong> tab</td></tr>
                        <tr><td>Shareable attendee rosters</td><td><strong>Tailor Made &gt; Magic Links</strong> tab</td></tr>
                        <tr><td>Activity log</td><td><strong>Tailor Made &gt; Sync Log</strong> tab (enable logging first)</td></tr>
                        <tr><td>Edit an event page in Bricks</td><td>Pages &gt; find your page &gt; <strong>Edit with Bricks</strong></td></tr>
                        <tr><td>View a single event's data</td><td>Tailor Made &gt; Events &gt; click event &gt; <strong>Custom Fields</strong> section</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <?php
    }

    /**
     * How Sync Works Tab
     */
    private static function render_tab_how_sync_works(): void {
        ?>
        <div class="postbox" style="padding: 20px; max-width: 900px;">

            <div class="tm-info-section">
                <h3>Sync Cycle</h3>
                <p>Each sync run follows this sequence:</p>
                <ol>
                    <li><strong>Fetch</strong> — All events are retrieved from the Ticket Tailor API (paginated, 100 per page).</li>
                    <li><strong>Match</strong> — Each TT event is matched to a WordPress post by the <code>_tt_event_id</code> meta field.</li>
                    <li><strong>Create</strong> — Events not yet in WordPress are created as new <code>tt_event</code> posts.</li>
                    <li><strong>Update</strong> — Events already in WordPress are updated with the latest data from TT.</li>
                    <li><strong>Delete Orphans</strong> — WordPress posts whose TT event no longer exists in the API are permanently deleted.</li>
                </ol>
            </div>

            <div class="tm-info-section">
                <h3>Status Mapping</h3>
                <table class="tm-info-table">
                    <thead>
                        <tr><th>Ticket Tailor Status</th><th>WordPress Post Status</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>published</code></td><td>Publish</td></tr>
                        <tr><td><code>live</code></td><td>Publish</td></tr>
                        <tr><td><code>past</code></td><td>Publish</td></tr>
                        <tr><td><code>draft</code></td><td>Draft</td></tr>
                        <tr><td><em>(any other)</em></td><td>Draft</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>Data Synced (34 Meta Fields)</h3>
                <table class="tm-info-table">
                    <thead>
                        <tr><th>Meta Key</th><th>Source</th><th>Description</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>_tt_event_id</code></td><td><code>id</code></td><td>Unique event identifier (e.g. ev_7669695)</td></tr>
                        <tr><td><code>_tt_event_series_id</code></td><td><code>event_series_id</code></td><td>Parent series ID</td></tr>
                        <tr><td><code>_tt_status</code></td><td><code>status</code></td><td>Raw TT status</td></tr>
                        <tr><td><code>_tt_currency</code></td><td><code>currency</code></td><td>Event currency code</td></tr>
                        <tr><td><code>_tt_start_date</code></td><td><code>start.date</code></td><td>Start date</td></tr>
                        <tr><td><code>_tt_start_time</code></td><td><code>start.time</code></td><td>Start time</td></tr>
                        <tr><td><code>_tt_start_formatted</code></td><td><code>start.formatted</code></td><td>Human-readable start</td></tr>
                        <tr><td><code>_tt_start_iso</code></td><td><code>start.iso</code></td><td>ISO 8601 start timestamp</td></tr>
                        <tr><td><code>_tt_start_unix</code></td><td><code>start.unix</code></td><td>Unix timestamp start</td></tr>
                        <tr><td><code>_tt_end_date</code></td><td><code>end.date</code></td><td>End date</td></tr>
                        <tr><td><code>_tt_end_time</code></td><td><code>end.time</code></td><td>End time</td></tr>
                        <tr><td><code>_tt_end_formatted</code></td><td><code>end.formatted</code></td><td>Human-readable end</td></tr>
                        <tr><td><code>_tt_end_iso</code></td><td><code>end.iso</code></td><td>ISO 8601 end timestamp</td></tr>
                        <tr><td><code>_tt_end_unix</code></td><td><code>end.unix</code></td><td>Unix timestamp end</td></tr>
                        <tr><td><code>_tt_timezone</code></td><td><code>timezone</code></td><td>Event timezone</td></tr>
                        <tr><td><code>_tt_venue_name</code></td><td><code>venue.name</code></td><td>Venue name</td></tr>
                        <tr><td><code>_tt_venue_country</code></td><td><code>venue.country</code></td><td>Venue country</td></tr>
                        <tr><td><code>_tt_venue_postal_code</code></td><td><code>venue.postal_code</code></td><td>Venue postal code</td></tr>
                        <tr><td><code>_tt_image_header</code></td><td><code>images.header</code></td><td>Header image URL</td></tr>
                        <tr><td><code>_tt_image_thumbnail</code></td><td><code>images.thumbnail</code></td><td>Thumbnail image URL</td></tr>
                        <tr><td><code>_tt_checkout_url</code></td><td><code>checkout_url</code></td><td>Direct checkout link</td></tr>
                        <tr><td><code>_tt_event_url</code></td><td><code>url</code></td><td>Public event page URL</td></tr>
                        <tr><td><code>_tt_call_to_action</code></td><td><code>call_to_action</code></td><td>CTA button text</td></tr>
                        <tr><td><code>_tt_online_event</code></td><td><code>online_event</code></td><td>Is online event (true/false)</td></tr>
                        <tr><td><code>_tt_private</code></td><td><code>private</code></td><td>Is private event</td></tr>
                        <tr><td><code>_tt_hidden</code></td><td><code>hidden</code></td><td>Is hidden event</td></tr>
                        <tr><td><code>_tt_tickets_available</code></td><td><code>tickets_available</code></td><td>Are tickets still available</td></tr>
                        <tr><td><code>_tt_revenue</code></td><td><code>revenue</code></td><td>Total revenue (cents)</td></tr>
                        <tr><td><code>_tt_total_orders</code></td><td><code>total_orders</code></td><td>Total order count</td></tr>
                        <tr><td><code>_tt_total_issued_tickets</code></td><td><code>total_issued_tickets</code></td><td>Tickets issued</td></tr>
                        <tr><td><code>_tt_ticket_types</code></td><td><code>ticket_types</code></td><td>JSON array of ticket type objects</td></tr>
                        <tr><td><code>_tt_min_price</code></td><td><em>computed</em></td><td>Lowest ticket price (cents)</td></tr>
                        <tr><td><code>_tt_max_price</code></td><td><em>computed</em></td><td>Highest ticket price (cents)</td></tr>
                        <tr><td><code>_tt_price_display</code></td><td><em>computed</em></td><td>Formatted price range (e.g. "$10 - $25")</td></tr>
                        <tr><td><code>_tt_total_capacity</code></td><td><em>computed</em></td><td>Sum of all ticket quantities</td></tr>
                        <tr><td><code>_tt_tickets_remaining</code></td><td><em>computed</em></td><td>Capacity minus issued</td></tr>
                        <tr><td><code>_tt_last_synced</code></td><td><em>internal</em></td><td>Timestamp of last sync</td></tr>
                        <tr><td><code>_tt_raw_json</code></td><td><em>full response</em></td><td>Complete API response (JSON)</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>Auto-Sync Schedule</h3>
                <p>Events are synced automatically every <strong>hour</strong> via WordPress Cron (<code>wp_cron</code>).</p>
                <p><strong>Note:</strong> WP-Cron is triggered by page visits. On low-traffic sites, cron may run less
                    frequently than scheduled. Consider setting up a real cron job on your server:</p>
                <pre style="background: #f1f1f1; padding: 10px; border-radius: 3px;">*/15 * * * * wget -q -O /dev/null <?php echo esc_html( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?></pre>
            </div>

            <div class="tm-info-section">
                <h3>Featured Images</h3>
                <ul>
                    <li>Header images from Ticket Tailor are downloaded and set as the WordPress Featured Image.</li>
                    <li>Images are <strong>downloaded once</strong> — if the source URL hasn't changed, the existing image is kept.</li>
                    <li>Images are stored in the WordPress Media Library and attached to the event post.</li>
                </ul>
            </div>

            <div class="tm-info-section">
                <h3>Matching Logic</h3>
                <p>Events are matched between Ticket Tailor and WordPress using the <code>_tt_event_id</code> post meta field.
                    Each Ticket Tailor event has a unique ID (e.g. <code>ev_7669695</code>) that is stored when the event is first created in WordPress.
                    On subsequent syncs, this ID is used to find and update the correct post.</p>
                <p>If a Ticket Tailor event ID is no longer returned by the API, the corresponding WordPress post is considered
                    an <strong>orphan</strong> and is permanently deleted during sync.</p>
            </div>

        </div>
        <?php
    }

    /**
     * Sync Log Tab
     */
    private static function render_tab_sync_log(): void {
        $logging_enabled = get_option( 'tailor_made_logging_enabled', false );
        $retention_days  = get_option( 'tailor_made_log_retention_days', 30 );
        $nonce           = wp_create_nonce( 'tailor_made_nonce' );

        // Pagination
        $current_page = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;
        $filter_sync  = isset( $_GET['sync_id'] ) ? sanitize_text_field( $_GET['sync_id'] ) : null;

        $log_data = Tailor_Made_Sync_Logger::get_entries( $current_page, 50, $filter_sync );
        $entries  = $log_data['entries'];
        $total    = $log_data['total'];
        $pages    = $log_data['pages'];
        ?>

        <!-- Log Settings -->
        <div class="postbox" style="padding: 15px; margin-bottom: 20px;">
            <h2><?php esc_html_e( 'Log Settings', 'tailor-made' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Enable Logging', 'tailor-made' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="tm-logging-enabled" value="1"
                                <?php checked( $logging_enabled ); ?> />
                            <?php esc_html_e( 'Record sync activity to the database', 'tailor-made' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When disabled, the logger is a no-op with zero performance impact.', 'tailor-made' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Retention Period', 'tailor-made' ); ?></th>
                    <td>
                        <input type="number" id="tm-retention-days" value="<?php echo esc_attr( $retention_days ); ?>"
                               min="1" max="365" style="width: 80px;" /> <?php esc_html_e( 'days', 'tailor-made' ); ?>
                        <p class="description"><?php esc_html_e( 'Log entries older than this are automatically purged daily.', 'tailor-made' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="tm-save-log-settings"><?php esc_html_e( 'Save Log Settings', 'tailor-made' ); ?></button>
                <button type="button" class="button" id="tm-clear-logs" style="margin-left: 10px; color: #a00;"><?php esc_html_e( 'Clear All Logs', 'tailor-made' ); ?></button>
                <span id="tm-log-settings-result" style="margin-left: 10px;"></span>
            </p>
        </div>

        <!-- Sync Run Filter -->
        <?php if ( $filter_sync ) : ?>
        <div class="tm-log-filter">
            Showing entries for sync run: <strong><?php echo esc_html( $filter_sync ); ?></strong>
            &nbsp;
            <a href="?page=tailor-made&tab=sync-log">Show All</a>
        </div>
        <?php endif; ?>

        <!-- Log Table -->
        <div class="postbox" style="padding: 15px;">
            <h2>Log Entries <?php if ( $total > 0 ) : ?><span style="font-weight: normal; font-size: 13px;">(<?php echo esc_html( $total ); ?> total)</span><?php endif; ?></h2>

            <?php if ( empty( $entries ) ) : ?>
                <p>No log entries found.
                    <?php if ( ! $logging_enabled ) : ?>
                        Enable logging above and run a sync to see entries here.
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <table class="tm-log-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;"><?php esc_html_e( 'Time', 'tailor-made' ); ?></th>
                            <th style="width: 70px;"><?php esc_html_e( 'Level', 'tailor-made' ); ?></th>
                            <th style="width: 80px;"><?php esc_html_e( 'Action', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Event', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'tailor-made' ); ?></th>
                            <th style="width: 90px;"><?php esc_html_e( 'Sync Run', 'tailor-made' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $entry ) :
                            $badge_class = 'tm-badge-info';
                            if ( $entry['level'] === 'warning' ) $badge_class = 'tm-badge-warning';
                            if ( $entry['level'] === 'error' ) $badge_class = 'tm-badge-error';
                            $short_sync_id = substr( $entry['sync_id'], 0, 8 );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $entry['timestamp'] ); ?></td>
                            <td><span class="tm-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $entry['level'] ); ?></span></td>
                            <td><?php echo esc_html( $entry['action'] ); ?></td>
                            <td>
                                <?php if ( $entry['event_name'] ) : ?>
                                    <?php echo esc_html( $entry['event_name'] ); ?>
                                    <?php if ( $entry['tt_event_id'] ) : ?>
                                        <br><small style="color: #666;"><?php echo esc_html( $entry['tt_event_id'] ); ?></small>
                                    <?php endif; ?>
                                <?php elseif ( $entry['tt_event_id'] ) : ?>
                                    <?php echo esc_html( $entry['tt_event_id'] ); ?>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $entry['message'] ); ?></td>
                            <td>
                                <?php if ( $filter_sync ) : ?>
                                    <code style="font-size: 11px;"><?php echo esc_html( $short_sync_id ); ?></code>
                                <?php else : ?>
                                    <a href="?page=tailor-made&tab=sync-log&sync_id=<?php echo esc_attr( $entry['sync_id'] ); ?>"
                                       title="Show all entries from this sync run">
                                        <code style="font-size: 11px;"><?php echo esc_html( $short_sync_id ); ?></code>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ( $pages > 1 ) : ?>
                <div style="margin-top: 15px; text-align: center;">
                    <?php
                    $base_url = '?page=tailor-made&tab=sync-log';
                    if ( $filter_sync ) {
                        $base_url .= '&sync_id=' . urlencode( $filter_sync );
                    }

                    if ( $current_page > 1 ) :
                        echo '<a class="button" href="' . esc_url( $base_url . '&log_page=' . ( $current_page - 1 ) ) . '">&laquo; Previous</a> ';
                    endif;

                    echo '<span style="margin: 0 10px;">Page ' . esc_html( $current_page ) . ' of ' . esc_html( $pages ) . '</span>';

                    if ( $current_page < $pages ) :
                        echo ' <a class="button" href="' . esc_url( $base_url . '&log_page=' . ( $current_page + 1 ) ) . '">Next &raquo;</a>';
                    endif;
                    ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function escHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
            }

            // Save Log Settings
            document.getElementById('tm-save-log-settings').addEventListener('click', function() {
                var btn = this;
                var result = document.getElementById('tm-log-settings-result');
                var enabled = document.getElementById('tm-logging-enabled').checked ? '1' : '0';
                var retention = document.getElementById('tm-retention-days').value;

                btn.disabled = true;
                result.textContent = 'Saving...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=tailor_made_save_log_settings&nonce=' + nonce +
                          '&logging_enabled=' + enabled + '&retention_days=' + retention
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (data.success) {
                        result.innerHTML = '<span style="color:green;">&#10003; ' + escHtml(data.data) + '</span>';
                    } else {
                        result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                    }
                });
            });

            // Clear All Logs
            document.getElementById('tm-clear-logs').addEventListener('click', function() {
                if (!confirm('Are you sure you want to delete all log entries? This cannot be undone.')) {
                    return;
                }

                var btn = this;
                var result = document.getElementById('tm-log-settings-result');
                btn.disabled = true;
                result.textContent = 'Clearing...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=tailor_made_clear_logs&nonce=' + nonce
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (data.success) {
                        result.innerHTML = '<span style="color:green;">&#10003; ' + escHtml(data.data) + '</span>';
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        result.innerHTML = '<span style="color:red;">&#10007; ' + escHtml(data.data || 'Failed') + '</span>';
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * About Tab
     */
    /**
     * Shortcodes Tab
     */
    /**
     * AJAX: Generate a roster magic link.
     */
    public static function ajax_generate_roster_link(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== 'tt_event' ) {
            wp_send_json_error( 'Invalid event.' );
        }

        $token = Tailor_Made_Magic_Links::generate_token( $post_id );
        $url   = Tailor_Made_Magic_Links::get_roster_url( $post_id );

        wp_send_json_success( array( 'token' => $token, 'url' => $url ) );
    }

    /**
     * AJAX: Rotate a roster magic link.
     */
    public static function ajax_rotate_roster_link(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== 'tt_event' ) {
            wp_send_json_error( 'Invalid event.' );
        }

        $token = Tailor_Made_Magic_Links::rotate_token( $post_id );
        $url   = Tailor_Made_Magic_Links::get_roster_url( $post_id );

        wp_send_json_success( array( 'token' => $token, 'url' => $url ) );
    }

    /**
     * AJAX: Revoke a roster magic link.
     */
    public static function ajax_revoke_roster_link(): void {
        check_ajax_referer( 'tailor_made_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== 'tt_event' ) {
            wp_send_json_error( 'Invalid event.' );
        }

        Tailor_Made_Magic_Links::revoke_token( $post_id );

        wp_send_json_success();
    }

    /**
     * Magic Links Tab
     */
    private static function render_tab_magic_links(): void {
        $nonce       = wp_create_nonce( 'tailor_made_nonce' );
        $roster_page = get_option( 'tailor_made_roster_page_id' );
        $roster_url  = $roster_page ? get_permalink( $roster_page ) : false;

        // Get all tt_event posts, sorted by start date (upcoming first).
        $events = get_posts( array(
            'post_type'   => 'tt_event',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_key'    => '_tt_start_unix',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
        ) );
        ?>
        <div class="postbox" style="padding: 20px; max-width: 1100px;">

            <div class="tm-info-section">
                <h3><?php esc_html_e( 'Magic Links', 'tailor-made' ); ?></h3>
                <p><?php esc_html_e( 'Generate shareable links that let organizers and volunteers view the attendee roster for an event — no WordPress login required. Each link is unguessable and can be rotated or revoked at any time.', 'tailor-made' ); ?></p>

                <?php if ( $roster_url ) : ?>
                <div style="background: #f0f6fc; border: 1px solid #0366d6; border-radius: 4px; padding: 12px; margin-bottom: 16px;">
                    <strong><?php esc_html_e( 'Roster Page:', 'tailor-made' ); ?></strong>
                    <a href="<?php echo esc_url( $roster_url ); ?>" target="_blank"><?php echo esc_html( $roster_url ); ?></a>
                    <br><small style="color: #666;"><?php esc_html_e( 'This page displays the attendee roster when visited with a valid token. It was auto-created on plugin activation.', 'tailor-made' ); ?></small>
                </div>
                <?php else : ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 12px; margin-bottom: 16px;">
                    <strong><?php esc_html_e( 'Roster page not found.', 'tailor-made' ); ?></strong>
                    <?php esc_html_e( 'Deactivate and reactivate the plugin to create it, or manually create a page with the [tt_roster] shortcode.', 'tailor-made' ); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( empty( $events ) ) : ?>
                <p><?php esc_html_e( 'No events found. Sync your events first from the Dashboard tab.', 'tailor-made' ); ?></p>
            <?php else : ?>
            <table class="tm-info-table" id="tm-magic-links-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Event', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Attendees', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Magic Link', 'tailor-made' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'tailor-made' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $events as $event ) :
                        $token     = Tailor_Made_Magic_Links::get_token( $event->ID );
                        $event_url = $token ? Tailor_Made_Magic_Links::get_roster_url( $event->ID ) : '';
                        $attendees = get_post_meta( $event->ID, '_tt_total_issued_tickets', true );
                        $date      = get_post_meta( $event->ID, '_tt_start_formatted', true );
                    ?>
                    <tr id="tm-ml-row-<?php echo esc_attr( $event->ID ); ?>">
                        <td><strong><?php echo esc_html( $event->post_title ); ?></strong></td>
                        <td><?php echo esc_html( $date ); ?></td>
                        <td><?php echo esc_html( $attendees ? $attendees : '0' ); ?></td>
                        <td class="tm-ml-link-cell" style="max-width: 280px; word-break: break-all;">
                            <?php if ( $token ) : ?>
                                <code class="tm-ml-url" style="font-size: 11px;"><?php echo esc_html( $event_url ); ?></code>
                            <?php else : ?>
                                <span style="color: #999;"><?php esc_html_e( 'Not generated', 'tailor-made' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tm-ml-actions-cell">
                            <?php if ( $token ) : ?>
                                <button type="button" class="button button-small tm-ml-copy" data-url="<?php echo esc_attr( $event_url ); ?>"><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button>
                                <button type="button" class="button button-small tm-ml-rotate" data-post-id="<?php echo esc_attr( $event->ID ); ?>"><?php esc_html_e( 'Rotate', 'tailor-made' ); ?></button>
                                <button type="button" class="button button-small" style="color:#a00;" data-post-id="<?php echo esc_attr( $event->ID ); ?>" onclick="tmRevokeLink(this)"><?php esc_html_e( 'Revoke', 'tailor-made' ); ?></button>
                            <?php else : ?>
                                <button type="button" class="button button-primary button-small tm-ml-generate" data-post-id="<?php echo esc_attr( $event->ID ); ?>"><?php esc_html_e( 'Generate Link', 'tailor-made' ); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        </div>

        <script>
        (function() {
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function escHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
            }

            function ajaxPost(action, postId, callback) {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=' + action + '&nonce=' + nonce + '&post_id=' + postId
                })
                .then(function(r) { return r.json(); })
                .then(callback);
            }

            function updateRow(postId, url) {
                var row = document.getElementById('tm-ml-row-' + postId);
                if (!row) return;
                var linkCell = row.querySelector('.tm-ml-link-cell');
                var actionsCell = row.querySelector('.tm-ml-actions-cell');

                linkCell.innerHTML = '<code class="tm-ml-url" style="font-size:11px;">' + escHtml(url) + '</code>';
                actionsCell.innerHTML =
                    '<button type="button" class="button button-small tm-ml-copy" data-url="' + escHtml(url) + '">Copy</button> ' +
                    '<button type="button" class="button button-small tm-ml-rotate" data-post-id="' + postId + '">Rotate</button> ' +
                    '<button type="button" class="button button-small" style="color:#a00;" data-post-id="' + postId + '" onclick="tmRevokeLink(this)">Revoke</button>';

                bindButtons(row);
            }

            function clearRow(postId) {
                var row = document.getElementById('tm-ml-row-' + postId);
                if (!row) return;
                var linkCell = row.querySelector('.tm-ml-link-cell');
                var actionsCell = row.querySelector('.tm-ml-actions-cell');

                linkCell.innerHTML = '<span style="color:#999;">Not generated</span>';
                actionsCell.innerHTML =
                    '<button type="button" class="button button-primary button-small tm-ml-generate" data-post-id="' + postId + '">Generate Link</button>';

                bindButtons(row);
            }

            function bindButtons(scope) {
                scope = scope || document;

                scope.querySelectorAll('.tm-ml-generate').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var postId = this.getAttribute('data-post-id');
                        this.disabled = true;
                        this.textContent = 'Generating...';
                        ajaxPost('tailor_made_generate_roster_link', postId, function(data) {
                            if (data.success) {
                                updateRow(postId, data.data.url);
                            } else {
                                alert('Error: ' + (data.data || 'Failed'));
                            }
                        });
                    });
                });

                scope.querySelectorAll('.tm-ml-copy').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var url = this.getAttribute('data-url');
                        var el = this;
                        navigator.clipboard.writeText(url).then(function() {
                            var orig = el.textContent;
                            el.textContent = 'Copied!';
                            el.style.color = '#38a169';
                            setTimeout(function() {
                                el.textContent = orig;
                                el.style.color = '';
                            }, 1500);
                        });
                    });
                });

                scope.querySelectorAll('.tm-ml-rotate').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var postId = this.getAttribute('data-post-id');
                        if (!confirm('This will invalidate the current link. Anyone with the old link will no longer have access. Continue?')) return;
                        this.disabled = true;
                        this.textContent = 'Rotating...';
                        ajaxPost('tailor_made_rotate_roster_link', postId, function(data) {
                            if (data.success) {
                                updateRow(postId, data.data.url);
                            } else {
                                alert('Error: ' + (data.data || 'Failed'));
                            }
                        });
                    });
                });
            }

            // Revoke needs to be global for inline onclick.
            window.tmRevokeLink = function(btn) {
                var postId = btn.getAttribute('data-post-id');
                if (!confirm('This will permanently disable this link. Continue?')) return;
                btn.disabled = true;
                btn.textContent = 'Revoking...';
                ajaxPost('tailor_made_revoke_roster_link', postId, function(data) {
                    if (data.success) {
                        clearRow(postId);
                    } else {
                        alert('Error: ' + (data.data || 'Failed'));
                        btn.disabled = false;
                        btn.textContent = 'Revoke';
                    }
                });
            };

            bindButtons();
        })();
        </script>
        <?php
    }

    private static function render_tab_shortcodes(): void {
        ?>
        <div class="postbox" style="padding: 20px; max-width: 960px;">

            <div class="tm-info-section">
                <h3><?php esc_html_e( 'Display Events Anywhere', 'tailor-made' ); ?></h3>
                <p><?php esc_html_e( 'Shortcodes let you display Ticket Tailor events on any page or post — no Bricks Builder or page builder required. Just paste a shortcode into the WordPress editor and your events appear.', 'tailor-made' ); ?></p>

                <div style="background: #f0f6fc; border: 1px solid #0366d6; border-radius: 4px; padding: 16px; margin-top: 12px;">
                    <strong><?php esc_html_e( 'Quick Start', 'tailor-made' ); ?></strong>
                    <ol style="margin: 10px 0 0; padding-left: 20px; line-height: 1.8;">
                        <li><?php esc_html_e( 'Make sure you have synced your events (Dashboard tab > Sync Now)', 'tailor-made' ); ?></li>
                        <li><?php esc_html_e( 'Edit any page or post in WordPress', 'tailor-made' ); ?></li>
                        <li><?php echo wp_kses( __( 'Add a <strong>Shortcode</strong> block (or paste directly into a text block)', 'tailor-made' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( 'Type <code>[tt_events]</code> — that\'s it! Your events will appear as a responsive card grid.', 'tailor-made' ), array( 'code' => array() ) ); ?></li>
                    </ol>
                </div>

                <div style="background: #f1f1f1; border-radius: 4px; padding: 16px; margin-top: 12px;">
                    <strong><?php esc_html_e( 'Common Examples', 'tailor-made' ); ?></strong>
                    <ul style="margin: 10px 0 0; padding-left: 20px; line-height: 2;">
                        <li><code>[tt_events]</code> — <?php esc_html_e( 'Show all events in a 3-column grid', 'tailor-made' ); ?></li>
                        <li><code>[tt_events limit="3" show="image,title,date,button"]</code> — <?php esc_html_e( 'Compact grid with just the essentials', 'tailor-made' ); ?></li>
                        <li><code>[tt_events style="list"]</code> — <?php esc_html_e( 'Horizontal list layout instead of grid', 'tailor-made' ); ?></li>
                        <li><code>[tt_event id="123"]</code> — <?php esc_html_e( 'Show one specific event by its WordPress post ID', 'tailor-made' ); ?></li>
                        <li><code>[tt_upcoming_count]</code> — <?php esc_html_e( 'Just the number of upcoming events (e.g. "We have 5 upcoming events!")', 'tailor-made' ); ?></li>
                    </ul>
                </div>
            </div>

            <hr style="margin: 30px 0;" />

            <div class="tm-info-section">
                <h3><?php esc_html_e( 'Full Reference', 'tailor-made' ); ?></h3>
            </div>

            <div class="tm-info-section">
                <h3>[tt_events]
                <p><?php esc_html_e( 'Display a grid or list of events.', 'tailor-made' ); ?></p>
                <table class="tm-info-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Attribute', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Default', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'tailor-made' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>limit</code></td><td><code>6</code></td><td><?php esc_html_e( 'Number of events to show', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>status</code></td><td><code>publish</code></td><td><?php esc_html_e( 'Post status filter', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>orderby</code></td><td><code>_tt_start_unix</code></td><td><?php esc_html_e( 'Meta key to sort by', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>order</code></td><td><code>ASC</code></td><td><?php esc_html_e( 'Sort direction (ASC or DESC)', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>columns</code></td><td><code>3</code></td><td><?php esc_html_e( 'Number of grid columns', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>show</code></td><td><code>image,title,date,price,location,description,button</code></td><td><?php esc_html_e( 'Comma-separated list of fields to display', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>style</code></td><td><code>grid</code></td><td><?php esc_html_e( 'Layout style: grid or list', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>class</code></td><td><code></code></td><td><?php esc_html_e( 'Additional CSS class', 'tailor-made' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>[tt_event]</h3>
                <p><?php esc_html_e( 'Display a single event card.', 'tailor-made' ); ?></p>
                <table class="tm-info-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Attribute', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Default', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'tailor-made' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>id</code></td><td><em><?php esc_html_e( 'required', 'tailor-made' ); ?></em></td><td><?php esc_html_e( 'WP post ID or TT event ID (ev_xxx)', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>show</code></td><td><code>image,title,date,price,location,description,button</code></td><td><?php esc_html_e( 'Comma-separated fields', 'tailor-made' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>[tt_event_field]</h3>
                <p><?php esc_html_e( 'Output a single event field value inline.', 'tailor-made' ); ?></p>
                <table class="tm-info-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Attribute', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Default', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'tailor-made' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>field</code></td><td><em><?php esc_html_e( 'required', 'tailor-made' ); ?></em></td><td><?php esc_html_e( 'Meta key without _tt_ prefix (e.g. venue_name, price_display)', 'tailor-made' ); ?></td></tr>
                        <tr><td><code>id</code></td><td><em><?php esc_html_e( 'current post', 'tailor-made' ); ?></em></td><td><?php esc_html_e( 'WP post ID or TT event ID', 'tailor-made' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tm-info-section">
                <h3>[tt_upcoming_count]</h3>
                <p><?php esc_html_e( 'Output the number of upcoming events (start date in the future).', 'tailor-made' ); ?></p>
                <p><?php esc_html_e( 'No attributes. Returns a count wrapped in a span.', 'tailor-made' ); ?></p>
            </div>

            <div class="tm-info-section">
                <h3><?php esc_html_e( 'Examples', 'tailor-made' ); ?></h3>
                <table class="tm-info-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Use Case', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Shortcode', 'tailor-made' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php esc_html_e( 'All events (default grid)', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_events]</code> <button type="button" class="button button-small tm-copy-btn" data-code="[tt_events]"><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Compact 3-event grid', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_events limit="3" show="image,title,date,button"]</code> <button type="button" class="button button-small tm-copy-btn" data-code='[tt_events limit="3" show="image,title,date,button"]'><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'List layout', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_events style="list" columns="1"]</code> <button type="button" class="button button-small tm-copy-btn" data-code='[tt_events style="list" columns="1"]'><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Single event by WP ID', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_event id="123"]</code> <button type="button" class="button button-small tm-copy-btn" data-code='[tt_event id="123"]'><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Single event by TT ID', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_event id="ev_7670163"]</code> <button type="button" class="button button-small tm-copy-btn" data-code='[tt_event id="ev_7670163"]'><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Inline venue name', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_event_field field="venue_name"]</code> <button type="button" class="button button-small tm-copy-btn" data-code='[tt_event_field field="venue_name"]'><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Upcoming event count', 'tailor-made' ); ?></td>
                            <td><code class="tm-copyable">[tt_upcoming_count]</code> <button type="button" class="button button-small tm-copy-btn" data-code="[tt_upcoming_count]"><?php esc_html_e( 'Copy', 'tailor-made' ); ?></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>

        <script>
        (function() {
            document.querySelectorAll('.tm-copy-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var code = this.getAttribute('data-code');
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(code).then(function() {
                            btn.textContent = '<?php echo esc_js( __( 'Copied!', 'tailor-made' ) ); ?>';
                            setTimeout(function() { btn.textContent = '<?php echo esc_js( __( 'Copy', 'tailor-made' ) ); ?>'; }, 1500);
                        });
                    } else {
                        var textarea = document.createElement('textarea');
                        textarea.value = code;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        btn.textContent = '<?php echo esc_js( __( 'Copied!', 'tailor-made' ) ); ?>';
                        setTimeout(function() { btn.textContent = '<?php echo esc_js( __( 'Copy', 'tailor-made' ) ); ?>'; }, 1500);
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * About Tab
     */
    private static function render_tab_about(): void {
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 960px;">

            <!-- About -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin-top: 0;">About Tailor Made</h2>
                <p style="font-size: 14px; line-height: 1.7;">
                    Tailor Made is an unofficial Ticket Tailor integration for WordPress. It syncs your Ticket Tailor
                    events, ticket types, and event data into WordPress as custom post types, making them available
                    as dynamic data in Bricks Builder.
                </p>

                <table class="widefat" style="margin: 15px 0;">
                    <tr>
                        <td><strong>Version</strong></td>
                        <td><?php echo esc_html( TAILOR_MADE_VERSION ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Made by</strong></td>
                        <td><a href="https://ashrafali.net" target="_blank">Ashraf Ali</a></td>
                    </tr>
                    <tr>
                        <td><strong>GitHub</strong></td>
                        <td><a href="https://github.com/nerveband/tailor-made" target="_blank">wavedepth/tailor-made</a></td>
                    </tr>
                    <tr>
                        <td><strong>License</strong></td>
                        <td>GPL-2.0-or-later</td>
                    </tr>
                    <tr>
                        <td><strong>Requires</strong></td>
                        <td>WordPress 6.0+ &bull; PHP 7.4+</td>
                    </tr>
                </table>

                <p>
                    <a href="https://github.com/nerveband/tailor-made/issues" target="_blank" class="button">Report an Issue</a>
                    <a href="https://github.com/nerveband/tailor-made/releases" target="_blank" class="button" style="margin-left: 5px;">View Releases</a>
                </p>
            </div>

            <!-- Quick Links -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin-top: 0;">Quick Links</h2>
                <ul style="font-size: 14px; line-height: 2;">
                    <li><a href="?page=tailor-made&tab=dashboard">Dashboard</a> — API settings, sync status, event comparison</li>
                    <li><a href="?page=tailor-made&tab=how-to-use">How To Use</a> — Display events with Bricks query loops</li>
                    <li><a href="?page=tailor-made&tab=how-sync-works">How Sync Works</a> — Technical sync reference, meta fields</li>
                    <li><a href="?page=tailor-made&tab=shortcodes">Shortcodes</a> — Display events anywhere with shortcodes</li>
                    <li><a href="?page=tailor-made&tab=magic-links">Magic Links</a> — Shareable attendee rosters for organizers</li>
                    <li><a href="?page=tailor-made&tab=sync-log">Sync Log</a> — Activity logging and troubleshooting</li>
                    <li><a href="edit.php?post_type=tt_event">All Events</a> — View synced events in WordPress</li>
                </ul>
            </div>

        </div>

        <!-- Changelog -->
        <div class="postbox" style="padding: 20px; max-width: 960px; margin-top: 20px;">
            <h2 style="margin-top: 0;">Changelog</h2>

            <div class="tm-info-section">
                <h3>v1.3.0 <span style="font-weight: normal; color: #666; font-size: 13px;">— February 2026</span></h3>
                <ul style="line-height: 1.8;">
                    <li><strong>New:</strong> Magic Links — shareable roster links for organizers and volunteers to view attendees without logging in</li>
                    <li><strong>New:</strong> <code>[tt_roster]</code> shortcode — renders attendee roster from a magic link token</li>
                    <li><strong>New:</strong> Roster page auto-created on plugin activation with <code>noindex</code> protection</li>
                    <li><strong>New:</strong> Magic Links admin tab — generate, copy, rotate, and revoke links per event</li>
                    <li><strong>New:</strong> API method <code>get_issued_tickets_for_event()</code> — fetches attendees for a specific event</li>
                    <li><strong>New:</strong> Custom checkout questions displayed as columns in the roster table</li>
                    <li><strong>New:</strong> Client-side search filtering on roster page</li>
                    <li><strong>Security:</strong> 256-bit tokens (64 hex chars) — cryptographically unguessable</li>
                    <li><strong>Security:</strong> All roster output escaped with <code>esc_html()</code> / <code>esc_url()</code></li>
                    <li><strong>Improved:</strong> 5-minute server-side cache prevents excessive API calls from roster views</li>
                </ul>
            </div>

            <div class="tm-info-section">
                <h3>v1.2.0 <span style="font-weight: normal; color: #666; font-size: 13px;">— February 17, 2026</span></h3>
                <ul style="line-height: 1.8;">
                    <li><strong>New:</strong> Shortcode system — <code>[tt_events]</code>, <code>[tt_event]</code>, <code>[tt_event_field]</code>, <code>[tt_upcoming_count]</code> for displaying events without Bricks Builder</li>
                    <li><strong>New:</strong> Shortcodes reference tab in admin with examples and copy-to-clipboard</li>
                    <li><strong>New:</strong> Uninstall handler — cleans up options, DB table, and cron on plugin delete</li>
                    <li><strong>New:</strong> "Delete events on uninstall" setting in Dashboard</li>
                    <li><strong>Security:</strong> Fixed XSS in admin JS — all innerHTML insertions now escaped via <code>escHtml()</code></li>
                    <li><strong>Security:</strong> SSRF protection — image downloads restricted to tickettailor.com domains over HTTPS</li>
                    <li><strong>Security:</strong> URL sanitization — checkout/event/image URLs sanitized with <code>esc_url_raw()</code> at save time</li>
                    <li><strong>Security:</strong> Orphan-delete safety — skips mass deletion if API returns 0 events but WP has posts</li>
                    <li><strong>Security:</strong> API pagination capped at 100 pages to prevent infinite loops</li>
                    <li><strong>Security:</strong> Path parameters URL-encoded in API client</li>
                    <li><strong>Security:</strong> Context-aware escaping in Bricks dynamic data provider</li>
                    <li><strong>Security:</strong> Sensitive meta fields (<code>_tt_revenue</code>, <code>_tt_raw_json</code>, etc.) hidden from REST API</li>
                    <li><strong>Security:</strong> Added <code>current_user_can</code> guard to admin page render</li>
                    <li><strong>Improved:</strong> Internationalization (i18n) — admin UI strings wrapped for translation</li>
                </ul>
            </div>

            <div class="tm-info-section">
                <h3>v1.1.0 <span style="font-weight: normal; color: #666; font-size: 13px;">— February 17, 2026</span></h3>
                <ul style="line-height: 1.8;">
                    <li><strong>New:</strong> Tabbed admin interface — Dashboard, How To Use, How Sync Works, Sync Log, About</li>
                    <li><strong>New:</strong> Event Comparison tool — side-by-side view of Ticket Tailor vs WordPress data</li>
                    <li><strong>New:</strong> "How To Use" documentation — step-by-step guide for displaying events with Bricks query loops, filtering, dynamic data tags</li>
                    <li><strong>New:</strong> Sync Log — DB-backed logging with enable/disable toggle, retention settings, paginated viewer, sync run filtering</li>
                    <li><strong>New:</strong> Log cleanup cron — automatically purges old log entries daily based on retention setting</li>
                    <li><strong>New:</strong> About tab with changelog, author info, and quick links</li>
                    <li><strong>Improved:</strong> Sync engine now logs 8 sync points (start, fetch, create, update, delete, end) when logging is enabled</li>
                    <li><strong>Improved:</strong> Logger is a no-op when disabled — zero performance impact</li>
                </ul>
            </div>

            <div class="tm-info-section">
                <h3>v1.0.0 <span style="font-weight: normal; color: #666; font-size: 13px;">— February 2026</span></h3>
                <ul style="line-height: 1.8;">
                    <li><strong>Initial release</strong></li>
                    <li>Ticket Tailor API client with full pagination support</li>
                    <li>Event sync engine — creates, updates, and deletes WordPress posts to match TT</li>
                    <li>Custom Post Type: <code>tt_event</code> with 34+ meta fields</li>
                    <li>Featured image download and caching</li>
                    <li>Status mapping (published/live/past → Publish, draft → Draft)</li>
                    <li>Hourly auto-sync via WP-Cron</li>
                    <li>Bricks Builder dynamic data provider</li>
                    <li>GitHub-based auto-updater</li>
                    <li>Admin page with API settings, connection test, and manual sync</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
