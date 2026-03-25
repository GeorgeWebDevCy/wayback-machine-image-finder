<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

function wir_get_scan_results(string $scan_id): ?array
{
    return get_transient('wir_last_scan_' . $scan_id);
}

function wir_save_scan_results(string $scan_id, array $results): bool
{
    return set_transient('wir_last_scan_' . $scan_id, $results, HOUR_IN_SECONDS);
}

function wir_get_settings(): array
{
    return Settings::get();
}

function wir_update_settings(array $settings): bool
{
    return Settings::save($settings);
}

function wir_log(string $level, string $action, array $data = []): void
{
    $logger = Logger::get_instance();
    $logger->log($level, $action, $data);
}

function wir_get_log_stats(): array
{
    $logger = Logger::get_instance();
    return $logger->get_log_stats();
}
