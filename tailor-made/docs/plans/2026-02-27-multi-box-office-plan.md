# Multi-Box-Office Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Upgrade Tailor Made from single-API-key to unlimited box offices — new DB table, taxonomy, scoped sync, filterable shortcodes/Bricks, and per-box-office rosters.

**Architecture:** Custom DB table `wp_tailor_made_box_offices` stores box office configs (encrypted API keys). Custom taxonomy `tt_box_office` on `tt_event` CPT enables native WP filtering. Sync engine iterates all active box offices with scoped orphan deletion. Shortcodes/Bricks gain `box_office` filter param.

**Tech Stack:** PHP 7.4+, WordPress 5.9+, Bricks Builder, Ticket Tailor REST API v1

**Design Doc:** `docs/plans/2026-02-27-multi-box-office-design.md`

**Staging:** ts-staging.wavedepth.com (SSH: `runcloud@23.94.202.65`, plugin path: `~/webapps/TS-Staging/wp-content/plugins/tailor-made/`)

**Deploy pattern:** Edit locally, upload via SCP, test on staging.

---

## Task 1: Create Box Office Manager Class

The foundational class for CRUD operations on the `wp_tailor_made_box_offices` table.

**Files:**
- Create: `includes/class-box-office-manager.php`

**Step 1: Create the class file**

```php
<?php
/**
 * Box Office Manager — CRUD for the wp_tailor_made_box_offices table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Box_Office_Manager {

    /** @var string */
    private static $table_name;

    /**
     * Get the full table name with prefix.
     */
    public static function table_name(): string {
        global $wpdb;
        if ( ! self::$table_name ) {
            self::$table_name = $wpdb->prefix . 'tailor_made_box_offices';
        }
        return self::$table_name;
    }

    /**
     * Create the box offices table (called on plugin activation).
     */
    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(64) NOT NULL,
            api_key VARCHAR(512) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'usd',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            roster_token VARCHAR(128) DEFAULT NULL,
            last_sync DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -----------------------------------------------------------------
    // Encryption helpers
    // -----------------------------------------------------------------

    private static function get_encryption_key(): string {
        return defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'tailor-made-fallback-key';
    }

    public static function encrypt_api_key( string $plain ): string {
        $key    = hash( 'sha256', self::get_encryption_key(), true );
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( $cipher === false ) {
            return $plain; // fallback: store plain if openssl unavailable
        }
        return base64_encode( $iv . $cipher );
    }

    public static function decrypt_api_key( string $encrypted ): string {
        $key  = hash( 'sha256', self::get_encryption_key(), true );
        $data = base64_decode( $encrypted, true );
        if ( $data === false || strlen( $data ) < 17 ) {
            return $encrypted; // not encrypted, return as-is (migration compat)
        }
        $iv     = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );
        $plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return $plain !== false ? $plain : $encrypted;
    }

    // -----------------------------------------------------------------
    // CRUD
    // -----------------------------------------------------------------

    /**
     * Add a new box office.
     *
     * @return int|false  Inserted row ID or false on failure.
     */
    public static function add( string $name, string $api_key, string $currency = 'usd' ) {
        global $wpdb;

        $slug = sanitize_title( $name );

        // Ensure unique slug
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE slug = %s",
            $slug
        ) );
        if ( $existing ) {
            $slug .= '-' . wp_generate_password( 4, false, false );
        }

        $result = $wpdb->insert(
            self::table_name(),
            array(
                'name'       => sanitize_text_field( $name ),
                'slug'       => $slug,
                'api_key'    => self::encrypt_api_key( $api_key ),
                'currency'   => sanitize_key( $currency ),
                'status'     => 'active',
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( $result === false ) {
            return false;
        }

        $box_office_id = (int) $wpdb->insert_id;

        // Create matching taxonomy term
        if ( ! term_exists( $slug, 'tt_box_office' ) ) {
            wp_insert_term( $name, 'tt_box_office', array( 'slug' => $slug ) );
        }

        return $box_office_id;
    }

    /**
     * Get a single box office by ID.
     *
     * @return object|null  Row object with decrypted api_key.
     */
    public static function get( int $id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE id = %d",
            $id
        ) );

        if ( $row ) {
            $row->api_key = self::decrypt_api_key( $row->api_key );
        }

        return $row;
    }

    /**
     * Get a box office by slug.
     *
     * @return object|null
     */
    public static function get_by_slug( string $slug ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE slug = %s",
            $slug
        ) );

        if ( $row ) {
            $row->api_key = self::decrypt_api_key( $row->api_key );
        }

        return $row;
    }

    /**
     * Get all box offices.
     *
     * @param string $status  Filter by status ('active', 'paused', or 'all').
     * @return array  Array of row objects with decrypted api_keys.
     */
    public static function get_all( string $status = 'all' ): array {
        global $wpdb;

        $table = self::table_name();

        if ( $status === 'all' ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC" );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC",
                $status
            ) );
        }

        if ( $rows ) {
            foreach ( $rows as &$row ) {
                $row->api_key = self::decrypt_api_key( $row->api_key );
            }
        }

        return $rows ? $rows : array();
    }

    /**
     * Update a box office.
     *
     * @param int   $id
     * @param array $data  Keys: name, api_key, currency, status
     * @return bool
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $update = array();
        $format = array();

        if ( isset( $data['name'] ) ) {
            $update['name'] = sanitize_text_field( $data['name'] );
            $format[]       = '%s';
        }

        if ( isset( $data['api_key'] ) ) {
            $update['api_key'] = self::encrypt_api_key( $data['api_key'] );
            $format[]          = '%s';
        }

        if ( isset( $data['currency'] ) ) {
            $update['currency'] = sanitize_key( $data['currency'] );
            $format[]           = '%s';
        }

        if ( isset( $data['status'] ) ) {
            $update['status'] = sanitize_key( $data['status'] );
            $format[]         = '%s';
        }

        if ( isset( $data['last_sync'] ) ) {
            $update['last_sync'] = $data['last_sync'];
            $format[]            = '%s';
        }

        if ( isset( $data['roster_token'] ) ) {
            $update['roster_token'] = $data['roster_token'];
            $format[]               = '%s';
        }

        if ( empty( $update ) ) {
            return false;
        }

        $result = $wpdb->update(
            self::table_name(),
            $update,
            array( 'id' => $id ),
            $format,
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * Delete a box office and optionally its events.
     *
     * @param int  $id
     * @param bool $delete_events  If true, delete all associated tt_event posts.
     * @return bool
     */
    public static function delete( int $id, bool $delete_events = false ): bool {
        global $wpdb;

        $box_office = self::get( $id );
        if ( ! $box_office ) {
            return false;
        }

        if ( $delete_events ) {
            $posts = get_posts( array(
                'post_type'   => 'tt_event',
                'numberposts' => -1,
                'post_status' => 'any',
                'fields'      => 'ids',
                'meta_key'    => '_tt_box_office_id',
                'meta_value'  => $id,
            ) );

            foreach ( $posts as $post_id ) {
                wp_delete_post( $post_id, true );
            }
        } else {
            // Unassign events (remove box office meta and taxonomy term)
            $posts = get_posts( array(
                'post_type'   => 'tt_event',
                'numberposts' => -1,
                'post_status' => 'any',
                'fields'      => 'ids',
                'meta_key'    => '_tt_box_office_id',
                'meta_value'  => $id,
            ) );

            foreach ( $posts as $post_id ) {
                delete_post_meta( $post_id, '_tt_box_office_id' );
                wp_remove_object_terms( $post_id, $box_office->slug, 'tt_box_office' );
            }
        }

        // Delete taxonomy term
        $term = get_term_by( 'slug', $box_office->slug, 'tt_box_office' );
        if ( $term ) {
            wp_delete_term( $term->term_id, 'tt_box_office' );
        }

        // Delete from DB
        $result = $wpdb->delete(
            self::table_name(),
            array( 'id' => $id ),
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * Get event count for a box office.
     */
    public static function get_event_count( int $id ): int {
        $posts = get_posts( array(
            'post_type'   => 'tt_event',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
            'meta_key'    => '_tt_box_office_id',
            'meta_value'  => $id,
        ) );

        return count( $posts );
    }

    /**
     * Mask an API key for display (show first 3 and last 4 chars).
     */
    public static function mask_api_key( string $key ): string {
        if ( strlen( $key ) <= 10 ) {
            return str_repeat( '*', strlen( $key ) );
        }
        return substr( $key, 0, 3 ) . str_repeat( '*', strlen( $key ) - 7 ) . substr( $key, -4 );
    }
}
```

