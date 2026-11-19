<?php
/**
 * src/v3_relations.php
 * -----------------------------------------------------------------------------
 * LỚP NGHIỆP VỤ ĐỢT 4 — LIÊN KẾT CÁC BÀI VIẾT + SƠ ĐỒ QUAN HỆ (F11).
 *
 * Bốn loại quan hệ:
 *   related     — Liên quan
 *   references  — Tham khảo
 *   supplements — Bổ sung
 *   contrasts   — Đối chiếu
 *
 * Nguyên tắc phân quyền:
 *   - Người dùng chỉ tạo/sửa/xoá quan hệ khi có quyền GHI trên thư viện chứa
 *     bài nguồn (chủ sở hữu / biên tập viên / quản trị viên).
 *   - Bài đích phải nằm trong một thư viện mà người dùng CÓ QUYỀN ĐỌC.
 *   - Khi hiển thị, quan hệ chỉ xuất hiện khi người đọc có quyền đọc CẢ hai bên.
 *   - Bài bị xoá -> quan hệ tự biến mất (ON DELETE CASCADE ở tầng CSDL) và
 *     vòng lặp hiển thị cũng bỏ qua dòng hỏng.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Danh sách loại quan hệ hợp lệ. */
const WA_RELATION_TYPES = ['related', 'references', 'supplements', 'contrasts'];

/**
 * Nhãn tiếng Việt + mô tả của từng loại quan hệ.
 *
 * @return array<string, array{label:string, icon:string, desc:string}>
 */
function wa_relation_type_meta(): array
{
    return [
        'related' => [
            'label' => 'Liên quan',
            'icon'  => 'link',
            'desc'  => 'Hai bài nói về cùng chủ đề, có thể đọc cạnh nhau.',
        ],
        'references' => [
            'label' => 'Tham khảo',
            'icon'  => 'menu_book',
            'desc'  => 'Bài nguồn trích dẫn hoặc dựa trên bài đích.',
        ],
        'supplements' => [
            'label' => 'Bổ sung',
            'icon'  => 'add_circle',
            'desc'  => 'Bài đích bổ sung thêm thông tin cho bài nguồn.',
        ],
        'contrasts' => [
            'label' => 'Đối chiếu',
            'icon'  => 'compare_arrows',
            'desc'  => 'Hai bài có những điểm khác biệt đáng so sánh.',
        ],
    ];
}

/**
 * Nhãn của một loại quan hệ (fallback về "Liên quan" nếu dữ liệu lạ).
 */
function wa_relation_type_label(string $type): string
{
    $meta = wa_relation_type_meta();

    return $meta[$type]['label'] ?? 'Liên quan';
}

/**
 * Đoạn SQL lọc "người dùng đọc được thư viện" — bản sao có THAM SỐ RIÊNG theo
 * tiền tố, vì PDO đang dùng prepares THẬT nên không cho phép một tên tham số
 * xuất hiện hai lần trong cùng câu (SQLSTATE HY093).
 *
 * @param string $alias Tên bí danh của bảng `libraries` trong câu đó.
 *
 * @return array{0:string,1:array<string,mixed>}
 */
function wa_relation_access_sql(?array $actor, string $alias, string $prefix): array
{
    if ($actor !== null && $actor['role'] === 'admin') {
        return ['1 = 1', []];
    }

    if ($actor === null) {
        return ['1 = 0', []];
    }

    $actorId = (int) $actor['id'];

    return [
        '(' . $alias . '.owner_id = :' . $prefix . '_owner OR EXISTS (
              SELECT 1 FROM `library_members` lm
              WHERE lm.library_id = ' . $alias . '.id AND lm.user_id = :' . $prefix . '_member
          ))',
        [
            ':' . $prefix . '_owner'  => $actorId,
            ':' . $prefix . '_member' => $actorId,
        ],
    ];
}

/**
 * Người dùng có quyền GHI trên bài viết này không (tức được tạo quan hệ)?
 */
