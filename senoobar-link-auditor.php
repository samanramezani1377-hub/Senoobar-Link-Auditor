<?php
/**
 * Plugin Name: Senoobar Link Auditor
 * Description: گزارش لینک‌های ذخیره‌شده در نوشته‌ها، برگه‌ها و محصولات ووکامرس؛ بدون تغییر محتوا.
 * Version: 0.3.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Senoobar
 * License: GPL-2.0-or-later
 * Text Domain: senoobar-link-auditor
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SLA_VERSION', '0.3.0');
define('SLA_FILE', __FILE__);
define('SLA_DIR', plugin_dir_path(__FILE__));
define('SLA_URL', plugin_dir_url(__FILE__));

final class Senoobar_Link_Auditor {
    private const BATCH_SIZE = 20;
    private const PAGE_SIZE = 50;
    private const HISTORY_LIMIT = 10;
    private const REDIRECT_HOP_LIMIT = 3;
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
        add_action('admin_init', [$this, 'maybe_upgrade_schema']);
        add_action('wp_ajax_sla_check_broken_batch', [$this, 'ajax_check_broken_batch']);
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
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            source_title text NOT NULL,
            source_type varchar(20) NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            check_status varchar(20) NOT NULL DEFAULT 'unchecked',
            check_code smallint(5) unsigned NOT NULL DEFAULT 0,
            check_message varchar(255) NOT NULL DEFAULT '',
            redirect_chain text NULL,
            checked_at datetime NULL,
            url text NOT NULL,
            anchor_text text NOT NULL,
            link_type varchar(10) NOT NULL,
            occurrence_no int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY occurrence (scan_id,source_post_id,occurrence_no),
            KEY scan_type (scan_id,link_type),
            KEY source_post (source_post_id),
            KEY scan_source_type (scan_id,source_type),
            KEY scan_target (scan_id,target_post_id),
            KEY scan_check_status (scan_id,check_status)
        ) ENGINE=InnoDB {$charset};");

        update_option('sla_db_version', SLA_VERSION, false);

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
        ) ENGINE=InnoDB {$charset};");
    }

    public function maybe_upgrade_schema(): void {
        if (get_option('sla_db_version') !== SLA_VERSION) $this->activate();
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
                'checking' => 'در حال بررسی دستی مقصد لینک‌ها…',
                'checkDone' => 'بررسی مقصد لینک‌ها تمام شد.',
                'copyDone' => 'گزارش کپی شد.',
            ],
        ]);
    }

    public function render_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'senoobar-link-auditor'));
        }

        $current = $this->current_scan();
        $counts = $current ? $this->report_counts((int) $current->id) : ['total' => 0, 'internal' => 0, 'external' => 0, 'empty' => 0, 'duplicates' => 0, 'without_internal' => 0, 'broken' => 0];
        $page = max(1, absint($_GET['paged'] ?? 1));
        $type = sanitize_key($_GET['link_type'] ?? 'all');
        $source_type = sanitize_key($_GET['source_type'] ?? 'all');
        $check_status = sanitize_key($_GET['check_status'] ?? 'all');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $rows = $current ? $this->get_links((int) $current->id, $page, $type, $source_type, $search, self::PAGE_SIZE, $check_status) : [];
        $total_pages = $current ? (int) ceil($this->count_links((int) $current->id, $type, $source_type, $search, $check_status) / self::PAGE_SIZE) : 0;
        ?>
        <div class="wrap sla-wrap" dir="rtl">
            <h1>Senoobar Link Auditor</h1>
            <p>این ابزار فقط در پیشخوان اجرا می‌شود. اسکن محتوا و بررسی مقصد لینک‌ها فقط با کلیک شما شروع می‌شود؛ هیچ محتوایی تغییر نمی‌کند.</p>
            <div class="sla-toolbar">
                <button type="button" class="button button-primary" id="sla-rescan">بررسی مجدد کامل</button>
                <button type="button" class="button" id="sla-cancel" hidden>لغو بررسی</button>
                <button type="button" class="button" id="sla-check-broken" <?php disabled(!$current); ?>>بررسی دستی لینک‌های شکسته</button>
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
                <div><strong><?php echo esc_html((string) $counts['without_internal']); ?></strong><span>صفحات بدون لینک ورودی</span></div>
                <div><strong><?php echo esc_html((string) $counts['broken']); ?></strong><span>لینک شکسته</span></div>
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
                    <label>وضعیت HTTP
                        <select name="check_status">
                            <option value="all" <?php selected($check_status, 'all'); ?>>همه</option>
                            <option value="unchecked" <?php selected($check_status, 'unchecked'); ?>>بررسی‌نشده</option>
                            <option value="ok" <?php selected($check_status, 'ok'); ?>>سالم</option>
                            <option value="redirect" <?php selected($check_status, 'redirect'); ?>>ریدایرکت</option>
                            <option value="redirect_chain" <?php selected($check_status, 'redirect_chain'); ?>>زنجیرهٔ ریدایرکت</option>
                            <option value="redirect_loop" <?php selected($check_status, 'redirect_loop'); ?>>حلقه/زنجیرهٔ بیش‌ازحد</option>
                            <option value="broken" <?php selected($check_status, 'broken'); ?>>خطای HTTP</option>
                            <option value="unknown" <?php selected($check_status, 'unknown'); ?>>نامشخص</option>
                        </select>
                    </label>
                    <label>جست‌وجو
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="عنوان، متن لنگر یا آدرس">
                    </label>
                    <button class="button">اعمال فیلتر</button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sla_export_csv&scan_id=' . (int) $current->id . '&link_type=' . rawurlencode($type) . '&source_type=' . rawurlencode($source_type) . '&check_status=' . rawurlencode($check_status) . '&s=' . rawurlencode($search)), 'sla_export_csv')); ?>">خروجی CSV</a>
                </form>
                <h2>فهرست لینک‌ها <button type="button" class="button sla-copy-report" data-copy="sla-link-inventory">کپی این گزارش</button></h2>
                <table class="widefat striped sla-table" id="sla-link-inventory">
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
                        'base' => add_query_arg(['page' => 'senoobar-link-auditor', 'paged' => '%#%', 'link_type' => $type, 'source_type' => $source_type, 'check_status' => $check_status, 's' => $search], admin_url('tools.php')),
                        'format' => '', 'current' => $page, 'total' => $total_pages,
                    ])); ?>
                    </div></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($current): $no_internal_sources = $this->get_pages_without_incoming((int) $current->id); ?>
                <?php $history = $this->get_scan_history((int) $current->id); $diff = $this->get_scan_diff((int) $current->id); ?>
                <h2>تاریخچه و مقایسهٔ اسکن‌ها</h2>
                <?php if ($diff['previous']): ?>
                    <p>مقایسهٔ آخرین اسکن موفق با اسکن قبلی: <strong><?php echo esc_html((string) $diff['added']); ?></strong> لینک/مورد جدید، <strong><?php echo esc_html((string) $diff['removed']); ?></strong> لینک/مورد حذف‌شده یا رفع‌شده.</p>
                    <?php if ($diff['http_comparable']): ?>
                        <p>مقایسهٔ مشکلات HTTP (پس از بررسی دستی هر دو اسکن): <strong><?php echo esc_html((string) $diff['new_problems']); ?></strong> مشکل جدید، <strong><?php echo esc_html((string) $diff['resolved_problems']); ?></strong> مشکل رفع‌شده.</p>
                    <?php else: ?>
                        <p class="description">برای مقایسهٔ مشکلات HTTP، لینک‌های هر دو اسکن باید با دکمهٔ بررسی دستی بررسی شده باشند؛ فعلاً فقط تغییرات فهرست لینک‌ها مقایسه می‌شود.</p>
                    <?php endif; ?>
                    <table class="widefat striped" id="sla-scan-diff"><thead><tr><th>تغییر</th><th>مبدأ</th><th>آدرس مقصد</th><th>متن لنگر</th></tr></thead><tbody>
                    <?php foreach ($diff['samples'] as $change): ?><tr><td><?php echo esc_html($change['change']); ?></td><td><?php echo esc_html($change['source_title']); ?></td><td class="sla-url"><code><?php echo esc_html($change['url']); ?></code></td><td><?php echo esc_html($change['anchor']); ?></td></tr><?php endforeach; ?>
                    <?php if (!$diff['samples']): ?><tr><td colspan="4">بین دو اسکن تغییر لینک قابل گزارش پیدا نشد.</td></tr><?php endif; ?>
                    </tbody></table>
                <?php else: ?><p>برای مقایسه، حداقل دو اسکن موفق لازم است.</p><?php endif; ?>
                <table class="widefat striped" id="sla-scan-history"><thead><tr><th>شناسه</th><th>شروع</th><th>پایان</th><th>محتوای بررسی‌شده</th><th>تعداد لینک‌ها</th><th>وضعیت</th></tr></thead><tbody>
                <?php foreach ($history as $past): ?><tr><td><?php echo esc_html((string) $past->id); ?></td><td><?php echo esc_html((string) $past->started_at); ?></td><td><?php echo esc_html((string) ($past->completed_at ?: '—')); ?></td><td><?php echo esc_html((string) $past->processed_posts); ?></td><td><?php echo esc_html((string) $past->link_count); ?></td><td><?php echo (int) $past->id === (int) $current->id ? 'اسکن فعلی' : 'اسکن قبلی'; ?></td></tr><?php endforeach; ?>
                </tbody></table>
                <h2>صفحات بدون لینک ورودی (حداکثر 100 مورد) <button type="button" class="button sla-copy-report" data-copy="sla-no-incoming">کپی این گزارش</button></h2>
                <table class="widefat striped" id="sla-no-incoming"><thead><tr><th>عنوان محتوا</th><th>نوع</th><th>تعداد لینک خروجی</th></tr></thead><tbody>
                <?php if (!$no_internal_sources): ?><tr><td colspan="3">همهٔ محتواها حداقل یک لینک داخلی دارند.</td></tr>
                <?php else: foreach ($no_internal_sources as $source): ?><tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link((int) $source->source_post_id) ?: ''); ?>"><?php echo esc_html($source->source_title ?: '(بدون عنوان)'); ?></a></td>
                    <td><?php echo esc_html($this->source_type_label((string) $source->source_type)); ?></td>
                    <td><?php echo esc_html((string) $source->link_count); ?></td>
                </tr><?php endforeach; endif; ?>
                </tbody></table>
                <h2>تعداد لینک‌های داخلی ورودی به هر صفحه <button type="button" class="button sla-copy-report" data-copy="sla-incoming">کپی این گزارش</button></h2>
                <?php $incoming = $this->get_incoming_report((int) $current->id); ?>
                <table class="widefat striped" id="sla-incoming"><thead><tr><th>صفحه مقصد</th><th>تعداد لینک ورودی</th><th>صفحات لینک‌دهنده</th></tr></thead><tbody>
                <?php foreach ($incoming as $target): ?><tr><td><?php echo esc_html($target['title']); ?></td><td><?php echo esc_html((string) $target['count']); ?></td><td><?php echo esc_html(implode(' | ', $target['sources'])); ?></td></tr><?php endforeach; ?>
                <?php if (!$incoming): ?><tr><td colspan="3">داده‌ای برای نمایش وجود ندارد.</td></tr><?php endif; ?>
                </tbody></table>
                <h2>لینک‌های شکسته <button type="button" class="button sla-copy-report" data-copy="sla-broken-links">کپی این گزارش</button></h2>
                <p class="description">با دکمهٔ بررسی دستی، مقصدها در دسته‌های کوچک بررسی می‌شوند. خطای اتصال به‌تنهایی به‌عنوان لینک شکسته قطعی ثبت نمی‌شود.</p>
                <table class="widefat striped" id="sla-broken-links"><thead><tr><th>صفحه مبدأ</th><th>آدرس مقصد</th><th>نتیجه</th><th>HTTP</th></tr></thead><tbody>
                <?php $broken = $this->get_checked_links((int) $current->id, ['broken']); foreach ($broken as $item): ?><tr><td><?php echo esc_html($item->source_title); ?></td><td class="sla-url"><code><?php echo esc_html($item->url); ?></code></td><td><?php echo esc_html($item->check_message); ?></td><td><?php echo esc_html((string) $item->check_code); ?></td></tr><?php endforeach; ?>
                <?php if (!$broken): ?><tr><td colspan="4">هنوز لینک شکستهٔ تأییدشده‌ای ثبت نشده است.</td></tr><?php endif; ?>
                </tbody></table>
            <?php endif; ?>
            <p class="description">فهرست لینک‌ها از محتوای ذخیره‌شده ساخته می‌شود؛ لینک‌های تکراری جداگانه حفظ می‌شوند.</p>
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
                // Keep a bounded history of completed snapshots; active scans are never removed here.
                $keep_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->scans_table} WHERE status = %s ORDER BY completed_at DESC, id DESC LIMIT %d", 'complete', self::HISTORY_LIMIT));
                $keep_ids = array_map('intval', $keep_ids ?: []);
                if ($keep_ids) {
                    $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
                    $old_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->scans_table} WHERE status = %s AND id NOT IN ({$placeholders})", ...array_merge(['complete'], $keep_ids)));
                    foreach (array_map('intval', $old_ids ?: []) as $old_id) {
                        $wpdb->delete($this->links_table, ['scan_id' => $old_id], ['%d']);
                        $wpdb->delete($this->sources_table, ['scan_id' => $old_id], ['%d']);
                        $wpdb->delete($this->scans_table, ['id' => $old_id, 'status' => 'complete'], ['%d', '%s']);
                    }
                }
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
                        'target_post_id' => ($link['type'] === 'internal' && $link['url'] !== '') ? (int) url_to_postid($link['url']) : 0,
                    ], ['%d','%d','%s','%s','%s','%s','%s','%d','%d']);
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
        $check_status = sanitize_key($_GET['check_status'] ?? 'all');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="senoobar-link-auditor.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Source ID', 'Source title', 'Source type', 'Anchor text', 'Destination URL', 'Link type', 'HTTP status category', 'HTTP code', 'Check details'], ',', '"', '');
        $page = 1;
        do {
            $rows = $this->get_links($scan_id, $page, $type, $source_type, $search, 1000, $check_status);
            foreach ($rows as $row) {
                fputcsv($out, [
                (int) $row->source_post_id,
                $this->csv_safe((string) $row->source_title),
                $this->csv_safe($this->source_type_label((string) $row->source_type)),
                $this->csv_safe((string) $row->anchor_text),
                $this->csv_safe((string) $row->url),
                    $row->link_type,
                    (string) $row->check_status,
                    (int) $row->check_code,
                    $this->csv_safe((string) $row->check_message),
                ], ',', '"', '');
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
            "SELECT COUNT(*) FROM {$this->sources_table} s
             LEFT JOIN (SELECT DISTINCT scan_id, target_post_id FROM {$this->links_table} WHERE scan_id = %d AND target_post_id > 0) t
             ON t.scan_id = s.scan_id AND t.target_post_id = s.source_post_id
             WHERE s.scan_id = %d AND t.target_post_id IS NULL",
            $scan_id, $scan_id
        ));
        return [
            'total' => (int) ($row->total ?? 0),
            'internal' => (int) ($row->internal_count ?? 0),
            'external' => (int) ($row->external_count ?? 0),
            'empty' => (int) ($row->empty_count ?? 0),
            'duplicates' => $duplicates,
            'without_internal' => $without_internal,
            'redirects' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->links_table} WHERE scan_id = %d AND check_status IN (%s,%s,%s)", $scan_id, 'redirect', 'redirect_chain', 'redirect_loop')),
            'broken' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->links_table} WHERE scan_id = %d AND check_status = %s", $scan_id, 'broken')),
        ];
    }

    private function get_pages_without_incoming(int $scan_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT s.source_post_id, s.source_title, s.source_type, s.link_count FROM {$this->sources_table} s LEFT JOIN (SELECT DISTINCT scan_id, target_post_id FROM {$this->links_table} WHERE scan_id = %d AND target_post_id > 0) t ON t.scan_id = s.scan_id AND t.target_post_id = s.source_post_id WHERE s.scan_id = %d AND t.target_post_id IS NULL ORDER BY s.source_title ASC LIMIT 100", $scan_id, $scan_id)) ?: [];
    }

    private function get_incoming_report(int $scan_id): array {
        global $wpdb;
        $targets = $wpdb->get_results($wpdb->prepare("SELECT target_post_id, COUNT(*) AS incoming_count FROM {$this->links_table} WHERE scan_id = %d AND target_post_id > 0 GROUP BY target_post_id ORDER BY incoming_count DESC, target_post_id ASC LIMIT 200", $scan_id)) ?: [];
        $report = [];
        foreach ($targets as $target) {
            $sources = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT source_title FROM {$this->links_table} WHERE scan_id = %d AND target_post_id = %d ORDER BY source_title ASC LIMIT 30", $scan_id, (int) $target->target_post_id));
            $report[] = ['title' => (string) (get_the_title((int) $target->target_post_id) ?: ('محتوا #' . (int) $target->target_post_id)), 'count' => (int) $target->incoming_count, 'sources' => array_map('strval', $sources ?: [])];
        }
        return $report;
    }

    private function get_checked_links(int $scan_id, array $statuses): array {
        global $wpdb;
        $statuses = array_values(array_filter($statuses, static fn($status) => in_array($status, ['broken', 'ok', 'unknown', 'redirect', 'redirect_chain', 'redirect_loop'], true)));
        if (!$statuses) return [];
        $holders = implode(',', array_fill(0, count($statuses), '%s'));
        return $wpdb->get_results($wpdb->prepare("SELECT source_post_id, source_title, url, check_status, check_code, check_message, redirect_chain FROM {$this->links_table} WHERE scan_id = %d AND check_status IN ({$holders}) ORDER BY check_status, source_post_id LIMIT 500", ...array_merge([$scan_id], $statuses))) ?: [];
    }

    public function ajax_check_broken_batch(): void {
        $this->verify_ajax_request();
        global $wpdb;
        $scan = $this->current_scan();
        if (!$scan) wp_send_json_error(['message' => 'ابتدا یک اسکن کامل و موفق اجرا کنید.'], 400);

        // Three URLs per request; each URL follows at most three redirects with a 2-second timeout.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, url FROM {$this->links_table} WHERE scan_id = %d AND check_status = %s AND url LIKE %s ORDER BY id ASC LIMIT 3",
            (int) $scan->id, 'unchecked', 'http%'
        ));
        if (!$rows) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->links_table} WHERE scan_id = %d AND check_status = %s AND url LIKE %s",
                (int) $scan->id, 'unchecked', 'http%'
            ));
            wp_send_json_success(['done' => true, 'checked' => 0, 'remaining' => $remaining]);
        }

        foreach ($rows as $row) {
            $current_url = esc_url_raw((string) $row->url, ['http', 'https']);
            $visited = [];
            $codes = [];
            $status = 'unknown';
            $code = 0;
            $message = 'نتیجه نامشخص؛ نیازمند بررسی دستی';
            $chain = [];
            if ($current_url === '' || !wp_http_validate_url($current_url)) {
                $message = 'آدرس نامعتبر یا غیرقابل بررسی';
            } else {
                for ($hop = 0; $hop <= self::REDIRECT_HOP_LIMIT; $hop++) {
                    $normalized = strtolower(rtrim($current_url, '/'));
                    if (isset($visited[$normalized])) {
                        $status = 'redirect_loop';
                        $message = 'حلقهٔ ریدایرکت شناسایی شد';
                        $chain[] = 'LOOP ' . $current_url;
                        break;
                    }
                    $visited[$normalized] = true;
                    $response = wp_safe_remote_head($current_url, [
                        'timeout' => 2,
                        'redirection' => 0,
                        'limit_response_size' => 1024,
                        'reject_unsafe_urls' => true,
                    ]);
                    if (!is_wp_error($response) && in_array((int) wp_remote_retrieve_response_code($response), [405, 501], true)) {
                        $response = wp_safe_remote_get($current_url, [
                            'timeout' => 2,
                            'redirection' => 0,
                            'limit_response_size' => 1024,
                            'headers' => ['Range' => 'bytes=0-0'],
                            'reject_unsafe_urls' => true,
                        ]);
                    }
                    if (is_wp_error($response)) {
                        $status = 'unknown';
                        $message = 'خطای اتصال؛ نتیجه قطعی نیست';
                        $chain[] = 'ERROR ' . $current_url;
                        break;
                    }

                    $response_code = (int) wp_remote_retrieve_response_code($response);
                    if ($code === 0) $code = $response_code;
                    $codes[] = $response_code;
                    $chain[] = $response_code . ' ' . $current_url;
                    if (in_array($response_code, [301, 302, 303, 307, 308], true))
                        $location = (string) wp_remote_retrieve_header($response, 'location');
                        if ($location === '') {
                            $status = 'unknown';
                            $message = 'پاسخ ریدایرکت بدون Location';
                            break;
                        }
                        if ($hop >= self::REDIRECT_HOP_LIMIT) {
                            $status = 'redirect_loop';
                            $message = 'بیش از سقف مجاز ریدایرکت؛ بررسی متوقف شد';
                            break;
                        }
                        if (class_exists('WP_Http')) {
                            $next_url = WP_Http::make_absolute_url($location, $current_url);
                        } else {
                            $next_url = $location;
                        }
                        $next_url = esc_url_raw($next_url, ['http', 'https']);
                        if ($next_url === '' || !wp_http_validate_url($next_url)) {
                            $status = 'unknown';
                            $message = 'مقصد ریدایرکت نامعتبر یا ناامن';
                            break;
                        }
                        $current_url = $next_url;
                        continue;
                    }

                    if ($response_code >= 400) {
                        $status = 'broken';
                        $message = 'خطای HTTP ' . $response_code;
                    } elseif ($response_code >= 200 && $response_code < 300) {
                        $status = count($codes) > 1 ? (count($codes) === 2 ? 'redirect' : 'redirect_chain') : 'ok';
                        $message = count($codes) > 1 ? 'ریدایرکت به پاسخ نهایی HTTP ' . $response_code : 'پاسخ معتبر';
                    } else {
                        $status = 'unknown';
                        $message = 'پاسخ HTTP نامعمول ' . $response_code;
                    }
                    break;
                }
                if (count($codes) > 1 && $status === 'redirect') $message = 'ریدایرکت (' . implode(' → ', $codes) . ')';
                if (count($codes) > 2 && $status === 'redirect_chain') $message = 'زنجیرهٔ ریدایرکت (' . implode(' → ', $codes) . ')';
            }
            $wpdb->update(
                $this->links_table,
                [
                    'check_status' => $status,
                    'check_code' => $code,
                    'check_message' => substr($message, 0, 250),
                    'redirect_chain' => implode("\n", $chain),
                    'checked_at' => current_time('mysql'),
                ],
                ['id' => (int) $row->id],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
        }
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->links_table} WHERE scan_id = %d AND check_status = %s AND url LIKE %s",
            (int) $scan->id, 'unchecked', 'http%'
        ));
        wp_send_json_success(['done' => $remaining === 0, 'checked' => count($rows), 'remaining' => $remaining]);
    }

    private function get_scan_history(int $current_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.started_at, s.completed_at, s.processed_posts,
                    (SELECT COUNT(*) FROM {$this->links_table} l WHERE l.scan_id = s.id) AS link_count
             FROM {$this->scans_table} s WHERE s.status = %s ORDER BY s.completed_at DESC, s.id DESC LIMIT %d",
            'complete', self::HISTORY_LIMIT
        )) ?: [];
    }

    private function get_scan_diff(int $current_id): array {
        global $wpdb;
        $previous = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->scans_table} WHERE status = %s AND id <> %d ORDER BY completed_at DESC, id DESC LIMIT 1",
            'complete', $current_id
        ));
        if (!$previous) return ['previous' => 0, 'added' => 0, 'removed' => 0, 'new_problems' => 0, 'resolved_problems' => 0, 'http_comparable' => false, 'samples' => []];
        $previous = (int) $previous;
        $added = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->links_table} n WHERE n.scan_id = %d AND NOT EXISTS (
                SELECT 1 FROM {$this->links_table} o WHERE o.scan_id = %d AND o.source_post_id = n.source_post_id AND o.url = n.url AND o.anchor_text = n.anchor_text AND o.link_type = n.link_type AND o.occurrence_no = n.occurrence_no
            )", $current_id, $previous
        ));
        $removed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->links_table} o WHERE o.scan_id = %d AND NOT EXISTS (
                SELECT 1 FROM {$this->links_table} n WHERE n.scan_id = %d AND n.source_post_id = o.source_post_id AND n.url = o.url AND n.anchor_text = o.anchor_text AND n.link_type = o.link_type AND n.occurrence_no = o.occurrence_no
            )", $previous, $current_id
        ));
        $comparable_current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->links_table} WHERE scan_id = %d AND check_status <> %s", $current_id, 'unchecked'
        ));
        $comparable_previous = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->links_table} WHERE scan_id = %d AND check_status <> %s", $previous, 'unchecked'
        ));
        $new_problems = 0;
        $resolved_problems = 0;
        if ($comparable_current > 0 && $comparable_previous > 0) {
            $new_problems = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->links_table} n
                 INNER JOIN {$this->links_table} o ON o.scan_id = %d AND o.source_post_id = n.source_post_id
                    AND o.url = n.url AND o.anchor_text = n.anchor_text AND o.link_type = n.link_type AND o.occurrence_no = n.occurrence_no
                 WHERE n.scan_id = %d AND n.check_status IN ('broken','redirect_chain','redirect_loop')
                   AND o.check_status NOT IN ('broken','redirect_chain','redirect_loop','unchecked')",
                $previous, $current_id
            ));
            $resolved_problems = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->links_table} o
                 INNER JOIN {$this->links_table} n ON n.scan_id = %d AND n.source_post_id = o.source_post_id
                    AND n.url = o.url AND n.anchor_text = o.anchor_text AND n.link_type = o.link_type AND n.occurrence_no = o.occurrence_no
                 WHERE o.scan_id = %d AND o.check_status IN ('broken','redirect_chain','redirect_loop')
                   AND n.check_status = %s",
                $current_id, $previous, 'ok'
            ));
        }
        $new_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.source_title, n.url, n.anchor_text FROM {$this->links_table} n WHERE n.scan_id = %d AND NOT EXISTS (
                SELECT 1 FROM {$this->links_table} o WHERE o.scan_id = %d AND o.source_post_id = n.source_post_id AND o.url = n.url AND o.anchor_text = n.anchor_text AND o.link_type = n.link_type AND o.occurrence_no = n.occurrence_no
            ) ORDER BY n.source_post_id, n.occurrence_no LIMIT 25", $current_id, $previous
        )) ?: [];
        $old_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.source_title, o.url, o.anchor_text FROM {$this->links_table} o WHERE o.scan_id = %d AND NOT EXISTS (
                SELECT 1 FROM {$this->links_table} n WHERE n.scan_id = %d AND n.source_post_id = o.source_post_id AND n.url = o.url AND n.anchor_text = o.anchor_text AND n.link_type = o.link_type AND n.occurrence_no = o.occurrence_no
            ) ORDER BY o.source_post_id, o.occurrence_no LIMIT %d", $previous, $current_id, max(0, 25 - count($new_rows))
        )) ?: [];
        $samples = [];
        foreach ($new_rows as $row) $samples[] = ['change' => 'جدید در اسکن فعلی', 'source_title' => (string) $row->source_title, 'url' => (string) $row->url, 'anchor' => (string) $row->anchor_text];
        foreach ($old_rows as $row) $samples[] = ['change' => 'حذف‌شده/رفع‌شده نسبت به اسکن قبلی', 'source_title' => (string) $row->source_title, 'url' => (string) $row->url, 'anchor' => (string) $row->anchor_text];
        return ['previous' => $previous, 'added' => $added, 'removed' => $removed, 'new_problems' => $new_problems, 'resolved_problems' => $resolved_problems, 'http_comparable' => ($comparable_current > 0 && $comparable_previous > 0), 'samples' => $samples];
    }

    private function get_sources_without_internal(int $scan_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT source_post_id, source_title, source_type, link_count FROM {$this->sources_table}
             WHERE scan_id = %d AND internal_count = 0 ORDER BY source_post_id ASC LIMIT 100",
            $scan_id
        )) ?: [];
    }

    private function count_links(int $scan_id, string $type, string $source_type, string $search, string $check_status = 'all'): int {
        global $wpdb;
        [$where, $params] = $this->filter_sql($scan_id, $type, $source_type, $search, $check_status);
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->links_table} WHERE {$where}", ...$params));
    }

    private function get_links(int $scan_id, int $page, string $type, string $source_type, string $search, int $limit = self::PAGE_SIZE, string $check_status = 'all'): array {
        global $wpdb;
        [$where, $params] = $this->filter_sql($scan_id, $type, $source_type, $search, $check_status);
        $limit = max(1, min(100000, $limit));
        $offset = $limit === 100000 ? 0 : (max(1, $page) - 1) * $limit;
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_post_id, source_title, source_type, url, anchor_text, link_type, check_status, check_code, check_message
             FROM {$this->links_table} WHERE {$where}
             ORDER BY source_post_id ASC, occurrence_no ASC LIMIT %d OFFSET %d",
            ...$params
        )) ?: [];
    }

    private function filter_sql(int $scan_id, string $type, string $source_type, string $search, string $check_status = 'all'): array {
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
        if (in_array($check_status, ['unchecked', 'ok', 'redirect', 'redirect_chain', 'redirect_loop', 'broken', 'unknown'], true)) {
            $where .= ' AND check_status = %s';
            $params[] = $check_status;
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
