<?php
/**
 * index.php — TRANG CHỦ DÀNH CHO KHÁCH (guest landing).
 *
 * Nội dung bám theo bản tham chiếu code4.html: hero, mockup kho lưu trữ,
 * 3 khối tính năng, quy trình 3 bước, không gian đọc, banner kêu gọi hành động.
 *
 * LƯU Ý: dùng header_guest.php (KHÔNG có user chip / Đăng xuất / menu workspace).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'WebArchive — Lưu tri thức hôm nay. Đọc lại bất cứ lúc nào.';

require __DIR__ . '/../templates/header_guest.php';
?>

<!-- ===================== HERO ===================== -->
<section class="hero">
  <div class="hero__inner">
    <div class="hero__badge">
      <span class="dot dot--live"></span>
      <span>Phiên bản v2.4</span>
      <span class="t-muted">•</span>
      <span class="t-muted">Đọc ngoại tuyến an toàn &amp; lưu trữ tĩnh HTML</span>
    </div>

    <h1 class="hero__title">
      Lưu tri thức hôm nay.<br>
      <span>Đọc lại bất cứ lúc nào.</span>
    </h1>

    <p class="hero__lead">
      Lưu nội dung bài viết từ những trang Web được hỗ trợ, sắp xếp vào thư viện cá nhân
      và đọc lại từ bản lưu ngay cả khi trang gốc không còn hoạt động.
    </p>

    <div class="hero__cta">
      <a class="btn btn--lg btn--dark" href="<?= wa_e(wa_url('register.php')) ?>">
        <span>Bắt đầu lưu bài viết</span>
        <span class="ms ms-18">arrow_forward</span>
      </a>
      <a class="btn btn--lg btn--light" href="#ban-luu-mau">
        <span class="ms ms-18" style="color:var(--wa-action)">visibility</span>
        <span>Khám phá bản lưu mẫu</span>
      </a>
    </div>

    <div class="hero__micro">
      <span><span class="ms ms-14 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span> Không quảng cáo bám đuổi</span>
      <span><span class="ms ms-14 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span> Bản snapshot vĩnh cửu</span>
      <span><span class="ms ms-14 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span> Tối ưu đọc ban đêm</span>
    </div>
  </div>
</section>

<!-- ===================== MOCKUP BẢN LƯU MẪU ===================== -->
<section class="page page--plain" style="padding-top:0" id="ban-luu-mau">
  <div class="mock">
    <div class="mock__bar">
      <div class="mock__dots">
        <span class="dot" style="width:12px;height:12px;background:rgba(186,26,26,.7)"></span>
        <span class="dot" style="width:12px;height:12px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="dot" style="width:12px;height:12px;background:rgba(53,165,154,.6)"></span>
        <span class="mock__url">
          <span class="ms ms-14">lock</span>
          <span>archive.local/snapshot/view?id=arc_89410</span>
        </span>
      </div>
      <div class="chip chip--plain">
        <span class="ms ms-14" style="color:var(--wa-teal-deep)">verified</span>
        <span>Mã kiểm chứng: sha256:d8c2e9</span>
      </div>
    </div>

    <div class="mock__body">
      <div class="row" style="gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <span class="chip chip--blue">PHP &amp; MySQL Architecture</span>
        <span class="chip chip--plain">4.2 MB Snapshot</span>
        <span class="chip chip--teal">
          <span class="dot dot--teal"></span> HTML sạch đã bóc tách
        </span>
        <span class="chip chip--plain">4 ảnh đã tải về máy chủ</span>
      </div>

      <div class="mock__doc">
        <div class="row t-body-sm t-muted" style="gap:8px;margin-bottom:8px">
          <span class="ms ms-16">public</span>
          <span>nguồn: dev-insights.org/posts/php-mysql-static-archive</span>
          <span>•</span>
          <span>Đã sao lưu: 14:28, 21/10/2026</span>
        </div>

        <h2 class="t-reader-title" style="color:var(--wa-primary);font-size:26px;line-height:36px">Tìm hiểu kiến trúc PHP &amp; MySQL trong lưu trữ Web tĩnh</h2>

        <div class="row t-body-sm t-muted" style="gap:16px;margin-top:8px;padding-bottom:16px">
          <span>Tác giả: Nguyễn Đức Trọng</span>
          <span>•</span>
          <span>Thời gian đọc: 7 phút</span>
          <span class="chip chip--teal" style="gap:4px">
            <span class="ms ms-14">offline_pin</span> Sẵn sàng đọc ngoại tuyến
          </span>
        </div>

        <div class="mock__placeholder">
          <span class="row" style="gap:8px">
            <span class="ms ms-28">image</span>
            <span class="t-body-sm">Ảnh minh hoạ đã tải về máy chủ nội bộ</span>
          </span>
        </div>

        <p class="t-reader-body" style="margin-top:16px;font-size:16px;line-height:28px;color:var(--wa-text-soft)">
          Khi xây dựng các thư viện lưu trữ ngoại tuyến độc lập, nguyên tắc cốt lõi là bóc tách toàn bộ
          tài nguyên động (JavaScript theo dõi, biểu mẫu bên ngoài, quảng cáo) và chỉ giữ lại cấu trúc
          ngữ nghĩa DOM thuần túy. Quá trình chuyển hóa các thẻ ảnh và stylesheet thành đường dẫn cục bộ
          giúp bài viết có thể hiển thị nguyên vẹn 10 năm sau mà không phụ thuộc vào tên miền gốc.
        </p>

        <div class="row row--between" style="gap:8px;margin-top:16px;padding:8px 16px;border-radius:8px;background:var(--wa-surface-low)">
          <div class="row" style="gap:8px">
            <span class="chip chip--plain">Serif</span>
            <span class="chip chip--plain">Sans</span>
            <span class="t-label-sm t-muted">Cỡ chữ: 18px</span>
          </div>
          <div class="row t-label-sm t-muted" style="gap:6px">
            <span class="ms ms-16">download_for_offline</span>
            <span>Đã nén ZIP (4.2 MB)</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== 3 TÍNH NĂNG ===================== -->
<section class="page page--plain" id="tinh-nang">
  <div class="section-head section-head--split">
    <div>
      <span class="t-label-md t-upper t-accent">Giá trị cốt lõi</span>
      <h2 class="section-title">Được thiết kế cho người nghiên cứu sâu</h2>
    </div>
    <p class="section-sub">
      Không chỉ đánh dấu trang (bookmark). WebArchive lưu trữ nguyên vẹn văn bản và tệp hình ảnh
      vào hệ sinh thái của bạn.
    </p>
  </div>

  <div class="feature-grid">
    <div class="feature">
      <div>
        <div class="feature__icon"><span class="ms ms-24">folder_special</span></div>
        <span class="feature__eyebrow">Tổ chức khoa học</span>
        <h3>Thư viện ngăn nắp</h3>
        <p>
          Phân loại bài viết theo chủ đề chuyên sâu, gán nhãn đa tầng linh hoạt và đồng bộ mục lục
          tra cứu giúp việc truy xuất tài liệu nghiên cứu diễn ra tức thì.
        </p>
      </div>
      <div class="feature__foot">
        <div class="feature__foot-top">
          <span>Thư mục ví dụ</span>
          <b>3 bộ sưu tập</b>
        </div>
        <div class="feature__tags">
          <span class="chip chip--plain">Khoa học máy tính</span>
          <span class="chip chip--plain">Kiến trúc hệ thống</span>
          <span class="chip chip--plain">Bảo mật web</span>
        </div>
      </div>
    </div>

    <div class="feature">
      <div>
        <div class="feature__icon feature__icon--blue"><span class="ms ms-24">save_as</span></div>
        <span class="feature__eyebrow">Miễn nhiễm biến động</span>
        <h3>Lưu nội dung an toàn</h3>
        <p>
          Bóc tách mã HTML tĩnh chuẩn xác, tự động tải và liên kết hình ảnh cục bộ độc lập. An tâm tuyệt đối
          ngay cả khi trang gốc đổi cấu trúc, xuất hiện tường thu phí hoặc biến mất hoàn toàn (Lỗi 404).
        </p>
      </div>
      <div class="feature__foot">
        <div class="feature__foot-top">
          <span>Bảo vệ đường dẫn hỏng (Link rot)</span>
          <b class="t-teal">100% Tĩnh</b>
        </div>
        <div class="feature__tags">
          <span class="chip chip--teal">SHA-256 xác thực</span>
          <span class="chip chip--teal">0 liên kết lỗi</span>
        </div>
      </div>
    </div>

    <div class="feature">
      <div>
        <div class="feature__icon feature__icon--mint"><span class="ms ms-24">menu_book</span></div>
        <span class="feature__eyebrow">Không gian thiền định</span>
        <h3>Đọc lại độc lập</h3>
        <p>
          Trình đọc tối giản (Distraction-free Reader) lược bỏ toàn bộ cửa sổ pop-up phiền toái,
          font chữ Merriweather chuẩn mực xuất bản báo chí quốc tế, hoạt động trơn tru ngay cả khi
          không có kết nối mạng Internet.
        </p>
      </div>
      <div class="feature__foot">
        <div class="feature__foot-top">
          <span>Trải nghiệm người đọc</span>
          <b>Tỉ lệ 65-75 ký tự/dòng</b>
        </div>
        <p class="t-reader-body" style="font-size:13px;line-height:22px;color:var(--wa-text-soft);font-style:italic;margin-top:6px">
          "Sự tĩnh lặng quý giá trong thời đại đầy rẫy thông tin phân mảnh."
        </p>
      </div>
    </div>
  </div>
</section>

<!-- ===================== QUY TRÌNH 3 BƯỚC ===================== -->
<section class="page page--plain" id="quy-trinh">
  <div class="steps-panel">
    <div class="steps-head">
      <span class="t-label-md t-upper t-accent">Quy trình đơn giản</span>
      <h2 class="section-title" style="margin-top:4px">Cách thức hoạt động trong 3 bước</h2>
      <p class="t-body-md t-soft" style="margin-top:8px">
        Không yêu cầu cài đặt tiện ích mở rộng phức tạp. Mọi quy trình xử lý nội dung được thực hiện
        chuẩn chỉ trên máy chủ cá nhân.
      </p>
    </div>

    <div class="steps-grid">
      <div class="step">
        <div class="step__top">
          <span class="step__num">01</span>
          <span class="step__icon"><span class="ms ms-18">link</span></span>
        </div>
        <h3>Dán liên kết bài viết</h3>
        <p>
          Hệ thống tự động phân tích và kiểm tra tính hợp lệ của URL, xác nhận cấu trúc nguồn từ
          trang báo điện tử hoặc tài liệu công nghệ.
        </p>
        <div class="step__note">https://themorningnews.org/article/deep-work</div>
      </div>

      <div class="step">
        <div class="step__top">
          <span class="step__num">02</span>
          <span class="step__icon"><span class="ms ms-18">data_object</span></span>
        </div>
        <h3>Bóc tách HTML &amp; lưu hình ảnh</h3>
        <p>
          Máy chủ tách lọc tiêu đề, nội dung chính, loại bỏ kịch bản theo dõi và tải toàn bộ ảnh đính kèm
          lưu về bộ nhớ độc lập.
        </p>
        <div class="step__note" style="color:var(--wa-teal-deep)">
          <span class="ms ms-14">tune</span> Chuẩn hóa DOM • Nén tài nguyên tĩnh
        </div>
      </div>

      <div class="step">
        <div class="step__top">
          <span class="step__num">03</span>
          <span class="step__icon"><span class="ms ms-18">chrome_reader_mode</span></span>
        </div>
        <h3>Đọc lại trọn vẹn ngoại tuyến</h3>
        <p>
          Mở bài viết trong không gian đọc ấm áp, chỉnh cỡ chữ tùy ý và thưởng thức văn bản thuần khiết
          mà không cần kết nối mạng.
        </p>
        <div class="step__note" style="color:var(--wa-primary)">
          <span class="ms ms-14">auto_stories</span> Không xao nhãng • Tự do biên mục
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== KHÔNG GIAN ĐỌC ===================== -->
<section class="page page--plain" id="khong-gian-doc">
  <div class="showcase">
    <div class="showcase__text">
      <span class="t-label-md t-upper t-accent">Không gian tập trung</span>
      <h2 class="section-title" style="margin-top:4px">Đọc theo cách mà mắt bạn xứng đáng được trải nghiệm</h2>
      <p class="section-sub" style="margin-top:16px;line-height:1.7">
        Được thiết kế riêng cho những ai dành hàng giờ đắm mình trong các bài luận dài. Tùy chỉnh màu
        giấy ngà dịu mắt, phông chữ báo in kinh điển và theo dõi tiến độ đọc theo thời gian thực.
      </p>

      <ul class="checklist">
        <li>
          <span class="ms ms-18 ms-fill">check_box</span>
          <div>
            <b>Tự chủ dữ liệu hoàn toàn</b>
            <p>Toàn bộ dữ liệu nằm trên máy chủ nội bộ hoặc cơ sở dữ liệu riêng của bạn.</p>
          </div>
        </li>
        <li>
          <span class="ms ms-18 ms-fill">check_box</span>
          <div>
            <b>Xuất dữ liệu mở định dạng tiêu chuẩn</b>
            <p>Dễ dàng tải về bản nén ZIP chứa file HTML đơn lập và thư mục ảnh đính kèm.</p>
          </div>
        </li>
      </ul>

      <div style="margin-top:40px">
        <a class="row" style="gap:6px;color:var(--wa-primary);font-size:18px;font-weight:600" href="<?= wa_e(wa_url('login.php')) ?>">
          <span>Trải nghiệm không gian đọc mẫu</span>
          <span class="ms ms-16">arrow_forward</span>
        </a>
      </div>
    </div>

    <div class="showcase__art">
      <div class="card" style="overflow:hidden;box-shadow:var(--wa-shadow-3)">
        <img src="<?= wa_e(wa_asset('img/reader-diagram.svg')) ?>" alt="Minh hoạ kiến trúc lưu trữ nội dung Web tĩnh" style="width:100%;display:block">
        <div class="row row--between" style="gap:12px;padding:16px;background:rgba(255,255,255,.95)">
          <div class="row" style="gap:12px">
            <span class="ms ms-24" style="color:var(--wa-primary)">auto_stories</span>
            <div>
              <div class="t-label-md" style="color:var(--wa-primary)">Chế độ đọc báo chí: Giấy ngà tự nhiên</div>
              <div class="t-label-sm t-muted">Tỷ lệ tương phản 12:1 • Không chói mắt</div>
            </div>
          </div>
          <span class="chip">Bản quyền mở</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== CTA ===================== -->
<section class="page page--plain">
  <div class="cta-banner">
    <div class="cta-banner__inner">
      <span class="t-label-md t-upper" style="color:var(--wa-primary-fixed)">Khởi tạo kho tàng số</span>
      <h2 style="margin-top:4px">Xây dựng thư viện tri thức trường tồn cùng thời gian.</h2>
      <p>
        Bắt đầu lưu trữ những bài viết giá trị nhất của bạn ngay bây giờ. Hoàn toàn bảo mật, độc lập
        và luôn sẵn sàng mỗi khi bạn cần tra cứu.
      </p>
      <div class="cta-banner__actions">
        <a class="btn btn--lg btn--light" href="<?= wa_e(wa_url('register.php')) ?>">
          <span>Bắt đầu lưu bài viết đầu tiên</span>
          <span class="ms ms-16">arrow_forward</span>
        </a>
        <a class="btn btn--lg" style="background:var(--wa-primary);color:var(--wa-on-primary)" href="<?= wa_e(wa_url('login.php')) ?>">
          <span>Xem thư viện mẫu</span>
        </a>
      </div>
    </div>
  </div>

  <div class="landing-foot__note">
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">memory</span>
      Hệ thống hỗ trợ lưu trữ cục bộ HTML &amp; hình ảnh an toàn • Tối ưu cho PHP &amp; MySQL
    </span>
  </div>
</section>

<?php require __DIR__ . '/../templates/footer_guest.php'; ?>
