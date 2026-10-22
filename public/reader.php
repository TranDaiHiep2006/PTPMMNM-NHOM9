<?php
/**
 * reader.php — Trình đọc ngoại tuyến (offline reader).
 *
 * Bám theo bản tham chiếu (code3.html): nền giấy ngà ivory, cột đọc tối đa 780px,
 * thanh công cụ có nút A- / A+ (JavaScript thuần), tiến độ đọc, hộp nguồn gốc
 * (URL, thời gian lưu, phiên bản snapshot), một bài viết tiếng Việt đầy đủ và
 * bảng tổng hợp phương pháp làm sạch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

// reader.php nhận id snapshot dạng số: ?id=<snapshot id>
$snapshotId = (int) ($_GET['id'] ?? 0);
$article = $snapshotId > 0 ? wa_find_snapshot($snapshotId) : null;

if ($article === null) {
    // Không có snapshot hợp lệ (hoặc không có quyền xem) -> trả về 404.
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

$librarySlug = (string) $article['library_slug'];
$libraryName = (string) $article['library_name'];

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
</head>
<body class="reader" id="reader-body">

<div class="reader__progress" id="reader-progress"></div>

<!-- ===================== THANH CÔNG CỤ ===================== -->
<header class="reader__bar">
  <a class="reader__back" href="<?= wa_e(wa_url('library.php?slug=' . urlencode($librarySlug))) ?>">
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

    <span class="chip chip--teal">
      <span class="ms ms-14">shield</span>
      Ngoại tuyến 100%
    </span>
  </div>
</header>

<!-- ===================== CỘT ĐỌC ===================== -->
<article class="reader__col">
  <header class="reader__head">
    <div class="row row--between" style="gap:8px">
      <span class="chip chip--blue">
        <span class="ms ms-14">terminal</span>
        <?= wa_e(strtoupper($libraryName)) ?>
      </span>
      <span class="t-label-sm t-mono" style="color:var(--wa-action)">
        Bản ghi snapshot v<?= (int) $article['version_no'] ?>.0 • SHA-256 xác thực
      </span>
    </div>

    <h1 class="reader__title"><?= wa_e($article['title']) ?></h1>

    <!-- Hộp nguồn gốc / thông tin bảo toàn -->
    <div class="reader__meta">
      <div class="reader__meta-row">
        <span class="row" style="gap:4px;color:var(--wa-text);font-weight:500">
          <span class="ms ms-16" style="color:var(--wa-action)">person_edit</span>
          Tác giả: <?= wa_e($article['creator_name']) ?>
        </span>
        <span class="t-muted">•</span>
        <span class="row" style="gap:4px">
          <span class="ms ms-16" style="color:var(--wa-action)">public</span>
          Nguồn: <span style="color:var(--wa-primary);font-weight:500"><?= wa_e($article['domain']) ?></span>
        </span>
        <span class="t-muted">•</span>
        <span class="row" style="gap:4px">
          <span class="ms ms-16" style="color:var(--wa-action)">history</span>
          Lưu snapshot: <?= wa_e($article['saved_at']) ?>
        </span>
      </div>
      <div class="reader__meta-row" style="padding-top:4px">
        <span class="t-label-sm t-muted">
          Đường dẫn gốc: <span class="t-mono" style="color:var(--wa-text-soft)"><?= wa_e($article['source_url']) ?></span>
        </span>
      </div>
      <div class="reader__meta-row" style="padding-top:4px">
        <span class="t-label-sm t-muted">
          Dung lượng tài liệu: <strong style="color:var(--wa-text)"><?= wa_e($article['size_label']) ?></strong>
          (Bao gồm <?= (int) $article['image_count'] ?> ảnh đã tải về máy chủ)
        </span>
        <span class="t-muted">•</span>
        <span class="t-label-sm" style="color:var(--wa-teal-deep);font-weight:600" >
          <span class="ms ms-14">lock</span> Độc lập hoàn toàn với trang gốc
        </span>
      </div>
    </div>

    <div class="reader__divider"></div>
  </header>

  <!-- ===================== NỘI DUNG BÀI VIẾT (từ bảng snapshots) ===================== -->
  <div class="reader__body" id="reading-content">
<?= $article['content_html'] ?>
  </div>

  <!-- ===================== CHÂN TRANG ĐỌC ===================== -->
  <div class="reader__divider" style="margin-top:40px"></div>

  <footer class="reader__foot">
    <div class="row t-label-md" style="gap:8px;color:var(--wa-text-muted)">
      <span class="ms ms-16 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span>
      <span>Đã đọc hết bài viết • Lưu trữ bởi WebArchive</span>
    </div>
    <a class="btn btn--primary" href="<?= wa_e(wa_url('library.php?slug=' . urlencode($librarySlug))) ?>">
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
</script>
</body>
</html>
