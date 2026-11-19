<?php
/**
 * src/v3_research.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 2 — NGHIÊN CỨU & QUẢN LÝ NỘI DUNG.
 *
 *   FEATURE 4 — Bôi đen, tô màu và ghi chú trực tiếp trên snapshot.
 *   FEATURE 5 — Tìm thời điểm một cụm từ xuất hiện / biến mất trong lịch sử
 *               phiên bản của một bài.
 *   FEATURE 6 — Xác minh tính toàn vẹn snapshot bằng SHA-256.
 *
 * NGUYÊN TẮC CHUNG:
 *   - KHÔNG BAO GIỜ sửa `content_html` / `content_text` gốc của snapshot.
 *     Đánh dấu nằm ở bảng `annotations` và được vẽ lên khi hiển thị.
 *   - Cơ chế neo văn bản: (selector, start_offset, end_offset) + quote +
 *     prefix/suffix 32 ký tự — định vị lại được khi nội dung dịch chuyển.
 *   - Hash: chuẩn hoá nội dung TRƯỚC khi băm, và KHÔNG ghi đè hash gốc khi
 *     phát hiện sai lệch.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/* =============================================================================
    FEATURE 4 — BÔI ĐEN / TÔ MÀU / GHI CHÚ TRỰC TIẾP
    ============================================================================= */

/** Các màu đánh dấu được phép. */
const WA_ANNOTATION_COLORS = ['yellow', 'green', 'blue', 'pink', 'orange'];

/** Số ký tự ngữ cảnh lưu hai bên đoạn trích (để định vị lại khi lệch). */
const WA_ANNOTATION_CONTEXT = 32;

/**
 * Danh sách đánh dấu của một tài khoản trên một snapshot.
 *
 * @return array<int, array<string,mixed>>
 */
function wa_annotation_list(int $snapshotId, int $userId): array
{
    if ($snapshotId <= 0) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, snapshot_id, user_id, selector, start_offset, end_offset,
                    quote, prefix, suffix, color, note, created_at, updated_at
               FROM `annotations`
              WHERE snapshot_id = ? AND user_id = ?
           ORDER BY start_offset ASC, id ASC'
        );
        $stmt->execute([$snapshotId, $userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $row['id']           = (int) $row['id'];
            $row['snapshot_id']  = (int) $row['snapshot_id'];
            $row['user_id']      = (int) $row['user_id'];
            $row['start_offset'] = (int) $row['start_offset'];
            $row['end_offset']   = (int) $row['end_offset'];
            $row['created_label'] = wa_datetime((string) $row['created_at']);
            $rows[] = $row;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] annotation list failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Thêm một đánh dấu (bôi đen) lên snapshot.
 *
 * @param array<string,mixed> $anchor selector/start_offset/end_offset/quote/prefix/suffix
 *
 * @return array{ok:bool, message:string, id:int}
 */
function wa_annotation_add(int $snapshotId, int $userId, array $anchor, string $color, ?string $note): array
{
    if ($snapshotId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Thiếu thông tin đánh dấu.', 'id' => 0];
    }

    $quote = trim((string) ($anchor['quote'] ?? ''));

    if ($quote === '') {
        return ['ok' => false, 'message' => 'Chưa có đoạn văn bản nào được chọn.', 'id' => 0];
    }

    if (mb_strlen($quote, 'UTF-8') > 900) {
        $quote = mb_substr($quote, 0, 900, 'UTF-8');
    }

    if (!in_array($color, WA_ANNOTATION_COLORS, true)) {
        $color = 'yellow';
    }

    $note = $note !== null ? trim($note) : '';

    if (mb_strlen($note, 'UTF-8') > 900) {
        $note = mb_substr($note, 0, 900, 'UTF-8');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `annotations`
                (`snapshot_id`, `user_id`, `selector`, `start_offset`, `end_offset`,
                 `quote`, `prefix`, `suffix`, `color`, `note`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $snapshotId,
            $userId,
            mb_substr((string) ($anchor['selector'] ?? ''), 0, 255, 'UTF-8'),
            max(0, (int) ($anchor['start_offset'] ?? 0)),
            max(0, (int) ($anchor['end_offset'] ?? 0)),
            $quote,
            mb_substr((string) ($anchor['prefix'] ?? ''), 0, 255, 'UTF-8'),
            mb_substr((string) ($anchor['suffix'] ?? ''), 0, 255, 'UTF-8'),
            $color,
            $note !== '' ? $note : null,
        ]);

        return [
            'ok'      => true,
            'message' => 'Đã lưu đánh dấu.',
            'id'      => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $e) {
        error_log('[WebArchive] annotation add failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không lưu được đánh dấu.', 'id' => 0];
    }
}

/**
 * Sửa màu / ghi chú của một đánh dấu — CHỈ tác giả của đánh dấu đó.
 *
 * @return array{ok:bool, message:string}
 */
function wa_annotation_update(int $annotationId, int $userId, ?string $color, ?string $note): array
{
    if ($annotationId <= 0) {
        return ['ok' => false, 'message' => 'Không xác định được đánh dấu.'];
    }

    // Chỉ đánh dấu của chính mình: điều kiện user_id nằm trong câu UPDATE.
    $stmt = db()->prepare(
        'SELECT color, note FROM `annotations` WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$annotationId, $userId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Bạn không có quyền sửa đánh dấu này.'];
    }

    $newColor = $color !== null ? (string) $color : (string) $row['color'];
    $newNote  = $note !== null ? trim($note) : (string) ($row['note'] ?? '');

    if (!in_array($newColor, WA_ANNOTATION_COLORS, true)) {
        $newColor = (string) $row['color'];
    }

    if (mb_strlen($newNote, 'UTF-8') > 900) {
        $newNote = mb_substr($newNote, 0, 900, 'UTF-8');
    }

    $stmt = db()->prepare(
        'UPDATE `annotations` SET `color` = ?, `note` = ?, `updated_at` = NOW()
          WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$newColor, $newNote !== '' ? $newNote : null, $annotationId, $userId]);

    return ['ok' => true, 'message' => 'Đã cập nhật đánh dấu.'];
}

