<?php
/**
 * scripts/migrate_auto_version.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG HẠ TẦNG "AUTO VERSION + THÔNG BÁO" — chạy được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_auto_version.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó CHỈ thêm các đối tượng mới, KHÔNG xoá và KHÔNG sửa dữ liệu cũ:
 *
 *   1) Bảng `notifications`
 *      - Mỗi dòng là MỘT thông báo cho MỘT tài khoản người dùng.
 *      - `dedupe_key` là khoá chống trùng (UNIQUE): cùng một phiên bản mới của
 *        cùng một bài sẽ KHÔNG tạo ra hai thông báo.
 *
 *   2) Cột `saved_pages.auto_checked_at`
 *      - Thời điểm kiểm tra tự động gần nhất (độc lập với `checked_at` của
 *        nút "Kiểm tra bản mới" thủ công).
 *
 *   3) Cột `saved_pages.auto_version_no`
 *      - Số phiên bản cuối cùng do kiểm tra TỰ ĐỘNG tạo ra.
 *      - Dùng để chống chạy trùng: hai request đồng thời sẽ tranh nhau giành
 *        quyền ghi cột này, chỉ một request thắng.
 *
 *   4) Cột `saved_pages.auto_locked_at`
 *      - Mốc thời gian đang giữ khoá kiểm tra. Một tiến trình kiểm tra đang
 *        chạy sẽ đặt mốc này; tiến trình khác thấy mốc còn "mới" thì bỏ qua.
 *
 *   5) Cột `saved_pages.auto_lock_token`
 *      - Token ngẫu nhiên của tiến trình đang giữ khoá. Chỉ tiến trình giữ
 *        token này mới được nhả khoá / ghi kết quả.
 *
 * Nhờ kiểm tra INFORMATION_SCHEMA trước nên chạy lại bao nhiêu lần cũng an toàn.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_auto_version.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $pdo = db();

    /* -------------------------------------------------------------------------
     * 1) Bảng `notifications`
     * ---------------------------------------------------------------------- */
    $hasTable = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'"
    )->fetchColumn();

    if ((int) $hasTable === 0) {
        $pdo->exec(
            'CREATE TABLE `notifications` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT UNSIGNED NOT NULL,
                `page_id`     INT UNSIGNED NULL DEFAULT NULL,
                `snapshot_id` INT UNSIGNED NULL DEFAULT NULL,
                `kind`        VARCHAR(40)  NOT NULL DEFAULT \'version_update\',
                `title`       VARCHAR(190) NOT NULL,
                `body`        VARCHAR(500) NOT NULL DEFAULT \'\',
                `dedupe_key`  VARCHAR(190) NOT NULL,
                `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
                `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `read_at`     DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_notifications_dedupe` (`dedupe_key`),
                KEY `ix_notifications_user` (`user_id`, `is_read`, `id`),
                KEY `ix_notifications_page` (`page_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        out('OK: da tao bang `notifications`.');
    } else {
        out('OK: bang `notifications` da ton tai.');
    }

    /* -------------------------------------------------------------------------
     * 2..5) Các cột mới trên `saved_pages`
     * ---------------------------------------------------------------------- */
    $cols = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_pages'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $newColumns = [
        'auto_checked_at'  => 'DATETIME NULL DEFAULT NULL',
        'auto_version_no'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'auto_locked_at'   => 'DATETIME NULL DEFAULT NULL',
        'auto_lock_token'  => 'CHAR(32) NULL DEFAULT NULL',
    ];

    foreach ($newColumns as $column => $definition) {
        if (!in_array($column, $cols, true)) {
            $pdo->exec(
                'ALTER TABLE `saved_pages` ADD COLUMN `' . $column . '` ' . $definition
            );
            out('OK: da them cot `' . $column . '`.');
        } else {
            out('OK: cot `' . $column . '` da ton tai.');
        }
    }

    /* -------------------------------------------------------------------------
     * 6) Bảng `auto_version_events` — nhật ký các lần tự tạo phiên bản.
     *
     * Dùng để chống trùng ở mức NGHIỆP VỤ: một bài chỉ được tự tạo phiên bản
     * mới khi nội dung thật sự khác bản thành công gần nhất. Nhật ký giữ vết
     * "lần tạo phiên bản n cho bài p đã dựa trên snapshot s" để chứng minh
     * không có bản trùng.
     * ---------------------------------------------------------------------- */
    $hasEvents = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_version_events'"
    )->fetchColumn();

    if ((int) $hasEvents === 0) {
        $pdo->exec(
            'CREATE TABLE `auto_version_events` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `page_id`     INT UNSIGNED NOT NULL,
                `base_snapshot_id` INT UNSIGNED NOT NULL,
                `new_snapshot_id`  INT UNSIGNED NOT NULL,
                `version_no`  INT UNSIGNED NOT NULL,
                `actor_id`    INT UNSIGNED NULL DEFAULT NULL,
                `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_auto_version_unique` (`page_id`, `version_no`),
                KEY `ix_auto_version_base` (`page_id`, `base_snapshot_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        out('OK: da tao bang `auto_version_events`.');
    } else {
        out('OK: bang `auto_version_events` da ton tai.');
    }

    out('');
    out('HOAN TAT. Hay chay tiep: php scripts/reindex_search.php (neu chua).');
    exit(0);
} catch (PDOException $e) {
    out('LOI: ' . $e->getMessage());
    exit(1);
}
