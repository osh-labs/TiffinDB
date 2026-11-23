# TiffinDB
An open-source self hosted replacement for a certain MacOS personal database tool.

TiffinDB is a web application for small personal databases: custom tables and fields, record forms, lists with search and filters, saved collections, attachments, and import and export. It runs on ordinary PHP web hosting and keeps each project in one SQLite file.

Status: in development. Milestones M0 to M4 are complete; there is no release yet. The exact state is in `docs/build/ledger.md`.

## Documentation

Open `docs/manual/index.html` in a web browser. No server or build step is needed.

| Guide | For | File |
|---|---|---|
| User Guide | Everyone who uses TiffinDB (written in Simplified Technical English) | `docs/manual/user-guide.html` |
| Install Guide | The person who puts TiffinDB on shared hosting, an Apache server, or a development machine | `docs/manual/install-guide.html` |
| Developer Handoff and Roadmap | A developer who is new to the project | `docs/manual/handoff.html` |

Design and build records (Markdown): `docs/plan.md`, `docs/decisions/`, `docs/specs/`, `docs/formats/`, `docs/build/ledger.md`.

## Development quick start

Requires PHP 8.3 or later with `pdo_sqlite`, and Composer.

```
composer install
composer serve
```

Then open `http://127.0.0.1:8080/` and follow the setup page. Run `composer check` (lint, then the offline test suite) before committing. The Install Guide covers the details.

## Licence

GNU Affero General Public License, version 3 or later (`AGPL-3.0-or-later`). See `LICENSE`.
