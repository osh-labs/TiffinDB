# CLAUDE.md

Guidance for AI agents working in this repository.

## Project

The official project name is TiffinDB (one word, capital T, D, B). Use it consistently in code, docs, package metadata, and commits; do not shorten to "Tiffin". The lead developer is the project owner and final decision-maker, referred to in this repo only by role ("the lead developer" or "the maintainer").

TiffinDB is an open-source, self-hosted personal database application inspired by Bento (Apple/FileMaker, discontinued). The goal is a free, user-owned tool for building and using small personal databases: custom fields, forms/layouts, lists, search, and filtered collections.

Status: in development. Milestones M0 (scaffolding, request pipeline, application shell, design tokens, fonts, repository rule tests) and M1 (storage, accounts and sessions, schema engine, trash, snapshots, audit log, installer, sign-in, people, sharing, project and schema editor screens) are complete; M2 is next. Core stack decisions are recorded in `docs/decisions/` and the plan is in `docs/plan.md`; record any new decision there before implementing it. Build state is tracked in `docs/build/ledger.md`, with per-milestone specs in `docs/specs/` and the build coordination rules in `docs/build/orchestration.md`. Run `composer test` and `composer lint` before committing. The v1 visual target is the model UI in `docs/design/model-ui/`, with `docs/ui-design-plan.md` and `docs/design/tokens.css`; logo files and usage rules are in `docs/brand/`.

## Public Repository Rules (Mandatory)

This is a public repository. Everything committed is world-readable and permanently retrievable from git history, forks, and caches.

Agents are expressly forbidden from committing, pushing, pasting into issues/PRs/commit messages, or otherwise placing any of the following in this repo:

- Live or real user data of any kind
- Personally identifiable information (PII)
- Personal names of real individuals (including the maintainer, collaborators, family, clients, colleagues)
- Email addresses, phone numbers, physical addresses, account numbers, or device identifiers
- Credentials, API keys, tokens, certificates, private keys, connection strings, or `.env` contents
- Non-public business, client, project, or employer information
- Exports, backups, or screenshots of real databases

Requirements:

- Test fixtures, sample databases, screenshots, and documentation examples must use obviously synthetic data (e.g. "Example Person", `user@example.com`, reserved ranges such as 192.0.2.0/24 and 555-01xx numbers). Prefer generated data with a documented seed.
- Do not derive fixtures from real data, even if anonymized by hand.
- Git author/committer metadata is the one exception that cannot be avoided; do not add names or emails anywhere else (no `AUTHORS` entries, code comments, docs, or sample data) unless the maintainer explicitly directs it for a specific, named file.
- If real or sensitive data is encountered in the working tree or supplied in a prompt, do not copy it into any file. Stop and notify the maintainer.
- If something sensitive has already been committed, do not attempt to hide it with a follow-up commit. Report it immediately; history rewrite and credential rotation are the maintainer's decision.
- Add `.gitignore` entries for local databases, exports, and secrets (`*.db`, `*.sqlite*`, `*.tiffin`, `.env*`, `/local/`, `/data/`) before adding any code that creates them.
- Never reference the maintainer's employers or affiliated organizations in code, docs, or commits.

## Licensing

Intent: anyone may use, modify, and redistribute the software for any purpose provided attribution is retained and derivative works remain free (copyleft), including when run as a hosted service.

License: GNU Affero General Public License v3.0 or later (`AGPL-3.0-or-later`), chosen by the lead developer because TiffinDB is designed to be hosted on a standard LAMP stack. Section 13 requires network-service operators who modify the software to offer the corresponding source to remote users. The canonical text is in `LICENSE` (from gnu.org); do not edit it.

Rules:

