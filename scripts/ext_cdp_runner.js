/**
 * scripts/ext_cdp_runner.js
 * ---------------------------------------------------------------------------
 * BỘ ĐIỀU KHIỂN CHROME DEVTOOLS PROTOCOL CHO KIỂM THỬ EXTENSION.
 *
 * Vì sao cần Node?
 *   Từ Chrome 137, `--load-extension` bị bỏ qua trong headless. Chrome 158 vẫn
 *   hỗ trợ lệnh CDP `Extensions.loadUnpacked` (cần `--enable-unsafe-extension-
 *   debugging`). Lệnh này nạp THẬT extension từ thư mục dự án.
 *
 * Giao diện:
 *   - Khởi động Chrome headless + mở cổng HTTP nội bộ (mặc định 9452).
 *   - POST /run  {step, params} -> JSON kết quả.
 *
 * Các bước (step):
 *   load            -> nạp extension, trả extension id
 *   sw              -> service worker của extension đã đăng ký chưa
 *   manifest        -> đọc manifest.json qua chrome-extension://
 *   popup_dom       -> mở popup.html, kiểm tra DOM
 *   configure       -> lưu serverUrl/token vào chrome.storage.local, mở lại
 *                      popup và đọc trạng thái + danh sách thư viện
 *   create_library  -> tạo thư viện qua popup, đọc select sau khi tạo
 *   save_page       -> lưu một URL qua popup
 *   autosave_cycle  -> kiểm tra công tắc tự động lưu (mặc định OFF, bật/tắt)
 *   warning_state   -> kiểm tra hộp cảnh báo rủi ro / trùng lặp có đang hiện
 *
 * Dùng Node >= 22 (có sẵn WebSocket tích hợp + fetch).
 * ---------------------------------------------------------------------------
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const CHROME_PATH = process.argv[2] || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const EXT_DIR = process.argv[3] || path.join(__dirname, '..', 'chrome-extension');
const PORT_FILE = process.argv[4] || '';

const PROFILE = path.join(os.tmpdir(), `wa-ext-runner-${process.pid}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let chrome = null;
let browser = null;          // kết nối CDP cấp browser
let extensionId = '';
let popupCdp = null;         // kết nối CDP cấp page của popup.html
let cdpPort = 0;             // cổng DevTools thực tế (Chrome tự chọn)

/* --------------------------------------------------------------------------
 * Lớp CDP tối giản
 * ------------------------------------------------------------------------ */

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    ws.onmessage = (ev) => {
      let msg;
      try { msg = JSON.parse(ev.data); } catch { return; }
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) reject(new Error(JSON.stringify(msg.error)));
        else resolve(msg.result);
      }
    };
  }

  static async connect(url) {
    const ws = new WebSocket(url);
    await new Promise((resolve, reject) => {
      ws.onopen = resolve;
      ws.onerror = () => reject(new Error('Không kết nối được WebSocket CDP: ' + url));
    });
    return new Cdp(ws);
  }

  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          reject(new Error('Timeout gọi CDP: ' + method));
        }
      }, 30000);
    });
  }

  /** Đánh giá biểu thức JS và trả về giá trị (đã JSON hóa nếu cần). */
  async eval(expression) {
    const res = await this.send('Runtime.evaluate', {
      expression,
      returnByValue: true,
      awaitPromise: true,
      userGesture: true,
    });

    if (res.exceptionDetails) {
      throw new Error('Lỗi JS: ' + JSON.stringify(res.exceptionDetails).slice(0, 400));
    }

    const value = res.result ? res.result.value : undefined;
    return typeof value === 'string' ? value : JSON.stringify(value === undefined ? null : value);
  }
}

/* --------------------------------------------------------------------------
 * Tiện ích
 * ------------------------------------------------------------------------ */

async function httpJson(url, options = {}) {
  const res = await fetch(url, options);
  return await res.json();
}

async function listTargets() {
  try {
    const res = await fetch(`http://127.0.0.1:${cdpPort}/json/list`);
    const text = await res.text();

    if (!text.trim()) {
      console.error('[ext_cdp_runner] /json/list trả về rỗng (cổng ' + cdpPort + ')');
      return [];
    }

    return JSON.parse(text);
  } catch (e) {
    console.error('[ext_cdp_runner] listTargets lỗi: ' + (e && e.message ? e.message : e));
    return [];
  }
}

