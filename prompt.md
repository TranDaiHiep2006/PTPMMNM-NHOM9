# P-CAPTURE — Real capture engine (WebArchive, Topic 16)

Read PROJECT_CONTEXT.md and the current code first. Current state: P-DB is
done (HEAD 7fefbbd); all data lives in MySQL; save.php still uses the
src/mock_capture.php simulation. This phase replaces the simulation with a
REAL capture engine. Pure PHP 8.3, no framework, no Composer packages — only
built-in DOM/DOMDocument and cURL. All user-facing text stays Vietnamese.

Hard rules:
- Do NOT run scripts/setup_db.php (it resets the database) and do NOT ask for
  the MySQL admin password. The app account in config/db.local.php is enough.
- No schema changes are expected. If you believe one is necessary, STOP and
  report instead of migrating silently.
- Prepared statements only, and never reuse one named placeholder twice in a
  single statement (the HY093 bug from last phase).

## Task 0 — cURL extension
This PHP build currently has no curl extension (last phase's checks used raw
sockets). Enable curl in php.ini, verify php -m lists curl and dom. If curl
genuinely cannot be enabled, STOP and report — do not silently switch design.

## Deliverable 1 — src/capture.php
One entry function capture_url(string $url): array with keys: ok, error_code
(null on success), http_status, final_url, title, content_html, content_text,
images (list of original_url, local_path, mime_type, size_bytes), fetched_at.
Pipeline, in this order:
1. Validate: parse_url; scheme must be http or https; host required; reject
   URLs containing credentials. Failure: error_code error_invalid_url.
2. Allowlist check BEFORE any network traffic: the host matches the
   allowlist_domains table when host equals a listed domain or ends with
   dot + domain (case-insensitive). No match: error_blocked, and no HTTP
   request may be sent at all.
3. Fetch with cURL WITHOUT FOLLOWLOCATION: follow redirects manually, max 5
   hops, and re-run the full allowlist check on EVERY redirect target before
   requesting it; a redirect that leaves the allowlist stops with
   error_blocked (put the target in error_message). Timeouts: connect 5 s,
   total 12 s; a timeout gives error_timeout. Use a clear User-Agent string.
   Abort downloads over 5 MB and report error_http with a size message.
4. Status mapping: 404 gives error_404; any other 4xx/5xx gives error_http
   with the status in the message; 2xx continues. Content-Type must be
   text/html (or XHTML), otherwise error_no_content.
5. Extraction with DOMDocument: title priority is og:title, then the title
   tag, then the first h1. For the body, prefer an article element, then
   main, then the div/section with the highest text density (paragraph text
   length weighed against link density — a plain readability-style score).
   Remove script, style, noscript, iframe, form, nav, header, footer, aside,
   svg, button and input elements before extracting.
6. If the extracted plain text is under 200 characters, return
   error_no_content — notice-only or empty pages are never valid articles.
7. Sanitizer (self-built whitelist): allowed tags are p, br, hr, h1 to h6,
   ul, ol, li, blockquote, pre, code, strong, b, em, i, u, a, img, table,
   thead, tbody, tfoot, tr, th, td, figure, figcaption, div, span, sup, sub.
   Allowed attributes only: a href and title; img src, alt, title, width,
   height; td and th colspan and rowspan. Strip every other attribute
   (including all on-handlers, style, class, id). Anchor hrefs: keep only
   http/https (resolve relative to absolute); otherwise unwrap the link and
   keep its text. Add rel noopener noreferrer to kept links. The output must
   contain no script tag, no javascript: URL and no event attribute — enforce
   by construction and assert it at the end.
8. Images: for each img in the sanitized body (max 20): resolve src to an
   absolute URL and allowlist-check its host exactly like a page URL; a host
   outside the allowlist means the image is skipped (removed, counted).
   Download with the same timeout and redirect rules, cap 5 MB. Accept only
   image/jpeg, image/png, image/gif, image/webp, verified by finfo or
   getimagesizefromstring — never trust the extension. SVG is never accepted.
   Files are stored under public/uploads/snapshots/<snapshot_id>/ with a hash
   file name; because the id exists only after insert, first create the
   snapshot row, then download images, rewrite the img src values to the
   local paths (/uploads/snapshots/<id>/<file>), insert the assets rows and
   update the snapshot content. After this step, content_html must contain
   NO external image URL — every img src is local or the img is gone.
