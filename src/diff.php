<?php
/**
 * src/diff.php — So sánh nội dung theo KHỐI (đoạn văn) giữa hai phiên bản.
 *
 * Vì sao theo khối, không theo dòng: content_text của dự án đã bị gộp mọi
 * khoảng trắng thành một dòng dài (wa_capture_clean_text), nên "dòng" không
 * còn ý nghĩa. content_html thì vẫn giữ cấu trúc thẻ, vì vậy khối ở đây là
 * một thẻ đoạn (p, h1-h6, li, blockquote, pre, figcaption, dt, dd) hoặc một
 * hàng bảng (tr). Khối cũ không còn thì tô đỏ, khối mới xuất hiện thì tô xanh.
 *
 * Thuật toán: LCS (dãy con chung dài nhất) với cắt tiền tố / hậu tố chung
 * trước để thu nhỏ ma trận, ma trận DP dùng SplFixedArray cho đỡ tốn RAM.
 * Giới hạn 1500 khối mỗi phía cho phần giữa; vượt quá thì trả cờ toolong để
 * trang hiển thị ghi chú thay vì so sánh sai.
 *
 * Tệp này KHÔNG phụ thuộc hàm nào khác của dự án (tự escape, tự làm sạch
 * chuỗi) nên trang nào cũng chỉ cần require_once là dùng được.
 */

declare(strict_types=1);

/** Số khối tối đa mỗi phía cho phần giữa của LCS (sau khi cắt tiền/hậu tố). */
const WA_DIFF_MAX_BLOCKS = 1500;

/** Các thẻ được tính là một khối nội dung. */
const WA_DIFF_BLOCK_TAGS = [
    'p' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1,
    'li' => 1, 'blockquote' => 1, 'pre' => 1, 'figcaption' => 1,
    'dt' => 1, 'dd' => 1, 'tr' => 1,
];

/**
 * Gộp khoảng trắng và trim — cùng quy tắc với wa_capture_clean_text nhưng
 * tự chứa để tệp này không phụ thuộc src/capture.php.
 */
function wa_diff_clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Tách content_html thành danh sách khối (đoạn văn / tiêu đề / mục / hàng bảng).
 * Khối nào nằm trong khối khác thì chỉ lấy khối ngoài cùng, tránh đếm trùng.
 *
 * @return string[]
 */
function wa_diff_blocks_from_html(string $html): array
{
    if (trim($html) === '') {
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $blocks = [];
    $root = $doc->getElementsByTagName('body')->item(0);
    if ($root === null) {
        $root = $doc->documentElement;
    }
    if ($root === null) {
        return [];
    }

    $walk = static function (DOMNode $node) use (&$walk, &$blocks): void {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (isset(WA_DIFF_BLOCK_TAGS[$tag])) {
                // Hàng bảng: nối các ô bằng " | " cho dễ đọc, thay vì dính chữ.
                if ($tag === 'tr') {
                    $cells = [];
                    foreach ($child->childNodes as $cell) {
                        if ($cell->nodeType === XML_ELEMENT_NODE
                            && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                            $cellText = wa_diff_clean_text($cell->textContent);
                            if ($cellText !== '') {
                                $cells[] = $cellText;
                            }
                        }
                    }
                    $text = implode(' | ', $cells);
                } else {
                    $text = wa_diff_clean_text($child->textContent);
                }
                if ($text !== '') {
                    $blocks[] = $text;
                }
                // Không đi sâu vào khối đã lấy.
            } else {
                $walk($child);
            }
        }
    };
    $walk($root);

    return $blocks;
}

/**
 * Tách văn bản thuần thành câu (dự phòng khi content_html không còn khối).
 *
 * @return string[]
 */
function wa_diff_units_from_text(string $text): array
{
    $text = wa_diff_clean_text($text);
    if ($text === '') {
        return [];
    }

    $parts = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [];
    $units = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $units[] = $part;
        }
    }

    return $units;
}

/**
 * Đơn vị so sánh cho một snapshot: ưu tiên khối từ content_html, nếu không
 * còn khối thì tách câu từ content_text, cuối cùng là toàn bộ văn bản.
 *
 * @return string[]
 */
function wa_diff_units(string $contentHtml, string $contentText): array
{
    $units = wa_diff_blocks_from_html($contentHtml);
    if (count($units) >= 2) {
        return $units;
    }

    $units = wa_diff_units_from_text($contentText);
    if ($units !== []) {
        return $units;
    }

    $text = wa_diff_clean_text($contentText);

    return $text === '' ? [] : [$text];
}

/**
 * So sánh hai danh sách đơn vị, trả về các thao tác theo thứ tự hiển thị.
 * Mỗi thao tác: ['op' => 'eq'|'del'|'add'|'toolong', 'text' => string].
 * 'toolong' xuất hiện duy nhất một lần khi phần giữa vượt giới hạn — trang
 * hiển thị phải gặp cờ này thì chỉ hiện ghi chú, không hiện số liệu sai.
 *
 * @param string[] $old
 * @param string[] $new
 * @return array<int, array{op:string, text:string}>
 */
