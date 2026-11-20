# M4 design-tier security review: WP3 (rules SQL, collection store) and WP5 (web components)

Independent review of merge commits 822dddb (M4-WP3) and fe9078f (M4-WP5) on the integration branch. Specs: `docs/specs/M4-core.md` sections 5 and 6, `docs/specs/M4-screens.md` sections 2, 6, and 8, ADR 0019, `docs/build/M4-session-plan.md` sections 5.2 and 8. Every property was checked by reading the code and running hostile cases, not by trusting the existing tests or comments. All data used is synthetic.

Verdict: **no blocking or high findings.** Six low findings and one nit, listed below. Suite with the new test file: 8963 tests OK (1 skipped); lint 727 files, 0 failed.

## Evidence

### WP3: SQL composition and stored rules

- One composition path: `ListSql::where()` calls `rules()` and `filter()`, and both call `condition()`. Each rule is wrapped by `Sql::wrap()`, joined by `Sql::join(' AND ' | ' OR ')`, and the group is wrapped again (`ListSql.php:162-173`). The date-time `on` fragment, which holds its own `AND`, is always inside the wrap. `Sql::of()` refuses `;`, comments, NUL, numbered or named parameters, and a placeholder-count mismatch at run time.
- Identifiers: column names come only from `FieldDef::sql()`, `RelationDef::sql()`, `TableDef::sql()`, and `Identifier::system('left_id'|'right_id')`. `ListSql::fieldOf()` refuses a field of another table. `CollectionRules::fieldOf()` looks a key up only among the table's current fields. `Condition` and `CollectionRule` require `Keys::isValid` on the field key.
- Values: every operand is bound. Hostile text (`x' OR '1'='1`) in contains, text `is`, choice `is`, and search does not appear in the SQL text (`HostileCollectionTest::testEveryFragmentKeepsValuesBound`).
- Tampered `_tiffin_collections` rows (`HostileCollectionTest::testTamperedRuleJsonNeverErrors`, 31 cases) are all read, resolved, and listed without an error: invalid JSON, an object, scalars, nesting 500 deep (over the decode depth of 8), nesting 20 deep inside `values`, 11 rules, 20,000 rules (read as the first 10), an unknown operator holding SQL, a filter-only operator (`between`) in a rule, an operator of the wrong type, a field key holding SQL, a field of another table, an unknown (removed) field, an attachment field, `values` as an object, nested, or an int, a text over 200 characters, a NUL, three values, a value on `empty`, `1e400`, `1 OR 1=1` for a number, `2026-02-30`, a removed choice key, a choice given by label instead of key, checkbox `2`, record ids `0`, 20 digits, and `1 OR 1=1`, and a lone UTF-16 surrogate. Each becomes a dropped entry or a broken rule.
- Tampered `match_mode` (empty, `ANY`, SQL, `none`) reads as `all`. Tampered `sort` (SQL, `added.asc; --`, a field of another table, an attachment field, an unknown key, `changed.asc`) gives the default order (`testTamperedMatchAndSort`).
- LIKE: `%`, `_`, and `\` in a contains operand match only themselves (`testLikeWildcardsAreLiteral`).
- Query plans: the 20,000-item fixture of T-RUL-11 was run three ways. With no statistics, which is the state of every project file because TiffinDB never runs ANALYZE, every page took 6 to 34 ms. With ANALYZE statistics, the same. The plan assertions also pass with no statistics, a case T-RUL-11 does not cover today. With forged statistics: see L-1.

### WP5: templates, headers, routes, stylesheet, script

- Templates (`link-control`, `link-rows`, `file-rows`, `leave-note`, `sidebar`, `layout`): every plain value goes through `e()` or a parameter of `t()` (which escapes both the text and the parameters). Every address goes through `url()`, and `UrlGenerator::to` refuses `//`, `\`, `?`, `#`, and control characters in a path. Ids come from `id()`. There is no inline `style`, no `<style>`, no inline `<script>`, and no `on*` attribute. No partial prints a `<form>`. The Unlink buttons use `form=` naming the page-level unlink form, and `HtmlAssertions` checks that every `form` attribute names a form on the page and that `//form//form` is empty. `img` appears only as `src="<?= $this->url(...) ?>"` with an escaped `alt`, and TemplateRulesTest refuses `srcset`, `sizes`, `width`, and `height`.
- `collection-editor.js`: an IIFE in strict mode. It makes no network call and uses no storage, `eval`, or `innerHTML`, and it does not change the DOM. `app.css`: no `url()`, no `@import`, no colour literal.
- `Response::file` and `SecurityHeaders` were run with a hostile matrix (scratch script, not committed):
  - The four image types with `inline` get `inline`. SVG, HTML, PDF, and `IMAGE/PNG` with `inline` are refused. A media type holding CR/LF is refused.
  - Every file response carries `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and `Cache-Control: no-store`. The policy stays sandboxed after `withHeader('Content-Security-Policy', ...)` and `apply()`.
  - Paths that are relative, hold NUL, do not exist, are a directory, or are a symbolic link are refused. `withAfterSend` is refused. `withHeader` with CR/LF is refused.
  - `contentDisposition`: CR/LF, `"`, `;`, `\`, `/`, DEL, NUL, tab, and non-ASCII become `_` in `filename=`, and `filename*=` is percent-encoded. No input could end the header, close the quote, or add a parameter.