/**
 * Xoá một đánh dấu — CHỈ tác giả của đánh dấu đó.
 *
 * @return array{ok:bool, message:string}
 */
function wa_annotation_delete(int $annotationId, int $userId): array
{
    $stmt = db()->prepare('DELETE FROM `annotations` WHERE id = ? AND user_id = ?');
    $stmt->execute([$annotationId, $userId]);

    if ($stmt->rowCount() > 0) {
        return ['ok' => true, 'message' => 'Đã xoá đánh dấu.'];
    }

    return ['ok' => false, 'message' => 'Không tìm thấy đánh dấu để xoá (hoặc không phải của bạn).'];
}

/* =============================================================================
    FEATURE 4 — VẼ LẠI ĐÁNH DẤU LÊN NỘI DUNG (KHÔNG SỬA NỘI DUNG GỐC)
    =============================================================================

    Cách neo (anchor) dùng chung giữa JavaScript và PHP:

      * `selector` — đường dẫn CSS của KHỐI chứa đoạn được chọn, tính từ
        thẻ `#reading-content`, kiểu:  body > p:nth-of-type(2) > span:nth-of-type(1)
      * `start_offset` / `end_offset` — vị trí ký tự (theo textContent của
        chính khối đó) của đoạn được chọn.
      * `quote` / `prefix` / `suffix` — văn bản thực tế của đoạn và 32 ký tự
        ngữ cảnh hai bên, dùng để định vị lại khi khối đã dịch chuyển.

    Nội dung gốc của snapshot KHÔNG bị sửa: hàm này chỉ trả về một BẢN SAO
    đã bọc thẻ <mark> để hiển thị.
 */

/**
 * Đường dẫn CSS tương đối của một phần tử, tính từ thân bài (`body` của cây
 * DOM dựng từ content_html) — cùng gốc với phía JavaScript (tương đối so với
 * `#reading-content`), nên đường dẫn của hai bên luôn khớp nhau.
 */
