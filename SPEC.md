# Wayback Image Restorer - WordPress Plugin Specification

## 1. Overview

### 1.1 Plugin Name
**Wayback Image Restorer**

### 1.2 Plugin Slug
`wayback-image-restorer`

### 1.3 Version
`1.0.0`

### 1.4 Description
A WordPress plugin that scans your website for missing or broken images, searches the Internet Archive Wayback Machine for archived copies, and optionally restores them to your WordPress media library.

### 1.5 Author
George Nicolaou

### 1.6 Author URI
https://profiles.wordpress.org/orionaselite/

### 1.7 Plugin URI
https://github.com/GeorgeWebDevCy/wayback-machine-image-finder

### 1.8 License
GPLv2 or later

### 1.9 Requires at Least
WordPress 6.0

### 1.10 Requires PHP
7.4 or higher

---

## 2. Technical Architecture

### 2.1 Directory Structure

```
wayback-image-restorer/
├── admin/
│   ├── class-admin.php              # Admin page controller
│   ├── class-admin-ajax.php        # AJAX handlers
│   ├── css/
│   │   └── wayback-image-restorer-admin.css
│   ├── js/
│   │   └── wayback-image-restorer-admin.js
│   └── partials/
│       ├── scan-settings.php        # Scan configuration section
│       ├── log-management.php       # Log controls section
│       └── scan-results.php         # Results table section
├── includes/
│   ├── class-activator.php         # Plugin activation hook
│   ├── class-deactivator.php       # Plugin deactivation hook
│   ├── class-loader.php            # Action/filter loader
│   ├── class-wayback-api.php       # Wayback Machine API wrapper
│   ├── class-image-scanner.php     # Broken image detection
│   ├── class-image-restorer.php     # Image restoration logic
│   ├── class-logger.php            # File-based logging system
│   └── class-settings.php          # Settings management
├── languages/
│   └── wayback-image-restorer.pot
├── public/
│   └── class-public.php             # Public-facing functionality (minimal)
├── vendor/                          # Composer dependencies
│   └── yahnis-elsts/
│       └── plugin-update-checker/
├── logs/                            # Runtime logs (created on install)
├── LICENSE
├── README.txt
├── composer.json
├── plugin-update-checker.php        # Update checker entry point
└── wayback-image-restorer.php       # Main plugin file
```

### 2.2 Database

#### 2.2.1 Options (wp_options)
| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `wayback_image_restorer_settings` | JSON | `{}` | Plugin settings |
| `wayback_image_restorer_last_scan` | JSON | `null` | Last scan results cache |
| `wayback_image_restorer_version` | string | - | Plugin version for upgrades |

#### 2.2.2 Settings Schema
```json
{
  "dry_run": true,
  "post_types": ["post", "page"],
  "date_from": null,
  "date_to": null,
  "log_max_size_mb": 10,
  "log_max_age_days": 30,
  "timeout_seconds": 30,
  "restore_mode": "archive_then_original"
}
```

### 2.3 File-Based Logging

#### 2.3.1 Log Directory
`wp-content/uploads/wayback-image-restorer/logs/`

#### 2.3.2 Log Filename Format
`{YYYY-MM}.log` (monthly rotation)

#### 2.3.3 Log Entry Format (JSON Lines)
```json
{"timestamp":"2026-03-25T10:30:00Z","level":"info","action":"scan_start","dry_run":true,"filters":{"post_types":["post","page"]}}
{"timestamp":"2026-03-25T10:30:05Z","level":"info","action":"scan_complete","found_broken":5,"dry_run":true,"duration_seconds":5}
{"timestamp":"2026-03-25T10:31:00Z","level":"info","action":"restore_start","image_url":"https://example.com/image.jpg","post_id":42}
{"timestamp":"2026-03-25T10:31:02Z","level":"success","action":"restore_complete","image_url":"https://example.com/image.jpg","post_id":42,"archive_url":"https://web.archive.org/...","new_attachment_id":156}
{"timestamp":"2026-03-25T10:31:03Z","level":"error","action":"restore_failed","image_url":"https://example.com/missing.png","post_id":55,"error":"No archive found"}
```

#### 2.3.4 Log Levels
| Level | Usage |
|-------|-------|
| `debug` | Detailed debugging information |
| `info` | General operational events |
| `success` | Successful restorations |
| `warning` | Non-critical issues |
| `error` | Failures and errors |

---

## 3. Features Specification

### 3.1 Feature: Broken Image Scanning

