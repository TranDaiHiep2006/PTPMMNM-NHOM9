<?php
/**
 * scripts/test_extension_chrome.php
 * ---------------------------------------------------------------------------
 * KIỂM THỬ CHROME EXTENSION THẬT (HEADLESS) — BẢN V3 DÙNG CDP `Extensions.loadUnpacked`.
 *
 * VÌ SAO KHÔNG DÙNG `--load-extension`?
 * ------------------------------------
 * Từ Chrome 137, cờ `--load-extension` bị bỏ qua trong chế độ headless (Chrome
 * chỉ nạp extension "đã cài" từ profile). Vì vậy bản cũ luôn dừng ở E2
 * (không thấy service worker của extension).
 *
 * Chrome 158 vẫn cung cấp lệnh CDP `Extensions.loadUnpacked` (yêu cầu cờ
 * `--enable-unsafe-extension-debugging`). Lệnh này NẠP THẬT extension từ thư
 * mục dự án và trả về extension id — sau đó mọi kiểm thử chạy đúng trên
 * mã nguồn thật của dự án.
 *
 * Môi trường: KHÔNG mở Chrome giao diện trên máy người dùng, KHÔNG tạo Task
 * Scheduler / cron / tiến trình CMD tự chạy. Chỉ dùng Chrome headless + DevTools
 * Protocol trên cổng cục bộ.
 *
 * CÁCH CHẠY:
 *     php scripts/test_extension_chrome.php
 *
 * Mã thoát: 0 khi tất cả PASS, 1 khi có FAIL, 2 khi BLOCKED (không giả PASS).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/test_extension_chrome.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

const WA_E_APP_PORT    = 18088;
const WA_E_SAMPLE_PORT = 18089;

// Bộ kiểm thử cần tải trang nội bộ (localhost) -> bật chế độ kiểm thử của
// chính sách URL. Chế độ này CHỈ nới lỏng loopback và KHÔNG nới lỏng gì khác.
if (getenv('WA_TEST_ALLOW_LOCAL') !== '1') {
    putenv('WA_TEST_ALLOW_LOCAL=1');
}

$repoRoot = dirname(__DIR__);
$extDir   = $repoRoot . '/chrome-extension';

/* ---------------------------------------------------------------------------
 * Kết quả
 * ------------------------------------------------------------------------ */

$results = [];
$appProc = null;
$sampleProc = null;
$nodeProc = null;
$runnerPort = 0;
$sampleDir = '';
$profile = sys_get_temp_dir() . '/wa-chrome-prof-' . getmypid();

function e_say(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function e_record(string $id, bool $passed, string $evidence): void
{
    global $results;

    $results[] = ['id' => $id, 'passed' => $passed, 'evidence' => $evidence];

    e_say(($passed ? '  PASS  ' : '  FAIL  ') . $id . ' — ' . $evidence);
}

function e_blocked(string $reason): void
{
    e_say('');
    e_say('  BLOCKED — ' . $reason);
    e_say('');
    e_say('============================================================');
    e_say('  KẾT QUẢ: BLOCKED (không giả PASS)');
    e_say('============================================================');
    exit(2);
}

/**
 * Tìm Chrome trên Windows.
 */
function e_find_chrome(): string
{
    $candidates = [
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        (getenv('LOCALAPPDATA') ?: '') . '\Google\Chrome\Application\chrome.exe',
    ];

    foreach ($candidates as $c) {
        if ($c !== '' && is_file($c)) {
            return $c;
        }
    }

    return '';
}

/**
 * Tìm Node.js (cần cho bộ điều khiển CDP).
 */
function e_find_node(): string
{
    foreach (['node', 'nodejs'] as $bin) {
        $out = [];
        $code = 1;
        @exec('where ' . escapeshellarg($bin) . ' 2>nul', $out, $code);

        if ($code === 0 && $out !== []) {
            return trim((string) $out[0]);
        }
    }

    return '';
}

function e_terminate_proc($proc): void
{
    if (!is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);

    if (($status['running'] ?? false) === true) {
        // -1 = SIGTERM trên POSIX, trên Windows PHP dùng TerminateProcess.
        @proc_terminate($proc, 1);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50000);
            $status = proc_get_status($proc);

            if (($status['running'] ?? false) !== true) {
                break;
            }
        }

        // Dự phòng: buộc giết theo PID (taskkill trả về 0 khi đã thành công).
        if (($status['running'] ?? false) === true && PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>nul');
        }
    }

    @proc_close($proc);
}

