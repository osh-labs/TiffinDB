# M2 design spec: records, forms, and work packages

Status: design spec for milestone M2, part 1 of 2 (M2-D1), awaiting spec review (M2-R1). This part covers the core: the record layer, list queries and search, the form format and generator, project format version 2, the new core operations, the warning-token correction, the test plan, the provisional decisions, and **all M2 work packages**. Part 2, `docs/specs/M2-screens.md`, covers the web layer: routes, controllers, templates, field rendering per type, strings, stylesheet and script, the keyboard procedure, and how screens are checked against the mock-ups. Screen designs are under `docs/design/m2/`.

Decisions this spec relies on: ADR 0008 (layout, conventions), ADR 0009 (provisional defaults, rows A1, A3, A4), ADR 0010 (project file), ADR 0012 (core operations), ADR 0013 (record layer), ADR 0014 (form format and rendering). The file format is documented for users in `docs/formats/tiffin.md` (now version 2); where this spec and that document differ, stop and report. Everything in `docs/specs/M1-foundation.md` and `docs/specs/M1-screens.md`, with their as-built notes and the provisional decisions P1 to P83 (`docs/build/ledger.md` section 4), still applies unless a section below changes it.

Audience: engineers holding this spec, `CLAUDE.md`, and the files a work package names. `CLAUDE.md` has the highest authority.

## 1. Goals, non-goals, exit criteria

### 1.1 Goals

1. A headless record layer (`Core\Query\Records`): create, read, update, list with paging, sort, one-condition filter, search within a table and across tables, required fields, auto-numbers on create, relation link operations, and a lost-update guard.
2. Form format 1 (`Core\Forms`): node model, default-deny parser, canonical serializer, generator, storage in `_tiffin_forms`, regeneration inside schema changes, and a binder that joins a form with field definitions and plain values.
3. Project format version 2 with its upgrade step.
4. Core operations for records, search, display settings, and form regeneration, with authorization in the core.
5. The web screens of part 2: list view, filter, search results, record form, new record, record in the trash, edit conflict, display settings, and the shared components.
6. The light-theme warning token at 7:1 or better, and contrast tests over all ten theme and accent combinations.
7. The carried-forward items mapped to M2 (section 11).

### 1.2 Non-goals for M2

- Relation picker, linking and unlinking on screen, attachment upload, opening and serving files, image view, the trash screen, saved collections (M4). M2 shows links and file names read-only; link operations exist in the core only.
- Form editor, source view, form CSS, the full sanitizer (M5). M2 reads stored forms through the default-deny parser and never prints stored markup.
- Import and export (M3), snapshot restore, project deletion, audit-log screen (P49).
- Autosave (ADR 0013 decision 7); list column configuration; filters with more than one condition.

### 1.3 Exit criteria

| Exit criterion (session plan section 2; plan 8; ui-design-plan 11) | How M2 meets it |
|---|---|
| A Member can add, find, and edit records by keyboard only | Core: T-APP-70 to T-APP-73. Through the kernel as a Member: T-E2E-5. Structure: T-KBD-1 (labels, order, no positive `tabindex`, focus targets, implicit submission). Scripted headless-browser run with key presses only: K-1 (part 2 section 10), evidence recorded in the ledger by the exit audit |
| Contrast tests pass and cover all 10 theme and accent combinations with the ui 4.2 figures | T-DES-7 (section 10.6) asserts every cell of ui-design-plan sections 4.2 and 4.5, the 7:1 text threshold, and the 3:1 control-border threshold |
| Light-theme warning token at 7:1 or better | Done in this design: `--warn` `#703D00` in both token files, 7.61:1 (section 9); T-DES-2 and T-DES-7 |
| Scope (session plan section 2): list view, auto-generated form, record create, read, update, move to trash, search and sort, display settings, search results, empty and error states, confirmation pages | Sections 3 to 7 and part 2; tests T-REC, T-FORM, T-FMT, T-APP, T-WEB, T-SCR-2; visual procedure V-9 to V-14 (part 2 section 11) |
| Data portability (exit audit, session plan 6.3) | Version 2 file matches `docs/formats/tiffin.md` (T-SCH-1, T-FMT-1); a version 1 file upgrades after a snapshot (T-FMT-2) |
| Security baseline for the new surface | Escaping of every type's display and input (T-WEB-27); authorization in core for every record operation, ungranted Member gets not found (T-APP-74); SQL from identifiers and fragments only (T-REC-14); stored forms never printed (T-FORM-6, T-WEB-29) |

## 2. Code layout

New PHP follows ADR 0008 decision 6 (`final`, typed `readonly` properties, `declare(strict_types=1)`, SPDX block, no `@`, no global state). New or changed directories:

```
src/Core/Error/       + Conflict
src/Core/Query/       + Records, Sql, InputMode, RecordData, AttachmentSummary, LinkedRecord, ListQuery, Sort,
                        Operator, Condition, NormalisedQuery, RecordPage, Position, SearchGroup, SearchHit,
                        RecordVersion (Trash unchanged)
src/Core/Forms/       new: FormFormat, FormDocument, Block, Section, FieldSlot, Heading, Paragraph, Divider, Image,
                        Inline, TextRun, Strong, Emphasis, LineBreak, Link, Placement, FormParser, FormRejected,
                        FormSerializer, FormGenerator, FormStore, StoredForm, FormBinder, BindData, BoundForm,
                        BoundNode and its kinds, LinkItem, FileItem
src/Core/Schema/      ProjectFile (version 2), FormatMigrations (step 2), SchemaEditor (after-change hook, form keys)
src/Core/App/         + RecordOperations, SettingsOperations, ListRequest, RecordListing, ListRow, Cell, SortOption,
                        ActiveFilter, FilterOptions, RecordScreen, RecordPosition, ConflictView, ConflictRow,
                        SearchResults, SearchResultGroup, SearchResultHit, RecordRef; Operations and Context changed
src/Core/Auth/        Users (saveSettings)
tests/core/query/, tests/core/forms/, tests/core/app/   new tests
```

Each work package (section 12) lists its exact files and may add small private helpers inside its own directories, reporting them.

### 2.1 Dependency rule

ADR 0014 decision 5 amends the table of `docs/specs/M1-foundation.md` section 2.1; `tests/repo/CoreLayerRulesTest.php` (T-REPO-9) gains the row and the App entry:

| Area | May use |
|---|---|
| Forms | Schema, Storage, Error |
| App | Error, Storage, System, Auth, Schema, Query, Forms |

`Core\Forms` must not use `Core\Query`; `Core\Query` must not use `Core\Forms`. The web layer still names only the classes T-WEB-23 allows (`docs/specs/M1-screens.md` section 9): it reads the result objects of `Core\App` through their properties and the `kind()` strings of bound-form nodes, never naming a `Core\Query` or `Core\Forms` class, so T-WEB-23 needs no change.

## 3. Record layer (`TiffinDB\Core\Query`)

### 3.1 Values

```php
enum InputMode: string
{
    case Form = 'form';            // text typed in a form: FieldType::parse(text, options, lenient: false)
    case Import = 'import';        // text from an import (M3): parse(text, options, lenient: true)
    case Canonical = 'canonical';  // canonical PHP values from a core caller: FieldType::check(value, options)
}

final class AttachmentSummary
{
    public function __construct(
        public readonly int $id, public readonly string $originalName,
        public readonly string $mediaType, public readonly int $size,
    ) {}
}

final class LinkedRecord
{
    public function __construct(
        public readonly string $tableKey, public readonly int $id,
        public readonly ?string $title,   // the title field's canonical text (toText), or null
    ) {}
}

final class RecordData
{
    public function __construct(
        public readonly int $id,
        public readonly array $values,        // field key => canonical value (null when empty), every field with a column
        public readonly array $links,         // relation field key => list<LinkedRecord>, live targets only, in link order
        public readonly array $attachments,   // attachment field key => list<AttachmentSummary>, in link order
        public readonly string $createdAt, public readonly ?string $createdBy,
        public readonly string $updatedAt, public readonly ?string $updatedBy,
        public readonly ?string $trashedAt, public readonly ?string $trashedBy,
        public readonly string $version,      // section 3.5
    ) {}
}
```

`TiffinDB\Core\Error\Conflict extends CoreException` with `public readonly string $currentVersion`; thrown by `Records::update` when the expected version differs. Its message is for logs only.

### 3.2 `Records`

```php
final class Records
{
    public const PAGE_SIZE = 50;
    public const MAX_SEARCH_LENGTH = 200;
    public const SEARCH_HITS_PER_TABLE = 10;
    public const MAX_LINK_IDS = 1000;
    public const PICKER_LIMIT = 500;

    /** One instance per opened project and request; $schema must be current (build a new one after schema changes). */
    public function __construct(Database $db, Schema $schema, FieldTypes $types, Clock $clock) {}

    public function get(string $tableKey, int $id): ?RecordData;   // trashed records included; null when absent
    public function count(string $tableKey): int;                   // live records
    /** @param array<string, mixed> $values field key => value as $mode expects */
    public function create(string $tableKey, array $values, InputMode $mode, string $userKey): int;
    public function update(string $tableKey, int $id, array $values, InputMode $mode,
                           string $expectedVersion, string $userKey): RecordData;
    public function query(string $tableKey, ?string $sort, ?string $filterField, ?string $operator,
                          array $operands, ?string $search, int $page): NormalisedQuery;   // section 3.6
    public function page(string $tableKey, ListQuery $query): RecordPage;                 // section 3.7
    public function position(string $tableKey, ListQuery $query, int $id): ?Position;    // section 3.7
    /** @return list<Sort> every sort the table offers, in menu order (section 3.6) */
    public function sorts(string $tableKey): array;
    public function search(string $text): array;                    // list<SearchGroup>, section 3.8
    /** @param list<int> $targetIds */
    public function setLinks(string $fieldKey, int $id, array $targetIds, string $userKey): void;
    /** @return array<int, ?string> id => title for the given live or trashed ids of the table */
    public function titles(string $tableKey, array $ids): array;
    /** @return list<LinkedRecord> live records of the table by title (ASCII case-insensitive), then id; at most $limit */
    public function pickList(string $tableKey, int $limit): array;
}
```

