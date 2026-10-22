# WebArchive — Lưu trang Web & đọc lại ngoại tuyến (Đề tài 16)

> **GIAI ĐOẠN GIAO DIỆN — UI phase.**
> Dữ liệu trong dự án là **dữ liệu mẫu**, quy trình **lưu trang** và **tìm kiếm** là **mô phỏng**.
> Chưa có MySQL, chưa tải trang thật, chưa có engine bóc tách/sanitize.

Ứng dụng lưu nội dung (snapshot) của trang web để đọc lại và tìm kiếm về sau, tổ chức trong
thư viện theo chủ đề, gắn nhãn và phân quyền thành viên. Toàn bộ giao diện bằng **tiếng Việt**,
viết bằng **PHP thuần** — không framework, không bước build.

---

## 1. Chạy dự án trên Windows

Yêu cầu: **PHP 8.0 trở lên** (khuyến nghị 8.3). Không cần MySQL để chạy bản demo này.

Mở **PowerShell** tại thư mục gốc dự án và chạy:

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

| Email | Mật khẩu | Vai trò | Quyền |
|-------|----------|---------|-------|
| `user@demo.vn` | `demo123` | Người dùng | Thư viện, lưu trang, tìm kiếm, đọc offline |
| `admin@demo.vn` | `admin123` | Quản trị viên | Như trên **+ khu vực Quản trị hệ thống** |

Mật khẩu lưu bằng `password_hash()` (bcrypt), kiểm tra bằng `password_verify()`.

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
| Trình đọc ngoại tuyến | `/reader.php?id=SNAP-8941` | Đăng nhập |
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
config/demo_users.php          Tài khoản demo (placeholder — sẽ thay bằng MySQL)
src/auth.php                   Session + require_login() / require_admin() / current_user()
src/bootstrap.php              Đường dẫn, nạp src, hàm trợ giúp
src/sample_data.php            TOÀN BỘ dữ liệu mẫu của giao diện
src/mock_capture.php           Mô phỏng trạng thái lưu trang (không tải thật)
templates/                     2 layout dùng chung + trang lỗi 403
public/                        WEB ROOT
  assets/css/app.css           Toàn bộ design token + component (không framework)
  assets/img/                  Logo & ảnh minh hoạ cục bộ (SVG)
docs/design-reference/         Nguồn thiết kế: DESIGN.md + 4 màn hình tham chiếu
```

---

## 5. Phạm vi của giai đoạn này

**Đã có (UI + session):**
- Toàn bộ giao diện 14 trang, tiếng Việt, đúng hệ thiết kế `docs/design-reference/`.
- Đăng nhập/đăng ký/đăng xuất hoạt động thật với **session**.
- Chặn trang theo vai trò (khách → đăng nhập; người dùng thường → trang 403 tiếng Việt).
- Trình đọc có nút **A- / A+**, đổi nền đọc và **tiến độ đọc** thật (JavaScript thuần).
- Bảng tìm kiếm lọc theo thẻ và tên miền, làm nổi bật từ khoá bằng `<mark>`.

**Chưa có (để phase sau):**
- ❌ MySQL — dữ liệu đọc từ `src/sample_data.php`.
- ❌ Tải trang thật — `src/mock_capture.php` chỉ **mô phỏng** trạng thái theo URL.
- ❌ Engine bóc tách tiêu đề/nội dung và sanitize HTML.
- ❌ Tìm kiếm thật — `search.php` lọc trên mảng dữ liệu mẫu.
- ❌ Trang đăng ký **không lưu** tài khoản vào CSDL; chỉ kiểm tra dữ liệu và báo thành công.
- ❌ Các nút "tạo thư viện", "thêm tên miền", "mời người dùng" chỉ hiển thị giao diện.

---

## 6. Cách trải nghiệm nhanh

1. Mở `http://localhost:8080` — trang chủ dành cho **khách**.
2. Bấm **Đăng nhập**, dùng `user@demo.vn` / `demo123` → vào `libraries.php`.
3. Mở thư viện **CNTT & Lập Trình** → xem thẻ bài viết, bảng thành viên, bảng lưu URL.
4. **Đọc bản đã lưu** → trình đọc nền giấy ngà, thử **A- / A+** và đổi nền đọc.
5. Vào **Lưu trang Web** → thử các URL mẫu để xem 4 trạng thái:
   - `https://phptutorial.vn/bai-viet` → thành công
   - `https://khong-duoc-duyet.vn/bai-viet` → không thuộc danh sách được duyệt
   - `https://phptutorial.vn/bai-viet-404` → trang gốc trả về 404
   - `https://techblog.dev/bai-viet-timeout` → hết thời gian chờ
6. Đăng xuất, đăng nhập bằng `admin@demo.vn` / `admin123` → thấy mục **Quản trị hệ thống** trong sidebar.

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