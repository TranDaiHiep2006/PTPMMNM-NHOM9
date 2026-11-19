<?php
/**
 * src/url_policy.php
 * -----------------------------------------------------------------------------
 * CHÍNH SÁCH URL CÔNG KHAI (V3 / ĐỢT 3 — điều chỉnh F7)
 * -----------------------------------------------------------------------------
 * MỤC TIÊU
 *   - KHÔNG bắt buộc quản trị viên duyệt từng tên miền trước khi lưu trang.
 *   - Mọi URL http(s) công khai đều có thể được gửi yêu cầu lưu ngay.
 *   - NHƯNG vẫn phải đi qua một CHỐT AN TOÀN cứng ở tầng máy chủ.
 *
 * HAI LỚP KIỂM TRA (tách bạch, không lẫn vào nhau)
 * -----------------------------------------------------------------------------
 *   LỚP 1 — CHỐT AN TOÀN (cứng, KHÔNG thao tác nào bỏ qua được)
 *     - Giao thức chỉ http:// và https://.
 *     - URL không chứa thông tin đăng nhập (user:password).
 *     - Tên miền hợp lệ; chặn tên miền nội bộ và cloud metadata.
 *     - DNS phải phân giải được; mọi IP trả về phải là IP CÔNG KHAI.
 *       Chặn: loopback, RFC1918, CGNAT, link-local (gồm 169.254.169.254),
 *       ULA, multicast, dải đặc biệt, IPv4-mapped tới IPv4 riêng tư, NAT64,
 *       6to4, discard-only.
 *     - GHIM IP đã xác minh vào cURL (CURLOPT_RESOLVE) để chống DNS rebinding.
 *     - MỌI bước chuyển hướng đều phải đi qua lại chốt này.
 *
 *   LỚP 2 — TÍN HIỆU RỦI RO (mềm, chỉ khi CÓ BẰNG CHỨNG kiểm chứng được)
 *     - `no_tls`           : dùng HTTP không mã hoá.
 *     - `ip_literal`       : địa chỉ IP thay vì tên miền (không có danh tính).
 *     - `nonstandard_port` : chạy ở cổng khác 80/443.
 *     - `homograph`        : nhãn punycode "xn--" (nguy cơ giả mạo tên miền).
 *
 *     KHÔNG cảnh báo chỉ vì tên miền "chưa có trong CSDL" — đó không phải bằng
 *     chứng rủi ro. Ở tầng này hệ thống KHÔNG tuyên bố URL nào là "an toàn"
 *     hay "độc hại": nó chỉ nêu đúng những gì đã kiểm chứng được.
 *
 *   Tên miền trong `allowlist_domains` nay là danh sách TIN CẬY: được miễn
 *   cảnh báo. Việc lưu KHÔNG còn phụ thuộc danh sách này.
 *
 * DÙNG CHUNG
 *   Extension · lưu thủ công (save.php) · lưu hàng loạt (bulk_save.php) ·
 *   RSS/Atom (feeds.php) · Auto Version — tất cả đi qua cùng một hàm này.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/*
 * File này KHÔNG nạp capture.php để tránh nạp vòng: capture.php nạp
 * url_policy.php. Hàm wa_capture_is_allowlisted() (dùng để đối chiếu danh
 * sách TIN CẬY) được gọi qua function_exists() nên hoạt động đúng dù thứ tự
 * nạp là thế nào.
 */

/* =============================================================================
    HẰNG SỐ
    ============================================================================= */

/** Tên miền hệ thống bị chặn tuyệt đối (cloud metadata, dịch vụ nội bộ). */
const WA_URL_POLICY_BLOCKED_HOSTS = [
    'metadata.google.internal',
    'metadata.goog',
    'metadata',
    'instance-data',
    'instance-data.ec2.internal',
    '169.254.169.254',
];

/** Hậu tố tên miền bị chặn tuyệt đối (mạng nội bộ / tên miền đặc biệt). */
const WA_URL_POLICY_BLOCKED_SUFFIXES = [
    '.localhost',
    '.local',
    '.internal',
    '.home.arpa',
    '.lan',
];

