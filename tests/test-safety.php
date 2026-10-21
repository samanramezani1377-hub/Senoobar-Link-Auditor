<?php
declare(strict_types=1);
function sla_safety_assert(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$main = file_get_contents(__DIR__ . '/../senoobar-link-auditor.php');
$js = file_get_contents(__DIR__ . '/../assets/js/admin.js');
$readme = file_get_contents(__DIR__ . '/../README.md');
sla_safety_assert(is_string($main) && is_string($js) && is_string($readme), 'plugin sources can be read');
sla_safety_assert(str_contains($main, "tools_page_senoobar-link-auditor"), 'assets are scoped to plugin admin screen');
sla_safety_assert(!preg_match('/wp_schedule_event|wp_schedule_single_event|wp_cron/i', $main), 'no scheduled scan hooks');
sla_safety_assert(!str_contains($main, 'register_rest_route') && !str_contains($main, 'wp_register_ability'), 'no agent REST routes or Abilities API tools');
sla_safety_assert(!str_contains($main, "add_action('rest_api_init'"), 'plugin does not register REST endpoints');
sla_safety_assert(str_contains($main, "add_action('wp_ajax_sla_start_scan'"), 'scan start is authenticated admin AJAX only');
sla_safety_assert(str_contains($main, "add_action('wp_ajax_sla_check_broken_batch'"), 'broken-link checks require authenticated admin AJAX');
sla_safety_assert(!str_contains($main, "add_action('wp_ajax_nopriv_"), 'no unauthenticated AJAX endpoints');
sla_safety_assert(str_contains($main, "current_user_can('manage_options')"), 'administrative capability is enforced');
sla_safety_assert(str_contains($main, 'target_post_id bigint(20) unsigned'), 'inbound-link target IDs are stored');
sla_safety_assert(str_contains($main, 'get_pages_without_incoming'), 'pages with no inbound links can be reported');
sla_safety_assert(str_contains($main, 'get_incoming_report'), 'inbound-link counts and sources are reported');
sla_safety_assert(str_contains($main, 'wp_safe_remote_head') && str_contains($main, 'wp_safe_remote_get'), 'manual link checks use safe HTTP methods');
sla_safety_assert(str_contains($main, "'timeout' => 3") && str_contains($main, "'redirection' => 2"), 'destination checks have bounded timeout and redirects');
sla_safety_assert(str_contains($main, "id=\\"sla-broken-links\\"") && str_contains($main, "id=\\"sla-incoming\\""), 'broken and inbound reports have copyable tables');
sla_safety_assert(str_contains($main, "redirect_chain text NULL"), 'redirect paths are stored in the snapshot');
sla_safety_assert(str_contains($main, "REDIRECT_HOP_LIMIT = 3") && str_contains($main, "'redirection' => 0"), 'redirect tracing has a strict hop cap and disables automatic following');
sla_safety_assert(str_contains($main, "'timeout' => 2") && str_contains($main, "wp_safe_remote_head"), 'HTTP checks use bounded timeouts and safe requests');
sla_safety_assert(str_contains($main, "function get_scan_diff") && str_contains($main, "new_problems") && str_contains($main, "resolved_problems"), 'history compares link changes and checked HTTP problems');
sla_safety_assert(str_contains($main, "HISTORY_LIMIT = 10") && str_contains($main, "ORDER BY completed_at DESC"), 'history retention is bounded');
sla_safety_assert(str_contains($main, "name=\\"check_status\\"") && str_contains($main, "HTTP status category"), 'HTTP status filter and CSV columns are available');
sla_safety_assert(str_contains($main, '[=+@-]') && str_contains($main, 'csv_safe'), 'CSV formula-leading values are neutralized');
sla_safety_assert(str_contains($main, 'ENGINE=InnoDB'), 'snapshot tables support transactional replacement');
sla_safety_assert(str_contains($main, "'\"', '');"), 'CSV export specifies empty escape parameter for PHP 8.4');
sla_safety_assert(str_contains($js, "await request('sla_scan_batch'"), 'scan batches are user-session driven');
sla_safety_assert(str_contains($js, "await request('sla_check_broken_batch'"), 'broken-link batches are user-session driven');
sla_safety_assert(str_contains($js, "navigator.clipboard.writeText"), 'each report supports clipboard copy');
sla_safety_assert(!preg_match('/setInterval\\s*\\(|setTimeout\\s*\\(/', $js), 'no background polling or timer is used');
sla_safety_assert(str_contains($readme, 'No agent abilities or custom agent REST endpoints'), 'documentation confirms agent integrations are removed');
echo "Safety checks passed (24 assertions).\n";
