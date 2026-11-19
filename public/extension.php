<?php
/**
 * extension.php — KẾT NỐI CHROME EXTENSION (V3 / FEATURE 7).
 *
 * Trang này là phía WEB của cơ chế ghép nối Extension:
 *   - Tạo token riêng (bearer token) cho Extension.
 *   - Xem danh sách token, thời hạn, phạm vi quyền, trạng thái thu hồi.
 *   - Thu hồi token ngay lập tức.
 *
 * Token thô CHỈ hiện đúng một lần ngay sau khi tạo; phía server chỉ lưu hash.
 * Extension KHÔNG lưu mật khẩu, không lưu cookie phiên.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

$newToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wa_csrf_check()) {
        $_SESSION['wa_flash'] = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng thử lại.'];
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $days = (int) ($_POST['days'] ?? 90);
            $days = in_array($days, [7, 30, 90, 365, 0], true) ? $days : 90;

            $scopes = array_keys(WA_API_SCOPES);

            $result = wa_api_token_create(
                $userId,
                (string) ($_POST['name'] ?? 'Chrome Extension'),
                $scopes,
                $days > 0 ? $days : null
            );

            if ($result['ok'] === true) {
                // Token thô chỉ hiện MỘT LẦN: lưu tạm trong session để trang sau
                // khi redirect có thể hiển thị, rồi xoá ngay sau khi đọc.
                $_SESSION['wa_api_new_token'] = (string) $result['token'];
                $_SESSION['wa_flash'] = ['type' => 'success', 'text' => (string) $result['message']];
            } else {
                $_SESSION['wa_flash'] = ['type' => 'error', 'text' => (string) $result['message']];
            }
        } elseif ($action === 'revoke') {
            $ok = wa_api_token_revoke((int) ($_POST['token_id'] ?? 0), $userId);

            $_SESSION['wa_flash'] = [
                'type' => $ok ? 'success' : 'error',
                'text' => $ok ? 'Đã thu hồi token.' : 'Không thu hồi được token này.',
            ];
        }
    }

    header('Location: ' . wa_url('extension.php'));
    exit;
}

$tokens = wa_api_token_list($userId);

/* Token thô chỉ hiện đúng một lần: đọc từ session rồi xoá ngay. */
$newToken = '';

if (isset($_SESSION['wa_api_new_token']) && is_string($_SESSION['wa_api_new_token'])) {
    $newToken = $_SESSION['wa_api_new_token'];
    unset($_SESSION['wa_api_new_token']);
}

$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Kết nối Chrome Extension';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Kết nối</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Chrome Extension</span>
      </div>
      <h1 class="ribbon__title">Kết nối Chrome Extension</h1>
      <p class="ribbon__sub">
        Tạo một token riêng để Extension lưu trang và tạo thư viện trực tiếp.
        Token có thời hạn, giới hạn tần suất và thu hồi được bất cứ lúc nào.
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