function wa_relation_can_write(int $pageId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT sp.library_id FROM `saved_pages` sp WHERE sp.id = ? LIMIT 1'
        );
        $stmt->execute([$pageId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] relation can_write failed: ' . $e->getMessage());

        return false;
    }

    if ($row === false) {
        return false;
    }

    if ($actor['role'] === 'admin') {
        return true;
    }

    return wa_can_write_library((int) $row['library_id'], $actor);
}

/**
 * Tìm bài viết trong các thư viện người dùng được đọc (dùng cho hộp chọn).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_relation_search_pages(string $query, ?array $actor = null, int $limit = 20): array
{
    $actor = $actor ?? wa_actor();
    $query = trim($query);

    if ($query === '' || $actor === null) {
        return [];
    }

    [$accessSql, $accessParams] = wa_library_access_sql($actor);

    $like = '%' . $query . '%';

    try {
        $stmt = db()->prepare(
            'SELECT sp.id, sp.title, sp.source_url, sp.library_id, l.name AS library_name,
                    (SELECT MAX(s.id) FROM `snapshots` s
                      WHERE s.page_id = sp.id AND s.fetch_status = \'success\') AS snapshot_id
               FROM `saved_pages` sp
               JOIN `libraries` l ON l.id = sp.library_id
              WHERE ' . $accessSql . '
                AND (sp.title LIKE :like_title OR sp.source_url LIKE :like_url)
              ORDER BY sp.updated_at DESC
              LIMIT ' . max(1, min(50, $limit))
        );

        $params = $accessParams;
        $params[':like_title'] = $like;
        $params[':like_url']  = $like;

        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[WebArchive] relation search failed: ' . $e->getMessage());

        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'page_id'       => (int) $row['id'],
            'title'         => (string) $row['title'],
            'source_url'    => (string) $row['source_url'],
            'library_id'    => (int) $row['library_id'],
            'library_name'  => (string) $row['library_name'],
            'snapshot_id'   => (int) ($row['snapshot_id'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Tạo quan hệ giữa hai bài viết.
 *
 * @return array{ok:bool, message:string, id:int}
 */
function wa_relation_create(
    int $sourcePageId,
    int $targetPageId,
    string $type,
    string $note = '',
    ?array $actor = null
): array {
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn cần đăng nhập.', 'id' => 0];
    }

    if ($sourcePageId <= 0 || $targetPageId <= 0) {
        return ['ok' => false, 'message' => 'Thiếu thông tin bài viết.', 'id' => 0];
    }

    if ($sourcePageId === $targetPageId) {
        return ['ok' => false, 'message' => 'Không thể liên kết một bài viết với chính nó.', 'id' => 0];
    }

    if (!in_array($type, WA_RELATION_TYPES, true)) {
        return ['ok' => false, 'message' => 'Loại quan hệ không hợp lệ.', 'id' => 0];
    }

    if (!wa_relation_can_write($sourcePageId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền tạo quan hệ trên bài viết này.', 'id' => 0];
    }

    // Bài đích phải nằm trong thư viện người dùng đọc được.
    $target = wa_relation_page_info($targetPageId, $actor);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Bài viết đích không tồn tại hoặc bạn không có quyền đọc.', 'id' => 0];
    }

    $note = trim($note);

    if (mb_strlen($note, 'UTF-8') > 400) {
        $note = mb_substr($note, 0, 400, 'UTF-8');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `page_relations`
                (`source_page_id`, `target_page_id`, `relation_type`, `note`, `created_by`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `note` = VALUES(`note`),
                `updated_at` = NOW()'
        );
        $stmt->execute([$sourcePageId, $targetPageId, $type, $note !== '' ? $note : null, (int) $actor['id']]);

        $id = (int) db()->lastInsertId();

        if ($id <= 0) {
            // ON DUPLICATE KEY UPDATE trả lastInsertId = 0 -> lấy id của dòng đã có.
            $find = db()->prepare(
                'SELECT id FROM `page_relations`
                  WHERE source_page_id = ? AND target_page_id = ? AND relation_type = ?
                  LIMIT 1'
            );
            $find->execute([$sourcePageId, $targetPageId, $type]);
            $row  = $find->fetch();
            $id   = $row === false ? 0 : (int) $row['id'];
        }

        return [
            'ok'      => true,
            'message' => 'Đã liên kết bài viết (' . wa_relation_type_label($type) . ').',
            'id'      => $id,
        ];
    } catch (Throwable $e) {
        error_log('[WebArchive] relation create failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không lưu được quan hệ.', 'id' => 0];
    }
}

