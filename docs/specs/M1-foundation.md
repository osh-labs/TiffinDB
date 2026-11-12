# M1 design spec: foundation

Status: design spec for milestone M1, part 1 of 2. This part covers the core: storage, the system database, accounts and sessions, the project file and schema engine, field types, trash, snapshots, audit log, the installer's core, the core operations, the test plan, the provisional decisions, and **all M1 work packages**. Part 2, `docs/specs/M1-screens.md`, covers the web layer: request pipeline changes, routes, controllers, templates, stylesheet, strings, and how screens are checked against the mock-ups. Screen designs are under `docs/design/m1/`.

Decisions this spec relies on: ADR 0008 (layout, conventions), ADR 0009 (provisional defaults), ADR 0010 (project file format and identifiers), ADR 0011 (system database, sign-in, sessions, installation), ADR 0012 (core operations and safeguards). The file format is documented for users in `docs/formats/tiffin.md`; where this spec and that document differ, stop and report.

Audience: engineers holding this spec, `CLAUDE.md`, and the files a work package names. `CLAUDE.md` has the highest authority.

## 1. Goals, non-goals, exit criteria

### 1.1 Goals

1. A headless core under `src/Core/` that can install TiffinDB, manage people, sign people in and out, create and open projects, and create and change tables, fields, and relations, with authorization, snapshots, and an audit log, all callable without the web layer.
2. The 17 v1 field types behind one interface, with storage, option validation, value validation, parsing, and conversion.
3. Record trash at the storage level, with core operations.
4. The web screens for M1 (part 2): installer, sign-in, own password, project list, new project, project settings, sharing, people, tables, and the table and field editor.
5. Tests for every M1 exit criterion.

### 1.2 Non-goals for M1

- Record create, edit, list, search (M2). M1 tests insert records through a test helper only.
- Display settings screen (M2). The `user_settings` table exists and is read for the root classes; nothing in M1 changes it.
- Rendering of field values or inputs in forms (M2); relation picker and attachment upload (M4); import and export (M3); form storage (M2, format version 2).
- Snapshot restore, project deletion, the audit-log screen, the plugins screen. Recorded as open items (section 16).
- Password recovery without shell access for a lone Admin (M7).

### 1.3 Exit criteria

| Exit criterion (plan 8, ui-design-plan 11) | How M1 meets it |
|---|---|
| Headless tests for schema and storage | Sections 4 to 10; tests T-STO, T-SYS, T-AUTH, T-SCH, T-TYP, T-TRASH, T-APP, T-INS (section 13) run without the web layer |
| Admin can create a project and a table | Core: T-APP-10, T-APP-20. End to end through the kernel, from an empty data directory: T-E2E-1 (part 2 section 9) |
| Screens match the mock-ups at 1360 px and 390 px | Part 2 section 8 defines "match" for the two mock-up screens and for the screens without a mock-up; structural tests T-SCR and the visual procedure V-1 to V-8 |
| Also from M1 (plan 8): core-only operations, core authorization, trash, snapshots, audit log | Sections 9 and 10; T-APP-30 to T-APP-60, T-TRASH, T-AUD |

## 2. Code layout

All new PHP is under `src/Core/` in the namespaces of ADR 0012 decision 1, following ADR 0008 decision 6 (`final` classes, typed `readonly` properties, `declare(strict_types=1)`, SPDX block, no `@`, no global state). Directories created by M1:

```
src/Core/Error/       shared exceptions and Problem
src/Core/Storage/     Database, Sqlite, Clock, Random, Keys, Timestamp, DataDirectory, Fs, Snapshots
src/Core/System/      SystemDatabase, SystemSchema, ProjectRegistry, AuditLog
src/Core/Auth/        Role, GrantLevel, Permission, Actor, PasswordHasher, PasswordPolicy, Usernames,
                      DisplayNames, Users, Grants, Sessions, LoginThrottle, Authenticator, Csrf, Authorizer
src/Core/Schema/      Identifier, ProjectFile, FormatMigrations, Schema, TableDef, FieldDef,
                      RelationDef, Cardinality, SchemaEditor, FieldTypes
src/Core/Schema/Type/ FieldType, Checked, and one class per field type
src/Core/Query/       Trash
src/Core/Install/     Installer, RequirementCheck, DataFolder, ExposureProbe, HttpProbe,
                      LoopbackHttpProbe, ProbeTarget, SetupCode
src/Core/App/         Operations and the per-area operation classes, value objects
tools/admin/          reset-password.php (CLI)
tests/core/<area>/    tests, namespace TiffinDB\Tests\Core\<Area>
tests/Support/        TempDir, FixedClock, SeededRandom, CoreFixture
```

Each work package (section 15) lists its exact files. A package may add small private helper classes inside its own directories and must report them.

`phpunit.xml.dist` gains a `<source>` element including `src/` (announced in the M0 spec section 3).

### 2.1 Dependency rule

ADR 0012 decision 1 is enforced by a new test, `tests/repo/CoreLayerRulesTest.php` (T-REPO-9): for each PHP file under `src/Core/<Area>/`, every `use` statement and every fully qualified name `TiffinDB\Core\<Other>` must name an area in that area's allowed list:

| Area | May use |
|---|---|
| Error | (none) |
| Storage | Error |
| System | Storage, Error |
| Auth | System, Storage, Error |
| Schema | Storage, Error |
| Query | Schema, Storage, Error |
| Install | Auth, System, Storage, Error |
| App | Error, Storage, System, Auth, Schema, Query |

The existing T-REPO-7 (no `TiffinDB\Web`, `TiffinDB\Mcp`, superglobals, or `filter_input` under `src/Core/`) stays and now runs against real files.

## 3. Core conventions

### 3.1 Errors and problems

`TiffinDB\Core\Error`:

```php
final class Problem
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly ?string $field,   // input name the problem is about, or null for the whole request
        public readonly string $key,      // message key, see section 12
        public readonly array $params = [],
    ) {}
}

abstract class CoreException extends \RuntimeException {}
final class NotFound extends CoreException {}            // also used for "exists but you may not see it"
final class AccessDenied extends CoreException {}
final class ValidationFailed extends CoreException
{
    /** @param non-empty-list<Problem> $problems */
    public function __construct(public readonly array $problems) {}
}
final class ConfirmationRequired extends CoreException
{
    // The change would clear or rewrite stored values; repeat it with confirmation.
    public function __construct(public readonly int $records, public readonly int $values) {}
}
final class Busy extends CoreException {}                 // SQLite busy or locked after the timeout
final class Unavailable extends CoreException
{
    // A project file is missing, is not a TiffinDB project, has a newer format, or does not match the registry.
    public function __construct(public readonly string $reason) {}   // 'missing' | 'not-project' | 'newer' | 'mismatch'
}
```

Rules:

- Core never returns or throws English sentences meant for people. Exception messages (`getMessage()`) are for logs only and never contain user data, passwords, tokens, or paths outside the data directory.
- Validation collects every problem it can find before throwing `ValidationFailed`, so a form can show all errors at once.
- Programming errors (wrong argument types, calling an operation in a transaction) throw `\LogicException` or `\InvalidArgumentException`; the web layer turns them into the generic 500 page.

### 3.2 Time and randomness

- `TiffinDB\Core\Storage\Clock` (interface): `now(): \DateTimeImmutable`, always UTC. `SystemClock` implements it. Tests use `tests/Support/FixedClock` (settable, advanceable).
- `Timestamp::format(\DateTimeImmutable): string` gives `YYYY-MM-DDTHH:MM:SSZ`; `Timestamp::parse(string): \DateTimeImmutable` accepts exactly that form. All stored times use it.
- `TiffinDB\Core\Storage\Random` (interface): `bytes(int $length): string`. `SecureRandom` uses `random_bytes`. Tests use `tests/Support/SeededRandom` (deterministic, from a documented seed, built on `hash('sha256', seed . counter)`). Nothing in `src/Core/` calls `random_bytes`, `random_int`, `mt_rand`, `time()`, or `date()` directly; T-REPO-9 also checks this.

### 3.3 SQL

- Every SQL statement is a string literal or a concatenation of string literals and values returned by `TiffinDB\Core\Schema\Identifier` (section 7.1). Values are always bound parameters. The only other generated SQL text allowed is a list of `?` placeholders for an `IN (...)` clause, built by `Database::placeholders(int $count)`.
- Transactions are short: no file I/O other than the database inside a transaction, no snapshot inside a transaction.

### 3.4 Input strings

Every string accepted by a core operation is checked to be valid UTF-8 (`preg_match('//u', $s) === 1`) and is trimmed of leading and trailing white space (`\s` in Unicode mode) unless the field type says otherwise. Character counts are Unicode code points, counted with `preg_match_all('/./su', $s)`; the `mbstring` extension is not required.

## 4. Storage (`TiffinDB\Core\Storage`)

### 4.1 `Sqlite` and `Database`

```php
final class Sqlite
{
    public const MIN_VERSION = '3.35.0';
    public const BUSY_TIMEOUT_MS = 5000;

    /** Opens an existing file. Throws Unavailable('missing') when it does not exist. */
    public static function open(string $path, int $busyTimeoutMs = self::BUSY_TIMEOUT_MS): Database;
    /** Creates a new file; throws \RuntimeException when the path already exists. */
    public static function create(string $path, int $busyTimeoutMs = self::BUSY_TIMEOUT_MS): Database;
    public static function memory(): Database;   // tests and the requirement check
    public static function libraryVersion(): ?string;  // null when pdo_sqlite is missing
}

final class Database
{
    public function run(string $sql, array $params = []): int;           // affected rows
    public function all(string $sql, array $params = []): array;         // list of associative rows
    public function one(string $sql, array $params = []): ?array;
    public function value(string $sql, array $params = []): mixed;       // first column of first row, or null
    public function lastInsertId(): int;
    /** @template T @param callable(Database): T $work @return T */
    public function write(callable $work): mixed;                       // BEGIN IMMEDIATE ... COMMIT, ROLLBACK on any Throwable
    public function inTransaction(): bool;
    public function journalMode(): string;                              // 'wal' or what SQLite reported
    public function path(): string;                                     // ':memory:' for memory databases
    public static function placeholders(int $count): string;            // '?, ?, ?'; $count >= 1
    public function close(): void;
}
```

Connection settings, applied by `open`, `create`, and `memory`, in this order:

| Setting | Value | Note |
|---|---|---|
| PDO attributes | `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE` = `FETCH_ASSOC`, `STRINGIFY_FETCHES` false | Integers come back as `int` (PHP 8.1+ behaviour of pdo_sqlite) |
| `PRAGMA busy_timeout` | 5000 | Before anything else that may lock |
| `PRAGMA journal_mode = WAL` | Read the result; store it. If not `wal`, carry on (rollback journal) | Not for `memory()` |
| `PRAGMA synchronous = NORMAL` | | Safe with WAL |
| `PRAGMA foreign_keys = ON` | | |

`create` refuses an existing path (checked with `file_exists` immediately before opening). `open` refuses a missing path (`Unavailable('missing')`).

`write()` must not be nested; calling it inside a transaction throws `\LogicException`. A `\PDOException` whose driver code (`errorInfo[1]`) is 5 (`SQLITE_BUSY`) or 6 (`SQLITE_LOCKED`) is rethrown as `Busy` from every method. Other `PDOException`s propagate unchanged.

### 4.2 `Keys`

```php
final class Keys
{
    public const PATTERN = '/^[a-z2-7]{12}\z/';
    public const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';
    public function __construct(Random $random) {}
    public function generate(): string;                      // 8 random bytes; the first 60 bits, 5 bits per character
    /** @param callable(string): bool $taken */
    public function generateUnique(callable $taken): string; // retries up to 10 times, then \RuntimeException
    public static function isValid(mixed $value): bool;
}
```

Also `Keys::short(int $length)` for the 4-character snapshot suffix, same alphabet.

### 4.3 `DataDirectory` and `Fs`

```php
final class DataDirectory
{
    public const SIBLING = '../tiffindb-data';   // relative to the application folder
    public const IN_TREE = 'data';

    /** The installed data directory: the first candidate holding system.sqlite, or null. */
    public static function locate(string $appRoot, ?string $configured): ?self;
    /** Candidates in search order (ADR 0011 decision 1). With $configured set, only that one. */
    public static function candidates(string $appRoot, ?string $configured): array; // list<DataCandidate>

    public function path(): string;                 // absolute, no trailing slash
    public function label(): string;                // for messages: '../tiffindb-data', 'data', or 'the folder set in config/local.php'
    public function systemDatabase(): string;       // <path>/system.sqlite
    public function projectFolder(string $projectKey): string;  // <path>/projects/<key>; key checked with Keys::isValid
}

final class DataCandidate
{
    public function __construct(
        public readonly string $path,      // absolute
        public readonly string $label,
        public readonly bool $inTree,      // true for <app>/data
    ) {}
}
```

`Fs` (static helpers, the only place that touches the file system outside SQLite):

| Method | Behaviour |
|---|---|
| `makeDir(string $path): void` | `mkdir` mode `0750`, not recursive; throws `\RuntimeException` on failure or when the path exists as a non-directory. Then `chmod 0750` |
| `writeNew(string $path, string $contents): void` | Opens with mode `x` (fails if the file exists), writes, closes, `chmod 0640` |
| `remove(string $path): void` | Deletes a file; no error when absent |
| `isWritableDir(string $path): bool` | |
| `nearestExisting(string $path): ?string` | `realpath` of the path or of its closest existing ancestor |
| `isInside(string $path, string $root): bool` | Both resolved with `nearestExisting`; true when equal or `$path` starts with `$root . '/'` |

PHP's error handler, not `@`, deals with warnings: wrap calls that can warn in a local `set_error_handler` that turns the warning into a `\RuntimeException`, restored in `finally`.

### 4.4 `Snapshots`

```php
enum SnapshotReason: string
{
    case FormatUpgrade = 'format-upgrade';
    case TableDelete = 'table-delete';
    case FieldDelete = 'field-delete';
    case FieldTypeChange = 'field-type-change';
    case FieldOptions = 'field-options';
    case EmptyTrash = 'empty-trash';
    case Manual = 'manual';
}

final class SnapshotInfo
{
    public function __construct(
        public readonly string $file,            // name only
        public readonly \DateTimeImmutable $takenAt,
        public readonly SnapshotReason $reason,
        public readonly int $size,
    ) {}
}

final class Snapshots
{
    public const KEEP_AUTOMATIC = 20;
    public const NAME = '/^(\d{8}T\d{6}Z)-([a-z-]+)-([a-z2-7]{4})\.tiffin\z/';

    public function __construct(Clock $clock, Keys $keys) {}
    /** VACUUM INTO <folder>/snapshots/<name>; creates snapshots/ if missing; prunes afterwards. */
    public function take(Database $db, string $projectFolder, SnapshotReason $reason): SnapshotInfo;
    /** Newest first; files whose names do not match NAME are ignored. */
    public function list(string $projectFolder): array;
}
```

`take` throws `\LogicException` when `$db->inTransaction()`. The file path is passed to `VACUUM INTO ?` as a bound parameter. Pruning deletes the oldest automatic snapshots beyond 20; `manual` ones are never pruned.

