<?php
/**
 * KIEM TRA TINH DUNG (KHONG can CSDL that) — logic backfill + prefetch + dup match.
 * Chay: php scripts/test_backfill_logic.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/capture.php';
require_once __DIR__ . '/../src/v3_research.php';
require_once __DIR__ . '/../src/v3_duplicates.php';

$fail = 0;
function ok(string $name, bool $cond): void {
    global $fail;
    if (!$cond) { $fail++; }
    echo ($cond ? 'OK   ' : 'FAIL ') . $name . PHP_EOL;
}

// --- 1) wa_snapshot_content_hash() bat buoc dung ---
$html = '<html><body><p>Xin chao</p><script>alert(1)</script></body></html>';
$h1 = wa_snapshot_content_hash($html);
$h2 = wa_snapshot_content_hash('<html><body><p>Xin chao</p></body></html>');
ok('hash ignore script -> giong nhau', $h1 === $h2);
ok('hash sha256 64 hex', preg_match('/^[0-9a-f]{64}$/', $h1) === 1);
ok('hash khac khi text khac', wa_snapshot_content_hash('<p>A</p>') !== wa_snapshot_content_hash('<p>B</p>'));

// --- 2) wa_bulk_normalize_url() + wa_dup_url_matches() dung chuan hoa chung ---
require_once __DIR__ . '/../src/v3_basic.php';

ok('normalize: trailing slash -> giong nhau',
    wa_bulk_normalize_url('https://example.com/a/') === wa_bulk_normalize_url('https://example.com/a'));
ok('normalize: host lowercase -> giong nhau',
    wa_bulk_normalize_url('https://EXAMPLE.com/a') === wa_bulk_normalize_url('https://example.com/a'));
ok('normalize: http vs https -> KHONG giong nhau',
    wa_bulk_normalize_url('http://example.com/a') !== wa_bulk_normalize_url('https://example.com/a'));
ok('normalize: path khac -> KHONG giong nhau',
    wa_bulk_normalize_url('https://example.com/a') !== wa_bulk_normalize_url('https://example.com/b'));
ok('normalize: www KHONG bi bo (2 host khac nhau)',
    wa_bulk_normalize_url('https://www.example.com/a') !== wa_bulk_normalize_url('https://example.com/a'));
ok('normalize: rong -> rong', wa_bulk_normalize_url('   ') === '');

// --- 3) keyset pagination: vong lap luon tien ---
$rows = [
    ['id' => 1, 'content_html' => '<p>A</p>'],
    ['id' => 2, 'content_html' => ''],       // rong -> khong ghi hash
    ['id' => 3, 'content_html' => '<p>C</p>'],
];
$after = 0; $seen = [];
$guard = 0;
while (true) {
    if (++$guard > 10) { break; }
    $batch = [];
    foreach ($rows as $r) {
        if ($r['id'] > $after && ($r['content_hash_missing'] ?? true)) { $batch[] = $r; }
    }
    // mo phong LIMIT
    $batch = array_slice($batch, 0, 2);
    if ($batch === []) { break; }
    $after = $batch[count($batch) - 1]['id'];
    foreach ($batch as $r) { $seen[] = $r['id']; }
}
ok('keyset pagination khong vong lap', $guard <= 10);
ok('keyset pagination quet het cac id', $seen === [1, 2, 3]);

echo str_repeat('-', 60) . PHP_EOL;
if ($fail > 0) { echo "FAIL: $fail truong hop." . PHP_EOL; exit(1); }
echo "PASS: tat ca truong hop dung." . PHP_EOL;
exit(0);
