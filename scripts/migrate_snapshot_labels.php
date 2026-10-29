<?php
/**
 * scripts/migrate_snapshot_labels.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG CỘT TÊN PHIÊN BẢN — chạy được nhiều lần.
 *
 * Cột `snapshots.label` lưu tên phiên bản do người dùng đặt trong lịch sử
 * phiên bản (để trống = chưa đặt tên, hiển thị mặc định "Phiên bản N").
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_snapshot_labels.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó chỉ thêm MỘT cột mới (NULL, không giá trị mặc định bắt buộc) nên không
 * sửa, không xoá dữ liệu cũ. Nhờ kiểm tra INFORMATION_SCHEMA trước nên chạy
 * lại bao nhiêu lần cũng an toàn. MySQL không hỗ trợ ADD COLUMN IF NOT EXISTS
 * nên phải kiểm tra bằng tay.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_snapshot_labels.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $check = db()->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS ' .
        "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snapshots' AND COLUMN_NAME = 'label'"
    );
    $check->execute();

    if ((int) $check->fetchColumn() === 0) {
        db()->exec(
            'ALTER TABLE `snapshots` ADD COLUMN `label` VARCHAR(255) NULL DEFAULT NULL AFTER `version_no`'
        );
        out('OK: đã thêm cột snapshots.label.');
    } else {
        out('OK: cột snapshots.label đã có sẵn.');
    }
} catch (Throwable $e) {
    out('LỖI: không thêm được cột snapshots.label: ' . $e->getMessage());
    exit(1);
}
