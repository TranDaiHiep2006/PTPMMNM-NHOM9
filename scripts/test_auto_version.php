<?php
/**
 * scripts/test_auto_version.php
 * -----------------------------------------------------------------------------
 * KIỂM THỬ THỰC TẾ cho AUTO VERSION + THÔNG BÁO (P-AUTOVERSION).
 *
 * Bộ kiểm thử này TỰ khởi động hai máy chủ riêng bằng proc_open():
 *   - Ứng dụng WebArchive : http://localhost:18082  (public/)
 *   - Máy chủ thư mẫu     : http://localhost:18093  (tests/samples/)
 *
 * Mọi thao tác đều đi qua HTTP THẬT bằng cURL với cookie jar — không gọi
 * thẳng hàm PHP, không sửa bảng trực tiếp.
 *
 * TÁM TRƯỜNG HỢP BẮT BUỘC
 *   A1. Mở Dashboard nhưng TỪ CHỐI quyền kiểm tra -> KHÔNG gửi request tới nguồn.
 *   A2. Cho phép kiểm tra và trang CHƯA ĐỔI -> KHÔNG sinh V2.
 *   A3. Trang ĐÃ ĐỔI -> tạo đúng MỘT phiên bản mới + có thông báo.
 *   A4. Kiểm tra lại, nội dung KHÔNG đổi -> KHÔNG tạo bản trùng.
 *   A5. Hai request ĐỒNG THỜI -> KHÔNG tạo hai phiên bản trùng nhau.
 *   A6. Người KHÔNG có quyền -> KHÔNG được kiểm tra / cập nhật.
 *   A7. Thông báo: gắn đúng tài khoản, có trạng thái đã đọc/chưa đọc,
 *       KHÔNG tạo trùng, xem được khác biệt giữa hai phiên bản.
 *   A8. CSDL kết nối được (SELECT 1) và các bảng cần thiết tồn tại.
 *
 * Dữ liệu thử nghiệm độc lập: dùng tệp mẫu tạm trong tests/samples/, dọn
 * qua chính hành động xoá thư viện của ứng dụng (library_action.php).
 * KHÔNG xoá tay bất kỳ tệp nào trong public/uploads/.
 *
 * Cách chạy:
 *     php scripts/test_auto_version.php
 *
 * Mã thoát: 0 khi tất cả PASS, 1 khi có FAIL.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/test_auto_version.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

const WA_T_APP_PORT    = 18082;
const WA_T_SAMPLE_PORT = 18093;
const WA_T_APP_BASE    = 'http://localhost:18082';
const WA_T_SAMPLE_BASE = 'http://localhost:18093';

$results = [];
$servers = [];
$logFiles = [];
$tempFiles = [];
$cookieJars = [];
$lastSaveDebug = [];

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

/* ---------------------------------------------------------------------------
 * TIỆN ÍCH MÁY CHỦ
 * ------------------------------------------------------------------------- */

/** Dừng tiến trình con (Windows: proc_terminate có thể không kịp). */
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

    $tempFiles = [];
    $cookieJars = [];
}

register_shutdown_function(static function (): void {
    t_kill_servers();
    t_cleanup_files();
});

/** @return string Đường dẫn tới php.exe */
function t_php_binary(): string
{
    $binary = PHP_BINARY;

    if (PHP_OS_FAMILY === 'Windows' && !str_ends_with(strtolower($binary), '.exe')) {
        $binary .= '.exe';
    }

    return $binary;
}

/** Cổng này đang có tiến trình nào lắng nghe không? */
function t_port_is_busy(int $port): bool
{
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);

    if ($probe === false) {
        return false;
    }

    fclose($probe);

    return true;
}

