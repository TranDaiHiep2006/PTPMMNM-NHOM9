# PROJECT_CONTEXT.md — WebArchive (Đề tài 16)

> Tài liệu ngữ cảnh dự án. **Giai đoạn hiện tại: P-FINAL — HOÀN TẤT, phát hành `v1.0.0`.**
> Tất cả năm chức năng nghiệp vụ của đề tài đã chạy thật và được kiểm chứng tự động.
> `capture_url()` là engine tải trang thật; phần ghi CSDL đã gom về một hàm
> dùng chung `wa_store_page_capture()` mà cả `save.php` lẫn `refetch.php` đều gọi.
> Tìm kiếm **KHÔNG còn** quét `LIKE` và **KHÔNG** dùng FULLTEXT / `MATCH ... AGAINST`:
> `public/search.php` đọc bảng chỉ mục `search_terms` do dự án tự dựng (`src/search.php`).
> **Tạo/sửa/xoá thư viện, quản lý thành viên, sửa nhãn theo bài và xuất bản lưu** đều là
> hành động thật ghi vào MySQL — không còn mục nào "chỉ hiển thị giao diện".
>
> **Bằng chứng P-FINAL:** 15 trang mẫu trong `tests/samples/` (+ bảng kê
> `tests/samples/README.md`), nạp vào kho bằng `scripts/seed_samples.php`, mười câu hỏi
> tìm kiếm trong `docs/SEARCH_QUERIES.md`, sáu bài kiểm thử bắt buộc T1–T6 trong
> `scripts/run_tests.php` (tự bật máy chủ riêng ở cổng 18080/18091), kịch bản trình diễn
> trong `docs/DEMO.md`, và gói mã nguồn mở `LICENSE` (MIT) + `CONTRIBUTING.md`.

---

## 1. Sản phẩm

WebArchive là một ứng dụng Web giúp **lưu nội dung (snapshot) của một trang web** để đọc lại
và tìm kiếm về sau. Nội dung được tổ chức trong **thư viện**, gắn **nhãn (tags)** và có
**phân quyền riêng cho từng thư viện**.

Ứng dụng chạy bằng PHP thuần (không framework, không bước build), giao diện dùng một
stylesheet cục bộ duy nhất. Toàn bộ nội dung hiển thị bằng **tiếng Việt**.

---

## 2. Năm chức năng nghiệp vụ

| # | Chức năng | Mô tả |
|---|-----------|-------|
| 1 | **Thư viện, nhãn, phân quyền** | Người dùng tạo thư viện theo chuyên đề, gắn nhãn đa tầng, và phân quyền thành viên theo vai trò **Chủ sở hữu / Biên tập viên / Người xem**. Chỉ những URL thuộc **danh sách được duyệt (allowlist)** mới được đưa vào quy trình lưu trang. |
| 2 | **Tải HTML + bóc tách tiêu đề/nội dung, phân biệt lỗi** | Hệ thống tải trang, tách tiêu đề và phần nội dung chính. **Các lỗi phải được báo riêng biệt** (không nằm trong allowlist / 404 / timeout) chứ không được ghi nhận như một bài viết hợp lệ. |
| 3 | **Bản đọc lại sạch, ảnh lưu cục bộ** | Sinh một phiên bản đọc "sạch": bỏ script, style rác, quảng cáo; tải ảnh về máy chủ nội bộ và nối lại bằng đường dẫn cục bộ. |
| 4 | **Tìm kiếm tự xây dựng trên nội dung đã lưu** | Tìm kiếm dựa trên nội dung đã lưu, trả về đoạn trích (snippet) và **luôn gắn với đúng phiên bản snapshot** mà kết quả thuộc về. |
| 5 | **Tải lại (refetch) + ghi chú + xuất kho** | Tải lại tạo **phiên bản mới nhưng giữ lại phiên bản cũ** kèm ghi chú; xuất kho có kiểm tra phân quyền. |

---

## 3. Hai yêu cầu kỹ thuật cốt lõi

1. **Không bao giờ lưu trang lỗi như một bài viết hợp lệ, và luôn đọc từ bản snapshot đã lưu.**
   Trang 404 / 5xx / timeout phải sinh thông báo lỗi tương ứng, không tạo bản ghi nội dung.
   Khi người dùng mở lại một bài viết, dữ liệu phải đến từ snapshot đã lưu chứ không truy cập lại trang gốc.

2. **Tìm kiếm phải dựa trên nội dung đã lưu; snapshot chặn script và tài nguyên ngoài không kiểm soát;
   mọi đích tải về — kể cả sau khi chuyển hướng — đều phải được kiểm tra với allowlist.**
   Bản snapshot không được chứa `<script>`, `<iframe>`, `<object>`, thuộc tính sự kiện, style inline
   hay tài nguyên CDN không kiểm soát được.

---

## 4. Bản đồ trang

| Trang | Đường dẫn | Quyền | Nội dung |
|-------|-----------|-------|----------|
| Trang chủ (khách) | `public/index.php` | Khách | Landing page: hero, 3 tính năng, quy trình 3 bước, không gian đọc, CTA |
| Đăng nhập | `public/login.php` | Khách | Biểu mẫu email/mật khẩu, lỗi tiếng Việt, đăng nhập bằng session |
| Đăng ký | `public/register.php` | Khách | Biểu mẫu đầy đủ, kiểm tra dữ liệu, trạng thái thành công (**ghi thật** vào bảng `users`) |
| Đăng xuất | `public/logout.php` | — | Xoá session, quay về trang chủ |
| Thư viện của tôi | `public/libraries.php` | Đăng nhập | Số liệu, tab lọc, lưới thẻ thư viện, **biểu mẫu tạo thư viện thật** (POST → `library_action.php`) |
| Chi tiết thư viện | `public/library.php?slug=...` | Đăng nhập | Breadcrumb, số liệu, **bảng thành viên (thêm/đổi vai trò/gỡ)**, **cài đặt thư viện (đổi tên/đổi hiển thị/xoá)**, **ô sửa nhãn theo bài**, thẻ bài viết (kèm huy hiệu "N phiên bản"), mục "Lưu thất bại gần đây", phân trang |
| Quản lý thư viện | `public/library_action.php` | Chủ sở hữu / biên tập viên / quản trị (POST) | Bộ điều khiển duy nhất cho các hành động `create`, `update`, `delete`, `member_add`, `member_role`, `member_remove`, `tags`; CSRF + phân quyền, trả flash tiếng Việt rồi chuyển hướng |
| Lưu trang Web | `public/save.php` | Chủ sở hữu / biên tập viên | Chọn thư viện + URL + nhãn → `wa_store_page_capture()`, chuyển tới trình đọc kèm số phiên bản |
| Tải lại phiên bản | `public/refetch.php` | Chủ sở hữu / biên tập viên (POST) | Gọi CHUNG `wa_store_page_capture()` với `source_url` đã lưu; thành công chuyển tới bản mới, thất bại ở lại bản hiện tại kèm lỗi riêng |
| Ghi chú | `public/note.php` | Đăng nhập (POST) | Thêm / sửa / xoá ghi chú của một snapshot; sở hữu ép bằng `user_id` trong SQL |
| Trình đọc | `public/reader.php?id=<snapshot id>` | Đăng nhập + thành viên thư viện | Đầu nguồn gốc, bộ chuyển phiên bản, nút "Lấy phiên bản mới", nút **"Xuất bản lưu"** (→ `export.php`) cạnh nút "Xuất PDF", bảng ghi chú, A-/A+, tiến độ đọc |
| **Xuất bản lưu** | `public/export.php?id=<snapshot id>` | Đăng nhập + thành viên thư viện | Tải về một tệp **HTML độc lập**: khối nguồn gốc + `<style>` tối giản + `content_html` với **mọi ảnh cục bộ nhúng base64 data URI**; không còn tài nguyên ngoài |
| Tìm kiếm | `public/search.php` | Đăng nhập | Từ khoá, lọc theo thẻ/tên miền, kết quả có `<mark>` + thư viện + phiên bản snapshot. Truy vấn trên **chỉ mục đảo** `search_terms` (`wa_search_snapshots()`), **không** `LIKE`, **không** FULLTEXT |
| Tổng quan hệ thống | `public/admin/index.php` | Quản trị | Tổng users/libraries/snapshots/assets/lỗi, nhật ký hoạt động |
| Người dùng | `public/admin/users.php` | Quản trị | Bảng tài khoản, vai trò, trạng thái, số thư viện, thao tác (UI) |
| Tên miền được duyệt | `public/admin/allowlist.php` | Quản trị | Bảng allowlist + biểu mẫu thêm tên miền (**ghi thật** vào CSDL) + ghi chú quy tắc |
| Tất cả thư viện | `public/admin/libraries.php` | Quản trị | Bảng thư viện toàn hệ thống, chủ sở hữu, hiển thị, số bài/ảnh |

