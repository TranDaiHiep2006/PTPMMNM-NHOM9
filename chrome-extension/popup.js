/**
 * popup.js — Giao diện popup của WebArchive Extension.
 *
 * TRIẾT LÝ GIAO DIỆN (ưu tiên tốc độ thao tác):
 *   1. Mở popup là thấy URL + tiêu đề tab, chọn thư viện, bấm "Lưu trang".
 *   2. Thư viện được chọn gần nhất được GHI NHỚ -> lần sau mở popup là lưu
 *      được ngay bằng một cú bấm (không cần chọn lại).
 *   3. Tạo thư viện mới ngay trong popup: chỉ MỘT ô bắt buộc là TÊN THƯ VIỆN.
 *      Mô tả / màu / biểu tượng dùng giá trị mặc định, đổi sau trong WebArchive.
 *   4. Trang bình thường -> lưu ngay, không hỏi thêm.
 *      Trang có dấu hiệu rủi ro -> hỏi rõ ràng với HAI nút: KHÔNG LƯU / VẪN LƯU.
 *   5. Mọi thao tác xác nhận của người dùng CHỈ bỏ qua CẢNH BÁO rủi ro.
 *      Chốt an toàn (chặn mạng riêng, cloud metadata...) luôn do máy chủ quyết định.
 */

const $ = (id) => document.getElementById(id);

let state = {
  settings: { serverUrl: '', token: '', autoSave: false, defaultLibraryId: null, domains: [] },
  libraries: [],
  tab: null,
  view: 'save',
  /** URL đang chờ người dùng xác nhận cảnh báo rủi ro. */
  pendingRisk: null,
  /** V3 ĐỢT 4 / F12 — cảnh báo trùng lặp đang chờ xác nhận. */
  pendingDup: null,
  /**
   * CỜ XÁC NHẬN TÍCH LUỸ — người dùng đã bấm "VẪN LƯU" cho cảnh báo nào.
   *
   * VÌ SAO PHẢI TÍCH LUỸ:
   *   Một trang có thể đồng thời có CẢ cảnh báo rủi ro VÀ cảnh báo trùng lặp.
   *   Máy chủ trả lần lượt: 409 rủi ro -> người dùng xác nhận -> 409 trùng lặp
   *   -> người dùng xác nhận -> lưu được.
   *
   *   Nếu mỗi lần xác nhận chỉ giữ cờ của mình (overrideRisk=true, overrideDup=false
   *   rồi ngược lại), máy chủ sẽ quay lại cảnh báo cũ và người dùng rơi vào
   *   VÒNG LẶP VÔ HẠN. Vì vậy hai cờ này CHỈ TĂNG, không bao giờ tự tắt trong
   *   cùng một lần lưu.
   *
   * AN TOÀN: cờ này chỉ bỏ qua CẢNH BÁO MỀM. Chốt SSRF cứng do máy chủ quyết
   * định và KHÔNG bao giờ bị bỏ qua (xem public/api.php — kiểm tra an toàn chạy
   * TRƯỚC khi đọc cờ này).
   */
  overrideRisk: false,
  overrideDup: false,
  /**
   * Số lần người dùng đã bấm "VẪN LƯU" trong lần lưu hiện tại (BUG-08).
   *
   * Một trang có thể đồng thời có cả cảnh báo rủi ro VÀ cảnh báo trùng lặp.
   * Hai cờ trên CHỈ TĂNG, không bao giờ tự tắt trong cùng một lần lưu — nếu
   * không, máy chủ sẽ quay lại cảnh báo cũ và người dùng rơi vào VÒNG LẶP
   * VÔ HẠN (chuỗi HTTP 409).
   *
   * Biến này là LƯỚI AN TOÀN thứ hai: nếu máy chủ vẫn trả lại cảnh báo sau
   * khi cả hai cờ đã bật, popup sẽ dừng và báo lỗi thay vì hỏi lại mãi.
   */
  confirmRounds: 0
};