## 5. System database (`TiffinDB\Core\System`)

### 5.1 DDL, schema version 1

Exact statements, executed in this order inside one transaction by `SystemSchema::migrate()`:

```sql
CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
) WITHOUT ROWID;

CREATE TABLE users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    key                  TEXT NOT NULL UNIQUE,
    username             TEXT NOT NULL,
    username_norm        TEXT NOT NULL UNIQUE,
    display_name         TEXT NOT NULL,
    role                 TEXT NOT NULL,
    password_hash        TEXT NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    active               INTEGER NOT NULL DEFAULT 1,
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    password_changed_at  TEXT NOT NULL,
    last_sign_in_at      TEXT
);

CREATE TABLE user_settings (
    user_id       INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    theme         TEXT NOT NULL DEFAULT 'light',
    accent        TEXT NOT NULL DEFAULT 'teal',
    typeface      TEXT NOT NULL DEFAULT 'inter',
    text_size     TEXT NOT NULL DEFAULT 'standard',
    line_spacing  TEXT NOT NULL DEFAULT 'standard',
    reduce_motion INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE sessions (
    token_hash          TEXT PRIMARY KEY,
    user_id             INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    csrf_token          TEXT NOT NULL,
    flash               TEXT,
    created_at          TEXT NOT NULL,
    last_seen_at        TEXT NOT NULL,
    idle_expires_at     TEXT NOT NULL,
    absolute_expires_at TEXT NOT NULL
) WITHOUT ROWID;
CREATE INDEX sessions_user ON sessions (user_id);

CREATE TABLE login_throttle (
    bucket            TEXT PRIMARY KEY,
    failures          INTEGER NOT NULL,
    window_started_at TEXT NOT NULL,
    blocked_until     TEXT
) WITHOUT ROWID;

CREATE TABLE projects (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    key        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    state      TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    created_by INTEGER REFERENCES users (id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX projects_name ON projects (name COLLATE NOCASE);

CREATE TABLE project_grants (
    project_id INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    level      TEXT NOT NULL DEFAULT 'edit',
    granted_at TEXT NOT NULL,
    granted_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    PRIMARY KEY (project_id, user_id)
) WITHOUT ROWID;
CREATE INDEX project_grants_user ON project_grants (user_id);

CREATE TABLE audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    at            TEXT NOT NULL,
    actor_user_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
    action        TEXT NOT NULL,
    project_id    INTEGER REFERENCES projects (id) ON DELETE SET NULL,
    target_kind   TEXT,
    target_key    TEXT,
    detail        TEXT NOT NULL DEFAULT '{}'
);
CREATE INDEX audit_log_at ON audit_log (at);
CREATE INDEX audit_log_action ON audit_log (action, at);
```

Value rules enforced in code (ADR 0011 decision 2): `users.role` in `admin`, `member`; booleans 0 or 1; `projects.state` in `creating`, `active`; `project_grants.level` in `edit`; `user_settings` values in the lists of section 5.5.

### 5.2 `meta` keys

| Key | Value | Written |
|---|---|---|
| `schema_version` | `1` | By `migrate()` |
| `installation_key` | A key (section 4.2) | At creation |
| `hmac_key` | 64 hexadecimal characters from 32 random bytes; used for throttle buckets | At creation |
| `created_at` | Timestamp | At creation |
| `installed_at` | Timestamp | When the first Admin is created; its presence means "installed" |
| `installed_with` | Application version | Same time |

### 5.3 `SystemDatabase` and `SystemSchema`

```php
final class SystemDatabase
{
    /** Creates system.sqlite in the data directory, migrates, writes installation_key, hmac_key, created_at. */
    public static function create(DataDirectory $data, Clock $clock, Random $random): self;
    /** Opens and migrates forward if schema_version is lower; refuses a higher version (Unavailable('newer')). */
    public static function open(DataDirectory $data): self;
    public function db(): Database;
    public function meta(string $key): ?string;
    public function setMeta(string $key, string $value): void;
    public function isInstalled(): bool;     // meta installed_at present
    public function hmac(string $purpose, string $value): string;  // hex HMAC-SHA256 with hmac_key over "$purpose\0$value"
}

final class SystemSchema
{
    public const VERSION = 1;
    /** @var array<int, list<string>> target version => statements; version 1 is section 5.1 */
    public const STEPS = [...];
    public static function migrate(Database $db, ?array $steps = null): void;  // $steps is a test seam
}
```

Upgrades are forward only, one transaction per step, `schema_version` updated in the same transaction. Before a step runs on an existing installation the file is copied with `VACUUM INTO` to `<data>/system-before-v<N>-<timestamp>.sqlite`. (Not exercised by M1, which only has version 1; tested with an injected step, T-SYS-3.)

### 5.4 `ProjectRegistry`

```php
final class ProjectRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $state,          // 'creating' | 'active'
        public readonly string $createdAt,
        public readonly ?int $createdBy,
    ) {}
}

final class ProjectRegistry
{
    public function __construct(SystemDatabase $system) {}
    public function add(string $key, string $name, int $createdBy, string $now): ProjectRecord;  // state 'creating'
    public function activate(int $id): void;
    public function remove(int $id): void;                     // only used to undo a failed create
    public function byKey(string $key): ?ProjectRecord;
    public function nameTaken(string $name, ?int $exceptId = null): bool;   // COLLATE NOCASE
    public function rename(int $id, string $name): void;
    /** Active projects, ordered by name COLLATE NOCASE then key. */
    public function all(): array;
    /** Active projects a Member holds a grant for, same order. */
    public function forMember(int $userId): array;
}
```

### 5.5 Appearance values in `user_settings`

| Column | Allowed values (default first) |
|---|---|
| `theme` | `light`, `dark`, `hc-light`, `hc-dark` |
| `accent` | `teal`, `indigo`, `plum`, `graphite` |
| `typeface` | `inter`, `atkinson`, `lexend`, `source-serif`, `device-sans`, `device-serif` |
| `text_size` | `standard`, `small`, `large`, `larger`, `largest` |
| `line_spacing` | `standard`, `roomy`, `very-roomy` |
| `reduce_motion` | 0, 1 |

Except for the defaults (`light`, `teal`, `inter`, `standard`, `standard`, 0), the values are the M0 query-parameter values (`TiffinDB\Web\Appearance::MAP`), so the web layer maps them with the existing table (part 2 section 2.7). A row is inserted with defaults whenever a user is created. M1 never changes it.

### 5.6 `AuditLog`

```php
final class AuditLog
{
    public const FAILED_SIGN_IN_RETENTION_DAYS = 90;
    public function __construct(SystemDatabase $system, Clock $clock) {}
    /** @param array<string, string|int|bool|null> $detail  flat; no passwords, tokens, or field values */
    public function record(string $action, ?int $actorUserId, ?int $projectId = null,
                           ?string $targetKind = null, ?string $targetKey = null, array $detail = []): void;
    /** Newest first. */
    public function recent(int $limit, int $offset = 0): array;   // list<AuditEntry>
}
```

`record()` writes in its own short transaction. When the action is `auth.sign_in_failed`, it first deletes `auth.sign_in_failed` rows older than 90 days. If writing fails with any `\Throwable`, `record()` logs one line with `error_log` (action and exception class only) and returns; it never throws (ADR 0012 decision 4). `AuditEntry` mirrors the row with `detail` decoded.

Actions (the complete v1 list for M1; constants on `TiffinDB\Core\System\AuditAction`):

| Action | Target kind, key | Detail |
|---|---|---|
| `setup.complete` | `user`, first Admin's key | none |
| `auth.sign_in` | `user`, key | none |
| `auth.sign_in_failed` | `user`, key when the username matched a person, else null | `reason`: `unknown-user`, `bad-password`, `inactive`, `throttled` |
| `auth.sign_out` | `user`, key | none |
| `auth.password_changed` | `user`, key | none |
| `people.created` | `user`, key | `role` |
| `people.updated` | `user`, key | `role`, `username_changed` (bool), `name_changed` (bool) |
| `people.password_set` | `user`, key | none |
| `people.deactivated`, `people.reactivated` | `user`, key | none |
| `people.access_changed` | `user`, key | `added`, `removed` (counts) |
| `project.created` | `project`, key | none |
| `project.renamed` | `project`, key | none |
| `project.sharing_changed` | `project`, key | `added`, `removed` (counts) |
| `schema.table_created`, `schema.table_changed`, `schema.table_deleted` | `table`, key | `records` (for delete) |
| `schema.field_created`, `schema.field_changed`, `schema.field_moved`, `schema.field_deleted` | `field`, key | `type`; for changes `type_from`, `values_cleared` |
| `snapshot.taken` | `snapshot`, file name | `reason` |
| `trash.emptied` | `table`, key or null | `records` |

`schema.*` and `snapshot.taken` and `trash.emptied` carry the project id.

## 6. Accounts, sessions, and permissions (`TiffinDB\Core\Auth`)

### 6.1 Values

```php
enum Role: string { case Admin = 'admin'; case Member = 'member'; }
enum GrantLevel: string { case Edit = 'edit'; }

final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userKey,
        public readonly Role $role,
        public readonly string $displayName,
    ) {}
    public function isAdmin(): bool;
}

final class UserRecord
{
    public function __construct(
        public readonly int $id, public readonly string $key, public readonly string $username,
        public readonly string $displayName, public readonly Role $role, public readonly bool $active,
        public readonly bool $mustChangePassword, public readonly string $createdAt,
        public readonly ?string $lastSignInAt,
    ) {}
    public function initials(): string;   // section 6.3
}
```

`Actor` objects are created only by `Authenticator` (from a session), by `Installer` (the first Admin), and by `tools/admin/reset-password.php`. There is no "system" actor in M1.

### 6.2 Usernames, display names, passwords

`Usernames::normalise(string): string` lower-cases ASCII letters. `Usernames::problems(string $username): list<Problem>`: after trimming, 3 to 32 characters, each in `A-Z a-z 0-9 . _ -` (key `problem.username.invalid`, field `username`).

Display names: 1 to 60 characters after trimming, no control characters (keys `problem.name.empty`, `problem.name.too_long` with `max` 60, `problem.text.control`; field `display_name`). `DisplayNames::problems(string $name, string $field = 'display_name'): list<Problem>` applies these rules; `Installer::createFirstAdmin` and `PeopleOperations` both call it.

```php
final class PasswordPolicy
{
    public const MIN_CHARACTERS = 10;
    public const MAX_BYTES = 72;
    /** Problems for field $field: too short, too long, same as username (case-insensitive), invalid UTF-8. */
    public static function problems(string $password, string $username, string $field = 'password'): array;
}

final class PasswordHasher
{
    /** $options null: Argon2id (memory_cost 19456 KiB, time_cost 2, threads 1) where available, else bcrypt cost 12. Tests pass ['bcrypt' => 4]. */
    public function __construct(?array $options = null) {}
    public function hash(string $password): string;
    public function verify(string $password, string $hash): bool;
    public function needsRehash(string $hash): bool;
    public function algorithm(): string;           // 'argon2id' | 'bcrypt'
    public function verifyDummy(string $password): void;  // same cost as verify against a hash made once per instance
}
```

Passwords are never trimmed. Password problems use keys `problem.password.too_short` (`min` 10), `problem.password.too_long`, `problem.password.same_as_username`.

Temporary passwords suggested to Admins (`PeopleOperations::suggestPassword()`): 12 characters from `abcdefghjkmnpqrstuvwxyz23456789` in three groups of four joined by `-` (14 characters, about 59 bits).

### 6.3 Initials

`UserRecord::initials()`: split the display name on white space; take the first letter (code point, upper-cased with `\IntlChar` if available, else `strtoupper` for ASCII) of the first and of the last word; one word gives one letter. "Example Admin" gives "EA". The chip shows them; they are decoration (`aria-hidden`).

### 6.4 `Users` and `Grants`

Repositories over the system database, no permission checks (those are in `Core\App`):

```php
final class Users
{
    public function __construct(SystemDatabase $system, Keys $keys, Clock $clock) {}
    public function create(string $username, string $displayName, Role $role, string $passwordHash, bool $mustChange): UserRecord; // also inserts user_settings
    public function byId(int $id): ?UserRecord;
    public function byKey(string $key): ?UserRecord;
    public function byUsername(string $username): ?UserRecord;       // normalises
    public function passwordHash(int $id): string;
    public function usernameTaken(string $username, ?int $exceptId = null): bool;
    /** Ordered by display name COLLATE NOCASE, then key. */
    public function all(): array;
    public function update(int $id, string $username, string $displayName, Role $role): void;
    public function setPassword(int $id, string $hash, bool $mustChange): void;   // sets password_changed_at
    public function setActive(int $id, bool $active): void;
    public function markSignedIn(int $id): void;
    public function countActiveAdmins(): int;
    public function settings(int $id): array;   // the user_settings row as column => value
}

final class Grants
{
    public function __construct(SystemDatabase $system, Clock $clock) {}
    public function level(int $projectId, int $userId): ?GrantLevel;
    /** @return list<int> user ids */
    public function usersFor(int $projectId): array;
    /** @return list<int> project ids */
    public function projectsFor(int $userId): array;
    /** Replaces the set; returns [added, removed]. */
    public function setUsersFor(int $projectId, array $userIds, int $grantedBy): array;
    public function setProjectsFor(int $userId, array $projectIds, int $grantedBy): array;
    public function countMembersFor(int $projectId): int;   // grants held by active Members
}
```

### 6.5 `Sessions`

```php
final class SessionRecord
{
    public function __construct(
        public readonly string $tokenHash, public readonly int $userId, public readonly string $csrfToken,
        public readonly string $createdAt, public readonly string $absoluteExpiresAt,
    ) {}
}

final class IssuedSession
{
    public function __construct(public readonly string $token, public readonly SessionRecord $record) {}
}

final class Sessions
{
    public const IDLE_SECONDS = 604800;        // 7 days
    public const ABSOLUTE_SECONDS = 2592000;   // 30 days
    public const TOUCH_SECONDS = 300;
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}\z/';

    public function __construct(SystemDatabase $system, Clock $clock, Random $random) {}
    public function start(int $userId): IssuedSession;
    public function resolve(string $token): ?SessionRecord;
    public function end(string $token): void;
    public function endAllFor(int $userId): void;
    public function setFlash(string $token, string $key, array $params = []): void;
    public function takeFlash(string $token): ?array;   // ['key' => ..., 'params' => [...]], cleared on read
}
```

- `start`: 32 random bytes encoded base64url without padding (43 characters) as the token; `token_hash` = lower-case hex SHA-256 of the token; `csrf_token` = 64 hexadecimal characters from 32 more random bytes; `idle_expires_at` = now + 7 days, `absolute_expires_at` = now + 30 days. Deletes expired rows of any user first.
- `resolve`: returns null for a token not matching `TOKEN_PATTERN`, an unknown hash, an expired row (deleted), or a row whose user is inactive (deleted). When `last_seen_at` is more than 300 seconds old, sets `last_seen_at` = now and `idle_expires_at` = the earlier of now + 7 days and `absolute_expires_at`.
- The token is never stored, logged, or put in the audit log.

