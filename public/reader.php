<?php
/**
 * reader.php — Trình đọc ngoại tuyến, không gian làm việc theo phiên bản (P-READ).
 *
 * Bám theo bản tham chiếu (code3.html): nền giấy ngà ivory, cột đọc tối đa 780px,
 * thanh công cụ có nút A- / A+ (JavaScript thuần), tiến độ đọc, hộp nguồn gốc,
 * một bài viết tiếng Việt đầy đủ và bảng tổng hợp phương pháp làm sạch.
 *
 * PHẦN THÊM Ở P-READ (mọi dữ liệu đều lấy từ CSDL, không có gì hard-code):
 *   - Đầu trang nguồn gốc (provenance): tiêu đề bài, tên thư viện + link quay lại,
 *     URL nguồn (hiện dạng chữ VÀ dạng liên kết ngoài), tên miền, nhãn
 *     "Phiên bản N" cùng mã SNAP-<id>, thời điểm tải định dạng kiểu Việt Nam và
 *     mã HTTP của lần tải đó.
 *   - Bộ chuyển phiên bản: danh sách TẤT CẢ bản thành công của cùng trang, sắp
 *     xếp version_no giảm dần, kèm thời điểm tải; bản đang xem được tô sáng.
 *     Có thêm liên kết phiên bản trước / sau khi tồn tại.
 *   - Nút "Lấy phiên bản mới": CHỈ hiện với chủ sở hữu / biên tập viên của thư
 *     viện (quản trị viên luôn thấy). POST sang public/refetch.php.
 *   - Bảng ghi chú của ĐÚNG snapshot này, kèm biểu mẫu thêm cho mọi người xem
 *     được thư viện; nút sửa/xoá chỉ hiện với tác giả (quản trị viên xoá được
 *     mọi ghi chú).
 *
 * PHÂN QUYỀN:
 *   - Khách            -> chuyển hướng login.php (require_login).
 *   - Đã đăng nhập nhưng không thuộc thư viện -> trang 403 tiếng Việt.
 *   - Snapshot không tồn tại hoặc fetch_status <> 'success' -> 404.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
// A9: bộ đếm tài nguyên cục bộ còn trên đĩa (thay nhãn "Ngoại tuyến 100%").
require_once __DIR__ . '/../templates/wa_metrics.php';

require_login();

// reader.php nhận id snapshot dạng số: ?id=<snapshot id>
$snapshotId = (int) ($_GET['id'] ?? 0);

/** Nhãn + mã lỗi tiếng Việt riêng cho mỗi nguyên nhân. */
$readerErrorTitles = [
    'error_invalid_url' => 'URL không hợp lệ',
    'error_blocked'     => 'URL không an toàn để tải',
    'error_404'         => 'Trang gốc trả về 404',
    'error_http'        => 'Máy chủ nguồn trả về lỗi',
    'error_timeout'     => 'Hết thời gian chờ khi tải trang',
    'error_no_content'  => 'Trang không có nội dung đọc được',
];

/** Flash do refetch.php / note.php gửi lại sau khi chuyển hướng. */
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

// --- Giải quyết ngữ cảnh: snapshot -> trang -> thư viện, kiểm tra quyền ---
$context = $snapshotId > 0 ? wa_reader_context($snapshotId) : ['status' => 'not_found'];

/* ---------------------------------------------------------------------------
 * 403 — đã đăng nhập nhưng KHÔNG phải thành viên của thư viện chứa snapshot.
 * ------------------------------------------------------------------------- */
if ($context['status'] === 'denied') {
    http_response_code(403);
    $waDeniedContext = [
        'title' => 'Bản lưu này thuộc thư viện khác',
        'intro' => 'Bản lưu bạn muốn mở nằm trong một thư viện mà bạn không phải là thành viên. '
                  . 'Chỉ chủ sở hữu, biên tập viên, người xem của thư viện đó hoặc quản trị viên mới đọc được.',
    ];
    require __DIR__ . '/../templates/error_no_permission.php';
    exit;
}

/* ---------------------------------------------------------------------------
 * 404 — không tồn tại, không phải bản thành công, hoặc không đủ quyền đọc.
 * ------------------------------------------------------------------------- */
