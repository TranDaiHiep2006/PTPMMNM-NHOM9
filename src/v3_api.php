<?php
/**
 * src/v3_api.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 3 — API CHO CHROME EXTENSION (F7).
 *
 * Cơ chế xác thực: TOKEN RIÊNG (bearer token) thay vì cookie phiên.
 *
 *   - Người dùng tự tạo token trong trang "Kết nối Extension" của WebArchive.
 *   - Token thô CHỈ hiện đúng một lần; phía server chỉ lưu HASH (SHA-256).
 *   - Token có: tên, phạm vi quyền (scope), thời hạn, và có thể thu hồi.
 *   - Mỗi token có bộ đếm tần suất riêng (giới hạn theo cửa sổ phút).
 *
 * Bảo mật:
 *   - KHÔNG dùng CORS wildcard. Chỉ trả lại `Access-Control-Allow-Origin` khi
 *     Origin có dạng `chrome-extension://...` — Origin do CHÍNH TRÌNH DUYỆT gắn
 *     vào, website không thể giả mạo được.
 *   - Mọi thao tác ghi đều kiểm tra lại quyền ở tầng server (wa_can_write_library,
 *     chính sách URL công khai + chống SSRF) — Extension KHÔNG được bỏ qua bước này.
 *   - Không lưu mật khẩu, không lưu cookie phiên.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/* =============================================================================
    TOKEN
    ============================================================================= */

/** Phạm vi quyền của một token. */
const WA_API_SCOPES = [
    'read'            => 'Xem thông tin tài khoản và danh sách thư viện',
    'library:create'  => 'Tạo thư viện mới',
    'page:save'       => 'Lưu trang vào thư viện',
];

/** Số request tối đa mỗi cửa sổ 1 phút, theo từng token. */
const WA_API_RATE_LIMIT = 60;

/** Số lần lưu trang tối đa mỗi cửa sổ 1 phút (hành động nặng). */
const WA_API_RATE_LIMIT_SAVE = 15;

/**
 * Sinh token thô ngẫu nhiên (32 byte) + bản mã base64url.
 */
function wa_api_new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * Tạo token mới cho một tài khoản.
 *
 * @param array<int, string> $scopes
 *
 * @return array{ok:bool, message:string, token:string, id:int}
 */
function wa_api_token_create(int $userId, string $name, array $scopes, ?int $expiresDays): array
{
    $name = trim($name);

    if ($name === '') {
        $name = 'Chrome Extension';
    }

    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120, 'UTF-8');
    }

    // Chỉ giữ các scope hợp lệ; mặc định cấp đủ cho Extension.
    $clean = array_values(array_intersect($scopes, array_keys(WA_API_SCOPES)));

    if ($clean === []) {
        $clean = array_keys(WA_API_SCOPES);
    }

    $raw  = wa_api_new_token();
    $hash = hash('sha256', $raw);

    $expiresAt = $expiresDays !== null && $expiresDays > 0
        ? date('Y-m-d H:i:s', time() + $expiresDays * 86400)
        : null;

    try {
        $stmt = db()->prepare(
            'INSERT INTO `api_tokens`
                (`user_id`, `token_hash`, `name`, `scopes`, `expires_at`)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $hash,
            $name,
            implode(',', $clean),
            $expiresAt,
        ]);
    } catch (Throwable $e) {
        error_log('[WebArchive] api token create failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không tạo được token. Vui lòng thử lại.', 'token' => '', 'id' => 0];
    }

    return [
        'ok'      => true,
        'message' => 'Đã tạo token. Hãy sao chép ngay — token chỉ hiện một lần.',
        'token'   => $raw,
        'id'      => (int) db()->lastInsertId(),
    ];
}

/**
 * Danh sách token của một tài khoản (KHÔNG kèm token thô).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_api_token_list(int $userId): array
{
    try {
        $stmt = db()->prepare(
            'SELECT id, name, scopes, expires_at, revoked_at, last_used_at, created_at
               FROM `api_tokens`
              WHERE user_id = ?
           ORDER BY id DESC'
        );
        $stmt->execute([$userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $row['id']       = (int) $row['id'];
            $row['scopes']   = array_values(array_filter(explode(',', (string) $row['scopes'])));
            $row['expired']  = $row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time();
            $row['revoked']  = $row['revoked_at'] !== null;
            $row['active']   = !$row['expired'] && !$row['revoked'];
            $row['expires_label'] = $row['expires_at'] !== null
                ? wa_datetime((string) $row['expires_at']) : 'Không hết hạn';
            $row['used_label'] = $row['last_used_at'] !== null
                ? wa_datetime((string) $row['last_used_at']) : 'Chưa dùng';
            $rows[] = $row;
        }

        return $rows;
    } catch (Throwable $e) {
        error_log('[WebArchive] api token list failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * Thu hồi một token (chỉ chủ sở hữu token đó).
 */
