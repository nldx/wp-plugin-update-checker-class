<?php
/**
 * Class for checking if an update is available for a self hosted wordpress plugin.
 *
 * @package   wp-plugin-update-checker-class
 * @version   2.1.0
 * @author    Robert Schneider, Raffael Knoll
 * @copyright Copyright (c) 2025, Robert Schneide
 * @license   GPL-2.0+
 */

namespace NLDX;

class UpdateChecker{

    public string $plugin_slug;
    public string $version;
    public string $json_url;
    private string $cache_key;
    private bool $cache_allowed;
    private bool $dev;

    public function __construct($slug, $version, $json_url) {
        $this->plugin_slug = $slug;
        $this->version = $version;
        $this->json_url = $json_url;
        $this->cache_key = str_replace('-', '_', $slug);
        $this->cache_allowed = true; // Set to false only during development
        $this->dev = false; // Set to true only during development
        // Adding filter and hooks
        add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 20, 3 );
        add_filter( 'site_transient_update_plugins', array( $this, 'update' ) );
        add_action( 'upgrader_process_complete', array( $this, 'purge_transient' ), 10, 2 );
    }

    /**
     * Request for fetching info json from remote.
     *
     * @return object|false The remote response or false on failure.
     */
    public function request(){
        // If cache is allowed, we receive false after the transient has expired ( https://developer.wordpress.org/reference/functions/get_transient/ )
        $remote = get_transient( $this->cache_key );

        if ( $remote === false || !$this->cache_allowed ) {

            $remote = wp_remote_get(
                $this->json_url,
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'Accept' => 'application/json'
                    )
                )
            );

            if ( $this->dev ) {
                error_log('NLDXUpdateCheckerX - wp_remote_get return: ' . print_r( $remote, true ) );
            }

            if (
                is_wp_error( $remote )
                || 200 !== wp_remote_retrieve_response_code( $remote )
                || empty( wp_remote_retrieve_body( $remote ) )
            ) {
                error_log( 'NLDXUpdateCheckerX - Error in remote request: ' . print_r( $remote, true ) );
                // Admin notive
                add_action('admin_notices', function() use ($remote) {
                    echo '<div class="error"><p>';
                    echo esc_html__('NLDXUpdateCheckerX: Update failed - ', 'nldx') . esc_html($remote->get_error_message());
                    echo '</p></div>';
                });
                return false;
            }
            // Save the remote response in a wp transient.
            set_transient( $this->cache_key, $remote, DAY_IN_SECONDS ); // Cache for a day
        }

        $remote = json_decode( wp_remote_retrieve_body( $remote ) );

        // Validate the remote data structure
        if (
            ! isset( $remote->name ) ||
            ! isset( $remote->slug ) ||
            ! isset( $remote->version ) ||
            ! isset( $remote->tested ) ||
            ! isset( $remote->requires ) ||
            ! isset( $remote->author ) ||
            ! isset( $remote->download_url )
        ) {
            error_log( 'NLDXUpdateCheckerX - Invalid remote data structure: ' . print_r( $remote, true ) );
            return false;
        }

        return $remote;
    }

    /**
     * Get plugin info data.
     *
     * @param object $response  Response object.
     * @param string $action    The type of information being requested from the Plugin Install API.
     * @param object $args      Plugin API arguments.
     * @return object The plugin info.
     */
    public function get_plugin_info( $response, $action, $args ) {
        // print_r( $action );
        // print_r( $args );

        // Do nothing if you're not getting plugin information right now
        if ( 'plugin_information' !== $action ) {
            return $response;
        }

        // Do nothing if it is not our plugin
        if ( $this->plugin_slug !== $args->slug ) {
            return $response;
        }

        // Get updates
        $remote = $this->request();

        if( !$remote ) {
            return $response;
        }

        $response = new stdClass();

        $response->name = $remote->name;
        $response->slug = $remote->slug;
        $response->version = $remote->version;
        $response->tested = $remote->tested;
        $response->requires = $remote->requires;
        $response->author = $remote->author;
        $response->author_profile = $remote->author_profile;
        $response->download_link = $remote->download_url;
        $response->trunk = $remote->download_url;
        $response->requires_php = $remote->requires_php;
        $response->last_updated = $remote->last_updated;

        $response->sections = array(
            'description' => $remote->sections->description,
            'installation' => $remote->sections->installation,
            'changelog' => $remote->sections->changelog
        );

        if( ! empty( $remote->banners ) ) {
            $response->banners = array(
                'low' => $remote->banners->low,
                'high' => $remote->banners->high
            );
        }

        return $response;
    }

    /**
     * Perform plugin update (for filter 'site_transient_update_plugins').
     *
     * @param object $transient The transient object containing update information.
     * @return object The transient object with potential update information added.
     */
    public function update( $transient ) {
        if ( empty($transient->checked ) ) {
            return $transient;
        }

        $remote = $this->request();

        // Perform update if all criterias are met.
        if (
            $remote
            && version_compare( $this->version, $remote->version, '<' )
            && version_compare( $remote->requires, get_bloginfo( 'version' ), '<=' )
            && version_compare( $remote->requires_php, PHP_VERSION, '<=' )
        ) {
            $response = new \stdClass();
            $response->slug = $this->plugin_slug;
            $response->plugin = "{$this->plugin_slug}/{$this->plugin_slug}.php";
            $response->new_version = $remote->version;
            $response->tested = $remote->tested;
            $response->package = $remote->download_url;
            // Adding plugin update to update process
            $transient->response[ $response->plugin ] = $response;
        }

        return $transient;
    }

    /**
     * Purge the transient cache when an update is complete.
     *
     * @param object $upgrader The upgrader object.
     * @param array  $options  The options array.
     */
    public function purge_transient( $upgrader, $options ){
        if (
            $this->cache_allowed
            && $options['action'] === 'update'
            && $options['type'] === 'plugin'
        ) {
            // Just clean the cache (transient) when a new plugin version is installed.
            delete_transient( $this->cache_key );
        }
    }
}

