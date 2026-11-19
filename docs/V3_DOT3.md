# WEBARCHIVE V3 — ĐỢT 3: CHROME EXTENSION + CHIA SẺ + RSS

Tài liệu cài đặt và kiểm thử thủ công cho ba tính năng mới:

| Mã | Tính năng | File chính |
|----|-----------|-----------|
| **F7** | Chrome Extension Manifest V3 (lưu trang, tạo thư viện, tự động lưu) | `chrome-extension/`, `public/api.php`, `src/v3_api.php`, `public/extension.php` |
| **F8** | Chia sẻ snapshot có thời hạn + giới hạn lượt xem | `src/v3_share.php`, `public/shared.php`, `public/share_action.php` |
| **F9** | RSS/Atom: xem trước feed và lưu bài viết | `src/v3_feeds.php`, `public/feeds.php` |

---

## 0. Migration cần chạy (chạy MỘT lần)

Tất cả bảng mới nằm trong script migration idempotent sẵn có:

```bash
php scripts/migrate_v3.php
```

Script tự kiểm tra `INFORMATION_SCHEMA` nên chạy lại bao nhiêu lần cũng an toàn.
Không chạy trực tiếp `database/schema.sql` (script đó xoá bảng).

Bảng được tạo/thêm:

| Bảng | Mục đích |
|------|----------|
| `api_tokens` | Token riêng cho Chrome Extension (chỉ lưu hash SHA-256) |
| `share_links` | Link chia sẻ (chỉ lưu hash SHA-256 của token) |
| `feed_subscriptions` | Nguồn RSS/Atom đã theo dõi |
| `feed_items` | Các bài thấy trong feed (chống trùng bằng `item_hash`) |

> Lưu ý: `share_links`, `feed_subscriptions`, `feed_items` đã có sẵn trong
> `scripts/migrate_v3.php` từ Đợt 1–2 (chỉ chưa chạy). `api_tokens` là mới.

---

## 1. Cài Chrome Extension (F7)

### 1.1. Chuẩn bị phía máy chủ

1. Mở WebArchive, đăng nhập.
2. Vào menu sidebar **“Kết nối Extension”** (hoặc mở trực tiếp
   `http://<máy-chủ>/extension.php`).
3. Bấm **“Tạo token”** → token thô hiện **đúng một lần**, dán vào Notepad.

### 1.2. Nạp Extension vào Chrome

1. Mở `chrome://extensions`.
2. Bật **Developer mode** (công tắc góc trên bên phải).
3. Bấm **Load unpacked**.
4. Chọn thư mục `chrome-extension/` trong dự án (`D:\PTPMMNM-NHOM9\chrome-extension`).
5. Extension xuất hiện trên thanh công cụ (biểu tượng WebArchive xanh).

### 1.3. Cấu hình trong Extension

1. Bấm biểu tượng WebArchive.
2. Bấm vào dòng trạng thái kết nối (góc trên) để mở tab **Cài đặt**.
3. Điền **Địa chỉ máy chủ WebArchive**: ví dụ `http://localhost:8080`
   (không có dấu `/` ở cuối).
4. Dán **token** đã tạo ở bước 1.1 vào ô *Token kết nối*.
5. Bấm **“Kiểm tra kết nối”** → phải báo *“Kết nối thành công.”*

### 1.4. Quyền của Extension

| Quyền | Vì sao |
|-------|--------|
| `activeTab` | Đọc tiêu đề/URL tab hiện tại (chỉ khi bấm biểu tượng) |
| `storage` | Lưu cấu hình + token cục bộ (không đồng bộ cloud) |
| `optional_host_permissions` | Chỉ được cấp **riêng** cho domain người dùng tự thêm |

> Extension **không** dùng quyền `scripting`, không cần quyền `tabs`, không yêu cầu
> `host_permissions` toàn cục — nhờ vậy khi cài đặt **không** bị hỏi quyền truy cập
> mọi website.

Extension **không** yêu cầu toàn quyền mọi website khi cài đặt, **không** lưu mật
khẩu MySQL / mật khẩu người dùng / cookie phiên.

### 1.5. Cơ chế xác thực