**Step 2: Commit**

```bash
git add includes/class-box-office-manager.php
git commit -m "feat: add Box Office Manager class with CRUD and encryption"
```

---

## Task 2: Register Taxonomy and Update Bootstrap

Update `tailor-made.php` to register the `tt_box_office` taxonomy, require the new class, and add migration logic.

**Files:**
- Modify: `tailor-made.php`
- Modify: `includes/class-cpt.php`

**Step 1: Update class-cpt.php — add taxonomy registration**

In `includes/class-cpt.php`, add a new method after the `register()` method (after line 39, before `meta_keys()`):

```php
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
```

Also add `_tt_box_office_id` to the `meta_keys()` array (after `_tt_event_id` line 63).

**Step 2: Update tailor-made.php — require new class, register taxonomy, add migration**

Add `require_once` for the new class (after line 31, before `tailor_made_init`):

```php
require_once TAILOR_MADE_DIR . 'includes/class-box-office-manager.php';
```

In `tailor_made_init()` (line 36-44), add taxonomy registration:

```php
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
```

In `tailor_made_activate()` (line 50-67), add box offices table creation and migration:

```php
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
```

Add the migration function:

```php
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
        // Already have box offices — just clean up old option
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

    // Create box office
    $box_office_id = Tailor_Made_Box_Office_Manager::add( $name, $old_key, $currency );

    if ( $box_office_id ) {
        $box_office = Tailor_Made_Box_Office_Manager::get( $box_office_id );

        // Assign all existing events to this box office
        $posts = get_posts( array(
            'post_type'   => 'tt_event',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            update_post_meta( $post_id, '_tt_box_office_id', $box_office_id );
            wp_set_object_terms( $post_id, $box_office->slug, 'tt_box_office' );
        }

        // Clean up old option
        delete_option( 'tailor_made_api_key' );
    }
}
```

Update the cron sync handler (lines 83-86) to use multi-box-office sync:

```php
add_action( 'tailor_made_sync_cron', function () {
    $engine = new Tailor_Made_Sync_Engine();
    $engine->sync_all_box_offices();
} );
```

Update version constant (line 17):

```php
define( 'TAILOR_MADE_VERSION', '2.0.0' );
```

And version header comment (line 6):

```
 * Version: 2.0.0
```

**Step 3: Commit**

```bash
git add tailor-made.php includes/class-cpt.php
git commit -m "feat: register tt_box_office taxonomy, add migration from single key"
```

---

## Task 3: Update Sync Engine for Multi-Box-Office

Modify the sync engine to iterate all active box offices, scope orphan deletion per box office, and assign taxonomy terms.

**Files:**
- Modify: `includes/class-sync-engine.php`

**Step 1: Add `sync_all_box_offices()` method and update internals**

Replace the entire `class-sync-engine.php` with the updated version. Key changes:

1. New `sync_all_box_offices()` method that iterates active box offices
2. `sync_all()` now accepts an optional `$box_office` parameter
3. `create_post()` and `update_post()` assign `_tt_box_office_id` meta + taxonomy term
4. `find_post_by_tt_id()` scoped by box office ID
5. `find_orphaned_posts()` scoped by box office ID
6. `save_meta()` includes `_tt_box_office_id`

