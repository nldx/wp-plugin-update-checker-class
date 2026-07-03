<?php
/**
 * Class for checking if an update is available for a self hosted wordpress plugin.
 *
 * @package   wp-plugin-update-checker-class
 * @version   2.1.4
 * @author    Robert Schneider, Raffael Knoll
 * @copyright Copyright (c) 2025, Robert Schneider
 * @license   GPL-2.0+
 */

namespace NLDX;

if (!class_exists('NLDX\\UpdateChecker'))
{
	class UpdateChecker
	{
		// Plugin folder slug used in WP plugin update payload keys.
		public string $plugin_slug;
		// Installed version of the local plugin.
		public string $version;
		// Remote endpoint that serves plugin update metadata JSON.
		public string $json_url;
		// Transient key used to cache the HTTP response from json_url.
		private string $cache_key;
		// Runtime flag to allow disabling caching through a filter.
		private bool $cache_allowed;

		public function __construct($slug, $version, $json_url)
		{
			// Persist constructor values for all later comparisons and requests.
			$this->plugin_slug = $slug;
			$this->version = $version;
			$this->json_url = $json_url;

			// Create a stable and WP-safe transient key.
			$this->cache_key = 'nldx_update_' . sanitize_key($this->plugin_slug);

			// External projects can disable cache for development/debugging.
			$this->cache_allowed = (bool)apply_filters('nldx_update_checker_cache_allowed', true);

			// Hook into WP update lifecycle and plugin modal API.
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
			// Read cached HTTP response first (false means expired/missing).
			$remote = get_transient($this->cache_key);

			if ($remote === false || !$this->cache_allowed) {
				// Cache miss (or cache disabled): request fresh JSON from remote endpoint.
				$remote = wp_remote_get(
					$this->json_url,
					array(
						'timeout' => 10,
						'headers' => array(
							'Accept' => 'application/json'
						)
					)
				);

				// Verbose transport debug only when WP debug logging is enabled.
				if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
					error_log('NLDXUpdateCheckerX (' . $this->plugin_slug . ') - wp_remote_get return: ' . print_r($remote, true));
				}

				// Normalize all transport/HTTP/body errors into a single message.
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
					// No transport error.
					$message = '';
				}

				if ($message) {
					// Debug log keeps production output quiet.
					if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
						error_log(sprintf(
							'NLDXUpdateCheckerX (%s) – Update failed: %s',
							$this->plugin_slug,
							$message
						));
					}

					// Show one admin-visible notice so failures are not silent.
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

				// Cache successful HTTP response object for one day.
				set_transient($this->cache_key, $remote, DAY_IN_SECONDS);
			}

			// Parse response body JSON into an object expected by mapping code.
			$body = wp_remote_retrieve_body($remote);
			$remote = json_decode($body);

			// Invalid JSON payload cannot be used for update checks.
			if (null === $remote) {
				if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
					error_log(sprintf(
						'NLDXUpdateCheckerX (%s) – JSON decode failed. Raw response: %s',
						$this->plugin_slug,
						$body
					));
				}
				return false;
			}

			// Required minimum contract for update logic.
			if (!isset($remote->name, $remote->slug, $remote->version, $remote->download_url)) {
				if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
					error_log(sprintf(
						'NLDXUpdateCheckerX (%s) – Invalid structure: %s',
						$this->plugin_slug,
						print_r($remote, true)
					));
				}
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
			// Only handle details-modal requests.
			if ('plugin_information' !== $action) {
				return $response;
			}

			// Guard against malformed API args payloads.
			if (!is_object($args) || empty($args->slug)) {
				return $response;
			}

			// Ignore requests for other plugins.
			if ($this->plugin_slug !== $args->slug) {
				return $response;
			}

			// Pull remote metadata; keep core response unchanged on failure.
			$remote = $this->request();
			if (!$remote) {
				return $response;
			}

			// Build response object in format expected by WordPress plugin modal.
			$response = new \stdClass();
			$response->name = $remote->name;
			$response->slug = $remote->slug;
			$response->version = $remote->version;

			// Optional fields are mapped with safe defaults.
			$response->tested = $remote->tested ?? '';
			$response->requires = $remote->requires ?? '';
			$response->author = $remote->author ?? '';
			$response->author_profile = $remote->author_profile ?? '';
			$response->download_link = $remote->download_url;
			$response->trunk = $remote->download_url;
			$response->requires_php = $remote->requires_php ?? '';
			$response->last_updated = $remote->last_updated ?? '';

			// sections is optional in remote JSON; normalize before property access.
			$sections = (isset($remote->sections) && is_object($remote->sections)) ? $remote->sections : new \stdClass();
			$response->sections = array(
				'description' => $sections->description ?? '',
				'installation' => $sections->installation ?? '',
				'changelog' => $sections->changelog ?? ''
			);

			// banners is optional in remote JSON; include only when provided.
			if (isset($remote->banners) && is_object($remote->banners)) {
				$response->banners = array(
					'low' => $remote->banners->low ?? '',
					'high' => $remote->banners->high ?? ''
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
			// Restrict update checks to admin users with plugin update capability.
			if (!is_admin() || !current_user_can('update_plugins')) {
				return $transient;
			}

			// Nothing to evaluate when core has not populated checked versions yet.
			if (empty($transient->checked)) {
				return $transient;
			}

			// Fetch validated remote metadata.
			$remote = $this->request();

			// Offer update only when local/remote and env constraints are satisfied.
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

				// Inject this plugin's update into the WP update response list.
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
			// Clear cache only when this specific plugin was updated.
			if (
				$this->cache_allowed
				&& $options['action'] === 'update'
				&& $options['type'] === 'plugin'
				&& isset($options['plugins'])
				&& in_array("{$this->plugin_slug}/{$this->plugin_slug}.php", (array)$options['plugins'], true)
			) {
				delete_transient($this->cache_key);
			}
		}
	}
}
