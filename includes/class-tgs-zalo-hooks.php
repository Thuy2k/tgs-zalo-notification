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
    }

    /**
     * Handle sale completed event
     *
     * @param array $sale_data Sale data from tgs_sale_completed hook
     */
    public static function on_sale_completed($sale_data) {
        if (!get_site_option('tgs_zalo_enabled', 0)) {
            return;
        }

        global $wpdb;

        $phone = $sale_data['person_phone'] ?? '';
        if (empty($phone)) {
            return;
        }

        // Check if phone is valid for Zalo
        $formatted_phone = TGS_Zalo_API::format_phone($phone);
        if (empty($formatted_phone)) {
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

        // Build available data for template mapping
        $available_data = [
            'customer_name'  => $sale_data['person_name'] ?? 'Quý khách',
            'customer_phone' => $phone,
            'customer_email' => $sale_data['person_email'] ?? '',
            'customer_id'    => $sale_data['customer_id'] ?? '',
            'sale_code'      => $sale_data['sale_code'] ?? '',
            'export_code'    => $sale_data['export_code'] ?? '',
            'total_amount'       => self::format_currency($sale_data['total_amount'] ?? 0),
            'total_amount_raw'   => intval($sale_data['total_amount'] ?? 0),
            'total_items'    => $sale_data['total_items'] ?? 0,
            'discount'           => self::format_currency($sale_data['discount'] ?? 0),
            'discount_raw'       => intval($sale_data['discount'] ?? 0),
            'sale_date'      => current_time('d/m/Y H:i'),
            'shop_name'      => $site_name,
            'shop_address'   => get_option('tgs_shop_address', $site_name),
            'employee_id'    => $sale_data['employee_id'] ?? 0,
        ];

        // Find all active templates for 'sale_completed' event
        $templates = self::get_active_templates('sale_completed');

        if (empty($templates)) {
            error_log('[TGS Zalo Hooks] No active templates for sale_completed event.');
            return;
        }

        foreach ($templates as $template) {
            $field_mapping = json_decode($template->field_mapping, true) ?: [];
            $template_data = self::map_fields($field_mapping, $available_data);

            if (empty($template_data)) {
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

            TGS_Zalo_Queue::enqueue([
                'blog_id'          => $blog_id,
                'phone'            => $phone,
                'template_id'      => $template->id,
                'zalo_template_id' => $template->zalo_template_id,
                'template_data'    => $template_data,
                'tracking_id'      => $tracking_id,
            ]);
        }
    }

    /**
     * Handle ledger status change (for manual approval of non-POS sales)
     *
     * @param int $ledger_id
     * @param int $old_status
     * @param int $new_status
     */
    public static function on_ledger_status_changed($ledger_id, $old_status, $new_status) {
        if (!get_site_option('tgs_zalo_enabled', 0)) {
            return;
        }

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

        $blog_id = get_current_blog_id();
        $site_name = get_bloginfo('name');

        $available_data = [
            'customer_name'  => $person->local_ledger_person_name ?? 'Quý khách',
            'customer_phone' => $person->local_ledger_person_phone,
            'customer_id'    => $person_id,
            'sale_code'      => $ledger->local_ledger_code ?? '',
            'total_amount'       => self::format_currency(floatval($ledger->local_ledger_total_amount ?? 0)),
            'total_amount_raw'   => intval($ledger->local_ledger_total_amount ?? 0),
            'sale_date'      => current_time('d/m/Y H:i'),
            'shop_name'      => $site_name,
            'shop_address'   => get_option('tgs_shop_address', $site_name),
        ];

        $templates = self::get_active_templates('sale_completed');

        foreach ($templates as $template) {
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

            TGS_Zalo_Queue::enqueue([
                'blog_id'          => $blog_id,
                'phone'            => $person->local_ledger_person_phone,
                'template_id'      => $template->id,
                'zalo_template_id' => $template->zalo_template_id,
                'template_data'    => $template_data,
                'tracking_id'      => $tracking_id,
            ]);
        }
    }

    /**
     * Get active templates for an event type
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
            }
        }

        return $result;
    }

    /**
     * Format number as Vietnamese currency
     */
    private static function format_currency($amount) {
        return number_format(floatval($amount), 0, ',', '.') . 'đ';
    }
}
