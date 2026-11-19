<?php
/**
 * auto_check.php — ĐIỂM VÀO DUY NHẤT của kiểm tra tự động (AUTO VERSION).
 *
 *   POST auto_check.php (token CSRF + hành động)
 *     hành động = enable | disable | consent | revoke | run
 *
 * CÁC HÀNH ĐỘNG
 * -----------------------------------------------------------------------------
 * 1. `enable` — BẬT tự động cập nhật. Lưu lâu dài theo tài khoản (cột
 *    `users.auto_update_enabled`), KHÔNG hỏi lại mỗi lần mở ứng dụng.
 *
 * 2. `disable` — TẮT tự động cập nhật. Từ đây mọi request kiểm tra đều bị từ
 *    chối TRƯỚC khi gọi mạng.
 *
 * 3. `consent` — ghi cờ đồng ý trong phiên (giữ để tương thích).
 *
 * 4. `revoke` — thu hồi cờ phiên VÀ tắt cài đặt lâu dài.
 *
 * 5. `run` — chạy kiểm tra. NẾU đã tắt tự động cập nhật thì hàm này dừng
 *    ngay và KHÔNG gửi bất kỳ request nào ra website nguồn.
 *
 * MẶC ĐỊNH: tự động cập nhật BẬT (tài khoản chưa cài đặt vẫn được kiểm tra
 * tự động khi mở ứng dụng — không có popup xin phép lặp lại).
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
 * 1) CÀI ĐẶT BẬT/TẮT — lưu lâu dài theo tài khoản (KHÔNG hỏi lại mỗi lần mở)
 * ------------------------------------------------------------------------- */
if ($action === 'enable' || $action === 'disable') {
    wa_auto_update_set($userId, $action === 'enable');

    // Tắt thì thu hồi luôn cờ đồng ý trong phiên -> chắc chắn không gọi mạng.
    if ($action === 'disable') {
        wa_auto_consent_revoke();
    }

    echo json_encode([
        'ok'      => true,
        'enabled' => wa_auto_update_enabled($userId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * 2) ĐỒNG Ý — chỉ ghi cờ phiên, KHÔNG gọi mạng.
 *    (Giữ lại để tương thích: cài đặt mới đã thay thế việc hỏi lặp lại.)
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
 * 3) THU HỒI ĐỒNG Ý
 * ------------------------------------------------------------------------- */
if ($action === 'revoke') {
    wa_auto_consent_revoke();
    // Tắt luôn cài đặt lâu dài -> người dùng không bị hỏi lại mỗi lần mở.
    wa_auto_update_set($userId, false);

    echo json_encode([
        'ok'      => true,
        'consent' => false,
        'enabled' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * 3) CHẠY KIỂM TRA
 * ------------------------------------------------------------------------- */
if ($action !== 'run') {
    $fail(['error' => 'action'], 400);
}

/* --- CỔNG CHẶN: ĐÃ TẮT TỰ ĐỘNG CẬP NHẬT -> KHÔNG GỌI MẠNG --- */
if (!wa_auto_update_enabled($userId)) {
    $fail([
        'error'   => 'auto_update_disabled',
        'consent' => false,
        'enabled' => false,
        'message' => 'Tự động cập nhật đang tắt — không có yêu cầu nào được gửi tới website nguồn.',
    ], 403);
}

/* --- Chỉ bài mà người dùng CÓ QUYỀN GHI mới được kiểm tra. --- */
$candidates = wa_auto_candidate_pages($actor);

if ($candidates === []) {
    echo json_encode([
        'ok'       => true,
        'consent'  => true,
        'enabled'  => true,
        'checked'  => 0,
        'results'  => [],
        'message'  => 'Không có bài viết nào đủ điều kiện để kiểm tra tự động.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- Chọn bài ĐẾN HẠN kiểm tra TRƯỚC khi áp giới hạn số bài. ---
 *
 * Nếu cắt danh sách thô trước rồi mới lọc "đã kiểm tra gần đây", thì 5 bài
 * đầu có thể đều đang nghỉ (chưa tới 900 giây) -> lượt này không kiểm tra
 * được bài nào, còn những website phía sau không bao giờ được tới.
 *
 * Vì vậy: lọc theo khoảng cách tối thiểu TRƯỚC, rồi mới cắt tối đa 5 bài.
 * Danh sách đã được sắp xếp "chưa kiểm tra / kiểm tra lâu nhất" trước, nên
 * mỗi website đều có cơ hội được kiểm tra qua các lượt sử dụng.
 */
$due = [];

foreach ($candidates as $page) {
    $pageId = (int) ($page['id'] ?? 0);

    if ($pageId <= 0) {
        continue;
    }

    // Bài đã kiểm tra tự động gần đây thì bỏ qua (tránh tải lại cùng URL).
    if (wa_auto_checked_recently($pageId)) {
        continue;
    }

    $due[] = $page;
}

/* --- Giới hạn số bài kiểm tra trong một lần mở ứng dụng. --- */
$due = array_slice($due, 0, WA_AUTO_MAX_PAGES_PER_RUN);

$results   = [];
$updated   = 0;
$unchanged = 0;
$skipped   = 0;
$failed    = 0;

foreach ($due as $page) {
    $pageId = (int) ($page['id'] ?? 0);

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

/* --- Số bài còn "đến hạn" nhưng vượt giới hạn 5 — để báo cho người dùng biết
       sẽ được kiểm tra ở lượt sau (không phải cron, vẫn chỉ khi mở ứng dụng). */
$pending = count($candidates) - count($due) - $skipped;

/* --- Số thông báo chưa đọc sau khi kiểm tra (cho badge trên thanh trên). --- */
$unread = wa_notify_unread_count($userId);

echo json_encode([
    'ok'       => true,
    'consent'  => true,
    'enabled'  => true,
    'checked'  => count($results),
    'updated'  => $updated,
    'unchanged' => $unchanged,
    'skipped'  => $skipped,
    'failed'   => $failed,
    'pending'  => max(0, $pending),
    'unread'   => $unread,
    'results'  => $results,
], JSON_UNESCAPED_UNICODE);
