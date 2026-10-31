<?php
/**
 * check_update.php — Kiểm tra trang gốc có nội dung mới không, XEM TRƯỚC
 * thay đổi rồi mới quyết định có lưu thành bản mới hay không.
 *
 *   POST check_update.php (page_id + token CSRF, kèm snapshot_id tuỳ chọn)
 *   — chỉ nhận POST.
 *
 * Luồng:
 *   1. Kiểm tra quyền ghi (chủ sở hữu / biên tập viên / quản trị viên, giống
 *      nút "Lấy phiên bản mới"). Người xem không kiểm tra được.
 *   2. Tải trang gốc bằng ĐÚNG engine lưu trang (capture_url: allowlist trước
 *      mọi byte, tối đa 5 redirect) nhưng KHÔNG ghi gì vào snapshots — nội
 *      dung xem trước này bị huỷ sau khi trang hiển thị xong.
 *   3. So sánh khối với MỘT phiên bản đã lưu làm mốc: mặc định là bản mới nhất,
 *      nhưng menu so sánh từng dòng có thể gửi kèm snapshot_id để lấy đúng bản
 *      ở dòng đó làm mốc (vd đang xem v1 mà muốn xem v1 khác gì bản gốc).
 *      CHỈ khi mốc là bản mới nhất thì kết quả mới cập nhật cờ has_update:
 *      mốc là bản cũ mà khác bản gốc thì chưa chắc bản mới nhất đã khác, ghi
 *      cờ theo đó sẽ báo sai "có bản mới".
 *      - Giống hệt  -> báo "Chưa có gì mới" (mốc là bản mới nhất thì xoá cờ
 *        có-bản-mới nếu có; mốc là bản cũ thì không đụng tới cờ).
 *      - Khác       -> báo "Có phiên bản mới", hiện diff tô màu (mốc là bản
 *        mới nhất thì ghi cờ has_update + ngày kiểm tra để thẻ bài và trình
 *        đọc hiện badge; mốc là bản cũ thì chỉ hiện ghi chú, không ghi cờ).
 *   4. Muốn lưu thật thì bấm "Lưu thành bản mới" — nút này POST sang đường
 *      refetch.php cũ, tức là tải lại lần nữa cho chắc nội dung mới nhất rồi
 *      mới ghi snapshot (đường lưu cũ cũng tự xoá cờ có-bản-mới).
 *
 * LƯU Ý: lần kiểm tra tải lại trang gốc nên có thể mất vài giây (tối đa ~12s
 * cho một trang, giống lúc lưu trang).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/diff.php';

require_login();

$pageTitle = 'Kiểm tra bản mới';
$pageId = (int) ($_POST['page_id'] ?? 0);

/** Quay về trình đọc của bản mới nhất (hoặc về danh sách) kèm flash. */
$backToReader = static function (?int $snapshotId, array $flash): never {
    if ($snapshotId !== null && $snapshotId > 0) {
        $_SESSION['wa_flash'] = $flash;
        header('Location: ' . wa_url('reader.php?id=' . $snapshotId));
        exit;
    }
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('libraries.php'));
    exit;
};

/* --- Chỉ nhận POST (bấm nút "Kiểm tra bản mới" từ trình đọc). --- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $backId = (int) ($_GET['snapshot_id'] ?? 0);
    $backToReader($backId > 0 ? $backId : null, [
        'type' => 'error',
        'text' => 'Hãy bấm nút “Kiểm tra bản mới” từ trình đọc.',
    ]);
}

if (!wa_csrf_check()) {
    $backToReader(null, [
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ]);
}

/* --- Nạp bài viết + thư viện chứa nó. --- */
$page = null;
if ($pageId > 0) {
    $stmt = db()->prepare(
        'SELECT sp.id, sp.library_id, sp.source_url, l.slug AS library_slug, l.name AS library_name
           FROM `saved_pages` sp
           JOIN `libraries` l ON l.id = sp.library_id
          WHERE sp.id = ?
          LIMIT 1'
    );
    $stmt->execute([$pageId]);
    $page = $stmt->fetch() ?: null;
}

