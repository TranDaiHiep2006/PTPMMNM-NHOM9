<?php
/**
 * config/db.php
 * -----------------------------------------------------------------------------
 * CẤU HÌNH CƠ SỞ DỮ LIỆU (tệp được commit).
 *
 * Tệp này KHÔNG chứa mật khẩu. Thông tin kết nối thật nằm trong
 * config/db.local.php — tệp đó được .gitignore và KHÔNG BAO GIỜ commit.
 * Tệp db.local.php được sinh tự động bởi scripts/setup_db.php.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

/**
 * Nạp cấu hình kết nối MySQL từ config/db.local.php.
 *
 * @return array{dsn:string,user:string,password:string,host:string,port:int,dbname:string}
 * @throws RuntimeException Khi thiếu tệp cấu hình hoặc cấu hình không hợp lệ.
 */
function wa_db_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/db.local.php';

    if (!is_file($localFile)) {
        throw new RuntimeException(
            'Chưa có tệp cấu hình config/db.local.php. '
            . 'Vui lòng chạy: php scripts/setup_db.php (cần biến môi trường WA_ADMIN_USER và WA_ADMIN_PASS).'
        );
    }

    /** @var mixed $local */
    $local = require $localFile;

    if (!is_array($local)) {
        throw new RuntimeException('Tệp cấu hình config/db.local.php không hợp lệ.');
    }

    foreach (['host', 'port', 'dbname', 'user', 'password'] as $key) {
        if (!array_key_exists($key, $local)) {
            throw new RuntimeException('Tệp cấu hình config/db.local.php thiếu khoá "' . $key . '".');
        }
    }

    // -------------------------------------------------------------------------
    // GHI ĐÈ BẰNG BIẾN MÔI TRƯỜNG (không bắt buộc).
    //
    // Nhờ vậy có thể đặt mật khẩu MySQL trong PowerShell rồi chạy ứng dụng mà
    // KHÔNG cần ghi mật khẩu vào config/db.local.php, không cần dán vào tin
    // nhắn và không rò rỉ bí mật khi chia sẻ mã nguồn.
    //
    //   $env:WA_DB_HOST = '127.0.0.1'
    //   $env:WA_DB_PORT = '3306'
    //   $env:WA_DB_NAME = 'webarchive'
    //   $env:WA_DB_USER = 'webarchive'
    //   $env:WA_DB_PASS = '<mat-khau>'
    // -------------------------------------------------------------------------
    foreach (['host', 'dbname', 'user'] as $key) {
        $envValue = getenv('WA_DB_' . strtoupper($key));
        if ($envValue !== false && $envValue !== '') {
            $local[$key] = $envValue;
        }
    }

    $envPort = getenv('WA_DB_PORT');
    if ($envPort !== false && $envPort !== '') {
        $local['port'] = (int) $envPort;
    }

    $envPass = getenv('WA_DB_PASS');
    if ($envPass !== false && $envPass !== '') {
        $local['password'] = $envPass;
    }

    $host    = (string) $local['host'];
    $port    = (int) $local['port'];
    $dbname  = (string) $local['dbname'];
    $charset = (string) ($local['charset'] ?? 'utf8mb4');

    $config = [
        'host'     => $host,
        'port'     => $port,
        'dbname'   => $dbname,
        'user'     => (string) $local['user'],
        'password' => (string) $local['password'],
        'charset'  => $charset,
        'dsn'      => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset),
    ];

    return $config;
}