**Tài khoản ngoài (không thuộc thư viện) dùng trong kiểm thử:** `public/register.php` ghi
thật vào bảng `users` — đây là đường đăng ký mà `scripts/run_tests.php` dùng để tạo
tài khoản rác cho bài `T6`.

**Bảo vệ trang:** mọi trang workspace/reader/search/admin đều gọi `require_login()`.
Các trang admin gọi thêm `require_admin()`; người dùng thường nhận trang
“Không có quyền truy cập” bằng tiếng Việt (`templates/error_no_permission.php`).

**Phân quyền P-READ (siết thêm so với trước đây):**
- `reader.php` giải quyết snapshot → trang → thư viện: người dùng phải là **quản trị
  viên hoặc thành viên** của thư viện (mọi vai trò), nếu không nhận trang **403 tiếng Việt**.
- Nút **“Lấy phiên bản mới”** và `refetch.php` chỉ dành cho **chủ sở hữu / biên tập viên**
  (quản trị viên luôn được phép). Người xem không thấy nút **và** bị từ chối ở server.
- **Mọi người xem được thư viện** đều thêm được ghi chú; **sửa/xoá** chỉ dành cho tác giả,
  điều kiện `user_id` nằm trong câu `UPDATE`/`DELETE`; quản trị viên xoá được mọi ghi chú
  qua một đường riêng.
- Mọi biểu mẫu POST mới dùng **token CSRF theo phiên**: `wa_csrf_token()` / `wa_csrf_check()`.

---

## 5. Cấu trúc mã nguồn

```
prompt.md                      Đặc tả giai đoạn này
PROJECT_CONTEXT.md             Tài liệu này
README.md                      Hướng dẫn chạy
database/schema.sql            Lược đồ MySQL: 11 bảng InnoDB utf8mb4, FK + UNIQUE (gồm `search_terms`)
database/seed.sql              Dữ liệu mẫu (INSERT) — chạy lại được nhiều lần
scripts/setup_db.php           Cài CSDL + tạo user riêng + nạp seed (CLI, idempotent)
scripts/migrate_search.php     Tạo bảng chỉ mục `search_terms` (CLI, idempotent, tài khoản ứng dụng)
scripts/reindex_search.php     Lập lại toàn bộ chỉ mục tìm kiếm (CLI, idempotent)
scripts/make_sample_images.php Sinh 5 tệp PNG nhỏ hợp lệ cho bộ trang mẫu (không cần GD)
scripts/seed_samples.php       Nạp 15 trang mẫu vào "Kho dữ liệu mẫu" qua engine thật (CLI, idempotent)
scripts/run_tests.php          Sáu bài kiểm thử bắt buộc T1–T6, tự bật máy chủ 18080/18091
config/db.php                  Nạp cấu hình, dựng DSN (KHÔNG chứa mật khẩu)
config/db.local.php            Thông tin kết nối thật — .gitignore, KHÔNG commit
src/db.php                     PDO singleton, chẩn đoán lỗi, trang lỗi tiếng Việt
src/auth.php                   Session, require_login(), require_admin(), current_user(), CSRF (wa_csrf_token/wa_csrf_check)
src/bootstrap.php              Hằng số đường dẫn, nạp src, hàm trợ giúp (wa_url/wa_e/...), bảng bỏ dấu tiếng Việt dùng chung (wa_vi_alphabet/wa_vi_fold_map)
src/queries.php                TOÀN BỘ truy vấn CSDL + wa_store_page_capture() (đường lưu dùng chung, gọi lập chỉ mục) + phiên bản/ghi chú + **quản lý thư viện/thành viên/nhãn + dựng tệp xuất HTML độc lập**
src/search.php                 TÌM KIẾM TỰ XÂY: tokenizer, bỏ dấu, wa_search_index_snapshot(), truy vấn AND + xếp hạng, đoạn trích <mark>
src/capture.php                ENGINE TẢI TRANG THẬT: capture_url() (allowlist → cURL → DOM → sanitize → tải ảnh cục bộ)
tests/fixtures/                Trang thử cho fixture server (php -S localhost:8090 -t tests/fixtures)
tests/samples/                 15 trang mẫu tiếng Việt + 5 ảnh PNG + README.md làm bảng kê
                                (php -S localhost:8091 -t tests/samples)
templates/header_guest.php     Layout khách: thanh trên + <main>   (KHÔNG có user chip)
templates/footer_guest.php     Đóng layout khách + chân trang
templates/header_app.php       Layout ứng dụng: thanh trên + sidebar
templates/footer_app.php       Đóng layout ứng dụng
templates/error_no_permission.php   Trang 403 tiếng Việt
templates/error_database.php   Trang lỗi CSDL tiếng Việt (503)
public/                        WEB ROOT
public/assets/css/app.css      Toàn bộ design token + component (không framework)
public/assets/img/             Logo SVG, ảnh minh hoạ cục bộ
public/uploads/snapshots/<id>/ Ảnh đã tải của từng snapshot (.gitignore, chỉ giữ .gitkeep)
public/library_action.php      Bộ điều khiển POST cho quản lý thư viện / thành viên / nhãn (CSRF + phân quyền)
public/export.php              Xuất bản lưu — HTML độc lập tải về (ảnh nhúng base64)
docs/design-reference/         Nguồn thiết kế (DESIGN.md + 4 màn hình tham chiếu)
docs/DEMO.md                   Hai kịch bản trình diễn chính thức, click-by-click
docs/SEARCH_QUERIES.md         10 câu hỏi tìm kiếm: kỳ vọng thiết kế trước, thực tế sau
docs/RELEASE_NOTES.md          Ghi chú phát hành v1.0.0
LICENSE                        Giấy phép MIT, bên giữ bản quyền "the WebArchive contributors"
CONTRIBUTING.md                Quy ước dự án và cách đóng góp
```

