# TiffinDB Build Ledger

Single source of truth for the v1 build. Updated and pushed after every state change. Contains no personal data.

## 1. Current state

| Item | Value |
|---|---|
| Milestone | M1 in design |
| Phase | M1 implementation wave 1: WP0 merged and pushed; WP1 and WP8 in progress (worktrees, unpushed) |
| Window start | 2026-11-12T02:30Z (window 2) |
| Resume reminder | `trig_01XZhcfKrDfgCzJQyY7oRt6r`, fires 2026-11-12T07:46Z |
| Next action | WP1, WP8: on report dispatch reviews (WP8 design tier; WP1 implementation tier, reviewed again with WP2). Then wave 2 (WP2, WP3) after WP1 merges. If resuming after a reclaim: rebuild PHPUnit install and redo every unpushed package. |

## 2. Milestones

| Milestone | Status | Exit-criteria results |
|---|---|---|
| M0 | CLOSED 2026-11-11 (independent exit audit) | See M0 exit results below |
| M1 | design | |
| M2 | not started | |
| M3 | not started | |
| M4 | not started | |
| M5 | not started | |
| M6 | deferred (awaiting maintainer discovery output) | |
| M7 | not started | |
| M8 | post-v1, gated | |
| M9 | post-v1, gated | |

### M0 exit results

Final suite: `composer test` OK (1075 tests, 11110 assertions, none skipped); `composer lint` 69 files, 0 failed; CI workflow green on the hosted runner for the integration branch (run 13, push event).

| Criterion | Result | Evidence |
|---|---|---|
| ADRs accepted | PASS | ADRs 0001 to 0009 carry an accepted status (0008, 0009 provisional); `DecisionRecordsTest` |
| `docs/formats/bento.md` first findings | DEFERRED | Awaits the maintainer's private discovery run; `tools/discovery/` script and procedure ready and reviewed; nothing in the repository guesses the format |
| Empty app serves a page | PASS | Built-in server: `/` 200 with the specified CSP and security headers, no `X-Powered-By`; 404, 400, 405 paths correct; pages well-formed |
| Shell renders in all four themes (ui 11) | PASS | Headless browser at 1360 px and 390 px, four themes: root class, token colours, mark and wordmark, footer licence line and "Source code" link, local fonts only, no console messages, no horizontal scroll (also 320 px at largest text); `tests/web` shell tests |
| `.gitignore` entries before data-creating code | PASS | Six mandatory entries present from the first scaffolding commit; `GitignoreTest` |
| Scope: scaffolding, CI, tokens, fonts and licences recorded | PASS | CI green on hosted runner; token parity and contrast tests; 8 WOFF2 files (269,836 bytes) and 4 licence files recorded in `docs/dependencies.md` |
| Repository rules | PASS | Independent SPDX scan; no names, phone numbers, credentials; emails `example.com` only; legacy product name only in docs prose and ignore patterns; no network calls in application code |
| Tests not vacuous | PASS | Six mutations on a scratch copy each produced failures |
| Deferred maintainer-side tools present | PASS | `tools/discovery/`, `tools/host-check/` with README procedures |

Not verified in M0: `.htaccess` behaviour on a real Apache server (not installed in the build environment; covered later by the host check); manual screen-reader pass; host-check parse compatibility on PHP 7.4.

## 3. Work packages