/**
 * Sửa loại / ghi chú của một quan hệ.
 */
function wa_relation_update(int $relationId, string $type, string $note, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn cần đăng nhập.'];
    }

    if ($relationId <= 0) {
        return ['ok' => false, 'message' => 'Không xác định được quan hệ.'];
    }

    if (!in_array($type, WA_RELATION_TYPES, true)) {
        return ['ok' => false, 'message' => 'Loại quan hệ không hợp lệ.'];
    }

    $info = wa_relation_get($relationId);

    if ($info === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy quan hệ này.'];
    }

    if (!wa_relation_can_write((int) $info['source_page_id'], $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền sửa quan hệ này.'];
    }

    $note = trim($note);

    if (mb_strlen($note, 'UTF-8') > 400) {
        $note = mb_substr($note, 0, 400, 'UTF-8');
    }

    try {
        $stmt = db()->prepare(
            'UPDATE `page_relations`
                SET `relation_type` = ?, `note` = ?, `updated_at` = NOW()
              WHERE id = ?'
        );
        $stmt->execute([$type, $note !== '' ? $note : null, $relationId]);

        return ['ok' => true, 'message' => 'Đã cập nhật quan hệ.'];
    } catch (Throwable $e) {
        error_log('[WebArchive] relation update failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không cập nhật được quan hệ.'];
    }
}

/**
 * Xoá một quan hệ.
 */
function wa_relation_delete(int $relationId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn cần đăng nhập.'];
    }

    if ($relationId <= 0) {
        return ['ok' => false, 'message' => 'Không xác định được quan hệ.'];
    }

    $info = wa_relation_get($relationId);

    if ($info === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy quan hệ này.'];
    }

    if (!wa_relation_can_write((int) $info['source_page_id'], $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền xoá quan hệ này.'];
    }

    try {
        $stmt = db()->prepare('DELETE FROM `page_relations` WHERE id = ?');
        $stmt->execute([$relationId]);

        return ['ok' => true, 'message' => 'Đã xoá quan hệ.'];
    } catch (Throwable $e) {
        error_log('[WebArchive] relation delete failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không xoá được quan hệ.'];
    }
}

/**
 * Lấy thông tin thô của một quan hệ.
 *
 * @return array<string,mixed>|null
 */
