# WEBARCHIVE V3 — MINH CHỨNG & BẢN ĐỒ MÃ NGUỒN (Đề tài 16)

Tài liệu này là **phụ lục minh chứng** cho báo cáo: mỗi chức năng được ánh xạ
về đúng file/hàm trong mã nguồn, kèm bài kiểm thử tương ứng. Dùng để giảng viên
đối chiếu khi hỏi "phần này nằm ở đâu?".

> Nguyên tắc: mọi dòng dưới đây đều **đã được kiểm chứng bằng bộ kiểm thử chạy
> thật** trên MySQL 3306 (`webarchive`) ngày 19/11/2026 — xem
> `docs/FINAL_TEST_REPORT.md`.

---

## 1. Ánh xạ 12 chức năng V3 → mã nguồn → bài kiểm thử

| Mã | Chức năng | File chính | Hàm / bảng | Bài kiểm thử |
|----|-----------|-------------|-------------|--------------|
| **F1** | Lưu URL hàng loạt + TXT/CSV | `public/bulk_save.php`, `src/v3_basic.php` | `wa_bulk_parse_urls()`, `wa_bulk_import_file()` | `run_tests.php` (T1) |
| **F2** | Trích dẫn APA | `public/reader.php`, `src/v3_research.php` | `wa_apa_citation()` | T5 (ghi chú giữ qua bản) |
| **F3** | Trạng thái đọc & Read Later | `public/reading_list.php`, `public/reading_action.php` | bảng `reading_states`, `reading_queue` | T1 |
| **F4** | Highlight & ghi chú | `public/reader.php`, `public/annotation_action.php` | bảng `annotations`, `notes` | `test_v3_research.php` (F4, 4 highlight độc lập) |
| **F5** | Lịch sử xuất hiện / biến mất cụm từ | `public/version_search.php`, `src/v3_research.php` | `wa_version_term_history()` | T2 |
| **F6** | SHA-256 nội dung | `src/capture.php`, `scripts/backfill_snapshot_hash.php` | `snapshots.content_hash` | `test_backfill_logic.php` (10/10) |
| **F7** | Chrome Extension (Manifest V3) | `chrome-extension/` | `background.js`, `popup.js` | `test_extension_chrome.php` (E1–E9, **11/11 — Chrome headless + CDP**) |
| **F8** | Chia sẻ snapshot, hạn dùng, thu hồi | `public/share_action.php`, `public/shared.php`, `src/v3_share.php` | bảng `share_links`, `wa_share_sanitize_html()` | `test_v3_share_sanitize.php` (3/3) |
| **F9** | RSS/Atom | `public/feeds.php`, `src/v3_feeds.php` | `wa_feeds_parse()`, bảng `feed_subscriptions`, `feed_items` | `test_v3_feeds_parse.php` (4/4) |
| **F10** | Theo dõi đoạn nội dung | `public/watch_action.php`, `public/watches.php`, `src/v3_watch.php` | `wa_watch_check_page()`, bảng `content_watches` | `test_v3_research.php` (F10) |
| **F11** | Liên kết bài viết & sơ đồ | `public/relation_action.php`, `src/v3_relations.php` | bảng `page_relations`, SVG trong `library.php` | `test_v3_research.php` (F11) |
| **F12** | Phát hiện trùng lặp | `src/v3_duplicates.php`, `public/save.php`, `public/api.php` | `wa_duplicates_check()` — URL chuẩn hoá / SHA-256 / độ tương đồng | `test_v3_research.php` (F12) |

---

## 2. Nhóm chức năng nền tảng (ngoài 12 chức năng V3)

