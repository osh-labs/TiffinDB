# TiffinDB build orchestration guide

Standing rules for the agent that coordinates the v1 build. `CLAUDE.md` overrides anything here. State lives in `docs/build/ledger.md`; background in `docs/build/context-brief.md`. The mapping from the three tiers below to concrete models is given in the session prompt, not in this repository.

## 1. Role

The orchestrator delivers milestones M1 to M5 and then M7 of `docs/plan.md` by delegating design, coding, and review to subagents, integrating their work, and keeping the ledger current. It does not write application code or read large files itself. M6 is deferred until the maintainer returns discovery output. M8 and M9 are post-v1 and need an explicit maintainer instruction.

## 2. Tiers

| Tier | Use for | Never use for |
|---|---|---|
| Design | Milestone specs, module interfaces, data and file formats, ADRs, design of undesigned screens (ui-design-plan 6.2), security design, review of security-critical code, milestone exit audits, root-cause analysis after two failed fix rounds | Bulk implementation |
| Implementation | Work packages from a design-tier spec, tests, review of implementation-tier and mechanical-tier output, merge conflicts, test repair | Original architecture or format decisions |
| Mechanical | Fully specified repetitive work with an exemplar or exact spec (headers, strings population, boilerplate after an exemplar, seeded fixture generators, dependency-table entries) | Anything with an open design choice or security relevance |

Rules:

1. Set the model explicitly on every subagent call. Do not use forked subagents for delegated work.
2. Every mechanical-tier output is reviewed by an implementation-tier or design-tier agent before integration.
3. Every implementation is reviewed by a different agent instance; an agent never reviews its own work.
4. Security-critical areas are always reviewed by the design tier: `core/auth` (passwords, sessions, roles, grants, CSRF), the form HTML sanitizer and CSP, attachment upload and download, the installer's data-directory exposure test, plugin discovery and loading, every importer that parses user-supplied files, SQL identifier generation, anything that emits HTML or headers.
5. The design and implementation tiers may be served by the same model. Independence then comes from separate agent instances with separate briefs: the reviewer or auditor never receives the author's conclusions, only the code, the spec, and the properties to check, and is asked to confirm findings by running.
6. Brief each subagent as a peer: goal, spec path, owned files, files it must not touch, test command, definition of done, applicable repository rules. The brief is its only context.

## 3. Per-milestone workflow

1. Design: `docs/specs/M<n>-<topic>.md` with interfaces, layout, data structures, test plan mapped to exit criteria, and work packages (owned files disjoint between concurrent packages, dependencies, tier, acceptance tests, security-critical flag). New decisions go in `docs/decisions/` as numbered ADRs before code. Undesigned screens are designed under `docs/design/` first.
2. Spec review by a second design-tier agent, who may fix clear defects in the spec directly and reports judgement calls.
3. Implement in isolated worktrees, at most three agents at once. Each agent commits on its local branch and runs the full suite before reporting.
4. Review per section 2. Send findings back to the author (resume the same agent). After two failed rounds on the same class of defect, require a root-cause redesign rather than another patch.
5. Integrate: merge one branch at a time into the session's assigned branch with a merge commit, run `composer test` and `composer lint`, push. Only the orchestrator pushes.
6. Exit audit by an independent design-tier agent against plan section 8 and ui-design-plan section 11; record pass, fail, or deferred per criterion with evidence in the ledger. The auditor also appends as-built notes to the spec.

Verify claims with cheap commands (test run, `git log`, `php -l`, a curl smoke test), not agent summaries.

## 4. Engineering constraints

See `docs/build/context-brief.md` sections 1, 4, and 11, and ADR 0008. In short: PHP 8.3+, no framework, no build step, every screen works without JavaScript; core is headless and holds authorization; trash, snapshots, and audit log are core features from M1; SPDX header on every source file; AGPL-compatible dependencies only, confirmed from the upstream licence file and recorded in `docs/dependencies.md`; deterministic offline tests with synthetic data; no telemetry or network calls; no inline scripts or styles; no personal data, real names, employer references, or legacy-product material anywhere; no pull requests unless the maintainer asks.

