<?php
/**
 * admin/libraries.php — Toàn bộ thư viện trên hệ thống (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../templates/wa_metrics.php';

require_admin();

// Toàn bộ thư viện trên hệ thống; tên chủ sở hữu đã được JOIN sẵn trong truy vấn.
$libraries = wa_all_libraries();

$filter = (string) ($_GET['filter'] ?? 'all');

if (!in_array($filter, ['all', 'private', 'shared'], true)) {
    $filter = 'all';
}

$visible = array_values(array_filter($libraries, static function (array $l) use ($filter): bool {
    return match ($filter) {
        'private' => $l['visibility_code'] === 'private',
        'shared'  => $l['visibility_code'] !== 'private',
        default   => true,
    };
}));

$allMetrics = wa_metrics_for_libraries(array_column($libraries, 'id'));

$personalCount = count(array_filter($libraries, static fn (array $l): bool => $l['visibility_code'] === 'private'));
$sharedCount   = count($libraries) - $personalCount;

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Tất cả thư viện';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Thư viện</span>
      </div>
      <h1 class="ribbon__title">Tất cả thư viện</h1>
      <p class="ribbon__sub">Toàn bộ thư viện của mọi tài khoản, chủ sở hữu và mức độ hiển thị.</p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/users.php')) ?>">
        <span class="ms ms-18">group</span>
        <span>Quản lý người dùng</span>
      </a>
    </div>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="form-alert <?= ($flash['type'] ?? '') === 'success' ? 'form-alert--ok' : '' ?>" style="margin-bottom:16px" role="status">
    <span class="ms ms-18"><?= ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?></span>
    <span><?= wa_e((string) ($flash['text'] ?? '')) ?></span>
  </div>
<?php endif; ?>

<div class="stats-row" style="margin-top:0">
  <div class="stats-grid">
    <div class="stat">
      <span class="stat__icon"><span class="ms ms-18">folder_special</span></span>
      <span>
        <span class="stat__value"><?= count($libraries) ?> Thư viện</span>
        <span class="stat__note">Trên toàn hệ thống</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--blue"><span class="ms ms-18">article</span></span>
      <span>
        <span class="stat__value"><?= $allMetrics['pages'] ?> Bài viết</span>
        <span class="stat__note"><?= $allMetrics['pages_ok'] ?> có bản lưu thành công</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--teal"><span class="ms ms-18">perm_media</span></span>
      <span>
        <span class="stat__value"><?= $allMetrics['image_total'] ?> Ảnh cục bộ</span>
        <span class="stat__note">Dung lượng <?= wa_e(wa_format_mb($allMetrics['bytes_total'])) ?></span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--mint"><span class="ms ms-18">lock_open</span></span>
      <span>
        <span class="stat__value"><?= $sharedCount ?> Được chia sẻ</span>
        <span class="stat__note"><?= $personalCount ?> Chỉ mình tôi</span>
      </span>
    </div>
  </div>
</div>

<div class="toolbar" style="margin-top:24px">
  <div class="tabs" role="tablist" aria-label="Lọc thư viện">
    <button type="button" data-filter="all" class="<?= $filter === 'all' ? 'is-active' : '' ?>">Tất cả (<?= count($libraries) ?>)</button>
    <button type="button" data-filter="private" class="<?= $filter === 'private' ? 'is-active' : '' ?>">Cá nhân (<?= $personalCount ?>)</button>
    <button type="button" data-filter="shared" class="<?= $filter === 'shared' ? 'is-active' : '' ?>">Được chia sẻ (<?= $sharedCount ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" id="lib-search" type="search" placeholder="Tìm thư viện..." aria-label="Tìm thư viện">
    </div>
  </div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Tên thư viện</th>
        <th>Chủ sở hữu</th>
        <th>Hiển thị</th>
        <th>Bài viết</th>
        <th>Ảnh cục bộ</th>
        <th>Dung lượng</th>
        <th>Thành viên</th>
        <th>Thao tác</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($visible as $lib): ?>
        <tr data-search="<?= wa_e($lib['name'] . ' ' . $lib['owner_name']) ?>">
          <td>
            <span class="row" style="gap:8px">
              <span class="lib-card__icon lib-card__icon--<?= wa_e($lib['strip']) ?>" style="width:32px;height:32px;flex:0 0 32px;border-radius:8px">
                <span class="ms ms-18"><?= wa_e($lib['icon']) ?></span>
              </span>
              <span>
                <span class="cell-main" style="display:block"><?= wa_e($lib['name']) ?></span>
                <span class="cell-sub">Mã #<?= wa_e((string) $lib['id']) ?></span>
              </span>
            </span>
          </td>
          <td class="t-muted"><?= wa_e((string) $lib['owner_name']) ?></td>
          <td>
            <span class="chip <?= $lib['visibility_code'] === 'shared' ? 'chip--blue' : 'chip--plain' ?>">
              <?= wa_e($lib['visibility_label']) ?>
            </span>
          </td>
          <td><?= $lib['article_count'] ?></td>
          <td><?= $lib['image_count'] ?></td>
          <td class="t-muted"><?= wa_e($lib['storage_label']) ?></td>
          <td><?= $lib['member_count'] ?></td>
          <td>
            <div class="table__actions">
              <button class="btn btn--outline btn--sm" type="button" title="Sửa thư viện" aria-label="Sửa thư viện"
                      data-modal-open="modal-edit-lib"
                      data-lib-id="<?= (int) $lib['id'] ?>"
                      data-lib-name="<?= wa_e($lib['name']) ?>"
                      data-lib-desc="<?= wa_e((string) ($lib['description'] ?? '')) ?>"
                      data-lib-vis="<?= wa_e($lib['visibility_code']) ?>">
                <span class="ms ms-16">edit</span>
              </button>
              <button class="btn btn--outline btn--sm" type="button" title="Xoá thư viện" aria-label="Xoá thư viện"
                      data-modal-open="modal-delete-lib"
                      data-lib-id="<?= (int) $lib['id'] ?>"
                      data-lib-name="<?= wa_e($lib['name']) ?>">
                <span class="ms ms-16">delete</span>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ===================== HỘP THOẠI SỬA THƯ VIỆN ===================== -->
<div class="modal-backdrop" id="modal-edit-lib" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">edit</span>
        <h2 class="t-headline-sm" style="font-weight:700">Sửa thư viện</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="library_update">
      <input type="hidden" name="library_id" id="edit-lib-id" value="">
      <div class="field">
        <label for="edit-lib-name">Tên thư viện <span class="t-error">*</span></label>
        <input class="input" id="edit-lib-name" name="name" type="text" required>
      </div>
      <div class="field">
        <label for="edit-lib-desc">Mô tả</label>
        <textarea class="input" id="edit-lib-desc" name="description" rows="3"></textarea>
      </div>
      <div class="field">
        <label for="edit-lib-vis">Mức hiển thị</label>
        <select class="select" id="edit-lib-vis" name="visibility">
          <option value="private">Chỉ mình tôi</option>
          <option value="shared">Nội bộ nhóm</option>
          <option value="public">Công khai</option>
        </select>
      </div>
      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">Lưu thay đổi</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== HỘP THOẠI XOÁ THƯ VIỆN ===================== -->
<div class="modal-backdrop" id="modal-delete-lib" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">delete</span>
        <h2 class="t-headline-sm" style="font-weight:700">Xoá thư viện</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>" id="delete-lib-form">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="library_delete">
      <input type="hidden" name="library_id" id="delete-lib-id" value="">
      <p class="t-body-md">Thư viện <strong id="delete-lib-name"></strong> và toàn bộ bài viết, nhãn cùng thư mục ảnh của nó sẽ bị xoá vĩnh viễn.</p>

      <div class="field">
        <label for="delete-lib-confirm">Gõ <b>DELETE</b> để xác nhận</label>
        <input class="input" id="delete-lib-confirm" name="confirm" type="text" placeholder="DELETE" autocomplete="off">
      </div>

      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">
          <span class="ms ms-18">delete</span> Xoá thư viện
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  function waFold(s) {
    return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
  }

  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.getElementById(btn.getAttribute('data-modal-open'));
      if (!modal) { return; }
      if (btn.hasAttribute('data-lib-name') && modal.id === 'modal-edit-lib') {
        document.getElementById('edit-lib-id').value = btn.getAttribute('data-lib-id');
        document.getElementById('edit-lib-name').value = btn.getAttribute('data-lib-name');
        document.getElementById('edit-lib-desc').value = btn.getAttribute('data-lib-desc');
        document.getElementById('edit-lib-vis').value = btn.getAttribute('data-lib-vis');
      }
      if (btn.hasAttribute('data-lib-name') && modal.id === 'modal-delete-lib') {
        document.getElementById('delete-lib-id').value = btn.getAttribute('data-lib-id');
        document.getElementById('delete-lib-name').textContent = btn.getAttribute('data-lib-name');
        document.getElementById('delete-lib-confirm').value = '';
      }
      modal.removeAttribute('hidden');
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal-backdrop').setAttribute('hidden', '');
    });
  });
  document.querySelectorAll('.modal-backdrop').forEach(function (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) { modal.setAttribute('hidden', ''); }
    });
  });

  document.getElementById('delete-lib-form').addEventListener('submit', function (e) {
    if (document.getElementById('delete-lib-confirm').value.trim().toUpperCase() !== 'DELETE') {
      e.preventDefault();
      alert('Chưa xác nhận xoá. Vui lòng gõ đúng DELETE để xác nhận.');
    }
  });

  document.querySelectorAll('[data-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.location.href = '<?= wa_e(wa_url('admin/libraries.php')) ?>?filter=' + btn.getAttribute('data-filter');
    });
  });

  var search = document.getElementById('lib-search');
  search.addEventListener('input', function () {
    var q = waFold(search.value.trim());
    document.querySelectorAll('tbody tr[data-search]').forEach(function (row) {
      row.hidden = q !== '' && waFold(row.getAttribute('data-search')).indexOf(q) === -1;
    });
  });
</script>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