| Nhóm | File chính | Bài kiểm thử |
|------|-------------|--------------|
| Đăng ký / đăng nhập / đăng xuất | `public/register.php`, `public/login.php`, `public/logout.php` | `run_tests.php` (đăng ký + đăng nhập), `test_admin_smoke.php` (S1) |
| Tạo / sửa / xoá thư viện | `public/libraries.php`, `public/library_action.php`, `src/queries.php` | `run_tests.php` (bước [2] + [4] dọn dẹp), `test_notify_summary.php` (D1) |
| Owner / editor / viewer | `src/auth.php`, bảng `library_members` | T6 (người ngoài 403), `test_auto_version.php` (A6), `test_admin_smoke.php` (S2) |
| Lưu URL công khai | `public/save.php`, `src/capture.php` | T1, T4 |
| Tách nội dung & lưu ảnh | `src/capture.php` (`wa_capture_find_content_node()`) | T1 (ảnh cục bộ HTTP 200) |
| Đọc offline | `public/reader.php` | T1 |
| Tìm kiếm & nhãn | `src/search.php`, `src/queries.php`, bảng `search_terms` | T2, `docs/SEARCH_QUERIES.md` |
| Phiên bản V1/V2/V3 | `public/refetch.php`, `src/auto_version.php`, `public/compare.php` | T5, `test_auto_version.php` (A3/A4/A5) |
| Ghi chú | `public/note.php`, `public/annotation_action.php` | T5 |
| Export | `public/export.php` | T6 |
| **Chính sách URL & chống SSRF** | `src/url_policy.php`, `src/capture.php` | `test_url_policy.php` (18/18), T3, T4 |

---

## 3. Bảng đồ lỗi đã sửa → nơi sửa

