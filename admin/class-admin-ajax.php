<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class Ajax
{
    private \Wayback_Image_Restorer\Logger $logger;
    private \Wayback_Image_Restorer\State_Store $state_store;
    private \Wayback_Image_Restorer\Operation_Lock $operation_lock;

    public function __construct()
    {
        $this->logger = \Wayback_Image_Restorer\Logger::get_instance();
        $this->state_store = new \Wayback_Image_Restorer\State_Store();
        $this->operation_lock = new \Wayback_Image_Restorer\Operation_Lock();
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

        $lock_error = $this->acquire_lock_error('scan', 30 * MINUTE_IN_SECONDS);
        if ($lock_error !== null) {
            wp_send_json_error($lock_error);
            return;
        }

        $scanner = new \Wayback_Image_Restorer\Image_Scanner();
        try {
            $results = $scanner->scan($args);
            $this->state_store->set_last_scan_id($scanner->get_scan_id());
        } finally {
            $this->operation_lock->release('scan');
        }

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
            $scan_id = $this->state_store->get_last_scan_id();
        }

        $results = $this->state_store->get_scan_results($scan_id);

        if (!is_array($results)) {
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
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'post_status' => 'inherit',
            'orderby' => 'ID',
            'order' => 'ASC',
            'offset' => $offset,
        ];

        if (!empty($_POST['date_from'])) {
            $args['date_query'][] = ['after' => sanitize_text_field((string) $_POST['date_from']), 'inclusive' => true];
        }

        if (!empty($_POST['date_to'])) {
            $args['date_query'][] = ['before' => sanitize_text_field((string) $_POST['date_to']), 'inclusive' => true];
        }

        if (count($args['date_query'] ?? []) > 1) {
            $args['date_query']['relation'] = 'AND';
        } elseif (isset($args['date_query'][0])) {
            $args['date_query'] = $args['date_query'][0];
        } else {
            unset($args['date_query']);
        }

        $attachments = get_posts($args);

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
                'target_date' => $target_date,
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
        $scan_id = sanitize_text_field((string) ($_POST['scan_id'] ?? ''));
        $referenced_in = $this->get_referenced_in_payload($image_url, $scan_id);

        if (empty($image_url)) {
            wp_send_json_error(['message' => __('No image URL provided', 'wayback-image-restorer')]);
            return;
        }

        $lock_error = $this->acquire_lock_error('restore', 15 * MINUTE_IN_SECONDS);
        if ($lock_error !== null) {
            wp_send_json_error($lock_error);
            return;
        }

        $image_id = absint($_POST['image_id'] ?? 0);
        $target_date = !empty($_POST['target_date']) ? sanitize_text_field((string) $_POST['target_date']) : null;
        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        try {
            $result = $restorer->restore([
                'id' => $image_id,
                'scan_id' => $scan_id,
                'url' => $image_url,
                'archive_url' => $archive_url,
                'target_date' => $target_date,
                'dry_run' => $dry_run,
                'referenced_in' => $referenced_in,
            ]);
        } finally {
            $this->operation_lock->release('restore');
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function handle_undo_restore(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            wp_send_json_error(['message' => __('No attachment provided', 'wayback-image-restorer')]);
            return;
        }

        $lock_error = $this->acquire_lock_error('restore', 15 * MINUTE_IN_SECONDS);
        if ($lock_error !== null) {
            wp_send_json_error($lock_error);
            return;
        }

        $scan_id = sanitize_text_field((string) ($_POST['scan_id'] ?? ''));
        $image_id = absint($_POST['image_id'] ?? 0);
        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        try {
            $result = $restorer->undo_restore($attachment_id);
        } finally {
            $this->operation_lock->release('restore');
        }

        if (!empty($result['success']) && $scan_id !== '' && $image_id > 0) {
            $this->state_store->clear_restore_result($scan_id, $image_id);
        }

        if ($result['success']) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
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
        $provided_images = $this->get_bulk_images_payload();
        $scan_id = sanitize_text_field((string) ($_POST['scan_id'] ?? ''));
        if (empty($image_ids) && !empty($provided_images)) {
            $image_ids = array_values(array_map(
                static fn(array $image): int => (int) $image['id'],
                $provided_images
            ));
        }

        $dry_run = $this->get_request_bool('dry_run');

        if (empty($image_ids)) {
            wp_send_json_error(['message' => __('No images selected', 'wayback-image-restorer')]);
            return;
        }

        $lock_error = $this->acquire_lock_error('restore', 30 * MINUTE_IN_SECONDS);
        if ($lock_error !== null) {
            wp_send_json_error($lock_error);
            return;
        }

        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        try {
            $result = $restorer->bulk_restore($image_ids, $dry_run, $provided_images, $scan_id);
        } finally {
            $this->operation_lock->release('restore');
        }

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

    private function get_referenced_in_payload(string $image_url, string $preferred_scan_id = ''): array
    {
        if (isset($_POST['referenced_in'])) {
            $decoded = json_decode((string) wp_unslash($_POST['referenced_in']), true);
            if (is_array($decoded) && !empty($decoded)) {
                return $this->sanitize_referenced_in_payload($decoded);
            }
        }

        if ($image_url === '') {
            return [];
        }

        $scan_ids = array_unique(array_filter([
            $preferred_scan_id,
            $this->state_store->get_current_scan_id(),
            $this->state_store->get_last_scan_id(),
        ]));

        foreach ($scan_ids as $scan_id) {
            $results = $this->state_store->get_scan_results((string) $scan_id);
            if (!is_array($results) || empty($results['broken_images']) || !is_array($results['broken_images'])) {
                continue;
            }

            foreach ($results['broken_images'] as $image) {
                if (($image['url'] ?? '') !== $image_url) {
                    continue;
                }

                $referenced_in = $image['referenced_in'] ?? [];
                return is_array($referenced_in) ? $this->sanitize_referenced_in_payload($referenced_in) : [];
            }
        }

        return [];
    }

    public function handle_merge_browser_results(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $scan_id = sanitize_text_field((string) ($_POST['scan_id'] ?? ''));
        $decoded = json_decode((string) wp_unslash($_POST['broken_images'] ?? ''), true);

        if ($scan_id === '' || !is_array($decoded)) {
            wp_send_json_error(['message' => __('No scan data provided', 'wayback-image-restorer')]);
            return;
        }

        $images = [];
        foreach ($decoded as $image) {
            $sanitized = $this->sanitize_bulk_image_payload($image);
            if ($sanitized === null) {
                continue;
            }

            $sanitized['target_date'] = !empty($image['target_date'])
                ? sanitize_text_field((string) $image['target_date'])
                : null;
            $sanitized['archive_found'] = !empty($image['archive_found']);
            $sanitized['archive_url'] = isset($image['archive_url']) ? esc_url_raw((string) $image['archive_url']) : null;
            $sanitized['archive_timestamp'] = !empty($image['archive_timestamp'])
                ? sanitize_text_field((string) $image['archive_timestamp'])
                : null;
            $sanitized['type'] = sanitize_text_field((string) ($image['type'] ?? 'local'));
            $sanitized['last_checked'] = sanitize_text_field((string) ($image['last_checked'] ?? current_time('c')));
            $images[] = $sanitized;
        }

        $results = $this->state_store->merge_browser_verified_images($scan_id, $images);
        if (!is_array($results)) {
            wp_send_json_error(['message' => __('Scan results expired or not found', 'wayback-image-restorer')]);
            return;
        }

        wp_send_json_success($results);
    }

    public function handle_lookup_archive(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $image_url = esc_url_raw((string) ($_POST['image_url'] ?? ''));
        $target_date = !empty($_POST['target_date']) ? sanitize_text_field((string) $_POST['target_date']) : null;
        $scan_id = sanitize_text_field((string) ($_POST['scan_id'] ?? ''));
        $image_id = absint($_POST['image_id'] ?? 0);

        if ($image_url === '') {
            wp_send_json_error(['message' => __('No image URL provided', 'wayback-image-restorer')]);
            return;
        }

        $api = new \Wayback_Image_Restorer\Wayback_Api();
        $archive_info = $api->find_archive($image_url, $target_date, $target_date !== null);

        if ($scan_id !== '' && $image_id > 0) {
            $this->state_store->update_scan_image($scan_id, $image_id, static function (array $image) use ($archive_info, $target_date): array {
                $image['target_date'] = $target_date;
                $image['archive_found'] = $archive_info !== null;
                $image['archive_url'] = $archive_info['archive_url'] ?? null;
                $image['archive_timestamp'] = $archive_info['timestamp'] ?? null;
                $image['last_checked'] = current_time('c');
                return $image;
            });
        }

        wp_send_json_success([
            'archive_found' => $archive_info !== null,
            'archive_url' => $archive_info['archive_url'] ?? null,
            'archive_timestamp' => $archive_info['timestamp'] ?? null,
            'target_date' => $target_date,
        ]);
    }

    private function get_bulk_images_payload(): array
    {
        if (!isset($_POST['images'])) {
            return [];
        }

        $decoded = json_decode((string) wp_unslash($_POST['images']), true);
        if (!is_array($decoded)) {
            return [];
        }

        $images = [];
        foreach ($decoded as $image) {
            $sanitized = $this->sanitize_bulk_image_payload($image);
            if ($sanitized !== null) {
                $images[] = $sanitized;
            }
        }

        return $images;
    }

    private function sanitize_bulk_image_payload($image): ?array
    {
        if (!is_array($image)) {
            return null;
        }

        $id = absint($image['id'] ?? 0);
        $url = esc_url_raw((string) ($image['url'] ?? ''));
        if ($id <= 0 || $url === '') {
            return null;
        }

        $archive_url = isset($image['archive_url']) ? esc_url_raw((string) $image['archive_url']) : null;
        $referenced_in = $this->sanitize_referenced_in_payload(
            isset($image['referenced_in']) && is_array($image['referenced_in']) ? $image['referenced_in'] : []
        );

        return [
            'id' => $id,
            'url' => $url,
            'archive_url' => $archive_url ?: null,
            'referenced_in' => $referenced_in,
            'target_date' => !empty($image['target_date']) ? sanitize_text_field((string) $image['target_date']) : null,
        ];
    }

    private function sanitize_referenced_in_payload(array $references): array
    {
        $sanitized = [];

        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }

            $sanitized[] = [
                'post_id' => absint($reference['post_id'] ?? 0),
                'post_title' => sanitize_text_field((string) ($reference['post_title'] ?? '')),
                'context' => sanitize_text_field((string) ($reference['context'] ?? '')),
            ];
        }

        return $sanitized;
    }

    private function acquire_lock_error(string $operation, int $ttl): ?array
    {
        if ($this->operation_lock->acquire($operation, $ttl, ['screen' => 'admin_ajax'])) {
            return null;
        }

        $lock = $this->operation_lock->get_active_lock($operation);
        $started_at = is_array($lock) ? (string) ($lock['started_at'] ?? '') : '';

        return [
            'message' => $started_at !== ''
                ? sprintf(__('Another %s operation started at %s is still running. Please wait and try again.', 'wayback-image-restorer'), $operation, $started_at)
                : sprintf(__('Another %s operation is already running. Please wait and try again.', 'wayback-image-restorer'), $operation),
            'operation' => $operation,
            'lock' => $lock,
        ];
    }
}