```php
<?php
/**
 * Sync Engine — pulls Ticket Tailor data into WordPress CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Sync_Engine {

    /** @var Tailor_Made_API_Client */
    private $client;

    /** @var Tailor_Made_Sync_Logger */
    private $logger;

    /**
     * @param Tailor_Made_API_Client|null  $client
     * @param Tailor_Made_Sync_Logger|null $logger
     */
    public function __construct( $client = null, $logger = null ) {
        $this->client = $client ? $client : new Tailor_Made_API_Client();
        $this->logger = $logger ? $logger : new Tailor_Made_Sync_Logger();
    }

    /**
     * Sync all active box offices.
     *
     * @return array  Aggregated result with per-box-office breakdown.
     */
    public function sync_all_box_offices(): array {
        $box_offices = Tailor_Made_Box_Office_Manager::get_all( 'active' );

        if ( empty( $box_offices ) ) {
            return array(
                'created' => 0, 'updated' => 0, 'deleted' => 0,
                'errors' => array( 'No active box offices configured.' ),
                'box_offices' => array(),
            );
        }

        $aggregated = array( 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => array(), 'box_offices' => array() );

        foreach ( $box_offices as $bo ) {
            $client = new Tailor_Made_API_Client( $bo->api_key );
            $logger = new Tailor_Made_Sync_Logger();

            $engine = new self( $client, $logger );
            $result = $engine->sync_all( $bo );

            $aggregated['created'] += $result['created'];
            $aggregated['updated'] += $result['updated'];
            $aggregated['deleted'] += $result['deleted'];
            $aggregated['errors']   = array_merge( $aggregated['errors'], $result['errors'] );
            $aggregated['box_offices'][ $bo->slug ] = array(
                'name'    => $bo->name,
                'created' => $result['created'],
                'updated' => $result['updated'],
                'deleted' => $result['deleted'],
                'errors'  => $result['errors'],
            );

            // Update last_sync on the box office row
            Tailor_Made_Box_Office_Manager::update( (int) $bo->id, array(
                'last_sync' => current_time( 'mysql' ),
            ) );
        }

        update_option( 'tailor_made_last_sync', current_time( 'mysql' ) );
        update_option( 'tailor_made_last_sync_result', $aggregated );

        return $aggregated;
    }

    /**
     * Sync all events from Ticket Tailor for a single box office.
     *
     * @param object|null $box_office  Box office row object. Null for legacy single-key mode.
     * @return array
     */
    public function sync_all( $box_office = null ) {
        $result = array( 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => array() );

        $bo_id   = $box_office ? (int) $box_office->id : 0;
        $bo_name = $box_office ? $box_office->name : 'Default';
        $bo_slug = $box_office ? $box_office->slug : '';

        $this->logger->info( 'start', sprintf( 'Sync started for box office: %s', $bo_name ), array(
            'details' => array( 'box_office_id' => $bo_id, 'box_office_name' => $bo_name ),
        ) );

        $events = $this->client->get_events();
        if ( is_wp_error( $events ) ) {
            $this->logger->error( 'error', sprintf( '[%s] API fetch failed: %s', $bo_name, $events->get_error_message() ), array(
                'details' => $events->get_error_data(),
            ) );
            $result['errors'][] = sprintf( '[%s] %s', $bo_name, $events->get_error_message() );
            return $result;
        }

        $this->logger->info( 'fetched', sprintf( '[%s] Fetched %d events from API', $bo_name, count( $events ) ) );

        $synced_tt_ids = array();

        foreach ( $events as $event ) {
            $tt_id = isset( $event['id'] ) ? $event['id'] : '';
            if ( empty( $tt_id ) ) {
                continue;
            }

            $synced_tt_ids[] = $tt_id;
            $event_name      = isset( $event['name'] ) ? $event['name'] : 'Untitled Event';
            $existing        = $this->find_post_by_tt_id( $tt_id, $bo_id );

            if ( $existing ) {
                $this->update_post( $existing->ID, $event, $bo_id, $bo_slug );
                $result['updated']++;

                $this->logger->info( 'updated', '[' . $bo_name . '] Updated: ' . $event_name, array(
                    'tt_event_id' => $tt_id,
                    'event_name'  => $event_name,
                ) );
            } else {
                $this->create_post( $event, $bo_id, $bo_slug );
                $result['created']++;

                $this->logger->info( 'created', '[' . $bo_name . '] Created: ' . $event_name, array(
                    'tt_event_id' => $tt_id,
                    'event_name'  => $event_name,
                ) );
            }
        }

        // Scoped orphan deletion — only delete posts belonging to THIS box office
        $orphans = $this->find_orphaned_posts( $synced_tt_ids, $bo_id );

        if ( empty( $synced_tt_ids ) && ! empty( $orphans ) ) {
            $this->logger->warning( 'skipped_delete', sprintf(
                '[%s] API returned 0 events but %d WP posts exist — skipping orphan deletion',
                $bo_name, count( $orphans )
            ) );
        } else {
            foreach ( $orphans as $orphan_id ) {
                $orphan_name  = get_the_title( $orphan_id );
                $orphan_tt_id = get_post_meta( $orphan_id, '_tt_event_id', true );

                wp_delete_post( $orphan_id, true );
                $result['deleted']++;

                $this->logger->warning( 'deleted', '[' . $bo_name . '] Deleted orphan: ' . $orphan_name, array(
                    'tt_event_id' => $orphan_tt_id,
                    'event_name'  => $orphan_name,
                ) );
            }
        }

        $this->logger->info( 'end', sprintf(
            '[%s] Sync completed — Created: %d, Updated: %d, Deleted: %d',
            $bo_name, $result['created'], $result['updated'], $result['deleted']
        ), array( 'details' => $result ) );

        return $result;
    }

    /**
     * Find a post by TT event ID, scoped to a box office.
     *
     * @return WP_Post|null
     */
    private function find_post_by_tt_id( string $tt_id, int $bo_id = 0 ) {
        $args = array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => 1,
            'post_status' => 'any',
            'meta_query'  => array(
                array(
                    'key'   => '_tt_event_id',
                    'value' => $tt_id,
                ),
            ),
        );

        if ( $bo_id > 0 ) {
            $args['meta_query'][] = array(
                'key'   => '_tt_box_office_id',
                'value' => $bo_id,
            );
        }

        $posts = get_posts( $args );
        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Find orphaned posts for a specific box office.
     *
     * @return array  Post IDs.
     */
    private function find_orphaned_posts( array $active_tt_ids, int $bo_id = 0 ): array {
        $args = array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        );

        if ( $bo_id > 0 ) {
            $args['meta_key']   = '_tt_box_office_id';
            $args['meta_value'] = $bo_id;
        }

        $all_posts = get_posts( $args );

        $orphans = array();
        foreach ( $all_posts as $post_id ) {
            $tt_id = get_post_meta( $post_id, '_tt_event_id', true );
            if ( $tt_id && ! in_array( $tt_id, $active_tt_ids, true ) ) {
                $orphans[] = $post_id;
            }
        }

        return $orphans;
    }

    /**
     * Create a new event post.
     */
    private function create_post( array $event, int $bo_id = 0, string $bo_slug = '' ): int {
        $name   = isset( $event['name'] ) ? $event['name'] : 'Untitled Event';
        $desc   = isset( $event['description'] ) ? $event['description'] : '';
        $status = isset( $event['status'] ) ? $event['status'] : 'draft';
        $created = isset( $event['created_at'] ) ? $event['created_at'] : 0;

        $post_id = wp_insert_post( array(
            'post_type'    => Tailor_Made_CPT::POST_TYPE,
            'post_title'   => $name,
            'post_content' => wp_kses_post( $desc ),
            'post_status'  => $this->map_status( $status ),
            'post_date'    => $this->unix_to_wp_date( $created ),
        ) );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        $this->save_meta( $post_id, $event, $bo_id );
        $this->assign_box_office( $post_id, $bo_id, $bo_slug );
        $this->maybe_set_featured_image( $post_id, $event );

        return $post_id;
    }

    /**
     * Update an existing event post.
     */
    private function update_post( int $post_id, array $event, int $bo_id = 0, string $bo_slug = '' ): void {
        $name   = isset( $event['name'] ) ? $event['name'] : 'Untitled Event';
        $desc   = isset( $event['description'] ) ? $event['description'] : '';
        $status = isset( $event['status'] ) ? $event['status'] : 'draft';

        wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => $name,
            'post_content' => wp_kses_post( $desc ),
            'post_status'  => $this->map_status( $status ),
        ) );

        $this->save_meta( $post_id, $event, $bo_id );
        $this->assign_box_office( $post_id, $bo_id, $bo_slug );
        $this->maybe_set_featured_image( $post_id, $event );
    }

    /**
     * Assign box office taxonomy term and meta to a post.
     */
    private function assign_box_office( int $post_id, int $bo_id, string $bo_slug ): void {
        if ( $bo_id > 0 ) {
            update_post_meta( $post_id, '_tt_box_office_id', $bo_id );
        }
        if ( $bo_slug ) {
            wp_set_object_terms( $post_id, $bo_slug, 'tt_box_office' );
        }
    }

    private function save_meta( int $post_id, array $event, int $bo_id = 0 ): void {
        $ticket_types = isset( $event['ticket_types'] ) ? $event['ticket_types'] : array();
        $prices       = array_column( $ticket_types, 'price' );
        $quantities   = array_column( $ticket_types, 'quantity_total' );
        $issued       = array_column( $ticket_types, 'quantity_issued' );

        $start = isset( $event['start'] ) ? $event['start'] : array();
        $end   = isset( $event['end'] ) ? $event['end'] : array();
        $venue = isset( $event['venue'] ) ? $event['venue'] : array();
        $imgs  = isset( $event['images'] ) ? $event['images'] : array();

        $meta = array(
            '_tt_event_id'             => isset( $event['id'] ) ? $event['id'] : '',
            '_tt_event_series_id'      => isset( $event['event_series_id'] ) ? $event['event_series_id'] : '',
            '_tt_status'               => isset( $event['status'] ) ? $event['status'] : '',
            '_tt_currency'             => isset( $event['currency'] ) ? $event['currency'] : 'usd',
            '_tt_start_date'           => isset( $start['date'] ) ? $start['date'] : '',
            '_tt_start_time'           => isset( $start['time'] ) ? $start['time'] : '',
            '_tt_start_formatted'      => isset( $start['formatted'] ) ? $start['formatted'] : '',
            '_tt_start_iso'            => isset( $start['iso'] ) ? $start['iso'] : '',
            '_tt_start_unix'           => isset( $start['unix'] ) ? $start['unix'] : 0,
            '_tt_end_date'             => isset( $end['date'] ) ? $end['date'] : '',
            '_tt_end_time'             => isset( $end['time'] ) ? $end['time'] : '',
            '_tt_end_formatted'        => isset( $end['formatted'] ) ? $end['formatted'] : '',
            '_tt_end_iso'              => isset( $end['iso'] ) ? $end['iso'] : '',
            '_tt_end_unix'             => isset( $end['unix'] ) ? $end['unix'] : 0,
            '_tt_timezone'             => isset( $event['timezone'] ) ? $event['timezone'] : '',
            '_tt_venue_name'           => isset( $venue['name'] ) ? $venue['name'] : '',
            '_tt_venue_country'        => isset( $venue['country'] ) ? $venue['country'] : '',
            '_tt_venue_postal_code'    => isset( $venue['postal_code'] ) ? $venue['postal_code'] : '',
            '_tt_image_header'         => isset( $imgs['header'] ) ? esc_url_raw( $imgs['header'] ) : '',
            '_tt_image_thumbnail'      => isset( $imgs['thumbnail'] ) ? esc_url_raw( $imgs['thumbnail'] ) : '',
            '_tt_checkout_url'         => isset( $event['checkout_url'] ) ? esc_url_raw( $event['checkout_url'] ) : '',
            '_tt_event_url'            => isset( $event['url'] ) ? esc_url_raw( $event['url'] ) : '',
            '_tt_call_to_action'       => isset( $event['call_to_action'] ) ? $event['call_to_action'] : '',
            '_tt_online_event'         => isset( $event['online_event'] ) ? $event['online_event'] : 'false',
            '_tt_private'              => isset( $event['private'] ) ? $event['private'] : 'false',
            '_tt_hidden'               => isset( $event['hidden'] ) ? $event['hidden'] : 'false',
            '_tt_tickets_available'    => isset( $event['tickets_available'] ) ? $event['tickets_available'] : 'false',
            '_tt_revenue'              => isset( $event['revenue'] ) ? $event['revenue'] : 0,
            '_tt_total_orders'         => isset( $event['total_orders'] ) ? $event['total_orders'] : 0,
            '_tt_total_issued_tickets' => isset( $event['total_issued_tickets'] ) ? $event['total_issued_tickets'] : 0,
            '_tt_ticket_types'         => wp_json_encode( $ticket_types ),
            '_tt_min_price'            => ! empty( $prices ) ? min( $prices ) : 0,
            '_tt_max_price'            => ! empty( $prices ) ? max( $prices ) : 0,
            '_tt_price_display'        => $this->format_price_range( $prices ),
            '_tt_total_capacity'       => array_sum( $quantities ),
            '_tt_tickets_remaining'    => array_sum( $quantities ) - array_sum( $issued ),
            '_tt_last_synced'          => current_time( 'mysql' ),
            '_tt_raw_json'             => wp_json_encode( $event ),
            '_tt_box_office_id'        => $bo_id,
        );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    private function maybe_set_featured_image( int $post_id, array $event ): void {
        $imgs      = isset( $event['images'] ) ? $event['images'] : array();
        $image_url = isset( $imgs['header'] ) ? $imgs['header'] : '';
        if ( empty( $image_url ) ) {
            return;
        }

        if ( ! $this->is_allowed_image_url( $image_url ) ) {
            return;
        }

        $current_url = get_post_meta( $post_id, '_tt_image_header_source', true );
        if ( $current_url === $image_url && has_post_thumbnail( $post_id ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return;
        }

        $name = isset( $event['name'] ) ? $event['name'] : 'event';
        $file_array = array(
            'name'     => sanitize_file_name( $name . '-header.jpg' ),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id, $name );
        if ( is_wp_error( $attachment_id ) ) {
            if ( file_exists( $tmp ) ) {
                unlink( $tmp );
            }
            return;
        }

        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $post_id, '_tt_image_header_source', $image_url );
    }

    private function is_allowed_image_url( string $url ): bool {
        $parsed = wp_parse_url( $url );
        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return false;
        }

        if ( strtolower( $parsed['scheme'] ) !== 'https' ) {
            return false;
        }

        $host = strtolower( $parsed['host'] );
        $allowed_domains = array( 'tickettailor.com', 'cdn.tickettailor.com' );

        foreach ( $allowed_domains as $domain ) {
            if ( $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
                return true;
            }
        }

        return false;
    }

    private function format_price_range( array $prices ): string {
        if ( empty( $prices ) ) {
            return 'Free';
        }
        $min = min( $prices );
        $max = max( $prices );
        if ( $min === 0 && $max === 0 ) {
            return 'Free';
        }
        $min_str = '$' . number_format( $min / 100, 0 );
        if ( $min === $max ) {
            return $min_str;
        }
        $max_str = '$' . number_format( $max / 100, 0 );
        return $min_str . ' - ' . $max_str;
    }

    private function map_status( string $tt_status ): string {
        switch ( $tt_status ) {
            case 'published':
            case 'live':
            case 'past':
            case 'draft':
                return 'publish';
            default:
                return 'draft';
        }
    }

    private function unix_to_wp_date( $unix ): string {
        if ( $unix <= 0 ) {
            return current_time( 'mysql' );
        }
        return gmdate( 'Y-m-d H:i:s', $unix );
    }
}
```

