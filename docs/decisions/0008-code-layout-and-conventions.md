# ADR 0008: Code layout and conventions

Status: Accepted (provisional, awaiting maintainer confirmation). Every item below settles something the earlier documents left open (context brief section 12 items 10, 11, 23). Nothing here overrides `CLAUDE.md`, ADRs 0001 to 0007, or `docs/plan.md`.

## Context

ADR 0001 fixes the stack (PHP 8.3+, no framework, Apache `.htaccess`, SQLite through PDO, server-rendered HTML, vanilla JavaScript, no build step). Plan section 3.1 names the layers and plan section 3.2 proposes an on-disk layout, but no document says how layers map to paths and namespaces, how requests are routed, how templates escape output, where strings live, which coding standard applies, how tests and CI are arranged, or how the Content-Security-Policy is emitted. Scaffolding (M0) cannot start without these. The detailed M0 design is `docs/specs/M0-scaffolding.md`; this record holds the decisions.

Design aim: each decision is small and local, so reversing it later touches few files.

## Decisions

### 1. Layers, paths, and namespaces

Autoloading is PSR-4 through Composer. One root namespace, `TiffinDB\`, maps to `src/`.

| Layer (plan 3.1) | Path | Namespace | May depend on |
|---|---|---|---|
| `core/storage` | `src/Core/Storage/` | `TiffinDB\Core\Storage` | none |
| `core/schema` | `src/Core/Schema/` | `TiffinDB\Core\Schema` | Storage |
| `core/query` | `src/Core/Query/` | `TiffinDB\Core\Query` | Schema, Storage |
| `core/io` | `src/Core/Io/` | `TiffinDB\Core\Io` | Schema, Query |
| `core/forms` | `src/Core/Forms/` | `TiffinDB\Core\Forms` | Schema |
| `core/auth` | `src/Core/Auth/` | `TiffinDB\Core\Auth` | system database access (placement decided in the M1 spec) |
| `web/` | `src/Web/` (classes), `templates/`, `strings/`, `public/` | `TiffinDB\Web` | all of Core |
| `mcp/` (post-v1) | `src/Mcp/` (reserved; not created) | `TiffinDB\Mcp` | all of Core |
| `plugins/` | `plugins/<kind>/<id>/` (reserved; not created before M3) | decided in the M3 spec | `TiffinDB\Core\Io` interfaces |

Rules:

- Nothing under `src/Core/` may reference `TiffinDB\Web` or `TiffinDB\Mcp`, read superglobals, send headers, or produce HTML page chrome. A test enforces the namespace rule from M0.
- Core returns data and message keys with parameters, not finished English sentences, so that the web layer and the later MCP adapter can each present them. The strings file belongs to the web layer.
- Items the layer table does not place (trash, snapshots, audit log, system database access; brief section 12 item 10) are placed by the M1 spec, inside `src/Core/`.
- Only directories that contain files are created. M0 creates `src/Web/` only.

Top-level directories:

| Path | Content | Web-reachable |
|---|---|---|
| `public/` | Web root: `index.php`, `.htaccess`, `assets/` | Yes |
| `src/` | PHP classes | No |
| `templates/` | Plain PHP templates | No |
| `strings/` | UI strings | No |
| `config/` | Committed default configuration (no secrets) | No |
| `tests/` | PHPUnit tests | No |
| `tools/` | Developer and maintainer scripts (`tools/dev/`, `tools/discovery/`, `tools/host-check/`) | No |
| `plugins/` | Reserved for M3 | No |
| `vendor/` | Composer packages; git-ignored | No |
| `data/` | Runtime data; git-ignored | No |

### 2. Front controller and routing

- `public/index.php` is the only PHP entry point. It loads the Composer autoloader, builds a `Request` from the server variables, passes it to `TiffinDB\Web\Kernel`, and sends the returned `Response`. It contains no other logic.
- `public/.htaccess` sends every request that is not an existing file to `index.php`. `mod_rewrite` is required; the M1 installer checks for it.
- A root `.htaccess` forwards every request into `public/`, for hosts where the whole tree must sit under the document root. Nothing outside `public/` is then reachable. This is defence in depth, not the data-directory protection required by plan section 3.2, which remains an installer duty (M1).
- Routes are one static table in `src/Web/routes.php`: a PHP file returning a list of `[method, pattern, [ControllerClass, 'method']]`. Patterns are literal paths with optional `{name}` segments; each placeholder matches one path segment of `[A-Za-z0-9_-]+`. No closures, no attribute scanning, no regular expressions written by route authors. First match wins.
- Controllers are small final classes in `TiffinDB\Web\Controller`. A controller method receives the `Request` (and route parameters) and returns a `Response`. Controllers call core services and render templates; they hold no business rules and no authorization decisions (plan section 8).
- The kernel attaches security headers to every response in one place, turns an unmatched path into a 404 page, a matched path with the wrong method into 405 with an `Allow` header, and any uncaught error into a generic 500 page with no detail in the body.

### 3. URL conventions

- Paths are lower-case ASCII, words joined by hyphens, plural nouns for collections, no file extensions, no trailing slash (the root `/` excepted). A non-canonical path is a 404, not a redirect. (Redirecting is a possible later refinement.)
- GET and HEAD never change state. Every state change is a POST carrying a CSRF token (from M1). No method-override parameter.
- Query strings carry view state only (search, sort, filter, paging), never identity or authority.
- Identifiers in URLs are opaque application-generated keys, never user-entered text (plan section 7). Their format is decided in the M1 spec.
- All links and asset references are produced by one URL helper that prepends the detected base path, so the application works in a subdirectory without configuration.
- Reserved path prefixes: `/assets/` (static files), `/setup` (installer), `/sign-in`, `/sign-out`, `/projects`, `/settings`, `/admin`, `/about`, `/files` (attachment endpoint), `/mcp` (post-v1). The routes under each prefix are fixed by the spec of the milestone that builds them. The indicative shape for data screens is `/projects/{project}/tables/{table}/records/{record}`.

### 4. Templates

- Plain PHP files under `templates/`, rendered by `TiffinDB\Web\View`. No template engine.
- A template runs inside a view-scope object and may use only that object's helpers and the variables passed to it.
- Escaping is mandatory and mechanical. Every `<?= ... ?>` in a template must begin with a call to one of the scope helpers: `$this->e()` (escape a value), `$this->t()` (look up a string, returned escaped), `$this->url()`, `$this->asset()` (both return escaped URLs), `$this->partial()`, `$this->slot()` (both return markup that was itself produced by templates). `echo`, `print`, and `<?=` with any other expression are forbidden. A test scans every template and fails on a violation.
- `e()` is `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5` and UTF-8. Attribute values are always double-quoted.
- Markup that did not come from a template (for example sanitized form HTML in M5) will need a typed "safe HTML" value and a dedicated helper. That is deliberately not provided in M0; it is added with the sanitizer.
- Output is HTML5 written in XML-well-formed syntax (upper-case `<!DOCTYPE html>`, void elements self-closed, every attribute with a quoted value, no named entities beyond the five XML ones). This lets the test suite check well-formedness offline with the XML parser built into PHP, with no extra dependency. PHP 8.3 has no built-in HTML5 parser.
- No inline `style` attributes, `<style>` elements, inline scripts, or event-handler attributes in any output (ui-design-plan section 10).

### 5. Strings

- One file, `strings/en.php`, returning a flat PHP array of `'dotted.key' => 'English text'`. PHP is chosen because it needs no parser, is cached by the opcode cache, and can carry the SPDX header.
- Placeholders are `{name}`. `t('key', ['name' => $value])` substitutes escaped values and returns escaped text.
- A missing key throws. A test checks that every key used in a template exists.
- Templates contain no English prose. One exception: the wordmark partial writes the product name as live text split for colouring (brand README).
- Adding a language later means adding `strings/<code>.php`; nothing else is assumed now.

### 6. Coding standard

- PER Coding Style 2.0 (the successor to PSR-12) and PSR-4.
- Every PHP file starts with the SPDX block and `declare(strict_types=1);`.
- Classes are `final` unless designed for extension; properties are typed and `readonly` where possible; no global functions outside templates' scope helpers; no global state; no `static` mutable state.
- No suppression operator (`@`), no `eval`, no variable variables, no `extract` (the view passes variables explicitly; see the M0 spec).
- Enforcement in M0 is deliberately light because the only development dependency allowed so far is PHPUnit: `php -l` on every file, plus tests for the SPDX header and `strict_types`. Style beyond that is checked in review. Adding a formatter or static analyser is a later decision and needs its own dependency record.

### 7. Tests

- PHPUnit, configured by `phpunit.xml.dist`; one command, `composer test`. `composer lint` runs the syntax check; `composer check` runs both.
- Tests live under `tests/`, grouped by area in lower-case directories: `tests/repo/` (repository rules), `tests/design/` (tokens, fonts), `tests/web/` (request pipeline and rendering), `tests/tools/` (scripts under `tools/`), and from M1 `tests/core/<layer>/`. Test classes use namespace `TiffinDB\Tests\<Area>` and are found by PHPUnit's directory scan (file suffix `Test.php`). Shared helpers live in `tests/Support/` and are autoloaded as `TiffinDB\Tests\Support\`.
- Tests are deterministic and offline: no network, no clock or locale dependence without injection, no writes outside a per-test temporary directory, synthetic data only (`CLAUDE.md`).
- Web tests run in process: they build a `Request`, call the kernel, and inspect the `Response`. No web server is started.

### 8. Continuous integration

- One GitHub Actions workflow, `.github/workflows/ci.yml`, on every push and pull request: check out, confirm PHP 8.3 or later, `composer install`, `composer lint`, `composer test`.
- `composer install` is the only step that uses the network, and it belongs to CI setup. Lint and tests make no network requests.
- The workflow uses the PHP and Composer that the hosted runner image provides, so the only third-party action is the checkout action. Assumption: the pinned runner image provides PHP 8.3 or later with the extensions PHPUnit needs; the workflow fails at its first step if that stops being true.
- Workflow permissions are read-only.

### 9. Security headers and the Content-Security-Policy

- One class, `TiffinDB\Web\SecurityHeaders`, holds the complete header set and the CSP as an ordered list of directives. The kernel applies it to every response, including error pages. No controller or template sets a security header. No `<meta http-equiv>` policy is used.
- The policy starts from `default-src 'none'` and allows only same-origin stylesheets, scripts, images, fonts, form targets, and connections. No `'unsafe-inline'`, no `'unsafe-eval'`, no nonces or hashes (there is nothing inline to allow), no external origins. The exact string is in the M0 spec.
- Static files are served by Apache without PHP, so `public/.htaccess` adds `X-Content-Type-Options` to them when `mod_headers` is present.
- HSTS is not sent by the application; whether a site is HTTPS-only is the host's decision. To be revisited with the installer (M1).

### 10. Static assets

```
public/assets/
  css/    tokens.css  fonts.css  app.css
  fonts/  <family>/<file>.woff2  <family>/LICENSE.txt
  js/     (created when the first script is added; none in M0)
  img/    (created when the first image is added; none in M0)
