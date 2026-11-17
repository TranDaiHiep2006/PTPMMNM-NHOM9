<?php
/**
 * check_one.php — Kiểm tra nền 1 bài viết: tải trang gốc, so sánh khối với
 * bản thành công mới nhất, ghi cờ has_update, trả JSON cho JS tự hiện nhãn.
 *
 *   POST check_one.php (page_id + token CSRF) -> JSON, KHÔNG render trang.
 *
 * Khác check_update.php ở chỗ: không hiện diff, không hiện trang xem trước —
 * chỉ trả {changed} để JS gắn/gỡ badge trên thẻ bài và banner trong trình
 * đọc. Đường này KHÔNG ghi snapshot nào (giống check_update.php: xem trước
 * thôi, muốn lưu thật thì bấm "Lưu thành bản mới" đi qua refetch.php cũ).
 *
 * Quyền: giống nút "Kiểm tra bản mới" — chỉ chủ sở hữu / biên tập viên /
 * quản trị viên mới kiểm tra được. Người xem không gọi được (trả 403 JSON).
 * Không tải được trang gốc thì cờ cũ được giữ nguyên, trả checked=false để
 * JS giữ nguyên nhãn cũ.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/diff.php';

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

$pageId = (int) ($_POST['page_id'] ?? 0);
$page = null;
if ($pageId > 0) {
    $stmt = db()->prepare(
        'SELECT sp.id, sp.library_id, sp.source_url
           FROM `saved_pages` sp
          WHERE sp.id = ?
          LIMIT 1'
    );
    $stmt->execute([$pageId]);
    $page = $stmt->fetch() ?: null;
}

if ($page === null) {
    $fail(['error' => 'not_found'], 404);
}

$libraryId = (int) $page['library_id'];
$role = wa_library_role($libraryId, $actor);
$canCheck = $actor['role'] === 'admin' || in_array($role, ['owner', 'editor'], true);
if (!$canCheck) {
    $fail(['error' => 'forbidden'], 403);
}

/* --- Bản thành công mới nhất làm mốc (giống check_update.php). --- */
$versions = wa_page_versions($pageId, 0);
$latest = $versions[0] ?? null;
if ($latest === null) {
    echo json_encode(
        ['ok' => true, 'checked' => false, 'changed' => false],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
$latestId = (int) $latest['id'];

/* --- Tải trang gốc (KHÔNG ghi snapshot). Lỗi tải thì giữ cờ cũ. --- */
$live = capture_url((string) $page['source_url']);
if ($live['ok'] !== true) {
    echo json_encode(
        ['ok' => true, 'checked' => false, 'changed' => false, 'error_code' => (string) $live['error_code']],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$stmt = db()->prepare(
    'SELECT title, content_html, content_text
       FROM `snapshots`
      WHERE id = ? AND fetch_status = \'success\'
      LIMIT 1'
);
$stmt->execute([$latestId]);
$saved = $stmt->fetch() ?: ['title' => '', 'content_html' => '', 'content_text' => ''];

$savedUnits = wa_diff_units((string) $saved['content_html'], (string) $saved['content_text']);
$liveUnits = wa_diff_units((string) $live['content_html'], (string) $live['content_text']);
$ops = wa_diff_ops($savedUnits, $liveUnits);
$titleChanged = ((string) $saved['title'] !== (string) $live['title']);
$changed = wa_diff_changed($ops) || $titleChanged;

/* --- Ghi cờ + ngày kiểm tra (mốc luôn là bản mới nhất nên ghi thẳng). --- */
wa_page_check_mark($pageId, $changed);

echo json_encode(
    [
        'ok' => true,
        'checked' => true,
        'changed' => $changed,
        'checked_at' => wa_datetime(date('Y-m-d H:i:s')),
        'snapshot_id' => $latestId,
    ],
    JSON_UNESCAPED_UNICODE
);
