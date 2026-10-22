# WebArchive — Lưu trang Web & đọc lại ngoại tuyến (Đề tài 16)

> **GIAI ĐOẠN LƯU TRANG THẬT — P-CAPTURE phase.**
> Dữ liệu nằm trong **MySQL** (database `webarchive`). Quy trình lưu trang đã là **bản thật**:
> `public/save.php` gọi engine `capture_url()` trong `src/capture.php` (cURL + DOMDocument),
> kiểm tra allowlist trước mọi kết nối, tải ảnh về `public/uploads/snapshots/…`.

Ứng dụng lưu nội dung (snapshot) của trang web để đọc lại và tìm kiếm về sau, tổ chức trong
thư viện theo chủ đề, gắn nhãn và phân quyền thành viên. Toàn bộ giao diện bằng **tiếng Việt**,
viết bằng **PHP thuần** — không framework, không bước build.

---

## 1. Chạy dự án trên Windows

Yêu cầu: **PHP 8.0 trở lên** (khuyến nghị 8.3) và **MySQL Server 8.0**.

Bật các extension PHP cần thiết trong `php.ini`: `mbstring`, `pdo_mysql`, `mysqli`,
`curl`, `fileinfo`, `openssl` (engine tải trang cần `curl` + `dom` + `fileinfo`).

> Trên Windows, nếu HTTPS báo lỗi CA, đặt
> `curl.cainfo = "C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt"` trong `php.ini`.

### 1.1. Cài đặt cơ sở dữ liệu (chạy MỘT LẦN, có thể chạy lại)

Mở **PowerShell** tại thư mục gốc dự án và đặt thông tin quản trị MySQL:

```powershell
$env:WA_ADMIN_USER = 'root'
$env:WA_ADMIN_PASS = '<mat khau MySQL cua ban>'
php scripts/setup_db.php
```

Kịch bản này sẽ:

1. Tạo database `webarchive` (utf8mb4 / utf8mb4_unicode_ci) nếu chưa có.
2. Tạo tài khoản MySQL riêng `'webarchive'@'localhost'` chỉ có quyền trên `webarchive.*`,
   với mật khẩu ngẫu nhiên, rồi ghi vào `config/db.local.php`.
3. Áp dụng `database/schema.sql` rồi `database/seed.sql` (xoá và nạp lại riêng database này).
4. In ra số dòng của từng bảng.

> **Bí mật:** `config/db.local.php` đã được `.gitignore` và **không bao giờ commit**.
> Mật khẩu quản trị `WA_ADMIN_PASS` chỉ tồn tại trong phiên PowerShell, không ghi ra tệp.
> Ứng dụng **luôn** kết nối bằng tài khoản riêng `webarchive`, không dùng tài khoản quản trị.

Chạy lại `setup_db.php` bất cứ lúc nào để **cài lại sạch** dữ liệu mẫu.

### 1.2. Chạy máy chủ web

```powershell
php -S localhost:8080 -t public
```

Sau đó mở trình duyệt tại: **http://localhost:8080**

> Lệnh phải chạy từ **thư mục gốc dự án** (`D:\PTPMMNM-NHOM9`) vì các trang nạp
> `src/`, `templates/`, `config/` nằm ngoài `public/` (public là web root).
>
> Nếu `php` chưa có trong PATH: mở PowerShell mới sau khi cài, hoặc dùng đường dẫn đầy đủ,
> ví dụ `"C:\Program Files\PHP\php.exe" -S localhost:8080 -t public`.

Nhấn `Ctrl + C` để dừng máy chủ.

---

## 2. Tài khoản demo

Đây là **tài khoản thật trong bảng `users`** của MySQL, mật khẩu lưu bằng
`password_hash()` (bcrypt), kiểm tra bằng `password_verify()`.

