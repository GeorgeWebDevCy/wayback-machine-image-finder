<?php

declare(strict_types=1);

/**
 * Plugin Name:       Wayback Image Restorer
 * Plugin URI:        https://github.com/GeorgeWebDevCy/wayback-machine-image-finder
 * Description:       Find missing images and restore them from the Wayback Machine
 * Version:           1.0.12
 * Update URI:        https://github.com/GeorgeWebDevCy/wayback-machine-image-finder
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            George Nicolaou
 * Author URI:        https://profiles.wordpress.org/orionaselite/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wayback-image-restorer
 * Domain Path:       /languages
 */

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WIR_VERSION')) {
    define('WIR_VERSION', '1.0.12');
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/class-loader.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-resource-manager.php';
require_once __DIR__ . '/includes/class-wayback-api.php';
require_once __DIR__ . '/includes/class-image-scanner.php';
require_once __DIR__ . '/includes/class-image-restorer.php';
require_once __DIR__ . '/includes/class-plugin.php';

require_once __DIR__ . '/admin/class-admin.php';
require_once __DIR__ . '/admin/class-admin-ajax.php';

require_once __DIR__ . '/public/class-public.php';

function wir(): Wayback_Image_Restorer
{
    return Wayback_Image_Restorer::instance();
}

function wir_run(): void
{
    $plugin = wir();
    $plugin->run();
}

add_action('plugins_loaded', 'Wayback_Image_Restorer\wir_run', 0);

register_activation_hook(__FILE__, function () {
    require_once __DIR__ . '/includes/class-activator.php';
    Activator::activate();
});

register_deactivation_hook(__FILE__, function () {
    require_once __DIR__ . '/includes/class-deactivator.php';
    Deactivator::deactivate();
});
