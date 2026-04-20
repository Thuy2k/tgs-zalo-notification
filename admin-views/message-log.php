<?php
/**
 * Message Log Page - Unified view
 * Network admin: sees all sites. Regular admin: sees own site only.
 */

if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) {
    echo '<div class="alert alert-danger">Bạn không có quyền truy cập trang này.</div>';
    return;
}

$is_network_admin = current_user_can('manage_network');

global $wpdb;
$log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
$queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;

// Pagination
$per_page = 50;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// Filters
$filter_status = sanitize_text_field($_GET['status'] ?? '');
$filter_blog = intval($_GET['blog_id'] ?? 0);
$filter_phone = sanitize_text_field($_GET['phone'] ?? '');

// Build query
$where = [];
$params = [];

// Non-network admins can only see their own site
if (!$is_network_admin) {
    $where[] = 'blog_id = %d';
    $params[] = get_current_blog_id();
}

if (!empty($filter_status)) {
    $where[] = 'status = %s';
    $params[] = $filter_status;
}
if ($filter_blog > 0 && $is_network_admin) {
    $where[] = 'blog_id = %d';
    $params[] = $filter_blog;
}
if (!empty($filter_phone)) {
    $where[] = 'phone LIKE %s';
    $params[] = '%' . $wpdb->esc_like($filter_phone) . '%';
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) FROM {$log_table} {$where_sql}";
$total = !empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql);
$total_pages = ceil($total / $per_page);

// Get rows
$query = "SELECT * FROM {$log_table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_params = array_merge($params, [$per_page, $offset]);
$logs = $wpdb->get_results($wpdb->prepare($query, ...$query_params));

// Failed queue items for retry (network admin only)
$failed_queue = [];
if ($is_network_admin) {
    $failed_queue = $wpdb->get_results(
        "SELECT * FROM {$queue_table} WHERE status = 'failed' AND retry_count >= max_retries ORDER BY updated_at DESC LIMIT 20"
    );
}
?>

<div class="tgs-zalo-wrap">

    <div id="tgsZaloNotice" class="notice" style="display:none;"><p></p></div>

    <!-- Filters -->
    <form method="get" class="tgs-zalo-filters">
        <input type="hidden" name="page" value="tgs-shop-management">
        <input type="hidden" name="view" value="zalo-log">

        <select name="status">
            <option value="">-- Tất cả trạng thái --</option>
            <option value="sent" <?php selected($filter_status, 'sent'); ?>>Đã gửi</option>
            <option value="failed" <?php selected($filter_status, 'failed'); ?>>Thất bại</option>
            <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>>Đã hủy</option>
        </select>

        <?php if ($is_network_admin): ?>
        <input type="number" name="blog_id" value="<?php echo $filter_blog ?: ''; ?>" placeholder="Site ID" style="width:100px;">
        <?php endif; ?>
        <input type="text" name="phone" value="<?php echo esc_attr($filter_phone); ?>" placeholder="Số điện thoại" style="width:150px;">

        <button type="submit" class="button">Lọc</button>
        <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=zalo-log'); ?>" class="button">Reset</a>
    </form>

    <p>Tổng: <strong><?php echo intval($total); ?></strong> tin nhắn</p>

    <!-- Log Table -->
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <?php if ($is_network_admin): ?><th>Site</th><?php endif; ?>
                <th>SĐT</th>
                <th>Template</th>
                <th>Tracking ID</th>
                <th>Trạng thái</th>
                <th>Zalo Msg ID</th>
                <th>Retry</th>
                <th>Tạo lúc</th>
                <th>Gửi lúc</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="<?php echo $is_network_admin ? 10 : 9; ?>" style="text-align:center;">Không có dữ liệu.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo intval($log->id); ?></td>
                        <?php if ($is_network_admin): ?><td><?php echo intval($log->blog_id); ?></td><?php endif; ?>
                        <td><code><?php echo esc_html($log->phone); ?></code></td>
                        <td><code><?php echo esc_html($log->zalo_template_id); ?></code></td>
                        <td><small><?php echo esc_html($log->tracking_id); ?></small></td>
                        <td>
                            <?php if ($log->status === 'sent'): ?>
                                <span class="tgs-badge tgs-badge-success">Đã gửi</span>
                            <?php elseif ($log->status === 'failed'): ?>
                                <span class="tgs-badge tgs-badge-danger" title="<?php echo esc_attr($log->error_message); ?>">Thất bại</span>
                            <?php else: ?>
                                <span class="tgs-badge tgs-badge-secondary"><?php echo esc_html($log->status); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html($log->zalo_msg_id ?: '-'); ?></small></td>
                        <td><?php echo intval($log->retry_count); ?></td>
                        <td><small><?php echo esc_html($log->created_at); ?></small></td>
                        <td><small><?php echo esc_html($log->sent_at ?: '-'); ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $page_links = paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $current_page,
                    'total'   => $total_pages,
                    'type'    => 'array',
                ]);
                if ($page_links) {
                    echo '<span class="pagination-links">' . implode(' ', $page_links) . '</span>';
                }
                ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Failed Messages (Retry) -->
    <?php if (!empty($failed_queue)): ?>
        <div class="tgs-zalo-section" style="margin-top: 30px;">
            <h2>Tin nhắn thất bại (có thể gửi lại)</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Queue ID</th>
                        <th>Site</th>
                        <th>SĐT</th>
                        <th>Template</th>
                        <th>Lỗi</th>
                        <th>Retry</th>
                        <th>Tạo lúc</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failed_queue as $fq): ?>
                        <tr id="failed-row-<?php echo intval($fq->id); ?>">
                            <td><?php echo intval($fq->id); ?></td>
                            <td><?php echo intval($fq->blog_id); ?></td>
                            <td><code><?php echo esc_html($fq->phone); ?></code></td>
                            <td><code><?php echo esc_html($fq->zalo_template_id); ?></code></td>
                            <td><small class="text-danger"><?php echo esc_html(mb_substr($fq->last_error ?? '', 0, 100)); ?></small></td>
                            <td><?php echo intval($fq->retry_count); ?>/<?php echo intval($fq->max_retries); ?></td>
                            <td><small><?php echo esc_html($fq->created_at); ?></small></td>
                            <td>
                                <button type="button" class="button button-small btn-retry-message" data-id="<?php echo intval($fq->id); ?>">
                                    Gửi lại
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
