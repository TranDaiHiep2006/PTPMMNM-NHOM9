<?php
/**
 * src/v3_basic.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 1 — TIỆN ÍCH CƠ BẢN (12 CHỨC NĂNG MỚI).
 *
 *   FEATURE 1 — Lưu hàng loạt URL
 *   FEATURE 2 — Trích dẫn tài liệu
 *   FEATURE 3 — Chế độ đọc và tiến độ đọc
 *
 * Mọi hàm ở đây đều dùng prepared statement, kiểm tra quyền ở tầng server và
 * KHÔNG ghi vòng vào `content_html` gốc của snapshot.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/capture.php';

/* =============================================================================
    FEATURE 1 — LƯU HÀNG LOẠT URL
    ============================================================================= */

/** Số URL tối đa trong MỘT lần lưu hàng loạt. */
const WA_BULK_MAX_URLS = 200;

/** Số URL xử lý trong MỘT lô (mỗi lô = MỘT request HTTP riêng). */
const WA_BULK_BATCH_SIZE = 5;

/**
 * Ngân sách bộ nhớ cho nội dung tải trước trong lưu hàng loạt (byte).
 *
 * Lưu hàng loạt tải trước nội dung để ĐỐI CHIẾU TRÙNG LẶP mà không tải lại
 * cùng một URL. Nội dung được giữ trong phiên (SESSION) giữa bước "kiểm tra
 * trùng lặp" và bước "lưu", nên với danh sách rất dài phiên có thể phình to.
 *
 * Khi vượt ngân sách, các URL còn lại SẼ ĐƯỢC TẢI LẠI ở bước lưu — vẫn đúng,
 * chỉ tốn thêm một lượt tải. Đây là đánh đổi có chủ đích để không làm chết
 * phiên. KHÔNG đụng tới hằng số của F12.
 */
const WA_BULK_PREFETCH_MAX_BYTES = 16777216; // 16 MB

/**
 * Tách danh sách URL thô thành các mục đã chuẩn hoá.
 *
 * Chấp nhận: mỗi dòng MỘT URL; hoặc CSV (dấu phẩy / chấm phẩy / tab / dấu
 * cách) — cột đầu tiên được hiểu là URL. Dòng bắt đầu bằng `#` được coi là
 * ghi chú và bỏ qua.
 *
 * @return array<int, array{raw:string, url:string}>
 */
function wa_bulk_parse_input(string $input): array
{
    $items = [];

    foreach (preg_split('/\R/u', $input) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // CSV: lấy cột đầu tiên.
        $parts = preg_split('/[,\t;]+/', $line) ?: [];
        $candidate = trim((string) ($parts[0] ?? ''));

        if ($candidate === '') {
            continue;
        }

        $items[] = ['raw' => $candidate, 'url' => $candidate];
    }

    return $items;
}

/**
 * Chuẩn hoá một URL để so sánh trùng: bỏ khoảng trắng, bỏ dấu #fragment,
 * hạ chữ scheme/host, bỏ "/" cuối, sắp xếp lại query string.
 */
function wa_bulk_normalize_url(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return mb_strtolower($url, 'UTF-8');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host   = strtolower((string) ($parts['host'] ?? ''));
    $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path   = (string) ($parts['path'] ?? '');

    if ($path === '' || $path === '/') {
        $path = '';
    } else {
        $path = rtrim($path, '/');
    }

    $query = '';

    if (isset($parts['query']) && $parts['query'] !== '') {
        parse_str((string) $parts['query'], $qs);
        ksort($qs);
        $query = http_build_query($qs);
    }

    return $scheme . '://' . $host . $port . $path . ($query !== '' ? '?' . $query : '');
}

/**
 * Kiểm tra một URL trước khi tải theo chính sách URL công khai (có kiểm tra an toàn).
 *
 * @return array{ok:bool, code:string, message:string}
 */
