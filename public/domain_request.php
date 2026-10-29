<?php
/**
 * public/domain_request.php — Người dùng gửi yêu cầu duyệt một tên miền.
 *
 * POST-only, không vẽ giao diện: ghi yêu cầu vào hàng chờ rồi quay lại
 * save.php kèm thông báo flash. Quản trị viên duyệt hoặc từ chối yêu cầu
 * ở trang admin/allowlist.php (tab "Đang chờ duyệt").
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . wa_url('save.php'));
    exit;
}

if (!wa_csrf_check()) {
    $_SESSION['wa_flash'] = [
        'type' => 'error',
        'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.',
    ];
    header('Location: ' . wa_url('save.php'));
    exit;
}

$user   = current_user();
$result = wa_domain_request_add((string) ($_POST['domain'] ?? ''), (int) ($user['id'] ?? 0));

$_SESSION['wa_flash'] = [
    'type' => $result['ok'] ? 'success' : 'error',
    'text' => (string) $result['message'],
];

header('Location: ' . wa_url('save.php'));
exit;
