# TiffinDB Build Ledger

Single source of truth for the v1 build. Updated and pushed after every state change. Contains no personal data.

## 1. Current state

| Item | Value |
|---|---|
| Milestone | M0 |
| Phase | implement (M0 WPs) |
| Window start | 2026-11-11T22:29Z |
| Resume reminder | `trig_01SLjaGLUL632fioKiH2G1bk`, fires 2026-11-12T03:45Z |
| Next action | Dispatch M0 WPs per `docs/specs/M0-scaffolding.md`: WP1 first (alone), then WP2/WP3/WP4/WP5 in parallel (max 3), then WP6 and WP7. Review each (security-critical WP1, WP5, WP6 by the design tier; mechanical WP3, WP4 by the implementation tier), merge one at a time, run `composer test`, push. Then M0 exit audit. Any WP row below marked in progress with no pushed branch must be redone after a container restart. |

## 2. Milestones

| Milestone | Status | Exit-criteria results |
|---|---|---|
| M0 | in progress | |
| M1 | not started | |
| M2 | not started | |
| M3 | not started | |
| M4 | not started | |
| M5 | not started | |
| M6 | deferred (awaiting maintainer discovery output) | |
| M7 | not started | |
| M8 | post-v1, gated | |
| M9 | post-v1, gated | |

## 3. Work packages

| ID | Milestone | Model tier | Owned files | Status | Branch | Reviewer | Review result |
|---|---|---|---|---|---|---|---|
| PRE-1 | pre | design | `docs/build/context-brief.md` | done (pushed) | assigned branch | orchestrator | accepted |
| M0-D1 | M0 | design | `docs/specs/M0-scaffolding.md`, `docs/decisions/0008-*`, `docs/decisions/0009-*` | done (pushed) | assigned branch | second design-tier reviewer | READY FOR IMPLEMENTATION after 13 fixes |
| M0-T1 | M0 | design tier | `tools/discovery/*`, `tests/tools/DiscoverStructureTest.php` | done (pushed) | assigned branch | design-tier privacy reviewer | APPROVE after 4 rounds (round 3 escalated to default-deny redesign); open nit: digit-run exemption uncapped |
| M0-T2 | M0 | implementation | `tools/host-check/*`, `tests/tools/HostCheckTest.php` | done (pushed) | assigned branch | design-tier security reviewer | APPROVE after 2 rounds; PHP 7.4 parse compatibility not executed (no 7.4 binary) |
| M0-WP1 | M0 | implementation | manifest, `.gitignore`, `.htaccess` files, PHPUnit config, lint, CI, base tests (security-critical) | not started | | design tier | |
| M0-WP2 | M0 | implementation | SPDX, PHP rules, licence, layer, ADR tests | not started (needs WP1) | | implementation tier | |
| M0-WP3 | M0 | mechanical | `tokens.css` | not started (needs WP1) | | implementation tier | |
| M0-WP4 | M0 | mechanical | fonts, licences, `fonts.css`, `docs/dependencies.md` | not started (needs WP1) | | implementation tier | |
| M0-WP5 | M0 | implementation | request pipeline (security-critical) | not started (needs WP1) | | design tier | |
| M0-WP6 | M0 | implementation | templates, strings, `app.css`, shell tests (security-critical) | not started (needs WP3, WP4, WP5) | | design tier | |
| M0-WP7 | M0 | implementation | design asset tests | not started (needs WP3, WP4) | | implementation tier | |

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
