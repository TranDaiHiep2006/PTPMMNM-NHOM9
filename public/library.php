<?php
/**
 * library.php — Chi tiết thư viện.
 *
 * Bám theo bản tham chiếu (code2.html): breadcrumb, số liệu thư viện, bảng
 * thành viên & nhãn, thẻ bài viết, phân trang và bảng lưu URL hiển thị TRẠNG
 * THÁI THÀNH CÔNG.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$slug = (string) ($_GET['slug'] ?? 'cntt-lap-trinh');
$library = sample_find_library($slug);

if ($library === null) {
    http_response_code(404);
    $pageTitle = 'Không tìm thấy thư viện';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy thư viện</h3>
      <p>Thư viện “<?= wa_e($slug) ?>” không tồn tại hoặc bạn không có quyền truy cập.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

$articles = sample_articles_by_library((string) $library['id']);
$members = sample_members();

// Phân trang (mô phỏng giao diện): 6 bài mỗi trang.
$perPage = 6;
$totalPages = max(1, (int) ceil(count($articles) / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$pagedArticles = array_slice($articles, ($page - 1) * $perPage, $perPage);

// Bảng lưu URL luôn hiển thị trạng thái THÀNH CÔNG (đúng như bản tham chiếu).
$capture = mock_capture('https://phptutorial.vn/kien-truc-php-mysql-webarchive');

$pageTitle = $library['name'];
require __DIR__ . '/../templates/header_app.php';
?>

<!-- ===================== A. BREADCRUMB ===================== -->
<div class="breadcrumb">
  <div class="breadcrumb__path">
    <a href="<?= wa_e(wa_url('libraries.php')) ?>">
      <span class="ms ms-14" style="color:var(--wa-action)">arrow_back</span>
      Thư viện của tôi
    </a>
    <span class="sep">/</span>
    <b><?= wa_e($library['name']) ?></b>
  </div>
  <div class="breadcrumb__meta">
    <span class="chip chip--plain">
      <span class="dot dot--teal"></span>
      Thư viện mở • <?= $library['article_count'] ?> bài viết
    </span>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-action)">lock_open</span>
      <?= wa_e($library['visibility']) ?>
    </span>
  </div>
</div>

<!-- ===================== B. TIÊU ĐỀ & HÀNH ĐỘNG ===================== -->
<div class="pagehead">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Bộ sưu tập chuyên đề</span>
      <span class="t-muted" style="font-weight:300">•</span>
      <span class="t-label-sm" style="color:#74777E">ID: <?= wa_e($library['id']) ?></span>
    </div>
    <h1 class="pagehead__title"><?= wa_e($library['name']) ?></h1>
    <p class="pagehead__desc"><?= wa_e($library['description']) ?> Bảo tồn snapshot ngoại tuyến độc lập với máy chủ gốc.</p>
  </div>

  <div class="pagehead__actions">
    <button class="btn btn--outline" type="button">
      <span class="ms ms-18">group</span>
      Quản lý thành viên (<?= $library['member_count'] ?>)
    </button>
    <button class="btn btn--outline" type="button">
      <span class="ms ms-18">sell</span>
      Quản lý nhãn (<?= $library['tag_count'] ?>)
    </button>
    <a class="btn btn--dark" href="#quick-capture">
      <span class="ms ms-18">add_link</span>
      Lưu trang Web mới
    </a>
  </div>
</div>

<!-- ===================== C. SỐ LIỆU ===================== -->
<div class="stat-cards" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Bài lưu trữ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">menu_book</span>
    </div>
    <div class="stat-card__value"><b><?= $library['article_count'] ?></b><span>snapshot</span></div>
    <div class="stat-card__note" style="color:var(--wa-action)">
      <span class="dot dot--teal"></span> <?= $library['integrity'] ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Ảnh cục bộ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">photo_library</span>
    </div>
    <div class="stat-card__value"><b><?= $library['image_count'] ?></b><span>tập tin</span></div>
    <div class="stat-card__note">Dung lượng ~<?= wa_e($library['asset_size']) ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Nhãn nội dung</span>
      <span class="ms ms-18" style="color:var(--wa-action)">local_offer</span>
    </div>
    <div class="stat-card__value"><b><?= $library['tag_count'] ?></b><span>phân mục</span></div>
    <div class="stat-card__note" style="color:var(--wa-action)">Phổ biến: <?= wa_e($library['tags'][0]) ?> <?= wa_e($library['tags'][1] ?? '') ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Thành viên</span>
      <span class="ms ms-18" style="color:var(--wa-action)">supervised_user_circle</span>
    </div>
    <div class="stat-card__value"><b><?= $library['member_count'] ?></b><span>tài khoản</span></div>
    <div class="stat-card__note">1 Chủ sở hữu • 1 Biên tập viên • 1 Người xem</div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Toàn vẹn tệp</span>
      <span class="ms ms-18" style="color:var(--wa-teal-deep)">verified</span>
    </div>
    <div class="stat-card__value"><b><?= $library['broken_images'] ?></b><span>Ảnh lỗi</span></div>
    <div class="stat-card__note" style="color:var(--wa-teal-deep)">Bảo toàn vĩnh viễn 100%</div>
  </div>
</div>

<!-- ===================== D. BẢNG LƯU URL (LUÔN THÀNH CÔNG) ===================== -->
<div class="capture" id="quick-capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">archive</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Lưu bài viết vào thư viện này</h2>
        <p class="t-body-sm t-muted">Tự động trích xuất nội dung văn bản, chuyển đổi hình ảnh về lưu trữ máy chủ cục bộ.</p>
      </div>
    </div>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">bolt</span> HTML snapshot engine
    </span>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('save.php')) ?>">
    <div class="capture__input">
      <span class="ms ms-18">link</span>
      <input class="input input--icon" type="url" name="url" required
             value="https://phptutorial.vn/kien-truc-php-mysql-webarchive"
             placeholder="https://example.com/bai-viet-lap-trinh-web"
             aria-label="Địa chỉ trang Web cần lưu">
    </div>
    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">download_for_offline</span>
      <span>Lưu trang Web</span>
    </button>
  </form>

  <div class="capture__hint">
    <p>Dán liên kết bài viết thuộc website được phép lưu (HTML tĩnh công khai).</p>
    <button class="linkish" type="button" id="guidance-toggle" aria-expanded="false" aria-controls="guidance-details">
      <span class="ms ms-16">help_outline</span>
      Các URL được phép lưu là gì?
    </button>
  </div>

  <div class="guidance" id="guidance-details" hidden>
    <div class="row" style="align-items:flex-start;gap:12px">
      <span class="ms ms-18" style="color:var(--wa-action);flex:0 0 18px;margin-top:2px">info</span>
      <div>
        <strong>Quy chuẩn bóc tách snapshot độc lập:</strong>
        Hệ thống hỗ trợ hoàn hảo các trang tin tức, blog kỹ thuật, bài nghiên cứu công nghệ không yêu cầu
        bức tường phí (paywall) hoặc xác thực 2 lớp. Trình bóc tách sẽ tải mã HTML gốc, bóc tách thẻ chứa
        nội dung chính và ánh xạ toàn bộ thẻ <code>&lt;img&gt;</code> sang định dạng cục bộ.
      </div>
    </div>
  </div>

  <!-- Trạng thái THÀNH CÔNG (đúng như bản tham chiếu) -->
  <div class="state state--success">
    <div class="state__body">
      <span class="state__icon"><span class="ms ms-18 ms-fill">check_circle</span></span>
      <div>
        <div class="row" style="gap:8px">
          <span class="state__title">Lưu thành công:</span>
          <span class="state__text"><?= wa_e($capture['message']) ?></span>
        </div>
        <p class="state__note">14 ảnh đã tải về máy chủ nội bộ • Dung lượng lưu trữ: 1.84 MB • Bản đọc offline đã sẵn sàng</p>
      </div>
    </div>
    <div class="state__actions">
      <a class="btn btn--light" href="<?= wa_e(wa_url('reader.php?id=SNAP-8941')) ?>">
        <span class="ms ms-16" style="color:var(--wa-teal-deep)">chrome_reader_mode</span>
        Mở xem ngay
      </a>
    </div>
  </div>
</div>

<!-- ===================== G. THÀNH VIÊN & NHÃN ===================== -->
<div class="two-col" style="margin-bottom:24px">
  <div class="two-col__main panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">badge</span>
        Thành viên &amp; Phân quyền thư viện (<?= $library['member_count'] ?>)
      </span>
      <a class="t-label-sm t-accent" href="#" style="font-weight:500">+ Mời thêm</a>
    </div>
    <div class="member-list">
      <?php foreach ($members as $member): ?>
        <span class="member-pill">
          <span class="avatar avatar--<?= wa_e($member['variant']) ?>"><?= wa_e($member['initials']) ?></span>
          <span class="member-pill__name"><?= wa_e($member['name']) ?></span>
          <span class="member-pill__role member-pill__role--<?= wa_e($member['role']) ?>"><?= wa_e($member['role_label']) ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="two-col__side panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">sell</span>
        Nhãn phân loại (<?= $library['tag_count'] ?>)
      </span>
      <span class="t-label-sm t-muted">Lọc nhanh</span>
    </div>
    <div class="row" style="gap:6px">
      <?php foreach ($library['tags'] as $index => $tag): ?>
        <button class="tag <?= $index === 0 ? 'tag--active' : 'tag--btn' ?>" type="button"><?= wa_e($tag) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ===================== E. TAB + LỌC ===================== -->
<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Điều hướng trong thư viện">
    <button type="button" class="is-active"><span class="ms ms-16">article</span> Tất cả bài viết (<?= $library['article_count'] ?>)</button>
    <button type="button"><span class="ms ms-16">label</span> Nhãn phân loại (<?= $library['tag_count'] ?>)</button>
    <button type="button"><span class="ms ms-16">manage_accounts</span> Thành viên &amp; phân quyền (<?= $library['member_count'] ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" placeholder="Lọc trong thư viện này..." aria-label="Lọc trong thư viện này">
    </div>
    <button class="btn btn--outline" type="button" title="Sắp xếp" aria-label="Sắp xếp">
      <span class="ms ms-18">sort</span>
    </button>
  </div>
</div>

<!-- ===================== F. THẺ BÀI VIẾT ===================== -->
<div class="article-grid">
  <?php foreach ($pagedArticles as $article): ?>
    <div class="article-card">
      <div>
        <div class="article-card__thumb">
          <div class="article-card__thumb-top">
            <span class="chip"><?= wa_e($article['thumb_label']) ?></span>
            <?php if ($article['images'] > 0): ?>
              <span class="chip chip--teal"><span class="dot dot--teal"></span><?= $article['images'] ?> ảnh cục bộ</span>
            <?php else: ?>
              <span class="chip chip--plain"><span class="ms ms-14">storage</span><?= wa_e($article['size']) ?></span>
            <?php endif; ?>
          </div>
          <div class="article-card__thumb-code"><?= wa_e($article['thumb_code']) ?></div>
        </div>

        <div class="article-card__body">
          <div class="article-card__meta">
            <span class="article-card__domain">
              <span class="ms ms-14">public</span><?= wa_e($article['domain']) ?>
            </span>
            <span>Lưu ngày: <?= wa_e($article['saved_at']) ?></span>
          </div>

          <h3 class="t-clamp-2"><?= wa_e($article['title']) ?></h3>
          <p class="article-card__excerpt t-clamp-2"><?= wa_e($article['excerpt']) ?></p>

          <div class="article-card__tags">
            <?php foreach ($article['tags'] as $tag): ?>
              <span class="tag"><?= wa_e($tag) ?></span>
            <?php endforeach; ?>
          </div>

          <span class="article-card__status">
            <span class="ms ms-16">offline_pin</span>
            Bản đọc ngoại tuyến sẵn sàng
          </span>
        </div>
      </div>

      <div class="article-card__foot">
        <a class="btn btn--dark" href="<?= wa_e(wa_url('reader.php?id=' . urlencode($article['id']))) ?>">
          <span class="ms ms-18">chrome_reader_mode</span>
          Đọc bản đã lưu
        </a>
        <button class="btn btn--outline" type="button" title="Chi tiết bản lưu" aria-label="Chi tiết bản lưu">
          <span class="ms ms-18">info</span>
        </button>
        <button class="btn btn--outline" type="button" title="Thao tác khác" aria-label="Thao tác khác">
          <span class="ms ms-18">more_vert</span>
        </button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ===================== PHÂN TRANG ===================== -->
<div class="pagination">
  <button type="button" disabled aria-label="Trang trước"><span class="ms ms-16">chevron_left</span></button>
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <button type="button" class="<?= $i === $page ? 'is-active' : '' ?>"
            onclick="window.location.href='<?= wa_e(wa_url('library.php?slug=' . urlencode($slug) . '&page=' . $i)) ?>'"><?= $i ?></button>
  <?php endfor; ?>
  <button type="button" <?= $page >= $totalPages ? 'disabled' : '' ?> aria-label="Trang sau"
          onclick="window.location.href='<?= wa_e(wa_url('library.php?slug=' . urlencode($slug) . '&page=' . ($page + 1))) ?>'">
    <span class="ms ms-16">chevron_right</span>
  </button>
  <span class="pagination__info">
    Trang <?= $page ?>/<?= $totalPages ?> • Hiển thị <?= count($pagedArticles) ?> trong tổng <?= $library['article_count'] ?> bài viết
  </span>
</div>

<script>
  var guidanceToggle = document.getElementById('guidance-toggle');
  var guidance = document.getElementById('guidance-details');
  if (guidanceToggle && guidance) {
    guidanceToggle.addEventListener('click', function () {
      var hidden = guidance.hasAttribute('hidden');
      if (hidden) { guidance.removeAttribute('hidden'); } else { guidance.setAttribute('hidden', ''); }
      guidanceToggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
    });
  }
</script>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
