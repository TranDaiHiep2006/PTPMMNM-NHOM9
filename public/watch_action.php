<?php
/**
 * watch_action.php — Điểm nhận DUY NHẤT cho mọi hành động theo dõi đoạn (V3 / F10).
 *
 * Trang này KHÔNG vẽ giao diện: nhận POST, kiểm tra bảo mật rồi trả JSON.
 * Trình đọc gọi bằng fetch() nên trả JSON cho tiện.
 *
 * THỨ TỰ KIỂM TRA (giống mọi POST khác của dự án):
 *   1. require_login()  — khách -> chuyển về login.php.
 *   2. wa_csrf_check()   — token sai -> từ chối, KHÔNG ghi gì.
 *   3. Phân quyền trong chính hàm gọi (wa_find_snapshot / wa_watch_*).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

/** Trả JSON rồi kết thúc. */
$json = static function (array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

/**
 * Quay lại trang trình đọc kèm thông báo flash (cho biểu mẫu POST thường).
 */
$backToReader = static function (int $id, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('reader.php?id=' . $id));
    exit;
};

/** Quay lại trang quản lý mục theo dõi. */
$backToWatches = static function (array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('watches.php'));
    exit;
};

/** Biểu mẫu POST thường (không phải fetch) -> quay lại trang đã chọn. */
$redirectTarget = (string) ($_POST['redirect'] ?? '');
$isForm        = in_array($redirectTarget, ['reader', 'watches'], true);

$user       = current_user();
$userId     = (int) ($user['id'] ?? 0);
$action     = (string) ($_POST['action'] ?? '');
$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $json(['ok' => false, 'message' => 'Phải dùng POST.'], 405);
}

if (!wa_csrf_check()) {
    if ($isForm) {
        if ($redirectTarget === 'watches') {
            $backToWatches(['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng thử lại.']);
        }

        $backToReader($snapshotId, ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng thử lại.']);
    }

    $json(['ok' => false, 'message' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'], 403);
}

/* ---------------------------------------------------------------------------
 * Thêm mục theo dõi mới (người dùng bôi đen đoạn rồi bấm "Theo dõi đoạn này").
 * ------------------------------------------------------------------------- */
if ($action === 'create') {
    $anchor = [
        'selector'     => (string) ($_POST['selector'] ?? ''),
        'start_offset' => (int) ($_POST['start_offset'] ?? 0),
        'end_offset'   => (int) ($_POST['end_offset'] ?? 0),
        'quote'        => (string) ($_POST['quote'] ?? ''),
        'prefix'       => (string) ($_POST['prefix'] ?? ''),
        'suffix'       => (string) ($_POST['suffix'] ?? ''),
    ];

    $result = wa_watch_create($snapshotId, $userId, $anchor);

    if ($isForm) {
        $backToReader($snapshotId, [
            'type' => $result['ok'] ? 'success' : 'error',
            'text' => $result['message'],
        ]);
    }

    $json([
        'ok'      => $result['ok'],
        'message' => $result['message'],
        'id'      => $result['id'],
    ], $result['ok'] === true ? 200 : 400);
}

/* ---------------------------------------------------------------------------
 * Bật / tắt một mục theo dõi.
 * ------------------------------------------------------------------------- */
if ($action === 'toggle') {
    $watchId = (int) ($_POST['watch_id'] ?? 0);
    $active  = ($_POST['is_active'] ?? '') === '1';

    $ok = wa_watch_set_active($watchId, $userId, $active);

    $message = $ok
        ? ($active ? 'Đã bật theo dõi đoạn này.' : 'Đã tắt theo dõi đoạn này.')
        : 'Không đổi được trạng thái mục theo dõi.';

    if ($isForm) {
        if ($redirectTarget === 'watches') {
            $backToWatches(['type' => $ok ? 'success' : 'error', 'text' => $message]);
        }

        $backToReader($snapshotId, [
            'type' => $ok ? 'success' : 'error',
            'text' => $message,
        ]);
    }

    $json([
        'ok'      => $ok,
        'message' => $message,
    ], $ok ? 200 : 400);
}

/* ---------------------------------------------------------------------------
 * Xoá một mục theo dõi.
 * ------------------------------------------------------------------------- */
if ($action === 'delete') {
    $watchId = (int) ($_POST['watch_id'] ?? 0);

    $ok = wa_watch_delete($watchId, $userId);

    $message = $ok ? 'Đã xoá mục theo dõi.' : 'Không xoá được mục theo dõi này.';

    if ($isForm) {
        if ($redirectTarget === 'watches') {
            $backToWatches(['type' => $ok ? 'success' : 'error', 'text' => $message]);
        }

        $backToReader($snapshotId, [
            'type' => $ok ? 'success' : 'error',
            'text' => $message,
        ]);
    }

    $json([
        'ok'      => $ok,
        'message' => $message,
    ], $ok ? 200 : 400);
}

/* ---------------------------------------------------------------------------
 * Danh sách mục theo dõi của một bài viết.
 * ------------------------------------------------------------------------- */
if ($action === 'list') {
    $pageId = (int) ($_POST['page_id'] ?? 0);

    $json([
        'ok'      => true,
        'watches' => wa_watch_list_for_page($pageId, $userId),
    ]);
}

$json(['ok' => false, 'message' => 'Hành động không hợp lệ.'], 400);
