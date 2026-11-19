<?php
/**
 * src/v3_feeds.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 3 — THEO DÕI RSS/ATOM (F9).
 *
 * Nguyên tắc:
 *   - KHÔNG có cron, KHÔNG có daemon. Feed CHỈ được tải khi người dùng bấm.
 *   - Tải feed đi qua wa_capture_http_get() -> tự động có chốt an toàn URL công
 *     khai (chặn mạng riêng, cloud metadata, DNS rebinding) + giới hạn thời gian
 *     + giới hạn dung lượng.
 *   - XML được phân tích bằng DOMDocument với LIBXML_NONET (không gọi mạng,
 *     không phân giải entity ngoài).
 *   - Lưu bài vào thư viện dùng lại đúng wa_store_page_capture().
 *   - Chống trùng: mỗi item có item_hash (SHA-256 của URL) và UNIQUE
 *     (subscription_id, item_hash).
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Số item tối đa đọc từ một feed trong một lần xem trước. */
const WA_FEED_MAX_ITEMS = 60;

/** Kích thước XML tối đa được phép phân tích (byte). */
const WA_FEED_MAX_XML_BYTES = 5242880; // 5 MB

/* =============================================================================
    TẢI + PHÂN TÍCH FEED
    ============================================================================= */

/**
 * Kiểm tra URL feed trước khi tải (chính sách URL công khai + chống SSRF).
 *
 * @return array{ok:bool, message:string}
 */
