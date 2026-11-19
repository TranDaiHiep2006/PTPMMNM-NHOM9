<?php
/**
 * reading_list.php — DANH SÁCH ĐỌC SAU (V3 / FEATURE 3).
 *
 * Liệt kê các bản lưu mà tài khoản đang đăng nhập đã đánh dấu "Đọc sau",
 * kèm tiến độ đọc hiện tại. Bấm "Bỏ khỏi danh sách" để gỡ.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

/** Bỏ khỏi danh sách đọc sau (POST + CSRF). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wa_csrf_check()) {
        $_SESSION['wa_flash'] = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng thử lại.'];
    } else {
        $snapshotId = (int) ($_POST['snapshot_id'] ?? 0);

        if ($snapshotId > 0) {
            wa_reading_queue_toggle($userId, $snapshotId);
            $_SESSION['wa_flash'] = ['type' => 'success', 'text' => 'Đã cập nhật danh sách đọc sau.'];
        }
    }

    header('Location: ' . wa_url('reading_list.php'));
    exit;
}

$items = wa_reading_queue_list($userId);

$states = wa_reading_states_map(
    $userId,
    array_map(static fn (array $r): int => (int) $r['snapshot_id'], $items)
);

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Danh sách đọc sau';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Đọc</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Chế độ đọc</span>
      </div>
      <h1 class="ribbon__title">Danh sách đọc sau</h1>
      <p class="ribbon__sub">
        <?= count($items) ?> bản lưu đang chờ bạn đọc. Tiến độ được lưu theo tài khoản
        nên mở lại bài đúng chỗ bạn dừng lúc trước.
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

<?php if ($items === []): ?>
  <div class="empty">
    <span class="ms ms-32">bookmark_border</span>
    <h3>Chưa có bài nào trong danh sách đọc sau</h3>
    <p>Mở một bản lưu và bấm nút <b>Đọc sau</b> trên thanh công cụ để thêm vào đây.</p>
    <div class="row" style="justify-content:center;margin-top:16px">
      <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
    </div>
  </div>
<?php else: ?>
  <div class="state-list">
    <?php foreach ($items as $item): ?>
      <?php $st = $states[(int) $item['snapshot_id']] ?? null; ?>
      <div class="state-item">
        <div class="state-item__head">
          <span class="state-item__name">
            <span class="ms ms-18">menu_book</span>
            <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $item['snapshot_id'])) ?>"
               style="color:inherit;text-decoration:none">
              <?= wa_e((string) $item['title']) ?>
            </a>
          </span>
          <span class="chip chip--plain">Phiên bản <?= (int) $item['version_no'] ?></span>
        </div>

        <p class="t-body-sm t-muted" style="margin-top:8px">
          <?= wa_e((string) $item['domain']) ?>
          · thêm lúc <?= wa_e((string) $item['added_label']) ?>
          <?php if ($st !== null): ?>
            · <?= $st['status'] === 'read' ? 'đã đọc' : 'đang đọc (' . (int) $st['progress'] . '%)' ?>
          <?php endif; ?>
        </p>

        <p class="t-body-sm" style="margin-top:6px"><?= wa_e((string) $item['excerpt']) ?></p>

        <div class="wa-notes__acts" style="margin-top:10px">
          <a class="btn btn--dark btn--sm" href="<?= wa_e(wa_url('reader.php?id=' . (int) $item['snapshot_id'])) ?>">
            <span class="ms ms-16">chrome_reader_mode</span>
            Đọc tiếp
          </a>
          <form method="post" action="<?= wa_e(wa_url('reading_list.php')) ?>" style="margin:0">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="snapshot_id" value="<?= (int) $item['snapshot_id'] ?>">
            <button class="btn btn--outline btn--sm" type="submit">
              <span class="ms ms-16">bookmark_remove</span>
              Bỏ khỏi danh sách
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
