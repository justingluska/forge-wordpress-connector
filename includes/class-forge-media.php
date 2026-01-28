<?php
/**
 * Forge Media Handler
 *
 * Handles all media-related API operations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forge_Media {

    /**
     * Register media-related routes
     */
    public function register_routes($namespace) {
        // List media
        register_rest_route($namespace, '/media', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_media'),
            'permission_callback' => array('Forge_API', 'check_auth'),
            'args' => array(
                'per_page' => array(
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'media_type' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'search' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Upload media
        register_rest_route($namespace, '/media', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'upload_media'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Get single media item
        register_rest_route($namespace, '/media/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_media_item'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Update media
        register_rest_route($namespace, '/media/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array($this, 'update_media'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Delete media
        register_rest_route($namespace, '/media/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => array($this, 'delete_media'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));

        // Upload from URL
        register_rest_route($namespace, '/media/upload-from-url', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'upload_from_url'),
            'permission_callback' => array('Forge_API', 'check_auth'),
        ));
    }

    /**
     * Get media library items
     */
    public function get_media($request) {
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => min($request->get_param('per_page'), 100),
            'paged' => $request->get_param('page'),
            'orderby' => 'date',
            'order' => 'DESC',
        );

        $media_type = $request->get_param('media_type');
        if (!empty($media_type)) {
            $args['post_mime_type'] = $media_type;
        }

        $search = $request->get_param('search');
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $media = array();

        foreach ($query->posts as $item) {
            $media[] = $this->format_media($item);
        }

        return rest_ensure_response(array(
            'success' => true,
            'media' => $media,
            'total' => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ));
    }

    /**
     * Get single media item
     */
    public function get_media_item($request) {
        $media_id = $request->get_param('id');
        $media = get_post($media_id);

        if (!$media || $media->post_type !== 'attachment') {
            return new WP_Error(
                'not_found',
                __('Media not found.', 'forge-connector'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'media' => $this->format_media($media, true),
        ));
    }

    /**
     * Upload media from file data (base64)
     */
    public function upload_media($request) {
        $params = $request->get_json_params();

        // Check for file data
        if (empty($params['file_data']) && empty($params['file_url'])) {
            return new WP_Error(
                'missing_file',
                __('File data or URL is required.', 'forge-connector'),
                array('status' => 400)
            );
        }

        // If URL provided, redirect to upload_from_url
        if (!empty($params['file_url'])) {
            return $this->upload_from_url($request);
        }

        $file_data = base64_decode($params['file_data']);
        if ($file_data === false) {
            return new WP_Error(
                'invalid_file',
                __('Invalid base64 file data.', 'forge-connector'),
                array('status' => 400)
            );
        }

        $filename = sanitize_file_name($params['filename'] ?? 'upload-' . time());

        // Get WordPress upload directory
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error']) {
            return new WP_Error(
                'upload_error',
                $upload_dir['error'],
                array('status' => 500)
            );
        }

        // Create temporary file
        $temp_file = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);

        // Write file data
        if (file_put_contents($temp_file, $file_data) === false) {
            return new WP_Error(
                'write_error',
                __('Failed to write file.', 'forge-connector'),
                array('status' => 500)
            );
        }

        // Get file type
        $file_type = wp_check_filetype($temp_file, null);

        // Prepare attachment data
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_text_field($params['title'] ?? preg_replace('/\.[^.]+$/', '', $filename)),
            'post_content' => sanitize_textarea_field($params['description'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($params['caption'] ?? ''),
            'post_status' => 'inherit',
        );

        // Insert the attachment
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = wp_insert_attachment($attachment, $temp_file);

        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return $attachment_id;
        }

        // Generate metadata
        $metadata = wp_generate_attachment_metadata($attachment_id, $temp_file);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Set alt text if provided
        if (!empty($params['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt_text']));
        }

        $media = get_post($attachment_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Media uploaded successfully.', 'forge-connector'),
            'media' => $this->format_media($media, true),
        ));
    }

    /**
     * Upload media from URL
     */
    public function upload_from_url($request) {
        $params = $request->get_json_params();
        $url = esc_url_raw($params['file_url'] ?? $params['url'] ?? '');

        if (empty($url)) {
            return new WP_Error(
                'missing_url',
                __('URL is required.', 'forge-connector'),
                array('status' => 400)
            );
        }

        // Download the file
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $temp_file = download_url($url, 60);

        if (is_wp_error($temp_file)) {
            return new WP_Error(
                'download_error',
                sprintf(__('Failed to download file: %s', 'forge-connector'), $temp_file->get_error_message()),
                array('status' => 500)
            );
        }

        // Get filename from URL or use provided
        $filename = sanitize_file_name($params['filename'] ?? basename(parse_url($url, PHP_URL_PATH)));
        if (empty($filename) || $filename === '/') {
            $filename = 'upload-' . time() . '.jpg';
        }

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file,
        );

        // Upload the file
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return $attachment_id;
        }

        // Update attachment details if provided
        $update_data = array('ID' => $attachment_id);

        if (!empty($params['title'])) {
            $update_data['post_title'] = sanitize_text_field($params['title']);
        }

        if (!empty($params['description'])) {
            $update_data['post_content'] = sanitize_textarea_field($params['description']);
        }

        if (!empty($params['caption'])) {
            $update_data['post_excerpt'] = sanitize_textarea_field($params['caption']);
        }

        if (count($update_data) > 1) {
            wp_update_post($update_data);
        }

        // Set alt text if provided
        if (!empty($params['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt_text']));
        }

        $media = get_post($attachment_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Media uploaded successfully.', 'forge-connector'),
            'media' => $this->format_media($media, true),
        ));
    }

    /**
     * Update media details
     */
    public function update_media($request) {
        $media_id = $request->get_param('id');
        $params = $request->get_json_params();

        $media = get_post($media_id);
        if (!$media || $media->post_type !== 'attachment') {
            return new WP_Error(
                'not_found',
                __('Media not found.', 'forge-connector'),
                array('status' => 404)
            );
        }

        $update_data = array('ID' => $media_id);

        if (isset($params['title'])) {
            $update_data['post_title'] = sanitize_text_field($params['title']);
        }

        if (isset($params['description'])) {
            $update_data['post_content'] = sanitize_textarea_field($params['description']);
        }

        if (isset($params['caption'])) {
            $update_data['post_excerpt'] = sanitize_textarea_field($params['caption']);
        }

        if (count($update_data) > 1) {
            $result = wp_update_post($update_data, true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (isset($params['alt_text'])) {
            update_post_meta($media_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt_text']));
        }

        $media = get_post($media_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Media updated successfully.', 'forge-connector'),
            'media' => $this->format_media($media, true),
        ));
    }

    /**
     * Delete media
     */
    public function delete_media($request) {
        $media_id = $request->get_param('id');
        $params = $request->get_json_params();
        $force = !empty($params['force']);

        $media = get_post($media_id);
        if (!$media || $media->post_type !== 'attachment') {
            return new WP_Error(
                'not_found',
                __('Media not found.', 'forge-connector'),
                array('status' => 404)
            );
        }

        $result = wp_delete_attachment($media_id, $force);

        if (!$result) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete media.', 'forge-connector'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Media deleted successfully.', 'forge-connector'),
        ));
    }

    /**
     * Format media for API response
     */
    private function format_media($media, $include_sizes = false) {
        $data = array(
            'id' => $media->ID,
            'title' => $media->post_title,
            'filename' => basename(get_attached_file($media->ID)),
            'url' => wp_get_attachment_url($media->ID),
            'mime_type' => $media->post_mime_type,
            'date' => $media->post_date,
            'modified' => $media->post_modified,
            'alt_text' => get_post_meta($media->ID, '_wp_attachment_image_alt', true),
            'caption' => $media->post_excerpt,
            'description' => $media->post_content,
        );

        // Get file size
        $file_path = get_attached_file($media->ID);
        if (file_exists($file_path)) {
            $data['file_size'] = filesize($file_path);
        }

        // Get dimensions for images
        if (strpos($media->post_mime_type, 'image/') === 0) {
            $metadata = wp_get_attachment_metadata($media->ID);
            if ($metadata) {
                $data['width'] = $metadata['width'] ?? null;
                $data['height'] = $metadata['height'] ?? null;

                if ($include_sizes && !empty($metadata['sizes'])) {
                    $upload_dir = wp_upload_dir();
                    $base_url = trailingslashit($upload_dir['baseurl']) . dirname($metadata['file']) . '/';

                    $data['sizes'] = array();
                    foreach ($metadata['sizes'] as $size_name => $size_data) {
                        $data['sizes'][$size_name] = array(
                            'url' => $base_url . $size_data['file'],
                            'width' => $size_data['width'],
                            'height' => $size_data['height'],
                            'mime_type' => $size_data['mime-type'],
                        );
                    }
                }
            }
        }

        return $data;
    }
}
