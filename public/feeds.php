<?php
/**
 * feeds.php — THEO DÕI RSS/ATOM (V3 / FEATURE 9).
 *
 * Luồng:
 *   1. Dán URL feed -> xem trước tên feed, tiêu đề bài, ngày xuất bản, URL.
 *   2. Chọn một hoặc nhiều bài -> lưu vào thư viện (dùng lại
 *      wa_store_page_capture()).
 *   3. Nếu chưa có thư viện -> tạo mới bằng đúng cơ chế wa_library_create().
 *
 * KHÔNG có cron / daemon: feed CHỈ được tải khi người dùng bấm nút.
 * SSRF, kiểm tra an toàn, timeout và giới hạn dung lượng do wa_capture_http_get()
 * đảm nhiệm.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user      = current_user();
$userId    = (int) ($user['id'] ?? 0);
$libraries = wa_writable_libraries();

/** @var array{type:string,text:string}|null */
$notice = null;

/** Kết quả lưu từng bài (để hiển thị rõ ràng từng bài). */
$saveResults = [];

/** Feed đang xem trước. */
$preview = null;

/** Thư viện đích (mặc định: thư viện đầu tiên có quyền ghi). */
$targetLibrary = (int) ($_GET['library_id'] ?? $_POST['library_id'] ?? ($libraries[0]['id'] ?? 0));

$feedUrl = trim((string) ($_GET['feed'] ?? $_POST['feed_url'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!wa_csrf_check()) {
        $notice = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'];
    } elseif ($action === 'preview') {
        // ---- XEM TRƯỚC FEED ----
        $feedUrl = trim((string) ($_POST['feed_url'] ?? ''));

        $preview = wa_feed_fetch($feedUrl);

        if ($preview['ok'] !== true) {
            $notice = ['type' => 'error', 'text' => (string) $preview['message']];
            $preview = null;
        } else {
            // Lưu lịch sử item để chống trùng ở lần xem sau.
            $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);

            if ($subscriptionId > 0) {
                wa_feed_record_items($subscriptionId, (array) $preview['items']);
                wa_feed_mark_checked($subscriptionId, (string) $preview['title']);
            }

            $_SESSION['wa_feed_preview'] = [
                'url'   => $feedUrl,
                'title' => (string) $preview['title'],
                'items' => $preview['items'],
                'sub'   => $subscriptionId,
            ];
        }
    } elseif ($action === 'save') {
        // ---- LƯU CÁC BÀI ĐÃ CHỌN ----
        $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
        $items           = isset($_SESSION['wa_feed_preview']['items']) && is_array($_SESSION['wa_feed_preview']['items'])
            ? $_SESSION['wa_feed_preview']['items']
            : [];

        $selected = array_map('intval', (array) ($_POST['items'] ?? []));
        $libraryId = (int) ($_POST['library_id'] ?? 0);

        if ($libraryId <= 0) {
            $notice = ['type' => 'error', 'text' => 'Vui lòng chọn thư viện đích.'];
        } elseif (!wa_can_write_library($libraryId)) {
            $notice = ['type' => 'error', 'text' => 'Bạn không có quyền lưu trang vào thư viện này.'];
        } elseif ($selected === []) {
            $notice = ['type' => 'error', 'text' => 'Chưa chọn bài nào để lưu.'];
        } else {
            $tags  = wa_parse_tag_input((string) ($_POST['tags'] ?? ''));
            $items = array_values($items);

            foreach ($selected as $index) {
                if (!isset($items[$index])) {
                    continue;
                }

                $item = $items[$index];
                $url  = (string) $item['link'];

                // Chống trùng: URL đã có trong thư viện -> tạo phiên bản mới
                // (đúng hành vi của wa_store_page_capture), không tạo bản trùng.
                $result = wa_store_page_capture($libraryId, $url, $tags, $userId);

                $saveResults[] = [
                    'title'   => (string) $item['title'],
                    'url'     => $url,
                    'ok'      => $result['ok'] === true,
                    'message' => $result['ok'] === true
                        ? 'Đã lưu phiên bản ' . (int) $result['version']
                        : (string) ($result['error_message'] ?? 'Không lưu được.'),
                    'snapshot_id' => (int) ($result['snapshot_id'] ?? 0),
                ];

                if ($result['ok'] === true && $subscriptionId > 0) {
                    wa_feed_mark_saved($subscriptionId, $url, (int) $result['page_id']);
                }
            }

            $okCount  = count(array_filter($saveResults, static fn (array $r): bool => $r['ok'] === true));
            $failCount = count($saveResults) - $okCount;

            $notice = [
                'type' => $failCount === 0 ? 'success' : 'error',
                'text' => 'Đã xử lý ' . count($saveResults) . ' bài: ' . $okCount
                    . ' thành công, ' . $failCount . ' thất bại.',
            ];
        }
    } elseif ($action === 'subscribe') {
        // ---- THÊM NGUỒN FEED ----
        $feedUrl   = trim((string) ($_POST['feed_url'] ?? ''));
        $libraryId = (int) ($_POST['library_id'] ?? 0);

        if ($libraryId <= 0) {
            $notice = ['type' => 'error', 'text' => 'Vui lòng chọn thư viện đích.'];
        } else {
            $result = wa_feed_subscribe(
                $userId,
                $libraryId,
                $feedUrl,
                (string) ($_POST['tags'] ?? '')
            );

            if ($result['ok'] === true) {
                $_SESSION['wa_flash'] = ['type' => 'success', 'text' => (string) $result['message']];
                header('Location: ' . wa_url('feeds.php'));
                exit;
            }

            $notice = ['type' => 'error', 'text' => (string) $result['message']];
        }
    } elseif ($action === 'unsubscribe') {
        // ---- XOÁ NGUỒN FEED ----
        $ok = wa_feed_unsubscribe((int) ($_POST['feed_id'] ?? 0), $userId);

        $_SESSION['wa_flash'] = [
            'type' => $ok ? 'success' : 'error',
            'text' => $ok ? 'Đã xoá nguồn feed.' : 'Không xoá được nguồn feed này.',
        ];

        header('Location: ' . wa_url('feeds.php'));
        exit;
    } elseif ($action === 'create_library') {
        // ---- TẠO THƯ VIỆN MỚI (cơ chế dùng chung) ----
        $result = wa_library_create(
            trim((string) ($_POST['name'] ?? '')),
            trim((string) ($_POST['description'] ?? '')),
            (string) ($_POST['visibility'] ?? 'private'),
            'a',
            'newspaper',
            $userId
        );

        if ($result['ok'] === true) {
            $_SESSION['wa_flash'] = ['type' => 'success', 'text' => (string) $result['message']];
            header('Location: ' . wa_url('feeds.php?library_id=' . (int) $result['library_id']));
            exit;
        }

        $notice = ['type' => 'error', 'text' => (string) $result['message']];
    }
}

