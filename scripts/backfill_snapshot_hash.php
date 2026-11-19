<?php
/**
 * scripts/backfill_snapshot_hash.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG `snapshots.content_hash` CHO CÁC SNAPSHOT CŨ (chạy được nhiều lần).
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     # 1) Xem trước số lượng cần xử lý (KHÔNG ghi gì):
 *     php scripts/backfill_snapshot_hash.php --dry-run
 *
 *     # 2) Chạy thật (xử lý theo lô, có thể dừng giữa chừng rồi chạy lại):
 *     php scripts/backfill_snapshot_hash.php
 *
 *     # 3) Giới hạn số dòng mỗi lần chạy (để giới hạn thời gian/thời gian chờ):
 *     php scripts/backfill_snapshot_hash.php --limit=500
 *
 * Dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php (tức là database mà
 * config đang trỏ tới — 3307 khi thử nghiệm, 3306 khi triển khai).
 * KHÔNG chạy `database/schema.sql`. KHÔNG đụng database nào khác.
 *
 * -----------------------------------------------------------------------------
 * NGUYÊN TẮC (KHÔNG ĐƯỢC VI PHẠM)
 * -----------------------------------------------------------------------------
 *   1. Dùng ĐÚNG hàm `wa_snapshot_content_hash()` sẵn có — không tự tính lại
 *      theo cách khác, để hash khớp với những gì F12 (phát hiện trùng lặp)
 *      dùng khi so sánh.
 *   2. CHỈ ghi cho dòng có `content_hash` NULL hoặc rỗng.
 *   3. KHÔNG ghi đè hash đã tồn tại (điều kiện `AND (content_hash IS NULL OR
 *      content_hash = '')` nằm ngay trong câu UPDATE -> an toàn kể cả khi hai
 *      tiến trình chạy song song).
 *   4. KHÔNG sửa `content_html` / `content_text` — chỉ đọc để tính hash.
 *   5. Xử lý theo lô nhỏ, có thể TIẾP TỤC khi bị gián đoạn: mỗi lần chạy
 *      bắt đầu từ các dòng còn thiếu hash, nên chạy lại là tiếp tục.
 *
 * -----------------------------------------------------------------------------
 * GIỚI HẠN CẦN BIẾT (baseline)
 * -----------------------------------------------------------------------------
 *   Hash mới được tính từ DỮ LIỆU HIỆN CÓ trong database. Điều đó chỉ tạo
 *   một BASELINE từ thời điểm backfill: nó KHÔNG chứng minh được rằng dữ liệu
 *   chưa từng bị sửa trước đó. Nếu `content_html` của một snapshot cũ đã bị
 *   thay đổi trước khi backfill, hash mới sẽ phản ánh nội dung HIỆN TẠI chứ
 *   không phải nội dung gốc lúc tải về.
 *   Vì vậy: KHÔNG dùng kết quả backfill làm bằng chứng toàn vẹn dữ liệu, và
 *   KHÔNG dùng nó để kết luận "nội dung chưa từng bị can thiệp".
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/backfill_snapshot_hash.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

/** Số dòng xử lý trong MỘT lô (nhỏ để không giữ transaction quá lâu). */
const WA_BACKFILL_BATCH = 50;

/**
 * Đọc tham số dòng lệnh.
 *
 * @param array<int, string> $argv
 *
 * @return array{dry_run:bool, limit:int|null, batch:int}
 */