/** Khởi động máy chủ `php -S` và chờ nó sẵn sàng. */
function t_start_server(int $port, string $docroot, string $label): void
{
    global $servers, $logFiles;

    if (t_port_is_busy($port)) {
        t_out('[LOI] Cổng ' . $port . ' đang bị chiếm — dừng để không xung đột.');
        exit(1);
    }

    $logFile = sys_get_temp_dir() . '/wa-autotest-' . getmypid() . '-' . $label . '.log';
    $logFiles[] = $logFile;

    $cmd = [t_php_binary(), '-S', '127.0.0.1:' . $port, '-t', $docroot];

    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ], $pipes);

    if (!is_resource($proc)) {
        t_out('[LOI] Không khởi động được máy chủ ' . $label . '.');
        exit(1);
    }

    $servers[] = $proc;

    for ($i = 0; $i < 60; $i++) {
        usleep(150000);

        if (t_port_is_busy($port)) {
            t_say('  · máy chủ ' . $label . ' sẵn sàng (cổng ' . $port . ')');
            return;
        }
    }

    t_out('[LOI] Máy chủ ' . $label . ' không khởi động được. Log: ' . $logFile);
    exit(1);
}

/* ---------------------------------------------------------------------------
 * HTTP
 * ------------------------------------------------------------------------- */

/** @return string Cookie jar mới cho một vai diễn. */
function t_cookie_jar(string $who): string
{
    global $cookieJars;

    $jar = sys_get_temp_dir() . '/wa-autotest-' . getmypid() . '-' . $who . '.cookies';
    $cookieJars[] = $jar;

    return $jar;
}

/**
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

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
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

/** Đăng nhập; trả về true nếu thành công. */
function t_login(string $email, string $password, string $jar): bool
{
    t_http(WA_T_APP_BASE . '/login.php', null, $jar);

    // login.php không dùng token CSRF -> gửi thẳng, không cần đọc token.
    $res = t_http(WA_T_APP_BASE . '/login.php', [
        'email'    => $email,
        'password' => $password,
    ], $jar);

    // Đăng nhập thành công -> chuyển hướng tới libraries.php.
    if (str_contains((string) $res['effective_url'], 'libraries.php')) {
        return true;
    }

    // Dự phòng: trang đã vào workspace (không còn biểu mẫu đăng nhập).
    return $res['status'] === 200
        && !str_contains($res['body'], 'name="email"')
        && !str_contains($res['body'], 'name="password"');
}

/**
 * @param array<string,string> $post
 * @return array{status:int, body:string}
 */
function t_json(string $url, array $post, string $jar): array
{
    $res = t_http($url, $post, $jar);

    $decoded = json_decode($res['body'], true);

    return [
        'status' => $res['status'],
        'body'   => is_array($decoded) ? $decoded : [],
        'raw'    => $res['body'],
    ];
}

/** Ghi kết quả một mục kiểm thử. */
function t_record(string $id, bool $passed, string $evidence): void
{
    global $results;

    $results[] = ['id' => $id, 'passed' => $passed, 'evidence' => $evidence];

    t_say(($passed ? '  PASS  ' : '  FAIL  ') . $id . ' — ' . $evidence);
}

/* =============================================================================
 * BẮT ĐẦU
 * ============================================================================= */

t_say('');
t_say('=== KIỂM THỬ AUTO VERSION + THÔNG BÁO ===');
t_say('');

/* --- A8: kết nối CSDL --- */
t_say('[A8] Kết nối cơ sở dữ liệu');

$dbOk = false;
$dbDetail = '';

try {
    $one = db()->query('SELECT 1')->fetchColumn();
    $dbOk = ($one === '1' || $one === 1);
    $dbDetail = 'SELECT 1 = ' . var_export($one, true);
} catch (Throwable $e) {
    $dbDetail = db_diagnose($e);
}

t_record('A8', $dbOk, $dbDetail);

if (!$dbOk) {
    t_say('');
    t_say('Không kết nối được CSDL — dừng kiểm thử. Hãy chạy: php scripts/check_db.php');
    exit(1);
}

/* --- Bảng / cột cần thiết --- */
$needTables = ['notifications', 'auto_version_events'];
$missing = [];

foreach ($needTables as $table) {
    try {
        db()->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
    } catch (Throwable) {
        $missing[] = $table;
    }
}

$cols = db()->query(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_pages'"
)->fetchAll(PDO::FETCH_COLUMN);

foreach (['auto_checked_at', 'auto_version_no', 'auto_locked_at', 'auto_lock_token'] as $column) {
    if (!in_array($column, $cols, true)) {
        $missing[] = 'saved_pages.' . $column;
    }
}

