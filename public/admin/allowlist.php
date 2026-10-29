<?php
/**
 * admin/allowlist.php — Danh sách tên miền được duyệt (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$allowlist = wa_allowlist();
$requests  = wa_domain_requests_pending();

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wa_csrf_check()) {
        $notice = ['ok' => false, 'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'];
    } else {
        // Ghi thật vào bảng allowlist_domains (tài khoản đang đăng nhập là quản trị viên).
        $user   = current_user();
        $result = wa_allowlist_add(
            (string) ($_POST['domain'] ?? ''),
            (string) ($_POST['note'] ?? ''),
            (int) ($user['id'] ?? 0)
        );

        $notice = ['ok' => $result['ok'], 'text' => $result['message']];

        // Đọc lại danh sách để phản ánh ngay thay đổi vừa ghi.
        $allowlist = wa_allowlist();
    }
}

$filter = (string) ($_GET['filter'] ?? 'all');

if (!in_array($filter, ['all', 'active', 'pending'], true)) {
    $filter = 'all';
}

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Tên miền được duyệt';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Allowlist</span>
      </div>
      <h1 class="ribbon__title">Tên miền được duyệt</h1>
      <p class="ribbon__sub">
        Danh sách kiểm soát truy cập cho tính năng lưu trang. Chỉ những URL thuộc các tên miền này mới được tải về.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <span class="chip chip--teal">
        <span class="ms ms-14">verified_user</span>
        <?= count($allowlist) ?> tên miền trong danh sách
      </span>
    </div>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="form-alert <?= ($flash['type'] ?? '') === 'success' ? 'form-alert--ok' : '' ?>" style="margin-bottom:16px" role="status">
    <span class="ms ms-18"><?= ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?></span>
    <span><?= wa_e((string) ($flash['text'] ?? '')) ?></span>
  </div>
<?php endif; ?>

<!-- ===================== GHI CHÚ QUY TẮC ===================== -->
<div class="note-box" style="margin-bottom:24px">
  <b>Quy tắc kiểm tra:</b> mọi yêu cầu lưu trang đều được đối chiếu với danh sách này — kể cả các đích
  tải về phát sinh sau khi chuyển hướng (redirect chain). Nếu bất kỳ đích nào không thuộc danh sách,
  toàn bộ quá trình lưu sẽ bị từ chối với thông báo
  <em>“URL không thuộc danh sách được duyệt”</em>. Ngoài ra, một trang trả về 404 hoặc timeout
  sẽ không bao giờ được lưu thành một bài viết hợp lệ.
</div>

<!-- ===================== BẢNG TÊN MIỀN ===================== -->
<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Lọc tên miền">
    <button type="button" data-filter="all" class="<?= $filter === 'all' ? 'is-active' : '' ?>">Tất cả (<?= count($allowlist) ?>)</button>
    <button type="button" data-filter="active" class="<?= $filter === 'active' ? 'is-active' : '' ?>">Đang hoạt động (<?= count($allowlist) ?>)</button>
    <button type="button" data-filter="pending" class="<?= $filter === 'pending' ? 'is-active' : '' ?>">Đang chờ duyệt (<?= count($requests) ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" id="domain-search" type="search" placeholder="Tìm tên miền..." aria-label="Tìm tên miền">
    </div>
  </div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Tên miền</th>
        <th><?= $filter === 'pending' ? 'Người yêu cầu' : 'Người thêm' ?></th>
        <th><?= $filter === 'pending' ? 'Ngày yêu cầu' : 'Ngày thêm' ?></th>
        <th>Trạng thái</th>
        <th>Thao tác</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($filter === 'pending'): ?>
        <?php foreach ($requests as $req): ?>
          <tr data-search="<?= wa_e($req['domain']) ?>">
            <td>
              <span class="row" style="gap:6px">
                <span class="ms ms-16" style="color:var(--wa-action)">public</span>
                <span class="cell-main"><?= wa_e($req['domain']) ?></span>
              </span>
            </td>
            <td class="t-muted"><?= wa_e($req['requester']) ?></td>
            <td class="t-muted"><?= wa_e($req['date']) ?></td>
            <td>
              <span class="chip chip--warn">
                <span class="dot" style="background:var(--wa-warning)"></span>
                Chờ duyệt
              </span>
            </td>
            <td>
              <div class="table__actions">
                <form method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>" style="display:contents">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="request_approve">
                  <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit" title="Duyệt tên miền" aria-label="Duyệt tên miền">
                    <span class="ms ms-16">check</span>
                  </button>
                </form>
                <form method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>" style="display:contents"
                      onsubmit="return confirm('Từ chối yêu cầu duyệt tên miền này?')">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="request_reject">
                  <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit" title="Từ chối yêu cầu" aria-label="Từ chối yêu cầu">
                    <span class="ms ms-16">delete</span>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($requests === []): ?>
          <tr><td colspan="5" class="t-muted">Không có yêu cầu nào đang chờ duyệt.</td></tr>
        <?php endif; ?>
      <?php else: ?>
        <?php foreach ($allowlist as $entry): ?>
          <tr data-search="<?= wa_e($entry['domain']) ?>">
            <td>
              <span class="row" style="gap:6px">
                <span class="ms ms-16" style="color:var(--wa-action)">public</span>
                <span class="cell-main"><?= wa_e($entry['domain']) ?></span>
              </span>
            </td>
            <td class="t-muted"><?= wa_e($entry['added_by']) ?></td>
            <td class="t-muted"><?= wa_e($entry['date']) ?></td>
            <td>
              <span class="chip chip--teal">
                <span class="dot" style="background:var(--wa-success)"></span>
                Đang hoạt động
              </span>
            </td>
            <td>
              <div class="table__actions">
                <button class="btn btn--outline btn--sm" type="button" title="Sửa tên miền" aria-label="Sửa tên miền"
                        data-modal-open="modal-edit-domain"
                        data-domain-id="<?= (int) $entry['id'] ?>"
                        data-domain-name="<?= wa_e($entry['domain']) ?>"
                        data-domain-note="<?= wa_e((string) ($entry['note'] ?? '')) ?>">
                  <span class="ms ms-16">edit</span>
                </button>
                <form method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>" style="display:contents"
                      onsubmit="return confirm('Xoá tên miền này khỏi danh sách được duyệt? Các trang thuộc tên miền này sẽ không lưu được nữa.')">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="allowlist_delete">
                  <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit" title="Xoá tên miền" aria-label="Xoá tên miền">
                    <span class="ms ms-16">delete</span>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ===================== HỘP THOẠI SỬA TÊN MIỀN ===================== -->
<div class="modal-backdrop" id="modal-edit-domain" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">edit</span>
        <h2 class="t-headline-sm" style="font-weight:700">Sửa tên miền</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="allowlist_update">
      <input type="hidden" name="id" id="edit-domain-id" value="">
      <div class="field">
        <label for="edit-domain-name">Tên miền <span class="t-error">*</span></label>
        <input class="input" id="edit-domain-name" name="domain" type="text" required>
      </div>
      <div class="field">
        <label for="edit-domain-note">Ghi chú</label>
        <input class="input" id="edit-domain-note" name="note" type="text">
      </div>
      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">Lưu thay đổi</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== BIỂU MẪU THÊM TÊN MIỀN ===================== -->
<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">add_link</span>
      Thêm tên miền được duyệt
    </span>
  </div>

  <?php if ($notice !== null): ?>
    <div class="form-alert <?= $notice['ok'] ? 'form-alert--ok' : '' ?>" style="margin-bottom:16px" role="status">
      <span class="ms ms-18"><?= $notice['ok'] ? 'check_circle' : 'error_outline' ?></span>
      <span><?= wa_e($notice['text']) ?></span>
    </div>
  <?php endif; ?>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
    <?= wa_csrf_field() ?>
    <div class="capture__input">
      <span class="ms ms-18">public</span>
      <input class="input input--icon" type="text" name="domain" placeholder="tenmien.vn" required
             aria-label="Tên miền cần thêm">
    </div>
    <div class="capture__input">
      <span class="ms ms-18">notes</span>
      <input class="input input--icon" type="text" name="note" placeholder="Ghi chú (không bắt buộc)"
             aria-label="Ghi chú cho tên miền">
    </div>
    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">add</span>
      <span>Thêm tên miền</span>
    </button>
  </form>

  <p class="t-label-sm t-muted" style="margin-top:8px">
    Tên miền được ghi thật vào bảng <code>allowlist_domains</code> của cơ sở dữ liệu MySQL
    và vẫn còn sau khi tải lại trang này.
  </p>
</div>

<script>
  function waFold(s) {
    return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
  }

  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.getElementById(btn.getAttribute('data-modal-open'));
      if (!modal) { return; }
      if (btn.hasAttribute('data-domain-id')) {
        document.getElementById('edit-domain-id').value = btn.getAttribute('data-domain-id');
        document.getElementById('edit-domain-name').value = btn.getAttribute('data-domain-name');
        document.getElementById('edit-domain-note').value = btn.getAttribute('data-domain-note');
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

  document.querySelectorAll('[data-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.location.href = '<?= wa_e(wa_url('admin/allowlist.php')) ?>?filter=' + btn.getAttribute('data-filter');
    });
  });

  var search = document.getElementById('domain-search');
  search.addEventListener('input', function () {
    var q = waFold(search.value.trim());
    document.querySelectorAll('tbody tr[data-search]').forEach(function (row) {
      row.hidden = q !== '' && waFold(row.getAttribute('data-search')).indexOf(q) === -1;
    });
  });
</script>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
