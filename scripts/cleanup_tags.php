<?php
/**
 * scripts/cleanup_tags.php
 * -----------------------------------------------------------------------------
 * DỌN NHÃN CŨ VI PHẠM QUY TẮC NHÃN (29/10) — chạy được nhiều lần.
 *
 * Quy tắc nhãn: chỉ gồm chữ (kể cả tiếng Việt có dấu), số và dấu gạch ngang.
 * Khoảng trắng và ký tự đặc biệt (. , ? $ % @ & ...) bị lược bỏ, nên nhãn cũ
 * "#di tich" thành "#ditich". Nhãn chỉ còn ký tự đặc biệt (ví dụ "...") thì
 * không còn gì nên bị xoá cùng các liên kết của nó.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/cleanup_tags.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'). Nó CHỈ đụng tới hai bảng `tags` và `page_tags`:
 * đổi tên nhãn, gộp hai nhãn về cùng một tên chuẩn (chuyển liên kết trang
 * sang nhãn chuẩn rồi xoá nhãn cũ), hoặc xoá nhãn rỗng. Không đụng tới trang,
 * snapshot, ảnh hay dữ liệu nào khác. Mọi thay đổi nằm trong MỘT transaction:
 * lỗi giữa chừng thì rollback toàn bộ. Kịch bản in từng thay đổi ra màn hình
 * và chạy lại lần hai sẽ báo "không còn nhãn nào cần dọn".
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/cleanup_tags.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

/** Tên nhãn chuẩn theo đúng quy tắc của wa_normalize_tag_names(). */
function clean_tag_name(string $name): string
{
    $body = preg_replace('/[^\p{L}\p{N}-]+/u', '', ltrim($name, '#')) ?? '';
    if ($body === '') {
        return '';
    }
    $canonical = '#' . $body;
    if (mb_strlen($canonical) > 64) {
        $canonical = mb_substr($canonical, 0, 64, 'UTF-8');
    }
    return $canonical;
}

try {
    $rows = db()->query('SELECT id, name FROM tags ORDER BY id')->fetchAll();

    $renamed = 0;
    $merged = 0;
    $removed = 0;
    $clean = 0;

    db()->beginTransaction();

    foreach ($rows as $row) {
        $oldId = (int) $row['id'];
        $oldName = (string) $row['name'];
        $canonical = clean_tag_name($oldName);

        // Nhãn đã chuẩn thì giữ nguyên.
        if ($canonical !== '' && $canonical === $oldName) {
            $clean++;
            continue;
        }

        // Nhãn chỉ còn ký tự đặc biệt: xoá nhãn cùng các liên kết của nó.
        if ($canonical === '') {
            $delLinks = db()->prepare('DELETE FROM page_tags WHERE tag_id = ?');
            $delLinks->execute([$oldId]);
            $delTag = db()->prepare('DELETE FROM tags WHERE id = ?');
            $delTag->execute([$oldId]);
            out("XOÁ: '{$oldName}' (id {$oldId}) — không còn ký tự hợp lệ.");
            $removed++;
            continue;
        }

        // Tên chuẩn đã có nhãn khác giữ (so khớp theo collation ci của MySQL,
        // nên cả khác hoa/thường hay dấu cũng bắt được): gộp về nhãn đó thay
        // vì đổi tên, nếu không sẽ vi phạm UNIQUE uq_tags_name.
        $existing = db()->prepare('SELECT id FROM tags WHERE name = ? AND id <> ? LIMIT 1');
        $existing->execute([$canonical, $oldId]);
        $canonicalId = $existing->fetchColumn();

        if ($canonicalId !== false) {
            $canonicalId = (int) $canonicalId;
            // Trang đã gắn cả hai nhãn thì bỏ liên kết trùng trước.
            $delDup = db()->prepare(
                'DELETE p1 FROM page_tags p1 ' .
                'INNER JOIN page_tags p2 ON p1.page_id = p2.page_id AND p2.tag_id = ? ' .
                'WHERE p1.tag_id = ?'
            );
            $delDup->execute([$canonicalId, $oldId]);
            // Chuyển các liên kết còn lại sang nhãn chuẩn rồi xoá nhãn cũ.
            $repoint = db()->prepare('UPDATE page_tags SET tag_id = ? WHERE tag_id = ?');
            $repoint->execute([$canonicalId, $oldId]);
            $delTag = db()->prepare('DELETE FROM tags WHERE id = ?');
            $delTag->execute([$oldId]);
            out("GỘP: '{$oldName}' (id {$oldId}) -> '{$canonical}' (id {$canonicalId}).");
            $merged++;
            continue;
        }

        $rename = db()->prepare('UPDATE tags SET name = ? WHERE id = ?');
        $rename->execute([$canonical, $oldId]);
        out("ĐỔI TÊN: '{$oldName}' (id {$oldId}) -> '{$canonical}'.");
        $renamed++;
    }

    db()->commit();

    out("XONG: đổi tên {$renamed}, gộp {$merged}, xoá {$removed}, đã chuẩn {$clean}.");
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    out('LỖI: không dọn được nhãn (đã rollback): ' . $e->getMessage());
    exit(1);
}
