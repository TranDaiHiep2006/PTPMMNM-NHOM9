# PROJECT_CONTEXT.md — WebArchive (Đề tài 16)

> Tài liệu ngữ cảnh dự án. Giai đoạn hiện tại: **UI + auth shell (session)**.
> Engine lưu trữ và MySQL sẽ được cài đặt ở các phase sau.

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
| Chi tiết thư viện | `public/library.php?slug=...` | Đăng nhập | Breadcrumb, số liệu, thành viên, nhãn, thẻ bài viết, phân trang, bảng lưu URL |
| Lưu trang Web | `public/save.php` | Đăng nhập | Bảng nhập URL + 4 trạng thái mô phỏng (thành công / ngoài allowlist / 404 / timeout) |
| Trình đọc | `public/reader.php?id=...` | Đăng nhập | Nền giấy ngà, cột 780px, A-/A+, tiến độ đọc, hộp nguồn gốc, bài viết mẫu |
| Tìm kiếm | `public/search.php` | Đăng nhập | Từ khoá, lọc theo thẻ/tên miền, kết quả có `<mark>` + thư viện + phiên bản snapshot |
| Tổng quan hệ thống | `public/admin/index.php` | Quản trị | Tổng users/libraries/snapshots/assets/lỗi, nhật ký hoạt động |
| Người dùng | `public/admin/users.php` | Quản trị | Bảng tài khoản, vai trò, trạng thái, số thư viện, thao tác (UI) |
| Tên miền được duyệt | `public/admin/allowlist.php` | Quản trị | Bảng allowlist + biểu mẫu thêm tên miền (UI) + ghi chú quy tắc |
| Tất cả thư viện | `public/admin/libraries.php` | Quản trị | Bảng thư viện toàn hệ thống, chủ sở hữu, hiển thị, số bài/ảnh |

**Bảo vệ trang:** mọi trang workspace/reader/search/admin đều gọi `require_login()`.
Các trang admin gọi thêm `require_admin()`; người dùng thường nhận trang
“Không có quyền truy cập” bằng tiếng Việt (`templates/error_no_permission.php`).

---

## 5. Cấu trúc mã nguồn

```
prompt.md                      Đặc tả giai đoạn này
PROJECT_CONTEXT.md             Tài liệu này
README.md                      Hướng dẫn chạy
config/demo_users.php          Tài khoản demo (PLACEHOLDER — sẽ thay bằng MySQL)
src/auth.php                   Session, require_login(), require_admin(), current_user()
src/bootstrap.php              Hằng số đường dẫn, nạp src, hàm trợ giúp (wa_url/wa_e/...)
src/sample_data.php            TOÀN BỘ dữ liệu mẫu của giao diện
src/mock_capture.php           MÔ PHỎNG quy trình lưu trang (không tải thật)
templates/header_guest.php     Layout khách: thanh trên + <main>   (KHÔNG có user chip)
templates/footer_guest.php     Đóng layout khách + chân trang
templates/header_app.php       Layout ứng dụng: thanh trên + sidebar
templates/footer_app.php       Đóng layout ứng dụng
templates/error_no_permission.php   Trang 403 tiếng Việt
public/                        WEB ROOT
public/assets/css/app.css      Toàn bộ design token + component (không framework)
public/assets/img/             Logo SVG, ảnh minh hoạ cục bộ
docs/design-reference/         Nguồn thiết kế (DESIGN.md + 4 màn hình tham chiếu)
```

---

## 6. Tài khoản demo

| Email | Mật khẩu | Vai trò | Tên hiển thị |
|-------|----------|---------|--------------|
| `user@demo.vn` | `demo123` | Người dùng | Lê Minh Tuấn |
| `admin@demo.vn` | `admin123` | Quản trị viên | Trần Hoàng Nam |

Mật khẩu được lưu bằng `password_hash()` (bcrypt) và kiểm tra bằng `password_verify()`.
> **Đây là dữ liệu giả lập cho giao diện**, sẽ bị thay bằng bảng `users` ở phase MySQL.

---

## 7. Quy tắc mô phỏng lưu trang (UI phase)

`src/mock_capture.php` **không tải trang thật**. Trạng thái được suy ra từ URL:

| Điều kiện | Trạng thái | Thông báo tiếng Việt |
|-----------|-----------|----------------------|
| Tên miền trong allowlist, đường dẫn không chứa `404`/`timeout` | Thành công | “Lưu thành công: …” |
| Tên miền **không** trong allowlist | Lỗi | “URL không thuộc danh sách được duyệt” |
| Đường dẫn chứa `404` | Lỗi | “Trang gốc trả về 404” |
| Đường dẫn chứa `timeout` | Lỗi | “Hết thời gian chờ khi tải trang” |

Bảng lưu URL trên `library.php` **luôn hiển thị trạng thái thành công**, đúng như bản tham chiếu.
Trang `save.php` trình diễn đủ cả bốn trạng thái.

---

## 8. Các phase sau cắm vào thế nào

| Phase | Cần làm | Chỗ cắm |
|-------|---------|----------|
| **Cơ sở dữ liệu** | Thay nội dung mảng trong `src/sample_data.php` bằng truy vấn PDO/MySQL; thay `demo_users()` bằng bảng `users` | `src/sample_data.php`, `config/demo_users.php` |
| **Engine tải trang** | Thay `mock_capture()` bằng pipeline thật: cURL → kiểm tra allowlist (kể cả redirect chain) → bóc tách tiêu đề/nội dung → sanitize → tải ảnh cục bộ | `src/mock_capture.php` → `src/capture_engine.php` |
| **Lưu trữ & tìm kiếm** | Lưu snapshot + chỉ mục nội dung; truy vấn snippet kèm `snapshot_version_id` | `public/search.php` (thay vòng lặp mô phỏng) |
| **Phân quyền nâng cao** | Áp dụng vai trò thật khi xuất kho / tải lại | `src/auth.php` |
| **Đăng ký thật** | Lưu tài khoản vào CSDL | `public/register.php` |

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