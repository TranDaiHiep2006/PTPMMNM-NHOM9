<?php
/**
 * bootstrap.php
 * -----------------------------------------------------------------------------
 * Điểm khởi động chung cho mọi trang trong public/.
 *
 * Nhiệm vụ:
 *   - Xác định đường dẫn gốc của ứng dụng (để trang trong public/admin/ vẫn
 *     trỏ đúng về tài nguyên và trang khác).
 *   - Nạp các tệp dùng chung: auth.php, sample_data.php, mock_capture.php.
 *   - Cung cấp các hàm trợ giúp: wa_e() (escape), wa_url(), wa_asset().
 *
 * Ở phase MySQL sau, chỉ cần thay phần nạp dữ liệu trong src/sample_data.php
 * bằng lớp truy vấn CSDL; giao diện không phải sửa.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
   Polyfill cho các hàm multibyte.
   ----------------------------------------------------------------------------
   mbstring là extension TUỲ CHỌN của PHP và có thể chưa được bật trên một số
   máy Windows khi cài PHP từ file nén (bản winget mặc định chưa có php.ini).
   Vì toàn bộ giao diện là tiếng Việt nên các hàm này là bắt buộc. Bên dưới ta
   cung cấp lại chúng bằng iconv và bảng tra ký tự hợp lệ, để trang không bao giờ
   báo lỗi "Call to undefined function".
   --------------------------------------------------------------------------- */

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $length = @iconv_strlen($string, 'UTF-8');
        return $length === false ? strlen($string) : $length;
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $result = $length === null
            ? @iconv_substr($string, $start, null, 'UTF-8')
            : @iconv_substr($string, $start, $length, 'UTF-8');

        return $result === false ? '' : $result;
    }
}

/** Tách chuỗi UTF-8 thành mảng ký tự (tương thích khi thiếu mbstring). */
if (!function_exists('wa_str_split')) {
    function wa_str_split(string $string): array
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? [] : $chars;
    }
}

/**
 * Bảng tra chữ thường ↔ chữ hoa (dạng precomposed) cho tiếng Việt.
 * Chỉ dùng khi máy không có mbstring.
 */
if (!function_exists('wa_vi_case_map')) {
    function wa_vi_case_map(): array
    {
        static $map = null;

        if ($map === null) {
            $upper = 'ÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬÈÉẺẼẸÊỀẾỂỄỆÌÍỈĨỊÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢÙÚỦŨỤƯỪỨỬỮỰỲÝỶỸỴĐ';
            $lower = 'àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ';

            $upperChars = wa_str_split($upper);
            $lowerChars = wa_str_split($lower);

            // 1) Chữ có dấu tiếng Việt.
            $map = [];
            foreach ($upperChars as $index => $char) {
                $map[$char] = $lowerChars[$index] ?? $char;
            }

            // 2) Chữ Latin ASCII không dấu — cần để hạ chữ hoa xuống.
            foreach (range('A', 'Z') as $asciiUpper) {
                $map[$asciiUpper] = strtolower($asciiUpper);
            }
        }

        return $map;
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($string, MB_CASE_LOWER, 'UTF-8');
        }

        return strtr($string, wa_vi_case_map());
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($string, MB_CASE_UPPER, 'UTF-8');
        }

        return strtr($string, array_flip(wa_vi_case_map()));
    }
}

define('WA_ROOT', dirname(__DIR__));
define('WA_SRC', WA_ROOT . '/src');
define('WA_TEMPLATES', WA_ROOT . '/templates');
define('WA_PUBLIC', WA_ROOT . '/public');

require_once WA_SRC . '/auth.php';
require_once WA_SRC . '/sample_data.php';
require_once WA_SRC . '/mock_capture.php';

/**
 * Đường dẫn gốc của ứng dụng trên web.
 *
 * public/ là web root, nên mọi tài nguyên và trang đều được trỏ bằng đường dẫn
 * tuyệt đối tính từ web root (ví dụ: /assets/css/app.css, /libraries.php,
 * /admin/users.php). Cách này giúp các trang trong public/admin/ không bị sai
 * đường dẫn tài nguyên.
 */
function wa_base_path(): string
{
    return '';
}

/**
 * Dựng URL nội bộ của ứng dụng.
 */
function wa_url(string $path = ''): string
{
    if ($path === '') {
        return '/';
    }

    return '/' . ltrim($path, '/');
}

/**
 * Dựng URL của tài nguyên tĩnh trong public/assets.
 */
function wa_asset(string $path): string
{
    return wa_url('assets/' . ltrim($path, '/'));
}

/**
 * Escape HTML an toàn.
 */
function wa_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Rút gọn văn bản.
 */
function wa_trim(string $value, int $limit = 120): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
}

/**
 * Bọc từ khóa tìm kiếm bằng thẻ <mark> (đã escape phần còn lại).
 */
function wa_highlight(string $text, string $keyword): string
{
    $safe = wa_e($text);

    $keyword = trim($keyword);
    if ($keyword === '') {
        return $safe;
    }

    return (string) preg_replace(
        '/(' . preg_quote(wa_e($keyword), '/') . ')/iu',
        '<mark>$1</mark>',
        $safe
    );
}