Every key argument is checked: an unknown table or field key, a field of another table, or a relation operation on a non-relation field throws `NotFound` (the operation layer has already checked the keys the person gave; these are guards). Record ids must be positive integers (`\InvalidArgumentException` otherwise).

### 3.3 Create and update

Validation (all problems collected, then `ValidationFailed`; `Problem::$field` is the field key):

| Rule | Problem |
|---|---|
| A key in `$values` that is not a field of the table | `problem.value.unknown_field` |
| A relation or attachment field in `$values` (links go through `setLinks`; files are M4) | `problem.value.unsupported` (M1 key) |
| The value fails the type's parse (`Form`, `Import`) or check (`Canonical`) for the field's options | the type's key and parameters (M1 section 8.2); an auto-number in `Form` mode gives `problem.auto_number.read_only` |
| A required field whose value is empty after parsing. On create: every required field of the table with a column, whether given or not. On update: required fields that are given | `problem.value.required` |

`checkbox` and `auto_number` fields are never required (M1 forces `required` false); relation and attachment fields are not checked for requiredness in M2 (ADR 0013 decision 7).

**Create**, in one `Database::write()`:

1. For each `auto_number` field of the table: read its stored `options` (`SELECT "options" FROM "_tiffin_fields" WHERE "key" = ?`) and `SELECT MAX(<f>) FROM <t>`; the number is `max(options.next, MAX + 1)` (the MAX term only when not null); store `options.next` = number + 1 with `UPDATE "_tiffin_fields" SET "options" = ? WHERE "key" = ?` (options encoded as `docs/specs/M1-foundation.md` section 7.3 requires). Field `updated_at` is not changed.
2. `INSERT INTO <t> ("_created_at", "_created_by", "_updated_at", "_updated_by", <given and auto-number columns>) VALUES (...)` with `toStorage` values; checkbox fields not given are stored as 0 by their column default; other fields not given are null.
3. `ProjectFile::touch($db, $userKey, $clock)`.
4. Return the new id.

**Update**, validation first (outside the transaction), then in one `Database::write()`:

1. Read the row with its links (as `get`). Absent or in the trash: `NotFound`.
2. Compute its version (section 3.5); different from `$expectedVersion`: throw `Conflict(current version)`; nothing changes.
3. When every given value equals the stored value (compared after `toStorage`): change nothing and return the record (its version stays the same, so a repeated save is harmless).
4. Otherwise `UPDATE <t> SET <given columns> = ?, "_updated_at" = ?, "_updated_by" = ? WHERE "id" = ? AND "_trashed_at" IS NULL`, then `ProjectFile::touch`.
5. Return the record read again.

Updates are partial: columns not in `$values` keep their values (ADR 0013 decision 7).

### 3.4 Links

`setLinks(fieldKey, id, targetIds, userKey)` makes the given ids exactly the record's links in that relation field, in the given order:

| Rule | Problem (field = the relation field key) |
|---|---|
| The record exists and is live | `NotFound` |
| 0 to 1,000 ids, each a positive integer (duplicates dropped, first kept) | `\InvalidArgumentException` |
| Each target exists and is live in the other table | `problem.link.target_missing` |
| This side may link to one record only, and more than one id is given | `problem.link.one_only` |
| The other side may link to one record only, and a target already links to another record on this side | `problem.link.target_taken` (`title` = the target's title or "{id}") |

In one `write()`: delete this record's join rows on this side whose other id is not in the list; insert the missing ones; set `position` to 1..n in list order (rows are oriented by the field's side: the left field's record is `left_id`); set the record's `_updated_at` and `_updated_by`; `ProjectFile::touch`. Links to records in the trash are kept as they are and are not part of the list (they reappear when that record is restored, ADR 0010 decision 4); `setLinks` neither adds nor removes them. `get` returns live targets only, ordered by `position`, then the other record's id. Relation fields work from either side and for self-relations (a record may link to itself).

### 3.5 Version token

`RecordVersion::of(array $row, array $links): string`: the first 16 lower-case hexadecimal characters of `hash('sha256', json_encode([$row, $links], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))`, where `$row` is the record's row as SQLite returned it (every column: `id`, the six system columns, and each field column), as a list of `[column name, value]` pairs sorted by column name, and `$links` is a list of `[relation field key, sorted list of every linked id on this record's side, live or trashed]` pairs sorted by field key. The token changes whenever any stored value or link of the record changes, including two saves within one second (ADR 0013 decision 6). It is not a secret and carries no data beyond the hash.

### 3.6 List queries

```php
final class Sort
{
    public function __construct(public readonly string $by, public readonly bool $descending) {}  // field key, 'added', or 'changed'
    public function token(): string;   // '<by>.asc' or '<by>.desc'
}

enum Operator: string { case Is = 'is'; case Empty = 'empty'; case Between = 'between'; case LinksTo = 'links-to'; }

final class Condition
{
    /** @param list<string> $operands */
    public function __construct(public readonly string $fieldKey, public readonly Operator $operator, public readonly array $operands) {}
}

final class ListQuery
{
    public function __construct(public readonly Sort $sort, public readonly ?Condition $filter,
                                public readonly ?string $search, public readonly int $page) {}
}

final class NormalisedQuery
{
    public function __construct(public readonly ListQuery $query,
                                public readonly ?Problem $filterProblem, public readonly ?Problem $searchProblem) {}
}
```

`Records::query()` turns the raw view state into a valid `ListQuery`; it never throws for bad view state:

- **Sort.** `$sort` is a token from `sorts()`; anything else (or null) gives the default: the title field ascending when the table has one whose type is sortable, else `added.asc`. `sorts()` lists, for each sortable field in field order, ascending then descending; then `added.desc`, `added.asc`, `changed.desc`. Sortable: every type except `attachment`; `relation` only when the linked table has a title field.
- **Filter.** With `$filterField` null, no filter. Otherwise the field must belong to the table and be filterable, and the operator and operands must fit:

| Field type | Operator | Operands | SQL condition on the record row `r` |
|---|---|---|---|
| choice | `is` | one current label (exact, as stored) | `<f> = ?` |
| choice, date, date_time | `empty` | none | `<f> IS NULL` |
| checkbox | `is` | `1` or `0` | `<f> = ?` (bound as integer) |
| relation | `links-to` | one positive record id of a live record in the linked table | `EXISTS (SELECT 1 FROM <j> AS "jj" WHERE "jj".<this side> = r."id" AND "jj".<other side> = ?)` |
| relation | `empty` | none | `NOT EXISTS (SELECT 1 FROM <j> AS "jj" JOIN <other> AS "o" ON "o"."id" = "jj".<other side> WHERE "jj".<this side> = r."id" AND "o"."_trashed_at" IS NULL)` |
| date | `between` | `[from, to]`, each a strict date or empty, not both empty | `<f> >= ?` and/or `<f> <= ?` |
| date_time | `between` | the same, dates covering whole days | `<f> >= ?` with `<from>T00:00:00` and/or `<f> <= ?` with `<to>T23:59:59` |

  Any failure drops the filter and sets `filterProblem`: an unknown or unfilterable field or an operator the type does not take, `problem.filter.invalid`; a missing operand, `problem.filter.value`; a bad date, `problem.date.invalid`; `from` after `to`, `problem.filter.range`; a choice label that is not a current label or a record that does not exist or is in the trash, `problem.filter.value`. The problem's field is null.
- **Search.** `$search` is trimmed (`Text::trim`); empty means no search. Invalid UTF-8 or control characters (`Text::hasControl`) give `problem.text.control`; more than 200 characters give `problem.text.too_long` (`max` 200); either drops the search and sets `searchProblem`.
- **Page.** Below 1 becomes 1. The upper clamp happens in `page()`, which knows the count.

### 3.7 Pages and positions

`page()` returns:

```php
final class RecordPage
{
    public function __construct(
        public readonly array $records,    // list<RecordData> of this page, in order
        public readonly int $total,        // live records matching filter and search
        public readonly int $tableTotal,   // live records in the table
        public readonly int $page,         // after clamping to 1..pages
        public readonly int $pages,        // max(1, ceil(total / 50))
        public readonly Sort $sort,
    ) {}
}
```

Query shape: `SELECT r.* FROM <t> AS "r" WHERE r."_trashed_at" IS NULL [AND <filter>] [AND <search>] ORDER BY <order> LIMIT 50 OFFSET ?`, plus a `COUNT(*)` with the same `WHERE`. Links and attachments of the page's records are read with one query per relation or attachment field (`IN (<placeholders>)` over the page's ids).

Order (`<e>` is the sort expression; empty values last in both directions; ties by id ascending):

| Sort | `<order>` |
|---|---|
| text, long_text, url, email, phone | `(<e> IS NULL), <e> COLLATE NOCASE ASC|DESC, r."id" ASC` with `<e>` = `r.<f>` |
| number, currency, rating, duration, auto_number, date, time, date_time, checkbox | `(<e> IS NULL), <e> ASC|DESC, r."id" ASC` |
| choice | `<e>` = `CASE r.<f> WHEN ? THEN ? WHEN ? THEN ? ... ELSE ? END` with each label and its list position, and the label count for `ELSE`, all bound (the scanner admits no number built at run time into SQL text), with `(r.<f> IS NULL)` first |
| relation | `<e>` = `(SELECT MIN("o".<title column>) FROM <j> AS "jj" JOIN <other> AS "o" ON "o"."id" = "jj".<other side> WHERE "jj".<this side> = r."id" AND "o"."_trashed_at" IS NULL)`; when the title type is text-like, `COLLATE NOCASE` both inside `MIN(...)` (so the lowest title is chosen ignoring ASCII case) and on the `ORDER BY` term |
| `added` | `r."id" ASC|DESC` |
| `changed` | `r."_updated_at" DESC, r."id" DESC` |

`position($tableKey, $query, $id)` returns null when the record is not live or does not match the query; otherwise:

```php
final class Position
{
    public function __construct(public readonly int $index, public readonly int $total,   // 1-based, in the list
                                public readonly ?int $previous, public readonly ?int $next) {}
}
```

computed with `ROW_NUMBER() OVER (ORDER BY <order>)` over the same `WHERE` (SQLite 3.25 or later; the floor is 3.35.5).

### 3.8 Search

Searchable fields of a table: `text`, `long_text`, `choice`, `url`, `email`, `phone` columns, and `relation` fields whose linked table's title field has one of those types. For a search text `s`, the pattern is `'%' . escape(s) . '%'` where `escape` puts `\` before each `\`, `%`, and `_`. The condition is the `OR` of `r.<f> LIKE ? ESCAPE '\'` for each searchable column and, for each searchable relation field, `EXISTS (SELECT 1 FROM <j> AS "jj" JOIN <other> AS "o" ON "o"."id" = "jj".<other side> WHERE "jj".<this side> = r."id" AND "o"."_trashed_at" IS NULL AND "o".<title column> LIKE ? ESCAPE '\')`. A table with no searchable field matches nothing. `LIKE` folds ASCII letters only (ADR 0013 decision 5).