/* =============================================================================
    CHẾ ĐỘ KIỂM THỬ (CHỈ CLI) — CHO PHÉP LOCALHOST / LOOPBACK
    -----------------------------------------------------------------------------
    VÌ SAO CẦN:
      Bộ kiểm thử tự động (scripts/run_tests.php) và script nạp trang mẫu
      (scripts/seed_samples.php) chạy máy chủ thật của dự án trên
      `http://localhost:<port>`. Chính sách URL chặn loopback nên bộ test cũ
      KHÔNG chạy được — nhưng bỏ chốt SSRF thì mất an toàn.

    CÁCH BẬT (tường minh, KHÔNG kích hoạt được qua request web):
      1. Tiến trình PHP phải chạy ở SAPI `cli` hoặc `cli-server`
         (dòng lệnh / máy chủ tích hợp `php -S` của bộ kiểm thử).
         Trên máy chủ thật (php-fpm, Apache mod_php) SAPI KHÔNG nằm trong danh
         sách này -> chế độ này LUÔN TẮT, kể cả khi có biến môi trường.
      2. Phải có biến môi trường `WA_TEST_ALLOW_LOCAL=1` (hoặc
         `WA_DEV_ALLOW_LOCAL=1`) do CHÍNH TIẾN TRÌNH đặt khi khởi động.
         Một request HTTP không thể tạo ra biến môi trường của tiến trình,
         nên web KHÔNG BAO GIỜ bật được chế độ này.

    PHẠM VI ĐƯỢC NỚI LỎNG (rất hẹp):
      - Chỉ `localhost`, `*.localhost` và địa chỉ loopback 127.0.0.0/8, ::1.
      - Cloud metadata (169.254.169.254) và MỌI địa chỉ mạng riêng khác
        (10/8, 172.16/12, 192.168/16, CGNAT, link-local...) VẪN BỊ CHẶN.
      - Tín hiệu rủi ro mềm (no_tls / nonstandard_port / ip_literal) bị TẮT
        cho đích loopback để bộ kiểm thử không bị vướng hộp thoại cảnh báo.
      - Chốt SSRF cứng, ghim IP (CURLOPT_RESOLVE) và kiểm tra lại ở từng bước
        chuyển hướng vẫn giữ nguyên.
    --------------------------------------------------------------------------
    */

const WA_URL_POLICY_TEST_ENV_PRIMARY   = 'WA_TEST_ALLOW_LOCAL';
const WA_URL_POLICY_TEST_ENV_SECONDARY = 'WA_DEV_ALLOW_LOCAL';

/**
 * Chế độ kiểm thử có đang bật không?
 *
 * @return bool true khi VÀ CHỈ KHI: SAPI là cli/cli-server VÀ biến môi
 *              trường kiểm thử được đặt ở mức '1'.
 */
function wa_url_policy_test_mode(): bool
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $cached = false;

    // (1) SAPI: chỉ dòng lệnh và máy chủ tích hợp của PHP.
    if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        return $cached;
    }

    // (2) Biến môi trường của TIẾN TRÌNH — không kích hoạt được qua web.
    foreach ([WA_URL_POLICY_TEST_ENV_PRIMARY, WA_URL_POLICY_TEST_ENV_SECONDARY] as $name) {
        $value = getenv($name);

        if ($value !== false && $value === '1') {
            // Phòng vệ: nếu cờ này xuất hiện trong dữ liệu REQUEST thì đó là
            // dữ liệu do người gọi gửi lên — KHÔNG được bật chế độ kiểm thử.
            // (Trong CLI, `$_SERVER` chứa sẵn biến môi trường nên chỉ kiểm tra
            //  các header HTTP_*, tức dữ liệu do người gọi thực sự gửi lên.)
            $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

            foreach ([$_GET, $_POST, $_COOKIE] as $bag) {
                if (is_array($bag) && array_key_exists($name, $bag)) {
                    return $cached; // vẫn là false
                }
            }

            if (isset($_SERVER[$httpKey])) {
                return $cached; // vẫn là false
            }

            $cached = true;

            return $cached;
        }
    }

    return $cached;
}

/**
 * Địa chỉ này có phải loopback không? (127.0.0.0/8 hoặc ::1)
 */
