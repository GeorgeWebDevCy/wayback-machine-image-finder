<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Wayback_Api
{
    private const CDX_API_URL = 'https://web.archive.org/cdx/search/cdx';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [2, 4, 8];
    private const ARCHIVE_EXTENSION_FALLBACKS = [
        'webp' => ['png', 'jpg', 'jpeg'],
        'jpg' => ['jpeg'],
        'jpeg' => ['jpg'],
    ];

    private int $timeout;
    private Resource_Manager $resources;

    public function __construct(?int $timeout = null, ?Resource_Manager $resources = null)
    {
        $this->resources = $resources ?? new Resource_Manager();
        $this->timeout = $timeout ?? $this->resources->get_request_timeout();
    }

    public function find_archive(string $url, ?string $target_date = null): ?array
    {
        if ($this->resources->should_stop('find_archive_start')) {
            return null;
        }

        $last_error = null;

        foreach ($this->get_archive_lookup_candidates($url) as $candidate_url) {
            $data = $this->query_cdx($candidate_url, 1, $target_date);

            if (is_wp_error($data)) {
                $last_error = $data;
                continue;
            }

            if (!empty($data[0])) {
                return $this->format_archive_record($data[0], $candidate_url);
            }
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

    public function download_image(string $archive_url): array
    {
        if ($this->resources->should_stop('download_image_start')) {
            return [
                'success' => false,
                'error' => 'Resource limit reached',
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
            ];
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $mime_type = $this->parse_content_type($content_type);

        if (!$this->is_valid_image_mime_type($mime_type)) {
            return [
                'success' => false,
                'error' => 'Invalid image type: ' . $mime_type,
            ];
        }

        $tmp_file = $this->create_temp_file($body);
        if ($tmp_file === false) {
            return [
                'success' => false,
                'error' => 'Failed to create temp file',
            ];
        }

        return [
            'success' => true,
            'file' => $tmp_file,
            'mime_type' => $mime_type,
        ];
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
        return date('Ymd', $timestamp);
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
                $query_parts[] = 'from=' . rawurlencode($date_formatted);
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

        return $file;
    }
}
