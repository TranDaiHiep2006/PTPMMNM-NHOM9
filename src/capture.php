<?php
/**
 * src/capture.php
 * -----------------------------------------------------------------------------
 * ENGINE TẢI TRANG THẬT (P-CAPTURE) — thay thế hoàn toàn src/mock_capture.php.
 *
 * PHP thuần 8.3, không framework, không package Composer. Chỉ dùng extension
 * cURL (mạng) và DOM/DOMDocument (bóc tách HTML).
 *
 * ── BA NGUYÊN TẮC BẮT BUỘC CỦA HỆ THỐNG ────────────────────────────────────
 * 1. KHÔNG BAO GIỜ lưu trang lỗi (404, 5xx, timeout, ngoài allowlist, không có
 *    nội dung) như một bài viết hợp lệ — mỗi nguyên nhân có error_code riêng.
 * 2. LUÔN đọc nội dung từ bản snapshot đã lưu, không đọc lại trang gốc.
 * 3. Mọi ĐÍCH tải về — kể cả sau khi chuyển hướng và cả ảnh — đều phải được
 *    kiểm tra với allowlist_domains. Không có ngoại lệ.
 *
 * ── CẤU HÌNH ─────────────────────────────────────────────────────────────────
 *   Kết nối 5 s • tổng 12 s • tối đa 5 lần chuyển hướng • tối đa 5 MB mỗi tài
 *   nguyên • tối đa 20 ảnh mỗi bài viết • tối thiểu 200 ký tự văn bản.
 *
 * Luồng của capture_url() (đúng thứ tự trong đặc tả):
 *   1. Kiểm tra URL                                   → error_invalid_url
 *   2. Allowlist TRƯỚC khi gọi mạng                  → error_blocked
 *   3. Tải bằng cURL, TỰ chuyển hướng, kiểm tra
 *      allowlist LẠI trên mọi đích chuyển hướng      → error_blocked
 *      timeout: kết nối 5 s / tổng 12 s              → error_timeout
 *   4. 404 → error_404; 4xx/5xx khác → error_http;
 *      Content-Type không phải HTML                  → error_no_content
 *   5. Bóc tách bằng DOMDocument (tiêu đề + vùng nội dung chính)
 *   6. Văn bản < 200 ký tự                          → error_no_content
 *   7. Sanitizer whitelist tự viết
 *   8. Ảnh tải về public/uploads/snapshots/<id>/ (xem wa_capture_localize_images)
 *   9. content_text: bỏ thẻ + giải thực thể + gộp khoảng trắng
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Thư mục public/ — nơi lưu ảnh đã tải về. */
if (!defined('WA_PUBLIC')) {
    define('WA_PUBLIC', dirname(__DIR__) . '/public');
}

/* =============================================================================
    HẰNG SỐ CẤU HÌNH
    ============================================================================= */

/** User-Agent rõ ràng (nhiều máy chủ chặn UA mặc định của cURL). */
const WA_CAPTURE_USER_AGENT = 'WebArchive/1.0 (offline snapshot reader; +https://example.org/webarchive)';

/** Thời gian chờ kết nối (giây). */
const WA_CAPTURE_CONNECT_TIMEOUT = 5;
/** Thời gian chờ tổng thể cho một lần tải (giây). */
const WA_CAPTURE_TIMEOUT = 12;
/** Số lần chuyển hướng tối đa. */
const WA_CAPTURE_MAX_REDIRECTS = 5;
/** Giới hạn dung lượng cho MỘT tài nguyên tải về (5 MB). */
const WA_CAPTURE_MAX_BYTES = 5 * 1024 * 1024;
/** Số ảnh tối đa lấy về mỗi bài viết. */
const WA_CAPTURE_MAX_IMAGES = 20;
/** Ngưỡng độ dài văn bản tối thiểu để coi là bài viết hợp lệ. */
const WA_CAPTURE_MIN_TEXT = 200;

/* =============================================================================
    DANH SÁCH MÃ LỖI
    ============================================================================= */

const WA_ERR_INVALID_URL = 'error_invalid_url';
const WA_ERR_BLOCKED     = 'error_blocked';
const WA_ERR_TIMEOUT     = 'error_timeout';
const WA_ERR_404         = 'error_404';
const WA_ERR_HTTP        = 'error_http';
const WA_ERR_NO_CONTENT  = 'error_no_content';

/**
 * Nhãn + thông điệp tiếng Việt RIÊNG BIỆT cho từng mã lỗi.
 *
 * public/save.php dùng hàm này để hiện thông báo khác nhau theo nguyên nhân, và
 * ghi vào snapshots.error_message để thống kê quản trị đếm được lỗi.
 *
 * @return array{title:string, message:string}
 */
function wa_capture_error_text(string $code, string $detail = ''): array
{
    $texts = [
        WA_ERR_INVALID_URL => [
            'title'   => 'URL không hợp lệ',
            'message' => 'Địa chỉ URL không hợp lệ. Vui lòng nhập một đường dẫn http:// hoặc https:// đầy đủ.',
        ],
        WA_ERR_BLOCKED => [
            'title'   => 'URL không thuộc danh sách được duyệt',
            'message' => 'Tên miền này chưa được quản trị viên duyệt. Mọi đích tải về — kể cả sau khi chuyển hướng — đều phải nằm trong danh sách được duyệt.',
        ],
        WA_ERR_TIMEOUT => [
            'title'   => 'Hết thời gian chờ khi tải trang',
            'message' => 'Máy chủ nguồn không phản hồi trong thời gian cho phép (kết nối 5 giây, tổng 12 giây). Bạn có thể thử lại sau.',
        ],
        WA_ERR_404 => [
            'title'   => 'Trang gốc trả về 404',
            'message' => 'Máy chủ nguồn trả về mã lỗi 404 (không tìm thấy nội dung). Trang lỗi sẽ không bao giờ được lưu thành một bài viết hợp lệ.',
        ],
        WA_ERR_HTTP => [
            'title'   => 'Máy chủ nguồn trả về lỗi',
            'message' => 'Máy chủ nguồn trả về mã lỗi HTTP nên nội dung chưa tải được đầy đủ. Bài viết không được lưu.',
        ],
        WA_ERR_NO_CONTENT => [
            'title'   => 'Trang không có nội dung đọc được',
            'message' => 'Trang tải về nhưng không có phần nội dung chính đủ dài để đọc (dưới 200 ký tự), hoặc không phải HTML. Trang thông báo và trang rỗng không được lưu.',
        ],
    ];

    $base = $texts[$code] ?? [
        'title'   => 'Không thể lưu trang',
        'message' => 'Không thể lưu trang này. Vui lòng thử lại với một đường dẫn khác.',
    ];

    $detail = trim(preg_replace('/\s+/u', ' ', $detail) ?? '');
    if ($detail !== '') {
        $base['message'] .= ' Chi tiết: ' . $detail . '.';
    }

    return $base;
}