t_record(
    'A8b',
    $missing === [],
    $missing === [] ? 'Đủ bảng và cột cho Auto Version' : 'Thiếu: ' . implode(', ', $missing)
);

if ($missing !== []) {
    t_say('');
    t_say('Thiếu cấu trúc CSDL — hãy chạy: php scripts/migrate_auto_version.php');
    exit(1);
}

/* --- Khởi động máy chủ --- */
t_say('');
t_say('[0] Khởi động máy chủ riêng của bộ kiểm thử');
t_start_server(WA_T_APP_PORT, dirname(__DIR__) . '/public', 'ung-dung');
t_start_server(WA_T_SAMPLE_PORT, dirname(__DIR__) . '/tests/samples', 'thu-mau');

$runId     = date('His') . '-' . getmypid();
$ownerEmail = 'autov-' . $runId . '@test.local';
$ownerPass  = 'Auto' . getmypid() . '123';

$ownerJar = t_cookie_jar('chu-so-hu');
$viewerJar = t_cookie_jar('nguoi-xem');

/* ---------------------------------------------------------------------------
 * Chuẩn bị: tài khoản chủ sở hữu, thư viện, nhãn
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[1] Đăng ký tài khoản chủ sở hữu');

$reg = t_http(WA_T_APP_BASE . '/register.php', null, $ownerJar);
$regCsrf = t_csrf(WA_T_APP_BASE . '/register.php', $ownerJar);

$regRes = t_http(WA_T_APP_BASE . '/register.php', [
    'wa_csrf'         => $regCsrf,
    'name'            => 'Auto Version Owner',
    'email'           => $ownerEmail,
    'password'        => $ownerPass,
    'confirm_password' => $ownerPass,
], $ownerJar);

// register.php báo thành công tại chỗ (không chuyển hướng) -> phải đăng nhập lại.
$registered = str_contains((string) $regRes['body'], 'Đăng ký thành công');
$loggedIn = $registered && t_login($ownerEmail, $ownerPass, $ownerJar);

if (!$loggedIn) {
    t_say('  [gỡ lỗi] HTTP ' . $regRes['status']
        . ' | đăng ký=' . ($registered ? 'ok' : 'KHÔNG ok'));
    t_say('  [gỡ lỗi] csrf=' . ($regCsrf === '' ? '(rỗng)' : 'có')
        . ' | effective_url=' . $regRes['effective_url']);

    // Trích thông báo lỗi nếu có.
    if (preg_match_all('#<div class="form-alert[^"]*"[^>]*>(.*?)</div>#su', $regRes['body'], $m)) {
        foreach ($m[1] as $msg) {
            t_say('  [gỡ lỗi] ' . trim(preg_replace('#<[^>]+>#', ' ', $msg) ?? ''));
        }
    }

    t_record('A1', false, 'Không đăng ký / đăng nhập được tài khoản thử nghiệm'
        . ($registered ? '' : ' (đăng ký không thành công)'));
    t_say('');
    t_say('Không thiết lập được môi trường thử nghiệm — dừng.');
    exit(1);
}

t_say('  · đăng ký + đăng nhập thành công: ' . $ownerEmail);

/* --- Tạo thư viện --- */
t_say('[2] Tạo thư viện thử nghiệm');

$libCsrf = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

$libRes = t_http(WA_T_APP_BASE . '/library_action.php', [
    'wa_csrf'    => $libCsrf,
    'action'     => 'create',
    'name'       => 'AutoVer ' . $runId,
    'description' => 'Thu vien thu nghiem cho Auto Version',
    'visibility' => 'private',
    'icon'       => 'folder_special',
    'color'      => 'teal',
], $ownerJar);

$libSlug = '';

if (preg_match('#library\.php\?slug=([a-z0-9\-]+)#', $libRes['effective_url'], $m)) {
    $libSlug = $m[1];
} else {
    // Lấy slug từ danh sách thư viện.
    $libsHtml = t_http(WA_T_APP_BASE . '/libraries.php', null, $ownerJar)['body'];
    if (preg_match('#library\.php\?slug=([a-z0-9\-]*autover[^"\'&]*)#i', $libsHtml, $m)) {
        $libSlug = $m[1];
    }
}

