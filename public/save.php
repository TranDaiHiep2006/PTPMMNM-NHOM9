<?php
/**
 * save.php — Trang "Lưu trang Web".
 *
 * Gồm bảng nhập URL và phần trình diễn đầy đủ 4 trạng thái theo quy tắc mô phỏng
 * trong src/mock_capture.php:
 *   1. Thành công
 *   2. URL không thuộc danh sách được duyệt
 *   3. Trang gốc trả về 404
 *   4. Hết thời gian chờ khi tải trang
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$result = null;
$submittedUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedUrl = trim((string) ($_POST['url'] ?? ''));
    $result = mock_capture($submittedUrl);
}

// Bảng trạng thái mô phỏng (dùng để trình diễn, không phải kết quả thật).
$showcases = [
    [
        'state' => MOCK_STATE_SUCCESS,
        'url' => 'https://phptutorial.vn/kien-truc-php-mysql-webarchive',
        'label' => 'Thành công',
        'hint' => 'Tên miền nằm trong danh sách được duyệt và đường dẫn hợp lệ.',
    ],
    [
        'state' => MOCK_STATE_NOT_ALLOWED,
        'url' => 'https://khong-duoc-duyet.vn/bai-viet-moi',
        'label' => 'URL không thuộc danh sách được duyệt',
        'hint' => 'Tên miền không có trong allowlist — kể cả khi nội dung trả về hợp lệ.',
    ],
    [
        'state' => MOCK_STATE_HTTP_404,
        'url' => 'https://phptutorial.vn/bai-viet-404',
        'label' => 'Trang gốc trả về 404',
        'hint' => 'Trang lỗi không bao giờ được lưu thành một bài viết hợp lệ.',
    ],
    [
        'state' => MOCK_STATE_TIMEOUT,
        'url' => 'https://techblog.dev/bai-viet-timeout',
        'label' => 'Hết thời gian chờ khi tải trang',
        'hint' => 'Máy chủ nguồn không phản hồi trong thời gian cho phép.',
    ],
];

$stateLabels = mock_state_labels();

$pageTitle = 'Lưu trang Web';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Bảo tồn nội dung</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Offline Ready</span>
      </div>
      <h1 class="ribbon__title">Lưu trang Web</h1>
      <p class="ribbon__sub">
        Dán liên kết bài viết để tạo một bản snapshot tĩnh, đọc lại được ngoại tuyến và tìm kiếm nội dung đã lưu.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('library.php?slug=cntt-lap-trinh')) ?>">
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
        <p class="t-body-sm t-muted">Trích xuất nội dung văn bản, tải ảnh về máy chủ cục bộ và tạo bản đọc ngoại tuyến.</p>
      </div>
    </div>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">bolt</span> HTML snapshot engine
    </span>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('save.php')) ?>">
    <div class="capture__input">
      <span class="ms ms-18">link</span>
      <input class="input input--icon" type="url" name="url" required
             value="<?= wa_e($submittedUrl !== '' ? $submittedUrl : 'https://phptutorial.vn/kien-truc-php-mysql-webarchive') ?>"
             placeholder="https://example.com/bai-viet-lap-trinh-web"
             aria-label="Địa chỉ trang Web cần lưu">
    </div>
    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">download_for_offline</span>
      <span>Lưu trang Web</span>
    </button>
  </form>

  <div class="capture__hint">
    <p>Chỉ những URL thuộc danh sách tên miền được duyệt mới có thể lưu. Mọi đích chuyển hướng cũng được kiểm tra lại.</p>
    <a class="linkish" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
      <span class="ms ms-16">verified_user</span>
      Xem danh sách tên miền được duyệt
    </a>
  </div>

  <?php if ($result !== null): ?>
    <!-- KẾT QUẢ MÔ PHỎNG TỪ URL VỪA NHẬP -->
    <?php if ($result['ok']): ?>
      <div class="state state--success">
        <div class="state__body">
          <span class="state__icon"><span class="ms ms-18 ms-fill">check_circle</span></span>
          <div>
            <div class="row" style="gap:8px">
              <span class="state__title">Lưu thành công:</span>
              <span class="state__text"><?= wa_e($result['message']) ?></span>
            </div>
            <p class="state__note"><?= wa_e($result['detail']) ?></p>
          </div>
        </div>
        <div class="state__actions">
          <a class="btn btn--light" href="<?= wa_e(wa_url('reader.php?id=8941')) ?>">
            <span class="ms ms-16" style="color:var(--wa-teal-deep)">chrome_reader_mode</span>
            Mở xem ngay
          </a>
        </div>
      </div>
    <?php elseif ($result['state'] === MOCK_STATE_NOT_ALLOWED): ?>
      <div class="state state--error">
        <div class="state__body">
          <span class="state__icon"><span class="ms ms-18 ms-fill">gpp_bad</span></span>
          <div>
            <div class="row" style="gap:8px">
              <span class="state__title">Không thể lưu trang</span>
            </div>
            <p class="state__text"><?= wa_e($result['message']) ?></p>
            <p class="state__note"><?= wa_e($result['detail']) ?></p>
          </div>
        </div>
        <div class="state__actions">
          <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">Xem danh sách được duyệt</a>
        </div>
      </div>
    <?php elseif ($result['state'] === MOCK_STATE_HTTP_404): ?>
      <div class="state state--error">
        <div class="state__body">
          <span class="state__icon"><span class="ms ms-18 ms-fill">error_outline</span></span>
          <div>
            <div class="row" style="gap:8px">
              <span class="state__title">Không thể lưu trang</span>
            </div>
            <p class="state__text"><?= wa_e($result['message']) ?></p>
            <p class="state__note"><?= wa_e($result['detail']) ?></p>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="state state--warning">
        <div class="state__body">
          <span class="state__icon"><span class="ms ms-18 ms-fill">timer_off</span></span>
          <div>
            <div class="row" style="gap:8px">
              <span class="state__title">Không thể lưu trang</span>
            </div>
            <p class="state__text"><?= wa_e($result['message']) ?></p>
            <p class="state__note"><?= wa_e($result['detail']) ?></p>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ===================== BẢNG TRẠNG THÁI MÔ PHỎNG ===================== -->
<div class="pagehead" style="margin-bottom:16px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Trạng thái hệ thống</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Bốn trạng thái lưu trang</h2>
    <p class="pagehead__desc">
      Giai đoạn giao diện: các trạng thái dưới đây được mô phỏng bằng quy tắc URL
      (xem <code>src/mock_capture.php</code>), chưa có tải trang thật.
    </p>
  </div>
</div>

<div class="state-list">
  <?php foreach ($showcases as $case): ?>
    <?php $caseResult = mock_capture($case['url']); ?>
    <div class="state-item">
      <div class="state-item__head">
        <span class="state-item__name">
          <span class="ms ms-18"><?= $caseResult['ok'] ? 'check_circle' : 'error_outline' ?></span>
          <?= wa_e($case['label']) ?>
        </span>
        <span class="chip <?= $caseResult['ok'] ? 'chip--teal' : 'chip--error' ?>">
          <?= wa_e($stateLabels[$caseResult['state']]) ?>
        </span>
      </div>

      <form class="capture__form" method="post" action="<?= wa_e(wa_url('save.php')) ?>" style="margin-bottom:12px">
        <div class="capture__input">
          <span class="ms ms-18">link</span>
          <input class="input input--icon input--sm" type="url" name="url" value="<?= wa_e($case['url']) ?>"
                 aria-label="URL ví dụ">
        </div>
        <button class="btn btn--outline btn--sm" type="submit">
          <span class="ms ms-16">play_arrow</span>
          <span>Mô phỏng</span>
        </button>
      </form>

      <?php if ($caseResult['ok']): ?>
        <div class="state state--success" style="margin-top:0">
          <div class="state__body">
            <span class="state__icon"><span class="ms ms-18 ms-fill">check_circle</span></span>
            <div>
              <span class="state__title">Lưu thành công:</span>
              <p class="state__text"><?= wa_e($caseResult['message']) ?></p>
              <p class="state__note"><?= wa_e($caseResult['detail']) ?></p>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="state state--<?= $caseResult['state'] === MOCK_STATE_TIMEOUT ? 'warning' : 'error' ?>" style="margin-top:0">
          <div class="state__body">
            <span class="state__icon">
              <span class="ms ms-18 ms-fill"><?= wa_e($caseResult['icon']) ?></span>
            </span>
            <div>
              <span class="state__title">Không thể lưu trang</span>
              <p class="state__text"><?= wa_e($caseResult['message']) ?></p>
              <p class="state__note"><?= wa_e($caseResult['detail']) ?></p>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <p class="t-label-sm t-muted" style="margin-top:8px"><?= wa_e($case['hint']) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
