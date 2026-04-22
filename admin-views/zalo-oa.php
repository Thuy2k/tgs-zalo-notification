<?php
/**
 * Zalo OA — Unified admin page with tabs
 * Integrates into TGS Shop Management main layout (Sneat Bootstrap 5)
 */
if (!defined('ABSPATH')) exit;

$is_network_admin = current_user_can('manage_network');
$active_tab = sanitize_text_field($_GET['tab'] ?? 'overview');

// Only network admin can access settings/templates tabs
if (in_array($active_tab, ['settings', 'templates']) && !$is_network_admin) {
    $active_tab = 'overview';
}

global $wpdb;

// === Data for Overview ===
$token_status = TGS_Zalo_Token_Manager::get_status();
$queue_stats = TGS_Zalo_Queue::get_stats();
$enabled = get_site_option('tgs_zalo_enabled', 0);
$dev_mode = get_site_option('tgs_zalo_dev_mode', 0);
$enabled_blog_ids = get_site_option('tgs_zalo_enabled_blog_ids', []);
$enabled_blog_ids = is_array($enabled_blog_ids) ? array_values(array_unique(array_map('intval', $enabled_blog_ids))) : [];

// === Data for Settings tab ===
$app_id = get_site_option('tgs_zalo_app_id', '');
$secret_key = get_site_option('tgs_zalo_secret_key', '');
$batch_size = get_site_option('tgs_zalo_batch_size', 50);
$retry_max = get_site_option('tgs_zalo_retry_max', 3);
$oauth_redirect_uri = admin_url('?tgs_zalo_oauth_callback=1');
$deploy_sites = [];
if ($is_network_admin && function_exists('get_sites')) {
    $site_objects = get_sites([
        'number'   => 0,
        'archived' => 0,
        'deleted'  => 0,
        'spam'     => 0,
    ]);

    foreach ($site_objects as $site_object) {
        $blog = get_blog_details($site_object->blog_id);
        $deploy_sites[] = [
            'blog_id'  => intval($site_object->blog_id),
            'name'     => $blog && !empty($blog->blogname) ? $blog->blogname : ('Shop #' . intval($site_object->blog_id)),
            'siteurl'  => $blog && !empty($blog->siteurl) ? $blog->siteurl : '',
            'selected' => in_array(intval($site_object->blog_id), $enabled_blog_ids, true),
        ];
    }

    usort($deploy_sites, function ($left, $right) {
        return strcasecmp($left['name'], $right['name']);
    });
}

// Flash messages
$oauth_result = sanitize_text_field($_GET['zalo_oauth'] ?? '');
$oauth_msg = sanitize_text_field($_GET['msg'] ?? '');

// === Data for Templates tab ===
$templates_table = TGS_TABLE_ZALO_TEMPLATES;
$templates = $wpdb->get_results("SELECT * FROM {$templates_table} ORDER BY created_at DESC");

$event_types = [
    'sale_completed' => 'Bán hàng thành công (POS / Web)',
];

$available_fields = [
    'customer_name'  => 'Tên khách hàng',
    'customer_phone' => 'Số điện thoại',
    'customer_email' => 'Email',
    'customer_id'    => 'Mã khách hàng',
    'customer_code'  => 'Mã khách hàng dùng cho template tích điểm',
    'sale_code'      => 'Mã đơn hàng',
    'order_code'     => 'Mã đơn hàng cho template Zalo',
    'order_date'     => 'Ngày bán cho template Zalo (dd/mm/yyyy HH:mm)',
    'export_code'    => 'Mã phiếu xuất',
    'price'          => 'Giá trị đơn hàng cuối cùng (số thuần, dùng cho number)',
    'point'          => 'Điểm thưởng gia tăng (số thuần)',
    'total_point'    => 'Tổng điểm hiện tại (đang để 0 cho demo)',
    'note'           => 'Ghi chú thân thiện cho khách',
    'total_amount'   => 'Tổng tiền (có định dạng)',
    'total_amount_raw' => 'Tổng tiền (số thuần, dùng cho number)',
    'total_items'    => 'Số lượng sản phẩm',
    'discount'       => 'Giảm giá (có định dạng)',
    'discount_raw'   => 'Giảm giá (số thuần, dùng cho number)',
    'sale_date'      => 'Ngày bán (dd/mm/yyyy HH:mm)',
    'shop_name'      => 'Tên cửa hàng',
    'shop_address'   => 'Địa chỉ cửa hàng',
];

// === Data for Log tab ===
$log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
$queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;

$per_page = 50;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

$filter_status = sanitize_text_field($_GET['log_status'] ?? '');
$filter_blog = intval($_GET['blog_id'] ?? 0);
$filter_phone = sanitize_text_field($_GET['phone'] ?? '');

$log_where = [];
$log_params = [];

if (!$is_network_admin) {
    $log_where[] = 'blog_id = %d';
    $log_params[] = get_current_blog_id();
}
if (!empty($filter_status)) {
    $log_where[] = 'status = %s';
    $log_params[] = $filter_status;
}
if ($filter_blog > 0 && $is_network_admin) {
    $log_where[] = 'blog_id = %d';
    $log_params[] = $filter_blog;
}
if (!empty($filter_phone)) {
    $log_where[] = 'phone LIKE %s';
    $log_params[] = '%' . $wpdb->esc_like($filter_phone) . '%';
}

$log_where_sql = !empty($log_where) ? 'WHERE ' . implode(' AND ', $log_where) : '';
$log_count_sql = "SELECT COUNT(*) FROM {$log_table} {$log_where_sql}";
$log_total = !empty($log_params) ? $wpdb->get_var($wpdb->prepare($log_count_sql, ...$log_params)) : $wpdb->get_var($log_count_sql);
$log_total_pages = ceil($log_total / $per_page);