function wa_annotation_css_path(DOMNode $node): string
{
    $parts = [];
    $cur   = $node;

    while ($cur instanceof DOMElement) {
        $tag = strtolower($cur->nodeName);

        if ($tag === 'body' || $tag === 'html') {
            break;
        }

        $index = 1;
        $sib   = $cur->previousSibling;

        while ($sib !== null) {
            if ($sib instanceof DOMElement && strtolower($sib->nodeName) === $tag) {
                $index++;
            }

            $sib = $sib->previousSibling;
        }

        array_unshift($parts, $tag . ':nth-of-type(' . $index . ')');

        $cur = $cur->parentNode;
    }

    return implode(' > ', $parts);
}

/**
 * Bọc một đoạn ký tự trong phạm vi một phần tử bằng thẻ <mark>.
 *
 * @param DOMElement $element  Khối chứa đoạn cần bôi.
 * @param int        $start    Vị trí bắt đầu (theo textContent của khối).
 * @param int        $end      Vị trí kết thúc.
 * @param string     $class    Lớp CSS cho thẻ mark.
 */
function wa_annotation_wrap_range(DOMElement $element, int $start, int $end, string $class): bool
{
    if ($end <= $start) {
        return false;
    }

    $doc  = $element->ownerDocument;
    $text = $element->textContent;
    $len  = mb_strlen($text, 'UTF-8');

    if ($start < 0 || $start >= $len) {
        return false;
    }

    $end = min($end, $len);

    // Gom danh sách các nút chữ cùng vị trí bắt đầu của chúng trong textContent.
    $nodes  = [];
    $offset = 0;

    $walk = static function (DOMNode $node) use (&$walk, &$nodes, &$offset): void {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $len = mb_strlen((string) $child->nodeValue, 'UTF-8');
                $nodes[] = ['node' => $child, 'start' => $offset, 'len' => $len];
                $offset += $len;

                continue;
            }

            if ($child->nodeType === XML_ELEMENT_NODE && $child instanceof DOMElement) {
                $walk($child);
            }
        }
    };
    $walk($element);

    if ($nodes === []) {
        return false;
    }

    // Tìm các nút chữ bị cắt bởi đoạn [start, end).
    $hit = [];

    foreach ($nodes as $info) {
        $nodeStart = (int) $info['start'];
        $nodeEnd   = $nodeStart + (int) $info['len'];

        if ($nodeEnd <= $start || $nodeStart >= $end) {
            continue;
        }

        $hit[] = $info;
    }

    if ($hit === []) {
        return false;
    }

    // Xử lý từ nút cuối về nút đầu để không làm lệch chỉ số của các nút trước.
    for ($i = count($hit) - 1; $i >= 0; $i--) {
        /** @var DOMText $textNode */
        $textNode = $hit[$i]['node'];
        $nodeStart = (int) $hit[$i]['start'];
        $nodeLen   = (int) $hit[$i]['len'];

        $localStart = max(0, $start - $nodeStart);
        $localEnd   = min($nodeLen, $end - $nodeStart);

        if ($localEnd <= $localStart) {
            continue;
        }

        $value  = (string) $textNode->nodeValue;
        $before = mb_substr($value, 0, $localStart, 'UTF-8');
        $middle = mb_substr($value, $localStart, $localEnd - $localStart, 'UTF-8');
        $after  = mb_substr($value, $localEnd, null, 'UTF-8');

        $textNode->nodeValue = $before;

        $mark = $doc->createElement('mark');
        $mark->setAttribute('class', $class);
        $mark->appendChild($doc->createTextNode($middle));

        if ($after !== '') {
            $textNode->parentNode->insertBefore($doc->createTextNode($after), $textNode->nextSibling);
        }

        $textNode->parentNode->insertBefore($mark, $textNode->nextSibling);
    }

    return true;
}

/**
 * Dựng lại nội dung đã có đánh dấu (bản sao — nội dung gốc không bị sửa).
 *
 * @param array<int, array<string,mixed>> $annotations
 */
