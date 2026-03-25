<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Deactivator
{
    public static function deactivate(): void
    {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('wir_auto_rotate_logs');
    }
}