```

- Three hand-written stylesheets, linked in the order fonts, tokens, app. No `@import`, no preprocessing, no bundling. This reads ui-design-plan section 10 ("one hand-written stylesheet, plus one per bundled font face declaration") loosely: tokens are kept in their own file so the contrast tests and the form-CSS rule ("may reference tokens but not redefine them") have a single target. Merging the files later is a concatenation.
- Asset URLs carry `?v=<application version>` so that an upgrade replaces cached files.
- Third-party assets are committed as obtained, with their licence file beside them, and recorded in `docs/dependencies.md`.

### 11. Appearance classes

- The server writes appearance classes on the `<html>` element (the root element, so that the root font size drives every `rem`).
- One value object, `TiffinDB\Web\Appearance`, holds the six settings and returns the class list. It is the only place that knows class names: theme `t-dark`, `t-hcl`, `t-hcd`; accent `a-indigo`, `a-plum`, `a-graphite` (never emitted with a high-contrast theme); typeface `f-atk`, `f-lexend`, `f-serif4`, `f-sans`, `f-serif`; text size `s-small`, `s-large`, `s-larger`, `s-largest`; line spacing `l-roomy`, `l-very-roomy`; reduced movement `m-reduce`. Defaults emit no class.
- The size, spacing, and movement class names are new in this record; the other names come from `docs/design/tokens.css`.
- Class names are chosen from fixed lists. Request or stored values are mapped through an allowlist and never written into markup.
- In M0, before accounts exist, the settings come from allowlisted query parameters (M0 spec section 7). From M1 they come from the signed-in user's stored settings.

### 12. Dependency lock file

`composer.lock` is git-ignored for now. The lock file embeds third-party package metadata that includes personal names and email addresses of package authors, which `CLAUDE.md` forbids in the repository. The cost is that development installs are pinned only by the version constraint in `composer.json`. This must be revisited before the first runtime dependency is added (M3), because the release archive ships `vendor/` and should be reproducible. Options then: commit the lock with a maintainer exemption for that named file, or record exact versions and content hashes in `docs/dependencies.md`.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Routing | Query-parameter routing (`index.php?r=...`) | Works without `mod_rewrite`, but gives poor URLs and the hosts in scope provide `mod_rewrite` (to be confirmed by the host check) |
| Routing | Route attributes on controllers | Needs reflection scanning or a cache; a table is simpler to read and test |
| Templates | A template engine | A dependency to vendor and audit; plain PHP with an enforced escaping rule is enough |
| Strings | JSON or INI | JSON cannot carry the licence header; INI quoting rules are error-prone |
| HTML checking | A validator library or service | No network in tests; no dependency beyond PHPUnit in M0 |
| CSP delivery | `.htaccess` `Header` directives | Depends on `mod_headers`; PHP delivery works everywhere the application runs |
| CI PHP setup | A third-party setup action | Avoids a further third-party action while the runner image is sufficient |
| Stylesheets | One file | See decision 10 |

## Consequences

- Engineers have one place for each concern: routes table, security headers class, appearance class, strings file, URL helper.
- XML-well-formed HTML is a stricter writing discipline than HTML5 requires. It costs a few characters per void element and gives an offline structural check. If a real HTML5 parser becomes available (PHP 8.4 or a vetted library), the rule can be relaxed.
- Light enforcement of coding style relies on review until a formatter is approved.
- The untracked lock file is a known gap, to be closed by M3.
- The root `.htaccess` and the base-path detection have not been exercised against a real Apache server in M0; the host-check script and the M1 installer test are where that is verified.

## Costly to reverse

Of everything here, only two choices spread widely enough to be expensive later: the URL shapes (once users bookmark them and, post-v1, MCP clients are registered against a path) and the root namespace `TiffinDB\` with its `Core`/`Web` split. Everything else is confined to one file or one directory.
