<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
if (!defined('SLA_KEEP_DATA_ON_UNINSTALL') || SLA_KEEP_DATA_ON_UNINSTALL !== true) {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sla_links");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sla_sources");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sla_scans");
}
