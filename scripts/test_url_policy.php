<?php
/**
 * KIỂM THỬ TĨNH (không mạng, không CSDL) — chính sách URL công khai.
 *
 * Chạy:  php scripts/test_url_policy.php
 *
 * Bản kiểm thử này KHÔNG gọi mạng thật và KHÔNG đụng CSDL — nó chỉ kiểm tra
 * hàm `wa_url_policy_inspect()` với các URL tĩnh.
 *
 * HAI CHẾ ĐỘ:
 *   1. Chế độ THƯỜNG (mặc định) — mọi đích loopback bị chặn.
 *   2. Chế độ KIỂM THỬ (bật bằng biến môi trường `WA_TEST_ALLOW_LOCAL=1`,
 *      chỉ khi chạy từ CLI / `php -S`) — localhost + loopback được phép,
 *      nhưng cloud metadata và mọi IP nội bộ KHÁC vẫn bị chặn.
 *
 * Bản kiểm thử tự nhận biết chế độ đang chạy nên kỳ vọng luôn đúng.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/url_policy.php';

$testMode = wa_url_policy_test_mode();

/**
 * [url, kỳ vọng khi chế độ thường, kỳ vọng khi chế độ kiểm thử]
 *
 * @var array<int, array{0:string, 1:string, 2:string}>
 */
$cases = [
    ['https://example.com/bai-viet.html',          'PUBLIC',  'PUBLIC'],
    ['http://example.com/bai-viet.html',           'PUBLIC',  'PUBLIC'],
    ['https://169.254.169.254/latest/meta-data/',  'BLOCKED', 'BLOCKED'],
    ['http://127.0.0.1:8091/bai-01.html',          'BLOCKED', 'PUBLIC'],
    ['http://localhost:18091/bai-01.html',         'BLOCKED', 'PUBLIC'],
    ['http://[::1]:8091/x',                        'BLOCKED', 'PUBLIC'],
    ['http://10.0.0.5/x',                          'BLOCKED', 'BLOCKED'],
    ['http://192.168.1.10/x',                      'BLOCKED', 'BLOCKED'],
    ['http://172.16.5.5/x',                        'BLOCKED', 'BLOCKED'],
    ['http://169.254.10.1/x',                      'BLOCKED', 'BLOCKED'],
    ['http://metadata.google.internal/',           'BLOCKED', 'BLOCKED'],
    ['http://foo.local/x',                         'BLOCKED', 'BLOCKED'],
    ['http://foo.internal/x',                      'BLOCKED', 'BLOCKED'],
    ['http://foo.home.arpa/x',                     'BLOCKED', 'BLOCKED'],
    ['ftp://example.com/x',                        'BLOCKED', 'BLOCKED'],
    ['http://user:pass@example.com/x',             'BLOCKED', 'BLOCKED'],
];

echo 'Che do kiem thu: ' . ($testMode ? 'BAT (WA_TEST_ALLOW_LOCAL)' : 'TAT') . PHP_EOL;
echo str_repeat('-', 78) . PHP_EOL;

$fail = 0;

foreach ($cases as [$url, $expectNormal, $expectTest]) {
    $r    = wa_url_policy_inspect($url);
    $got  = $r['ok'] === true ? 'PUBLIC' : 'BLOCKED';
    $want = $testMode ? $expectTest : $expectNormal;

    $mark = $got === $want ? 'OK  ' : 'FAIL';

    if ($mark === 'FAIL') {
        $fail++;
    }

    printf(
        "%s %-8s %-46s %s\n",
        $mark,
        $got,
        $url,
        $r['ok'] === true ? ('signals=' . count($r['signals'])) : ('code=' . $r['code'])
    );
}

echo str_repeat('-', 78) . PHP_EOL;

if ($fail > 0) {
    echo "FAIL: $fail truong hop khong dung." . PHP_EOL;
    exit(1);
}

echo 'PASS: tat ca truong hop dung.' . PHP_EOL;
exit(0);
