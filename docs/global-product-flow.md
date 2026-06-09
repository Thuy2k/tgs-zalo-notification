# Luồng sản phẩm global cho TGS Zalo Notification

Tài liệu này ghi lại kết quả rà soát plugin `tgs-zalo-notification` theo chuẩn sản phẩm global.

## Kết luận hiện tại

- Plugin gửi thông báo Zalo/ZNS dựa trên sự kiện bán hàng và duyệt phiếu.
- Plugin không query bảng sản phẩm local `local_product_name`.
- Plugin không đọc `local_ledger_item` để lấy tên/SKU/barcode/đơn vị sản phẩm.
- Trường `total_items` trong template chỉ là số lượng tổng hợp nhận từ hook `tgs_sale_completed`.
- Vì không có catalog sản phẩm trong plugin nên hiện chưa cần helper hydrate sản phẩm.

## Luồng dữ liệu chính

`includes/class-tgs-zalo-hooks.php`

- `on_sale_completed($sale_data)`
  - Nhận payload từ hook `tgs_sale_completed` của `tgs_shop_management`.
  - Dùng thông tin khách hàng, mã đơn, tổng tiền, điểm thưởng, cửa hàng.
  - Không tự truy vấn sản phẩm.

- `on_ledger_status_changed($ledger_id, $old_status, $new_status)`
  - Chỉ xử lý phiếu bán được duyệt.
  - Đọc header ledger và khách hàng để tạo thông báo.
  - Không đọc dòng sản phẩm.

`includes/class-tgs-zalo-queue.php`

- Chỉ lưu và gửi payload template đã map sẵn.
- Không đọc sản phẩm.

`admin-views/zalo-oa.php` và `admin-views/templates.php`

- Chỉ khai báo danh sách field cho template Zalo.
- `total_items` là field tổng hợp, không phải catalog sản phẩm.

## Quy tắc nếu phát triển thêm

- Không thêm `FROM local_product_name` hoặc `JOIN local_product_name`.
- Không lấy tên/SKU/barcode/đơn vị từ bảng sản phẩm local.
- Nếu template Zalo cần danh sách sản phẩm, lấy dòng nghiệp vụ từ nguồn chứng từ phù hợp rồi hydrate catalog qua `TGS_Global_Product_Source`.
- Nếu cần gọi API/tìm kiếm sản phẩm, đọc tài liệu chuẩn:

```text
wp-content/plugins/tgs_shop_management/docs/global-product-api.md
```

- Các khóa legacy như `local_product_name_id` hoặc `local_product_sku`, nếu xuất hiện trong dữ liệu sale/ledger cũ, phải hiểu là alias của `global_product_name_id` và `global_product_sku`.

## Checklist rà soát

- `local_product_name`: không dùng.
- `local_ledger_item`: không dùng trong plugin này.
- `global_product_*`: chưa cần dùng vì plugin không gửi chi tiết sản phẩm.
- `total_items`: nhận từ sale hook, dùng để map template Zalo.