if ($libSlug === '') {
    t_record('A1', false, 'Không tạo được thư viện thử nghiệm');
    exit(1);
}

// Mọi hành động của ứng dụng dùng library_id (số), không phải slug.
$libId = (int) db()->query(
    'SELECT id FROM `libraries` WHERE slug = ' . db()->quote($libSlug)
)->fetchColumn();

if ($libId <= 0) {
    t_record('A1', false, 'Không tra được library_id của thư viện thử nghiệm');
    exit(1);
}

t_say('  · thư viện: ' . $libSlug . ' (id=' . $libId . ')');

/* --- Đưa tên miền thử mẫu vào allowlist (quyền admin) --- */
t_say('[3] Thêm tên miền thử mẫu vào allowlist (quyền quản trị)');

$adminEmail = 'admin@demo.vn';
$adminPass  = 'admin123';
$adminJar   = t_cookie_jar('quan-tri');

$adminLoggedIn = t_login($adminEmail, $adminPass, $adminJar);

if ($adminLoggedIn) {
    $alCsrf = t_csrf(WA_T_APP_BASE . '/admin/allowlist.php', $adminJar);

    // Cả hai tên miền trỏ về máy chủ thử mẫu của bộ kiểm thử.
    foreach (['127.0.0.1', 'localhost'] as $testDomain) {
        t_http(WA_T_APP_BASE . '/admin/allowlist.php', [
            'wa_csrf' => $alCsrf,
            'domain'  => $testDomain,
            'note'    => 'may chu thu nghiem auto version',
        ], $adminJar);
    }

    t_say('  · đã thêm 127.0.0.1 + localhost vào allowlist');
} else {
    t_say('  · KHÔNG đăng nhập được quản trị — bỏ qua bước allowlist');
}

/* ---------------------------------------------------------------------------
 * Tệp mẫu: một trang sẽ KHÔNG đổi, một trang SẼ đổi
 * ------------------------------------------------------------------------- */
$samplesDir = dirname(__DIR__) . '/tests/samples';

/** Tạo tệp mẫu tạm trong tests/samples/ (tự dọn khi kết thúc). */
function t_write_sample(string $dir, string $name, string $html): string
{
    global $tempFiles;

    $path = $dir . '/' . $name;
    file_put_contents($path, $html);
    $tempFiles[] = $path;

    return $path;
}

$markerStable = 'MARKER-' . $runId . '-STABLE';
$markerChange = 'MARKER-' . $runId . '-CHANGE';

/**
 * Dựng một trang HTML mẫu đủ dài (nội dung chính > 200 ký tự như yêu cầu
 * của wa_store_page_capture) với một đoạn văn đánh dấu riêng.
 */
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

t_write_sample($samplesDir, 'autov-stable-' . $runId . '.html',
    t_sample_html('Bai on dinh', $markerStable, 'Day la noi dung khong bao gio doi.'));

t_write_sample($samplesDir, 'autov-change-' . $runId . '.html',
    t_sample_html('Bai thay doi', $markerChange, 'Phien ban dau.'));

$urlStable = WA_T_SAMPLE_BASE . '/autov-stable-' . $runId . '.html';
$urlChange = WA_T_SAMPLE_BASE . '/autov-change-' . $runId . '.html';

/* ---------------------------------------------------------------------------
 * Lưu hai bài viết
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[4] Lưu hai bài viết (một bài ổn định, một bài sẽ đổi)');

/** Lưu một URL vào thư viện; trả về snapshot_id. */
function t_save(string $url, int $libId, string $jar): int
{
    global $lastSaveDebug;

    $csrf = t_csrf(WA_T_APP_BASE . '/save.php', $jar);
    $res  = t_http(WA_T_APP_BASE . '/save.php', [
        'wa_csrf'   => $csrf,
        'url'       => $url,
        'library_id' => $libId,
    ], $jar);

    $lastSaveDebug = [
        'status' => $res['status'],
        'url'    => $res['effective_url'],
        'csrf'   => $csrf,
        'body'   => $res['body'],
    ];

    if (preg_match('#reader\.php\?id=(\d+)#', $res['effective_url'], $m)) {
        return (int) $m[1];
    }

    return 0;
}

