<?php
/**
 * scripts/migrate_v3.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG HẠ TẦNG CHO 12 CHỨC NĂNG MỚI (V3) — chạy được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_v3.php
 *
 * Dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php. KHÔNG hỏi mật khẩu quản
 * trị, KHÔNG đụng database nào khác.
 *
 * Nó CHỈ TẠO THÊM bảng/cột, KHÔNG xoá và KHÔNG sửa dữ liệu cũ:
 *
 *   ĐỢT 1 — Tiện ích cơ bản
 *     1) `reading_states` — trạng thái đọc (chưa đọc / đang đọc / đã đọc) và
 *        tiến độ đọc theo tài khoản, gắn với đúng một snapshot.
 *     2) `reading_queue`  — "Danh sách đọc sau" (đánh dấu đọc sau).
 *
 *   ĐỢT 2 — Nghiên cứu & quản lý nội dung
 *     3) `annotations`    — bôi đen / đánh dấu / ghi chú trực tiếp trong reader.
 *     4) Cột `snapshots.content_hash` + `snapshots.hash_algo` — SHA-256 nội dung.
 *
 *   ĐỢT 3 — Thu thập & chia sẻ
 *     5) `share_links`    — link chia sẻ có thời hạn / giới hạn lượt xem.
 *     6) `feed_subscriptions` + `feed_items` — theo dõi RSS/Atom.
 *
 *   ĐỢT 4 — Tính năng nâng cao
 *     7) `content_watches` — theo dõi một đoạn nội dung (F10).
 *     8) `page_relations`  — quan hệ giữa các bài viết (F11).
 *     9) F12 (phát hiện trùng lặp) KHÔNG cần bảng mới — dùng cột
 *        `snapshots.content_hash` đã có sẵn.
 *
 * Nhờ kiểm tra INFORMATION_SCHEMA trước nên chạy lại bao nhiêu lần cũng an toàn.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_v3.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

/** Bảng đã tồn tại trong database hiện tại chưa? */
function v3_has_table(PDO $pdo, string $table): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
    )->fetchColumn() > 0;
}

/**
 * Tạo bảng nếu chưa có.
 *
 * @param array<string,string> $extra Các đoạn bổ sung sau phần `) ENGINE=...`.
 */
function v3_create_table(PDO $pdo, string $table, string $columns, string $extra = ''): void
{
    if (v3_has_table($pdo, $table)) {
        out('OK: bang `' . $table . '` da ton tai.');

        return;
    }

    $pdo->exec(
        'CREATE TABLE `' . $table . '` (' . $columns . ')'
        . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' . $extra
    );

    out('OK: da tao bang `' . $table . '`.');
}

/** Cột đã tồn tại trong bảng này chưa? */
function v3_has_column(PDO $pdo, string $table, string $column): bool
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table)
        . ' AND COLUMN_NAME = ' . $pdo->quote($column)
    )->fetchColumn() > 0;
}

/**
 * Index này đã tồn tại trong bảng này chưa?
 */
function v3_has_index(PDO $pdo, string $table, string $index): bool
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table)
        . ' AND INDEX_NAME = ' . $pdo->quote($index)
    )->fetchColumn() > 0;
}

/**
 * Index này có chứa cột này không?
 *
 * Dùng để phân biệt "khoá UNIQUE cũ trên `feed_url`" với "khoá mới trên
 * `feed_url_hash`" — cả hai cùng tên `ux_feed_subs_user_url`.
 */
function v3_index_has_column(PDO $pdo, string $table, string $index, string $column): bool
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table)
        . ' AND INDEX_NAME = ' . $pdo->quote($index)
        . ' AND COLUMN_NAME = ' . $pdo->quote($column)
    )->fetchColumn() > 0;
}

/** Thêm cột nếu chưa có. */
function v3_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!v3_has_table($pdo, $table)) {
        out('BO QUA: bang `' . $table . '` chua ton tai.');

        return;
    }

    if (v3_has_column($pdo, $table, $column)) {
        out('OK: cot `' . $table . '.' . $column . '` da ton tai.');

        return;
    }

    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);

    out('OK: da them cot `' . $table . '.' . $column . '`.');
}

