<?php
/**
 * api.php — API CHO CHROME EXTENSION (V3 / FEATURE 7).
 *
 * Xác thực bằng TOKEN RIÊNG (bearer token), KHÔNG dùng cookie phiên.
 *
 *   GET  api.php?action=whoami                → thông tin tài khoản + thư viện
 *   GET  api.php?action=libraries             → danh sách thư viện có quyền ghi
 *   POST api.php?action=library_create        → tạo thư viện mới
 *   POST api.php?action=save                  → lưu trang vào thư viện
 *
 * Mọi hành động ghi đều kiểm tra lại quyền ở tầng server, cùng chính sách URL
 * công khai có kiểm tra an toàn (chặn mạng riêng, cloud metadata, DNS rebinding).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

wa_api_cors_headers();

// Preflight CORS (Extension gọi POST với Content-Type: application/json).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------------------------------------------------------------------------
 * Chặn request đến từ website thường.
 *
 * Header `Origin` do trình duyệt gắn vào và website KHÔNG thể giả mạo được.
 * - Không có Origin  → gọi từ CLI / công cụ dòng lệnh: cho qua (vẫn cần token).
 * - Origin hợp lệ     → chrome-extension://... : cho qua.
 * - Origin khác       → chặn ngay, KHÔNG chạy hành động.
 * ------------------------------------------------------------------------- */
$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

