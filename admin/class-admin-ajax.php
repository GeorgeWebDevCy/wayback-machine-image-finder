<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class Ajax
{
    private \Wayback_Image_Restorer\Logger $logger;

    public function __construct()
    {
        $this->logger = \Wayback_Image_Restorer\Logger::get_instance();
    }

    public function handle_scan(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $args = [];
        
        if (isset($_POST['dry_run'])) {
            $args['dry_run'] = $this->get_request_bool('dry_run');
        }
        
        if (isset($_POST['post_types']) && is_array($_POST['post_types'])) {
            $args['post_types'] = array_map('sanitize_text_field', $_POST['post_types']);
        }
        
        if (!empty($_POST['date_from'])) {
            $args['date_from'] = sanitize_text_field($_POST['date_from']);
        }
        
        if (!empty($_POST['date_to'])) {
            $args['date_to'] = sanitize_text_field($_POST['date_to']);
        }

        $scanner = new \Wayback_Image_Restorer\Image_Scanner();
        $results = $scanner->scan($args);

        set_transient('wir_current_scan_id', $scanner->get_scan_id(), HOUR_IN_SECONDS);
        set_transient('wir_last_scan_id', $scanner->get_scan_id(), HOUR_IN_SECONDS);
        update_option('wir_last_scan_id', $scanner->get_scan_id());

        wp_send_json_success([
            'scan_id' => $results['scan_id'],
            'started_at' => $results['started_at'],
            'completed_at' => $results['completed_at'],
            'duration_seconds' => $results['duration_seconds'],
            'stats' => $results['stats'],
            'broken_images' => $results['broken_images'],
        ]);
    }

    public function handle_get_results(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $scan_id = sanitize_text_field($_POST['scan_id'] ?? '');
        
        if (empty($scan_id)) {
            $scan_id = get_option('wir_last_scan_id', '');
        }

        $results = get_transient('wir_last_scan_' . $scan_id);

        if ($results === false) {
            wp_send_json_error(['message' => __('Scan results expired or not found', 'wayback-image-restorer')]);
            return;
        }

        wp_send_json_success($results);
    }

    public function handle_get_media_candidates(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
        $limit = isset($_POST['limit']) ? max(1, min(100, absint($_POST['limit']))) : 40;

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'post_status' => 'inherit',
            'orderby' => 'ID',
            'order' => 'ASC',
            'offset' => $offset,
        ]);

        $items = [];
        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            if (!$url) {
                continue;
            }

            $items[] = [
                'attachment_id' => (int) $attachment->ID,
                'url' => $url,
                'post_title' => get_the_title($attachment->ID),
                'context' => 'media_library',
                'target_date' => $attachment->post_date,
            ];
        }

        wp_send_json_success([
            'items' => $items,
            'next_offset' => $offset + count($attachments),
            'has_more' => count($attachments) === $limit,
        ]);
    }

    public function handle_enrich_media_failures(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $decoded = json_decode((string) wp_unslash($_POST['items'] ?? ''), true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => __('No media items provided', 'wayback-image-restorer')]);
            return;
        }

        $api = new \Wayback_Image_Restorer\Wayback_Api();
        $broken_images = [];
        $seen = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = esc_url_raw((string) ($item['url'] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $attachment_id = absint($item['attachment_id'] ?? 0);
            $post_title = sanitize_text_field((string) ($item['post_title'] ?? ''));
            $context = sanitize_text_field((string) ($item['context'] ?? 'media_library'));
            $target_date = !empty($item['target_date']) ? sanitize_text_field((string) $item['target_date']) : null;

            $archive_info = $api->find_archive($url, $target_date);
            $broken_images[] = [
                'id' => count($broken_images) + 1,
                'url' => $url,
                'type' => 'local',
                'referenced_in' => [[
                    'post_id' => $attachment_id,
                    'post_title' => $post_title,
                    'context' => $context,
                ]],
                'archive_found' => $archive_info !== null,
                'archive_url' => $archive_info['archive_url'] ?? null,
                'archive_timestamp' => $archive_info['timestamp'] ?? null,
                'last_checked' => current_time('c'),
            ];
            $seen[$url] = true;
        }

        wp_send_json_success([
            'broken_images' => $broken_images,
        ]);
    }

    public function handle_restore(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $image_url = esc_url_raw($_POST['image_url'] ?? '');
        $archive_url = isset($_POST['archive_url']) ? esc_url_raw($_POST['archive_url']) : null;
        $dry_run = $this->get_request_bool('dry_run');
        $referenced_in = $this->get_referenced_in_payload($image_url);

        if (empty($image_url)) {
            wp_send_json_error(['message' => __('No image URL provided', 'wayback-image-restorer')]);
            return;
        }

        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        $result = $restorer->restore([
            'url' => $image_url,
            'archive_url' => $archive_url,
            'dry_run' => $dry_run,
            'referenced_in' => $referenced_in,
        ]);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function handle_bulk_restore(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $image_ids = isset($_POST['image_ids']) && is_array($_POST['image_ids'])
            ? array_map('absint', $_POST['image_ids'])
            : [];
        
        $dry_run = $this->get_request_bool('dry_run');

        if (empty($image_ids)) {
            wp_send_json_error(['message' => __('No images selected', 'wayback-image-restorer')]);
            return;
        }

        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        $result = $restorer->bulk_restore($image_ids, $dry_run);

        if ($result['success']) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
    }

    public function handle_get_logs(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $args = [
            'page' => isset($_POST['page']) ? absint($_POST['page']) : 1,
            'per_page' => isset($_POST['per_page']) ? max(1, min(100, absint($_POST['per_page']))) : 50,
            'level' => sanitize_text_field($_POST['level'] ?? 'all'),
            'action' => sanitize_text_field($_POST['action'] ?? 'all'),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
        ];

        $logger = \Wayback_Image_Restorer\Logger::get_instance();
        $result = $logger->get_logs($args);

        wp_send_json_success($result);
    }

    public function handle_clear_logs(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $logger = \Wayback_Image_Restorer\Logger::get_instance();
        $count = $logger->clear_logs();

        $this->logger->info('logs_cleared', ['files_deleted' => $count]);

        wp_send_json_success([
            'message' => sprintf(_n('%d log file deleted', '%d log files deleted', $count, 'wayback-image-restorer'), $count),
            'deleted_files' => $count,
        ]);
    }

    public function handle_rotate_logs(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $logger = \Wayback_Image_Restorer\Logger::get_instance();
        $result = $logger->rotate_logs();
        
        $this->logger->info('logs_rotated', $result);

        wp_send_json_success([
            'message' => __('Logs rotated successfully', 'wayback-image-restorer'),
            'archived' => $result['archived'],
            'deleted' => $result['deleted'],
        ]);
    }

    public function handle_download_logs(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'wayback-image-restorer'));
        }

        $logger = \Wayback_Image_Restorer\Logger::get_instance();
        $csv = $logger->export_to_csv();

        $filename = 'wayback-image-restorer-logs-' . date('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));

        echo $csv;
        wp_die();
    }

    private function get_request_bool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $_POST)) {
            return $default;
        }

        $value = wp_unslash($_POST[$key]);
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private function get_referenced_in_payload(string $image_url): array
    {
        if (isset($_POST['referenced_in'])) {
            $decoded = json_decode((string) wp_unslash($_POST['referenced_in']), true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        if ($image_url === '') {
            return [];
        }

        $scan_ids = array_unique(array_filter([
            (string) get_transient('wir_current_scan_id'),
            (string) get_option('wir_last_scan_id', ''),
        ]));

        foreach ($scan_ids as $scan_id) {
            $results = get_transient('wir_last_scan_' . $scan_id);
            if (!is_array($results) || empty($results['broken_images']) || !is_array($results['broken_images'])) {
                continue;
            }

            foreach ($results['broken_images'] as $image) {
                if (($image['url'] ?? '') !== $image_url) {
                    continue;
                }

                $referenced_in = $image['referenced_in'] ?? [];
                return is_array($referenced_in) ? $referenced_in : [];
            }
        }

        return [];
    }
}
