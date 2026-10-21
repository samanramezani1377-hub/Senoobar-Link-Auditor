# Senoobar Link Auditor Implementation Plan

> Required implementation workflow: execute this plan task-by-task; every checkbox is a verifiable step.

**Goal:** Build a standalone WordPress plugin that stores a durable link inventory and only rescans after an administrator explicitly starts a scan, without adding frontend work or persistent background workers.

**Architecture:** Keep all runtime hooks and assets admin-only. Scan published posts/pages/products in bounded, token-protected AJAX batches initiated by the administrator's open scan screen; write results to a staging scan ID and mark a completed scan as current only after success, retaining the prior snapshot on failure/cancel. Store data in plugin-owned indexed tables and render the saved snapshot without rescanning.

**Tech Stack:** PHP compatible with WordPress conventions, WordPress APIs and $wpdb, vanilla admin JavaScript/CSS, PHPUnit-oriented isolated unit tests, GitHub Actions.

**Spec:** docs/superpowers/specs/2026-10-21-link-auditor-design.md

## Global Constraints

- Never run scanner logic, inventory queries, assets, remote requests, or other plugin work on frontend requests.
- Never register cron jobs, recurring schedules, persistent workers, service workers, or idle polling.
- Never start a scan on activation or admin-page load.
- Keep the previous successful snapshot visible until a new scan completes successfully.
- Only inspect saved content from published posts, pages, and products; do not crawl rendered HTML or fetch destination URLs.
- All scan requests require a capability check, nonce, and scan token.
- Use bounded batches, indexed tables, prepared queries, contextual output escaping, and safe CSV export.
- CI must fail closed; package ZIP only after all required checks pass.

## Review Focus

- Malformed HTML and relative/protocol-relative URLs must not crash parsing or be misclassified.
- A lost/replayed AJAX request must not duplicate inventory rows or advance the wrong scan.
- A failed or cancelled rescan must not replace the last good snapshot.
- Search and CSV values containing HTML, quotes, commas, newlines, or spreadsheet formula prefixes must remain safe.
- Frontend hooks and ordinary admin page loads must not start scans or query inventory.

---

### Task 1: Plugin scaffold, activation, and persistence schema

**Files:**
- Create: senoobar-link-auditor.php
- Create: includes/class-sla-installer.php
- Create: includes/class-sla-admin.php
- Create: uninstall.php
- Create: readme.txt
- Create: README.md
- Create: .distignore

**Interfaces:**
- Installer creates versioned, indexed plugin-owned tables using dbDelta().
- Main plugin file defines constants, loads classes, and registers only required hooks.
- Admin screen requires manage_options; activation must not scan content.

- [ ] Add tests/static assertions that activation creates schema only and registers no cron.
- [ ] Implement versioned tables for scan metadata and per-scan link rows, including source post ID, URL, anchor text, link type, and uniqueness/idempotency keys.
- [ ] Implement admin-only screen registration and a saved-snapshot empty state.
- [ ] Document data retention and uninstall behavior; uninstall removes plugin data only according to the documented cleanup policy.
- [ ] Run PHP syntax checks and scaffold tests.

### Task 2: Pure link parsing and classification

**Files:**
- Create: includes/class-sla-scanner.php
- Create: tests/bootstrap.php
- Create: tests/test-link-parser.php

**Interfaces:**
- Parser extracts anchors from saved post_content only and returns normalized records with destination URL and anchor text.
- URL classifier resolves relative URLs against the site's home URL and classifies same-site hosts as internal.
- Parser does not make network requests and does not execute markup or scripts.

- [ ] Write tests for internal/external URLs, relative and protocol-relative URLs, empty anchors, duplicate links, malformed HTML, and irrelevant non-anchor markup.
- [ ] Run tests and confirm expected initial failures.
- [ ] Implement parser with a safe HTML parsing approach compatible with the supported PHP floor; restore libxml state after parsing and suppress parser warnings without leaking global state.
- [ ] Normalize destinations consistently while preserving useful query strings and fragments; avoid merging distinct destinations incorrectly.
- [ ] Run parser tests and PHP syntax checks.

