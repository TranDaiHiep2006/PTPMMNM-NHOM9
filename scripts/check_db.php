<?php
/**
 * scripts/check_db.php — KIỂM TRA KẾT NỐI CƠ SỞ DỮ LIỆU (CLI, idempotent).
 *
 * Dùng để trả lời câu hỏi "MySQL đã kết nối được chưa?" mà không cần mở
 * trình duyệt và không cần dán mật khẩu vào mã nguồn.
 *
 * CÁCH CHẠY (PowerShell):
 *
 *     php scripts/check_db.php
 *
 * Nếu chưa có config/db.local.php hoặc mật khẩu sai, hãy đặt biến môi trường
 * rồi chạy lại (KHÔNG ghi mật khẩu vào tệp, không gửi vào tin nhắn):
 *
 *     $env:WA_DB_USER = 'webarchive'
 *     $env:WA_DB_PASS = '<mat-khau-tai-khoan-ung-dung>'
 *     php scripts/check_db.php
 *
 * Mã thoát: 0 khi kết nối được, 1 khi không.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/check_db.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

out('[1/4] Cấu hình:');

try {
    $config = wa_db_config();
} catch (Throwable $e) {
    out('  LOI: ' . $e->getMessage());
    out('');
    out('  Hãy tạo config/db.local.php hoặc đặt biến môi trường WA_DB_*.');
    exit(1);
}

out('  host   = ' . $config['host'] . ':' . $config['port']);
out('  dbname = ' . $config['dbname']);
out('  user   = ' . $config['user']);
out('  pass   = ' . ($config['password'] === '' ? '(rỗng)' : '(đã có — không hiện)'));

out('');
out('[2/4] Mở kết nối PDO...');

try {
    $pdo = db();
} catch (Throwable $e) {
    out('  FAIL: ' . db_diagnose($e));
    exit(1);
}

out('  OK — đã mở được kết nối.');

out('');
out('[3/4] Kiểm tra SELECT 1...');

try {
    $one = $pdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    out('  FAIL: ' . db_diagnose($e));
    exit(1);
}

out('  SELECT 1 = ' . var_export($one, true)
    . ((int) $one === 1 ? '  -> PASS' : '  -> FAIL'));

out('');
out('[4/4] Kiểm tra bảng chính của ứng dụng...');

$required = ['users', 'libraries', 'saved_pages', 'snapshots', 'notes', 'allowlist_domains'];
$missing  = [];

foreach ($required as $table) {
    try {
        $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
    } catch (Throwable) {
        $missing[] = $table;
    }
}

if ($missing === []) {
    out('  OK — đủ ' . count($required) . ' bảng cốt lõi.');
} else {
    out('  Thiếu bảng: ' . implode(', ', $missing));
    out('  Hãy chạy: php scripts/setup_db.php');
}

out('');
out('KẾT NỐI MYSQL: PASS');
exit(0);