#### 3.1.1 Scan Sources
| Source | Method | Description |
|--------|--------|-------------|
| Media Library Attachments | Database query | All image attachments |
| Content Images | Regex extraction | `<img>` tags in post content |
| Featured Images | Post meta query | Featured images (thumbnails) |
| External Images | Content scan | External image URLs |

#### 3.1.2 Scan Logic
```
1. Query all posts of selected post_types
2. For each post:
   a. Extract all <img> tags via regex
   b. Extract srcset URLs
   c. Extract featured image if exists
3. For each image URL:
   a. If local (same domain):
      - Check if file exists in uploads directory
      - If not, mark as BROKEN
   b. If external:
      - Send HEAD request
      - If response >= 400, mark as BROKEN
      - If timeout or error, mark as BROKEN
4. Query media library:
   a. Get all attachments with image mime types
   b. Check if files physically exist
   c. Mark missing as BROKEN
5. Return consolidated list of broken images
```

#### 3.1.3 Image URL Extraction Regex
```php
// Main src attribute
'/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i'

// srcset for responsive images
'/<img[^>]+srcset=["\']([^"\']+)["\'][^>]*>/i'
// Then parse srcset: '/(\S+)\s+(?:[\d.]+)?w/'
```

#### 3.1.4 Scan Results Structure
```json
{
  "scan_id": "abc123",
  "started_at": "2026-03-25T10:30:00Z",
  "completed_at": "2026-03-25T10:30:15Z",
  "dry_run": true,
  "filters": {
    "post_types": ["post", "page"],
    "date_range": {"from": "2025-01-01", "to": null}
  },
  "stats": {
    "posts_scanned": 150,
    "images_found": 423,
    "images_broken": 12,
    "images_ok": 411
  },
  "broken_images": [
    {
      "id": 1,
      "url": "https://example.com/wp-content/uploads/2020/05/old-image.jpg",
      "type": "local",
      "referenced_in": [
        {"post_id": 42, "post_title": "Hello World", "context": "content"},
        {"post_id": 55, "post_title": "About Us", "context": "featured"}
      ],
      "archive_found": true,
      "archive_url": "https://web.archive.org/web/20210315id_/https://example.com/wp-content/uploads/2020/05/old-image.jpg",
      "archive_timestamp": "20210315120000",
      "last_checked": "2026-03-25T10:30:00Z"
    }
  ]
}
```

### 3.2 Feature: Wayback Machine Integration

#### 3.2.1 CDX API Query
**Endpoint:** `https://web.archive.org/cdx/search/cdx`

**Parameters:**
| Param | Value | Description |
|-------|-------|-------------|
| `url` | `{image_url}` | URL to search (URL-encoded) |
| `output` | `json` | Return JSON format |
| `filter` | `statuscode:200` | Only successful responses |
| `filter` | `mimetype:image/.*` | Only images |
| `fl` | `timestamp,original,statuscode,mimetype` | Fields to return |
| `limit` | `1` | Return only closest |
| `from` | `{YYYYMMDD}` | Start date (optional) |

**Example Request:**
```
https://web.archive.org/cdx/search/cdx?url=https://example.com/image.jpg&output=json&filter=statuscode:200&filter=mimetype:image/.*&fl=timestamp,original&limit=1
```

**Example Response:**
```json
[["20210315120000","https://example.com/image.jpg"]]
```

#### 3.2.2 Image Retrieval URL
**Format:** `https://web.archive.org/web/{timestamp}id_/{encoded_url}`

**The `id_` suffix** ensures raw content retrieval without Wayback Machine toolbar.

**Example:**
```
https://web.archive.org/web/20210315120000id_/https://example.com/image.jpg
```

#### 3.2.3 Fallback Strategies
| Strategy | Description |
|----------|-------------|
| `archive_only` | Only use Wayback Machine |
| `original_only` | Only try original source |
| `archive_then_original` | Try archive first, then original |
| `original_then_archive` | Try original first, then archive |

#### 3.2.4 Archive Search by Date
1. Use post's published date to find likely snapshot
2. Search CDX API with `from` parameter set to post date
3. If no result, search last 30 days before post date
4. If still nothing, search any available snapshot (no date filter)

### 3.3 Feature: Image Restoration

#### 3.3.1 Restoration Workflow
```
1. User clicks "Restore" on broken image
2. If dry_run=true:
   a. Log scan action
   b. Display "Would restore" message
   c. Return
3. Else:
   a. Download image from archive (or original)
   b. Verify downloaded content is valid image
   c. Import to media library via media_handle_sideload()
   d. Get new attachment ID
   e. Update all post references
   f. Log success
```

