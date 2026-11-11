# TiffinDB Project Plan

Status: draft 1, planning phase. No application code exists yet.
Decisions referenced here are recorded in `docs/decisions/`.

## 1. Goal

A free, self-hosted personal database application for non-technical users, typically installed and administered by a more technical household member. It replaces a discontinued proprietary personal database product and can import that product's files.

Design priorities, in order:

1. Ease of use for non-technical users.
2. Legibility and visual accessibility.
3. Data ownership and portability.
4. Simple installation and upkeep on ordinary shared hosting.
5. Extensibility through plugins (import, export, field types).

## 2. Decisions to date

| # | Topic | Decision | Record |
|---|---|---|---|
| 1 | Runtime | PHP 8.2+ (floor to be confirmed, see 9), no framework, Apache with `.htaccess`, server-rendered HTML, vanilla JS, no build step | ADR 0001 |
| 2 | Project storage | One SQLite file per project, inside a per-project folder | ADR 0001 |
| 3 | System data | Small SQLite system database (users, sessions, project registry, grants). MySQL/MariaDB is not required | ADR 0001 |
| 4 | Accounts | Household accounts, two roles (Admin, Member), per-project access grants | ADR 0002 |
| 5 | Forms | Stored as plain HTML/CSS with field-binding attributes; auto-generated from schema; simple in-browser editor; Admin may hand-edit source | ADR 0003 |
| 6 | Relations | First-class field type in v1; view and edit links | ADR 0004 |
| 7 | Attachments | File and image fields in v1, stored in the project folder | ADR 0004 |
| 8 | Fonts | Atkinson Hyperlegible bundled and self-hosted; OFL-1.1 approved for font assets only | ADR 0004 |
| 9 | Import/export | Plugin architecture. v1 import: CSV, XLSX, Bento. v1 export: CSV, XLSX (plus JSON per repository portability rule) | ADR 0004 |

## 3. Architecture

### 3.1 Layers

Core logic is separated from presentation so it can be tested headlessly.

| Layer | Responsibility | Depends on |
|---|---|---|
| `core/schema` | Tables, fields, field types, relations, migrations of user schema | storage |
| `core/storage` | SQLite access (PDO), transactions, project open/create, attachments on disk | none |
| `core/query` | Record CRUD, search, sort, filter, saved collections | schema, storage |
| `core/io` | Importer/exporter interfaces, neutral intermediate model, plugin discovery | schema, query |
| `core/forms` | Form generation from schema, form HTML sanitizer, binding of records to form HTML | schema |
| `core/auth` | Users, sessions, roles, project grants, CSRF | system DB |
| `web/` | Routing, controllers, templates, static assets | all core |
| `plugins/` | Import, export, and (later) field-type plugins | core/io interfaces |

### 3.2 On-disk layout (proposed)

```
tiffindb/
  public/            web root: index.php, .htaccess, assets/ (css, js, fonts)
  src/               core and web PHP (not web-reachable)
  plugins/
    import/csv/  import/xlsx/  import/bento/
    export/csv/  export/xlsx/  export/json/
  vendor/            Composer dependencies, shipped in the release archive
  data/              git-ignored; not web-reachable
    system.sqlite
    projects/<project-id>/
      project.tiffin         SQLite database
      attachments/           content-addressed files
      forms/                 optional: form HTML/CSS source mirrored for hand editing (open question)
```

Many shared hosts force the whole application under the document root. The installer must therefore (a) prefer a `data/` location outside the web root when available, (b) otherwise write a deny-all `.htaccess` into `data/`, and (c) actively test over HTTP that `data/` is unreachable and refuse to finish setup if it is.

### 3.3 Project database

Each user table is a real SQLite table with real typed columns (not an entity-attribute-value store). This keeps the file directly usable with any SQLite tool, which serves the portability goal.

Metadata lives in reserved tables in the same file:

| Table | Content |
|---|---|
| `_tiffin_meta` | Format version, project name, creation info |
| `_tiffin_tables` | User tables: stable key, display name, order |
| `_tiffin_fields` | Fields: stable key, display name, type, options (JSON), order |
| `_tiffin_relations` | Link definitions between tables; many-to-many via generated join tables |
| `_tiffin_forms` | Form HTML, CSS, target table, default flag |
| `_tiffin_collections` | Saved filters/sorts (smart collections) |
| `_tiffin_attachments` | File hash, original name, MIME type, size |
| `_tiffin_import_log` | Source, plugin, time, row counts, warnings |

