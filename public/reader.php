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

$articleId = (string) ($_GET['id'] ?? 'SNAP-8941');

$article = null;
foreach (sample_articles() as $item) {
    if ($item['id'] === $articleId) {
        $article = $item;
        break;
    }
}

if ($article === null) {
    $article = sample_articles()[0];
}

$library = sample_find_library('cntt-lap-trinh');

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
  <a class="reader__back" href="<?= wa_e(wa_url('library.php?slug=cntt-lap-trinh')) ?>">
    <span class="ms ms-16">arrow_back</span>
    <span class="reader__back-label">Quay lại thư viện</span>
  </a>

  <div class="reader__status">
    <span class="dot dot--live"></span>
    <span>Bài viết đã lưu trữ an toàn</span>
    <span class="t-muted">/</span>
    <span>Snapshot:</span>
    <strong class="t-mono">#<?= wa_e($article['id']) ?></strong>
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
        LẬP TRÌNH WEB &amp; KIẾN TRÚC HỆ THỐNG
      </span>
      <span class="t-label-sm t-mono" style="color:var(--wa-action)">
        Bản ghi snapshot <?= wa_e($article['version']) ?> • SHA-256 xác thực
      </span>
    </div>

    <h1 class="reader__title"><?= wa_e($article['title']) ?></h1>

    <!-- Hộp nguồn gốc / thông tin bảo toàn -->
    <div class="reader__meta">
      <div class="reader__meta-row">
        <span class="row" style="gap:4px;color:var(--wa-text);font-weight:500">
          <span class="ms ms-16" style="color:var(--wa-action)">person_edit</span>
          Tác giả: <?= wa_e($article['author']) ?>
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
          Đường dẫn gốc: <span class="t-mono" style="color:var(--wa-text-soft)"><?= wa_e($article['url']) ?></span>
        </span>
      </div>
      <div class="reader__meta-row" style="padding-top:4px">
        <span class="t-label-sm t-muted">
          Dung lượng tài liệu: <strong style="color:var(--wa-text)"><?= wa_e($article['size']) ?></strong>
          (Bao gồm <?= $article['images'] ?> ảnh đã tải về máy chủ)
        </span>
        <span class="t-muted">•</span>
        <span class="t-label-sm" style="color:var(--wa-teal-deep);font-weight:600" >
          <span class="ms ms-14">lock</span> Độc lập hoàn toàn với trang gốc
        </span>
      </div>
    </div>

    <div class="reader__divider"></div>
  </header>

  <!-- ===================== NỘI DUNG BÀI VIẾT ===================== -->
  <div class="reader__body" id="reading-content">
    <p class="reader__lead">
      Trong kỷ nguyên mà nội dung số liên tục bị cập nhật, sửa đổi hoặc xóa bỏ hoàn toàn mà không để lại
      dấu vết, nhu cầu xây dựng kho tư liệu nghiên cứu bất biến trở nên tối quan trọng. Bài phân tích này
      giải thích giải pháp kỹ thuật bóc tách cây DOM HTML, loại bỏ mã độc tiềm ẩn, chuyển đổi liên kết
      nội bộ và tải toàn bộ hình ảnh về bộ nhớ cục bộ để phục vụ đọc ngoại tuyến an toàn suốt nhiều thập kỷ.
    </p>

    <figure class="reader__figure">
      <img src="<?= wa_e(wa_asset('img/reader-diagram.svg')) ?>" alt="Sơ đồ kiến trúc bóc tách nội dung HTML tĩnh và lưu trữ hình ảnh cục bộ" width="640" height="360">
      <figcaption>
        Hình 1 — Luồng xử lý snapshot: trang gốc → HTML tĩnh &amp; ảnh cục bộ → cơ sở dữ liệu → trình đọc ngoại tuyến.
        Ảnh được tải về máy chủ nội bộ tại thời điểm lưu trang.
      </figcaption>
    </figure>

    <section>
      <h2><i>01.</i> Tại sao cần lưu trữ nội dung tĩnh độc lập?</h2>
      <p>
        Hầu hết các công cụ đánh dấu trang (bookmark) hiện đại chỉ lưu trữ duy nhất một
        <em>siêu liên kết (hyperlink)</em>. Tuy nhiên, theo các nghiên cứu về độ bền của web (link rot),
        hơn <strong>38% các đường dẫn nghiên cứu kỹ thuật từ năm 2013 hiện đã rơi vào trạng thái 404</strong>
        hoặc bị chèn quảng cáo thay thế nội dung gốc.
      </p>

      <blockquote>
        <p>
          Tri thức số rất mong manh. Khi một trang web đóng cửa hoặc thay đổi cấu trúc URL, toàn bộ dữ liệu
          tham khảo quý giá sẽ biến mất. Bản lưu HTML tĩnh cục bộ là cách duy nhất đảm bảo tính trường tồn
          của tư liệu nghiên cứu.
        </p>
        <footer>— Tạp chí Kiến trúc Dữ liệu Độc lập, Số 14/2026</footer>
      </blockquote>

      <p>
        Để đảm bảo tính toàn vẹn khi đọc mà không kích hoạt cookie theo dõi hoặc tải các tập lệnh mã độc
        của bên thứ ba, chúng ta phải chuyển giao thức duyệt web trực tiếp thành một không gian lưu trữ
        tĩnh cách ly hoàn toàn.
      </p>
    </section>

    <section>
      <h2><i>02.</i> Quy trình xử lý bóc tách và làm sạch mã nguồn</h2>
      <p>
        Một đường ống (pipeline) xử lý nội dung đáng tin cậy bao gồm 4 giai đoạn nghiêm ngặt, chuyển đổi mã
        nguồn thô chứa đầy script phân tích thành định dạng HTML sạch chỉ chứa văn bản có cấu trúc và hình
        ảnh tĩnh được xác thực:
      </p>

      <ol>
        <li>
          <span>1</span>
          <div>
            <strong style="font-family:var(--wa-font-ui);font-size:16px;font-weight:600;color:var(--wa-text)">Thu thập mã thô &amp; Chuẩn hóa bảng mã:</strong>
            <span class="t-body-md t-soft">Sử dụng cURL với cơ chế giả lập trình duyệt nghiên cứu, ép buộc chuyển mã về định dạng UTF-8 hợp lệ.</span>
          </div>
        </li>
        <li>
          <span>2</span>
          <div>
            <strong style="font-family:var(--wa-font-ui);font-size:16px;font-weight:600;color:var(--wa-text)">Lọc bỏ mã thực thi (Sanitization):</strong>
            <span class="t-body-md t-soft">Cắt tỉa triệt để toàn bộ thẻ &lt;script&gt;, &lt;iframe&gt;, &lt;object&gt;, thuộc tính onclick, inline styles và liên kết quảng cáo.</span>
          </div>
        </li>
        <li>
          <span>3</span>
          <div>
            <strong style="font-family:var(--wa-font-ui);font-size:16px;font-weight:600;color:var(--wa-text)">Bản địa hóa tài nguyên ảnh (Asset Localizing):</strong>
            <span class="t-body-md t-soft">Quét các thẻ &lt;img src="..."&gt;, tải nhị phân về ổ đĩa cục bộ, tính mã băm MD5 và viết lại thuộc tính thành đường dẫn nội bộ.</span>
          </div>
        </li>
        <li>
          <span>4</span>
          <div>
            <strong style="font-family:var(--wa-font-ui);font-size:16px;font-weight:600;color:var(--wa-text)">Kiểm tra allowlist &amp; chống lỗi:</strong>
            <span class="t-body-md t-soft">Mọi đích tải về — kể cả sau khi chuyển hướng — đều phải nằm trong danh sách tên miền được duyệt. Trang lỗi 404 hoặc timeout không bao giờ được lưu thành bài viết hợp lệ.</span>
          </div>
        </li>
      </ol>

      <h3>Ví dụ về đường dẫn cục bộ hoá</h3>
      <pre><code>&lt;img src="https://cdn.example.com/2026/10/architecture.png"
     alt="Sơ đồ kiến trúc"&gt;

