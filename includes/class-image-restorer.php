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
    private State_Store $state_store;

    public function __construct(?Wayback_Api $api = null, ?Resource_Manager $resources = null)
    {
        $this->api = $api ?? new Wayback_Api();
        $this->logger = Logger::get_instance();
        $this->resources = $resources ?? new Resource_Manager();
        $this->state_store = new State_Store();

        if (!defined('WIR_START_TIME')) {
            define('WIR_START_TIME', microtime(true));
        }
    }

    public function restore(array $image_data): array
    {
        $image_url     = $image_data['url'] ?? '';
        $archive_url   = $image_data['archive_url'] ?? null;
        $referenced_in = $image_data['referenced_in'] ?? [];
        $dry_run       = $image_data['dry_run'] ?? false;
        $scan_id       = (string) ($image_data['scan_id'] ?? '');
        $image_id      = (int) ($image_data['id'] ?? 0);
        $target_date   = isset($image_data['target_date']) && is_string($image_data['target_date']) && $image_data['target_date'] !== ''
            ? $image_data['target_date']
            : null;

        if (empty($image_url)) {
            $result = [
                'success' => false,
                'error' => 'No image URL provided',
            ];

            $this->persist_restore_state($scan_id, $image_id, $result);
            return $result;
        }

        if ($this->resources->should_stop('restore_start')) {
            return $this->create_resource_limit_failure(
                $image_url,
                'Resource limit reached',
                'restore_start',
                $scan_id,
                $image_id
            );
        }

        $restore_mode = Settings::get('restore_mode', 'archive_then_original');

        if (!$dry_run) {
            $preflight = $this->preflight_wayback_restore(
                $image_url,
                $archive_url,
                $restore_mode,
                $target_date,
                $scan_id,
                $image_id
            );

            if (!empty($preflight['failure']) && is_array($preflight['failure'])) {
                return $preflight['failure'];
            }

            $archive_url = $preflight['archive_url'] ?? $archive_url;
        }

        $this->mark_restore_started($scan_id, $image_id);

        if (
            $target_date !== null
            && $this->restore_mode_uses_archive($restore_mode)
            && !$this->has_archive_url($archive_url)
        ) {
            $archive_info = $this->api->find_archive($image_url, $target_date, true);
            $archive_url = $archive_info['archive_url'] ?? null;
        }

        $this->logger->info('restore_start', [
            'image_url'   => $image_url,
            'archive_url' => $archive_url,
            'dry_run'     => $dry_run,
            'target_date' => $target_date,
        ]);

        if ($dry_run) {
            $this->logger->info('restore_dry_run', [
                'image_url'    => $image_url,
                'would_restore' => true,
            ]);

            $result = [
                'success'     => true,
                'dry_run'     => true,
                'message'     => 'Dry run - would restore image',
                'image_url'   => $image_url,
                'archive_url' => $archive_url,
            ];

            $this->persist_restore_state($scan_id, $image_id, $result);
            return $result;
        }

        $download_result = $this->download_image($image_url, $archive_url, $restore_mode);

        if (!$download_result['success']) {
            $this->log_restore_failure($image_url, $download_result);

            $result = [
                'success' => false,
                'error' => $download_result['error'],
            ];

            if (!empty($download_result['resource_limit'])) {
                $result['resource_limit'] = true;
                $result['resource_checkpoint'] = (string) ($download_result['resource_checkpoint'] ?? '');
            }

            $this->persist_restore_state($scan_id, $image_id, $result);
            return $result;
        }

        $temp_file              = $download_result['file'];
        $existing_attachment_id = $this->resolve_existing_attachment_id($image_url, $referenced_in);

        try {
            if ($existing_attachment_id > 0) {
                $import_result = $this->restore_existing_attachment(
                    $existing_attachment_id,
                    $temp_file,
                    $download_result['mime_type'],
                    $image_url
                );
            } else {
                $import_result = $this->import_to_media_library(
                    $temp_file,
                    $download_result['mime_type'],
                    $image_url
                );
            }

            if (!$import_result['success']) {
                $this->log_restore_failure($image_url, $import_result);

                $result = [
                    'success' => false,
                    'error' => $import_result['error'],
                ];

                if (!empty($import_result['resource_limit'])) {
                    $result['resource_limit'] = true;
                    $result['resource_checkpoint'] = (string) ($import_result['resource_checkpoint'] ?? '');
                }

                $this->persist_restore_state($scan_id, $image_id, $result);
                return $result;
            }

            $new_attachment_id = (int) $import_result['attachment_id'];
            $new_url           = $import_result['url'] ?? wp_get_attachment_url($new_attachment_id);
            $backup_data       = is_array($import_result['backup'] ?? null) ? $import_result['backup'] : null;

            $update_result   = ['updated' => 0, 'failed' => 0];
            $has_url_change  = is_string($new_url) && $new_url !== '' && $new_url !== $image_url;
            $undo_available  = ($existing_attachment_id === 0 && $has_url_change) || $backup_data !== null;

            if ($has_url_change) {
                $update_result = $this->update_post_references($image_url, $new_url, $referenced_in, $new_attachment_id);
            }

            if ($undo_available) {
                $this->store_rollback_data(
                    $new_attachment_id,
                    $image_url,
                    is_string($new_url) ? $new_url : $image_url,
                    $referenced_in,
                    $existing_attachment_id === 0,
                    $existing_attachment_id,
                    $backup_data
                );
            }

            $this->logger->success('restore_complete', [
                'image_url'         => $image_url,
                'new_url'           => $new_url,
                'new_attachment_id' => $new_attachment_id,
                'posts_updated'     => $update_result['updated'],
                'failed_posts'      => $update_result['failed'],
                'undo_available'    => $undo_available,
            ]);

            $this->resources->optimize();
            $this->resources->pause();

            $result = [
                'success'           => true,
                'dry_run'           => false,
                'new_attachment_id' => $new_attachment_id,
                'new_url'           => $new_url,
                'posts_updated'     => $update_result['updated'],
                'failed_posts'      => $update_result['failed'],
                'undo_available'    => $undo_available,
                'undo_attachment_id' => $undo_available ? $new_attachment_id : 0,
            ];

            $this->persist_restore_state($scan_id, $image_id, $result);
            return $result;
        } finally {
            if (isset($temp_file) && is_string($temp_file) && $temp_file !== '') {
                $this->api->cleanup_temp_file($temp_file);
            }
        }
    }

    private function store_rollback_data(
        int $attachment_id,
        string $original_url,
        string $new_url,
        array $referenced_in,
        bool $was_new_import,
        int $original_attachment_id = 0,
        ?array $backup = null
    ): void {
        $affected_post_ids = array_values(array_unique(array_map(
            static fn($r) => (int) ($r['post_id'] ?? 0),
            array_filter($referenced_in, static fn($r) => ($r['context'] ?? '') !== 'media_library')
        )));

        update_post_meta($attachment_id, '_wir_rollback_data', [
            'original_url'      => $original_url,
            'new_url'           => $new_url,
            'affected_post_ids' => $affected_post_ids,
            'referenced_in'     => $referenced_in,
            'was_new_import'    => $was_new_import,
            'original_attachment_id' => $original_attachment_id,
            'backup'            => $backup,
            'restored_at'       => current_time('mysql'),
        ]);
    }

    public function undo_restore(int $attachment_id): array
    {
        $rollback = get_post_meta($attachment_id, '_wir_rollback_data', true);

        if (!is_array($rollback) || empty($rollback['original_url'])) {
            return ['success' => false, 'error' => 'No rollback data found for this attachment'];
        }

        $original_url   = (string) $rollback['original_url'];
        $new_url        = (string) ($rollback['new_url'] ?? '');
        $referenced_in  = (array) ($rollback['referenced_in'] ?? []);
        $was_new_import = (bool) ($rollback['was_new_import'] ?? false);
        $backup         = is_array($rollback['backup'] ?? null) ? $rollback['backup'] : null;

        if ($new_url === '' && $backup === null) {
            return ['success' => false, 'error' => 'Invalid rollback data'];
        }

        if (!$was_new_import && $backup === null) {
            return ['success' => false, 'error' => 'Undo is only available when the original file or a replacement attachment can be restored'];
        }

        $revert_result = ['updated' => 0, 'failed' => 0];
        if ($new_url !== '' && $original_url !== '' && $original_url !== $new_url) {
            $revert_result = $this->update_post_references($new_url, $original_url, $referenced_in, 0);
        }

        if (!$was_new_import && $backup !== null) {
            $restore_backup = $this->restore_attachment_backup($attachment_id, $backup);
            if (!$restore_backup['success']) {
                return ['success' => false, 'error' => $restore_backup['error']];
            }
        }

        delete_post_meta($attachment_id, '_wir_rollback_data');

        if ($was_new_import) {
            wp_delete_attachment($attachment_id, true);
        }

        $this->logger->info('restore_undone', [
            'attachment_id'      => $attachment_id,
            'original_url'       => $original_url,
            'new_url'            => $new_url,
            'posts_reverted'     => $revert_result['updated'],
            'was_new_import'     => $was_new_import,
        ]);

        return [
            'success'             => true,
            'original_url'        => $original_url,
            'posts_reverted'      => $revert_result['updated'],
            'attachment_deleted'  => $was_new_import,
        ];
    }

    private function restore_attachment_backup(int $attachment_id, array $backup): array
    {
        $backup_file = (string) ($backup['backup_file'] ?? '');
        $original_path = (string) ($backup['original_path'] ?? '');
        $original_mime_type = (string) ($backup['original_post_mime_type'] ?? '');
        $original_metadata = is_array($backup['original_metadata'] ?? null) ? $backup['original_metadata'] : null;

        if ($backup_file === '' || $original_path === '' || !is_file($backup_file)) {
            return [
                'success' => false,
                'error' => 'Original attachment backup could not be found',
            ];
        }

        $current_path = get_attached_file($attachment_id);
        $current_path = is_string($current_path) ? $current_path : '';
        $original_dir = dirname($original_path);

        if (!file_exists($original_dir) && !wp_mkdir_p($original_dir)) {
            return [
                'success' => false,
                'error' => 'Failed to recreate the original attachment directory',
            ];
        }

        if (!@copy($backup_file, $original_path)) {
            return [
                'success' => false,
                'error' => 'Failed to restore the original attachment file',
            ];
        }

        $path_sync = $this->sync_attachment_file_path($attachment_id, $original_path);
        if (!$path_sync['success']) {
            return [
                'success' => false,
                'error' => $path_sync['error'],
            ];
        }

        if ($original_mime_type !== '') {
            wp_update_post([
                'ID' => $attachment_id,
                'post_mime_type' => $original_mime_type,
            ]);
        }

        if ($original_metadata !== null) {
            wp_update_attachment_metadata($attachment_id, $original_metadata);
        }

        if ($current_path !== '' && $current_path !== $original_path && is_file($current_path)) {
            @unlink($current_path);
        }

        @unlink($backup_file);

        return ['success' => true];
    }

    private function mark_restore_started(string $scan_id, int $image_id): void
    {
        if ($scan_id === '' || $image_id <= 0) {
            return;
        }

        $this->state_store->mark_restore_started($scan_id, $image_id);
    }

    private function persist_restore_state(string $scan_id, int $image_id, array $result): void
    {
        if ($scan_id === '' || $image_id <= 0) {
            return;
        }

        $this->state_store->mark_restore_result($scan_id, $image_id, $result);
    }

    private function create_resource_limit_failure(
        string $image_url,
        string $error,
        string $checkpoint,
        string $scan_id = '',
        int $image_id = 0
    ): array {
        $result = [
            'success' => false,
            'error' => $error,
            'resource_limit' => true,
            'resource_checkpoint' => $checkpoint,
        ];

        $this->log_restore_failure($image_url, $result);

        $this->persist_restore_state($scan_id, $image_id, $result);

        return $result;
    }

    private function create_wayback_unreachable_failure(
        string $image_url,
        string $restore_mode,
        ?string $target_date,
        ?string $archive_url,
        array $preflight,
        string $scan_id = '',
        int $image_id = 0
    ): array {
        $detail = (string) ($preflight['error'] ?? 'Unknown error');
        $error = $detail !== ''
            ? sprintf('Wayback Machine is currently unreachable: %s', $detail)
            : 'Wayback Machine is currently unreachable';

        $result = [
            'success' => false,
            'error' => $error,
            'restore_checkpoint' => 'wayback_preflight',
            'wayback_reachable' => false,
            'wayback_preflight' => $preflight,
            'restore_mode' => $restore_mode,
            'target_date' => $target_date,
            'archive_url' => $archive_url,
        ];

        $this->log_restore_failure($image_url, $result);
        $this->persist_restore_state($scan_id, $image_id, $result);

        return $result;
    }

    private function preflight_wayback_restore(
        string $image_url,
        ?string $archive_url,
        string $restore_mode,
        ?string $target_date,
        string $scan_id,
        int $image_id
    ): array {
        if (!$this->needs_wayback_preflight($restore_mode, $archive_url, $target_date)) {
            return [
                'archive_url' => $archive_url,
                'failure' => null,
            ];
        }

        $preflight = $this->api->check_service_reachability();
        if (!empty($preflight['reachable'])) {
            return [
                'archive_url' => $archive_url,
                'failure' => null,
            ];
        }

        if (!empty($preflight['rate_limited'])) {
            if ($this->can_skip_archive_after_wayback_failure($restore_mode, $target_date)) {
                if (empty($preflight['cached'])) {
                    $this->logger->warning('restore_wayback_rate_limited', [
                        'image_url' => $image_url,
                        'restore_mode' => $restore_mode,
                        'archive_url' => $archive_url,
                        'target_date' => $target_date,
                        'wayback_error' => (string) ($preflight['error'] ?? 'Rate limited by Wayback Machine'),
                        'wayback_status_code' => (int) ($preflight['status_code'] ?? 429),
                        'wayback_checked_at' => (string) ($preflight['checked_at'] ?? ''),
                        'wayback_rate_limit_until' => (string) ($preflight['rate_limit_until'] ?? ''),
                        'wayback_rate_limit_remaining_seconds' => (int) ($preflight['rate_limit_remaining_seconds'] ?? 0),
                    ]);
                }

                return [
                    'archive_url' => $archive_url,
                    'failure' => null,
                ];
            }

            if (empty($preflight['cached'])) {
                $this->logger->warning('restore_wayback_rate_limited', [
                    'image_url' => $image_url,
                    'restore_mode' => $restore_mode,
                    'archive_url' => $archive_url,
                    'target_date' => $target_date,
                    'wayback_error' => (string) ($preflight['error'] ?? 'Rate limited by Wayback Machine'),
                    'wayback_status_code' => (int) ($preflight['status_code'] ?? 429),
                    'wayback_checked_at' => (string) ($preflight['checked_at'] ?? ''),
                    'wayback_rate_limit_until' => (string) ($preflight['rate_limit_until'] ?? ''),
                    'wayback_rate_limit_remaining_seconds' => (int) ($preflight['rate_limit_remaining_seconds'] ?? 0),
                ]);
            }

            return [
                'archive_url' => $archive_url,
                'failure' => $this->create_wayback_unreachable_failure(
                    $image_url,
                    $restore_mode,
                    $target_date,
                    $archive_url,
                    $preflight,
                    $scan_id,
                    $image_id
                ),
            ];
        }

        if ($this->can_skip_archive_after_wayback_failure($restore_mode, $target_date)) {
            if (empty($preflight['cached'])) {
                $this->logger->warning('restore_wayback_skipped', [
                    'image_url' => $image_url,
                    'restore_mode' => $restore_mode,
                    'archive_url' => $archive_url,
                    'wayback_error' => (string) ($preflight['error'] ?? 'Unknown error'),
                    'wayback_status_code' => (int) ($preflight['status_code'] ?? 0),
                    'wayback_checked_at' => (string) ($preflight['checked_at'] ?? ''),
                ]);
            }

            return [
                'archive_url' => null,
                'failure' => null,
            ];
        }

        return [
            'archive_url' => $archive_url,
            'failure' => $this->create_wayback_unreachable_failure(
                $image_url,
                $restore_mode,
                $target_date,
                $archive_url,
                $preflight,
                $scan_id,
                $image_id
            ),
        ];
    }

    private function log_restore_failure(string $image_url, array $result): void
    {
        $log_data = [
            'image_url' => $image_url,
            'error' => (string) ($result['error'] ?? 'Unknown error'),
        ];

        if (!empty($result['resource_limit'])) {
            $log_data['resource_limit'] = true;
            $log_data['resource_checkpoint'] = (string) ($result['resource_checkpoint'] ?? '');
            $log_data['resource_status'] = $this->resources->get_status();
        }

        if (!empty($result['restore_checkpoint'])) {
            $log_data['restore_checkpoint'] = (string) $result['restore_checkpoint'];
        }

        if (array_key_exists('wayback_reachable', $result)) {
            $log_data['wayback_reachable'] = !empty($result['wayback_reachable']);
        }

        $preflight = $result['wayback_preflight'] ?? null;
        if (is_array($preflight)) {
            $log_data['wayback_error'] = (string) ($preflight['error'] ?? '');
            $log_data['wayback_status_code'] = isset($preflight['status_code'])
                ? (int) $preflight['status_code']
                : 0;
            $log_data['wayback_checked_at'] = (string) ($preflight['checked_at'] ?? '');
            $log_data['wayback_check_cached'] = !empty($preflight['cached']);
            $log_data['wayback_rate_limit_until'] = (string) ($preflight['rate_limit_until'] ?? '');
            $log_data['wayback_rate_limit_remaining_seconds'] = isset($preflight['rate_limit_remaining_seconds'])
                ? (int) $preflight['rate_limit_remaining_seconds']
                : 0;
        }

        if (!empty($result['restore_mode'])) {
            $log_data['restore_mode'] = (string) $result['restore_mode'];
        }

        if (array_key_exists('target_date', $result)) {
            $log_data['target_date'] = $result['target_date'];
        }

        $this->logger->error('restore_failed', $log_data);
    }

    private function needs_wayback_preflight(string $restore_mode, ?string $archive_url, ?string $target_date): bool
    {
        if (!$this->restore_mode_uses_archive($restore_mode)) {
            return false;
        }

        if ($this->has_archive_url($archive_url)) {
            return false;
        }

        return $target_date !== null;
    }

    private function can_skip_archive_after_wayback_failure(string $restore_mode, ?string $target_date): bool
    {
        if ($target_date !== null) {
            return false;
        }

        return in_array($restore_mode, ['archive_then_original', 'original_then_archive'], true);
    }

    private function restore_mode_uses_archive(string $restore_mode): bool
    {
        return in_array($restore_mode, ['archive_only', 'archive_then_original', 'original_then_archive'], true);
    }

    private function has_archive_url(?string $archive_url): bool
    {
        return is_string($archive_url) && $archive_url !== '';
    }

    private function download_image(string $original_url, ?string $archive_url, string $restore_mode): array
    {
        if ($this->resources->should_stop('download_start')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
                'resource_limit' => true,
                'resource_checkpoint' => 'download_start',
            ];
        }

        $sources = $this->get_download_sources($original_url, $archive_url, $restore_mode);

        foreach ($sources as $source) {
            if ($this->resources->should_stop('download_attempt')) {
                return [
                    'success' => false,
                    'error' => 'Resource limit reached during download',
                    'resource_limit' => true,
                    'resource_checkpoint' => 'download_attempt',
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
                'resource_limit' => true,
                'resource_checkpoint' => 'import_start',
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

            if (in_array($post_id, $processed_posts, true)) {
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
        $has_exact_match   = str_contains($content, $old_url);
        $has_size_variants = !$has_exact_match && $this->has_srcset_size_variants($content, $old_url);

        if (!$has_exact_match && !$has_size_variants) {
            return false;
        }

        $updated_content = $this->replace_image_url_in_content($content, $old_url, $new_url);

        if ($updated_content === $content) {
            return false;
        }

        $update_result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $updated_content,
        ], true);

        if (is_wp_error($update_result)) {
            $this->logger->warning('post_content_update_failed', [
                'post_id' => $post_id,
                'old_url' => $old_url,
                'new_url' => $new_url,
                'error' => $update_result->get_error_message(),
            ]);

            return false;
        }

        $this->logger->info('post_content_updated', [
            'post_id' => $post_id,
            'old_url' => $old_url,
            'new_url' => $new_url,
        ]);

        return true;
    }

    private function replace_image_url_in_content(string $content, string $old_url, string $new_url): string
    {
        $updated = $this->replace_image_url_in_blocks($content, $old_url, $new_url);
        $updated = $this->replace_image_url_in_markup($updated, $old_url, $new_url);
        $updated = $this->replace_string_url_occurrences($updated, $old_url, $new_url);

        return $updated;
    }

    private function replace_image_url_in_blocks(string $content, string $old_url, string $new_url): string
    {
        if (
            !function_exists('has_blocks') ||
            !function_exists('parse_blocks') ||
            !function_exists('serialize_blocks') ||
            !has_blocks($content)
        ) {
            return $content;
        }

        $blocks = parse_blocks($content);
        if (!is_array($blocks)) {
            return $content;
        }

        $changed = false;
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $blocks[$index] = $this->replace_urls_in_block($block, $old_url, $new_url, $changed);
        }

        return $changed ? serialize_blocks($blocks) : $content;
    }

    private function replace_urls_in_block(array $block, string $old_url, string $new_url, bool &$changed): array
    {
        if (isset($block['attrs']) && is_array($block['attrs'])) {
            $block['attrs'] = $this->replace_urls_in_value($block['attrs'], $old_url, $new_url, $changed);
        }

        if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
            $updated_html = $this->replace_image_url_in_markup($block['innerHTML'], $old_url, $new_url);
            $updated_html = $this->replace_string_url_occurrences($updated_html, $old_url, $new_url);
            if ($updated_html !== $block['innerHTML']) {
                $block['innerHTML'] = $updated_html;
                $changed = true;
            }
        }

        if (isset($block['innerContent']) && is_array($block['innerContent'])) {
            foreach ($block['innerContent'] as $index => $inner_content) {
                if (!is_string($inner_content)) {
                    continue;
                }

                $updated_inner_content = $this->replace_image_url_in_markup($inner_content, $old_url, $new_url);
                $updated_inner_content = $this->replace_string_url_occurrences($updated_inner_content, $old_url, $new_url);
                if ($updated_inner_content !== $inner_content) {
                    $block['innerContent'][$index] = $updated_inner_content;
                    $changed = true;
                }
            }
        }

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $index => $inner_block) {
                if (!is_array($inner_block)) {
                    continue;
                }

                $block['innerBlocks'][$index] = $this->replace_urls_in_block($inner_block, $old_url, $new_url, $changed);
            }
        }

        return $block;
    }

    private function replace_urls_in_value(mixed $value, string $old_url, string $new_url, bool &$changed): mixed
    {
        if (is_string($value)) {
            $updated = $this->replace_string_url_occurrences($value, $old_url, $new_url);
            if ($updated !== $value) {
                $changed = true;
            }

            return $updated;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replace_urls_in_value($item, $old_url, $new_url, $changed);
            }
        }

        return $value;
    }

    private function replace_image_url_in_markup(string $content, string $old_url, string $new_url): string
    {
        $updated = $this->replace_standard_attribute_urls($content, $old_url, $new_url);
        $updated = $this->replace_srcset_attributes($updated, $old_url, $new_url);
        $updated = $this->replace_srcset_size_variants($updated, $old_url, $new_url);

        return $updated;
    }

    private function replace_standard_attribute_urls(string $content, string $old_url, string $new_url): string
    {
        $attributes = [
            'src',
            'href',
            'poster',
            'data-src',
            'data-lazy-src',
            'data-src-full',
            'data-full-url',
            'data-image-src',
            'data-original',
            'data-orig-file',
            'data-medium-file',
            'data-large-file',
        ];

        $pattern = '#\b(' . implode('|', array_map(static fn(string $attribute): string => preg_quote($attribute, '#'), $attributes)) . ')\s*=\s*(["\'])(.*?)\2#is';

        return preg_replace_callback(
            $pattern,
            function (array $matches) use ($old_url, $new_url): string {
                $updated_value = $this->replace_string_url_occurrences((string) $matches[3], $old_url, $new_url);
                return $matches[1] . '=' . $matches[2] . $updated_value . $matches[2];
            },
            $content
        ) ?? $content;
    }

    private function replace_srcset_attributes(string $content, string $old_url, string $new_url): string
    {
        $pattern = '#\b(srcset|data-srcset)\s*=\s*(["\'])(.*?)\2#is';

        return preg_replace_callback(
            $pattern,
            function (array $matches) use ($old_url, $new_url): string {
                $updated_value = $this->replace_srcset_attribute_value((string) $matches[3], $old_url, $new_url);
                return $matches[1] . '=' . $matches[2] . $updated_value . $matches[2];
            },
            $content
        ) ?? $content;
    }

    private function replace_srcset_attribute_value(string $srcset, string $old_url, string $new_url): string
    {
        $parts = preg_split('/\s*,\s*/', trim($srcset));
        if (!is_array($parts)) {
            return $srcset;
        }

        $updated_parts = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($part), 2);
            if (!is_array($tokens) || empty($tokens[0])) {
                $updated_parts[] = $part;
                continue;
            }

            $tokens[0] = $this->replace_string_url_occurrences($tokens[0], $old_url, $new_url);
            $updated_parts[] = implode(' ', array_filter($tokens, static fn(string $token): bool => $token !== ''));
        }

        return implode(', ', $updated_parts);
    }

    private function replace_string_url_occurrences(string $content, string $old_url, string $new_url): string
    {
        if (!str_contains($content, $old_url) && !$this->has_srcset_size_variants($content, $old_url)) {
            return $content;
        }

        $updated = str_replace($old_url, $new_url, $content);

        return $this->replace_srcset_size_variants($updated, $old_url, $new_url);
    }

    private function has_srcset_size_variants(string $content, string $url): bool
    {
        $old_path = parse_url($url, PHP_URL_PATH);
        if (!is_string($old_path) || $old_path === '') {
            return false;
        }

        $base = pathinfo($old_path, PATHINFO_FILENAME);
        $ext  = pathinfo($old_path, PATHINFO_EXTENSION);
        $dir  = dirname($old_path);

        if ($base === '' || $ext === '') {
            return false;
        }

        $pattern = '#' . preg_quote($dir . '/' . $base, '#') . '-\d+x\d+\.' . preg_quote($ext, '#') . '#';
        return (bool) preg_match($pattern, $content);
    }

    private function replace_srcset_size_variants(string $content, string $old_url, string $new_url): string
    {
        $old_path = parse_url($old_url, PHP_URL_PATH);
        $new_path = parse_url($new_url, PHP_URL_PATH);

        if (!is_string($old_path) || !is_string($new_path)) {
            return $content;
        }

        $old_dir  = dirname($old_path);
        $old_base = pathinfo($old_path, PATHINFO_FILENAME);
        $old_ext  = pathinfo($old_path, PATHINFO_EXTENSION);
        $new_dir  = dirname($new_path);
        $new_base = pathinfo($new_path, PATHINFO_FILENAME);
        $new_ext  = pathinfo($new_path, PATHINFO_EXTENSION);

        if ($old_base === '' || $old_ext === '') {
            return $content;
        }

        $pattern = '#' . preg_quote($old_dir . '/' . $old_base, '#') . '-(\d+x\d+)\.' . preg_quote($old_ext, '#') . '#';

        return preg_replace_callback(
            $pattern,
            static fn(array $m): string => $new_dir . '/' . $new_base . '-' . $m[1] . '.' . $new_ext,
            $content
        ) ?? $content;
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

    public function bulk_restore(
        array $image_ids,
        bool $dry_run = false,
        array $provided_images = [],
        ?string $scan_id = null
    ): array
    {
        $provided_lookup = $this->index_provided_images($provided_images);
        $scan_id = is_string($scan_id) && $scan_id !== '' ? $scan_id : null;

        if (empty($image_ids) && !empty($provided_lookup)) {
            $image_ids = array_keys($provided_lookup);
        }

        $scan_data = null;
        if (count($provided_lookup) < count($image_ids)) {
            $scan_id = $scan_id ?? $this->state_store->get_current_scan_id();

            if ($scan_id) {
                $scan_data = $this->get_scan_results($scan_id);
            }
        }

        if (!$scan_data && empty($provided_lookup)) {
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
            'items' => [],
        ];

        $batch_size = $this->resources->get_batch_size();

        foreach ($image_ids as $index => $image_id) {
            if ($this->resources->should_stop('bulk_restore_' . $index)) {
                $result['stopped_early'] = true;
                $result['stop_reason'] = 'resource_limit';
                $this->logger->warning('bulk_restore_stopped_early', [
                    'processed' => $result['processed'],
                    'remaining' => max(0, count($image_ids) - $result['processed']),
                    'resource_status' => $this->resources->get_status(),
                ]);
                break;
            }

            $image_data = $provided_lookup[(int) $image_id] ?? $this->find_image_in_scan_data($scan_data, (int) $image_id);

            if (!$image_data) {
                $error = 'Image not found in scan results';
                $result['errors'][] = [
                    'id' => $image_id,
                    'error' => $error,
                ];
                $result['items'][] = [
                    'id' => (int) $image_id,
                    'success' => false,
                    'error' => $error,
                ];
                $result['failed']++;
                continue;
            }

            $image_data['id'] = (int) $image_id;
            $image_data['dry_run'] = $dry_run;
            $image_data['scan_id'] = $scan_id ?? '';
            $restore_result = $this->restore($image_data);

            $result['processed']++;

            if ($restore_result['success']) {
                $result['succeeded']++;
                $result['items'][] = [
                    'id' => (int) $image_id,
                    'success' => true,
                    'dry_run' => (bool) ($restore_result['dry_run'] ?? false),
                    'attachment_id' => (int) ($restore_result['new_attachment_id'] ?? 0),
                    'new_url' => (string) ($restore_result['new_url'] ?? ''),
                    'undo_available' => !empty($restore_result['undo_available']),
                    'undo_attachment_id' => (int) ($restore_result['undo_attachment_id'] ?? 0),
                ];
            } else {
                $error = $restore_result['error'] ?? 'Unknown error';
                $result['failed']++;
                $result['errors'][] = [
                    'id' => $image_id,
                    'error' => $error,
                ];
                $result['items'][] = [
                    'id' => (int) $image_id,
                    'success' => false,
                    'error' => $error,
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

    private function index_provided_images(array $provided_images): array
    {
        $lookup = [];

        foreach ($provided_images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $image_id = (int) ($image['id'] ?? 0);
            $image_url = (string) ($image['url'] ?? '');
            if ($image_id <= 0 || $image_url === '') {
                continue;
            }

            $lookup[$image_id] = $image;
        }

        return $lookup;
    }

    private function find_image_in_scan_data(?array $scan_data, int $image_id): ?array
    {
        if (!is_array($scan_data) || empty($scan_data['broken_images']) || !is_array($scan_data['broken_images'])) {
            return null;
        }

        foreach ($scan_data['broken_images'] as $image) {
            if ((int) ($image['id'] ?? 0) === $image_id) {
                return $image;
            }
        }

        return null;
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
                'resource_limit' => true,
                'resource_checkpoint' => 'restore_existing_attachment',
            ];
        }

        $backup = $this->create_attachment_backup($attachment_id);
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
            'backup' => $backup,
        ];
    }

    private function create_attachment_backup(int $attachment_id): ?array
    {
        $current_path = get_attached_file($attachment_id);
        if (!is_string($current_path) || $current_path === '' || !is_file($current_path)) {
            return null;
        }

        $uploads = wp_get_upload_dir();
        $backup_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'wayback-image-restorer/backups';
        if ($backup_dir === '' || (!file_exists($backup_dir) && !wp_mkdir_p($backup_dir))) {
            return null;
        }

        $extension = (string) pathinfo($current_path, PATHINFO_EXTENSION);
        $backup_name = sprintf(
            'attachment-%d-%s.%s',
            $attachment_id,
            date('YmdHis'),
            $extension !== '' ? $extension : 'bak'
        );
        $backup_path = trailingslashit($backup_dir) . wp_unique_filename($backup_dir, $backup_name);

        if (!@copy($current_path, $backup_path)) {
            return null;
        }

        return [
            'backup_file' => $backup_path,
            'original_path' => $current_path,
            'original_relative_path' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
            'original_post_mime_type' => (string) get_post_mime_type($attachment_id),
            'original_metadata' => wp_get_attachment_metadata($attachment_id),
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
            $this->state_store->get_current_scan_id(),
            $this->state_store->get_last_scan_id(),
        ]));

        foreach ($scan_ids as $candidate_scan_id) {
            $scan_data = $this->state_store->get_scan_results((string) $candidate_scan_id);
            if (is_array($scan_data) && !empty($scan_data['broken_images']) && is_array($scan_data['broken_images'])) {
                return $scan_data;
            }
        }

        return null;
    }
}
