<?php
/**
 * scripts/migrate_page_check.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG CỜ KIỂM TRA BẢN MỚI CHO saved_pages — chạy được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_page_check.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó chỉ thêm HAI cột vào `saved_pages` (không xoá, không sửa dữ liệu cũ):
 *   has_update TINYINT(1) — 1 khi lần kiểm tra gần nhất thấy trang gốc đổi.
 *   checked_at DATETIME  — thời điểm kiểm tra gần nhất.
 * Nhờ kiểm tra INFORMATION_SCHEMA trước nên chạy lại bao nhiêu lần cũng an toàn.
 * Tính năng so sánh / kiểm tra bản mới vẫn chạy được khi chưa migrate (cờ chỉ
 * không lưu lại được), nhưng muốn badge "Có bản mới" hiện thì phải chạy nó.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_page_check.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $cols = db()->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_pages'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('has_update', $cols, true)) {
        db()->exec(
            'ALTER TABLE `saved_pages` ADD COLUMN `has_update` TINYINT(1) NOT NULL DEFAULT 0'
        );
        out('OK: da them cot has_update.');
    } else {
        out('OK: cot has_update da ton tai.');
    }

    if (!in_array('checked_at', $cols, true)) {
        db()->exec(
            'ALTER TABLE `saved_pages` ADD COLUMN `checked_at` DATETIME NULL DEFAULT NULL'
        );
        out('OK: da them cot checked_at.');
    } else {
        out('OK: cot checked_at da ton tai.');
    }
} catch (PDOException $e) {
    out('LOI: ' . $e->getMessage());
    exit(1);
}
