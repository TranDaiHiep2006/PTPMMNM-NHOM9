<?php
/**
 * save.php — Trang "Lưu trang Web" (P-READ: dùng đường lưu dùng chung).
 *
 * Biểu mẫu gồm: chọn thư viện (chỉ thư viện mà người dùng là chủ sở hữu hoặc
 * biên tập viên), ô URL, và ô nhãn tuỳ chọn (phân tách bằng dấu phẩy).
 *
 * Toàn bộ phần GHI CSDL (tìm/tạo saved_pages, tạo snapshot, tải và nối lại ảnh
 * cục bộ, ghi assets, cập nhật trang, gắn nhãn, ghi bản ghi lỗi) đã được gom về
 * HÀM DUY NHẤT wa_store_page_capture() trong src/queries.php — cùng hàm mà nút
 * "Lấy phiên bản mới" của public/refetch.php gọi. Hành vi của trang giữ nguyên.
 *
 * Thành công: chuyển hướng tới reader.php?id=<snapshot id> kèm thông báo
 * tiếng Việt có số phiên bản.
 *
 * Thất bại: vẫn ghi lại lần thử (dòng snapshot với fetch_status là mã lỗi và
 * error_message đầy đủ) để thống kê quản trị đếm được lỗi, đồng thời hiện
 * thông báo tiếng Việt RIÊNG cho từng mã lỗi. Snapshot lỗi không bao giờ hiện
 * trong danh sách bài viết và không mở được trong trình đọc.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user     = current_user();
$userId   = (int) ($user['id'] ?? 0);
$libraries = wa_writable_libraries();

/** Thông báo trả về sau khi xử lý: @var array{type:string, text:string}|null */
$notice = null;
/** Dữ liệu form để hiển thị lại sau khi POST. */
$form = [
    'url'      => '',
    'library'  => '',
    'tags'     => '',
];

/* ---------------------------------------------------------------------------
 * XỬ LÝ POST — lưu trang thật
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['url']     = trim((string) ($_POST['url'] ?? ''));
    $form['library'] = trim((string) ($_POST['library_id'] ?? ''));
    $form['tags']    = trim((string) ($_POST['tags'] ?? ''));

    $libraryId = (int) $form['library'];
    $tagNames  = wa_parse_tag_input($form['tags']);

    // --- Kiểm tra quyền ghi vào thư viện (chủ sở hữu / biên tập viên) ---
    if ($libraryId <= 0) {
        $notice = ['type' => 'error', 'text' => 'Vui lòng chọn thư viện để lưu trang.'];
    } elseif (!wa_can_write_library($libraryId)) {
        $notice = ['type' => 'error', 'text' => 'Bạn không có quyền lưu trang vào thư viện này.'];
    } elseif (!wa_csrf_check()) {
        $notice = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'];
    } else {
        /* ---------------------------------------------------------------------------
         * ĐƯỜNG LƯU DÙNG CHUNG — cùng hàm với public/refetch.php.
         * Hàm tự tìm/tạo trang, tự tính version_no = MAX + 1, tự tải ảnh cục bộ,
         * tự ghi assets, và tự ghi bản ghi lỗi khi thất bại.
         * ------------------------------------------------------------------------- */
        $result = wa_store_page_capture($libraryId, $form['url'], $tagNames, $userId);

        if ($result['ok'] === true) {
            /* ---------------- THÀNH CÔNG ---------------- */
            $imageCount = (int) $result['image_count'];

            $_SESSION['wa_flash'] = [
                'type'        => 'success',
                'text'        => 'Lưu thành công phiên bản ' . (int) $result['version'] . ' của “'
                                 . (string) $result['title'] . '”'
                                 . ($imageCount > 0
                                     ? ' — đã tải ' . $imageCount . ' ảnh về máy chủ nội bộ.'
                                     : '.'),
                'snapshot_id' => (int) $result['snapshot_id'],
            ];

            header('Location: ' . wa_url('reader.php?id=' . (int) $result['snapshot_id']));
            exit;
        }

        /* ---------------- THẤT BẠI — bản ghi lỗi đã được ghi trong hàm dùng chung ---------------- */
        $notice = [
            'type'  => 'error',
            'text'  => (string) $result['error_message'],
            'title' => (string) $result['error_title'],
            'code'  => (string) $result['error_code'],
        ];
    }
}

/* ---------------------------------------------------------------------------
 * Biến cho giao diện
 * ------------------------------------------------------------------------- */

// Thông báo thành công do reader.php gửi lại sau khi chuyển hướng.
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Lưu trang Web';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Bảo tồn nội dung</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Engine tải trang thật</span>
      </div>
      <h1 class="ribbon__title">Lưu trang Web</h1>
      <p class="ribbon__sub">
        Dán liên kết bài viết để tạo một bản snapshot tĩnh, đọc lại được ngoại tuyến và tìm kiếm nội dung đã lưu.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('libraries.php')) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Về thư viện</span>
      </a>
    </div>
  </div>
</div>