---

## 6. Tài khoản demo

| Email | Mật khẩu | Vai trò | Tên hiển thị |
|-------|----------|---------|--------------|
| `user@demo.vn` | `demo123` | Người dùng | Lê Minh Tuấn |
| `admin@demo.vn` | `admin123` | Quản trị viên | Trần Hoàng Nam |

Đây là **tài khoản thật trong bảng `users`** của MySQL (được nạp từ `database/seed.sql`).
Mật khẩu được lưu bằng `password_hash()` (bcrypt) và kiểm tra bằng `password_verify()`.
Ngoài ra còn có 3 tài khoản phụ dùng cho màn hình quản trị:
`ha.nguyen@demo.vn` (Nguyễn Thu Hà), `minh.vu@demo.vn` (Vũ Quang Minh — **đang bị khoá**),
`linh.pham@demo.vn` (Phạm Thùy Linh).

---

## 6b. Cơ sở dữ liệu

- MySQL 8.0, database **`webarchive`**, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.
- Cài đặt / nạp lại dữ liệu: đặt `WA_ADMIN_USER` + `WA_ADMIN_PASS` rồi chạy `php scripts/setup_db.php`.
- Ứng dụng kết nối bằng tài khoản riêng `'webarchive'@'localhost'`; thông tin nằm trong
  `config/db.local.php` (**đã .gitignore, không bao giờ commit**).
- `config/db.php` chỉ đọc cấu hình và dựng DSN, **không chứa mật khẩu**.
- 11 bảng: `users`, `libraries`, `library_members`, `tags`, `saved_pages`, `page_tags`,
  `snapshots`, **`search_terms`** (chỉ mục đảo tìm kiếm — P-SEARCH), `assets`, `notes`,
  `allowlist_domains`.
- Bảng `search_terms` được thêm bằng `php scripts/migrate_search.php` (kịch bản **idempotent**,
  chạy bằng **tài khoản ứng dụng** trong `config/db.local.php`, **không** cần mật khẩu quản trị
  MySQL và **không** chạy lại `setup_db.php`); nó cũng nằm trong `database/schema.sql` để cài mới
  có sẵn. Lập lại toàn bộ chỉ mục: `php scripts/reindex_search.php`.
- Mọi truy vấn có tham số dùng prepared statement (`PDO::ATTR_EMULATE_PREPARES = false`).
- Quyền truy cập cơ bản: người dùng thấy thư viện mình **sở hữu** hoặc **là thành viên**;
  quản trị viên thấy tất cả (xem `wa_library_access_sql()` trong `src/queries.php`).
- Nếu CSDL không truy cập được, hiện `templates/error_database.php` — không lộ stack trace.

---

## 7. Engine tải trang thật (`src/capture.php`)

`capture_url(string $url): array` trả về `ok, error_code, http_status, final_url,
title, content_html, content_text, images, fetched_at` (kèm `error_message`,
`images_skipped`). `src/mock_capture.php` **đã bị xoá** hoàn toàn.

Thứ tự pipeline (đúng đặc tả P-CAPTURE):

| Bước | Việc | Mã lỗi |
|------|------|--------|
| 1 | `parse_url`; chỉ `http`/`https`; bắt buộc có host; từ chối URL có `user:pass` | `error_invalid_url` |
| 2 | Kiểm tra allowlist **trước khi gửi bất kỳ byte nào**: host bằng đúng một domain trong bảng, hoặc kết thúc bằng `.` + domain (không phân biệt hoa thường) | `error_blocked` |
| 3 | cURL **không** `FOLLOWLOCATION`; tự đi theo redirect tối đa **5** bước; **kiểm tra allowlist lại ở mỗi đích redirect** trước khi tải; kết nối **5 s**, tổng **12 s**; dừng nếu tải vượt **5 MB** | `error_timeout`, `error_http` |
| 4 | 404 → `error_404`; 4xx/5xx khác → `error_http` (kèm mã); `Content-Type` không phải HTML/XHTML → `error_no_content` | — |
| 5 | DOMDocument: tiêu đề `og:title` → `<title>` → `<h1>` đầu; thân ưu tiên `<article>` → `<main>` → div/section có **điểm mật độ chữ** cao nhất; gỡ `script, style, noscript, iframe, form, nav, header, footer, aside, svg, button, input` | — |
| 6 | Text < 200 ký tự → `error_no_content` | `error_no_content` |
| 7 | Sanitizer **whitelist tự viết**: chỉ giữ tag và thuộc tính trong danh sách cho phép; `a` chỉ giữ `href` http/https (đã giải phân giải tuyệt đối) + `title`, mọi link được thêm `rel="noopener noreferrer"`; `img` chỉ giữ `src, alt, title, width, height`; `td/th` chỉ giữ `colspan, rowspan`; link không hợp lệ bị **unwrap** giữ chữ; cuối cùng **assert** không còn `<script>`, `javascript:` hay thuộc tính sự kiện | — |
| 8 | Ảnh (tối đa **20**): `src` → tuyệt đối, kiểm tra allowlist host như URL trang (ngoài allowlist thì **xoá ảnh** và đếm); tải cùng luật timeout/redirect, cap 5 MB; chỉ nhận `image/jpeg, image/png, image/gif, image/webp` kiểm bằng `finfo`/`getimagesizefromstring` (**không tin phần mở rộng**, **không SVG**); lưu `public/uploads/snapshots/<snapshot_id>/<hash>.<ext>`; INSERT `assets`; ghi lại `content_html` — sau bước này **không còn URL ảnh ngoài** | — |
| 9 | `content_text`: bỏ thẻ, giải mã entity, gộp khoảng trắng | — |

**Mã lỗi → thông báo tiếng Việt** (mỗi mã một thông báo riêng, xem `wa_capture_error_text()`):
`error_invalid_url`, `error_blocked`, `error_404`, `error_http`, `error_timeout`,
`error_no_content`.

**Ghi bản ghi lỗi:** `save.php` vẫn tạo/find `saved_pages` và INSERT một `snapshots`
với `fetch_status = error_code`, `error_message` đầy đủ và nội dung rỗng, để thống kê
quản trị đếm được lỗi. Các truy vấn `wa_library_articles()`, `wa_find_snapshot()` và
`wa_search_snapshots()` **luôn lọc `fetch_status = 'success'`**, nên bản ghi lỗi không bao
giờ hiện như bài đọc được.