$log_query = "SELECT * FROM {$log_table} {$log_where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$log_query_params = array_merge($log_params, [$per_page, $offset]);
$logs = $wpdb->get_results($wpdb->prepare($log_query, ...$log_query_params));

$failed_queue = [];
if ($is_network_admin) {
    $failed_queue = $wpdb->get_results(
        "SELECT * FROM {$queue_table} WHERE status = 'failed' AND retry_count >= max_retries ORDER BY updated_at DESC LIMIT 20"
    );
}

// Base URL for tabs
$base_url = admin_url('admin.php?page=tgs-shop-management&view=zalo-oa');
?>

<div class="app-zalo-oa">

    <!-- ═══ Page Header ═══ -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1 fw-bold">
                <i class="bx bx-message-dots text-info me-2"></i>Zalo OA
            </h4>
            <p class="text-muted mb-0">Cấu hình gửi tin nhắn Zalo ZNS tự động khi bán hàng</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($token_status['has_refresh_token'] && !$token_status['is_expired']): ?>
                <span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Đã kết nối OA</span>
            <?php elseif ($token_status['has_refresh_token']): ?>
                <span class="badge bg-label-warning"><i class="bx bx-time me-1"></i>Token cần refresh</span>
            <?php else: ?>
                <span class="badge bg-label-danger"><i class="bx bx-x-circle me-1"></i>Chưa kết nối</span>
            <?php endif; ?>
            <?php if ($enabled): ?>
                <span class="badge bg-label-primary"><i class="bx bx-power-off me-1"></i>Đang bật</span>
            <?php endif; ?>
            <?php if ($dev_mode): ?>
                <span class="badge bg-label-warning"><i class="bx bx-test-tube me-1"></i>Dev Mode</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ OAuth Flash Messages ═══ -->
    <?php if ($oauth_result === 'success'): ?>
        <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
            <i class="bx bx-check-circle bx-md me-3"></i>
            <div><strong>Kết nối Zalo OA thành công!</strong> Token đã được lưu và sẵn sàng gửi tin.</div>
        </div>
    <?php elseif ($oauth_result === 'error'): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
            <i class="bx bx-error-circle bx-md me-3"></i>
            <div><strong>Kết nối thất bại:</strong> <?php echo esc_html($oauth_msg); ?></div>
        </div>
    <?php endif; ?>

    <!-- ═══ Notice container for AJAX ═══ -->
    <div id="tgsZaloNotice" class="alert d-none mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="bx bx-info-circle me-2 notice-icon"></i>
            <span class="notice-text"></span>
        </div>
    </div>

    <!-- ═══ Tab Navigation ═══ -->
    <div class="nav-align-top">
        <ul class="nav nav-pills flex-column flex-md-row mb-4 gap-2 gap-md-0">
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'overview' ? 'active' : ''; ?>"
                   href="<?php echo esc_url($base_url . '&tab=overview'); ?>">
                    <i class="bx bx-home-alt me-1"></i> Tổng quan
                </a>
            </li>
            <?php if ($is_network_admin): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>"
                   href="<?php echo esc_url($base_url . '&tab=settings'); ?>">
                    <i class="bx bx-cog me-1"></i> Cài đặt
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'templates' ? 'active' : ''; ?>"
                   href="<?php echo esc_url($base_url . '&tab=templates'); ?>">
                    <i class="bx bx-layout me-1"></i> Template ZNS
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'log' ? 'active' : ''; ?>"
                   href="<?php echo esc_url($base_url . '&tab=log'); ?>">
                    <i class="bx bx-list-ul me-1"></i> Lịch sử gửi tin
                    <?php if ($queue_stats['pending'] > 0): ?>
                        <span class="badge bg-warning ms-1"><?php echo intval($queue_stats['pending']); ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'guide' ? 'active' : ''; ?>"
                   href="<?php echo esc_url($base_url . '&tab=guide'); ?>">
                    <i class="bx bx-book-open me-1"></i> Hướng dẫn
                </a>
            </li>
        </ul>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  TAB: TỔNG QUAN                                              -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <?php if ($active_tab === 'overview'): ?>
        <div class="row g-4 mb-4">
            <!-- Card: Kết nối -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <div class="avatar bg-label-<?php echo $token_status['has_refresh_token'] ? ($token_status['is_expired'] ? 'warning' : 'success') : 'danger'; ?> rounded">
                                <span class="avatar-initial"><i class="bx bx-link bx-sm"></i></span>
                            </div>
                        </div>
                        <h6 class="mb-1 text-muted">Trạng thái kết nối</h6>
                        <h5 class="mb-0 <?php echo $token_status['has_refresh_token'] ? ($token_status['is_expired'] ? 'text-warning' : 'text-success') : 'text-danger'; ?>">
                            <?php if ($token_status['has_refresh_token']): ?>
                                <?php echo $token_status['is_expired'] ? 'Cần refresh' : 'Đã kết nối'; ?>
                            <?php else: ?>
                                Chưa kết nối
                            <?php endif; ?>
                        </h5>
                        <small class="text-muted">Còn: <?php echo esc_html($token_status['time_remaining']); ?></small>
                    </div>
                </div>
            </div>

            <!-- Card: Chờ gửi -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <div class="avatar bg-label-warning rounded">
                                <span class="avatar-initial"><i class="bx bx-time-five bx-sm"></i></span>
                            </div>
                        </div>
                        <h6 class="mb-1 text-muted">Đang chờ gửi</h6>
                        <h5 class="mb-0"><?php echo intval($queue_stats['pending']); ?></h5>
                        <small class="text-muted">Đang xử lý: <?php echo intval($queue_stats['processing']); ?></small>
                    </div>
                </div>
            </div>

            <!-- Card: Đã gửi -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <div class="avatar bg-label-success rounded">
                                <span class="avatar-initial"><i class="bx bx-check-double bx-sm"></i></span>
                            </div>
                        </div>
                        <h6 class="mb-1 text-muted">Đã gửi thành công</h6>
                        <h5 class="mb-0 text-success"><?php echo intval($queue_stats['sent']); ?></h5>
                        <small class="text-muted">Tổng tin nhắn gửi đi</small>
                    </div>
                </div>
            </div>

            <!-- Card: Thất bại -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <div class="avatar bg-label-danger rounded">
                                <span class="avatar-initial"><i class="bx bx-error bx-sm"></i></span>
                            </div>
                        </div>
                        <h6 class="mb-1 text-muted">Gửi thất bại</h6>
                        <h5 class="mb-0 text-danger"><?php echo intval($queue_stats['failed']); ?></h5>
                        <small class="text-muted">Kiểm tra tab Lịch sử</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick info -->
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-info-circle me-2 text-primary"></i>Thông tin token</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr><td class="text-muted" style="width:160px;">Refresh Token</td><td><?php echo $token_status['has_refresh_token'] ? '<span class="text-success fw-semibold">Có</span>' : '<span class="text-danger fw-semibold">Chưa có</span>'; ?></td></tr>
                            <tr><td class="text-muted">Hết hạn lúc</td><td><?php echo esc_html($token_status['expires_at']); ?></td></tr>
                            <tr><td class="text-muted">Thời gian còn</td><td><?php echo esc_html($token_status['time_remaining']); ?></td></tr>
                            <tr><td class="text-muted">Lần refresh cuối</td><td><?php echo esc_html($token_status['last_refresh']); ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-bolt me-2 text-warning"></i>Hành động nhanh</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary" id="btnTestConnection">
                                <i class="bx bx-transfer me-1"></i>Kiểm tra kết nối & Quota
                            </button>
                            <?php if ($is_network_admin): ?>
                            <a href="<?php echo esc_url($base_url . '&tab=settings'); ?>" class="btn btn-outline-info">
                                <i class="bx bx-cog me-1"></i>Cài đặt Zalo OA
                            </a>
                            <a href="<?php echo esc_url($base_url . '&tab=templates'); ?>" class="btn btn-outline-success">
                                <i class="bx bx-layout me-1"></i>Quản lý Template
                            </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($base_url . '&tab=guide'); ?>" class="btn btn-outline-secondary">
                                <i class="bx bx-book-open me-1"></i>Xem hướng dẫn thiết lập
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  TAB: CÀI ĐẶT (network admin only)                          -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <?php elseif ($active_tab === 'settings' && $is_network_admin): ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <form id="tgsZaloSettingsForm">
                    <!-- API Credentials -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0 fw-semibold"><i class="bx bx-key me-2 text-primary"></i>Thông tin ứng dụng Zalo</h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info border-0 py-2 mb-4" style="font-size: 13px;">
                                <i class="bx bx-info-circle me-1"></i>
                                Lấy App ID và Secret Key tại <a href="https://developers.zalo.me" target="_blank" class="fw-semibold">developers.zalo.me</a>
                                → Chọn ứng dụng → Cài đặt.
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Zalo App ID <span class="text-danger">*</span></label>
                                    <input type="text" name="app_id" class="form-control" value="<?php echo esc_attr($app_id); ?>"
                                           placeholder="VD: 4215637890123456">
                                    <div class="form-text">ID ứng dụng từ trang Zalo Developer</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Secret Key <span class="text-danger">*</span></label>
                                    <input type="password" name="secret_key" class="form-control"
                                           value="<?php echo $secret_key ? '********' : ''; ?>"
                                           placeholder="Nhập Secret Key">
                                    <div class="form-text">Để trống nếu không muốn thay đổi</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Switches -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0 fw-semibold"><i class="bx bx-toggle-left me-2 text-success"></i>Bật/Tắt tính năng</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                                <div>
                                    <strong>Kích hoạt gửi tin Zalo ZNS</strong><br>
                                    <small class="text-muted">Khi tắt, hệ thống sẽ không enqueue/gửi tin mới</small>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                           style="width: 3rem; height: 1.5rem;" <?php checked($enabled, 1); ?>>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Chế độ Development</strong><br>
                                    <small class="text-muted">Gửi miễn phí, chỉ tới số test đã đăng ký trên Zalo Business Portal</small>
                                    <?php if (!$dev_mode): ?>
                                        <br><small class="text-success fw-semibold"><i class="bx bx-check-circle me-1"></i>PRODUCTION — Gửi tin thật, tính phí</small>
                                    <?php else: ?>
                                        <br><small class="text-warning fw-semibold"><i class="bx bx-test-tube me-1"></i>DEV MODE — Chỉ gửi tới số test, miễn phí</small>
                                    <?php endif; ?>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="dev_mode" value="1"
                                           style="width: 3rem; height: 1.5rem;" <?php checked($dev_mode, 1); ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0 fw-semibold"><i class="bx bx-store me-2 text-info"></i>Phạm vi triển khai theo shop</h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning border-0 py-2 mb-3" style="font-size: 13px;">
                                <i class="bx bx-shield-quarter me-1"></i>
                                Chỉ các shop được chọn mới gửi Zalo OA tự động. Nếu không chọn shop nào, hệ thống sẽ <strong>không gửi</strong> để tránh bung toàn mạng.
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="text-muted small">Đã chọn: <strong id="selectedDeploySiteCount"><?php echo count($enabled_blog_ids); ?></strong> / <?php echo count($deploy_sites); ?> shop</div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnSelectAllDeploySites">Chọn tất cả</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearDeploySites">Bỏ chọn</button>
                                </div>
                            </div>

                            <div class="border rounded p-3" style="max-height: 320px; overflow:auto;">
                                <div class="row g-2">
                                    <?php foreach ($deploy_sites as $deploy_site): ?>
                                    <div class="col-md-6">
                                        <label class="border rounded p-2 d-block h-100" style="cursor:pointer;">
                                            <div class="form-check mb-1">
                                                <input class="form-check-input deploy-site-checkbox" type="checkbox"
                                                       name="deploy_blog_ids[]"
                                                       value="<?php echo intval($deploy_site['blog_id']); ?>"
                                                       <?php checked($deploy_site['selected']); ?>>
                                                <span class="form-check-label fw-semibold">
                                                    #<?php echo intval($deploy_site['blog_id']); ?> - <?php echo esc_html($deploy_site['name']); ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted ps-4" style="word-break: break-all;">
                                                <?php echo esc_html($deploy_site['siteurl']); ?>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Performance -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0 fw-semibold"><i class="bx bx-tachometer me-2 text-warning"></i>Hiệu suất</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Batch Size</label>
                                    <input type="number" name="batch_size" class="form-control"
                                           value="<?php echo intval($batch_size); ?>" min="1" max="100">
                                    <div class="form-text">Số tin xử lý mỗi phút. 650 store khuyến nghị: <strong>50</strong></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Số lần retry tối đa</label>
                                    <input type="number" name="retry_max" class="form-control"
                                           value="<?php echo intval($retry_max); ?>" min="0" max="10">
                                    <div class="form-text">Backoff: 5 phút → 15 phút → 30 phút</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mb-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bx bx-check me-1"></i>Lưu cài đặt
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sidebar: OAuth -->
            <div class="col-lg-4">
                <div class="card mb-4 border-<?php echo $token_status['has_refresh_token'] ? 'success' : 'warning'; ?>">
                    <div class="card-header bg-<?php echo $token_status['has_refresh_token'] ? 'label-success' : 'label-warning'; ?>">
                        <h6 class="mb-0 fw-semibold">
                            <i class="bx bx-link me-2"></i>Kết nối Zalo OA
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($token_status['has_refresh_token']): ?>
                            <div class="text-center mb-3">
                                <div class="avatar avatar-lg bg-label-success rounded-circle mb-2">
                                    <span class="avatar-initial"><i class="bx bx-check bx-md"></i></span>
                                </div>
                                <p class="text-success fw-semibold mb-1">Đã kết nối</p>
                                <small class="text-muted">Token hết hạn: <?php echo esc_html($token_status['expires_at']); ?></small>
                            </div>
                        <?php else: ?>
                            <div class="text-center mb-3">
                                <div class="avatar avatar-lg bg-label-warning rounded-circle mb-2">
                                    <span class="avatar-initial"><i class="bx bx-unlink bx-md"></i></span>
                                </div>
                                <p class="text-warning fw-semibold mb-0">Chưa kết nối</p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($app_id)): ?>
                            <?php $oauth_url = TGS_Zalo_Token_Manager::get_oauth_url($oauth_redirect_uri); ?>
                            <a href="<?php echo esc_url($oauth_url); ?>" class="btn btn-primary w-100 mb-3">
                                <i class="bx bx-log-in me-1"></i>
                                <?php echo $token_status['has_refresh_token'] ? 'Kết nối lại OA' : 'Kết nối Zalo OA'; ?>
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning py-2 mb-3" style="font-size: 12px;">
                                <i class="bx bx-info-circle me-1"></i>Nhập App ID & Secret Key rồi lưu trước khi kết nối.
                            </div>
                        <?php endif; ?>

                        <div class="bg-light rounded p-3" style="font-size: 12px;">
                            <strong>Callback URL:</strong><br>
                            <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html($oauth_redirect_uri); ?></code>
                            <br><br>
                            <i class="bx bx-right-arrow-alt me-1"></i>Copy URL này và thêm vào
                            <a href="https://developers.zalo.me" target="_blank">developers.zalo.me</a>
                            → Ứng dụng → Cài đặt → Redirect URI
                        </div>
                    </div>
                </div>

                <!-- Quick Test -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-test-tube me-2 text-info"></i>Kiểm tra nhanh</h6>
                    </div>
                    <div class="card-body">
                        <button type="button" class="btn btn-outline-info w-100" id="btnTestConnection2">
                            <i class="bx bx-transfer me-1"></i>Test kết nối & Quota
                        </button>
                        <div id="testResult" class="mt-3" style="display: none;"></div>
                    </div>
                </div>

                <!-- Send Test Message -->
                <div class="card">
                    <div class="card-header bg-label-<?php echo $dev_mode ? 'warning' : 'primary'; ?>">
                        <h6 class="mb-0 fw-semibold">
                            <i class="bx bx-send me-2"></i>Gửi tin test thật
                            <?php if ($dev_mode): ?>
                                <span class="badge bg-warning ms-1" style="font-size:10px;">Dev Mode - Miễn phí</span>
                            <?php else: ?>
                                <span class="badge bg-primary ms-1" style="font-size:10px;">Production - Tính phí</span>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($dev_mode): ?>
                        <div class="alert alert-warning border-0 py-2 mb-3" style="font-size: 12px;">
                            <i class="bx bx-info-circle me-1"></i>
                            <strong>Dev Mode:</strong> Gửi miễn phí. Số nhận phải <strong>follow OA</strong> và <strong>đăng ký test</strong> trên Zalo Business Portal.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info border-0 py-2 mb-3" style="font-size: 12px;">
                            <i class="bx bx-check-circle me-1"></i>
                            <strong>Production:</strong> Gửi tin thật, tính phí ZNS (~300-400đ/tin).
                        </div>
                        <?php endif; ?>
                        <form id="tgsZaloDirectTestForm">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Số điện thoại <span class="text-danger">*</span></label>
                                <input type="text" name="test_phone" id="directTestPhone" class="form-control form-control-sm"
                                       placeholder="VD: 0912345678" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Zalo Template ID <span class="text-danger">*</span></label>
                                <input type="text" name="zalo_template_id" id="directTestTemplateId" class="form-control form-control-sm"
                                       placeholder="VD: 276530">
                                <div class="form-text" style="font-size: 11px;">
                                    Lấy từ <a href="https://account.zalo.cloud/QBSPortal/zns/template/manage" target="_blank">Zalo Business Portal</a> → Template đã duyệt
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Template Data (JSON)</label>
                                <textarea name="template_data" id="directTestData" rows="5" class="form-control form-control-sm font-monospace"
                                          placeholder='{"customer_name": "Nguyen Van A", "order_code": "DH001", "amount": "500000"}'></textarea>
                                <div class="form-text" style="font-size: 11px;">
                                    Nhập đúng tên param trong template Zalo của bạn. 
                                    <a href="#" id="btnFillSampleData" class="text-primary">Điền mẫu</a>
                                </div>
                            </div>

                            <?php if (!empty($templates)): ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted">Hoặc chọn template đã cấu hình:</label>
                                <select name="test_template_id" id="testTemplateId" class="form-select form-select-sm">
                                    <option value="0">-- Nhập thủ công ở trên --</option>
                                    <?php foreach ($templates as $tpl):
                                        $active_label = $tpl->is_active ? '' : ' (tắt)';
                                    ?>
                                        <option value="<?php echo intval($tpl->id); ?>"
                                                data-zalo-id="<?php echo esc_attr($tpl->zalo_template_id); ?>"
                                                data-mapping="<?php echo esc_attr($tpl->field_mapping); ?>">
                                            <?php echo esc_html($tpl->label); ?> [<?php echo esc_html($tpl->zalo_template_id); ?>]<?php echo $active_label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-<?php echo $dev_mode ? 'warning' : 'primary'; ?> w-100" id="btnDirectTest">
                                <i class="bx bx-send me-1"></i>Gửi tin ngay
                            </button>
                        </form>
                        <div id="directTestResult" class="mt-3" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  TAB: TEMPLATE ZNS (network admin only)                       -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <?php elseif ($active_tab === 'templates' && $is_network_admin): ?>

        <div class="alert alert-info border-0 py-2 mb-4" style="font-size: 13px;">
            <i class="bx bx-info-circle me-1"></i>
            Template ZNS phải được tạo và <strong>duyệt trước</strong> trên
            <a href="https://account.zalo.cloud/QBSPortal/zns/template/manage" target="_blank" class="fw-semibold">Zalo Business Portal</a>.
            Sau khi duyệt xong, lấy <strong>Template ID</strong> rồi mapping ở đây.
        </div>

        <div class="row g-4">
            <!-- Form thêm/sửa -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0 fw-semibold" id="formTitle"><i class="bx bx-plus-circle me-2 text-success"></i>Thêm Template mới</h6>
                    </div>
                    <div class="card-body">
                        <form id="tgsZaloTemplateForm">
                            <input type="hidden" name="template_id" id="editTemplateId" value="0">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Tên gợi nhớ <span class="text-danger">*</span></label>
                                <input type="text" name="label" id="templateLabel" class="form-control"
                                       placeholder="VD: Cảm ơn mua hàng" required>
                                <div class="form-text">Đặt tên dễ nhớ, chỉ hiện trong admin</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Sự kiện kích hoạt <span class="text-danger">*</span></label>
                                <select name="event_type" id="templateEventType" class="form-select" required>
                                    <option value="">-- Chọn sự kiện --</option>
                                    <?php foreach ($event_types as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Khi sự kiện này xảy ra, tin nhắn sẽ được gửi tự động</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Zalo Template ID <span class="text-danger">*</span></label>
                                <input type="text" name="zalo_template_id" id="templateZaloId" class="form-control"
                                       placeholder="VD: 276530" required>
                                <div class="form-text">
                                    Lấy từ Zalo Business Portal → ZNS → Template đã duyệt → Copy ID
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Field Mapping (JSON)</label>
                                <textarea name="field_mapping" id="templateFieldMapping" rows="6" class="form-control font-monospace"
                                          placeholder='{"tên_param_zalo": "data_key"}'>{}</textarea>
                                <div class="form-text">
                                    Map tên tham số trên Zalo với dữ liệu hệ thống.<br>
                                    <strong>Mẫu:</strong>
                                    <code class="cursor-pointer text-primary" id="btnSampleMapping"
                                          title="Click để điền mẫu"
                                        style="cursor: pointer;">{"customer_name": "customer_name", "order_code": "sale_code", "amount": "total_amount_raw", "date": "sale_date"}</code>
                                    <br>Giá trị tĩnh: <code>{"status": "static:Đã thanh toán"}</code>
                                </div>
                            </div>

                            <!-- Available keys reference -->
                            <div class="mb-3">
                                <div class="accordion" id="fieldRef">
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-2" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#fieldRefBody"
                                                    style="font-size: 13px;">
                                                <i class="bx bx-list-check me-2"></i>Xem danh sách data keys có sẵn
                                            </button>
                                        </h2>
                                        <div id="fieldRefBody" class="accordion-collapse collapse" data-bs-parent="#fieldRef">
                                            <div class="accordion-body p-0">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 12px;">
                                                    <thead class="table-light">
                                                        <tr><th>Key</th><th>Mô tả</th></tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($available_fields as $key => $desc): ?>
                                                        <tr>
                                                            <td><code><?php echo esc_html($key); ?></code></td>
                                                            <td><?php echo esc_html($desc); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="templateIsActive" value="1" checked>
                                    <label class="form-check-label fw-semibold" for="templateIsActive">Kích hoạt ngay</label>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="bx bx-check me-1"></i>Lưu Template
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnCancelEdit" style="display: none;">
                                    <i class="bx bx-x"></i> Hủy
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Danh sách template -->
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-list-ul me-2 text-primary"></i>Danh sách Template</h6>
                        <span class="badge bg-label-primary"><?php echo count($templates); ?> template</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($templates)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bx bx-package" style="font-size: 48px;"></i>
                                <p class="mt-2 mb-0">Chưa có template nào. Tạo template đầu tiên ở bên trái.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tên</th>
                                            <th>Sự kiện</th>
                                            <th>Template ID</th>
                                            <th>Trạng thái</th>
                                            <th style="width: 130px;">Hành động</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($templates as $tpl): ?>
                                        <tr id="tpl-row-<?php echo intval($tpl->id); ?>">
                                            <td>
                                                <strong><?php echo esc_html($tpl->label); ?></strong><br>
                                                <small class="text-muted"><?php echo esc_html($tpl->created_at); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-label-info"><?php echo esc_html($event_types[$tpl->event_type] ?? $tpl->event_type); ?></span>
                                            </td>
                                            <td><code><?php echo esc_html($tpl->zalo_template_id); ?></code></td>
                                            <td>
                                                <?php if ($tpl->is_active): ?>
                                                    <span class="badge bg-success">Bật</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Tắt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-icon btn-text-primary btn-sm btn-edit-template"
                                                        data-id="<?php echo intval($tpl->id); ?>"
                                                        data-label="<?php echo esc_attr($tpl->label); ?>"
                                                        data-event="<?php echo esc_attr($tpl->event_type); ?>"
                                                        data-zalo-id="<?php echo esc_attr($tpl->zalo_template_id); ?>"
                                                        data-mapping="<?php echo esc_attr($tpl->field_mapping); ?>"
                                                        data-active="<?php echo intval($tpl->is_active); ?>"
                                                        title="Sửa">
                                                        <i class="bx bx-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-icon btn-text-warning btn-sm btn-toggle-template"
                                                        data-id="<?php echo intval($tpl->id); ?>"
                                                        title="<?php echo $tpl->is_active ? 'Tắt' : 'Bật'; ?>">
                                                        <i class="bx <?php echo $tpl->is_active ? 'bx-pause' : 'bx-play'; ?>"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-icon btn-text-danger btn-sm btn-delete-template"
                                                        data-id="<?php echo intval($tpl->id); ?>"
                                                        title="Xóa">
                                                        <i class="bx bx-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  TAB: LỊCH SỬ GỬI TIN                                        -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <?php elseif ($active_tab === 'log'): ?>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold"><i class="bx bx-list-ul me-2 text-primary"></i>Lịch sử gửi tin</h6>
                <span class="badge bg-label-primary"><?php echo intval($log_total); ?> tin nhắn</span>
            </div>
            <div class="card-body pb-0">
                <!-- Filters -->
                <form method="get" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="page" value="tgs-shop-management">
                    <input type="hidden" name="view" value="zalo-oa">
                    <input type="hidden" name="tab" value="log">

                    <div class="col-auto">
                        <label class="form-label small mb-1">Trạng thái</label>
                        <select name="log_status" class="form-select form-select-sm" style="width: 150px;">
                            <option value="">Tất cả</option>
                            <option value="sent" <?php selected($filter_status, 'sent'); ?>>Đã gửi</option>
                            <option value="failed" <?php selected($filter_status, 'failed'); ?>>Thất bại</option>
                            <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>>Đã hủy</option>
                        </select>
                    </div>
                    <?php if ($is_network_admin): ?>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Site ID</label>
                        <input type="number" name="blog_id" class="form-control form-control-sm"
                               value="<?php echo $filter_blog ?: ''; ?>" placeholder="Tất cả" style="width: 100px;">
                    </div>
                    <?php endif; ?>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Số điện thoại</label>
                        <input type="text" name="phone" class="form-control form-control-sm"
                               value="<?php echo esc_attr($filter_phone); ?>" placeholder="0912..." style="width: 150px;">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bx bx-filter-alt me-1"></i>Lọc</button>
                        <a href="<?php echo esc_url($base_url . '&tab=log'); ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <?php if ($is_network_admin): ?><th>Site</th><?php endif; ?>
                            <th>SĐT</th>
                            <th>Template</th>
                            <th>Trạng thái</th>
                            <th>Zalo Msg ID</th>
                            <th>Retry</th>
                            <th>Tạo lúc</th>
                            <th>Gửi lúc</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="<?php echo $is_network_admin ? 9 : 8; ?>" class="text-center py-4 text-muted">
                                <i class="bx bx-inbox" style="font-size: 24px;"></i><br>Không có dữ liệu
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo intval($log->id); ?></td>
                                <?php if ($is_network_admin): ?><td><span class="badge bg-label-secondary"><?php echo intval($log->blog_id); ?></span></td><?php endif; ?>
                                <td><code><?php echo esc_html($log->phone); ?></code></td>
                                <td><code class="small"><?php echo esc_html($log->zalo_template_id); ?></code></td>
                                <td>
                                    <?php if ($log->status === 'sent'): ?>
                                        <span class="badge bg-success">Đã gửi</span>
                                    <?php elseif ($log->status === 'failed'): ?>
                                        <span class="badge bg-danger" title="<?php echo esc_attr($log->error_message); ?>">Thất bại</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo esc_html($log->status); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?php echo esc_html($log->zalo_msg_id ?: '-'); ?></small></td>
                                <td><?php echo intval($log->retry_count); ?></td>
                                <td><small><?php echo esc_html($log->created_at); ?></small></td>
                                <td><small><?php echo esc_html($log->sent_at ?: '-'); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($log_total_pages > 1): ?>
            <div class="card-footer d-flex justify-content-center">
                <?php
                $page_links = paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $current_page,
                    'total'   => $log_total_pages,
                    'type'    => 'array',
                    'prev_text' => '<i class="bx bx-chevron-left"></i>',
                    'next_text' => '<i class="bx bx-chevron-right"></i>',
                ]);
                if ($page_links) {
                    echo '<nav><ul class="pagination pagination-sm mb-0">';
                    foreach ($page_links as $link) {
                        $active = strpos($link, 'current') !== false ? ' active' : '';
                        echo '<li class="page-item' . $active . '">' . str_replace(['page-numbers', 'current'], ['page-link', ''], $link) . '</li>';
                    }
                    echo '</ul></nav>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Failed queue (retry) -->
        <?php if (!empty($failed_queue)): ?>
        <div class="card">
            <div class="card-header bg-label-danger">
                <h6 class="mb-0 fw-semibold"><i class="bx bx-error me-2"></i>Tin nhắn thất bại (có thể gửi lại)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Site</th>
                            <th>SĐT</th>
                            <th>Template</th>
                            <th>Lỗi</th>
                            <th>Retry</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_queue as $fq): ?>
                        <tr id="failed-row-<?php echo intval($fq->id); ?>">
                            <td><?php echo intval($fq->id); ?></td>
                            <td><span class="badge bg-label-secondary"><?php echo intval($fq->blog_id); ?></span></td>
                            <td><code><?php echo esc_html($fq->phone); ?></code></td>
                            <td><code><?php echo esc_html($fq->zalo_template_id); ?></code></td>
                            <td><small class="text-danger"><?php echo esc_html(mb_substr($fq->last_error ?? '', 0, 100)); ?></small></td>
                            <td><?php echo intval($fq->retry_count); ?>/<?php echo intval($fq->max_retries); ?></td>
                            <td>
                                <button type="button" class="btn btn-outline-warning btn-sm btn-retry-message"
                                        data-id="<?php echo intval($fq->id); ?>">
                                    <i class="bx bx-refresh me-1"></i>Gửi lại
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  TAB: HƯỚNG DẪN                                              -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <?php elseif ($active_tab === 'guide'): ?>

        <div class="row g-4">
            <div class="col-lg-8">

                <!-- Step 1 -->
                <div class="card mb-4">
                    <div class="card-header bg-label-primary">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-check-circle me-2"></i>Bước 1: Tạo Zalo App</h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0">
                            <li class="mb-2">Truy cập <a href="https://developers.zalo.me" target="_blank" class="fw-semibold">developers.zalo.me</a></li>
                            <li class="mb-2">Đăng nhập bằng tài khoản Zalo admin</li>
                            <li class="mb-2">Click <strong>"Tạo ứng dụng mới"</strong></li>
                            <li class="mb-2">Điền tên app, chọn loại: <strong>Ứng dụng OA</strong></li>
                            <li class="mb-2">Vào <strong>Cài đặt</strong> → copy <strong>App ID</strong> và <strong>Secret Key</strong></li>
                            <li class="mb-2">
                                Thêm <strong>Redirect URI</strong> (quan trọng!):<br>
                                <div class="bg-light rounded p-2 mt-1">
                                    <code style="word-break: break-all; font-size: 12px;"><?php echo esc_html($oauth_redirect_uri); ?></code>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-copy-url"
                                            data-url="<?php echo esc_attr($oauth_redirect_uri); ?>">
                                        <i class="bx bx-copy"></i>
                                    </button>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="card mb-4">
                    <div class="card-header bg-label-success">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-check-circle me-2"></i>Bước 2: Tạo & Duyệt Template ZNS</h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-3">
                            <li class="mb-2">Truy cập <a href="https://account.zalo.cloud/QBSPortal/zns/template/manage" target="_blank" class="fw-semibold">Zalo Business Portal → ZNS</a></li>
                            <li class="mb-2">Click <strong>"Tạo template"</strong></li>
                            <li class="mb-2">Chọn loại: <strong>Xác nhận giao dịch</strong> (phù hợp nhất cho hóa đơn)</li>
                            <li class="mb-2">
                                Thiết kế nội dung, ví dụ:<br>
                                <div class="bg-light rounded p-3 mt-2" style="font-size: 13px; border-left: 3px solid var(--tgs-info);">
                                    <strong>🛒 Xác nhận đơn hàng</strong><br><br>
                                    Cảm ơn <strong>{customer_name}</strong> đã mua hàng!<br>
                                    📋 Mã đơn: <strong>{order_code}</strong><br>
                                    💰 Tổng tiền: <strong>{amount}</strong> VNĐ<br>
                                    📅 Ngày: <strong>{date}</strong><br>
                                    Trạng thái: <strong>{status}</strong>
                                </div>
                            </li>
                            <li class="mb-2">Submit để <strong>Zalo duyệt</strong> (thường 1-2 ngày, dev mode nhanh hơn)</li>
                            <li class="mb-2">Sau khi duyệt, copy <strong>Template ID</strong> (dãy số 6 chữ số)</li>
                        </ol>
                        <div class="alert alert-warning py-2 mb-0" style="font-size: 13px;">
                            <i class="bx bx-bulb me-1"></i>
                            <strong>Mẹo:</strong> Bật <strong>Dev Mode</strong> trên Zalo Business Portal để duyệt template nhanh hơn và test miễn phí.
                        </div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="card mb-4">
                    <div class="card-header bg-label-warning">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-check-circle me-2"></i>Bước 3: Cấu hình plugin</h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0">
                            <li class="mb-2">
                                Vào tab <a href="<?php echo esc_url($base_url . '&tab=settings'); ?>" class="fw-semibold">Cài đặt</a>
                                → Nhập <strong>App ID</strong> + <strong>Secret Key</strong> → Lưu
                            </li>
                            <li class="mb-2">Click <strong>"Kết nối Zalo OA"</strong> → Đăng nhập Zalo → Cho phép quyền</li>
                            <li class="mb-2">Bật <strong>"Kích hoạt gửi tin"</strong> và <strong>"Chế độ Development"</strong> (nếu test)</li>
                            <li class="mb-2">
                                Vào tab <a href="<?php echo esc_url($base_url . '&tab=templates'); ?>" class="fw-semibold">Template ZNS</a>
                                → Thêm template mapping:
                                <ul class="mt-1">
                                    <li>Sự kiện: <code>Bán hàng thành công</code></li>
                                    <li>Template ID: (paste từ bước 2)</li>
                                    <li>
                                        Field Mapping mẫu:<br>
                                        <code style="font-size: 12px;">{"customer_name": "customer_name", "order_code": "sale_code", "amount": "total_amount_raw", "date": "sale_date", "status": "static:Đã thanh toán"}</code>
                                    </li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="card mb-4">
                    <div class="card-header bg-label-info">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-check-circle me-2"></i>Bước 4: Test gửi tin</h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-3">
                            <li class="mb-2">
                                Trên Zalo Business Portal → <strong>Cài đặt</strong> → <strong>Dev Mode</strong> → Thêm số điện thoại test
                                <div class="form-text">(Số người muốn nhận tin test phải <strong>follow OA</strong> trước)</div>
                            </li>
                            <li class="mb-2">Vào POS → Tạo 1 đơn bán với <strong>số điện thoại test</strong></li>
                            <li class="mb-2">Thanh toán xong → chờ tối đa <strong>1 phút</strong> (cron tự chạy)</li>
                            <li class="mb-2">Mở <strong>Zalo trên điện thoại</strong> → tin nhắn từ OA sẽ hiện lên!</li>
                            <li class="mb-2">Kiểm tra log tại tab <a href="<?php echo esc_url($base_url . '&tab=log'); ?>" class="fw-semibold">Lịch sử gửi tin</a></li>
                        </ol>
                        <div class="alert alert-success py-2 mb-0" style="font-size: 13px;">
                            <i class="bx bx-check me-1"></i>
                            <strong>Dev Mode hoàn toàn miễn phí.</strong> Khi chuyển production, chỉ cần tắt Dev Mode + nạp tiền. Không cần sửa code.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar: Quick reference -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-link-external me-2 text-info"></i>Link hữu ích</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-3">
                                <a href="https://developers.zalo.me" target="_blank" class="d-flex align-items-center text-decoration-none">
                                    <div class="avatar avatar-sm bg-label-primary rounded me-2"><i class="bx bx-code-alt"></i></div>
                                    <div>
                                        <strong>Zalo Developer</strong><br>
                                        <small class="text-muted">Quản lý App, API keys</small>
                                    </div>
                                </a>
                            </li>
                            <li class="mb-3">
                                <a href="https://account.zalo.cloud/QBSPortal/zns/template/manage" target="_blank" class="d-flex align-items-center text-decoration-none">
                                    <div class="avatar avatar-sm bg-label-success rounded me-2"><i class="bx bx-layout"></i></div>
                                    <div>
                                        <strong>Zalo Business Portal</strong><br>
                                        <small class="text-muted">Tạo & quản lý Template ZNS</small>
                                    </div>
                                </a>
                            </li>
                            <li class="mb-3">
                                <a href="https://developers.zalo.me/docs/official-account/bat-dau/xac-thuc-va-uy-quyen-cho-ung-dung" target="_blank" class="d-flex align-items-center text-decoration-none">
                                    <div class="avatar avatar-sm bg-label-warning rounded me-2"><i class="bx bx-book"></i></div>
                                    <div>
                                        <strong>Tài liệu OAuth Zalo</strong><br>
                                        <small class="text-muted">Xác thực & ủy quyền</small>
                                    </div>
                                </a>
                            </li>
                            <li>
                                <a href="https://developers.zalo.me/docs/official-account/tin-nhan/tin-zns" target="_blank" class="d-flex align-items-center text-decoration-none">
                                    <div class="avatar avatar-sm bg-label-info rounded me-2"><i class="bx bx-message-dots"></i></div>
                                    <div>
                                        <strong>Tài liệu ZNS API</strong><br>
                                        <small class="text-muted">Gửi tin nhắn ZNS</small>
                                    </div>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-dollar me-2 text-success"></i>Chi phí tham khảo</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0" style="font-size: 13px;">
                            <tr><td class="text-muted">Giá mỗi tin ZNS</td><td class="fw-semibold">~300-400 VNĐ</td></tr>
                            <tr><td class="text-muted">Dev Mode</td><td class="fw-semibold text-success">Miễn phí</td></tr>
                            <tr><td class="text-muted">Nạp tối thiểu</td><td class="fw-semibold">500.000 VNĐ</td></tr>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0 fw-semibold"><i class="bx bx-question-mark me-2 text-warning"></i>Lưu ý Dev Mode</h6>
                    </div>
                    <div class="card-body" style="font-size: 13px;">
                        <ul class="mb-0 ps-3">
                            <li class="mb-2">Chỉ gửi được đến <strong>số đã đăng ký test</strong></li>
                            <li class="mb-2">Số test phải <strong>follow OA</strong> trước</li>
                            <li class="mb-2">Tin nhắn <strong>hiện thật</strong> trên app Zalo</li>
                            <li class="mb-2">Chuyển production: <strong>tắt Dev Mode + nạp tiền</strong>, không sửa code</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>

</div><!-- .app-zalo-oa -->