→  &lt;img src="/assets/snapshots/2026/10/architecture.png"
     alt="Sơ đồ kiến trúc"
     data-original="https://cdn.example.com/2026/10/architecture.png"&gt;</code></pre>
    </section>

    <section>
      <h2><i>03.</i> Bảng tổng hợp phương pháp làm sạch</h2>
      <p>
        Bảng dưới đây tóm tắt cách từng thành phần HTML của trang gốc được xử lý trước khi đưa vào kho
        snapshot và cơ chế tìm kiếm nội dung.
      </p>

      <div class="reader__table">
        <table>
          <thead>
            <tr>
              <th>Thành phần HTML</th>
              <th>Phương thức xử lý an toàn</th>
              <th>Kết quả lưu trữ</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><code>&lt;script&gt;</code> &amp; <code>&lt;iframe&gt;</code></td>
              <td style="color:var(--wa-error);font-weight:500">Bóc tách toàn bộ &amp; Hủy thẻ</td>
              <td class="t-muted">Ngăn chặn hoàn toàn XSS &amp; Tracking</td>
            </tr>
            <tr>
              <td><code>&lt;img src="https://..."&gt;</code></td>
              <td style="color:var(--wa-teal-deep);font-weight:500">Tải nhị phân &amp; Đổi đường dẫn nội bộ</td>
              <td class="t-muted">Hiển thị mượt mà không cần mạng Internet</td>
            </tr>
            <tr>
              <td>CSS Stylesheet CDN</td>
              <td style="font-weight:500">Thay thế bằng bộ Typographic CSS chuẩn</td>
              <td class="t-muted">Tránh vỡ giao diện khi domain CDN die</td>
            </tr>
            <tr>
              <td>Văn bản bài viết gốc</td>
              <td style="color:var(--wa-primary);font-weight:600">Sanitize &amp; Lưu UTF8MB4 vào MySQL</td>
              <td class="t-muted">Hỗ trợ tìm kiếm Fulltext tốc độ cao</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p>
        Bằng phương pháp này, trải nghiệm đọc nội dung được chuyển đổi trọn vẹn: không còn sự gián đoạn của
        quảng cáo trượt, không còn nỗi lo các bài hướng dẫn cốt lõi bị gỡ bỏ, và người dùng hoàn toàn làm chủ
        nguồn tri thức nghiên cứu của bản thân.
      </p>
    </section>

    <div class="reader__note">
      <span class="ms ms-28" style="color:var(--wa-primary);flex:0 0 28px;margin-top:2px">verified_user</span>
      <div>
        <b>Tóm tắt nguyên lý trường tồn kỹ thuật số</b>
        <p>
          Bản lưu trữ HTML tĩnh không phụ thuộc vào trạng thái trực tuyến của máy chủ tác giả. Mọi hình ảnh,
          kiểu chữ và nội dung văn bản đều được lưu đóng gói ngay trong hệ sinh thái của WebArchive cá nhân bạn.
        </p>
      </div>
    </div>
  </div>

  <!-- ===================== CHÂN TRANG ĐỌC ===================== -->
  <div class="reader__divider" style="margin-top:40px"></div>

  <footer class="reader__foot">
    <div class="row t-label-md" style="gap:8px;color:var(--wa-text-muted)">
      <span class="ms ms-16 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span>
      <span>Đã đọc hết bài viết • Lưu trữ bởi WebArchive</span>
    </div>
    <a class="btn btn--primary" href="<?= wa_e(wa_url('library.php?slug=cntt-lap-trinh')) ?>">
      <span class="ms ms-16">arrow_back</span>
      <span>Trở về Thư viện CNTT &amp; Lập Trình</span>
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
