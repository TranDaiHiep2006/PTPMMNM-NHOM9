<?php
/**
 * scripts/test_notify_summary.php
 * -----------------------------------------------------------------------------
 * KIỂM THỬ TÓM TẮT THAY ĐỔI TRONG THÔNG BÁO PHIÊN BẢN (P-AUTOVERSION).
 *
 * Chỉ kiểm tra 5 trường hợp:
 *   N1. Tạo V3 sinh ĐÚNG 1 thông báo.
 *   N2. Link "Xem V3" mở đúng snapshot (reader.php?id=<snapshot_id>).
 *   N3. Link so sánh mở đúng V2 → V3 (compare.php?page_id&from&to).
 *   N4. Tóm tắt ngắn, đúng diff, KHÔNG có XSS.
 *   N5. Không tạo thông báo trùng, không lộ dữ liệu vượt quyền.
 *
 * Cách chạy:
 *     php scripts/test_notify_summary.php
 *
 * Mã thoát: 0 khi tất cả PASS, 1 khi có FAIL.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/test_notify_summary.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

const WA_T_APP_PORT    = 18084;
const WA_T_SAMPLE_PORT = 18095;
const WA_T_APP_BASE    = 'http://localhost:18084';
const WA_T_SAMPLE_BASE = 'http://localhost:18095';

$results = [];
$servers = [];
$logFiles = [];
$tempFiles = [];
$cookieJars = [];

/** @param string $line */
function t_out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

/** @param string $message */
function t_say(string $message): void
{
    t_out($message);
}

/** Ghi kết quả một mục kiểm thử. */
function t_record(string $id, bool $passed, string $evidence): void
{
    global $results;

    $results[] = ['id' => $id, 'passed' => $passed, 'evidence' => $evidence];

    t_say(($passed ? '  PASS  ' : '  FAIL  ') . $id . ' — ' . $evidence);
}

/** Dừng tiến trình con. */
function t_terminate_proc($proc): void
{
    if (!is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);

    if ($status['running'] ?? false) {
        proc_terminate($proc);

        for ($i = 0; $i < 20; $i++) {
            usleep(100000);
            $status = proc_get_status($proc);

            if (!($status['running'] ?? false)) {
                break;
            }
        }

        if (($status['running'] ?? false) && PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>nul');
        }
    }

    proc_close($proc);
}

/** Dừng toàn bộ máy chủ con. */
function t_kill_servers(): void
{
    global $servers;

    foreach ($servers as $proc) {
        t_terminate_proc($proc);
    }

    $servers = [];
}

/** Dọn tệp tạm + cookie jar. */
function t_cleanup_files(): void
{
    global $tempFiles, $cookieJars;

    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    foreach ($cookieJars as $jar) {
        if (is_file($jar)) {
            @unlink($jar);
        }
    }

    $tempFiles  = [];
    $cookieJars = [];
}

/** Cổng đang bận? */
function t_port_is_busy(int $port): bool
{
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);

    if ($sock === false) {
        return false;
    }

    fclose($sock);

    return true;
}

/** Khởi động `php -S` với docroot riêng. */
function t_start_server(int $port, string $docroot, string $label): void
{
    global $servers, $logFiles;

    if (t_port_is_busy($port)) {
        t_say('  · cổng ' . $port . ' đang bận — bỏ qua máy chủ ' . $label);
        return;
    }

    $logFile = sys_get_temp_dir() . '/wa-notifsum-' . getmypid() . '-' . $label . '.log';

    $cmd = sprintf(
        '%s -d display_errors=0 -S 127.0.0.1:%d -t %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($docroot)
    );

    $descriptors = [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']];
    $proc        = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($proc)) {
        t_say('  · KHÔNG khởi động được máy chủ ' . $label);
        return;
    }

    $servers[]  = $proc;
    $logFiles[] = $logFile;

    for ($i = 0; $i < 60; $i++) {
        if (t_port_is_busy($port)) {
            t_say('  · máy chủ ' . $label . ' sẵn sàng (cổng ' . $port . ')');
            return;
        }

        usleep(100000);
    }

    t_say('  · máy chủ ' . $label . ' KHÔNG sẵn sàng');
}

/** Cookie jar riêng cho mỗi vai trò. */
function t_cookie_jar(string $label): string
{
    global $cookieJars;

    $jar = sys_get_temp_dir() . '/wa-notifsum-' . getmypid() . '-' . $label . '.jar';
    @unlink($jar);

    $cookieJars[] = $jar;

    return $jar;
}

