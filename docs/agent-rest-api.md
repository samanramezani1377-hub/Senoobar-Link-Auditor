# Agent REST API integration

This document describes the supported bridge for a WordPress MCP/AI agent when native WordPress Abilities API discovery is unavailable.

## Important distinction: registered routes vs. MCP route grants

The plugin registers these routes under the WordPress REST namespace `sla/v1`. The connected MCP server may maintain its own route/method allowlist. Its `rest-list-routes` tool reports routes that the connection is allowed to call, not necessarily every route registered by WordPress. Therefore, a route missing from that tool's results does not by itself prove that the plugin failed to register it.

For the bridge to work, both conditions must be true:

1. The installed plugin version contains this API and WordPress has loaded it.
2. The MCP connection is authorized/configured to call the listed route and HTTP method.

Do not try to work around the second condition by making endpoints public. Keep the permission callback and grant only the required methods to the authenticated agent connection.

## Base URL and authentication

Base path: `https://YOUR-SITE.example/wp-json/sla/v1`

Every route checks `current_user_can('manage_options')`. Use HTTPS and an authenticated WordPress administrator account, preferably with a dedicated WordPress Application Password supported by the connector. Never send credentials in a prompt, query string, source file, or GitHub commit. These endpoints are not public.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/status` | Plugin version, latest successful report summary, and active scan progress |
| GET | `/report` | Read a page of the last successful report |
| POST | `/scans` | Explicitly start a staged scan |
| POST | `/scans/{id}/continue` | Process one bounded batch for the specified scan |
| GET | `/scans/{id}` | Read the status/progress of one scan |

### Read a report

Call `GET /report?page=1&per_page=50&link_type=all&source_type=all`.

- `per_page` is capped at 100.
- `link_type`: `all`, `internal`, or `external`.
- `source_type`: `all`, `post`, `page`, or `product`.
- `search` filters by source title, anchor text, or destination URL.
- If no successful scan exists, the response says `available: false`; it does not silently start a scan.

### Run a scan only on explicit request

1. Call `POST /scans` once. Save the returned `scan_id`.
2. Call `POST /scans/{scan_id}/continue` one time per request. Each call processes at most 20 published posts/pages/products.
3. Read `GET /scans/{scan_id}` and repeat step 2 only while the scan is still `running`.
4. When the scan reports `complete`, read `GET /report` to get the new successful snapshot.

If a scan is already running, the start endpoint returns HTTP 409 and its scan ID. Do not start concurrent scans. The previous successful report remains available until a new scan completes successfully.

The API does not fetch external destinations, crawl rendered pages, modify post/page/product content, create content, or run a persistent background worker. Starting and continuing a scan are state-changing database operations and must only be invoked after an explicit user request.

## MCP connector setup and diagnosis

1. Deploy the plugin build that contains this REST API and verify the plugin is active.
2. In the MCP connector's REST-route permissions, allow only the routes above and the listed HTTP methods. The connection must be allowed to use `GET` for status/report/scan status and `POST` for scan start/continue.
3. Use the connector's `rest-list-routes` tool with namespace `sla/v1`. If the route list is empty, check the connector's route grant/configuration first; this tool lists routes the connection is allowed to call.
4. Use `rest-describe-route` for each granted route/method to verify the accepted parameters.
5. Call `GET /sla/v1/status` before any scan. Do not call `POST /sla/v1/scans` as a diagnostic probe, because that actually starts a scan.

The API uses WordPress's normal REST authentication and capability checks. Do not remove `rest_admin_permission`, relax `manage_options`, or expose these routes to unauthenticated users to make discovery easier.
