<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit;
}

class PluginUpdateChecker
{
    private $updateChecker = null;
    private string $githubRepo = 'https://github.com/GeorgeWebDevCy/wayback-machine-image-finder';
    private string $pluginFile;
    private string $slug = 'wayback-image-restorer';

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    public function init(): void
    {
        add_action('admin_init', [$this, 'registerUpdateChecker']);
        add_filter('plugin_auto_update_setting_html', [$this, 'autoUpdateSettingHtml'], 10, 3);
    }

    public function registerUpdateChecker(): void
    {
        if (!class_exists(PucFactory::class)) {
            return;
        }

        $this->updateChecker = PucFactory::buildUpdateChecker(
            $this->githubRepo,
            $this->pluginFile,
            $this->slug
        );

        $this->updateChecker->setBranch('main');
    }

    public function autoUpdateSettingHtml(string $html, string $pluginFile, string $status): string
    {
        if ($pluginFile === plugin_basename($this->pluginFile)) {
            $html .= ' <span class="wir-update-info">';
            $html .= '<br><small>' . esc_html__('Updates managed via GitHub', 'wayback-image-restorer') . '</small>';
            $html .= '</span>';
        }
        return $html;
    }

    public function setGithubRepo(string $repo): void
    {
        $this->githubRepo = rtrim($repo, '/');
    }

    public function getGithubRepo(): string
    {
        return $this->githubRepo;
    }
}
