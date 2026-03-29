<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class State_Store
{
    private const SCAN_OPTION_PREFIX = 'wir_scan_results_';
    private const SCAN_INDEX_OPTION = 'wir_scan_results_index';
    private const SCAN_RETENTION_SECONDS = 7 * DAY_IN_SECONDS;

    public function save_scan_results(array $results, ?int $ttl = null, bool $set_as_last_scan = false): void
    {
        $scan_id = (string) ($results['scan_id'] ?? '');
        if ($scan_id === '') {
            return;
        }

        $ttl = $ttl ?? self::SCAN_RETENTION_SECONDS;
        $record = [
            'stored_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'expires_at' => time() + $ttl,
            'data' => $results,
        ];

        update_option($this->get_scan_option_name($scan_id), $record, false);
        set_transient('wir_last_scan_' . $scan_id, $results, $ttl);

        $index = $this->get_scan_index();
        $index[$scan_id] = (int) $record['expires_at'];
        update_option(self::SCAN_INDEX_OPTION, $index, false);

        if ($set_as_last_scan) {
            $this->set_last_scan_id($scan_id, $ttl);
        }
        $this->cleanup_expired_scans();
    }

    public function get_scan_results(string $scan_id): ?array
    {
        if ($scan_id === '') {
            return null;
        }

        $cached = get_transient('wir_last_scan_' . $scan_id);
        if (is_array($cached)) {
            return $cached;
        }

        $record = get_option($this->get_scan_option_name($scan_id), null);
        if (!is_array($record) || !isset($record['data']) || !is_array($record['data'])) {
            return null;
        }

        $expires_at = (int) ($record['expires_at'] ?? 0);
        if ($expires_at > 0 && $expires_at < time()) {
            $this->delete_scan_results($scan_id);
            return null;
        }

        $ttl = max(60, $expires_at - time());
        set_transient('wir_last_scan_' . $scan_id, $record['data'], $ttl);

        return $record['data'];
    }

    public function delete_scan_results(string $scan_id): void
    {
        if ($scan_id === '') {
            return;
        }

        delete_option($this->get_scan_option_name($scan_id));
        delete_transient('wir_last_scan_' . $scan_id);

        $index = $this->get_scan_index();
        unset($index[$scan_id]);
        update_option(self::SCAN_INDEX_OPTION, $index, false);
    }

    public function set_last_scan_id(string $scan_id, ?int $ttl = null): void
    {
        if ($scan_id === '') {
            return;
        }

        $ttl = $ttl ?? self::SCAN_RETENTION_SECONDS;

        update_option('wir_last_scan_id', $scan_id, false);
        set_transient('wir_last_scan_id', $scan_id, $ttl);
        set_transient('wir_current_scan_id', $scan_id, $ttl);
    }

    public function get_last_scan_id(): string
    {
        $scan_id = (string) get_transient('wir_last_scan_id');
        if ($scan_id !== '') {
            return $scan_id;
        }

        return (string) get_option('wir_last_scan_id', '');
    }

    public function get_current_scan_id(): string
    {
        $scan_id = (string) get_transient('wir_current_scan_id');
        if ($scan_id !== '') {
            return $scan_id;
        }

        return $this->get_last_scan_id();
    }

    public function get_scan_results_for_last_scan(): ?array
    {
        $scan_id = $this->get_last_scan_id();
        return $scan_id !== '' ? $this->get_scan_results($scan_id) : null;
    }

    public function find_scan_image(string $scan_id, int $image_id): ?array
    {
        $scan = $this->get_scan_results($scan_id);
        if (!is_array($scan) || empty($scan['broken_images']) || !is_array($scan['broken_images'])) {
            return null;
        }

        foreach ($scan['broken_images'] as $image) {
            if ((int) ($image['id'] ?? 0) === $image_id) {
                return $image;
            }
        }

        return null;
    }

    public function find_scan_image_by_url(string $scan_id, string $url): ?array
    {
        $scan = $this->get_scan_results($scan_id);
        if (!is_array($scan) || empty($scan['broken_images']) || !is_array($scan['broken_images'])) {
            return null;
        }

        foreach ($scan['broken_images'] as $image) {
            if ((string) ($image['url'] ?? '') === $url) {
                return $image;
            }
        }

        return null;
    }

    public function merge_browser_verified_images(string $scan_id, array $images): ?array
    {
        $scan = $this->get_scan_results($scan_id);
        if (!is_array($scan) || !isset($scan['broken_images']) || !is_array($scan['broken_images'])) {
            return null;
        }

        $existing_urls = [];
        $next_id = 0;

        foreach ($scan['broken_images'] as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url !== '') {
                $existing_urls[$url] = true;
            }
            $next_id = max($next_id, (int) ($image['id'] ?? 0));
        }

        $added = 0;
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $url = (string) ($image['url'] ?? '');
            if ($url === '' || isset($existing_urls[$url])) {
                continue;
            }

            $next_id++;
            $image['id'] = $next_id;
            $scan['broken_images'][] = $image;
            $existing_urls[$url] = true;
            $added++;
        }

        if ($added === 0) {
            return $scan;
        }

        $scan['stats'] = is_array($scan['stats'] ?? null) ? $scan['stats'] : [];
        $scan['stats']['images_broken'] = count($scan['broken_images']);
        $scan['stats']['browser_verified_broken'] = (int) ($scan['stats']['browser_verified_broken'] ?? 0) + $added;

        $this->save_scan_results($scan);

        return $scan;
    }

    public function update_scan_image(string $scan_id, int $image_id, callable $callback): ?array
    {
        $scan = $this->get_scan_results($scan_id);
        if (!is_array($scan) || !isset($scan['broken_images']) || !is_array($scan['broken_images'])) {
            return null;
        }

        foreach ($scan['broken_images'] as $index => $image) {
            if ((int) ($image['id'] ?? 0) !== $image_id) {
                continue;
            }

            $updated = $callback($image);
            if (!is_array($updated)) {
                return null;
            }

            $scan['broken_images'][$index] = $updated;
            $this->save_scan_results($scan);

            return $updated;
        }

        return null;
    }

    public function mark_restore_started(string $scan_id, int $image_id): void
    {
        $this->update_scan_image($scan_id, $image_id, static function (array $image): array {
            $image['restore_status'] = 'in_progress';
            $image['restore_started_at'] = current_time('c');
            unset($image['restore_error']);
            return $image;
        });
    }

    public function mark_restore_result(string $scan_id, int $image_id, array $result): void
    {
        $this->update_scan_image($scan_id, $image_id, static function (array $image) use ($result): array {
            $success = !empty($result['success']);
            $dry_run = !empty($result['dry_run']);

            $image['restore_status'] = $success
                ? ($dry_run ? 'dry_run' : 'restored')
                : 'failed';
            $image['restore_completed_at'] = current_time('c');
            $image['restored_attachment_id'] = (int) ($result['undo_attachment_id'] ?? $result['new_attachment_id'] ?? 0);
            $image['restored_url'] = (string) ($result['new_url'] ?? '');
            $image['undo_available'] = !empty($result['undo_available']);

            if ($success) {
                unset($image['restore_error']);
            } else {
                $image['restore_error'] = (string) ($result['error'] ?? 'Unknown error');
            }

            return $image;
        });
    }

    public function clear_restore_result(string $scan_id, int $image_id): void
    {
        $this->update_scan_image($scan_id, $image_id, static function (array $image): array {
            unset(
                $image['restore_status'],
                $image['restore_started_at'],
                $image['restore_completed_at'],
                $image['restored_attachment_id'],
                $image['restored_url'],
                $image['undo_available'],
                $image['restore_error']
            );

            return $image;
        });
    }

    public function cleanup_expired_scans(): void
    {
        $index = $this->get_scan_index();
        if (empty($index)) {
            return;
        }

        $changed = false;
        foreach ($index as $scan_id => $expires_at) {
            if ((int) $expires_at >= time()) {
                continue;
            }

            delete_option($this->get_scan_option_name((string) $scan_id));
            delete_transient('wir_last_scan_' . $scan_id);
            unset($index[$scan_id]);
            $changed = true;
        }

        if ($changed) {
            update_option(self::SCAN_INDEX_OPTION, $index, false);
        }
    }

    private function get_scan_option_name(string $scan_id): string
    {
        return self::SCAN_OPTION_PREFIX . sanitize_key($scan_id);
    }

    private function get_scan_index(): array
    {
        $index = get_option(self::SCAN_INDEX_OPTION, []);
        return is_array($index) ? $index : [];
    }
}
