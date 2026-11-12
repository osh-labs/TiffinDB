# ADR 0012: Core operations, authorization, snapshots, and audit log

Status: Accepted (provisional, awaiting maintainer confirmation). Settles context brief section 12 items 10 and 15, places the items ADR 0008 decision 1 left to the M1 spec, and fixes the M1 URL shapes. Implementation design: `docs/specs/M1-foundation.md` and `docs/specs/M1-screens.md`.

## Context

Plan section 8 and ADR 0005 require, from M1: every operation callable through the core without the web layer; authorization in the core; trash, project snapshots, and an audit log as core features. ADR 0008 maps the plan's layers to namespaces but leaves system-database access, trash, snapshots, and audit unplaced.

## Decisions

### 1. Namespaces added under `src/Core/`

| Namespace | Holds | May depend on |
|---|---|---|
| `TiffinDB\Core\Error` | Exceptions and the `Problem` value (message key plus parameters) shared by every core layer | none |
| `TiffinDB\Core\Storage` | SQLite connections, transactions, clock, randomness, keys, data directory, file helpers, project snapshots | Error |
| `TiffinDB\Core\System` | System database and its migrations, project registry, audit log | Storage, Error |
| `TiffinDB\Core\Auth` | Users, passwords, sessions, throttling, CSRF tokens, roles, grants, the authorizer | System, Storage, Error |
| `TiffinDB\Core\Schema` | Project file format, field types, schema reading and editing | Storage, Error |
| `TiffinDB\Core\Query` | M1: record trash. M2 onward: record CRUD, search, sort, filter | Schema, Storage, Error |
| `TiffinDB\Core\Install` | Requirement check, data-folder setup and exposure test, first Admin | Auth, System, Storage, Error |
| `TiffinDB\Core\App` | **The core operations**: one entry point per action, each taking the acting user, checking permission, taking snapshots, writing the audit log | every Core namespace except Install |

The web layer calls `Core\App` (and `Core\Install` during setup) only; it never touches Storage, Schema, or the databases directly. The post-v1 MCP adapter will call the same `Core\App` operations. A repository test enforces these dependencies.

### 2. Authorization in the core

- Every `Core\App` operation takes an `Actor` as its first argument and checks a named `Permission` before doing anything. Controllers make no authorization decision; they only require that someone is signed in.
- A Member asking for a project they hold no grant for gets "not found", not "access denied", so project names and keys do not leak.
- The permission table (M1 spec section 9.2) decides v1 Member rights: Members view and change records, move records to the trash, and restore them, in granted projects. Only Admins empty the trash, change schema or forms, import, export, share, manage people and plugins, and take snapshots. Saved collections are provisionally open to Members (to be confirmed in the M4 spec).

### 3. Snapshots

- A snapshot is a consistent copy of `project.tiffin` made with `VACUUM INTO`, stored as `snapshots/<UTC time>-<reason>-<4 characters>.tiffin` in the project folder.
- Taken automatically, before the change, for: a project-format upgrade, deleting a table, deleting a field or relation, changing a field's type, changing field options in a way that clears values, and emptying the trash. Later milestones add import (M3) and MCP schema changes (post-v1).
- Admins may also take one by hand ("manual"). The 20 newest automatic snapshots per project are kept; older automatic ones are deleted when a new one is taken. Manual snapshots are never deleted automatically.
- Restoring a snapshot is not built in M1: replacing a live SQLite file safely needs a design of its own (open connections, WAL files). Until then a snapshot is restored by hand while the application is idle, as `docs/formats/tiffin.md` describes.

### 4. Audit log

- In the system database (`audit_log`), so it survives project deletion and is never copied with a project.
- Records security and structure events: setup completion; sign-in, failed sign-in, sign-out; people created, changed, deactivated, reactivated, password set by an Admin, own password changed; sharing changes; project created and renamed; tables, fields, and relations created, changed, and deleted; snapshots taken; trash emptied. Record-level edits are not logged in v1 (plan section 2: no per-record history).
- Failed sign-ins store no attempted username and no address. Entries never contain passwords, tokens, or field values.
- Kept indefinitely, except failed sign-in entries older than 90 days, which are pruned when new ones are written. An Admin screen for the log is not part of M1.
- Written after the change it describes has been committed. If writing the entry fails, the failure is logged to the server error log and the operation still reports success.

### 5. M1 URL shapes

M1 fixes these paths (in addition to ADR 0008 decision 3): `/setup`, `/sign-in`, `/sign-out`, `/account/password`, `/projects`, `/projects/new`, `/projects/{project}`, `/projects/{project}/settings`, `/projects/{project}/sharing`, `/projects/{project}/tables`, `/projects/{project}/tables/new`, `/projects/{project}/tables/{table}/fields` with `.../new`, `.../{field}`, and `delete` and `move` actions, `/admin/people`, `/admin/people/new`, `/admin/people/{person}`, each with the action paths (`settings`, `delete`, `move`, `type`, `password`, `projects`, `deactivate`, `reactivate`) listed in the complete route table of `docs/specs/M1-screens.md` section 2.3. `{project}`, `{table}`, `{field}`, and `{person}` are keys (ADR 0010). `/projects/{project}/tables/{table}` is reserved for the M2 list view. The attachment endpoint is project-scoped, `/projects/{project}/files/{attachment}`, replacing the top-level `/files` prefix that ADR 0008 reserved.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Operations | Authorization in controllers | Forbidden by plan section 8; the MCP adapter would have to repeat it |
| Operations | Authorization inside Schema and Storage | Mixes mechanics with policy; Schema tests would need users |
| Snapshots | Copy the file with `copy()` | Not consistent while WAL files hold recent changes |
| Snapshots | Unlimited retention | Disk quotas on shared hosts |
| Audit | Per-project log in the project file | Carries user data into exported projects; lost with the project |
| Audit | Log every record edit | Volume; plan excludes per-record history in v1 |

## Consequences

- `Core\App` is the security boundary. Its tests run headlessly with fake clocks and seeded randomness.
- Snapshot restore and the audit screen are deferred and recorded as open items.
- A reverse dependency from Forms (M2) to Query, needed to bind records, is a later amendment to this table.

## Costly to reverse

The `Core\App` boundary (every caller depends on it) and the M1 URL shapes. Snapshot retention and the event list are constants.
