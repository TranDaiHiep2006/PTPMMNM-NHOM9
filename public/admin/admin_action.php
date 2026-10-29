<?php
/**
 * admin/admin_action.php — Điểm nhận DUY NHẤT cho mọi hành động quản trị
 * (người dùng, thư viện, tên miền, yêu cầu duyệt tên miền).
 *
 * Trang này KHÔNG vẽ giao diện: chỉ nhận POST, kiểm tra bảo mật rồi chuyển
 * hướng lại đúng trang quản trị đã gọi, kèm thông báo flash tiếng Việt.
 * Mọi hành động đều cần quyền quản trị viên (require_admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$action = (string) ($_POST['action'] ?? '');

/* ---------------------------------------------------------------------------
 * Chỉ nhận POST. GET vô nghĩa — đưa về trang tổng quan quản trị.
 * ------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . wa_url('admin/index.php'));
    exit;
}

/** Chuyển hướng về `admin/users.php` kèm thông báo flash. */
$backToUsers = static function (array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('admin/users.php'));
    exit;
};

/** Chuyển hướng về `admin/libraries.php` kèm thông báo flash. */
$backToLibs = static function (array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('admin/libraries.php'));
    exit;
};

/** Chuyển hướng về `admin/allowlist.php` kèm thông báo flash. */
$backToAllowlist = static function (array $flash, string $filter = ''): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('admin/allowlist.php' . ($filter !== '' ? '?filter=' . $filter : '')));
    exit;
};

/* ---------------------------------------------------------------------------
 * CSRF — kiểm tra TRƯỚC khi đọc/ghi bất cứ thứ gì vào CSDL.
 * ------------------------------------------------------------------------- */
if (!wa_csrf_check()) {
    $backToUsers([
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ]);
}

$user    = current_user();
$adminId = (int) ($user['id'] ?? 0);

/* ===========================================================================
 * NGƯỜI DÙNG — sửa họ tên + vai trò
 * ========================================================================= */
if ($action === 'user_update') {
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $name     = trim((string) ($_POST['name'] ?? ''));
    $role     = (string) ($_POST['role'] ?? '');

    $target = $targetId > 0 ? auth_find_user_by_id($targetId) : null;

    if ($target === null) {
        $backToUsers(['type' => 'error', 'text' => 'Không tìm thấy tài khoản cần sửa.']);
    }

    if ($name === '' || mb_strlen($name) > 150) {
        $backToUsers(['type' => 'error', 'text' => 'Họ và tên không được để trống và tối đa 150 ký tự.']);
    }

    if (!in_array($role, ['admin', 'user'], true)) {
        $backToUsers(['type' => 'error', 'text' => 'Vai trò không hợp lệ.']);
    }

    // Không tự đổi vai trò của chính mình: tránh mất tài khoản quản trị duy nhất.
    if ($targetId === $adminId && $role !== ($target['role'] ?? '')) {
        $backToUsers(['type' => 'error', 'text' => 'Không thể đổi vai trò của chính mình.']);
    }

    wa_user_update_admin($targetId, $name, $role);

    $backToUsers(['type' => 'success', 'text' => 'Đã cập nhật tài khoản “' . $name . '”.']);
}

/* ===========================================================================
 * NGƯỜI DÙNG — khoá / mở khoá (đảo trạng thái).
 * Tài khoản bị khoá đã bị chặn sẵn ở đăng nhập và ở phiên làm việc
 * (current_user trả về null khi status khác 'active').
 * ========================================================================= */
if ($action === 'user_lock') {
    $targetId = (int) ($_POST['user_id'] ?? 0);

    $target = $targetId > 0 ? auth_find_user_by_id($targetId) : null;

    if ($target === null) {
        $backToUsers(['type' => 'error', 'text' => 'Không tìm thấy tài khoản cần khoá.']);
    }

    if ($targetId === $adminId) {
        $backToUsers(['type' => 'error', 'text' => 'Không thể khoá chính mình.']);
    }

    $newStatus = ($target['status'] ?? 'active') === 'active' ? 'locked' : 'active';
    wa_user_set_status($targetId, $newStatus);

    $backToUsers(['type' => 'success', 'text' => $newStatus === 'locked'
        ? 'Đã khoá tài khoản “' . (string) $target['name'] . '”. Tài khoản này sẽ bị đăng xuất ngay.'
        : 'Đã mở khoá tài khoản “' . (string) $target['name'] . '”.']);
}

/* ===========================================================================
 * THƯ VIỆN — sửa tên, mô tả, mức hiển thị (quản trị viên qua được kiểm tra
 * quyền trong wa_library_update, giống mọi hành động quản lý thư viện khác).
 * ========================================================================= */
if ($action === 'library_update') {
    $libraryId   = (int) ($_POST['library_id'] ?? 0);
    $name        = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $visibility  = (string) ($_POST['visibility'] ?? '');

    if ($name === '' || !in_array($visibility, ['private', 'shared', 'public'], true)) {
        $backToLibs(['type' => 'error', 'text' => 'Tên thư viện không được để trống và mức hiển thị không hợp lệ.']);
    }

    $result = wa_library_update($libraryId, $name, $description, $visibility, $user);

    $backToLibs(['type' => $result['ok'] ? 'success' : 'error', 'text' => (string) $result['message']]);
}

