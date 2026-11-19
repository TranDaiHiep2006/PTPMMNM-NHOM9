/**
 * background.js — Service worker của WebArchive Extension (Manifest V3).
 *
 * NHIỆM VỤ:
 *   1. Gửi yêu cầu tới API WebArchive (có token riêng, không dùng cookie).
 *   2. Tự động lưu các trang thuộc domain người dùng đã cho phép theo dõi.
 *
 * BẢO MẬT:
 *   - Token KHÔNG nằm trong mã nguồn; người dùng dán vào qua Cài đặt và nó
 *     được lưu trong chrome.storage.local (KHÔNG đồng bộ ra cloud).
 *   - Không có mật khẩu, không có cookie phiên.
 *   - Không tự động lưu bất kỳ domain nào chưa được người dùng cho phép.
 *
 * BẢN SỬA BUG-02 (tự động lưu không ổn định):
 *   - Cấu hình được đọc/ghi qua `chrome.storage.local` — nguồn sự thật DUY NHẤT
 *     (popup và service worker luôn đọc cùng một chỗ nên luôn khớp nhau).
 *   - Mặc định TẮT. Bật/tắt nhiều lần vẫn giữ đúng trạng thái.
 *   - Service worker có thể bị Chrome tắt và khởi động lại: mọi trạng thái
 *     "đang xử lý" KHÔNG được giữ trong bộ nhớ của service worker, mà được
 *     ghi vào `chrome.storage.local` (mốc thời gian lưu gần đây) để sau khi
 *     khởi động lại vẫn chống lưu trùng đúng cách.
 *   - Tự động lưu KHÔNG BAO GIỜ tự vượt qua cảnh báo rủi ro / trùng lặp:
 *     cảnh báo nào cũng phải được người dùng xác nhận thủ công.
 */

const DEFAULT_SERVER = 'http://localhost:8080';
const DEFAULT_INTERVAL_MIN = 30;   // chống lưu quá tần suất
const MAX_HISTORY = 100;

/**
 * Khoá "đang xử lý" cho một URL.
 *
 * Dùng `chrome.storage.local` (không phải biến trong bộ nhớ) để tồn tại qua
 * lần khởi động lại của service worker.
 */
const SAVE_LOCK_PREFIX = 'lock:';

/* =========================================================================
   TIỆN ÍCH LƯU TRỮ
   ====================================================================== */

async function getSettings() {
  const data = await chrome.storage.local.get([
    'serverUrl', 'token', 'autoSave', 'defaultLibraryId', 'domains', 'lastLibraryId'
  ]);

  // `autoSave` LUÔN là boolean: nếu khoá bị xoá (do cài lại / dọn dữ liệu)
  // thì mặc định là TẮT (BUG-02: mặc định phải là OFF).
  const autoSave = data.autoSave === true;

  return {
    serverUrl: (data.serverUrl || DEFAULT_SERVER).replace(/\/+$/, ''),
    token: data.token || '',
    autoSave: autoSave,
    defaultLibraryId: data.defaultLibraryId || null,
    domains: Array.isArray(data.domains) ? data.domains : [],
    // Thư viện được chọn gần nhất — dùng để lưu bằng MỘT cú bấm.
    lastLibraryId: data.lastLibraryId || null
  };
}

async function setSetting(key, value) {
  await chrome.storage.local.set({ [key]: value });
}

/**
 * Ghi cấu hình từ popup — chỉ ghi các khoá ĐƯỢC BIẾT.
 *
 * Quan trọng (BUG-02): không ghi đè khoá nào người dùng không gửi lên, và
 * luôn ép `autoSave` thành boolean để trạng thái "bật/tắt" không bao giờ bị
 * lẫn (ví dụ `undefined` hay chuỗi "false" đều được hiểu là TẮT).
 */
async function saveSettings(incoming) {
  const patch = {};

  if (typeof incoming.serverUrl === 'string') {
    patch.serverUrl = incoming.serverUrl.trim().replace(/\/+$/, '');
  }

  if (typeof incoming.token === 'string') {
    patch.token = incoming.token.trim();
  }

  if (Object.prototype.hasOwnProperty.call(incoming, 'autoSave')) {
    patch.autoSave = incoming.autoSave === true;
  }

  if (Object.prototype.hasOwnProperty.call(incoming, 'defaultLibraryId')) {
    const id = Number(incoming.defaultLibraryId);
    patch.defaultLibraryId = Number.isFinite(id) && id > 0 ? id : null;
  }

  if (Array.isArray(incoming.domains)) {
    patch.domains = incoming.domains
      .filter((d) => d && typeof d.domain === 'string' && d.domain.trim() !== '')
      .map((d) => ({
        domain: String(d.domain).trim().toLowerCase(),
        libraryId: d.libraryId ? Number(d.libraryId) : null,
        tags: typeof d.tags === 'string' ? d.tags : ''
      }));
  }

  if (Object.prototype.hasOwnProperty.call(incoming, 'lastLibraryId')) {
    const id = Number(incoming.lastLibraryId);
    patch.lastLibraryId = Number.isFinite(id) && id > 0 ? id : null;
  }

  await chrome.storage.local.set(patch);

  return getSettings();
}