**Step 2: Commit**

```bash
git add includes/class-sync-engine.php
git commit -m "feat: multi-box-office sync engine with scoped orphan deletion"
```

---

## Task 4: Update Admin UI — Box Office Management

Replace the single API key form with a box office list table and add/edit/remove functionality.

**Files:**
- Modify: `includes/class-admin.php`

**Step 1: Update `register_settings()` — remove old `tailor_made_api_key` setting**

In `register_settings()` (line 55-68), remove the `tailor_made_api_key` registration. Keep `tailor_made_sync_interval` and `tailor_made_delete_events_on_uninstall`.

**Step 2: Add new AJAX handlers**

Add these new AJAX actions in `init()` (after line 26):

```php
add_action( 'wp_ajax_tailor_made_add_box_office', [ __CLASS__, 'ajax_add_box_office' ] );
add_action( 'wp_ajax_tailor_made_edit_box_office', [ __CLASS__, 'ajax_edit_box_office' ] );
add_action( 'wp_ajax_tailor_made_delete_box_office', [ __CLASS__, 'ajax_delete_box_office' ] );
add_action( 'wp_ajax_tailor_made_test_box_office', [ __CLASS__, 'ajax_test_box_office' ] );
add_action( 'wp_ajax_tailor_made_toggle_box_office', [ __CLASS__, 'ajax_toggle_box_office' ] );
```

