<?php
/**
 * src/notifications.php
 * -----------------------------------------------------------------------------
 * THÔNG BÁO PHIÊN BẢN MỚI — lưu bền vững trong CSDL.
 *
 * Khác với `wa_flash` (thông báo tạm trong phiên, mất khi chuyển trang), thông
 * báo ở đây:
 *   - tồn tại lâu dài, đọc lại được ở lần mở ứng dụng sau;
 *   - GẮN ĐÚNG tài khoản được phép truy cập bài viết;
 *   - có trạng thái đã đọc / chưa đọc;
 *   - KHÔNG tạo trùng (khoá UNIQUE `dedupe_key`).
 *
 * DEDUPE_KEY
 * -----------------------------------------------------------------------------
 * Khoá chống trùng có dạng:  `v:<user_id>:<page_id>:<version_no>`
 *
 * Nghĩa là: mỗi (người dùng, bài viết, số phiên bản) chỉ có tối đa MỘT
 * thông báo. Nếu cùng một phiên bản mới được phát lại (ví dụ người dùng bấm
 * kiểm tra lại) thì KHÔNG tạo thêm dòng thông báo trùng.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Loại thông báo. */
const WA_NOTIFY_VERSION_UPDATE = 'version_update';

/**
 * Tạo khoá chống trùng cho một thông báo phiên bản mới.
 */
function wa_notify_dedupe_key(int $userId, int $pageId, int $versionNo): string
{
    return 'v:' . $userId . ':' . $pageId . ':' . $versionNo;
}

/**
 * Tạo một thông báo. Trả về id của thông báo, hoặc null nếu trùng / lỗi.
 *
 * Dùng INSERT IGNORE + khoá UNIQUE để hai lần gọi song song không tạo ra hai
 * dòng trùng nhau.
 */
