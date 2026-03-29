<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Wayback_Api
{
    private const CDX_API_URL = 'https://web.archive.org/cdx/search/cdx';
    private const SERVICE_REACHABILITY_CACHE_KEY = 'wir_wayback_service_reachability';
    private const SERVICE_REACHABILITY_SUCCESS_TTL = 300;
    private const SERVICE_REACHABILITY_FAILURE_TTL = 60;
    private const SERVICE_REACHABILITY_PROBE_URL = 'https://example.com/';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [2, 4, 8];
    private const ARCHIVE_EXTENSION_FALLBACKS = [
        'webp' => ['png', 'jpg', 'jpeg'],
        'jpg' => ['jpeg'],
        'jpeg' => ['jpg'],
    ];

    private static array $temp_files = [];
    private static bool $shutdown_cleanup_registered = false;

    private int $timeout;
    private Resource_Manager $resources;

    public function __construct(?int $timeout = null, ?Resource_Manager $resources = null)
    {
        $this->resources = $resources ?? new Resource_Manager();
        $this->timeout = $timeout ?? $this->resources->get_request_timeout();
    }

    public function find_archive(string $url, ?string $target_date = null, bool $explicit_target_date = false): ?array
    {
        if ($this->resources->should_stop('find_archive_start')) {
            return null;
        }

        $resolved_target_date = $this->resolve_lookup_target_date($url, $target_date, $explicit_target_date);
        $last_error = null;
        $best_match = null;
        $best_rank = null;

        foreach ($this->get_archive_lookup_candidates($url) as $candidate_url) {
            $record = $this->find_best_candidate_record($candidate_url, $resolved_target_date);

            if (is_wp_error($record)) {
                $last_error = $record;
                continue;
            }

            if (!is_array($record) || empty($record[0])) {
                continue;
            }

            $archive = $this->format_archive_record($record, $candidate_url);
            $rank = $this->rank_archive_record($archive, $url, $resolved_target_date);

            if ($best_match === null || $this->is_better_archive_rank($rank, $best_rank)) {
                $best_match = $archive;
                $best_rank = $rank;
            }
        }

        if ($best_match !== null) {
            return $best_match;
        }

        if ($last_error instanceof \WP_Error) {
            Logger::get_instance()->warning('wayback_api_error', [
                'url' => $url,
                'error' => $last_error->get_error_message(),
            ]);
        }

        return null;
    }

    public function find_archives(string $url, int $limit = 10): array
    {
        if ($this->resources->should_stop('find_archives_start')) {
            return [];
        }

        $archives = [];
        $seen = [];

        foreach ($this->get_archive_lookup_candidates($url) as $candidate_url) {
            $data = $this->query_cdx($candidate_url, $limit);

            if (is_wp_error($data)) {
                continue;
            }

            foreach ($data as $item) {
                if (!is_array($item) || empty($item[0])) {
                    continue;
                }

                $archive = $this->format_archive_record($item, $candidate_url);
                $archive_key = ($archive['timestamp'] ?? '') . '|' . ($archive['original'] ?? '');

                if (isset($seen[$archive_key])) {
                    continue;
                }

                $seen[$archive_key] = true;
                $archives[] = $archive;

                if (count($archives) >= $limit) {
                    break 2;
                }
            }
        }

        return $archives;
    }

    public function check_service_reachability(bool $force = false): array
    {
        if (!$force) {
            $cached = get_transient(self::SERVICE_REACHABILITY_CACHE_KEY);
            if (is_array($cached) && array_key_exists('reachable', $cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $response = $this->make_request($this->build_cdx_query_url(self::SERVICE_REACHABILITY_PROBE_URL, 1));
        $checked_at = current_time('c');

        if (is_wp_error($response)) {
            $result = [
                'reachable' => false,
                'error' => $response->get_error_message(),
                'checked_at' => $checked_at,
            ];

            set_transient(
                self::SERVICE_REACHABILITY_CACHE_KEY,
                $result,
                self::SERVICE_REACHABILITY_FAILURE_TTL
            );

            Logger::get_instance()->warning('wayback_reachability_failed', [
                'error' => $result['error'],
                'checked_at' => $checked_at,
            ]);

            $result['cached'] = false;

            return $result;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 400) {
            $result = [
                'reachable' => true,
                'status_code' => $status_code,
                'checked_at' => $checked_at,
            ];

            set_transient(
                self::SERVICE_REACHABILITY_CACHE_KEY,
                $result,
                self::SERVICE_REACHABILITY_SUCCESS_TTL
            );

            $result['cached'] = false;

            return $result;
        }

        $error = sprintf('HTTP %d', $status_code);
        if ($status_code === 429) {
            $error = 'Rate limited by Wayback Machine';
        } elseif ($status_code === 503) {
            $error = 'Wayback Machine is temporarily unavailable';
        }

        $result = [
            'reachable' => false,
            'status_code' => $status_code,
            'error' => $error,
            'checked_at' => $checked_at,
        ];

        set_transient(
            self::SERVICE_REACHABILITY_CACHE_KEY,
            $result,
            self::SERVICE_REACHABILITY_FAILURE_TTL
        );

        Logger::get_instance()->warning('wayback_reachability_failed', [
            'status_code' => $status_code,
            'error' => $error,
            'checked_at' => $checked_at,
        ]);

        $result['cached'] = false;

        return $result;
    }

    public function download_image(string $archive_url): array
    {
        if ($this->resources->should_stop('download_image_start')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
                'resource_limit' => true,
                'resource_checkpoint' => 'download_image_start',
            ];
        }

        $response = $this->make_request_with_retry($archive_url);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'success' => false,
                'error' => sprintf('HTTP %d', $code),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        
        if ($this->resources->memory_exhausted()) {
            return [
                'success' => false,
                'error' => 'Memory limit reached',
                'resource_limit' => true,
                'resource_checkpoint' => 'download_image_memory',
            ];
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $mime_type = $this->parse_content_type($content_type);

        $tmp_file = $this->create_temp_file($body);
        if ($tmp_file === false) {
            return [
                'success' => false,
                'error' => 'Failed to create temp file',
            ];
        }

        $detected_mime_type = $this->detect_downloaded_image_mime_type($tmp_file);
        $final_mime_type = $detected_mime_type ?? $mime_type;

        if (!$this->is_valid_image_mime_type($final_mime_type)) {
            $this->cleanup_temp_file($tmp_file);

            return [
                'success' => false,
                'error' => 'Downloaded file is not a valid image',
            ];
        }

        return [
            'success' => true,
            'file' => $tmp_file,
            'mime_type' => $final_mime_type,
        ];
    }

    public function cleanup_temp_file(string $file): void
    {
        unset(self::$temp_files[$file]);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function check_url_accessibility(string $url): array
    {
        $response = $this->make_request_with_retry($url, 'HEAD');

        if (is_wp_error($response)) {
            return [
                'accessible' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);

        return [
            'accessible' => $code >= 200 && $code < 400,
            'status_code' => $code,
        ];
    }

    private function make_request_with_retry(string $url, string $method = 'GET'): array|\WP_Error
    {
        $last_error = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($this->resources->should_stop('request_retry_' . $attempt)) {
                break;
            }

            $response = $this->make_request($url, $method);

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                
                if ($code === 429 || $code === 503) {
                    $last_error = new \WP_Error('rate_limited', 'Rate limited by Wayback Machine');
                    $delay = self::RETRY_DELAYS[$attempt] ?? 8;
                    sleep($delay);
                    continue;
                }

                return $response;
            }

            $last_error = $response;
            $error_code = $response->get_error_code();

            if (in_array($error_code, ['http_request_failed', 'connect_error', 'stream_socket_client'])) {
                $delay = self::RETRY_DELAYS[$attempt] ?? 8;
                sleep($delay);
                continue;
            }

            break;
        }

        return $last_error ?: new \WP_Error('request_failed', 'Request failed after retries');
    }

    private function make_request(string $url, string $method = 'GET'): array|\WP_Error
    {
        $timeout = $this->timeout;

        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 3,
            'user-agent' => 'Wayback-Image-Restorer-WordPress/1.0',
            'sslverify' => true,
        ];

        if ($method === 'GET') {
            $args['headers'] = [
                'Accept' => '*/*',
            ];
        }

        return wp_safe_remote_request($url, $args);
    }

    private function query_cdx(string $url, int $limit = 1, ?string $target_date = null): array|\WP_Error
    {
        $api_url = $this->build_cdx_query_url($url, $limit, $target_date);
        $response = $this->make_request_with_retry($api_url);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data) || count($data) < 2) {
            return [];
        }

        array_shift($data);

        return is_array($data) ? $data : [];
    }

    private function build_archive_url(string $original_url, string $timestamp): string
    {
        $encoded_url = urlencode($original_url);
        return sprintf(
            'https://web.archive.org/web/%sid_/%s',
            $timestamp,
            $encoded_url
        );
    }

    private function format_date_for_cdx(string $date): ?string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }
        return gmdate('YmdHis', $timestamp);
    }

    private function build_cdx_query_url(string $url, int $limit, ?string $target_date = null): string
    {
        $query_parts = [
            'url=' . rawurlencode($url),
            'output=json',
            'filter=' . rawurlencode('statuscode:200'),
            'filter=' . rawurlencode('mimetype:image/.*'),
            'fl=' . rawurlencode('timestamp,original,statuscode,mimetype'),
            'limit=' . rawurlencode((string) $limit),
        ];

        if ($target_date !== null) {
            $date_formatted = $this->format_date_for_cdx($target_date);
            if ($date_formatted) {
                $query_parts[] = 'closest=' . rawurlencode($date_formatted);
            }
        }

        return self::CDX_API_URL . '?' . implode('&', $query_parts);
    }

    private function format_archive_record(array $record, string $lookup_url): array
    {
        $timestamp = $record[0] ?? '';
        $original = $record[1] ?? $lookup_url;

        return [
            'timestamp' => $timestamp,
            'original' => $original,
            'statuscode' => $record[2] ?? '200',
            'mimetype' => $record[3] ?? 'image/jpeg',
            'archive_url' => $this->build_archive_url($original, $timestamp),
            'lookup_url' => $lookup_url,
        ];
    }

    private function get_archive_lookup_candidates(string $url): array
    {
        $candidates = [$url];
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || !preg_match('/\.([a-z0-9]+)$/i', $path, $matches)) {
            return $candidates;
        }

        $current_extension = strtolower($matches[1]);
        $fallback_extensions = self::ARCHIVE_EXTENSION_FALLBACKS[$current_extension] ?? [];

        foreach ($fallback_extensions as $fallback_extension) {
            $candidate = preg_replace(
                '/\.' . preg_quote($current_extension, '/') . '(?=($|[?#]))/i',
                '.' . $fallback_extension,
                $url,
                1
            );

            if (is_string($candidate) && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function find_best_candidate_record(string $url, ?string $target_date = null): array|\WP_Error|null
    {
        $attempt_dates = [];

        if ($target_date !== null) {
            $attempt_dates[] = $target_date;
        }

        $attempt_dates[] = null;
        $last_error = null;

        foreach ($attempt_dates as $attempt_date) {
            $data = $this->query_cdx($url, 1, $attempt_date);

            if (is_wp_error($data)) {
                $last_error = $data;
                continue;
            }

            if (!empty($data[0])) {
                return $data[0];
            }
        }

        return $last_error;
    }

    private function resolve_lookup_target_date(
        string $url,
        ?string $fallback_target_date,
        bool $explicit_target_date = false
    ): ?string
    {
        if ($explicit_target_date && $fallback_target_date !== null && $fallback_target_date !== '') {
            return $fallback_target_date;
        }

        $upload_target_date = $this->extract_upload_target_date($url);

        if ($upload_target_date !== null) {
            return $upload_target_date;
        }

        return $fallback_target_date;
    }

    private function extract_upload_target_date(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if (!preg_match('#/wp-content/uploads/(\d{4})/(\d{2})/#', $path, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        if ($year < 1990 || $month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%04d-%02d-01 00:00:00', $year, $month);
    }

    private function rank_archive_record(array $archive, string $requested_url, ?string $target_date): array
    {
        $archive_timestamp = $this->parse_archive_timestamp($archive['timestamp'] ?? '');
        $target_timestamp = $target_date !== null ? strtotime($target_date) : false;

        return [
            'distance' => ($archive_timestamp !== null && $target_timestamp !== false)
                ? abs($archive_timestamp - $target_timestamp)
                : null,
            'timestamp' => $archive_timestamp ?? PHP_INT_MAX,
            'candidate_priority' => (($archive['lookup_url'] ?? '') === $requested_url) ? 0 : 1,
        ];
    }

    private function is_better_archive_rank(array $candidate_rank, ?array $current_best_rank): bool
    {
        if ($current_best_rank === null) {
            return true;
        }

        $candidate_distance = $candidate_rank['distance'];
        $current_distance = $current_best_rank['distance'];

        if ($candidate_distance !== null || $current_distance !== null) {
            if ($candidate_distance === null) {
                return false;
            }

            if ($current_distance === null) {
                return true;
            }

            if ($candidate_distance !== $current_distance) {
                return $candidate_distance < $current_distance;
            }
        }

        if ($candidate_rank['timestamp'] !== $current_best_rank['timestamp']) {
            return $candidate_rank['timestamp'] < $current_best_rank['timestamp'];
        }

        return $candidate_rank['candidate_priority'] < $current_best_rank['candidate_priority'];
    }

    private function parse_archive_timestamp(string $timestamp): ?int
    {
        if (!preg_match('/^\d{14}$/', $timestamp)) {
            return null;
        }

        $datetime = \DateTimeImmutable::createFromFormat('YmdHis', $timestamp, new \DateTimeZone('UTC'));
        if ($datetime === false) {
            return null;
        }

        return $datetime->getTimestamp();
    }

    private function parse_content_type(string $content_type): string
    {
        if (preg_match('/^([^;]+)/', $content_type, $matches)) {
            return trim($matches[1]);
        }
        return 'application/octet-stream';
    }

    private function is_valid_image_mime_type(string $mime_type): bool
    {
        $valid_types = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/tiff',
            'image/x-icon',
        ];

        return in_array($mime_type, $valid_types, true);
    }

    private function create_temp_file(string $content): string|false
    {
        if (strlen($content) > 10 * 1024 * 1024) {
            return false;
        }

        $tmp = get_temp_dir();
        $file = $tmp . 'wir_' . uniqid() . '.tmp';
        
        $result = file_put_contents($file, $content);
        if ($result === false) {
            return false;
        }

        $this->register_temp_file($file);

        return $file;
    }

    private function detect_downloaded_image_mime_type(string $file): ?string
    {
        if ($this->file_looks_like_svg($file)) {
            return 'image/svg+xml';
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime_type = @finfo_file($finfo, $file);
                finfo_close($finfo);

                if (is_string($mime_type) && $mime_type !== '') {
                    $mime_type = strtolower(trim($mime_type));
                    if ($mime_type === 'image/svg' || $mime_type === 'text/xml') {
                        return 'image/svg+xml';
                    }

                    if ($this->is_valid_image_mime_type($mime_type)) {
                        return $mime_type;
                    }
                }
            }
        }

        if (function_exists('getimagesize')) {
            $image_info = @getimagesize($file);
            if (is_array($image_info) && !empty($image_info['mime'])) {
                $mime_type = strtolower((string) $image_info['mime']);
                if ($this->is_valid_image_mime_type($mime_type)) {
                    return $mime_type;
                }
            }
        }

        return null;
    }

    private function file_looks_like_svg(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }

        $snippet = fread($handle, 4096);
        fclose($handle);

        if (!is_string($snippet) || $snippet === '') {
            return false;
        }

        $snippet = strtolower(ltrim($snippet));
        if (str_starts_with($snippet, '<?xml')) {
            return str_contains($snippet, '<svg');
        }

        return str_contains($snippet, '<svg');
    }

    private function register_temp_file(string $file): void
    {
        self::$temp_files[$file] = true;

        if (!self::$shutdown_cleanup_registered) {
            register_shutdown_function([self::class, 'cleanup_registered_temp_files']);
            self::$shutdown_cleanup_registered = true;
        }
    }

    public static function cleanup_registered_temp_files(): void
    {
        foreach (array_keys(self::$temp_files) as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        self::$temp_files = [];
    }
}
