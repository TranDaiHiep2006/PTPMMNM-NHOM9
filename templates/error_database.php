<?php
/**
 * error_database.php
 * -----------------------------------------------------------------------------
 * Trang thông báo tiếng Việt khi không kết nối được tới cơ sở dữ liệu MySQL.
 *
 * KHÔNG BAO GIỜ hiển thị stack trace, thông báo lỗi PDO hay tên máy chủ CSDL.
 * Người dùng chỉ thấy hướng dẫn bằng tiếng Việt để chạy lại kịch bản cài đặt.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** @var string $detail Lý do đã được làm sạch, có thể rỗng. */
$detail = isset($detail) && is_string($detail) ? $detail : '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chưa kết nối được cơ sở dữ liệu — WebArchive</title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
</head>
<body>
<div class="error-page">
  <div class="error-page__box">
    <span class="ms ms-32" style="font-size:40px;color:var(--wa-error)">database</span>
    <h1>Chưa thiết lập được cơ sở dữ liệu</h1>
    <p>
      Ứng dụng WebArchive chưa kết nối được với cơ sở dữ liệu MySQL, nên chưa thể hiển thị
      thư viện và các bản lưu của bạn.
    </p>

    <p style="text-align:left">
      Vui lòng mở dòng lệnh PowerShell tại thư mục dự án và chạy kịch bản cài đặt:
    </p>

    <pre style="text-align:left;background:#F4F2ED;border:1px solid #D8D4CC;border-radius:8px;padding:12px 14px;overflow-x:auto"><code>$env:WA_ADMIN_USER = 'root'
$env:WA_ADMIN_PASS = '&lt;mật khẩu MySQL của bạn&gt;'
php scripts/setup_db.php</code></pre>

    <p style="text-align:left" class="t-muted">
      Kịch bản sẽ tạo database <code>webarchive</code>, tạo tài khoản riêng cho ứng dụng và
      nạp dữ liệu mẫu. Mật khẩu quản trị không được lưu vào bất kỳ tệp nào của dự án.
    </p>

    <?php if ($detail !== ''): ?>
      <p style="text-align:left" class="t-muted">
        Chi tiết: <code><?= wa_e($detail) ?></code>
      </p>
    <?php endif; ?>

    <div class="row" style="justify-content:center;margin-top:8px">
      <a class="btn btn--primary" href="<?= wa_e(wa_url('login.php')) ?>">
        <span class="ms ms-18">refresh</span>
        <span>Thử lại</span>
      </a>
    </div>
  </div>
</div>
</body>
</html>