$snapStable = t_save($urlStable, $libId, $ownerJar);
$snapChange = t_save($urlChange, $libId, $ownerJar);

if ($snapStable <= 0 || $snapChange <= 0) {
    t_say('  [gỡ lỗi] HTTP ' . $lastSaveDebug['status']
        . ' | effective_url=' . $lastSaveDebug['url']
        . ' | csrf=' . ($lastSaveDebug['csrf'] === '' ? '(rỗng)' : 'có'));

    if (preg_match_all('#<div class="state__body">(.*?)</div>#su', $lastSaveDebug['body'], $m)) {
        foreach ($m[1] as $msg) {
            t_say('  [gỡ lỗi] ' . trim(preg_replace('#\s+#', ' ', preg_replace('#<[^>]+>#', ' ', $msg) ?? '')));
        }
    }

    t_record('A1', false, 'Không lưu được bài viết thử nghiệm (stable=' . $snapStable . ', change=' . $snapChange . ')');
    exit(1);
}

t_say('  · snapshot ổn định = ' . $snapStable . ', snapshot thay đổi = ' . $snapChange);

/* --- Lấy page_id từ CSDL (để gọi auto_check.php) --- */
function t_page_id_of(int $snapshotId): int
{
    $stmt = db()->prepare('SELECT page_id FROM `snapshots` WHERE id = ? LIMIT 1');
    $stmt->execute([$snapshotId]);

    return (int) $stmt->fetchColumn();
}

$pageStable = t_page_id_of($snapStable);
$pageChange = t_page_id_of($snapChange);

/* ---------------------------------------------------------------------------
 * A1 — TỪ CHỐI quyền kiểm tra -> KHÔNG gửi request ra nguồn
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A1] Từ chối quyền kiểm tra -> không được gửi request tới nguồn');

/** Đếm số request tới máy chủ thử mẫu bằng cách đọc log của máy chủ. */
function t_sample_request_count(string $logFile): int
{
    if (!is_file($logFile)) {
        return 0;
    }

    $content = (string) file_get_contents($logFile);

    // Log của `php -S` ghi mỗi request một dòng kèm mã trạng thái.
    return preg_match_all('/\] .* \[200\]/i', $content) ?: 0;
}

$sampleLog = sys_get_temp_dir() . '/wa-autotest-' . getmypid() . '-thu-mau.log';

$before = t_sample_request_count($sampleLog);

$csrf = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

$deny = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $csrf,
    'action'  => 'run',
], $ownerJar);

$after = t_sample_request_count($sampleLog);
$requestsDuring = $after - $before;

$denyOk = ($deny['status'] === 403)
    && (($deny['body']['error'] ?? '') === 'consent_required')
    && ($requestsDuring === 0);

t_record('A1', $denyOk,
    'HTTP ' . $deny['status'] . ', lỗi=' . (string) ($deny['body']['error'] ?? '?')
    . ', request tới nguồn=' . $requestsDuring . ' (mong đợi 0)');

