<?php
/**
 * header_app.php
 * -----------------------------------------------------------------------------
 * LAYOUT CHUNG CHO ỨNG DỤNG (đã đăng nhập) — thanh trên cùng + sidebar.
 *
 * Khác hoàn toàn với header_guest.php: đây là header đã đăng nhập (user chip,
 * nút Đăng xuất, menu workspace) và KHÔNG được dùng cho trang chủ khách.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = $pageTitle ?? 'WebArchive';
$user = current_user();
$active = current_page();

$navMain = [
    ['file' => 'libraries.php', 'label' => 'Thư viện của tôi', 'icon' => 'folder'],
    ['file' => 'library.php', 'label' => 'CNTT & Lập Trình', 'icon' => 'menu_book'],
    ['file' => 'search.php', 'label' => 'Tìm kiếm nội dung', 'icon' => 'search'],
    ['file' => 'save.php', 'label' => 'Lưu trang Web', 'icon' => 'add_link'],
];

$navAdmin = [
    ['file' => 'admin/index.php', 'label' => 'Tổng quan hệ thống', 'icon' => 'dashboard'],
    ['file' => 'admin/users.php', 'label' => 'Người dùng', 'icon' => 'group'],
    ['file' => 'admin/libraries.php', 'label' => 'Tất cả thư viện', 'icon' => 'folder_special'],
    ['file' => 'admin/allowlist.php', 'label' => 'Tên miền được duyệt', 'icon' => 'verified_user'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= wa_e($pageTitle) ?> — WebArchive</title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
</head>
<body>

<header class="topbar">
  <div class="topbar__accent"></div>
  <div class="topbar__inner">
    <div class="row" style="gap:24px">
      <button class="sidebar__toggle" type="button" id="sidebar-toggle" aria-label="Mở menu">
        <span class="ms ms-18">menu</span>
      </button>

      <a class="brand" href="<?= wa_e(wa_url('libraries.php')) ?>">
        <img class="brand__mark" src="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>" alt="Biểu trưng WebArchive" width="32" height="32">
        <span class="brand__text">
          <span class="brand__name">WebArchive</span>
          <span class="brand__tag">Thư viện Web cá nhân</span>
        </span>
      </a>

      <nav class="topnav" aria-label="Điều hướng chính">
        <a href="<?= wa_e(wa_url('libraries.php')) ?>" class="<?= $active === 'libraries.php' ? 'is-active' : '' ?>">Thư viện của tôi</a>
        <a href="<?= wa_e(wa_url('library.php?slug=cntt-lap-trinh')) ?>" class="<?= $active === 'library.php' ? 'is-active' : '' ?>">Thư viện đang mở</a>
        <a href="<?= wa_e(wa_url('search.php')) ?>" class="<?= $active === 'search.php' ? 'is-active' : '' ?>">Tìm kiếm</a>
        <a href="<?= wa_e(wa_url('save.php')) ?>" class="<?= $active === 'save.php' ? 'is-active' : '' ?>">Lưu trang Web</a>
      </nav>
    </div>

    <div class="topbar__right">
      <div class="offline-pill">
        <span class="dot dot--live"></span>
        <span>Chế độ offline sẵn sàng</span>
      </div>

      <div class="user-chip">
        <span class="avatar" aria-hidden="true"><?= wa_e($user['initials'] ?? 'KH') ?></span>
        <span class="user-chip__meta">
          <span class="user-chip__name"><?= wa_e($user['name'] ?? 'Khách') ?></span>
          <span class="user-chip__role"><?= wa_e($user['role_label'] ?? 'Khách') ?></span>
        </span>
      </div>

      <a class="btn btn--danger-ghost" href="<?= wa_e(wa_url('logout.php')) ?>">Đăng xuất</a>
    </div>
  </div>
</header>

<aside class="sidebar" id="sidebar">
  <div class="sidebar__group">
    <div class="sidebar__label">Bộ sưu tập lưu trữ</div>
    <nav aria-label="Bộ sưu tập lưu trữ">
      <?php foreach ($navMain as $item): ?>
        <a href="<?= wa_e(wa_url($item['file'])) ?>" class="<?= $active === $item['file'] ? 'is-active' : '' ?>">
          <span class="ms ms-18"><?= wa_e($item['icon']) ?></span>
          <span><?= wa_e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <div class="sidebar__label">Quản trị hệ thống</div>
      <nav aria-label="Quản trị hệ thống">
        <?php foreach ($navAdmin as $item): ?>
          <a href="<?= wa_e(wa_url($item['file'])) ?>" class="<?= $active === $item['file'] ? 'is-active' : '' ?>">
            <span class="ms ms-18"><?= wa_e($item['icon']) ?></span>
            <span><?= wa_e($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <div class="sidebar__label">Công cụ bảo tồn</div>
    <div class="stack--sm">
      <a class="btn btn--outline btn--block" href="<?= wa_e(wa_url('save.php')) ?>">
        <span class="ms ms-18">add_link</span>
        <span>Lưu snapshot URL</span>
      </a>
      <div class="sidebar__card">
        <span class="ms ms-18" style="color:var(--wa-action)">hard_drive</span>
        <div>
          <strong>Kho lưu trữ 2.4 GB</strong>
          <span>Sẵn sàng đồng bộ</span>
        </div>
      </div>
    </div>
  </div>

  <div class="sidebar__foot">
    <div class="sidebar__card">
      <span class="ms ms-18" style="color:var(--wa-action)">verified</span>
      <div>
        <strong>Bản quyền cá nhân</strong>
        <span>Sẵn sàng đồng bộ</span>
      </div>
    </div>
  </div>
</aside>

<div class="app-shell">
<main class="page">