### 6.6 `LoginThrottle`

```php
final class LoginThrottle
{
    public const WINDOW_SECONDS = 900;
    public const USER_LIMIT = 5;
    public const ADDRESS_LIMIT = 20;
    public const SETUP_LIMIT = 5;
    public const FIRST_WAIT = 30;
    public const MAX_WAIT = 900;

    public function __construct(SystemDatabase $system, Clock $clock) {}
    public static function userBucket(SystemDatabase $s, string $usernameNorm): string;  // 'u:' . hmac('throttle-user', ...)
    public static function addressBucket(SystemDatabase $s, string $address): string;    // 'a:' . hmac('throttle-address', ...)
    public const SETUP_BUCKET = 's:setup';
    /** Seconds to wait (0 = go ahead): the longest remaining block among the buckets. */
    public function wait(array $buckets): int;
    public function failure(string $bucket, int $limit): void;
    public function clear(string $bucket): void;
}
```

`failure`: if the row is missing, or its window started more than 900 seconds ago and it is not blocked now, reset it to `failures = 0`, window = now. Then `failures + 1`; when `failures >= limit`, `blocked_until` = now + min(30 × 2^(failures − limit), 900) seconds. Rows whose window started more than one day ago and that are not blocked are deleted on each call. The client address is `REMOTE_ADDR` as given by the server; forwarding headers are ignored.

### 6.7 `Authenticator`

```php
enum SignInOutcome { case Success; case Invalid; case Throttled; }

final class SignInResult
{
    public function __construct(
        public readonly SignInOutcome $outcome,
        public readonly ?IssuedSession $session = null,
        public readonly int $waitSeconds = 0,
        public readonly bool $mustChangePassword = false,
    ) {}
}

final class SignedIn
{
    public function __construct(
        public readonly Actor $actor,
        public readonly SessionRecord $session,
        public readonly bool $mustChangePassword,
    ) {}
}

final class Authenticator
{
    public function __construct(SystemDatabase $s, Users $users, Sessions $sessions, LoginThrottle $throttle,
                                PasswordHasher $hasher, AuditLog $audit) {}
    public function signIn(string $username, string $password, string $clientAddress): SignInResult;
    /** The actor and session for a cookie token, or null. */
    public function resume(string $token): ?SignedIn;
    public function signOut(string $token): void;
}
```

`signIn`, in order:

1. Buckets: user bucket of the normalised username (also when it does not match the username rules), address bucket. If `wait() > 0`: audit `auth.sign_in_failed` (`throttled`; actor id when the username matches a person), return `Throttled` with the wait. The password is not checked.
2. Look up the person. If none: `verifyDummy`, then failure `unknown-user`.
3. If the password does not verify: failure `bad-password`. If it verifies but the person is inactive: failure `inactive`.
4. On failure: `failure(userBucket, 5)`, `failure(addressBucket, 20)`, audit, return `Invalid`. Every failure reason gives the same result to the caller.
5. On success: `clear(userBucket)`; rehash if `needsRehash`; `markSignedIn`; `start` a session; audit `auth.sign_in`; return `Success` with the session and the person's `mustChangePassword`.

### 6.8 `Csrf`

```php
final class Csrf
{
    public static function generate(Random $random): string;   // 64 lower-case hex characters
    public static function matches(string $expected, mixed $given): bool;  // is_string, 64 chars, hash_equals
}
```

The web layer decides which expected value applies (session token or double-submit cookie; part 2 section 2.5). The token is owned by core (plan section 3.1).

### 6.9 `Permission` and `Authorizer`

```php
enum Permission: string
{
    case ManagePeople = 'people.manage';
    case CreateProject = 'project.create';
    case ViewProject = 'project.view';
    case ManageProject = 'project.manage';       // rename, sharing
    case ChangeSchema = 'schema.change';
    case ViewRecords = 'records.view';
    case EditRecords = 'records.edit';
    case TrashRecords = 'records.trash';          // move to trash and restore
    case EmptyTrash = 'trash.empty';
    case ManageSnapshots = 'snapshots.manage';
    case ViewAudit = 'audit.view';
    case ImportExport = 'io.use';                 // M3
    case ManageCollections = 'collections.manage';// M4, provisional
    case EditForms = 'forms.edit';                // M5
    case ManagePlugins = 'plugins.manage';        // M3
}

final class Authorizer
{
    public function __construct(Grants $grants) {}
    public function can(Actor $actor, Permission $permission, ?ProjectRecord $project = null): bool;
    /** Throws AccessDenied; for project-scoped permissions a Member with no grant gets NotFound. */
    public function require(Actor $actor, Permission $permission, ?ProjectRecord $project = null): void;
}
```

| Permission | Admin | Member with a grant on the project | Member without |
|---|---|---|---|
| `people.manage`, `project.create`, `audit.view`, `plugins.manage` (instance-wide) | yes | no | no |
| `project.view`, `records.view`, `records.edit`, `records.trash`, `collections.manage` | yes | yes | not found |
| `project.manage`, `schema.change`, `trash.empty`, `snapshots.manage`, `io.use`, `forms.edit` | yes | no (access denied) | not found |

Project-scoped permissions require `$project`; passing null throws `\InvalidArgumentException`. Instance-wide permissions ignore it. A project whose state is not `active` is not found for everyone.

## 7. Project file and schema engine (`TiffinDB\Core\Schema`)

The file format is `docs/formats/tiffin.md`, format version 1. This section is how the core produces and changes it.

### 7.1 `Identifier`

The only producer of SQL identifiers (security-critical, orchestration guide section 2 rule 4).

```php
final class Identifier
{
    public const PURPOSES = ['trash', 'u', 'r', 'l1', 'r1'];
    public static function table(string $tableKey): string;        // '"t_<key>"'
    public static function column(string $fieldKey): string;       // '"f_<key>"'
    public static function joinTable(string $relationKey): string; // '"j_<key>"'
    public static function index(string $objectSqlName, string $purpose): string; // '"x_<name>_<purpose>"'
    public static function tableName(string $tableKey): string;    // unquoted 't_<key>', for metadata rows
    public static function columnName(string $fieldKey): string;   // unquoted 'f_<key>'
    public static function joinName(string $relationKey): string;  // unquoted 'j_<key>'
    public static function system(string $name): string;           // '"<name>"' for one of the fixed names below
}
```

- Every key argument must satisfy `Keys::isValid`; anything else throws `\InvalidArgumentException` (and is a bug).
- `index()` accepts an object SQL name matching `^(t|f|j)_[a-z2-7]{12}\z` and a purpose from `PURPOSES`.
- `system()` accepts only: `id`, `_created_at`, `_created_by`, `_updated_at`, `_updated_by`, `_trashed_at`, `_trashed_by`, `left_id`, `right_id`, `position`.
- The result is always wrapped in double quotes. Because the inputs are restricted to `[a-z0-9_]`, no escaping is needed; the function asserts the restriction before returning.

### 7.2 `ProjectFile` and `FormatMigrations`

```php
final class ProjectFile
{
    public const FORMAT = 'tiffindb-project';
    public const VERSION = 1;
    public const APPLICATION_ID = 1413891138;

    /** Creates project.tiffin at $path with every format-1 table and the meta rows. */
    public static function create(string $path, string $projectKey, string $name, string $createdByKey,
                                  string $appVersion, Clock $clock): Database;
    /** Opens, checks identity, upgrades older formats (after a snapshot). */
    public static function open(string $path, string $expectedKey, Snapshots $snapshots,
                                ?FormatMigrations $migrations = null): Database;
    public static function touch(Database $db, string $userKey, Clock $clock): void;   // meta updated_at, updated_by
    public static function setName(Database $db, string $name): void;                  // meta project_name
}
```

`create` steps: `Sqlite::create`; `PRAGMA application_id = 1413891138`; `PRAGMA user_version = 1`; in one `write()`: the DDL of `docs/formats/tiffin.md` section 4 and the nine meta rows of its section 4.1. The DDL lives as a constant list of strings in `ProjectFile`, copied verbatim from the format document (T-SCH-1 compares them, ignoring white space).

`open` steps: `Sqlite::open`; `application_id` must equal `APPLICATION_ID` and meta `format` must equal `FORMAT`, else `Unavailable('not-project')`; `user_version` greater than `VERSION` gives `Unavailable('newer')`; meta `project_key` different from `$expectedKey` gives `Unavailable('mismatch')`; `user_version` lower than `VERSION` runs `FormatMigrations::upgrade` (a `format-upgrade` snapshot first, then each step in its own transaction, setting `PRAGMA user_version` and meta `format_version` in that transaction).

`FormatMigrations` holds `STEPS: array<int, list<string>>` (empty in M1; version 2 arrives with M2). Its constructor takes optional steps as a test seam.

### 7.3 Read model

```php
final class TableDef
{
    public function __construct(
        public readonly string $key, public readonly string $name, public readonly string $itemName,
        public readonly int $position, public readonly ?string $titleFieldKey,
    ) {}
    public function sql(): string;   // Identifier::table($this->key)
}

final class FieldDef
{
    public function __construct(
        public readonly string $key, public readonly string $tableKey, public readonly string $name,
        public readonly string $type, public readonly array $options, public readonly bool $required,
        public readonly string $help, public readonly int $position, public readonly bool $hasColumn,
    ) {}
    public function sql(): string;   // Identifier::column; \LogicException when !$hasColumn
}

enum Cardinality: string { case One = 'one'; case Many = 'many'; }

final class RelationDef
{
    public function __construct(
        public readonly string $key,
        public readonly string $leftTable, public readonly string $leftField, public readonly Cardinality $leftMax,
        public readonly string $rightTable, public readonly string $rightField, public readonly Cardinality $rightMax,
    ) {}
    public function sql(): string;                      // Identifier::joinTable
    public function sideOf(string $fieldKey): string;   // 'left' | 'right'
    public function otherField(string $fieldKey): string;
    public function otherTable(string $fieldKey): string;
    public function maxFor(string $fieldKey): Cardinality;   // how many records this field's record may link to
}

final class Schema
{
    public function __construct(Database $db, FieldTypes $types) {}  // reads _tiffin_* once; immutable
    public function tables(): array;                    // list<TableDef> by position
    public function table(string $key): TableDef;       // NotFound when absent
    public function fields(string $tableKey): array;    // list<FieldDef> by position
    public function field(string $key): FieldDef;       // NotFound when absent
    public function relation(string $key): RelationDef;
    public function relationOf(FieldDef $field): ?RelationDef;
    public function relationsTouching(string $tableKey): array;
    public function projectName(): string;
    public function meta(string $key): ?string;
}
```

`Schema` validates what it reads: keys must match the pattern, `type` must be known to `FieldTypes`, options must decode to a JSON object. Writers therefore encode options as `json_encode((object) $options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`: plain `json_encode([])` gives `[]`, which a type with no options would otherwise store and `Schema` would then refuse. A file that fails is `Unavailable('not-project')`. Keys arriving from a URL are checked with `Keys::isValid` before any lookup and give `NotFound` when invalid.

### 7.4 `SchemaEditor`

All structural changes go through this class. It takes no actor and makes no permission decision (that is `Core\App`), but it takes the snapshots itself so that no caller can skip them.

```php
final class SchemaEditor
{
    public const MAX_TABLE_NAME = 60;
    public const MAX_ITEM_NAME = 40;
    public const MAX_FIELD_NAME = 60;
    public const MAX_HELP = 200;
    public const MAX_FIELDS = 500;
    public const MAX_CONVERT_RECORDS = 50000;

    /** @param callable(SnapshotInfo): void $onSnapshot */
    public function __construct(Database $db, string $projectFolder, FieldTypes $types, Keys $keys,
                                Clock $clock, Snapshots $snapshots, string $userKey, callable $onSnapshot) {}

    public function createTable(string $name, string $itemName, bool $withNameField): TableDef;
    public function updateTable(string $tableKey, string $name, string $itemName, ?string $titleFieldKey): TableDef;
    public function deleteTable(string $tableKey): int;                  // records deleted

    public function addField(string $tableKey, string $name, string $type, array $options, bool $required, string $help): FieldDef;
    public function addRelation(string $tableKey, string $name, string $targetTableKey, Cardinality $thisMax,
                                Cardinality $otherMax, string $reverseName, bool $required, string $help): FieldDef;
    public function previewUpdate(string $fieldKey, array $options): Impact;
    public function updateField(string $fieldKey, string $name, array $options, bool $required, string $help, bool $confirmLoss): FieldDef;
    public function previewTypeChange(string $fieldKey, string $newType, array $options): Impact;
    public function changeType(string $fieldKey, string $newType, array $options, bool $confirmLoss): Impact;
    public function moveField(string $fieldKey, int $direction): void;   // -1 earlier, +1 later; no-op at the ends
    public function deleteField(string $fieldKey): void;
    public function valueCount(string $fieldKey): int;                   // non-empty values, live and trashed records
    public function recordCount(string $tableKey, bool $includeTrashed = true): int;
}

final class Impact
{
    public function __construct(public readonly int $records, public readonly int $values) {}  // values cleared or rewritten
}
```

Every public method that changes something runs inside one `Database::write()` and ends with `ProjectFile::touch()` in the same transaction. Snapshots are taken before the transaction starts, and reported through `$onSnapshot`. After any change the caller must build a new `Schema`.

**Validation shared by the methods** (problems collected, then `ValidationFailed`):

| Input | Rule | Problem (field, key, params) |
|---|---|---|
| Table name | 1 to 60 characters, no control characters | `name`: `problem.name.empty`, `problem.name.too_long` (`max`), `problem.text.control` |
| Table name | Unique in the project, ignoring ASCII case | `name`: `problem.table_name.taken` |
| Item name | 1 to 40 characters | `item_name`: the same keys as the name |
| Field name | 1 to 60 characters; unique in its table | `name`: as above; `problem.field_name.taken` |
| Help | 0 to 200 characters, no control characters | `help`: `problem.name.too_long` (`max` 200), `problem.text.control` |
| Type | Known to `FieldTypes`; not `relation` in `addField` | `type`: `problem.type.unknown` |
| Options | `FieldType::normaliseOptions` | as the type reports, field `options.<name>` |
| Field count | At most 500 fields per table | null: `problem.table.too_many_fields` (`max`) |
| Title field | Null, or a field of this table whose type has `canBeTitle()` | `title_field`: `problem.title_field.invalid` |

**`createTable`**: key; `CREATE TABLE <t> (...)` with the seven columns of the format document section 5; `CREATE INDEX "x_t_<key>_trash"`; insert into `_tiffin_tables` with `position` = max + 1, `item_name`, timestamps. With `$withNameField`, add a `text` field named `Name` (default options, not required, position 1) and make it the title field. That field name is data written by core in English, like any field name, and can be renamed (section 16).

**`updateTable`**: names and title field; no SQL schema change.

**`deleteTable`**: snapshot `table-delete`. Then in one transaction: delete each relation touching the table as `deleteField` does; delete `_tiffin_attachment_links` rows of the table's fields; `DROP TABLE`; delete its field rows and table row; renumber remaining table positions 1..n. Returns the record count it had.

