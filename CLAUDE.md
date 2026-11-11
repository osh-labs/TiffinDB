# CLAUDE.md

Guidance for AI agents working in this repository.

## Project

The official project name is TiffinDB (one word, capital T, D, B). Use it consistently in code, docs, package metadata, and commits; do not shorten to "Tiffin". The lead developer is the project owner and final decision-maker, referred to in this repo only by role ("the lead developer" or "the maintainer").

TiffinDB is an open-source, self-hosted personal database application inspired by Bento (Apple/FileMaker, discontinued). The goal is a free, user-owned tool for building and using small personal databases: custom fields, forms/layouts, lists, search, and filtered collections.

Status: planning. No application code yet. Core stack decisions are recorded in `docs/decisions/` and the plan is in `docs/plan.md`; record any new decision there before scaffolding. The v1 visual target is the model UI in `docs/design/model-ui/`, with `docs/ui-design-plan.md` and `docs/design/tokens.css`; logo files and usage rules are in `docs/brand/`.

## Public Repository Rules (Mandatory)

This is a public repository. Everything committed is world-readable and permanently retrievable from git history, forks, and caches.

Agents are expressly forbidden from committing, pushing, pasting into issues/PRs/commit messages, or otherwise placing any of the following in this repo:

- Live or real user data of any kind
- Personally identifiable information (PII)
- Personal names of real individuals (including the maintainer, collaborators, family, clients, colleagues)
- Email addresses, phone numbers, physical addresses, account numbers, or device identifiers
- Credentials, API keys, tokens, certificates, private keys, connection strings, or `.env` contents
- Non-public business, client, project, or employer information
- Exports, backups, or screenshots of real databases

Requirements:

- Test fixtures, sample databases, screenshots, and documentation examples must use obviously synthetic data (e.g. "Example Person", `user@example.com`, reserved ranges such as 192.0.2.0/24 and 555-01xx numbers). Prefer generated data with a documented seed.
- Do not derive fixtures from real data, even if anonymized by hand.
- Git author/committer metadata is the one exception that cannot be avoided; do not add names or emails anywhere else (no `AUTHORS` entries, code comments, docs, or sample data) unless the maintainer explicitly directs it for a specific, named file.
- If real or sensitive data is encountered in the working tree or supplied in a prompt, do not copy it into any file. Stop and notify the maintainer.
- If something sensitive has already been committed, do not attempt to hide it with a follow-up commit. Report it immediately; history rewrite and credential rotation are the maintainer's decision.
- Add `.gitignore` entries for local databases, exports, and secrets (`*.db`, `*.sqlite*`, `*.tiffin`, `.env*`, `/local/`, `/data/`) before adding any code that creates them.
- Never reference the maintainer's employers or affiliated organizations in code, docs, or commits.

## Licensing

Intent: anyone may use, modify, and redistribute the software for any purpose provided attribution is retained and derivative works remain free (copyleft), including when run as a hosted service.

License: GNU Affero General Public License v3.0 or later (`AGPL-3.0-or-later`), chosen by the lead developer because TiffinDB is designed to be hosted on a standard LAMP stack. Section 13 requires network-service operators who modify the software to offer the corresponding source to remote users. The canonical text is in `LICENSE` (from gnu.org); do not edit it.

Rules:

- Every source file carries an SPDX header: `SPDX-License-Identifier: AGPL-3.0-or-later` plus a copyright line using "TiffinDB contributors" (not an individual).
- Keep the application's "source code" link/notice reachable in the UI (e.g. footer or About page) pointing to the repository, so AGPL section 13 is satisfied for hosted instances and modified forks inherit the pattern.
- Only add dependencies whose licenses are AGPL-3.0-compatible (MIT, BSD, Apache-2.0, ISC, LGPL, MPL-2.0, GPL-3.0, AGPL-3.0). Bundled font assets may additionally use OFL-1.1 (ADR 0004, ADR 0007). Do not add GPL-2.0-only, proprietary, source-available, or non-commercial-restricted dependencies. Record each dependency's license in `docs/dependencies.md`.
- Do not copy code, schemas, UI assets, icons, or documentation from Bento or any other proprietary product. Do not use the name "Bento" in the product name, UI, or package metadata. Describing TiffinDB as "inspired by" or "a replacement for" it in prose is acceptable.
- Do not paste code from sources of unknown or incompatible license (Stack Overflow snippets carry CC BY-SA; check before use).

## Working Conventions

- Branching: develop on the branch assigned for the session; do not push elsewhere. Do not open pull requests unless asked.
- Commits: small, descriptive, imperative mood.
- State assumptions explicitly in PR descriptions and design docs. Where uncertain, say so and list the missing information.
- Keep the data model, storage, query, and import/export logic separated from presentation so the core is testable headlessly.
- Data portability is a core value: user data must be stored in an open, documented format and be exportable (CSV and JSON at minimum) without the application.
- Tests must be deterministic and run offline with synthetic fixtures only.
- No telemetry, analytics, or network calls in the application without explicit maintainer approval and an opt-in design.
- Do not fabricate citations, standards, or license text. Fetch license text from the canonical source (gnu.org or spdx.org) when the `LICENSE` file is added.

## Open Decisions

Accepted decisions are in `docs/decisions/`; the working plan is `docs/plan.md`.

| Item | Status |
|---|---|
| Language / UI framework | Decided (ADR 0001): PHP 8.3+, no framework, Apache `.htaccess`, server-rendered HTML with vanilla JS, no build step. |
| Storage engine and file format | Decided (ADR 0001): one SQLite file per project plus a SQLite system database; MySQL/MariaDB not required |
| Accounts | Decided (ADR 0002): household accounts, Admin and Member roles, per-project grants |
| Forms | Decided (ADR 0003): stored HTML/CSS with binding attributes, auto-generated, simple editor |
| v1 scope | Decided (ADR 0004): relations, attachments, CSV/XLSX/Bento import, CSV/XLSX/JSON export |
| UI design | Decided (ADR 0007): model UI is the v1 visual target; Inter default typeface; per-user typeface and accent colour; some layout differences from the plan await confirmation (`docs/ui-design-plan.md` section 9) |
| WYSIWYG form editor | Decided (ADR 0006): post-v1, same form format, snap-to-grid layout; some defaults awaiting confirmation |
| MCP server | Decided (ADR 0005): post-v1, full scope, OAuth 2.1, disabled by default; OAuth design document required before implementation |
| Platform targets | Web application on self-hosted Apache and DreamHost shared hosting; desktop/local packaging undecided |
| Bento file/template import | Approach undecided pending file-format discovery (requires clean-room approach; no reverse-engineered proprietary code) |