function wa_api_token_revoke(int $tokenId, int $userId): bool
{
    try {
        $stmt = db()->prepare(
            'UPDATE `api_tokens` SET `revoked_at` = NOW()
              WHERE id = ? AND user_id = ? AND `revoked_at` IS NULL'
        );
        $stmt->execute([$tokenId, $userId]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[WebArchive] api token revoke failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Kiểm tra token thô và trả về thông tin token + tài khoản.
 *
 * @return array{ok:bool, error:string, token:?array, user:?array}
 */
function wa_api_authenticate(string $rawToken): array
{
    $fail = static fn (string $error): array => [
        'ok' => false, 'error' => $error, 'token' => null, 'user' => null,
    ];

    $rawToken = trim($rawToken);

    if ($rawToken === '') {
        return $fail('Thiếu token xác thực.');
    }

    if (strlen($rawToken) > 200) {
        return $fail('Token không hợp lệ.');
    }

    $hash = hash('sha256', $rawToken);

    try {
        $stmt = db()->prepare(
            'SELECT t.id, t.user_id, t.name, t.scopes, t.expires_at, t.revoked_at,
                    t.rate_window_start, t.rate_count, t.save_window_start, t.save_count,
                    u.full_name, u.email, u.role, u.status
               FROM `api_tokens` t
               JOIN `users` u ON u.id = t.user_id
              WHERE t.token_hash = ?
              LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] api authenticate failed: ' . $e->getMessage());

        return $fail('Máy chủ chưa sẵn sàng. Hãy chạy migration cho bảng api_tokens.');
    }

    if ($row === false) {
        return $fail('Token không hợp lệ hoặc đã bị thu hồi.');
    }

    if ($row['revoked_at'] !== null) {
        return $fail('Token đã bị thu hồi.');
    }

    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return $fail('Token đã hết hạn.');
    }

    if ((string) $row['status'] !== 'active') {
        return $fail('Tài khoản không còn hoạt động.');
    }

    return [
        'ok'    => true,
        'error' => '',
        'token' => [
            'id'      => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'name'    => (string) $row['name'],
            'scopes'  => array_values(array_filter(explode(',', (string) $row['scopes']))),
        ],
        'user'  => [
            'id'    => (int) $row['user_id'],
            'name'  => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'role'  => (string) $row['role'],
        ],
    ];
}

/**
 * Token có đủ scope không?
 */
function wa_api_token_can(array $token, string $scope): bool
{
    $scopes = (array) ($token['scopes'] ?? []);

    return in_array($scope, $scopes, true);
}

/**
 * Giới hạn tần suất theo token (cửa sổ 1 phút).
 *
 * @return array{ok:bool, remaining:int}
 */
function wa_api_rate_limit(array $token, bool $heavy = false): array
{
    $tokenId = (int) ($token['id'] ?? 0);

    if ($tokenId <= 0) {
        return ['ok' => false, 'remaining' => 0];
    }

    $limit = $heavy ? WA_API_RATE_LIMIT_SAVE : WA_API_RATE_LIMIT;
    $now   = time();

    try {
        $stmt = db()->prepare(
            'SELECT rate_window_start, rate_count, save_window_start, save_count
               FROM `api_tokens` WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$tokenId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['ok' => false, 'remaining' => 0];
        }

        if ($heavy) {
            $start = $row['save_window_start'] !== null ? (int) strtotime((string) $row['save_window_start']) : 0;
            $count = (int) $row['save_count'];

            if ($start === 0 || $now - $start >= 60) {
                $stmt = db()->prepare(
                    'UPDATE `api_tokens` SET save_window_start = NOW(), save_count = 1 WHERE id = ?'
                );
                $stmt->execute([$tokenId]);

                return ['ok' => true, 'remaining' => $limit - 1];
            }

            if ($count >= $limit) {
                return ['ok' => false, 'remaining' => 0];
            }

            $stmt = db()->prepare('UPDATE `api_tokens` SET save_count = save_count + 1 WHERE id = ?');
            $stmt->execute([$tokenId]);

            return ['ok' => true, 'remaining' => $limit - $count - 1];
        }

        $start = $row['rate_window_start'] !== null ? (int) strtotime((string) $row['rate_window_start']) : 0;
        $count = (int) $row['rate_count'];

        if ($start === 0 || $now - $start >= 60) {
            $stmt = db()->prepare(
                'UPDATE `api_tokens` SET rate_window_start = NOW(), rate_count = 1 WHERE id = ?'
            );
            $stmt->execute([$tokenId]);

            return ['ok' => true, 'remaining' => $limit - 1];
        }

        if ($count >= $limit) {
            return ['ok' => false, 'remaining' => 0];
        }

        $stmt = db()->prepare('UPDATE `api_tokens` SET rate_count = rate_count + 1 WHERE id = ?');
        $stmt->execute([$tokenId]);

        return ['ok' => true, 'remaining' => $limit - $count - 1];
    } catch (Throwable $e) {
        error_log('[WebArchive] api rate limit failed: ' . $e->getMessage());

        return ['ok' => false, 'remaining' => 0];
    }
}

/**
 * Ghi nhận lần dùng gần nhất của token (best-effort).
 */
function wa_api_token_touch(int $tokenId): void
{
    try {
        $stmt = db()->prepare('UPDATE `api_tokens` SET `last_used_at` = NOW() WHERE id = ?');
        $stmt->execute([$tokenId]);
    } catch (Throwable $e) {
        // Không quan trọng.
    }
}

/* =============================================================================
    CORS + CẢNH BÁO
    ============================================================================= */

/**
 * Origin nào được phép gọi API: chỉ Extension của Chrome.
 *
 * Trả về chuỗi Origin hợp lệ, hoặc '' nếu không hợp lệ.
 *
 * Lưu ý bảo mật: header `Origin` do CHÍNH TRÌNH DUYỆT gắn vào và một website
 * KHÔNG THỂ tự đặt/ghi đè giá trị này bằng JavaScript. Vì vậy chỉ chấp nhận
 * scheme `chrome-extension://` là an toàn: mọi request khác (kể cả từ trang
 * web) đều bị từ chối ở tầng CORS.
 */
function wa_api_allowed_origin(): string
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin === '') {
        return '';
    }

    // Định dạng chuẩn: chrome-extension://<id 32 ký tự a-p>
    if (preg_match('#^chrome-extension://[a-p]{32}$#', $origin) === 1) {
        return $origin;
    }

    // Dự phòng: mọi extension ID hợp lệ khác (ví dụ bản dựng không chuẩn).
    if (preg_match('#^chrome-extension://[a-zA-Z0-9_\-]{16,64}$#', $origin) === 1) {
        return $origin;
    }

    return '';
}