function wa_bulk_check_url(string $url): array
{
    $url = trim($url);

    if ($url === '') {
        return ['ok' => false, 'code' => 'empty', 'message' => 'URL rỗng'];
    }

    // CHÍNH SÁCH URL CÔNG KHAI (dùng chung với Extension, lưu thủ công,
    // RSS và Auto Version): không cần tên miền được Admin duyệt, nhưng mọi đích
    // phải qua được chốt an toàn (chặn mạng riêng, cloud metadata, đích không
    // xác minh được, DNS rebinding).
    $policy = wa_url_policy_inspect($url);

    if ($policy['ok'] !== true) {
        return [
            'ok'      => false,
            'code'    => 'blocked',
            'message' => (string) $policy['message'],
        ];
    }

    return ['ok' => true, 'code' => 'ok', 'message' => ''];
}

/**
 * Lọc danh sách URL thô: bỏ trùng, đếm hợp lệ / trùng / bị chặn.
 *
 * @param array<int, array{raw:string, url:string}> $items
 *
 * @return array{
 *   valid: array<int, array{raw:string, url:string}>,
 *   duplicates: array<int, string>,
 *   invalid: array<int, array{url:string, message:string}>,
 *   blocked: array<int, array{url:string, message:string}>
 * }
 */
function wa_bulk_filter_urls(array $items): array
{
    $valid      = [];
    $duplicates = [];
    $invalid    = [];
    $blocked    = [];
    $seen       = [];

    foreach ($items as $item) {
        $url = (string) ($item['url'] ?? '');

        $check = wa_bulk_check_url($url);

        if (!$check['ok']) {
            if ($check['code'] === 'blocked') {
                $blocked[] = ['url' => $url, 'message' => $check['message']];
            } else {
                $invalid[] = ['url' => $url, 'message' => $check['message']];
            }

            continue;
        }

        $key = wa_bulk_normalize_url($url);

        if (isset($seen[$key])) {
            $duplicates[] = $url;

            continue;
        }

        $seen[$key] = true;

        $valid[] = ['raw' => (string) ($item['raw'] ?? $url), 'url' => $url];
    }

    return [
        'valid'      => $valid,
        'duplicates' => $duplicates,
        'invalid'    => $invalid,
        'blocked'    => $blocked,
    ];
}

/**
 * Lưu kết quả TẢI TRƯỚC của lưu hàng loạt vào phiên, để bước "lưu" dùng lại
 * mà KHÔNG tải lại cùng một URL.
 *
 * Phiên chỉ chứa được một lượng vừa phải, nên có ngân sách byte. Khi vượt
 * ngân sách, URL đó sẽ được tải lại ở bước lưu — vẫn đúng, chỉ tốn thêm một
 * lượt tải. Đây là đánh đổi có chủ đích, KHÔNG đụng tới hằng số của F12.
 */
function wa_bulk_prefetch_set(string $url, ?array $capture): void
{
    if (!is_array($capture)) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
        return;
    }

    $bytes = strlen((string) json_encode($capture));

    if ($bytes <= 0) {
        return;
    }

    $used = (int) ($_SESSION['wa_bulk_prefetch_bytes'] ?? 0);

    // Đã vượt ngân sách -> không giữ, để bước lưu tự tải lại.
    if ($used + $bytes > WA_BULK_PREFETCH_MAX_BYTES) {
        return;
    }

    if (!isset($_SESSION['wa_bulk_prefetch']) || !is_array($_SESSION['wa_bulk_prefetch'])) {
        $_SESSION['wa_bulk_prefetch'] = [];
    }

    $key = md5(trim($url));

    // Nếu có sẵn bản cũ thì thay (và trừ byte cũ) — không cộng dồn.
    if (isset($_SESSION['wa_bulk_prefetch'][$key]['bytes'])) {
        $used -= (int) $_SESSION['wa_bulk_prefetch'][$key]['bytes'];
    }

    $_SESSION['wa_bulk_prefetch'][$key] = [
        'ts'     => time(),
        'bytes'  => $bytes,
        'capture' => $capture,
    ];

    $_SESSION['wa_bulk_prefetch_bytes'] = $used + $bytes;
}