/* =========================================================================
   TIỆN ÍCH
   ====================================================================== */

function show(view) {
  state.view = view;
  $('view-save').hidden = view !== 'save';
  $('view-settings').hidden = view !== 'settings';
}

function setStatus(text, kind) {
  const el = $('status');
  el.textContent = text;
  el.className = 'head__status' + (kind ? ' is-' + kind : '');
}

function setResult(box, text, kind, html) {
  const el = $(box);
  el.hidden = false;
  el.className = 'result is-' + kind;
  el.innerHTML = html || escapeHtml(text);
}

function clearResult(box) {
  $(box).hidden = true;
  $(box).innerHTML = '';
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

function send(msg) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(msg, (res) => {
      if (chrome.runtime.lastError) {
        resolve({ ok: false, error: chrome.runtime.lastError.message });
        return;
      }
      resolve(res || { ok: false, error: 'Không nhận được phản hồi.' });
    });
  });
}

/** Ghi nhớ thư viện vừa dùng để lần sau mở popup đã chọn sẵn. */
async function rememberLibrary(libraryId) {
  if (!libraryId) return;

  await chrome.storage.local.set({ lastLibraryId: Number(libraryId) });
  state.settings.lastLibraryId = Number(libraryId);
}

/**
 * Thông điệp thân thiện cho từng mã lỗi của API (BUG-05).
 *
 * Mục tiêu: người dùng luôn biết CHÍNH XÁC phải làm gì tiếp theo, và
 * KHÔNG BAO GIỜ thấy thông báo thành công khi máy chủ thực sự thất bại.
 */
function apiErrorMessage(res) {
  const base = (res && res.error) ? res.error : 'Không thực hiện được yêu cầu.';
  const code = (res && res.code) ? res.code : '';

  switch (code) {
    case 'no_token':
      return 'Chưa có token kết nối. Mở Cài đặt và dán token tạo trong trang "Kết nối Chrome Extension".';

    case 'unauthorized':
      return base + ' Token có thể đã hết hạn hoặc bị thu hồi — hãy tạo token mới trong WebArchive.';

    case 'forbidden':
      return base + ' Token của bạn không có quyền này — hãy tạo lại token với đủ phạm vi.';

    case 'rate_limit':
      return 'Quá nhiều yêu cầu trong thời gian ngắn. Vui lòng thử lại sau ít phút.';

    case 'network':
      return 'Không kết nối được tới máy chủ WebArchive. Hãy chắc chắn máy chủ đang chạy và địa chỉ trong Cài đặt đúng.';

    case 'server_error':
      return 'Máy chủ WebArchive đang gặp lỗi. Vui lòng thử lại sau.';

    case 'bad_response':
      return 'Máy chủ trả về dữ liệu không hợp lệ. Vui lòng kiểm tra địa chỉ máy chủ trong Cài đặt.';

    default:
      return base;
  }
}

/* =========================================================================
   KHỞI TẠO
   ====================================================================== */

async function init() {
  const res = await send({ type: 'getSettings' });

  if (res.ok) {
    state.settings = res.settings;
  }

  // Đọc tab hiện tại (chỉ cần activeTab).
  try {
    const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    state.tab = tabs && tabs[0] ? tabs[0] : null;
  } catch (e) {
    state.tab = null;
  }

  renderTabInfo();
  renderSettings();
  await loadLibraries();

  show('save');
}

function renderTabInfo() {
  const t = state.tab;

  if (!t) {
    $('tabTitle').textContent = 'Không đọc được tab hiện tại';
    $('tabUrl').textContent = '—';
    $('btnSave').disabled = true;
    return;
  }

  const url = t.url || '';

  $('tabTitle').textContent = t.title || '(không có tiêu đề)';
  $('tabUrl').textContent = url;

  // Không cho lưu các URL không phải http(s).
  if (!/^https?:\/\//i.test(url)) {
    $('btnSave').disabled = true;
    $('btnSaveLabel').textContent = 'URL này không thể lưu';
    return;
  }

  $('btnSave').disabled = false;
  $('btnSaveLabel').textContent = 'Lưu trang';
}

