<?php
/**
 * version_search.php — TÌM LỊCH SỬ XUẤT HIỆN THÔNG TIN (V3 / FEATURE 5).
 *
 * Nhập một từ / cụm từ, xem:
 *   - phiên bản ĐẦU TIÊN có cụm từ đó,
 *   - những phiên bản vẫn còn chứa nó,
 *   - phiên bản ĐẦU TIÊN cụm từ biến mất (nếu có),
 *   - timeline V1 → V2 → V3… kèm liên kết mở đúng snapshot.
 *
 * Dùng `snapshots.content_text` (dữ liệu mà hệ thống diff đang dùng) nên
 * KHÔNG tạo chỉ mục mới.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user     = current_user();
$userId   = (int) ($user['id'] ?? 0);
$pageId   = (int) ($_GET['page_id'] ?? 0);
$query    = trim((string) ($_GET['q'] ?? ''));

$result = [
    'ok'                => false,
    'message'           => '',
    'query'             => $query,
    'versions'          => [],
    'first_appeared'    => null,
    'last_appeared'     => null,
    'first_disappeared' => null,
    'still_present'     => false,
];

$page = null;

if ($pageId > 0) {
    $stmt = db()->prepare(
        'SELECT sp.id, sp.title, sp.source_url, sp.domain, sp.library_id, l.name AS library_name
           FROM `saved_pages` sp
           JOIN `libraries` l ON l.id = sp.library_id
          WHERE sp.id = ? LIMIT 1'
    );
    $stmt->execute([$pageId]);
    $row = $stmt->fetch();

    if ($row !== false && wa_can_read_library((int) $row['library_id'])) {
        $page = $row;
    }
}

if ($page !== null && $query !== '') {
    $result = wa_version_search($pageId, $query);
}

$pageTitle = 'Tìm trong lịch sử phiên bản';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Nghiên cứu</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Lịch sử phiên bản</span>
      </div>
      <h1 class="ribbon__title">Tìm trong lịch sử phiên bản</h1>
      <p class="ribbon__sub">
        Biết cụm từ xuất hiện từ phiên bản nào, tồn tại đến khi nào và biến mất
        vào lúc nào — không cần mở từng bản.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <?php if ($page !== null): ?>
        <a class="btn btn--outline" href="<?= wa_e(wa_url('reader.php?id=' . (int) ($result['versions'][0]['id'] ?? 0))) ?>">
          <span class="ms ms-18">arrow_back</span>
          <span>Về bài đang đọc</span>
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">manage_search</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Tìm một cụm từ</h2>
        <p class="t-body-sm t-muted">
          Chọn bài viết và nhập từ / cụm từ cần truy vết trong toàn bộ lịch sử
          phiên bản của bài đó.
        </p>
      </div>
    </div>
  </div>

  <form class="capture__form" method="get" action="<?= wa_e(wa_url('version_search.php')) ?>">
    <div class="capture__input">
      <span class="ms ms-18">article</span>
      <input class="input input--icon" type="number" name="page_id"
             value="<?= $pageId > 0 ? (int) $pageId : '' ?>" required
             placeholder="Mã bài viết (page id)"
             aria-label="Mã bài viết">
    </div>

    <div class="capture__input">
      <span class="ms ms-18">search</span>
      <input class="input input--icon" type="text" name="q"
             value="<?= wa_e($query) ?>"
             placeholder="cụm từ cần tìm"
             aria-label="Từ hoặc cụm từ cần tìm">
    </div>

    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">travel_explore</span>
      <span>Tìm trong lịch sử</span>
    </button>
  </form>

  <?php if ($page === null && $pageId > 0): ?>
    <div class="capture__hint">
      <p>Không tìm thấy bài viết này hoặc bạn không có quyền đọc thư viện chứa nó.</p>
    </div>
  <?php endif; ?>
</div>

<?php if ($page !== null): ?>
  <div class="pagehead" style="margin-top:24px">
    <div>
      <div class="pagehead__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Bài đang tra cứu</span>
      </div>
      <h2 class="t-headline-md" style="color:var(--wa-primary)"><?= wa_e((string) $page['title']) ?></h2>
      <p class="pagehead__desc">
        <?= wa_e((string) $page['domain']) ?>
        · Thư viện: <?= wa_e((string) $page['library_name']) ?>
        · <a href="<?= wa_e(wa_url('reader.php?id=' . (int) ($result['versions'][0]['id'] ?? 0))) ?>">mở trình đọc</a>
      </p>
    </div>
  </div>

  <?php if ($query === ''): ?>
    <p class="t-body-sm t-muted" style="margin-top:12px">
      Nhập một từ hoặc cụm từ để bắt đầu tra cứu.
    </p>
  <?php elseif (!$result['ok']): ?>
    <div class="state state--error" style="margin-top:16px">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill">error_outline</span></span>
        <div>
          <span class="state__title">Không tìm được</span>
          <p class="state__text"><?= wa_e((string) $result['message']) ?></p>
        </div>
      </div>
    </div>
  <?php else: ?>

    <?php if ($result['first_appeared'] === null): ?>
      <div class="state state--error" style="margin-top:16px">
        <div class="state__body">
          <span class="state__icon"><span class="ms ms-18 ms-fill">search_off</span></span>
          <div>
            <span class="state__title">Cụm từ không xuất hiện</span>
            <p class="state__text">
              Không phiên bản nào của bài này chứa “<?= wa_e($query) ?>”.
            </p>
          </div>
        </div>
      </div>
    <?php else: ?>

      <div class="state-list" style="margin-top:16px">
        <div class="state-item">
          <div class="state-item__head">
            <span class="state-item__name">
              <span class="ms ms-18">flag</span>
              Xuất hiện lần đầu
            </span>
            <span class="chip chip--teal">Phiên bản <?= (int) $result['first_appeared']['version_no'] ?></span>
          </div>
          <p class="t-body-sm t-muted" style="margin-top:8px">
            <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $result['first_appeared']['id'])) ?>">
              Mở snapshot <?= (int) $result['first_appeared']['id'] ?>
            </a>
            · <?= wa_e((string) $result['first_appeared']['fetched_label']) ?>
          </p>
        </div>

        <div class="state-item">
          <div class="state-item__head">
            <span class="state-item__name">
              <span class="ms ms-18">visibility</span>
              Còn xuất hiện ở
            </span>
            <span class="chip chip--plain">
              <?= $result['still_present']
                  ? 'Còn trong bản mới nhất'
                  : 'Đã biến mất' ?>
            </span>
          </div>
          <p class="t-body-sm t-muted" style="margin-top:8px">
            Lần cuối còn xuất hiện ở phiên bản
            <?= (int) $result['last_appeared']['version_no'] ?>
            · <?= wa_e((string) $result['last_appeared']['fetched_label']) ?>
          </p>
        </div>

        <?php if ($result['first_disappeared'] !== null): ?>
          <div class="state-item">
            <div class="state-item__head">
              <span class="state-item__name">
                <span class="ms ms-18">visibility_off</span>
                Biến mất lần đầu
              </span>
              <span class="chip chip--warn">Phiên bản <?= (int) $result['first_disappeared']['version_no'] ?></span>
            </div>
            <p class="t-body-sm t-muted" style="margin-top:8px">
              <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $result['first_disappeared']['id'])) ?>">
                Mở snapshot <?= (int) $result['first_disappeared']['id'] ?>
              </a>
              — từ bản này trở đi cụm từ không còn trong nội dung.
            </p>
          </div>
        <?php endif; ?>
      </div>

      <!-- ===================== TIMELINE V1 → V2 → V3 ===================== -->
      <div class="pagehead" style="margin-top:24px">
        <div>
          <div class="pagehead__eyebrow">
            <span class="t-label-sm t-upper t-accent" style="font-weight:600">Dòng thời gian</span>
          </div>
          <h2 class="t-headline-md" style="color:var(--wa-primary)">
            <?= count($result['versions']) ?> phiên bản — “<?= wa_e($query) ?>”
          </h2>
        </div>
      </div>

      <div class="state-list">
        <?php foreach ($result['versions'] as $v): ?>
          <div class="state-item">
            <div class="state-item__head">
              <span class="state-item__name">
                <span class="ms ms-18"><?= $v['contains'] ? 'check_circle' : 'cancel' ?></span>
                Phiên bản <?= (int) $v['version_no'] ?>
              </span>
              <span class="chip chip--<?= $v['contains'] ? 'teal' : 'plain' ?>">
                <?= $v['contains'] ? 'có cụm từ' : 'không có' ?>
              </span>
            </div>
            <p class="t-body-sm t-muted" style="margin-top:8px">
              <?= wa_e((string) $v['fetched_label']) ?>
              · <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $v['id'])) ?>">mở snapshot <?= (int) $v['id'] ?></a>
              <?php if ($v['contains'] && $v['snippet'] !== ''): ?>
                <br><span style="color:var(--wa-text-soft)">…<?= wa_e((string) $v['snippet']) ?>…</span>
              <?php endif; ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
