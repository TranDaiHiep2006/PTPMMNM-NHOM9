<?php
/**
 * admin/user_detail.php — Chi tiết một tài khoản (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$id     = (int) ($_GET['id'] ?? 0);
$target = $id > 0 ? auth_find_user_by_id($id) : null;

if ($target === null) {
    $_SESSION['wa_flash'] = ['type' => 'error', 'text' => 'Không tìm thấy tài khoản cần xem.'];
    header('Location: ' . wa_url('admin/users.php'));
    exit;
}

$detail        = wa_user_libraries($id);
$ownedArticles = array_sum(array_column($detail['owned'], 'article_count'));

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Chi tiết người dùng';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Người dùng / Chi tiết</span>
      </div>
      <h1 class="ribbon__title"><?= wa_e($target['name']) ?></h1>
      <p class="ribbon__sub"><?= wa_e($target['email']) ?></p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/users.php')) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Quay lại danh sách</span>
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

<div class="panel" style="margin-bottom:24px">
  <div class="row" style="gap:16px;justify-content:space-between">
    <div class="row" style="gap:16px">
      <span class="avatar avatar--<?= $target['role'] === 'admin' ? 'alt' : 'light' ?>" style="width:56px;height:56px;flex:0 0 56px;font-size:18px">
        <?= wa_e($target['initials']) ?>
      </span>
      <div>
        <div class="row" style="gap:8px">
          <span class="chip <?= $target['role'] === 'admin' ? 'chip--navy' : 'chip--plain' ?>">
            <?= wa_e($target['role_label']) ?>
          </span>
          <span class="chip <?= $target['status'] === 'active' ? 'chip--teal' : 'chip--error' ?>">
            <span class="dot" style="background:<?= $target['status'] === 'active' ? 'var(--wa-success)' : 'var(--wa-error)' ?>"></span>
            <?= wa_e($target['status_label']) ?>
          </span>
        </div>
        <p class="t-muted" style="margin-top:8px">Tham gia ngày <?= wa_e(wa_date((string) $target['created_at'])) ?></p>
      </div>
    </div>

    <div class="table__actions">
      <button class="btn btn--outline btn--sm" type="button" title="Sửa tài khoản" aria-label="Sửa tài khoản"
              data-modal-open="modal-edit-user"
              data-user-id="<?= (int) $target['id'] ?>"
              data-user-name="<?= wa_e($target['name']) ?>"
              data-user-role="<?= wa_e($target['role']) ?>">
        <span class="ms ms-16">edit</span>
      </button>
      <form method="post" action="<?= wa_e(wa_url('admin/admin_action.php')) ?>" style="display:contents"
            onsubmit="return confirm('<?= $target['status'] === 'active' ? 'Khoá tài khoản này? Tài khoản sẽ bị đăng xuất ngay.' : 'Mở khoá tài khoản này?' ?>')">
        <?= wa_csrf_field() ?>
        <input type="hidden" name="action" value="user_lock">
        <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
        <input type="hidden" name="return_to" value="detail">
        <?php if ($target['status'] === 'active'): ?>
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
  </div>
</div>

<div class="stats-row" style="margin-top:0">
  <div class="stats-grid">
    <div class="stat">
      <span class="stat__icon"><span class="ms ms-18">folder_special</span></span>
      <span>
        <span class="stat__value"><?= count($detail['owned']) ?> Thư viện sở hữu</span>
        <span class="stat__note">Do tài khoản này tạo</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--blue"><span class="ms ms-18">group</span></span>
      <span>
        <span class="stat__value"><?= count($detail['memberships']) ?> Thư viện tham gia</span>
        <span class="stat__note">Với vai trò thành viên</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--teal"><span class="ms ms-18">article</span></span>
      <span>
        <span class="stat__value"><?= $ownedArticles ?> Bài viết</span>
        <span class="stat__note">Trong các thư viện sở hữu</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--mint"><span class="ms ms-18">notes</span></span>
      <span>
        <span class="stat__value"><?= (int) $detail['note_count'] ?> Ghi chú</span>
        <span class="stat__note">Đã viết trên các phiên bản</span>
      </span>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">folder_special</span>
      Thư viện sở hữu
    </span>
  </div>

  <?php if ($detail['owned'] === []): ?>
    <p class="t-muted">Tài khoản này chưa sở hữu thư viện nào.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Tên thư viện</th>
            <th>Hiển thị</th>
            <th>Bài viết</th>
            <th>Thành viên</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detail['owned'] as $lib): ?>
            <tr>
              <td>
                <span class="cell-main" style="display:block"><?= wa_e($lib['name']) ?></span>
                <span class="cell-sub">Mã #<?= wa_e((string) $lib['id']) ?></span>
              </td>
              <td>
                <span class="chip <?= $lib['visibility'] === 'shared' ? 'chip--blue' : 'chip--plain' ?>">
                  <?= wa_e($lib['visibility_label']) ?>
                </span>
              </td>
              <td><?= $lib['article_count'] ?></td>
              <td><?= $lib['member_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">group</span>
      Thư viện tham gia
    </span>
  </div>

  <?php if ($detail['memberships'] === []): ?>
    <p class="t-muted">Tài khoản này chưa tham gia thư viện nào.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Tên thư viện</th>
            <th>Chủ sở hữu</th>
            <th>Vai trò</th>
            <th>Hiển thị</th>
            <th>Bài viết</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detail['memberships'] as $lib): ?>
            <tr>
              <td>
                <span class="cell-main" style="display:block"><?= wa_e($lib['name']) ?></span>
                <span class="cell-sub">Mã #<?= wa_e((string) $lib['id']) ?></span>
              </td>
              <td class="t-muted"><?= wa_e((string) $lib['owner_name']) ?></td>
              <td>
                <span class="chip chip--plain"><?= wa_e($lib['role_label']) ?></span>
              </td>
              <td>
                <span class="chip <?= $lib['visibility'] === 'shared' ? 'chip--blue' : 'chip--plain' ?>">
                  <?= wa_e($lib['visibility_label']) ?>
                </span>
              </td>
              <td><?= $lib['article_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
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
      <input type="hidden" name="return_to" value="detail">
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
</script>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