/* =============================================================================
    HÀM TIỆN ÍCH
    ============================================================================= */

/**
 * Chuẩn hoá host từ URL: hạ chữ thường, bỏ dấu chấm cuối và tiền tố "www.".
 */
function wa_capture_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return '';
    }

    return wa_capture_normalize_domain($host);
}

/**
 * Chuẩn hoá một tên miền về dạng so khớp (bỏ "www." và dấu chấm cuối).
 */
function wa_capture_normalize_domain(string $domain): string
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    $domain = (string) preg_replace('/^www\./i', '', $domain);

    return $domain;
}

/**
 * URL tuyệt đối có phải http/https không?
 */
function wa_capture_is_http_url(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return $scheme === 'http' || $scheme === 'https';
}

/**
 * Làm sạch văn bản: giải thực thể HTML rồi gộp khoảng trắng.
 */
function wa_capture_clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Bỏ mọi thẻ khỏi chuỗi HTML để lấy văn bản thuần.
 */
function wa_capture_text_only(string $html): string
{
    // Gỡ nốt script/style (phòng vệ) rồi bỏ thẻ và giải thực thể.
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
    $html = strip_tags($html);

    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Dựng một kết quả lỗi với đúng các khoá của capture_url().
 *
 * @return array<string, mixed>
 */
function wa_capture_failure(string $errorCode, string $detail, int $httpStatus = 0, string $finalUrl = ''): array
{
    return [
        'ok'             => false,
        'error_code'     => $errorCode,
        'error_message'  => $detail,
        'http_status'    => $httpStatus,
        'final_url'      => $finalUrl,
        'title'          => '',
        'content_html'   => '',
        'content_text'   => '',
        'images'         => [],
        'images_skipped' => 0,
        'fetched_at'     => date('Y-m-d H:i:s'),
    ];
}

/* =============================================================================
    BƯỚC 2 — KIỂM TRA ALLOWLIST (chạy TRƯỚC mọi lệnh gọi mạng)
    ============================================================================= */

/**
 * Tên miền có nằm trong bảng `allowlist_domains` không?
 *
 * Quy tắc so khớp (không phân biệt hoa/thường):
 *   - host BẰNG ĐÚNG một tên miền trong danh sách, HOẶC
 *   - host KẾT THÚC bằng "." + tên miền trong danh sách (chấp nhận subdomain).
 *
 * Ví dụ có `example.org` trong danh sách thì `example.org`, `www.example.org`
 * và `blog.example.org` đều được phép, còn `notexample.org` thì KHÔNG.
 *
 * Nếu không đọc được CSDL thì coi như KHÔNG được duyệt — an toàn theo nguyên tắc.
 */
function wa_capture_is_allowlisted(string $host): bool
{
    $host = wa_capture_normalize_domain($host);

    if ($host === '') {
        return false;
    }

    try {
        // Mỗi chỗ dùng MỘT tên tham số khác nhau: PDO đang bật prepares thật
        // (ATTR_EMULATE_PREPARES = false) nên lặp lại một tên tham số trong
        // cùng câu lệnh sẽ gây lỗi SQLSTATE[HY093].
        //
        // Dùng TRIM(LEADING '.' FROM domain) chứ không phải RTRIM/TRIM hai tham số:
        // tham số thứ hai của RTRIM trong MySQL là SỐ LẦN lặp, và bản dựng
        // MySQL của máy này từ chối dạng TRIM(..., <danh sách ký tự>) trong câu
        // truy vấn chuẩn bị. Dạng LEADING ... FROM chạy được và cho cùng kết quả.
        $stmt = db()->prepare(
            "SELECT 1
               FROM `allowlist_domains`
              WHERE LOWER(TRIM(LEADING '.' FROM `domain`)) = :host_exact
                 OR :host_subdomain LIKE CONCAT('%.', LOWER(TRIM(LEADING '.' FROM `domain`)))
              LIMIT 1"
        );
        $stmt->execute([':host_exact' => $host, ':host_subdomain' => $host]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        // Không đọc được allowlist thì coi như KHÔNG được duyệt.
        error_log('[WebArchive] allowlist query failed: ' . $e->getMessage());
        return false;
    }
}

/* =============================================================================
    BƯỚC 3 — TẢI BẰNG cURL (tự chuyển hướng, kiểm tra allowlist mỗi lần)
    ============================================================================= */

/**
 * Tải một tài nguyên, tự xử lý chuyển hướng và kiểm tra allowlist ở MỌI đích.
 *
 * cURL được dùng KHÔNG kèm CURLOPT_FOLLOWLOCATION. Mỗi lần gặp 3xx, ta lấy
 * địa chỉ đích từ CURLINFO_REDIRECT_URL, chạy lại hàm kiểm tra allowlist, rồi
 * mới tải tiếp. Nhờ vậy không có đường nào để một redirect lách khỏi allowlist.
 *
 * @return array{ok:bool, error_code?:string, detail?:string, body?:string,
 *              status:int, final_url:string, content_type:string}
 */
function wa_capture_http_get(string $url): array
{
    $current  = $url;
    $redirect = 0;

    while (true) {
        // --- Allowlist TRƯỚC khi mở kết nối (nguyên tắc số 3) ---
        $host = wa_capture_host($current);
        if (!wa_capture_is_allowlisted($host)) {
            return [
                'ok'           => false,
                'error_code'   => WA_ERR_BLOCKED,
                'detail'       => 'tên miền "' . $host . '" không nằm trong danh sách được duyệt',
                'status'       => 0,
                'final_url'    => $current,
                'content_type' => '',
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => $current,
            CURLOPT_RETURNTRANSFER  => true,
            // KHÔNG dùng CURLOPT_FOLLOWLOCATION — ta đi từng bước để kiểm tra allowlist.
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => WA_CAPTURE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => WA_CAPTURE_TIMEOUT,
            CURLOPT_USERAGENT       => WA_CAPTURE_USER_AGENT,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_BUFFERSIZE      => 65536,
        ]);

        $body      = curl_exec($ch);
        $errno     = curl_errno($ch);
        $errstr    = curl_error($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $redirectTo = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        // --- Lỗi mạng / hết thời gian ---
        if ($body === false || $errno !== 0) {
            $isTimeout = in_array($errno, [
                CURLE_OPERATION_TIMEOUTED,
                CURLE_COULDNT_CONNECT,
                CURLE_GOT_NOTHING,
            ], true);

            return [
                'ok'           => false,
                'error_code'   => $isTimeout ? WA_ERR_TIMEOUT : WA_ERR_HTTP,
                'detail'       => $isTimeout
                    ? 'không có phản hồi trong ' . WA_CAPTURE_CONNECT_TIMEOUT . ' s kết nối / '
                        . WA_CAPTURE_TIMEOUT . ' s tổng thời gian'
                    : 'lỗi mạng cURL ' . $errno . ': ' . $errstr,
                'status'       => 0,
                'final_url'    => $current,
                'content_type' => '',
            ];
        }

        // --- Chuyển hướng: allowlist được kiểm tra LẠI ở vòng lặp kế tiếp ---
        if ($status >= 300 && $status < 400) {
            if ($redirectTo === '') {
                return [
                    'ok'           => false,
                    'error_code'   => WA_ERR_HTTP,
                    'detail'       => 'máy chủ trả về ' . $status . ' nhưng không có địa chỉ chuyển hướng',
                    'status'       => $status,
                    'final_url'    => $current,
                    'content_type' => '',
                ];
            }

            $redirect++;
            if ($redirect > WA_CAPTURE_MAX_REDIRECTS) {
                return [
                    'ok'           => false,
                    'error_code'   => WA_ERR_HTTP,
                    'detail'       => 'quá nhiều lần chuyển hướng (tối đa ' . WA_CAPTURE_MAX_REDIRECTS . ')',
                    'status'       => $status,
                    'final_url'    => $current,
                    'content_type' => '',
                ];
            }

            $next = wa_capture_resolve_url($current, $redirectTo);
            if ($next === '' || !wa_capture_is_http_url($next)) {
                return [
                    'ok'           => false,
                    'error_code'   => WA_ERR_INVALID_URL,
                    'detail'       => 'địa chỉ chuyển hướng không hợp lệ',
                    'status'       => $status,
                    'final_url'    => $current,
                    'content_type' => '',
                ];
            }

            $current = $next;
            continue;
        }

        // --- Giới hạn 5 MB cho một tài nguyên ---
        $body = (string) $body;
        if (strlen($body) > WA_CAPTURE_MAX_BYTES) {
            return [
                'ok'           => false,
                'error_code'   => WA_ERR_HTTP,
                'detail'       => 'nội dung ' . strlen($body) . ' byte vượt quá giới hạn '
                    . WA_CAPTURE_MAX_BYTES . ' byte',
                'status'       => $status,
                'final_url'    => $current,
                'content_type' => $ctype,
            ];
        }

        return [
            'ok'           => true,
            'body'         => $body,
            'status'       => $status,
            'final_url'    => $current,
            'content_type' => $ctype,
        ];
    }
}

/* =============================================================================
    BƯỚC 4 — ÁNH XẠ MÃ HTTP
    ============================================================================= */

/**
 * Ánh xạ mã trạng thái HTTP sang mã lỗi; null nghĩa là 2xx, đi tiếp.
 */
function wa_capture_status_error(int $status): ?string
{
    if ($status === 404) {
        return WA_ERR_404;
    }

    if ($status >= 400) {
        return WA_ERR_HTTP;
    }

    return null;
}

/**
 * Content-Type có phải HTML (hoặc XHTML) không?
 */
function wa_capture_is_html(string $contentType): bool
{
    $ct = strtolower(trim($contentType));
    if ($ct === '') {
        return false;
    }

    // Bỏ tham số charset=... phía sau dấu chấm phẩy.
    $mime = trim(explode(';', $ct)[0]);

    return $mime === 'text/html' || $mime === 'application/xhtml+xml';
}

/* =============================================================================
    PHÂN GIẢI URL
    ============================================================================= */

/**
 * Phân giải một URL (tuyệt đối hoặc tương đối) so với URL cơ sở.
 *
 * Xử lý: URL tuyệt đối, dạng protocol-relative (//host/x), đường dẫn gốc
 * (/a/b), đường dẫn tương đối (a/b, ./a, ../a), query và fragment.
 */
function wa_capture_resolve_url(string $base, string $relative): string
{
    $relative = trim(html_entity_decode($relative, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($relative === '') {
        return '';
    }

    // URL tuyệt đối có scheme.
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $relative) === 1) {
        return $relative;
    }

    $baseParts = parse_url($base);
    if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
        return '';
    }

    $scheme = strtolower((string) $baseParts['scheme']);
    $host   = (string) $baseParts['host'];
    $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    // Protocol-relative.
    if (str_starts_with($relative, '//')) {
        return $scheme . ':' . $relative;
    }

    // Tách fragment và query của URL tương đối.
    $relFragment = '';
    $relQuery    = '';
    if (str_contains($relative, '#')) {
        [$relative, $relFragment] = explode('#', $relative, 2);
    }
    if (str_contains($relative, '?')) {
        [$relative, $relQuery] = explode('?', $relative, 2);
    }

    if ($relative === '') {
        $path = (string) ($baseParts['path'] ?? '/');
        if ($relQuery === '') {
            $relQuery = (string) ($baseParts['query'] ?? '');
        }
    } elseif (str_starts_with($relative, '/')) {
        $path = $relative;
    } else {
        $basePath = (string) ($baseParts['path'] ?? '/');
        $slashPos = strrpos($basePath, '/');
        $dir      = $slashPos === false ? '/' : substr($basePath, 0, $slashPos + 1);
        $path     = $dir . $relative;
    }

    // Chuẩn hoá các đoạn "." và "..".
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '') {
            if ($segment === '' && count($segments) === 0) {
                $segments[] = '';
            }
            continue;
        }
        if ($segment === '..') {
            if (count($segments) > 1) {
                array_pop($segments);
            }
            continue;
        }
        $segments[] = $segment;
    }

    $normalized = implode('/', $segments);
    if ($normalized === '' || $normalized[0] !== '/') {
        $normalized = '/' . $normalized;
    }

    $url = $scheme . '://' . $host . $port . $normalized;

    if ($relQuery !== '') {
        $url .= '?' . $relQuery;
    }
    if ($relFragment !== '') {
        $url .= '#' . $relFragment;
    }

    return $url;
}

