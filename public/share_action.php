<?php
/**
 * share_action.php — Điểm nhận DUY NHẤT cho mọi hành động chia sẻ (V3 / F8).
 *
 * Trang này KHÔNG vẽ giao diện: chỉ nhận POST, kiểm tra bảo mật rồi chuyển
 * hướng lại trang trình đọc kèm thông báo flash.
 *
 * THỨ TỰ KIỂM TRA (giống mọi POST khác của dự án):
 *   1. require_login()  — khách -> chuyển về login.php.
 *   2. wa_csrf_check()   — token sai -> từ chối, KHÔNG ghi gì.
 *   3. Phân quyền trong chính hàm gọi (wa_share_can_manage).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$action    = (string) ($_POST['action'] ?? '');
$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . wa_url('libraries.php'));
    exit;
}

/** Quay lại trình đọc kèm thông báo flash. */
$backToReader = static function (int $id, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('reader.php?id=' . $id));
    exit;
};

if (!wa_csrf_check()) {
    $backToReader($snapshotId, ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng thử lại.']);
}

/* ---------------------------------------------------------------------------
 * Tạo link chia sẻ mới.
 * ------------------------------------------------------------------------- */
if ($action === 'create') {
    $preset     = (string) ($_POST['preset'] ?? '1d');
    $customHour = (int) ($_POST['custom_hours'] ?? 24);
    $maxViewsRaw = trim((string) ($_POST['max_views'] ?? ''));
    $label      = trim((string) ($_POST['label'] ?? ''));

    $maxViews = $maxViewsRaw === '' ? null : max(1, (int) $maxViewsRaw);

    $result = wa_share_create($snapshotId, $preset, $customHour, $maxViews, $label);

    if ($result['ok'] !== true) {
        $backToReader($snapshotId, ['type' => 'error', 'text' => (string) $result['message']]);
    }

    // Link thô chỉ hiện MỘT LẦN — lưu vào session để trình đọc hiển thị.
    $_SESSION['wa_share_new_link'] = [
        'id'         => (int) $result['id'],
        'url'        => (string) $result['url'],
        'expires_at' => (string) $result['expires_at'],
        'max_views'  => $maxViews,
    ];

    $backToReader($snapshotId, [
        'type' => 'success',
        'text' => 'Đã tạo link chia sẻ. Sao chép ngay bên dưới — link chỉ hiện một lần.',
    ]);
}

/* ---------------------------------------------------------------------------
 * Thu hồi link chia sẻ.
 * ------------------------------------------------------------------------- */
if ($action === 'revoke') {
    $linkId = (int) ($_POST['link_id'] ?? 0);

    $ok = wa_share_revoke($linkId);

    $backToReader($snapshotId, [
        'type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Đã thu hồi link chia sẻ.' : 'Không thu hồi được link này.',
    ]);
}

$backToReader($snapshotId, ['type' => 'error', 'text' => 'Hành động không hợp lệ.']);
