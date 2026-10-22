<?php
/**
 * src/queries.php
 * -----------------------------------------------------------------------------
 * Lớp truy vấn dữ liệu cho WebArchive — thay thế src/sample_data.php (UI phase).
 *
 * Mọi truy vấn đều dùng prepared statement; KHÔNG có nối chuỗi SQL với dữ liệu
 * do người dùng nhập.
 *
 * Quy tắc truy cập cơ bản (giai đoạn này):
 *   - Người dùng thường chỉ thấy các thư viện mà họ SỞ HỮU hoặc là THÀNH VIÊN.
 *   - Quản trị viên thấy tất cả.
 *   - Việc siết chặt phân quyền chi tiết (editor/viewer) sẽ làm ở phase sau.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Ngữ cảnh người dùng đang đăng nhập (id + vai trò) dùng để lọc truy cập.
 *
 * @return array{id:int, role:string}|null
 */
function wa_actor(): ?array
{
    $user = current_user();

    if ($user === null || !isset($user['id'])) {
        return null;
    }

    return [
        'id'   => (int) $user['id'],
        'role' => (string) ($user['role'] ?? 'user'),
    ];
}

/**
 * Người dùng hiện tại có quyền quản trị không.
 */
function wa_is_admin(): bool
{
    $actor = wa_actor();

    return $actor !== null && $actor['role'] === 'admin';
}

/**
 * Đoạn SQL lọc các thư viện mà người dùng được phép thấy.
 *
 * Lưu ý kỹ thuật: PDO đang dùng prepares THẬT (ATTR_EMULATE_PREPARES = false),
 * nên MySQL không cho phép một tham số có tên được lặp lại nhiều lần trong cùng
 * câu truy vấn. Vì vậy mỗi chỗ cần so sánh với id người dùng phải dùng một tên
 * tham số KHÁC NHAU (:actor_owner và :actor_member), dù giá trị truyền vào là
 * giống nhau. Dùng chung một tên sẽ gây lỗi SQLSTATE[HY093] Invalid parameter number.
 *
 * @return array{0:string,1:array<int|string,mixed>}
 */
function wa_library_access_sql(?array $actor): array
{
    if ($actor !== null && $actor['role'] === 'admin') {
        return ['1 = 1', []];
    }

    if ($actor === null) {
        return ['1 = 0', []];
    }

    $actorId = (int) $actor['id'];

    return [
        '(l.owner_id = :actor_owner OR EXISTS (
              SELECT 1 FROM `library_members` lm
              WHERE lm.library_id = l.id AND lm.user_id = :actor_member
          ))',
        [
            ':actor_owner'  => $actorId,
            ':actor_member' => $actorId,
        ],
    ];
}

/**
 * Nhãn tiếng Việt của vai trò thành viên.
 *
 * @return array{owner:string,editor:string,viewer:string}
 */
function wa_member_role_labels(): array
{
    return [
        'owner'  => 'Chủ sở hữu',
        'editor' => 'Biên tập viên',
        'viewer' => 'Người xem',
    ];
}

/**
 * Nhãn tiếng Việt của mức độ hiển thị thư viện.
 *
 * @return array{private:string,shared:string,public:string}
 */
function wa_visibility_labels(): array
{
    return [
        'private' => 'Chỉ mình tôi',
        'shared'  => 'Nội bộ nhóm',
        'public'  => 'Công khai',
    ];
}

/**
 * Nhãn tiếng Việt của trạng thái tài khoản.
 *
 * @return array{active:string,locked:string}
 */
function wa_user_status_labels(): array
{
    return [
        'active' => 'Hoạt động',
        'locked' => 'Bị khóa',
    ];
}

/**
 * Nhãn tiếng Việt của vai trò tài khoản.
 *
 * @return array{admin:string,user:string}
 */
function wa_user_role_labels(): array
{
    return [
        'admin' => 'Quản trị viên',
        'user'  => 'Người dùng',
    ];
}

/**
 * Tên biến thể avatar (màu) theo vai trò thành viên.
 */
function wa_avatar_variant(string $role): string
{
    return match ($role) {
        'owner'  => 'navy',
        'editor' => 'blue',
        default  => 'light',
    };
}

/**
 * Chữ viết tắt (2 ký tự đầu) của một họ tên tiếng Việt.
 */
function wa_initials(string $fullName): string
{
    $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

    if ($parts === []) {
        return 'KH';
    }

    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';

    return mb_strtoupper($first . $last, 'UTF-8');
}

/**
 * Định dạng ngày giờ kiểu Việt Nam: d/m/Y, H:i.
 */
function wa_datetime(string $value): string
{
    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y, H:i', $ts);
}

/**
 * Định dạng ngày kiểu Việt Nam: d/m/Y.
 */
function wa_date(string $value): string
{
    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y', $ts);
}

/**
 * Định dạng dung lượng (byte -> KB / MB).
 */
function wa_format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2, ',', '.') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }

    return $bytes . ' B';
}

/**
 * Định dạng dung lượng gọn (dùng cho thẻ thư viện): "142 MB".
 */
function wa_format_mb(int $bytes): string
{
    $mb = $bytes / (1024 * 1024);

    return ($mb >= 10 ? number_format($mb, 0, ',', '.') : number_format($mb, 1, ',', '.')) . ' MB';
}

/* =============================================================================
    THƯ VIỆN
    ============================================================================= */

/**
 * Danh sách thư viện mà người dùng hiện tại được thấy, kèm số liệu tổng hợp.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_libraries(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "
        SELECT
            l.id, l.owner_id, l.name, l.slug, l.description, l.visibility,
            l.color, l.icon, l.created_at,
            u.full_name AS owner_name,
            lm.role     AS member_role,
            (SELECT COUNT(*) FROM `saved_pages` sp WHERE sp.library_id = l.id)                       AS article_count,
            (SELECT COUNT(*) FROM `assets` a
                JOIN `snapshots` s ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id AND a.mime_type LIKE 'image/%')                          AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
                JOIN `snapshots` s ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id)                                                         AS storage_bytes,
            (SELECT COUNT(*) FROM `library_members` lm2 WHERE lm2.library_id = l.id)                AS member_count,
            (SELECT COUNT(DISTINCT pt.tag_id) FROM `page_tags` pt
                JOIN `saved_pages` sp3 ON sp3.id = pt.page_id
              WHERE sp3.library_id = l.id)                                                          AS tag_count
        FROM `libraries` l
        JOIN `users` u ON u.id = l.owner_id
        LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor_user
        WHERE {$accessSql}
        ORDER BY l.id
    ";

    // Nếu không phải admin, câu truy vấn cần cả :actor_user; nếu admin thì
    // left join vẫn dùng :actor_user nên phải truyền giá trị (null khi khách).
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([':actor_user' => $actor['id'] ?? 0], $params));

    $roleLabels = wa_member_role_labels();
    $visLabels  = wa_visibility_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']             = (int) $row['id'];
        $row['owner_id']       = (int) $row['owner_id'];
        $row['article_count']  = (int) $row['article_count'];
        $row['image_count']    = (int) $row['image_count'];
        $row['storage_bytes']  = (int) $row['storage_bytes'];
        $row['member_count']   = (int) $row['member_count'];
        $row['tag_count']      = (int) $row['tag_count'];

        // Vai trò: chủ sở hữu nếu là owner, ngược lại lấy vai trò thành viên.
        if ((int) $row['owner_id'] === ($actor['id'] ?? -1)) {
            $row['role'] = 'owner';
        } else {
            $row['role'] = (string) ($row['member_role'] ?? 'viewer');
        }
        if (!isset($roleLabels[$row['role']])) {
            $row['role'] = 'viewer';
        }
        $row['role_label'] = $roleLabels[$row['role']];

        $row['visibility_label'] = $visLabels[(string) $row['visibility']] ?? $row['visibility'];
        $row['strip']    = (string) $row['color'];
        $row['category'] = $row['visibility'] === 'private' ? 'personal' : 'shared';
        $row['storage_label'] = wa_format_mb($row['storage_bytes']);
        $row['updated']  = 'Cập nhật ' . wa_datetime((string) $row['created_at']);
        $row['tags']     = wa_library_tags($row['id']);
        $row['starred']  = false;
        $row['broken_images'] = 0;
        $row['integrity'] = '100% nguyên vẹn';

        $rows[] = $row;
    }

    return $rows;
}

/**
 * Danh sách nhãn của một thư viện.
 *
 * Không dùng SELECT DISTINCT ... ORDER BY t.id: với chế độ ONLY_FULL_GROUP_BY của
 * MySQL 8, cột trong ORDER BY phải nằm trong SELECT list, nên sẽ sinh lỗi 3065.
 * Thay vào đó GROUP BY chính tên nhãn rồi sắp xếp theo tên nhãn.
 *
 * @return array<int, string>
 */
