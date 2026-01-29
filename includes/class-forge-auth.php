<?php
/**
 * Forge Authentication Handler
 *
 * Handles HMAC signature validation for secure communication with Forge.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_Auth {

    const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    /**
     * Validate an incoming request from Forge
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function validate_request($request) {
        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['connection_key'])) {
            return new WP_Error(
                'forge_not_configured',
                __('Forge Connector is not configured. Please enter your connection key in Settings.', 'forge-connector'),
                array('status' => 401)
            );
        }

        // Get authentication headers
        $signature = $request->get_header('X-Forge-Signature');
        $timestamp = $request->get_header('X-Forge-Timestamp');
        $site_id = $request->get_header('X-Forge-Site-ID');

        if (empty($signature) || empty($timestamp)) {
            return new WP_Error(
                'forge_missing_auth',
                __('Missing authentication headers.', 'forge-connector'),
                array('status' => 401)
            );
        }

        // Validate timestamp to prevent replay attacks
        $request_time = intval($timestamp);
        $current_time = time();

        if (abs($current_time - $request_time) > self::TIMESTAMP_TOLERANCE) {
            return new WP_Error(
                'forge_expired_request',
                __('Request has expired. Please check your server time.', 'forge-connector'),
                array('status' => 401)
            );
        }

        // Build the string to sign
        $method = $request->get_method();
        $path = $request->get_route();
        $body = $request->get_body();

        $string_to_sign = $method . "\n" . $path . "\n" . $timestamp . "\n" . $body;

        // Calculate expected signature
        $expected_signature = hash_hmac('sha256', $string_to_sign, $settings['connection_key']);

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error(
                'forge_invalid_signature',
                __('Invalid request signature.', 'forge-connector'),
                array('status' => 401)
            );
        }

        // Handle site ID: verify match if stored, or store if not yet set
        if (!empty($site_id)) {
            if (!empty($settings['forge_site_id'])) {
                // Verify stored site ID matches
                if ($settings['forge_site_id'] !== $site_id) {
                    return new WP_Error(
                        'forge_site_mismatch',
                        __('Site ID mismatch.', 'forge-connector'),
                        array('status' => 401)
                    );
                }
            } else {
                // Site ID not stored yet - store it now (first authenticated request)
                $settings['forge_site_id'] = $site_id;
                update_option('forge_connector_settings', $settings);
            }
        }

        return true;
    }

    /**
     * Generate a signature for outgoing requests to Forge
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $body Request body
     * @return array Headers to include
     */
    public static function sign_request($method, $path, $body = '') {
        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['connection_key'])) {
            return array();
        }

        $timestamp = time();
        $string_to_sign = $method . "\n" . $path . "\n" . $timestamp . "\n" . $body;
        $signature = hash_hmac('sha256', $string_to_sign, $settings['connection_key']);

        return array(
            'X-Forge-Signature' => $signature,
            'X-Forge-Timestamp' => $timestamp,
            'X-Forge-Site-ID' => $settings['forge_site_id'] ?? '',
            'X-Forge-Plugin-Version' => FORGE_CONNECTOR_VERSION,
        );
    }

    /**
     * Check if the plugin is connected to Forge
     *
     * @return bool
     */
    public static function is_connected() {
        $settings = get_option('forge_connector_settings', array());
        return !empty($settings['connected']) && !empty($settings['connection_key']);
    }

    /**
     * Get the connection status
     *
     * @return array
     */
    public static function get_status() {
        $settings = get_option('forge_connector_settings', array());

        return array(
            'connected' => !empty($settings['connected']),
            'connected_at' => $settings['connected_at'] ?? null,
            'forge_site_id' => $settings['forge_site_id'] ?? null,
            'has_key' => !empty($settings['connection_key']),
        );
    }

    /**
     * Store connection details after successful handshake
     *
     * @param string $connection_key
     * @param string $forge_site_id
     * @return bool
     */
    public static function store_connection($connection_key, $forge_site_id = null) {
        $settings = get_option('forge_connector_settings', array());

        $settings['connection_key'] = $connection_key;
        $settings['connected'] = true;
        $settings['connected_at'] = current_time('mysql');
        $settings['forge_site_id'] = $forge_site_id;

        return update_option('forge_connector_settings', $settings);
    }

    /**
     * Clear connection
     *
     * @return bool
     */
    public static function disconnect() {
        $settings = array(
            'connection_key' => '',
            'connected' => false,
            'connected_at' => null,
            'forge_site_id' => null,
        );

        return update_option('forge_connector_settings', $settings);
    }

    /**
     * Validate a connection key format
     *
     * @param string $key
     * @return bool
     */
    public static function validate_key_format($key) {
        // Key should be prefixed with 'fk_' and be at least 32 chars
        if (strpos($key, 'fk_') !== 0) {
            return false;
        }

        if (strlen($key) < 35) { // fk_ + 32 chars minimum
            return false;
        }

        // Should only contain alphanumeric characters after prefix
        $key_body = substr($key, 3);
        return ctype_alnum($key_body);
    }
}
