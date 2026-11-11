# ADR 0001: Runtime stack and storage

Status: accepted by the lead developer (planning session)

## Context

TiffinDB must install on ordinary shared hosting: no root access, no long-running daemons, often no privilege to create databases programmatically. Users are non-technical; the host is a technical household member. Data portability is a core value. Each project is relational (several linked tables).

## Decision

- Server: PHP 8.2 or later (exact floor to be confirmed against dependencies), no application framework, Apache with `.htaccess`.
- Client: server-rendered HTML, CSS, and vanilla JavaScript as progressive enhancement. No build step.
- Project storage: one SQLite file per project (`project.tiffin`) inside a per-project folder that also holds attachments. User tables are real SQLite tables with typed columns; metadata is kept in reserved `_tiffin_*` tables in the same file.
- System storage: one SQLite system database for users, sessions, the project registry, and access grants.
- MySQL/MariaDB is not required.

## Alternatives considered

| Option | Reason not chosen |
|---|---|
| One MySQL database per project | Needs manual database creation per project on most shared hosts |
| One MySQL database, table prefix per project | Works everywhere, but projects are not physically separable; backup and transfer need a dump step |
| SQLite and MySQL drivers both | Doubles storage test surface; restricts SQL to a common subset |
| MySQL for system data only | Adds a credential step to installation for little benefit at household scale |
| PHP micro-framework | More dependencies to vendor and audit; routing needs are small |

## Consequences

- Installation is upload plus a setup page; backup is a folder copy.
- The project file is itself the open, documented data format.
- Write concurrency is limited to what SQLite provides; household-scale use is assumed.
- The data directory must be protected from direct HTTP access; the installer must verify this.
- A MySQL backend would be a later, separate decision.
