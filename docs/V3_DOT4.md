# WEBARCHIVE V3 — ĐỢT 4: THEO DÕI ĐOẠN + QUAN HỆ + TRÙNG LẶP

Tài liệu cài đặt và kiểm thử cho ba chức năng cuối của WebArchive V3:

| Mã | Tính năng | File chính |
|----|-----------|-----------|
| **F10** | Theo dõi thay đổi **một đoạn nội dung** | `src/v3_watch.php`, `public/watch_action.php`, `public/watches.php` |
| **F11** | **Liên kết bài viết** + sơ đồ quan hệ (SVG) | `src/v3_relations.php`, `public/relation_action.php` |
| **F12** | **Phát hiện nội dung trùng lặp** (URL / SHA-256 / gần giống) | `src/v3_duplicates.php`, `public/save.php`, `public/api.php` |

---

## 0. Migration cần chạy (chạy MỘT lần)

```bash
php scripts/migrate_v3.php
```

Script kiểm tra `INFORMATION_SCHEMA` trước nên **chạy lại bao nhiêu lần cũng an toàn**.
Không chạy trực tiếp `database/schema.sql` (script đó có `DROP TABLE`).

Bảng **mới** của Đợt 4:

| Bảng | Mục đích |
|------|----------|
| `content_watches` | Các đoạn nội dung đang được theo dõi (F10) |
| `page_relations` | Quan hệ giữa các bài viết (F11) |

> F12 **không cần bảng mới** — dùng cột `snapshots.content_hash` đã có sẵn.

Sau khi migration xong, kiểm tra:

```sql
SHOW TABLES LIKE 'content_watches';
SHOW TABLES LIKE 'page_relations';
```

---

## 1. F10 — Theo dõi thay đổi một đoạn nội dung

### 1.1. Nguyên tắc

- Người dùng **bôi đen** một đoạn văn bản trong trình đọc → menu nổi hiện ra →
  bấm **“Theo dõi đoạn này”**.
- Hệ thống lưu: đoạn đã chọn (`quote`), ngữ cảnh hai bên (`prefix` / `suffix`),
  vị trí trong khối (`selector` + `start_offset` / `end_offset`) theo **snapshot nguồn**.
- Khi Auto Version (`src/auto_version.php`) hoặc nút **“Lấy phiên bản mới”**
  (`public/refetch.php`) tạo phiên bản mới, `wa_watch_check_page()` đối chiếu TỪNG mục
  theo dõi với nội dung mới — **tái sử dụng kết quả tải sẵn, KHÔNG tải lại website**.
- `content_html` gốc của snapshot **không bị sửa** (giữ nguyên F4).

### 1.2. Ba trạng thái

| Trạng thái | Nghĩa |
|-----------|-------|
| `ok` | Đoạn vẫn còn nguyên trong bản mới |
| `changed` | Đoạn bị sửa (khớp một phần, Jaccard ≥ 0,55) → **có thông báo** |
| `unknown` | **Không tìm thấy đoạn tương ứng** — hệ thống KHÔNG tự kết luận “đã bị xoá” |

Nút bật/tắt sẽ đặt trạng thái `paused` (mục bị tắt không được đối chiếu).

### 1.3. Chống thông báo trùng

Khoá `dedupe_key` của dòng thông báo = `watch:{userId}:{watchId}:{versionNo}`
→ mỗi thay đổi của một mục theo dõi **chỉ báo đúng MỘT lần** cho mỗi phiên bản.

### 1.4. Nội dung thông báo

- Có nội dung cũ, có nội dung mới.
- Trang `public/notifications.php` tự dựng link **“Xem thay đổi (V{n-1} → V{n})”**
  sang `public/compare.php` (dùng `from_version` / `to_version` lấy từ CSDL thật).

### 1.5. Kiểm thử tay

1. Đăng nhập → mở một bản lưu (`reader.php?id=…`).
2. Bôi đen một đoạn văn bản dài khoảng 1–3 câu → menu nổi **“Theo dõi đoạn này”** hiện ra.
3. Bấm → trang tải lại, mục mới xuất hiện ở mục **“Đoạn nội dung đang theo dõi”**.
4. Vào **Đoạn theo dõi** (menu sidebar) → thấy danh sách đầy đủ, có nút **Bật/Tắt** và **Xoá**.
5. Bấm **“Lấy phiên bản mới”** trên trang đọc (cần quyền ghi thư viện).
6. Mở **Thông báo** (chuông) → thấy thông báo “Đoạn nội dung bạn theo dõi đã được sửa”
   kèm nội dung cũ / mới và nút **Xem thay đổi**.