| Email | Mật khẩu | Vai trò | Quyền |
|-------|----------|---------|-------|
| `user@demo.vn` | `demo123` | Người dùng | Thư viện, lưu trang, tìm kiếm, đọc offline |
| `admin@demo.vn` | `admin123` | Quản trị viên | Như trên **+ khu vực Quản trị hệ thống** |

Dữ liệu mẫu còn có thêm 3 tài khoản phụ (`ha.nguyen@demo.vn`, `minh.vu@demo.vn` — đang bị
khoá, `linh.pham@demo.vn`) dùng để minh hoạ màn hình quản trị.

**Khi đăng nhập bằng tài khoản thường và mở trang quản trị** sẽ thấy trang tiếng Việt
“Không có quyền truy cập”. Khi là **khách** và mở bất kỳ trang workspace nào, hệ thống
**chuyển hướng** về `login.php`.

---

## 3. Bản đồ trang

| Trang | Đường dẫn | Quyền |
|-------|-----------|-------|
| Trang chủ | `/index.php` | Khách |
| Đăng nhập | `/login.php` | Khách |
| Đăng ký | `/register.php` | Khách |
| Đăng xuất | `/logout.php` | Đã đăng nhập |
| Thư viện của tôi | `/libraries.php` | Đăng nhập |
| Chi tiết thư viện | `/library.php?slug=cntt-lap-trinh` | Đăng nhập |
| Lưu trang Web | `/save.php` | Đăng nhập |
| Trình đọc ngoại tuyến | `/reader.php?id=8941` | Đăng nhập |
| Tìm kiếm nội dung | `/search.php?q=snapshot` | Đăng nhập |
| Tổng quan hệ thống | `/admin/index.php` | **Quản trị** |
| Người dùng | `/admin/users.php` | **Quản trị** |
| Tên miền được duyệt | `/admin/allowlist.php` | **Quản trị** |
| Tất cả thư viện | `/admin/libraries.php` | **Quản trị** |

---

## 4. Cấu trúc dự án

```
prompt.md                      Đặc tả giai đoạn này
PROJECT_CONTEXT.md             Tóm tắt đặc tả, bản đồ trang, cách các phase sau cắm vào
database/schema.sql            Lược đồ MySQL (10 bảng, InnoDB, utf8mb4, khoá ngoại)
database/seed.sql              Dữ liệu mẫu (INSERT) — chạy lại được nhiều lần
scripts/setup_db.php           Cài đặt CSDL + tài khoản riêng + nạp seed (CLI, idempotent)
config/db.php                  Nạp cấu hình CSDL, dựng DSN (KHÔNG chứa mật khẩu)
config/db.local.php            Thông tin kết nối thật — .gitignore, KHÔNG commit
src/db.php                     PDO singleton + trang lỗi tiếng Việt khi CSDL lỗi
src/auth.php                   Session + require_login() / require_admin() / current_user()
src/bootstrap.php              Đường dẫn, nạp src, hàm trợ giúp, polyfill mbstring
src/queries.php                Toàn bộ truy vấn CSDL (thay thế src/sample_data.php)
src/capture.php                Engine lưu trang THẬT (cURL + DOMDocument + sanitizer + tải ảnh)
templates/                     2 layout dùng chung + trang lỗi 403 + trang lỗi CSDL
public/                        WEB ROOT
  assets/css/app.css           Toàn bộ design token + component (không framework)
  assets/img/                  Logo & ảnh minh hoạ cục bộ (SVG)
  uploads/snapshots/<id>/      Ảnh đã tải của từng snapshot (.gitignore, chỉ giữ .gitkeep)
tests/fixtures/                Trang thử (article.html, pic.png, redir-*.php, slow.php, notice.html)
docs/design-reference/         Nguồn thiết kế: DESIGN.md + 4 màn hình tham chiếu
```

---

## 5. Engine lưu trang thật

`src/capture.php` cung cấp `capture_url(string $url): array` — không dùng thư viện ngoài,
chỉ `cURL` + `DOMDocument`. Pipeline theo thứ tự:

1. Kiểm tra URL: chỉ `http`/`https`, bắt buộc có host, từ chối URL chứa thông tin đăng nhập.
2. **Allowlist trước khi gửi bất kỳ byte nào** — host khớp đúng một domain trong
   `allowlist_domains`, hoặc kết thúc bằng `.` + domain (không phân biệt hoa thường).
   Lệch allowlist → `error_blocked`, **không gửi HTTP request nào**.
3. cURL **không** tự đi theo redirect; ứng dụng tự đi tối đa **5** bước và **kiểm tra lại
   allowlist ở từng đích redirect** trước khi tải. Kết nối **5 s**, tổng **12 s**.
   Dừng tải nếu vượt **5 MB**.
4. 404 → `error_404`; 4xx/5xx khác → `error_http`; `Content-Type` không phải HTML → `error_no_content`.
5. Bóc tách bằng `DOMDocument`: tiêu đề `og:title` → `<title>` → `<h1>`; thân lấy `<article>` →
   `<main>` → div/section có điểm mật độ chữ cao nhất; gỡ `script/style/noscript/iframe/
   form/nav/header/footer/aside/svg/button/input` trước khi trích.
6. Text dưới **200 ký tự** → `error_no_content`.
7. **Sanitizer whitelist tự viết** — chỉ giữ tag/thuộc tính được duyệt, mọi link thêm
   `rel="noopener noreferrer"`, link không phải http/https bị bỏ nhưng giữ chữ.
8. **Ảnh tải về máy chủ**: tối đa 20 ảnh, `src` giải phân giải thành URL tuyệt đối, kiểm tra
   allowlist host, cap 5 MB, chỉ nhận JPEG/PNG/GIF/WebP (kiểm bằng `finfo`, **không tin
   phần mở rộng**, không SVG). Lưu vào `public/uploads/snapshots/<snapshot_id>/<hash>.<ext>`,
   ghi bảng `assets`, và `img src` được đổi thành đường dẫn cục bộ — sau bước này nội dung
   **không còn URL ảnh ngoài**.

Lỗi vẫn được **ghi lại** vào CSDL (snapshot với `fetch_status = error_code`) để thống kê quản
trị đếm được, nhưng danh sách thư viện, tìm kiếm và trình đọc **chỉ lấy snapshot có
`fetch_status = 'success'`** — trang lỗi không bao giờ hiện như bài đọc được.

**Ghi chú:** `localhost` được giữ trong `allowlist_domains` làm **host thử nghiệm cục bộ**
cho fixture server (`php -S localhost:8090 -t tests/fixtures`). Nếu không muốn thử nghiệm
trang nội bộ thì xoá dòng đó trong `/admin/allowlist.php`.

---

## 6. Phạm vi của giai đoạn này

**Đã có (CSDL + session):**
- MySQL thật qua PDO; 10 bảng InnoDB utf8mb4 với khoá ngoại và ràng buộc UNIQUE.
- Đăng nhập/đăng ký/đăng xuất đối chiếu bảng `users`; **đăng ký ghi thật** vào CSDL.
- Thư viện, chi tiết thư viện, trình đọc, tìm kiếm và khu vực quản trị đọc từ bảng thật.
- Form **thêm tên miền được duyệt** ghi thật vào bảng `allowlist_domains` và còn nguyên sau khi tải lại trang.
- Quyền truy cập cơ bản: người dùng chỉ thấy thư viện mình sở hữu / là thành viên; quản trị thấy tất cả.
- Mọi truy vấn có tham số đều dùng **prepared statement** — không nối chuỗi SQL với dữ liệu người dùng.
- Trình đọc có nút **A- / A+**, đổi nền đọc và **tiến độ đọc** thật (JavaScript thuần).
- Biểu mẫu lưu trang dùng **engine thật** (xem mục 5): cURL + allowlist + DOMDocument + tải ảnh cục bộ.

