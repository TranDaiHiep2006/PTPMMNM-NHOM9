# P-UI — WebArchive (Topic 16) UI phase: guest landing, auth shell, workspace, reader, admin

## Role
You are a senior PHP developer. This phase builds the COMPLETE user interface of the WebArchive app plus a working session-based login shell. No MySQL and no real web capture in this phase. All UI text must be Vietnamese.

## Project context
- Working directory: D:\PTPMMNM-NHOM9 (Windows). Git remote: https://github.com/TranDaiHiep2006/PTPMMNM-NHOM9.git (currently empty — initialize and push to main in this phase).
- Product: Topic 16 — a web app that saves the CONTENT of a web page (snapshot) to reread and search later, organized in libraries with tags and per-library permissions. Full spec summary goes into PROJECT_CONTEXT.md (create it in this phase): 5 functions (libraries/tags/permissions + approved-URL intake; HTML fetch + title/article extraction with distinct errors; clean reread version with images saved locally; self-built search over saved content with snippets tied to the correct snapshot version; refetch keeps old version + notes, permission-checked export), 2 core technical requirements (never save error pages as valid articles and always read from the saved snapshot; search must be based on saved content, snapshots must block scripts and uncontrolled external resources, every fetch target including redirects must be checked against an allowlist). Later phases implement the engine and MySQL; THIS phase is UI + auth shell only.
- Stack: pure PHP 8+ (no framework, no build step), one local CSS file, MySQL arrives in a later phase.

## Design source of truth (read these FIRST, before writing any code)
- docs/design-reference/stitch_webarchive_saas_ui_system/webarchive_workspace/DESIGN.md — colors, typography, spacing, components.
- The 4 screen folders next to it: trang_ch (landing), th_vi_n_c_a_t_i (my libraries), chi_ti_t_th_vi_n (library detail), tr_nh_c_ngo_i_tuy_n (offline reader) — each has code.html and screen.png. Match layout and content closely, in Vietnamese.
- KNOWN DEFECT in the reference, you MUST fix it: the landing page mock uses an authenticated header (user chip, Dang xuat, workspace nav). The landing page is for GUESTS. Never mix the two headers.