| ID | Milestone | Model tier | Owned files | Status | Branch | Reviewer | Review result |
|---|---|---|---|---|---|---|---|
| PRE-1 | pre | design | `docs/build/context-brief.md` | done (pushed) | assigned branch | orchestrator | accepted |
| M0-D1 | M0 | design | `docs/specs/M0-scaffolding.md`, `docs/decisions/0008-*`, `docs/decisions/0009-*` | done (pushed) | assigned branch | second design-tier reviewer | READY FOR IMPLEMENTATION after 13 fixes |
| M0-T1 | M0 | design tier | `tools/discovery/*`, `tests/tools/DiscoverStructureTest.php` | done (pushed) | assigned branch | design-tier privacy reviewer | APPROVE after 4 rounds (round 3 escalated to default-deny redesign); open nit: digit-run exemption uncapped |
| M0-T2 | M0 | implementation | `tools/host-check/*`, `tests/tools/HostCheckTest.php` | done (pushed) | assigned branch | design-tier security reviewer | APPROVE after 2 rounds; PHP 7.4 parse compatibility not executed (no 7.4 binary) |
| M0-WP1 | M0 | implementation | manifest, `.gitignore`, `.htaccess` files, PHPUnit config, lint, CI, base tests (security-critical) | done, merged, pushed (incl. test-hardening follow-ups) | merged | design tier | APPROVE (design-tier security review); Apache behaviour unverified (not installed) |
| M0-WP2 | M0 | implementation | SPDX, PHP rules, licence, layer, ADR tests | done, merged, pushed | merged | implementation tier | APPROVE after 2 rounds (round 1: licence column not checked; walker scanned ignored files) |
| M0-WP3 | M0 | mechanical | `tokens.css` | done, merged, pushed | merged | implementation tier | APPROVE (byte-identical to spec Appendix A) |
| M0-WP4 | M0 | mechanical | fonts, licences, `fonts.css`, `docs/dependencies.md` | done, merged, pushed | merged | implementation tier | APPROVE (12 checksums verified; one optional nit in `docs/dependencies.md` rules bullet) |
| M0-WP5 | M0 | implementation | request pipeline (security-critical) | done, merged, pushed (incl. security follow-ups) | merged | design tier | APPROVE; follow-ups re-verified by the same security reviewer |
| M0-WP6 | M0 | implementation | templates, strings, `app.css`, shell tests (security-critical) | done, merged, pushed | merged | design tier | APPROVE after 2 rounds (round 1: template rules test bypassable by dynamic calls; shipped output clean; four themes verified in a browser) |
| M0-WP7 | M0 | implementation | design asset tests | done, merged, pushed (incl. follow-ups) | merged | implementation tier | APPROVE |
| M1-D1 | M1 | design | `docs/specs/M1-*.md`, `docs/design/m1/*`, ADRs 0010 to 0012, `docs/formats/tiffin.md` | done, merged, pushed | merged | M1-R1 (design tier) | pending |
| M1-R1 | M1 | design | fixes to the D1 files only | done, merged, pushed (`c543e46`) | merged | n/a | READY AFTER LISTED FIXES; 8 fixes made; 13 judgement calls ruled below |
| M1-D2 | M1 | design (D1 author resumed) | apply rulings J1 to J13 to the M1 spec files | done, merged, pushed (`a1714b8`) | merged | orchestrator check | accepted, including P58's extra allowed value types |
| M1-WP0 | M1 | implementation | per spec (depends on none; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE; follow-up round fixed trailing-newline acceptance in all 19 anchored patterns of the discovery tool (pre-existing), re-reviewed APPROVE |
| M1-WP1 | M1 | implementation | per spec (depends on none; security-critical: no) | implemented (`0b6dfa1`, worktree, unpushed; 1346 tests OK per author); round 1 fixes in progress | agent worktree | implementation tier | round 1 REQUEST CHANGES (blocking: `Fs::makeDir` accepted an existing directory; should-fix: `isInside` with `..`/NUL beyond existing ancestor, `writeNew` through dangling link, database file mode, T-REPO-9 alias bypass, control-character set) |
| M1-WP8 | M1 | implementation | per spec (depends on none; security-critical: yes) | in progress (worktree, unpushed) | agent worktree | | |
| M1-WP2 | M1 | implementation | per spec (depends on WP1; security-critical: yes) | not started | | | |
| M1-WP3 | M1 | implementation | per spec (depends on WP1; security-critical: yes) | not started | | | |
| M1-WP4 | M1 | implementation | per spec (depends on WP1, WP3; security-critical: yes) | not started | | | |
| M1-WP6 | M1 | implementation | per spec (depends on WP2; security-critical: yes) | not started | | | |
| M1-WP5 | M1 | implementation | per spec (depends on WP2, WP4; security-critical: yes) | not started | | | |
| M1-WP7 | M1 | implementation | per spec (depends on WP2, WP6, WP8; security-critical: yes) | not started | | | |
| M1-WP9 | M1 | implementation | per spec (depends on WP6, WP7, WP8; security-critical: yes) | not started | | | |
| M1-WP10 | M1 | implementation | per spec (depends on WP5, WP7, WP8; security-critical: yes) | not started | | | |
| M1-WP11 | M1 | implementation | per spec (depends on WP5, WP7, WP8; security-critical: no) | not started | | | |
| M1-WP12 | M1 | implementation | per spec (depends on WP9, WP10, WP11; security-critical: no) | not started | | | |

## 4. Provisional decisions awaiting maintainer confirmation

| # | Item | Provisional resolution | Recorded in |
|---|---|---|---|
| P1 | ui-design-plan section 9, items 1 to 5 | Adopt the "Proposed resolution" column as written | to be recorded in M0/M2 specs |
| P2 | Trash retention (plan Q3) | Items stay until an Admin empties trash; no automatic purge | to be recorded in M1 spec |
| P3 | Font payload (plan Q9) | Measure in M0; if four families exceed 2 MB as WOFF2 Latin subsets, ship Inter and Atkinson Hyperlegible, document others as optional downloads | to be recorded in M0 spec |
| P4 | Undesigned screens (ui-design-plan 6.2) | Designed from existing tokens and shell before each milestone; added to `docs/design/` | per-milestone specs |
| P5 | Non-Apache hosting, desktop packaging (plan Q6, Q7) | Out of scope for v1 | this ledger |
| P6 | XLSX library | Adopt OpenSpout if license file confirms MIT and PHP 8.3 is supported; otherwise select an alternative via ADR | to be recorded in M3 spec |
| P7 | Code layout, namespaces, URL shapes, routing, CSP, CI (ADR 0008) | As written in ADR 0008; URL shapes and the Core/Web namespace split are the costly-to-reverse parts | ADR 0008 |
| P8 | `composer.lock` | Not committed (`"lock": false`) because lock files embed package author names and emails; dev tool pinned to an exact version. Reviewer recommends the maintainer exempt `composer.lock` as a named file before M3 | ADR 0008 decision 12 |
| P9 | Source Serif 4 | Shipped as the unmodified upstream WOFF2 (Reserved Font Name prevents shipping a subset under the same name); all four families ship (payload about 270 kB) | ADR 0009, M0 spec 10.5 |
| P10 | "Source code" link target | Uses the repository URL already present in the committed mock-up (contains the hosting organization's name); maintainer to confirm | M0 spec |
| P11 | Legacy importer plugin id, directory and UI label | Left open until M6 | ADR 0009 |
| P12 | CI action pinning | `actions/checkout@v4` by tag, not commit SHA (lookup blocked in the build environment) | M0 spec |
| P13 | Other brief section 12 gaps (import transaction vs chunking; sanitizer URL rule; high-contrast token values follow `tokens.css`) | Settled in M3 and M5 specs; tokens.css is the visual source of truth | ADR 0009 |
| P14 | Commit trailers | Commits carry the session-supplied attribution trailers (a co-author line with a no-reply address and a session link). Two reviewers flagged this against the repository rule on email addresses in commit messages; kept because the session requires them. Maintainer to decide; changing past commits would need a history rewrite | this ledger |
| P15 | Repository walk exclusions | `.claude/` (local agent worktrees) excluded from lint and repository tests in addition to the spec's list | WP1 follow-up |
| P16 to P47 | M1 design defaults (match criterion for undesigned screens, namespaces, 12-char base32 keys and generated SQL names, field storage, trash columns, snapshots via `VACUUM INTO` keeping 20 automatic, audit log in system DB, join-table relations, SHA-256 attachments, Admin-mediated password reset plus CLI, one-time setup code, Member permissions, sessions 7 d idle / 30 d absolute, throttling, password length, CSRF, URL shapes, data-directory search order, SQLite 3.35+, deactivate-not-delete people, split strings files, and others) | As listed with rationales in the spec | `docs/specs/M1-foundation.md` section 14 |
| P48 | Installer data-directory exposure test makes one HTTP request from the server to itself | Accepted: plan 3.2 and ADR 0001 require an HTTP test; not a third-party call; skipped when data is outside the web root | `docs/specs/M1-foundation.md` section 16 |
| P49 | Deferred from M1: snapshot restore, project deletion, audit-log screen, trash screen (M4), lone-Admin recovery without shell (M7) | Deferred | `docs/specs/M1-foundation.md` section 16 |
| P50 to P60 | Spec review rulings J1 to J13 (P38 amended to SQLite 3.35.5): WP8 ships a form-actions partial; last-Admin check and change in one system-DB write, repositories never open writes; shared text helper owned by WP1; `changeType` converts inside the transaction; SQLite floor raised to 3.35.5 (amends P38); setup code written before the system DB and regenerated if missing; ADR 0012 allows a web composition root; probe uses a local error handler; stale `creating` projects cleared after one hour; throttling trade-offs accepted; choice-conversion dedupe and truncation; confirmation re-post excludes `_csrf`; probe Host trust verified on the host check | Ruled by the orchestrator; applied by M1-D2 | `docs/specs/M1-foundation.md` section 14 |
| P61 | Control characters in names and text (`Core\Error\Text`) | Unicode categories Cc, Zl, Zp, Cf, except U+200C and U+200D | M1-WP1 review ruling; spec section 3.4 to be amended in as-built notes |
| P62 | Path-containment check beyond the nearest existing ancestor | `Fs::isInside` refuses NUL and `.`/`..` segments in the non-existent part (stricter than spec 4.3 algorithm) | M1-WP1 review ruling; spec as-built notes |

## 5. Blockers

Open: none.

Resolved: none.

Deferred maintainer tasks (raise only at end of build):

| Task | Status | Script / procedure |
|---|---|---|
| Legacy-format discovery (plan 6.4) | ready, reviewed | `tools/discovery/discover-structure.php`, procedure in `tools/discovery/README.md` |
| Reference shared host verification (plan Q2; M7 exit criterion) | ready, reviewed | `tools/host-check/host-check.php`, procedure in `tools/host-check/README.md` |

## 6. Usage log

| Time (UTC) | Event |
|---|---|
| 2026-11-11T22:29Z | Build started; window 1 start; resume reminder armed for 03:45Z |
| 2026-11-11 | Environment note: Composer dist downloads are refused by the network proxy and fall back to full git clones (several GB). Workaround: a trimmed PHPUnit install kept in the session scratchpad is copied into each worktree's git-ignored `vendor/` followed by `composer dump-autoload`. After a container restart this must be rebuilt (`composer require --dev phpunit/phpunit:<pinned>` in a scratch dir, delete `.git` dirs and the Composer vcs cache). Not a blocker. |
| 2026-11-11T23:5xZ | M0 closed. Maintainer instruction: stop after M0. Resume reminder deleted; no further action until instructed. |
| 2026-11-12 | Handover: standing rules written to `docs/build/orchestration.md`; branch verified ahead of `main` by 75+ commits, not behind, suite green. | Maintainer instruction: from M1 the design tier and the implementation tier use the same model; reviews and audits must still be done by a separate agent instance. |
| 2026-11-12T02:30Z | Window 2 start (new orchestrator session); M0 present on the assigned branch, which matches `main`; resume reminder armed for 07:46Z |
| 2026-11-12T02:5xZ | Offline PHPUnit 12.5.43 rebuilt (6.4 GB before trim, 17 MB after); `composer lint` 69 files 0 failed; `composer test` OK 1075 tests. M1-D1 dispatched |
| 2026-11-12T03:4xZ | M1-D1 merged (`54b24fc`), suite green; M1-R1 spec review dispatched |
| 2026-11-12T04:0xZ | M1-R1 merged (`c543e46`); rulings sent to the spec author (M1-D2); M1-WP0 dispatched |
| 2026-11-12T04:3xZ | M1-D2 merged (`a1714b8`); WP1 and WP8 dispatched; WP0 in review |
| 2026-11-12T05:1xZ | M1-WP0 merged after two review rounds; suite 1079 tests OK |

## 7. Carried forward to M1 (open nits from reviews, none blocking)

| Item | Location |
|---|---|
| Cap the digit-run exemption in the identifier heuristic (names like `int5550107` print verbatim; they do appear in the review list) | `tools/discovery/discover-structure.php` near line 257 |
| Fallback response sends a body on HEAD requests | `src/Web/Fallback.php` near line 54 |
| Template scanner should reject the pipe operator (`\|>`) before the PHP floor rises to 8.5 | `tests/web/TemplateRulesTest.php` |
| Template source scanner accepts `href="/\\host/"` (rendered-page assertion and URL generator already reject it) | `tests/web/TemplateRulesTest.php` near line 268 |
| Rules bullet should state the transitive package count clause | `docs/dependencies.md` line 11 |
| `@symlink` used in a test although the code base otherwise avoids the suppression operator | `tests/tools/HostCheckTest.php` near line 231 |
| Add a test forbidding nested `.htaccess` files with rewrite directives in release contents (`vendor/` scanned at release), and require `data/.htaccess` to use only a deny rule | M1 installer spec, M7 packaging |
| Make `/files` routes project-scoped | M1 spec (ADR 0008 URL shapes) |
| Static assets are served without CSP by Apache | M7 packaging |

Mapping (M1 spec): WP0 takes the digit-run cap, `@symlink`, dependencies bullet; WP7 the HEAD body; WP8 the template scanner items; WP6 the nested `.htaccess` test and deny-only data rule; `/files` scoping decided as P35; static-asset CSP stays in M7.

New carried-forward items (from M1-WP0):

| Item | Location |
|---|---|
| `@` suppression operator still used in maintainer tools (about 13 and 20 uses) | `tools/discovery/discover-structure.php`, `tools/host-check/host-check.php` |
| README describes the digit-run rule less precisely than the code (four-digit cap on type-like names) | `tools/discovery/README.md` |
| Discovery tool: replace the `sqlite_` prefix exemption with an allowlist of real internal names (`sqlite_sequence`, `sqlite_stat1` to `4`, `sqlite_master`, `sqlite_schema`, `sqlite_temp_*`, autoindex via recursion); only hand-crafted files reach it | `tools/discovery/discover-structure.php` `IdentifierRedactor::internal()` |
| Discovery tool: `NameRedactor::extension()` fallback prints input verbatim (unreachable today); make it fail closed | `tools/discovery/discover-structure.php` near line 154 |
| Newline-in-file-name test assumes a POSIX filesystem (CI is Linux) | `tests/tools/DiscoverStructureTest.php` |