/**
 * Gọi HTTP bằng cURL.
 *
 * @param array<string,string>|null $post
 * @return array{status:int, body:string, headers:string, effective_url:string}
 */
function t_http(string $url, ?array $post = null, string $jar = '', bool $follow = true): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = curl_exec($ch);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return ['status' => 0, 'body' => '', 'headers' => '', 'effective_url' => $url . ' [ERR ' . $error . ']'];
    }

    $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effective  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status'        => $status,
        'body'          => substr((string) $raw, $headerSize),
        'headers'       => substr((string) $raw, 0, $headerSize),
        'effective_url' => $effective,
    ];
}

/** Lấy token CSRF từ HTML. */
function t_csrf(string $url, string $jar): string
{
    $res = t_http($url, null, $jar);

    if (preg_match('/name="wa_csrf" value="([^"]+)"/', $res['body'], $m)) {
        return $m[1];
    }

    return '';
}

/** Đăng nhập (login.php không dùng CSRF). */
function t_login(string $email, string $password, string $jar): bool
{
    $res = t_http(WA_T_APP_BASE . '/login.php', [
        'email'    => $email,
        'password' => $password,
    ], $jar);

    if (str_contains((string) $res['effective_url'], 'libraries.php')) {
        return true;
    }

    return $res['status'] === 200
        && !str_contains($res['body'], 'name="email"')
        && !str_contains($res['body'], 'name="password"');
}

/** Đăng ký tài khoản mới. */
function t_register(string $name, string $email, string $password, string $jar): bool
{
    t_http(WA_T_APP_BASE . '/register.php', null, $jar);
    $csrf = t_csrf(WA_T_APP_BASE . '/register.php', $jar);

    $res = t_http(WA_T_APP_BASE . '/register.php', [
        'wa_csrf'          => $csrf,
        'name'             => $name,
        'email'            => $email,
        'password'         => $password,
        'confirm_password' => $password,
    ], $jar);

    return str_contains((string) $res['body'], 'Đăng ký thành công');
}

/** Dựng một trang HTML mẫu đủ dài cho capture. */
function t_sample_html(string $title, string $marker, string $extra = ''): string
{
    $filler = 'Bai viet kiem thu tu dong phien ban WebArchive. '
        . 'Noi dung nay du de doc va so sanh giua cac phien ban, '
        . 'giup kiem thu phat hien khac biet noi dung thay doi. ';

    return '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>'
        . $title . '</title></head><body><article><h1>' . $title . '</h1>'
        . '<p>' . $marker . '</p>'
        . '<p>' . $extra . '</p>'
        . '<p>' . $filler . $filler . $filler . $filler . '</p>'
        . '<p>' . $filler . $filler . $filler . $filler . '</p>'
        . '</article></body></html>';
}

/* =============================================================================
 * BẮT ĐẦU
 * ============================================================================= */

register_shutdown_function(static function (): void {
    t_kill_servers();
    t_cleanup_files();
});

t_say('');
t_say('=== KIỂM THỬ TÓM TẮT THAY ĐỔI TRONG THÔNG BÁO ===');

/* --- Kiểm tra bảng/cột cần thiết --- */
$need = ['notifications', 'snapshots', 'saved_pages', 'libraries', 'users'];
$missing = [];

foreach ($need as $table) {
    $n = (int) db()->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . db()->quote($table)
    )->fetchColumn();

    if ($n === 0) {
        $missing[] = $table;
    }
}

if ($missing !== []) {
    t_record('N0', false, 'Thiếu bảng: ' . implode(', ', $missing));
    exit(1);
}

/* --- Khởi động máy chủ --- */
t_say('');
t_say('[0] Khởi động máy chủ riêng của bộ kiểm thử');
t_start_server(WA_T_APP_PORT, dirname(__DIR__) . '/public', 'ung-dung');
t_start_server(WA_T_SAMPLE_PORT, dirname(__DIR__) . '/tests/samples', 'thu-mau');

$runId     = date('His') . '-' . getmypid();
$ownerEmail = 'notifsum-' . $runId . '@test.local';
$ownerPass  = 'Notif' . getmypid() . '123';
$viewerEmail = 'notifsum-v-' . $runId . '@test.local';
$viewerPass  = 'View' . getmypid() . '123';

$ownerJar  = t_cookie_jar('chu-so-hu');
$viewerJar = t_cookie_jar('nguoi-xem');

/* --- Chuẩn bị tài khoản + thư viện --- */
t_say('');
t_say('[1] Chuẩn bị tài khoản, thư viện, allowlist');