### Task 3: Explicit, bounded scan lifecycle and durable snapshots

**Files:**
- Modify: includes/class-sla-scanner.php
- Create: includes/class-sla-scan-controller.php
- Create: tests/test-scan-lifecycle.php

**Interfaces:**
- Admin AJAX actions: start scan, process next bounded batch, cancel scan, and fetch status for the active scan.
- Start returns an opaque scan token and initial progress; each subsequent batch request is initiated by the admin UI only while that explicitly started scan is active.
- Each request validates manage_options, nonce, token ownership/state, and bounded batch size.
- Batch processing is idempotent; a completed scan becomes current only after all eligible source records have been processed successfully.

- [ ] Write tests for idempotent batches, authorization rejection, token mismatch, cancellation, and preservation of the old snapshot on failure.
- [ ] Implement staging scans with a stable source cursor (e.g. post ID), bounded SQL queries, and deduplicated rows.
- [ ] On success, atomically mark the new scan as current and retire prior scan rows according to retention policy; on error/cancel, keep the old current snapshot untouched.
- [ ] Expire stale in-progress scan metadata without scheduled cleanup; do not create cron events or background processes.
- [ ] Ensure the UI sends another batch only after receiving a response for the current active scan; no idle polling and no automatic resume after page reload.
- [ ] Run lifecycle tests and inspect registered hooks for cron or frontend behavior.

### Task 4: Admin inventory, filters, reports, and CSV export

**Files:**
- Create: includes/class-sla-reports.php
- Create: includes/class-sla-export.php
- Create: assets/js/admin.js
- Create: assets/css/admin.css
- Modify: includes/class-sla-admin.php
- Create: tests/test-reports-export.php

**Interfaces:**
- Saved snapshot list supports internal/external type, source content type, URL/anchor search, and pagination.
- Reports identify duplicate destination/anchor combinations, empty anchor text, and source content with no internal links.
- CSV export uses the selected saved scan, checks capability and nonce, and safely encodes cells.

- [ ] Test filtering, pagination boundaries, report counts, and CSV formula injection (cells beginning with =, +, -, or @).
- [ ] Implement indexed, paginated reads from the saved snapshot; opening the admin page must never scan content.
- [ ] Enqueue JS/CSS only on this plugin's admin page.
- [ ] Add a clear Rescan button, progress and error feedback, cancellation, and a saved-snapshot view that remains visible during rescans.
- [ ] Add safe CSV export and verify that no content is modified.
- [ ] Run all report/export tests and syntax checks.

### Task 5: CI, package validation, and installable ZIP

**Files:**
- Create: .github/workflows/ci.yml
- Create: composer.json
- Create: phpunit.xml.dist
- Create: scripts/build-plugin-zip.sh
- Create: tests/test-package.php

**Interfaces:**
- CI runs on pushes, pull requests, and version tags.
- Required jobs include PHP syntax checks, unit tests, static/security checks available in the project, and package-content validation.
- ZIP creation and GitHub Release publishing run only after all required checks pass; release publication occurs only for a version tag.

- [ ] Add reproducible test dependencies and a PHP version matrix appropriate to the declared compatibility floor.
- [ ] Configure CI to fail if any test/check fails; ensure packaging depends on the successful test job.
- [ ] Build ZIP with the plugin directory at archive root and exclude tests, CI files, repository metadata, and development dependencies.
- [ ] Test the ZIP contains the main plugin file and required runtime files but no development-only files.
- [ ] Run the full suite and verify workflow job dependencies and artifact/release conditions.

### Task 6: Final verification and release readiness

**Files:**
- Update: README.md
- Update: readme.txt

- [ ] Review all PHP/JS changes for frontend hooks, remote calls, cron schedules, unbounded queries, and unsafe output.
- [ ] Run the complete test suite and package build locally or in CI; record actual results.
- [ ] Verify the installable ZIP artifact exists only after all checks pass.
- [ ] Document installation, supported content types, scan lifecycle, data retention, limitations, and how to download the artifact.
