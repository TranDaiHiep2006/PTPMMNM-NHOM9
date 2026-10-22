# WebArchive — Lưu trang Web & đọc lại ngoại tuyến (Đề tài 16)

> **GIAI ĐOẠN CƠ SỞ DỮ LIỆU — P-DB phase.**
> Dữ liệu nằm trong **MySQL** (database `webarchive`). Đăng nhập/đăng ký, thư viện, thẻ bài
> viết, trình đọc, tìm kiếm và khu vực quản trị đều đọc từ bảng thật.
> Quy trình **lưu trang** vẫn là **mô phỏng** (engine tải/sanitize là phase sau).

Ứng dụng lưu nội dung (snapshot) của trang web để đọc lại và tìm kiếm về sau, tổ chức trong
thư viện theo chủ đề, gắn nhãn và phân quyền thành viên. Toàn bộ giao diện bằng **tiếng Việt**,
viết bằng **PHP thuần** — không framework, không bước build.

---

## 1. Chạy dự án trên Windows

Yêu cầu: **PHP 8.0 trở lên** (khuyến nghị 8.3) và **MySQL Server 8.0**.

Bật các extension PHP cần thiết trong `php.ini`: `mbstring`, `pdo_mysql`, `mysqli`.

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
src/mock_capture.php           Mô phỏng trạng thái lưu trang (không tải thật)
templates/                     2 layout dùng chung + trang lỗi 403 + trang lỗi CSDL
public/                        WEB ROOT
  assets/css/app.css           Toàn bộ design token + component (không framework)
  assets/img/                  Logo & ảnh minh hoạ cục bộ (SVG)
docs/design-reference/         Nguồn thiết kế: DESIGN.md + 4 màn hình tham chiếu
```

---

## 5. Phạm vi của giai đoạn này

**Đã có (CSDL + session):**
- MySQL thật qua PDO; 10 bảng InnoDB utf8mb4 với khoá ngoại và ràng buộc UNIQUE.
- Đăng nhập/đăng ký/đăng xuất đối chiếu bảng `users`; **đăng ký ghi thật** vào CSDL.
- Thư viện, chi tiết thư viện, trình đọc, tìm kiếm và khu vực quản trị đọc từ bảng thật.
- Form **thêm tên miền được duyệt** ghi thật vào bảng `allowlist_domains` và còn nguyên sau khi tải lại trang.
- Quyền truy cập cơ bản: người dùng chỉ thấy thư viện mình sở hữu / là thành viên; quản trị thấy tất cả.
- Mọi truy vấn có tham số đều dùng **prepared statement** — không nối chuỗi SQL với dữ liệu người dùng.
- Trình đọc có nút **A- / A+**, đổi nền đọc và **tiến độ đọc** thật (JavaScript thuần).
- Biểu mẫu lưu trang vẫn **mô phỏng** trạng thái theo allowlist/404/timeout.

**Chưa có (để phase sau):**
- ❌ Tải trang thật — `src/mock_capture.php` chỉ **mô phỏng** trạng thái theo URL.
- ❌ Engine bóc tách tiêu đề/nội dung và sanitize HTML.
- ❌ Tải ảnh về máy chủ và ghi vào bảng `assets` khi lưu trang.
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
7. Vào **Lưu trang Web** → thử các URL mẫu để xem 4 trạng thái:
   - `https://phptutorial.vn/bai-viet` → thành công
   - `https://khong-duoc-duyet.vn/bai-viet` → không thuộc danh sách được duyệt
   - `https://phptutorial.vn/bai-viet-404` → trang gốc trả về 404
   - `https://techblog.dev/bai-viet-timeout` → hết thời gian chờ
8. Đăng xuất, đăng nhập bằng `admin@demo.vn` / `admin123` → thấy mục **Quản trị hệ thống** trong sidebar,
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