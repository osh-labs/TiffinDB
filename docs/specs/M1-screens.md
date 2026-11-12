# M1 design spec: web layer and screens

Status: design spec for milestone M1, part 2 of 2. Part 1, `docs/specs/M1-foundation.md`, has the core, the test plan for it, the provisional decisions, and the work-package list; this part has the web layer those packages build on, the file ownership of the web packages (section 10), and how screens are checked against the mock-ups (section 8). Screen-by-screen designs, with every UI string, are in `docs/design/m1/`.

The M0 request pipeline (`docs/specs/M0-scaffolding.md` sections 6 to 9, and its as-built notes) is extended, not replaced. Everything M0 specified stays true unless a section below changes it.

## 1. What changes from M0

| Area | M0 | M1 |
|---|---|---|
| Methods | GET, HEAD | GET, HEAD, POST (POST always checked for CSRF) |
| Request input | path, query | also POST fields, cookies, and a few server values |
| Responses | status, headers, body | also cookies and 303 redirects |
| Routes | `[method, pattern, handler]` | `[method, pattern, handler, access]` |
| Controllers | constructed with `View` | constructed with `Context` |
| Appearance | query parameters (`appearance_preview` true) | stored per user; defaults before sign-in; `appearance_preview` false |
| `/` | home page | redirect to setup, sign-in, or the project list |
| Strings | `strings/en.php` | `strings/en.php` combining `strings/en/*.php` (P40) |
| Script | none | `public/assets/js/show-password.js` |

## 2. Request pipeline

### 2.1 `Request`

New read-only properties, all filled by `Request::fromGlobals(array $server, array $get, array $post, array $cookies): self`. `fromServer($server, $query)` stays and calls `fromGlobals($server, $query, [], [])`. The constructor gains the same properties as optional named parameters with safe defaults, for tests.

| Property | Source and rule |
|---|---|
| `post` | `$_POST` as given (values may be strings or arrays) |
| `cookies` | `$_COOKIE` as given |
| `secure` | True when `HTTPS` is set and not `off` (ASCII case-insensitive), or `REQUEST_SCHEME` is `https`. Forwarding headers are ignored |
| `clientAddress` | `REMOTE_ADDR` when it is a string of at most 64 characters, else `''` |
| `secFetchSite` | `HTTP_SEC_FETCH_SITE` when it is one of `cross-site`, `same-site`, `same-origin`, `none`, else null |
| `host` | `HTTP_HOST` when it matches `^[A-Za-z0-9.-]+(:\d{1,5})?$` or `^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$`, else null |
| `serverAddress` | `SERVER_ADDR` when it is a valid IP address (`filter_var(FILTER_VALIDATE_IP)`), else null |
| `serverPort` | `SERVER_PORT` when it is digits in 1 to 65535, else null |
| `documentRoot` | `realpath(DOCUMENT_ROOT)` when that resolves, else null |

Helpers:

- `input(string $name): string` returns the POST value when it is a string, else `''`. It never trims; the core trims.
- `inputList(string $name): array` returns the POST value when it is a list of strings (other elements dropped), else `[]`.
- `cookie(string $name): ?string` returns the cookie when it is a string, else null.
- `isPost(): bool`.

`Request` stays immutable. Nothing outside `Request::fromGlobals`, called from `public/index.php`, reads a superglobal.

### 2.2 `Response`, `Cookie`, redirects, `Fallback`

```php
final class Cookie
{
    public function __construct(
        public readonly string $name,        // ^[a-z_]{1,32}$
        public readonly string $value,       // ^[A-Za-z0-9_-]*$, at most 128 characters
        public readonly string $path,        // base path + '/', checked like a base path
        public readonly bool $secure,
        public readonly string $sameSite,    // 'Lax' | 'Strict'
        public readonly ?int $maxAge,        // seconds; null for a browser-session cookie; 0 deletes
    ) {}
    public function header(): string;        // 'name=value; Path=/; Max-Age=N; Secure; HttpOnly; SameSite=Lax'
}
```

Every cookie is `HttpOnly`. `Max-Age` is omitted when null; when 0 the value is empty. `Response::withCookie(Cookie $c): self` adds a cookie (a later cookie with the same name replaces an earlier one). `send()` emits each as `Set-Cookie` with `header($line, false)`. `Response::redirect(string $location): self` returns status 303, `Location` set to `$location`, empty body; `$location` must start with `/` and not `//`, and contain no control characters or back-slashes (else `\InvalidArgumentException`); controllers build it with `UrlGenerator::to`.

`Fallback::send(string $body = self::BODY, bool $headRequest = false)` omits the body for HEAD; `Fallback::onShutdown()` passes `true` when `$_SERVER['REQUEST_METHOD']` is `HEAD` (ledger section 7 item).

### 2.3 Route table and access levels

`src/Web/routes.php` returns a list of `[method, pattern, [ControllerClass, 'method'], access]`. `Router` validates the fourth element against `AccessLevel` and `RouteMatch::found` carries it.

```php
enum AccessLevel: string
{
    case Setup = 'setup';   // only before installation is complete
    case Guest = 'guest';   // installed; a signed-in person is redirected to /projects
    case User = 'user';     // installed and signed in
    case Any = 'any';       // the kernel applies no gate (only "/")
}
```

The complete M1 table, in this order (first match wins, so literal segments such as `new` come before placeholders):