function wa_relation_get(int $relationId): ?array
{
    if ($relationId <= 0) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM `page_relations` WHERE id = ? LIMIT 1');
        $stmt->execute([$relationId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    } catch (Throwable $e) {
        error_log('[WebArchive] relation get failed: ' . $e->getMessage());

        return null;
    }
}

/**
 * Thông tin một bài viết (kèm snapshot mới nhất thành công) nếu người dùng đọc được.
 *
 * @return array<string,mixed>|null
 */
function wa_relation_page_info(int $pageId, ?array $actor = null): ?array
{
    if ($pageId <= 0) {
        return null;
    }

    $actor = $actor ?? wa_actor();

    [$accessSql, $accessParams] = wa_library_access_sql($actor);

    try {
        $stmt = db()->prepare(
            'SELECT sp.id, sp.title, sp.source_url, sp.library_id, l.name AS library_name,
                    (SELECT MAX(s.id) FROM `snapshots` s
                      WHERE s.page_id = sp.id AND s.fetch_status = \'success\') AS snapshot_id
               FROM `saved_pages` sp
               JOIN `libraries` l ON l.id = sp.library_id
              WHERE sp.id = :page_id AND ' . $accessSql . '
              LIMIT 1'
        );
        $params = $accessParams;
        $params[':page_id'] = $pageId;

        $stmt->execute($params);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[WebArchive] relation page info failed: ' . $e->getMessage());

        return null;
    }

    if ($row === false) {
        return null;
    }

    return [
        'page_id'      => (int) $row['id'],
        'title'        => (string) $row['title'],
        'source_url'   => (string) $row['source_url'],
        'library_id'   => (int) $row['library_id'],
        'library_name' => (string) $row['library_name'],
        'snapshot_id'  => (int) ($row['snapshot_id'] ?? 0),
    ];
}

/**
 * Danh sách quan hệ của một bài viết — CHỈ gồm những quan hệ mà người đọc
 * có quyền đọc CẢ bài nguồn LẪN bài đích.
 *
 * @return array<int, array<string,mixed>>
 */
function wa_relation_list_for_page(int $pageId, ?array $actor = null): array
{
    if ($pageId <= 0) {
        return [];
    }

    $actor = $actor ?? wa_actor();

    // Bộ lọc quyền đọc áp cho THƯ VIỆN CHỨA BÀI NGUỒN (alias `tsl`).
    [$accessSql, $accessParams] = wa_relation_access_sql($actor, 'tsl', 'rel_src');

    try {
        $stmt = db()->prepare(
            'SELECT r.id, r.source_page_id, r.target_page_id, r.relation_type, r.note,
                    r.created_at, r.updated_at,
                    ts.title AS target_title, ts.source_url AS target_url,
                    tl.name AS target_library, tl.slug AS target_slug,
                    (SELECT MAX(s.id) FROM `snapshots` s
                      WHERE s.page_id = r.target_page_id AND s.fetch_status = \'success\') AS target_snapshot_id
               FROM `page_relations` r
               JOIN `saved_pages` tp ON tp.id = r.target_page_id
               JOIN `libraries` tl    ON tl.id = tp.library_id
               JOIN `saved_pages` ts  ON ts.id = r.source_page_id
               JOIN `libraries` tsl   ON tsl.id = ts.library_id
              WHERE r.source_page_id = :sp
                AND ' . $accessSql . '
                AND EXISTS (
                    SELECT 1 FROM `saved_pages` tsp
                      JOIN `libraries` tsl2 ON tsl2.id = tsp.library_id
                     WHERE tsp.id = r.target_page_id AND ' . wa_relation_access_sql($actor, 'tsl2', 'rel_dst')[0] . '
                )
              ORDER BY r.id DESC'
        );

        $params = $accessParams;
        $params[':sp'] = $pageId;
        $params = array_merge($params, wa_relation_access_sql($actor, 'tsl2', 'rel_dst')[1]);

        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[WebArchive] relation list failed: ' . $e->getMessage());

        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $type = (string) $row['relation_type'];

        $out[] = [
            'id'            => (int) $row['id'],
            'source_page_id' => (int) $row['source_page_id'],
            'target_page_id' => (int) $row['target_page_id'],
            'relation_type' => $type,
            'type_label'    => wa_relation_type_label($type),
            'note'          => (string) ($row['note'] ?? ''),
            'target_title'  => (string) $row['target_title'],
            'target_url'    => (string) $row['target_url'],
            'target_library' => (string) $row['target_library'],
            'target_slug'   => (string) $row['target_slug'],
            'target_snapshot_id' => (int) ($row['target_snapshot_id'] ?? 0),
            'created_label' => wa_datetime((string) $row['created_at']),
        ];
    }

    return $out;
}

/**
 * Dữ liệu cho SƠ ĐỒ QUAN HỆ (SVG tự vẽ bằng JavaScript thuần).
 *
 * Trả về các nút + các cạnh. Mỗi nút là một bài viết (kèm snapshot mới nhất
 * để bấm là mở được). Nút gốc là bài đang xem.
 *
 * @return array{nodes:array<int,array<string,mixed>>, edges:array<int,array<string,mixed>>}
 */
function wa_relation_graph_data(int $pageId, ?array $actor = null): array
{
    $nodes = [];
    $edges = [];

    $self = wa_relation_page_info($pageId, $actor);

    if ($self === null) {
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    $nodes[(int) $self['page_id']] = [
        'id'       => (int) $self['page_id'],
        'title'    => (string) $self['title'],
        'snapshot_id' => (int) $self['snapshot_id'],
        'library'  => (string) $self['library_name'],
        'is_self'  => true,
    ];

    // Quan hệ ĐI ra (bài này -> bài kia) và quan hệ ĐI VÀO (bài kia -> bài này).
    foreach (wa_relation_list_for_page($pageId, $actor) as $rel) {
        $targetId = (int) $rel['target_page_id'];

        if (!isset($nodes[$targetId])) {
            $nodes[$targetId] = [
                'id'       => $targetId,
                'title'    => (string) $rel['target_title'],
                'snapshot_id' => (int) $rel['target_snapshot_id'],
                'library'  => (string) $rel['target_library'],
                'is_self'  => false,
            ];
        }

        $edges[] = [
            'from'  => $pageId,
            'to'    => $targetId,
            'type'  => (string) $rel['relation_type'],
            'label' => (string) $rel['type_label'],
            'note'  => (string) $rel['note'],
            'direction' => 'out',
        ];
    }

    $incoming = wa_relation_list_incoming($pageId, $actor);

    foreach ($incoming as $rel) {
        $sourceId = (int) $rel['source_page_id'];

        if (!isset($nodes[$sourceId])) {
            $nodes[$sourceId] = [
                'id'       => $sourceId,
                'title'    => (string) $rel['source_title'],
                'snapshot_id' => (int) $rel['source_snapshot_id'],
                'library'  => (string) $rel['source_library'],
                'is_self'  => false,
            ];
        }

        $edges[] = [
            'from'  => $sourceId,
            'to'    => $pageId,
            'type'  => (string) $rel['relation_type'],
            'label' => (string) $rel['type_label'],
            'note'  => (string) $rel['note'],
            'direction' => 'in',
        ];
    }

    return [
        'nodes' => array_values($nodes),
        'edges' => $edges,
    ];
}

/**
 * Các quan hệ ĐI VÀO một bài viết (bài khác trỏ tới bài này).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_relation_list_incoming(int $pageId, ?array $actor = null): array
{
    if ($pageId <= 0) {
        return [];
    }

    $actor = $actor ?? wa_actor();
    [$accessSql, $accessParams] = wa_relation_access_sql($actor, 'sl', 'rel_inc');

    try {
        $stmt = db()->prepare(
            'SELECT r.id, r.source_page_id, r.target_page_id, r.relation_type, r.note,
                    ss.title AS source_title, ss.source_url AS source_url,
                    sl.name AS source_library,
                    (SELECT MAX(s.id) FROM `snapshots` s
                      WHERE s.page_id = r.source_page_id AND s.fetch_status = \'success\') AS source_snapshot_id
               FROM `page_relations` r
               JOIN `saved_pages` ss ON ss.id = r.source_page_id
               JOIN `libraries` sl    ON sl.id = ss.library_id
              WHERE r.target_page_id = :target_id
                AND ' . $accessSql . '
              ORDER BY r.id DESC'
        );        $params = $accessParams;
        $params[':target_id'] = $pageId;

        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[WebArchive] relation incoming failed: ' . $e->getMessage());

        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $type = (string) $row['relation_type'];

        $out[] = [
            'id'             => (int) $row['id'],
            'source_page_id' => (int) $row['source_page_id'],
            'target_page_id' => (int) $row['target_page_id'],
            'relation_type'  => $type,
            'type_label'     => wa_relation_type_label($type),
            'note'           => (string) ($row['note'] ?? ''),
            'source_title'   => (string) $row['source_title'],
            'source_url'     => (string) $row['source_url'],
            'source_library' => (string) $row['source_library'],
            'source_snapshot_id' => (int) ($row['source_snapshot_id'] ?? 0),
        ];
    }

    return $out;
}