9. content_text: strip tags from the final content_html, decode entities,
   collapse whitespace.

## Deliverable 2 — wire save.php to the real engine
- Form: library select (only libraries where the current user is owner or
  editor), URL field, optional comma-separated tags field.
- On success: if a saved_pages row already exists in that library with the
  same source_url, create the snapshot with version_no = current max + 1;
  otherwise create the page (status active) and snapshot version 1. Apply
  the tags to the page. Redirect to reader.php?id=<snapshot id> with a
  Vietnamese success message that includes the version number.
- On failure: still record the attempt — create or find the page (status
  error when new) and insert a snapshot row with fetch_status set to the
  error_code, error_message filled, and empty content — so admin statistics
  can count failures. Show the user a DISTINCT Vietnamese message per error
  code (blocked, 404, timeout, no_content, http, invalid_url). Failed
  snapshots must never appear as readable articles: library article lists and
  the reader only ever use snapshots with fetch_status success.
- DELETE src/mock_capture.php and the old 4-state demo panel; grep proof that
  no code references it remain. Update README.md and PROJECT_CONTEXT.md:
  capture is now real, describe the allowlist rule (subdomains allowed,
  every redirect re-checked), the timeouts, the image localization and the
  uploads storage path.

## Deliverable 3 — test fixtures (tests/fixtures/)
Served by a second php -S on port 8090 with docroot tests/fixtures:
- article.html: a realistic article (title tag, h1, several paragraphs
  containing one unique body-only keyword of your choosing, one img pointing
  to pic.png, one script tag with alert, one onclick attribute, one external
  link).
- pic.png: a small valid PNG (generate it programmatically).
- redir-ok.php: redirects to article.html on the same host.
- redir-escape.php: redirects to https://example.org/ (never allowlist it).
- slow.php: sleeps 15 seconds, then prints an article.
- notice.html: a page whose text is under 200 characters.
Add the domain localhost to allowlist_domains through the admin UI and KEEP
it there at the end (it is the project's local test/demo host); say so in
README and in your report.

## Verification (evidence required in the REPORT, real outputs)
1. php -m lines proving curl is loaded; php -l on all PHP files: 0 errors.
2. Capture article.html through the real save.php flow (logged-in HTTP POST):
   DB shows the page row and a snapshot version 1 with fetch_status success;
   content_html contains the body-only keyword; content_html contains no
   script tag and no onclick (show the grep); every img src in content_html
   starts with /uploads/; the assets row exists and the file exists on disk
   with matching size.
3. Origin-down test: stop the fixture server, then GET the new snapshot in
   reader.php: HTTP 200, body still contains the keyword and the local image
   path.
4. Error matrix — each case lands in the DB with the right fetch_status and
   shows its distinct Vietnamese message: missing file on the fixture server
   gives error_404; slow.php gives error_timeout; notice.html gives
   error_no_content; https://example.com/ (not allowlisted) gives
   error_blocked; redir-escape.php gives error_blocked; redir-ok.php gives
   success.
5. Versioning: change article.html by adding one marker sentence, capture the
   same URL into the same library again: a snapshot with version_no 2 exists
   and version 1 is unchanged.
6. One real public capture: allowlist the domain of
   https://vi.wikipedia.org/wiki/PHP through the admin UI and capture that
   URL: report the resulting snapshot id, title and fetch_status. If the
   network blocks it, report the actual error honestly instead of faking it.
7. Git: add public/uploads/ to .gitignore (with a .gitkeep exception) so
   captured binaries stay out of the repo; commit and push to origin main;
   confirm HEAD == origin/main and a clean working tree.

## Out of scope
Notes UI, a refetch button (data-level versioning through save.php is
enough), export, the real search engine, the 15 sample pages and 10 queries
set, and permission hardening beyond the owner/editor check on save.

## REPORT (mandatory, end of your final message)
Files created/changed/deleted; how the allowlist, redirect and timeout rules
are implemented; the full verification table from items 1 to 6 with actual
outputs; the final allowlist contents; anything blocked or guessed. Commit
and push only after all verification passes, confirming HEAD == origin/main.