**Allowlist hiện có (giữ nguyên, gồm `localhost` làm host thử nghiệm cục bộ):**
`phptutorial.vn`, `techblog.dev`, `tapchicongnghe.vn`, `dev-insights.org`,
`themorningnews.org`, `old-forum.example.net`, `localhost` (fixture server cổng 8090),
`vi.wikipedia.org` + `thumb.wikimedia.org` (kiểm thử lưu trang thật P-CAPTURE).
`example.com` và `example.org` **cố ý không có trong danh sách** — chúng là nguồn cho các
ca kiểm thử `error_blocked` và `redir-escape.php` chuyển hướng tới `example.org`.

### Máy chủ thử nghiệm (fixture server)

`tests/fixtures/` được phục vụ bằng máy chủ thứ hai:

```powershell
php -S localhost:8090 -t tests/fixtures
```

Gồm `article.html` (có `og:title`, `<h1>`, khoá chỉ có trong thân, `<img src="pic.png">`,
`<script>`, `onclick`, link ngoài), `pic.png` (PNG 64×64 hợp lệ), `redir-ok.php`,
`redir-escape.php` (→ `https://example.org/`, không bao giờ cho vào allowlist),
`slow.php` (ngủ 15 s), `notice.html` (text < 200 ký tự).

### Máy chủ mẫu (samples server)

`tests/samples/` được phục vụ bằng máy chủ thứ ba:

```powershell
php -S localhost:8091 -t tests/samples
```

Gồm 13 bài `bai-01.html` … `bai-13.html`, `chuyen-huong-ngoai.php` (chuyển hướng ra
ngoài allowlist), `cham.php` (ngủ 15 s → timeout), 5 ảnh `anh-0*.png`, và
`README.md` làm bảng kê. Trường hợp 404 không cần tệp: `/khong-ton-tai.html` trên chính máy
chủ này trả về 404.

---

## 8. Kiến trúc theo từng phase và cách các phase sau cắm vào

### 8b. Kiến trúc P-READ (đường lưu dùng chung + phiên bản + ghi chú)

**Một đường ghi CSDL duy nhất.** `wa_store_page_capture(int $libraryId, string $url,
array $tagNames, int $userId)` trong `src/queries.php` là nơi DUY NHẤT biết cách
tạo ra một bản ghi lưu trang. Nó làm đúng một loạt việc, theo đúng thứ tự:

1. `capture_url($url)` — tải + bóc tách + sanitize.
2. Tìm hoặc tạo dòng `saved_pages` theo `(library_id, source_url)`.
3. `version_no = MAX(version_no) + 1`.
4. **Thành công**: `INSERT snapshots` → tải ảnh về máy chủ nội bộ → `UPDATE content_html/content_text`
   → `INSERT assets` → `UPDATE saved_pages` → gắn nhãn.
5. **Thất bại**: `INSERT snapshots` với `fetch_status` = mã lỗi và `error_message` tiếng Việt riêng.

Hai nơi gọi hàm này:

| Nơi gọi | URL nguồn lấy từ đâu | Kết quả |
|---------|----------------------|---------|
| `public/save.php` | ô nhập của người dùng | chuyển tới `reader.php?id=<snapshot mới>` |
| `public/refetch.php` | `source_url` **đã lưu trong CSDL** (không nhận từ POST) | chuyển tới bản mới, hoặc ở lại bản hiện tại kèm lỗi |

Nhờ gọi chung một hàm, **tải lại không bao giờ đụng tới phiên bản cũ** (kể cả ghi chú
của chúng): hàm chỉ `INSERT` thêm một dòng `snapshots`.

**Phiên bản.** `wa_page_versions(page_id)` lấy các bản `fetch_status = 'success'`,
sắp `version_no` giảm dần; `wa_page_neighbour_versions()` tìm bản liền trước/sau.
`wa_reader_context(snapshot_id)` gộp lại: nạp bài, kiểm tra quyền, vai trò, quyền
tải lại, danh sách phiên bản và ghi chú — `reader.php` chỉ cần một lần gọi.

**Ghi chú theo từng snapshot.** Bảng `notes` gắn với `snapshot_id` nên mỗi phiên bản
có danh sách ghi chú riêng. `wa_note_update()` và `wa_note_delete()` đặt điều kiện
`user_id = ?` **trong câu lệnh SQL**, nên gửi POST thẳng bằng tay cũng không sửa/xoá
được ghi chú của người khác. Quản trị viên xoá mọi ghi chú qua `wa_note_delete_by_admin()`
— một đường riêng, không đi qua hàm của tác giả.

**CSRF.** `wa_csrf_token()` sinh token 32 byte cho mỗi phiên; `wa_csrf_check()` so khớp
bằng `hash_equals()`. `refetch.php`, `note.php` và `save.php` đều kiểm tra TRƯỚC khi
ghi bất cứ thứ gì vào CSDL.

### 8c. Kiến trúc P-SEARCH (chỉ mục đảo tìm kiếm tự xây)

**Vì sao không FULLTEXT?** Đề tài yêu cầu phần tìm kiếm phải **tự xây** và giải thích được khi
vấn đáp. Vì vậy dự án không tạo FULLTEXT index và không dùng `MATCH ... AGAINST`; thay vào đó
tự dựng chỉ mục đảo.

**Bảng `search_terms`** — mỗi dòng là một cặp (từ đã bỏ dấu, snapshot) kèm `count_title` và
`count_body`. `UNIQUE (term_folded, snapshot_id)` + `INDEX (term_folded)`, khoá ngoại
`snapshot_id → snapshots(id)` `ON DELETE CASCADE` (xoá snapshot là mất chỉ mục theo).

**Chuỗi xử lý** (tất cả trong `src/search.php`, bảng bỏ dấu dùng chung nằm ở
`src/bootstrap.php: wa_vi_fold_map()` — dự án chỉ có **một** bảng bỏ dấu):

1. `wa_search_tokenize($text)` — `mb_strtolower()`, tách ở mọi ký tự không phải chữ/số Unicode
   (`/[^\p{L}\p{N}]+/u`), bỏ token dưới 2 ký tự, cắt token trên 100 ký tự. Tiếng Việt tách theo
   khoảng trắng, không cần bộ tách từ theo từ điển.
2. `wa_search_fold($token)` — bỏ dấu tiếng Việt (dùng chung bảng của `src/bootstrap.php`) để
   người dùng gõ không dấu vẫn khớp được bài có dấu.
3. `wa_search_index_snapshot($snapshotId)` — xoá dòng cũ của snapshot, tách token **riêng** cho
   tiêu đề và thân, gộp theo dạng đã bỏ dấu, `INSERT`. Hàm này là **idempotent** và chỉ chạy cho
   `fetch_status = 'success'` — snapshot lỗi không bao giờ có dòng chỉ mục.
4. `wa_store_page_capture()` gọi (3) ngay sau khi lưu thành công → **lập chỉ mục tự động**,
   không cần chạy kịch bản. Mỗi phiên bản giữ bộ chỉ mục riêng nên kết quả gắn đúng phiên bản.
