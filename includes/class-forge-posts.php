<?php
/**
 * Forge Posts Handler
 *
 * Handles all post-related API operations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_Posts {

    /**
     * Register post-related routes
     */
    public function register_routes($namespace) {
        // List posts
        register_rest_route($namespace, '/posts', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_posts'),
            'permission_callback' => array('Forge_API', 'check_auth'),
            'args' => array(
                'post_type' => array(
                    'default' => 'post',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'status' => array(
                    'default' => 'any',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'per_page' => array(
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'search' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'orderby' => array(
                    'default' => 'date',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'order' => array(
                    'default' => 'DESC',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Create post
        register_rest_route($namespace, '/posts', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_post'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Get single post
        register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_post'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Update post
        register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array($this, 'update_post'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Delete post
        register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => array($this, 'delete_post'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Verify post exists
        register_rest_route($namespace, '/posts/(?P<id>\d+)/verify', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'verify_post'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));
    }

    /**
     * Get list of posts
     */
    public function get_posts($request) {
        $args = array(
            'post_type' => $this->normalize_post_type($request->get_param('post_type')),
            'post_status' => $request->get_param('status'),
            'posts_per_page' => min($request->get_param('per_page'), 100),
            'paged' => $request->get_param('page'),
            'orderby' => $request->get_param('orderby'),
            'order' => $request->get_param('order'),
        );

        $search = $request->get_param('search');
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $posts = array();

        foreach ($query->posts as $post) {
            $posts[] = $this->format_post($post);
        }

        return rest_ensure_response(array(
            'success' => true,
            'posts' => $posts,
            'total' => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ));
    }

    /**
     * Get single post
     */
    public function get_post($request) {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Post not found.', 'forge-connector'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'post' => $this->format_post($post, true),
        ));
    }

    /**
     * Create a new post
     */
    public function create_post($request) {
        $params = $request->get_json_params();

        // Normalize post_type: REST API uses rest_base ('posts', 'pages')
        // but wp_insert_post() expects the slug ('post', 'page')
        $post_type = $this->normalize_post_type(sanitize_text_field($params['post_type'] ?? 'post'));

        // Build post data
        $post_data = array(
            'post_title' => sanitize_text_field($params['title'] ?? ''),
            'post_content' => wp_kses_post($params['content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($params['excerpt'] ?? ''),
            'post_status' => sanitize_text_field($params['status'] ?? 'draft'),
            'post_type' => $post_type,
            'post_author' => absint($params['author'] ?? get_current_user_id()),
        );

        // Handle slug
        if (!empty($params['slug'])) {
            $post_data['post_name'] = sanitize_title($params['slug']);
        }

        // Handle scheduled posts
        if ($post_data['post_status'] === 'future' && !empty($params['date'])) {
            $post_data['post_date'] = sanitize_text_field($params['date']);
            $post_data['post_date_gmt'] = get_gmt_from_date($params['date']);
        }

        // Validate required fields
        if (empty($post_data['post_title'])) {
            return new WP_Error(
                'missing_title',
                __('Post title is required.', 'forge-connector'),
                array('status' => 400)
            );
        }

        // Validate post type exists and is public
        $post_type_obj = get_post_type_object($post_data['post_type']);
        if (!$post_type_obj || !$post_type_obj->public) {
            return new WP_Error(
                'invalid_post_type',
                sprintf(__('Post type "%s" does not exist or is not public.', 'forge-connector'), $post_data['post_type']),
                array('status' => 400)
            );
        }

        // Insert the post
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Handle categories
        if (!empty($params['categories']) && is_array($params['categories'])) {
            wp_set_post_categories($post_id, array_map('absint', $params['categories']));
        }

        // Handle tags
        if (!empty($params['tags']) && is_array($params['tags'])) {
            wp_set_post_tags($post_id, array_map('absint', $params['tags']));
        }

        // Handle featured image
        if (!empty($params['featured_media'])) {
            set_post_thumbnail($post_id, absint($params['featured_media']));
        }

        // Handle meta fields (for SEO plugins like Yoast, RankMath)
        if (!empty($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
            }
        }

        // Get the created post
        $post = get_post($post_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Post created successfully.', 'forge-connector'),
            'post' => $this->format_post($post, true),
        ));
    }

    /**
     * Update an existing post
     */
    public function update_post($request) {
        $post_id = $request->get_param('id');
        $params = $request->get_json_params();

        // Check if post exists
        $existing_post = get_post($post_id);
        if (!$existing_post) {
            return new WP_Error(
                'not_found',
                __('Post not found.', 'forge-connector'),
                array('status' => 404)
            );
        }

        // Build update data
        $post_data = array(
            'ID' => $post_id,
        );

        // Only update fields that are provided
        if (isset($params['title'])) {
            $post_data['post_title'] = sanitize_text_field($params['title']);
        }

        if (isset($params['content'])) {
            $post_data['post_content'] = wp_kses_post($params['content']);
        }

        if (isset($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }

        if (isset($params['status'])) {
            $post_data['post_status'] = sanitize_text_field($params['status']);
        }

        if (isset($params['slug'])) {
            $post_data['post_name'] = sanitize_title($params['slug']);
        }

        if (isset($params['author'])) {
            $post_data['post_author'] = absint($params['author']);
        }

        // Handle scheduled posts
        if (!empty($params['status']) && $params['status'] === 'future' && !empty($params['date'])) {
            $post_data['post_date'] = sanitize_text_field($params['date']);
            $post_data['post_date_gmt'] = get_gmt_from_date($params['date']);
        }

        // Update the post
        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        // Handle categories
        if (isset($params['categories']) && is_array($params['categories'])) {
            wp_set_post_categories($post_id, array_map('absint', $params['categories']));
        }

        // Handle tags
        if (isset($params['tags']) && is_array($params['tags'])) {
            wp_set_post_tags($post_id, array_map('absint', $params['tags']));
        }

        // Handle featured image
        if (isset($params['featured_media'])) {
            if (empty($params['featured_media'])) {
                delete_post_thumbnail($post_id);
            } else {
                set_post_thumbnail($post_id, absint($params['featured_media']));
            }
        }

        // Handle meta fields
        if (!empty($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
            }
        }

        // Get the updated post
        $post = get_post($post_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Post updated successfully.', 'forge-connector'),
            'post' => $this->format_post($post, true),
        ));
    }

    /**
     * Delete a post
     */
    public function delete_post($request) {
        $post_id = $request->get_param('id');
        $params = $request->get_json_params();
        $force = !empty($params['force']);

        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Post not found.', 'forge-connector'),
                array('status' => 404)
            );
        }

        // Delete the post
        $result = wp_delete_post($post_id, $force);

        if (!$result) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete post.', 'forge-connector'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => $force
                ? __('Post permanently deleted.', 'forge-connector')
                : __('Post moved to trash.', 'forge-connector'),
        ));
    }

    /**
     * Verify a post exists
     */
    public function verify_post($request) {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return rest_ensure_response(array(
                'success' => true,
                'exists' => false,
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'exists' => true,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'url' => get_permalink($post_id),
            'modified' => $post->post_modified_gmt,
        ));
    }

    /**
     * Format a post for API response
     */
    private function format_post($post, $include_content = false) {
        $data = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'url' => get_permalink($post->ID),
            'author' => array(
                'id' => $post->post_author,
                'name' => get_the_author_meta('display_name', $post->post_author),
            ),
            'date' => $post->post_date,
            'date_gmt' => $post->post_date_gmt,
            'modified' => $post->post_modified,
            'modified_gmt' => $post->post_modified_gmt,
        );

        // Include full content when requested
        if ($include_content) {
            $data['content'] = $post->post_content;
            $data['excerpt'] = $post->post_excerpt;
        }

        // Categories
        $categories = wp_get_post_categories($post->ID, array('fields' => 'all'));
        $data['categories'] = array_map(function($cat) {
            return array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
            );
        }, $categories);

        // Tags
        $tags = wp_get_post_tags($post->ID, array('fields' => 'all'));
        $data['tags'] = array_map(function($tag) {
            return array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            );
        }, $tags ?: array());

        // Featured image
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $data['featured_media'] = array(
                'id' => $thumbnail_id,
                'url' => wp_get_attachment_url($thumbnail_id),
                'sizes' => $this->get_attachment_sizes($thumbnail_id),
            );
        } else {
            $data['featured_media'] = null;
        }

        // SEO meta (Yoast, RankMath, etc.)
        $data['meta'] = array(
            '_yoast_wpseo_title' => get_post_meta($post->ID, '_yoast_wpseo_title', true),
            '_yoast_wpseo_metadesc' => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true),
            '_yoast_wpseo_focuskw' => get_post_meta($post->ID, '_yoast_wpseo_focuskw', true),
            'rank_math_title' => get_post_meta($post->ID, 'rank_math_title', true),
            'rank_math_description' => get_post_meta($post->ID, 'rank_math_description', true),
            'rank_math_focus_keyword' => get_post_meta($post->ID, 'rank_math_focus_keyword', true),
        );

        return $data;
    }

    /**
     * Get all available sizes for an attachment
     */
    private function get_attachment_sizes($attachment_id) {
        $sizes = array();
        $image_sizes = get_intermediate_image_sizes();

        foreach ($image_sizes as $size) {
            $image = wp_get_attachment_image_src($attachment_id, $size);
            if ($image) {
                $sizes[$size] = array(
                    'url' => $image[0],
                    'width' => $image[1],
                    'height' => $image[2],
                );
            }
        }

        return $sizes;
    }

    /**
     * Normalize REST API base to WordPress post_type slug.
     *
     * REST API uses rest_base ('posts', 'pages') in URLs,
     * but wp_insert_post() and WP_Query expect the slug ('post', 'page').
     * Custom post types typically have matching rest_base and slug.
     */
    private function normalize_post_type($post_type) {
        $rest_base_map = array('posts' => 'post', 'pages' => 'page');
        return isset($rest_base_map[$post_type]) ? $rest_base_map[$post_type] : $post_type;
    }
}