- Token = 32 byte ngẫu nhiên (base64url). Server chỉ lưu **SHA-256**.
- Gửi qua header `Authorization: Bearer <token>` (không đặt trong URL).
- Token có **thời hạn** (mặc định 90 ngày) và **thu hồi được** ngay.
- **Scope**: `read`, `library:create`, `page:save`.
- **Rate limit**: 60 request/phút; riêng hành động lưu trang là 15/phút.
- **CORS**: chỉ trả `Access-Control-Allow-Origin` khi Origin có dạng
  `chrome-extension://<32 ký tự a–p>`. Nếu request mang Origin của website
  thường, API trả **403** ngay. Website thường **không** gọi được API
  (không có Origin hợp lệ, không có token).

---



## 1.6. Chính sách URL công khai (lưu mọi URL, không cần Admin duyệt)



**Nguyên tắc:** người dùng được lưu **bất kỳ URL công khai nào** (http/https) ngay

trong 1 cú bấm. Quản trị viên **không** phải duyệt tên miền trước. Đổi lại, **máy chủ**

tự kiểm tra an toàn trước khi tải bất cứ byte nào.



### Lớp 1 — Chốt an toàn cứng (KHÔNG BAO GIỜ bỏ qua được)



Nằm trong `src/url_policy.php` → `wa_url_policy_inspect()`, được gọi bởi

`capture_url()`, `wa_capture_http_get()`, `wa_bulk_check_url()` (lưu hàng loạt),

`wa_feed_check_url()` (RSS) và `public/api.php` (Extension) — **cùng một chính sách**

cho mọi đường lưu.



| Kiểm tra | Kết quả |

|----------|---------|

| Giao thức | Chỉ `http`/`https`; chặn `file:`, `gopher:`, `ftp:`, `data:`… |

| Thông tin đăng nhập trong URL | Chặn `user:password@host` |

| Tên miền bị chặn tuyệt đối | `localhost`, `*.localhost`, `*.local`, `*.internal`, `*.home.arpa`, `metadata.google.internal`, `169.254.169.254` |

| DNS | Phải phân giải được; **mọi** IP trả về phải là IP công khai |

| IPv4 bị chặn | `0/8`, `10/8`, `100.64/10` (CGNAT), `127/8`, `169.254/16` (link-local + **cloud metadata**), `172.16/12`, `192.168/16`, `192.0.0/24`, `192.0.2/24`, `192.88.99/24`, `198.18/15`, `198.51.100/24`, `203.0.113/24`, `224/4`, `240/4` |

| IPv6 bị chặn | `::/128`, `::1/128`, `::ffff:0:0/96` (mapped), `64:ff9b::/96` (NAT64), `2002::/16` (6to4), `fc00::/7` (ULA), `fe80::/10` (link-local), `ff00::/8` (multicast), `100::/64` (discard) |

| DNS rebinding | IP đã xác minh được **ghim** vào cURL bằng `CURLOPT_RESOLVE` → DNS đổi kết quả giữa lúc kiểm tra và lúc kết nối vẫn nối đúng IP công khai |

| Mọi đích chuyển hướng | Được kiểm tra **lại** ở từng bước (cURL **không** dùng `CURLOPT_FOLLOWLOCATION`) |

| Ảnh trong bài | Cùng chính sách với URL trang |



Vi phạm → `error_blocked` / `blocked: true`, **không** gửi HTTP request nào.



### Lớp 2 — Cảnh báo rủi ro (mềm, có thể xác nhận để tiếp tục)



Chỉ phát sinh khi có **bằng chứng kiểm chứng được**:



| Mã tín hiệu | Điều kiện |

|-------------|-----------|

| `no_tls` | URL dùng `http://` (không mã hoá) |

| `ip_literal` | Host là địa chỉ IP thay vì tên miền |

| `nonstandard_port` | Cổng khác 80/443 |

| `homograph` | Tên miền chứa nhãn punycode `xn--` |



Hệ thống **không** cảnh báo chỉ vì tên miền "chưa có trong CSDL" và **không** tự

đặt điểm rủi ro tuỳ tiện. Khi có tín hiệu, người dùng được hỏi rõ ràng bằng **hai

nút**: **KHÔNG LƯU** (huỷ) và **VẪN LƯU** (tiếp tục). Xác nhận **chỉ** bỏ qua cảnh

báo — **không** bao giờ bỏ qua lớp 1.