function backfill_parse_args(array $argv): array
{
    $dryRun = false;
    $limit  = null;
    $batch  = WA_BACKFILL_BATCH;

    foreach (array_slice($argv, 1) as $arg) {
        $arg = (string) $arg;

        if ($arg === '--dry-run' || $arg === '--dryrun') {
            $dryRun = true;

            continue;
        }

        if (str_starts_with($arg, '--limit=')) {
            $value = (int) substr($arg, 8);

            if ($value > 0) {
                $limit = $value;
            }

            continue;
        }

        if (str_starts_with($arg, '--batch=')) {
            $value = (int) substr($arg, 8);

            if ($value > 0) {
                $batch = min(500, max(1, $value));
            }

            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            echo "Usage:\n";
            echo "  php scripts/backfill_snapshot_hash.php [--dry-run] [--limit=N] [--batch=N]\n\n";
            echo "  --dry-run   Chi dem so ban can xu ly, KHONG ghi gi vao database.\n";
            echo "  --limit=N   Chi xu ly toi da N ban.\n";
            echo "  --batch=N   So ban xu ly trong MOT lo (mac dinh " . WA_BACKFILL_BATCH . ").\n";

            exit(0);
        }

        fwrite(STDERR, 'Tham so khong ro: ' . $arg . PHP_EOL);
        exit(1);
    }

    return ['dry_run' => $dryRun, 'limit' => $limit, 'batch' => $batch];
}

/** @param string $message */
function backfill_out(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Bảng `snapshots` đã có cột `content_hash` chưa?
 */
function backfill_has_hash_column(PDO $pdo): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'snapshots'
            AND COLUMN_NAME = 'content_hash'"
    )->fetchColumn() > 0;
}

/**
 * Đếm số snapshot còn THIẾU hash.
 */
function backfill_count_missing(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM `snapshots`
          WHERE `content_hash` IS NULL OR `content_hash` = ''"
    )->fetchColumn();
}

/**
 * Lấy MỘT lô snapshot còn thiếu hash (kèm `content_html`).
 *
 * Dùng CON TRỎ (keyset pagination) theo `id`: chỉ lấy các dòng có
 * `id > $afterId`. Nhờ vậy vòng lặp luôn tiến về phía trước, KỂ CẢ khi có
 * dòng không ghi được hash (ví dụ `content_html` rỗng) — nếu chỉ lọc bằng
 * `content_hash IS NULL` thì các dòng đó sẽ bị lấy lại vô hạn.
 *
 * @return array<int, array{id:int, content_html:string}>
 */
function backfill_fetch_batch(PDO $pdo, int $batch, int $afterId = 0): array
{
    $stmt = $pdo->query(
        "SELECT `id`, `content_html`
           FROM `snapshots`
          WHERE `id` > " . (int) $afterId . "
            AND (`content_hash` IS NULL OR `content_hash` = '')
          ORDER BY `id` ASC
          LIMIT " . (int) $batch
    );

    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'id'           => (int) $row['id'],
            'content_html' => (string) ($row['content_html'] ?? ''),
        ];
    }

    return $rows;
}

