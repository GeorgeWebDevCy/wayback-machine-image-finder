<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    private string $plugin_name;
    private string $version;

    public function __construct()
    {
        $this->plugin_name = 'wayback-image-restorer';
        $this->version = defined('WIR_VERSION') ? WIR_VERSION : '1.0.16';
    }

    public function add_admin_menu(): void
    {
        add_menu_page(
            __('Wayback Image Restorer', 'wayback-image-restorer'),
            __('Wayback Image Restorer', 'wayback-image-restorer'),
            'manage_options',
            'wayback-image-restorer',
            [$this, 'render_admin_page'],
            'dashicons-format-image',
            58
        );
    }

    public function register_settings(): void
    {
        register_setting('wir_settings_group', 'wir_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section(
            'wir_scan_section',
            __('Scan Settings', 'wayback-image-restorer'),
            '__return_false',
            'wayback-image-restorer'
        );

        add_settings_field(
            'wir_dry_run',
            __('Dry Run Mode', 'wayback-image-restorer'),
            [$this, 'render_dry_run_field'],
            'wayback-image-restorer',
            'wir_scan_section'
        );

        add_settings_field(
            'wir_post_types',
            __('Post Types', 'wayback-image-restorer'),
            [$this, 'render_post_types_field'],
            'wayback-image-restorer',
            'wir_scan_section'
        );

        add_settings_field(
            'wir_date_range',
            __('Date Range', 'wayback-image-restorer'),
            [$this, 'render_date_range_field'],
            'wayback-image-restorer',
            'wir_scan_section'
        );

        add_settings_field(
            'wir_timeout',
            __('HTTP Timeout', 'wayback-image-restorer'),
            [$this, 'render_timeout_field'],
            'wayback-image-restorer',
            'wir_scan_section'
        );

        add_settings_section(
            'wir_wayback_section',
            __('Wayback Settings', 'wayback-image-restorer'),
            '__return_false',
            'wayback-image-restorer'
        );

        add_settings_field(
            'wir_restore_mode',
            __('Restore Mode', 'wayback-image-restorer'),
            [$this, 'render_restore_mode_field'],
            'wayback-image-restorer',
            'wir_wayback_section'
        );

        add_settings_section(
            'wir_log_section',
            __('Log Settings', 'wayback-image-restorer'),
            '__return_false',
            'wayback-image-restorer'
        );

        add_settings_field(
            'wir_log_max_size',
            __('Max Log Size (MB)', 'wayback-image-restorer'),
            [$this, 'render_log_max_size_field'],
            'wayback-image-restorer',
            'wir_log_section'
        );

        add_settings_field(
            'wir_log_max_age',
            __('Max Log Age (days)', 'wayback-image-restorer'),
            [$this, 'render_log_max_age_field'],
            'wayback-image-restorer',
            'wir_log_section'
        );
    }

    public function sanitize_settings(array $input): array
    {
        $sanitized = [];

        $sanitized['dry_run'] = !empty($input['dry_run']);
        $sanitized['post_types'] = isset($input['post_types']) && is_array($input['post_types'])
            ? array_map('sanitize_text_field', $input['post_types'])
            : ['post', 'page'];
        $sanitized['date_from'] = isset($input['date_from']) && !empty($input['date_from'])
            ? sanitize_text_field($input['date_from'])
            : null;
        $sanitized['date_to'] = isset($input['date_to']) && !empty($input['date_to'])
            ? sanitize_text_field($input['date_to'])
            : null;
        $sanitized['timeout_seconds'] = isset($input['timeout_seconds'])
            ? max(5, min(120, (int) $input['timeout_seconds']))
            : 30;
        $sanitized['restore_mode'] = isset($input['restore_mode']) && in_array($input['restore_mode'], [
            'archive_only', 'original_only', 'archive_then_original', 'original_then_archive'
        ], true)
            ? $input['restore_mode']
            : 'archive_then_original';
        $sanitized['log_max_size_mb'] = isset($input['log_max_size_mb'])
            ? max(1, min(100, (int) $input['log_max_size_mb']))
            : 10;
        $sanitized['log_max_age_days'] = isset($input['log_max_age_days'])
            ? max(1, min(365, (int) $input['log_max_age_days']))
            : 30;

        return $sanitized;
    }

    public function enqueue_assets(string $hook): void
    {
        if (str_contains($hook, 'wayback-image-restorer') === false) {
            return;
        }

        $plugin_url = plugin_dir_url(__DIR__ . '/../wayback-image-restorer.php');

        wp_enqueue_style(
            'wir-admin',
            $plugin_url . 'admin/css/wayback-image-restorer-admin.css',
            [],
            $this->version
        );

        wp_enqueue_script(
            'wir-admin',
            $plugin_url . 'admin/js/wayback-image-restorer-admin.js',
            ['jquery'],
            $this->version,
            true
        );

        wp_localize_script('wir-admin', 'wirData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wir_nonce'),
            'strings' => [
                'scanning' => __('Scanning...', 'wayback-image-restorer'),
                'scanComplete' => __('Scan complete!', 'wayback-image-restorer'),
                'scanInProgress' => __('Scan in progress', 'wayback-image-restorer'),
                'scanElapsed' => __('Elapsed', 'wayback-image-restorer'),
                'scanStageBrowser' => __('Verifying media library URLs from the browser...', 'wayback-image-restorer'),
                'scanActivityTitle' => __('Recent Scan Activity', 'wayback-image-restorer'),
                'scanActivityLoading' => __('Loading scan activity...', 'wayback-image-restorer'),
                'scanActivityEmpty' => __('No scan activity was recorded for this run.', 'wayback-image-restorer'),
                'scanActivityError' => __('Unable to load scan activity.', 'wayback-image-restorer'),
                'scanStagePrepare' => __('Preparing scan request...', 'wayback-image-restorer'),
                'scanStagePosts' => __('Scanning posts and pages for image references...', 'wayback-image-restorer'),
                'scanStageImages' => __('Checking image URLs and local files...', 'wayback-image-restorer'),
                'scanStageWayback' => __('Looking for archived copies in the Wayback Machine...', 'wayback-image-restorer'),
                'scanStageMedia' => __('Checking media library attachments...', 'wayback-image-restorer'),
                'scanStageFinalize' => __('Finalizing scan results...', 'wayback-image-restorer'),
                'restoring' => __('Restoring...', 'wayback-image-restorer'),
                'restoreFailed' => __('Restore failed', 'wayback-image-restorer'),
                'restored' => __('Restored', 'wayback-image-restorer'),
                'undoRestore' => __('Undo Restore', 'wayback-image-restorer'),
                'undoing' => __('Undoing...', 'wayback-image-restorer'),
                'archiveDate' => __('Archive Date', 'wayback-image-restorer'),
                'recheckArchive' => __('Recheck Archive', 'wayback-image-restorer'),
                'lookingUpArchive' => __('Looking up archive...', 'wayback-image-restorer'),
                'confirmRestore' => __('Are you sure you want to restore the selected images?', 'wayback-image-restorer'),
                'confirmUndo' => __('Undo this restore? This will restore the previous URL references and remove the imported attachment.', 'wayback-image-restorer'),
                'confirmClear' => __('Are you sure you want to clear all logs?', 'wayback-image-restorer'),
                'dryRunComplete' => __('Dry run complete. No changes were made.', 'wayback-image-restorer'),
                'dryRunActive' => __('Dry-run mode is active. Restores will be simulated only.', 'wayback-image-restorer'),
                'startScan' => __('Start Scan', 'wayback-image-restorer'),
                'startDryRunScan' => __('Start Dry-Run Scan', 'wayback-image-restorer'),
                'loadingSavedResults' => __('Loading the most recent saved scan results...', 'wayback-image-restorer'),
                'loadedSavedResults' => __('Loaded the most recent saved scan results.', 'wayback-image-restorer'),
                'undoComplete' => __('Restore undone.', 'wayback-image-restorer'),
                'success' => __('Success', 'wayback-image-restorer'),
                'error' => __('Error', 'wayback-image-restorer'),
            ],
        ]);
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = \Wayback_Image_Restorer\Settings::get();
        $logger = \Wayback_Image_Restorer\Logger::get_instance();
        $log_stats = $logger->get_log_stats();
        ?>
        <div class="wrap wir-wrap">
            <h1><?php echo esc_html__('Wayback Image Restorer', 'wayback-image-restorer'); ?></h1>

            <div class="wir-grid">
                <div class="wir-main">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('wir_settings_group');
                        do_settings_sections('wayback-image-restorer');
                        submit_button(__('Save Settings', 'wayback-image-restorer'));
                        ?>
                    </form>

                    <hr class="wp-header-end">

                    <h2><?php esc_html_e('Scan Results', 'wayback-image-restorer'); ?></h2>
                    
                    <div class="wir-scan-controls">
                        <button type="button" id="wir-start-scan" class="button button-primary button-hero">
                            <?php esc_html_e('Start Scan', 'wayback-image-restorer'); ?>
                        </button>
                        <span id="wir-scan-status" class="wir-status"></span>
                    </div>

                    <div id="wir-dry-run-indicator" class="wir-dry-run-indicator" style="display: none;"></div>

                    <div id="wir-scan-results" class="wir-results">
                        <p class="description">
                            <?php esc_html_e('Click "Start Scan" to find broken images on your website.', 'wayback-image-restorer'); ?>
                        </p>
                    </div>
                </div>

                <div class="wir-sidebar">
                    <div class="wir-panel">
                        <h3><?php esc_html_e('Log Management', 'wayback-image-restorer'); ?></h3>
                        
                        <div class="wir-log-stats">
                            <p>
                                <strong><?php esc_html_e('Current Log:', 'wayback-image-restorer'); ?></strong>
                                <?php echo esc_html($log_stats['filename']); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Size:', 'wayback-image-restorer'); ?></strong>
                                <?php echo esc_html($log_stats['size_formatted']); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Entries:', 'wayback-image-restorer'); ?></strong>
                                <?php echo esc_html(number_format($log_stats['entries'])); ?>
                            </p>
                        </div>

                        <div class="wir-log-actions">
                            <button type="button" id="wir-view-logs" class="button">
                                <?php esc_html_e('View Logs', 'wayback-image-restorer'); ?>
                            </button>
                            <button type="button" id="wir-download-logs" class="button">
                                <?php esc_html_e('Download CSV', 'wayback-image-restorer'); ?>
                            </button>
                            <button type="button" id="wir-clear-logs" class="button">
                                <?php esc_html_e('Clear Logs', 'wayback-image-restorer'); ?>
                            </button>
                            <button type="button" id="wir-rotate-logs" class="button">
                                <?php esc_html_e('Rotate Now', 'wayback-image-restorer'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="wir-panel">
                        <h3><?php esc_html_e('About', 'wayback-image-restorer'); ?></h3>
                        <p>
                            <?php esc_html_e('Wayback Image Restorer helps you find and restore missing or broken images from the Internet Archive Wayback Machine.', 'wayback-image-restorer'); ?>
                        </p>
                        <p class="version">
                            <?php printf(
                                esc_html__('Version %s', 'wayback-image-restorer'),
                                $this->version
                            ); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div id="wir-logs-modal" class="wir-modal" style="display: none;">
            <div class="wir-modal-content">
                <span class="wir-modal-close">&times;</span>
                <h2><?php esc_html_e('Log Viewer', 'wayback-image-restorer'); ?></h2>
                <div class="wir-log-filters">
                    <select id="wir-log-level">
                        <option value="all"><?php esc_html_e('All Levels', 'wayback-image-restorer'); ?></option>
                        <option value="debug">Debug</option>
                        <option value="info">Info</option>
                        <option value="success">Success</option>
                        <option value="warning">Warning</option>
                        <option value="error">Error</option>
                    </select>
                    <input type="text" id="wir-log-search" placeholder="<?php esc_attr_e('Search...', 'wayback-image-restorer'); ?>">
                </div>
                <div id="wir-logs-list" class="wir-logs-list">
                    <p><?php esc_html_e('Loading...', 'wayback-image-restorer'); ?></p>
                </div>
                <div class="wir-log-pagination">
                    <button type="button" id="wir-logs-prev" class="button" disabled>
                        <?php esc_html_e('Previous', 'wayback-image-restorer'); ?>
                    </button>
                    <span id="wir-logs-page-info"></span>
                    <button type="button" id="wir-logs-next" class="button" disabled>
                        <?php esc_html_e('Next', 'wayback-image-restorer'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="wir-confirm-modal" class="wir-modal" style="display: none;">
            <div class="wir-modal-content wir-modal-small">
                <span class="wir-modal-close">&times;</span>
                <h2 id="wir-confirm-title"><?php esc_html_e('Confirm', 'wayback-image-restorer'); ?></h2>
                <p id="wir-confirm-message"></p>
                <div class="wir-modal-actions">
                    <button type="button" id="wir-confirm-cancel" class="button">
                        <?php esc_html_e('Cancel', 'wayback-image-restorer'); ?>
                    </button>
                    <button type="button" id="wir-confirm-ok" class="button button-primary">
                        <?php esc_html_e('Confirm', 'wayback-image-restorer'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="wir-progress-modal" class="wir-modal" style="display: none;">
            <div class="wir-modal-content">
                <h2><?php esc_html_e('Processing...', 'wayback-image-restorer'); ?></h2>
                <div class="wir-progress-bar">
                    <div class="wir-progress-fill"></div>
                </div>
                <p id="wir-progress-text">0 / 0</p>
                <p id="wir-progress-current"></p>
            </div>
        </div>
        <?php
    }

    public function render_dry_run_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        ?>
        <label for="wir_dry_run">
            <input type="checkbox" id="wir_dry_run" name="wir_settings[dry_run]" value="1" <?php checked($settings['dry_run'] ?? true); ?>>
            <?php esc_html_e('Preview changes without making modifications', 'wayback-image-restorer'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, the scanner will identify broken images but will not restore them.', 'wayback-image-restorer'); ?>
        </p>
        <?php
    }

    public function render_post_types_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        $post_types = \Wayback_Image_Restorer\Settings::get_all_post_types();
        $selected = $settings['post_types'] ?? ['post', 'page'];
        ?>
        <fieldset>
            <?php foreach ($post_types as $type) : ?>
                <label for="wir_post_type_<?php echo esc_attr($type); ?>">
                    <input type="checkbox" 
                           id="wir_post_type_<?php echo esc_attr($type); ?>" 
                           name="wir_settings[post_types][]" 
                           value="<?php echo esc_attr($type); ?>"
                           <?php checked(in_array($type, $selected, true)); ?>>
                    <?php echo esc_html($type); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    public function render_date_range_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        ?>
        <label for="wir_date_from">
            <?php esc_html_e('From:', 'wayback-image-restorer'); ?>
            <input type="date" id="wir_date_from" name="wir_settings[date_from]" value="<?php echo esc_attr($settings['date_from'] ?? ''); ?>">
        </label>
        <label for="wir_date_to">
            <?php esc_html_e('To:', 'wayback-image-restorer'); ?>
            <input type="date" id="wir_date_to" name="wir_settings[date_to]" value="<?php echo esc_attr($settings['date_to'] ?? ''); ?>">
        </label>
        <?php
    }

    public function render_timeout_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        ?>
        <label for="wir_timeout">
            <input type="number" id="wir_timeout" name="wir_settings[timeout_seconds]" 
                   value="<?php echo esc_attr($settings['timeout_seconds'] ?? 30); ?>" 
                   min="5" max="120" step="5">
            <?php esc_html_e('seconds', 'wayback-image-restorer'); ?>
        </label>
        <?php
    }

    public function render_restore_mode_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        $modes = \Wayback_Image_Restorer\Settings::get_restore_modes();
        $current = $settings['restore_mode'] ?? 'archive_then_original';
        ?>
        <select id="wir_restore_mode" name="wir_settings[restore_mode]">
            <?php foreach ($modes as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_log_max_size_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        ?>
        <label for="wir_log_max_size">
            <input type="number" id="wir_log_max_size" name="wir_settings[log_max_size_mb]" 
                   value="<?php echo esc_attr($settings['log_max_size_mb'] ?? 10); ?>" 
                   min="1" max="100">
            <?php esc_html_e('MB - Logs will be rotated when they exceed this size.', 'wayback-image-restorer'); ?>
        </label>
        <?php
    }

    public function render_log_max_age_field(): void
    {
        $settings = \Wayback_Image_Restorer\Settings::get();
        ?>
        <label for="wir_log_max_age">
            <input type="number" id="wir_log_max_age" name="wir_settings[log_max_age_days]" 
                   value="<?php echo esc_attr($settings['log_max_age_days'] ?? 30); ?>" 
                   min="1" max="365">
            <?php esc_html_e('days - Archived logs older than this will be deleted.', 'wayback-image-restorer'); ?>
        </label>
        <?php
    }
}