- Tên miền nằm trong `allowlist_domains` nay là **danh sách TIN CẬY**: được miễn cảnh báo.

- Trong Extension, chế độ tự động lưu **bỏ qua** mọi trang gặp cảnh báo và báo người

  dùng xác nhận thủ công — không tự vượt cảnh báo.



### Luồng trong Extension



```

bấm "Lưu trang"

   ├─ API: wa_url_policy_inspect() → lỗi an toàn? → 400 blocked (dừng)

   ├─ API: có tín hiệu rủi ro? → 409 { risk: true, signals: [...] }

   │        └─ popup hiện KHÔNG LƯU / VẪN LƯU

   │              └─ "VẪN LƯU" → gửi lại kèm override_risk: true

   └─ lưu thật → trả snapshot_id + link "Mở bản lưu"

```



---



## 2. Kiểm thử F7 (Chrome Extension)

| # | Thao tác | Kết quả mong đợi |
|---|----------|------------------|
| 1 | Mở popup khi WebArchive **đang tắt** | Header báo lỗi kết nối (màu đỏ), danh sách thư viện rỗng, bấm “Lưu trang” ra thông báo lỗi rõ ràng |
| 2 | Mở popup khi WebArchive **đang bật** | Header báo *“Đã kết nối · <tên>”* (màu xanh), danh sách thư viện có dữ liệu thật |
| 3 | Chọn thư viện + nhập nhãn → bấm **Lưu trang** | Thanh tiến độ chạy, thông báo thành công, xuất hiện link **“Mở bản lưu”** → mở đúng `reader.php` |
| 4 | Bấm **“+”** cạnh danh sách thư viện | Chỉ hiện **MỘT ô bắt buộc: Tên thư viện** → bấm **Tạo thư viện** → thông báo thành công, **thư viện mới được tự chọn**, danh sách làm mới, **không chuyển tab / không tải lại popup** |
| 5 | Kiểm tra DB | Thư viện mới có trong bảng `libraries` + `library_members` (role `owner`) |
| 6 | Bấm **Lưu trang** ngay sau khi tạo thư viện (không chuyển tab) | Lưu thành công vào đúng thư viện vừa tạo |
| 7 | Lưu trang ở domain **không** trong allowlist | **Vẫn lưu thành công** — không cần Admin duyệt tên miền (máy chủ tự kiểm tra an toàn) |
| 8 | Lưu lại cùng 1 URL ngay sau lần trước | Báo “vừa được lưu — dùng lại bản lưu hiện có”, **không** tạo phiên bản thừa |
| 9 | Bật **Tự động lưu website** | Mặc định vẫn TẮT; chỉ bật khi người dùng bấm |
| 10 | Thêm domain `example.com` | Chrome hiện hộp thoại xin quyền → bấm **Cho phép** → domain vào danh sách |
| 11 | Mở một trang thuộc domain đã cho phép, đợi tải xong | Trang được tự động lưu vào thư viện mặc định (hoặc thư viện riêng của domain) |
| 12 | Mở trang `localhost` / trang đăng nhập / `.pdf` | **Không** bị tự động lưu (danh sách chặn trong `background.js`) |
| 13 | Bấm **×** ở một domain trong Cài đặt | Domain bị xoá khỏi danh sách theo dõi, không còn tự động lưu |
| 14 | Thu hồi token ở `extension.php` | Extension lập tức báo lỗi token không hợp lệ |
| 15 | Mở popup lần thứ hai sau khi đã lưu | Thư viện đã chọn gần nhất **được chọn sẵn** → bấm **Lưu trang** là xong, không cần chọn lại |
| 16 | Tài khoản **chưa có thư viện nào** → mở popup | Ô **Tạo thư viện** tự động hiện, chỉ cần nhập tên |
| 17 | Lưu trang ở địa chỉ **IP** (ví dụ `http://93.184.216.34/`) | Hiện **cảnh báo rủi ro** với hai nút **KHÔNG LƯU** / **VẪN LƯU** |
| 18 | Bấm **VẪN LƯU** ở cảnh báo | Trang được tải, sanitize và lưu thành snapshot tĩnh như bình thường |
| 19 | Bấm **KHÔNG LƯU** ở cảnh báo | Hủy, **không** tải trang, **không** ghi gì vào CSDL |
| 20 | Lưu trang ở `http://localhost/` hoặc `http://169.254.169.254/` | **Bị chặn** bởi chốt an toàn (mạng nội bộ / cloud metadata) — xác nhận của người dùng **không** bỏ qua được |
| 21 | Bật **Tự động lưu** rồi mở trang có cảnh báo rủi ro | **Không** tự động lưu; Extension báo cho người dùng xác nhận thủ công |