async function openTab(url) {
  try {
    await fetch(`http://127.0.0.1:${cdpPort}/json/new?${encodeURIComponent(url)}`, { method: 'PUT' });
  } catch (e) {
    throw new Error('Không mở được tab: ' + e.message);
  }
}

/** Đóng mọi tab đang mở (trừ about:blank) để tránh tích tụ. */
async function closeTabs() {
  const targets = await listTargets();

  for (const t of targets) {
    if (t.type === 'page' && t.url !== 'about:blank') {
      try { await fetch(`http://127.0.0.1:${cdpPort}/json/close/${t.id}`); } catch { /* bỏ qua */ }
    }
  }
}

/**
 * Mở popup.html và trả về kết nối CDP tới nó.
 */
async function openPopup() {
  await closeTabs();
  await openTab(`chrome-extension://${extensionId}/popup.html`);
  await sleep(2500);

  const targets = await listTargets();
  const popup = targets.find((t) => t.url.includes(`${extensionId}/popup.html`));

  if (!popup || !popup.webSocketDebuggerUrl) {
    throw new Error('Không tìm thấy tab popup.html trong Chrome.');
  }

  if (popupCdp) {
    try { popupCdp.ws.close(); } catch { /* bỏ qua */ }
  }

  popupCdp = await Cdp.connect(popup.webSocketDebuggerUrl);
  return popupCdp;
}

/* --------------------------------------------------------------------------
 * Các bước kiểm thử
 * ------------------------------------------------------------------------ */

