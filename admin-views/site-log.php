<?php
/**
 * Site-level Log Page - Read-only view for individual sites
 */

if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die('Unauthorized');

global $wpdb;
$log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
$blog_id = get_current_blog_id();

// Pagination
$per_page = 50;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// Filter
$filter_status = sanitize_text_field($_GET['status'] ?? '');

$where = ['blog_id = %d'];
$params = [$blog_id];

if (!empty($filter_status)) {
    $where[] = 'status = %s';
    $params[] = $filter_status;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$log_table} {$where_sql}", ...$params));
$total_pages = ceil($total / $per_page);

$query_params = array_merge($params, [$per_page, $offset]);
$logs = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$log_table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
    ...$query_params
));
?>

<div class="wrap tgs-zalo-wrap">
    <h1><span class="dashicons dashicons-megaphone"></span> Zalo ZNS - Lịch sử gửi tin</h1>

    <!-- Filters -->
    <form method="get" class="tgs-zalo-filters">
        <input type="hidden" name="page" value="tgs-zalo-site-log">
        <select name="status">
            <option value="">-- Tất cả --</option>
            <option value="sent" <?php selected($filter_status, 'sent'); ?>>Đã gửi</option>
            <option value="failed" <?php selected($filter_status, 'failed'); ?>>Thất bại</option>
        </select>
        <button type="submit" class="button">Lọc</button>
    </form>

    <p>Tổng: <strong><?php echo intval($total); ?></strong> tin nhắn từ site này</p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>SĐT</th>
                <th>Template</th>
                <th>Tracking</th>
                <th>Trạng thái</th>
                <th>Retry</th>
                <th>Tạo lúc</th>
                <th>Gửi lúc</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;">Không có dữ liệu.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><code><?php echo esc_html($log->phone); ?></code></td>
                        <td><code><?php echo esc_html($log->zalo_template_id); ?></code></td>
                        <td><small><?php echo esc_html($log->tracking_id); ?></small></td>
                        <td>
                            <?php if ($log->status === 'sent'): ?>
                                <span class="tgs-badge tgs-badge-success">Đã gửi</span>
                            <?php else: ?>
                                <span class="tgs-badge tgs-badge-danger"><?php echo esc_html($log->status); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($log->retry_count); ?></td>
                        <td><small><?php echo esc_html($log->created_at); ?></small></td>
                        <td><small><?php echo esc_html($log->sent_at ?: '-'); ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

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
</div>
