/**
 * TGS Zalo OA — Admin JavaScript
 * Works with Sneat Bootstrap 5 layout inside TGS Shop Management
 */
(function($) {
    'use strict';

    var ajaxUrl = tgsZaloAdmin.ajaxUrl;
    var nonce   = tgsZaloAdmin.nonce;

    /**
     * Show alert notice (Bootstrap style)
     */
    function showNotice(message, type) {
        type = type || 'info';
        var iconMap = {
            'success': 'bx-check-circle',
            'danger': 'bx-error-circle',
            'warning': 'bx-error',
            'info': 'bx-info-circle'
        };
        var $notice = $('#tgsZaloNotice');
        $notice.removeClass('alert-success alert-danger alert-warning alert-info d-none')
            .addClass('alert-' + type)
            .find('.notice-icon').removeClass().addClass('bx ' + (iconMap[type] || 'bx-info-circle') + ' me-2 notice-icon');
        $notice.find('.notice-text').html(message);
        $notice.removeClass('d-none').hide().slideDown(200);
        setTimeout(function() { $notice.slideUp(300); }, 6000);
    }

    /**
     * Settings Form
     */
    $('#tgsZaloSettingsForm').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('[type=submit]').prop('disabled', true);

        $.post(ajaxUrl, {
            action: 'tgs_zalo_save_settings',
            nonce: nonce,
            app_id: $form.find('[name=app_id]').val(),
            secret_key: $form.find('[name=secret_key]').val(),
            enabled: $form.find('[name=enabled]').is(':checked') ? 1 : 0,
            dev_mode: $form.find('[name=dev_mode]').is(':checked') ? 1 : 0,
            batch_size: $form.find('[name=batch_size]').val(),
            retry_max: $form.find('[name=retry_max]').val(),
        }, function(res) {
            $btn.prop('disabled', false);
            showNotice(res.success ? res.data : (res.data || 'Lỗi không xác định'), res.success ? 'success' : 'danger');
        }).fail(function() {
            $btn.prop('disabled', false);
            showNotice('Lỗi kết nối server', 'danger');
        });
    });

    /**
     * Test Connection (both buttons)
     */
    $(document).on('click', '#btnTestConnection, #btnTestConnection2', function() {
        var $btn = $(this).prop('disabled', true);
        var origHtml = $btn.html();
        $btn.html('<i class="bx bx-loader-alt bx-spin me-1"></i>Đang kiểm tra...');

        $.post(ajaxUrl, {
            action: 'tgs_zalo_test_connection',
            nonce: nonce,
        }, function(res) {
            $btn.prop('disabled', false).html(origHtml);
            if (res.success) {
                var d = res.data;
                var quotaInfo = '';
                if (d.daily_quota && d.daily_quota !== 'N/A') {
                    quotaInfo = ' — Quota: <strong>' + d.daily_quota + '</strong> | Còn: <strong>' + d.remaining_quota + '</strong>';
                } else if (d.quota_note) {
                    quotaInfo = '<br><small class="text-muted">' + d.quota_note + '</small>';
                }
                showNotice(d.message + quotaInfo, 'success');

                // Show inline result if #testResult exists
                var inlineQuota = '';
                if (d.daily_quota && d.daily_quota !== 'N/A') {
                    inlineQuota = 'Quota: ' + d.daily_quota + ' | Còn: ' + d.remaining_quota;
                } else if (d.quota_note) {
                    inlineQuota = d.quota_note;
                }
                $('#testResult').html(
                    '<div class="alert alert-success py-2 mb-0" style="font-size:12px;">' +
                    '<i class="bx bx-check-circle me-1"></i>' + d.message +
                    (inlineQuota ? '<br>' + inlineQuota : '') +
                    '</div>'
                ).show();
            } else {
                showNotice(res.data || 'Kết nối thất bại', 'danger');
                $('#testResult').html(
                    '<div class="alert alert-danger py-2 mb-0" style="font-size:12px;">' +
                    '<i class="bx bx-error me-1"></i>' + (res.data || 'Kết nối thất bại') +
                    '</div>'
                ).show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).html(origHtml);
            showNotice('Lỗi kết nối server', 'danger');
        });
    });

    /**
     * Sample Mapping — click to fill
     */
    $(document).on('click', '#btnSampleMapping', function() {
        var sample = {
            "customer_name": "customer_name",
            "order_code": "sale_code",
            "amount": "total_amount_raw",
            "date": "sale_date",
            "status": "static:Đã thanh toán"
        };
        $('#templateFieldMapping').val(JSON.stringify(sample, null, 2));
        showNotice('Đã điền mẫu mapping. Hãy chỉnh sửa tên param cho khớp với template Zalo của bạn.', 'info');
    });

    /**
     * Copy URL
     */
    $(document).on('click', '.btn-copy-url', function() {
        var url = $(this).data('url');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() {
                showNotice('Đã copy URL!', 'success');
            });
        } else {
            // Fallback
            var $temp = $('<input>').val(url).appendTo('body').select();
            document.execCommand('copy');
            $temp.remove();
            showNotice('Đã copy URL!', 'success');
        }
    });

    /**
     * Template Form
     */
    $('#tgsZaloTemplateForm').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('[type=submit]').prop('disabled', true);

        var mapping = $form.find('#templateFieldMapping').val();
        try {
            JSON.parse(mapping);
        } catch(err) {
            showNotice('Field Mapping JSON không hợp lệ: ' + err.message, 'danger');
            $btn.prop('disabled', false);
            return;
        }

        $.post(ajaxUrl, {
            action: 'tgs_zalo_save_template',
            nonce: nonce,
            template_id: $form.find('#editTemplateId').val(),
            label: $form.find('#templateLabel').val(),
            event_type: $form.find('#templateEventType').val(),
            zalo_template_id: $form.find('#templateZaloId').val(),
            field_mapping: mapping,
            is_active: $form.find('#templateIsActive').is(':checked') ? 1 : 0,
        }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                showNotice(res.data, 'success');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showNotice(res.data || 'Lỗi', 'danger');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            showNotice('Lỗi kết nối server', 'danger');
        });
    });

    /**
     * Edit Template
     */
    $(document).on('click', '.btn-edit-template', function() {
        var $btn = $(this);
        $('#editTemplateId').val($btn.data('id'));
        $('#templateLabel').val($btn.data('label'));
        $('#templateEventType').val($btn.data('event'));
        $('#templateZaloId').val($btn.data('zalo-id'));
        try {
            $('#templateFieldMapping').val(
                JSON.stringify(JSON.parse($btn.data('mapping') || '{}'), null, 2)
            );
        } catch(e) {
            $('#templateFieldMapping').val($btn.data('mapping'));
        }
        $('#templateIsActive').prop('checked', $btn.data('active') == 1);
        $('#formTitle').html('<i class="bx bx-edit me-2 text-warning"></i>Chỉnh sửa Template #' + $btn.data('id'));
        $('#btnCancelEdit').show();

        $('html, body').animate({ scrollTop: $('#formTitle').closest('.card').offset().top - 80 }, 300);
    });

    /**
     * Cancel Edit
     */
    $('#btnCancelEdit').on('click', function() {
        $('#editTemplateId').val(0);
        $('#tgsZaloTemplateForm')[0].reset();
        $('#templateFieldMapping').val('{}');
        $('#templateIsActive').prop('checked', true);
        $('#formTitle').html('<i class="bx bx-plus-circle me-2 text-success"></i>Thêm Template mới');
        $(this).hide();
    });

    /**
     * Toggle Template
     */
    $(document).on('click', '.btn-toggle-template', function() {
        var $btn = $(this).prop('disabled', true);
        $.post(ajaxUrl, {
            action: 'tgs_zalo_toggle_template',
            nonce: nonce,
            template_id: $btn.data('id'),
        }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                location.reload();
            } else {
                showNotice(res.data || 'Lỗi', 'danger');
            }
        });
    });

    /**
     * Delete Template
     */
    $(document).on('click', '.btn-delete-template', function() {
        var $btn = $(this);
        var id = $btn.data('id');

        if (!confirm('Bạn có chắc muốn xóa template #' + id + '?')) return;
        $btn.prop('disabled', true);

        $.post(ajaxUrl, {
            action: 'tgs_zalo_delete_template',
            nonce: nonce,
            template_id: id,
        }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $('#tpl-row-' + id).fadeOut(300, function() { $(this).remove(); });
                showNotice(res.data, 'success');
            } else {
                showNotice(res.data || 'Lỗi', 'danger');
            }
        });
    });

    /**
     * Retry Failed Message
     */
    $(document).on('click', '.btn-retry-message', function() {
        var $btn = $(this).prop('disabled', true);
        var id = $btn.data('id');

        $.post(ajaxUrl, {
            action: 'tgs_zalo_retry_message',
            nonce: nonce,
            message_id: id,
        }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $btn.html('<i class="bx bx-check me-1"></i>Đã reset').addClass('btn-outline-success').removeClass('btn-outline-warning');
                showNotice(res.data, 'success');
            } else {
                showNotice(res.data || 'Lỗi', 'danger');
            }
        });
    });

    /**
     * Send Test Message (Direct)
     */
    $('#tgsZaloDirectTestForm').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('#btnDirectTest').prop('disabled', true);
        var origHtml = $btn.html();
        $btn.html('<i class="bx bx-loader-alt bx-spin me-1"></i>Đang gửi...');

        var phone = $form.find('#directTestPhone').val().trim();
        var zaloTemplateId = $form.find('#directTestTemplateId').val().trim();
        var templateData = $form.find('#directTestData').val().trim();
        var configTemplateId = $form.find('#testTemplateId').val() || '0';

        if (!phone) {
            showNotice('Vui lòng nhập số điện thoại.', 'warning');
            $btn.prop('disabled', false).html(origHtml);
            return;
        }

        // Validate JSON if provided
        if (templateData) {
            try { JSON.parse(templateData); } catch(err) {
                showNotice('Template Data JSON không hợp lệ: ' + err.message, 'danger');
                $btn.prop('disabled', false).html(origHtml);
                return;
            }
        }

        $.post(ajaxUrl, {
            action: 'tgs_zalo_send_test',
            nonce: nonce,
            phone: phone,
            zalo_template_id: zaloTemplateId,
            template_data: templateData,
            config_template_id: configTemplateId,
        }, function(res) {
            $btn.prop('disabled', false).html(origHtml);
            if (res.success) {
                var d = res.data;
                showNotice(d.message, 'success');
                var html = '<div class="alert alert-success py-2 mb-0" style="font-size:12px;">' +
                    '<i class="bx bx-check-circle me-1"></i><strong>Gửi thành công!</strong><br>' +
                    '<strong>SĐT:</strong> ' + d.phone + '<br>' +
                    '<strong>Template:</strong> ' + d.template_id + '<br>' +
                    '<strong>Msg ID:</strong> <code>' + d.msg_id + '</code>';
                if (d.template_data && Object.keys(d.template_data).length) {
                    html += '<br><strong>Data gửi:</strong> <code style="font-size:11px;">' + JSON.stringify(d.template_data) + '</code>';
                }
                html += '</div>';
                $('#directTestResult').html(html).show();
            } else {
                showNotice(res.data || 'Gửi thất bại', 'danger');
                $('#directTestResult').html(
                    '<div class="alert alert-danger py-2 mb-0" style="font-size:12px;">' +
                    '<i class="bx bx-error me-1"></i>' + (res.data || 'Gửi thất bại') +
                    '</div>'
                ).show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).html(origHtml);
            showNotice('Lỗi kết nối server', 'danger');
        });
    });

    /**
     * Fill sample template data
     */
    $(document).on('click', '#btnFillSampleData', function(e) {
        e.preventDefault();
        var sample = {
            "customer_name": "Nguyen Van A",
            "order_code": "DH-TEST-001",
            "amount": 1500000,
            "date": new Date().toLocaleDateString('vi-VN') + ' ' + new Date().toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit'}),
            "status": "Đã thanh toán"
        };
        $('#directTestData').val(JSON.stringify(sample, null, 2));
        showNotice('Đã điền dữ liệu mẫu. Hãy sửa tên param cho khớp với template Zalo của bạn.', 'info');
    });

    /**
     * Auto-fill from pre-configured template selection
     */
    $(document).on('change', '#testTemplateId', function() {
        var $opt = $(this).find(':selected');
        var zaloId = $opt.data('zalo-id') || '';
        var mapping = $opt.data('mapping') || '';

        if (zaloId) {
            $('#directTestTemplateId').val(zaloId);
        }
        if (mapping) {
            try {
                var parsed = typeof mapping === 'string' ? JSON.parse(mapping) : mapping;
                // Convert mapping to sample data
                var sampleData = {};
                var sampleValues = {
                    'customer_name': 'Nguyen Van A',
                    'customer_phone': '0912345678',
                    'sale_code': 'DH-TEST-001',
                    'export_code': 'PX-TEST-001',
                    'total_amount': '1.500.000đ',
                    'total_amount_raw': 1500000,
                    'total_items': '3',
                    'discount': '0đ',
                    'discount_raw': 0,
                    'sale_date': new Date().toLocaleDateString('vi-VN'),
                    'shop_name': 'Thế Giới Sữa',
                    'shop_address': '402 Duong Chau Phong, Viet Tri, Phu Tho',
                    'customer_id': 'KH-001'
                };
                for (var key in parsed) {
                    var val = parsed[key];
                    if (val.indexOf('static:') === 0) {
                        sampleData[key] = val.substring(7);
                    } else {
                        sampleData[key] = sampleValues[val] || val;
                    }
                }
                $('#directTestData').val(JSON.stringify(sampleData, null, 2));
            } catch(e) {}
        }
    });

})(jQuery);
