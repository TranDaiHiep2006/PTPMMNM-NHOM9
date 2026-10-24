Phase P-FIX-UI — Landing and workspace UI corrections (templates/CSS only)

Repo: D:\PTPMMNM-NHOM9 (read PROJECT_CONTEXT.md and docs/design-reference/DESIGN.md first). Current HEAD: 6795727 (tag v1.0.0). Work against the existing local database as-is.

Hard constraints:
- Changes are limited to templates/ and the page templates/CSS under public/ (plus reusing existing functions from src/queries.php if the sidebar needs data). Do NOT modify src/capture.php or src/search.php logic, do NOT change the schema, do NOT run scripts/setup_db.php, do NOT insert/delete any database rows, and never delete anything under public/uploads/.
- Keep all Vietnamese copy unchanged, except where an item below explicitly says otherwise.
- Follow the design tokens in DESIGN.md.

Issues to fix:

1. Guest header call-to-action. The public header for guests currently shows a single "Tạo tài khoản" button, so returning users have no login entry. Replace it with two actions: "Đăng nhập" (secondary/ghost style, links to login.php) and "Đăng ký" (primary style, links to register.php). Apply this in the shared guest header/layout so every guest-facing page is consistent.

2. Landing page layout defects (guest home page).
   a. In the section headed "Được thiết kế cho người nghiên cứu sâu", the three feature cards ("Thư viện ngăn nắp", "Lưu nội dung an toàn", "Đọc lại độc lập") render with misaligned, unstable text. Equalize card heights and align icon, label, title and body consistently; fix padding/spacing.
   b. In the section headed "Đọc theo cách mà mắt bạn xứng đáng được trải nghiệm", the right-hand illustration card ("Minh hoạ kiến trúc lưu trữ nội dung Web tĩnh" / "Chế độ đọc báo chí: Giấy ngà tự nhiên") is displaced and crowds the text column. Restore the intended two-column layout from the design reference: no overlap, balanced column widths.
   CSS/layout only; do not change the copy text. Verify at viewport widths around 1920px and 1366px.

3. Logged-in workspace header. In the workspace layout, the top header's right cluster (avatar, user identity, "Đăng xuất") is poorly arranged and vertically misaligned. Normalize it: avatar and display name aligned to the header's vertical center, logout as a clear, aligned text button, and a consistent header height/padding across workspace pages (dashboard, libraries, library detail, search, save page).

4. Inconsistent library-card buttons. On public/libraries.php, the "Mở thư viện" button renders as a solid dark button on some cards but as a white/outline button on another card ("Nghiên cứu công nghệ"). Find the cause and make the button style identical on every card (the solid primary style), regardless of the current user's role in that library.

5. Hardcoded library in the sidebar, dead link, wrong active state. The workspace sidebar contains a fixed item "CNTT & Lập Trình" linking to library.php?slug=cntt-lap-trinh. That library no longer exists in the current database, so the item leads to a "Không tìm thấy thư viện" page, and when another library is open the CNTT item is still highlighted as active. This is a general-purpose app; navigation must not hardcode one specific library. Fix:
   - Remove the hardcoded item entirely.
   - In its place, render a dynamic list of the CURRENT USER's libraries loaded from the database through the existing queries layer, in the same order as libraries.php, each item showing the library name and linking to library.php?slug=<slug>.
   - On library.php, mark the actually-open library (matched by slug) as the active sidebar item.
   - If the user has no libraries, render no extra entries and no placeholder.
   Acceptance: after the fix, the string "cntt-lap-trinh" and the label "CNTT & Lập Trình" must not appear anywhere under templates/ or public/. Occurrences in database/seed.sql and in docs are data/documentation and must stay untouched.

6. Invented numbers and labels. Several displayed values are decorative inventions left over from the design mock and do not match the real data; every number the UI shows must be computed from the database or the element must be removed:
   - Workspace sidebar widget "Kho lưu trữ 2.4 GB" (the real total stored size is only a few MB) and the "Bản quyền cá nhân / Sẵn sàng đồng bộ" block: compute the storage figure from the assets data (sum of file sizes) with the same formatting used on library cards, or remove these blocks.
   - Landing page badge "Phiên bản v2.4": the real release is v1.0.0; correct it or remove the version claim.
   - Library detail stat "THÀNH VIÊN": the subtext "1 Chủ sở hữu • 1 Biên tập viên • 1 Người xem" is static and contradicts the real member list (this library has exactly 1 member). Compute the per-role counts from library_members or drop the breakdown subtext.
   Audit the workspace and landing templates for any other invented figures of the same kind and apply the same rule (compute from DB or remove), listing what you found in the report.

Verification — real HTTP against the locally running app (php -S localhost:8080 -t public), logged in as user@demo.vn where needed:
- Guest home page and one more guest page: header shows both "Đăng nhập" and "Đăng ký"; the two landing sections render aligned at ~1920px and ~1366px (describe what you checked).
- Libraries page: all cards show the identical solid "Mở thư viện" button; the workspace header cluster is aligned.
- Sidebar: lists exactly the current user's real libraries from the database (currently: Tài liệu học tập, Nghiên cứu công nghệ, Kho dữ liệu mẫu), contains no dead link, and opening each library highlights that library's own sidebar entry.
- Report the raw grep output proving item 5 (search for cntt-lap-trinh and for CNTT in templates/ and public/), the full list of changed files, and a per-issue before/after summary.

Deliverables: commit(s) pushed to origin/main with clear commit messages; update the phase status in PROJECT_CONTEXT.md if it tracks phases. Return the standard REPORT with the evidence above. Raw command output is required wherever a count or a search result is claimed — do not present asserted numbers as measured ones.