function wa_annotation_render_html(string $contentHtml, array $annotations): string
{
    if ($annotations === [] || trim($contentHtml) === '') {
        return $contentHtml;
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $contentHtml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $body = $doc->getElementsByTagName('body')->item(0);

    if ($body === null) {
        return $contentHtml;
    }

    // Nhóm đánh dấu theo selector để gom nhiều đoạn trong cùng một khối.
    $bySelector = [];

    foreach ($annotations as $a) {
        $selector = (string) ($a['selector'] ?? '');

        if ($selector === '') {
            continue;
        }

        $bySelector[$selector][] = $a;
    }

    foreach ($bySelector as $selector => $list) {
        $node = wa_annotation_find_by_path($body, $selector);

        if (!$node instanceof DOMElement) {
            continue;
        }

        // Sắp xếp theo vị trí để bọc từ trên xuống dưới không làm lệch offset.
        usort($list, static fn (array $x, array $y): int => (int) $x['start_offset'] <=> (int) $y['start_offset']);

        foreach ($list as $a) {
            $color = (string) ($a['color'] ?? 'yellow');

            if (!in_array($color, WA_ANNOTATION_COLORS, true)) {
                $color = 'yellow';
            }

            $class = 'wa-mark wa-mark--' . $color;
            $note  = trim((string) ($a['note'] ?? ''));

            if ($note !== '') {
                $class .= ' wa-mark--has-note';
            }

            wa_annotation_wrap_range(
                $node,
                (int) $a['start_offset'],
                (int) $a['end_offset'],
                $class
            );
        }
    }

    $html = $doc->saveHTML();

    if ($html === false) {
        return $contentHtml;
    }

    // Bỏ thẻ <html>/<body> do DOMDocument thêm vào.
    $html = (string) preg_replace('#^.*?<body[^>]*>#s', '', $html);
    $html = (string) preg_replace('#</body>.*$#s', '', $html);

    return trim($html);
}

/**
 * Tìm phần tử theo đường dẫn CSS tương đối (dạng do wa_annotation_css_path tạo).
 */
function wa_annotation_find_by_path(DOMElement $root, string $path): ?DOMElement
{
    $parts = preg_split('/\s*>\s*/', trim($path)) ?: [];

    $current = $root;

    foreach ($parts as $part) {
        if ($part === '' || $part === 'body' || $part === 'html') {
            continue;
        }

        if (!preg_match('/^([a-z0-9]+):nth-of-type\((\d+)\)$/i', $part, $m)) {
            return null;
        }

        $tag   = strtolower($m[1]);
        $index = (int) $m[2];

        $found = null;
        $count = 0;

        foreach ($current->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === $tag) {
                $count++;

                if ($count === $index) {
                    $found = $child;
                    break;
                }
            }
        }

        if ($found === null) {
            return null;
        }

        $current = $found;
    }

    return $current === $root ? null : $current;
}

/* =============================================================================
    FEATURE 5 — TÌM LỊCH SỬ XUẤT HIỆN THÔNG TIN
    ============================================================================= */
/**
 * Tìm một cụm từ trong LỊCH SỬ PHIÊN BẢN của một bài.
 *
 * Dùng `snapshots.content_text` (văn bản đã chuẩn hoá sẵn) nên không cần
 * tạo chỉ mục mới: đây chính là dữ liệu mà hệ thống diff đang dùng.
 *
 * @return array{
 *   ok:bool, message:string, query:string,
 *   versions: array<int, array<string,mixed>>,
 *   first_appeared:?array, last_appeared:?array, first_disappeared:?array,
 *   still_present: bool
 * }
 */
