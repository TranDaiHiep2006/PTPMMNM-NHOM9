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
    // Cờ xác nhận: CHỈ dùng để bỏ qua CẢNH BÁO rủi ro, không bỏ qua chốt an toàn.
    'override_risk' => false,
    // V3 ĐỢT 4 / F12: người dùng đã xác nhận "VẪN LƯU" dù có bài gần giống.
    'override_dup'  => false,
];

/** Cảnh báo rủi ro đang chờ người dùng xác nhận (null nếu không có). */
$riskNotice = null;

/** V3 ĐỢT 4 / F12: cảnh báo trùng lặp đang chờ người dùng xác nhận. */
$dupNotice = null;

/* ---------------------------------------------------------------------------
 * XỬ LÝ POST — lưu trang thật
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['url']     = trim((string) ($_POST['url'] ?? ''));
    $form['library'] = trim((string) ($_POST['library_id'] ?? ''));
    $form['tags']    = trim((string) ($_POST['tags'] ?? ''));
    $form['override_risk'] = ($_POST['override_risk'] ?? '') === '1';
    $form['override_dup']  = ($_POST['override_dup'] ?? '') === '1';

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
         * CHÍNH SÁCH URL CÔNG KHAI — chốt an toàn cấp máy chủ.
         * Không cần tên miền được Admin duyệt, nhưng mọi đích phải qua được kiểm tra
         * an toàn (chặn mạng riêng, localhost, cloud metadata, đích không xác minh
         * được, DNS rebinding). KHÔNG có thao tác nào bỏ qua được chốt này.
         * ------------------------------------------------------------------------- */
        $policy = wa_url_policy_inspect($form['url']);

        if ($policy['ok'] !== true) {
            $notice = [
                'type'  => 'error',
                'title' => 'URL không an toàn để tải',
                'text'  => (string) $policy['message'],
                'code'  => 'error_blocked',
            ];
        } elseif ($policy['signals'] !== [] && !$form['override_risk']) {
            /* ---- CẢNH BÁO RỦI RO: hai lựa chọn KHÔNG LƯU / VẪN LƯU ---- */
            $riskNotice = [
                'reason'  => wa_url_policy_warning_message($policy['signals'], (string) $policy['host']),
                'signals' => $policy['signals'],
            ];
        } else {
        /* ---------------------------------------------------------------------------
         * V3 ĐỢT 4 / F12 — PHÁT HIỆN NỘI DUNG TRÙNG LẶP.
         *
         * Tải TRƯỚC nội dung trang (MỘT lần, cache trong session 10 phút) rồi so
         * trùng URL / trùng nội dung chính xác (SHA-256) / gần giống (n-gram).
         *
         * KHÔNG tự xoá / gộp / ghi đè snapshot: nếu có bài gần giống, người dùng
         * được chọn "VẪN LƯU" hoặc "HUỶ". Bước "VẪN LƯU" DÙNG LẠI nội dung đã
         * tải sẵn nên KHÔNG tải lại URL lần thứ hai.
         * ------------------------------------------------------------------------- */
        $prefetch = wa_dup_prefetch_content($form['url']);

        // Bài đang lưu thêm phiên bản: loại trừ chính nó khỏi so sánh
        // (V1/V2/V3 của cùng một bài KHÔNG phải bài trùng độc lập).
        $existingPage = wa_find_page_by_url($libraryId, $form['url']);
        $excludePageId = $existingPage !== null ? (int) $existingPage['id'] : 0;

        $dupCheck = [
            'report'  => [],
            'scanned' => 0,
            'has_any' => false,
        ];

        if ($prefetch['ok'] === true && $prefetch['content_html'] !== '') {
            $dupCheck = wa_dup_check_url(
                $form['url'],
                (string) $prefetch['content_html'],
                $libraryId,
                $excludePageId
            );
        }

        if ($dupCheck['has_any'] === true && !$form['override_dup']) {
            /* ---- CẢNH BÁO TRÙNG LẶP: hai lựa chọn HUỶ / VẪN LƯU ---- */
            $dupNotice = [
                'report'  => $dupCheck['report'],
                'scanned' => (int) $dupCheck['scanned'],
            ];
        } else {
        /* ---------------------------------------------------------------------------
         * ĐƯỜNG LƯU DÙNG CHUNG — cùng hàm với public/refetch.php.
         * Hàm tự tìm/tạo trang, tự tính version_no = MAX + 1, tự tải ảnh cục bộ,
         * tự ghi assets, và tự ghi bản ghi lỗi khi thất bại.
         * ------------------------------------------------------------------------- */
        // Nếu nội dung đã tải sẵn ở bước kiểm tra trùng lặp thì DÙNG LẠI,
        // không tải cùng một URL hai lần.
        $prefetchedResult = is_array($prefetch['result'] ?? null) ? $prefetch['result'] : null;

        $result = wa_store_page_capture($libraryId, $form['url'], $tagNames, $userId, $prefetchedResult);

        // Xoá cache prefetch: bài đã lưu xong, không cần giữ nội dung trong session.
        wa_dup_prefetch_clear($form['url']);

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
        }   // hết nhánh else của cảnh báo trùng lặp
        }   // hết nhánh else của chính sách URL
    }       // hết nhánh if/elseif/else đầu tiên
}           // hết POST

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

    <?php /* Cờ xác nhận: chỉ bỏ qua CẢNH BÁO rủi ro, không bỏ qua chốt an toàn. */ ?>
    <input type="hidden" name="override_risk" value="<?= $form['override_risk'] ? '1' : '' ?>">

    <?php /* V3 ĐỢT 4 / F12: chỉ bỏ qua CẢNH BÁO trùng lặp. */ ?>
    <input type="hidden" name="override_dup" value="<?= $form['override_dup'] ? '1' : '' ?>">

    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">download_for_offline</span>
      <span>Lưu trang Web</span>
    </button>
  </form>

  <div class="capture__hint">
    <p>
      Mọi URL công khai (http/https) đều có thể lưu — không cần quản trị viên duyệt tên miền.
      Máy chủ tự kiểm tra trước khi tải: chặn mạng nội bộ, localhost, cloud metadata,
      đích không xác minh được và chống DNS rebinding. Mọi đích chuyển hướng và cả ảnh
      trong bài cũng được kiểm tra lại.
    </p>
    <p>
      Trang có dấu hiệu rủi ro cụ thể (địa chỉ IP thay tên miền, cổng không chuẩn,
      không mã hoá, tên miền punycode) sẽ hiện cảnh báo để bạn chọn <b>VẪN LƯU</b>
      hoặc <b>KHÔNG LƯU</b>.
    </p>
  </div>

  <?php if ($flash !== null): ?>
    <div class="state <?= ($flash['type'] ?? 'success') === 'success' ? 'state--success' : 'state--error' ?>">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill"><?= ($flash['type'] ?? 'success') === 'success' ? 'check_circle' : 'error_outline' ?></span></span>
        <div>
          <span class="state__title"><?= ($flash['type'] ?? 'success') === 'success' ? 'Lưu thành công:' : 'Thông báo:' ?></span>
          <p class="state__text"><?= wa_e((string) $flash['text']) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($riskNotice !== null): ?>
    <div class="state state--warning">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill">warning</span></span>
        <div>
          <span class="state__title">Cảnh báo rủi ro</span>
          <p class="state__text"><?= wa_e((string) $riskNotice['reason']) ?></p>
          <?php if (!empty($riskNotice['signals'])): ?>
            <ul class="state__note" style="margin:8px 0 0;padding-left:18px">
              <?php foreach ($riskNotice['signals'] as $signal): ?>
                <li><?= wa_e((string) $signal['detail']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
      <div class="state__actions">
        <a class="btn btn--outline" href="<?= wa_e(wa_url('save.php')) ?>">KHÔNG LƯU</a>
        <form method="post" action="<?= wa_e(wa_url('save.php')) ?>" style="display:inline">
          <?= wa_csrf_field() ?>
          <input type="hidden" name="url" value="<?= wa_e($form['url']) ?>">
          <input type="hidden" name="library_id" value="<?= wa_e($form['library']) ?>">
          <input type="hidden" name="tags" value="<?= wa_e($form['tags']) ?>">
          <input type="hidden" name="override_risk" value="1">
          <button class="btn btn--dark" type="submit">VẪN LƯU</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($dupNotice !== null): ?>
    <div class="state state--warning">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill">content_copy</span></span>
        <div>
          <span class="state__title">Có thể trùng hoặc gần giống bài đã lưu</span>
          <p class="state__text">
            Trang này có nội dung giống hoặc gần giống với
            <?= count($dupNotice['report']) ?> bài đã có trong các thư viện bạn có quyền đọc.
            Hệ thống KHÔNG tự xoá, gộp hay ghi đè bản lưu nào — bạn tự quyết định.
          </p>
          <ul class="state__note" style="margin:8px 0 0;padding-left:18px">
            <?php foreach ($dupNotice['report'] as $dup): ?>
              <li>
                <strong><?= wa_e((string) ($dup['title'] ?? 'Không rõ tên')) ?></strong>
                <span class="chip chip--plain" style="margin-left:6px">
                  <?= wa_e((string) ($dup['level_label'] ?? 'Trùng')) ?>
                </span>
                <span class="t-label-sm t-muted">
                  · <?= (int) ($dup['score'] ?? 0) ?>%
                  <?php if ((string) ($dup['library_name'] ?? '') !== ''): ?>
                    · <?= wa_e((string) $dup['library_name']) ?>
                  <?php endif; ?>
                </span>
                <br>
                <span class="t-label-sm t-muted">
                  <?= wa_e((string) ($dup['reason'] ?? '')) ?>
                  <?php if ((int) ($dup['snapshot_id'] ?? 0) > 0): ?>
                    · <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $dup['snapshot_id'])) ?>">xem bài</a>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="state__note" style="margin-top:8px">
            Mức độ tương đồng là <b>ước lượng</b> (so n-gram 3 từ) — không phải kết luận tuyệt đối.
            Các phiên bản V1/V2/V3 của chính bài này không bị coi là bài trùng.
            Đã so sánh <?= (int) $dupNotice['scanned'] ?> bài ứng viên gần nhất.
          </p>
        </div>
      </div>
      <div class="state__actions">
        <a class="btn btn--outline" href="<?= wa_e(wa_url('save.php')) ?>">HUỶ</a>
        <form method="post" action="<?= wa_e(wa_url('save.php')) ?>" style="display:inline">
          <?= wa_csrf_field() ?>
          <input type="hidden" name="url" value="<?= wa_e($form['url']) ?>">
          <input type="hidden" name="library_id" value="<?= wa_e($form['library']) ?>">
          <input type="hidden" name="tags" value="<?= wa_e($form['tags']) ?>">
          <input type="hidden" name="override_risk" value="<?= $form['override_risk'] ? '1' : '' ?>">
          <input type="hidden" name="override_dup" value="1">
          <button class="btn btn--dark" type="submit">VẪN LƯU</button>
        </form>
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
      <?php if ((($notice['code'] ?? '') === 'error_blocked')): ?>
        <div class="state__actions">
          <a class="btn btn--outline" href="<?= wa_e(wa_url('save.php')) ?>">Thử lại với URL khác</a>
        </div>
      <?php endif; ?>
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
      <span class="state-item__name"><span class="ms ms-18">gpp_bad</span>Kiểm tra an toàn trước khi tải</span>
      <span class="chip chip--teal">error_blocked</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Mọi URL công khai đều lưu được — <b>không</b> cần quản trị viên duyệt tên miền.
      Máy chủ tự kiểm tra DNS và địa chỉ IP: chặn mạng nội bộ (RFC 1918), localhost,
      cloud metadata (169.254.169.254), dải đặc biệt, IPv6 riêng tư và mọi đích không
      xác minh được; đồng thời ghim IP đã xác minh để chống DNS rebinding.
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