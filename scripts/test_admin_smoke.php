<?php
/**
 * scripts/test_admin_smoke.php — KIỂM THỬ KHỐI (SMOKE) PHẦN ADMIN.
 *
 * Phạm vi (theo yêu cầu đề tài 16 — KHÔNG nâng cấp giao diện Admin):
 *   S1. Tài khoản Quản trị viên đăng nhập được và vào được /admin/.
 *   S2. Người dùng thường bị từ chối khi truy cập /admin/ (HTTP 403).
 *   S3. Các chức năng quản trị CŨ vẫn hoạt động:
 *       - /admin/index.php (tổng quan) trả HTTP 200
 *       - /admin/users.php (quản lý người dùng) trả HTTP 200
 *       - /admin/allowlist.php (tên miền tin cậy) trả HTTP 200
 *   S4. Người dùng thường bị chặn ở TẤT CẢ các trang admin kể trên.
 *
 * Chạy: php scripts/test_admin_smoke.php
 * Mã thoát: 0 khi tất cả PASS, 1 khi có FAIL.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/test_admin_smoke.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

const WA_S_APP_PORT = 18086;
const WA_S_APP_BASE = 'http://127.0.0.1:18086';

$results = [];
$proc    = null;
$jar     = sys_get_temp_dir() . '/wa-adminsmoke-' . getmypid() . '.jar';

register_shutdown_function(static function () use (&$proc, $jar): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }

    if (is_file($jar)) {
        @unlink($jar);
    }
});

function s_say(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function s_record(string $id, bool $passed, string $evidence): void
{
    global $results;

    $results[] = ['id' => $id, 'passed' => $passed, 'evidence' => $evidence];

    s_say(($passed ? '  PASS  ' : '  FAIL  ') . $id . ' — ' . $evidence);
}

function s_http(string $url, ?array $post = null, string $jar = ''): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = curl_exec($ch);

    if ($raw === false) {
        curl_close($ch);

        return ['status' => 0, 'body' => '', 'headers' => ''];
    }

    $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => substr((string) $raw, $headerSize),
        'headers' => substr((string) $raw, 0, $headerSize),
    ];
}

function s_login(string $email, string $password, string $jar): bool
{
    $res = s_http(WA_S_APP_BASE . '/login.php', [
        'email'    => $email,
        'password' => $password,
    ], $jar);

    return $res['status'] === 302
        || ($res['status'] === 200 && !str_contains($res['body'], 'name="password"'));
}

/* --- Khởi động máy chủ --- */
$logFile = sys_get_temp_dir() . '/wa-adminsmoke-' . getmypid() . '.log';

$cmd = sprintf(
    '%s -d display_errors=0 -S 127.0.0.1:%d -t %s',
    escapeshellarg(PHP_BINARY),
    WA_S_APP_PORT,
    escapeshellarg(dirname(__DIR__) . '/public')
);

