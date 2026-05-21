/**
 * TGS Zalo OA — Admin JavaScript
 * Works with Sneat Bootstrap 5 layout inside TGS Shop Management
 */
(function($) {
    'use strict';

    var ajaxUrl = tgsZaloAdmin.ajaxUrl;
    var nonce   = tgsZaloAdmin.nonce;

    function updateDeploySiteCounter() {
        var count = $('.deploy-site-checkbox:checked').length;
        $('#selectedDeploySiteCount').text(count);
    }

    function updateTemplateBlogCounter() {
        var count = $('.template-blog-checkbox:checked').length;
        $('#templateBlogCount').text(count);
    }

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

    $(document).on('change', '.deploy-site-checkbox', updateDeploySiteCounter);
    $(document).on('change', '.template-blog-checkbox', updateTemplateBlogCounter);

    $(document).on('click', '#btnSelectAllDeploySites', function() {
        $('.deploy-site-checkbox').prop('checked', true);
        updateDeploySiteCounter();
    });

    $(document).on('click', '#btnClearDeploySites', function() {
        $('.deploy-site-checkbox').prop('checked', false);
        updateDeploySiteCounter();
    });

    $(document).on('click', '#btnSelectAllTemplateSites', function() {
        $('.template-blog-checkbox').prop('checked', true);
        updateTemplateBlogCounter();
    });

    $(document).on('click', '#btnClearTemplateSites', function() {
        $('.template-blog-checkbox').prop('checked', false);
        updateTemplateBlogCounter();
    });

    updateDeploySiteCounter();
    updateTemplateBlogCounter();

    /**
     * Intermediary Settings Form
     */
    $('#tgsZaloIntermediaryForm').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('[type=submit]').prop('disabled', true);

        $.post(ajaxUrl, {
            action: 'tgs_zalo_save_intermediary_settings',
            nonce: nonce,
            intermediary_url: $form.find('[name=intermediary_url]').val(),
            intermediary_method: $form.find('[name=intermediary_method]').val(),
            intermediary_auth: $form.find('[name=intermediary_auth]').val(),
            intermediary_enabled: $form.find('[name=intermediary_enabled]').is(':checked') ? 1 : 0,
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

    var SAMPLE_OFFICIAL = {
        "customer_name":    "customer_name",
        "order_code":       "order_code",
        "blog_id":          "blog_id",
        "order_code_url":   "order_code_url",
        "amount":           "total_amount_raw",
        "date":             "sale_date",
        "status":           "static:Đã thanh toán"
    };

    var SAMPLE_INTERMEDIARY = {
        "ten_khach_hang":   "customer_name",
        "ma_khach_hang":    "customer_code",
        "don_hang":         "order_code",
        "ngay":             "sale_date_only",
        "gia_tri":          "price",
        "diem_tich_luy":    "point",
        "tong_diem":        "total_point",
        "note":             "note",
        "hoadon":           "hoadon_query"
    };

    function getSelectedProvider() {
        return $('[name=provider]:checked').val() || 'official';
    }

    function updateProviderUI() {
        var provider = getSelectedProvider();
        var $hint    = $('#providerMappingHint');
        var $sample  = $('#btnSampleMapping');

        if (provider === 'intermediary') {
            $hint
                .removeClass('alert-primary')
                .addClass('alert-warning')
                .html(
                    '<i class="bx bx-transfer me-1"></i><strong>Trung gian Yoursales:</strong> ' +
                    'Dùng <code>hoadon_query</code> cho tham số tra cứu hóa đơn (VD: <code>hoadon: "hoadon_query"</code>). ' +
                    'Không dùng <code>order_code_url</code>. ' +
                    'Dùng <code>sale_date_only</code> cho ngày (chỉ d/m/Y, không có giờ), thay vì <code>sale_date</code>.'
                )
                .show();
            $sample.text(JSON.stringify(SAMPLE_INTERMEDIARY));
        } else {
            $hint
                .removeClass('alert-warning')
                .addClass('alert-primary')
                .html(
                    '<i class="bx bxl-meta me-1"></i><strong>Zalo chính thống:</strong> ' +
                    'Dùng <code>order_code_url</code> cho button tra cứu hóa đơn. ' +
                    'Không dùng <code>hoadon_query</code>.'
                )
                .show();
            $sample.text(JSON.stringify(SAMPLE_OFFICIAL));
        }
    }

    $(document).on('change', '[name=provider]', updateProviderUI);

    /**
     * Sample Mapping — click to fill (provider-aware)
     */
    $(document).on('click', '#btnSampleMapping', function() {
        var provider = getSelectedProvider();
        var sample   = provider === 'intermediary' ? SAMPLE_INTERMEDIARY : SAMPLE_OFFICIAL;
        $('#templateFieldMapping').val(JSON.stringify(sample, null, 2));
        showNotice('Đã điền mẫu mapping ' + (provider === 'intermediary' ? 'Trung gian Yoursales' : 'Zalo chính thống') + '. Hãy chỉnh tên param cho khớp với template của bạn.', 'info');
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
        // Normalize common paste artifacts before validating
        mapping = mapping.replace(/[\u200B\u200C\u200D\uFEFF]/g, ''); // zero-width chars
        mapping = mapping.replace(/[\u201C\u201D\u201E\u201F]/g, '"'); // smart double quotes
        mapping = mapping.replace(/[\u2018\u2019\u201A\u201B]/g, "'"); // smart single quotes
        mapping = mapping.replace(/,(\s*[}\]])/g, '$1'); // trailing commas
        mapping = mapping.trim();
        if (mapping && mapping[0] !== '{' && mapping[0] !== '[') { mapping = '{' + mapping + '}'; }
        $form.find('#templateFieldMapping').val(mapping); // write back cleaned value
        try {
            JSON.parse(mapping);
        } catch(err) {
            showNotice('Field Mapping JSON không hợp lệ: ' + err.message, 'danger');
            $btn.prop('disabled', false);
            return;
        }

        var blogIds = $form.find('.template-blog-checkbox:checked').map(function() { return $(this).val(); }).get();

        $.post(ajaxUrl, {
            action: 'tgs_zalo_save_template',
            nonce: nonce,
            template_id: $form.find('#editTemplateId').val(),
            label: $form.find('#templateLabel').val(),
            event_type: $form.find('#templateEventType').val(),
            zalo_template_id: $form.find('#templateZaloId').val(),
            field_mapping: mapping,
            is_active: $form.find('#templateIsActive').is(':checked') ? 1 : 0,
            provider: $form.find('[name=provider]:checked').val() || 'official',
            'enabled_blog_ids[]': blogIds,
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
        var rawMapping = $btn.data('mapping');
        var mappingObject = {};

        if (rawMapping && typeof rawMapping === 'object') {
            mappingObject = rawMapping;
        } else if (typeof rawMapping === 'string' && rawMapping.trim() !== '') {
            try {
                mappingObject = JSON.parse(rawMapping);
            } catch (e) {
                $('#templateFieldMapping').val(rawMapping);
                mappingObject = null;
            }
        }

        if (mappingObject !== null) {
            $('#templateFieldMapping').val(JSON.stringify(mappingObject || {}, null, 2));
        }
        $('#templateIsActive').prop('checked', $btn.data('active') == 1);

        // Populate provider
        var provider = $btn.data('provider') || 'official';
        $('[name=provider][value=' + provider + ']').prop('checked', true);
        updateProviderUI();

        // Populate blog checkboxes
        var blogIds = [];
        var rawBlogIds = $btn.data('blog-ids');
        if (rawBlogIds) {
            if (Array.isArray(rawBlogIds)) {
                blogIds = rawBlogIds; // jQuery đã auto-parse JSON array
            } else {
                try { blogIds = JSON.parse(rawBlogIds); } catch(e) {}
            }
        }
        $('.template-blog-checkbox').prop('checked', false);
        if (blogIds.length) {
            $.each(blogIds, function(i, bid) {
                $('.template-blog-checkbox[value=' + bid + ']').prop('checked', true);
            });
        }
        updateTemplateBlogCounter();

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
        $('[name=provider][value=official]').prop('checked', true);
        $('.template-blog-checkbox').prop('checked', false);
        updateTemplateBlogCounter();
        updateProviderUI();
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
            templateData = templateData.replace(/[\u200B\u200C\u200D\uFEFF]/g, '');
            templateData = templateData.replace(/[\u201C\u201D\u201E\u201F]/g, '"');
            templateData = templateData.replace(/[\u2018\u2019\u201A\u201B]/g, "'");
            templateData = templateData.replace(/,(\s*[}\]])/g, '$1');
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
        var sampleCode = 'HD1_TEST001';
        var sample = {
            "customer_name": "Nguyen Van A",
            "order_code": sampleCode,
            "blog_id": "1",
            "order_code_url": window.location.origin + '/tra-cuu-hoa-don-dien-tu/?blog_id=1&order_code=' + encodeURIComponent(sampleCode),
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
                var sampleCode = 'HD1_TEST001';
                var sampleValues = {
                    'customer_name': 'Nguyen Van A',
                    'customer_phone': '0912345678',
                    'sale_code': sampleCode,
                    'order_code': sampleCode,
                    'blog_id': '1',
                    'order_code_url': window.location.origin + '/tra-cuu-hoa-don-dien-tu/?blog_id=1&order_code=' + encodeURIComponent(sampleCode),
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