5. `wa_search_snapshots($filters)` — truy vấn trên chỉ mục: ngữ nghĩa **AND** (đếm số từ đã khớp
   phải bằng số từ truy vấn), xếp hạng `score = 3 × SUM(count_title) + SUM(count_body)`,
   lọc `fetch_status = 'success'` và **bộ lọc quyền truy cập ngay trong SQL**
   (`wa_library_access_sql()`), cộng thêm lọc thẻ / tên miền.
6. `wa_search_snippet()` / `wa_search_highlight_title()` — so khớp trên **dạng đã bỏ dấu** nhưng
   in ra **văn bản gốc có dấu**, bọc `<mark>`; mọi thứ đều escape HTML.

Hai kịch bản CLI: `scripts/migrate_search.php` (tạo bảng, idempotent, dùng tài khoản ứng dụng)
và `scripts/reindex_search.php` (xoá sạch rồi lập lại mọi snapshot thành công, idempotent).

### 8d. Kiến trúc P-LIB (quản lý thư viện, thành viên, nhãn, xuất bản lưu)

**Một bộ điều khiển duy nhất cho mọi hành động ghi.** `public/library_action.php` nhận `POST`
với trường `action` và **token CSRF** (`wa_csrf_token()` / `wa_csrf_check()`), kiểm tra quyền,
rồi gọi hàm trong `src/queries.php` và chuyển hướng kèm **flash tiếng Việt**. Người không đủ
quyền nhận **403 tiếng Việt** (`templates/error_no_permission.php`).

**Slug.** `wa_slugify()` + `wa_library_unique_slug()` trong `src/queries.php`: hạ chữ thường →
bỏ dấu bằng bảng bỏ dấu **dùng chung** `wa_vi_fold_map()` (`src/bootstrap.php`) → khoảng trắng
thành gạch ngang; trùng thì nối `-2`, `-3`, … **Đổi tên mà tên không đổi thì giữ slug cũ**.

**Xoá thư viện — chỉ chủ sở hữu.** `wa_library_delete()` kiểm tra `owner_id` **thật** (quản trị
viên không xoá được từ đây), xoá dòng `libraries` để khoá ngoại `ON DELETE CASCADE` dọn
`library_members` / `saved_pages` / `snapshots` / `assets` / `page_tags`, rồi **xoá thư mục ảnh
trên đĩa** `public/uploads/snapshots/<snapshot_id>/` của **mọi** snapshot thuộc thư viện
(`unlink()` từng tệp rồi `rmdir()`).

**Bảo vệ dòng chủ sở hữu — ép trong SQL.** `wa_member_update_role()` và `wa_member_remove()`
đều có điều kiện `AND role <> 'owner'` ngay trong `WHERE`, nên gửi thẳng `POST` bằng tay cũng
**không** hạ cấp hay xoá được chủ sở hữu — không chỉ ẩn nút trong giao diện.

**Sửa nhãn theo bài.** `wa_page_tags_replace()` nhận danh sách nhãn phân tách bằng dấu phẩy,
`wa_normalize_tag_names()` chuẩn hoá (giữ tiền tố `#` nếu dữ liệu sẵn có dùng), tạo dòng `tags`
mới khi cần, rồi **thay thế** toàn bộ tập `page_tags` của trang đó. `wa_page_can_edit_tags()`
chỉ cho phép `owner` / `editor` / `admin`; người xem chỉ thấy nhãn chỉ đọc.

**Xuất bản lưu — HTML độc lập.** `public/export.php` (`GET ?id=<snapshot_id>`) áp **đúng quyền
của trình đọc** (quản trị hoặc thành viên thư viện; không thì 403; snapshot lỗi / không tồn tại
→ 404). Tệp trả về có `Content-Disposition: attachment; filename="<slug>-v<version>.html"`.

| Bước | Hàm | Việc |
|------|-----|------|
| 1 | `wa_export_build_document()` | Dựng khối nguồn gốc (tiêu đề, URL nguồn, tên thư viện, "Phiên bản N", `fetched_at`, người xuất + thời điểm) + `<style>` tối giản + `content_html` |
| 2 | `wa_export_inline_images()` | Duyệt `<img>` bằng DOM; ảnh cục bộ (`/uploads/…`) được thay bằng **data URI base64**; ảnh không đọc được hoặc ảnh ngoài thì **gỡ khỏi tệp** để tệp luôn độc lập |
| 3 | `wa_export_read_image_data_uri()` | Đọc tệp, nhận diện MIME bằng `finfo_buffer()` (**không tin phần mở rộng**), chỉ nhận JPEG/PNG/GIF/WebP (SVG bị loại vì có thể chứa mã thực thi) |
| 4 | `wa_export_html_is_standalone()` | **Kiểm tra bằng DOM (`DOMXPath`)**, không dùng regex trên chuỗi: mọi `src`/`poster`/`data` phải là `data:` hoặc rỗng; không có `link`/`script`/`iframe`/`object`/`embed`/`audio`/`video`/`source` |

> **Vì sao kiểm tra bằng DOM chứ không dùng regex?** Regex trên chuỗi sẽ **báo nhầm** khi bài viết
> chứa HTML đã escape (ví dụ một khối code có `<img src="/assets/…">` trong nội dung) — đó chỉ là
> *văn bản* trong bài, không phải tài nguyên sẽ được tải. `DOMXPath` chỉ nhìn **thuộc tính thật**
> của phần tử, nên không báo nhầm.

Liên kết `<a href="http…">` **được giữ nguyên** trong tệp xuất: đó là *nội dung*, không phải
tài nguyên phải tải — đúng như đặc tả.

### 8e. Các phase sau cắm vào thế nào

| Phase | Cần làm | Chỗ cắm |
|-------|---------|----------|
| ~~**Engine tải trang**~~ **XONG (P-CAPTURE)** | Pipeline thật: cURL → allowlist (kể cả chuỗi redirect) → bóc tách → sanitize → tải ảnh cục bộ → INSERT `saved_pages`/`snapshots`/`assets` | `src/capture.php` (thay `src/mock_capture.php`), `public/save.php` |
| ~~**Lưu trữ & tìm kiếm**~~ **XONG (P-SEARCH)** | Chỉ mục đảo tự dựng `search_terms` (không FULLTEXT): tokenizer + bỏ dấu + lập chỉ mục tự động khi lưu, truy vấn AND + xếp hạng + lọc quyền trong SQL, đoạn trích `<mark>` | `src/search.php`, `database/schema.sql`, `scripts/migrate_search.php`, `scripts/reindex_search.php`, `src/bootstrap.php` (`wa_vi_fold_map()`), `src/queries.php` (hook lập chỉ mục) |
| **Quản lý thư viện & thành viên & nhãn & xuất** | ~~Áp dụng vai trò `editor`/`viewer` khi sửa, xoá, xuất kho hoặc tải lại~~ | **XONG (P-LIB)** — `src/queries.php`, `public/library_action.php`, `public/library.php`, `public/libraries.php`, `public/export.php` |
| **Ghi chú & tài nguyên** | Hoàn thiện màn hình ghi chú (`notes`) và quản lý `assets` | **XONG (P-READ)** — `templates/`, `public/library.php` |
| ~~**Bộ mẫu, kiểm thử, đóng gói OSS**~~ | ~~Bộ dữ liệu 15 trang mẫu + 10 câu hỏi, bộ 6 kịch bản kiểm thử bắt buộc, gói OSS (license/release)~~ | **XONG (P-FINAL)** — `tests/samples/`, `scripts/seed_samples.php`, `scripts/make_sample_images.php`, `docs/SEARCH_QUERIES.md`, `scripts/run_tests.php`, `docs/DEMO.md`, `LICENSE`, `CONTRIBUTING.md`, tag `v1.0.0` |

