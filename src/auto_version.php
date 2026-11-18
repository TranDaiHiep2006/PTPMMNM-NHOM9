<?php
/**
 * src/auto_version.php
 * -----------------------------------------------------------------------------
 * AUTO VERSION — tự kiểm tra & tự tạo phiên bản mới (V2, V3, V4…) khi
 * người dùng đang mở ứng dụng.
 *
 * BA NGUYÊN TẮC BẮT BUỘC
 * -----------------------------------------------------------------------------
 * 1. **XIN PHÉP TRƯỚC, KHÔNG GỬI GÌ RA NGOÀI.** Trước khi kết nối tới website
 *    nguồn, ứng dụng phải hiện yêu cầu xin phép rõ ràng. Chưa được đồng ý thì
 *    KHÔNG có bất kỳ request nào ra ngoài — kể cả request "vô hại" (ví dụ HEAD).
 *    Sự đồng ý được ghi trong phiên (session) nên chỉ cần đồng ý MỘT LẦN cho
 *    phiên làm việc hiện tại.
 *
 * 2. **KHÔNG TẠO BẢN TRÙNG.** Chỉ tạo phiên bản mới khi nội dung thật sự khác
 *    bản thành công MỚI NHẤT. Kiểm tra lại lần nữa mà nội dung không đổi thì
 *    KHÔNG sinh thêm dòng snapshot nào.
 *
 * 3. **CHỐNG CHẠY TRÙNG / RACE CONDITION.** Nếu hai request đến cùng lúc, chỉ
 *    một request được quyền tải trang gốc và ghi phiên bản. Request còn lại thấy
 *    khoá còn "mới" thì bỏ qua (không tạo bản trùng).
 *
 * Bản lịch sử và ghi chú của phiên bản cũ KHÔNG bị đụng tới: đường tự động dùng
 * CHUNG hàm `wa_store_page_capture()` — hàm này chỉ INSERT thêm một dòng
 * `snapshots` mới, không sửa/xoá bản cũ.
 *
 * Allowlist, kiểm tra redirect và các quy tắc chống SSRF được giữ nguyên vì
 * mọi lần tải trang gốc đều đi qua `capture_url()` — hàm đã kiểm allowlist
 * TRƯỚC mọi byte và kiểm LẠI ở mỗi bước chuyển hướng.
 *
 * KHÔNG có cron, Task Scheduler hay daemon: mọi hoạt động chỉ chạy khi người
 * dùng đang mở ứng dụng, thông qua một request HTTP thật.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/diff.php';

/** Khoá phiên: tên cờ trong $_SESSION. */
const WA_AUTO_CONSENT_KEY = 'wa_auto_version_consent';

/** Thời gian tối đa một lần giữ khoá kiểm tra (giây). */
const WA_AUTO_LOCK_TTL = 300;

/** Số bài tối đa kiểm tra trong MỘT lần mở ứng dụng. */
const WA_AUTO_MAX_PAGES_PER_RUN = 5;

/** Khoảng cách tối thiểu giữa hai lần kiểm tra tự động cùng một bài (giây). */
const WA_AUTO_MIN_INTERVAL = 900;

/* =============================================================================
    PHẦN 1 — SỰ ĐỒNG Ý (XIN PHÉP)
    ============================================================================= */

/**
 * Người dùng đã đồng ý cho phép kiểm tra tự động trong phiên này chưa?
 */
function wa_auto_consent_given(): bool
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return false;
    }

    return ($_SESSION[WA_AUTO_CONSENT_KEY] ?? '') === 'yes';
}

/**
 * Ghi nhận sự đồng ý của người dùng cho phiên hiện tại.
 */
function wa_auto_consent_grant(): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return;
    }

    $_SESSION[WA_AUTO_CONSENT_KEY] = 'yes';
}

/**
 * Thu hồi sự đồng ý (người dùng bấm "Tạm dừng kiểm tra").
 */
function wa_auto_consent_revoke(): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return;
    }

    unset($_SESSION[WA_AUTO_CONSENT_KEY]);
}

/* =============================================================================
    PHẦN 2 — KHOÁ CHỐNG CHẠY TRÙNG
    ============================================================================= */

/**
 * Thử giành khoá kiểm tra cho một bài viết.
 *
 * Dùng một câu UPDATE có điều kiện: chỉ khi khoá cũ đã cũ (quá TTL) hoặc chưa
 * có khoá thì mới ghi được. Vì vậy hai request đồng thời sẽ có đúng MỘT
 * request thắng — thao tác này là nguyên tử ở mức hàng của InnoDB.
 *
 * @return string|null Token khoá nếu thắng, null nếu khoá còn hiệu lực.
 */
