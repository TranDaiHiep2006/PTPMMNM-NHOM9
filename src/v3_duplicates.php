<?php
/**
 * src/v3_duplicates.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 4 — PHÁT HIỆN NỘI DUNG TRÙNG LẶP (F12).
 *
 * Ba mức phát hiện, tất cả đều nhẹ và chạy được với PHP thuần + MySQL:
 *
 *   1. TRÙNG URL  — so sánh URL đã CHUẨN HOÁ (wa_bulk_normalize_url).
 *   2. TRÙNG NỘI DUNG CHÍNH XÁC — SHA-256 của văn bản đã chuẩn hoá
 *      (dùng cột `snapshots.content_hash` đã có sẵn).
 *   3. GẦN GIỐNG — Jaccard trên tập n-gram (3 từ) của phần nội dung rút gọn.
 *      Có bước lọc trước bằng độ dài + hash để KHÔNG quét cả thư viện.
 *
 * Nguyên tắc:
 *   - KHÔNG bao giờ tự xoá / gộp / ghi đè snapshot.
 *   - KHÔNG coi các phiên bản V1/V2/V3 của CÙNG một bài là bài trùng độc lập
 *     (loại trừ theo page_id).
 *   - CHỈ so sánh trong phạm vi bài viết mà người dùng có quyền đọc.
 *   - Số lượng bài so sánh có giới hạn để không làm chậm hệ thống.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Số bài ứng viên tối đa được quét cho một lần phát hiện. */
const WA_DUP_MAX_CANDIDATES = 200;

/** Số n-gram (3 từ) lấy mẫu từ mỗi bài để tính Jaccard. */
const WA_DUP_SHINGLE_SIZE = 3;

/** Số n-gram mẫu tối đa cho mỗi bài (giới hạn bộ nhớ). */
const WA_DUP_MAX_SHINGLES = 600;

/** Ngưỡng Jaccard để coi là "gần giống". */
const WA_DUP_SIMILAR_THRESHOLD = 0.55;

/** Ngưỡng Jaccard để cảnh báo mạnh. */
const WA_DUP_WARN_THRESHOLD = 0.75;

/**
 * Chuẩn hoá nội dung để so sánh: bỏ thẻ -> giải thực thể -> gộp khoảng trắng.
 */
function wa_dup_normalize_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Tập n-gram (3 từ liên tiếp) của một văn bản, đã bỏ dấu và hạ chữ thường.
 *
 * Dùng để đo Jaccard: hai văn bản giống nhau sẽ có phần lớn n-gram trùng.
 *
 * @return array<string,true>
 */
function wa_dup_shingles(string $text): array
{
    $tokens = array_keys(wa_search_tokenize(wa_dup_normalize_text($text)));
    $folded = [];

    foreach ($tokens as $token) {
        $f = wa_search_fold((string) $token);

        if ($f !== '') {
            $folded[] = $f;
        }
    }

    $count = count($folded);

    if ($count < WA_DUP_SHINGLE_SIZE) {
        return $folded === [] ? [] : [implode(' ', $folded) => true];
    }

    $shingles = [];

    for ($i = 0; $i + WA_DUP_SHINGLE_SIZE - 1 < $count; $i++) {
        $shingles[implode(' ', array_slice($folded, $i, WA_DUP_SHINGLE_SIZE))] = true;

        if (count($shingles) >= WA_DUP_MAX_SHINGLES) {
            break;
        }
    }

    return $shingles;
}

/**
 * Jaccard giữa hai tập n-gram.
 */
function wa_dup_jaccard(array $a, array $b): float
{
    if ($a === [] || $b === []) {
        return 0.0;
    }

    $inter = count(array_intersect_key($a, $b));
    $union = count($a) + count($b) - $inter;

    return $union > 0 ? $inter / $union : 0.0;
}

