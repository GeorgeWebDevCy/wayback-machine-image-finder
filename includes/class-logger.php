<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

use DateTime;
use Exception;

final class Logger
{
    private const LOG_LEVELS = ['debug', 'info', 'success', 'warning', 'error'];
    
    private string $log_dir;
    private string $current_log_file;
    private static ?Logger $instance = null;

    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/wayback-image-restorer/logs';
        $this->ensure_log_directory();
        $this->current_log_file = $this->get_log_filename();
    }

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensure_log_directory(): void
    {
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }

    private function get_log_filename(?DateTime $date = null): string
    {
        $date = $date ?? new DateTime();
        return $this->log_dir . '/' . $date->format('Y-m') . '.log';
    }

    public function log(string $level, string $action, array $data = []): void
    {
        if (!in_array($level, self::LOG_LEVELS, true)) {
            $level = 'info';
        }

        $entry = [
            'timestamp' => (new DateTime())->format('c'),
            'level' => $level,
            'action' => $action,
        ];

        if (!empty($data)) {
            $entry = array_merge($entry, $data);
        }

        $line = wp_json_encode($entry) . "\n";

        @file_put_contents($this->current_log_file, $line, FILE_APPEND | LOCK_EX);

        $this->check_rotation();
    }

    public function debug(string $action, array $data = []): void
    {
        $this->log('debug', $action, $data);
    }

    public function info(string $action, array $data = []): void
    {
        $this->log('info', $action, $data);
    }

    public function success(string $action, array $data = []): void
    {
        $this->log('success', $action, $data);
    }

    public function warning(string $action, array $data = []): void
    {
        $this->log('warning', $action, $data);
    }

    public function error(string $action, array $data = []): void
    {
        $this->log('error', $action, $data);
    }

    public function get_logs(array $args = []): array
    {
        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'level' => 'all',
            'action' => 'all',
            'search' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $all_logs = $this->read_logs();
        
        if ($args['level'] !== 'all') {
            $all_logs = array_filter($all_logs, fn($log) => ($log['level'] ?? '') === $args['level']);
        }

        if ($args['action'] !== 'all') {
            $all_logs = array_filter($all_logs, fn($log) => ($log['action'] ?? '') === $args['action']);
        }

        if (!empty($args['search'])) {
            $search = strtolower($args['search']);
            $all_logs = array_filter($all_logs, function ($log) use ($search) {
                $text = json_encode($log);
                return str_contains(strtolower($text), $search);
            });
        }

        $all_logs = array_values($all_logs);
        $total = count($all_logs);
        $total_pages = ceil($total / $args['per_page']);
        
        $offset = ($args['page'] - 1) * $args['per_page'];
        $logs = array_slice($all_logs, $offset, $args['per_page']);

        return [
            'logs' => $logs,
            'total' => $total,
            'total_pages' => $total_pages,
        ];
    }

    private function read_logs(): array
    {
        $logs = [];
        
        if (!file_exists($this->current_log_file)) {
            return $logs;
        }

        $handle = fopen($this->current_log_file, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $logs[] = $entry;
                }
            }
            fclose($handle);
        }

        return array_reverse($logs);
    }

    public function get_log_stats(): array
    {
        $file = $this->current_log_file;
        $size = file_exists($file) ? filesize($file) : 0;
        $entries = 0;

        if (file_exists($file)) {
            $handle = fopen($file, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    if (trim($line)) {
                        $entries++;
                    }
                }
                fclose($handle);
            }
        }

        $last_entry = null;
        $logs = $this->read_logs();
        if (!empty($logs)) {
            $last_entry = $logs[0];
        }

        return [
            'filename' => basename($file),
            'size' => $size,
            'size_formatted' => size_format($size),
            'entries' => $entries,
            'last_entry' => $last_entry,
        ];
    }

    public function clear_logs(): int
    {
        $files = glob($this->log_dir . '/*.log*');
        $count = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }

    public function rotate_logs(): array
    {
        $result = ['archived' => 0, 'deleted' => 0];

        if (file_exists($this->current_log_file)) {
            $new_file = $this->current_log_file . '.gz';
            $content = file_get_contents($this->current_log_file);
            
            if ($content !== false) {
                $compressed = gzencode($content, 9);
                if ($compressed !== false) {
                    file_put_contents($new_file, $compressed);
                    @unlink($this->current_log_file);
                    $result['archived'] = 1;
                }
            }
        }

        $result['deleted'] = $this->cleanup_old_archives();

        return $result;
    }

    private function cleanup_old_archives(): int
    {
        $max_age = (int) Settings::get('log_max_age_days', 30);
        $cutoff = strtotime("-{$max_age} days");
        $deleted = 0;

        $files = glob($this->log_dir . '/*.log.gz');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function check_rotation(): void
    {
        $max_size = (int) Settings::get('log_max_size_mb', 10) * 1024 * 1024;
        $max_age = (int) Settings::get('log_max_age_days', 30);

        if (filesize($this->current_log_file) > $max_size) {
            $this->rotate_logs();
        }

        $age = $this->get_log_file_age_days();
        if ($age > $max_age) {
            $this->rotate_logs();
        }
    }

    private function get_log_file_age_days(): int
    {
        if (!file_exists($this->current_log_file)) {
            return 0;
        }

        $mtime = filemtime($this->current_log_file);
        $now = time();
        return (int)(($now - $mtime) / DAY_IN_SECONDS);
    }

    public function export_to_csv(): string
    {
        $logs = $this->read_logs();
        $output = fopen('php://temp', 'r+');

        fputcsv($output, ['Timestamp', 'Level', 'Action', 'Details']);

        foreach ($logs as $log) {
            $details = [];
            unset($log['timestamp'], $log['level'], $log['action']);
            
            foreach ($log as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $details[] = "{$key}: {$value}";
            }

            fputcsv($output, [
                $log['timestamp'] ?? '',
                $log['level'] ?? '',
                $log['action'] ?? '',
                implode('; ', $details),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
