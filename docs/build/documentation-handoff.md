# Documentation set: handoff

First written 2026-11-23 when the work was stopped on the maintainer's instruction; updated 2026-11-23 when the work was resumed and completed. Contains no personal data. Scope: the HTML documentation set of ADR 0020 (`docs/manual/`), not the application build (for that, see `docs/build/ledger.md`, entry DOC-1).

## 1. Bottom line

- The set is complete for the code on `main` at a8ca17b (M0 to M4 closed): entry page, user guide, install guide, and developer handoff, with inline SVG diagrams and 51 screenshots (50 in the guides plus the entry-page hero) of synthetic demo data.
- Each guide was checked against the code and the running application by a reviewer who did not write it, and corrected. The install guide was also checked for unsafe instructions; one was found (moving an installation could put the data folder under the document root) and fixed.
- Not verified anywhere in the set: behaviour on a real Apache server or on the reference shared host (the install guide marks every such step as derived from the code), a manual screen-reader pass, and browsers other than Chromium.
- A later integration branch, not merged to `main`, has M5 closed and M7 started. When it merges, the handoff page's status parts and any changed screens must be updated (ADR 0020 decision 6).

## 2. What exists

| Item | File |
|---|---|
| Decision record | `docs/decisions/0020-html-documentation-set.md` (accepted, provisional) |
| Entry page | `docs/manual/index.html` |
| User guide (Simplified Technical English) | `docs/manual/user-guide.html`, `assets/user-guide.css`, `img/user-*.png` |
| Install guide | `docs/manual/install-guide.html`, `assets/install-guide.css`, `img/install-*.png` |
| Developer handoff and roadmap | `docs/manual/handoff.html`, `assets/handoff.css`, `img/handoff-*.png` |
| Shared style and script | `docs/manual/assets/manual.css`, `manual.js` |
| Demo data and screenshot scripts | `tools/docs/seed-demo.js`, `shots-user-guide.js`, `shots-install-guide.js`, `shots-handoff.js`, `shots-index.js`, `reduce-png.php`; procedure in `tools/docs/README.md` |
| Writers' brief | `docs/build/documentation-brief.md` (rules every page follows) |

## 3. Application observations made while writing

None of these were changed (documentation work only). The user guide describes the real behaviour neutrally where a reader meets it.

1. Plural text: "1 values could not be converted and were cleared." and an import warning "1 value ... They will be left empty".
2. "Add a field": "Next" with no type chosen reloads with no message; "Choose what the field will hold." is never shown.
3. Export ignores the scope choice without saying so: JSON always exports the whole project; CSV with "Every table" exports the selected table.
4. The tables page has no link to the record lists; for a Member it is a dead end.
5. XLSX "How the file is read" shows two groups both titled "Sheet".
6. Link picker: choosing a record during a search then shows that nothing matches.
7. A Checkbox field's page counts only ticked boxes as having a value; the delete-table page counts records in the trash, lists do not.
8. Import into a new table sets the record word to the table name in lower case.
9. Change password reports only one problem per attempt when the new passwords differ.
10. At phone width the sidebar sits above the record list.
11. The setup page's "PHP too old" row is probably unreachable: Composer's platform check stops first with a plain error page.
12. After setup, `DataDirectory::locate()` uses the first candidate that holds `system.sqlite` without checking the document root again. After a move to a host where the sibling folder is inside the document root, only the data folder's `.htaccess` protects it. The install guide's move procedure avoids this; a runtime check could be considered for M7.
13. `/admin/plugins`: section headings sit flush against the first card; the breadcrumb repeats the heading.

## 4. Known limits of the set

- The upload limit shown in two user-guide screenshots ("2.0 MB") is the PHP built-in server's default, not a TiffinDB limit; the captions say so.
- Wide tables scroll sideways inside their frame on a phone; the page itself does not.
- `tests/repo` test counts grow with the number of files, so the handoff page quotes the ledger's suite figure as the baseline.

## 5. Decisions made in this work that the maintainer should confirm

| Decision | Where recorded | Reversal cost |
|---|---|---|
| Documentation lives in `docs/manual/` as static HTML with no build step | ADR 0020 decision 1 | Low |
| Binary PNG screenshots are allowed in the repository, in `docs/manual/img/` only | ADR 0020 decision 3, SPDX test register | Low to remove; files stay in git history |
| Screenshot scripts in `tools/docs/` need Node.js and Playwright, which are not project dependencies | ADR 0020 decision 4, `docs/dependencies.md` | Low |
| The user guide is "written to the rules of" ASD-STE100, not certified, and UI text is quoted exactly | ADR 0020 decision 5 | None |
| Fonts are referenced from `public/assets/fonts/` by relative path, with device-font fallback when the set is copied elsewhere | ADR 0020 decision 2 | Low |
| The `CLAUDE.md` status paragraph was edited | Commit `07b0eb0` | Low |

## 6. Outlines of the three guides (as briefed to the writers; kept as the record of scope)

All three follow `docs/build/documentation-brief.md`. Each owns one page, its `img/<prefix>-*.png` files, and one capture script.

### 6.1 User guide (`user-guide.html`, prefix `user-`)