if ($context['status'] !== 'ok') {
    http_response_code(404);
    $pageTitle = 'Không tìm thấy bản lưu';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy bản lưu</h3>
      <p>Bản lưu này không tồn tại hoặc bạn không có quyền truy cập.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

/** @var array<string,mixed> $article */
$article       = $context['article'];
$librarySlug  = (string) $article['library_slug'];
$libraryName  = (string) $article['library_name'];
$libraryUrl   = wa_url('library.php?slug=' . urlencode($librarySlug));
$versions     = $context['versions'];
$prevVersion  = $context['prev'];
$nextVersion  = $context['next'];
$notes        = $context['notes'];
$canRefetch   = (bool) $context['can_refetch'];
$versionNo    = (int) $article['version_no'];
$httpStatus   = (int) $article['http_status'];
$currentUser  = current_user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$isAdmin      = wa_is_admin();
$roleLabels   = wa_member_role_labels();
$role         = (string) $context['role'];
// Xoá / đổi tên phiên bản: chỉ chủ sở hữu hoặc quản trị viên (giống mọi
// hành động khác của library_action.php ngoài tạo thư viện).
$canManageReader = $role === 'owner' || $isAdmin;

// P-CHECK: cờ kiểm tra bản mới (thay đổi ở trang gốc, từ lần kiểm tra trước).
$hasUpdate = (bool) ($context['has_update'] ?? false);
$checkedAt = (string) ($context['checked_at'] ?? '');

// V3 / FEATURE 3 — trạng thái đọc + tiến độ đọc của CHÍNH tài khoản này.
$readingState = wa_reading_state($currentUserId, (int) $article['id']);
$readingStatus = (string) ($readingState['status'] ?? 'unread');
$readingProgress = (int) ($readingState['progress'] ?? 0);
$readingInQueue = wa_reading_queue_map($currentUserId, [(int) $article['id']])[(int) $article['id']] ?? false;

// V3 / FEATURE 4 — đánh dấu (bôi đen) của chính tài khoản này trên snapshot này.
$annotations = wa_annotation_list((int) $article['id'], $currentUserId);

// V3 / FEATURE 2 — trích dẫn tài liệu (APA + thông thường).
$citationSnapshot = [
    'id'         => (int) $article['id'],
    'title'      => (string) $article['title'],
    'source_url' => (string) $article['source_url'],
    'version_no' => $versionNo,
    'fetched_at' => (string) $article['fetched_at'],
    'site_name'  => (string) $article['domain'],
    // Tác giả / ngày xuất bản không có sẵn trong CSDL -> để trống, hàm dựng
    // trích dẫn sẽ dùng nhãn quy ước thay vì bịa.
    'author_name' => '',
    'published_at' => '',
];
$citationApa   = wa_citation_build($citationSnapshot, 'apa');
$citationPlain = wa_citation_build($citationSnapshot, 'plain');

// V3 / FEATURE 8 — CHIA SẺ snapshot: danh sách link + link vừa tạo (chỉ hiện 1 lần).
$shareLinks = wa_share_list((int) $article['id']);
$canShare   = wa_share_can_manage((int) $article['id']);
$newShareLink = null;
if (isset($_SESSION['wa_share_new_link']) && is_array($_SESSION['wa_share_new_link'])) {
    $newShareLink = $_SESSION['wa_share_new_link'];
    unset($_SESSION['wa_share_new_link']);
}

// A9: số tài nguyên cục bộ CÒN trên đĩa của bản lưu đang mở (đo thật).
$snapshotAssets = wa_metrics_snapshot_assets((int) $article['id']);

// V3 ĐỢT 4 / F10 — các ĐOẠN NỘI DUNG đang được theo dõi trong bài này.
$watches = wa_watch_list_for_page((int) $context['page_id'], $currentUserId);

// V3 ĐỢT 4 / F11 — QUAN HỆ với các bài viết khác + dữ liệu sơ đồ quan hệ.
$relations       = wa_relation_list_for_page((int) $context['page_id']);
$relationTypes   = wa_relation_type_meta();
$relationGraph   = wa_relation_graph_data((int) $context['page_id']);
$canWriteRelation = wa_relation_can_write((int) $context['page_id']);
$relationIncoming = wa_relation_list_incoming((int) $context['page_id']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= wa_e($article['title']) ?> — Trình đọc WebArchive</title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
<style>
    /* ---- Bố cục P-READ: đầu nguồn gốc, chuyển phiên bản, bảng ghi chú ---- */
    .reader__col { max-width: 960px; }

    /* Khung phiên bản: KHÔNG dùng overflow:hidden (nó cắt menu 3 chấm xổ
       xuống khỏi dòng cuối); bo góc đầu khung bằng chính phần head. */
    .wa-prov { border:1px solid var(--wa-border); border-radius:12px; background:#fff; margin-bottom:20px; }
    .wa-prov__head { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;
                     padding:10px 14px; background:linear-gradient(180deg,#F7FAFC,#EEF3F8); border-bottom:1px solid var(--wa-border);
                     border-radius:12px 12px 0 0; }
    .wa-prov__lib  { display:inline-flex; align-items:center; gap:6px; font-weight:600; color:var(--wa-primary); text-decoration:none; font-size:13px; }
    .wa-prov__lib:hover { text-decoration:underline; }
    .wa-prov__body { padding:14px; display:grid; gap:10px; }
    .wa-prov__row  { display:grid; grid-template-columns:150px 1fr; gap:10px; align-items:start; font-size:13px; }
    .wa-prov__key  { color:#74777E; font-weight:500; }
    .wa-prov__val  { color:var(--wa-text); word-break:break-word; }
    .wa-prov__url  { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; color:var(--wa-text-soft); }
    .wa-prov__ext  { display:inline-flex; align-items:center; gap:4px; font-size:12px; color:var(--wa-action); text-decoration:none; font-weight:500; }
    .wa-prov__ext:hover { text-decoration:underline; }

    .wa-ver__chip { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px;
                    background:var(--wa-teal-soft,#E3F4F1); color:var(--wa-teal-deep); font-weight:600; font-size:12px; }

    /* Danh sách phiên bản: bỏ overflow:hidden để menu 3 chấm của dòng cuối
       không bị cắt; bo góc lại bằng chính dòng đầu/cuối. */
    .wa-ver__list { list-style:none; margin:12px 0 0; padding:0; border:1px solid var(--wa-border); border-radius:10px; }
    .wa-ver__item { display:flex; align-items:stretch; }
    .wa-ver__item + .wa-ver__item { border-top:1px solid var(--wa-border); }
    .wa-ver__link { flex:1; min-width:0; display:flex; gap:10px; align-items:center; justify-content:space-between; padding:9px 12px;
                    font-size:13px; color:var(--wa-text); text-decoration:none; background:#fff; }
    .wa-ver__link:hover { background:#F5F7F9; }
    .wa-ver__link.is-current { background:#EAF0F8; font-weight:600; box-shadow:inset 3px 0 0 var(--wa-action); }
    .wa-ver__item:first-child .wa-ver__link { border-top-left-radius:10px; border-top-right-radius:10px; }
    .wa-ver__item:last-child .wa-ver__link { border-bottom-left-radius:10px; border-bottom-right-radius:10px; }
    /* Menu 3 chấm trên từng dòng phiên bản (xoá / đổi tên phiên bản). */
    .wa-ver__menu { position:relative; display:flex; align-items:center; padding-right:8px; }
    .wa-ver__menu-list { position:absolute; right:8px; top:calc(100% + 4px); z-index:30; min-width:200px; background:#fff;
                         border:1px solid var(--wa-border); border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.12);
                         padding:4px; display:flex; flex-direction:column; }
    .wa-ver__menu-list[hidden] { display:none; }
    .wa-ver__menu-item { display:flex; align-items:center; gap:8px; width:100%; text-align:left; background:none; border:0;
                         cursor:pointer; padding:8px 10px; border-radius:8px; font-size:13px; color:var(--wa-text); text-decoration:none; }
    .wa-ver__menu-item:hover { background:#F1F5F9; }
    .wa-ver__num  { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:var(--wa-action); }
    .wa-ver__meta { color:#74777E; font-size:12px; }
    .wa-ver__nav  { display:flex; gap:8px; margin-top:10px; }

    .wa-notes { border:1px solid var(--wa-border); border-radius:12px; background:#fff; padding:14px; margin-top:24px; }
    .wa-notes__item { border:1px solid var(--wa-border); border-radius:10px; padding:10px 12px; background:#FCFDFE; }
    .wa-notes__item + .wa-notes__item { margin-top:10px; }
    .wa-notes__meta { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between; font-size:12px; }
    .wa-notes__author { font-weight:600; color:var(--wa-primary); }
    .wa-notes__time   { color:#74777E; }
    .wa-notes__text   { margin:8px 0 0; font-size:14px; line-height:1.7; color:var(--wa-text); white-space:pre-wrap; }
    .wa-notes__acts   { display:flex; gap:6px; margin-top:8px; }
    .wa-notes form { display:inline; }

    .wa-editor { display:none; margin-top:10px; }
    .wa-editor.is-open { display:block; }
    .wa-editor textarea { width:100%; min-height:88px; font:inherit; font-size:14px; padding:8px 10px;
                          border:1px solid var(--wa-border); border-radius:8px; resize:vertical; }
    .wa-editor__row { display:flex; gap:8px; margin-top:8px; }

    .wa-flash { border-radius:10px; padding:10px 12px; font-size:13px; margin-bottom:16px;
                display:flex; gap:8px; align-items:flex-start; }
    .wa-flash--ok   { background:#E7F6F3; border:1px solid #BFE5DE; color:#14655C; }
    .wa-flash--err  { background:#FDECEC; border:1px solid #F3C9C9; color:#8A2B2B; }
    .wa-banner { border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px;
                 display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; }
    .wa-banner--update { background:#FEF3C7; border:1px solid #F5D67B; color:#6B4E06; }
    .wa-banner__actions { display:flex; gap:8px; flex-wrap:wrap; }

    /* ---- V3 ĐỢT 4 / F10: menu nổi khi bôi đen đoạn văn bản ---- */
    .wa-selmenu { position:absolute; z-index:80; display:none; gap:6px; align-items:center;
                  background:#fff; border:1px solid var(--wa-border); border-radius:10px;
                  box-shadow:0 8px 24px rgba(0,0,0,.16); padding:6px 8px; }
    .wa-selmenu.is-open { display:flex; }
    .wa-selmenu__label { font-size:12px; color:var(--wa-text-muted); max-width:320px;
                         overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

    /* ---- V3 ĐỢT 4 / F11: hộp tìm bài + sơ đồ quan hệ ---- */
    .wa-relsearch { display:flex; flex-direction:column; gap:8px; }
    .wa-relsearch__list { max-height:280px; overflow:auto; border:1px solid var(--wa-border);
                          border-radius:10px; }
    .wa-relsearch__item { display:flex; gap:10px; align-items:flex-start; width:100%; text-align:left;
                           background:#fff; border:0; cursor:pointer; padding:9px 12px; font-size:13px;
                           color:var(--wa-text); }
    .wa-relsearch__item + .wa-relsearch__item { border-top:1px solid var(--wa-border); }
    .wa-relsearch__item:hover { background:#F5F7F9; }
    .wa-relsearch__item.is-picked { background:#EAF0F8; }
    .wa-relsearch__empty { padding:14px; font-size:13px; color:var(--wa-text-muted); }
    .wa-graph { width:100%; height:auto; max-height:520px; border:1px solid var(--wa-border);
                border-radius:10px; background:#FCFDFE; }
    .wa-graph__node { cursor:pointer; }
    .wa-graph__node circle { fill:#EAF0F8; stroke:var(--wa-action); stroke-width:1.5; }
    .wa-graph__node.is-self circle { fill:var(--wa-teal-soft,#E3F4F1); stroke:var(--wa-teal-deep); stroke-width:2.5; }
    .wa-graph__node text { font-size:10px; fill:var(--wa-text); }
    .wa-graph__edge { stroke:#9AA7B4; stroke-width:1.4; }
    .wa-graph__edge-label { font-size:9px; fill:#74777E; }
    .wa-graph__legend { display:flex; flex-wrap:wrap; gap:12px; margin-top:10px; font-size:12px; color:var(--wa-text-muted); }
</style>
</head>
<body class="reader" id="reader-body">

<div class="reader__progress" id="reader-progress"></div>

<!-- ===================== THANH CÔNG CỤ ===================== -->
<header class="reader__bar">
  <a class="reader__back" href="<?= wa_e($libraryUrl) ?>">
    <span class="ms ms-16">arrow_back</span>
    <span class="reader__back-label">Quay lại thư viện</span>
  </a>

  <div class="reader__status">
    <span class="dot dot--live"></span>
    <span>Bài viết đã lưu trữ an toàn</span>
    <span class="t-muted">/</span>
    <span>Snapshot:</span>
    <strong class="t-mono">SNAP-<?= (int) $article['id'] ?></strong>
  </div>

  <div class="reader__tools">
    <!-- Điều khiển cỡ chữ -->
    <div class="font-toggle" title="Thay đổi kích thước chữ">
      <button type="button" id="btn-font-decrease" aria-label="Giảm kích thước phông chữ">A-</button>
      <span></span>
      <button type="button" id="btn-font-increase" aria-label="Tăng kích thước phông chữ">A+</button>
    </div>

    <!-- Nhạc nền đọc -->
    <div class="font-toggle" title="Nền đọc">
      <button type="button" id="btn-theme-warm" class="is-active" style="width:auto;padding:0 8px">Ấm áp</button>
      <span></span>
      <button type="button" id="btn-theme-white" style="width:auto;padding:0 8px">Trắng tinh</button>
      <span></span>
      <button type="button" id="btn-theme-night" style="width:auto;padding:0 8px">Ban đêm</button>
    </div>

    <button class="btn btn--outline btn--sm" type="button" onclick="window.print()" title="Xuất tài liệu bảo lưu PDF">
      <span class="ms ms-16">picture_as_pdf</span>
      <span class="reader__export-label">Xuất PDF</span>
    </button>

    <!-- P-LIB: xuất bản lưu ra TỆP HTML ĐỘC LẬP (ảnh nhúng base64, mở offline).
         Đây là liên kết tải xuống (GET), không phải hành động thay đổi dữ liệu,
         nên không cần token CSRF. Nút "Xuất PDF" ở trên vẫn hoạt động như cũ. -->
    <a class="btn btn--dark btn--sm"
       href="<?= wa_e(wa_url('export.php?id=' . (int) $article['id'])) ?>"
       title="Tải bản lưu này thành tệp HTML độc lập, mở được ngoại tuyến">
      <span class="ms ms-16">download</span>
      <span class="reader__export-label">Xuất bản lưu</span>
    </a>

    <!-- A9: nhãn "Ngoại tuyến 100%" là một con số BỊA. Thay bằng số đo thật:
         bao nhiêu tài nguyên cục bộ của bản lưu này còn trên đĩa. -->
    <span class="chip chip--teal">
      <span class="ms ms-14">shield</span>
      <?= $snapshotAssets['total'] > 0
          ? $snapshotAssets['present'] . '/' . $snapshotAssets['total'] . ' tài nguyên cục bộ'
          : 'Đọc ngoại tuyến' ?>
    </span>

    <!-- V3 / FEATURE 4 — bật/tắt chế độ bôi đen + chọn màu đánh dấu. -->
    <div class="wa-annot" id="wa-annot-tool">
      <button class="btn btn--outline btn--sm" type="button" id="wa-annot-toggle"
              aria-pressed="false" title="Bật chế độ bôi đen để tô màu hoặc ghi chú">
        <span class="ms ms-16">ink_highlighter</span>
        <span class="reader__export-label">Bôi đen</span>
      </button>
      <div class="wa-annot__colors" id="wa-annot-colors" hidden>
        <?php foreach (['yellow', 'green', 'blue', 'pink', 'orange'] as $c): ?>
          <button class="wa-annot__color wa-annot__color--<?= wa_e($c) ?>" type="button"
                  data-color="<?= wa_e($c) ?>" title="<?= wa_e($c) ?>"
                  aria-label="Màu <?= wa_e($c) ?>"></button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- V3 / FEATURE 2 — nút Trích dẫn (APA / thông thường), sao chép nhanh. -->
    <button class="btn btn--outline btn--sm" type="button"
            data-modal-open="modal-citation"
            title="Xem và sao chép trích dẫn cho bản lưu này">
      <span class="ms ms-16">format_quote</span>
      <span class="reader__export-label">Trích dẫn</span>
    </button>

    <!-- V3 / FEATURE 5 — tìm cụm từ trong lịch sử phiên bản của bài này. -->
    <a class="btn btn--outline btn--sm"
       href="<?= wa_e(wa_url('version_search.php?page_id=' . (int) $context['page_id'])) ?>"
       title="Tìm một cụm từ trong lịch sử phiên bản của bài này">
      <span class="ms ms-16">manage_search</span>
      <span class="reader__export-label">Tìm trong lịch sử</span>
    </a>

    <!-- V3 / FEATURE 6 — xác minh tính toàn vẹn bản lưu bằng SHA-256. -->
    <a class="btn btn--outline btn--sm"
       href="<?= wa_e(wa_url('integrity.php?id=' . (int) $article['id'])) ?>"
       title="Kiểm tra tính toàn vẹn nội dung bản lưu này bằng SHA-256">
      <span class="ms ms-16">verified_user</span>
      <span class="reader__export-label">Tính toàn vẹn</span>
    </a>

    <!-- V3 / FEATURE 8 — chia sẻ bản lưu này bằng link có thời hạn / giới hạn lượt xem. -->
    <?php if ($canShare): ?>
      <button class="btn btn--outline btn--sm" type="button"
              data-modal-open="modal-share"
              title="Tạo link chia sẻ cho đúng phiên bản này">
        <span class="ms ms-16">share</span>
        <span class="reader__export-label">Chia sẻ</span>
      </button>
    <?php endif; ?>

    <!-- V3 / FEATURE 3 — đánh dấu đọc / đọc sau. -->
    <button class="btn btn--outline btn--sm" type="button" id="wa-queue-btn"
            data-in-queue="<?= $readingInQueue ? '1' : '0' ?>"
            title="Thêm / gỡ khỏi danh sách đọc sau">
      <span class="ms ms-16">bookmark_add</span>
      <span class="reader__export-label">Đọc sau</span>
    </button>
  </div>
</header>

<!-- ===================== CỘT ĐỌC ===================== -->
<article class="reader__col">
  <!-- Flash sau khi tải lại hoặc thao tác ghi chú -->
  <?php if ($flash !== null): ?>
    <div class="wa-flash <?= (string) ($flash['type'] ?? '') === 'success' ? 'wa-flash--ok' : 'wa-flash--err' ?>" role="status">
      <span class="ms ms-18 <?= (string) ($flash['type'] ?? '') === 'success' ? 'ms-fill' : '' ?>">
        <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
      </span>
      <span>
        <?php if (isset($flash['title']) && (string) $flash['title'] !== ''): ?>
          <strong><?= wa_e((string) $flash['title']) ?>.</strong>
        <?php endif; ?>
        <?= wa_e((string) ($flash['text'] ?? '')) ?>
        <?php if (isset($flash['code']) && (string) $flash['code'] !== ''): ?>
          <br><span class="t-mono">Mã lỗi: <?= wa_e((string) $flash['code']) ?>
            — <?= wa_e($readerErrorTitles[(string) $flash['code']] ?? 'Lỗi') ?></span>
        <?php endif; ?>
      </span>
    </div>
  <?php endif; ?>

  <?php if ($canRefetch): ?>
    <!-- P-CHECK-AUTO: slot cho banner tự hiện sau khi kiểm tra nền xong. -->
    <div id="wa-check-banner-slot"></div>
  <?php endif; ?>

  <!-- P-CHECK: banner "trang gốc có nội dung mới" từ lần kiểm tra trước. -->
  <?php if ($hasUpdate): ?>
    <div class="wa-banner wa-banner--update" role="status">
      <span>
        Trang gốc có nội dung mới
        <?php if ($checkedAt !== ''): ?>
          (kiểm tra ngày <?= wa_e(wa_datetime($checkedAt)) ?>)
        <?php endif; ?>
        — bấm “Kiểm tra bản mới” để xem trước thay đổi.
      </span>
      <?php if ($canRefetch): ?>
        <span class="wa-banner__actions">
          <form method="post" action="<?= wa_e(wa_url('check_update.php')) ?>">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="page_id" value="<?= (int) $context['page_id'] ?>">
            <button class="btn btn--dark btn--sm" type="submit">Xem trước thay đổi</button>
          </form>
        </span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <header class="reader__head">
    <!-- V3 / FEATURE 3 — trạng thái đọc của chính tài khoản này. -->
    <div class="wa-readstate" id="wa-readstate"
         data-status="<?= wa_e($readingStatus) ?>"
         data-progress="<?= (int) $readingProgress ?>">
      <span class="chip <?= $readingStatus === 'read' ? 'chip--teal' : ($readingStatus === 'reading' ? 'chip--blue' : 'chip--plain') ?>"
            id="wa-readstate-chip">
        <span class="ms ms-14"><?= $readingStatus === 'read' ? 'task_alt' : ($readingStatus === 'reading' ? 'menu_book' : 'radio_button_unchecked') ?></span>
        <span id="wa-readstate-label"><?= $readingStatus === 'read' ? 'Đã đọc' : ($readingStatus === 'reading' ? 'Đang đọc' : 'Chưa đọc') ?></span>
      </span>
      <span class="wa-readstate__pct" id="wa-readstate-pct"><?= (int) $readingProgress ?>%</span>
      <span class="wa-readstate__actions">
        <button class="btn btn--ghost btn--sm" type="button" id="wa-read-mark"
                data-status="<?= wa_e($readingStatus) ?>">
          <span class="ms ms-16">check_circle</span>
          <span id="wa-read-mark-label"><?= $readingStatus === 'read' ? 'Đánh dấu chưa đọc' : 'Đánh dấu đã đọc' ?></span>
        </button>
      </span>
    </div>

    <div class="row row--between" style="gap:8px">
      <span class="chip chip--blue">
        <span class="ms ms-14">terminal</span>
        <?= wa_e(strtoupper($libraryName)) ?>
      </span>
      <span class="wa-ver__chip">
        <span class="ms ms-14">history</span>
        Phiên bản <?= $versionNo ?> · SNAP-<?= (int) $article['id'] ?>
      </span>
    </div>

    <h1 class="reader__title"><?= wa_e($article['title']) ?></h1>

    <!-- ===================== ĐẦU NGUỒN GỐC (PROVENANCE) ===================== -->
    <div class="wa-prov">
      <div class="wa-prov__head">
        <a class="wa-prov__lib" href="<?= wa_e($libraryUrl) ?>">
          <span class="ms ms-16">folder</span>
          Thư viện: <?= wa_e($libraryName) ?>
        </a>
        <span class="t-label-sm t-muted">Vai trò của bạn:
          <strong><?= wa_e($roleLabels[$role] ?? 'Người xem') ?></strong></span>
      </div>

      <div class="wa-prov__body">
        <div class="wa-prov__row">
          <span class="wa-prov__key">Tiêu đề bài viết</span>
          <span class="wa-prov__val"><?= wa_e($article['title']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Thư viện</span>
          <span class="wa-prov__val">
            <a href="<?= wa_e($libraryUrl) ?>"><?= wa_e($libraryName) ?></a>
            <span class="t-muted">(mở lại library.php?slug=<?= wa_e($librarySlug) ?>)</span>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">URL nguồn</span>
          <span class="wa-prov__val">
            <div class="wa-prov__url"><?= wa_e($article['source_url']) ?></div>
            <a class="wa-prov__ext" href="<?= wa_e($article['source_url']) ?>"
               target="_blank" rel="noopener noreferrer">
              <span class="ms ms-14">open_in_new</span>
              Mở trang gốc trong tab mới
            </a>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Tên miền</span>
          <span class="wa-prov__val"><?= wa_e($article['domain']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Phiên bản</span>
          <span class="wa-prov__val">
            <strong>Phiên bản <?= $versionNo ?></strong>
            <span class="t-mono">· SNAP-<?= (int) $article['id'] ?></span>
            <span class="t-muted">· mã trang #<?= (int) $article['page_id'] ?></span>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Thời điểm tải</span>
          <span class="wa-prov__val"><?= wa_e($article['saved_at']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Mã HTTP lần tải</span>
          <span class="wa-prov__val">
            <strong><?= $httpStatus ?></strong>
            <span class="t-muted">— tải thành công, nội dung đã lưu về máy chủ nội bộ</span>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Người lưu</span>
          <span class="wa-prov__val"><?= wa_e($article['creator_name']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Dung lượng</span>
          <span class="wa-prov__val">
            <?= wa_e($article['size_label']) ?>
            <span class="t-muted">(<?= (int) $article['image_count'] ?> ảnh đã tải về máy chủ)</span>
          </span>
        </div>
      </div>
    </div>

    <!-- ===================== CHUYỂN PHIÊN BẢN ===================== -->
    <div class="wa-prov">
      <div class="wa-prov__head">
        <span class="wa-prov__lib" style="cursor:default">
          <span class="ms ms-16">history</span>
          Lịch sử phiên bản (<?= count($versions) ?> bản thành công)
        </span>
        <div style="display:flex; gap:8px; flex-wrap:wrap">
          <?php if ($canRefetch): ?>
            <!-- P-CHECK: xem trước thay đổi ở trang gốc, chưa lưu gì. -->
            <form method="post" action="<?= wa_e(wa_url('check_update.php')) ?>">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="page_id" value="<?= (int) $context['page_id'] ?>">
              <button class="btn btn--outline btn--sm" type="submit">
                <span class="ms ms-16">update</span>
                <span>Kiểm tra bản mới</span>
              </button>
            </form>
            <form method="post" action="<?= wa_e(wa_url('refetch.php')) ?>">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
              <button class="btn btn--dark btn--sm" type="submit" data-wa-refetch>
                <span class="ms ms-16">cloud_download</span>
                <span>Lấy phiên bản mới</span>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="wa-prov__body">
        <ul class="wa-ver__list">
          <?php foreach ($versions as $version): ?>
            <li class="wa-ver__item">
              <a class="wa-ver__link <?= $version['is_current'] ? 'is-current' : '' ?>"
                 href="<?= wa_e(wa_url('reader.php?id=' . $version['id'])) ?>"
                 <?= $version['is_current'] ? 'aria-current="true"' : '' ?>>
                <span>
                  <span class="wa-ver__num">Phiên bản <?= (int) $version['version_no'] ?></span>
                  <?php if (!empty($version['label'])): ?>
                    <span class="wa-ver__meta"> · <?= wa_e((string) $version['label']) ?></span>
                  <?php endif; ?>
                  <?php if ($version['is_current']): ?>
                    <span class="chip chip--plain" style="margin-left:6px">đang xem</span>
                  <?php endif; ?>
                  <span class="wa-ver__meta">· SNAP-<?= (int) $version['id'] ?></span>
                </span>
                <span class="wa-ver__meta">
                  <?= wa_e($version['fetched_label']) ?> · HTTP <?= (int) $version['http_status'] ?>
                </span>
              </a>
              <!-- P-CHECK: nút so sánh NGAY BÊN TRÁI nút thao tác (⋮) của từng
                   dòng phiên bản. Bấm vào nút ở dòng phiên bản KHÁC để so sánh
                   dòng đó với bản đang xem; mục thứ hai so sánh dòng đó với
                   bản gốc đang ở trên web. Dòng đang xem thì không có mục so
                   sánh với chính nó; người xem thì không có mục so với bản gốc
                   (mục đó cần quyền ghi như nút "Kiểm tra bản mới"). JS đóng/mở
                   menu dùng chung với menu ⋮ nên không cần thêm JS. -->
              <?php if (!$version['is_current'] || $canRefetch): ?>
                <div class="wa-ver__menu">
                  <button class="btn btn--ghost wa-ver__menu-btn" type="button" title="So sánh phiên bản" aria-label="So sánh phiên bản" aria-expanded="false">
                    <span class="ms ms-18">compare_arrows</span>
                  </button>
                  <div class="wa-ver__menu-list" hidden>
                    <?php if (!$version['is_current']): ?>
                      <a class="wa-ver__menu-item" style="text-decoration:none;color:inherit"
                         href="<?= wa_e(wa_url('compare.php?page_id=' . (int) $context['page_id'] . '&from=' . min((int) $version['version_no'], $versionNo) . '&to=' . max((int) $version['version_no'], $versionNo))) ?>">
                        <span class="ms ms-18">compare_arrows</span> So sánh với phiên bản đang xem
                      </a>
                    <?php endif; ?>
                    <?php if ($canRefetch): ?>
                      <form method="post" action="<?= wa_e(wa_url('check_update.php')) ?>" style="margin:0">
                        <?= wa_csrf_field() ?>
                        <input type="hidden" name="page_id" value="<?= (int) $context['page_id'] ?>">
                        <input type="hidden" name="snapshot_id" value="<?= (int) $version['id'] ?>">
                        <button class="wa-ver__menu-item" type="submit">
                          <span class="ms ms-18">language</span> So sánh với bản gốc trên web
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if ($canManageReader): ?>
                <div class="wa-ver__menu">
                  <button class="btn btn--ghost wa-ver__menu-btn" type="button" title="Thao tác phiên bản" aria-label="Thao tác phiên bản" aria-expanded="false">
                    <span class="ms ms-18">more_vert</span>
                  </button>
                  <div class="wa-ver__menu-list" hidden>
                    <button class="wa-ver__menu-item" type="button" data-modal-open="modal-rename-version"
                            data-snapshot-id="<?= (int) $version['id'] ?>"
                            data-version-no="<?= (int) $version['version_no'] ?>"
                            data-version-label="<?= wa_e((string) ($version['label'] ?? '')) ?>">
                      <span class="ms ms-18">edit</span> Đổi tên phiên bản
                    </button>
                    <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>"
                          onsubmit="return confirm('Xoá vĩnh viễn phiên bản này?');" style="margin:0">
                      <?= wa_csrf_field() ?>
                      <input type="hidden" name="action" value="version_delete">
                      <input type="hidden" name="snapshot_id" value="<?= (int) $version['id'] ?>">
                      <input type="hidden" name="current_id" value="<?= (int) $article['id'] ?>">
                      <button class="wa-ver__menu-item" type="submit">
                        <span class="ms ms-18">delete</span> Xoá phiên bản này
                      </button>
                    </form>
                  </div>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($prevVersion !== null || $nextVersion !== null): ?>
          <div class="wa-ver__nav">
            <?php if ($nextVersion !== null): ?>
              <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('reader.php?id=' . (int) $nextVersion['id'])) ?>">
                <span class="ms ms-16">chevron_left</span>
                Bản cũ hơn (v<?= (int) $nextVersion['version_no'] ?>)
              </a>
            <?php endif; ?>
            <?php if ($prevVersion !== null): ?>
              <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('reader.php?id=' . (int) $prevVersion['id'])) ?>">
                Bản mới hơn (v<?= (int) $prevVersion['version_no'] ?>)
                <span class="ms ms-16">chevron_right</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="reader__divider"></div>
  </header>

  <!-- ===================== NỘI DUNG BÀI VIẾT (từ bảng snapshots) ===================== -->
  <!-- V3 / FEATURE 4: nội dung được vẽ lại với các đánh dấu của CHÍNH tài khoản.
       Bản gốc trong CSDL KHÔNG bị sửa — đánh dấu nằm riêng ở bảng `annotations`. -->
  <div class="reader__body" id="reading-content">
<?= wa_annotation_render_html((string) $article['content_html'], $annotations) ?>
  </div>

  <!-- ===================== ĐÁNH DẤU & GHI CHÚ TRỰC TIẾP (V3 / FEATURE 4) ===================== -->
  <section class="wa-notes" id="wa-annotations" aria-labelledby="wa-annotations-title">
    <div class="wa-prov__head" style="margin:-14px -14px 14px">
      <span class="wa-prov__lib" style="cursor:default">
        <span class="ms ms-16">ink_highlighter</span>
        <span id="wa-annotations-title">Đánh dấu của tôi</span>
      </span>
      <span class="t-label-sm t-muted">
        <?= count($annotations) ?> đánh dấu · thuộc riêng phiên bản <?= $versionNo ?>
      </span>
    </div>

    <p class="t-body-sm t-muted" style="margin:0 0 12px">
      Bôi đen một đoạn văn trong bài để tô màu hoặc ghi chú. Đánh dấu được neo
      theo vị trí văn bản nên vẫn hiện đúng khi mở lại bản lưu này.
    </p>

    <?php if ($annotations === []): ?>
      <p class="t-body-sm t-muted" style="margin:0 0 12px">
        Chưa có đánh dấu nào. Bôi đen một đoạn trong bài rồi chọn màu hoặc thêm ghi chú.
      </p>
    <?php else: ?>
      <?php foreach ($annotations as $ann): ?>
        <div class="wa-notes__item" id="ann-<?= (int) $ann['id'] ?>">
          <div class="wa-notes__meta">
            <span>
              <span class="wa-notes__author">
                <span class="ms ms-16">ink_highlighter</span>
                Đánh dấu #<?= (int) $ann['id'] ?>
              </span>
              <span class="chip chip--plain" style="margin-left:6px">
                <?= wa_e((string) $ann['color']) ?>
              </span>
            </span>
            <span class="wa-notes__time"><?= wa_e((string) $ann['created_label']) ?></span>
          </div>

          <p class="wa-notes__text">“<?= wa_e((string) $ann['quote']) ?>”</p>

          <?php if (trim((string) ($ann['note'] ?? '')) !== ''): ?>
            <p class="t-body-sm" style="margin:6px 0 0">
              <span class="t-label-sm t-muted">Ghi chú:</span>
              <?= wa_e((string) $ann['note']) ?>
            </p>
          <?php endif; ?>

          <div class="wa-notes__acts">
            <form method="post" action="<?= wa_e(wa_url('annotation_action.php')) ?>"
                  onsubmit="return confirm('Xoá đánh dấu này?');" style="margin:0">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
              <input type="hidden" name="annotation_id" value="<?= (int) $ann['id'] ?>">
              <button class="btn btn--outline btn--sm" type="submit">
                <span class="ms ms-16">delete</span> Xoá
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <!-- ===================== THEO DÕI ĐOẠN NỘI DUNG (V3 ĐỢT 4 / F10) ===================== -->
  <section class="wa-notes" id="wa-watches" aria-labelledby="wa-watches-title">
    <div class="wa-prov__head" style="margin:-14px -14px 14px">
      <span class="wa-prov__lib" style="cursor:default">
        <span class="ms ms-16">visibility</span>
        <span id="wa-watches-title">Đoạn nội dung đang theo dõi</span>
      </span>
      <span class="t-label-sm t-muted">
        <?= count($watches) ?> mục · theo dõi riêng theo tài khoản này
      </span>
    </div>

    <p class="t-body-sm t-muted" style="margin:0 0 12px">
      Bôi đen một đoạn trong bài rồi bấm <strong>Theo dõi đoạn này</strong>. Khi
      Auto Version tạo phiên bản mới, hệ thống đối chiếu đoạn đó và báo cho bạn
      nếu nội dung đã bị sửa. Bản lưu gốc không bị thay đổi.
    </p>

    <?php if ($watches === []): ?>
      <p class="t-body-sm t-muted" style="margin:0 0 12px">
        Chưa có đoạn nào được theo dõi trong bài viết này.
      </p>
    <?php else: ?>
      <div class="wa-ver__list">
        <?php foreach ($watches as $w): ?>
          <div class="wa-ver__item" id="watch-<?= (int) $w['id'] ?>">
            <div class="wa-ver__link" style="align-items:flex-start;flex-direction:column;gap:4px">
              <div class="row" style="gap:8px;flex-wrap:wrap;width:100%">
                <span class="chip chip--<?= $w['status'] === 'changed' ? 'warn' : ($w['status'] === 'unknown' ? 'plain' : 'teal') ?>">
                  <?= wa_e((string) $w['status_label']) ?>
                </span>
                <span class="t-label-sm t-muted">#<?= (int) $w['id'] ?></span>
                <?php if (!$w['is_active']): ?>
                  <span class="chip chip--plain">đang tạm dừng</span>
                <?php endif; ?>
              </div>
              <div class="t-body-sm" style="margin:0">“<?= wa_e((string) $w['quote']) ?>”</div>
              <?php if ((string) $w['status_note'] !== ''): ?>
                <div class="t-label-sm t-muted"><?= wa_e((string) $w['status_note']) ?></div>
              <?php endif; ?>
              <div class="t-label-sm t-muted">
                Tạo: <?= wa_e((string) $w['created_label']) ?>
                <?php if ((string) $w['last_changed_at'] !== ''): ?>
                  · Cập nhật: <?= wa_e(wa_datetime((string) $w['last_changed_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="wa-ver__menu">
              <form method="post" action="<?= wa_e(wa_url('watch_action.php')) ?>" style="margin:0">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="redirect" value="reader">
                <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
                <input type="hidden" name="watch_id" value="<?= (int) $w['id'] ?>">
                <input type="hidden" name="is_active" value="<?= $w['is_active'] ? '0' : '1' ?>">
                <button class="btn btn--ghost btn--sm" type="submit"
                        title="<?= $w['is_active'] ? 'Tạm dừng theo dõi' : 'Bật theo dõi' ?>">
                  <span class="ms ms-16"><?= $w['is_active'] ? 'pause' : 'play_arrow' ?></span>
                  <span class="reader__export-label">
                    <?= $w['is_active'] ? 'Tắt' : 'Bật' ?>
                  </span>
                </button>
              </form>
              <form method="post" action="<?= wa_e(wa_url('watch_action.php')) ?>"
                    onsubmit="return confirm('Xoá mục theo dõi này?');" style="margin:0">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="redirect" value="reader">
                <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
                <input type="hidden" name="watch_id" value="<?= (int) $w['id'] ?>">
                <button class="btn btn--ghost btn--sm" type="submit" title="Xoá mục theo dõi">
                  <span class="ms ms-16">delete</span>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ===================== BÀI VIẾT LIÊN QUAN + SƠ ĐỒ (V3 ĐỢT 4 / F11) ===================== -->
  <section class="wa-notes" id="wa-relations" aria-labelledby="wa-relations-title">
    <div class="wa-prov__head" style="margin:-14px -14px 14px">
      <span class="wa-prov__lib" style="cursor:default">
        <span class="ms ms-16">hub</span>
        <span id="wa-relations-title">Bài viết liên quan</span>
      </span>
      <span class="t-label-sm t-muted">
        <?= count($relations) ?> liên kết ra · <?= count($relationIncoming) ?> liên kết vào
      </span>
    </div>

    <div class="row" style="gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <button class="btn btn--outline btn--sm" type="button" id="wa-relation-add-open"
              data-modal-open="modal-relation">
        <span class="ms ms-16">add_link</span> Liên kết bài viết
      </button>
      <button class="btn btn--outline btn--sm" type="button" id="wa-graph-open"
              data-modal-open="modal-graph">
        <span class="ms ms-16">schema</span> Xem sơ đồ quan hệ
      </button>
    </div>

    <?php if ($relations === [] && $relationIncoming === []): ?>
      <p class="t-body-sm t-muted" style="margin:0 0 12px">
        Bài viết này chưa liên kết với bài nào khác. Bấm “Liên kết bài viết” để
        tạo quan hệ Liên quan / Tham khảo / Bổ sung / Đối chiếu.
      </p>
    <?php endif; ?>

    <?php if ($relations !== []): ?>
      <h3 class="t-headline-sm" style="font-weight:700;margin:0 0 8px">Liên kết từ bài này</h3>
      <div class="wa-ver__list" style="margin-bottom:16px">
        <?php foreach ($relations as $rel): ?>
          <div class="wa-ver__item">
            <div class="wa-ver__link" style="align-items:flex-start;flex-direction:column;gap:4px">
              <div class="row" style="gap:8px;flex-wrap:wrap;width:100%">
                <span class="chip chip--plain"><?= wa_e((string) $rel['type_label']) ?></span>
                <?php if ($rel['target_snapshot_id'] > 0): ?>
                  <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $rel['target_snapshot_id'])) ?>"
                     style="font-size:13px;font-weight:600"><?= wa_e((string) $rel['target_title']) ?></a>
                <?php else: ?>
                  <strong style="font-size:13px"><?= wa_e((string) $rel['target_title']) ?></strong>
                <?php endif; ?>
              </div>
              <?php if ((string) $rel['note'] !== ''): ?>
                <div class="t-body-sm t-muted" style="margin:0"><?= wa_e((string) $rel['note']) ?></div>
              <?php endif; ?>
              <div class="t-label-sm t-muted">
                <?= wa_e((string) $rel['target_library']) ?> · <?= wa_e((string) $rel['created_label']) ?>
              </div>
            </div>
            <?php if ($canWriteRelation): ?>
              <div class="wa-ver__menu">
                <button class="btn btn--ghost btn--sm" type="button"
                        data-relation-edit="<?= (int) $rel['id'] ?>"
                        data-relation-type="<?= wa_e((string) $rel['relation_type']) ?>"
                        data-relation-note="<?= wa_e((string) $rel['note']) ?>"
                        title="Sửa quan hệ">
                  <span class="ms ms-16">edit</span>
                </button>
                <form method="post" action="<?= wa_e(wa_url('relation_action.php')) ?>"
                      onsubmit="return confirm('Xoá quan hệ này?');" style="margin:0">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="redirect" value="reader">
                  <input type="hidden" name="source_page_id" value="<?= (int) $context['page_id'] ?>">
                  <input type="hidden" name="relation_id" value="<?= (int) $rel['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit" title="Xoá quan hệ">
                    <span class="ms ms-16">delete</span>
                  </button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($relationIncoming !== []): ?>
      <h3 class="t-headline-sm" style="font-weight:700;margin:0 0 8px">Bài khác trỏ tới bài này</h3>
      <div class="wa-ver__list">
        <?php foreach ($relationIncoming as $rel): ?>
          <div class="wa-ver__item">
            <div class="wa-ver__link" style="align-items:flex-start;flex-direction:column;gap:4px">
              <div class="row" style="gap:8px;flex-wrap:wrap;width:100%">
                <span class="chip chip--plain"><?= wa_e((string) $rel['type_label']) ?></span>
                <?php if ($rel['source_snapshot_id'] > 0): ?>
                  <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $rel['source_snapshot_id'])) ?>"
                     style="font-size:13px;font-weight:600"><?= wa_e((string) $rel['source_title']) ?></a>
                <?php else: ?>
                  <strong style="font-size:13px"><?= wa_e((string) $rel['source_title']) ?></strong>
                <?php endif; ?>
              </div>
              <div class="t-label-sm t-muted"><?= wa_e((string) $rel['source_library']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ===================== GHI CHÚ CỦA PHIÊN BẢN NÀY ===================== -->
  <section class="wa-notes" aria-labelledby="wa-notes-title">
    <div class="wa-prov__head" style="margin:-14px -14px 14px">
      <span class="wa-prov__lib" style="cursor:default">
        <span class="ms ms-16">sticky_note_2</span>
        <span id="wa-notes-title">Ghi chú của phiên bản <?= $versionNo ?></span>
      </span>
      <span class="t-label-sm t-muted"><?= count($notes) ?> ghi chú · chỉ thuộc phiên bản này</span>
    </div>

    <?php if ($notes === []): ?>
      <p class="t-body-sm t-muted" style="margin:0 0 12px">
        Chưa có ghi chú nào cho phiên bản <?= $versionNo ?>.
        Ghi chú của một phiên bản khác không hiện ở đây.
      </p>
    <?php else: ?>
      <?php foreach ($notes as $note): ?>
        <?php
        // Chỉ tác giả được sửa/xoá; quản trị viên chỉ được XOÁ (đường riêng).
        $isAuthor = ((int) $note['user_id'] === $currentUserId);
        $canEdit  = $isAuthor;
        $canDel   = $isAuthor || $isAdmin;
        ?>
        <div class="wa-notes__item" id="note-<?= (int) $note['id'] ?>">
          <div class="wa-notes__meta">
            <span>
              <span class="wa-notes__author"><?= wa_e($note['author_name']) ?></span>
              <?php if ($note['edited']): ?>
                <span class="chip chip--plain" style="margin-left:6px" title="Sửa lần cuối: <?= wa_e($note['edited_label']) ?>">đã sửa</span>
              <?php endif; ?>
            </span>
            <span class="wa-notes__time"><?= wa_e($note['created_label']) ?></span>
          </div>

          <p class="wa-notes__text"><?= wa_e($note['content']) ?></p>

          <?php if ($canEdit || $canDel): ?>
            <div class="wa-notes__acts">
              <?php if ($canEdit): ?>
                <button class="btn btn--outline btn--sm" type="button"
                        onclick="waNoteEdit(<?= (int) $note['id'] ?>)">
                  <span class="ms ms-16">edit</span> Sửa
                </button>
              <?php endif; ?>

              <?php if ($canDel): ?>
                <form method="post" action="<?= wa_e(wa_url('note.php')) ?>"
                      onsubmit="return confirm('Xoá ghi chú này?');">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
                  <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit">
                    <span class="ms ms-16">delete</span> Xoá
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($canEdit): ?>
            <form class="wa-editor" id="wa-note-edit-<?= (int) $note['id'] ?>" method="post"
                  action="<?= wa_e(wa_url('note.php')) ?>">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
              <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
              <textarea name="content" aria-label="Nội dung ghi chú"><?= wa_e($note['content']) ?></textarea>
              <div class="wa-editor__row">
                <button class="btn btn--dark btn--sm" type="submit">Lưu thay đổi</button>
                <button class="btn btn--outline btn--sm" type="button"
                        onclick="waNoteCancel(<?= (int) $note['id'] ?>)">Huỷ</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Mọi người xem được thư viện đều thêm được ghi chú -->
    <form method="post" action="<?= wa_e(wa_url('note.php')) ?>" style="margin-top:14px">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
      <div class="field">
        <label for="wa-note-content">Thêm ghi chú cho phiên bản <?= $versionNo ?></label>
        <textarea class="input" id="wa-note-content" name="content" rows="3"
                  placeholder="Nhận xét về nội dung của đúng phiên bản này…" style="width:100%"></textarea>
      </div>
      <div class="row" style="margin-top:8px;gap:8px">
        <button class="btn btn--dark" type="submit">
          <span class="ms ms-18">add_comment</span>
          <span>Ghi chú</span>
        </button>
        <span class="t-label-sm t-muted">Ghi chú chỉ thuộc phiên bản <?= $versionNo ?>, không dùng chung với các bản khác.</span>
      </div>
    </form>
  </section>

  <!-- ===================== CHÂN TRANG ĐỌC ===================== -->
  <div class="reader__divider" style="margin-top:40px"></div>

  <footer class="reader__foot">
    <div class="row t-label-md" style="gap:8px;color:var(--wa-text-muted)">
      <span class="ms ms-16 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span>
      <span>Đã đọc hết bài viết • Lưu trữ bởi WebArchive</span>
    </div>
    <a class="btn btn--primary" href="<?= wa_e($libraryUrl) ?>">
      <span class="ms ms-16">arrow_back</span>
      <span>Trở về Thư viện <?= wa_e($libraryName) ?></span>
    </a>
  </footer>
</article>

<script>
  // ---- Cỡ chữ A- / A+ (JavaScript thuần) ----
  (function () {
    var content = document.getElementById('reading-content');
    var dec = document.getElementById('btn-font-decrease');
    var inc = document.getElementById('btn-font-increase');
    var steps = [16, 17, 18, 19, 20, 22];
    var index = 2;

    function apply() {
      content.style.fontSize = steps[index] + 'px';
      content.style.lineHeight = '1.78';
    }

    if (dec) {
      dec.addEventListener('click', function () {
        if (index > 0) { index--; apply(); }
      });
    }
    if (inc) {
      inc.addEventListener('click', function () {
        if (index < steps.length - 1) { index++; apply(); }
      });
    }
  })();

  // ---- Nền đọc ----
  (function () {
    var body = document.getElementById('reader-body');
    var themes = {
      'btn-theme-warm': '',
      'btn-theme-white': 'reader--white',
      'btn-theme-night': 'reader--night'
    };

    Object.keys(themes).forEach(function (id) {
      var btn = document.getElementById(id);
      if (!btn) { return; }
      btn.addEventListener('click', function () {
        Object.keys(themes).forEach(function (otherId) {
          var other = document.getElementById(otherId);
          if (other) { other.classList.remove('is-active'); }
        });
        btn.classList.add('is-active');
        body.className = 'reader ' + themes[id];
      });
    });
  })();

  // ---- Tiến độ đọc ----
  (function () {
    var bar = document.getElementById('reader-progress');
    function update() {
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      var pct = max > 0 ? (doc.scrollTop / max) * 100 : 0;
      bar.style.width = Math.min(100, Math.max(0, pct)) + '%';
    }
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  })();

  // ---- V3 / FEATURE 2: sao chép trích dẫn (Clipboard API + fallback) ----
  (function () {
    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var box = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!box) { return; }

        // Ô nhập (textarea/input) dùng .value; khối <div> dùng .textContent.
        var text = ('value' in box) ? (box.value || '') : (box.textContent || '');
        var done = function () {
          var old = btn.getAttribute('data-old-label');
          btn.setAttribute('data-old-label', btn.innerHTML);
          btn.innerHTML = '<span class="ms ms-16 ms-fill">check</span> Đã sao chép';
          setTimeout(function () {
            if (old !== null && old !== undefined) { btn.innerHTML = old; }
          }, 1800);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () { fallback(); });
        } else {
          fallback();
        }

        function fallback() {
          if ('value' in box) {
            box.removeAttribute('readonly');
            box.focus();
            box.select();
            try { document.execCommand('copy'); } catch (e) { /* bỏ qua */ }
            box.setAttribute('readonly', 'readonly');
          } else {
            var sel = window.getSelection();
            var range = document.createRange();
            range.selectNodeContents(box);
            sel.removeAllRanges();
            sel.addRange(range);
            try { document.execCommand('copy'); } catch (e) { /* bỏ qua */ }
            sel.removeAllRanges();
          }
          done();
        }
      });
    });
  })();

  // ---- V3 / FEATURE 4: bôi đen, tô màu và ghi chú trực tiếp ----
  (function () {
    var content = document.getElementById('reading-content');
    var toggle = document.getElementById('wa-annot-toggle');
    var palette = document.getElementById('wa-annot-colors');
    if (!content || !toggle) { return; }

    var snapshotId = <?= (int) $article['id'] ?>;
    var csrf = '<?= wa_e(wa_csrf_token()) ?>';
    var actionUrl = '<?= wa_e(wa_url('annotation_action.php')) ?>';
    var color = 'yellow';
    var active = false;

    // Đường dẫn CSS của khối, TÍNH TƯƠNG ĐỐI so với #reading-content.
    // Phía PHP dựng lại cây DOM chỉ từ content_html, nên phải dùng cùng một
    // gốc để đường dẫn khớp nhau.
    function cssPath(el) {
      var parts = [];
      var cur = el;
      while (cur && cur !== content) {
        if (cur.nodeType !== 1) { break; }
        var tag = cur.nodeName.toLowerCase();
        var index = 1;
        var sib = cur.previousElementSibling;
        while (sib) {
          if (sib.nodeName.toLowerCase() === tag) { index++; }
          sib = sib.previousElementSibling;
        }
        parts.unshift(tag + ':nth-of-type(' + index + ')');
        cur = cur.parentNode;
      }
      return parts.join(' > ');
    }

    // Tìm khối chứa đoạn đang chọn (phần tử khối gần nhất: p, li, td, h1..h6, blockquote, pre).
    function blockOf(node) {
      var cur = node;
      while (cur && cur !== content) {
        if (cur.nodeType === 1) {
          var t = cur.nodeName.toLowerCase();
          if (['p', 'li', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'figcaption'].indexOf(t) !== -1) {
            return cur;
          }
        }
        cur = cur.parentNode;
      }
      return content;
    }

    // Tính vị trí ký tự của một nút chữ trong textContent của khối.
    // Đếm theo CODE POINT (mảng ký tự Unicode) để khớp với mb_strlen() của PHP.
    function offsetIn(block, textNode, offset) {
      var total = 0;
      var found = false;
      var walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT, null);
      var n;
      while ((n = walker.nextNode())) {
        var chars = Array.from(n.nodeValue);
        if (n === textNode) {
          total += Math.min(offset, chars.length);
          found = true;
          break;
        }
        total += chars.length;
      }
      return found ? total : null;
    }

    function contextAround(block, start, end) {
      var chars = Array.from(block.textContent || '');
      return {
        prefix: chars.slice(Math.max(0, start - <?= WA_ANNOTATION_CONTEXT ?>), start).join(''),
        suffix: chars.slice(end, end + <?= WA_ANNOTATION_CONTEXT ?>).join('')
      };
    }

    function post(action, payload, cb) {
      var body = new FormData();
      body.append('wa_csrf', csrf);
      body.append('action', action);
      body.append('snapshot_id', String(snapshotId));
      Object.keys(payload || {}).forEach(function (k) { body.append(k, String(payload[k])); });

      fetch(actionUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) { if (data && cb) { cb(data); } })
        .catch(function () { /* im lặng */ });
    }

    // Bật/tắt chế độ bôi đen.
    toggle.addEventListener('click', function () {
      active = !active;
      toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
      toggle.classList.toggle('is-active', active);
      if (palette) { palette.hidden = !active; }
      content.classList.toggle('wa-annot-on', active);
    });

    // Chọn màu.
    if (palette) {
      palette.querySelectorAll('.wa-annot__color').forEach(function (btn) {
        btn.addEventListener('click', function () {
          color = btn.getAttribute('data-color') || 'yellow';
          palette.querySelectorAll('.wa-annot__color').forEach(function (b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');
        });
      });
    }

    // Bôi đen -> hiện hộp chọn màu / ghi chú.
    content.addEventListener('mouseup', function () {
      if (!active) { return; }

      var sel = window.getSelection();
      if (!sel || sel.isCollapsed || sel.rangeCount === 0) { return; }

      var text = sel.toString();
      if (!text || text.trim().length < 2) { return; }

      var range = sel.getRangeAt(0);
      var block = blockOf(range.startContainer);

      if (!block || block === content) { return; }

      var start = offsetIn(block, range.startContainer, range.startOffset);
      var end = offsetIn(block, range.endContainer, range.endOffset);

      if (start === null || end === null || end <= start) { return; }

      var ctx = contextAround(block, start, end);
      var note = window.prompt('Ghi chú cho đoạn đã bôi đen (để trống nếu không cần):', '');

      if (note === null) { return; }

      post('add', {
        selector: cssPath(block),
        start_offset: start,
        end_offset: end,
        quote: text.slice(0, 900),
        prefix: ctx.prefix,
        suffix: ctx.suffix,
        color: color,
        note: note
      }, function (data) {
        if (data && data.ok) {
          window.location.reload();
        }
      });

      sel.removeAllRanges();
    });
  })();

  // ---- V3 / FEATURE 3: trạng thái đọc + tiến độ đọc + danh sách đọc sau ----
  (function () {
    var snapshotId = <?= (int) $article['id'] ?>;
    var csrf = '<?= wa_e(wa_csrf_token()) ?>';
    var actionUrl = '<?= wa_e(wa_url('reading_action.php')) ?>';

    var panel = document.getElementById('wa-readstate');
    var chip = document.getElementById('wa-readstate-chip');
    var label = document.getElementById('wa-readstate-label');
    var pctEl = document.getElementById('wa-readstate-pct');
    var markBtn = document.getElementById('wa-read-mark');
    var markLabel = document.getElementById('wa-read-mark-label');
    var queueBtn = document.getElementById('wa-queue-btn');

    var status = panel ? panel.getAttribute('data-status') : 'unread';
    var progress = panel ? parseInt(panel.getAttribute('data-progress') || '0', 10) : 0;
    var saved = false;
    var timer = null;

    function post(action, extra, cb) {
      var body = new FormData();
      body.append('wa_csrf', csrf);
      body.append('action', action);
      body.append('snapshot_id', String(snapshotId));
      Object.keys(extra || {}).forEach(function (k) { body.append(k, String(extra[k])); });

      fetch(actionUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) { if (data && data.ok === true && cb) { cb(data); } })
        .catch(function () { /* im lặng: lưu tiến độ không được làm hỏng trang */ });
    }

    function syncStatus() {
      if (!panel) { return; }
      panel.setAttribute('data-status', status);
      panel.setAttribute('data-progress', String(progress));

      if (label) {
        label.textContent = status === 'read' ? 'Đã đọc' : (status === 'reading' ? 'Đang đọc' : 'Chưa đọc');
      }

      if (chip) {
        chip.className = 'chip ' + (status === 'read' ? 'chip--teal' : (status === 'reading' ? 'chip--blue' : 'chip--plain'));
        var icon = chip.querySelector('.ms');
        if (icon) {
          icon.textContent = status === 'read' ? 'task_alt' : (status === 'reading' ? 'menu_book' : 'radio_button_unchecked');
        }
      }

      if (pctEl) { pctEl.textContent = progress + '%'; }

      if (markLabel) {
        markLabel.textContent = status === 'read' ? 'Đánh dấu chưa đọc' : 'Đánh dấu đã đọc';
      }
    }

    // Lưu tiến độ (mỗi 5 giây, chỉ khi đã cuộn).
    function currentProgress() {
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      if (max <= 0) { return 100; }
      return Math.round(Math.min(100, Math.max(0, (doc.scrollTop / max) * 100)));
    }

    window.addEventListener('scroll', function () {
      if (timer) { return; }
      timer = setTimeout(function () {
        timer = null;
        if (status === 'read') { return; }

        var pct = currentProgress();

        if (pct <= progress && progress > 0) { return; }

        progress = pct;
        if (status === 'unread') { status = 'reading'; }
        syncStatus();
        saved = true;
        post('progress', { progress: progress, scroll_offset: Math.round(window.scrollY) });
      }, 5000);
    }, { passive: true });

    if (markBtn) {
      markBtn.addEventListener('click', function () {
        var next = status === 'read' ? 'unread' : 'read';
        status = next;
        if (next === 'read') { progress = 100; }
        syncStatus();
        post('state', { status: next, progress: progress, scroll_offset: Math.round(window.scrollY) });
      });
    }

    if (queueBtn) {
      queueBtn.addEventListener('click', function () {
        post('queue', {}, function (data) {
          var inQueue = data.in_queue === true;
          queueBtn.setAttribute('data-in-queue', inQueue ? '1' : '0');
          var icon = queueBtn.querySelector('.ms');
          if (icon) { icon.textContent = inQueue ? 'bookmark' : 'bookmark_add'; }
          var txt = queueBtn.querySelector('.reader__export-label');
          if (txt) { txt.textContent = inQueue ? 'Đã lưu' : 'Đọc sau'; }
        });
      });
    }

    // Mở lại bài đã đọc dở -> khôi phục vị trí cuộn.
    if (panel && (status === 'reading' || status === 'read') && progress > 2 && progress < 98) {
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      if (max > 0) {
        window.scrollTo(0, Math.round((progress / 100) * max));
      }
    }
  })();

  // ---- Sửa / huỷ ghi chú (JavaScript thuần) ----
  function waNoteEdit(noteId) {
    var box = document.getElementById('wa-note-edit-' + noteId);
    if (!box) { return; }
    box.classList.add('is-open');
    var area = box.querySelector('textarea');
    if (area) { area.focus(); area.setSelectionRange(area.value.length, area.value.length); }
  }

  function waNoteCancel(noteId) {
    var box = document.getElementById('wa-note-edit-' + noteId);
    if (box) { box.classList.remove('is-open'); }
  }

  // ---- Nút tải lại: khoá nút khi form ĐÃ bắt đầu gửi đi ----
  // KHÔNG khoá nút trong sự kiện click: trình duyệt thấy nút submit
  // bị disabled trước lúc gửi thì huỷ luôn việc gửi form (nút kẹt ở
  // "Đang tải lại…" mà server không nhận được POST nào). Khoá trong
  // sự kiện submit thì việc gửi đã bắt đầu nên không bị huỷ, vẫn
  // chống được bấm đúp.
  (function () {
    var btn = document.querySelector('[data-wa-refetch]');
    if (!btn || !btn.form) { return; }
    btn.form.addEventListener('submit', function () {
      btn.setAttribute('disabled', 'disabled');
      var label = btn.querySelector('span:last-child');
      if (label) { label.textContent = 'Đang tải lại…'; }
    });
  })();
</script>

<!-- ===================== MODAL: TRÍCH DẪN TÀI LIỆU (V3 / FEATURE 2) =====================
     Trích dẫn được dựng từ metadata thật của snapshot. Không có tác giả hay
     ngày xuất bản trong CSDL thì dùng nhãn quy ước ("n.d." / bỏ trống), KHÔNG bịa. -->
<div class="modal-backdrop" id="modal-citation" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">format_quote</span>
        <h2 class="t-headline-sm" style="font-weight:700">Trích dẫn tài liệu</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <div class="stack">
      <p class="t-body-sm t-muted">
        Bản lưu phiên bản <?= $versionNo ?> · SNAP-<?= (int) $article['id'] ?>.
        Trích dẫn lấy từ metadata thật của bản lưu; trường thiếu được ghi rõ
        theo quy ước, không tự điền.
      </p>

      <div class="field">
        <label for="wa-citation-apa">APA (7th)</label>
        <textarea class="input" id="wa-citation-apa" rows="3" readonly><?= wa_e($citationApa['text']) ?></textarea>
      </div>

      <div class="field">
        <label for="wa-citation-plain">Trích dẫn thông thường</label>
        <textarea class="input" id="wa-citation-plain" rows="3" readonly><?= wa_e($citationPlain['text']) ?></textarea>
      </div>

      <div class="row" style="gap:8px;justify-content:flex-end;flex-wrap:wrap">
        <button class="btn btn--outline btn--sm" type="button" data-copy-target="wa-citation-apa">
          <span class="ms ms-16">content_copy</span> Sao chép APA
        </button>
        <button class="btn btn--outline btn--sm" type="button" data-copy-target="wa-citation-plain">
          <span class="ms ms-16">content_copy</span> Sao chép kiểu thường
        </button>
        <button class="btn btn--dark btn--sm" type="button" data-modal-close>Đóng</button>
      </div>
    </div>
  </div>
</div>

<!-- ===================== MODAL: CHIA SẺ BẢN LƯU (V3 / FEATURE 8) =====================
     Tạo link chia sẻ cho ĐÚNG snapshot đang xem. Token chỉ hiện một lần ngay
     sau khi tạo (nằm trong $newShareLink); server chỉ lưu hash SHA-256. -->
<div class="modal-backdrop" id="modal-share" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">share</span>
        <h2 class="t-headline-sm" style="font-weight:700">Chia sẻ bản lưu này</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <div class="stack">
      <?php if ($newShareLink !== null): ?>
        <div class="wa-flash wa-flash--ok" style="margin:0">
          <span class="ms ms-18 ms-fill">check_circle</span>
          <span>
            <strong>Link đã tạo.</strong> Sao chép ngay — link chỉ hiện một lần:
            <div class="wa-prov__url" id="wa-share-new-url"
                 style="margin-top:6px;word-break:break-all"><?= wa_e((string) $newShareLink['url']) ?></div>
            <div class="row" style="gap:8px;margin-top:8px">
              <button class="btn btn--outline btn--sm" type="button"
                      data-copy-target="wa-share-new-url">
                <span class="ms ms-16">content_copy</span> Sao chép link
              </button>
              <span class="t-label-sm t-muted">
                Hết hạn: <?= wa_e((string) $newShareLink['expires_at']) ?>
                <?php if (!empty($newShareLink['max_views'])): ?>
                  · Tối đa <?= (int) $newShareLink['max_views'] ?> lượt xem
                <?php endif; ?>
              </span>
            </div>
          </span>
        </div>
      <?php endif; ?>

      <p class="t-body-sm t-muted" style="margin:0">
        Link chia sẻ chỉ mở đúng phiên bản <?= $versionNo ?> · SNAP-<?= (int) $article['id'] ?>.
        Khách có link chỉ đọc — không thấy ghi chú, thành viên hay thư viện.
      </p>

      <form class="stack stack--sm" method="post" action="<?= wa_e(wa_url('share_action.php')) ?>">
        <?= wa_csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">

        <div class="field">
          <label for="share-label">Nhãn link (tuỳ chọn)</label>
          <input class="input" type="text" id="share-label" name="label" maxlength="190"
                 placeholder="Ví dụ: Gửi đồng nghiệp">
        </div>

        <div class="field">
          <label for="share-preset">Thời hạn</label>
          <select class="input" id="share-preset" name="preset">
            <option value="1h">1 giờ</option>
            <option value="1d" selected>1 ngày</option>
            <option value="7d">7 ngày</option>
            <option value="custom">Tuỳ chỉnh…</option>
          </select>
        </div>

        <div class="field" id="share-custom-wrap" hidden>
          <label for="share-custom-hours">Số giờ tự động hết hạn</label>
          <input class="input" type="number" id="share-custom-hours" name="custom_hours"
                 min="1" max="720" value="24">
        </div>

        <div class="field">
          <label for="share-max-views">Giới hạn lượt mở (để trống = không giới hạn)</label>
          <input class="input" type="number" id="share-max-views" name="max_views"
                 min="1" max="100000" placeholder="Ví dụ: 10">
        </div>

        <div class="row" style="justify-content:flex-end;gap:8px">
          <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
          <button class="btn btn--dark" type="submit">
            <span class="ms ms-18">link</span> Tạo link
          </button>
        </div>
      </form>

      <?php if ($shareLinks !== []): ?>
        <div>
          <h3 class="t-headline-sm" style="font-weight:700;margin-bottom:8px">
            Link đã tạo (<?= count($shareLinks) ?>)
          </h3>
          <div class="wa-ver__list">
            <?php foreach ($shareLinks as $sl): ?>
              <div class="wa-ver__item">
                <div class="wa-ver__link" style="align-items:flex-start;flex-direction:column;gap:4px">
                  <div class="row" style="gap:8px;flex-wrap:wrap;width:100%">
                    <strong style="font-size:13px"><?= wa_e((string) $sl['label']) ?></strong>
                    <span class="chip chip--<?= $sl['dead'] ? 'plain' : 'teal' ?>"><?= wa_e((string) $sl['status']) ?></span>
                  </div>
                  <div class="t-label-sm t-muted">
                    Hết hạn: <?= wa_e((string) $sl['expires_label']) ?>
                    · Lượt xem: <?= (int) $sl['view_count'] ?><?= $sl['max_views'] !== null ? '/' . (int) $sl['max_views'] : '' ?>
                    · Tạo: <?= wa_e((string) $sl['created_label']) ?>
                  </div>
                </div>
                <?php if (!$sl['dead']): ?>
                  <div class="wa-ver__menu">
                    <form method="post" action="<?= wa_e(wa_url('share_action.php')) ?>"
                          onsubmit="return confirm('Thu hồi link này ngay lập tức?');" style="margin:0">
                      <?= wa_csrf_field() ?>
                      <input type="hidden" name="action" value="revoke">
                      <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
                      <input type="hidden" name="link_id" value="<?= (int) $sl['id'] ?>">
                      <button class="btn btn--ghost btn--sm" type="submit" title="Thu hồi link">
                        <span class="ms ms-16">link_off</span>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===================== MODAL: ĐỔI TÊN PHIÊN BẢN =====================
     Mở từ menu 3 chấm trên dòng lịch sử phiên bản (chủ / quản trị viên);
     JS điền số phiên bản và tên hiện tại từ data-* của nút. Để trống để gỡ tên. -->
<div class="modal-backdrop" id="modal-rename-version" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">edit</span>
        <h2 class="t-headline-sm" style="font-weight:700">Đổi tên phiên bản</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" method="post" action="<?= wa_e(wa_url('library_action.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="version_rename">
      <input type="hidden" name="snapshot_id" id="rename-version-id" value="">
      <p class="t-body-md">Phiên bản <strong id="rename-version-no"></strong> sẽ hiển thị kèm tên này trong lịch sử phiên bản. Để trống để gỡ tên.</p>

      <div class="field">
        <label for="rename-version-label">Tên phiên bản</label>
        <input class="input" id="rename-version-label" name="label" type="text" maxlength="100">
      </div>

      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">
          <span class="ms ms-18">save</span> Lưu tên
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // Menu 3 chấm trên từng dòng lịch sử phiên bản + modal đổi tên phiên bản.
  (function () {
    function closeAllMenus() {
      document.querySelectorAll('.wa-ver__menu-list').forEach(function (list) { list.hidden = true; });
      document.querySelectorAll('.wa-ver__menu-btn').forEach(function (btn) { btn.setAttribute('aria-expanded', 'false'); });
    }
    document.querySelectorAll('.wa-ver__menu-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var list = btn.closest('.wa-ver__menu').querySelector('.wa-ver__menu-list');
        var wasHidden = list.hidden;
        closeAllMenus();
        if (wasHidden) {
          list.hidden = false;
          btn.setAttribute('aria-expanded', 'true');
        }
      });
    });
    document.addEventListener('click', function (e) {
      if (e.target.closest('.wa-ver__menu-btn')) { return; }
      closeAllMenus();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeAllMenus(); }
    });
    // Modal đổi tên: mở/đóng kiểu chung của dự án, điền dữ liệu từ nút menu.
    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = document.getElementById(btn.getAttribute('data-modal-open'));
        if (target) { target.removeAttribute('hidden'); }
        if (btn.getAttribute('data-modal-open') === 'modal-rename-version') {
          var d = btn.dataset;
          document.getElementById('rename-version-id').value = d.snapshotId || '';
          document.getElementById('rename-version-no').textContent = d.versionNo || '';
          document.getElementById('rename-version-label').value = d.versionLabel || '';
        }
      });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var box = btn.closest('.modal-backdrop');
        if (box) { box.setAttribute('hidden', ''); }
      });
    });
    document.querySelectorAll('.modal-backdrop').forEach(function (box) {
      box.addEventListener('click', function (e) {
        if (e.target === box) { box.setAttribute('hidden', ''); }
      });
    });

    // V3 / FEATURE 8: hiện/ẩn ô "số giờ tuỳ chỉnh" theo lựa chọn thời hạn.
    var sharePreset = document.getElementById('share-preset');
    var shareCustom = document.getElementById('share-custom-wrap');
    if (sharePreset && shareCustom) {
      var syncShareCustom = function () {
        shareCustom.hidden = (sharePreset.value !== 'custom');
      };
      sharePreset.addEventListener('change', syncShareCustom);
      syncShareCustom();
    }
  })();
</script>

<?php if ($canRefetch): ?>
<script>
  /* P-CHECK-AUTO: kiểm tra nền 1 bài. Trang tải xong mới gọi check_one.php;
     trang gốc đổi thì tự hiện banner vàng (hoặc gỡ banner cũ nếu giờ không
     còn đổi). Lỗi mạng / không tải được trang gốc thì giữ nguyên banner cũ. */
  (function () {
    var slot = document.getElementById('wa-check-banner-slot');
    if (!slot) { return; }
    var body = new FormData();
    body.append('page_id', '<?= (int) $context['page_id'] ?>');
    body.append('wa_csrf', '<?= wa_e(wa_csrf_token()) ?>');
    fetch('<?= wa_e(wa_url('check_one.php')) ?>', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || data.ok !== true || data.checked !== true) { return; }
        var banner = document.querySelector('.wa-banner--update');
        if (data.changed) {
          if (!banner) {
            var div = document.createElement('div');
            div.className = 'wa-banner wa-banner--update';
            div.setAttribute('role', 'status');
            div.innerHTML = '<span>Trang gốc có nội dung mới (kiểm tra ngày ' + data.checked_at + ') — bấm “Kiểm tra bản mới” để xem trước thay đổi.</span>'
              + '<span class="wa-banner__actions"><form method="post" action="<?= wa_e(wa_url('check_update.php')) ?>"><?= wa_csrf_field() ?><input type="hidden" name="page_id" value="<?= (int) $context['page_id'] ?>"><button class="btn btn--dark btn--sm" type="submit">Xem trước thay đổi</button></form></span>';
            slot.appendChild(div);
          }
        } else if (banner) {
          banner.remove();
        }
      })
      .catch(function () { /* giữ nguyên banner cũ */ });
  })();
</script>
<?php endif; ?>
<!-- ===================== MENU NỔI: THEO DÕI ĐOẠN ĐANG BÔI ĐEN (V3 ĐỢT 4 / F10) =====================
     Hiện khi người dùng bôi đen một đoạn văn bản trong #reading-content. -->
<div class="wa-selmenu" id="wa-selmenu" role="menu" aria-label="Thao tác với đoạn đang chọn">
  <span class="wa-selmenu__label" id="wa-selmenu-text"></span>
  <button class="btn btn--dark btn--sm" type="button" id="wa-watch-create">
    <span class="ms ms-16">visibility</span>
    <span>Theo dõi đoạn này</span>
  </button>
  <button class="btn btn--ghost btn--sm" type="button" id="wa-selmenu-close" aria-label="Đóng">
    <span class="ms ms-16">close</span>
  </button>
</div>

<!-- ===================== MODAL: LIÊN KẾT BÀI VIẾT (V3 ĐỢT 4 / F11) =====================
     Tìm bài viết trong các thư viện người dùng có quyền đọc, chọn loại quan hệ
     rồi gửi sang public/relation_action.php (action=create). -->
<div class="modal-backdrop" id="modal-relation" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">add_link</span>
        <h2 class="t-headline-sm" style="font-weight:700">Liên kết bài viết</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <div class="stack">
      <?php if (!$canWriteRelation): ?>
        <div class="wa-flash wa-flash--err" style="margin:0">
          <span class="ms ms-18 ms-fill">lock</span>
          <span>Bạn không có quyền ghi trên bài viết này nên không thể tạo liên kết.</span>
        </div>
      <?php endif; ?>

      <p class="t-body-sm t-muted" style="margin:0">
        Bài viết nguồn: <strong><?= wa_e($article['title']) ?></strong>
      </p>

      <div class="field">
        <label for="wa-rel-q">Tìm bài viết để liên kết</label>
        <input class="input" type="search" id="wa-rel-q"
               placeholder="Nhập tiêu đề hoặc URL của bài viết…" autocomplete="off">
      </div>

      <div class="wa-relsearch__list" id="wa-rel-results">
        <div class="wa-relsearch__empty">Nhập từ khoá để tìm bài viết trong các thư viện bạn có quyền đọc.</div>
      </div>

      <div class="field">
        <label for="wa-rel-picked">Bài viết đã chọn</label>
        <input class="input" type="text" id="wa-rel-picked" readonly value="">
      </div>

      <div class="field">
        <label for="wa-rel-type">Loại quan hệ</label>
        <select class="input" id="wa-rel-type">
          <?php foreach ($relationTypes as $relType => $relMeta): ?>
            <option value="<?= wa_e($relType) ?>"><?= wa_e($relMeta['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="t-body-sm t-muted" style="margin:6px 0 0" id="wa-rel-type-desc">
          <?= wa_e($relationTypes['related']['desc']) ?>
        </p>
      </div>

      <div class="field">
        <label for="wa-rel-note">Ghi chú (tuỳ chọn)</label>
        <textarea class="input" id="wa-rel-note" rows="2" maxlength="400"
                  placeholder="Ví dụ: bài này dùng làm nguồn số liệu cho bài kia."></textarea>
      </div>

      <div class="row" style="justify-content:flex-end;gap:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="button" id="wa-rel-submit" disabled>
          <span class="ms ms-18">link</span> Tạo liên kết
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===================== MODAL: SƠ ĐỒ QUAN HỆ (V3 ĐỢT 4 / F11) =====================
     SVG tự vẽ bằng JavaScript thuần; bấm vào một nút để mở bài viết. -->
<div class="modal-backdrop" id="modal-graph" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">schema</span>
        <h2 class="t-headline-sm" style="font-weight:700">Sơ đồ quan hệ bài viết</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <div class="stack">
      <svg class="wa-graph" id="wa-graph-svg" viewBox="0 0 720 480"
           role="img" aria-label="Sơ đồ quan hệ giữa các bài viết"></svg>
      <div class="wa-graph__legend" id="wa-graph-legend"></div>
      <p class="t-body-sm t-muted" style="margin:0">
        Chỉ hiển thị những bài viết mà bạn có quyền đọc. Bấm vào một nút để mở bài.
      </p>
    </div>
  </div>
</div>

<script>
  // ===========================================================================
  // V3 ĐỢT 4 / F10 — THEO DÕI MỘT ĐOẠN NỘI DUNG
  // ===========================================================================
  (function () {
    var content = document.getElementById('reading-content');
    var menu    = document.getElementById('wa-selmenu');
    var menuTxt = document.getElementById('wa-selmenu-text');
    var btnSave = document.getElementById('wa-watch-create');
    var btnClose= document.getElementById('wa-selmenu-close');
    if (!content || !menu || !btnSave) { return; }

    var snapshotId = <?= (int) $article['id'] ?>;
    var csrf    = '<?= wa_e(wa_csrf_token()) ?>';
    var actionUrl = '<?= wa_e(wa_url('watch_action.php')) ?>';
    var pending = null;   // vị trí đoạn đang chọn

    // Đường dẫn CSS của khối, TƯƠNG ĐỐI so với #reading-content (giống F4).
    function cssPath(el) {
      var parts = [];
      var cur = el;
      while (cur && cur !== content) {
        if (cur.nodeType !== 1) { break; }
        var tag = cur.nodeName.toLowerCase();
        var index = 1;
        var sib = cur.previousElementSibling;
        while (sib) {
          if (sib.nodeName.toLowerCase() === tag) { index++; }
          sib = sib.previousElementSibling;
        }
        parts.unshift(tag + ':nth-of-type(' + index + ')');
        cur = cur.parentNode;
      }
      return parts.join(' > ');
    }

    function blockOf(node) {
      var cur = node;
      while (cur && cur !== content) {
        if (cur.nodeType === 1) {
          var t = cur.nodeName.toLowerCase();
          if (['p', 'li', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'figcaption'].indexOf(t) !== -1) {
            return cur;
          }
        }
        cur = cur.parentNode;
      }
      return content;
    }

    // Vị trí ký tự theo CODE POINT để khớp mb_strlen() của PHP.
    function offsetIn(block, textNode, offset) {
      var total = 0;
      var found = false;
      var walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT, null);
      var n;
      while ((n = walker.nextNode())) {
        var chars = Array.from(n.nodeValue);
        if (n === textNode) {
          total += Math.min(offset, chars.length);
          found = true;
          break;
        }
        total += chars.length;
      }
      return found ? total : null;
    }

    function closeMenu() {
      menu.classList.remove('is-open');
      pending = null;
    }

    btnClose.addEventListener('click', closeMenu);

    document.addEventListener('mouseup', function (e) {
      if (e.target.closest('#wa-selmenu')) { return; }

      // Nếu đang bật chế độ Bôi đen của F4 thì không mở menu theo dõi
      // (tránh hai hộp thoại cùng lúc trên cùng một lần bôi đen).
      var annotBtn = document.getElementById('wa-annot-toggle');
      if (annotBtn && annotBtn.getAttribute('aria-pressed') === 'true') { return; }

      var sel = window.getSelection();
      if (!sel || sel.isCollapsed || sel.rangeCount === 0) { closeMenu(); return; }

      var text = sel.toString();
      if (!text || text.trim().length < 3) { closeMenu(); return; }

      var range = sel.getRangeAt(0);
      if (!content.contains(range.startContainer) || !content.contains(range.endContainer)) {
        closeMenu();
        return;
      }

      var block = blockOf(range.startContainer);
      if (!block || block === content) { closeMenu(); return; }

      var start = offsetIn(block, range.startContainer, range.startOffset);
      var end   = offsetIn(block, range.endContainer, range.endOffset);
      if (start === null || end === null || end <= start) { closeMenu(); return; }

      var chars = Array.from(block.textContent || '');
      var quote = text.replace(/\s+/g, ' ').trim().slice(0, 900);

      pending = {
        selector: cssPath(block),
        start_offset: start,
        end_offset: end,
        quote: quote,
        prefix: chars.slice(Math.max(0, start - 32), start).join(''),
        suffix: chars.slice(end, end + 32).join('')
      };

      menuTxt.textContent = quote.length > 60 ? quote.slice(0, 60) + '…' : quote;
      var rect = range.getBoundingClientRect();
      menu.style.left = (window.scrollX + rect.left + (rect.width / 2)) + 'px';
      menu.style.top  = (window.scrollY + rect.bottom + 8) + 'px';
      menu.classList.add('is-open');
    });

    btnSave.addEventListener('click', function () {
      if (!pending) { return; }
      var body = new FormData();
      body.append('wa_csrf', csrf);
      body.append('action', 'create');
      body.append('snapshot_id', String(snapshotId));
      body.append('selector', pending.selector);
      body.append('start_offset', String(pending.start_offset));
      body.append('end_offset', String(pending.end_offset));
      body.append('quote', pending.quote);
      body.append('prefix', pending.prefix);
      body.append('suffix', pending.suffix);

      btnSave.disabled = true;

      fetch(actionUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          btnSave.disabled = false;
          if (data && data.ok) {
            window.location.reload();
            return;
          }
          window.alert((data && data.message) ? data.message : 'Không lưu được mục theo dõi.');
        })
        .catch(function () {
          btnSave.disabled = false;
          window.alert('Không gửi được yêu cầu. Vui lòng thử lại.');
        });
    });
  })();

  // ===========================================================================
  // V3 ĐỢT 4 / F11 — LIÊN KẾT BÀI VIẾT + SƠ ĐỒ QUAN HỆ
  // ===========================================================================
  (function () {
    var csrf     = '<?= wa_e(wa_csrf_token()) ?>';
    var relUrl   = '<?= wa_e(wa_url('relation_action.php')) ?>';
    var pageId   = <?= (int) $context['page_id'] ?>;
    var canWrite = <?= $canWriteRelation ? 'true' : 'false' ?>;
    var typeMeta = <?= json_encode($relationTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    var qInput   = document.getElementById('wa-rel-q');
    var results  = document.getElementById('wa-rel-results');
    var picked   = document.getElementById('wa-rel-picked');
    var typeSel  = document.getElementById('wa-rel-type');
    var typeDesc = document.getElementById('wa-rel-type-desc');
    var noteInput= document.getElementById('wa-rel-note');
    var submit   = document.getElementById('wa-rel-submit');
    if (!qInput || !results || !submit) { return; }

    var chosen = null;
    var timer  = null;

    function post(action, payload, cb) {
      var body = new FormData();
      body.append('wa_csrf', csrf);
      body.append('action', action);
      Object.keys(payload || {}).forEach(function (k) { body.append(k, String(payload[k])); });

      fetch(relUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) { if (data && cb) { cb(data); } })
        .catch(function () {
          results.innerHTML = '<div class="wa-relsearch__empty">Không gửi được yêu cầu. Vui lòng thử lại.</div>';
        });
    }

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = s == null ? '' : String(s);
      return d.innerHTML;
    }

    // --- Tìm bài viết ---
    qInput.addEventListener('input', function () {
      if (timer) { clearTimeout(timer); }
      var q = qInput.value.trim();
      if (q.length < 2) {
        results.innerHTML = '<div class="wa-relsearch__empty">Nhập từ khoá để tìm bài viết trong các thư viện bạn có quyền đọc.</div>';
        return;
      }
      timer = setTimeout(function () {
        post('search', { q: q }, function (data) {
          if (!data || data.ok !== true) { return; }
          var items = data.items || [];
          if (!items.length) {
            results.innerHTML = '<div class="wa-relsearch__empty">Không tìm thấy bài viết nào phù hợp.</div>';
            return;
          }
          results.innerHTML = '';
          items.forEach(function (it) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wa-relsearch__item';
            btn.setAttribute('data-page-id', String(it.page_id));
            btn.innerHTML = '<span style="flex:1"><span style="font-weight:600;display:block">' + esc(it.title)
                          + '</span><span class="t-label-sm t-muted">' + esc(it.library_name) + '</span></span>';
            btn.addEventListener('click', function () {
              chosen = it;
              picked.value = it.title + ' · ' + it.library_name;
              results.querySelectorAll('.wa-relsearch__item').forEach(function (b) { b.classList.remove('is-picked'); });
              btn.classList.add('is-picked');
              submit.disabled = false;
            });
            results.appendChild(btn);
          });
        });
      }, 300);
    });

    // --- Mô tả loại quan hệ ---
    if (typeSel && typeDesc) {
      var syncDesc = function () {
        var m = typeMeta[typeSel.value];
        typeDesc.textContent = m ? m.desc : '';
      };
      typeSel.addEventListener('change', syncDesc);
      syncDesc();
    }

    // --- Tạo liên kết ---
    submit.addEventListener('click', function () {
      if (!chosen || !canWrite) { return; }
      submit.disabled = true;
      post('create', {
        source_page_id: pageId,
        target_page_id: chosen.page_id,
        relation_type: typeSel ? typeSel.value : 'related',
        note: noteInput ? noteInput.value : ''
      }, function (data) {
        submit.disabled = false;
        if (data && data.ok) {
          window.location.reload();
          return;
        }
        window.alert((data && data.message) ? data.message : 'Không tạo được liên kết.');
      });
    });

    // --- Sửa loại quan hệ / ghi chú (nút Sửa trong danh sách) ---
    document.querySelectorAll('[data-relation-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var rid = btn.getAttribute('data-relation-edit');
        var t   = btn.getAttribute('data-relation-type') || 'related';
        var n   = btn.getAttribute('data-relation-note') || '';
        var newType = window.prompt(
          'Loại quan hệ mới (related / references / supplements / contrasts):', t);
        if (newType === null) { return; }
        newType = newType.trim();
        if (typeMeta[newType] === undefined) {
          window.alert('Loại quan hệ không hợp lệ.');
          return;
        }
        var newNote = window.prompt('Ghi chú quan hệ:', n);
        if (newNote === null) { return; }

        post('update', { relation_id: rid, relation_type: newType, note: newNote }, function (data) {
          if (data && data.ok) {
            window.location.reload();
            return;
          }
          window.alert((data && data.message) ? data.message : 'Không cập nhật được quan hệ.');
        });
      });
    });

    // --- Sơ đồ quan hệ (SVG tự vẽ) ---
    var svg    = document.getElementById('wa-graph-svg');
    var legend = document.getElementById('wa-graph-legend');
    if (!svg) { return; }

    function drawGraph(graph) {
      var nodes = (graph && graph.nodes) || [];
      var edges = (graph && graph.edges) || [];

      if (!nodes.length) {
        svg.innerHTML = '<text x="360" y="240" text-anchor="middle" font-size="13" fill="#74777E">'
                      + 'Chưa có quan hệ nào để hiển thị.</text>';
        if (legend) { legend.innerHTML = ''; }
        return;
      }

      var W = 720, H = 480, cx = W / 2, cy = H / 2;
      var R = nodes.length > 1 ? Math.min(170, 60 + nodes.length * 26) : 0;
      var pos = {};

      nodes.forEach(function (n, i) {
        var angle = (2 * Math.PI * i) / nodes.length - Math.PI / 2;
        pos[n.id] = n.is_self
          ? { x: cx, y: cy }
          : { x: cx + R * Math.cos(angle), y: cy + R * Math.sin(angle) };
      });

      var parts = [];

      edges.forEach(function (e) {
        var a = pos[e.from], b = pos[e.to];
        if (!a || !b) { return; }
        parts.push('<line class="wa-graph__edge" x1="' + a.x + '" y1="' + a.y + '" x2="' + b.x + '" y2="' + b.y + '"></line>');
        var mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
        parts.push('<text class="wa-graph__edge-label" x="' + mx + '" y="' + (my - 4) + '" text-anchor="middle">'
                 + esc(e.label) + '</text>');
      });

      nodes.forEach(function (n) {
        var p = pos[n.id];
        var short = n.title.length > 26 ? n.title.slice(0, 26) + '…' : n.title;
        var g = '<g class="wa-graph__node' + (n.is_self ? ' is-self' : '') + '" data-page-id="' + n.id
              + '" data-snapshot-id="' + n.snapshot_id + '">'
              + '<circle cx="' + p.x + '" cy="' + p.y + '" r="22"></circle>'
              + '<text x="' + p.x + '" y="' + (p.y + 36) + '" text-anchor="middle">' + esc(short) + '</text>'
              + '<title>' + esc(n.title) + ' — ' + esc(n.library) + '</title>'
              + '</g>';
        parts.push(g);
      });

      svg.innerHTML = parts.join('');

      svg.querySelectorAll('.wa-graph__node').forEach(function (g) {
        g.addEventListener('click', function () {
          var sid = parseInt(g.getAttribute('data-snapshot-id') || '0', 10);
          if (sid > 0) {
            window.location.href = '<?= wa_e(wa_url('reader.php')) ?>?id=' + sid;
          }
        });
      });

      if (legend) {
        var seen = {};
        legend.innerHTML = edges.map(function (e) {
          if (seen[e.type]) { return ''; }
          seen[e.type] = true;
          return '<span class="chip chip--plain">' + esc(e.label) + '</span>';
        }).join('') + '<span class="t-label-sm t-muted">Bấm vào một nút để mở bài viết.</span>';
      }
    }

    var graphLoaded = false;
    var graphModal = document.getElementById('modal-graph');

    function loadGraph() {
      post('graph', { source_page_id: pageId }, function (data) {
        if (data && data.ok) {
          drawGraph(data.graph);
          graphLoaded = true;
        }
      });
    }

    var graphBtn = document.getElementById('wa-graph-open');
    if (graphBtn) {
      graphBtn.addEventListener('click', function () {
        if (graphLoaded) { return; }
        drawGraph(null);
        loadGraph();
      });
    }
    if (graphModal) {
      graphModal.addEventListener('click', function (e) {
        if (e.target === graphModal) { return; }
      });
    }
  })();
</script>
</body>
</html>