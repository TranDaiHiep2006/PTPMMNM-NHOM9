<?php
/**
 * admin/users.php — Bảng người dùng (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$users = wa_users();

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

    <div class="row" style="flex-shrink:0">
      <button class="btn btn--dark" type="button" data-modal-open="modal-invite">
        <span class="ms ms-18">person_add</span>
        <span>Mời người dùng</span>
      </button>
    </div>
  </div>
</div>

<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Lọc người dùng">
    <button type="button" class="is-active">Tất cả (<?= count($users) ?>)</button>
    <button type="button">Quản trị viên (1)</button>
    <button type="button">Người dùng (<?= count($users) - 1 ?>)</button>
    <button type="button">Bị khóa (1)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" placeholder="Tìm theo tên hoặc email..." aria-label="Tìm người dùng">
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
      <?php foreach ($users as $user): ?>
        <tr>
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
              <button class="btn btn--outline btn--sm" type="button" title="Sửa tài khoản" aria-label="Sửa tài khoản">
                <span class="ms ms-16">edit</span>
              </button>
              <?php if ($user['status'] === 'active'): ?>
                <button class="btn btn--outline btn--sm" type="button" title="Khoá tài khoản" aria-label="Khoá tài khoản">
                  <span class="ms ms-16">lock</span>
                </button>
              <?php else: ?>
                <button class="btn btn--outline btn--sm" type="button" title="Mở khoá tài khoản" aria-label="Mở khoá tài khoản">
                  <span class="ms ms-16">lock_open</span>
                </button>
              <?php endif; ?>
              <button class="btn btn--outline btn--sm" type="button" title="Thao tác khác" aria-label="Thao tác khác">
                <span class="ms ms-16">more_vert</span>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="t-label-sm t-muted" style="margin-top:16px">
  Giai đoạn giao diện: các thao tác trên bảng chỉ hiển thị, chưa thực hiện thay đổi trên cơ sở dữ liệu.
</p>

<!-- ===================== HỘP THOẠI MỜI NGƯỜI DÙNG (chỉ giao diện) ===================== -->
<div class="modal-backdrop" id="modal-invite" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">person_add</span>
        <h2 class="t-headline-sm" style="font-weight:700">Mời người dùng mới</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack">
      <div class="field">
        <label for="invite-name">Họ và tên <span class="t-error">*</span></label>
        <input class="input" id="invite-name" type="text" placeholder="Nguyễn Văn A" required>
      </div>
      <div class="field">
        <label for="invite-email">Email <span class="t-error">*</span></label>
        <input class="input" id="invite-email" type="email" placeholder="ban@example.com" required>
      </div>
      <div class="field">
        <label for="invite-role">Vai trò</label>
        <select class="select" id="invite-role">
          <option value="user">Người dùng</option>
          <option value="admin">Quản trị viên</option>
        </select>
      </div>
      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">Gửi lời mời</button>
      </div>
    </form>
  </div>
</div>

<script>
  var modal = document.getElementById('modal-invite');
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

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
