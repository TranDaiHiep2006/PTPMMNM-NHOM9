<?php
/**
 * integrity.php — XÁC MINH TÍNH TOÀN VẸN SNAPSHOT BẰNG SHA-256 (V3 / FEATURE 6).
 *
 * Trang công khai cho tài khoản đã đăng nhập:
 *   - Hiện mã hash gốc đã ghi nhận (nếu có) và mã tính lại từ nội dung hiện tại.
 *   - Nút "Kiểm tra tính toàn vẹn" — chạy kiểm tra và báo khớp / không khớp.
 *   - Nút "Tính hash ban đầu" cho snapshot CŨ chưa có hash: ghi hash lần đầu
 *     mà KHÔNG sửa nội dung.
 *
 * LƯU Ý TRUNG THỰC: mã hash và nội dung nằm cùng một database, nên việc kiểm
 * tra này phát hiện được thay đổi do vô tình (sửa nhầm, hỏng dữ liệu) và
 * thay đổi trực tiếp trên CSDL, nhưng KHÔNG phải bằng chứng chống sửa đổi
 * tuyệt đối trước một người kiểm soát được cả CSDL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user       = current_user();
$userId     = (int) ($user['id'] ?? 0);
$snapshotId = (int) ($_GET['id'] ?? 0);

/* ---------------------------------------------------------------------------
 * Tính hash ban đầu cho snapshot cũ (POST + CSRF).
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = static function (string $type, string $text) use ($snapshotId): never {
        $_SESSION['wa_flash'] = ['type' => $type, 'text' => $text];
        header('Location: ' . wa_url('integrity.php?id=' . $snapshotId));
        exit;
    };

    if (!wa_csrf_check()) {
        $back('error', 'Phiên làm việc đã thay đổi. Vui lòng thử lại.');
    }

    $context = $snapshotId > 0 ? wa_reader_context($snapshotId) : ['status' => 'not_found'];

    if ($context['status'] !== 'ok') {
        $back('error', 'Không tìm thấy bản lưu này hoặc bạn không có quyền truy cập.');
    }

    if (wa_is_admin() || $context['can_refetch'] === true) {
        $stmt = db()->prepare('SELECT content_html, content_hash FROM `snapshots` WHERE id = ? LIMIT 1');
        $stmt->execute([$snapshotId]);
        $row = $stmt->fetch();

        if ($row !== false && trim((string) ($row['content_hash'] ?? '')) === '') {
            wa_snapshot_record_hash($snapshotId, (string) $row['content_html']);
            $back('success', 'Đã ghi mã hash ban đầu cho bản lưu này (nội dung không bị thay đổi).');
        }

        $back('error', 'Bản lưu này đã có mã hash gốc, không ghi đè.');
    }

    $back('error', 'Bạn không có quyền ghi mã hash cho bản lưu này.');
}

/* ---------------------------------------------------------------------------
 * Hiển thị.
 * ------------------------------------------------------------------------- */
