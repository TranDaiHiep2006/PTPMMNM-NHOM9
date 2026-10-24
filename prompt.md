Phase P-FINAL-2 — Landing + workspace polish (P-FIX-UI-2) and BTL compliance pass (code/project parts only)

Repo: D:\PTPMMNM-NHOM9 (read PROJECT_CONTEXT.md first). Current HEAD: 255e253 (after P-FIX-UI). The app runs against the existing local database. Work in stages, in order, and commit + push each stage separately with a clear message. Update the phase status in PROJECT_CONTEXT.md as you go.

Hard constraints for all stages:
- No setup_db.php run by you (it needs the admin password and wipes the live database). Stage C is gated on the user confirming they ran it themselves.
- Never delete anything under public/uploads/ manually.
- Never invent member names, numbers, or results. Every claimed number must come with its raw command output.
- Do NOT produce or edit any Word document, written report document, or presentation slides. This phase covers code and project artifacts only.
- Keep all Vietnamese UI copy unchanged except where a stage explicitly says otherwise.

Stage A — UI polish from the user's review (24/10, landing + workspace screenshots):

A1. Hero mock: the light-blue "illustration" rectangle inside the hero browser mock reads as a broken/missing image. Replace it with a real image (reuse one of the sample PNGs under tests/samples/) or a designed placeholder block with real content (small title + faded text lines + a small inner image frame). If no suitable image exists, remove the rectangle and let the body text take the space.
A2. Showcase diagram ("Không gian tập trung" section): the connector lines between the boxes (Web source, HTML, images, MySQL snapshot, resource files, offline reading) are messy diagonals. Redraw with orthogonal connectors only (horizontal/vertical, right angles) with clear arrowheads, or simplify to one linear 4-step flow with one horizontal arrow per step.
A3. Final dark CTA panel: content is crammed top-left while the right half and bottom are empty. Either center the whole content block, or add a right-hand block of real metrics (libraries count, saved pages count, successful-snapshot ratio) computed from the database.
A4. Copy fixes on the landing page: "HTML sạch đã độc tách" becomes "HTML sạch đã tách"; "thông tin phẫn mãnh" becomes "thông tin dồn dập" (or drop that quote); the "408 B Snapshot" / "Đã nén ZIP (408 B)" figure for an article with 3 images cannot be right if it includes the images — first verify the size formula, then either label it clearly as HTML-only ("HTML 408 B") or fix the formula to sum HTML + image bytes; the footer "© 2026 WebArchive Inc." drops the fake "Inc." and becomes "© 2026 WebArchive" (a course-project credit line is acceptable, a fake company suffix is not).
A5. Section header alignment: the hero stays centered; every content section header becomes left-aligned (currently the hero and the "3 steps" section are centered while the others are left-aligned).
A6. Feature-card footers: the three cards under "Được thiết kế cho người nghiên cứu sâu" use three different footer patterns. Unify to a single pattern: a small label plus one real metric line computed from the database.
A7. Workspace header: the logged-in top bar is still the guest header with a user cluster bolted on. Restyle the user cluster: remove the pink "Đăng xuất" pill (it is the only pink element on the page and clashes with the navy/teal palette); make logout a ghost navy button or an avatar dropdown menu containing "Đăng xuất"; differentiate the workspace header from the guest header (denser, with a workspace context label) so it reads as an in-app header.
A8. Workspace sidebar: the section header "THƯ VIỆN CỦA TÔI" duplicates the navigation item "Thư viện của tôi" directly above it. Rename the section header to "Danh sách thư viện". Visually subordinate the library list (smaller, indented one level) relative to the main navigation so the hierarchy reads as navigation, then content list, then tools. The bottom storage widget ("Kho lưu trữ 2,9 MB / 3 thư viện · 19 bài viết") duplicates the main stat strip; remove it or merge it into the stat strip — keep it only if it adds non-duplicated information.
A9. While doing A1-A8, audit the landing and workspace templates for any other invented figure or label of the same kind found in P-FIX-UI (item 6) and apply the same rule: compute it from the database or remove it, and list what you found in the report.

Stage B — BTL compliance audit, code/project parts only (based on the official assignment document for topic 16):

B1. README completeness (the submission guide requires topic name, members, functional scope, and install/use/test instructions). Verify and complete:
   - Topic name: present, keep.
   - Member list: ADD a members section with unmissable placeholders for the user to fill in (for example, lines clearly marked TO BE FILLED BY THE USER with name, student ID and responsibility). NEVER invent member names. State in your report that this section is incomplete until the user fills it.
   - Functional scope: list the 5 functions from the assignment, each mapped to the code that implements it (file + function/route).
   - Install/init/run/test instructions: run every command exactly as written in the README and fix any step that does not work as written.
   - Self-built vs reused attribution: add a section stating what the group built itself (capture engine, sanitizer, inverted index, versioning, permissions, metrics) and what is reused (PHP core extensions cURL/DOMDocument/mbstring, and anything the templates actually load such as fonts or icons — verify by reading the templates, do not guess).