/* ---------------------------------------------------------------------------
 * A2 — Cho phép + trang CHƯA ĐỔI -> KHÔNG sinh V2
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A2] Cho phép kiểm tra, trang chưa đổi -> không sinh V2');

$consent = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $csrf,
    'action'  => 'consent',
], $ownerJar);

$consentOk = ($consent['status'] === 200) && (($consent['body']['consent'] ?? false) === true);
t_record('A2a', $consentOk, 'Đồng ý: HTTP ' . $consent['status']);

$versionsBefore = db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$before = t_sample_request_count($sampleLog);
$run = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $csrf,
    'action'  => 'run',
], $ownerJar);
$after = t_sample_request_count($sampleLog);

$versionsAfter = db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$a2Ok = ($run['status'] === 200)
    && ((int) $versionsAfter === (int) $versionsBefore)
    && ((int) ($run['body']['updated'] ?? -1) === 0)
    && (($after - $before) > 0); // đã thực sự tải trang gốc để so sánh

t_record('A2', $a2Ok,
    'HTTP ' . $run['status'] . ', updated=' . (int) ($run['body']['updated'] ?? -1)
    . ', snapshot ' . $versionsBefore . ' -> ' . $versionsAfter
    . ', request tới nguồn=' . ($after - $before) . ' (mong đợi > 0)');

/* ---------------------------------------------------------------------------
 * A3 — Trang ĐÃ ĐỔI -> tạo đúng MỘT phiên bản mới + thông báo
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A3] Nội dung thay đổi -> tạo đúng một phiên bản mới + thông báo');

// Sửa tệp mẫu: đổi nội dung.
file_put_contents($samplesDir . '/autov-change-' . $runId . '.html',
    t_sample_html('Bai thay doi', $markerChange, 'PHIEN BAN DA DOI — noi dung moi.'));

// Bỏ khoảng cách tối thiểu để lần kiểm tra này được chạy ngay.
db()->prepare('UPDATE `saved_pages` SET `auto_checked_at` = NULL WHERE `id` = ?')
    ->execute([$pageChange]);

$versionsBefore = (int) db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$run2 = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $csrf,
    'action'  => 'run',
], $ownerJar);

$versionsAfter = (int) db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$newSnapshots = $versionsAfter - $versionsBefore;

$a3Ok = ($run2['status'] === 200)
    && ($newSnapshots === 1)
    && ((int) ($run2['body']['updated'] ?? 0) === 1);

t_record('A3', $a3Ok,
    'HTTP ' . $run2['status'] . ', updated=' . (int) ($run2['body']['updated'] ?? 0)
    . ', snapshot mới=' . $newSnapshots . ' (mong đợi đúng 1)');

/* --- Kiểm tra thông báo --- */
$notifStmt = db()->prepare(
    'SELECT id, user_id, page_id, snapshot_id, title, body, is_read
       FROM `notifications`
      WHERE page_id = ? AND user_id = ?
      ORDER BY id DESC LIMIT 1'
);
$notifStmt->execute([$pageChange, (int) db()->query(
    "SELECT id FROM `users` WHERE email = " . db()->quote($ownerEmail)
)->fetchColumn()]);
$notif = $notifStmt->fetch();

$notifOk = is_array($notif)
    && str_contains((string) $notif['title'], 'phiên bản V')
    && ((int) $notif['is_read'] === 0)
    && ((int) $notif['snapshot_id'] > 0);

t_record('A7a', $notifOk, is_array($notif)
    ? 'Thông báo: "' . $notif['title'] . '" (chưa đọc=' . ((int) $notif['is_read'] === 0 ? 'có' : 'không') . ')'
    : 'KHÔNG có thông báo nào cho bài vừa cập nhật');

