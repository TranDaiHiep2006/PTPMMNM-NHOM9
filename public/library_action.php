<?php
/**
 * library_action.php — Điểm nhận DUY NHẤT cho mọi hành động quản lý thư viện
 * (P-LIB): tạo thư viện, đổi tên / đổi hiển thị, xoá thư viện, thêm / đổi vai
 * trò / gỡ thành viên, sửa nhãn của một trang đã lưu, xoá bài viết, xoá một
 * phiên bản và đổi tên phiên bản.
 *
 * Trang này KHÔNG vẽ giao diện: nó chỉ nhận POST, kiểm tra bảo mật rồi chuyển
 * hướng lại đúng trang đã gọi, kèm thông báo flash tiếng Việt. Tách kiểu này
 * giống hệt `refetch.php` và `note.php` của P-READ.
 *
 * THỨ TỰ KIỂM TRA (bắt buộc, giống mọi POST khác của dự án):
 *   1. require_login()                 — khách -> chuyển về login.php.
 *   2. wa_csrf_check()                  — token sai -> từ chối, KHÔNG ghi gì.
 *   3. Phân quyền trong chính hàm gọi   — được ép lẦN NỮA trong SQL.
 *
 * Quyền: mọi hành động ngoài "tạo thư viện" đều cần quyền quản lý thư viện
 * (chủ sở hữu hoặc quản trị viên). Người gửi POST trực tiếp (không qua giao
 * diện) mà không có quyền vẫn bị từ chối — và nếu họ không thuộc thư viện thì
 * nhận trang 403 tiếng Việt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$action = (string) ($_POST['action'] ?? '');

/* ---------------------------------------------------------------------------
 * Chỉ nhận POST. GET vô nghĩa — đưa về trang phù hợp.
 * ------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . wa_url($action === 'create' ? 'libraries.php' : 'libraries.php'));
    exit;
}

/** Chuyển hướng về `libraries.php` kèm thông báo flash. */
$backToList = static function (array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('libraries.php'));
    exit;
};

/** Chuyển hướng về trang chi tiết của một thư viện kèm thông báo flash. */
$backToLibrary = static function (string $slug, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('library.php?slug=' . urlencode($slug)));
    exit;
};

/** Chuyển hướng về trình đọc của một phiên bản kèm thông báo flash. */
$backToReader = static function (int $snapshotId, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('reader.php?id=' . $snapshotId));
    exit;
};

/**
 * Người gọi có quyền quản lý thư viện không — nếu KHÔNG thì trả 403 tiếng Việt.
 *
 * Trả về slug để nơi gọi chuyển hướng. `http_response_code(403)` + template là
 * đúng như yêu cầu "direct POSTs từ người không phải chủ sở hữu bị từ chối bằng
 * trang 403 tiếng Việt".
 */
$requireManager = static function (int $libraryId): string {
    $slug = '';

    if (wa_can_read_library($libraryId)) {
        $row = wa_library_by_id($libraryId);
        $slug = (string) ($row['slug'] ?? '');
    }

    if (!wa_can_manage_library($libraryId)) {
        http_response_code(403);
        $waDeniedContext = [
            'title' => 'Không có quyền quản lý thư viện này',
            'intro' => 'Chỉ chủ sở hữu của thư viện (hoặc quản trị viên hệ thống) mới đổi tên, '
                     . 'đổi mức hiển thị, xoá thư viện, quản lý thành viên hoặc sửa nhãn bài viết. '
                     . 'Tài khoản của bạn không có quyền này.',
        ];
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }

    return $slug;
};

/* ---------------------------------------------------------------------------
 * CSRF — kiểm tra TRƯỚC khi đọc/ghi bất cứ thứ gì vào CSDL.
 * ------------------------------------------------------------------------- */
if (!wa_csrf_check()) {
    $backToList([
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ]);
}

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

/* ===========================================================================
 * 1) TẠO THƯ VIỆN — libraries.php
 * ========================================================================= */
if ($action === 'create') {
    $result = wa_library_create(
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        (string) ($_POST['visibility'] ?? 'private'),
        (string) ($_POST['color'] ?? 'a'),
        (string) ($_POST['icon'] ?? 'folder_special'),
        $userId
    );

    if ($result['ok'] === true) {
        // Thông báo thành công bằng tiếng Việt rồi mở thẳng trang chi tiết
        // thư viện vừa tạo.
        $_SESSION['wa_flash'] = [
            'type' => 'success',
            'text' => (string) $result['message'],
        ];

        header('Location: ' . wa_url('library.php?slug=' . urlencode((string) $result['slug'])));
        exit;
    }

    // Lỗi: quay lại modal tạo thư viện kèm thông báo.
    $backToList(['type' => 'error', 'text' => (string) $result['message']]);
}

