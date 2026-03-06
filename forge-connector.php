<?php
/**
 * Plugin Name: Forge Connector
 * Plugin URI: https://forge.gluska.co
 * Description: Connect your WordPress site to Forge by GLUSKA for seamless content publishing and management.
 * Version: 1.5.0
 * Author: Justin Gluska
 * Author URI: https://gluska.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: forge-connector
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FORGE_CONNECTOR_VERSION', '1.5.0');
define('FORGE_CONNECTOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FORGE_CONNECTOR_PLUGIN_URL', plugin_dir_url(__FILE__));

class Forge_Connector {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'includes/class-forge-auth.php';
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'includes/class-forge-api.php';
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'includes/class-forge-posts.php';
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'includes/class-forge-media.php';
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'includes/class-forge-cta.php';
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'admin/class-forge-settings.php';
        require_once FORGE_CONNECTOR_PLUGIN_DIR . 'admin/class-forge-cta-admin.php';
    }

    private function init_hooks() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('rest_api_init', array($this, 'register_seo_meta_fields'));
        add_action('init', array($this, 'register_post_meta_fields'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Daily heartbeat cron for version tracking
        add_action('forge_daily_heartbeat', array($this, 'send_heartbeat'));

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // Initialize CTA handler (shortcodes, tracking, site-wide CTAs)
        global $forge_cta;
        $forge_cta = new Forge_CTA();

        // Initialize CTA admin page
        if (is_admin()) {
            new Forge_CTA_Admin();
        }
    }

    public function register_rest_routes() {
        $api = new Forge_API();
        $api->init();
        $api->register_routes();
    }

    /**
     * Register SEO meta fields for REST API access
     * Exposes Yoast SEO, Rank Math, and Genesis SEO fields to WordPress REST API
     * Enables Forge to sync SEO metadata when publishing posts
     */
    public function register_seo_meta_fields() {
        $post_types = array('post', 'page');

        foreach ($post_types as $post_type) {
            // Yoast SEO fields
            register_rest_field($post_type, 'yoast_meta', array(
                'get_callback' => function($post) {
                    return array(
                        'title' => get_post_meta($post['id'], '_yoast_wpseo_title', true),
                        'description' => get_post_meta($post['id'], '_yoast_wpseo_metadesc', true),
                        'focus_keyword' => get_post_meta($post['id'], '_yoast_wpseo_focuskw', true),
                    );
                },
                'update_callback' => function($value, $post) {
                    if (isset($value['title'])) {
                        update_post_meta($post->ID, '_yoast_wpseo_title', sanitize_text_field($value['title']));
                    }
                    if (isset($value['description'])) {
                        update_post_meta($post->ID, '_yoast_wpseo_metadesc', sanitize_textarea_field($value['description']));
                    }
                    if (isset($value['focus_keyword'])) {
                        update_post_meta($post->ID, '_yoast_wpseo_focuskw', sanitize_text_field($value['focus_keyword']));
                    }
                    return true;
                },
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'title' => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                        'focus_keyword' => array('type' => 'string'),
                    ),
                ),
            ));
        }
    }

    /**
     * Register individual post meta fields for REST API
     * Alternative method that exposes fields directly in the 'meta' parameter
     */
    public function register_post_meta_fields() {
        // Register for all public post types (not just post/page)
        $post_types = get_post_types(array('public' => true));

        $seo_meta_keys = array(
            // Yoast SEO
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_focuskw',
            // Rank Math SEO
            'rank_math_title',
            'rank_math_description',
            'rank_math_focus_keyword',
            // Genesis SEO
            '_genesis_title',
            '_genesis_description',
        );

        foreach ($post_types as $post_type) {
            foreach ($seo_meta_keys as $meta_key) {
                register_post_meta($post_type, $meta_key, array(
                    'show_in_rest'      => true,
                    'single'            => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback'     => function() {
                        return current_user_can('edit_posts');
                    },
                ));
            }
        }
    }

    public function add_admin_menu() {
        // Add top-level Forge menu with Settings as the first page
        add_menu_page(
            __('Forge', 'forge-connector'),
            __('Forge', 'forge-connector'),
            'manage_options',
            'forge-connector',
            array($this, 'render_settings_page'),
            'dashicons-admin-site-alt3',
            30
        );

        // Add Settings submenu (same as parent page)
        add_submenu_page(
            'forge-connector',
            __('Settings', 'forge-connector'),
            __('Settings', 'forge-connector'),
            'manage_options',
            'forge-connector',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        $settings = new Forge_Settings();
        $settings->register();
    }

    public function render_settings_page() {
        $settings = new Forge_Settings();
        $settings->render();
    }

    public function enqueue_admin_scripts($hook) {
        // Load on Forge settings page (now a top-level menu, not under Settings)
        if ('toplevel_page_forge-connector' !== $hook && 'forge_page_forge-ctas' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'forge-connector-admin',
            FORGE_CONNECTOR_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            FORGE_CONNECTOR_VERSION
        );

        wp_enqueue_script(
            'forge-connector-admin',
            FORGE_CONNECTOR_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            FORGE_CONNECTOR_VERSION,
            true
        );

        wp_localize_script('forge-connector-admin', 'forgeConnector', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('forge_connector_nonce'),
            'restUrl' => rest_url('forge/v1/'),
            'strings' => array(
                'connecting' => __('Connecting...', 'forge-connector'),
                'connected' => __('Connected', 'forge-connector'),
                'disconnected' => __('Disconnected', 'forge-connector'),
                'error' => __('Connection failed', 'forge-connector'),
                'testSuccess' => __('Connection successful!', 'forge-connector'),
                'testFailed' => __('Connection test failed', 'forge-connector'),
            )
        ));
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=forge-connector">' . __('Settings', 'forge-connector') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Send a heartbeat to Forge API with plugin version and environment info.
     * Called on activation, deactivation, and daily via WP cron.
     *
     * @param bool $active Whether the plugin is active (false on deactivation)
     */
    public static function send_heartbeat($active = true) {
        $settings = get_option('forge_connector_settings', array());

        // Only send if connected (has connection_key and site_id)
        if (empty($settings['connection_key']) || empty($settings['forge_site_id'])) {
            return;
        }

        global $wp_version;

        $path = '/api/webhooks/wordpress/heartbeat';
        $body = wp_json_encode(array(
            'plugin_version' => FORGE_CONNECTOR_VERSION,
            'wp_version' => $wp_version,
            'php_version' => phpversion(),
            'active' => $active,
            'site_url' => get_site_url(),
        ));

        $headers = Forge_Auth::sign_request('POST', $path, $body);
        if (empty($headers)) {
            return;
        }

        $headers['Content-Type'] = 'application/json';

        wp_remote_post('https://api.gluska.co' . $path, array(
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'blocking' => false, // Fire-and-forget, don't block page load
        ));
    }

    public static function activate() {
        // Set default options
        if (!get_option('forge_connector_settings')) {
            add_option('forge_connector_settings', array(
                'connection_key' => '',
                'connected' => false,
                'connected_at' => null,
                'forge_site_id' => null,
            ));
        }

        // Schedule daily heartbeat for version tracking
        if (!wp_next_scheduled('forge_daily_heartbeat')) {
            wp_schedule_event(time(), 'daily', 'forge_daily_heartbeat');
        }

        // Flush rewrite rules for REST API
        flush_rewrite_rules();

        // Send activation heartbeat
        self::send_heartbeat(true);
    }

    public static function deactivate() {
        // Send deactivation heartbeat before clearing cron
        self::send_heartbeat(false);

        wp_clear_scheduled_hook('forge_daily_heartbeat');
        flush_rewrite_rules();
    }

    public static function uninstall() {
        delete_option('forge_connector_settings');
    }
}

// Activation/deactivation hooks
register_activation_hook(__FILE__, array('Forge_Connector', 'activate'));
register_deactivation_hook(__FILE__, array('Forge_Connector', 'deactivate'));
register_uninstall_hook(__FILE__, array('Forge_Connector', 'uninstall'));

// Initialize the plugin
function forge_connector_init() {
    return Forge_Connector::get_instance();
}
add_action('plugins_loaded', 'forge_connector_init');