/**
 * Tìm các bài ĐÃ LƯU có URL TRÙNG (sau khi chuẩn hoá) với một URL nguồn.
 *
 * Đây là phần "trùng URL" của `wa_dup_detect()` được tách ra để nơi khác
 * (lưu hàng loạt) dùng LẠI đúng một bộ so sánh — KHÔNG viết bộ phát hiện
 * trùng thứ hai.
 *
 * @param int $excludePageId Bài cần loại trừ (bài đang được lưu thêm phiên bản).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_dup_url_matches(
    string $sourceUrl,
    int $libraryId = 0,
    ?array $actor = null,
    int $excludePageId = 0
): array {
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return [];
    }

    $normUrl = wa_bulk_normalize_url($sourceUrl);

    if ($normUrl === '') {
        return [];
    }

    $out     = [];
    $isAdmin = (string) ($actor['role'] ?? '') === 'admin';

    try {
        $stmt = db()->prepare(
            'SELECT sp.id, sp.title, sp.source_url, sp.library_id, l.name AS library_name,
                    (SELECT MAX(s.id) FROM `snapshots` s
                      WHERE s.page_id = sp.id AND s.fetch_status = \'success\') AS snapshot_id
               FROM `saved_pages` sp
               JOIN `libraries` l ON l.id = sp.library_id
              WHERE ' . ($isAdmin ? '1 = 1' : '(
                    sp.library_id IN (
                      SELECT id FROM `libraries` l2
                       WHERE l2.owner_id = :owner
                          OR EXISTS (SELECT 1 FROM `library_members` lm
                                      WHERE lm.library_id = l2.id AND lm.user_id = :member))
                     OR EXISTS (SELECT 1 FROM `library_members` lm2
                                 WHERE lm2.library_id = sp.library_id AND lm2.user_id = :member2)
                  )') . '
              LIMIT 500'
        );
        // Prepared statement THẬT: nếu câu không dùng tham số (quản trị)
        // thì KHÔNG được truyền mảng tham số rỗng/khác.
        $stmt->execute($isAdmin ? [] : [
            ':owner'   => (int) $actor['id'],
            ':member'  => (int) $actor['id'],
            ':member2' => (int) $actor['id'],
        ]);

        foreach ($stmt->fetchAll() as $row) {
            if ($excludePageId > 0 && (int) $row['id'] === $excludePageId) {
                // Chính bài này (phiên bản mới đang lưu) — không phải bài trùng.
                continue;
            }

            if (wa_bulk_normalize_url((string) $row['source_url']) !== $normUrl) {
                continue;
            }

            $out[] = [
                'page_id'      => (int) $row['id'],
                'title'        => (string) $row['title'],
                'source_url'   => (string) $row['source_url'],
                'library_id'   => (int) $row['library_id'],
                'library_name' => (string) $row['library_name'],
                'snapshot_id'  => (int) ($row['snapshot_id'] ?? 0),
                'same_library' => ((int) $row['library_id']) === $libraryId,
            ];
        }
    } catch (Throwable $e) {
        error_log('[WebArchive] dup url scan failed: ' . $e->getMessage());
    }

    return $out;
}

/**
 * Phát hiện bài trùng / gần giống với một nội dung cho trước.
 *
 * @param string $contentHtml Nội dung HTML sắp lưu (đã tải về).
 * @param string $sourceUrl   URL nguồn (để so trùng URL).
 * @param int    $libraryId   Thư viện đích (để loại trừ bài cùng URL trong cùng thư viện).
 * @param array|null $actor   Người dùng hiện tại (giới hạn phạm vi đọc).
 * @param int    $excludePageId Id của bài đang được lưu thêm phiên bản (nếu có) —
 *                               các phiên bản V1/V2/V3 của CHÍNH bài này KHÔNG được
 *                               coi là bài trùng độc lập.
 *
 * @return array{
 *   url_matches:array<int,array<string,mixed>>,
 *   exact_matches:array<int,array<string,mixed>>,
 *   similar_matches:array<int,array<string,mixed>>,
 *   scanned:int, has_any:bool
 * }
 */