/* ===========================================================================
 * 2) ĐỔI TÊN + ĐỔI MỨC HIỂN THỊ — library.php (chủ sở hữu / quản trị viên)
 * ========================================================================= */
if ($action === 'update') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_library_update(
        $libraryId,
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        (string) ($_POST['visibility'] ?? 'private')
    );

    // Sau khi đổi tên, slug có thể đã đổi theo -> dùng slug MỚI trả về.
    $targetSlug = (string) ($result['slug'] ?? $slug);

    // Sửa từ trang danh sách (modal trên libraries.php) thì ở lại danh sách.
    if ((string) ($_POST['return_to'] ?? '') === 'list') {
        $backToList([
            'type' => $result['ok'] === true ? 'success' : 'error',
            'text' => (string) $result['message'],
        ]);
    }

    $backToLibrary($targetSlug !== '' ? $targetSlug : $slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 3) XOÁ THƯ VIỆN — CHỈ CHỦ SỞ HỮU, có bước xác nhận bằng từ DELETE
 *    Xoá cả dòng CSDL lẫn thư mục ảnh public/uploads/snapshots/<id>/.
 * ========================================================================= */
if ($action === 'delete') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $row = wa_library_by_id($libraryId);

    if ($row === null) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy thư viện cần xoá.']);
    }

    // BƯỚC XÁC NHẬN RÕ RÀNG: người dùng phải gõ đúng từ DELETE vào ô xác
    // nhận (JS đã kiểm ở client, kiểm lại ở đây vì JS có thể bị qua mặt).
    // Không có từ này thì không xoá — tránh xoá nhầm thư viện chỉ với một
    // cú bấm. (Trước đây backend đòi confirm=1 trong khi form gửi từ xác
    // nhận, nên nút xoá không bao giờ xoá được — đã sửa theo từ mới.)
    if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'DELETE') {
        if ((string) ($_POST['return_to'] ?? '') === 'list') {
            $backToList([
                'type' => 'error',
                'text' => 'Chưa xác nhận xoá. Vui lòng gõ đúng DELETE để xác nhận.',
            ]);
        }
        $backToLibrary((string) $row['slug'], [
            'type' => 'error',
            'text' => 'Chưa xác nhận xoá. Vui lòng gõ đúng DELETE để xác nhận.',
        ]);
    }

    $result = wa_library_delete($libraryId);

    if ($result['ok'] === true) {
        // Thư viện đã mất -> về danh sách kèm thông báo (nêu cả phần dọn ảnh).
        $backToList([
            'type' => 'success',
            'text' => (string) $result['message']
                . ' Đã dọn ' . (int) $result['dirs'] . ' thư mục ảnh ('
                . (int) $result['files'] . ' tệp) trên máy chủ.',
        ]);
    }

    // Không xoá được (ví dụ không phải chủ sở hữu) -> quay lại trang thư viện.
    $backToLibrary((string) $row['slug'], [
        'type' => 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 4) THÊM THÀNH VIÊN THEO EMAIL — vai trò editor hoặc viewer
 * ========================================================================= */
if ($action === 'member_add') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_member_add(
        $libraryId,
        trim((string) ($_POST['email'] ?? '')),
        (string) ($_POST['role'] ?? 'viewer')
    );

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 5) ĐỔI VAI TRÒ THÀNH VIÊN — editor <-> viewer (dòng owner bị chặn trong SQL)
 * ========================================================================= */
if ($action === 'member_role') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_member_update_role(
        $libraryId,
        (int) ($_POST['user_id'] ?? 0),
        (string) ($_POST['role'] ?? 'viewer')
    );

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 6) GỠ THÀNH VIÊN (dòng owner bị chặn trong SQL)
 * ========================================================================= */
if ($action === 'member_remove') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_member_remove($libraryId, (int) ($_POST['user_id'] ?? 0));

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 7) SỬA NHÃN CỦA MỘT TRANG ĐÃ LƯU (thay toàn bộ bộ nhãn của trang đó)
 * ========================================================================= */
if ($action === 'tags') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $pageId = (int) ($_POST['page_id'] ?? 0);

    // Tách chuỗi dấu phẩy rồi chuẩn hoá tên + bỏ dấu # thừa.
    $result = wa_page_tags_replace(
        $pageId,
        wa_parse_tag_input((string) ($_POST['tags'] ?? ''))
    );

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * ĐÁNH DẤU / BỎ ĐÁNH DẤU YÊU THÍCH — libraries.php (mọi người đọc được)
 * Đảo trạng thái dòng (user_id, library_id) trong bảng library_stars rồi về
 * lại đúng tab lọc đang xem. Bảng chưa migrate thì báo rõ thay vì lỗi trắng.
 * ========================================================================= */
