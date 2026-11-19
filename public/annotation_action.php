<?php
/**
 * annotation_action.php — BÔI ĐEN / TÔ MÀU / GHI CHÚ TRỰC TIẾP (V3 / FEATURE 4).
 *
 *   POST annotation_action.php (CSRF)
 *     action = add | update | delete
 *
 * - add    : tạo đánh dấu từ anchor do JavaScript gửi lên.
 * - update : đổi màu / sửa ghi chú — CHỈ tác giả của đánh dấu đó.
 * - delete : xoá đánh dấu — CHỈ tác giả của đánh dấu đó.
 *
 * KHÔNG BAO GIỜ ghi vào `content_html` / `content_text` gốc của snapshot.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/** @param array<string, mixed> $data */
$fail = static function (array $data, int $status): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false], $data), JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail(['error' => 'method'], 405);
}

if (!wa_csrf_check()) {
    $fail(['error' => 'csrf'], 403);
}

$actor = wa_actor();

if ($actor === null) {
    $fail(['error' => 'login'], 401);
}

$userId     = (int) $actor['id'];
$action     = (string) ($_POST['action'] ?? 'add');
$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);

// Phải đọc được thư viện chứa snapshot thì mới được đánh dấu.
if ($snapshotId > 0) {
    $context = wa_reader_context($snapshotId);

    if ($context['status'] !== 'ok') {
        $fail(['error' => 'denied'], 403);
    }
}

/* ---------------------------------------------------------------------------
 * Thêm đánh dấu
 * ------------------------------------------------------------------------- */
if ($action === 'add') {
    $anchor = [
        'selector'     => (string) ($_POST['selector'] ?? ''),
        'start_offset' => (int) ($_POST['start_offset'] ?? 0),
        'end_offset'   => (int) ($_POST['end_offset'] ?? 0),
        'quote'        => (string) ($_POST['quote'] ?? ''),
        'prefix'       => (string) ($_POST['prefix'] ?? ''),
        'suffix'       => (string) ($_POST['suffix'] ?? ''),
    ];

    $color = (string) ($_POST['color'] ?? 'yellow');
    $note  = (string) ($_POST['note'] ?? '');

    $result = wa_annotation_add($snapshotId, $userId, $anchor, $color, $note !== '' ? $note : null);

    echo json_encode([
        'ok'      => $result['ok'],
        'id'      => $result['id'],
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * Sửa đánh dấu (tác giả)
 * ------------------------------------------------------------------------- */
if ($action === 'update') {
    $annotationId = (int) ($_POST['annotation_id'] ?? 0);
    $color = isset($_POST['color']) ? (string) $_POST['color'] : null;
    $note  = isset($_POST['note']) ? (string) $_POST['note'] : null;

    $result = wa_annotation_update($annotationId, $userId, $color, $note);

    echo json_encode([
        'ok'      => $result['ok'],
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * Xoá đánh dấu (tác giả)
 * ------------------------------------------------------------------------- */
if ($action === 'delete') {
    $annotationId = (int) ($_POST['annotation_id'] ?? 0);

    $result = wa_annotation_delete($annotationId, $userId);

    echo json_encode([
        'ok'      => $result['ok'],
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fail(['error' => 'action'], 400);