| Method | Pattern | Handler | Access |
|---|---|---|---|
| GET | `/` | `HomeController::show` | any |
| GET | `/setup` | `SetupController::show` | setup |
| POST | `/setup/data-folder` | `SetupController::dataFolder` | setup |
| POST | `/setup/admin` | `SetupController::admin` | setup |
| GET | `/sign-in` | `SignInController::form` | guest |
| POST | `/sign-in` | `SignInController::submit` | guest |
| POST | `/sign-out` | `SignInController::signOut` | user |
| GET | `/account/password` | `AccountController::passwordForm` | user |
| POST | `/account/password` | `AccountController::password` | user |
| GET | `/projects` | `ProjectController::index` | user |
| GET | `/projects/new` | `ProjectController::newForm` | user |
| POST | `/projects` | `ProjectController::create` | user |
| GET | `/projects/{project}` | `ProjectController::open` | user |
| GET | `/projects/{project}/settings` | `ProjectController::settingsForm` | user |
| POST | `/projects/{project}/settings` | `ProjectController::settings` | user |
| GET | `/projects/{project}/sharing` | `SharingController::form` | user |
| POST | `/projects/{project}/sharing` | `SharingController::save` | user |
| GET | `/projects/{project}/tables` | `TableController::index` | user |
| GET | `/projects/{project}/tables/new` | `TableController::newForm` | user |
| POST | `/projects/{project}/tables` | `TableController::create` | user |
| GET | `/projects/{project}/tables/{table}/fields` | `TableController::fields` | user |
| POST | `/projects/{project}/tables/{table}/settings` | `TableController::update` | user |
| GET | `/projects/{project}/tables/{table}/delete` | `TableController::deleteForm` | user |
| POST | `/projects/{project}/tables/{table}/delete` | `TableController::delete` | user |
| GET | `/projects/{project}/tables/{table}/fields/new` | `FieldController::newForm` | user |
| POST | `/projects/{project}/tables/{table}/fields` | `FieldController::create` | user |
| GET | `/projects/{project}/tables/{table}/fields/{field}` | `FieldController::editForm` | user |
| POST | `/projects/{project}/tables/{table}/fields/{field}` | `FieldController::update` | user |
| GET | `/projects/{project}/tables/{table}/fields/{field}/type` | `FieldController::typeForm` | user |
| POST | `/projects/{project}/tables/{table}/fields/{field}/type` | `FieldController::changeType` | user |
| POST | `/projects/{project}/tables/{table}/fields/{field}/move` | `FieldController::move` | user |
| GET | `/projects/{project}/tables/{table}/fields/{field}/delete` | `FieldController::deleteForm` | user |
| POST | `/projects/{project}/tables/{table}/fields/{field}/delete` | `FieldController::delete` | user |
| GET | `/admin/people` | `PeopleController::index` | user |
| GET | `/admin/people/new` | `PeopleController::newForm` | user |
| POST | `/admin/people` | `PeopleController::create` | user |
| GET | `/admin/people/{person}` | `PeopleController::show` | user |
| POST | `/admin/people/{person}` | `PeopleController::update` | user |
| POST | `/admin/people/{person}/password` | `PeopleController::password` | user |
| POST | `/admin/people/{person}/projects` | `PeopleController::access` | user |
| GET | `/admin/people/{person}/deactivate` | `PeopleController::deactivateForm` | user |
| POST | `/admin/people/{person}/deactivate` | `PeopleController::deactivate` | user |
| POST | `/admin/people/{person}/reactivate` | `PeopleController::reactivate` | user |

All controllers are in `TiffinDB\Web\Controller`. A route placeholder value that is not a valid key (`Keys::isValid`) is passed to the core anyway; the core answers `NotFound` (part 1 section 7.3). `/projects/{project}/tables/{table}` is reserved for M2 and not routed in M1.

### 2.4 Kernel

`new Kernel(array $config, string $appRoot, ?array $routes = null, ?Services $services = null)`. `$services` null means `new CoreServices($config, $appRoot)`. `handle(Request $request): Response`, in order:

1. Malformed request: 400 page.
2. Method not GET, HEAD, POST: 405 page, `Allow: GET, HEAD, POST`.
3. Route match: not found, 404 page; wrong method, 405 page with the route's `Allow`.
4. POST with `secFetchSite === 'cross-site'`: 403 page (`error.403`).
5. Installation state, from `Services::installState()`:
   - not `Installed` and access is not `setup` or `any`: 303 to `/setup`;
   - `Installed` and access is `setup`: 404 page.
6. When installed: resume the session from cookie `tdb_session` through `Services::resume` (the same behaviour as `AuthOperations::resume`). A cookie that does not resolve is deleted on the response (`Max-Age=0`).
7. Access `user` without a session: 303 to `/sign-in`. Access `guest` with a session: 303 to `/projects`.
8. A session whose person must change their password, on any `user` route other than `AccountController::passwordForm`, `AccountController::password`, and `SignInController::signOut`: 303 to `/account/password`.
9. CSRF for POST (section 2.5). Failure: 403 page with `error.csrf.title` and `error.csrf.body`.
10. Build `Context` and call the controller.
11. Map exceptions escaping the controller: `NotFound` 404 page; `AccessDenied` 403 page; `Busy` 503 page with `Retry-After: 5` and keys `error.busy.*`; `Unavailable` 503 page with keys `error.unavailable.<reason>.*`; any other `Throwable` the M0 500 handling. `ValidationFailed` and `ConfirmationRequired` must be caught by controllers; reaching the kernel is a bug (500).
12. On `setup` and `guest` routes without a session, the anonymous CSRF token (section 2.5) is created before step 10 when the cookie is missing or malformed, and the cookie is added to whatever response the request produces.
13. `Services::close()` (closes databases), then `SecurityHeaders::apply` exactly once (M0 rule).

Error pages keep the M0 shape (title, one sentence, link to the start page) and use the signed-in shell when a session exists, the plain shell otherwise.

### 2.5 CSRF

