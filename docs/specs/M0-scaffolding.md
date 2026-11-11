# M0 design spec: scaffolding

Status: design spec for milestone M0. Decisions it relies on are recorded in ADR 0008 (code layout and conventions) and ADR 0009 (provisional defaults); both are provisional and await maintainer confirmation.

Audience: engineers who have this spec, `CLAUDE.md`, and the design files it references (`docs/design/tokens.css`, `docs/design/model-ui/`, `docs/brand/`). `CLAUDE.md` has the highest authority; if this spec conflicts with it, stop and report.

This spec describes what to build. It contains exact content only for configuration and data files, where exactness matters more than judgement.

## 1. Goals and non-goals

### 1.1 Goals

1. A repository skeleton: Composer manifest, autoloading, test runner, lint command, CI workflow.
2. `.gitignore` entries in place before any code that could create data files.
3. A minimal request pipeline: front controller, router, kernel, security headers, templates, strings.
4. One page that renders the application shell (top bar with mark and wordmark, main area, footer with licence line and "Source code" link) in all four themes.
5. The application stylesheets, derived from `docs/design/tokens.css`, with every size in `rem`.
6. The four bundled font families as committed WOFF2 files with their licence files.
7. `docs/dependencies.md` with every dependency and its verified licence.
8. Tests that make each M0 exit criterion checkable by `composer test`.

### 1.2 Non-goals

- No database, no `data/` directory, no installer, no accounts, no sessions, no CSRF, no forms (all M1).
- No sidebar, breadcrumb, account links, Display link, or other components from ui-design-plan section 7 beyond what the shell needs.
- No JavaScript. `public/assets/js/` is not created.
- No favicon or app icon (M7, ui-design-plan section 11). Browsers will request `/favicon.ico` and receive the 404 page; that is accepted for M0.
- No release packaging (M7).
- No formatter or static analyser (ADR 0008 decision 6).
- The legacy-format discovery script and the shared-host check script are **not** part of this spec's work packages. They are handled separately under `tools/discovery/` and `tools/host-check/` (section 2.2).

### 1.3 Exit criteria and their status

| Exit criterion (plan section 8, ui-design-plan section 11, `CLAUDE.md`) | How M0 meets it |
|---|---|
| ADRs accepted | ADRs 0008 and 0009 are recorded as provisional; the maintainer confirms. Not testable |
| `docs/formats/bento.md` first findings | Deferred, pending maintainer discovery run (ADR 0009 row D2) |
| Empty app serves a page | Section 6; tests T-WEB-1 to T-WEB-5 |
| Empty page renders the shell in all four themes | Sections 7 to 9; tests T-WEB-6 to T-WEB-10, T-DES-1 to T-DES-4 |
| `.gitignore` entries exist before any code that creates data | Section 4; test T-REPO-1 |
| CI runs offline tests | Section 12; tests run with no network |
| Tokens copied into the application stylesheet; fonts and licences added and recorded | Sections 8, 10, 11; tests T-DES-1 to T-DES-6 |

## 2. File and directory layout

### 2.1 Files M0 creates

`WP` is the owning work package (section 16).

```
.github/workflows/ci.yml                         WP1
.gitignore                                       WP1  (replace content; section 4)
.htaccess                                        WP1  (root guard; section 5.2)
composer.json                                    WP1
phpunit.xml.dist                                 WP1
config/app.php                                   WP1
public/.htaccess                                 WP1
tools/dev/lint.php                               WP1
tests/Support/RepoFiles.php                      WP1
tests/repo/GitignoreTest.php                     WP1
tests/repo/ComposerManifestTest.php              WP1
tests/repo/HtaccessTest.php                      WP1
tests/repo/SpdxHeaderTest.php                    WP2
tests/repo/PhpSourceRulesTest.php                WP2
tests/repo/DependencyLicenceTest.php             WP2
tests/repo/LayerRulesTest.php                    WP2
public/assets/css/tokens.css                     WP3
public/assets/css/fonts.css                      WP4
public/assets/fonts/inter/inter-latin-400-normal.woff2                                  WP4
public/assets/fonts/inter/inter-latin-700-normal.woff2                                  WP4
public/assets/fonts/inter/LICENSE.txt                                                   WP4
public/assets/fonts/atkinson-hyperlegible/atkinson-hyperlegible-latin-400-normal.woff2  WP4
public/assets/fonts/atkinson-hyperlegible/atkinson-hyperlegible-latin-700-normal.woff2  WP4
public/assets/fonts/atkinson-hyperlegible/LICENSE.txt                                   WP4
public/assets/fonts/lexend/lexend-latin-400-normal.woff2                                WP4
public/assets/fonts/lexend/lexend-latin-700-normal.woff2                                WP4
public/assets/fonts/lexend/LICENSE.txt                                                  WP4
public/assets/fonts/source-serif-4/source-serif-4-latin-400-normal.woff2                WP4
public/assets/fonts/source-serif-4/source-serif-4-latin-700-normal.woff2                WP4
public/assets/fonts/source-serif-4/LICENSE.txt                                          WP4
docs/dependencies.md                             WP4
public/index.php                                 WP5
src/Web/Kernel.php                               WP5
src/Web/Request.php                              WP5
src/Web/Response.php                             WP5
src/Web/Router.php                               WP5
src/Web/RouteMatch.php                           WP5
src/Web/routes.php                               WP5
src/Web/SecurityHeaders.php                      WP5
src/Web/UrlGenerator.php                         WP5
src/Web/Appearance.php                           WP5
src/Web/Strings.php                              WP5
src/Web/View.php                                 WP5
src/Web/Controller/HomeController.php            WP5
tests/web/RequestTest.php                        WP5
tests/web/RouterTest.php                         WP5
tests/web/SecurityHeadersTest.php                WP5
tests/web/UrlGeneratorTest.php                   WP5
tests/web/AppearanceTest.php                     WP5
tests/web/StringsTest.php                        WP5
tests/web/ViewTest.php                           WP5
tests/web/fixtures/                              WP5  (template and strings fixtures for the tests above)
templates/layout.php                             WP6
templates/partials/top-bar.php                   WP6
templates/partials/mark.php                      WP6
templates/partials/footer.php                    WP6
templates/pages/home.php                         WP6
templates/pages/error.php                        WP6
strings/en.php                                   WP6
public/assets/css/app.css                        WP6
tests/Support/HtmlAssertions.php                 WP6
tests/web/ShellTest.php                          WP6
tests/web/TemplateRulesTest.php                  WP6
tests/Support/CssTokens.php                      WP7
tests/Support/Contrast.php                       WP7
tests/design/TokenParityTest.php                 WP7
tests/design/ContrastTest.php                    WP7
tests/design/StylesheetRulesTest.php             WP7
tests/design/FontAssetsTest.php                  WP7
```

WP5 may add further small classes under `src/Web/` and tests under `tests/web/` if the design needs them; WP6 may add partials under `templates/`. No WP creates files outside its rows without reporting it.

### 2.2 Reserved paths (not created by these work packages)

| Path | Reserved for |
|---|---|
| `tools/discovery/` | Legacy-format discovery: one PHP CLI script and a `README.md`. Handled separately |
| `tools/host-check/` | Shared-host check: one PHP CLI script and a `README.md`. Handled separately |
| `tests/tools/` | Tests for the scripts above. Handled separately |
| `src/Core/`, `src/Mcp/`, `plugins/` | Later milestones (ADR 0008 decision 1) |
| `data/`, `local/` | Runtime and local-only data; git-ignored; never created by tests |
| `docs/formats/` | Format documents (M1 onward; the legacy-format findings are deferred) |

The scripts under `tools/discovery/` and `tools/host-check/` are PHP source files and **must** be covered by the SPDX header test and the lint command (sections 13 and 15). They are standalone scripts that may need to start on an older PHP in order to report on it, so they are exempt from the `strict_types` rule only. Their `README.md` files are Markdown and are not required to carry a header.

## 3. `composer.json`

Exact content. The package name is the lower-case technical identifier; the product name in prose is always "TiffinDB".

```json
{
    "name": "tiffindb/tiffindb",
    "description": "TiffinDB: a self-hosted personal database web application.",
    "type": "project",
    "license": "AGPL-3.0-or-later",
    "require": {
        "php": ">=8.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^12.5"
    },
    "autoload": {
        "psr-4": {
            "TiffinDB\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "TiffinDB\\Tests\\Support\\": "tests/Support/"
        }
    },
    "config": {
        "sort-packages": true
    },
    "scripts": {
        "lint": "@php tools/dev/lint.php",
        "test": "phpunit",
        "check": [
            "@lint",
            "@test"
        ],
        "serve": [
            "Composer\\Config::disableProcessTimeout",
            "@php -S 127.0.0.1:8080 -t public public/index.php"
        ]
    },
    "scripts-descriptions": {
        "lint": "Syntax-check every PHP file (php -l).",
        "test": "Run the offline PHPUnit suite.",
        "check": "Run lint, then tests. This is what CI runs.",
        "serve": "Serve the application locally with the PHP built-in server."
    }
}
```

Notes:

- This content was checked with `composer validate --strict` during design.
- PHPUnit is the only development dependency. There are no runtime dependencies in M0. Do not add any package without a new decision and a row in `docs/dependencies.md`.
- PHPUnit 12 requires PHP 8.3 or later, which matches the project floor. During design the constraint resolved to 12.5.43 with 24 transitive development packages.
- PHPUnit licence: BSD-3-Clause, confirmed by reading the `LICENSE` file on the project's main branch ("BSD 3-Clause License"). The implementer of WP4 re-confirms from `vendor/phpunit/phpunit/LICENSE` after installing and records the installed version.
- PHP extension requirements (`pdo_sqlite`, `zip`, `xmlreader`) are added to `require` by the milestone that first uses each one. M0 application code uses only the PHP core. The tests use `ext-dom` and `ext-libxml`, which PHPUnit itself requires.
- `composer.lock` is git-ignored (ADR 0008 decision 12: the lock file embeds package authors' names and email addresses).
- `vendor/` is git-ignored during development and is shipped only inside the release archive (M7).
- Environment note for whoever runs the install: Composer downloads package archives from the code host. Where that host is unreachable, `composer install` may be slow or fail; that is an environment limitation, not a defect in the manifest.

`phpunit.xml.dist`, exact content (validated against the PHPUnit 12.5 schema during design):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) TiffinDB contributors -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnEmptyTestSuite="true"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="all">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <ini name="error_reporting" value="-1"/>
        <ini name="display_errors" value="1"/>
    </php>
</phpunit>
```

A `<source>` element is added in M1, when `src/Core/` exists.

`tools/dev/lint.php`: a PHP CLI script that walks the repository (excluding `.git/`, `vendor/`, `node_modules/`, `data/`, `local/`, `.phpunit.cache/`), runs `php -l` with the current PHP binary (`PHP_BINARY`) on every `*.php` file, prints each failure, prints a one-line summary, and exits 0 only if every file passed and at least one file was checked. It takes no arguments and makes no network requests.

`config/app.php`: returns a PHP array with exactly these keys.

| Key | M0 value | Meaning |
|---|---|---|
| `version` | `'0.1.0-dev'` | Application version; used in asset URLs |
| `source_url` | The public URL of this repository, copied from the "Source code" link in the footer of `docs/design/model-ui/projects.html` | Target of the footer link (AGPL section 13) |
| `appearance_preview` | `true` | Enables the M0 query-parameter appearance mechanism (section 7). Set to `false` in M1 |

No secrets ever go in `config/`. Installation-specific settings arrive with the installer (M1) and live under `data/`.

## 4. `.gitignore`

Exact content. It keeps every existing entry and adds the tooling entries.

```gitignore
# Local databases, exports, and secrets (see CLAUDE.md, Public Repository Rules)
*.db
*.sqlite*
*.tiffin
.env*
/local/
/data/

# Legacy source files used for import discovery must never be committed
*.bentodb
*.bentoTemplate
*.bento

# Dependencies and tooling
# vendor/ is git-ignored during development and shipped only in the release archive.
/vendor/
# The lock file embeds third-party authors' names and email addresses (ADR 0008).
/composer.lock
/composer.phar
.phpunit.cache/
/phpunit.xml
# npm is a download channel for font files only; nothing from it is committed.
/node_modules/
/package.json
/package-lock.json
*.tgz

# Release build output (M7)
/build/
/dist/

# Operating system and editor files
.DS_Store
Thumbs.db
*.swp
.idea/
.vscode/
```

The six entries `*.db`, `*.sqlite*`, `*.tiffin`, `.env*`, `/local/`, `/data/` are mandatory (`CLAUDE.md`) and must each appear on a line of their own. `.gitignore` carries no SPDX header (section 13).

## 5. Web server files

Both files were written for Apache 2.4 and have **not** been exercised against a running Apache server in M0 (none is available to the test suite). `tests/repo/HtaccessTest.php` checks their content statically. Real-server verification belongs to the host check and the M1 installer.

### 5.1 `public/.htaccess`

Exact content:

```apache
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) TiffinDB contributors
#
# TiffinDB web root. Existing files (static assets) are served directly;
# everything else goes to the front controller.

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>

<IfModule mod_mime.c>
    AddType font/woff2 .woff2
    AddType image/svg+xml .svg
</IfModule>
```

Reasoning an implementer needs:

- Directories are not files, so a directory URL such as `/assets/` goes to the front controller and gets the 404 page. No directory listing is possible, and no `Options` directive is used (on some shared hosts `Options` in `.htaccess` causes a server error).
- `index.php` is the only PHP file under `public/`. Nothing may ever write files under `public/`.

### 5.2 Root `.htaccess`

Exact content:

```apache
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) TiffinDB contributors
#
# Guard for installations where the whole TiffinDB tree sits under the
# document root. Every request is passed into public/, so nothing else
# (src/, templates/, vendor/, data/, tests/, tools/) can be fetched.
# Where the document root can be pointed at public/, this file is unused.

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>

<IfModule !mod_rewrite.c>
    Require all denied
