<?php
/**
 * Hooks - Listens to tgs_shop_management events and enqueues Zalo messages
 */

if (!defined('ABSPATH')) exit;

class TGS_Zalo_Hooks {

    /**
     * Register hooks
     */
    public static function register() {
        // Hook into sale completion (fired after DB COMMIT in TGS_API_Sales::create_sale)
        add_action('tgs_sale_completed', [__CLASS__, 'on_sale_completed'], 10, 1);

        // Hook into manual ticket approval (optional - for non-POS sales)
        add_action('tgs_ledger_status_changed', [__CLASS__, 'on_ledger_status_changed'], 10, 3);

        self::sync_default_sale_points_template();
    }

    /**
     * Handle sale completed event
     *
     * @param array $sale_data Sale data from tgs_sale_completed hook
     */
    public static function on_sale_completed($sale_data) {
        error_log('[TGS Zalo] on_sale_completed fired. sale_code=' . ($sale_data['sale_code'] ?? 'n/a') . ' blog_id=' . ($sale_data['blog_id'] ?? get_current_blog_id()) . ' source_type=' . ($sale_data['source_type'] ?? 'n/a'));

        if (!get_site_option('tgs_zalo_enabled', 0) && !get_site_option('tgs_zalo_intermediary_enabled', 0)) {
            error_log('[TGS Zalo] Skipped: cả tgs_zalo_enabled và tgs_zalo_intermediary_enabled đều = 0');
            return;
        }

        global $wpdb;

        $phone = $sale_data['person_phone'] ?? '';
        if (empty($phone)) {
            error_log('[TGS Zalo] Skipped: empty phone');
            return;
        }

        // Check if phone is valid for Zalo
        $formatted_phone = TGS_Zalo_API::format_phone($phone);
        if (empty($formatted_phone)) {
            error_log('[TGS Zalo] Skipped: invalid phone format: ' . $phone);
            return;
        }

        $blog_id = $sale_data['blog_id'] ?? get_current_blog_id();

        // Get shop/site name for template
        $site_name = '';
        if (function_exists('get_blog_details')) {
            $blog = get_blog_details($blog_id);
            $site_name = $blog ? $blog->blogname : get_bloginfo('name');
        } else {
            $site_name = get_bloginfo('name');
        }

        $shop_address = $sale_data['shop_address'] ?? get_option('tgs_shop_address', $site_name);
        $order_code = (string) ($sale_data['order_code'] ?? ($sale_data['sale_code'] ?? ''));
        $order_date = self::to_zalo_date_string($sale_data['order_date'] ?? ($sale_data['sale_date'] ?? ''));
        $price = intval(round($sale_data['price'] ?? ($sale_data['total_amount'] ?? 0)));
        $earned_points = isset($sale_data['point'])
            ? intval($sale_data['point'])
            : self::calculate_points($sale_data['total_amount'] ?? 0);
        $customer_wp_user_id = intval($sale_data['customer_wp_user_id'] ?? 0);
        $base_points = self::get_current_wallet_points($phone, $customer_wp_user_id);
        $total_points = $base_points + max(0, intval($earned_points));
        $note = self::build_points_note($sale_data['note'] ?? '', $shop_address, $site_name);

        // Build available data for template mapping
        $available_data = [
            'customer_name'    => $sale_data['person_name'] ?? 'Quý khách',
            'customer_phone'   => $phone,
            'customer_email'   => $sale_data['person_email'] ?? '',
            'customer_id'      => $sale_data['customer_id'] ?? '',
            'customer_code'    => $sale_data['customer_code'] ?? ($sale_data['customer_id'] ?? ''),
            'sale_code'        => $sale_data['sale_code'] ?? '',
            'order_code'       => $order_code,
            'blog_id'          => (string) $blog_id,
            'order_code_url'   => self::build_order_lookup_url($blog_id, $order_code),
            // Phàn trung gian: hóa đơn dưới dạng query param cho nút button Zalo
            'hoadon_query'     => $order_code !== '' ? 'order_code=' . rawurlencode($order_code) : '',
            'export_code'      => $sale_data['export_code'] ?? '',
            'order_date'       => $order_date,
            'price'            => $price,
            'point'            => $earned_points,
            'total_point'      => $total_points,
            'note'             => self::truncate_text($note, 30),
            'total_amount'         => self::format_currency($sale_data['total_amount'] ?? 0),
            'total_amount_raw'     => intval($sale_data['total_amount'] ?? 0),
            'total_items'      => $sale_data['total_items'] ?? 0,
            'discount'             => self::format_currency($sale_data['discount'] ?? 0),
            'discount_raw'         => intval($sale_data['discount'] ?? 0),
            'sale_date'        => current_time('d/m/Y H:i:s'),
            'sale_date_only'   => current_time('d/m/Y'),
            'shop_name'        => $sale_data['shop_name'] ?? $site_name,
            'shop_address'     => $shop_address,
            'employee_id'      => $sale_data['employee_id'] ?? 0,
        ];

        // Find all active templates for 'sale_completed' event
        $templates = self::get_active_templates('sale_completed');

        if (empty($templates)) {
            error_log('[TGS Zalo Hooks] No active templates for sale_completed event.');
            return;
        }

        foreach ($templates as $template) {
            // Kiểm tra phạm vi shop của từng template
            if (!self::is_blog_enabled_for_template($blog_id, $template)) {
                error_log('[TGS Zalo] Template #' . $template->id . ' skipped: blog_id=' . $blog_id . ' not in template scope. enabled_blog_ids=' . ($template->enabled_blog_ids ?? 'null'));
                continue;
            }

            $field_mapping = json_decode($template->field_mapping, true) ?: [];
            $template_data = self::map_fields($field_mapping, $available_data);

            if (empty($template_data)) {
                error_log('[TGS Zalo] Template #' . $template->id . ' skipped: empty template_data after mapping. field_mapping=' . $template->field_mapping);
                continue;
            }

            $tracking_id = 'sale_' . $blog_id . '_' . ($sale_data['sale_code'] ?? uniqid()) . '_' . $template->id;

            // Prevent duplicate sends for the same sale + template
            $queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE tracking_id = %s",
                $tracking_id
            ));
            if ($exists > 0) {
                error_log('[TGS Zalo Hooks] Duplicate tracking_id skipped: ' . $tracking_id);
                continue;
            }

