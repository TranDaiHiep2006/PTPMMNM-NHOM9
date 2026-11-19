# WEBARCHIVE V3 — HƯỚNG DẪN DEMO & BÁO CÁO (Đề tài 16)

Tài liệu này dùng để **chuẩn bị bảo vệ đồ án**: mô tả từng bước thao tác,
kèm danh sách chức năng có thể chụp ảnh, hai tình huống demo chính, và các
sơ đồ nên đưa vào báo cáo Word.

---

## 0. Chuẩn bị trước khi demo (10 phút)

### 0.1. Khởi động môi trường

```powershell
# (1) MySQL phải đang chạy ở 127.0.0.1:3306 với database `webarchive`
# (2) Cấu hình kết nối (KHÔNG commit file này lên GitHub)
#     config/db.local.php đã có sẵn sau khi chạy setup_db.php
# (3) Chạy máy chủ web
cd D:\PTPMMNM-NHOM9
php -S 127.0.0.1:8080 -t public
```

Mở trình duyệt: <http://127.0.0.1:8080/index.php>

### 0.2. Tài khoản demo

| Vai trò | Email | Mật khẩu | Dùng để demo |
|---------|-------|----------|---------------|
| Chủ sở hữu | `user@demo.vn` | `user123` | Thư viện, lưu trang, đọc offline |
| Quản trị | `admin@demo.vn` | `admin123` | Trang `/admin/`, danh sách tin cậy |
| Biên tập viên / Người xem | tạo trong lúc demo | — | Phân quyền owner / editor / viewer |

### 0.3. Kiểm tra nhanh trước khi vào phòng thi

```powershell
php scripts/run_tests.php           # phải in "6/6 bài kiểm thử PASS"
php scripts/test_auto_version.php   # phải in "19 mục — PASS: 19 — FAIL: 0"
```

Nếu cả hai lệnh in PASS → hệ thống sẵn sàng trình diễn.

---

## 1. Danh sách chức năng có thể chụp ảnh (theo thứ tự trình bày)

| # | Chức năng | Trang | Ảnh cần chụp | Ghi chú |
|---|-----------|-------|---------------|---------|
| 1 | Bảng điều khiển (Dashboard) | `index.php` | Toàn màn hình | Thẻ số liệu: số thư viện, số bài, số phiên bản |
| 2 | Danh sách thư viện | `libraries.php` | Toàn màn hình | Nút bật/tắt **Tự động cập nhật** (Auto Version) |
| 3 | Thư viện chi tiết | `library.php?slug=...` | Toàn màn hình | Nhãn nội dung, nút "Bản đọc ngoại tuyến sẵn sàng" |
| 4 | Lưu trang (URL công khai) | `save.php` | Form + thông báo | Nhập URL → chọn thư viện → Lưu |
| 5 | **Cảnh báo trùng lặp (F12)** | `save.php` | Hộp thoại cảnh báo | Lưu lại 1 URL đã có → hộp "Trùng lặp" hiện ra |
| 6 | Lưu hàng loạt (F1) | `bulk_save.php` | Form + kết quả | Dán nhiều URL hoặc file TXT/CSV |
| 7 | Tìm kiếm toàn văn | `search.php` | Kết quả + `<mark>` | Truy vấn chỉ có trong **thân bài** |
| 8 | Đọc offline (Reader) | `reader.php?id=...` | Nội dung bài | Đường dẫn ảnh cục bộ, màu nền, nhãn |
| 9 | **Bôi đen nhiều đoạn (F4)** | `reader.php` | 2 ảnh: trước / sau | Bôi A → lưu; bôi B → lữu; reload → cả hai còn |
| 10 | **Theo dõi đoạn (F10)** | `reader.php` → `watches.php` | Hộp thoại + danh sách | Bôi C → theo dõi; lấy bản mới → báo "đã thay đổi" |
| 11 | **Ghi chú (F4)** | `reader.php` | Ghi chú trên bản V1 | Ghi chú vẫn còn khi lên bản V2 |
| 12 | **Phiên bản V1/V2/V3** | `reader.php` → `compare.php` | So sánh hai bản | Diff tô màu từng dòng |
| 13 | **Thông báo (Auto Version)** | `notifications.php` | Danh sách thông báo | Link "Xem V3" và "So sánh V2 → V3" |
| 14 | **Chrome Extension (F7)** | Popup Extension | 3 ảnh | Danh sách thư viện, tạo thư viện, lưu trang |
| 15 | **Chia sẻ snapshot (F8)** | `share_action.php` | Link chia sẻ + mở link ẩn danh | Link có hạn dùng, thu hồi được |
| 16 | **RSS/Atom (F9)** | `feeds.php` | Danh sách feed + mục | Nút "Lấy nội dung mục" |
| 17 | **Quan hệ bài viết (F11)** | `library.php` | Sơ đồ SVG quan hệ | Nút "Thêm quan hệ" |
| 18 | **Trích dẫn APA (F2)** | `reader.php` | Hộp thoại trích dẫn | Sao chép citation |
| 19 | Trạng thái đọc & Read Later (F3) | `reading_list.php` | Danh sách đọc | Nút đánh dấu "đã đọc" |
| 20 | Lịch sử xuất hiện cụm từ (F5) | `version_search.php` | Bảng lịch sử | Cụm từ xuất hiện / biến mất qua các bản |
| 21 | SHA-256 (F6) | `library.php` | Cột hash | Hash đổi khi nội dung đổi |
| 22 | Xuất bản lưu (HTML) | `export.php` | File tải về | Người không có quyền → 403 |
| 23 | Chính sách URL / chống SSRF | `save.php` | Thông báo bị chặn | Nhập `http://169.254.169.254/...` → bị chặn |
| 24 | Phần quản trị | `admin/index.php` | Toàn màn hình | Tổng quan + tên miền tin cậy |