</IfModule>
```

Assumption to verify on a real server: because `public/.htaccess` sets `RewriteEngine On` itself, the root rules are not re-applied inside `public/`, so there is no rewrite loop.

## 6. Request pipeline

All classes are in namespace `TiffinDB\Web`, are `final`, and follow ADR 0008 decision 6. Signatures below are the contract between WP5 and WP6; internal details are the implementer's.

### 6.1 `public/index.php`

In order:

1. SPDX block and `declare(strict_types=1);`.
2. Built-in development server only (`PHP_SAPI === 'cli-server'`): if the request path resolves, by `realpath`, to an existing regular file inside `public/` other than a `.php` file, `return false` so the server sends it. The resolved path must start with the real path of `public/` followed by a directory separator.
3. If `../vendor/autoload.php` does not exist: send status 500 with `Content-Type: text/plain; charset=utf-8` and one plain sentence saying the application's dependencies are not installed. Reveal no paths. Stop.
4. Require the autoloader. Load `../config/app.php`. Build the kernel with the configuration and the project root path. `Request::fromServer($_SERVER, $_GET)`, `handle()`, `send()`.

No other logic belongs here. `$_POST`, `$_COOKIE`, and `$_FILES` are not read in M0.

### 6.2 `Request`

Immutable. `Request::fromServer(array $server, array $query): self` and a plain constructor for tests.

| Property | Source and rule |
|---|---|
| `method` | `REQUEST_METHOD`, upper-cased; default `GET` |
| `path` | The route path (see below); always starts with `/` |
| `basePath` | Detected base path; empty string or a path starting with `/` and not ending with `/` |
| `query` | The query array as given. Values may be strings or arrays; consumers must check the type |

Path and base-path detection (unit-tested with the table in section 15.2, T-WEB-2):

1. `uriPath` = `REQUEST_URI` up to but not including the first `?`. If absent, `/`.
2. Percent-decode `uriPath` once (`rawurldecode`). If the result contains a NUL byte or is not valid UTF-8, the request is malformed: the kernel answers 400 with the error page.
3. `scriptDir` = directory part of `SCRIPT_NAME` with back-slashes normalised to `/` and any trailing `/` removed (so `/index.php` gives the empty string, `/sub/public/index.php` gives `/sub/public`).
4. If `scriptDir` is empty: `basePath` is empty.
5. Else if `uriPath` equals `scriptDir` or starts with `scriptDir . '/'`: `basePath` = `scriptDir`.
6. Else if `scriptDir` ends with `/public`, let `parent` be `scriptDir` without that suffix; if `parent` is empty, or `uriPath` equals `parent` or starts with `parent . '/'`: `basePath` = `parent`. (This is the root-guard case in section 5.2.)
7. Else `basePath` is empty.
8. `path` = `uriPath` with the `basePath` prefix removed; if the result is empty, `/`.

The `Host` header is never used to build URLs. All generated URLs are path-absolute (`/base/...`), so no host-header trust question arises in M0.

### 6.3 `Response`

Immutable value: `status` (int), `headers` (map of name to string), `body` (string). `withHeader()` returns a copy. `send()` calls `header_remove('X-Powered-By')`, sets the status, emits the headers, and writes the body unless told the request was `HEAD`. `send()` is the only code that calls `header()`.

### 6.4 `Router` and `routes.php`

- `src/Web/routes.php` returns the route table (ADR 0008 decision 2). M0 content: one route, `GET /` to `HomeController::show`.
- `Router::match(string $method, string $path): RouteMatch`. A `RouteMatch` is one of: found (handler and parameters), not found, or method not allowed (with the list of allowed methods).
- `HEAD` matches `GET` routes.
- Matching is exact and case-sensitive. `/` is the only path that may end with a slash. Placeholders `{name}` match one segment of `[A-Za-z0-9_-]+` (not used by an M0 route, but implemented and tested so M1 does not redesign the router).
- The constructor rejects a malformed table (unknown method, pattern not starting with `/`, duplicate method and pattern, handler not a two-element class-and-method list) with an exception. A test loads the real table.

### 6.5 `Kernel`

`new Kernel(array $config, string $projectRoot)`; `handle(Request $request): Response`.

1. Malformed request (section 6.2 step 2): 400 error page.
2. Method not one of `GET`, `HEAD`: 405 error page with `Allow: GET, HEAD`. (M1 adds `POST` together with CSRF.)
3. Router result: not found gives the 404 error page; method not allowed gives 405 with the `Allow` header from the match; found calls the controller method.
4. Any `Throwable` from steps 1 to 3 is caught: log one line with `error_log` (class, message, file, line; no request data), return the 500 error page. If rendering the error page itself throws, return a 500 response with a short fixed plain-text body.
5. Every response leaving `handle()`, including every error response, passes through `SecurityHeaders::apply()` exactly once.

The kernel builds the `Appearance` (section 7) once per request and passes it, with the `UrlGenerator`, `Strings`, and configuration, to the `View`. Construction is explicit `new` calls in the kernel; there is no container.

Error pages use the same layout and shell as the home page, with a title, one sentence, and a link to the start page. Status codes used in M0: 200, 400, 404, 405, 500.

### 6.6 `UrlGenerator`

- `to(string $path): string` returns `basePath . $path` for an application path that starts with `/`.
- `asset(string $file): string` returns `basePath . '/assets/' . $file . '?v=' . rawurlencode(version)`. `$file` must match `^[a-z0-9][a-z0-9./-]*$` and must not contain `..`; otherwise throw.
- Both return unescaped strings; the view helpers escape them.

### 6.7 `Strings`

Loads `strings/en.php` once. `get(string $key, array $params = []): string` returns the raw text with `{name}` placeholders replaced; unknown key or missing parameter throws `OutOfBoundsException`. The view helper `t()` escapes parameters and text (ADR 0008 decision 5).

### 6.8 `View`

- `page(string $template, array $data, int $status = 200): Response` renders `templates/pages/<template>.php` to a string, then renders `templates/layout.php` with that string available as the `content` slot, and returns an HTML response.
- Template names are fixed strings written in controllers. They must match `^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)*$`; anything else throws. A template name is never derived from request data.
- Inside a template, `$this` is a view-scope object and `$data` is the array passed by the controller. Nothing else is in scope. `extract` is not used.
- Scope helpers, all returning strings safe to print: `e(string|int|float|null $value)`, `t(string $key, array $params = [])`, `url(string $path)`, `asset(string $file)`, `partial(string $name, array $data = [])`, `slot(string $name)`. Layout-only accessors that return already-escaped strings may be added under the same rule (they must be called through `<?= $this->...( ?>` and be covered by tests): M0 needs `rootClassAttribute()` (section 7.3).
- HTML responses carry `Content-Type: text/html; charset=utf-8`.

### 6.9 `HomeController`

`show(Request $request): Response` returns `View::page('home', [...])`. It passes the list of theme preview links (section 9.3) when `appearance_preview` is on.

## 7. Appearance in M0

### 7.1 Mechanism

Accounts do not exist until M1, so there is no stored setting to read. For M0 the appearance comes from query parameters on the request, each restricted to an allowlist. The parameter names and values are those the mock-ups already use (`docs/design/model-ui/README.md`), plus `motion`.

| Parameter | Accepted values | Root class |
|---|---|---|
| `theme` | `dark`, `hc-light`, `hc-dark` | `t-dark`, `t-hcl`, `t-hcd` |
| `accent` | `indigo`, `plum`, `graphite` | `a-indigo`, `a-plum`, `a-graphite` |
| `font` | `atkinson`, `lexend`, `source-serif`, `device-sans`, `device-serif` | `f-atk`, `f-lexend`, `f-serif4`, `f-sans`, `f-serif` |
| `size` | `small`, `large`, `larger`, `largest` | `s-small`, `s-large`, `s-larger`, `s-largest` |
| `spacing` | `roomy`, `very-roomy` | `l-roomy`, `l-very-roomy` |
| `motion` | `reduce` | `m-reduce` |

Example: `/?theme=dark&accent=plum&font=lexend`.

### 7.2 Rules

- `Appearance::fromQuery(array $query): self` looks each parameter up in a constant map. A missing parameter, a non-string value (for example an array from `theme[]=x`), or a value not in the map gives the default for that setting. No error, no redirect, no message.
- The parameter value is only ever used as a lookup key. It is never written to the response, a header, or a log.
- `rootClasses(): list<string>` returns the classes in the fixed order theme, accent, typeface, size, spacing, motion. Defaults contribute nothing.
- When the theme is `t-hcl` or `t-hcd`, the accent class is omitted whatever the `accent` parameter says.
- When `appearance_preview` is `false`, the kernel uses `Appearance::default()` and ignores the query parameters.
- The mechanism is a development aid for M0. It is not a user feature, is not linked from anywhere except the home page preview list, and does not persist: links on the page do not carry the parameters forward. M1 replaces it with stored per-user settings and turns the flag off.

### 7.3 Root element

The classes go on `<html>`. With no classes the attribute is omitted entirely:

```
<html lang="en">
<html lang="en" class="t-dark a-plum f-lexend">
```

The view exposes this as one helper that returns either an empty string or ` class="..."` built only from `Appearance::rootClasses()`.

## 8. Stylesheets

Three files under `public/assets/css/`, linked from the layout in this order: `fonts.css`, `tokens.css`, `app.css`. No `@import`, no inline styles, no `<style>` element.

### 8.1 The px and rem rule

`docs/design/tokens.css` gives sizes in px while requiring `rem` in its closing comment (context brief section 12 item 11). The application resolves this as follows. "Design px" means the pixel value shown in `docs/design/tokens.css` or the mock-ups, which are drawn at the Standard text size.

- **R1. Root size.** The text-size setting sets `font-size` on `<html>` as a percentage of the browser's default size, never in px, so that a reader's own browser text-size preference still applies. With the usual 16 px browser default these give the sizes in ui-design-plan section 4.3:

  | Step | Root class | `html` font-size | At a 16 px default |
  |---|---|---|---|
  | Small | `s-small` | `100%` | 16 px |
  | Standard | none | `112.5%` | 18 px |
  | Large | `s-large` | `131.25%` | 21 px |
  | Larger | `s-larger` | `150%` | 24 px |
  | Largest | `s-largest` | `175%` | 28 px |

- **R2. Everything scales.** Every length in `tokens.css` and `app.css` is written in `rem`, computed as design px divided by 18 and rounded to four decimal places. This covers font sizes, spacing, control and bar heights, icon sizes, corner radii, and maximum content widths. One `rem` is therefore the body text size at every step.
- **R3. What stays in px.** Only: border widths (`1px`, `2px`), the focus outline width and offset (`3px`, `2px`), the pill radius (`999px`), and the two floors in R4. These are hairlines and limits, not sizes that should grow with text.
- **R4. Target floors.** The minimum touch target and control height must not shrink below their accessibility minimums at the Small step, so they are written as `max(44px, 2.4444rem)` and `max(48px, 2.6667rem)`.
- **R5. No other units for size.** No `px` font sizes, no `zoom`, no `vw`-based font sizes. Line height is unitless and comes from `--leading`. Percentages and `fr` are fine for layout.
- **R6. Media queries.** None are needed in M0 (the shell wraps with flexbox). When a later milestone adds one, the breakpoint is written in `em` (design px divided by 16).

Conversion table for the values M0 uses (design px to `rem`):

| px | rem | px | rem | px | rem |
|---|---|---|---|---|---|
| 4 | 0.2222 | 16 | 0.8889 | 34 | 1.8889 |
| 8 | 0.4444 | 20 | 1.1111 | 36 | 2 |
| 10 | 0.5556 | 22 | 1.2222 | 44 | 2.4444 |
| 12 | 0.6667 | 24 | 1.3333 | 48 | 2.6667 |
| 14 | 0.7778 | 32 | 1.7778 | 68 | 3.7778 |
| 800 | 44.4444 | 1120 | 62.2222 | 18 | 1 |

### 8.2 `tokens.css`

Exact content is in Appendix A. It is `docs/design/tokens.css` with these changes and no others:

1. Colour values, selectors, and their order are unchanged. (High-contrast values are kept as designed; ADR 0009 row C2 records the discrepancy with ADR 0007.)
2. Type and shape tokens are converted by rule R2, with R3 and R4 applied.
3. Added tokens: spacing steps `--space-1` to `--space-8` (4, 8, 12, 16, 20, 24, 32, 48 design px), `--border-card`, `--focus-offset`, `--width-form`, `--width-wide`, `--bar-min`.
4. Added rules: `color-scheme` per theme (so native controls match), the root font size steps (R1), and the line-spacing steps.

`docs/design/tokens.css` remains the design reference. `tests/design/TokenParityTest.php` fails if a colour in one file differs from the other, so a token change (such as the warning fix due before M2 exit) must be made in both.

### 8.3 `fonts.css`

Exact content is in Appendix B: eight `@font-face` rules, `font-display: swap`, WOFF2 only, relative URLs, no `unicode-range`, no `local()` sources. A face is downloaded by the browser only when text actually uses it, so declaring all eight costs nothing for a reader who keeps the default.

### 8.4 `app.css`

Written by WP6. It may reference tokens and must not redefine a colour token. It contains, in this order:

| Block | Requirements |
|---|---|
| Base | `box-sizing: border-box` on everything. `html` and `body` margin 0. `body`: background `--bg`, colour `--ink`, `font-family: var(--font-ui)`, `font-size: var(--text-base)`, `line-height: var(--leading)`, font weight 400. Bold is 700; no other weights anywhere |
| Links | Colour `--accent`; hover colour `--ink`; underlined in running text |
| Focus | `:focus-visible` on every focusable element: `outline: var(--focus-ring) solid var(--accent); outline-offset: var(--focus-offset)`. Never remove an outline without replacing it |
| Skip link | `.skip-link`: visually off-screen until focused; when focused it is visible at the top-left with surface background, accent text, and the standard focus outline |
| Page frame | `.app`: column flex container, minimum height of the viewport, so the footer sits at the bottom of short pages |
| Top bar | `.top-bar`: flex, wrapping, centred items, gap 8 px by 16 px, minimum height `--bar-min`, padding 8 px 24 px, background `--surface`, bottom border `--border-card` solid `--line` |
| Brand link | `.brand`: flex, gap 10 px, minimum height `--target-min`, colour `--accent`, no underline. `.brand-mark`: 34 px square (in `rem`), no shrinking. `.brand-word`: `font-family: var(--font-brand)`, weight 700, size 22 px, `letter-spacing: -0.025em`. `.brand-word-a` ("Tiffin"): colour `--ink`; the remainder ("DB") inherits `--accent` |
| Mark | `.mark-frame { fill: none; stroke: currentColor }`, `.mark-part { fill: currentColor }`, `.mark-tier2 { fill: var(--brand2) }`. Colours are set here, not by attributes in the SVG, so the mark follows theme and accent (ADR 0009 row A2) |
| Main | `.page`: grows to fill, full width up to `--width-wide`, centred, padding 36 px 24 px 48 px. `h1`: `--text-h1`, line height 1.2, margin 0. Secondary text uses `--ink2` |
| Preview list | The theme preview links (section 9.3): a wrapping row of links, each at least `--target-min` high |
| Footer | `.site-footer`: flex, wrapping, gap 8 px by 24 px, padding 16 px 24 px, top border `--border-card` solid `--line`, font size `--text-small`, colour `--ink2` |
| Movement | Under `@media (prefers-reduced-motion: reduce)` and, separately, under `.m-reduce`: transitions and animations off, `scroll-behavior: auto` |

Layout must not scroll sideways at 320 CSS px width or at the Largest text size; the top bar and footer wrap instead.

The letter-spacing of the wordmark follows `docs/brand/README.md` (-0.025 em). The mock-ups use -0.01 em; the brand reference is taken as the rule. This is an assumption to confirm at the M1 visual check.

## 9. Shell markup

### 9.1 Document

Output is HTML5 in XML-well-formed syntax (ADR 0008 decision 4). The layout produces this structure; attribute order and whitespace are not significant.

```
<!DOCTYPE html>
<html lang="en" [class="..."]>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>[page title] - TiffinDB</title>
  <link rel="stylesheet" href="[base]/assets/css/fonts.css?v=[version]" />
  <link rel="stylesheet" href="[base]/assets/css/tokens.css?v=[version]" />
  <link rel="stylesheet" href="[base]/assets/css/app.css?v=[version]" />
</head>
<body>
  <a class="skip-link" href="#main">[shell.skip]</a>
  <div class="app">
    <header class="top-bar">            (partial: top-bar)
      <a class="brand" href="[base]/" aria-label="[shell.brand.label]">
        <svg class="brand-mark" ...>    (partial: mark)
        <span class="brand-word"><span class="brand-word-a">Tiffin</span>DB</span>
      </a>
    </header>
    <main id="main" class="page" tabindex="-1">
      [content slot]
    </main>
    <footer class="site-footer">        (partial: footer)
      <span>[shell.footer.licence]</span>
      <a href="[source_url]" rel="noopener noreferrer">[shell.footer.source]</a>
    </footer>
  </div>
</body>
</html>
```

Requirements:

- Exactly one each of `header`, `main`, `footer`, and `h1` per page. The `h1` is in the page template.
- The home page title is just "TiffinDB" (no repeated name).
- No element has a `style` attribute or an attribute whose name starts with `on`. There is no `script` or `style` element.
- The footer appears on every page, including error pages (AGPL section 13; ui-design-plan section 5).
- The top bar in M0 has the brand link only. The Display link, user chip, and "Sign out" link arrive with accounts in M1 (ADR 0009 row A5).

### 9.2 Mark

`templates/partials/mark.php` draws the mark inline, using the geometry of `docs/brand/tiffindb-mark.svg` (64 by 64 unit grid; frame path, handle, lid, three tiers) with `viewBox="0 0 64 64"`, as in the mock-up top bar. Differences from the brand file: `aria-hidden="true"` and `focusable="false"` on the `svg`, no `title`, no `width`, `height`, `fill`, or `stroke` colour attributes; each shape carries a class instead (`mark-frame` for the frame path, `mark-tier2` for the middle tier, `mark-part` for the rest). The frame keeps `stroke-width="3.5"` and `stroke-linecap="round"` as attributes, since they are geometry. Do not alter the geometry (`docs/brand/README.md`).

### 9.3 Home page

`templates/pages/home.php`: an `h1`, one sentence of secondary text and, when `appearance_preview` is on, a `nav` labelled by `home.preview.label` containing four links, one per theme: `[base]/`, `[base]/?theme=dark`, `[base]/?theme=hc-light`, `[base]/?theme=hc-dark`. The link for the theme currently shown carries `aria-current="true"`.

### 9.4 Strings

`strings/en.php` in M0. Wording may be refined by WP6 within the plain-language principle (ui-design-plan section 3); keys are fixed.

| Key | Text |
|---|---|
| `app.name` | TiffinDB |
| `shell.skip` | Skip to main content |
| `shell.brand.label` | TiffinDB, start page |
| `shell.title` | {title} - TiffinDB |
| `shell.footer.licence` | TiffinDB, free software under AGPL-3.0-or-later |
| `shell.footer.source` | Source code |
| `home.title` | TiffinDB |
| `home.lead` | This is an early build. There is nothing to set up or sign in to yet. |
| `home.preview.label` | Preview a theme |
| `home.preview.light` | Light |
| `home.preview.dark` | Dark |
| `home.preview.hc-light` | High contrast, light |
| `home.preview.hc-dark` | High contrast, dark |
| `error.back` | Go to the start page |
| `error.400.title` | That address is not valid |
| `error.400.body` | TiffinDB could not read the address of this page. |
| `error.404.title` | Page not found |
| `error.404.body` | There is no page at this address. |
| `error.405.title` | That action is not available here |
| `error.405.body` | This page can be viewed but not changed. |
| `error.500.title` | Something went wrong |
| `error.500.body` | TiffinDB could not show this page. Nothing was changed. |

The footer licence line is the wording used in the mock-ups.

## 10. Fonts

### 10.1 What is bundled

Four families, upright only, weights 400 and 700, Latin subset, WOFF2 (ui-design-plan sections 4.3 and 10; ADR 0007).

| Family | Directory | Channel package and version | Font version reported by the channel |
|---|---|---|---|
| Inter | `public/assets/fonts/inter/` | `@fontsource/inter` 5.3.0 | v20 |
| Atkinson Hyperlegible | `public/assets/fonts/atkinson-hyperlegible/` | `@fontsource/atkinson-hyperlegible` 5.3.0 | v12 |
| Lexend | `public/assets/fonts/lexend/` | `@fontsource/lexend` 5.3.0 | v26 |
| Source Serif 4 | `public/assets/fonts/source-serif-4/` | `@fontsource/source-serif-4` 5.3.1 | v15 |

npm is used only as a download channel. It must not become a project dependency or a build step: no `package.json`, no `node_modules/`, and no tarball may be left in the working tree (the `.gitignore` entries are a second line of defence, not permission). The font files are committed assets and are never regenerated.

### 10.2 Procedure (WP4)

Work in a temporary directory outside the repository.

1. `npm pack @fontsource/inter@5.3.0 @fontsource/atkinson-hyperlegible@5.3.0 @fontsource/lexend@5.3.0 @fontsource/source-serif-4@5.3.1`
2. Extract each tarball. Take from `package/files/` exactly the two files named `<id>-latin-400-normal.woff2` and `<id>-latin-700-normal.woff2`. Take nothing else: no CSS, no other subsets, no italics, no `.woff`, and not the package's own `LICENSE` file (its copyright header is generated by the channel and differs from the upstream file).
3. Fetch each licence file from the family's upstream project repository (URLs in 10.3) and save it as `LICENSE.txt` in the family directory, byte for byte.
4. Verify every file against the SHA-256 values in 10.3 before copying it into the repository. If any value differs, stop and report; do not commit a file whose checksum does not match, and do not update the checksum to fit.
5. Copy the files to the paths in section 2.1. File names are kept as published so they can be traced.

### 10.3 Files, sizes, and checksums

Measured during design.

| File (under `public/assets/fonts/`) | Bytes | SHA-256 |
|---|---|---|
| `inter/inter-latin-400-normal.woff2` | 23,664 | `8909904ab6c872eb994093482a88a28eca2cd95912d7b6fecd72103b0dc07edc` |
| `inter/inter-latin-700-normal.woff2` | 24,356 | `6f56409fd3d64bb85f7d070bce20749db2d66b6d63cec586cc22d1c761be2491` |
| `atkinson-hyperlegible/atkinson-hyperlegible-latin-400-normal.woff2` | 17,208 | `d64ba838ef5472bba248620ec4fd8b5aa7cf0db2908e0bb230600caf279ba7bc` |
| `atkinson-hyperlegible/atkinson-hyperlegible-latin-700-normal.woff2` | 17,524 | `140e2bd25a7315c8a062508391426b0d8c3297400c947b8d847be28f73a199f0` |
| `lexend/lexend-latin-400-normal.woff2` | 14,476 | `0601e0a909219a542cdd581e3f8f1ff8fb208978cbc9bca1c90df02fd8062bb1` |
| `lexend/lexend-latin-700-normal.woff2` | 14,808 | `52dcdaa127bac4a5ea4c39ee17a3f454ceacc18d9f2a45cc453eeab68d378588` |
| `source-serif-4/source-serif-4-latin-400-normal.woff2` | 20,088 | `02194deb92d3975dd30e11a3824a1f1db32b48c93654e60560cb81ce8e7b5f95` |
| `source-serif-4/source-serif-4-latin-700-normal.woff2` | 21,716 | `7691c51bc286a9014db0048277d2c3f2ad0a90b533dc8adfb16cb22a95390d39` |
| Eight font files | 153,840 | |

Licence files, each saved as `LICENSE.txt` in the family directory:

| Family | Upstream licence file (on `raw.githubusercontent.com`) | Bytes | SHA-256 |
|---|---|---|---|
| Inter | `rsms/inter/master/LICENSE.txt` | 4,380 | `262481e844521b326f5ecd053e59b98c8b2da78c8ee1bdbb6e8174305e54935a` |
| Atkinson Hyperlegible | `googlefonts/atkinson-hyperlegible/main/OFL.txt` | 4,352 | `f32d22b3908fcad2c86a74000614ec22e6a7f66ea7e867e616026a27aebdc143` |
| Lexend | `googlefonts/lexend/main/OFL.txt` | 4,436 | `5da8505887d0fa7fe963445fd58852707fda34adfeb65af25c99d152bab285bd` |
| Source Serif 4 | `adobe-fonts/source-serif/release/LICENSE.md` | 4,491 | `c21d7293d87b6d7ab1d0229a2f55b77f33a7613a6a4e66f6693d68d7d8d09464` |
| Four licence files | | 17,659 | |

These URLs point at branches, not tags, so a file may change upstream. The checksum is the pin: if a fetched file no longer matches, stop and report (step 4).

### 10.4 Payload and the 2 MB rule

Total font payload: 153,840 bytes (about 150 KiB) for the eight font files; 171,499 bytes with the licence files. The rule in ADR 0009 row B2 (ship only Inter and Atkinson Hyperlegible if the four families exceed 2 MB) is **not triggered**. All four families ship. `tests/design/FontAssetsTest.php` keeps a 2,097,152-byte ceiling on the font directory so that a later addition cannot pass the limit unnoticed.

### 10.5 Licence confirmation

What was verified during design, and how:

- Each of the four upstream licence files above was fetched and read. Each states that the font software is licensed under the SIL Open Font License, Version 1.1, followed by the licence text. SPDX identifier: `OFL-1.1`.
- Each of the eight font files was decompressed and its embedded name strings read. Each carries an Open Font License URL as its licence URL, and a copyright string consistent with the upstream licence file for its family.
- The channel package metadata declares `OFL-1.1` for all four.

What was not verified:

- That the channel's files are built from the upstream sources without other changes. The channel's metadata points to a font-service distribution directory per family; the build itself was not audited.
- The Reserved Font Name question for Source Serif 4 described in ADR 0009 ("Font licences"). It is flagged for the maintainer; the provisional position is to bundle the family.

OFL-1.1 is approved for bundled font assets only (ADR 0004, `CLAUDE.md`).

## 11. `docs/dependencies.md`

### 11.1 Format

One Markdown file with a short introduction, the rules, and one table per category. Categories, in this order: "Runtime dependencies (shipped)", "Bundled font assets (shipped)", "Development dependencies (not shipped)". An empty category keeps its heading and says "None."

Columns for package tables:

| Column | Content |
|---|---|
| Name | Package or project name |
| Version | Constraint in `composer.json`, and the version installed when the row was last verified |
| Purpose | One phrase |
| Licence | SPDX identifier |
| Verified from | Which licence file was read, and the date (ISO 8601) |
| Shipped | Yes or No |

Columns for the font table: Family, Files (directory), Source (channel package and version), Licence, Verified from (upstream licence file and date), Licence file shipped (path).

Rules stated in the file:

- A dependency is added to this file in the same change that adds it to the project.
- The licence is confirmed from the dependency's own licence file, never from memory or from a third-party summary. If it could not be confirmed, the row says so and the dependency is not merged.
- Allowed licences are those listed in `CLAUDE.md`; `OFL-1.1` for font assets only.
- No personal names or email addresses are written in this file. Copyright notices stay in the shipped licence files, which are reproduced verbatim as their licences require.
- Transitive development packages are not listed one by one. They are covered by `tests/repo/DependencyLicenceTest.php`, which reads the licence each installed package declares and fails on anything outside the allowlist. The file states the count and the licences seen at the last verification.

### 11.2 Content for M0 (WP4)

- Runtime dependencies: None.
- Bundled font assets: four rows from sections 10.1 and 10.3, licence `OFL-1.1`, with the checksum of each shipped licence file, and a pointer to ADR 0009 for the open Reserved Font Name question.
- Development dependencies: one row, `phpunit/phpunit`, constraint `^12.5`, the installed version, purpose "Test runner", licence `BSD-3-Clause`, verified from `vendor/phpunit/phpunit/LICENSE` on the date of verification, shipped No. Below the table: "Transitive development packages: N, declaring BSD-3-Clause or MIT in their package metadata (checked automatically)", with N taken from the actual install. At design time N was 24.
- A "Checksums" subsection listing the eight font files with size and SHA-256 (the table in section 10.3).

The file is prose and tables only. It carries no SPDX header (section 13).

## 12. Continuous integration

`.github/workflows/ci.yml`, exact content:

```yaml
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) TiffinDB contributors