**`addField`** (every type except relation): normalise options; key; when the type has a column definition, `ALTER TABLE <t> ADD COLUMN <f> <definition>`; for `auto_number`, number the existing records in `id` order from `options.start`, set `options.next` to the following number, then `CREATE UNIQUE INDEX "x_f_<key>_u" ON <t> (<f>)`; insert the field row with `position` = max + 1. `required` is forced to false for `checkbox` and `auto_number`.

**`addRelation`**: the target table must exist (`target`: `problem.relation.target_missing`); the reverse name follows the field-name rules and must be unique in the target table (`reverse_name`: `problem.relation.reverse_name_taken`, param `table`); for a self-relation it must also differ from the new field's name. Keys for the relation and both fields. The new field is the left side, the reverse field the right side: `left_max` = `$thisMax`, `right_max` = `$otherMax`. SQL, exactly:

```sql
CREATE TABLE "j_<r>" (
    "left_id"  INTEGER NOT NULL REFERENCES "t_<left table>" ("id") ON DELETE CASCADE,
    "right_id" INTEGER NOT NULL REFERENCES "t_<right table>" ("id") ON DELETE CASCADE,
    "position" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("left_id", "right_id")
) WITHOUT ROWID;
CREATE INDEX "x_j_<r>_r" ON "j_<r>" ("right_id");
-- only when left_max is 'one':
CREATE UNIQUE INDEX "x_j_<r>_l1" ON "j_<r>" ("left_id");
-- only when right_max is 'one':
CREATE UNIQUE INDEX "x_j_<r>_r1" ON "j_<r>" ("right_id");
```

Both field rows have `type` `relation`, `sql_name` null, options `{"relation": "<r>", "side": "left"|"right"}`; the reverse field gets the next position in the target table, `required` false, and empty help.

**`updateField`**: name, help, required, and options. Option changes that touch stored values:

| Type | Change | Effect | Snapshot |
|---|---|---|---|
| `choice` | A label renamed (same choice `key`) | `UPDATE <t> SET <f> = ? WHERE <f> = ?` | `field-options` when any row changes |
| `choice` | A choice removed | Values equal to its label set to null; counted | `field-options` when any row changes |
| `rating` | `max` lowered | Values above the new `max` set to null; counted | `field-options` when any row changes |
| `relation` | This side from `many` to `one` | Refused when any record on this side links to more than one record (`options.links`: `problem.relation.cardinality_conflict`, param `count`); otherwise create the unique index | none |
| `relation` | This side from `one` to `many` | Drop that unique index | none |
| `currency` | `minor_digits` changed | Refused: `options.minor_digits`: `problem.options.minor_digits_fixed` | |
| `auto_number` | `start` changed | Refused: `options.start`: `problem.options.start_fixed` | |
| any | Other options (`max_length`, `decimals`, `min`, `max`, `seconds`, `code`, `images_only`) | Stored values unchanged; values outside a new limit stay until edited | none |

