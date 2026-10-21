=== Senoobar Link Auditor ===
Contributors: senoobar
Tags: links, seo, reports, woocommerce
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reports internal and external links saved in published posts, pages, and WooCommerce products without changing content.

== Description ==

The plugin stores a link inventory for saved post content. Use Tools > Link Auditor to view the last successful snapshot, filter and search links, review empty anchors, and export CSV.

The scanner runs only after an administrator clicks the manual rescan button. It does not scan rendered frontend pages, request destination URLs, use cron, or run persistent background workers. The previous successful report remains available until a new scan finishes successfully.

== Installation ==

1. Upload and activate the plugin ZIP.
2. Open Tools > Link Auditor.
3. Click the manual rescan button to create the first report.

== Frequently Asked Questions ==

= Does this change my articles or products? =
No. The plugin is read-only with respect to content.

= Does it check whether external URLs are reachable? =
No. It inventories stored links and does not fetch destination URLs.

= Does it affect frontend speed? =
The plugin does not enqueue frontend assets or run its scan on frontend requests. The inventory is read from the saved snapshot in the admin screen.

== Changelog ==

= 0.1.0 =
* Initial manual, snapshot-based link inventory.
