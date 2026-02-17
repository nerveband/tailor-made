<?php
/**
 * GitHub Release Updater.
 *
 * Enables WordPress to check for and install plugin updates from
 * a public GitHub repository's releases page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tailor_Made_GitHub_Updater {

    /** @var string */
    private $slug;

    /** @var string */
    private $plugin_file;

    /** @var string */
    private $version;

    /** @var string */
    private $github_repo = 'wavedepth/tailor-made';

    /** @var string */
    private $cache_key = 'tailor_made_github_release';

    /** @var int Cache for 6 hours */
    private $cache_ttl = 21600;

    public function __construct() {
        $this->slug        = 'tailor-made';
        $this->plugin_file = 'tailor-made/tailor-made.php';
        $this->version     = TAILOR_MADE_VERSION;
    }

    public static function init() {
        $instance = new self();

        add_filter( 'pre_set_site_transient_update_plugins', array( $instance, 'check_update' ) );
        add_filter( 'plugins_api', array( $instance, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( $instance, 'post_install' ), 10, 3 );
    }

    /**
     * Check GitHub for a newer release.
     *
     * @param object $transient
     * @return object
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $download_url = $this->get_download_url( $release );

            if ( $download_url ) {
                $obj              = new stdClass();
                $obj->slug        = $this->slug;
                $obj->plugin      = $this->plugin_file;
                $obj->new_version = $remote_version;
                $obj->url         = 'https://github.com/' . $this->github_repo;
                $obj->package     = $download_url;
                $obj->icons       = array();
                $obj->banners     = array();
                $obj->tested      = '';
                $obj->requires    = '5.0';
                $obj->requires_php = '7.4';

                $transient->response[ $this->plugin_file ] = $obj;
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the WordPress updates screen.
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $info              = new stdClass();
        $info->name        = 'Tailor Made';
        $info->slug        = $this->slug;
        $info->version     = ltrim( $release['tag_name'], 'v' );
        $info->author      = '<a href="https://wavedepth.com">wavedepth</a>';
        $info->homepage    = 'https://github.com/' . $this->github_repo;
        $info->requires    = '5.0';
        $info->tested      = '';
        $info->requires_php = '7.4';
        $info->downloaded  = 0;
        $info->last_updated = isset( $release['published_at'] ) ? $release['published_at'] : '';
        $info->sections    = array(
            'description'  => 'Unofficial Ticket Tailor full API integration for WordPress and Bricks Builder.',
            'changelog'    => isset( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : '',
        );
        $info->download_link = $this->get_download_url( $release );

        return $info;
    }

    /**
     * After install, move the plugin to the correct directory name.
     *
     * @param bool  $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function post_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $result;
        }

        global $wp_filesystem;

        $proper_dest = WP_PLUGIN_DIR . '/' . $this->slug;
        $wp_filesystem->move( $result['destination'], $proper_dest );
        $result['destination'] = $proper_dest;

        activate_plugin( $this->plugin_file );

        return $result;
    }

    /**
     * Fetch latest release from GitHub API (cached).
     *
     * @return array|null
     */
    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'TailorMade-WP-Plugin/' . $this->version,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body ) || isset( $body['message'] ) ) {
            return null;
        }

        set_transient( $this->cache_key, $body, $this->cache_ttl );

        return $body;
    }

    /**
     * Get the zip download URL from a release.
     *
     * Prefers an attached .zip asset; falls back to the source zipball.
     *
     * @param array $release
     * @return string|null
     */
    private function get_download_url( $release ) {
        // Check for a .zip asset attached to the release
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( substr( $asset['name'], -4 ) === '.zip' ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fall back to GitHub's auto-generated source zip
        return isset( $release['zipball_url'] ) ? $release['zipball_url'] : null;
    }
}