#### 3.3.2 Download Process
```php
// 1. Download to temp file
$tmp_file = download_url($archive_url);

// 2. Validate image
$image_data = @file_get_contents($tmp_file);
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->buffer($image_data);

if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'])) {
    unlink($tmp_file);
    return new WP_Error('invalid_image', 'Downloaded file is not a valid image');
}

// 3. Sideload to media library
$file_array = [
    'name'     => basename($original_url),
    'tmp_name' => $tmp_file
];

$attachment_id = media_handle_sideload($file_array, $post_id);
```

#### 3.3.3 Post Content Update
```php
// Update image URLs in content
$new_url = wp_get_attachment_url($new_attachment_id);

$post = get_post($post_id);
$post->post_content = str_replace($broken_url, $new_url, $post->post_content);
wp_update_post($post);

// Update featured image if applicable
if (has_post_thumbnail($post_id)) {
    $thumbnail_id = get_post_thumbnail_id($post_id);
    if ($thumbnail_url === $broken_url) {
        set_post_thumbnail($post_id, $new_attachment_id);
    }
}
```

#### 3.3.4 Bulk Restore
- Process images sequentially to avoid server overload
- Show progress bar with current/total count
- Allow cancellation (save state for resume)
- Final summary: X success, Y failed

### 3.4 Feature: Logging System

#### 3.4.1 Log File Management
| Operation | Description |
|-----------|-------------|
| View | Display log entries in admin with filtering |
| Download | Export as CSV file |
| Clear | Delete all log files |
| Rotate | Archive current month, start new file |

#### 3.4.2 Auto-Rotation Triggers
| Trigger | Condition |
|---------|------------|
| Size-based | Log file exceeds `log_max_size_mb` setting |
| Time-based | Log file older than `log_max_age_days` |

#### 3.4.3 Archived Log Format
Archived logs: `{YYYY-MM}.log.gz` (gzip compressed)

#### 3.4.4 Log Viewer Interface
- Paginated table view (50 entries per page)
- Filter by: date range, level, action type
- Search by: image URL, post ID
- Real-time tail option (last 20 entries)

### 3.5 Feature: Admin Settings Page

#### 3.5.1 Page Location
**Menu:** Tools > Wayback Image Restorer

#### 3.5.2 Settings Sections

**Section 1: Scan Settings**
| Field | Type | Default | Description |
|-------|------|---------|-------------|
| Dry Run Mode | checkbox | checked | Preview only, no changes |
| Post Types | multiselect | post, page | Which post types to scan |
| Date Range From | date | null | Filter posts by date |
| Date Range To | date | null | Filter posts by date |
| Timeout | number | 30 | HTTP timeout in seconds |

**Section 2: Wayback Settings**
| Field | Type | Default | Description |
|-------|------|---------|-------------|
| Restore Mode | select | archive_then_original | Fallback strategy |

**Section 3: Log Management**
| Field | Type | Default | Description |
|-------|------|---------|-------------|
| Max Log Size (MB) | number | 10 | Trigger rotation |
| Max Log Age (days) | number | 30 | Delete old archives |
| Current Log Size | display | - | Read-only info |
| Last Log Entry | display | - | Read-only info |

**Section 4: Actions**
| Button | Action |
|--------|--------|
| Start Scan | Begin broken image scan |
| View Logs | Open log viewer modal |
| Download Logs | Export as CSV |
| Clear Logs | Delete all log files |
| Rotate Now | Manually rotate logs |

#### 3.5.3 Scan Results Table
| Column | Description |
|--------|-------------|
| Checkbox | Bulk selection |
| Image Preview | Thumbnail or broken icon |
| Original URL | The broken image URL |
| Found In | Post title(s) referencing this image |
| Archive Status | Found / Not found |
| Archive Timestamp | Date of archive |
| Actions | Restore, Retry, Ignore |

---

## 4. API Specification

### 4.1 AJAX Endpoints

#### 4.1.1 Start Scan
```
Action: wayback_image_restorer_scan
Nonce: wayback_image_restorer_nonce
POST: {
  "dry_run": true,
  "post_types": ["post", "page"],
  "date_from": "2025-01-01",
  "date_to": null
}
Response: {
  "success": true,
  "scan_id": "abc123"
}
```

