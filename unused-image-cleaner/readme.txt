=== Unused Image Cleaner ===
Contributors: trekrosales
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit image attachments for database references and move explicitly reviewed potential matches to WordPress Trash.

== Description ==

Unused Image Cleaner checks featured-image metadata, post/page content, post metadata, and site options for references to image attachments. It reports Used, Potentially unused, or Uncertain. Nothing is deleted automatically, and the plugin does not claim that a Potentially unused image is definitely unused.

== Installation ==

1. Upload the ZIP through Plugins > Add New > Upload Plugin, or copy the plugin folder into wp-content/plugins/.
2. Activate the plugin.
3. Open Media > Image Cleaner and start a scan.

== Frequently Asked Questions ==

= Does the plugin permanently delete files? =

No. After explicit selection and two-step confirmation, eligible items are sent to WordPress Trash using wp_trash_post(). Restore items from Media > Trash during the retention period.

= Can the scan prove an image is unused? =

No. References in external systems, hardcoded theme or plugin code, CSS, JavaScript, caches, and third-party services may not be detected. Review every candidate and maintain backups.

== Changelog ==

= 1.0.1 =
* Treat database query failures as uncertain and block them from deletion.
* Keep trashed attachments out of cached scan results.

= 1.0.0 =
* Initial release with batched read-only scanning, searchable/paginated review, and explicit Trash workflow.