B2. Secrets audit. Prove with command output that none of the following are tracked in git: config/db.local.php, any file under .local/, any Zone.Identifier file, any real password or credential string. Confirm .gitignore covers them. Report the exact commands and their outputs.

B3. Function, technical-requirement and mandatory-test audit (assignment sections 2, 3 and 6). For each of the 5 functions, the 2 technical requirements, and the 5 mandatory tests, verify against the code AND live HTTP evidence, and fix any gap:
   - Function 4 requires filtering by tag AND by source: confirm the source filter exists and works on the search page; add it if it is missing.
   - Error snapshots must never appear as readable articles in library lists, search results, or the reader.
   - Every redirect hop must be re-checked against the allowlist before fetching.
   - Export must enforce membership: a non-member gets an error page, never the file.
   - Demo scenario 1 (URL, check, tag, open, body-only keyword search) and scenario 2 (origin changed/lost, read old copy, refetch, compare source/time/notes per version, export) must both be walkable end to end on the current code.

B4. Deletion-safety audit (unresolved since P-FINAL, must be answered before any test run). Quote the exact cleanup/deletion code in scripts/run_tests.php and scripts/seed_samples.php — every call to wa_library_delete or DELETE FROM libraries — and state for each call site exactly how the target library id is chosen. If any call site can select a library other than the one that script itself created in the same run, that is a defect: fix it before running any test, and say so plainly in the report.

B5. Seed asset integrity (the known "N local images not found" problem). Seeded asset rows reference file paths that do not exist on disk, so the honest counters report many missing local images. Fix it in code/seed so that after a fresh setup the counters show approximately zero missing local images: align the seed asset paths with files the setup actually creates (for example, setup copies the sample PNGs to the corresponding uploads paths), or change the seed to reference only shipped files. Verify by listing the full mapping (seed asset row, source file, destination path) and confirming every destination has a source. Run php -l on every changed file. Do NOT verify this by running setup_db.php yourself; live verification happens in Stage C after the user runs setup.

B6. Regression. After all fixes, run scripts/run_tests.php fresh and require 6/6 PASS with exit code 0. If anything fails, fix it and re-run. Report each run's raw output.

B7. Release preparation. Do NOT move tag v1.0.0 (it points at the older commit 6795727). Recommend tagging v1.0.1 at the final commit after Stage C (the assignment document's example tag name btl-nop-cuoi-ky is acceptable if the user prefers it). Verify the release package by git archive of HEAD: extract it to a scratch directory and confirm it contains README, LICENSE, CONTRIBUTING, docs/, tests/, scripts/, src/, templates/, public/, and contains NO .local file, NO config/db.local.php, NO Zone.Identifier file, and NO credential string; run php -l over the extracted PHP files. In your report, give the exact steps for the user to create the Release on the GitHub website, download it, rename it to BTL_Nhom09.zip (group 9, following the guide's naming pattern), and the submission checklist (group name, topic name, Release link, full commit hash, ZIP file).

Stage C — Demo-data rebuild. GATED: do NOT start Stage C until the user tells you in this chat that they have run scripts/setup_db.php themselves in their own terminal. Then:
C1. Run scripts/reindex_search.php, then scripts/seed_samples.php (this recreates the "Kho dữ liệu mẫu" library).
C2. Re-capture the Wikipedia PHP article into library cntt-lap-trinh through the real engine (the allowlist already includes vi.wikipedia.org and thumb.wikimedia.org). Create a short 2-3 version chain with one note on a fixture article (boot the fixtures server on port 8090 as needed) so demo scenario 2 has material.
C3. Run scripts/run_tests.php once (6/6 required) and produce the corrected final-state section with raw query output: per-table counts, per-library page/snapshot counts, and the metrics counters (missing local images must be approximately zero after the B5 fix).
C4. Final REPORT: per-stage before/after, raw outputs for every claimed number, the full list of changed files, the deletion-safety conclusion from B4, the README member-placeholder status (names still to be filled by the user), and the exact Release steps for the user.

Deliverables: commit(s) pushed to origin/main with clear messages, one per stage. Return the standard REPORT with the evidence above. Raw command output is required wherever a count, a search result, or a verification is claimed.
