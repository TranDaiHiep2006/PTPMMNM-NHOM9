<?php
/**
 * notifications.php — TRANG THÔNG BÁO của tài khoản đang đăng nhập.
 *
 * Liệt kê các thông báo phiên bản mới (mới nhất trước), kèm:
 *   - thời gian cập nhật;
 *   - liên kết xem phiên bản mới (mở trình đọc ở đúng snapshot V[n]);
 *   - liên kết xem khác biệt giữa hai phiên bản (compare.php);
 *   - trạng thái đã đọc / chưa đọc.
 *
 * Mỗi thông báo có nút "Đánh dấu đã đọc" POST sang notification_action.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user    = current_user();
$userId  = (int) ($user['id'] ?? 0);
$filter  = (string) ($_GET['filter'] ?? 'all');

$notifications = wa_notify_list($userId, 50, $filter === 'unread');
$unreadCount   = wa_notify_unread_count($userId);

$pageTitle = 'Thông báo';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="stack">
  <div class="row row--between" style="gap:16px;flex-wrap:wrap">
    <div>
      <h1 class="pagehead__title">Thông báo</h1>
      <p class="t-body-md t-muted">
        <?php if ($unreadCount > 0): ?>
          Còn <strong><?= $unreadCount ?></strong> thông báo chưa đọc.
        <?php else: ?>
          Bạn đã đọc hết thông báo.
        <?php endif; ?>
      </p>
    </div>

    <div class="row" style="gap:8px">
      <a class="btn btn--outline btn--sm <?= $filter === 'all' ? 'is-active' : '' ?>"
         href="<?= wa_e(wa_url('notifications.php')) ?>">Tất cả</a>
      <a class="btn btn--outline btn--sm <?= $filter === 'unread' ? 'is-active' : '' ?>"
         href="<?= wa_e(wa_url('notifications.php?filter=unread')) ?>">Chưa đọc</a>
      <?php if ($unreadCount > 0): ?>
        <form method="post" action="<?= wa_e(wa_url('notification_action.php')) ?>">
          <?= wa_csrf_field() ?>
          <input type="hidden" name="action" value="read_all">
          <button class="btn btn--dark btn--sm" type="submit">Đánh dấu đã đọc tất cả</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($notifications === []): ?>
    <div class="form-alert form-alert--ok">
      <?php if ($filter === 'unread'): ?>
        Không có thông báo chưa đọc nào.
      <?php else: ?>
        Chưa có thông báo nào. Khi trang gốc của một bài viết thay đổi và bạn
        cho phép kiểm tra tự động, WebArchive sẽ lưu phiên bản mới và báo bạn ở đây.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="stack--sm">
      <?php foreach ($notifications as $notification): ?>
        <?php
        $snapshotId  = (int) ($notification['snapshot_id'] ?? 0);
        $pageId      = (int) ($notification['page_id'] ?? 0);
        $isRead      = (bool) $notification['is_read'];
        $toVersion   = (int) ($notification['to_version'] ?? 0);
        $fromVersion = (int) ($notification['from_version'] ?? 0);

        // Tách phần tóm tắt (các dòng bắt đầu bằng "• ") khỏi phần mô tả.
        $bodyLines = explode("\n", (string) ($notification['body'] ?? ''));
        $bodyText  = [];
        $summary   = [];

        foreach ($bodyLines as $line) {
            if (str_starts_with($line, '• ')) {
                // Bỏ tiền tố "• " (2 ký tự: bullet + khoảng trắng).
                $summary[] = mb_substr($line, 2);
            } else {
                $bodyText[] = $line;
            }
        }

        $bodyText = trim(implode("\n", $bodyText));

        // Link so sánh: chỉ dựng khi biết CẢ hai số phiên bản từ dữ liệu thật.
        $compareUrl = '';

        if ($pageId > 0 && $toVersion > 0 && $fromVersion > 0 && $fromVersion !== $toVersion) {
            $compareUrl = wa_url(
                'compare.php?page_id=' . $pageId
                . '&from=' . $fromVersion
                . '&to=' . $toVersion
            );
        }
        ?>
        <div class="article-card <?= $isRead ? '' : 'is-unread' ?>">
          <div class="article-card__body">
            <div class="article-card__main">
              <div class="article-card__meta">
                <span class="article-card__domain">
                  <span class="ms ms-14">update</span><?= $isRead ? 'Đã đọc' : 'Chưa đọc' ?>
                </span>
                <span>Cập nhật: <?= wa_e((string) $notification['created_label']) ?></span>
                <?php if ((string) $notification['source_url'] !== ''): ?>
                  <span>•</span>
                  <span class="t-clamp-1"><?= wa_e((string) $notification['source_url']) ?></span>
                <?php endif; ?>
              </div>

              <h3 class="t-clamp-2"><?= wa_e((string) $notification['title']) ?></h3>

              <?php if ($bodyText !== ''): ?>
                <p class="t-body-md t-muted"><?= wa_e($bodyText) ?></p>
              <?php endif; ?>

              <?php if ($summary !== []): ?>
                <div class="notify-summary">
                  <p class="notify-summary__label">Thay đổi:</p>
                  <ul class="notify-summary__list">
                    <?php foreach ($summary as $line): ?>
                      <li><?= wa_e($line) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <div class="row" style="gap:8px;margin-top:12px;flex-wrap:wrap">
                <?php if ($snapshotId > 0): ?>
                  <a class="btn btn--dark btn--sm" href="<?= wa_e(wa_url('reader.php?id=' . $snapshotId)) ?>">
                    <span class="ms ms-14">open_in_new</span>
                    <?= $toVersion > 0 ? 'Xem V' . $toVersion : 'Xem phiên bản mới' ?>
                  </a>
                <?php endif; ?>

                <?php if ($compareUrl !== ''): ?>
                  <a class="btn btn--outline btn--sm" href="<?= wa_e($compareUrl) ?>">
                    <span class="ms ms-14">difference</span>Xem thay đổi
                    (V<?= $fromVersion ?> → V<?= $toVersion ?>)
                  </a>
                <?php elseif ($pageId > 0): ?>
                  <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('compare.php?page_id=' . $pageId)) ?>">
                    <span class="ms ms-14">difference</span>Xem khác biệt
                  </a>
                <?php endif; ?>

                <?php if (!$isRead): ?>
                  <form method="post" action="<?= wa_e(wa_url('notification_action.php')) ?>">
                    <?= wa_csrf_field() ?>
                    <input type="hidden" name="action" value="read">
                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                    <button class="btn btn--outline btn--sm" type="submit">Đánh dấu đã đọc</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php
require __DIR__ . '/../templates/footer_app.php';
