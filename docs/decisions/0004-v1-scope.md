# ADR 0004: v1 scope for relations, attachments, fonts, and import/export

Status: accepted by the lead developer (planning session)

## Decision

### Relations

Relations between tables in a project are a first-class field type in v1. Imports preserve them, forms show related records, and users can add and remove links.

### Attachments

File and image attachment fields are in v1. Files are stored in the project folder beside the SQLite file and served only through an access-checked endpoint. A project is therefore a folder, transferable as a zip archive.

### Fonts

Atkinson Hyperlegible is bundled and self-hosted as the default typeface. The SIL Open Font License 1.1 (`OFL-1.1`) is approved for bundled font assets only; it is not a general addition to the code dependency license list. Font files ship unmodified with their license text.

Superseded in part by ADR 0007: Inter is now the default typeface. Atkinson Hyperlegible remains bundled as a user option and as the wordmark typeface, and the OFL-1.1 rule above applies unchanged to the additional families.

### Import and export

Import and export are implemented as plugins against a common interface and a neutral intermediate model.

| Direction | v1 formats |
|---|---|
| Import | CSV, XLSX, Bento |
| Export | CSV, XLSX, JSON (JSON retained to meet the repository's portability rule) |

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Relations | Read-only or deferred | Bento imports would be incomplete without editable links |
| Attachments | Images only, or deferred | Media fields would be dropped on import |
| Fonts | System fonts only | Legibility would vary by device |

## Consequences

- Join tables and referential cleanup are part of the schema engine from the start.
- Upload limits, MIME checks, and storage quotas need handling on shared hosts.
- The Bento importer depends on a discovery step; see `docs/plan.md` section 6.4.