try {
    $pdo = db();

    /* =========================================================================
     * ĐỢT 1 — TIỆN ÍCH CƠ BẢN
     * ====================================================================== */

    // 1) reading_states — trạng thái đọc + tiến độ đọc theo tài khoản.
    v3_create_table(
        $pdo,
        'reading_states',
        '`id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`       INT UNSIGNED NOT NULL,
        `snapshot_id`   INT UNSIGNED NOT NULL,
        `status`        ENUM(\'unread\',\'reading\',\'read\') NOT NULL DEFAULT \'unread\',
        `progress`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `scroll_offset` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_reading_states_user_snapshot` (`user_id`, `snapshot_id`),
        KEY `ix_reading_states_user` (`user_id`, `status`),
        CONSTRAINT `fk_reading_states_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_reading_states_snapshot`
            FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    // 2) reading_queue — danh sách đọc sau.
    v3_create_table(
        $pdo,
        'reading_queue',
        '`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT UNSIGNED NOT NULL,
        `snapshot_id` INT UNSIGNED NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_reading_queue_user_snapshot` (`user_id`, `snapshot_id`),
        KEY `ix_reading_queue_user` (`user_id`, `created_at`),
        CONSTRAINT `fk_reading_queue_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_reading_queue_snapshot`
            FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    /* =========================================================================
     * ĐỢT 2 — NGHIÊN CỨU & QUẢN LÝ NỘI DUNG
     * ====================================================================== */

    // 3) annotations — bôi đen / đánh dấu / ghi chú trực tiếp.
    //    Dùng cơ chế neo văn bản: `selector` = CSS selector tới khối chứa đoạn,
    //    `start_offset`/`end_offset` = vị trí ký tự trong khối đó,
    //    `prefix`/`suffix` = 32 ký tự ngữ cảnh hai bên để định vị lại khi
    //    nội dung dịch chuyển (không ghi vào content_html gốc của snapshot).
    v3_create_table(
        $pdo,
        'annotations',
        '`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `snapshot_id` INT UNSIGNED NOT NULL,
        `user_id`     INT UNSIGNED NOT NULL,
        `selector`    VARCHAR(255)  NOT NULL DEFAULT \'\',
        `start_offset` INT UNSIGNED NOT NULL DEFAULT 0,
        `end_offset`  INT UNSIGNED NOT NULL DEFAULT 0,
        `quote`       VARCHAR(1000) NOT NULL DEFAULT \'\',
        `prefix`      VARCHAR(255)  NOT NULL DEFAULT \'\',
        `suffix`      VARCHAR(255)  NOT NULL DEFAULT \'\',
        `color`       VARCHAR(20)   NOT NULL DEFAULT \'yellow\',
        `note`        VARCHAR(1000) NULL DEFAULT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `ix_annotations_snapshot` (`snapshot_id`),
        KEY `ix_annotations_user` (`user_id`),
        CONSTRAINT `fk_annotations_snapshot`
            FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_annotations_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    // 4) SHA-256 nội dung snapshot.
    v3_add_column($pdo, 'snapshots', 'content_hash', "CHAR(64) NULL DEFAULT NULL");
    v3_add_column($pdo, 'snapshots', 'hash_algo', "VARCHAR(20) NOT NULL DEFAULT 'sha256'");
    v3_add_column($pdo, 'snapshots', 'hash_recorded_at', 'DATETIME NULL DEFAULT NULL');
    v3_add_column($pdo, 'snapshots', 'hash_verified_at', 'DATETIME NULL DEFAULT NULL');

    /* =========================================================================
     * ĐỢT 3 — THU THẬP & CHIA SẺ
     * ====================================================================== */

    // 5) share_links — link chia sẻ có thời hạn / giới hạn lượt xem.
    //    CHỈ lưu HASH của token, không lưu token thô.
    v3_create_table(
        $pdo,
        'share_links',
        '`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `snapshot_id` INT UNSIGNED NOT NULL,
        `created_by`  INT UNSIGNED NOT NULL,
        `token_hash`  CHAR(64)     NOT NULL,
        `label`       VARCHAR(190) NOT NULL DEFAULT \'\',
        `expires_at`  DATETIME     NULL DEFAULT NULL,
        `max_views`   INT UNSIGNED NULL DEFAULT NULL,
        `view_count`  INT UNSIGNED NOT NULL DEFAULT 0,
        `revoked_at`  DATETIME     NULL DEFAULT NULL,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_share_links_token` (`token_hash`),
        KEY `ix_share_links_snapshot` (`snapshot_id`),
        KEY `ix_share_links_creator` (`created_by`),
        CONSTRAINT `fk_share_links_snapshot`
            FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_share_links_creator`
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    // 5b) api_tokens — token riêng cho Chrome Extension (F7).
    //     CHỈ lưu HASH của token, không lưu token thô. Có thời hạn, thu hồi
    //     và bộ đếm tần suất theo từng token.
    v3_create_table(
        $pdo,
        'api_tokens',
        '`id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`      INT UNSIGNED NOT NULL,
        `token_hash`   CHAR(64)     NOT NULL,
        `name`         VARCHAR(120) NOT NULL DEFAULT \'\',
        `scopes`       VARCHAR(255) NOT NULL DEFAULT \'read,library:create,page:save\',
        `expires_at`   DATETIME     NULL DEFAULT NULL,
        `revoked_at`   DATETIME     NULL DEFAULT NULL,
        `last_used_at` DATETIME     NULL DEFAULT NULL,
        `rate_window_start` DATETIME NULL DEFAULT NULL,
        `rate_count`   INT UNSIGNED NOT NULL DEFAULT 0,
        `save_window_start` DATETIME NULL DEFAULT NULL,
        `save_count`   INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_api_tokens_hash` (`token_hash`),
        KEY `ix_api_tokens_user` (`user_id`, `revoked_at`),
        CONSTRAINT `fk_api_tokens_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    // 6) feed_subscriptions + feed_items — theo dõi RSS/Atom.
    //
    //    LƯU Ý VỀ KHOÁ UNIQUE (`ux_feed_subs_user_url`):
    //    InnoDB giới hạn khoá tối đa 3072 byte. Với charset utf8mb4, mỗi ký tự
    //    tính 4 byte, nên UNIQUE (user_id, feed_url VARCHAR(1000)) = 4 + 4000 =
    //    4004 byte -> MySQL báo lỗi 1071 "Specified key was too long".
    //
    //    Cách sửa (KHÔNG cắt ngắn `feed_url`, vẫn lưu URL dài đủ 1000 ký tự):
    //    thêm cột `feed_url_hash` CHAR(64) = SHA-256 của `feed_url`, và đặt
    //    UNIQUE trên (user_id, feed_url_hash) = 4 + 256 = 260 byte.
    //    `feed_url` vẫn giữ nguyên VARCHAR(1000) để lưu trọn vẹn URL.
    v3_create_table(
        $pdo,
        'feed_subscriptions',
        '`id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`       INT UNSIGNED NOT NULL,
        `library_id`    INT UNSIGNED NOT NULL,
        `feed_url`      VARCHAR(1000) NOT NULL,
        `feed_url_hash` CHAR(64)     NOT NULL,
        `feed_title`    VARCHAR(255)  NOT NULL DEFAULT \'\',
        `tags`          VARCHAR(255)  NOT NULL DEFAULT \'\',
        `last_checked_at` DATETIME   NULL DEFAULT NULL,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_feed_subs_user_url` (`user_id`, `feed_url_hash`),
        KEY `ix_feed_subs_library` (`library_id`),
        CONSTRAINT `fk_feed_subs_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_feed_subs_library`
            FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    // 6b) Sửa chữa cho trường hợp bảng `feed_subscriptions` đã có từ lần chạy
    //     trước (bản cũ: UNIQUE trên `feed_url` -> lỗi 1071, chưa có cột hash).
    //     Không DROP bảng, không xoá dữ liệu: chỉ thêm cột, băm lại và đổi khoá.
    if (v3_has_table($pdo, 'feed_subscriptions')) {
        if (!v3_has_column($pdo, 'feed_subscriptions', 'feed_url_hash')) {
            v3_add_column(
                $pdo,
                'feed_subscriptions',
                'feed_url_hash',
                'CHAR(64) NULL DEFAULT NULL'
            );
        }

        // Băm lại các dòng cũ.
        //
        // BIỂU THỨC CHUẨN HOÁ (phải GIỐNG HỆT `wa_feed_url_hash()` bên PHP):
        //   SHA2(WEIGHT_STRING(TRIM(TRAILING ' ' FROM feed_url) COLLATE utf8mb4_unicode_ci), 256)
        //
        // Vì sao phải chuẩn hoá thế này:
        //   1. `TRIM(TRAILING ' ')` — bỏ khoảng trắng cuối, vì collation
        //      utf8mb4_unicode_ci là kiểu PAD SPACE: 'a' và 'a  ' ĐƯỢC coi là
        //      BẰNG NHAU. Không bỏ thì khoá mới khác khoá cũ.
        //   2. `WEIGHT_STRING(... COLLATE utf8mb4_unicode_ci)` — trả về dãy byte
        //      ĐÃ QUY ĐỔI theo quy tắc so sánh của collation (không phân biệt
        //      HOA/thường, không phân biệt dấu, ǅ = ǆ, ﬀ = ff...). Đây chính là
        //      nội dung mà MySQL dùng khi so sánh khoá cũ -> hash mới BẢO ĐẢM
        //      "trùng" đúng nghĩa với khoá cũ.
        //   3. `SHA2(..., 256)` — băm 256 bit ra 64 ký tự hex.
        //
        // LƯU Ý: MySQL cắt chuỗi theo byte khi `feed_url` vượt 1000 ký tự, nên
        // giá trị đọc ra từ cột luôn <= 1000 ký tự -> hash ổn định.
        $pdo->exec(
            "UPDATE `feed_subscriptions`
                SET `feed_url_hash` = SHA2(
                        WEIGHT_STRING(TRIM(TRAILING ' ' FROM `feed_url`) COLLATE utf8mb4_unicode_ci),
                        256
                    )
              WHERE `feed_url_hash` IS NULL OR `feed_url_hash` = ''"
        );

        // Gỡ khoá UNIQUE cũ (trên `feed_url`) nếu vẫn còn.
        if (v3_index_has_column($pdo, 'feed_subscriptions', 'ux_feed_subs_user_url', 'feed_url')) {
            $pdo->exec('ALTER TABLE `feed_subscriptions` DROP INDEX `ux_feed_subs_user_url`');

            out('OK: da go khoa UNIQUE cu tren `feed_url`.');
        }

        // Đặt lại UNIQUE trên (user_id, feed_url_hash).
        if (!v3_has_index($pdo, 'feed_subscriptions', 'ux_feed_subs_user_url')) {
            try {
                $pdo->exec(
                    'ALTER TABLE `feed_subscriptions`
                        ADD UNIQUE KEY `ux_feed_subs_user_url` (`user_id`, `feed_url_hash`)'
                );

                out('OK: da them khoa UNIQUE `ux_feed_subs_user_url` tren (user_id, feed_url_hash).');
            } catch (PDOException $e) {
                out('LOI: khong them duoc khoa UNIQUE tren feed_subscriptions: ' . $e->getMessage());
                out('     Hay kiem tra lai co du lieu trung (user_id, feed_url) hay khong.');
                exit(1);
            }
        }

        // Sau khi đã băm đủ, cột hash không được để NULL.
        $nullable = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feed_subscriptions'
                AND COLUMN_NAME = 'feed_url_hash' AND IS_NULLABLE = 'YES'"
        )->fetchColumn();

        if ($nullable > 0) {
            $pdo->exec('ALTER TABLE `feed_subscriptions` MODIFY `feed_url_hash` CHAR(64) NOT NULL');

            out('OK: da chuyen `feed_url_hash` sang NOT NULL.');
        }
    }

    v3_create_table(
        $pdo,
        'feed_items',
        '`id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `subscription_id` INT UNSIGNED NOT NULL,
        `item_url`        VARCHAR(1000) NOT NULL,
        `item_title`      VARCHAR(500)  NOT NULL DEFAULT \'\',
        `item_hash`       CHAR(64)     NOT NULL,
        `published_at`    DATETIME     NULL DEFAULT NULL,
        `saved_page_id`   INT UNSIGNED NULL DEFAULT NULL,
        `seen_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_feed_items_sub_hash` (`subscription_id`, `item_hash`),
        KEY `ix_feed_items_subscription` (`subscription_id`),
        CONSTRAINT `fk_feed_items_subscription`
            FOREIGN KEY (`subscription_id`) REFERENCES `feed_subscriptions` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    /* =========================================================================
     * ĐỢT 4 — TÍNH NĂNG NÂNG CAO
     * ====================================================================== */

    // 7) content_watches — theo dõi MỘT ĐOẠN nội dung (F10).
    //    Đoạn đã bôi đen được lưu kèm ngữ cảnh hai bên và vị trí trong khối
    //    để định vị lại được khi nội dung dịch chuyển.
    //    KHÔNG sửa content_html gốc của snapshot.
    v3_create_table(
        $pdo,
        'content_watches',
        '`id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`        INT UNSIGNED NOT NULL,
        `page_id`        INT UNSIGNED NOT NULL,
        `snapshot_id`    INT UNSIGNED NOT NULL,
        `selector`       VARCHAR(255)  NOT NULL DEFAULT \'\',
        `start_offset`   INT UNSIGNED NOT NULL DEFAULT 0,
        `end_offset`     INT UNSIGNED NOT NULL DEFAULT 0,
        `quote`          VARCHAR(1000) NOT NULL DEFAULT \'\',
        `prefix`         VARCHAR(255)  NOT NULL DEFAULT \'\',
        `suffix`         VARCHAR(255)  NOT NULL DEFAULT \'\',
        `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
        `status`         ENUM(\'ok\',\'changed\',\'unknown\',\'paused\') NOT NULL DEFAULT \'ok\',
        `status_note`    VARCHAR(255)  NOT NULL DEFAULT \'\',
        `last_seen_quote` VARCHAR(1000) NULL DEFAULT NULL,
        `last_changed_at` DATETIME     NULL DEFAULT NULL,
        `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `ix_content_watches_page` (`page_id`, `is_active`),
        KEY `ix_content_watches_user` (`user_id`, `id`),
        CONSTRAINT `fk_content_watches_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_content_watches_page`
            FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_content_watches_snapshot`
            FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE'
    );

    // 8) page_relations — quan hệ giữa các bài viết (F11).
    //    Bốn loại: related / references / supplements / contrasts.
    //    FK ON DELETE CASCADE: xoá bài viết thì quan hệ của nó tự biến mất.
    v3_create_table(
        $pdo,
        'page_relations',
        '`id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `source_page_id` INT UNSIGNED NOT NULL,
        `target_page_id` INT UNSIGNED NOT NULL,
        `relation_type`  ENUM(\'related\',\'references\',\'supplements\',\'contrasts\') NOT NULL DEFAULT \'related\',
        `note`           VARCHAR(400)  NULL DEFAULT NULL,
        `created_by`     INT UNSIGNED NULL DEFAULT NULL,
        `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_page_relations_pair` (`source_page_id`, `target_page_id`, `relation_type`),
        KEY `ix_page_relations_target` (`target_page_id`),
        CONSTRAINT `fk_page_relations_source`
            FOREIGN KEY (`source_page_id`) REFERENCES `saved_pages` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_page_relations_target`
            FOREIGN KEY (`target_page_id`) REFERENCES `saved_pages` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_page_relations_creator`
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
            ON DELETE SET NULL ON UPDATE CASCADE'
    );

    // 9) F12 (phát hiện trùng lặp) KHÔNG cần bảng mới:
    //    - trùng URL  : chuẩn hoá bằng wa_bulk_normalize_url() trên saved_pages;
    //    - trùng nội dung chính xác: cột `snapshots.content_hash` (SHA-256,
    //      đã tạo ở BƯỚC 4 của đợt 2);
    //    - gần giống  : Jaccard trên n-gram 3 từ của content_text, lọc trước
    //      theo độ dài và giới hạn số ứng viên để không làm chậm hệ thống.

    out('');
    out('HOAN TAT.');
    exit(0);
} catch (PDOException $e) {
    out('LOI: ' . $e->getMessage());
    exit(1);
}
