# TiffinDB Build Ledger

Single source of truth for the v1 build. Updated and pushed after every state change. Contains no personal data.

## 1. Current state

| Item | Value |
|---|---|
| Milestone | M2 CLOSED; M3 design reconciliation next (was: M1 CLOSED; M2 design in progress (session plan `docs/build/M2-M3-session-plan.md`, run without a stop to the M3 exit audit) |
| Phase | Phase 5: M3 implementation. M2 CLOSED; M3-D1, M3-R1, M3-D2 merged (P110 to P141 recorded). Waves per `docs/specs/M3-io.md` section 16: wave 1 WP1, WP3, then WP0; wave 1b WP2 (after WP1), WP7; wave 2 WP4, WP6, then WP5; wave 3 WP8; wave 4 WP9, WP10 (merge one at a time: both edit `TemplateRulesTest.php` pending lists); wave 5 WP11 |
| Window start | 2026-11-19 (window 5) |
| Resume reminder | none armed (orchestrator has no scheduling tool beyond a one-shot; resume is by maintainer instruction) |
| Next action | Dispatch M3 wave 1; merge each package, run its security review (every package except WP6 and WP11 is security-critical); stage OpenSpout 5.2.2 for WP0 (scratchpad `openspout-install/vendor`; rebuild per orchestration 9 if reclaimed). End with M3-X audit and the single end-of-session notification (plan 11). If resuming after a reclaim: rebuild the offline PHPUnit install (orchestration 9) |

### Phase 0 record (2026-11-19)

Baseline on the integration branch: `composer test` OK (4777 tests, 1 skipped), `composer lint` 348 files 0 failed, as at M1 close.

XLSX dependency probe (metadata read from Packagist; no code installed): `openspout/openspout` declares licence MIT. Releases v5.3 and later that were checked (v5.4.1, v5.5.0, v5.10.0, v5.15.x) require PHP `~8.4.0` or newer and do NOT admit PHP 8.3. Releases that admit 8.3: v5.0.0, v5.1.1, v5.2.2 (PHP `~8.3.0 || ~8.4.0 || ~8.5.0`) and v4.32.0. Required extensions: `dom`, `filter`, `libxml`, `xmlreader`, `zip` (v4.x also `fileinfo`). Not checked: v5.3.0 and v5.4.0 floors, transitive packages (none declared), the licence file at a tag. Consequence for M3-D1: ADR 0016 must pin a release admitting 8.3 (candidate v5.2.2 or v4.32.0, subject to a tag-level licence check) and note the extension additions (`dom`, `filter`, `libxml`) for the installer check and `tools/host-check/`. No download blocker was seen.

## 2. Milestones

| Milestone | Status | Exit-criteria results |
|---|---|---|
| M0 | CLOSED 2026-11-11 (independent exit audit) | See M0 exit results below |
| M1 | CLOSED 2026-11-17 (independent exit audit; listed fixes F-1 and F-3 merged) | See M1 exit results below |
| M2 | CLOSED 2026-11-19 (independent exit audit; no blocking findings) | See M2 exit results below |
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

### M1 exit results

Independent design-tier exit audit on the integration branch (all M1 work packages merged). Suite: `composer test` OK (4777 tests, 1 skipped: no comma-decimal locale installed); `composer lint` 348 files, 0 failed. Full table with evidence kept in the session scratchpad (not committed).

| Criterion | Result | Evidence |
|---|---|---|
| Suite and lint | PASS | As above |
| Live end-to-end (built-in server, data folder outside `public/`) | PASS | Setup (wrong code 422, nothing echoed, `/setup` 404 after install); Admin creates "Home Inventory" with Items, Rooms and a relation field; Member forced password change, sees only the shared project, schema/people/sharing POSTs 403, other project 404; sign-out CSRF and cross-site/same-site refusals; old cookie dead; 6th sign-in try 429 with `Retry-After: 30`, then success after the wait |
| Visual V-1, V-2, V-4 to V-8 | PASS | 150 pages: 1360 and 390 px light, three other themes at 1360 px, 320 px Largest; every M1-screens 8.1 measurement within 1 px; colours equal the tokens; only 8.3 and P83 differences; no horizontal scroll; local fonts only; no off-host requests |
| Visual V-3 installer | PASS; step 1 failing state DEFERRED to `SetupScreenTest` | Step 1, step 2 inconclusive and exposed (produced live with a multi-worker router), step 3 with errors |
| Repository rules | PASS | SPDX on every source file; no personal data or legacy-product material in application code; the only socket call is the loopback probe; no `@` in application code; no inline script or style; `.gitignore` complete; dependencies match `docs/dependencies.md` |
| Security baseline (plan section 7, M1 scope) | PASS | Argon2id; only session-token hashes stored; planted cookie deleted; deactivation ends sessions; security headers on 2xx/3xx/4xx; authorization in core; escaping, traversal, Host and 2 MB password spot checks held |
| Probe positive control (P75) | PASS | Exposed verdict produced live; control and probe files removed afterwards |
| Data portability | PASS | Project file has the documented `application_id`, `user_version`, meta rows, names per `docs/formats/tiffin.md` section 3; `integrity_check` and `foreign_key_check` pass; a snapshot opens on its own. Opened read-only with the plain SQLite library (the `sqlite3` CLI is not installed) |
| Tests not vacuous | PASS | 10 of 10 mutations caught (core and web) |
| Plan section 8 and spec 1.3 exit criteria | PASS | Headless core tests; Admin can create a project and a table; screens match the mock-ups; trash, snapshots and audit log working |

Findings: F-1 (should-fix, fixed: front-controller tests use a scratch app with a temporary `data_dir`) `tests/web/FrontControllerTest.php` not hermetic when a data folder exists next to the checkout; F-2 (nit) no `Origin` check when `Sec-Fetch-Site` is absent (carried forward); F-3 (nit, fixed) duplicate `check` class in `password-toggle.php`. Final suite after fixes: 4777 tests OK (1 skipped), lint 348 files 0 failed. As-built notes appended to both M1 specs; `docs/formats/tiffin.md` section 11 corrected (one transaction per upgrade step).

Post-close CI fix (F-4, should-fix, fixed): the hosted CI runner failed one test, `KernelTest::testBadLocalConfigurationIsRefused` (time zone case). The runner's PHP loads Xdebug, whose develop mode turns PHP 8.3's `DateInvalidTimeZoneException` into an `Error` ("Cannot create dynamic property ...::$xdebug_message"), which `Kernel::checkLocalConfig()` did not catch. The time-zone check now catches `Throwable`, so a bad `timezone` in `config/local.php` is refused on such hosts too. Not reproducible locally (no Xdebug); verified by the hosted CI run on the PR.

Not verified in M1: `.htaccess` behaviour on a real Apache server (host check); manual screen-reader pass.

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
| M1-WP2 | M1 | implementation | per spec (depends on WP1; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 3 rounds (round 2: takeback not net-zero at the address limit; round 3 fix `f9030b5` verified at every boundary and under interleaving, tests fail when the fix is reverted); two nits carried forward |
| M1-WP3 | M1 | implementation | per spec (depends on WP1; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 2 rounds (round 1: decimal numbers off by one bit through SQLite, precision limit, URL ports/characters/numeric hosts, email local parts) |
| M1-WP4 | M1 | implementation | per spec (depends on WP1, WP3; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 3 rounds (round 1: scanner bypasses, vacuous WAL bytes check, trash meta, vacuous auto-number test, untested retry; round 2: remaining dynamic-call bypasses, root-cause redesign to default-deny scanner; round 3 APPROVE). Nit follow-ups (P77, computed array callables except as bound parameters, return values or destructuring, Reflection, include) verified by orchestrator |
| M1-WP6 | M1 | implementation | per spec (depends on WP2; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 2 rounds (round 1: forged Host made an exposed folder `safe`, fixed by positive control P75; T-REPO-10 bypasses; setup-code removal order; read deadline; configured-folder keys). Test nits (distinct control nonce, public cleanup guards, redundant deadline check removed) verified by orchestrator |
| M1-WP5 | M1 | implementation | per spec (depends on WP2, WP4; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE (round 1): IDOR, Member-on-Admin, stale-actor and last-Admin attacks refused by running; follow-ups (Member refusal provider over every Admin-only method, `requireCurrentPerson` active test, reset script clears throttle and notes inactive person, echo restored on SIGINT) verified by orchestrator |
| M1-WP7 | M1 | implementation | per spec (depends on WP2, WP6, WP8; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 2 rounds (round 1: test gaps only, runtime confirmed by curl; P79 same-site refusal and read-first flash; round 2: runtime fixes confirmed, seam-test alias bypass). Alias fix verified by orchestrator; the kernel's `new $class($context)` controller dispatch (class from the route table) is the one allowed dynamic `new` |
| M1-WP9 | M1 | implementation | per spec (depends on WP6, WP7, WP8; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE after 2 rounds (round 1: F1 blocker, first-Admin 500 on real services from a cached install state, fixed by a once-per-request re-check in `CoreServices::setFlash` and a real-services end-to-end test; controller state tests; port-rule docblock). Round 2 verified live: 303, cookie, flash once, `/setup` 404, no session before an Admin exists |
| M1-WP10 | M1 | implementation | per spec (depends on WP5, WP7, WP8; security-critical: yes) | done, merged, pushed | merged | design tier | APPROVE (round 1): identical failure responses (dummy verify), cookie attributes, sign-out, must-change gate, Member and IDOR attempts refused, escaping, `no-store`; 14 of 14 mutations caught. Nits (header and cookie comparison; malformed list fields give 400) verified by orchestrator |
| M1-WP11 | M1 | implementation | per spec (depends on WP5, WP7, WP8; security-critical: yes, templates) | done, merged, pushed | merged | design tier | APPROVE (round 1): hostile values escaped on 15 pages, Member and IDOR attempts refused, confirmation tampering re-validated, no 500 on malformed input; follow-ups (title-type and group drift test, `options.choices` mapping, radio `aria-invalid`, relation escaping test) verified by orchestrator; all-problems-at-once for a tampered link cardinality not done (needs a core validate-only call) |
| M1-WP12 | M1 | implementation | per spec (depends on WP9, WP10, WP11; security-critical: no) | done, merged, pushed | merged | implementation tier | APPROVE after round 1 fixes (Member POST now passes CSRF and is refused by authorization, orchestrator re-ran the mutation and it fails; T-SCR-1 visits the failing-check installer state; whole-tree unchanged check; probe use asserted zero). Visual check: 157 of 159 pass; D1 fixed by WP8d; D2 to D6 accepted (P83) |
| M1-WP8b | M1 | implementation | WP8 files only: string-use scan counts literal keys passed to partials (names derived from each partial's `t()` calls) and literal defined keys in controllers; `notice` `heading` 2/3; `form-actions` `submitParams`, `secondary`, `size`, `block`, `cancelLabel`/`cancelParams` (default output byte-identical); `fieldset.field` reset; `assertNoInlineCode` checks attributes and resource loads only (inline `style`/`script` elements still refused); `home.preview.` prefix removed | done, merged, pushed | merged | orchestrator | accepted after diff read (all output through `t()`/`url()`, literal class lists) and test run |
| M1-WP8c | M1 | implementation | `HtmlAssertions::assertForm` accepts a text box named by a non-empty `aria-label` (still needs `name`) | done, merged, pushed | merged | orchestrator | accepted after test run |
| M1-WP8d | M1 | implementation | `app.css`: radio-card text may shrink; container query below 12.5rem thins card padding and stacks the radio above its text (whole words fit at 320 px, Largest text) | done, merged, pushed | merged | orchestrator | accepted after diff read; author browser-verified 320/390 px Largest and unchanged 390/1360 px Standard screenshots |

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
| P73 | Trash operations touch project meta | `Trash::moveToTrash`, `restore`, `empty` update `updated_at`/`updated_by` in the same transaction, only when a record actually changed; `FormatMigrations` test seam takes closures and is `@internal`; `Trash::empty` gains a `$userKey` parameter (keeps format doc 4.1 true) | M1-WP4 review ruling; amends spec 9.7 |
| P74 | WP4 author choices accepted | Checkbox conversion (unticked = empty; text target parsed leniently; `required` forced false for checkbox targets, also on `changeType`); rating conversion (lenient parse against target max, null parse counts as failure needing confirmation); removing a choice rewrites values matching a remaining label case-insensitively to that label and clears the rest, by record id (amends spec 7.4 "set to null"); `valueCount` counts non-null values, ticked boxes, records with at least one link on this side or at least one attachment, trashed records included; `Trash::list` returns documented arrays (typed object may come in M4) | M1-WP4 review rulings; spec 7.4 and 9.7 as-built notes |
| P75 | Exposure probe positive control | The probe first writes a per-run control file with its own nonce into `public/` and must fetch it (2xx, nonce present) at `<basePath>/<name>`; if `public/` is not writable it fetches a shipped static asset and requires a byte-identical body; a failed control makes the verdict `inconclusive` (which blocks). Residual risk: with the static fallback, a second identical TiffinDB copy on another virtual host of the same address could pass the control | M1-WP6 review F1; amends spec 10.4 |
| P76 | Installer details accepted | `Installer` optional 7th constructor argument (system-DB factory test seam); `ProbeTarget` validation and `tryCreate()`; existing data folder: `projects/` must be empty, symbolic links and foreign `.htaccess` refused; session started inside the first-Admin transaction, audit before best-effort code-file removal, leftover code removed once installed (amends 10.5 order); `setupCodeLocation`/`ensureSetupCode` throw outside `NeedsAdmin`; direct capped reads under `ErrorTrap` until `Fs` gains read helpers; setup-code throttle is global only (`$clientAddress` unused); a probe run may make up to three requests plus the control (rewords P48 "one HTTP request") | M1-WP6 review rulings |
| P77 | `SchemaEditor` snapshot callback type | Constructor takes `\Closure(SnapshotInfo): void $onSnapshot` (was `callable`), so the SQL scanner needs no allowlist | M1-WP4 round 2 review ruling; amends spec 7.4 |
| P78 | Core operations details accepted | `Operations` optional 7th constructor argument (test seam inside the write; add a repository test that `src/` and `public/` pass exactly 6 arguments once the web composition root exists); T-APP-30 shown by repositories refusing to run outside a transaction instead of a spy Database; `changeOwnPassword` requires the actor's own live session, takes back the pre-counted failure when the current password was right, clears the bucket on success; deactivate/reactivate of a person already in that state is a no-op, not audited; `projectAccess` lists all projects for an Admin; `setSharing`/`setProjectAccess` keep grants of non-Member or inactive people and on projects being created (demoting a former Member restores their old grants); direct file-system calls and literal system-DB SQL in `ProjectOperations` until `Fs`/`ProjectRegistry` helpers exist. Stale-actor re-check covers people, role, grant and create changes only; other Admin operations rely on `resume()` (one-request window) | M1-WP5 review rulings |
| P79 | Same-site requests and flash reads | Unsafe-method requests with `Sec-Fetch-Site: same-site` are refused like `cross-site`; cookie names keep `^[a-z_]{1,32}$` (no `__Host-` prefix, which would need `Secure` and `Path=/` and break base-path scoping); residual risk: sibling subdomains on the same registrable domain can plant cookies and are trusted. `takeFlash` reads first and writes only when a flash exists | M1-WP7 review ruling; amends M1-screens 2.5 |
| P80 | Installer probe port | The probe target is null (inconclusive) unless `SERVER_PORT` is the scheme default or equals the port in the Host header; this gives no protection under Apache `UseCanonicalName Off` (it only fails closed when the Host header has no port), kept because it fails closed; the port-scan oracle (WP6 F2) stays open; closing it needs an operator-configured port (config key), deferred to M7 packaging and the host check | M1-WP9 report |
| P81 | Add-person project list | Built from `people()->projectAccess($actor, $actor->userKey)` (relies on P78: an Admin's list holds every project); display only, core validates keys | M1-WP10 review ruling |
| P82 | Schema confirmation scope | Confirmation is a yes/no flag, not bound to the count shown; if records change between the confirmation page and the re-post, more values can be cleared than shown (Admin's own data, CSRF-bound). Later fix: post back the expected count and have the core ask again when the real impact is larger. Confirmation strings use plain numbers with a singular body (departs from spec 11 `count.records.*` wording) | M1-WP11 review |
| P83 | Visual deviations from the mock-ups accepted in M1 (add to M1-screens 8.3) | Project-name links and footer links keep a 44 px minimum height (touch-target accessibility; cards 14 px and footer 20 px taller than the mock-up); project cards are sorted by name per `sign-in-and-projects.md` 3.1, not in the mock-up's order; sign-in content sits at the top per `components.md` section 1, not vertically centred; the sign-in wordmark uses Atkinson per the brand README, not the mock-up's Inter. Installer step 2 "exposed" cannot be produced on the PHP built-in server; verified only on the Apache host check | M1-WP12 visual check (D2 to D6) |
| P84 | Paging | 50 records per page; record Previous and Next follow the list's sort, filter, and search carried in the query string; out-of-range pages clamp | ADR 0013 d2 |
| P85 | Lost-update guard | Hidden version token (16 hex characters of SHA-256 over the row and its link ids) instead of `_updated_at`; mismatch gives a 409 conflict page with both versions and a re-post | ADR 0013 d6 |
| P86 | Autosave | Not built; explicit Save; "Last saved {when}" replaces "All changes saved"; after Save the record page reloads with a notice (the mock-up's Save went to the list) | ADR 0013 d7 |
| P87 | Search | ASCII case-insensitive substring (`LIKE`) over text, long text, choice, web address, email, phone, and linked titles one level deep; 1 to 200 characters; across tables 10 hits per table; no full-text index | ADR 0013 d5 |
| P88 | Sort | Every type but attachment; empties last both ways; ties by id; choice by list order; relation by lowest linked title; default title A to Z else order added; plus newest, oldest, recently changed | ADR 0013 d3 |
| P89 | Filter | One condition from the query string; choice, checkbox, relation, date, date-time; condition object reused by M4 collections; invalid filters reported, list unfiltered | ADR 0013 d4 |
| P90 | List columns | Title field first, then up to six more fields in field order, skipping long text; no column configuration in M2 | ADR 0013 d9.1 |
| P91 | Form format 1 | Vocabulary of `docs/formats/tiffin.md` 12.1; XML syntax parsed with `XMLReader`; images by attachment reference only; links parsed, shown as text until M5; form CSS reserved and ignored | ADR 0014 d1 |
| P92 | Showing stored forms | Default-deny parse into a node model rendered by templates; rejected forms replaced by the generated layout; no safe-HTML type in M2 (deviates from the session plan) | ADR 0014 d4 |
| P93 | Generated forms | Details, Files, one section per many-link relation; long text and rating full width; English legends as data; regenerated in the schema transaction while `generated` is 1; edited forms gain and lose slots only | ADR 0014 d3 |
| P94 | Version 2 upgrade | Adds `_tiffin_forms` only; tables have no stored form until their fields next change | ADR 0014 d2 |
| P95 | Columns on wide screens (A1) | `Details` laid out as a 300 px minimum auto-fit grid; record page 1120 wide | ADR 0014 d6 |
| P96 | Generated ids in templates | New scope helper `id(prefix, part...)` producing validated tokens for `id`, `for`, `aria-describedby`, `aria-labelledby`, `form`, and fragment links; amends the M1 rule that ids are literal | ADR 0013 d9.2 |
| P97 | Plural strings | Placeholder `{name | plural}` with `#` for the formatted number, English one/other only; `Format::count` stays |
| P98 | Move to trash | Immediate, with an Undo button in the next page's notice; no confirmation page; a trashed record's address shows a Restore page | ADR 0013 d8 |
| P99 | Warning token | Light `--warn` `#703D00` (7.61:1 on the tint); no other published figure changes | ADR 0013 d9.4 |
| P100 | Display link and settings route | Display link on every signed-in page (reverses that part of P41); `/settings` with Save and "Back to standard" (a `formaction` reset); live preview script; mock-up wording about black-and-white strong contrast changed | ADR 0013 d9.5 |
| P101 | Record form controls | Required fields marked "(required)"; choice as a select with "Not chosen"; rating as radios with "Not rated"; numbers and money as text inputs with `inputmode`; currency label adds the currency name or code | ADR 0013 d9.6 |
| P102 | List controls | Visible Apply button; the sort menu never submits on change | ADR 0013 d9.7 |
| P103 | Sidebar in M2 | Collections, Import, Export left out; trash count shown as text; "Tables and fields" link added; `/projects/{p}` opens the first table's list (completes P43) | ADR 0013 d9.8 |
| P104 | Record ids from URLs | Passed to the core as strings; anything but `^[1-9][0-9]{0,18}$` is not found | ADR 0013 d9.9 |
| P105 | Required and links | Requiredness not enforced for relation and attachment fields until they can be edited (M4) | ADR 0013 d7 |
| P106 | Record writes touch project meta | Create, update, links, trash, and restore update `updated_at` and `updated_by` | ADR 0013 d7 |
| P107 | SQL composition | `Core\Query\Sql` fragments plus an extended scanner; `Sql.php` the only file joining variables into SQL text | ADR 0013 d9.10 |
| P108 | Work-package changes from the session plan | Plural strings moved from WP0 to WP5; P82 fix moved to WP8; the token value changed in the design, so WP1 is mechanical (tests only) | this spec 12 (process; ledger only) |
| P109 | `Origin` check | With no `Sec-Fetch-Site`, a POST whose `Origin` is present and not the request's own origin (or is `null`) is refused; requests with no `Origin` pass | ADR 0013 d9.11 |
| P110 | XLSX library (settles P6, ADR 0009 B5) | `openspout/openspout` 5.2.2, pinned exactly, no lock file; licence `MIT AND Apache-2.0` (two licence files at the tag; package metadata says MIT only); v5.3.0 also admits PHP 8.3 but was not chosen; v5.4 and later need PHP 8.4 | ADR 0016 d1, d2 |
| P111 | Extensions for XLSX | `dom`, `filter`, `libxml` reported as installer warnings; XLSX plugins unavailable without them; host check extended | ADR 0016 d3 |
| P112 | Import commit model (settles E1) | Read and prepare in chunks (2,000 rows or 5 s) into a per-job SQLite file under `<data>/staging/imports/`; one final transaction after an `import` snapshot; Continue button with an auto-submit script; no staging table in the project format | ADR 0015 d1 |
| P113 | Plugin layout and loading | `plugins/<kind>/<id>/plugin.json` plus `src/` under `TiffinDB\Plugin\<Kind>\<Id>\`; manifests never execute; enabled plugins loaded by a per-plugin autoloader on use; contract `Core\Io\Contract` API 1; CSV reader and writer in core | ADR 0015 d2, d3 |
| P114 | Plugin state | System schema version 2 table `plugins`; the five shipped plugins enabled by default, others disabled until an Admin enables them through a confirmation page | ADR 0015 d4 |
| P115 | Project format version 3 | `_tiffin_import_log` with a JSON `report` (counts and row numbers, never values); snapshot reason `import` | ADR 0015 d1; `docs/formats/tiffin.md` 4.7, 11.2 |
| P116 | Import limits | 50,000 rows, 200 columns, 20,000 characters per cell, uploads at most 64 MiB or the PHP limits, whichever is smaller | this spec 6.8 |
| P117 | Type inference | Rules of section 6.3: ISO dates only (as the M1 types), checkbox only for yes/no/true/false, currency by symbol or two decimals, choice for at most 20 repeated short values, link by a heading naming an existing table; rating offered, never proposed (deviation from the mock-up); new table item name is the lower-cased table name | this spec 6.3, 6.6 |
| P118 | Requiredness | Imports enforce no required fields (counted in the report); `Records::create` gains `requiredKeys`; record creation from a form requires only the required fields that form shows (reviewer-flagged point) | ADR 0017 d4 |
| P119 | Appending to an existing table | Columns map to existing fields only; no new fields; choice values not in the list emptied and reported; fields not mapped stay empty | this spec 6.4, 6.5 |
| P120 | Review warnings | Counted over the first 5,000 rows, labelled as such; the report has exact counts | this spec 6.4 |
| P121 | CSV dialects | Export: UTF-8 with BOM, comma, CRLF, heading row of field names, optional record-number column. Import: UTF-8, UTF-16 with BOM, Windows-1252 by TiffinDB's own decoder; delimiters comma, semicolon, tab, bar | this spec 8 |
| P122 | Formula-like cells | CSV keeps exact values by default; "Protect against spreadsheet formulas" prefixes `'` on text-like cells starting `= + - @`, tab, CR. XLSX always writes text as string cells and reads cached formula results | ADR 0016 d4; this spec 8.2 |
| P123 | Exports | Streamed from a staged file in the POST response; live records only; relation and attachment cells as `; `-joined titles and names; CSV one table, XLSX one table or all, JSON whole project | this spec 10 |
| P124 | Project archive | Zip with manifest, `VACUUM INTO` copy, attachments; no snapshots; import verifies, then rebuilds into a new project (keys and ids kept, new project key, no grants, source import log dropped, invalid values emptied, invalid forms dropped, forms regenerated so edited forms gain missing slots) | ADR 0017 d1 to d3 |
| P125 | JSON export format 1 | Whole project, live records, values per type with currency as a decimal string, relation and attachment values as id arrays | ADR 0017 d5 |
| P126 | Uploads | `Request::$files` from PHP's upload array, single files only, checked with `is_uploaded_file`; a POST larger than `post_max_size` gets a 413 page before the CSRF check | part 2 section 2 |
| P127 | Staging area | `<data>/staging/{imports,exports,archives}/<key>/`; recursive removal allowed only there (amends P64); swept after 24 h (imports) or 1 h | this spec 5.1 |
| P128 | Audit actions | `import.completed`, `export.created`, `archive.exported`, `archive.imported`, `plugin.enabled`, `plugin.disabled` (amends ADR 0012 d4) | this spec 13.6 |
| P129 | M3 routes | Part 2 section 3 | part 2 |
| P130 | Joined mode and DDL | `SchemaEditor::within()`, `Records::within()`; DDL text in one class `Core\Schema\Ddl` shared by the editor and the archive rebuild | this spec 6.6, 7 |
| P131 | Layers | `Core\Io` may use Schema, Query, Forms, Storage, Error; plugins only `Core\Io\Contract`, their namespace, PHP, `OpenSpout\` | ADR 0015 d5 |
| P132 | Navigation | Sidebar Import and Export for people with `io.use`; Plugins tab for Admins; "Import a project archive" on the project list | part 2 section 4 |
| P133 | Plugin text | Names, setting labels, `readAs`, descriptions supplied by plugins are escaped plain text, not message keys (built-in plugins write English) | this spec 3 |
| P134 | Import jobs | Bound to the project and the Admin who started them; anyone else gets "not found" | this spec 5.2 |
| P135 | CSV formula protection (ruling) | "Protect against spreadsheet formulas" stays off by default; the export page tells people who will open the file in a spreadsheet program to choose XLSX, whose text cells are never formulas | this spec 8.2; `docs/design/m3/export.md` |
| P136 | Archives over the upload cap (ruling) | The 64 MiB upload cap stays; a larger archive is restored by hand into a project created in TiffinDB, by the documented procedure (`docs/formats/tiffin.md` section 13.4); no command-line tool in v1 | ADR 0017 d2; format 13.4 |
| P137 | "Check again" on the review page (ruling) | Core method `ImportOperations::recheck` stores the posted target and shape-checked column values without `MappingCheck`, so switching the target table never shows errors for the old table | this spec 13.1 |
| P138 | Currency proposal (ruling) | A symbol in any cell, or a heading containing a money word (price, cost, value, amount, total, paid, spent, fee, budget, balance, money, salary, wage), proposes currency; two decimals alone propose number with 2 decimals | this spec 6.3.1 |
| P139 | XLSX rows per chunk (ruling) | Every row a plugin yields, empty or not, counts towards the chunk (at most 2,000 per `rows()` call) and towards a cap of 100,000 yielded rows per import | this spec 6.8, 9.2 |
| P140 | Records after a schema change in one transaction | The committer builds a new `Schema` and `Records` inside the transaction after creating the table (as built, `Records` keeps the `Schema` it was given); `SchemaEditor`'s joined copy reads a fresh `Schema` per call | this spec 6.6 |
| P141 | Pending strings for wave-4 screens | `TemplateRulesTest` gains two lists, `PENDING_IMPORT` and `PENDING_EXPORT`, merged into `PENDING_KEYS`; WP3 fills them with the shared keys only WP9 or WP10 templates print, and WP9 and WP10 each empty only their own list (separate lines, so their edits do not conflict) | part 2 sections 6, 11 |

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
| 2026-11-17T05:50Z | Window 3 start (maintainer resume); integration branch now `claude/exciting-maxwell-l2yieq`; offline PHPUnit rebuild started; resume reminder armed for 11:06Z |
| 2026-11-17, 06:00Z to 07:40Z | PHPUnit rebuilt (17 MB); suite on integration branch 2528 tests OK (1 skipped: no comma-decimal locale installed), lint 156 files 0 failed. Dispatched: WP2 round 3 verification (design tier), WP4 review (design tier) |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP2 merged (APPROVE); suite 2960 tests OK (1 skipped), lint 201 files 0 failed; WP6 dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP4 review: REQUEST CHANGES (no blockers); rulings P73, P74; fix agent dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP6 implemented (unpushed); orchestrator re-ran suite 3145 OK, lint 222 files OK; design-tier security review dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP4 round 1 fixes done (unpushed); orchestrator re-ran suite 2879 OK, lint OK; sent back to the WP4 reviewer for verification |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP6 review: REQUEST CHANGES (F1 blocker, spec gap); rulings P75, P76; author resumed with fixes |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP4 round 2: REQUEST CHANGES (scanner only); root-cause redesign to a default-deny scanner sent to the fix agent |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP4 default-deny scanner done (unpushed); orchestrator re-ran suite 2937 OK, lint OK; sent to the reviewer |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP6 round 1 fixes done (unpushed); orchestrator re-ran suite 3168 OK, lint 223 OK; sent to the reviewer. On merge: add `/public/probe-*.txt` to `.gitignore` |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP4 APPROVED (default-deny scanner: 17 old and 28 new bypasses caught); nit follow-ups sent to the fix agent; ruling P77 |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP4 merged; suite 3397 tests OK (1 skipped), lint 227 files 0 failed; WP5 dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP6 APPROVED (positive control held against forged Host, echoing 404, catch-all 200, forged port, wrong basePath, fallback); test nits sent to author |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP6 merged; `/public/probe-*.txt` added to `.gitignore`; suite 3608 tests OK (1 skipped), lint 249 files 0 failed; WP7 dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP5 implemented (unpushed); orchestrator re-ran suite 3587 OK, lint 257 OK; design-tier security review dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP5 APPROVED; follow-ups (Member refusal provider, reset script clears throttle and warns on inactive person) sent to author; ruling P78 |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP5 merged; suite 3810 tests OK (1 skipped), lint 279 files 0 failed |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP7 implemented (unpushed); test-merged with integration (clean, 4142 OK, lint 286 OK); design-tier security review dispatched |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP7 review: REQUEST CHANGES (test-only); ruling P79; author resumed |
| 2026-11-17, 06:00Z to 07:40Z | M1-WP7 round 1 fixes done (unpushed); orchestrator re-ran suite 4295 OK, lint 288 OK; sent to the reviewer |
| 2026-11-17T07:4xZ | M1-WP7 merged; suite 4308 tests OK (1 skipped), lint 288 files 0 failed; wave 5 (WP9, WP10, WP11) dispatched |
| 2026-11-17, about 08:00Z | Usage limit hit (reset 08:50Z); WP9 (one commit, `c0dd2da`), WP10 and WP11 (uncommitted work) stopped in their worktrees |
| 2026-11-17T11:06Z | Window 4 start; worker restarted; the three agents resumed in place; resume reminder armed for 16:22Z |
| 2026-11-17T11:2xZ | M1-WP9 implemented (inlined partial markup because the strings-used scan counts only literal `t()` keys); WP8b follow-up dispatched to fix the scan; WP10 and WP11 told to use the shared partials |
| 2026-11-17T12:xxZ | WP10, WP11 implemented (each one failing string-use test pending WP8b); WP8b merged (suite 4359 OK, lint 291 OK); WP9, WP10, WP11 told to merge the integration branch and adopt the shared partials |
| 2026-11-17, window 4 | WP8c merged; WP9, WP10, WP11 updated for the shared partials and verified by the orchestrator; three design-tier reviews running in parallel |
| 2026-11-17, window 4 | M1-WP10 merged (suite 4472 OK, lint 307 OK). M1-WP10 APPROVED; nits sent to author. Process note: concurrent reviewers clashed in the shared scratchpad (`scratchpad/app`, `data`); future briefs must name a per-agent scratch subfolder and port |
| 2026-11-17, window 4 | M1-WP9 review: REQUEST CHANGES (F1 blocker, first-Admin 500 on real services); author resumed with a narrow WP7-file exception. Lesson for WP12: screen tests on `FakeServices` must mirror real preconditions |
| 2026-11-17, window 4 | M1-WP11 merged; suite 4681 tests OK (1 skipped), lint 340 files 0 failed |
| 2026-11-17, window 4 | M1-WP9 merged; suite 4765 tests OK (1 skipped), lint 346 files 0 failed; wave 5 closed; WP12 dispatched |
| 2026-11-17, window 4 | M1-WP12 implemented; D1 fix (WP8d) and WP12 review dispatched; P83 accepts D2 to D6 |
| 2026-11-17, window 4 | WP8d and WP12 merged; suite 4777 tests OK (1 skipped), lint 348 files 0 failed; M1 exit audit dispatched |
| 2026-11-17, window 4 | M1 exit audit: CLOSES WITH LISTED FIXES; as-built notes and format-doc correction merged; F-1 and F-3 fixes dispatched |
| 2026-11-17, window 4 | M1 exit fixes F-1 and F-3 merged; M1 CLOSED. Stopped per maintainer instruction (do not start M2); resume reminder deleted |

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
| Sign-in success clears the user bucket outright, erasing failures counted on it during password verification (about one extra burst of 5 guesses per overlapped real sign-in; address bucket still limits). Harden: clear only if the row is unchanged since step (a), else decrement | `src/Core/Auth/Authenticator.php` near line 128 (M1-WP2 round 3 review nit) |
| Takeback decrement path can remove a failure counted after a window reset if password verification stalls over 900 s (at most one lost failure) | `src/Core/Auth/LoginThrottle.php` near line 216 (M1-WP2 round 3 review nit) |
| WP9 must not trust `SERVER_PORT` (follows the client's Host port under Apache `UseCanonicalName Off`); the P75 control covers the `safe` case, but the probe can still be aimed at other ports on the server's own address (port-scan oracle through the verdict). Consider refusing a port other than the scheme default unless the operator configured it; also confirm Apache BOM and line-continuation handling on the host check | M1-WP6 review F2, F3 |
| WP9 needs strings `setup.check.data.configured-in-tree`, `-outside`, `-failed`, `setup.code_location` (`{path}`), `setup.code_location.configured` (`{file}`, outside the `setup.check.` runtime prefix); `setupCodeLocation()` returns `InstallMessage`; `DataFolderResult::$label` still carries `DataDirectory`'s English label for a configured folder | M1-WP6 round 1 report |
| `Installer::state()` is not read-only: it removes a leftover setup code once per instance when installed; spec 10 as-built notes. Residual: a second virtual host whose document root is `public/` with the same base path passes the control (within the 10.4 limits) | M1-WP6 round 2 review |
| Add `Fs::entries()` (and stat helpers) and `ProjectRegistry::abandoned(string $before)`; `SchemaEditor::updateField` could return the values-cleared count for the `schema.field_changed` audit entry | M1-WP5 report (files outside WP5 ownership) |
| `rename` writes file meta before the registry name re-check (race leaves names different; spec order); `activate` after a takeover by another create's cleanup throws after `project.created` is audited (practically unreachable) | `src/Core/App/ProjectOperations.php` (M1-WP5 review nits 7, 8) |
| WP8 owner follow-ups from WP7: remove `home.preview.` from `RUNTIME_PREFIXES` in `tests/web/TemplateRulesTest.php`; refocus or drop `AppearanceTest` preview-link tests (now pass through a fixture start page); fixture `home` template and strings still used by `ViewTest`; optional system-database wording for `error.unavailable.*` | `tests/web/*`, `strings/en/errors.php` |
| WP9, WP10: call `Context::withNewSession()` at sign-in and first-Admin creation (ends the old cookie's session, rotates CSRF cookie); `Installer` has no `close()`, so `CoreServices::close()` cannot close its connection; `takeFlash` opens a write on every signed-in GET | M1-WP7 report |
| Confirmation and count strings have no singular forms ("1 values in 1 records"); add plural support to `Strings` or reword | `strings/en/schema.php`, `src/Web/Strings.php` (M1-WP11 report) |
| `HtmlAssertions::assertForm` requires `id` plus `label for` on every text box; exempt controls named by `aria-label` (choice editor, M1-screens 4.5 item 6) | `tests/Support/HtmlAssertions.php` (M1-WP11 report) |
| Delete-table page matches incoming links by table name (correct only while names are unique; `FieldSummary` has no key) | `src/Web/Controller/TableController.php` near line 270 (M1-WP11 review) |
| Shared scratch copy `scratchpad/app` may hold a mutated source file from an interrupted mutation run; treat as dirty, never copy from it | session scratchpad |
| Adding a link field with a tampered cardinality reports only that problem; a core validate-only call for `addRelationField` would allow all problems at once | `src/Web/Controller/FieldController.php` near line 109 (M1-WP11 review nit 2) |
| A losing concurrent first-Admin POST gets 500 (core `LogicException` "The first Admin already exists") instead of a 303 to `/setup` or `/sign-in` | `src/Core/Install/Installer.php` near line 302, `SetupController` (M1-WP9 round 2 review) |
| "Temporary" breaks mid-word on the add-person page at 320 px, Largest text | `public/assets/css/app.css`, `templates/pages/people/new.php` (M1-WP8d report) |
| When a request has no `Sec-Fetch-Site` header, a POST with a foreign `Origin` and a valid `_csrf` is accepted; checking `Origin` against the request host would add defence in depth (care needed behind proxies) | `src/Web/Kernel.php` near lines 73 and 207 (M1 exit audit F-2) |


XLSX probe correction (M3-D1): v5.3.0 also admits PHP 8.3; v5.4.0 is the first needing 8.4. Pinned openspout 5.2.2 (ADR 0016).

### M2 review findings carried (WP0 review: APPROVE)

- Origin: a value over 2048 characters, with a tab, or with non-ASCII bytes is read as absent and passes to the CSRF check (Low; add a third "malformed, refuse" state later). Residual behind-proxy risk is documented only in the `Kernel::originAllowed()` docblock: add it to the deployment documentation at M7.
- UrlType: hexadecimal labels are refused anywhere in the host (stricter than spec 10.5 "last label only"; amend the spec wording at M2-X). Hosts whose last label is all digits (e.g. `example.123`) were accepted in M1 and are now refused; stored values read back as empty (behaviour change, recorded here).
- `ProjectOperations::create` with a symbolic-link `projects/` folder now fails closed; no test covers it (add at M2-X). `Fs::entries` lstat-then-scandir race is out of the threat model. Mutation survivor: the abandoned-row recheck inside the write transaction.
- M2-WP1 (mechanical tier) merged before its implementation-tier review; review pending, to be done with the M2-X audit at the latest.

### M2-WP5 review findings carried (APPROVE)

- Low: `ValueText::cut` loses its length cap on invalid UTF-8 (output is safe). Add tests at M2-X: markup in plural text (mutation M5 survived: escaping around the plural result in `Strings::format`) and a static check for the class-name check in `settings-preview.js` (M7 survived; no JS test).
- Info: right-to-left override characters in values are printed escaped but not isolated (visual spoofing possible); `HtmlAssertions::assertNoInlineCode` flags `value="javascript:..."` inputs, which WP7 hostile-value tests may trip.

### M2-WP6 and WP8 review results (both APPROVE)

- WP6: 650 hostile requests clean; three escape-test gaps closed in WP6b (all 10 reviewer mutations now killed). No existence oracle; the relation-filter title lookup discloses nothing (grants are per project).
- WP8: 9 of 9 mutations killed. Low: the schema confirmation accepts any count at or above the number to be cleared (spec 7.5 says "more than"), so a client with a valid CSRF token can skip the confirmation page by posting a large `confirm` on the first POST, as `confirm=1` could before; a lower count than shown is also accepted. Accepted as specified; a maintainer change to an exact match needs a spec change.

### M3-R1 spec review (merged; READY FOR IMPLEMENTATION once M3-D2 applies the M2 as-built mismatches)

- Fixes made by the reviewer (in the specs): WP2 now depends on WP1 (wave 1b with WP7); five test files added to package ownership; archive problem keys snake_case; import job lock and duplicate-commit check; archive jobs bound to the starting Admin; DOCTYPE/ENTITY in XLSX parts refused before OpenSpout opens the file; JSON numbers written from the field type's text; Records refuses auto-number values in Import mode and duplicates in Canonical mode (closes the raw PDOException from the M2-WP2 review).
- M2-as-built mismatches for M3-D2: `RecordOperations::create` does not get the screen first; `Records` takes its Schema in the constructor so `within()` cannot see a table created earlier in the same transaction; real `Sidebar::build` and `addRelation` signatures differ (exact in M3-io section 18); `FormStore::regenerate` extends only each table's default form; WP9 files not yet in the tree at review time.
- Orchestrator rulings on the open judgement calls (provisional, cheapest to reverse; M3-D2 records them as P135 onward): CSV formula protection stays off by default and the export screen points to XLSX for spreadsheets; the 64 MiB upload cap stays and larger archives are restored by hand (documented); two-decimal columns propose currency only when the column header or sample suggests money, else number (M3-D2 to define the cheapest rule); cap total rows returned per XLSX chunk read; add a core "check again" method for import step 1.
- Hard gate for M3-WP0: the shared offline `vendor/` must hold OpenSpout 5.2.2 (obtain by the orchestration 9 method) before any M3 package is dispatched, because the dependency guard test needs listed packages installed.

### M2 exit results

Independent design-tier exit audit on the integration branch (all M2 packages merged). Suite: `composer test` OK (6310 tests, 1 skipped: no comma-decimal locale); `composer lint` 474 files, 0 failed. Full evidence in the session scratchpad (not committed).

| Criterion | Result | Evidence |
|---|---|---|
| Suite and lint | PASS | As above |
| A Member can add, find, and edit records by keyboard only | PASS | Auditor's own key-presses-only headless run (add, find, edit and save, trash, Undo) with focus ring visible and no focus trap; builders' run K-1 (7 of 7 steps) agrees; `KeyboardStructureTest` fails on a planted positive `tabindex` or a removed textarea id. Manual screen-reader pass: not verified, as in M0 and M1 |
| Contrast tests cover all 10 combinations; light warn token at 7:1 or better | PASS | 100 cells recomputed independently, 0 mismatches; lowest text pair 7.09; light warn 7.61:1; five mutations fail the test. Low gap: only the Light column of ui 4.2 is checked against the tokens. M2-WP1 (mechanical tier) implementation-tier review done here |
| Scope | PASS | 31 screen states respond on the built-in server, data folder outside `public/` |
| Security baseline (new surface) | PASS | Ungranted Member 404 byte-identical to a nonexistent project on 6 GET and 4 POST routes; all 6 new POST routes refuse missing or bad CSRF, foreign or `null` Origin, and cross-site fetch with 403; hostile values escaped; stored form with a script falls back to the generated layout; headers present, no inline code, no outbound requests, dependencies unchanged |
| Data portability | PASS | Version 2 file read with plain SQLite matches `docs/formats/tiffin.md` (13 `_tiffin_*` statements, names, integrity checks, `user_version`); a version 1 file built with the M1 code upgrades on first open with one snapshot, records and links unchanged |
| Visual V-9 to V-14 | PASS | 186 renderings (1360 px four themes, 390 px, 320 px Largest), no sideways scroll; X-1 to X-5 held; D-15 to D-19 confirmed. Remaining: long file names and "sans-serif" break inside the word at 320 px Largest; high-contrast theme cards 74 px tall |
| Repository rules | PASS | SPDX, names, `.gitignore`, layer tests, no `@` suppression |
| Tests not vacuous | PASS | 15 mutations, 13 caught; the 2 survivors are equivalent (ADR 0002: a granted Member has every record permission) |

Open low items (test-only, carried to M3 or later): no test for the class-name pattern in `settings-preview.js`; no test for a symbolic-link `projects/` folder; no test pins which record permission each method checks (needed if a read-only role is added); WP0 abandoned-row recheck mutation not re-checked; cite deviations with their milestone because M3-screens reuses D-15 to D-19.

Not verified in M2: `.htaccess` behaviour on a real Apache server (host check); manual screen-reader pass.

### M3-WP0 merged (review running)

OpenSpout 5.2.2 pinned; shared vendor with OpenSpout is at scratchpad `vendor-m3` (steps in `STEPS.txt`; `vendor/composer/installed.php` merged by hand because dump-autoload does not rebuild it); the main checkout's `vendor/` now includes it, so T-DEP-1 fails in any worktree without it. Orchestrator sign-offs: `tests/web/SetupScreenTest.php` (row ids shifted by the three new setup rows) assigned to WP0 for that change; `ci.yml` tolerates only the exact-pin warning from `composer validate --strict`; the licence check accepts "MIT AND Apache-2.0" only when every operand is allowlisted. Stale: `docs/design/m1/installer.md` lists only the old checks (fix at the M3 audit).

### M3-WP1 and WP3 merged and reviewed (APPROVE, follow-ups merged)

- P142 (provisional): at most 10,000 new records in linked tables per import (`IoLimits::MAX_NEW_LINK_RECORDS`); over the cap the commit is refused with `problem.import.too_many_links {max}`, nothing is committed, the job returns to Review. Cheapest to reverse: a constant.
- Open for WP8 (owner of the core operations and strings): add the `problem.import.too_many_links` row to M3-io section 14, its text to `strings/en/problems.php` ("This file would add more than {max} records to other tables. Leave a link column out, or link fewer titles."), and remove it from `MessageKeysTest::PENDING_SPEC_KEYS`; add the `-shm`/header-rewrite note (WAL-mode source) to M3-io section 11.4.
- WP7 note: rewrite the source header before opening a WAL-mode untrusted file. WP2 review must confirm the plugin include path is validated before `require`.
- WP3 follow-ups merged (download headers, ini quantity parsing, field-name rule). Routes and the Admin Plugins nav link return 500 until WP9 and WP10.

- P143 (provisional, M3-WP2b): plugins may name `TiffinDB\Core\Io\Csv\` in addition to Contract, their own namespace, PHP built-ins and `OpenSpout\` (T-PLG-8); the core CSV reader and writer are pure. Lower-case SQL detection added to the plugin layer test (a bare `select x from t` with no further clause is deliberately not caught, to keep English labels).

### M3-WP2 review outcome (REQUEST CHANGES, fixed in WP2c)

Registry held up; gaps were in the plugin layer rules (T-PLG-8) and manifest strictness. Fixed: `use function` aliasing, code-execution constructs, PHP in non-`.php` files (only `plugin.json`, `src/**/*.php` and README/LICENSE/NOTICE/COPYING/`*.md`/`*.txt` allowed under `plugins/`; manifests with `<?` are Broken `php-tag`), request-data access, SQL and URL detection, duplicate manifest keys (Broken `duplicate-member`). P143 narrowed to five named classes (`Charset`, `CsvDetector`, `CsvReader`, `CsvWriter`, `CsvDialect`); core rule: `Core\Io\Csv` may use only `Core\Io\Contract` and `Core\Error\Text`. Plugin authoring constraints (for WP4, WP5, WP6): no `eval`, `include`, `call_user_func`, `Reflection`, `unserialize`, variable calls, `new $x`, computed member names; closures in variables are called as `$f->__invoke()`; string literals naming a denied function (e.g. 'system', 'mail') are refused even as text. WP10 needs strings for Broken reasons `php-tag` and `duplicate-member`. Accepted informational: a fatal or `exit` in an enabled plugin ends the request; the `final` check can be bypassed with `eval`; a stale enabled row enables a re-dropped plugin with the same id.

- M3-WP6 merged (implementation-tier review pending). Defects reported in `docs/formats/json-export.md` for the design-tier fix: (1) it does not say what happens to invalid stored UTF-8 (the exporter writes U+FFFD via JSON_INVALID_UTF8_SUBSTITUTE, added to the 10.3 flags); (2) section 6 says "one object per attached file" but only attachments linked from live records are exported; (3) section 4 says numbers have "at most 15 significant digits" but a float stored before `decimals` was lowered can print up to 17, and values keep the field's decimals ("12.00") unlike the doc's "12" example. An unknown field type fails as `unsupported` and the partial file is removed.

- M3-WP4 review outcome (REQUEST CHANGES, fixed in WP4b): offset-cache bounds now tested with a valid key (negative, at or past end, non-integer, huge skip); an offset at the file end falls back to the start; `CsvReader::MAX_SKIP` 256 KiB; cache key adds the folder real path and SHA-256 of the first 4 KiB; Charset converts in 64 KiB pieces; failed conversion is `PluginFailure(unreadable)`. Open: replacement counts for UTF-16 and Windows-1252 cannot reach the review warning because the contract has no channel (WP1 contract change: carry to the M3 audit as a known limitation, or add a field in WP9's review step if cheap); `docs/design/m3/export.md` still quotes the old help text (update at the M3 audit); `CsvWriter::fileName` is not yet called by anything: WP10's download must use it.

- M3-WP6 review: APPROVE (12 of 12 mutations caught; 200k-record export streams at constant memory; output validated with an independent strict JSON parser). json-export.md and M3-io 10.3 corrected (invalid UTF-8 as U+FFFD, only attachments linked from live records listed, number values keep the field's decimals and an older float prints up to 17 significant digits). MEDIUM (core, WP1 file): `ProjectExportSource::linksOf` is quadratic (EXPLAIN shows a scan of the other table's trash index ahead of the join table per 500-record page; 20k records took 25.4 s, 200k about 40 minutes; affects CSV export too): fix by WP1 author (WP1c). LOW test gaps for the WP6 author: fixture lacks Unicode table/field names, an empty table beside non-empty ones, control characters other than newline, and a number stored before `decimals` was lowered.

- M3-WP1c merged: export link and attachment queries use a forced join order (`CROSS JOIN`); 20k records with a self-relation 69.7 s to 0.15 s, output hash unchanged; also fixed in `Records` (`setLinks` existing-links query, `linksOf`, attachments query). T-IO-15 regression test (its EXPLAIN runs on a copy of the SQL, so drift between code and copy is not caught by that part; the file scan is the backstop). Open (M2 file, not owned by an M3 package): `src/Core/Query/ListSql.php` has the same JOIN pattern in four subqueries (relation sort title and the link filters, lines ~160, 167, 224, 316): check with EXPLAIN QUERY PLAN on a 20k-record fixture and fix in a small follow-up before the M3 exit audit.

- P144 (provisional, M3-WP5; amends ADR 0016 decision 4 and M3-io 9.2): the XLSX importer reads with PHP's `XMLReader` over `zip://` and does not use OpenSpout's Reader; OpenSpout is used only for export. Reasons (v5.2.2 source read and timed by the author): a formula with no cached value reads as 0; a text cell starting with "=" becomes a formula cell; one out-of-range date stops the whole read; a hostile `spans` attribute can exhaust memory; rows are rebuilt cell by cell (quadratic in width); one pass over 50,000 x 20 took about 8 s and every chunk repeats it. Consequence: our own parser of untrusted XML is the attack surface (security review in progress); the manifests still require dom and filter (needed by OpenSpout for export). Plugin-only limits in `plugins/import/xlsx/src/Limits.php`: 255 sheets; shared strings up to 2,000,000 and 32 MiB. Contract limitation: plugin issues cannot be carried, so formula-no-value and error cells come back as null with no issue. Exporter writes text cells for numbers the writer would round (14 significant digits) and for dates before 1900-03-01. Macro workbooks refused as unsupported; prolog (DOCTYPE/ENTITY) check runs on every zip entry. ADR 0016 and M3-io 9.2 to be amended at the M3 audit.

### M3-WP8 review (APPROVE; 21 of 22 mutations killed)

Another Admin gets the same NotFound for an existing, absent, malformed or lock-held job (no Busy leak); archive reason codes all map to `problem.archive.*` keys with strings. Low: `ImportOperations::commit` does not re-read the actor inside the project write (an Admin deactivated during the snapshot copy still commits; M2 record writes behave the same); survivor: the LogicException in `Context::usePluginsRoot` has no test (not reachable from web input). Info: spec 11.6 says `problem.name.taken` but code uses `problem.project_name.taken` (amend spec at the M3 audit); download names keep non-ASCII letters so the web layer must send `Content-Disposition` with `filename*` and an ASCII fallback (WP10 review item); a commit refused for `too_many_links`, `target_changed` or Busy has already taken and audited its `import` snapshot; `ImportJob::open` opens a foreign job's database (WAL files) before the owner check.

### M3 screens and XLSX fixes (state)

- M3-WP9 merged; security review APPROVE (12 of 12 mutations killed; live server with four accounts: 40,000-row import with 5 parallel saves gave 1 commit, 4 Busy; exactly one import-log row). Lows: a tampered `table` key with `target=existing` returns a 404 page instead of a 422 review (same body for every value, no oracle); the file name in the review facts and report is escaped but not cut to 200 characters (shown at the core's 255 cap); bidi controls are escaped but kept in `th`/aria-label text. Wording oddities: "Choose at least one column" links to the table-name input.
- M3-WP10 merged (review running). Stale doc: `docs/design/m3/export.md` quotes the old CSV help text and old notice key names (`notice.archive_imported`, `notice.archive_imported_changes`, `notice.plugin_enabled`, `notice.plugin_disabled`); gaps: no project size on the export screen; name-taken problem does not return the stored name.
- M3-WP5b merged (round-2 review running): readString/expand banned on untrusted subtrees; real bytes counted once per job; per-call step budget; job caches `xlsx-cache.json`, `xlsx-strings.bin`, `xlsx-offsets.bin` in the job `tmp/` re-checked as untrusted; ADR 0016 decision 4 and M3-io 9.1, 9.2, 18 amended. Residual: skipping to a late `from` has no work limit (late chunks up to 5.3 s for the 2M-string workbook, 4.1 s for 12k x 200).

- M3-WP10 review: APPROVE (12 of 14 mutations killed; real built-in server: downloads, tampered export requests, forged plugin confirmations all handled). Low: `ArchiveController::fileName` strips C0/C1 controls but keeps bidi and format characters (U+202E, U+200B, U+FEFF); a forged hidden `file_name` shows reversed in the archive-checked sentence (WP10 follow-up, core name rules reject invisible characters). Survivor: accepting a disabled plugin id on the export POST has no web test (core tests kill it). Info: export is streamed from the POST with no download URL (owner check in `ExportOperations::finish`, stale files swept, `ignore_user_abort` set) while the design says download URL: amend `docs/design/m3/export.md` at the audit; the ASCII fallback name replaces each non-ASCII byte with `_`.

### M3-WP11 merged (round trips, E2E, keyboard, visual)

T-RT-1..6, T-E2E-8..10, T-SCR-3, T-KBD-2 pass; K-2 keyboard-only run passes (file choice set by the script: the single non-keyboard step; "Save the rows" never pressed automatically). Round trips: CSV byte-identical; XLSX equal cell for cell (hand parser); JSON matches the format doc and plain SQL; archive equal table by table. Failed-import test (3 failure points): project checksum unchanged (failure injected with a trigger in the project file because `ImportOperations::commit` has no core seam). T-RT-6 timings, 20k / 50k self-relation records, seconds: archive export 0.58/1.48, archive import 1.70/6.97, JSON 0.42/1.03, XLSX export 1.49/3.93, CSV export 0.46/1.09; CSV import read 2.10/5.22, prepare 0.58/1.63, commit 1.47/3.34; XLSX read 3.74/12.03. Visual: 118 of 120 automated passes. Deviations to fix before the M3 audit: WP9: report table scrolls sideways at 320 Largest (wrap in `div.table-scroll`), "Change how the file is read" button outside the panel, fields section gap, repeated "Check again" empties the new table name; WP3: mapping checkboxes 13 px not 24, Type select clipped (`width:auto`), "Start again" padding 0 28 not 0 20, report numbers not right-aligned; WP8: a failed commit gives a 500 on the save POST instead of a 303 to the failure page (T-E2E-9 tolerates it), warning reads "new room" instead of "rooms"; WP1: first values cut to 60 characters without an ellipsis, a file-name column proposed as Web address and rewritten to "https://stand-mixer.jpg"; spec 6.3: Date choice for a column with unreadable dates not selectable, Phone never offered, checkbox and currency default to Number. Proposed accepted deviations M3 D-22 to D-26. Full report: scratchpad `M3-WP11/M3-WP11-report.md`.