- Signed in: the expected token is `SessionRecord::$csrfToken`.
- Not signed in (setup and sign-in forms): the expected token is the value of cookie `tdb_csrf` (64 lower-case hex characters, `SameSite=Strict`, `HttpOnly`, `Secure` when the request is secure, browser-session lifetime, path = base path + `/`). The kernel creates it with `Csrf::generate` when missing or malformed, before the controller runs, so the page can print it.
- Every POST must carry the field `_csrf`; `Csrf::matches(expected, $request->post['_csrf'] ?? null)`.
- Templates print the token through the new view helper `$this->csrf()` (section 4.3), inside `<input type="hidden" name="_csrf" value="..." />`, as the first child of every `form` with `method="post"`.
- After sign-in, sign-out, and password changes the session and therefore the token change; pages always print the current one.

### 2.6 Sessions and cookies in the web layer

| Cookie | Value | Attributes |
|---|---|---|
| `tdb_session` | the session token (43 characters) | `HttpOnly`, `SameSite=Lax`, `Secure` when secure, `Path` base + `/`, `Max-Age` 2592000 (the absolute lifetime; the server enforces idle expiry) |
| `tdb_csrf` | 64 hex characters | `HttpOnly`, `SameSite=Strict`, `Secure` when secure, `Path` base + `/`, no `Max-Age` |

`secure` is the request's `secure` flag unless `config/local.php` sets `secure_cookies` to `true` or `false`.

Flash notices: controllers call `Context::flash(string $key, array $params = [])` before redirecting; the next page that renders the shell takes it (`Services::takeFlash`) and shows it with the `notice` partial (role `status`). Only signed-in pages have flash notices. The sign-in page shows "You have signed out." from a `?signed-out=1` query parameter (view state only; it changes nothing).

### 2.7 Appearance

`Appearance::fromSettings(array $row): self` maps a `user_settings` row (part 1 section 5.5) to the M0 classes: `theme`, `accent` (`teal` is the default), `typeface` (`inter` is the default; others map through the M0 `font` map), `text_size`, `line_spacing`, `reduce_motion` (1 gives `m-reduce`). A value outside the lists gives that setting's default. Before sign-in, `Appearance::default()`. `config/app.php` sets `appearance_preview` to `false`; the query-parameter path stays in `Appearance` (and its tests) for development use only when the flag is true, but no M1 page links to it.

### 2.8 `Services` and `Context`

```php
interface Services
{
    public function installState(): InstallState;
    public function installer(): Installer;          // \LogicException once installed
    public function operations(): Operations;        // \LogicException before installation
    public function probe(): HttpProbe;               // LoopbackHttpProbe in CoreServices
    /** Used by the kernel; implemented over Core\Auth (Authenticator, Sessions), not over Core\App. */
    public function resume(string $token): ?SignedIn;
    public function takeFlash(string $token): ?array;
    public function setFlash(string $token, string $key, array $params = []): void;
    public function close(): void;
}

final class CoreServices implements Services { /* builds the real objects lazily from config and app root */ }

final class Context
{
    public function __construct(/* request, view, services, ?SignedIn, url generator, csrf token, ?flash */) {}
    public function request(): Request;
    public function services(): Services;
    public function signedIn(): ?SignedIn;
    public function actor(): Actor;                  // \LogicException when nobody is signed in
    public function sessionToken(): ?string;
    public function csrfToken(): string;
    public function url(string $path, array $query = []): string;   // unescaped, from UrlGenerator::to
    public function redirect(string $path, array $query = []): Response;
    public function flash(string $key, array $params = []): void;
    /** Renders a page in the signed-in shell (or the plain shell before sign-in). */
    public function page(string $template, array $data, int $status = 200, string $nav = ''): Response;
    public function plainPage(string $template, array $data, int $status = 200): Response;
    public function withCookie(Response $r, Cookie $c): Response;
    public function sessionCookie(?IssuedSession $session): Cookie;  // null gives the deleting cookie
}
```

`CoreServices` builds, once per request: `SystemClock`, `SecureRandom`, `PasswordHasher`, `DataDirectory::locate`, `SystemDatabase::open`, `Operations`, `Installer`, and `LoopbackHttpProbe`. Tests give the kernel a `tests/web/fixtures/FakeServices.php` with scripted states (install state, a `SignedIn` for a given token, flash); its `operations()` and `installer()` throw unless a test supplies real ones, so WP7 does not depend on WP5. Later packages pass real objects built by `CoreFixture`.

Controllers receive `Context` in their constructor: `new $class(Context $context)` (the M0 kernel passed `View`). Controllers hold no business rules and no permission checks; they read input with `Request::input`, call one `Core\App` operation, and render or redirect. When an operation throws `ValidationFailed`, the controller renders the same page with status 422, the submitted values, and the problems (section 4.5).

### 2.9 Configuration and the front controller

- `config/app.php`: `appearance_preview` becomes `false`; the other keys are unchanged.
- `config/local.php` (optional, git-ignored; `.gitignore` gains `/config/local.php`, and T-REPO-1 checks it): returns an array with any of `data_dir` (absolute path string), `timezone` (an identifier accepted by `new \DateTimeZone`), `secure_cookies` (bool). Unknown keys or wrong types: the plain 500 fallback, like an invalid `config/app.php` (M0 as-built). It never holds secrets.
- `public/index.php` loads `config/local.php` when the file exists, merges the three keys over the defaults, and calls `Request::fromGlobals($server, $_GET, $_POST, $_COOKIE)`.

## 3. Controllers and screens

### 3.1 Controller responsibilities

