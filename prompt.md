# P-LIB — Library & member management, page tags, export (WebArchive, Topic 16)

Read PROJECT_CONTEXT.md and the current code first. Current state:
P-SEARCH is done (HEAD eeaac8a). Capture, versioning, notes and the
self-built search all work. What is still display-only: creating
libraries, managing members, editing a saved page's tags, and export.
This phase makes all four real. Pure PHP 8.3, no framework, no Composer
packages. All user-facing text stays Vietnamese.

Hard rules:
- Do NOT run scripts/setup_db.php and do NOT ask for the MySQL admin
  password; the app account in config/db.local.php is enough.
- No schema changes are expected — every table you need already exists.
  If you believe one is necessary, STOP and report.
- Every new POST form uses the existing CSRF helper
  (wa_csrf_token / wa_csrf_check). Prepared statements only; never reuse
  one named placeholder twice in a single statement.

## Deliverable 1 — library management
- libraries.php: the "Tạo thư viện" control becomes a real form (name
  required; description optional; visibility select private / shared /
  public; keep the existing color/icon choices from the UI if present).
  On submit: INSERT into libraries with owner = current user, generate
  the slug from the name (lowercase, diacritics folded with the project's
  shared folding map, spaces to hyphens; if the slug exists, append -2,
  -3, ... until unique), INSERT the owner row into library_members with
  role 'owner', then redirect to the new library's detail page with a
  Vietnamese success message.
- library.php owner/admin settings: rename the library and change its
  visibility (same slug rules; keep the old slug if the name is
  unchanged). Delete library: owner only (admin may also delete from the
  admin area only if that already exists — do not build new admin
  actions), requires an explicit confirm step, deletes the DB rows via
  the existing foreign keys AND removes the on-disk upload directories
  public/uploads/snapshots/<id>/ of every snapshot of that library.
  Non-owners must not see the controls, and direct POSTs from them are
  rejected with the Vietnamese 403 page.

## Deliverable 2 — member management (in library.php)
Visible to the library owner and to admins; all actions POST + CSRF:
- Add member by email: the email must belong to an existing user,
  otherwise a Vietnamese error ("Không tìm thấy tài khoản với email
  này"); adding someone who is already a member gives a Vietnamese
  "đã là thành viên" error. Role chosen at add time: editor or viewer
  (never owner).
- Change a member's role between editor and viewer.
- Remove a member. The owner's own membership row can never be removed
  or demoted — enforce this in SQL (the WHERE clause excludes rows with
  role 'owner'), not only in the UI.
- The members table in the UI reflects the DB immediately after each
  action (name, email, role label in Vietnamese, join order).

## Deliverable 3 — edit tags of a saved page
- In library.php, each article card/row gets a tag editor for users with
  edit rights (owner/editor/admin): a small form with the page's current
  tags as a comma-separated value; submitting replaces the page's
  page_tags set (create rows in tags when a name is new, delete links
  that are no longer listed, keep the '#'-prefix convention used by the
  seed if the existing data uses it — check the current tags format and
  follow it consistently). Viewers see tags read-only.

## Deliverable 4 — export a snapshot (public/export.php)
- GET export.php?id=<snapshot id>, login required, same access rule as
  the reader (admin or library member; otherwise the Vietnamese 403
  page; failed or missing snapshot -> 404).
- Response: a downloadable standalone HTML file
  (Content-Disposition: attachment; filename
  <folded-title-slug>-v<version_no>.html) that opens fully offline:
  a provenance header (article title, source URL, library name,
  "Phiên bản N", fetched_at, exported by + export time), then the
  snapshot's content_html with every local image (/uploads/...) inlined
  as a base64 data URI (read the file, detect MIME with finfo), plus a
  minimal inline <style> for readability. The exported file must contain
  NO external resource references: no http:// or https:// img src, no
  external stylesheet/script. Article links inside the body may stay as
  absolute http(s) anchors (they are content, not loaded resources).
- Add an "Xuất bản lưu" action in the reader next to the existing print
  ("Xuất PDF") button; keep the print button working.

## Docs
Update README.md (page map with export.php; the new management flows)
and PROJECT_CONTEXT.md. The "Tạo thư viện" / "mời người dùng" items move
from the not-yet list to the done list.

## Verification (real HTTP against php -S; fixture server on 8090;
evidence required in the REPORT)
1. php -l on all PHP files: 0 errors.
2. Create library: POST the create form as user@demo.vn -> the library
   exists in the DB with an owner member row, appears in libraries.php,
   and its detail page opens. Create a second library with the SAME
   name -> a different slug (show both slugs from the DB).
3. Members (throwaway accounts registered through register.php; cleaned
   up afterwards and stated in the report): owner adds account B as
   viewer -> B now lists the library and opens the reader; B cannot
   save or refetch (already enforced — re-confirm quickly). Owner
   promotes B to editor -> B captures the fixture article into that
   library successfully. Owner removes B -> B's library list no longer
   shows it and the reader link returns 403. Add with a nonexistent
   email -> the Vietnamese not-found error; add the same member twice
   -> the already-member error; attempt to remove/demote the owner row
   -> rejected and the row is unchanged in the DB.
4. Delete library: create a throwaway library, capture the fixture
   article into it (note the snapshot id and its uploads directory),
   delete it as its owner -> libraries/pages/snapshots rows are gone
   and the uploads directory no longer exists on disk. An editor (not
   owner) POSTing delete is rejected.
5. Tags: set tags on the fixture page via the library UI -> page_tags
   rows in the DB match exactly; search.php with the tag filter now
   finds the page under the new tag; remove one tag -> its link row is
   gone.
6. Export: GET export.php?id=<a fixture snapshot with an image> as a
   member -> HTTP 200, Content-Disposition attachment with the expected
   filename pattern; the body contains the body-only keyword, the
   provenance block (source URL, version, fetched_at), at least one
   data:image base64 URI, and NO <img src="http and NO /uploads/ src
   left. Non-member -> 403; guest -> login redirect; failed snapshot
   id -> 404.
7. Git: commit and push to origin main; confirm HEAD == origin/main and
   a clean working tree.

## Out of scope
The 15 sample pages + 10 queries dataset, the six mandatory tests
suite, and OSS packaging (license/release) — those are the final phase.
Admin user lock/unlock actions. Do not start any of these.

## REPORT (mandatory, end of your final message)
Files created/changed/deleted; verification results for items 1 to 6
with actual outputs (DB rows and slugs you inspected, HTTP statuses,
export filename and grep results); scaffolding created and removed;
anything blocked or guessed. Commit and push only after all verification
passes, confirming HEAD == origin/main.
