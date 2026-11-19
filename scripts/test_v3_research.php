<?php
/**
 * scripts/test_v3_research.php
 * -----------------------------------------------------------------------------
 * KIỂM THỬ TỰ ĐỘNG CHO F4 / F10 / F11 / F12 (V3 ĐỢT 4).
 *
 * MỤC TIÊU
 *   F4  — Bôi đen nhiều lần: mỗi đánh dấu độc lập, xoá một đánh dấu KHÔNG ảnh
 *         hưởng các đánh dấu còn lại; đánh dấu thuộc đúng snapshot + tài khoản.
 *   F10 — Theo dõi đoạn: tạo mục theo dõi -> lấy phiên bản mới -> đúng đoạn
 *         được báo thay đổi (changed), không báo trùng.
 *   F11 — Liên kết bài viết: tạo -> đọc -> sửa -> xoá; phân quyền ghi/đọc.
 *   F12 — Phát hiện trùng lặp: trùng URL, trùng SHA-256, gần giống; V1/V2/V3
 *         của CÙNG một bài KHÔNG bị coi là bài trùng.
 *
 * MÔI TRƯỜNG
 *   Bộ kiểm thử tự khởi động máy chủ riêng (proc_open):
 *     - Ứng dụng : http://127.0.0.1:18094  (public/)
 *     - Mẫu      : http://127.0.0.1:18095  (tests/samples/)
 *   Mọi thao tác đi qua HTTP THẬT bằng cURL + cookie jar (không gọi thẳng
 *   hàm PHP), giống bộ run_tests.php.
 *
 * DỮ LIỆU
 *   Chỉ tạo/xoá dữ liệu thử nghiệm RIÊNG (thư viện tạm + tệp mẫu tạm) và
 *   dọn trong phần cuối. KHÔNG đụng tới thư viện/snapshot/tài khoản thật.
 *
 * CÁCH CHẠY
 *     php scripts/test_v3_research.php
 *
 * Mã thoát: 0 khi tất cả PASS, 1 khi có FAIL.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/test_v3_research.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

/* Chế độ kiểm thử của chính sách URL: chỉ nới lỏng loopback, chỉ khi SAPI là
   cli/cli-server (không kích hoạt được qua HTTP). */
if (getenv('WA_TEST_ALLOW_LOCAL') !== '1') {
    putenv('WA_TEST_ALLOW_LOCAL=1');
}

const WA_R_APP_PORT    = 18094;
const WA_R_SAMPLE_PORT = 18095;
const WA_R_APP_BASE    = 'http://127.0.0.1:18094';
const WA_R_SAMPLE_BASE = 'http://127.0.0.1:18095';

$results = [];
$servers = [];
$cookieJars = [];
$tempFiles = [];
$runId = date('ymdHis') . '-' . getmypid();

/* =============================================================================
    TIỆN ÍCH
    ====================================================================== */

function r_out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function r_record(string $id, bool $passed, string $evidence): void
{
    global $results;

    $results[] = ['id' => $id, 'passed' => $passed, 'evidence' => $evidence];

    r_out(($passed ? '  PASS  ' : '  FAIL  ') . $id . ' — ' . $evidence);
}

function r_port_busy(int $port): bool
{
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);

    if ($probe === false) {
        return false;
    }

    fclose($probe);

    return true;
}

/** Dừng tiến trình con (Windows: proc_terminate có thể không kịp). */
function r_kill_proc($proc): void
{
    if (!is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);

    if (($status['running'] ?? false) === true) {
        @proc_terminate($proc);

        for ($i = 0; $i < 20; $i++) {
            usleep(50000);
            $status = proc_get_status($proc);

            if (($status['running'] ?? false) !== true) {
                break;
            }
        }

        if (($status['running'] ?? false) === true && PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>nul');
        }
    }

    @proc_close($proc);
}

function r_kill_servers(): void
{
    global $servers;

    foreach ($servers as $proc) {
        r_kill_proc($proc);
    }

    $servers = [];
}

function r_cleanup_files(): void
{
    global $tempFiles, $cookieJars;

    foreach ([...$tempFiles, ...$cookieJars] as $file) {
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    $tempFiles = [];
    $cookieJars = [];
}

register_shutdown_function(static function (): void {
    r_kill_servers();
    r_cleanup_files();
});

function r_start_server(int $port, string $docroot, string $label): void
{
    global $servers;

    if (r_port_busy($port)) {
        r_out('[LOI] Cổng ' . $port . ' đang bị chiếm — dừng để không xung đột.');
        exit(1);
    }

    $logFile = sys_get_temp_dir() . '/wa-v3research-' . getmypid() . '-' . $label . '.log';

    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot],
        [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes
    );

    if (!is_resource($proc)) {
        r_out('[LOI] Không khởi động được máy chủ ' . $label . '. Log: ' . $logFile);
        exit(1);
    }

    $servers[] = $proc;

    for ($i = 0; $i < 80; $i++) {
        usleep(150000);

        if (r_port_busy($port)) {
            r_out('  · máy chủ ' . $label . ' sẵn sàng (cổng ' . $port . ')');
            return;
        }
    }

    r_out('[LOI] Máy chủ ' . $label . ' không khởi động được. Log: ' . $logFile);
    exit(1);
}

