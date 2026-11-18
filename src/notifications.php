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
                       sp.source_url, sp.title AS page_title
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
                // Số phiên bản MỚI và bản TRƯỚC đó (lấy từ bảng snapshots —
                // KHÔNG giả định "bản trước = bản mới - 1").
                'to_version'  => wa_notify_snapshot_version($snapshotId),
                'from_version' => wa_notify_previous_version($pageId, $snapshotId),
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
 */
function wa_notify_previous_version(int $pageId, int $snapshotId): int
{
    if ($pageId <= 0 || $snapshotId <= 0) {
        return 0;
    }

    try {
        $stmt = db()->prepare(
            'SELECT version_no
               FROM `snapshots`
              WHERE page_id = ?
                AND id <> ?
                AND fetch_status = \'success\'
              ORDER BY version_no DESC
              LIMIT 1'
        );
        $stmt->execute([$pageId, $snapshotId]);

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

/**
 * Phát thông báo "bài viết đã được cập nhật lên phiên bản V[n]" cho TẤT CẢ
 * tài khoản được phép truy cập bài viết đó.
 *
 * "Được phép truy cập" = chủ sở hữu thư viện + thành viên có vai trò owner /
 * editor. Người xem (viewer) KHÔNG nhận thông báo — họ không được phép cập
 * nhật bài viết này.
 *
 * Thông báo gồm:
 *   - tiêu đề có tên bài + số phiên bản mới;
 *   - tối đa 3 ý tóm tắt thay đổi (tự sinh từ diff, KHÔNG dùng AI);
 *   - hai liên kết: xem bản mới (reader.php?id=<snapshot_id>) và xem khác
 *     biệt (compare.php?page_id=..&from=..&to=..).
 *
 * @param int         $libraryId   Thư viện chứa bài viết.
 * @param int         $pageId      Bài viết (saved_pages.id).
 * @param int         $snapshotId  Snapshot MỚI (phiên bản vừa tạo).
 * @param int         $versionNo   Số phiên bản mới.
 * @param string      $pageTitle   Tên bài viết.
 * @param string      $sourceUrl   URL nguồn.
 * @param int         $oldSnapshotId Snapshot TRƯỚC đó (0 nếu không rõ) — dùng
 *                               để tính tóm tắt thay đổi.
 *
 * @return int Số thông báo thực sự được tạo.
 */
function wa_auto_notify_version(
    int $libraryId,
    int $pageId,
    int $snapshotId,
    int $versionNo,
    string $pageTitle,
    string $sourceUrl,
    int $oldSnapshotId = 0
): int {
    $recipients = wa_auto_version_recipients($libraryId);

    if ($recipients === []) {
        return 0;
    }

    $title = sprintf(
        'Bài viết %s đã được cập nhật lên phiên bản V%d',
        $pageTitle !== '' ? $pageTitle : 'không rõ tên',
        $versionNo
    );

    // --- Tóm tắt thay đổi: tự sinh từ diff giữa bản cũ và bản mới. ---
    $summary = wa_notify_version_summary($oldSnapshotId, $snapshotId);

    $body = sprintf(
        'Trang gốc đã thay đổi. Phiên bản V%d được lưu tự động lúc %s.',
        $versionNo,
        wa_datetime(date('Y-m-d H:i:s'))
    );

    if ($summary !== []) {
        $body .= "\n" . implode("\n", array_map(
            static fn (string $line): string => '• ' . $line,
            $summary
        ));
    } else {
        $body .= "\n" . 'Nội dung đã được cập nhật. Xem so sánh để biết chi tiết.';
    }

    $created = 0;

    foreach ($recipients as $userId) {
        $id = wa_notify_create(
            (int) $userId,
            $pageId,
            $snapshotId,
            WA_NOTIFY_VERSION_UPDATE,
            $title,
            $body,
            wa_notify_dedupe_key((int) $userId, $pageId, $versionNo)
        );

        if ($id !== null) {
            $created++;
        }
    }

    return $created;
}

/**
 * Tóm tắt thay đổi giữa hai snapshot, dựng từ hàm diff sẵn có.
 *
 * Trả về tối đa 3 ý tiếng Việt. Nếu không đủ dữ liệu (thiếu bản cũ, diff quá
 * dài, nội dung rỗng) thì trả về mảng rỗng — nơi gọi sẽ dùng thông báo dự
 * phòng. KHÔNG bịa nội dung.
 *
 * @return array<int, string>
 */
function wa_notify_version_summary(int $oldSnapshotId, int $newSnapshotId): array
{
    if ($oldSnapshotId <= 0 || $newSnapshotId <= 0 || $oldSnapshotId === $newSnapshotId) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, content_html, content_text
               FROM `snapshots`
              WHERE id IN (?, ?)
                AND fetch_status = \'success\''
        );
        $stmt->execute([$oldSnapshotId, $newSnapshotId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $old = null;
        $new = null;

        foreach ($rows as $row) {
            if ((int) $row['id'] === $oldSnapshotId) {
                $old = $row;
            } elseif ((int) $row['id'] === $newSnapshotId) {
                $new = $row;
            }
        }

        if ($old === null || $new === null) {
            return [];
        }

        $oldUnits = wa_diff_units(
            (string) ($old['content_html'] ?? ''),
            (string) ($old['content_text'] ?? '')
        );
        $newUnits = wa_diff_units(
            (string) ($new['content_html'] ?? ''),
            (string) ($new['content_text'] ?? '')
        );

        if ($oldUnits === [] || $newUnits === []) {
            return [];
        }

        $ops = wa_diff_ops($oldUnits, $newUnits);

        return wa_diff_summary($ops);
    } catch (Throwable $e) {
        error_log('[WebArchive] notify summary failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Danh sách tài khoản được phép truy cập (và cập nhật) một bài trong thư viện.
 *
 * @return array<int, int> Danh sách user_id (không trùng lặp).
 */
function wa_auto_version_recipients(int $libraryId): array
{
    try {
        $stmt = db()->prepare(
            "SELECT DISTINCT u.id
               FROM `users` u
          LEFT JOIN `library_members` lm ON lm.user_id = u.id AND lm.library_id = ?
          LEFT JOIN `libraries` l ON l.id = ?
              WHERE (l.owner_id = u.id OR lm.role IN ('owner', 'editor'))
                AND u.status = 'active'"
        );
        $stmt->execute([$libraryId, $libraryId]);

        $ids = [];

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    } catch (Throwable $e) {
        error_log('[WebArchive] auto recipients failed: ' . $e->getMessage());
        return [];
    }
}