#### 4.1.2 Get Scan Results
```
Action: wayback_image_restorer_get_results
Nonce: wayback_image_restorer_nonce
POST: {
  "scan_id": "abc123"
}
Response: {
  "success": true,
  "results": { ... scan results structure ... }
}
```

#### 4.1.3 Restore Single Image
```
Action: wayback_image_restorer_restore
Nonce: wayback_image_restorer_nonce
POST: {
  "image_url": "https://example.com/image.jpg",
  "archive_url": "https://web.archive.org/..."
}
Response: {
  "success": true,
  "new_attachment_id": 156,
  "new_url": "https://example.com/wp-content/uploads/..."
}
```

#### 4.1.4 Bulk Restore
```
Action: wayback_image_restorer_bulk_restore
Nonce: wayback_image_restorer_nonce
POST: {
  "image_ids": [1, 2, 3, 5],
  "dry_run": false
}
Response: {
  "success": true,
  "processed": 4,
  "succeeded": 3,
  "failed": 1,
  "errors": [{"id": 5, "error": "..."}]
}
```

#### 4.1.5 Get Logs
```
Action: wayback_image_restorer_get_logs
Nonce: wayback_image_restorer_nonce
POST: {
  "page": 1,
  "per_page": 50,
  "level": "all",
  "action": "all",
  "search": ""
}
Response: {
  "success": true,
  "logs": [...],
  "total": 123,
  "total_pages": 3
}
```

#### 4.1.6 Clear Logs
```
Action: wayback_image_restorer_clear_logs
Nonce: wayback_image_restorer_nonce
Response: {
  "success": true,
  "deleted_files": 5
}
```

---

## 5. User Interface

### 5.1 Admin Page Layout

```
+-------------------------------------------------------------------------+
| Wayback Image Restorer                                                  |
+-------------------------------------------------------------------------+
|                                                                         |
| +--- Scan Settings -------------------+ +--- Wayback Settings --------+ |
| |                                      | |                             | |
| |  [x] Dry Run Mode                   | |  Restore Mode:              | |
| |                                      | |  [Archive then Original]   | |
| |  Post Types:                         | |                             | |
| |  [x] Posts  [x] Pages  [ ] Products | |                             | |
| |                                      | |                             | |
| |  Date Range:                        | |                             | |
| |  From: [2025-01-01]  To: [____]    | |                             | |
| |                                      | |                             | |
| |  HTTP Timeout: [30] seconds         | |                             | |
| |                                      | |                             | |
| |  [ > Start Scan ]                   | |                             | |
| |                                      | |                             | |
| +--------------------------------------+ +-----------------------------+ |
|                                                                         |
| +--- Scan Results (Dry Run) ----------------------------------------+   |
| |  Status: Ready. Click "Start Scan" to find broken images.          |   |
| |                                                                      |   |
| |  +------------------------------------------------------------+    |   |
| |  | [x] | Image | URL | Posts | Archive | Actions |             |    |   |
| |  +------------------------------------------------------------+    |   |
| |  |   | img | example.com/... | 2 posts | Yes 2021 | [Restore] |    |   |
| |  |   | img | missing.png | 1 post | No None | [Retry]   |    |   |
| |  +------------------------------------------------------------+    |   |
| |                                                                      |   |
| |  [Restore Selected (0)]                                            |   |
| +----------------------------------------------------------------------+   |
|                                                                         |
| +--- Log Management -------------------------------------------------+   |
| |  Log File: 2026-03.log  |  Size: 2.4 MB  |  Entries: 1,234          |   |
| |                                                                      |   |
| |  [View Logs]  [Download CSV]  [Clear Logs]  [Rotate Now]          |   |
| |                                                                      |   |
| |  Log Settings:                                                     |   |
| |  Max Size: [10] MB  |  Max Age: [30] days  |  [Save Settings]        |   |
| +----------------------------------------------------------------------+   |
|                                                                         |
+-------------------------------------------------------------------------+
```

### 5.2 Confirmation Modal (Restore)

```
+-----------------------------------------------+
|          Confirm Restore                       |
+-----------------------------------------------+
|                                                |
|  You are about to restore 5 images.           |
|                                                |
|  This will:                                    |
|  - Download images from Wayback Machine       |
|  - Import them to your Media Library         |
|  - Update 7 posts with new image URLs        |
|                                                |
|  Dry Run Mode: OFF                            |
|                                                |
|  [Cancel]              [Confirm Restore]       |
|                                                |
+-----------------------------------------------+
```