/* ===========================================================================
 * THƯ VIỆN — xoá (cần gõ đúng DELETE; đường quản trị trong wa_library_delete
 * vẫn dọn cả bài viết, snapshot và thư mục ảnh như đường của chủ sở hữu).
 * ========================================================================= */
if ($action === 'library_delete') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);

    if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'DELETE') {
        $backToLibs(['type' => 'error', 'text' => 'Chưa xác nhận xoá. Vui lòng gõ đúng DELETE để xác nhận.']);
    }

    $result = wa_library_delete($libraryId, $user, true);

    $backToLibs(['type' => $result['ok'] ? 'success' : 'error', 'text' => (string) $result['message']]);
}

/* ===========================================================================
 * TÊN MIỀN — sửa tên miền và ghi chú
 * ========================================================================= */
if ($action === 'allowlist_update') {
    $entryId = (int) ($_POST['id'] ?? 0);
    $domain  = strtolower(rtrim(trim((string) ($_POST['domain'] ?? '')), '.'));
    $note    = trim((string) ($_POST['note'] ?? ''));

    $valid = (bool) preg_match(
        '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i',
        $domain
    );

    if (!$valid) {
        $backToAllowlist(['type' => 'error', 'text' => 'Tên miền không hợp lệ. Vui lòng nhập một tên miền dạng "tenmien.vn" hoặc "localhost".']);
    }

    $exists = db()->prepare('SELECT 1 FROM `allowlist_domains` WHERE `id` = ? LIMIT 1');
    $exists->execute([$entryId]);

    if ($exists->fetchColumn() === false) {
        $backToAllowlist(['type' => 'error', 'text' => 'Không tìm thấy tên miền cần sửa.']);
    }

    try {
        $stmt = db()->prepare('UPDATE `allowlist_domains` SET `domain` = ?, `note` = ? WHERE `id` = ?');
        $stmt->execute([$domain, $note !== '' ? $note : null, $entryId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $backToAllowlist(['type' => 'error', 'text' => 'Tên miền "' . $domain . '" đã có trong danh sách được duyệt.']);
        }

        throw $e;
    }

    $backToAllowlist(['type' => 'success', 'text' => 'Đã cập nhật tên miền "' . $domain . '".']);
}

/* ===========================================================================
 * TÊN MIỀN — xoá khỏi danh sách được duyệt
 * ========================================================================= */
if ($action === 'allowlist_delete') {
    $entryId = (int) ($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT `domain` FROM `allowlist_domains` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$entryId]);
    $row = $stmt->fetch();

    if ($row === false) {
        $backToAllowlist(['type' => 'error', 'text' => 'Không tìm thấy tên miền cần xoá.']);
    }

    $del = db()->prepare('DELETE FROM `allowlist_domains` WHERE `id` = ?');
    $del->execute([$entryId]);

    $backToAllowlist(['type' => 'success', 'text' => 'Đã xoá tên miền "' . (string) $row['domain'] . '" khỏi danh sách được duyệt.']);
}

/* ===========================================================================
 * YÊU CẦU DUYỆT — duyệt: thêm vào allowlist rồi dọn yêu cầu khỏi hàng chờ
 * ========================================================================= */
if ($action === 'request_approve') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $request   = $requestId > 0 ? wa_domain_request_get($requestId) : null;

    if ($request === null) {
        $backToAllowlist(['type' => 'error', 'text' => 'Không tìm thấy yêu cầu cần duyệt.'], 'pending');
    }

    $domain  = (string) $request['domain'];
    $already = false;

    foreach (wa_allowlist() as $entry) {
        if ($entry['domain'] === $domain) {
            $already = true;
            break;
        }
    }

    if (!$already) {
        $added = wa_allowlist_add($domain, (string) ($request['note'] ?? ''), $adminId);

        if ($added['ok'] !== true) {
            $backToAllowlist(['type' => 'error', 'text' => (string) $added['message']], 'pending');
        }
    }

    wa_domain_request_delete($requestId);

    $backToAllowlist(['type' => 'success', 'text' => $already
        ? 'Tên miền "' . $domain . '" đã có trong danh sách, yêu cầu đã được dọn.'
        : 'Đã duyệt tên miền "' . $domain . '".'], 'pending');
}

/* ===========================================================================
 * YÊU CẦU DUYỆT — từ chối: chỉ dọn yêu cầu khỏi hàng chờ
 * ========================================================================= */
if ($action === 'request_reject') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $request   = $requestId > 0 ? wa_domain_request_get($requestId) : null;

    if ($request === null) {
        $backToAllowlist(['type' => 'error', 'text' => 'Không tìm thấy yêu cầu cần từ chối.'], 'pending');
    }

    wa_domain_request_delete($requestId);

    $backToAllowlist(['type' => 'success', 'text' => 'Đã từ chối yêu cầu duyệt tên miền "' . (string) $request['domain'] . '".'], 'pending');
}

header('Location: ' . wa_url('admin/index.php'));
exit;
