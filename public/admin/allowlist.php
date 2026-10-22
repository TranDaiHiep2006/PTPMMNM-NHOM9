<?php
/**
 * admin/allowlist.php — Danh sách tên miền được duyệt (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$allowlist = sample_allowlist();

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
    $valid = $domain !== '' && (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain);

    if ($valid) {
        $notice = ['ok' => true, 'text' => 'Đã thêm tên miền "' . $domain . '" vào danh sách được duyệt (giai đoạn giao diện: chưa lưu vào cơ sở dữ liệu).'];
    } else {
        $notice = ['ok' => false, 'text' => 'Tên miền không hợp lệ. Vui lòng nhập một tên miền dạng "tenmien.vn".'];
    }
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
    <button type="button" class="is-active">Tất cả (<?= count($allowlist) ?>)</button>
    <button type="button">Đang hoạt động (<?= count(array_filter($allowlist, static fn ($e) => $e['status'] === 'active')) ?>)</button>
    <button type="button">Đang chờ duyệt (1)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" placeholder="Tìm tên miền..." aria-label="Tìm tên miền">
    </div>
  </div>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Tên miền</th>
        <th>Người thêm</th>
        <th>Ngày thêm</th>
        <th>Trạng thái</th>
        <th>Thao tác</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($allowlist as $entry): ?>
        <tr>
          <td>
            <span class="row" style="gap:6px">
              <span class="ms ms-16" style="color:var(--wa-action)">public</span>
              <span class="cell-main"><?= wa_e($entry['domain']) ?></span>
            </span>
          </td>
          <td class="t-muted"><?= wa_e($entry['added_by']) ?></td>
          <td class="t-muted"><?= wa_e($entry['date']) ?></td>
          <td>
            <span class="chip <?= $entry['status'] === 'active' ? 'chip--teal' : 'chip--warn' ?>">
              <span class="dot" style="background:<?= $entry['status'] === 'active' ? 'var(--wa-success)' : 'var(--wa-warning)' ?>"></span>
              <?= wa_e($entry['status_label']) ?>
            </span>
          </td>
          <td>
            <div class="table__actions">
              <button class="btn btn--outline btn--sm" type="button" title="Sửa" aria-label="Sửa tên miền">
                <span class="ms ms-16">edit</span>
              </button>
              <button class="btn btn--outline btn--sm" type="button" title="Xoá" aria-label="Xoá tên miền">
                <span class="ms ms-16">delete</span>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
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
    <div class="capture__input">
      <span class="ms ms-18">public</span>
      <input class="input input--icon" type="text" name="domain" placeholder="tenmien.vn" required
             aria-label="Tên miền cần thêm">
    </div>
    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">add</span>
      <span>Thêm tên miền</span>
    </button>
  </form>

  <p class="t-label-sm t-muted" style="margin-top:8px">
    Giai đoạn giao diện: biểu mẫu chỉ kiểm tra định dạng và hiển thị kết quả, chưa ghi vào cơ sở dữ liệu.
  </p>
</div>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