name: CI

on:
  push:
  pull_request:

permissions:
  contents: read

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  check:
    runs-on: ubuntu-24.04
    timeout-minutes: 10
    steps:
      - name: Check out
        uses: actions/checkout@v4
        with:
          persist-credentials: false

      - name: Require PHP 8.3 or later
        run: |
          php --version
          composer --version
          php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'

      # The only step that uses the network. Lint and tests run offline.
      - name: Install development dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Lint
        run: composer lint

      - name: Tests
        run: composer test
```

Notes and assumptions:

- GitHub Actions is the CI system. The workflow needs no secrets and has read-only permissions.
- Assumption: the `ubuntu-24.04` hosted runner image provides PHP 8.3 or later and Composer 2. This could not be checked from the design environment. If the assumption fails, the "Require PHP" step fails clearly; the remedy is an ADR 0008 amendment choosing a setup method.
- Only one PHP version is tested. A version matrix needs a setup action and is deferred with that decision.
- The checkout action is referenced by major version tag. Pinning it to a full commit identifier is better practice; the identifier could not be looked up from the design environment and is left to the maintainer.
- "Offline" is a property of the tests themselves (they open no sockets and start no server), not something the workflow enforces by cutting the network. Enforcing it in the workflow was considered and left out as fragile.

## 13. SPDX header convention

Every source file carries both lines, exactly:

```
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) TiffinDB contributors
```

The copyright line names "TiffinDB contributors", never an individual, and has no year.

| File type | Form | Position |
|---|---|---|
| PHP classes, scripts, configuration, strings (`*.php`) | One block comment: `/*`, ` * SPDX-License-Identifier: ...`, ` * Copyright (C) ...`, ` */` | Immediately after the `<?php` line (after a `#!` line and `<?php` in an executable script). A description may follow inside the same block. Then a blank line and `declare(strict_types=1);` |
| PHP templates (`templates/**/*.php`) | Same as PHP | The file starts with the PHP block, then `declare(strict_types=1);`, then `?>` and the markup. The header is a PHP comment so it never reaches the browser |
| CSS (`*.css`) | Two comments, `/* SPDX-... */` and `/* Copyright ... */` | First two lines |
| JavaScript (`*.js`) | Two `//` comments | First two lines |
| Static HTML (`*.html`) | Two `<!-- -->` comments | Immediately after the doctype line |
| SVG (`*.svg`) | Two `<!-- -->` comments | First two lines, or immediately after an XML declaration |
| XML (`*.xml`, `*.xml.dist`) | Two `<!-- -->` comments | Immediately after the XML declaration |
| `.htaccess` | Two `#` comments | First two lines |
| Shell (`*.sh`) | Two `#` comments | Immediately after the `#!` line |
| YAML (`*.yml`, `*.yaml`) | Two `#` comments | First two lines |