function wa_library_tags(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT t.name
           FROM `tags` t
           JOIN `page_tags` pt ON pt.tag_id = t.id
           JOIN `saved_pages` sp ON sp.id = pt.page_id
          WHERE sp.library_id = ?
          GROUP BY t.name
          ORDER BY t.name"
    );
    $stmt->execute([$libraryId]);

    return array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll());
}

/**
 * Tìm một thư viện theo slug mà người dùng được phép xem.
 *
 * Kết quả gồm luôn các số liệu tổng hợp mà trang library.php hiển thị
 * (article_count, image_count, asset_size, member_count, tag_count, tags, ...).
 *
 * @return array<string, mixed>|null
 */
function wa_find_library(string $slug, ?array $actor = null): ?array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "
        SELECT
            l.*,
            u.full_name AS owner_name,
            lm.role     AS member_role,
            (SELECT COUNT(*) FROM `saved_pages` sp WHERE sp.library_id = l.id)                  AS article_count,
            (SELECT COUNT(*) FROM `assets` a
                JOIN `snapshots` s  ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id AND a.mime_type LIKE 'image/%')                     AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
                JOIN `snapshots` s  ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id)                                                    AS storage_bytes,
            (SELECT COUNT(*) FROM `library_members` lm2 WHERE lm2.library_id = l.id)           AS member_count,
            (SELECT COUNT(DISTINCT pt.tag_id) FROM `page_tags` pt
                JOIN `saved_pages` sp3 ON sp3.id = pt.page_id
              WHERE sp3.library_id = l.id)                                                   AS tag_count
          FROM `libraries` l
          JOIN `users` u ON u.id = l.owner_id
          LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor_user
         WHERE l.slug = :slug AND {$accessSql}
         LIMIT 1
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge(
        [':actor_user' => $actor['id'] ?? 0, ':slug' => $slug],
        $params
    ));

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $row['id']             = (int) $row['id'];
    $row['owner_id']       = (int) $row['owner_id'];
    $row['article_count']  = (int) $row['article_count'];
    $row['image_count']    = (int) $row['image_count'];
    $row['storage_bytes']  = (int) $row['storage_bytes'];
    $row['member_count']   = (int) $row['member_count'];
    $row['tag_count']      = (int) $row['tag_count'];

    if ($row['owner_id'] === ($actor['id'] ?? -1)) {
        $row['role'] = 'owner';
    } else {
        $row['role'] = (string) ($row['member_role'] ?? 'viewer');
    }

    $row['role_label'] = wa_member_role_labels()[$row['role']] ?? 'Người xem';
    $row['visibility_label'] = wa_visibility_labels()[(string) $row['visibility']] ?? $row['visibility'];

    // Các nhãn phục vụ giao diện.
    $row['asset_size'] = wa_format_mb($row['storage_bytes']);
    $row['tags']       = wa_library_tags($row['id']);
    $row['integrity']  = '100% nguyên vẹn';

    return $row;
}

/**
 * Thành viên của một thư viện.
 *
 * Mỗi phần tử có cả 'name' (tên hiển thị) và 'full_name' (tên gốc trong CSDL)
 * để khớp với giao diện, vốn hiển thị $member['name'].
 *
 * @return array<int, array<string, mixed>>
 */
function wa_library_members(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT lm.role, u.id, u.full_name, u.email
           FROM `library_members` lm
           JOIN `users` u ON u.id = lm.user_id
          WHERE lm.library_id = ?
          ORDER BY FIELD(lm.role, 'owner', 'editor', 'viewer'), u.id"
    );
    $stmt->execute([$libraryId]);

    $roleLabels = wa_member_role_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']    = (int) $row['id'];
        $row['name']  = (string) $row['full_name'];
        $row['role_label'] = $roleLabels[(string) $row['role']] ?? 'Người xem';
        $row['initials']   = wa_initials((string) $row['full_name']);
        $row['variant']    = wa_avatar_variant((string) $row['role']);
        $rows[] = $row;
    }

    return $rows;
}

/* =============================================================================
    BÀI VIẾT / SNAPSHOT
    ============================================================================= */

/**
 * Các bài viết (saved_pages) của một thư viện, kèm phiên bản snapshot mới nhất.
 *
 * QUY TẮC: danh sách bài viết chỉ gồm những trang CÓ ÍT NHẤT một snapshot
 * fetch_status = 'success'. Một lần lưu thất bại (blocked / 404 / timeout /
 * http / no_content) vẫn được ghi vào CSDL để thống kê quản trị đếm lỗi, nhưng
 * trang đó không bao giờ hiện ra trong danh sách bài viết hay mở được trong trình đọc.
 *
 * P-READ: mỗi thẻ bài viết mang thêm `version_count` (số bản THÀNH CÔNG) để
 * hiển thị huy hiệu "N phiên bản", và tiêu đề lấy từ bản thành công mới nhất.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_library_articles(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT
            sp.id, sp.source_url, sp.domain, sp.status, sp.created_at, sp.updated_at,
            s.id AS snapshot_id, s.version_no, s.title, s.fetched_at, s.http_status, s.fetch_status,
            s.content_text,
            (SELECT COUNT(*) FROM `snapshots` s3
              WHERE s3.page_id = sp.id AND s3.fetch_status = 'success')                  AS version_count,
            (SELECT COUNT(*) FROM `assets` a
               JOIN `snapshots` s2 ON s2.id = a.snapshot_id
              WHERE s2.page_id = sp.id AND a.mime_type LIKE 'image/%')                    AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
               JOIN `snapshots` s2 ON s2.id = a.snapshot_id
              WHERE s2.page_id = sp.id)                                                   AS size_bytes
          FROM `saved_pages` sp
          JOIN `snapshots` s ON s.id = (
                SELECT s2.id FROM `snapshots` s2
                 WHERE s2.page_id = sp.id
                   AND s2.fetch_status = 'success'
                 ORDER BY s2.version_no DESC
                 LIMIT 1
          )
         WHERE sp.library_id = ?
         ORDER BY sp.created_at DESC, sp.id DESC"
    );
    $stmt->execute([$libraryId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']          = (int) $row['id'];
        $row['snapshot_id'] = $row['snapshot_id'] !== null ? (int) $row['snapshot_id'] : null;
        $row['version_no']  = $row['version_no'] !== null ? (int) $row['version_no'] : 1;
        $row['version_count'] = (int) $row['version_count'];
        $row['image_count'] = (int) $row['image_count'];
        $row['size_bytes']  = (int) $row['size_bytes'];
        $row['size_label']  = wa_format_bytes($row['size_bytes']);
        // P-READ: thời điểm tải của bản thành công mới nhất (không phải ngày
        // tạo dòng saved_pages) — đúng ý nghĩa "lần cập nhật gần nhất".
        $row['saved_at']    = wa_datetime((string) $row['fetched_at']);
        $row['title']       = (string) $row['title'];
        $row['tags']        = wa_page_tags((int) $row['id']);
        $row['version']     = 'v' . $row['version_no'] . '.0';
        $row['version_label'] = $row['version_count'] . ' phiên bản';

        // Đoạn trích hiển thị trên thẻ bài viết, lấy từ nội dung snapshot đã lưu.
        $row['excerpt'] = wa_trim((string) ($row['content_text'] ?? ''), 180);

        // Nhãn + mô tả kỹ thuật cho ô xem trước (thumbnail).
        [$thumbLabel, $thumbCode] = wa_thumb_preview((string) $row['domain'], (int) $row['version_no']);
        $row['thumb_label'] = $thumbLabel;
        $row['thumb_code']  = $thumbCode;

        $rows[] = $row;
    }

    return $rows;
}

/**
 * Nhãn và đoạn mã kỹ thuật hiển thị trong ô xem trước của thẻ bài viết.
 *
 * @return array{0:string,1:string}
 */
