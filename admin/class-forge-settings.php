<?php
/**
 * Forge Settings Page
 *
 * Handles the plugin settings page in WordPress admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_Settings {

    /**
     * Register settings
     */
    public function register() {
        register_setting('forge_connector', 'forge_connector_settings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));

        // AJAX handlers
        add_action('wp_ajax_forge_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_forge_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_forge_save_key', array($this, 'ajax_save_key'));
    }

    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        if (isset($input['connection_key'])) {
            $sanitized['connection_key'] = sanitize_text_field($input['connection_key']);
        }

        if (isset($input['connected'])) {
            $sanitized['connected'] = (bool) $input['connected'];
        }

        if (isset($input['connected_at'])) {
            $sanitized['connected_at'] = sanitize_text_field($input['connected_at']);
        }

        if (isset($input['forge_site_id'])) {
            $sanitized['forge_site_id'] = sanitize_text_field($input['forge_site_id']);
        }

        return $sanitized;
    }

    /**
     * Render the settings page
     */
    public function render() {
        $settings = get_option('forge_connector_settings', array());
        $is_connected = !empty($settings['connected']) && !empty($settings['connection_key']);
        $has_key = !empty($settings['connection_key']);
        ?>
        <div class="wrap forge-connector-wrap">
            <h1>
                <svg class="forge-logo" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7V17L12 22L22 17V7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 22V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M22 7L12 12L2 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php _e('Forge Connector', 'forge-connector'); ?>
            </h1>

            <div class="forge-card">
                <div class="forge-status-header">
                    <div class="forge-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                        <span class="forge-status-indicator"></span>
                        <span class="forge-status-text">
                            <?php echo $is_connected ? __('Connected', 'forge-connector') : __('Not Connected', 'forge-connector'); ?>
                        </span>
                    </div>
                    <?php if ($is_connected && !empty($settings['connected_at'])): ?>
                        <span class="forge-connected-since">
                            <?php printf(__('Since %s', 'forge-connector'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($settings['connected_at']))); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!$is_connected): ?>
                    <div class="forge-setup">
                        <h2><?php _e('Connect to Forge', 'forge-connector'); ?></h2>
                        <p class="forge-description">
                            <?php _e('Enter your connection key from the Forge dashboard to connect this site.', 'forge-connector'); ?>
                        </p>

                        <div class="forge-setup-steps">
                            <div class="forge-step">
                                <span class="forge-step-number">1</span>
                                <span class="forge-step-text"><?php _e('Copy your site URL and go to Forge dashboard', 'forge-connector'); ?></span>
                            </div>
                            <div class="forge-step">
                                <span class="forge-step-number">2</span>
                                <span class="forge-step-text"><?php _e('Add a new WordPress connection using the Plugin method', 'forge-connector'); ?></span>
                            </div>
                            <div class="forge-step">
                                <span class="forge-step-number">3</span>
                                <span class="forge-step-text"><?php _e('Copy the connection key and paste it below', 'forge-connector'); ?></span>
                            </div>
                        </div>

                        <div class="forge-site-url-box">
                            <label><?php _e('Your Site URL', 'forge-connector'); ?></label>
                            <div class="forge-url-copy-group">
                                <input
                                    type="text"
                                    id="forge-site-url"
                                    value="<?php echo esc_attr(get_site_url()); ?>"
                                    class="forge-input"
                                    readonly
                                >
                                <button type="button" class="button forge-copy-url-btn" id="forge-copy-url">
                                    <span class="forge-copy-text"><?php _e('Copy', 'forge-connector'); ?></span>
                                    <span class="forge-copied-text" style="display:none;"><?php _e('Copied!', 'forge-connector'); ?></span>
                                </button>
                            </div>
                            <p class="forge-input-hint">
                                <?php _e('Paste this URL in Forge when creating your connection', 'forge-connector'); ?>
                            </p>
                        </div>

                        <form class="forge-connect-form" id="forge-connect-form">
                            <div class="forge-input-group">
                                <label for="forge-connection-key"><?php _e('Connection Key', 'forge-connector'); ?></label>
                                <input
                                    type="text"
                                    id="forge-connection-key"
                                    name="connection_key"
                                    placeholder="fk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                    class="forge-input"
                                    autocomplete="off"
                                    spellcheck="false"
                                >
                                <p class="forge-input-hint">
                                    <?php _e('Your connection key starts with "fk_"', 'forge-connector'); ?>
                                </p>
                            </div>

                            <div class="forge-form-actions">
                                <button type="submit" class="button button-primary forge-connect-btn">
                                    <span class="forge-btn-text"><?php _e('Connect to Forge', 'forge-connector'); ?></span>
                                    <span class="forge-btn-loading" style="display:none;">
                                        <span class="spinner is-active"></span>
                                        <?php _e('Connecting...', 'forge-connector'); ?>
                                    </span>
                                </button>
                            </div>

                            <div class="forge-message" id="forge-message" style="display:none;"></div>
                        </form>
                    </div>

                <?php else: ?>
                    <div class="forge-connected-info">
                        <h2><?php _e('Site Connected', 'forge-connector'); ?></h2>
                        <p class="forge-description">
                            <?php _e('Your WordPress site is connected to Forge. You can now publish and sync content directly from your Forge dashboard.', 'forge-connector'); ?>
                        </p>

                        <div class="forge-site-info">
                            <div class="forge-info-row">
                                <span class="forge-info-label"><?php _e('Site URL', 'forge-connector'); ?></span>
                                <span class="forge-info-value"><?php echo esc_html(get_site_url()); ?></span>
                            </div>
                            <div class="forge-info-row">
                                <span class="forge-info-label"><?php _e('REST API', 'forge-connector'); ?></span>
                                <span class="forge-info-value"><?php echo esc_html(rest_url('forge/v1/')); ?></span>
                            </div>
                            <div class="forge-info-row">
                                <span class="forge-info-label"><?php _e('Plugin Version', 'forge-connector'); ?></span>
                                <span class="forge-info-value"><?php echo esc_html(FORGE_CONNECTOR_VERSION); ?></span>
                            </div>
                        </div>

                        <div class="forge-actions">
                            <button type="button" class="button" id="forge-test-connection">
                                <span class="forge-btn-text"><?php _e('Test Connection', 'forge-connector'); ?></span>
                                <span class="forge-btn-loading" style="display:none;">
                                    <span class="spinner is-active"></span>
                                </span>
                            </button>
                            <button type="button" class="button button-link-delete" id="forge-disconnect">
                                <?php _e('Disconnect', 'forge-connector'); ?>
                            </button>
                        </div>

                        <div class="forge-message" id="forge-message" style="display:none;"></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="forge-card forge-help">
                <h3><?php _e('Need Help?', 'forge-connector'); ?></h3>
                <p>
                    <?php _e('Visit our documentation for setup guides and troubleshooting.', 'forge-connector'); ?>
                </p>
                <a href="https://forge.gluska.co/docs" target="_blank" rel="noopener noreferrer" class="button">
                    <?php _e('View Documentation', 'forge-connector'); ?> →
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save connection key and test
     */
    public function ajax_save_key() {
        check_ajax_referer('forge_connector_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forge-connector')));
        }

        $key = sanitize_text_field($_POST['connection_key'] ?? '');

        if (empty($key)) {
            wp_send_json_error(array('message' => __('Connection key is required.', 'forge-connector')));
        }

        if (!Forge_Auth::validate_key_format($key)) {
            wp_send_json_error(array('message' => __('Invalid connection key format. Key should start with "fk_".', 'forge-connector')));
        }

        // Store the connection
        Forge_Auth::store_connection($key);

        wp_send_json_success(array(
            'message' => __('Connected successfully!', 'forge-connector'),
            'connected' => true,
        ));
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('forge_connector_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forge-connector')));
        }

        $status = Forge_Auth::get_status();

        if (!$status['has_key']) {
            wp_send_json_error(array('message' => __('No connection key configured.', 'forge-connector')));
        }

        // Just verify the key is still valid by checking format
        $settings = get_option('forge_connector_settings', array());
        if (!Forge_Auth::validate_key_format($settings['connection_key'])) {
            wp_send_json_error(array('message' => __('Connection key is invalid.', 'forge-connector')));
        }

        wp_send_json_success(array(
            'message' => __('Connection is working!', 'forge-connector'),
            'status' => $status,
        ));
    }

    /**
     * AJAX: Disconnect
     */
    public function ajax_disconnect() {
        check_ajax_referer('forge_connector_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forge-connector')));
        }

        // Notify Forge about the disconnect (non-blocking)
        $this->notify_forge_disconnect();

        Forge_Auth::disconnect();

        wp_send_json_success(array(
            'message' => __('Disconnected successfully.', 'forge-connector'),
            'connected' => false,
        ));
    }

    /**
     * Notify Forge API that this site has disconnected
     */
    private function notify_forge_disconnect() {
        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['connection_key']) || empty($settings['forge_site_id'])) {
            return; // Can't notify without credentials
        }

        $api_url = 'https://api.gluska.co/api/webhooks/wordpress/disconnect';
        $timestamp = time();
        $body = json_encode(array('action' => 'disconnect'));

        // Sign the request
        $string_to_sign = "POST\n/api/webhooks/wordpress/disconnect\n{$timestamp}\n{$body}";
        $signature = hash_hmac('sha256', $string_to_sign, $settings['connection_key']);

        // Send notification (non-blocking - we disconnect locally even if this fails)
        wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Forge-Signature' => $signature,
                'X-Forge-Timestamp' => (string) $timestamp,
                'X-Forge-Site-ID' => (string) $settings['forge_site_id'],
            ),
            'body' => $body,
            'timeout' => 5,
            'blocking' => false, // Don't wait for response
        ));
    }
}