$proc = proc_open($cmd, [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);

$ready = false;

for ($i = 0; $i < 80; $i++) {
    $sock = @fsockopen('127.0.0.1', WA_S_APP_PORT, $errno, $errstr, 0.3);

    if ($sock !== false) {
        fclose($sock);
        $ready = true;
        break;
    }

    usleep(100000);
}

s_say('');
s_say('=== KIỂM THỬ KHỐI PHẦN QUẢN TRỊ (ADMIN) ===');
s_say('');

if (!$ready) {
    s_record('S0', false, 'Không khởi động được máy chủ thử nghiệm trên cổng ' . WA_S_APP_PORT);
    exit(1);
}

s_say('  · máy chủ thử nghiệm sẵn sàng (cổng ' . WA_S_APP_PORT . ')');
s_say('');

/* --- Tài khoản quản trị --- */
$adminJar = sys_get_temp_dir() . '/wa-adminsmoke-admin-' . getmypid() . '.jar';
$adminOk  = s_login('admin@demo.vn', 'admin123', $adminJar);

if (!$adminOk) {
    s_record('S1', false, 'Tài khoản quản trị demo đăng nhập không được');
    exit(1);
}

$adminHome = s_http(WA_S_APP_BASE . '/admin/index.php', null, $adminJar);
$adminOk   = ($adminHome['status'] === 200)
    && !str_contains($adminHome['body'], 'name="password"');

s_record('S1', $adminOk,
    'Quản trị đăng nhập được, /admin/index.php trả HTTP ' . $adminHome['status']
    . ', có nội dung trang quản trị=' . ($adminOk ? 'có' : 'không'));

/* --- Các trang quản trị cũ vẫn hoạt động --- */
$adminPages = [
    'S3a' => '/admin/index.php',
    'S3b' => '/admin/users.php',
    'S3c' => '/admin/allowlist.php',
];

foreach ($adminPages as $id => $path) {
    $res = s_http(WA_S_APP_BASE . $path, null, $adminJar);

    s_record($id, $res['status'] === 200,
        'Quản trị truy cập ' . $path . ' -> HTTP ' . $res['status'] . ' (mong đợi 200)');
}

/* --- Người dùng thường bị chặn --- */
$runId   = date('His') . '-' . getmypid();
$userJar = sys_get_temp_dir() . '/wa-adminsmoke-user-' . getmypid() . '.jar';
$email   = 'adminsmoke-' . $runId . '@test.local';
$pass    = 'Smk' . getmypid() . '123';

// Đăng ký tài khoản thường.
s_http(WA_S_APP_BASE . '/register.php', null, $userJar);
$regBody = s_http(WA_S_APP_BASE . '/register.php', null, $userJar)['body'];
$csrf    = '';

if (preg_match('/name="wa_csrf" value="([^"]+)"/', $regBody, $m)) {
    $csrf = $m[1];
}

s_http(WA_S_APP_BASE . '/register.php', [
    'wa_csrf'          => $csrf,
    'name'             => 'Smoke User',
    'email'            => $email,
    'password'         => $pass,
    'confirm_password' => $pass,
], $userJar);

$userLoggedIn = s_login($email, $pass, $userJar);

if (!$userLoggedIn) {
    s_record('S2', false, 'Không đăng ký/đăng nhập được tài khoản thường');
    exit(1);
}

$blocked = [];

foreach (array_keys($adminPages) as $id) {
    $path   = $adminPages[$id];
    $res    = s_http(WA_S_APP_BASE . $path, null, $userJar);
    $denied = ($res['status'] === 403) || ($res['status'] === 302 && str_contains($res['headers'], 'login.php'));

    if (!$denied) {
        $blocked[] = $path . ' -> HTTP ' . $res['status'];
    }
}

s_record('S2', $blocked === [],
    'Người dùng thường bị chặn ở mọi trang admin'
    . ($blocked === [] ? '' : ' — LỖI: ' . implode(', ', $blocked)));

/* --- Dọn dẹp tài khoản thử nghiệm --- */
$userId = (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote($email))->fetchColumn();

if ($userId > 0) {
    db()->prepare('DELETE FROM `users` WHERE id = ?')->execute([$userId]);
}

s_say('');
s_say('  · dọn tài khoản thử nghiệm (id=' . $userId . ')');

/* --- Tổng kết --- */
$passed = 0;
$failed = 0;

s_say('');
s_say('============================================================');
s_say('  KẾT QUẢ KIỂM THỬ KHỐI PHẦN QUẢN TRỊ');
s_say('============================================================');

foreach ($results as $item) {
    s_say(($item['passed'] ? '  PASS  ' : '  FAIL  ') . $item['id'] . ' — ' . $item['evidence']);

    if ($item['passed']) {
        $passed++;
    } else {
        $failed++;
    }
}

s_say('------------------------------------------------------------');
s_say('  Tổng: ' . count($results) . ' mục — PASS: ' . $passed . ' — FAIL: ' . $failed);
s_say('============================================================');
s_say('');

exit($failed === 0 ? 0 : 1);