- Every source file carries an SPDX header: `SPDX-License-Identifier: AGPL-3.0-or-later` plus a copyright line using "TiffinDB contributors" (not an individual).
- Keep the application's "source code" link/notice reachable in the UI (e.g. footer or About page) pointing to the repository, so AGPL section 13 is satisfied for hosted instances and modified forks inherit the pattern.
- Only add dependencies whose licenses are AGPL-3.0-compatible (MIT, BSD, Apache-2.0, ISC, LGPL, MPL-2.0, GPL-3.0, AGPL-3.0). Bundled font assets may additionally use OFL-1.1 (ADR 0004, ADR 0007). Do not add GPL-2.0-only, proprietary, source-available, or non-commercial-restricted dependencies. Record each dependency's license in `docs/dependencies.md`.
- Do not copy code, schemas, UI assets, icons, or documentation from Bento or any other proprietary product. Do not use the name "Bento" in the product name, UI, or package metadata. Describing TiffinDB as "inspired by" or "a replacement for" it in prose is acceptable.
- Do not paste code from sources of unknown or incompatible license (Stack Overflow snippets carry CC BY-SA; check before use).

## Working Conventions

- Branching: develop on the branch assigned for the session; do not push elsewhere. Do not open pull requests unless asked.
- Commits: small, descriptive, imperative mood.
- State assumptions explicitly in PR descriptions and design docs. Where uncertain, say so and list the missing information.
- Keep the data model, storage, query, and import/export logic separated from presentation so the core is testable headlessly.
- Data portability is a core value: user data must be stored in an open, documented format and be exportable (CSV and JSON at minimum) without the application.
- Tests must be deterministic and run offline with synthetic fixtures only.
- No telemetry, analytics, or network calls in the application without explicit maintainer approval and an opt-in design.
- Do not fabricate citations, standards, or license text. Fetch license text from the canonical source (gnu.org or spdx.org) when the `LICENSE` file is added.

## Open Decisions

Accepted decisions are in `docs/decisions/`; the working plan is `docs/plan.md`.

| Item | Status |
|---|---|
| Language / UI framework | Decided (ADR 0001): PHP 8.3+, no framework, Apache `.htaccess`, server-rendered HTML with vanilla JS, no build step. |
| Storage engine and file format | Decided (ADR 0001): one SQLite file per project plus a SQLite system database; MySQL/MariaDB not required |
| Accounts | Decided (ADR 0002): household accounts, Admin and Member roles, per-project grants |
| Forms | Decided (ADR 0003): stored HTML/CSS with binding attributes, auto-generated, simple editor |
| v1 scope | Decided (ADR 0004): relations, attachments, CSV/XLSX/Bento import, CSV/XLSX/JSON export |
| UI design | Decided (ADR 0007): model UI is the v1 visual target; Inter default typeface; per-user typeface and accent colour; some layout differences from the plan await confirmation (`docs/ui-design-plan.md` section 9) |
| WYSIWYG form editor | Decided (ADR 0006): post-v1, same form format, snap-to-grid layout; some defaults awaiting confirmation |
| MCP server | Decided (ADR 0005): post-v1, full scope, OAuth 2.1, disabled by default; OAuth design document required before implementation |
| Platform targets | Web application on self-hosted Apache and DreamHost shared hosting; desktop/local packaging undecided |
| Bento file/template import | Approach undecided pending file-format discovery (requires clean-room approach; no reverse-engineered proprietary code) |

## Multi-agent workflow

<!-- multi-agent-workflow policy v1.2. Source: SEM Dev > Claude Multi-Agent Workflow. Update this copy when the source changes. -->

This repo uses a plan -> orchestrate -> slice -> integrate workflow.

- Planner (Opus or Fable): writes PLAN.md and the PROGRESS.md skeleton. Writes no code.
- Orchestrator (Sonnet, medium effort): runs the implement-plan skill. Dispatches, merges clean merges, runs targeted and contract tests, and updates PROGRESS.md. Never reads or edits source code.
- Integrator (Opus, medium effort): called by the orchestrator, fresh each time, for merge conflicts, cross-slice test failures, contract-test failures, and final integration.
- Slice agents (Opus, medium effort, by default; Sonnet, medium effort, for mechanical slices): implement one slice each, in their own worktree and branch. Haiku is not used for slices.

### Project commands

Fill these in per repo. All agents use these exact commands.

