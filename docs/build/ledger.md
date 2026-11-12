# TiffinDB Build Ledger

Single source of truth for the v1 build. Updated and pushed after every state change. Contains no personal data.

## 1. Current state

| Item | Value |
|---|---|
| Milestone | M1 in design |
| Phase | M1 paused by the maintainer; no agents running. Merged: WP0, WP1, WP3, WP8. Preserved unmerged: WP2 (`-wip-wp2`, round 3 fix awaiting verification), WP4 (`-wip-wp4`, awaiting review) |
| Window start | 2026-11-12T02:30Z (window 2) |
| Resume reminder | none (deleted for the maintainer's pause; the maintainer resumes the build) |
| Next action | Paused. On the maintainer's resume: (1) WP2: have the design-tier reviewer verify the round 3 takeback fix (`f9030b5`), then merge; (2) WP4: dispatch the design-tier review of `Identifier`, every SQL string and the nine author choices in its report (help-text key, checkbox and rating conversions, removed-choice rewrite, untested `updateField` retry, `valueCount`, `Trash::list` arrays, trash not touching project meta), then merge; (3) dispatch WP6 after WP2 merges, then WP5 and WP7. Review economy (maintainer instruction): nit-only follow-ups are verified by the orchestrator (test run and diff read) instead of resuming the reviewer; security fixes still go back to the reviewer; browser checks only in WP12 and the exit audit. Stop when M1 closes; do not start M2. If resuming after a reclaim: rebuild the PHPUnit install and redo every unpushed package (check the worktree branches first; WP2 and WP4 work is on the `-wip-wp2` and `-wip-wp4` remote branches). |

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
| M1-WP1 | M1 | implementation | per spec (depends on none; security-critical: no) | done, merged, pushed | merged | implementation tier | APPROVE after 3 rounds (round 1: `makeDir` accepted existing dirs, path checks, links, modes, layer-test bypasses; round 2: comment bypass of transaction guard, untested snapshot cleanup) |
| M1-WP8 | M1 | implementation | per spec (depends on none; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after follow-up round (show-password reset on submit, scanner forbids meta/iframe/SVG animation and dynamic srcdoc etc., csrf() only in its partial, ENT_DISALLOWED, script only in head, 44 px link); verified by running |
| M1-WP2 | M1 | implementation | per spec (depends on WP1; security-critical: yes) | round 3 fix done (`f9030b5`); awaiting reviewer verification; preserved on remote branch `claude/magical-hamilton-isy74q-wip-wp2` (not merged) | wip-wp2 | design tier | round 2 REQUEST CHANGES for one regression: takeback not net-zero at the address limit (fixed in `f9030b5`, unverified); round 1 APPROVE with should-fix |
| M1-WP3 | M1 | implementation | per spec (depends on WP1; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 2 rounds (round 1: decimal numbers off by one bit through SQLite, precision limit, URL ports/characters/numeric hosts, email local parts) |
| M1-WP4 | M1 | implementation | per spec (depends on WP1, WP3; security-critical: yes) | implemented (`5154df5`, 2833 tests OK per author); preserved on remote branch `claude/magical-hamilton-isy74q-wip-wp4` (not merged); design-tier review not started | wip-wp4 | design tier (on resume) | |
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
| P63 | Show-password script also resets switched inputs to `password` and unticks the box on form submit | Extends M1-screens section 6 item 3 | M1-WP8 review ruling; spec as-built notes |
| P64 | Folder removal | `Fs::removeDir` removes an empty directory only, never follows links; no recursive removal; callers remove known files first | M1-WP1 review ruling; spec as-built notes |
| P65 | Login throttle reset | A bucket resets only when its last failure (not its window start) is over 900 s old and it is not blocked, so waiting out each block still doubles to the 15-minute cap; `login_throttle` gains `last_failure_at` (system schema v1); stale rows deleted when last failure is over a day old and not blocked | M1-WP2 ruling; spec section 6.6 as-built notes |
| P66 | WP2 additions accepted | Repositories refuse row changes outside `write()` (`SystemDatabase::requireTransaction()`); `Authenticator::signOut` writes its own audit entry (WP5 must not repeat it); failed sign-ins record the matched person; usernames trimmed before normalising; NUL refused in passwords | M1-WP2 report; spec as-built notes |
| P67 | Field-type validation strictness | URLs: userinfo refused, printable ASCII only (IDN as `xn--`), http/https only; emails: quoted local parts and `< > " ` \ ( ) , ; : [ ]` in the local part refused; apostrophe allowed (amended); addendum: `? # % & /` also refused in local parts | M1-WP3 rulings; spec section 8 as-built notes |
| P68 | Sign-in transaction shape (amends P51 for sign-in) | Three steps: short write that checks the throttle and pre-counts a failure; password verify with no transaction; short write that settles success or failure | M1-WP2 review ruling; spec section 6 as-built notes |
| P69 | Session resume | Plain read first; write transaction only to touch (over 300 s) or delete, re-checked inside | M1-WP2 review ruling |
| P70 | Throttle address buckets | Canonicalised with `inet_pton`/`inet_ntop`; IPv6 keyed by /64; IPv4-mapped IPv6 treated as IPv4; unparseable addresses used as given | M1-WP2 review ruling; amends spec 6.6 |
| P71 | Number magnitude | Limit is `10^(15 - decimals)` exclusive (at most 15 significant digits); applies to parse, check and min/max options; `fromStorage` snaps a float to `decimals` when within 4 ulp | M1-WP3 review ruling; amends spec 8 |
| P72 | URL characters and hosts | Refuse `" < > `` ` `` anywhere; authority must be host or bracketed IPv6 with port 1 to 65535; refuse hex, single-integer and short-dotted numeric hosts | M1-WP3 review ruling; amends spec 8 |

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
| 2026-11-12, 02:50Z to 04:25Z | M1-D1 merged (`54b24fc`), suite green; M1-R1 spec review dispatched |
| 2026-11-12, 02:50Z to 04:25Z | M1-R1 merged (`c543e46`); rulings sent to the spec author (M1-D2); M1-WP0 dispatched |
| 2026-11-12, 02:50Z to 04:25Z | M1-D2 merged (`a1714b8`); WP1 and WP8 dispatched; WP0 in review |
| 2026-11-12, 02:50Z to 04:25Z | M1-WP0 merged after two review rounds; suite 1079 tests OK |
| 2026-11-12, 02:50Z to 04:25Z | M1-WP1 merged; wave 2 (WP2, WP3) dispatched |
| 2026-11-12T04:4xZ | M1-WP8 merged; wave 1 closed |
| 2026-11-12T05:1xZ | M1-WP3 merged; WP4 dispatched |
| 2026-11-12T05:1xZ | Maintainer instruction: stop at the end of M1 (after the exit audit and checkpoint); do not start M2 until instructed |
| 2026-11-12T05:2xZ | Maintainer instructions: apply review-economy options 1 and 2; pause once WP4 is done and wait for the maintainer to resume. Resume reminder deleted |
| 2026-11-12T05:3xZ | Maintainer permission: unreviewed package work is preserved on side branches `claude/magical-hamilton-isy74q-wip-wp2` and `-wip-wp4` (merge into the assigned branch only after review). WP2 round 3 pushed there |
| 2026-11-12T05:3xZ | WP4 implemented and pushed to `-wip-wp4`; build paused, no agents running |

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

Brief notes for later M1 packages (from M1-WP8):

| For | Note |
|---|---|
| WP7, WP10, WP11 | The layout prints breadcrumb, flash notice and error summary (in that order) before the page; pages pass `breadcrumb`, `problems`, `errorTitle` and must not print those parts themselves |
| WP7 | CSRF token and Format reach the view via `View::withCsrfToken()` and `View::withFormat()`; `Format` takes a `Closure(): DateTimeImmutable` (web may not name `Core\Storage`), so `CoreServices`/`Context` pass `$clock->now(...)` |
| WP7 | Delete the temporary `home.preview.` entry in `RUNTIME_PREFIXES` with `templates/pages/home.php` and `strings/en/home.php`; `AppearanceTest` preview-link tests go through M0 `/` and need updating when `/` becomes a redirect |
| WP12 | Error summary heading is an `h2` before the `h1`; change to `p` if strict heading order is enforced |
| WP12, spec | Reconcile `field-error` (section 4.4 says it outputs the `p`; the caller writes it) and section 7 heading order with the `h2` summary before `h1` | `docs/specs/M1-screens.md` |
| WP6 | Treat a path `Fs::isInside` cannot resolve as not outside the document root (fail safe); replace files by `Fs::remove` then `Fs::writeNew` (`writeNew` never overwrites) | M1-WP1 review |
| Maintainer (P61) | Emoji tag-sequence flags and soft hyphens are refused in names; a leading BOM is reported, not trimmed | M1-WP1 review |
| WP12 | Add a test rendering `form-actions` without the `danger` key (mutation survivor) | `tests/web/ShellTest.php` |
| WP5, WP6, WP7 | Wrap flash set/take, installer writes and the reset script in `write()` (repositories refuse changes outside a transaction); do not record `auth.sign_out` again; WP7 ends the old cookie's session at sign-in | M1-WP2 report |
| WP4 | `updateField` normalises options: keep auto-number `next` and relation `relation`/`side` (types return `[]` for relation options) | M1-WP3 report |
| M2 | `Text::trim` removes PCRE whitespace incl. U+180E and U+0085 at value ends; email values must be escaped in HTML | M1-WP3 report |
| WP5 | Re-read the actor's role and active flag inside the operation's `write()` for people, role and grant changes (`Authorizer` trusts the role read at resume) | M1-WP2 review F7 |
| WP7 | End any previous session token when signing someone in | M1-WP2 review F9 |
| WP4 | `RatingType::fromStorage` does not check `max`; `updateField` must clear values above a lowered max (T-SCH-9) | M1-WP3 review N3 |
| M2 | Build `mailto:` hrefs with the local part `rawurlencode`d; escape every attribute with ENT_QUOTES | M1-WP3 review |
| WP6, WP7 | Call `LoginThrottle::wait()` inside `write()`; read session tokens via `IssuedSession::token()` (property no longer public) | M1-WP2 follow-up |
| URL rule refuses any host label starting `0x` (e.g. `0xford.example`); apply the hex test to the last label only and require hex digits | `src/Core/Schema/Type/UrlType.php` `looksLikeOtherIpv4` |
| `docs/formats/tiffin.md` section 11 says format upgrades run in one transaction; spec 7.2 (implemented) runs each step in its own transaction; correct the format doc | `docs/formats/tiffin.md` |

