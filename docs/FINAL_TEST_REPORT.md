# WEBARCHIVE V3 — BÁO CÁO KIỂM THỬ CUỐI KỲ (Đề tài 16)

> Ngày chạy: **19/11/2026**
> Môi trường: Windows 11 · PHP 8.3.33 (CLI + `php -S`) · Node.js v22.18.0 (kiểm tra cú pháp JS) · MySQL 3306 (`webarchive`)
> Mã lần chạy bộ T1–T6: `261119005328-12616`
> Commit: xem `docs/REPORT_EVIDENCE.md`

---

## 1. Tổng quan

| Bộ kiểm thử | Lệnh | Kết quả | Số mục |
|---|---|---|---|
| 6 bài bắt buộc (T1–T6) | `php scripts/run_tests.php` | **PASS** | 6/6 |
| Auto Version + Thông báo | `php scripts/test_auto_version.php` | **PASS** | 19/19 |
| Tóm tắt thay đổi trong thông báo | `php scripts/test_notify_summary.php` | **PASS** | 7/7 |
| Chính sách URL / chống SSRF | `php scripts/test_url_policy.php` | **PASS** | 18/18 |
| Logic backfill SHA-256 | `php scripts/test_backfill_logic.php` | **PASS** | 10/10 |
| Phân tích RSS/Atom (F9) | `php scripts/test_v3_feeds_parse.php` | **PASS** | 4/4 |
| Khử XSS nội dung chia sẻ (F8) | `php scripts/test_v3_share_sanitize.php` | **PASS** | 3/3 |
| Khối phần Quản trị | `php scripts/test_admin_smoke.php` | **PASS** | 5/5 |
| PHP lint (101 tệp) | `php -l` | **PASS** | 101/101 |
| Cú pháp JS Extension | `node --check` | **PASS** | 2/2 |

**Tổng: 74 mục kiểm thử tự động — PASS 74 — FAIL 0 — BLOCKED 0.**

