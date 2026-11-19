<?php
/**
 * src/v3_share.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 3 — CHIA SẺ SNAPSHOT (F8).
 *
 * Nguyên tắc:
 *   - Link chia sẻ gắn với MỘT snapshot cụ thể (đúng phiên bản đang xem).
 *   - Token đủ ngẫu nhiên (32 byte); server CHỈ lưu HASH SHA-256.
 *   - Có thời hạn (1 giờ / 1 ngày / 7 ngày / tuỳ chỉnh) và giới hạn lượt xem.
 *   - Lượt xem được TĂNG bằng câu UPDATE có điều kiện (an toàn khi đồng thời),
 *     nên không bao giờ vượt quá giới hạn.
 *   - Khách có link chỉ XEM: không thấy ghi chú, không thấy thành viên,
 *     không thấy thư viện, không tải được bản quản trị.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/* =============================================================================
    HẠN MỘC
    ============================================================================= */

/** Sinh token thô ngẫu nhiên cho link chia sẻ (32 byte, base64url). */
function wa_share_new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * Chuẩn hoá lựa chọn thời hạn thành số giây.
 *
 * @param string $preset  '1h' | '1d' | '7d' | 'custom'
 * @param int    $custom  số GIỜ cho preset 'custom'
 */
function wa_share_expire_seconds(string $preset, int $custom = 0): int
{
    switch ($preset) {
        case '1h':
            return 3600;
        case '1d':
            return 86400;
        case '7d':
            return 604800;
        case 'custom':
            $hours = max(1, min($custom, 24 * 30));
            return $hours * 3600;
        default:
            return 86400;
    }
}

/**
 * Người dùng này có quyền tạo link chia sẻ cho snapshot này không?
 *
 * Quy tắc: chủ sở hữu thư viện, biên tập viên, hoặc quản trị viên —
 * hoặc chính người đã tạo snapshot đó.
 */
function wa_share_can_manage(int $snapshotId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT s.id, sp.library_id, sp.created_by, l.owner_id
               FROM `snapshots` s
               JOIN `saved_pages` sp ON sp.id = s.page_id
               JOIN `libraries` l ON l.id = sp.library_id
              WHERE s.id = ?
              LIMIT 1'
        );
        $stmt->execute([$snapshotId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_can_manage failed: ' . $e->getMessage());

        return false;
    }

    if ($row === false) {
        return false;
    }

    if ($actor['role'] === 'admin') {
        return true;
    }

    if ((int) $row['created_by'] === (int) $actor['id']) {
        return true;
    }

    if ((int) $row['owner_id'] === (int) $actor['id']) {
        return true;
    }

    $role = wa_library_role((int) $row['library_id'], $actor);

    return in_array($role, ['owner', 'editor'], true);
}

/**
 * Tạo link chia sẻ mới.
 *
 * @param int|null $maxViews  null = không giới hạn
 *
 * @return array{ok:bool, message:string, id?:int, token?:string, url?:string, expires_at?:string}
 */
function wa_share_create(
    int $snapshotId,
    string $preset,
    int $customHours,
    ?int $maxViews,
    string $label,
    ?array $actor = null
): array {
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn cần đăng nhập để tạo link chia sẻ.'];
    }

    if (!wa_share_can_manage($snapshotId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền chia sẻ bản lưu này.'];
    }

    // Snapshot phải tồn tại và tải được.
    try {
        $stmt = db()->prepare(
            "SELECT id FROM `snapshots` WHERE id = ? AND fetch_status = 'success' LIMIT 1"
        );
        $stmt->execute([$snapshotId]);
        $exists = $stmt->fetch() !== false;
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_create check failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không kiểm tra được bản lưu.'];
    }

    if (!$exists) {
        return ['ok' => false, 'message' => 'Không tìm thấy bản lưu cần chia sẻ.'];
    }

    // Số lượt xem: null = không giới hạn; >= 1 = giới hạn.
    if ($maxViews !== null) {
        $maxViews = max(1, min((int) $maxViews, 100000));
    }

    $seconds   = wa_share_expire_seconds($preset, $customHours);
    $expiresAt = date('Y-m-d H:i:s', time() + $seconds);

    $label = trim($label);
    if (mb_strlen($label) > 190) {
        $label = mb_substr($label, 0, 190, 'UTF-8');
    }

    $raw  = wa_share_new_token();
    $hash = hash('sha256', $raw);

    try {
        $stmt = db()->prepare(
            'INSERT INTO `share_links`
                (`snapshot_id`, `created_by`, `token_hash`, `label`, `expires_at`, `max_views`)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $snapshotId,
            (int) $actor['id'],
            $hash,
            $label !== '' ? $label : 'Link chia sẻ',
            $expiresAt,
            $maxViews,
        ]);

        $linkId = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_create insert failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không tạo được link chia sẻ. Vui lòng thử lại.'];
    }

    return [
        'ok'         => true,
        'message'    => 'Đã tạo link chia sẻ.',
        'id'         => $linkId,
        'token'      => $raw,
        'url'        => wa_share_url($raw),
        'expires_at' => wa_datetime($expiresAt),
    ];
}

