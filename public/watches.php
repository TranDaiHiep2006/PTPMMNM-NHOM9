<?php
/**
 * watches.php — QUẢN LÝ TOÀN BỘ ĐOẠN NỘI DUNG ĐANG THEO DÕI (V3 ĐỢT 4 / F10).
 *
 * Trang này liệt kê MỌI mục theo dõi thuộc đúng tài khoản đang đăng nhập
 * (bất kể bài viết nào), kèm nút bật/tắt và xoá.
 *
 * Mọi hành động ghi đều đi qua public/watch_action.php (POST + CSRF) và quay
 * lại trang này bằng thông báo flash.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$watches = wa_watch_list_for_user($userId);

$pageTitle = 'Đoạn nội dung đang theo dõi';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Theo dõi nội dung</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Tự động đối chiếu theo phiên bản</span>
      </div>
      <h1 class="ribbon__title">Đoạn nội dung đang theo dõi</h1>
      <p class="ribbon__sub">
        <?= count($watches) ?> mục. Khi Auto Version tạo phiên bản mới, hệ thống đối chiếu
        từng đoạn đã bôi đen và báo cho bạn nếu nội dung bị sửa hoặc không còn tìm thấy.
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

<?php if ($flash !== null): ?>
  <div class="state <?= (string) ($flash['type'] ?? '') === 'success' ? 'state--success' : 'state--error' ?>" style="margin-bottom:16px">
    <div class="state__body">
      <div class="state__icon">
        <span class="ms ms-18 ms-fill">
          <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
        </span>
      </div>
      <div>
        <span class="state__title"><?= (string) ($flash['type'] ?? '') === 'success' ? 'Thành công:' : 'Thông báo:' ?></span>
        <p class="state__text"><?= wa_e((string) ($flash['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($watches === []): ?>
  <div class="empty">
    <span class="ms ms-32">visibility_off</span>
    <h3>Chưa theo dõi đoạn nào</h3>
    <p>
      Mở một bản lưu, bôi đen một đoạn văn bản rồi bấm
      <b>Theo dõi đoạn này</b> để bắt đầu theo dõi.
    </p>
    <div class="row" style="justify-content:center;margin-top:16px">
      <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
    </div>
  </div>
<?php else: ?>
  <div class="state-list">
    <?php foreach ($watches as $w): ?>
      <div class="state-item">
        <div class="state-item__head">
          <span class="state-item__name">
            <span class="ms ms-18">visibility</span>
            <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $w['snapshot_id'])) ?>"
               style="color:inherit;text-decoration:none">
              <?= wa_e((string) ($w['page_title'] !== '' ? $w['page_title'] : 'Bài viết #' . (int) $w['page_id'])) ?>
            </a>
          </span>
          <span class="row" style="gap:6px;flex-wrap:wrap">
            <?php
            $chip = $w['status'] === 'changed' ? 'warn' : ($w['status'] === 'unknown' ? 'plain' : 'teal');
            ?>
            <span class="chip chip--<?= $chip ?>"><?= wa_e((string) $w['status_label']) ?></span>
            <?php if (!$w['is_active']): ?>
              <span class="chip chip--plain">đang tạm dừng</span>
            <?php endif; ?>
          </span>
        </div>

        <p class="t-body-sm" style="margin-top:8px">“<?= wa_e((string) $w['quote']) ?>”</p>

        <?php if ((string) $w['status_note'] !== ''): ?>
          <p class="t-body-sm t-muted" style="margin-top:6px">
            <span class="t-label-sm t-muted">Trạng thái:</span>
            <?= wa_e((string) $w['status_note']) ?>
          </p>
        <?php endif; ?>

        <?php if ((string) $w['last_seen_quote'] !== ''): ?>
          <p class="t-body-sm t-muted" style="margin-top:6px">
            <span class="t-label-sm t-muted">Nội dung ở phiên bản mới:</span>
            “<?= wa_e((string) $w['last_seen_quote']) ?>”
          </p>
        <?php endif; ?>

        <p class="t-body-sm t-muted" style="margin-top:6px">
          <?= wa_e((string) ($w['library_name'] ?? '')) ?>
          · tạo lúc <?= wa_e((string) $w['created_label']) ?>
          <?php if ((string) $w['last_changed_at'] !== ''): ?>
            · cập nhật lúc <?= wa_e(wa_datetime((string) $w['last_changed_at'])) ?>
          <?php endif; ?>
        </p>

        <div class="wa-notes__acts" style="margin-top:10px">
          <a class="btn btn--dark btn--sm"
             href="<?= wa_e(wa_url('reader.php?id=' . (int) $w['snapshot_id'])) ?>">
            <span class="ms ms-16">chrome_reader_mode</span>
            Mở bài
          </a>

          <form method="post" action="<?= wa_e(wa_url('watch_action.php')) ?>" style="margin:0">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="redirect" value="watches">
            <input type="hidden" name="snapshot_id" value="<?= (int) $w['snapshot_id'] ?>">
            <input type="hidden" name="watch_id" value="<?= (int) $w['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $w['is_active'] ? '0' : '1' ?>">
            <button class="btn btn--outline btn--sm" type="submit">
              <span class="ms ms-16"><?= $w['is_active'] ? 'pause' : 'play_arrow' ?></span>
              <?= $w['is_active'] ? 'Tắt' : 'Bật' ?>
            </button>
          </form>

          <form method="post" action="<?= wa_e(wa_url('watch_action.php')) ?>"
                onsubmit="return confirm('Xoá mục theo dõi này?');" style="margin:0">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="redirect" value="watches">
            <input type="hidden" name="snapshot_id" value="<?= (int) $w['snapshot_id'] ?>">
            <input type="hidden" name="watch_id" value="<?= (int) $w['id'] ?>">
            <button class="btn btn--outline btn--sm" type="submit">
              <span class="ms ms-16">delete</span>
              Xoá
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
