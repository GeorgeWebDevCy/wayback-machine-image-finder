<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Activator
{
    public static function activate(): void
    {
        self::create_logs_directory();
        self::init_settings();
        self::set_version();

        flush_rewrite_rules();
    }

    private static function create_logs_directory(): void
    {
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/wayback-image-restorer/logs';

        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);

            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<Files \"*.log\">\n";
            $htaccess_content .= "Order allow,deny\n";
            $htaccess_content .= "Deny from all\n";
            $htaccess_content .= "</Files>\n";

            @file_put_contents($logs_dir . '/.htaccess', $htaccess_content);
        }
    }

    private static function init_settings(): void
    {
        $defaults = [
            'dry_run' => true,
            'post_types' => ['post', 'page'],
            'date_from' => null,
            'date_to' => null,
            'log_max_size_mb' => 10,
            'log_max_age_days' => 30,
            'timeout_seconds' => 30,
            'restore_mode' => 'archive_then_original',
        ];

        if (get_option('wir_settings') === false) {
            add_option('wir_settings', $defaults);
        }
    }

    private static function set_version(): void
    {
        update_option('wir_version', defined('WIR_VERSION') ? WIR_VERSION : '1.0.15');
    }
}