function wa_feed_check_url(string $url): array
{
    $url = trim($url);

    if ($url === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập địa chỉ feed.'];
    }

    $policy = wa_url_policy_inspect($url);

    if ($policy['ok'] !== true) {
        return ['ok' => false, 'message' => (string) $policy['message']];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Tải và phân tích một feed RSS/Atom.
 *
 * @return array{ok:bool, message:string, title?:string, link?:string, description?:string, items?:array}
 */
function wa_feed_fetch(string $url): array
{
    $check = wa_feed_check_url($url);

    if ($check['ok'] !== true) {
        return ['ok' => false, 'message' => $check['message']];
    }

    // Tải qua lớp capture có sẵn -> có allowlist, SSRF, timeout, giới hạn byte.
    $http = wa_capture_http_get($url);

    if ($http['ok'] !== true) {
        return [
            'ok'      => false,
            'message' => 'Không tải được feed: ' . (string) $http['detail'],
        ];
    }

    $body = (string) $http['body'];

    if (strlen($body) > WA_FEED_MAX_XML_BYTES) {
        return ['ok' => false, 'message' => 'Feed quá lớn để phân tích.'];
    }

    if (trim($body) === '') {
        return ['ok' => false, 'message' => 'Feed rỗng.'];
    }

    return wa_feed_parse($body, (string) $http['final_url']);
}

/**
 * Phân tích XML feed (RSS 2.0 / RSS 1.0 / Atom) ra mảng item.
 *
 * @return array{ok:bool, message:string, title?:string, link?:string, description?:string, items?:array}
 */
function wa_feed_parse(string $xml, string $sourceUrl = ''): array
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument();
    // LIBXML_NONET: không bao giờ gọi mạng khi phân tích (chống XXE).
    // KHÔNG dùng LIBXML_NOENT -> entity ngoài KHÔNG được thay thế.
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($loaded !== true) {
        return ['ok' => false, 'message' => 'Nội dung không phải XML feed hợp lệ (RSS/Atom).'];
    }

    $root = $doc->documentElement;

    if ($root === null) {
        return ['ok' => false, 'message' => 'Feed không có phần tử gốc.'];
    }

    $rootName = strtolower($root->localName);

    $title = '';
    $link  = '';
    $desc  = '';
    $items = [];

    if ($rootName === 'feed') {
        // ---------------- ATOM ----------------
        $title = wa_feed_text($root, 'title');
        $desc  = wa_feed_text($root, 'subtitle');
        $link  = wa_feed_atom_link($root);

        foreach ($root->getElementsByTagName('entry') as $entry) {
            if ($entry instanceof DOMElement) {
                $items[] = [
                    'title'        => wa_feed_text($entry, 'title'),
                    'link'         => wa_feed_atom_link($entry),
                    'published_at' => wa_feed_date(wa_feed_text($entry, 'published') ?: wa_feed_text($entry, 'updated')),
                    'summary'      => wa_feed_strip(wa_feed_text($entry, 'summary') ?: wa_feed_text($entry, 'content')),
                    'guid'         => wa_feed_text($entry, 'id'),
                ];
            }
        }
    } else {
        // ---------------- RSS 2.0 / RSS 1.0 ----------------
        $channel = null;

        if ($rootName === 'rss') {
            $nodes = $root->getElementsByTagName('channel');
            $channel = ($nodes->length > 0 && $nodes->item(0) instanceof DOMElement) ? $nodes->item(0) : null;
        } elseif ($rootName === 'rdf') {
            $nodes = $root->getElementsByTagName('channel');
            $channel = ($nodes->length > 0 && $nodes->item(0) instanceof DOMElement) ? $nodes->item(0) : null;
        } else {
            $channel = $root;
        }

        if ($channel !== null) {
            $title = wa_feed_text($channel, 'title');
            $desc  = wa_feed_text($channel, 'description');
            $link  = wa_feed_rss_link($channel);

            foreach ($channel->getElementsByTagName('item') as $item) {
                if (!$item instanceof DOMElement) {
                    continue;
                }

                $items[] = [
                    'title'        => wa_feed_text($item, 'title'),
                    'link'         => wa_feed_rss_link($item),
                    'published_at' => wa_feed_date(wa_feed_text($item, 'pubDate') ?: wa_feed_text($item, 'date') ?: wa_feed_text($item, 'published')),
                    'summary'      => wa_feed_strip(wa_feed_text($item, 'description') ?: wa_feed_text($item, 'encoded')),
                    'guid'         => wa_feed_text($item, 'guid'),
                ];
            }
        }
    }

    // Loại bỏ item không có URL — không lưu được thì không có ý nghĩa.
    $items = array_values(array_filter(
        $items,
        static fn (array $it): bool => $it['link'] !== '' && wa_capture_is_http_url($it['link'])
    ));

    if (!in_array($rootName, ['feed', 'rss', 'rdf'], true)) {
        return [
            'ok'      => false,
            'message' => 'Nội dung không phải feed RSS/Atom (thẻ gốc là <' . $rootName . '>).',
        ];
    }

    if ($items === [] && $title === '' && $link === '') {
        return ['ok' => false, 'message' => 'Không tìm thấy mục nào trong feed.'];
    }

    // Giới hạn số item trả về.
    $items = array_slice($items, 0, WA_FEED_MAX_ITEMS);

    return [
        'ok'          => true,
        'message'     => '',
        'title'       => $title !== '' ? $title : 'Feed không tên',
        'link'        => $link,
        'description' => wa_feed_strip($desc),
        'items'       => $items,
    ];
}

/**
 * Text của một phần tử con (không tính phần tử cháu).
 */
function wa_feed_text(DOMElement $parent, string $tagName): string
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->localName) === strtolower($tagName)) {
            return trim($child->textContent);
        }
    }

    return '';
}

/**
 * Link của phần tử Atom (ưu tiên rel="alternate" hoặc không có rel).
 */
function wa_feed_atom_link(DOMElement $parent): string
{
    $fallback = '';

    foreach ($parent->getElementsByTagName('link') as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        $href = trim($link->getAttribute('href'));
        $rel  = strtolower(trim($link->getAttribute('rel')));

        if ($href === '') {
            continue;
        }

        if ($rel === '' || $rel === 'alternate') {
            return $href;
        }

        if ($fallback === '') {
            $fallback = $href;
        }
    }

    return $fallback;
}

/**
 * Link của phần tử RSS (dùng text thay vì href).
 */
function wa_feed_rss_link(DOMElement $parent): string
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->localName) === 'link') {
            $text = trim($child->textContent);

            if ($text !== '') {
                return $text;
            }

            $href = trim($child->getAttribute('href'));

            if ($href !== '') {
                return $href;
            }
        }
    }

    // RSS 1.0: link nằm ở thuộc tính rdf:about của item.
    $about = trim($parent->getAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about'));

    return $about !== '' ? $about : '';
}

