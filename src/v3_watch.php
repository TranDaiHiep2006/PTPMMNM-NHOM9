<?php
/**
 * src/v3_watch.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 4 — THEO DÕI MỘT ĐOẠN NỘI DUNG (F10).
 *
 * Người dùng bôi đen một đoạn văn bản trong trình đọc rồi chọn "Theo dõi đoạn
 * này". Ta lưu:
 *   - đoạn đã chọn (quote),
 *   - ngữ cảnh hai bên (prefix/suffix) để định vị lại khi nội dung dịch chuyển,
 *   - vị trí trong khối (selector + start/end offset) theo snapshot nguồn.
 *
 * Khi Auto Version tạo phiên bản mới, hàm wa_watch_check_page() đối chiếu TỪNG
 * mục theo dõi của bài đó với nội dung mới:
 *   - đoạn vẫn còn nguyên            -> trạng thái 'ok'
 *   - đoạn bị sửa (khớp một phần)   -> trạng thái 'changed' + thông báo
 *   - không xác định được đoạn tương ứng -> trạng thái 'unknown'
 *     (KHÔNG tự khẳng định nội dung đã bị xoá)
 *
 * Nguyên tắc:
 *   - KHÔNG sửa content_html gốc của snapshot (giữ nguyên F4).
 *   - KHÔNG tải lại website: chỉ dùng nội dung snapshot MỚI đã có sẵn.
 *   - Mỗi thay đổi chỉ báo MỘT lần (khoá chống trùng theo user + mục + phiên bản).
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/diff.php';

/** Độ dài tối đa của đoạn được lưu. */
const WA_WATCH_QUOTE_MAX = 900;

/** Độ dài ngữ cảnh hai bên (ký tự). */
const WA_WATCH_CONTEXT = 32;

/** Ngưỡng Jaccard để coi là "đoạn đã bị sửa". */
const WA_WATCH_SIMILAR_THRESHOLD = 0.55;

/**
 * Chuẩn hoá văn bản để so sánh: gộp khoảng trắng + trim.
 */