In every case both lines must appear within the first six lines of the file.

Files that do not carry a header:

| Kind | Rule |
|---|---|
| JSON (`composer.json`, `docs/design/model-ui/canvas-source/canvas.json`) | JSON has no comments. `composer.json` states the licence in its `license` field, and a test checks it. Any other JSON file must be listed by path in the exemption list of the SPDX test, with a reason |
| Binary fonts (`*.woff2`) | Third-party assets under their own licence. Covered by the `LICENSE.txt` in the same directory and by `docs/dependencies.md`. Never given an AGPL header or sidecar |
| Third-party licence texts (`public/assets/fonts/*/LICENSE.txt`) | Reproduced verbatim; never edited |
| `LICENSE` | The canonical licence text; never edited (`CLAUDE.md`) |
| Markdown (`*.md`) | Documentation, not source; covered by the repository licence. No header required |
| Git control files (`.gitignore`, `.gitattributes`) | No header required |
| Generated or ignored paths (`vendor/`, `.phpunit.cache/`, `data/`, `local/`, `node_modules/`) | Not scanned |

No sidecar licence files (`<name>.license`) are used. The exemption list in `tests/repo/SpdxHeaderTest.php` is the register of exceptions.

The test works on a closed-world basis: it walks every file in the repository outside the non-scanned paths, and each file must either match a header rule, match an exemption, or the test fails naming the file. A new file type therefore forces a decision instead of slipping through.

## 14. Security baseline for M0

### 14.1 Response headers

Sent by `SecurityHeaders::apply()` on every response from the kernel:

| Header | Value |
|---|---|
| `Content-Security-Policy` | `default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `same-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `Permissions-Policy` | `camera=(), geolocation=(), microphone=(), payment=(), usb=()` |
| `Cache-Control` | `no-store` |

Also: `X-Powered-By` is removed.

Reasons, briefly:

- The CSP has no `'unsafe-inline'`, no `'unsafe-eval'`, and no external origin. Anything not listed falls to `default-src 'none'` (objects, frames, media, workers, manifests). `script-src 'self'` and `connect-src 'self'` are present, although M0 ships no script, so that adding the first script later does not require touching the policy. `frame-ancestors 'none'` and `X-Frame-Options: DENY` both forbid framing; the second covers older browsers. `base-uri 'none'` because the application never uses a `base` element.
- `Referrer-Policy: same-origin` keeps addresses, which will contain project and record identifiers, from reaching other sites, including the "Source code" link target.
- `Cache-Control: no-store` on pages, because from M1 pages hold personal data. Static assets are served by Apache and are not affected.
- Not sent: `Strict-Transport-Security` (ADR 0008 decision 9) and `X-XSS-Protection` (obsolete).