The format is to be documented in `docs/formats/tiffin.md` and versioned; upgrades run as forward-only migrations with an automatic file copy beforehand.

### 3.4 Field types (v1 candidate set)

Text, long text, number, currency, date, time, date-time, duration, checkbox, choice (single), rating, URL, email, phone, attachment (file/image), relation, auto-number.

Deferred: calculated fields, multi-choice, address composite, encrypted fields.

Each type defines: storage column type, validation, HTML input rendering, display rendering, import coercion, export formatting. Field types should be implemented behind one interface so they can later become plugins.

## 4. Forms

- A form is an HTML fragment plus a scoped CSS block. Elements are bound to data with attributes, e.g. `data-tiffin-field="<field key>"`; the server fills values and emits the correct input control for the field type.
- No per-form JavaScript. Forms work without JS; JS only enhances (autosave, date pickers where native ones are weak, link pickers).
- Auto-generation: one accessible single-column layout per table (label + control pairs, `fieldset` grouping, relation lists at the end). Runs on table creation and after import.
- Simple editor (Admin): reorder, show/hide, set width on a CSS grid, group under headings, rename labels. Output is the same HTML/CSS, so hand editing and the editor operate on one representation.
- Hand editing (Admin): source view with validation.
- Security: stored form HTML passes an allowlist sanitizer (no `script`, no event-handler attributes, no external URLs, no `style` escapes out of the form scope). A strict Content-Security-Policy backs this up. Members cannot edit forms.
- User accessibility settings (font size, contrast, typeface) must override form CSS, not the reverse. Form CSS is restricted to layout and to design tokens.

## 5. Accessibility and legibility

- Target: WCAG 2.2 AA throughout; default theme aims for 7:1 text contrast (AAA level for contrast).
- Typeface: Atkinson Hyperlegible, self-hosted. No network font loading.
- Per-user settings, stored server-side and applied through CSS custom properties: text size (stepped scale), line spacing, theme (light, dark, high-contrast light, high-contrast dark), reduced motion, optional system-font fallback.
- Layout in relative units; usable at 200% and 400% zoom without horizontal scrolling of forms.
- Full keyboard operation, visible focus, semantic landmarks, labels on every control, error messages tied to fields.
- Large touch targets (minimum 44 px) and plain-language UI text.
- Automated checks (static HTML validation, contrast tests on tokens) in the test suite; manual screen-reader passes per milestone.

## 6. Import and export

### 6.1 Pipeline

```
file -> Importer.inspect() -> proposed schema + preview
     -> user review (rename fields, change types, pick target table, skip columns)
     -> Importer.read() rows -> coercion -> single transaction commit
     -> import report (counts, warnings, skipped values)
     -> auto-generated form
```

Importers never write to storage directly. They produce a neutral intermediate model (tables, typed fields, row iterator, relations, attachment streams) so every source format shares one review UI and one commit path. Exporters consume the same model.

### 6.2 Plugin contract (sketch)

- A plugin is a directory with a manifest (id, name, version, kind, file extensions, license) and a class implementing `Importer` or `Exporter`.
- Discovery by directory scan; Admin can enable/disable.
- Plugins run with full PHP privileges (no sandbox is feasible on shared hosting). Only Admins install them; this is to be stated plainly in the documentation.

### 6.3 Formats

| Format | Direction | Notes |
|---|---|---|
| CSV | import, export | Delimiter/encoding detection, header row option, type inference, streaming |
| XLSX | import, export | One table per sheet; streaming library to stay within shared-host memory limits |
| JSON | export | Full project, schema included; documented in `docs/formats/` |
| Bento | import | See 6.4 |
| Project archive | export, import | Zip of the project folder; the native backup/transfer path |

### 6.4 Bento import

Source file form is not yet known (database package, template export, or mobile data). Discovery is therefore the first task.

Assumptions (unverified; to be confirmed against real files during discovery):

- The desktop database package contains a SQLite store, which PHP can open read-only with `pdo_sqlite`.
- Template exports may embed the same or a similar structure.

Constraints from the repository rules:

- Clean-room: work only from observation of the file structure of legitimately owned files. No proprietary code, schemas copied from vendor materials, or UI assets.
- Real Bento files must never enter the repository, issues, or commit messages. Discovery runs locally and outside the working tree.
- A discovery script will emit structure only (table names, column names and types, row counts), with no row values. Its output still needs maintainer review before anything is committed, because user-defined library and field names can themselves be personal.
- Test fixtures cannot be produced by the original application. They will be SQLite files generated by a seeded script that reproduces the observed structure with synthetic records.

Findings go in `docs/formats/bento.md`. Unsupported field types are imported as text where possible and always listed in the import report.

## 7. Security baseline

- Passwords with `password_hash` (Argon2id where available, bcrypt otherwise); session cookies `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS.
- CSRF tokens on all state-changing requests; prepared statements only; identifiers (table/column names) generated by the application, never taken from user text.
- Attachments served only through an access-checked endpoint with safe content types and `Content-Disposition`; upload type and size limits.
- Strict CSP, no inline script.
- No telemetry and no outbound network calls.
- A "Source code" link in the UI footer or About page (AGPL section 13).

## 8. Milestones

| ID | Scope | Exit criteria |
|---|---|---|
| M0 | Decisions, Bento discovery, repo scaffolding, `.gitignore`, CI running offline tests | ADRs accepted; `docs/formats/bento.md` first findings; empty app serves a page |
| M1 | Installer, system DB, auth, roles, project create/open, schema engine, field types | Headless tests for schema and storage; Admin can create a project and a table |
| M2 | List view, auto-generated form, record CRUD, search/sort, design tokens, accessibility settings | A Member can add, find, and edit records by keyboard only; contrast tests pass |
| M3 | Import/export framework; CSV and XLSX import; CSV, XLSX, JSON export; project archive | Round-trip tests on seeded synthetic fixtures |
| M4 | Relations UI, attachments, saved collections | Linked records editable from a form; attachment access control tested |
| M5 | Form editor and source editing with sanitizer | Editor output equals hand-editable HTML; sanitizer test corpus passes |
| M6 | Bento import plugin | Synthetic Bento-structure fixture imports with relations and attachments; import report complete |
| M7 | Packaging, upgrade path, host and user documentation | Release archive installs on a stock shared host by upload and setup page only |

Accessibility work is continuous from M2 onward, not a separate phase.

## 9. Candidate dependencies (not yet added)

| Dependency | Purpose | License | Note |
|---|---|---|---|
| OpenSpout | Streaming CSV/XLSX read and write | MIT | Its minimum PHP version may set the project's PHP floor; to be verified before adoption |
| Atkinson Hyperlegible | Default UI typeface | OFL-1.1 | Font asset; approved under ADR 0004 |
| PHPUnit (dev only) | Tests | BSD-3-Clause | Not shipped in releases |

Each is to be recorded in `docs/dependencies.md` when actually added. Licenses above are stated from general knowledge and must be confirmed against each project's own license file at that time.

## 10. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Bento file structure differs from assumptions or varies by version | Import milestone slips | Discovery in M0; importer isolated as a plugin; CSV export from the old application remains a fallback path where still available |
| Shared host lacks `pdo_sqlite`, `zip`, or `xmlreader` | Install fails | Installer requirement check with plain-language messages |
| Data directory web-reachable on some hosts | Data exposure | Installer HTTP self-test; deny rules; refuse to complete setup |
| SQLite write contention | Lock errors under simultaneous edits | WAL mode where the filesystem supports it, busy timeout, short transactions; household scale assumed |
| User-authored form HTML | Stored XSS | Allowlist sanitizer, CSP, Admin-only editing |
| Large imports hit PHP time/memory limits | Failed imports | Streaming readers, chunked import with resumable progress |

## 11. Open questions

1. Bento source file form and application version(s) (discovery, M0).
2. Whether form source is also mirrored to files on disk for editing with an external editor, or kept only in the project database.
3. Minimum PHP version (8.2 vs 8.3), pending dependency check and target hosts.
4. Localization: English only in v1, or translatable strings from the start.
5. Record change history / undo depth for non-technical users (trash with restore is assumed as a minimum).
6. Desktop/local packaging (out of scope for v1 unless decided otherwise).
7. Nginx or other non-Apache hosts (v1 assumes Apache `.htaccess`).