/**
 * Lấy lại kết quả tải trước (nếu còn trong phiên và chưa quá hạn).
 *
 * @return array<string,mixed>|null
 */
function wa_bulk_prefetch_get(string $url): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
        return null;
    }

    $key = md5(trim($url));

    if (!isset($_SESSION['wa_bulk_prefetch'][$key]['capture'])) {
        return null;
    }

    $entry = $_SESSION['wa_bulk_prefetch'][$key];
    $age   = time() - (int) ($entry['ts'] ?? 0);

    if ($age < 0 || $age >= 1800) {
        return null;
    }

    return is_array($entry['capture'] ?? null) ? $entry['capture'] : null;
}

/**
 * Xoá một bản tải trước khỏi phiên (sau khi đã lưu xong).
 */
function wa_bulk_prefetch_clear(string $url): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
        return;
    }

    $key = md5(trim($url));

    if (isset($_SESSION['wa_bulk_prefetch'][$key]['bytes'])) {
        $_SESSION['wa_bulk_prefetch_bytes'] = max(
            0,
            (int) ($_SESSION['wa_bulk_prefetch_bytes'] ?? 0)
            - (int) $_SESSION['wa_bulk_prefetch'][$key]['bytes']
        );
    }

    unset($_SESSION['wa_bulk_prefetch'][$key]);
}

/**
 * Xoá SẠCH mọi bản tải trước của lưu hàng loạt trong phiên.
 */
function wa_bulk_prefetch_reset(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
        return;
    }

    unset($_SESSION['wa_bulk_prefetch'], $_SESSION['wa_bulk_prefetch_bytes']);
}

/**
 * Đối chiếu một lô URL với nội dung ĐÃ LƯU trong thư viện — TÁI SỬ DỤNG F12.
 *
 * Dùng đúng bộ phát hiện của F12 (`wa_dup_prefetch_content()` + `wa_dup_check_url()`),
 * KHÔNG có bộ phát hiện trùng thứ hai trong lớp lưu hàng loạt.
 *
 * Mỗi URL chỉ được TẢI MỘT LẦN: kết quả tải được giữ trong phiên (theo ngân
 * sách byte) để bước lưu dùng lại — không tải lại cùng một URL chỉ để kiểm tra
 * trùng.
 *
 * @param array<int, array{raw:string, url:string}> $urls
 * @param int   $libraryId Thư viện đích (bài cùng URL trong chính thư viện này
 *                         được loại trừ — lưu lại là tạo PHIÊN BẢN MỚI).
 *
 * @return array<int, array{url:string, dup:bool, level:string, count:int, items:array<int,array<string,mixed>>}>
 */
