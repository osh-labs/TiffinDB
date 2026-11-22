# M4 design spec: relations, attachments, collections, trash: core and work packages

Status: design spec for milestone M4, part 1 of 2 (M4-D1), awaiting spec review (M4-R1). This part covers the core: the attachment store, attachment and link operations, the picker candidate query, the multi-rule list query and its operators, the collection store, project format version 4, the archive and JSON export changes, the trash changes, the core operations and their authorization, the message keys, the test plan, the provisional decisions, and **all M4 work packages**. Part 2, `docs/specs/M4-screens.md`, covers file responses, routes, controllers, templates, strings, the keyboard procedure, the visual check, and the web packages' file ownership. Screen designs are under `docs/design/m4/`.

Decisions this spec relies on: ADR 0008, ADR 0010, ADR 0012, ADR 0013, ADR 0014, ADR 0015, ADR 0017, and the new ADR 0018 (attachments) and ADR 0019 (saved collections, relation picker). Formats: `docs/formats/tiffin.md` (version 4: sections 4.8, 8, 11.3, 13.3) and `docs/formats/json-export.md` (section 7); where this spec and those documents differ, stop and report. Everything in the M1 to M3 specs, with their as-built notes and provisional decisions P1 to P145, still applies unless a section below changes it. New provisional decisions are P146 to P174 (section 15).

**Designed against the code as built at M3 close** (integration branch at `ab2c96e`). Signatures quoted from the tree are marked "(as built)". Session plan: `docs/build/M4-session-plan.md` ("plan N" below is a section of that document).

Audience: engineers holding this spec, `CLAUDE.md`, and the files a work package names. `CLAUDE.md` has the highest authority.

## 1. Goals, non-goals, exit criteria

### 1.1 Goals

1. Relations edited from the record form: open a linked record, unlink, and choose or add links through a picker page (ADR 0019 decisions 9 to 11); required relation fields enforced on save (P105 closed, P157).
2. Attachments: upload, remove, replace, download, images shown on the record form and on a file page, one access-checked serving route, file removal when nothing links to a file (ADR 0018).
3. Saved collections: `_tiffin_collections` (format version 4), the collection editor (name, all or any, rule rows, sort, preview), the sidebar Collections group, a collection shown through the list view (ADR 0019 decisions 1 to 7).
4. Trash screen per project: list by table, restore one, restore all in a table, empty (Admins, with a confirmation that states record and file counts); sidebar trash count becomes a link.
5. Authorization in the core for every new operation; the access-control matrix of plan 5.3 as tests.
6. Archive and JSON export carry collections; archive import accepts version 4 files.

### 1.2 Non-goals for M4

- Form editor and sanitizer (M5); static form images can be created only by M5.
- Legacy-product importer (M6); packaging and About page (M7).
- Snapshot restore, project deletion, audit-log screen: assigned to M7 (P170, plan section 3 row 6 default).
- Thumbnails, quotas, per-person collections, nested rule groups, reordering collections, bulk linking, importing attachments from CSV or XLSX.
- Showing PDF, SVG, or any non-image file inside a page.

### 1.3 Exit criteria

| Exit criterion (plan 2; plan 7) | How M4 meets it |
|---|---|
| Linked records editable from a form, by keyboard only | T-LNK-1 to T-LNK-9, T-APP-100 to T-APP-106, T-WEB-50, T-WEB-51; structure T-KBD-3 and scripted run K-3 (part 2 section 10) |
| Relation and attachment controls match the record mock-up | V-20, V-21 (part 2 section 11.2), deviations listed in part 2 section 11.3 |
| Requiredness of relation and attachment fields enforced (P105) | T-LNK-8, T-ATT-12, T-APP-104 |
| Attachment access control tested (matrix plan 5.3) | Core rows T-APP-120 to T-APP-129, request rows T-WEB-60 to T-WEB-69 (section 12.9 maps each matrix row) |
| Upload, remove, download, image display, image view | T-STO-9 to T-STO-16, T-ATT-1 to T-ATT-14, T-APP-110 to T-APP-116, T-WEB-52 to T-WEB-56 |
| File removal when nothing links to a file; failed upload leaves no file and no row | T-ATT-6 to T-ATT-10 (injected failures), T-TRASH-4 to T-TRASH-7 |
| Collections: format version 4; editor matches the mock-up; sidebar group; list view | T-FMT-6, T-FMT-7, T-RUL-1 to T-RUL-12, T-COL-1 to T-COL-10, T-APP-130 to T-APP-137, T-WEB-57, T-WEB-58, V-22, V-23 |
| Collection round-trips through archive export and import | T-ARC-10 to T-ARC-13, T-RT-7, T-JSON-3 |
| Trash: restore and empty by keyboard only; emptying removes files nothing else links to | T-TRASH-4 to T-TRASH-8, T-APP-140 to T-APP-145, T-WEB-59, K-3 steps 9 to 11 |
| Multi-rule SQL: no injection, acceptable plans | T-RUL-10 (scanner), T-RUL-11 (`EXPLAIN QUERY PLAN` on a 20,000-record fixture) |
| Hostile uploads (plan 7) | T-WEB-70 (corpus), T-STO-12 |
| Version 3 file upgrades to version 4 after a snapshot and opens with plain SQLite tools | T-FMT-7 |
| A Member is refused Admin-only operations; ungranted people get "not found" | T-APP-150 (data provider over every new method) |
| Data portability | `docs/formats/tiffin.md` version 4 matches the file (T-FMT-6); JSON export documented (T-JSON-3) |

## 2. Code layout

New PHP follows ADR 0008 decision 6. New or changed files (owners in section 14):

```
src/Core/Storage/   AttachmentStore (new), MediaType (new), StoredUpload (new), Fs (sha256, freeBytes)
src/Core/Io/        StagingArea (kind "uploads")
src/Core/Query/     Attachments, AttachmentRow, AttachmentAdded, AttachmentRemoved, Picker, CandidatePage, Candidate,
                    LinkDetail, TrashedRow, EmptiedTrash, TrashImpact (new); Records, Trash, AttachmentSummary (changed);
                    ListQuery, ListSql, Operator (changed); MatchMode, RuleSet, Collection, CollectionRule, CollectionStore,
                    CollectionRules, ResolvedRules, RuleState (new)
src/Core/Schema/    ProjectFile (version 4), FormatMigrations (step 4)
src/Core/Io/Archive UntrustedProject, ProjectRebuilder, RebuildReport (collections)
src/Core/Io/Contract ExportSource (collections()), ExportCollection, ExportRule (new)
src/Core/Io/        ProjectExportSource (collections)
src/Core/Forms/     BoundImage (new), FileItem, LinkItem, BindData, FormBinder (changed)
src/Core/App/       AttachmentOperations, CollectionOperations and their result objects (new); RecordOperations,
                    TrashOperations, SchemaOperations, ProjectOperations, Operations, Context, ListRequest,
                    RecordListing, SearchResults, RecordScreen (changed)
src/Core/System/    AuditAction (four actions)
plugins/export/json/src/   collections member
```

### 2.1 Dependency rule

Unchanged (M3 section 2.1). `Core\Storage\AttachmentStore` touches files only and uses no SQL; rows and links are `Core\Query\Attachments`. `Core\App` may use every core layer; the web layer may use only `Core\App` result objects and `Core\Error` values (T-WEB-23 unchanged). `Core\Io\Contract` gains two value classes and uses nothing else.

### 2.2 SQL rules

`tests/core/schema/SqlStringsTest.php` already covers `src/Core/Query/` and `src/Core/Io/` (P107: `Sql.php` is the only file joining variables into SQL; literals, `Identifier` calls, `Database::placeholders()`, and `Sql` fragments elsewhere). Every new file in those directories falls under it unchanged. `src/Core/Storage/AttachmentStore.php` and `MediaType.php` hold no SQL (the scanner gains `src/Core/Storage/Attachment*.php` and `MediaType.php` with a "no SQL keyword in a string literal" rule, WP1).

## 3. Attachment store (`TiffinDB\Core\Storage`, M4-WP1) (security-critical)

### 3.1 `MediaType`

```php
final class MediaType
{
    public const PNG = 'image/png';
    public const JPEG = 'image/jpeg';
    public const GIF = 'image/gif';
    public const WEBP = 'image/webp';
    public const PDF = 'application/pdf';
    public const OTHER = 'application/octet-stream';
    /** The four types ever shown in a page (ADR 0018 decision 1). */
    public const INLINE = [self::PNG, self::JPEG, self::GIF, self::WEBP];

    /** The type of a file from its first 16 bytes (fewer when the file is shorter), per ADR 0018 decision 2. */
    public static function sniff(string $head): string;
    /** The type of the file at $path: reads at most 16 bytes with fopen/fread under ErrorTrap. */
    public static function ofFile(string $path): string;
    public static function isInline(string $mediaType): bool;
}
```

`sniff` compares bytes exactly (`str_starts_with`, and for WebP bytes 0 to 3 `RIFF` and 8 to 11 `WEBP`, needing at least 12 bytes); nothing else is inspected. `isInline` is true only for the four constants, compared exactly (a stored value from another tool such as `image/svg+xml` or `IMAGE/PNG` is not inline).

### 3.2 `AttachmentStore`

One instance per project folder. Files only; every path is built from a validated SHA-256 (`^[0-9a-f]{64}\z`, else `\InvalidArgumentException`); nothing is built from user text.

```php
final class AttachmentStore
{
    public const DIR = 'attachments';
    public function __construct(string $projectFolder);   // must be an existing directory, no symbolic link

    /** Hashes and checks a staged upload: SHA-256 (streamed, Fs::sha256), size, and MediaType::ofFile. */
    public function inspect(string $stagedPath): StoredUpload;   // StoredUpload { string $path; string $sha256; int $size; string $mediaType }

    /**
     * Puts the staged file at attachments/<first 2>/<64>. Must be called inside the project's
     * Database::write() (\LogicException otherwise; the caller passes the Database to prove it).
     * - nothing at the target: makes the <2> folder (Fs::makeDir, 0750) when absent, then Fs::moveIn
     *   (a rename on the same file system; mode 0640);
     * - a regular file at the target whose size equals the upload's: the staged file is left for the
     *   caller to remove (identical content is stored once);
     * - anything else at the target (a link, a folder, a different size): \RuntimeException, nothing moved.
     * Returns true when it moved the file.
     */
    public function place(Database $inWrite, StoredUpload $upload): bool;

    /** The stored file's absolute path when it is a regular file (not a link) inside the folder; else null. */
    public function path(string $sha256): ?string;

    /** Deletes the stored file when present (Fs::remove); inside a write, like place(). */
    public function delete(Database $inWrite, string $sha256): void;

    /**
     * Every stored hash: the entries of attachments/<2 hex>/ that are regular files named with 64 lower-case
     * hex characters starting with the folder's two. Other entries are skipped and reported with error_log
     * once per sweep (never deleted: they are not TiffinDB's).
     * @return list<string>
     */
    public function storedHashes(): array;
}
```

`Fs` gains `sha256(string $path): string` (streamed with `hash_init`/`hash_update_stream`, refuses links and non-regular files) and `freeBytes(string $path): ?int` (`disk_free_space` under ErrorTrap; null when unknown) (WP1). The M3 upload paths compute free space in `Core\App\IoSupport`; WP1 adds the `Fs` helper and WP4 uses it for attachments only (M3 code unchanged).

### 3.3 Staging kind `uploads`

`StagingArea::KINDS` gains `uploads`, `MAX_AGE['uploads'] = 3600` (P149). A job folder holds `upload.bin` only. The M3 sweep (`StagingArea::sweep`) removes old ones.

### 3.4 Why the store never deletes on its own

`AttachmentStore` has no "remove if unused" logic: whether a hash is still used is a database question, answered inside a write transaction by `Core\Query\Attachments` (section 4.2). Because `place` and `delete` both require the caller's open write, SQLite's write lock serialises every placement against every deletion of the same project (ADR 0018 decision 6).

## 4. Records layer (`TiffinDB\Core\Query`, M4-WP2) (security-critical)

### 4.1 Values