- Targeted tests: `vendor/bin/phpunit {test paths} 2>&1 | tail -n 40`
- Full suite: `composer test` (equivalently `vendor/bin/phpunit`); `composer check` runs lint then tests (CI)
- Lint and types: `composer lint` (php -l over all PHP files); no static type checker is configured
- Source-to-test mapping: `src/Core/<Area>/Foo.php -> tests/core/<area>/FooTest.php`; `src/Web/...` -> `tests/web/`; layering and repo rules in `tests/repo/`; design assets in `tests/design/` (mapping inferred from directory names, not verified per file)

### Testing policy (all agents)

1. Slice agents run targeted tests only for the files they changed, once, after their final change. On failure, fix and run again. After 3 failed runs, stop and report the slice as blocked with the failing test names.
2. Do not run tests after intermediate edits. Do not re-run a passing test on unchanged code.
3. Only the integrator runs the full suite, and only during final integration: once, plus at most one re-run after fixes, with the `FULL_SUITE_OK=1` prefix. A hook blocks other full-suite runs.
4. A suspected flaky test may be re-run once. If it then passes, record it under Flaky in PROGRESS.md and move on.
5. Pipe test output through `2>&1 | tail -n 40`. Report results in 10 lines or fewer: pass and fail counts, and failing test names. Never paste full logs into reports or PROGRESS.md.
6. After each merge, the orchestrator runs targeted tests for the merge diff once and records the SHA and result in PROGRESS.md. Skip any run whose SHA already has a recorded pass.
7. At the end of a wave that included a slice exposing a shared interface, the orchestrator runs that slice's contract tests once. Failures go to the integrator. No full suite at wave boundaries.

### Review tiers

The planner assigns one tier per slice. Slice agents never change a tier. The orchestrator may raise a tier only when a trigger below applies, and must state which one.

- R0: tests and lint only. Default for docs, config, isolated UI, and diffs under 150 lines.
- R1: one Sonnet reviewer, read-only, report of 300 words or fewer against the slice's acceptance criteria.
- R2: deep review on Opus. Only for changes to shared interfaces or contracts, security or data-integrity code, diffs over 400 lines, or an R1 that found a blocking issue.
- R2 on Fable: only with the user's explicit approval, for unusual complexity or risk. The planner may request it in the slice (`R2-fable: <reason>`); the orchestrator asks the user before running it, uses Opus if not approved, and records the decision in PROGRESS.md.

### PROGRESS.md

- Lives next to the plan file. It and git are the record of run state; conversation history is not.
- Only the orchestrator edits it. Slice agents never touch it.
- Update and commit it after every slice state change, message `progress: <slice> <status>`, and push with the merge.
- Status values: todo, dispatched, merged, verified, blocked.
- Change requests that arrive mid-run go under Deferred, not to a running agent, unless the user says otherwise.
- On resume, rebuild state from PROGRESS.md and `git log` only. Do not re-read old transcripts or re-explore the codebase. If PROGRESS.md and git disagree, git wins.

### Plan format (planner)

Write the plan to `docs/plans/<plan-name>/PLAN.md` and a PROGRESS.md skeleton beside it. Put the branch name and plan path in the commit message. Each slice has:

- ID and title (S1, S2, ...)
- Goal: one or two sentences
- Files: exact paths to create or modify
- Interfaces: signatures or contracts the slice exposes or consumes
- Depends on: slice IDs, or none
- Wave: slices in the same wave are independent and can run in parallel
- Model: opus (default) or sonnet (mechanical: renames, boilerplate, config, docs, test scaffolding from a given spec). Both run at medium effort. No haiku.
- Review tier: R0, R1, R2, or R2-fable with a one-line reason
- Test targets: the test files or command the slice must pass
- Contract tests: for a slice that exposes a shared interface, the test file that later slices must also pass
- Acceptance: runnable commands and observable results, not prose
- Out of scope: what the slice must not touch

Each slice must be self-contained: an agent that reads only its slice and this file can finish it without exploring the codebase. Waves hold at most 3 slices by default.

Integration rules for the planner:
- Slices in the same wave must not list any of the same files. If two slices must touch one file, put them in different waves.
- Shared-interface slices go in the earliest wave and ship their contract tests with them.
- End the plan with an Integration notes section: the cross-slice touchpoints (shared files, registries, routes, config, migrations) and what the integrator should check at each.
