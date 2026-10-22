<?php
/**
 * sample_data.php
 * -----------------------------------------------------------------------------
 * NGUỒN DỮ LIỆU MẪU DUY NHẤT cho toàn bộ giao diện WebArchive (UI phase).
 *
 * Tất cả dữ liệu "động" của trang (thư viện, bài viết, thẻ, thành viên, người
 * dùng, danh sách tên miền được duyệt, số liệu thống kê) đều lấy từ file này.
 * Ở phase sau (MySQL), chỉ cần thay nội dung mảng bên dưới bằng truy vấn CSDL
 * mà không phải sửa giao diện.
 *
 * Số liệu được giữ nhất quán giữa mọi trang:
 *   - 3 thư viện, 48 bài viết, 312 ảnh cục bộ, 0 liên kết lỗi
 *   - CNTT & Lập Trình: 24 bài / 168 ảnh / 142 MB
 *   - Tài liệu học tập: 15 bài /  86 ảnh /  86 MB
 *   - Nghiên cứu công nghệ: 9 bài / 58 ảnh / 64 MB
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** @return array<int, array<string, mixed>> Danh sách thư viện của người dùng đang đăng nhập. */
function sample_libraries(): array
{
    return [
        [
            'id' => 'LIB-DEV-2026',
            'slug' => 'cntt-lap-trinh',
            'name' => 'CNTT & Lập Trình',
            'description' => 'Tổng hợp bài viết về lập trình web, thuật toán, cơ sở dữ liệu và công nghệ phần mềm.',
            'icon' => 'terminal',
            'strip' => 'a',
            'role' => 'owner',              // Chu sở hữu | editor | viewer
            'role_label' => 'Chủ sở hữu',
            'visibility' => 'Nội bộ nhóm',
            'visibility_code' => 'shared',
            'category' => 'personal',
            'updated' => 'Cập nhật 2 giờ trước',
            'article_count' => 24,
            'image_count' => 168,
            'storage_mb' => 142,
            'storage_label' => '142 MB',
            'asset_size' => '~84.2 MB',
            'tag_count' => 6,
            'member_count' => 3,
            'starred' => true,
            'broken_images' => 0,
            'integrity' => '100% nguyên vẹn',
            'tags' => ['#PHP', '#MySQL', '#Architecture', '#WebStandards', '#HTML', '#Security'],
        ],
        [
            'id' => 'LIB-EDU-2026',
            'slug' => 'tai-lieu-hoc-tap',
            'name' => 'Tài liệu học tập',
            'description' => 'Các giáo trình công nghệ thông tin, bài giảng chuyên ngành và tài liệu ôn tập học phần.',
            'icon' => 'school',
            'strip' => 'b',
            'role' => 'owner',
            'role_label' => 'Chủ sở hữu',
            'visibility' => 'Chỉ mình tôi',
            'visibility_code' => 'private',
            'category' => 'personal',
            'updated' => 'Cập nhật hôm qua',
            'article_count' => 15,
            'image_count' => 86,
            'storage_mb' => 86,
            'storage_label' => '86 MB',
            'asset_size' => '~51.4 MB',
            'tag_count' => 3,
            'member_count' => 1,
            'starred' => false,
            'broken_images' => 0,
            'integrity' => '100% nguyên vẹn',
            'tags' => ['#CS', '#Algorithms', '#Databases'],
        ],
        [
            'id' => 'LIB-RND-2026',
            'slug' => 'nghien-cuu-cong-nghe',
            'name' => 'Nghiên cứu công nghệ',
            'description' => 'Bài phân tích chuyên sâu về hệ thống phân tán, bảo mật web và kiến trúc lưu trữ dữ liệu tĩnh.',
            'icon' => 'hub',
            'strip' => 'c',
            'role' => 'editor',
            'role_label' => 'Biên tập viên',
            'visibility' => 'Nội bộ nhóm',
            'visibility_code' => 'shared',
            'category' => 'shared',
            'updated' => 'Cập nhật 3 ngày trước',
            'article_count' => 9,
            'image_count' => 58,
            'storage_mb' => 64,
            'storage_label' => '64 MB',
            'asset_size' => '~38.9 MB',
            'tag_count' => 3,
            'member_count' => 3,
            'starred' => false,
            'broken_images' => 0,
            'integrity' => '100% nguyên vẹn',
            'tags' => ['#SystemDesign', '#Security', '#Archive'],
        ],
    ];
}

