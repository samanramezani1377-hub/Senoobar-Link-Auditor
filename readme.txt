=== Senoobar Link Auditor ===
Contributors: senoobar
Tags: links, seo, reports, woocommerce, broken-links
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only link reports for published posts, pages, and WooCommerce products. Includes inbound internal-link counts, pages with no inbound internal links, manual broken-link checks, and independent copy buttons for each report.

== Description ==

The plugin inventories links in stored post content and preserves the last successful snapshot. Use Tools > Link Auditor to inspect internal and external links, see how many internal links point to each page, find pages with no inbound internal links, manually check HTTP/HTTPS destinations, copy individual reports, and export CSV.

Content scans and destination checks only run after an administrator clicks the relevant button. No frontend assets or scan queries, cron jobs, persistent workers, or idle polling are used. The plugin does not modify content, create posts/pages, or register agent abilities or custom agent REST endpoints.

== Installation ==

1. Upload and activate the plugin ZIP.
2. Open Tools > Link Auditor.
3. Click the manual rescan button to create the first report.

== Frequently Asked Questions ==

= Does this change my articles or products? =
No. The plugin does not edit content.

= How are broken links checked? =
The separate manual check requests HTTP/HTTPS destinations in small batches. HTTP 4xx/5xx responses are flagged; connection failures are shown as unknown and are not considered confirmed broken links.

= Does it affect frontend speed? =
The plugin does not enqueue frontend assets or run scans on frontend requests. Scans and destination checks are user-triggered in the admin screen only.

== Changelog ==

= 0.2.0 =
* Remove agent abilities and custom REST endpoints.
* Add inbound internal-link counts and pages without inbound links.
* Add manual broken-link checks and per-report copy buttons.

= 0.1.0 =
* Initial manual, snapshot-based link inventory.