if ($origin !== '' && wa_api_allowed_origin() === '') {
    wa_api_json(['ok' => false, 'error' => 'Origin không được phép truy cập API này.'], 403);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

/* ---------------------------------------------------------------------------
 * Xác thực token
 * ------------------------------------------------------------------------- */
$auth = wa_api_authenticate(wa_api_read_token());

if ($auth['ok'] !== true) {
    wa_api_json(['ok' => false, 'error' => $auth['error']], 401);
}

$token = (array) $auth['token'];
$user  = (array) $auth['user'];
$userId = (int) $user['id'];

// Ngữ cảnh actor cho các hàm truy vấn (tương đương đang đăng nhập).
$actor = ['id' => $userId, 'role' => (string) $user['role']];

/* ---------------------------------------------------------------------------
 * Giới hạn tần suất
 * ------------------------------------------------------------------------- */
$isHeavy = in_array($action, ['save', 'library_create'], true);
$rate    = wa_api_rate_limit($token, $isHeavy);

if ($rate['ok'] !== true) {
    wa_api_json([
        'ok'    => false,
        'error' => 'Quá nhiều yêu cầu trong thời gian ngắn. Vui lòng thử lại sau.',
    ], 429);
}

wa_api_token_touch((int) $token['id']);

/* ---------------------------------------------------------------------------
 * Action: whoami / libraries
 * ------------------------------------------------------------------------- */
if ($action === 'whoami' || $action === 'libraries') {
    if (!wa_api_token_can($token, 'read')) {
        wa_api_json(['ok' => false, 'error' => 'Token không có quyền đọc.'], 403);
    }

    $libraries = [];

    foreach (wa_writable_libraries($actor) as $lib) {
        $libraries[] = [
            'id'   => (int) $lib['id'],
            'name' => (string) $lib['name'],
            'slug' => (string) $lib['slug'],
            'role' => (string) $lib['role'],
        ];
    }

    wa_api_json([
        'ok'        => true,
        'user'      => [
            'id'    => $userId,
            'name'  => (string) $user['name'],
            'email' => (string) $user['email'],
            'role'  => (string) $user['role'],
        ],
        'libraries' => $libraries,
    ]);
}

/* ---------------------------------------------------------------------------
 * Action: library_create — tạo thư viện mới (dùng lại wa_library_create)
 * ------------------------------------------------------------------------- */
if ($action === 'library_create') {
    if (!wa_api_token_can($token, 'library:create')) {
        wa_api_json(['ok' => false, 'error' => 'Token không có quyền tạo thư viện.'], 403);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        wa_api_json(['ok' => false, 'error' => 'Phải dùng POST.'], 405);
    }

    $input = wa_api_input();

    $name        = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $visibility  = (string) ($input['visibility'] ?? 'private');
    $color       = (string) ($input['color'] ?? 'a');
    $icon        = (string) ($input['icon'] ?? 'folder_special');

    // Dùng đúng hàm và quy tắc quản lý thư viện sẵn có.
    $result = wa_library_create($name, $description, $visibility, $color, $icon, $userId);

    if ($result['ok'] !== true) {
        wa_api_json(['ok' => false, 'error' => (string) $result['message']], 400);
    }

    $libraryId = (int) $result['library_id'];

    // Làm mới danh sách và trả về thư viện vừa tạo để Extension tự chọn.
    $libraries = [];

    foreach (wa_writable_libraries($actor) as $lib) {
        $libraries[] = [
            'id'   => (int) $lib['id'],
            'name' => (string) $lib['name'],
            'slug' => (string) $lib['slug'],
            'role' => (string) $lib['role'],
        ];
    }

    wa_api_json([
        'ok'         => true,
        'message'    => (string) $result['message'],
        'library'    => [
            'id'   => $libraryId,
            'name' => $name,
            'slug' => (string) $result['slug'],
            'role' => 'owner',
        ],
        'libraries'  => $libraries,
    ]);
}

/* ---------------------------------------------------------------------------
 * Action: save — lưu trang vào thư viện (dùng lại wa_store_page_capture)
 * ------------------------------------------------------------------------- */
if ($action === 'save') {
    if (!wa_api_token_can($token, 'page:save')) {
        wa_api_json(['ok' => false, 'error' => 'Token không có quyền lưu trang.'], 403);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        wa_api_json(['ok' => false, 'error' => 'Phải dùng POST.'], 405);
    }

    $input = wa_api_input();

    $url       = trim((string) ($input['url'] ?? ''));
    $libraryId = (int) ($input['library_id'] ?? 0);
    $tagsInput = (string) ($input['tags'] ?? '');

    if ($url === '') {
        wa_api_json(['ok' => false, 'error' => 'Thiếu URL cần lưu.'], 400);
    }

    if ($libraryId <= 0) {
        wa_api_json(['ok' => false, 'error' => 'Vui lòng chọn thư viện đích.'], 400);
    }

    // --- Kiểm tra quyền ghi (tầng server, không tin Extension) ---
    if (!wa_can_write_library($libraryId, $actor)) {
        wa_api_json(['ok' => false, 'error' => 'Bạn không có quyền lưu trang vào thư viện này.'], 403);
    }
    // --- CHỐT AN TOÀN TRƯỚC khi gọi mạng (chính sách URL công khai) ---
    // Tên miền KHÔNG cần được Admin duyệt. Máy chủ tự kiểm tra DNS + IP,
    // chặn mạng riêng, cloud metadata, đích không xác minh được.
    $policy = wa_url_policy_inspect($url);

    if ($policy['ok'] !== true) {
        wa_api_json([
            'ok'      => false,
            'error'   => (string) $policy['message'],
            'blocked' => true,
        ], 400);
    }

    // --- CẢNH BÁO RỦI RO (chỉ khi có tín hiệu kiểm chứng được) ---
    // Thao tác xác nhận của người dùng CHỈ bỏ qua CẢNH BÁO, KHÔNG bao giờ
    // bỏ qua chốt an toàn ở trên.
    $overrideRisk = ($input['override_risk'] ?? false) === true;

    if ($policy['signals'] !== [] && !$overrideRisk) {
        wa_api_json([
            'ok'          => false,
            'risk'        => true,
            'risk_level'  => (string) $policy['level'],
            'risk_reason' => wa_url_policy_warning_message($policy['signals'], (string) $policy['host']),
            'signals'     => array_map(
                static fn (array $s): array => [
                    'code'   => (string) ($s['code'] ?? ''),
                    'label'  => (string) ($s['label'] ?? ''),
                    'detail' => (string) ($s['detail'] ?? ''),
                ],
                $policy['signals']
            ),
            'host'        => (string) $policy['host'],
            'error'       => 'Trang này có dấu hiệu rủi ro nên cần xác nhận trước khi lưu.',
        ], 409);
    }
    $tagNames = wa_parse_tag_input($tagsInput);

    /* ---------------------------------------------------------------------------
     * V3 ĐỢT 4 / F12 — PHÁT HIỆN NỘI DUNG TRÙNG LẶP (cảnh báo trước khi lưu).
     *
     * Chỉ CẢNH BÁO + cho người dùng quyết định: KHÔNG tự xoá/gộp/ghi đè snapshot.
     * ------------------------------------------------------------------------- */
    $overrideDup = ($input['override_dup'] ?? false) === true;

    // Nội dung tải sẵn (nếu có) để DÙNG LẠI khi lưu — không tải URL hai lần.
    $prefetchedResult = null;

    if (!$overrideDup) {
        $prefetch = wa_dup_prefetch_content($url, $actor);

        if ($prefetch['ok'] === true && (string) $prefetch['content_html'] !== '') {
            $prefetchedResult = is_array($prefetch['result'] ?? null) ? $prefetch['result'] : null;

            // Bài đang lưu thêm phiên bản: loại trừ chính nó (V1/V2/V3 cùng bài).
            $existingPage = wa_find_page_by_url($libraryId, $url);
            $excludePageId = $existingPage !== null ? (int) $existingPage['id'] : 0;

            $dupCheck = wa_dup_check_url(
                $url,
                (string) $prefetch['content_html'],
                $libraryId,
                $excludePageId,
                $actor
            );

            if ($dupCheck['has_any'] === true) {
                $items = [];

                foreach ($dupCheck['report'] as $dup) {
                    $items[] = [
                        'page_id'      => (int) ($dup['page_id'] ?? 0),
                        'snapshot_id'  => (int) ($dup['snapshot_id'] ?? 0),
                        'title'        => (string) ($dup['title'] ?? ''),
                        'source_url'   => (string) ($dup['source_url'] ?? ''),
                        'library_name' => (string) ($dup['library_name'] ?? ''),
                        'level'        => (string) ($dup['level'] ?? ''),
                        'level_label'  => (string) ($dup['level_label'] ?? ''),
                        'score'        => (int) ($dup['score'] ?? 0),
                        'reason'       => (string) ($dup['reason'] ?? ''),
                        'reader_url'   => (int) ($dup['snapshot_id'] ?? 0) > 0
                            ? wa_url('reader.php?id=' . (int) $dup['snapshot_id'])
                            : '',
                    ];
                }

                wa_api_json([
                    'ok'          => false,
                    'duplicate'   => true,
                    'error'       => 'Trang này trùng hoặc gần giống với bài đã lưu — cần xác nhận trước khi lưu.',
                    'duplicates'  => $items,
                    'scanned'     => (int) $dupCheck['scanned'],
                    'confirmation'=> 'Gửi lại với override_dup = true để vẫn lưu.',
                ], 409);
            }
        }
    }

    // --- Chống tạo snapshot trùng: URL vừa lưu -> trả lại bản lưu cũ ---
    $recent = wa_api_find_recent_snapshot($libraryId, $url, 10);

    if ($recent !== null) {
        wa_api_json([
            'ok'          => true,
            'message'     => 'Trang này vừa được lưu — dùng lại bản lưu hiện có (phiên bản '
                             . $recent['version_no'] . ').',
            'duplicate'   => true,
            'snapshot_id' => $recent['id'],
            'page_id'     => $recent['page_id'],
            'version'     => $recent['version_no'],
            'title'       => '',
            'source_url'  => $url,
            'reader_url'  => wa_url('reader.php?id=' . $recent['id']),
        ]);
    }

    // --- Lưu thật qua đường lưu dùng chung (DÙNG LẠI nội dung đã tải sẵn) ---
    if ($prefetchedResult === null) {
        $prefetched = wa_dup_prefetch_get($url);
        $prefetchedResult = is_array($prefetched['result'] ?? null) ? $prefetched['result'] : null;
    }

    $result = wa_store_page_capture($libraryId, $url, $tagNames, $userId, $prefetchedResult);

    wa_dup_prefetch_clear($url);

    if ($result['ok'] !== true) {
        wa_api_json([
            'ok'         => false,
            'error'      => (string) $result['error_message'],
            'error_code' => (string) $result['error_code'],
        ], 422);
    }

    wa_api_json([
        'ok'          => true,
        'message'     => 'Đã lưu phiên bản ' . (int) $result['version'] . ' của “' . (string) $result['title'] . '”.',
        'snapshot_id' => (int) $result['snapshot_id'],
        'page_id'     => (int) $result['page_id'],
        'version'     => (int) $result['version'],
        'title'       => (string) $result['title'],
        'source_url'  => (string) $result['source_url'],
        'reader_url'  => wa_url('reader.php?id=' . (int) $result['snapshot_id']),
    ]);
}

wa_api_json(['ok' => false, 'error' => 'Hành động không hợp lệ.'], 400);
