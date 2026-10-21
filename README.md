# Senoobar Link Auditor

A standalone, admin-only WordPress plugin for practical internal-link analysis.

## Features

- Inventory internal and external links found in saved content for published posts, pages, and WooCommerce products.
- Count internal inbound links per destination page and list source pages linking to it.
- List pages with zero inbound internal links, so orphaned content can be found.
- Manually check HTTP/HTTPS link destinations in small batches; broken HTTP responses are separated from connection errors or unknown results.
- Copy each report table independently with its own button, including broken links, pages with no inbound links, and inbound-link counts.
- Search, filters, pagination, summary counts, and CSV export.
- Keeps the last successful content scan visible while a manual rescan runs.
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

The broken-link checker is a separate, user-triggered operation. It checks HTTP/HTTPS URLs in small batches using WordPress safe HTTP requests with short timeouts and bounded redirects. HTTP 4xx/5xx responses are reported as broken; connection errors and unclassifiable responses are not treated as confirmed broken links. Some hosts block HEAD requests or respond differently to bots, so verify important URLs manually before making content changes.

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