> Không có cron, Task Scheduler hay tiến trình CMD nào được tạo. Tự động lưu chạy
> bằng `chrome.tabs.onUpdated` trong service worker của Extension.

---

## 3. Kiểm thử F8 (Chia sẻ snapshot)

1. Mở một bản lưu bất kỳ: `reader.php?id=<snapshot id>`.
2. Bấm nút **“Chia sẻ”** trên thanh công cụ.
3. Chọn thời hạn (1 giờ / 1 ngày / 7 ngày / tuỳ chỉnh) và giới hạn lượt mở.
4. Bấm **“Tạo link”** → link hiện một lần kèm nút **Sao chép link**.
5. Mở link ở cửa sổ ẩn danh (không đăng nhập) → chỉ thấy nội dung bài viết:
   - Không thấy ghi chú, đánh dấu, thành viên, tên thư viện.
   - Không có nút sửa / xoá / xuất bản quản trị.
6. Bấm **“Thu hồi”** ở danh sách link trong modal → mở lại link → báo
   *“Link chia sẻ đã bị thu hồi.”*
7. Đặt giới hạn = 1 lượt → mở link lần 1 OK, lần 2 báo
   *“Link chia sẻ đã hết lượt xem cho phép.”*

### Ghi chú kỹ thuật

- Token 32 byte ngẫu nhiên; server chỉ lưu SHA-256.
- Lượt xem tăng bằng `UPDATE … WHERE max_views IS NULL OR view_count < max_views`
  → an toàn khi nhiều người mở đồng thời, không bao giờ vượt giới hạn.
- HTML trong bản chia sẻ được làm sạch lại (`wa_share_sanitize_html()`): bỏ
  `<script>`, `<style>`, `<iframe>`, `<form>`, mọi `on*`, `javascript:`.

---

## 4. Kiểm thử F9 (RSS/Atom)

1. Vào sidebar **“Nguồn RSS / Atom”** (hoặc `feeds.php`).
2. Dán URL feed (mọi URL công khai đều dùng được — máy chủ tự kiểm tra an toàn), bấm **“Xem trước”**.
3. Xem trước hiện: tên feed, tiêu đề bài, ngày xuất bản, URL, mô tả ngắn.
4. Tích vào một hoặc nhiều bài → chọn thư viện đích (+ nhãn) →
   bấm **“Lưu N bài đã chọn”**.
5. Kết quả hiện **riêng từng bài**: thành công / thất bại + link mở bản lưu.
6. Nếu chưa có thư viện → dùng khối **“Tạo thư viện mới”** ngay trên trang
   (dùng chung `wa_library_create()`).

### Ghi chú kỹ thuật

- Feed được tải qua `wa_capture_http_get()` → có kiểm tra an toàn + chống SSRF +
  timeout + giới hạn 5 MB.
- XML phân tích bằng `DOMDocument` với `LIBXML_NONET` (không gọi mạng, không
  phân giải entity ngoài).
- Chống trùng: `item_hash` = SHA-256(URL), `UNIQUE(subscription_id, item_hash)`.
- Bài lưu dùng lại đúng `wa_store_page_capture()` → có bản lưu thật + ảnh nội bộ
  + chỉ mục tìm kiếm.
- **Không có cron/daemon**: feed chỉ tải khi người dùng bấm “Xem trước”.

---

## 5. Danh sách file thêm/sửa

### File MỚI

| File | Vai trò |
|------|---------|
| `chrome-extension/manifest.json` | Manifest V3 |
| `chrome-extension/popup.html` / `popup.css` / `popup.js` | Giao diện popup: lưu 1 cú bấm, tạo thư viện 1 ô, cảnh báo KHÔNG LƯU / VẪN LƯU |
| `chrome-extension/background.js` | Service worker: gọi API + tự động lưu theo domain |
| `chrome-extension/icons/icon{16,32,48,128}.png` | Biểu tượng |
| `scripts/make_extension_icons.php` | Sinh icon (chạy 1 lần, có sẵn kết quả) |
| `src/url_policy.php` | **Chính sách URL công khai**: chốt an toàn SSRF (chặn mạng riêng, cloud metadata, DNS rebinding) + tín hiệu rủi ro — dùng chung cho mọi đường lưu |

