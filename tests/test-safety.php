<?php
declare(strict_types=1);
function sla_safety_assert(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$main = file_get_contents(__DIR__ . '/../senoobar-link-auditor.php');
$js = file_get_contents(__DIR__ . '/../assets/js/admin.js');
sla_safety_assert(is_string($main) && is_string($js), 'plugin sources can be read');
sla_safety_assert(str_contains($main, "tools_page_senoobar-link-auditor"), 'assets are scoped to the plugin admin screen');
sla_safety_assert(!preg_match('/wp_schedule_event|wp_schedule_single_event|wp_cron/i', $main), 'no scheduled scan hooks are registered');
sla_safety_assert(str_contains($main, "register_rest_route('sla/v1', '/report'"), 'agent report REST route is registered');
sla_safety_assert(str_contains($main, "register_rest_route('sla/v1', '/scans'"), 'explicit scan REST route is registered');
sla_safety_assert(str_contains($main, "'permission_callback' => [\$this, 'rest_admin_permission']"), 'REST routes require administrator permission');
sla_safety_assert(str_contains($main, "return current_user_can('manage_options');"), 'REST permission callback checks manage_options');
sla_safety_assert(str_contains($main, "wp_abilities_api_categories_init"), 'custom Abilities API category is registered before abilities');
sla_safety_assert(str_contains($main, "'category' => 'senoobar-link-auditor'"), 'abilities use the plugin-owned category');
sla_safety_assert(substr_count($main, "'show_in_rest' => true") === 2, 'both abilities are exposed through the Abilities REST API');
sla_safety_assert(substr_count($main, "'mcp' => ['public' => true]") === 2, 'both abilities are discoverable as MCP tools');
sla_safety_assert(substr_count($main, "'public' => false") === 2, 'abilities do not opt into broad public exposure');
sla_safety_assert(str_contains($main, "'readonly' => true") && str_contains($main, "'readonly' => false"), 'ability annotations distinguish report reads from scan actions');
sla_safety_assert(str_contains($main, "add_action('wp_ajax_sla_start_scan'"), 'scan start is authenticated admin AJAX only');
sla_safety_assert(!str_contains($main, "add_action('wp_ajax_nopriv_"), 'no unauthenticated scan endpoint exists');
sla_safety_assert(str_contains($main, "current_user_can('manage_options')"), 'administrative capability is enforced');
sla_safety_assert(str_contains($main, '[=+@-]') && str_contains($main, 'csv_safe'), 'CSV formula-leading values are neutralized');
sla_safety_assert(str_contains($main, 'ENGINE=InnoDB'), 'snapshot tables support transactional replacement');
sla_safety_assert(str_contains($main, ", '\"', '')"), 'CSV export specifies an empty escape parameter for PHP 8.4');
sla_safety_assert(str_contains($js, "await request('sla_scan_batch'"), 'scan batches are user-session driven');
sla_safety_assert(!preg_match('/setInterval\\s*\\(|setTimeout\\s*\\(/', $js), 'no background polling or timer is used');
echo "Safety checks passed (15 assertions).\n";
