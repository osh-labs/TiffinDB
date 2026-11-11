# ADR 0002: Accounts and roles

Status: accepted by the lead developer (planning session)

## Context

An instance is typically shared by a household: one technical host and several non-technical users.

## Decision

- Individual logins.
- Two roles:
  - Admin: manages users, projects, imports/exports, plugins, schema, and form design.
  - Member: views and edits records in projects they have been granted.
- Access to each project is granted per user.

## Alternatives considered

| Option | Reason not chosen |
|---|---|
| Single shared login | No per-person access control or edit attribution |
| Per-project roles (owner/editor/read-only) | More UI and test burden than v1 needs |

## Consequences

- Schema and form editing are Admin-only, which bounds the stored-HTML risk in ADR 0003.
- A read-only grant level is a likely later addition; the grant table should carry a level column from the start.
