# M1 screens built from mock-ups: sign-in, project list, own password

Status: design for milestone M1. Sign-in and the project list follow the accepted mock-ups `docs/design/model-ui/sign-in.html` and `projects.html`; this document says how they are built from the application's classes and partials (`components.md`, `docs/specs/M1-screens.md` section 4), and lists every string. "Change your password" has no mock-up and is designed here. Deviations from the mock-ups are listed in `M1-screens.md` section 8.3.

All data shown is synthetic.

## 1. Conventions in this document

- "Element order" is reading order and DOM order. Indentation shows nesting.
- Strings are given as `key`: text. Keys belong to the strings file named in each section. Shared keys (`shell.*`, `form.*`, `error.*`, `notice.*`, `date.*`, `wait.*`, `problem.*`) are in `M1-screens.md` section 5 and part 1 section 12.
- Ids are literal (template rule, `M1-screens.md` section 4.3).

## 2. Sign in

| | |
|---|---|
| Purpose | Let a person with an account sign in |
| Who | Anyone, once TiffinDB is installed; a signed-in person is sent to `/projects` |
| URL | `GET /sign-in` shows; `POST /sign-in` submits; `GET /sign-in?signed-out=1` after signing out |
| Shell | Plain shell (lockup header, centred `main.page.page-plain`, centred footer) |
| Template | `templates/pages/sign-in.php` (WP10) |
| Strings | `strings/en/account.php` |
| Page title | `signin.title` (and `form.errors.page_title` when there is an error) |

### 2.1 Elements

1. `header.lockup-bar` (partial `lockup`): brand link to `/` with `aria-label` = `shell.brand.label`, mark 64 px, wordmark 40 px.
2. `main#main.page.page-plain`
   1. Only with `?signed-out=1`: partial `notice`, tone info, `notice.signed_out` ("You have signed out."), `role="status"`, inside a wrapper `div.form-narrow` so it lines up with the card.
   2. `form.form.form-card.form-narrow` `method="post"` `action` = `/sign-in`
      1. Partial `csrf`.
      2. `h1`: `signin.title` "Sign in".
      3. Only after a failed attempt: `div.notice.notice-warn` with `role="alert"` and `id="signin-error"`, containing `p`: `signin.error.invalid` "That username and password do not match. Check them and try again." or, when throttled, `problem.throttled` "Too many tries. Wait {wait}, then try again."
      4. `div.field`: `label.label` for `signin-username`: `signin.username` "Username"; `input#signin-username.input` `type="text"` `name="username"` `autocomplete="username"` `autocapitalize="none"` `spellcheck="false"` `required="required"`, value = the submitted username after a failure; `aria-describedby="signin-error"` when the error is shown.
      5. `div.field`: `label.label` for `signin-password`: `signin.password` "Password"; `input#signin-password.input` `type="password"` `name="password"` `autocomplete="current-password"` `required="required"` (never printed back); partial `password-toggle` (singular: "Show password").
      6. `button.button.button-primary.button-large.button-block` `type="submit"`: `signin.submit` "Sign in".
      7. `p.form-note`: `signin.reset_note` "Forgotten your password? Ask the person who looks after this TiffinDB to reset it."
3. `footer.site-footer.site-footer-centered` (M0 footer).

The page sets `script` to `show-password`.

### 2.2 States

| State | Status | What changes |
|---|---|---|
| Empty | 200 | As above, no notice, empty username |
| Signed out | 200 | Notice 2.1.2.1 |
| Wrong username or password (any reason, including an account that cannot sign in) | 422 | Error notice with `signin.error.invalid`; username kept; password empty; page title "Error: Sign in - TiffinDB" |
| Too many tries | 429 with `Retry-After` = the wait in seconds | Error notice with `problem.throttled`; username kept |
| Form expired (CSRF) | 403 | Kernel page `error.csrf.*` with a link to the start page, which leads back here |
| Success | 303 | To `/projects`, or to `/account/password` when the person must change their password |

### 2.3 Behaviour

- Keyboard: Tab order is skip link, brand link, Username, Password, Show password (with JavaScript only), Sign in, Source code link. Enter in either field submits.
- Screen reader: the error notice is announced (`role="alert"`) and each input is described by it. The `h1` is the first heading.
- Without JavaScript: identical except the "Show password" row stays hidden.
- 390 px: the card spans the width less 16 px each side; the lockup stays on one line (mark 64 px and 40 px wordmark fit in 358 px); the footer wraps and stays centred.

