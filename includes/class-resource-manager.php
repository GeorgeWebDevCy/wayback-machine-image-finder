<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Resource_Manager
{
    private int $max_memory_mb;
    private int $max_execution_time;
    private int $batch_delay_ms;
    private bool $low_resource_mode;

    public function __construct()
    {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === '-1') {
            $this->max_memory_mb = 256;
        } else {
            $this->max_memory_mb = (int) filter_var($memory_limit, FILTER_SANITIZE_NUMBER_INT);
        }

        $this->max_execution_time = max(0, (int) ini_get('max_execution_time'));
        $this->batch_delay_ms = 500;
        $this->low_resource_mode = $this->detect_low_resource_server();
    }

    private function detect_low_resource_server(): bool
    {
        $memory_limit_mb = $this->max_memory_mb;
        
        if ($memory_limit_mb <= 64) {
            return true;
        }

        if ($this->max_execution_time > 0 && $this->max_execution_time <= 30) {
            return true;
        }

        if (defined('WPCOMSH_VERSION') || defined('IS_WPE') || isset($_SERVER['HTTP_X_ENGINE'])) {
            return true;
        }

        return false;
    }

    public function get_timeout(): int
    {
        if ($this->max_execution_time <= 0) {
            return 0;
        }

        $buffer = $this->low_resource_mode ? 3 : 5;
        $buffer = min($buffer, max(1, $this->max_execution_time - 1));
        $budget = max(1, $this->max_execution_time - $buffer);

        if ($this->low_resource_mode) {
            return min(15, $budget);
        }

        return min(30, $budget);
    }

    public function get_request_timeout(): int
    {
        $configured_timeout = (int) Settings::get('timeout_seconds', 30);
        $configured_timeout = max(5, min(120, $configured_timeout > 0 ? $configured_timeout : 30));

        if ($this->max_execution_time <= 0) {
            return $configured_timeout;
        }

        return min($configured_timeout, max(5, $this->max_execution_time - 1));
    }

    public function should_stop(string $reason = ''): bool
    {
        if ($this->memory_exhausted()) {
            if ($reason) {
                Logger::get_instance()->warning('resource_limit_reached', [
                    'reason' => 'memory_exhausted',
                    'detail' => $reason,
                ]);
            }
            return true;
        }

        if ($this->time_exhausted()) {
            if ($reason) {
                Logger::get_instance()->warning('resource_limit_reached', [
                    'reason' => 'time_exhausted',
                    'detail' => $reason,
                ]);
            }
            return true;
        }

        return false;
    }

    public function memory_exhausted(): bool
    {
        $memory_used = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit();
        
        if ($memory_limit <= 0) {
            return false;
        }

        $threshold = 0.85;
        if ($this->low_resource_mode) {
            $threshold = 0.75;
        }

        return ($memory_used / $memory_limit) > $threshold;
    }

    public function time_exhausted(): bool
    {
        if ($this->max_execution_time <= 0) {
            return false;
        }

        $timeout = $this->get_timeout();
        if ($timeout <= 0) {
            return false;
        }

        $start_time = defined('WIR_START_TIME') ? WIR_START_TIME : microtime(true);
        $elapsed = microtime(true) - $start_time;

        return $elapsed >= $timeout;
    }

    private function get_memory_limit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0;
        }
        return (int) filter_var($limit, FILTER_SANITIZE_NUMBER_INT) * 1024 * 1024;
    }

    public function get_batch_delay(): int
    {
        if ($this->low_resource_mode) {
            return 1000;
        }
        return $this->batch_delay_ms;
    }

    public function get_batch_size(): int
    {
        if ($this->low_resource_mode) {
            return 5;
        }
        return 10;
    }

    public function pause(): void
    {
        usleep($this->get_batch_delay() * 1000);
    }

    public function optimize(): void
    {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        gc_collect_cycles();
    }

    public function is_low_resource(): bool
    {
        return $this->low_resource_mode;
    }

    public function get_status(): array
    {
        return [
            'low_resource_mode' => $this->low_resource_mode,
            'memory_limit_mb' => $this->max_memory_mb,
            'memory_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'max_execution_time' => $this->max_execution_time,
            'timeout' => $this->get_timeout(),
            'request_timeout' => $this->get_request_timeout(),
            'batch_delay_ms' => $this->get_batch_delay(),
            'batch_size' => $this->get_batch_size(),
        ];
    }
}