<!-- ===================== TOKEN MỚI (HIỆN MỘT LẦN) ===================== -->
<?php if ($newToken !== ''): ?>
  <div class="state state--success" style="margin-bottom:16px">
    <div class="state__body">
      <span class="state__icon"><span class="ms ms-18 ms-fill">vpn_key</span></span>
      <div style="flex:1">
        <span class="state__title">Token của bạn (chỉ hiện đúng một lần)</span>
        <p class="state__text">
          Dán token này vào ô <b>Token</b> trong popup của Extension. Hãy lưu lại ngay —
          sau khi rời trang này sẽ không xem lại được.
        </p>
        <textarea class="input" rows="2" readonly style="width:100%;font-family:ui-monospace,monospace;font-size:13px;margin-top:8px"><?= wa_e($newToken) ?></textarea>
        <div class="row" style="margin-top:8px">
          <button class="btn btn--outline btn--sm" type="button"
                  onclick="(function(t){var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');a.remove();this.innerHTML='<span class=\"ms ms-16 ms-fill\">check</span> Đã sao chép';}.bind(this))(document.querySelector('textarea[readonly]').value))">
            <span class="ms ms-16">content_copy</span> Sao chép token
          </button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">extension</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Tạo token mới</h2>
        <p class="t-body-sm t-muted">
          Token có quyền đọc thư viện, tạo thư viện và lưu trang. Bạn có thể thu hồi bất kỳ lúc nào.
        </p>
      </div>
    </div>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('extension.php')) ?>">
    <?= wa_csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <div class="capture__input">
      <span class="ms ms-18">badge</span>
      <input class="input input--icon" type="text" name="name"
             value="Chrome Extension" maxlength="120"
             placeholder="Tên token" aria-label="Tên token">
    </div>

    <div class="capture__input">
      <span class="ms ms-18">schedule</span>
      <select class="input input--icon" name="days" aria-label="Thời hạn token">
        <option value="7">Hết hạn sau 7 ngày</option>
        <option value="30">Hết hạn sau 30 ngày</option>
        <option value="90" selected>Hết hạn sau 90 ngày</option>
        <option value="365">Hết hạn sau 1 năm</option>
        <option value="0">Không hết hạn (có thể thu hồi)</option>
      </select>
    </div>

    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">key</span>
      <span>Tạo token</span>
    </button>
  </form>
</div>

<!-- ===================== DANH SÁCH TOKEN ===================== -->
<div class="pagehead" style="margin-top:24px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản lý</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Token của bạn (<?= count($tokens) ?>)</h2>
  </div>
</div>

<?php if ($tokens === []): ?>
  <div class="empty">
    <span class="ms ms-32">vpn_key_off</span>
    <h3>Chưa có token nào</h3>
    <p>Tạo token ở ô trên để Extension có thể kết nối.</p>
  </div>
<?php else: ?>
  <div class="state-list">
    <?php foreach ($tokens as $t): ?>
      <div class="state-item">
        <div class="state-item__head">
          <span class="state-item__name">
            <span class="ms ms-18">key</span>
            <?= wa_e((string) $t['name']) ?>
          </span>
          <span class="chip chip--<?= $t['active'] ? 'teal' : 'plain' ?>">
            <?= $t['revoked'] ? 'đã thu hồi' : ($t['expired'] ? 'đã hết hạn' : 'đang hoạt động') ?>
          </span>
        </div>

        <p class="t-body-sm t-muted" style="margin-top:8px">
          Quyền: <?= wa_e(implode(', ', $t['scopes'])) ?><br>
          Hết hạn: <?= wa_e((string) $t['expires_label']) ?><br>
          Dùng lần cuối: <?= wa_e((string) $t['used_label']) ?>
        </p>

        <?php if (!$t['revoked']): ?>
          <div class="row" style="gap:8px;margin-top:10px">
            <form method="post" action="<?= wa_e(wa_url('extension.php')) ?>"
                  onsubmit="return confirm('Thu hồi token này? Extension sẽ ngừng hoạt động ngay.');" style="margin:0">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn--outline btn--sm" type="submit">
                <span class="ms ms-16">link_off</span>
                Thu hồi token
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ===================== HƯỚNG DẪN CÀI ĐẶT ===================== -->
<div class="pagehead" style="margin-top:28px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Hướng dẫn</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Cài Chrome Extension</h2>
    <p class="pagehead__desc">
      Thư mục Extension nằm trong dự án tại <code>chrome-extension/</code>.
    </p>
  </div>
</div>

<div class="state-list">
  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">extension</span>Bước 1 — Bật chế độ dành cho nhà phát triển</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Mở <code>chrome://extensions</code> → bật <b>Developer mode</b> (góc trên bên phải).
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">folder_open</span>Bước 2 — Nạp Extension</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Bấm <b>Load unpacked</b> → chọn thư mục <code>chrome-extension/</code> trong dự án.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">link</span>Bước 3 — Điền địa chỉ server và token</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Bấm biểu tượng WebArchive trên thanh công cụ → mở <b>Cài đặt</b> → điền
      <b>Địa chỉ máy chủ</b> (ví dụ <code>http://localhost:8080</code>) và dán token vừa tạo.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