**Step 3: Implement the AJAX handlers**

```php
public static function ajax_add_box_office(): void {
    check_ajax_referer( 'tailor_made_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
    if ( empty( $api_key ) ) {
        wp_send_json_error( 'API key is required.' );
    }

    // Test the key first
    $client   = new Tailor_Made_API_Client( $api_key );
    $overview = $client->overview();
    if ( is_wp_error( $overview ) ) {
        wp_send_json_error( 'API connection failed: ' . $overview->get_error_message() );
    }

    $name     = isset( $overview['box_office_name'] ) ? $overview['box_office_name'] : 'Unknown Box Office';
    $currency = isset( $overview['currency']['code'] ) ? $overview['currency']['code'] : 'usd';

    $id = Tailor_Made_Box_Office_Manager::add( $name, $api_key, $currency );
    if ( ! $id ) {
        wp_send_json_error( 'Failed to save box office.' );
    }

    wp_send_json_success( array(
        'id'       => $id,
        'name'     => $name,
        'currency' => $currency,
        'message'  => sprintf( 'Added: %s (%s)', $name, strtoupper( $currency ) ),
    ) );
}

public static function ajax_edit_box_office(): void {
    check_ajax_referer( 'tailor_made_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $id   = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';

    if ( ! $id ) {
        wp_send_json_error( 'Invalid box office ID.' );
    }

    $data = array();
    if ( $name ) {
        $data['name'] = $name;
    }

    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
    if ( $api_key ) {
        // Test new key before saving
        $client = new Tailor_Made_API_Client( $api_key );
        $ping   = $client->ping();
        if ( is_wp_error( $ping ) ) {
            wp_send_json_error( 'New API key failed: ' . $ping->get_error_message() );
        }
        $data['api_key'] = $api_key;
    }

    if ( empty( $data ) ) {
        wp_send_json_error( 'Nothing to update.' );
    }

    Tailor_Made_Box_Office_Manager::update( $id, $data );
    wp_send_json_success( 'Box office updated.' );
}

public static function ajax_delete_box_office(): void {
    check_ajax_referer( 'tailor_made_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $id            = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
    $delete_events = isset( $_POST['delete_events'] ) && $_POST['delete_events'] === '1';

    if ( ! $id ) {
        wp_send_json_error( 'Invalid box office ID.' );
    }

    $result = Tailor_Made_Box_Office_Manager::delete( $id, $delete_events );
    if ( $result ) {
        wp_send_json_success( 'Box office removed.' );
    } else {
        wp_send_json_error( 'Failed to remove box office.' );
    }
}

public static function ajax_test_box_office(): void {
    check_ajax_referer( 'tailor_made_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $id = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
    if ( ! $id ) {
        wp_send_json_error( 'Invalid box office ID.' );
    }

    $bo = Tailor_Made_Box_Office_Manager::get( $id );
    if ( ! $bo ) {
        wp_send_json_error( 'Box office not found.' );
    }

    $client   = new Tailor_Made_API_Client( $bo->api_key );
    $overview = $client->overview();

    if ( is_wp_error( $overview ) ) {
        wp_send_json_error( $overview->get_error_message() );
    }

    wp_send_json_success( array( 'overview' => $overview ) );
}

public static function ajax_toggle_box_office(): void {
    check_ajax_referer( 'tailor_made_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $id = isset( $_POST['box_office_id'] ) ? absint( $_POST['box_office_id'] ) : 0;
    if ( ! $id ) {
        wp_send_json_error( 'Invalid box office ID.' );
    }

    $bo = Tailor_Made_Box_Office_Manager::get( $id );
    if ( ! $bo ) {
        wp_send_json_error( 'Box office not found.' );
    }

    $new_status = $bo->status === 'active' ? 'paused' : 'active';
    Tailor_Made_Box_Office_Manager::update( $id, array( 'status' => $new_status ) );

    wp_send_json_success( array( 'status' => $new_status ) );
}
```