**Đã chốt trong P-READ:** vai trò `editor`/`viewer` cho **tải lại** và **sửa/xoá ghi chú**
(quyền ép ngay trong SQL), cùng màn hình ghi chú theo từng phiên bản.
**Đã chốt trong P-SEARCH:** chỉ mục đảo tìm kiếm tự xây thay cho `LIKE`/FULLTEXT.
**Đã chốt trong P-LIB:** tạo/đổi tên/xoá thư viện (xoá kèm thư mục ảnh trên đĩa), quản lý thành viên
(bảo vệ dòng `owner` trong SQL), sửa nhãn theo bài, và **xuất bản lưu** ra HTML độc lập.
**Đã chốt trong P-FINAL:** toàn bộ năm chức năng nghiệp vụ **đã xong và đã kiểm chứng tự động**
(bộ 6 bài kiểm thử T1–T6 chạy 6/6 PASS). Đề tài **không còn phase nào để làm**.

### 8f. Kiến trúc P-FINAL (bộ mẫu, kiểm thử tự động, gói OSS)

P-FINAL **không thêm tính năng sản phẩm** — nó dựng bằng chứng kiểm chứng những gì đã có.

**Bộ mẫu `tests/samples/`.** 15 trang tiếng Việt phục vụ bằng
`php -S localhost:8091 -t tests/samples`, phủ đủ các trường hợp biên: bài thường, **trùng
tiêu đề** (`bai-01` / `bai-07` cùng `<title>`), nhiều ảnh, bài dài, bảng + danh sách, trang có
`<script>` / `onclick` / `<iframe>`, trang không có nội dung, chuyển hướng ra ngoài allowlist,
và trang chậm gây timeout. Mỗi trang mang một **cụm khoá riêng** (`mau01webarchive` …) nằm trong
thân để câu hỏi tìm kiếm có kết quả xác định. `scripts/make_sample_images.php` sinh 5 PNG
hợp lệ **không cần thư viện GD** (tự dựng khối PNG + CRC32).

**Nạp mẫu — `scripts/seed_samples.php`.** CLI dùng **tài khoản ứng dụng**, bảo đảm thư viện
**"Kho dữ liệu mẫu"** của `user@demo.vn` tồn tại (tạo bằng đúng các hàm truy vấn mà web dùng),
rồi lưu từng trang qua **`wa_store_page_capture()` — đường lưu trang thật, không chèn SQL tay** —
và in một dòng cho mỗi mục. Đã có đủ 15 trang thì in `already seeded` và thoát mã `0`.

**Mười câu hỏi tìm kiếm — `docs/SEARCH_QUERIES.md`.** Kỳ vọng được **thiết kế từ nội dung mẫu
đã soạn và viết ra trước**, bằng công cụ mô phỏng ngữ nghĩa AND + công thức xếp hạng trên
chính nội dung đó; **sau đó mới** chạy qua `public/search.php` bằng HTTP thật và ghi kết quả
thực tế cạnh kỳ vọng. Bộ 10 câu phủ: cụm chỉ có trong thân, từ nằm ở tiêu đề, gõ không dấu
khớp nội dung có dấu, điều kiện VÀ nhiều từ, lọc thẻ, lọc tên miền, cặp trùng tiêu đề, một
trang có hai phiên bản, câu không có kết quả, và câu xếp hạng tiêu đề đứng trước thân.

**Sáu bài kiểm thử — `scripts/run_tests.php`.** CLI tự bật **hai máy chủ riêng** bằng
`proc_open()` (ứng dụng `18080`, trang mẫu `18091`) nên không đụng tới máy chủ đang chạy; có
`register_shutdown_function()` luôn tắt cả hai. Mọi thao tác đi qua **HTTP thật** với cURL
cookie jar trong thư mục tạm của hệ điều hành — không gọi thẳng hàm PHP. Dọn dẹp **chỉ qua
hành động của chính ứng dụng**: thư viện tạm bị xoá bằng `library_action.php` (hàm này tự dọn
cả thư mục ảnh — **không bao giờ xoá tay tệp trong `public/uploads/`**), tài khoản rác xoá bằng
tài khoản CSDL của ứng dụng. Tên thư viện và tài khoản rác có hậu tố ngẫu nhiên nên **chạy lại
an toàn**.

**Một defect thật do kiểm thử phát hiện (đã sửa).** Bài `T2` làm lộ lỗi trong
`wa_search_snippet()`: `preg_match_all(..., PREG_OFFSET_CAPTURE)` trả về **vị trí tính bằng
BYTE**, nhưng đoạn trích lại cắt bằng `mb_substr()` (tính theo **KÝ TỰ**). Với tiếng Việt có
dấu, cửa sổ đoạn trích lệch đủ xa để `<mark>` không bọc quanh từ khoá. Đã sửa bằng cách quy
đổi byte → ký tự tường minh: `mb_strlen(substr($text, 0, $firstPos), 'UTF-8')`. Quy ước này
đã ghi vào `CONTRIBUTING.md`.

**Một defect thứ hai do kiểm thử phát hiện (đã sửa) — máy chủ test bị sót lại.** Trên
Windows, `proc_open()` với lệnh dạng **chuỗi** sẽ chạy qua `cmd.exe`, nên
`proc_terminate()` chỉ giết vỏ `cmd` mà **không** giết được `php.exe`; mỗi lần chạy để lại
hai tiến trình `php -S` mồ côi. Tệ hơn nữa: phép thăm dò cổng thấy cổng *đã* mở nên bộ
kiểm thử âm thầm chạy trên **máy chủ của lần chạy trước** — bằng chứng "tự bật máy chủ riêng"
khi đó là sai. Đã sửa ba điểm: (1) truyền lệnh dạng **mảng** cho `proc_open()` để PHP chạy
tiến trình trực tiếp, không qua `cmd.exe`; (2) thêm `t_terminate_proc()` dự phòng bằng
`taskkill /F` trên Windows và chờ tiến trình thực sự chết; (3) **từ chối chạy** nếu cổng
18080/18091 đã bị chiếm sẵn, kèm thông báo tiếng Việt chỉ đường dẫn khắc phục. Sau khi sửa,
hai lần chạy liên tiếp **không còn tiến trình nào sót lại** và CSDL trở về đúng trạng thái
trước khi chạy (đối chiếu số dòng từng bảng).