The policy string is built from an ordered constant list in `SecurityHeaders`; a test asserts the exact string above.

### 14.2 Other M0 rules

- Output escaping through the view helpers only (section 6.8); a test scans the templates.
- Request data is never reflected. The only request input M0 reads is the path (matched against the route table) and the appearance parameters (used as lookup keys).
- Error responses reveal no paths, class names, or stack traces. `display_errors` is not relied upon: the kernel catches everything.
- No file is written at run time. No network request is made by the application (`CLAUDE.md`; plan section 7).
- The "Source code" link is the only external URL in the output, and it comes from configuration, not from request data.
- `.gitignore` is in place before any code exists that could create a data file (WP1 comes first).

## 15. Test plan

Tests are PHPUnit tests under `tests/` (ADR 0008 decision 7), run by `composer test`, offline and deterministic. Identifiers are for reference in work packages and review.

### 15.1 Repository rules (`tests/repo/`)

| ID | Test | What it asserts | Exit criterion |
|---|---|---|---|
| T-REPO-1 | `GitignoreTest` | `.gitignore` contains each of `*.db`, `*.sqlite*`, `*.tiffin`, `.env*`, `/local/`, `/data/`, `/vendor/`, `/composer.lock` as a whole line | `.gitignore` entries exist |
| T-REPO-2 | `ComposerManifestTest` | `composer.json` name is `tiffindb/tiffindb`; licence is `AGPL-3.0-or-later`; `require` has only `php`; `require-dev` has only `phpunit/phpunit`; scripts `lint`, `test`, `check` exist; neither the name nor the description contains the legacy product's name (case-insensitive) | Scaffolding |
| T-REPO-3 | `HtaccessTest` | Both `.htaccess` files exist; `public/.htaccess` routes to `index.php` and contains no `Options` directive; the root file rewrites to `public/` and denies when `mod_rewrite` is absent; `public/` contains exactly one `*.php` file | Empty app serves a page (static part) |
| T-REPO-4 | `SpdxHeaderTest` | Section 13, closed-world. Includes `tools/discovery/` and `tools/host-check/` | Licensing rule |
| T-REPO-5 | `PhpSourceRulesTest` | Every `*.php` file outside non-scanned paths passes `php -l` (same file set as `tools/dev/lint.php`). Every such file also declares `strict_types=1`, except the standalone scripts directly under `tools/discovery/` and `tools/host-check/` (section 2.2), which are linted but not required to declare it | Lint all PHP |
| T-REPO-6 | `DependencyLicenceTest` | Reads `vendor/composer/installed.json`; every installed package declares at least one licence from the `CLAUDE.md` allowlist expressed as SPDX identifiers (MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0, ISC, LGPL-2.1-or-later, LGPL-3.0-or-later, MPL-2.0, GPL-3.0-or-later, AGPL-3.0-or-later, and their `-only` forms for version 3). Fails on a package with no declared licence | Licensing rule |
| T-REPO-7 | `LayerRulesTest` | No file under `src/Core/` mentions `TiffinDB\Web` or `TiffinDB\Mcp`, or the superglobals. Passes trivially while `src/Core/` does not exist; the test must say so in its output message, not be skipped | Layering (from M1) |

Running `php -l` from a test starts a child PHP process per file. That is acceptable at this size; if it becomes slow, T-REPO-5 keeps the `strict_types` check and leaves syntax to `composer lint`.

### 15.2 Request pipeline and shell (`tests/web/`)

| ID | Test | What it asserts | Exit criterion |
|---|---|---|---|
| T-WEB-1 | `RouterTest` | Exact match; `HEAD` matches `GET`; unknown path is not found; known path with another method reports allowed methods; placeholder matching and rejection of bad segments; trailing slash is not found; malformed tables are rejected; the real `routes.php` loads | Empty app serves a page |
| T-WEB-2 | `RequestTest` | Base-path table below; query string stripped; percent-decoding; NUL and invalid UTF-8 flagged as malformed | Empty app serves a page |
| T-WEB-3 | `SecurityHeadersTest` | Exact header set and exact CSP string of section 14.1; applied to 200, 404, 405, and 500 responses; the CSP contains none of `unsafe-inline`, `unsafe-eval`, `data:`, `http:`, `https:`, `*` | Security baseline |
| T-WEB-4 | `UrlGeneratorTest`, `StringsTest`, `ViewTest` | Base path prefixing; asset version parameter; rejection of unsafe asset names; missing string key throws; placeholders are escaped; `e()` escapes `& < > " '`; invalid template names throw | Empty app serves a page |
| T-WEB-5 | `ShellTest` (status) | `GET /` gives 200 and `text/html; charset=utf-8`; `HEAD /` gives 200; `GET /nope` gives 404 with the shell; `POST /` gives 405 with `Allow`; a controller that throws (injected through a test route table) gives 500 with the shell and no exception text in the body | Empty app serves a page |
| T-WEB-6 | `ShellTest` (themes) | For each of the four themes requested by the `theme` parameter, the `class` attribute on `html` is exactly: absent, `t-dark`, `t-hcl`, `t-hcd` | Shell in four themes |
| T-WEB-7 | `AppearanceTest` | Every allowlisted value of every parameter maps to its class; class order; unknown, empty, and array values give defaults; accent omitted under both high-contrast themes; preview flag off ignores parameters; an injected value such as `"><script>` never appears in the output | Shell in four themes |
| T-WEB-8 | `ShellTest` (structure) | Exactly one `header`, `main`, `footer`, `h1`; `html` has `lang="en"`; non-empty `title`; skip link targets an existing `id`; three stylesheet links in the order fonts, tokens, app, each resolving to an existing file under `public/`; brand link present with accessible name; footer has the licence line and a link with text "Source code" whose `href` equals `source_url`, and `source_url` equals the link in the footer of `docs/design/model-ui/projects.html` | Shell renders; AGPL section 13 |
| T-WEB-9 | `ShellTest` (no inline code) | In the output of every M0 page and error page, in every theme: no `style` attribute, no `style` element, no `script` element, no attribute whose name starts with `on`, no `javascript:` URL, and no `http://` or `https://` URL other than `source_url` | No inline style or script |
| T-WEB-10 | `ShellTest` (well-formed) | Every M0 page and error page parses with `DOMDocument::loadXML` (flag `LIBXML_NONET`) with zero libxml errors, and starts with `<!DOCTYPE html>` | HTML well-formedness |
| T-WEB-11 | `TemplateRulesTest` | Every file under `templates/`: each `<?=` begins with an allowed scope helper call; no `echo` or `print`; no `style=` attribute, `<style`, `<script`, or `on...=` attribute in the template source; every string key passed literally to `t()` exists in `strings/en.php`; no key in `strings/en.php` is unused, except keys built at run time, which the test lists explicitly | Escaping rule |

Shared assertions for T-WEB-8 to T-WEB-10 live in `tests/Support/HtmlAssertions.php` so that later milestones apply them to every new page.

Base-path cases for T-WEB-2:

| `SCRIPT_NAME` | `REQUEST_URI` | Expected `basePath` | Expected `path` |
|---|---|---|---|
| `/index.php` | `/` | (empty) | `/` |
| `/index.php` | `/projects?x=1` | (empty) | `/projects` |
| `/tiffindb/index.php` | `/tiffindb/` | `/tiffindb` | `/` |
| `/tiffindb/index.php` | `/tiffindb/projects` | `/tiffindb` | `/projects` |
| `/tiffindb/public/index.php` | `/tiffindb/public/projects` | `/tiffindb/public` | `/projects` |
| `/tiffindb/public/index.php` | `/tiffindb/projects` | `/tiffindb` | `/projects` |
| `/tiffindb/public/index.php` | `/tiffindb` | `/tiffindb` | `/` |
| `/public/index.php` | `/projects` | (empty) | `/projects` |
| `/public/index.php` | `/public/projects` | `/public` | `/projects` |
| `/tiffindb/index.php` | `/elsewhere` | (empty) | `/elsewhere` |

### 15.3 Design assets (`tests/design/`)

| ID | Test | What it asserts | Exit criterion |
|---|---|---|---|
| T-DES-1 | `TokenParityTest` | Using a small parser for the flat custom-property blocks (`tests/Support/CssTokens.php`): every selector block in `docs/design/tokens.css` exists in `public/assets/css/tokens.css`, and every custom property whose value is a hex colour has the same value in both. Neither file has a colour property the other lacks | Tokens copied |
| T-DES-2 | `ContrastTest` (skeleton) | WCAG 2 contrast from relative luminance (`tests/Support/Contrast.php`), ratios rounded to two decimals. Resolves tokens for the default light theme by cascade and asserts the ui-design-plan section 4.2 "Light" column exactly: 16.19, 8.86, 8.05, 9.43, 7.84, 9.43, 6.81, 9.43, 7.43, 4.36. The 6.81 warning figure is asserted as the measured value and marked in the test as a known shortfall against 7:1 (ADR 0009 row A4). The test is structured as a data provider over (theme, accent) so that M2 only adds rows | Token contrast test skeleton |
| T-DES-3 | `ContrastTest` (calculator) | Black on white is 21.00; identical colours give 1.00; the function is symmetric | Token contrast test skeleton |
| T-DES-4 | `StylesheetRulesTest` | Comments are stripped before checking. In `tokens.css` and `app.css`: no `font-size` in px; no `zoom`; every px length is one of `1px`, `2px`, `3px`, `999px`, `44px`, `48px`; no `@import`; no `url(` at all. In `app.css`: no declaration of a colour token defined in `tokens.css`. In all three stylesheets: no `http:` or `https:`. The root font-size steps are exactly the five percentages of rule R1 | px and rem rule; no network fonts |
| T-DES-5 | `FontAssetsTest` (files) | The eight font files exist with the sizes and SHA-256 values of section 10.3; each family directory has a `LICENSE.txt` with the listed SHA-256 containing the words "SIL Open Font License, Version 1.1"; the font directory contains no other files; its total size is at most 2,097,152 bytes | Fonts and licences added |
| T-DES-6 | `FontAssetsTest` (declarations) | `fonts.css` has exactly eight `@font-face` rules; each has `font-display: swap`, a weight of 400 or 700, a family name that `tokens.css` uses, and a relative `url()` that resolves to an existing file; every font file is referenced exactly once; `docs/dependencies.md` mentions each family and each font file name | Fonts recorded |