| Controller | Screens (design document) | Core calls |
|---|---|---|
| `HomeController` | none: `show` redirects to `/setup`, `/sign-in`, or `/projects` according to install state and session | `Services::installState`, `Context::signedIn` |
| `SetupController` | `docs/design/m1/installer.md` | `Installer` methods; `Services::probe` |
| `SignInController` | Sign in (`sign-in-and-projects.md` section 2) | `AuthOperations::signIn`, `signOut` |
| `AccountController` | Change your password (`sign-in-and-projects.md` section 4) | `AuthOperations::changeOwnPassword` |
| `ProjectController` | Project list (`sign-in-and-projects.md` section 3), new project (`new-project.md`), project settings (`schema-editor.md` section 2) | `ProjectOperations` |
| `SharingController` | Sharing (`people-and-sharing.md` section 6) | `ProjectOperations::sharing`, `setSharing` |
| `PeopleController` | People screens (`people-and-sharing.md` sections 2 to 5) | `PeopleOperations` |
| `TableController` | Tables, new table, table fields, delete table (`schema-editor.md` sections 3 to 6) | `SchemaOperations`, `ProjectOperations::get` |
| `FieldController` | Add, edit, change type, delete field (`schema-editor.md` sections 7 to 10) | `SchemaOperations` |

`ProjectController::open` redirects to `/projects/{project}/tables` after `ProjectOperations::get` succeeds (P43).

### 3.2 Setup controller specifics

- `show`: renders the step for the current state (`NeedsDataFolder`: step 1, requirement check; `NeedsAdmin`: step 3, first Admin).
- `dataFolder` (POST): builds a `ProbeTarget` from the request (`host`, `serverAddress`, `serverPort`, `secure`, `documentRoot`, `basePath`), or null when any of the first three is null, and calls `prepareDataFolder($request->documentRoot, $target, $services->probe())`. `safe`: 303 to `/setup`. `exposed`, `inconclusive`, `failed`: renders step 2 with that outcome, status 409.
- `admin` (POST): `createFirstAdmin`; on success sets the session cookie, deletes `tdb_csrf`, flashes `notice.setup.done`, 303 to `/projects`; on `ValidationFailed`, step 3 again with 422.

## 4. Templates

### 4.1 Layout and shells

`templates/layout.php` renders one of two shells, chosen by the view from the data key `shell`:

- **Signed-in shell** (`shell` is a `Shell` object): skip link, top bar (section 4.2), `main`, footer. Used by every page after sign-in and by error pages when signed in.
- **Plain shell** (`shell` null): skip link, a `header.lockup-bar` holding the lockup (partial `lockup`: the larger mark and wordmark, linking to `/`), `main`, and the footer with `site-footer-centered`. Used by sign-in, setup, and error pages before sign-in. `main` is `main#main.page.page-plain` (centred column, for sign-in and error pages) unless the page sets `data['wide']` to true, which gives `main#main.page` (setup). The two class strings are literal alternatives in the layout.

Both keep the M0 head (charset, viewport, title, three stylesheets in order) and add, only when the page sets `data['script']` to `show-password`, `<script src="<?= $this->asset('js/show-password.js') ?>" defer="defer"></script>` at the end of `head`. No other script exists in M1.

### 4.2 Top bar (signed in)

In reading order, matching `docs/design/model-ui/projects.html` minus the Display link and Plugins tab (P41):

1. Brand link (`a.brand`, `href` = base + `/`, `aria-label` = `shell.brand.label`), mark and wordmark as M0.
2. `nav.top-nav` with `aria-label` = `shell.nav.main`: "Projects" (`/projects`), and for Admins "People" (`/admin/people`). The link for the current area has `aria-current="page"`.
3. `nav.account-nav` with `aria-label` = `shell.nav.account`: the user chip, a link to `/account/password` (`a.user-chip`, initials in `span.user-initials` with `aria-hidden="true"`, then the display name; accessible name from `shell.account.label` with `{name}`), then a `form.sign-out` (POST `/sign-out`, CSRF field) holding `button.top-pill` "Sign out".

```php
final class Shell
{
    public function __construct(
        public readonly string $displayName,
        public readonly string $initials,
        public readonly bool $isAdmin,
        public readonly string $nav,          // 'projects' | 'people' | 'account' | ''
        public readonly ?array $flash,        // ['key' => ..., 'params' => [...]] or null
    ) {}
}
```

### 4.3 Template rules (changes to `TemplateRulesTest`, ADR 0008 decision 4)

- New scope helper `csrf()`: returns the escaped token; allowed only as the whole value of a `value` attribute.
- New scope helper `count(string $keyBase, int $n)`: returns the escaped text of `Format::count($keyBase, $n)` (section 5.3). Allowed in element text and in `aria-label`. Controllers that need a count inside another string (for example "{fields}. {records}.") call `Format::count` themselves and pass the plain text as a parameter, which `t()` escapes.
- The `script` element is allowed only in `templates/layout.php`, only as written in section 4.1.
- `RUNTIME_KEYS` becomes `RUNTIME_PREFIXES`: keys starting with `problem.`, `error.`, `type.`, `setup.check.`, `notice.`, `wait.`, `date.`, `count.`, `role.` are built at run time and need not appear literally in a template; every other key in the strings must.
- The scanner rejects the pipe operator `|>` (ledger section 7) and the address forms `//` and `/\` at the start of a literal `href`, `src`, or `action` value (ledger section 7).
- Existing rules stay: no `class`, `id`, `style`, or `on*` attribute value from `<?=`. Class variants are written as literal alternatives in `if` branches; ids are literal. Lists of repeated controls therefore use wrapping `label` elements, not `for`/`id` pairs (section 4.5).

### 4.4 Shared partials (WP8)

| Partial | Data | Output |
|---|---|---|
| `top-bar` | `shell` | Section 4.2 |
| `lockup` | none | `header.lockup-bar` with `a.brand.brand-lockup` (mark 64 px, wordmark 40 px) to `/` |
| `footer`, `mark` | as M0 | as M0 |
| `breadcrumb` | `items`: list of `{label (plain text), path or null}` | `nav.breadcrumb` with `aria-label` = `shell.breadcrumb`; links for items with a path; the last item as `span` with `aria-current="page"`; separators `/` in `span` with `aria-hidden="true"` |
| `notice` | `key`, `params`, `tone` (`info` or `warn`), optional `title` key | `div.notice` or `div.notice.notice-warn`, `role="status"` for info and `role="alert"` for warn |
| `error-summary` | `problems`: list of `{field, text}` where `field` is the id of the input or null | `div.error-summary` with `role="alert"`, `tabindex="-1"`, heading `count('count.problems', n)` ("There is a problem" / "There are {count} problems"), a list linking each problem to `#<field id>` |
| `field-error` | `text` | `p.error-text` (the id is written by the calling template, see 4.5) |
| `password-toggle` | `plural` (bool, default false) | `div.check.show-password` with `hidden="hidden"`, containing `label` > `input type="checkbox" data-show-password="data-show-password"` and the text `form.show_password` ("Show password") or, plural, `form.show_passwords` ("Show passwords") |
| `page-head` | `title` (plain text), optional `lead`, optional `actions`: list of `{label, path, primary}` | `div.page-head` with `h1`, `p.lead`, and `div.page-actions` |
| `csrf` | none | `<input type="hidden" name="_csrf" value="<?= $this->csrf() ?>" />` |

