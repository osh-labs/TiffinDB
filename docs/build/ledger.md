# TiffinDB Build Ledger

Single source of truth for the v1 build. Updated and pushed after every state change. Contains no personal data.

## 1. Current state

| Item | Value |
|---|---|
| Milestone | M0 |
| Phase | pre-design (context brief in progress) |
| Window start | 2026-11-11T22:29Z |
| Resume reminder | `trig_01SLjaGLUL632fioKiH2G1bk`, fires 2026-11-12T03:45Z |
| Next action | When `docs/build/context-brief.md` exists: commit it, read it, dispatch the M0 design spec (`docs/specs/M0-*.md`). If the brief is absent, re-dispatch the context-brief agent. |

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
| PRE-1 | pre | design | `docs/build/context-brief.md` | in progress | assigned branch | orchestrator | |

## 4. Provisional decisions awaiting maintainer confirmation

| # | Item | Provisional resolution | Recorded in |
|---|---|---|---|
| P1 | ui-design-plan section 9, items 1 to 5 | Adopt the "Proposed resolution" column as written | to be recorded in M0/M2 specs |
| P2 | Trash retention (plan Q3) | Items stay until an Admin empties trash; no automatic purge | to be recorded in M1 spec |
| P3 | Font payload (plan Q9) | Measure in M0; if four families exceed 2 MB as WOFF2 Latin subsets, ship Inter and Atkinson Hyperlegible, document others as optional downloads | to be recorded in M0 spec |
| P4 | Undesigned screens (ui-design-plan 6.2) | Designed from existing tokens and shell before each milestone; added to `docs/design/` | per-milestone specs |
| P5 | Non-Apache hosting, desktop packaging (plan Q6, Q7) | Out of scope for v1 | this ledger |
| P6 | XLSX library | Adopt OpenSpout if license file confirms MIT and PHP 8.3 is supported; otherwise select an alternative via ADR | to be recorded in M3 spec |

## 5. Blockers

Open: none.

Resolved: none.

Deferred maintainer tasks (raise only at end of build):

| Task | Status | Script / procedure |
|---|---|---|
| Bento discovery (plan 6.4) | script to be written in M0 | pending |
| DreamHost verification (plan Q2; M7 exit criterion) | script to be written in M0 | pending |

## 6. Usage log

| Time (UTC) | Event |
|---|---|
| 2026-11-11T22:29Z | Build started; window 1 start; resume reminder armed for 03:45Z |