```php
final class AttachmentSummary   // changed: gains id-adjacent facts the screens need
{   public function __construct(int $id, string $originalName, string $mediaType, int $size,
        string $createdAt = '', ?string $createdBy = null, string $sha256 = ''); }
final class AttachmentRow { int $id; string $sha256; int $size; string $mediaType; string $originalName; string $createdAt; ?string $createdBy; }
final class AttachmentAdded { AttachmentSummary $attachment; ?AttachmentSummary $replaced; list<string> $freedHashes; }
final class AttachmentRemoved { AttachmentSummary $attachment; list<string> $freedHashes; }
final class LinkDetail { FieldDef $field; mixed $value; }               // the secondary line of a linked-record row (P158)
final class Candidate { int $id; ?string $title; ?LinkedRecord $takenBy; }
final class CandidatePage { list<LinkedRecord> $linked; list<Candidate> $candidates; int $total; int $page; int $pages;
                            int $trashedLinks; bool $onlyOne; ?string $search; ?Problem $searchProblem; }
final class TrashedRow { int $id; string $trashedAt; ?string $trashedBy; ?string $title; }   // P74: typed Trash::list
final class EmptiedTrash { int $records; list<string> $freedHashes; }
final class TrashImpact { array $records /* table key => count */; int $total; int $files; }
```

### 4.2 `Attachments`

```php
final class Attachments
{
    public const MAX_PER_FIELD = 100;
    public function __construct(Database $db, Schema $schema, AttachmentStore $store, Clock $clock);

    public function get(int $id): ?AttachmentRow;
    /** Whether the record (live) links the attachment through the field. */
    public function isLinked(string $fieldKey, int $recordId, int $attachmentId): bool;
    /** Whether a live record links the attachment through an attachment field that exists (ADR 0018 decision 5). */
    public function isServable(int $attachmentId): bool;
    /** @return array{files: int, bytes: int} distinct stored contents (by sha256) and their total size */
    public function totals(): array;

    /** Adds a staged upload to a live record's attachment field (section 4.2.1). */
    public function add(string $fieldKey, int $recordId, StoredUpload $upload, string $originalName, string $userKey, ?int $replaceId): AttachmentAdded;
    /** Removes the record's link to the attachment (section 4.2.2). */
    public function remove(string $fieldKey, int $recordId, int $attachmentId, string $userKey): AttachmentRemoved;
    /** In its own write: deletes each stored file whose hash no row names any more; returns how many. */
    public function deleteFiles(array $hashes): int;
    /** Deletes rows nothing links to (own write), then every stored file no row names (own write); returns files deleted. */
    public function sweep(): int;
}
```

Every key argument is checked like `Records` (unknown or foreign keys are `NotFound`; a field that is not an attachment field of a table is `NotFound`).

#### 4.2.1 `add`, in one `Database::write()`