/**
 * Chuẩn hoá ngày xuất bản về định dạng MySQL; null nếu không đọc được.
 */
function wa_feed_date(string $raw): ?string
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);

    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Bỏ thẻ HTML trong mô tả, rút gọn còn một đoạn văn ngắn.
 */
function wa_feed_strip(string $html): string
{
    $text = trim(strip_tags($html));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    if (mb_strlen($text) > 280) {
        $text = mb_substr($text, 0, 277, 'UTF-8') . '…';
    }

    return $text;
}

/* =============================================================================
    ĐĂNG KÝ NGUỒN FEED
    ============================================================================= */

/**
 * Thêm (hoặc lấy lại) một nguồn feed cho tài khoản + thư viện.
 *
 * @return array{ok:bool, message:string, id?:int}
 */
function wa_feed_subscribe(int $userId, int $libraryId, string $feedUrl, string $tags = ''): array
{
    $feedUrl = trim($feedUrl);

    $check = wa_feed_check_url($feedUrl);

    if ($check['ok'] !== true) {
        return ['ok' => false, 'message' => $check['message']];
    }

    if (!wa_can_write_library($libraryId)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền lưu vào thư viện này.'];
    }

    $tags = trim($tags);
    if (mb_strlen($tags) > 255) {
        $tags = mb_substr($tags, 0, 255, 'UTF-8');
    }

    try {
        // Khoá UNIQUE là (user_id, feed_url_hash) — tra cứu bằng hash để khớp
        // đúng khoá. Nếu bảng chưa có cột hash (chạy trước khi migrate) thì
        // dùng đường cũ trên `feed_url`.
        $useHash = wa_feed_has_hash_column();

        if ($useHash) {
            $urlHash = wa_feed_url_hash($feedUrl);

            // Hash rỗng = lỗi tính hash -> KHÔNG được ghi dòng rỗng vào cột
            // NOT NULL (sẽ làm hỏng khoá UNIQUE cho mọi dòng sau).
            if ($urlHash === '') {
                return ['ok' => false, 'message' => 'Không lưu được nguồn feed. Vui lòng thử lại.'];
            }

            $stmt = db()->prepare(
                'SELECT id FROM `feed_subscriptions`
                  WHERE user_id = ? AND feed_url_hash = ?
                  LIMIT 1'
            );
            $stmt->execute([$userId, $urlHash]);
        } else {
            $stmt = db()->prepare(
                'SELECT id FROM `feed_subscriptions`
                  WHERE user_id = ? AND feed_url = ?
                  LIMIT 1'
            );
            $stmt->execute([$userId, $feedUrl]);
        }

        $existing = $stmt->fetch();

        if ($existing !== false) {
            // Đã theo dõi — cập nhật thư viện + nhãn.
            $stmt = db()->prepare(
                'UPDATE `feed_subscriptions`
                    SET `library_id` = ?, `tags` = ?
                  WHERE id = ? AND `user_id` = ?'
            );
            $stmt->execute([$libraryId, $tags, (int) $existing['id'], $userId]);

            return [
                'ok'      => true,
                'message' => 'Nguồn feed đã có trong danh sách — đã cập nhật thư viện đích.',
                'id'      => (int) $existing['id'],
            ];
        }

        if ($useHash) {
            $stmt = db()->prepare(
                'INSERT INTO `feed_subscriptions`
                    (`user_id`, `library_id`, `feed_url`, `feed_url_hash`, `feed_title`, `tags`)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $libraryId, $feedUrl, $urlHash, '', $tags]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO `feed_subscriptions`
                    (`user_id`, `library_id`, `feed_url`, `feed_title`, `tags`)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $libraryId, $feedUrl, '', $tags]);
        }

        return [
            'ok'      => true,
            'message' => 'Đã thêm nguồn feed.',
            'id'      => (int) db()->lastInsertId(),
        ];
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_feed_subscribe failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không lưu được nguồn feed. Vui lòng thử lại.'];
    }
}

/**
 * Xoá một nguồn feed đã theo dõi.
 */