function wa_url_policy_ip_is_loopback(string $ip): bool
{
    $packed = @inet_pton($ip);

    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        /** @var array<int,int> $b */
        $b = array_values(unpack('C4', $packed));

        return $b[0] === 127;
    }

    // ::1/128
    for ($i = 0; $i < 15; $i++) {
        if ($packed[$i] !== "\0") {
            return false;
        }
    }

    return $packed[15] === "\1";
}

/**
 * Tên miền này có phải dạng "localhost" (chỉ dùng cho chế độ kiểm thử) không?
 */
function wa_url_policy_host_is_localhost(string $host): bool
{
    $host = strtolower(trim($host));

    return $host === 'localhost' || str_ends_with($host, '.localhost');
}

/* =============================================================================
    LỚP 1 — CHỐT AN TOÀN
    ============================================================================= */

/**
 * Địa chỉ IP này có phải địa chỉ CÔNG KHAI (Internet) không?
 *
 * Trả về false với MỌI dải địa chỉ không an toàn để máy chủ tải về:
 * loopback, riêng tư (RFC1918), CGNAT, link-local (gồm địa chỉ cloud
 * metadata 169.254.169.254), ULA, multicast, các dải đặc biệt, IPv4-mapped
 * trỏ về IPv4 riêng tư, NAT64, 6to4 và discard-only.
 */
function wa_url_policy_ip_is_public(string $ip): bool
{
    $packed = @inet_pton($ip);

    if ($packed === false) {
        return false;
    }

    return strlen($packed) === 4
        ? wa_url_policy_ipv4_is_public($packed)
        : wa_url_policy_ipv6_is_public($packed);
}

/**
 * IPv4 (4 byte đã nén) có công khai không?
 */
function wa_url_policy_ipv4_is_public(string $packed): bool
{
    /** @var array<int,int> $b */
    $b = array_values(unpack('C4', $packed));

    // 0.0.0.0/8 — "mạng này"
    if ($b[0] === 0) {
        return false;
    }

    // 10.0.0.0/8 — riêng tư
    if ($b[0] === 10) {
        return false;
    }

    // 100.64.0.0/10 — CGNAT (carrier-grade NAT)
    if ($b[0] === 100 && $b[1] >= 64 && $b[1] <= 127) {
        return false;
    }

    // 127.0.0.0/8 — loopback
    if ($b[0] === 127) {
        return false;
    }

    // 169.254.0.0/16 — link-local (gồm 169.254.169.254 = cloud metadata)
    if ($b[0] === 169 && $b[1] === 254) {
        return false;
    }

    // 172.16.0.0/12 — riêng tư
    if ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31) {
        return false;
    }

    // 192.168.0.0/16 — riêng tư
    if ($b[0] === 192 && $b[1] === 168) {
        return false;
    }

    // 192.0.0.0/24 — IETF protocol assignments
    if ($b[0] === 192 && $b[1] === 0 && $b[2] === 0) {
        return false;
    }

    // 192.0.2.0/24 — TEST-NET-1
    if ($b[0] === 192 && $b[1] === 0 && $b[2] === 2) {
        return false;
    }

    // 192.88.99.0/24 — 6to4 relay anycast
    if ($b[0] === 192 && $b[1] === 88 && $b[2] === 99) {
        return false;
    }

    // 198.18.0.0/15 — dải đo hiệu năng (benchmark)
    if ($b[0] === 198 && ($b[1] === 18 || $b[1] === 19)) {
        return false;
    }

    // 198.51.100.0/24 — TEST-NET-2
    if ($b[0] === 198 && $b[1] === 51 && $b[2] === 100) {
        return false;
    }

    // 203.0.113.0/24 — TEST-NET-3
    if ($b[0] === 203 && $b[1] === 0 && $b[2] === 113) {
        return false;
    }

    // 224.0.0.0/4 multicast · 240.0.0.0/4 dành riêng · 255.255.255.255
    if ($b[0] >= 224) {
        return false;
    }

    return true;
}

/**
 * IPv6 (16 byte đã nén) có công khai không?
 */