- Routes: every new GET route is a page or a form, and every change is a POST. Literal `new` segments come before placeholders.

## Findings

| Id | Severity | Finding | Where | Confirmed by running |
|---|---|---|---|---|
| L-1 | low | With forged `sqlite_stat1` rows (both join columns unselective), SQLite skip-scans the join primary key for relation rules from the right side, so cost is rooms × items. Measured: 500 rooms 0.54 s and 0.70 s (as reported by the author), 4,000 rooms 5.3 s and 10.7 s. Judged **acceptable**: TiffinDB never runs ANALYZE, `ProjectRebuilder` does not copy `sqlite_stat1`, and real statistics cannot claim 40,000 unique pairs over 2 × 2 distinct ids. Only someone who can already write the project file can cause it. Hardening if wanted: delete `sqlite_stat1` on open, or `INDEXED BY` the `_r` index for right-side lookups. Add the no-statistics case to T-RUL-11. | `src/Core/Query/ListSql.php:292-302` | yes |
| L-2 | low | `Response::file` sends any non-inline media type as given, for example `text/html` with `attachment`. The spec says "exactly", and the core maps non-inline types to `application/octet-stream`, so this holds today. The web layer could enforce octet-stream for every type outside `INLINE_TYPES` as a second guard, as it already does for `inline`. | `src/Web/Response.php:180` | yes |
| L-3 | low | `contentDisposition` keeps format characters (U+202E and other bidi controls) in `filename*`, and it has no length cap. A 5,000-emoji name gives an 80 KB header. Both are mitigated today by `Attachments::cleanName` (Cf removed, 255 code points) on write. The WP10 archive rebuild and WP6 serving must use the same cleaning for names read from untrusted files. | `src/Web/Response.php:227-234` | yes |
| L-4 | low | A collection row whose table no longer exists (possible only with foreign keys off, for example a file edited by another tool) is returned by `CollectionStore::read`, and `CollectionRules::resolve` then throws `NotFound`. WP4 and WP7 must resolve per collection and skip such a row (sidebar counts, the collections list), so one bad row cannot fail every page. | `src/Core/Query/CollectionRules.php:51` | yes |
| L-5 | low | `fromRow` decodes the whole `rules` text before the 10-rule cap (depth is capped at 8, but size is not). A 20,000-rule row read fine. Memory grows with the stored size, and only a tampered file can be large. A `length(rules)` bound in SQL, as `UntrustedProject` does, would close it. | `src/Core/Query/CollectionStore.php:262` | yes |
| L-6 | low | The sidebar takes a count per collection from its caller: up to 50 rule queries per page. One 10-rule `any` count on 20,000 items measured about 11 ms, so a worst case is about 0.5 s per page. WP4 and WP7 should bound or cache the counts. | `src/Web/Sidebar.php:92` | partly (one query measured, sidebar not wired yet) |
| L-7 | low | `collection-editor.js` submits on every `change` of the match radios, so pressing an arrow key in the radio group reloads the page and moves focus (WCAG 3.2.2 On Input). This is as specified. Consider submitting on field-select change only, and checking it in the K-3 keyboard run. | `public/assets/js/collection-editor.js:31` | no |
| N-1 | nit | In `file-rows`, the image link and the Open link of an image row go to the same address: two tab stops for one target. | `templates/partials/file-rows.php:40,45` | no |

## Added test

`tests/core/query/HostileCollectionTest.php` (4 tests, all pass) keeps the tampered-row, sort, LIKE, and binding cases above as regression cover. It changes no source or existing test.
