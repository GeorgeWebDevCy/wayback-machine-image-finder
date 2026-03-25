<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    private static ?array $settings = null;

    public static function get(string $key = null, mixed $default = null): mixed
    {
        if (self::$settings === null) {
            self::$settings = get_option('wir_settings', []);
            if (!is_array(self::$settings)) {
                self::$settings = [];
            }
        }

        if ($key === null) {
            return self::$settings;
        }

        return self::$settings[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): bool
    {
        self::$settings = null;
        $settings = self::get();
        $settings[$key] = $value;
        return update_option('wir_settings', $settings);
    }

    public static function save(array $settings): bool
    {
        self::$settings = null;
        return update_option('wir_settings', $settings);
    }

    public static function get_all_post_types(): array
    {
        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);
        return array_values($types);
    }

    public static function get_restore_modes(): array
    {
        return [
            'archive_only' => __('Archive Only', 'wayback-image-restorer'),
            'original_only' => __('Original Only', 'wayback-image-restorer'),
            'archive_then_original' => __('Archive then Original', 'wayback-image-restorer'),
            'original_then_archive' => __('Original then Archive', 'wayback-image-restorer'),
        ];
    }
}
