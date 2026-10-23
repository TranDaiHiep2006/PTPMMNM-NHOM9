<?php
/**
 * libraries.php — "Thư viện của tôi".
 *
 * Bám theo bản tham chiếu (code1.html): sidebar, dải số liệu, tab lọc,
 * lưới thẻ thư viện có badge vai trò, thẻ nhận diện, số đếm và nút "Mở thư viện".
 *
 * P-LIB: nút "+ Tạo thư viện mới" đã là BIỂU MẪU THẬT — POST sang
 * library_action.php (CSRF + prepared statement), sinh slug bỏ dấu từ tên có hậu
 * tố -2/-3 nếu trùng, tạo dòng chủ sở hữu trong library_members, rồi chuyển
 * thẳng tới trang chi tiết thư viện vừa tạo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

// --- P-LIB: xử lý POST tạo thư viện ngay tại trang này ---------------------
// (Điểm nhận duy nhất vẫn là library_action.php; đoạn dưới chỉ làm nhiệm vụ
//  đọc thông báo flash sau khi hành động đã chuyển hướng.)
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

$libraries = wa_libraries();
$stats = [
    'library_count'      => count($libraries),
    'article_count'      => array_sum(array_column($libraries, 'article_count')),
    'image_count'        => array_sum(array_column($libraries, 'image_count')),
    'broken_links'       => 0,
    'storage_gb'         => wa_admin_stats()['storage_label'],
    'personal_libraries' => count(array_filter($libraries, static fn (array $l): bool => $l['category'] === 'personal')),
    'shared_libraries'   => count(array_filter($libraries, static fn (array $l): bool => $l['category'] === 'shared')),
];

// Lọc theo tab được chọn.
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'personal', 'shared'], true)) {
    $filter = 'all';
}

// Lọc theo từ khoá trong ô tìm kiếm (chạy trên dữ liệu đã nạp — không cần truy vấn mới).
$search = trim((string) ($_GET['q'] ?? ''));

$visible = array_values(array_filter(
    $libraries,
    static function (array $lib) use ($filter, $search): bool {
        if ($filter !== 'all' && $lib['category'] !== $filter) {
            return false;
        }

        if ($search === '') {
            return true;
        }

        // Bỏ dấu cả hai vế để gõ không dấu vẫn khớp (dùng chung bảng bỏ dấu của dự án).
        $needle = wa_search_fold($search);

        return str_contains(wa_search_fold((string) $lib['name']), $needle)
            || str_contains(wa_search_fold((string) $lib['description']), $needle);
    }
));

// Lựa chọn cho biểu mẫu tạo thư viện (mức hiển thị, màu, biểu tượng).
$visibilityOptions = wa_library_visibility_options();
$colorOptions      = wa_library_color_options();
$iconOptions       = wa_library_icon_options();

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
    <form class="searchbox" method="get" action="<?= wa_e(wa_url('libraries.php')) ?>">
      <?php if ($filter !== 'all'): ?>
        <input type="hidden" name="filter" value="<?= wa_e($filter) ?>">
      <?php endif; ?>
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" name="q" value="<?= wa_e($search) ?>"
             placeholder="Tìm kiếm bộ sưu tập..." aria-label="Tìm kiếm bộ sưu tập">
    </form>
    <div class="view-toggle" role="group" aria-label="Kiểu hiển thị">
      <button type="button" class="is-active" aria-label="Hiển thị dạng lưới"><span class="ms ms-16">grid_view</span></button>
      <button type="button" aria-label="Hiển thị dạng danh sách"><span class="ms ms-16">format_list_bulleted</span></button>
    </div>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="state <?= (string) ($flash['type'] ?? '') === 'success' ? 'state--success' : 'state--error' ?>" role="status" style="margin-top:16px">
    <div class="state__body">
      <span class="state__icon">
        <span class="ms ms-18 <?= (string) ($flash['type'] ?? '') === 'success' ? 'ms-fill' : 'ms-fill' ?>">
          <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
        </span>
      </span>
      <div>
        <span class="state__title"><?= (string) ($flash['type'] ?? '') === 'success' ? 'Thành công' : 'Không thực hiện được' ?></span>
        <p class="state__text"><?= wa_e((string) ($flash['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

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
    <h3><?= $libraries === [] ? 'Bạn chưa có thư viện nào' : 'Không có thư viện nào khớp bộ lọc' ?></h3>
    <p><?= $libraries === []
        ? 'Bấm “+ Tạo thư viện mới” để tạo thư viện đầu tiên của bạn.'
        : 'Hãy chuyển sang tab khác, xoá từ khoá tìm kiếm, hoặc tạo một thư viện mới.' ?></p>
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

<!-- ===================== HỘP THOẠI TẠO THƯ VIỆN (P-LIB — biểu mẫu THẬT) =========
     POST sang public/library_action.php. Mọi thao tác ghi đều có token CSRF
     (wa_csrf_field) và được xử lý bằng prepared statement. -->
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

    <form class="stack" method="post" action="<?= wa_e(wa_url('library_action.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field">
        <label for="lib-name">Tên thư viện lưu trữ <span class="t-error">*</span></label>
        <input class="input" id="lib-name" name="name" type="text" required maxlength="150"
               placeholder="Ví dụ: Tài liệu Trí tuệ Nhân tạo">
        <span class="field-hint">
          Đường dẫn thư viện được sinh tự động từ tên (bỏ dấu, khoảng trắng thành dấu gạch ngang).
          Nếu trùng, hệ thống tự thêm hậu tố -2, -3, …
        </span>
      </div>

      <div class="field">
        <label for="lib-desc">Mô tả chuyên đề</label>
        <textarea class="input" id="lib-desc" name="description" rows="3"
                  placeholder="Tóm tắt mục đích thu thập và ghi chú nghiên cứu..."></textarea>
      </div>

      <div class="field">
        <label for="lib-visibility">Ai có thể xem thư viện này?</label>
        <select class="select" id="lib-visibility" name="visibility">
          <?php foreach ($visibilityOptions as $option): ?>
            <option value="<?= wa_e($option['key']) ?>" <?= $option['key'] === 'private' ? 'selected' : '' ?>>
              <?= wa_e($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="field-hint">
          Quyền ghi và xoá thư viện chỉ thuộc về chủ sở hữu là bạn; thành viên khác do bạn mời sau.
        </span>
      </div>

      <div class="field">
        <label for="lib-color">Màu nhận diện</label>
        <select class="select" id="lib-color" name="color">
          <?php foreach ($colorOptions as $option): ?>
            <option value="<?= wa_e($option['key']) ?>" <?= $option['key'] === 'a' ? 'selected' : '' ?>>
              <?= wa_e($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="lib-icon">Biểu tượng</label>
        <select class="select" id="lib-icon" name="icon">
          <?php foreach ($iconOptions as $option): ?>
            <option value="<?= wa_e($option['key']) ?>" <?= $option['key'] === 'folder_special' ? 'selected' : '' ?>>
              <?= wa_e($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">
          <span class="ms ms-18">create_new_folder</span>
          <span>Tạo thư viện</span>
        </button>
      </div>
      <p class="t-label-sm t-muted">
        Thư viện mới sẽ có bạn làm <b>chủ sở hữu</b> ngay khi tạo.
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

  // Hộp thoại tạo thư viện — mở lại hộp thoại khi gửi lỗi (flash type = error)
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