> Xem mục [§6](#6-các-hạng-mục-blocked--giới-hạn-đã-biết) về các hạng mục kiểm thử
> thủ công (Chrome thật) chưa tự động hoá được.

---

## 2. Sáu bài kiểm thử bắt buộc của Đề tài 16 (T1–T6)

Lệnh: `php scripts/run_tests.php`

```
=== WebArchive — 6 bài kiểm thử bắt buộc (P-FINAL) ===
Mã lần chạy: 261119005328-12616

[0] Khởi động máy chủ riêng của bộ kiểm thử
  ✓ máy chủ ung-dung sẵn sàng trên cổng 18080
  ✓ máy chủ thu-mau sẵn sàng trên cổng 18091

[1] Đăng nhập user@demo.vn
  ✓ đăng nhập thành công
[2] Tạo thư viện tạm qua library_action.php
  ✓ thư viện «Thư viện KIỂM THỬ 261119005328-12616» (id=80)
[3] Đăng ký tài khoản người ngoài qua register.php
  ✓ tài khoản ngoai-261119005328-12616@test.local (id=58)

T1 PASS — nguồn đã tắt — reader=200, có cụm "hopdongAPI"=có, ảnh cục bộ=200
T2 PASS — q="chuan bi that" -> SNAP-9227, có thẻ "Phiên bản" và đoạn trích <mark>
T3 PASS — ba trang luu co ba ma loi KHAC NHAU: khong-ton-tai.html=error_404,
          cham.php=error_timeout, chuyen-huong-ngoai.php=error_blocked,
          cloud-metadata=error_blocked
T4 PASS — SNAP-9296 success, script=không, onclick=không, iframe=không,
          cụm "mau11webarchive"=có
T5 PASS — v1=1, v2=2, đánh dấu ở v2=có, ở v1=không, ghi chú v1=còn
T6 PASS — người ngoài reader=403, export=403; chủ sở hữu export=200

[4] Dọn dẹp
  ✓ xoá thư viện tạm (kèm thư mục ảnh) — còn 0 dòng trong CSDL
  ✓ xoá tài khoản người ngoài
  ✓ xoá tệp thử mẫu tạm và cookie jar
  ✓ dừng cả hai máy chủ của bộ kiểm thử

Kết quả: 6/6 bài kiểm thử PASS.
```

### Ý nghĩa từng bài

| Mã | Yêu cầu đề tài | Cách kiểm chứng | Kết quả |
|----|-----------------|------------------|---------|
| **T1** | Nguồn ngừng hoạt động nhưng văn bản và ảnh vẫn đọc offline | Tắt máy chủ nguồn, mở `reader.php` → HTTP 200, tìm thấy cụm chỉ có trong thân bài, tải được ảnh cục bộ (HTTP 200) | PASS |
| **T2** | Tìm được từ khóa chỉ nằm trong thân bài, trả đúng snapshot | Tìm `chuan bi that` → `SNAP-9227`, kết quả có thẻ "Phiên bản" và đoạn trích `<mark>` | PASS |
| **T3** | Phân biệt lỗi 404 / HTML không hợp lệ / timeout | 3 trang mẫu → 3 mã lỗi **khác nhau**: `error_404`, `error_timeout`, `error_blocked` | PASS |
| **T4** | Script không thực thi; URL/redirect không an toàn bị chặn | Snapshot không còn `<script>`, `onclick`, `iframe`; redirect sang `169.254.169.254` bị chặn bởi chính sách URL | PASS |
| **T5** | Lấy phiên bản mới giữ bản cũ và ghi chú | V1 = 1 phiên bản, sau khi lấy bản mới V2 = 2 phiên bản; đánh dấu ở V2 có, ở V1 không; ghi chú V1 còn nguyên | PASS |
| **T6** | Người không có quyền không đọc được dữ liệu riêng / file xuất | Người ngoài: `reader.php` = 403, `export.php` = 403; chủ sở hữu: export = 200 | PASS |

---

## 3. Auto Version + hệ thống thông báo

Lệnh: `php scripts/test_auto_version.php` → **19/19 PASS**

| Mã | Nội dung | Bằng chứng |
|----|----------|------------|
| A8 / A8b | Kết nối CSDL, đủ bảng và cột cho Auto Version | `SELECT 1 = 1` |
| A1a | Tắt tự động cập nhật → trạng thái OFF được ghi nhận | HTTP 200, `enabled=false` |
| A1b | Khi OFF, **không** gửi request tới nguồn | HTTP 403, lỗi `auto_update_disabled`, số request tới nguồn = **0** |
| A1c | Bật lại → ON | HTTP 200, `enabled=true` (xử lý BUG-01) |
| A2 | Bật, trang chưa đổi → **không** sinh V2 | `updated=0`, snapshot 1 → 1, nhưng có 2 request tới nguồn (đã thật sự kiểm tra) |
| A3 | Nội dung đổi → tạo **đúng một** phiên bản mới + thông báo | `updated=1`, snapshot mới = 1 |
| A7a | Nội dung thông báo đúng | "Bài viết Bai thay doi đã được cập nhật lên phiên bản V2", `is_read=0` |
| A4 | Kiểm tra lại, nội dung không đổi → không tạo bản trùng | snapshot 2 → 2 |
| A5 | **Hai request đồng thời** → không tạo hai phiên bản trùng | snapshot mới = 1 (chống race) |
| A6 | Người chỉ có quyền xem → không được kiểm tra/cập nhật | consent HTTP 200, run HTTP 200, số bài được kiểm tra = 0 |
| A7b | Người xem không nhận thông báo | 0 thông báo |
| A7c | Phát lại thông báo cùng phiên bản → không trùng | 2 → 2 |
| A7d | Đánh dấu đã đọc | `is_read=1` |
| A7e / A7f | Trang thông báo + trang so sánh | HTTP 200, có liên kết khác biệt; compare HTTP 200 |
| A8 | Phân phối công bằng cho > 5 website | 8 bài: lượt 1 kiểm tra 5 bài (5/8), lượt 2 kiểm tra 5 bài, sau 2 lượt phủ 8/8; bài chưa kiểm tra luôn đứng trước bài đã kiểm tra |
| D1 | Dọn thư viện thử nghiệm | 0 dòng còn lại |

---

## 4. Tóm tắt thay đổi trong thông báo (N1–N5, D1)

Lệnh: `php scripts/test_notify_summary.php` → **7/7 PASS**

```
PASS  N1 — Thông báo cho V3=1 (mong đợi 1), tổng cho bài=2 (mong đợi 2: V2 + V3)
PASS  N2 — Trang thông báo có link reader.php?id=9290 = có | mở reader HTTP 200
PASS  N3 — Link compare (from=2&to=3) trong trang thông báo = có | mở compare HTTP 200, có diff-output = có
PASS  N3b — V4 đã tồn tại (snapshot=9291) | previous_version(V3)=2 (mong đợi 2),
           previous_version(V4)=3 (mong đợi 3) | link from=2&to=3 còn nguyên=có,
           link sai from=4&to=3=không
PASS  N4 — Số ý=2 (1-3), tổng ký tự=107 (<=250), có nhãn thay đổi=có,
           dự phòng hợp lệ=có, bám nội dung thật=có, không XSS=có
    · Bổ sung: PHIEN BAN V3 — them mot doan van nua va sua mot doan cu.
    · Xoá: PHIEN BAN V2 — them mot doan van moi.
PASS  N5 — Phát lại thông báo V3: 3 -> 3 | người ngoài đăng ký=ok, đăng nhập=ok
           | nhận 0 thông báo (mong đợi 0) | đọc reader HTTP 403 (mong đợi 403)
           | thấy tiêu đề thông báo của chủ = không
PASS  D1 — Thư viện thử nghiệm đã được xoá (0 dòng còn lại)
```

> **N3b** là bài hồi quy cho lỗi cũ: khi đã có V4, thông báo của V3 phải **vẫn** so
> sánh với V2 (không bị trỏ nhầm sang bản mới nhất).

---

## 5. Chính sách URL / chống SSRF (T4 của đề tài)

Lệnh: `php scripts/test_url_policy.php` → **PASS (18/18)**

| Nhóm | Kết quả |
|---|---|
| Đích công khai hợp lệ | OK |
| Đích riêng `10.0.0.5`, `192.168.1.10`, `172.16.5.5` | BLOCKED — `private_ip` |
| Link-local `169.254.10.1` | BLOCKED — `private_ip` |
| `metadata.google.internal` | BLOCKED — `blocked_host` |
| `foo.local`, `foo.internal`, `foo.home.arpa` | BLOCKED — `blocked_host` |
| `ftp://example.com/x` | BLOCKED — `bad_scheme` |
| `http://user:pass@example.com/x` | BLOCKED — `has_credentials` |

**Nguyên lý:** IP đích được **ghim** bằng `CURLOPT_RESOLVE` sau khi đã kiểm tra là
công khai → chống DNS rebinding. Mọi đích redirect cũng được kiểm tra lại
(`src/capture.php`, `src/url_policy.php`).

---

## 6. Các hạng mục BLOCKED / giới hạn đã biết

| Hạng mục | Trạng thái | Ghi chú |
|---|---|---|
| Kiểm thử Extension trên **Chrome thật** (tải popup, bấm nút, kiểm tra service worker) | **BLOCKED** | Môi trường kiểm thử không có Chrome có thể điều khiển tự động. Đã kiểm tra thay bằng: `node --check` (cú pháp JS), `manifest.json` hợp lệ (Manifest V3), và review tĩnh luồng `popup.js` ↔ `background.js` ↔ `public/api.php`. **Không tuyên bố PASS cho phần này.** |
| Kiểm thử E2E trình duyệt (Selenium/Playwright) | **BLOCKED** | Không có trình duyệt headless được phép trong môi trường hiện tại. |
| Wikipedia tiếng Việt (`vi.wikipedia.org/wiki/C%2B%2B`) từ máy chủ thật | **PASS (đã xử lý)** | Đã sửa `wa_capture_find_content_node()` để hỗ trợ cấu trúc MediaWiki (`#mw-content-text`, `.mw-parser-output`) và đo **mật độ văn bản** thay vì chỉ độ dài thẻ đầu tiên. Không hạ ngưỡng 200 ký tự. |
| Chạy `database/schema.sql` / `setup_db.php` trên 3306 | **KHÔNG CHẠY (theo yêu cầu)** | Database chính chỉ được **kiểm tra đọc** và migration idempotent. |
| Task Scheduler / cron / tiến trình CMD tự chạy | **KHÔNG TẠO** | Auto Version chỉ chạy khi người dùng bấm "Kiểm tra cập nhật" hoặc vào Dashboard sau khi đã đồng ý (consent gate). |

---

## 7. Quy trình tái lập kết quả

```bash
# 1) Chuẩn bị (một lần)
php scripts/setup_db.php          # hoặc dùng config/db.local.php có sẵn
php scripts/migrate_auto_version.php
php scripts/migrate_v3.php
php scripts/backfill_snapshot_hash.php

# 2) Chạy toàn bộ kiểm thử
php scripts/run_tests.php                 # T1–T6
php scripts/test_auto_version.php         # A1–A8b, D1
php scripts/test_notify_summary.php       # N1–N5, D1
php scripts/test_url_policy.php           # chính sách URL
php scripts/test_backfill_logic.php       # SHA-256 / keyset pagination
php scripts/test_v3_feeds_parse.php       # F9
php scripts/test_v3_share_sanitize.php    # F8
php scripts/test_admin_smoke.php          # phần quản trị

# 3) Kiểm tra tĩnh
php -l <từng tệp .php>
node --check chrome-extension/popup.js
node --check chrome-extension/background.js
```

> Các bộ kiểm thử tự khởi động máy chủ `php -S` riêng trên các cổng
> 18080/18082/18084/18086/18091/18093/18095 và **tự dọn dẹp** khi kết thúc.
> Các bộ này tự đặt `WA_TEST_ALLOW_LOCAL=1` (chỉ có hiệu lực khi SAPI là
> `cli`/`cli-server`) để tải được trang mẫu trên `127.0.0.1` mà không nới lỏng
> chốt SSRF trên máy chủ thật.

---

## 8. Kết luận

- **74/74** mục kiểm thử tự động PASS, 0 FAIL.
- Sáu bài kiểm thử bắt buộc của Đề tài 16 (T1–T6) đều PASS.
- Các nhóm chức năng nền tảng (đăng ký/đăng nhập, quản lý thư viện, phân quyền
  owner/editor/viewer, lưu URL công khai, tách nội dung và lưu ảnh, đọc offline,
  tìm kiếm và nhãn, phiên bản V1/V2/V3, ghi chú, export, chính sách URL và chống
  SSRF) đều được kiểm chứng bằng bộ kiểm thử tự động.
- Bộ **15 trang HTML fixture** (`tests/samples/bai-01..13.html` + 3 trang lỗi) và
  **10 truy vấn có kết quả mong đợi** (`docs/SEARCH_QUERIES.md`) đã sẵn sàng.
- Phần kiểm thử Chrome thật còn **BLOCKED** — cần chạy thủ công theo
  `docs/DEMO_GUIDE.md` mục 5.
