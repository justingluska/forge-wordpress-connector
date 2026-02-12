<?php
/**
 * Forge REST API Handler
 *
 * Registers and handles all Forge REST API endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_API {

    const NAMESPACE = 'forge/v1';

    /**
     * Initialize API - add hooks for cache control
     */
    public function init() {
        // Prevent caching of all Forge API responses
        add_filter('rest_post_dispatch', array($this, 'add_cache_headers'), 10, 3);
    }

    /**
     * Add no-cache headers to all Forge API responses
     * This prevents WP Engine and other caching layers from caching authenticated responses
     */
    public function add_cache_headers($response, $server, $request) {
        $route = $request->get_route();

        // Only apply to Forge API endpoints
        if (strpos($route, '/forge/v1') === 0) {
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }

        return $response;
    }

    /**
     * Register all REST routes
     */
    public function register_routes() {
        // Connection endpoints
        // SECURITY: /connect requires the connection key in the request body
        // AND the site must not already be connected (prevents hijacking)
        register_rest_route(self::NAMESPACE, '/connect', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle_connect'),
            'permission_callback' => array($this, 'check_connect_permission'),
        ));

        register_rest_route(self::NAMESPACE, '/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'handle_status'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        register_rest_route(self::NAMESPACE, '/disconnect', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle_disconnect'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Sync endpoint - get all site data
        register_rest_route(self::NAMESPACE, '/sync', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'handle_sync'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Posts endpoints
        $posts = new Forge_Posts();
        $posts->register_routes(self::NAMESPACE);

        // Media endpoints
        $media = new Forge_Media();
        $media->register_routes(self::NAMESPACE);

        // Categories
        register_rest_route(self::NAMESPACE, '/categories', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        register_rest_route(self::NAMESPACE, '/categories', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_category'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Tags
        register_rest_route(self::NAMESPACE, '/tags', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_tags'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        register_rest_route(self::NAMESPACE, '/tags', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_tag'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Users
        register_rest_route(self::NAMESPACE, '/users', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_users'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Post types
        register_rest_route(self::NAMESPACE, '/post-types', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_post_types'),
            'permission_callback' => array($this, 'check_auth'),
        ));

        // Post statuses
        register_rest_route(self::NAMESPACE, '/post-statuses', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_post_statuses'),
            'permission_callback' => array($this, 'check_auth'),
        ));
    }

    /**
     * Permission callback - validates HMAC signature
     * IMPORTANT: This runs BEFORE the callback handler
     * Must be static because Forge_Posts and Forge_Media reference it as
     * array('Forge_API', 'check_auth') - a static callback
     */
    public static function check_auth($request) {
        // Prevent caching of authenticated API responses - set early
        nocache_headers();

        $result = Forge_Auth::validate_request($request);

        if (is_wp_error($result)) {
            return $result;
        }

        // Set WordPress user context after HMAC auth succeeds
        // Required for wp_insert_post() and plugins like ACF PRO that
        // check current_user_can() during post creation hooks
        self::set_user_context();

        return true;
    }

    /**
     * Set a WordPress user context for API operations
     * Uses the first available administrator, falling back to any user with edit_posts
     */
    private static function set_user_context() {
        $admins = get_users(array(
            'role' => 'administrator',
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
        ));

        if (!empty($admins)) {
            wp_set_current_user($admins[0]->ID);
            return;
        }

        // Fallback: any user who can edit posts
        $editors = get_users(array(
            'capability' => 'edit_posts',
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
        ));

        if (!empty($editors)) {
            wp_set_current_user($editors[0]->ID);
        }
    }

    /**
     * Permission callback for connect endpoint
     *
     * SECURITY: Only allows connection if:
     * 1. Site is NOT already connected (prevents hijacking), OR
     * 2. Request includes the existing connection key (allows reconnection)
     */
    public function check_connect_permission($request) {
        // If not connected, anyone with a valid key can connect
        if (!Forge_Auth::is_connected()) {
            return true;
        }

        // Site is already connected - only allow if the request comes with the same key
        // This allows Forge to "reconnect" if needed, but prevents hijacking
        $params = $request->get_json_params();
        $provided_key = sanitize_text_field($params['connection_key'] ?? '');

        if (empty($provided_key)) {
            return new WP_Error(
                'forge_already_connected',
                __('This site is already connected to Forge. Disconnect first or provide the existing connection key.', 'forge-connector'),
                array('status' => 403)
            );
        }

        // Verify the provided key matches the stored key
        $settings = get_option('forge_connector_settings', array());
        $stored_key = $settings['connection_key'] ?? '';

        // Use constant-time comparison to prevent timing attacks
        if (!hash_equals($stored_key, $provided_key)) {
            return new WP_Error(
                'forge_invalid_connection_key',
                __('Invalid connection key. This site is already connected to a different Forge account.', 'forge-connector'),
                array('status' => 403)
            );
        }

        // Key matches - allow reconnection
        return true;
    }

    /**
     * Handle initial connection handshake
     */
    public function handle_connect($request) {
        $params = $request->get_json_params();
        $connection_key = sanitize_text_field($params['connection_key'] ?? '');
        $forge_site_id = sanitize_text_field($params['forge_site_id'] ?? '');

        if (empty($connection_key)) {
            return new WP_Error(
                'missing_key',
                __('Connection key is required.', 'forge-connector'),
                array('status' => 400)
            );
        }

        if (!Forge_Auth::validate_key_format($connection_key)) {
            return new WP_Error(
                'invalid_key',
                __('Invalid connection key format.', 'forge-connector'),
                array('status' => 400)
            );
        }

        // Store the connection
        Forge_Auth::store_connection($connection_key, $forge_site_id);

        // Return site info
        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Connected successfully!', 'forge-connector'),
            'site' => $this->get_site_info(),
        ));
    }

    /**
     * Handle status check
     */
    public function handle_status($request) {
        return rest_ensure_response(array(
            'success' => true,
            'status' => Forge_Auth::get_status(),
            'site' => $this->get_site_info(),
        ));
    }

    /**
     * Handle disconnect
     */
    public function handle_disconnect($request) {
        Forge_Auth::disconnect();

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Disconnected successfully.', 'forge-connector'),
        ));
    }

    /**
     * Handle full sync - returns all site data
     */
    public function handle_sync($request) {
        // Prevent caching of authenticated API responses
        nocache_headers();

        return rest_ensure_response(array(
            'success' => true,
            'site' => $this->get_site_info(),
            'categories' => $this->fetch_all_categories(),
            'tags' => $this->fetch_all_tags(),
            'users' => $this->fetch_all_users(),
            'post_types' => $this->fetch_post_types(),
            'post_statuses' => $this->fetch_post_statuses(),
            'media_count' => $this->get_media_count(),
        ));
    }

    /**
     * Get categories
     */
    public function get_categories($request) {
        return rest_ensure_response(array(
            'success' => true,
            'categories' => $this->fetch_all_categories(),
        ));
    }

    /**
     * Create category
     */
    public function create_category($request) {
        $params = $request->get_json_params();

        $cat_data = array(
            'cat_name' => sanitize_text_field($params['name'] ?? ''),
            'category_description' => sanitize_textarea_field($params['description'] ?? ''),
            'category_parent' => intval($params['parent'] ?? 0),
        );

        if (empty($cat_data['cat_name'])) {
            return new WP_Error('missing_name', __('Category name is required.', 'forge-connector'), array('status' => 400));
        }

        $result = wp_insert_category($cat_data);

        if (is_wp_error($result)) {
            return $result;
        }

        $category = get_category($result);

        return rest_ensure_response(array(
            'success' => true,
            'category' => array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'parent' => $category->parent,
                'count' => $category->count,
            ),
        ));
    }

    /**
     * Get tags
     */
    public function get_tags($request) {
        return rest_ensure_response(array(
            'success' => true,
            'tags' => $this->fetch_all_tags(),
        ));
    }

    /**
     * Create tag
     */
    public function create_tag($request) {
        $params = $request->get_json_params();
        $name = sanitize_text_field($params['name'] ?? '');

        if (empty($name)) {
            return new WP_Error('missing_name', __('Tag name is required.', 'forge-connector'), array('status' => 400));
        }

        $result = wp_insert_term($name, 'post_tag', array(
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'slug' => sanitize_title($params['slug'] ?? $name),
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        $tag = get_tag($result['term_id']);

        return rest_ensure_response(array(
            'success' => true,
            'tag' => array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'count' => $tag->count,
            ),
        ));
    }

    /**
     * Get users
     */
    public function get_users($request) {
        return rest_ensure_response(array(
            'success' => true,
            'users' => $this->fetch_all_users(),
        ));
    }

    /**
     * Get post types
     */
    public function get_post_types($request) {
        return rest_ensure_response(array(
            'success' => true,
            'post_types' => $this->fetch_post_types(),
        ));
    }

    /**
     * Get post statuses
     */
    public function get_post_statuses($request) {
        return rest_ensure_response(array(
            'success' => true,
            'post_statuses' => $this->fetch_post_statuses(),
        ));
    }

    /**
     * Get basic site information
     */
    private function get_site_info() {
        global $wp_version;

        return array(
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => get_site_url(),
            'home' => get_home_url(),
            'admin_email' => get_option('admin_email'),
            'language' => get_locale(),
            'timezone' => wp_timezone_string(),
            'wp_version' => $wp_version,
            'plugin_version' => FORGE_CONNECTOR_VERSION,
            'multisite' => is_multisite(),
        );
    }

    /**
     * Fetch all categories
     */
    private function fetch_all_categories() {
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        return array_map(function($cat) {
            return array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'description' => $cat->description,
                'parent' => $cat->parent,
                'count' => $cat->count,
            );
        }, $categories);
    }

    /**
     * Fetch all tags
     */
    private function fetch_all_tags() {
        $tags = get_tags(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        if (is_wp_error($tags)) {
            return array();
        }

        return array_map(function($tag) {
            return array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'count' => $tag->count,
            );
        }, $tags);
    }

    /**
     * Fetch all users who can edit posts
     */
    private function fetch_all_users() {
        $users = get_users(array(
            'capability' => 'edit_posts',
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));

        return array_map(function($user) {
            return array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'avatar' => get_avatar_url($user->ID, array('size' => 96)),
                'roles' => $user->roles,
            );
        }, $users);
    }

    /**
     * Fetch available post types with post counts
     * Field names match frontend expectations: name (display), slug (machine name)
     */
    private function fetch_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $result = array();

        foreach ($post_types as $post_type) {
            if ($post_type->name === 'attachment') {
                continue; // Skip media
            }

            // Get post count for this post type
            $count_obj = wp_count_posts($post_type->name);
            $count = isset($count_obj->publish) ? intval($count_obj->publish) : 0;

            $result[] = array(
                'slug' => $post_type->name,           // Machine name (e.g., 'post')
                'name' => $post_type->label,          // Display name (e.g., 'Posts')
                'singular_name' => $post_type->labels->singular_name,
                'description' => $post_type->description,
                'public' => $post_type->public,       // Frontend filters by this
                'hierarchical' => $post_type->hierarchical,
                'has_archive' => $post_type->has_archive,
                'supports' => array_keys(get_all_post_type_supports($post_type->name)),
                'taxonomies' => get_object_taxonomies($post_type->name),
                'count' => $count,
            );
        }

        return $result;
    }

    /**
     * Fetch post statuses
     */
    private function fetch_post_statuses() {
        $statuses = get_post_stati(array('internal' => false), 'objects');
        $result = array();

        foreach ($statuses as $status) {
            $result[] = array(
                'name' => $status->name,
                'label' => $status->label,
                'public' => $status->public,
                'protected' => $status->protected,
                'private' => $status->private,
            );
        }

        return $result;
    }

    /**
     * Get media count
     */
    private function get_media_count() {
        $count = wp_count_attachments();
        $total = 0;

        foreach ($count as $type => $num) {
            if ($type !== 'trash') {
                $total += $num;
            }
        }

        return $total;
    }
}
