<?php
/**
 * tests/samples/chuyen-huong-ngoai.php
 * -----------------------------------------------------------------------------
 * CA THỬ NGHIỆM "CHUYỂN HƯỚNG RA NGOÀI" (P-FINAL / T3).
 *
 * Trang này trả về lệnh chuyển hướng HTTP 302 tới một ĐỊA CHỈ BỊ CHẶN
 * tuyệt đối: cloud metadata của môi trường đám mây (169.254.169.254).
 *
 * Kỳ vọng của công cụ lưu trữ:
 *   - Mỗi đích chuyển hướng đều phải được kiểm tra lại với CHÍNH SÁCH URL,
 *     KHÔNG chỉ địa chỉ ban đầu.
 *   - Vì vậy lần lưu này phải thất bại với mã trạng thái `error_blocked`,
 *     chứ KHÔNG phải thành công.
 *
 * Vì sao đích lại là cloud metadata?
 *   Từ V3 ĐỢT 3, tên miền KHÔNG còn cần quản trị viên duyệt — mọi URL công
 *   khai đều lưu được. Nên một chuyển hướng tới `example.com` vẫn hợp lệ và
 *   sẽ được tải. Để chứng minh được CHÍNH SÁCH chặn đích nguy hiểm, chuyển
 *   hướng này trỏ tới 169.254.169.254 — địa chỉ mà chốt an toàn chặn tuyệt
 *   đối ở MỌI lớp, kể cả khi nó được chèn vào giữa chuỗi chuyển hướng.
 *
 * Không có nội dung bài viết nào ở đây — mục đích chỉ là tạo ra một lệnh
 * chuyển hướng.
 * --------------------------------------------------------------------------- */

declare(strict_types=1);

header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8">'
   . '<title>Đang chuyển hướng ra ngoài</title></head><body>'
   . '<p>Trang này đang chuyển hướng tới một địa chỉ bị chặn.</p>'
   . '</body></html>';