**Step 4: Update `ajax_sync()` to use multi-box-office sync**

Replace `ajax_sync()` (lines 74-85):

```php
public static function ajax_sync(): void {
    check_ajax_referer( 'tailor_made_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $engine = new Tailor_Made_Sync_Engine();
    $result = $engine->sync_all_box_offices();

    wp_send_json_success( $result );
}
```

**Step 5: Update `ajax_test_connection()` — remove (replaced by per-box-office test)**

Keep it for backward compat but have it test the first active box office:

```php
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
```

**Step 6: Rewrite `render_tab_dashboard()` with box office management**

Replace the dashboard tab rendering. The new layout includes:
1. Box office list table at the top
2. Add box office form
3. Sync controls below (unchanged logic, updated display for per-box-office results)

This is a large HTML/PHP/JS replacement of `render_tab_dashboard()`. The key UI elements:

- Table with columns: Name, Slug, Currency, Events, Last Sync, Status, Actions
- "Add Box Office" section with API key input + "Test & Add" button
- Sync Now button and per-box-office result display
- Remove the old single API key form entirely

**Step 7: Update the sync result display in dashboard JS**

Update the Sync Now button handler to show per-box-office breakdown:

```javascript
if (data.success) {
    var d = data.data;
    var html = '<span style="color:green;">&#10003; Done! ';
    html += 'Total — Created: ' + d.created + ', Updated: ' + d.updated + ', Deleted: ' + d.deleted;
    if (d.box_offices) {
        html += '<br>';
        for (var slug in d.box_offices) {
            var bo = d.box_offices[slug];
            html += bo.name + ': ' + bo.created + 'C/' + bo.updated + 'U/' + bo.deleted + 'D &nbsp;';
        }
    }
    html += '</span>';
    result.innerHTML = html;
    setTimeout(function() { location.reload(); }, 2000);
}
```

**Step 8: Commit**

```bash
git add includes/class-admin.php
git commit -m "feat: admin UI for multi-box-office management"
```

---

## Task 5: Update Shortcodes — Add box_office Filter

Add the `box_office` parameter to `[tt_events]`, `[tt_upcoming_count]`, and a new `box_office_name` field to `[tt_event_field]`.

**Files:**
- Modify: `includes/class-shortcodes.php`

**Step 1: Update `shortcode_events()` — add box_office attribute**

In `shortcode_atts` (line 69-78), add:

```php
'box_office' => '',
```

After building `$query_args` (line 80-87), add taxonomy query:

```php
if ( ! empty( $atts['box_office'] ) ) {
    $slugs = array_map( 'sanitize_key', explode( ',', $atts['box_office'] ) );
    $query_args['tax_query'] = array(
        array(
            'taxonomy' => 'tt_box_office',
            'field'    => 'slug',
            'terms'    => $slugs,
        ),
    );
}
```

**Step 2: Update `render_card()` — add box office CSS class**

In `render_card()` (line 224-296), update the card wrapper to include box office slug:

```php
private static function render_card( WP_Post $post, array $show_fields ): string {
    $bo_slug = '';
    $terms = wp_get_post_terms( $post->ID, 'tt_box_office', array( 'fields' => 'slugs' ) );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        $bo_slug = $terms[0];
    }

    $bo_class = $bo_slug ? ' tt-box-office-' . esc_attr( $bo_slug ) : '';
    $html = '<div class="tt-event-card' . $bo_class . '">';
    // ... rest unchanged
```

**Step 3: Update `shortcode_event_field()` — add `box_office_name` field**

In `shortcode_event_field()` (line 153-197), add handling for the virtual `box_office_name` field before the meta lookup:

```php
// Handle virtual fields
if ( $field === 'box_office_name' ) {
    $terms = wp_get_post_terms( $post_id, 'tt_box_office', array( 'fields' => 'names' ) );
    $value = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : '';
    if ( empty( $value ) ) {
        return '';
    }
    return '<span class="tt-field tt-field--box_office_name">' . esc_html( $value ) . '</span>';
}
```

**Step 4: Update `shortcode_upcoming_count()` — add box_office attribute**

Replace `shortcode_upcoming_count()`:

```php
public static function shortcode_upcoming_count( $atts ): string {
    $atts = shortcode_atts( array(
        'box_office' => '',
    ), $atts, 'tt_upcoming_count' );

    $count = self::get_upcoming_count( $atts['box_office'] );
    return '<span class="tt-upcoming-count">' . esc_html( $count ) . '</span>';
}
```

Update `get_upcoming_count()` to accept a box_office filter:

