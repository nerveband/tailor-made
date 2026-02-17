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
     * All dynamic data tags we register.
     */
    private static array $tags = [
        'tt_event_name'            => [ 'label' => 'TT Event Name',             'group' => 'Ticket Tailor',  'meta' => null ],
        'tt_event_description'     => [ 'label' => 'TT Event Description',      'group' => 'Ticket Tailor',  'meta' => null ],
        'tt_event_id'              => [ 'label' => 'TT Event ID',               'group' => 'Ticket Tailor',  'meta' => '_tt_event_id' ],
        'tt_status'                => [ 'label' => 'TT Status',                 'group' => 'Ticket Tailor',  'meta' => '_tt_status' ],
        'tt_start_date'            => [ 'label' => 'TT Start Date',             'group' => 'Ticket Tailor',  'meta' => '_tt_start_date' ],
        'tt_start_time'            => [ 'label' => 'TT Start Time',             'group' => 'Ticket Tailor',  'meta' => '_tt_start_time' ],
        'tt_start_formatted'       => [ 'label' => 'TT Start (Formatted)',      'group' => 'Ticket Tailor',  'meta' => '_tt_start_formatted' ],
        'tt_end_date'              => [ 'label' => 'TT End Date',               'group' => 'Ticket Tailor',  'meta' => '_tt_end_date' ],
        'tt_end_time'              => [ 'label' => 'TT End Time',               'group' => 'Ticket Tailor',  'meta' => '_tt_end_time' ],
        'tt_end_formatted'         => [ 'label' => 'TT End (Formatted)',        'group' => 'Ticket Tailor',  'meta' => '_tt_end_formatted' ],
        'tt_venue_name'            => [ 'label' => 'TT Venue',                  'group' => 'Ticket Tailor',  'meta' => '_tt_venue_name' ],
        'tt_venue_country'         => [ 'label' => 'TT Venue Country',          'group' => 'Ticket Tailor',  'meta' => '_tt_venue_country' ],
        'tt_image_header'          => [ 'label' => 'TT Header Image URL',       'group' => 'Ticket Tailor',  'meta' => '_tt_image_header' ],
        'tt_image_thumbnail'       => [ 'label' => 'TT Thumbnail URL',          'group' => 'Ticket Tailor',  'meta' => '_tt_image_thumbnail' ],
        'tt_checkout_url'          => [ 'label' => 'TT Checkout URL',           'group' => 'Ticket Tailor',  'meta' => '_tt_checkout_url' ],
        'tt_event_url'             => [ 'label' => 'TT Event Page URL',         'group' => 'Ticket Tailor',  'meta' => '_tt_event_url' ],
        'tt_call_to_action'        => [ 'label' => 'TT Call to Action',         'group' => 'Ticket Tailor',  'meta' => '_tt_call_to_action' ],
        'tt_online_event'          => [ 'label' => 'TT Online Event',           'group' => 'Ticket Tailor',  'meta' => '_tt_online_event' ],
        'tt_tickets_available'     => [ 'label' => 'TT Tickets Available',      'group' => 'Ticket Tailor',  'meta' => '_tt_tickets_available' ],
        'tt_min_price'             => [ 'label' => 'TT Min Price',              'group' => 'Ticket Tailor',  'meta' => '_tt_min_price' ],
        'tt_max_price'             => [ 'label' => 'TT Max Price',              'group' => 'Ticket Tailor',  'meta' => '_tt_max_price' ],
        'tt_min_price_formatted'   => [ 'label' => 'TT Min Price (Formatted)',  'group' => 'Ticket Tailor',  'meta' => '_tt_min_price' ],
        'tt_max_price_formatted'   => [ 'label' => 'TT Max Price (Formatted)',  'group' => 'Ticket Tailor',  'meta' => '_tt_max_price' ],
        'tt_total_capacity'        => [ 'label' => 'TT Total Capacity',         'group' => 'Ticket Tailor',  'meta' => '_tt_total_capacity' ],
        'tt_tickets_remaining'     => [ 'label' => 'TT Tickets Remaining',      'group' => 'Ticket Tailor',  'meta' => '_tt_tickets_remaining' ],
        'tt_total_orders'          => [ 'label' => 'TT Total Orders',           'group' => 'Ticket Tailor',  'meta' => '_tt_total_orders' ],
        'tt_currency'              => [ 'label' => 'TT Currency',               'group' => 'Ticket Tailor',  'meta' => '_tt_currency' ],
        'tt_timezone'              => [ 'label' => 'TT Timezone',               'group' => 'Ticket Tailor',  'meta' => '_tt_timezone' ],
    ];

    public static function init(): void {
        // Only register if Bricks is active
        if ( ! defined( 'BRICKS_VERSION' ) ) {
            return;
        }

        add_filter( 'bricks/dynamic_tags_list', [ __CLASS__, 'register_tags' ] );
        add_filter( 'bricks/dynamic_data/render_tag', [ __CLASS__, 'render_tag' ], 10, 3 );
        add_filter( 'bricks/dynamic_data/render_content', [ __CLASS__, 'render_content' ], 10, 3 );
        add_filter( 'bricks/setup/control_options', [ __CLASS__, 'add_query_loop_option' ] );
    }

    /**
     * Register all dynamic data tags with Bricks.
     */
    public static function register_tags( array $tags ): array {
        foreach ( self::$tags as $name => $config ) {
            $tags[] = [
                'name'  => '{' . $name . '}',
                'label' => $config['label'],
                'group' => $config['group'],
            ];
        }

        return $tags;
    }

    /**
     * Render a single dynamic tag.
     */
    public static function render_tag( string $tag, $post, string $context = 'text' ) {
        // Strip braces
        $name = trim( $tag, '{}' );

        if ( ! isset( self::$tags[ $name ] ) ) {
            return $tag;
        }

        $post_id = is_object( $post ) ? $post->ID : intval( $post );

        if ( get_post_type( $post_id ) !== Tailor_Made_CPT::POST_TYPE ) {
            return '';
        }

        // Special cases: title and content come from the post itself
        if ( $name === 'tt_event_name' ) {
            return get_the_title( $post_id );
        }

        if ( $name === 'tt_event_description' ) {
            $p = get_post( $post_id );
            return $p ? $p->post_content : '';
        }

        // Formatted price (cents to dollars)
        if ( $name === 'tt_min_price_formatted' || $name === 'tt_max_price_formatted' ) {
            $cents    = intval( get_post_meta( $post_id, self::$tags[ $name ]['meta'], true ) );
            $currency = strtoupper( get_post_meta( $post_id, '_tt_currency', true ) ?: 'USD' );
            return self::format_price( $cents, $currency );
        }

        $meta_key = self::$tags[ $name ]['meta'] ?? null;
        if ( ! $meta_key ) {
            return '';
        }

        return get_post_meta( $post_id, $meta_key, true );
    }

    /**
     * Replace dynamic tags within rendered content strings.
     */
    public static function render_content( string $content, $post, string $context = 'text' ): string {
        // Find all {tt_*} tags in the content
        if ( preg_match_all( '/\{(tt_[a-z_]+)\}/', $content, $matches ) ) {
            foreach ( $matches[0] as $i => $full_match ) {
                $tag_name = $matches[1][ $i ];
                if ( isset( self::$tags[ $tag_name ] ) ) {
                    $value   = self::render_tag( $full_match, $post, $context );
                    $content = str_replace( $full_match, $value, $content );
                }
            }
        }

        return $content;
    }

    /**
     * Add tt_event to Bricks query loop post type options.
     */
    public static function add_query_loop_option( array $options ): array {
        if ( isset( $options['postTypes'] ) ) {
            $options['postTypes'][ Tailor_Made_CPT::POST_TYPE ] = 'TT Events';
        }

        return $options;
    }

    /**
     * Format cents into a price string.
     */
    private static function format_price( int $cents, string $currency = 'USD' ): string {
        $symbols = [
            'USD' => '$', 'GBP' => '£', 'EUR' => '€',
            'CAD' => 'CA$', 'AUD' => 'A$',
        ];

        $symbol = $symbols[ $currency ] ?? $currency . ' ';
        $amount = number_format( $cents / 100, 2 );

        if ( $cents === 0 ) {
            return 'Free';
        }

        return $symbol . $amount;
    }
}
