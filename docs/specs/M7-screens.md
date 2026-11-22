# M7 design spec: web layer and screens

Status: design spec for milestone M7, part 2 of 2 of the part 1 design (M7-D1), awaiting spec review (M7-R1) and reconciliation with the M5 as-built notes (M7-D2). Part 1, `docs/specs/M7-release.md`, has the core, the release tool, the test plan, the provisional decisions (P213 to P241), and the work-package list; this part has the web layer: headers and static files, routes, controllers, shell changes, templates, strings, the stylesheet, the keyboard procedure, the visual check, and the file ownership of the web packages. Screen-by-screen designs with every string are in `docs/design/m7/`.

The M1 to M5 web layers (`docs/specs/M1-screens.md` to `docs/specs/M5-screens.md` and their as-built notes) are extended, not replaced. Statements about web classes are from the code at M4 close with the M5 design merged (`0c3ad18`), marked "(as built)"; M5's own additions (form routes, layouts, `Response::attachmentText`) are taken as designed and are checked by M7-D2.

## 1. What changes from M5

| Area | M5 | M7 |
|---|---|---|
| Page head | stylesheets, one optional script | plus the favicon and the web manifest (section 3) |
| Page CSP | ADR 0008 policy | plus `manifest-src 'self'` (section 2.1) |
| Static files on Apache | `nosniff` only | sandbox CSP, `DENY`, `no-referrer`, one week of caching (section 2.2) |
| Routes | M5 table | 12 more (section 4) |
| Top bar | Projects, People, Plugins tabs for Admins | plus Activity (section 5) |
| Footer | licence line, Source code | plus "About TiffinDB" when signed in (section 5) |
| Sign-in page | form | plus the recovery link and the "password changed" notice after recovery (section 6.3) |
| Project settings | name, file totals | plus links to Snapshots and Delete this project (section 6.2) |
| New screens | form editor, source, upload, reset | snapshots, restore confirmation, project deletion, activity log, About, licence, recovery |
| Scripts | M5 adds none | none |

## 2. Headers and static files (M7-WP3) (security-critical)

### 2.1 Page CSP

`SecurityHeaders::CSP_DIRECTIVES` (as built) gains `['manifest-src', "'self'"]`, placed after `img-src`. Nothing else in the page policy changes. `SecurityHeadersTest` asserts the full policy string.

### 2.2 `public/.htaccess`

Inside the existing `<IfModule mod_headers.c>` block, after the `nosniff` line, a `<FilesMatch "\.(css|js|woff2|svg|webmanifest|txt)$">` section with exactly:

```
Header set Content-Security-Policy "default-src 'none'; frame-ancestors 'none'; sandbox"
Header set X-Frame-Options "DENY"
Header set Referrer-Policy "no-referrer"
Header set Cache-Control "public, max-age=604800"
```

