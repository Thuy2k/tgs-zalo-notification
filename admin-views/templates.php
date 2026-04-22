<?php
/**
 * Template Management Page - Map Zalo ZNS templates to events
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_network')) {
    echo '<div class="alert alert-danger">Bạn cần quyền quản trị mạng để truy cập trang này.</div>';
    return;
}

global $wpdb;
$table = TGS_TABLE_ZALO_TEMPLATES;
$templates = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

// Available event types
$event_types = [
    'sale_completed' => 'Bán hàng thành công (POS / Web)',
];

// Available data fields for mapping
$available_fields = [
    'customer_name'    => 'Tên khách hàng',
    'customer_phone'   => 'Số điện thoại',
    'customer_email'   => 'Email',
    'customer_id'      => 'Mã khách hàng',
    'customer_code'    => 'Mã khách hàng dùng cho template tích điểm',
    'sale_code'        => 'Mã đơn hàng',
    'order_code'       => 'Mã đơn hàng cho template Zalo',
    'order_date'       => 'Ngày bán (dd/mm/yyyy HH:mm) cho template Zalo',
    'export_code'      => 'Mã phiếu xuất',
    'price'            => 'Giá trị đơn hàng cuối cùng (số thuần) — dùng cho number',
    'point'            => 'Điểm thưởng gia tăng (số thuần)',
    'total_point'      => 'Tổng điểm hiện tại (đang để 0 cho demo)',
    'note'             => 'Ghi chú thân thiện cho khách',
    'total_amount'     => 'Tổng tiền (có định dạng VD: 1.500.000đ) — dùng cho string',
    'total_amount_raw' => 'Tổng tiền (số thuần VD: 1500000) — dùng cho number',
    'total_items'      => 'Số lượng sản phẩm',
    'discount'         => 'Giảm giá (có định dạng) — dùng cho string',
    'discount_raw'     => 'Giảm giá (số thuần) — dùng cho number',
    'sale_date'        => 'Ngày bán (dd/mm/yyyy HH:mm)',
    'shop_name'        => 'Tên cửa hàng',
    'shop_address'     => 'Địa chỉ cửa hàng',
];
?>

<div class="tgs-zalo-wrap">

    <p>Cấu hình mapping giữa template Zalo ZNS và các sự kiện trong hệ thống. Mỗi template cần được đăng ký và duyệt trước trên
        <a href="https://account.zalo.cloud/QBSPortal/zns/template/manage" target="_blank">Zalo Business Portal</a>.
    </p>

    <div id="tgsZaloNotice" class="notice" style="display:none;"><p></p></div>

    <!-- Add/Edit Template Form -->
    <div class="tgs-zalo-section">
        <h2 id="formTitle">Thêm Template mới</h2>
        <form id="tgsZaloTemplateForm">
            <input type="hidden" name="template_id" id="editTemplateId" value="0">
            <table class="form-table">
                <tr>
                    <th>Tên gợi nhớ</th>
                    <td><input type="text" name="label" id="templateLabel" class="regular-text" placeholder="VD: Cảm ơn mua hàng" required></td>
                </tr>
                <tr>
                    <th>Sự kiện</th>
                    <td>
                        <select name="event_type" id="templateEventType" required>
                            <option value="">-- Chọn sự kiện --</option>
                            <?php foreach ($event_types as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Zalo Template ID</th>
                    <td>
                        <input type="text" name="zalo_template_id" id="templateZaloId" class="regular-text" placeholder="ID từ Zalo Business Portal" required>
                        <p class="description">Lấy từ trang quản lý template ZNS trên Zalo.</p>
                    </td>
                </tr>
                <tr>
                    <th>Field Mapping (JSON)</th>
                    <td>
                        <textarea name="field_mapping" id="templateFieldMapping" rows="8" class="large-text code" placeholder='{"tên_param_zalo": "data_key_hệ_thống"}'>{}</textarea>
                        <p class="description">
                            Map tham số template Zalo với dữ liệu hệ thống. Ví dụ:<br>
                            <code>{"customer_name": "customer_name", "order_code": "sale_code", "total": "total_amount_raw", "date": "sale_date"}</code><br>
                            Hỗ trợ giá trị tĩnh: <code>{"status": "static:Đã thanh toán"}</code>
                        </p>
                        <details>
                            <summary><strong>Danh sách data keys có sẵn</strong></summary>
                            <table class="widefat" style="max-width: 500px; margin-top: 10px;">
                                <thead><tr><th>Key</th><th>Mô tả</th></tr></thead>
                                <tbody>
                                    <?php foreach ($available_fields as $key => $desc): ?>
                                        <tr>
                                            <td><code><?php echo esc_html($key); ?></code></td>
                                            <td><?php echo esc_html($desc); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </td>
                </tr>
                <tr>
                    <th>Trạng thái</th>
                    <td>
                        <label><input type="checkbox" name="is_active" id="templateIsActive" value="1" checked> Kích hoạt</label>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Lưu Template</button>
                <button type="button" class="button" id="btnCancelEdit" style="display:none;">Hủy chỉnh sửa</button>
            </p>
        </form>
    </div>

    <!-- Template List -->
    <div class="tgs-zalo-section">
        <h2>Danh sách Template</h2>
        <?php if (empty($templates)): ?>
            <p>Chưa có template nào. Hãy thêm template đầu tiên ở trên.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên</th>
                        <th>Sự kiện</th>
                        <th>Zalo Template ID</th>
                        <th>Mapping</th>
                        <th>Trạng thái</th>
                        <th>Ngày tạo</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                        <tr id="tpl-row-<?php echo intval($tpl->id); ?>">
                            <td><?php echo intval($tpl->id); ?></td>
                            <td><strong><?php echo esc_html($tpl->label); ?></strong></td>
                            <td>
                                <code><?php echo esc_html($tpl->event_type); ?></code>
                                <br><small><?php echo esc_html($event_types[$tpl->event_type] ?? ''); ?></small>
                            </td>
                            <td><code><?php echo esc_html($tpl->zalo_template_id); ?></code></td>
                            <td>
                                <details>
                                    <summary>Xem mapping</summary>
                                    <pre style="max-width:300px;overflow:auto;font-size:11px;"><?php echo esc_html($tpl->field_mapping); ?></pre>
                                </details>
                            </td>
                            <td>
                                <?php if ($tpl->is_active): ?>
                                    <span class="tgs-badge tgs-badge-success">Hoạt động</span>
                                <?php else: ?>
                                    <span class="tgs-badge tgs-badge-secondary">Tắt</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($tpl->created_at); ?></td>
                            <td>
                                <button type="button" class="button button-small btn-edit-template"
                                    data-id="<?php echo intval($tpl->id); ?>"
                                    data-label="<?php echo esc_attr($tpl->label); ?>"
                                    data-event="<?php echo esc_attr($tpl->event_type); ?>"
                                    data-zalo-id="<?php echo esc_attr($tpl->zalo_template_id); ?>"
                                    data-mapping="<?php echo esc_attr($tpl->field_mapping); ?>"
                                    data-active="<?php echo intval($tpl->is_active); ?>">
                                    Sửa
                                </button>
                                <button type="button" class="button button-small btn-toggle-template"
                                    data-id="<?php echo intval($tpl->id); ?>">
                                    <?php echo $tpl->is_active ? 'Tắt' : 'Bật'; ?>
                                </button>
                                <button type="button" class="button button-small button-link-delete btn-delete-template"
                                    data-id="<?php echo intval($tpl->id); ?>">
                                    Xóa
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