/** Cổng này đang có tiến trình khác lắng nghe không? */
function e_port_is_busy(int $port): bool
{
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);

    if (is_resource($probe)) {
        fclose($probe);
        return true;
    }

    return false;
}

function e_cleanup(): void
{
    global $appProc, $sampleProc, $nodeProc, $profile, $sampleDir;

    e_terminate_proc($nodeProc);
    e_terminate_proc($appProc);
    e_terminate_proc($sampleProc);

    if ($sampleDir !== '' && is_dir($sampleDir)) {
        foreach (glob($sampleDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($sampleDir);
    }

    foreach (glob($profile . '*') ?: [] as $dir) {
        @rmdir($dir);
    }
}

register_shutdown_function('e_cleanup');

/* ---------------------------------------------------------------------------
 * Bắt đầu
 * ------------------------------------------------------------------------ */

e_say('');
e_say('=== KIỂM THỬ CHROME EXTENSION THẬT (HEADLESS + CDP) ===');
e_say('');

$chromePath = e_find_chrome();

if ($chromePath === '') {
    e_blocked('Không tìm thấy Google Chrome trên máy này.');
}

$nodePath = e_find_node();

if ($nodePath === '') {
    e_blocked('Không tìm thấy Node.js — cần Node.js để điều khiển DevTools Protocol.');
}

e_say('  · Chrome: ' . $chromePath);
e_say('  · Node  : ' . $nodePath);

/* --- Token API cho tài khoản demo --- */
$userId = (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote('user@demo.vn'))->fetchColumn();

if ($userId <= 0) {
    e_blocked('Không có tài khoản demo user@demo.vn trong CSDL.');
}

$tokenRes = wa_api_token_create($userId, 'auto-test-headless-' . date('His'), ['read', 'library:create', 'page:save'], 1);
$token    = (string) ($tokenRes['token'] ?? '');
$tokenId  = (int) ($tokenRes['id'] ?? 0);

if ($token === '') {
    e_blocked('Không tạo được token API cho tài khoản demo.');
}

e_say('  · Đã tạo token API cho tài khoản demo');
e_say('');

/* --- Máy chủ ứng dụng ---
 * Môi trường được truyền TƯỚNG cho tiến trình con: `WA_TEST_ALLOW_LOCAL=1`
 * kích hoạt chế độ kiểm thử của chính sách URL (chỉ nới lỏng loopback,
 * chỉ khi SAPI là cli/cli-server). Nếu không truyền, chính sách chặn
 * loopback và bài lưu trong E7 sẽ thất bại.
 */
$appLog = sys_get_temp_dir() . '/wa-ext-app-' . getmypid() . '.log';

$childEnv = ['WA_TEST_ALLOW_LOCAL' => '1', 'WA_DEV_ALLOW_LOCAL' => '1'];

foreach (getenv() as $key => $value) {
    if (!isset($childEnv[$key]) && is_string($key) && is_string($value)) {
        $childEnv[$key] = $value;
    }
}

// Cổng đã bị chiếm bởi tiến trình sót lại từ lần chạy trước? Nếu không kiểm
// tra, bài test sẽ nói chuyện với máy chủ CŨ (thiếu biến môi trường kiểm
// thử) và mọi kết quả sẽ sai.
foreach ([WA_E_APP_PORT, WA_E_SAMPLE_PORT] as $busyPort) {
    if (e_port_is_busy($busyPort)) {
        e_blocked('Cổng ' . $busyPort . ' đang bị chiếm bởi tiến trình khác '
            . '(thường là máy chủ `php -S` sót lại từ lần chạy bị ngắt). '
            . 'Hãy kết thúc tiến trình đó rồi chạy lại.');
    }
}

$appProc = proc_open(
    [
        PHP_BINARY,
        '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . WA_E_APP_PORT,
        '-t', $repoRoot . '/public',
    ],
    [1 => ['file', $appLog, 'a'], 2 => ['file', $appLog, 'a']],
    $pipes,
    $repoRoot,
    $childEnv
);

$appReady = false;

for ($i = 0; $i < 60; $i++) {
    $sock = @fsockopen('127.0.0.1', WA_E_APP_PORT, $errno, $errstr, 0.3);

    if ($sock !== false) {
        fclose($sock);
        $appReady = true;
        break;
    }

    usleep(100000);
}

if (!$appReady) {
    e_blocked('Không khởi động được máy chủ ứng dụng trên cổng ' . WA_E_APP_PORT . '.');
}

e_say('  · máy chủ ứng dụng sẵn sàng (cổng ' . WA_E_APP_PORT . ')');

/* --- Máy chủ mẫu (trang HTML thật để Extension lưu) ---
 *
 * DÃY LỆNH (mảng) chứ không phải chuỗi: trên Windows, chuỗi sẽ khiến
 * proc_open() gọi qua `cmd.exe /c`, nên proc_terminate() chỉ giết được vỏ
 * cmd.exe còn php.exe thật vẫn chạy, giữ cổng và treo proc_close() ở cuối.
 */
$sampleProc = proc_open(
    [
        PHP_BINARY,
        '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . WA_E_SAMPLE_PORT,
        '-t', $repoRoot . '/tests/samples',
    ],
    [1 => ['file', $appLog, 'a'], 2 => ['file', $appLog, 'a']],
    $spipes,
    $repoRoot,
    $childEnv
);

$sampleReady = false;

for ($i = 0; $i < 60; $i++) {
    $sock = @fsockopen('127.0.0.1', WA_E_SAMPLE_PORT, $errno, $errstr, 0.3);

    if ($sock !== false) {
        fclose($sock);
        $sampleReady = true;
        break;
    }

    usleep(100000);
}

if (!$sampleReady) {
    e_blocked('Không khởi động được máy chủ mẫu trên cổng ' . WA_E_SAMPLE_PORT . '.');
}

e_say('  · máy chủ mẫu sẵn sàng (cổng ' . WA_E_SAMPLE_PORT . ')');

/* --- Bộ điều khiển CDP (Node) --- */
$nodeLog   = sys_get_temp_dir() . '/wa-ext-node-' . getmypid() . '.log';
$portFile = sys_get_temp_dir() . '/wa-ext-port-' . getmypid() . '.txt';

if (is_file($portFile)) {
    @unlink($portFile);
}

$nodeProc = proc_open(
    [
        $nodePath,
        $repoRoot . '/scripts/ext_cdp_runner.js',
        $chromePath,
        $extDir,
        $portFile,
    ],
    [1 => ['file', $nodeLog, 'a'], 2 => ['file', $nodeLog, 'a']],
    $npipes,
    $repoRoot,
    $childEnv
);

if (!is_resource($nodeProc)) {
    e_blocked('Không khởi động được bộ điều khiển CDP (Node).');
}

// Chờ tệp cổng được ghi ra (bộ điều khiển tự chọn cổng trống).
$runnerPort = 0;

for ($i = 0; $i < 160; $i++) {
    if (is_file($portFile)) {
        $raw = trim((string) @file_get_contents($portFile));

        if ($raw !== '' && (int) $raw > 0) {
            $runnerPort = (int) $raw;
            break;
        }
    }

    usleep(500000);
}

if ($runnerPort <= 0) {
    $log = @file_get_contents($nodeLog);

    e_blocked('Bộ điều khiển CDP không sẵn sàng. Log: ' . mb_substr((string) $log, 0, 400));
}

e_say('  · Chrome headless + CDP sẵn sàng (bộ điều khiển cổng ' . $runnerPort . ')');
e_say('');

/* ---------------------------------------------------------------------------
 * Gọi các bước kiểm thử qua HTTP (bộ điều khiển Node chờ lệnh)
 * ------------------------------------------------------------------------ */

/**
 * Gửi một lệnh kiểm thử cho bộ điều khiển CDP và nhận JSON kết quả.
 *
 * @return array<string,mixed>
 */
function e_run(string $step, array $params = []): array
{
    global $runnerPort;

    $payload = json_encode(
        ['step' => $step, 'params' => $params],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 90,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents('http://127.0.0.1:' . $runnerPort . '/run', false, $ctx);

    if ($raw === false || trim((string) $raw) === '') {
        return ['ok' => false, 'error' => 'Không nhận được phản hồi từ bộ điều khiển CDP.'];
    }

    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Phản hồi không phải JSON.'];
}

/* --- E1: nạp extension --- */
$load = e_run('load');

if (($load['ok'] ?? false) !== true) {
    e_record('E1', false, 'Không nạp được extension: ' . (string) ($load['error'] ?? 'không rõ lý do'));
    e_say('');
    exit(1);
}

e_record('E1', true, 'Chrome headless nạp được extension từ ' . $extDir
    . ' — extension id = ' . (string) ($load['extension_id'] ?? ''));

$extId = (string) ($load['extension_id'] ?? '');

/* --- E2: service worker --- */
$sw = e_run('sw');

$swOk = ($sw['ok'] ?? false) === true;

e_record('E2', $swOk, $swOk
    ? 'Service worker background.js đã đăng ký thành công: ' . (string) ($sw['url'] ?? '')
    : 'Không thấy service worker của extension: ' . (string) ($sw['error'] ?? ''));

/* --- E3: manifest V3 --- */
$mf = e_run('manifest');

$mfOk = ($mf['ok'] ?? false) === true;

e_record('E3', $mfOk, $mfOk
    ? 'manifest.json đọc được qua chrome-extension:// và là Manifest V3 ('
      . (string) ($mf['manifest_version'] ?? '') . ', name="'
      . (string) ($mf['name'] ?? '') . '")'
    : 'Không đọc được manifest: ' . (string) ($mf['error'] ?? ''));

/* --- E4: popup nạp được + có đủ nút --- */
$popup = e_run('popup_dom');

$popupOk = ($popup['ok'] ?? false) === true;

e_record('E4', $popupOk, $popupOk
    ? 'popup.html nạp được — title="' . (string) ($popup['title'] ?? '') . '", DOM='
      . (string) ($popup['dom_length'] ?? '0') . ' ký tự, nút Lưu='
      . (string) ($popup['has_save'] ?? '?') . ', ô chọn thư viện='
      . (string) ($popup['has_library'] ?? '?') . ', công tắc tự động lưu='
      . (string) ($popup['has_autosave'] ?? '?')
    : 'Không nạp được popup: ' . (string) ($popup['error'] ?? ''));

/* --- E5: popup gọi API thật (BUG-03 / BUG-05) --- */
$apiBase = 'http://127.0.0.1:' . WA_E_APP_PORT;

$e5 = e_run('configure', ['serverUrl' => $apiBase, 'token' => $token]);

$e5Ok = ($e5['ok'] ?? false) === true
    && (int) ($e5['library_count'] ?? 0) > 1
    && (string) ($e5['status'] ?? '') !== '';

e_record('E5', $e5Ok, 'Popup gọi API thật — trạng thái="' . (string) ($e5['status'] ?? '')
    . '", số thư viện tải được=' . (string) ($e5['library_count'] ?? '0')
    . ', tên thư viện đầu tiên="' . (string) ($e5['first_library'] ?? '') . '"'
    . ((string) ($e5['error'] ?? '') !== '' ? ', lỗi="' . (string) $e5['error'] . '"' : ''));

/* --- E5b: token sai -> KHÔNG được báo thành công (BUG-05) --- */
$e5b = e_run('configure', ['serverUrl' => $apiBase, 'token' => 'token-hong-ton-tai-' . bin2hex(random_bytes(6))]);

// Khi API lỗi, popup KHÔNG được đổ danh sách rỗng giả: ô chọn thư viện chỉ
// còn đúng một dòng "— Chọn thư viện —" và trạng thái phải là lỗi.
$e5bOk = ($e5b['ok'] ?? false) === false
    && (int) ($e5b['library_count'] ?? -1) <= 1
    && (string) ($e5b['status'] ?? '') !== ''
    && (string) ($e5b['status'] ?? '') !== (string) ($e5['status'] ?? '');

e_record('E5b', $e5bOk, 'Token không hợp lệ — popup báo lỗi thay vì báo thành công: trạng thái="'
    . (string) ($e5b['status'] ?? '') . '", số dòng trong ô chọn thư viện='
    . (string) ($e5b['library_count'] ?? '?') . ', thông báo="'
    . mb_substr((string) ($e5b['message'] ?? ''), 0, 160) . '"');

/* --- E5c: khôi phục token hợp lệ cho các bước sau --- */
$e5c = e_run('configure', ['serverUrl' => $apiBase, 'token' => $token]);

$e5cOk = ($e5c['ok'] ?? false) === true;

e_record('E5c', $e5cOk, 'Khôi phục token hợp lệ — số thư viện tải được='
    . (string) ($e5c['library_count'] ?? '0') . ', trạng thái="'
    . (string) ($e5c['status'] ?? '') . '"');

/* --- E6: tạo thư viện mới ngay trong popup (BUG-04) --- */
$libName = 'EXT HEADLESS ' . date('His') . '-' . getmypid();

$e6 = e_run('create_library', ['name' => $libName]);

$newId = (string) ($e6['library_id'] ?? '');

$inDb = 0;

if ($newId !== '') {
    $inDb = (int) db()->query('SELECT COUNT(*) FROM `libraries` WHERE id = ' . (int) $newId)->fetchColumn();
}

$e6Ok = ($e6['ok'] ?? false) === true
    && $newId !== ''
    && $inDb === 1;

e_record('E6', $e6Ok, 'Tạo thư viện trong popup — thư viện có trong CSDL=' . $inDb
    . ', popup TỰ CHỌN thư viện mới (select="' . (string) ($e6['selected'] ?? '') . '")'
    . ', thông báo="' . mb_substr((string) ($e6['message'] ?? ''), 0, 120) . '"');

/* --- E7: lưu một URL công khai thật qua popup --- */
$libForSave = $newId !== '' ? $newId : (string) ($e6['selected'] ?? '');

if ($libForSave === '') {
    e_record('E7', false, 'Không có thư viện nào để lưu trang.');
} else {
    $sampleUrl = 'http://127.0.0.1:' . WA_E_SAMPLE_PORT . '/bai-01.html';

    $e7 = e_run('save_page', [
        'url'        => $sampleUrl,
        'libraryId' => $libForSave,
    ]);

    $snapId = (int) ($e7['snapshot_id'] ?? 0);

    $e7Ok = ($e7['ok'] ?? false) === true && $snapId > 0;

    e_record('E7', $e7Ok, 'Lưu trang qua popup — thông báo="' . mb_substr((string) ($e7['message'] ?? ''), 0, 160)
        . '", snapshot_id=' . $snapId
        . ((string) ($e7['error'] ?? '') !== '' ? ', lỗi="' . (string) $e7['error'] . '"' : ''));
}

/* --- E8: công tắc tự động lưu — mặc định TẮT và bật/tắt ổn định (BUG-02) --- */
$e8 = e_run('autosave_cycle');

$e8Ok = ($e8['ok'] ?? false) === true;

e_record('E8', $e8Ok, $e8Ok
    ? 'Công tắc tự động lưu: mặc định=' . (string) ($e8['default'] ?? '')
      . ', sau khi bật/tắt/bật=' . (string) ($e8['after_cycle'] ?? '')
      . ', lưu trong storage=' . (string) ($e8['stored'] ?? '')
    : 'Kiểm tra công tắc tự động lưu thất bại: ' . (string) ($e8['error'] ?? ''));

/* --- E9: không hiện cảnh báo rủi ro/trùng lặp khi chưa kiểm tra (BUG-06/07/08) --- */
$e9 = e_run('warning_state');

$riskHidden = in_array((string) ($e9['risk_visible'] ?? 'true'), ['false', '0', ''], true);
$dupHidden  = in_array((string) ($e9['dup_visible'] ?? 'true'), ['false', '0', ''], true);

$e9Ok = ($e9['ok'] ?? false) === true && $riskHidden && $dupHidden;

e_record('E9', $e9Ok, 'Mở popup KHÔNG tự hiện hộp cảnh báo — hộp rủi ro hiện='
    . (string) ($e9['risk_visible'] ?? '?') . ', hộp trùng lặp hiện='
    . (string) ($e9['dup_visible'] ?? '?'));

/* ---------------------------------------------------------------------------
 * Dọn dẹp
 * ------------------------------------------------------------------------ */

if ($newId !== '') {
    db()->prepare('DELETE FROM `libraries` WHERE id = ?')->execute([(int) $newId]);
}

if ($tokenId > 0) {
    db()->prepare('DELETE FROM `api_tokens` WHERE id = ?')->execute([$tokenId]);
}

/* --- Tổng kết --- */
$passed = 0;
$failed = 0;

e_say('');
e_say('============================================================');
e_say('  KẾT QUẢ KIỂM THỬ CHROME EXTENSION (HEADLESS)');
e_say('============================================================');

foreach ($results as $item) {
    e_say(($item['passed'] ? '  PASS  ' : '  FAIL  ') . $item['id'] . ' — ' . $item['evidence']);

    if ($item['passed']) {
        $passed++;
    } else {
        $failed++;
    }
}

e_say('------------------------------------------------------------');
e_say('  Tổng: ' . count($results) . ' mục — PASS: ' . $passed . ' — FAIL: ' . $failed);
e_say('============================================================');
e_say('');

exit($failed === 0 ? 0 : 1);