function wa_version_search(int $pageId, string $query, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    $empty = [
        'ok'              => false,
        'message'         => '',
        'query'           => $query,
        'versions'        => [],
        'first_appeared'  => null,
        'last_appeared'   => null,
        'first_disappeared' => null,
        'still_present'   => false,
    ];

    if ($pageId <= 0) {
        $empty['message'] = 'Không xác định được bài cần tìm.';

        return $empty;
    }

    if ($actor === null) {
        $empty['message'] = 'Bạn cần đăng nhập để tìm trong lịch sử phiên bản.';

        return $empty;
    }

    $query = trim($query);

    if ($query === '') {
        $empty['message'] = 'Vui lòng nhập từ hoặc cụm từ cần tìm.';

        return $empty;
    }

    if (mb_strlen($query, 'UTF-8') > 200) {
        $query = mb_substr($query, 0, 200, 'UTF-8');
    }

    // --- Quyền: phải đọc được thư viện chứa trang này ---
    $stmt = db()->prepare('SELECT library_id FROM `saved_pages` WHERE id = ? LIMIT 1');
    $stmt->execute([$pageId]);
    $row = $stmt->fetch();

    if ($row === false) {
        $empty['message'] = 'Không tìm thấy bài này.';

        return $empty;
    }

    if (!wa_can_read_library((int) $row['library_id'], $actor)) {
        $empty['message'] = 'Bạn không có quyền đọc thư viện chứa bài này.';

        return $empty;
    }

    // --- Các bản thành công của trang, theo version_no tăng dần ---
    $stmt = db()->prepare(
        "SELECT id, version_no, title, source_url, fetched_at, content_text
           FROM `snapshots`
          WHERE page_id = ? AND fetch_status = 'success'
       ORDER BY version_no ASC, id ASC"
    );
    $stmt->execute([$pageId]);

    $versions = [];

    foreach ($stmt->fetchAll() as $v) {
        $text   = (string) ($v['content_text'] ?? '');
        $pos    = $text !== '' ? mb_stripos($text, $query, 0, 'UTF-8') : false;
        $has    = $pos !== false;

        $snippet = '';

        if ($has) {
            $from   = max(0, (int) $pos - 60);
            $snippet = wa_trim(
                mb_substr($text, $from, mb_strlen($query, 'UTF-8') + 120, 'UTF-8'),
                200
            );
        }

        $versions[] = [
            'id'            => (int) $v['id'],
            'version_no'    => (int) $v['version_no'],
            'title'         => (string) $v['title'],
            'source_url'    => (string) $v['source_url'],
            'fetched_label' => wa_datetime((string) $v['fetched_at']),
            'contains'      => $has,
            'snippet'       => $snippet,
        ];
    }

    if ($versions === []) {
        $empty['message'] = 'Bài này chưa có phiên bản thành công nào để tìm.';

        return $empty;
    }

    // --- Phiên bản đầu tiên xuất hiện ---
    $first = null;
    $last  = null;

    foreach ($versions as $v) {
        if ($v['contains']) {
            if ($first === null) {
                $first = $v;
            }

            $last = $v;
        }
    }

    // --- Phiên bản đầu tiên biến mất (sau khi xuất hiện liên tiếp) ---
    $disappeared = null;

    if ($first !== null) {
        $runEnd = null;

        foreach ($versions as $v) {
            if ((int) $v['version_no'] < (int) $first['version_no']) {
                continue;
            }

            if ($v['contains']) {
                $runEnd = $v;

                continue;
            }

            // Gặp bản không chứa cụm từ ngay sau khi còn chứa -> biến mất.
            $disappeared = $v;
            break;
        }

        if ($disappeared === null && $runEnd !== null) {
            // Chuỗi chứa kéo dài tới bản cuối -> vẫn còn, không biến mất.
            $disappeared = null;
        }
    }

    $lastVersion = $versions[count($versions) - 1];

    return [
        'ok'                => true,
        'message'           => '',
        'query'             => $query,
        'versions'          => $versions,
        'first_appeared'    => $first,
        'last_appeared'     => $last,
        'first_disappeared' => $disappeared,
        'still_present'     => $disappeared === null && $first !== null
            && (int) $last['version_no'] === (int) $lastVersion['version_no'],
    ];
}

/* =============================================================================
    FEATURE 6 — XÁC MINH SHA-256
    ============================================================================= */

/**
 * Tính SHA-256 của nội dung đã lưu theo cách CHUẨN HOÁ NHẤT QUÁN.
 *
 * Chuẩn hoá: bỏ thẻ HTML -> giải thực thể -> gộp khoảng trắng -> trim.
 * Đây đúng là quy tắc dùng để tạo `content_text` khi lưu, nên hash ổn định
 * với nội dung hiển thị.
 */
