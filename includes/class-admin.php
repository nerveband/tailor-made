<?php
/**
 * Admin settings page and sync controls.
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
        register_setting( 'tailor_made_settings', 'tailor_made_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_setting( 'tailor_made_settings', 'tailor_made_sync_interval', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
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

        $engine = new Tailor_Made_Sync_Engine();
        $result = $engine->sync_all();

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

        $client = new Tailor_Made_API_Client();
        $ping   = $client->ping();

        if ( is_wp_error( $ping ) ) {
            wp_send_json_error( $ping->get_error_message() );
        }

        $overview = $client->overview();
        if ( is_wp_error( $overview ) ) {
            wp_send_json_success( [ 'ping' => $ping, 'overview' => null ] );
        }

        wp_send_json_success( [ 'ping' => $ping, 'overview' => $overview ] );
    }

    /**
     * Render the settings page.
     */
    public static function render_page(): void {
        $api_key     = get_option( 'tailor_made_api_key', '' );
        $last_sync   = get_option( 'tailor_made_last_sync', 'Never' );
        $last_result = get_option( 'tailor_made_last_sync_result', [] );
        $event_count = wp_count_posts( Tailor_Made_CPT::POST_TYPE );
        $total       = ( $event_count->publish ?? 0 ) + ( $event_count->draft ?? 0 );
        $next_cron   = wp_next_scheduled( 'tailor_made_sync_cron' );
        ?>
        <div class="wrap">
            <h1>Tailor Made — Ticket Tailor Integration</h1>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">

                <!-- Settings -->
                <div class="postbox" style="padding: 15px;">
                    <h2>API Settings</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields( 'tailor_made_settings' ); ?>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="tailor_made_api_key"
                                           value="<?php echo esc_attr( $api_key ); ?>"
                                           class="regular-text" id="tm-api-key" />
                                    <button type="button" class="button" onclick="
                                        var el = document.getElementById('tm-api-key');
                                        el.type = el.type === 'password' ? 'text' : 'password';
                                    ">Show/Hide</button>
                                    <p class="description">Your Ticket Tailor API key (starts with sk_)</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Save Settings' ); ?>
                    </form>

                    <hr />

                    <button type="button" class="button" id="tm-test-btn">Test Connection</button>
                    <span id="tm-test-result" style="margin-left: 10px;"></span>
                </div>

                <!-- Sync Status -->
                <div class="postbox" style="padding: 15px;">
                    <h2>Sync Status</h2>
                    <table class="widefat" style="margin-bottom: 15px;">
                        <tr>
                            <td><strong>Events in WordPress</strong></td>
                            <td><?php echo esc_html( $total ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Last Sync</strong></td>
                            <td><?php echo esc_html( $last_sync ); ?></td>
                        </tr>
                        <?php if ( ! empty( $last_result ) ) : ?>
                        <tr>
                            <td><strong>Last Result</strong></td>
                            <td>
                                Created: <?php echo intval( $last_result['created'] ?? 0 ); ?>,
                                Updated: <?php echo intval( $last_result['updated'] ?? 0 ); ?>,
                                Deleted: <?php echo intval( $last_result['deleted'] ?? 0 ); ?>
                                <?php if ( ! empty( $last_result['errors'] ) ) : ?>
                                    <br><span style="color:red;">Errors: <?php echo esc_html( implode( ', ', $last_result['errors'] ) ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Next Auto-Sync</strong></td>
                            <td>
                                <?php
                                if ( $next_cron ) {
                                    echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'M j, Y g:i A' ) );
                                } else {
                                    echo 'Not scheduled';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>

                    <button type="button" class="button button-primary" id="tm-sync-btn">Sync Now</button>
                    <span id="tm-sync-result" style="margin-left: 10px;"></span>
                </div>

            </div>
        </div>

        <script>
        (function() {
            var nonce = '<?php echo wp_create_nonce( 'tailor_made_nonce' ); ?>';

            document.getElementById('tm-test-btn').addEventListener('click', function() {
                var btn = this;
                var result = document.getElementById('tm-test-result');
                btn.disabled = true;
                result.textContent = 'Testing...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=tailor_made_test_connection&nonce=' + nonce
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.success) {
                        var ov = data.data.overview;
                        result.innerHTML = '<span style="color:green;">&#10003; Connected! ' +
                            (ov ? ov.box_office_name + ' (' + ov.currency.code.toUpperCase() + ')' : '') +
                            '</span>';
                    } else {
                        result.innerHTML = '<span style="color:red;">&#10007; ' + (data.data || 'Failed') + '</span>';
                    }
                });
            });

            document.getElementById('tm-sync-btn').addEventListener('click', function() {
                var btn = this;
                var result = document.getElementById('tm-sync-result');
                btn.disabled = true;
                result.textContent = 'Syncing...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=tailor_made_sync&nonce=' + nonce
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.success) {
                        var d = data.data;
                        result.innerHTML = '<span style="color:green;">&#10003; Done! ' +
                            'Created: ' + d.created + ', Updated: ' + d.updated + ', Deleted: ' + d.deleted +
                            '</span>';
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        result.innerHTML = '<span style="color:red;">&#10007; ' + (data.data || 'Failed') + '</span>';
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
