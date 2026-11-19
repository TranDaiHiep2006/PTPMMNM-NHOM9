<?php
/**
 * scripts/test_v3_feeds_parse.php
 * Kiểm tra nhanh bộ phân tích RSS 2.0 / Atom (KHÔNG cần CSDL, KHÔNG gọi mạng).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/capture.php';
require_once dirname(__DIR__) . '/src/v3_feeds.php';

$rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Blog Tin Tức</title>
    <link>https://example.com</link>
    <description>Mô tả kênh</description>
    <item>
      <title>Bài 1</title>
      <link>https://example.com/bai-1</link>
      <pubDate>Mon, 17 Nov 2025 10:00:00 +0700</pubDate>
      <description>Tóm tắt bài 1</description>
    </item>
    <item>
      <title>Bài 2</title>
      <link>https://example.com/bai-2</link>
      <pubDate>Tue, 18 Nov 2025 08:30:00 +0700</pubDate>
    </item>
  </channel>
</rss>
XML;

$atom = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Kênh Atom</title>
  <subtitle>Mô tả Atom</subtitle>
  <link rel="alternate" href="https://example.org/"/>
  <entry>
    <title>Entry A</title>
    <link rel="alternate" href="https://example.org/a"/>
    <published>2025-11-17T10:00:00+07:00</published>
    <summary>Tóm tắt A</summary>
    <id>tag:example.org,2025:a</id>
  </entry>
</feed>
XML;

$failed = 0;

/** @param array{ok:bool,message:string,title?:string,items?:array} $r */
$check = static function (string $name, array $r, array $mustContain, array $mustNot = []) use (&$failed): void {
    if ($r['ok'] !== true) {
        echo 'FAIL [' . $name . '] ' . $r['message'] . PHP_EOL;
        $failed++;

        return;
    }

    $out = $r['title'] . ' ' . json_encode($r['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ($mustContain as $needle) {
        if (stripos($out, (string) $needle) === false) {
            echo 'FAIL [' . $name . '] thiếu: ' . $needle . PHP_EOL;
            $failed++;
        }
    }

    foreach ($mustNot as $needle) {
        if (stripos($out, (string) $needle) !== false) {
            echo 'FAIL [' . $name . '] vẫn còn: ' . $needle . PHP_EOL;
            $failed++;
        }
    }

    echo 'OK   ' . $name . PHP_EOL;
};

$check('RSS 2.0', wa_feed_parse($rss, 'https://example.com/feed.xml'),
    ['Blog Tin Tức', 'Bài 1', 'https://example.com/bai-1', 'Bài 2', 'https://example.com/bai-2'],
    ['<script']
);

$check('Atom', wa_feed_parse($atom, 'https://example.org/feed.xml'),
    ['Kênh Atom', 'Entry A', 'https://example.org/a']
);

$bad = wa_feed_parse('<html><body>khong phai feed</body></html>');
if ($bad['ok'] === true) {
    echo 'FAIL [XML rác] phải báo lỗi' . PHP_EOL;
    $failed++;
} else {
    echo 'OK   XML không phải feed bị từ chối: ' . $bad['message'] . PHP_EOL;
}

// Item không có URL -> bị loại.
$noLink = wa_feed_parse(
    '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
    . '<item><title>Không có link</title></item></channel></rss>'
);
if ($noLink['ok'] === true && count($noLink['items']) !== 0) {
    echo 'FAIL [item không URL] phải bị loại' . PHP_EOL;
    $failed++;
} else {
    echo 'OK   item không có URL bị loại' . PHP_EOL;
}

echo PHP_EOL . ($failed === 0 ? 'TAT CA PASS' : ('FAIL: ' . $failed . ' truong hop')) . PHP_EOL;

exit($failed === 0 ? 0 : 1);
