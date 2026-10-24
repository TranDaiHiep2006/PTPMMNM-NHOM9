<?php
/**
 * admin/libraries.php — Toàn bộ thư viện trên hệ thống (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
// P-FIX-UI (6): số liệu thật từ CSDL thay cho các con số bịa đặt trong mock.
require_once __DIR__ . '/../../templates/wa_metrics.php';

require_admin();

// Toàn bộ thư viện trên hệ thống; tên chủ sở hữu đã được JOIN sẵn trong truy vấn.
$libraries = wa_all_libraries();

$allMetrics = wa_metrics_for_libraries(array_column($libraries, 'id'));

$personalCount = count(array_filter($libraries, static fn (array $l): bool => $l['visibility_code'] === 'private'));
$sharedCount   = count($libraries) - $personalCount;

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
    <button type="button" class="is-active">Tất cả (<?= count($libraries) ?>)</button>
    <button type="button">Cá nhân (<?= $personalCount ?>)</button>
    <button type="button">Được chia sẻ (<?= $sharedCount ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" placeholder="Tìm thư viện..." aria-label="Tìm thư viện">
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
      <?php foreach ($libraries as $lib): ?>
        <tr>
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
              <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('library.php?slug=' . urlencode($lib['slug']))) ?>"
                 title="Mở thư viện" aria-label="Mở thư viện">
                <span class="ms ms-16">open_in_new</span>
              </a>
              <button class="btn btn--outline btn--sm" type="button" title="Sửa" aria-label="Sửa thư viện">
                <span class="ms ms-16">edit</span>
              </button>
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

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