if ($action === 'star') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);

    if (!wa_can_read_library($libraryId)) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy thư viện cần đánh dấu.']);
    }

    $filter = (string) ($_POST['filter'] ?? 'all');
    if (!in_array($filter, ['all', 'personal', 'shared', 'mine_shared'], true)) {
        $filter = 'all';
    }
    $backToFilteredList = static function (array $flash) use ($filter): never {
        $_SESSION['wa_flash'] = $flash;
        header('Location: ' . wa_url('libraries.php' . ($filter === 'all' ? '' : '?filter=' . urlencode($filter))));
        exit;
    };

    try {
        $exists = db()->prepare('SELECT 1 FROM library_stars WHERE user_id = ? AND library_id = ? LIMIT 1');
        $exists->execute([$userId, $libraryId]);
        if ($exists->fetchColumn() !== false) {
            $del = db()->prepare('DELETE FROM library_stars WHERE user_id = ? AND library_id = ?');
            $del->execute([$userId, $libraryId]);
            $backToFilteredList(['type' => 'success', 'text' => 'Đã bỏ đánh dấu yêu thích.']);
        }
        $ins = db()->prepare('INSERT INTO library_stars (user_id, library_id) VALUES (?, ?)');
        $ins->execute([$userId, $libraryId]);
        $backToFilteredList(['type' => 'success', 'text' => 'Đã đánh dấu yêu thích.']);
    } catch (Throwable) {
        $backToFilteredList(['type' => 'error', 'text' => 'Chưa chạy migrate bảng library_stars. Hãy chạy php scripts/migrate_library_stars.php rồi thử lại.']);
    }
}

/* ===========================================================================
 * XOÁ BÀI VIẾT — library.php (chủ sở hữu / quản trị viên)
 * mode=all: xoá toàn bộ bài cùng mọi phiên bản. mode=selected: chỉ xoá các
 * phiên bản đã tích; nếu bài không còn snapshot nào thì bài cũng biến mất.
 * Xác nhận bằng từ DELETE giống xoá thư viện. Về lại trang thư viện.
 * ========================================================================= */
if ($action === 'page_delete') {
    $pageId = (int) ($_POST['page_id'] ?? 0);

    $pageRow = db()->prepare('SELECT library_id FROM saved_pages WHERE id = ? LIMIT 1');
    $pageRow->execute([$pageId]);
    $page = $pageRow->fetch();
    if ($page === false) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy bài viết cần xoá.']);
    }
    $slug = $requireManager((int) $page['library_id']);

    if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'DELETE') {
        $backToLibrary($slug, [
            'type' => 'error',
            'text' => 'Chưa xác nhận xoá. Vui lòng gõ đúng DELETE để xác nhận.',
        ]);
    }

    $mode = (string) ($_POST['mode'] ?? '');
    if ($mode === 'selected') {
        $wanted = array_values(array_unique(array_map('intval', (array) ($_POST['snapshot_ids'] ?? []))));
        if ($wanted === []) {
            $backToLibrary($slug, ['type' => 'error', 'text' => 'Chưa chọn phiên bản nào để xoá.']);
        }
        // Chỉ xoá snapshot thuộc đúng bài này (chống POST tay lệch bài).
        $placeholders = implode(',', array_fill(0, count($wanted), '?'));
        $own = db()->prepare("SELECT id FROM snapshots WHERE page_id = ? AND id IN ({$placeholders})");
        $own->execute(array_merge([$pageId], $wanted));
        $ownedIds = array_column($own->fetchAll(), 'id');
        if ($ownedIds === []) {
            $backToLibrary($slug, ['type' => 'error', 'text' => 'Không tìm thấy phiên bản nào thuộc bài viết này.']);
        }
        wa_delete_snapshots($ownedIds);

        $left = db()->prepare('SELECT COUNT(*) FROM snapshots WHERE page_id = ?');
        $left->execute([$pageId]);
        if ((int) $left->fetchColumn() === 0) {
            $delPage = db()->prepare('DELETE FROM saved_pages WHERE id = ?');
            $delPage->execute([$pageId]);
            $backToLibrary($slug, ['type' => 'success', 'text' => 'Đã xoá bài viết cùng toàn bộ phiên bản.']);
        }
        $backToLibrary($slug, ['type' => 'success', 'text' => 'Đã xoá ' . count($ownedIds) . ' phiên bản.']);
    }

    if ($mode !== 'all') {
        $backToLibrary($slug, ['type' => 'error', 'text' => 'Chưa chọn cách xoá bài viết.']);
    }

    wa_delete_page($pageId);
    $backToLibrary($slug, ['type' => 'success', 'text' => 'Đã xoá bài viết cùng toàn bộ phiên bản.']);
}

