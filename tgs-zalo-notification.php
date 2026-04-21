<?php
/**
 * Plugin Name: TGS Zalo Notification
 * Plugin URI:  https://tgs.vn
 * Description: Gửi thông báo Zalo ZNS tự động khi bán hàng thành công. Tích hợp với TGS Shop Management.
 * Version:     1.0.0
 * Author:      TGS Team
 * Author URI:  https://tgs.vn
 * Network:     true
 * Text Domain: tgs-zalo-notification
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_ZALO_VERSION', '1.0.0');
define('TGS_ZALO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_ZALO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TGS_ZALO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Zalo API endpoints
define('TGS_ZALO_OAUTH_URL', 'https://oauth.zaloapp.com/v4/oa/access_token');
define('TGS_ZALO_ZNS_URL', 'https://business.openapi.zalo.me/message/template');
define('TGS_ZALO_QUOTA_URL', 'https://business.openapi.zalo.me/message/quota');
define('TGS_ZALO_MSG_STATUS_URL', 'https://business.openapi.zalo.me/message/status');

// Autoload includes
require_once TGS_ZALO_PLUGIN_DIR . 'includes/class-tgs-zalo-token-manager.php';
require_once TGS_ZALO_PLUGIN_DIR . 'includes/class-tgs-zalo-api.php';
require_once TGS_ZALO_PLUGIN_DIR . 'includes/class-tgs-zalo-queue.php';
require_once TGS_ZALO_PLUGIN_DIR . 'includes/class-tgs-zalo-cron.php';
require_once TGS_ZALO_PLUGIN_DIR . 'includes/class-tgs-zalo-hooks.php';

/**
 * Main plugin class
 */
class TGS_Zalo_Notification {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Init hooks
        add_action('init', [$this, 'init']);

        // Hook into TGS Shop Management dashboard routes & menu
        add_filter('tgs_shop_dashboard_routes', [$this, 'add_dashboard_routes']);
        add_action('tgs_shop_system_menu', [$this, 'add_sidebar_menu']);

        // Cron schedule
        TGS_Zalo_Cron::register();

        // Sale hook listener
        TGS_Zalo_Hooks::register();

        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_tgs_zalo_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_tgs_zalo_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_tgs_zalo_save_template', [$this, 'ajax_save_template']);
        add_action('wp_ajax_tgs_zalo_delete_template', [$this, 'ajax_delete_template']);
        add_action('wp_ajax_tgs_zalo_toggle_template', [$this, 'ajax_toggle_template']);
        add_action('wp_ajax_tgs_zalo_retry_message', [$this, 'ajax_retry_message']);
        add_action('wp_ajax_tgs_zalo_send_test', [$this, 'ajax_send_test']);

        // OAuth callback
        add_action('admin_init', [$this, 'handle_oauth_callback']);