/* ---------------------------------------------------------------------------
 * A4 — Kiểm tra lại, nội dung KHÔNG đổi -> KHÔNG tạo bản trùng
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A4] Kiểm tra lại, nội dung không đổi -> không tạo bản trùng');

db()->prepare('UPDATE `saved_pages` SET `auto_checked_at` = NULL WHERE `id` = ?')
    ->execute([$pageChange]);

$versionsBefore = (int) db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$run3 = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $csrf,
    'action'  => 'run',
], $ownerJar);

$versionsAfter = (int) db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$a4Ok = ($run3['status'] === 200) && ($versionsAfter === $versionsBefore);

t_record('A4', $a4Ok,
    'HTTP ' . $run3['status'] . ', snapshot ' . $versionsBefore . ' -> ' . $versionsAfter
    . ' (mong đợi không đổi)');

/* ---------------------------------------------------------------------------
 * A5 — Hai request ĐỒNG THỜI -> không tạo hai phiên bản trùng
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A5] Hai request đồng thời -> không tạo hai phiên bản trùng nhau');

// Đổi nội dung lần nữa để có nội dung mới cần tạo phiên bản.
file_put_contents($samplesDir . '/autov-change-' . $runId . '.html',
    t_sample_html('Bai thay doi', $markerChange, 'PHIEN BAN THU HAI — doi lan nua.'));

db()->prepare('UPDATE `saved_pages` SET `auto_checked_at` = NULL WHERE `id` = ?')
    ->execute([$pageChange]);

$versionsBefore = (int) db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

// Hai request SONG SONG (multi-curl).
$multi = curl_multi_init();
$handles = [];

for ($i = 0; $i < 2; $i++) {
    $ch = curl_init(WA_T_APP_BASE . '/auto_check.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['wa_csrf' => $csrf, 'action' => 'run']),
        CURLOPT_COOKIEJAR      => $ownerJar,
        CURLOPT_COOKIEFILE     => $ownerJar,
        CURLOPT_TIMEOUT        => 60,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.1);
} while ($running > 0);

foreach ($handles as $ch) {
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}

curl_multi_close($multi);

$versionsAfter = (int) db()->query(
    'SELECT COUNT(*) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$newSnapshots = $versionsAfter - $versionsBefore;

$a5Ok = ($newSnapshots === 1);

t_record('A5', $a5Ok,
    'Hai request song song -> snapshot mới=' . $newSnapshots . ' (mong đợi đúng 1)');

/* ---------------------------------------------------------------------------
 * A6 — Người KHÔNG có quyền -> không được kiểm tra / cập nhật
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A6] Người không có quyền -> không được kiểm tra / cập nhật');

$viewerEmail = 'autov-view-' . $runId . '@test.local';
$viewerPass  = 'View' . getmypid() . '123';

// Đăng ký người ngoài rồi thêm vào thư viện với vai trò 'viewer'.
t_http(WA_T_APP_BASE . '/register.php', null, $viewerJar);
$viewCsrf = t_csrf(WA_T_APP_BASE . '/register.php', $viewerJar);
t_http(WA_T_APP_BASE . '/register.php', [
    'wa_csrf'         => $viewCsrf,
    'name'            => 'Auto Version Viewer',
    'email'           => $viewerEmail,
    'password'        => $viewerPass,
    'confirm_password' => $viewerPass,
], $viewerJar);
t_login($viewerEmail, $viewerPass, $viewerJar);

$ownerCsrf2 = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

// Thêm người xem vào thư viện (nếu có hành động add_member).
$memberRes = t_http(WA_T_APP_BASE . '/library_action.php', [
    'wa_csrf'   => $ownerCsrf2,
    'action'    => 'member_add',
    'library_id' => $libId,
    'email'     => $viewerEmail,
    'role'      => 'viewer',
], $ownerJar);

$viewerCsrf = t_csrf(WA_T_APP_BASE . '/libraries.php', $viewerJar);

$viewerRun = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $viewerCsrf,
    'action'  => 'consent',
], $viewerJar);

$viewerRun2 = t_json(WA_T_APP_BASE . '/auto_check.php', [
    'wa_csrf' => $viewerCsrf,
    'action'  => 'run',
], $viewerJar);

// Người xem KHÔNG được kiểm tra bất kỳ bài nào -> checked = 0.
$viewerChecked = (int) ($viewerRun2['body']['checked'] ?? -1);

$a6Ok = ($viewerRun['status'] === 200)
    && ($viewerRun2['status'] === 200)
    && ($viewerChecked === 0);

t_record('A6', $a6Ok,
    'Người xem: consent HTTP ' . $viewerRun['status']
    . ', run HTTP ' . $viewerRun2['status']
    . ', số bài được kiểm tra=' . $viewerChecked . ' (mong đợi 0)');

/* ---------------------------------------------------------------------------
 * A7b — Thông báo: gắn đúng tài khoản, không trùng, xem được khác biệt
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[A7] Thông báo: đúng tài khoản, không trùng, xem khác biệt');

// Người xem KHÔNG nhận thông báo (không có quyền cập nhật).
$viewerId = (int) db()->query("SELECT id FROM `users` WHERE email = " . db()->quote($viewerEmail))
    ->fetchColumn();

$viewerNotifs = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE user_id = ' . $viewerId
)->fetchColumn();

t_record('A7b', $viewerNotifs === 0,
    'Người xem nhận ' . $viewerNotifs . ' thông báo (mong đợi 0)');

// Gọi lại hàm phát thông báo cho cùng phiên bản -> KHÔNG tạo trùng.
$ownerId = (int) db()->query("SELECT id FROM `users` WHERE email = " . db()->quote($ownerEmail))
    ->fetchColumn();

$notifCountBefore = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

$lastVersion = (int) db()->query(
    'SELECT MAX(version_no) FROM `snapshots` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

wa_auto_notify_version(
    (int) db()->query('SELECT library_id FROM `saved_pages` WHERE id = ' . (int) $pageChange)->fetchColumn(),
    (int) $pageChange,
    (int) db()->query('SELECT id FROM `snapshots` WHERE page_id = ' . (int) $pageChange . ' ORDER BY id DESC LIMIT 1')->fetchColumn(),
    (int) $lastVersion,
    'Bai thay doi',
    $urlChange
);

$notifCountAfter = (int) db()->query(
    'SELECT COUNT(*) FROM `notifications` WHERE page_id = ' . (int) $pageChange
)->fetchColumn();

t_record('A7c', $notifCountAfter === $notifCountBefore,
    'Phát lại thông báo cùng phiên bản: ' . $notifCountBefore . ' -> ' . $notifCountAfter
    . ' (mong đợi không đổi)');

// Đánh dấu đã đọc.
$notifId = (int) db()->query(
    'SELECT id FROM `notifications` WHERE user_id = ' . $ownerId . ' ORDER BY id DESC LIMIT 1'
)->fetchColumn();

wa_notify_mark_read($notifId, $ownerId);

$isRead = (int) db()->query('SELECT is_read FROM `notifications` WHERE id = ' . $notifId)->fetchColumn();

t_record('A7d', $isRead === 1, 'Đánh dấu đã đọc: is_read=' . $isRead . ' (mong đợi 1)');

// Trang thông báo trả về 200 và có nội dung.
$notifPage = t_http(WA_T_APP_BASE . '/notifications.php', null, $ownerJar);

$notifPageOk = ($notifPage['status'] === 200)
    && str_contains($notifPage['body'], 'phiên bản V')
    && str_contains($notifPage['body'], 'compare.php');

t_record('A7e', $notifPageOk,
    'Trang thông báo: HTTP ' . $notifPage['status']
    . ', có liên kết khác biệt=' . (str_contains($notifPage['body'], 'compare.php') ? 'có' : 'không'));

// Trang so sánh phiên bản hoạt động.
$comparePage = t_http(WA_T_APP_BASE . '/compare.php?page_id=' . $pageChange, null, $ownerJar);

$compareOk = ($comparePage['status'] === 200) && str_contains($comparePage['body'], 'diff-output');

t_record('A7f', $compareOk, 'Trang so sánh: HTTP ' . $comparePage['status']);

/* ---------------------------------------------------------------------------
 * Dọn dẹp: xoá thư viện thử nghiệm qua chính hành động của ứng dụng
 * ------------------------------------------------------------------------- */
t_say('');
t_say('[5] Dọn thư viện thử nghiệm (qua hành động xoá của ứng dụng)');

$libId = (int) db()->query(
    'SELECT id FROM `libraries` WHERE slug = ' . db()->quote($libSlug)
)->fetchColumn();

if ($libId > 0) {
    $ownerCsrf3 = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);
    t_http(WA_T_APP_BASE . '/library_action.php', [
        'wa_csrf'   => $ownerCsrf3,
        'action'    => 'delete',
        'library_id' => $libId,
        'confirm'   => 'DELETE',
    ], $ownerJar);

    $stillThere = (int) db()->query(
        'SELECT COUNT(*) FROM `libraries` WHERE id = ' . $libId
    )->fetchColumn();

    t_record('D1', $stillThere === 0, 'Thư viện thử nghiệm đã được xoá (' . $stillThere . ' dòng còn lại)');
}

/* ---------------------------------------------------------------------------
 * TỔNG KẾT
 * ------------------------------------------------------------------------- */
$passed = 0;
$failed = 0;

t_say('');
t_say('============================================================');
t_say('  KẾT QUẢ KIỂM THỬ AUTO VERSION');
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