            $provider = in_array($template->provider ?? '', ['official', 'intermediary'], true)
                ? $template->provider
                : 'official';

            TGS_Zalo_Queue::enqueue([
                'blog_id'          => $blog_id,
                'phone'            => $phone,
                'template_id'      => $template->id,
                'zalo_template_id' => $template->zalo_template_id,
                'provider'         => $provider,
                'template_data'    => $template_data,
                'tracking_id'      => $tracking_id,
            ]);

            error_log('[TGS Zalo] Enqueued template #' . $template->id . ' provider=' . $provider . ' tracking=' . $tracking_id);
        }

        // Gửi ngay sau khi enqueue — ignore_user_abort đảm bảo PHP không bị kill
        // dù POS client đã nhận response và disconnect
        ignore_user_abort(true);
        TGS_Zalo_Queue::process_queue();
    }
    /**
     * Handle ledger status change (for manual approval of non-POS sales)
     *
     * @param int $ledger_id
     * @param int $old_status
     * @param int $new_status
     */
    public static function on_ledger_status_changed($ledger_id, $old_status, $new_status) {
        if (!get_site_option('tgs_zalo_enabled', 0) && !get_site_option('tgs_zalo_intermediary_enabled', 0)) {
            return;
        }

        $blog_id = get_current_blog_id();

        // Only trigger on approval (status 4) of sale orders (type 10)
        if ($new_status != 4) {
            return;
        }

        // Check if this is a sale order
        global $wpdb;
        $table_name = defined('TGS_TABLE_LOCAL_LEDGER') ? TGS_TABLE_LOCAL_LEDGER : '';
        if (empty($table_name)) return;

        $ledger = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE local_ledger_id = %d",
            $ledger_id
        ));

        if (!$ledger) return;

        $ledger_type = intval($ledger->local_ledger_type ?? 0);
        if ($ledger_type !== 10) return; // Only sale orders (TGS_LEDGER_TYPE_SALE_ORDER = 10)

        // Get customer info
        $person_id = intval($ledger->local_ledger_person_id ?? 0);
        if ($person_id <= 0) return;

        $person_table = defined('TGS_TABLE_LOCAL_LEDGER_PERSON') ? TGS_TABLE_LOCAL_LEDGER_PERSON : '';
        if (empty($person_table)) return;

        $person = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$person_table} WHERE local_ledger_person_id = %d",
            $person_id
        ));

        if (!$person || empty($person->local_ledger_person_phone)) return;

        // (ledger path) kiểm tra theo global enabled_blog_ids vì không có template cụ thể
        if (!self::is_blog_enabled($blog_id)) {
            return;
        }

        $site_name = get_bloginfo('name');
        $shop_address = get_option('tgs_shop_address', $site_name);
        $order_code = (string) ($ledger->local_ledger_code ?? '');
        $price = intval(round(floatval($ledger->local_ledger_total_amount ?? 0)));
        $earned_points = self::calculate_points($price);
        $customer_wp_user_id = intval($person->user_wp_id ?? 0);
        $base_points = self::get_current_wallet_points($person->local_ledger_person_phone, $customer_wp_user_id);
        $total_points = $base_points + max(0, intval($earned_points));

        $available_data = [
            'customer_name'    => $person->local_ledger_person_name ?? 'Quý khách',
            'customer_phone'   => $person->local_ledger_person_phone,
            'customer_id'      => $person_id,
            'customer_code'    => $person->local_ledger_person_code ?? $person_id,
            'sale_code'        => $order_code,
            'order_code'       => $order_code,
            'blog_id'          => (string) $blog_id,
            'order_code_url'   => self::build_order_lookup_url($blog_id, $order_code),
            'hoadon_query'     => $order_code !== '' ? 'order_code=' . rawurlencode($order_code) : '',
            'order_date'       => self::to_zalo_date_string(current_time('timestamp')),
            'price'            => $price,
            'point'            => $earned_points,
            'total_point'      => $total_points,
            'note'             => self::build_points_note('', $shop_address, $site_name),
            'total_amount'         => self::format_currency(floatval($ledger->local_ledger_total_amount ?? 0)),
            'total_amount_raw'     => intval($ledger->local_ledger_total_amount ?? 0),
            'sale_date'        => current_time('d/m/Y H:i'),
            'shop_name'        => $site_name,
            'shop_address'     => $shop_address,
        ];

        $templates = self::get_active_templates('sale_completed');

        foreach ($templates as $template) {
            if (!self::is_blog_enabled_for_template($blog_id, $template)) {
                continue;
            }

            $field_mapping = json_decode($template->field_mapping, true) ?: [];
            $template_data = self::map_fields($field_mapping, $available_data);

            if (empty($template_data)) continue;

            $tracking_id = 'approve_' . $blog_id . '_' . ($ledger->local_ledger_code ?? $ledger_id) . '_' . $template->id;

            // Prevent duplicate sends
            $queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE tracking_id = %s",
                $tracking_id
            ));
            if ($exists > 0) continue;

            $provider = in_array($template->provider ?? '', ['official', 'intermediary'], true)
                ? $template->provider
                : 'official';

            TGS_Zalo_Queue::enqueue([
                'blog_id'          => $blog_id,
                'phone'            => $person->local_ledger_person_phone,
                'template_id'      => $template->id,
                'zalo_template_id' => $template->zalo_template_id,
                'provider'         => $provider,
                'template_data'    => $template_data,
                'tracking_id'      => $tracking_id,
            ]);
        }
    }

    /**
     * Get active templates for an event type (không lọc theo template ID cụ thể)
     *
     * @param string $event_type
     * @return array
     */
    private static function get_active_templates($event_type) {
        global $wpdb;
        $table = TGS_TABLE_ZALO_TEMPLATES;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_type = %s AND is_active = 1",
            $event_type
        ));
    }

    /**
     * Map internal data keys to Zalo template parameter names
     *
     * @param array $mapping  { "zalo_param_name" => "internal_data_key", ... }
     * @param array $data     Available data
     * @return array Mapped template data for Zalo
     */
    private static function map_fields(array $mapping, array $data) {
        $result = [];
        $empty  = [];

        foreach ($mapping as $zalo_key => $internal_key) {
            // Support static values (prefixed with "static:")
            if (strpos($internal_key, 'static:') === 0) {
                $result[$zalo_key] = substr($internal_key, 7);
                continue;
            }

            if (isset($data[$internal_key])) {
                $value = $data[$internal_key];
                // Keep numeric types for Zalo number/date fields (raw keys)
                $result[$zalo_key] = is_int($value) || is_float($value) ? $value : (string) $value;

                if ($result[$zalo_key] === '') {
                    $empty[] = $zalo_key . ' (' . $internal_key . ')';
                }
            }
        }

        /*
         * ZNS TỪ CHỐI NGUYÊN GÓI TIN NẾU CÓ MỘT THAM SỐ RỖNG.
         *
         * Bên trung gian chỉ trả về đúng một câu "Template data không hợp lệ",
         * không nói tham số nào — nhìn log không tài nào biết hỏng ở đâu, phải
         * mở DB đọc cột template_data mới ra. Ghi thẳng tên tham số rỗng ra log
         * để lần sau đọc log là biết.
         *
         * Ghi log chứ KHÔNG tự điền bừa giá trị thay thế: mỗi tham số một ý
         * nghĩa, "-" nhét vào tham số nút tra cứu hoá đơn là sinh ra cái link
         * hỏng gửi tới khách. Chỗ nào thiếu thì sửa đúng chỗ đó.
         */
        if (!empty($empty)) {
            error_log('[TGS Zalo] Tham số RỖNG, ZNS sẽ từ chối cả gói tin: ' . implode(', ', $empty));
        }

        return $result;
    }

    /**
     * Format number as Vietnamese currency
     */
    private static function format_currency($amount) {
        return number_format(floatval($amount), 0, ',', '.') . 'đ';
    }

    /**
     * Tạm thời áp dụng rule demo: 1.000đ = 1 điểm.
     */
    private static function calculate_points($amount) {
        return max(0, (int) floor(floatval($amount) / 1000));
    }

    /**
     * Ghi chú ngắn, phù hợp giới hạn param template.
     */
    private static function build_points_note($raw_note, $shop_address, $site_name) {
        $raw_note = trim((string) $raw_note);
        if ($raw_note !== '') {
            if (function_exists('mb_strlen')) {
                if (mb_strlen($raw_note, 'UTF-8') <= 30) {
                    return $raw_note;
                }
            } elseif (strlen($raw_note) <= 30) {
                return $raw_note;
            }
        }

        // Keep note concise to avoid unreadable cut-off on Zalo cards.
        $note = 'Cảm ơn quý khách đã mua hàng';

        return self::truncate_text($note, 30);
    }

    private static function truncate_text($text, $limit) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }

            return mb_substr($text, 0, $limit, 'UTF-8');
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit);
    }

    /**
     * Zalo date type field requires Unix timestamp in milliseconds (integer).
     * Returns integer ms timestamp.
     */
    private static function to_zalo_date_string($value) {
        if (is_numeric($value)) {
            $timestamp = (float) $value;
            if ($timestamp <= 0) {
                return current_time('H:i:s d/m/Y');
            }

            // Milliseconds -> seconds
            if ($timestamp >= 1000000000000) {
                $timestamp = $timestamp / 1000;
            }

            return wp_date('H:i:s d/m/Y', intval(round($timestamp)));
        }

        $parsed = strtotime((string) $value);
        if ($parsed && $parsed > 0) {
            return wp_date('H:i:s d/m/Y', intval($parsed));
        }

        return current_time('H:i:s d/m/Y');
    }

    /**
     * Build invoice lookup URL that can auto-fill invoice code on public page.
     */
    private static function build_order_lookup_url($blog_id, $order_code) {
        $blog_id = intval($blog_id);
        $order_code = trim((string) $order_code);

        if ($order_code === '') {
            return '';
        }

        return add_query_arg([
            'blog_id'    => $blog_id,
            'order_code' => $order_code,
        ], home_url('/tra-cuu-hoa-don-dien-tu/'));
    }

    /**
     * Default field mapping for sale_completed event.
     */
    private static function get_default_sale_field_mapping() {
        return [
            'customer_name'  => 'customer_name',
            'customer_code'  => 'customer_code',
            'order_code'     => 'order_code',
            'order_date'     => 'order_date',
            'price'          => 'price',
            'point'          => 'point',
            'total_point'    => 'total_point',
            'note'           => 'note',
            'blog_id'        => 'blog_id',
            'order_code_url' => 'order_code_url',
        ];
    }

    /**
     * Merge missing default keys into existing mapping.
     */
    private static function merge_default_sale_field_mapping($raw_mapping) {
        $mapping = json_decode((string) $raw_mapping, true);
        if (!is_array($mapping)) {
            $mapping = [];
        }

        foreach (self::get_default_sale_field_mapping() as $zalo_param => $data_key) {
            if (!isset($mapping[$zalo_param])) {
                $mapping[$zalo_param] = $data_key;
            }
        }

        return wp_json_encode($mapping, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Kiểm tra blog_id có trong phạm vi của một template cụ thể.
     * - Nếu template.enabled_blog_ids có dữ liệu → chỉ gửi nếu blog_id nằm trong danh sách.
     * - Nếu null/rỗng → fallback về cấu hình toàn cục (tgs_zalo_enabled_blog_ids).
     */
    private static function is_blog_enabled_for_template($blog_id, $template) {
        $blog_id = intval($blog_id);
        $template_ids = [];

        if (!empty($template->enabled_blog_ids)) {
            $decoded = json_decode($template->enabled_blog_ids, true);
            if (is_array($decoded) && !empty($decoded)) {
                $template_ids = array_values(array_unique(array_filter(array_map('intval', $decoded))));
            }
        }

        if (!empty($template_ids)) {
            return in_array($blog_id, $template_ids, true);
        }

        // Fallback: kiểm tra cấu hình toàn cục
        return self::is_blog_enabled($blog_id);
    }

    private static function is_blog_enabled($blog_id) {
        $enabled_blog_ids = get_site_option('tgs_zalo_enabled_blog_ids', []);

        if (!is_array($enabled_blog_ids)) {
            $enabled_blog_ids = [];
        }

        $enabled_blog_ids = array_values(array_unique(array_filter(array_map('intval', $enabled_blog_ids), function($site_blog_id) {
            return $site_blog_id > 0;
        })));

        if (empty($enabled_blog_ids)) {
            return false;
        }

        return in_array(intval($blog_id), $enabled_blog_ids, true);
    }

    /**
     * Resolve current wallet points from wp_wallet.balance by phone/wp_user_id.
     */
    private static function get_current_wallet_points($phone, $wp_user_id = 0) {
        global $wpdb;

        $resolved_user_id = intval($wp_user_id);
        if ($resolved_user_id <= 0) {
            $resolved_user_id = self::find_wp_user_id_by_phone($phone);
        }

        if ($resolved_user_id <= 0) {
            return 0;
        }

        $wallet_table = $wpdb->base_prefix . 'wallet';
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM {$wallet_table} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $resolved_user_id
        ));

        if ($balance === null) {
            return 0;
        }

        return intval(round(floatval($balance)));
    }

    /**
     * Find WP user ID by phone variants.
     */
    private static function find_wp_user_id_by_phone($phone) {
        global $wpdb;

        $variants = self::build_phone_variants($phone);
        if (empty($variants)) {
            return 0;
        }

        $usermeta_table = $wpdb->base_prefix . 'usermeta';
        $users_table = $wpdb->base_prefix . 'users';
        $placeholders = implode(',', array_fill(0, count($variants), '%s'));

        $meta_sql = "SELECT user_id
                     FROM {$usermeta_table}
                     WHERE meta_key = 'billing_phone'
                       AND meta_value IN ({$placeholders})
                     LIMIT 1";
        $meta_query = $wpdb->prepare($meta_sql, $variants);
        $meta_user_id = intval($wpdb->get_var($meta_query));
        if ($meta_user_id > 0) {
            return $meta_user_id;
        }

        $user_sql = "SELECT ID
                     FROM {$users_table}
                     WHERE user_login IN ({$placeholders})
                     LIMIT 1";
        $user_query = $wpdb->prepare($user_sql, $variants);
        $user_id = intval($wpdb->get_var($user_query));

        return $user_id > 0 ? $user_id : 0;
    }

    /**
     * Build phone variants for matching (0xxx, 84xxx, +84xxx).
     */
    private static function build_phone_variants($phone) {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $phone);
        $variants = [$phone];

        if (!empty($digits)) {
            $variants[] = $digits;

            if (strpos($digits, '84') === 0 && strlen($digits) > 2) {
                $variants[] = '0' . substr($digits, 2);
            }

            if (strpos($digits, '0') === 0 && strlen($digits) > 1) {
                $variants[] = '84' . substr($digits, 1);
                $variants[] = '+84' . substr($digits, 1);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Đảm bảo có ít nhất 1 template mặc định (chỉ seed lần đầu, KHÔNG override dữ liệu hiện có).
     * Không còn ép buộc zalo_template_id cố định nữa — admin tự quản lý qua giao diện.
     */
    public static function sync_default_sale_points_template() {
        if (!defined('TGS_TABLE_ZALO_TEMPLATES')) {
            return;
        }

        global $wpdb;

        $table = TGS_TABLE_ZALO_TEMPLATES;
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        // Đã seed rồi thì bỏ qua — không override template admin đã chỉnh
        if (get_site_option('tgs_zalo_seeded_sale_points_template', 0)) {
            return;
        }

        $has_templates = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_type = %s",
            'sale_completed'
        ));

        // Nếu đã có template thì đánh dấu đã seed và không làm gì thêm
        if ($has_templates > 0) {
            update_site_option('tgs_zalo_seeded_sale_points_template', 1);
            return;
        }

        // Seed template mặc định lần đầu (admin chưa tạo bất kỳ template nào)
        $default_mapping_json = wp_json_encode(self::get_default_sale_field_mapping(), JSON_UNESCAPED_UNICODE);
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'zalo_template_id' => '',
            'event_type'       => 'sale_completed',
            'label'            => 'Thông báo tích điểm POS (mặc định — cần cấu hình Template ID)',
            'field_mapping'    => $default_mapping_json,
            'is_active'        => 0,
            'provider'         => 'official',
            'enabled_blog_ids' => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        update_site_option('tgs_zalo_seeded_sale_points_template', 1);
    }
}