### 4.5 Form conventions

Every form screen follows these rules; the design documents give ids and strings.

1. `method="post"` (the one exception is the GET type picker of `schema-editor.md` section 7.1, which changes nothing), `action` from `url()`, `novalidate` is **not** set (browser checks are a bonus; the server checks everything), the `csrf` partial first.
2. Each input: `div.field` containing `label.label` (with `for`), optional `p.help` with an id, optional `p.error-text` with an id, then the control. `aria-describedby` lists the help and error ids that are present. With an error, the control has `aria-invalid="true"`.
3. At 422, the `error-summary` partial comes first in `main` after the breadcrumb and any flash notice, before the `h1` (sign-in is the one exception: its single message sits inside the card, `sign-in-and-projects.md` section 2); the page title becomes `form.errors.page_title` ("Error: {title}"). Focus is not moved by script; the summary has `role="alert"`.
4. Submitted values are printed back (escaped) except passwords and setup codes, which are never printed back. The one deliberate exception is the temporary password an Admin types or accepts for someone else (`people-and-sharing.md`), which is a visible text field by design.
5. Problems from the core are mapped to inputs by field name (`Problem::$field`): the controller holds a map from core field names to element ids. A problem with a null field, or with a field the page does not show, goes only in the summary.
6. Repeated controls (checkbox lists of people or projects, move buttons in a table) use a wrapping `label` or an `aria-label` built with `t()`, never generated ids.
7. One filled (primary) button per screen (ui-design-plan section 3); secondary actions are `button` or `a.button`; destructive actions use `.button-danger` and always go to a confirmation page first.

## 5. Strings

### 5.1 Files

`strings/en.php` returns the merge of every `strings/en/*.php` file, sorted by file name, and throws `\LogicException` naming the key when two files define the same key (P40). Each part returns a flat `'dotted.key' => 'Text'` array and carries the SPDX block. `Strings` is unchanged except that it loads whatever `strings/en.php` returns.

| File | Owner | Content |
|---|---|---|
| `strings/en/shell.php` | WP8 | Section 5.2 |
| `strings/en/errors.php` | WP8 | Section 5.2 |
| `strings/en/format.php` | WP8 | Section 5.3 |
| `strings/en/problems.php` | WP8 | Part 1 section 12, exactly |
| `strings/en/home.php` | WP8 creates (the M0 `home.*` keys, moved); WP7 deletes it with `templates/pages/home.php` | M0 section 9.4 `home.*` rows |
| `strings/en/setup.php` | WP9 | `docs/design/m1/installer.md` |
| `strings/en/account.php` | WP10 | `sign-in-and-projects.md` sections 2 and 4 |
| `strings/en/people.php` | WP10 | `people-and-sharing.md` |
| `strings/en/projects.php` | WP11 | `sign-in-and-projects.md` section 3, `new-project.md` |
| `strings/en/schema.php` | WP11 | `schema-editor.md` |
| `strings/en/types.php` | WP11 | Section 5.4 |

### 5.2 Shell and error strings

M0 keys keep their text except where marked "changed".

| Key | Text |
|---|---|
| `shell.brand.label` | TiffinDB, all projects (changed; matches the mock-ups) |
| `shell.nav.main` | Main |
| `shell.nav.account` | Account |
| `shell.nav.projects` | Projects |
| `shell.nav.people` | People |
| `shell.account.label` | {name}, change your password |
| `shell.sign_out` | Sign out |
| `shell.breadcrumb` | Where you are |
| `count.problems.one` | There is a problem |
| `count.problems.many` | There are {count} problems |
| `form.errors.page_title` | Error: {title} |
| `form.show_password` | Show password |
| `form.show_passwords` | Show passwords |
| `form.cancel` | Cancel |
| `form.optional` | (optional) |
| `error.403.title` | You cannot do that here |
| `error.403.body` | Your account does not allow this. Ask an Admin if you need it. |
| `error.405.body` | This address does not accept that kind of request. (changed: POST now exists) |
| `error.csrf.title` | This form has expired |
| `error.csrf.body` | Go back, reload the page, and try again. Nothing was saved. |
| `error.busy.title` | Please try again in a moment |
| `error.busy.body` | Someone else is saving a change to this project right now. Nothing was lost. Wait a moment, then try again. |
| `error.unavailable.missing.title` | This project's file cannot be found |
| `error.unavailable.missing.body` | The project folder or its database file is missing from the data folder. The person who looks after this TiffinDB can restore it from a backup. |
| `error.unavailable.not-project.title` | This project's file cannot be read |
| `error.unavailable.not-project.body` | The file in the project folder is not a TiffinDB project, or it is damaged. The person who looks after this TiffinDB can restore it from a backup or a snapshot. |
| `error.unavailable.newer.title` | This project needs a newer TiffinDB |
| `error.unavailable.newer.body` | It was saved by a newer version of TiffinDB. Update TiffinDB to open it. |
| `error.unavailable.mismatch.title` | This project's file does not match |
| `error.unavailable.mismatch.body` | The file in the project folder belongs to a different project. The person who looks after this TiffinDB should check the data folder. |
| `notice.signed_out` | You have signed out. |
| `notice.password_changed` | Your password has been changed. |

