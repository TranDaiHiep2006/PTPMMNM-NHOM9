<?php
/**
 * scripts/make_db_local_config.php
 * -----------------------------------------------------------------------------
 * SINH FILE CẤU HÌNH `db.local.php` CHO CỔNG 3306 (CLI, dùng bởi
 * `setup-3306.ps1` và `prepare-3306-config.ps1`).
 *
 * CÁCH CHẠY (không dùng tay — hai script .ps1 gọi giúp):
 *
 *     php scripts/make_db_local_config.php
 *
 * BIẾN MÔI TRƯỜNG (đầu vào):
 *   WA_SRC_CFG  — đường dẫn tuyệt đối tới file cấu hình nguồn (đọc để lấy
 *                 host / dbname / charset). Bắt buộc.
 *   WA_DST_CFG  — đường dẫn tuyệt đối tới file cấu hình ĐÍCH (sẽ ghi đè).
 *                 Bắt buộc.
 *   WA_DB_PORT  — cổng của file đích. Mặc định 3306.
 *   WA_DB_USER  — tài khoản của file đích. Mặc định `wa_app`.
 *
 * MẬT KHẨU:
 *   Đọc từ STDIN (dòng đầu tiên), KHÔNG qua tham số dòng lệnh, KHÔNG qua
 *   biến môi trường (để không bị lộ trong danh sách tiến trình / log).
 *   KHÔNG BAO GIỜ in mật khẩu ra màn hình, KHÔNG ghi vào file log.
 *
 * AN TOÀN CHUỖI:
 *   Dùng `var_export($value, true)` của PHP để sinh LITERAL PHP — đây là
 *   cách chuẩn xác của chính PHP, nên mọi ký tự đặc biệt (dấu nháy đơn,
 *   backslash, ký tự `$`, Unicode, ký tự điều khiển, xuống dòng) đều được
 *   escape đúng và đọc lại ra **y hệt** giá trị gốc.
 *   Bản cũ (nội suy bằng regex trong PowerShell) làm hỏng file khi mật khẩu
 *   chứa `'` hoặc `$` — hàm này loại bỏ hoàn toàn lỗi đó.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/make_db_local_config.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

/**
 * In ra thông báo lỗi (KHÔNG bao giờ kèm mật khẩu).
 */
function cfg_out(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Đọc mật khẩu từ STDIN.
 *
 * Đọc TOÀN BỘ stdin (không dùng `fgets`) rồi bỏ đúng MỘT ký tự xuống dòng
 * cuối mà PowerShell tự thêm khi pipe (`\r\n` hoặc `\n`).
 *
 * Vì sao không dùng `fgets()`: nếu mật khẩu chứa xuống dòng, `fgets()` chỉ
 * đọc tới ký tự xuống dòng ĐẦU TIÊN -> mật khẩu bị cắt cụt -> file cấu hình
 * sai. Đọc hết stdin giữ được mọi ký tự.
 */
function cfg_read_password(): string
{
    $raw = stream_get_contents(STDIN);

    if ($raw === false) {
        return '';
    }

    // Bỏ đúng một ký tự xuống dòng cuối (LF hoặc CRLF) do pipe thêm vào.
    if (substr($raw, -1) === "\n") {
        $raw = substr($raw, 0, -1);

        if (substr($raw, -1) === "\r") {
            $raw = substr($raw, 0, -1);
        }
    }

    return $raw;
}

$srcPath = (string) (getenv('WA_SRC_CFG') ?: '');
$dstPath = (string) (getenv('WA_DST_CFG') ?: '');
$dstPort = (string) (getenv('WA_DB_PORT') ?: '3306');
$dstUser = (string) (getenv('WA_DB_USER') ?: 'wa_app');

if ($srcPath === '' || $dstPath === '') {
    cfg_out('LOI: thieu bien moi truong WA_SRC_CFG hoac WA_DST_CFG.');
    exit(1);
}

if (!is_file($srcPath)) {
    cfg_out('LOI: khong tim thay file cau hinh nguon.');
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 1) Đọc cấu hình nguồn
 * ------------------------------------------------------------------------- */
try {
    $src = require $srcPath;
} catch (Throwable $e) {
    cfg_out('LOI: khong doc duoc file cau hinh nguon.');
    exit(1);
}

if (!is_array($src)) {
    cfg_out('LOI: file cau hinh nguon khong hop le.');
    exit(1);
}

$srcHost = (string) ($src['host'] ?? '127.0.0.1');
$srcName = (string) ($src['dbname'] ?? 'webarchive');
$srcChar = (string) ($src['charset'] ?? 'utf8mb4');

if ($srcChar === '') {
    $srcChar = 'utf8mb4';
}

/* ---------------------------------------------------------------------------
 * 2) Đọc mật khẩu từ STDIN
 * ------------------------------------------------------------------------- */
$password = cfg_read_password();

if ($password === '') {
    cfg_out('LOI: mat khau rong.');
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 3) Sinh nội dung file cấu hình đích
 *
 * `var_export($v, true)` sinh đúng một LITERAL PHP chuẩn cho mỗi giá trị.
 * ------------------------------------------------------------------------- */
$lines = [];

$lines[] = '<?php';
$lines[] = '/**';
$lines[] = ' * config/db.local.php — CẤU HÌNH KẾT NỐI 3306 (sinh tự động).';
$lines[] = ' *';
$lines[] = ' * TUYỆT ĐỐI KHÔNG COMMIT — chứa mật khẩu, nằm trong .gitignore.';
$lines[] = ' */';
$lines[] = '';
$lines[] = 'declare(strict_types=1);';
$lines[] = '';
$lines[] = 'return [';
$lines[] = "    'host'     => " . var_export($srcHost, true) . ',';
$lines[] = "    'port'     => " . (int) $dstPort . ',';
$lines[] = "    'dbname'   => " . var_export($srcName, true) . ',';
$lines[] = "    'user'     => " . var_export($dstUser, true) . ',';
$lines[] = "    'password' => " . var_export($password, true) . ',';
$lines[] = "    'charset'  => " . var_export($srcChar, true) . ',';
$lines[] = '];';
$lines[] = '';

$content = implode("\r\n", $lines);

/* ---------------------------------------------------------------------------
 * 4) Ghi file (UTF-8 không BOM) — chỉ ghi khi ghi thành công mới báo OK
 * ------------------------------------------------------------------------- */
$dir = dirname($dstPath);

if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
    cfg_out('LOI: khong tao duoc thu muc dich.');
    exit(1);
}

$bytes = @file_put_contents($dstPath, $content, LOCK_EX);

if ($bytes === false) {
    cfg_out('LOI: khong ghi duoc file cau hinh dich.');
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 5) Xác minh lại bằng chính PHP: file đích phải đọc ra đúng giá trị
 * ------------------------------------------------------------------------- */
$check = require $dstPath;

if (!is_array($check)) {
    cfg_out('LOI: file vua ghi khong doc lai duoc.');
    exit(1);
}

if ((int) ($check['port'] ?? 0) !== (int) $dstPort) {
    cfg_out('LOI: port trong file dich khong dung.');
    exit(1);
}

if ((string) ($check['user'] ?? '') !== $dstUser) {
    cfg_out('LOI: user trong file dich khong dung.');
    exit(1);
}

// So sánh mật khẩu bằng so sánh chuỗi thuần (hàm hash_equals) — KHÔNG in ra.
if (!is_string($check['password'] ?? null) || !hash_equals($password, $check['password'])) {
    cfg_out('LOI: mat khau doc lai tu file dich KHONG khop.');
    exit(1);
}

cfg_out('OK: da sinh file cau hinh dich.');
exit(0);