7. **Kiểm tra chống trùng:** bấm “Lấy phiên bản mới” lần nữa ở cùng phiên bản → không
   có thông báo trùng cho cùng một phiên bản.
8. **Kiểm tra `unknown`:** sửa bài gốc (nếu có) để đoạn đã theo dõi bị diễn đạt lại
   hoàn toàn → thông báo phải ghi rõ *“không xác định được đoạn tương ứng”*,
   **không** được nói “nội dung đã bị xoá”.

---

## 2. F11 — Liên kết bài viết và sơ đồ quan hệ

### 2.1. Bốn loại quan hệ

| Mã | Nhãn | Ý nghĩa |
|----|------|---------|
| `related` | Liên quan | Cùng chủ đề, đọc cạnh nhau |
| `references` | Tham khảo | Bài nguồn trích dẫn / dựa trên bài đích |
| `supplements` | Bổ sung | Bài đích bổ sung thông tin cho bài nguồn |
| `contrasts` | Đối chiếu | Có điểm khác biệt đáng so sánh |

### 2.2. Phân quyền

- **Tạo / sửa / xoá**: cần quyền **ghi** trên thư viện chứa bài nguồn
  (chủ sở hữu / biên tập viên / quản trị viên) — `wa_relation_can_write()`.
- **Bài đích**: phải nằm trong thư viện người dùng **có quyền đọc**.
- **Hiển thị**: quan hệ chỉ xuất hiện khi người đọc có quyền đọc **cả hai bên**
  (`wa_relation_list_for_page()` lọc ngay trong SQL).
- Bài bị xoá → quan hệ tự biến mất (FK `ON DELETE CASCADE`).

### 2.3. Sơ đồ quan hệ

- Vẽ bằng **JavaScript thuần + SVG** (không thư viện đồ hoạ nặng, không dịch vụ trả phí).
- Bấm vào một nút → mở `reader.php?id=<snapshot mới nhất của bài đó>`.
- Chỉ hiển thị những bài mà người dùng có quyền đọc.

### 2.4. Kiểm thử tay

1. Mở một bản lưu → kéo xuống mục **“Bài viết liên quan”**.
2. Bấm **“Liên kết bài viết”** → gõ từ khoá (≥ 2 ký tự) → danh sách bài tìm thấy
   (chỉ trong thư viện bạn có quyền đọc).
3. Chọn một bài → chọn loại quan hệ → ghi chú (tuỳ chọn) → **Tạo liên kết**.
4. Quan hệ mới xuất hiện trong danh sách (kèm link tới bài đích).
5. Bấm **“Xem sơ đồ quan hệ”** → sơ đồ SVG với nút tròn, cạnh có nhãn loại quan hệ;
   bấm vào nút → mở đúng bài.
6. Bấm nút **Sửa** (icon bút chì) → đổi loại quan hệ / ghi chú.
7. Bấm **Xoá** → quan hệ biến mất, có thông báo flash.
8. **Kiểm tra phân quyền:** đăng nhập tài khoản chỉ có quyền **đọc** →
   nút sửa/xoá và nút “Tạo liên kết” phải bị khoá, không hiện thông tin
   thư viện/snapshot ngoài quyền.

---

## 3. F12 — Phát hiện nội dung trùng lặp

### 3.1. Ba mức phát hiện

| Mức | Cách so | Kết quả |
|-----|---------|---------|
| 1 | URL sau chuẩn hoá (`wa_bulk_normalize_url`) | “Trùng URL” — 100% |
| 2 | SHA-256 của văn bản (`snapshots.content_hash`) | “Trùng hoàn toàn” — 100% |
| 3 | **n-gram 3 từ + Jaccard** | “Gần giống” — **ước lượng** (hiển thị % và nói rõ là ước lượng) |

### 3.2. Giới hạn khối lượng so sánh

- Ứng viên được **lọc trước theo độ dài văn bản** (40% – 250% so với bài đang lưu).
- Tối đa **200 bài ứng viên** mỗi lần (`WA_DUP_MAX_CANDIDATES`).
- Tối đa **600 n-gram** mẫu cho mỗi bài (`WA_DUP_MAX_SHINGLES`) → giới hạn bộ nhớ.

### 3.3. Quy tắc quan trọng

- **KHÔNG** tự động xoá / gộp / ghi đè snapshot.
- **KHÔNG** coi các phiên bản V1/V2/V3 của **cùng một bài** là bài trùng độc lập
  (loại trừ theo `page_id`).
- Chỉ so sánh và hiển thị bài mà người dùng **có quyền đọc** (lọc ngay trong SQL).

### 3.4. Cảnh báo trước khi lưu

