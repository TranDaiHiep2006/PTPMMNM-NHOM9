<?php
/**
 * bulk_save.php — LƯU HÀNG LOẠT URL (V3 / FEATURE 1).
 *
 * Nhập 10–50 URL (dán danh sách hoặc nạp file TXT/CSV), chọn thư viện đích và
 * nhãn chung, rồi lưu theo TỪNG LÔ nhỏ (mỗi lô = một request) để không tạo
 * request quá tải.
 *
 * Mỗi URL đi qua ĐÚNG đường lưu dùng chung `wa_store_page_capture()` — không
 * viết lại bộ tải trang. Chính sách URL công khai được kiểm tra TRƯỚC mọi request ra ngoài.
 *
 * Bảo mật: CSRF, kiểm tra quyền ghi vào thư viện ở tầng server.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user      = current_user();
$userId    = (int) ($user['id'] ?? 0);
$libraries = wa_writable_libraries();

/** @var array{type:string,text:string}|null */
$notice = null;

/** Dữ liệu form để hiển thị lại sau khi POST. */
$form = ['urls' => '', 'library' => '', 'tags' => ''];

/**
 * Token của LẦN chạy hiện tại — để biết đâu là lô đầu tiên của một lần bấm
 * "Bắt đầu" mới (reset session) và đâu là lần lặp lại cùng một lần chạy.
 *
 * Token chỉ được SINH khi tải trang (GET). Khi POST, token phải giữ NGUYÊN để
 * các lô sau nhận ra đây là cùng một lần chạy.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $bulkToken = bin2hex(random_bytes(8));
    $_SESSION['wa_bulk_token'] = $bulkToken;
} else {
    $bulkToken = (string) ($_SESSION['wa_bulk_token'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['urls']    = trim((string) ($_POST['urls'] ?? ''));
    $form['library'] = trim((string) ($_POST['library_id'] ?? ''));
    $form['tags']    = trim((string) ($_POST['tags'] ?? ''));

    $stage    = (string) ($_POST['stage'] ?? 'save');
    $postTok  = (string) ($_POST['bulk_token'] ?? '');
    $sessTok  = (string) ($_SESSION['wa_bulk_token'] ?? '');

    // Token lệch -> đây là một lần chạy MỚI -> reset toàn bộ trạng thái lô.
    $isNewRun = ($postTok === '' || $sessTok === '' || !hash_equals($sessTok, $postTok));

    if ($isNewRun) {
        unset(
            $_SESSION['wa_bulk_urls'],
            $_SESSION['wa_bulk_meta'],
            $_SESSION['wa_bulk_done'],
            $_SESSION['wa_bulk_dups']
        );

        wa_bulk_prefetch_reset();

        // NHẬN LẠI token của lần chạy mới, để các lô sau của CÙNG lần chạy đó
        // (check -> quyet dinh -> save) khớp và KHONG bị coi là lần chạy mới.
        $_SESSION['wa_bulk_token'] = $postTok !== ''
            ? $postTok
            : bin2hex(random_bytes(8));

        $bulkToken = (string) $_SESSION['wa_bulk_token'];
    }

    $libraryId = (int) $form['library'];
    $tagNames  = wa_parse_tag_input($form['tags']);

    if ($libraryId <= 0) {
        $notice = ['type' => 'error', 'text' => 'Vui lòng chọn thư viện đích.'];
    } elseif (!wa_can_write_library($libraryId)) {
        $notice = ['type' => 'error', 'text' => 'Bạn không có quyền lưu trang vào thư viện này.'];
    } elseif (!wa_csrf_check()) {
        $notice = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'];
        } else {
            $items = wa_bulk_parse_input($form['urls']);

            if ($items === []) {
                $notice = ['type' => 'error', 'text' => 'Chưa có URL nào để lưu.'];
            } elseif (count($items) > WA_BULK_MAX_URLS) {
                $notice = [
                    'type' => 'error',
                    'text' => 'Chỉ nhập tối đa ' . WA_BULK_MAX_URLS . ' URL mỗi lần (bạn đã nhập ' . count($items) . ').',
                ];
            } else {
                $filtered = wa_bulk_filter_urls($items);

                if ($filtered['valid'] === []) {
                    $notice = [
                        'type' => 'error',
                        'text' => 'Không còn URL hợp lệ nào để lưu ('
                            . count($filtered['duplicates']) . ' trùng, '
                            . count($filtered['invalid']) . ' không hợp lệ, '
                            . count($filtered['blocked']) . ' bị chặn).',
                    ];
                } else {
                    // Lô đầu tiên: lưu danh sách URL đã lọc vào phiên, các lô
                    // sau đọc lại từ phiên (client chỉ gửi số thứ tự lô).
                    $batchNo = (int) ($_POST['batch'] ?? $_GET['batch'] ?? 1);

                    if ($isNewRun || !isset($_SESSION['wa_bulk_urls'])) {
                        $_SESSION['wa_bulk_urls'] = $filtered['valid'];
                        $_SESSION['wa_bulk_meta'] = [
                            'library' => $libraryId,
                            'tags'    => $tagNames,
                            'total'   => count($filtered['valid']),
                            'batches' => count(array_chunk($filtered['valid'], WA_BULK_BATCH_SIZE)),
                            'stats'   => [
                                'duplicates' => count($filtered['duplicates']),
                                'invalid'    => count($filtered['invalid']),
                                'blocked'    => count($filtered['blocked']),
                            ],
                            'dup_total' => 0,
                            'checked'   => false,
                        ];
                        $batchNo = 1;
                    }

                    $meta  = $_SESSION['wa_bulk_meta'];
                    $batch = array_chunk($_SESSION['wa_bulk_urls'], WA_BULK_BATCH_SIZE);

                    $current = $batch[$batchNo - 1] ?? [];

                    /* -------------------------------------------------------------
                     * BƯỚC A — ĐỐI CHIẾU TRÙNG LẶP (chỉ báo cáo, CHƯA lưu).
                     * Dùng lại bộ phát hiện của F12. Mỗi URL chỉ tải MỘT LẦN:
                     * kết quả tải được giữ trong phiên để bước lưu dùng lại.
                     * ------------------------------------------------------------- */
                    if ($stage === 'check') {
                        $verdicts = wa_bulk_check_duplicates(
                            $current,
                            (int) $meta['library'],
                            wa_actor()
                        );

                        if (!isset($_SESSION['wa_bulk_dups']) || !is_array($_SESSION['wa_bulk_dups'])) {
                            $_SESSION['wa_bulk_dups'] = [];
                        }

                        $dupCount = 0;

                        foreach ($verdicts as $verdict) {
                            $_SESSION['wa_bulk_dups'][(string) $verdict['url']] = $verdict;

                            if ((bool) $verdict['dup']) {
                                $dupCount++;
                            }
                        }

                        $_SESSION['wa_bulk_meta']['dup_total'] =
                            (int) ($_SESSION['wa_bulk_meta']['dup_total'] ?? 0) + $dupCount;
                        $_SESSION['wa_bulk_meta']['checked'] = true;

                        header('Content-Type: application/json; charset=utf-8');

                        echo json_encode([
                            'ok'      => true,
                            'stage'   => 'check',
                            'batch'   => $batchNo,
                            'batches' => (int) $meta['batches'],
                            'dups'    => $verdicts,
                            'dup_count' => $dupCount,
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }

                    /* -------------------------------------------------------------
                     * BƯỚC B — LƯU (sau khi người dùng đã chọn cách xử lý trùng).
                     * ------------------------------------------------------------- */
                    $dupPolicy = (string) ($_POST['dup_policy'] ?? 'save_all');
                    if (!in_array($dupPolicy, ['skip', 'save_all'], true)) {
                        $dupPolicy = 'save_all';
                    }

                    $dupMap = isset($_SESSION['wa_bulk_dups']) && is_array($_SESSION['wa_bulk_dups'])
                        ? $_SESSION['wa_bulk_dups']
                        : [];

                    $done = wa_bulk_store(
                        $current,
                        (int) $meta['library'],
                        $meta['tags'],
                        $userId,
                        [
                            'dup_policy' => $dupPolicy,
                            'dup_map'    => $dupMap,
                        ]
                    );

                    if (!isset($_SESSION['wa_bulk_done'])) {
                        $_SESSION['wa_bulk_done'] = [];
                    }

                    $_SESSION['wa_bulk_done'] = array_merge($_SESSION['wa_bulk_done'], $done['results']);

                    $totalOk = 0;
                    $totalFail = 0;

                    foreach ($_SESSION['wa_bulk_done'] as $r) {
                        if ($r['ok']) {
                            $totalOk++;
                        } else {
                            $totalFail++;
                        }
                    }

                    if ($batchNo >= (int) $meta['batches']) {
                        $stats = (array) $meta['stats'];

                        unset(
                            $_SESSION['wa_bulk_urls'],
                            $_SESSION['wa_bulk_meta'],
                            $_SESSION['wa_bulk_done'],
                            $_SESSION['wa_bulk_dups']
                        );

                        wa_bulk_prefetch_reset();

                        $skipped = (int) $done['skipped'];

                        $_SESSION['wa_flash'] = [
                            'type' => ($totalFail === 0 && $skipped === 0) ? 'success' : 'error',
                            'text' => 'Lưu hàng loạt xong: ' . $totalOk . ' thành công, ' . $totalFail . ' thất bại'
                                . ($skipped > 0 ? ', ' . $skipped . ' bài trùng đã bỏ qua' : '')
                                . ' (' . $stats['duplicates'] . ' trùng trong danh sách, ' . $stats['invalid']
                                . ' không hợp lệ, ' . $stats['blocked'] . ' bị chặn).',
                        ];

                        header('Location: ' . wa_url('bulk_save.php'));
                        exit;
                    }

                    // Trả JSON cho JavaScript tiếp tục lô kế tiếp.
                    header('Content-Type: application/json; charset=utf-8');

                    echo json_encode([
                        'ok'      => true,
                        'stage'   => 'save',
                        'batch'   => $batchNo,
                        'batches' => (int) $meta['batches'],
                        'done'    => $done,
                        'stats'   => $meta['stats'],
                        'total_ok' => $totalOk,
                        'total_fail' => $totalFail,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
}

/* --- Flash từ lần chạy trước --- */
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Lưu hàng loạt URL';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Bảo tồn nội dung</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Tiện ích cơ bản</span>
      </div>
      <h1 class="ribbon__title">Lưu hàng loạt URL</h1>
      <p class="ribbon__sub">
        Dán cả danh sách link — WebArchive loại bỏ URL trùng, kiểm tra an toàn từng
        URL (chặn mạng nội bộ, cloud metadata, đích không xác minh được) và lưu từng
        lô nhỏ để không tạo request quá tải.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('save.php')) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Lưu một trang</span>
      </a>
    </div>
  </div>
</div>

<div class="capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">library_add</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Danh sách URL</h2>
        <p class="t-body-sm t-muted">
          Mỗi dòng một URL. File CSV/TXT cũng được — cột đầu tiên được hiểu là URL.
          Dòng bắt đầu bằng <code>#</code> là ghi chú và bị bỏ qua.
        </p>
      </div>
    </div>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">speed</span>
      <?= WA_BULK_BATCH_SIZE ?> URL / lô
    </span>
  </div>

  <form id="wa-bulk-form" class="capture__form" method="post" action="<?= wa_e(wa_url('bulk_save.php')) ?>">
    <?= wa_csrf_field() ?>
    <input type="hidden" name="bulk_token" id="wa-bulk-token" value="<?= wa_e($bulkToken) ?>">

    <div class="capture__input">
      <span class="ms ms-18">folder_special</span>
      <select class="input input--icon" name="library_id" required aria-label="Thư viện để lưu trang">
        <option value="">— Chọn thư viện —</option>
        <?php foreach ($libraries as $library): ?>
          <option value="<?= (int) $library['id'] ?>"
            <?= (string) $form['library'] === (string) $library['id'] ? 'selected' : '' ?>>
            <?= wa_e($library['name']) ?>
            (<?= wa_e($library['role'] === 'owner' ? 'chủ sở hữu' : 'biên tập viên') ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="capture__input">
      <span class="ms ms-18">sell</span>
      <input class="input input--icon" type="text" name="tags"
             value="<?= wa_e($form['tags']) ?>"
             placeholder="nhãn chung: nhãn 1, nhãn 2"
             aria-label="Nhãn phân loại áp dụng cho mọi URL">
    </div>

    <div class="capture__input" style="grid-column:1/-1;display:block">
      <label class="t-label-sm" for="wa-bulk-urls" style="display:block;margin-bottom:6px">
        Danh sách URL (tối đa <?= WA_BULK_MAX_URLS ?>)
      </label>
      <textarea class="input" id="wa-bulk-urls" name="urls" rows="10"
                placeholder="https://example.com/bai-1&#10;https://example.com/bai-2&#10;https://example.com/bai-3"
                style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px"><?= wa_e($form['urls']) ?></textarea>
    </div>

    <div class="capture__input">
      <span class="ms ms-18">upload_file</span>
      <input class="input input--icon" type="file" id="wa-bulk-file"
             accept=".txt,.csv,text/plain,text/csv"
             aria-label="Nạp danh sách URL từ file TXT hoặc CSV">
    </div>

    <button class="btn btn--dark" type="submit" id="wa-bulk-submit">
      <span class="ms ms-18">playlist_add</span>
      <span>Bắt đầu lưu</span>
    </button>
  </form>

  <div class="capture__hint">
    <p>
      Mỗi URL vẫn đi qua đúng engine tải trang thật và đường lưu dùng chung
      <code>wa_store_page_capture()</code>. Mọi URL công khai đều có thể lưu —
      không cần quản trị viên duyệt tên miền. URL không vượt qua chốt an toàn
      (mạng nội bộ, cloud metadata, đích không xác minh được) sẽ bị bỏ qua trước
      khi gửi bất kỳ request nào.
    </p>
  </div>
</div>

<!-- ===================== TIẾN ĐỘ + KẾT QUẢ ===================== -->
<div id="wa-bulk-result" class="pagehead" style="margin-top:24px;display:none">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Tiến độ</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Kết quả lưu hàng loạt</h2>
    <p class="pagehead__desc" id="wa-bulk-summary">Đang xử lý…</p>
    <div style="height:8px;background:#E9EEF4;border-radius:999px;overflow:hidden;margin-top:12px">
      <div id="wa-bulk-bar" style="height:100%;width:0%;background:var(--wa-action);transition:width .25s"></div>
    </div>
  </div>
</div>

<div id="wa-bulk-list" style="margin-top:16px"></div>

<!-- ============ QUYẾT ĐỊNH XỬ LÝ BÀI TRÙNG (hiện sau bước đối chiếu) ============ -->
<div id="wa-bulk-decision" class="capture" style="margin-top:24px;display:none">
  <div class="capture__head">
    <div class="capture__title">
      <div class="capture__title-icon"><span class="ms ms-22">content_copy</span></div>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Bài trùng đã tìm thấy</h2>
        <p class="t-body-sm t-muted" id="wa-bulk-decision-text"></p>
      </div>
    </div>
  </div>

  <div id="wa-bulk-dups" style="margin-bottom:16px"></div>

  <div class="capture__form" style="display:flex;gap:12px;flex-wrap:wrap">
    <button class="btn btn--dark" type="button" data-dup-policy="skip">
      <span class="ms ms-18">filter_alt</span>
      <span>Bỏ qua bài trùng, chỉ lưu bài mới</span>
    </button>
    <button class="btn btn--outline" type="button" data-dup-policy="save_all">
      <span class="ms ms-18">save</span>
      <span>Lưu tất cả (kể cả bài trùng)</span>
    </button>
  </div>

  <div class="capture__hint">
    <p>
      Không có gì bị xoá hay gộp tự động. Bài trùng chỉ được <strong>bỏ qua</strong>
      hoặc <strong>lưu thêm phiên bản</strong> — tuỳ bạn chọn ở trên.
    </p>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="state <?= (string) ($flash['type'] ?? '') === 'success' ? 'state--success' : 'state--error' ?>" style="margin-top:16px">
    <div class="state__body">
      <span class="state__icon">
        <span class="ms ms-18 ms-fill">
          <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
        </span>
      </span>
      <div>
        <span class="state__title"><?= (string) ($flash['type'] ?? '') === 'success' ? 'Thành công:' : 'Thông báo:' ?></span>
        <p class="state__text"><?= wa_e((string) ($flash['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($notice !== null): ?>
  <div class="state state--error" style="margin-top:16px">
    <div class="state__body">
      <span class="state__icon"><span class="ms ms-18 ms-fill">error_outline</span></span>
      <div>
        <span class="state__title">Không thể lưu hàng loạt</span>
        <p class="state__text"><?= wa_e((string) ($notice['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  // ---- Nạp danh sách URL từ file TXT/CSV ----
  (function () {
    var file = document.getElementById('wa-bulk-file');
    var area = document.getElementById('wa-bulk-urls');
    if (!file || !area) { return; }
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) { return; }
      var reader = new FileReader();
      reader.onload = function () {
        area.value = String(reader.result || '');
      };
      reader.readAsText(f);
    });
  })();

  // ---- Chạy từng lô: server trả JSON { batch, batches, done } ----
  (function () {
    var form = document.getElementById('wa-bulk-form');
    var box = document.getElementById('wa-bulk-result');
    var bar = document.getElementById('wa-bulk-bar');
    var sum = document.getElementById('wa-bulk-summary');
    var list = document.getElementById('wa-bulk-list');
    var btn = document.getElementById('wa-bulk-submit');
    var decision = document.getElementById('wa-bulk-decision');
    var decisionText = document.getElementById('wa-bulk-decision-text');
    var dupList = document.getElementById('wa-bulk-dups');
    if (!form || !box) { return; }

    var state = { fd: null, dups: [], dupCount: 0 };

    // Form gửi bằng fetch để không rời trang -> tiến độ hiện liên tục.
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      box.style.display = '';
      list.innerHTML = '';
      bar.style.width = '0%';

      state.dups = [];
      state.dupCount = 0;

      if (decision) { decision.style.display = 'none'; }
      if (dupList) { dupList.innerHTML = ''; }

      var fd = new FormData(form);
      fd.set('batch', '1');
      fd.set('stage', 'check');

      state.fd = fd;

      runCheck(fd, 1);
    });

    /* ---------- BƯỚC A: đối chiếu trùng lặp (chưa lưu) ---------- */
    function runCheck(fd, batchNo) {
      sum.textContent = 'Đang đối chiếu trùng lặp — lô ' + batchNo + '…';

      fd.set('batch', String(batchNo));
      fd.set('stage', 'check');

      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) {
          var ct = res.headers.get('content-type') || '';
          if (ct.indexOf('application/json') === -1) {
            window.location.reload();
            return null;
          }
          return res.json();
        })
        .then(function (data) {
          if (!data || data.ok !== true) {
            sum.textContent = 'Có lỗi xảy ra: ' + ((data && data.error) || 'không rõ');
            return;
          }

          (data.dups || []).forEach(function (d) {
            if (d.dup) {
              state.dups.push(d);
              state.dupCount++;
            }
          });

          var pct = Math.round((data.batch / data.batches) * 100);
          bar.style.width = pct + '%';

          sum.textContent = 'Đã đối chiếu lô ' + data.batch + '/' + data.batches
            + ' — tìm thấy ' + state.dupCount + ' bài trùng.';

          if (data.batch < data.batches) {
            runCheck(fd, data.batch + 1);
          } else {
            bar.style.width = '100%';
            showDecision();
          }
        })
        .catch(function () {
          sum.textContent = 'Không gửi được yêu cầu. Vui lòng thử lại.';
        });
    }

    /* ---------- BƯỚC B: cho người dùng quyết định cách xử lý trùng ---------- */
    function showDecision() {
      if (decisionText) {
        decisionText.textContent = state.dupCount === 0
          ? 'Không tìm thấy bài trùng nào trong thư viện. Bạn có thể lưu toàn bộ danh sách.'
          : 'Tìm thấy ' + state.dupCount + ' bài trùng với nội dung đã có trong thư viện. Bạn muốn xử lý thế nào?';
      }

      if (dupList && state.dups.length) {
        var html = '<div class="state-list">';
        state.dups.forEach(function (d) {
          var first = (d.items && d.items[0]) ? d.items[0] : {};
          var title = first.title || first.source_url || '';
          var label = first.level_label || 'Trùng';
          html += '<div class="state-item">'
            + '<div class="state-item__head">'
            + '<span class="state-item__name">'
            + '<span class="ms ms-18">content_copy</span>'
            + waEscape(d.url)
            + '</span>'
            + '<span class="chip chip--plain">' + waEscape(label) + '</span>'
            + '</div>'
            + '<p class="t-body-sm t-muted" style="margin-top:8px">Trùng với: ' + waEscape(title)
            + (d.count > 1 ? ' (+' + (d.count - 1) + ' bài khác)' : '') + '</p>'
            + '</div>';
        });
        html += '</div>';
        dupList.innerHTML = html;
      }

      if (decision) { decision.style.display = ''; }
      sum.textContent += ' Sẵn sàng lưu — hãy chọn cách xử lý bài trùng bên dưới.';
    }

    document.addEventListener('click', function (ev) {
      var btnSave = ev.target.closest ? ev.target.closest('[data-dup-policy]') : null;
      if (!btnSave || !state.fd) { return; }
      ev.preventDefault();

      var policy = btnSave.getAttribute('data-dup-policy');

      if (decision) { decision.style.display = 'none'; }

      var fd = new FormData(state.fd);
      fd.set('stage', 'save');
      fd.set('dup_policy', policy);
      fd.set('batch', '1');

      state.fd = fd;
      runSave(fd, 1);
    });

    /* ---------- BƯỚC C: lưu ---------- */
    function runSave(fd, batchNo) {
      sum.textContent = 'Đang lưu — lô ' + batchNo + '…';

      fd.set('batch', String(batchNo));
      fd.set('stage', 'save');

      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) {
          var ct = res.headers.get('content-type') || '';
          if (ct.indexOf('application/json') === -1) {
            // Server đã xong toàn bộ và chuyển hướng -> tải lại trang.
            window.location.reload();
            return null;
          }
          return res.json();
        })
        .then(function (data) {
          if (!data || data.ok !== true) {
            sum.textContent = 'Có lỗi xảy ra: ' + ((data && data.error) || 'không rõ');
            return;
          }

          renderResults(data.done.results);

          var pct = Math.round((data.batch / data.batches) * 100);
          bar.style.width = pct + '%';

          var st = data.stats || {};
          sum.textContent = 'Lô ' + data.batch + '/' + data.batches
            + ' — đã lưu ' + (data.total_ok || 0) + ' thành công, '
            + (data.total_fail || 0) + ' thất bại'
            + (st.duplicates ? ', ' + st.duplicates + ' trùng trong danh sách' : '')
            + (st.invalid ? ', ' + st.invalid + ' không hợp lệ' : '')
            + (st.blocked ? ', ' + st.blocked + ' bị chặn' : '')
            + '.';

          if (data.batch < data.batches) {
            runSave(fd, data.batch + 1);
          } else {
            bar.style.width = '100%';
            sum.textContent += ' Đã xong.';
          }
        })
        .catch(function () {
          sum.textContent = 'Không gửi được yêu cầu. Vui lòng thử lại.';
        });
    }

    function renderResults(results) {
      var html = '<div class="state-list">';
      results.forEach(function (r) {
        html += '<div class="state-item">'
          + '<div class="state-item__head">'
          + '<span class="state-item__name">'
          + '<span class="ms ms-18">' + (r.ok ? 'check_circle' : 'error_outline') + '</span>'
          + waEscape(r.url)
          + '</span>'
          + '<span class="chip chip--' + (r.ok ? 'teal' : 'error') + '">' + (r.ok ? 'thành công' : 'lỗi') + '</span>'
          + '</div>'
          + '<p class="t-body-sm t-muted" style="margin-top:8px">' + waEscape(r.message)
          + (r.snapshot_id > 0 ? ' · <a href="reader.php?id=' + r.snapshot_id + '">mở bản lưu</a>' : '')
          + '</p>'
          + '</div>';
      });
      html += '</div>';
      list.insertAdjacentHTML('beforeend', html);
    }

    function waEscape(text) {
      var d = document.createElement('div');
      d.textContent = text == null ? '' : String(text);
      return d.innerHTML;
    }
  })();
</script>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