/**
 * Tải danh sách thư viện từ máy chủ (BUG-03).
 *
 * PHÂN BIỆT RÕ 3 TRẠNG THÁI:
 *   1. LỖI kết nối / xác thực -> báo lỗi, KHÔNG hiển thị danh sách rỗng (tránh
 *      người dùng tưởng là không có thư viện nào).
 *   2. Kết nối OK nhưng KHÔNG có thư viện -> thông báo "chưa có thư viện" và
 *      mở ô tạo thư viện nhanh.
 *   3. Kết nối OK và có thư viện -> đổ danh sách vào ô chọn.
 */
async function loadLibraries() {
  const sel = $('librarySelect');

  // Trạng thái "đang tải" — KHÔNG để người dùng bấm Lưu khi chưa biết danh sách.
  sel.disabled = true;
  setStatus('Đang tải danh sách thư viện…', '');

  const res = await send({ type: 'whoami' });

  if (!res.ok) {
    state.libraries = [];
    fillLibrarySelects();
    sel.disabled = false;

    const msg = apiErrorMessage(res);
    setStatus('Chưa kết nối được', 'err');
    setResult('result', msg, 'err');
    return;
  }

  const data = res.data || {};
  const libs = Array.isArray(data.libraries) ? data.libraries : [];

  state.libraries = libs;
  fillLibrarySelects();
  sel.disabled = false;

  if (!libs.length) {
    setStatus('Đã kết nối · ' + (data.user && data.user.name ? data.user.name : ''), 'ok');
    setResult('result',
      'Tài khoản này chưa có thư viện nào. Bấm "+" để tạo thư viện đầu tiên.',
      'info');
    openQuickLibrary();
    return;
  }

  setStatus('Đã kết nối · ' + (data.user && data.user.name ? data.user.name : ''), 'ok');
}

function fillLibrarySelects() {
  const sel = $('librarySelect');
  const def = $('defaultLib');

  const keep = sel.value;
  const keepDef = def.value;

  sel.innerHTML = '<option value="">— Chọn thư viện —</option>';
  def.innerHTML = '<option value="">— Chưa chọn —</option>';

  state.libraries.forEach((lib) => {
    const label = `${lib.name} (${lib.role === 'owner' ? 'chủ sở hữu' : 'biên tập viên'})`;

    const o1 = document.createElement('option');
    o1.value = lib.id;
    o1.textContent = label;
    sel.appendChild(o1);

    const o2 = document.createElement('option');
    o2.value = lib.id;
    o2.textContent = lib.name;
    def.appendChild(o2);
  });

  if (keep) sel.value = keep;
  if (keepDef) def.value = keepDef;

  // ƯU TIÊN 1: thư viện đã chọn gần nhất (ghi nhớ trong chrome.storage.local).
  const remembered = state.settings.lastLibraryId;

  if (remembered && state.libraries.some((l) => Number(l.id) === Number(remembered))) {
    sel.value = String(remembered);
  }

  // ƯU TIÊN 2: thư viện mặc định đã cấu hình.
  if (!sel.value && state.settings.defaultLibraryId) {
    const defId = String(state.settings.defaultLibraryId);
    if (state.libraries.some((l) => String(l.id) === defId)) {
      sel.value = defId;
    }
  }

  // ƯU TIÊN 3: thư viện đầu tiên.
  if (!sel.value && state.libraries.length) {
    sel.value = String(state.libraries[0].id);
  }
}

/* =========================================================================
   LƯU TRANG
   ====================================================================== */

/**
 * Bấm "Lưu trang" — bắt đầu MỘT lần lưu MỚI.
 *
 * Mỗi lần lưu mới bắt đầu với cả hai cờ xác nhận ở trạng thái TẮT, nên
 * người dùng luôn phải xác nhận lại từ đầu với một trang khác.
 */
