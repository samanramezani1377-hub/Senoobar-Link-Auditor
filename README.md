# Senoobar Link Auditor

A standalone WordPress plugin that inventories links found in saved content for published posts, pages, and WooCommerce products.

## Features

- Internal and external link inventory with source content, anchor text, URL, and content type.
- Search, filters, pagination, summary counts, and CSV export.
- Keeps the last successful snapshot visible while a manual rescan runs.
- A scan starts only when an administrator clicks the manual rescan button.
- Reads saved post content only; it does not crawl rendered pages or fetch destination URLs.
- No frontend hooks/assets/queries for scanning, no cron jobs, persistent workers, timers, or idle polling.
- Does not edit content, create posts/pages, or alter SEO plugin settings.

## Installation

1. Download the senoobar-link-auditor.zip artifact from a successful GitHub Actions run or release.
2. In WordPress, open Plugins → Add New Plugin → Upload Plugin.
3. Upload the ZIP, install, and activate.
4. Open Tools → Link Auditor and click the manual rescan button.

## What is scanned

Only published WordPress post, page, and product records and their stored post_content field. Header/footer markup, navigation menus, widgets, scripts, and rendered frontend output are outside scope. A link is classified as internal when its hostname exactly matches the site's hostname; subdomains are treated as distinct hosts.

## Performance and data behavior

Normal frontend requests do not run scanner logic or load the plugin's assets. Opening the admin screen only reads the most recent completed snapshot. When explicitly started, the browser sends one bounded batch request at a time; if the screen is closed, processing stops. There is no automatic resume and no persistent background process.

New results are written to a staging scan. Only a successful completion replaces the current snapshot. A failed or cancelled scan leaves the previous completed snapshot available. Scanning inspects stored content and does not request external URLs, so it does not verify whether a destination currently responds.

## Security and data retention

Scan and export actions require manage_options and nonce validation. Database operations use prepared queries where input is involved; output is escaped and CSV formula-leading cells are prefixed. Data is stored in plugin-owned database tables. Successful rescans remove older snapshots. Uninstall removes plugin tables unless a site administrator explicitly defines SLA_KEEP_DATA_ON_UNINSTALL as true in wp-config.php before uninstalling.

## Development checks

~~~sh
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/test-parser.php
php tests/test-safety.php
node --check assets/js/admin.js
bash scripts/build-plugin-zip.sh
~~~

GitHub Actions runs syntax, behavior, and safety checks before packaging. ZIP artifacts are generated only by the package job after checks pass.