## 3. Project list

| | |
|---|---|
| Purpose | Start page after sign-in: every project the person can use |
| Who | Every signed-in person. Admins see every project and Admin-only controls; Members see projects shared with them |
| URL | `GET /projects` |
| Shell | Signed-in shell, `nav` = `projects` |
| Template | `templates/pages/projects/index.php` (WP11) |
| Strings | `strings/en/projects.php` |
| Page title | `projects.title` |

### 3.1 Elements

`main#main.page`:

1. Flash notice when present (partial `notice`, info).
2. `div.page-head`
   1. `div`: `h1` `projects.title` "Your projects"; `p.lead` `projects.lead` "A project is one database: its tables, forms, and files."
   2. Admins only: `div.page-actions` with `a.button.button-primary` to `/projects/new`: the "+" icon (`svg.button-icon`, `aria-hidden`) and `projects.new` "New project".
3. When there are projects: `ul.project-grid`, one `li.card.project-card` per project, ordered by name:
   1. `h2.project-name` with a link to `/projects/{key}` holding the project name.
   2. `dl.facts`:
      - `dt` `projects.fact.tables` "Tables", `dd` the table names joined by ", " in table order, or `projects.no_tables` "None yet".
      - `dt` `projects.fact.records` "Records", `dd` the live record count with thousands separators ("1,234").
      - `dt` `projects.fact.changed` "Last changed", `dd` `date.last_change` "{date}, by {name}" (date from `Format::relativeDate`: "Today", "Yesterday", or "3 Nov 2026"), or `date.last_change_unknown` "{date}" when the person is unknown, or `projects.never_changed` "Not yet".
      - Admins only: `dt` `projects.fact.shared` "Shared with", `dd` `count.people.one` "1 person" or `count.people.many` "{count} people", or `projects.shared.none` "Admins only".
   3. `div.card-links.link-row`: `a.link-strong` to `/projects/{key}`: `projects.open` "Open"; Admins only: `a` to `/projects/{key}/sharing`: `projects.sharing` "Sharing".
   4. A project whose file cannot be opened: the name is plain text (no link), the `dl` is replaced by `p.notice.notice-warn` with `projects.unavailable` "TiffinDB cannot open this project's file. The person who looks after this TiffinDB can check the data folder.", and only the Sharing link remains (Admins).
4. When there are none: `p.notice` with, for Admins, `projects.empty.admin` "There are no projects yet. Select New project to make the first one."; for Members, `projects.empty.member` "No projects have been shared with you yet. Ask the person who looks after this TiffinDB to share one with you."
5. Admins only: `p.notice.notice-muted`: `projects.admin_note` "Signed in as an Admin. Members see only the projects shared with them, and do not see New project, Sharing, or People."

### 3.2 States

| State | What changes |
|---|---|
| Admin, projects | As above (matches the mock-up, with the deviations of `M1-screens.md` 8.3) |
| Member, projects | No New project, no Shared with, no Sharing link, no Admin note |
| No projects | Item 4 instead of the grid |
| After creating a project, sharing, signing in after setup | Flash notice: `notice.setup.done`, `notice.sharing_saved` |
| A project file missing | Its card as 3.1.3.4; others unaffected |

### 3.3 Behaviour

- Keyboard: top bar, then New project, then each card's name link, Open, Sharing, in card order.
- Screen reader: each card is a list item with an `h2`; the facts are a description list. "Open" links have the accessible name `projects.open.label` "Open {name}" through `aria-label`, and "Sharing" `projects.sharing.label` "Sharing for {name}", so a list of links is unambiguous.
- Without JavaScript: identical.
- 390 px: `page-head` stacks the title above the button; one card per row; card links wrap; nothing scrolls sideways.

## 4. Change your password

| | |
|---|---|
| Purpose | Change one's own password; required after an Admin sets a temporary one |
| Who | Every signed-in person |
| URL | `GET /account/password`, `POST /account/password` |
| Shell | Signed-in shell, `nav` = `account` (the user chip gets `aria-current="page"`) |
| Template | `templates/pages/account/password.php` (WP10) |
| Strings | `strings/en/account.php` |
| Page title | `account.password.title` |

### 4.1 Elements

`main#main.page`:

1. Error summary at 422.
2. When the person must change their password: partial `notice`, info, `account.password.forced` "Before you go on, choose your own password. The one you were given was only for signing in the first time."
3. `div`: `h1` `account.password.title` "Change your password"; `p.lead` `account.password.lead` "Choose a password that only you know."
4. `form.form.form-card.form-narrow` `method="post"` `action` = `/account/password`:
   1. Partial `csrf`.
   2. `div.field`: label for `account-current`: `account.password.current` "Current password"; `p.error-text#account-current-error` when needed; `input#account-current.input` `type="password"` `name="current"` `autocomplete="current-password"` `required="required"`.
   3. `div.field`: label for `account-new`: `account.password.new` "New password"; `p.help#account-new-help`: `account.password.new_help` "At least 10 characters. A few unrelated words make a good password."; `p.error-text#account-new-error` when needed; `input#account-new.input` `type="password"` `name="password"` `autocomplete="new-password"` `required="required"` `aria-describedby="account-new-help"` (plus the error id).
   4. `div.field`: label for `account-repeat`: `account.password.repeat` "Type the new password again"; `p.error-text#account-repeat-error` when needed; `input#account-repeat.input` `type="password"` `name="repeat"` `autocomplete="new-password"` `required="required"`.
   5. Partial `password-toggle` (plural: "Show passwords").
   6. `div.form-actions`: `button.button.button-primary.button-large` `account.password.submit` "Change password"; unless forced, `a` to `/projects`: `form.cancel` "Cancel".

The page sets `script` to `show-password`. Field map for core problems: `current` to `account-current`, `password` to `account-new`. The web layer adds `account.password.mismatch` "The two new passwords are different." on `account-repeat` when `password` and `repeat` differ (checked before calling the core; the core is not called then).

### 4.2 States

| State | Status | What changes |
|---|---|---|
| Normal | 200 | No forced notice; Cancel shown |
| Forced | 200 | Forced notice; no Cancel; every other page redirects here until done |
| Errors | 422 | Summary and field errors; no password printed back |
| Too many wrong current passwords | 422 | `problem.throttled` at Current password |
| Success | 303 | To `/projects`, flash `notice.password_changed` "Your password has been changed."; the session cookie is replaced (the core ended every session and started a new one) |

### 4.3 Behaviour

- Keyboard: fields in order, Show passwords, Change password, Cancel.
- Screen reader: help text and errors are tied with `aria-describedby`; the error summary is announced.
- Without JavaScript: identical except "Show passwords" stays hidden.
- 390 px: the card spans the width; buttons wrap.

## 5. Strings

`strings/en/account.php` (WP10):

| Key | Text |
|---|---|
| `signin.title` | Sign in |
| `signin.username` | Username |
| `signin.password` | Password |
| `signin.submit` | Sign in |
| `signin.reset_note` | Forgotten your password? Ask the person who looks after this TiffinDB to reset it. |
| `signin.error.invalid` | That username and password do not match. Check them and try again. |
| `account.password.title` | Change your password |
| `account.password.lead` | Choose a password that only you know. |
| `account.password.forced` | Before you go on, choose your own password. The one you were given was only for signing in the first time. |
| `account.password.current` | Current password |
| `account.password.new` | New password |
| `account.password.new_help` | At least 10 characters. A few unrelated words make a good password. |
| `account.password.repeat` | Type the new password again |
| `account.password.mismatch` | The two new passwords are different. |
| `account.password.submit` | Change password |

`strings/en/projects.php` (WP11), project list part (the new-project part is in `new-project.md`):

| Key | Text |
|---|---|
| `projects.title` | Your projects |
| `projects.lead` | A project is one database: its tables, forms, and files. |
| `projects.new` | New project |
| `projects.fact.tables` | Tables |
| `projects.fact.records` | Records |
| `projects.fact.changed` | Last changed |
| `projects.fact.shared` | Shared with |
| `projects.no_tables` | None yet |
| `projects.never_changed` | Not yet |
| `projects.shared.none` | Admins only |
| `projects.open` | Open |
| `projects.open.label` | Open {name} |
| `projects.sharing` | Sharing |
| `projects.sharing.label` | Sharing for {name} |
| `projects.unavailable` | TiffinDB cannot open this project's file. The person who looks after this TiffinDB can check the data folder. |
| `projects.empty.admin` | There are no projects yet. Select New project to make the first one. |
| `projects.empty.member` | No projects have been shared with you yet. Ask the person who looks after this TiffinDB to share one with you. |
| `projects.admin_note` | Signed in as an Admin. Members see only the projects shared with them, and do not see New project, Sharing, or People. |
| `count.people.one` | 1 person |
| `count.people.many` | {count} people |