## 5. Decisions without asking

Adopt the provisional resolutions in ADR 0009 and ledger section 4. For new open points, choose the option that is cheapest to reverse, record it as provisional in the ledger and the relevant ADR or spec, and continue.

## 6. Usage windows

- Keep exactly one pending resume reminder (5 hours 15 minutes after the window start) with the message `RESUME: read docs/build/ledger.md and continue from the recorded next action.` Record its id and fire time in the ledger.
- On a rate-limit or usage-limit signal: stop dispatching, let running agents finish, checkpoint (commit, push, ledger with work-package states and the exact next action), reset the reminder to the stated reset time plus 10 minutes, end the turn.
- On resume: read the ledger, run the suite, redo work packages whose commits existed only in a reclaimed worktree.
- Repeated immediate limits: back off 5, 10, then 24 hours. A stall over 24 hours is a blocker.

## 7. Maintainer contact

Notify only for: a blocked network host the job needs; sensitive data found or already committed; a dependency with no compatible licence; an exit criterion that still fails after root-cause analysis and one redesign; a costly-to-reverse decision not covered by an ADR or section 5; a usage stall over 24 hours; end of build. Head the message `BLOCKER`, state what is blocked, why, what is needed with a recommendation, and what continues meanwhile.

Deferred maintainer tasks (raise only at end of build): the legacy-format discovery run (`tools/discovery/`) and the reference-shared-host check (`tools/host-check/`). Record the M7 host criterion as pending maintainer verification.

Commands the maintainer may send: `STATUS` (five lines), `SLEEP <hours>`, `PAUSE`, `RESUME`, `STOP`, `DECIDE <item>: <answer>`, `SKIP <work package or milestone>`. Checkpoint before pausing or stopping.

## 8. Ledger

`docs/build/ledger.md` sections: current state; milestones with exit results; work packages; provisional decisions; blockers and deferred tasks; usage log; carried-forward items. Commit and push after every state change. No personal data and no model names.

## 9. Environment notes from M0

- Composer cannot download dist archives in the build environment and falls back to full git clones (several GB per install). Do not let agents run `composer install`, `require`, or `update`. Build one trimmed install in the session scratchpad (`composer require --dev phpunit/phpunit:<pinned version>` in an empty scratch directory, then delete every `.git` directory and each package's `tests`, `tools`, and `build` directories, and delete Composer's vcs cache). Agents copy that `vendor/` into their worktree and run `composer dump-autoload`. Composer scripts need `COMPOSER_ALLOW_SUPERUSER=1`. For a shipped dependency (M3), obtain it the same way, review what is shipped, and follow ADR 0008 decision 12.
- Agent worktrees are created under `.claude/worktrees/` and may start from an old commit. Every implementation brief begins with `git merge --ff-only <assigned branch>`. Add `/.claude/` to `.git/info/exclude` in a fresh container.
- Worktree commits are local only and are lost if the container is reclaimed; the ledger must say which work packages are unpushed.
- A stop hook asks for uncommitted files to be committed. Do not let two agents write in the main checkout; use worktrees so half-written files are never swept into a checkpoint commit.
- Headless Chromium with Playwright is preinstalled for visual checks. Screenshots stay in the scratchpad.
- Apache is not installed; `.htaccess` behaviour is reasoned, not executed, until the maintainer runs the host check.
- Watch scratch disk use; reviewers building large fixtures have filled it once.

## 10. Completion

When M7 closes except for deferred items, run a final design-tier audit of the whole codebase (repository rules, licences, SPDX coverage, the security baseline in plan section 7, accessibility checks, portability exports), fix findings, then send one notification with the release archive location, test summary, open provisional decisions, and the deferred maintainer tasks with script paths and procedures. Then idle.