function wa_thumb_preview(string $domain, int $versionNo): array
{
    $labels = [
        'phptutorial.vn'   => 'HTML DOM Snapshot',
        'techblog.dev'      => 'MySQL Query Engine',
        'tapchicongnghe.vn' => 'Security Sandbox',
        'dev-insights.org'  => 'Fulltext Index',
    ];

    $codes = [
        'phptutorial.vn'   => '<article class="markdown-table">' . "\n" . '  <table data-preserved="true">...</table>',
        'techblog.dev'      => 'INDEX (library_id, created_at)' . "\n" . 'EXPLAIN SELECT * FROM snapshots...',
        'tapchicongnghe.vn' => 'CRAWLER :: DOMPurify + Regex' . "\n" . 'assets/images/2026/10/img_921.webp',
        'dev-insights.org'  => 'FULLTEXT (title, body)' . "\n" . "MATCH(title, body) AGAINST('...')",
    ];

    return [
        $labels[$domain] ?? 'HTML Snapshot',
        $codes[$domain] ?? 'snapshot v' . $versionNo . ' · ' . $domain,
    ];
}

/**
 * Nhãn của một trang đã lưu.
 *
 * @return array<int, string>
 */
function wa_page_tags(int $pageId): array
{
    $stmt = db()->prepare(
        "SELECT t.name
           FROM `tags` t
           JOIN `page_tags` pt ON pt.tag_id = t.id
          WHERE pt.page_id = ?
          ORDER BY t.id"
    );
    $stmt->execute([$pageId]);

    return array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll());
}

/**
 * Lấy một phiên bản snapshot theo id, kèm thông tin trang và thư viện.
 * Tôn trọng quy tắc truy cập của người dùng hiện tại.
 *
 * QUY TẮC: chỉ trả về snapshot có fetch_status = 'success'. Snapshot lỗi vẫn còn
 * trong CSDL để thống kê quản trị, nhưng không bao giờ mở được như bài viết hợp lệ.
 *
 * @return array<string, mixed>|null
 */
function wa_find_snapshot(int $snapshotId, ?array $actor = null): ?array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "
        SELECT
            s.id, s.page_id, s.version_no, s.title, s.content_html, s.content_text,
            s.source_url, s.fetched_at, s.http_status, s.fetch_status, s.error_message,
            sp.library_id, sp.domain, sp.status AS page_status,
            l.name AS library_name, l.slug AS library_slug,
            u.full_name AS creator_name,
            (SELECT COUNT(*) FROM `assets` a WHERE a.snapshot_id = s.id AND a.mime_type LIKE 'image/%') AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a WHERE a.snapshot_id = s.id)         AS size_bytes
          FROM `snapshots` s
          JOIN `saved_pages` sp ON sp.id = s.page_id
          JOIN `libraries` l ON l.id = sp.library_id
          JOIN `users` u ON u.id = sp.created_by
         WHERE s.id = :snapshot_id AND s.fetch_status = 'success' AND {$accessSql}
         LIMIT 1
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([':snapshot_id' => $snapshotId], $params));

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $row['id']          = (int) $row['id'];
    $row['page_id']     = (int) $row['page_id'];
    $row['library_id']  = (int) $row['library_id'];
    $row['version_no']  = (int) $row['version_no'];
    $row['image_count'] = (int) $row['image_count'];
    $row['size_bytes']  = (int) $row['size_bytes'];
    $row['size_label']  = wa_format_bytes($row['size_bytes']);
    $row['saved_at']    = wa_datetime((string) $row['fetched_at']);
    $row['tags']        = wa_page_tags((int) $row['page_id']);

    return $row;
}

/* =============================================================================
    TÌM KIẾM
    ============================================================================= */

/**
 * Tìm kiếm trên nội dung snapshot đã lưu (LIKE trên title + content_text).
 *
 * QUY TẮC: chỉ tìm trong snapshot có fetch_status = 'success' — kết quả tìm kiếm
 * luôn trỏ tới một bản đọc hợp lệ, không bao giờ tới một lần lưu thất bại.
 *
 * @param array{q?:string,tag?:string,domain?:string} $filters
 * @return array<int, array<string, mixed>>
 */
function wa_search_snapshots(array $filters, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $query   = trim((string) ($filters['q'] ?? ''));
    $tag     = trim((string) ($filters['tag'] ?? ''));
    $domain  = trim((string) ($filters['domain'] ?? ''));

    $where  = ["s.fetch_status = 'success'", $accessSql];
    $bind   = $params;

    if ($query !== '') {
        // Hai tham số tên khác nhau (không dùng chung :q) vì prepares thật của PDO
        // không cho phép lặp lại một tham số có tên trong cùng câu truy vấn.
        $where[] = '(s.title LIKE :q_title OR s.content_text LIKE :q_text)';
        $bind[':q_title'] = '%' . $query . '%';
        $bind[':q_text']  = '%' . $query . '%';
    }

    if ($tag !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM `page_tags` pt JOIN `tags` t ON t.id = pt.tag_id
                             WHERE pt.page_id = sp.id AND t.name = :tag)';
        $bind[':tag'] = $tag;
    }

    if ($domain !== '') {
        $where[] = 'sp.domain = :domain';
        $bind[':domain'] = $domain;
    }

    // Khi không có điều kiện lọc nào, chỉ trả về danh sách rỗng (không liệt kê tất cả).
    if ($query === '' && $tag === '' && $domain === '') {
        return [];
    }

    $sql = "
        SELECT
            s.id, s.page_id, s.version_no, s.title, s.content_text, s.fetched_at,
            sp.domain, sp.library_id, l.name AS library_name, l.slug AS library_slug,
            (SELECT COUNT(*) FROM `assets` a WHERE a.snapshot_id = s.id AND a.mime_type LIKE 'image/%') AS image_count
          FROM `snapshots` s
          JOIN `saved_pages` sp ON sp.id = s.page_id
          JOIN `libraries` l ON l.id = sp.library_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY s.fetched_at DESC, s.id DESC
         LIMIT 200
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($bind);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']         = (int) $row['id'];
        $row['page_id']    = (int) $row['page_id'];
        $row['version_no'] = (int) $row['version_no'];
        $row['image_count'] = (int) $row['image_count'];
        $row['saved_at']   = wa_datetime((string) $row['fetched_at']);
        $row['tags']       = wa_page_tags((int) $row['page_id']);
        $row['snippet']    = wa_snippet((string) $row['content_text'], $query);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Tạo đoạn trích quanh từ khoá tìm kiếm.
 */