function wa_dup_detect(
    string $contentHtml,
    string $sourceUrl,
    int $libraryId = 0,
    ?array $actor = null,
    int $excludePageId = 0
): array {
    $out = [
        'url_matches'    => [],
        'exact_matches' => [],
        'similar_matches' => [],
        'scanned'        => 0,
        'has_any'        => false,
    ];

    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return $out;
    }

    $text = wa_dup_normalize_text(wa_capture_clean_text(wa_capture_text_only($contentHtml)));

    if ($text === '') {
        return $out;
    }

    $hash      = hash('sha256', $text);
    $normUrl   = wa_bulk_normalize_url($sourceUrl);
    $textLen   = mb_strlen($text, 'UTF-8');

    // BUG: $isAdmin chưa được định nghĩa trong hàm này -> PHP Warning
    // "Undefined variable $isAdmin" và câu SQL sinh ra sai.
    $isAdmin = (string) ($actor['role'] ?? '') === 'admin';

    /* ---------------------------------------------------------------------
     * 1) TRÙNG URL (sau chuẩn hoá) — so trong các thư viện người dùng đọc.
     *     Dùng lại đúng một hàm so sánh (wa_dup_url_matches) để lưu hàng loạt
     *     cũng dùng chung, không có bộ phát hiện trùng thứ hai.
     * ------------------------------------------------------------------ */
    $out['url_matches'] = wa_dup_url_matches($sourceUrl, $libraryId, $actor, $excludePageId);

    /* ---------------------------------------------------------------------
     * 2) TRÙNG NỘI DUNG CHÍNH XÁC — SHA-256 đã lưu trong snapshots.content_hash.
     *    Chỉ lấy bản THÀNH CÔNG mới nhất của mỗi bài.
     * ------------------------------------------------------------------ */
    try {
        $stmt = db()->prepare(
            'SELECT s.page_id, s.id AS snapshot_id, s.content_hash, s.title,
                    sp.library_id, l.name AS library_name, sp.source_url
               FROM `snapshots` s
               JOIN `saved_pages` sp ON sp.id = s.page_id
               JOIN `libraries` l    ON l.id = sp.library_id
              WHERE s.content_hash = :hash
                AND s.fetch_status = \'success\'
                AND ' . ($isAdmin ? '1 = 1' : '(
                        sp.library_id IN (
                          SELECT id FROM `libraries` l2
                           WHERE l2.owner_id = :owner
                              OR EXISTS (SELECT 1 FROM `library_members` lm
                                          WHERE lm.library_id = l2.id AND lm.user_id = :member))
                         OR EXISTS (SELECT 1 FROM `library_members` lm2
                                     WHERE lm2.library_id = sp.library_id AND lm2.user_id = :member2)
                      )') . '
              LIMIT 50'
        );
        $stmt->execute($isAdmin ? [':hash' => $hash] : [
            ':hash'    => $hash,
            ':owner'   => (int) $actor['id'],
            ':member'  => (int) $actor['id'],
            ':member2' => (int) $actor['id'],
        ]);

        foreach ($stmt->fetchAll() as $row) {
            if ($excludePageId > 0 && (int) $row['page_id'] === $excludePageId) {
                // Các phiên bản V1/V2/V3 của CHÍNH bài này không phải bài trùng.
                continue;
            }

            $out['exact_matches'][] = [
                'page_id'      => (int) $row['page_id'],
                'snapshot_id'  => (int) $row['snapshot_id'],
                'title'        => (string) $row['title'],
                'source_url'   => (string) $row['source_url'],
                'library_id'   => (int) $row['library_id'],
                'library_name' => (string) $row['library_name'],
                'score'        => 100,
                'reason'       => 'Trùng nội dung 100% — SHA-256 giống hệt bản đã lưu.',
            ];
        }
    } catch (Throwable $e) {
        error_log('[WebArchive] dup hash scan failed: ' . $e->getMessage());
    }

    /* ---------------------------------------------------------------------
     * 3) GẦN GIỐNG — lọc trước theo độ dài, rồi đo Jaccard trên n-gram.
     * ------------------------------------------------------------------ */
    $candidates = wa_dup_candidates($textLen, $actor, $excludePageId);

    $out['scanned'] = count($candidates);

    if ($candidates !== []) {
        $mine = wa_dup_shingles($text);

        foreach ($candidates as $cand) {
            $other = wa_dup_shingles((string) $cand['content_text']);

            if ($other === []) {
                continue;
            }

            $score = wa_dup_jaccard($mine, $other);

            if ($score < WA_DUP_SIMILAR_THRESHOLD) {
                continue;
            }

            $out['similar_matches'][] = [
                'page_id'      => (int) $cand['page_id'],
                'snapshot_id'  => (int) $cand['snapshot_id'],
                'title'        => (string) $cand['title'],
                'source_url'   => (string) $cand['source_url'],
                'library_id'   => (int) $cand['library_id'],
                'library_name' => (string) $cand['library_name'],
                'score'        => (int) round($score * 100),
                'reason'       => 'Ước lượng gần giống ~' . (int) round($score * 100)
                                 . '% nội dung văn bản (so sánh n-gram 3 từ).',
            ];
        }

        usort(
            $out['similar_matches'],
            static fn (array $a, array $b): int => $b['score'] <=> $a['score']
        );
    }

    $out['has_any'] = $out['url_matches'] !== []
        || $out['exact_matches'] !== []
        || $out['similar_matches'] !== [];

    return $out;
}

