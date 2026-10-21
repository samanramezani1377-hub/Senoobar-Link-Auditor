# Senoobar Link Auditor

A standalone, admin-only WordPress plugin for practical internal-link analysis.

## Features

- Inventory internal and external links found in saved content for published posts, pages, and WooCommerce products.
- Count internal inbound links per destination page and list source pages linking to it.
- List pages with zero inbound internal links, so orphaned content can be found.
- Manually check HTTP/HTTPS link destinations in small batches; broken HTTP responses are separated from connection errors or unknown results.
- Copy each report table independently with its own button, including broken links, pages with no inbound links, and inbound-link counts.
- Search, link-type/content-type/HTTP-status filters, pagination, summary counts, and CSV export including HTTP status and check details.
- Manually traces up to three redirect hops per URL, recording 301/302-style redirects, chains, loops/overlong chains, 404/410 and other HTTP errors.
- Keeps the last successful content scan visible while a manual rescan runs.
- Retains the latest 10 successful scan snapshots and compares added/removed links. When both snapshots have been manually checked, it also compares new and resolved HTTP issues.
- Scans only after an administrator clicks the rescan button; broken-link checks also require a separate explicit click.
- No frontend assets/queries for scanning, no cron jobs, persistent workers, timers, or idle polling.
- Does not edit content, create posts/pages, or alter SEO settings.
- No agent abilities or custom agent REST endpoints are registered.

## Installation

1. Download the ZIP artifact from a successful GitHub Actions run or release.
2. In WordPress, open Plugins → Add New Plugin → Upload Plugin.
3. Upload the ZIP, install, and activate.
4. Open Tools → Link Auditor and click the manual rescan button.

## Scope and accuracy

The content scan examines stored `post_content` for published posts, pages, and products. Navigation menus, headers/footers, widgets, scripts, and rendered frontend output are outside scope. A link is internal when its hostname exactly matches the site's hostname; subdomains are treated as separate hosts. Inbound counts depend on WordPress resolving the saved URL to a published post/page/product. Rescan after upgrading to populate the new inbound-link fields for the existing report.

The destination checker is a separate, user-triggered operation. It checks three HTTP/HTTPS URLs per request, uses WordPress safe HTTP requests with a two-second per-hop timeout, disables automatic redirect following, and follows at most three redirect hops. It records the initial response code, final response code and URL, and each redirect step. A repeated URL is classified as a loop; reaching the hop cap is reported separately and is not falsely called a confirmed loop. HTTP errors including 404/410 are broken; timeouts and blocked requests remain unknown. Some hosts block HEAD requests or respond differently to bots, so verify important URLs manually before making content changes.

History retains at most 10 successful snapshots. Link differences are compared by source post, URL, anchor, link type, and occurrence position; reordered links can therefore appear as changes. HTTP issue comparisons run only after there are no unchecked HTTP URLs in either snapshot and compare matched links only. CSV exports include initial/final HTTP codes, final URL, redirect chain, and can be downloaded for retained historical scans using the current filters.

## Performance and data behavior

Normal frontend requests do not run scanner logic or load plugin assets. Opening the admin screen reads the last completed snapshot. When explicitly started, the browser sends one bounded batch at a time; closing the screen stops the work. There is no automatic resume or persistent background process.

New content-scan results are staged. Only successful completion replaces the current snapshot; failed or cancelled scans preserve the previous successful report. Broken-link checks update status fields on the current report and do not modify post content.

## Security and data retention

Scan/export actions require `manage_options` and nonce validation. Destination checks use WordPress safe HTTP functions to reject unsafe destinations. Database queries use prepared statements where user-controlled input is involved, output is escaped, and CSV formula-leading values are neutralized. Uninstall removes plugin tables unless `SLA_KEEP_DATA_ON_UNINSTALL` is set to true in `wp-config.php`.

## Development checks

~~~sh
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/test-parser.php
php tests/test-safety.php
node --check assets/js/admin.js
bash scripts/build-plugin-zip.sh
~~~

GitHub Actions runs syntax, behavior, and safety checks before packaging. ZIP artifacts are generated only after quality checks pass.
