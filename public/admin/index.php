<?php
/**
 * admin/index.php — Tổng quan hệ thống (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$stats = sample_stats();
$users = sample_users();
$activity = sample_activity();
$allowlist = sample_allowlist();

$activeAllowlist = 0;
foreach ($allowlist as $entry) {
    if ($entry['status'] === 'active') {
        $activeAllowlist++;
    }
}

$pageTitle = 'Tổng quan hệ thống';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Tổng quan</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Offline Ready</span>
      </div>
      <h1 class="ribbon__title">Tổng quan hệ thống</h1>
      <p class="ribbon__sub">Theo dõi toàn bộ hoạt động lưu trữ, tài khoản và danh sách tên miền được duyệt.</p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
        <span class="ms ms-18">verified_user</span>
        <span>Tên miền được duyệt</span>
      </a>
      <a class="btn btn--dark" href="<?= wa_e(wa_url('admin/users.php')) ?>">
        <span class="ms ms-18">group</span>
        <span>Quản lý người dùng</span>
      </a>
    </div>
  </div>
</div>

<!-- ===================== SỐ LIỆU ===================== -->
<div class="stats-row" style="margin-top:0">
  <div class="stats-grid">
    <div class="stat">
      <span class="stat__icon"><span class="ms ms-18">group</span></span>
      <span>
        <span class="stat__value"><?= count($users) ?> Người dùng</span>
        <span class="stat__note">Trong đó 1 quản trị viên</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--blue"><span class="ms ms-18">folder_special</span></span>
      <span>
        <span class="stat__value"><?= count(sample_libraries()) ?> Thư viện</span>
        <span class="stat__note">Toàn hệ thống</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--teal"><span class="ms ms-18">perm_media</span></span>
      <span>
        <span class="stat__value"><?= $stats['image_count'] ?> Ảnh cục bộ</span>
        <span class="stat__note">Dung lượng <?= $stats['storage_gb'] ?></span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--mint"><span class="ms ms-18">error_outline</span></span>
      <span>
        <span class="stat__value">3 Lần lưu thất bại</span>
        <span class="stat__note">404 / timeout / ngoài allowlist</span>
      </span>
    </div>
  </div>
</div>

<!-- ===================== SNAPSHOT & ALLOWLIST ===================== -->
<div class="stat-cards" style="margin-top:24px">
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Tổng snapshot</span>
      <span class="ms ms-18" style="color:var(--wa-action)">archive</span>
    </div>
    <div class="stat-card__value"><b><?= $stats['article_count'] ?></b><span>bản ghi</span></div>
    <div class="stat-card__note" style="color:var(--wa-action)"><span class="dot dot--teal"></span> 100% nguyên vẹn</div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Tài nguyên cục bộ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">photo_library</span>
    </div>
    <div class="stat-card__value"><b><?= $stats['image_count'] ?></b><span>tập tin</span></div>
    <div class="stat-card__note">Dung lượng <?= $stats['storage_gb'] ?> tổng cộng</div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Tên miền được duyệt</span>
      <span class="ms ms-18" style="color:var(--wa-teal-deep)">verified_user</span>
    </div>
    <div class="stat-card__value"><b><?= $activeAllowlist ?></b><span>đang hoạt động</span></div>
    <div class="stat-card__note">Tổng <?= count($allowlist) ?> mục trong danh sách</div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Liên kết lỗi</span>
      <span class="ms ms-18" style="color:var(--wa-teal-deep)">verified</span>
    </div>
    <div class="stat-card__value"><b><?= $stats['broken_links'] ?></b><span>ảnh hỏng</span></div>
    <div class="stat-card__note" style="color:var(--wa-teal-deep)">Bảo toàn vĩnh viễn 100%</div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Lưu trang thất bại</span>
      <span class="ms ms-18" style="color:var(--wa-error)">report</span>
    </div>
    <div class="stat-card__value"><b>3</b><span>lần</span></div>
    <div class="stat-card__note">Không chặn bản lưu hợp lệ</div>
  </div>
</div>

<!-- ===================== HOẠT ĐỘNG GẦN ĐÂY ===================== -->
<div class="two-col" style="margin-top:24px">
  <div class="two-col__main panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">history</span>
        Hoạt động gần đây
      </span>
      <span class="t-label-sm t-muted">6 sự kiện mới nhất</span>
    </div>

    <div class="activity">
      <?php foreach ($activity as $item): ?>
        <div class="activity__item">
          <span class="activity__icon"><span class="ms ms-16"><?= wa_e($item['icon']) ?></span></span>
          <div>
            <p class="activity__text"><?= wa_e($item['text']) ?></p>
            <p class="activity__time"><?= wa_e($item['time']) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="two-col__side panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">verified_user</span>
        Tên miền được duyệt gần đây
      </span>
      <a class="t-label-sm t-accent" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>" style="font-weight:500">Xem tất cả</a>
    </div>

    <div class="stack--sm">
      <?php foreach (array_slice($allowlist, 0, 5) as $entry): ?>
        <div class="row row--between" style="gap:8px">
          <span class="row" style="gap:6px">
            <span class="ms ms-14" style="color:var(--wa-action)">public</span>
            <span class="t-body-md" style="font-weight:500"><?= wa_e($entry['domain']) ?></span>
          </span>
          <span class="chip <?= $entry['status'] === 'active' ? 'chip--teal' : 'chip--warn' ?>">
            <?= wa_e($entry['status_label']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="note-box" style="margin-top:16px">
      <b>Lưu ý:</b> mọi yêu cầu lưu trang — kể cả sau khi chuyển hướng — đều được kiểm tra lại với
      danh sách này trước khi tải về.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