/* ===========================================================================
 * XOÁ MỘT PHIÊN BẢN — lịch sử phiên bản trong reader.php (chủ / quản trị)
 * Xoá xong: nếu bản đang xem vẫn còn thì về lại nó, nếu không thì về bản
 * mới nhất còn lại; bài không còn bản đọc được thì về trang thư viện (nếu
 * không còn snapshot nào thì bài cũng biến mất, nếu chỉ còn bản lỗi thì bài
 * ở lại dạng lưu thất bại).
 * ========================================================================= */
if ($action === 'version_delete') {
    $snapshotId = (int) ($_POST['snapshot_id'] ?? 0);
    $currentId  = (int) ($_POST['current_id'] ?? 0);

    $snapRow = db()->prepare(
        'SELECT s.page_id, p.library_id FROM snapshots s ' .
        'JOIN saved_pages p ON p.id = s.page_id WHERE s.id = ? LIMIT 1'
    );
    $snapRow->execute([$snapshotId]);
    $snap = $snapRow->fetch();
    if ($snap === false) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy phiên bản cần xoá.']);
    }
    $slug = $requireManager((int) $snap['library_id']);
    $pageId = (int) $snap['page_id'];

    wa_delete_snapshots([$snapshotId]);

    // Bản đang xem còn nguyên thì về lại nó.
    if ($currentId > 0 && $currentId !== $snapshotId) {
        $still = db()->prepare("SELECT 1 FROM snapshots WHERE id = ? AND fetch_status = 'success' LIMIT 1");
        $still->execute([$currentId]);
        if ($still->fetchColumn() !== false) {
            $backToReader($currentId, ['type' => 'success', 'text' => 'Đã xoá phiên bản.']);
        }
    }

    // Không thì về bản thành công mới nhất còn lại.
    $newest = db()->prepare(
        "SELECT id FROM snapshots WHERE page_id = ? AND fetch_status = 'success' " .
        'ORDER BY version_no DESC, id DESC LIMIT 1'
    );
    $newest->execute([$pageId]);
    $newestId = $newest->fetchColumn();
    if ($newestId !== false) {
        $backToReader((int) $newestId, ['type' => 'success', 'text' => 'Đã xoá phiên bản.']);
    }

    // Hết bản đọc được: không còn snapshot nào thì xoá luôn bài.
    $anyLeft = db()->prepare('SELECT COUNT(*) FROM snapshots WHERE page_id = ?');
    $anyLeft->execute([$pageId]);
    if ((int) $anyLeft->fetchColumn() === 0) {
        $delPage = db()->prepare('DELETE FROM saved_pages WHERE id = ?');
        $delPage->execute([$pageId]);
    }
    $backToLibrary($slug, ['type' => 'success', 'text' => 'Đã xoá phiên bản. Bài viết không còn bản đọc được.']);
}

/* ===========================================================================
 * ĐỔI TÊN PHIÊN BẢN — lịch sử phiên bản trong reader.php (chủ / quản trị)
 * Tên lưu ở cột snapshots.label (tối đa 100 ký tự); để trống thì gỡ tên.
 * Cột chưa migrate thì báo rõ thay vì lỗi trắng. Về lại đúng phiên bản đó.
 * ========================================================================= */
if ($action === 'version_rename') {
    $snapshotId = (int) ($_POST['snapshot_id'] ?? 0);
    $label = trim((string) ($_POST['label'] ?? ''));
    if (mb_strlen($label) > 100) {
        $label = mb_substr($label, 0, 100, 'UTF-8');
    }

    $snapRow = db()->prepare(
        'SELECT s.page_id, p.library_id FROM snapshots s ' .
        'JOIN saved_pages p ON p.id = s.page_id WHERE s.id = ? LIMIT 1'
    );
    $snapRow->execute([$snapshotId]);
    $snap = $snapRow->fetch();
    if ($snap === false) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy phiên bản cần đổi tên.']);
    }
    $requireManager((int) $snap['library_id']);

    try {
        $upd = db()->prepare('UPDATE `snapshots` SET `label` = ? WHERE id = ?');
        $upd->execute([$label === '' ? null : $label, $snapshotId]);
    } catch (Throwable) {
        $backToReader($snapshotId, ['type' => 'error', 'text' => 'Chưa chạy migrate cột snapshots.label. Hãy chạy php scripts/migrate_snapshot_labels.php rồi thử lại.']);
    }
    $backToReader($snapshotId, ['type' => 'success', 'text' => $label === '' ? 'Đã gỡ tên phiên bản.' : 'Đã đổi tên phiên bản.']);
}

/* ---------------------------------------------------------------------------
 * Hành động không hợp lệ.
 * ------------------------------------------------------------------------- */
$backToList(['type' => 'error', 'text' => 'Hành động không hợp lệ.']);