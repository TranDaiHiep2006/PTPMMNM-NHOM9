# P-READ — Version history, refetch, and per-snapshot notes (WebArchive, Topic 16)

Read PROJECT_CONTEXT.md and the current code first. Current state: P-CAPTURE
is done (HEAD 812476b). The capture engine (src/capture.php, capture_url) is
real and wired into save.php; reader.php renders one snapshot by numeric id.
This phase turns the reader into the version-aware workspace the spec asks
for. Pure PHP 8.3, no framework, no Composer packages. All user-facing text
stays Vietnamese.

Hard rules:
- Do NOT run scripts/setup_db.php and do NOT ask for the MySQL admin
  password; the app account in config/db.local.php is enough.
- No schema changes are expected (the notes and snapshots tables already
  carry everything). If you believe one is necessary, STOP and report.
- Prepared statements only; never reuse one named placeholder twice in a
  single statement.

## Deliverable 0 — one shared store path
Refactor the persistence half of the current save flow (find-or-create the
saved_pages row by library + source_url, insert the snapshot, download and
rewrite images, insert assets rows, record failures as error snapshots)
into ONE shared function in src/queries.php or src/capture.php — name it in
your report — called by BOTH public/save.php and the new refetch action
below. save.php behavior must stay identical after the refactor; the
verification includes a regression capture through save.php.

## Deliverable 1 — reader.php upgrades
- Access control first: resolve snapshot -> page -> library. The current
  user must be an admin or a member of that library (any role); otherwise
  render the existing Vietnamese 403 page. Snapshots whose fetch_status is
  not success keep returning 404.
- Provenance header wired to real data: article title; library name linking
  back to library.php?slug=...; source URL shown as text and as an external
  link; domain; the label "Phiên bản N" together with SNAP-<id>; fetched_at
  formatted for Vietnamese readers; HTTP status of that fetch.
- Version switcher: a list (or select) of ALL successful versions of the
  same page, version_no descending, each with its fetched_at; the current
  version is highlighted; choosing one opens reader.php?id=<that snapshot>.
  Add previous/next version links when they exist.
- Refetch button "Lấy phiên bản mới": visible only to users whose library
  role is owner or editor (admins always see it). It POSTs to the shared
  store path with the page's stored source_url. On success, redirect to the
  NEW snapshot with a Vietnamese success message naming the new version
  number. On failure, the shared path records the failed snapshot as usual;
  stay on the current snapshot and show the distinct Vietnamese error
  message for that error code. Old versions and their notes are never
  touched by a refetch.
- Notes panel for THIS snapshot, under the article: list the notes whose
  snapshot_id is the current snapshot (author full name, created_at, and an
  edited marker when updated_at is later than created_at), oldest first.
  An add-note form (textarea) is available to every user who can view the
  library. Edit and delete controls appear only on the note's own author;
  an admin may delete any note. Notes belong to one snapshot only: another
  version of the same page shows its own notes, never these.
- Add a small session CSRF helper (wa_csrf_token / wa_csrf_check) and use
  it on the refetch and notes forms.
- Keep the existing A- / A+ control, background toggle and reading
  progress working.

## Deliverable 2 — library.php article list polish
- Each saved page shows: title of its latest successful snapshot, a badge
  with the successful version count ("N phiên bản"), the latest fetched_at,
  its tags; the row links to the reader at the latest successful snapshot.
- Pages with no successful snapshot appear in a separate small section
  "Lưu thất bại gần đây" (URL, error message, time; most recent 5) instead
  of the article list, visible to users who can edit the library.

## Deliverable 3 — queries (src/queries.php)
Add what you need with prepared statements, for example:
wa_page_versions(page_id) — successful snapshots of a page, version_no DESC;
wa_notes_for_snapshot(snapshot_id) — notes joined with author names;
wa_note_add / wa_note_update / wa_note_delete — update and delete must
include the author id in the WHERE clause so ownership is enforced in SQL,
not only in the UI (admin delete is a separate explicit path);
wa_library_failed_attempts(library_id, limit).

## Verification (real HTTP against php -S, fixture server on port 8090;
evidence required in the REPORT)
1. php -l on all PHP files: 0 errors. Grep proof that save.php and the
   refetch action both call the shared store function.
2. Regression: capture http://localhost:8090/article.html through save.php
   again — success, a new version is created (the refactor broke nothing).
3. Notes: as user@demo.vn, add a note on version 1 of the fixture page; it
   renders with the author name. Edit it: new text plus the edited marker.
   Switch to another version: that note is NOT there; add a different note
   on that version; switch back: the first note is intact.
4. Refetch: change tests/fixtures/article.html by adding one marker
   sentence; from the reader of the latest version, submit the refetch
   form; you land on a new snapshot whose version_no is previous max + 1
   and whose body contains the marker; the version list now shows every
   version; version 1 still opens with its original body and its note.
5. Refetch failure: stop the fixture server; submit refetch again; you
   stay on the current version, see the distinct Vietnamese error message,
   a new failed snapshot row exists in the DB (show its fetch_status), and
   the current version still returns HTTP 200.
6. Permissions (test scaffolding is allowed: register throwaway accounts
   through register.php and insert their library_members rows with a
   .local helper using the app DB account; delete the throwaway rows and
   accounts afterwards and say so): a viewer-role member sees NO refetch
   button and a direct refetch POST from them is rejected; a viewer CAN
   add a note but CANNOT edit another user's note (the POST is rejected
   and the note is unchanged in the DB); a registered non-member opening
   reader.php?id=<fixture snapshot> gets the Vietnamese 403 page; a guest
   is redirected to login.
7. Git: commit and push to origin main; confirm HEAD == origin/main and a
   clean working tree.

## Out of scope
Export, search engine changes, library creation and member-management UI,
allowlist changes. Do not start any of these.

## REPORT (mandatory, end of your final message)
Files created/changed/deleted; the shared store function's name and its
call sites; verification results for items 1 to 6 with actual outputs
(including the DB rows you inspected); test scaffolding created and
removed; anything blocked or guessed. Commit and push only after all
verification passes, confirming HEAD == origin/main.
