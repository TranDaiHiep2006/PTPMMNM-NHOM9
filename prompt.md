# P-DB — MySQL schema, database-backed auth, and seed data (WebArchive, Topic 16)

Read PROJECT_CONTEXT.md and the current code first. This phase replaces the UI
phase's placeholder data layer with a real MySQL database. Pure PHP 8.3, no
framework, no Composer packages. All user-facing text stays Vietnamese.

## Environment facts (verified, do not re-investigate)
- Windows, project at D:\PTPMMNM-NHOM9, run with: php -S localhost:8080 -t public
- PHP 8.3.36 was installed via winget in the previous phase WITHOUT a php.ini,
  so mbstring is currently missing (a polyfill exists in src/bootstrap.php).
- MySQL Server 8.0.46 runs as Windows service MySQL80 at 127.0.0.1:3306 and is
  healthy. Its admin account is the user's own account (username: tranhiep).

## Rules before touching the database
- ASK the user for the MySQL admin password at the start of your work. Never
  guess it, never reset or change any existing MySQL account, never drop or
  modify any database other than the new one named webarchive.
- The app must connect with its OWN limited database user (created by the
  setup script below), never with the admin account. App credentials live only
  in config/db.local.php, which must be gitignored and never committed.

## Task 0 — PHP extensions
Create a php.ini for the winget PHP install (based on php.ini-development if
present) enabling at least: mbstring, pdo_mysql, mysqli. Verify with php -m and
include the relevant lines in your REPORT. Keep the bootstrap polyfill in place
as a fallback; do not remove it.

## Deliverable 1 — database/schema.sql
Full DDL, InnoDB, utf8mb4 / utf8mb4_unicode_ci, foreign keys, DROP TABLE IF
EXISTS in FK-safe order so the file is re-runnable. Tables:
- users: id, full_name, email (unique), password_hash, role ('admin','user'),
  status ('active','locked'), created_at
- libraries: id, owner_id FK users, name, slug (unique), description,
  visibility ('private','shared','public'), color, icon, created_at
- library_members: library_id FK, user_id FK, role ('owner','editor','viewer'),
  composite PK (library_id, user_id)
- tags: id, name (unique)
- saved_pages: id, library_id FK, source_url, domain, title, status
  ('active','error'), created_by FK users, created_at, updated_at
- page_tags: page_id FK saved_pages, tag_id FK tags, composite PK
- snapshots: id, page_id FK, version_no, title, content_html (MEDIUMTEXT),
  content_text (MEDIUMTEXT), source_url, fetched_at, http_status,
  fetch_status ('success','error_404','error_timeout','error_blocked',
  'error_no_content','error_http'), error_message,
  UNIQUE (page_id, version_no)
- assets: id, snapshot_id FK, original_url, local_path, mime_type, size_bytes
- notes: id, snapshot_id FK, user_id FK, content, created_at, updated_at
- allowlist_domains: id, domain (unique), note, added_by FK users, created_at

## Deliverable 2 — database/seed.sql
Port ALL sample content from src/sample_data.php into INSERT statements:
the two demo users (generate real password_hash bcrypt literals for demo123 and
admin123 and embed the hash strings), libraries with owners, members with
roles, tags, saved pages, exactly one snapshot per article whose content_html /
content_text hold the existing sample reader article, page-tag links, and the
allowlist domains. Keep names, slugs and counts consistent with sample_data.php.

## Deliverable 3 — scripts/setup_db.php (CLI, idempotent)
- Reads admin credentials from environment variables WA_ADMIN_USER and
  WA_ADMIN_PASS; if missing, exits with a clear instruction message.
- Creates database webarchive if it does not exist (utf8mb4).
- If config/db.local.php does not exist: generates a random app password,
  creates user 'webarchive'@'localhost' with ALL PRIVILEGES on webarchive.*
  only, and writes config/db.local.php returning the app credentials.
  If the file exists, reuses it.
- Applies database/schema.sql then database/seed.sql (this resets the
  webarchive database only), and prints the row count of every table.

## Deliverable 4 — code changes
- config/db.php (committed): loads config/db.local.php and exposes the PDO
  config. Add config/db.local.php to .gitignore. DELETE config/demo_users.php.
- src/db.php: PDO singleton (utf8mb4, ERRMODE_EXCEPTION, real prepares).
  If the database is unreachable, show a Vietnamese error page telling the
  user to run the setup script — no raw stack traces.
- src/auth.php: authenticate against the users table (email + password_verify);
  session stores the user id. register.php now INSERTS a real user (role
  'user', status 'active'); duplicate email shows a Vietnamese error.
  require_login() / require_admin() behavior unchanged.
- Move these pages from sample_data.php to database queries: libraries.php,
  library.php, reader.php, search.php, admin/index.php (counts from real
  tables), admin/users.php, admin/libraries.php, admin/allowlist.php.
  The allowlist ADD form must really INSERT and persist.
- Basic access rule for queries: a user sees libraries they own or are a
  member of; admin sees everything. (Hardening comes in a later phase.)
- reader.php now takes a numeric snapshot id (?id=<snapshot id>); display the
  label SNAP-<id> as text only. Update all internal links to match.
- search.php: simple database search for this phase — LIKE over
  snapshots.title and snapshots.content_text, joined with saved_pages and
  libraries, access-filtered, snippet with <mark> via the existing
  wa_highlight helper, showing library name and snapshot version_no.
  (The real self-built search engine is a later phase.)
- save.php and src/mock_capture.php stay exactly as they are (capture engine
  is the next phase).
- Delete src/sample_data.php only after grepping that no file references it;
  if anything still needs a piece of it, move that piece into mock_capture.php
  and say so in the REPORT.
- Use prepared statements for every query involving input. No SQL string
  concatenation with user data anywhere.
- Update README.md and PROJECT_CONTEXT.md: MySQL is now required; document
  the setup_db.php step, the WA_ADMIN_USER / WA_ADMIN_PASS variables, and that
  the demo accounts are now real database accounts with the same passwords.

## Verification (evidence required in the REPORT)
1. php -m lines proving mbstring and pdo_mysql are loaded.
2. Run scripts/setup_db.php TWICE; show both outputs incl. per-table counts.
3. php -l on every PHP file: 0 errors.
4. Real HTTP checks against php -S: both demo accounts log in (302 to
   libraries.php); wrong password shows the Vietnamese error; registering a
   NEW account then logging in with it works; guest redirect and non-admin
   403 page still behave as before.
5. Reflection test: UPDATE one library name directly in MySQL, show that
   libraries.php displays the new name, then revert it. Include the outputs.
6. Add an allowlist domain through the admin form and show it persists after
   reloading the page.
7. Grep proof that no references to demo_users.php or sample_data.php remain
   (or list the exact exceptions).
8. Git: show that config/db.local.php is NOT tracked (git ls-files), working
   tree clean after commit + push, and HEAD == origin/main.

## Out of scope
Real page fetching/capture, extraction and sanitizing engines, image
downloading, version refetch, export, the real search engine, and permission
hardening. Do not start any of these.

## REPORT (mandatory, end of your final message)
Files created/changed/deleted; the schema table list with row counts after
seed; how setup_db.php is run; every verification result from the list above
with its actual output; anything blocked or guessed. Commit and push to origin
main only after all verification passes, and confirm HEAD == origin/main.