const steps = {
  async load() {
    if (!extensionId) {
      const res = await browser.send('Extensions.loadUnpacked', { path: EXT_DIR });
      extensionId = res.id;
      await sleep(2500);
    }

    return { ok: !!extensionId, extension_id: extensionId };
  },

  async sw() {
    const targets = await listTargets();
    const sw = targets.find(
      (t) => t.type === 'service_worker' && t.url.includes(extensionId) && t.url.includes('background.js')
    );

    if (!sw) {
      const all = targets.filter((t) => t.type === 'service_worker').map((t) => t.url);
      return { ok: false, error: 'Không thấy service worker. Đang có: ' + (all.join(', ') || '(không có)') };
    }

    return { ok: true, url: sw.url, id: sw.id };
  },

  async manifest() {
    await openTab(`chrome-extension://${extensionId}/manifest.json`);
    await sleep(1500);

    const targets = await listTargets();
    const tab = targets.find((t) => t.url.includes(`${extensionId}/manifest.json`));

    if (!tab || !tab.webSocketDebuggerUrl) {
      return { ok: false, error: 'Không mở được manifest.json.' };
    }

    const cdp = await Cdp.connect(tab.webSocketDebuggerUrl);
    const text = await cdp.eval('document.body ? document.body.innerText : ""');

    let parsed = null;
    try { parsed = JSON.parse(text); } catch { /* không parse được */ }

    await closeTabs();

    if (!parsed) {
      return { ok: false, error: 'manifest.json không phải JSON hợp lệ.' };
    }

    return {
      ok: parsed.manifest_version === 3,
      manifest_version: parsed.manifest_version,
      name: parsed.name,
      version: parsed.version,
    };
  },

  async popup_dom() {
    const cdp = await openPopup();

    const title = await cdp.eval('document.title');
    const domLength = await cdp.eval('document.body ? document.body.innerHTML.length : 0');
    const hasSave = await cdp.eval('!!document.getElementById("btnSave")');
    const hasLibrary = await cdp.eval('!!document.getElementById("librarySelect")');
    const hasAutoSave = await cdp.eval('!!document.getElementById("autoSave")');

    return {
      ok: hasSave === 'true' && hasLibrary === 'true' && Number(domLength) > 500,
      title,
      dom_length: domLength,
      has_save: hasSave,
      has_library: hasLibrary,
      has_autosave: hasAutoSave,
    };
  },

  /**
   * Lưu cấu hình vào chrome.storage.local rồi mở lại popup để đọc trạng thái.
   */
  async configure(params) {
    const cdp = await openPopup();

    const serverUrl = String(params.serverUrl || '');
    const token = String(params.token || '');

    await cdp.eval(
      `chrome.storage.local.set(${JSON.stringify({ serverUrl, token, autoSave: false })}).then(() => 'stored')`
    );

    // Mở lại popup để chạy lại init() với cấu hình mới.
    await openPopup();
    await sleep(3000);

    const cdp2 = await openPopup();
    await sleep(2500);

    const libraryCount = await cdp2.eval(
      'document.getElementById("librarySelect") ? document.getElementById("librarySelect").options.length : -1'
    );
    const status = await cdp2.eval(
      'document.getElementById("status") ? document.getElementById("status").textContent : ""'
    );
    const message = await cdp2.eval(
      'document.getElementById("result") && !document.getElementById("result").hidden '
      + '? document.getElementById("result").textContent : ""'
    );
    const firstLibrary = await cdp2.eval(
      'document.getElementById("librarySelect") && document.getElementById("librarySelect").options[1] '
      + '? document.getElementById("librarySelect").options[1].textContent : ""'
    );

    const count = Number(libraryCount);

    return {
      ok: count > 1,
      library_count: count,
      status,
      message,
      first_library: firstLibrary,
    };
  },

  /**
   * Tạo thư viện mới giống hệt người dùng thật:
   *   bấm nút "+" -> gõ tên -> bấm "Tạo thư viện" -> đọc lại ô chọn thư viện.
   *
   * Việc bấm nút thật (không gọi thẳng chrome.runtime.sendMessage) là BẮT BUỘC
   * để chứng minh BUG-04 đã được sửa: popup phải TỰ CHỌN thư viện vừa tạo mà
   * không cần người dùng đóng/mở lại Extension.
   */
  async create_library(params) {
    const cdp = await openPopup();
    await sleep(1500);

    const name = String(params.name || 'EXT TEST');

    // 1) Bấm nút "+" để mở ô tạo thư viện nhanh.
    await cdp.eval("(function(){ var b = document.getElementById('btnNewLib'); if (b) { b.click(); return 'clicked'; } return 'no-button'; })()");
    await sleep(400);

    // 2) Gõ tên thư viện.
    await cdp.eval(
      "(function(){ var i = document.getElementById('quickLibName'); if (!i) { return 'no-input'; }"
      + " i.focus(); i.value = " + JSON.stringify(name) + ';'
      + " i.dispatchEvent(new Event('input', {bubbles: true}));"
      + " i.dispatchEvent(new Event('change', {bubbles: true}));"
      + " return 'typed'; })()"
    );
    await sleep(200);

    // 3) Bấm "Tạo thư viện".
    await cdp.eval("(function(){ var b = document.getElementById('btnQuickCreate'); if (b) { b.click(); return 'clicked'; } return 'no-button'; })()");

    // 4) Chờ popup xử lý xong (gọi API + đổ danh sách + chọn thư viện mới).
    let selected = '';
    let message = '';

    for (let i = 0; i < 20; i++) {
      await sleep(700);

      selected = await cdp.eval(
        'document.getElementById("librarySelect") ? document.getElementById("librarySelect").value : ""'
      );
      message = await cdp.eval(
        'document.getElementById("result") && !document.getElementById("result").hidden '
        + '? document.getElementById("result").textContent : ""'
      );

      if (selected && selected !== '') {
        break;
      }
    }

    return { ok: !!selected, library_id: selected, selected, message };
  },

  /**
   * Lưu một URL thật qua NÚT "Lưu trang" của popup (đi qua background.js
   * -> API -> CSDL), đúng như người dùng thật bấm.
   */
  async save_page(params) {
    const cdp = await openPopup();
    await sleep(1500);

    const url = String(params.url || '');
    const libraryId = String(params.libraryId || '');
    const tags = String(params.tags || '');

    // Chọn thư viện đích trước khi lưu.
    await cdp.eval(
      "(function(){ var s = document.getElementById('librarySelect'); if (!s) { return 'no-select'; }"
      + " s.value = " + JSON.stringify(libraryId) + ';'
      + " s.dispatchEvent(new Event('change', {bubbles: true}));"
      + " return s.value; })()"
    );

    // Mô phỏng "tab hiện tại" của người dùng là trang cần lưu.
    // Trong headless, tab hiện tại chính là tab popup nên URL là
    // chrome-extension://... -> popup sẽ tự khoá nút "Lưu trang". Ta đặt lại
    // state.tab và mở khoá nút, đúng như khi người dùng bấm vào icon trên
    // một trang http(s).
    const prepared = await cdp.eval(
      "(function(){"
      + " if (typeof state === 'undefined' || !state) { return 'no-state'; }"
      + " state.tab = { url: " + JSON.stringify(url) + ", title: 'Trang kiểm thử' };"
      + " var b = document.getElementById('btnSave');"
      + " if (b) { b.disabled = false; }"
      + " var l = document.getElementById('btnSaveLabel');"
      + " if (l) { l.textContent = 'Lưu trang'; }"
      + " return 'prepared'; })()"
    );

    if (prepared !== 'prepared') {
      return { ok: false, error: 'Không chuẩn bị được tab hiện tại: ' + prepared };
    }

    // Bấm nút "Lưu trang".
    await cdp.eval("(function(){ var b = document.getElementById('btnSave'); if (b) { b.click(); return 'clicked'; } return 'no-button'; })()");

    // Chờ kết quả.
    let message = '';
    let snapshotId = 0;
    let riskShown = false;
    let dupShown = false;

    for (let i = 0; i < 25; i++) {
      await sleep(700);

      message = await cdp.eval(
        'document.getElementById("result") && !document.getElementById("result").hidden '
        + '? document.getElementById("result").textContent : ""'
      );

      const href = await cdp.eval(
        'document.getElementById("openReader") ? document.getElementById("openReader").getAttribute("data-snapshot-id") : ""'
      );

      const m = /^(\d+)$/.exec(String(href || '').trim());

      if (m) {
        snapshotId = Number(m[1]);
        break;
      }

      // V3 ĐỢT 4 / F12 — nếu máy chủ báo TRÙNG LẶP thì popup hiện hộp thoại
      // KHÔNG LƯU / VẪN LƯU. Người dùng thật sẽ bấm "VẪN LƯU" — ta làm
      // đúng như vậy (không bỏ qua chính sách, chỉ xác nhận) để kiểm thử
      // đúng luồng và tránh rơi vào vòng lặp 409 (BUG-08).
      const dupVisible = await cdp.eval(
        'document.getElementById("dupBox") && !document.getElementById("dupBox").hidden'
      );

      if (dupVisible === 'true') {
        dupShown = true;
        await cdp.eval("(function(){ var b = document.getElementById('btnDupProceed'); if (b) { b.click(); return 'clicked'; } return 'no-button'; })()");
        continue;
      }

      const riskVisible = await cdp.eval(
        'document.getElementById("riskBox") && !document.getElementById("riskBox").hidden'
      );

      if (riskVisible === 'true') {
        riskShown = true;
      }

      if (message && /Không lưu được|thất bại|Thất bại|không thể/i.test(message)) {
        break;
      }
    }

    return {
      ok: snapshotId > 0,
      snapshot_id: snapshotId,
      message: message.slice(0, 300),
      risk_shown: riskShown,
      dup_shown: dupShown,
    };
  },

  /**
   * Kiểm tra công tắc tự động lưu: mặc định TẮT, bật/tắt/bật vẫn đúng.
   */
  async autosave_cycle() {
    const cdp = await openPopup();
    await sleep(1200);

    // Xoá khoá để bắt đầu từ trạng thái "chưa cấu hình".
    await cdp.eval("chrome.storage.local.remove(['autoSave']).then(() => 'cleared')");

    const defaultValue = await cdp.eval(
      'chrome.storage.local.get(["autoSave"]).then((d) => String(d.autoSave === true))'
    );

    // Bật -> tắt -> bật qua đúng đường lưu cấu hình của extension.
    const cycle = await cdp.eval(`(async () => {
      const send = (msg) => new Promise((resolve) => chrome.runtime.sendMessage(msg, resolve));
      await send({ type: 'saveSettings', settings: { autoSave: true } });
      const afterOn = await chrome.storage.local.get(['autoSave']);
      await send({ type: 'saveSettings', settings: { autoSave: false } });
      const afterOff = await chrome.storage.local.get(['autoSave']);
      await send({ type: 'saveSettings', settings: { autoSave: true } });
      const afterOnAgain = await chrome.storage.local.get(['autoSave']);
      return JSON.stringify({
        afterOn: afterOn.autoSave === true,
        afterOff: afterOff.autoSave === true,
        afterOnAgain: afterOnAgain.autoSave === true
      });
    })()`);

    let parsed = {};
    try { parsed = JSON.parse(cycle); } catch { /* không parse được */ }

    const ok = parsed.afterOn === true && parsed.afterOff === false && parsed.afterOnAgain === true;

    return {
      ok,
      default: defaultValue,
      after_cycle: cycle,
      stored: String(await cdp.eval(
        'chrome.storage.local.get(["autoSave"]).then((d) => String(d.autoSave === true))'
      )),
      error: ok ? '' : 'Chu kỳ bật/tắt không cho kết quả mong đợi: ' + cycle,
    };
  },

  /**
   * Kiểm tra hộp cảnh báo rủi ro / trùng lặp KHÔNG tự hiện khi mở popup.
   */
  async warning_state() {
    const cdp = await openPopup();
    await sleep(1500);

    const riskVisible = await cdp.eval(
      'document.getElementById("riskBox") ? !document.getElementById("riskBox").hidden : false'
    );
    const dupVisible = await cdp.eval(
      'document.getElementById("dupBox") ? !document.getElementById("dupBox").hidden : false'
    );

    return {
      ok: riskVisible === 'false' && dupVisible === 'false',
      risk_visible: riskVisible,
      dup_visible: dupVisible,
    };
  },
};