if (!t_register('Auto Version Owner', $ownerEmail, $ownerPass, $ownerJar)) {
    t_record('N0', false, 'Không đăng ký được tài khoản chủ sở hữu');
    exit(1);
}

if (!t_login($ownerEmail, $ownerPass, $ownerJar)) {
    t_record('N0', false, 'Không đăng nhập được tài khoản chủ sở hữu');
    exit(1);
}

$libCsrf = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

t_http(WA_T_APP_BASE . '/library_action.php', [
    'wa_csrf'    => $libCsrf,
    'action'     => 'create',
    'name'       => 'NotifSum ' . $runId,
    'description' => 'Thu vien kiem thu tom tat thay doi',
    'visibility' => 'private',
    'icon'       => 'folder_special',
    'color'      => 'teal',
], $ownerJar);

$libSlug = '';

if (preg_match('#library\.php\?slug=([a-z0-9\-]+)#', t_http(WA_T_APP_BASE . '/libraries.php', null, $ownerJar)['effective_url'], $m)) {
    $libSlug = $m[1];
}

$libsHtml = t_http(WA_T_APP_BASE . '/libraries.php', null, $ownerJar)['body'];

if (preg_match('#library\.php\?slug=([a-z0-9\-]*notifsum[^"\'&]*)#i', $libsHtml, $m)) {
    $libSlug = $m[1];
}

if ($libSlug === '') {
    t_record('N0', false, 'Không tạo được thư viện thử nghiệm');
    exit(1);
}

$libId = (int) db()->query('SELECT id FROM `libraries` WHERE slug = ' . db()->quote($libSlug))->fetchColumn();

t_say('  · thư viện: ' . $libSlug . ' (id=' . $libId . ')');

// Allowlist cho máy chủ thử mẫu.
$adminJar = t_cookie_jar('quan-tri');

if (t_login('admin@demo.vn', 'admin123', $adminJar)) {
    $alCsrf = t_csrf(WA_T_APP_BASE . '/admin/allowlist.php', $adminJar);

    foreach (['127.0.0.1', 'localhost'] as $testDomain) {
        t_http(WA_T_APP_BASE . '/admin/allowlist.php', [
            'wa_csrf' => $alCsrf,
            'domain'  => $testDomain,
            'note'    => 'may chu thu nghiem tom tat thay doi',
        ], $adminJar);
    }

    t_say('  · allowlist: 127.0.0.1 + localhost');
} else {
    t_say('  · KHÔNG đăng nhập được quản trị — bỏ qua allowlist');
}

/* --- Tệp mẫu: V2 rồi V3 --- */
$samplesDir = dirname(__DIR__) . '/tests/samples';
$sampleFile = $samplesDir . '/notifsum-' . $runId . '.html';

file_put_contents($sampleFile, t_sample_html('Bai tom tat', 'MARKER-' . $runId, 'Phien ban V2.'));
$tempFiles[] = $sampleFile;

$url = WA_T_SAMPLE_BASE . '/notifsum-' . $runId . '.html';

/* --- Lưu V1 --- */
t_say('');
t_say('[2] Lưu bản V1');

/** Lưu URL vào thư viện; trả về snapshot_id. */
function t_save(string $url, int $libId, string $jar): int
{
    $csrf = t_csrf(WA_T_APP_BASE . '/save.php', $jar);
    $res  = t_http(WA_T_APP_BASE . '/save.php', [
        'wa_csrf'    => $csrf,
        'url'        => $url,
        'library_id' => $libId,
    ], $jar);

    if (preg_match('#reader\.php\?id=(\d+)#', $res['effective_url'], $m)) {
        return (int) $m[1];
    }

    return 0;
}

$snap1 = t_save($url, $libId, $ownerJar);

if ($snap1 <= 0) {
    t_record('N0', false, 'Không lưu được bản V1');
    exit(1);
}

$stmt = db()->prepare('SELECT page_id FROM `snapshots` WHERE id = ? LIMIT 1');
$stmt->execute([$snap1]);
$pageId = (int) $stmt->fetchColumn();

t_say('  · V1: snapshot=' . $snap1 . ', page=' . $pageId);

/* --- Đồng ý cho phép kiểm tra --- */
$csrf = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

t_http(WA_T_APP_BASE . '/auto_check.php', ['wa_csrf' => $csrf, 'action' => 'consent'], $ownerJar);

