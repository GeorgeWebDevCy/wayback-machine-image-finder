<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class CLI_Command
{
    public function scan(array $args, array $assoc_args): void
    {
        $scan_args = [];

        if (!empty($assoc_args['post_type'])) {
            $scan_args['post_types'] = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['post_type']))));
        }

        if (!empty($assoc_args['date_from'])) {
            $scan_args['date_from'] = sanitize_text_field((string) $assoc_args['date_from']);
        }

        if (!empty($assoc_args['date_to'])) {
            $scan_args['date_to'] = sanitize_text_field((string) $assoc_args['date_to']);
        }

        $scan_args['dry_run'] = !empty($assoc_args['dry-run']);

        $scanner = new Image_Scanner();
        $result = $scanner->scan($scan_args);

        \WP_CLI::success(sprintf('Scan %s completed with %d broken image(s).', $result['scan_id'], (int) ($result['stats']['images_broken'] ?? 0)));
        \WP_CLI::line(wp_json_encode([
            'scan_id' => $result['scan_id'],
            'duration_seconds' => $result['duration_seconds'],
            'stats' => $result['stats'],
        ]));
    }

    public function restore(array $args, array $assoc_args): void
    {
        $state_store = new State_Store();
        $scan_id = !empty($assoc_args['scan_id'])
            ? sanitize_text_field((string) $assoc_args['scan_id'])
            : $state_store->get_last_scan_id();

        if ($scan_id === '') {
            \WP_CLI::error('No scan ID was provided and no previous scan is available.');
        }

        $scan_results = $state_store->get_scan_results($scan_id);
        if (!is_array($scan_results) || empty($scan_results['broken_images']) || !is_array($scan_results['broken_images'])) {
            \WP_CLI::error('Scan results were not found for the requested scan ID.');
        }

        $dry_run = !empty($assoc_args['dry-run']);
        $target_date_override = !empty($assoc_args['target_date'])
            ? sanitize_text_field((string) $assoc_args['target_date'])
            : null;

        $restorer = new Image_Restorer();

        if (!empty($assoc_args['image_id'])) {
            $image_id = (int) $assoc_args['image_id'];
            $image = $state_store->find_scan_image($scan_id, $image_id);
            if (!is_array($image)) {
                \WP_CLI::error(sprintf('Image ID %d was not found in scan %s.', $image_id, $scan_id));
            }

            $image['scan_id'] = $scan_id;
            $image['dry_run'] = $dry_run;
            if ($target_date_override !== null) {
                $image['target_date'] = $target_date_override;
            }

            $result = $restorer->restore($image);
            if (empty($result['success'])) {
                \WP_CLI::error((string) ($result['error'] ?? 'Restore failed.'));
            }

            \WP_CLI::success(sprintf('Restored image %d from scan %s.', $image_id, $scan_id));
            \WP_CLI::line(wp_json_encode($result));
            return;
        }

        $images = array_values(array_filter(
            $scan_results['broken_images'],
            static function (array $image) use ($assoc_args): bool {
                if (empty($assoc_args['pending'])) {
                    return true;
                }

                return (int) ($image['restored_attachment_id'] ?? 0) === 0;
            }
        ));

        if ($target_date_override !== null) {
            foreach ($images as $index => $image) {
                $images[$index]['target_date'] = $target_date_override;
            }
        }

        $image_ids = array_values(array_map(static fn(array $image): int => (int) ($image['id'] ?? 0), $images));
        $result = $restorer->bulk_restore($image_ids, $dry_run, $images, $scan_id);

        if (empty($result['success'])) {
            \WP_CLI::error((string) ($result['error'] ?? 'Bulk restore failed.'));
        }

        \WP_CLI::success(sprintf(
            'Processed %d image(s) from scan %s. Succeeded: %d. Failed: %d.',
            (int) ($result['processed'] ?? 0),
            $scan_id,
            (int) ($result['succeeded'] ?? 0),
            (int) ($result['failed'] ?? 0)
        ));
        \WP_CLI::line(wp_json_encode($result));
    }
}