**Chưa có (để phase sau):**
- ❌ Tải trang thật — ~~`src/mock_capture.php` chỉ **mô phỏng** trạng thái theo URL.~~ **ĐÃ LÀM (P-CAPTURE).**
- ~~❌ Engine bóc tách tiêu đề/nội dung và sanitize HTML.~~ **ĐÃ LÀM (P-CAPTURE).**
- ~~❌ Tải ảnh về máy chủ và ghi vào bảng `assets` khi lưu trang.~~ **ĐÃ LÀM (P-CAPTURE).**
- ❌ Tìm kiếm thật — `search.php` hiện dùng `LIKE` trên `snapshots.title` / `content_text`.
- ❌ Các nút "tạo thư viện", "mời người dùng" chỉ hiển thị giao diện.
- ❌ Siết chặt phân quyền editor/viewer (hiện mới lọc ở mức thư viện).

---

## 6. Cách trải nghiệm nhanh

1. Chạy `php scripts/setup_db.php` một lần để tạo CSDL (xem mục 1.1).
2. Mở `http://localhost:8080` — trang chủ dành cho **khách**.
3. Bấm **Đăng nhập**, dùng `user@demo.vn` / `demo123` → vào `libraries.php`.
4. Mở thư viện **CNTT & Lập Trình** → xem thẻ bài viết, bảng thành viên, bảng lưu URL.
5. **Đọc bản đã lưu** → trình đọc nền giấy ngà, thử **A- / A+** và đổi nền đọc.
6. Vào **Tìm kiếm** (`/search.php?q=snapshot`) → xem đoạn trích có từ khoá được làm nổi bật.
7. Mở terminal thứ hai tại thư mục gốc và chạy fixture server:
   `php -S localhost:8090 -t tests/fixtures`
8. Vào **Lưu trang Web** → chọn thư viện và dán URL để thử từng trường hợp:
   - `http://localhost:8090/article.html` → thành công, ảnh tải về `/uploads/snapshots/…`
   - `http://localhost:8090/redir-ok.php` → thành công (đi qua chuyển hướng trong allowlist)
   - `http://localhost:8090/redir-escape.php` → chuyển hướng ra ngoài allowlist → bị chặn
   - `http://localhost:8090/khong-ton-tai.html` → trang gốc trả về 404
   - `http://localhost:8090/slow.php` → hết thời gian chờ (giới hạn 12 s)
   - `http://localhost:8090/notice.html` → nội dung quá ngắn
   - `https://example.com/` → không thuộc danh sách được duyệt
9. Đăng xuất, đăng nhập bằng `admin@demo.vn` / `admin123` → thấy mục **Quản trị hệ thống** trong sidebar,
   thêm một tên miền ở `/admin/allowlist.php` và tải lại trang để thấy nó vẫn còn.

---

## 7. Ghi chú thiết kế

- Nguồn thiết kế: `docs/design-reference/DESIGN.md` và 4 màn hình tham chiếu trong cùng thư mục
  (các tệp `code*.html` / `screen*.png` được commit để tiện tra cứu).
- Giao diện dùng **một** stylesheet cục bộ `public/assets/css/app.css` với CSS variables —
  **không dùng** Tailwind CDN hay framework CSS nào.
- Chữ **Inter** (giao diện) và **Merriweather** (trình đọc) tải từ Google Fonts, có phông
  hệ thống dự phòng nên demo vẫn hiển thị tốt khi offline.
- Biểu tượng **Material Symbols** luôn đi kèm nhãn chữ tiếng Việt.
- **Defect đã sửa:** trang chủ trong bộ tham chiếu dùng nhầm header đã đăng nhập. Trang chủ
  là trang dành cho khách, nên dự án này dùng header khách (chỉ có “Đăng nhập” / “Tạo tài khoản”).
  Hai kiểu header không bao giờ trộn lẫn.