| `src/v3_api.php` | Token, xác thực, CORS, rate limit |
| `src/v3_share.php` | Nghiệp vụ link chia sẻ + làm sạch HTML |
| `src/v3_feeds.php` | Tải/parse RSS-Atom + lưu bài |
| `public/api.php` | API cho Extension (`whoami`, `library_create`, `save`) |
| `public/extension.php` | Trang quản lý token |
| `public/shared.php` | Trình xem công khai của link chia sẻ |
| `public/share_action.php` | POST tạo/thu hồi link chia sẻ |
| `public/feeds.php` | Trang quản lý nguồn RSS/Atom |

### File SỬA

| File | Thay đổi |
|------|----------|
| `src/bootstrap.php` | Nạp thêm `url_policy.php`, `v3_api.php`, `v3_share.php`, `v3_feeds.php` |

| `src/capture.php` | Thay allowlist bắt buộc bằng chốt an toàn (`wa_url_policy_inspect()`), ghim IP bằng `CURLOPT_RESOLVE` |

| `src/v3_basic.php` / `src/v3_feeds.php` | Kiểm tra URL dùng chung `wa_url_policy_inspect()` |

| `public/api.php` | `action=save` trả **409 + tín hiệu rủi ro**; hỗ trợ `override_risk` (chỉ bỏ qua cảnh báo) |

| `public/save.php` | Cảnh báo rủi ro hai nút **KHÔNG LƯU** / **VẪN LƯU**; bỏ nút “yêu cầu duyệt tên miền” |

| `public/admin/allowlist.php` | Đổi nhãn “Tên miền được duyệt” → “Tên miền tin cậy” (danh sách tin cậy, không phải điều kiện lưu) |
| `public/reader.php` | Nút “Chia sẻ” + modal quản lý link + sao chép link |
| `templates/header_app.php` | Thêm 2 mục sidebar (RSS/Atom, Kết nối Extension) |
| `database/schema.sql` | Thêm DDL tham chiếu cho 4 bảng mới |
| `scripts/migrate_v3.php` | Thêm bảng `api_tokens` |
| `README.md` | Thêm mục 6.5 (V3 Đợt 3), bản đồ trang và cây thư mục |

---

## 6. Lưu ý vận hành

- `public/api.php` **không** dùng cookie phiên. Nếu truy cập trực tiếp bằng
  trình duyệt mà không có token → HTTP 401 với JSON `{"ok":false,"error":…}`.
- `public/shared.php` **không** yêu cầu đăng nhập — đó là bản chất của link chia sẻ.

- **Không còn danh sách tên miền bắt buộc.** Mọi URL công khai đều lưu được ngay;

  quản trị viên không phải duyệt tên miền trước. Bảng `allowlist_domains` giữ

  nguyên trong CSDL nhưng nay chỉ là **danh sách TIN CẬY** (miễn cảnh báo rủi ro).

- **Cảnh báo rủi ro** chỉ hiện khi có bằng chứng kiểm chứng được (HTTP không mã

  hoá, địa chỉ IP thay tên miền, cổng không chuẩn, punycode). Không cảnh báo chỉ

  vì tên miền “chưa có trong CSDL”.

- **Chốt an toàn SSRF không bao giờ bị bỏ qua** bằng thao tác của người dùng:

  `http://localhost/`, `http://169.254.169.254/` và mọi đích trong mạng nội bộ

  đều bị từ chối ở tầng máy chủ, kể cả khi người dùng bấm “VẪN LƯU”.

- Trong Extension, **tự động lưu** bỏ qua mọi trang gặp cảnh báo và báo người dùng

  xác nhận thủ công (không tự vượt cảnh báo).
- Nếu Extension báo “Chưa kết nối”, kiểm tra WebArchive đã chạy chưa và
  địa chỉ máy chủ trong Cài đặt có đúng không (bao gồm cả `http://` và cổng).
