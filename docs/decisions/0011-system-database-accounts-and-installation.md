# ADR 0011: System database, sign-in, sessions, and installation

Status: Accepted (provisional, awaiting maintainer confirmation). Settles context brief section 12 items 18, 19 (in part), 22 (session lifetime, throttling, password rules, CSRF) and the data-directory part of section 4. Implementation design: `docs/specs/M1-foundation.md` sections 5, 6, and 11.

## Context

ADR 0001 places users, sessions, the project registry, and access grants in one SQLite system database; ADR 0002 sets individual logins with Admin and Member roles and per-project grants carrying a level. Plan section 7 sets the password and cookie baseline, and plan section 3.2 requires the installer to keep `data/` away from the web and to prove it over HTTP. Nothing yet fixes where the data directory is found, how the first Admin is created, how a forgotten password is reset without email, how long a session lasts, how sign-in is throttled, or how forms are protected before anyone is signed in.

## Decisions

### 1. Data directory

- The application looks for its data directory in this order and uses the first that holds `system.sqlite`: the path in the optional, git-ignored `config/local.php` (key `data_dir`); `<application folder>/../tiffindb-data`; `<application folder>/data`.
- The installer creates the first candidate that is writable and, where the document root is known, outside it. `<application folder>/data` is used only when nothing outside is writable; it then gets a deny-all `.htaccess` containing only `Require all denied`.
- Setup cannot finish unless the data directory is either outside the document root or shown, by a request over HTTP to the same server, not to serve a probe file. An inconclusive test blocks setup like a failed one.
- Directories are created with mode `0750`, files with `0640`.

### 2. System database

`<data>/system.sqlite`, WAL mode, foreign keys on. Tables: `meta`, `users`, `user_settings`, `sessions`, `login_throttle`, `projects`, `project_grants`, `audit_log` (exact DDL in the M1 spec). Schema changes are forward-only steps keyed by `meta.schema_version`. Enumerated values (roles, grant levels, appearance values) are validated by the core, not by SQL `CHECK` constraints, so that adding a value (for example a read-only grant level) needs no table rebuild.

### 3. Accounts

- Usernames: 3 to 32 characters from `A-Z a-z 0-9 . _ -`, unique without regard to case. Display names: 1 to 60 characters.
- Roles `admin` and `member`. Admins can use every project without a grant. Members use projects they hold a grant for; the only grant level in v1 is `edit` (view and change records).
- People are never deleted, only stopped from signing in ("deactivated"), so attribution in project files keeps resolving. There is always at least one active Admin: the core refuses to demote or deactivate the last one.

### 4. Passwords

- `password_hash` with Argon2id where PHP provides it, bcrypt otherwise; rehash on sign-in when the algorithm or cost changes.
- At least 10 characters and at most 72 bytes (the bcrypt limit, applied whatever the algorithm so that the rule never changes with the host); must not equal the username. No composition rules. No breached-password list (it would need a large data file or a network call).
- Reset is Admin-mediated: an Admin sets a temporary password, which signs the person out everywhere; at their next sign-in they must choose a new password before anything else. No email is sent.
- If the only Admin forgets their password, a command-line script in `tools/` resets it (shell access needed). A recovery route for hosts without shell access is left to M7.

### 5. Sessions

- Server-side, in `sessions`. The cookie holds 32 random bytes (base64url); the database stores only their SHA-256 hash.
- Cookie `tdb_session`: `HttpOnly`, `SameSite=Lax`, `Secure` when PHP sees HTTPS (forwarded-protocol headers are not trusted), `Path` = the application's base path.
- Lifetime: ends after 7 days without use or 30 days after sign-in, whichever comes first. A new session is created at every sign-in. All of a person's sessions end when their password is changed or reset, their role changes, or they are deactivated.

### 6. Throttling

Failed sign-ins are counted per username and per client address, each stored only as an HMAC with a per-installation key (no raw address or attempted username is stored). Five failures for a username, or twenty from one address, within 15 minutes start a wait of 30 seconds that doubles with each further failure up to 15 minutes. Waiting applies to any username, existing or not. A successful sign-in clears that username's count. There is no permanent lockout.

### 7. Cross-site request forgery

- Signed-in forms: one random token per session, sent as the field `_csrf`, compared in constant time.
- Forms used before sign-in (setup, sign-in): a double-submit token, cookie `tdb_csrf` (`HttpOnly`, `SameSite=Strict`) and field `_csrf`, which must match.
- Every `POST` whose `Sec-Fetch-Site` header is `cross-site` is refused before any other check.
- GET and HEAD never change state (ADR 0008).

### 8. First Admin

After the data directory passes its check, the installer writes a one-time setup code to `<data>/setup-code.txt`. Creating the first Admin requires that code, so only someone who can read the server's files can claim the installation. The file is deleted when setup completes. Attempts are throttled as in decision 6.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Data location | Ask the user to type a path | Error-prone for non-technical hosts; kept as an override in `config/local.php` |
| Sessions | PHP native sessions | File-based storage outside our control on shared hosts; no per-user revocation |
| Sessions | Signed stateless cookies | Cannot be revoked on password reset |
| Reset | Email link | Outbound network calls are not allowed (plan section 7) |
| Reset | Security questions | Weak and guessable |
| Throttling | Lockout after N failures | Lets anyone lock out the Admin |
| First Admin | First visitor becomes Admin | A freshly uploaded site can be claimed by a stranger |
| CSRF before sign-in | Anonymous sessions in the database | Writes a row for every visitor and bot |
| Enumerations | SQL `CHECK` constraints | Adding a value needs a table rebuild in SQLite |

## Consequences

- Backups: the data directory holds everything (system database and project folders). Copying it while the application is idle is a complete backup.
- The installer needs one HTTP request from the server to itself; it is not an outbound call to a third party, and it is skipped when the data directory is outside the document root.
- A household with one Admin and no shell access cannot recover a lost Admin password in v1 without help; recommending a second Admin is part of the people screen.
- Throttling and audit data contain no raw IP addresses.

## Costly to reverse

The session cookie and server-side session table, the data-directory search order, and the password rules (changing them affects existing users). Throttle limits and lifetimes are constants.