/* =========================================================================
   GỌI API
   ====================================================================== */

async function apiGet(action) {
  const s = await getSettings();

  if (!s.token) {
    const err = new Error('Chưa cấu hình token trong Cài đặt.');
    err.code = 'no_token';
    throw err;
  }

  const url = `${s.serverUrl}/api.php?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, {
    method: 'GET',
    headers: { 'Authorization': `Bearer ${s.token}` }
  });

  return handleResponse(res);
}

async function apiPost(action, payload) {
  const s = await getSettings();

  if (!s.token) {
    const err = new Error('Chưa cấu hình token trong Cài đặt.');
    err.code = 'no_token';
    throw err;
  }

  const url = `${s.serverUrl}/api.php?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${s.token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(payload || {})
  });

  return handleResponse(res);
}

/**
 * Gọi API, bắt lỗi mạng và đưa ra thông báo tiếng Việt dễ hiểu.
 *
 * - Máy chủ tắt        → "không kết nối được tới máy chủ".
 * - Chưa được cấp quyền → nhắc vào Cài đặt bấm "Kiểm tra kết nối".
 *
 * BẢN SỬA BUG-05: mọi lỗi đều mang theo MÃ lỗi cụ thể (`code`) để popup
 * phân biệt được:
 *   - `no_token`       : chưa dán token
 *   - `network`        : không gọi được máy chủ
 *   - `unauthorized`   : token sai / hết hạn / bị thu hồi (HTTP 401)
 *   - `forbidden`      : token thiếu scope (HTTP 403)
 *   - `rate_limit`     : quá giới hạn tần suất (HTTP 429)
 *   - `server_error`   : lỗi phía máy chủ (HTTP 5xx)
 *   - `bad_response`   : máy chủ trả dữ liệu không phải JSON
 */
async function apiCall(action, payload) {
  try {
    return payload === undefined ? await apiGet(action) : await apiPost(action, payload);
  } catch (e) {
    const msg = String((e && e.message) || e);

    if (/Failed to fetch|NetworkError|ERR_CONNECTION|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED/i.test(msg)) {
      const err = new Error(
        'Không kết nối được tới máy chủ WebArchive. Hãy chắc chắn máy chủ đang chạy '
        + 'và địa chỉ trong Cài đặt đúng (kèm http:// và cổng).'
      );
      err.code = 'network';
      throw err;
    }

    // Giữ nguyên mã lỗi đã gán sẵn.
    if (e && e.code) {
      throw e;
    }

    throw e;
  }
}

/**
 * Bọc lỗi HTTP thành lỗi có mã để popup hiển thị đúng trạng thái.
 */
function apiErrorFromResponse(res, data) {
  const err = new Error(
    (data && data.error) ? data.error : `Máy chủ trả về HTTP ${res.status}.`
  );

  err.status = res.status;

  if (res.status === 401) {
    err.code = 'unauthorized';
  } else if (res.status === 403) {
    err.code = 'forbidden';
  } else if (res.status === 429) {
    err.code = 'rate_limit';
  } else if (res.status >= 500) {
    err.code = 'server_error';
  } else {
    err.code = 'http_error';
  }

  return err;
}

