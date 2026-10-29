<?php
/**
 * admin/users.php — Bảng người dùng (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$users = wa_users();

$filter = (string) ($_GET['filter'] ?? 'all');

if (!in_array($filter, ['all', 'admin', 'user', 'locked'], true)) {
    $filter = 'all';
}

$visible = array_values(array_filter($users, static function (array $u) use ($filter): bool {
    return match ($filter) {
        'admin'  => $u['role'] === 'admin',
        'user'   => $u['role'] !== 'admin',
        'locked' => $u['status'] === 'locked',
        default  => true,
    };
}));

$adminCount  = count(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin'));
$plainCount  = count(array_filter($users, static fn (array $u): bool => $u['role'] !== 'admin'));
$lockedCount = count(array_filter($users, static fn (array $u): bool => $u['status'] === 'locked'));

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Quản lý người dùng';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Người dùng</span>
      </div>
      <h1 class="ribbon__title">Quản lý người dùng</h1>
      <p class="ribbon__sub">Danh sách tài khoản, vai trò và trạng thái hoạt động trên hệ thống.</p>
    </div>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="form-alert <?= ($flash['type'] ?? '') === 'success' ? 'form-alert--ok' : '' ?>" style="margin-bottom:16px" role="status">
    <span class="ms ms-18"><?= ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?></span>
    <span><?= wa_e((string) ($flash['text'] ?? '')) ?></span>
  </div>
<?php endif; ?>

<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Lọc người dùng">
    <button type="button" data-filter="all" class="<?= $filter === 'all' ? 'is-active' : '' ?>">Tất cả (<?= count($users) ?>)</button>
    <button type="button" data-filter="admin" class="<?= $filter === 'admin' ? 'is-active' : '' ?>">Quản trị viên (<?= $adminCount ?>)</button>
    <button type="button" data-filter="user" class="<?= $filter === 'user' ? 'is-active' : '' ?>">Người dùng (<?= $plainCount ?>)</button>
    <button type="button" data-filter="locked" class="<?= $filter === 'locked' ? 'is-active' : '' ?>">Bị khóa (<?= $lockedCount ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" id="user-search" type="search" placeholder="Tìm theo tên hoặc email..." aria-label="Tìm người dùng">
    </div>
  </div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Họ và tên</th>
        <th>Email</th>
        <th>Vai trò</th>
        <th>Trạng thái</th>
        <th>Số thư viện</th>
        <th>Ngày tham gia</th>
        <th>Thao tác</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($visible as $user): ?>
        <tr data-search="<?= wa_e($user['name'] . ' ' . $user['email']) ?>">
          <td>
            <div class="row" style="gap:8px">
              <span class="avatar avatar--<?= $user['role'] === 'admin' ? 'alt' : 'light' ?>" style="width:28px;height:28px;flex:0 0 28px;font-size:11px">
                <?= wa_e(mb_strtoupper(mb_substr($user['name'], 0, 2))) ?>
              </span>
              <span class="cell-main"><?= wa_e($user['name']) ?></span>
            </div>
          </td>
          <td class="t-muted"><?= wa_e($user['email']) ?></td>
          <td>
            <span class="chip <?= $user['role'] === 'admin' ? 'chip--navy' : 'chip--plain' ?>">
              <?= wa_e($user['role_label']) ?>
            </span>
          </td>
          <td>
            <span class="chip <?= $user['status'] === 'active' ? 'chip--teal' : 'chip--error' ?>">
              <span class="dot" style="background:<?= $user['status'] === 'active' ? 'var(--wa-success)' : 'var(--wa-error)' ?>"></span>
              <?= wa_e($user['status_label']) ?>
            </span>
          </td>
          <td><?= $user['libraries'] ?></td>
          <td class="t-muted"><?= wa_e($user['joined']) ?></td>
          <td>
            <div class="table__actions">
              <button class="btn btn--outline btn--sm" type="button" title="Sửa tài khoản" aria-label="Sửa tài khoản"
                      data-modal-open="modal-edit-user"
                      data-user-id="<?= (int) $user['id'] ?>"
                      data-user-name="<?= wa_e($user['name']) ?>"
                      data-user-role="<?= wa_e($user['role']) ?>">
                <span class="ms ms-16">edit</span>
              </button>
              <form method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>" style="display:contents"
                    onsubmit="return confirm('<?= $user['status'] === 'active' ? 'Khoá tài khoản này? Tài khoản sẽ bị đăng xuất ngay.' : 'Mở khoá tài khoản này?' ?>')">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="user_lock">
                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                <?php if ($user['status'] === 'active'): ?>
                  <button class="btn btn--outline btn--sm" type="submit" title="Khoá tài khoản" aria-label="Khoá tài khoản">
                    <span class="ms ms-16">lock</span>
                  </button>
                <?php else: ?>
                  <button class="btn btn--outline btn--sm" type="submit" title="Mở khoá tài khoản" aria-label="Mở khoá tài khoản"
                          style="color:var(--wa-error);border-color:var(--wa-error)">
                    <span class="ms ms-16">lock_open</span>
                  </button>
                <?php endif; ?>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ===================== HỘP THOẠI SỬA TÀI KHOẢN ===================== -->
<div class="modal-backdrop" id="modal-edit-user" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">edit</span>
        <h2 class="t-headline-sm" style="font-weight:700">Sửa tài khoản</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="user_update">
      <input type="hidden" name="user_id" id="edit-user-id" value="">
      <div class="field">
        <label for="edit-user-name">Họ và tên <span class="t-error">*</span></label>
        <input class="input" id="edit-user-name" name="name" type="text" maxlength="150" required>
      </div>
      <div class="field">
        <label for="edit-user-role">Vai trò</label>
        <select class="select" id="edit-user-role" name="role">
          <option value="user">Người dùng</option>
          <option value="admin">Quản trị viên</option>
        </select>
      </div>
      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">Lưu thay đổi</button>
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
      if (btn.hasAttribute('data-user-id')) {
        document.getElementById('edit-user-id').value = btn.getAttribute('data-user-id');
        document.getElementById('edit-user-name').value = btn.getAttribute('data-user-name');
        document.getElementById('edit-user-role').value = btn.getAttribute('data-user-role');
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
      window.location.href = '<?= wa_e(wa_url('admin/users.php')) ?>?filter=' + btn.getAttribute('data-filter');
    });
  });

  var search = document.getElementById('user-search');
  search.addEventListener('input', function () {
    var q = waFold(search.value.trim());
    document.querySelectorAll('tbody tr[data-search]').forEach(function (row) {
      row.hidden = q !== '' && waFold(row.getAttribute('data-search')).indexOf(q) === -1;
    });
  });
</script>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
