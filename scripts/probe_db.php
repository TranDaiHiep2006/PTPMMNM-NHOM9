<?php
/**
 * scripts/probe_db.php — chẩn đoán nhanh kết nối MySQL ở một cổng khác.
 *
 * Dùng biến môi trường WA_DB_HOST / WA_DB_PORT / WA_DB_NAME / WA_DB_USER /
 * WA_DB_PASS (hoặc đọc config/db.local.php). KHÔNG in mật khẩu.
 *
 *   php scripts/probe_db.php 3306
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$port  = (int) ($argv[1] ?? 3306);
$host  = (string) (getenv('WA_DB_HOST') ?: '127.0.0.1');
$name  = (string) (getenv('WA_DB_NAME') ?: 'webarchive');
$user  = (string) (getenv('WA_DB_USER') ?: 'webarchive');
$pass  = (string) (getenv('WA_DB_PASS') ?: '');

$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);

try {
    // Bước 1: kết nối KHÔNG chọn database — xác nhận user/pass đúng.
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    echo '[OK] Dang nhap thanh cong tai ', $host, ':', $port, ' voi user "', $user, '"', PHP_EOL;

    $one = (int) $pdo->query('SELECT 1')->fetchColumn();
    echo '[OK] SELECT 1 = ', $one, PHP_EOL;

    // Bước 2: database "webarchive" có tồn tại không?
    $stmt = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $stmt->execute([$name]);
    $exists = $stmt->fetchColumn();

    $msg = $exists
        ? '[OK] Database "' . $name . '" ton tai.'
        : '[--] Database "' . $name . '" CHUA ton tai tren may chu nay.';

    echo $msg, PHP_EOL;

    // Bước 3: nếu có, liệt kê bảng.
    if ($exists) {
        $pdo->exec('USE `' . str_replace('`', '', $name) . '`');

        $tables = $pdo->query(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);

        echo '[--] Bang (', count($tables), '): ', implode(', ', $tables), PHP_EOL;
    }

    echo 'KET QUA: PASS', PHP_EOL;
    exit(0);
} catch (PDOException $e) {
    echo '[FAIL] ', $e->getMessage(), PHP_EOL;
    echo 'KET QUA: FAIL', PHP_EOL;
    exit(1);
}
