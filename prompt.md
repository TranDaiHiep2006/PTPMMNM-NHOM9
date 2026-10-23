# P-FINAL — Sample dataset, mandatory tests, OSS delivery (WebArchive, Topic 16)

Read PROJECT_CONTEXT.md and the current code first. Current state: P-LIB
is done (HEAD e0d0ef6). Every feature works. This final phase builds the
assignment's evidence package: the 15 sample pages, the 10 search
queries with expected results, an automated runner for the 6 mandatory
tests, and the open-source delivery files. Pure PHP 8.3, no framework,
no Composer packages. All user-facing text stays Vietnamese.

Hard rules:
- Do NOT run scripts/setup_db.php and do NOT ask for the MySQL admin
  password; the app account in config/db.local.php is enough.
- No schema changes. No new product features — bug fixes only when a
  test exposes a real bug, and every fix is reported.
- NEVER delete files under public/uploads/ manually (a cleanup bug in
  P-LIB wiped real uploads once). Deleting test data goes through the
  app's own delete-library action, which removes upload dirs itself.
- Prepared statements only; never reuse one named placeholder twice in
  a single statement.

## Deliverable 1 — sample dataset (tests/samples/)
Static Vietnamese pages served by: php -S localhost:8091 -t tests/samples
Every content page has a clearly identifiable article section and more
than 600 characters of body text (except the notice page). Each content
page carries at least one distinctive body-only phrase that the search
queries in Deliverable 3 will target. The 15 items:
 1. bai-01.html — PHP basics article, one image (anh-01.png)
 2. bai-02.html — MySQL article, one image
 3. bai-03.html — HTML/CSS article
 4. bai-04.html — JavaScript article
 5. bai-05.html — Linux article
 6. bai-06.html — Git article
 7. bai-07.html — SAME <title> as bai-01 but clearly different content
    (the duplicate-title case)
 8. bai-08.html — article with three images
 9. bai-09.html — long article about computer networks and DNS
10. bai-10.html — article built around a table and lists
11. bai-11.html — article containing a script tag, an onclick attribute
    and an iframe (the sanitizer case)
12. bai-12.html — notice-only page, under 200 characters of text
    (the error_no_content case)
13. bai-13.html — article laid out with main + divs instead of article
14. chuyen-huong-ngoai.php — redirects to a non-allowlisted host
    (the redirect-escape case)
15. cham.php — sleeps 15 seconds before printing an article
    (the timeout case)
The 404 case needs no file: /khong-ton-tai.html on this server.
Generate the PNG images programmatically (small valid PNGs, distinct
colors). Write tests/samples/README.md: a manifest table of all 15
items with each one's purpose and expected capture outcome (success
with the expected extracted title, or the expected error code).