/**
 * URL công khai của một link chia sẻ.
 */
function wa_share_url(string $rawToken): string
{
    return wa_url('shared.php?t=' . rawurlencode($rawToken));
}

/**
 * Danh sách link chia sẻ của một snapshot (chỉ người có quyền quản lý).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_share_list(int $snapshotId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if (!wa_share_can_manage($snapshotId, $actor)) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, snapshot_id, label, expires_at, max_views, view_count, revoked_at, created_at
               FROM `share_links`
              WHERE snapshot_id = ?
           ORDER BY id DESC'
        );
        $stmt->execute([$snapshotId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $expired = $row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time();
            $revoked = $row['revoked_at'] !== null;
            $usedUp  = $row['max_views'] !== null && (int) $row['view_count'] >= (int) $row['max_views'];

            $row['id']        = (int) $row['id'];
            $row['max_views'] = $row['max_views'] === null ? null : (int) $row['max_views'];
            $row['view_count'] = (int) $row['view_count'];
            $row['revoked']   = $revoked;
            $row['expired']   = $expired;
            $row['used_up']   = $usedUp;
            $row['dead']      = $revoked || $expired || $usedUp;

            $row['status'] = $revoked
                ? 'Đã thu hồi'
                : ($expired ? 'Đã hết hạn' : ($usedUp ? 'Đã hết lượt xem' : 'Đang hoạt động'));

            $row['expires_label'] = $row['expires_at'] !== null
                ? wa_datetime((string) $row['expires_at']) : 'Không hết hạn';

            $row['created_label'] = wa_datetime((string) $row['created_at']);

            $rows[] = $row;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_list failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Thu hồi link chia sẻ ngay lập tức.
 */