async function handleResponse(res) {
  let data;

  try {
    data = await res.json();
  } catch (e) {
    const err = new Error(`Máy chủ trả về dữ liệu không hợp lệ (HTTP ${res.status}).`);
    err.code = 'bad_response';
    err.status = res.status;
    throw err;
  }

  if (!res.ok || data.ok !== true) {
    // Cảnh báo rủi ro (HTTP 409): KHÔNG phải lỗi — trả về riêng để popup
    // hiện hai nút KHÔNG LƯU / VẪN LƯU.
    if (data && data.risk === true) {
      const err = new Error(data.risk_reason || 'Trang này có dấu hiệu rủi ro.');
      err.risk = true;
      err.riskReason = data.risk_reason || '';
      err.riskLevel = data.risk_level || 'low';
      err.signals = Array.isArray(data.signals) ? data.signals : [];
      err.host = data.host || '';
      throw err;
    }

    // V3 ĐỢT 4 / F12 — cảnh báo TRÙNG LẶP (HTTP 409): cũng KHÔNG phải lỗi,
    // người dùng được chọn VẪN LƯU (gửi lại với override_dup = true).
    if (data && data.duplicate === true && Array.isArray(data.duplicates)) {
      const err = new Error(data.error || 'Trang này trùng hoặc gần giống bài đã lưu.');
      err.duplicate = true;
      err.duplicateMessage = data.error || '';
      err.duplicates = data.duplicates;
      err.scanned = data.scanned || 0;
      throw err;
    }

    throw apiErrorFromResponse(res, data);
  }

  return data;
}

/* =========================================================================
   LƯU MỘT TRANG
   ====================================================================== */

async function savePage(url, libraryId, tags, overrideRisk, overrideDup) {
  return apiCall('save', {
    url,
    library_id: libraryId,
    tags: tags || '',
    // Chỉ bỏ qua CẢNH BÁO rủi ro khi người dùng đã bấm "VẪN LƯU".
    // Chốt an toàn của máy chủ không bao giờ bị bỏ qua.
    override_risk: overrideRisk === true,
    // V3 ĐỢT 4 / F12: bỏ qua cảnh báo trùng lặp (KHÔNG tự xoá/gộp bản lưu).
    override_dup: overrideDup === true
  });
}

/* =========================================================================
   TỰ ĐỘNG LẮU THEO DOMAIN ĐƯỢC PHÉP
   ====================================================================== */

/** Rút domain (không www.) ra khỏi URL. */
function hostOf(url) {
  try {
    const u = new URL(url);
    return u.hostname.replace(/^www\./i, '').toLowerCase();
  } catch (e) {
    return '';
  }
}

/**
 * Domain này có được theo dõi không? (theo quy tắc: domain cha cũng khớp)
 */
function findTrackedDomain(url, domains) {
  const host = hostOf(url);
  if (!host) return null;

  for (const d of domains) {
    const tracked = String(d.domain || '').replace(/^www\./i, '').toLowerCase();
    if (!tracked) continue;
    if (host === tracked || host.endsWith('.' + tracked)) {
      return d;
    }
  }

  return null;
}

/** Trang này có nên bị bỏ qua (localhost, nội bộ, trang nhạy cảm)? */
function isBlockedUrl(url) {
  let u;
  try {
    u = new URL(url);
  } catch (e) {
    return true;
  }

  const p = u.protocol;
  if (p !== 'http:' && p !== 'https:') return true;

  const host = u.hostname.toLowerCase();

  // localhost / loopback / nội bộ / trang cấu hình trình duyệt
  if (host === 'localhost' || host === '127.0.0.1' || host === '::1' || host === '0.0.0.0') return true;
  if (host.endsWith('.localhost') || host.endsWith('.local') || host.endsWith('.internal')) return true;
  if (/^10\./.test(host) || /^192\.168\./.test(host)) return true;
  if (/^172\.(1[6-9]|2\d|3[01])\./.test(host)) return true;
  if (host === 'accounts.google.com' || host === 'login.microsoftonline.com') return true;

  // Trang đăng nhập / thanh toán
  const p2 = u.pathname.toLowerCase();
  if (p2.includes('/login') || p2.includes('/signin') || p2.includes('/logout')
      || p2.includes('/checkout') || p2.includes('/password')) return true;

  // Tệp tải về / dữ liệu nhạy cảm
  if (/\.(pdf|zip|rar|7z|exe|dmg|apk|mp4|mkv|iso)(\?|$)/i.test(p2)) return true;

  return false;
}

/**
 * URL này đã được lưu gần đây chưa? (chống trùng + chống tạo phiên bản thừa)
 *
 * CHỈ đánh dấu khi lưu THÀNH CÔNG — nếu máy chủ đang tắt thì lần sau vẫn
 * phải thử lại được.
 */
async function wasSavedRecently(url, minutes) {
  const key = `saved:${url}`;
  const now = Date.now();

  const data = await chrome.storage.local.get([key]);
  const last = data[key];

  return typeof last === 'number' && now - last < minutes * 60 * 1000;
}

/** Ghi nhận "URL này vừa được lưu" để không lưu lại trong thời gian tới. */
async function markSaved(url) {
  await chrome.storage.local.set({ [`saved:${url}`]: Date.now() });
}