---

## 2. Tình huống demo 1 — "Lưu trang, đọc offline, bản mới có thông báo"

> **Mục tiêu**: chứng minh được vòng đời đầy đủ
> *lưu → đọc offline → có bản mới → có thông báo → so sánh hai bản*.

**Thời lượng: ~4 phút.**

| Bước | Thao tác | Điều cần nói |
|------|----------|---------------|
| 1 | Đăng nhập `user@demo.vn` | "Tài khoản dùng thử có sẵn, không cần đăng ký" |
| 2 | Mở `libraries.php`, bật **Tự động cập nhật** cho một thư viện | "Đây là consent gate: **chỉ khi bật**, hệ thống mới kiểm tra nguồn. Tắt là không có request nào gửi đi — test A1b đo được 0 request." |
| 3 | Lưu một URL bài báo thật (ví dụ `https://vi.wikipedia.org/wiki/C%2B%2B`) | "Chỉnh sách URL chặn mạng riêng, cloud metadata, scheme lạ. Chỉ URL công khai http/https mới lưu được." |
| 4 | Mở `reader.php` | "Nội dung đã được tách, ảnh tải về máy chủ, đọc được khi nguồn tắt — đây là bản chất của web archive." |
| 5 | Bôi đen một đoạn → **Lưu highlight** | "F4: mỗi đoạn độc lập, gắn với đúng snapshot." |
| 6 | Bôi đen đoạn khác → **Theo dõi đoạn này** | "F10: hệ thống lưu trích dẫn + vị trí đoạn để đối chiếu giữa các bản." |
| 7 | Bấm **Lấy phiên bản mới** (nếu nguồn đã đổi) | "Tạo V2, **giữ nguyên V1**. Ghi chú của V1 vẫn còn." |
| 8 | Mở `notifications.php` | "Hệ thống tự sinh thông báo kèm **tóm tắt ngắn** nội dung đã thay đổi, kèm link 'Xem V2' và 'So sánh V1 → V2'." |
| 9 | Bấm **So sánh V1 → V2** | "Diff từng dòng, tô màu thêm/bớt." |
| 10 | Mở `watches.php` | "Đoạn đã theo dõi được đối chiếu với bản mới: trạng thái `ok` / `changed` / `unknown`." |

**Điểm cần nói rõ trước giảng viên:**

- Auto Version **không chạy ngầm**: không có Task Scheduler, không có cron, không có
  tiến trình CMD tự khởi động. Chỉ chạy khi người dùng đã bật công tắc và
  chủ động bấm kiểm tra.
- Bản cũ **không bao giờ bị xoá** — mỗi lần lấy bản mới là một phiên bản mới.

---

## 3. Tình huống demo 2 — "Trùng lặp, phân quyền, chống truy cập trái phép"

> **Mục tiêu**: chứng minh được F12 (trùng lặp), F8 (chia sẻ có hạn dùng),
> phân quyền owner/editor/viewer và chống SSRF.

**Thời lượng: ~4 phút.**

