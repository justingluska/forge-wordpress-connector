<?php
/**
 * Plugin Name: Forge Connector
 * Plugin URI: https://forge.gluska.co
 * Description: Connect your WordPress site to Forge by GLUSKA for seamless content publishing and management.
 * Version: 1.0.0
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

define('FORGE_CONNECTOR_VERSION', '1.0.0');
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // Initialize CTA handler (shortcodes, tracking, site-wide CTAs)
        new Forge_CTA();

        // Initialize CTA admin page
        if (is_admin()) {
            new Forge_CTA_Admin();
        }
    }

    public function register_rest_routes() {
        $api = new Forge_API();
        $api->register_routes();
    }

    public function add_admin_menu() {
        add_options_page(
            __('Forge Connector', 'forge-connector'),
            __('Forge Connector', 'forge-connector'),
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
        if ('settings_page_forge-connector' !== $hook) {
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

        // Flush rewrite rules for REST API
        flush_rewrite_rules();
    }

    public static function deactivate() {
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