**Một defect thứ ba do kiểm thử phát hiện (đã sửa) — `--force` không nạp lại từ đầu.**
`scripts/seed_samples.php --force` được tài liệu mô tả là *"nạp lại từ đầu (xoá thư viện cũ
trước)"*, nhưng thực tế nó chỉ **nạp chồng lên**. Mỗi lần chạy lại `--force` lại sinh thêm
phiên bản mới, và `bai-05.html` (vốn cố ý có 2 phiên bản) dần biến thành 3, 4, 5… — dữ liệu
mẫu lệch dần khỏi bảng kê. Đã sửa: `--force` giờ **xoá thư viện cũ qua đúng hàm xoá của ứng
dụng** (`wa_library_delete()`, hàm này tự dọn cả thư mục ảnh — **không bao giờ xoá tay tệp
trong `public/uploads/`**) rồi tạo lại thư viện từ đầu. Sau khi sửa, mọi trang về `v1` và chỉ
`bai-05.html` là `v2`, đúng như bảng kê.

**Gói mã nguồn mở.** `LICENSE` (MIT, "the WebArchive contributors"), `CONTRIBUTING.md`
(cách cài, quy ước dự án, cách nhóm làm việc với issue và pull request), `docs/DEMO.md`
(hai kịch bản trình diễn chính thức), `docs/RELEASE_NOTES.md` (ghi chú `v1.0.0`).

Nguyên tắc: **giao diện không cần sửa**, chỉ thay lớp dữ liệu phía sau.

---

### 8g. Kiến trúc P-FIX-UI (sáu lỗi giao diện landing + workspace)

P-FIX-UI **không đụng vào engine, schema hay dữ liệu** — chỉ sửa `templates/` và
CSS/markup trong `public/`. Không chạy `scripts/setup_db.php`, không thêm/xoá dòng CSDL,
không xoá tệp trong `public/uploads/`.

**1. Header khách — hai hành động riêng biệt.** `templates/header_guest.php` thay nút
đơn "Tạo tài khoản" bằng `Đăng nhập` (ghost, `login.php`) + `Đăng ký` (primary,
`register.php`). Xoá luôn quy tắc `@media (min-width: 1024px) { .guest-cta .btn--outline
{ display: none; } }` trong `app.css` vì nó vô hiệu hoá lối đăng nhập trên màn rộng.
`public/login.php` và `public/register.php` bỏ `$hideTopbar = true` nên thanh trên của
layout khách (và cả hai nút) hiện trên **mọi** trang khách, không chỉ trang chủ; khối
`.auth` được đẩy xuống dưới thanh trên cố định bằng
`padding-top: calc(var(--wa-header-h) + var(--wa-space-xl))`.

**2. Lỗi bố cục trang chủ.** (a) Ba thẻ tính năng dùng `grid-auto-rows: 1fr` + khối
`.feature__body` flex để cao bằng nhau và icon/nhãn/tiêu đề/đoạn văn thẳng hàng. (b) Khối
"không gian đọc" trở lại lưới **12 cột** (`span 5` cột chữ, `span 7` cột minh hoạ), ảnh
minh hoạ `width:100%; height:auto`; hai tệp SVG `public/assets/img/reader-diagram.svg` và
`reader-cover.svg` được sửa lại byte UTF-8 hợp lệ (trước đó XML lỗi nên ảnh vỡ và
cột bị dồn). Ngoài ra khối `.hero` được đẩy xuống dưới thanh trên cố định
(`padding-top: calc(var(--wa-header-h) + var(--wa-space-xl))`): trước đó huy hiệu
"Phiên bản …" nằm ở `top=40..68` nên bị thanh trên che kín **hoàn toàn**
(`elementFromPoint` trả về `.topbar__inner`), tức là nhãn phiên bản không bao giờ
nhìn thấy được.

**3. Cụm tài khoản trên thanh trong workspace.** `.topbar` cao đúng `var(--wa-header-h)`
(68px), `.topbar__inner` cao `calc(var(--wa-header-h) - 4px)`; `.topbar__right`,
`.user-chip`, `.user-chip__meta` và `.topbar__logout` đều căn giữa theo trục dọc — đồng
nhất trên `libraries.php`, `library.php`, `search.php`, `save.php`.

**4. Nút "Mở thư viện".** Mọi thẻ thư viện trong `public/libraries.php` dùng chung
`btn btn--dark` (đặc), bỏ nhánh điều kiện `btn--outline` cho thư viện không phải của
chủ sở hữu.

**5. Sidebar không hardcode thư viện.** Xoá mục cống định trỏ tới `library.php?slug=…`
(trước đây là một thư viện không còn tồn tại trong CSDL). Sidebar nay render danh sách
thư viện **động** của người dùng qua `wa_libraries()`, tô sáng mục đang mở theo `?slug=`
(kèm `aria-current="page"`). `public/library.php` không còn slug mặc định — thiếu
`?slug=` thì chuyển hướng về `libraries.php`.

