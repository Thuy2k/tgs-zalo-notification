<?php
/**
 * Network Admin Settings Page - Zalo OA configuration
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_network')) {
    echo '<div class="alert alert-danger">Bạn cần quyền quản trị mạng để truy cập trang này.</div>';
    return;
}

$app_id = get_site_option('tgs_zalo_app_id', '');
$secret_key = get_site_option('tgs_zalo_secret_key', '');
$enabled = get_site_option('tgs_zalo_enabled', 0);
$dev_mode = get_site_option('tgs_zalo_dev_mode', 0);
$batch_size = get_site_option('tgs_zalo_batch_size', 50);
$retry_max = get_site_option('tgs_zalo_retry_max', 3);
$enabled_blog_ids = get_site_option('tgs_zalo_enabled_blog_ids', []);
$enabled_blog_ids = is_array($enabled_blog_ids) ? array_values(array_unique(array_map('intval', $enabled_blog_ids))) : [];
$deploy_sites = [];
if (function_exists('get_sites')) {
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
}

$token_status = TGS_Zalo_Token_Manager::get_status();
$queue_stats = TGS_Zalo_Queue::get_stats();

// OAuth callback URL
$oauth_redirect_uri = admin_url('?tgs_zalo_oauth_callback=1');

// Flash messages
$oauth_result = sanitize_text_field($_GET['zalo_oauth'] ?? '');
$oauth_msg = sanitize_text_field($_GET['msg'] ?? '');
?>

<div class="tgs-zalo-wrap">

    <?php if ($oauth_result === 'success'): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Kết nối Zalo OA thành công!</strong> Token đã được lưu.</p>
        </div>
    <?php elseif ($oauth_result === 'error'): ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>Kết nối Zalo OA thất bại:</strong> <?php echo esc_html($oauth_msg); ?></p>
        </div>
    <?php endif; ?>

    <div id="tgsZaloNotice" class="notice" style="display:none;"><p></p></div>

    <!-- Status Overview -->
    <div class="tgs-zalo-cards">
        <div class="tgs-zalo-card">
            <h3>Trạng thái kết nối</h3>
            <div class="tgs-zalo-status <?php echo $token_status['has_refresh_token'] ? ($token_status['is_expired'] ? 'warning' : 'connected') : 'disconnected'; ?>">
                <?php if ($token_status['has_refresh_token']): ?>
                    <?php if ($token_status['is_expired']): ?>
                        <span class="dashicons dashicons-warning"></span> Token hết hạn (sẽ tự refresh)
                    <?php else: ?>
                        <span class="dashicons dashicons-yes-alt"></span> Đã kết nối
                    <?php endif; ?>
                <?php else: ?>
                    <span class="dashicons dashicons-dismiss"></span> Chưa kết nối
                <?php endif; ?>
            </div>
            <table class="tgs-zalo-info-table">
                <tr><td>Refresh Token:</td><td><?php echo $token_status['has_refresh_token'] ? '<span class="text-success">Có</span>' : '<span class="text-danger">Chưa có</span>'; ?></td></tr>
                <tr><td>Token hết hạn:</td><td><?php echo esc_html($token_status['expires_at']); ?></td></tr>
                <tr><td>Thời gian còn:</td><td><?php echo esc_html($token_status['time_remaining']); ?></td></tr>
                <tr><td>Lần refresh cuối:</td><td><?php echo esc_html($token_status['last_refresh']); ?></td></tr>
            </table>
        </div>

        <div class="tgs-zalo-card">
            <h3>Hàng đợi tin nhắn</h3>
            <table class="tgs-zalo-info-table">
                <tr><td>Đang chờ:</td><td><strong><?php echo intval($queue_stats['pending']); ?></strong></td></tr>
                <tr><td>Đang xử lý:</td><td><?php echo intval($queue_stats['processing']); ?></td></tr>
                <tr><td>Đã gửi:</td><td><span class="text-success"><?php echo intval($queue_stats['sent']); ?></span></td></tr>
                <tr><td>Thất bại:</td><td><span class="text-danger"><?php echo intval($queue_stats['failed']); ?></span></td></tr>
            </table>
            <button type="button" class="button" id="btnTestConnection">
                <span class="dashicons dashicons-admin-plugins"></span> Test kết nối & Quota
            </button>
        </div>
    </div>

    <!-- Settings Form -->
    <div class="tgs-zalo-section">
        <h2>Cài đặt Zalo OA</h2>

        <form id="tgsZaloSettingsForm">
            <table class="form-table">
                <tr>
                    <th scope="row">Bật thông báo Zalo</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($enabled, 1); ?>>
                            Kích hoạt gửi tin nhắn Zalo ZNS
                        </label>
                        <p class="description">Khi tắt, tin nhắn sẽ vẫn được thêm vào hàng đợi nhưng không gửi đi.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Zalo App ID</th>
                    <td>
                        <input type="text" name="app_id" value="<?php echo esc_attr($app_id); ?>" class="regular-text" placeholder="Nhập App ID từ Zalo Developer">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Zalo Secret Key</th>
                    <td>
                        <input type="password" name="secret_key" value="<?php echo $secret_key ? '********' : ''; ?>" class="regular-text" placeholder="Nhập Secret Key">
                        <p class="description">Để trống nếu không muốn thay đổi.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Chế độ Development</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dev_mode" value="1" <?php checked($dev_mode, 1); ?>>
                            Gửi tin trong chế độ test (không tốn phí, chỉ gửi tới số test)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Batch Size</th>
                    <td>
                        <input type="number" name="batch_size" value="<?php echo intval($batch_size); ?>" min="1" max="100" class="small-text">
                        <p class="description">Số tin nhắn xử lý mỗi lần chạy cron (mỗi phút). Khuyến nghị: 50.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Số lần retry tối đa</th>
                    <td>
                        <input type="number" name="retry_max" value="<?php echo intval($retry_max); ?>" min="0" max="10" class="small-text">
                        <p class="description">Số lần gửi lại khi thất bại. Backoff: 5 phút → 15 phút → 30 phút.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Triển khai theo shop</th>
                    <td>
                        <p class="description">Chỉ các shop được chọn mới gửi Zalo OA tự động. Không chọn shop nào = không gửi.</p>
                        <div style="max-height:260px;overflow:auto;border:1px solid #ccd0d4;padding:12px;background:#fff;">
                            <?php foreach ($deploy_sites as $deploy_site): ?>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="checkbox" class="deploy-site-checkbox" name="deploy_blog_ids[]" value="<?php echo intval($deploy_site['blog_id']); ?>" <?php checked($deploy_site['selected']); ?>>
                                    <strong>#<?php echo intval($deploy_site['blog_id']); ?> - <?php echo esc_html($deploy_site['name']); ?></strong>
                                    <br><span style="color:#646970;margin-left:22px;"><?php echo esc_html($deploy_site['siteurl']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-saved"></span> Lưu cài đặt
                </button>
            </p>
        </form>
    </div>

    <!-- OAuth Section -->
    <div class="tgs-zalo-section">
        <h2>Kết nối Zalo OA (OAuth)</h2>
        <p>Để gửi tin ZNS, bạn cần kết nối tài khoản Zalo OA. Click nút bên dưới để đăng nhập và cấp quyền.</p>

        <?php if (!empty($app_id)): ?>
            <?php $oauth_url = TGS_Zalo_Token_Manager::get_oauth_url($oauth_redirect_uri); ?>
            <a href="<?php echo esc_url($oauth_url); ?>" class="button button-primary button-hero" id="btnConnectZalo">
                <span class="dashicons dashicons-admin-links"></span>
                <?php echo $token_status['has_refresh_token'] ? 'Kết nối lại Zalo OA' : 'Kết nối Zalo OA'; ?>
            </a>
            <p class="description">Callback URL: <code><?php echo esc_html($oauth_redirect_uri); ?></code></p>
            <p class="description"><strong>Lưu ý:</strong> Hãy thêm URL callback ở trên vào cài đặt ứng dụng Zalo tại <a href="https://developers.zalo.me" target="_blank">developers.zalo.me</a></p>
        <?php else: ?>
            <p class="description"><strong>Vui lòng nhập App ID và Secret Key trước, sau đó lưu cài đặt.</strong></p>
        <?php endif; ?>
    </div>
</div>
