<?php
/**
 * compare.php — So sánh hai phiên bản đã lưu của cùng một bài viết.
 *
 *   GET compare.php?page_id=<id>&from=<version_no>&to=<version_no>
 *
 * Quyền: chỉ cần đọc được thư viện (người xem cũng so sánh được). Hai phiên
 * bản đều phải là bản thành công (fetch_status = 'success'). So sánh theo
 * khối (xem src/diff.php): khối bị xoá tô đỏ, khối mới thêm tô xanh, khối
 * không đổi hiện thường và có thể ẩn bằng ô "Chỉ hiện khối thay đổi".
 *
 * Nếu bài chưa có đủ 2 phiên bản thì trang chỉ hiện ghi chú, không báo lỗi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/diff.php';

require_login();

$pageTitle = 'So sánh phiên bản';
$pageId = (int) ($_GET['page_id'] ?? 0);

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
    $pageTitle = 'Không tìm thấy bài viết';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy bài viết</h3>
      <p>Bài viết này không tồn tại hoặc đã bị xoá.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

$libraryId = (int) $page['library_id'];
$actor = wa_actor();

/* --- Quyền đọc: không phải thành viên thì 403 (mẫu giống reader.php). --- */
if (!wa_can_read_library($libraryId, $actor)) {
    http_response_code(403);
    $waDeniedContext = [
        'title' => 'Bài viết này thuộc thư viện khác',
        'intro' => 'Bài viết bạn muốn so sánh nằm trong một thư viện mà bạn không phải là thành viên. '
                  . 'Chỉ chủ sở hữu, biên tập viên, người xem của thư viện đó hoặc quản trị viên mới xem được.',
    ];
    require __DIR__ . '/../templates/error_no_permission.php';
    exit;
}

$versions = wa_page_versions($pageId, 0);
$libraryUrl = wa_url('library.php?slug=' . urlencode((string) $page['library_slug']));

require __DIR__ . '/../templates/header_app.php';
?>

<div class="stack">
  <div>
    <a class="t-label-sm" href="<?= wa_e($libraryUrl) ?>">&larr; Về <?= wa_e((string) $page['library_name']) ?></a>
    <h1 class="pagehead__title" style="margin-top:8px">So sánh phiên bản</h1>
    <p class="t-body-md t-muted"><?= wa_e((string) $page['source_url']) ?></p>
  </div>

<?php if (count($versions) < 2): ?>
  <div class="form-alert">
    Bài này mới có <?= count($versions) ?> phiên bản thành công nên chưa so sánh được.
    Hãy lấy thêm phiên bản mới rồi quay lại đây.
  </div>
  <?php if ($versions !== []): ?>
    <ul>
      <?php foreach ($versions as $v): ?>
        <li>
          <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $v['id'])) ?>">
            Phiên bản <?= (int) $v['version_no'] ?>
          </a>
          — <?= wa_e((string) $v['fetched_label']) ?>
          <?php if ((string) ($v['label'] ?? '') !== ''): ?>
            · <?= wa_e((string) $v['label']) ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php else: ?>
  <?php
  $nos = [];
  $meta = [];
  foreach ($versions as $v) {
      $nos[] = (int) $v['version_no'];
      $meta[(int) $v['version_no']] = $v;
  }
  // Mặc định: bản cũ = bản liền trước bản mới nhất, bản mới = bản mới nhất.
  $toNo = (int) ($_GET['to'] ?? 0);
  $fromNo = (int) ($_GET['from'] ?? 0);
  if (!in_array($toNo, $nos, true)) {
      $toNo = $nos[0];
  }
  if (!in_array($fromNo, $nos, true)) {
      $fromNo = $nos[1];
  }

  $stmt = db()->prepare(
      'SELECT version_no, title, content_html, content_text
         FROM `snapshots`
        WHERE page_id = ? AND version_no IN (?, ?) AND fetch_status = \'success\''
  );
  $stmt->execute([$pageId, $fromNo, $toNo]);
  $snaps = [];
  foreach ($stmt->fetchAll() as $row) {
      $snaps[(int) $row['version_no']] = $row;
  }
  $fromSnap = $snaps[$fromNo] ?? null;
  $toSnap = $snaps[$toNo] ?? null;
  ?>

  <form method="get" action="<?= wa_e(wa_url('compare.php')) ?>" class="compare-form">
    <input type="hidden" name="page_id" value="<?= $pageId ?>">
    <div class="field">
      <label for="compare-from">Bản cũ</label>
      <select class="select" id="compare-from" name="from">
        <?php foreach ($versions as $v): ?>
          <option value="<?= (int) $v['version_no'] ?>" <?= (int) $v['version_no'] === $fromNo ? 'selected' : '' ?>>
            Phiên bản <?= (int) $v['version_no'] ?> — <?= wa_e((string) $v['fetched_label']) ?>
            <?php if ((string) ($v['label'] ?? '') !== ''): ?>· <?= wa_e((string) $v['label']) ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn--outline" type="button" id="compare-swap" title="Đổi hai phiên bản cho nhau">
      <span class="ms ms-18">swap_horiz</span>
    </button>
    <div class="field">
      <label for="compare-to">Bản mới</label>
      <select class="select" id="compare-to" name="to">
        <?php foreach ($versions as $v): ?>
          <option value="<?= (int) $v['version_no'] ?>" <?= (int) $v['version_no'] === $toNo ? 'selected' : '' ?>>
            Phiên bản <?= (int) $v['version_no'] ?> — <?= wa_e((string) $v['fetched_label']) ?>
            <?php if ((string) ($v['label'] ?? '') !== ''): ?>· <?= wa_e((string) $v['label']) ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn--dark" type="submit">So sánh</button>
  </form>

  <?php if ($fromSnap === null || $toSnap === null): ?>
    <div class="form-alert">
      Không tải được một trong hai phiên bản đã chọn. Hãy chọn lại.
    </div>
  <?php else: ?>
    <?php
    $fromUnits = wa_diff_units((string) $fromSnap['content_html'], (string) $fromSnap['content_text']);
    $toUnits = wa_diff_units((string) $toSnap['content_html'], (string) $toSnap['content_text']);
    $ops = wa_diff_ops($fromUnits, $toUnits);
    $stats = wa_diff_stats($ops);
    $titleChanged = ((string) $fromSnap['title'] !== (string) $toSnap['title']);
    ?>

    <?php if ($fromNo === $toNo): ?>
      <div class="form-alert">Hai phiên bản chọn giống nhau nên không có gì để so sánh.</div>
    <?php endif; ?>

    <?php if ($titleChanged): ?>
      <p class="t-body-md">
        Tiêu đề đã đổi: <del><?= wa_e((string) $fromSnap['title']) ?></del>
        &rarr; <strong><?= wa_e((string) $toSnap['title']) ?></strong>
      </p>
    <?php endif; ?>

    <?php if ($stats['toolong']): ?>
      <div class="diff-notice">
        Bài quá dài nên trang không đếm được số khối thêm/xoá. Hãy so sánh hai
        phiên bản gần nhau hơn để thấy chi tiết.
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

    <script>
    (function () {
      var swap = document.getElementById('compare-swap');
      if (swap) {
        swap.addEventListener('click', function () {
          var from = document.getElementById('compare-from');
          var to = document.getElementById('compare-to');
          var tmp = from.value; from.value = to.value; to.value = tmp;
        });
      }
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
