<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class Wayback_Image_Restorer
{
    protected static ?Wayback_Image_Restorer $instance = null;

    public string $plugin_name;
    public string $version;
    protected Loader $loader;
    protected static bool $init_complete = false;

    public static function instance(): Wayback_Image_Restorer
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->plugin_name = 'wayback-image-restorer';
        $this->version = defined('WIR_VERSION') ? WIR_VERSION : '1.0.10';
    }

    public function run(): void
    {
        if (self::$init_complete) {
            return;
        }
        self::$init_complete = true;

        $this->loader = new Loader();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->init_update_checker();
        $this->loader->run();
    }

    private function init_update_checker(): void
    {
        if (!class_exists(PucFactory::class)) {
            return;
        }

        $GLOBALS['wayback_image_restorer_update_checker'] = PucFactory::buildUpdateChecker(
            'https://github.com/GeorgeWebDevCy/wayback-machine-image-finder/',
            __DIR__ . '/../wayback-image-restorer.php',
            'wayback-image-restorer'
        );

        $GLOBALS['wayback_image_restorer_update_checker']->setBranch('main');
    }

    private function define_admin_hooks(): void
    {
        $admin = new Admin\Admin();
        $this->loader->add_action('admin_menu', $admin, 'add_admin_menu');
        $this->loader->add_action('admin_init', $admin, 'register_settings');
        $this->loader->add_action('admin_enqueue_scripts', $admin, 'enqueue_assets');

        $ajax = new Admin\Ajax();
        $this->loader->add_action('wp_ajax_wir_scan', $ajax, 'handle_scan');
        $this->loader->add_action('wp_ajax_wir_get_results', $ajax, 'handle_get_results');
        $this->loader->add_action('wp_ajax_wir_get_media_candidates', $ajax, 'handle_get_media_candidates');
        $this->loader->add_action('wp_ajax_wir_enrich_media_failures', $ajax, 'handle_enrich_media_failures');
        $this->loader->add_action('wp_ajax_wir_restore', $ajax, 'handle_restore');
        $this->loader->add_action('wp_ajax_wir_bulk_restore', $ajax, 'handle_bulk_restore');
        $this->loader->add_action('wp_ajax_wir_get_logs', $ajax, 'handle_get_logs');
        $this->loader->add_action('wp_ajax_wir_clear_logs', $ajax, 'handle_clear_logs');
        $this->loader->add_action('wp_ajax_wir_rotate_logs', $ajax, 'handle_rotate_logs');
        $this->loader->add_action('wp_ajax_wir_download_logs', $ajax, 'handle_download_logs');
    }

    private function define_public_hooks(): void
    {
        $public = new Public_\Public_();
        $this->loader->add_action('wp_enqueue_scripts', $public, 'enqueue_assets');
    }

    public function activate(): void
    {
        Activator::activate();
    }

    public function deactivate(): void
    {
        Deactivator::deactivate();
    }

    public function get_version(): string
    {
        return $this->version;
    }

    public function get_plugin_dir(): string
    {
        return plugin_dir_path(__DIR__ . '/../wayback-image-restorer.php');
    }

    public function get_plugin_url(): string
    {
        return plugin_dir_url(__DIR__ . '/../wayback-image-restorer.php');
    }
}