try {
    $options = backfill_parse_args($argv);
    $pdo     = db();

    backfill_out('=== BACKFILL SHA-256 CHO SNAPSHOT CU ===');
    backfill_out('Database : ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn()
        . '  (server ' . (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ')');
    backfill_out('Che do   : ' . ($options['dry_run'] ? 'DRY-RUN (khong ghi gi)' : 'GHI THAT'));
    backfill_out('');

    if (!backfill_has_hash_column($pdo)) {
        backfill_out('LOI: bang `snapshots` chua co cot `content_hash`.');
        backfill_out('Hay chay: php scripts/migrate_v3.php');
        exit(1);
    }

    $missing = backfill_count_missing($pdo);

    backfill_out('So ban THIEU hash : ' . $missing);
    backfill_out('So ban DA CO hash  : ' . (int) $pdo->query(
        "SELECT COUNT(*) FROM `snapshots`
          WHERE `content_hash` IS NOT NULL AND `content_hash` <> ''"
    )->fetchColumn());

    if ($missing === 0) {
        backfill_out('');
        backfill_out('KHONG co ban nao thieu hash. Het viec.');
        exit(0);
    }

    if ($options['dry_run']) {
        backfill_out('');
        backfill_out('DRY-RUN: khong ghi gi vao database.');
        backfill_out('Neu muon chay that: php scripts/backfill_snapshot_hash.php');

        // Xem trước vài dòng sắp được xử lý (chi doc).
        $sample = backfill_fetch_batch($pdo, min(10, $options['batch']));

        if ($sample !== []) {
            backfill_out('');
            backfill_out('Vi du cac ban se duoc xu ly (theo thu tu id):');

            foreach ($sample as $row) {
                backfill_out('  - id=' . $row['id'] . '  content_html=' . strlen($row['content_html']) . ' byte');
            }
        }

        exit(0);
    }

    /* -------------------------------------------------------------------------
     * XỬ LÝ THẬT — theo lô, mỗi lô một transaction riêng.
     * Nhờ vậy bị gián đoạn (Ctrl+C, hết thời gian, mất kết nối) thì chạy lại
     * là TIẾP TỤC từ chỗ dừng, không mất dữ liệu.
     * ---------------------------------------------------------------------- */
    $processed = 0;
    $written   = 0;
    $skipped   = 0;
    $startId   = 0;

    if ($options['limit'] !== null && $options['limit'] <= 0) {
        $options['limit'] = null;
    }

    backfill_out('Bat dau xu ly (lo ' . $options['batch'] . ' ban/lan)...');
    backfill_out('');

    while (true) {
        if ($options['limit'] !== null && $processed >= $options['limit']) {
            backfill_out('Da dat gioi han --limit=' . $options['limit'] . '.');
            break;
        }

        $size = $options['batch'];

        if ($options['limit'] !== null) {
            $size = (int) min($size, $options['limit'] - $processed);
        }

        if ($size <= 0) {
            break;
        }

        $rows = backfill_fetch_batch($pdo, $size, $startId);

        if ($rows === []) {
            break;
        }

        // Đẩy con trỏ sang cuối lô vừa lấy -> lô sau KHÔNG lấy lại các dòng này.
        $startId = (int) $rows[count($rows) - 1]['id'];

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "UPDATE `snapshots`
                    SET `content_hash` = ?, `hash_algo` = 'sha256', `hash_recorded_at` = NOW()
                  WHERE `id` = ?
                    AND (`content_hash` IS NULL OR `content_hash` = '')"
            );

            foreach ($rows as $row) {
                $id         = (int) $row['id'];
                $contentHtml = (string) $row['content_html'];

                $processed++;

                // Nội dung rỗng -> không có gì để băm; để nguyên NULL.
                if (trim($contentHtml) === '') {
                    $skipped++;
                    continue;
                }

                // Dùng ĐÚNG hàm của hệ thống (không tự tính lại).
                $hash = wa_snapshot_content_hash($contentHtml);

                $stmt->execute([$hash, $id]);

                if ($stmt->rowCount() > 0) {
                    $written++;
                } else {
                    // Dòng này đã có hash (có thể do tiến trình khác vừa ghi)
                    // -> KHÔNG ghi đè.
                    $skipped++;
                }

                if ($processed % 25 === 0) {
                    backfill_out('  ... da xu ly ' . $processed . ' ban (ghi ' . $written . ', bo qua ' . $skipped . ')');
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            backfill_out('LOI khi xu ly lo: ' . $e->getMessage());
            backfill_out('Da xu ly ' . $processed . ' ban, ghi ' . $written . '. Chay lai de tiep tuc.');
            exit(1);
        }
    }

    backfill_out('');
    backfill_out('=== KET QUA ===');
    backfill_out('Da xu ly   : ' . $processed);
    backfill_out('Da ghi hash: ' . $written);
    backfill_out('Bo qua     : ' . $skipped);
    backfill_out('Con thieu   : ' . backfill_count_missing($pdo));
    backfill_out('');
    backfill_out('LUU Y: hash moi tinh tu du lieu hien co -> chi tao BASELINE tu');
    backfill_out('thoi diem backfill. KHONG chung minh du lieu chua tung bi sua');
    backfill_out('truoc do.');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'LOI: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
