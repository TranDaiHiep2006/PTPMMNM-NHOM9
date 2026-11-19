-- =============================================================================
-- database/schema.sql — Lược đồ cơ sở dữ liệu WebArchive (đề tài 16)
-- -----------------------------------------------------------------------------
-- MySQL 8.0 / InnoDB / utf8mb4 / utf8mb4_unicode_ci
-- Toàn bộ tệp có thể chạy lại nhiều lần (DROP TABLE IF EXISTS theo thứ tự
-- an toàn với ràng buộc khóa ngoại).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- users — tài khoản người dùng
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(150)  NOT NULL,
    `email`         VARCHAR(190)  NOT NULL,
    `password_hash` VARCHAR(255)  NOT NULL,
    `role`          ENUM('admin','user')         NOT NULL DEFAULT 'user',
    `status`        ENUM('active','locked')      NOT NULL DEFAULT 'active',
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- libraries — thư viện chuyên đề
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `libraries`;
CREATE TABLE `libraries` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_id`    INT UNSIGNED NOT NULL,
    `name`        VARCHAR(150)  NOT NULL,
    `slug`        VARCHAR(150)  NOT NULL,
    `description` TEXT          NULL,
    `visibility`  ENUM('private','shared','public') NOT NULL DEFAULT 'private',
    `color`       VARCHAR(32)   NOT NULL DEFAULT 'a',
    `icon`        VARCHAR(64)   NOT NULL DEFAULT 'folder_special',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_libraries_slug` (`slug`),
    KEY `ix_libraries_owner` (`owner_id`),
    CONSTRAINT `fk_libraries_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- library_stars — thư viện được người dùng đánh dấu yêu thích (nút sao)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `library_stars`;
CREATE TABLE `library_stars` (
    `user_id`    INT UNSIGNED NOT NULL,
    `library_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `library_id`),
    KEY `ix_library_stars_library` (`library_id`),
    CONSTRAINT `fk_library_stars_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_library_stars_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- library_members — thành viên & phân quyền trong thư viện
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `library_members`;
CREATE TABLE `library_members` (
    `library_id` INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `role`       ENUM('owner','editor','viewer') NOT NULL DEFAULT 'viewer',
    PRIMARY KEY (`library_id`, `user_id`),
    KEY `ix_library_members_user` (`user_id`),
    CONSTRAINT `fk_library_members_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_library_members_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tags — nhãn phân loại
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
    `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64)  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- saved_pages — các URL đã lưu trong thư viện
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `saved_pages`;
CREATE TABLE `saved_pages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `library_id` INT UNSIGNED NOT NULL,
    `source_url` VARCHAR(1000) NOT NULL,
    `domain`     VARCHAR(255)  NOT NULL,
    `title`      VARCHAR(500)  NOT NULL,
    `status`     ENUM('active','error') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `has_update` TINYINT(1) NOT NULL DEFAULT 0,
    `checked_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_saved_pages_library` (`library_id`),
    KEY `ix_saved_pages_domain` (`domain`),
    KEY `ix_saved_pages_created_by` (`created_by`),
    CONSTRAINT `fk_saved_pages_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_saved_pages_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- page_tags — liên kết thẻ với trang đã lưu
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `page_tags`;
CREATE TABLE `page_tags` (
    `page_id` INT UNSIGNED NOT NULL,
    `tag_id`  INT UNSIGNED NOT NULL,
    PRIMARY KEY (`page_id`, `tag_id`),
    KEY `ix_page_tags_tag` (`tag_id`),
    CONSTRAINT `fk_page_tags_page`
        FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_page_tags_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- snapshots — các phiên bản nội dung đã lưu của một trang
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `snapshots`;
CREATE TABLE `snapshots` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_id`       INT UNSIGNED NOT NULL,
    `version_no`    INT UNSIGNED NOT NULL DEFAULT 1,
    `label`         VARCHAR(255)  NULL DEFAULT NULL,
    `title`         VARCHAR(500)  NOT NULL,
    `content_html`  MEDIUMTEXT    NULL,
    `content_text`  MEDIUMTEXT    NULL,
    `source_url`    VARCHAR(1000) NOT NULL,
    `fetched_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `http_status`   SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `fetch_status`  ENUM('success','error_404','error_timeout','error_blocked',
                          'error_no_content','error_http') NOT NULL DEFAULT 'success',
    `error_message` VARCHAR(500)  NULL,
    -- V3 / FEATURE 6: mã SHA-256 của nội dung (đã chuẩn hoá), ghi lúc tạo snapshot.
    `content_hash`  CHAR(64)     NULL DEFAULT NULL,
    `hash_algo`     VARCHAR(20)  NOT NULL DEFAULT 'sha256',
    `hash_recorded_at` DATETIME  NULL DEFAULT NULL,
    `hash_verified_at` DATETIME  NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_snapshots_page_version` (`page_id`, `version_no`),
    KEY `ix_snapshots_page` (`page_id`),
    CONSTRAINT `fk_snapshots_page`
        FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- search_terms — CHỈ MỤC ĐẢO (INVERTED INDEX) TỰ XÂY DỰNG cho tìm kiếm (P-SEARCH)
