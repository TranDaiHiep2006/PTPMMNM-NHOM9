<?php
/**
 * relation_action.php — Điểm nhận DUY NHẤT cho mọi hành động liên kết bài viết (V3 / F11).
 *
 * Trang này KHÔNG vẽ giao diện: nhận POST, kiểm tra bảo mật rồi trả JSON.
 * Trình đọc gọi bằng fetch() nên trả JSON cho tiện.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$json = static function (array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $json(['ok' => false, 'message' => 'Phải dùng POST.'], 405);
}

if (!wa_csrf_check()) {
    $json(['ok' => false, 'message' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'], 403);
}

/**
 * Biểu mẫu POST thường (không phải fetch) -> quay lại trang trình đọc kèm
 * thông báo flash. Các hành động trả JSON vẫn trả JSON cho fetch().
 */
$isForm = (string) ($_POST['redirect'] ?? '') === 'reader';

$backToReader = static function (int $pageId, array $flash) use ($isForm): void {
    if (!$isForm) {
        return;
    }

    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('reader.php?id=' . $pageId));
    exit;
};

$action     = (string) ($_POST['action'] ?? '');
$sourcePage = (int) ($_POST['source_page_id'] ?? 0);
$targetPage = (int) ($_POST['target_page_id'] ?? 0);
$type       = (string) ($_POST['relation_type'] ?? 'related');
$note       = (string) ($_POST['note'] ?? '');

/* ---------------------------------------------------------------------------
 * Tìm bài viết để liên kết (trong các thư viện người dùng có quyền đọc).
 * ------------------------------------------------------------------------- */
if ($action === 'search') {
    $query = (string) ($_POST['q'] ?? '');

    $json([
        'ok'    => true,
        'items' => wa_relation_search_pages($query, null, 20),
    ]);
}

/* ---------------------------------------------------------------------------
 * Tạo quan hệ.
 * ------------------------------------------------------------------------- */
if ($action === 'create') {
    $result = wa_relation_create($sourcePage, $targetPage, $type, $note);

    $backToReader($sourcePage, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);

    $json([
        'ok'      => $result['ok'],
        'message' => $result['message'],
        'id'      => $result['id'],
    ], $result['ok'] === true ? 200 : 400);
}

/* ---------------------------------------------------------------------------
 * Sửa quan hệ (loại + ghi chú).
 * ------------------------------------------------------------------------- */
if ($action === 'update') {
    $relationId = (int) ($_POST['relation_id'] ?? 0);

    $result = wa_relation_update($relationId, $type, $note);

    $backToReader($sourcePage, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);

    $json([
        'ok'      => $result['ok'],
        'message' => $result['message'],
    ], $result['ok'] === true ? 200 : 400);
}

/* ---------------------------------------------------------------------------
 * Xoá quan hệ.
 * ------------------------------------------------------------------------- */
if ($action === 'delete') {
    $relationId = (int) ($_POST['relation_id'] ?? 0);

    $result = wa_relation_delete($relationId);

    $backToReader($sourcePage, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);

    $json([
        'ok'      => $result['ok'],
        'message' => $result['message'],
    ], $result['ok'] === true ? 200 : 400);
}

/* ---------------------------------------------------------------------------
 * Danh sách quan hệ của một bài viết.
 * ------------------------------------------------------------------------- */
if ($action === 'list') {
    $json([
        'ok'        => true,
        'relations' => wa_relation_list_for_page($sourcePage),
        'types'     => wa_relation_type_meta(),
    ]);
}

/* ---------------------------------------------------------------------------
 * Dữ liệu sơ đồ quan hệ (nút + cạnh cho SVG tự vẽ).
 * ------------------------------------------------------------------------- */
if ($action === 'graph') {
    $json([
        'ok'   => true,
        'graph' => wa_relation_graph_data($sourcePage),
    ]);
}

$json(['ok' => false, 'message' => 'Hành động không hợp lệ.'], 400);
