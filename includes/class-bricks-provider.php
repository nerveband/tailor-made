<?php
/**
 * Bricks Builder Dynamic Data Provider.
 *
 * Registers dynamic data tags for tt_event CPT fields so they can
 * be used in Bricks Builder templates, query loops, and elements.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Bricks_Provider {

    /**
     * @return array
     */
    private static function get_tags() {
        return array(
            'tt_event_name'            => array( 'label' => 'TT Event Name',             'group' => 'Ticket Tailor',  'meta' => null ),
            'tt_event_description'     => array( 'label' => 'TT Event Description',      'group' => 'Ticket Tailor',  'meta' => null ),
            'tt_event_id'              => array( 'label' => 'TT Event ID',               'group' => 'Ticket Tailor',  'meta' => '_tt_event_id' ),
            'tt_status'                => array( 'label' => 'TT Status',                 'group' => 'Ticket Tailor',  'meta' => '_tt_status' ),
            'tt_start_date'            => array( 'label' => 'TT Start Date',             'group' => 'Ticket Tailor',  'meta' => '_tt_start_date' ),
            'tt_start_time'            => array( 'label' => 'TT Start Time',             'group' => 'Ticket Tailor',  'meta' => '_tt_start_time' ),
            'tt_start_formatted'       => array( 'label' => 'TT Start (Formatted)',      'group' => 'Ticket Tailor',  'meta' => '_tt_start_formatted' ),
            'tt_end_date'              => array( 'label' => 'TT End Date',               'group' => 'Ticket Tailor',  'meta' => '_tt_end_date' ),
            'tt_end_time'              => array( 'label' => 'TT End Time',               'group' => 'Ticket Tailor',  'meta' => '_tt_end_time' ),
            'tt_end_formatted'         => array( 'label' => 'TT End (Formatted)',        'group' => 'Ticket Tailor',  'meta' => '_tt_end_formatted' ),
            'tt_venue_name'            => array( 'label' => 'TT Venue',                  'group' => 'Ticket Tailor',  'meta' => '_tt_venue_name' ),
            'tt_venue_country'         => array( 'label' => 'TT Venue Country',          'group' => 'Ticket Tailor',  'meta' => '_tt_venue_country' ),
            'tt_image_header'          => array( 'label' => 'TT Header Image URL',       'group' => 'Ticket Tailor',  'meta' => '_tt_image_header' ),
            'tt_image_thumbnail'       => array( 'label' => 'TT Thumbnail URL',          'group' => 'Ticket Tailor',  'meta' => '_tt_image_thumbnail' ),
            'tt_checkout_url'          => array( 'label' => 'TT Checkout URL',           'group' => 'Ticket Tailor',  'meta' => '_tt_checkout_url' ),
            'tt_event_url'             => array( 'label' => 'TT Event Page URL',         'group' => 'Ticket Tailor',  'meta' => '_tt_event_url' ),
            'tt_call_to_action'        => array( 'label' => 'TT Call to Action',         'group' => 'Ticket Tailor',  'meta' => '_tt_call_to_action' ),
            'tt_online_event'          => array( 'label' => 'TT Online Event',           'group' => 'Ticket Tailor',  'meta' => '_tt_online_event' ),
            'tt_tickets_available'     => array( 'label' => 'TT Tickets Available',      'group' => 'Ticket Tailor',  'meta' => '_tt_tickets_available' ),
            'tt_min_price'             => array( 'label' => 'TT Min Price',              'group' => 'Ticket Tailor',  'meta' => '_tt_min_price' ),
            'tt_max_price'             => array( 'label' => 'TT Max Price',              'group' => 'Ticket Tailor',  'meta' => '_tt_max_price' ),
            'tt_min_price_formatted'   => array( 'label' => 'TT Min Price (Formatted)',  'group' => 'Ticket Tailor',  'meta' => '_tt_min_price' ),
            'tt_max_price_formatted'   => array( 'label' => 'TT Max Price (Formatted)',  'group' => 'Ticket Tailor',  'meta' => '_tt_max_price' ),
            'tt_total_capacity'        => array( 'label' => 'TT Total Capacity',         'group' => 'Ticket Tailor',  'meta' => '_tt_total_capacity' ),
            'tt_tickets_remaining'     => array( 'label' => 'TT Tickets Remaining',      'group' => 'Ticket Tailor',  'meta' => '_tt_tickets_remaining' ),
            'tt_total_orders'          => array( 'label' => 'TT Total Orders',           'group' => 'Ticket Tailor',  'meta' => '_tt_total_orders' ),
            'tt_currency'              => array( 'label' => 'TT Currency',               'group' => 'Ticket Tailor',  'meta' => '_tt_currency' ),
            'tt_timezone'              => array( 'label' => 'TT Timezone',               'group' => 'Ticket Tailor',  'meta' => '_tt_timezone' ),
        );
    }

    public static function init() {
        if ( ! defined( 'BRICKS_VERSION' ) ) {
            return;
        }

        add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'register_tags' ) );
        add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 10, 3 );
        add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 10, 3 );
        add_filter( 'bricks/setup/control_options', array( __CLASS__, 'add_query_loop_option' ) );
    }

    /**
     * @param array $tags
     * @return array
     */
    public static function register_tags( $tags ) {
        foreach ( self::get_tags() as $name => $config ) {
            $tags[] = array(
                'name'  => '{' . $name . '}',
                'label' => $config['label'],
                'group' => $config['group'],
            );
        }

        return $tags;
    }

    /**
     * @param string $tag
     * @param mixed  $post
     * @param string $context
     * @return string
     */
    public static function render_tag( $tag, $post, $context = 'text' ) {
        $name    = trim( $tag, '{}' );
        $all_tags = self::get_tags();

        if ( ! isset( $all_tags[ $name ] ) ) {
            return $tag;
        }

        $post_id = is_object( $post ) ? $post->ID : intval( $post );

        if ( get_post_type( $post_id ) !== Tailor_Made_CPT::POST_TYPE ) {
            return '';
        }

        if ( $name === 'tt_event_name' ) {
            return get_the_title( $post_id );
        }

        if ( $name === 'tt_event_description' ) {
            $p = get_post( $post_id );
            return $p ? $p->post_content : '';
        }

        if ( $name === 'tt_min_price_formatted' || $name === 'tt_max_price_formatted' ) {
            $cents    = intval( get_post_meta( $post_id, $all_tags[ $name ]['meta'], true ) );
            $currency = strtoupper( get_post_meta( $post_id, '_tt_currency', true ) );
            if ( empty( $currency ) ) {
                $currency = 'USD';
            }
            return self::format_price( $cents, $currency );
        }

        $meta_key = isset( $all_tags[ $name ]['meta'] ) ? $all_tags[ $name ]['meta'] : null;
        if ( ! $meta_key ) {
            return '';
        }

        return get_post_meta( $post_id, $meta_key, true );
    }

    /**
     * @param string $content
     * @param mixed  $post
     * @param string $context
     * @return string
     */
    public static function render_content( $content, $post, $context = 'text' ) {
        if ( preg_match_all( '/\{(tt_[a-z_]+)\}/', $content, $matches ) ) {
            $all_tags = self::get_tags();
            foreach ( $matches[0] as $i => $full_match ) {
                $tag_name = $matches[1][ $i ];
                if ( isset( $all_tags[ $tag_name ] ) ) {
                    $value   = self::render_tag( $full_match, $post, $context );
                    $content = str_replace( $full_match, $value, $content );
                }
            }
        }

        return $content;
    }

    /**
     * @param array $options
     * @return array
     */
    public static function add_query_loop_option( $options ) {
        if ( isset( $options['postTypes'] ) ) {
            $options['postTypes'][ Tailor_Made_CPT::POST_TYPE ] = 'TT Events';
        }

        return $options;
    }

    /**
     * @param int    $cents
     * @param string $currency
     * @return string
     */
    private static function format_price( $cents, $currency = 'USD' ) {
        $symbols = array(
            'USD' => '$', 'GBP' => "\xC2\xA3", 'EUR' => "\xE2\x82\xAC",
            'CAD' => 'CA$', 'AUD' => 'A$',
        );

        $symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';
        $amount = number_format( $cents / 100, 2 );

        if ( $cents === 0 ) {
            return 'Free';
        }

        return $symbol . $amount;
    }
}