function wa_notify_create(
    int $userId,
    ?int $pageId,
    ?int $snapshotId,
    string $kind,
    string $title,
    string $body,
    string $dedupeKey
): ?int {
    try {
        $stmt = db()->prepare(
            'INSERT IGNORE INTO `notifications`
                (`user_id`, `page_id`, `snapshot_id`, `kind`, `title`, `body`, `dedupe_key`)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $pageId,
            $snapshotId,
            $kind,
            $title,
            $body,
            $dedupeKey,
        ]);

        $id = (int) db()->lastInsertId();

        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        error_log('[WebArchive] notify create failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Danh sách thông báo của một tài khoản (mới nhất trước).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_notify_list(int $userId, int $limit = 20, bool $unreadOnly = false): array
{
    try {
        $sql = 'SELECT n.id, n.user_id, n.page_id, n.snapshot_id, n.kind, n.title, n.body,
                       n.is_read, n.created_at, n.read_at,
                       sp.source_url, sp.title AS page_title, sp.library_id
                  FROM `notifications` n
             LEFT JOIN `saved_pages` sp ON sp.id = n.page_id
                 WHERE n.user_id = ?';

        if ($unreadOnly) {
            $sql .= ' AND n.is_read = 0';
        }

        $sql .= ' ORDER BY n.id DESC LIMIT ' . max(1, min(100, $limit));

        $stmt = db()->prepare($sql);
        $stmt->execute([$userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $snapshotId = $row['snapshot_id'] === null ? 0 : (int) $row['snapshot_id'];
            $pageId     = $row['page_id'] === null ? 0 : (int) $row['page_id'];
            $libraryId  = $row['library_id'] === null ? 0 : (int) $row['library_id'];

            // KIỂM TRA QUYỀN TẠI THỜI ĐIỂM HIỂN THỊ: người dùng có thể đã bị
            // gỡ khỏi thư viện sau khi nhận thông báo. Không được lộ liên kết
            // tới bài mà họ không còn quyền đọc.
            $canRead = $libraryId > 0 && wa_can_read_library($libraryId);

            $toVersion   = $canRead ? wa_notify_snapshot_version($snapshotId) : 0;
            $fromVersion = ($canRead && $toVersion > 0)
                ? wa_notify_previous_version($pageId, $snapshotId)
                : 0;

            $rows[] = [
                'id'          => (int) $row['id'],
                'page_id'     => $row['page_id'] === null ? null : (int) $row['page_id'],
                'snapshot_id' => $row['snapshot_id'] === null ? null : (int) $row['snapshot_id'],
                'kind'        => (string) $row['kind'],
                'title'       => (string) $row['title'],
                'body'        => (string) $row['body'],
                'is_read'     => ((int) $row['is_read']) === 1,
                'created_at'  => (string) $row['created_at'],
                'created_label' => wa_datetime((string) $row['created_at']),
                'source_url'  => (string) ($row['source_url'] ?? ''),
                'page_title'  => (string) ($row['page_title'] ?? ''),
                // Quyền đọc hiện tại (đã kiểm tra lúc hiển thị).
                'can_read'    => $canRead,
                // Số phiên bản MỚI và bản TRƯỚC đó (lấy từ bảng snapshots —
                // KHÔNG giả định "bản trước = bản mới - 1").
                'to_version'  => $toVersion,
                'from_version' => $fromVersion,
            ];
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] notify list failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Số phiên bản (version_no) của một snapshot. Trả 0 nếu không tìm thấy.
 */
function wa_notify_snapshot_version(int $snapshotId): int
{
    if ($snapshotId <= 0) {
        return 0;
    }

    try {
        $stmt = db()->prepare(
            'SELECT version_no FROM `snapshots` WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$snapshotId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[WebArchive] notify snapshot version failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Số phiên bản THÀNH CÔNG liền trước bản `snapshotId` của cùng một bài viết.
 *
 * Truy vấn bảng `snapshots` theo đúng thứ tự phiên bản — KHÔNG suy đoán
 * "bản trước = bản mới - 1" (bản có thể bị xoá hoặc lỗi).
 *
 * QUAN TRỌNG: chỉ lấy bản có `version_no` NHỎ HƠN bản đang thông báo. Nếu không
 * có điều kiện này, thông báo của V3 sẽ bị trỏ sang V4 (bản tạo ra sau) và
 * link so sánh sẽ sai.
 */
function wa_notify_previous_version(int $pageId, int $snapshotId): int
{
    if ($pageId <= 0 || $snapshotId <= 0) {
        return 0;
    }

    try {
        // Lấy version_no của CHÍNH bản đang thông báo.
        $stmt = db()->prepare(
            'SELECT version_no FROM `snapshots` WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$snapshotId]);
        $currentVersion = (int) $stmt->fetchColumn();

        if ($currentVersion <= 0) {
            return 0;
        }

        // Bản thành công gần nhất có version_no NHỎ HƠN bản hiện tại.
        $stmt = db()->prepare(
            'SELECT version_no
               FROM `snapshots`
              WHERE page_id = ?
                AND version_no < ?
                AND fetch_status = \'success\'
              ORDER BY version_no DESC
              LIMIT 1'
        );
        $stmt->execute([$pageId, $currentVersion]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[WebArchive] notify previous version failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Số thông báo chưa đọc của một tài khoản.
 */
function wa_notify_unread_count(int $userId): int
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = ? AND `is_read` = 0'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[WebArchive] notify count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Đánh dấu một thông báo là đã đọc (chỉ thông báo của chính tài khoản đó).
 */
function wa_notify_mark_read(int $notificationId, int $userId): bool
{
    try {
        $stmt = db()->prepare(
            'UPDATE `notifications`
                SET `is_read` = 1, `read_at` = NOW()
              WHERE `id` = ? AND `user_id` = ? AND `is_read` = 0'
        );
        $stmt->execute([$notificationId, $userId]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[WebArchive] notify mark read failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Đánh dấu TẤT CẢ thông báo của tài khoản là đã đọc.
 */
function wa_notify_mark_all_read(int $userId): int
{
    try {
        $stmt = db()->prepare(
            'UPDATE `notifications`
                SET `is_read` = 1, `read_at` = NOW()
              WHERE `user_id` = ? AND `is_read` = 0'
        );
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('[WebArchive] notify mark all read failed: ' . $e->getMessage());
        return 0;
    }
}