The full M2 criteria (all ten theme and accent combinations, every figure in ui-design-plan section 4.2, the 7:1 threshold, the fixed warning token) are out of scope here; T-DES-2 is the skeleton they extend.

### 15.4 Manual checks at M0 exit

Not automatable in M0; recorded in the ledger when done.

1. `composer serve`, open the home page, follow each of the four theme links: the shell renders, the mark and wordmark take the theme's colours, the footer is present.
2. Add `&size=largest` and narrow the window to about 320 px: nothing scrolls sideways.
3. Tab through the page: skip link appears first; every focus position is visible.
4. In the browser's network view: only same-origin requests; the only font files fetched are the ones in use (Inter and Atkinson Hyperlegible Bold by default).
5. The browser console shows no Content-Security-Policy violation.

## 16. Work packages

Tiers: "implementation" for work that needs judgement; "mechanical" only where this spec gives exact content. A WP's owned files are those marked with its number in section 2.1. WPs that can run at the same time own disjoint files.

Order: WP1 first. Then WP2, WP3, WP4, and WP5 in parallel. WP6 after WP5. WP7 after WP3 and WP4. The full suite (`composer check`) is expected to pass only when all seven are merged; each WP's own acceptance is stated so it can be judged alone.

### M0-WP1: Repository scaffolding

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (`.gitignore`, both `.htaccess` files, CI permissions) |
| Owned files | `.gitignore`, `.htaccess`, `composer.json`, `phpunit.xml.dist`, `.github/workflows/ci.yml`, `config/app.php`, `public/.htaccess`, `tools/dev/lint.php`, `tests/Support/RepoFiles.php`, `tests/repo/GitignoreTest.php`, `tests/repo/ComposerManifestTest.php`, `tests/repo/HtaccessTest.php` |
| Scope | Sections 3, 4, 5, 12. Exact-content files are copied from this spec without change. `tests/Support/RepoFiles.php` is the shared repository walker (project root, non-scanned paths, file listing by extension) used by WP2 and WP7 |
| Acceptance | `composer validate --strict` passes; `composer install` succeeds; `composer lint` exits 0; T-REPO-1, T-REPO-2, T-REPO-3 pass (the "exactly one PHP file under `public/`" assertion in T-REPO-3 passes once WP5 is merged; until then it is expected to fail and must not be weakened); the exact-content files match this spec byte for byte apart from a final newline; `git status` shows no `vendor/`, `composer.lock`, or cache files as untracked candidates |