```php
private static function get_upcoming_count( string $box_office = '' ): int {
    $args = array(
        'post_type'      => Tailor_Made_CPT::POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_tt_start_unix',
                'value'   => time(),
                'compare' => '>',
                'type'    => 'NUMERIC',
            ),
        ),
    );

    if ( ! empty( $box_office ) ) {
        $slugs = array_map( 'sanitize_key', explode( ',', $box_office ) );
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'tt_box_office',
                'field'    => 'slug',
                'terms'    => $slugs,
            ),
        );
    }

    $posts = get_posts( $args );
    return count( $posts );
}
```

**Step 5: Commit**

```bash
git add includes/class-shortcodes.php
git commit -m "feat: add box_office filter to shortcodes"
```

---

## Task 6: Update Bricks Provider — New Dynamic Tags

Add `{tt_box_office_name}` and `{tt_box_office_slug}` dynamic data tags.

**Files:**
- Modify: `includes/class-bricks-provider.php`

**Step 1: Add new tags to `get_tags()` array**

Add these two entries to the end of the tags array (inside `get_tags()`, after line 48):

```php
'tt_box_office_name' => array( 'label' => __( 'TT Box Office Name', 'tailor-made' ),  'group' => $group, 'meta' => null, 'type' => 'text' ),
'tt_box_office_slug' => array( 'label' => __( 'TT Box Office Slug', 'tailor-made' ),  'group' => $group, 'meta' => null, 'type' => 'text' ),
```

**Step 2: Add rendering logic in `render_tag()`**

After the `tt_event_description` block (line 114-118), add:

```php
if ( $name === 'tt_box_office_name' ) {
    $terms = wp_get_post_terms( $post_id, 'tt_box_office', array( 'fields' => 'names' ) );
    return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? esc_html( $terms[0] ) : '';
}

if ( $name === 'tt_box_office_slug' ) {
    $terms = wp_get_post_terms( $post_id, 'tt_box_office', array( 'fields' => 'slugs' ) );
    return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? esc_html( $terms[0] ) : '';
}
```

**Step 3: Commit**

```bash
git add includes/class-bricks-provider.php
git commit -m "feat: add box office name/slug Bricks dynamic tags"
```

---

## Task 7: Update Magic Links — Use Correct API Key Per Box Office

Ensure roster data fetches use the correct box office's API key, and add per-box-office roster support.

**Files:**
- Modify: `includes/class-magic-links.php`

**Step 1: Update `get_roster_data()` to use correct API key**

In `get_roster_data()` (line 111-206), replace the API client creation (line 130):

```php
// OLD: $client = new Tailor_Made_API_Client();
// NEW: Look up the correct API key for this event's box office
$bo_id = get_post_meta( $post_id, '_tt_box_office_id', true );
$api_key = null;
if ( $bo_id ) {
    $bo = Tailor_Made_Box_Office_Manager::get( (int) $bo_id );
    if ( $bo ) {
        $api_key = $bo->api_key;
    }
}
$client = new Tailor_Made_API_Client( $api_key );
```

**Step 2: Add box office roster shortcode**

Add new shortcode registration in `init()`:

```php
add_shortcode( 'tt_roster_box_office', [ __CLASS__, 'shortcode_roster_box_office' ] );
```

Add the handler method:

```php
/**
 * Render a per-box-office roster — shows all events and attendees for a box office.
 */
public static function shortcode_roster_box_office( $atts ): string {
    $token = isset( $_GET['box_office_token'] ) ? preg_replace( '/[^a-f0-9]/', '', $_GET['box_office_token'] ) : '';

    if ( empty( $token ) || strlen( $token ) < 32 ) {
        return self::render_error( __( 'Invalid or expired link.', 'tailor-made' ) );
    }

    // Find box office by roster_token
    global $wpdb;
    $bo = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . Tailor_Made_Box_Office_Manager::table_name() . " WHERE roster_token = %s AND status = 'active'",
        $token
    ) );

    if ( ! $bo ) {
        return self::render_error( __( 'Invalid or expired link.', 'tailor-made' ) );
    }

    $bo->api_key = Tailor_Made_Box_Office_Manager::decrypt_api_key( $bo->api_key );

    self::$rendering_roster = true;

    wp_enqueue_style(
        'tailor-made-roster',
        TAILOR_MADE_URL . 'assets/css/roster.css',
        array(),
        TAILOR_MADE_VERSION
    );

    // Get all events for this box office
    $events = get_posts( array(
        'post_type'   => 'tt_event',
        'numberposts' => -1,
        'post_status' => 'any',
        'meta_key'    => '_tt_box_office_id',
        'meta_value'  => $bo->id,
        'orderby'     => 'meta_value_num',
        'meta_key'    => '_tt_start_unix',
        'order'       => 'ASC',
    ) );

    if ( empty( $events ) ) {
        return self::render_error( __( 'No events found for this box office.', 'tailor-made' ) );
    }

    // Fetch roster data for each event
    $all_rosters = array();
    $total_attendees = 0;

    foreach ( $events as $event_post ) {
        $roster = self::get_roster_data( $event_post->ID );
        if ( ! is_wp_error( $roster ) ) {
            $all_rosters[] = $roster;
            $total_attendees += $roster['total'];
        }
    }

    return self::render_box_office_roster( $bo->name, $all_rosters, $total_attendees );
}

/**
 * Render the box office roster HTML (grouped by event).
 */
private static function render_box_office_roster( string $bo_name, array $rosters, int $total ): string {
    ob_start();
    ?>
    <div class="tt-roster">
        <div class="tt-roster__header">
            <h2 class="tt-roster__title"><?php echo esc_html( $bo_name ); ?> — All Events Roster</h2>
            <p>Total attendees across all events: <strong><?php echo esc_html( $total ); ?></strong></p>
        </div>

        <input type="text" class="tt-roster__search" placeholder="<?php esc_attr_e( 'Search all attendees...', 'tailor-made' ); ?>" />

        <?php foreach ( $rosters as $roster ) : ?>
            <div class="tt-roster__event-section" style="margin-top: 30px;">
                <h3><?php echo esc_html( $roster['event_name'] ); ?></h3>
                <p>
                    <?php echo esc_html( $roster['event_date'] ); ?>
                    <?php if ( $roster['venue'] ) : ?> — <?php echo esc_html( $roster['venue'] ); ?><?php endif; ?>
                    &bull; <?php echo esc_html( $roster['total'] ); ?> attendees
                </p>

                <div class="tt-roster__table-wrap">
                    <table class="tt-roster__table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'tailor-made' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'tailor-made' ); ?></th>
                                <th><?php esc_html_e( 'Ticket', 'tailor-made' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'tailor-made' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $roster['attendees'] ) ) : ?>
                                <tr><td colspan="4"><?php esc_html_e( 'No attendees yet.', 'tailor-made' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $roster['attendees'] as $a ) : ?>
                                <tr>
                                    <td><?php echo esc_html( trim( $a['first_name'] . ' ' . $a['last_name'] ) ); ?></td>
                                    <td><?php echo esc_html( $a['email'] ); ?></td>
                                    <td><?php echo esc_html( $a['ticket_type'] ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $a['status'] === 'void' ? 'voided' : $a['status'] ) ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    (function() {
        var search = document.querySelector('.tt-roster__search');
        if (!search) return;
        var tables = document.querySelectorAll('.tt-roster__table');
        search.addEventListener('input', function() {
            var term = this.value.toLowerCase();
            tables.forEach(function(table) {
                var rows = table.querySelectorAll('tbody tr');
                for (var i = 0; i < rows.length; i++) {
                    rows[i].style.display = rows[i].textContent.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
                }
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
```