/**
 * Gửi đúng các header CORS cần thiết (KHÔNG dùng wildcard).
 */
function wa_api_cors_headers(): void
{
    $origin = wa_api_allowed_origin();

    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 600');
}

/**
 * Gửi phản hồi JSON.
 *
 * @param array<string,mixed> $data
 */
function wa_api_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Đọc token từ header Authorization.
 *
 * KHÔNG chấp nhận token qua query string — token trong URL sẽ bị ghi vào log
 * máy chủ và lịch sử trình duyệt.
 */
function wa_api_read_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (stripos($header, 'bearer ') === 0) {
        return trim(substr($header, 7));
    }

    return '';
}

/**
 * Thân yêu cầu JSON.
 *
 * @return array<string,mixed>
 */
function wa_api_input(): array
{
    $raw = (string) file_get_contents('php://input');

    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/**
 * Tìm bản lưu thành công gần nhất của một URL trong thư viện.
 *
 * Dùng để TRÁNH tạo snapshot trùng: nếu URL này vừa được lưu trong vài
 * phút trở lại thì Extension/API trả lại bản lưu cũ thay vì tạo phiên bản mới.
 *
 * @return array{id:int, page_id:int, version_no:int, saved_at:string}|null
 */
function wa_api_find_recent_snapshot(int $libraryId, string $url, int $withinMinutes = 10): ?array
{
    // Mốc thời gian được tính sẵn ở PHP (không dùng INTERVAL ? phía server
    // để tránh khác biệt cú pháp giữa các phiên bản MySQL).
    $since = date('Y-m-d H:i:s', time() - max(0, $withinMinutes) * 60);

    try {
        $stmt = db()->prepare(
            'SELECT s.id, s.page_id, s.version_no, s.fetched_at
               FROM `snapshots` s
               JOIN `saved_pages` sp ON sp.id = s.page_id
              WHERE sp.library_id = ?
                AND sp.source_url IN (?, ?)
                AND s.fetch_status = \'success\'
                AND s.fetched_at >= ?
           ORDER BY s.id DESC
              LIMIT 1'
        );
        $stmt->execute([$libraryId, $url, rtrim($url, '/'), $since]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'page_id'    => (int) $row['page_id'],
            'version_no' => (int) $row['version_no'],
            'saved_at'   => (string) $row['fetched_at'],
        ];
    } catch (Throwable $e) {
        error_log('[WebArchive] wa_api_find_recent_snapshot failed: ' . $e->getMessage());

        return null;
    }
}
