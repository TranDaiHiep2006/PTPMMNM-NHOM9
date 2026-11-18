<?php
/**
 * notification_action.php — HÀNH ĐỘNG trên thông báo (đánh dấu đã đọc).
 *
 * Trang này KHÔNG vẽ giao diện: chỉ nhận POST, kiểm tra bảo mật rồi chuyển
 * hướng lại trang thông báo kèm thông báo flash.
 *
 *   1. Bắt buộc đăng nhập.
 *   2. CSRF: mọi POST phải kèm token hợp lệ của phiên.
 *   3. Phân quyền: chỉ đánh dấu được thông báo CỦA CHÍNH MÌNH — điều kiện
 *      `user_id = ?` nằm TRONG câu UPDATE, không kiểm ở tầng giao diện.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . wa_url('notifications.php'));
    exit;
}

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

if (!wa_csrf_check()) {
    $_SESSION['wa_flash'] = [
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ];
    header('Location: ' . wa_url('notifications.php'));
    exit;
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'read_all') {
    $count = wa_notify_mark_all_read($userId);

    $_SESSION['wa_flash'] = [
        'type' => 'success',
        'text' => $count > 0
            ? 'Đã đánh dấu ' . $count . ' thông báo là đã đọc.'
            : 'Không có thông báo chưa đọc nào.',
    ];

    header('Location: ' . wa_url('notifications.php'));
    exit;
}

if ($action === 'read') {
    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId > 0) {
        wa_notify_mark_read($notificationId, $userId);
    }

    header('Location: ' . wa_url('notifications.php'));
    exit;
}

header('Location: ' . wa_url('notifications.php'));
exit;