if ($snapshotId <= 0) {
    http_response_code(404);
    $pageTitle = 'Xác minh tính toàn vẹn';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy bản lưu</h3>
      <p>Vui lòng chọn một bản lưu để kiểm tra tính toàn vẹn.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

$context = $snapshotId > 0 ? wa_reader_context($snapshotId) : ['status' => 'not_found'];

if ($context['status'] !== 'ok') {
    http_response_code(403);
    $pageTitle = 'Xác minh tính toàn vẹn';
    require __DIR__ . '/../templates/error_no_permission.php';
    exit;
}

$article = $context['article'];

$verify = wa_snapshot_verify_hash($snapshotId);

$canRecord = wa_is_admin() || (bool) $context['can_refetch'];

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Xác minh tính toàn vẹn — ' . (string) $article['title'];
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Toàn vẹn dữ liệu</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">SHA-256</span>
      </div>
      <h1 class="ribbon__title">Kiểm tra tính toàn vẹn</h1>
      <p class="ribbon__sub">
        Bản lưu <strong><?= wa_e((string) $article['title']) ?></strong> —
        phiên bản <?= (int) $article['version_no'] ?> · SNAP-<?= (int) $article['id'] ?>.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('reader.php?id=' . $snapshotId)) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Về trình đọc</span>
      </a>
    </div>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="state <?= (string) ($flash['type'] ?? '') === 'success' ? 'state--success' : 'state--error' ?>" style="margin-bottom:16px">
    <div class="state__body">
      <span class="state__icon">
        <span class="ms ms-18 ms-fill">
          <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
        </span>
      </span>
      <div>
        <span class="state__title"><?= (string) ($flash['type'] ?? '') === 'success' ? 'Thành công:' : 'Thông báo:' ?></span>
        <p class="state__text"><?= wa_e((string) ($flash['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="state-list">
  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name">
        <span class="ms ms-18">
          <?= $verify['state'] === 'match' ? 'verified' : ($verify['state'] === 'mismatch' ? 'gpp_bad' : 'help') ?>
        </span>
        Kết quả kiểm tra
      </span>
      <span class="chip chip--<?= $verify['state'] === 'match' ? 'teal' : ($verify['state'] === 'mismatch' ? 'error' : 'warn') ?>">
        <?= $verify['state'] === 'match' ? 'khớp' : ($verify['state'] === 'mismatch' ? 'sai lệch' : 'chưa có hash') ?>
      </span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px"><?= wa_e((string) $verify['message']) ?></p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">tag</span>Mã hash gốc đã ghi nhận</span>
      <span class="chip chip--plain"><?= wa_e((string) $verify['algo']) ?></span>
    </div>
    <p class="t-body-sm" style="margin-top:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all">
      <?= $verify['stored_hash'] !== '' ? wa_e($verify['stored_hash']) : '— chưa có —' ?>
    </p>
    <?php if ($verify['recorded_at'] !== ''): ?>
      <p class="t-body-sm t-muted" style="margin-top:4px">
        Ghi nhận lúc <?= wa_e(wa_datetime($verify['recorded_at'])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">calculate</span>Mã tính lại từ nội dung hiện tại</span>
      <span class="chip chip--plain">chuẩn hoá</span>
    </div>
    <p class="t-body-sm" style="margin-top:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all">
      <?= wa_e($verify['current_hash']) ?>
    </p>
    <p class="t-body-sm t-muted" style="margin-top:6px">
      Nội dung được chuẩn hoá (bỏ thẻ HTML, giải thực thể, gộp khoảng trắng)
      trước khi băm, giống hệt quy tắc dùng lúc lưu bản lưu.
    </p>
  </div>
</div>

<div class="row" style="gap:8px;margin-top:16px;flex-wrap:wrap">
  <a class="btn btn--dark" href="<?= wa_e(wa_url('integrity.php?id=' . $snapshotId)) ?>">
    <span class="ms ms-18">verified</span>
    Kiểm tra tính toàn vẹn
  </a>

  <?php if ($canRecord && $verify['stored_hash'] === ''): ?>
    <form method="post" action="<?= wa_e(wa_url('integrity.php?id=' . $snapshotId)) ?>">
      <?= wa_csrf_field() ?>
      <button class="btn btn--outline" type="submit">
        <span class="ms ms-18">add_task</span>
        Tính hash ban đầu cho bản lưu cũ
      </button>
    </form>
  <?php endif; ?>
</div>

<div class="state" style="margin-top:20px">
  <div class="state__body">
    <span class="state__icon"><span class="ms ms-18 ms-fill">info</span></span>
    <div>
      <span class="state__title">Giới hạn của phép kiểm tra này</span>
      <p class="state__text">
        Mã hash và nội dung nằm cùng một database, nên phép kiểm tra phát hiện được
        thay đổi bất thường (sửa nhầm, dữ liệu hỏng) và thay đổi trực tiếp trên
        CSDL — nhưng KHÔNG phải bằng chứng chống sửa đổi tuyệt đối trước một người
        kiểm soát được cả máy chủ.
      </p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
