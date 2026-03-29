<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Image_Scanner
{
    private const IMG_TAG_REGEX = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';
    private const SRCSET_REGEX = '/srcset=["\']([^"\']+)["\']/i';
    private const MAX_POSTS_PER_BATCH = 50;
    private const MAX_ATTACHMENTS_PER_BATCH = 100;

    private string $site_url;
    private string $upload_dir;
    private string $upload_base_url;
    private string $scan_id;
    private Resource_Manager $resources;
    private State_Store $state_store;
    private array $broken_url_cache = [];

    public function __construct(?Resource_Manager $resources = null)
    {
        $this->site_url = get_site_url();
        $upload = wp_upload_dir();
        $this->upload_dir = (string) ($upload['basedir'] ?? '');
        $this->upload_base_url = rtrim((string) ($upload['baseurl'] ?? ''), '/');
        $this->scan_id = $this->generate_scan_id();
        $this->resources = $resources ?? new Resource_Manager();
        $this->state_store = new State_Store();

        if (!defined('WIR_START_TIME')) {
            define('WIR_START_TIME', microtime(true));
        }
    }

    private function generate_scan_id(): string
    {
        return substr(md5(uniqid((string) wp_rand(), true)), 0, 12);
    }

    public function get_scan_id(): string
    {
        return $this->scan_id;
    }

    public function scan(array $args = []): array
    {
        $defaults = [
            'post_types' => Settings::get('post_types', ['post', 'page']),
            'date_from' => Settings::get('date_from'),
            'date_to' => Settings::get('date_to'),
            'dry_run' => Settings::get('dry_run', true),
        ];
        $args = wp_parse_args($args, $defaults);

        $logger = Logger::get_instance();
        $started_at = wp_date('c');
        $logger->info('scan_start', [
            'scan_id' => $this->scan_id,
            'dry_run' => $args['dry_run'],
            'filters' => $args,
            'resource_status' => $this->resources->get_status(),
        ]);

        $start_time = microtime(true);

        $broken_images = [];
        $stats = [
            'posts_scanned' => 0,
            'images_found' => 0,
            'images_broken' => 0,
            'images_ok' => 0,
            'scan_stopped_early' => false,
            'stop_reason' => null,
        ];

        $total_posts = $this->count_posts($args['post_types'], $args['date_from'], $args['date_to']);
        $processed_posts = 0;

        $logger->info('scan_posts_started', [
            'scan_id' => $this->scan_id,
            'total_posts' => $total_posts,
        ]);

        $offset = 0;
        while ($processed_posts < $total_posts) {
            if ($this->resources->should_stop('scan_batch')) {
                $stats['scan_stopped_early'] = true;
                $stats['stop_reason'] = 'resource_limit';
                $logger->warning('scan_stopped_early', [
                    'processed_posts' => $processed_posts,
                    'total_posts' => $total_posts,
                    'resource_status' => $this->resources->get_status(),
                ]);
                break;
            }

            $posts = $this->get_posts_batch(
                $args['post_types'],
                $args['date_from'],
                $args['date_to'],
                self::MAX_POSTS_PER_BATCH,
                $offset
            );

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $post_images = $this->extract_images_from_post($post);
                
                foreach ($post_images as $image) {
                    $stats['images_found']++;
                    
                    if ($this->resources->should_stop('check_image')) {
                        $stats['scan_stopped_early'] = true;
                        $stats['stop_reason'] = 'resource_limit';
                        break 2;
                    }
                    
                    $is_broken = $this->check_image_broken($image['url']);
                    
                    if ($is_broken) {
                        $this->record_broken_image(
                            $broken_images,
                            $image['url'],
                            (int) $post->ID,
                            $post->post_title,
                            $image['context'],
                            $post->post_date
                        );
                    } else {
                        $stats['images_ok']++;
                    }
                }

                $processed_posts++;
                
                if ($processed_posts % 10 === 0) {
                    $this->resources->optimize();
                    $this->resources->pause();
                }
            }

            if ($stats['scan_stopped_early']) {
                break;
            }

            $offset += self::MAX_POSTS_PER_BATCH;

            $this->resources->pause();
        }

        $stats['posts_scanned'] = $processed_posts;

        $logger->info('scan_posts_complete', [
            'scan_id' => $this->scan_id,
            'posts_scanned' => $processed_posts,
            'images_found' => $stats['images_found'],
        ]);

        $logger->info('scan_media_started', [
            'scan_id' => $this->scan_id,
        ]);
        $this->scan_media_library($broken_images);
        $stats['images_broken'] = count($broken_images);

        $logger->info('scan_media_complete', [
            'scan_id' => $this->scan_id,
            'broken_images' => $stats['images_broken'],
        ]);

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);
        $completed_at = wp_date('c');

        $result = [
            'scan_id' => $this->scan_id,
            'started_at' => $started_at,
            'completed_at' => $completed_at,
            'duration_seconds' => $duration,
            'dry_run' => $args['dry_run'],
            'filters' => [
                'post_types' => $args['post_types'],
                'date_range' => [
                    'from' => $args['date_from'],
                    'to' => $args['date_to'],
                ],
            ],
            'stats' => $stats,
            'broken_images' => array_values($broken_images),
        ];

        $this->state_store->save_scan_results($result, null, true);

        $logger->info('scan_complete', [
            'scan_id' => $this->scan_id,
            'found_broken' => $stats['images_broken'],
            'dry_run' => $args['dry_run'],
            'duration_seconds' => $duration,
            'scan_stopped_early' => $stats['scan_stopped_early'],
        ]);

        return $result;
    }

    private function count_posts(array $post_types, ?string $date_from, ?string $date_to): int
    {
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ];

        if ($date_from) {
            $args['date_query'][] = ['after' => $date_from, 'inclusive' => true];
        }
        if ($date_to) {
            $args['date_query'][] = ['before' => $date_to, 'inclusive' => true];
        }
        if (count($args['date_query'] ?? []) > 1) {
            $args['date_query']['relation'] = 'AND';
        } elseif (isset($args['date_query'][0])) {
            $args['date_query'] = $args['date_query'][0];
        } else {
            unset($args['date_query']);
        }

        $query = new \WP_Query($args);
        return (int) $query->found_posts;
    }

    private function get_posts_batch(array $post_types, ?string $date_from, ?string $date_to, int $limit, int $offset): array
    {
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
        ];

        if ($date_from) {
            $args['date_query'][] = ['after' => $date_from, 'inclusive' => true];
        }
        if ($date_to) {
            $args['date_query'][] = ['before' => $date_to, 'inclusive' => true];
        }
        if (count($args['date_query'] ?? []) > 1) {
            $args['date_query']['relation'] = 'AND';
        } elseif (isset($args['date_query'][0])) {
            $args['date_query'] = $args['date_query'][0];
        } else {
            unset($args['date_query']);
        }

        return get_posts($args);
    }

    private function record_broken_image(
        array &$broken_images,
        string $url,
        int $post_id,
        string $post_title,
        string $context,
        ?string $target_date = null
    ): void {
        $reference = [
            'post_id' => $post_id,
            'post_title' => $post_title,
            'context' => $context,
        ];

        $existing_idx = $this->find_existing_broken($broken_images, $url);
        if ($existing_idx !== null) {
            foreach ($broken_images[$existing_idx]['referenced_in'] as $existing_reference) {
                if (
                    (int) ($existing_reference['post_id'] ?? 0) === $post_id &&
                    ($existing_reference['context'] ?? '') === $context
                ) {
                    return;
                }
            }

            $broken_images[$existing_idx]['referenced_in'][] = $reference;
            return;
        }

        $archive_info = $this->find_archive($url, $target_date);

        $broken_images[] = [
            'id' => count($broken_images) + 1,
            'url' => $url,
            'type' => $this->get_image_type($url),
            'referenced_in' => [$reference],
            'target_date' => $target_date,
            'archive_found' => $archive_info !== null,
            'archive_url' => $archive_info['archive_url'] ?? null,
            'archive_timestamp' => $archive_info['timestamp'] ?? null,
            'last_checked' => current_time('c'),
        ];
    }

    private function extract_images_from_post(\WP_Post $post): array
    {
        $images = [];

        if (!empty($post->post_content)) {
            $content_images = $this->extract_images_from_html($post->post_content, 'content');
            $images = array_merge($images, $content_images);
        }

        $featured_id = get_post_thumbnail_id($post->ID);
        if ($featured_id) {
            $featured_url = wp_get_attachment_url($featured_id);
            if ($featured_url) {
                $images[] = [
                    'url' => $featured_url,
                    'context' => 'featured',
                    'post_id' => $post->ID,
                ];
            }
        }

        return $images;
    }

    private function extract_images_from_html(string $html, string $context): array
    {
        $images = [];

        if (preg_match_all(self::IMG_TAG_REGEX, $html, $matches)) {
            foreach ($matches[1] as $url) {
                $images[] = [
                    'url' => $url,
                    'context' => $context,
                ];
            }
        }

        if (preg_match_all(self::SRCSET_REGEX, $html, $srcset_matches)) {
            foreach ($srcset_matches[1] as $srcset) {
                $srcset_urls = $this->parse_srcset($srcset);
                foreach ($srcset_urls as $url) {
                    $exists = array_filter($images, fn($img) => $img['url'] === $url);
                    if (empty($exists)) {
                        $images[] = [
                            'url' => $url,
                            'context' => $context . '_srcset',
                        ];
                    }
                }
            }
        }

        return $images;
    }

    private function parse_srcset(string $srcset): array
    {
        $urls = [];
        $parts = preg_split('/\s*,\s*/', $srcset);

        foreach ($parts as $part) {
            $tokens = preg_split('/\s+/', trim($part));
            if (!empty($tokens[0])) {
                $urls[] = $tokens[0];
            }
        }

        return $urls;
    }

    private function get_image_type(string $url): string
    {
        $parsed = parse_url($url);
        $site_parsed = parse_url($this->site_url);

        if (isset($parsed['host']) && isset($site_parsed['host'])) {
            if ($parsed['host'] === $site_parsed['host']) {
                return 'local';
            }
        }

        return 'external';
    }

    private function check_image_broken(string $url): bool
    {
        if (array_key_exists($url, $this->broken_url_cache)) {
            return $this->broken_url_cache[$url];
        }

        $type = $this->get_image_type($url);

        $is_broken = $type === 'local'
            ? $this->check_local_file_missing($url)
            : $this->check_external_url_broken($url);

        $this->broken_url_cache[$url] = $is_broken;

        return $is_broken;
    }

    private function check_local_file_missing(string $url): bool
    {
        $relative_path = $this->get_relative_path_from_url($url);
        if ($relative_path !== null && $this->upload_dir !== '') {
            $full_path = wp_normalize_path(trailingslashit($this->upload_dir) . ltrim($relative_path, '/'));
            if (!file_exists($full_path)) {
                return true;
            }
        }

        return $this->check_http_url_broken($url, 'check_local');
    }

    private function get_relative_path_from_url(string $url): ?string
    {
        $url_path = parse_url($url, PHP_URL_PATH);
        $upload_base_path = parse_url($this->upload_base_url, PHP_URL_PATH);

        if (!is_string($url_path) || !is_string($upload_base_path) || $upload_base_path === '') {
            return null;
        }

        $normalized_upload_base_path = rtrim($upload_base_path, '/');
        if ($normalized_upload_base_path === '') {
            return null;
        }

        if ($url_path === $normalized_upload_base_path) {
            return '';
        }

        $prefix = $normalized_upload_base_path . '/';
        if (!str_starts_with($url_path, $prefix)) {
            return null;
        }

        return ltrim(substr($url_path, strlen($prefix)), '/');
    }

    private function check_external_url_broken(string $url): bool
    {
        return $this->check_http_url_broken($url, 'check_external');
    }

    private function check_http_url_broken(string $url, string $resource_reason): bool
    {
        if ($this->resources->should_stop($resource_reason)) {
            return false;
        }

        $head_response = $this->make_http_image_check_request($url, 'HEAD');
        if (!is_wp_error($head_response)) {
            $head_result = $this->interpret_http_image_response($head_response);
            if ($head_result !== null) {
                return !$head_result;
            }
        }

        if ($this->resources->should_stop($resource_reason . '_get')) {
            return false;
        }

        $get_response = $this->make_http_image_check_request($url, 'GET');
        if (is_wp_error($get_response)) {
            return true;
        }

        $get_result = $this->interpret_http_image_response($get_response, true);

        return $get_result !== true;
    }

    private function make_http_image_check_request(string $url, string $method): array|\WP_Error
    {
        $args = [
            'method' => $method,
            'timeout' => 10,
            'redirection' => 3,
            'sslverify' => true,
            'user-agent' => 'Wayback-Image-Restorer-WordPress/1.0',
        ];

        if ($method === 'GET') {
            $args['headers'] = [
                'Accept' => '*/*',
            ];
            $args['limit_response_size'] = 1024;
        }

        return wp_safe_remote_request($url, $args);
    }

    private function interpret_http_image_response(array $response, bool $allow_body_sniff = false): ?bool
    {
        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 400) {
            return false;
        }

        $content_type = $this->normalize_content_type((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($content_type !== '') {
            return $this->is_image_content_type($content_type);
        }

        if (!$allow_body_sniff) {
            return null;
        }

        $body = ltrim((string) wp_remote_retrieve_body($response));
        if ($body === '') {
            return null;
        }

        $body_preview = strtolower(substr($body, 0, 256));
        if (str_contains($body_preview, '<svg')) {
            return true;
        }

        return $body[0] !== '<';
    }

    private function normalize_content_type(string $content_type): string
    {
        if (preg_match('/^([^;]+)/', $content_type, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return '';
    }

    private function is_image_content_type(string $content_type): bool
    {
        return str_starts_with($content_type, 'image/') || $content_type === 'application/octet-stream';
    }

    private function scan_media_library(array &$broken_images): void
    {
        if ($this->resources->should_stop('scan_media_start')) {
            return;
        }

        $offset = 0;
        $processed = 0;
        while (true) {
            if ($this->resources->should_stop('scan_media_batch')) {
                break;
            }

            $attachments = get_posts([
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => self::MAX_ATTACHMENTS_PER_BATCH,
                'post_status' => 'inherit',
                'orderby' => 'ID',
                'order' => 'ASC',
                'offset' => $offset,
            ]);

            if (empty($attachments)) {
                break;
            }

            foreach ($attachments as $attachment) {
                if ($this->resources->should_stop('scan_media_item')) {
                    return;
                }

                $file_path = get_attached_file($attachment->ID);
                $url = wp_get_attachment_url($attachment->ID);

                if ($url && (!$file_path || !file_exists($file_path) || $this->check_image_broken($url))) {
                    $this->record_broken_image(
                        $broken_images,
                        $url,
                        (int) $attachment->ID,
                        get_the_title($attachment->ID),
                        'media_library',
                        $attachment->post_date
                    );
                }

                $processed++;
                if ($processed % 20 === 0) {
                    $this->resources->pause();
                }
            }

            $offset += self::MAX_ATTACHMENTS_PER_BATCH;

            if (count($attachments) < self::MAX_ATTACHMENTS_PER_BATCH) {
                break;
            }

            $this->resources->pause();
        }
    }

    private function find_existing_broken(array $broken_images, string $url): ?int
    {
        foreach ($broken_images as $index => $image) {
            if ($image['url'] === $url) {
                return $index;
            }
        }
        return null;
    }

    private function find_archive(string $url, ?string $target_date = null): ?array
    {
        if ($this->resources->should_stop('find_archive')) {
            return null;
        }

        $api = new Wayback_Api(null, $this->resources);
        return $api->find_archive($url, $target_date);
    }
}