1. The record is live (`NotFound` otherwise) and the field is an attachment field of its table.
2. `$originalName` is cleaned (`Attachments::cleanName`: not valid UTF-8 gives `file`; Unicode categories Cc, Cf, Zl, Zp removed (P61, so bidirectional and zero-width characters go too); `/` and `\` become `_`; trimmed; cut to 255 characters by `Text::length`; `file` when empty).
3. Problems (all collected, then `ValidationFailed` with field = `$fieldKey`): the field's option `images_only` is true and the upload is not inline (`problem.attachment.not_image`); the record already has `MAX_PER_FIELD` files in the field and `$replaceId` is null (`problem.attachment.too_many`); `$replaceId` is not linked from this record and field (`NotFound`, not a problem).
4. `AttachmentStore::place($db, $upload)` (section 3.2). When `place` refuses because a regular file of a different size is at the target and no `_tiffin_attachments` row has that `sha256`, the file is an orphan (for example a copy cut short by a crash in `Fs::moveIn`'s copy fallback): `AttachmentStore::delete` it and `place` once more, so one interrupted copy cannot block that content for good. Any other refusal is a `\RuntimeException` that rolls back.
5. Insert the `_tiffin_attachments` row (`sha256`, `size`, `media_type` = the sniffed type, `original_name`, `created_at` now, `created_by`) and the link at position `MAX(position) + 1` for that field and record (1 when none).
6. With `$replaceId`: delete that link; when no link to the replaced row remains, delete the row and add its `sha256` to `freedHashes` (unless another row still has the same hash; `deleteFiles` checks again).
7. Update the record's `_updated_at`, `_updated_by` and `ProjectFile::touch`.

A failure at any step rolls back; a file moved by step 4 stays as an orphan (no row names it), never a row without a file. The caller removes the staged folder in every case and calls `deleteFiles($added->freedHashes)` after the write returns.

#### 4.2.2 `remove`, in one `Database::write()`

The record is live; the link (field, record, attachment) exists (`NotFound` otherwise); delete it; when no link to the row remains, delete the row and report its hash; update the record and project meta. The caller calls `deleteFiles` afterwards.

#### 4.2.3 `deleteFiles` and `sweep`

`deleteFiles(list<string> $hashes)`: in one write, for each distinct valid hash: `SELECT COUNT(*) FROM "_tiffin_attachments" WHERE "sha256" = ?`; when 0, `AttachmentStore::delete`. A failed deletion is logged (`error_log('TiffinDB: an attachment file could not be deleted.')`, no hash or name) and the next sweep retries.

`sweep()`: write 1: `DELETE FROM "_tiffin_attachments" WHERE "id" NOT IN (SELECT "attachment_id" FROM "_tiffin_attachment_links") RETURNING "sha256"`; write 2: the set difference of `AttachmentStore::storedHashes()` and `SELECT DISTINCT "sha256" FROM "_tiffin_attachments"`, each deleted. Both writes are short; `storedHashes` is listed inside write 2 so no placement can interleave.

`isServable($id)`: for each attachment field of the schema, `SELECT 1 FROM "_tiffin_attachment_links" AS "l" CROSS JOIN <table> AS "r" ON "r"."id" = "l"."record_id" WHERE "l"."field_key" = ? AND "l"."attachment_id" = ? AND "r"."_trashed_at" IS NULL LIMIT 1`, true at the first hit (the `CROSS JOIN` order as M3-WP1c: the link row by its key first).

### 4.3 `Records` changes

```php
/** Ensures the record links $targetId (section 4.3.1). Joined mode supported (within()). */
public function addLink(string $fieldKey, int $id, int $targetId, string $userKey): void;
/** Ensures the record does not link $targetId; returns whether a link was removed. */
public function removeLink(string $fieldKey, int $id, int $targetId, string $userKey): bool;
/**
 * The required relation and attachment fields among $fieldKeys that the record has no live link or no file in:
 * one problem.link.required or problem.attachment.required per field (P157).
 * @param list<string> $fieldKeys
 * @return list<Problem>
 */
public function requiredLinkProblems(string $tableKey, int $id, array $fieldKeys): array;
/** @param list<int> $ids @return array<int, LinkDetail|null> by id: the first list-column field of the table, other than its title, holding a value (P158) */
public function linkDetails(string $tableKey, array $ids): array;
/** Trimmed search text, null for none, or the problem that refuses it (was private normaliseSearch). */
public static function normaliseSearch(string $search): string|Problem|null;
```

#### 4.3.1 `addLink` and `removeLink`

Both run in `transaction()` (own write, or the caller's in joined mode). `setLinks`' body becomes a private `applyLinks(Database $db, FieldDef $field, int $id, list<int> $targets, string $userKey)` that all three call, so the checks (`target_missing`, `one_only`, `target_taken`) and the writes stay in one place.

- `addLink`: reads the record's live links inside the write. When the field allows many and the target is already linked: no change. When many: the new list is the current live links plus the target at the end. When the field allows one: the new list is `[target]` (replaces the live link); when the record also keeps a link to a record in the trash (which `applyLinks` counts), the result is `ValidationFailed(problem.link.trashed_holds)` with the field key, instead of the M2 `one_only` text, so the picker can say why.
- `removeLink`: when the target is among the live links, the new list is the live links without it; otherwise no change and `false`.
- Neither takes a version token: each is idempotent ("make linked", "make unlinked"; ADR 0019 decision 9).

`setLinks` keeps its M2 signature and behaviour.

#### 4.3.2 `requiredLinkProblems`

For each key that is a field of the table, required, and of type `relation` (no live linked record: the `linksOf` query restricted to one id) or `attachment` (no row in `_tiffin_attachment_links` for field and record): `new Problem($key, 'problem.link.required')` or `new Problem($key, 'problem.attachment.required')`. Keys of other types or tables are ignored.

#### 4.3.3 `linkDetails`

The detail field is chosen once per table: the first field in field order that the M2 list-column rule would show (`RecordOperations::columns`, as built: skipping the title field and long text; ADR 0013 decision 9.1), also skipping relation and attachment fields (they have no single column value); per id, the field and its value, or null when the record has no value there. One `SELECT "id", <column> FROM <table> WHERE "id" IN (...)` per chunk of 500 ids.

### 4.4 `Picker`

```php
final class Picker
{
    public function __construct(Database $db, Schema $schema, FieldTypes $types);
    /** The candidates for a relation field of a live record, ordered as the other table's default list, 50 per page. */
    public function page(string $fieldKey, int $recordId, ?string $search, int $page): CandidatePage;
}
```

- `linked`: the record's live links in link order (as `Records::linksOf`); `trashedLinks`: how many of its links point to records in the trash; `onlyOne`: whether this side allows one link.
- `candidates`: live records of the other table that the record does not link (live or trashed), matching the search when given (`ListSql::search` on the other table, P87), ordered by `ListSql::order` with the other table's default sort, `LIMIT 50 OFFSET (page - 1) * 50`; pages clamp to the last page (P84). The exclusion is `NOT EXISTS (SELECT 1 FROM <join> AS "jj" WHERE "jj".<this side> = ? AND "jj".<other side> = r."id")` with the record id bound.
- A self-relation lists the record itself as a candidate (M2 allows such a link).
- `takenBy`: when the other side allows one link, one query `SELECT "jj".<other side>, "jj".<this side> FROM <join> AS "jj" WHERE "jj".<other side> IN (<page ids>) AND "jj".<this side> <> ?`, then the holders' titles from this table.
- `search`: `Records::normaliseSearch`; a refused search gives `searchProblem` and an unfiltered list (as the M2 list).

### 4.5 `Trash` changes

- `list()` returns `list<TrashedRow>` (P74); `TrashOperations::list` adapts (WP2 owns that call site).
- `restoreAll(TableDef $table, string $userKey): int`: one `UPDATE ... WHERE "_trashed_at" IS NOT NULL` (no id limit), touching meta when any changed.
- `impact(array $tables, array $attachmentFields): TrashImpact`: per table the trashed count; `files`: the number of distinct `sha256` of rows that are linked only from trashed records of those tables and fields (the rows `empty` will free).
- `empty()` returns `EmptiedTrash`: before deleting the links of trashed records it reads the attachment ids they hold; after deleting rows, `DELETE FROM "_tiffin_attachments" WHERE "id" IN (...) AND "id" NOT IN (SELECT "attachment_id" FROM "_tiffin_attachment_links") RETURNING "sha256"` (ids bound in chunks of 500), in the same transaction; the hashes are returned. `TrashOperations::empty` adapts (WP2) and WP4 adds the file deletion and sweep.

## 5. Multi-rule list query (`TiffinDB\Core\Query`, M4-WP3) (security-critical: SQL composition)

### 5.1 Values

```php
enum MatchMode: string { case All = 'all'; case Any = 'any'; }

enum Operator: string            // M2 cases kept
{   case Is = 'is'; case Empty = 'empty'; case Between = 'between'; case LinksTo = 'links-to';
    case Contains = 'contains'; case NotEmpty = 'not-empty'; case Less = 'less'; case More = 'more';
    case On = 'on'; case Before = 'before'; case After = 'after'; }

/** Resolved, query-ready conditions joined by one match mode: 1 to 10 conditions (\InvalidArgumentException otherwise). */
final class RuleSet { public function __construct(MatchMode $match, array $conditions); }

final class ListQuery            // gains one optional argument; M2 callers unchanged
{   public function __construct(Sort $sort, ?Condition $filter, ?string $search, int $page, ?RuleSet $rules = null); }
```

A `Condition` in a `RuleSet` is in **query form**: field key of the table, an operator the table of section 5.2 allows for the field's type, and operands that `ListSql` can bind after its own checks (text for contains and text `is`; the field type's canonical text for numbers, currency, rating, duration; a `YYYY-MM-DD` date; a choice **label**; `1` or `0`; a record id). Conversion from stored rules to query form is `CollectionRules` (section 6.3).

### 5.2 Operators by type

`ListSql::FILTERS` (the M2 list filter) is unchanged. `ListSql::RULES` (collections; P160):

| Types | Operators |
|---|---|
| `text`, `long_text`, `url`, `email`, `phone` | `contains`, `is`, `empty`, `not-empty` |
| `number`, `currency`, `rating`, `duration` | `is`, `less`, `more`, `empty` |
| `date`, `date_time` | `on`, `before`, `after`, `empty` |
| `choice` | `is`, `empty` |
| `checkbox` | `is` |
| `relation` | `links-to`, `empty` |

`ListSql::condition(TableDef, Condition)` accepts the union of `FILTERS` and `RULES` for the field's type (anything else is `\InvalidArgumentException`); the M2 `filter()` becomes a call to it, so the M2 filter is the one-rule case of one method (P165).

### 5.3 SQL per operator (on the record row `r`)

| Types, operator | Fragment (every value bound) |
|---|---|
| text-like `contains` | `r.<col> LIKE ? ESCAPE '\'` with `'%' . escapeLike(text) . '%'`; text 1 to 200 characters (`\InvalidArgumentException` otherwise) |
| text-like `is` | `r.<col> = ? COLLATE NOCASE` |
| any column type `empty` | `r.<col> IS NULL` (checkbox has no `empty`) |
| text-like `not-empty` | `r.<col> IS NOT NULL` |
| number, currency, rating, duration `is` / `less` / `more` | `r.<col> = ?` / `< ?` / `> ?`, the operand parsed with the field type (`parse($text, $options, false)`, must be ok and not null) and bound as `toStorage` (currency in minor units, duration in seconds) |
| date `on` / `before` / `after` | `r.<col> = ?` / `< ?` / `> ?` with the date |
| date-time `on` | `r.<col> >= ? AND r.<col> <= ?` with `<d>T00:00:00`, `<d>T23:59:59` |
| date-time `before` / `after` | `r.<col> < ?` with `<d>T00:00:00` / `r.<col> > ?` with `<d>T23:59:59` |
| date, date-time `between` (M2) | unchanged |
| choice `is`, checkbox `is`, relation `links-to`, relation `empty` (M2) | unchanged |

Dates are checked with `^\d{4}-\d{2}-\d{2}\z` and the date type's parse. Empty values never match the comparison operators because `NULL` comparisons are not true.

### 5.4 Composition

`ListSql::where(TableDef, ListQuery)`: `r."_trashed_at" IS NULL`, then when `rules` is set the conditions, **each wrapped in parentheses** (`Sql::wrap`, because some fragments such as date-time `on` already hold `AND`), joined by `AND` (all) or `OR` (any), and the whole group wrapped in parentheses, then the filter, then the search, all joined by `AND`. Every part is an `Sql` fragment; `Sql::join` is the only joining. `Records::page`, `position`, and the picker reuse `where` and need no change (`Records.php` is not touched by WP3).

### 5.5 Query plans

Relation conditions keep the M3 lesson: the join row is found by its key from `r."id"` (`"jj".<this side> = r."id"`, the join table's primary key or `x_j_<key>_r` index), and the other table by its primary key through `CROSS JOIN` where a join to the other table is needed (`empty`). T-RUL-11 checks the plans on a 20,000-record fixture with a 10-rule `any` collection.

## 6. Collections (`TiffinDB\Core\Query`, M4-WP3)

### 6.1 Values

```php
final class CollectionRule { string $field; string $op; list<string> $values; }   // exactly as stored (format 4.8.1)
final class Collection
{   string $key; string $tableKey; string $name; MatchMode $match; list<CollectionRule> $rules; ?string $sort;
    int $position; string $createdAt; ?string $createdBy; string $updatedAt; ?string $updatedBy; }
final class RuleState { CollectionRule $rule; ?string $brokenReason; }   // null when the rule works; else a message key
final class ResolvedRules { ?RuleSet $set; list<RuleState> $states; int $broken; ?Sort $sort; }
```

`CollectionRule` validates shape only: `field` a valid key, `op` an `Operator` value, `values` a list of at most 2 strings of at most 200 characters each (`\InvalidArgumentException`); the archive rebuild uses the same check (section 8.2).

### 6.2 `CollectionStore`

```php
final class CollectionStore
{
    public const MAX_COLLECTIONS = 50;
    public const MAX_RULES = 10;
    public const MAX_NAME = 60;
    public function __construct(Database $db, Schema $schema, Keys $keys, Clock $clock);
    /** @return list<Collection> every collection of the project, by position */
    public function all(): array;
    /** @return list<Collection> what all() returns, read from $db without a store (reading rules below; used by ProjectExportSource, WP10) */
    public static function read(Database $db): array;
    public function get(string $key): ?Collection;
    /** Creates; returns the new key. Runs its own write. */
    public function create(string $tableKey, string $name, MatchMode $match, array $rules, ?string $sort, string $userKey): string;
    public function update(string $key, string $name, MatchMode $match, array $rules, ?string $sort, string $userKey): void;
    public function delete(string $key): void;
}
```

- Reading: explicit column lists; a row whose `rules` JSON does not decode to a list of rule objects of the 6.1 shape, or whose `match_mode` is not `all` or `any`, is read with the rules it can read (invalid entries dropped; `match_mode` `all`) and logged (`error_log`, key only), never an error page. A `sort` that is not a token `Sort` accepts reads as null.
- Writing: in one `Database::write()` with `ProjectFile::touch`; `name` checked by `Text::nameProblems($name, 'name', 60)` and unique in the project ignoring ASCII case (checked inside the write before the insert: `problem.collection.name_taken`; the unique index is never the first check); at most `MAX_RULES` rules; table key must exist (`NotFound`); at most `MAX_COLLECTIONS` per project (`problem.collection.too_many`); `position` = `MAX(position) + 1`. `rules` encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` as `[{"field":...,"op":...,"values":[...]}]`.
- `CollectionStore` never interprets rules; it stores what `CollectionRules` validated (section 6.3).

### 6.3 `CollectionRules`

```php
final class CollectionRules
{
    public function __construct(Database $db, Schema $schema, FieldTypes $types);
    /** Rules as stored, checked against the table as it is now (ADR 0019 decision 4). */
    public function resolve(string $tableKey, MatchMode $match, array $rules, ?string $sort): ResolvedRules;
    /** A posted draft rule to its stored form, or the problems that refuse it (editor; section 10.4). */
    public function fromInput(string $tableKey, array $row): CollectionRule|array;
    /** @return list<array{op: string, label: string}> the tests the editor offers for a field (by type, section 5.2) */
    public function testsFor(FieldDef $field): array;
}
```

`resolve`, per rule (the first matching reason is kept as `brokenReason`):

| Check | Reason key |
|---|---|
| The field is a field of the table | `collection.broken.field` |
| `op` is in `ListSql::RULES` for the field's type | `collection.broken.type` |
| Operand count: 0 for `empty`, `not-empty`; 1 otherwise | `collection.broken.value` |
| Text operand 1 to 200 characters after `Text::trim`, no control characters | `collection.broken.value` |
| Number-like operand passes the type's `parse` with the current options | `collection.broken.value` |
| Date operand passes the date type's `parse` | `collection.broken.value` |
| Choice operand is the key of a current choice (converted to its label for the query) | `collection.broken.choice` |
| Checkbox operand is `1` or `0` | `collection.broken.value` |
| Relation operand is a canonical id of a live record of the linked table | `collection.broken.record` |

Working rules become query-form `Condition`s in a `RuleSet` (null when none works); `sort` resolves to the matching `Sort` from `Records::sorts`-equivalent logic (`ListSql::isSortable`; tokens `added.asc`, `added.desc`, `changed.desc`; otherwise null, meaning the default order).

`fromInput(row)`: `row` holds `field` (a key), `test` (an editor test token), `value` (text), and for a relation `record` (an id) — see section 10.4. The editor's checkbox tokens `ticked` and `not-ticked` map to `is` with `1` and `0`; a choice value is posted as the choice key. Problems use the keys of section 11 with field = `rule-<n>` (the row's index from 1).

## 7. Project format version 4 (M4-WP10)

- `ProjectFile::VERSION` = 4; `create` runs the four SQL blocks of `docs/formats/tiffin.md` section 4 and writes `format_version` 4 and `PRAGMA user_version = 4`.
- `FormatMigrations::STEPS[4]` holds the three statements of format section 11.3 before its `PRAGMA` line; `VERSION_PRAGMAS[4]` = `'PRAGMA user_version = 4'`. A version 1, 2, or 3 file upgrades step by step after one `format-upgrade` snapshot (M1 mechanism).
- No new snapshot reason.

## 8. Archive and JSON export (M4-WP10) (security-critical: untrusted files)

### 8.1 `UntrustedProject`

- `user_version` 1 to 4 (the check is against `ProjectFile::VERSION`); `_tiffin_collections` and its two indexes are allowed from version 4 (the `$since` mapping gains `_tiffin_collections` => 4); the table is `WITHOUT ROWID`, so SQLite makes no `sqlite_autoindex__tiffin_collections_1` (checked with SQLite 3.45 on a version 3 file made by the current `ProjectFile::create` and upgraded with the statements of format section 11.3); one present is refused like any undocumented object.
- Metadata read with an explicit column list (`key`, `table_key`, `name`, `match_mode`, `rules`, `sort`, `position`, `created_at`, `created_by`, `updated_at`, `updated_by`), at most 1,000 rows and 1 MiB of `rules` text in total (else `bad-metadata`); keys valid and unique across tables, fields, relations, forms, and collections (`bad-metadata`). Everything else about a collection row is checked by the rebuild, not refused.

### 8.2 `ProjectRebuilder`

After forms (M3 section 11.5 step 6), new step 6b, inside the same write: for each source collection row in `position`, then key, order: dropped (counted `collections_dropped`) unless the key is valid, the table key is a rebuilt table, `Text::nameProblems(name, 'name', 60)` is empty, the name is not taken by an earlier kept collection ignoring ASCII case, and fewer than 50 are kept; `match_mode` other than `all`/`any` becomes `all`; `sort` that is not a sort token shape (`^([a-z2-7]{12}|added)\.(asc|desc)\z` or `changed.desc`) becomes null; `rules` JSON that does not decode to a list becomes `[]` (counted as one dropped rule); each rule kept when it passes the `CollectionRule` shape check and fewer than 10 are kept, else dropped (counted `collection_rules_dropped`); times kept when they match the timestamp pattern, else now; person keys kept when valid, else null; `position` renumbered 1, 2, 3, ... Inserted with literal SQL and bound values. Rules that do not fit the rebuilt schema are kept (broken, as in a live project). `RebuildReport` gains `collectionsDropped`, `collectionRulesDropped`; the import log `report.archive` gains `collections_dropped` and `collection_rules_dropped` (format section 4.7.1).

`ArchiveWriter` needs no change (it copies the whole database with `VACUUM INTO`).

### 8.3 JSON export

- `Core\Io\Contract\ExportSource` gains `/** @return list<ExportCollection> */ public function collections(): array;` (API stays 1: plugins consume the source and never implement it; the test double `tests/plugins/json/FakeExportSource.php` is updated).
- `ExportCollection { string $key; string $tableKey; string $name; string $match; list<ExportRule> $rules; ?string $sort; string $createdAt; string $updatedAt; }`, `ExportRule { string $field; string $op; list<string> $values; }`, both `final` with `readonly` properties and constructor shape checks like the other contract values.
- `ProjectExportSource::collections()` reads `CollectionStore::read($db)` (section 6.2; rules as stored, broken ones included) and keeps the collections whose table is among the exported tables (as `relations()` keeps only relations between exported tables).
- `ExportSource` has three implementers that WP10 updates: `ProjectExportSource`, `tests/plugins/json/FakeExportSource.php`, and `FakeSource` in `tests/plugins/csv/CsvExporterTest.php`.
- `plugins/export/json`: after `tables`, a final member `"collections": [...]` per `docs/formats/json-export.md` section 7, one collection per line; `match` from `match_mode`. WP10 also adds a `collections` member to the example of that document's section 1 (one line, after `tables`), in the same commit, because `JsonExportFormatTest` compares the example's member order with the output.
- CSV and XLSX exporters ignore collections.

## 9. Forms (`TiffinDB\Core\Forms`, M4-WP4)

- `FileItem` gains `int $id` (first argument); `LinkItem` gains `?FieldDef $detailField = null` and `mixed $detailValue = null` (the layer rules let `Core\Forms` use `Core\Schema` but not `Core\Query`, so `Core\App` copies `LinkDetail` into these two arguments).
- New `BoundImage implements BoundNode { int $attachmentId; string $alt; bool $servable; ?Placement $placement }`, `kind()` `image`. `FormBinder` turns each `Image` block into a `BoundImage`; `BindData` gains `list<int> $servableImages` (ids `Core\App` checked with `Attachments::isServable` and an inline media type). M2's "Image: nothing" becomes: a servable image is shown (part 2), any other prints nothing (P173).
- Relation and attachment fields stay not editable through form values (`NOT_EDITABLE` unchanged): their controls are separate actions.

## 10. Core operations (`TiffinDB\Core\App`, M4-WP4) (security-critical: authorization boundary)

`Operations` gains `attachments(): AttachmentOperations` and `collections(): CollectionOperations`; the constructor keeps six required arguments (P78). `Context` gains `attachments(ProjectRecord, Database, Schema): Attachments`, `store(ProjectRecord): AttachmentStore`, and `collectionStore(Database, Schema): CollectionStore`. Every method takes `Actor $actor` first and begins with `Context::project($actor, <permission>, $projectKey)`: an ungranted Member, an unknown project, and an inactive project are `NotFound`; a granted Member lacking an Admin-only permission is `AccessDenied`. Record ids, attachment ids, and target ids arrive as strings and must match `^[1-9][0-9]{0,18}\z` (P104), else `NotFound`. Keys that are not keys of the project's tables, fields, or collections are `NotFound`.

### 10.1 Record and link operations (`RecordOperations`, changed)

| Method (after `Actor, string $projectKey`) | Permission | Returns or does |
|---|---|---|
| `screen`, `blank`, `redisplay` (M2, changed) | as M2 | `RecordScreen` whose bound form now carries: per relation slot the live links with `LinkDetail` (many-link fields only) and the picker path facts (`canEdit`); per attachment slot the files with ids and types; servable form images; `RecordScreen` gains `bool $canEdit` (`records.edit`) and `?LinkOrigin $origin` (on `blank` with a `from`) |
| `update(..., $input, $version)` (M2, changed) | `records.edit` | Before `Records::update`: `Records::requiredLinkProblems($table, $id, <relation and attachment keys the effective form shows>)`; any problem joins the value problems in one `ValidationFailed` (P157). The M2 order of checks is otherwise kept |
| `create(..., $input, ?string $from = null)` (M2, changed) | `records.edit` | Without `from`: as M2. With `from`: `linkOrigin` first, then one `Database::write()` with `Records::within()`: `create`, then `addLink($origin->fieldKey, $origin->recordId, $newId)`; returns the new id; a link problem rolls back the create and is reported at the field `link` (`ValidationFailed`) |
| `linkOrigin(string $tableKey, string $from)` | `records.edit` | `LinkOrigin { string $tableKey; string $recordId; string $fieldKey; ?string $recordTitle; string $itemName; bool $onlyOne }`; `from` must match `^([a-z2-7]{12})-([1-9][0-9]{0,18})\z`; the field must be a relation field whose other table is `$tableKey`, of a live record; anything else `NotFound` |
| `picker(string $tableKey, string $recordId, string $fieldKey, ?string $q, int $page)` | `records.edit` | `PickerScreen { ProjectRecord $project; TableDef $table; RecordRef $record; FieldDef $field; TableDef $other; CandidatePage $page; bool $canAdd }`; the record must be live, the field a relation field of the table |
| `link(string $tableKey, string $recordId, string $fieldKey, string $targetId)` | `records.edit` | `RecordRef` of the target; `Records::addLink`; `ValidationFailed` from it passes through |
| `unlink(string $tableKey, string $recordId, string $fieldKey, string $targetId)` | `records.edit` | `RecordRef` of the target (its title, or null); `Records::removeLink`; not linked is no error |
| `setLinks` (M2) | `records.edit` | unchanged |

### 10.2 `AttachmentOperations` (new)

| Method (after `Actor, string $projectKey`) | Permission | Returns or does |
|---|---|---|
| `uploadScreen(string $tableKey, string $recordId, string $fieldKey, ?string $replaceId)` | `records.edit` | `UploadScreen { ProjectRecord $project; TableDef $table; RecordRef $record; FieldDef $field; ?AttachmentSummary $replacing; int $maxBytes; bool $imagesOnly; int $count }`; `maxBytes` = `AttachmentOperations::MAX_BYTES` (64 MiB; the web layer takes the smaller PHP limit) |
| `add(string $tableKey, string $recordId, string $fieldKey, string $uploadPath, string $clientName, ?string $replaceId)` | `records.edit` | `AttachmentSummary` of the new file. Order: permission; table, field, live record; `replaceId` canonical (else `NotFound`); `Fs::freeBytes` of the project folder minus 100 MiB at least the upload's size (else `problem.upload.no_space`); staging job `uploads`, `Fs::moveIn($uploadPath, <job>/upload.bin)`; size by `Fs::size` before any hashing: 0 (`problem.upload.empty`) or over `MAX_BYTES` (`problem.upload.too_large` with `{max}`); `AttachmentStore::inspect`; `Attachments::add` (a `\RuntimeException` from the store, section 4.2.1 step 4, becomes `problem.attachment.store_failed` and one `error_log` line without the name); in a `finally`, the staging job removed; after success `Attachments::deleteFiles($freed)`; audit `attachment.added` (and `attachment.removed` for a replaced file). Problems are `ValidationFailed` with field `file` |
| `file(string $tableKey, string $recordId, string $fieldKey, string $attachmentId)` | `records.view` | `FileScreen { ProjectRecord $project; TableDef $table; RecordRef $record; FieldDef $field; AttachmentSummary $file; ?string $addedBy (display name); bool $inline; bool $canEdit }`; the record must be live and link the attachment through the field |
| `remove(string $tableKey, string $recordId, string $fieldKey, string $attachmentId)` | `records.edit` | `AttachmentSummary` removed; `Attachments::remove`, then `deleteFiles`; audit `attachment.removed` |
| `serve(string $attachmentId, bool $download)` | `records.view` | `ServedFile { string $path; string $mediaType; string $fileName; int $size; bool $inline }`. `NotFound` unless the row exists, `isServable`, and `AttachmentStore::path($sha256)` is a regular file (a missing file is also logged: `error_log('TiffinDB: an attachment file is missing; attachment=' . $id)`, no name). `mediaType` is the stored type when `MediaType::isInline`, else `application/octet-stream`; `inline` is true only for inline types without `$download`. The size is the file's own (`Fs::size`), not the row's |

Each `AttachmentOperations` method opens the project with `ProjectOpener` exactly as `RecordOperations` does; `serve` never writes.

### 10.3 `ProjectOperations` (changed)

`ProjectOperations` has no settings result as built (the settings page uses `get` and the project name). New: `attachmentTotals(Actor $actor, string $projectKey): AttachmentTotals { int $files; int $bytes }` from `Attachments::totals()`, permission `project.manage` (as the settings page); `ProjectController` calls it for the settings page (200 and 422).

### 10.4 `CollectionOperations` (new)

| Method (after `Actor, string $projectKey`) | Permission | Returns or does |
|---|---|---|
| `summaries()` | `records.view` | `list<CollectionSummary { string $key; string $tableKey; string $name; int $count; int $broken }>` by position; `count` = live records matching (resolved rules), for the sidebar |
| `listing(string $collectionKey, ListRequest $request)` | `records.view` | `RecordListing` of the collection's table with the resolved rules as `ListQuery::$rules`, the request's search, sort (default: the collection's resolved sort, else the table's), and page; the request's filter is ignored; `RecordListing` gains `?CollectionFacts $collection` (`key`, `name`, `broken`, `canManage`) |
| `editor(?string $tableKey, ?string $collectionKey, ?array $input, string $action)` | `collections.manage` | `CollectionEditor` (below). New (`tableKey`) or existing (`collectionKey`); `$input` null shows the stored collection (or an empty draft with one empty rule for a new one); `$action` one of `show`, `preview`, `add`, `remove-<n>` (n 1 to 10): the draft is rebuilt from `$input` (unknown fields dropped, tests reset to the field's first test when they do not fit, `add` appends an empty row while fewer than 10, `remove-<n>` drops row n), and the preview is computed from the rows that resolve |
| `save(?string $tableKey, ?string $collectionKey, array $input)` | `collections.manage` | `string` key; `ValidationFailed` with every problem (name, each row's `fromInput` problems, too many); `CollectionStore::create` or `update`; audit `collection.saved` |
| `deleteScreen(string $collectionKey)` | `collections.manage` | `CollectionSummary` |
| `delete(string $collectionKey)` | `collections.manage` | void; audit `collection.deleted` |

`CollectionEditor { ProjectRecord $project; TableDef $table; ?string $key; string $name; MatchMode $match; list<EditorRule> $rules; list<SortOption> $sorts; ?string $sort; list<FieldDef> $fields (usable in rules: types of section 5.2); int $matchCount; list<ListRow> $preview (first 5); list<FieldDef> $previewColumns (title plus at most 3, P163; the same type as `RecordListing::$columns`); list<Problem> $problems; bool $canAddRule }`; `EditorRule { int $index; ?FieldDef $field; string $test; list<array{op, label}> $tests; string $value; ?string $record; list<LinkItem> $records (relation: up to 500 from Records::pickList); bool $moreRecords; list<array{key, label}> $choices; ?string $brokenReason }`.

Posted draft shape (from the editor form, part 2): `name`, `match` (`all`|`any`), `sort` (a token or empty), `rules[<n>][field]`, `rules[<n>][test]`, `rules[<n>][value]`, `rules[<n>][record]`, n from 1 in posted order, at most 10 rows read (extra rows ignored). Strings only; arrays where a string is expected are dropped.

`RecordListing` and `SearchResults` gain a last constructor argument `list<CollectionSummary> $collections = []` (filled by `RecordOperations::listing`, `RecordOperations::search`, and `CollectionOperations::listing` with the `summaries()` of the project), which the list and search controllers pass to `Sidebar::build` as plain data.

`ListRequest` gains `?string $collection` (query parameter `collection`, a key or null); `RecordOperations::screen` with a `collection` uses that collection's resolved rules and sort for the record's position and Previous/Next (P162); an unknown collection key in the state is dropped silently (the record still opens).

### 10.5 `TrashOperations` (changed)

| Method (after `Actor, string $projectKey`) | Permission | Returns or does |
|---|---|---|
| `screen(?string $tableKey, int $page)` | `records.view` | `TrashScreen { ProjectRecord $project; list<TrashSection { TableDef $table; int $count; list<TrashedRecord> $records; int $page; int $pages }> $sections; int $total; bool $canRestore; bool $canEmpty }`. Without `tableKey`: every table with at least one trashed record, first 50 each (page 1). With `tableKey`: that table only, paged 50; pages clamp. `TrashedRecord` gains `?string $trashedByName` (display name) |
| `restore(string $tableKey, list<int> $ids)` (M1) | `records.trash` | unchanged. The trash screen restores one record through `RecordOperations::restore` (M2, `records.trash`), which returns the `RecordRef` whose title the notice needs and leaves a live record as it is |
| `restoreAll(string $tableKey)` | `records.trash` | `RestoredAll { TableDef $table; int $count }` (the table's name is in the notice); `Trash::restoreAll` |
| `emptyImpact()` | `trash.empty` | `TrashImpact` over every table and attachment field |
| `empty(?string $tableKey, ?int $expectedRecords = null)` (M1, changed) | `trash.empty` | Returns `TrashEmptied { int $records; int $files }` (was `int`; the two M1 assertions in `TrashAndSnapshotOperationsTest` read `->records`). As M1 (snapshot when anything waits, then delete, audit); new: when `$expectedRecords` is given and the real count is larger, `new ConfirmationRequired($records, $files)` (the existing class; its `values` argument carries the file count) before anything happens (P82 pattern; the screen asks again); after the write, `Attachments::deleteFiles($emptied->freedHashes)` then `Attachments::sweep()`; audit detail gains `files` (files deleted) |

### 10.6 `SchemaOperations` (changed)

After `deleteField` of an attachment field and after `deleteTable` (each once its editor write has committed): `Attachments::sweep()` on the project. Field type changes from or to `attachment` are not allowed by M1 conversions (unchanged), so no other path removes links.

### 10.7 Permissions

| Operation | Admin | Granted Member | Ungranted |
|---|---|---|---|
| Record screen, picker, link, unlink, upload, remove, file page, serve | yes | yes | not found |
| Collection view, summaries | yes | yes | not found |
| Collection editor, save, delete | yes | yes (`collections.manage`) | not found |
| Trash screen, restore, restore all | yes | yes | not found |
| Trash impact, empty | yes | access denied | not found |
| Project settings totals | yes | access denied | not found |

### 10.8 Audit actions (amends ADR 0012 decision 4, P169)

`AuditAction` gains `ATTACHMENT_ADDED = 'attachment.added'`, `ATTACHMENT_REMOVED = 'attachment.removed'` (target kind `table`, key the table key; detail `field`, `record`, `attachment`, `size`), `COLLECTION_SAVED = 'collection.saved'`, `COLLECTION_DELETED = 'collection.deleted'` (target kind `collection`, key the collection key; detail `table`, `rules`, `match`). `trash.emptied` detail gains `files`. No entry holds a file name, a rule operand, or a value. Adding them to `AuditAction::ALL` makes `AuditTrailTest` require each to be written, so the constants land with WP4.

## 11. Message keys produced by the core (new)

English text in `strings/en/problems.php` (owned by M4-WP5), exactly. `tests/core/app/MessageKeysTest.php` and `tests/web/StringsTest.php` read this section as they read M3 section 14 (WP5 for `StringsTest`; WP3 for `MessageKeysTest`, because `MessageKeysTest` fails as soon as any `src/Core` file uses a `problem.*` key no spec section lists, and WP3 (wave 1) and WP2 (wave 2) add such keys before WP4 runs).

| Key | Text |
|---|---|
| `problem.upload.empty` | That file is empty. Choose a file with something in it. |
| `problem.link.required` | Link at least one record here. |
| `problem.attachment.required` | Add at least one file here. |
| `problem.attachment.not_image` | This field takes images only: PNG, JPEG, GIF, or WebP. |
| `problem.attachment.too_many` | This {item} already has {max} files here. Remove one first. |
| `problem.attachment.store_failed` | The file could not be stored. Try again. |
| `problem.link.trashed_holds` | This {item} is linked to a record in the trash. Restore that record and unlink it, or empty the trash, before choosing another. |
| `problem.collection.name_taken` | Another collection already has this name. |
| `problem.collection.too_many` | This project already has {max} collections. Delete one first. |
| `problem.collection.too_many_rules` | A collection can have at most {max} rules. |
| `problem.collection.field` | Choose a field for this rule. |
| `problem.collection.test` | Choose a test for this rule. |
| `problem.collection.value` | Enter a value for this rule. |
| `problem.collection.text` | Use 1 to {max} characters. |
| `problem.collection.number` | Enter a number, for example 12 or 12.5. |
| `problem.collection.date` | Enter a date. |
| `problem.collection.choice` | Choose one of the listed values. |
| `problem.collection.record` | Choose a record. |
| `collection.broken.field` | The field this rule used has been deleted. |
| `collection.broken.type` | The field this rule uses has changed type. |
| `collection.broken.value` | The value of this rule no longer fits the field. |
| `collection.broken.choice` | The value this rule used has been removed from the list. |
| `collection.broken.record` | The record this rule used is in the trash or has been deleted. |

M1 to M3 keys reused: `problem.upload.missing`, `problem.upload.too_large`, `problem.upload.failed`, `problem.upload.no_space`, `problem.name.*`, `problem.text.*`, `problem.link.target_missing`, `problem.link.one_only`, `problem.link.target_taken`. `{item}` in `problem.attachment.too_many` and `problem.link.trashed_holds` is the table's item name, passed as a parameter by the core. The `collection.broken.*` keys are message keys for reasons, printed by the editor and listed in `strings/en/collections.php` by WP7 (not `problems.php`); they are listed here so `MessageKeysTest` sees every key the core produces, and WP5 adds `collection.broken.` to the runtime prefixes of `TemplateRulesTest`.

## 12. Test plan

All tests offline and deterministic with `TempDir`, `FixedClock`, `SeededRandom`; synthetic data only. Files for upload tests are generated in the test: PNG, GIF, JPEG, and WebP files are built byte by byte (signature, a minimal valid structure, `crc32` and `gzcompress` for PNG chunks) by `tests/Support/FileFixture.php` (WP1, seed `FileFixture::SEED = 20261120`); no binary fixture is committed. `tests/Support/M4Fixture.php` (WP9, building on `M2Fixture`) adds attachments and collections to the "Home Inventory" project.

### 12.1 Attachment store (`tests/core/storage/`, WP1)

| ID | Asserts |
|---|---|
| T-STO-9 | `MediaType::sniff`: each signature, each one byte short or altered, empty input, a WebP header with a different form type, `%PDF-`; an SVG, an HTML file, and a PNG signature followed by HTML give the expected type (the last is `image/png`; it is served under `nosniff` and sandbox, part 2) |
| T-STO-10 | `inspect`: SHA-256 equals `hash_file`, size, type; refuses a link or a folder |
| T-STO-11 | `place`: absent target moved with mode 0640 and folder 0750; identical content not moved twice; a link, a folder, and a different-size file at the target refused with nothing moved; outside a write `\LogicException` |
| T-STO-12 | Paths only from valid hashes: upper case, 63 or 65 characters, `../`, NUL refused; `path` null for a link, a folder, a missing file |
| T-STO-13 | `storedHashes` lists only valid entries; stray names (`notes.txt`, a hash in the wrong folder, a link) are skipped and left in place |
| T-STO-14 | `Fs::sha256` streams (a file larger than the memory limit of a child process passes), refuses links; `Fs::freeBytes` returns an integer or null |
| T-STO-15 | `StagingArea` `uploads` kind: create, folder, sweep after one hour (T-IO-2 extended) |
| T-STO-16 | No SQL in the store (the scanner extension of section 2.2) and no path built from anything but a validated hash (source scan) |

### 12.2 Attachments and links (`tests/core/query/`, WP2)

| ID | Asserts |
|---|---|
| T-ATT-1 | `add`: row columns, link position, record `_updated_at` and project meta touched, returned summary; a second upload of the same content: two rows, one file |
| T-ATT-2 | Name cleaning: control characters, U+202E, U+200B, U+FEFF, `/`, `\`, a 300-character name, an empty name, invalid UTF-8 |
| T-ATT-3 | `images_only` refuses a PDF and an SVG with `problem.attachment.not_image`; accepts the four images |
| T-ATT-4 | `MAX_PER_FIELD`: the 101st refused; with `replaceId` at the limit accepted |
| T-ATT-5 | Replace: old link gone, new link at the end, old row deleted only when unlinked, its hash reported; a `replaceId` of another record or field is `NotFound` |
| T-ATT-6 | Failure injected after `place` and before the row insert (trigger on `_tiffin_attachments`): no row, no link, record unchanged; the moved file is an orphan that `sweep` deletes; a shorter orphan file at the target (no row names it) is replaced by the next upload of that content; a different-size file that a row names is refused (`\RuntimeException`, nothing written) |
| T-ATT-7 | `remove`: link deleted; row deleted when last; hash reported; `deleteFiles` deletes the file only when no row has the hash (two rows sharing a file: file kept after the first removal) |
| T-ATT-8 | Interleaving: a second connection holds a write while `deleteFiles` waits; an upload of the same content committed first keeps the file (run with two `Database` objects on one file, `busy_timeout`) |
| T-ATT-9 | `sweep`: unlinked rows deleted, orphan files deleted, stray entries kept; idempotent |
| T-ATT-10 | Deleting an attachment field and a table, then `sweep`: their files gone, files shared with other fields kept |
| T-ATT-11 | `isServable`: live record yes; trashed only no; field deleted no; another project's id no (different file); row without link no |
| T-ATT-12 | `requiredLinkProblems` for attachment fields: no file (problem), one file (none), a field that is not required or not in the given keys (ignored) |
| T-ATT-13 | `totals`: distinct contents and bytes |
| T-ATT-14 | Attachment queries use the forced join order (EXPLAIN shows the link table searched by key first) |
| T-LNK-1 | `addLink` many: appended at the end; already linked: no change; returns quietly |
| T-LNK-2 | `addLink` one: replaces; with a trashed link: `problem.link.trashed_holds` |
| T-LNK-3 | `addLink` respects `target_taken` and `target_missing` (trashed target, absent target, target of another table's key space) |
| T-LNK-4 | `removeLink`: removes live link; absent link `false`; never touches trashed links |
| T-LNK-5 | Joined mode: `within()->create` then `within()->addLink` in one write; a failing link rolls back the create |
| T-LNK-6 | `setLinks` unchanged (M2 tests pass) |
| T-LNK-7 | `linkDetails`: the first list-column field; null value; table with no such field |
| T-LNK-8 | `requiredLinkProblems` for relation fields: no links, only trashed links (problem), one live link (none) |
| T-LNK-9 | Version tokens: `addLink`/`removeLink` change the record's version (links are part of it); a concurrent save with the old token gets `Conflict` |
| T-PCK-1 | `Picker::page`: linked first list, candidates exclude linked (live and trashed), default order, 50 per page, clamp, search with `%` and `_` literal, refused search reported |
| T-PCK-2 | `takenBy` for one-on-the-other-side relations; self-relation lists the record |
| T-PCK-3 | Picker queries' plans on a 20,000-record fixture: the exclusion uses the join table's key, no scan of the join table per candidate |
| T-TRASH-4 | `Trash::list` returns `TrashedRow`; `restoreAll` restores every trashed record of the table, touches meta once |
| T-TRASH-5 | `impact`: records per table; files counts only rows linked solely from trashed records (a shared row with a live link is not counted) |
| T-TRASH-6 | `empty` returns the freed hashes; rows linked from live records survive |
| T-TRASH-7 | M1 trash tests pass with the typed list |

### 12.3 Rules and collections (`tests/core/query/`, WP3)

| ID | Asserts |
|---|---|
| T-RUL-1 | Each operator of section 5.2 on each type against a seeded table: expected ids (including empties never matching comparisons) |
| T-RUL-2 | `all` and `any` with 1, 2, and 10 conditions; no rules: all live records |
| T-RUL-3 | Combined with the M2 filter and search: AND of all three |
| T-RUL-4 | Currency compares minor units; duration seconds; date-time `on` covers the whole day; `before`/`after` exclusive |
| T-RUL-5 | Text `is` ASCII case-insensitive; `contains` with `%`, `_`, `\` literal |
| T-RUL-6 | Hostile operands bound, never in SQL text: quotes, `--`, `;`, NUL (refused), 201 characters (refused) |
| T-RUL-7 | `ListSql::condition` refuses operators outside the union and a condition naming another table's field |
| T-RUL-8 | `RuleSet` refuses 0 and 11 conditions and non-`Condition` values |
| T-RUL-9 | M2 list, filter, position, and search tests pass unchanged (the filter is the one-rule case) |
| T-RUL-10 | `SqlStringsTest` covers the new files with no exemption |
| T-RUL-11 | `EXPLAIN QUERY PLAN` on a 20,000-record fixture (two tables, a many relation, a one relation) for a 10-rule `any` collection with every operator family: no `SCAN` of a join table or of the other table inside a correlated subquery; reported timings (not asserted) |
| T-RUL-12 | `Records::page` and `position` honour `ListQuery::$rules` (position of a record inside a collection, Previous and Next) |
| T-COL-1 | `CollectionStore` create, get, all, update, delete; positions; touch meta |
| T-COL-2 | Names: empty, 61 characters, control characters, taken ignoring ASCII case (`problem.collection.name_taken`, checked before the index) |
| T-COL-3 | Limits: 51st collection, 11 rules refused |
| T-COL-4 | Stored JSON exactly as format 4.8.1; a hand-damaged `rules` value reads with the valid entries and logs |
| T-COL-5 | `CollectionRules::resolve`: every broken reason of section 6.3; broken rules ignored; all broken gives a null set |
| T-COL-6 | Choice by key survives a rename of the choice; removal breaks it |
| T-COL-7 | Sort resolution: a valid token, a deleted field's token (null), `changed.asc` (null) |
| T-COL-8 | `fromInput`: each test token per type, checkbox `ticked`/`not-ticked`, problems with field `rule-<n>` |
| T-COL-9 | `testsFor`: the table of section 5.2 per type; time, auto-number, attachment fields offered none |
| T-COL-10 | Deleting a table deletes its collections (cascade); deleting a field keeps the collection with a broken rule |

### 12.4 Format and archive (WP10)

| ID | Asserts |
|---|---|
| T-FMT-6 | A new file matches `docs/formats/tiffin.md` section 4 (four blocks, `ProjectFileTest` reads the 4th once `VERSION` is 4); `user_version` and meta 4 |
| T-FMT-7 | Version 3 to 4 (and 1 to 4) upgrade: one `format-upgrade` snapshot, the statements of section 11.3 exactly (`FormatUpgradeTest` reads them like 11.1 and 11.2), records and links unchanged, `integrity_check` `ok`, `foreign_key_check` empty; the file opens with the plain PDO SQLite driver and its tables list as documented |
| T-ARC-10 | A version 4 archive with collections imports; keys, names, rules, sort, times kept; positions renumbered |
| T-ARC-11 | Hostile collection rows: invalid key, unknown table, duplicate names, 51 collections, damaged JSON, 11 rules, rules with 3 values, 201-character values, unknown operators, `match_mode` `none`, sort `x.y`: dropped or repaired as section 8.2, counts in the report; never `rebuild-failed` |
| T-ARC-12 | `UntrustedProject` refuses a version 4 file with an extra object, an over-long `rules` total, a collection key equal to a table key; accepts versions 1 to 3 without the table and refuses a version 3 file that has it |
| T-ARC-13 | Version 3 archives (made by the M3 writer layout) still import |
| T-JSON-3 | JSON export has `collections` last, members per `docs/formats/json-export.md` section 7 (read from the document), broken rules included, CSV and XLSX output unchanged |

### 12.5 Forms (WP4)

| ID | Asserts |
|---|---|
| T-FORM-11 | `FormBinder`: `BoundImage` for every image block, `servable` from `BindData`; files with ids; link details |

### 12.6 Core operations (`tests/core/app/`, WP4)

| ID | Asserts |
|---|---|
| T-APP-100 | `picker`: screen facts; record in the trash `NotFound`; non-relation field `NotFound`; field of another table `NotFound` |
| T-APP-101 | `link`/`unlink`: success, idempotence, ids not canonical `NotFound`, target problems pass through |
| T-APP-102 | `linkOrigin`: valid; wrong pattern, non-relation field, relation to another table, trashed record: `NotFound` |
| T-APP-103 | `create` with `from`: one transaction (failure injected in the link step leaves no record) |
| T-APP-104 | `update` with required relation and attachment fields shown on the form and empty: problems with the value problems; not shown on the form: not enforced; create never enforces |
| T-APP-105 | `screen`: link details for many-link fields only; servable images; `canEdit` |
| T-APP-106 | `screen` with `collection` in the state: position and neighbours inside the collection; unknown collection dropped |
| T-APP-110 | `uploadScreen` facts; `replaceId` of another record `NotFound` |
| T-APP-111 | `add`: staged then placed; staging folder removed on success and on every failure (empty, too large, no space, not image, too many, injected store failure); nothing stored on failure |
| T-APP-112 | `add` with replace: one transaction, old file deleted after commit when unshared; audit entries |
| T-APP-113 | `file`: facts; attachment of another record or field `NotFound` |
| T-APP-114 | `remove`: link and row and file; audit `attachment.removed` |
| T-APP-115 | `attachmentTotals`: distinct files and bytes; a Member `AccessDenied` |
| T-APP-116 | Audit detail holds no file name or value (scan of the stored detail JSON) |
| T-APP-120 to T-APP-129 | The access-control matrix at core level (section 12.9) |
| T-APP-130 | `summaries` counts; broken counts |
| T-APP-131 | `listing` of a collection: rules, search, sort default, paging; filter ignored |
| T-APP-132 | `editor` actions `show`, `preview`, `add` (up to 10), `remove-<n>`, field change resets the test |
| T-APP-133 | `save` new and existing; every problem at once; audit `collection.saved` |
| T-APP-134 | `delete`; audit `collection.deleted` |
| T-APP-135 | Collections of another project: `NotFound` by key |
| T-APP-136 | Preview counts equal `listing` totals for the same rules |
| T-APP-137 | Hostile draft arrays (nested arrays, 1,000 rows, non-string values) never throw anything but `ValidationFailed` or redisplay |
| T-APP-140 | Trash `screen` with and without a table; paging; names |
| T-APP-141 | `restoreAll` |
| T-APP-142 | `emptyImpact` and `empty` with `expectedRecords`: equal proceeds, larger asks again (`ConfirmationRequired`), smaller proceeds |
| T-APP-143 | `empty` deletes files freed and sweeps; a file shared with a live record kept |
| T-APP-144 | Deleting an attachment field and a table sweeps their files |
| T-APP-145 | Members: trash screen and restore yes, impact and empty `AccessDenied` |
| T-APP-150 | Data provider over every new or changed method: ungranted Member `NotFound`; granted Member `AccessDenied` exactly where section 10.7 says; deactivated actor refused as M1 |

### 12.7 Web tests

Part 2 section 11.1 (T-WEB-50 to T-WEB-70, T-SCR-4, T-KBD-3, T-E2E-11 to T-E2E-14).

### 12.8 Round trips (WP9)

| ID | Asserts |
|---|---|
| T-RT-7 | `M4Fixture` project with real attachment files (the four image types, a PDF, an HTML file, a zero-byte refusal) and three collections (one with a broken rule): archive export then import: attachment bytes hash-equal, rows and links equal, collections equal (key, name, match, rules, sort); JSON of both equal apart from M3's T-RT-5 exceptions |
| T-RT-8 | Version 3 project made by building a version 3 file (the documented blocks 1 to 3) with attachments: opens, upgrades after one snapshot, serves its files |

### 12.9 Access-control matrix (plan 5.3)

| Matrix row | Core test | Request test |
|---|---|---|
| Signed out, any attachment address | n/a (no actor) | T-WEB-60: 303 to `/sign-in`, no body bytes of the file |
| Member without a grant, valid id | T-APP-120: `NotFound` | T-WEB-61: 404, body equal to a nonexistent id's |
| Member with a grant, linked from a live record | T-APP-121: `ServedFile` | T-WEB-62: 200, headers of ADR 0018 decision 5 exactly (type, disposition, length, nosniff, sandbox CSP), bytes equal |
| Member with a grant, id from another project | T-APP-122: `NotFound` (an id that exists only in the other project; ids are numbered per project) | T-WEB-63: 404 |
| Member with a grant, linked only from a trashed record | T-APP-123: `NotFound` | T-WEB-64: 404 |
| Member with a grant, row present, file missing | T-APP-124: `NotFound`, one `error_log` line without the name | T-WEB-65: 404 |
| Any, upload without CSRF or over the cap | T-APP-125: over the cap `ValidationFailed`, nothing stored | T-WEB-66: 403 without CSRF; 413 over `post_max_size`; 422 over the core cap; staging and attachments folders unchanged |
| Any, uploaded HTML, SVG, or script file | T-APP-126: stored as `application/octet-stream` (SVG, HTML) | T-WEB-67: served as `application/octet-stream` with `attachment` and the sandbox CSP; never inline, also with `?download=0` or other parameters |
| Deactivated person with an old cookie | T-APP-127: `NotFound`/refused via `requireCurrentActor` as M1 | T-WEB-68: 303 to `/sign-in` |
| Direct web request to `attachments/` under the data folder | T-APP-128: none (no core path) | T-WEB-69: the front controller answers 404 for `/data/...` and `/projects/<p>/attachments/...`; installer probe tests (M1) unchanged; Apache behaviour pending the maintainer's host check |
| (added) Image inline only for the four types; `?download=1` | T-APP-129 | T-WEB-62, T-WEB-67 |

## 13. Carried-forward items (M4-WP0)

From plan section 2 and the ledger ("Open low items carried forward", M2 open test gaps):

| ID | Item | Change |
|---|---|---|
| CF-1 | "Check again" loses a typed table name after switching to an existing target and back (M3 X-12) | `ImportOperations::recheck` keeps the stored new-table name when the posted one is empty or absent |
| CF-2 | Import report: the "Rows" column is right-aligned like the count column | `templates/pages/import/report.php`: only the count cell carries `num` |
| CF-3 | A CSV over 200 columns or a record over 1 MiB shows "uses something TiffinDB cannot read yet" | The column case gives `problem.import.too_many_columns` with `{max}`; the 1 MiB record case keeps `unsupported` with a test pinning it. As built the column check is in `src/Core/Io/Csv/CsvFile.php` (which the CSV plugin calls) and throws `PluginFailure` reason `too_large`, which `ImportOperations::readProblem` maps to `unsupported` together with the record case; a plugin can only throw the four `PluginFailure` reasons, so the change is in `CsvFile` or in `ImportOperations`' failure mapping, not in the plugin alone. `tests/core/io/csv/CsvHostileTest.php` pins reason `too_large` for "a million delimiters on one line" and must keep passing unchanged; if CF-3 cannot be met within the owned files, WP0 reports it instead |
| CF-4 | No test pins the XLSX early stop on entries holding more bytes than declared | New test in `tests/plugins/xlsx/` |
| CF-5 | Export page project size (`export.archive.size`, M3 open gap) | `ExportScreen` gains `int $projectBytes` (database file size plus the sizes of the regular files under the project's `attachments/<2>/` folders, read with `Fs::entries` and `Fs::size`; WP0 runs before WP1 and WP2 and uses neither); the archive panel shows "About {size}." |
| CF-6 | M2: no test for the class-name pattern in `settings-preview.js` | Static test of the script's class-name list against `Appearance` |
| CF-7 | M2: no test for a symbolic-link `projects/` folder | `ProjectOperations::create` fails closed; test |
| CF-8 | M2: no test pins which record permission each method checks | Data-provider test over `RecordOperations` and `TrashOperations` M2 methods with a spy authorizer; WP4 extends it to the new methods (T-APP-150) |
| CF-9 | M2: WP0 abandoned-row recheck mutation not re-checked | Mutation run recorded in the WP0 report (no code change expected) |

Tests: T-CF-5 covers CF-1 (a recheck test in `ImportOperationsTest`) and CF-2 (the report's `num` cells in `ImportScreenTest`); T-CF-6 covers CF-3 (a CSV of 201 columns gives `problem.import.too_many_columns` with `max` 200; a record over 1 MiB gives `problem.import.unsupported`) and CF-4 (`XlsxLimitsTest`: an entry holding more bytes than declared stops early); T-CF-7 covers CF-5 (`ExportOperationsTest` with a stored attachment file, `ExportScreenTest` for the text); T-CF-8 covers CF-6 to CF-8 (the three new test files of WP0).

P74 (typed trash listing) is in WP2 (section 4.5). P105 is closed by P157 (WP2, WP4). The older M1 carried items in ledger section 7 not named here stay carried.

## 14. Work packages

Tiers per orchestration guide section 2. Owned files may be created or changed only by that package; packages that can run at the same time own disjoint files (check at the end). A file owned by two packages in **different** waves is listed in both with the part each changes; the later one starts after the earlier one is merged. Every package starts with `git merge --ff-only <assigned branch>`, runs `composer test` and `composer lint` before committing and before reporting, and may not weaken an existing test. A package may add private helpers inside its own directories and must report them.

Waves (at most three agents at once). Changes from plan section 6, with reasons: the plan's WP3 is split into **WP3** (query, rules, collection store; no format change) and **WP10** (format version 4, archive, JSON export), because raising `ProjectFile::VERSION` without the archive accepting version 4 would break the M3 archive round-trip test on merge, and the split keeps the security-critical SQL review small; WP10 runs in wave 2 beside WP2 (disjoint files).

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1, WP3, WP5, then WP0 as a slot frees | M4-R1 merged |
| 2 | WP2, WP10 | WP1 merged (WP2); WP3 merged (WP10) |
| 3 | WP4 | WP2, WP3, WP10 merged |
| 4 | WP6, WP7, WP8 | WP4 and WP5 merged |
| 5 | WP9 | wave 4 merged |

### M4-WP0: Carried-forward items

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | No |
| Owned files | `src/Core/App/ImportOperations.php` (recheck and, for CF-3, the plugin-failure mapping only); `src/Core/Io/Csv/CsvFile.php` (CF-3 column case only); `tests/core/io/csv/CsvReaderTest.php` (CF-3 cases); `src/Core/App/ExportOperations.php`, `src/Core/App/ExportScreen.php` (project size only); `templates/pages/import/report.php`; `templates/pages/export/form.php`; `strings/en/export.php` (`export.archive.size`); `plugins/import/csv/src/*` (only if CF-3 needs it); `tests/core/app/ImportOperationsTest.php`, `ExportOperationsTest.php`; `tests/web/ImportScreenTest.php`, `ExportScreenTest.php` (the new assertions only); `tests/plugins/csv/CsvImporterTest.php`; `tests/plugins/xlsx/XlsxLimitsTest.php`; new `tests/web/SettingsScriptTest.php`, `tests/core/app/ProjectLinkFolderTest.php`, `tests/core/app/RecordPermissionMapTest.php` |
| Scope | Section 13 |
| Acceptance | Tests T-CF-5 (CF-1 and CF-2), T-CF-6 (CF-3 and CF-4), T-CF-7 (CF-5), T-CF-8 (CF-6 to CF-8) pass; CF-9 reported; implementation-tier review |

### M4-WP1: Attachment store

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (file handling, names, placement) |
| Owned files | `src/Core/Storage/AttachmentStore.php`, `MediaType.php`, `StoredUpload.php` (new); `src/Core/Storage/Fs.php` (`sha256`, `freeBytes`); `src/Core/Io/StagingArea.php` (kind `uploads`); `tests/core/storage/AttachmentStoreTest.php`, `MediaTypeTest.php` (new), `tests/core/storage/FsTest.php` (new cases), `tests/core/io/StagingAreaTest.php`; `tests/core/schema/SqlStringsTest.php` (the Storage rule of section 2.2 only); `tests/Support/FileFixture.php` (new) |
| Scope | Section 3 |
| Acceptance | T-STO-9 to T-STO-16 pass; design-tier security review |

### M4-WP2: Attachments, links, picker, trash in the records layer

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 |
| Security-critical | Yes (file removal order, link integrity, SQL) |
| Owned files | `src/Core/Query/Attachments.php`, `AttachmentRow.php`, `AttachmentAdded.php`, `AttachmentRemoved.php`, `Picker.php`, `CandidatePage.php`, `Candidate.php`, `LinkDetail.php`, `TrashedRow.php`, `EmptiedTrash.php`, `TrashImpact.php` (new); `src/Core/Query/Records.php`, `Trash.php`, `AttachmentSummary.php`; `src/Core/App/TrashOperations.php` (the adapted `Trash::list` and `empty` call sites only); `tests/core/query/AttachmentsTest.php`, `LinkChangeTest.php`, `PickerTest.php`, `RequiredLinksTest.php`, `TrashFilesTest.php` (new), `tests/core/query/TrashTest.php`, `RecordsFixture.php` |
| Scope | Section 4 |
| Acceptance | T-ATT-1 to T-ATT-14, T-LNK-1 to T-LNK-9, T-PCK-1 to T-PCK-3, T-TRASH-4 to T-TRASH-7 pass; M2 record tests pass unchanged; design-tier security review (removal order with injected failures, interleaving test) |

### M4-WP3: Multi-rule query, operators, collection store

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (SQL composition) |
| Owned files | `src/Core/Query/ListQuery.php`, `ListSql.php`, `Operator.php`; `src/Core/Query/MatchMode.php`, `RuleSet.php`, `Collection.php`, `CollectionRule.php`, `CollectionStore.php`, `CollectionRules.php`, `ResolvedRules.php`, `RuleState.php` (new); `tests/core/query/RuleSqlTest.php`, `RulePlanTest.php`, `CollectionStoreTest.php`, `CollectionRulesTest.php` (new); `tests/core/query/RecordsListTest.php` (new cases only); `tests/core/app/MessageKeysTest.php` (reads section 11 as well) |
| Scope | Sections 5 and 6. Tests create `_tiffin_collections` from the fourth block of `docs/formats/tiffin.md` section 4 on files made by the version 3 `ProjectFile::create` (WP10 makes it part of the file) |
| Acceptance | T-RUL-1 to T-RUL-12, T-COL-1 to T-COL-10 pass; M2 list tests pass unchanged; design-tier security review of the SQL composition |

### M4-WP4: Core operations

| | |
|---|---|
| Tier | implementation |
| Depends on | WP2, WP3, WP10 |
| Security-critical | Yes (authorization boundary) |
| Owned files | `src/Core/App/AttachmentOperations.php`, `CollectionOperations.php`, `PickerScreen.php`, `LinkOrigin.php`, `UploadScreen.php`, `FileScreen.php`, `ServedFile.php`, `CollectionSummary.php`, `CollectionFacts.php`, `CollectionEditor.php`, `EditorRule.php`, `TrashScreen.php`, `TrashSection.php`, `RestoredAll.php`, `TrashEmptied.php`, `AttachmentTotals.php` (new); `src/Core/App/RecordOperations.php`, `TrashOperations.php`, `SchemaOperations.php`, `ProjectOperations.php`, `Operations.php`, `Context.php`, `ListRequest.php`, `RecordListing.php`, `SearchResults.php`, `RecordScreen.php`, `TrashedRecord.php`; `src/Core/Forms/BoundImage.php` (new), `FileItem.php`, `LinkItem.php`, `BindData.php`, `FormBinder.php`; `src/Core/System/AuditAction.php`; `tests/core/app/AttachmentOperationsTest.php`, `LinkOperationsTest.php`, `CollectionOperationsTest.php`, `TrashScreenOperationsTest.php`, `AttachmentAccessTest.php`, `M4PermissionsTest.php` (new), `tests/core/app/RecordOperationsTest.php`, `RecordListingTest.php`, `TrashAndSnapshotOperationsTest.php`, `SchemaOperationsTest.php`, `ProjectOperationsTest.php`, `AuditTrailTest.php`, `RecordPermissionMapTest.php` (extended); `tests/core/forms/FormBinderTest.php`; `tests/Support/CoreFixture.php` |
| Scope | Sections 9 and 10 |
| Acceptance | T-FORM-11, T-APP-100 to T-APP-150 pass; M1 to M3 T-APP tests pass; design-tier security review |

### M4-WP5: Web components

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (headers, templates) |
| Owned files | Part 2 section 12, WP5 row |
| Scope | Part 2 sections 2 to 9 (shared parts) |
| Acceptance | Part 2 section 12, WP5 row |

### M4-WP6: Record screens, picker, files

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4, WP5 |
| Security-critical | Yes (upload path, serving controller, templates) |
| Owned files | Part 2 section 12, WP6 row |
| Scope | `docs/design/m4/record-controls.md`, `picker.md`, `files.md` |
| Acceptance | Part 2 section 12, WP6 row |

### M4-WP7: Collection screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4, WP5 |
| Security-critical | Yes (templates; posted rule arrays) |
| Owned files | Part 2 section 12, WP7 row |
| Scope | `docs/design/m4/collections.md` |
| Acceptance | Part 2 section 12, WP7 row |

### M4-WP8: Trash screen

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4, WP5 |
| Security-critical | Yes (templates, bound confirmation) |
| Owned files | Part 2 section 12, WP8 row |
| Scope | `docs/design/m4/trash.md` |
| Acceptance | Part 2 section 12, WP8 row |

### M4-WP9: End to end, matrix at request level, round trips, keyboard, visual

| | |
|---|---|
| Tier | implementation |
| Depends on | WP6, WP7, WP8 |
| Security-critical | No |
| Owned files | `tests/Support/M4Fixture.php` (new); `tests/roundtrip/AttachmentRoundTripTest.php`, `UpgradeWithFilesTest.php` (new); part 2 section 12, WP9 row |
| Scope | Section 12.8; part 2 sections 10 and 11 (T-E2E-11 to T-E2E-14, T-WEB-60 to T-WEB-70, T-SCR-4, T-KBD-3, K-3, V-20 to V-25) |
| Acceptance | Tests pass; a report with K-3 steps, visual deviations per screen and width, and T-RUL-11 and T-PCK-3 timings |

### M4-WP10: Format version 4, archive, JSON export of collections

| | |
|---|---|
| Tier | implementation |
| Depends on | WP3 |
| Security-critical | Yes (opens untrusted files) |
| Owned files | `src/Core/Schema/ProjectFile.php`, `FormatMigrations.php`; `src/Core/Io/Archive/UntrustedProject.php`, `ProjectRebuilder.php`, `RebuildReport.php`; `src/Core/Io/Contract/ExportSource.php`, `ExportCollection.php`, `ExportRule.php` (new); `src/Core/Io/ProjectExportSource.php`; `plugins/export/json/src/*`; `docs/formats/json-export.md` (the section 1 example's `collections` member and the removal of the two M4-D1 design notes, one in each format document); `docs/formats/tiffin.md` (that design note only); `tests/core/schema/ProjectFileTest.php`, `FormatUpgradeTest.php`; `tests/core/io/archive/ArchiveImportTest.php`, `RebuildCollectionsTest.php` (new), `SqliteCorpusTest.php`; `tests/core/io/ExportSourceTest.php`; `tests/plugins/json/*`; `tests/plugins/csv/CsvExporterTest.php` (its `FakeSource` gains `collections()` only); `tests/formats/JsonExportFormatTest.php`; `tests/roundtrip/ArchiveRoundTripTest.php`, `JsonRoundTripTest.php` (collections assertions) |
| Scope | Sections 7 and 8 |
| Acceptance | T-FMT-6, T-FMT-7, T-ARC-10 to T-ARC-13, T-JSON-3 pass; M3 archive and JSON tests pass; design-tier security review of the rebuild |

### Summary

| ID | Title | Tier | Depends on | Security-critical | Wave |
|---|---|---|---|---|---|
| M4-WP0 | Carried-forward items | implementation | none | No | 1 |
| M4-WP1 | Attachment store | implementation | none | Yes | 1 |
| M4-WP2 | Attachments, links, picker, trash (Query) | implementation | WP1 | Yes | 2 |
| M4-WP3 | Multi-rule query, operators, collection store | implementation | none | Yes | 1 |
| M4-WP4 | Core operations | implementation | WP2, WP3, WP10 | Yes | 3 |
| M4-WP5 | Web components | implementation | none | Yes | 1 |
| M4-WP6 | Record screens, picker, files | implementation | WP4, WP5 | Yes | 4 |
| M4-WP7 | Collection screens | implementation | WP4, WP5 | Yes | 4 |
| M4-WP8 | Trash screen | implementation | WP4, WP5 | Yes | 4 |
| M4-WP9 | End to end, matrix, round trips, keyboard, visual | implementation | WP6, WP7, WP8 | No | 5 |
| M4-WP10 | Format version 4, archive, JSON export | implementation | WP3 | Yes | 2 |

Design-tier security reviews (orch 2 rule 4; plan section 6): WP1 (store), WP2 (removal order), WP3 (multi-rule SQL), WP4 (authorization, download authorization), WP5 (file headers, templates), WP6 (upload path, serving controller), WP10 (untrusted archive rows).

Ownership check. Wave 1: WP0 holds the M3 import and export operation files named, `CsvFile.php`, two templates, `export.php` strings, the CSV import plugin, and its own tests; WP1 holds the Storage files, `StagingArea.php`, its tests, the Storage rule of `SqlStringsTest.php`, `FileFixture.php`; WP3 holds `ListQuery`, `ListSql`, `Operator`, the new collection files, its tests, new cases in `RecordsListTest.php`, and `MessageKeysTest.php`; WP5 holds the web files of part 2 section 12. `tests/web/ImportScreenTest.php` and `ExportScreenTest.php` are WP0's only; `TemplateRulesTest.php` is WP5's. No file is shared in wave 1. Wave 2: WP2 holds `Records.php`, `Trash.php`, `AttachmentSummary.php`, its new Query files, the two call sites of `TrashOperations.php`, `RecordsFixture.php`; WP10 holds the two Schema files, the archive files, the contract and export source files, the JSON plugin, `CsvExporterTest.php`, and the format documents' notes; disjoint (WP0, which owns `CsvImporterTest.php`, ran in wave 1). Wave 3: WP4 alone (it changes `TrashOperations.php` after WP2). Wave 4: WP6, WP7, WP8 own their controllers, templates, strings, and tests (part 2 section 12); `routes.php`, `app.css`, `layout.php`, shared partials, `Sidebar.php`, and shared strings are WP5's (merged). Files touched by two waves: `TrashOperations.php` (WP2 call sites, then WP4), `tests/core/app/RecordPermissionMapTest.php` (WP0, then WP4), `docs/formats/*.md` (this design, then WP10's notes).

## 15. Provisional decisions (for the ledger, from P146)

Each is the option cheapest to reverse (orchestration guide section 5) and is recorded in ADR 0018 or 0019 where it is a decision rather than a constant. Plan section 3 rows 1 to 7 were not answered by the maintainer; their defaults are applied (rows 1 to 5 in P146 to P148, P159 to P161; row 6 in P170; row 7 is a session rule: stop at M4 close with one notification).

| # | Item | Provisional resolution | Recorded in |
|---|---|---|---|
| P146 | Attachment types (plan 3 row 1) | Any type accepted; only PNG, JPEG, GIF, WebP shown in a page, detected by TiffinDB's own leading-byte check; PDF recognised for its label only; everything else stored as `application/octet-stream` and served as a download | ADR 0018 d1, d2 |
| P147 | Upload limits (plan 3 row 2) | Per file the smaller of 64 MiB and the PHP limits; empty files refused; 100 files per field per record; disk reserve 100 MiB; no quota; project settings show file count and total size | ADR 0018 d3 |
| P148 | Thumbnails (plan 3 row 3) | None; images sent as stored and sized by CSS; no image decoding, no `gd` | ADR 0018 d3 |
| P149 | Attachment staging and placement | Upload staged in `<data>/staging/uploads/<key>/` (new kind, swept after 1 h), hashed, then placed at `attachments/<2>/<64>` inside the project write before the row; one row per upload, identical content shares the file; names cleaned (P61 categories, `/` `\`, 255 characters) | ADR 0018 d2, d4 |
| P150 | Serving | `GET /projects/{p}/files/{a}` (`?download=1`); `records.view` plus a link from a live record in an existing attachment field; four image types inline with their type, all else `application/octet-stream` attachment; `nosniff`; CSP `default-src 'none'; frame-ancestors 'none'; sandbox`; global `Cache-Control: no-store` kept (plan's `private` implied); missing file is not found and logged | ADR 0018 d5 |
| P151 | File removal | Link removed, row removed when unlinked, file deleted after commit inside a write when no row names the hash; full sweep after emptying the trash and after deleting an attachment field or table; form `img` references keep nothing alive (M5 to revisit) | ADR 0018 d6 |
| P152 | Remove and replace | Both through a confirmation page (irreversible); replace is one upload that adds and removes in one transaction | ADR 0018 d6 |
| P153 | Relation picker | Separate page; immediate per-row Link/Choose and Unlink posts, find and paging kept, no staged ticks and no version token (amends plan 5.2 "checkboxes and one Save") | ADR 0019 d9 |
| P154 | Add a new record from the picker | New-record form with `from=<field key>-<record id>`; create and link in one transaction; return to the picker; one-link field replaces ("Add a new room and choose it") | ADR 0019 d11 |
| P155 | Record form controls | Choose, Link, Add a file, Open, Replace, Remove are links to their own pages; Unlink buttons use the `form` attribute with one form per relation field; unsaved edits not carried and the form says so | ADR 0019 d10 |
| P156 | New records | Relation and file sections show a note that they can be filled after the first Save | ADR 0019 d10 |
| P157 | Requiredness of relation and attachment fields (closes P105) | Enforced on saving an existing record for fields its effective form shows; unlinking or removing the last one allowed; create and imports never enforce | ADR 0018 d7, ADR 0019 d8 |
| P158 | Linked-record row secondary line | The linked table's first list-column field other than its title, holding a value: "{field} {value}" | `docs/design/m4/record-controls.md` |
| P159 | Collections storage (format version 4) | `_tiffin_collections` of format 4.8; names unique in the project ignoring ASCII case; choice operands by choice key; operands as canonical text re-checked on read; broken rules kept, shown, ignored; 0 to 10 rules; at most 50 collections | ADR 0019 d1, d2, d4 |
| P160 | Collection operators (plan 3 row 5) | Table of ADR 0019 d3; time, auto-number, attachment fields not usable; mock-up's "is on or after" becomes "is after" | ADR 0019 d3 |
| P161 | Collection ownership (plan 3 row 4) | Shared; Admins and granted Members manage; viewing needs `records.view` | ADR 0019 d5 |
| P162 | Collection view | List view at `/projects/{p}/collections/{c}` with find, sort, paging, no extra filter; record Previous/Next follow the collection (`collection` in the list state) | ADR 0019 d7 |
| P163 | Collection editor without script | One form; Add a rule, Remove, Preview are submit buttons; a hidden Preview button first so Enter previews; field changes applied on any press; optional script presses Preview on field change; preview of the count and first 5 records with the title and up to 3 columns | ADR 0019 d7 |
| P164 | Deleting a collection | Through a confirmation page | ADR 0019 d5 |
| P165 | Multi-rule composition | `ListQuery` gains an optional `RuleSet`; one `ListSql::condition` for filter and rules | ADR 0019 d6 |
| P166 | Archive and collections | Archives of versions 1 to 4 accepted; collection rows repaired or dropped and counted; shape-invalid rules dropped and counted; rules that do not fit the schema kept | `docs/formats/tiffin.md` 13.3; this spec 8 |
| P167 | JSON export collections | `collections` last member; `format_version` stays 1 (additive) | `docs/formats/json-export.md` 7, 9 |
| P168 | Trash screen | `/projects/{p}/trash` (breadcrumb page); a section per table with records, 50 per table, a table's own paged view; Restore; Restore all in a table; Empty the trash (whole project, Admins) through a confirmation stating records and files, re-asked when the count grew; sidebar trash count becomes a link | `docs/design/m4/trash.md`; this spec 10.5 |
| P169 | Audit actions | `attachment.added`, `attachment.removed`, `collection.saved`, `collection.deleted`; `trash.emptied` gains `files` (amends ADR 0012 d4) | ADR 0018 d8, ADR 0019 d12 |
| P170 | P49 items (plan 3 row 6) | Snapshot restore, project deletion, audit-log screen assigned to M7 with the About page | ledger |
| P171 | Work-package changes from the session plan | WP3 split into WP3 (query, collections) and WP10 (format 4, archive, JSON export, wave 2); WP2 adapts two `TrashOperations` call sites | this spec 14 (process; ledger only) |
| P172 | File page | One page per attached file (`.../records/{r}/files/{field}/{a}`): the image large for the four types, facts, Download, Replace, Remove; the record form's "Open" goes there for every file | `docs/design/m4/files.md` |
| P173 | Form images | Form format 1 `img` elements render through the serving route only when servable (inline type, linked from a live record); otherwise nothing | `docs/formats/tiffin.md` 12.4; this spec 9 |
| P174 | Picker page size and order | 50 candidates per page in the other table's default order; candidates exclude records already linked (live or in the trash) | this spec 4.4 |

## 16. Assumptions and open points

1. The web layer's `UploadedFile` and `UploadLimits` (M3) are reused unchanged for attachment uploads; the field name is `file`.
2. `Database::write()` is `BEGIN IMMEDIATE`, so a write holds SQLite's write lock from its start (as built); the removal order of section 4.2 depends on it.
3. `RETURNING` is available (SQLite 3.35 or later; the floor is 3.35.5, P38).
4. Household scale: at most a few thousand attachments per project; `storedHashes` lists them all inside one short write. A project with very many files would make the sweep's write longer; measured in WP9 (reported only).
5. The serving route reads the whole file through `Response`'s streaming (64 KiB chunks, M3); range requests are not supported (videos cannot be seeked; not needed for v1).
6. Browsers honour `X-Content-Type-Options: nosniff` for images and `Content-Disposition: attachment`; the CSP sandbox protects a file opened directly. Old browsers that ignore these are out of scope.
7. A visually hidden first submit button is used by browsers for implicit submission (Enter) in the collection editor; K-3 confirms it in headless Chromium only (assumption for other browsers; if it fails, the fallback is to place a visible Preview button before the rules).
8. Not known: the reference shared host's upload limits (host check), and whether the host's file system allows `rename` from `staging/` to `projects/` (same data folder; `Fs::moveIn` falls back to copy).
9. Missing information for the maintainer (not blocking): whether collections should be per person later (P161); whether time and auto-number fields should be usable in rules (P160); whether a picker "link all shown" action is wanted (ADR 0019 consequences); whether `Cache-Control` for files should allow private caching (P150).
10. `docs/design/m4/` components reuse the M2 `.link-list`, `.link-row`, `.file-row` classes; their final measurements come from `record.html` at review.

## 17. As-built notes (M4-X exit audit)

Where the merged code differs from the text above, the code, these notes, and the provisional decisions in `docs/build/ledger.md` govern.

- **Section 8.1, invalid collection keys (P183, WP10 review L2).** The sentence "keys valid and unique across tables, fields, relations, forms, and collections (`bad-metadata`)" reads as built: a collection key that **is** a valid key must not equal the key of a table, field, relation, form, or another collection (`bad-metadata`, `UntrustedProject::readCollections`); a collection row whose key is **not** a valid key is not refused: it is skipped by `UntrustedProject` and dropped by the rebuild (section 8.2, counted in `collections_dropped`; T-ARC-11). The row is never copied, so the file as a whole stays importable.
- **Section 10.4, collection counts.** `Listings::count` (sidebar and `summaries()` counts) no longer joins its own `SELECT COUNT(*)` statement in `Core\App`; it calls the new `Records::countMatching(string $tableKey, ListQuery $query): int`, which `Records::page` and the search count share (`Records::countWhere`). SQL composition stays in the directories `SqlStringsTest` scans (section 2.2, P107); the new test `SqlStringsTest::testCoreAppComposesNoSqlFragments` refuses any use of the `Sql` fragment class in `src/Core/App/`.
- **Section 4.2.1, failed uploads.** As designed (ADR 0018 decision 6): an upload refused before placement (CSRF, size, zero bytes, type, count) leaves no file, no row, and no staging folder; a failure after placement and before commit (a database error while inserting the row or link) leaves no row and the placed file as an orphan, which the orphan-file sweep at the start of the next upload or trash emptying of that project removes. M4-X checked both on a real installation (injected trigger failures).
- **T-ATT-8** (`AttachmentsTest::testDeleteFilesWaitsForAnUploadOfTheSameContent`) asserts the order of events (deleteFiles started before the other process's commit and returned after it), with the other process holding its write until told to go, instead of a wall-clock wait above 200 ms; the earlier form failed under a scheduling stall of about 200 ms (the one-off full-suite failure M4-X was asked to find is most likely this test).
- Response headers for served files are exactly those of ADR 0018 decision 5 (`Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, `Content-Disposition` inline only for the four image types); the session plan's `default-src 'none'; sandbox` and `Cache-Control: private` are superseded by that decision.