- `public/save.php`: tải trước nội dung **một lần** (cache session 10 phút) → nếu phát
  hiện trùng, hiện bảng kết quả với nút **HUỶ** / **VẪN LƯU**.
  Bấm **VẪN LƯU** **dùng lại** nội dung đã tải → **không tải URL lần thứ hai**.
- `public/api.php` (Chrome Extension): trả HTTP **409** kèm `duplicates[]`;
  Extension gửi lại với `override_dup: true` để vẫn lưu.
- Chrome Extension: `background.js` nhận HTTP 409 → `popup.js` hiện hộp **"Cảnh báo trùng lặp"**
  (danh sách bài tương tự + link xem bài) với hai nút **KHÔNG LƯU** / **VẪN LƯU**.
  **Tự động lưu theo domain KHÔNG BAO GIỜ vượt qua cảnh báo trùng lặp.**

### 3.5. Kiểm thử tay

1. Lưu một trang (một URL) vào thư viện → lưu thành công.
2. Lưu **lại chính URL đó** (cùng thư viện) → phải hiện cảnh báo **trùng URL**
   với nút **HUỶ** / **VẪN LƯU** (không tự động gộp).
3. Lưu một trang khác nhưng **nội dung giống hệt** (copy nguyên văn bản) →
   cảnh báo **“Trùng hoàn toàn”** (SHA-256 giống hệt).
4. Lưu một trang có nội dung **gần giống** (sửa vài chữ) → cảnh báo **“Gần giống”**
   kèm **tỷ lệ ước lượng**; dòng giải thích phải ghi rõ *“Ước lượng … (so n-gram 3 từ)”*.
5. Bấm **HUỢ** (Huỷ) → **không** có bản lưu mới nào được tạo.
6. Bấm **VẪN LƯU** → bản lưu mới được tạo, snapshot cũ **giữ nguyên**.
7. Kiểm tra “V1/V2/V3 cùng bài không phải bài trùng”: lưu lại cùng URL lần nữa →
   cảnh báo chỉ nói **trùng URL**, **không** nói “trùng hoàn toàn” với chính nó.

---

## 4. File thêm / sửa trong Đợt 4

| File | Trạng thái | Vai trò |
|------|-----------|--------|
| `src/v3_watch.php` | mới | Lớp nghiệp vụ F10 |
| `src/v3_relations.php` | mới | Lớp nghiệp vụ F11 |
| `src/v3_duplicates.php` | mới | Lớp nghiệp vụ F12 |
| `public/watch_action.php` | mới | Endpoint F10 (create / toggle / delete / list) |
| `public/relation_action.php` | mới | Endpoint F11 (search / create / update / delete / list / graph) |
| `public/watches.php` | mới | Trang quản lý toàn bộ mục theo dõi |
| `public/reader.php` | sửa | Menu nổi theo dõi đoạn + 2 modal (liên kết / sơ đồ) + khối dữ liệu F10/F11 |
| `public/save.php` | sửa | Cảnh báo trùng lặp trước khi lưu (F12) |
| `public/api.php` | sửa | Trả 409 + danh sách bài trùng (F12) |
| `chrome-extension/background.js` | sửa | Nhận HTTP 409 (trùng lặp) + `override_dup` |
| `chrome-extension/popup.js` | sửa | Hộp "Cảnh báo trùng lặp" + nút KHÔNG LƯU / VẪN LƯU |
| `chrome-extension/popup.html` | sửa | Khối giao diện cảnh báo trùng lặp |
| `chrome-extension/popup.css` | sửa | Kiểu cho khối cảnh báo trùng lặp |
| `src/auto_version.php` | sửa | Gọi `wa_watch_check_page()` sau khi tạo phiên bản |
| `public/refetch.php` | sửa | Gọi `wa_watch_check_page()` sau khi lấy phiên bản mới |
| `src/bootstrap.php` | sửa | Nạp 3 file lớp nghiệp vụ mới |
| `database/schema.sql` | sửa | Thêm `content_watches`, `page_relations` |
| `scripts/migrate_v3.php` | sửa | Thêm 2 bảng (idempotent) |
| `templates/header_app.php` | sửa | Thêm menu **“Đoạn theo dõi”** |

---

## 5. Phần CHƯA được kiểm thử tự động

- Không chạy `scripts/run_tests.php` (theo yêu cầu đợt này).
- Không chạy migration trên MySQL thật — bảng mới **phải** được tạo trước khi dùng.
- Không mở trình duyệt tự động — các bước kiểm thử tay ở trên **chưa chạy** trên máy.
- Các truy vấn SQL của F11 dùng **prepared statement thật** với tham số có tiền tố
  riêng (`:rel_src_owner` / `:rel_dst_member` …) để tránh `SQLSTATE[HY093]`.
