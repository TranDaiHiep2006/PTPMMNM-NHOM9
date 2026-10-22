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
