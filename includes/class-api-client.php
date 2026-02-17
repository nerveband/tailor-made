<?php
/**
 * Ticket Tailor API Client.
 *
 * Wraps all GET endpoints with pagination support.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_API_Client {

    private string $api_key;
    private string $base_url = 'https://api.tickettailor.com/v1';

    public function __construct( ?string $api_key = null ) {
        $this->api_key = $api_key ?? get_option( 'tailor_made_api_key', '' );
    }

    /**
     * Make an authenticated GET request.
     */
    private function get( string $endpoint, array $params = [] ): array|WP_Error {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'Ticket Tailor API key not configured.' );
        }

        $url = $this->base_url . $endpoint;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new WP_Error(
                'api_error',
                $body['message'] ?? "HTTP {$code}",
                [ 'status' => $code, 'body' => $body ]
            );
        }

        return $body;
    }

    /**
     * Fetch all pages of a paginated endpoint.
     */
    private function get_all( string $endpoint, array $params = [] ): array|WP_Error {
        $all  = [];
        $params['limit'] = 100;

        while ( true ) {
            $result = $this->get( $endpoint, $params );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $data = $result['data'] ?? [];
            $all  = array_merge( $all, $data );

            $next = $result['links']['next'] ?? null;
            if ( ! $next || empty( $data ) ) {
                break;
            }

            // Extract starting_after cursor from last item
            $last_item = end( $data );
            $params['starting_after'] = $last_item['id'];
        }

        return $all;
    }

    /**
     * Health check.
     */
    public function ping(): array|WP_Error {
        return $this->get( '/ping' );
    }

    /**
     * Account overview.
     */
    public function overview(): array|WP_Error {
        return $this->get( '/overview' );
    }

    /**
     * Get all events (all pages).
     */
    public function get_events(): array|WP_Error {
        return $this->get_all( '/events' );
    }

    /**
     * Get a single event by ID.
     */
    public function get_event( string $event_id ): array|WP_Error {
        return $this->get( "/events/{$event_id}" );
    }

    /**
     * Get all event series.
     */
    public function get_event_series(): array|WP_Error {
        return $this->get_all( '/event_series' );
    }

    /**
     * Get a single event series.
     */
    public function get_event_series_by_id( string $series_id ): array|WP_Error {
        return $this->get( "/event_series/{$series_id}" );
    }

    /**
     * Get all orders.
     */
    public function get_orders(): array|WP_Error {
        return $this->get_all( '/orders' );
    }

    /**
     * Get all issued tickets.
     */
    public function get_issued_tickets(): array|WP_Error {
        return $this->get_all( '/issued_tickets' );
    }

    /**
     * Get all vouchers.
     */
    public function get_vouchers(): array|WP_Error {
        return $this->get_all( '/vouchers' );
    }

    /**
     * Get all products.
     */
    public function get_products(): array|WP_Error {
        return $this->get_all( '/products' );
    }

    /**
     * Get all checkout forms.
     */
    public function get_checkout_forms(): array|WP_Error {
        return $this->get_all( '/checkout_forms' );
    }

    /**
     * Get store info.
     */
    public function get_stores(): array|WP_Error {
        return $this->get_all( '/stores' );
    }
}
