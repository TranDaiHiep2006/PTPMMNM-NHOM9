<?php
/**
 * scripts/migrate_domain_requests.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG BẢNG HÀNG CHỜ YÊU CẦU DUYỆT TÊN MIỀN — chạy được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_domain_requests.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó chỉ tạo MỘT bảng mới: `domain_requests`. Không xoá, không sửa dữ liệu cũ.
 * Nhờ `CREATE TABLE IF NOT EXISTS` nên chạy lại bao nhiêu lần cũng an toàn.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_domain_requests.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

// DDL của bảng domain_requests — GIỐNG HỆT khối trong database/schema.sql, nên
// cài mới (schema.sql) và nâng cấp (kịch bản này) luôn cho cùng một cấu trúc.
$createSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `domain_requests` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain`       VARCHAR(255)  NOT NULL,
    `note`         VARCHAR(255)  NULL,
    `requested_by` INT UNSIGNED NOT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_domain_requests_domain` (`domain`),
    KEY `ix_domain_requests_requested_by` (`requested_by`),
    CONSTRAINT `fk_domain_requests_requested_by`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

try {
    db()->exec($createSql);
    out('OK: bang domain_requests da san sang.');
} catch (PDOException $e) {
    out('LOI: ' . $e->getMessage());
    exit(1);
}