/* =============================================================================
    BƯỚC 5 — BÓC TÁCH BẰNG DOMDocument
    ============================================================================= */

/**
 * Phân tích chuỗi HTML thành DOMDocument (mã hoá UTF-8, không gọi mạng).
 */
function wa_capture_dom(string $html): ?DOMDocument
{
    $doc = new DOMDocument();

    // HTML thực tế thường không đúng chuẩn: tắt cảnh báo libxml để không rò
    // thông báo kỹ thuật ra ngoài, nhưng không dùng @ để nuốt lỗi.
    $previous = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return ($ok && $doc->hasChildNodes()) ? $doc : null;
}

/**
 * Gỡ toàn bộ các phần tử có tên nằm trong danh sách.
 *
 * @param string[] $tags
 */
function wa_capture_remove_elements(DOMDocument $doc, array $tags): void
{
    $xpath = new DOMXPath($doc);

    foreach ($tags as $tag) {
        $nodes = $xpath->query('//' . $tag);
        if ($nodes === false) {
            continue;
        }

        // Sao chép trước vì NodeList thay đổi khi phần tử bị xoá.
        $list = iterator_to_array($nodes);
        foreach ($list as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}

/**
 * Tiêu đề theo thứ tự ưu tiên: og:title → thẻ <title> → <h1> đầu tiên.
 */
function wa_capture_extract_title(DOMDocument $doc, string $url): string
{
    $xpath = new DOMXPath($doc);

    $og = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "og:title"]/@content');
    if ($og !== false && $og->length > 0) {
        $value = wa_capture_clean_text((string) $og->item(0)->nodeValue);
        if ($value !== '') {
            return $value;
        }
    }

    $title = $xpath->query('//title');
    if ($title !== false && $title->length > 0) {
        $value = wa_capture_clean_text((string) $title->item(0)->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    $h1 = $xpath->query('//h1');
    if ($h1 !== false && $h1->length > 0) {
        $value = wa_capture_clean_text((string) $h1->item(0)->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    $host = wa_capture_host($url);

    return $host !== '' ? $host : 'Trang không có tiêu đề';
}

/**
 * Chấm điểm mật độ văn bản kiểu Readability đơn giản.
 *
 * Điểm = độ dài văn bản trong thẻ <p> (phần lớn giá trị của bài viết) trừ
 * văn bản nằm trong liên kết — liên kết thường là menu/điều hướng rác.
 *
 * @return array{score:float, text_length:int}
 */
function wa_capture_density(DOMNode $node): array
{
    if (!$node instanceof DOMElement) {
        return ['score' => 0.0, 'text_length' => 0];
    }

    $paragraphText = 0;
    foreach (wa_capture_descendants($node, 'p') as $p) {
        $paragraphText += mb_strlen(wa_capture_clean_text($p->textContent), 'UTF-8');
    }

    $linkText = 0;
    foreach (wa_capture_descendants($node, 'a') as $a) {
        $linkText += mb_strlen(wa_capture_clean_text($a->textContent), 'UTF-8');
    }

    $allText = mb_strlen(wa_capture_clean_text($node->textContent), 'UTF-8');

    $base  = $paragraphText > 0 ? $paragraphText : $allText;
    $score = $base - ($linkText * 0.9);

    return ['score' => max(0.0, (float) $score), 'text_length' => $allText];
}

/**
 * Tìm phần tử chứa nội dung chính: <article> → <main> → div/section có mật độ
 * văn bản cao nhất → <body>.
 */
function wa_capture_find_content_node(DOMDocument $doc): ?DOMElement
{
    $xpath = new DOMXPath($doc);

    $article = $xpath->query('//article');
    if ($article !== false && $article->length > 0 && $article->item(0) instanceof DOMElement) {
        return $article->item(0);
    }

    $main = $xpath->query('//main');
    if ($main !== false && $main->length > 0 && $main->item(0) instanceof DOMElement) {
        return $main->item(0);
    }

    $best      = null;
    $bestScore = 0.0;
    $candidates = $xpath->query('//div | //section');

    if ($candidates !== false) {
        foreach ($candidates as $node) {
            $density = wa_capture_density($node);
            if ($density['score'] > $bestScore) {
                $bestScore = $density['score'];
                $best      = $node;
            }
        }
    }

    if ($best instanceof DOMElement) {
        return $best;
    }

    $body = $doc->getElementsByTagName('body');

    return $body->length > 0 ? $body->item(0) : null;
}

/**
 * Các phần tử cháu có tên thẻ cho trước, nằm trong cây con của $node.
 *
 * @return DOMElement[]
 */
function wa_capture_descendants(DOMNode $node, string $tag): array
{
    $doc = $node instanceof DOMDocument ? $node : $node->ownerDocument;
    if ($doc === null) {
        return [];
    }

    $nodes = (new DOMXPath($doc))->query('.//' . $tag, $node);
    if ($nodes === false) {
        return [];
    }

    $result = [];
    foreach ($nodes as $child) {
        if ($child instanceof DOMElement) {
            $result[] = $child;
        }
    }

    return $result;
}

/* =============================================================================
    BƯỚC 7 — SANITIZER WHITELIST (tự viết, không dùng thư viện ngoài)
    ============================================================================= */

/** Thẻ được phép giữ trong content_html. */
const WA_ALLOWED_TAGS = [
    'p', 'br', 'hr',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'blockquote', 'pre', 'code',
    'strong', 'b', 'em', 'i', 'u',
    'a', 'img',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'figure', 'figcaption', 'div', 'span', 'sup', 'sub',
];

/**
 * Thuộc tính được phép giữ, theo từng thẻ.
 *
 * Mọi thuộc tính khác — kể cả on*, style, class, id, srcset, data-* — đều bị gỡ.
 */
const WA_ALLOWED_ATTRS = [
    'a'   => ['href', 'title'],
    'img' => ['src', 'alt', 'title', 'width', 'height'],
    'td'  => ['colspan', 'rowspan'],
    'th'  => ['colspan', 'rowspan'],
];

/**
 * Thẻ bị gỡ khỏi cây DOM TRƯỚC khi trích xuất nội dung.
 */
const WA_STRIP_TAGS = [
    'script', 'style', 'noscript', 'iframe', 'object', 'embed',
    'form', 'nav', 'header', 'footer', 'aside',
    'svg', 'button', 'input', 'select', 'textarea', 'link', 'meta', 'base',
];

/**
 * Thẻ bị gỡ HOÀN TOÀN (kể cả nội dung con) khi sanitizer gặp.
 *
 * Đây là lưới an toàn thứ hai bên dưới bước gỡ trước: nếu vì lý do nào đó
 * một thẻ nguy hiểm lọt vào cây, nó vẫn biến mất cùng con.
 */
const WA_DROP_TAGS = [
    'script', 'style', 'noscript', 'iframe', 'object', 'embed',
    'svg', 'math', 'form', 'button', 'input', 'select', 'textarea',
];

/**
 * Làm sạch một nhánh DOM theo whitelist và trả về HTML đã vệ sinh.
 *
 * - Thẻ trong WA_DROP_TAGS: gỡ cả phần tử lẫn con.
 * - Thẻ không có trong whitelist: tháo thẻ, giữ lại phần tử con.
 * - Thuộc tính không được phép: gỡ khỏi thẻ.
 * - <a>: chỉ giữ href http/https (đã phân giải tuyệt đối) và thêm
 *   rel="noopener noreferrer"; href khác (javascript:, mailto:, #) → tháo
 *   thẻ <a>, giữ nguyên phần chữ.
 * - <img>: src phân giải tuyệt đối và phải là http/https, nếu không thì gỡ ảnh.
 */
function wa_capture_sanitize(DOMNode $node, string $baseUrl): string
{
    $doc = $node instanceof DOMDocument ? $node : $node->ownerDocument;
    if ($doc === null) {
        return '';
    }

    // Sử lý các phần tử con trước, để khi tháo/gỡ phần tử cha không bỏ sót con.
    wa_capture_walk_sanitize($node, $baseUrl);

    // Chính phần tử gốc (ví dụ <article>) cũng phải qua whitelist. Nếu không
    // được phép thì bọc nó vào một <div> tạm để giữ nguyên cây DOM, rồi lấy
    // HTML BÊN TRONG thẻ đó. (Không tháo node gốc: sau khi tháo, node mất liên
    // kết với tài liệu và saveHTML($node) sẽ trả về chuỗi rỗng.)
    $rootIsAllowed = $node instanceof DOMDocument
        || in_array(strtolower($node->nodeName), WA_ALLOWED_TAGS, true);

    if ($rootIsAllowed) {
        $html = $doc->saveHTML($node);
    } else {
        $wrapper = $doc->createElement('div');
        $parent  = $node->parentNode;

        if ($parent === null) {
            return '';
        }

        $parent->insertBefore($wrapper, $node);
        $wrapper->appendChild($node);

        $html = $doc->saveHTML($wrapper);
        $html = $html === false ? '' : trim((string) preg_replace(
            '#^<div>|</div>$#',
            '',
            $html
        ));

        // Trả cây DOM về nguyên trạng để không ảnh hưởng phần xử lý sau đó.
        $parent->insertBefore($node, $wrapper);
        $parent->removeChild($wrapper);
    }

    if ($html === false) {
        return '';
    }

    // Bỏ bọc <body> nếu node gốc là body.
    $html = (string) preg_replace('#^<body[^>]*>#i', '', (string) $html);
    $html = (string) preg_replace('#</body>$#i', '', $html);

    return trim($html);
}

/**
 * Duyệt cây DOM, áp dụng whitelist cho từng phần tử.
 */
function wa_capture_walk_sanitize(DOMNode $node, string $baseUrl): void
{
    $children = iterator_to_array($node->childNodes);

    foreach ($children as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->nodeName);

        // --- Thẻ nguy hiểm: gỡ cả phần tử lẫn nội dung con ---
        if (in_array($tag, WA_DROP_TAGS, true)) {
            if ($child->parentNode !== null) {
                $child->parentNode->removeChild($child);
            }
            continue;
        }

        // --- Thẻ không nằm trong whitelist: tháo thẻ, giữ lại con ---
        if (!in_array($tag, WA_ALLOWED_TAGS, true)) {
            wa_capture_walk_sanitize($child, $baseUrl);
            wa_capture_unwrap($child);
            continue;
        }

        // Đệ quy vào con TRƯỚC, để thuộc tính của con được lọc trước khi cha
        // có thể bị tháo (khi đó con được giữ lại đúng đã lọc).
        wa_capture_walk_sanitize($child, $baseUrl);

        wa_capture_sanitize_attributes($child, $tag, $baseUrl);

        if ($tag === 'img' && !$child->hasAttribute('src')) {
            if ($child->parentNode !== null) {
                $child->parentNode->removeChild($child);
            }
        }
    }
}

/**
 * Tháo phần tử nhưng giữ lại các phần tử con.
 */
function wa_capture_unwrap(DOMElement $element): void
{
    $parent = $element->parentNode;
    if ($parent === null) {
        return;
    }

    while ($element->firstChild !== null) {
        $parent->insertBefore($element->firstChild, $element);
    }

    $parent->removeChild($element);
}

/**
 * Lọc thuộc tính của một phần tử theo whitelist.
 */
function wa_capture_sanitize_attributes(DOMElement $element, string $tag, string $baseUrl): void
{
    $allowed = WA_ALLOWED_ATTRS[$tag] ?? [];

    // --- Gỡ mọi thuộc tính không nằm trong danh sách cho phép ---
    if ($element->hasAttributes()) {
        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attr->nodeName);
            }
        }
    }

    // --- <a href>: chỉ giữ http/https ---
    if ($tag === 'a') {
        $href     = trim($element->getAttribute('href'));
        $absolute = $href === '' ? '' : wa_capture_resolve_url($baseUrl, $href);

        if ($absolute !== '' && wa_capture_is_http_url($absolute)) {
            $element->setAttribute('href', $absolute);
            // rel do sanitizer tự thêm sau khi đã lọc thuộc tính.
            $element->setAttribute('rel', 'noopener noreferrer');
        } else {
            // javascript:, mailto:, rỗng... → bỏ thẻ <a>, giữ lại phần chữ.
            wa_capture_unwrap($element);
        }

        return;
    }

    // --- <img src>: phân giải tuyệt đối, chỉ giữ http/https ---
    if ($tag === 'img') {
        $src      = trim($element->getAttribute('src'));
        $absolute = $src === '' ? '' : wa_capture_resolve_url($baseUrl, $src);

        if ($absolute !== '' && wa_capture_is_http_url($absolute)) {
            $element->setAttribute('src', $absolute);
        } else {
            $element->removeAttribute('src');
        }
    }
}

/**
 * KIỂM TRA CUỐI — khẳng định HTML đã sanitize không còn nội dung nguy hiểm.
 *
 * Sanitizer đã loại bỏ theo cấu trúc, nhưng ta vẫn kiểm tra lại ở cuối để không
 * bao giờ lọt một trường hợp biên nào vào content_html.
 */
function wa_capture_assert_clean(string $contentHtml): bool
{
    $lower = strtolower($contentHtml);

    // 1) Không còn thẻ nguy hiểm.
    foreach (['script', 'iframe', 'object', 'embed', 'form', 'style', 'noscript', 'svg'] as $tag) {
        if (str_contains($lower, '<' . $tag)) {
            return false;
        }
    }

    // 2) Không còn URL thực thi.
    if (str_contains($lower, 'javascript:') || str_contains($lower, 'vbscript:')) {
        return false;
    }

    // 3) Không còn thuộc tính sự kiện on* (onclick, onerror, onload...).
    if (preg_match('/\son[a-z]+\s*=/i', $lower) === 1) {
        return false;
    }

    return true;
}

/* =============================================================================
    HÀM CHÍNH
    ============================================================================= */

/**
 * Tải và bóc tách MỌT trang web.
 *
 * Ảnh CHƯA được tải về ở đây (cần snapshot_id trước); xem
 * wa_capture_localize_images(). Danh sách ảnh tìm thấy được trả về trong
 * khoá "images" với local_path rỗng.
 *
 * @return array{
 *   ok:bool, error_code:?string, error_message:string, http_status:int,
 *   final_url:string, title:string, content_html:string, content_text:string,
 *   images:array<int,array{original_url:string,local_path:string,mime_type:string,size_bytes:int}>,
 *   images_skipped:int, fetched_at:string
 * }
 */
function capture_url(string $url): array
{
    $url = trim($url);

    /* ---------- BƯỚC 1: KIỂM TRA URL ---------- */
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return wa_capture_failure(WA_ERR_INVALID_URL, 'không phân tích được địa chỉ URL');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = (string) ($parts['host'] ?? '');

    if ($scheme !== 'http' && $scheme !== 'https') {
        return wa_capture_failure(
            WA_ERR_INVALID_URL,
            'giao thức phải là http:// hoặc https://, nhận được "' . $scheme . '"'
        );
    }

    if ($host === '') {
        return wa_capture_failure(WA_ERR_INVALID_URL, 'URL không có tên miền');
    }

    // URL mang thông tin đăng nhập (user:pass@) thì từ chối.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return wa_capture_failure(WA_ERR_INVALID_URL, 'URL không được chứa thông tin đăng nhập (user:password)');
    }

    /* ---------- BƯỚC 2: ALLOWLIST TRƯỚC KHI GỌI MẠNG ---------- */
    if (!wa_capture_is_allowlisted($host)) {
        return wa_capture_failure(
            WA_ERR_BLOCKED,
            'tên miền "' . $host . '" không nằm trong danh sách được duyệt',
            0,
            $url
        );
    }

    /* ---------- BƯỚC 3: TẢI BẰNG cURL ---------- */
    $response = wa_capture_http_get($url);

    if ($response['ok'] !== true) {
        return wa_capture_failure(
            (string) ($response['error_code'] ?? WA_ERR_HTTP),
            (string) ($response['detail'] ?? ''),
            (int) ($response['status'] ?? 0),
            (string) ($response['final_url'] ?? $url)
        );
    }

    $httpStatus  = (int) $response['status'];
    $finalUrl    = (string) $response['final_url'];
    $html        = (string) $response['body'];
    $contentType = (string) $response['content_type'];

    /* ---------- BƯỚC 4: MÃ HTTP + CONTENT-TYPE ---------- */
    $statusError = wa_capture_status_error($httpStatus);

    if ($statusError !== null) {
        return wa_capture_failure(
            $statusError,
            $statusError === WA_ERR_404
                ? 'mã 404 (không tìm thấy nội dung)'
                : 'mã lỗi HTTP ' . $httpStatus,
            $httpStatus,
            $finalUrl
        );
    }

    if (!wa_capture_is_html($contentType)) {
        return wa_capture_failure(
            WA_ERR_NO_CONTENT,
            'Content-Type là "' . ($contentType !== '' ? $contentType : 'trống') . '" chứ không phải HTML',
            $httpStatus,
            $finalUrl
        );
    }

    if (trim($html) === '') {
        return wa_capture_failure(WA_ERR_NO_CONTENT, 'phản hồi rỗng', $httpStatus, $finalUrl);
    }

    /* ---------- BƯỚC 5: BÓC TÁCH BẰNG DOMDocument ---------- */
    $doc = wa_capture_dom($html);

    if ($doc === null) {
        return wa_capture_failure(WA_ERR_NO_CONTENT, 'không phân tích được HTML', $httpStatus, $finalUrl);
    }

    $title = wa_capture_extract_title($doc, $finalUrl);

    // Gỡ phần tử rác TRƯỚC khi tìm vùng nội dung, để chúng không làm nhiễu
    // phép tính mật độ văn bản.
    wa_capture_remove_elements($doc, WA_STRIP_TAGS);

    $contentNode = wa_capture_find_content_node($doc);

    if ($contentNode === null) {
        return wa_capture_failure(WA_ERR_NO_CONTENT, 'không tìm thấy phần nội dung chính', $httpStatus, $finalUrl);
    }

    /* ---------- BƯỚC 6: ĐỘ DÀI VĂN BẢN ---------- */
    $rawHtml   = $doc->saveHTML($contentNode) ?: '';
    $rawText   = wa_capture_clean_text(wa_capture_text_only($rawHtml));
    $textChars = mb_strlen($rawText, 'UTF-8');

    if ($textChars < WA_CAPTURE_MIN_TEXT) {
        return wa_capture_failure(
            WA_ERR_NO_CONTENT,
            'phần nội dung chính chỉ ' . $textChars . ' ký tự, dưới ngưỡng ' . WA_CAPTURE_MIN_TEXT,
            $httpStatus,
            $finalUrl
        );
    }

    /* ---------- BƯỚC 7: SANITIZE ---------- */
    $contentHtml = wa_capture_sanitize($contentNode, $finalUrl);

    // Khẳng định an toàn trước khi tiếp tục.
    if (!wa_capture_assert_clean($contentHtml)) {
        return wa_capture_failure(
            WA_ERR_NO_CONTENT,
            'nội dung sau khi làm sạch vẫn chứa thẻ hoặc thuộc tính không an toàn',
            $httpStatus,
            $finalUrl
        );
    }

    /* ---------- BƯỚC 8 (một phần): GOM DANH SÁCH ẢNH ---------- */
    $images = wa_capture_collect_images($contentHtml);

    /* ---------- BƯỚC 9: content_text ---------- */
    $contentText = wa_capture_clean_text(wa_capture_text_only($contentHtml));

    return [
        'ok'             => true,
        'error_code'     => null,
        'error_message'  => '',
        'http_status'    => $httpStatus,
        'final_url'      => $finalUrl,
        'title'          => $title,
        'content_html'   => $contentHtml,
        'content_text'   => $contentText,
        'images'         => $images,
        'images_skipped' => 0,
        'fetched_at'     => date('Y-m-d H:i:s'),
    ];
}

