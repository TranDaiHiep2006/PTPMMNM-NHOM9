<?php
/**
 * refetch.php — Hành động "Lấy phiên bản mới" (P-READ).
 *
 * Trang này KHÔNG vẽ giao diện: nó chỉ nhận POST, kiểm tra bảo mật rồi chuyển
 * hướng lại trình đọc. Đây là nơi DUY NHẤT xử lý tải lại một URL đã lưu.
 *
 *   1. Bắt buộc đăng nhập (khách -> chuyển về login.php).
 *   2. CSRF: mọi POST phải kèm token hợp lệ của phiên.
 *   3. Phân quyền: chỉ chủ sở hữu / biên tập viên của thư viện (quản trị viên
 *      luôn được phép) mới tải lại được. Người xem bị từ chối ở BƯỚC NÀY —
 *      không phải chỉ ẩn nút trên giao diện.
 *   4. Dùng CHUNG đường lưu với save.php: wa_store_page_capture().
 *
 * Kết quả:
 *   - Thành công -> chuyển tới snapshot MỚI kèm thông báo tiếng Việt có số
 *     phiên bản (flash).
 *   - Thất bại  -> ở lại phiên bản hiện tại, hiện thông báo tiếng Việt RIÊNG
 *     cho mã lỗi đó. Bản ghi lỗi vẫn được ghi trong CSDL để thống kê.
 *
 * Phiên bản cũ (kể cả ghi chú của chúng) không bị đụng tới: đường lưu chung chỉ
 * INSERT thêm một dòng `snapshots` mới.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

// Chỉ nhận POST. GET vô nghĩa — đưa về trình đọc nếu có id, về thư viện nếu không.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $backId = (int) ($_GET['id'] ?? $_POST['snapshot_id'] ?? 0);
    header('Location: ' . wa_url($backId > 0 ? 'reader.php?id=' . $backId : 'libraries.php'));
    exit;
}

$user      = current_user();
$userId    = (int) ($user['id'] ?? 0);
$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);

/** Quay lại một snapshot cụ thể kèm thông báo flash. */
$readerRedirect = static function (int $id, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('reader.php?id=' . $id));
    exit;
};

/* ---------------------------------------------------------------------------
 * 1) CSRF — token thiếu/sai thì từ chối ngay, không tải trang.
 * ------------------------------------------------------------------------- */
if (!wa_csrf_check()) {
    $readerRedirect($snapshotId, [
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ]);
}

/* ---------------------------------------------------------------------------
 * 2) Phân quyền — phải là chủ sở hữu / biên tập viên (hoặc quản trị viên).
 * ------------------------------------------------------------------------- */
$context = $snapshotId > 0 ? wa_reader_context($snapshotId) : ['status' => 'not_found'];

if ($context['status'] !== 'ok') {
    $readerRedirect($snapshotId, [
        'type' => 'error',
        'text' => 'Không tìm thấy bản lưu này hoặc bạn không có quyền tải lại.',
    ]);
}

/** @var array<string,mixed> $article */
$article = $context['article'];

if ($context['can_refetch'] !== true) {
    $readerRedirect($snapshotId, [
        'type' => 'error',
        'text' => 'Bạn không có quyền tải lại trang này. Chỉ chủ sở hữu và biên tập viên của thư viện mới tải lại được.',
    ]);
}

/* ---------------------------------------------------------------------------
 * 3) Tải lại bằng ĐÚNG đường lưu dùng chung với public/save.php.
 * ------------------------------------------------------------------------- */
$sourceUrl = (string) $article['source_url'];

// Dùng source_url đã lưu trong CSDL (không nhận URL từ POST) — người dùng không
// thể dùng nút này để âm thầm lưu một trang khác vào thư viện.
$result = wa_store_page_capture(
    (int) $article['library_id'],
    $sourceUrl,
    wa_page_tags((int) $article['page_id']),
    $userId
);

if ($result['ok'] === true) {
    $imageCount = (int) $result['image_count'];

    $readerRedirect((int) $result['snapshot_id'], [
        'type' => 'success',
        'text' => 'Đã lấy phiên bản ' . (int) $result['version'] . ' mới cho “'
                  . (string) $result['title'] . '”'
                  . ($imageCount > 0
                      ? ' — tải thêm ' . $imageCount . ' ảnh về máy chủ nội bộ.'
                      : '.'),
        'snapshot_id' => (int) $result['snapshot_id'],
    ]);
}

/* ---------------------------------------------------------------------------
 * 4) Thất bại — bản ghi lỗi đã ghi trong hàm dùng chung; ở lại phiên bản hiện tại
 *    và hiện thông báo RIÊNG cho mã lỗi này.
 * ------------------------------------------------------------------------- */
$readerRedirect($snapshotId, [
    'type'  => 'error',
    'title' => (string) $result['error_title'],
    'text'  => (string) $result['error_message'],
    'code'  => (string) $result['error_code'],
]);