function wa_auto_lock_acquire(int $pageId): ?string
{
    $token = bin2hex(random_bytes(16));

    try {
        $stmt = db()->prepare(
            'UPDATE `saved_pages`
                SET `auto_locked_at` = NOW(), `auto_lock_token` = ?
              WHERE `id` = ?
                AND (`auto_locked_at` IS NULL
                     OR `auto_locked_at` < (NOW() - INTERVAL ? SECOND))
                AND (`auto_lock_token` IS NULL
                     OR `auto_lock_token` <> ?)'
        );
        $stmt->execute([$token, $pageId, WA_AUTO_LOCK_TTL, $token]);

        // rowCount = 0 nghĩa là có tiến trình khác đang giữ khoá.
        if ($stmt->rowCount() === 0) {
            return null;
        }
    } catch (Throwable $e) {
        // Cột chưa migrate: không khoá được thì KHÔNG được tải trang gốc
        // (an toàn hơn là tải rồi ghi trùng).
        error_log('[WebArchive] auto lock failed: ' . $e->getMessage());
        return null;
    }

    return $token;
}

/**
 * Nhả khoá kiểm tra (chỉ tiến trình đang giữ token mới được nhả).
 */
function wa_auto_lock_release(int $pageId, string $token): void
{
    try {
        db()->prepare(
            'UPDATE `saved_pages`
                SET `auto_locked_at` = NULL, `auto_lock_token` = NULL
              WHERE `id` = ? AND `auto_lock_token` = ?'
        )->execute([$pageId, $token]);
    } catch (Throwable) {
        // Bỏ qua: khoá sẽ tự hết hạn sau TTL.
    }
}

/* =============================================================================
    PHẦN 3 — NỘI DUNG KHÔNG ĐỔI / ĐÃ ĐỔI
    ============================================================================= */

/**
 * So nội dung của một bản lưu với nội dung vừa tải từ trang gốc.
 *
 * Mốc so sánh LUÔN là bản thành công MỚI NHẤT (không phải bản đang mở).
 *
 * @param array<string,mixed> $saved  Dòng snapshots của bản thành công mới nhất.
 * @param array<string,mixed> $live   Kết quả capture_url().
 */
function wa_auto_content_changed(array $saved, array $live): bool
{
    $savedUnits = wa_diff_units(
        (string) ($saved['content_html'] ?? ''),
        (string) ($saved['content_text'] ?? '')
    );
    $liveUnits = wa_diff_units(
        (string) ($live['content_html'] ?? ''),
        (string) ($live['content_text'] ?? '')
    );

    $ops = wa_diff_ops($savedUnits, $liveUnits);

    if (wa_diff_changed($ops)) {
        return true;
    }

    return (string) ($saved['title'] ?? '') !== (string) ($live['title'] ?? '');
}

/* =============================================================================
    PHẦN 4 — DANH SÁCH BÀI ĐỦ ĐIỀU KIỆN
    ============================================================================= */

/**
 * Các bài viết mà người dùng này được phép kiểm tra tự động.
 *
 * Chỉ gồm bài mà người dùng CÓ QUYỀN GHI (chủ sở hữu / biên tập viên /
 * quản trị viên) — người xem không bao giờ nằm trong danh sách này nên không
 * thể kích hoạt việc tải trang gốc.
 *
 * @return array<int, array<string,mixed>>
 */
function wa_auto_candidate_pages(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return [];
    }

    try {
        if ($actor['role'] === 'admin') {
            $stmt = db()->prepare(
                "SELECT sp.id, sp.library_id, sp.source_url, sp.title
                   FROM `saved_pages` sp
                   JOIN `snapshots` s ON s.page_id = sp.id AND s.fetch_status = 'success'
                  GROUP BY sp.id
                  ORDER BY sp.id ASC"
            );
            $stmt->execute();
        } else {
            $stmt = db()->prepare(
                "SELECT sp.id, sp.library_id, sp.source_url, sp.title
                   FROM `saved_pages` sp
                   JOIN `snapshots` s ON s.page_id = sp.id AND s.fetch_status = 'success'
                   JOIN `libraries` l ON l.id = sp.library_id
              LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
                  WHERE (l.owner_id = :actor_owner OR lm.role IN ('owner', 'editor'))
              GROUP BY sp.id
                  ORDER BY sp.id ASC"
            );
            $stmt->execute([':actor' => (int) $actor['id'], ':actor_owner' => (int) $actor['id']]);
        }

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[WebArchive] auto candidates failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Bài này đã được kiểm tra tự động gần đây chưa?
 *
 * Khoảng cách tối thiểu giữa hai lần kiểm tra cùng một bài nhằm tránh tải lại
 * cùng một URL liên tục khi người dùng chuyển qua chuyển nhiều trang.
 */
function wa_auto_checked_recently(int $pageId): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT `auto_checked_at` FROM `saved_pages` WHERE `id` = ? LIMIT 1'
        );
        $stmt->execute([$pageId]);
        $row = $stmt->fetch();

        if ($row === false || $row['auto_checked_at'] === null) {
            return false;
        }

        $checkedAt = strtotime((string) $row['auto_checked_at']);

        if ($checkedAt === false) {
            return false;
        }

        return (time() - $checkedAt) < WA_AUTO_MIN_INTERVAL;
    } catch (Throwable) {
        // Cột chưa migrate: coi như chưa kiểm tra bao giờ.
        return false;
    }
}