`search($text)` (the text already normalised as in 3.6): for each table in table order, the count of live matches and the first 10 matches in the table's default sort:

```php
final class SearchHit
{
    public function __construct(public readonly int $id, public readonly ?string $title,
                                public readonly ?string $fieldKey, public readonly ?string $text) {}
}
final class SearchGroup
{
    public function __construct(public readonly string $tableKey, public readonly int $total,
                                public readonly array $hits) {}   // list<SearchHit>, at most 10
}
```

Groups with no match are left out. For each hit, `fieldKey` and `text` name the first searchable field, in field order and other than the title field, whose canonical text (or, for a relation, one linked title) contains `s` ignoring ASCII case (`stripos`), with that text; both are null when only the title matched.

### 3.9 SQL composition (security-critical)

M1 allowed only literal SQL plus identifiers (`docs/specs/M1-foundation.md` section 3.3). Lists, filters, searches, and sorts need SQL whose shape depends on the schema, so M2 adds one composition class, and `tests/core/schema/SqlStringsTest.php` is extended to cover it (T-REC-14):

```php
final class Sql
{
    /**
     * A fragment of SQL with its bound values. $text must be built only from string literals,
     * Identifier calls, and Database::placeholders() at the call site (enforced by the scanner);
     * it must hold exactly count($params) '?' characters (checked; \InvalidArgumentException).
     */
    public static function of(string $text, array $params = []): self;
    /** Joins fragments with one of ' AND ', ' OR ', ', ', ' ' (any other glue: \InvalidArgumentException). */
    public static function join(string $glue, array $parts): self;   // non-empty list<Sql>
    public function wrap(): self;          // '(' . text . ')'
    public function sql(): string;
    public function params(): array;
}
```

Rules for every file under `src/Core/Query/` and `src/Core/Forms/` (and still under `src/Core/Schema/`): the M1 rules 0 to 6 of `SqlStringsTest`, with these additions: (3a) the first argument of every `Sql::of(` call follows rule 3; (3b) `$v->sql()` is also allowed where the scan shows `$v` holds an `Sql` (a parameter of that type, or a local assigned only from `Sql::of`, `Sql::join`, or `->wrap()`); (3c) the second argument of `->run(`, `->all(`, `->one(`, `->value(` may be `$v->params()` for such a `$v`. `Sql.php` itself is the one file allowed to concatenate a variable into SQL text (inside `join` and `wrap`); the scanner exempts it by name and the design-tier review reads it. Values are always bound. Rule 2 forbids the global functions `implode` and `join`; the static call `Sql::join(` is not that function and is allowed. Choices between literal fragments (`ASC` or `DESC`, `COLLATE NOCASE` or none) are made by choosing between whole `Sql::of('...')` calls (in `if` branches or a ternary whose both arms are `Sql::of` calls with literal text), never by choosing a string that is then concatenated; a local assigned that way counts as assigned only from `Sql::of` for rule 3b.

## 4. View state (`TiffinDB\Core\App\ListRequest`)

```php
final class ListRequest
{
    public const PARAMS = ['q', 'sort', 'filter', 'op', 'v', 'v2', 'page'];

    /** Reads the parameters from a query array; non-string values are ignored; `page` must be digits, 1 to 1,000,000. */
    public static function fromQuery(array $query): self;
    public static function none(): self;
    public function __construct(public readonly ?string $q, public readonly ?string $sort, public readonly ?string $filter,
                                public readonly ?string $op, public readonly ?string $v, public readonly ?string $v2,
                                public readonly int $page) {}
    /** Query parameters for links, in PARAMS order; empty values and page 1 left out. @return array<string, string> */
    public function toQuery(): array;
    public function withPage(int $page): self;
    public function withoutPage(): self;
    public function withoutFilter(): self;
    public function withoutSearchAndFilter(): self;
}
```

`RecordOperations` passes the raw strings to `Records::query()` and returns, in its results, a `ListRequest` rebuilt from the normalised query (invalid parts dropped; the default sort left out), which the web layer uses for every link. The web layer reads `$request->query` only through `ListRequest::fromQuery`.

### 4.1 Problems in view state

A refused filter or search is reported in the listing result (`filterProblem`, `searchProblem`), not thrown; the list still shows (unfiltered or unsearched). This keeps GET requests free of error statuses for bookmarked or edited addresses.

## 5. Forms (`TiffinDB\Core\Forms`)

The format is `docs/formats/tiffin.md` section 12; decisions are in ADR 0014.

### 5.1 Node model

All node classes are `final` with `readonly` properties and a `kind(): string` method, so templates branch on strings and never name a class.

```php
final class Placement { public function __construct(public readonly ?int $col, public readonly ?int $row, public readonly ?string $span) {} }  // span '1'..'12' or 'full'

interface Block { public function kind(): string; public function placement(): ?Placement; }
final class Section implements Block    // kind 'section'
{ public function __construct(public readonly ?string $role, public readonly ?string $legend, public readonly array $children, ?Placement $placement = null) {} }
final class FieldSlot implements Block  // kind 'field'
{ public function __construct(public readonly string $fieldKey, public readonly ?string $label, ?Placement $placement = null) {} }
final class Heading implements Block    // kind 'heading'; level 2 or 3; children list<Inline>
final class Paragraph implements Block  // kind 'paragraph'; children list<Inline>
final class Divider implements Block    // kind 'divider'
final class Image implements Block      // kind 'image'; int $attachmentId, string $alt

interface Inline { public function kind(): string; }
final class TextRun implements Inline   // kind 'text', string $text
final class Strong implements Inline    // kind 'strong', list<Inline> $children
final class Emphasis implements Inline  // kind 'em', list<Inline> $children
final class LineBreak implements Inline // kind 'br'
final class Link implements Inline      // kind 'link', string $href, list<Inline> $children

final class FormDocument { public function __construct(public readonly array $blocks) {} }   // list<Block>
final class FormFormat { public const VERSION = 1; public const MAX_BYTES = 262144; public const MAX_ELEMENTS = 5000; public const MAX_DEPTH = 8; }
```

Constructors validate what the format requires (key pattern, label and legend lengths, levels, placement ranges, role pattern) and throw `\InvalidArgumentException`, so no invalid document can be built in code either.

### 5.2 `FormParser` (security-critical)

```php
final class FormParser
{
    public function __construct(FieldTypes $types) {}          // for the url rule of a href
    /** @throws FormRejected with a reason code (for logs; never the content) */
    public function parse(string $html): FormDocument;
}
final class FormRejected extends \RuntimeException { public function __construct(public readonly string $reason) {} }
```

Algorithm (default deny; the first violation rejects the whole form):

