# Unused Image Cleaner

A lightweight WordPress admin plugin for auditing image attachments against references found in the site database. It is deliberately conservative: a result of **Potentially unused** means no match was found in the supported database fields, not that an image is definitely unused. Nothing is deleted automatically.

## Requirements and installation

- WordPress 6.2 or later
- PHP 7.4 or later

Upload `unused-image-cleaner.zip` in **Plugins → Add New → Upload Plugin**, or copy the `unused-image-cleaner` directory into `wp-content/plugins/` and activate **Unused Image Cleaner**. Open **Media → Image Cleaner** to scan.

## Scan and review

The scan processes image attachments in batches of 25 and stores the latest per-user scan results temporarily. It checks featured-image IDs, post and page content (including blocks, classic editor content, and common builder payloads), post metadata/custom fields, and site options such as widget and theme settings. It also checks original paths/URLs and generated image sizes where attachment metadata is available.

The table supports filename search, status filtering, and pagination, and shows a thumbnail, filename, file size, upload date, status/reason, and attachment URL. Uncertain and used items cannot be selected for deletion. Scan results are read-only and are stored for up to 24 hours.

## Deletion and recovery

To remove files, explicitly select eligible **Potentially unused** items, choose **Review selected for Trash**, review the confirmation list, and confirm a second time. The plugin rechecks references and permissions immediately before calling WordPress `wp_trash_post()`. It never calls permanent file deletion. Restore trashed attachments through **Media → Trash** while they remain in the WordPress Trash retention period. Keep a current database and file backup before taking destructive actions.

## Limitations and safety

No automated scanner can guarantee that an image is unused. This plugin cannot reliably detect references in external systems, hardcoded theme/plugin source, CSS/JavaScript, remote caches/CDNs, third-party services, or database locations outside the scanned post content, post metadata, and options tables. Database substring matching can also produce false positives. Validate every item yourself; do not treat a scan result as proof. It requires an administrator (`manage_options`) and uses WordPress nonces, capability checks, prepared SQL queries, escaping, and sanitization.

## Tests

Run the dependency-free classifier tests with:

```sh
php tests/test-classification.php
```

WordPress integration tests require a WordPress test environment; no external framework or Composer dependencies are included.
