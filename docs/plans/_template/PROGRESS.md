# PROGRESS: <plan name>

Plan: docs/plans/<plan-name>/PLAN.md
Integration branch: <branch>   Base: <main>
Updated: <YYYY-MM-DD HH:MM> by orchestrator
Current wave: <n> of <N>   Max parallel: 3

## Next step

<One exact action, e.g. "Dispatch S7, S8 (wave 3)" or "Run full suite (integration)".>

## Slices

| ID | Title | Wave | Model | Review | Status | Slice branch | Merge SHA | Tested SHA | Tests | Notes |
|----|-------|------|-------|--------|--------|--------------|-----------|------------|-------|-------|
| S1 | <title> | 1 | opus | R1 | todo | | | | | |
| S2 | <title> | 1 | sonnet | R0 | todo | | | | | |

Status: todo, dispatched, merged, verified, blocked. Tests: pass, fail, or blank.

## Integration

- [ ] All slices merged
- [ ] Deferred items applied
- [ ] Full suite run: SHA ______ result ______
- [ ] PR opened or updated

## Deferred

- <change request> (from <who>, belongs with <slice or integration>)

## Blocked

- <slice>: <failing tests or open question>; needs <what>

## Flaky

- <test name>: first seen at <SHA>

## Integrator log

- <YYYY-MM-DD> <mode: conflict | cross-slice | contract | final> <slices> -> <resolved | routed | blocked>, <commit>

## Decisions

- <YYYY-MM-DD> <decision> (<who>)