function wa_diff_ops(array $old, array $new): array
{
    $n = count($old);
    $m = count($new);

    // Cắt tiền tố chung.
    $pre = 0;
    while ($pre < $n && $pre < $m && $old[$pre] === $new[$pre]) {
        $pre++;
    }

    // Cắt hậu tố chung (không lấn vào tiền tố).
    $suf = 0;
    while ($suf < ($n - $pre) && $suf < ($m - $pre)
        && $old[$n - 1 - $suf] === $new[$m - 1 - $suf]) {
        $suf++;
    }

    $ops = [];
    for ($i = 0; $i < $pre; $i++) {
        $ops[] = ['op' => 'eq', 'text' => $old[$i]];
    }

    $oMid = array_slice($old, $pre, $n - $pre - $suf);
    $nMid = array_slice($new, $pre, $m - $pre - $suf);
    $on = count($oMid);
    $nm = count($nMid);

    if ($on === 0) {
        foreach ($nMid as $text) {
            $ops[] = ['op' => 'add', 'text' => $text];
        }
    } elseif ($nm === 0) {
        foreach ($oMid as $text) {
            $ops[] = ['op' => 'del', 'text' => $text];
        }
    } elseif ($on > WA_DIFF_MAX_BLOCKS || $nm > WA_DIFF_MAX_BLOCKS) {
        $ops[] = ['op' => 'toolong', 'text' => ''];
    } else {
        // Ma trận DP bằng SplFixedArray (nhẹ RAM hơn mảng PHP thường).
        $dp = [];
        for ($i = 0; $i <= $on; $i++) {
            $dp[$i] = new SplFixedArray($nm + 1);
            $dp[$i][0] = 0;
        }
        for ($j = 0; $j <= $nm; $j++) {
            $dp[0][$j] = 0;
        }
        for ($i = 1; $i <= $on; $i++) {
            for ($j = 1; $j <= $nm; $j++) {
                $dp[$i][$j] = ($oMid[$i - 1] === $nMid[$j - 1])
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        // Truy vết ngược từ góc dưới-phải.
        $i = $on;
        $j = $nm;
        $mid = [];
        while ($i > 0 && $j > 0) {
            if ($oMid[$i - 1] === $nMid[$j - 1]) {
                $mid[] = ['op' => 'eq', 'text' => $oMid[$i - 1]];
                $i--;
                $j--;
            } elseif ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
                $mid[] = ['op' => 'del', 'text' => $oMid[$i - 1]];
                $i--;
            } else {
                $mid[] = ['op' => 'add', 'text' => $nMid[$j - 1]];
                $j--;
            }
        }
        while ($i > 0) {
            $mid[] = ['op' => 'del', 'text' => $oMid[$i - 1]];
            $i--;
        }
        while ($j > 0) {
            $mid[] = ['op' => 'add', 'text' => $nMid[$j - 1]];
            $j--;
        }
        $ops = array_merge($ops, array_reverse($mid));
    }

    for ($i = $n - $suf; $i < $n; $i++) {
        $ops[] = ['op' => 'eq', 'text' => $old[$i]];
    }

    return $ops;
}

/**
 * Thống kê từ danh sách thao tác. Khi có cờ toolong thì del/add không có
 * nghĩa (phần giữa chưa được so sánh) nên trả null để trang khỏi hiện số sai.
 *
 * @param array<int, array{op:string, text:string}> $ops
 * @return array{eq:int, del:int|null, add:int|null, toolong:bool}
 */
function wa_diff_stats(array $ops): array
{
    $stats = ['eq' => 0, 'del' => 0, 'add' => 0, 'toolong' => false];
    foreach ($ops as $op) {
        if ($op['op'] === 'toolong') {
            $stats['toolong'] = true;
        } elseif (isset($stats[$op['op']])) {
            $stats[$op['op']]++;
        }
    }
    if ($stats['toolong']) {
        $stats['del'] = null;
        $stats['add'] = null;
    }

    return $stats;
}

/** Có khối thay đổi nào không (thêm hoặc xoá). */
function wa_diff_changed(array $ops): bool
{
    foreach ($ops as $op) {
        if ($op['op'] === 'del' || $op['op'] === 'add') {
            return true;
        }
    }

    return false;
}

/**
 * Vẽ danh sách thao tác thành HTML. Khối không đổi mang class diff-eq để
 * trang có thể ẩn/hiện bằng ô "Chỉ hiện khối thay đổi".
 *
 * @param array<int, array{op:string, text:string}> $ops
 */
function wa_diff_render_html(array $ops): string
{
    $out = '';
    foreach ($ops as $op) {
        if ($op['op'] === 'toolong') {
            $out .= '<div class="diff-notice">Bài quá dài (phần thay đổi vượt '
                . WA_DIFF_MAX_BLOCKS . ' khối) nên không so sánh chi tiết được. '
                . 'Hãy so sánh hai phiên bản gần nhau hơn.</div>';
            continue;
        }
        $text = htmlspecialchars($op['text'], ENT_QUOTES, 'UTF-8');
        if ($op['op'] === 'del') {
            $out .= '<div class="diff-block diff-del"><span class="diff-sign">−</span>'
                . $text . '</div>';
        } elseif ($op['op'] === 'add') {
            $out .= '<div class="diff-block diff-add"><span class="diff-sign">+</span>'
                . $text . '</div>';
        } else {
            $out .= '<div class="diff-block diff-eq">' . $text . '</div>';
        }
    }

    return $out;
}