function doSave() {
  state.overrideRisk = false;
  state.overrideDup = false;
  state.confirmRounds = 0;

  runSave();
}

/**
 * Gửi yêu cầu lưu với các cờ xác nhận TÍCH LUỸ hiện có.
 *
 * Cờ chỉ BẬT, không bao giờ tự TẮT trong cùng một lần lưu — nhờ vậy khi một
 * trang có cả cảnh báo rủi ro lẫn cảnh báo trùng lặp, người dùng xác nhận
 * lần lượt mà không rơi vào vòng lặp vô hạn (BUG-08).
 *
 * BẢN SỬA BUG-08: bổ sung bộ đếm số lần đã xác nhận. Nếu máy chủ VẪN trả
 * lại cùng một cảnh báo DỒ SAU KHI người dùng đã xác nhận, popup báo lỗi
 * rõ ràng thay vì hỏi lại vô hạn (nguồn gốc của vòng lặp HTTP 409).
 */
const MAX_CONFIRM_ROUNDS = 4;

async function runSave() {
  const url = state.tab ? (state.tab.url || '') : '';
  const libraryId = $('librarySelect').value;
  const tags = $('tagsInput').value.trim();

  if (!libraryId) {
    openQuickLibrary();
    setResult('result', 'Chưa có thư viện nào — hãy tạo thư viện trước khi lưu.', 'err');
    return;
  }

  clearResult('result');
  hideRisk();
  hideDup();
  $('progress').hidden = false;
  $('progressFill').style.width = '15%';
  $('progressText').textContent = 'Đang tải trang và tạo bản lưu…';

  $('btnSave').disabled = true;

  const res = await send({
    type: 'savePage',
    url,
    libraryId,
    tags,
    overrideRisk: state.overrideRisk === true,
    overrideDup: state.overrideDup === true
  });

  $('progressFill').style.width = '100%';
  $('progressText').textContent = res.ok ? 'Xong.' : 'Thất bại.';
  $('btnSave').disabled = false;

  if (!res.ok) {
    // Cảnh báo rủi ro -> hiện HAI nút KHÔNG LƯU / VẪN LƯU.
    // (Máy chủ chỉ trả lại cảnh báo rủi ro khi cờ overrideRisk CHƯA được bật,
    //  nên nếu ta đã bật cờ mà vẫn nhận cảnh báo này thì đó là lỗi hiển thị —
    //  hiện thông báo lỗi thay vì hỏi lại vô hạn.)
    if (res.risk) {
      if (state.overrideRisk === true) {
        setResult('result',
          res.error || 'Máy chủ vẫn báo cảnh báo rủi ro dù đã xác nhận. Không thể bỏ qua — hãy thử lại sau.',
          'err');
        $('progress').hidden = true;
        return;
      }

      if (state.confirmRounds >= MAX_CONFIRM_ROUNDS) {
        setResult('result',
          'Quá nhiều lần xác nhận liên tiếp — đã dừng để tránh lặp. Hãy thử lại sau.',
          'err');
        $('progress').hidden = true;
        return;
      }

      state.confirmRounds++;
      showRisk(res);
      $('progress').hidden = true;
      return;
    }

    // V3 ĐỢT 4 / F12 — cảnh báo trùng lặp -> hiện HAI nút KHÔNG LƯU / VẪN LƯU.
    if (res.duplicate) {
      if (state.overrideDup === true) {
        setResult('result',
          res.error || 'Máy chủ vẫn báo trùng lặp dù đã xác nhận. Không thể bỏ qua — hãy thử lại sau.',
          'err');
        $('progress').hidden = true;
        return;
      }

      if (state.confirmRounds >= MAX_CONFIRM_ROUNDS) {
        setResult('result',
          'Quá nhiều lần xác nhận liên tiếp — đã dừng để tránh lặp. Hãy thử lại sau.',
          'err');
        $('progress').hidden = true;
        return;
      }

      state.confirmRounds++;
      showDup(res);
      $('progress').hidden = true;
      return;
    }

    setResult('result', apiErrorMessage(res), 'err');
    $('progress').hidden = true;
    return;
  }

  const data = res.data || {};

  // Ghi nhớ thư viện vừa dùng -> lần sau lưu được bằng một cú bấm.
  await rememberLibrary(libraryId);

  // Lần lưu đã xong -> cờ xác nhận không còn ý nghĩa.
  state.overrideRisk = false;
  state.overrideDup = false;
  state.confirmRounds = 0;

  const message = data.message || 'Đã lưu trang.';

  setResult(
    'result',
    message,
    'ok',
    escapeHtml(message)
    + (data.snapshot_id ? '<br><a href="#" id="openReader" data-snapshot-id="' + escapeHtml(String(data.snapshot_id)) + '">Mở bản lưu</a>' : '')
  );

  const link = $('openReader');
  if (link) {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      chrome.tabs.create({ url: state.settings.serverUrl + '/reader.php?id=' + data.snapshot_id });
    });
  }
}

