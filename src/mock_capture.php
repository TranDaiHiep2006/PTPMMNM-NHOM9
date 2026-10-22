<?php
/**
 * mock_capture.php
 * -----------------------------------------------------------------------------
 * MÔ PHỎNG QUY TRÌNH LƯU SNAPSHOT — CHỈ DÙNG CHO GIAO DIỆN (UI PHASE).
 *
 * ⚠ CẢNH BÁO: file này KHÔNG thực hiện tải trang, KHÔNG gọi mạng và KHÔNG
 * kiểm tra HTTP thật. Nó chỉ suy ra trạng thái từ URL người dùng nhập theo
 * các quy tắc mô phỏng bên dưới.
 *
 * Phase engine sau sẽ thay toàn bộ hàm này bằng bộ tải HTML + bóc tách tiêu đề /
 * nội dung chính + tải ảnh cục bộ, đồng thời áp dụng ba nguyên tắc bắt buộc:
 *   1. KHÔNG BAO GIỜ lưu trang lỗi (404, 5xx, timeout) như một bài viết hợp lệ.
 *   2. LUÔN đọc nội dung từ bản snapshot đã lưu, không đọc lại trang gốc.
 *   3. Mọi đích tải về — kể cả sau khi chuyển hướng — phải nằm trong allowlist.
 *
 * Quy tắc mô phỏng (theo đặc tả prompt.md):
 *   - Tên miền nằm trong allowlist VÀ đường dẫn không chứa "404"/"timeout"
 *       → SUCCESS
 *   - Tên miền không nằm trong allowlist
 *       → "URL khong thuoc danh sach duoc duyet"
 *   - Đường dẫn chứa "404"
 *       → "Trang goc tra ve 404"
 *   - Đường dẫn chứa "timeout"
 *       → "Het thoi gian cho khi tai trang"
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Trạng thái mô phỏng: thành công. */
const MOCK_STATE_SUCCESS = 'success';
/** Trạng thái mô phỏng: URL không thuộc danh sách được duyệt. */
const MOCK_STATE_NOT_ALLOWED = 'not_allowed';
/** Trạng thái mô phỏng: trang gốc trả về 404. */
const MOCK_STATE_HTTP_404 = 'http_404';
/** Trạng thái mô phỏng: hết thời gian chờ tải trang. */
const MOCK_STATE_TIMEOUT = 'timeout';

/**
 * Chuẩn hóa tên miền từ URL.
 */
function mock_extract_domain(string $url): string
{
    $host = parse_url(trim($url), PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }

    return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
}

/**
 * Đường dẫn (path + query) của URL, dùng để áp dụng quy tắc 404 / timeout.
 */
function mock_extract_path(string $url): string
{
    $path = parse_url(trim($url), PHP_URL_PATH);
    $query = parse_url(trim($url), PHP_URL_QUERY);

    $result = is_string($path) ? $path : '';
    if (is_string($query) && $query !== '') {
        $result .= '?' . $query;
    }

    return strtolower($result);
}

/**
 * Kiểm tra tên miền có nằm trong danh sách được duyệt hay không.
 *
 * Đọc từ bảng `allowlist_domains` (CSDL MySQL). Nếu CSDL không truy cập được
 * thì coi như tên miền KHÔNG được duyệt — an toàn theo nguyên tắc của hệ thống.
 */
function mock_is_allowlisted(string $domain): bool
{
    if ($domain === '') {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM `allowlist_domains` WHERE domain = ? LIMIT 1');
        $stmt->execute([strtolower($domain)]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Mô phỏng kết quả lưu trang cho một URL.
 *
 * @return array{
 *     state: string,
 *     ok: bool,
 *     title: string,
 *     message: string,
 *     detail: string,
 *     icon: string,
 *     domain: string,
 *     url: string
 * }
 */
function mock_capture(string $url): array
{
    $url = trim($url);
    $domain = mock_extract_domain($url);
    $path = mock_extract_path($url);

    $base = [
        'domain' => $domain,
        'url' => $url,
    ];

    // 1) URL rỗng hoặc không phải URL hợp lệ.
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return $base + [
            'state' => MOCK_STATE_NOT_ALLOWED,
            'ok' => false,
            'title' => 'URL không hợp lệ',
            'message' => 'URL không thuộc danh sách được duyệt',
            'detail' => 'Vui lòng nhập một địa chỉ URL đầy đủ, bắt đầu bằng http:// hoặc https://.',
            'icon' => 'link_off',
        ];
    }

    // 2) Đường dẫn chứa "404" — trang gốc trả về lỗi 404.
    if (str_contains($path, '404')) {
        return $base + [
            'state' => MOCK_STATE_HTTP_404,
            'ok' => false,
            'title' => 'Không thể lưu trang',
            'message' => 'Trang gốc trả về 404',
            'detail' => 'Máy chủ nguồn trả về mã lỗi 404 (Không tìm thấy nội dung). Trang lỗi sẽ không bao giờ được lưu thành một bài viết hợp lệ.',
            'icon' => 'error_outline',
        ];
    }

    // 3) Đường dẫn chứa "timeout" — hết thời gian chờ tải trang.
    if (str_contains($path, 'timeout')) {
        return $base + [
            'state' => MOCK_STATE_TIMEOUT,
            'ok' => false,
            'title' => 'Không thể lưu trang',
            'message' => 'Hết thời gian chờ khi tải trang',
            'detail' => 'Máy chủ nguồn không phản hồi trong thời gian cho phép. Bạn có thể thử lại sau hoặc kiểm tra đường dẫn khác.',
            'icon' => 'timer_off',
        ];
    }

    // 4) Tên miền không thuộc danh sách được duyệt.
    if (!mock_is_allowlisted($domain)) {
        return $base + [
            'state' => MOCK_STATE_NOT_ALLOWED,
            'ok' => false,
            'title' => 'Không thể lưu trang',
            'message' => 'URL không thuộc danh sách được duyệt',
            'detail' => 'Tên miền "' . ($domain !== '' ? $domain : 'không xác định') . '" chưa được quản trị viên duyệt. Mọi đích tải về, kể cả sau khi chuyển hướng, đều phải được kiểm tra với danh sách này.',
            'icon' => 'gpp_bad',
        ];
    }

    // 5) Thành công.
    return $base + [
        'state' => MOCK_STATE_SUCCESS,
        'ok' => true,
        'title' => 'Lưu thành công',
        'message' => '“Tìm hiểu kiến trúc PHP & MySQL trong lưu trữ Web”',
        'detail' => '14 ảnh đã tải về máy chủ nội bộ • Dung lượng lưu trữ: 1.84 MB • Bản đọc offline đã sẵn sàng',
        'icon' => 'check_circle',
    ];
}

/**
 * Nhãn tiếng Việt của từng trạng thái mô phỏng (dùng cho bảng trạng thái).
 *
 * @return array<string, string>
 */
function mock_state_labels(): array
{
    return [
        MOCK_STATE_SUCCESS => 'Lưu thành công',
        MOCK_STATE_NOT_ALLOWED => 'URL không thuộc danh sách được duyệt',
        MOCK_STATE_HTTP_404 => 'Trang gốc trả về 404',
        MOCK_STATE_TIMEOUT => 'Hết thời gian chờ khi tải trang',
    ];
}
