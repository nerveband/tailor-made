<?php
/**
 * Sync Engine — pulls Ticket Tailor data into WordPress CPT.
 *
 * Supports multiple box offices: each box office syncs independently
 * with scoped orphan deletion to prevent cross-box-office collisions.
 *
 * @since 2.0.0
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

    /* ------------------------------------------------------------------
     * Multi-Box-Office entry point
     * ----------------------------------------------------------------*/

    /**
     * Sync all active box offices.
     *
     * Top-level method called by the cron handler and admin sync button.
     * For each active box office, creates a fresh API client and sync engine
     * instance, runs sync_all(), and aggregates the results.
     *
     * If one box office's API fails, the others still sync.
     *
     * @return array Aggregated result with totals and per-box-office breakdown.
     */
    public static function sync_all_box_offices() {
        $aggregated = array(
            'created'      => 0,
            'updated'      => 0,
            'deleted'      => 0,
            'errors'       => array(),
            'box_offices'  => array(),
        );

        $box_offices = Tailor_Made_Box_Office_Manager::get_all( 'active' );

        if ( empty( $box_offices ) ) {
            // No box offices configured — fall back to legacy single-key sync.
            $engine = new self();
            $result = $engine->sync_all();

            $aggregated['created'] = $result['created'];
            $aggregated['updated'] = $result['updated'];
            $aggregated['deleted'] = $result['deleted'];
            $aggregated['errors']  = $result['errors'];

            update_option( 'tailor_made_last_sync', current_time( 'mysql' ) );
            update_option( 'tailor_made_last_sync_result', $aggregated );

            return $aggregated;
        }

        foreach ( $box_offices as $bo ) {
            $api_key = $bo->api_key;
            $client  = new Tailor_Made_API_Client( $api_key );
            $logger  = new Tailor_Made_Sync_Logger();
            $engine  = new self( $client, $logger );

            $result = $engine->sync_all( $bo );

            // Aggregate totals.
            $aggregated['created'] += $result['created'];
            $aggregated['updated'] += $result['updated'];
            $aggregated['deleted'] += $result['deleted'];

            if ( ! empty( $result['errors'] ) ) {
                foreach ( $result['errors'] as $err ) {
                    $aggregated['errors'][] = '[' . $bo->name . '] ' . $err;
                }
            }

            // Per-box-office breakdown.
            $aggregated['box_offices'][ $bo->slug ] = array(
                'name'    => $bo->name,
                'created' => $result['created'],
                'updated' => $result['updated'],
                'deleted' => $result['deleted'],
                'errors'  => $result['errors'],
            );

            // Update last_sync on the box office row.
            Tailor_Made_Box_Office_Manager::update( $bo->id, array(
                'last_sync' => current_time( 'mysql' ),
            ) );
        }

        // Update global last sync options.
        update_option( 'tailor_made_last_sync', current_time( 'mysql' ) );
        update_option( 'tailor_made_last_sync_result', $aggregated );

        return $aggregated;
    }

    /* ------------------------------------------------------------------
     * Core sync logic
     * ----------------------------------------------------------------*/

    /**
     * Sync all events from Ticket Tailor for a single box office (or legacy mode).
     *
     * @param object|null $box_office Box office row object, or null for legacy single-key mode.
     * @return array
     */
    public function sync_all( $box_office = null ) {
        $result = array( 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => array() );

        // Extract box office identifiers (defaults for legacy/null).
        $bo_id   = $box_office ? (int) $box_office->id : 0;
        $bo_name = $box_office ? $box_office->name : 'Default';
        $bo_slug = $box_office ? $box_office->slug : '';

        $log_prefix = $bo_id > 0 ? '[' . $bo_name . '] ' : '';
        $log_bo     = $bo_id > 0 ? array( 'box_office_name' => $bo_name ) : array();

        // Log: Sync started.
        $this->logger->info( 'start', $log_prefix . 'Sync started', $log_bo );

        $events = $this->client->get_events();
        if ( is_wp_error( $events ) ) {
            $this->logger->error( 'error', $log_prefix . 'API fetch failed: ' . $events->get_error_message(), array_merge( $log_bo, array(
                'details' => $events->get_error_data(),
            ) ) );
            $result['errors'][] = $events->get_error_message();
            return $result;
        }

        // Log: Fetched N events.
        $this->logger->info( 'fetched', $log_prefix . sprintf( 'Fetched %d events from Ticket Tailor API', count( $events ) ), $log_bo );

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

                $this->logger->info( 'updated', $log_prefix . 'Updated: ' . $event_name, array_merge( $log_bo, array(
                    'tt_event_id' => $tt_id,
                    'event_name'  => $event_name,
                ) ) );
            } else {
                $this->create_post( $event, $bo_id, $bo_slug );
                $result['created']++;

                $this->logger->info( 'created', $log_prefix . 'Created: ' . $event_name, array_merge( $log_bo, array(
                    'tt_event_id' => $tt_id,
                    'event_name'  => $event_name,
                ) ) );
            }
        }

        // Delete posts for events no longer in the API (with safety check).
        // Scoped to this box office when $bo_id > 0.
        if ( $bo_id > 0 ) {
            // Multi-box-office mode: count only this box office's posts.
            $bo_post_query = new WP_Query( array(
                'post_type'      => Tailor_Made_CPT::POST_TYPE,
                'posts_per_page' => 1,
                'post_status'    => array( 'publish', 'draft' ),
                'fields'         => 'ids',
                'no_found_rows'  => false,
                'meta_key'       => '_tt_box_office_id',
                'meta_value'     => $bo_id,
                'meta_type'      => 'NUMERIC',
            ) );
            $total_bo_posts = (int) $bo_post_query->found_posts;
        } else {
            // Legacy mode: count all posts.
            $wp_post_count  = wp_count_posts( Tailor_Made_CPT::POST_TYPE );
            $total_bo_posts = ( isset( $wp_post_count->publish ) ? $wp_post_count->publish : 0 )
                            + ( isset( $wp_post_count->draft ) ? $wp_post_count->draft : 0 );
        }

        if ( empty( $synced_tt_ids ) && $total_bo_posts > 0 ) {
            // API returned 0 events but we have posts — skip deletion to prevent mass-delete.
            $this->logger->warning( 'skipped_delete', $log_prefix . sprintf(
                'API returned 0 events but %d WP posts exist — skipping orphan deletion as a safety measure',
                $total_bo_posts
            ), $log_bo );
        } else {
            $orphans = $this->find_orphaned_posts( $synced_tt_ids, $bo_id );
            foreach ( $orphans as $orphan_id ) {
                $orphan_name  = get_the_title( $orphan_id );
                $orphan_tt_id = get_post_meta( $orphan_id, '_tt_event_id', true );

                wp_delete_post( $orphan_id, true );
                $result['deleted']++;

                $this->logger->warning( 'deleted', $log_prefix . 'Deleted orphan: ' . $orphan_name, array_merge( $log_bo, array(
                    'tt_event_id' => $orphan_tt_id,
                    'event_name'  => $orphan_name,
                ) ) );
            }
        }

        // Do NOT update global options here — that is done in sync_all_box_offices().
        // Only update if running in legacy mode (no box office).
        if ( null === $box_office ) {
            update_option( 'tailor_made_last_sync', current_time( 'mysql' ) );
            update_option( 'tailor_made_last_sync_result', $result );
        }

        // Log: Sync completed.
        $this->logger->info( 'end', $log_prefix . sprintf(
            'Sync completed — Created: %d, Updated: %d, Deleted: %d',
            $result['created'],
            $result['updated'],
            $result['deleted']
        ), array_merge( $log_bo, array(
            'details' => $result,
        ) ) );

        return $result;
    }

    /* ------------------------------------------------------------------
     * Post lookup
     * ----------------------------------------------------------------*/

    /**
     * Find an existing WP post by Ticket Tailor event ID, scoped to a box office.
     *
     * When $bo_id > 0, queries with both _tt_event_id AND _tt_box_office_id
     * to prevent cross-box-office collisions (same TT event ID in different accounts).
     *
     * @param string $tt_id Ticket Tailor event ID.
     * @param int    $bo_id Box office ID (0 for legacy unscoped lookup).
     * @return WP_Post|null
     */
    private function find_post_by_tt_id( $tt_id, $bo_id = 0 ) {
        $args = array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => 1,
            'post_status' => 'any',
        );

        if ( $bo_id > 0 ) {
            $args['meta_query'] = array(
                'relation' => 'AND',
                array(
                    'key'   => '_tt_event_id',
                    'value' => $tt_id,
                ),
                array(
                    'key'     => '_tt_box_office_id',
                    'value'   => $bo_id,
                    'type'    => 'NUMERIC',
                ),
            );
        } else {
            $args['meta_key']   = '_tt_event_id';
            $args['meta_value'] = $tt_id;
        }

        $posts = get_posts( $args );

        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Find orphaned posts — posts whose TT event ID is no longer in the API response.
     *
     * When $bo_id > 0, only queries posts belonging to that box office.
     * This is CRITICAL: Box Office A's sync must never delete Box Office B's events.
     *
     * @param array $active_tt_ids Array of TT event IDs still active in the API.
     * @param int   $bo_id         Box office ID (0 for legacy unscoped lookup).
     * @return array Array of post IDs to delete.
     */
    private function find_orphaned_posts( $active_tt_ids, $bo_id = 0 ) {
        $args = array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        );

        if ( $bo_id > 0 ) {
            $args['meta_query'] = array(
                array(
                    'key'   => '_tt_box_office_id',
                    'value' => $bo_id,
                    'type'  => 'NUMERIC',
                ),
            );
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

    /* ------------------------------------------------------------------
     * Post create / update
     * ----------------------------------------------------------------*/

    /**
     * Create a new WP post from a Ticket Tailor event.
     *
     * @param array  $event   Event data from the TT API.
     * @param int    $bo_id   Box office ID (0 for legacy).
     * @param string $bo_slug Box office slug ('' for legacy).
     * @return int Post ID on success, 0 on failure.
     */
    private function create_post( $event, $bo_id = 0, $bo_slug = '' ) {
        $name    = isset( $event['name'] ) ? $event['name'] : 'Untitled Event';
        $desc    = isset( $event['description'] ) ? $event['description'] : '';
        $status  = isset( $event['status'] ) ? $event['status'] : 'draft';
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
        $this->maybe_set_featured_image( $post_id, $event );
        $this->assign_box_office( $post_id, $bo_id, $bo_slug );

        return $post_id;
    }

    /**
     * Update an existing WP post from a Ticket Tailor event.
     *
     * @param int    $post_id WP post ID.
     * @param array  $event   Event data from the TT API.
     * @param int    $bo_id   Box office ID (0 for legacy).
     * @param string $bo_slug Box office slug ('' for legacy).
     */
    private function update_post( $post_id, $event, $bo_id = 0, $bo_slug = '' ) {
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
        $this->maybe_set_featured_image( $post_id, $event );
        $this->assign_box_office( $post_id, $bo_id, $bo_slug );
    }

    /* ------------------------------------------------------------------
     * Box office assignment
     * ----------------------------------------------------------------*/

    /**
     * Assign a post to a box office via meta and taxonomy term.
     *
     * Sets the _tt_box_office_id post meta and assigns the tt_box_office
     * taxonomy term by slug. Skips if $bo_id is 0 (legacy mode).
     *
     * @param int    $post_id WP post ID.
     * @param int    $bo_id   Box office ID.
     * @param string $bo_slug Box office slug.
     */
    private function assign_box_office( $post_id, $bo_id, $bo_slug ) {
        if ( $bo_id <= 0 ) {
            return;
        }

        update_post_meta( $post_id, '_tt_box_office_id', $bo_id );

        if ( ! empty( $bo_slug ) && taxonomy_exists( Tailor_Made_Box_Office_Manager::TAXONOMY ) ) {
            wp_set_object_terms( $post_id, $bo_slug, Tailor_Made_Box_Office_Manager::TAXONOMY );
        }
    }

    /* ------------------------------------------------------------------
     * Meta
     * ----------------------------------------------------------------*/

    /**
     * Save all event meta fields to a post.
     *
     * @param int   $post_id WP post ID.
     * @param array $event   Event data from the TT API.
     * @param int   $bo_id   Box office ID (0 for legacy).
     */
    private function save_meta( $post_id, $event, $bo_id = 0 ) {
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
            '_tt_box_office_id'        => $bo_id,
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
        );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    /* ------------------------------------------------------------------
     * Featured image
     * ----------------------------------------------------------------*/

    private function maybe_set_featured_image( $post_id, $event ) {
        $imgs      = isset( $event['images'] ) ? $event['images'] : array();
        $image_url = isset( $imgs['header'] ) ? $imgs['header'] : '';
        if ( empty( $image_url ) ) {
            return;
        }

        // SSRF protection: only allow HTTPS URLs from tickettailor.com domains
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

    /* ------------------------------------------------------------------
     * Helpers (unchanged)
     * ----------------------------------------------------------------*/

    /**
     * Validate that an image URL is safe to download (SSRF protection).
     *
     * @param string $url
     * @return bool
     */
    private function is_allowed_image_url( $url ) {
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

    private function format_price_range( $prices ) {
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

    private function map_status( $tt_status ) {
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

    private function unix_to_wp_date( $unix ) {
        if ( $unix <= 0 ) {
            return current_time( 'mysql' );
        }
        return gmdate( 'Y-m-d H:i:s', $unix );
    }
}