/* --- Dữ liệu xem trước (khôi phục từ phiên nếu có) --- */
if ($preview === null && isset($_SESSION['wa_feed_preview']) && is_array($_SESSION['wa_feed_preview'])) {
    $cached = $_SESSION['wa_feed_preview'];

    if ($feedUrl === '' || (string) ($cached['url'] ?? '') === $feedUrl) {
        $feedUrl = (string) ($cached['url'] ?? '');
        $preview = [
            'ok'          => true,
            'title'       => (string) ($cached['title'] ?? 'Feed'),
            'link'        => '',
            'description' => '',
            'items'       => (array) ($cached['items'] ?? []),
        ];
    }
}

/* --- Mở trực tiếp một nguồn đã theo dõi: ?feed=...&library_id=... --- */
$previewSubscription = 0;

if ($preview === null && $feedUrl !== '' && ($_GET['feed'] ?? '') !== '') {
    $preview = wa_feed_fetch($feedUrl);

    if ($preview['ok'] !== true) {
        $notice = ['type' => 'error', 'text' => (string) $preview['message']];
        $preview = null;
    } else {
        foreach (wa_feed_list($userId) as $sub) {
            if ((string) $sub['feed_url'] === $feedUrl) {
                $previewSubscription = (int) $sub['id'];
                wa_feed_record_items($previewSubscription, (array) $preview['items']);
                wa_feed_mark_checked($previewSubscription, (string) $preview['title']);
                break;
            }
        }

        $_SESSION['wa_feed_preview'] = [
            'url'   => $feedUrl,
            'title' => (string) $preview['title'],
            'items' => $preview['items'],
            'sub'   => $previewSubscription,
        ];
    }
}

$subscriptions = wa_feed_list($userId);