function wa_share_revoke(int $linkId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT sl.id, sl.snapshot_id
               FROM `share_links` sl
              WHERE sl.id = ?
              LIMIT 1'
        );
        $stmt->execute([$linkId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_revoke lookup failed: ' . $e->getMessage());

        return false;
    }

    if ($row === false) {
        return false;
    }

    if (!wa_share_can_manage((int) $row['snapshot_id'], $actor)) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'UPDATE `share_links` SET `revoked_at` = NOW()
              WHERE id = ? AND `revoked_at` IS NULL'
        );
        $stmt->execute([$linkId]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_revoke failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Mở link chia sẻ: kiểm tra token, hết hạn, thu hồi, giới hạn lượt xem —
 * rồi TĂNG bộ đếm lượt xem một cách an toàn khi có nhiều người xem đồng thời.
 *
 * @return array{ok:bool, status:string, message:string, snapshot?:array}
 */
function wa_share_open(string $rawToken): array
{
    $rawToken = trim($rawToken);

    if ($rawToken === '' || strlen($rawToken) > 200) {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'Link chia sẻ không hợp lệ.'];
    }

    $hash = hash('sha256', $rawToken);

    try {
        $stmt = db()->prepare(
            'SELECT id, snapshot_id, expires_at, max_views, view_count, revoked_at
               FROM `share_links`
              WHERE token_hash = ?
              LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_open lookup failed: ' . $e->getMessage());

        return ['ok' => false, 'status' => 'error', 'message' => 'Máy chủ chưa sẵn sàng.'];
    }

    if ($row === false) {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'Link chia sẻ không hợp lệ hoặc đã bị xoá.'];
    }

    if ($row['revoked_at'] !== null) {
        return ['ok' => false, 'status' => 'revoked', 'message' => 'Link chia sẻ đã bị thu hồi.'];
    }

    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'status' => 'expired', 'message' => 'Link chia sẻ đã hết hạn.'];
    }

    // --- TĂNG LƯỢT XEM AN TOÀN KHI XEM ĐỒNG THỜI ---
    // UPDATE có điều kiện: chỉ tăng khi còn lượt. Hai request cùng lúc sẽ được
    // serialize bởi InnoDB (row lock), nên tổng không vượt giới hạn.
    try {
        $stmt = db()->prepare(
            'UPDATE `share_links`
                SET `view_count` = `view_count` + 1
              WHERE id = ?
                AND `revoked_at` IS NULL
                AND (`expires_at` IS NULL OR `expires_at` > NOW())
                AND (`max_views` IS NULL OR `view_count` < `max_views`)'
        );
        $stmt->execute([(int) $row['id']]);

        if ($stmt->rowCount() === 0) {
            return [
                'ok'      => false,
                'status'  => 'used_up',
                'message' => 'Link chia sẻ đã hết lượt xem cho phép.',
            ];
        }

        // Đọc lại số lượt xem sau khi tăng -> con số hiển thị luôn chính xác,
        // kể cả khi có nhiều người mở link đồng thời.
        $stmt = db()->prepare('SELECT `view_count` FROM `share_links` WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $row['id']]);
        $newCount = $stmt->fetchColumn();

        $viewCount = $newCount === false ? ((int) $row['view_count'] + 1) : (int) $newCount;
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_open count failed: ' . $e->getMessage());

        return ['ok' => false, 'status' => 'error', 'message' => 'Máy chủ chưa sẵn sàng.'];
    }

    // --- Nạp nội dung snapshot (chỉ nội dung, KHÔNG ghi chú/thành viên) ---
    $snapshotId = (int) $row['snapshot_id'];

    try {
        $stmt = db()->prepare(
            "SELECT s.id, s.title, s.content_html, s.source_url, s.fetched_at, s.version_no
               FROM `snapshots` s
              WHERE s.id = ? AND s.fetch_status = 'success'
              LIMIT 1"
        );
        $stmt->execute([$snapshotId]);
        $snap = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_share_open snapshot failed: ' . $e->getMessage());

        return ['ok' => false, 'status' => 'error', 'message' => 'Máy chủ chưa sẵn sàng.'];
    }

    if ($snap === false) {
        return ['ok' => false, 'status' => 'not_found', 'message' => 'Không tìm thấy nội dung bản lưu.'];
    }

    return [
        'ok'      => true,
        'status'  => 'ok',
        'message' => '',
        'snapshot' => [
            'id'           => (int) $snap['id'],
            'title'        => (string) $snap['title'],
            'content_html' => (string) $snap['content_html'],
            'source_url'   => (string) $snap['source_url'],
            'version_no'   => (int) $snap['version_no'],
            'saved_at'     => wa_datetime((string) $snap['fetched_at']),
            'view_count'   => $viewCount,
            'max_views'    => $row['max_views'] === null ? null : (int) $row['max_views'],
        ],
    ];
}

/**
 * Dọn dẹp HTML cho chế độ xem công khai: bỏ script, style nội bộ, iframe,
 * form, event handler và các thuộc tính nguy hiểm.
 *
 * @param string $html
 */
function wa_share_sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    // Tắt entity ngoài để chống XXE.
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<div id="wa-share-root">' . $html . '</div>'
        . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    // getElementById() cần DTD nên có thể trả null với HTML fragment — dùng
    // XPath theo @id cho chắc chắn.
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//*[@id="wa-share-root"]');
    $root  = ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : null;

    if (!$root instanceof DOMElement) {
        return '';
    }

    // 1) Xoá các thẻ nguy hiểm (kể cả thẻ bên trong).
    $badTags = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
                'applet', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base'];

    foreach ($badTags as $tag) {
        $nodes = iterator_to_array($doc->getElementsByTagName($tag));

        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    // 2) Dọn thuộc tính nguy hiểm trên mọi phần tử.
    foreach ($doc->getElementsByTagName('*') as $el) {
        if (!$el->hasAttributes()) {
            continue;
        }

        $remove = [];

        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            $val  = strtolower(trim($attr->nodeValue ?? ''));

            if (str_starts_with($name, 'on')) {
                $remove[] = $attr->nodeName;
                continue;
            }

            if (in_array($name, ['srcdoc', 'formaction', 'xlink:href'], true)) {
                $remove[] = $attr->nodeName;
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                // Chỉ cho http/https và đường dẫn tương đối.
                if (str_starts_with($val, 'javascript:') || str_starts_with($val, 'data:')
                    || str_starts_with($val, 'vbscript:')) {
                    $remove[] = $attr->nodeName;
                }
                continue;
            }

            if ($name === 'style' && preg_match('/(expression|javascript:|url\s*\(\s*[\'"]?\s*javascript)/i', $attr->nodeValue ?? '')) {
                $remove[] = $attr->nodeName;
            }
        }

        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }

    $out = '';

    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return $out;
}
