<?php
/**
 * reading_action.php — LƯU TRẠNG THÁI ĐỌC & DANH SÁCH ĐỌC SAU (V3 / FEATURE 3).
 *
 *   POST reading_action.php (token CSRF + hành động)
 *     hành động = state | progress | queue
 *
 *   state    — đặt trạng thái đọc (unread | reading | read) của một snapshot.
 *   progress — lưu tiến độ đọc (0–100 %) + vị trí cuộn.
 *   queue    — thêm / gỡ snapshot khỏi "Danh sách đọc sau".
 *
 * Trả về JSON. Mọi thao tác đều kiểm tra quyền đọc snapshot ở tầng server.
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
$action     = (string) ($_POST['action'] ?? 'state');
$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);

if ($snapshotId <= 0) {
    $fail(['error' => 'snapshot'], 400);
}

// --- Kiểm tra quyền đọc đúng snapshot này (bằng chính lớp truy vấn sẵn có) ---
$context = wa_reader_context($snapshotId);

if ($context['status'] !== 'ok') {
    $fail(['error' => 'denied'], 403);
}

/* ---------------------------------------------------------------------------
 * 1) Trạng thái đọc
 * ------------------------------------------------------------------------- */
if ($action === 'state') {
    $status = (string) ($_POST['status'] ?? 'reading');

    if (!in_array($status, ['unread', 'reading', 'read'], true)) {
        $fail(['error' => 'status'], 400);
    }

    $progress = (int) ($_POST['progress'] ?? 0);
    $offset   = (int) ($_POST['scroll_offset'] ?? 0);

    wa_reading_state_set($userId, $snapshotId, $status, $progress, $offset);

    echo json_encode([
        'ok'       => true,
        'status'   => $status,
        'progress' => max(0, min(100, $progress)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * 2) Tiến độ đọc
 * ------------------------------------------------------------------------- */
if ($action === 'progress') {
    $progress = (int) ($_POST['progress'] ?? 0);
    $offset   = (int) ($_POST['scroll_offset'] ?? 0);

    $current = wa_reading_state($userId, $snapshotId);
    $status  = (string) $current['status'];

    // Đã đọc xong thì không bị ghi đè về "đang đọc" khi người dùng cuộn lại.
    if ($status === 'read') {
        $progress = 100;
    } elseif ($status === 'unread') {
        $status = 'reading';
    }

    wa_reading_state_set($userId, $snapshotId, $status, $progress, $offset);

    echo json_encode([
        'ok'       => true,
        'status'   => $status,
        'progress' => max(0, min(100, $progress)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * 3) Danh sách đọc sau
 * ------------------------------------------------------------------------- */
if ($action === 'queue') {
    $inQueue = wa_reading_queue_toggle($userId, $snapshotId);

    echo json_encode([
        'ok'        => true,
        'in_queue'  => $inQueue,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fail(['error' => 'action'], 400);
