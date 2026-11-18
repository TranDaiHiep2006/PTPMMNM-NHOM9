<?php
/**
 * auto_check.php — ĐIỂM VÀO DUY NHẤT của kiểm tra tự động (AUTO VERSION).
 *
 *   POST auto_check.php (token CSRF + hành động)
 *     hành động = consent | revoke | run
 *
 * BA HÀNH ĐỘNG
 * -----------------------------------------------------------------------------
 * 1. `consent` — người dùng BẤM ĐỒNG Ý. Chỉ ghi cờ trong phiên, KHÔNG gọi
 *    mạng. Sau đó mới được phép kiểm tra trong phiên này.
 *
 * 2. `revoke` — người dùng bấm "Tạm dừng". Xoá cờ trong phiên. Từ đây mọi
 *    request kiểm tra đều bị từ chối TRƯỚC khi gọi mạng.
 *
 * 3. `run` — chạy kiểm tra. NẾU CHƯA ĐỒNG Ý thì hàm này dừng ngay và
 *    KHÔNG gửi bất kỳ request nào ra website nguồn.
 *
 * CHỈ chạy khi người dùng đang mở ứng dụng: không cron, không daemon, không
 * Task Scheduler. Mỗi lần gọi tự dừng sau khi kiểm tra xong.
 *
 * Quyền: chỉ chủ sở hữu / biên tập viên / quản trị viên được kiểm tra được
 * (đã ép trong SQL ở wa_auto_candidate_pages()). Người xem bị từ chối.
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

$userId = (int) $actor['id'];
$action = (string) ($_POST['action'] ?? 'run');

/* ---------------------------------------------------------------------------
 * 1) ĐỒNG Ý — chỉ ghi cờ phiên, KHÔNG gọi mạng.
 * ------------------------------------------------------------------------- */
if ($action === 'consent') {
    wa_auto_consent_grant();

    echo json_encode([
        'ok'          => true,
        'consent'     => true,
        'consent_at'  => wa_datetime(date('Y-m-d H:i:s')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * 2) THU HỒI ĐỒNG Ý
 * ------------------------------------------------------------------------- */
if ($action === 'revoke') {
    wa_auto_consent_revoke();

    echo json_encode([
        'ok'      => true,
        'consent' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * 3) CHẠY KIỂM TRA
 * ------------------------------------------------------------------------- */
if ($action !== 'run') {
    $fail(['error' => 'action'], 400);
}

/* --- CỔNG CHẶN: CHƯA ĐỒNG Ý -> KHÔNG GỌI MẠNG --- */
if (!wa_auto_consent_given()) {
    $fail([
        'error'   => 'consent_required',
        'consent' => false,
        'message' => 'Chưa được phép kiểm tra tự động — không có yêu cầu nào được gửi tới website nguồn.',
    ], 403);
}

/* --- Chỉ bài mà người dùng CÓ QUYỀN GHI mới được kiểm tra. --- */
$candidates = wa_auto_candidate_pages($actor);

if ($candidates === []) {
    echo json_encode([
        'ok'       => true,
        'consent'  => true,
        'checked'  => 0,
        'results'  => [],
        'message'  => 'Không có bài viết nào đủ điều kiện để kiểm tra tự động.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- Giới hạn số bài kiểm tra trong một lần mở ứng dụng. --- */
$candidates = array_slice($candidates, 0, WA_AUTO_MAX_PAGES_PER_RUN);

$results   = [];
$updated   = 0;
$unchanged = 0;
$skipped   = 0;
$failed    = 0;

foreach ($candidates as $page) {
    $pageId = (int) ($page['id'] ?? 0);

    // Bài đã kiểm tra tự động gần đây thì bỏ qua (tránh tải lại cùng URL).
    if (wa_auto_checked_recently($pageId)) {
        $skipped++;
        continue;
    }

    $result = wa_auto_check_page($page, $userId);

    $status = (string) ($result['status'] ?? 'unknown');

    if ($status === 'updated') {
        $updated++;
    } elseif ($status === 'unchanged') {
        $unchanged++;
    } elseif ($status === 'locked') {
        $skipped++;
    } elseif ($status === 'fetch_failed' || $status === 'create_failed') {
        $failed++;
    }

    $results[] = [
        'page_id'     => $pageId,
        'title'       => (string) ($page['title'] ?? ''),
        'status'      => $status,
        'version'     => (int) ($result['version'] ?? 0),
        'snapshot_id' => (int) ($result['snapshot_id'] ?? 0),
    ];
}

/* --- Số thông báo chưa đọc sau khi kiểm tra (cho badge trên thanh trên). --- */
$unread = wa_notify_unread_count($userId);

echo json_encode([
    'ok'       => true,
    'consent'  => true,
    'checked'  => count($results),
    'updated'  => $updated,
    'unchanged' => $unchanged,
    'skipped'  => $skipped,
    'failed'   => $failed,
    'unread'   => $unread,
    'results'  => $results,
], JSON_UNESCAPED_UNICODE);
