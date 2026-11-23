# Shared brief for the TiffinDB documentation writers

Kept in the repository so that the documentation work (ADR 0020) can be resumed. State of the work: `docs/build/documentation-handoff.md`.

You are one of three writers producing the HTML documentation set in `/home/user/TiffinDB/docs/manual/`. An orchestrator owns the shared files and will integrate, review, and commit. Read this whole brief, then your own assignment.

SCRATCH = a scratch folder outside the repository, chosen by the session that resumes the work. `SCRATCH/work/app0` is a runnable copy of the application with `vendor/` (make it with `git archive HEAD | tar -x -C SCRATCH/work/app0` and a copy of `vendor/`). `SCRATCH/work/shot-doc.js` is the render-check script in the appendix of `docs/build/documentation-handoff.md`.

## 1. The project, in short

- TiffinDB: open-source (AGPL-3.0-or-later), self-hosted personal database web application. PHP 8.3+, no framework, Apache `.htaccess`, server-rendered HTML, vanilla JS, no build step. One SQLite file per project plus a SQLite system database. Household accounts with Admin and Member roles.
- Read `/home/user/TiffinDB/CLAUDE.md` first. Its "Public Repository Rules" and "Licensing" sections are mandatory for everything you write.
- TRUE BUILD STATE (the status line in CLAUDE.md and the header of `docs/plan.md` are stale): milestones M0, M1, M2, M3, M4 are CLOSED (M4 closed 2026-11-22). M5 (form editor and sanitizer) is next and not started. M6 (legacy import plugin) is deferred until the maintainer runs a private discovery tool. M7 is packaging, upgrade path, release. M8 (WYSIWYG form editor) and M9 (MCP server) are post-v1. The authority is `docs/build/ledger.md`.
- Sources of truth, in order: the code and what the running app actually does; `docs/build/ledger.md`; `docs/specs/*.md` (especially the "as-built" notes at the end of each); `docs/decisions/*.md` (ADRs 0001 to 0019); `docs/formats/*.md`; `docs/plan.md`; `docs/ui-design-plan.md`; `docs/design/`.
- Never state something the code or the docs do not support. If something is unverified (for example behaviour on a real Apache or shared host: Apache was never available in the build environment, and the reference-host check is a deferred maintainer task), say so plainly in the text. Do not invent version numbers, limits, commands, file names, or standards. Check every command you print by running it where that is possible.

## 2. Hard rules for content

- Product name is always "TiffinDB" (never "Tiffin", "Tiffin DB"). Lower-case `tiffindb` only in technical identifiers.
- No personal names of real people, no email addresses other than `@example.com`, no phone numbers other than 555-01xx, no real addresses, no credentials or tokens (not even demo passwords: write "your password"), no employer or organisation names other than what is already in the repository URL `https://github.com/osh-labs/tiffindb`. Refer to people by role only: "the maintainer", "the lead developer", "an Admin", "a Member".
- Never use the name of the discontinued product that inspired TiffinDB in headings, UI descriptions, alt text, or file names. In running prose prefer "the discontinued product" or "the legacy product".
- No model names, no mention of AI agents by product name. (The handoff may say the build was run "by automated agents under an orchestration procedure", as `docs/build/orchestration.md` does.)
- No emojis. No marketing language. Direct, precise, technical where the audience is technical.
- Typography: do not use em dashes or en dashes as sentence punctuation; use commas, colons, or separate sentences. Straight quotes are fine.
- No external resources: no CDN, no web fonts, no remote images, no analytics. Everything is local and relative. External hyperlinks (`<a href="https://...">`) are allowed only to the project repository, php.net, sqlite.org, httpd.apache.org, getcomposer.org, and gnu.org, and only where useful.
- Screenshots show only the synthetic demo data made by `tools/docs/seed-demo.js` (project "Home Inventory", people "Example Admin" and "Example Member").

## 3. Files you own, and files you must not touch

Each writer owns exactly:
- `docs/manual/<doc>.html` (your one page)
- `docs/manual/img/<prefix>-*.png` (your screenshots; prefix given in your assignment)
- `tools/docs/shots-<doc>.js` (your screenshot capture script)
- optionally `docs/manual/assets/<doc>.css` for page-specific rules that the shared stylesheet lacks (keep it small; first two lines must be the SPDX comment lines in CSS form, see section 5)

Do NOT edit: `docs/manual/index.html`, `docs/manual/assets/manual.css`, `docs/manual/assets/manual.js`, `tools/docs/seed-demo.js`, any application code, tests, or other docs. Do not commit, push, or run `git add`. Do not run `composer install/update/require` (very slow here; `vendor/` is already installed in the main checkout). If you find an error in the app or in existing docs, report it in your final message instead of fixing it.

