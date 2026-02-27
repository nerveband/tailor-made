<?php
/**
 * Shortcode registration and rendering for Tailor Made.
 *
 * Provides [tt_events], [tt_event], [tt_event_field], and [tt_upcoming_count].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Shortcodes {

    /** @var bool */
    private static $css_enqueued = false;

    /** @var array URL-type meta fields (escaped with esc_url). */
    private static $url_fields = array(
        'checkout_url',
        'event_url',
        'image_header',
        'image_thumbnail',
    );

    /** @var array Price fields stored in cents. */
    private static $price_fields = array(
        'min_price',
        'max_price',
    );

    public static function init(): void {
        add_shortcode( 'tt_events', array( __CLASS__, 'shortcode_events' ) );
        add_shortcode( 'tt_event', array( __CLASS__, 'shortcode_event' ) );
        add_shortcode( 'tt_event_field', array( __CLASS__, 'shortcode_event_field' ) );
        add_shortcode( 'tt_upcoming_count', array( __CLASS__, 'shortcode_upcoming_count' ) );
    }

    /**
     * Register the shortcodes stylesheet.
     */
    public static function register_assets(): void {
        wp_register_style(
            'tailor-made-shortcodes',
            TAILOR_MADE_URL . 'assets/css/shortcodes.css',
            array(),
            TAILOR_MADE_VERSION
        );
    }

    /**
     * Enqueue CSS on first shortcode use.
     */
    private static function maybe_enqueue_css(): void {
        if ( ! self::$css_enqueued ) {
            wp_enqueue_style( 'tailor-made-shortcodes' );
            self::$css_enqueued = true;
        }
    }

    // -------------------------------------------------------------------------
    // [tt_events]
    // -------------------------------------------------------------------------

    /**
     * @param array|string $atts
     * @return string
     */
    public static function shortcode_events( $atts ): string {
        $atts = shortcode_atts( array(
            'limit'      => 6,
            'status'     => 'publish',
            'orderby'    => '_tt_start_unix',
            'order'      => 'ASC',
            'columns'    => 3,
            'show'       => 'image,title,date,price,location,description,button',
            'style'      => 'grid',
            'class'      => '',
            'box_office' => '',
        ), $atts, 'tt_events' );

        $query_args = array(
            'post_type'      => Tailor_Made_CPT::POST_TYPE,
            'posts_per_page' => absint( $atts['limit'] ),
            'post_status'    => sanitize_key( $atts['status'] ),
            'meta_key'       => sanitize_key( $atts['orderby'] ),
            'orderby'        => 'meta_value_num',
            'order'          => strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC',
        );

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

        $posts = get_posts( $query_args );

        if ( empty( $posts ) ) {
            return '<!-- tt_events: no events found -->';
        }

        self::maybe_enqueue_css();

        $show    = array_map( 'trim', explode( ',', $atts['show'] ) );
        $style   = $atts['style'] === 'list' ? 'list' : 'grid';
        $columns = absint( $atts['columns'] );
        $extra   = ! empty( $atts['class'] ) ? ' ' . esc_attr( $atts['class'] ) : '';

        $html = '<div class="tt-events tt-events--' . esc_attr( $style ) . $extra . '"'
              . ' style="--tt-columns: ' . $columns . ';">';

        foreach ( $posts as $post ) {
            $html .= self::render_card( $post, $show );
        }

        $html .= '</div>';

        return $html;
    }

    // -------------------------------------------------------------------------
    // [tt_event]
    // -------------------------------------------------------------------------

    /**
     * @param array|string $atts
     * @return string
     */
    public static function shortcode_event( $atts ): string {
        $atts = shortcode_atts( array(
            'id'   => '',
            'show' => 'image,title,date,price,location,description,button',
        ), $atts, 'tt_event' );

        if ( empty( $atts['id'] ) ) {
            return '<!-- tt_event: id attribute is required -->';
        }

        $post = self::resolve_post( $atts['id'] );

        if ( ! $post ) {
            return '<!-- tt_event: event not found -->';
        }

        self::maybe_enqueue_css();

        $show = array_map( 'trim', explode( ',', $atts['show'] ) );

        return '<div class="tt-events tt-events--single">' . self::render_card( $post, $show ) . '</div>';
    }

    // -------------------------------------------------------------------------
    // [tt_event_field]
    // -------------------------------------------------------------------------

    /**
     * @param array|string $atts
     * @return string
     */
    public static function shortcode_event_field( $atts ): string {
        $atts = shortcode_atts( array(
            'field' => '',
            'id'    => '',
        ), $atts, 'tt_event_field' );

        if ( empty( $atts['field'] ) ) {
            return '<!-- tt_event_field: field attribute is required -->';
        }

        $field = sanitize_key( $atts['field'] );

        if ( ! empty( $atts['id'] ) ) {
            $post = self::resolve_post( $atts['id'] );
            $post_id = $post ? $post->ID : 0;
        } else {
            $post_id = get_the_ID();
        }

        if ( ! $post_id || get_post_type( $post_id ) !== Tailor_Made_CPT::POST_TYPE ) {
            return '';
        }

        if ( $field === 'box_office_name' ) {
            $terms = wp_get_post_terms( $post_id, 'tt_box_office', array( 'fields' => 'names' ) );
            $value = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : '';
            if ( empty( $value ) ) {
                return '';
            }
            return '<span class="tt-field tt-field--box_office_name">' . esc_html( $value ) . '</span>';
        }

        $meta_key = '_tt_' . $field;
        $value    = get_post_meta( $post_id, $meta_key, true );

        if ( $value === '' || $value === false ) {
            return '';
        }

        // Smart escaping based on field type.
        if ( in_array( $field, self::$url_fields, true ) ) {
            $escaped = esc_url( $value );
        } elseif ( in_array( $field, self::$price_fields, true ) ) {
            $currency = strtoupper( get_post_meta( $post_id, '_tt_currency', true ) );
            if ( empty( $currency ) ) {
                $currency = 'USD';
            }
            $escaped = esc_html( self::format_price( intval( $value ), $currency ) );
        } else {
            $escaped = esc_html( $value );
        }

        return '<span class="tt-field tt-field--' . esc_attr( $field ) . '">' . $escaped . '</span>';
    }

    // -------------------------------------------------------------------------
    // [tt_upcoming_count]
    // -------------------------------------------------------------------------

    /**
     * @param array|string $atts
     * @return string
     */
    public static function shortcode_upcoming_count( $atts ): string {
        $atts = shortcode_atts( array(
            'box_office' => '',
        ), $atts, 'tt_upcoming_count' );

        $count = self::get_upcoming_count( $atts['box_office'] );
        return '<span class="tt-upcoming-count">' . esc_html( $count ) . '</span>';
    }

    // -------------------------------------------------------------------------
    // Shared Rendering
    // -------------------------------------------------------------------------

    /**
     * Render a single event card.
     *
     * @param WP_Post $post
     * @param array   $show_fields
     * @return string
     */
    private static function render_card( WP_Post $post, array $show_fields ): string {
        $bo_slug = '';
        $terms = wp_get_post_terms( $post->ID, 'tt_box_office', array( 'fields' => 'slugs' ) );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            $bo_slug = $terms[0];
        }
        $bo_class = $bo_slug ? ' tt-box-office-' . esc_attr( $bo_slug ) : '';
        $html = '<div class="tt-event-card' . $bo_class . '">';

        // Image
        if ( in_array( 'image', $show_fields, true ) ) {
            $thumb_id = get_post_thumbnail_id( $post->ID );
            if ( $thumb_id ) {
                $img_url = wp_get_attachment_image_url( $thumb_id, 'medium_large' );
                if ( $img_url ) {
                    $html .= '<div class="tt-event-card__image">';
                    $html .= '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( get_the_title( $post ) ) . '" loading="lazy" />';
                    $html .= '</div>';
                }
            }
        }

        $html .= '<div class="tt-event-card__body">';

        // Title
        if ( in_array( 'title', $show_fields, true ) ) {
            $html .= '<h3 class="tt-event-card__title">' . esc_html( get_the_title( $post ) ) . '</h3>';
        }

        // Date
        if ( in_array( 'date', $show_fields, true ) ) {
            $date = get_post_meta( $post->ID, '_tt_start_formatted', true );
            if ( $date ) {
                $html .= '<p class="tt-event-card__date">' . esc_html( $date ) . '</p>';
            }
        }

        // Price
        if ( in_array( 'price', $show_fields, true ) ) {
            $price = get_post_meta( $post->ID, '_tt_price_display', true );
            if ( $price ) {
                $html .= '<p class="tt-event-card__price">' . esc_html( $price ) . '</p>';
            }
        }

        // Location
        if ( in_array( 'location', $show_fields, true ) ) {
            $venue = get_post_meta( $post->ID, '_tt_venue_name', true );
            if ( $venue ) {
                $html .= '<p class="tt-event-card__location">' . esc_html( $venue ) . '</p>';
            }
        }

        // Description
        if ( in_array( 'description', $show_fields, true ) ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
            if ( $excerpt ) {
                $html .= '<p class="tt-event-card__description">' . esc_html( $excerpt ) . '</p>';
            }
        }

        // Button
        if ( in_array( 'button', $show_fields, true ) ) {
            $checkout_url = get_post_meta( $post->ID, '_tt_checkout_url', true );
            $cta          = get_post_meta( $post->ID, '_tt_call_to_action', true );
            if ( empty( $cta ) ) {
                $cta = __( 'Get Tickets', 'tailor-made' );
            }
            if ( $checkout_url ) {
                $html .= '<a class="tt-event-card__button" href="' . esc_url( $checkout_url ) . '" target="_blank" rel="noopener noreferrer">'
                        . esc_html( $cta ) . '</a>';
            }
        }

        $html .= '</div>'; // __body
        $html .= '</div>'; // __card

        return $html;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a post by WP post ID or TT event ID (ev_xxx).
     *
     * @param string|int $id
     * @return WP_Post|null
     */
    private static function resolve_post( $id ) {
        $id = trim( $id );

        // TT event ID format: ev_1234567
        if ( strpos( $id, 'ev_' ) === 0 ) {
            $posts = get_posts( array(
                'post_type'   => Tailor_Made_CPT::POST_TYPE,
                'meta_key'    => '_tt_event_id',
                'meta_value'  => sanitize_text_field( $id ),
                'numberposts' => 1,
                'post_status' => 'any',
            ) );

            return ! empty( $posts ) ? $posts[0] : null;
        }

        // WP post ID
        $post = get_post( absint( $id ) );
        if ( $post && $post->post_type === Tailor_Made_CPT::POST_TYPE ) {
            return $post;
        }

        return null;
    }

    /**
     * Count upcoming events (start time > now).
     *
     * @param string $box_office Optional comma-separated box office slugs.
     * @return int
     */
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

    /**
     * Format a price in cents to a display string.
     *
     * @param int    $cents
     * @param string $currency
     * @return string
     */
    private static function format_price( int $cents, string $currency = 'USD' ): string {
        $symbols = array(
            'USD' => '$', 'GBP' => "\xC2\xA3", 'EUR' => "\xE2\x82\xAC",
            'CAD' => 'CA$', 'AUD' => 'A$',
        );

        if ( $cents === 0 ) {
            return __( 'Free', 'tailor-made' );
        }

        $symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';
        return $symbol . number_format( $cents / 100, 2 );
    }
}