-- -----------------------------------------------------------------------------
-- Mỗi dòng là một (từ đã bỏ dấu, snapshot) kèm số lần xuất hiện trong tiêu đề
-- và trong thân bài. Cơ chế này do dự án TỰ LÀM — KHÔNG dùng FULLTEXT /
-- MATCH ... AGAINST của MySQL.
--   * term        : token gốc đã hạ chữ thường (giữ nguyên dấu tiếng Việt).
--   * term_folded : token sau khi bỏ dấu tiếng Việt — đây là khoá so khớp.
--   * UNIQUE (term_folded, snapshot_id): một từ chỉ có MỘT dòng cho mỗi bản
--     snapshot, nên đếm tần suất ngay trong lúc lập chỉ mục (không cần quét lại
--     content_text lúc tìm).
--   * FK ON DELETE CASCADE: xoá snapshot thì dòng chỉ mục của nó tự biến mất.
-- LƯU Ý kiểu dữ liệu: `id` dùng BIGINT UNSIGNED (bảng chỉ mục có thể rất lớn),
-- còn `snapshot_id` phải là INT UNSIGNED để khớp đúng kiểu cột được tham chiếu
-- `snapshots.id` — MySQL không cho khoá ngoại giữa hai kiểu khác nhau.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `search_terms`;
CREATE TABLE `search_terms` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `term`        VARCHAR(100)   NOT NULL,
    `term_folded` VARCHAR(100)   NOT NULL,
    `snapshot_id` INT UNSIGNED  NOT NULL,
    `count_title` INT UNSIGNED   NOT NULL DEFAULT 0,
    `count_body`  INT UNSIGNED   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_search_terms_folded_snapshot` (`term_folded`, `snapshot_id`),
    KEY `ix_search_terms_folded` (`term_folded`),
    CONSTRAINT `fk_search_terms_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- assets — tài nguyên (ảnh) tải về máy chủ nội bộ
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `assets`;
CREATE TABLE `assets` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id`  INT UNSIGNED NOT NULL,
    `original_url` VARCHAR(1000) NOT NULL,
    `local_path`   VARCHAR(500)  NOT NULL,
    `mime_type`    VARCHAR(120)  NOT NULL DEFAULT 'application/octet-stream',
    `size_bytes`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_assets_snapshot` (`snapshot_id`),
    CONSTRAINT `fk_assets_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- notes — ghi chú của người dùng lên một phiên bản snapshot
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `notes`;
CREATE TABLE `notes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id` INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `content`     TEXT          NOT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_notes_snapshot` (`snapshot_id`),
    KEY `ix_notes_user` (`user_id`),
    CONSTRAINT `fk_notes_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- allowlist_domains — các tên miền được duyệt để lưu trang
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `allowlist_domains`;
CREATE TABLE `allowlist_domains` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain`     VARCHAR(255)  NOT NULL,
    `note`       VARCHAR(255)  NULL,
    `added_by`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_allowlist_domain` (`domain`),
    KEY `ix_allowlist_added_by` (`added_by`),
    CONSTRAINT `fk_allowlist_added_by`
        FOREIGN KEY (`added_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- domain_requests — hàng chờ yêu cầu duyệt tên miền (người dùng gửi, quản trị
-- viên duyệt hoặc từ chối; yêu cầu đã xử lý thì bị xoá khỏi hàng chờ)
-- -----------------------------------------------------------------------------
CREATE TABLE `domain_requests` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain`       VARCHAR(255)  NOT NULL,
    `note`         VARCHAR(255)  NULL,
    `requested_by` INT UNSIGNED NOT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_domain_requests_domain` (`domain`),
    KEY `ix_domain_requests_requested_by` (`requested_by`),
    CONSTRAINT `fk_domain_requests_requested_by`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- notifications — thông báo phiên bản mới (P-AUTOVERSION)