| Bước | Thao tác | Điều cần nói |
|------|----------|---------------|
| 1 | Lưu lại **cùng một URL** đã lưu ở demo 1 | "F12 phát hiện trùng theo **URL chuẩn hoá**, theo **SHA-256** của nội dung, và theo **độ tương đồng nội dung** theo ngưỡng đã công bố." |
| 2 | Xem hộp thoại trùng lặp | "Hộp này chỉ hiện **sau khi server thực sự kiểm tra** — không phải cảnh báo vô tội vạ." |
| 3 | Bấm **KHÔNG LƯU** | "Chặn trùng lặp." |
| 4 | Lưu lại lần nữa → **VẪN LƯU** | "Đây là cờ xác nhận **tích luỹ**: với trang vừa có cả cảnh báo rủi ro lẫn trùng lặp, hai cờ độc lập, không gây vòng lặp HTTP 409." |
| 5 | Mở `admin/index.php` bằng tài khoản thường | "Người dùng thường bị chặn 403 ở toàn bộ trang quản trị." |
| 6 | Đăng nhập `admin@demo.vn` → vào `/admin/` | "Chức năng quản trị cũ vẫn hoạt động nguyên vẹn." |
| 7 | Tạo **link chia sẻ** cho một snapshot | "F8: link có **thời hạn** và **có thể thu hồi**. Người mở link không cần đăng nhập." |
| 8 | Mở link chia sẻ ở cửa sổ ẩn danh | "Đọc được đúng nội dung chia sẻ, không đọc được phần khác." |
| 9 | **Thu hồi** link rồi mở lại | "Link chết — đúng như thiết kế." |
| 10 | Thử lưu `http://169.254.169.254/latest/meta-data/` | "Chính sách URL chặn địa chỉ cloud metadata của các nhà cung cấp đám mây — chống SSRF." |
| 11 | Thử lưu `http://192.168.1.10/x` | "Chặn toàn bộ mạng riêng (10/8, 172.16/12, 192.168/16, link-local)." |
| 12 | Thử lưu `ftp://example.com/x` | "Chỉ chấp nhận http/https." |
| 13 | Đăng nhập tài khoản **viewer** rồi mở `reader.php` của bài của người khác | "403 — không lộ dữ liệu riêng." |

---

## 4. Kịch bản dự phòng (khi mạng không ổn định lúc trình diễn)

Trình duyệt mở sẵn 4 tab:

1. `index.php` — Dashboard
2. `reader.php?id=<snapshot đã lưu>` — Reader (có highlight + watch sẵn)
3. `notifications.php` — Thông báo
4. `admin/index.php` — Quản trị

Kịch bản dự phòng thay cho bước "lấy bản mới": dùng nút **Lấy phiên bản mới**
trên bài đã chuẩn bị sẵn (nội dung nguồn đã được thay đổi trước khi demo).

**Không** demo mục sau nếu mạng công cộng bị chặn: lưu URL Wikipedia thật.
Thay bằng URL trang mẫu nội bộ (`tests/samples/`) — nội dung đã nằm trong CSDL.

---

## 5. Kiểm thử Chrome Extension thủ công (phần còn BLOCKED)

Cần máy có Chrome (>= 102). Không tự mở Chrome trên máy người dùng khi chưa được
phép — bước này là **thao tác tay** của người trình diễn.

### 5.1. Cài đặt

1. Mở `chrome://extensions`
2. Bật **Developer mode** (góc trên bên phải)
3. Chọn **Load unpacked** → chọn thư mục `D:\PTPMMNM-NHOM9\chrome-extension`
4. Kiểm tra: không có lỗi, `manifest.json` ghi "Manifest V3"

### 5.2. Lấy token

1. Vào WebArchive → trang **Kết nối Chrome Extension** (`extension.php`)
2. Chọn phạm vi: `read`, `library:create`, `page:save`
3. Bấm **Tạo token** → copy (token chỉ hiện **một lần**)

### 5.3. Kiểm tra từng lỗi đã sửa

| Mã | Kịch bản | Kết quả mong đợi |
|----|----------|-------------------|
| BUG-02 | Cài đặt → bật "Tự động lưu" → tắt → bật lại | Công tắc giữ đúng trạng thái sau khi đóng/mở lại popup |
| BUG-03 | Cài đặt → dán token → bấm "Kiểm tra kết nối" | Hiện đúng **số thư viện** có quyền ghi; nếu token sai → báo lỗi, **không** hiện danh sách rỗng |
| BUG-04 | Bấm "+" → gõ `TEST_EXTENSION` → "Tạo" | Popup **tự chọn** thư viện vừa tạo, không cần đóng/mở lại Extension |
| BUG-05 | Dán token sai/hết hạn | Thông báo lỗi rõ ràng; **không** báo "thành công" khi server lỗi |
| BUG-06 | Mở popup, **không** bấm gì | **Không** hiện hộp cảnh báo rủi ro |
| BUG-07 | Lưu lại URL đã lưu | Hộp "Trùng lặp" hiện ra, có nút KHÔNG LƯU / VẪN LƯU |
| BUG-08 | Trang vừa có cả rủi ro lẫn trùng lặp | Xác nhận lần lượt, **không** lặp vô hạn; hai cờ độc lập |
| BUG-09 | Mở `reader.php` → bôi đen đoạn → "Theo dõi đoạn này" | Đoạn vào `watches.php`; lấy bản mới → báo thay đổi |
| BUG-10 | Bôi A → lưu; bôi B → lưu; bôi C → theo dõi; bôi D → theo dõi → **reload** | Cả bốn còn nguyên; xoá B → A, C, D không ảnh hưởng |