/**
 * Ứng viên để so sánh gần giống: bản THÀNH CÔNG MỚI NHẤT của mỗi bài trong
 * các thư viện người dùng đọc, LỌC theo độ dài văn bản gần bằng nhau.
 *
 * @param int $excludePageId Bài cần loại trừ (bài đang được lưu thêm phiên bản).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_dup_candidates(int $textLen, ?array $actor = null, int $excludePageId = 0): array
{
    if ($actor === null || $textLen <= 0) {
        return [];
    }

    // Chỉ xét bài có độ dài trong khoảng 40%–250% so với bài đang lưu.
    $minLen = (int) max(200, $textLen * 0.4);
    $maxLen = (int) min(2000000, $textLen * 2.5);

    $isAdmin = (string) ($actor['role'] ?? '') === 'admin';

    try {
        $stmt = db()->prepare(
            'SELECT sp.id AS page_id, sp.title, sp.source_url, sp.library_id,
                    l.name AS library_name, s.id AS snapshot_id, s.content_text
               FROM `saved_pages` sp
               JOIN `libraries` l ON l.id = sp.library_id
               JOIN `snapshots` s ON s.id = (
                    SELECT MAX(s2.id) FROM `snapshots` s2
                     WHERE s2.page_id = sp.id AND s2.fetch_status = \'success\')
              WHERE s.fetch_status = \'success\'
                AND CHAR_LENGTH(s.content_text) BETWEEN :min_len AND :max_len
                AND ' . ($isAdmin ? '1 = 1' : '(
                        sp.library_id IN (
                          SELECT id FROM `libraries` l2
                           WHERE l2.owner_id = :owner
                              OR EXISTS (SELECT 1 FROM `library_members` lm
                                          WHERE lm.library_id = l2.id AND lm.user_id = :member))
                         OR EXISTS (SELECT 1 FROM `library_members` lm2
                                     WHERE lm2.library_id = sp.library_id AND lm2.user_id = :member2)
                      )') . '
                AND sp.id <> :exclude_page
              ORDER BY s.fetched_at DESC
              LIMIT ' . WA_DUP_MAX_CANDIDATES
        );
        $stmt->execute($isAdmin ? [
            ':min_len' => $minLen,
            ':max_len' => $maxLen,
            ':exclude_page' => $excludePageId > 0 ? $excludePageId : 0,
        ] : [
            ':min_len' => $minLen,
            ':max_len' => $maxLen,
            ':owner'   => (int) $actor['id'],
            ':member'  => (int) $actor['id'],
            ':member2' => (int) $actor['id'],
            ':exclude_page' => $excludePageId > 0 ? $excludePageId : 0,
        ]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[WebArchive] dup candidates failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Tổng hợp kết quả thành danh sách hiển thị (bỏ trùng theo page_id).
 *
 * @param array<string,mixed> $detect Kết quả từ wa_dup_detect().
 *
 * @return array<int, array<string,mixed>>
 */
function wa_dup_merge_report(array $detect): array
{
    $merged = [];
    $seen   = [];

    foreach ($detect['exact_matches'] as $row) {
        $key = 'p' . (int) $row['page_id'];

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[]   = array_merge($row, ['level' => 'exact', 'level_label' => 'Trùng hoàn toàn']);
    }

    foreach ($detect['url_matches'] as $row) {
        $key = 'p' . (int) $row['page_id'];

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[]   = array_merge($row, [
            'level'      => 'url',
            'level_label' => 'Trùng URL',
            'score'      => 100,
            'reason'     => 'URL sau khi chuẩn hoá trùng với một bài đã lưu trong thư viện bạn có quyền đọc.',
        ]);
    }

    foreach ($detect['similar_matches'] as $row) {
        $key = 'p' . (int) $row['page_id'];

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[]   = array_merge($row, [
            'level'      => 'similar',
            'level_label' => 'Gần giống',
        ]);
    }

    return $merged;
}

/**
 * Bản tóm tắt một kết quả phát hiện để đưa vào thông báo / UI.
 */
function wa_dup_report_summary(array $report): string
{
    if ($report === []) {
        return '';
    }

    $parts = [];

    foreach ($report as $row) {
        $score = (int) ($row['score'] ?? 0);

        $parts[] = sprintf(
            '%s — %s (%d%%)%s',
            (string) ($row['title'] ?? 'Không rõ tên'),
            (string) ($row['level_label'] ?? 'Trùng'),
            $score,
            (string) ($row['library_name'] ?? '') !== ''
                ? ' · ' . (string) $row['library_name']
                : ''
        );
    }

    return implode("\n", $parts);
}

