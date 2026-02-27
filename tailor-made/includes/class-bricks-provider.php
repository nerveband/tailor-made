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
        $group = __( 'Ticket Tailor', 'tailor-made' );
        return array(
            'tt_event_name'            => array( 'label' => __( 'TT Event Name', 'tailor-made' ),             'group' => $group,  'meta' => null,                  'type' => 'text' ),
            'tt_event_description'     => array( 'label' => __( 'TT Event Description', 'tailor-made' ),      'group' => $group,  'meta' => null,                  'type' => 'html' ),
            'tt_event_id'              => array( 'label' => __( 'TT Event ID', 'tailor-made' ),               'group' => $group,  'meta' => '_tt_event_id',        'type' => 'text' ),
            'tt_status'                => array( 'label' => __( 'TT Status', 'tailor-made' ),                 'group' => $group,  'meta' => '_tt_status',          'type' => 'text' ),
            'tt_start_date'            => array( 'label' => __( 'TT Start Date', 'tailor-made' ),             'group' => $group,  'meta' => '_tt_start_date',      'type' => 'text' ),
            'tt_start_time'            => array( 'label' => __( 'TT Start Time', 'tailor-made' ),             'group' => $group,  'meta' => '_tt_start_time',      'type' => 'text' ),
            'tt_start_formatted'       => array( 'label' => __( 'TT Start (Formatted)', 'tailor-made' ),      'group' => $group,  'meta' => '_tt_start_formatted', 'type' => 'text' ),
            'tt_end_date'              => array( 'label' => __( 'TT End Date', 'tailor-made' ),               'group' => $group,  'meta' => '_tt_end_date',        'type' => 'text' ),
            'tt_end_time'              => array( 'label' => __( 'TT End Time', 'tailor-made' ),               'group' => $group,  'meta' => '_tt_end_time',        'type' => 'text' ),
            'tt_end_formatted'         => array( 'label' => __( 'TT End (Formatted)', 'tailor-made' ),        'group' => $group,  'meta' => '_tt_end_formatted',   'type' => 'text' ),
            'tt_venue_name'            => array( 'label' => __( 'TT Venue', 'tailor-made' ),                  'group' => $group,  'meta' => '_tt_venue_name',      'type' => 'text' ),
            'tt_venue_country'         => array( 'label' => __( 'TT Venue Country', 'tailor-made' ),          'group' => $group,  'meta' => '_tt_venue_country',   'type' => 'text' ),
            'tt_image_header'          => array( 'label' => __( 'TT Header Image URL', 'tailor-made' ),       'group' => $group,  'meta' => '_tt_image_header',    'type' => 'url' ),
            'tt_image_thumbnail'       => array( 'label' => __( 'TT Thumbnail URL', 'tailor-made' ),          'group' => $group,  'meta' => '_tt_image_thumbnail', 'type' => 'url' ),
            'tt_checkout_url'          => array( 'label' => __( 'TT Checkout URL', 'tailor-made' ),           'group' => $group,  'meta' => '_tt_checkout_url',    'type' => 'url' ),
            'tt_event_url'             => array( 'label' => __( 'TT Event Page URL', 'tailor-made' ),         'group' => $group,  'meta' => '_tt_event_url',       'type' => 'url' ),
            'tt_call_to_action'        => array( 'label' => __( 'TT Call to Action', 'tailor-made' ),         'group' => $group,  'meta' => '_tt_call_to_action',  'type' => 'text' ),
            'tt_online_event'          => array( 'label' => __( 'TT Online Event', 'tailor-made' ),           'group' => $group,  'meta' => '_tt_online_event',    'type' => 'text' ),
            'tt_tickets_available'     => array( 'label' => __( 'TT Tickets Available', 'tailor-made' ),      'group' => $group,  'meta' => '_tt_tickets_available', 'type' => 'text' ),
            'tt_min_price'             => array( 'label' => __( 'TT Min Price', 'tailor-made' ),              'group' => $group,  'meta' => '_tt_min_price',       'type' => 'text' ),
            'tt_max_price'             => array( 'label' => __( 'TT Max Price', 'tailor-made' ),              'group' => $group,  'meta' => '_tt_max_price',       'type' => 'text' ),
            'tt_min_price_formatted'   => array( 'label' => __( 'TT Min Price (Formatted)', 'tailor-made' ),  'group' => $group,  'meta' => '_tt_min_price',       'type' => 'text' ),
            'tt_max_price_formatted'   => array( 'label' => __( 'TT Max Price (Formatted)', 'tailor-made' ),  'group' => $group,  'meta' => '_tt_max_price',       'type' => 'text' ),
            'tt_total_capacity'        => array( 'label' => __( 'TT Total Capacity', 'tailor-made' ),         'group' => $group,  'meta' => '_tt_total_capacity',  'type' => 'text' ),
            'tt_tickets_remaining'     => array( 'label' => __( 'TT Tickets Remaining', 'tailor-made' ),      'group' => $group,  'meta' => '_tt_tickets_remaining', 'type' => 'text' ),
            'tt_total_orders'          => array( 'label' => __( 'TT Total Orders', 'tailor-made' ),           'group' => $group,  'meta' => '_tt_total_orders',    'type' => 'text' ),
            'tt_currency'              => array( 'label' => __( 'TT Currency', 'tailor-made' ),               'group' => $group,  'meta' => '_tt_currency',        'type' => 'text' ),
            'tt_timezone'              => array( 'label' => __( 'TT Timezone', 'tailor-made' ),               'group' => $group,  'meta' => '_tt_timezone',        'type' => 'text' ),
            'tt_box_office_name'       => array( 'label' => __( 'TT Box Office Name', 'tailor-made' ),        'group' => $group,  'meta' => null,                  'type' => 'text' ),
            'tt_box_office_slug'       => array( 'label' => __( 'TT Box Office Slug', 'tailor-made' ),        'group' => $group,  'meta' => null,                  'type' => 'text' ),
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
    public static function render_tag( $tag, $post = null, $context = 'text' ) {
        if ( ! is_string( $tag ) ) {
            return $tag;
        }

        $name    = trim( $tag, '{}' );
        $all_tags = self::get_tags();

        if ( ! isset( $all_tags[ $name ] ) ) {
            return $tag;
        }

        if ( empty( $post ) ) {
            return '';
        }

        $post_id = is_object( $post ) ? $post->ID : intval( $post );

        if ( $post_id <= 0 || get_post_type( $post_id ) !== Tailor_Made_CPT::POST_TYPE ) {
            return '';
        }

        $tag_config = $all_tags[ $name ];
        $tag_type   = isset( $tag_config['type'] ) ? $tag_config['type'] : 'text';

        if ( $name === 'tt_event_name' ) {
            return esc_html( get_the_title( $post_id ) );
        }

        if ( $name === 'tt_event_description' ) {
            // HTML content — already sanitized via wp_kses_post at save time
            $p = get_post( $post_id );
            return $p ? $p->post_content : '';
        }

        if ( $name === 'tt_box_office_name' ) {
            $terms = wp_get_post_terms( $post_id, 'tt_box_office', array( 'fields' => 'names' ) );
            return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? esc_html( $terms[0] ) : '';
        }

        if ( $name === 'tt_box_office_slug' ) {
            $terms = wp_get_post_terms( $post_id, 'tt_box_office', array( 'fields' => 'slugs' ) );
            return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? esc_html( $terms[0] ) : '';
        }

        if ( $name === 'tt_min_price_formatted' || $name === 'tt_max_price_formatted' ) {
            $cents    = intval( get_post_meta( $post_id, $tag_config['meta'], true ) );
            $currency = strtoupper( get_post_meta( $post_id, '_tt_currency', true ) );
            if ( empty( $currency ) ) {
                $currency = 'USD';
            }
            return esc_html( self::format_price( $cents, $currency ) );
        }

        $meta_key = isset( $tag_config['meta'] ) ? $tag_config['meta'] : null;
        if ( ! $meta_key ) {
            return '';
        }

        $value = get_post_meta( $post_id, $meta_key, true );

        if ( $tag_type === 'url' ) {
            return esc_url( $value );
        }

        if ( $context === 'text' ) {
            return esc_html( $value );
        }

        return $value;
    }

    /**
     * @param string $content
     * @param mixed  $post
     * @param string $context
     * @return string
     */
    public static function render_content( $content, $post = null, $context = 'text' ) {
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