        // Deactivation hook — unschedule cron events
        register_deactivation_hook(__FILE__, ['TGS_Zalo_Cron', 'unschedule']);
    }

    public function init() {
        // Tables are managed by tgs_shop_management (class-tgs-database.php)
    }

    /**
     * Register routes into TGS Shop Management dashboard
     */
    public function add_dashboard_routes($routes) {
        $routes['zalo-oa'] = ['Zalo OA', TGS_ZALO_PLUGIN_DIR . 'admin-views/zalo-oa.php'];
        return $routes;
    }

    /**
     * Add menu item to TGS Shop system dropdown
     */
    public function add_sidebar_menu($current_view) {
        ?>
        <li class="menu-item <?php echo $current_view === 'zalo-oa' ? 'active' : ''; ?>">
            <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=zalo-oa'); ?>" class="menu-link">
                <i class="bx bx-message-dots text-info me-1"></i>
                <div>Zalo OA</div>
            </a>
        </li>
        <?php
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'tgs-shop-management') === false) return;

        $current_view = sanitize_text_field($_GET['view'] ?? '');
        if ($current_view !== 'zalo-oa') return;

        wp_enqueue_style(
            'tgs-zalo-admin',
            TGS_ZALO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            TGS_ZALO_VERSION
        );

        wp_enqueue_script(
            'tgs-zalo-admin',
            TGS_ZALO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            TGS_ZALO_VERSION,
            true
        );

        wp_localize_script('tgs-zalo-admin', 'tgsZaloAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tgs_zalo_admin'),
        ]);
    }

    /**
     * Handle OAuth callback from Zalo
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['tgs_zalo_oauth_callback'])) return;

        // Verify user permission
        if (!current_user_can('manage_network') && !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $code = sanitize_text_field($_GET['code'] ?? '');
        if (empty($code)) {
            wp_die('Missing authorization code from Zalo.');
        }

        // Verify state parameter to prevent CSRF
        $state = sanitize_text_field($_GET['state'] ?? '');
        $expected_state = get_site_option('tgs_zalo_oauth_state', '');
        if (empty($state) || !hash_equals($expected_state, $state)) {
            wp_die('Invalid OAuth state. Please try again.');
        }
        delete_site_option('tgs_zalo_oauth_state');

        $code_verifier = get_site_option('tgs_zalo_oauth_code_verifier', '');

        $result = TGS_Zalo_Token_Manager::exchange_code_for_tokens($code, $code_verifier);

        delete_site_option('tgs_zalo_oauth_code_verifier');

        if (is_wp_error($result)) {
            $redirect_url = admin_url('admin.php?page=tgs-shop-management&view=zalo-oa&tab=settings&zalo_oauth=error&msg=' . urlencode($result->get_error_message()));
        } else {
            $redirect_url = admin_url('admin.php?page=tgs-shop-management&view=zalo-oa&tab=settings&zalo_oauth=success');
        }

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Unauthorized');
        }

        $app_id = sanitize_text_field($_POST['app_id'] ?? '');
        $secret_key = sanitize_text_field($_POST['secret_key'] ?? '');
        $enabled = intval($_POST['enabled'] ?? 0);
        $dev_mode = intval($_POST['dev_mode'] ?? 0);
        $batch_size = max(1, min(100, intval($_POST['batch_size'] ?? 50)));
        $retry_max = max(0, min(10, intval($_POST['retry_max'] ?? 3)));

        update_site_option('tgs_zalo_app_id', $app_id);
        if (!empty($secret_key) && $secret_key !== '********') {
            update_site_option('tgs_zalo_secret_key', $secret_key);
        }
        update_site_option('tgs_zalo_enabled', $enabled);
        update_site_option('tgs_zalo_dev_mode', $dev_mode);
        update_site_option('tgs_zalo_batch_size', $batch_size);
        update_site_option('tgs_zalo_retry_max', $retry_max);

        wp_send_json_success('Đã lưu cài đặt.');
    }

    /**
     * AJAX: Test Zalo connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Unauthorized');
        }

        // Step 1: Check token exists and is valid
        $token = TGS_Zalo_Token_Manager::get_access_token();
        if (is_wp_error($token)) {
            wp_send_json_error('Kết nối thất bại: ' . $token->get_error_message());
        }

        $result = [
            'message' => 'Kết nối thành công! Access token hợp lệ.',
            'token_status' => 'OK',
        ];

        // Step 2: Try to get quota (optional — may fail if ZNS not fully enabled)
        $quota = TGS_Zalo_API::get_quota();
        if (!is_wp_error($quota)) {
            $result['daily_quota'] = $quota['dailyQuota'] ?? 'N/A';
            $result['remaining_quota'] = $quota['remainingQuota'] ?? 'N/A';
        } else {
            $result['quota_note'] = 'Quota API chưa khả dụng (không ảnh hưởng gửi tin): ' . $quota->get_error_message();
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Save template mapping
     */
    public function ajax_save_template() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = TGS_TABLE_ZALO_TEMPLATES;

        $id = intval($_POST['template_id'] ?? 0);
        $zalo_template_id = sanitize_text_field($_POST['zalo_template_id'] ?? '');
        $event_type = sanitize_text_field($_POST['event_type'] ?? '');
        $label = sanitize_text_field($_POST['label'] ?? '');
        $field_mapping = sanitize_textarea_field($_POST['field_mapping'] ?? '{}');
        $is_active = intval($_POST['is_active'] ?? 1);

        if (empty($zalo_template_id) || empty($event_type) || empty($label)) {
            wp_send_json_error('Thiếu thông tin bắt buộc.');
        }

        // Validate JSON
        $decoded = json_decode($field_mapping, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Field mapping JSON không hợp lệ.');
        }

        $data = [
            'zalo_template_id' => $zalo_template_id,
            'event_type'       => $event_type,
            'label'            => $label,
            'field_mapping'    => $field_mapping,
            'is_active'        => $is_active,
            'updated_at'       => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }

        wp_send_json_success('Đã lưu template.');
    }

    /**
     * AJAX: Delete template
     */
    public function ajax_delete_template() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = TGS_TABLE_ZALO_TEMPLATES;
        $id = intval($_POST['template_id'] ?? 0);

        if ($id > 0) {
            $wpdb->delete($table, ['id' => $id]);
            wp_send_json_success('Đã xóa template.');
        }

        wp_send_json_error('ID không hợp lệ.');
    }

    /**
     * AJAX: Toggle template active/inactive
     */
    public function ajax_toggle_template() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = TGS_TABLE_ZALO_TEMPLATES;
        $id = intval($_POST['template_id'] ?? 0);

        if ($id > 0) {
            $current = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$table} WHERE id = %d", $id));
            $new_status = $current ? 0 : 1;
            $wpdb->update($table, ['is_active' => $new_status, 'updated_at' => current_time('mysql')], ['id' => $id]);
            wp_send_json_success(['is_active' => $new_status]);
        }

        wp_send_json_error('ID không hợp lệ.');
    }

    /**
     * AJAX: Retry failed message
     */
    public function ajax_retry_message() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network') && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $message_id = intval($_POST['message_id'] ?? 0);
        if ($message_id > 0) {
            TGS_Zalo_Queue::reset_for_retry($message_id);
            wp_send_json_success('Đã đặt lại tin nhắn để gửi lại.');
        }

        wp_send_json_error('ID không hợp lệ.');
    }

    /**
     * AJAX: Send test ZNS message — supports both direct (raw template ID + JSON data)
     * and pre-configured templates
     */
    public function ajax_send_test() {
        check_ajax_referer('tgs_zalo_admin', 'nonce');

        if (!current_user_can('manage_network')) {
            wp_send_json_error('Unauthorized');
        }

        $phone_raw = sanitize_text_field($_POST['phone'] ?? '');
        $zalo_template_id = sanitize_text_field($_POST['zalo_template_id'] ?? '');
        $raw_template_data = sanitize_textarea_field($_POST['template_data'] ?? '');
        $config_template_id = intval($_POST['config_template_id'] ?? 0);

        if (empty($phone_raw)) {
            wp_send_json_error('Vui lòng nhập số điện thoại.');
        }

        $phone = TGS_Zalo_API::format_phone($phone_raw);
        if (empty($phone)) {
            wp_send_json_error('Số điện thoại không hợp lệ. Định dạng: 09xxxxxxxx hoặc 84xxxxxxxxx');
        }

        global $wpdb;
        $template_label = '';

        // Mode 1: Use pre-configured template from plugin
        if ($config_template_id > 0) {
            $table = TGS_TABLE_ZALO_TEMPLATES;
            $template = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d", $config_template_id
            ));
            if (!$template) {
                wp_send_json_error('Template #' . $config_template_id . ' không tồn tại.');
            }

            $zalo_template_id = $template->zalo_template_id;
            $template_label = $template->label;

            // Build data from mapping with sample values
            $field_mapping = json_decode($template->field_mapping, true) ?: [];
            $template_data = [];
            $sample_values = [
                'customer_name'  => 'Khách Test',
                'customer_phone' => $phone_raw,
                'customer_email' => 'test@example.com',
                'customer_id'    => 'KH-001',
                'sale_code'      => 'TEST-' . date('YmdHis'),
                'export_code'    => 'PX-TEST-' . date('YmdHis'),
                'total_amount'   => '1.500.000đ',
                'total_amount_raw' => 1500000,
                'total_items'    => '3',
                'discount'       => '0đ',
                'discount_raw'   => 0,
                'sale_date'      => date('d/m/Y H:i'),
                'shop_name'      => get_bloginfo('name'),
                'shop_address'   => get_option('tgs_shop_address', get_bloginfo('name')),
            ];
            foreach ($field_mapping as $zalo_param => $data_key) {
                if (strpos($data_key, 'static:') === 0) {
                    $template_data[$zalo_param] = substr($data_key, 7);
                } else {
                    $template_data[$zalo_param] = $sample_values[$data_key] ?? $data_key;
                }
            }

        // Mode 2: Direct — raw Zalo template ID + JSON data
        } else {
            if (empty($zalo_template_id)) {
                wp_send_json_error('Vui lòng nhập Zalo Template ID.');
            }

            $template_data = [];
            if (!empty($raw_template_data)) {
                $template_data = json_decode($raw_template_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    wp_send_json_error('Template Data JSON không hợp lệ: ' . json_last_error_msg());
                }
            }

            $template_label = 'Direct [' . $zalo_template_id . ']';
        }

        // Send
        $result = TGS_Zalo_API::send_zns_message($phone, $zalo_template_id, $template_data, 'test-' . time());

        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $detail = '';
            if (!empty($error_data['error_code'])) {
                $detail = ' [code: ' . $error_data['error_code'] . ']';
            }
            wp_send_json_error('Gửi thất bại: ' . $result->get_error_message() . $detail);
        }

        $msg_id = $result['data']['msg_id'] ?? ($result['msg_id'] ?? 'N/A');

        // Log
        $log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
        $wpdb->insert($log_table, [
            'blog_id'          => get_current_blog_id(),
            'phone'            => $phone,
            'zalo_template_id' => $zalo_template_id,
            'template_data'    => wp_json_encode($template_data, JSON_UNESCAPED_UNICODE),
            'status'           => 'sent',
            'zalo_msg_id'      => $msg_id,
            'retry_count'      => 0,
            'created_at'       => current_time('mysql'),
            'sent_at'          => current_time('mysql'),
        ]);

        $dev_mode = get_site_option('tgs_zalo_dev_mode', 0);
        wp_send_json_success([
            'message'        => 'Gửi thành công! Kiểm tra Zalo trên điện thoại.' . ($dev_mode ? ' (Dev Mode - miễn phí)' : ''),
            'msg_id'         => $msg_id,
            'phone'          => $phone,
            'template_id'    => $zalo_template_id,
            'template_label' => $template_label,
            'template_data'  => $template_data,
        ]);
    }
}

// Initialize plugin
TGS_Zalo_Notification::get_instance();