function wa_url_policy_ipv6_is_public(string $packed): bool
{
    /** @var array<int,int> $b */
    $b = array_values(unpack('C16', $packed));

    // ::/128 — địa chỉ không xác định
    $allZero = true;

    for ($i = 0; $i < 16; $i++) {
        if ($b[$i] !== 0) {
            $allZero = false;
            break;
        }
    }

    if ($allZero) {
        return false;
    }

    // ::1/128 — loopback
    if ($b[15] === 1
        && $b[0] === 0 && $b[1] === 0 && $b[2] === 0 && $b[3] === 0
        && $b[4] === 0 && $b[5] === 0 && $b[6] === 0 && $b[7] === 0
        && $b[8] === 0 && $b[9] === 0 && $b[10] === 0 && $b[11] === 0
        && $b[12] === 0 && $b[13] === 0 && $b[14] === 0) {
        return false;
    }

    // ::ffff:0:0/96 — IPv4-mapped: xét IPv4 bên trong
    $mapped = true;

    for ($i = 0; $i < 10; $i++) {
        if ($b[$i] !== 0) {
            $mapped = false;
            break;
        }
    }

    if ($mapped && $b[10] === 0xFF && $b[11] === 0xFF) {
        $v4 = chr($b[12]) . chr($b[13]) . chr($b[14]) . chr($b[15]);

        return wa_url_policy_ipv4_is_public($v4);
    }

    // 64:ff9b::/96 — NAT64 (có thể ánh xạ tới IPv4 riêng tư)
    if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xFF && $b[3] === 0x9B) {
        return false;
    }

    // 2002::/16 — 6to4
    if ($b[0] === 0x20 && $b[1] === 0x02) {
        return false;
    }

    // fc00::/7 — Unique Local Address (riêng tư)
    if (($b[0] & 0xFE) === 0xFC) {
        return false;
    }

    // fe80::/10 — link-local
    if ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80) {
        return false;
    }

    // ff00::/8 — multicast
    if ($b[0] === 0xFF) {
        return false;
    }

    // 100::/64 — discard-only
    if ($b[0] === 0x01 && $b[1] === 0x00) {
        return false;
    }

    return true;
}

/**
 * Phân giải tên miền thành danh sách địa chỉ IP.
 *
 * Kết quả được nhớ trong bộ nhớ đệm của request để một bài viết có 20 ảnh
 * không phải phân giải DNS lại 20 lần.
 *
 * @return array<int,string>
 */
function wa_url_policy_resolve_ips(string $host): array
{
    static $cache = [];

    if (isset($cache[$host])) {
        return $cache[$host];
    }

    $ips = [];

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips = [$host];
    } else {
        // A records — gethostbynamel() có sẵn trong PHP core, chạy được trên Windows.
        $v4 = @gethostbynamel($host);

        if (is_array($v4) && $v4 !== []) {
            $ips = array_values($v4);
        }

        // AAAA records — BẮT BUỘC phải lấy cả IPv6, không chỉ IPv4.
        //
        // BUG-14: trên Windows, `localhost` thường chỉ nghe trên [::1] (IPv6)
        // trong khi gethostbynamel() chỉ trả về 127.0.0.1. Nếu chỉ ghim IP
        // IPv4 thì cURL không kết nối được và mọi lần tải đều báo
        // error_timeout dù máy chủ nguồn đang chạy bình thường.
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
        }

        // `localhost` / `*.localhost` KHÔNG đi qua DNS công cộng nên
        // dns_get_record() không trả về gì trên Windows. Bổ sung ::1 thủ công
        // để cURL có thể thử cả hai họ địa chỉ (xem BUG-14 ở trên).
        if (wa_url_policy_host_is_localhost($host)) {
            $ips[] = '::1';
        }
    }

    $ips = array_values(array_unique(array_filter(
        $ips,
        static fn (string $ip): bool => $ip !== ''
    )));

    $cache[$host] = $ips;

    return $ips;
}

