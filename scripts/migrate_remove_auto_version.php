<?php
/**
 * scripts/migrate_remove_auto_version.php
 * -----------------------------------------------------------------------------
 * DỌN HẠ TẦNG "AUTO VERSION" KHỎI DATABASE ĐANG CHẠY — chạy được nhiều lần.
 *
 * Bối cảnh: tính năng TỰ ĐỘNG cập nhật phiên bản mới đã bị gỡ khỏi code.
 * Từ nay hệ thống CHỈ THÔNG BÁO "Có bản mới" (cờ `saved_pages.has_update`
 * do `public/check_one.php` ghi); người dùng tự bấm "Lấy phiên bản mới"
 * (refetch.php) khi muốn. Không còn snapshot nào được tạo tự động.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_remove_auto_version.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó CHỈ xoá các đối tượng của Auto Version, KHÔNG xoá dữ liệu khác:
 *
 *   1) Bảng `auto_version_events` (nhật ký các lần tự tạo phiên bản).
 *   2) Bốn cột trên `saved_pages`: `auto_checked_at`, `auto_version_no`,
 *      `auto_locked_at`, `auto_lock_token`.
 *   3) Cột `users.auto_update_enabled` (cài đặt bật/tắt tự động cập nhật).
 *
 * GIỮ NGUYÊN (không đụng tới):
 *   - Bảng `notifications` + các thông báo đã có (tính năng theo dõi đoạn F10
 *     vẫn dùng bảng này để báo đoạn thay đổi).
 *   - Cờ `saved_pages.has_update` + `checked_at` (cơ chế CHỈ THÔNG BÁO).
 *
 * LƯU Ý: việc xoá bảng `auto_version_events` là MỘT CHIỀU — nhật ký các lần
 * tự tạo phiên bản cũ sẽ mất. Chỉ chạy khi đã chắc chắn không cần xem lại
 * nhật ký đó nữa.
 *
 * Nhờ kiểm tra INFORMATION_SCHEMA trước nên chạy lại bao nhiêu lần cũng an toàn.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_remove_auto_version.php chỉ chạy được từ dòng lệnh (CLI).';
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
     * 1) Bảng `auto_version_events`
     * ---------------------------------------------------------------------- */
    $hasTable = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_version_events'"
    )->fetchColumn();

    if ((int) $hasTable > 0) {
        $pdo->exec('DROP TABLE `auto_version_events`');
        out('OK: da xoa bang `auto_version_events`.');
    } else {
        out('OK: bang `auto_version_events` da khong con.');
    }

    /* -------------------------------------------------------------------------
     * 2) Bốn cột auto_* trên `saved_pages`
     * ---------------------------------------------------------------------- */
    $pageCols = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_pages'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach (['auto_checked_at', 'auto_version_no', 'auto_locked_at', 'auto_lock_token'] as $col) {
        if (in_array($col, $pageCols, true)) {
            $pdo->exec("ALTER TABLE `saved_pages` DROP COLUMN `{$col}`");
            out("OK: da xoa cot `saved_pages.{$col}`.");
        } else {
            out("OK: cot `saved_pages.{$col}` da khong con.");
        }
    }

    /* -------------------------------------------------------------------------
     * 3) Cột `users.auto_update_enabled`
     * ---------------------------------------------------------------------- */
    $userCols = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('auto_update_enabled', $userCols, true)) {
        $pdo->exec('ALTER TABLE `users` DROP COLUMN `auto_update_enabled`');
        out('OK: da xoa cot `users.auto_update_enabled`.');
    } else {
        out('OK: cot `users.auto_update_enabled` da khong con.');
    }

    out('');
    out('HOAN TAT. Tu nay chi thong bao "Co ban moi", khong tu tao phien ban nua.');
    exit(0);
} catch (PDOException $e) {
    out('LOI: ' . $e->getMessage());
    exit(1);
}