/**
 * Xử lý một tab vừa điều hướng xong: tự lưu nếu thuộc domain được theo dõi.
 *
 * BẢN SỬA BUG-02:
 *   - Dùng khoá lưu TRONG `chrome.storage.local` (sống qua cả lần khởi động
 *     lại của service worker) thay vì chỉ dựa vào bộ nhớ.
 *   - Đọc cấu hình MỘT LẦN ở đầu và dùng cho cả vòng lặp -> trạng thái
 *     bật/tắt không bị "lệch" giữa các bước.
 */
async function maybeAutoSave(tabId, url) {
  const s = await getSettings();

  // Mặc định TẮT -> không gửi request nào ra ngoài.
  if (!s.autoSave) return;
  if (!s.token) return;
  if (!url) return;
  if (isBlockedUrl(url)) return;

  const tracked = findTrackedDomain(url, s.domains);
  if (!tracked) return;

  const libraryId = tracked.libraryId || s.defaultLibraryId;
  if (!libraryId) return;

  // Chống trùng URL + giới hạn tần suất.
  if (await wasSavedRecently(url, DEFAULT_INTERVAL_MIN)) return;

  // Chống hai lần lưu chồng nhau (ví dụ tab điều hướng nhiều lần).
  const lockKey = SAVE_LOCK_PREFIX + url;

  if (!(await acquireSaveLock(lockKey))) {
    return;
  }

  try {
    const result = await savePage(url, Number(libraryId), tracked.tags || '');

    await markSaved(url);
    await pushHistory({
      url,
      title: result.title || url,
      ok: true,
      message: result.message || 'Đã lưu',
      at: Date.now()
    });
  } catch (e) {
    // Gặp CẢNH BÁO RỦI RO -> KHÔNG tự động lưu và KHÔNG tự vượt cảnh báo.
    // Chỉ ghi nhận lịch sử + thông báo cho người dùng xác nhận thủ công.
    if (e && e.risk === true) {
      await pushHistory({
        url,
        title: url,
        ok: false,
        risk: true,
        message: 'Cần xác nhận: ' + (e.riskReason || 'trang có dấu hiệu rủi ro.'),
        at: Date.now()
      });

      notifyRisk(url, e.riskReason || 'Trang có dấu hiệu rủi ro.');

      return;
    }

    // V3 ĐỢT 4 / F12 — cảnh báo trùng lặp: tự động lưu KHÔNG được vượt qua.
    if (e && e.duplicate === true) {
      await pushHistory({
        url,
        title: url,
        ok: false,
        message: 'Cần xác nhận: ' + (e.duplicateMessage || 'trang trùng hoặc gần giống bài đã lưu.'),
        at: Date.now()
      });

      return;
    }

    await pushHistory({
      url,
      title: url,
      ok: false,
      message: String(e.message || e),
      at: Date.now()
    });
  } finally {
    await releaseSaveLock(lockKey);
  }
}

/**
 * Giữ khoá lưu cho một URL (dùng storage, sống qua lần khởi động lại của SW).
 *
 * @return true nếu giành được khoá.
 */
async function acquireSaveLock(lockKey) {
  const now = Date.now();

  const data = await chrome.storage.local.get([lockKey]);
  const lock = data[lockKey];

  // Khoá còn hiệu lực -> không giành được.
  if (lock && typeof lock.ts === 'number' && now - lock.ts < 60 * 1000) {
    return false;
  }

  await chrome.storage.local.set({ [lockKey]: { ts: now } });

  // Đọc lại để kiểm tra: nếu có tiến trình khác ghi đè sau mình thì mốc
  // thời gian sẽ khác — dùng mốc mình vừa ghi làm chuẩn.
  const check = await chrome.storage.local.get([lockKey]);
  const current = check[lockKey];

  return Boolean(current && current.ts === now);
}

/** Nhả khoá lưu. */
async function releaseSaveLock(lockKey) {
  await chrome.storage.local.remove([lockKey]);
}

/**
 * Báo cho người dùng biết một trang cần xác nhận thủ công.
 *
 * Chrome đang yêu cầu quyền "notifications"; nếu chưa được cấp thì bỏ qua —
 * người dùng vẫn thấy trong Lịch sử lưu của popup.
 */
function notifyRisk(url, reason) {
  if (!chrome.notifications) return;

  try {
    chrome.notifications.create('wa-risk-' + Date.now(), {
      type: 'basic',
      iconUrl: 'icons/icon128.png',
      title: 'WebArchive — cần xác nhận',
      message: reason + ' Mở popup để chọn VẪN LƯU hoặc KHÔNG LƯU.',
      contextMessage: url
    });
  } catch (e) {
    // Không có quyền thông báo -> bỏ qua.
  }
}

