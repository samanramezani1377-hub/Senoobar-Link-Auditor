# Senoobar Link Auditor — Design Specification

**Date:** 2026-10-21  
**Repository:** `samanramezani1377-hub/Senoobar-Link-Auditor`

## Goal

Build a standalone WordPress plugin that inventories internal and external hyperlinks found in saved post content, preserves the last completed inventory between visits, and only performs a fresh full scan when an administrator explicitly requests it.

## Confirmed requirements

- The plugin is independent of the Senoobar theme and must continue working if the theme changes.
- Inspect saved content for published posts, pages, and WooCommerce products. Do not crawl rendered frontend HTML.
- Classify links as internal or external relative to the WordPress site URL.
- Show source content title/type, anchor text, destination URL, and link category.
- Provide search, filters, pagination, duplicate-link reporting, empty-anchor reporting, content-without-internal-links reporting, and CSV export.
- The last completed scan remains available in the admin UI across page loads and browser sessions until a new scan successfully replaces it.
- A rescan is started only by an explicit administrator action. No automatic scans on page loads, frontend requests, activation, or cron schedules.
- No persistent background worker, scheduled task, recurring polling, or frontend script/style/hook. Any progress requests may exist only during a user-initiated scan and must stop when that scan finishes or is cancelled.
- The plugin must not edit content or links, create posts/pages, or alter SEO plugin settings.
- The plugin must have no measurable routine frontend performance cost: no frontend hooks, queries, assets, or remote requests. Work happens only in authenticated admin actions.
- Require appropriate capabilities, nonces, validation, escaping, and prepared SQL queries.
- CI must run automated checks and tests. The distributable ZIP is created/published only if all required CI checks pass.

## UX and scan lifecycle

1. The admin opens the plugin page and sees the latest successfully completed snapshot, if one exists. Opening the page never starts a scan.
2. “Rescan” explicitly starts a new scan. The existing snapshot remains available while scanning.
3. A scan processes content in bounded batches initiated only by the active admin session. The interface may request the next batch only while that explicit scan is active; there is no persistent/background scheduler and no idle polling.
4. Each batch is idempotent and protected by a scan token/nonce and capability check. Progress is shown to the administrator.
5. On successful completion, atomically promote the new inventory to the current snapshot. On failure or cancellation, preserve the previous completed snapshot.
6. Store inventory in plugin-owned database tables (or an equally robust versioned persistence design); do not rescan on ordinary page visits. Use indexes and bounded queries.
7. Deactivation must stop/expire any in-progress scan. Uninstall cleanup must follow WordPress conventions and must not delete data unless explicitly documented and confirmed through the plugin's uninstall policy.

## Performance and scope boundaries

- Never parse all content during a normal frontend request.
- Never run a full-site scan from a single unbounded request. Use a user-initiated, bounded-batch scan so each request has a predictable workload.
- Do not install cron jobs, queues, service workers, daemon processes, or permanent polling loops.
- Do not fetch external destination URLs to check availability; this avoids slow requests and network side effects.
- Do not crawl menus, widgets, theme templates, headers, footers, JavaScript-generated links, or rendered HTML.
- Avoid loading plugin CSS/JS except on the plugin's own admin screens.

## Security

- Restrict access to users with an appropriate capability (default: `manage_options`).
- Verify nonces for all state-changing actions and validate scan tokens.
- Use prepared database queries; escape output by context; sanitize search/filter/export inputs.
- CSV output must mitigate spreadsheet formula injection for cells beginning with characters such as `=`, `+`, `-`, or `@`.
- Do not expose internal errors or arbitrary filesystem paths to unauthorized users.

## Testing and CI

- Unit tests for internal/external URL classification, relative URLs, empty anchors, duplicate detection, ignored non-link markup, malformed HTML, and CSV formula injection.
- Integration-oriented tests or static checks for capability/nonces, snapshot retention on failed scans, idempotent batch processing, and no scan on frontend/page-load hooks.
- Run PHP syntax checks, coding/static checks configured by the project, and the test suite.
- GitHub Actions must fail closed: do not create a distributable ZIP or GitHub Release when any required test/check fails.
- Package only installable plugin files; exclude tests, development dependencies, repository metadata, and CI configuration from the ZIP.

## Acceptance criteria

1. Installing the ZIP activates a standalone plugin and does not require the Senoobar theme.
2. Normal frontend page requests do not trigger scanner work, database inventory reads, or plugin assets.
3. Opening the admin screen never starts or resumes a scan automatically.
4. The last successful inventory survives navigation and subsequent sessions.
5. Clicking Rescan starts a full scan with visible progress and replaces the snapshot only after success.
6. A failed/cancelled scan leaves the previous successful inventory intact.
7. No cron event, persistent worker, frontend hook, external URL fetch, or automatic rescan is registered.
8. CI tests and package validation pass before an installable ZIP is published.