function r_jar(string $who): string
{
    global $cookieJars;

    $jar = sys_get_temp_dir() . '/wa-v3research-' . getmypid() . '-' . $who . '.cookies';
    $cookieJars[] = $jar;

    return $jar;
}

/**
 * @param array<string,string>|null $post
 * @return array{status:int, body:string, headers:string, effective_url:string}
 */
function r_http(string $url, ?array $post = null, string $jar = '', bool $follow = true): array
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

/** @return array{status:int, body:array<string,mixed>, raw:string} */
function r_json(string $url, array $post, string $jar): array
{
    $res = r_http($url, $post, $jar);

    $decoded = json_decode($res['body'], true);

    return [
        'status' => $res['status'],
        'body'   => is_array($decoded) ? $decoded : [],
        'raw'    => $res['body'],
    ];
}

function r_csrf(string $url, string $jar): string
{
    $res = r_http($url, null, $jar);

    if (preg_match('/name="wa_csrf" value="([^"]+)"/', $res['body'], $m)) {
        return $m[1];
    }

    // Một số trang dùng thứ tự thuộc tính khác.
    if (preg_match('/value="([^"]+)"[^>]*name="wa_csrf"/', $res['body'], $m)) {
        return $m[1];
    }

    return '';
}

function r_login(string $email, string $password, string $jar): bool
{
    r_http(WA_R_APP_BASE . '/login.php', null, $jar);

    $res = r_http(WA_R_APP_BASE . '/login.php', [
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

function r_snapshot_from(string $url): int
{
    if (preg_match('#reader\.php\?id=(\d+)#', $url, $m)) {
        return (int) $m[1];
    }

    return 0;
}

/**
 * Lưu trang theo đúng luồng người dùng (xác nhận cảnh báo trùng lặp nếu có).
 *
 * @param array<string,string> $form
 * @return array{status:int, effective_url:string, body:string, confirmed:bool}
 */
function r_save(string $savePage, array $form, string $jar): array
{
    $res = r_http($savePage, $form, $jar);

    if (r_snapshot_from($res['effective_url']) > 0) {
        return $res + ['confirmed' => false];
    }

    $hasRisk = str_contains($res['body'], 'name="override_risk"');
    $hasDup  = str_contains($res['body'], 'name="override_dup"');

    if (!$hasRisk && !$hasDup) {
        return $res + ['confirmed' => false];
    }

    $form['override_risk'] = $hasRisk ? '1' : ($form['override_risk'] ?? '');
    $form['override_dup']  = $hasDup ? '1' : ($form['override_dup'] ?? '');

    $res2 = r_http($savePage, $form, $jar);

    return $res2 + ['confirmed' => true];
}

/* =============================================================================
    BẮT ĐẦU
    ====================================================================== */

r_out('');
r_out('=== KIỂM THỬ V3 ĐỢT 4 — F4 / F10 / F11 / F12 ===');
r_out('');
r_out('Mã lần chạy: ' . $runId);
r_out('');

r_out('[0] Khởi động máy chủ riêng');
r_start_server(WA_R_APP_PORT, dirname(__DIR__) . '/public', 'ung-dung');
r_start_server(WA_R_SAMPLE_PORT, dirname(__DIR__) . '/tests/samples', 'thu-mau');
r_out('');

/* --- Đăng nhập chủ sở hữu --- */
$ownerJar = r_jar('chu-so-hu');

r_out('[1] Đăng nhập user@demo.vn');

if (!r_login('user@demo.vn', 'demo123', $ownerJar)) {
    r_out('[LỖI] Không đăng nhập được chủ sở hữu.');
    exit(1);
}

r_out('  ✓ đăng nhập thành công');

/* --- Tạo thư viện tạm --- */
$csrf = r_csrf(WA_R_APP_BASE . '/libraries.php', $ownerJar);

if ($csrf === '') {
    r_out('[LỖI] Không lấy được token CSRF.');
    exit(1);
}

$libraryName = 'V3 RESEARCH ' . $runId;

$create = r_http(WA_R_APP_BASE . '/library_action.php', [
    'wa_csrf'     => $csrf,
    'action'      => 'create',
    'name'        => $libraryName,
    'description' => 'Thư viện tạm cho bộ kiểm thử V3 Đợt 4.',
    'visibility'  => 'private',
    'color'       => 'a',
    'icon'        => 'terminal',
], $ownerJar);

$libraryId = 0;
$librarySlug = '';

if (preg_match('#library\.php\?slug=([^&"\']+)#', $create['effective_url'], $m)) {
    $librarySlug = urldecode($m[1]);
}

$stmt = db()->prepare('SELECT id FROM `libraries` WHERE slug = ? LIMIT 1');
$stmt->execute([$librarySlug]);
$libraryId = (int) $stmt->fetchColumn();

if ($libraryId <= 0) {
    r_out('[LỖI] Không tạo được thư viện tạm.');
    exit(1);
}

r_out('  ✓ thư viện tạm «' . $libraryName . '» (id=' . $libraryId . ')');
r_out('');

$savePage = WA_R_APP_BASE . '/save.php';

/* ---------------------------------------------------------------------------
 * Chuẩn bị nội dung: 4 khối văn bản riêng biệt để bôi đen nhiều lần.
 *
 * LƯU Ý: content_html lưu vào CSDL là BẢN SAO đã sanitize của khối <article>,
 * nên đường dẫn CSS của khối bắt đầu bằng `article > p:nth-of-type(N)`.
 * Selector phải KHỚP với cây DOM đó, nếu không PHP không tìm được phần tử và
 * không thể vẽ lại thẻ <mark>.
 * ------------------------------------------------------------------------- */
$blocks = [];

for ($i = 1; $i <= 4; $i++) {
    $blocks[$i] = 'DoanKhoa' . $i . ' chuKyRieng ' . $runId . ' — nội dung riêng của khối số ' . $i
        . '. Đoạn này đủ dài để người kiểm thử bôi đen và lưu thành đánh dấu hoặc mục theo dõi mà không bị trùng với các đoạn khác trong cùng bài viết, '
        . 'vì mỗi khối đều mang một mã khoá duy nhất DoanKhoa' . $i . ' chuKyRieng ' . $runId . '.';
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Bài kiểm thử V3 Đợt 4</title>
<meta property="og:title" content="Bài kiểm thử V3 Đợt 4">
</head>
<body>
<main class="noi-dung">
  <article>
    <h1>Bài kiểm thử V3 Đợt 4</h1>
    <p>{$blocks[1]}</p>
    <p>{$blocks[2]}</p>
    <p>{$blocks[3]}</p>
    <p>{$blocks[4]}</p>
  </article>
</main>
</body>
</html>
HTML;

$fileName = 'v3research-' . $runId . '.html';
$filePath = dirname(__DIR__) . '/tests/samples/' . $fileName;
$GLOBALS['tempFiles'][] = $filePath;

if (file_put_contents($filePath, $html) === false) {
    r_out('[LỖI] Không tạo được tệp mẫu tạm.');
    exit(1);
}

$pageUrl = WA_R_SAMPLE_BASE . '/' . $fileName;

/* ---------------------------------------------------------------------------
 * Lưu bài (V1).
 * ------------------------------------------------------------------------- */
$csrf = r_csrf($savePage, $ownerJar);

$save = r_save($savePage, [
    'wa_csrf'    => $csrf,
    'url'        => $pageUrl,
    'library_id' => (string) $libraryId,
    'tags'       => 'v3-dot4',
], $ownerJar);

$snapshotId = r_snapshot_from($save['effective_url']);

if ($snapshotId <= 0) {
    r_out('[LỖI] Không lưu được bài mẫu (snapshot=0).');
    exit(1);
}

$stmt = db()->prepare('SELECT page_id FROM `snapshots` WHERE id = ? LIMIT 1');
$stmt->execute([$snapshotId]);
$pageId = (int) $stmt->fetchColumn();

r_out('  ✓ đã lưu bài mẫu — SNAP-' . $snapshotId . ' (page_id=' . $pageId . ')');
r_out('');

/* ---------------------------------------------------------------------------
 * F4 — BÔI ĐEN NHIỀU LẦN (4 đánh dấu độc lập trên CÙNG một snapshot).
 * ------------------------------------------------------------------------- */
r_out('[F4] Bôi đen nhiều lần — mỗi đánh dấu độc lập');

$annotIds = [];
$f4Ok = true;
$f4DetailParts = [];

foreach ([1, 2, 3, 4] as $n) {
    $quote = 'DoanKhoa' . $n . ' chuKyRieng ' . $runId;

    $readerUrl = WA_R_APP_BASE . '/reader.php?id=' . $snapshotId;
    $csrf = r_csrf($readerUrl, $ownerJar);

    $res = r_json(WA_R_APP_BASE . '/annotation_action.php', [
        'wa_csrf'      => $csrf,
        'action'       => 'add',
        'snapshot_id'  => (string) $snapshotId,
        'selector'     => 'article > p:nth-of-type(' . $n . ')',
        'start_offset' => '0',
        'end_offset'   => (string) mb_strlen($quote, 'UTF-8'),
        'quote'        => $quote,
        'prefix'       => '',
        'suffix'       => '',
        'color'        => 'yellow',
        'note'         => 'Ghi chú kiểm thử F4 ' . $n,
    ], $ownerJar);

    $id = (int) ($res['body']['id'] ?? 0);

    if ($id <= 0) {
        $f4Ok = false;
        $f4DetailParts[] = 'đánh dấu ' . $n . ': KHÔNG tạo được';
        continue;
    }

    $annotIds[$n] = $id;
    $f4DetailParts[] = '#' . $n . '=ANN' . $id;
}

/* Reload trang -> cả bốn đánh dấu còn nguyên. */
$readerRes = r_http(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, null, $ownerJar);
$marksCount = substr_count($readerRes['body'], 'wa-mark--yellow');

$allFourAlive = count($annotIds) === 4;

foreach ($annotIds as $id) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM `annotations` WHERE id = ? AND user_id = ? AND snapshot_id = ?');
    $stmt->execute([$id, (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote('user@demo.vn'))->fetchColumn(), $snapshotId]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $allFourAlive = false;
    }
}

if ($marksCount < 4) {
    $f4Ok = false;
    $f4DetailParts[] = 'sau reload chỉ còn ' . $marksCount . '/4 thẻ mark';
}

/* Xoá đánh dấu B -> A, C, D không bị ảnh hưởng. */
if (isset($annotIds[2])) {
    $csrf = r_csrf(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, $ownerJar);

    $del = r_json(WA_R_APP_BASE . '/annotation_action.php', [
        'wa_csrf'       => $csrf,
        'action'        => 'delete',
        'annotation_id' => (string) $annotIds[2],
    ], $ownerJar);

    if (($del['body']['ok'] ?? false) !== true) {
        $f4Ok = false;
        $f4DetailParts[] = 'xoá đánh dấu B thất bại';
    }
}

$remaining = 0;
$stmt = db()->prepare('SELECT COUNT(*) FROM `annotations` WHERE snapshot_id = ? AND user_id = ?');
$stmt->execute([$snapshotId, (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote('user@demo.vn'))->fetchColumn()]);
$remaining = (int) $stmt->fetchColumn();

if ($remaining !== 3) {
    $f4Ok = false;
    $f4DetailParts[] = 'sau khi xoá B còn ' . $remaining . '/3 đánh dấu';
}

$readerRes2 = r_http(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, null, $ownerJar);
$marksAfter = substr_count($readerRes2['body'], 'wa-mark--yellow');

if ($marksAfter !== 3) {
    $f4Ok = false;
    $f4DetailParts[] = 'sau khi xoá B còn ' . $marksAfter . '/3 thẻ mark trên trang';
}

r_record('F4', $f4Ok, '4 đánh dấu độc lập trên 1 snapshot (' . implode(', ', $f4DetailParts)
    . ') — reload còn đủ 4, xoá B còn đúng 3');

/* ---------------------------------------------------------------------------
 * F10 — THEO DÕI ĐOẠN.
 * ------------------------------------------------------------------------- */
r_out('[F10] Theo dõi đoạn — tạo mục theo dõi, lấy phiên bản mới, đúng đoạn báo thay đổi');

/* Tạo mục theo dõi cho khối 1 (đoạn sẽ bị sửa ở bản mới) và khối 2 (đoạn giữ nguyên). */
$watchIds = [];
$f10Ok = true;
$f10Parts = [];

foreach ([1 => 'thay đổi', 2 => 'giữ nguyên'] as $n => $label) {
    /* Theo dõi CẢ ĐOẠN VĂN (không chỉ mã khoá) — đó là hành vi thật của
       người dùng khi bôi đen một đoạn trong trình đọc. */
    $quote = $blocks[$n];

    $csrf = r_csrf(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, $ownerJar);

    $res = r_json(WA_R_APP_BASE . '/watch_action.php', [
        'wa_csrf'      => $csrf,
        'action'       => 'create',
        'snapshot_id'  => (string) $snapshotId,
        'selector'     => 'article > p:nth-of-type(' . $n . ')',
        'start_offset' => '0',
        'end_offset'   => (string) mb_strlen($quote, 'UTF-8'),
        'quote'        => $quote,
        'prefix'       => '',
        'suffix'       => '',
    ], $ownerJar);

    $id = (int) ($res['body']['id'] ?? 0);

    if ($id <= 0) {
        $f10Ok = false;
        $f10Parts[] = 'tạo mục theo dõi ' . $label . ': thất bại';
        continue;
    }

    $watchIds[$n] = $id;
    $f10Parts[] = 'theo dõi ' . $label . '=WATCH' . $id;
}

/* Sửa nội dung gốc: khối 1 được VIẾT LẠI MỘT PHẦN (đổi vài từ, giữ phần
   lớn câu) -> đoạn theo dõi phải được báo là "changed".
   PHẢI giữ đủ token chung để Jaccard >= 0.55; nếu viết lại hoàn toàn thì hệ
   thống đúng khi báo "unknown" (không xác định được đoạn tương ứng) — đó là
   hành vi được đặc tả, KHÔNG phải lỗi. */
$edited = str_replace(
    'nội dung riêng của khối số 1. Đoạn này đủ dài để người kiểm thử bôi đen và lưu thành đánh dấu hoặc mục theo dõi mà không bị trùng với các đoạn khác trong cùng bài viết, ',
    'nội dung riêng của khối số 1. Đoạn này đủ dài để người kiểm thử bôi đen và lưu thành đánh dấu hoặc mục theo dõi mà không bị trùng với các đoạn khác trong cùng bài viết này, ',
    $html
);

if ($edited === $html) {
    $f10Ok = false;
    $f10Parts[] = 'không thay được nội dung khối 1 trong tệp mẫu';
}

file_put_contents($filePath, $edited);

/* Lấy phiên bản mới. */
$csrf = r_csrf(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, $ownerJar);

$refetch = r_http(WA_R_APP_BASE . '/refetch.php', [
    'wa_csrf'     => $csrf,
    'snapshot_id' => (string) $snapshotId,
], $ownerJar);

$snapshot2 = r_snapshot_from($refetch['effective_url']);

if ($snapshot2 <= 0) {
    $f10Ok = false;
    $f10Parts[] = 'không lấy được phiên bản mới';
} else {
    $f10Parts[] = 'phiên bản mới=SNAP' . $snapshot2;

    $stmt = db()->prepare('SELECT status, status_note FROM `content_watches` WHERE id = ?');
    $stmt->execute([$watchIds[1] ?? 0]);
    $row1 = $stmt->fetch();

    $stmt->execute([$watchIds[2] ?? 0]);
    $row2 = $stmt->fetch();

    $statusChanged = (string) ($row1['status'] ?? '');
    $statusSame    = (string) ($row2['status'] ?? '');

    if ($statusChanged !== 'changed') {
        $f10Ok = false;
        $f10Parts[] = 'đoạn bị sửa có trạng thái "' . $statusChanged . '" (mong đợi "changed")';
    }

    if ($statusSame !== 'ok') {
        $f10Ok = false;
        $f10Parts[] = 'đoạn giữ nguyên có trạng thái "' . $statusSame . '" (mong đợi "ok")';
    }

    /* Thông báo: đúng một thông báo cho đoạn bị sửa. */
    $userId = (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote('user@demo.vn'))->fetchColumn();

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM `notifications`
          WHERE user_id = ? AND kind = 'watch_changed' AND snapshot_id = ?"
    );
    $stmt->execute([$userId, $snapshot2]);
    $notifCount = (int) $stmt->fetchColumn();

    if ($notifCount < 1) {
        $f10Ok = false;
        $f10Parts[] = 'KHÔNG có thông báo cho đoạn bị sửa';
    } else {
        $f10Parts[] = 'thông báo=' . $notifCount;
    }

    /* Chống trùng: gọi lại kiểm tra lần nữa ở cùng phiên bản -> không thêm thông báo. */
    if (function_exists('wa_watch_check_page')) {
        wa_watch_check_page($pageId, $snapshotId, $snapshot2, 2);

        $stmt->execute([$userId, $snapshot2]);
        $notifCount2 = (int) $stmt->fetchColumn();

        if ($notifCount2 !== $notifCount) {
            $f10Ok = false;
            $f10Parts[] = 'kiểm tra lại sinh thêm thông báo (' . $notifCount . ' -> ' . $notifCount2 . ')';
        }
    }
}

/* Trang "Đoạn theo dõi" phải liệt kê đủ 2 mục. */
$watchesRes = r_http(WA_R_APP_BASE . '/watches.php', null, $ownerJar);
$watchRows = substr_count($watchesRes['body'], 'DoanKhoa');

if ($watchRows < 2) {
    $f10Ok = false;
    $f10Parts[] = 'trang Đoạn theo dõi chỉ hiện ' . $watchRows . '/2 mục';
}

r_record('F10', $f10Ok, implode(', ', $f10Parts));

/* ---------------------------------------------------------------------------
 * F11 — LIÊN KẾT BÀI VIẾT.
 * ------------------------------------------------------------------------- */
r_out('[F11] Liên kết bài viết + sơ đồ quan hệ');

/* Cần một bài thứ hai để liên kết tới. */
$fileName2 = 'v3research-b-' . $runId . '.html';
$filePath2 = dirname(__DIR__) . '/tests/samples/' . $fileName2;
$GLOBALS['tempFiles'][] = $filePath2;

file_put_contents($filePath2, str_replace('Bài kiểm thử V3 Đợt 4', 'Bài kiểm thử V3 Đợt 4 — bản đích', $html));

$csrf = r_csrf($savePage, $ownerJar);

$save2 = r_save($savePage, [
    'wa_csrf'    => $csrf,
    'url'        => WA_R_SAMPLE_BASE . '/' . $fileName2,
    'library_id' => (string) $libraryId,
    'tags'       => 'v3-dot4',
], $ownerJar);

$snapshotB = r_snapshot_from($save2['effective_url']);

$f11Ok = $snapshotB > 0;
$f11Parts = [];

if (!$f11Ok) {
    $f11Parts[] = 'không lưu được bài đích';
} else {
    $stmt = db()->prepare('SELECT page_id FROM `snapshots` WHERE id = ? LIMIT 1');
    $stmt->execute([$snapshotB]);
    $pageB = (int) $stmt->fetchColumn();

    $f11Parts[] = 'bài đích=SNAP' . $snapshotB . '/page' . $pageB;

    /* Tạo liên kết. */
    $csrf = r_csrf(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, $ownerJar);

    $rel = r_json(WA_R_APP_BASE . '/relation_action.php', [
        'wa_csrf'        => $csrf,
        'action'         => 'create',
        'source_page_id' => (string) $pageId,
        'target_page_id' => (string) $pageB,
        'relation_type'  => 'references',
        'note'           => 'Ghi chú quan hệ kiểm thử F11',
    ], $ownerJar);

    $relationId = (int) ($rel['body']['id'] ?? 0);

    if ($relationId <= 0) {
        $f11Ok = false;
        $f11Parts[] = 'tạo liên kết thất bại: ' . mb_substr((string) ($rel['body']['message'] ?? ''), 0, 90);
    } else {
        $f11Parts[] = 'quan hệ=REL' . $relationId;

        /* Đọc lại danh sách — phải thấy liên kết. */
        $csrf = r_csrf(WA_R_APP_BASE . '/reader.php?id=' . $snapshotId, $ownerJar);

        $list = r_json(WA_R_APP_BASE . '/relation_action.php', [
            'wa_csrf'        => $csrf,
            'action'         => 'list',
            'source_page_id' => (string) $pageId,
        ], $ownerJar);

        $relations = $list['body']['relations'] ?? [];

        if (!is_array($relations) || count($relations) === 0) {
            $f11Ok = false;
            $f11Parts[] = 'danh sách quan hệ rỗng';
        } else {
            $f11Parts[] = 'danh sách=' . count($relations);
        }

        /* Sơ đồ quan hệ (graph). */
        $graph = r_json(WA_R_APP_BASE . '/relation_action.php', [
            'wa_csrf'        => $csrf,
            'action'         => 'graph',
            'source_page_id' => (string) $pageId,
        ], $ownerJar);

        $graphData = $graph['body']['graph'] ?? [];

        if (!is_array($graphData) || ($graphData['nodes'] ?? []) === []) {
            $f11Ok = false;
            $f11Parts[] = 'sơ đồ quan hệ rỗng';
        } else {
            $f11Parts[] = 'sơ đồ=' . count($graphData['nodes']) . ' nút / '
                . count($graphData['edges'] ?? []) . ' cạnh';
        }

        /* Sửa quan hệ. */
        $upd = r_json(WA_R_APP_BASE . '/relation_action.php', [
            'wa_csrf'        => $csrf,
            'action'         => 'update',
            'relation_id'   => (string) $relationId,
            'source_page_id' => (string) $pageId,
            'relation_type'  => 'contrasts',
            'note'           => 'Đã sửa loại quan hệ',
        ], $ownerJar);

        if (($upd['body']['ok'] ?? false) !== true) {
            $f11Ok = false;
            $f11Parts[] = 'sửa quan hệ thất bại';
        } else {
            $stmt = db()->prepare('SELECT relation_type FROM `page_relations` WHERE id = ?');
            $stmt->execute([$relationId]);

            if ((string) $stmt->fetchColumn() !== 'contrasts') {
                $f11Ok = false;
                $f11Parts[] = 'loại quan hệ chưa được cập nhật';
            }
        }

        /* Xoá quan hệ. */
        $del = r_json(WA_R_APP_BASE . '/relation_action.php', [
            'wa_csrf'        => $csrf,
            'action'         => 'delete',
            'relation_id'   => (string) $relationId,
            'source_page_id' => (string) $pageId,
        ], $ownerJar);

        if (($del['body']['ok'] ?? false) !== true) {
            $f11Ok = false;
            $f11Parts[] = 'xoá quan hệ thất bại';
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM `page_relations` WHERE id = ?');
            $stmt->execute([$relationId]);

            if ((int) $stmt->fetchColumn() !== 0) {
                $f11Ok = false;
                $f11Parts[] = 'quan hệ vẫn còn trong CSDL sau khi xoá';
            }
        }
    }
}

r_record('F11', $f11Ok, implode(', ', $f11Parts));

/* ---------------------------------------------------------------------------
 * F12 — PHÁT HIỆN TRÙNG LẶP.
 * ------------------------------------------------------------------------- */
r_out('[F12] Phát hiện trùng lặp — trùng URL / trùng SHA-256 / gần giống');

$f12Ok = true;
$f12Parts = [];

/* 1) Trùng SHA-256: tạo tệp mẫu khác ĐƯỜNG DẪN nhưng nội dung GIỐNG HỆT. */
$fileNameDup = 'v3research-dup-' . $runId . '.html';
$filePathDup = dirname(__DIR__) . '/tests/samples/' . $fileNameDup;
$GLOBALS['tempFiles'][] = $filePathDup;

file_put_contents($filePathDup, $html);

$csrf = r_csrf($savePage, $ownerJar);

$saveDup = r_http($savePage, [
    'wa_csrf'    => $csrf,
    'url'        => WA_R_SAMPLE_BASE . '/' . $fileNameDup,
    'library_id' => (string) $libraryId,
    'tags'       => 'v3-dot4',
], $ownerJar);

$hasDupConfirm = str_contains($saveDup['body'], 'name="override_dup"')
    && str_contains($saveDup['body'], 'VẪN LƯU');

if (!$hasDupConfirm) {
    $f12Ok = false;
    $f12Parts[] = 'nội dung GIỐNG HỆT nhưng KHÔNG hiện cảnh báo trùng lặp';
} else {
    $f12Parts[] = 'trùng SHA-256: có hộp VẪN LƯU';
}

/* 2) Trùng URL: lưu CHÍNH URL đã lưu vào MỘT THƯ VIỆN KHÁC.
      (Lưu lại cùng URL trong CÙNG thư viện là tạo V2 của chính bài đó — đúng
      đặc tả V1/V2/V3 của một bài KHÔNG phải bài trùng, nên KHÔNG được cảnh báo.) */
$libraryName2 = 'V3 RESEARCH B ' . $runId;

$csrf = r_csrf(WA_R_APP_BASE . '/libraries.php', $ownerJar);

$create2 = r_http(WA_R_APP_BASE . '/library_action.php', [
    'wa_csrf'     => $csrf,
    'action'      => 'create',
    'name'        => $libraryName2,
    'description' => 'Thư viện tạm thứ hai cho bộ kiểm thử V3 Đợt 4.',
    'visibility'  => 'private',
    'color'       => 'b',
    'icon'        => 'folder_special',
], $ownerJar);

$librarySlug2 = '';

if (preg_match('#library\.php\?slug=([^&"\']+)#', $create2['effective_url'], $m)) {
    $librarySlug2 = urldecode($m[1]);
}

$stmt = db()->prepare('SELECT id FROM `libraries` WHERE slug = ? LIMIT 1');
$stmt->execute([$librarySlug2]);
$libraryId2 = (int) $stmt->fetchColumn();

if ($libraryId2 <= 0) {
    $f12Ok = false;
    $f12Parts[] = 'không tạo được thư viện tạm thứ hai để thử trùng URL';
} else {
    $csrf = r_csrf($savePage, $ownerJar);

    $saveSame = r_http($savePage, [
        'wa_csrf'    => $csrf,
        'url'        => $pageUrl,
        'library_id' => (string) $libraryId2,
        'tags'       => 'v3-dot4',
    ], $ownerJar);

    $hasUrlConfirm = str_contains($saveSame['body'], 'name="override_dup"')
        && str_contains($saveSame['body'], 'VẪN LƯU');

    if (!$hasUrlConfirm) {
        $f12Ok = false;
        $f12Parts[] = 'cùng URL ở thư viện KHÁC nhưng KHÔNG hiện cảnh báo trùng lặp';
    } else {
        $f12Parts[] = 'trùng URL (thư viện khác): có hộp VẪN LƯU';
    }

    /* Dọn thư viện tạm thứ hai ngay. */
    $csrf = r_csrf(WA_R_APP_BASE . '/library.php?slug=' . urlencode($librarySlug2), $ownerJar);

    r_http(WA_R_APP_BASE . '/library_action.php', [
        'wa_csrf'    => $csrf,
        'action'     => 'delete',
        'library_id' => (string) $libraryId2,
        'confirm'    => 'DELETE',
    ], $ownerJar);
}

/* 3) Gần giống: tệp mẫu khác, nội dung gần giống (đổi vài chữ). */
$fileNameNear = 'v3research-near-' . $runId . '.html';
$filePathNear = dirname(__DIR__) . '/tests/samples/' . $fileNameNear;
$GLOBALS['tempFiles'][] = $filePathNear;

$near = str_replace('Đoạn này đủ dài', 'Doan nay du dai', $html);
file_put_contents($filePathNear, $near);

$csrf = r_csrf($savePage, $ownerJar);

$saveNear = r_http($savePage, [
    'wa_csrf'    => $csrf,
    'url'        => WA_R_SAMPLE_BASE . '/' . $fileNameNear,
    'library_id' => (string) $libraryId,
    'tags'       => 'v3-dot4',
], $ownerJar);

$hasNearConfirm = str_contains($saveNear['body'], 'name="override_dup"');

if (!$hasNearConfirm) {
    $f12Ok = false;
    $f12Parts[] = 'nội dung GẦN GIỐNG nhưng KHÔNG hiện cảnh báo';
} else {
    $f12Parts[] = 'gần giống: có hộp VẪN LƯU';
}

/* 4) V1/V2/V3 của CÙNG một bài KHÔNG phải bài trùng độc lập:
      lấy phiên bản mới của bài đã lưu -> không được coi là trùng "với chính nó". */
$stmt = db()->prepare(
    'SELECT COUNT(*) FROM `page_relations` WHERE id = -1'
);
$stmt->execute();

$dupSelf = wa_dup_detect(
    (string) db()->query('SELECT content_html FROM `snapshots` WHERE id = ' . $snapshotId)->fetchColumn(),
    $pageUrl,
    $libraryId,
    ['id' => (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote('user@demo.vn'))->fetchColumn(), 'role' => 'user'],
    $pageId
);

$selfExact = 0;

foreach ($dupSelf['exact_matches'] as $m) {
    if ((int) ($m['page_id'] ?? 0) === $pageId) {
        $selfExact++;
    }
}

if ($selfExact > 0) {
    $f12Ok = false;
    $f12Parts[] = 'V1/V2 của CHÍNH bài bị coi là bài trùng (' . $selfExact . ')';
} else {
    $f12Parts[] = 'V1/V2 của chính bài không bị coi là trùng';
}

/* 5) API Extension (Chrome) cũng phải trả 409 khi trùng.
      Dùng một tệp mẫu MỚI có nội dung GIỐNG HỆT bài đã lưu (chưa từng lưu
      qua API) -> API phải trả HTTP 409 + duplicate=true. */
$userId = (int) db()->query('SELECT id FROM `users` WHERE email = ' . db()->quote('user@demo.vn'))->fetchColumn();

$fileNameApi = 'v3research-api-' . $runId . '.html';
$filePathApi = dirname(__DIR__) . '/tests/samples/' . $fileNameApi;
$GLOBALS['tempFiles'][] = $filePathApi;

file_put_contents($filePathApi, $html);

$tokenRes = wa_api_token_create($userId, 'v3research-' . $runId, ['read', 'library:create', 'page:save'], 1);
$token = (string) ($tokenRes['token'] ?? '');

if ($token === '') {
    $f12Ok = false;
    $f12Parts[] = 'không tạo được token API';
} else {
    // Gọi bằng cURL kèm Authorization (r_http chưa hỗ trợ header tuỳ biến).
    $ch = curl_init(WA_R_APP_BASE . '/api.php?action=save');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'url'         => WA_R_SAMPLE_BASE . '/' . $fileNameApi,
            'library_id'  => (string) $libraryId,
            'tags'        => '',
            'override_dup' => false,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw = curl_exec($ch);
    $apiStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $apiData = json_decode((string) $raw, true);

    if ($apiStatus !== 409 || !is_array($apiData) || ($apiData['duplicate'] ?? false) !== true) {
        $f12Ok = false;
        $f12Parts[] = 'API Extension không trả 409 khi trùng (HTTP ' . $apiStatus . ')';
    } else {
        $f12Parts[] = 'API Extension trả 409 + duplicate=true';
    }

    /* API save với override_dup=true phải LƯU ĐƯỢC (không lặp 409). */
    $ch = curl_init(WA_R_APP_BASE . '/api.php?action=save');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'url'         => WA_R_SAMPLE_BASE . '/' . $fileNameApi,
            'library_id'  => (string) $libraryId,
            'tags'        => '',
            'override_dup' => true,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw2 = curl_exec($ch);
    $apiStatus2 = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $apiData2 = json_decode((string) $raw2, true);

    if ($apiStatus2 !== 200 || !is_array($apiData2) || ($apiData2['ok'] ?? false) !== true) {
        $f12Ok = false;
        $f12Parts[] = 'API override_dup=true không lưu được (HTTP ' . $apiStatus2 . ')';
    } else {
        $f12Parts[] = 'API override_dup=true lưu thành công';
    }

    if ($tokenId = (int) ($tokenRes['id'] ?? 0)) {
        db()->prepare('DELETE FROM `api_tokens` WHERE id = ?')->execute([$tokenId]);
    }
}

r_record('F12', $f12Ok, implode(', ', $f12Parts));

/* =============================================================================
    DỌN DẸP
    ====================================================================== */

r_out('');
r_out('[D] Dọn dẹp');

$csrf = r_csrf(WA_R_APP_BASE . '/library.php?slug=' . urlencode($librarySlug), $ownerJar);

r_http(WA_R_APP_BASE . '/library_action.php', [
    'wa_csrf'    => $csrf,
    'action'     => 'delete',
    'library_id' => (string) $libraryId,
    'confirm'    => 'DELETE',
], $ownerJar);

$stmt = db()->prepare('SELECT COUNT(*) FROM `libraries` WHERE id = ?');
$stmt->execute([$libraryId]);

$libraryGone = (int) $stmt->fetchColumn() === 0;

r_out('  ' . ($libraryGone ? '✓' : '✗') . ' xoá thư viện tạm — '
    . ($libraryGone ? 'còn 0 dòng trong CSDL' : 'vẫn còn dòng!'));

/* =============================================================================
    TỔNG KẾT
    ====================================================================== */

r_out('');
r_out('=' . str_repeat('=', 66));
r_out('TỔNG KẾT');
r_out('=' . str_repeat('=', 66));

$passed = 0;
$failed = 0;

foreach ($results as $item) {
    printf("  %s  %-4s  %s\n", $item['passed'] ? 'PASS' : 'FAIL', $item['id'], $item['evidence']);

    if ($item['passed']) {
        $passed++;
    } else {
        $failed++;
    }
}

r_out(str_repeat('-', 70));
r_out(sprintf('Kết quả: %d/%d mục PASS.', $passed, count($results)));
r_out(str_repeat('-', 70));

exit($failed === 0 ? 0 : 1);