function wa_snippet(string $text, string $keyword, int $radius = 110): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

    if ($text === '') {
        return '';
    }

    if ($keyword === '') {
        return wa_trim($text, $radius * 2);
    }

    $pos = mb_stripos($text, $keyword, 0, 'UTF-8');

    if ($pos === false) {
        return wa_trim($text, $radius * 2);
    }

    $start = max(0, $pos - $radius);
    $chunk = mb_substr($text, $start, $radius * 2, 'UTF-8');

    return ($start > 0 ? '…' : '') . $chunk . (mb_strlen($text, 'UTF-8') > $start + $radius * 2 ? '…' : '');
}

/**
 * Danh sách tên miền có trong kho (để lọc tìm kiếm).
 *
 * @return array<int, string>
 */
function wa_snapshot_domains(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "SELECT DISTINCT sp.domain
              FROM `saved_pages` sp
              JOIN `libraries` l ON l.id = sp.library_id
             WHERE {$accessSql}
             ORDER BY sp.domain";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map(static fn (array $r): string => (string) $r['domain'], $stmt->fetchAll());
}

/**
 * Toàn bộ thẻ đang có trong hệ thống.
 *
 * @return array<int, string>
 */
function wa_all_tags(): array
{
    $stmt = db()->query('SELECT name FROM `tags` ORDER BY id');

    return array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll());
}

/* =============================================================================
    NGƯỜI DÙNG & QUẢN TRỊ
    ============================================================================= */

/**
 * Danh sách tài khoản kèm số thư viện (quản trị).
 *
 * @return array<int, array<string, mixed>>
 */
function wa_users(): array
{
    $stmt = db()->query(
        "SELECT u.id, u.full_name, u.email, u.role, u.status, u.created_at,
                (SELECT COUNT(*) FROM `libraries` l WHERE l.owner_id = u.id) AS library_count
           FROM `users` u
          ORDER BY u.id"
    );

    $roleLabels  = wa_user_role_labels();
    $statusLabels = wa_user_status_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']      = (int) $row['id'];
        $row['name']    = (string) $row['full_name'];
        $row['libraries'] = (int) $row['library_count'];
        $row['role_label']  = $roleLabels[(string) $row['role']] ?? 'Người dùng';
        $row['status_label'] = $statusLabels[(string) $row['status']] ?? 'Hoạt động';
        $row['joined']  = wa_date((string) $row['created_at']);
        $row['initials'] = wa_initials((string) $row['full_name']);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Toàn bộ thư viện trên hệ thống (quản trị), kèm chủ sở hữu.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_all_libraries(): array
{
    $stmt = db()->query(
        "SELECT l.id, l.name, l.slug, l.visibility, l.color, l.icon,
                u.full_name AS owner_name,
                (SELECT COUNT(*) FROM `saved_pages` sp WHERE sp.library_id = l.id) AS article_count,
                (SELECT COUNT(*) FROM `assets` a
                   JOIN `snapshots` s ON s.id = a.snapshot_id
                   JOIN `saved_pages` sp2 ON sp2.id = s.page_id
                  WHERE sp2.library_id = l.id AND a.mime_type LIKE 'image/%') AS image_count,
                (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
                   JOIN `snapshots` s ON s.id = a.snapshot_id
                   JOIN `saved_pages` sp2 ON sp2.id = s.page_id
                  WHERE sp2.library_id = l.id) AS storage_bytes,
                (SELECT COUNT(*) FROM `library_members` lm WHERE lm.library_id = l.id) AS member_count
           FROM `libraries` l
           JOIN `users` u ON u.id = l.owner_id
          ORDER BY l.id"
    );

    $visLabels = wa_visibility_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']            = (int) $row['id'];
        $row['article_count'] = (int) $row['article_count'];
        $row['image_count']   = (int) $row['image_count'];
        $row['storage_bytes'] = (int) $row['storage_bytes'];
        $row['member_count']  = (int) $row['member_count'];
        $row['visibility_label'] = $visLabels[(string) $row['visibility']] ?? $row['visibility'];
        $row['visibility_code']  = (string) $row['visibility'];
        $row['strip']  = (string) $row['color'];
        $row['storage_label'] = wa_format_mb($row['storage_bytes']);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Danh sách tên miền được duyệt (quản trị).
 *
 * @return array<int, array<string, mixed>>
 */
function wa_allowlist(): array
{
    $stmt = db()->query(
        "SELECT a.id, a.domain, a.note, a.created_at, u.full_name AS added_by
           FROM `allowlist_domains` a
           JOIN `users` u ON u.id = a.added_by
          ORDER BY a.id"
    );

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']      = (int) $row['id'];
        $row['date']    = wa_date((string) $row['created_at']);
        $row['status']  = 'active';
        $row['status_label'] = 'Đang hoạt động';
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Thêm một tên miền vào allowlist.
 *
 * Chấp nhận cả tên miền nhiều nhãn ("tenmien.vn") và tên máy chủ một nhãn
 * ("localhost") — máy chủ kiểm thử cục bộ của dự án dùng dạng một nhãn.
 *
 * @return array{ok:bool, message:string}
 */
function wa_allowlist_add(string $domain, string $note, int $addedBy): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    $note   = trim($note);

    // Một nhãn: localhost. Nhiều nhãn: tenmien.vn, a.b.example.org.
    $valid = (bool) preg_match(
        '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i',
        $domain
    );

    if (!$valid) {
        return [
            'ok'      => false,
            'message' => 'Tên miền không hợp lệ. Vui lòng nhập một tên miền dạng "tenmien.vn" hoặc "localhost".',
        ];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `allowlist_domains` (`domain`, `note`, `added_by`) VALUES (?, ?, ?)'
        );
        $stmt->execute([$domain, $note !== '' ? $note : null, $addedBy]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return [
                'ok'      => false,
                'message' => 'Tên miền "' . $domain . '" đã có trong danh sách được duyệt.',
            ];
        }

        throw $e;
    }

    return [
        'ok'      => true,
        'message' => 'Đã thêm tên miền "' . $domain . '" vào danh sách được duyệt.',
    ];
}

/**
 * Tổng quan hệ thống cho trang quản trị.
 *
 * @return array<string, mixed>
 */
function wa_admin_stats(): array
{
    $pdo = db();

    $count = static function (string $sql) use ($pdo): int {
        return (int) $pdo->query($sql)->fetchColumn();
    };

    $storageBytes = (int) $pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM `assets`')->fetchColumn();

    return [
        'user_count'       => $count('SELECT COUNT(*) FROM `users`'),
        'admin_count'      => $count("SELECT COUNT(*) FROM `users` WHERE role = 'admin'"),
        'locked_count'     => $count("SELECT COUNT(*) FROM `users` WHERE status = 'locked'"),
        'library_count'    => $count('SELECT COUNT(*) FROM `libraries`'),
        'shared_count'     => $count("SELECT COUNT(*) FROM `libraries` WHERE visibility <> 'private'"),
        'personal_count'   => $count("SELECT COUNT(*) FROM `libraries` WHERE visibility = 'private'"),
        'snapshot_count'   => $count('SELECT COUNT(*) FROM `snapshots`'),
        'error_count'      => $count("SELECT COUNT(*) FROM `snapshots` WHERE fetch_status <> 'success'"),
        'asset_count'      => $count("SELECT COUNT(*) FROM `assets` WHERE mime_type LIKE 'image/%'"),
        'image_count'      => $count("SELECT COUNT(*) FROM `assets` WHERE mime_type LIKE 'image/%'"),
        'page_count'       => $count('SELECT COUNT(*) FROM `saved_pages`'),
        'storage_bytes'    => $storageBytes,
        'storage_label'    => $storageBytes >= 1024 * 1024 * 1024
            ? number_format($storageBytes / (1024 * 1024 * 1024), 2, ',', '.') . ' GB'
            : wa_format_mb($storageBytes),
        'allowlist_count'  => $count('SELECT COUNT(*) FROM `allowlist_domains`'),
    ];
}

/**
 * Hoạt động gần đây, ghép từ dữ liệu thật trong CSDL (trang quản trị).
 *
 * Gồm các snapshot mới nhất và các tên miền vừa được thêm vào allowlist.
 *
 * @return array<int, array{icon:string, text:string, time:string}>
 */
function wa_recent_activity(int $limit = 6): array
{
    $limit = max(1, $limit);
    $items = [];

    // 1) Các bản snapshot mới nhất.
    $stmt = db()->prepare(
        "SELECT s.title, s.version_no, s.fetched_at, u.full_name AS creator_name, l.name AS library_name
           FROM `snapshots` s
           JOIN `saved_pages` sp ON sp.id = s.page_id
           JOIN `libraries` l     ON l.id = sp.library_id
           JOIN `users` u         ON u.id = sp.created_by
          ORDER BY s.fetched_at DESC, s.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'icon' => 'archive',
            'text' => 'Lưu snapshot v' . (int) $row['version_no'] . ' “' . wa_trim((string) $row['title'], 60) . '” vào thư viện ' . (string) $row['library_name'],
            'time' => wa_datetime((string) $row['fetched_at']) . ' • ' . (string) $row['creator_name'],
        ];
    }

    // 2) Các tên miền vừa được duyệt.
    $stmt = db()->prepare(
        "SELECT a.domain, a.created_at, u.full_name AS added_by
           FROM `allowlist_domains` a
           JOIN `users` u ON u.id = a.added_by
          ORDER BY a.created_at DESC, a.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'icon' => 'public',
            'text' => 'Thêm tên miền "' . (string) $row['domain'] . '" vào danh sách được duyệt',
            'time' => wa_datetime((string) $row['created_at']) . ' • ' . (string) $row['added_by'],
        ];
    }

    // Sắp xếp theo thời gian giảm dần rồi cắt lấy $limit sự kiện.
    usort($items, static function (array $a, array $b): int {
        return strcmp((string) $b['time'], (string) $a['time']);
    });

    return array_slice($items, 0, $limit);
}