function wa_bulk_check_duplicates(array $urls, int $libraryId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    $out   = [];

    foreach ($urls as $item) {
        $url = trim((string) ($item['url'] ?? ''));

        if ($url === '') {
            continue;
        }

        // Bài đang có sẵn trong thư viện đích: lưu lại URL này là TẠO PHIÊN
        // BẢN MỚI của chính nó, không phải bài trùng -> loại trừ.
        $existing      = wa_find_page_by_url($libraryId, $url);
        $excludePageId = $existing !== null ? (int) $existing['id'] : 0;

        // TẢI TRƯỚC MỘT LẦN (đường tải của F12) — kết quả được giữ lại cho bước lưu.
        $prefetch = wa_dup_prefetch_content($url, $actor);

        $verdict = [
            'url'   => $url,
            'dup'   => false,
            'level' => '',
            'count' => 0,
            'items' => [],
        ];

        if ($prefetch['ok'] === true && (string) ($prefetch['content_html'] ?? '') !== '') {
            $check = wa_dup_check_url(
                $url,
                (string) $prefetch['content_html'],
                $libraryId,
                $excludePageId,
                $actor
            );

            if ($check['has_any'] === true) {
                $verdict['dup']   = true;
                $verdict['count'] = count($check['report']);

                foreach ($check['report'] as $row) {
                    $verdict['items'][] = [
                        'level'       => (string) ($row['level'] ?? ''),
                        'level_label' => (string) ($row['level_label'] ?? ''),
                        'title'       => (string) ($row['title'] ?? ''),
                        'source_url'  => (string) ($row['source_url'] ?? ''),
                        'library_id'  => (int) ($row['library_id'] ?? 0),
                        'score'       => (int) ($row['score'] ?? 0),
                        'reason'      => (string) ($row['reason'] ?? ''),
                    ];
                }

                // Mức mạnh nhất làm "nhãn" cho bài trùng.
                $level = 'similar';

                foreach ($verdict['items'] as $row) {
                    if ((string) $row['level'] === 'exact') {
                        $level = 'exact';
                        break;
                    }

                    if ((string) $row['level'] === 'url') {
                        $level = 'url';
                    }
                }

                $verdict['level'] = $level;
            }
        }

        // Chuyển kết quả tải sang kho của lưu hàng loạt (có ngân sách byte),
        // rồi XOÁ bản trong cache của F12 để không giữ nội dung hai lần.
        wa_bulk_prefetch_set($url, is_array($prefetch['result'] ?? null) ? $prefetch['result'] : null);
        wa_dup_prefetch_clear($url);

        $out[] = $verdict;
    }

    return $out;
}

/**
 * Tóm tắt ngắn gọn một bài trùng (dùng cho thông báo).
 *
 * @param array<string,mixed> $verdict
 */
function wa_bulk_dup_summary(array $verdict): string
{
    $items = (array) ($verdict['items'] ?? []);

    if ($items === []) {
        return 'nội dung đã có trong thư viện';
    }

    $first = (array) $items[0];
    $title = trim((string) ($first['title'] ?? ''));

    if ($title === '') {
        $title = trim((string) ($first['source_url'] ?? ''));
    }

    $more = count($items) - 1;

    return $title . ($more > 0 ? ' (+' . $more . ' bài khác)' : '');
}

/**
 * Lưu hàng loạt URL vào một thư viện, dùng lại `wa_store_page_capture()`.
 *
 * @param array<int, array{raw:string, url:string}> $urls Danh sách đã lọc.
 * @param array<int, string>                        $tags Danh sách nhãn chung.
 * @param array<string,mixed>                       $options
 *        - `dup_policy` : 'skip' (bỏ qua bài trùng) | 'save_all' (lưu tất cả)
 *        - `dup_map`    : map url => verdict từ `wa_bulk_check_duplicates()`
 *        - `actor`      : người dùng hiện tại
 *
 * @return array{
 *   total:int, ok:int, failed:int, skipped:int,
 *   results: array<int, array{url:string, ok:bool, message:string, snapshot_id:int, dup:bool}>
 * }
 */