/**
 * Gom danh sách URL ảnh (src) trong content_html đã sanitize, tối đa 20 ảnh.
 *
 * @return array<int, array{original_url:string, local_path:string, mime_type:string, size_bytes:int}>
 */
function wa_capture_collect_images(string $contentHtml): array
{
    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return [];
    }

    $nodes = (new DOMXPath($doc))->query('//img');
    if ($nodes === false) {
        return [];
    }

    $images = [];
    $seen   = [];

    foreach ($nodes as $img) {
        /** @var DOMElement $img */
        $src = trim($img->getAttribute('src'));
        if ($src === '' || isset($seen[$src])) {
            continue;
        }
        $seen[$src] = true;

        $images[] = [
            'original_url' => $src,
            'local_path'   => '',
            'mime_type'    => '',
            'size_bytes'   => 0,
        ];
    }

    return array_slice($images, 0, WA_CAPTURE_MAX_IMAGES);
}

/* =============================================================================
    BƯỚC 8 — TẢI ẢNH VỀ MÁY CHỦ NỘI BỘ
    ============================================================================= */

/**
 * Kiểm tra dữ liệu bytes có phải ảnh raster hợp lệ không.
 *
 * Chỉ chấp nhận image/jpeg, image/png, image/gif, image/webp — xác minh bằng
 * finfo và/hoặc getimagesizefromstring, KHÔNG tin vào phần mở rộng tệp.
 * SVG luôn bị từ chối vì có thể chứa mã thực thi.
 *
 * @return string|null mime type hợp lệ hoặc null
 */