Inside `<IfModule mod_mime.c>`: `AddType application/manifest+json .webmanifest`. The rewrite rules are unchanged. `HtaccessTest` (as built: reads the file's text) asserts each line, that the `FilesMatch` pattern lists exactly these six extensions, and that no other directive is added. The root `.htaccess` is unchanged (everything already goes into `public/`). Apache behaviour stays pending the host check.

The application's own responses keep their headers (as built); the files served by the application (attachments, form downloads, exports) are not static files and are not affected.

## 3. Icons and manifest (M7-WP3)

- `public/assets/icons/favicon.svg`: byte-identical copy of `docs/brand/tiffindb-mark-small.svg`. `public/assets/icons/app-icon.svg`: byte-identical copy of `docs/brand/tiffindb-app-icon.svg`. A design test (`tests/design/IconFilesTest.php`, new) compares the bytes, so the brand files stay the source.
- `public/assets/app.webmanifest`: JSON, exactly `{"name": "TiffinDB", "short_name": "TiffinDB", "start_url": "../", "scope": "../", "display": "browser", "icons": [{"src": "icons/app-icon.svg", "sizes": "any", "type": "image/svg+xml"}]}` JSON has no comments, so the file gets an entry in `SpdxHeaderTest::EXEMPTIONS` (as built for `composer.json` and plugin manifests), added by WP6 together with the one for `config/notices.json` (WP6 owns that test; WP3 starts after WP6 is merged). `start_url` and `scope` are relative to the manifest's address, so both layouts and any base path work.
- `layout.php` head, after the stylesheets: `<link rel="icon" href="<?= $this->asset('icons/favicon.svg') ?>" type="image/svg+xml" />` and `<link rel="manifest" href="<?= $this->asset('app.webmanifest') ?>" />`, on every page, signed in or not. `asset()` adds `?v=<version>` (as built), which is harmless on both.
- `TemplateRulesTest` (as built: allows `link` only for stylesheets) allows exactly these two `rel` values with `href` from `asset()`.

## 4. Routes (M7-WP3, with the controllers pending as in M4 and M5)

| Method | Pattern | Handler | Access | Package |
|---|---|---|---|---|
| GET | `/projects/{project}/snapshots` | `SnapshotController::index` | user | WP4 |
| POST | `/projects/{project}/snapshots` | `SnapshotController::take` | user | WP4 |
| GET | `/projects/{project}/snapshots/{snapshot}/restore` | `SnapshotController::restoreForm` | user | WP4 |
| POST | `/projects/{project}/snapshots/{snapshot}/restore` | `SnapshotController::restore` | user | WP4 |
| GET | `/projects/{project}/delete` | `ProjectController::deleteForm` | user | WP4 |
| POST | `/projects/{project}/delete` | `ProjectController::delete` | user | WP4 |
| GET | `/admin/activity` | `AuditController::index` | user | WP5 |
| GET | `/about` | `AboutController::show` | user | WP5 |
| GET | `/about/licences/{licence}` | `AboutController::licence` | user | WP5 |
| GET | `/recover` | `RecoveryController::form` | guest | WP5 |
| POST | `/recover/start` | `RecoveryController::start` | guest | WP5 |
| POST | `/recover` | `RecoveryController::recover` | guest | WP5 |

Order: the project routes after `/projects/{project}/sharing` (POST) and before the import routes; `/admin/activity` after the plugin routes; `/about` and `/recover` after `/settings/reset`. `{snapshot}` is the snapshot file name without `.tiffin` (it fits the router's `[A-Za-z0-9_-]+`, as built); the core decides whether it names a snapshot. URL shapes follow ADR 0008 decision 3; they are bookmarkable and costly to reverse.

## 5. Shell changes (M7-WP3)

- `Shell::NAVS` (as built) gains `activity`. The top bar draws, for Admins, after Plugins: `a.top-pill` to `/admin/activity`, `shell.nav.activity` "Activity", with `aria-current="page"` when `nav` is `activity`.
- The footer partial gains a data key `about` (bool, default false). When true it draws, between the licence line and "Source code", `a` to `/about` with `shell.footer.about` "About TiffinDB". `layout.php` passes `about` true in the signed-in branch only (the About page needs a sign-in; the AGPL "Source code" link stays on every page, signed in or not, as built).
- Breadcrumbs: snapshots list: project, `snapshots.crumb` "Snapshots"; restore: project, Snapshots, `snapshots.restore.crumb` "Restore"; delete: project, `projects.delete.crumb` "Delete"; About and licence pages: none (top-level pages); activity: none.

## 6. Templates

### 6.1 Shared parts (WP3)

No new shared partial. The M7 pages use the existing `page-head`, `notice`, `facts`, `form-actions`, `field-label`, `field-error`, `pager`, `empty-state`, `csrf` partials (as built) and the `danger-zone` class (M4). Classes added to `app.css` are in section 8.

Layouts for each page (all exist as built; no `layout.php` branch is added, which is checked by T-WEB-80):

| Page | `layout` |
|---|---|
| Snapshots | `settings` |
| Restore confirmation | `record-narrow` |
| Delete a project | `record-narrow` |
| Activity | `io` |
| About, licence | `settings` |
| Recovery | plain shell (`shell` null), `wide` false |

### 6.2 WP4 pages

| Page | Status | Template |
|---|---|---|
| Snapshots | 200 | `templates/pages/snapshots/index.php` |
| Restore confirmation | 200; 422 (unreadable snapshot, locks unavailable) | `templates/pages/snapshots/restore.php` |
| Delete a project | 200; 422 (wrong name, locks unavailable, move failed); 200 again when the counts grew | `templates/pages/projects/delete.php` |
| Project settings (changed) | 200 | `templates/pages/projects/settings.php`: after the totals line, `h2` `projects.settings.more` "More for this project" and a `ul` with `a` `projects.settings.snapshots` "Snapshots" and `a.link-danger` `projects.settings.delete` "Delete this project" |

### 6.3 WP5 pages

| Page | Status | Template |
|---|---|---|
| Activity | 200 | `templates/pages/audit/index.php` |
| About | 200 | `templates/pages/about/index.php` |
| Licence | 200 | `templates/pages/about/licence.php` |
| Recovery (explain and start; then enter the code) | 200; 422 (password problems, failed); 429 (wait) | `templates/pages/recover/index.php` |
| Sign in (changed) | as built | `templates/pages/sign-in.php`: below the form, `p.help` with `a` `signin.recover` "Locked out of the Admin account?" to `/recover` when `recoveryLink` is true; above the form, `notice` `notice.recovered` "The password has been changed. Sign in with the new password." when `recovered` is true |

### 6.4 Text from outside in pages

| Source | Where it appears | Rule |
|---|---|---|
| Audit detail values, display names, project names, target keys | Activity | `$this->e()` always, as text content; never in an attribute except a `title`-free `datetime` attribute holding the `at` timestamp, which the core read from its own column and the template checks against the `Timestamp` pattern before printing (else omitted) |
| Snapshot file names | Snapshots, restore | printed only as text; the restore link's `{snapshot}` path segment goes through `url()` with the name checked against `^[0-9]{8}T[0-9]{6}Z-[a-z-]+-[a-z2-7]{4}\z` in the controller |
| Licence texts | Licence page | escaped text content of one `pre.licence-text`; `TemplateRulesTest` allows `pre` only in that template |
| Plugin names, versions, licences | About | escaped plain text (P133) |
| Typed project name | Delete (after 422) | kept in the input's `value`, escaped |
| Typed username | Recovery (after 422) | kept, escaped; the code and passwords are never put back |

## 7. Strings

| File | Owner | Content |
|---|---|---|
| `strings/en/shell.php` | WP3 | `shell.nav.activity`, `shell.footer.about` |
| `strings/en/problems.php` | WP1, WP2 | Part 1 section 11.3 rows, exactly |
| `strings/en/errors.php` | WP0 | `error.unavailable.system-newer.*` (part 1 section 12.2) |
| `strings/en/snapshots.php` (new) | WP4 | Snapshots and restore (`docs/design/m7/snapshots.md`), `snapshots.reason.<reason>` for every `SnapshotReason` |
| `strings/en/projects.php` | WP4 | Project settings links; delete page and notice (`project-delete.md`) |
| `strings/en/audit.php` (new) | WP5 | Activity page; `audit.action.<action>` for every value of `AuditAction::ALL` (`audit.md`) |
| `strings/en/about.php` (new) | WP5 | About and licence pages (`about.md`) |
| `strings/en/recovery.php` (new) | WP5 | Recovery page (`recovery.md`) |
| `strings/en/account.php` | WP5 | `signin.recover`, `notice.recovered` |

Runtime prefixes (`TemplateRulesTest`, WP3): `snapshots.reason.`, `audit.action.`. Completeness of those keys is checked by the packages that write them: `SnapshotScreenTest` (WP4) asserts that every `SnapshotReason` value has a `snapshots.reason.` key, and `AuditScreenTest` (WP5) that every value of `AuditAction::ALL` has an `audit.action.` key. No `PENDING_*` list is needed: no WP3 partial prints a page package's key.

## 8. Stylesheet (M7-WP3)

`public/assets/css/app.css` gains (rules of M0 to M5 stay: rem with the px exceptions, tokens only, no colour literal, no `url()`, no `@import`, breakpoints in `em`; `docs/design/m7/components.md` has each rule):

- `.snapshot-table` (a `.data-table` variant: time, reason, size columns; size right-aligned with `.num`, as built for numbers).
- `.audit-filters` (a wrapping row of three selects and the Apply button; stacks under 40em).
- `.audit-list` and `.audit-entry` (one entry per `li`: a line with time and who, the sentence, the project, and `dl.audit-detail` in small text).
- `.licence-text` (`pre`: `white-space: pre-wrap`, `overflow-wrap: anywhere`, `font-size: var(--text-small)` as built token, max-width the settings width; scrolls inside its own box only when a single line cannot wrap).
- `.about-list` (definition list of components and plugins).
- `.recovery-steps` (ordered list with 12 px between items).
- `.link-danger` (warning ink on a link, `--warn`; text underlined; no new colour).
- Mid-word breaks (part 1 section 15.2): the nine `overflow-wrap: anywhere` rules are changed as listed there, with `min-width: 0` on the flex and grid children concerned. The record values, file names, and licence text keep `anywhere`.

## 9. Controllers and page data

Rules (M1 section 2.8 onward): no authorization decision in controllers; `AccessDenied` gives the 403 page, `NotFound` the 404 page, `ValidationFailed` re-renders the same page with 422 and the error summary, `ConfirmationRequired` re-renders the confirmation with 200 and a notice. Every POST answers 303 on success. Specific rules:

| Controller | Rule |
|---|---|
| `SnapshotController::index` | `SnapshotOperations::screen`. Shows the list; "Take a snapshot now" posts to `take` |
| `SnapshotController::take` | `take`; 303 to the list with flash `notice.snapshot_taken` "A snapshot was saved: {time}." |
| `SnapshotController::restoreForm` | Checks `{snapshot}` against the name pattern (else 404 without calling the core); `restoreScreen`; renders the counts |
| `SnapshotController::restore` | Same check; `restore`; 303 to the list with one flash key, chosen by the controller: `notice.snapshot_restored` "{project} is back to how it was on {date}. A snapshot of how it was just before was saved first." when `filesMissing` is 0, else `notice.snapshot_restored_missing` "{project} is back to how it was on {date}. A snapshot of how it was just before was saved first. {count|# file is|# files are} missing from that copy." (`docs/design/m7/snapshots.md` section 4); `Busy` reaches the busy page (503) |
| `ProjectController::deleteForm` | `deleteScreen`; renders counts, the archive link (`/projects/{p}/export`), the name field |
| `ProjectController::delete` | Reads `confirm_name` (string, else `''`) and `expected` (digits, else 0, as the trash pattern P184); `delete`; 303 to `/projects` with flash `notice.project_deleted` "{name} has been deleted." |
| `AuditController::index` | Passes the query string's `person`, `project`, `action`, `page` (strings only) to `AuditOperations::page`; never 422 (bad values are ignored by the core) |
| `AboutController::show` | `AboutOperations::about`; the "Source code" link target is the configured `source_url` (as built, from the view) |
| `AboutController::licence` | `{licence}` checked against `^[a-z0-9-]{1,40}\z` (else 404); `licence` |
| `RecoveryController::form` | 404 when `admin_recovery` is false; renders the page; `started` (from `?started=1`) shows the "code written" step text |
| `RecoveryController::start` | 404 when off; `RecoveryOperations::start`; 303 to `/recover?started=1` whatever happened |
| `RecoveryController::recover` | 404 when off; reads `username`, `code`, `password`, `password_again` (strings, else `''`); `recover`. `done`: 303 to `/sign-in?recovered=1`. `failed`: 422 with `problem.recovery.failed` in the summary, tied to the code field. `wait`: 429 with `Retry-After` and the sign-in throttle text (as built `signin.throttled` wording reused through `recovery.wait`). The body for `failed` is the same bytes for every cause, apart from the CSRF token |
| `SignInController` | `recovered` from `?recovered=1` as `signed-out` (as built); `recoveryLink` from the `admin_recovery` setting |

The `admin_recovery` setting reaches the controllers through the web `Context`: WP0 adds a constructor argument `bool $adminRecovery = true` and an accessor `adminRecovery(): bool` to `src/Web/Context.php`, and the kernel passes the checked configuration value (as built for `secureCookies`). Absent means true. Guest POSTs carry the guest CSRF token (as built for sign-in, P79).

## 10. Keyboard criterion

M1 section 7 to M5 section 9 apply.

### 10.1 Structural checks (T-KBD-5; WP4 in `SnapshotScreenTest` and `ProjectDeleteScreenTest`, WP5 in `AuditScreenTest`, `AboutScreenTest`, `RecoveryScreenTest`, each with the `HtmlAssertions` helpers as built; `KeyboardStructureTest.php` is not changed in part 1, and the whole-application run is WP10's)

Every M7 page: one `h1`; every control has a label (`HtmlAssertions::assertForm`, as built); no positive `tabindex`; the primary button is the only `.button-primary` (restore and delete use `.button-danger` as the one filled button, as the trash confirmation does); the error summary's links point at existing ids; the delete page's name field has `autocomplete="off"`, `spellcheck="false"`, and `aria-describedby` naming the help and, after 422, the error.

### 10.2 Scripted keyboard run (K-5, WP4 and WP5 evidence; run once by the later of the two and recorded in the ledger)

Key presses only, JavaScript on and then off: as Admin, Project settings, Snapshots, Take a snapshot now, the restore link of the newest snapshot, Restore this snapshot, back on the list with the notice read; Project settings, Delete this project, type the name, Delete for good, back on the project list; Activity, choose a person and an action, Apply, next page; footer About TiffinDB, a licence page, back. Signed out: Sign in page, the recovery link, Start recovery, read the code from the data folder (the one non-keyboard step, done by the script), type username, code, new password twice, Change the password, sign in with it.

## 11. Web tests and visual check

### 11.1 Tests

| Test | Package | Checks |
|---|---|---|
| T-WEB-80 | WP3 | Every route of section 4 exists with its access level (`RouterTest`, pending controllers as M4); every M7 page's `layout` is one `layout.php` has (a data-provider over the pages of 6.1) |
| T-WEB-81 | WP3 | The head of a signed-in page, a signed-out page, and an error page holds the two `link` elements of section 3, with `asset()` addresses; the page CSP holds `manifest-src 'self'`; the manifest is valid JSON with exactly the fields of section 3; the icon files equal the brand files |
| T-WEB-82 | WP3 | Activity tab for Admins only, current on `/admin/activity`; footer About link on signed-in pages only; Source code link on every page |
| T-WEB-83 | WP3 | `TemplateRulesTest`: the two `link` rules, `pre` only in the licence template, the new runtime prefixes |
| T-WEB-84 | WP3 | `HtaccessTest`: section 2.2 lines exactly |
| T-WEB-85 | WP4 | Snapshots page: Admin 200 with rows in order and reasons in words; Member 403; ungranted 404; take: 303, flash, one new manual snapshot; CSRF missing 403 |
| T-WEB-86 | WP5 | Activity: a display name `<b>"x"</b>`, a project name with `&` and quotes, a detail value with `<script>` and a right-to-left override: all appear escaped, no element from them; filters and paging keep each other in links; Member 403 |
| T-WEB-87 | WP4 | Restore: counts shown; a bad `{snapshot}` 404 without a core call; an unreadable snapshot 422; success 303 and the restored data; a held shared lock gives 503 with `Retry-After` |
| T-WEB-88 | WP4 | Delete: wrong name 422 with the field error and the typed text kept; grown counts give the confirmation again with the notice; success 303 to `/projects` with the notice and the project gone; Member 403 |
| T-WEB-89 | WP5 | About: Member sees no PHP or SQLite version; Admin sees both; plugin list; licence page escapes a licence text holding `</pre><script>`; unknown licence 404 |
| T-WEB-90 | WP4, WP5 | Every new POST refuses a missing or wrong CSRF token, a foreign or `null` `Origin`, a malformed `Origin` (WP0 rule), and `Sec-Fetch-Site: cross-site`, with 403 and no change |
| T-WEB-91 | WP5 | Recovery: with `admin_recovery` false the three routes are 404 and the sign-in page has no link; start answers the same 303 whether a file was written or not; `failed` bodies byte-identical (CSRF token removed) for unknown user, Member, inactive Admin, wrong code, expired code; `done` 303 to `/sign-in?recovered=1`; `wait` 429 with `Retry-After`; the code never appears in any response body |
| T-WEB-92 | WP5 | Sign-in page: link present by default; notice after `?recovered=1` |
| T-WEB-93 | WP3 | `app.css`: the classes of section 8 exist; the changed `overflow-wrap` rules; existing stylesheet rules pass |
| T-WEB-94 | WP4 | Project settings shows the two links for Admins (the page is Admin-only, as built) |
| T-WEB-95 | WP4, WP5 | T-KBD-5 (section 10.1), each package in its own screen tests |

### 11.2 Visual check (V-35 to V-40, run by WP5 after WP4 is merged, or by WP10 in part 2 if WP5 runs first; screenshots in the scratchpad)

At 1360 px and 390 px in four themes, and at 320 px with Largest text, against `docs/design/m7/`:

| Check | Screens |
|---|---|
| V-35 | Snapshots (empty, several rows), restore confirmation (with and without missing files), its 422 |
| V-36 | Delete a project (start, wrong name, grown counts) |
| V-37 | Activity (no filters, filtered, last page, empty result) |
| V-38 | About (Admin, Member), a licence page (long lines wrap; no sideways page scroll) |
| V-39 | Recovery (start, after start, failed, wait), sign-in with the link and the notice; the favicon request answers 200 `image/svg+xml` and the manifest request 200 |
| V-40 | The mid-word cases of part 1 section 15.2 at 320 px Largest: "Temporary" on the add-person page, the display settings' "sans-serif" card, sidebar table names; record in the ledger what still breaks inside a word |

## 12. Points for the maintainer

1. The Activity tab name (instead of "Audit log") follows the plain-language rule (ui 3); the page title is "Activity".
2. The recovery link on the sign-in page is visible to everyone. It reveals only that recovery exists, which the documentation says anyway; turning `admin_recovery` off removes it.
3. A restore deletes the attachment files the restored copy does not name (ADR 0022 decision 2); the confirmation page states how many. Keeping them would need snapshots to keep files alive (part 1 section 21 item 4).
4. No PNG icon (ADR 0021 decision 8).

## 13. File ownership for the web packages

| Package | Files |
|---|---|
| WP0 | `src/Web/Request.php`, `Kernel.php`, `Context.php` (`adminRecovery`), `Response.php` (extension rule), `Controller/SetupController.php` (probe port), `strings/en/errors.php`, their tests (part 1 section 17) |
| WP3 | `src/Web/routes.php`, `Shell.php`, `SecurityHeaders.php`; `templates/layout.php`, `templates/partials/footer.php`, `top-bar.php`; `public/assets/css/app.css`, `public/.htaccess`, `public/assets/icons/*`, `public/assets/app.webmanifest`; `strings/en/shell.php`; `tests/web/TemplateRulesTest.php`, `ShellTest.php`, `SecurityHeadersTest.php`, `RouterTest.php`, `StringsTest.php`; `tests/repo/HtaccessTest.php`; new `tests/design/IconFilesTest.php` |
| WP4 | `src/Web/Controller/SnapshotController.php`, `ProjectController.php`; `templates/pages/snapshots/*`, `templates/pages/projects/delete.php`, `settings.php`; `strings/en/snapshots.php`, `projects.php`; `tests/web/SnapshotScreenTest.php`, `ProjectDeleteScreenTest.php` |
| WP5 | `src/Web/Controller/AuditController.php`, `AboutController.php`, `RecoveryController.php`, `SignInController.php`; `templates/pages/audit/*`, `about/*`, `recover/*`, `sign-in.php`; `strings/en/audit.php`, `about.php`, `recovery.php`, `account.php`; `tests/web/AuditScreenTest.php`, `AboutScreenTest.php`, `RecoveryScreenTest.php`, `SignInScreenTest.php` |

WP4 and WP5 share no file. `StringsTest.php` and `KeyboardStructureTest.php` are WP3's in part 1 (WP3 changes only `StringsTest.php`, for its own shell keys) and WP10's in part 2.

## 14. As-built notes

To be appended by the M7 exit audit (M7-X).