if ($page === null) {
    $backToReader(null, ['type' => 'error', 'text' => 'Không tìm thấy bài viết này.']);
}

$libraryId = (int) $page['library_id'];
$actor = wa_actor();
$role = wa_library_role($libraryId, $actor);
$canCheck = $actor['role'] === 'admin' || in_array($role, ['owner', 'editor'], true);

/* --- Phiên bản thành công mới nhất (mốc để so sánh). --- */
$versions = wa_page_versions($pageId, 0);
$latest = $versions[0] ?? null;

if (!$canCheck || $latest === null) {
    $backToReader($latest !== null ? (int) $latest['id'] : null, [
        'type' => 'error',
        'text' => $canCheck
            ? 'Bài này chưa có phiên bản thành công nào để so sánh.'
            : 'Bạn không có quyền kiểm tra bản mới. Chỉ chủ sở hữu và biên tập viên của thư viện mới kiểm tra được.',
    ]);
}

$latestId = (int) $latest['id'];
$latestNo = (int) $latest['version_no'];

/* --- Mốc so sánh: mặc định là bản mới nhất; menu so sánh từng dòng có thể
 * gửi kèm snapshot_id để lấy đúng bản ở dòng đó làm mốc. snapshot_id lạ
 * (không thuộc bài này hoặc không phải bản thành công) thì về lại bản mới nhất. --- */
$baseId = $latestId;
$baseNo = $latestNo;
$wantedBaseId = (int) ($_POST['snapshot_id'] ?? 0);
if ($wantedBaseId > 0 && $wantedBaseId !== $latestId) {
    $baseStmt = db()->prepare(
        'SELECT id, version_no FROM `snapshots`
          WHERE id = ? AND page_id = ? AND fetch_status = \'success\' LIMIT 1'
    );
    $baseStmt->execute([$wantedBaseId, $pageId]);
    $baseRow = $baseStmt->fetch();
    if ($baseRow !== false) {
        $baseId = (int) $baseRow['id'];
        $baseNo = (int) $baseRow['version_no'];
    }
}
$isLatestBase = ($baseId === $latestId);

/* --- Tải trang gốc để xem trước (KHÔNG ghi snapshot). --- */
$live = capture_url((string) $page['source_url']);

require __DIR__ . '/../templates/header_app.php';
?>

<div class="stack">
  <div>
    <a class="t-label-sm" href="<?= wa_e(wa_url('reader.php?id=' . $baseId)) ?>">&larr; Về trình đọc (phiên bản <?= $baseNo ?>)</a>
    <h1 class="pagehead__title" style="margin-top:8px">Kiểm tra bản mới</h1>
    <p class="t-body-md t-muted"><?= wa_e((string) $page['source_url']) ?></p>
  </div>

<?php if ($live['ok'] !== true): ?>
  <div class="form-alert">
    Không tải được trang gốc nên chưa thể so sánh (mã lỗi: <?= wa_e((string) $live['error_code']) ?>)
    — <?= wa_e((string) $live['error_message']) ?>.
    Trạng thái kiểm tra trước đó được giữ nguyên.
  </div>
  <div class="row">
    <a class="btn btn--dark" href="<?= wa_e(wa_url('reader.php?id=' . $baseId)) ?>">Về trình đọc</a>
  </div>