/**
 * Cache nội dung tải sẵn trong CÙNG một request (dùng khi không có phiên,
 * ví dụ API của Chrome Extension). Kết hợp với cache SESSION khi có phiên.
 *
 * @var array<string, array{ts:int, content_html:string, result:array<string,mixed>}>
 */
$GLOBALS['wa_dup_prefetch_static'] = [];

/**
 * Lấy nội dung đã tải sẵn từ cache phiên (không tải lại mạng).
 *
 * @return array{ts:int, content_html:string, result:array<string,mixed>}|null
 */
function wa_dup_prefetch_get(string $url): ?array
{
    $url = trim($url);

    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
        // Không có phiên (API Extension): dùng cache trong request.
        $static = $GLOBALS['wa_dup_prefetch_static'] ?? [];
        $cached = $static[md5($url)] ?? null;

        if (!is_array($cached)) {
            return null;
        }

        $age = time() - (int) ($cached['ts'] ?? 0);

        return ($age < 0 || $age >= 600) ? null : $cached;
    }

    $key = 'wa_dup_prefetch_' . md5($url);

    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return null;
    }

    $cached = $_SESSION[$key];
    $age    = time() - (int) ($cached['ts'] ?? 0);

    if ($age < 0 || $age >= 600) {
        return null;
    }

    return $cached;
}

/**
 * Tải TRƯỚC nội dung một URL (một lần) để kiểm tra trùng lặp TRƯỚC khi lưu.
 *
 * Kết quả được cache trong SESSION trong 10 phút, nên khi người dùng bấm
 * "VẪN LƯU" ở bước xác nhận, trang KHÔNG tải lại URL lần thứ hai.
 *
 * @return array{ok:bool, content_html:string, result:array<string,mixed>|null, message:string}
 */
function wa_dup_prefetch_content(string $url, ?array $actor = null): array
{
    $url = trim($url);

    if ($url === '') {
        return ['ok' => false, 'content_html' => '', 'result' => null, 'message' => 'Thiếu URL.'];
    }

    $cached = wa_dup_prefetch_get($url);

    if ($cached !== null) {
        return [
            'ok'           => true,
            'content_html' => (string) ($cached['content_html'] ?? ''),
            'result'       => is_array($cached['result'] ?? null) ? $cached['result'] : null,
            'message'      => '',
        ];
    }

    $result = capture_url($url);

    if (($result['ok'] ?? false) !== true) {
        wa_dup_prefetch_clear($url);

        return [
            'ok'           => false,
            'content_html' => '',
            'result'       => null,
            'message'      => (string) ($result['error_message'] ?? 'Không tải được trang.'),
        ];
    }

    $contentHtml = (string) ($result['content_html'] ?? '');

    // Chỉ cache khi nội dung không quá lớn để tránh phình session.
    if (mb_strlen($contentHtml, 'UTF-8') <= 400000) {
        $entry = [
            'ts'           => time(),
            'content_html' => $contentHtml,
            'result'       => $result,
        ];

        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION)) {
            $_SESSION['wa_dup_prefetch_' . md5($url)] = $entry;
        } else {
            $GLOBALS['wa_dup_prefetch_static'][md5($url)] = $entry;
        }
    }

    return [
        'ok'           => true,
        'content_html' => $contentHtml,
        'result'       => $result,
        'message'      => '',
    ];
}

/**
 * Xoá cache prefetch của một URL (sau khi đã lưu xong).
 */
function wa_dup_prefetch_clear(string $url): void
{
    $url = trim($url);

    unset($GLOBALS['wa_dup_prefetch_static'][md5($url)]);

    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
        return;
    }

    unset($_SESSION['wa_dup_prefetch_' . md5($url)]);
}

/**
 * Kiểm tra trùng lặp cho một URL SẮP LƯU, dùng nội dung đã tải sẵn.
 *
 * @param int $excludePageId Bài đang được tạo phiên bản mới (loại trừ chính nó).
 *
 * @return array{report:array<int,array<string,mixed>>, scanned:int, has_any:bool}
 */
function wa_dup_check_url(
    string $url,
    string $contentHtml,
    int $libraryId = 0,
    int $excludePageId = 0,
    ?array $actor = null
): array {
    $detect = wa_dup_detect($contentHtml, $url, $libraryId, $actor, $excludePageId);

    return [
        'report'  => wa_dup_merge_report($detect),
        'scanned' => (int) $detect['scanned'],
        'has_any' => (bool) $detect['has_any'],
    ];
}
