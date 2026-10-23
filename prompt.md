# P-SEARCH — Self-built inverted-index search (WebArchive, Topic 16)

Read PROJECT_CONTEXT.md and the current code first. Current state: P-READ
is done (HEAD 63489b9). public/search.php currently searches with LIKE over
snapshots.title / content_text. The assignment requires storage, versioning,
search and permissions to be SELF-BUILT, so this phase replaces LIKE with a
hand-built inverted index. Do NOT use MySQL FULLTEXT indexes or MATCH ...
AGAINST — the mechanism must be ours and explainable. Pure PHP 8.3, no
framework, no Composer packages. All user-facing text stays Vietnamese.

Hard rules:
- Do NOT run scripts/setup_db.php against the live database (it resets
  data) and do NOT ask for the MySQL admin password. Schema additions go
  through the migration script below, which uses the app account from
  config/db.local.php (it holds DDL rights inside the webarchive database).
- Prepared statements only; never reuse one named placeholder twice in a
  single statement. Dynamic IN lists must build one placeholder per value.

## Deliverable 1 — index table + migration
- Add ONE new table, both to database/schema.sql (so fresh installs get it)
  and to a new idempotent scripts/migrate_search.php (CREATE TABLE IF NOT
  EXISTS, runnable with the app account) for the live database:
  search_terms: id BIGINT AUTO_INCREMENT PK; term VARCHAR(100) — the
  original lowercased token; term_folded VARCHAR(100) — the token with
  Vietnamese diacritics removed; snapshot_id BIGINT FK to snapshots with
  ON DELETE CASCADE; count_title INT; count_body INT;
  UNIQUE KEY (term_folded, snapshot_id); INDEX on term_folded.
- scripts/reindex_search.php (CLI): deletes all rows of search_terms, then
  indexes every snapshot with fetch_status = 'success' (title + content_text)
  and prints: snapshots indexed, total term rows, distinct folded terms.
  It must be safe to re-run.

## Deliverable 2 — tokenizer + indexer (src/search.php)
- wa_search_tokenize(string $text): array of tokens with counts. Rules:
  lowercase with mb_strtolower; split on anything that is not a Unicode
  letter or digit; drop tokens shorter than 2 characters; cap token length
  at 100. Vietnamese text is tokenized by spaces (no dictionary segmenter).
- wa_search_fold(string $token): string — strip Vietnamese diacritics
  (a/ă/â -> a, e/ê -> e, i -> i, o/ô/ơ -> o, u/ư -> u, y -> y, đ -> d, all
  tone marks removed). REUSE the folding table that already exists in the
  project if there is one (check src/bootstrap.php polyfill) — one folding
  implementation for the whole project, not a second copy.
- wa_search_index_snapshot(int $snapshotId): deletes existing rows for that
  snapshot, tokenizes its title and content_text, merges counts per folded
  term (count_title from the title, count_body from the body), inserts.
- Hook it into wa_store_page_capture(): after a successful snapshot is
  stored, index it. Failed snapshots get no index rows. Existing versions
  keep their own index rows — search results are per snapshot, i.e. tied to
  the exact version.

## Deliverable 3 — search.php on the index
- Keep the current GET parameters working (q, plus the tag and domain
  filters already in the UI; add a library filter only if it already
  exists in the UI).
- Query handling: tokenize and fold the query with the same functions;
  empty token list -> show the form with a Vietnamese hint, no result dump.
- Match semantics: AND — a snapshot must contain EVERY query term (matched
  on term_folded). Only fetch_status = 'success' snapshots, and only in
  libraries the current user can access (owner, member, or admin) — the
  access filter belongs in the SQL.
- Ranking: score = 3 * sum(count_title) + sum(count_body) over the query
  terms; order by score DESC, then fetched_at DESC. Show the result count.
- Each result: article title, library name, "Phiên bản N" + SNAP-<id>,
  fetched_at, domain, and a snippet. The snippet comes from content_text:
  find the first token whose folded form matches a query term, take about
  200 characters around it, and wrap matched tokens in <mark> — matching
  for highlight is done on folded forms while the displayed text keeps its
  original diacritics. All output escaped; no raw content_text echoed.
- Tag filter narrows to pages carrying that tag; domain filter narrows to
  pages with that domain. Both combine with the text query.
- Remove the old LIKE-based search path (grep proof in the report that
  search.php and its query helper no longer LIKE-scan title/content_text).
- No results -> a clear Vietnamese "không tìm thấy" message.

## Deliverable 4 — docs
README gets a short "Tìm kiếm tự xây" section: the table, the tokenizer
rules, the folding, when indexing happens, the AND semantics and the
ranking formula — written so a student can defend the design in Q&A.
PROJECT_CONTEXT.md updated (search is no longer LIKE).

## Verification (real outputs required in the REPORT)
1. Run scripts/migrate_search.php, then scripts/reindex_search.php: show
   both outputs; indexed snapshot count equals the number of success
   snapshots in the DB; term rows > 0. Run reindex a second time: counts
   identical (idempotent).
2. Body-only keyword: search the fixture body-only keyword
   (khoa_tu_ban_than_webarchive) -> results are fixture snapshots with
   correct version labels, each snippet contains the keyword in <mark>.
3. Diacritics: search "luu trang" (no accents) -> results include content
   whose text contains "lưu trang" with accents, highlighted in the
   snippet. Show one result line as evidence.
4. AND semantics: pick two body words of the fixture article; the two-word
   query returns a subset (show counts: word A alone, word B alone, A+B).
5. Version-tied: identify a token that exists only in later versions of
   the fixture page (the marker sentence added during P-CAPTURE/P-READ)
   and one token present from version 1. Searching the late token lists
   the later snapshot ids and NOT snapshot 8943 (version 1); searching
   the early token includes 8943. Name the tokens you used.
6. Filters: with a text query that has results, applying the tag filter
   and then the domain filter (localhost) each narrow the result set;
   show counts before/after.
7. Access: a throwaway registered account that is not a member of the
   fixture library searches the body-only keyword -> 0 results, while
   user@demo.vn finds them (scaffolding via .local helper, cleaned up
   afterwards, stated in the report).
8. Auto-index: capture the fixture article once more through save.php,
   then WITHOUT running reindex, search the body-only keyword -> the new
   snapshot appears in the results.
9. A token unique to tests/fixtures/notice.html (never successfully
   captured) returns 0 results.
10. php -l on all PHP files: 0 errors. Commit and push to origin main;
    confirm HEAD == origin/main and a clean working tree.

## Out of scope
Library creation and member-management UI, export, the 15 sample pages
and 10 queries set. Do not start any of these.

## REPORT (mandatory, end of your final message)
Files created/changed/deleted; the final search_terms DDL; migration and
reindex outputs; verification results for items 1 to 9 with actual outputs
(tokens used, result snapshot ids, counts); scaffolding created and
removed; anything blocked or guessed. Commit and push only after all
verification passes, confirming HEAD == origin/main.
