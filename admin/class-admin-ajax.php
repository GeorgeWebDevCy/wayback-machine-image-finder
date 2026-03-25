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
            $args['dry_run'] = (bool) $_POST['dry_run'];
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

        set_transient('wir_last_scan_id', $scanner->get_scan_id(), HOUR_IN_SECONDS);
        update_option('wir_last_scan_id', $scanner->get_scan_id());

        wp_send_json_success([
            'scan_id' => $results['scan_id'],
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

    public function handle_restore(): void
    {
        check_ajax_referer('wir_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wayback-image-restorer')]);
            return;
        }

        $image_url = esc_url_raw($_POST['image_url'] ?? '');
        $archive_url = isset($_POST['archive_url']) ? esc_url_raw($_POST['archive_url']) : null;
        $dry_run = (bool) ($_POST['dry_run'] ?? false);

        if (empty($image_url)) {
            wp_send_json_error(['message' => __('No image URL provided', 'wayback-image-restorer')]);
            return;
        }

        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        $result = $restorer->restore([
            'url' => $image_url,
            'archive_url' => $archive_url,
            'dry_run' => $dry_run,
            'referenced_in' => isset($_POST['referenced_in']) ? json_decode(stripslashes($_POST['referenced_in']), true) : [],
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
        
        $dry_run = (bool) ($_POST['dry_run'] ?? false);

        if (empty($image_ids)) {
            wp_send_json_error(['message' => __('No images selected', 'wayback-image-restorer')]);
            return;
        }

        $restorer = new \Wayback_Image_Restorer\Image_Restorer();
        $result = $restorer->bulk_restore($image_ids, $dry_run);

        wp_send_json_success($result);
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
            'per_page' => 50,
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
}
