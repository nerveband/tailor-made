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

    /** @var string */
    private $api_key;

    /** @var string */
    private $base_url = 'https://api.tickettailor.com/v1';

    /**
     * @param string|null $api_key
     */
    public function __construct( $api_key = null ) {
        $this->api_key = $api_key ? $api_key : get_option( 'tailor_made_api_key', '' );
    }

    /**
     * Make an authenticated GET request.
     *
     * @return array|WP_Error
     */
    private function get( $endpoint, $params = array() ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'Ticket Tailor API key not configured.' );
        }

        $url = $this->base_url . $endpoint;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = isset( $body['message'] ) ? $body['message'] : "HTTP {$code}";
            return new WP_Error(
                'api_error',
                $msg,
                array( 'status' => $code, 'body' => $body )
            );
        }

        return $body;
    }

    /**
     * Fetch all pages of a paginated endpoint.
     *
     * @return array|WP_Error
     */
    private function get_all( $endpoint, $params = array() ) {
        $all  = array();
        $params['limit'] = 100;

        while ( true ) {
            $result = $this->get( $endpoint, $params );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $data = isset( $result['data'] ) ? $result['data'] : array();
            $all  = array_merge( $all, $data );

            $next = isset( $result['links']['next'] ) ? $result['links']['next'] : null;
            if ( ! $next || empty( $data ) ) {
                break;
            }

            $last_item = end( $data );
            $params['starting_after'] = $last_item['id'];
        }

        return $all;
    }

    /** @return array|WP_Error */
    public function ping() {
        return $this->get( '/ping' );
    }

    /** @return array|WP_Error */
    public function overview() {
        return $this->get( '/overview' );
    }

    /** @return array|WP_Error */
    public function get_events() {
        return $this->get_all( '/events' );
    }

    /** @return array|WP_Error */
    public function get_event( $event_id ) {
        return $this->get( "/events/{$event_id}" );
    }

    /** @return array|WP_Error */
    public function get_event_series() {
        return $this->get_all( '/event_series' );
    }

    /** @return array|WP_Error */
    public function get_event_series_by_id( $series_id ) {
        return $this->get( "/event_series/{$series_id}" );
    }

    /** @return array|WP_Error */
    public function get_orders() {
        return $this->get_all( '/orders' );
    }

    /** @return array|WP_Error */
    public function get_issued_tickets() {
        return $this->get_all( '/issued_tickets' );
    }

    /** @return array|WP_Error */
    public function get_vouchers() {
        return $this->get_all( '/vouchers' );
    }

    /** @return array|WP_Error */
    public function get_products() {
        return $this->get_all( '/products' );
    }

    /** @return array|WP_Error */
    public function get_checkout_forms() {
        return $this->get_all( '/checkout_forms' );
    }

    /** @return array|WP_Error */
    public function get_stores() {
        return $this->get_all( '/stores' );
    }
}