### 5.4. Ghi lại bằng chứng

Chụp màn hình từng bước (ảnh 5.3), đặt tên theo mã lỗi
(ví dụ `bug-04-quick-create.png`). Đưa vào phụ lục báo cáo.

---

## 6. Các sơ đồ nên đưa vào báo cáo Word

| Sơ đồ | Nội dung | Nguồn trong mã |
|--------|----------|----------------|
| **Kiến trúc tổng thể** | Trình duyệt → `public/*.php` → `src/*.php` → MySQL; kèm Service Worker của Extension | `src/bootstrap.php` |
| **Luồng lưu trang** | Form → `save.php` → `wa_url_policy_inspect()` → `wa_capture_http_get()` (ghim IP) → `wa_capture_find_content_node()` → tách ảnh → SHA-256 → `snapshots` | `src/capture.php`, `src/url_policy.php` |
| **Chính sách URL (SSRF)** | Sơ đồ quyết định: scheme → host → DNS → IP công khai? → ghim IP → kiểm tra lại ở mỗi redirect | `src/url_policy.php` |
| **Vòng đời phiên bản V1/V2/V3** | Bản gốc → `refetch.php`/`auto_check.php` → snapshot mới (`version_no` tăng) → thông báo → so sánh | `src/auto_version.php`, `src/notifications.php` |
| **Mô hình phân quyền** | Owner / Editor / Viewer trên cấp thư viện; token API có scope | `src/auth.php`, `src/v3_api.php` |
| **Sơ đồ lớp CSDL** | `users`, `libraries`, `library_members`, `saved_pages`, `snapshots`, `search_terms`, `notifications`, `content_watches`, `page_relations`, `share_links`, `feed_*` | `database/schema.sql` |
| **Luồng kiểm thử tự động** | 8 bộ test → 74 mục → PASS | `docs/FINAL_TEST_REPORT.md` |
| **Kiến trúc Extension** | Popup ↔ Service Worker ↔ `public/api.php`; `chrome.storage.local` là nguồn sự thật duy nhất | `chrome-extension/background.js` |

> Sơ đồ quan hệ bài viết (F11) **đã được vẽ sẵn bằng SVG** trong `library.php` —
> chụp màn hình trực tiếp làm minh chứng, không cần vẽ lại.

---

## 7. Các phần CHƯA hoàn thiện (nói thẳng trước giảng viên)

| Hạng mục | Tình trạng | Lý do |
|----------|-----------|-------|
| Nâng cấp giao diện Admin | **Chưa làm** | Nằm ngoài phạm vi nhiệm vụ; các chức năng quản trị **cũ vẫn chạy tốt** (test S1–S3 PASS). |
| Dashboard giám sát Admin nâng cao | **Chưa làm** | Ngoài phạm vi. |
| Nhật ký quản trị nâng cao | **Chưa làm** | Bảng `admin_logs` đã có sẵn nhưng chưa có giao diện quản lý đầy đủ. |
| Kiểm thử Chrome thật tự động | **BLOCKED** | Không có Chrome điều khiển được trong môi trường; đã thay bằng kiểm tra tĩnh + hướng dẫn thủ công ở mục 5. |
| Ghi chú dạng rich-text / chú thích chéo nhiều phiên bản | **Cơ bản** | Ghi chú đã gắn với snapshot và giữ qua các bản; chưa có trải nghiệm "ghi chú theo đoạn". |
| Đồng bộ hai chiều với thư viện ngoài (Zotero, Mendeley) | **Chưa làm** | Ngoài phạm vi 12 chức năng. |

---

## 8. Danh sách lệnh dự phòng khi demo

```powershell
# Dựng lại dữ liệu mẫu (KHÔNG chạy trên database thật có dữ liệu người dùng)
php scripts/seed_samples.php

# Kiểm tra tính toàn vẹn của bản lưu
php scripts/check_db.php

# Xem thống kê nhanh
php scripts/probe_db.php
```
