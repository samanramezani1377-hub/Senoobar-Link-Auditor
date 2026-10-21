# Agent integrations removed

Senoobar Link Auditor no longer registers WordPress Abilities API tools or custom REST endpoints for agent access. The plugin intentionally exposes no `sla/v1` REST routes.

Use the WordPress admin screen at Tools → Link Auditor. All content scans and broken-link checks must be initiated manually by an authorized administrator. The plugin does not run a persistent background worker or cron job.

Security note: do not reintroduce agent-facing endpoints or public permissions as part of link auditing. If a future integration is requested, it must be designed as a separate, explicitly authorized component.