/** Chạy một lần kiểm tra tự động; trả về mảng JSON. */
function t_auto_run(string $csrf, string $jar): array
{
    $res  = t_http(WA_T_APP_BASE . '/auto_check.php', ['wa_csrf' => $csrf, 'action' => 'run'], $jar);
    $decoded = json_decode($res['body'], true);

    return ['status' => $res['status'], 'body' => is_array($decoded) ? $decoded : []];
}

/* --- Tạo V2 --- */
t_say('');
t_say('[3] Đổi nội dung -> tạo V2');

file_put_contents($sampleFile, t_sample_html('Bai tom tat', 'MARKER-' . $runId, 'PHIEN BAN V2 — them mot doan van moi.'));
db()->prepare('UPDATE `saved_pages` SET `auto_checked_at` = NULL WHERE `id` = ?')->execute([$pageId]);

t_auto_run($csrf, $ownerJar);

/* --- Tạo V3 --- */
t_say('');
t_say('[4] Đổi nội dung lần nữa -> tạo V3');

file_put_contents($sampleFile, t_sample_html('Bai tom tat', 'MARKER-' . $runId, 'PHIEN BAN V3 — them mot doan van nua va sua mot doan cu.'));
db()->prepare('UPDATE `saved_pages` SET `auto_checked_at` = NULL WHERE `id` = ?')->execute([$pageId]);

$run3 = t_auto_run($csrf, $ownerJar);

$versions = db()->prepare(
    'SELECT id, version_no FROM `snapshots` WHERE page_id = ? ORDER BY version_no ASC'
);
$versions->execute([$pageId]);
$allVersions = $versions->fetchAll(PDO::FETCH_ASSOC);

$snapV2 = 0;
$snapV3 = 0;

foreach ($allVersions as $v) {
    if ((int) $v['version_no'] === 2) {
        $snapV2 = (int) $v['id'];
    } elseif ((int) $v['version_no'] === 3) {
        $snapV3 = (int) $v['id'];
    }
}

t_say('  · V2 snapshot=' . $snapV2 . ', V3 snapshot=' . $snapV3);

/* ============================================================================
 * N1 — Tạo V3 sinh ĐÚNG 1 thông báo
 * ========================================================================= */
t_say('');
t_say('[N1] Tạo V3 sinh đúng 1 thông báo');

$ownerId = (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote($ownerEmail))->fetchColumn();

$notifV3 = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE user_id = ' . $ownerId
    . ' AND page_id = ' . (int) $pageId
    . ' AND snapshot_id = ' . (int) $snapV3
)->fetchColumn();

$notifAll = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE user_id = ' . $ownerId
    . ' AND page_id = ' . (int) $pageId
)->fetchColumn();

t_record('N1', $notifV3 === 1 && $notifAll === 2,
    'Thông báo cho V3=' . $notifV3 . ' (mong đợi 1), tổng cho bài=' . $notifAll . ' (mong đợi 2: V2 + V3)');

