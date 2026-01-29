<?php
/**
 * Forge CTA Handler
 *
 * Handles CTA rendering via shortcode and tracks impressions/clicks.
 * CTAs are designed in Forge and delivered here for rendering.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_CTA {

    private $api_url = 'https://api.gluska.co/api';
    private $ctas_loaded = [];

    public function __construct() {
        // Register shortcode
        add_shortcode('forge_cta', array($this, 'render_shortcode'));

        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Load site-wide CTAs
        add_action('wp_footer', array($this, 'render_site_ctas'));
    }

    /**
     * Enqueue frontend tracking script
     */
    public function enqueue_scripts() {
        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['connected']) || empty($settings['forge_site_id'])) {
            return;
        }

        wp_enqueue_script(
            'forge-cta-tracker',
            FORGE_CONNECTOR_PLUGIN_URL . 'assets/js/cta-tracker.js',
            array(),
            FORGE_CONNECTOR_VERSION,
            true
        );

        wp_localize_script('forge-cta-tracker', 'forgeCTA', array(
            'apiUrl' => $this->api_url,
            'siteId' => $settings['forge_site_id'],
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
        ));
    }

    /**
     * Render CTA via shortcode [forge_cta id="slug"]
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'debug' => '',
        ), $atts, 'forge_cta');

        $debug = !empty($atts['debug']) || (defined('WP_DEBUG') && WP_DEBUG);
        $debug_output = '';

        if (empty($atts['id'])) {
            return '<!-- Forge CTA: No ID specified -->';
        }

        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['connected']) || empty($settings['forge_site_id'])) {
            if ($debug) {
                $debug_html = '<div style="padding:20px;background:#fee;border:1px solid #c00;margin:10px 0;font-size:12px;">';
                $debug_html .= '<strong>Forge CTA Debug:</strong> Connection issue<br>';
                $debug_html .= 'connected: ' . ($settings['connected'] ? 'true' : 'false') . '<br>';
                $debug_html .= 'forge_site_id: ' . esc_html($settings['forge_site_id'] ?? '(not set)') . '<br>';
                $debug_html .= 'connection_key: ' . (!empty($settings['connection_key']) ? '(set)' : '(not set)') . '<br>';
                $debug_html .= '<br><strong>Fix:</strong> Try disconnecting and reconnecting in the Forge Connector settings.';
                $debug_html .= '</div>';
                return $debug_html;
            }
            return '<!-- Forge CTA: Not connected to Forge -->';
        }

        if ($debug) {
            $debug_output .= '<div style="padding:20px;background:#eff;border:1px solid #09c;margin:10px 0;font-size:12px;">';
            $debug_output .= '<strong>Forge CTA Debug:</strong><br>';
            $debug_output .= 'Slug: ' . esc_html($atts['id']) . '<br>';
            $debug_output .= 'Site ID: ' . esc_html($settings['forge_site_id']) . '<br>';
        }

        // Fetch CTA from API (with caching)
        $cta = $this->get_cta_by_slug($atts['id'], $settings);

        if (!$cta) {
            if ($debug) {
                $debug_output .= 'Status: <span style="color:red;">CTA not found</span><br>';
                $debug_output .= 'API URL: ' . esc_html($this->api_url . '/ctas/slug/' . $atts['id']) . '<br>';
                $debug_output .= '</div>';
                return $debug_output;
            }
            return '<!-- Forge CTA: CTA not found for slug: ' . esc_html($atts['id']) . ' -->';
        }

        if ($debug) {
            $debug_output .= 'Status: <span style="color:green;">Found</span><br>';
            $debug_output .= 'CTA ID: ' . esc_html($cta['id']) . '<br>';
            $debug_output .= 'Type: ' . esc_html($cta['type']) . '<br>';
            $debug_output .= 'Headline: ' . esc_html($cta['content']['headline'] ?? '(empty)') . '<br>';
            $debug_output .= 'Button: ' . esc_html($cta['content']['button_text'] ?? '(empty)') . '<br>';
            $debug_output .= '</div>';
        }

        // Track that we've loaded this CTA
        $this->ctas_loaded[$cta['id']] = $cta;

        return $debug_output . $this->render_cta($cta);
    }

    /**
     * Render site-wide CTAs in footer (floating bars, popups)
     */
    public function render_site_ctas() {
        $settings = get_option('forge_connector_settings', array());

        if (empty($settings['connected']) || empty($settings['forge_site_id'])) {
            return;
        }

        // Fetch site-wide CTAs
        $ctas = $this->get_site_ctas($settings);

        if (empty($ctas)) {
            return;
        }

        foreach ($ctas as $cta) {
            if (!isset($this->ctas_loaded[$cta['id']])) {
                $this->ctas_loaded[$cta['id']] = $cta;
                echo $this->render_cta($cta);
            }
        }

        // Output the loaded CTAs data for JavaScript
        echo '<script>window.forgeCTAsLoaded = ' . json_encode(array_values($this->ctas_loaded)) . ';</script>';
    }

    /**
     * Get CTA by slug from API
     */
    private function get_cta_by_slug($slug, $settings) {
        $cache_key = 'forge_cta_' . md5($slug);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(
            $this->api_url . '/ctas/slug/' . urlencode($slug),
            array(
                'headers' => array(
                    'X-Forge-Site-Id' => $settings['forge_site_id'],
                    'X-Forge-Connection-Key' => $settings['connection_key'],
                ),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $cta = json_decode($body, true);

        if (empty($cta) || isset($cta['error'])) {
            return null;
        }

        // Cache for 5 minutes
        set_transient($cache_key, $cta, 5 * MINUTE_IN_SECONDS);

        return $cta;
    }

    /**
     * Get site-wide CTAs from API
     */
    private function get_site_ctas($settings) {
        $cache_key = 'forge_site_ctas_' . $settings['forge_site_id'];
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(
            $this->api_url . '/ctas/wordpress',
            array(
                'headers' => array(
                    'X-Forge-Site-Id' => $settings['forge_site_id'],
                    'X-Forge-Connection-Key' => $settings['connection_key'],
                ),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $ctas = $data['ctas'] ?? array();

        // Cache for 5 minutes
        set_transient($cache_key, $ctas, 5 * MINUTE_IN_SECONDS);

        return $ctas;
    }

    /**
     * Render a CTA to HTML
     */
    private function render_cta($cta) {
        $id = $cta['id'];
        $type = $cta['type'];

        // Merge with defaults to handle missing fields from API
        $default_content = array(
            'headline' => '',
            'text' => '',
            'button_text' => 'Get Started',
            'button_url' => '#',
            'secondary_button_text' => '',
            'secondary_button_url' => '',
            'show_close_button' => false,
            'eyebrow_text' => '',
            'phone' => '',
            'fine_print' => '',
        );
        $content = array_merge($default_content, $cta['content'] ?? array());

        $default_style = array(
            'background' => '#ffffff',
            'text_color' => '#374151',
            'headline_color' => '#111827',
            'button_bg' => '#2563eb',
            'button_text_color' => '#ffffff',
            'secondary_button_bg' => 'transparent',
            'secondary_button_text_color' => '#2563eb',
            'secondary_button_border_color' => '#2563eb',
            'border_color' => '#e5e7eb',
            'border_width' => 1,
            'border_radius' => 8,
            'button_radius' => 6,
            'padding' => 24,
            'shadow' => 'md',
        );
        $style = array_merge($default_style, $cta['style'] ?? array());

        // Build inline styles
        $containerStyles = $this->build_container_styles($style, $type);
        $buttonStyles = $this->build_button_styles($style);

        $html = '<div class="forge-cta forge-cta-' . esc_attr($type) . '" ';
        $html .= 'data-cta-id="' . esc_attr($id) . '" ';
        $html .= 'data-cta-type="' . esc_attr($type) . '" ';
        $html .= 'style="' . esc_attr($containerStyles) . '">';

        // Headline
        if (!empty($content['headline'])) {
            $headlineStyles = 'color:' . ($style['headline_color'] ?? $style['text_color']) . ';margin:0 0 8px;';
            $html .= '<h3 class="forge-cta-headline" style="' . esc_attr($headlineStyles) . '">';
            $html .= esc_html($content['headline']);
            $html .= '</h3>';
        }

        // Text
        if (!empty($content['text'])) {
            $html .= '<p class="forge-cta-text" style="margin:0 0 16px;opacity:0.9;">';
            $html .= esc_html($content['text']);
            $html .= '</p>';
        }

        // Primary Button
        if (!empty($content['button_text']) && !empty($content['button_url'])) {
            $html .= '<a href="' . esc_url($content['button_url']) . '" ';
            $html .= 'class="forge-cta-button forge-cta-button-primary" ';
            $html .= 'data-cta-button="primary" ';
            $html .= 'style="' . esc_attr($buttonStyles) . '">';
            $html .= esc_html($content['button_text']);
            $html .= '</a>';
        }

        // Secondary Button
        if (!empty($content['secondary_button_text']) && !empty($content['secondary_button_url'])) {
            $secondaryStyles = $this->build_secondary_button_styles($style);
            $html .= ' <a href="' . esc_url($content['secondary_button_url']) . '" ';
            $html .= 'class="forge-cta-button forge-cta-button-secondary" ';
            $html .= 'data-cta-button="secondary" ';
            $html .= 'style="' . esc_attr($secondaryStyles) . '">';
            $html .= esc_html($content['secondary_button_text']);
            $html .= '</a>';
        }

        // Close button for popups/floating bars
        if ($type !== 'banner' && !empty($content['show_close_button'])) {
            $html .= '<button class="forge-cta-close" data-cta-close aria-label="Close">&times;</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Build container inline styles
     */
    private function build_container_styles($style, $type) {
        $styles = array(
            'background-color:' . ($style['background'] ?? '#ffffff'),
            'color:' . ($style['text_color'] ?? '#333333'),
            'border-radius:' . ($style['border_radius'] ?? 8) . 'px',
            'padding:' . ($style['padding'] ?? 24) . 'px',
            'font-family:inherit',
        );

        if (!empty($style['border_width']) && $style['border_width'] > 0) {
            $styles[] = 'border:' . $style['border_width'] . 'px solid ' . ($style['border_color'] ?? '#e5e5e5');
        }

        if (!empty($style['shadow']) && $style['shadow'] !== 'none') {
            $shadows = array(
                'sm' => '0 1px 2px rgba(0,0,0,0.05)',
                'md' => '0 4px 6px rgba(0,0,0,0.1)',
                'lg' => '0 10px 15px rgba(0,0,0,0.1)',
                'xl' => '0 20px 25px rgba(0,0,0,0.15)',
            );
            $styles[] = 'box-shadow:' . ($shadows[$style['shadow']] ?? $shadows['md']);
        }

        // Type-specific positioning
        if ($type === 'floating-bar') {
            $position = $style['position'] ?? 'bottom';
            $styles[] = 'position:fixed';
            $styles[] = 'left:0';
            $styles[] = 'right:0';
            $styles[] = 'z-index:9999';
            $styles[] = ($position === 'top' ? 'top:0' : 'bottom:0');
        }

        if ($type === 'popup') {
            $styles[] = 'position:fixed';
            $styles[] = 'z-index:10000';
            $styles[] = 'max-width:500px';
            $styles[] = 'top:50%';
            $styles[] = 'left:50%';
            $styles[] = 'transform:translate(-50%,-50%)';
        }

        return implode(';', $styles);
    }

    /**
     * Build primary button inline styles
     */
    private function build_button_styles($style) {
        return implode(';', array(
            'display:inline-block',
            'background-color:' . ($style['button_bg'] ?? '#2563eb'),
            'color:' . ($style['button_text_color'] ?? '#ffffff'),
            'padding:12px 24px',
            'border-radius:' . ($style['button_radius'] ?? 6) . 'px',
            'text-decoration:none',
            'font-weight:600',
            'border:none',
            'cursor:pointer',
        ));
    }

    /**
     * Build secondary button inline styles
     */
    private function build_secondary_button_styles($style) {
        $bg = $style['secondary_button_bg'] ?? 'transparent';
        $color = $style['secondary_button_text_color'] ?? ($style['button_bg'] ?? '#2563eb');
        $border = $style['secondary_button_border_color'] ?? $color;

        return implode(';', array(
            'display:inline-block',
            'background-color:' . $bg,
            'color:' . $color,
            'padding:12px 24px',
            'border-radius:' . ($style['button_radius'] ?? 6) . 'px',
            'text-decoration:none',
            'font-weight:600',
            'border:1px solid ' . $border,
            'cursor:pointer',
            'margin-left:8px',
        ));
    }
}