For relation fields, the options `updateField` accepts are `{"links": "one"|"many"}` (this side's maximum); the stored options keep `relation` and `side`. When the change clears values (`Impact.values > 0` counting only cleared values, not renamed labels) and `$confirmLoss` is false, `updateField` throws `ConfirmationRequired` before changing anything. `previewUpdate` returns the impact without changing anything.

**`changeType`**: allowed only when `FieldTypes::canConvert(from, to)` (`type`: `problem.type.not_convertible`). Steps:

1. Refuse tables with more than 50,000 records (null: `problem.table.too_large_to_convert`, `max`). Read every row's `id` and value (live and trashed). Convert each non-empty value: `to->parse(from->toText(value, fromOptions), toOptions, lenient: true)`. Count failures. For a `choice` target whose `choices` option is empty, first fill it with the distinct non-empty source texts, sorted, at most 200 (more: `options.choices`: `problem.options.choices_too_many`).
2. If failures > 0 and not `$confirmLoss`: throw `ConfirmationRequired(records with a cleared value, failures)`.
3. Snapshot `field-type-change`.
4. One transaction: drop the field's own indexes (`"x_f_<key>_u"` if present); `ALTER TABLE <t> DROP COLUMN <f>`; `ALTER TABLE <t> ADD COLUMN <f> <new definition>`; `UPDATE <t> SET <f> = ? WHERE "id" = ?` for each converted value read in step 1 that is not null (values that failed or are empty are not written, so the new column keeps its default: null, or 0 for a `checkbox` target, whose column is `NOT NULL`); update the field row's `type`, `options`, `updated_at`; when the field was the title field and the new type cannot be a title, clear `title_field`.
5. Return `Impact(records with a cleared value, values cleared)`.

**`moveField`**: swap `position` with the neighbour in the direction given; renumber to 1..n.

**`deleteField`**: snapshot `field-delete`. One transaction: for a relation field, `DROP TABLE` the join table (its indexes go with it), delete the relation row, then both field rows (in that order: `_tiffin_relations` references both fields without `ON DELETE`, so deleting a field row first fails the foreign-key check); for an attachment field, delete its `_tiffin_attachment_links` rows and its field row; for a field with a column, drop its own indexes, `ALTER TABLE <t> DROP COLUMN <f>`, and delete the field row; clear `title_field` where it named a deleted field; renumber positions in each affected table.

### 7.5 `FieldTypes`

```php
final class FieldTypes
{
    public static function standard(?Keys $keys = null): self;   // the 17 v1 types
    public function get(string $id): FieldType;                  // \InvalidArgumentException for an unknown id
    public function has(string $id): bool;
    /** @return list<string> type ids in the order of section 8.2 */
    public function ids(): array;
    public function canConvert(string $from, string $to): bool;  // section 8.4
}
```

## 8. Field types (`TiffinDB\Core\Schema\Type`)

### 8.1 Interface

```php
final class Checked
{
    public static function ok(mixed $value): self;           // canonical value, or null for empty
    public static function problem(string $key, array $params = []): self;
    public function isOk(): bool;
    public function value(): mixed;                          // \LogicException when not ok
    public function problemKey(): ?string;
    public function problemParams(): array;
}

interface FieldType
{
    public function id(): string;
    /** SQL column definition, or null for types stored outside the user table. */
    public function columnDefinition(): ?string;
    /** @return array<string, mixed> */
    public function defaultOptions(): array;
    /** Complete, normalised options; throws ValidationFailed with fields 'options.<name>'. */
    public function normaliseOptions(array $options): array;
    /** Canonical value from what SQLite returned. */
    public function fromStorage(mixed $stored, array $options): mixed;
    /** SQLite value from a canonical value. */
    public function toStorage(mixed $value, array $options): string|int|float|null;
    /** Checks a canonical PHP value given by a core caller. */
    public function check(mixed $value, array $options): Checked;
    /** Parses text from a form field (strict) or from an import or a type conversion (lenient). */
    public function parse(string $text, array $options, bool $lenient = false): Checked;
    /** Canonical text of a canonical value; parse(toText(v)) gives v back. */
    public function toText(mixed $value, array $options): string;
    public function canBeTitle(): bool;
    /** False when people never type the value (auto-number). */
    public function isEditable(): bool;
}
```

Every type is a `final` class in `TiffinDB\Core\Schema\Type` named after its id in PascalCase plus `Type` (`TextType`, `LongTextType`, ..., `AutoNumberType`). Types are stateless apart from injected `Keys`. Empty text (after trimming, where the type trims) parses to `Checked::ok(null)`. Requiredness is enforced by the record layer (M2), not by the type.

### 8.2 The 17 types

Ids in this order: `text`, `long_text`, `number`, `currency`, `date`, `time`, `date_time`, `duration`, `checkbox`, `choice`, `rating`, `url`, `email`, `phone`, `attachment`, `relation`, `auto_number`. Storage and option names as `docs/formats/tiffin.md` section 6.

| Type | Canonical PHP value | Strict parse (form input) | Lenient parse adds | Problem keys |
|---|---|---|---|---|
| `text` | string | Trim; reject control characters other than tab; reject line breaks; at most `max_length` characters (1 to 10,000, default 500) | Line breaks become single spaces; text that is too long is still refused, never cut | `problem.text.control`, `problem.text.line_break`, `problem.text.too_long` (`max`) |
| `long_text` | string, line breaks as `\n` | `\r\n` and `\r` become `\n`; trim; reject control characters other than `\n` and tab; at most `max_length` (1 to 1,000,000, default 20,000) | as strict | the same |
| `number` | int (when `decimals` is 0) or float | After trimming: an optional sign, then either plain digits or digits in comma-separated groups of three (`1,234,567`), then optionally a point and digits (pattern `NUMBER` below the table); at most `decimals` digits after the point (0 to 6, default 0); within `min` and `max` when set; magnitude at most 1e15 | More decimals rounded half away from zero | `problem.number.invalid`, `problem.number.whole`, `problem.number.decimals` (`decimals`), `problem.number.too_small` (`min`), `problem.number.too_large` (`max`), `problem.number.range` |
| `currency` | int, minor units | As number, optionally with one leading `$`, `€`, `£`, or `¥`, or the field's `code` before or after (separated by a space); at most `minor_digits` digits after the point; magnitude at most 9e15 minor units | More digits rounded half away from zero | `problem.currency.invalid`, `problem.currency.decimals` (`digits`), `problem.number.range` |
| `date` | string `YYYY-MM-DD` | Exactly that form; a real calendar date; year 0001 to 9999 | also `YYYY-MM-DDTHH:MM[:SS]` (time dropped) | `problem.date.invalid` |
| `time` | string `HH:MM:SS` | `HH:MM` or `HH:MM:SS`, 24-hour | also `H:MM` | `problem.time.invalid` |
| `date_time` | string `YYYY-MM-DDTHH:MM:SS` | A date, then `T` or one space, then `HH:MM` or `HH:MM:SS` | also a date alone (time 00:00:00). A trailing `Z` or offset is refused in both modes | `problem.date_time.invalid` |
| `duration` | int seconds, 0 to 3,153,600,000 | `H:MM` or `H:MM:SS` (minutes and seconds 00 to 59), or a whole number of minutes | as strict | `problem.duration.invalid` |
| `checkbox` | bool | `1`, `on`, `true`, `yes` give true; empty, `0`, `off`, `false`, `no` give false (ASCII case-insensitive) | as strict | `problem.checkbox.invalid` |
| `choice` | string, one of the labels | The exact label, or one equal ignoring ASCII case (returns the stored label) | as strict | `problem.choice.invalid` |
| `rating` | int 1 to `max` (3 to 10, default 5) | A whole number; `0` gives null | as strict | `problem.rating.invalid` (`max`) |
| `url` | string | Trim; at most 2048 characters; no white space or control characters; scheme `http` or `https` (any case; stored lower-case) then `://` and a host; passes `filter_var(FILTER_VALIDATE_URL)` | A value without a scheme that starts with a letter or digit and has a dot before any `/` gets `https://` in front | `problem.url.invalid` |
| `email` | string | Trim; at most 254 characters; passes `filter_var(FILTER_VALIDATE_EMAIL)` | as strict | `problem.email.invalid` |
| `phone` | string | Trim; runs of white space become one space; 3 to 40 characters; only digits, space, `+ ( ) - .`, optionally followed by ` x`, ` ext`, or ` ext.` and digits; at least 3 digits | as strict | `problem.phone.invalid` |
| `attachment` | list of attachment ids (M4) | Not parseable: `problem.value.unsupported` | the same | `problem.value.unsupported` |
| `relation` | list of record ids (M2, M4) | Not parseable: `problem.value.unsupported` | the same | `problem.value.unsupported` |
| `auto_number` | int | Not typed by people: `problem.auto_number.read_only` | A whole number 0 or more (keeps imported numbers in M3) | `problem.auto_number.read_only` |

Pattern `NUMBER` (used by `number` and, after removing a symbol or code, by `currency`): `/^[+-]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?\z/`.

`check()` applies the same rules to canonical values. `toText` gives: text unchanged; numbers without thousands separators, floats with exactly `decimals` digits; currency as units, a point, and `minor_digits` digits (`329.00`; no point when `minor_digits` is 0), no symbol; dates and times in canonical form (`date_time` with `T`); duration `H:MM:SS`; checkbox `1` or `0`; rating digits; relation and attachment ids joined by `,`.

`canBeTitle()`: true for `text`, `long_text`, `number`, `currency`, `date`, `time`, `date_time`, `choice`, `email`, `phone`, `url`, `auto_number`; false for `duration`, `checkbox`, `rating`, `attachment`, `relation`.

`isEditable()`: false for `auto_number`; true otherwise.

Options validation (`normaliseOptions`), problems with field `options.<name>`:

| Type | Option | Rule | Problem |
|---|---|---|---|
| `text`, `long_text` | `max_length` | Integer within the range above | `problem.options.range` (`min`, `max`) |
| `number` | `decimals` | 0 to 6 | `problem.options.range` |
| `number` | `min`, `max` | Null or a number; `min` < `max` when both are set | `problem.number.invalid`, `problem.options.min_max` |
| `currency` | `code` | Three ASCII letters; stored upper-case | `problem.options.currency_code` |
| `currency` | `minor_digits` | 0 to 4 | `problem.options.range` |
| `time`, `date_time`, `duration` | `seconds` | Boolean | `problem.options.invalid` |
| `choice` | `choices` | 1 to 200 entries; each label 1 to 100 characters, no control characters; labels unique ignoring ASCII case; each entry keeps a valid given `key` or gets a new one | `problem.options.choices_empty`, `problem.options.choices_too_many` (`max`), `problem.options.choice_too_long` (`max`), `problem.options.choices_duplicate` (`label`) |
| `rating` | `max` | 3 to 10 | `problem.options.range` |
| `auto_number` | `start` | 0 to 1,000,000,000 | `problem.options.range` |
| `attachment` | `images_only` | Boolean | `problem.options.invalid` |
| `relation` | `relation`, `side` | Set by the engine only; values from callers are ignored | |

Unknown option names are dropped. `ChoiceType` takes `Keys` through its constructor.

### 8.3 What each type does in M1 and later

| Behaviour (plan 3.4) | M1 | Later |
|---|---|---|
| Storage column type | Core, all 17 | |
| Validation of options and values | Core, all 17 | |
| Import coercion | Core: lenient `parse` | M3 adds format detection (date orders, decimal commas) in front of it |
| Export formatting | Core: `toText` | M3 uses it; JSON export may use canonical values |
| HTML input rendering | none | M2, web layer; relation picker and attachment upload M4 |
| Display rendering | none | M2, web layer |
| Auto-number for new records | Numbering existing records when the field is added | M2 record creation |
| Relation links and attachment files | Storage tables only | M2 core link operations; M4 interface |

Rendering stays in the web layer, not in the type classes, so the core produces no HTML (ADR 0008 decision 1).

### 8.4 Conversion matrix

`canConvert(from, to)` is true when `from !== to` and either both are in the set `text`, `long_text`, `number`, `currency`, `date`, `time`, `date_time`, `duration`, `checkbox`, `choice`, `rating`, `url`, `email`, `phone`, or `from` is `auto_number` and `to` is `number` or `text`. Nothing converts to or from `relation` or `attachment`, and nothing converts to `auto_number`.

## 9. Core operations (`TiffinDB\Core\App`)

### 9.1 Entry point

```php
final class Operations
{
    public function __construct(
        SystemDatabase $system, DataDirectory $data, Clock $clock, Random $random,
        PasswordHasher $hasher, string $appVersion,
    ) {}
    public function auth(): AuthOperations;
    public function people(): PeopleOperations;
    public function projects(): ProjectOperations;
    public function schema(): SchemaOperations;
    public function trash(): TrashOperations;
    public function snapshots(): SnapshotOperations;
    public function audit(): AuditOperations;
    public function close(): void;    // closes every project database this instance opened
}
```

`Operations` builds the shared objects once (`Users`, `Grants`, `Sessions`, `LoginThrottle`, `Authenticator`, `ProjectRegistry`, `AuditLog`, `Authorizer`, `Keys`, `Snapshots`, `FieldTypes::standard($keys)`) and passes them to the per-area classes. Project databases are opened through one internal `ProjectOpener`, which looks the key up in the registry, opens `ProjectFile::open`, and caches the `Database` by key for the life of the `Operations` instance (one request).

Every method whose first parameter is `Actor $actor` starts with `Authorizer::require`. Inputs that fail validation throw `ValidationFailed` with every problem found. Audit entries are written after the change commits.

### 9.2 `AuthOperations`

| Method | Permission | Behaviour | Audit |
|---|---|---|---|
| `signIn(string $username, string $password, string $clientAddress): SignInResult` | none | `Authenticator::signIn` | as section 6.7 |
| `resume(string $token): ?SignedIn` | none | `Authenticator::resume`; `SignedIn` (section 6.7) is defined in `Core\Auth` | |
| `signOut(string $token): void` | none | Ends the session when it exists | `auth.sign_out` when it existed |
| `changeOwnPassword(Actor $actor, string $token, string $current, string $new): IssuedSession` | signed in | `$current` must verify (`current`: `problem.password.current_wrong`; a wrong value counts as a failure on the person's throttle bucket, and a blocked bucket gives `current`: `problem.throttled`); `PasswordPolicy::problems($new, username, 'password')`; new hash; `must_change_password` = 0; end all the person's sessions; start and return a new one | `auth.password_changed` |
| `setFlash(string $token, string $key, array $params = []): void`, `takeFlash(string $token): ?array` | none | One notice carried to the next page | |
| `can(Actor $actor, Permission $permission, ?string $projectKey = null): bool` | none | `Authorizer::can` after looking the project up; false for an unknown project. Lets the web layer decide which controls to show without deciding policy itself | |
| `require(Actor $actor, Permission $permission, ?string $projectKey = null): void` | none | `Authorizer::require` after looking the project up (unknown project: `NotFound`). Used by controllers before showing a form whose submission would need the permission | |

### 9.3 `PeopleOperations`

All require `people.manage`.

| Method | Behaviour | Audit |
|---|---|---|
| `list(Actor): list<PersonSummary>` | `PersonSummary { UserRecord $person; int $projectCount }`; for an Admin the count is all active projects | |
| `get(Actor, string $personKey): UserRecord` | NotFound when absent | |
| `suggestPassword(Actor): string` | Section 6.2 | |
| `create(Actor, string $username, string $displayName, Role $role, string $temporaryPassword, list<string> $projectKeys): UserRecord` | Username rules and uniqueness (`problem.username.taken`), display name, `PasswordPolicy` with field `temporary_password`; unknown project keys ignored; `must_change_password` = 1; grants given when the role is Member | `people.created` |
| `update(Actor, string $personKey, string $username, string $displayName, Role $role): UserRecord` | The same validation. Demoting the last active Admin: `role`: `problem.people.last_admin`. A change of role or username ends that person's sessions | `people.updated` |
| `setTemporaryPassword(Actor, string $personKey, string $password): void` | Policy as create (field `temporary_password`); `must_change_password` = 1; ends their sessions (also when it is the acting Admin) | `people.password_set` |
| `deactivate(Actor, string $personKey): void` | Not oneself (null: `problem.people.self_deactivate`); not the last active Admin (null: `problem.people.last_admin`); ends their sessions; grants are kept | `people.deactivated` |
| `reactivate(Actor, string $personKey): void` | | `people.reactivated` |
| `projectAccess(Actor, string $personKey): list<ProjectAccess>` | `ProjectAccess { ProjectRecord $project; bool $granted }` for every active project | |
| `setProjectAccess(Actor, string $personKey, list<string> $projectKeys): void` | Members only (for an Admin: null: `problem.people.admin_has_all`); unknown keys ignored | `people.access_changed` |

### 9.4 `ProjectOperations`

| Method | Permission | Behaviour | Audit |
|---|---|---|---|
| `listFor(Actor): list<ProjectSummary>` | signed in | Admin: every active project; Member: granted ones. Opens each file to build the summary; a file that cannot be opened gives `available` false | |
| `create(Actor, string $name, ?string $firstTableName): ProjectRecord` | `project.create` | Section 9.4.1 | `project.created`; `schema.table_created` for a first table |
| `get(Actor, string $projectKey): ProjectRecord` | `project.view` | | |
| `rename(Actor, string $projectKey, string $name): ProjectRecord` | `project.manage` | Name rules as create; the file's meta first, then the registry | `project.renamed` |
| `sharing(Actor, string $projectKey): list<SharingRow>` | `project.manage` | `SharingRow { UserRecord $member; bool $granted }` for every Member, active or not, by display name | |
| `setSharing(Actor, string $projectKey, list<string> $memberKeys): void` | `project.manage` | Sets grants for active Members to exactly `$memberKeys`; Admin keys and unknown keys ignored; grants held by inactive Members are left as they are | `project.sharing_changed` |

```php
final class ProjectSummary
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly bool $available,
        public readonly array $tableNames,        // list<string>, by position
        public readonly int $recordCount,         // live records in all tables
        public readonly ?string $updatedAt,       // meta updated_at
        public readonly ?string $updatedByName,   // display name for meta updated_by, null when unknown
        public readonly int $memberCount,         // active Members holding a grant
    ) {}
}
```

#### 9.4.1 Creating a project

1. `project.create`. Name: 1 to 80 characters, no control characters, unique among projects ignoring ASCII case (`name`: `problem.name.empty`, `problem.name.too_long`, `problem.text.control`, `problem.project_name.taken`). A first table name that is not empty after trimming follows section 7.4 (field `first_table`). Both validated before anything is written.
2. A key unique in the registry; `ProjectRegistry::add` with state `creating`.
3. `Fs::makeDir` for `projects/` (when missing), the project folder, `attachments/`, `snapshots/`. An existing project folder is an error.
4. `ProjectFile::create` at `<folder>/project.tiffin`, with the actor's key and the application version.
5. With a first table: `SchemaEditor::createTable($firstTableName, 'item', true)`.
6. `ProjectRegistry::activate`.
7. If any of steps 3 to 6 fails: close the database, delete what was created (only that), `ProjectRegistry::remove`, rethrow.

### 9.5 `SchemaOperations`

Every method takes `Actor $actor, string $projectKey` first. Reading needs `project.view`; changing needs `schema.change`. The operation opens the project, builds a `Schema`, checks that the table (and field) exist and belong together (NotFound otherwise), and calls `SchemaEditor` with the actor's key.

| Method (after actor and project) | Permission | Returns | Audit |
|---|---|---|---|
| `tables()` | view | `list<TableSummary>`: `{ TableDef $table; int $fieldCount; int $recordCount }` (live records) | |
| `design(string $tableKey)` | view | `TableDesign { TableDef $table; list<FieldSummary> $fields; int $recordCount; int $trashedCount }`, `FieldSummary { FieldDef $field; ?string $linkedTableName; ?Cardinality $links }` | |
| `field(string $tableKey, string $fieldKey)` | change | `FieldDesign { FieldDef $field; ?RelationDef $relation; ?string $linkedTableName; list<string> $convertibleTo; int $valueCount }` | |
| `createTable(string $name, string $itemName, bool $withNameField)` | change | `TableDef` | `schema.table_created` |
| `updateTable(string $tableKey, string $name, string $itemName, ?string $titleFieldKey)` | change | `TableDef` | `schema.table_changed` |
| `tableImpact(string $tableKey)` | change | `Impact` (records; values 0) | |
| `deleteTable(string $tableKey)` | change | void | `snapshot.taken`, `schema.table_deleted` |
| `addField(string $tableKey, string $name, string $type, array $options, bool $required, string $help)` | change | `FieldDef` | `schema.field_created` |
| `addRelationField(string $tableKey, string $name, string $targetTableKey, Cardinality $thisMax, Cardinality $otherMax, string $reverseName, bool $required, string $help)` | change | `FieldDef` | `schema.field_created` (for the new field) |
| `updateField(string $tableKey, string $fieldKey, string $name, array $options, bool $required, string $help, bool $confirmLoss)` | change | `FieldDef` | `snapshot.taken` when taken, `schema.field_changed` |
| `previewFieldUpdate(string $tableKey, string $fieldKey, array $options)` | change | `Impact` | |
| `changeFieldType(string $tableKey, string $fieldKey, string $newType, array $options, bool $confirmLoss)` | change | `Impact` | `snapshot.taken`, `schema.field_changed` with `type_from` and `values_cleared` |
| `previewTypeChange(string $tableKey, string $fieldKey, string $newType, array $options)` | change | `Impact` | |
| `moveField(string $tableKey, string $fieldKey, int $direction)` | change | void | `schema.field_moved` |
| `deleteField(string $tableKey, string $fieldKey)` | change | void | `snapshot.taken`, `schema.field_deleted` |

### 9.6 `TrashOperations`, `SnapshotOperations`, `AuditOperations`

| Method | Permission | Behaviour | Audit |
|---|---|---|---|
| `TrashOperations::moveToTrash(Actor, string $projectKey, string $tableKey, list<int> $ids): int` | `records.trash` | `Trash::moveToTrash`; returns records moved | none (record-level) |
| `TrashOperations::restore(...the same...): int` | `records.trash` | `Trash::restore` | none |
| `TrashOperations::list(Actor, string $projectKey, string $tableKey, int $limit, int $offset): list<TrashedRecord>` | `records.view` | `TrashedRecord { int $id; string $trashedAt; ?string $trashedBy; ?string $title }`, newest first; `$limit` 1 to 200 | |
| `TrashOperations::count(Actor, string $projectKey, ?string $tableKey): int` | `records.view` | | |
| `TrashOperations::empty(Actor, string $projectKey, ?string $tableKey): int` | `trash.empty` | Snapshot `empty-trash` when there is anything to delete; then `Trash::empty` | `snapshot.taken`, `trash.emptied` |
| `SnapshotOperations::take(Actor, string $projectKey): SnapshotInfo` | `snapshots.manage` | Reason `manual` | `snapshot.taken` |
| `SnapshotOperations::list(Actor, string $projectKey): list<SnapshotInfo>` | `snapshots.manage` | | |
| `AuditOperations::recent(Actor, int $limit = 100, int $offset = 0): list<AuditEntry>` | `audit.view` | `$limit` 1 to 500 | |

### 9.7 `Trash` (`TiffinDB\Core\Query\Trash`)

```php
final class Trash
{
    public const MAX_IDS = 1000;
    public function __construct(Database $db, Clock $clock) {}
    public function moveToTrash(TableDef $table, array $ids, string $userKey): int;
    public function restore(TableDef $table, array $ids, string $userKey): int;
    public function count(TableDef $table): int;
    public function list(TableDef $table, ?FieldDef $titleField, FieldTypes $types, int $limit, int $offset): array;
    /** @param list<TableDef> $tables @param array<string, list<FieldDef>> $attachmentFields by table key */
    public function empty(array $tables, array $attachmentFields): int;
}
```

- `moveToTrash`: `UPDATE <t> SET "_trashed_at" = ?, "_trashed_by" = ?, "_updated_at" = ?, "_updated_by" = ? WHERE "id" IN (<placeholders>) AND "_trashed_at" IS NULL`. Ids must be positive integers (else `\InvalidArgumentException`); 1 to 1,000 per call.
- `restore`: the inverse (`"_trashed_at" = NULL, "_trashed_by" = NULL`, updating `_updated_*`) for rows that are in the trash.
- `list`: the title is the title field's value through `fromStorage` and `toText`, or null.
- `empty`: one transaction; per table, for each attachment field, `DELETE FROM "_tiffin_attachment_links" WHERE "field_key" = ? AND "record_id" IN (SELECT "id" FROM <t> WHERE "_trashed_at" IS NOT NULL)`; then `DELETE FROM <t> WHERE "_trashed_at" IS NOT NULL`. Join rows go by `ON DELETE CASCADE`. Returns rows deleted. Unreferenced attachment files are left for M4's cleanup.
- A link to a record in the trash stays in its join table (ADR 0010 decision 4); the M2 and M4 record layers hide such links.

## 10. Installer core (`TiffinDB\Core\Install`)

The screens are in `docs/design/m1/installer.md`; this is the logic behind them.

### 10.1 States

```php
enum InstallState { case NeedsDataFolder; case NeedsAdmin; case Installed; }

final class Installer
{
    public function __construct(string $appRoot, ?string $configuredDataDir, Clock $clock, Random $random,
                                PasswordHasher $hasher, string $appVersion) {}
    public function state(): InstallState;
    public function requirements(bool $secure, ?string $documentRoot): RequirementReport;
    public function prepareDataFolder(?string $documentRoot, ?ProbeTarget $target, HttpProbe $probe): DataFolderResult;
    public function setupCodeLocation(): string;        // for messages, e.g. '../tiffindb-data/setup-code.txt'
    /** Creates the first Admin and signs them in. */
    public function createFirstAdmin(string $setupCode, string $displayName, string $username,
                                     string $password, string $clientAddress): IssuedSession;
}
```

- `NeedsDataFolder`: no candidate data directory holds `system.sqlite`.
- `NeedsAdmin`: `system.sqlite` exists; `installed_at` absent.
- `Installed`: `installed_at` present. Every installer method other than `state()` then throws `\LogicException`.

### 10.2 Requirement check

`RequirementReport` holds an ordered list of `Requirement { string $id; string $status /* 'ok'|'warning'|'failed' */; string $key; array $params }` and has `canContinue(): bool` (true when nothing failed).

| Id | Check | ok | warning | failed |
|---|---|---|---|---|
| `php` | `PHP_VERSION_ID >= 80300` | `setup.check.php.ok` (`version`) | | `setup.check.php.failed` (`version`) |
| `sqlite` | `pdo_sqlite` loaded and library at least 3.35.0 | `setup.check.sqlite.ok` (`version`) | | `setup.check.sqlite.missing`, or `setup.check.sqlite.old` (`version`) |
| `zip` | `extension_loaded('zip')` | `setup.check.zip.ok` | | `setup.check.zip.failed` |
| `xmlreader` | `extension_loaded('xmlreader')` | `setup.check.xmlreader.ok` | | `setup.check.xmlreader.failed` |
| `random` | `random_bytes(16)` succeeds | `setup.check.random.ok` | | `setup.check.random.failed` |
| `passwords` | `PasswordHasher::algorithm()` | `setup.check.passwords.argon2id` | `setup.check.passwords.bcrypt` | |
| `https` | The request was secure | `setup.check.https.ok` | `setup.check.https.warning` | |
| `data` | A data folder can be used (10.3) | `setup.check.data.outside` or `setup.check.data.in-tree` (`label`) | | `setup.check.data.failed` (`label` of the first candidate) |

The web layer shows status in words, never by colour or icon alone.

Test seam: the checks read the environment through `RequirementEnvironment` (methods `phpVersionId(): int`, `phpVersion(): string`, `extensionLoaded(string): bool`, `sqliteVersion(): ?string`, `randomWorks(): bool`), with a default implementation over the real functions. `Installer::requirements()` takes an optional third argument of that type; tests pass a fake (T-INS-1).

### 10.3 Choosing and preparing the data folder

Choosing (`DataFolder::choose(string $appRoot, ?string $configured, ?string $documentRoot): ?DataCandidate`):

1. With a configured `data_dir`: that path only. Usable when it exists and is writable, or its parent exists and is writable.
2. Otherwise, for each candidate in order (`../tiffindb-data`, then `data`): usable as above. A candidate is *outside* when the document root is known and `Fs::isInside(candidate, documentRoot)` is false. Choose the first usable candidate that is outside; failing that, `<app>/data` when usable (the root `.htaccess` guard and its own deny rule protect it, and it must pass the HTTP test); failing that, none.
3. The sibling candidate inside a known document root is never chosen.

`prepareDataFolder`:

1. Choose; none gives outcome `failed` with `setup.data.no-place`. Create the directory unless it exists; an existing directory that holds anything other than a `.htaccess` written by TiffinDB, a `projects/` folder, and stale `probe-*.txt` files is refused (`failed`, `setup.data.not-empty`).
2. Write `<data>/.htaccess` with exactly these two lines and a final newline (overwriting a copy TiffinDB wrote before):

   ```
   # TiffinDB data folder. Nothing here may be served over the web.
   Require all denied
   ```

3. Outside a known document root: outcome `safe` without a probe.
4. Otherwise run the exposure probe (10.4). `exposed` or `inconclusive` stops here and leaves the folder in place (it holds nothing sensitive yet) so a later attempt reuses it.
5. `safe`: create `projects/`, `SystemDatabase::create`, write `<data>/setup-code.txt` (content: the code and a newline). Return `safe`.

```php
final class DataFolderResult
{
    public function __construct(
        public readonly string $outcome,      // 'safe' | 'exposed' | 'inconclusive' | 'failed'
        public readonly string $label,        // the chosen folder's label, or the first candidate's
        public readonly bool $inTree,
        public readonly ?string $failureKey = null,
    ) {}
}
```

### 10.4 Exposure probe

```php
final class ProbeTarget
{
    public function __construct(
        public readonly string $host,          // Host header, checked against ^[A-Za-z0-9.-]+(:\d{1,5})?$ or ^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$
        public readonly string $serverAddress, // SERVER_ADDR
        public readonly int $serverPort,       // SERVER_PORT
        public readonly bool $secure,
        public readonly ?string $documentRoot, // realpath of DOCUMENT_ROOT, or null
        public readonly string $basePath,      // the detected base path (M0 spec section 6.2)
    ) {}
}

final class ProbeResponse
{
    public function __construct(public readonly int $status, public readonly string $body) {}
}

interface HttpProbe
{
    /** One GET of $path on the server that received the current request; null when there was no usable answer. */
    public function get(ProbeTarget $target, string $path): ?ProbeResponse;
}

final class ExposureProbe
{
    public function __construct(Random $random) {}
    /** 'safe' | 'exposed' | 'inconclusive' */
    public function run(string $dataDir, string $appRoot, ProbeTarget $target, HttpProbe $http): string;
}
```

The web layer passes a null `$target` when `HTTP_HOST`, `SERVER_ADDR`, or `SERVER_PORT` is missing or invalid; when a probe is then needed, the outcome is `inconclusive` without one.

`ExposureProbe::run`:

1. Write `<data>/probe-<16 hex>.txt` (`Fs::writeNew`) containing a 32-character hexadecimal nonce. Delete it in a `finally` block.
2. Paths to try, duplicates removed, in this order: when the document root is known and the data folder is inside it, `/` plus the folder's path relative to the document root with each segment `rawurlencode`d, plus `/<probe name>`; when the data folder is `<app>/data`, `<basePath>/data/<probe name>` and, when `basePath` ends with `/public`, `<basePath without /public>/data/<probe name>`.
3. `HttpProbe::get` for each path. Classify each answer: **served** when the status is 200 to 299 and the body contains the nonce; **refused** when the status is 400 to 499, or 200 to 299 without the nonce; **unclear** otherwise (no answer, 1xx, 3xx, 5xx).
4. Verdict: `exposed` when any path was served; `safe` when there was at least one path and every path was refused; `inconclusive` otherwise.

`LoopbackHttpProbe implements HttpProbe` is the only network code in the application, and it only talks to the server that is running it:

- Connects to `SERVER_ADDR:SERVER_PORT` (an IPv6 address in brackets) with `stream_socket_client`, `tcp://`, or `tls://` when the target is secure, with `ssl` context options `verify_peer` true, `verify_peer_name` true, `allow_self_signed` false, `peer_name` the host without its port, `SNI_enabled` true. Connection and read timeouts 5 seconds each.
- Sends exactly `GET <path> HTTP/1.0\r\nHost: <host>\r\nUser-Agent: TiffinDB-setup-check\r\nConnection: close\r\n\r\n`.
- Reads at most 65,536 bytes; parses the status line `HTTP/1.x NNN`; the body is everything after the first `\r\n\r\n`. Anything unparseable, a timeout, or a TLS failure gives null.
- Never follows redirects, sends no cookies or credentials, and never uses the `Host` value to decide where to connect.
- Test seam: the constructor takes an optional `\Closure(string $uri, array $contextOptions, float $timeout): mixed` returning a stream or false. Tests return one end of `stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)` whose other end already holds a canned response, then read what the probe wrote; no network and no server are involved (T-INS-8). The default closure calls `stream_socket_client`.

Limits, stated plainly because they are why "inconclusive" blocks: the probe cannot see an exposure through another virtual host, alias, or proxy that maps to the folder by a different path, and it cannot run under the single-worker PHP built-in server (in development, use a data folder outside the document root, or `PHP_CLI_SERVER_WORKERS=2`). Apache is not available in the build environment, so the `.htaccess` behaviour is reasoned, not executed, until the maintainer's host check (M0 spec section 5).

### 10.5 Setup code and first Admin

`SetupCode::generate(Random): string`: 16 characters from `ABCDEFGHJKMNPQRSTVWXYZ23456789`, written `XXXX-XXXX-XXXX-XXXX`. `SetupCode::matches(string $fileContents, string $typed): bool`: both upper-cased with white space and dashes removed, non-empty, compared with `hash_equals`.

`createFirstAdmin`:

1. State must be `NeedsAdmin`.
2. Throttle bucket `s:setup`: when blocked, `ValidationFailed` with `setup_code`: `problem.throttled` (`seconds`).
3. Validate everything in one pass: setup code (`setup_code`: `problem.setup_code.wrong`, and `failure('s:setup', 5)` when wrong), display name, username, password policy. Any problem: `ValidationFailed`.
4. In one system-database transaction: refuse when any user exists (`\LogicException`); create the user (role Admin, `must_change_password` 0); set `installed_at` and `installed_with`.
5. Delete `setup-code.txt`; start a session; audit `setup.complete`.

### 10.6 Command-line password reset

`tools/admin/reset-password.php <username>`: CLI only (`PHP_SAPI === 'cli'`, else exit 2). Finds the data directory as the application does (reading `config/local.php` when present), reads a new password twice from standard input with echo turned off where the terminal allows (`stty -echo` through `proc_open`; otherwise it says the input will be visible), applies `PasswordPolicy`, stores it with `must_change_password` 0, ends the person's sessions, and writes the audit entry `people.password_set` with no actor. Exit 0 on success, 1 on any problem, printed in plain English without absolute paths. SPDX block and `strict_types`.

## 11. Error handling

What the web layer does with each core outcome (mechanics in part 2 section 2.4):

| Core outcome | Web response |
|---|---|
| `ValidationFailed` | The same form again, status 422, each problem at its field and listed in an error summary at the top |
| `ConfirmationRequired` | A confirmation page, status 409, stating the counts, with a destructive button that repeats the request with `confirm=1` |
| `NotFound` | 404 page |
| `AccessDenied` | 403 page |
| `Busy` | 503 page with `Retry-After: 5` |
| `Unavailable` | 503 page with reason-specific text |
| Anything else | 500 page (M0 behaviour), logged without request data |

## 12. Message keys produced by the core

Core problems carry these keys (section 3.1). Their English text lives in `strings/en/problems.php` (part 2 section 5), exactly as below. `{name}` placeholders are the problem's parameters, except `{wait}`, which the web layer builds from the `seconds` parameter (part 2 section 5.3).

| Key | Text |
|---|---|
| `problem.name.empty` | Enter a name. |
| `problem.name.too_long` | Use at most {max} characters. |
| `problem.text.control` | Remove the special characters, such as tabs or invisible characters. |
| `problem.text.line_break` | Keep this to one line. |
| `problem.text.too_long` | Use at most {max} characters. |
| `problem.table_name.taken` | Another table in this project already has this name. |
| `problem.field_name.taken` | Another field in this table already has this name. |
| `problem.project_name.taken` | Another project already has this name. |
| `problem.type.unknown` | Choose a type. |
| `problem.type.not_convertible` | A field of this type cannot be changed to that type. |
| `problem.table.too_many_fields` | A table can have at most {max} fields. |
| `problem.table.too_large_to_convert` | This table has more than {max} records, which is too many to change the type here. |
| `problem.title_field.invalid` | Choose a field that holds text, a number, or a date. |
| `problem.relation.target_missing` | Choose the table to link to. |
| `problem.relation.reverse_name_taken` | The table {table} already has a field with this name. Choose another name. |
| `problem.relation.cardinality_conflict` | {count} records already link to more than one. Remove the extra links first, or keep "several". |
| `problem.options.range` | Choose a number from {min} to {max}. |
| `problem.options.invalid` | Choose one of the options shown. |
| `problem.options.min_max` | The smallest value must be less than the largest. |
| `problem.options.currency_code` | Use a three-letter currency code, for example USD, EUR, or GBP. |
| `problem.options.choices_empty` | Add at least one choice, one per line. |
| `problem.options.choices_too_many` | Use at most {max} choices. |
| `problem.options.choice_too_long` | Each choice can have at most {max} characters. |
| `problem.options.choices_duplicate` | The choice "{label}" is listed twice. |
| `problem.options.minor_digits_fixed` | The number of digits after the point cannot be changed once the field exists. |
| `problem.options.start_fixed` | The starting number cannot be changed once the field exists. |
| `problem.number.invalid` | Enter a number, for example 12 or 3.5. |
| `problem.number.whole` | Enter a whole number, without a point. |
| `problem.number.decimals` | Use at most {decimals} digits after the point. |
| `problem.number.too_small` | Enter {min} or more. |
| `problem.number.too_large` | Enter {max} or less. |
| `problem.number.range` | That number is too large to store. |
| `problem.currency.invalid` | Enter an amount, for example 12.50. |
| `problem.currency.decimals` | Use at most {digits} digits after the point. |
| `problem.date.invalid` | Enter a date as year, month, day, for example 2026-03-14. |
| `problem.time.invalid` | Enter a time as hours and minutes, for example 14:30. |
| `problem.date_time.invalid` | Enter a date and a time, for example 2026-03-14 14:30. |
| `problem.duration.invalid` | Enter hours and minutes, for example 1:30. |
| `problem.checkbox.invalid` | Tick the box or leave it empty. |
| `problem.choice.invalid` | Pick one of the listed choices. |
| `problem.rating.invalid` | Choose a rating from 1 to {max}. |
| `problem.url.invalid` | Enter a web address that starts with https:// or http://. |
| `problem.email.invalid` | Enter an email address, for example name@example.com. |
| `problem.phone.invalid` | Enter a phone number using digits, spaces, and + ( ) - . only. |
| `problem.value.unsupported` | This field cannot be changed here. |
| `problem.auto_number.read_only` | TiffinDB sets this number. It cannot be typed in. |
| `problem.username.invalid` | Use 3 to 32 letters, numbers, dots, dashes, or underscores, with no spaces. |
| `problem.username.taken` | Someone already uses this username. |
| `problem.password.too_short` | Use at least {min} characters. |
| `problem.password.too_long` | That password is too long. Use a shorter one. |
| `problem.password.same_as_username` | Do not use the username as the password. |
| `problem.password.current_wrong` | That is not your current password. |
| `problem.people.last_admin` | There must always be at least one Admin who can sign in. Make someone else an Admin first. |
| `problem.people.self_deactivate` | You cannot stop yourself from signing in. |
| `problem.people.admin_has_all` | Admins can use every project, so there is nothing to choose. |
| `problem.setup_code.wrong` | That setup code is not right. Open the file again and copy the code exactly. |
| `problem.throttled` | Too many tries. Wait {wait}, then try again. |

## 13. Test plan

All tests are PHPUnit tests under `tests/` (ADR 0008 decision 7), run by `composer test`, offline and deterministic. Core tests use `tests/Support/TempDir` (a fresh directory under `sys_get_temp_dir()` per test, removed in `tearDown`), `FixedClock`, and `SeededRandom` (seed written in the test). Password hashing in tests uses bcrypt cost 4 through `new PasswordHasher(['bcrypt' => 4])`, except T-AUTH-2, which also checks the default algorithm. `tests/Support/CoreFixture` builds an installed instance in a temp directory: an Admin "Example Admin" (username `admin`), Members "Example Member" (`member`) and "Another Member" (`member2`), all with password `correct horse 1` (synthetic). Test records are inserted with plain SQL through `Database` by `CoreFixture::insertRecord(project, tableKey, array $values)`, since record operations arrive in M2.

### 13.1 Repository rules (`tests/repo/`)

| ID | Test | Asserts |
|---|---|---|
| T-REPO-9 | `CoreLayerRulesTest` | Section 2.1 table; no `random_bytes`, `random_int`, `mt_rand`, `rand(`, `time(`, `date(`, `microtime(` under `src/Core/` except in `SecureRandom.php` and `SystemClock.php`; negative cases built in a temp tree |
| T-REPO-10 | `HtaccessTest` (extended) | The data-folder `.htaccess` text in `Core\Install` equals the two lines of section 10.3 and contains no `Rewrite`, `Options`, `Header`, or `Allow` directive; the existing "no other `.htaccess` files" assertion stays (ledger section 7 item) |

### 13.2 Storage (`tests/core/storage/`)

| ID | Asserts |
|---|---|
| T-STO-1 | `create` on a temp file: journal mode `wal`, `foreign_keys` 1, `busy_timeout` 5000; `create` refuses an existing path; `open` of a missing path throws `Unavailable('missing')` |
| T-STO-2 | `write` commits; rolls back on an exception and rethrows; nested `write` throws `\LogicException` |
| T-STO-3 | With two connections (`busyTimeoutMs` 50), the second `write` while the first holds `BEGIN IMMEDIATE` throws `Busy` |
| T-STO-4 | `Keys`: pattern; the same seed gives the same keys; `generateUnique` retries on a taken key and fails after 10 |
| T-STO-5 | `Timestamp` round trip; rejects other forms |
| T-STO-6 | `DataDirectory::candidates` order and labels; `locate` finds the first holding `system.sqlite`; configured path overrides; `projectFolder` rejects an invalid key |
| T-STO-7 | `Fs`: modes `0750` and `0640` (skipped where the file system ignores modes, with the reason in the skip message); `writeNew` refuses an existing file; `isInside` with symbolic links and `..` |
| T-STO-8 | `Snapshots`: name pattern; the copy opens and holds data committed just before (also data still in the WAL); refused inside a transaction; 25 automatic snapshots leave 20, manual ones untouched; foreign files ignored by `list` |

### 13.3 System database and accounts (`tests/core/system/`, `tests/core/auth/`)

| ID | Asserts |
|---|---|
| T-SYS-1 | `create`: exactly the tables and indexes of section 5.1 (from `sqlite_master`), the meta keys of section 5.2, `hmac_key` 64 hex characters |
| T-SYS-2 | `open` of a missing file: `Unavailable('missing')`; `schema_version` 99: `Unavailable('newer')` |
| T-SYS-3 | An injected step 2 runs once, writes the `system-before-v2-*.sqlite` copy, sets `schema_version` 2; a failing step leaves version 1 and the tables unchanged |
| T-SYS-4 | `ProjectRegistry`: add, activate, `byKey`, `nameTaken` ignoring ASCII case, `all` and `forMember` order, `creating` projects excluded from both |
| T-AUD-1 | `AuditLog`: `recent` newest first; detail decoded; failed sign-in rows older than 90 days pruned on the next failed sign-in, other actions kept; `record` on a closed database logs and does not throw |
| T-AUTH-1 | `PasswordPolicy`: 9 characters fail, 10 pass; 72 bytes pass, 73 fail (also with multi-byte characters); equal to the username ignoring case fails; invalid UTF-8 fails |
| T-AUTH-2 | `PasswordHasher`: hash and verify; `needsRehash` after a cost change; `algorithm()` matches what PHP provides |
| T-AUTH-3 | `Usernames`: valid and invalid cases table; normalisation |
| T-AUTH-4 | `Users`: create inserts a default `user_settings` row; usernames unique ignoring case; `countActiveAdmins` |
| T-AUTH-5 | `Sessions`: token 43 characters; the database holds the hash, never the token (search every column); touch at 299 seconds does nothing, at 301 seconds moves `idle_expires_at`; idle expiry after 7 days; absolute expiry after 30 days even when used daily; an inactive user's session resolves to null and is deleted; `endAllFor`; flash is read once |
| T-AUTH-6 | `LoginThrottle`: 4 failures no wait; the 5th gives 30 s, then 60, 120, capped at 900; reset after the window; `clear`; address limit 20; table holds no raw username or address |
| T-AUTH-7 | `Authenticator`: success; wrong password, unknown user, and inactive user give identical results; unknown user still runs one hash verification (hasher spy); throttled sign-in verifies nothing; rehash on sign-in; audit rows carry the reasons and never the attempted username or password |
| T-AUTH-8 | `Csrf::matches` with wrong length, non-string, different, equal |
| T-AUTH-9 | `Authorizer`: the whole table of section 6.9 as a data provider over Admin, Member with grant, Member without grant, and a `creating` project |
| T-AUTH-10 | `initials()` cases: two words, one word, three words, extra spaces, non-ASCII first letters |

### 13.4 Schema, types, trash (`tests/core/schema/`, `tests/core/types/`, `tests/core/query/`)

| ID | Asserts |
|---|---|
| T-SCH-1 | `ProjectFile::create`: `application_id`, `user_version`, meta rows; the `sqlite_master` SQL of every `_tiffin_*` object equals the statements in the first SQL block of `docs/formats/tiffin.md` section 4, compared with white space collapsed |
| T-SCH-2 | `open` refuses a plain SQLite file (`not-project`), a newer version, a different project key, a missing file |
| T-SCH-3 | An injected format step: `format-upgrade` snapshot taken first, version and meta updated; a failing step rolls back |
| T-SCH-4 | `Identifier`: outputs for valid keys; rejects upper case, wrong length, quotes, spaces, non-ASCII, `x"; DROP TABLE t; --`; `system()` rejects other names; `index()` rejects other purposes |
| T-SCH-5 | Names from people never become identifiers: tables and fields named `"; DROP TABLE x; --`, `Name"`, `Ünïcödé`, and 60 characters of `x` are created; afterwards every name in `sqlite_master` matches `^(_tiffin_\w+|sqlite_\w+|[tfj]_[a-z2-7]{12}|x_[tfj]_[a-z2-7]{12}_(trash|u|r|l1|r1))$`, and the display names are stored unchanged |
| T-SCH-6 | `createTable` with and without the Name field; positions; title field; every validation problem of section 7.4, all reported together |
| T-SCH-7 | `addField` for each type with a column: the declared column type from `PRAGMA table_xinfo` equals section 8.2; `checkbox` is `NOT NULL DEFAULT 0`; `auto_number` numbers three existing records 1, 2, 3 and has the unique index |
| T-SCH-8 | `addRelation`: join table DDL, the indexes for each of the four cardinality pairs, both field rows, a self-relation, reverse-name conflicts; deleting a record row removes its join rows (cascade) |
| T-SCH-9 | `updateField`: a choice label rename rewrites rows without confirmation; removing a used choice needs confirmation, then clears, with a `field-options` snapshot; rating `max` lowered; relation `many` to `one` refused with the count, then accepted after removing extra links; currency `minor_digits` and auto-number `start` refused |
| T-SCH-10 | `changeType`: text to number with two unconvertible values throws `ConfirmationRequired(2, 2)` and changes nothing (file bytes and snapshots unchanged); with confirmation converts the rest, clears the two, takes one snapshot; text to choice derives sorted choices; title field cleared when the new type cannot be a title; refused pairs from section 8.4 |
| T-SCH-11 | `deleteField` for a column field, both sides of a relation, an attachment field (links removed); `deleteTable` removes relations into it from other tables; snapshots taken; positions renumbered |
| T-SCH-12 | `moveField` up, down, and at both ends |
| T-SCH-13 | `Schema` refuses a file with an unknown type or a malformed key (`Unavailable('not-project')`) |
| T-SCH-14 | Every change updates meta `updated_at` and `updated_by` |
| T-SCH-15 | The example query in `docs/formats/tiffin.md` section 7, with its keys replaced by those of a generated fixture, runs and returns the expected rows |
| T-TYP-1 | Per type, strict `parse`: at least six accepted and six refused inputs each, with the expected canonical value or problem key |
| T-TYP-2 | Lenient `parse` additions per type |
| T-TYP-3 | `parse(toText(v)) == v` for 200 seeded canonical values per type with values |
| T-TYP-4 | `toStorage`, insert into SQLite, read back, `fromStorage` gives the same canonical value |
| T-TYP-5 | `normaliseOptions`: defaults, every problem in section 8.2's options table, unknown options dropped, choice keys kept or generated |
| T-TYP-6 | `canConvert` equals the matrix of section 8.4 for all 17 × 17 pairs |
| T-TYP-7 | `url` refuses `javascript:alert(1)`, `JaVaScRiPt:x`, `data:text/html,x`, `vbscript:x`, `//example.com`, `http:/example.com`, `https://exa mple.com`, a URL with a tab or newline, and 2049 characters; accepts `HTTPS://Example.com/a?b=1#c` storing the scheme lower-case |
| T-TRASH-1 | `moveToTrash` and `restore` counts, idempotence, `_updated_*` set, invalid ids refused, more than 1,000 refused |
| T-TRASH-2 | `empty`: trashed rows gone, live rows kept, join rows of deleted records gone (cascade), attachment links of deleted records gone, other links kept |
| T-TRASH-3 | `list` newest first with titles through the title field's type |

### 13.5 Core operations (`tests/core/app/`)

| ID | Asserts |
|---|---|
| T-APP-10 | Admin creates a project: registry state `active`, folder with `project.tiffin`, `attachments/`, `snapshots/`, file opens, meta `project_name`, audit `project.created`; with a first table, the table and its Name field exist; duplicate name (other case) gives `problem.project_name.taken` and creates nothing; a failure in `ProjectFile::create` (folder pre-created with the key `SeededRandom` will produce) leaves no registry row and no new files |
| T-APP-11 | A Member cannot create a project (`AccessDenied`) |
| T-APP-12 | `listFor`: Admin sees all, Member sees granted only; summary values (tables, live record count, last change and name, member count); a missing file gives `available` false |
| T-APP-13 | `rename` updates file meta and registry; `sharing` and `setSharing` add and remove grants, ignore Admin keys, keep inactive Members' grants; an ungranted Member gets `NotFound` from `get` |
| T-APP-20 | Admin creates a table and adds one field of each of the 17 types (relation through `addRelationField`), then reads `design`; a granted Member can call `tables` and `design` |
| T-APP-21 | Every changing `SchemaOperations` method throws `AccessDenied` for a granted Member and `NotFound` for an ungranted one (data provider over all methods) |
| T-APP-22 | A field key from another table, a table key from another project, and keys that are not valid keys all give `NotFound` |
| T-APP-30 | People: create (validation all at once, uniqueness ignoring case); demoting or deactivating the last active Admin refused; deactivating oneself refused; deactivate ends sessions; `setTemporaryPassword` sets must-change and ends sessions; every method refuses a Member |
| T-APP-31 | `changeOwnPassword`: wrong current password refused and counted; after 5 wrong attempts throttled; success ends the other sessions and returns a new one; must-change flag cleared |
| T-APP-40 | Trash: a granted Member moves to trash and restores; a Member cannot empty; empty takes an `empty-trash` snapshot and writes `trash.emptied` |
| T-APP-50 | Manual snapshot is Admin-only; deleting a field writes `snapshot.taken` before `schema.field_deleted` |
| T-APP-60 | Each action in section 5.6 is written by the operation that should write it, with the target and detail stated (table-driven); `AuditOperations::recent` is Admin-only |
| T-AUD-2 | After a scenario that signs in, changes a password, and sets a temporary password, no column of any system-database table and no audit detail contains any of the passwords or session tokens used |
| T-CLI-1 | `tools/admin/reset-password.php` run as a child process with `--data-dir` pointing at a `CoreFixture` directory and passwords on standard input: success (exit 0, hash changed, sessions ended, audit row), unknown user (exit 1), weak password (exit 1), run without `cli` refused is not testable and is reviewed instead |

`--data-dir=<path>` is accepted by the script for tests and for installations whose data folder is not found automatically.

### 13.6 Installer core (`tests/core/install/`)

| ID | Asserts |
|---|---|
| T-INS-1 | `requirements()` with a fake environment: each row's status and key for passing and failing inputs; `canContinue` |
| T-INS-2 | `DataFolder::choose` matrix: document root equal to the application folder (sibling chosen, outside); document root above the application folder (sibling inside, so `data` chosen); document root unknown (`data` chosen); configured path; nothing writable (none) |
| T-INS-3 | Outside the document root: `safe` without calling the probe (spy); `system.sqlite`, `setup-code.txt` (code pattern), and `.htaccess` (exact text) exist |
| T-INS-4 | Inside, with a fake `HttpProbe`: a served nonce gives `exposed`, no `system.sqlite`, no setup code; 404 gives `safe`; 403 gives `safe`; 200 without the nonce gives `safe`; 302, 500, and null give `inconclusive`; one served among refused gives `exposed`; the probe file is deleted in every case, including when the fake throws |
| T-INS-5 | Probe paths: inside a known document root with a space in a folder name (encoded); base path `/x/public` gives both `/x/public/data/...` and `/x/data/...`; no paths gives `inconclusive` |
| T-INS-6 | An existing non-empty data folder with unknown files is refused; one holding only TiffinDB's `.htaccess` is reused |
| T-INS-7 | `createFirstAdmin`: wrong code gives the problem and, after five, `problem.throttled`; code typed in lower case with spaces is accepted; all field problems together; success sets `installed_at`, deletes the setup code, returns a session that `resume` accepts, audits `setup.complete`; state then `Installed` |
| T-INS-8 | `LoopbackHttpProbe` with a socket pair: the request bytes are exactly as section 10.4; status and body parsed; more than 65,536 bytes truncated; a non-HTTP answer gives null; a secure target gives a `tls://` URI and the `ssl` options with `peer_name` without the port; an IPv6 address is bracketed |
| T-INS-9 | After installation every installer method other than `state()` throws `\LogicException` |

## 14. Provisional decisions (for the ledger, from P16)

Each was chosen as the option cheapest to reverse. "Brief" is the context brief section 12 item it resolves.

| # | Item (brief) | Provisional resolution | Rationale |
|---|---|---|---|
| P16 | "Match the mock-ups" for screens with no mock-up (9) | Screens without a mock-up match their design under `docs/design/m1/` plus the shell measurements of the mock-ups (part 2 section 8) | Gives a checkable target without inventing mock-ups |
| P17 | Layer table gaps (10) | Namespaces `Core\Error`, `Core\System`, `Core\Install`, `Core\App` added; trash in `Core\Query`; snapshots in `Core\Storage`; audit in `Core\System` (ADR 0012) | Smallest set that keeps authorization in one place |
| P18 | Keys and generated names (14) | 12-character base32 keys; SQL names `t_`, `f_`, `j_`, `x_` plus key (ADR 0010) | Never derived from user text; short; collision-free per project |
| P19 | Storage per field type (14) | ADR 0010 decision 5: choice stores its label, currency minor units, date-time as floating local time, duration seconds | Readable file; exact money |
| P20 | Record ids (14, 23) | Integer `AUTOINCREMENT` ids, used in URLs | No extra key column; never reused |
| P21 | Format versioning (14) | M1 writes version 1; each later format change bumps the version with a migration, before and after v1 | Exercises the upgrade path early |
| P22 | Trash storage (15) | `_trashed_at`/`_trashed_by` columns; links kept while trashed; tables and fields are not trashed but snapshotted before deletion; items stay until an Admin empties the trash (P2) | No data copying; restore is one update |
| P23 | Snapshots (15) | `VACUUM INTO`; reasons in section 4.4; 20 newest automatic kept, manual kept; restore by hand until a later spec designs it | Consistent copies with WAL; bounded disk use |
| P24 | Audit log (15) | System database; event list in section 5.6; failed sign-ins kept 90 days, others indefinitely; best-effort write; no screen in M1 | Survives project deletion; no user data in project files |
| P25 | Relation cardinality and cleanup (16) | One join table per relation; `one`/`many` per side enforced by unique indexes; cascade on record deletion; links kept while a record is trashed | One storage shape; cardinality change needs no rebuild |
| P26 | Attachment hash and layout (17, part) | SHA-256; `attachments/<2 hex>/<64 hex>`; links table; limits left to M4 | Format needs it now; limits are cheap later |
| P27 | Password reset (18) | Admin sets a temporary password; forced change at next sign-in; CLI script for a lone Admin; no-shell recovery deferred to M7 | No email possible |
| P28 | First Admin (18) | One-time setup code written to the data folder; required to create the first Admin | Stops strangers claiming a fresh upload |
| P29 | Member permissions (19) | Section 6.9: Members view, edit, trash, and restore records in granted projects; collections provisionally allowed (confirm in M4); everything else Admin-only; ungranted projects are "not found" | Narrow default; widening later needs no data change |
| P30 | Sessions (22) | Server-side; 7 days idle, 30 days absolute; cookie `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, base-path scoped | Revocable; household-friendly lifetime |
| P31 | Sign-in throttling (22) | 5 per username or 20 per address in 15 minutes, then 30 s doubling to 15 min; HMAC buckets; no lockout | Slows guessing without letting anyone lock out the Admin |
| P32 | Password rules (22) | 10 characters to 72 bytes; not the username; no composition rules; no breach list | Follows common guidance within bcrypt's limit |
| P33 | CSRF (22) | Per-session token; double-submit cookie before sign-in; `Sec-Fetch-Site: cross-site` POSTs refused | No anonymous sessions in the database |
| P34 | CSP (22) | M0 policy unchanged: `form-action 'self'` covers the forms and `script-src 'self'` the one M1 script | Nothing inline is added |
| P35 | M1 URLs (23); ledger item "make `/files` project-scoped" | ADR 0012 decision 5; attachments at `/projects/{project}/files/{attachment}` | Bookmarkable shapes fixed early |
| P36 | System database upgrades (23) | `meta.schema_version`, forward-only steps, a copy before each | Same pattern as project files |
| P37 | Data directory location | ADR 0011 decision 1; optional git-ignored `config/local.php` with `data_dir`, `timezone`, `secure_cookies` | Works on shared hosts without typing paths |
| P38 | Platform minimums | SQLite 3.35 or later required; `mbstring` not required | `DROP COLUMN`; fewer host requirements |
| P39 | Removing people | Deactivate only, never delete; at least one active Admin always | Keeps attribution; avoids lock-out |
| P40 | Strings file | `strings/en.php` combines area files `strings/en/*.php` (amends ADR 0008 decision 5: still one language entry point, now in parts); duplicate keys are an error | Lets work packages own disjoint files |
| P41 | M1 top bar | No Display link (settings screen is M2) and no Plugins tab (M3); the user chip links to the password page | No links to screens that do not exist |
| P42 | Time zone for display | `timezone` in `config/local.php`, else PHP's default (UTC when unset) | Avoids a settings screen in M1 |
| P43 | Project start page | `/projects/{project}` redirects to the tables page in M1; M2 changes the target to the first table's list | One-line change later |
| P44 | Enumerated values | Validated in core; no SQL `CHECK` in the system database | Adding a value needs no table rebuild |
| P45 | New-table "Name" field | Created by core with the English name "Name" as ordinary data | Matches the mock-ups; renameable |
| P46 | "Show password" | A same-origin script, `public/assets/js/show-password.js`; the checkbox is hidden without JavaScript | Keeps the mock-up control without inline script |
| P47 | Project names | Unique ignoring ASCII case | Avoids two identical cards |

## 15. Work packages

Tiers per orchestration guide section 2. "Owned files" may be created or changed only by that package; packages that can run at the same time own disjoint files. A package may add private helpers inside its own directories and must report them. Every package runs `composer test` and `composer lint` before reporting; a test owned by a later package may not exist yet, but no existing test may be weakened.

Order (at most three at once):

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP0, WP1, WP8 | at once |
| 2 | WP2, WP3 | WP1 merged |
| 3 | WP4, WP6 | WP4 after WP3; WP6 after WP2 |
| 4 | WP5, WP7 | WP5 after WP2 and WP4; WP7 after WP2, WP6, and WP8 |
| 5 | WP9, WP10, WP11 | WP9 after WP6, WP7, WP8; WP10 and WP11 after WP5, WP7, WP8 |
| 6 | WP12 | after WP9, WP10, WP11 |

Ledger section 7 items: the discovery-tool digit run, the `@symlink` in `HostCheckTest`, and the `docs/dependencies.md` bullet are WP0; the `Fallback` HEAD body is WP7; the two template-scanner items are WP8; the nested `.htaccess` test and the deny-only data rule are WP6 (T-REPO-10); "`/files` project-scoped" is decided here (P35) with no code in M1; "static assets without CSP" stays with M7 packaging.

### M1-WP0: Carried-forward tidy-ups

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (the discovery tool is a privacy filter) |
| Owned files | `tools/discovery/discover-structure.php`, `tests/tools/DiscoverStructureTest.php`, `tests/tools/HostCheckTest.php`, `docs/dependencies.md` |
| Scope | (1) Cap the digit-run exemption in the discovery tool's identifier heuristic (near line 257): a digit run longer than 4 digits no longer exempts a name, so it takes the tool's existing default-deny path; tests for `int5550107` (now withheld or listed for review, as the tool's default-deny path does), `varchar255` and `int8` (still allowed). (2) Replace `@symlink` in `HostCheckTest` (near line 231) with a call wrapped in a local error handler, skipping with a reason where symbolic links cannot be made. (3) In `docs/dependencies.md` line 11, state the clause about the count of transitive packages from M0 spec section 11.1 |
| Acceptance | New tests pass; existing discovery and host-check tests pass unchanged; no `@` operator left in `tests/`; `docs/dependencies.md` otherwise unchanged |

### M1-WP1: Storage foundation

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | No (reviewed with WP2 because sessions and data paths build on it) |
| Owned files | `src/Core/Error/*`, `src/Core/Storage/*`, `tests/core/storage/*`, `tests/Support/TempDir.php`, `tests/Support/FixedClock.php`, `tests/Support/SeededRandom.php`, `tests/repo/CoreLayerRulesTest.php`, `phpunit.xml.dist`, `composer.json` (only `autoload-dev` entries for new test namespaces and `require` entry `ext-pdo_sqlite`) |
| Scope | Sections 2.1, 3, 4; T-REPO-9 |
| Acceptance | T-STO-1 to T-STO-8 and T-REPO-9 pass; `composer validate --strict` passes; T-REPO-2 updated only as far as the new `require` entries need (it is owned by M0-WP1's file `tests/repo/ComposerManifestTest.php`, which this package may edit for that purpose alone) |

### M1-WP2: System database, accounts, sessions

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 |
| Security-critical | Yes (passwords, sessions, throttling, CSRF tokens, roles, grants) |
| Owned files | `src/Core/System/*`, `src/Core/Auth/*`, `tests/core/system/*`, `tests/core/auth/*` |
| Scope | Sections 5 and 6 |
| Acceptance | T-SYS-1 to T-SYS-4, T-AUD-1, T-AUTH-1 to T-AUTH-10 pass; design-tier security review |

### M1-WP3: Field types

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 |
| Security-critical | Yes (URL and email validation feed later HTML output) |
| Owned files | `src/Core/Schema/Type/*`, `src/Core/Schema/FieldTypes.php`, `tests/core/types/*` |
| Scope | Section 8 (all 17 types, `Checked`, `FieldType`, `FieldTypes`) |
| Acceptance | T-TYP-1 to T-TYP-7 pass |

### M1-WP4: Project file, schema engine, trash

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP3 |
| Security-critical | Yes (SQL identifier generation) |
| Owned files | `src/Core/Schema/*` except `Type/` and `FieldTypes.php`, `src/Core/Query/Trash.php`, `tests/core/schema/*`, `tests/core/query/*` |
| Scope | Sections 7 and 9.7; must keep `docs/formats/tiffin.md` true (report any needed change; do not edit it) |
| Acceptance | T-SCH-1 to T-SCH-15, T-TRASH-1 to T-TRASH-3 pass; design-tier review of `Identifier` and every SQL string |

### M1-WP5: Core operations

| | |
|---|---|
| Tier | implementation |
| Depends on | WP2, WP4 |
| Security-critical | Yes (authorization boundary) |
| Owned files | `src/Core/App/*`, `tests/core/app/*`, `tests/Support/CoreFixture.php`, `tools/admin/reset-password.php`, `tests/tools/ResetPasswordTest.php` |
| Scope | Sections 9.1 to 9.6, 10.6, 12 (keys only; the strings file is WP8's) |
| Acceptance | T-APP-10 to T-APP-60, T-AUD-2, T-CLI-1 pass; design-tier security review |

### M1-WP6: Installer core

| | |
|---|---|
| Tier | implementation |
| Depends on | WP2 |
| Security-critical | Yes (data-directory exposure test) |
| Owned files | `src/Core/Install/*`, `tests/core/install/*`, `tests/repo/HtaccessTest.php` |
| Scope | Section 10 except 10.6; T-REPO-10 |
| Acceptance | T-INS-1 to T-INS-9 and T-REPO-10 pass; no test opens a network socket (reviewer checks that only `stream_socket_pair` is used); design-tier security review |

### M1-WP7: Web pipeline

| | |
|---|---|
| Tier | implementation |
| Depends on | WP2 (Actor, Csrf, SessionRecord, SignedIn), WP6 (InstallState, HttpProbe), WP8 (View constructor). Not WP5: the kernel never calls `Core\App`; `Services::operations()` is only declared, and `FakeServices` may throw from it |
| Security-critical | Yes (cookies, CSRF enforcement, authentication gate, headers) |
| Owned files | Listed in part 2 section 10 under WP7 |
| Scope | Part 2 sections 2 and 3.1 |
| Acceptance | Part 2 section 10, WP7 row |

### M1-WP8: View layer, shell, components, shared strings

| | |
|---|---|
| Tier | implementation |
| Depends on | none (works against the M0 kernel; its tests render pages through `View` directly) |
| Security-critical | Yes (templates, escaping, the one script) |
| Owned files | Listed in part 2 section 10 under WP8 |
| Scope | Part 2 sections 4, 5, 6 and `docs/design/m1/components.md` |
| Acceptance | Part 2 section 10, WP8 row |

### M1-WP9: Installer screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP6, WP7, WP8 |
| Security-critical | Yes (installer) |
| Owned files | Part 2 section 10, WP9 row |
| Scope | `docs/design/m1/installer.md`; part 2 section 3.2 |
| Acceptance | Part 2 section 10, WP9 row |

### M1-WP10: Sign-in, own password, people, sharing screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP5, WP7, WP8 |
| Security-critical | Yes (authentication screens) |
| Owned files | Part 2 section 10, WP10 row |
| Scope | `docs/design/m1/sign-in-and-projects.md` (sign-in, own password), `docs/design/m1/people-and-sharing.md` |
| Acceptance | Part 2 section 10, WP10 row |

### M1-WP11: Project list, new project, project settings, tables, schema editor screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP5, WP7, WP8 |
| Security-critical | No (every change goes through `Core\App`; reviewed by the implementation tier) |
| Owned files | Part 2 section 10, WP11 row |
| Scope | `docs/design/m1/sign-in-and-projects.md` (project list), `new-project.md`, `schema-editor.md` |
| Acceptance | Part 2 section 10, WP11 row |

### M1-WP12: End-to-end tests and visual check

| | |
|---|---|
| Tier | implementation |
| Depends on | WP9, WP10, WP11 |
| Security-critical | No |
| Owned files | `tests/web/EndToEndTest.php`, `tests/web/ScreenStructureTest.php` |
| Scope | Part 2 sections 8 and 9: T-E2E-1 to T-E2E-4, T-SCR-1, and the visual procedure V-1 to V-8 run once with a headless browser (screenshots stay in the scratchpad) |
| Acceptance | Tests pass; a visual-check report per screen and width, listing every deviation; deviations are fixed by the owning package before the exit audit |

### Summary

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M1-WP0 | Carried-forward tidy-ups | implementation | none | Yes |
| M1-WP1 | Storage foundation | implementation | none | No |
| M1-WP2 | System database, accounts, sessions | implementation | WP1 | Yes |
| M1-WP3 | Field types | implementation | WP1 | Yes |
| M1-WP4 | Project file, schema engine, trash | implementation | WP1, WP3 | Yes |
| M1-WP5 | Core operations | implementation | WP2, WP4 | Yes |
| M1-WP6 | Installer core | implementation | WP2 | Yes |
| M1-WP7 | Web pipeline | implementation | WP2, WP6, WP8 | Yes |
| M1-WP8 | View layer, shell, components, shared strings | implementation | none | Yes |
| M1-WP9 | Installer screens | implementation | WP6, WP7, WP8 | Yes |
| M1-WP10 | Sign-in, password, people, sharing screens | implementation | WP5, WP7, WP8 | Yes |
| M1-WP11 | Projects and schema editor screens | implementation | WP5, WP7, WP8 | No |
| M1-WP12 | End-to-end tests and visual check | implementation | WP9, WP10, WP11 | No |

No package is mechanical: every one has judgement in it. The strings files are written from exact tables, but they belong to the packages whose templates use them, so that the unused-key test passes in each.

## 16. Assumptions and open points

1. Apache 2.4 with `mod_rewrite` and `.htaccess` overrides (M0 spec section 17). The data-folder deny rule and the probe's verdicts on a real Apache server are unverified until the maintainer runs the host check.
2. The hosting PHP has `pdo_sqlite` with SQLite 3.35 or later, `zip`, and `xmlreader` (plan Q2, unverified). The installer refuses to continue otherwise.
3. Argon2id is configured with 19 MiB of memory, 2 passes, 1 thread (section 6.2), well below PHP's 64 MiB default, so that hashing fits shared-host memory limits. These are commonly recommended minimum parameters, not measured on the reference host; the host check should confirm a hash completes within the host's limits.
4. Snapshot restore, project deletion, the audit-log screen, and the trash screen are not in M1. Snapshot restore needs its own design (open connections, WAL files); recorded for M4 or M7.
5. A lone Admin without shell access cannot recover a forgotten password (P27); M7 documentation must recommend a second Admin, and M7 may add a file-based recovery.
6. The English field name "Name" written by core into new tables (P45) is data, not interface text; if the interface becomes translatable, this default would come from the strings of the language in use when the table is made.
7. Date-time values are floating local time (P19). If a household spans time zones, a later format version can add an option to store UTC.
8. The exposure probe makes one HTTP request from the server to itself during setup. It is not a call to a third party, but it is network code; the maintainer may wish to confirm it is acceptable under the "no network calls" rule. The alternative is to require the data folder outside the document root, which some shared hosts cannot offer.
9. `projects.name` uniqueness uses SQLite's `NOCASE`, which folds only ASCII letters; "Über" and "über" are distinct names.
10. Record ids in URLs are sequential integers (P20); they reveal how many records were ever created in a table. Acceptable for a household application; revisit if records are ever shared outside the installation.