The other `error.*` keys stay as M0.

### 5.3 Formatting

`TiffinDB\Web\Format` (WP8), constructed with `Strings`, the configured time zone, and a clock:

| Method | Output |
|---|---|
| `relativeDate(string $timestamp): string` | `date.today` ("Today"), `date.yesterday` ("Yesterday"), else `date.day` ("{day} {month} {year}") with `date.month.1` to `date.month.12` = Jan, Feb, Mar, Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec; computed in the configured time zone |
| `wait(int $seconds): string` | 1 to 59 seconds: `wait.second` ("1 second") or `wait.seconds` ("{count} seconds"); otherwise minutes rounded up: `wait.minute` ("1 minute") or `wait.minutes` ("{count} minutes") |
| `problem(Problem $p): string` | The text of `$p->key` with its parameters; for `problem.throttled`, `{wait}` from `wait($p->params['seconds'])` |
| `count(string $keyBase, int $n): string` | The text of `<keyBase>.one` when `$n` is 1, else `<keyBase>.many` with `{count}` = `number_format($n)` ("1,234") |
| `number(int $n): string` | `number_format($n)` (record counts on the project list) |

`date.last_change` = "{date}, by {name}" and `date.last_change_unknown` = "{date}" are in `format.php` too.

### 5.4 Field type labels (`strings/en/types.php`)

| Type id | `type.<id>` | `type.<id>.hint` |
|---|---|---|
| `text` | Text | A short piece of text on one line, such as a name. |
| `long_text` | Long text | Several lines of text, such as notes. |
| `number` | Number | A number, such as a quantity or a measurement. |
| `currency` | Currency | An amount of money. |
| `date` | Date | A day, such as a purchase date or a birthday. |
| `time` | Time | A time of day. |
| `date_time` | Date and time | A day and a time, such as an appointment. |
| `duration` | Duration | A length of time, such as 1 hour 30 minutes. |
| `checkbox` | Checkbox | Yes or no, shown as a box to tick. |
| `choice` | Choice | One option picked from a list you set. |
| `rating` | Rating | A score from 1 to 5, or to another top score you choose. |
| `url` | Web address | A link to a web page. |
| `email` | Email address | An email address. |
| `phone` | Phone number | A phone number. |
| `attachment` | File or image | Files such as photos, receipts, or manuals. |
| `relation` | Link to another table | Connects records, such as an item and the room it is in. |
| `auto_number` | Auto-number | A number TiffinDB gives each new record: 1, 2, 3, and so on. |

Also `type.relation.to` = "Link to {table}" (used in field lists, as in the import mock-up), and the group headings of the type picker (`type.group.words` "Words", `type.group.numbers` "Numbers and money", `type.group.time` "Dates and times", `type.group.choices` "Yes or no, and choices", `type.group.contact` "Contact and web", `type.group.links` "Files and links").

## 6. Stylesheet and script

`public/assets/css/app.css` gains the component classes of `docs/design/m1/components.md`. All M0 rules stay (rem only, the px exceptions, no colour token redefined, no `url()`, no `@import`). No media queries are added in M1 (`components.md`); any later breakpoint is in `em` (M0 spec rule R6).

`public/assets/js/show-password.js` (WP8), the only script, loaded with `defer`:

1. For every element with class `show-password` that has the `hidden` attribute: remove `hidden`.
2. Its checkbox (`input[data-show-password]`) toggles, on `change`, the `type` of every `input[type=password]` and of every input it switched before, inside the same `form`, between `password` and `text`.
3. Nothing else: no network, no storage, no inline styles, no `eval`, no `innerHTML`. It starts with the two SPDX `//` lines and runs in strict mode inside an IIFE.

Without JavaScript the checkbox stays hidden and the form works unchanged.

## 7. Accessibility requirements common to all M1 screens

- WCAG 2.2 AA; text contrast from the tokens (7:1 target, ui-design-plan section 4.2).
- Landmarks: one `header`, one `main`, one `footer`; navigation regions labelled; one `h1`; headings in order.
- Every control has a visible label; error text is tied to its control; status in words; touch targets at least 44 px, inputs 48 px.
- Keyboard: every action reachable by Tab in reading order; no keyboard traps; visible focus (M0 rule).
- Screen readers: page title names the screen first ("Sign in - TiffinDB"); confirmation pages state the consequence in the `h1` or the first paragraph; notices use `role="status"` and errors `role="alert"`.
- Without JavaScript every screen works; the only lost feature is "Show password".
- At 390 px and at 320 px with the Largest text size: no horizontal page scroll; tables scroll inside `div.table-wrap`.

## 8. Matching the mock-ups (exit criterion)

### 8.1 Screens with a mock-up: sign-in and project list

"Matches" means all of the following, checked at viewport widths 1360 px and 390 px, default appearance (light theme, teal, Inter, standard text size and spacing), with synthetic data equal to the mock-up (three projects "Home Inventory", "Recipes", "Garden Log" with the same tables, record counts, and sharing counts; signed in as an Admin "Example Admin"):

1. **Content and order.** The same elements in the same reading order with the same text, except the deviations listed in 8.3.
2. **Measurements** (CSS pixels from `getBoundingClientRect` or computed style, tolerance 1 px), at 1360 px:

| Element | Sign-in | Project list |
|---|---|---|
| Top bar height | none (plain shell) | 68 |
| Brand mark | 64 × 64 | 34 × 34 |
| Wordmark font size | 40 | 22 |
| Main content width | form card max 440, padding 32 | max 1120, padding 36 24 48 |
| `h1` font size | 30 | 34 |
| Card | radius 12, border 1 | radius 12, border 1, padding 24 |
| Card grid | | 3 columns, gap 20 |
| Inputs, sign-in button | min height 48, border 2, radius 8 | |
| Header buttons, card links | | min height 44 |
| Footer | font size 16, padding 16 24 | the same |

   At 390 px: the card grid is one column; nothing scrolls sideways; the top bar wraps onto further lines without overlap.
3. **Colours.** Background, text, border, and button colours equal the token values the mock-up uses for the same element.
4. **By eye.** Side-by-side screenshots of the mock-up and the screen at both widths show no difference beyond those listed.

### 8.2 Screens without a mock-up

The installer, people, person, new person, sharing, own password, new project, project settings, tables, new table, table fields, field editor, type change, and every confirmation page "match" when:

1. Their elements, order, and strings are exactly as in their design document under `docs/design/m1/`.
2. Shared parts measure as in 8.1 (top bar, page width and padding, `h1`, cards, inputs, buttons, footer), and components measure as `components.md` states.
3. They render in all four themes (correct root class, no unreadable text), and at 390 px and at 320 px with the Largest text size nothing scrolls sideways.

### 8.3 Deviations from the mock-ups accepted in M1

| Screen | Mock-up | M1 | Reason |
|---|---|---|---|
| Both | Wordmark tracking -0.01 em | -0.025 em | Brand README (M0 spec section 8.4) |
| Project list | "Display" link | absent | P41; arrives with display settings (M2) |
| Project list | "Plugins" tab | absent | P41; M3 |
| Project list | User chip is plain text | a link to the password page | P41 |
| Project list | "Import a file" button, "Export" card link | absent | M3 |
| Project list | "Shared with: Only you" | "Shared with: Admins only" when no Member has a grant | Admins always have access; "only you" would be wrong with two Admins |
| Project list | Admin note mentions Plugins | omits Plugins | P41 |
| Sign-in | "Sign in" drawn as a link | a `button` | It submits a form |
| Sign-in | Lockup is a plain `div` | a `header` with a link to `/` | Shell assertions need one `header` and a brand link |

### 8.4 Procedure (V-1 to V-8)

Run by WP12 and again by the exit auditor with the preinstalled headless browser against `composer serve` on a scratch installation (data folder outside `public/`, created with `CoreFixture`-equivalent synthetic data). Screenshots stay in the scratchpad and are never committed.

| ID | Screens |
|---|---|
| V-1 | Sign-in: empty, with an error, with "Show password" on |
| V-2 | Project list: Admin with three projects; Member with one; empty for each |
| V-3 | Installer: step 1 passing and failing, step 2 exposed and inconclusive, step 3 with errors |
| V-4 | People, new person, person page |
| V-5 | Sharing |
| V-6 | New project, project settings |
| V-7 | Tables, new table, table fields |
| V-8 | Add field (type picker and details for choice, currency, relation), edit field, change type with confirmation, delete field, delete table |

