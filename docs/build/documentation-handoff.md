# Documentation set: handoff

Written 2026-11-23 when the work was stopped on the maintainer's instruction. Contains no personal data. Scope: the HTML documentation set of ADR 0020 (`docs/manual/`), not the application build (for that, see `docs/build/ledger.md`).

## 1. Bottom line

- The task: a browser-viewable documentation set with three guides (user guide in Simplified Technical English, install guide, developer handoff and roadmap), with SVG diagrams and real screenshots.
- State: the foundation is committed and pushed. None of the three guides exists yet. No screenshot exists yet.
- Estimated completion: about 15 percent (foundation, entry page, demo-data script, briefs). The three guides are the bulk of the work.
- Nothing is running. All agents and local servers were stopped. No work is unpushed.
- Branch: `claude/nice-cray-z9jgtj` (three commits ahead of `main` after this handoff is committed). No pull request was opened.

## 2. What is done

| Item | File | State |
|---|---|---|
| Decision record | `docs/decisions/0020-html-documentation-set.md` | Committed. Status: accepted, provisional. Sets location, self-contained pages, screenshot rules, tooling, STE scope, upkeep |
| Shared stylesheet | `docs/manual/assets/manual.css` | Committed. Design tokens, light and dark, print, all page components, `d-` classes for themed SVG diagrams |
| Shared script | `docs/manual/assets/manual.js` | Committed. Optional: current-section marker, folding contents list on narrow screens |
| Entry page | `docs/manual/index.html` | Committed. Two SVG diagrams (system overview, milestone progress). Rendered and checked at 1440 px and 390 px, light and dark: no horizontal overflow |
| SPDX exemption for screenshots | `tests/repo/SpdxHeaderTest.php`, `docs/specs/M0-scaffolding.md` section 13 | Committed. One register entry: `docs/manual/img/*.png` |
| Tooling record | `docs/dependencies.md`, new last section | Committed. Playwright licence (Apache-2.0) confirmed from its own licence file |
| Stale status corrected | `CLAUDE.md` status paragraph, `README.md` | Committed. Both now state M0 to M4 complete, M5 next, and point to `docs/manual/` |
| Demo-data script | `tools/docs/seed-demo.js` (646 lines) | Committed. Installs a fresh instance through the setup page, then creates "Home Inventory" (Rooms, Items, Warranties, links, 3 attachments, CSV import, 2 saved collections, 2 trashed records), "Recipe Box", and the people "Example Admin" and "Example Member". Passwords are generated at run time and written only to a state file outside the repository |
| Writers' brief | `docs/build/documentation-brief.md` | Committed with this handoff. Page shell, components, diagram and screenshot rules, content rules, verification steps |

Test evidence:

- `vendor/bin/phpunit tests/repo`: OK, 3264 tests, after the SPDX and dependency-record changes (run by the orchestrating session).
- Full suite: OK, 9502 tests, 963,516 assertions, 1 skipped, 3 min 14 s. Run by the install-guide agent on the working tree that already held the foundation changes. The orchestrating session did not repeat it.
- `composer lint`: not run in this session.
- `seed-demo.js`: two consecutive clean runs ended with "Seeded." (logs of the seed agent). The orchestrating session did not inspect the seeded instance or its check screenshots, so treat the script as passing but unreviewed.

## 3. What is not done

| Item | State | Notes |
|---|---|---|
| `docs/manual/user-guide.html` | Not started | Was waiting for the seeded demo data. Outline in section 6.1 |
| `docs/manual/install-guide.html` | Not started (research partly done by an agent, nothing written) | Outline in section 6.2 |
| `docs/manual/handoff.html` | Not started (research partly done by an agent, nothing written) | Outline in section 6.3 |
| Screenshots, `docs/manual/img/` | None | Folder does not exist yet |
| `tools/docs/shots-<page>.js` capture scripts | None | One per guide, plus the entry-page hero shot |
| `tools/docs/README.md` | Not written | ADR 0020 decision 4 refers to it |
| UI notes for writers | Not written | The seed agent was to record every screen, URL pattern, and exact label; it was stopped first |
| Independent review of each guide | Not started | Planned: fact check against code for install guide and handoff; STE and accuracy check for the user guide |
| PNG palette reduction | Not started | ADR 0020 decision 3 |
| Ledger entry | Not made | `docs/build/ledger.md` has no record of this work or of ADR 0020 as a provisional decision |

## 4. Known defects in what is pushed

These are on the branch now. Fix or accept them before a merge to `main`.

1. `docs/manual/index.html` shows a broken image: it references `img/index-hero.png`, which does not exist.
2. `docs/manual/index.html`, `README.md`, and `CLAUDE.md` link to or name `user-guide.html`, `install-guide.html`, and `handoff.html`, which do not exist. The links are dead until the guides are written.
3. `docs/manual/index.html` and ADR 0020 name `tools/docs/README.md`, which does not exist.
4. `docs/dependencies.md` and ADR 0020 describe `shots-<page>.js` scripts that do not exist yet.
5. `docs/plan.md` still says in its header that no application code exists. Not changed in this work.

If the work will not resume soon, the smallest consistent state is: remove the hero figure from `index.html` and mark the three guide cards as "not written yet", or revert the `README.md` documentation table. Neither was done, because the instruction was to stop.

## 5. Decisions made in this work that the maintainer should confirm

| Decision | Where recorded | Reversal cost |
|---|---|---|
| Documentation lives in `docs/manual/` as static HTML with no build step | ADR 0020 decision 1 | Low |
| Binary PNG screenshots are allowed in the repository, in `docs/manual/img/` only | ADR 0020 decision 3, SPDX test register | Low to remove; files stay in git history |
| Screenshot scripts in `tools/docs/` need Node.js and Playwright, which are not project dependencies | ADR 0020 decision 4, `docs/dependencies.md` | Low |
| The user guide is "written to the rules of" ASD-STE100, not certified, and UI text is quoted exactly | ADR 0020 decision 5 | None |
| Fonts are referenced from `public/assets/fonts/` by relative path, with device-font fallback when the set is copied elsewhere | ADR 0020 decision 2 | Low |
| The `CLAUDE.md` status paragraph was edited | Commit `07b0eb0` | Low |

## 6. Outlines of the three guides (as briefed to the writers)

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

## 7. How to resume

1. Check out `claude/nice-cray-z9jgtj`. Run `composer install` (see section 8 for the environment quirk).
2. Make a scratch folder outside the repository and a runnable copy of the application in it, with `config/local.php` setting `data_dir` to a scratch data folder. Without that file the application uses `../tiffindb-data`, which copies of the application would share.
3. Start the copy: `php -S 127.0.0.1:8091 -t public public/index.php`.
4. Seed it: `node tools/docs/seed-demo.js --base http://127.0.0.1:8091 --app <app copy> --data <data folder> --out <scratch folder>`. Review the result in a browser before relying on it. Keep the `state.json` it writes out of the repository: it holds the generated passwords.
5. Write the UI notes (every screen, URL pattern, exact labels), then the three guides, each to `docs/build/documentation-brief.md` and its outline in section 6.
6. Have each guide reviewed by someone who did not write it, against the code and the running application.
7. Look at every screenshot before it is committed. A screenshot is permanent in a public history; none may show a password, a setup code, or data that is not the synthetic demo data.
8. Reduce the PNG files to an indexed palette, write `tools/docs/README.md`, add the ledger entry, run `composer check`, and fix the defects in section 4.

Work-splitting note: the three guides are independent once the seeded instance and the UI notes exist. The user guide depends on both; the other two depend on neither for their text.

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