## Tech constraints
- Port the DESIGN.md tokens into ONE local stylesheet public/assets/css/app.css (CSS variables for the palette: primary #152F4A, action #315E91, teal #289C91, workspace canvas #F5F7F9, reader ivory #FAF8F4, border #E2E8F0). Do NOT use the Tailwind CDN or any CSS framework in the output.
- Fonts: Inter (UI) and Merriweather (reader only) via Google Fonts, with system-font fallbacks so the demo still works offline.
- Icons: Material Symbols via Google Fonts like the reference, always paired with a Vietnamese text label so nothing breaks if the font fails.
- lang="vi", UTF-8 everywhere, correct Vietnamese diacritics (never strip accents).
- Two shared layouts only: templates/header_guest.php + footer, and templates/header_app.php (app header + sidebar). Every page uses one of them; do not duplicate header markup per page.
- Responsive: app sidebar collapses on narrow screens; reader column max 780px.
- All dynamic-looking data comes from ONE file src/sample_data.php (libraries, articles, tags, members, users, allowlist, stats) so the DB phase can replace it. Keep every number consistent across pages (same library counts, article counts, storage figures as the reference screenshots).

## Auth shell (working, demo data)
- src/auth.php: session start, require_login(), require_admin(), current_user().
- config/demo_users.php: two demo accounts with password_hash() values, clearly commented as UI-phase placeholders to be replaced by MySQL: user@demo.vn / demo123 (role: user, display name Le Minh Tuan) and admin@demo.vn / admin123 (role: admin).
- Login flow: guest clicks Dang nhap -> login.php form (email, password, Vietnamese validation errors, link to register) -> on success set session and redirect to libraries.php; on failure show a Vietnamese error on the form. Register page: full form (name, email, password, confirm) with Vietnamese validation and a success state that redirects to login (no persistence needed in this phase; state this limitation in the REPORT). Logout clears the session and returns to the landing page.
- Route guard: every workspace, reader, search and admin page redirects guests to login.php. Admin pages additionally require the admin account; a normal user opening them gets a Vietnamese "khong co quyen truy cap" page.

## Pages to build (public/ is the web root)
1. index.php — GUEST landing, closely following the reference landing content (hero, 3 feature blocks, 3-step flow, reader showcase, CTA) but with the corrected GUEST header.
2. login.php — NEW, in design system: centered card on workspace canvas, brand panel, Vietnamese labels.
3. register.php — NEW, same visual family as login.
4. libraries.php — "Thu vien cua toi" exactly like reference image 2: sidebar, stats row, filter tabs (Tat ca / Thu vien ca nhan / Duoc chia se), library cards with role badge (Chu so huu / Bien tap vien), tags, counts, "Mo thu vien" actions, create-library button (UI only).
5. library.php — library detail like reference image 3: breadcrumb, stats, members with roles, tags, article cards, pagination, and the save-URL panel (see mock capture below) showing the SUCCESS state.
6. save.php — "Luu trang Web" full page: URL input panel plus a states showcase driven by the mock rules below: success, URL not in allowlist, origin returned 404, timeout. Vietnamese messages, distinct styling per state.
7. reader.php — offline reader like reference image 4: ivory surface, 780px Merriweather column, toolbar with working A- / A+ font-size toggle (small vanilla JS), reading progress, provenance box (original URL, saved-at time, snapshot version), one full sample Vietnamese article (headings, blockquote, code block, image placeholder as a local asset, sanitization summary table).
8. search.php — NEW search results page: query input, filters by tag and by source domain, mock results whose snippets highlight the keyword with <mark> and show which library and snapshot version each hit belongs to (this demonstrates spec Function 4's UI).
9. Admin (NEW, admin account only), sharing the app layout plus an "Quan tri he thong" section in the sidebar:
   - admin/index.php — system overview: total users, libraries, snapshots, local assets, failed captures; recent activity list.
   - admin/users.php — user table: name, email, role badge (Quan tri vien / Nguoi dung), status (Hoat dong / Bi khoa), library count, actions (UI only).
   - admin/allowlist.php — approved-domains table (domain, added by, date, status) with an add-domain form (UI only) and a note explaining that captures and redirects are checked against this list.
   - admin/libraries.php — all libraries table with owner, visibility, article counts.

## Mock capture rules (UI simulation — implement in src/mock_capture.php, comment it clearly as UI-phase simulation)
- URL whose domain is in the sample allowlist and path does not contain "404" or "timeout": SUCCESS state (reuse the reference success message pattern).
- Domain not in allowlist: error "URL khong thuoc danh sach duoc duyet".
- Path contains "404": error "Trang goc tra ve 404".
- Path contains "timeout": error "Het thoi gian cho khi tai trang".
The save panel on library.php always shows SUCCESS (as in the reference); save.php exercises all four states via these rules.

## Deliverables and acceptance
- PROJECT_CONTEXT.md at repo root (spec summary above + page map + demo accounts + how later phases plug in).
- README.md: how to run on Windows (php -S localhost:8080 -t public from the project root), demo accounts table, page map, explicit scope note "UI phase — data is sample data, capture/search are simulated".
- .gitignore covering .local, .env, *.log. Do NOT commit docs/design-reference screen.png files if they make the repo heavy — commit DESIGN.md and code.html references only if small; otherwise list the folder in .gitignore and say so in the REPORT.
- php -l passes on every PHP file. Every page listed above opens with no PHP warnings. Guest -> workspace redirect works. Login with both demo accounts works; admin account sees the admin section, user account does not.
- Commit everything and push to origin main. If the remote is missing, add it from the URL above.

## Out of scope (do NOT build in this phase)
No MySQL, no real HTTP fetching, no real extraction/sanitization engine, no real search index, no file upload, no email. Do not invent extra pages beyond this list.

## REPORT (mandatory, end of your run)
Files created (grouped by page/area) / How to run (exact command) / Demo accounts / Page-by-page verification (which pages you opened or lint-checked and the result) / Guest-vs-user-vs-admin header check result / Known UI-phase limitations / Git commit hash and push result / Anything blocked or guessed.
