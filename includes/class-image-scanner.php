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

    private string $site_url;
    private string $upload_dir;
    private string $scan_id;
    private Resource_Manager $resources;

    public function __construct(?Resource_Manager $resources = null)
    {
        $this->site_url = get_site_url();
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'];
        $this->scan_id = $this->generate_scan_id();
        $this->resources = $resources ?? new Resource_Manager();

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
                        $stats['images_broken']++;
                        
                        $existing_idx = $this->find_existing_broken($broken_images, $image['url']);
                        
                        if ($existing_idx !== null) {
                            $broken_images[$existing_idx]['referenced_in'][] = [
                                'post_id' => $post->ID,
                                'post_title' => $post->post_title,
                                'context' => $image['context'],
                            ];
                        } else {
                            $archive_info = $this->find_archive($image['url'], $post->post_date);
                            
                            $broken_images[] = [
                                'id' => count($broken_images) + 1,
                                'url' => $image['url'],
                                'type' => $this->get_image_type($image['url']),
                                'referenced_in' => [[
                                    'post_id' => $post->ID,
                                    'post_title' => $post->post_title,
                                    'context' => $image['context'],
                                ]],
                                'archive_found' => $archive_info !== null,
                                'archive_url' => $archive_info['archive_url'] ?? null,
                                'archive_timestamp' => $archive_info['timestamp'] ?? null,
                                'last_checked' => current_time('c'),
                            ];
                        }
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

            $offset += self::MAX_POSTS_PER_BATCH;

            $this->resources->pause();
        }

        $media_broken = $this->scan_media_library();
        $broken_images = array_merge($broken_images, $media_broken);
        $stats['images_broken'] = count($broken_images);

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        $result = [
            'scan_id' => $this->scan_id,
            'started_at' => date('c'),
            'completed_at' => date('c'),
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

        set_transient('wir_last_scan_' . $this->scan_id, $result, HOUR_IN_SECONDS);

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
        $type = $this->get_image_type($url);

        if ($type === 'local') {
            return $this->check_local_file_missing($url);
        }

        return $this->check_external_url_broken($url);
    }

    private function check_local_file_missing(string $url): bool
    {
        $relative_path = $this->get_relative_path_from_url($url);
        if ($relative_path === null) {
            return false;
        }

        $full_path = $this->upload_dir . '/' . ltrim($relative_path, '/');
        return !file_exists($full_path);
    }

    private function get_relative_path_from_url(string $url): ?string
    {
        $parsed = parse_url($url);
        $site_parsed = parse_url($this->site_url);

        $url_path = $parsed['path'] ?? '';
        $site_path = $site_parsed['path'] ?? '/';

        if (str_starts_with($url_path, $site_path)) {
            return substr($url_path, strlen($site_path));
        }

        if (str_starts_with($url_path, '/wp-content/')) {
            return ltrim($url_path, '/');
        }

        return null;
    }

    private function check_external_url_broken(string $url): bool
    {
        if ($this->resources->should_stop('check_external')) {
            return false;
        }

        $response = wp_safe_remote_head($url, [
            'timeout' => 10,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return true;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code >= 400;
    }

    private function scan_media_library(): array
    {
        if ($this->resources->should_stop('scan_media_start')) {
            return [];
        }

        $broken = [];

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 100,
            'post_status' => 'inherit',
        ]);

        $processed = 0;
        foreach ($attachments as $attachment) {
            if ($this->resources->should_stop('scan_media_item')) {
                break;
            }

            $file_path = get_attached_file($attachment->ID);
            
            if ($file_path && !file_exists($file_path)) {
                $url = wp_get_attachment_url($attachment->ID);
                $archive_info = $this->find_archive($url);

                $broken[] = [
                    'id' => time() . '_' . $attachment->ID,
                    'url' => $url,
                    'type' => 'local',
                    'referenced_in' => [[
                        'post_id' => $attachment->ID,
                        'post_title' => get_the_title($attachment->ID),
                        'context' => 'media_library',
                    ]],
                    'archive_found' => $archive_info !== null,
                    'archive_url' => $archive_info['archive_url'] ?? null,
                    'archive_timestamp' => $archive_info['timestamp'] ?? null,
                    'last_checked' => current_time('c'),
                ];
            }

            $processed++;
            if ($processed % 20 === 0) {
                $this->resources->pause();
            }
        }

        return $broken;
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