/* =========================================================================
   CẢNH BÁO RỦI RO — KHÔNG LƯU / VẪN LƯU
   ====================================================================== */

function showRisk(res) {
  state.pendingRisk = res;

  $('riskText').textContent = res.risk_reason || 'Trang này có dấu hiệu rủi ro.';

  const list = $('riskList');
  list.innerHTML = '';

  (res.signals || []).forEach((s) => {
    const li = document.createElement('li');
    li.textContent = s.detail || s.label || '';
    list.appendChild(li);
  });

  $('riskBox').hidden = false;
}

function hideRisk() {
  $('riskBox').hidden = true;
  state.pendingRisk = null;
}

/* =========================================================================
   V3 ĐỢT 4 / F12 — CẢNH BÁO TRÙNG LẶP: KHÔNG LƯU / VẪN LƯU
   ========================================================================= */

function showDup(res) {
  state.pendingDup = res;

  $('dupText').textContent = res.error
    || 'Trang này trùng hoặc gần giống với bài đã lưu.';

  const list = $('dupList');
  list.innerHTML = '';

  (res.duplicates || []).forEach((d) => {
    const li = document.createElement('li');

    const title = document.createElement('span');
    title.textContent = d.title || d.source_url || '(không rõ tiêu đề)';
    li.appendChild(title);

    const meta = document.createElement('span');
    meta.textContent = ' — ' + (d.level_label || 'Trùng')
      + (typeof d.score === 'number' ? ' (' + d.score + '%)' : '')
      + (d.library_name ? ' · ' + d.library_name : '');
    meta.style.color = 'var(--wa-muted)';
    li.appendChild(meta);

    if (d.reason) {
      const why = document.createElement('div');
      why.textContent = d.reason;
      why.style.color = 'var(--wa-muted)';
      li.appendChild(why);
    }

    if (d.reader_url) {
      const a = document.createElement('a');
      a.href = d.reader_url;
      a.className = 'risk__link';
      a.textContent = 'xem bài đã lưu';
      a.target = '_blank';
      li.appendChild(a);
    }

    list.appendChild(li);
  });

  $('dupBox').hidden = false;
}

function hideDup() {
  $('dupBox').hidden = true;
  state.pendingDup = null;
}

/* =========================================================================
   TẠO THƯ VIỆN SIÊU NHANH (CHỈ MỘT Ô: TÊN THƯ VIỆN)
   ====================================================================== */

function openQuickLibrary() {
  $('quickLib').hidden = false;
  $('quickLibName').focus();
}

function closeQuickLibrary() {
  $('quickLib').hidden = true;
  $('quickLibName').value = '';
}