<?php else: ?>
  <?php
  $stmt = db()->prepare(
      'SELECT title, content_html, content_text
         FROM `snapshots`
        WHERE id = ? AND fetch_status = \'success\'
        LIMIT 1'
  );
  $stmt->execute([$baseId]);
  $saved = $stmt->fetch() ?: ['title' => '', 'content_html' => '', 'content_text' => ''];

  $savedUnits = wa_diff_units((string) $saved['content_html'], (string) $saved['content_text']);
  $liveUnits = wa_diff_units((string) $live['content_html'], (string) $live['content_text']);
  $ops = wa_diff_ops($savedUnits, $liveUnits);
  $stats = wa_diff_stats($ops);
  $titleChanged = ((string) $saved['title'] !== (string) $live['title']);
  $changed = wa_diff_changed($ops) || $titleChanged;

  // Ghi kết quả kiểm tra (cờ + ngày) CHỈ khi mốc là bản mới nhất. Mốc là
  // bản cũ thì không đụng tới cờ (xem ghi chú trong docblock đầu tệp).
  // Cột chưa migrate thì hàm tự trả false, trang vẫn hiển thị bình thường.
  if ($isLatestBase) {
      wa_page_check_mark($pageId, $changed);
  }
  ?>

  <?php if (!$changed): ?>
    <div class="form-alert form-alert--ok">
      Chưa có gì mới. Trang gốc chưa thay đổi so với phiên bản <?= $baseNo ?> đã lưu.
      <?php if ($isLatestBase): ?>
        Cờ “có bản mới” (nếu có) đã được xoá.
      <?php endif; ?>
    </div>
    <div class="row">
      <a class="btn btn--dark" href="<?= wa_e(wa_url('reader.php?id=' . $baseId)) ?>">Về trình đọc</a>
    </div>
  <?php else: ?>
    <div class="form-alert form-alert--ok">
      <strong>Có phiên bản mới!</strong> Trang gốc đã thay đổi so với phiên bản <?= $baseNo ?> đã lưu.
      Nội dung dưới đây chỉ là <strong>xem trước, chưa lưu</strong> — bấm “Lưu thành bản mới” mới ghi thật.
      <?php if (!$isLatestBase): ?>
        <br>Đang so sánh với phiên bản cũ (không phải bản mới nhất) nên kết quả này
        không cập nhật cờ “có bản mới”.
      <?php endif; ?>
    </div>

    <?php if ($titleChanged): ?>
      <p class="t-body-md">
        Tiêu đề đã đổi: <del><?= wa_e((string) $saved['title']) ?></del>
        &rarr; <strong><?= wa_e((string) $live['title']) ?></strong>
      </p>
    <?php endif; ?>

    <?php if ($stats['toolong']): ?>
      <div class="diff-notice">
        Bài quá dài nên trang không đếm được số khối thêm/xoá, nhưng trang gốc
        chắc chắn đã đổi (tiêu đề hoặc nội dung khác bản đã lưu).
      </div>
    <?php else: ?>
      <div class="diff-stats">
        <span><strong><?= (int) $stats['add'] ?></strong> khối thêm</span>
        <span><strong><?= (int) $stats['del'] ?></strong> khối xoá</span>
        <span><strong><?= (int) $stats['eq'] ?></strong> khối không đổi</span>
      </div>
    <?php endif; ?>

    <label class="t-body-md" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      <input type="checkbox" id="diff-only-changed">
      Chỉ hiện khối thay đổi
    </label>

    <div id="diff-output">
      <?= wa_diff_render_html($ops) ?>
    </div>

    <div class="row" style="margin-top:16px">
      <form method="post" action="<?= wa_e(wa_url('refetch.php')) ?>">
        <?= wa_csrf_field() ?>
        <input type="hidden" name="snapshot_id" value="<?= $latestId ?>">
        <button class="btn btn--dark" type="submit">
          <span class="ms ms-18">cloud_download</span>
          Lưu thành bản mới
        </button>
      </form>
      <a class="btn btn--outline" href="<?= wa_e(wa_url('reader.php?id=' . $baseId)) ?>">Để sau</a>
    </div>

    <script>
    (function () {
      var toggle = document.getElementById('diff-only-changed');
      if (toggle) {
        toggle.addEventListener('change', function () {
          document.querySelectorAll('#diff-output .diff-eq').forEach(function (el) {
            el.style.display = toggle.checked ? 'none' : '';
          });
        });
      }
    })();
    </script>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php
require __DIR__ . '/../templates/footer_app.php';