### 5.3 Progress Modal (Bulk Restore)

```
+-----------------------------------------------+
|          Restoring Images...                   |
+-----------------------------------------------+
|                                                |
|  Progress: [████████        ]  5/12 (42%)     |
|                                                |
|  Currently: example.com/image5.jpg            |
|  Status: Downloading from archive...          |
|                                                |
|  Completed: 4 successful, 1 failed            |
|                                                |
|            [Cancel]                            |
|                                                |
+-----------------------------------------------+
```

---

## 6. WordPress Integration

### 6.1 Hooks and Filters

#### 6.1.1 Actions
| Action | Location | Description |
|--------|----------|-------------|
| `admin_menu` | admin.php | Add admin menu item |
| `admin_init` | admin.php | Register settings |
| `wp_ajax_wayback_image_restorer_scan` | ajax.php | Handle scan AJAX |
| `wp_ajax_wayback_image_restorer_restore` | ajax.php | Handle restore AJAX |
| `admin_enqueue_scripts` | admin.php | Enqueue admin assets |

#### 6.1.2 Filters
| Filter | Type | Description |
|--------|------|-------------|
| `wayback_image_restorer_scan_results` | apply_filters | Modify scan results |
| `wayback_image_restorer_archive_url` | apply_filters | Modify archive URL |
| `wayback_image_restorer_restore_url` | apply_filters | Modify restored image URL |

### 6.2 Capabilities
| Capability | Required Role | Description |
|------------|---------------|-------------|
| `manage_options` | Administrator | Access plugin settings |
| `upload_files` | Editor+ | Upload to media library |
| `edit_posts` | Author+ | Update post content |

### 6.3 Update Checker Integration

```php
// In main plugin file
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/GeorgeWebDevCy/wayback-machine-image-finder/',
    __FILE__,
    'wayback-image-restorer'
);

$updateChecker->setBranch('stable');
```

---

## 7. Error Handling

### 7.1 Error Types

| Error Code | Description | User Message |
|------------|-------------|--------------|
| `no_archive_found` | No Wayback Machine snapshot | "No archived version found for this image." |
| `download_failed` | Failed to download image | "Failed to download image. Try again later." |
| `invalid_image` | Downloaded file is not valid image | "Downloaded content is not a valid image." |
| `media_import_failed` | Failed to add to media library | "Failed to import image to media library." |
| `content_update_failed` | Failed to update post content | "Image restored but failed to update post." |
| `api_rate_limit` | Wayback API rate limited | "Wayback Machine rate limit reached. Please wait." |
| `network_error` | Network connectivity issue | "Network error. Check your connection." |

### 7.2 Retry Logic
- Automatic retry: 3 attempts with exponential backoff
- Retry delay: 2s, 4s, 8s
- After 3 failures: Mark as failed, log error, skip

---

## 8. Security Considerations

### 8.1 Nonce Verification
All AJAX requests must include and verify WordPress nonce:
```php
check_ajax_referer('wayback_image_restorer_nonce', 'nonce');
```

### 8.2 Capability Checks
```php
if (!current_user_can('manage_options')) {
    wp_die(__('Unauthorized', 'wayback-image-restorer'));
}
```

### 8.3 Input Sanitization
```php
$sanitized_url = esc_url_raw($url);
$sanitized_id = absint($id);
```

### 8.4 Output Escaping
```php
echo esc_html($message);
echo esc_url($image_url);
```

### 8.5 File Access
- Logs directory created with `.htaccess` protection
- Direct file access denied
- CSV exports sanitized

---

## 9. Performance

### 9.1 Optimization Strategies

| Strategy | Implementation |
|----------|----------------|
| Caching | Cache scan results in transient (1 hour) |
| Pagination | Process images in batches of 10 |
| Async | Use WordPress Cron for long operations |
| Timeouts | Strict HTTP timeout (30s default) |
| Memory | Limit memory usage with `wp_raise_memory_limit()` |

### 9.2 Batch Processing
- Scan: All posts in single query with pagination
- Restore: 10 images per batch
- Logs: 50 entries per page

---

## 10. Dependencies

### 10.1 Composer (vendor/)
```json
{
  "require": {
    "php": ">=7.4",
    "yahnis-elsts/plugin-update-checker": "^5.6"
  }
}
```

### 10.2 WordPress Requirements
| Requirement | Minimum |
|------------|---------|
| WordPress | 6.0 |
| PHP | 7.4 |
| Extensions | cURL, GD/ImageMagick |

