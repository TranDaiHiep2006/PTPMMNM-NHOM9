<?php
/**
 * search.php — Trang kết quả tìm kiếm nội dung đã lưu.
 *
 * Thể hiện giao diện của chức năng số 4 trong đặc tả: tìm kiếm dựa trên NỘI DUNG
 * ĐÃ LƯU (không phải trang gốc), mỗi kết quả hiển thị đoạn trích có từ khóa được
 * làm nổi bật và luôn gắn với đúng thư viện + đúng phiên bản snapshot.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$query = trim((string) ($_GET['q'] ?? ''));
$tagFilter = trim((string) ($_GET['tag'] ?? ''));
$domainFilter = trim((string) ($_GET['domain'] ?? ''));

// Tìm kiếm trực tiếp trên bảng snapshots (LIKE title + content_text),
// có lọc theo quyền truy cập của thư viện.
$results = wa_search_snapshots([
    'q'      => $query,
    'tag'    => $tagFilter,
    'domain' => $domainFilter,
]);

// Danh sách tên miền có trong kho (để lọc) + toàn bộ thẻ đang có.
$domains = wa_snapshot_domains();
$allTags = wa_all_tags();

$pageTitle = 'Tìm kiếm nội dung';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Truy xuất tri thức</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Offline Ready</span>
      </div>
      <h1 class="ribbon__title">Tìm kiếm nội dung đã lưu</h1>
      <p class="ribbon__sub">
        Tìm kiếm chạy trên nội dung của bản snapshot đã lưu — kết quả luôn trỏ về đúng phiên bản bạn đang đọc.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <span class="chip chip--teal">
        <span class="ms ms-14">offline_pin</span>
        <?= count($results) ?> kết quả trong kho nội dung
      </span>
    </div>
  </div>
</div>

<!-- ===================== Ô TÌM KIẾM ===================== -->
<form class="filter-bar" method="get" action="<?= wa_e(wa_url('search.php')) ?>">
  <div class="field" style="flex:1 1 320px">
    <label for="q">Từ khoá</label>
    <div class="searchbox" style="flex:1">
      <span class="ms">search</span>
      <input class="input" type="search" id="q" name="q" value="<?= wa_e($query) ?>"
             placeholder="Ví dụ: snapshot, MySQL, bảo mật..." aria-label="Từ khoá tìm kiếm">
    </div>
  </div>

  <div class="field">
    <label for="tag">Lọc theo thẻ</label>
    <select class="select" id="tag" name="tag">
      <option value="">Tất cả thẻ</option>
      <?php foreach ($allTags as $tag): ?>
        <option value="<?= wa_e($tag) ?>" <?= $tagFilter === $tag ? 'selected' : '' ?>><?= wa_e($tag) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="domain">Lọc theo tên miền nguồn</label>
    <select class="select" id="domain" name="domain">
      <option value="">Tất cả tên miền</option>
      <?php foreach ($domains as $domain): ?>
        <option value="<?= wa_e($domain) ?>" <?= $domainFilter === $domain ? 'selected' : '' ?>><?= wa_e($domain) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <button class="btn btn--primary" type="submit">
    <span class="ms ms-18">search</span>
    <span>Tìm kiếm</span>
  </button>

  <?php if ($query !== '' || $tagFilter !== '' || $domainFilter !== ''): ?>
    <a class="btn btn--ghost" href="<?= wa_e(wa_url('search.php')) ?>">Xoá bộ lọc</a>
  <?php endif; ?>
</form>

<?php if ($query === '' && $tagFilter === '' && $domainFilter === ''): ?>
  <div class="note-box">
    <b>Gợi ý:</b> thử tìm với các từ khoá như <code>snapshot</code>, <code>MySQL</code>, <code>bảo mật</code>,
    <code>liên kết nội bộ</code> hoặc <code>ảnh cục bộ</code>.
  </div>
<?php endif; ?>

<!-- ===================== KẾT QUẢ ===================== -->
<div style="margin-top:24px">
  <?php if ($query !== ''): ?>
    <p class="t-body-md t-muted" style="margin-bottom:16px">
      Tìm thấy <strong class="t-primary"><?= count($results) ?></strong> kết quả cho
      “<strong class="t-primary"><?= wa_e($query) ?></strong>” trong nội dung đã lưu.
    </p>
  <?php endif; ?>

  <?php foreach ($results as $hit): ?>
    <div class="result">
      <div class="result__top">
        <span class="chip chip--blue">
          <span class="ms ms-14">folder_special</span><?= wa_e($hit['library_name']) ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">history</span>Phiên bản snapshot: v<?= (int) $hit['version_no'] ?>.0
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">public</span><?= wa_e($hit['domain']) ?>
        </span>
      </div>

      <h3><?= wa_highlight($hit['title'], $query) ?></h3>

      <p class="result__snippet">
        <?= wa_highlight($hit['snippet'], $query) ?>
      </p>

      <div class="row" style="gap:6px;margin-top:12px">
        <?php foreach ($hit['tags'] as $tag): ?>
          <span class="tag"><?= wa_e($tag) ?></span>
        <?php endforeach; ?>
      </div>

      <div class="result__foot">
        <span><span class="ms ms-14">description</span>Snapshot: <strong>SNAP-<?= (int) $hit['id'] ?></strong></span>
        <span><span class="ms ms-14">public</span>Nguồn: <?= wa_e($hit['domain']) ?></span>
        <span><span class="ms ms-14">history</span>Lưu lúc: <?= wa_e($hit['saved_at']) ?></span>
        <span><span class="ms ms-14">image</span><?= (int) $hit['image_count'] ?> ảnh</span>
        <span><span class="ms ms-14">offline_pin</span>Sẵn sàng đọc ngoại tuyến</span>
        <a style="margin-left:auto;font-weight:600" href="<?= wa_e(wa_url('library.php?slug=' . urlencode((string) $hit['library_slug']))) ?>">
          Mở thư viện →
        </a>
        <a style="font-weight:600" href="<?= wa_e(wa_url('reader.php?id=' . (int) $hit['id'])) ?>">
          Đọc bản đã lưu →
        </a>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($results === []): ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy kết quả nào</h3>
      <p>
        Không có nội dung đã lưu nào khớp với từ khoá và bộ lọc hiện tại.
        Hãy thử một từ khoá khác hoặc xoá bớt điều kiện lọc.
      </p>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