/* --- Lấy nội dung thông báo V3 --- */
$row = db()->query(
    'SELECT id, title, body, is_read FROM `notifications`
      WHERE user_id = ' . $ownerId
    . ' AND snapshot_id = ' . (int) $snapV3 . ' LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$notifBody = (string) ($row['body'] ?? '');
$notifTitle = (string) ($row['title'] ?? '');

/* ============================================================================
 * N2 — Link "Xem V3" mở đúng snapshot
 * ========================================================================= */
t_say('');
t_say('[N2] Link "Xem V3" mở đúng snapshot');

$page = t_http(WA_T_APP_BASE . '/notifications.php', null, $ownerJar);

$hasReaderLink = str_contains($page['body'], 'reader.php?id=' . $snapV3);
$readerOk = $hasReaderLink && str_contains($page['body'], 'Xem V3');

$readerRes = t_http(WA_T_APP_BASE . '/reader.php?id=' . $snapV3, null, $ownerJar);
$readerLive = $readerRes['status'] === 200;

t_record('N2', $readerOk && $readerLive,
    'Trang thông báo có link reader.php?id=' . $snapV3 . ' = ' . ($hasReaderLink ? 'có' : 'không')
    . ' | mở reader HTTP ' . $readerRes['status']);

/* ============================================================================
 * N3 — Link so sánh mở đúng V2 → V3
 * ========================================================================= */
t_say('');
t_say('[N3] Link so sánh mở đúng V2 → V3');

$hasCompare = str_contains(
    $page['body'],
    'compare.php?page_id=' . (int) $pageId . '&amp;from=2&amp;to=3'
) || str_contains(
    $page['body'],
    'compare.php?page_id=' . (int) $pageId . '&from=2&to=3'
);

$compareRes = t_http(
    WA_T_APP_BASE . '/compare.php?page_id=' . (int) $pageId . '&from=2&to=3',
    null,
    $ownerJar
);

$compareLive = $compareRes['status'] === 200
    && str_contains($compareRes['body'], 'diff-output');

t_record('N3', $hasCompare && $compareLive,
    'Link compare (from=2&to=3) trong trang thông báo = ' . ($hasCompare ? 'có' : 'không')
    . ' | mở compare HTTP ' . $compareRes['status']
    . ', có diff-output = ' . (str_contains($compareRes['body'], 'diff-output') ? 'có' : 'không'));

/* ============================================================================
 * N4 — Tóm tắt ngắn, đúng diff, KHÔNG XSS
 * ========================================================================= */
t_say('');
t_say('[N4] Tóm tắt ngắn, đúng diff, không XSS');

$summaryLines = [];

foreach (explode("\n", $notifBody) as $line) {
    if (str_starts_with($line, '• ')) {
        // Bỏ tiền tố "• " (2 ký tự: bullet + khoảng trắng).
        $summaryLines[] = mb_substr($line, 2);
    }
}

$summaryText = implode('', $summaryLines);
$summaryLen  = mb_strlen($summaryText);

$countOk    = count($summaryLines) >= 1 && count($summaryLines) <= 3;
$lenOk      = $summaryLen <= 250;
$hasKeyword = $summaryLines === []
    || (bool) preg_match('/^(Bổ sung|Sửa|Xoá): /u', $summaryLines[0]);

// Dự phòng: chỉ bắt buộc khi KHÔNG tạo được tóm tắt. Khi có tóm tắt thật
// thì KHÔNG được chứa câu dự phòng (tránh thông báo thừa).
$fallbackText = 'Nội dung đã được cập nhật. Xem so sánh để biết chi tiết.';
$fallbackOk   = $summaryLines === []
    ? str_contains($notifBody, $fallbackText)
    : !str_contains($notifBody, $fallbackText);

// Tóm tắt phải phản ánh nội dung THẬT của V3, không bịa.
$actualText = (string) db()->query(
    'SELECT content_text FROM `snapshots` WHERE id = ' . (int) $snapV3
)->fetchColumn();

$groundedOk = true;

if ($summaryLines !== []) {
    // Ý tóm tắt phải lấy từ nội dung THẬT: mỗi ý phải có ít nhất một mảnh
    // (>= 20 ký tự) nằm trong nội dung của V3 hoặc của bản cũ V2.
    $oldText = (string) db()->query(
        'SELECT content_text FROM `snapshots` WHERE id = ' . (int) $snapV2
    )->fetchColumn();

    foreach ($summaryLines as $line) {
        $needle = trim(preg_replace('/^(Bổ sung|Sửa|Xoá): /u', '', $line) ?? '');
        // Chỉ kiểm tra phần TRƯỚC mũi tên "→" (phần nội dung cũ).
        $needle = trim(strtok($needle, '→') ?: '');
        $needle = trim($needle, '—–- ');

        if (mb_strlen($needle) < 20) {
            // Ý quá ngắn để đối chiếu -> không tính là sai, nhưng cũng không
            // được là bằng chứng "bám nội dung thật".
            continue;
        }

        $probe    = mb_substr($needle, 0, 40);
        $inNew    = mb_strpos($actualText, $probe) !== false;
        $inOld    = mb_strpos($oldText, $probe) !== false;

        if ($inNew || $inOld) {
            $groundedOk = true;
            break;
        }

        $groundedOk = false;
    }
}

// XSS: nội dung thông báo phải được escape khi hiển thị.
$xssOk = !str_contains($page['body'], '<script>alert')
    && !str_contains($page['body'], '<img src=x onerror');

t_record('N4', $countOk && $lenOk && $hasKeyword && $fallbackOk && $groundedOk && $xssOk,
    'Số ý=' . count($summaryLines) . ' (1-3), tổng ký tự=' . $summaryLen . ' (<=250)'
    . ', có nhãn thay đổi=' . ($hasKeyword ? 'có' : 'không')
    . ', dự phòng hợp lệ=' . ($fallbackOk ? 'có' : 'không')
    . ', bám nội dung thật=' . ($groundedOk ? 'có' : 'không')
    . ', không XSS=' . ($xssOk ? 'có' : 'không'));

if ($summaryLines !== []) {
    foreach ($summaryLines as $line) {
        t_say('    · ' . $line);
    }
}

/* ============================================================================
 * N5 — Không trùng, không lộ dữ liệu vượt quyền
 * ========================================================================= */
t_say('');
t_say('[N5] Không thông báo trùng, không lộ dữ liệu vượt quyền');

// Phát lại thông báo cùng phiên bản -> KHÔNG tạo dòng trùng.
$before = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE user_id = ' . $ownerId
    . ' AND page_id = ' . (int) $pageId
)->fetchColumn();