function wa_watch_normalize(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Tập token đã bỏ dấu của một đoạn văn (dùng để so sánh gần giống).
 *
 * @return array<string,true>
 */
function wa_watch_tokens(string $text): array
{
    $tokens = [];

    foreach (array_keys(wa_search_tokenize(wa_watch_normalize($text))) as $token) {
        $folded = wa_search_fold((string) $token);

        if ($folded !== '') {
            $tokens[$folded] = true;
        }
    }

    return $tokens;
}

/**
 * Jaccard giữa hai tập token.
 */
function wa_watch_jaccard(array $a, array $b): float
{
    if ($a === [] || $b === []) {
        return 0.0;
    }

    $inter = count(array_intersect_key($a, $b));
    $union = count($a) + count($b) - $inter;

    return $union > 0 ? $inter / $union : 0.0;
}

/**
 * Tạo mục theo dõi mới cho một đoạn đã bôi đen trong snapshot.
 *
 * @param array<string,mixed> $anchor selector/start_offset/end_offset/quote/prefix/suffix
 *
 * @return array{ok:bool, message:string, id:int}
 */
function wa_watch_create(int $snapshotId, int $userId, array $anchor, ?array $actor = null): array
{
    if ($snapshotId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Thiếu thông tin mục theo dõi.', 'id' => 0];
    }

    $quote = wa_watch_normalize((string) ($anchor['quote'] ?? ''));

    if (mb_strlen($quote, 'UTF-8') < 3) {
        return ['ok' => false, 'message' => 'Đoạn văn bản được chọn quá ngắn để theo dõi.', 'id' => 0];
    }

    if (mb_strlen($quote, 'UTF-8') > WA_WATCH_QUOTE_MAX) {
        $quote = mb_substr($quote, 0, WA_WATCH_QUOTE_MAX, 'UTF-8');
    }

    // Snapshot phải tồn tại VÀ người dùng phải có quyền đọc.
    $snapshot = wa_find_snapshot($snapshotId, $actor);

    if ($snapshot === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy bản lưu này hoặc bạn không có quyền đọc.', 'id' => 0];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `content_watches`
                (`user_id`, `page_id`, `snapshot_id`, `selector`, `start_offset`, `end_offset`,
                 `quote`, `prefix`, `suffix`, `is_active`, `status`, `status_note`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'ok\', \'\')'
        );
        $stmt->execute([
            $userId,
            (int) ($snapshot['page_id'] ?? 0),
            $snapshotId,
            mb_substr((string) ($anchor['selector'] ?? ''), 0, 255, 'UTF-8'),
            max(0, (int) ($anchor['start_offset'] ?? 0)),
            max(0, (int) ($anchor['end_offset'] ?? 0)),
            $quote,
            mb_substr((string) ($anchor['prefix'] ?? ''), 0, 255, 'UTF-8'),
            mb_substr((string) ($anchor['suffix'] ?? ''), 0, 255, 'UTF-8'),
        ]);

        return [
            'ok'      => true,
            'message' => 'Đã theo dõi đoạn này. Khi bài viết có phiên bản mới, bạn sẽ được thông báo.',
            'id'      => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $e) {
        error_log('[WebArchive] watch create failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không lưu được mục theo dõi.', 'id' => 0];
    }
}

/**
 * Danh sách mục theo dõi của một bài viết (thuộc đúng tài khoản này).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_watch_list_for_page(int $pageId, int $userId): array
{
    if ($pageId <= 0 || $userId <= 0) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, page_id, snapshot_id, quote, prefix, suffix, is_active, status,
                    status_note, last_seen_quote, last_changed_at, created_at, updated_at
               FROM `content_watches`
              WHERE page_id = ? AND user_id = ?
              ORDER BY id DESC'
        );
        $stmt->execute([$pageId, $userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = wa_watch_format_row($row);
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] watch list failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Toàn bộ mục theo dõi của tài khoản (dùng cho trang quản lý).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_watch_list_for_user(int $userId, int $limit = 200): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT w.id, w.page_id, w.snapshot_id, w.quote, w.prefix, w.suffix, w.is_active,
                    w.status, w.status_note, w.last_seen_quote, w.last_changed_at,
                    w.created_at, w.updated_at,
                    sp.title AS page_title, sp.source_url, l.name AS library_name
               FROM `content_watches` w
               JOIN `saved_pages` sp ON sp.id = w.page_id
               JOIN `libraries` l    ON l.id = sp.library_id
              WHERE w.user_id = ?
              ORDER BY w.id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $formatted = wa_watch_format_row($row);
            $formatted['page_title']   = (string) ($row['page_title'] ?? '');
            $formatted['source_url']   = (string) ($row['source_url'] ?? '');
            $formatted['library_name'] = (string) ($row['library_name'] ?? '');
            $rows[] = $formatted;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] watch list user failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Chuẩn hoá một dòng CSDL của mục theo dõi.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function wa_watch_format_row(array $row): array
{
    $status = (string) ($row['status'] ?? 'ok');

    return [
        'id'             => (int) ($row['id'] ?? 0),
        'page_id'        => (int) ($row['page_id'] ?? 0),
        'snapshot_id'    => (int) ($row['snapshot_id'] ?? 0),
        'quote'          => (string) ($row['quote'] ?? ''),
        'prefix'         => (string) ($row['prefix'] ?? ''),
        'suffix'         => (string) ($row['suffix'] ?? ''),
        'is_active'      => ((int) ($row['is_active'] ?? 1)) === 1,
        'status'         => $status,
        'status_label'   => wa_watch_status_label($status),
        'status_note'    => (string) ($row['status_note'] ?? ''),
        'last_seen_quote' => (string) ($row['last_seen_quote'] ?? ''),
        'last_changed_at' => (string) ($row['last_changed_at'] ?? ''),
        'created_label'  => wa_datetime((string) ($row['created_at'] ?? '')),
    ];
}

/**
 * Nhãn tiếng Việt của trạng thái một mục theo dõi.
 */
function wa_watch_status_label(string $status): string
{
    switch ($status) {
        case 'changed':
            return 'Đã thay đổi';
        case 'unknown':
            return 'Không tìm thấy đoạn tương ứng';
        case 'paused':
            return 'Đang tạm dừng';
        default:
            return 'Đang theo dõi';
    }
}

/**
 * Bật / tắt một mục theo dõi (chỉ chủ sở hữu mục đó).
 */
function wa_watch_set_active(int $watchId, int $userId, bool $active): bool
{
    if ($watchId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'UPDATE `content_watches`
                SET `is_active` = ?, `status` = ?, `updated_at` = NOW()
              WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $active ? 'ok' : 'paused', $watchId, $userId]);

        return $stmt->rowCount() >= 0;
    } catch (Throwable $e) {
        error_log('[WebArchive] watch toggle failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Xoá một mục theo dõi (chỉ chủ sở hữu mục đó).
 */
function wa_watch_delete(int $watchId, int $userId): bool
{
    if ($watchId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare('DELETE FROM `content_watches` WHERE id = ? AND user_id = ?');
        $stmt->execute([$watchId, $userId]);

        return true;
    } catch (Throwable $e) {
        error_log('[WebArchive] watch delete failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Đối chiếu toàn bộ mục theo dõi của một bài với snapshot MỚI.
 *
 * @param int $oldSnapshotId Snapshot gốc (trước thay đổi) — 0 nếu không rõ.
 * @param int $newSnapshotId Snapshot mới (đã có sẵn trong CSDL).
 * @param int $versionNo    Số phiên bản mới.
 *
 * @return array{checked:int, changed:int, unknown:int}
 */
function wa_watch_check_page(
    int $pageId,
    int $oldSnapshotId,
    int $newSnapshotId,
    int $versionNo
): array {
    $result = ['checked' => 0, 'changed' => 0, 'unknown' => 0];

    if ($pageId <= 0 || $newSnapshotId <= 0) {
        return $result;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, user_id, quote, prefix, suffix
               FROM `content_watches`
              WHERE page_id = ? AND is_active = 1'
        );
        $stmt->execute([$pageId]);
        $watches = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[WebArchive] watch check fetch failed: ' . $e->getMessage());

        return $result;
    }

    if ($watches === []) {
        return $result;
    }

    // Nội dung mới: dùng CHUNG cho mọi mục theo dõi của bài (không tải lại web).
    $new = wa_watch_load_content($newSnapshotId);

    if ($new['text'] === '' && $new['blocks'] === []) {
        return $result;
    }

    $newTokens = wa_watch_tokens($new['text']);

    foreach ($watches as $watch) {
        $watchId = (int) $watch['id'];
        $userId  = (int) $watch['user_id'];
        $quote   = wa_watch_normalize((string) $watch['quote']);

        $result['checked']++;

        // 1) Đoạn vẫn còn nguyên trong bản mới?
        if ($quote !== '' && $new['text'] !== '' && mb_strpos($new['text'], $quote, 0, 'UTF-8') !== false) {
            wa_watch_update_state($watchId, 'ok', '', $quote);

            continue;
        }

        // 2) Tìm khối gần giống nhất trong bản mới.
        $quoteTokens = wa_watch_tokens($quote);
        $bestScore   = 0.0;
        $bestBlock   = '';

        foreach ($new['blocks'] as $block) {
            $score = wa_watch_jaccard($quoteTokens, wa_watch_tokens($block));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestBlock = $block;
            }
        }

        if ($bestScore >= WA_WATCH_SIMILAR_THRESHOLD && $bestBlock !== '') {
            $result['changed']++;

            wa_watch_update_state($watchId, 'changed', 'Đoạn đã thay đổi trong phiên bản m.', $bestBlock);
            wa_watch_notify(
                $userId,
                $pageId,
                $newSnapshotId,
                $versionNo,
                $watchId,
                $quote,
                $bestBlock
            );

            continue;
        }

        // 3) Không xác định được đoạn tương ứng -> KHÔNG kết luận "đã bị xoá".
        $result['unknown']++;

        wa_watch_update_state(
            $watchId,
            'unknown',
            'Không tìm thấy đoạn tương ứng trong phiên bản mới.',
            ''
        );

        wa_watch_notify(
            $userId,
            $pageId,
            $newSnapshotId,
            $versionNo,
            $watchId,
            $quote,
            ''
        );
    }

    return $result;
}

/**
 * Nạp nội dung của một snapshot (văn bản + danh sách khối để so khớp).
 *
 * @return array{text:string, blocks:array<int,string>}
 */
function wa_watch_load_content(int $snapshotId): array
{
    $empty = ['text' => '', 'blocks' => []];

    if ($snapshotId <= 0) {
        return $empty;
    }

    try {
        $stmt = db()->prepare(
            'SELECT content_html, content_text FROM `snapshots`
              WHERE id = ? AND fetch_status = \'success\' LIMIT 1'
        );
        $stmt->execute([$snapshotId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] watch load content failed: ' . $e->getMessage());

        return $empty;
    }

    if ($row === false) {
        return $empty;
    }

    $html = (string) ($row['content_html'] ?? '');
    $text = wa_watch_normalize((string) ($row['content_text'] ?? ''));

    $blocks = [];

    foreach (wa_diff_units($html, $text) as $block) {
        $block = wa_watch_normalize($block);

        if ($block !== '') {
            $blocks[] = $block;
        }
    }

    return ['text' => $text, 'blocks' => $blocks];
}

/**
 * Cập nhật trạng thái của một mục theo dõi.
 */
function wa_watch_update_state(int $watchId, string $status, string $note, string $seenQuote): void
{
    try {
        $stmt = db()->prepare(
            'UPDATE `content_watches`
                SET `status` = ?, `status_note` = ?, `last_seen_quote` = ?,
                    `last_changed_at` = NOW(), `updated_at` = NOW()
              WHERE id = ?'
        );
        $stmt->execute([
            $status,
            mb_substr($note, 0, 250, 'UTF-8'),
            $seenQuote !== '' ? mb_substr($seenQuote, 0, WA_WATCH_QUOTE_MAX, 'UTF-8') : null,
            $watchId,
        ]);
    } catch (Throwable $e) {
        error_log('[WebArchive] watch update state failed: ' . $e->getMessage());
    }
}

/**
 * Tạo thông báo cho một mục theo dõi bị thay đổi (hoặc không xác định được).
 *
 * Khoá chống trùng: watch + user + phiên bản -> mỗi thay đổi chỉ báo MỘT lần.
 */
function wa_watch_notify(
    int $userId,
    int $pageId,
    int $newSnapshotId,
    int $versionNo,
    int $watchId,
    string $oldQuote,
    string $newQuote
): void {
    if ($userId <= 0 || $pageId <= 0 || $newSnapshotId <= 0) {
        return;
    }

    $dedupe = 'watch:' . $userId . ':' . $watchId . ':' . $versionNo;

    if ($newQuote !== '') {
        $title = 'Đoạn nội dung bạn theo dõi đã được sửa';
        $body  = "Đoạn bạn theo dõi trong bài viết này đã thay đổi ở phiên bản V" . $versionNo . ".\n";
        $body .= '• Nội dung cũ: ' . wa_watch_excerpt($oldQuote);
        $body .= "\n" . '• Nội dung mới: ' . wa_watch_excerpt($newQuote);
    } else {
        $title = 'Không tìm thấy đoạn theo dõi trong phiên bản mới';
        $body  = "Khi kiểm tra phiên bản V" . $versionNo
            . ", hệ thống không xác định được đoạn bạn đang theo dõi.\n";
        $body .= '• Đoạn đang theo dõi: ' . wa_watch_excerpt($oldQuote);
        $body .= "\n• Lưu ý: đoạn này có thể đã được diễn đạt lại hoặc dời chỗ — hệ thống KHÔNG tự kết luận nội dung đã bị xoá.";
    }

    // Cột `notifications.body` chỉ chứa được 500 ký tự -> cắt bớt, KHÔNG làm
    // mất thông báo (nếu cắt ở giữa thì thêm dấu "…").
    if (mb_strlen($body, 'UTF-8') > 500) {
        $body = rtrim(mb_substr($body, 0, 497, 'UTF-8')) . '…';
    }

    wa_notify_create(
        $userId,
        $pageId,
        $newSnapshotId,
        'watch_changed',
        $title,
        $body,
        $dedupe
    );
}

/**
 * Rút gọn đoạn văn cho thông báo (giữ ranh giới từ).
 */
function wa_watch_excerpt(string $text, int $max = 180): string
{
    $text = wa_watch_normalize($text);

    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }

    $cut  = mb_substr($text, 0, $max, 'UTF-8');
    $pos  = mb_strrpos($cut, ' ', 0, 'UTF-8');

    if ($pos !== false && $pos > (int) ($max * 0.6)) {
        $cut = mb_substr($cut, 0, $pos, 'UTF-8');
    }

    return rtrim($cut) . '…';
}
