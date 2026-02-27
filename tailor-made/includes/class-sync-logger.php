<?php
/**
 * Sync Logger — DB-backed logging for sync operations.
 *
 * No-op when disabled; zero performance impact.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Sync_Logger {

    /** @var string */
    private static $table_name;

    /** @var string Current sync run ID */
    private $sync_id;

    /** @var bool */
    private $enabled;

    /**
     * Get the full table name.
     */
    public static function table_name() {
        global $wpdb;
        if ( ! self::$table_name ) {
            self::$table_name = $wpdb->prefix . 'tailor_made_sync_log';
        }
        return self::$table_name;
    }

    /**
     * Create the log table (called on plugin activation).
     */
    public static function create_table() {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_id VARCHAR(36) NOT NULL DEFAULT '',
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            action VARCHAR(20) NOT NULL DEFAULT '',
            tt_event_id VARCHAR(32) NOT NULL DEFAULT '',
            event_name VARCHAR(255) NOT NULL DEFAULT '',
            box_office_name VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            details LONGTEXT,
            PRIMARY KEY (id),
            KEY idx_sync_id (sync_id),
            KEY idx_timestamp (timestamp),
            KEY idx_tt_event_id (tt_event_id),
            KEY idx_box_office (box_office_name)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * @param string|null $sync_id  Optional sync run ID; auto-generated if null.
     */
    public function __construct( $sync_id = null ) {
        $this->enabled = (bool) get_option( 'tailor_made_logging_enabled', false );
        $this->sync_id = $sync_id ? $sync_id : wp_generate_uuid4();
    }

    /**
     * Get current sync run ID.
     *
     * @return string
     */
    public function get_sync_id() {
        return $this->sync_id;
    }

    /**
     * Log an entry. No-op when logging is disabled.
     *
     * @param string $level      info|warning|error
     * @param string $action     start|end|fetched|created|updated|deleted|error
     * @param string $message    Human-readable description
     * @param array  $extra      Optional: tt_event_id, event_name, details
     */
    public function log( $level, $action, $message, $extra = array() ) {
        if ( ! $this->enabled ) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            self::table_name(),
            array(
                'sync_id'     => $this->sync_id,
                'timestamp'   => current_time( 'mysql' ),
                'level'       => $level,
                'action'      => $action,
                'tt_event_id' => isset( $extra['tt_event_id'] ) ? $extra['tt_event_id'] : '',
                'event_name'      => isset( $extra['event_name'] ) ? $extra['event_name'] : '',
                'box_office_name' => isset( $extra['box_office_name'] ) ? $extra['box_office_name'] : '',
                'message'         => $message,
                'details'     => isset( $extra['details'] ) ? wp_json_encode( $extra['details'] ) : null,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Convenience: log info.
     */
    public function info( $action, $message, $extra = array() ) {
        $this->log( 'info', $action, $message, $extra );
    }

    /**
     * Convenience: log warning.
     */
    public function warning( $action, $message, $extra = array() ) {
        $this->log( 'warning', $action, $message, $extra );
    }

    /**
     * Convenience: log error.
     */
    public function error( $action, $message, $extra = array() ) {
        $this->log( 'error', $action, $message, $extra );
    }

    /**
     * Get paginated log entries.
     *
     * @param int         $page     1-based page number.
     * @param int         $per_page Items per page.
     * @param string|null $sync_id  Filter to specific sync run.
     * @return array { entries: array, total: int, pages: int }
     */
    public static function get_entries( $page = 1, $per_page = 50, $sync_id = null ) {
        global $wpdb;

        $table  = self::table_name();
        $where  = '';
        $params = array();

        if ( $sync_id ) {
            $where    = 'WHERE sync_id = %s';
            $params[] = $sync_id;
        }

        // Total count
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
        $total     = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : (int) $wpdb->get_var( $count_sql );

        $pages  = max( 1, (int) ceil( $total / $per_page ) );
        $offset = max( 0, ( $page - 1 ) * $per_page );

        // Fetch entries
        $query_params   = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $entries_sql = "SELECT * FROM {$table} {$where} ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d";
        $entries     = $wpdb->get_results( $wpdb->prepare( $entries_sql, $query_params ), ARRAY_A );

        return array(
            'entries' => $entries ? $entries : array(),
            'total'   => $total,
            'pages'   => $pages,
        );
    }

    /**
     * Delete all log entries.
     */
    public static function clear_all() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE " . self::table_name() );
    }

    /**
     * Purge entries older than the retention period.
     */
    public static function purge_old_entries() {
        global $wpdb;

        $days = (int) get_option( 'tailor_made_log_retention_days', 30 );
        if ( $days < 1 ) {
            $days = 30;
        }

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table_name() . " WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    /**
     * Get distinct sync runs for the log viewer.
     *
     * @param int $limit
     * @return array
     */
    public static function get_sync_runs( $limit = 50 ) {
        global $wpdb;

        $table = self::table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT sync_id, MIN(timestamp) AS started, MAX(timestamp) AS ended, COUNT(*) AS entry_count
             FROM {$table}
             GROUP BY sync_id
             ORDER BY started DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );
    }
}
