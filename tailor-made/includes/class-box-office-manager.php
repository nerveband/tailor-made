<?php
/**
 * Box Office Manager — CRUD operations for the box offices table.
 *
 * Provides data layer for managing multiple Ticket Tailor box offices,
 * including API key encryption, taxonomy term management, and helpers.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Box_Office_Manager {

    /** @var string Cached full table name. */
    private static $table_name;

    /** @var string Taxonomy name for box office terms. */
    const TAXONOMY = 'tt_box_office';

    /** @var string Encryption cipher. */
    const CIPHER = 'aes-256-cbc';

    /* ------------------------------------------------------------------
     * Table
     * ----------------------------------------------------------------*/

    /**
     * Get the full table name with prefix.
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        if ( ! self::$table_name ) {
            self::$table_name = $wpdb->prefix . 'tailor_made_box_offices';
        }
        return self::$table_name;
    }

    /**
     * Create the box offices table (called on plugin activation).
     *
     * @return void
     */
    public static function create_table() {
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

    /* ------------------------------------------------------------------
     * Encryption helpers
     * ----------------------------------------------------------------*/

    /**
     * Derive the encryption key from WordPress AUTH_KEY.
     *
     * Falls back to a hardcoded string if AUTH_KEY is not defined,
     * which should only happen in rare misconfigured environments.
     *
     * @return string 32-byte key suitable for AES-256-CBC.
     */
    private static function get_encryption_key() {
        $source = defined( 'AUTH_KEY' ) && AUTH_KEY
            ? AUTH_KEY
            : 'tailor-made-fallback-encryption-key-do-not-use-in-production';

        return hash( 'sha256', $source, true );
    }

    /**
     * Encrypt an API key for storage.
     *
     * @param string $plain The plaintext API key.
     * @return string Base64-encoded IV + ciphertext.
     */
    public static function encrypt_api_key( $plain ) {
        if ( empty( $plain ) ) {
            return '';
        }

        $key    = self::get_encryption_key();
        $iv_len = openssl_cipher_iv_length( self::CIPHER );
        $iv     = openssl_random_pseudo_bytes( $iv_len );

        $encrypted = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $encrypted ) {
            return $plain; // Fallback: store unencrypted if encryption fails.
        }

        // Prefix with 'enc:' marker so we can detect encrypted values.
        return 'enc:' . base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt an API key from storage.
     *
     * Gracefully handles unencrypted strings for migration compatibility:
     * if the value does not start with the 'enc:' marker, it is returned as-is.
     *
     * @param string $encrypted The stored (possibly encrypted) API key.
     * @return string The plaintext API key.
     */
    public static function decrypt_api_key( $encrypted ) {
        if ( empty( $encrypted ) ) {
            return '';
        }

        // If the value doesn't have our encryption marker, return as-is (migration compat).
        if ( 0 !== strpos( $encrypted, 'enc:' ) ) {
            return $encrypted;
        }

        $key    = self::get_encryption_key();
        $iv_len = openssl_cipher_iv_length( self::CIPHER );
        $raw    = base64_decode( substr( $encrypted, 4 ) ); // Strip 'enc:' prefix.

        if ( false === $raw || strlen( $raw ) < $iv_len ) {
            return ''; // Corrupted data.
        }

        $iv         = substr( $raw, 0, $iv_len );
        $ciphertext = substr( $raw, $iv_len );

        $decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        return ( false !== $decrypted ) ? $decrypted : '';
    }

    /* ------------------------------------------------------------------
     * CRUD
     * ----------------------------------------------------------------*/

    /**
     * Add a new box office.
     *
     * Inserts a row with an encrypted API key, auto-generates a unique slug
     * via sanitize_title(), and creates a matching tt_box_office taxonomy term.
     *
     * @param string $name     Display name for the box office.
     * @param string $api_key  Plaintext Ticket Tailor API key.
     * @param string $currency Currency code (e.g. 'usd', 'gbp').
     * @return int|false Insert ID on success, false on failure.
     */
    public static function add( $name, $api_key, $currency = 'usd' ) {
        global $wpdb;

        $slug = self::generate_unique_slug( $name );

        $result = $wpdb->insert(
            self::table_name(),
            array(
                'name'       => sanitize_text_field( $name ),
                'slug'       => $slug,
                'api_key'    => self::encrypt_api_key( $api_key ),
                'currency'   => sanitize_text_field( strtolower( $currency ) ),
                'status'     => 'active',
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return false;
        }

        $insert_id = (int) $wpdb->insert_id;

        // Create matching taxonomy term (silently fails if taxonomy not yet registered).
        if ( taxonomy_exists( self::TAXONOMY ) ) {
            wp_insert_term( sanitize_text_field( $name ), self::TAXONOMY, array(
                'slug' => $slug,
            ) );
        }

        return $insert_id;
    }

    /**
     * Get a single box office by ID.
     *
     * @param int $id Box office row ID.
     * @return object|null Row object with decrypted api_key, or null.
     */
    public static function get( $id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE id = %d",
            (int) $id
        ) );

        if ( ! $row ) {
            return null;
        }

        $row->api_key = self::decrypt_api_key( $row->api_key );
        return $row;
    }

    /**
     * Get a single box office by slug.
     *
     * @param string $slug Box office slug.
     * @return object|null Row object with decrypted api_key, or null.
     */
    public static function get_by_slug( $slug ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE slug = %s",
            sanitize_title( $slug )
        ) );

        if ( ! $row ) {
            return null;
        }

        $row->api_key = self::decrypt_api_key( $row->api_key );
        return $row;
    }

    /**
     * Get all box offices.
     *
     * @param string $status Filter by status: 'active', 'inactive', or 'all'.
     * @return array Array of row objects with decrypted api_keys.
     */
    public static function get_all( $status = 'all' ) {
        global $wpdb;

        $table = self::table_name();

        if ( 'all' === $status ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC" );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC",
                sanitize_text_field( $status )
            ) );
        }

        if ( ! $rows ) {
            return array();
        }

        foreach ( $rows as $row ) {
            $row->api_key = self::decrypt_api_key( $row->api_key );
        }

        return $rows;
    }

    /**
     * Update specific fields on a box office.
     *
     * Supported keys: name, api_key, currency, status, last_sync, roster_token.
     * The api_key value will be encrypted automatically if provided.
     *
     * @param int   $id   Box office row ID.
     * @param array $data Associative array of column => value pairs.
     * @return bool True on success, false on failure.
     */
    public static function update( $id, $data ) {
        global $wpdb;

        $allowed = array( 'name', 'api_key', 'currency', 'status', 'last_sync', 'roster_token' );

        $update_data    = array();
        $update_formats = array();

        foreach ( $data as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                continue;
            }

            switch ( $key ) {
                case 'api_key':
                    $update_data['api_key'] = self::encrypt_api_key( $value );
                    $update_formats[]       = '%s';
                    break;

                case 'currency':
                    $update_data['currency'] = sanitize_text_field( strtolower( $value ) );
                    $update_formats[]        = '%s';
                    break;

                case 'name':
                    $update_data['name'] = sanitize_text_field( $value );
                    $update_formats[]    = '%s';
                    break;

                case 'status':
                    $update_data['status'] = sanitize_text_field( $value );
                    $update_formats[]      = '%s';
                    break;

                case 'last_sync':
                    $update_data['last_sync'] = sanitize_text_field( $value );
                    $update_formats[]         = '%s';
                    break;

                case 'roster_token':
                    $update_data['roster_token'] = sanitize_text_field( $value );
                    $update_formats[]            = '%s';
                    break;
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            self::table_name(),
            $update_data,
            array( 'id' => (int) $id ),
            $update_formats,
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Delete a box office and clean up associated data.
     *
     * If $delete_events is true, permanently deletes all associated tt_event posts.
     * Otherwise, unassigns them by removing box office meta and taxonomy term.
     * Always deletes the taxonomy term itself.
     *
     * @param int  $id            Box office row ID.
     * @param bool $delete_events Whether to permanently delete associated events.
     * @return bool True on success, false on failure.
     */
    public static function delete( $id, $delete_events = false ) {
        global $wpdb;

        $box_office = self::get( $id );
        if ( ! $box_office ) {
            return false;
        }

        // Find all associated tt_event posts.
        $post_ids = get_posts( array(
            'post_type'      => 'tt_event',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_tt_box_office_id',
            'meta_value'     => (int) $id,
            'post_status'    => 'any',
        ) );

        if ( $delete_events ) {
            // Permanently delete all associated events.
            foreach ( $post_ids as $post_id ) {
                wp_delete_post( $post_id, true );
            }
        } else {
            // Unassign: remove box office meta and taxonomy term from each post.
            foreach ( $post_ids as $post_id ) {
                delete_post_meta( $post_id, '_tt_box_office_id' );
                wp_remove_object_terms( $post_id, $box_office->slug, self::TAXONOMY );
            }
        }

        // Delete the taxonomy term if it exists.
        if ( taxonomy_exists( self::TAXONOMY ) ) {
            $term = get_term_by( 'slug', $box_office->slug, self::TAXONOMY );
            if ( $term && ! is_wp_error( $term ) ) {
                wp_delete_term( $term->term_id, self::TAXONOMY );
            }
        }

        // Delete the box office row.
        $result = $wpdb->delete(
            self::table_name(),
            array( 'id' => (int) $id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Count tt_event posts associated with a box office.
     *
     * @param int $id Box office row ID.
     * @return int Number of associated events.
     */
    public static function get_event_count( $id ) {
        $query = new WP_Query( array(
            'post_type'      => 'tt_event',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_tt_box_office_id',
            'meta_value'     => (int) $id,
            'post_status'    => 'any',
            'no_found_rows'  => false,
        ) );

        return (int) $query->found_posts;
    }

    /**
     * Mask an API key for safe display.
     *
     * Shows the first 3 and last 4 characters, masking the rest with asterisks.
     * Returns a fully masked string if the key is too short.
     *
     * @param string $key Plaintext API key.
     * @return string Masked key (e.g. "abc****defg").
     */
    public static function mask_api_key( $key ) {
        $len = strlen( $key );

        if ( $len <= 7 ) {
            return str_repeat( '*', $len );
        }

        $first = substr( $key, 0, 3 );
        $last  = substr( $key, -4 );
        $mask  = str_repeat( '*', $len - 7 );

        return $first . $mask . $last;
    }

    /**
     * Generate a unique slug for a box office name.
     *
     * Uses sanitize_title() and appends a numeric suffix if the slug
     * already exists in the database.
     *
     * @param string $name The box office display name.
     * @return string A unique slug.
     */
    private static function generate_unique_slug( $name ) {
        global $wpdb;

        $base_slug = sanitize_title( $name );
        $slug      = $base_slug;
        $table     = self::table_name();
        $counter   = 1;

        while ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE slug = %s",
            $slug
        ) ) ) {
            $counter++;
            $slug = $base_slug . '-' . $counter;
        }

        return $slug;
    }
}
