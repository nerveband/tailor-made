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
     * Sync all events from Ticket Tailor.
     *
     * @return array
     */
    public function sync_all() {
        $result = array( 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => array() );

        // Log 1: Sync started
        $this->logger->info( 'start', 'Sync started' );

        $events = $this->client->get_events();
        if ( is_wp_error( $events ) ) {
            // Log 2: API fetch failed
            $this->logger->error( 'error', 'API fetch failed: ' . $events->get_error_message(), array(
                'details' => $events->get_error_data(),
            ) );
            $result['errors'][] = $events->get_error_message();
            return $result;
        }

        // Log 3: Fetched N events
        $this->logger->info( 'fetched', sprintf( 'Fetched %d events from Ticket Tailor API', count( $events ) ) );

        $synced_tt_ids = array();

        foreach ( $events as $event ) {
            $tt_id = isset( $event['id'] ) ? $event['id'] : '';
            if ( empty( $tt_id ) ) {
                continue;
            }

            $synced_tt_ids[] = $tt_id;
            $event_name      = isset( $event['name'] ) ? $event['name'] : 'Untitled Event';
            $existing        = $this->find_post_by_tt_id( $tt_id );

            if ( $existing ) {
                $this->update_post( $existing->ID, $event );
                $result['updated']++;

                // Log 5: Updated event
                $this->logger->info( 'updated', 'Updated: ' . $event_name, array(
                    'tt_event_id' => $tt_id,
                    'event_name'  => $event_name,
                ) );
            } else {
                $this->create_post( $event );
                $result['created']++;

                // Log 4: Created event
                $this->logger->info( 'created', 'Created: ' . $event_name, array(
                    'tt_event_id' => $tt_id,
                    'event_name'  => $event_name,
                ) );
            }
        }

        // Delete posts for events no longer in the API
        $orphans = $this->find_orphaned_posts( $synced_tt_ids );
        foreach ( $orphans as $orphan_id ) {
            $orphan_name = get_the_title( $orphan_id );
            $orphan_tt_id = get_post_meta( $orphan_id, '_tt_event_id', true );

            wp_delete_post( $orphan_id, true );
            $result['deleted']++;

            // Log 6: Deleted orphan
            $this->logger->warning( 'deleted', 'Deleted orphan: ' . $orphan_name, array(
                'tt_event_id' => $orphan_tt_id,
                'event_name'  => $orphan_name,
            ) );
        }

        update_option( 'tailor_made_last_sync', current_time( 'mysql' ) );
        update_option( 'tailor_made_last_sync_result', $result );

        // Log 7: Sync completed
        $this->logger->info( 'end', sprintf(
            'Sync completed — Created: %d, Updated: %d, Deleted: %d',
            $result['created'],
            $result['updated'],
            $result['deleted']
        ), array(
            'details' => $result,
        ) );

        return $result;
    }

    /**
     * @return WP_Post|null
     */
    private function find_post_by_tt_id( $tt_id ) {
        $posts = get_posts( array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'meta_key'    => '_tt_event_id',
            'meta_value'  => $tt_id,
            'numberposts' => 1,
            'post_status' => 'any',
        ) );

        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * @return array
     */
    private function find_orphaned_posts( $active_tt_ids ) {
        $all_posts = get_posts( array(
            'post_type'   => Tailor_Made_CPT::POST_TYPE,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ) );

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
     * @return int
     */
    private function create_post( $event ) {
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

        $this->save_meta( $post_id, $event );
        $this->maybe_set_featured_image( $post_id, $event );

        return $post_id;
    }

    private function update_post( $post_id, $event ) {
        $name   = isset( $event['name'] ) ? $event['name'] : 'Untitled Event';
        $desc   = isset( $event['description'] ) ? $event['description'] : '';
        $status = isset( $event['status'] ) ? $event['status'] : 'draft';

        wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => $name,
            'post_content' => wp_kses_post( $desc ),
            'post_status'  => $this->map_status( $status ),
        ) );

        $this->save_meta( $post_id, $event );
        $this->maybe_set_featured_image( $post_id, $event );
    }

    private function save_meta( $post_id, $event ) {
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
            '_tt_image_header'         => isset( $imgs['header'] ) ? $imgs['header'] : '',
            '_tt_image_thumbnail'      => isset( $imgs['thumbnail'] ) ? $imgs['thumbnail'] : '',
            '_tt_checkout_url'         => isset( $event['checkout_url'] ) ? $event['checkout_url'] : '',
            '_tt_event_url'            => isset( $event['url'] ) ? $event['url'] : '',
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

    private function maybe_set_featured_image( $post_id, $event ) {
        $imgs      = isset( $event['images'] ) ? $event['images'] : array();
        $image_url = isset( $imgs['header'] ) ? $imgs['header'] : '';
        if ( empty( $image_url ) ) {
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
            @unlink( $tmp );
            return;
        }

        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $post_id, '_tt_image_header_source', $image_url );
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
                return 'publish';
            case 'draft':
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