| Mã lỗi | Triệu chứng | Nguyên nhân gốc | File đã sửa |
|---------|-------------|-----------------|-------------|
| **BUG-01** | Auto Version bật ON → OFF → ON lần nữa bị lỗi | Nút công tắc bị khoá cứng sau khi tắt; trạng thái session lệch với CSDL | `public/libraries.php`, `public/auto_check.php`, `src/auto_version.php` |
| **BUG-02** | Công tắc tự động lưu của Extension không ổn định | Cấu hình chỉ nằm trong bộ nhớ popup, mất khi đóng; không chuẩn hoá boolean | `chrome-extension/background.js` (lưu vào `chrome.storage.local`, `saveSettings()` nguyên tử) |
| **BUG-03** | Không tìm thấy thư viện trong Extension | Không phân biệt "lỗi API" với "chưa có thư viện"; hiển thị danh sách rỗng giả | `chrome-extension/popup.js` (`loadLibraries()` — 3 trạng thái rõ ràng) |
| **BUG-04** | Tạo thư viện mới nhưng không lưu được | Sau khi tạo, popup không chọn thư viện vừa tạo → người dùng phải đóng/mở lại Extension | `chrome-extension/popup.js` (`doQuickCreateLibrary()` — dùng luôn danh sách API trả về và tự chọn `newId`) |
| **BUG-05** | Báo thành công khi server thất bại | Không ánh xạ mã lỗi API; lỗi mạng bị nuốt | `chrome-extension/popup.js` (`apiErrorMessage()`), `chrome-extension/background.js` |
| **BUG-06** | Cảnh báo rủi ro xuất hiện vô tội vạ | Hộp cảnh báo hiện sẵn khi mở popup | `chrome-extension/popup.js` (`hideRisk()` mặc định; chỉ hiện khi server trả `res.risk`) |
| **BUG-07** | Cảnh báo trùng lặp xuất hiện vô tội vạ | Không có trạng thái riêng cho cảnh báo trùng lặp | `chrome-extension/popup.js` (`showDup()`/`hideDup()`), `src/v3_duplicates.php` |
| **BUG-08** | Hai cảnh báo lặp → vòng lặp HTTP 409 | Cờ xác nhận ghi đè lẫn nhau khiến server quay lại cảnh báo cũ | `chrome-extension/popup.js` (`overrideRisk`/`overrideDup` **tích luỹ**, thêm `MAX_CONFIRM_ROUNDS = 4`) |
| **BUG-09** | Đoạn theo dõi không sử dụng được | Chưa có bảng `content_watches` và luồng đối chiếu giữa các bản | `src/v3_watch.php`, `public/watch_action.php`, `public/watches.php` |
| **BUG-10** | Không bôi đen được nhiều lần | Mỗi lần bôi đen ghi đè bản ghi cũ; mất selection khi bấm thanh công cụ | `public/reader.php`, `public/annotation_action.php` (mỗi highlight/watch độc lập, gắn đúng snapshot) |
| **BUG-11** | PHP Warning: Undefined array key 0 | Truy cận phần tử đầu tiên của mảng rỗng (mảng nhãn) | `public/library.php` (dòng ~351), `src/queries.php`, `src/v3_duplicates.php` (biến `$isAdmin` chưa khai báo, dòng ~258) |
| **BUG-12** | Banner "bản đọc ngoại tuyến sẵn sàng" hiện giả | Điều kiện banner không kiểm tra số snapshot thành công | `public/library.php` (`$libMetrics['snapshots_ok'] > 0`), `public/assets/css/app.css` (`.state--empty`) |
| **BUG-13** | Wikipedia C++ lỗi `error_no_content` (chỉ 31 ký tự) | Thuật toán chọn `<article>`/`<main>` **đầu tiên** (quá ngắn), không hiểu cấu trúc MediaWiki | `src/capture.php` (`wa_capture_find_content_node()`: hỗ trợ `#mw-content-text`, `.mw-parser-output`, chọn theo **mật độ văn bản**) |
| **BUG-14** | Trạng thái giao diện & API không thống nhất; test T1–T6 timeout | `CURLOPT_RESOLVE` **ghim một IP**; trên Windows `localhost` nghe trên `[::1]` nhưng chỉ ghim `127.0.0.1` → `error_timeout` | `src/url_policy.php` (`wa_url_policy_resolve_ips()` + `wa_url_policy_resolve_entries()`), `src/capture.php` (`wa_capture_http_get()` — thử lần lượt từng IP đã xác minh) |
| **BUG-15** (phát sinh khi kiểm thử) | Bộ kiểm thử Chrome headless treo vĩnh viễn ở `proc_close()` | Trên Windows, `proc_open()` với **chuỗi** lệnh gọi qua `cmd.exe /c`; `proc_terminate()` chỉ giết vỏ `cmd.exe`, còn `php.exe`/`node.exe` vẫn chạy và giữ cổng | `scripts/test_extension_chrome.php`, `scripts/test_admin_smoke.php`, `scripts/test_notify_summary.php` (dùng **dãy lệnh dạng mảng** + `taskkill /F /T /PID`) |
| **BUG-16** (phát sinh khi kiểm thử) | Highlight F4 không render sau khi reload | `wa_annotation_find_by_path()` chỉ chấp nhận `tag:nth-of-type(n)`, không chấp nhận tên thẻ trần (`article > p:nth-of-type(1)` không khớp) | `src/v3_research.php` (`wa_annotation_find_by_path()` — nhận cả hai dạng, mặc định chỉ số = 1) |

---

## 4. Bằng chứng bảo mật & phân quyền