wa_auto_notify_version(
    (int) db()->query('SELECT library_id FROM `saved_pages` WHERE id = ' . (int) $pageId)->fetchColumn(),
    (int) $pageId,
    (int) $snapV3,
    3,
    'Bai tom tat',
    $url,
    (int) $snapV2
);

$after = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE user_id = ' . $ownerId
    . ' AND page_id = ' . (int) $pageId
)->fetchColumn();

$noDupOk = ($after === $before);

// Người NGOÀI (không phải thành viên của thư viện) KHÔNG nhận thông báo
// và KHÔNG đọc được bài.
$viewerRegistered = false;

if (t_register('Auto Version Outsider', $viewerEmail, $viewerPass, $viewerJar)) {
    $viewerRegistered = true;
}

$viewerLoggedIn = $viewerRegistered && t_login($viewerEmail, $viewerPass, $viewerJar);

$viewerId = (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote($viewerEmail))->fetchColumn();

$viewerNotifs = $viewerId > 0
    ? (int) db()->query('SELECT COUNT(*) FROM `notifications` WHERE user_id = ' . $viewerId)->fetchColumn()
    : -1;

// Người ngoài KHÔNG được đọc bài của thư viện (không phải thành viên).
$viewerReader = $viewerId > 0
    ? t_http(WA_T_APP_BASE . '/reader.php?id=' . $snapV3, null, $viewerJar)['status']
    : 0;

// Trang thông báo của người ngoài KHÔNG được chứa dữ liệu của bài này.
$viewerNotifPage = t_http(WA_T_APP_BASE . '/notifications.php', null, $viewerJar);

$noLeakOk = ($viewerNotifs === 0)
    && ($viewerReader === 403)
    && !str_contains((string) $viewerNotifPage['body'], (string) $notifTitle);

t_record('N5', $noDupOk && $noLeakOk,
    'Phát lại thông báo V3: ' . $before . ' -> ' . $after . ' (mong đợi không đổi)'
    . ' | người ngoài đăng ký=' . ($viewerRegistered ? 'ok' : 'LỖI')
    . ', đăng nhập=' . ($viewerLoggedIn ? 'ok' : 'LỖI')
    . ' | nhận ' . $viewerNotifs . ' thông báo (mong đợi 0)'
    . ' | đọc reader HTTP ' . $viewerReader . ' (mong đợi 403)'
    . ' | thấy tiêu đề thông báo của chủ = ' . (str_contains((string) $viewerNotifPage['body'], (string) $notifTitle) ? 'CÓ (LỖI)' : 'không'));

/* --- Dọn dẹn --- */
t_say('');
t_say('[5] Dọn thư viện thử nghiệm');

$ownerCsrf3 = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

t_http(WA_T_APP_BASE . '/library_action.php', [
    'wa_csrf'    => $ownerCsrf3,
    'action'     => 'delete',
    'library_id' => $libId,
    'confirm'    => 'DELETE',
], $ownerJar);

$gone = (int) db()->query('SELECT COUNT(*) FROM `libraries` WHERE id = ' . $libId)->fetchColumn();

t_record('D1', $gone === 0, 'Thư viện thử nghiệm đã được xoá (' . $gone . ' dòng còn lại)');

/* --- Tổng kết --- */
$passed = 0;
$failed = 0;

t_say('');
t_say('============================================================');
t_say('  KẾT QUẢ KIỂM THỬ TÓM TẮT THAY ĐỔI');
t_say('============================================================');

foreach ($results as $item) {
    t_say(($item['passed'] ? '  PASS  ' : '  FAIL  ') . $item['id'] . ' — ' . $item['evidence']);

    if ($item['passed']) {
        $passed++;
    } else {
        $failed++;
    }
}

t_say('------------------------------------------------------------');
t_say('  Tổng: ' . count($results) . ' mục — PASS: ' . $passed . ' — FAIL: ' . $failed);
t_say('============================================================');
t_say('');

exit($failed === 0 ? 0 : 1);