## Deliverable 2 — seed the samples (scripts/seed_samples.php)
CLI, app account. Ensures a library named "Kho dữ liệu mẫu" owned by
user@demo.vn exists (create it through the same query helpers the web
uses). Captures each of the 15 sample URLs (http://localhost:8091/...)
through wa_store_page_capture() — the real engine path — applying one
or two tags per page, and prints one line per item: file, snapshot id,
fetch_status. Expected outcomes must match the manifest (success for
content pages; error_blocked for item 14; error_timeout for item 15;
error_no_content for item 12). If the library already contains pages
for these URLs, print "already seeded" and exit 0 without duplicating.

## Deliverable 3 — 10 search queries with expected results
docs/SEARCH_QUERIES.md. FIRST design the 10 queries from the sample
contents you authored and write down the expected results (which sample
titles, and versions where relevant); only then run them through the
real search.php over HTTP against the seeded library and record the
actual results next to the expected ones. The set must cover: a
body-only phrase; a title word; an unaccented query matching accented
content; a two-word AND query; a tag-filtered query; a domain-filtered
query (localhost); a query whose expected answer is the duplicate-title
pair (both bai-01 and bai-07); a query returning a page in two
versions (capture bai-05 a second time through save.php first, so it
has versions 1 and 2 — expected: both versions listed); a query with
zero expected results; a query that ranks a title match above a body
match. All 10 must end with expected == actual; if one disagrees,
explain and fix the data or the document — never silently rewrite the
expectation to match, say in the report what changed and why.

## Deliverable 4 — automated mandatory-tests runner (scripts/run_tests.php)
CLI. It boots its OWN servers with proc_open (app on port 18080,
samples on port 18091), waits for readiness, runs everything over real
HTTP with cURL cookie jars in the system temp dir, prints one line per
test "T<n> PASS/FAIL — <evidence>", a final summary, and exits 0 only
when all six pass. A shutdown handler always kills both servers.
Setup inside the runner: log in as user@demo.vn; create a throwaway
library through the real library_action.php; register a throwaway
outsider account through register.php.
 T1 (origin down): capture bai-01 into the throwaway library; kill the
    samples server; GET the reader -> 200 and contains the body-only
    phrase; GET the local image URL -> 200. Restart the samples server.
 T2 (body-only search): search bai-02's body-only phrase -> the results
    contain bai-02's snapshot with the correct "Phiên bản" label and a
    <mark> snippet.
 T3 (distinct errors): capture /khong-ton-tai.html, cham.php and
    https://example.com/ -> the DB holds three snapshots with three
    DISTINCT fetch_status values: error_404, error_timeout,
    error_blocked.
 T4 (script cannot run): capture bai-11 -> snapshot success; its
    content_html contains no "<script", no "onclick", no "<iframe";
    its body phrase is present.
 T5 (versions + notes survive): the runner writes a temporary sample
    file into tests/samples (unique name), captures it, adds a note
    through note.php, edits the temp file adding a marker sentence,
    refetches through refetch.php -> version 2 contains the marker,
    version 1 does not, and version 1's note is still there. The temp
    file is deleted in cleanup.
 T6 (no access for outsiders): the outsider GETs the T1 snapshot in the
    reader -> 403; GETs export.php for it -> 403; the owner GETs the
    export -> 200.
Cleanup: delete the throwaway library through library_action.php (its
uploads go with it), delete the outsider account row via the app DB
account, remove temp files and cookie jars. The runner must be safely
re-runnable (unique throwaway names per run).

## Deliverable 5 — OSS delivery and final docs
- LICENSE at the repo root: the MIT License, copyright holder "the
  WebArchive contributors".
- CONTRIBUTING.md: how to set up and run, the project conventions
  (pure PHP, prepared statements only, CSRF on POST forms, Vietnamese
  UI text, one shared store path), and how the group works with issues
  and pull requests on GitHub.
- docs/DEMO.md: the two official demo scenarios as click-by-click
  scripts (accounts, URLs, expected screens): Scenario 1 — save a URL,
  tag it, open the saved copy, search a body-only keyword. Scenario 2 —
  origin changed/lost, read the old copy, fetch a new version, compare
  source/time/notes per version, export.
- README.md final pass: replace the phase banners with one product
  summary; a complete from-scratch setup (PHP extensions, setup_db.php,
  then reindex_search.php so the seed snapshots get indexed, start the
  app server plus the fixtures and samples servers, demo accounts);
  the full feature list as DONE; links to docs/DEMO.md,
  docs/SEARCH_QUERIES.md and tests/samples/README.md.
- PROJECT_CONTEXT.md: final state.
- Release: create the annotated tag v1.0.0 on the final commit and push
  it. If the gh CLI is available and authenticated, also create the
  GitHub Release from that tag; otherwise add docs/RELEASE_NOTES.md
  with the v1.0.0 notes and state in your report that the release
  itself is left for the user to publish from the tag.

## Verification (evidence required in the REPORT)
1. php -l on all PHP files: 0 errors.
2. Samples: the manifest lists 15 items; spot outputs: two content
   pages return HTTP 200 on port 8091, chuyen-huong-ngoai.php returns
   a redirect to the external host, /khong-ton-tai.html returns 404.
3. seed_samples.php output: 15 lines whose fetch_status matches the
   manifest exactly.
4. SEARCH_QUERIES.md: 10 of 10 expected == actual, with the method
   stated; list any expectation you had to correct and why.
5. run_tests.php: paste the full output of TWO consecutive runs — all
   six tests PASS in both, exit code 0 both times.
6. git ls-files shows LICENSE, CONTRIBUTING.md, docs/DEMO.md,
   docs/SEARCH_QUERIES.md; git ls-remote --tags shows v1.0.0 (and the
   release status, created or left-for-user).
7. Final git state: HEAD == origin/main, clean working tree; final DB
   counts per table.

## REPORT (mandatory, end of your final message)
Files created/changed/deleted; the sample manifest summary; the seed
output; the 10 queries outcome; both test-run outputs; the OSS files
and tag/release status; any bug a test exposed and its fix; anything
blocked or guessed. Commit and push only after all verification passes,
confirming HEAD == origin/main.