/**
 * Danh sách chuỗi "host:port:ip" cho CURLOPT_RESOLVE — ghim IP đã xác minh để
 * chống DNS rebinding (DNS đổi kết quả giữa lúc kiểm tra và lúc kết nối).
 *
 * BUG-14 / sửa lỗi hồi quy T1-T6: TRƯỚC ĐÂY chỉ ghim MỘT IP (IP đầu tiên
 * trong danh sách). Với `localhost`, `gethostbynamel()` trả về 127.0.0.1 nhưng
 * máy chủ `php -S` lại chỉ LẮNG NGHE trên `[::1]` (IPv6) -> cURL bị ghim vào
 * IPv4 không có listener nào và mọi lần tải đều báo error_timeout.
 *
 * Nay ta ghim TẤT CẢ địa chỉ đã xác minh là công khai: cURL sẽ thử lần lượt,
 * vẫn chống DNS rebinding (không có IP nào ngoài danh sách đã kiểm tra) nhưng
 * không còn bỏ sót trường hợp máy chủ chỉ nghe trên một họ địa chỉ.
 *
 * @param array<int,string> $ips Danh sách IP đã xác minh (có thể rỗng).
 *
 * @return array<int,string> Danh sách entry cho CURLOPT_RESOLVE.
 */
function wa_url_policy_resolve_entries(string $host, int $port, array $ips): array
{
    if ($host === '' || $port <= 0 || $ips === []) {
        return [];
    }

    // Host đã là địa chỉ IP thì không cần ghim.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [];
    }

    $entries = [];

    foreach ($ips as $ip) {
        $ip = trim((string) $ip);

        if ($ip === '') {
            continue;
        }

        $entry = $host . ':' . $port . ':' . $ip;

        if (!in_array($entry, $entries, true)) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/* =============================================================================
    LỚP 2 — TÍN HIỆU RỦI RO
    ============================================================================= */

/**
 * Tên miền này có nằm trong danh sách TIN CẬY của quản trị viên không?
 *
 * Danh sách tin cậy CHỈ có tác dụng miễn cảnh báo rủi ro — KHÔNG phải điều
 * kiện để được phép tải.
 */
function wa_url_policy_host_is_trusted(string $host): bool
{
    return function_exists('wa_capture_is_allowlisted') && wa_capture_is_allowlisted($host);
}

/**
 * Tín hiệu rủi ro của một URL.
 *
 * CHỈ trả về tín hiệu khi có bằng chứng kiểm chứng được. Không có tín hiệu
 * nào dựa vào việc "tên miền chưa từng xuất hiện trong CSDL".
 *
 * @return array<int, array{code:string, label:string, detail:string}>
 */
function wa_url_policy_signals(string $host, string $scheme, int $port, bool $trusted): array
{
    // Quản trị viên đã duyệt tên miền này -> không cảnh báo.
    if ($trusted) {
        return [];
    }

    $signals = [];

    if ($scheme === 'http') {
        $signals[] = [
            'code'   => 'no_tls',
            'label'  => 'Không mã hoá',
            'detail' => 'Trang dùng HTTP (không có HTTPS) — nội dung có thể bị thay đổi trên đường truyền.',
        ];
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $signals[] = [
            'code'   => 'ip_literal',
            'label'  => 'Địa chỉ IP thay vì tên miền',
            'detail' => 'Địa chỉ "' . $host . '" không gắn với tên miền nào nên không có danh tính để đối chiếu.',
        ];
    }

    if (!in_array($port, [80, 443], true)) {
        $signals[] = [
            'code'   => 'nonstandard_port',
            'label'  => 'Cổng không chuẩn',
            'detail' => 'Máy chủ phục vụ ở cổng ' . $port . ' thay vì 80 (http) hoặc 443 (https).',
        ];
    }

    if (preg_match('/(^|\.)xn--/i', $host) === 1) {
        $signals[] = [
            'code'   => 'homograph',
            'label'  => 'Tên miền dạng punycode',
            'detail' => 'Tên miền chứa nhãn "xn--" nên có thể là tên miền được tạo để giả mạo tên miền khác.',
        ];
    }

    return $signals;
}

/**
 * Mức độ của tập tín hiệu rủi ro: none | low | medium | high.
 *
 * @param array<int, array{code:string, label:string, detail:string}> $signals
 */
function wa_url_policy_level(array $signals): string
{
    if ($signals === []) {
        return 'none';
    }

    $level = 'low';

    foreach ($signals as $signal) {
        $code = (string) ($signal['code'] ?? '');

        if ($code === 'homograph') {
            return 'high';
        }

        if ($code === 'ip_literal' || $code === 'nonstandard_port') {
            $level = 'medium';
        }
    }

    return $level;
}

/**
 * Câu cảnh báo ngắn gọn cho người dùng (tiếng Việt).
 *
 * @param array<int, array{code:string, label:string, detail:string}> $signals
 */
function wa_url_policy_warning_message(array $signals, string $host = ''): string
{
    if ($signals === []) {
        return '';
    }

    $labels = [];

    foreach ($signals as $signal) {
        $labels[] = (string) ($signal['label'] ?? '');
    }

    $hostPart = $host !== '' ? ' Trang "' . $host . '"' : '';

    return $hostPart . ' có dấu hiệu rủi ro: ' . implode(' · ', $labels)
        . '. Bản lưu vẫn tải được nhưng nội dung có thể không đáng tin cậy.';
}

/* =============================================================================
    HÀM CHÍNH — KIỂM TRA MỘT URL THEO CHÍNH SÁCH
    ============================================================================= */

/**
 * Kiểm tra một URL theo chính sách URL công khai.
 *
 * @return array{
 *   ok:bool,             // đi qua được CHỐT AN TOÀN hay không
 *   code:string,         // mã lý do khi bị chặn
 *   message:string,      // thông điệp tiếng Việt khi bị chặn
 *   host:string,
 *   ip:string,           // IP công khai đã xác minh (để ghim vào cURL)
 *   port:int,
 *   scheme:string,
 *   signals:array<int, array{code:string, label:string, detail:string}>,
 *   level:string,        // none | low | medium | high
 *   trusted:bool         // tên miền nằm trong danh sách tin cậy
 * }
 */
function wa_url_policy_inspect(string $url): array
{
    $result = [
        'ok'      => false,
        'code'    => 'invalid',
        'message' => '',
        'host'    => '',
        'ip'      => '',
        'ips'     => [],
        'port'    => 0,
        'scheme'  => '',
        'signals' => [],
        'level'   => 'none',
        'trusted' => false,
    ];

    $url = trim($url);

    if ($url === '') {
        $result['message'] = 'URL rỗng.';

        return $result;
    }

    // Bỏ khoảng trắng và ký tự điều khiển (có thể dùng để lừa bộ lọc).
    $url = preg_replace('/[\x00-\x20\x7F]/u', '', $url) ?? '';

    $parts = @parse_url($url);

    if (!is_array($parts)) {
        $result['message'] = 'URL không hợp lệ.';

        return $result;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));

    if (!in_array($scheme, ['http', 'https'], true)) {
        $result['code']    = 'bad_scheme';
        $result['message'] = 'Giao thức "' . ($scheme !== '' ? $scheme : '(trống)')
            . '" không được phép. Chỉ chấp nhận http:// và https://.';

        return $result;
    }

    $result['scheme'] = $scheme;

    if (isset($parts['user']) || isset($parts['pass'])) {
        $result['code']    = 'has_credentials';
        $result['message'] = 'URL chứa thông tin đăng nhập (user:password) — bị từ chối.';

        return $result;
    }

    $host = strtolower(trim((string) ($parts['host'] ?? '')));

    // IPv6 literal trong URL: [::1]
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }

    if ($host === '') {
        $result['code']    = 'no_host';
        $result['message'] = 'URL không có tên miền.';

        return $result;
    }

    $result['host'] = $host;

    // Chế độ kiểm thử (chỉ CLI + biến môi trường) -> cho phép đích loopback.
    // Mọi trường hợp khác vẫn đi qua chốt an toàn đầy đủ.
    $testMode    = wa_url_policy_test_mode();
    $isLocalhost = $testMode && wa_url_policy_host_is_localhost($host);
    $isLoopbackIp = $testMode && filter_var($host, FILTER_VALIDATE_IP) !== false
        && wa_url_policy_ip_is_loopback($host);

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

    if ($port < 1 || $port > 65535) {
        $result['code']    = 'bad_port';
        $result['message'] = 'Cổng của URL không hợp lệ.';

        return $result;
    }

    $result['port'] = $port;

    // --- Tên miền bị chặn tuyệt đối ---
    if (in_array($host, WA_URL_POLICY_BLOCKED_HOSTS, true)) {
        $result['code']    = 'blocked_host';
        $result['message'] = 'Tên miền "' . $host . '" là địa chỉ hệ thống nội bộ — bị chặn.';

        return $result;
    }

    foreach (WA_URL_POLICY_BLOCKED_SUFFIXES as $suffix) {
        // Chế độ kiểm thử (chỉ CLI): cho phép `localhost` / `*.localhost`.
        if ($isLocalhost && $suffix === '.localhost') {
            continue;
        }

        if (str_ends_with($host, $suffix)) {
            $result['code']    = 'blocked_host';
            $result['message'] = 'Tên miền "' . $host . '" thuộc mạng nội bộ — bị chặn.';

            return $result;
        }
    }

    // Tên miền phải là tên miền hợp lệ hoặc một địa chỉ IP.
    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

    if (!$isIp
        && !$isLocalhost
        && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) !== 1) {
        $result['code']    = 'bad_host';
        $result['message'] = 'Tên miền "' . $host . '" không hợp lệ.';

        return $result;
    }

    // --- DNS + kiểm tra IP ---
    $ips = wa_url_policy_resolve_ips($host);

    if ($ips === []) {
        $result['code']    = 'dns_failed';
        $result['message'] = 'Không phân giải được tên miền "' . $host
            . '" — không xác minh được đích nên không tải.';

        return $result;
    }

    $publicIp = '';
    $publicIps = [];

    foreach ($ips as $ip) {
        if (!wa_url_policy_ip_is_public($ip)) {
            // Chế độ kiểm thử (chỉ CLI): loopback được phép.
            // Cloud metadata (169.254.169.254) và mọi IP nội bộ KHÁC vẫn chặn.
            if (($isLocalhost || $isLoopbackIp) && wa_url_policy_ip_is_loopback($ip)) {
                if ($publicIp === '') {
                    $publicIp = $ip;
                }

                $publicIps[] = $ip;

                continue;
            }

            $result['code']    = 'private_ip';
            $result['message'] = 'Tên miền "' . $host . '" trỏ tới địa chỉ mạng nội bộ ('
                . $ip . ') — bị chặn để chống SSRF.';

            return $result;
        }

        if ($publicIp === '') {
            $publicIp = $ip;
        }

        $publicIps[] = $ip;
    }

    $result['ip'] = $publicIp;

    // DANH SÁCH IP công khai đã xác minh — dùng để ghim vào cURL.
    // BUG-14: phải ghim TẤT CẢ, không chỉ IP đầu tiên (xem
    // wa_url_policy_resolve_entries()).
    $result['ips'] = array_values(array_unique($publicIps));

    // --- Tín hiệu rủi ro (chỉ khi có bằng chứng) ---
    $result['trusted'] = wa_url_policy_host_is_trusted($host);

    if ($isLocalhost || $isLoopbackIp) {
        // Chế độ kiểm thử: đích loopback không có tín hiệu rủi ro mềm
        // (no_tls / nonstandard_port / ip_literal) để bộ kiểm thử không bị
        // vướng hộp thoại cảnh báo. CHỐT AN TOÀN CỨNG vẫn còn nguyên.
        $result['signals'] = [];
        $result['level']   = 'none';
    } else {
        $result['signals'] = wa_url_policy_signals($host, $scheme, $port, $result['trusted']);
        $result['level']   = wa_url_policy_level($result['signals']);
    }

    $result['ok'] = true;

    return $result;
}

/**
 * URL này có vượt qua CHỐT AN TOÀN không? (bỏ qua phần tín hiệu rủi ro)
 *
 * @return array{ok:bool, code:string, message:string}
 */
function wa_url_policy_is_safe(string $url): array
{
    $policy = wa_url_policy_inspect($url);

    return [
        'ok'      => (bool) $policy['ok'],
        'code'    => (string) $policy['code'],
        'message' => (string) $policy['message'],
    ];
}