/* =============================================================================
    LƯU TRANG — dữ liệu cho public/save.php (P-CAPTURE)
    ============================================================================= */

/**
 * Các thư viện mà người dùng được PHÉP LƯU TRANG vào (chủ sở hữu hoặc biên tập viên).
 *
 * Người xem (viewer) không thấy thư viện trong danh sách này.
 *
 * @return array<int, array{id:int, name:string, slug:string, role:string}>
 */
function wa_writable_libraries(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return [];
    }

    $userId = (int) $actor['id'];

    $stmt = db()->prepare(
        "SELECT l.id, l.name, l.slug,
                CASE WHEN l.owner_id = :owner_check THEN 'owner' ELSE lm.role END AS role
           FROM `libraries` l
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :member_check
          WHERE l.owner_id = :owner_where
             OR lm.role IN ('owner', 'editor')
          ORDER BY l.name"
    );

    // Ba vị trí so sánh cần BA tên tham số khác nhau (prepares thật của PDO không
    // cho phép lặp lại một tên tham số trong cùng câu lệnh — lỗi SQLSTATE[HY093]).
    $stmt->execute([
        ':owner_check'  => $userId,
        ':member_check' => $userId,
        ':owner_where'  => $userId,
    ]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id'   => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'role' => (string) $row['role'],
        ];
    }

    return $rows;
}

/**
 * Người dùng có quyền lưu trang vào thư viện này không (chủ sở hữu hoặc biên tập viên)?
 */
function wa_can_write_library(int $libraryId, ?array $actor = null): bool
{
    foreach (wa_writable_libraries($actor) as $library) {
        if ($library['id'] === $libraryId) {
            return true;
        }
    }

    return false;
}

/**
 * Tìm trang đã lưu trong một thư viện theo URL nguồn (để quyết định tạo bản mới
 * hay thêm phiên bản tiếp theo).
 *
 * Thử cả dạng có và không có dấu "/" ở cuối để việc gõ tay không sinh ra hai
 * trang khác nhau cho cùng một nguồn.
 *
 * @return array<string, mixed>|null
 */
function wa_find_page_by_url(int $libraryId, string $sourceUrl): ?array
{
    $candidates = [$sourceUrl, rtrim($sourceUrl, '/')];

    foreach ($candidates as $candidate) {
        $stmt = db()->prepare(
            'SELECT id, library_id, source_url, domain, title, status, created_by
               FROM `saved_pages`
              WHERE library_id = ? AND source_url = ?
              LIMIT 1'
        );
        $stmt->execute([$libraryId, $candidate]);

        $row = $stmt->fetch();
        if ($row !== false) {
            $row['id'] = (int) $row['id'];
            return $row;
        }
    }

    return null;
}

/**
 * Tạo mới một dòng saved_pages.
 *
 * @return int id của trang vừa tạo
 */
