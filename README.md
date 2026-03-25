# Wayback Image Restorer

Wayback Image Restorer is a WordPress plugin that scans for missing images, looks them up in the Internet Archive Wayback Machine, and restores recoverable files back into the WordPress media library.

This repository now uses a standard root-level WordPress plugin layout so GitHub-based update checks can read the plugin header directly from `main`.

## Features

- Scan posts, pages, featured images, media library records, and `srcset` image candidates
- Detect broken local uploads and inaccessible external image URLs
- Query the Wayback Machine CDX API for archived image snapshots
- Restore archived images into the WordPress media library
- Update post content and featured image references after restore
- Support common image formats including JPEG, PNG, GIF, WebP, SVG, BMP, and TIFF
- Try legacy `.png`, `.jpg`, and `.jpeg` archive paths when a missing image is now referenced as `.webp`
- Offer dry-run mode, batching, logging, and GitHub-based updates

## How It Works

1. The scanner crawls published content and attachment records for image URLs.
2. Each image is checked locally or over HTTP to decide whether it is missing.
3. Missing images are searched in the Wayback Machine.
4. If an archive exists, the file can be downloaded and imported into WordPress.
5. The plugin updates the original references to the restored media item.

## Project Layout

```text
.
|-- wayback-image-restorer.php
|-- README.md
|-- README.txt
|-- composer.json
|-- admin/
|-- includes/
|-- languages/
|-- public/
`-- vendor/
```

Key files and directories:

- `wayback-image-restorer.php`: main plugin bootstrap and plugin header
- `includes/class-wayback-api.php`: Wayback Machine lookup and download logic
- `includes/class-image-scanner.php`: broken image discovery
- `includes/class-image-restorer.php`: media import and reference replacement
- `includes/class-plugin.php`: plugin wiring and GitHub update checker setup
- `admin/`: WordPress admin UI, AJAX handlers, and assets
- `public/`: frontend-side hooks
- `README.txt`: WordPress-style plugin readme used for plugin metadata

## Architecture Notes

### Scanning

- Scans post content with image and `srcset` extraction
- Includes featured images and media library attachments
- Distinguishes local versus external images before checking them
- Uses batching and a resource manager to avoid exhausting low-resource hosts

### Wayback Lookup

- Uses the CDX API at `https://web.archive.org/cdx/search/cdx`
- Filters for successful image responses
- Builds raw-content archive URLs using the `id_` snapshot format
- Falls back from `.webp` to legacy `.png`, `.jpg`, and `.jpeg` lookup candidates where relevant

### Restoration

- Downloads the chosen source
- Validates MIME type before import
- Imports via WordPress media handling
- Updates content or featured-image references for affected posts

### Logging

- Logs are stored under `wp-content/uploads/wayback-image-restorer/logs`
- The plugin supports rotation, viewing, export, and cleanup

## Development

Requirements:

- PHP 7.4+
- WordPress 6.0+
- Composer, only when updating dependencies

Notes:

- The `vendor/` directory is committed, so a normal install does not require Composer
- If dependency changes are needed, run `composer install --no-dev --prefer-dist --optimize-autoloader`
- The plugin update checker is configured against this GitHub repository and the `main` branch

## Release Notes

- Keep the main plugin file at repository root so the GitHub updater can read the plugin header correctly
- Bump versions consistently in `wayback-image-restorer.php`, `README.txt`, and any mirrored version constants
- Keep `README.txt` accurate because WordPress uses it for plugin details and upgrade notes

## Current Status

Implemented:

- Broken image scanning
- Wayback archive lookup
- Archive download and restore flow
- Post content replacement
- Featured image handling
- Logging and admin tooling
- GitHub-based update checks

## License

GPLv2 or later. See `LICENSE`.
