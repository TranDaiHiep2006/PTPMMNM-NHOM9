<?php
/**
 * scripts/test_v3_share_sanitize.php
 * Kiểm tra nhanh hàm làm sạch HTML cho bản chia sẻ (KHÔNG cần CSDL).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/v3_share.php';

$cases = [
    [
        'name' => 'bỏ script + on* + javascript:',
        'html' => '<p onclick="evil()">Hello <script>alert(1)</script><a href="javascript:x">bad</a></p>',
        'must_not_contain' => ['script', 'onclick', 'javascript:'],
        'must_contain' => ['Hello'],
    ],
    [
        'name' => 'bỏ iframe + form + style',
        'html' => '<div><iframe src="http://x"></iframe><form><input name="a"></form><style>p{}</style><p>ok</p></div>',
        'must_not_contain' => ['<iframe', '<form', '<input', '<style'],
        'must_contain' => ['ok'],
    ],
    [
        'name' => 'giữ ảnh và liên kết http',
        'html' => '<p><img src="/public/uploads/snapshots/a/b.png"><a href="https://example.com/x">link</a></p>',
        'must_not_contain' => [],
        'must_contain' => ['<img', 'https://example.com/x'],
    ],
];

$failed = 0;

foreach ($cases as $case) {
    $out = wa_share_sanitize_html((string) $case['html']);

    $ok = true;

    foreach ($case['must_not_contain'] as $needle) {
        if (stripos($out, (string) $needle) !== false) {
            $ok = false;
            echo 'FAIL [' . $case['name'] . '] vẫn còn: ' . $needle . PHP_EOL;
        }
    }

    foreach ($case['must_contain'] as $needle) {
        if (stripos($out, (string) $needle) === false) {
            $ok = false;
            echo 'FAIL [' . $case['name'] . '] thiếu: ' . $needle . PHP_EOL;
        }
    }

    if ($ok) {
        echo 'OK   ' . $case['name'] . PHP_EOL;
    } else {
        $failed++;
        echo '     -> ' . $out . PHP_EOL;
    }
}

echo PHP_EOL . ($failed === 0 ? 'TAT CA PASS' : ('FAIL: ' . $failed . ' truong hop')) . PHP_EOL;

exit($failed === 0 ? 0 : 1);