function wa_create_page(
    int $libraryId,
    string $sourceUrl,
    string $domain,
    string $title,
    string $status,
    int $createdBy
): int {
    $stmt = db()->prepare(
        "INSERT INTO `saved_pages` (`library_id`, `source_url`, `domain`, `title`, `status`, `created_by`)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $libraryId,
        $sourceUrl,
        $domain,
        $title !== '' ? $title : $domain,
        $status,
        $createdBy,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Cập nhật tiêu đề và trạng thái của một trang đã lưu.
 */
function wa_update_page(int $pageId, string $title, string $status): void
{
    $stmt = db()->prepare(
        'UPDATE `saved_pages` SET `title` = ?, `status` = ? WHERE id = ?'
    );
    $stmt->execute([$title !== '' ? $title : 'Trang chưa có tiêu đề', $status, $pageId]);
}

/**
 * Số phiên bản lớn nhất đã có của một trang (0 nghĩa là chưa có phiên bản nào).
 */
function wa_max_snapshot_version(int $pageId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(version_no), 0) FROM `snapshots` WHERE page_id = ?'
    );
    $stmt->execute([$pageId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Tạo mới một dòng snapshots.
 *
 * Hàm này được gọi TRƯỚC khi tải ảnh, vì thư mục lưu ảnh là
 * public/uploads/snapshots/<snapshot_id>/ nên phải có id trước.
 *
 * @return int id của snapshot vừa tạo
 */
function wa_create_snapshot(
    int $pageId,
    int $versionNo,
    string $title,
    string $contentHtml,
    string $contentText,
    string $sourceUrl,
    string $fetchedAt,
    int $httpStatus,
    string $fetchStatus,
    ?string $errorMessage
): int {
    $stmt = db()->prepare(
        "INSERT INTO `snapshots`
            (`page_id`, `version_no`, `title`, `content_html`, `content_text`,
             `source_url`, `fetched_at`, `http_status`, `fetch_status`, `error_message`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $pageId,
        $versionNo,
        $title !== '' ? $title : 'Trang chưa có tiêu đề',
        $contentHtml,
        $contentText,
        $sourceUrl,
        $fetchedAt,
        $httpStatus,
        $fetchStatus,
        $errorMessage,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Cập nhật nội dung của một snapshot sau khi ảnh đã được nối lại đường dẫn cục bộ.
 */
function wa_update_snapshot_content(int $snapshotId, string $contentHtml, string $contentText): void
{
    $stmt = db()->prepare(
        'UPDATE `snapshots` SET `content_html` = ?, `content_text` = ? WHERE id = ?'
    );
    $stmt->execute([$contentHtml, $contentText, $snapshotId]);
}

/**
 * Ghi các dòng assets cho một snapshot (ảnh đã tải về máy chủ nội bộ).
 *
 * @param array<int, array{original_url:string, local_path:string, mime_type:string, size_bytes:int}> $images
 */
function wa_insert_assets(int $snapshotId, array $images): void
{
    if ($images === []) {
        return;
    }

    $stmt = db()->prepare(
        "INSERT INTO `assets` (`snapshot_id`, `original_url`, `local_path`, `mime_type`, `size_bytes`)
         VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($images as $image) {
        $stmt->execute([
            $snapshotId,
            (string) $image['original_url'],
            (string) $image['local_path'],
            (string) ($image['mime_type'] !== '' ? $image['mime_type'] : 'application/octet-stream'),
            (int) $image['size_bytes'],
        ]);
    }
}

/**
 * Gắn nhãn cho một trang đã lưu (tạo nhãn nếu chưa có).
 *
 * @param string[] $tagNames
 */
function wa_apply_page_tags(int $pageId, array $tagNames): void
{
    $names = [];
    foreach ($tagNames as $name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        if ($name !== '' && mb_strlen($name) <= 64) {
            $names[$name] = $name;
        }
    }

    if ($names === []) {
        return;
    }

    $findTag   = db()->prepare('SELECT id FROM `tags` WHERE name = ? LIMIT 1');
    $insertTag = db()->prepare('INSERT IGNORE INTO `tags` (`name`) VALUES (?)');
    $getTagId  = db()->prepare('SELECT id FROM `tags` WHERE name = ? LIMIT 1');
    $attachTag = db()->prepare(
        'INSERT IGNORE INTO `page_tags` (`page_id`, `tag_id`) VALUES (?, ?)'
    );

    foreach ($names as $name) {
        $findTag->execute([$name]);

        if ($findTag->fetchColumn() === false) {
            $insertTag->execute([$name]);
        }

        $getTagId->execute([$name]);
        $tagId = $getTagId->fetchColumn();

        if ($tagId !== false) {
            $attachTag->execute([$pageId, (int) $tagId]);
        }
    }
}

/**
 * Tách chuỗi nhãn người dùng nhập (phân tách bằng dấu phẩy) thành mảng tên.
 *
 * @return string[]
 */
function wa_parse_tag_input(string $input): array
{
    $parts = preg_split('/[,;\r\n]+/u', $input) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
}

/* =============================================================================
    ĐƯỜNG LƯU DÙNG CHUNG — P-READ
    -----------------------------------------------------------------------------
    Hàm DUY NHẤT ghi một lần lưu trang xong CSDL. Cả hai nơi gọi đều dùng chung:
      1. public/save.php    — lưu trang mới / thêm phiên bản tiếp theo.
      2. public/refetch.php — nút "Lấy phiên bản mới" trong trình đọc.
    Việc gom về một hàm bảo đảm hai nơi không thể lệch nhau: find-or-create
    saved_pages, version_no = MAX + 1, INSERT snapshots, tải + nối lại ảnh cục bộ,
    INSERT assets, cập nhật trang + gắn nhãn, và ghi bản ghi lỗi.
    -------------------------------------------------------------------------- */

/**
 * Lưu một lần tải trang vào CSDL theo đúng một đường duy nhất.
 *
 * Luồng (khác với capture_url() ở chỗ đây là ghi CSDL):
 *   1. capture_url($url)                        — tải + bóc tách + sanitize
 *   2. tìm hoặc tạo saved_pages theo (library_id, source_url)
 *   3. version_no = MAX(version_no) + 1
 *   4. THÀNH CÔNG: INSERT snapshots -> tải ảnh về máy chủ nội bộ -> UPDATE
 *      content_html/content_text -> INSERT assets -> UPDATE saved_pages -> gắn nhãn
 *   5. THẤT BẠI: INSERT snapshots với fetch_status = mã lỗi và error_message
 *      tiếng Việt riêng cho từng mã (bản ghi lỗi KHÔNG bao giờ mở được để đọc)
 *
 * Refetch dùng ĐÚNG hàm này nên: phiên bản cũ (kể cả ghi chú của chúng) không
 * bị đụng tới — hàm chỉ INSERT thêm một dòng snapshots mới.
 *
 * @param int      $libraryId Thư viện đích (đã kiểm tra quyền ở nơi gọi).
 * @param string   $url       URL nguồn (dùng đúng URL này để tìm trang đã lưu).
 * @param string[] $tagNames  Danh sách tên nhãn tuỳ chọn.
 * @param int      $userId    Tài khoản thực hiện lưu (saved_pages.created_by).
 *
 * @return array{
 *   ok:bool, error_code:?string, error_title:string, error_message:string,
 *   page_id:int, version:int, snapshot_id:?int, title:string, source_url:string,
 *   domain:string, http_status:int, fetched_at:string, image_count:int
 * }
 */
function wa_store_page_capture(int $libraryId, string $url, array $tagNames, int $userId): array
{
    $url = trim($url);

    /* ---------- BƯỚC 1: chạy engine tải trang thật ---------- */
    $result = capture_url($url);

    $sourceUrl = (string) $result['final_url'] !== '' ? (string) $result['final_url'] : $url;
    $domain    = wa_capture_host($sourceUrl);

    /* ---------- BƯỚC 2: tìm hoặc tạo saved_pages ---------- */
    $page = wa_find_page_by_url($libraryId, $url);

    if ($page === null) {
        $pageId = wa_create_page(
            $libraryId,
            $url,
            $domain !== '' ? $domain : 'khong-ro',
            (string) $result['title'],
            $result['ok'] === true ? 'active' : 'error',
            $userId
        );
        $version = 1;
    } else {
        $pageId  = (int) $page['id'];
        $version = wa_max_snapshot_version($pageId) + 1;
    }

    /* ---------- BƯỚC 3 + 4: ghi snapshot theo kết quả tải ---------- */
    if ($result['ok'] === true) {
        // 4a) Tạo dòng snapshot trước để có id cho thư mục ảnh.
        $snapshotId = wa_create_snapshot(
            $pageId,
            $version,
            (string) $result['title'],
            '',
            '',
            $sourceUrl,
            (string) $result['fetched_at'],
            (int) $result['http_status'],
            'success',
            null
        );

        // 4b) Tải ảnh về máy chủ nội bộ và nối lại bằng đường dẫn cục bộ.
        $localized = wa_capture_localize_images((string) $result['content_html'], $snapshotId);
        $finalHtml = (string) $localized['html'];
        $images    = $localized['images'];

        // 4c) Bỏ qua nếu còn sót ảnh ngoài — không bao giờ xảy ra (phòng vệ).
        if (!wa_capture_localize_images_ok($finalHtml)) {
            $finalHtml = (string) $result['content_html'];
            $images    = [];
        }

        $finalText = wa_capture_clean_text(wa_capture_text_only($finalHtml));
        wa_update_snapshot_content($snapshotId, $finalHtml, $finalText);

        // 4d) Ghi các dòng assets.
        wa_insert_assets($snapshotId, $images);

        // 4e) Cập nhật trang + gắn nhãn.
        wa_update_page($pageId, (string) $result['title'], 'active');
        wa_apply_page_tags($pageId, $tagNames);

        return [
            'ok'            => true,
            'error_code'    => null,
            'error_title'   => '',
            'error_message' => '',
            'page_id'       => $pageId,
            'version'       => $version,
            'snapshot_id'   => $snapshotId,
            'title'         => (string) $result['title'],
            'source_url'    => $sourceUrl,
            'domain'        => $domain,
            'http_status'   => (int) $result['http_status'],
            'fetched_at'    => (string) $result['fetched_at'],
            'image_count'   => count($images),
        ];
    }

    /* ---------- THẤT BẠI — vẫn ghi lại lần thử để thống kê lỗi ---------- */
    $errorCode = (string) ($result['error_code'] ?? WA_ERR_HTTP);
    $errorText = wa_capture_error_text($errorCode, (string) ($result['error_message'] ?? ''));

    $failedSnapshotId = wa_create_snapshot(
        $pageId,
        $version,
        $errorText['title'],
        '',
        '',
        $sourceUrl,
        (string) $result['fetched_at'],
        (int) $result['http_status'],
        $errorCode,
        $errorText['message']
    );

    if ($tagNames !== []) {
        wa_apply_page_tags($pageId, $tagNames);
    }

    return [
        'ok'            => false,
        'error_code'    => $errorCode,
        'error_title'   => $errorText['title'],
        'error_message' => $errorText['message'],
        'page_id'       => $pageId,
        'version'       => $version,
        'snapshot_id'   => $failedSnapshotId,
        'title'         => $errorText['title'],
        'source_url'    => $sourceUrl,
        'domain'        => $domain,
        'http_status'   => (int) $result['http_status'],
        'fetched_at'    => (string) $result['fetched_at'],
        'image_count'   => 0,
    ];
}

/* =============================================================================
    PHIÊN BẢN & GHI CHÚ THEO TỪNG SNAPSHOT — P-READ
    ============================================================================= */

/**
 * Các phiên bản THÀNH CÔNG của một trang, version_no giảm dần.
 *
 * Chỉ lấy fetch_status = 'success': một lần tải lại thất bại vẫn còn trong CSDL
 * để đếm lỗi, nhưng không bao giờ xuất hiện trong bộ chuyển phiên bản.
 *
 * @return array<int, array{id:int, version_no:int, fetched_at:string, fetched_label:string,
 *                          http_status:int, title:string, is_current:bool}>
 */
function wa_page_versions(int $pageId, int $currentSnapshotId = 0): array
{
    $stmt = db()->prepare(
        "SELECT id, version_no, fetched_at, http_status, title
           FROM `snapshots`
          WHERE page_id = ? AND fetch_status = 'success'
       ORDER BY version_no DESC, id DESC"
    );
    $stmt->execute([$pageId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $id        = (int) $row['id'];
        $fetchedAt = (string) $row['fetched_at'];

        $rows[] = [
            'id'            => $id,
            'version_no'    => (int) $row['version_no'],
            'fetched_at'    => $fetchedAt,
            'fetched_label' => wa_datetime($fetchedAt),
            'http_status'   => (int) $row['http_status'],
            'title'         => (string) $row['title'],
            'is_current'    => $id === $currentSnapshotId,
        ];
    }

    return $rows;
}

/**
 * Phiên bản liền trước / liền sau của snapshot đang xem (theo version_no).
 *
 * @return array{prev:?array<string,mixed>, next:?array<string,mixed>}
 */
function wa_page_neighbour_versions(int $pageId, int $currentSnapshotId): array
{
    $versions = wa_page_versions($pageId, $currentSnapshotId);

    $index = null;
    foreach ($versions as $position => $version) {
        if ($version['id'] === $currentSnapshotId) {
            $index = $position;
            break;
        }
    }

    if ($index === null) {
        return ['prev' => null, 'next' => null];
    }

    return [
        // Danh sách đang sắp xếp GIẢM DẦN: phần tử sau là phiên bản cũ hơn.
        'prev' => $versions[$index - 1] ?? null,
        'next' => $versions[$index + 1] ?? null,
    ];
}

/**
 * Ghi chú của MỘT phiên bản snapshot, cùng tên tác giả, cũ nhất trước.
 *
 * `edited` = true khi updated_at muộn hơn created_at, để giao diện đánh dấu.
 *
 * @return array<int, array{id:int, user_id:int, author_name:string, content:string,
 *                          created_at:string, created_label:string, edited:bool, edited_label:string}>
 */
function wa_notes_for_snapshot(int $snapshotId): array
{
    $stmt = db()->prepare(
        'SELECT n.id, n.user_id, n.content, n.created_at, n.updated_at, u.full_name
           FROM `notes` n
           JOIN `users` u ON u.id = n.user_id
          WHERE n.snapshot_id = ?
       ORDER BY n.created_at, n.id'
    );
    $stmt->execute([$snapshotId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $createdAt = (string) $row['created_at'];
        $updatedAt = (string) $row['updated_at'];

        $rows[] = [
            'id'            => (int) $row['id'],
            'user_id'       => (int) $row['user_id'],
            'author_name'   => (string) $row['full_name'],
            'content'       => (string) $row['content'],
            'created_at'    => $createdAt,
            'created_label' => wa_datetime($createdAt),
            'edited'        => $updatedAt > $createdAt,
            'edited_label'  => wa_datetime($updatedAt),
        ];
    }

    return $rows;
}

/**
 * Thêm một ghi chú vào một snapshot.
 *
 * @return array{ok:bool, message:string, note_id?:int}
 */
function wa_note_add(int $snapshotId, int $userId, string $content): array
{
    $content = trim(preg_replace('/[ \t]+/u', ' ', $content) ?? '');

    if ($content === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập nội dung ghi chú.'];
    }

    if (mb_strlen($content, 'UTF-8') > 2000) {
        return ['ok' => false, 'message' => 'Ghi chú không được vượt quá 2000 ký tự.'];
    }

    $stmt = db()->prepare('INSERT INTO `notes` (`snapshot_id`, `user_id`, `content`) VALUES (?, ?, ?)');
    $stmt->execute([$snapshotId, $userId, $content]);

    return [
        'ok'      => true,
        'message' => 'Đã lưu ghi chú cho phiên bản này.',
        'note_id' => (int) db()->lastInsertId(),
    ];
}

/**
 * Sửa một ghi chú — CHỈ tác giả của chính ghi chú đó mới sửa được.
 *
 * Điều kiện `user_id = ?` nằm TRONG CÂU UPDATE (không chỉ kiểm tra ở giao diện):
 * nếu ai đó gửi thẳng POST bằng tay, câu lệnh vẫn không đụng tới dòng dữ liệu
 * của người khác và hàm trả về ok = false.
 *
 * @return array{ok:bool, message:string}
 */
function wa_note_update(int $noteId, int $userId, string $content): array
{
    $content = trim(preg_replace('/[ \t]+/u', ' ', $content) ?? '');

    if ($content === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập nội dung ghi chú.'];
    }

    if (mb_strlen($content, 'UTF-8') > 2000) {
        return ['ok' => false, 'message' => 'Ghi chú không được vượt quá 2000 ký tự.'];
    }

    $stmt = db()->prepare('UPDATE `notes` SET `content` = ? WHERE `id` = ? AND `user_id` = ?');
    $stmt->execute([$content, $noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        return [
            'ok'      => false,
            'message' => 'Bạn chỉ có thể sửa ghi chú do chính mình viết.',
        ];
    }

    return ['ok' => true, 'message' => 'Đã cập nhật ghi chú.'];
}

/**
 * Xoá ghi chú — CHỈ tác giả của chính ghi chú đó mới xoá được.
 *
 * Điều kiện `user_id = ?` nằm trong câu DELETE; quyền xoá của quản trị viên là
 * một đường riêng (wa_note_delete_by_admin) chứ không đi qua đây.
 *
 * @return array{ok:bool, message:string}
 */
function wa_note_delete(int $noteId, int $userId): array
{
    $stmt = db()->prepare('DELETE FROM `notes` WHERE `id` = ? AND `user_id` = ?');
    $stmt->execute([$noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        return [
            'ok'      => false,
            'message' => 'Bạn chỉ có thể xoá ghi chú do chính mình viết.',
        ];
    }

    return ['ok' => true, 'message' => 'Đã xoá ghi chú.'];
}

/**
 * Quản trị viên xoá bất kỳ ghi chú nào (đường xoá riêng, không kiểm tra tác giả).
 *
 * @return array{ok:bool, message:string}
 */
function wa_note_delete_by_admin(int $noteId): array
{
    $stmt = db()->prepare('DELETE FROM `notes` WHERE `id` = ?');
    $stmt->execute([$noteId]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Không tìm thấy ghi chú cần xoá.'];
    }

    return ['ok' => true, 'message' => 'Quản trị viên đã xoá ghi chú.'];
}

/**
 * Các lần lưu THẤT BẠI gần đây của một thư viện (mới nhất trước).
 *
 * Những trang chưa từng có snapshot thành công không xuất hiện trong danh sách
 * bài viết, nên trang library.php hiển thị chúng ở mục riêng cho người có
 * quyền biên tập.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_library_failed_attempts(int $libraryId, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    // $limit là int đã ép kiểu ở trên nên nối thẳng được (không phải dữ liệu người
    // dùng); các điều kiện khác đều dùng tham số có tên.
    $stmt = db()->prepare(
        "SELECT p.id AS page_id, p.source_url, p.domain, p.title,
                s.id AS snapshot_id, s.version_no, s.fetch_status, s.http_status,
                s.fetched_at, s.error_message
           FROM `snapshots` s
           JOIN `saved_pages` p ON p.id = s.page_id
          WHERE p.library_id = ?
            AND s.fetch_status <> 'success'
            AND NOT EXISTS (
                SELECT 1 FROM `snapshots` s2
                 WHERE s2.page_id = p.id AND s2.fetch_status = 'success'
            )
       ORDER BY s.fetched_at DESC, s.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute([$libraryId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $fetchedAt = (string) $row['fetched_at'];

        $rows[] = [
            'page_id'       => (int) $row['page_id'],
            'snapshot_id'   => (int) $row['snapshot_id'],
            'version_no'    => (int) $row['version_no'],
            'source_url'    => (string) $row['source_url'],
            'domain'        => (string) $row['domain'],
            'title'         => (string) $row['title'],
            'fetch_status'  => (string) $row['fetch_status'],
            'status_label'  => wa_fetch_status_label((string) $row['fetch_status']),
            'http_status'   => (int) $row['http_status'],
            'fetched_at'    => $fetchedAt,
            'fetched_label' => wa_datetime($fetchedAt),
            'error_message' => (string) ($row['error_message'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * Nhãn tiếng Việt của một mã trạng thái tải trang (dùng cho snapshot lỗi).
 */
function wa_fetch_status_label(string $status): string
{
    return match ($status) {
        'success'           => 'Thành công',
        'error_404'         => 'Trang gốc trả về 404',
        'error_timeout'     => 'Hết thời gian chờ',
        'error_blocked'     => 'Ngoài danh sách được duyệt',
        'error_no_content'  => 'Không có nội dung đọc được',
        'error_http'        => 'Máy chủ nguồn trả về lỗi',
        'error_invalid_url' => 'URL không hợp lệ',
        default             => 'Lỗi không xác định',
    };
}

/**
 * Người dùng có được tải lại (refetch) trang trong thư viện này không?
 *
 * Chủ sở hữu và biên tập viên được tải lại; người xem thì không.
 * Quản trị viên luôn được phép (đã kiểm tra trước ở nơi gọi).
 */
function wa_can_refetch_library(int $libraryId, ?array $actor = null): bool
{
    return wa_can_write_library($libraryId, $actor);
}

/**
 * Người dùng có quyền XEM thư viện này không (chủ sở hữu, thành viên, hoặc quản trị)?
 */
function wa_can_read_library(int $libraryId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    if ($actor['role'] === 'admin') {
        return true;
    }

    // Ba vị trí so sánh dùng BA tên tham số khác nhau (prepares thật của PDO không
    // cho phép lặp lại một tên tham số trong cùng câu lệnh — SQLSTATE[HY093]).
    $stmt = db()->prepare(
        'SELECT 1
           FROM `libraries` l
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
          WHERE l.id = :library_id
            AND (l.owner_id = :owner_id OR lm.user_id IS NOT NULL)
          LIMIT 1'
    );
    $stmt->execute([
        ':actor'      => (int) $actor['id'],
        ':owner_id'   => (int) $actor['id'],
        ':library_id' => $libraryId,
    ]);

    return $stmt->fetchColumn() !== false;
}

/* =============================================================================
    NGỮ CẢNH ĐỌC MỘT SNAPSHOT — P-READ
    -----------------------------------------------------------------------------
    reader.php cần biết trước khi vẽ gì: ai đang đọc, họ có quyền xem thư viện
    không, họ có quyền tải lại không, và các phiên bản + ghi chú của đúng snapshot
    đang mở. Hàm này gộp phần giải quyết đó lại một chỗ.
    ============================================================================= */

/**
 * Giải quyết ngữ cảnh đọc của một snapshot id.
 *
 * @return array{status:string, article?:array<string,mixed>, library_id?:int,
 *               page_id?:int, role?:string, can_refetch?:bool, versions?:array,
 *               prev?:?array, next?:?array, notes?:array}
 *
 * status:
 *   - 'ok'       : đọc được.
 *   - 'denied'   : đã đăng nhập nhưng không thuộc thư viện của snapshot -> hiện 403.
 *   - 'not_found': không tồn tại, không phải success, hoặc không có quyền xem
 *                  -> hiện 404 (đúng quy tắc: bản ghi lỗi không mở được).
 */
function wa_reader_context(int $snapshotId): array
{
    $actor = wa_actor();

    // --- Bước 1: chỉ lấy snapshot có fetch_status = 'success' (bản ghi lỗi -> 404).
    $stmt = db()->prepare(
        'SELECT s.id, s.page_id, s.fetch_status, p.library_id
           FROM `snapshots` s
           JOIN `saved_pages` p ON p.id = s.page_id
          WHERE s.id = ?
          LIMIT 1'
    );
    $stmt->execute([$snapshotId]);
    $row = $stmt->fetch();

    if ($row === false || (string) $row['fetch_status'] !== 'success') {
        return ['status' => 'not_found'];
    }

    // Khách: require_login() đã chuyển hướng, nhưng để phòng thủ vẫn trả not_found.
    if ($actor === null) {
        return ['status' => 'not_found'];
    }

    $pageId    = (int) $row['page_id'];
    $libraryId = (int) $row['library_id'];

    // --- Bước 2: nạp đầy đủ bài + kiểm tra truy cập ở tầng SQL (wa_find_snapshot).
    $article = wa_find_snapshot($snapshotId, $actor);

    if ($article === null) {
        // Không nạp được dù có quyền (chặn bởi access sql) hoặc dữ liệu lệch.
        return wa_can_read_library($libraryId, $actor)
            ? ['status' => 'not_found']
            : ['status' => 'denied'];
    }

    // --- Bước 3: vai trò người dùng trong thư viện + quyền tải lại.
    $role = wa_library_role($libraryId, $actor);

    $canRefetch = $actor['role'] === 'admin' || in_array($role, ['owner', 'editor'], true);

    // --- Bước 4: phiên bản + ghi chú của ĐÚNG snapshot đang mở.
    $versions = wa_page_versions($pageId, $snapshotId);
    $neighbours = wa_page_neighbour_versions($pageId, $snapshotId);

    return [
        'status'       => 'ok',
        'article'      => $article,
        'library_id'   => $libraryId,
        'page_id'      => $pageId,
        'role'         => $role,
        'can_refetch'  => $canRefetch,
        'versions'     => $versions,
        'prev'         => $neighbours['prev'],
        'next'         => $neighbours['next'],
        'notes'        => wa_notes_for_snapshot($snapshotId),
    ];
}

/**
 * Vai trò của người dùng trong một thư viện: owner | editor | viewer | null.
 *
 * Chủ sở hữu (libraries.owner_id) luôn là 'owner' kể cả khi không có dòng
 * trong library_members.
 */
function wa_library_role(int $libraryId, ?array $actor = null): ?string
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT l.owner_id, lm.role
           FROM `libraries` l
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
          WHERE l.id = :library_id
          LIMIT 1'
    );
    $stmt->execute([
        ':actor'      => (int) $actor['id'],
        ':library_id' => $libraryId,
    ]);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    if ((int) $row['owner_id'] === (int) $actor['id']) {
        return 'owner';
    }

    return $row['role'] !== null ? (string) $row['role'] : null;
}