### M0-WP2: Repository rule tests

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 |
| Security-critical | No |
| Owned files | `tests/repo/SpdxHeaderTest.php`, `tests/repo/PhpSourceRulesTest.php`, `tests/repo/DependencyLicenceTest.php`, `tests/repo/LayerRulesTest.php` |
| Scope | Section 13 and tests T-REPO-4 to T-REPO-7 |
| Acceptance | The four tests pass on the tree as it stands when the WP is delivered, including the existing `docs/` files and the scripts under `tools/`; the SPDX test fails when a header is removed from any one covered file, and when a file of an unlisted type is added (demonstrated by the test's own negative cases against temporary files, not by editing the repository); the exemption list contains exactly the entries of section 13 |

### M0-WP3: Token stylesheet

| | |
|---|---|
| Tier | mechanical |
| Depends on | WP1 |
| Security-critical | No |
| Owned files | `public/assets/css/tokens.css` |
| Scope | Create the file with the exact content of Appendix A |
| Acceptance | The file equals Appendix A byte for byte apart from a final newline; T-DES-1 and T-DES-4 pass once WP7 is merged |

### M0-WP4: Fonts and dependency record

| | |
|---|---|
| Tier | mechanical |
| Depends on | WP1 |
| Security-critical | No (licence-critical: checksums must match) |
| Owned files | `public/assets/css/fonts.css`, everything under `public/assets/fonts/`, `docs/dependencies.md` |
| Scope | Section 10.2 procedure; `fonts.css` from Appendix B; `docs/dependencies.md` per section 11. The one part with judgement is the prose of `docs/dependencies.md`, which is bounded by section 11.2 |
| Acceptance | `sha256sum` of the twelve committed files matches section 10.3; `fonts.css` equals Appendix B; no `package.json`, `node_modules/`, or tarball in the working tree; `docs/dependencies.md` has the three categories and the PHPUnit row with the installed version and the licence re-confirmed from the installed licence file; T-DES-5 and T-DES-6 pass once WP7 is merged. If a checksum does not match, the WP stops and reports |

### M0-WP5: Request pipeline

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 |
| Security-critical | Yes (security headers, escaping, input allowlists, error handling) |
| Owned files | `public/index.php`, everything under `src/Web/`, `tests/web/RequestTest.php`, `RouterTest.php`, `SecurityHeadersTest.php`, `UrlGeneratorTest.php`, `AppearanceTest.php`, `StringsTest.php`, `ViewTest.php`, `tests/web/fixtures/` |
| Scope | Sections 6, 7, 14. The view and strings classes are tested against small fixture templates and a fixture strings file under `tests/web/fixtures/`, so this WP does not need WP6's files. `HomeController` is written against the template name `home` and the keys in section 9.4 |
| Acceptance | T-WEB-1 to T-WEB-4 and T-WEB-7 pass; the kernel can be driven end to end in a test using fixture templates; no class under `src/Web/` reads a superglobal except through `Request::fromServer` being called from `public/index.php`; `header()` is called only in `Response::send()` |

### M0-WP6: Shell templates, strings, and application stylesheet

| | |
|---|---|
| Tier | implementation |
| Depends on | WP5 (code); the token names of Appendix A (not the WP3 file itself) |
| Security-critical | Yes (template escaping; no inline code) |
| Owned files | everything under `templates/`, `strings/en.php`, `public/assets/css/app.css`, `tests/Support/HtmlAssertions.php`, `tests/web/ShellTest.php`, `tests/web/TemplateRulesTest.php` |
| Scope | Sections 8.4 and 9; tests T-WEB-5, T-WEB-6, T-WEB-8 to T-WEB-11 |
| Acceptance | Those tests pass; the manual checks of section 15.4 are done once WP3 and WP4 are merged and their result reported; the mark geometry matches `docs/brand/tiffindb-mark.svg` |

### M0-WP7: Design asset tests

| | |
|---|---|
| Tier | implementation |
| Depends on | WP3, WP4 (and WP1 for the walker). `StylesheetRulesTest` also reads `app.css`; if WP6 is not yet merged it checks the files that exist and says which it checked |
| Security-critical | No |
| Owned files | `tests/Support/CssTokens.php`, `tests/Support/Contrast.php`, `tests/design/TokenParityTest.php`, `tests/design/ContrastTest.php`, `tests/design/StylesheetRulesTest.php`, `tests/design/FontAssetsTest.php` |
| Scope | Tests T-DES-1 to T-DES-6 |
| Acceptance | All six pass; the contrast figures reproduce the "Light" column of ui-design-plan section 4.2 without any tolerance beyond rounding to two decimals; the parity test fails when one colour is changed in a temporary copy of either file |

### Summary

| ID | Tier | Depends on | Security-critical | Scope |
|---|---|---|---|---|
| M0-WP1 | implementation | none | Yes | Composer manifest, `.gitignore`, `.htaccess` files, test runner, lint script, CI, base repository tests |
| M0-WP2 | implementation | WP1 | No | SPDX, PHP source, dependency licence, and layer rule tests |
| M0-WP3 | mechanical | WP1 | No | `tokens.css` from Appendix A |
| M0-WP4 | mechanical | WP1 | No | Font files, licence files, `fonts.css`, `docs/dependencies.md` |
| M0-WP5 | implementation | WP1 | Yes | Front controller, kernel, router, request and response, security headers, view, strings, appearance |
| M0-WP6 | implementation | WP5 | Yes | Templates, strings file, `app.css`, shell tests |
| M0-WP7 | implementation | WP3, WP4 | No | Token parity, contrast skeleton, stylesheet rules, font asset tests |

## 17. Assumptions and open points

Stated so they can be checked or corrected.

1. Apache 2.4 with `mod_rewrite` and `AllowOverride` permitting `mod_rewrite`, `mod_headers`, and `mod_mime` directives in `.htaccess`. Not verified on a real host (deferred host check).
2. The rewrite behaviour described in section 5.2 and the base-path cases in section 15.2 match what Apache actually passes in `SCRIPT_NAME` and `REQUEST_URI` under the root guard. Unit-tested as logic only.
3. The hosted CI runner image provides PHP 8.3 or later and Composer.
4. The font channel's Latin subset files are faithful subsets of the upstream fonts (section 10.5).
5. The Reserved Font Name question for Source Serif 4 is unresolved (ADR 0009).
6. Wordmark letter-spacing follows the brand reference, not the mock-up (section 8.4).
7. XML-well-formed HTML is acceptable as the M0 well-formedness check. It is not full HTML validation; plan section 5 asks for "static HTML validation", and a fuller validator remains to be chosen (no dependency for it has been approved).
8. `composer.lock` stays untracked until the question in ADR 0008 decision 12 is settled.
9. The M0 home page text and the theme preview links are placeholders that M1 replaces with the sign-in and project list screens.

## Appendix A: `public/assets/css/tokens.css`

Exact content.

```css
/* SPDX-License-Identifier: AGPL-3.0-or-later */
/* Copyright (C) TiffinDB contributors */

/*
 * TiffinDB design tokens for the application.
 * Transcribed from docs/design/tokens.css (the design reference, ADR 0007).
 * Colour values must stay identical to that file; a test compares them.
 * Sizes are in rem: design px divided by 18 (docs/specs/M0-scaffolding.md, 8.1).
 *
 * Classes go on the <html> element:
 *   theme      (none) | .t-dark | .t-hcl | .t-hcd
 *   accent     (none) | .a-indigo | .a-plum | .a-graphite   (never with .t-hcl or .t-hcd)
 *   typeface   (none) | .f-atk | .f-lexend | .f-serif4 | .f-sans | .f-serif
 *   text size  (none) | .s-small | .s-large | .s-larger | .s-largest
 *   spacing    (none) | .l-roomy | .l-very-roomy
 *   movement   (none) | .m-reduce
 */

:root {
  /* Surfaces and text */
  --bg: #F3F5F4;          /* page ground */
  --surface: #FFFFFF;     /* cards, bars, inputs */
  --sunk: #E6EBE9;        /* table headers, inset rows */
  --ink: #111A18;         /* primary text */
  --ink2: #3A4744;        /* secondary text */
  --line: #C5CECB;        /* dividers, card borders */
  --ctl: #6E7C78;         /* control borders (3:1 minimum against surface) */

  /* Accent (Teal, the default) */
  --accent: #084F4A;      /* primary buttons, links, focus ring */
  --on-accent: #FFFFFF;   /* text on accent */
  --soft: #DCEEEB;        /* selected and hover tint */

  /* Warnings and destructive actions */
  --warn: #7A4300;
  --warn-soft: #FBEBD3;

  /* Brand second colour (middle tier of the mark) */
  --brand2: #C27F00;

  /* Colour-blocked sidebar */
  --side: #084F4A;
  --side-ink: #FFFFFF;
  --side-ink2: #D3E9E5;
  --side-on: #FFFFFF;     /* current item background */
  --side-on-ink: #084F4A; /* current item text */

  /* Type */
  --font-ui: 'Inter', system-ui, sans-serif;
  --font-brand: 'Atkinson Hyperlegible', system-ui, sans-serif;
  --text-base: 1rem;         /* 18 design px: body text */
  --text-small: 0.8889rem;   /* 16 design px */
  --text-h2: 1.2222rem;      /* 22 design px */
  --text-h1: 1.8889rem;      /* 34 design px */
  --leading: 1.5;

  /* Shape and size */
  --radius-control: 0.4444rem;             /* 8 design px */
  --radius-card: 0.6667rem;                /* 12 design px */
  --radius-pill: 999px;
  --target-min: max(44px, 2.4444rem);      /* minimum touch target */
  --control-height: max(48px, 2.6667rem);  /* form inputs */
  --border-control: 2px;
  --border-card: 1px;
  --focus-ring: 3px;
  --focus-offset: 2px;

  /* Spacing steps: 4, 8, 12, 16, 20, 24, 32, 48 design px */
  --space-1: 0.2222rem;
  --space-2: 0.4444rem;
  --space-3: 0.6667rem;
  --space-4: 0.8889rem;
  --space-5: 1.1111rem;
  --space-6: 1.3333rem;
  --space-7: 1.7778rem;
  --space-8: 2.6667rem;

  /* Layout */
  --width-form: 44.4444rem;  /* 800 design px */
  --width-wide: 62.2222rem;  /* 1120 design px */
  --bar-min: 3.7778rem;      /* 68 design px: top bar */
}

/* Themes */
.t-dark {
  --bg: #0F1716; --surface: #182322; --sunk: #0A100F;
  --ink: #EEF3F1; --ink2: #BAC7C3; --line: #35443F; --ctl: #8C9C97;
  --accent: #7AD9CD; --on-accent: #06211E; --soft: #17393A;
  --warn: #F2B866; --warn-soft: #3A2A10; --brand2: #F2B441;
  --side: #0B2B29; --side-ink: #EEF3F1; --side-ink2: #BAC7C3;
  --side-on: #7AD9CD; --side-on-ink: #06211E;
}
.t-hcl {
  --bg: #FFFFFF; --surface: #FFFFFF; --sunk: #FFFFFF;
  --ink: #000000; --ink2: #000000; --line: #000000; --ctl: #000000;
  --accent: #003B37; --on-accent: #FFFFFF; --soft: #D5F0EC;
  --warn: #4A2800; --warn-soft: #FFE9C2; --brand2: #000000;
  --side: #000000; --side-ink: #FFFFFF; --side-ink2: #FFFFFF;
  --side-on: #FFFFFF; --side-on-ink: #000000;
}
.t-hcd {
  --bg: #000000; --surface: #000000; --sunk: #000000;
  --ink: #FFFFFF; --ink2: #FFFFFF; --line: #FFFFFF; --ctl: #FFFFFF;
  --accent: #8FF5E8; --on-accent: #000000; --soft: #00302C;
  --warn: #FFD27A; --warn-soft: #3A2A00; --brand2: #FFFFFF;
  --side: #FFFFFF; --side-ink: #000000; --side-ink2: #000000;
  --side-on: #000000; --side-on-ink: #FFFFFF;
}

/* Accents: light theme */
.a-indigo   { --accent: #2A3990; --soft: #E2E6F8; --side: #2A3990; --side-ink2: #D9DEF7; --side-on-ink: #2A3990; }
.a-plum     { --accent: #6B1F5C; --soft: #F4E1EF; --side: #6B1F5C; --side-ink2: #F0D9EA; --side-on-ink: #6B1F5C; }
.a-graphite { --accent: #22302D; --soft: #E1E7E5; --side: #22302D; --side-ink2: #D5DDDA; --side-on-ink: #22302D; }

/* Accents: dark theme */
.t-dark.a-indigo   { --accent: #AEB9FF; --soft: #252C57; --side: #1A2045; --side-ink2: #BAC7C3; --side-on: #AEB9FF; --side-on-ink: #0E1330; }
.t-dark.a-plum     { --accent: #F0A8DD; --soft: #4A1F41; --side: #33152D; --side-ink2: #BAC7C3; --side-on: #F0A8DD; --side-on-ink: #2A0C24; }
.t-dark.a-graphite { --accent: #D5DDDA; --soft: #2A3532; --side: #1B2523; --side-ink2: #BAC7C3; --side-on: #D5DDDA; --side-on-ink: #111A18; }

/* The high-contrast themes must not take an accent class; the server omits it. */

/* Typefaces */
.f-atk    { --font-ui: 'Atkinson Hyperlegible', system-ui, sans-serif; }
.f-lexend { --font-ui: 'Lexend', system-ui, sans-serif; }
.f-serif4 { --font-ui: 'Source Serif 4', Georgia, serif; }
.f-sans   { --font-ui: system-ui, -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; }
.f-serif  { --font-ui: Georgia, 'Times New Roman', serif; }

/* Native controls and scrollbars follow the theme. */
:root { color-scheme: light; }
.t-dark, .t-hcd { color-scheme: dark; }

/*
 * Text size steps. The root size is a percentage of the browser default
 * (normally 16px), so 1rem is the body text size at every step:
 *   Small 16px | Standard 18px | Large 21px | Larger 24px | Largest 28px
 */
:root { font-size: 112.5%; }
:root.s-small { font-size: 100%; }
:root.s-large { font-size: 131.25%; }
:root.s-larger { font-size: 150%; }
:root.s-largest { font-size: 175%; }

/* Line spacing steps: Standard 1.5 | Roomy 1.75 | Very roomy 2 */
.l-roomy { --leading: 1.75; }
.l-very-roomy { --leading: 2; }
```

## Appendix B: `public/assets/css/fonts.css`

Exact content.

```css
/* SPDX-License-Identifier: AGPL-3.0-or-later */
/* Copyright (C) TiffinDB contributors */

/*
 * Bundled typefaces: self-hosted WOFF2, Latin subset, weights 400 and 700.
 * The font files are third-party assets under OFL-1.1; each family's licence
 * is the LICENSE.txt beside its files. See docs/dependencies.md.
 */

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../fonts/inter/inter-latin-400-normal.woff2') format('woff2');
}
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../fonts/inter/inter-latin-700-normal.woff2') format('woff2');
}

@font-face {
  font-family: 'Atkinson Hyperlegible';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../fonts/atkinson-hyperlegible/atkinson-hyperlegible-latin-400-normal.woff2') format('woff2');
}
@font-face {
  font-family: 'Atkinson Hyperlegible';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../fonts/atkinson-hyperlegible/atkinson-hyperlegible-latin-700-normal.woff2') format('woff2');
}

@font-face {
  font-family: 'Lexend';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../fonts/lexend/lexend-latin-400-normal.woff2') format('woff2');
}
@font-face {
  font-family: 'Lexend';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../fonts/lexend/lexend-latin-700-normal.woff2') format('woff2');
}

@font-face {
  font-family: 'Source Serif 4';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../fonts/source-serif-4/source-serif-4-latin-400-normal.woff2') format('woff2');
}
@font-face {
  font-family: 'Source Serif 4';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../fonts/source-serif-4/source-serif-4-latin-700-normal.woff2') format('woff2');
}
```