function wa_capture_verify_image(string $bytes): ?string
{
    if ($bytes === '') {
        return null;
    }

    // SVG / XML -> từ chối ngay trước khi kiểm tra.
    $head = strtolower(substr($bytes, 0, 512));
    if (str_contains($head, '<svg') || str_contains($head, '<?xml')) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // (1) finfo: đọc chữ ký nhị phân thật sự của nội dung.
    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower($detected);
            }
        }
    }

    // (2) getimagesizefromstring: xác nhận đây đúng là ảnh, kèm kích thước.
    $info = @getimagesizefromstring($bytes);

    if ($mime !== null && in_array($mime, $allowed, true)) {
        // finfo nói đúng loại được phép.
        if ($info !== false) {
            return $mime;
        }
        // Một số bản PHP không đọc được kích thước webp nhưng vẫn xác định
        // đúng mime -> vẫn chấp nhận vì finfo đã xác nhận chữ ký.
        if ($mime === 'image/webp') {
            return $mime;
        }
    }

    // (3) Một số bản dựng không có webp trong bảng mime -> thử theo thông tin ảnh.
    if ($info !== false) {
        // getimagesizefromstring trả kích thước ở khoá số (0, 1) và mime ở
        // khoá chữ 'mime' — dùng đúng kiểu dữ liệu thực tế của PHP 8.3.
        $infoMime = strtolower((string) ($info['mime'] ?? ''));
        if (in_array($infoMime, $allowed, true)) {
            return $infoMime;
        }
    }

    return null;
}

