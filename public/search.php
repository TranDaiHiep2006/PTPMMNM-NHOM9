<?php
/**
 * search.php — Trang kết quả tìm kiếm nội dung đã lưu (P-SEARCH).
 *
 * Thể hiện giao diện của chức năng số 4 trong đặc tả: tìm kiếm dựa trên NỘI DUNG
 * ĐÃ LƯU, mỗi kết quả hiển thị đoạn trích có từ khoá được làm nổi bật và luôn
 * gắn với đúng thư viện + đúng PHIÊN BẢN snapshot.
 *
 * Trang này KHÔNG tự quét `snapshots.title` / `content_text`. Nó gọi
 * wa_search_snapshots() trong src/search.php, hàm chạy trên bảng chỉ mục đảo
 * `search_terms` do dự án tự dựng: ngữ nghĩa AND, xếp hạng
 * score = 3 × SUM(count_title) + SUM(count_body), quyền truy cập lọc trong SQL.
 *
 * Tham số GET (không đổi so với trước):
 *   q       — từ khoá (nhiều từ = ngữ nghĩa AND)
 *   tag     — lọc theo nhãn
 *   domain  — lọc theo tên miền nguồn
 *   library — slug thư viện, chỉ tìm trong thư viện đó
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$query        = trim((string) ($_GET['q'] ?? ''));
$tagFilter    = trim((string) ($_GET['tag'] ?? ''));

// Hiển thị và so khớp lựa chọn luôn ở dạng '#...' (gõ tay 'php' trên URL
// vẫn chọn đúng mục '#php' trong danh sách lọc).
if ($tagFilter !== '' && !str_starts_with($tagFilter, '#')) {
    $tagFilter = '#' . $tagFilter;
}
$domainFilter = trim((string) ($_GET['domain'] ?? ''));

// Lọc theo thư viện (khi bấm nhãn trong một thư viện): slug phải thuộc về
// thư viện mà người dùng được xem, nếu không thì bỏ qua lọc này (tìm toàn bộ).
$librarySlug = trim((string) ($_GET['library'] ?? ''));
$scopeLibrary = $librarySlug !== '' ? wa_find_library($librarySlug) : null;

// Link "Bỏ lọc thư viện": giữ nguyên từ khoá + các lọc khác, chỉ bỏ library.
$unscopedParams = [];
if ($query !== '') { $unscopedParams['q'] = $query; }
if ($tagFilter !== '') { $unscopedParams['tag'] = $tagFilter; }
if ($domainFilter !== '') { $unscopedParams['domain'] = $domainFilter; }
$unscopedUrl = wa_url('search.php' . ($unscopedParams !== [] ? '?' . http_build_query($unscopedParams) : ''));

// Truy vấn CHỈ trên chỉ mục đảo. Từ khoá rỗng (hoặc chỉ gõ dấu câu) thì hàm
// trả về rỗng và trang hiện đúng gợi ý tiếng Việt bên dưới.
$results = wa_search_snapshots([
    'q'       => $query,
    'tag'     => $tagFilter,
    'domain'  => $domainFilter,
    'library' => $librarySlug,
]);

// Danh sách tên miền có trong kho (để lọc) + thẻ đang có. Khi đã lọc theo
// thư viện thì hộp chọn nhãn chỉ liệt kê nhãn của thư viện đó.
$domains = wa_snapshot_domains();
$allTags = $scopeLibrary !== null
    ? wa_library_tags((int) $scopeLibrary['id'])
    : wa_all_tags();

// Điều kiện "đã thực sự tìm" để quyết định hiện thông báo kết quả hay gợi ý.
$hasQuery = wa_search_query_terms($query) !== [];
$hasFilter = $tagFilter !== '' || $domainFilter !== '';
$pageTitle = 'Tìm kiếm nội dung';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Truy xuất tri thức</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Chỉ mục đảo tự xây</span>
      </div>
      <h1 class="ribbon__title">Tìm kiếm nội dung đã lưu</h1>
      <p class="ribbon__sub">
        Tìm kiếm chạy trên <b>chỉ mục đảo tự xây dựng</b> của nội dung bản snapshot đã lưu —
        không phân biệt dấu tiếng Việt, nhiều từ là <b>và</b> (AND), và kết quả luôn trỏ về đúng phiên bản bạn đang đọc.
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
             placeholder="Ví dụ: lưu trang, mã hoá, snapshot..." aria-label="Từ khoá tìm kiếm">
    </div>
  </div>

  <div class="field">
    <label for="tag-input">Lọc theo thẻ</label>
    <div class="combo">
      <input class="input combo__input" type="text" id="tag-input" name="tag"
             value="<?= wa_e($tagFilter) ?>" placeholder="Tất cả thẻ"
             autocomplete="off" role="combobox" aria-expanded="false" aria-controls="tag-list"
             aria-label="Lọc theo thẻ, gõ để tìm">
      <div class="combo__list" id="tag-list" role="listbox" hidden>
        <button type="button" class="combo__option combo__option--all" data-value="">Tất cả thẻ</button>
        <?php foreach ($allTags as $tag): ?>
          <button type="button" class="combo__option" data-value="<?= wa_e($tag) ?>"><?= wa_e($tag) ?></button>
        <?php endforeach; ?>
        <div class="combo__empty t-label-sm t-muted" hidden>Không có thẻ nào khớp.</div>
      </div>
    </div>
  </div>

  <div class="field">
    <label for="domain-input">Lọc theo tên miền nguồn</label>
    <div class="combo">
      <input class="input combo__input" type="text" id="domain-input" name="domain"
             value="<?= wa_e($domainFilter) ?>" placeholder="Tất cả tên miền"
             autocomplete="off" role="combobox" aria-expanded="false" aria-controls="domain-list"
             aria-label="Lọc theo tên miền nguồn, gõ để tìm">
      <div class="combo__list" id="domain-list" role="listbox" hidden>
        <button type="button" class="combo__option combo__option--all" data-value="">Tất cả tên miền</button>
        <?php foreach ($domains as $domain): ?>
          <button type="button" class="combo__option" data-value="<?= wa_e($domain) ?>"><?= wa_e($domain) ?></button>
        <?php endforeach; ?>
        <div class="combo__empty t-label-sm t-muted" hidden>Không có tên miền nào khớp.</div>
      </div>
    </div>
  </div>

  <?php if ($scopeLibrary !== null): ?>
    <input type="hidden" name="library" value="<?= wa_e($scopeLibrary['slug']) ?>">
  <?php endif; ?>

  <button class="btn btn--primary" type="submit">
    <span class="ms ms-18">search</span>
    <span>Tìm kiếm</span>
  </button>

  <?php if ($query !== '' || $hasFilter): ?>
    <a class="btn btn--ghost" href="<?= wa_e(wa_url('search.php')) ?>">Xoá bộ lọc</a>
  <?php endif; ?>
</form>

<?php if ($scopeLibrary !== null): ?>
  <p class="t-body-md t-muted" style="margin-top:16px">
    Đang tìm trong thư viện <strong class="t-primary"><?= wa_e($scopeLibrary['name']) ?></strong>.
    <a href="<?= wa_e($unscopedUrl) ?>">Bỏ lọc thư viện</a> để tìm trong tất cả thư viện
    · <a href="<?= wa_e(wa_url('library.php?slug=' . urlencode($scopeLibrary['slug']))) ?>">Về thư viện</a>
  </p>
<?php endif; ?>

<?php if (!$hasQuery): ?>
  <div class="note-box">
    <b>Gợi ý:</b> nhập ít nhất một từ có nghĩa. Từ khoá được <b>bỏ dấu</b> trước khi
    so khớp, nên gõ <code>luu trang</code> vẫn ra nội dung có dấu là
    <code>lưu trang</code>. Nhiều từ được hiểu là <b>và</b> (AND): bài viết phải chứa
    <i>tất cả</i> các từ đó. Thử với các từ khoá như <code>snapshot</code>,
    <code>mã hoá</code>, <code>lưu trang</code>, <code>ảnh cục bộ</code> hoặc
    <code>allowlist</code>.
  </div>
<?php endif; ?>

<!-- ===================== KẾT QUẢ ===================== -->
<div style="margin-top:24px">
  <?php if ($hasQuery || $hasFilter): ?>
    <p class="t-body-md t-muted" style="margin-bottom:16px">
      Tìm thấy <strong class="t-primary"><?= count($results) ?></strong> kết quả
      <?php if ($hasQuery): ?>
        cho “<strong class="t-primary"><?= wa_e($query) ?></strong>”
      <?php endif; ?>
      <?php if ($tagFilter !== ''): ?>
        với nhãn <strong class="t-primary"><?= wa_e($tagFilter) ?></strong>
      <?php endif; ?>
      <?php if ($domainFilter !== ''): ?>
        từ miền <strong class="t-primary"><?= wa_e($domainFilter) ?></strong>
      <?php endif; ?>
      trong nội dung đã lưu.
      <?php if ($hasQuery): ?>
        Xếp theo điểm <code>3 × (số lần trong tiêu đề) + (số lần trong thân)</code>,
        sau đó tới bản lưu mới nhất.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php foreach ($results as $hit): ?>
    <?php
    // Điều kiện so khớp dùng chung cho cả tiêu đề lẫn đoạn trích.
    $hitTerms = wa_search_query_terms($query);
    ?>
    <div class="result">
      <div class="result__top">
        <span class="chip chip--blue">
          <span class="ms ms-14">folder_special</span><?= wa_e($hit['library_name']) ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">history</span>Phiên bản <?= (int) $hit['version_no'] ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">public</span><?= wa_e($hit['domain']) ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">tag</span>Điểm <?= (int) $hit['score'] ?>
        </span>
      </div>

      <h3><?= wa_search_highlight_title((string) $hit['title'], $hitTerms) ?></h3>

      <p class="result__snippet">
        <?= $hit['snippet'] /* đã escape + bọc <mark> sẵn trong wa_search_snippet() */ ?>
      </p>

      <div class="row" style="gap:6px;margin-top:12px">
        <?php foreach ($hit['tags'] as $tag): ?>
          <span class="tag"><?= wa_e($tag) ?></span>
        <?php endforeach; ?>
      </div>

      <div class="result__foot">
        <span><span class="ms ms-14">description</span>Snapshot: <strong>SNAP-<?= (int) $hit['id'] ?></strong></span>
        <span><span class="ms ms-14">public</span>Nguồn: <?= wa_e($hit['domain']) ?></span>
        <span><span class="ms ms-14">schedule</span>Lưu lúc: <?= wa_e($hit['saved_at']) ?></span>
        <span><span class="ms ms-14">image</span><?= (int) $hit['image_count'] ?> ảnh</span>
        <a style="margin-left:auto;font-weight:600" href="<?= wa_e(wa_url('library.php?slug=' . urlencode((string) $hit['library_slug']))) ?>">
          Mở thư viện →
        </a>
        <a style="font-weight:600" href="<?= wa_e(wa_url('reader.php?id=' . (int) $hit['id'])) ?>">
          Đọc bản đã lưu →
        </a>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (($hasQuery || $hasFilter) && $results === []): ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy kết quả nào</h3>
      <p>
        <?php if ($hasQuery): ?>
          Không có bản snapshot nào chứa <b>tất cả</b> các từ trong
          “<strong><?= wa_e($query) ?></strong>” mà bạn được phép xem.
        <?php else: ?>
          Không có bản snapshot nào khớp với bộ lọc mà bạn đang chọn.
        <?php endif; ?>
        Hãy thử một từ khoá khác, bỏ bớt từ (vì nhiều từ là điều kiện <b>và</b>),
        hoặc xoá bớt điều kiện lọc.
      </p>
    </div>
  <?php endif; ?>