async function doQuickCreateLibrary() {
  const name = $('quickLibName').value.trim();

  clearResult('result');

  if (!name) {
    setResult('result', 'Vui lòng nhập tên thư viện.', 'err');
    $('quickLibName').focus();
    return;
  }

  const btn = $('btnQuickCreate');
  btn.disabled = true;

  // Mô tả / màu / biểu tượng dùng giá trị mặc định — tạo riêng tư.
  const res = await send({
    type: 'createLibrary',
    name,
    description: '',
    visibility: 'private',
    color: 'a',
    icon: 'folder_special'
  });

  btn.disabled = false;

  if (!res.ok) {
    setResult('result', apiErrorMessage(res), 'err');
    return;
  }

  // Máy chủ trả về danh sách thư viện ĐÃ CẬP NHẬT — dùng luôn để khỏi gọi
  // lại API (BUG-04: không được yêu cầu người dùng đóng/mở lại Extension).
  const data = res.data || {};

  if (Array.isArray(data.libraries) && data.libraries.length) {
    state.libraries = data.libraries;
    fillLibrarySelects();
  } else {
    await loadLibraries();
  }

  // TỰ CHỌN thư viện vừa tạo.
  const newId = String((data.library && data.library.id) || '');

  if (newId && state.libraries.some((l) => String(l.id) === newId)) {
    $('librarySelect').value = newId;
  }

  // Ghi nhớ thư viện vừa tạo.
  await rememberLibrary(newId);

  closeQuickLibrary();

  setResult(
    'result',
    (data.message || 'Đã tạo thư viện.'),
    'ok',
    escapeHtml(data.message || 'Đã tạo thư viện.')
    + '<br>Đã chọn thư viện mới — bấm “Lưu trang” để lưu ngay.'
  );
}

/* =========================================================================
   CÀI ĐẶT
   ====================================================================== */

function renderSettings() {
  const s = state.settings;

  $('serverUrl').value = s.serverUrl || '';
  $('tokenInput').value = s.token || '';
  $('autoSave').checked = s.autoSave === true;
  $('defaultLib').value = s.defaultLibraryId ? String(s.defaultLibraryId) : '';

  renderDomains();
}

function renderDomains() {
  const list = $('domainList');
  list.innerHTML = '';

  const domains = state.settings.domains || [];

  if (!domains.length) {
    const li = document.createElement('li');
    li.style.color = 'var(--wa-muted)';
    li.textContent = 'Chưa có domain nào được theo dõi.';
    list.appendChild(li);
    return;
  }

  domains.forEach((d, index) => {
    const li = document.createElement('li');

    const name = document.createElement('span');
    name.className = 'dom';
    name.textContent = d.domain;
    li.appendChild(name);

    const sel = document.createElement('select');
    const none = document.createElement('option');
    none.value = '';
    none.textContent = 'Thư viện mặc định';
    sel.appendChild(none);

    state.libraries.forEach((lib) => {
      const o = document.createElement('option');
      o.value = lib.id;
      o.textContent = lib.name;
      sel.appendChild(o);
    });

    sel.value = d.libraryId ? String(d.libraryId) : '';

    sel.addEventListener('change', async () => {
      const arr = (state.settings.domains || []).slice();
      arr[index].libraryId = sel.value ? Number(sel.value) : null;
      state.settings.domains = arr;
      await saveSettings();
    });

    li.appendChild(sel);

    const x = document.createElement('button');
    x.className = 'x';
    x.type = 'button';
    x.title = 'Xoá domain khỏi danh sách theo dõi';
    x.textContent = '×';
    x.addEventListener('click', async () => {
      const arr = (state.settings.domains || []).filter((_, i) => i !== index);
      state.settings.domains = arr;
      await saveSettings();
      renderDomains();
    });

    li.appendChild(x);
    list.appendChild(li);
  });
}

async function saveSettings() {
  const res = await send({
    type: 'saveSettings',
    settings: {
      serverUrl: $('serverUrl').value.trim(),
      token: $('tokenInput').value.trim(),
      autoSave: $('autoSave').checked,
      defaultLibraryId: $('defaultLib').value ? Number($('defaultLib').value) : null,
      domains: state.settings.domains || []
    }
  });

  if (res.ok) {
    state.settings = res.settings;
  }
}

