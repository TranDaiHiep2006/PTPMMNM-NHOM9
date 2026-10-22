<?php
/**
 * demo_users.php
 * -----------------------------------------------------------------------------
 * TÀI KHOẢN DEMO — PLACEHOLDER THỜI GIAO DIỆN (UI PHASE).
 *
 * CẢNH BÁO: đây là dữ liệu GIẢ LẬP CHỈ DÙNG CHO GIAO DIỆN.
 * Ở phase MySQL sau, toàn bộ nội dung file này sẽ bị thay bằng bảng `users`
 * trong cơ sở dữ liệu và mật khẩu sẽ được xác thực bằng `password_verify()`
 * đọc từ CSDL. Không dùng bộ hash cố định này cho môi trường thật.
 *
 * Hash được sinh bằng `password_hash($mậtKhẩu, PASSWORD_DEFAULT)` (bcrypt).
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

/**
 * @return array<int, array<string, mixed>>
 * Danh sách tài khoản demo: email, mật khẩu (bản rõ để hiển thị trong UI),
 * hash đã mã hóa, vai trò và tên hiển thị.
 */
function demo_users(): array
{
    return [
        [
            'id' => 'USR-0001',
            'email' => 'user@demo.vn',
            'plain_password' => 'demo123',              // placeholder UI phase
            'password_hash' => '$2y$10$4agBBR6lumse3hLis.MpDud2TwZ046thGO1THpbJQr09puBq5ZsL2',
            'name' => 'Lê Minh Tuấn',
            'role' => 'user',
            'role_label' => 'Người dùng',
            'initials' => 'LT',
        ],
        [
            'id' => 'USR-0002',
            'email' => 'admin@demo.vn',
            'plain_password' => 'admin123',             // placeholder UI phase
            'password_hash' => '$2y$10$MsJrjQfRk.z797wV/F1I4usFH/KsG5oij7gRRfAKVIt7ciTtDpQoi',
            'name' => 'Trần Hoàng Nam',
            'role' => 'admin',
            'role_label' => 'Quản trị viên',
            'initials' => 'TN',
        ],
    ];
}

/**
 * Tìm tài khoản demo theo email (không phân biệt hoa thường).
 *
 * @return array<string, mixed>|null
 */
function demo_find_user(string $email): ?array
{
    foreach (demo_users() as $user) {
        if (strcasecmp($user['email'], trim($email)) === 0) {
            return $user;
        }
    }
    return null;
}