| Yêu cầu | Bằng chứng cụ thể | Nơi kiểm chứng |
|----------|-------------------|----------------|
| Chống SSRF — mạng riêng | `10.0.0.5`, `192.168.1.10`, `172.16.5.5`, `169.254.10.1` → BLOCKED `private_ip` | `test_url_policy.php` |
| Chống SSRF — cloud metadata | `169.254.169.254`, `metadata.google.internal` → BLOCKED | `test_url_policy.php`, T3 |
| Chống SSRF — scheme & credential | `ftp://` → `bad_scheme`; `http://user:pass@` → `has_credentials` | `test_url_policy.php` |
| Chống DNS rebinding | IP đích được **ghim** bằng `CURLOPT_RESOLVE` sau khi xác minh công khai; mỗi đích redirect được kiểm tra lại | `src/capture.php`, `src/url_policy.php` |
| Không thực thi script trong bản lưu | Snapshot không còn `<script>`, `onclick`, `iframe` | T4 |
| Chặn nội dung chia sẻ độc hại | Bỏ `<script>`, `on*`, `javascript:`, `<iframe>`, `<form>`, `<style>`; giữ ảnh và link http | `test_v3_share_sanitize.php` |
| Chặn XSS trong thông báo | Nội dung thông báo được escape khi hiển thị | N4 (`không XSS=có`) |
| Không lộ dữ liệu riêng | Người ngoài: `reader.php` 403, `export.php` 403, 0 thông báo, không thấy tiêu đề thông báo của chủ | T6, N5 |
| Phân quyền trong thư viện | Người có vai trò viewer không thể kiểm tra/cập nhật, không nhận thông báo | A6, A7b |
| Phân quyền quản trị | Người dùng thường bị chặn ở `/admin/index.php`, `/admin/users.php`, `/admin/allowlist.php` | `test_admin_smoke.php` (S2) |
| Token API có scope & hạn dùng | Scope: `read`, `library:create`, `page:save`; token thu hồi được; rate limit 60 req/phút (15 req lưu trang/phút) | `src/v3_api.php` |
| Chống race khi tạo phiên bản | Hai request đồng thời → chỉ 1 snapshot mới | A5 |

---

## 5. Bằng chứng dữ liệu (đo trên CSDL thật, MySQL 3306 `webarchive`)

Đo tại thời điểm 19/11/2026, **sau** khi chạy xong toàn bộ bộ kiểm thử (bộ kiểm thử
tự dọn dẹp dữ liệu thử nghiệm của nó):

| Bảng | Số dòng | Ghi chú |
|------|----------|---------|
| `users` | 17 | Gồm tài khoản demo + tài khoản thử nghiệm đã dọn |
| `libraries` | 22 | |
| `library_members` | 25 | Quan hệ owner/editor/viewer |
| `saved_pages` | 41 | |
| `snapshots` | 187 | Mỗi bản lưu = 1 dòng, `version_no` tăng dần |
| `search_terms` | 17 584 | Chỉ mục đảo tự xây (không dùng FULLTEXT) |
| `notifications` | 10 | Thông báo Auto Version |
| `api_tokens` | 2 | |
| `content_watches` | 0 | Bảng F10 đã tạo, chưa có dữ liệu thật từ người dùng |
| `page_relations` | 0 | Bảng F11 đã tạo, chưa có dữ liệu thật từ người dùng |
| `annotations` | 0 | Bảng F4 đã tạo |

> **Lưu ý:** hai bảng `content_watches` và `page_relations` còn 0 dòng vì chưa có
> người dùng thật tạo theo dõi/liên kết — **không phải** lỗi. Luồng được kiểm
> chứng bằng bài kiểm thử hồi quy N3b (`test_notify_summary.php`).

---

## 6. Bằng chứng "không phá dữ liệu thật"

| Ràng buộc đặt ra | Cách bảo đảm | Bằng chứng |
|-----------------|---------------|------------|
| Không chạy `database/schema.sql` / `setup_db.php` trên 3306 | Chỉ dùng migration idempotent (`migrate_auto_version.php`, `migrate_v3.php`) | Cả hai script đều kiểm tra `INFORMATION_SCHEMA` trước khi tạo |
| Không `DROP`/`TRUNCATE` bảng production | Script migration không chứa `DROP TABLE` | `scripts/migrate_v3.php`, `scripts/migrate_auto_version.php` |
| Không xoá snapshot / thư viện / tài khoản thật | Bộ kiểm thử chỉ tạo và xoá **dữ liệu do chính nó tạo** (có tiền tố mã lần chạy) | Mục `[4] Dọn dẹp` của `run_tests.php`: "xoá thư viện tạm — còn 0 dòng trong CSDL" |
| Không ghi mật khẩu / token / backup SQL vào Git | `config/db.local.php` nằm trong `.gitignore`; `.gitignore` bổ sung thêm `db.local.*.php`, `.local/`, `*.log` | `git ls-files` không chứa tệp bí mật |
| Không tạo Task Scheduler / cron / CMD tự chạy | Auto Version chỉ chạy qua consent + nút bấm tay | A1b (0 request khi OFF) |