/* =========================================================================
   LỊCH SỬ LƯU GẦN NHẤT (popup hiển thị)
   ====================================================================== */

async function pushHistory(entry) {
  const data = await chrome.storage.local.get(['history']);
  const list = Array.isArray(data.history) ? data.history : [];
  list.unshift(entry);
  await chrome.storage.local.set({ history: list.slice(0, MAX_HISTORY) });
}

/* =========================================================================
   SỰ KIỆN
   ====================================================================== */

chrome.tabs.onUpdated.addListener((tabId, changeInfo) => {
  if (changeInfo.status !== 'complete') return;
  if (!changeInfo.url) return;

  maybeAutoSave(tabId, changeInfo.url).catch(() => {});
});

/**
 * Dự phòng: một số bản Chrome KHÔNG gửi changeInfo.url cho tab không có host
 * permission. Khi đó hỏi lại tab để lấy URL — nhưng chỉ khi extension ĐÃ được
 * cấp quyền cho domain đó (nếu không, chrome.tabs.get trả về tab không có
 * trường url).
 */
chrome.tabs.onUpdated.addListener(async (tabId, changeInfo) => {
  if (changeInfo.status !== 'complete') return;
  if (changeInfo.url) return;

  try {
    const tab = await chrome.tabs.get(tabId);
    if (!tab || !tab.url) return;

    await maybeAutoSave(tabId, tab.url);
  } catch (e) {
    // Bỏ qua — không có URL thì không làm gì được.
  }
});

chrome.runtime.onInstalled.addListener(async (details) => {
  if (details.reason === 'install') {
    await chrome.storage.local.set({
      serverUrl: DEFAULT_SERVER,
      token: '',
      autoSave: false,
      defaultLibraryId: null,
      lastLibraryId: null,
      domains: [],
      history: []
    });
  }
});

// Cho phép popup gọi các hàm này qua chrome.runtime.sendMessage.
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    try {
      switch (msg && msg.type) {
        case 'getSettings':
          sendResponse({ ok: true, settings: await getSettings() });
          break;

        case 'saveSettings':
          sendResponse({ ok: true, settings: await saveSettings(msg.settings || {}) });
          break;

        case 'whoami':
          sendResponse({ ok: true, data: await apiCall('whoami') });
          break;

        case 'createLibrary':
          sendResponse({
            ok: true,
            data: await apiCall('library_create', {
              name: msg.name,
              description: msg.description || '',
              visibility: msg.visibility || 'private',
              color: msg.color || 'a',
              icon: msg.icon || 'folder_special'
            })
          });
          break;

        case 'savePage':
          sendResponse({
            ok: true,
            data: await apiCall('save', {
              url: msg.url,
              library_id: msg.libraryId,
              tags: msg.tags || '',
              override_risk: msg.overrideRisk === true,
              // V3 ĐỢT 4 / F12: bỏ qua cảnh báo trùng lặp.
              override_dup: msg.overrideDup === true
            })
          });
          break;

        case 'getHistory': {
          const data = await chrome.storage.local.get(['history']);
          sendResponse({ ok: true, history: Array.isArray(data.history) ? data.history : [] });
          break;
        }

        case 'clearHistory':
          await chrome.storage.local.set({ history: [] });
          sendResponse({ ok: true });
          break;

        default:
          sendResponse({ ok: false, error: 'Hành động không hợp lệ.' });
      }
    } catch (e) {
      // Cảnh báo rủi ro được trả về riêng để popup hiện hai nút.
      if (e && e.risk === true) {
        sendResponse({
          ok: false,
          risk: true,
          error: String(e.riskReason || e.message || e),
          risk_reason: e.riskReason || '',
          risk_level: e.riskLevel || 'low',
          signals: e.signals || [],
          host: e.host || ''
        });
        return;
      }

      // V3 ĐỢT 4 / F12 — cảnh báo trùng lặp (HTTP 409).
      if (e && e.duplicate === true) {
        sendResponse({
          ok: false,
          duplicate: true,
          error: String(e.duplicateMessage || e.message || e),
          duplicates: Array.isArray(e.duplicates) ? e.duplicates : [],
          scanned: e.scanned || 0
        });
        return;
      }

      sendResponse({ ok: false, error: String((e && e.message) || e), code: (e && e.code) || 'unknown' });
    }
  })();

  return true; // giữ kênh mở để gửi phản hồi bất đồng bộ
});