Audience: non-technical Members and Admins. Language: Simplified Technical English. About 25 to 35 screenshots, several annotated with numbered callouts.

1. About TiffinDB and the words it uses (project, table, record, field, collection), with a diagram of how they nest.
2. Sign in, sign out, change your password (including the forced change at first sign-in).
3. A tour of the screen (annotated screenshot).
4. Projects.
5. Look at records: list, sort, search, filter.
6. Add and change a record; table of the 17 field types.
7. Links between records.
8. Attached files.
9. Saved collections.
10. Trash and restore.
11. Import from CSV and XLSX.
12. Export, and project archives.
13. Settings: theme, typeface, accent colour, text size, line spacing.
14. Admin tasks: make a project, design tables and fields, people, sharing, plugins.
15. Keyboard use; problems and answers.

### 6.2 Install guide (`install-guide.html`, prefix `install-`)

Audience: a technical person who deploys to shared PHP hosting or an Apache server, or starts a development server. About 8 to 12 screenshots and at least 4 diagrams (path decision, on-disk layouts, installer flow with the exposure probe, request path through the two `.htaccess` files).

1. Before you start, including a verification-status statement.
2. Requirements, exactly as `src/Core/Install/` checks them.
3. Check the host first (`tools/host-check/`).
4. Get and prepare the files (no release archive exists until M7: source plus `composer install --no-dev`).
5. Deploy: shared hosting in two document-root arrangements; own Apache server.
6. The setup page: three steps, setup code, first Admin.
7. Configuration reference: `config/local.php` (`data_dir`, `timezone`, `secure_cookies`), `config/app.php`, PHP ini values.
8. Security checklist for a live site.
9. Development server: `composer serve`, `composer test`, `composer lint`, `composer check`.
10. Operations: backups, password reset from the command line, moving, updating, uninstalling.
11. Troubleshooting table.
12. Quick reference.

Constraint that the text must state: Apache was never available in the build environment, and the reference shared host has not been checked (ledger section 5, deferred maintainer task). Every Apache and shared-host step is derived from the code and the `.htaccess` files, not from a run.

### 6.3 Developer handoff and roadmap (`handoff.html`, prefix `handoff-`)

Audience: a developer new to the project. At least 6 diagrams (system context, layers and allowed dependencies, life of a request, data model, import pipeline, build process, roadmap chart).

1. Start here: true state, what is next, what waits on the maintainer, first-day checklist.
2. Orientation: goals, users, hard constraints.
3. Architecture.
4. Code map, and a "where do I change X" table.
5. Working rules: rule, reason, what enforces it.
6. How the build has been run (specifications, work packages, reviews, exit audits, ledger).
7. Tests and quality gates.
8. Status of M0 to M4 in detail.
9. Roadmap M5 to M9 with exit criteria, prerequisites, and open questions.
10. Open decisions and maintainer tasks.
11. Risks, known gaps, and a list of stale documents.
12. Reference: glossary, ADR index, document map.

## 7. How to regenerate

Follow `tools/docs/README.md`. In short: make a runnable copy of the application outside the repository with its own `config/local.php`, start it with the built-in server, run `seed-demo.js`, run the capture script of each changed page, look at every PNG, reduce them with `reduce-png.php`, and check each changed page at 1440 and 390 px in light and dark. Never commit `state.json`: it holds the generated passwords, and `seed-demo.js` refuses an output folder inside the checkout.

## 8. Environment notes from this session

- Composer could not download distribution archives and fell back to git clones (7.3 GB for the development install; 946 MB after the `.git` folders inside `vendor/` were removed). This matches `docs/build/orchestration.md` section 9. `vendor/` is git-ignored and is lost when the container is reclaimed.
- Headless Chromium with Playwright was preinstalled; nothing was installed for the screenshots.
- A usage limit stopped all three agents a few minutes after they started, and they were resumed about four hours later. This is why the guides had not been started when the stop instruction came.
- A commit hook in the session asks for every untracked file to be committed. Agent output therefore reaches the public history as soon as the session pauses, so review must happen before each pause, not at the end.

## Appendix: render-check script

Used to check a page at desktop and phone widths. Save outside the repository as `shot-doc.js`; run `node shot-doc.js <absolute path to page> <output prefix> [dark]`.

```js
const { chromium } = require('playwright');
(async () => {
  const [file, out, mode] = process.argv.slice(2);
  const b = await chromium.launch();
  for (const [name, w, h] of [['desk', 1440, 900], ['phone', 390, 800]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: mode === 'dark' ? 'dark' : 'light' });
    const p = await ctx.newPage();
    const msgs = [];
    p.on('console', m => msgs.push(m.type() + ': ' + m.text()));
    p.on('requestfailed', r => msgs.push('FAILED ' + r.url()));
    await p.goto('file://' + file);
    await p.waitForTimeout(300);
    await p.screenshot({ path: `${out}-${name}${mode === 'dark' ? '-dark' : ''}.png`, fullPage: true });
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    console.log(name, 'overflow-x:', overflow, msgs.join(' | '));
    await ctx.close();
  }
  await b.close();
})();
```
