<?php
/**
 * Class for checking if an update is available for a self hosted wordpress plugin.
 *
 * @package   wp-plugin-update-checker-class
 * @version   2.1.1
 * @author    Robert Schneider, Raffael Knoll
 * @copyright Copyright (c) 2025, Robert Schneide
 * @license   GPL-2.0+
 */

namespace NLDX;

class UpdateChecker
{

    public string $plugin_slug;
    public string $version;
    public string $json_url;
    private string $cache_key;
    private bool $cache_allowed;

    public function __construct($slug, $version, $json_url)
    {
        $this->plugin_slug = $slug;
        $this->version = $version;
        $this->json_url = $json_url;
        $this->cache_key = 'nldx_update_' . sanitize_key($this->plugin_slug);
        $this->cache_allowed = (bool)apply_filters('nldx_update_checker_cache_allowed', true); // can be set by filter add_filter( 'nldx_update_checker_cache_allowed', '__return_false' );
        // Adding filter and hooks
        add_filter('plugins_api', array($this, 'get_plugin_info'), 20, 3);
        add_filter('site_transient_update_plugins', array($this, 'update'), 10, 1);
        add_action('upgrader_process_complete', array($this, 'purge_transient'), 10, 2);
    }

    /**
     * Request for fetching info json from remote.
     *
     * @return object|false The remote response or false on failure.
     */
    public function request()
    {
        // If cache is allowed, we receive false after the transient has expired ( https://developer.wordpress.org/reference/functions/get_transient/ )
        $remote = get_transient($this->cache_key);

        if ($remote === false || !$this->cache_allowed) {

            $remote = wp_remote_get(
                $this->json_url,
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'Accept' => 'application/json'
                    )
                )
            );

            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('NLDXUpdateCheckerX (' . $this->plugin_slug . ') - wp_remote_get return: ' . print_r($remote, true));
            }

            // Handle error cases and determine the exact error message
            if (is_wp_error($remote)) {
                $message = $remote->get_error_message();
            } elseif (200 !== wp_remote_retrieve_response_code($remote)) {
                $status = wp_remote_retrieve_response_code($remote);
                $message = sprintf(
                    // translators: 1: HTTP status code
                    __('Unexpected HTTP status %d', 'nldx'),
                    $status
                );
            } elseif (empty(wp_remote_retrieve_body($remote))) {
                $message = __('Empty response body', 'nldx');
            } else {
                // Everything is OK – no error
                $message = '';
            }

            if ($message) {
                // Log to debug.log if WP_DEBUG is enabled
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log(sprintf(
                        'NLDXUpdateCheckerX (%s) – Update failed: %s',
                        $this->plugin_slug,
                        $message
                    ));
                }

                // Display an admin notice
                add_action('admin_notices', function () use ($message) {
                    printf(
                        '<div class="error"><p>%s: %s</p></div>',
                        sprintf(
                            esc_html__('NLDXUpdateCheckerX (%s) – Update failed', 'nldx'),
                            esc_html($this->plugin_slug)
                        ),
                        esc_html($message)
                    );
                });

                return false;
            }
            // Save the remote response in a wp transient.
            set_transient($this->cache_key, $remote, DAY_IN_SECONDS); // Cache for a day
        }

        // Extract the raw response body
        $body = wp_remote_retrieve_body($remote);
        //Decode into an object
        $remote = json_decode($body);
        // Handle decode errors
        if (null === $remote) {
            // Only log when WP_DEBUG_LOG is enabled
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    'NLDXUpdateCheckerX (%s) – JSON decode failed. Raw response: %s',
                    $this->plugin_slug,
                    $body
                ));
            }
            return false;
        }

        // Validate the remote data structure
        if (!isset($remote->name, $remote->slug, $remote->version, $remote->download_url)) {
            error_log(sprintf(
                'NLDXUpdateCheckerX (%s) – Invalid structure: %s',
                $this->plugin_slug,
                print_r($remote, true)
            ));
            return false;
        }

        return $remote;
    }

    /**
     * Get plugin info data.
     *
     * @param object $response Response object.
     * @param string $action The type of information being requested from the Plugin Install API.
     * @param object $args Plugin API arguments.
     * @return object The plugin info.
     */
    public function get_plugin_info($response, $action, $args)
    {
        // print_r( $action );
        // print_r( $args );

        // Do nothing if you're not getting plugin information right now
        if ('plugin_information' !== $action) {
            return $response;
        }

        // Do nothing if it is not our plugin
        if ($this->plugin_slug !== $args->slug) {
            return $response;
        }

        // Get updates
        $remote = $this->request();

        if (!$remote) {
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

        if (!empty($remote->banners)) {
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
    public function update($transient)
    {
        // Admin-Only
        if (!is_admin() || !current_user_can('update_plugins')) {
            return $transient;
        }

        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->request();

        // Perform update if all criterias are met.
        if (
            $remote
            && version_compare($this->version, $remote->version, '<')
            && version_compare($remote->requires, get_bloginfo('version'), '<=')
            && version_compare($remote->requires_php, PHP_VERSION, '<=')
        ) {
            $response = new \stdClass();
            $response->slug = $this->plugin_slug;
            $response->plugin = "{$this->plugin_slug}/{$this->plugin_slug}.php";
            $response->new_version = $remote->version;
            $response->tested = $remote->tested;
            $response->package = $remote->download_url;
            // Adding plugin update to update process
            $transient->response[$response->plugin] = $response;
        }

        return $transient;
    }

    /**
     * Purge the transient cache when an update is complete.
     *
     * @param object $upgrader The upgrader object.
     * @param array $options The options array.
     */
    public function purge_transient($upgrader, $options)
    {
        if (
            $this->cache_allowed
            && $options['action'] === 'update'
            && $options['type'] === 'plugin'
        ) {
            // Just clean the cache (transient) when a new plugin version is installed.
            delete_transient($this->cache_key);
        }
    }
}