<!-- ===================== BẢNG NHẬP URL ===================== -->
<div class="capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">archive</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Lưu một trang Web vào kho của bạn</h2>
        <p class="t-body-sm t-muted">Trích xuất nội dung văn bản, tải ảnh về máy chủ nội bộ và tạo bản đọc ngoại tuyến.</p>
      </div>
    </div>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">bolt</span>
      cURL + DOMDocument
    </span>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('save.php')) ?>">
    <?= wa_csrf_field() ?>

    <!-- Chọn thư viện: chỉ hiện thư viện người dùng có quyền ghi -->
    <div class="capture__input">
      <span class="ms ms-18">folder_special</span>
      <select class="input input--icon" name="library_id" required aria-label="Thư viện để lưu trang">
        <option value="">— Chọn thư viện —</option>
        <?php foreach ($libraries as $library): ?>
          <option value="<?= (int) $library['id'] ?>"
            <?= (string) $form['library'] === (string) $library['id'] ? 'selected' : '' ?>>
            <?= wa_e($library['name']) ?>
            (<?= wa_e($library['role'] === 'owner' ? 'chủ sở hữu' : 'biên tập viên') ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="capture__input">
      <span class="ms ms-18">link</span>
      <input class="input input--icon" type="url" name="url" required
             value="<?= wa_e($form['url']) ?>"
             placeholder="https://example.com/bai-viet-lap-trinh-web"
             aria-label="Địa chỉ trang Web cần lưu">
    </div>

    <div class="capture__input">
      <span class="ms ms-18">sell</span>
      <input class="input input--icon" type="text" name="tags"
             value="<?= wa_e($form['tags']) ?>"
             placeholder="nhãn 1, nhãn 2"
             aria-label="Nhãn phân loại, phân tách bằng dấu phẩy">
    </div>

    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">download_for_offline</span>
      <span>Lưu trang Web</span>
    </button>
  </form>

  <div class="capture__hint">
    <p>Chỉ những URL thuộc danh sách tên miền được duyệt mới có thể lưu. Mọi đích chuyển hướng và cả ảnh trong bài cũng được kiểm tra lại.</p>
    <a class="linkish" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
      <span class="ms ms-16">verified_user</span>
      Xem danh sách tên miền được duyệt
    </a>
  </div>

  <?php if ($flash !== null): ?>
    <div class="state state--success">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill">check_circle</span></span>
        <div>
          <span class="state__title">Lưu thành công:</span>
          <p class="state__text"><?= wa_e((string) $flash['text']) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($notice !== null): ?>
    <div class="state state--error">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill">error_outline</span></span>
        <div>
          <span class="state__title"><?= wa_e((string) ($notice['title'] ?? 'Không thể lưu trang')) ?></span>
          <p class="state__text"><?= wa_e($notice['text']) ?></p>
          <?php if (isset($notice['code'])): ?>
            <p class="state__note">Mã lỗi lưu vào hệ thống: <code><?= wa_e((string) $notice['code']) ?></code></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="state__actions">
        <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">Xem danh sách được duyệt</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ===================== QUY TẮC LƯU TRANG ===================== -->
<div class="pagehead" style="margin-bottom:16px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Nguyên tắc hệ thống</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Bảy quy tắc khi lưu một trang</h2>
    <p class="pagehead__desc">
      Mỗi lần lưu đều đi qua engine tải trang thật trong <code>src/capture.php</code> và
      ghi CSDL qua một đường dùng chung <code>wa_store_page_capture()</code>.
      Trang lỗi không bao giờ trở thành một bài viết hợp lệ.
    </p>
  </div>
</div>

<div class="state-list">
  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">gpp_bad</span>Kiểm tra danh sách được duyệt trước</span>
      <span class="chip chip--teal">error_blocked</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Tên miền phải khớp một mục trong danh sách được duyệt (cho phép cả subdomain).
      Việc kiểm tra diễn ra <b>trước khi</b> gửi bất kỳ yêu cầu mạng nào, và được kiểm tra
      lại ở mỗi lần chuyển hướng cũng như với từng ảnh trong bài.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">timer_off</span>Giới hạn thời gian chờ</span>
      <span class="chip chip--warn">error_timeout</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Chờ kết nối tối đa 5 giây và tổng thời gian tải tối đa 12 giây; tối đa 5 lần chuyển
      hướng và 5 MB cho mỗi tài nguyên.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">error_outline</span>Phân biệt từng nguyên nhân lỗi</span>
      <span class="chip chip--error">error_404 · error_http · error_no_content</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Mỗi nguyên nhân có một mã trạng thái riêng và một thông báo tiếng Việt riêng.
      Lần lưu thất bại vẫn được ghi lại để thống kê quản trị đếm được lỗi.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">image</span>Ảnh tải về máy chủ nội bộ</span>
      <span class="chip chip--teal">assets</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Tối đa 20 ảnh mỗi bài, chỉ nhận JPEG/PNG/GIF/WebP đã kiểm tra nội dung thật, lưu vào
      <code>public/uploads/snapshots/&lt;id&gt;/</code> và được nối lại bằng đường dẫn cục bộ.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">history</span>Mỗi lần lưu là một phiên bản</span>
      <span class="chip chip--plain">version_no</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Lưu lại cùng một URL trong cùng thư viện sẽ tạo phiên bản tiếp theo; các phiên bản
      cũ vẫn được giữ nguyên. Nút <b>Lấy phiên bản mới</b> trong trình đọc dùng chung
      đúng đường lưu này.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>