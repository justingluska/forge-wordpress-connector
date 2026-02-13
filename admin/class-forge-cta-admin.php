<?php
/**
 * Forge CTA Admin Page
 *
 * Provides admin interface for viewing and debugging CTAs
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_CTA_Admin {

    private $api_url = 'https://api.gluska.co/api';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_forge_clear_cta_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_forge_test_cta_api', array($this, 'ajax_test_api'));
    }

    public function add_menu() {
        add_menu_page(
            __('Forge CTAs', 'forge-connector'),
            __('Forge CTAs', 'forge-connector'),
            'manage_options',
            'forge-ctas',
            array($this, 'render_page'),
            'dashicons-megaphone',
            30
        );
    }

    public function render_page() {
        $settings = get_option('forge_connector_settings', array());
        $is_connected = !empty($settings['connected']) && !empty($settings['forge_site_id']);
        $site_id = $settings['forge_site_id'] ?? null;

        // Fetch CTAs from API if connected
        $ctas = array();
        $api_error = null;

        if ($is_connected && $site_id) {
            $result = $this->fetch_all_ctas($settings);
            if (is_wp_error($result)) {
                $api_error = $result->get_error_message();
            } else {
                $ctas = $result;
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Forge CTAs', 'forge-connector'); ?></h1>

            <!-- Connection Status -->
            <div class="forge-cta-status" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php _e('Connection Status', 'forge-connector'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Status', 'forge-connector'); ?></th>
                        <td>
                            <?php if ($is_connected): ?>
                                <span style="color: green; font-weight: bold;">&#10003; <?php _e('Connected', 'forge-connector'); ?></span>
                            <?php else: ?>
                                <span style="color: red; font-weight: bold;">&#10007; <?php _e('Not Connected', 'forge-connector'); ?></span>
                                <p class="description">
                                    <?php _e('Go to Settings → Forge Connector to connect your site.', 'forge-connector'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Site ID', 'forge-connector'); ?></th>
                        <td>
                            <?php echo $site_id ? esc_html($site_id) : '<em>' . __('Not set', 'forge-connector') . '</em>'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Connection Key', 'forge-connector'); ?></th>
                        <td>
                            <?php echo !empty($settings['connection_key']) ? '<span style="color: green;">&#10003; ' . __('Set', 'forge-connector') . '</span>' : '<span style="color: red;">&#10007; ' . __('Not set', 'forge-connector') . '</span>'; ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button" id="forge-clear-cache">
                        <?php _e('Clear CTA Cache', 'forge-connector'); ?>
                    </button>
                    <button type="button" class="button" id="forge-test-api">
                        <?php _e('Test API Connection', 'forge-connector'); ?>
                    </button>
                    <span id="forge-action-result" style="margin-left: 10px;"></span>
                </p>
            </div>

            <?php if ($api_error): ?>
            <div class="notice notice-error">
                <p><strong><?php _e('API Error:', 'forge-connector'); ?></strong> <?php echo esc_html($api_error); ?></p>
            </div>
            <?php endif; ?>

            <!-- Available CTAs -->
            <div class="forge-cta-list" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php _e('Available CTAs', 'forge-connector'); ?></h2>

                <?php if (!$is_connected): ?>
                    <p><?php _e('Connect to Forge to see available CTAs.', 'forge-connector'); ?></p>
                <?php elseif (empty($ctas)): ?>
                    <p><?php _e('No CTAs found. Create CTAs in your Forge dashboard.', 'forge-connector'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Name', 'forge-connector'); ?></th>
                                <th><?php _e('Slug', 'forge-connector'); ?></th>
                                <th><?php _e('Type', 'forge-connector'); ?></th>
                                <th><?php _e('Status', 'forge-connector'); ?></th>
                                <th><?php _e('Shortcode', 'forge-connector'); ?></th>
                                <th><?php _e('Preview', 'forge-connector'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            global $forge_cta;
                            foreach ($ctas as $cta):
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($cta['name']); ?></strong></td>
                                <td><code><?php echo esc_html($cta['slug']); ?></code></td>
                                <td><?php echo esc_html(ucfirst($cta['type'])); ?></td>
                                <td>
                                    <?php if (!empty($cta['is_active'])): ?>
                                        <span style="color: green;">&#10003; <?php _e('Active', 'forge-connector'); ?></span>
                                    <?php else: ?>
                                        <span style="color: gray;"><?php _e('Inactive', 'forge-connector'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code>[forge_cta id="<?php echo esc_attr($cta['slug']); ?>"]</code>
                                    <button type="button" class="button button-small forge-copy-shortcode" data-shortcode='[forge_cta id="<?php echo esc_attr($cta['slug']); ?>"]'>
                                        <?php _e('Copy', 'forge-connector'); ?>
                                    </button>
                                </td>
                                <td>
                                    <button type="button" class="button button-small forge-preview-toggle" data-cta-id="<?php echo esc_attr($cta['id']); ?>">
                                        <?php _e('Preview', 'forge-connector'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr class="forge-preview-row" id="forge-preview-<?php echo esc_attr($cta['id']); ?>" style="display: none;">
                                <td colspan="6" style="padding: 0; border-top: none;">
                                    <div style="background: #f9f9f9; border: 1px dashed #ccd0d4; border-radius: 4px; padding: 20px; margin: 8px 16px 16px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                            <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #888; font-weight: 600;">
                                                <?php _e('Preview (view-only)', 'forge-connector'); ?>
                                            </span>
                                            <a href="<?php echo esc_url('https://forge.gluska.co/ctas/' . $cta['id']); ?>" target="_blank" class="button button-small">
                                                <?php _e('Edit in Forge', 'forge-connector'); ?> &#8599;
                                            </a>
                                        </div>
                                        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; overflow: hidden;">
                                            <?php
                                            if ($forge_cta) {
                                                echo $forge_cta->render_cta($cta);
                                            } else {
                                                echo '<p style="color: #999;">' . esc_html__('Preview unavailable.', 'forge-connector') . '</p>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Debug Info -->
            <div class="forge-cta-debug" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php _e('Debug Information', 'forge-connector'); ?></h2>
                <p class="description"><?php _e('Use this info when troubleshooting CTA issues.', 'forge-connector'); ?></p>

                <h4><?php _e('Test a CTA Shortcode', 'forge-connector'); ?></h4>
                <p><?php _e('Add', 'forge-connector'); ?> <code>debug="1"</code> <?php _e('to any shortcode to see debug output:', 'forge-connector'); ?></p>
                <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">[forge_cta id="your-slug" debug="1"]</pre>

                <h4><?php _e('API Endpoints', 'forge-connector'); ?></h4>
                <ul>
                    <li><strong><?php _e('Get CTA by slug:', 'forge-connector'); ?></strong> <code><?php echo esc_html($this->api_url); ?>/ctas/slug/{slug}</code></li>
                    <li><strong><?php _e('Get site CTAs:', 'forge-connector'); ?></strong> <code><?php echo esc_html($this->api_url); ?>/ctas/wordpress</code></li>
                </ul>

                <h4><?php _e('Cache Keys', 'forge-connector'); ?></h4>
                <p><?php _e('CTA responses are cached for 5 minutes. Cache keys:', 'forge-connector'); ?></p>
                <ul>
                    <li><code>forge_cta_{md5(slug)}</code> - <?php _e('Individual CTA cache', 'forge-connector'); ?></li>
                    <li><code>forge_site_ctas_{site_id}</code> - <?php _e('Site-wide CTAs cache', 'forge-connector'); ?></li>
                </ul>

                <h4><?php _e('Raw Settings', 'forge-connector'); ?></h4>
                <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-height: 200px;"><?php
                    $debug_settings = $settings;
                    if (!empty($debug_settings['connection_key'])) {
                        $debug_settings['connection_key'] = substr($debug_settings['connection_key'], 0, 10) . '...';
                    }
                    print_r($debug_settings);
                ?></pre>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Clear cache
            $('#forge-clear-cache').on('click', function() {
                var $btn = $(this);
                var $result = $('#forge-action-result');
                $btn.prop('disabled', true);
                $result.text('<?php _e('Clearing...', 'forge-connector'); ?>');

                $.post(ajaxurl, {
                    action: 'forge_clear_cta_cache',
                    nonce: '<?php echo wp_create_nonce('forge_cta_admin'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color: green;">' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: red;">' + response.data.message + '</span>');
                    }
                });
            });

            // Test API
            $('#forge-test-api').on('click', function() {
                var $btn = $(this);
                var $result = $('#forge-action-result');
                $btn.prop('disabled', true);
                $result.text('<?php _e('Testing...', 'forge-connector'); ?>');

                $.post(ajaxurl, {
                    action: 'forge_test_cta_api',
                    nonce: '<?php echo wp_create_nonce('forge_cta_admin'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color: green;">' + response.data.message + '</span>');
                        // Reload page to show updated CTAs
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        $result.html('<span style="color: red;">' + response.data.message + '</span>');
                    }
                });
            });

            // Copy shortcode
            $('.forge-copy-shortcode').on('click', function() {
                var shortcode = $(this).data('shortcode');
                navigator.clipboard.writeText(shortcode).then(function() {
                    alert('<?php _e('Shortcode copied!', 'forge-connector'); ?>');
                });
            });

            // Toggle CTA preview
            $('.forge-preview-toggle').on('click', function() {
                var ctaId = $(this).data('cta-id');
                var $previewRow = $('#forge-preview-' + ctaId);
                var $btn = $(this);

                if ($previewRow.is(':visible')) {
                    $previewRow.hide();
                    $btn.text('<?php _e('Preview', 'forge-connector'); ?>');
                } else {
                    $previewRow.show();
                    $btn.text('<?php _e('Hide Preview', 'forge-connector'); ?>');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Fetch all CTAs from the API
     */
    private function fetch_all_ctas($settings) {
        $response = wp_remote_get(
            $this->api_url . '/ctas/wordpress/all',
            array(
                'headers' => array(
                    'X-Forge-Site-Id' => $settings['forge_site_id'],
                    'X-Forge-Connection-Key' => $settings['connection_key'],
                ),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            return new WP_Error('api_error', $data['error'] ?? "API returned status {$code}");
        }

        // Return all CTAs (endpoint now returns both inline and site-wide)
        return $data['ctas'] ?? array();
    }

    /**
     * AJAX: Clear CTA cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('forge_cta_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forge-connector')));
        }

        global $wpdb;

        // Delete all forge CTA transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_forge_cta_%' OR option_name LIKE '_transient_timeout_forge_cta_%' OR option_name LIKE '_transient_forge_site_ctas_%' OR option_name LIKE '_transient_timeout_forge_site_ctas_%'"
        );

        wp_send_json_success(array('message' => __('Cache cleared!', 'forge-connector')));
    }

    /**
     * AJAX: Test CTA API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('forge_cta_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forge-connector')));
        }

        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['forge_site_id'])) {
            wp_send_json_error(array('message' => __('Site ID not configured. Try syncing from Forge first.', 'forge-connector')));
        }

        // Test the API
        $response = wp_remote_get(
            $this->api_url . '/ctas/wordpress',
            array(
                'headers' => array(
                    'X-Forge-Site-Id' => $settings['forge_site_id'],
                    'X-Forge-Connection-Key' => $settings['connection_key'] ?? '',
                ),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 200) {
            // Clear cache on successful test
            global $wpdb;
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_forge_cta_%' OR option_name LIKE '_transient_timeout_forge_cta_%' OR option_name LIKE '_transient_forge_site_ctas_%' OR option_name LIKE '_transient_timeout_forge_site_ctas_%'"
            );

            wp_send_json_success(array('message' => __('API connection successful! Cache cleared.', 'forge-connector')));
        } else {
            $data = json_decode($body, true);
            $error = $data['error'] ?? "HTTP {$code}";
            wp_send_json_error(array('message' => $error));
        }
    }
}
