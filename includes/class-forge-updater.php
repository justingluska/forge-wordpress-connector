<?php
/**
 * Forge Plugin Auto-Updater
 *
 * Checks the Forge API for new plugin versions and integrates
 * with WordPress's built-in plugin update system.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_Updater {

    private $plugin_slug;
    private $plugin_basename;
    private $api_url = 'https://api.gluska.co/api/plugin/update-check';
    private $cache_key = 'forge_connector_update_check';
    private $cache_ttl = 43200; // 12 hours

    public function __construct() {
        $this->plugin_slug = 'forge-wordpress-connector';
        $this->plugin_basename = plugin_basename(FORGE_CONNECTOR_PLUGIN_DIR . 'forge-connector.php');

        add_filter('site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_action('upgrader_process_complete', array($this, 'clear_cache'), 10, 2);
    }

    /**
     * Check for plugin updates and inject into WordPress update transient
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_info();
        if (!$remote) {
            return $transient;
        }

        if (version_compare(FORGE_CONNECTOR_VERSION, $remote->version, '<')) {
            $res = new stdClass();
            $res->slug = $this->plugin_slug;
            $res->plugin = $this->plugin_basename;
            $res->new_version = $remote->version;
            $res->package = $remote->download_url;
            $res->tested = $remote->tested ?? '';
            $res->requires_php = $remote->requires_php ?? '7.4';
            $res->url = $remote->homepage ?? 'https://forge.gluska.co';
            $res->icons = array(
                '1x' => 'https://forge.gluska.co/forge-icon-128.png',
            );

            $transient->response[$this->plugin_basename] = $res;
        } else {
            // No update available — add to no_update to prevent false positives
            $res = new stdClass();
            $res->slug = $this->plugin_slug;
            $res->plugin = $this->plugin_basename;
            $res->new_version = FORGE_CONNECTOR_VERSION;
            $res->url = 'https://forge.gluska.co';

            $transient->no_update[$this->plugin_basename] = $res;
        }

        return $transient;
    }

    /**
     * Show plugin details in the "View details" popup
     */
    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action) {
            return $result;
        }

        if (!isset($args->slug) || $this->plugin_slug !== $args->slug) {
            return $result;
        }

        $remote = $this->get_remote_info();
        if (!$remote) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'Forge Connector';
        $info->slug = $this->plugin_slug;
        $info->version = $remote->version;
        $info->author = '<a href="https://gluska.co">Justin Gluska</a>';
        $info->homepage = $remote->homepage ?? 'https://forge.gluska.co';
        $info->requires = $remote->requires ?? '5.6';
        $info->tested = $remote->tested ?? '';
        $info->requires_php = $remote->requires_php ?? '7.4';
        $info->download_link = $remote->download_url;
        $info->last_updated = $remote->last_updated ?? '';
        $info->sections = array(
            'description' => $remote->description ?? 'Connect your WordPress site to Forge for seamless content publishing and management.',
            'changelog' => $remote->changelog ?? '',
        );
        $info->banners = array(
            'low' => 'https://forge.gluska.co/forge-banner-772x250.png',
            'high' => 'https://forge.gluska.co/forge-banner-1544x500.png',
        );

        return $info;
    }

    /**
     * Fetch update info from Forge API with caching
     */
    private function get_remote_info() {
        $cached = get_transient($this->cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $response = wp_remote_get($this->api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
                'X-Forge-Plugin-Version' => FORGE_CONNECTOR_VERSION,
            ),
        ));

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            // Cache the failure briefly to avoid hammering the API
            set_transient($this->cache_key, null, 3600);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (empty($body) || empty($body->version)) {
            return null;
        }

        set_transient($this->cache_key, $body, $this->cache_ttl);
        return $body;
    }

    /**
     * Clear cached update info after any plugin update completes
     */
    public function clear_cache($upgrader, $options) {
        if ('update' === $options['action'] && 'plugin' === $options['type']) {
            delete_transient($this->cache_key);
        }
    }
}