function wa_feed_unsubscribe(int $id, int $userId): bool
{
    try {
        $stmt = db()->prepare(
            'DELETE FROM `feed_subscriptions` WHERE id = ? AND `user_id` = ?'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_feed_unsubscribe failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Danh sách nguồn feed của một tài khoản.
 *
 * @return array<int, array<string,mixed>>
 */
function wa_feed_list(int $userId): array
{
    try {
        $stmt = db()->prepare(
            'SELECT s.id, s.feed_url, s.feed_title, s.tags, s.last_checked_at, s.created_at,
                    s.library_id, l.name AS library_name,
                    (SELECT COUNT(*) FROM `feed_items` fi WHERE fi.subscription_id = s.id) AS item_count
               FROM `feed_subscriptions` s
               JOIN `libraries` l ON l.id = s.library_id
              WHERE s.user_id = ?
           ORDER BY s.id DESC'
        );
        $stmt->execute([$userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $row['id']        = (int) $row['id'];
            $row['library_id'] = (int) $row['library_id'];
            $row['item_count'] = (int) $row['item_count'];
            $row['title']     = (string) $row['feed_title'] !== ''
                ? (string) $row['feed_title']
                : (string) $row['feed_url'];
            $row['checked_label'] = $row['last_checked_at'] !== null
                ? wa_datetime((string) $row['last_checked_at']) : 'Chưa kiểm tra';
            $rows[] = $row;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_feed_list failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Ghi nhận mốc kiểm tra feed.
 */
function wa_feed_mark_checked(int $subscriptionId, string $title = ''): void
{
    try {
        if ($title !== '') {
            $stmt = db()->prepare(
                'UPDATE `feed_subscriptions` SET `last_checked_at` = NOW(), `feed_title` = ? WHERE id = ?'
            );
            $stmt->execute([mb_substr($title, 0, 255, 'UTF-8'), $subscriptionId]);
        } else {
            $stmt = db()->prepare(
                'UPDATE `feed_subscriptions` SET `last_checked_at` = NOW() WHERE id = ?'
            );
            $stmt->execute([$subscriptionId]);
        }
    } catch (Throwable $e) {
        // Không quan trọng.
    }
}

/**
 * Băm một item feed để chống trùng.
 */
function wa_feed_item_hash(string $url): string
{
    return hash('sha256', trim($url));
}

/**
 * Băm URL nguồn feed để làm khoá UNIQUE.
 *
 * VÌ SAO CẦN HASH:
 *   `feed_subscriptions.feed_url` là VARCHAR(1000). Với charset utf8mb4, mỗi ký
 *   tự tính 4 byte, nên UNIQUE (user_id, feed_url) = 4 + 1000*4 = 4004 byte,
 *   vượt giới hạn 3072 byte của InnoDB -> MySQL lỗi 1071 "Specified key was too
 *   long". Vì vậy khoá UNIQUE được đặt trên (user_id, `feed_url_hash`) =
 *   4 + 64*4 = 260 byte, còn `feed_url` vẫn giữ nguyên VARCHAR(1000) để lưu
 *   trọn vẹn URL dài (KHÔNG cắt xuống 191, KHÔNG dùng prefix index).
 *
 * VÌ SAO HASH PHẢI "TƯƠNG ĐƯƠNG" KHOÁ CŨ:
 *   Khoá cũ là UNIQUE(user_id, feed_url) với collation utf8mb4_unicode_ci — tức
 *   KHÔNG phân biệt HOA/thường, KHÔNG phân biệt dấu (é = e), ǅ = ǆ, ﬀ = ff,
 *   và là kiểu PAD SPACE (trailing space bị bỏ qua khi so sánh). Nếu hash
 *   chỉ băm thẳng chuỗi thô thì hai URL trước đây bị coi là trùng (bị từ
 *   chối) có thể lọt qua khoá mới — tức là LỎNG chống trùng.
 *
 *   Vì vậy hash được tính trên DẠNG CHUẨN HOÁ:
 *       SHA2(WEIGHT_STRING(TRIM(TRAILING ' ' FROM url) COLLATE utf8mb4_unicode_ci), 256)
 *   - TRIM(TRAILING ' ') bỏ khoảng trắng cuối (tương đương PAD SPACE);
 *   - WEIGHT_STRING() đổi chuỗi thành dãy byte mà MySQL dùng khi SO SÁNH
 *     theo collation đó (không phân biệt hoa/thường, không phân biệt dấu);
 *   - SHA2(..., 256) băm ra 64 ký tự hex.
 *
 *   Hàm này CỐ TÌNH gọi chính MySQL để tính hash, thay vì tự băm bằng PHP:
 *   như vậy biểu thức dùng ở đây và biểu thức dùng trong
 *   `scripts/migrate_v3.php` (để băm lại dữ liệu cũ) KHÔNG THỂ lệch nhau.
 *
 * @return string 64 ký tự hex (SHA-256), hoặc chuỗi rỗng nếu lỗi.
 */
function wa_feed_url_hash(string $url): string
{
    try {
        $stmt = db()->prepare(
            "SELECT SHA2(
                    WEIGHT_STRING(TRIM(TRAILING ' ' FROM ?) COLLATE utf8mb4_unicode_ci),
                    256
                ) AS h"
        );
        $stmt->execute([$url]);

        $hash = $stmt->fetchColumn();

        return is_string($hash) && $hash !== '' ? $hash : '';
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_feed_url_hash failed: ' . $e->getMessage());

        return '';
    }
}

/**
 * Bảng `feed_subscriptions` đã có cột `feed_url_hash` chưa?
 *
 * Cột này được thêm bởi `scripts/migrate_v3.php`. Hàm này cho phép mã chạy
 * được cả trước và sau khi migrate (trước migrate -> dùng đường cũ, không
 * cần cột hash).
 */
function wa_feed_has_hash_column(): bool
{
    static $has = null;

    if ($has !== null) {
        return $has;
    }

    try {
        $has = (int) db()->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'feed_subscriptions'
                AND COLUMN_NAME = 'feed_url_hash'"
        )->fetchColumn() > 0;
    } catch (Throwable $e) {
        $has = false;
    }

    return $has;
}

/**
 * Lưu các item feed đã xem vào bảng `feed_items` (chống trùng).
 *
 * @param array<int, array<string,mixed>> $items
 *
 * @return array{new:int, known:int}
 */
function wa_feed_record_items(int $subscriptionId, array $items): array
{
    $new = 0;
    $known = 0;

    try {
        $stmt = db()->prepare(
            'INSERT IGNORE INTO `feed_items`
                (`subscription_id`, `item_url`, `item_title`, `item_hash`, `published_at`)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            $url   = trim((string) ($item['link'] ?? ''));
            $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 500, 'UTF-8');

            if ($url === '') {
                continue;
            }

            $stmt->execute([
                $subscriptionId,
                mb_substr($url, 0, 1000, 'UTF-8'),
                $title,
                wa_feed_item_hash($url),
                $item['published_at'] ?? null,
            ]);

            if ($stmt->rowCount() > 0) {
                $new++;
            } else {
                $known++;
            }
        }
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_feed_record_items failed: ' . $e->getMessage());
    }

    return ['new' => $new, 'known' => $known];
}

/**
 * Đánh dấu một item feed đã được lưu vào thư viện.
 */
function wa_feed_mark_saved(int $subscriptionId, string $itemUrl, int $pageId): void
{
    try {
        $stmt = db()->prepare(
            'UPDATE `feed_items`
                SET `saved_page_id` = ?
              WHERE `subscription_id` = ? AND `item_hash` = ?'
        );
        $stmt->execute([$pageId, $subscriptionId, wa_feed_item_hash($itemUrl)]);
    } catch (Throwable $e) {
        // Không quan trọng.
    }
}

/**
 * Các item của một nguồn đã lưu vào thư viện (để đánh dấu trong UI).
 *
 * @return array<int, string>  danh sách hash của item đã lưu
 */
function wa_feed_saved_hashes(int $subscriptionId): array
{
    try {
        $stmt = db()->prepare(
            'SELECT item_hash FROM `feed_items`
              WHERE `subscription_id` = ? AND `saved_page_id` IS NOT NULL'
        );
        $stmt->execute([$subscriptionId]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[] = (string) $row['item_hash'];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