For each: 1360 px and 390 px in the light theme with the measurements of 8.1; one pass in each of the other three themes at 1360 px; 320 px at the Largest text size (set through a test account's `user_settings` row). Record pass or the deviation per screen and width.

## 9. Web tests

| ID | Test | Asserts | Owner |
|---|---|---|---|
| T-WEB-13 | `RequestTest` (extended) | `fromGlobals` fields of section 2.1, including rejected `HTTP_HOST`, `SERVER_ADDR`, and `SERVER_PORT` values; `input` returns `''` for arrays; `inputList` drops non-strings | WP7 |
| T-WEB-14 | `ResponseTest`, `CookieTest` | Cookie header text for both cookies; invalid names, values, paths refused; redirect validation; several cookies sent; `Fallback` sends no body for HEAD | WP7 |
| T-WEB-15 | `RouterTest` (extended) | Four-element table; unknown access level refused; the real `routes.php` loads; literal routes before placeholders | WP7 |
| T-WEB-16 | `GateTest` | Every step of section 2.4 with `FakeServices`: setup redirect, setup 404 after install, sign-in redirect, guest redirect, must-change redirect and its three exceptions, cross-site POST refused, missing and wrong CSRF refused for both kinds of token, anonymous CSRF cookie set, stale session cookie deleted, exception mapping including `Retry-After` | WP7 |
| T-WEB-17 | `KernelTest`, `FrontControllerTest`, `FrontControllerFailureTest` (updated) | M0 assertions that still apply; `/` from the CLI with no data folder gives an empty body (redirect) | WP7 |
| T-WEB-18 | `AppearanceTest` (extended) | `fromSettings` for every value and for invalid values | WP8 |
| T-WEB-19 | `StringsTest` (extended) | Parts are merged; a duplicate key across parts throws naming the key; every key in `problems.php` equals part 1 section 12 | WP8 |
| T-WEB-20 | `FormatTest` | Relative dates across midnight in a non-UTC zone; `wait` boundaries 1, 59, 60, 61, 900 | WP8 |
| T-WEB-11 | `TemplateRulesTest` (extended) | Section 4.3, with negative cases for each new rule | WP8 |
| T-WEB-21 | `ShellTest` (rewritten) | Both shells rendered through `View` with real templates and strings: M0 structure assertions, top bar order and labels, Admin and Member variants, `aria-current`, sign-out form with CSRF, flash notice, plain shell lockup, script tag only when requested; four themes; well-formed; no inline code | WP8 |
| T-WEB-22 | `HtmlAssertions` (extended) | `assertNoInlineCode` allows exactly one `script` element with a same-origin `src` under `/assets/js/`, `defer`, and no content; `assertForm` (new) checks rule 4.5 items 1, 2, and 6 on every form in a page | WP8 |
| T-SCR-1 | `ScreenStructureTest` | For every GET route in each of its states listed in the design documents: well-formed, no inline code, shell assertions, `assertForm`, title text, `h1` text | WP12 (each screen package also tests its own screens) |
| T-E2E-1 | `EndToEndTest` | From an empty temporary folder, through the kernel with real `CoreServices` (configuration `data_dir` set to a temporary folder and `DOCUMENT_ROOT` set to the repository's `public/`, so the folder is outside the document root and no probe runs; nothing is written outside the temporary folder): `/` redirects to setup; step 1 lists checks; POST data folder; read `setup-code.txt`; POST first Admin; land on the project list with the notice; create project "Home Inventory" with first table "Items"; add table "Rooms"; add a relation field "Room" from Items to Rooms; the tables page lists both tables; the fields page lists Name and Room. **Admin can create a project and a table** | WP12 |
| T-E2E-2 | `EndToEndTest` | Admin adds Member "Example Member" with a temporary password and shares the project; the Member signs in, is sent to change their password, changes it, sees only that project, can open the tables page, gets 403 on the fields editor POST and sees no editing controls | WP12 |
| T-E2E-3 | `EndToEndTest` | Sign-out deletes the session; the old cookie no longer works; a POST without `_csrf` is refused | WP12 |
| T-E2E-4 | `EndToEndTest` | Five wrong passwords, then the sign-in page shows the wait message and does not sign in with the right password until the clock passes the wait | WP12 |

## 10. File ownership for the web packages

| Package | Owned files | Acceptance |
|---|---|---|
| WP7 | `public/index.php`, `config/app.php`, `.gitignore`, `tests/repo/GitignoreTest.php`; `src/Web/Request.php`, `Response.php`, `Cookie.php`, `Kernel.php`, `Router.php`, `RouteMatch.php`, `AccessLevel.php`, `routes.php`, `Services.php`, `CoreServices.php`, `Context.php`, `Fallback.php`, `Controller/HomeController.php`; deletion of `templates/pages/home.php` and `strings/en/home.php`; `tests/web/RequestTest.php`, `ResponseTest.php`, `CookieTest.php`, `RouterTest.php`, `GateTest.php`, `KernelTest.php`, `FrontControllerTest.php`, `FrontControllerFailureTest.php`, `tests/web/fixtures/Controllers.php`, `Fixtures.php`, `FakeServices.php` | T-WEB-13 to T-WEB-17 pass; `header()` only in `Response::send` and `Fallback`; no superglobal outside `index.php` and `Fallback::onShutdown`; design-tier security review |
| WP8 | `src/Web/View.php`, `ViewScope.php`, `Strings.php`, `Appearance.php`, `Shell.php`, `Format.php`; `templates/layout.php`, `templates/pages/error.php`, `templates/partials/*.php` (the partials of section 4.4 and the M0 ones); `strings/en.php`, `strings/en/shell.php`, `errors.php`, `format.php`, `problems.php`, `home.php` (created, section 5.1); `public/assets/css/app.css`, `public/assets/js/show-password.js`; `tests/Support/HtmlAssertions.php`; `tests/web/ShellTest.php`, `TemplateRulesTest.php`, `ViewTest.php`, `StringsTest.php`, `AppearanceTest.php`, `FormatTest.php`; `tests/web/fixtures/templates/*`, `tests/web/fixtures/strings/*` | T-WEB-11, T-WEB-18 to T-WEB-22 pass; every class in `components.md` exists in `app.css`; T-DES-1 to T-DES-6 still pass; the M0 kernel still serves `/` until WP7 merges; design-tier review of templates and the script |
| WP9 | `src/Web/Controller/SetupController.php`; `templates/pages/setup/*.php`; `strings/en/setup.php`; `tests/web/SetupScreenTest.php` | Every state in `installer.md` rendered and asserted (strings, order, `assertForm`); POST flows with `FakeServices` and a fake probe: safe redirect, exposed and inconclusive pages (409), admin creation (cookie set, `tdb_csrf` deleted, redirect), validation (422, no password or code printed back); design-tier security review |
| WP10 | `src/Web/Controller/SignInController.php`, `AccountController.php`, `PeopleController.php`, `SharingController.php`; `templates/pages/sign-in.php`, `templates/pages/account/*.php`, `templates/pages/people/*.php`, `templates/pages/sharing.php`; `strings/en/account.php`, `strings/en/people.php`; `tests/web/SignInScreenTest.php`, `PeopleScreenTest.php`, `SharingScreenTest.php` | Every state in the two design documents rendered and asserted; sign-in success sets the cookie with the attributes of 2.6 and redirects; failure and throttling messages identical for unknown and known usernames; must-change flow; people and sharing POSTs call the right operations and map every problem to its field; design-tier security review |
| WP11 | `src/Web/Controller/ProjectController.php`, `TableController.php`, `FieldController.php`; `templates/pages/projects/*.php`, `templates/pages/tables/*.php`, `templates/pages/fields/*.php`, `templates/partials/field-options/*.php`; `strings/en/projects.php`, `schema.php`, `types.php`; `tests/web/ProjectScreensTest.php`, `SchemaScreensTest.php` | Every state in `sign-in-and-projects.md` section 3, `new-project.md`, and `schema-editor.md` rendered and asserted; every POST maps problems to fields; confirmation pages reached for lossy changes and deletions; Member variants show no editing controls; design-tier review of templates |
| WP12 | `tests/web/EndToEndTest.php`, `tests/web/ScreenStructureTest.php` | T-SCR-1, T-E2E-1 to T-E2E-4 pass; the visual procedure of 8.4 run and reported |

WP9, WP10, and WP11 must not change `app.css`, shared partials, or another package's strings; a missing class or partial is reported to the orchestrator, who routes it to a follow-up on WP8's files.