/* --- Flash --- */
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Nguồn RSS / Atom';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:660px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Thu thập</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">RSS · Atom</span>
      </div>
      <h1 class="ribbon__title">Nguồn RSS / Atom</h1>
      <p class="ribbon__sub">
        Dán địa chỉ feed để xem trước các bài mới, chọn bài cần và lưu vào thư viện.
        Feed chỉ được tải khi bạn bấm — không có cron, không có tiến trình nền.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('save.php')) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Về trang chính</span>
      </a>
    </div>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="state <?= (string) ($flash['type'] ?? '') === 'success' ? 'state--success' : 'state--error' ?>" style="margin-bottom:16px">
    <div class="state__body">
      <span class="state__icon">
        <span class="ms ms-18 ms-fill"><?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?></span>
      </span>
      <div>
        <span class="state__title"><?= (string) ($flash['type'] ?? '') === 'success' ? 'Thành công:' : 'Thông báo:' ?></span>
        <p class="state__text"><?= wa_e((string) ($flash['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($notice !== null): ?>
  <div class="state state--error" style="margin-bottom:16px">
    <div class="state__body">
      <span class="state__icon"><span class="ms ms-18 ms-fill">error_outline</span></span>
      <div>
        <span class="state__title">Không thực hiện được</span>
        <p class="state__text"><?= wa_e((string) ($notice['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== THÊM / XEM TRƯỚC FEED ===================== -->
<div class="capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">rss_feed</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Thêm nguồn feed</h2>
        <p class="t-body-sm t-muted">
          Dán URL RSS hoặc Atom. Mọi URL công khai đều dùng được — máy chủ tự kiểm tra
          an toàn (chặn mạng nội bộ, cloud metadata, đích không xác minh được) trước khi tải.
        </p>
      </div>
    </div>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('feeds.php')) ?>">
    <?= wa_csrf_field() ?>
    <input type="hidden" name="action" value="preview">

    <div class="capture__input" style="grid-column:1/-1">
      <label class="t-label-sm" for="wa-feed-url" style="display:block;margin-bottom:6px">Địa chỉ feed</label>
      <input class="input" id="wa-feed-url" name="feed_url" type="url" required
             value="<?= wa_e($feedUrl) ?>"
             placeholder="https://example.com/feed.xml"
             style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px">
    </div>

    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">search</span>
      <span>Xem trước</span>
    </button>
  </form>
</div>

<!-- ===================== XEM TRƯỚC + CHỌN BÀI ===================== -->
<?php if ($preview !== null): ?>
  <div class="pagehead" style="margin-top:24px">
    <div>
      <div class="pagehead__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Xem trước</span>
      </div>
      <h2 class="t-headline-md" style="color:var(--wa-primary)"><?= wa_e((string) $preview['title']) ?></h2>
      <p class="pagehead__desc">
        <?= count((array) $preview['items']) ?> bài trong feed
        <?php if ((string) ($preview['link'] ?? '') !== ''): ?>
          · <a href="<?= wa_e((string) $preview['link']) ?>" target="_blank" rel="noopener noreferrer nofollow">mở trang feed</a>
        <?php endif; ?>
      </p>
    </div>

    <!-- Nút theo dõi nguồn này (dùng chung wa_feed_subscribe). -->
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <form method="post" action="<?= wa_e(wa_url('feeds.php')) ?>" style="margin:0">
        <?= wa_csrf_field() ?>
        <input type="hidden" name="action" value="subscribe">
        <input type="hidden" name="feed_url" value="<?= wa_e($feedUrl) ?>">
        <input type="hidden" name="library_id" value="<?= (int) $targetLibrary ?>">
        <button class="btn btn--outline btn--sm" type="submit"
                <?= $targetLibrary <= 0 ? 'disabled' : '' ?>>
          <span class="ms ms-16">bookmark_added</span> Theo dõi nguồn này
        </button>
      </form>
      <?php if ($targetLibrary <= 0): ?>
        <span class="t-label-sm t-muted">Cần có thư viện đích để theo dõi — tạo ở khối bên dưới.</span>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" action="<?= wa_e(wa_url('feeds.php')) ?>">
    <?= wa_csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="subscription_id" value="<?= (int) ($_SESSION['wa_feed_preview']['sub'] ?? 0) ?>">

    <div class="capture">
      <div class="capture__head">
        <div class="capture__title">
          <span class="capture__title-icon"><span class="ms ms-22">library_add</span></span>
          <div>
            <h2 class="t-headline-sm" style="color:var(--wa-primary)">Lưu bài đã chọn</h2>
            <p class="t-body-sm t-muted">
              Tích vào các bài cần lưu, chọn thư viện đích rồi bấm “Lưu đã chọn”.
            </p>
          </div>
        </div>
      </div>

      <div class="capture__form">
        <div class="capture__input">
          <span class="ms ms-18">folder_special</span>
          <select class="input input--icon" name="library_id" required aria-label="Thư viện đích">
            <option value="">— Chọn thư viện —</option>
            <?php foreach ($libraries as $library): ?>
              <option value="<?= (int) $library['id'] ?>"
                <?= $targetLibrary === (int) $library['id'] ? 'selected' : '' ?>>
                <?= wa_e((string) $library['name']) ?>
                (<?= wa_e($library['role'] === 'owner' ? 'chủ sở hữu' : 'biên tập viên') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="capture__input">
          <span class="ms ms-18">sell</span>
          <input class="input input--icon" type="text" name="tags"
                 placeholder="nhãn: nhãn 1, nhãn 2" aria-label="Nhãn áp dụng">
        </div>

        <button class="btn btn--dark" type="submit" id="wa-feed-save">
          <span class="ms ms-18">download_done</span>
          <span>Lưu đã chọn</span>
        </button>
      </div>

      <?php if ($libraries === []): ?>
        <div class="state state--error" style="margin-top:12px">
          <div class="state__body">
            <span class="state__icon"><span class="ms ms-18 ms-fill">error_outline</span></span>
            <div>
              <span class="state__title">Bạn chưa có thư viện nào</span>
              <p class="state__text">
                Hãy tạo thư viện ở khối "Tạo thư viện mới" bên dưới trước khi lưu bài.
              </p>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="state-list" style="margin-top:16px">
      <?php foreach ((array) $preview['items'] as $i => $item): ?>
        <?php
        $itemUrl   = (string) ($item['link'] ?? '');
        $itemTitle = (string) ($item['title'] ?? '');
        $itemDate  = $item['published_at'] ?? null;
        ?>
        <div class="state-item">
          <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
            <input type="checkbox" name="items[]" value="<?= (int) $i ?>" style="margin-top:4px">
            <span style="flex:1;min-width:0">
              <span style="display:block;font-weight:600;font-size:14px"><?= wa_e($itemTitle !== '' ? $itemTitle : '(không có tiêu đề)') ?></span>
              <span class="t-label-sm t-muted" style="display:block;margin-top:4px;word-break:break-all;font-family:ui-monospace,Menlo,monospace"><?= wa_e($itemUrl) ?></span>
              <?php if (!empty($itemDate)): ?>
                <span class="t-label-sm t-muted" style="display:block;margin-top:4px">
                  <span class="ms ms-14">schedule</span> <?= wa_e(wa_datetime((string) $itemDate)) ?>
                </span>
              <?php endif; ?>
              <?php if (!empty($item['summary'])): ?>
                <span class="t-body-sm t-muted" style="display:block;margin-top:6px"><?= wa_e((string) $item['summary']) ?></span>
              <?php endif; ?>
            </span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </form>
<?php endif; ?>

<!-- ===================== KẾT QUẢ LƯU TỪNG BÀI ===================== -->
<?php if ($saveResults !== []): ?>
  <div class="pagehead" style="margin-top:24px">
    <div>
      <div class="pagehead__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Kết quả</span>
      </div>
      <h2 class="t-headline-md" style="color:var(--wa-primary)">Kết quả lưu từng bài</h2>
    </div>
  </div>

  <div class="state-list">
    <?php foreach ($saveResults as $r): ?>
      <div class="state-item">
        <div class="state-item__head">
          <span class="state-item__name">
            <span class="ms ms-18"><?= $r['ok'] ? 'check_circle' : 'error_outline' ?></span>
            <?= wa_e($r['title'] !== '' ? $r['title'] : $r['url']) ?>
          </span>
          <span class="chip chip--<?= $r['ok'] ? 'teal' : 'error' ?>">
            <?= $r['ok'] ? 'thành công' : 'thất bại' ?>
          </span>
        </div>
        <p class="t-body-sm t-muted" style="margin-top:8px">
          <?= wa_e($r['message']) ?>
          <?php if ($r['snapshot_id'] > 0): ?>
            · <a href="<?= wa_e(wa_url('reader.php?id=' . (int) $r['snapshot_id'])) ?>">mở bản lưu</a>
          <?php endif; ?>
        </p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ===================== TẠO THƯ VIỆN MỚI ===================== -->
<div class="pagehead" style="margin-top:28px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Thiếu thư viện?</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Tạo thư viện mới</h2>
    <p class="pagehead__desc">
      Dùng đúng cơ chế tạo thư viện của WebArchive — dữ liệu được ghi thật vào CSDL.
    </p>
  </div>
</div>

<div class="capture">
  <form class="capture__form" method="post" action="<?= wa_e(wa_url('feeds.php')) ?>">
    <?= wa_csrf_field() ?>
    <input type="hidden" name="action" value="create_library">

    <div class="capture__input">
      <span class="ms ms-18">folder_special</span>
      <input class="input input--icon" type="text" name="name" required maxlength="150"
             placeholder="Tên thư viện" aria-label="Tên thư viện">
    </div>

    <div class="capture__input">
      <span class="ms ms-18">notes</span>
      <input class="input input--icon" type="text" name="description" maxlength="500"
             placeholder="Mô tả (tuỳ chọn)" aria-label="Mô tả thư viện">
    </div>

    <div class="capture__input">
      <span class="ms ms-18">lock</span>
      <select class="input input--icon" name="visibility" aria-label="Mức riêng tư">
        <option value="private">Riêng tư</option>
        <option value="shared">Chia sẻ</option>
        <option value="public">Công khai</option>
      </select>
    </div>

    <button class="btn btn--outline" type="submit">
      <span class="ms ms-18">add</span>
      <span>Tạo thư viện</span>
    </button>
  </form>
</div>

<!-- ===================== DANH SÁCH NGUỒN ĐÃ THEO DÕI ===================== -->
<div class="pagehead" style="margin-top:28px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Đã theo dõi</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Nguồn feed của bạn (<?= count($subscriptions) ?>)</h2>
  </div>
</div>

<?php if ($subscriptions === []): ?>
  <div class="empty">
    <span class="ms ms-32">rss_feed</span>
    <h3>Chưa theo dõi nguồn nào</h3>
    <p>Xem trước một feed ở ô trên rồi bấm “Theo dõi nguồn này”.</p>
  </div>
<?php else: ?>
  <div class="state-list">
    <?php foreach ($subscriptions as $sub): ?>
      <div class="state-item">
        <div class="state-item__head">
          <span class="state-item__name">
            <span class="ms ms-18">rss_feed</span>
            <?= wa_e((string) $sub['title']) ?>
          </span>
          <span class="chip chip--plain"><?= (int) $sub['item_count'] ?> mục đã thấy</span>
        </div>

        <p class="t-body-sm t-muted" style="margin-top:8px;word-break:break-all">
          <?= wa_e((string) $sub['feed_url']) ?>
        </p>
        <p class="t-body-sm t-muted" style="margin-top:4px">
          Thư viện: <?= wa_e((string) $sub['library_name']) ?>
          · Kiểm tra lần cuối: <?= wa_e((string) $sub['checked_label']) ?>
        </p>

        <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap">
          <a class="btn btn--outline btn--sm"
             href="<?= wa_e(wa_url('feeds.php?feed=' . urlencode((string) $sub['feed_url']) . '&library_id=' . (int) $sub['library_id'])) ?>">
            <span class="ms ms-16">refresh</span> Xem trước
          </a>

          <form method="post" action="<?= wa_e(wa_url('feeds.php')) ?>" style="margin:0">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="action" value="subscribe">
            <input type="hidden" name="feed_url" value="<?= wa_e((string) $sub['feed_url']) ?>">
            <input type="hidden" name="library_id" value="<?= (int) $sub['library_id'] ?>">
            <input type="hidden" name="tags" value="<?= wa_e((string) $sub['tags']) ?>">
            <button class="btn btn--outline btn--sm" type="submit">
              <span class="ms ms-16">bookmark_added</span> Đã theo dõi
            </button>
          </form>

          <form method="post" action="<?= wa_e(wa_url('feeds.php')) ?>"
                onsubmit="return confirm('Xoá nguồn feed này?');" style="margin:0">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="action" value="unsubscribe">
            <input type="hidden" name="feed_id" value="<?= (int) $sub['id'] ?>">
            <button class="btn btn--ghost btn--sm" type="submit">
              <span class="ms ms-16">delete</span> Xoá
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
  // Bật/tắt nút "Lưu đã chọn" theo số ô đang tích.
  (function () {
    var boxes = document.querySelectorAll('input[name="items[]"]');
    var btn = document.getElementById('wa-feed-save');
    if (!boxes.length || !btn) { return; }

    var sync = function () {
      var n = 0;
      boxes.forEach(function (b) { if (b.checked) { n++; } });
      btn.disabled = (n === 0);
      btn.querySelector('span:last-child').textContent = n > 0 ? ('Lưu ' + n + ' bài đã chọn') : 'Lưu đã chọn';
    };

    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    sync();
  })();
</script>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
