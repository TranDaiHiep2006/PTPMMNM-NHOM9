<?php
/**
 * scripts/migrate_library_stars.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG BẢNG ĐÁNH DẤU YÊU THÍCH CHO THƯ VIỆN — chạy được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_library_stars.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó chỉ tạo MỘT bảng mới: `library_stars`. Không xoá, không sửa dữ liệu cũ.
 * Nhờ `CREATE TABLE IF NOT EXISTS` nên chạy lại bao nhiêu lần cũng an toàn.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_library_stars.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

// DDL của bảng library_stars — GIỐNG HỆT khối trong database/schema.sql, nên
// cài mới (schema.sql) và nâng cấp (kịch bản này) luôn cho cùng một cấu trúc.
$createSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `library_stars` (
    `user_id`    INT UNSIGNED NOT NULL,
    `library_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `library_id`),
    KEY `ix_library_stars_library` (`library_id`),
    CONSTRAINT `fk_library_stars_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_library_stars_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

try {
    db()->exec($createSql);
    out('OK: bảng library_stars đã sẵn sàng.');
} catch (Throwable $e) {
    out('LỖI: không tạo được bảng library_stars: ' . $e->getMessage());
    exit(1);
}