/** @return array<int, array<string, mixed>> Thẻ nhãn dùng chung cho mọi thư viện. */
function sample_tags(): array
{
    return ['#PHP', '#MySQL', '#Architecture', '#WebStandards', '#HTML', '#Security', '#CS', '#Algorithms', '#Databases', '#SystemDesign', '#Archive', '#AI'];
}

/** @return array<int, array<string, mixed>> Thành viên tham gia thư viện. */
function sample_members(): array
{
    return [
        [
            'initials' => 'LT',
            'name' => 'Lê Minh Tuấn',
            'email' => 'user@demo.vn',
            'role' => 'owner',
            'role_label' => 'Chủ sở hữu',
            'variant' => 'navy',
        ],
        [
            'initials' => 'TN',
            'name' => 'Trần Hoàng Nam',
            'email' => 'nam.tran@demo.vn',
            'role' => 'editor',
            'role_label' => 'Biên tập viên',
            'variant' => 'blue',
        ],
        [
            'initials' => 'NH',
            'name' => 'Nguyễn Thu Hà',
            'email' => 'ha.nguyen@demo.vn',
            'role' => 'viewer',
            'role_label' => 'Người xem',
            'variant' => 'light',
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 * Bài viết đã lưu. `snippet` dùng cho tìm kiếm, `content` dùng cho trang đọc.
 */
function sample_articles(): array
{
    return [
        [
            'id' => 'SNAP-8941',
            'library_id' => 'LIB-DEV-2026',
            'library' => 'CNTT & Lập Trình',
            'title' => 'Tìm hiểu kiến trúc PHP & MySQL trong lưu trữ Web tĩnh',
            'domain' => 'phptutorial.vn',
            'url' => 'https://phptutorial.vn/kien-truc-php-mysql-webarchive',
            'author' => 'Nguyễn Đức Trọng',
            'saved_at' => '21/10/2026, 14:28',
            'version' => 'v3.2.0',
            'size' => '245 KB',
            'images' => 14,
            'reading_time' => '7 phút',
            'thumb_label' => 'HTML DOM Snapshot',
            'thumb_code' => '<article class="markdown-table">' . "\n" . '  <table data-preserved="true">...</table>',
            'excerpt' => 'Hướng dẫn xử lý các bảng dữ liệu đa cột, bảo toàn định dạng CSS gốc và tự động bóc tách các style inline không cần thiết khi trích xuất tài liệu.',
            'tags' => ['#HTML', '#PHP', '#WebStandards'],
            'snippet' => 'Khi xây dựng các thư viện lưu trữ ngoại tuyến độc lập, nguyên tắc cốt lõi là bóc tách toàn bộ tài nguyên động và chỉ giữ lại cấu trúc ngữ nghĩa DOM thuần túy.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8937',
            'library_id' => 'LIB-DEV-2026',
            'library' => 'CNTT & Lập Trình',
            'title' => 'Tối ưu hóa truy vấn MySQL cho hệ thống lưu trữ tài liệu phân trang',
            'domain' => 'techblog.dev',
            'url' => 'https://techblog.dev/toi-uu-hoa-truy-van-mysql-luu-tru-tai-lieu',
            'author' => 'Phạm Gia Huy',
            'saved_at' => '19/10/2026, 09:05',
            'version' => 'v2.1.0',
            'size' => '450 KB',
            'images' => 0,
            'reading_time' => '9 phút',
            'thumb_label' => 'MySQL Query Engine',
            'thumb_code' => 'INDEX (library_id, created_at)' . "\n" . 'EXPLAIN SELECT * FROM snapshots...',
            'excerpt' => 'Kỹ thuật đánh chỉ mục B-Tree đa cột, xử lý tải 100,000 bản ghi bài viết và phương pháp chia bảng để giảm độ trễ truy xuất snapshot dưới 15ms.',
            'tags' => ['#MySQL', '#Databases', '#Architecture'],
            'snippet' => 'B-Tree đa cột giúp giảm độ trễ truy xuất snapshot xuống dưới 15ms khi bảng dữ liệu vượt 100.000 bản ghi bài viết.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8930',
            'library_id' => 'LIB-DEV-2026',
            'library' => 'CNTT & Lập Trình',
            'title' => 'Cơ chế sandbox trong bộ lọc HTML chống chèn mã độc và theo dõi',
            'domain' => 'tapchicongnghe.vn',
            'url' => 'https://tapchicongnghe.vn/sandbox-loc-html-chong-ma-doc',
            'author' => 'Lê Bảo Khánh',
            'saved_at' => '18/10/2026, 21:42',
            'version' => 'v1.4.2',
            'size' => '312 KB',
            'images' => 9,
            'reading_time' => '6 phút',
            'thumb_label' => 'Security Sandbox',
            'thumb_code' => 'CRAWLER :: DOMPurify + Regex' . "\n" . 'assets/images/2026/10/img_921.webp',
            'excerpt' => 'Phân tích cách thức chặn thẻ script, iframe, thuộc tính sự kiện và đường dẫn quảng cáo trước khi lưu snapshot vào kho nội bộ.',
            'tags' => ['#Security', '#HTML', '#WebStandards'],
            'snippet' => 'Lọc bỏ mã thực thi triệt để toàn bộ thẻ script, iframe, object, thuộc tính onclick, inline styles và liên kết quảng cáo trước khi lưu trữ.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8912',
            'library_id' => 'LIB-DEV-2026',
            'library' => 'CNTT & Lập Trình',
            'title' => 'Thiết kế chỉ mục toàn văn cho tìm kiếm nội dung snapshot đã lưu',
            'domain' => 'dev-insights.org',
            'url' => 'https://dev-insights.org/thiet-ke-chi-muc-fulltext-cho-snapshot',
            'author' => 'Đặng Thùy Trâm',
            'saved_at' => '16/10/2026, 16:10',
            'version' => 'v2.0.1',
            'size' => '188 KB',
            'images' => 6,
            'reading_time' => '5 phút',
            'thumb_label' => 'Fulltext Index',
            'thumb_code' => 'FULLTEXT (title, body)' . "\n" . "MATCH(title, body) AGAINST('...')",
            'excerpt' => 'Đánh giá cách tìm kiếm dựa trên nội dung đã lưu, trả về đoạn trích có từ khóa được làm nổi bật và luôn gắn với đúng phiên bản snapshot.',
            'tags' => ['#Architecture', '#Databases', '#MySQL'],
            'snippet' => 'Tìm kiếm phải dựa trên nội dung đã lưu chứ không phải trang gốc, mỗi kết quả phải trỏ về đúng phiên bản snapshot tương ứng.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8890',
            'library_id' => 'LIB-EDU-2026',
            'library' => 'Tài liệu học tập',
            'title' => 'Cấu trúc dữ liệu và giải thuật: cây nhị phân cân bằng',
            'domain' => 'phptutorial.vn',
            'url' => 'https://phptutorial.vn/cay-nhi-phan-can-bang',
            'author' => 'TS. Nguyễn Văn Hùng',
            'saved_at' => '20/10/2026, 08:00',
            'version' => 'v1.0.0',
            'size' => '512 KB',
            'images' => 22,
            'reading_time' => '12 phút',
            'thumb_label' => 'Giáo trình',
            'thumb_code' => 'struct Node { Node *left, *right; }' . "\n" . 'rotateLeft(root);',
            'excerpt' => 'Trình bày trực quan các phép xoay trên cây AVL, điều kiện cân bằng và mức độ phức tạp của thao tác chèn, xóa.',
            'tags' => ['#Algorithms', '#CS'],
            'snippet' => 'Cây AVL duy trì chiều cao cân bằng ở mọi nút, nhờ vậy các phép tìm kiếm giữ được độ phức tạp logarithm.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8871',
            'library_id' => 'LIB-EDU-2026',
            'library' => 'Tài liệu học tập',
            'title' => 'Cơ sở dữ liệu quan hệ: chuẩn hóa lược đồ và phụ thuộc hàm',
            'domain' => 'techblog.dev',
            'url' => 'https://techblog.dev/chuan-hoa-luoc-do-phu-thuoc-ham',
            'author' => 'TS. Trương Minh Anh',
            'saved_at' => '18/10/2026, 10:35',
            'version' => 'v1.2.0',
            'size' => '276 KB',
            'images' => 4,
            'reading_time' => '8 phút',
            'thumb_label' => 'Giáo trình',
            'thumb_code' => 'F1 -> F2' . "\n" . 'Decompose(R, FDs) -> R1, R2, R3',
            'excerpt' => 'Phân tích các dạng chuẩn 1NF, 2NF, 3NF và cách phân rã lược đồ để tránh dữ liệu dư thừa trong lưu trữ tài liệu.',
            'tags' => ['#Databases', '#CS'],
            'snippet' => 'Chuẩn hóa giúp giảm dư thừa dữ liệu, đảm bảo mỗi thuộc tính phụ thuộc đầy đủ vào khoá chính của quan hệ.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8855',
            'library_id' => 'LIB-RND-2026',
            'library' => 'Nghiên cứu công nghệ',
            'title' => 'Kiến trúc lưu trữ tĩnh và vai trò của bản sao WARC dài hạn',
            'domain' => 'tapchicongnghe.vn',
            'url' => 'https://tapchicongnghe.vn/kien-truc-luu-tru-tinh-warc',
            'author' => 'PGS. TS. Bùi Thanh Sơn',
            'saved_at' => '17/10/2026, 11:20',
            'version' => 'v3.0.0',
            'size' => '1.84 MB',
            'images' => 31,
            'reading_time' => '14 phút',
            'thumb_label' => 'WARC Archive',
            'thumb_code' => 'WARC/1.1' . "\n" . 'Content-Type: application/http; msgtype=response',
            'excerpt' => 'Khảo sát cách đóng gói snapshot theo chuẩn WARC để bảo toàn cấu trúc phản hồi HTTP cùng toàn bộ tài nguyên nhúng.',
            'tags' => ['#Archive', '#SystemDesign'],
            'snippet' => 'Chuẩn WARC ghi lại cả mã trạng thái, tiêu đề và phần thân phản hồi, giúp bản lưu có thể tái dựng nguyên vẹn.',
            'offline' => true,
        ],
        [
            'id' => 'SNAP-8832',
            'library_id' => 'LIB-RND-2026',
            'library' => 'Nghiên cứu công nghệ',
            'title' => 'Đánh giá rủi ro liên kết hỏng và chính sách bảo tồn nguồn',
            'domain' => 'dev-insights.org',
            'url' => 'https://dev-insights.org/danh-gia-rui-ro-lien-ket-hong',
            'author' => 'TS. Lê Thu Trang',
            'saved_at' => '15/10/2026, 13:55',
            'version' => 'v2.3.0',
            'size' => '298 KB',
            'images' => 5,
            'reading_time' => '10 phút',
            'thumb_label' => 'Research',
            'thumb_code' => 'allowed_domains[]' . "\n" . 'verify_redirect_chain($url)',
            'excerpt' => 'Đề xuất chính sách kiểm tra danh sách tên miền được duyệt cho cả đích tải về lẫn chuỗi chuyển hướng.',
            'tags' => ['#Security', '#Archive', '#SystemDesign'],
            'snippet' => 'Mọi đích tải về, kể cả sau khi chuyển hướng, đều phải được kiểm tra lại với danh sách tên miền được duyệt.',
            'offline' => true,
        ],
    ];
}

/** @return array<int, array<string, mixed>> Tài khoản hiển thị trong bảng quản trị. */
function sample_users(): array
{
    return [
        ['name' => 'Lê Minh Tuấn', 'email' => 'user@demo.vn', 'role' => 'user', 'role_label' => 'Người dùng', 'status' => 'active', 'status_label' => 'Hoạt động', 'libraries' => 2, 'joined' => '12/09/2026'],
        ['name' => 'Trần Hoàng Nam', 'email' => 'admin@demo.vn', 'role' => 'admin', 'role_label' => 'Quản trị viên', 'status' => 'active', 'status_label' => 'Hoạt động', 'libraries' => 3, 'joined' => '01/09/2026'],
        ['name' => 'Nguyễn Thu Hà', 'email' => 'ha.nguyen@demo.vn', 'role' => 'user', 'role_label' => 'Người dùng', 'status' => 'active', 'status_label' => 'Hoạt động', 'libraries' => 1, 'joined' => '20/09/2026'],
        ['name' => 'Vũ Quang Minh', 'email' => 'minh.vu@demo.vn', 'role' => 'user', 'role_label' => 'Người dùng', 'status' => 'locked', 'status_label' => 'Bị khóa', 'libraries' => 0, 'joined' => '05/10/2026'],
        ['name' => 'Phạm Thùy Linh', 'email' => 'linh.pham@demo.vn', 'role' => 'user', 'role_label' => 'Người dùng', 'status' => 'active', 'status_label' => 'Hoạt động', 'libraries' => 1, 'joined' => '11/10/2026'],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 * Danh sách tên miền được phép lưu snapshot (allowlist).
 * Mọi đích tải về — kể cả sau khi chuyển hướng — đều phải nằm trong danh sách này.
 */
function sample_allowlist(): array
{
    return [
        ['domain' => 'phptutorial.vn', 'added_by' => 'Trần Hoàng Nam', 'date' => '12/09/2026', 'status' => 'active', 'status_label' => 'Đang hoạt động'],
        ['domain' => 'techblog.dev', 'added_by' => 'Trần Hoàng Nam', 'date' => '12/09/2026', 'status' => 'active', 'status_label' => 'Đang hoạt động'],
        ['domain' => 'tapchicongnghe.vn', 'added_by' => 'Lê Minh Tuấn', 'date' => '15/09/2026', 'status' => 'active', 'status_label' => 'Đang hoạt động'],
        ['domain' => 'dev-insights.org', 'added_by' => 'Lê Minh Tuấn', 'date' => '15/09/2026', 'status' => 'active', 'status_label' => 'Đang hoạt động'],
        ['domain' => 'themorningnews.org', 'added_by' => 'Trần Hoàng Nam', 'date' => '28/09/2026', 'status' => 'active', 'status_label' => 'Đang hoạt động'],
        ['domain' => 'old-forum.example.net', 'added_by' => 'Lê Minh Tuấn', 'date' => '04/10/2026', 'status' => 'pending', 'status_label' => 'Đang chờ duyệt'],
    ];
}

/** @return array<string, mixed> Số liệu tổng quan dùng thống nhất trên mọi trang. */
function sample_stats(): array
{
    return [
        'library_count' => 3,
        'article_count' => 48,
        'image_count' => 312,
        'broken_links' => 0,
        'storage_gb' => '2.4 GB',
        'personal_libraries' => 2,
        'shared_libraries' => 1,
    ];
}

/** @return array<int, array<string, string>> Nhật ký hoạt động gần đây (trang quản trị). */
function sample_activity(): array
{
    return [
        ['icon' => 'download_for_offline', 'text' => 'Lê Minh Tuấn lưu snapshot “Tìm hiểu kiến trúc PHP & MySQL trong lưu trữ Web tĩnh”.', 'time' => '21/10/2026, 14:28'],
        ['icon' => 'sync', 'text' => 'Hệ thống đồng bộ 14 ảnh cục bộ cho snapshot #SNAP-8941.', 'time' => '21/10/2026, 14:29'],
        ['icon' => 'person_add', 'text' => 'Trần Hoàng Nam mời Nguyễn Thu Hà vào thư viện “Nghiên cứu công nghệ”.', 'time' => '20/10/2026, 17:02'],
        ['icon' => 'verified_user', 'text' => 'Danh sách tên miền được duyệt đã được cập nhật thêm 1 tên miền.', 'time' => '20/10/2026, 09:15'],
        ['icon' => 'label', 'text' => 'Gắn thẻ #Architecture cho 3 bài viết trong thư viện “CNTT & Lập Trình”.', 'time' => '19/10/2026, 20:41'],
        ['icon' => 'error_outline', 'text' => 'Một yêu cầu lưu trang bị từ chối: URL không thuộc danh sách được duyệt.', 'time' => '19/10/2026, 11:33'],
    ];
}

/**
 * Tìm thư viện theo slug.
 *
 * @return array<string, mixed>|null
 */
function sample_find_library(string $slug): ?array
{
    foreach (sample_libraries() as $library) {
        if ($library['slug'] === $slug) {
            return $library;
        }
    }
    return null;
}

/**
 * Lấy danh sách bài viết của một thư viện.
 *
 * @return array<int, array<string, mixed>>
 */
function sample_articles_by_library(string $libraryId): array
{
    return array_values(array_filter(
        sample_articles(),
        static fn (array $article): bool => $article['library_id'] === $libraryId
    ));
}