---

## 7. Bằng chứng mã nguồn mở

| Tệp | Nội dung |
|------|----------|
| `README.md` | Hướng dẫn cài đặt, kết nối MySQL, cài Chrome Extension, mô tả F1–F12, kết quả test, tình huống demo, giới hạn |
| `CONTRIBUTING.md` | Quy trình đóng góp, quy tắc commit, quy trình kiểm thử |
| `docs/DEMO_GUIDE.md` | Hướng dẫn demo + ảnh cần chụp + sơ đồ cho báo cáo |
| `docs/FINAL_TEST_REPORT.md` | Báo cáo kiểm thử cuối kỳ (10 bộ tự động / 187 mục, gồm Chrome headless 11/11) |
| `docs/REPORT_EVIDENCE.md` | Tài liệu này — minh chứng & bản đồ mã nguồn |
| `docs/V3_DOT3.md` | Tài liệu Đợt 3 (Extension + Chia sẻ + RSS) |
| `docs/V3_DOT4.md` | Tài liệu Đợt 4 (Theo dõi đoạn + Quan hệ + Trùng lặp) |
| `docs/SEARCH_QUERIES.md` | 10 truy vấn có kết quả mong đợi |
| `docs/RELEASE_NOTES.md` | Ghi chú phát hành |
| `tests/samples/` | 15 trang HTML fixture + 3 trang lỗi (404 / timeout / redirect nguy hiểm) |
| `scripts/run_tests.php` | 6 bài kiểm thử bắt buộc T1–T6 |
| `scripts/test_auto_version.php` | Auto Version A1–A8b, D1 (19 mục) |
| `scripts/test_notify_summary.php` | Tóm tắt thay đổi N1–N5, D1 (7 mục) |
| `scripts/test_url_policy.php` | Chính sách URL / chống SSRF (18 mục) |
| `scripts/test_backfill_logic.php` | Backfill SHA-256 (10 mục) |
| `scripts/test_v3_feeds_parse.php` | F9 RSS/Atom (4 mục) |
| `scripts/test_v3_share_sanitize.php` | F8 khử XSS (3 mục) |
| `scripts/test_v3_research.php` | F4 / F10 / F11 / F12 (4 mục) |
| `scripts/test_admin_smoke.php` | Smoke test phần quản trị (5 mục) |
| `scripts/test_extension_chrome.php` + `scripts/ext_cdp_runner.js` | Kiểm thử Chrome Extension thật, headless + CDP (11 mục) |

---

## 8. Lịch sử commit chính (để đối chiếu khi bảo vệ)

```
fba882c  fix: stabilize WebArchive V3 extension, reader and capture flows
dbb36be  feat: auto version updates and notification summaries
886ddba  update thong bao giua cac ban
23b40ab  P-FIX-CARD-CHIPS: gom 3 chip thumb ve goc trai, het gian deu 2 dau
06cbde7  P-FIX-SEARCH-1062: fold Latin ve ASCII + ON DUPLICATE VALUES(), luu bai va reindex khong loi
0c1687a  P-FEAT-COMPARE: so sanh phien ban, kiem tra ban moi truoc khi luu
```

Commit phát hành V3 (sửa lỗi + kiểm thử + tài liệu) được ghi ở phần **D. GITHUB**
trong báo cáo bàn giao và truy cập được tại:
<https://github.com/TranDaiHiep2006/PTPMMNM-NHOM9>
