<?php
/**
 * Magic Links — Shareable attendee roster for organizers/volunteers.
 *
 * Provides unguessable URLs that display a read-only attendee roster for a specific event.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_Magic_Links {

    /** @var int Cache TTL in seconds. */
    const CACHE_TTL = 300; // 5 minutes

    /** @var bool Whether we're rendering a roster (for noindex hook). */
    private static $rendering_roster = false;

    /**
     * Register shortcode and hooks.
     */
    public static function init(): void {
        add_shortcode( 'tt_roster', [ __CLASS__, 'shortcode_roster' ] );
        add_shortcode( 'tt_roster_box_office', [ __CLASS__, 'shortcode_roster_box_office' ] );
        add_action( 'wp_head', [ __CLASS__, 'maybe_noindex' ] );
    }

    // -------------------------------------------------------------------------
    // Token Management
    // -------------------------------------------------------------------------

    /**
     * Generate a new token for an event post.
     */
    public static function generate_token( int $post_id ): string {
        $token = bin2hex( random_bytes( 32 ) );
        update_post_meta( $post_id, '_tt_roster_token', $token );
        update_post_meta( $post_id, '_tt_roster_token_created', time() );
        return $token;
    }

    /**
     * Rotate token — generates a new one, old one stops working.
     */
    public static function rotate_token( int $post_id ): string {
        // Clear cached roster data since the token is changing.
        delete_post_meta( $post_id, '_tt_roster_data' );
        delete_post_meta( $post_id, '_tt_roster_fetched_at' );
        return self::generate_token( $post_id );
    }

    /**
     * Revoke token — deletes all magic link meta.
     */
    public static function revoke_token( int $post_id ): void {
        delete_post_meta( $post_id, '_tt_roster_token' );
        delete_post_meta( $post_id, '_tt_roster_token_created' );
        delete_post_meta( $post_id, '_tt_roster_data' );
        delete_post_meta( $post_id, '_tt_roster_fetched_at' );
    }

    /**
     * Get current token for a post.
     */
    public static function get_token( int $post_id ) {
        $token = get_post_meta( $post_id, '_tt_roster_token', true );
        return $token ? $token : false;
    }

    /**
     * Find the post ID that has a given token.
     *
     * @return int|false
     */
    public static function find_post_by_token( string $token ) {
        $posts = get_posts( array(
            'post_type'   => 'tt_event',
            'numberposts' => 1,
            'post_status' => 'any',
            'meta_key'    => '_tt_roster_token',
            'meta_value'  => $token,
            'fields'      => 'ids',
        ) );

        return ! empty( $posts ) ? (int) $posts[0] : false;
    }

    /**
     * Build the full roster URL for a given post.
     */
    public static function get_roster_url( int $post_id ): string {
        $token   = self::get_token( $post_id );
        $page_id = get_option( 'tailor_made_roster_page_id' );

        if ( ! $token || ! $page_id ) {
            return '';
        }

        return add_query_arg( 'token', $token, get_permalink( $page_id ) );
    }

    // -------------------------------------------------------------------------
    // Roster Data
    // -------------------------------------------------------------------------

    /**
     * Get roster data for an event, with caching.
     *
     * @return array|WP_Error
     */
    public static function get_roster_data( int $post_id ) {
        // Check cache.
        $fetched_at = (int) get_post_meta( $post_id, '_tt_roster_fetched_at', true );
        if ( $fetched_at && ( time() - $fetched_at ) < self::CACHE_TTL ) {
            $cached = get_post_meta( $post_id, '_tt_roster_data', true );
            if ( $cached ) {
                $data = json_decode( $cached, true );
                if ( is_array( $data ) ) {
                    return $data;
                }
            }
        }

        // Fetch fresh data from API.
        $tt_event_id = get_post_meta( $post_id, '_tt_event_id', true );
        if ( ! $tt_event_id ) {
            return new WP_Error( 'no_event_id', __( 'No Ticket Tailor event ID found for this post.', 'tailor-made' ) );
        }

        // Look up the correct API key for this event's box office.
        $bo_id   = get_post_meta( $post_id, '_tt_box_office_id', true );
        $api_key = null;
        if ( $bo_id ) {
            $bo = Tailor_Made_Box_Office_Manager::get( (int) $bo_id );
            if ( $bo ) {
                $api_key = $bo->api_key;
            }
        }
        $client  = new Tailor_Made_API_Client( $api_key );
        $tickets = $client->get_issued_tickets_for_event( $tt_event_id );

        if ( is_wp_error( $tickets ) ) {
            return $tickets;
        }

        // Build roster data.
        $attendees = array();
        $valid     = 0;
        $voided    = 0;
        $refunded  = 0;

        // Collect all unique custom questions across tickets.
        $all_questions = array();

        foreach ( $tickets as $ticket ) {
            $status = isset( $ticket['status'] ) ? $ticket['status'] : 'unknown';

            if ( $status === 'valid' ) {
                $valid++;
            } elseif ( $status === 'void' || $status === 'voided' ) {
                $voided++;
            } elseif ( $status === 'refunded' ) {
                $refunded++;
            }

            // Process custom questions.
            $custom_questions = array();
            if ( ! empty( $ticket['custom_questions'] ) && is_array( $ticket['custom_questions'] ) ) {
                foreach ( $ticket['custom_questions'] as $cq ) {
                    $q = isset( $cq['question'] ) ? $cq['question'] : '';
                    $r = isset( $cq['response'] ) ? $cq['response'] : '';
                    if ( $q ) {
                        $custom_questions[ $q ] = $r;
                        if ( ! in_array( $q, $all_questions, true ) ) {
                            $all_questions[] = $q;
                        }
                    }
                }
            }

            $ticket_type_name = '';
            if ( isset( $ticket['ticket_type'] ) && is_array( $ticket['ticket_type'] ) ) {
                $ticket_type_name = isset( $ticket['ticket_type']['name'] ) ? $ticket['ticket_type']['name'] : '';
            }

            $attendees[] = array(
                'first_name'       => isset( $ticket['first_name'] ) ? $ticket['first_name'] : '',
                'last_name'        => isset( $ticket['last_name'] ) ? $ticket['last_name'] : '',
                'email'            => isset( $ticket['email'] ) ? $ticket['email'] : '',
                'ticket_type'      => $ticket_type_name,
                'status'           => $status,
                'custom_questions' => $custom_questions,
                'created_at'       => isset( $ticket['created_at'] ) ? $ticket['created_at'] : '',
            );
        }

        $roster = array(
            'event_name'     => get_the_title( $post_id ),
            'event_date'     => get_post_meta( $post_id, '_tt_start_formatted', true ),
            'venue'          => get_post_meta( $post_id, '_tt_venue_name', true ),
            'total'          => count( $tickets ),
            'valid'          => $valid,
            'voided'         => $voided,
            'refunded'       => $refunded,
            'all_questions'  => $all_questions,
            'attendees'      => $attendees,
            'tt_event_id'    => $tt_event_id,
        );

        // Cache it.
        update_post_meta( $post_id, '_tt_roster_data', wp_json_encode( $roster ) );
        update_post_meta( $post_id, '_tt_roster_fetched_at', time() );

        return $roster;
    }

    // -------------------------------------------------------------------------
    // Shortcode: [tt_roster]
    // -------------------------------------------------------------------------

    /**
     * Render the roster shortcode.
     */
    public static function shortcode_roster( $atts ): string {
        // Sanitize token from query string.
        $token = isset( $_GET['token'] ) ? preg_replace( '/[^a-f0-9]/', '', $_GET['token'] ) : '';

        if ( empty( $token ) || strlen( $token ) !== 64 ) {
            return self::render_error( __( 'Invalid or expired link.', 'tailor-made' ) );
        }

        $post_id = self::find_post_by_token( $token );
        if ( ! $post_id ) {
            return self::render_error( __( 'Invalid or expired link.', 'tailor-made' ) );
        }

        // Set flag for noindex.
        self::$rendering_roster = true;

        // Enqueue styles.
        wp_enqueue_style(
            'tailor-made-roster',
            TAILOR_MADE_URL . 'assets/css/roster.css',
            array(),
            TAILOR_MADE_VERSION
        );

        // Fetch roster data.
        $roster = self::get_roster_data( $post_id );
        if ( is_wp_error( $roster ) ) {
            return self::render_error( __( 'Unable to load roster data. Please try again later.', 'tailor-made' ) );
        }

        return self::render_roster( $roster );
    }

    /**
     * Render an error message.
     */
    private static function render_error( string $message ): string {
        return '<div class="tt-roster tt-roster--error"><p>' . esc_html( $message ) . '</p></div>';
    }

    /**
     * Render the roster HTML.
     */
    private static function render_roster( array $roster ): string {
        $event_name = esc_html( $roster['event_name'] );
        $event_date = esc_html( $roster['event_date'] );
        $venue      = esc_html( $roster['venue'] );
        $total      = (int) $roster['total'];
        $valid      = (int) $roster['valid'];
        $voided     = (int) $roster['voided'];
        $refunded   = (int) $roster['refunded'];
        $attendees  = $roster['attendees'];
        $questions  = isset( $roster['all_questions'] ) ? $roster['all_questions'] : array();
        $tt_id      = isset( $roster['tt_event_id'] ) ? $roster['tt_event_id'] : '';

        // Build TT dashboard URL.
        $tt_dashboard_url = $tt_id ? 'https://www.tickettailor.com/organiser#/events/' . urlencode( $tt_id ) : 'https://www.tickettailor.com/organiser';

        ob_start();
        ?>
        <div class="tt-roster">
            <div class="tt-roster__header">
                <h2 class="tt-roster__title"><?php echo $event_name; ?></h2>
                <?php if ( $event_date ) : ?>
                    <p class="tt-roster__date"><?php echo $event_date; ?></p>
                <?php endif; ?>
                <?php if ( $venue ) : ?>
                    <p class="tt-roster__venue"><?php echo $venue; ?></p>
                <?php endif; ?>
            </div>

            <div class="tt-roster__stats">
                <div class="tt-roster__stat">
                    <span class="tt-roster__stat-value"><?php echo $total; ?></span>
                    <span class="tt-roster__stat-label"><?php esc_html_e( 'Total', 'tailor-made' ); ?></span>
                </div>
                <div class="tt-roster__stat tt-roster__stat--valid">
                    <span class="tt-roster__stat-value"><?php echo $valid; ?></span>
                    <span class="tt-roster__stat-label"><?php esc_html_e( 'Valid', 'tailor-made' ); ?></span>
                </div>
                <?php if ( $voided > 0 ) : ?>
                <div class="tt-roster__stat tt-roster__stat--voided">
                    <span class="tt-roster__stat-value"><?php echo $voided; ?></span>
                    <span class="tt-roster__stat-label"><?php esc_html_e( 'Voided', 'tailor-made' ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $refunded > 0 ) : ?>
                <div class="tt-roster__stat tt-roster__stat--refunded">
                    <span class="tt-roster__stat-value"><?php echo $refunded; ?></span>
                    <span class="tt-roster__stat-label"><?php esc_html_e( 'Refunded', 'tailor-made' ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <input type="text" class="tt-roster__search" placeholder="<?php esc_attr_e( 'Search attendees...', 'tailor-made' ); ?>" />

            <div class="tt-roster__table-wrap">
                <table class="tt-roster__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Ticket', 'tailor-made' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'tailor-made' ); ?></th>
                            <?php foreach ( $questions as $q ) : ?>
                                <th><?php echo esc_html( $q ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $attendees ) ) : ?>
                            <tr><td colspan="<?php echo 4 + count( $questions ); ?>"><?php esc_html_e( 'No attendees yet.', 'tailor-made' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $attendees as $a ) :
                                $name        = esc_html( trim( $a['first_name'] . ' ' . $a['last_name'] ) );
                                $email       = esc_html( $a['email'] );
                                $ticket_type = esc_html( $a['ticket_type'] );
                                $status      = $a['status'];
                                $badge_class = 'tt-roster__badge--' . esc_attr( $status );
                                $status_label = ucfirst( $status === 'void' ? 'voided' : $status );
                            ?>
                            <tr>
                                <td><?php echo $name; ?></td>
                                <td><?php echo $email; ?></td>
                                <td><?php echo $ticket_type; ?></td>
                                <td><span class="<?php echo $badge_class; ?>"><?php echo esc_html( $status_label ); ?></span></td>
                                <?php foreach ( $questions as $q ) :
                                    $answer = isset( $a['custom_questions'][ $q ] ) ? $a['custom_questions'][ $q ] : '';
                                ?>
                                    <td><?php echo esc_html( $answer ); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="tt-roster__footer">
                <?php
                printf(
                    /* translators: %s: relative time */
                    esc_html__( 'Last updated: %s', 'tailor-made' ),
                    esc_html( human_time_diff( (int) get_post_meta( self::find_post_by_token( preg_replace( '/[^a-f0-9]/', '', $_GET['token'] ) ), '_tt_roster_fetched_at', true ), time() ) . ' ago' )
                );
                ?>
                &bull;
                <a href="<?php echo esc_url( $tt_dashboard_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Manage in Ticket Tailor', 'tailor-made' ); ?>
                </a>
            </p>
        </div>

        <script>
        (function() {
            var search = document.querySelector('.tt-roster__search');
            if (!search) return;
            var table = document.querySelector('.tt-roster__table');
            if (!table) return;
            var rows = table.querySelectorAll('tbody tr');

            search.addEventListener('input', function() {
                var term = this.value.toLowerCase();
                for (var i = 0; i < rows.length; i++) {
                    var text = rows[i].textContent.toLowerCase();
                    rows[i].style.display = text.indexOf(term) !== -1 ? '' : 'none';
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Shortcode: [tt_roster_box_office]
    // -------------------------------------------------------------------------

    /**
     * Render a grouped roster for all events in a box office.
     * Accessed via a box_office_token query parameter.
     */
    public static function shortcode_roster_box_office( $atts ): string {
        $token = isset( $_GET['box_office_token'] ) ? preg_replace( '/[^a-f0-9]/', '', $_GET['box_office_token'] ) : '';

        if ( empty( $token ) || strlen( $token ) < 32 ) {
            return self::render_error( __( 'Invalid or expired link.', 'tailor-made' ) );
        }

        // Find box office by roster_token.
        global $wpdb;
        $bo = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . Tailor_Made_Box_Office_Manager::table_name() . " WHERE roster_token = %s AND status = 'active'",
            $token
        ) );

        if ( ! $bo ) {
            return self::render_error( __( 'Invalid or expired link.', 'tailor-made' ) );
        }

        $bo->api_key = Tailor_Made_Box_Office_Manager::decrypt_api_key( $bo->api_key );

        self::$rendering_roster = true;

        wp_enqueue_style(
            'tailor-made-roster',
            TAILOR_MADE_URL . 'assets/css/roster.css',
            array(),
            TAILOR_MADE_VERSION
        );

        // Get all events for this box office.
        $events = get_posts( array(
            'post_type'      => 'tt_event',
            'numberposts'    => -1,
            'post_status'    => 'any',
            'meta_key'       => '_tt_start_unix',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'   => '_tt_box_office_id',
                    'value' => $bo->id,
                ),
            ),
        ) );

        if ( empty( $events ) ) {
            return self::render_error( __( 'No events found for this box office.', 'tailor-made' ) );
        }

        // Fetch roster data for each event.
        $all_rosters    = array();
        $total_attendees = 0;

        foreach ( $events as $event_post ) {
            $roster = self::get_roster_data( $event_post->ID );
            if ( ! is_wp_error( $roster ) ) {
                $all_rosters[] = $roster;
                $total_attendees += $roster['total'];
            }
        }

        return self::render_box_office_roster( $bo->name, $all_rosters, $total_attendees );
    }

    /**
     * Render grouped roster HTML for a box office (all events).
     */
    private static function render_box_office_roster( string $bo_name, array $rosters, int $total ): string {
        ob_start();
        ?>
        <div class="tt-roster">
            <div class="tt-roster__header">
                <h2 class="tt-roster__title"><?php echo esc_html( $bo_name ); ?> — All Events Roster</h2>
                <p>Total attendees across all events: <strong><?php echo esc_html( $total ); ?></strong></p>
            </div>

            <input type="text" class="tt-roster__search" placeholder="<?php esc_attr_e( 'Search all attendees...', 'tailor-made' ); ?>" />

            <?php foreach ( $rosters as $roster ) : ?>
                <div class="tt-roster__event-section" style="margin-top: 30px;">
                    <h3><?php echo esc_html( $roster['event_name'] ); ?></h3>
                    <p>
                        <?php echo esc_html( $roster['event_date'] ); ?>
                        <?php if ( $roster['venue'] ) : ?> — <?php echo esc_html( $roster['venue'] ); ?><?php endif; ?>
                        &bull; <?php echo esc_html( $roster['total'] ); ?> attendees
                    </p>

                    <div class="tt-roster__table-wrap">
                        <table class="tt-roster__table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Name', 'tailor-made' ); ?></th>
                                    <th><?php esc_html_e( 'Email', 'tailor-made' ); ?></th>
                                    <th><?php esc_html_e( 'Ticket', 'tailor-made' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'tailor-made' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $roster['attendees'] ) ) : ?>
                                    <tr><td colspan="4"><?php esc_html_e( 'No attendees yet.', 'tailor-made' ); ?></td></tr>
                                <?php else : ?>
                                    <?php foreach ( $roster['attendees'] as $a ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( trim( $a['first_name'] . ' ' . $a['last_name'] ) ); ?></td>
                                        <td><?php echo esc_html( $a['email'] ); ?></td>
                                        <td><?php echo esc_html( $a['ticket_type'] ); ?></td>
                                        <td><?php echo esc_html( ucfirst( $a['status'] === 'void' ? 'voided' : $a['status'] ) ); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function() {
            var search = document.querySelector('.tt-roster__search');
            if (!search) return;
            var tables = document.querySelectorAll('.tt-roster__table');
            search.addEventListener('input', function() {
                var term = this.value.toLowerCase();
                tables.forEach(function(table) {
                    var rows = table.querySelectorAll('tbody tr');
                    for (var i = 0; i < rows.length; i++) {
                        rows[i].style.display = rows[i].textContent.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
                    }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Add noindex meta when rendering a roster page.
     */
    public static function maybe_noindex(): void {
        if ( self::$rendering_roster ) {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        }

        // Also check if we're on the roster page with a token param.
        $page_id = get_option( 'tailor_made_roster_page_id' );
        if ( $page_id && is_page( (int) $page_id ) && isset( $_GET['token'] ) ) {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        }
    }

    // -------------------------------------------------------------------------
    // Page Auto-Creation
    // -------------------------------------------------------------------------

    /**
     * Create the roster page if it doesn't already exist.
     */
    public static function maybe_create_roster_page(): void {
        $existing_id = get_option( 'tailor_made_roster_page_id' );

        // Check if the page still exists.
        if ( $existing_id && get_post_status( $existing_id ) !== false ) {
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => __( 'Event Roster', 'tailor-made' ),
            'post_content' => '[tt_roster]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'roster',
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'tailor_made_roster_page_id', $page_id );
        }
    }
}