function wa_bulk_store(
    array $urls,
    int $libraryId,
    array $tags,
    int $userId,
    array $options = []
): array {
    $results   = [];
    $ok        = 0;
    $failed    = 0;
    $skipped   = 0;

    $dupPolicy = (string) ($options['dup_policy'] ?? 'save_all');
    $dupMap    = (array) ($options['dup_map'] ?? []);

    foreach ($urls as $item) {
        $url = (string) ($item['url'] ?? '');

        if ($url === '') {
            continue;
        }

        // Kiểm tra lại lúc thực thi (chính sách URL có thể đã đổi).
        $check = wa_bulk_check_url($url);

        if (!$check['ok']) {
            $failed++;

            $results[] = [
                'url'         => $url,
                'ok'          => false,
                'message'     => $check['message'],
                'snapshot_id' => 0,
                'dup'         => false,
            ];

            continue;
        }

        // Bài trùng đã được người dùng QUYẾT ĐỊNH trước ở bước đối chiếu:
        // nếu chọn "bỏ qua" thì KHÔNG lưu — và KHÔNG tự xoá/gộp gì cả.
        $verdict = (array) ($dupMap[$url] ?? []);
        $isDup   = (bool) ($verdict['dup'] ?? false);

        if ($dupPolicy === 'skip' && $isDup) {
            $skipped++;

            $results[] = [
                'url'         => $url,
                'ok'          => false,
                'message'     => 'Bỏ qua — trùng với bài đã lưu: ' . wa_bulk_dup_summary($verdict),
                'snapshot_id' => 0,
                'dup'         => true,
            ];

            wa_bulk_prefetch_clear($url);

            continue;
        }

        // DÙNG LẠI nội dung đã tải ở bước đối chiếu (nếu còn trong phiên)
        // -> không tải lại cùng một URL.
        $prefetched = wa_bulk_prefetch_get($url);

        $result = wa_store_page_capture($libraryId, $url, $tags, $userId, $prefetched);

        wa_bulk_prefetch_clear($url);

        if ($result['ok'] === true) {
            $ok++;

            $results[] = [
                'url'         => $url,
                'ok'          => true,
                'message'     => 'Đã lưu phiên bản ' . (int) $result['version']
                    . ($isDup ? ' (đã xác nhận trùng)' : ''),
                'snapshot_id' => (int) $result['snapshot_id'],
                'dup'         => $isDup,
            ];
        } else {
            $failed++;

            $results[] = [
                'url'         => $url,
                'ok'          => false,
                'message'     => (string) $result['error_message'],
                'snapshot_id' => 0,
                'dup'         => $isDup,
            ];
        }
    }

    return [
        'total'   => count($results),
        'ok'      => $ok,
        'failed'  => $failed,
        'skipped' => $skipped,
        'results' => $results,
    ];
}

/* =============================================================================
    FEATURE 2 — TRÍCH DẪN TÀI LIỆU
    ============================================================================= */

/**
 * Dựng trích dẫn từ metadata của snapshot.
 *
 * Quy tắc KHÔNG BỊA: khi thiếu tác giả hoặc ngày xuất bản thì dùng nhãn quy
 * ước ("n.d." cho APA, "không rõ" cho kiểu thông thường) — không tự đặt.
 *
 * @param array<string,mixed> $snapshot Dòng snapshots (đã JOIN thêm tên người lưu).
 * @param string               $style    'apa' | 'plain'.
 *
 * @return array{text:string, fields:array<string,string>, style:string}
 */