/* =============================================================================
    PHẦN 5 — TỰ TẠO PHIÊN BẢN MỚI
    ============================================================================= */

/**
 * Tự tạo MỘT phiên bản mới cho bài viết khi nội dung nguồn đã thay đổi.
 *
 * Dùng CHUNG `wa_store_page_capture()` với save.php / refetch.php nên:
 *   - quy tắc đánh số phiên bản (V2, V3…) y hệt đường lưu thủ công;
 *   - lập chỉ mục tìm kiếm cho bản mới được tạo đúng như bình thường;
 *   - lịch sử + ghi chú của bản cũ KHÔNG bị đụng tới.
 *
 * `$prefetched` là kết quả tải trang gốc ĐÃ có sẵn từ bước so sánh — nhờ vậy
 * cùng một URL KHÔNG bị tải hai lần trong một lần kiểm tra.
 *
 * @return array{ok:bool, created:bool, version:int, snapshot_id:int, reason:string}
 */
function wa_auto_create_version(
    int $libraryId,
    string $sourceUrl,
    int $userId,
    ?array $prefetched = null
): array {
    // Bài đã lưu phải tồn tại — đường tự động chỉ tạo PHIÊN BẢN cho một bài
    // có sẵn, KHÔNG bao giờ tạo bài mới.
    $existing = wa_find_page_by_url($libraryId, $sourceUrl);

    if ($existing === null) {
        return [
            'ok'          => false,
            'created'     => false,
            'version'     => 0,
            'snapshot_id' => 0,
            'reason'      => 'page_not_found',
        ];
    }

    $result = wa_store_page_capture(
        $libraryId,
        $sourceUrl,
        wa_page_tags((int) $existing['id']),
        $userId,
        $prefetched
    );

    if (($result['ok'] ?? false) !== true) {
        return [
            'ok'          => false,
            'created'     => false,
            'version'     => 0,
            'snapshot_id' => 0,
            'reason'      => 'capture_failed:' . (string) ($result['error_code'] ?? 'unknown'),
        ];
    }

    return [
        'ok'          => true,
        'created'     => true,
        'version'     => (int) ($result['version'] ?? 0),
        'snapshot_id' => (int) ($result['snapshot_id'] ?? 0),
        'reason'      => 'created',
    ];
}

/* =============================================================================
    PHẦN 6 — KIỂM TRA MỘT BÀI (luồng chính)
    ============================================================================= */

/**
 * Kiểm tra MỘT bài viết: tải trang gốc, so sánh với bản mới nhất, và nếu
 * nội dung đổi thì tự tạo phiên bản mới + ghi thông báo.
 *
 * @param array<string,mixed> $page Dòng saved_pages (id, library_id, source_url).
 * @return array<string,mixed> Kết quả kiểm tra (không nhạy cảm).
 */