/**
 * Đuôi tệp theo mime (chỉ 4 loại ảnh được phép).
 */
function wa_capture_extension_for_mime(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'bin',
    };
}

/**
 * Tải ảnh về public/uploads/snapshots/<snapshot_id>/ và nối lại bằng đường dẫn
 * cục bộ.
 *
 * Vì snapshot_id chỉ có sau khi INSERT, luồng ở public/save.php là:
 *   1) INSERT dòng snapshot (nội dung tạm rỗng)  -> có id
 *   2) gọi hàm này để tải ảnh xuống thư mục theo id
 *   3) UPDATE content_html với src đã trỏ về đường dẫn cục bộ
 *   4) INSERT các dòng assets
 *
 * Mọi ảnh được kiểm tra allowlist GIỐNG HỆT URL trang (kể cả từng bước chuyển
 * hướng). Ảnh ngoài allowlist, ảnh không phải raster hợp lệ, ảnh tải lỗi hoặc
 * vượt giới hạn đều bị GỎ khỏi HTML và đếm vào images_skipped — sau bước này
 * content_html không còn URL ảnh bên ngoài nào.
 *
 * @return array{html:string, images:array<int,array<string,mixed>>, skipped:int}
 */
function wa_capture_localize_images(string $contentHtml, int $snapshotId): array
{
    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return ['html' => $contentHtml, 'images' => [], 'skipped' => 0];
    }

    $imgNodes = (new DOMXPath($doc))->query('//img');
    if ($imgNodes === false) {
        return ['html' => $contentHtml, 'images' => [], 'skipped' => 0];
    }

    $images  = [];
    $skipped = 0;
    $seen    = [];
    $handled = 0;

    foreach (iterator_to_array($imgNodes) as $img) {
        /** @var DOMElement $img */
        if ($img->parentNode === null) {
            continue;
        }

        $remove = static function () use ($img, &$skipped): void {
            if ($img->parentNode !== null) {
                $img->parentNode->removeChild($img);
                $skipped++;
            }
        };

        // Vượt giới hạn 20 ảnh -> gỡ, không được để lọt URL bên ngoài.
        if ($handled >= WA_CAPTURE_MAX_IMAGES) {
            $remove();
            continue;
        }
        $handled++;

        $original = trim($img->getAttribute('src'));
        if ($original === '') {
            $remove();
            continue;
        }

        // Trùng ảnh đã tải -> dùng lại tệp đã lưu.
        if (isset($seen[$original])) {
            $img->setAttribute('src', $seen[$original]['local_path']);
            continue;
        }

        // --- Ảnh ngoài allowlist -> bỏ và đếm ---
        if (!wa_capture_is_http_url($original) || !wa_capture_is_allowlisted(wa_capture_host($original))) {
            $remove();
            continue;
        }

        // --- Tải ảnh (tự chuyển hướng + allowlist ở mỗi bước) ---
        $response = wa_capture_http_get($original);
        if ($response['ok'] !== true) {
            $remove();
            continue;
        }

        $bytes = (string) $response['body'];

        // Chỉ nhận ảnh raster hợp lệ — kiểm tra nội dung thật, không tin đuôi tệp.
        $mime = wa_capture_verify_image($bytes);
        if ($mime === null) {
            $remove();
            continue;
        }

        // --- Ghi tệp với tên băm SHA-256 ---
        $fileName = substr(hash('sha256', $original . '|' . $bytes), 0, 32)
            . '.' . wa_capture_extension_for_mime($mime);
        $dir = WA_PUBLIC . '/uploads/snapshots/' . $snapshotId;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $remove();
            continue;
        }

        if (@file_put_contents($dir . '/' . $fileName, $bytes) === false) {
            $remove();
            continue;
        }

        $localUrl = '/uploads/snapshots/' . $snapshotId . '/' . $fileName;
        $img->setAttribute('src', $localUrl);

        // Kích thước nằm ở khoá SỐ (0 = width, 1 = height); bản PHP này không
        // trả khoá chữ "width"/"height", nên phải đọc theo chỉ số.
        $size = @getimagesizefromstring($bytes);
        if ($size !== false) {
            $img->setAttribute('width', (string) (int) ($size[0] ?? 0));
            $img->setAttribute('height', (string) (int) ($size[1] ?? 0));
        }

        $seen[$original] = [
            'original_url' => $original,
            'local_path'   => $localUrl,
            'mime_type'    => $mime,
            'size_bytes'   => strlen($bytes),
        ];
        $images[] = $seen[$original];
    }

    $body  = $doc->getElementsByTagName('body')->item(0);
    $html  = $body === null ? '' : (string) $doc->saveHTML($body);
    $html  = (string) preg_replace('#^<body[^>]*>#i', '', $html);
    $html  = (string) preg_replace('#</body>$#i', '', $html);

    return ['html' => trim($html), 'images' => $images, 'skipped' => $skipped];
}

/**
 * Kiểm tra cuối: content_html sau khi tải ảnh KHÔNG còn URL ảnh bên ngoài.
 *
 * Mọi <img src> phải là đường dẫn cục bộ; nếu không thì ảnh đó sẽ bị gỡ.
 */
function wa_capture_localize_images_ok(string $contentHtml): bool
{
    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return true;
    }

    $nodes = (new DOMXPath($doc))->query('//img');
    if ($nodes === false) {
        return true;
    }

    foreach ($nodes as $img) {
        /** @var DOMElement $img */
        $src = trim($img->getAttribute('src'));
        if ($src === '' || wa_capture_is_http_url($src)) {
            return false;
        }
    }

    return true;
}