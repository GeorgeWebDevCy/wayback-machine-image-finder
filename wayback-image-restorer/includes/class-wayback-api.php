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

    private int $timeout;
    private Resource_Manager $resources;

    public function __construct(?int $timeout = null, ?Resource_Manager $resources = null)
    {
        $this->resources = $resources ?? new Resource_Manager();
        $this->timeout = $timeout ?? $this->resources->get_timeout();
    }

    public function find_archive(string $url, ?string $target_date = null): ?array
    {
        if ($this->resources->should_stop('find_archive_start')) {
            return null;
        }

        $params = [
            'url' => $url,
            'output' => 'json',
            'filter' => 'statuscode:200',
            'filter' => 'mimetype:image/.*',
            'fl' => 'timestamp,original,statuscode,mimetype',
            'limit' => 1,
        ];

        if ($target_date !== null) {
            $date_formatted = $this->format_date_for_cdx($target_date);
            if ($date_formatted) {
                $params['from'] = $date_formatted;
            }
        }

        $api_url = add_query_arg($params, self::CDX_API_URL);
        $response = $this->make_request_with_retry($api_url);

        if (is_wp_error($response)) {
            Logger::get_instance()->warning('wayback_api_error', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data) || count($data) < 2) {
            return null;
        }

        array_shift($data);

        if (empty($data[0])) {
            return null;
        }

        return [
            'timestamp' => $data[0][0] ?? '',
            'original' => $data[0][1] ?? $url,
            'statuscode' => $data[0][2] ?? '200',
            'mimetype' => $data[0][3] ?? 'image/jpeg',
            'archive_url' => $this->build_archive_url($url, $data[0][0] ?? ''),
        ];
    }

    public function find_archives(string $url, int $limit = 10): array
    {
        if ($this->resources->should_stop('find_archives_start')) {
            return [];
        }

        $params = [
            'url' => $url,
            'output' => 'json',
            'filter' => 'statuscode:200',
            'filter' => 'mimetype:image/.*',
            'fl' => 'timestamp,original,statuscode,mimetype',
            'limit' => $limit,
        ];

        $api_url = add_query_arg($params, self::CDX_API_URL);
        $response = $this->make_request_with_retry($api_url);

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data) || count($data) < 2) {
            return [];
        }

        array_shift($data);

        $archives = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item[0])) {
                continue;
            }

            $archives[] = [
                'timestamp' => $item[0] ?? '',
                'original' => $item[1] ?? $url,
                'statuscode' => $item[2] ?? '200',
                'mimetype' => $item[3] ?? 'image/jpeg',
                'archive_url' => $this->build_archive_url($url, $item[0] ?? ''),
            ];
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