function wa_auto_check_page(array $page, int $userId): array
{
    $pageId = (int) ($page['id'] ?? 0);

    if ($pageId <= 0) {
        return ['status' => 'invalid'];
    }

    // --- Chống chạy trùng: giành khoá trước khi gọi mạng. ---
    $token = wa_auto_lock_acquire($pageId);

    if ($token === null) {
        return ['status' => 'locked'];
    }

    try {
        // --- Mốc so sánh: bản thành công MỚI NHẤT. ---
        $versions = wa_page_versions($pageId, 0);
        $latest   = $versions[0] ?? null;

        if ($latest === null) {
            wa_auto_mark_checked($pageId);
            return ['status' => 'no_version'];
        }

        $latestId = (int) $latest['id'];

        $stmt = db()->prepare(
            "SELECT title, content_html, content_text
               FROM `snapshots`
              WHERE id = ? AND fetch_status = 'success'
              LIMIT 1"
        );
        $stmt->execute([$latestId]);
        $saved = $stmt->fetch();

        if ($saved === false) {
            wa_auto_mark_checked($pageId);
            return ['status' => 'no_version'];
        }

        // --- Tải trang gốc (allowlist + chống SSRF do capture_url() lo). ---
        $live = capture_url((string) $page['source_url']);

        if (($live['ok'] ?? false) !== true) {
            wa_auto_mark_checked($pageId);
            return [
                'status'      => 'fetch_failed',
                'error_code'  => (string) ($live['error_code'] ?? 'unknown'),
            ];
        }

        $changed = wa_auto_content_changed($saved, $live);

        if (!$changed) {
            // Nội dung KHÔNG đổi -> KHÔNG tạo bản trùng. Chỉ ghi cờ.
            wa_auto_mark_checked($pageId);
            wa_page_check_mark($pageId, false);

            return [
                'status'  => 'unchanged',
                'changed' => false,
            ];
        }

        // --- Nội dung ĐÃ đổi -> tự tạo đúng MỘT phiên bản mới. ---
        // Dùng lại $live (đã tải sẵn) — KHÔNG tải cùng URL hai lần.
        $created = wa_auto_create_version(
            (int) $page['library_id'],
            (string) $page['source_url'],
            $userId,
            $live
        );

        if ($created['created'] !== true) {
            wa_auto_mark_checked($pageId);
            wa_page_check_mark($pageId, true);

            return [
                'status'     => 'create_failed',
                'error_code' => (string) $created['reason'],
                'changed'    => true,
            ];
        }

        // Ghi cờ + số phiên bản cuối cùng do kiểm tra tự động tạo ra.
        wa_auto_mark_checked($pageId, (int) $created['version']);

        // Bản mới đã lưu -> cờ "có bản mới" phải xoá (giống refetch.php).
        wa_page_check_mark($pageId, false);

        // Ghi nhật kỹ + phát thông báo cho những người được quyền truy cập.
        wa_auto_record_event($pageId, $latestId, (int) $created['snapshot_id'], (int) $created['version'], $userId);
        wa_auto_notify_version(
            (int) $page['library_id'],
            $pageId,
            (int) $created['snapshot_id'],
            (int) $created['version'],
            (string) ($page['title'] ?? ''),
            (string) ($page['source_url'] ?? ''),
            $latestId
        );

        return [
            'status'      => 'updated',
            'changed'     => true,
            'version'     => (int) $created['version'],
            'snapshot_id' => (int) $created['snapshot_id'],
        ];
    } finally {
        // Luôn nhả khoá, kể cả khi có ngoại lệ.
        wa_auto_lock_release($pageId, $token);
    }
}

/**
 * Ghi thời điểm kiểm tra tự động + số phiên bản cuối cùng do kiểm tra tự động
 * tạo ra.
 */
function wa_auto_mark_checked(int $pageId, int $autoVersionNo = 0): void
{
    try {
        db()->prepare(
            'UPDATE `saved_pages`
                SET `auto_checked_at` = NOW(),
                    `auto_version_no` = GREATEST(`auto_version_no`, ?)
              WHERE `id` = ?'
        )->execute([$autoVersionNo, $pageId]);
    } catch (Throwable $e) {
        error_log('[WebArchive] auto mark checked failed: ' . $e->getMessage());
    }
}

/**
 * Ghi nhật ký lần tạo phiên bản tự động.
 *
 * Khoá UNIQUE (page_id, version_no) là chốt chặn CUỐI CÙNG chống bản trùng:
 * ngay cả khi hai request cùng thắng khoá (không xảy ra với khoá theo hàng),
 * chỉ một request được chèn được vào bảng này.
 */
function wa_auto_record_event(
    int $pageId,
    int $baseSnapshotId,
    int $newSnapshotId,
    int $versionNo,
    int $actorId
): bool {
    try {
        db()->prepare(
            'INSERT INTO `auto_version_events`
                (`page_id`, `base_snapshot_id`, `new_snapshot_id`, `version_no`, `actor_id`)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$pageId, $baseSnapshotId, $newSnapshotId, $versionNo, $actorId]);

        return true;
    } catch (Throwable $e) {
        error_log('[WebArchive] auto version event failed: ' . $e->getMessage());
        return false;
    }
}
