<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer\Public_;

if (!defined('ABSPATH')) {
    exit;
}

final class Public_
{
    private string $plugin_name;
    private string $version;

    public function __construct()
    {
        $this->plugin_name = 'wayback-image-restorer';
        $this->version = defined('WIR_VERSION') ? WIR_VERSION : '1.0.5';
    }

    public function enqueue_assets(): void
    {
    }
}