---

## 11. Testing Checklist

### 11.1 Unit Tests
- [ ] Wayback API response parsing
- [ ] Image URL extraction from HTML
- [ ] Log entry formatting
- [ ] Settings validation

### 11.2 Integration Tests
- [ ] Scan finds broken local images
- [ ] Scan finds broken external images
- [ ] Wayback archive search
- [ ] Media library import
- [ ] Post content update
- [ ] Log rotation

### 11.3 Manual Testing
- [ ] Dry run mode produces correct preview
- [ ] Restore mode updates all references
- [ ] Bulk restore with progress
- [ ] Log viewer with filtering
- [ ] Settings persistence
- [ ] Update checker notification

---

## 12. Version History

### 1.0.0 (Planned)
- Initial release
- Broken image scanning (posts + media library)
- Wayback Machine CDX API integration
- Image restoration to media library
- Dry run mode
- File-based logging with rotation
- Admin settings page
- GitHub update checker integration

---

## Appendix A: WordPress Plugin Boilerplate Headers

```php
/**
 * Plugin Name:       Wayback Image Restorer
 * Plugin URI:        https://github.com/GeorgeWebDevCy/wayback-machine-image-finder
 * Description:       Find missing images and restore them from the Wayback Machine
 * Version:           1.0.0
 * Requires at least:  6.0
 * Requires PHP:      7.4
 * Author:            George Nicolaou
 * Author URI:        https://profiles.wordpress.org/orionaselite/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wayback-image-restorer
 * Domain Path:       /languages
 */
```

---

## Appendix B: README.txt Format

See WordPress.org readme.txt specification for complete format.

---

## Appendix C: File Permissions

| Path | Permission | Owner |
|------|------------|-------|
| `/logs/` | 755 | www-data |
| `/logs/*.log` | 644 | www-data |
| `/uploads/` | (inherited) | - |

---

## 13. Implementation Status

### 13.1 Core Files (Completed)
- [x] `wayback-image-restorer.php` - Main plugin file with headers
- [x] `includes/class-plugin.php` - Main plugin class
- [x] `includes/class-loader.php` - Action/filter loader
- [x] `includes/class-activator.php` - Activation hook
- [x] `includes/class-deactivator.php` - Deactivation hook
- [x] `includes/class-settings.php` - Settings management
- [x] `includes/class-logger.php` - File-based logging system
- [x] `includes/class-resource-manager.php` - Resource management
- [x] `includes/class-wayback-api.php` - Wayback API wrapper
- [x] `includes/class-image-scanner.php` - Broken image detection
- [x] `includes/class-image-restorer.php` - Image restoration
- [x] `includes/functions-helpers.php` - Helper functions
- [x] `admin/class-admin.php` - Admin page controller
- [x] `admin/class-admin-ajax.php` - AJAX handlers
- [x] `public/class-public.php` - Public functionality
- [x] `uninstall.php` - Clean removal handler

### 13.2 Assets (Completed)
- [x] `admin/css/wayback-image-restorer-admin.css` - Admin styles
- [x] `admin/js/wayback-image-restorer-admin.js` - Admin JavaScript

### 13.3 Documentation (Completed)
- [x] `README.txt` - WordPress.org readme
- [x] `LICENSE` - GPLv2 license
- [x] `languages/wayback-image-restorer.pot` - Translation template
- [x] `.gitignore` - Git ignore rules
- [x] `composer.json` - Composer dependencies
- [x] Security index.php files in all directories

### 13.4 Features Implemented
- [x] Broken image scanning (posts + media library)
- [x] Wayback Machine CDX API integration
- [x] Image download from archive
- [x] Media library import
- [x] Post content update
- [x] Featured image support
- [x] Dry run mode
- [x] File-based logging with rotation
- [x] Log viewer with filtering
- [x] Log export to CSV
- [x] Log clear and rotate
- [x] Resource management for low-resource hosting
- [x] Auto-detection of low-resource servers
- [x] Batch processing with pauses
- [x] Memory management
- [x] Execution time tracking
- [x] Retry logic with exponential backoff
- [x] Rate limit handling (429/503)
- [x] GitHub update checker integration
- [x] Clean uninstallation

### 13.5 Remaining Tasks
- [ ] Run `composer install` to download dependencies
- [ ] Update GitHub username in `class-plugin.php`
- [ ] Create GitHub repository and releases
- [ ] Test on WordPress installation