**6. Mọi con số bịa đặt đều lấy từ CSDL.** Mô-đun mới `templates/wa_metrics.php`
(chỉ đọc) cung cấp `wa_metrics_for_libraries()`, `wa_metrics_showcase_snapshot()`,
`wa_metrics_top_tags()`, `wa_metrics_reader_measure()`, `wa_metrics_contrast_ratio()`,
`wa_metrics_library_count()`, `wa_metrics_all_library_ids()`. Đã thay: widget "Kho lưu trữ
2.4 GB" → tổng `SUM(size_bytes)` định dạng bằng `wa_format_mb()`; khối "Bản quyền cá
nhân / Sẵn sàng đồng bộ" → gỡ bỏ; badge "Phiên bản v2.4" → "Phiên bản v1.0.0"; dòng
"1 Chủ sở hữu • 1 Biên tập viên • 1 Người xem" → đếm theo vai trò từ
`wa_library_members()`; các số ở `public/admin/libraries.php` (48 bài viết / 312 ảnh /
2.4 GB), `public/admin/users.php` (số đếm tab), `public/admin/index.php` ("100% nguyên
vẹn"), `public/libraries.php` ("0 liên kết lỗi", "Snapshot HTML/WARC") và khối minh
hoạ bản lưu mẫu ở `public/index.php` (mã snapshot, checksum, dung lượng, số ảnh,
tác giả, thời gian đọc, "3 bộ sưu tập", "100% Tĩnh", "0 liên kết lỗi", "65-75 ký
tự/dòng", "12:1") — tất cả đều tính từ dữ liệu thật.

### 8h. P-FINAL-2 Stage A — hoàn thiện giao diện landing + workspace

Tiếp nối `8g`, xử lý 9 điểm người dùng rà soát lại từ ảnh chụp màn hình ngày 24/10.
Vẫn **không** đụng engine/schema/dữ liệu, **không** chạy `scripts/setup_db.php`,
**không** xoá tệp trong `public/uploads/`.

**A1 — Ô "ảnh minh hoạ" trong mock hero.** Ô xanh nhạt trống bị xoá. Thay bằng khối
`.mock__figure` có nội dung thật: tiêu đề ảnh, 4 dòng chữ mờ, và **3 thumbnail thật**
đã tải về máy chủ (`wa_metrics_snapshot_thumbs()` đọc kích thước pixel bằng
`getimagesize()`). Ảnh mẫu của dự án chỉ **64×64 / 136 byte** nên tuyệt đối không
phóng to thành khối lớn (sẽ méo) — hiển thị đúng 64×64.

**A2 — Sơ đồ "Không gian tập trung".** `public/assets/img/reader-diagram.svg` vẽ lại
theo luồng **4 bước ngang**: `Trang Web → HTML tĩnh → MySQL + ảnh cục bộ → Đọc lại
ngoại tuyến`. Ba đường nối **hoàn toàn ngang** (`H` tuyệt đối), mỗi bước một mũi
tên `<marker>` (`orient="auto-start-reverse"`). Bản cũ có 4 đường chéo `l52-58` gây
rối.

**A3 — Panel CTA tối.** `.cta-banner__inner` thành lưới 2 cột từ `1024px`: cột trái
giữ nội dung, cột phải là `.cta-banner__metrics` gồm **4 số liệu thật** từ CSDL
(số thư viện, số trang đã lưu, số bản lưu thành công `ok/total`, tổng dung lượng).

**A4 — Sửa chữ.** "HTML sạch đã bóc tách" → **"HTML sạch đã tách"**; câu trích dẫn
"thông tin phân mảnh" bị **xoá** (thay bằng dòng số liệu thật); nhãn dung lượng
tách rõ **HTML** và **ảnh** (`wa_metrics_showcase_snapshot()` trả thêm
`html_size_label`, `total_size_label`) — nhãn cũ "408 B Snapshot" gộp nhầm byte ảnh
với byte HTML; chân trang "© 2026 WebArchive **Inc.**" → **"© 2026 WebArchive —
Đồ án môn Lập trình Nhóm 9"**.

**A5 — Căn tiêu đề.** `.steps-head` đổi từ `margin: 0 auto` + `text-align: center`
sang căn **trái** (`margin: 0 0` + `text-align: left`). Hero trang chủ **giữ** căn
giữa.

**A6 — Thống nhất chân ba thẻ tính năng.** Cả ba dùng cùng một mẫu: 1 nhãn nhỏ +
1 dòng số liệu thật + 2 chip. (1) `Dung lượng thư viện` = `SUM(size_bytes)` →
**2,9 MB**. (2) `Bản lưu thành công` = **17/20 bản lưu**. (3) `Tổng thời gian đọc` =
**33 phút** (200 từ/phút × 5 ký tự/từ, `wa_metrics_reading_total()`).

**A7 — Thanh workspace.** Nút "Đăng xuất" đổi từ `btn--danger-ghost` (hồng — màu
duy nhất lệch bảng màu navy/teal) sang `.btn--logout` **ghost navy** (viền navy +
chữ navy, hover viền đậm). Thêm nhãn ngữ cảnh `.topbar__context` ("Workspace") cạnh
thương hiệu để thanh trong ứng dụng **đọc khác** thanh khách (`header_guest.php`
không có nhãn này).

**A8 — Sidebar.** Tiêu đề khối "Thư viện của tôi" (trùng mục điều hướng ngay trên)
đổi thành **"Danh sách thư viện"**. Danh sách thư viện được **xếp thụp**: nhỏ hơn
(13px vs 14px), thụt lề một cấp (`padding-left: 20px`), viền trái làm mốc, màu nhạt
hơn. Ô "Kho lưu trữ 2,9 MB / 3 thư viện · 19 bài viết" ở đáy sidebar bị **xoá**
(lặp lại y hệt dải số liệu đầu trang); thay bằng ghi chú nhỏ **"5/67 ảnh cục bộ còn
trên đĩa"** — thông tin **không** có ở dải số liệu.

**A9 — Rà soát số liệu bịa còn lại.** (a) `reader.php`: nhãn "Ngoại tuyến 100%" →
**"3/3 tài nguyên cục bộ"** (`wa_metrics_snapshot_assets()`). (b) Hai chip phông
"Serif"/"Sans" trong mock (trình đọc **không** có chuyển phông) → thay bằng "A- / A+
đổi cỡ chữ" + "Nền sáng / ban đêm" (đúng điều khiển có thật trong `reader.php`).
(c) URL ví dụ `https://themorningnews.org/article/deep-work` (đường dẫn bịa) → URL
**thật** của bản lưu đang minh hoạ. (d) Câu "hiển thị nguyên vẹn 10 năm sau" (cam
kết không kiểm chứng được) → bỏ. (e) `step__note` dùng URL thật.

**Hàm mới trong `templates/wa_metrics.php`** (vẫn chỉ đọc): `wa_metrics_snapshot_thumbs()`,
`wa_metrics_snapshot_assets()`, `wa_metrics_reading_total()`; `wa_metrics_showcase_snapshot()`
bổ sung `image_src`, `html_size_label`, `total_bytes`, `total_size_label`.

**Kiểm chứng Stage A (HTTP thật trên `php -S localhost:8080 -t public`):** trang chủ
HTTP 200 không cảnh báo PHP; đăng nhập `user@demo.vn` → `/libraries.php`,
`/library.php?slug=kho-du-lieu-mau`, `/search.php?q=php`, `/save.php`,
`/reader.php?id=9102` đều 200, 0 lỗi PHP; `/admin/*.php` đăng nhập
`admin@demo.vn` đều 200.

---

## 9. Hệ thống thiết kế

Nguồn: `docs/design-reference/DESIGN.md` và 4 màn hình tham chiếu.

| Token | Giá trị | Dùng cho |
|-------|---------|----------|
| Primary | `#152F4A` | Header, khung cấu trúc |
| Active | `#24476B` | Trạng thái chọn |
| Action | `#315E91` | Nút, liên kết, vòng focus |
| Teal | `#289C91` | Số liệu lưu trữ, tiến độ đọc, huy hiệu |
| Canvas | `#F5F7F9` | Nền không gian làm việc |
| Ivory | `#FAF8F4` | Nền trình đọc |
| Border | `#E2E8F0` | Đường viền 1px |

Chữ: **Inter** cho giao diện, **Merriweather** cho trình đọc (cột tối đa 780px),
biểu tượng **Material Symbols** — luôn đi kèm nhãn chữ tiếng Việt.

### Defect đã sửa trong bản tham chiếu

Trang chủ trong bộ tham chiếu **dùng nhầm header đã đăng nhập** (có user chip, nút “Đăng xuất”,
menu workspace). Trang chủ là trang dành cho **khách**, nên dự án này dùng
`templates/header_guest.php`: chỉ có điều hướng công khai + “Đăng nhập” (ghost) /
“Đăng ký” (primary). Hai kiểu header **không bao giờ trộn lẫn**.