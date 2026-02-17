<?php
/**
 * Sync Engine — pulls Ticket Tailor data into WordPress CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Sync_Engine {

    private Tailor_Made_API_Client $client;

    public function __construct( ?Tailor_Made_API_Client $client = null ) {
        $this->client = $client ?? new Tailor_Made_API_Client();
    }

    /**
     * Sync all events from Ticket Tailor.
     *
     * @return array{created: int, updated: int, deleted: int, errors: string[]}
     */
    public function sync_all(): array {
        $result = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => [] ];

        $events = $this->client->get_events();
        if ( is_wp_error( $events ) ) {
            $result['errors'][] = $events->get_error_message();
            return $result;
        }

        $synced_tt_ids = [];

        foreach ( $events as $event ) {
            $tt_id = $event['id'] ?? '';
            if ( empty( $tt_id ) ) {
                continue;
            }

            $synced_tt_ids[] = $tt_id;
            $existing = $this->find_post_by_tt_id( $tt_id );

            if ( $existing ) {
                $this->update_post( $existing->ID, $event );
                $result['updated']++;
            } else {
                $this->create_post( $event );
                $result['created']++;
            }
        }

        // Delete posts for events no longer in the API
        $orphans = $this->find_orphaned_posts( $synced_tt_ids );
        foreach ( $orphans as $orphan_id ) {
            wp_delete_post( $orphan_id, true );
            $result['deleted']++;
        }

        update_option( 'tailor_made_last_sync', current_time( 'mysql' ) );
        update_option( 'tailor_made_last_sync_result', $result );

        return $result;
    }

    /**
     * Find WP post by Ticket Tailor event ID.
     */
    private function find_post_by_tt_id( string $tt_id ): ?WP_Post {
        $posts = get_posts( [
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'meta_key'    => '_tt_event_id',
            'meta_value'  => $tt_id,
            'numberposts' => 1,
            'post_status' => 'any',
        ] );

        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Find posts whose TT IDs are no longer in the API.
     */
    private function find_orphaned_posts( array $active_tt_ids ): array {
        $all_posts = get_posts( [
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ] );

        $orphans = [];
        foreach ( $all_posts as $post_id ) {
            $tt_id = get_post_meta( $post_id, '_tt_event_id', true );
            if ( $tt_id && ! in_array( $tt_id, $active_tt_ids, true ) ) {
                $orphans[] = $post_id;
            }
        }

        return $orphans;
    }

    /**
     * Create a new WP post from a TT event.
     */
    private function create_post( array $event ): int {
        $post_id = wp_insert_post( [
            'post_type'    => Tailor_Made_CPT::POST_TYPE,
            'post_title'   => $event['name'] ?? 'Untitled Event',
            'post_content' => $this->clean_description( $event['description'] ?? '' ),
            'post_status'  => $this->map_status( $event['status'] ?? 'draft' ),
            'post_date'    => $this->unix_to_wp_date( $event['created_at'] ?? 0 ),
        ] );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        $this->save_meta( $post_id, $event );
        $this->maybe_set_featured_image( $post_id, $event );

        return $post_id;
    }

    /**
     * Update an existing WP post from a TT event.
     */
    private function update_post( int $post_id, array $event ): void {
        wp_update_post( [
            'ID'           => $post_id,
            'post_title'   => $event['name'] ?? 'Untitled Event',
            'post_content' => $this->clean_description( $event['description'] ?? '' ),
            'post_status'  => $this->map_status( $event['status'] ?? 'draft' ),
        ] );

        $this->save_meta( $post_id, $event );
        $this->maybe_set_featured_image( $post_id, $event );
    }

    /**
     * Save all meta fields for an event.
     */
    private function save_meta( int $post_id, array $event ): void {
        $ticket_types = $event['ticket_types'] ?? [];
        $prices       = array_column( $ticket_types, 'price' );
        $quantities   = array_column( $ticket_types, 'quantity_total' );
        $issued       = array_column( $ticket_types, 'quantity_issued' );

        $meta = [
            '_tt_event_id'            => $event['id'] ?? '',
            '_tt_event_series_id'     => $event['event_series_id'] ?? '',
            '_tt_status'              => $event['status'] ?? '',
            '_tt_currency'            => $event['currency'] ?? 'usd',
            '_tt_start_date'          => $event['start']['date'] ?? '',
            '_tt_start_time'          => $event['start']['time'] ?? '',
            '_tt_start_formatted'     => $event['start']['formatted'] ?? '',
            '_tt_start_iso'           => $event['start']['iso'] ?? '',
            '_tt_start_unix'          => $event['start']['unix'] ?? 0,
            '_tt_end_date'            => $event['end']['date'] ?? '',
            '_tt_end_time'            => $event['end']['time'] ?? '',
            '_tt_end_formatted'       => $event['end']['formatted'] ?? '',
            '_tt_end_iso'             => $event['end']['iso'] ?? '',
            '_tt_end_unix'            => $event['end']['unix'] ?? 0,
            '_tt_timezone'            => $event['timezone'] ?? '',
            '_tt_venue_name'          => $event['venue']['name'] ?? '',
            '_tt_venue_country'       => $event['venue']['country'] ?? '',
            '_tt_venue_postal_code'   => $event['venue']['postal_code'] ?? '',
            '_tt_image_header'        => $event['images']['header'] ?? '',
            '_tt_image_thumbnail'     => $event['images']['thumbnail'] ?? '',
            '_tt_checkout_url'        => $event['checkout_url'] ?? '',
            '_tt_event_url'           => $event['url'] ?? '',
            '_tt_call_to_action'      => $event['call_to_action'] ?? '',
            '_tt_online_event'        => $event['online_event'] ?? 'false',
            '_tt_private'             => $event['private'] ?? 'false',
            '_tt_hidden'              => $event['hidden'] ?? 'false',
            '_tt_tickets_available'   => $event['tickets_available'] ?? 'false',
            '_tt_revenue'             => $event['revenue'] ?? 0,
            '_tt_total_orders'        => $event['total_orders'] ?? 0,
            '_tt_total_issued_tickets' => $event['total_issued_tickets'] ?? 0,
            '_tt_ticket_types'        => wp_json_encode( $ticket_types ),
            '_tt_min_price'           => ! empty( $prices ) ? min( $prices ) : 0,
            '_tt_max_price'           => ! empty( $prices ) ? max( $prices ) : 0,
            '_tt_total_capacity'      => array_sum( $quantities ),
            '_tt_tickets_remaining'   => array_sum( $quantities ) - array_sum( $issued ),
            '_tt_last_synced'         => current_time( 'mysql' ),
            '_tt_raw_json'            => wp_json_encode( $event ),
        ];

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    /**
     * Download and set featured image from TT header image.
     */
    private function maybe_set_featured_image( int $post_id, array $event ): void {
        $image_url = $event['images']['header'] ?? '';
        if ( empty( $image_url ) ) {
            return;
        }

        // Skip if we already have a featured image and the URL hasn't changed
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

        $file_array = [
            'name'     => sanitize_file_name( ( $event['name'] ?? 'event' ) . '-header.jpg' ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id, $event['name'] ?? '' );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return;
        }

        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $post_id, '_tt_image_header_source', $image_url );
    }

    /**
     * Map TT status to WP post status.
     */
    private function map_status( string $tt_status ): string {
        return match ( $tt_status ) {
            'published', 'live' => 'publish',
            'draft'             => 'draft',
            'past'              => 'publish', // Keep past events visible
            default             => 'draft',
        };
    }

    /**
     * Strip HTML tags from description but keep basic formatting.
     */
    private function clean_description( string $html ): string {
        return wp_kses_post( $html );
    }

    /**
     * Convert unix timestamp to WP date format.
     */
    private function unix_to_wp_date( int $unix ): string {
        if ( $unix <= 0 ) {
            return current_time( 'mysql' );
        }
        return gmdate( 'Y-m-d H:i:s', $unix );
    }
}