## 4. Page shell (must match exactly so the three pages read as one set)

Copy the structure of `/home/user/TiffinDB/docs/manual/index.html` (read it): doctype line, the two SPDX comment lines immediately after it, `<html lang="en">`, the `<head>` (change `<title>` and description; keep the favicon, `assets/manual.css`, and `assets/manual.js` lines; add your optional `assets/<doc>.css` after manual.css), the skip link, `div.m-shell`, `nav.m-side` (brand block copied verbatim, `details.m-menu`, `p.m-side__doc` with your document's name, `ol.m-toc` with your sections and at most one nested level of `ol` for subsections, `ul.m-docs` copied verbatim but with `aria-current="page"` moved to your page), `main#main.m-main` containing `header.m-hero` then a flat sequence of `<section id="...">` elements, each starting with `<h2><span class="m-num">Part N</span>Title</h2>`, and a closing `nav.m-pager` linking to the other guides.

Line 1 must be exactly `<!doctype html>`, line 2 exactly `<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->`, line 3 exactly `<!-- Copyright (C) TiffinDB contributors -->` (a repository test checks this).

Components available in `assets/manual.css` (read the file; it is commented):

| Need | Markup |
|---|---|
| Hero | `header.m-hero` > `p.m-kicker`, `h1`, `p.m-lede`, `dl.m-meta` (`div` > `dt` + `dd`) |
| Callouts | `div.m-note` (Note), `.m-note.m-note--caution` (Caution: risk to data), `.m-note.m-note--warning` (Warning: risk to security or people), `.m-note.m-note--tip`; each starts with `p.m-note__label` |
| Procedure | `ol.m-steps` > `li`; the visible result of a step goes in `p.m-result` inside the `li` |
| UI label | `<span class="m-ui">Save changes</span>` for the exact on-screen text of a button, link, field, or menu |
| Keys, code | `<kbd>`, `<code>`, `pre.m-code[data-label="Shell"]` > `code`; `div.m-tree` for a folder tree (use `<i>` for comments in it) |
| Screenshot | `figure.m-fig.m-fig--shot` > `img[src][width][height][alt]` + `figcaption` (start with `<b>Short title.</b>`); add `.m-wide` for a full-width shot, `.m-fig--half` for a cropped detail, `.m-fig--phone` for a phone-width shot; `div.m-figrow` puts figures side by side |
| Diagram | `figure.m-fig.m-fig--diagram` (+ `.m-wide`) > inline `<svg viewBox role="img" aria-labelledby>` with `<title>` and `<desc>` + `figcaption` |
| Table | `div.m-table-wrap` (+ `.m-wide`) > `table.m-table` with `thead` |
| Badges | `span.m-badge` + `--done`, `--next`, `--open`, `--blocked`, `--later`, `--admin` |
| Cards | `ul.m-cards` > `li` > `a.m-card` or `div.m-card` |
| Glossary | `dl.m-gloss` |
| Timeline | `ol.m-timeline` > `li.is-done` / `li.is-next` / `li` |
| Columns | `div.m-cols` |
| Checklist | `ul.m-checklist` |

Inside `section`, direct children are centred at a reading width of 46rem. Add class `m-wide` to a direct child (figure, table wrap, card list) to let it use 64rem.

## 5. SVG diagrams ("rich SVG graphics" is an explicit requirement)

- Inline SVG only, hand-written, in the page. No external SVG files, no `<image>`, no `<foreignObject>`, no scripts, no web fonts.
- Colours: never hard-code hex colours for diagram parts. Use the `d-` classes from manual.css so diagrams follow light and dark themes: fills `d-surface d-bg d-sunk d-soft d-accent d-side d-brand2 d-warn d-warn-soft d-danger d-danger-soft d-ok d-ok-soft d-ink d-ink2 d-none`; strokes `d-s-line d-s-ctl d-s-ink d-s-ink2 d-s-accent d-s-brand2 d-s-warn d-s-danger d-s-ok`, `d-dash`; text `d-t` (14px), `d-t2` (12.5px secondary), `d-th` (15px bold), `d-ton` (on accent), `d-tside` (on the dark sidebar colour), `d-taccent`, `d-twarn`, `d-tmono`, with `d-mid` / `d-end` anchors. Arrowheads: define a `<marker>` per SVG with a unique id (prefix ids with your doc prefix, since three pages must not clash and ids must be unique within a page).
- Quality bar: study the two diagrams in `index.html` and do better. Each diagram must show a real mechanism or structure (layers, a request's path, a file layout, a decision, a data model, a timeline), not decoration. Consistent 8 to 14 px corner radii, 1.5 to 2 px strokes, text never clipped or overlapping (leave generous room: at 14px, allow about 8px per character), aligned to a grid, legible at 390 px wide (if a wide diagram cannot stay legible on a phone, prefer a taller layout or two diagrams). Every SVG has `<title>` and a `<desc>` that fully replaces the picture for a screen-reader user.
- Minimum: several substantial diagrams per page (your assignment says which are required).

## 6. Screenshots

- A running app instance per writer. Create yours with:
  `cp -r SCRATCH/work/app0 SCRATCH/work/app-<prefix>` then write `SCRATCH/work/app-<prefix>/config/local.php` containing `<?php return ['data_dir' => 'SCRATCH/work/data-<prefix>'];` (absolute path), then start `php -S 127.0.0.1:<your port> -t public public/index.php` from that folder in the background. Without `config/local.php` the app would use a shared `../tiffindb-data` folder: never run without it. Use only your port.
- Seeded demo data: `tools/docs/seed-demo.js` (being finished by another agent; read its header for usage). When `SCRATCH/work/data-seeded/` and `SCRATCH/work/seed-out/state.json` exist you can copy that folder to your `data-<prefix>` instead of re-running the seed (state.json has URLs and generated passwords; keys are random per seed run, so always navigate from state.json, never hard-code keys). `SCRATCH/work/ui-notes.md` (same agent) lists every screen, its URL pattern, and the exact UI labels. If those files do not exist yet, start with the parts of your work that do not need them and check again later.
- Playwright and Chromium are preinstalled: `require('playwright')` works from any directory in a CommonJS `.js` file; `chromium.launch()` needs no options. Do not install anything.
- Capture script: `tools/docs/shots-<doc>.js`, CommonJS, first two lines exactly `// SPDX-License-Identifier: AGPL-3.0-or-later` and `// Copyright (C) TiffinDB contributors`. It must regenerate every one of your screenshots from a seeded (or, for the installer, fresh) instance: arguments `--base <url> --state <state.json> --out <img folder>` (plus what you need). One clearly named entry per screenshot. Re-use `signIn` from `seed-demo.js` (`require('./seed-demo.js')`). No hard-coded passwords.
- Standard full-window shot: viewport 1280x800, `deviceScaleFactor: 1.5`, light theme (PNG 1920x1200). Crop to the relevant element or region wherever that teaches better (element screenshots or `clip`, same scale factor; give `.m-fig--half` to small crops). Phone shots: viewport 390x780, scale factor 2. Use dark or high-contrast themes only where the text is about themes.
- Annotated screenshots (numbered callouts): inject small numbered badge elements into the page with `page.evaluate` before capturing (positioned at the bounding boxes of the target elements; teal `#084F4A` circle, white bold digit, 2px white ring), then explain the numbers in a list under the figure. Use this for the "tour of the screen" style figures.
- Never capture a real password, the setup code value, or a session value: blank or mask such fields (`page.evaluate`) before capturing if they would be visible.
- File names: `docs/manual/img/<prefix>-<short-slug>.png`, lower case, hyphens. Every `<img>` needs true `width` and `height` attributes (the PNG pixel size) and a real `alt` text describing what the picture shows. Do not optimise PNGs (the orchestrator does one pass at the end). Look at every screenshot yourself (Read tool) before using it: no half-loaded pages, no focus rings in odd places, no empty states where data was intended, and nothing cut off.

## 7. Verify before you report

1. `node SCRATCH/work/shot-doc.js /home/user/TiffinDB/docs/manual/<doc>.html SCRATCH/check/<doc>` renders desktop (1440) and phone (390) full-page PNGs and prints `overflow-x` and any failed requests; add a third argument `dark` for the dark scheme. Requirements: no horizontal overflow at 390 px, no failed requests, no console errors. Read the PNGs (they are long: also take viewport-sized shots of each diagram, or crop, so you can actually check the text in the SVGs for overlap and clipping; check dark mode for every diagram too).
2. Every `href="#..."` has a matching `id`; every id is unique; every link to another page of the set points at an id that exists there (or at the page itself); every `img src` exists.
3. HTML is well formed (for example run it through `php -r` with `DOMDocument::loadHTML` and `libxml_use_internal_errors`, or `xmllint --html`, and read the warnings).
4. Re-read your text once against section 2 (names, dashes, product name, unverified claims).
5. Your capture script runs clean from start to finish against a fresh copy of the seeded data.

## 8. Final report (short; it goes to the orchestrator, not the reader)

List: files written; number of screenshots and diagrams; every fact you could not verify and how the text handles it; every discrepancy you found between the app and the existing docs/specs; application bugs noticed; anything you want added to the shared stylesheet.