function wa_citation_build(array $snapshot, string $style = 'apa'): array
{
    $style = $style === 'plain' ? 'plain' : 'apa';

    $title      = trim((string) ($snapshot['title'] ?? ''));
    $sourceUrl  = trim((string) ($snapshot['source_url'] ?? ''));
    $siteName   = trim((string) ($snapshot['site_name'] ?? ''));
    $authorName = trim((string) ($snapshot['author_name'] ?? ''));
    $savedAt    = trim((string) ($snapshot['fetched_at'] ?? ''));
    $versionNo  = (int) ($snapshot['version_no'] ?? 0);
    $snapshotId = (int) ($snapshot['id'] ?? 0);

    // Nguồn: tên miền của trang gốc (luôn có, không bịa).
    if ($siteName === '') {
        $siteName = (string) (parse_url($sourceUrl, PHP_URL_HOST) ?? '');
    }

    // Ngày lưu (ngày truy cập bản lưu).
    $savedLabel = '';
    $savedYear  = '';

    if ($savedAt !== '') {
        $ts = strtotime($savedAt);

        if ($ts !== false) {
            $savedLabel = date('d/m/Y', $ts);
            $savedYear  = (string) date('Y', $ts);
        }
    }

    // Ngày xuất bản: chưa có trong CSDL -> theo quy ước, KHÔNG BỊA.
    $publishedYear = '';
    $publishedLabel = '';

    if (!empty($snapshot['published_at'])) {
        $ts = strtotime((string) $snapshot['published_at']);

        if ($ts !== false) {
            $publishedYear  = (string) date('Y', $ts);
            $publishedLabel = date('d/m/Y', $ts);
        }
    }

    $fields = [
        'title'      => $title,
        'author'     => $authorName,
        'site_name'  => $siteName,
        'url'        => $sourceUrl,
        'version_no' => (string) $versionNo,
        'snapshot_id' => (string) $snapshotId,
        'saved_at'   => $savedLabel,
        'published_at' => $publishedLabel,
    ];

    if ($style === 'apa') {
        // APA 7: Tác giả. (Năm). Tiêu đề. Nguồn. URL
        // - Không có tác giả -> bắt đầu bằng tiêu đề.
        // - Không có năm xuất bản -> (n.d.)
        $authorPart = $authorName !== '' ? $authorName . '. ' : '';
        $yearPart   = $publishedYear !== '' ? $publishedYear : 'n.d.';

        $text = $authorPart . '(' . $yearPart . '). ' . ($title !== '' ? $title . '. ' : '')
            . ($siteName !== '' ? $siteName . '. ' : '')
            . ($sourceUrl !== '' ? $sourceUrl : '')
            . ($savedLabel !== '' ? ' (Bản lưu ngày ' . $savedLabel . ')' : '');

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    } else {
        // Kiểu thông thường (IEEE-like): Tác giả, "Tiêu đề," Nguồn, ngày.
        $text = ($authorName !== '' ? $authorName . ', ' : '')
            . '"' . ($title !== '' ? $title : 'Không rõ tiêu đề') . '," '
            . ($siteName !== '' ? $siteName . ', ' : '')
            . ($publishedLabel !== '' ? $publishedLabel . ', ' : ($savedLabel !== '' ? $savedLabel . ', ' : ''))
            . ($sourceUrl !== '' ? $sourceUrl : '')
            . ($versionNo > 0 ? ' (bản lưu V' . $versionNo . ')' : '');

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    return ['text' => $text, 'fields' => $fields, 'style' => $style];
}

/* =============================================================================
    FEATURE 3 — CHẾ ĐỘ ĐỌC VÀ TIẾN ĐỘ ĐỌC
    ============================================================================= */

/**
 * Trạng thái đọc hiện tại của một tài khoản với một snapshot.
 *
 * @return array{status:string, progress:int, scroll_offset:int, updated_at:string}
 */
function wa_reading_state(int $userId, int $snapshotId): array
{
    $default = ['status' => 'unread', 'progress' => 0, 'scroll_offset' => 0, 'updated_at' => ''];

    if ($userId <= 0 || $snapshotId <= 0) {
        return $default;
    }

    try {
        $stmt = db()->prepare(
            'SELECT status, progress, scroll_offset, updated_at
               FROM `reading_states`
              WHERE user_id = ? AND snapshot_id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId, $snapshotId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $default;
        }

        return [
            'status'        => (string) $row['status'],
            'progress'      => (int) $row['progress'],
            'scroll_offset' => (int) $row['scroll_offset'],
            'updated_at'    => (string) $row['updated_at'],
        ];
    } catch (Throwable $e) {
        error_log('[WebArchive] reading state failed: ' . $e->getMessage());

        return $default;
    }
}

/**
 * Lưu trạng thái đọc + tiến độ đọc (upsert).
 */
function wa_reading_state_set(int $userId, int $snapshotId, string $status, int $progress, int $scrollOffset = 0): bool
{
    if ($userId <= 0 || $snapshotId <= 0) {
        return false;
    }

    if (!in_array($status, ['unread', 'reading', 'read'], true)) {
        $status = 'reading';
    }

    $progress = max(0, min(100, $progress));

    try {
        $stmt = db()->prepare(
            'INSERT INTO `reading_states`
                (`user_id`, `snapshot_id`, `status`, `progress`, `scroll_offset`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                `status`        = VALUES(`status`),
                `progress`      = VALUES(`progress`),
                `scroll_offset` = VALUES(`scroll_offset`),
                `updated_at`    = NOW()'
        );
        $stmt->execute([$userId, $snapshotId, $status, $progress, max(0, $scrollOffset)]);

        return true;
    } catch (Throwable $e) {
        error_log('[WebArchive] reading state write failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Trạng thái đọc của NHIỀU snapshot cùng lúc (để vẽ badge trên thẻ bài viết).
 *
 * @param array<int, int> $snapshotIds
 *
 * @return array<int, array{status:string, progress:int}>
 */
function wa_reading_states_map(int $userId, array $snapshotIds): array
{
    $map = [];

    $ids = array_values(array_unique(array_filter(array_map('intval', $snapshotIds))));

    if ($userId <= 0 || $ids === []) {
        return $map;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = db()->prepare(
            'SELECT snapshot_id, status, progress
               FROM `reading_states`
              WHERE user_id = ? AND snapshot_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$userId], $ids));

        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['snapshot_id']] = [
                'status'   => (string) $row['status'],
                'progress' => (int) $row['progress'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[WebArchive] reading states map failed: ' . $e->getMessage());
    }

    return $map;
}

/* --- Danh sách đọc sau ---------------------------------------------------- */

/**
 * Thêm / gỡ một snapshot khỏi danh sách đọc sau.
 */
function wa_reading_queue_toggle(int $userId, int $snapshotId): bool
{
    if ($userId <= 0 || $snapshotId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM `reading_queue` WHERE user_id = ? AND snapshot_id = ?'
        );
        $stmt->execute([$userId, $snapshotId]);

        if ((int) $stmt->fetchColumn() > 0) {
            $stmt = db()->prepare(
                'DELETE FROM `reading_queue` WHERE user_id = ? AND snapshot_id = ?'
            );
            $stmt->execute([$userId, $snapshotId]);

            return false;
        }

        $stmt = db()->prepare(
            'INSERT IGNORE INTO `reading_queue` (`user_id`, `snapshot_id`) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $snapshotId]);

        return true;
    } catch (Throwable $e) {
        error_log('[WebArchive] reading queue toggle failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Snapshot nào đang nằm trong danh sách đọc sau của tài khoản.
 *
 * @param array<int, int> $snapshotIds
 *
 * @return array<int, bool>
 */
function wa_reading_queue_map(int $userId, array $snapshotIds): array
{
    $map = [];

    $ids = array_values(array_unique(array_filter(array_map('intval', $snapshotIds))));

    if ($userId <= 0 || $ids === []) {
        return $map;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = db()->prepare(
            'SELECT snapshot_id FROM `reading_queue`
              WHERE user_id = ? AND snapshot_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$userId], $ids));

        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['snapshot_id']] = true;
        }
    } catch (Throwable $e) {
        error_log('[WebArchive] reading queue map failed: ' . $e->getMessage());
    }

    return $map;
}

/**
 * Danh sách đọc sau (đầy đủ thông tin hiển thị).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_reading_queue_list(int $userId, int $limit = 50): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT q.snapshot_id, q.created_at, s.title, s.version_no, s.source_url,
                    s.content_text, sp.library_id, sp.domain
               FROM `reading_queue` q
               JOIN `snapshots` s ON s.id = q.snapshot_id
               JOIN `saved_pages` sp ON sp.id = s.page_id
              WHERE q.user_id = ? AND s.fetch_status = \'success\'
           ORDER BY q.created_at DESC
              LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $row['snapshot_id'] = (int) $row['snapshot_id'];
            $row['version_no']  = (int) $row['version_no'];
            $row['library_id']  = (int) $row['library_id'];
            $row['added_label'] = wa_datetime((string) $row['created_at']);
            $row['excerpt']     = wa_trim((string) $row['content_text'], 160);
            $rows[]             = $row;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] reading queue list failed: ' . $e->getMessage());

        return [];
    }
}
