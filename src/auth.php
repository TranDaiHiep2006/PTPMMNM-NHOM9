<?php
/**
 * auth.php
 * -----------------------------------------------------------------------------
 * Lớp xác thực phiên (session-based) cho giao diện WebArchive.
 *
 * Trong phase UI này, danh sách tài khoản lấy từ config/demo_users.php.
 * Phase MySQL sau sẽ thay lời gọi demo_find_user() bằng truy vấn bảng `users`
 * mà giữ nguyên toàn bộ chữ ký hàm dưới đây.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/../config/demo_users.php';

/**
 * Khởi động phiên làm việc (idempotent).
 */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (PHP_SAPI === 'cli') {
        // Khi chạy kiểm tra bằng dòng lệnh, không dùng cookie phiên.
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

/**
 * Tài khoản đang đăng nhập, hoặc null nếu là khách.
 *
 * @return array<string, mixed>|null
 */
function current_user(): ?array
{
    auth_start_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    foreach (demo_users() as $user) {
        if ($user['id'] === $_SESSION['user_id']) {
            return $user;
        }
    }

    return null;
}

/**
 * Đăng nhập bằng email + mật khẩu.
 *
 * @return array{ok: bool, message: string}
 */
function login_attempt(string $email, string $password): array
{
    auth_start_session();

    $email = trim($email);
    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập đầy đủ email và mật khẩu.'];
    }

    $user = demo_find_user($email);
    if ($user === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản với email này.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'message' => 'Mật khẩu không đúng. Vui lòng thử lại.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    return ['ok' => true, 'message' => 'Đăng nhập thành công.'];
}

/**
 * Đăng xuất — xoá toàn bộ dữ liệu phiên và quay về trang chủ.
 */
function logout(): void
{
    auth_start_session();

    $_SESSION = [];

    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}

/**
 * Bắt buộc đăng nhập — khách chuyển hướng sang login.php.
 */
function require_login(): void
{
    auth_start_session();

    if (current_user() === null) {
        $target = $_SERVER['REQUEST_URI'] ?? 'libraries.php';
        header('Location: login.php?next=' . rawurlencode($target));
        exit;
    }
}

/**
 * Bắt buộc quyền quản trị — người dùng thường nhận trang thông báo tiếng Việt.
 */
function require_admin(): void
{
    require_login();

    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }
}

/**
 * Tên hiển thị của tài khoản đang đăng nhập (dùng trong header).
 */
function current_user_name(): string
{
    $user = current_user();
    return $user['name'] ?? 'Khách';
}

/**
 * Chữ viết tắt để hiển thị trong avatar tròn.
 */
function current_user_initials(): string
{
    $user = current_user();
    return $user['initials'] ?? 'KH';
}

/**
 * Vai trò hiển thị (Quản trị viên / Người dùng).
 */
function current_user_role_label(): string
{
    $user = current_user();
    return $user['role_label'] ?? 'Khách';
}

/**
 * Đường dẫn tương đối của trang hiện tại, dùng để đánh dấu menu đang hoạt động.
 */
function current_page(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '');
}
