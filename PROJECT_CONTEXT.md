# PROJECT_CONTEXT.md — WebArchive (Đề tài 16)

> Tài liệu ngữ cảnh dự án. Giai đoạn hiện tại: **P-SEARCH — tìm kiếm tự xây trên chỉ mục đảo**.
> `capture_url()` vẫn là engine tải trang thật; phần ghi CSDL đã gom về một hàm
> dùng chung `wa_store_page_capture()` mà cả `save.php` lẫn `refetch.php` đều gọi.
> Tìm kiếm **KHÔNG còn** quét `LIKE` và **KHÔNG** dùng FULLTEXT / `MATCH ... AGAINST`:
> `public/search.php` đọc bảng chỉ mục `search_terms` do dự án tự dựng (`src/search.php`).
> Ghi chú / xuất kho vẫn để các phase sau (xuất kho chưa làm).

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
| Đăng ký | `public/register.php` | Khách | Biểu mẫu đầy đủ, kiểm tra dữ liệu, trạng thái thành công (chưa lưu CSDL) |
| Đăng xuất | `public/logout.php` | — | Xoá session, quay về trang chủ |
| Thư viện của tôi | `public/libraries.php` | Đăng nhập | Số liệu, tab lọc, lưới thẻ thư viện, tạo thư viện (UI) |
| Chi tiết thư viện | `public/library.php?slug=...` | Đăng nhập | Breadcrumb, số liệu, thành viên, nhãn, thẻ bài viết (kèm huy hiệu "N phiên bản"), mục "Lưu thất bại gần đây", phân trang |
| Lưu trang Web | `public/save.php` | Chủ sở hữu / biên tập viên | Chọn thư viện + URL + nhãn → `wa_store_page_capture()`, chuyển tới trình đọc kèm số phiên bản |
| Tải lại phiên bản | `public/refetch.php` | Chủ sở hữu / biên tập viên (POST) | Gọi CHUNG `wa_store_page_capture()` với `source_url` đã lưu; thành công chuyển tới bản mới, thất bại ở lại bản hiện tại kèm lỗi riêng |
| Ghi chú | `public/note.php` | Đăng nhập (POST) | Thêm / sửa / xoá ghi chú của một snapshot; sở hữu ép bằng `user_id` trong SQL |
| Trình đọc | `public/reader.php?id=<snapshot id>` | Đăng nhập + thành viên thư viện | Đầu nguồn gốc, bộ chuyển phiên bản, nút "Lấy phiên bản mới", bảng ghi chú, A-/A+, tiến độ đọc |
| Tìm kiếm | `public/search.php` | Đăng nhập | Từ khoá, lọc theo thẻ/tên miền, kết quả có `<mark>` + thư viện + phiên bản snapshot. Truy vấn trên **chỉ mục đảo** `search_terms` (`wa_search_snapshots()`), **không** `LIKE`, **không** FULLTEXT |
| Tổng quan hệ thống | `public/admin/index.php` | Quản trị | Tổng users/libraries/snapshots/assets/lỗi, nhật ký hoạt động |
| Người dùng | `public/admin/users.php` | Quản trị | Bảng tài khoản, vai trò, trạng thái, số thư viện, thao tác (UI) |
| Tên miền được duyệt | `public/admin/allowlist.php` | Quản trị | Bảng allowlist + biểu mẫu thêm tên miền (UI) + ghi chú quy tắc |
| Tất cả thư viện | `public/admin/libraries.php` | Quản trị | Bảng thư viện toàn hệ thống, chủ sở hữu, hiển thị, số bài/ảnh |

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
config/db.php                  Nạp cấu hình, dựng DSN (KHÔNG chứa mật khẩu)
config/db.local.php            Thông tin kết nối thật — .gitignore, KHÔNG commit
src/db.php                     PDO singleton, chẩn đoán lỗi, trang lỗi tiếng Việt
src/auth.php                   Session, require_login(), require_admin(), current_user(), CSRF (wa_csrf_token/wa_csrf_check)
src/bootstrap.php              Hằng số đường dẫn, nạp src, hàm trợ giúp (wa_url/wa_e/...), bảng bỏ dấu tiếng Việt dùng chung (wa_vi_alphabet/wa_vi_fold_map)
src/queries.php                TOÀN BỘ truy vấn CSDL + wa_store_page_capture() (đường lưu dùng chung, gọi lập chỉ mục) + phiên bản/ghi chú
src/search.php                 TÌM KIẾM TỰ XÂY: tokenizer, bỏ dấu, wa_search_index_snapshot(), truy vấn AND + xếp hạng, đoạn trích <mark>
src/capture.php                ENGINE TẢI TRANG THẬT: capture_url() (allowlist → cURL → DOM → sanitize → tải ảnh cục bộ)
tests/fixtures/                Trang thử cho fixture server (php -S localhost:8090 -t tests/fixtures)
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
docs/design-reference/         Nguồn thiết kế (DESIGN.md + 4 màn hình tham chiếu)
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

---

## 8. Các phase sau cắm vào thế nào

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

### 8d. Các phase sau cắm vào thế nào

| Phase | Cần làm | Chỗ cắm |
|-------|---------|----------|
| ~~**Engine tải trang**~~ **XONG (P-CAPTURE)** | Pipeline thật: cURL → allowlist (kể cả chuỗi redirect) → bóc tách → sanitize → tải ảnh cục bộ → INSERT `saved_pages`/`snapshots`/`assets` | `src/capture.php` (thay `src/mock_capture.php`), `public/save.php` |
| ~~**Lưu trữ & tìm kiếm**~~ **XONG (P-SEARCH)** | Chỉ mục đảo tự dựng `search_terms` (không FULLTEXT): tokenizer + bỏ dấu + lập chỉ mục tự động khi lưu, truy vấn AND + xếp hạng + lọc quyền trong SQL, đoạn trích `<mark>` | `src/search.php`, `database/schema.sql`, `scripts/migrate_search.php`, `scripts/reindex_search.php`, `src/bootstrap.php` (`wa_vi_fold_map()`), `src/queries.php` (hook lập chỉ mục) |
| **Phân quyền nâng cao** | Áp dụng vai trò `editor`/`viewer` khi sửa, xoá, xuất kho hoặc tải lại | `src/auth.php`, `src/queries.php` |
| **Ghi chú & tài nguyên** | Hoàn thiện màn hình ghi chú (`notes`) và quản lý `assets` | `templates/`, `public/library.php` |

**Đã chốt trong P-READ:** vai trò `editor`/`viewer` cho **tải lại** và **sửa/xoá ghi chú**
(quyền ép ngay trong SQL), cùng màn hình ghi chú theo từng phiên bản.
**Đã chốt trong P-SEARCH:** chỉ mục đảo tìm kiếm tự xây thay cho `LIKE`/FULLTEXT.
**Còn lại cho phase sau:** xuất kho (nút "Xuất PDF" hiện mới gọi `window.print()`).

Nguyên tắc: **giao diện không cần sửa**, chỉ thay lớp dữ liệu phía sau.

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
`templates/header_guest.php`: chỉ có điều hướng công khai + “Đăng nhập” / “Tạo tài khoản”.
Hai kiểu header **không bao giờ trộn lẫn**.