</div>

<script>
  /* Ô lọc thẻ / tên miền ở trang Tìm kiếm: ô nhập + danh sách xổ xuống lọc
     trực tiếp khi gõ (thay cho <select> thuần, quá dài khi nhiều thẻ / nhiều
     tên miền). Bấm vào một lựa chọn thì ô nhận đúng giá trị đó; "Tất cả..."
     thì xoá lọc. Không có JS thì ô nhập vẫn gửi đúng giá trị gõ tay. */
  function wa_fold(text) {
    return text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
  }
  function wa_match(haystack, needle) {
    if (haystack.indexOf(needle) !== -1) { return true; }
    var j = 0;
    for (var i = 0; i < haystack.length && j < needle.length; i++) {
      if (haystack[i] === needle[j]) { j++; }
    }
    return j === needle.length;
  }
  function wa_init_combo(inputId, listId) {
    var input = document.getElementById(inputId);
    var list = document.getElementById(listId);
    if (!input || !list) { return; }
    var options = Array.prototype.slice.call(list.querySelectorAll('.combo__option'));
    var empty = list.querySelector('.combo__empty');
    var highlighted = -1;
    function visibleOptions() {
      return options.filter(function (opt) { return !opt.hidden; });
    }
    function setHighlight(index) {
      var vis = visibleOptions();
      options.forEach(function (opt) { opt.classList.remove('combo__option--active'); });
      highlighted = index;
      if (vis[index]) { vis[index].classList.add('combo__option--active'); }
    }
    function open() {
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      setHighlight(-1);
    }
    function filter() {
      var needle = wa_fold(input.value.trim());
      var any = false;
      options.forEach(function (opt) {
        if (opt.classList.contains('combo__option--all')) { return; }
        var match = needle === '' || wa_match(wa_fold(opt.getAttribute('data-value') || ''), needle);
        opt.hidden = !match;
        if (match) { any = true; }
      });
      if (empty) { empty.hidden = any; }
      setHighlight(-1);
    }
    options.forEach(function (opt) {
      opt.addEventListener('click', function () {
        input.value = opt.getAttribute('data-value') || '';
        filter();
        close();
        input.focus();
      });
    });
    input.addEventListener('focus', function () { filter(); open(); });
    input.addEventListener('click', function () { filter(); open(); });
    input.addEventListener('input', function () { filter(); open(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        open();
        var vis = visibleOptions();
        if (vis.length === 0) { return; }
        var next = e.key === 'ArrowDown'
          ? (highlighted + 1) % vis.length
          : (highlighted - 1 + vis.length) % vis.length;
        setHighlight(next);
        return;
      }
      if (e.key === 'Enter') {
        var vis = visibleOptions();
        if (!list.hidden && vis[highlighted]) {
          e.preventDefault();
          vis[highlighted].click();
        }
      }
    });
    document.addEventListener('click', function (e) {
      var combo = e.target.closest('.combo');
      if (!combo || !combo.contains(input)) { close(); }
    });
  }
  wa_init_combo('tag-input', 'tag-list');
  wa_init_combo('domain-input', 'domain-list');
</script>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>