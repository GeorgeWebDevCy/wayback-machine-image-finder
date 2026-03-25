<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wir_settings');
delete_option('wir_version');
delete_option('wir_last_scan_id');

$upload_dir = wp_upload_dir();
$logs_dir = $upload_dir['basedir'] . '/wayback-image-restorer/logs';

if (is_dir($logs_dir)) {
    $files = glob($logs_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($logs_dir);
}

$parent_dir = $upload_dir['basedir'] . '/wayback-image-restorer';
if (is_dir($parent_dir)) {
    @rmdir($parent_dir);
}

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wir_%'");