function wa_snapshot_content_hash(string $contentHtml): string
{
    $text = wa_capture_clean_text(wa_capture_text_only($contentHtml));

    return hash('sha256', $text);
}

/**
 * Ghi hash gốc của một snapshot (chỉ khi chưa có).
 *
 * @return bool true nếu đã ghi, false nếu đã có sẵn hoặc lỗi.
 */
function wa_snapshot_record_hash(int $snapshotId, string $contentHtml, bool $overwrite = false): bool
{
    if ($snapshotId <= 0) {
        return false;
    }

    $hash = wa_snapshot_content_hash($contentHtml);

    try {
        if ($overwrite) {
            $stmt = db()->prepare(
                'UPDATE `snapshots`
                    SET `content_hash` = ?, `hash_algo` = \'sha256\', `hash_recorded_at` = NOW()
                  WHERE id = ?'
            );
            $stmt->execute([$hash, $snapshotId]);
        } else {
            // KHÔNG ghi đè hash gốc đã có.
            $stmt = db()->prepare(
                'UPDATE `snapshots`
                    SET `content_hash` = ?, `hash_algo` = \'sha256\', `hash_recorded_at` = NOW()
                  WHERE id = ? AND (`content_hash` IS NULL OR `content_hash` = \'\')'
            );
            $stmt->execute([$hash, $snapshotId]);
        }

        return true;
    } catch (Throwable $e) {
        error_log('[WebArchive] snapshot hash write failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Kiểm tra tính toàn vẹn của một snapshot.
 *
 * @return array{
 *   ok:bool, message:string, state:string,
 *   stored_hash:string, current_hash:string, match:bool,
 *   recorded_at:string, verified_at:string, algo:string
 * }
 */
function wa_snapshot_verify_hash(int $snapshotId): array
{
    $result = [
        'ok'          => false,
        'message'     => '',
        'state'       => 'unknown',
        'stored_hash' => '',
        'current_hash' => '',
        'match'       => false,
        'recorded_at' => '',
        'verified_at' => '',
        'algo'        => 'sha256',
    ];

    if ($snapshotId <= 0) {
        $result['message'] = 'Không xác định được bản lưu.';

        return $result;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, content_html, content_hash, hash_algo, hash_recorded_at
               FROM `snapshots` WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$snapshotId]);
        $row = $stmt->fetch();

        if ($row === false) {
            $result['message'] = 'Không tìm thấy bản lưu này.';

            return $result;
        }

        $stored = trim((string) ($row['content_hash'] ?? ''));
        $current = wa_snapshot_content_hash((string) ($row['content_html'] ?? ''));

        $result['stored_hash']  = $stored;
        $result['current_hash'] = $current;
        $result['algo']         = (string) ($row['hash_algo'] ?? 'sha256');
        $result['recorded_at']  = (string) ($row['hash_recorded_at'] ?? '');
        $result['verified_at']  = date('Y-m-d H:i:s');

        if ($stored === '') {
            $result['state']   = 'missing';
            $result['message'] = 'Bản lưu này chưa có mã hash gốc. Bạn có thể tính hash ban đầu bằng một thao tác bổ sung.';

            return $result;
        }

        if (hash_equals($stored, $current)) {
            $result['ok']      = true;
            $result['state']   = 'match';
            $result['match']   = true;
            $result['message'] = 'Nội dung khớp với mã hash đã ghi nhận. Bản lưu không bị thay đổi.';

            // Ghi thời điểm kiểm tra (không đụng hash gốc).
            $stmt = db()->prepare('UPDATE `snapshots` SET `hash_verified_at` = NOW() WHERE id = ?');
            $stmt->execute([$snapshotId]);

            return $result;
        }

        $result['state']   = 'mismatch';
        $result['message'] = 'Sai lệch: nội dung hiện tại KHÔNG khớp mã hash đã ghi nhận. '
            . 'Mã hash gốc được giữ nguyên, không bị ghi đè.';

        return $result;
    } catch (Throwable $e) {
        error_log('[WebArchive] snapshot verify failed: ' . $e->getMessage());
        $result['message'] = 'Không kiểm tra được: ' . $e->getMessage();

        return $result;
    }
}
