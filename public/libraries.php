<?php
/**
 * libraries.php — "Thư viện của tôi".
 *
 * Bám theo bản tham chiếu (code1.html): sidebar, dải số liệu, tab lọc,
 * lưới thẻ thư viện có badge vai trò, thẻ nhận diện, số đếm và nút "Mở thư viện".
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$libraries = sample_libraries();
$stats = sample_stats();

// Lọc theo tab được chọn.
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'personal', 'shared'], true)) {
    $filter = 'all';
}

$visible = array_values(array_filter(
    $libraries,
    static fn (array $lib): bool => $filter === 'all' || $lib['category'] === $filter
));

$pageTitle = 'Thư viện của tôi';
require __DIR__ . '/../templates/header_app.php';
?>

<!-- ===================== DẢI TIÊU ĐỀ ===================== -->
<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Quản lý tri thức</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Offline Ready</span>
      </div>
      <h1 class="ribbon__title">Thư viện của tôi</h1>
      <p class="ribbon__sub">Quản lý và sắp xếp những nội dung Web đã lưu theo chuyên đề nghiên cứu.</p>
    </div>

    <div class="row" style="flex-shrink:0">
      <button class="btn btn--dark" type="button" data-modal-open="modal-create">
        <span class="ms ms-18">add</span>
        <span>+ Tạo thư viện mới</span>
      </button>
    </div>
  </div>

  <!-- ===================== SỐ LIỆU ===================== -->
  <div class="stats-row">
    <div class="stats-grid">
      <div class="stat">
        <span class="stat__icon"><span class="ms ms-18">folder_special</span></span>
        <span>
          <span class="stat__value"><?= $stats['library_count'] ?> Thư viện hoạt động</span>
          <span class="stat__note">Đồng bộ ngoại tuyến</span>
        </span>
      </div>
      <div class="stat">
        <span class="stat__icon stat__icon--blue"><span class="ms ms-18">auto_stories</span></span>
        <span>
          <span class="stat__value"><?= $stats['article_count'] ?> Bài viết đã lưu trữ</span>
          <span class="stat__note">Snapshot HTML/WARC</span>
        </span>
      </div>
      <div class="stat">
        <span class="stat__icon stat__icon--teal"><span class="ms ms-18">perm_media</span></span>
        <span>
          <span class="stat__value"><?= $stats['image_count'] ?> Ảnh cục bộ</span>
          <span class="stat__note">Nén không mất dữ liệu</span>
        </span>
      </div>
      <div class="stat">
        <span class="stat__icon stat__icon--mint"><span class="ms ms-18">verified</span></span>
        <span>
          <span class="stat__value"><?= $stats['broken_links'] ?> Liên kết lỗi</span>
          <span class="stat__note">Bảo toàn vĩnh viễn 100%</span>
        </span>
      </div>
    </div>
  </div>
</div>

<!-- ===================== TAB LỌC + TÌM KIẾM ===================== -->
<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Lọc thư viện">
    <button type="button" class="<?= $filter === 'all' ? 'is-active' : '' ?>" data-filter-link="all">Tất cả (<?= $stats['library_count'] ?>)</button>
    <button type="button" class="<?= $filter === 'personal' ? 'is-active' : '' ?>" data-filter-link="personal">Thư viện cá nhân (<?= $stats['personal_libraries'] ?>)</button>
    <button type="button" class="<?= $filter === 'shared' ? 'is-active' : '' ?>" data-filter-link="shared">Được chia sẻ (<?= $stats['shared_libraries'] ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" placeholder="Tìm kiếm bộ sưu tập..." aria-label="Tìm kiếm bộ sưu tập">
    </div>
    <div class="view-toggle" role="group" aria-label="Kiểu hiển thị">
      <button type="button" class="is-active" aria-label="Hiển thị dạng lưới"><span class="ms ms-16">grid_view</span></button>
      <button type="button" aria-label="Hiển thị dạng danh sách"><span class="ms ms-16">format_list_bulleted</span></button>
    </div>
  </div>
</div>

<!-- ===================== LƯỚI THẺ THƯ VIỆN ===================== -->
<div class="lib-grid">
  <?php foreach ($visible as $lib): ?>
    <div class="lib-card" data-category="<?= wa_e($lib['category']) ?>">
      <div class="lib-card__strip lib-card__strip--<?= wa_e($lib['strip']) ?>"></div>

      <div class="lib-card__body">
        <div class="lib-card__top">
          <div class="lib-card__id">
            <span class="lib-card__icon lib-card__icon--<?= wa_e($lib['strip']) ?>">
              <span class="ms ms-24"><?= wa_e($lib['icon']) ?></span>
            </span>
            <span class="lib-card__role">
              <?php if ($lib['role'] === 'owner'): ?>
                <span class="chip chip--blue">
                  <span class="dot dot--teal"></span><?= wa_e($lib['role_label']) ?>
                </span>
              <?php else: ?>
                <span class="chip chip--teal">
                  <span class="ms ms-14">group</span><?= wa_e($lib['role_label']) ?>
                </span>
              <?php endif; ?>
              <span class="lib-card__updated"><?= wa_e($lib['updated']) ?></span>
            </span>
          </div>

          <div class="row" style="gap:2px">
            <?php if ($lib['starred']): ?>
              <button class="btn btn--ghost" type="button" title="Bỏ đánh dấu yêu thích" aria-label="Bỏ đánh dấu yêu thích">
                <span class="ms ms-18 ms-fill" style="color:var(--wa-primary)">star</span>
              </button>
            <?php else: ?>
              <button class="btn btn--ghost" type="button" title="Đánh dấu yêu thích" aria-label="Đánh dấu yêu thích">
                <span class="ms ms-18">star</span>
              </button>
            <?php endif; ?>
            <button class="btn btn--ghost" type="button" title="Thao tác khác" aria-label="Thao tác khác">
              <span class="ms ms-18">more_vert</span>
            </button>
          </div>
        </div>

        <h3><?= wa_e($lib['name']) ?></h3>
        <p class="lib-card__desc t-clamp-2"><?= wa_e($lib['description']) ?></p>

        <div class="lib-card__metrics">
          <span class="chip chip--blue">
            <span class="ms ms-14">article</span><?= $lib['article_count'] ?> bài viết
          </span>
          <span class="chip chip--plain">
            <span class="ms ms-14">storage</span><?= wa_e($lib['storage_label']) ?>
          </span>
        </div>

        <div class="lib-card__tags">
          <?php foreach ($lib['tags'] as $tag): ?>
            <span class="tag"><?= wa_e($tag) ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="lib-card__foot">
        <a class="btn <?= $lib['role'] === 'owner' ? 'btn--dark' : 'btn--outline' ?>"
           href="<?= wa_e(wa_url('library.php?slug=' . urlencode($lib['slug']))) ?>">
          <span>Mở thư viện</span>
          <span class="ms ms-16">arrow_forward</span>
        </a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($visible === []): ?>
  <div class="empty">
    <span class="ms ms-32">folder_off</span>
    <h3>Không có thư viện nào trong nhóm này</h3>
    <p>Hãy chuyển sang tab khác hoặc tạo một thư viện mới.</p>
  </div>
<?php endif; ?>

<!-- ===================== MẸO TỔ CHỨC ===================== -->
<div class="tip-strip" style="margin-top:40px">
  <div class="tip-strip__body">
    <span class="tip-strip__icon"><span class="ms ms-22">lightbulb</span></span>
    <div>
      <h3 class="t-headline-sm" style="color:var(--wa-text)">Mẹo tổ chức kho dữ liệu</h3>
      <p class="t-body-md t-soft" style="margin-top:2px">
        Bạn có thể phân quyền thành viên <strong class="t-primary">Chủ sở hữu</strong>,
        <strong class="t-primary">Biên tập viên</strong> hoặc <strong class="t-primary">Người xem</strong>
        cho từng thư viện nghiên cứu để đồng bộ hóa kho tài liệu ngoại tuyến nhóm.
      </p>
    </div>
  </div>
  <div class="tip-strip__actions">
    <button class="btn btn--outline" type="button">Xem tài liệu phân quyền</button>
    <button class="btn btn--ghost" type="button" id="dismiss-tip" title="Đóng mẹo" aria-label="Đóng mẹo">
      <span class="ms ms-18">close</span>
    </button>
  </div>
</div>

<!-- ===================== HỘP THOẠI TẠO THƯ VIỆN (chỉ giao diện) ===================== -->
<div class="modal-backdrop" id="modal-create" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">create_new_folder</span>
        <h2 class="t-headline-sm" style="font-weight:700">Tạo thư viện mới</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" id="create-lib-form">
      <div class="field">
        <label for="lib-name">Tên thư viện lưu trữ <span class="t-error">*</span></label>
        <input class="input" id="lib-name" name="name" type="text" placeholder="Ví dụ: Tài liệu Trí tuệ Nhân tạo" required>
      </div>
      <div class="field">
        <label for="lib-desc">Mô tả chuyên đề</label>
        <textarea class="input" id="lib-desc" name="description" rows="3"
                  placeholder="Tóm tắt mục đích thu thập và ghi chú nghiên cứu..."></textarea>
      </div>
      <div class="field">
        <label for="lib-tags">Thẻ nhận diện (Tags)</label>
        <input class="input" id="lib-tags" name="tags" type="text" placeholder="#AI #MachineLearning #DeepSeek">
      </div>
      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">Tạo thư viện</button>
      </div>
      <p class="t-label-sm t-muted">
        Giai đoạn giao diện: nút này chỉ hiển thị biểu mẫu, chưa lưu vào cơ sở dữ liệu.
      </p>
    </form>
  </div>
</div>

<script>
  // Tab lọc: chuyển trang bằng tham số filter
  document.querySelectorAll('[data-filter-link]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('filter', tab.getAttribute('data-filter-link'));
      window.location.href = url.toString();
    });
  });

  // Đóng mẹo tổ chức
  var dismiss = document.getElementById('dismiss-tip');
  if (dismiss) {
    dismiss.addEventListener('click', function () {
      var strip = dismiss.closest('.tip-strip');
      if (strip) { strip.style.display = 'none'; }
    });
  }

  // Hộp thoại tạo thư viện
  var modal = document.getElementById('modal-create');
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () { modal.removeAttribute('hidden'); });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () { modal.setAttribute('hidden', ''); });
  });
  modal.addEventListener('click', function (e) {
    if (e.target === modal) { modal.setAttribute('hidden', ''); }
  });
</script>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
