<?php
/**
 * note.php — Hành động ghi chú trên MỘT phiên bản snapshot (P-READ).
 *
 * Trang này không vẽ giao diện: chỉ nhận POST, kiểm tra bảo mật rồi chuyển hướng
 * lại đúng phiên bản đang đọc. Ba hành động: add / update / delete.
 *
 * QUY TẮC SỞ HỮU ĐƯỢC CÀ CHẶT NGAY TRONG SQL:
 *   - Ai cũng xem được thì ai cũng THÊM được ghi chú (kể cả người xem).
 *   - Sửa và xoá chỉ dành cho TÁC GIẢ của chính ghi chú đó. Điều kiện
 *     `user_id = ?` nằm trong câu UPDATE/DELETE của wa_note_update() và
 *     wa_note_delete(), nên gửi POST thẳng bằng tay cũng không sửa/xoá được
 *     ghi chú của người khác.
 *   - Quản trị viên xoá được bất kỳ ghi chú nào, nhưng đó là ĐƯỜNG RIÊNG
 *     (wa_note_delete_by_admin) và không đi qua hàm của tác giả.
 *
 * Ghi chú thuộc về MỘT phiên bản duy nhất: mở phiên bản khác sẽ không thấy.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user       = current_user();
$userId     = (int) ($user['id'] ?? 0);
$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);
$action     = (string) ($_POST['action'] ?? 'add');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $backId = (int) ($_GET['id'] ?? $snapshotId);
    header('Location: ' . wa_url($backId > 0 ? 'reader.php?id=' . $backId : 'libraries.php'));
    exit;
}

/** Quay lại snapshot đang đọc kèm thông báo flash. */
$back = static function (int $id, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('reader.php?id=' . $id));
    exit;
};

/* --- CSRF --- */
if (!wa_csrf_check()) {
    $back($snapshotId, [
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ]);
}

/* --- Người dùng phải xem được thư viện chứa snapshot này --- */
$context = $snapshotId > 0 ? wa_reader_context($snapshotId) : ['status' => 'not_found'];

if ($context['status'] !== 'ok') {
    $back($snapshotId, [
        'type' => 'error',
        'text' => 'Không tìm thấy bản lưu này hoặc bạn không có quyền ghi chú.',
    ]);
}

/* ---------------------------------------------------------------------------
 * Thêm ghi chú — mọi người xem được thư viện đều thêm được.
 * ------------------------------------------------------------------------- */
if ($action === 'add') {
    $result = wa_note_add($snapshotId, $userId, (string) ($_POST['content'] ?? ''));

    $back($snapshotId, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ---------------------------------------------------------------------------
 * Sửa ghi chú — chỉ tác giả (điều kiện user_id nằm trong câu UPDATE).
 * ------------------------------------------------------------------------- */
if ($action === 'update') {
    $noteId  = (int) ($_POST['note_id'] ?? 0);
    $result  = wa_note_update($noteId, $userId, (string) ($_POST['content'] ?? ''));

    $back($snapshotId, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ---------------------------------------------------------------------------
 * Xoá ghi chú — tác giả dùng đường có điều kiện user_id; quản trị viên dùng
 * đường riêng. Cả hai đều trả về 403 khi không có quyền.
 * ------------------------------------------------------------------------- */
if ($action === 'delete') {
    $noteId = (int) ($_POST['note_id'] ?? 0);

    if ($noteId <= 0) {
        $back($snapshotId, ['type' => 'error', 'text' => 'Không xác định được ghi chú cần xoá.']);
    }

    // Quản trị viên: đường xoá riêng, không kiểm tra tác giả.
    if (wa_is_admin()) {
        $result = wa_note_delete_by_admin($noteId);
    } else {
        $result = wa_note_delete($noteId, $userId);
    }

    $back($snapshotId, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ---------------------------------------------------------------------------
 * Cập nhật trong trình đọc: ô sửa ghi chú cần trở lại chính phiên bản đó.
 * ------------------------------------------------------------------------- */
if ($action === 'edit') {
    $noteId  = (int) ($_POST['note_id'] ?? 0);
    $result  = wa_note_update($noteId, $userId, (string) ($_POST['content'] ?? ''));

    $back($snapshotId, [
        'type' => $result['ok'] ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

$back($snapshotId, ['type' => 'error', 'text' => 'Hành động không hợp lệ.']);