**Step 3: Commit**

```bash
git add includes/class-magic-links.php
git commit -m "feat: per-box-office rosters and correct API key lookup"
```

---

## Task 8: Update Uninstall Handler

Clean up the new table and taxonomy on uninstall.

**Files:**
- Modify: `uninstall.php`

**Step 1: Update options list and add box offices table cleanup**

Add to the options array (line 13-20):

```php
// Remove tailor_made_api_key from the list (no longer used)
// Add new option if any were added
```

After the sync log table drop (line 29), add:

```php
// Drop the box offices table.
$bo_table = $wpdb->prefix . 'tailor_made_box_offices';
$wpdb->query( "DROP TABLE IF EXISTS {$bo_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
```

Remove `tailor_made_api_key` from the options list since it's been migrated.

**Step 2: Commit**

```bash
git add uninstall.php
git commit -m "feat: clean up box offices table on uninstall"
```

---

## Task 9: Update Sync Logger — Add Box Office Context

Add box office name to log entries for filtering.

**Files:**
- Modify: `includes/class-sync-logger.php`

**Step 1: Add `box_office_name` column to the table**

In `create_table()`, add a new column after `event_name`:

```sql
box_office_name VARCHAR(255) NOT NULL DEFAULT '',
```

And add an index: `KEY idx_box_office (box_office_name)`

**Step 2: Update `log()` method to include box_office_name**

Add `box_office_name` to the insert data:

```php
'box_office_name' => isset( $extra['box_office_name'] ) ? $extra['box_office_name'] : '',
```

**Step 3: Commit**

```bash
git add includes/class-sync-logger.php
git commit -m "feat: add box office name to sync log entries"
```

---

## Task 10: Deploy and Test on Staging

Upload all files to staging, activate, and verify the migration and sync work.

**Step 1: Upload all changed files via SCP**

```bash
scp "includes/class-box-office-manager.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "tailor-made.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/
scp "includes/class-cpt.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "includes/class-sync-engine.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "includes/class-admin.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "includes/class-shortcodes.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "includes/class-bricks-provider.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "includes/class-magic-links.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "includes/class-sync-logger.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/includes/
scp "uninstall.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/
```

**Step 2: Deactivate and reactivate to trigger migration**

```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp plugin deactivate tailor-made && wp plugin activate tailor-made"
```

**Step 3: Verify migration**

```bash
# Check box offices table was created and populated
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp db query 'SELECT id, name, slug, currency, status FROM wp_tailor_made_box_offices'"

# Check existing events were assigned to the migrated box office
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp post list --post_type=tt_event --fields=ID,post_title --format=table"

# Check taxonomy terms exist
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp term list tt_box_office --fields=term_id,name,slug"

# Check old API key option was removed
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp option get tailor_made_api_key || echo 'REMOVED (correct)'"
```

**Step 4: Add the second box office via admin UI**

Navigate to ts-staging.wavedepth.com/wp-admin/?page=tailor-made and use the "Add Box Office" form with the Tayseer Travel API key.

**Step 5: Run a sync and verify both box offices' events appear**

Click "Sync All" in the admin dashboard. Verify:
- Tayseer Seminary events are tagged with `tayseer-seminary` taxonomy term
- Tayseer Travel events (if any) are tagged with `tayseer-travel`
- No orphan deletion across box offices

**Step 6: Test shortcodes**

```
[tt_events]                                          → shows all events
[tt_events box_office="tayseer-seminary"]            → shows only Seminary events
[tt_upcoming_count]                                  → counts all
[tt_upcoming_count box_office="tayseer-seminary"]    → counts Seminary only
[tt_event_field field="box_office_name"]             → shows box office name
```

**Step 7: Commit all changes**

```bash
git add -A
git commit -m "feat: multi-box-office support v2.0.0

- New wp_tailor_made_box_offices DB table with encrypted API keys
- tt_box_office taxonomy for native WP filtering
- Sync engine iterates all active box offices with scoped orphan deletion
- Admin UI: box office list table, add/edit/remove, per-box-office test
- Shortcodes: box_office filter param on [tt_events] and [tt_upcoming_count]
- Bricks: {tt_box_office_name} and {tt_box_office_slug} dynamic tags
- Magic links: correct API key per box office, new [tt_roster_box_office]
- Migration: auto-migrates single API key to box offices table on upgrade"
```

---

## Task Summary

| # | Task | Files | Est. |
|---|------|-------|------|
| 1 | Box Office Manager class | New: class-box-office-manager.php | Core |
| 2 | Taxonomy + bootstrap + migration | tailor-made.php, class-cpt.php | Core |
| 3 | Multi-box-office sync engine | class-sync-engine.php | Core |
| 4 | Admin UI for box office management | class-admin.php | Large |
| 5 | Shortcode box_office filter | class-shortcodes.php | Medium |
| 6 | Bricks dynamic tags | class-bricks-provider.php | Small |
| 7 | Magic links per-box-office | class-magic-links.php | Medium |
| 8 | Uninstall cleanup | uninstall.php | Small |
| 9 | Sync logger box office context | class-sync-logger.php | Small |
| 10 | Deploy + test on staging | All files | Testing |
