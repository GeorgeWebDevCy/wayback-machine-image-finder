<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Image_Restorer
{
    private Wayback_Api $api;
    private Logger $logger;
    private Resource_Manager $resources;

    public function __construct(?Wayback_Api $api = null, ?Resource_Manager $resources = null)
    {
        $this->api = $api ?? new Wayback_Api();
        $this->logger = Logger::get_instance();
        $this->resources = $resources ?? new Resource_Manager();

        if (!defined('WIR_START_TIME')) {
            define('WIR_START_TIME', microtime(true));
        }
    }

    public function restore(array $image_data): array
    {
        $image_url = $image_data['url'] ?? '';
        $archive_url = $image_data['archive_url'] ?? null;
        $referenced_in = $image_data['referenced_in'] ?? [];
        $dry_run = $image_data['dry_run'] ?? false;

        if (empty($image_url)) {
            return [
                'success' => false,
                'error' => 'No image URL provided',
            ];
        }

        if ($this->resources->should_stop('restore_start')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
                'resource_limit' => true,
            ];
        }

        $this->logger->info('restore_start', [
            'image_url' => $image_url,
            'archive_url' => $archive_url,
            'dry_run' => $dry_run,
        ]);

        if ($dry_run) {
            $this->logger->info('restore_dry_run', [
                'image_url' => $image_url,
                'would_restore' => true,
            ]);

            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Dry run - would restore image',
                'image_url' => $image_url,
                'archive_url' => $archive_url,
            ];
        }

        $restore_mode = Settings::get('restore_mode', 'archive_then_original');
        $download_result = $this->download_image($image_url, $archive_url, $restore_mode);

        if (!$download_result['success']) {
            $this->logger->error('restore_failed', [
                'image_url' => $image_url,
                'error' => $download_result['error'],
            ]);

            return [
                'success' => false,
                'error' => $download_result['error'],
            ];
        }

        $existing_attachment_id = $this->resolve_existing_attachment_id($image_url, $referenced_in);

        if ($existing_attachment_id > 0) {
            $import_result = $this->restore_existing_attachment(
                $existing_attachment_id,
                $download_result['file'],
                $download_result['mime_type'],
                $image_url
            );
        } else {
            $import_result = $this->import_to_media_library(
                $download_result['file'],
                $download_result['mime_type'],
                $image_url
            );
        }

        if (!$import_result['success']) {
            @unlink($download_result['file']);

            $this->logger->error('restore_failed', [
                'image_url' => $image_url,
                'error' => $import_result['error'],
            ]);

            return [
                'success' => false,
                'error' => $import_result['error'],
            ];
        }

        $new_attachment_id = (int) $import_result['attachment_id'];
        $new_url = $import_result['url'] ?? wp_get_attachment_url($new_attachment_id);

        $update_result = ['updated' => 0, 'failed' => 0];
        if (is_string($new_url) && $new_url !== '' && $new_url !== $image_url) {
            $update_result = $this->update_post_references($image_url, $new_url, $referenced_in, $new_attachment_id);
        }

        @unlink($download_result['file']);

        $this->logger->success('restore_complete', [
            'image_url' => $image_url,
            'new_url' => $new_url,
            'new_attachment_id' => $new_attachment_id,
            'posts_updated' => $update_result['updated'],
            'failed_posts' => $update_result['failed'],
        ]);

        $this->resources->optimize();
        $this->resources->pause();

        return [
            'success' => true,
            'dry_run' => false,
            'new_attachment_id' => $new_attachment_id,
            'new_url' => $new_url,
            'posts_updated' => $update_result['updated'],
            'failed_posts' => $update_result['failed'],
        ];
    }

    private function download_image(string $original_url, ?string $archive_url, string $restore_mode): array
    {
        if ($this->resources->should_stop('download_start')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
            ];
        }

        $sources = $this->get_download_sources($original_url, $archive_url, $restore_mode);

        foreach ($sources as $source) {
            if ($this->resources->should_stop('download_attempt')) {
                return [
                    'success' => false,
                    'error' => 'Resource limit reached during download',
                ];
            }

            $result = $this->api->download_image($source['url']);

            if ($result['success']) {
                $result['source'] = $source['type'];
                return $result;
            }

            $this->logger->warning('download_attempt_failed', [
                'url' => $source['url'],
                'type' => $source['type'],
                'error' => $result['error'] ?? 'Unknown error',
            ]);
        }

        return [
            'success' => false,
            'error' => 'Failed to download image from any source',
        ];
    }

    private function get_download_sources(string $original_url, ?string $archive_url, string $restore_mode): array
    {
        $sources = [];

        switch ($restore_mode) {
            case 'archive_only':
                if ($archive_url) {
                    $sources[] = ['url' => $archive_url, 'type' => 'archive'];
                }
                break;

            case 'original_only':
                $sources[] = ['url' => $original_url, 'type' => 'original'];
                break;

            case 'archive_then_original':
                if ($archive_url) {
                    $sources[] = ['url' => $archive_url, 'type' => 'archive'];
                }
                $sources[] = ['url' => $original_url, 'type' => 'original'];
                break;

            case 'original_then_archive':
                $sources[] = ['url' => $original_url, 'type' => 'original'];
                if ($archive_url) {
                    $sources[] = ['url' => $archive_url, 'type' => 'archive'];
                }
                break;
        }

        return $sources;
    }

    private function import_to_media_library(string $file_path, string $mime_type, string $original_url): array
    {
        if ($this->resources->should_stop('import_start')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
            ];
        }

        $filename = $this->generate_filename($original_url, $mime_type);

        $file_array = [
            'name' => $filename,
            'tmp_name' => $file_path,
        ];

        $post_id = 0;

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            return [
                'success' => false,
                'error' => $attachment_id->get_error_message(),
            ];
        }

        $this->update_attachment_meta($attachment_id, $original_url);

        return [
            'success' => true,
            'attachment_id' => $attachment_id,
        ];
    }

    private function generate_filename(string $url, string $mime_type): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $pathinfo = pathinfo(is_string($path) ? $path : '');
        $original_name = $pathinfo['filename'] ?? 'restored-image';
        $original_ext = strtolower((string) ($pathinfo['extension'] ?? ''));
        $final_ext = $this->get_preferred_extension($original_ext, $mime_type);

        $timestamp = date('Y-m-d-His');
        $unique_id = substr(uniqid(), -6);

        return sanitize_file_name("{$original_name}-restored-{$timestamp}-{$unique_id}.{$final_ext}");
    }

    private function mime_type_to_extension(string $mime_type): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
        ];

        return $map[$mime_type] ?? 'jpg';
    }

    private function update_attachment_meta(int $attachment_id, string $source_url): void
    {
        update_post_meta($attachment_id, '_wir_source_url', $source_url);
        update_post_meta($attachment_id, '_wir_restored_date', current_time('mysql'));
        update_post_meta($attachment_id, '_wir_restored_from', 'wayback_machine');
    }

    private function update_post_references(
        string $old_url,
        string $new_url,
        array $referenced_in,
        int $replacement_attachment_id = 0
    ): array
    {
        $result = [
            'updated' => 0,
            'failed' => 0,
        ];

        $processed_posts = [];

        foreach ($referenced_in as $reference) {
            if ($this->resources->should_stop('update_references')) {
                break;
            }

            $post_id = $reference['post_id'];
            $context = $reference['context'];

            if (in_array($post_id, $processed_posts)) {
                continue;
            }

            if ($context === 'media_library') {
                $update_result = $replacement_attachment_id > 0 && $replacement_attachment_id === $post_id;
            } elseif ($context === 'featured') {
                $update_result = $this->update_featured_image($post_id, $old_url, $new_url, $replacement_attachment_id);
            } else {
                $update_result = $this->update_post_content($post_id, $old_url, $new_url);
            }

            if ($update_result) {
                $result['updated']++;
            } else {
                $result['failed']++;
            }

            $processed_posts[] = $post_id;
        }

        return $result;
    }

    private function update_post_content(int $post_id, string $old_url, string $new_url): bool
    {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $content = $post->post_content;

        if (str_contains($content, $old_url)) {
            $updated_content = str_replace($old_url, $new_url, $content);

            wp_update_post([
                'ID' => $post_id,
                'post_content' => $updated_content,
            ]);

            $this->logger->info('post_content_updated', [
                'post_id' => $post_id,
                'old_url' => $old_url,
                'new_url' => $new_url,
            ]);

            return true;
        }

        return false;
    }

    private function update_featured_image(
        int $post_id,
        string $old_url,
        string $new_url,
        int $replacement_attachment_id = 0
    ): bool
    {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return false;
        }

        if ($replacement_attachment_id > 0 && $thumbnail_id === $replacement_attachment_id) {
            return true;
        }

        $thumbnail_url = wp_get_attachment_url($thumbnail_id);
        if ($thumbnail_url !== $old_url) {
            return false;
        }

        $new_attachment_id = $replacement_attachment_id > 0
            ? $replacement_attachment_id
            : attachment_url_to_postid($new_url);
        if (!$new_attachment_id) {
            return false;
        }

        set_post_thumbnail($post_id, $new_attachment_id);

        $this->logger->info('featured_image_updated', [
            'post_id' => $post_id,
            'old_attachment_id' => $thumbnail_id,
            'new_attachment_id' => $new_attachment_id,
        ]);

        return true;
    }

    public function bulk_restore(array $image_ids, bool $dry_run = false): array
    {
        $scan_id = (string) get_transient('wir_current_scan_id');
        if (!$scan_id) {
            $scan_id = (string) get_option('wir_last_scan_id', '');
        }

        if (!$scan_id) {
            return [
                'success' => false,
                'error' => 'No scan results found',
            ];
        }

        $scan_data = $this->get_scan_results($scan_id);

        if (!$scan_data) {
            return [
                'success' => false,
                'error' => 'Scan results expired',
            ];
        }

        $result = [
            'success' => true,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'stopped_early' => false,
            'stop_reason' => null,
            'errors' => [],
        ];

        $batch_size = $this->resources->get_batch_size();

        foreach ($image_ids as $index => $image_id) {
            if ($this->resources->should_stop('bulk_restore_' . $index)) {
                $result['stopped_early'] = true;
                $result['stop_reason'] = 'resource_limit';
                break;
            }

            $image_data = null;
            foreach ($scan_data['broken_images'] as $img) {
                if ((int) ($img['id'] ?? 0) === (int) $image_id) {
                    $image_data = $img;
                    break;
                }
            }

            if (!$image_data) {
                $result['errors'][] = [
                    'id' => $image_id,
                    'error' => 'Image not found in scan results',
                ];
                $result['failed']++;
                continue;
            }

            $image_data['dry_run'] = $dry_run;
            $restore_result = $this->restore($image_data);

            $result['processed']++;

            if ($restore_result['success']) {
                $result['succeeded']++;
            } else {
                $result['failed']++;
                $result['errors'][] = [
                    'id' => $image_id,
                    'error' => $restore_result['error'] ?? 'Unknown error',
                ];
            }

            if (($index + 1) % $batch_size === 0) {
                $this->resources->optimize();
            }

            $this->resources->pause();
        }

        $this->logger->info('bulk_restore_complete', [
            'processed' => $result['processed'],
            'succeeded' => $result['succeeded'],
            'failed' => $result['failed'],
            'stopped_early' => $result['stopped_early'],
            'dry_run' => $dry_run,
        ]);

        return $result;
    }

    private function get_preferred_extension(string $original_extension, string $mime_type): string
    {
        $mime_extension = $this->mime_type_to_extension($mime_type);

        if ($original_extension !== '' && $this->extensions_match($original_extension, $mime_extension)) {
            return $original_extension;
        }

        return $mime_extension;
    }

    private function extensions_match(string $first, string $second): bool
    {
        $first = strtolower($first);
        $second = strtolower($second);

        if ($first === $second) {
            return true;
        }

        return in_array($first, ['jpg', 'jpeg'], true) && in_array($second, ['jpg', 'jpeg'], true);
    }

    private function resolve_existing_attachment_id(string $image_url, array $referenced_in): int
    {
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id > 0) {
            return (int) $attachment_id;
        }

        foreach ($referenced_in as $reference) {
            if (($reference['context'] ?? '') !== 'media_library') {
                continue;
            }

            $post_id = (int) ($reference['post_id'] ?? 0);
            if ($post_id > 0 && get_post_type($post_id) === 'attachment') {
                return $post_id;
            }
        }

        return 0;
    }

    private function restore_existing_attachment(
        int $attachment_id,
        string $downloaded_file,
        string $mime_type,
        string $source_url
    ): array {
        if ($this->resources->should_stop('restore_existing_attachment')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
            ];
        }

        $target_path = $this->get_attachment_restore_path($attachment_id, $mime_type);
        if ($target_path === '') {
            return [
                'success' => false,
                'error' => 'Attachment file path could not be resolved',
            ];
        }

        $target_dir = dirname($target_path);
        if (!file_exists($target_dir) && !wp_mkdir_p($target_dir)) {
            return [
                'success' => false,
                'error' => 'Failed to create attachment directory',
            ];
        }

        if (!@copy($downloaded_file, $target_path)) {
            return [
                'success' => false,
                'error' => 'Failed to restore attachment file',
            ];
        }

        clearstatcache(true, $target_path);

        $path_sync = $this->sync_attachment_file_path($attachment_id, $target_path);
        if (!$path_sync['success']) {
            return [
                'success' => false,
                'error' => $path_sync['error'],
            ];
        }

        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $mime_type,
        ]);

        $metadata = $this->generate_attachment_metadata($attachment_id, $target_path, $mime_type);
        if ($metadata !== null) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $this->update_attachment_meta($attachment_id, $source_url);

        $attachment_url = wp_get_attachment_url($attachment_id);
        if (!$attachment_url) {
            return [
                'success' => false,
                'error' => 'Failed to resolve restored attachment URL',
            ];
        }

        $this->logger->info('attachment_restored_in_place', [
            'attachment_id' => $attachment_id,
            'file_path' => $target_path,
            'mime_type' => $mime_type,
        ]);

        return [
            'success' => true,
            'attachment_id' => $attachment_id,
            'url' => $attachment_url,
        ];
    }

    private function sync_attachment_file_path(int $attachment_id, string $target_path): array
    {
        $normalized_target_path = wp_normalize_path($target_path);
        $current_path = get_attached_file($attachment_id);
        $normalized_current_path = is_string($current_path) ? wp_normalize_path($current_path) : '';

        if ($normalized_current_path === $normalized_target_path) {
            return [
                'success' => true,
                'changed' => false,
            ];
        }

        $update_result = update_attached_file($attachment_id, $target_path);
        if ($update_result !== false) {
            return [
                'success' => true,
                'changed' => true,
            ];
        }

        $resolved_path = get_attached_file($attachment_id);
        if (is_string($resolved_path) && wp_normalize_path($resolved_path) === $normalized_target_path) {
            return [
                'success' => true,
                'changed' => true,
            ];
        }

        $relative_upload_path = $this->get_relative_upload_path($target_path);
        if ($relative_upload_path !== null) {
            $meta_update = update_post_meta($attachment_id, '_wp_attached_file', $relative_upload_path);
            if (
                $meta_update !== false ||
                (string) get_post_meta($attachment_id, '_wp_attached_file', true) === $relative_upload_path
            ) {
                return [
                    'success' => true,
                    'changed' => true,
                ];
            }
        }

        $this->logger->warning('attachment_path_sync_failed', [
            'attachment_id' => $attachment_id,
            'target_path' => $target_path,
            'resolved_path' => is_string($resolved_path) ? $resolved_path : '',
            'stored_relative_path' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
        ]);

        return [
            'success' => false,
            'error' => 'Failed to update attachment file path in WordPress metadata',
        ];
    }

    private function get_attachment_restore_path(int $attachment_id, string $mime_type): string
    {
        $current_path = get_attached_file($attachment_id);
        if (!is_string($current_path) || $current_path === '') {
            return '';
        }

        $desired_extension = $this->mime_type_to_extension($mime_type);
        $current_extension = strtolower((string) pathinfo($current_path, PATHINFO_EXTENSION));

        if ($this->extensions_match($current_extension, $desired_extension)) {
            return $current_path;
        }

        $directory = dirname($current_path);
        $filename = pathinfo($current_path, PATHINFO_FILENAME) . '.' . $desired_extension;

        return trailingslashit($directory) . wp_unique_filename($directory, $filename);
    }

    private function get_relative_upload_path(string $path): ?string
    {
        $uploads = wp_get_upload_dir();
        $basedir = $uploads['basedir'] ?? '';
        if (!is_string($basedir) || $basedir === '') {
            return null;
        }

        $normalized_basedir = trailingslashit(wp_normalize_path($basedir));
        $normalized_path = wp_normalize_path($path);

        if (!str_starts_with($normalized_path, $normalized_basedir)) {
            return null;
        }

        return ltrim(substr($normalized_path, strlen($normalized_basedir)), '/');
    }

    private function generate_attachment_metadata(int $attachment_id, string $file_path, string $mime_type): ?array
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            return null;
        }

        if ($mime_type === 'image/svg+xml') {
            return [];
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);

        return is_array($metadata) ? $metadata : [];
    }

    private function get_scan_results(string $scan_id): ?array
    {
        $scan_ids = array_unique(array_filter([
            $scan_id,
            (string) get_transient('wir_current_scan_id'),
            (string) get_option('wir_last_scan_id', ''),
        ]));

        foreach ($scan_ids as $candidate_scan_id) {
            $scan_data = get_transient('wir_last_scan_' . $candidate_scan_id);
            if (is_array($scan_data) && !empty($scan_data['broken_images']) && is_array($scan_data['broken_images'])) {
                return $scan_data;
            }
        }

        return null;
    }
}