/* =========================================================================
   LỊCH SỬ
   ====================================================================== */

async function loadHistory() {
  const res = await send({ type: 'getHistory' });
  const list = $('historyList');

  list.innerHTML = '';

  if (!res.ok || !res.history.length) {
    const li = document.createElement('li');
    li.textContent = 'Chưa có lần lưu nào.';
    list.appendChild(li);
    return;
  }

  res.history.slice(0, 12).forEach((h) => {
    const li = document.createElement('li');
    const cls = h.ok ? 'ok' : 'bad';
    li.innerHTML = '<span class="' + cls + '">' + (h.ok ? '✓' : '✕') + '</span> '
      + escapeHtml(h.title) + '<br><span style="color:var(--wa-muted)">' + escapeHtml(h.message || '') + '</span>';
    list.appendChild(li);
  });
}

/* =========================================================================
   GẮN SỰ KIỆN
   ====================================================================== */

$('btnSave').addEventListener('click', () => doSave());

$('btnNewLib').addEventListener('click', () => {
  clearResult('result');
  openQuickLibrary();
});

$('btnQuickCancel').addEventListener('click', closeQuickLibrary);

$('btnQuickCreate').addEventListener('click', doQuickCreateLibrary);

$('quickLibName').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    doQuickCreateLibrary();
  }
});

// KHÔNG LƯU -> huỷ, không tải trang.
$('btnRiskCancel').addEventListener('click', () => {
  hideRisk();
  hideDup();
  state.overrideRisk = false;
  state.overrideDup = false;
  state.confirmRounds = 0;
  $('progress').hidden = true;
  setResult('result', 'Đã huỷ — trang này không được lưu.', 'info');
});

// VẪN LƯU -> BẬT cờ xác nhận rủi ro (tích luỹ) rồi thử lại.
// Cờ trùng lặp (nếu đã bật trước đó) VẪN GIỮ NGUYÊN -> không quay lại
// cảnh báo cũ, không lặp vô hạn.
$('btnRiskProceed').addEventListener('click', () => {
  hideRisk();
  state.overrideRisk = true;
  runSave();
});

// V3 ĐỢT 4 / F12 — KHÔNG LƯU -> huỷ, không tạo bản lưu nào.
$('btnDupCancel').addEventListener('click', () => {
  hideDup();
  hideRisk();
  state.overrideRisk = false;
  state.overrideDup = false;
  state.confirmRounds = 0;
  $('progress').hidden = true;
  setResult('result', 'Đã huỷ — trang này không được lưu.', 'info');
});

// V3 ĐỢT 4 / F12 — VẪN LƯU -> BẬT cờ xác nhận trùng lặp (tích luỹ) rồi thử lại.
// Cờ rủi ro (nếu đã bật trước đó) VẪN GIỮ NGUYÊN -> không quay lại cảnh báo
// cũ, không lặp vô hạn. KHÔNG tự xoá/gộp bản lưu.
$('btnDupProceed').addEventListener('click', () => {
  hideDup();
  state.overrideDup = true;
  runSave();
});

$('librarySelect').addEventListener('change', (e) => {
  rememberLibrary(e.target.value);
});

$('btnSettings').addEventListener('click', () => show('settings'));

$('btnTestConn').addEventListener('click', async () => {
  const serverUrl = $('serverUrl').value.trim();

  clearResult('settingsResult');

  // Xin quyền truy cập máy chủ WebArchive (bắt buộc để gọi API).
  const granted = await requestServerPermission(serverUrl);

  if (!granted) {
    setResult('settingsResult',
      'Chưa được cấp quyền truy cập ' + serverUrl + '. Bấm lại và chọn "Cho phép" trong hộp thoại của Chrome.',
      'err');
    return;
  }

  await saveSettings();
  await loadLibraries();

  if (state.libraries.length) {
    setResult('settingsResult', 'Kết nối thành công — ' + state.libraries.length + ' thư viện có quyền ghi.', 'ok');
  } else {
    setResult('settingsResult', 'Kết nối thành công nhưng tài khoản chưa có thư viện nào. Bấm "+" ở màn hình chính để tạo thư viện.', 'info');
  }
});

