<?php
/**
 * Plugin Name: Senoobar Link Auditor
 * Description: گزارش لینک‌های ذخیره‌شده در نوشته‌ها، برگه‌ها و محصولات ووکامرس؛ بدون تغییر محتوا.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Senoobar
 * License: GPL-2.0-or-later
 * Text Domain: senoobar-link-auditor
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SLA_VERSION', '0.1.0');
define('SLA_FILE', __FILE__);
define('SLA_DIR', plugin_dir_path(__FILE__));
define('SLA_URL', plugin_dir_url(__FILE__));

final class Senoobar_Link_Auditor {
    private const BATCH_SIZE = 20;
    private const PAGE_SIZE = 50;
    private string $scans_table;
    private string $links_table;
    private string $sources_table;

    public function __construct() {
        global $wpdb;
        $this->scans_table = $wpdb->prefix . 'sla_scans';
        $this->links_table = $wpdb->prefix . 'sla_links';
        $this->sources_table = $wpdb->prefix . 'sla_sources';

        register_activation_hook(SLA_FILE, [$this, 'activate']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_sla_start_scan', [$this, 'ajax_start_scan']);
        add_action('wp_ajax_sla_scan_batch', [$this, 'ajax_scan_batch']);
        add_action('wp_ajax_sla_cancel_scan', [$this, 'ajax_cancel_scan']);
        add_action('admin_post_sla_export_csv', [$this, 'export_csv']);
    }

    public function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $scans = $this->scans_table;
        $links = $this->links_table;
        $sources = $this->sources_table;

        dbDelta("CREATE TABLE {$scans} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'running',
            is_current tinyint(1) NOT NULL DEFAULT 0,
            started_at datetime NOT NULL,
            completed_at datetime NULL,
            last_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            total_posts int(10) unsigned NOT NULL DEFAULT 0,
            processed_posts int(10) unsigned NOT NULL DEFAULT 0,
            error_message text NULL,
            PRIMARY KEY  (id),
            KEY current_status (is_current,status),
            KEY status_started (status,started_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            source_title text NOT NULL,
            source_type varchar(20) NOT NULL,
            url text NOT NULL,
            anchor_text text NOT NULL,
            link_type varchar(10) NOT NULL,
            occurrence_no int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY occurrence (scan_id,source_post_id,occurrence_no),
            KEY scan_type (scan_id,link_type),
            KEY source_post (source_post_id),
            KEY scan_source_type (scan_id,source_type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$sources} (
            scan_id bigint(20) unsigned NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            source_title text NOT NULL,
            source_type varchar(20) NOT NULL,
            link_count int(10) unsigned NOT NULL DEFAULT 0,
            internal_count int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (scan_id,source_post_id),
            KEY scan_internal (scan_id,internal_count),
            KEY scan_type (scan_id,source_type)
        ) {$charset};");
    }

    public function admin_menu(): void {
        add_management_page(
            'Senoobar Link Auditor',
            'Link Auditor',
            'manage_options',
            'senoobar-link-auditor',
            [$this, 'render_admin']
        );
    }

    public function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'tools_page_senoobar-link-auditor') {
            return;
        }
        wp_enqueue_style('sla-admin', SLA_URL . 'assets/css/admin.css', [], SLA_VERSION);
        wp_enqueue_script('sla-admin', SLA_URL . 'assets/js/admin.js', [], SLA_VERSION, true);
        wp_localize_script('sla-admin', 'SLAConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sla_scan_action'),
            'batchSize' => self::BATCH_SIZE,
            'strings' => [
                'confirm' => 'بررسی کامل لینک‌ها شروع شود؟ گزارش قبلی تا پایان موفق بررسی حفظ می‌شود.',
                'starting' => 'در حال آماده‌سازی بررسی…',
                'working' => 'در حال بررسی محتوای ذخیره‌شده…',
                'done' => 'بررسی با موفقیت کامل شد.',
                'failed' => 'بررسی کامل نشد؛ گزارش قبلی حفظ شده است.',
                'cancelled' => 'بررسی لغو شد؛ گزارش قبلی حفظ شده است.',
            ],
        ]);
    }

    public function render_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'senoobar-link-auditor'));
        }

        $current = $this->current_scan();
        $counts = $current ? $this->report_counts((int) $current->id) : ['total' => 0, 'internal' => 0, 'external' => 0, 'empty' => 0, 'duplicates' => 0, 'without_internal' => 0];
        $page = max(1, absint($_GET['paged'] ?? 1));
        $type = sanitize_key($_GET['link_type'] ?? 'all');
        $source_type = sanitize_key($_GET['source_type'] ?? 'all');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $rows = $current ? $this->get_links((int) $current->id, $page, $type, $source_type, $search) : [];
        $total_pages = $current ? (int) ceil($this->count_links((int) $current->id, $type, $source_type, $search) / self::PAGE_SIZE) : 0;
        ?>
        <div class="wrap sla-wrap" dir="rtl">
            <h1>Senoobar Link Auditor</h1>
            <p>این ابزار فقط لینک‌های ذخیره‌شده در محتوای نوشته‌ها، برگه‌ها و محصولات را گزارش می‌کند. هیچ محتوایی را تغییر نمی‌دهد و آدرس مقصدها را از طریق اینترنت بررسی نمی‌کند.</p>
            <div class="sla-toolbar">
                <button type="button" class="button button-primary" id="sla-rescan">بررسی مجدد کامل</button>
                <button type="button" class="button" id="sla-cancel" hidden>لغو بررسی</button>
                <span id="sla-status" role="status" aria-live="polite"><?php echo $current ? esc_html('آخرین بررسی: ' . ($current->completed_at ?: $current->started_at)) : esc_html('هنوز بررسی موفقی انجام نشده است.'); ?></span>
            </div>
            <div id="sla-progress-wrap" hidden><progress id="sla-progress" max="100" value="0"></progress><span id="sla-progress-label">0%</span></div>
            <div id="sla-error" class="notice notice-error" hidden><p></p></div>

            <div class="sla-stats">
                <div><strong><?php echo esc_html((string) $counts['total']); ?></strong><span>کل لینک‌ها</span></div>
                <div><strong><?php echo esc_html((string) $counts['internal']); ?></strong><span>لینک داخلی</span></div>
                <div><strong><?php echo esc_html((string) $counts['external']); ?></strong><span>لینک خارجی</span></div>
                <div><strong><?php echo esc_html((string) $counts['empty']); ?></strong><span>لنگر خالی</span></div>
                <div><strong><?php echo esc_html((string) $counts['duplicates']); ?></strong><span>گروه لینک تکراری</span></div>
                <div><strong><?php echo esc_html((string) $counts['without_internal']); ?></strong><span>محتوای بدون لینک داخلی</span></div>
            </div>

            <?php if (!$current): ?>
                <div class="notice notice-info"><p>برای ساخت اولین گزارش، دکمه «بررسی مجدد کامل» را بزنید.</p></div>
            <?php else: ?>
                <form method="get" class="sla-filters">
                    <input type="hidden" name="page" value="senoobar-link-auditor">
                    <label>نوع لینک
                        <select name="link_type">
                            <option value="all" <?php selected($type, 'all'); ?>>همه</option>
                            <option value="internal" <?php selected($type, 'internal'); ?>>داخلی</option>
                            <option value="external" <?php selected($type, 'external'); ?>>خارجی</option>
                        </select>
                    </label>
                    <label>نوع محتوا
                        <select name="source_type">
                            <option value="all" <?php selected($source_type, 'all'); ?>>همه</option>
                            <option value="post" <?php selected($source_type, 'post'); ?>>مقاله</option>
                            <option value="page" <?php selected($source_type, 'page'); ?>>برگه</option>
                            <option value="product" <?php selected($source_type, 'product'); ?>>محصول</option>
                        </select>
                    </label>
                    <label>جست‌وجو
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="عنوان، متن لنگر یا آدرس">
                    </label>
                    <button class="button">اعمال فیلتر</button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sla_export_csv&scan_id=' . (int) $current->id . '&link_type=' . rawurlencode($type) . '&source_type=' . rawurlencode($source_type) . '&s=' . rawurlencode($search)), 'sla_export_csv')); ?>">خروجی CSV</a>
                </form>
                <table class="widefat striped sla-table">
                    <thead><tr><th>محتوای مبدأ</th><th>نوع</th><th>متن لنگر</th><th>آدرس مقصد</th><th>دسته</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5">موردی برای نمایش پیدا نشد.</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?php echo esc_url(get_edit_post_link((int) $row->source_post_id) ?: ''); ?>"><?php echo esc_html($row->source_title ?: '(بدون عنوان)'); ?></a><small>#<?php echo esc_html((string) $row->source_post_id); ?></small></td>
                            <td><?php echo esc_html($this->source_type_label((string) $row->source_type)); ?></td>
                            <td><?php echo $row->anchor_text !== '' ? esc_html($row->anchor_text) : '<span class="sla-empty">خالی</span>'; ?></td>
                            <td class="sla-url"><?php echo $row->url !== '' ? '<code>' . esc_html($row->url) . '</code>' : '<span class="sla-empty">بدون href</span>'; ?></td>
                            <td><?php echo $row->link_type === 'internal' ? 'داخلی' : 'خارجی'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post(paginate_links([
                        'base' => add_query_arg(['page' => 'senoobar-link-auditor', 'paged' => '%#%', 'link_type' => $type, 'source_type' => $source_type, 's' => $search], admin_url('tools.php')),
                        'format' => '', 'current' => $page, 'total' => $total_pages,
                    ])); ?>
                    </div></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($current): $no_internal_sources = $this->get_sources_without_internal((int) $current->id); ?>
                <h2>محتواهای بدون لینک داخلی (حداکثر 100 مورد)</h2>
                <table class="widefat striped"><thead><tr><th>عنوان محتوا</th><th>نوع</th><th>تعداد لینک‌ها</th></tr></thead><tbody>
                <?php if (!$no_internal_sources): ?><tr><td colspan="3">همهٔ محتواها حداقل یک لینک داخلی دارند.</td></tr>
                <?php else: foreach ($no_internal_sources as $source): ?><tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link((int) $source->source_post_id) ?: ''); ?>"><?php echo esc_html($source->source_title ?: '(بدون عنوان)'); ?></a></td>
                    <td><?php echo esc_html($this->source_type_label((string) $source->source_type)); ?></td>
                    <td><?php echo esc_html((string) $source->link_count); ?></td>
                </tr><?php endforeach; endif; ?>
                </tbody></table>
            <?php endif; ?>
            <p class="description">فهرست نشان‌دهندهٔ لینک‌های موجود در آخرین بررسی موفق است. لینک‌های تکراری عمداً جداگانه نگه داشته می‌شوند تا محل هر استفاده قابل شناسایی باشد.</p>
        </div>
        <?php
    }

    public function ajax_start_scan(): void {
        $this->verify_ajax_request();
        global $wpdb;

        // Reclaim abandoned scans only when an administrator explicitly starts a new scan.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->scans_table} SET status = %s WHERE status = %s AND started_at < DATE_SUB(%s, INTERVAL 2 HOUR)",
            'abandoned',
            'running',
            current_time('mysql')
        ));
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->scans_table} WHERE status = %s LIMIT 1", 'running'));
        if ($existing) {
            wp_send_json_error(['message' => 'یک بررسی دیگر هنوز فعال است. آن را لغو کنید یا کمی بعد دوباره تلاش کنید.'], 409);
        }

        $token = bin2hex(random_bytes(32));
        $total = (int) $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page','product')");
        $inserted = $wpdb->insert($this->scans_table, [
            'token_hash' => hash('sha256', $token),
            'status' => 'running',
            'is_current' => 0,
            'started_at' => current_time('mysql'),
            'last_post_id' => 0,
            'total_posts' => $total,
            'processed_posts' => 0,
        ], ['%s','%s','%d','%s','%d','%d','%d']);
        if (!$inserted) {
            wp_send_json_error(['message' => 'ذخیرهٔ وضعیت بررسی ممکن نشد.'], 500);
        }

        wp_send_json_success(['scanId' => (int) $wpdb->insert_id, 'token' => $token, 'total' => $total, 'processed' => 0]);
    }

    public function ajax_scan_batch(): void {
        $this->verify_ajax_request();
        require_once SLA_DIR . 'includes/class-sla-parser.php';
        global $wpdb;

        $scan_id = absint($_POST['scanId'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $scan = $this->get_authorized_scan($scan_id, $token);
        if (!$scan || $scan->status !== 'running') {
            wp_send_json_error(['message' => 'بررسی فعال معتبر پیدا نشد.'], 403);
        }

        $wpdb->query('START TRANSACTION');
        try {
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post','page','product') AND ID > %d
                 ORDER BY ID ASC LIMIT %d",
                (int) $scan->last_post_id,
                self::BATCH_SIZE
            ));

            if (!$posts) {
                $wpdb->update($this->scans_table, [
                    'status' => 'complete',
                    'is_current' => 1,
                    'completed_at' => current_time('mysql'),
                ], ['id' => $scan_id, 'status' => 'running'], ['%s','%d','%s'], ['%d','%s']);
                $wpdb->query($wpdb->prepare("UPDATE {$this->scans_table} SET is_current = 0 WHERE id <> %d AND is_current = 1", $scan_id));
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->links_table} WHERE scan_id <> %d", $scan_id));
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->sources_table} WHERE scan_id <> %d", $scan_id));
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->scans_table} WHERE id <> %d AND status <> %s", $scan_id, 'running'));
                $wpdb->query('COMMIT');
                wp_send_json_success(['done' => true, 'processed' => (int) $scan->processed_posts, 'total' => (int) $scan->total_posts, 'percent' => 100]);
            }

            $last_id = (int) $scan->last_post_id;
            $processed = (int) $scan->processed_posts;
            foreach ($posts as $post) {
                $last_id = (int) $post->ID;
                $occurrence = 0;
                $links = SLA_Parser::extract_links((string) $post->post_content, home_url('/'), get_permalink((int) $post->ID) ?: home_url('/'));
                $internal_count = count(array_filter($links, static fn(array $link): bool => $link['type'] === 'internal'));
                $source_saved = $wpdb->insert($this->sources_table, [
                    'scan_id' => $scan_id,
                    'source_post_id' => (int) $post->ID,
                    'source_title' => (string) $post->post_title,
                    'source_type' => (string) $post->post_type,
                    'link_count' => count($links),
                    'internal_count' => $internal_count,
                ], ['%d','%d','%s','%s','%d','%d']);
                if ($source_saved === false) {
                    throw new RuntimeException('source row insert failed');
                }
                foreach ($links as $link) {
                    $ok = $wpdb->insert($this->links_table, [
                        'scan_id' => $scan_id,
                        'source_post_id' => (int) $post->ID,
                        'source_title' => (string) $post->post_title,
                        'source_type' => (string) $post->post_type,
                        'url' => $link['url'],
                        'anchor_text' => $link['anchor'],
                        'link_type' => $link['type'],
                        'occurrence_no' => $occurrence++,
                    ], ['%d','%d','%s','%s','%s','%s','%s','%d']);
                    if ($ok === false) {
                        throw new RuntimeException('link row insert failed');
                    }
                }
                $processed++;
            }

            $wpdb->update($this->scans_table, [
                'last_post_id' => $last_id,
                'processed_posts' => $processed,
            ], ['id' => $scan_id, 'status' => 'running'], ['%d','%d'], ['%d','%s']);
            $wpdb->query('COMMIT');
            $percent = (int) min(99, floor(($processed / max(1, (int) $scan->total_posts)) * 100));
            wp_send_json_success(['done' => false, 'processed' => $processed, 'total' => (int) $scan->total_posts, 'percent' => $percent]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $wpdb->update($this->scans_table, ['status' => 'failed', 'error_message' => 'خطا هنگام ذخیره‌سازی نتایج.'], ['id' => $scan_id], ['%s','%s'], ['%d']);
            wp_send_json_error(['message' => 'بررسی به دلیل خطا کامل نشد؛ گزارش قبلی دست‌نخورده باقی ماند.'], 500);
        }
    }

    public function ajax_cancel_scan(): void {
        $this->verify_ajax_request();
        global $wpdb;
        $scan_id = absint($_POST['scanId'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $scan = $this->get_authorized_scan($scan_id, $token);
        if (!$scan || $scan->status !== 'running') {
            wp_send_json_error(['message' => 'بررسی فعال معتبر پیدا نشد.'], 403);
        }
        $wpdb->query('START TRANSACTION');
        $wpdb->delete($this->links_table, ['scan_id' => $scan_id], ['%d']);
        $wpdb->delete($this->sources_table, ['scan_id' => $scan_id], ['%d']);
        $wpdb->delete($this->scans_table, ['id' => $scan_id, 'status' => 'running'], ['%d','%s']);
        $wpdb->query('COMMIT');
        wp_send_json_success(['cancelled' => true]);
    }

    public function export_csv(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی غیرمجاز', 'senoobar-link-auditor'), '', ['response' => 403]);
        }
        check_admin_referer('sla_export_csv');
        $scan_id = absint($_GET['scan_id'] ?? 0);
        $scan = $this->current_scan();
        if (!$scan || (int) $scan->id !== $scan_id) {
            wp_die(esc_html__('گزارش انتخاب‌شده دیگر در دسترس نیست.', 'senoobar-link-auditor'), '', ['response' => 404]);
        }
        $type = sanitize_key($_GET['link_type'] ?? 'all');
        $source_type = sanitize_key($_GET['source_type'] ?? 'all');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="senoobar-link-auditor.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Source ID', 'Source title', 'Source type', 'Anchor text', 'Destination URL', 'Link type']);
        $page = 1;
        do {
            $rows = $this->get_links($scan_id, $page, $type, $source_type, $search, 1000);
            foreach ($rows as $row) {
                fputcsv($out, [
                (int) $row->source_post_id,
                $this->csv_safe((string) $row->source_title),
                $this->csv_safe($this->source_type_label((string) $row->source_type)),
                $this->csv_safe((string) $row->anchor_text),
                $this->csv_safe((string) $row->url),
                    $row->link_type,
                ]);
            }
            $page++;
        } while (count($rows) === 1000);
        fclose($out);
        exit;
    }

    private function verify_ajax_request(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        }
        check_ajax_referer('sla_scan_action', 'nonce');
    }

    private function get_authorized_scan(int $id, string $token): ?object {
        global $wpdb;
        if ($id < 1 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $scan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->scans_table} WHERE id = %d", $id));
        if (!$scan || !hash_equals((string) $scan->token_hash, hash('sha256', $token))) {
            return null;
        }
        return $scan;
    }

    private function current_scan(): ?object {
        global $wpdb;
        $scan = $wpdb->get_row("SELECT * FROM {$this->scans_table} WHERE is_current = 1 AND status = 'complete' ORDER BY id DESC LIMIT 1");
        return $scan ?: null;
    }

    private function report_counts(int $scan_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,
                SUM(link_type = 'internal') internal_count,
                SUM(link_type = 'external') external_count,
                SUM(anchor_text = '') empty_count
             FROM {$this->links_table} WHERE scan_id = %d",
            $scan_id
        ));
        $duplicates = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT source_post_id, url, anchor_text FROM {$this->links_table}
                WHERE scan_id = %d GROUP BY source_post_id, url, anchor_text HAVING COUNT(*) > 1
            ) AS duplicate_groups",
            $scan_id
        ));
        $without_internal = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sources_table} WHERE scan_id = %d AND internal_count = 0",
            $scan_id
        ));
        return [
            'total' => (int) ($row->total ?? 0),
            'internal' => (int) ($row->internal_count ?? 0),
            'external' => (int) ($row->external_count ?? 0),
            'empty' => (int) ($row->empty_count ?? 0),
            'duplicates' => $duplicates,
            'without_internal' => $without_internal,
        ];
    }

    private function get_sources_without_internal(int $scan_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT source_post_id, source_title, source_type, link_count FROM {$this->sources_table}
             WHERE scan_id = %d AND internal_count = 0 ORDER BY source_post_id ASC LIMIT 100",
            $scan_id
        )) ?: [];
    }

    private function count_links(int $scan_id, string $type, string $source_type, string $search): int {
        global $wpdb;
        [$where, $params] = $this->filter_sql($scan_id, $type, $source_type, $search);
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->links_table} WHERE {$where}", ...$params));
    }

    private function get_links(int $scan_id, int $page, string $type, string $source_type, string $search, int $limit = self::PAGE_SIZE): array {
        global $wpdb;
        [$where, $params] = $this->filter_sql($scan_id, $type, $source_type, $search);
        $limit = max(1, min(100000, $limit));
        $offset = $limit === 100000 ? 0 : (max(1, $page) - 1) * $limit;
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_post_id, source_title, source_type, url, anchor_text, link_type
             FROM {$this->links_table} WHERE {$where}
             ORDER BY source_post_id ASC, occurrence_no ASC LIMIT %d OFFSET %d",
            ...$params
        )) ?: [];
    }

    private function filter_sql(int $scan_id, string $type, string $source_type, string $search): array {
        global $wpdb;
        $where = 'scan_id = %d';
        $params = [$scan_id];
        if (in_array($type, ['internal', 'external'], true)) {
            $where .= ' AND link_type = %s';
            $params[] = $type;
        }
        if (in_array($source_type, ['post', 'page', 'product'], true)) {
            $where .= ' AND source_type = %s';
            $params[] = $source_type;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (source_title LIKE %s OR anchor_text LIKE %s OR url LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        return [$where, $params];
    }

    private function source_type_label(string $type): string {
        return match ($type) {
            'post' => 'مقاله',
            'page' => 'برگه',
            'product' => 'محصول',
            default => $type,
        };
    }

    private function csv_safe(string $value): string {
        // Prefix formula-leading cells so spreadsheet software treats them as text.
        if (preg_match('/^[\x00-\x20]*[=+@-]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}

new Senoobar_Link_Auditor();
