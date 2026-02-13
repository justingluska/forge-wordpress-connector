<?php
/**
 * Forge CTA Handler
 *
 * Handles CTA rendering via shortcode and tracks impressions/clicks.
 * CTAs are designed in Forge and delivered here for rendering.
 *
 * IMPORTANT: This render code must match the Forge React preview exactly.
 * See forge-ui/src/pages/cta-editor.tsx for the source of truth.
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
        echo '<script>window.forgeCTAsLoaded = ' . wp_json_encode(array_values($this->ctas_loaded)) . ';</script>';
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
     * Generate scoped CSS for a CTA
     *
     * Returns a CSS string with all selectors scoped under #forge-cta-{id}.
     * This replaces inline styles with semantic class-based styling.
     */
    private function generate_cta_css($cta) {
        $id = $cta['id'];
        $content = $cta['content'] ?? array();
        $style = $cta['style'] ?? array();
        $type = $cta['type'] ?? 'banner';

        // Apply default content values
        $content = array_merge(array(
            'image_position' => 'top',
            'image_scale' => 100,
            'image_fit' => 'cover',
            'show_phone_icon' => true,
            'button_style' => 'solid',
            'secondary_button_style' => 'outline',
        ), $content);

        // Apply default style values
        $style = array_merge(array(
            'background' => '#ffffff',
            'text_color' => '#374151',
            'headline_color' => '#111827',
            'headline_size' => 'lg',
            'headline_weight' => 'semibold',
            'text_size' => 'sm',
            'text_align' => 'left',
            'button_bg' => '#2563eb',
            'button_text_color' => '#ffffff',
            'button_hover_bg' => '#1d4ed8',
            'button_radius' => 6,
            'button_border_color' => '',
            'secondary_button_bg' => 'transparent',
            'secondary_button_text_color' => '#2563eb',
            'secondary_button_border_color' => '#2563eb',
            'border_color' => '#e5e7eb',
            'border_width' => 0,
            'border_radius' => 8,
            'padding' => 24,
            'padding_x' => null,
            'padding_y' => null,
            'gap' => 12,
            'shadow' => 'md',
            'shadow_offset_x' => 0,
            'shadow_offset_y' => 4,
            'shadow_blur' => 12,
            'shadow_color' => 'rgba(0, 0, 0, 0.15)',
            'layout' => 'horizontal',
            'animation' => 'none',
            'custom_css' => '',
            'bg_pattern' => 'none',
            'bg_pattern_opacity' => 10,
            'bg_pattern_color' => '#000000',
            'position' => 'bottom',
        ), $style);

        $s = '#forge-cta-' . esc_attr($id);
        $shadowCss = $this->get_shadow_style($style);
        $animationCss = $this->get_animation_style($style['animation']);
        $paddingY = $style['padding_y'] ?? $style['padding'];
        $paddingX = $style['padding_x'] ?? $style['padding'];
        $isHorizontal = $style['layout'] === 'horizontal';
        $headlineSize = $this->get_font_size($style['headline_size']);
        $headlineWeight = $this->get_font_weight($style['headline_weight']);
        $textSize = $this->get_font_size($style['text_size']);
        $buttonRadius = ($style['button_radius'] ?? $style['border_radius'] ?? 6) . 'px';
        $gap = ($style['gap'] ?? 12) . 'px';

        // Button color calculations
        $primaryBg = $style['button_bg'];
        $primaryText = $style['button_text_color'];
        $primaryBorder = $style['button_border_color'] ?: $style['button_bg'];
        $secondaryBg = $style['secondary_button_bg'];
        $secondaryText = $style['secondary_button_text_color'];
        $secondaryBorder = $style['secondary_button_border_color'];

        $effectivePrimaryStyle = ($content['button_style'] ?? 'solid') ?: 'solid';
        $effectiveSecondaryStyle = ($content['secondary_button_style'] ?? 'outline') ?: 'outline';

        $imgFit = $content['image_fit'] ?? 'cover';
        $imgScale = (!empty($content['image_scale']) && $content['image_scale'] != 100)
            ? "transform:scale(" . ($content['image_scale'] / 100) . ");" : '';

        $css = '';

        // --- CSS RESET on scoped container ---
        $css .= "{$s}{";
        $css .= "all:initial;display:block;box-sizing:border-box;";
        $css .= "font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,\"Helvetica Neue\",Arial,sans-serif;";
        $css .= "font-size:16px;line-height:1.5;";
        $css .= "background-color:{$style['background']};color:{$style['text_color']};";

        if ($type === 'banner') {
            $css .= "border-radius:{$style['border_radius']}px;";
            $css .= "padding:{$paddingY}px {$paddingX}px;";
            $css .= "width:100%;max-width:100%;position:relative;overflow:hidden;margin:1em 0;";
            if (!empty($style['border_width']) && $style['border_width'] > 0) {
                $css .= "border:{$style['border_width']}px solid {$style['border_color']};";
            }
        } elseif ($type === 'floating-bar') {
            $position = $style['position'];
            $css .= "position:fixed;left:0;right:0;{$position}:0;z-index:999999;";
            $css .= "padding:16px 24px;display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;";
        } elseif ($type === 'popup') {
            $css .= "position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:1000000;";
            $css .= "max-width:500px;width:90%;";
            $css .= "border-radius:{$style['border_radius']}px;";
            $css .= "padding:{$style['padding']}px;";
        }

        if ($shadowCss) {
            $css .= "box-shadow:{$shadowCss};";
        }
        if ($animationCss) {
            $css .= "animation:{$animationCss};";
        }
        $css .= "}\n";

        // Ensure box-sizing for all children
        $css .= "{$s} *,{$s} *::before,{$s} *::after{box-sizing:border-box;}\n";

        // --- Pattern overlay ---
        $patternStyles = $this->get_background_pattern($style);
        $css .= "{$s} .forge-cta-pattern{position:absolute;inset:0;pointer-events:none;";
        if (!empty($patternStyles['background-image'])) {
            $css .= "background-image:{$patternStyles['background-image']};";
        }
        if (!empty($patternStyles['background-size'])) {
            $css .= "background-size:{$patternStyles['background-size']};";
        }
        $css .= "}\n";

        // --- Background image ---
        $css .= "{$s} .forge-cta-bg-image{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:{$imgFit};z-index:0;{$imgScale}}\n";
        $css .= "{$s} .forge-cta-bg-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.4);z-index:1;}\n";

        // --- Wrapper ---
        $css .= "{$s} .forge-cta-wrapper{position:relative;z-index:10;}\n";

        // --- Image (top) ---
        $css .= "{$s} .forge-cta-image-top{display:block;width:100%;max-height:200px;border-radius:8px;object-fit:{$imgFit};{$imgScale}}\n";
        $css .= "{$s} .forge-cta-below-image{margin-top:16px;}\n";

        // --- Image (side: left/right) ---
        $css .= "{$s} .forge-cta-image-side{display:block;width:128px;height:128px;border-radius:8px;flex-shrink:0;object-fit:{$imgFit};{$imgScale}}\n";

        // --- Side image flex wrapper ---
        $css .= "{$s} .forge-cta-side-layout{display:flex;gap:24px;align-items:center;position:relative;z-index:10;}\n";
        $css .= "{$s} .forge-cta-side-layout--reverse{flex-direction:row-reverse;}\n";
        $css .= "{$s} .forge-cta-side-content{flex:1;}\n";

        // --- Content wrapper (flex container for text + buttons) ---
        $css .= "{$s} .forge-cta-content-wrapper{display:flex;gap:{$gap};";
        if ($isHorizontal) {
            $css .= "align-items:center;justify-content:space-between;flex-wrap:wrap;";
        } else {
            $css .= "flex-direction:column;text-align:center;align-items:center;";
        }
        $css .= "}\n";

        // --- Content (text side) ---
        if (!$isHorizontal) {
            $css .= "{$s} .forge-cta-content{text-align:center;}\n";
        }

        // --- Eyebrow ---
        $css .= "{$s} .forge-cta-eyebrow{display:block;font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7;margin-bottom:4px;}\n";

        // --- Headline ---
        $css .= "{$s} .forge-cta-headline{color:{$style['headline_color']};margin:0;font-size:{$headlineSize};font-weight:{$headlineWeight};line-height:1.3;}\n";

        // --- Text ---
        $css .= "{$s} .forge-cta-text{margin:4px 0 0 0;font-size:{$textSize};opacity:0.9;color:{$style['text_color']};}\n";

        // --- Buttons container ---
        $css .= "{$s} .forge-cta-buttons{display:flex;gap:{$gap};align-items:center;flex-shrink:0;";
        if (!$isHorizontal) {
            $css .= "flex-direction:column;width:100%;margin-top:16px;";
        }
        $css .= "}\n";

        // --- Phone link ---
        $css .= "{$s} .forge-cta-phone{display:flex;align-items:center;gap:8px;font-weight:500;white-space:nowrap;color:{$style['button_bg']};text-decoration:none;}\n";

        // --- Common button base ---
        $css .= "{$s} .forge-cta-button{display:inline-flex;align-items:center;gap:8px;font-weight:500;text-decoration:none;white-space:nowrap;cursor:pointer;";
        $css .= "border-radius:{$buttonRadius};padding:10px 20px;font-size:14px;line-height:1.5;text-align:center;transition:all 0.2s ease;font-family:inherit;}\n";

        // --- Primary button ---
        $css .= "{$s} .forge-cta-button-primary{";
        if ($effectivePrimaryStyle === 'solid') {
            $css .= "background-color:{$primaryBg};color:{$primaryText};border:2px solid {$primaryBorder};";
        } elseif ($effectivePrimaryStyle === 'outline') {
            $css .= "background-color:transparent;color:{$primaryBorder};border:2px solid {$primaryBorder};";
        } else {
            $css .= "background-color:transparent;color:{$primaryBorder};border:none;";
        }
        $css .= "}\n";

        // --- Secondary button ---
        $css .= "{$s} .forge-cta-button-secondary{";
        if ($effectiveSecondaryStyle === 'solid') {
            $css .= "background-color:{$secondaryBg};color:{$secondaryText};border:2px solid {$secondaryBorder};";
        } elseif ($effectiveSecondaryStyle === 'outline') {
            $css .= "background-color:transparent;color:{$secondaryBorder};border:2px solid {$secondaryBorder};";
        } else {
            $css .= "background-color:transparent;color:{$secondaryBorder};border:none;";
        }
        $css .= "}\n";

        // --- Fine print ---
        $css .= "{$s} .forge-cta-fine-print{font-size:10px;opacity:0.5;margin:12px 0 0 0;position:relative;z-index:10;}\n";

        // --- Close button ---
        $css .= "{$s} .forge-cta-close{background:none;border:none;cursor:pointer;color:{$style['text_color']};opacity:0.5;}\n";

        // --- Floating bar specific ---
        if ($type === 'floating-bar') {
            $css .= "{$s} .forge-cta-bar-content{text-align:center;}\n";
            $css .= "{$s} .forge-cta-bar-headline{font-weight:bold;color:{$style['headline_color']};}\n";
            $css .= "{$s} .forge-cta-bar-text{margin-left:8px;opacity:0.9;}\n";
            $css .= "{$s} .forge-cta-close{position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:20px;}\n";
        }

        // --- Popup specific ---
        if ($type === 'popup') {
            $popupHeadlineSize = $this->get_font_size($style['headline_size'] === 'lg' ? 'xl' : $style['headline_size']);
            $css .= "{$s} .forge-cta-close{position:absolute;top:12px;right:12px;font-size:24px;}\n";
            $css .= "{$s} .forge-cta-popup-content{text-align:center;}\n";
            $css .= "{$s} .forge-cta-popup-headline{color:{$style['headline_color']};margin:0 0 8px;font-size:{$popupHeadlineSize};font-weight:600;line-height:1.3;}\n";
            $css .= "{$s} .forge-cta-popup-text{margin:0 0 16px;opacity:0.9;}\n";
            $css .= "{$s} .forge-cta-popup-buttons{display:flex;flex-direction:column;gap:12px;align-items:center;}\n";
        }

        // --- Backdrop (popup only, scoped by CTA-specific class) ---
        if ($type === 'popup') {
            $css .= ".forge-cta-backdrop-" . esc_attr($id) . "{all:initial;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:999999;}\n";
        }

        // --- Custom CSS (sanitized to prevent XSS via </style> injection) ---
        if (!empty($style['custom_css'])) {
            $custom = wp_strip_all_tags($style['custom_css']);
            $custom = str_replace('<', '', $custom);
            $css .= "{$s}{" . $custom . "}\n";
        }

        return $css;
    }

    /**
     * Render a CTA to HTML
     *
     * This function mirrors the React rendering in forge-ui/src/pages/cta-editor.tsx
     * to ensure CTAs look identical in WordPress and Forge preview.
     */
    public function render_cta($cta) {
        $id = $cta['id'];
        $type = $cta['type'] ?? 'banner';
        $content = $cta['content'] ?? array();
        $style = $cta['style'] ?? array();

        // Apply default content values
        $content = array_merge(array(
            'headline' => '',
            'text' => '',
            'eyebrow_text' => '',
            'button_text' => '',
            'button_url' => '#',
            'button_style' => 'solid',
            'button_icon' => '',
            'button_icon_position' => 'left',
            'secondary_button_text' => '',
            'secondary_button_url' => '#',
            'secondary_button_style' => 'outline',
            'show_close_button' => false,
            'fine_print' => '',
            'phone' => '',
            'show_phone_icon' => true,
            'image_url' => '',
            'image_position' => 'top',
            'image_alt' => '',
            'image_scale' => 100,
            'image_fit' => 'cover',
            'is_lead_magnet' => false,
            'lead_magnet_title' => '',
        ), $content);

        // Apply default style values (matching Forge defaults)
        $style = array_merge(array(
            'background' => '#ffffff',
            'text_color' => '#374151',
            'headline_color' => '#111827',
            'headline_size' => 'lg',
            'headline_weight' => 'semibold',
            'text_size' => 'sm',
            'text_align' => 'left',
            'button_bg' => '#2563eb',
            'button_text_color' => '#ffffff',
            'button_hover_bg' => '#1d4ed8',
            'button_radius' => 6,
            'button_border_color' => '',
            'secondary_button_bg' => 'transparent',
            'secondary_button_text_color' => '#2563eb',
            'secondary_button_border_color' => '#2563eb',
            'border_color' => '#e5e7eb',
            'border_width' => 0,
            'border_radius' => 8,
            'padding' => 24,
            'padding_x' => null,
            'padding_y' => null,
            'gap' => 12,
            'shadow' => 'md',
            'shadow_offset_x' => 0,
            'shadow_offset_y' => 4,
            'shadow_blur' => 12,
            'shadow_color' => 'rgba(0, 0, 0, 0.15)',
            'layout' => 'horizontal',
            'animation' => 'none',
            'custom_css' => '',
            'bg_pattern' => 'none',
            'bg_pattern_opacity' => 10,
            'bg_pattern_color' => '#000000',
        ), $style);

        // Determine layout
        $isHorizontal = ($style['layout'] ?? 'horizontal') === 'horizontal';
        $hasImage = !empty($content['image_url']);
        $imagePosition = $content['image_position'] ?? 'top';

        // Generate scoped CSS
        $css = $this->generate_cta_css($cta);

        // Render based on type
        $html = '<style>' . $css . '</style>';

        if ($type === 'banner') {
            $html .= $this->render_banner_cta($id, $content, $style, $isHorizontal, $hasImage, $imagePosition);
        } elseif ($type === 'floating-bar') {
            $html .= $this->render_floating_bar_cta($id, $content, $style);
        } elseif ($type === 'popup') {
            $html .= $this->render_popup_cta($id, $content, $style);
        }

        return $html;
    }

    /**
     * Render a banner (inline) CTA
     */
    private function render_banner_cta($id, $content, $style, $isHorizontal, $hasImage, $imagePosition) {
        $html = '';

        $html .= '<div id="forge-cta-' . esc_attr($id) . '" class="forge-cta forge-cta-banner" ';
        $html .= 'data-cta-id="' . esc_attr($id) . '" ';
        $html .= 'data-cta-type="banner">';

        // Pattern overlay
        if (($style['bg_pattern'] ?? 'none') !== 'none') {
            $html .= '<div class="forge-cta-pattern"></div>';
        }

        // Image as background
        if ($hasImage && $imagePosition === 'background') {
            $html .= '<img class="forge-cta-bg-image" src="' . esc_url($content['image_url']) . '" alt="' . esc_attr($content['image_alt']) . '" />';
            $html .= '<div class="forge-cta-bg-overlay"></div>';
        }

        // Determine layout based on image position
        if ($hasImage && $imagePosition === 'top') {
            $html .= '<div class="forge-cta-wrapper">';
            $html .= $this->render_image($content, 'top');
            $html .= '<div class="forge-cta-below-image">';
            $html .= $this->render_banner_content($content, $style, false);
            $html .= '</div>';
            $html .= '</div>';
        } elseif ($hasImage && ($imagePosition === 'left' || $imagePosition === 'right')) {
            $reverseClass = $imagePosition === 'right' ? ' forge-cta-side-layout--reverse' : '';
            $html .= '<div class="forge-cta-side-layout' . $reverseClass . '">';
            $html .= $this->render_image($content, $imagePosition);
            $html .= '<div class="forge-cta-side-content">';
            $html .= $this->render_banner_content($content, $style, $isHorizontal);
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="forge-cta-wrapper">';
            $html .= $this->render_banner_content($content, $style, $isHorizontal);
            $html .= '</div>';
        }

        // Fine print
        if (!empty($content['fine_print'])) {
            $html .= '<p class="forge-cta-fine-print">';
            $html .= esc_html($content['fine_print']);
            $html .= '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render image element
     */
    private function render_image($content, $position) {
        if (empty($content['image_url'])) return '';

        $class = ($position === 'top') ? 'forge-cta-image-top' : 'forge-cta-image-side';

        return '<img class="' . $class . '" src="' . esc_url($content['image_url']) . '" alt="' . esc_attr($content['image_alt']) . '" />';
    }

    /**
     * Render banner content (headline, text, buttons)
     */
    private function render_banner_content($content, $style, $isHorizontal) {
        $html = '';

        $html .= '<div class="forge-cta-content-wrapper">';

        // Content side (headline + text)
        $html .= '<div class="forge-cta-content">';

        // Eyebrow
        if (!empty($content['eyebrow_text'])) {
            $html .= '<span class="forge-cta-eyebrow">';
            $html .= esc_html($content['eyebrow_text']);
            $html .= '</span>';
        }

        // Headline
        if (!empty($content['headline'])) {
            $html .= '<h3 class="forge-cta-headline">';
            $html .= esc_html($content['headline']);
            $html .= '</h3>';
        }

        // Text
        if (!empty($content['text'])) {
            $html .= '<p class="forge-cta-text">';
            $html .= esc_html($content['text']);
            $html .= '</p>';
        }

        $html .= '</div>';

        // Buttons side
        $html .= '<div class="forge-cta-buttons">';

        // Phone number
        if (!empty($content['phone'])) {
            $html .= '<a class="forge-cta-phone" href="tel:' . esc_attr(preg_replace('/\D/', '', $content['phone'])) . '">';
            if (!empty($content['show_phone_icon'])) {
                $html .= $this->get_phone_icon($style['button_bg']);
            }
            $html .= esc_html($content['phone']);
            $html .= '</a>';
        }

        // Primary button
        if (!empty($content['button_text'])) {
            $html .= $this->render_button(
                $content['button_text'],
                $content['button_url'],
                true,
                $style,
                !empty($content['is_lead_magnet'])
            );
        }

        // Secondary button
        if (!empty($content['secondary_button_text'])) {
            $html .= $this->render_button(
                $content['secondary_button_text'],
                $content['secondary_button_url'],
                false,
                $style,
                false
            );
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a floating bar CTA
     */
    private function render_floating_bar_cta($id, $content, $style) {
        $html = '<div id="forge-cta-' . esc_attr($id) . '" class="forge-cta forge-cta-floating-bar" ';
        $html .= 'data-cta-id="' . esc_attr($id) . '" ';
        $html .= 'data-cta-type="floating-bar">';

        // Content
        if (!empty($content['headline']) || !empty($content['text'])) {
            $html .= '<div class="forge-cta-bar-content">';
            if (!empty($content['headline'])) {
                $html .= '<strong class="forge-cta-bar-headline">' . esc_html($content['headline']) . '</strong>';
            }
            if (!empty($content['text'])) {
                $html .= '<span class="forge-cta-bar-text">' . esc_html($content['text']) . '</span>';
            }
            $html .= '</div>';
        }

        // Button
        if (!empty($content['button_text'])) {
            $html .= $this->render_button($content['button_text'], $content['button_url'], true, $style, !empty($content['is_lead_magnet']));
        }

        // Close button
        if (!empty($content['show_close_button'])) {
            $html .= '<button class="forge-cta-close" data-cta-close>&times;</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a popup CTA
     */
    private function render_popup_cta($id, $content, $style) {
        // Backdrop
        $html = '<div class="forge-cta-backdrop-' . esc_attr($id) . '" data-cta-backdrop></div>';

        $html .= '<div id="forge-cta-' . esc_attr($id) . '" class="forge-cta forge-cta-popup" ';
        $html .= 'data-cta-id="' . esc_attr($id) . '" ';
        $html .= 'data-cta-type="popup">';

        // Close button
        $html .= '<button class="forge-cta-close" data-cta-close>&times;</button>';

        // Content
        $html .= '<div class="forge-cta-popup-content">';

        if (!empty($content['headline'])) {
            $html .= '<h3 class="forge-cta-popup-headline">' . esc_html($content['headline']) . '</h3>';
        }

        if (!empty($content['text'])) {
            $html .= '<p class="forge-cta-popup-text">' . esc_html($content['text']) . '</p>';
        }

        // Buttons
        $html .= '<div class="forge-cta-popup-buttons">';
        if (!empty($content['button_text'])) {
            $html .= $this->render_button($content['button_text'], $content['button_url'], true, $style, !empty($content['is_lead_magnet']));
        }
        if (!empty($content['secondary_button_text'])) {
            $html .= $this->render_button($content['secondary_button_text'], $content['secondary_button_url'], false, $style, false);
        }
        $html .= '</div>';

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a button element
     * Uses semantic classes styled by generate_cta_css()
     */
    private function render_button($text, $url, $isPrimary, $style, $showDownloadIcon = false) {
        if (empty($text)) return '';

        $class = 'forge-cta-button forge-cta-button-' . ($isPrimary ? 'primary' : 'secondary');

        $html = '<a href="' . esc_url($url) . '" ';
        $html .= 'class="' . $class . '" ';
        $html .= 'data-cta-button="' . ($isPrimary ? 'primary' : 'secondary') . '">';

        // Download icon for lead magnets
        if ($showDownloadIcon) {
            $textColor = $isPrimary ? $style['button_text_color'] : ($style['secondary_button_text_color'] ?? $style['button_bg']);
            $html .= $this->get_download_icon($textColor);
        }

        $html .= esc_html($text);
        $html .= '</a>';

        return $html;
    }

    /**
     * Get shadow CSS value - matches Forge's getShadowStyle function
     */
    private function get_shadow_style($style) {
        $shadow = $style['shadow'] ?? 'md';

        if ($shadow === 'none') {
            return '';
        }

        // Check if custom shadow values are set
        if (isset($style['shadow_offset_x']) || isset($style['shadow_offset_y']) || isset($style['shadow_blur']) || isset($style['shadow_color'])) {
            $x = $style['shadow_offset_x'] ?? 0;
            $y = $style['shadow_offset_y'] ?? 4;
            $blur = $style['shadow_blur'] ?? 12;
            $color = $style['shadow_color'] ?? 'rgba(0, 0, 0, 0.15)';
            return "{$x}px {$y}px {$blur}px {$color}";
        }

        // Fallback to preset shadows
        $shadows = array(
            'none' => '',
            'sm' => '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
            'md' => '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1)',
            'lg' => '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1)',
            'xl' => '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)',
        );

        return $shadows[$shadow] ?? $shadows['md'];
    }

    /**
     * Get background pattern styles - matches Forge's getBackgroundPattern function
     */
    private function get_background_pattern($style) {
        $pattern = $style['bg_pattern'] ?? 'none';

        if ($pattern === 'none') {
            return array();
        }

        $opacity = ($style['bg_pattern_opacity'] ?? 10) / 100;
        $color = $style['bg_pattern_color'] ?? '#000000';
        $patternColor = $this->apply_opacity_to_color($color, $opacity);

        $patterns = array(
            'dots' => "radial-gradient({$patternColor} 1px, transparent 1px)",
            'grid' => "linear-gradient({$patternColor} 1px, transparent 1px), linear-gradient(90deg, {$patternColor} 1px, transparent 1px)",
            'diagonal-lines' => "repeating-linear-gradient(45deg, transparent, transparent 10px, {$patternColor} 10px, {$patternColor} 11px)",
        );

        $sizes = array(
            'dots' => '20px 20px',
            'grid' => '20px 20px, 20px 20px',
            'diagonal-lines' => 'auto',
        );

        if (!isset($patterns[$pattern])) {
            return array();
        }

        return array(
            'background-image' => $patterns[$pattern],
            'background-size' => $sizes[$pattern],
        );
    }

    /**
     * Apply opacity to a hex color
     */
    private function apply_opacity_to_color($color, $opacity) {
        // Handle hex colors
        if (strpos($color, '#') === 0) {
            $hex = ltrim($color, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return "rgba({$r},{$g},{$b},{$opacity})";
        }

        // Handle rgba - replace alpha
        if (strpos($color, 'rgba') === 0) {
            return preg_replace('/[\d.]+\)$/', $opacity . ')', $color);
        }

        // Handle rgb - convert to rgba
        if (strpos($color, 'rgb(') === 0) {
            return str_replace('rgb(', 'rgba(', str_replace(')', ",{$opacity})", $color));
        }

        return $color;
    }

    /**
     * Get animation CSS
     */
    private function get_animation_style($animation) {
        if ($animation === 'none') {
            return '';
        }

        $animations = array(
            'fade' => 'forgeFadeIn 0.3s ease-out',
            'slide-up' => 'forgeSlideUp 0.3s ease-out',
            'slide-down' => 'forgeSlideDown 0.3s ease-out',
            'scale' => 'forgeScale 0.3s ease-out',
        );

        // Output keyframes once
        static $keyframes_added = false;
        if (!$keyframes_added && isset($animations[$animation])) {
            add_action('wp_footer', function() {
                echo '<style>
                    @keyframes forgeFadeIn { from { opacity: 0; } to { opacity: 1; } }
                    @keyframes forgeSlideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
                    @keyframes forgeSlideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
                    @keyframes forgeScale { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
                </style>';
            }, 5);
            $keyframes_added = true;
        }

        return $animations[$animation] ?? '';
    }

    /**
     * Get font size from size name
     */
    private function get_font_size($size) {
        $sizes = array(
            'xs' => '12px',
            'sm' => '14px',
            'base' => '16px',
            'lg' => '18px',
            'xl' => '20px',
            '2xl' => '24px',
            '3xl' => '30px',
        );

        return $sizes[$size] ?? $sizes['base'];
    }

    /**
     * Get font weight from weight name
     */
    private function get_font_weight($weight) {
        $weights = array(
            'normal' => '400',
            'medium' => '500',
            'semibold' => '600',
            'bold' => '700',
        );

        return $weights[$weight] ?? $weights['semibold'];
    }

    /**
     * Get download icon SVG
     */
    private function get_download_icon($color) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
    }

    /**
     * Get phone icon SVG
     */
    private function get_phone_icon($color) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg> ';
    }

}