$('btnBackFromSettings').addEventListener('click', async () => {
  await saveSettings();
  show('save');
});

$('serverUrl').addEventListener('change', saveSettings);
$('tokenInput').addEventListener('change', saveSettings);
$('defaultLib').addEventListener('change', saveSettings);

$('autoSave').addEventListener('change', async () => {
  await saveSettings();

  if ($('autoSave').checked) {
    const domains = state.settings.domains || [];

    if (!domains.length) {
      setResult('settingsResult', 'Bật tự động lưu nhưng chưa có domain nào được theo dõi. Thêm domain ngay dưới đây.', 'info');
    }
  }
});

$('btnAddDomain').addEventListener('click', async () => {
  const input = $('domainInput');
  let host = input.value.trim().toLowerCase();

  if (!host) return;

  host = host.replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/^www\./, '');

  if (!host || host.indexOf('.') === -1) {
    setResult('settingsResult', 'Domain không hợp lệ. Ví dụ: example.com', 'err');
    return;
  }

  const arr = (state.settings.domains || []).slice();

  if (arr.some((d) => d.domain === host)) {
    setResult('settingsResult', 'Domain này đã có trong danh sách.', 'info');
    input.value = '';
    return;
  }

  // Xin quyền truy cập RIÊNG domain này (optional host permission).
  // Không xin toàn quyền mọi website; người dùng phải bấm "Cho phép".
  const granted = await requestHostPermission(host);

  if (!granted) {
    setResult('settingsResult',
      'Chưa được cấp quyền truy cập ' + host + '. Bấm lại và chọn "Cho phép" trong hộp thoại của Chrome.',
      'err');
    return;
  }

  arr.push({ domain: host, libraryId: null, tags: '' });
  state.settings.domains = arr;

  await saveSettings();
  input.value = '';
  renderDomains();
  setResult('settingsResult', 'Đã theo dõi ' + host + '.', 'ok');
});

/**
 * Xin quyền host cho một domain (trả về true nếu được cấp).
 *
 * Gọi thẳng `chrome.permissions.request()` — KHÔNG await `contains()` trước,
 * vì việc chờ một Promise khác sẽ làm mất "user gesture" và Chrome sẽ từ chối
 * mở hộp thoại xin quyền. Nếu quyền đã có, `request()` tự resolve ngay.
 */
async function requestHostPermission(host) {
  if (!chrome.permissions || !chrome.permissions.request) {
    return true;
  }

  const origins = ['https://' + host + '/*', 'http://' + host + '/*'];

  try {
    return await chrome.permissions.request({ origins });
  } catch (e) {
    return false;
  }
}

/**
 * Xin quyền truy cập máy chủ WebArchive (origin của API).
 */
async function requestServerPermission(serverUrl) {
  if (!chrome.permissions || !chrome.permissions.request) {
    return true;
  }

  let origin;
  try {
    origin = new URL(serverUrl).origin + '/*';
  } catch (e) {
    return false;
  }

  try {
    return await chrome.permissions.request({ origins: [origin] });
  } catch (e) {
    return false;
  }
}

$('domainInput').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    $('btnAddDomain').click();
  }
});

$('btnClearHistory').addEventListener('click', async () => {
  await send({ type: 'clearHistory' });
  await loadHistory();
});

// Mở Cài đặt khi bấm vào trạng thái kết nối.
$('status').addEventListener('click', () => show('settings'));
$('status').style.cursor = 'pointer';
$('status').title = 'Mở Cài đặt';

init();
