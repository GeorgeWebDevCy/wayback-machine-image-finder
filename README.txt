=== Wayback Image Restorer ===
Contributors: orionaselite
Tags: images, wayback machine, broken images, restore, media library
Donate link: https://georgenicolaou.me
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find missing or broken images on your WordPress site and restore them from the Internet Archive Wayback Machine.

== Description ==

Wayback Image Restorer helps you find and restore images that are broken or missing from your WordPress website. The plugin scans your posts and media library, identifies broken images, and can restore them from the Internet Archive Wayback Machine.

**Features:**

* Scan all posts and media library for broken/missing images
* Search the Wayback Machine for archived copies
* Preview changes with Dry Run mode before making modifications
* Import restored images directly to your media library
* Automatically update post content with new image URLs
* Comprehensive logging with rotation and export
* Resource-efficient design for low-resource hosting
* GitHub-based automatic updates

**How it works:**

1. The plugin scans your posts and pages for image references
2. It checks if each image exists (either locally or externally)
3. For missing images, it queries the Wayback Machine CDX API
4. If an archive is found, you can restore it to your media library
5. Post content is automatically updated with the new image URL

== Installation ==

1. Upload the `wayback-image-restorer` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > Wayback Image Restorer
4. Configure your scan settings and click "Start Scan"

== Frequently Asked Questions ==

= Does this plugin require an API key? =

No, the Wayback Machine CDX API is free to use without an API key.

= What image types are supported? =

The plugin supports JPEG, PNG, GIF, WebP, SVG, BMP, and TIFF images.

= Will this slow down my site? =

The plugin is designed to be resource-efficient. It uses batch processing, memory management, and respects server resource limits. You can enable "Low Resource Mode" for shared hosting environments.

= Can I preview changes before they're made? =

Yes! Enable "Dry Run Mode" to scan and see what would be restored without making any changes.

== Changelog ==

= 1.0.4 =
* Give the plugin its own top-level WordPress admin menu

= 1.0.3 =
* Move the plugin code to the repository root so GitHub branch updates work with the standard updater setup

= 1.0.2 =
* Fix plugin updates for the current repository layout by switching to explicit update metadata and packaged ZIP downloads

= 1.0.1 =
* Try legacy `.png`, `.jpg`, and `.jpeg` archive URLs when a missing image is now stored as `.webp`

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.4 =
Adds a dedicated top-level admin menu for Wayback Image Restorer.

= 1.0.3 =
Moves the plugin to the repository root so GitHub updater checks can read the plugin version directly.

= 1.0.2 =
Fixes automatic updates for installs of this plugin from GitHub-backed packages.

= 1.0.1 =
Adds Wayback lookup fallback for legacy PNG/JPG/JPEG versions of missing WebP images.

= 1.0.0 =
Initial release of Wayback Image Restorer.
