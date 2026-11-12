# M1 screen design: installer and requirement check

Status: design for milestone M1 (undesigned screen, ui-design-plan section 6.2). Logic: `docs/specs/M1-foundation.md` section 10; controller: `docs/specs/M1-screens.md` section 3.2. Implemented by M1-WP9.

## 1. Purpose and flow

Three steps, each one page, all at `/setup`:

| Step | Shown when | Page |
|---|---|---|
| 1. Check this server | No data folder yet (`NeedsDataFolder`) | `GET /setup` |
| 2. Make the data folder safe | After "Make the data folder" when the result is not safe | Response to `POST /setup/data-folder` |
| 3. Create the first Admin | Data folder ready (`NeedsAdmin`) | `GET /setup` |

A safe result in step 2 redirects straight to step 3, so most hosts see steps 1 and 3 only. After step 3 the person is signed in as the first Admin and lands on the project list. Once installed, `/setup` is 404.

| | |
|---|---|
| Who | Anyone who reaches the site before setup is complete (step 3 then needs the setup code from the server's files) |
| Shell | Plain shell with a wide main (the pages set `wide` to true): `header.lockup-bar`, `main#main.page` (not `page-plain`), centred footer |
| Templates | `templates/pages/setup/check.php` (step 1), `templates/pages/setup/data-folder.php` (step 2), `templates/pages/setup/admin.php` (step 3). The step indicator is written in each of the three templates from the same controller data (`steps`: list of `{number, state}` with state `done`, `now`, or `later`), because shared partials belong to WP8 |
| Strings | `strings/en/setup.php` |
| Page title | `setup.title` "Set up TiffinDB" in every step (`form.errors.page_title` with errors) |

## 2. Shared top of every step

`main#main.page`:

1. `div`: `h1` `setup.title` "Set up TiffinDB"; `p.lead` `setup.lead` "Three short steps. Nothing is saved until step 2 makes the data folder."
2. `ol.steps` with `aria-label` = `setup.steps.label` "Setup steps", three `li.step`:
   - Text line 1: `setup.step.number` "Step {number}", or `setup.step.done` "Step {number}, done" for a finished step, or `setup.step.now` "Step {number}, now" for the current step (`aria-current="step"`).
   - `span.step-name`: `setup.step.1` "Check this server", `setup.step.2` "Make the data folder safe", `setup.step.3` "Create the first Admin".

## 3. Step 1: Check this server

After the shared top:

1. `section.section` with `aria-labelledby="setup-check-title"`:
   1. `h2#setup-check-title.section-title`: `setup.check.title` "Step 1: Check this server".
   2. `p`: `setup.check.intro` "TiffinDB has checked what this web server offers."
   3. `div.table-wrap` > `table.data-table`:
      - `caption.visually-hidden`: `setup.check.caption` "Server checks".
      - `thead`: `th` `setup.check.col.check` "Check", `th` `setup.check.col.result` "Result", `th` `setup.check.col.meaning` "What this means".
      - One row per requirement, in the order of part 1 section 10.2: `th scope="row"` the check's name, `td` with `span.status-word` (plus `.status-failed` for failures) holding the result word, `td` the explanation.
2. When nothing failed: `form.form` `method="post"` `action` = `/setup/data-folder`: partial `csrf`; `p` `setup.check.next` "Next, TiffinDB makes its data folder and checks that it cannot be opened from the web."; `div.form-actions` with `button.button.button-primary.button-large`: `setup.check.continue` "Make the data folder".
3. When something failed: `div.notice.notice-warn` `role="alert"`: `p` `setup.check.fix` "Fix the checks marked Needs fixing, then check again. Your host's control panel or support can help with the PHP settings."; then `div.form-actions` with `a.button.button-primary` to `/setup`: `setup.check.again` "Check again".

Result words: `setup.result.ok` "Ready", `setup.result.warning` "Works, with a note", `setup.result.failed` "Needs fixing".

Check names and explanations (runtime keys, prefix `setup.check.`):

| Id | Name key and text | Explanation keys and text |
|---|---|---|
| `php` | `setup.check.php` "PHP version" | ok `setup.check.php.ok` "PHP {version}. TiffinDB needs 8.3 or later."; failed `setup.check.php.failed` "PHP {version} is too old. Ask your host to switch this site to PHP 8.3 or later." |
| `sqlite` | `setup.check.sqlite` "SQLite database support" | ok `setup.check.sqlite.ok` "SQLite {version} is available."; `setup.check.sqlite.missing` "PHP's SQLite support (pdo_sqlite) is not turned on. Ask your host to turn it on."; `setup.check.sqlite.old` "SQLite {version} is too old. TiffinDB needs 3.35.5 or later." |
| `zip` | `setup.check.zip` "Zip archives" | ok `setup.check.zip.ok` "Available. Needed to save and bring in whole projects."; failed `setup.check.zip.failed` "PHP's zip support is not turned on. Ask your host to turn it on." |
| `xmlreader` | `setup.check.xmlreader` "Reading spreadsheet files" | ok `setup.check.xmlreader.ok` "Available. Needed to bring in XLSX spreadsheets."; failed `setup.check.xmlreader.failed` "PHP's XMLReader support is not turned on. Ask your host to turn it on." |
| `random` | `setup.check.random` "Secure random numbers" | ok `setup.check.random.ok` "Available."; failed `setup.check.random.failed` "PHP cannot make secure random numbers on this server. TiffinDB cannot run safely without them." |
| `passwords` | `setup.check.passwords` "Password protection" | ok `setup.check.passwords.argon2id` "Passwords will be stored with Argon2id."; warning `setup.check.passwords.bcrypt` "Passwords will be stored with bcrypt. This is safe. Argon2id is better and is used where PHP offers it." |
| `https` | `setup.check.https` "Secure connection (HTTPS)" | ok `setup.check.https.ok` "This page was loaded over HTTPS."; warning `setup.check.https.warning` "This page was not loaded over HTTPS, so passwords could be read by others on the network. Ask your host to turn on HTTPS, then open this page with https:// at the start." |
| `data` | `setup.check.data` "A place for the data folder" | ok `setup.check.data.outside` "TiffinDB will keep its data in {label}, outside the web site."; ok `setup.check.data.in-tree` "TiffinDB will keep its data in the folder {label} inside the TiffinDB folder, protected by a server rule. Step 2 tests that the rule works."; failed `setup.check.data.failed` "TiffinDB cannot make its data folder at {label}. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php." |

`{label}` is a relative label such as `../tiffindb-data` or `data`, never an absolute path.

## 4. Step 2: Make the data folder safe (only when not safe)

Status 409. After the shared top (step 2 current, step 1 done):

1. `div.notice.notice-warn` `role="alert"`: `h2.notice-title` and `p` by outcome:

| Outcome | Title | Body |
|---|---|---|
| exposed | `setup.data.exposed.title` "Your data folder can be opened from the web" | `setup.data.exposed.body` "Anyone could download TiffinDB's data from {label}. Setup stops here until this is fixed." |
| inconclusive | `setup.data.unclear.title` "TiffinDB could not test the data folder" | `setup.data.unclear.body` "TiffinDB asked this web server for a test file in {label}, to make sure the folder cannot be opened from the web, but did not get a clear answer. To be safe, setup stops here." |
| failed, `setup.data.no-place` | `setup.data.failed.title` "TiffinDB could not make its data folder" | `setup.data.no-place` "There is no folder TiffinDB can write to. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php." |
| failed, `setup.data.not-empty` | `setup.data.failed.title` | `setup.data.not-empty` "The folder {label} already exists and has files in it that TiffinDB did not put there. Empty it, or set data_dir in config/local.php to another folder." |

2. For exposed and inconclusive: `section.section` `aria-labelledby="setup-fix-title"`: `h2#setup-fix-title.section-title` `setup.fix.title` "How to fix it"; `ol` with:
   - `setup.fix.root` "Best: make the public folder inside the TiffinDB folder the root of your web site, so that the data folder is outside it. Hosting control panels call this the document root or web directory."
   - `setup.fix.htaccess` "Or: make sure the web server reads .htaccess files in the TiffinDB folder. Ask your host if you are not sure."
   - `setup.fix.local` "Or: put the data folder outside the web site, and write its full path as data_dir in config/local.php."
3. `form.form` `method="post"` `action` = `/setup/data-folder`: partial `csrf`; `div.form-actions` with `button.button.button-primary.button-large` `setup.data.test_again` "Test again". (For failed outcomes the button text is `setup.check.again` "Check again" and the form is a link to `/setup` instead.)

## 5. Step 3: Create the first Admin

After the shared top (steps 1 and 2 done, step 3 current):

1. Error summary at 422.
2. Partial `notice`, info: `setup.data.ready` "Step 2 is done: the data folder is ready, and it cannot be opened from the web."
3. `form.form.form-card.form-wide` `method="post"` `action` = `/setup/admin`:
   1. Partial `csrf`.
   2. `h2.section-title`: `setup.admin.title` "Step 3: Create the first Admin".
   3. `p`: `setup.admin.intro` "The first Admin can add other people, create projects, share them, and change tables and fields."
   4. `div.field`: label for `setup-code`: `setup.admin.code` "Setup code"; `p.help#setup-code-help`: `setup.admin.code_help` "To show that you look after this server, open the file {location} with your hosting file manager or FTP program, and copy the code from it."; error `p.error-text#setup-code-error`; `input#setup-code.input.input-medium` `type="text"` `name="setup_code"` `autocomplete="off"` `autocapitalize="characters"` `spellcheck="false"` `required="required"` (never printed back). `{location}` is `Installer::setupCodeLocation()`, for example `../tiffindb-data/setup-code.txt`.
   5. `div.field`: label for `setup-name`: `setup.admin.name` "Your name"; `p.help#setup-name-help`: `setup.admin.name_help` "Shown to others, for example on the project list."; error; `input#setup-name.input` `name="display_name"` `autocomplete="name"` `required="required"`.
   6. `div.field`: label for `setup-username`: `setup.admin.username` "Username"; `p.help#setup-username-help`: `setup.admin.username_help` "Used to sign in. Letters, numbers, dots, dashes, and underscores."; error; `input#setup-username.input.input-medium` `name="username"` `autocomplete="username"` `autocapitalize="none"` `spellcheck="false"` `required="required"`.
   7. `div.field`: label for `setup-password`: `setup.admin.password` "Password"; `p.help#setup-password-help`: `setup.admin.password_help` "At least 10 characters. A few unrelated words make a good password."; error; `input#setup-password.input` `type="password"` `name="password"` `autocomplete="new-password"` `required="required"`.
   8. `div.field`: label for `setup-repeat`: `setup.admin.repeat` "Type the password again"; error; `input#setup-repeat.input` `type="password"` `name="repeat"` `autocomplete="new-password"` `required="required"`.
   9. Partial `password-toggle` (plural).
   10. `div.form-actions`: `button.button.button-primary.button-large` `setup.admin.submit` "Create Admin and finish".

The page sets `script` to `show-password`. Field map: `setup_code` to `setup-code`, `display_name` to `setup-name`, `username` to `setup-username`, `password` to `setup-password`. The web layer adds `setup.admin.mismatch` "The two passwords are different." on `setup-repeat` when `password` and `repeat` differ, and then does not call the core.

Success: 303 to `/projects` with the session cookie set, `tdb_csrf` deleted, and flash `notice.setup.done` "TiffinDB is ready. Select New project to make your first project."

On every `GET /setup` in this state the controller first calls `Installer::ensureSetupCode()`, so a deleted code file is replaced and the location shown always holds a code.

## 6. States summary

| State | Status | Page |
|---|---|---|
| Not installed, all checks pass | 200 | Step 1 with "Make the data folder" |
| Not installed, a check fails | 200 | Step 1 with the fix notice and "Check again" |
| Data folder exposed | 409 | Step 2, exposed |
| Probe inconclusive (no answer, redirect, server error, missing server values) | 409 | Step 2, inconclusive |
| Data folder cannot be made | 409 | Step 2, failed |
| Data folder ready | 200 | Step 3 |
| Step 3 errors, wrong setup code, too many tries | 422 | Step 3 with the summary; code and passwords empty; other values kept |
| Installed | 404 | Kernel page |
| Form expired | 403 | Kernel page `error.csrf.*` |

## 7. Behaviour

- Keyboard: skip link, brand, then the page in reading order; the checks table has row headers; every action is a button or link reachable by Tab.
- Screen reader: results are words ("Ready", "Works, with a note", "Needs fixing"), never colour alone; the step indicator marks the current step with `aria-current="step"` and says "done" in text; warning notices use `role="alert"`.
- Without JavaScript: every step works; "Show passwords" stays hidden.
- 390 px: the step boxes wrap one per line (`flex: 1 1 200`); the checks table scrolls inside its `table-wrap`; the form card spans the width.

## 8. The exposure test, and how it is tested without a network

Described for the maintainer and reviewers; the logic is part 1 section 10.4.

- The test runs only when the data folder could be inside the web site. When it is outside the document root, nothing is requested.
- TiffinDB writes a random file into the data folder, asks this same web server for it at the addresses where it would appear, and deletes the file. If the server returns the file's content, the folder is exposed and setup stops. If every address is refused (403, 404, or a page without the content), the folder is safe. Anything else (no answer, a redirect, a server error) is treated as "could not test", which also stops setup.
- The request goes to the address and port that received the current request (`SERVER_ADDR`, `SERVER_PORT`), with the site's own `Host` name; it is not a call to any other site.
- In the test suite, the HTTP part is an interface (`HttpProbe`) with a fake that returns scripted answers (T-INS-4, T-INS-5), and the real implementation is tested with a local socket pair created inside the test process (T-INS-8). No test opens a network connection or starts a server.
- Apache is not available in the build environment, so the deny rule's effect on a real Apache server is reasoned, not executed; the maintainer's host check covers it.

## 9. Strings (`strings/en/setup.php`)

Every key named above with the text given there, plus:

| Key | Text |
|---|---|
| `setup.title` | Set up TiffinDB |
| `setup.lead` | Three short steps. Nothing is saved until step 2 makes the data folder. |
| `setup.steps.label` | Setup steps |
| `setup.step.number` | Step {number} |
| `setup.step.done` | Step {number}, done |
| `setup.step.now` | Step {number}, now |
| `setup.step.1` | Check this server |
| `setup.step.2` | Make the data folder safe |
| `setup.step.3` | Create the first Admin |
| `setup.result.ok` | Ready |
| `setup.result.warning` | Works, with a note |
| `setup.result.failed` | Needs fixing |
| `setup.admin.mismatch` | The two passwords are different. |
| `notice.setup.done` | TiffinDB is ready. Select New project to make your first project. |