1. Byte length at most 262,144; valid UTF-8 (`Text::isUtf8`); no NUL byte. The text must not contain `<!` other than at the start of a comment `<!--`, nor `<?` (refuses DOCTYPE, entity declarations, CDATA, processing instructions, and the XML declaration before any parser runs).
2. Parse with `XMLReader::XML($html, 'UTF-8', LIBXML_NONET)` with the parser properties `LOADDTD`, `DEFAULTATTRS`, `VALIDATE`, and `SUBST_ENTITIES` all false, inside `libxml_use_internal_errors(true)` (previous setting restored in `finally`, errors cleared). Any libxml error rejects.
3. Walk the nodes. Allowed node types: element, end element, text, whitespace, significant whitespace, comment (skipped). Any other type (CDATA, entity reference, processing instruction, document type, notation) rejects.
4. The root is one `div` whose only attribute is `data-tiffin-form="1"`; nothing but whitespace and comments outside it.
5. Each element's name, place, and attributes follow `docs/formats/tiffin.md` section 12.1 exactly: names compared case-sensitively (lower case only); every attribute must be on the element's list (no namespaces, no `xmlns`); required attributes present; values validated (field key `Keys::isValid`; label 1 to 60 characters, legend 1 to 100, alt 0 to 200, after `Text::trim`, no control characters except that text runs may hold tab and newline; role `^[a-z][a-z0-9-]{0,31}\z`; `data-tiffin-col` and `span` integers 1 to 12 written without sign or leading zero, span also `full`; row 1 to 50; `src` `^attachment:[1-9][0-9]{0,18}\z`; `href` accepted by the `url` field type's strict parse and stored as given).
6. Text that is not only whitespace may appear only inside `legend`, `h2`, `h3`, `p`, `strong`, `em`, and `a`. `legend` holds text only. Empty elements (`div` field slot, `hr`, `br`, `img`) have no children.
7. A field key may appear in at most one slot. At most 5,000 elements and a depth of 8.
8. Build and return the `FormDocument`. Text runs keep their characters; consecutive text and whitespace nodes inside inline content are merged.

`XMLReader` is used because the installer already requires the `xmlreader` extension; no `dom` requirement is added in M2.

### 5.3 `FormSerializer`

`serialize(FormDocument): string` writes the canonical text: the root `<div data-tiffin-form="1">`, each block on its own line indented two spaces per level, inline content on one line as given; attributes in the order `data-tiffin-section`, `data-tiffin-field`, `data-tiffin-label`, `data-tiffin-col`, `data-tiffin-row`, `data-tiffin-span`, `src`, `alt`, `href`; `div` field slots as `<div ...></div>`, `hr`, `br`, `img` self-closed (`<hr/>`); text escaped with `htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`; a final newline. Properties (T-FORM-3): `parse(serialize(d))` equals `d`; `serialize(parse(t))` is a fixed point after one round.

### 5.4 `FormGenerator`

`generate(Schema $schema, string $tableKey): FormDocument` builds the layout of `docs/formats/tiffin.md` section 12.3 from `Schema::fields($tableKey)` in position order: a `details` section (legend `Details`) with every field except attachment fields and relation fields whose `RelationDef::maxFor()` is `many`, with `span` `full` for `long_text` and `rating`; a `files` section (legend `Files`) when the table has attachment fields; one `links` section per many-link relation field, legend = the field's name. The `details` section is written even when empty, so a table with no fields gets a valid form with one empty section.

### 5.5 `FormStore`

```php
final class StoredForm
{
    public function __construct(public readonly string $key, public readonly string $tableKey, public readonly string $name,
                                public readonly int $format, public readonly string $html, public readonly string $css,
                                public readonly bool $isDefault, public readonly bool $generated,
                                public readonly string $createdAt, public readonly string $updatedAt) {}
}

final class FormStore
{
    public const GENERATED_NAME = 'Standard form';
    public function __construct(Database $db, Keys $keys, Clock $clock, FormParser $parser) {}
    public function defaultFor(string $tableKey): ?StoredForm;
    /** The document to show: the default form parsed, or the generated layout when there is none, its format is not 1, or it is rejected. Never writes. */
    public function effective(Schema $schema, string $tableKey): FormDocument;
    /** Brings the default forms of the given tables in line with the schema; must run inside the caller's write(). */
    public function regenerate(Schema $schema, array $tableKeys): void;
}
```

`effective` logs a rejected form with `error_log` naming the table key and the reason code only. `regenerate` (called with `$db->inTransaction()` true, else `\LogicException`), for each key that is still a table in `$schema`:

- no default form: insert one (`key` new, `name` `Standard form`, `format` 1, `html` = the serialized generated document, `css` `''`, `is_default` 1, `generated` 1, both times now);
- default form with `generated` 1: when the serialized generated document differs from `html`, update `html` and `updated_at`;
- default form with `generated` 0: parse it; when rejected, leave it unchanged; otherwise remove slots whose key is not a field of the table, append a slot for each field of the table that no slot names (at the end of the first section, or at the end of the root when there is none), and store the serialized result if it changed.

A `_tiffin_forms` row whose columns do not have the documented types makes `defaultFor`, and so `regenerate`, throw `Unavailable('not-project')` (rolling back the caller's schema change), as M1 treats any other malformed project file; `effective` instead logs reason `row` and shows the generated layout.

Form keys share the key scope of tables, fields, and relations (`docs/formats/tiffin.md` section 4.6): `FormStore` and `SchemaEditor::newKey` both check `_tiffin_tables`, `_tiffin_fields`, `_tiffin_relations`, and `_tiffin_forms` for a candidate key.

### 5.6 Regeneration inside schema changes

`SchemaEditor`'s constructor gains a last optional parameter `?\Closure $afterChange = null`, typed `\Closure(Database, list<string>): void`. Each structural method calls it once, inside its transaction, after its own changes and before `ProjectFile::touch`, with the keys of the tables whose forms may change:

| Method | Table keys passed |
|---|---|
| `createTable` | the new table |
| `deleteTable` | every other table that had a relation to the deleted one (the deleted table's forms go with it by `ON DELETE CASCADE`) |
| `addField`, `changeType`, `moveField`, `updateField` | the field's table, plus the other table for a relation field |
| `addRelation`, `deleteField` of a relation | both tables (one for a self-relation) |
| `deleteField` of another type | the field's table |
| `updateTable` | none (no call) |

An exception from the closure rolls the whole change back. `Core\App\Context::editor()` passes a closure that builds `new Schema($db, $types)` and calls `FormStore::regenerate`.

### 5.7 `FormBinder`

```php
final class LinkItem { public function __construct(public readonly string $tableKey, public readonly int $id, public readonly ?string $title) {} }
final class FileItem { public function __construct(public readonly string $name, public readonly string $mediaType, public readonly int $size) {} }

final class BindData
{
    public function __construct(
        public readonly array $values = [],     // field key => canonical value
        public readonly array $submitted = [],  // field key => text typed (shown instead of the value)
        public readonly array $problems = [],   // field key => list<Problem>
        public readonly array $links = [],      // relation field key => list<LinkItem>
        public readonly array $files = [],      // attachment field key => list<FileItem>
        public readonly bool $isNew = false,
    ) {}
}

final class FormBinder
{
    public function bind(FormDocument $form, string $tableKey, array $fields, FieldTypes $types, BindData $data): BoundForm;
}
```

`$fields` is `Schema::fields($tableKey)`. The result:

| Node (`kind()`) | Properties |
|---|---|
| `section` | `role` (?string), `legend` (?string), `children` (list of nodes), `grid` (bool: role is `details`), `spanFull` (bool) |
| `field` | `field` (FieldDef), `label` (slot label or field name), `inputText` (string: the submitted text when given, else `''` for an empty value, else `toText(value)`), `submitted` (bool: `inputText` came from `BindData::$submitted`), `value` (canonical or null), `problems` (list<Problem>), `editable` (bool: the type is editable and is not relation or attachment), `links` (list<LinkItem>), `files` (list<FileItem>), `spanFull` (bool), `labelHidden` (bool: the slot is the only child of a section with role `links`), `isNew` (bool) |
| `heading` | `level`, `children` (the document's inline nodes) |
| `paragraph` | `children` |
| `divider` | none |

Slots naming a key that is not in `$fields` are dropped; images are dropped in M2. `BoundForm` has `nodes` (list), `editableKeys(): list<string>` (field keys of editable bound fields in document order: exactly the inputs the record form posts), and `hasGrid(): bool`.

## 6. Project format version 2 in code

- `ProjectFile::VERSION` = 2. `create` runs the DDL of `docs/formats/tiffin.md` section 4, both SQL blocks, in that order, and writes `format_version` 2 and `PRAGMA user_version = 2`. The DDL constant is copied verbatim from the document (T-SCH-1 compares it).
- `FormatMigrations::STEPS[2]` holds the three statements of `docs/formats/tiffin.md` section 11.1 (`CREATE TABLE _tiffin_forms ...` and the two indexes), and `VERSION_PRAGMAS[2]` = `'PRAGMA user_version = 2'`. The existing upgrade mechanism takes the `format-upgrade` snapshot, runs the step in its own transaction, and sets `format_version`.
- A version 1 file opened by M2 is therefore upgraded on first open (any request that opens the project, including a GET, as in M1's design); a failure leaves the file at version 1 with the snapshot, and the request gets the M1 `Unavailable` handling only if the file cannot then be opened.
- No forms are written by the upgrade (ADR 0014 decision 2). `FormStore::effective` shows the generated layout until the table's next structural change.

## 7. Core operations (`TiffinDB\Core\App`)

### 7.1 Entry point

`Operations` gains `records(): RecordOperations` and `settings(): SettingsOperations`; the constructor is unchanged (T-REPO for the six-argument composition seam, P78, still holds). `Context` gains `records(ProjectRecord, Database, Schema): Records` and `forms(Database): FormStore` builders, and `editor()` passes the after-change hook of section 5.6. Record operations open the project through the existing `ProjectOpener`, build one `Schema`, and use one `Records` per call.

Every method takes `Actor $actor` first and starts with the permission check (`Context::project` for project-scoped permissions). A table key that is not a table of the project, an invalid key, or a record id that is not `^[1-9][0-9]{0,18}\z` (record ids arrive as strings from URLs) gives `NotFound`, as do absent records. No record operation writes an audit entry (ADR 0012 decision 4: record-level edits are not logged).

### 7.2 `RecordOperations`

| Method (after `Actor $actor, string $projectKey`) | Permission | Returns or does |
|---|---|---|
| `listing(string $tableKey, ListRequest $request)` | `records.view` | `RecordListing` (below) |
| `filterOptions(string $tableKey, ?string $fieldKey)` | `records.view` | `FilterOptions { TableDef $table; list<FieldDef> $fields (filterable, in order); ?FieldDef $field (when $fieldKey names one of them); list<string> $choices; list<LinkItem> $records (Records::pickList, up to 500); bool $more; list<TableSummary> $tables }` |
| `blank(string $tableKey, ListRequest $request)` | `records.edit` | `RecordScreen` for a new record (`isNew` true, empty values) |
| `screen(string $tableKey, string $recordId, ListRequest $request)` | `records.view` | `RecordScreen`; for a record in the trash, `trashed` true and no form |
| `redisplay(string $tableKey, ?string $recordId, array $input, array $problems, ListRequest $request)` | `records.edit` | `RecordScreen` showing `$input` (field key => text) and `$problems` (from `ValidationFailed`), for the 422 page |
| `create(string $tableKey, array $input)` | `records.edit` | `int` new id. `$input`: field key => text, `InputMode::Form` |
| `update(string $tableKey, string $recordId, array $input, string $version)` | `records.edit` | void; `Conflict` on a version mismatch; `NotFound` for a record in the trash |
| `conflict(string $tableKey, string $recordId, array $input, ListRequest $request)` | `records.edit` | `ConflictView` (below) |
| `trash(string $tableKey, string $recordId)` | `records.trash` | `RecordRef { int $id; ?string $title }` (title before the move); uses `Trash::moveToTrash` |
| `restore(string $tableKey, string $recordId)` | `records.trash` | `RecordRef`; uses `Trash::restore` |
| `setLinks(string $tableKey, string $recordId, string $fieldKey, list<int> $targetIds)` | `records.edit` | void (core only in M2; used by tests and later by M4 and the MCP adapter) |
| `search(string $q)` | `records.view` | `SearchResults` (below) |

Result objects (all `final`, `readonly` public properties):

```php
final class RecordListing
{
    public readonly ProjectRecord $project;  public readonly TableDef $table;
    public readonly array $tables;           // list<TableSummary> (M1), for the sidebar
    public readonly int $trashCount;         // records in the trash in the whole project
    public readonly array $columns;          // list<FieldDef>: the title field (when set), then up to 6 more
                                             // fields in field order, skipping long_text; 7 at most
    public readonly array $rows;             // list<ListRow>
    public readonly int $total; public readonly int $tableTotal; public readonly int $page; public readonly int $pages;
    public readonly int $from; public readonly int $to;   // 1-based range shown; 0 and 0 when empty
    public readonly array $sortOptions;      // list<SortOption>
    public readonly string $sort;            // the effective sort token
    public readonly ?ActiveFilter $filter;
    public readonly ?Problem $filterProblem; public readonly ?Problem $searchProblem;
    public readonly ?string $search;         // the effective search text
    public readonly ListRequest $request;    // normalised view state for links
    public readonly bool $hasFields;         // the table has at least one field
    public readonly bool $hasFilterable;     // at least one filterable field
    public readonly bool $canChangeSchema;   // Authorizer::can(schema.change), for the no-fields empty state
}
final class ListRow { public readonly int $id; public readonly ?string $title; public readonly array $cells; }   // cells: field key => Cell
final class Cell { public readonly FieldDef $field; public readonly mixed $value; public readonly array $links; // list<LinkItem>, at most 3
                   public readonly int $moreLinks; public readonly int $fileCount; }
final class SortOption { public readonly string $token; public readonly ?FieldDef $field; public readonly string $kind; // 'field'|'added'|'changed'
                         public readonly bool $descending; }
final class ActiveFilter { public readonly FieldDef $field; public readonly string $operator; public readonly array $operands;
                           public readonly ?string $linkTitle; }   // title of the linked record for links-to

final class RecordScreen
{
    public readonly ProjectRecord $project; public readonly TableDef $table;
    public readonly ?int $id; public readonly bool $isNew; public readonly bool $trashed; public readonly ?string $trashedAt;
    public readonly ?string $title;          // title text, or null (the web layer shows "{Item} {id}")
    public readonly ?string $version;
    public readonly ?BoundForm $form;        // null when trashed
    public readonly ?RecordPosition $position;
    public readonly ?string $createdAt; public readonly ?string $createdByName;
    public readonly ?string $updatedAt; public readonly ?string $updatedByName;
    public readonly ListRequest $request;
}
final class RecordPosition { public readonly int $index; public readonly int $total;
                             public readonly ?int $previousId; public readonly ?string $previousTitle;
                             public readonly ?int $nextId; public readonly ?string $nextTitle; }
final class ConflictView { public readonly RecordScreen $screen;   // the saved record, with its current version
                           public readonly array $rows; }          // list<ConflictRow>
final class ConflictRow { public readonly FieldDef $field; public readonly string $label;
                          public readonly mixed $saved; public readonly string $yours; }
final class SearchResults { public readonly ProjectRecord $project; public readonly ?string $search; public readonly ?Problem $problem;
                            public readonly int $total; public readonly array $groups;   // list<SearchResultGroup>
                            public readonly array $tables; public readonly int $trashCount; }
final class SearchResultGroup { public readonly TableDef $table; public readonly int $total; public readonly array $hits; }  // list<SearchResultHit>
final class SearchResultHit { public readonly int $id; public readonly ?string $title; public readonly ?FieldDef $field; public readonly ?string $text; }
```

Behaviour notes:

- `listing` builds the query with `Records::query` from the request's raw strings, then `page`; the title of a row is the title field's canonical text (null when no title field or empty). Relation cells carry at most three linked titles and the count of the rest; attachment cells the file count.
- `screen` and `redisplay` use `FormStore::effective`, then `FormBinder::bind` with the record's values, links (as `LinkItem`s), and files (as `FileItem`s). Display names come from `Users::byKey` (system database), null when the person is unknown. Position: `Records::query` from the request, then `Records::position`; titles of the neighbours via `Records::titles`.
- `update` reads `$input` only for the keys the caller passes; the web layer passes exactly `BoundForm::editableKeys()`, with an unticked checkbox as `''`.
- `conflict` reads the saved record and, for each key of `$input` whose field still exists and is editable, compares the stored value with `$input` parsed strictly: a row is listed when the parse fails or the canonical values differ.
- `search` normalises `$q` as `Records::query` does; with a problem or empty text it returns no groups.

### 7.3 `SettingsOperations`

| Method | Permission | Behaviour |
|---|---|---|
| `get(Actor)` | signed in | `array<string, string|int>`: `Users::settings` for the actor (M1 section 5.5 columns) |
| `save(Actor, array<string, string> $values)` | signed in | Inside one system-database `write()`: `Context::requireCurrentPerson`; each of `theme`, `accent`, `typeface`, `text_size`, `line_spacing` must be one of its allowed values (`Users::SETTINGS`), else problem (field = the column name, `problem.options.invalid`); `reduce_motion` is `'1'` for 1, missing or `''` for 0, anything else a problem; all problems together; then `Users::saveSettings($userId, $settings)` |
| `reset(Actor)` | signed in | `save` with the defaults (`light`, `teal`, `inter`, `standard`, `standard`, 0) |

`Users::saveSettings(int $id, array $settings): void` (repository, P51: no transaction of its own, refuses to run outside one as M1 repositories do) writes the six columns with `INSERT INTO user_settings (...) VALUES (...) ON CONFLICT (user_id) DO UPDATE SET ...` (SQLite 3.24 or later). Not audited (not a security event). The next request's root classes come from the saved row (M1 `Appearance::fromSettings`).

### 7.4 Permissions

No new `Permission` case. M2 uses `records.view` (list, record screen, filter options, search), `records.edit` (new, create, update, conflict, links), `records.trash` (move to trash, restore), and `schema.change` only through `Authorizer::can` for the empty-state link. Admins hold all; a Member with a grant holds the three record permissions; a Member without a grant gets `NotFound` from every method (T-APP-74).

### 7.5 Confirmation bound to the count (P82 fix, M2-WP8)

`SchemaEditor::updateField` and `changeType`, and `SchemaOperations::updateField` and `changeFieldType`, replace `bool $confirmLoss` with `int $acceptedLoss` (the number of values the person was shown and accepted; 0 when nothing was shown). Inside the transaction, when the change would clear more than `$acceptedLoss` values, the method throws `ConfirmationRequired` with the real counts and changes nothing; otherwise it proceeds. The web confirmation page posts the count it displayed as `confirm` (an integer; anything else is 0). Existing callers and tests that passed `true` pass the count they showed.

## 8. Message keys produced by the core (new)

English text in `strings/en/problems.php` (owned by M2-WP5), exactly:

| Key | Text |
|---|---|
| `problem.value.required` | Fill this in. |
| `problem.value.unknown_field` | This field is not part of this table. |
| `problem.link.target_missing` | Choose a record that still exists. |
| `problem.link.one_only` | Only one record can be linked here. |
| `problem.link.target_taken` | {title} is already linked to another record, and it can only be linked once. |
| `problem.filter.invalid` | That filter cannot be used with this field. |
| `problem.filter.value` | Choose a value for the filter. |
| `problem.filter.range` | The start of the range must be on or before the end. |

M1 keys reused: `problem.value.unsupported`, `problem.auto_number.read_only`, `problem.text.control`, `problem.text.too_long`, `problem.date.invalid`, `problem.options.invalid`, and every field-type key. T-WEB-19 (`StringsTest`) is extended to compare `problems.php` with the M1 table and this one.

## 9. Warning token correction (ui-design-plan section 9 item 4)

Done in this design (commit of M2-D1), in both token files: light-theme `--warn` changes from `#7A4300` to `#703D00`, the same hue darkened. Computed with the suite's own calculator (`tests/Support/Contrast.php`, `CssTokens::resolve`):

| Pair (light theme, any accent) | Before | After |
|---|---|---|
| Warning text on warning tint (`--warn` on `--warn-soft`) | 6.81 | 7.61 |
| Warning text on page ground (`--warn` on `--bg`; error text) | 7.29 | 8.14 |
| Warning text on surface (`--warn` on `--surface`; error text, danger buttons) | 7.98 | 8.91 |
| Warning text on inset areas (`--warn` on `--sunk`) | 6.62 | 7.39 |
| Invalid control border on surface (3:1 needed) | 7.98 | 8.91 |

`--warn` is in no other published pair, and accents do not override it, so every other figure of ui-design-plan section 4.2 is unchanged; the dark and high-contrast themes are untouched. Lightening `--warn-soft` instead was not chosen because it would also lower the warning tint's separation from `--surface` and `--bg`, and would leave error text on inset areas below 7:1. ui-design-plan sections 4.2 and 9 were updated, and section 4.5 now publishes every pair in all ten combinations. `tests/design/ContrastTest.php` asserts 7.61 for the light row and no longer carries the known-shortfall note. The mock-ups keep the old value; they are reference images.

## 10. Test plan

All tests under `tests/`, run by `composer test`, offline and deterministic, with `TempDir`, `FixedClock`, `SeededRandom` (seed written in each test), and synthetic data only. Core tests build projects with `CoreFixture` (M1) and the M2 helpers it gains (WP4: `CoreFixture::addRecord(project, table, array $text): int` through `RecordOperations::create`).

### 10.1 Records (`tests/core/query/`, WP2)

| ID | Asserts |
|---|---|
| T-REC-1 | `create` in `Form` mode for every editable type stores the canonical value through `toStorage` (read back with plain SQL); fields not given are null (checkbox 0); `_created_*` and `_updated_*` set; project meta touched |
| T-REC-2 | Validation collects every problem at once: unknown key, relation and attachment keys, bad values per type with their keys, required fields missing on create (given or not), required given empty on update, auto-number in `Form` mode refused; nothing written |
| T-REC-3 | `Import` mode uses lenient parsing (a number with extra decimals, a date-time as a date); `Canonical` mode uses `check` |
| T-REC-4 | Auto-number: three creates give 1, 2, 3 after a field with `start` 1; a row inserted by hand with number 10 makes the next create 11; `options.next` advanced; two `Records` instances on separate connections creating at once get distinct numbers or `Busy` |
| T-REC-5 | `update` is partial (fields not given unchanged); equal values change nothing (version and `_updated_at` unchanged); a stale version throws `Conflict` with the current version and changes nothing; a trashed record gives `NotFound` |
| T-REC-6 | Version token: 16 hex characters; stable across reads; changes on any column change, on a link added or removed, and on two updates within the same second (`FixedClock` not advanced); the documented construction reproduced in the test gives the same token |
| T-REC-7 | `setLinks`: replaces and orders links from the left and the right side and on a self-relation; `one_only`, `target_missing` (absent and trashed), `target_taken` problems; links to trashed records kept and hidden; `get` shows live targets in position order |
| T-REC-8 | Sort: for each sortable type, ascending and descending orders over seeded values including empties (always last) and ties (by id); choice in list order; relation by lowest linked title, empties last; `added`, `changed`; unknown token gives the default; `sorts()` lists the menu order of section 3.6 |
| T-REC-9 | Filter: every row of the section 3.6 table returns exactly the expected ids; each failure case gives its problem and no filter |
| T-REC-10 | Search: substring in each searchable type and in linked titles; ASCII case-insensitive; `%`, `_`, and `\` match literally; trashed records never found; a table without searchable fields matches nothing; length and control-character problems |
| T-REC-11 | `page`: 120 records give pages of 50, 50, 20; page 9 clamps to 3; `total` and `tableTotal` with a filter; links and attachments loaded for the page |
| T-REC-12 | `position`: index, total, previous, next at the start, middle, and end, under a sort, filter, and search; null for a record outside the query or trashed |
| T-REC-13 | Cross-table `search`: groups in table order, at most 10 hits each with the right `total`, matched field other than the title, null field when only the title matched |
| T-REC-14 | `SqlStringsTest` extended (section 3.9): covers every file in `src/Core/Query/` and `src/Core/Forms/`; negative cases for `Sql::of` with a variable, a `->sql()` on a non-`Sql` variable, a `->params()` of another object, and a disallowed glue; `Sql::of` refuses a `?` count mismatch |
| T-REC-15 | Names from people never reach SQL: records and searches in tables and fields named `"; DROP TABLE x; --` and `%_\` work, and the schema afterwards matches the M1 T-SCH-5 pattern |

### 10.2 Forms (`tests/core/forms/`, WP3)

| ID | Asserts |
|---|---|
| T-FORM-1 | Node constructors refuse invalid keys, labels, legends, levels, roles, and placements |
| T-FORM-2 | `FormParser` accepts the example of `docs/formats/tiffin.md` section 12.2 (read from the document) and a document using every element and attribute of section 12.1 |
| T-FORM-3 | `parse(serialize(d)) == d` for 200 seeded documents; the serializer output for the generated example equals the document's example text after normalising leading spaces |
| T-FORM-4 | Rejection corpus, each with its reason code: DOCTYPE with internal entity (billion laughs), external entity, `<?xml` declaration, processing instruction, CDATA, undefined entity, `script`, `style`, `iframe`, `form`, `input`, `on*` attribute, `style` attribute, `class`, `id`, `xmlns` and prefixed attributes, upper-case element names, unknown `data-` attribute, `href="javascript:alert(1)"`, `href` with tab or `//host`, `img src="https://..."`, `src="attachment:0"`, duplicate field slot, nested `fieldset`, text directly in a `fieldset`, more than 8 levels, more than 5,000 elements, 262,145 bytes, invalid UTF-8, NUL, a root with a second attribute, two roots, wrong format number |
| T-FORM-5 | `FormGenerator`: the rules of section 5.4 for a table with every type, a one-link and a many-link relation, attachments, no fields; field order follows positions |
| T-FORM-6 | `FormStore::effective`: stored form used when valid; generated layout when missing, rejected, or `format` 2; nothing written; a rejected form is logged with its reason code and no content |
| T-FORM-7 | `regenerate`: inserts a missing default form; rewrites a generated form after a field is added, moved, renamed (relation legend), retyped to long text, and deleted; edits a `generated` 0 form by appending and removing slots only; leaves a rejected `generated` 0 form alone; refuses to run outside a transaction |
| T-FORM-8 | Hook: each `SchemaEditor` method of section 5.6 calls the closure once inside its transaction with exactly the listed table keys; an exception from the closure rolls the schema change back (file unchanged) |
| T-FORM-9 | `FormBinder`: input text from submitted, from value, empty; problems attached to their fields; slots for unknown keys and images dropped; `editableKeys` order; `labelHidden` for a lone slot in a `links` section; relation and attachment fields carry links and files and are not editable |
| T-FORM-10 | Form keys share the scope: with `SeededRandom` forced to collide, a new field key never equals an existing form key and vice versa |

### 10.3 Format (`tests/core/schema/`, WP3)

| ID | Asserts |
|---|---|
| T-SCH-1 (updated) | `ProjectFile::create` writes version 2; the `sqlite_master` SQL of every `_tiffin_*` object equals the statements of the first and second SQL blocks of `docs/formats/tiffin.md` section 4, white space collapsed |
| T-FMT-1 | The statements of section 11.1, read from the document: the first three (the `CREATE TABLE` and two `CREATE INDEX`) equal `FormatMigrations::STEPS[2]`, the fourth equals `VERSION_PRAGMAS[2]`, and the fifth is the meta update the upgrade mechanism runs (white space and identifier quotes normalised) |
| T-FMT-2 | A version 1 file built from the first SQL block (with a table, fields, records, a relation, and trashed rows) opened by `ProjectFile::open`: a `format-upgrade` snapshot exists and opens at version 1; the file is version 2 with `_tiffin_forms` empty; every record and link unchanged; `PRAGMA integrity_check` and `foreign_key_check` pass; a second open takes no snapshot |
| T-FMT-3 | A failing step 2 (test seam) leaves the file at version 1 with no `_tiffin_forms` |

### 10.4 Core operations (`tests/core/app/`, WP4)

| ID | Asserts |
|---|---|
| T-APP-70 | A granted Member creates a record through `RecordOperations::create` with text input, sees it in `listing` (title, cells, counts, sidebar tables, trash count), opens `screen`, updates it with the version, and the list shows the change |
| T-APP-71 | `listing` with each sort token, a filter (valid and invalid: problem reported, list unfiltered), a search (valid and too long), and page clamping; the returned `ListRequest` drops invalid parts; columns rule (title first, long text skipped, 7 at most) |
| T-APP-72 | `screen`: bound form from the stored form, from the generated layout when missing, and from the generated layout when the stored form is rejected; position and neighbour titles under a list request; trashed record gives `trashed` true and no form; display names, unknown person gives null |
| T-APP-73 | `update` with a stale version throws `Conflict`; `conflict` lists exactly the differing fields among those posted, including a value that fails to parse; `redisplay` keeps the typed text and problems |
| T-APP-74 | Every `RecordOperations` method: Admin allowed; granted Member allowed; ungranted Member `NotFound`; invalid table key, table of another project, record id `0`, `-1`, `1e3`, `x`, 20 digits, and an absent id give `NotFound` (data provider over all methods) |
| T-APP-75 | `trash` and `restore` return the title and move the record; repeating either changes nothing |
| T-APP-76 | `search` across tables for a Member sees only the granted project; results as T-REC-13 through the operation |
| T-APP-77 | Schema changes through `SchemaOperations` regenerate forms in the same transaction (table created gets a stored default form; a field added appears in the stored HTML); `Operations` still has exactly six required constructor arguments |
| T-APP-78 | `SettingsOperations`: save every allowed value; invalid values give all problems at once and change nothing; `reduce_motion` rules; `reset`; a deactivated actor refused (`requireCurrentPerson`); `Users::saveSettings` refuses to run outside a transaction; no audit entry |
| T-APP-79 | No record operation writes to `audit_log` (row count unchanged across a create, update, trash, restore, link change) |
| T-APP-80 | P82 (WP8): `updateField` and `changeFieldType` with `acceptedLoss` equal to the shown count succeed; with more values to clear than accepted (a record changed between the preview and the change, simulated on a second connection) throw `ConfirmationRequired` with the new counts and change nothing |

### 10.5 Carried-forward items (WP0)

| ID | Asserts |
|---|---|
| T-CF-1 | `Request` reads `HTTP_ORIGIN` into a new `origin` property (a string of at most 2,048 printable ASCII characters, else null). `Kernel`: a POST with no `Sec-Fetch-Site` whose `Origin` is present and is not the request's own origin (`http://` or `https://` from `Request::secure`, then `Request::host`, default ports 80 and 443 omitted on both sides, compared ignoring ASCII case) is refused with the M1 CSRF 403 page before the CSRF check; `Origin: null` is refused; a matching `Origin` goes on to the CSRF check; a POST with neither header goes on to the CSRF check; with `Sec-Fetch-Site` present the M1 rules apply and `Origin` is not consulted; GET and HEAD are never checked |
| T-CF-2 | `UrlType`: the hexadecimal-host rule looks at the last label only and needs hexadecimal digits after `0x`: `https://0xford.example/` accepted; `https://0x7f000001/` and `https://a.0x7f/` refused; every M1 numeric-host case (T-TYP-7, P72) still refused |
| T-CF-3 | `SetupController::admin`: when `createFirstAdmin` throws because the first Admin already exists, the response is 303 to `/sign-in` (no 500) |
| T-CF-4 | `Fs::entries` (names in a directory, sorted, without `.` and `..`, never following links, `\RuntimeException` for a missing directory) and the stat helpers (`isFile`, `isDir`, `size`, each not following links); `ProjectRegistry::abandoned(string $before)` returns `creating` rows older than the given time; `ProjectOperations` step 0 of project creation uses them, with no direct file-system call or literal system-database SQL left in it (P78) and T-APP-10 unchanged |

### 10.6 Design (`tests/design/`, WP1)

| ID | Asserts |
|---|---|
| T-DES-7 | `ContrastTest` extended: every cell of ui-design-plan section 4.5 (100 values) computed from `public/assets/css/tokens.css` for its combination and compared exactly; the expected figures are read from the published table (as the M0 test reads the Light column), so the test and the document cannot drift; every text pair is at least 7.00 and the control border at least 3.00 in all ten combinations; the high-contrast combinations are computed without an accent class |

Web tests (T-WEB-24 to T-WEB-32, T-SCR-2, T-KBD-1, T-E2E-5 to T-E2E-7) are in part 2 section 12.

## 11. Carried-forward items

| Item (ledger section 7) | Where in M2 |
|---|---|
| Plural strings ("1 values in 1 records") | WP5 adds the plural placeholder to `Strings` (part 2 section 6.2); WP8 rewrites the schema confirmation strings with it |
| `Fs::entries` and stat helpers, `ProjectRegistry::abandoned` | WP0 (T-CF-4) |
| P82: confirmation bound to the count | WP8 (section 7.5, T-APP-80) |
| `Origin` check when `Sec-Fetch-Site` is absent (M1 exit audit F-2) | WP0 (T-CF-1) |
| Hex-host rule in `UrlType` applies to any label starting `0x` | WP0 (T-CF-2) |
| Losing first-Admin POST gives 500 | WP0 (T-CF-3) |
| Email values escaped; `mailto:` local parts `rawurlencode`d; attributes escaped with `ENT_QUOTES` | WP5 (part 2 section 5.2, T-WEB-27) |
| `HtmlAssertions::assertForm` should exempt controls named by `aria-label` | WP5 |
| "Temporary" breaks mid-word at 320 px Largest (add-person page) | WP5 (`app.css`: `overflow-wrap: break-word` instead of `anywhere` on that label, or `hyphens: manual`; checked in V-14) |
| `Text::trim` removes U+180E and U+0085 at value ends | Noted for WP2 (search text normalisation uses `Text::trim`; no change) |
| Others in ledger section 7 not listed here | Stay carried forward (not M2 scope) |

## 12. Work packages

Tiers per orchestration guide section 2. "Owned files" may be created or changed only by that package; packages that can run at the same time own disjoint files (checked in the table at the end of this section). A package may add private helpers inside its own directories and must report them. Every package starts with `git merge --ff-only <assigned branch>`, runs `composer test` and `composer lint` before committing and before reporting, and may not weaken an existing test. Tests owned by a later package may not exist yet. WP0 and WP1 have no dependants; they only have to merge before the exit audit.

Waves (at most three agents at once; the M3 design agent holds one slot from wave 1 until its review merges, session plan section 4):

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP2, WP3, then WP5, WP0, WP1 as slots free | M2-R1 merged |
| 2 | WP4 | WP2 and WP3 merged |
| 3 | WP6, WP7, WP8 | WP4 and WP5 merged |
| 4 | WP9 | WP6, WP7, WP8 merged |

### M2-WP0: Carried-forward items

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (request origin check, URL validation, installer) |
| Owned files | `src/Web/Kernel.php`, `src/Web/Request.php`, `tests/web/GateTest.php`, `tests/web/RequestTest.php`, `src/Core/Schema/Type/UrlType.php`, `tests/core/types/UrlHostTest.php` (new), `src/Web/Controller/SetupController.php`, `tests/web/SetupScreenTest.php`, `src/Core/Storage/Fs.php`, `tests/core/storage/FsEntriesTest.php` (new), `src/Core/System/ProjectRegistry.php`, `tests/core/system/ProjectRegistryAbandonedTest.php` (new), `src/Core/App/ProjectOperations.php` |
| Scope | Section 11 rows for WP0. `Origin` rule: only when `secFetchSite` is null and the request is a POST; `Origin` compared with `scheme://host[:port]` built from `Request::secure` and `Request::host` (default ports omitted on both sides); behind a proxy that rewrites the host the check can refuse legitimate requests, so the 403 page is the M1 CSRF page and the residual risk is recorded |
| Acceptance | T-CF-1 to T-CF-4 pass; M1 tests unchanged except `GateTest` additions; design-tier security review |

### M2-WP1: Contrast tests for all combinations

| | |
|---|---|
| Tier | mechanical (exemplar: the existing `ContrastTest`; exact expected values: ui-design-plan section 4.5) |
| Depends on | none (the token value change is already made by M2-D1) |
| Security-critical | No |
| Owned files | `tests/design/ContrastTest.php` |
| Scope | T-DES-7 |
| Acceptance | T-DES-7 passes; the existing contrast assertions still pass; reviewed by an implementation-tier agent |

### M2-WP2: Record layer

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (SQL composition from identifiers; the `Sql` class) |
| Owned files | `src/Core/Query/*` except `Trash.php`; `src/Core/Error/Conflict.php`; `tests/core/query/*` except `TrashTest.php`; `tests/core/schema/SqlStringsTest.php` |
| Scope | Section 3 (all), section 8 keys (keys only; the strings file is WP5's) |
| Acceptance | T-REC-1 to T-REC-15 pass; T-SCH and T-TRASH unchanged; design-tier review of `Sql.php`, every SQL string, and the scanner changes |

### M2-WP3: Forms and project format version 2

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (the parser is the only gate between stored form text and pages) |
| Owned files | `src/Core/Forms/*`; `src/Core/Schema/ProjectFile.php`, `src/Core/Schema/FormatMigrations.php`, `src/Core/Schema/SchemaEditor.php`; `tests/core/forms/*`; `tests/core/schema/ProjectFileTest.php`, `tests/core/schema/FormatUpgradeTest.php` (new); `tests/repo/CoreLayerRulesTest.php` |
| Scope | Sections 2.1, 5, 6. SQL in `src/Core/Forms/` uses literal statements only (rule 3 of section 3.9), so the extended scanner of WP2 passes over it once both are merged |
| Acceptance | T-FORM-1 to T-FORM-10, T-SCH-1 (updated), T-FMT-1 to T-FMT-3 pass; T-REPO-9 with the new rows; existing schema tests pass; design-tier security review of `FormParser` |

### M2-WP4: Core operations

| | |
|---|---|
| Tier | implementation |
| Depends on | WP2, WP3 |
| Security-critical | Yes (authorization boundary for records) |
| Owned files | `src/Core/App/*` new files of section 2, `src/Core/App/Operations.php`, `src/Core/App/Context.php`; `src/Core/Auth/Users.php`; `tests/core/app/Record*Test.php`, `tests/core/app/SettingsOperationsTest.php`, `tests/core/app/FormRegenerationTest.php` (new); `tests/Support/CoreFixture.php` |
| Scope | Sections 4, 7.1 to 7.4 |
| Acceptance | T-APP-70 to T-APP-79 pass; M1 T-APP tests unchanged; design-tier security review |

### M2-WP5: Web components, shell, strings infrastructure, stylesheet, script

| | |
|---|---|
| Tier | implementation |
| Depends on | none (renders through `View` with arrays and `FieldDef` objects; no `Core\App` call) |
| Security-critical | Yes (templates that emit every field type's value; the template rules; the script) |
| Owned files | Part 2 section 13, WP5 row |
| Scope | Part 2 sections 3 to 8 and `docs/design/m2/components.md`, `states.md` sections 1 and 4, `list-and-search.md` sections 5 and 6, `settings.md` section 3 |
| Acceptance | Part 2 section 13, WP5 row |

### M2-WP6: List view, filter, search results

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4, WP5 |
| Security-critical | Yes (templates; view state from the query string) |
| Owned files | Part 2 section 13, WP6 row |
| Scope | `docs/design/m2/list-and-search.md` sections 1 to 4 |
| Acceptance | Part 2 section 13, WP6 row |

### M2-WP7: Record screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4, WP5 |
| Security-critical | Yes (templates rendering stored forms; record writes) |
| Owned files | Part 2 section 13, WP7 row |
| Scope | `docs/design/m2/record.md` |
| Acceptance | Part 2 section 13, WP7 row |

### M2-WP8: Display settings and the bound schema confirmation

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4, WP5 |
| Security-critical | Yes (templates; schema confirmation) |
| Owned files | Part 2 section 13, WP8 row; and `src/Core/Schema/SchemaEditor.php`, `src/Core/App/SchemaOperations.php`, `tests/core/app/ConfirmationCountTest.php` (new), `tests/core/schema/UpdateFieldTest.php`, `tests/core/schema/ChangeTypeTest.php`; and, only to change the last argument of `updateField`, `changeType`, or `changeFieldType` calls from `bool` to the accepted count, `tests/core/app/SchemaOperationsTest.php`, `tests/core/app/AuditTrailTest.php`, `tests/core/schema/MetaTouchTest.php`, and any test under `tests/core/forms/`, `tests/core/app/`, or `tests/Support/` written by WP3 or WP4 that calls them (all merged before wave 3; with `strict_types`, a `bool` passed to the new `int` parameter is a `TypeError`, so every caller must change in the same commit) |
| Scope | `docs/design/m2/settings.md` sections 1, 2, 4; section 7.5 of this part |
| Acceptance | Part 2 section 13, WP8 row; T-APP-80 passes |

### M2-WP9: End-to-end, structure, keyboard, and visual checks

| | |
|---|---|
| Tier | implementation |
| Depends on | WP6, WP7, WP8 |
| Security-critical | No |
| Owned files | Part 2 section 13, WP9 row |
| Scope | Part 2 sections 10 to 12: T-E2E-5 to T-E2E-7, T-SCR-2, T-KBD-1, the scripted keyboard run K-1, and the visual procedure V-9 to V-14 (screenshots in the scratchpad) |
| Acceptance | Tests pass; a report listing K-1 steps and results and every visual deviation per screen and width; deviations are fixed by the owning package before the exit audit |

### Summary

| ID | Title | Tier | Depends on | Security-critical | Wave |
|---|---|---|---|---|---|
| M2-WP0 | Carried-forward items | implementation | none | Yes | 1 |
| M2-WP1 | Contrast tests for all combinations | mechanical | none | No | 1 |
| M2-WP2 | Record layer | implementation | none | Yes | 1 |
| M2-WP3 | Forms and project format version 2 | implementation | none | Yes | 1 |
| M2-WP4 | Core operations | implementation | WP2, WP3 | Yes | 2 |
| M2-WP5 | Web components, shell, strings, stylesheet, script | implementation | none | Yes | 1 |
| M2-WP6 | List view, filter, search results | implementation | WP4, WP5 | Yes | 3 |
| M2-WP7 | Record screens | implementation | WP4, WP5 | Yes | 3 |
| M2-WP8 | Display settings and bound schema confirmation | implementation | WP4, WP5 | Yes | 3 |
| M2-WP9 | End-to-end, structure, keyboard, visual | implementation | WP6, WP7, WP8 | No | 4 |

Ownership check: within wave 1, WP0, WP1, WP2, WP3, and WP5 share no file (WP0 holds `Kernel.php`, `Request.php`, `SetupController.php`, `UrlType.php`, `Fs.php`, `ProjectRegistry.php`, `ProjectOperations.php` and their tests; WP2 holds `src/Core/Query/` and `SqlStringsTest.php`; WP3 holds `src/Core/Forms/`, three `src/Core/Schema/` files, and `CoreLayerRulesTest.php`; WP5 holds the files of part 2 section 13; WP1 holds `ContrastTest.php`). Within wave 3, WP6, WP7, and WP8 share no file: each owns its controllers, templates, strings file, and tests; `routes.php`, `app.css`, `layout.php`, and every shared partial and strings file belong to WP5, which has merged before wave 3. `SchemaEditor.php` is owned by WP3 in wave 1 and by WP8 in wave 3, never at the same time. Session plan section 6.2 placed plural strings and P82 in WP0; they moved to WP5 and WP8 because both touch files those packages own (`Strings.php`; `SchemaEditor.php` and the schema screens' strings).

## 13. Provisional decisions (for the ledger, from P84)

Each was chosen as the option cheapest to reverse (orchestration guide section 5) and is recorded in ADR 0013 or ADR 0014 where it is a decision rather than a constant.

| # | Item | Provisional resolution | Rationale | Recorded in |
|---|---|---|---|---|
| P84 | Paging | 50 records per page; record Previous and Next follow the list's sort, filter, and search carried in the query string; out-of-range pages clamp | Plan recommendation; one constant | ADR 0013 d2 |
| P85 | Lost-update guard | Hidden version token (16 hex characters of SHA-256 over the row and its link ids) instead of `_updated_at`; mismatch gives a 409 conflict page with both versions and a re-post | `_updated_at` has one-second resolution; no format change | ADR 0013 d6 |
| P86 | Autosave | Not built; explicit Save; "Last saved {when}" replaces "All changes saved"; after Save the record page reloads with a notice (the mock-up's Save went to the list) | Undefined behaviour; not needed for exit | ADR 0013 d7 |
| P87 | Search | ASCII case-insensitive substring (`LIKE`) over text, long text, choice, web address, email, phone, and linked titles one level deep; 1 to 200 characters; across tables 10 hits per table; no full-text index | Session plan question 3 default; no format change | ADR 0013 d5 |
| P88 | Sort | Every type but attachment; empties last both ways; ties by id; choice by list order; relation by lowest linked title; default title A to Z else order added; plus newest, oldest, recently changed | Predictable; constants | ADR 0013 d3 |
| P89 | Filter | One condition from the query string; choice, checkbox, relation, date, date-time; condition object reused by M4 collections; invalid filters reported, list unfiltered | Plan recommendation | ADR 0013 d4 |
| P90 | List columns | Title field first, then up to six more fields in field order, skipping long text; no column configuration in M2 | No list editor in v1 scope; mock-up shows seven columns | ADR 0013 d9.1 |
| P91 | Form format 1 | Vocabulary of `docs/formats/tiffin.md` 12.1; XML syntax parsed with `XMLReader`; images by attachment reference only; links parsed, shown as text until M5; form CSS reserved and ignored | ADR 0003, 0006 headroom; no new extension | ADR 0014 d1 |
| P92 | Showing stored forms | Default-deny parse into a node model rendered by templates; rejected forms replaced by the generated layout; no safe-HTML type in M2 (deviates from the session plan) | No markup needs it; keeps the template rule whole | ADR 0014 d4 |
| P93 | Generated forms | Details, Files, one section per many-link relation; long text and rating full width; English legends as data; regenerated in the schema transaction while `generated` is 1; edited forms gain and lose slots only | Mock-up layout; atomic | ADR 0014 d3 |
| P94 | Version 2 upgrade | Adds `_tiffin_forms` only; tables have no stored form until their fields next change | Format steps stay constant SQL | ADR 0014 d2 |
| P95 | Columns on wide screens (A1) | `Details` laid out as a 300 px minimum auto-fit grid; record page 1120 wide | ADR 0009 A1 as proposed | ADR 0014 d6 |
| P96 | Generated ids in templates | New scope helper `id(prefix, part...)` producing validated tokens for `id`, `for`, `aria-describedby`, `aria-labelledby`, `form`, and fragment links; amends the M1 rule that ids are literal | Record forms have one control per field | ADR 0013 d9.2 |
| P97 | Plural strings | Placeholder `{name|singular|plural}` with `#` for the formatted number, English one/other only; `Format::count` stays | One mechanism for sentences with several counts | ADR 0013 d9.3 |
| P98 | Move to trash | Immediate, with an Undo button in the next page's notice; no confirmation page; a trashed record's address shows a Restore page | Reversible action; plan recommendation | ADR 0013 d8 |
| P99 | Warning token | Light `--warn` `#703D00` (7.61:1 on the tint); no other published figure changes | Closes ui 9.4 | ADR 0013 d9.4 |
| P100 | Display link and settings route | Display link on every signed-in page (reverses that part of P41); `/settings` with Save and "Back to standard" (a `formaction` reset); live preview script; mock-up wording about black-and-white strong contrast changed | Mock-up target; ADR 0009 C2 | ADR 0013 d9.5 |
| P101 | Record form controls | Required fields marked "(required)"; choice as a select with "Not chosen"; rating as radios with "Not rated"; numbers and money as text inputs with `inputmode`; currency label adds the currency name or code | Accessible; locale-safe parsing | ADR 0013 d9.6 |
| P102 | List controls | Visible Apply button; the sort menu never submits on change | WCAG 3.2.2; works without script | ADR 0013 d9.7 |
| P103 | Sidebar in M2 | Collections, Import, Export left out; trash count shown as text; "Tables and fields" link added; `/projects/{p}` opens the first table's list (completes P43) | Screens that do not exist are not linked | ADR 0013 d9.8 |
| P104 | Record ids from URLs | Passed to the core as strings; anything but `^[1-9][0-9]{0,18}$` is not found | Same rule as keys (M1) | ADR 0013 d9.9 |
| P105 | Required and links | Requiredness not enforced for relation and attachment fields until they can be edited (M4) | Cannot be satisfied on screen in M2 | ADR 0013 d7 |
| P106 | Record writes touch project meta | Create, update, links, trash, and restore update `updated_at` and `updated_by` | Project list shows last change; extends P73 | ADR 0013 d7 |
| P107 | SQL composition | `Core\Query\Sql` fragments plus an extended scanner; `Sql.php` the only file joining variables into SQL text | Dynamic list SQL without string assembly elsewhere | ADR 0013 d9.10 |
| P108 | Work-package changes from the session plan | Plural strings moved from WP0 to WP5; P82 fix moved to WP8; the token value changed in the design, so WP1 is mechanical (tests only) | Disjoint ownership | this spec 12 (process; ledger only) |
| P109 | `Origin` check | With no `Sec-Fetch-Site`, a POST whose `Origin` is present and not the request's own origin (or is `null`) is refused; requests with no `Origin` pass | Defence in depth (M1 F-2); old browsers still work | ADR 0013 d9.11 |

## 14. Assumptions and open points

1. Household scale: tables of up to about 50,000 records (the M1 type-change limit). List, search, and position queries scan the table without indexes on field columns; at that size this is assumed fast enough on shared hosts. Not measured; the M2 exit audit may time a 50,000-record list on the build machine and record the figure.
2. `LIKE` and `NOCASE` fold ASCII letters only; searches and sorts treat "É" and "é" as different. Acceptable for English-only v1 (plan decision 11); a later version may register a folding function.
3. Window functions (`ROW_NUMBER`) and `ON CONFLICT ... DO UPDATE` need SQLite 3.25 and 3.24; the floor is 3.35.5 (P38).
4. `XMLReader` with `LIBXML_NONET` and no DTD loading does not fetch or expand external entities in the libxml versions PHP 8.3 builds with; the pre-scan of section 5.2 step 1 refuses DOCTYPE and entity declarations independently, so the parser is not the only guard. Verified by T-FORM-4 on the build machine only.
5. The time zone for "Last saved" and date displays is the configured one (P42).
6. The mock-up's list shows 10 rows per page; with 50 per page the visual check shows all 48 synthetic rows on one page (part 2 section 11.3). The maintainer may prefer a smaller page.
7. The record mock-up orders Category before Room while the list mock-up shows Room first; with one field order for both screens, the synthetic visual-check data follows the list (part 2 section 11.3).
8. Missing information: whether the maintainer wants the record screen to return to the list after Save (mock-up) or stay (P86); whether search should cover numbers and dates (P87); whether the trash count should become a link before M4 (P103). Each has a provisional answer above.
9. Behind a reverse proxy that changes the `Host` header, the `Origin` check (P109) can refuse legitimate POSTs that carry `Origin` but not `Sec-Fetch-Site`. Modern browsers send `Sec-Fetch-Site`, so the check rarely applies; the host check (maintainer task) should include one POST through the reference host.