--
-- Mỗi dòng là MỘT thông báo cho MỘT tài khoản. `dedupe_key` là khoá chống
-- trùng (UNIQUE): cùng một (người dùng, bài viết, số phiên bản) chỉ có tối
-- đa một thông báo — phát lại cũng không tạo dòng trùng.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `page_id`     INT UNSIGNED NULL DEFAULT NULL,
    `snapshot_id` INT UNSIGNED NULL DEFAULT NULL,
    `kind`        VARCHAR(40)  NOT NULL DEFAULT 'version_update',
    `title`       VARCHAR(190) NOT NULL,
    `body`        VARCHAR(500) NOT NULL DEFAULT '',
    `dedupe_key`  VARCHAR(190) NOT NULL,
    `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at`     DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_notifications_dedupe` (`dedupe_key`),
    KEY `ix_notifications_user` (`user_id`, `is_read`, `id`),
    KEY `ix_notifications_page` (`page_id`),
    CONSTRAINT `fk_notifications_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_page`
        FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- V3 (12 CHỨC NĂNG MỚI) — nằm SAU khối trên để không phá thứ tự khoá ngoại.
--
-- LƯU Ý: khối trên dùng DROP TABLE IF EXISTS nên khi cài MỚI thì CSDL đầy đủ.
-- Database đang chạy KHÔNG được reset — hãy chạy scripts/migrate_v3.php thay vì
-- chạy lại file schema.sql này.
-- ============================================================================

-- -----------------------------------------------------------------------------
-- reading_states — trạng thái đọc + tiến độ đọc theo tài khoản (V3 Feature 3)
-- -----------------------------------------------------------------------------
CREATE TABLE `reading_states` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `snapshot_id`   INT UNSIGNED NOT NULL,
    `status`        ENUM('unread','reading','read') NOT NULL DEFAULT 'unread',
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
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reading_queue — danh sách đọc sau (V3 Feature 3)
-- -----------------------------------------------------------------------------
CREATE TABLE `reading_queue` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- annotations — bôi đen / tô màu / ghi chú trực tiếp (V3 Feature 4)
--
-- KHÔNG sửa content_html gốc của snapshot. Vị trí đánh dấu được neo bằng
-- (selector, start_offset, end_offset) kèm quote + prefix/suffix 32 ký tự
-- để định vị lại được khi nội dung dịch chuyển.
-- -----------------------------------------------------------------------------
CREATE TABLE `annotations` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id` INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `selector`    VARCHAR(255)  NOT NULL DEFAULT '',
    `start_offset` INT UNSIGNED NOT NULL DEFAULT 0,
    `end_offset`  INT UNSIGNED NOT NULL DEFAULT 0,
    `quote`       VARCHAR(1000) NOT NULL DEFAULT '',
    `prefix`      VARCHAR(255)  NOT NULL DEFAULT '',
    `suffix`      VARCHAR(255)  NOT NULL DEFAULT '',
    `color`       VARCHAR(20)   NOT NULL DEFAULT 'yellow',
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
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- share_links — link chia sẻ có thời hạn / giới hạn lượt xem (V3 Feature 8)
--
-- CHỈ lưu HASH SHA-256 của token, KHÔNG lưu token thô. Lượt xem được tăng bằng
-- UPDATE có điều kiện nên an toàn khi nhiều người mở đồng thời.
-- -----------------------------------------------------------------------------
CREATE TABLE `share_links` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id` INT UNSIGNED NOT NULL,
    `created_by`  INT UNSIGNED NOT NULL,
    `token_hash`  CHAR(64)     NOT NULL,
    `label`       VARCHAR(190) NOT NULL DEFAULT '',
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
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- api_tokens — token riêng cho Chrome Extension (V3 Feature 7)
--
-- CHỈ lưu HASH SHA-256 của token. Có thời hạn, thu hồi được, và bộ đếm tần suất
-- theo từng token (rate_limit chung + rate_limit riêng cho hành động lưu trang).
-- -----------------------------------------------------------------------------
CREATE TABLE `api_tokens` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `token_hash`   CHAR(64)     NOT NULL,
    `name`         VARCHAR(120) NOT NULL DEFAULT '',
    `scopes`       VARCHAR(255) NOT NULL DEFAULT 'read,library:create,page:save',
    `expires_at`   DATETIME     NULL DEFAULT NULL,
    `revoked_at`   DATETIME     NULL DEFAULT NULL,
    `last_used_at` DATETIME     NULL DEFAULT NULL,
    `rate_window_start`  DATETIME NULL DEFAULT NULL,
    `rate_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `save_window_start` DATETIME NULL DEFAULT NULL,
    `save_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_api_tokens_hash` (`token_hash`),
    KEY `ix_api_tokens_user` (`user_id`, `revoked_at`),
    CONSTRAINT `fk_api_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- feed_subscriptions + feed_items — nguồn RSS/Atom (V3 Feature 9)
--
-- KHÔNG có cron: feed chỉ được tải khi người dùng yêu cầu. item_hash là
-- SHA-256 của URL nên UNIQUE(subscription_id, item_hash) chống trùng.
--
-- LƯU Ý: `feed_subscriptions` KHÔNG đặt UNIQUE trực tiếp trên `feed_url`
-- (VARCHAR(1000) × utf8mb4 = 4000 byte + 4 byte user_id = 4004 byte > 3072 byte
-- giới hạn khoá InnoDB -> MySQL lỗi 1071). Thay vào đó dùng cột
-- `feed_url_hash` CHAR(64) và UNIQUE(user_id, feed_url_hash) = 260 byte.
-- `feed_url` vẫn giữ nguyên VARCHAR(1000) để lưu trọn URL.
--
-- `feed_url_hash` = SHA2(WEIGHT_STRING(TRIM(TRAILING ' ' FROM feed_url)
-- COLLATE utf8mb4_unicode_ci), 256) — dùng đúng quy tắc so sánh của
-- collation utf8mb4_unicode_ci (không phân biệt hoa/thường, không phân biệt
-- dấu, PAD SPACE) nên khoá hash tương đương với khoá UNIQUE cũ trên `feed_url`.
-- -----------------------------------------------------------------------------
CREATE TABLE `feed_subscriptions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `library_id`      INT UNSIGNED NOT NULL,
    `feed_url`        VARCHAR(1000) NOT NULL,
    `feed_url_hash`   CHAR(64)     NOT NULL,
    `feed_title`      VARCHAR(255)  NOT NULL DEFAULT '',
    `tags`            VARCHAR(255)  NOT NULL DEFAULT '',
    `last_checked_at` DATETIME     NULL DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_feed_subs_user_url` (`user_id`, `feed_url_hash`),
    KEY `ix_feed_subs_library` (`library_id`),
    CONSTRAINT `fk_feed_subs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_feed_subs_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feed_items` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscription_id` INT UNSIGNED NOT NULL,
    `item_url`        VARCHAR(1000) NOT NULL,
    `item_title`      VARCHAR(500)  NOT NULL DEFAULT '',
    `item_hash`       CHAR(64)     NOT NULL,
    `published_at`    DATETIME     NULL DEFAULT NULL,
    `saved_page_id`   INT UNSIGNED NULL DEFAULT NULL,
    `seen_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_feed_items_sub_hash` (`subscription_id`, `item_hash`),
    KEY `ix_feed_items_subscription` (`subscription_id`),
    CONSTRAINT `fk_feed_items_subscription`
        FOREIGN KEY (`subscription_id`) REFERENCES `feed_subscriptions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- content_watches — THEO DÕI MỘT ĐOẠN NỘI DUNG (V3 Đợt 4 / Feature 10)
--
-- KHÔNG sửa content_html gốc của snapshot. Đoạn được bôi đen được lưu kèm
-- ngữ cảnh hai bên (prefix/suffix) và vị trí trong khối (selector + offset)
-- để định vị lại được khi nội dung dịch chuyển.
-- -----------------------------------------------------------------------------
CREATE TABLE `content_watches` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `page_id`        INT UNSIGNED NOT NULL,
    `snapshot_id`    INT UNSIGNED NOT NULL,
    `selector`       VARCHAR(255)  NOT NULL DEFAULT '',
    `start_offset`   INT UNSIGNED NOT NULL DEFAULT 0,
    `end_offset`     INT UNSIGNED NOT NULL DEFAULT 0,
    `quote`          VARCHAR(1000) NOT NULL DEFAULT '',
    `prefix`         VARCHAR(255)  NOT NULL DEFAULT '',
    `suffix`         VARCHAR(255)  NOT NULL DEFAULT '',
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `status`         ENUM('ok','changed','unknown','paused') NOT NULL DEFAULT 'ok',
    `status_note`    VARCHAR(255)  NOT NULL DEFAULT '',
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
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- page_relations — QUAN HỆ GIỮA CÁC BÀI VIẾT (V3 Đợt 4 / Feature 11)
--
-- Bốn loại: related (Liên quan), references (Tham khảo),
--            supplements (Bổ sung), contrasts (Đối chiếu).
-- FK ON DELETE CASCADE: xoá bài viết thì quan hệ của nó tự biến mất.
-- -----------------------------------------------------------------------------
CREATE TABLE `page_relations` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_page_id` INT UNSIGNED NOT NULL,
    `target_page_id` INT UNSIGNED NOT NULL,
    `relation_type`  ENUM('related','references','supplements','contrasts') NOT NULL DEFAULT 'related',
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
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V3 Đợt 4 / Feature 12 (phát hiện trùng lặp) KHÔNG cần bảng mới:
--   - trùng URL dùng chuẩn hoá wa_bulk_normalize_url() trên saved_pages;
--   - trùng nội dung chính xác dùng cột `snapshots.content_hash` (SHA-256);
--   - gần giống dùng Jaccard trên n-gram 3 từ của content_text, lọc trước
--     theo độ dài và giới hạn số ứng viên để không làm chậm hệ thống.

