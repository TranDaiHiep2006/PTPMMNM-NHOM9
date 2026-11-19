<?php
/**
 * shared.php — TRÌNH XEM CÔNG KHAI của một link chia sẻ (V3 / FEATURE 8).
 *
 * ĐÂY KHÔNG PHẢI trình đọc của thành viên: khách có link chỉ được XEM đúng
 * snapshot được chia sẻ.
 *
 *   - KHÔNG yêu cầu đăng nhập.
 *   - KHÔNG hiện ghi chú / đánh dấu / thành viên / tên thư viện / thư viện khác.
 *   - KHÔNG có nút sửa / xoá / tải bản quản trị.
 *   - Token thô chỉ dùng để tra hash; server chỉ lưu SHA-256.
 *   - Lượt xem được tăng bằng UPDATE có điều kiện (an toàn khi xem đồng thời).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$token = trim((string) ($_GET['t'] ?? ''));

$result = wa_share_open($token);

/* ---------------------------------------------------------------------------
 * Link không dùng được -> trang lỗi thân thiện (không tiết lộ chi tiết).
 * ------------------------------------------------------------------------- */
if ($result['ok'] !== true) {
    http_response_code(404);

    $statusLabels = [
        'invalid'  => 'Link chia sẻ không hợp lệ',
        'revoked'  => 'Link chia sẻ đã bị thu hồi',
        'expired'  => 'Link chia sẻ đã hết hạn',
        'used_up'  => 'Link chia sẻ đã hết lượt xem',
        'not_found'=> 'Không tìm thấy nội dung',
        'error'    => 'Máy chủ chưa sẵn sàng',
    ];

    $pageTitle = 'Không mở được bản chia sẻ';
    $hideTopbar = false;
    require __DIR__ . '/../templates/header_guest.php';
    ?>
    <div style="max-width:640px;margin:48px auto 0;padding:0 18px">
      <div class="empty">
        <span class="ms ms-32">link_off</span>
        <h3><?= wa_e($statusLabels[(string) $result['status']] ?? 'Không mở được') ?></h3>
        <p><?= wa_e((string) $result['message']) ?></p>
        <div class="row" style="justify-content:center;margin-top:16px">
          <a class="btn btn--outline" href="<?= wa_e(wa_url('index.php')) ?>">Về trang chủ</a>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_guest.php';
    exit;
}

/** @var array<string,mixed> $snap */
$snap = (array) $result['snapshot'];

$safeHtml = wa_share_sanitize_html((string) $snap['content_html']);

$pageTitle = (string) $snap['title'];
$hideTopbar = false;
$hideFooter = false;

require __DIR__ . '/../templates/header_guest.php';
?>

<style>
  /* Bản chia sẻ dùng đúng bộ khung gọn của reader.php. */
  .wa-share { max-width: 780px; margin: 0 auto; padding: 28px 18px 60px; }
  .wa-share__bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;
                   padding:12px 0; margin-bottom:18px; border-bottom:1px solid var(--wa-border); }
  .wa-share__meta { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  .wa-share__title { font-family: var(--wa-font-reader); font-size:28px; line-height:38px; font-weight:700;
                     color:var(--wa-primary); margin:0 0 6px; }
  .wa-share__url { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px;
                   color:var(--wa-text-soft); word-break:break-all; }
  .wa-share__body { display:flex; flex-direction:column; gap:var(--wa-space-lg); padding-top:var(--wa-space-md);
                    font-family:var(--wa-font-reader); font-size:18px; line-height:32px; color:var(--wa-text); }
  .wa-share__body h2 { font-family:var(--wa-font-ui); font-size:24px; line-height:32px; font-weight:700; color:var(--wa-primary); }
  .wa-share__body h3 { font-family:var(--wa-font-ui); font-size:18px; line-height:26px; font-weight:600; color:var(--wa-primary); }
  .wa-share__body img { max-width:100%; height:auto; }
  .wa-share__body a { text-decoration:underline; }
  .wa-share__body ul, .wa-share__body ol { padding-left:22px; }
  .wa-share__body table { width:100%; border-collapse:collapse; font-family:var(--wa-font-ui); font-size:13px; }
  .wa-share__body th, .wa-share__body td { padding:8px 12px; border:1px solid var(--wa-border); text-align:left; }
  .wa-share__body blockquote { margin:0; padding-left:14px; border-left:3px solid var(--wa-teal); color:var(--wa-text-soft); }
  .wa-share__foot { margin-top:36px; padding-top:16px; border-top:1px solid var(--wa-border);
                    font-size:12px; color:var(--wa-text-muted); }
</style>

<div class="wa-share">

  <div class="wa-share__bar">
    <div class="wa-share__meta">
      <span class="chip chip--teal">
        <span class="ms ms-14">visibility</span>
        Bản chia sẻ · chỉ đọc
      </span>
      <span class="chip chip--plain">
        <span class="ms ms-14">history</span>
        Phiên bản <?= (int) $snap['version_no'] ?>
      </span>
      <span class="chip chip--plain">
        <span class="ms ms-14">schedule</span>
        Lưu lúc <?= wa_e((string) $snap['saved_at']) ?>
      </span>
      <?php if ($snap['max_views'] !== null): ?>
        <span class="chip chip--warn">
          <span class="ms ms-14">visibility</span>
          Lượt xem: <?= (int) $snap['view_count'] ?>/<?= (int) $snap['max_views'] ?>
        </span>
      <?php endif; ?>
    </div>

    <a class="btn btn--outline btn--sm" href="<?= wa_e((string) $snap['source_url']) ?>"
       target="_blank" rel="noopener noreferrer nofollow">
      <span class="ms ms-16">open_in_new</span>
      <span>Mở trang gốc</span>
    </a>
  </div>

  <h1 class="wa-share__title"><?= wa_e((string) $snap['title']) ?></h1>
  <div class="wa-share__url"><?= wa_e((string) $snap['source_url']) ?></div>

  <div class="wa-share__body">
<?= $safeHtml ?>
  </div>

  <div class="wa-share__foot">
    <p>
      Trang này được chia sẻ qua WebArchive. Link có thể có thời hạn và giới hạn
      số lượt mở — khi hết hạn hoặc hết lượt, link sẽ ngừng hoạt động.
    </p>
    <p>
      <a href="<?= wa_e(wa_url('index.php')) ?>">WebArchive</a> — lưu tri thức, đọc lại bất cứ lúc nào.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_guest.php'; ?>