/* --------------------------------------------------------------------------
 * HTTP server nội bộ
 * ------------------------------------------------------------------------ */

const server = http.createServer((req, res) => {
  if (req.method !== 'POST' || !req.url.startsWith('/run')) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: false, error: 'Not found' }));
    return;
  }

  let body = '';
  req.on('data', (c) => { body += c; });
  req.on('end', async () => {
    let payload = {};
    try { payload = JSON.parse(body || '{}'); } catch { /* không parse được */ }

    const step = String(payload.step || '');
    const fn = steps[step];

    if (!fn) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'Bước không hợp lệ: ' + step }));
      return;
    }

    try {
      const result = await fn(payload.params || {});
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(result));
    } catch (e) {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify({ ok: false, error: String(e && e.message ? e.message : e) }));
    }
  });
});

/* --------------------------------------------------------------------------
 * Khởi động
 * ------------------------------------------------------------------------ */

async function main() {
  // Cổng DevTools: để Chrome TỰ CHỌN cổng trống rồi đọc lại từ tệp
  // DevToolsActivePort trong thư mục profile. Cách này tránh đụng cổng còn
  // sót lại của một lần chạy trước (rất hay xảy ra trên Windows).
  chrome = spawn(CHROME_PATH, [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--enable-unsafe-extension-debugging',
    `--user-data-dir=${PROFILE}`,
    '--remote-debugging-port=0',
    '--remote-allow-origins=*',
    'about:blank',
  ], { stdio: 'ignore' });

  // Chờ DevTools sẵn sàng.
  let version = null;

  for (let i = 0; i < 120; i++) {
    const portFile = path.join(PROFILE, 'DevToolsActivePort');

    if (fs.existsSync(portFile)) {
      const raw = fs.readFileSync(portFile, 'utf8').split(/\r?\n/);
      const port = Number((raw[0] || '').trim());

      if (port > 0) {
        cdpPort = port;

        try {
          version = await httpJson(`http://127.0.0.1:${cdpPort}/json/version`);
          break;
        } catch {
          /* thử lại vòng sau */
        }
      }
    }

    await sleep(500);
  }

  if (!version) {
    throw new Error('Chrome DevTools Protocol không khởi động được.');
  }

  console.log('[ext_cdp_runner] DevTools sẵn sàng trên cổng ' + cdpPort);

  browser = await Cdp.connect(version.webSocketDebuggerUrl);

  // Lắng nghe trên cổng trống và ghi cổng thật ra tệp để PHP biết.
  server.listen(0, '127.0.0.1', () => {
    const address = server.address();
    const realPort = address && typeof address === 'object' ? address.port : 0;

    if (PORT_FILE) {
      try { fs.writeFileSync(PORT_FILE, String(realPort)); } catch { /* bỏ qua */ }
    }

    console.log('[ext_cdp_runner] sẵn sàng trên cổng ' + realPort
      + ' (DevTools cổng ' + cdpPort + ')');
  });
}

function shutdown() {
  try { server.close(); } catch { /* bỏ qua */ }
  if (chrome) {
    try { chrome.kill('SIGKILL'); } catch { /* bỏ qua */ }
  }
  try { fs.rmSync(PROFILE, { recursive: true, force: true }); } catch { /* bỏ qua */ }
}

process.on('exit', shutdown);
process.on('SIGINT', () => { shutdown(); process.exit(0); });
process.on('SIGTERM', () => { shutdown(); process.exit(0); });

main().catch((e) => {
  console.error('ext_cdp_runner lỗi: ' + (e && e.message ? e.message : e));
  shutdown();
  process.exit(1);
});
