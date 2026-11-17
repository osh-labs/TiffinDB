<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use DateTimeZone;
use ErrorException;
use Exception;
use LogicException;
use Throwable;
use TiffinDB\Core\Auth\Csrf;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Web\Controller\AccountController;
use TiffinDB\Web\Controller\SignInController;
use UnexpectedValueException;

/**
 * Turns a Request into a Response (M0 spec section 6.5; M1 spec part 2
 * section 2.4). handle() runs, in order:
 *
 *  1. malformed request: 400;
 *  2. method not GET, HEAD, POST: 405 with `Allow: GET, HEAD, POST`;
 *  3. route match: 404, or 405 with the route's Allow;
 *  4. POST with `Sec-Fetch-Site` `cross-site` or `same-site`: 403 (P79;
 *     TiffinDB forms always post same-origin, so a sibling subdomain is
 *     refused too where the browser sends the header);
 *  5. installation state: not installed and access not setup or any, 303
 *     to /setup; installed and access setup, 404;
 *  6. when installed, the session from cookie `tdb_session`; a cookie that
 *     does not resolve is deleted on the response;
 *  7. access user without a session, 303 to /sign-in; access guest with a
 *     session, 303 to /projects;
 *  8. a person who must change their password, on any user route except
 *     the password form, its POST, and sign-out: 303 to /account/password;
 *  9. CSRF for every POST (section 2.5): 403 `error.csrf.*` on failure;
 * 10. the controller, as `new Controller(Context $context)` and
 *     `$controller->method(Request $request, array $params)` (the M0 call
 *     shape, kept);
 * 11. core exceptions mapped to pages (NotFound 404, AccessDenied 403,
 *     Busy 503 with Retry-After, Unavailable 503); anything else, the 500
 *     page;
 * 12. on setup and guest routes without a session, the anonymous CSRF
 *     cookie `tdb_csrf` is created when missing or malformed, and added to
 *     whatever response the request produces;
 * 13. Services::close(), then the security headers, exactly once.
 *
 * The kernel reads templates from `<appRoot>/templates` and strings from
 * `<appRoot>/strings/en.php`, and calls the core only through Services
 * (never Core\App). Error pages use the signed-in shell once a session is
 * known (from step 6 on) and the plain shell before that.
 *
 * Configuration keys read: `version`, `source_url`, `appearance_preview`
 * (config/app.php), and the optional `timezone`, `secure_cookies`, and
 * `data_dir` (config/local.php, merged by public/index.php through
 * withLocalConfig()).
 */
final class Kernel
{
    /** Methods the application accepts. */
    public const ACCEPTED_METHODS = ['GET', 'HEAD', 'POST'];

    /** Sec-Fetch-Site values refused on POST (P79, amending M1-screens section 2.5). */
    public const REFUSED_FETCH_SITES = ['cross-site', 'same-site'];

    /** Seconds a client is asked to wait after a Busy answer. */
    public const RETRY_AFTER = 5;

    /** Keys config/local.php may set, with their checks in withLocalConfig(). */
    public const LOCAL_KEYS = ['data_dir', 'timezone', 'secure_cookies'];

    /** Routes a person who must change their password may still use. */
    private const MUST_CHANGE_ALLOWED = [
        [AccountController::class, 'passwordForm'],
        [AccountController::class, 'password'],
        [SignInController::class, 'signOut'],
    ];

    private readonly SecurityHeaders $securityHeaders;
    private readonly Services $services;

    /** Per-request state, reset at the start of handle(): the view and context error pages use. */
    private ?View $view = null;
    private ?Context $context = null;

    /** @var list<Cookie> Cookies the kernel adds to whatever response the request produces. */
    private array $pendingCookies = [];

    /**
     * @param array<string, mixed> $config   Configuration (config/app.php merged with config/local.php).
     * @param list<mixed>|null     $routes   Route table in the routes.php format; null for the real one.
     * @param Services|null        $services Null for CoreServices over $config and $appRoot.
     */
    public function __construct(
        private readonly array $config,
        private readonly string $appRoot,
        private readonly ?array $routes = null,
        ?Services $services = null,
    ) {
        $this->securityHeaders = new SecurityHeaders();
        $this->services = $services ?? new CoreServices($config, $appRoot);
    }

    /**
     * $config with the values of config/local.php merged in, after checking
     * them: an array holding only `data_dir` (absolute path), `timezone`
     * (an identifier \DateTimeZone accepts), and `secure_cookies` (bool).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     * @throws UnexpectedValueException for unknown keys or wrong types
     */
    public static function withLocalConfig(array $config, mixed $local): array
    {
        if (!is_array($local)) {
            throw new UnexpectedValueException('config/local.php must return an array.');
        }
        foreach ($local as $key => $value) {
            if (!in_array($key, self::LOCAL_KEYS, true)) {
                throw new UnexpectedValueException('config/local.php: unknown key.');
            }
            $config[$key] = $value;
        }
        self::checkLocalConfig($config);

        return $config;
    }

    public function handle(Request $request): Response
    {
        // Any PHP warning or notice during handling becomes an exception, so
        // that it ends in the generic 500 page rather than a broken page.
        // This deliberately ignores error_reporting(): the suppression
        // operator is not used in this code base (ADR 0008 decision 6).
        // Deprecations go on to the previous handler.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_ALL & ~(E_DEPRECATED | E_USER_DEPRECATED));

        $this->view = null;
        $this->context = null;
        $this->pendingCookies = [];
        try {
            try {
                $response = $this->dispatch($request);
            } catch (Throwable $e) {
                Fallback::log($e);
                $response = $this->serverError();
            }
            $response = self::addPendingCookies($response, $this->pendingCookies);
        } finally {
            try {
                $this->services->close();
            } catch (Throwable $e) {
                Fallback::log($e);
            } finally {
                restore_error_handler();
            }
        }

        return $this->securityHeaders->apply($response);
    }

    private function dispatch(Request $request): Response
    {
        $this->checkConfig();
        // An installation missing its templates or strings is broken; fail
        // closed with the plain 500 even for requests that would only redirect.
        $strings = new Strings($this->appRoot . '/strings/en.php');
        if (!is_dir($this->appRoot . '/templates') || !$strings->has('app.name')) {
            throw new LogicException('The application templates or strings are missing.');
        }
        $urls = new UrlGenerator($request->basePath, $this->version());
        $this->view = $this->buildView($request, $strings, $urls, Appearance::default());

        // Steps 1 to 3.
        if ($request->malformed) {
            return $this->errorPage(400);
        }
        if (!in_array($request->method, self::ACCEPTED_METHODS, true)) {
            return $this->errorPage(405)->withHeader('Allow', implode(', ', self::ACCEPTED_METHODS));
        }
        $match = (new Router($this->routes ?? Router::applicationRoutes()))->match($request->method, $request->path);
        if ($match->isNotFound()) {
            return $this->errorPage(404);
        }
        if ($match->isMethodNotAllowed()) {
            return $this->errorPage(405)->withHeader('Allow', implode(', ', $match->allowedMethods));
        }
        $access = $match->access;
        /** @var array{0: class-string, 1: string} $handler */
        $handler = $match->handler;
        if ($access === null) {
            throw new LogicException('A found route carries its access level.');
        }

        // Step 4.
        if ($request->isPost() && in_array($request->secFetchSite, self::REFUSED_FETCH_SITES, true)) {
            return $this->errorPage(403);
        }

        try {
            return $this->gate($request, $strings, $urls, $access, $handler, $match->params);
        } catch (NotFound) {
            return $this->errorPage(404);
        } catch (AccessDenied) {
            return $this->errorPage(403);
        } catch (Busy) {
            return $this->errorPage(503, 'error.busy.title', 'error.busy.body')
                ->withHeader('Retry-After', (string) self::RETRY_AFTER);
        } catch (Unavailable $e) {
            $reason = $e->reason;
            if (!in_array($reason, [Unavailable::MISSING, Unavailable::NOT_PROJECT, Unavailable::NEWER, Unavailable::MISMATCH], true)) {
                throw new LogicException('Unknown unavailable reason.', 0, $e);
            }

            return $this->errorPage(503, "error.unavailable.{$reason}.title", "error.unavailable.{$reason}.body");
        }
    }

    /**
     * Steps 5 to 10 and 12.
     *
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, string>             $params
     */
    private function gate(
        Request $request,
        Strings $strings,
        UrlGenerator $urls,
        AccessLevel $access,
        array $handler,
        array $params,
    ): Response {
        $secure = $this->secureCookies($request);
        $cookiePath = $urls->to('/');

        // Step 5.
        $installed = $this->services->installState() === InstallState::Installed;
        if (!$installed && $access !== AccessLevel::Setup && $access !== AccessLevel::Any) {
            return Response::redirect($urls->to('/setup'));
        }
        if ($installed && $access === AccessLevel::Setup) {
            return $this->errorPage(404);
        }

        // Step 6.
        $signedIn = null;
        $sessionToken = null;
        if ($installed) {
            $cookie = $request->cookie(Context::SESSION_COOKIE);
            if ($cookie !== null) {
                $signedIn = $this->services->resume($cookie);
                if ($signedIn === null) {
                    $this->pendingCookies[] = Cookie::deleting(Context::SESSION_COOKIE, $cookiePath, $secure, 'Lax');
                } else {
                    $sessionToken = $cookie;
                }
            }
        }

        // Step 12 (prepared here so that every later response carries it).
        $csrfToken = null;
        $anonymousExpected = null;
        if ($signedIn !== null) {
            $csrfToken = $signedIn->session->csrfToken;
        } elseif ($access === AccessLevel::Setup || $access === AccessLevel::Guest) {
            $anonymousExpected = self::wellFormedCsrf($request->cookie(Context::CSRF_COOKIE));
            $csrfToken = $anonymousExpected;
            if ($csrfToken === null) {
                $csrfToken = $this->services->newCsrfToken();
                $this->pendingCookies[] = new Cookie(Context::CSRF_COOKIE, $csrfToken, $cookiePath, $secure, 'Strict', null);
            }
        }

        $appearance = $this->appearance($request, $signedIn);
        $view = $this->buildView($request, $strings, $urls, $appearance);
        if ($csrfToken !== null) {
            $view = $view->withCsrfToken($csrfToken);
        }
        $context = new Context($request, $view, $this->services, $signedIn, $urls, $csrfToken, $secure, $sessionToken);
        $this->view = $view;
        $this->context = $context;

        // Step 7.
        if ($access === AccessLevel::User && $signedIn === null) {
            return Response::redirect($urls->to('/sign-in'));
        }
        if ($access === AccessLevel::Guest && $signedIn !== null) {
            return Response::redirect($urls->to('/projects'));
        }

        // Step 8.
        if (
            $access === AccessLevel::User
            && $signedIn !== null
            && $signedIn->mustChangePassword
            && !in_array($handler, self::MUST_CHANGE_ALLOWED, true)
        ) {
            return Response::redirect($urls->to('/account/password'));
        }

        // Step 9.
        if ($request->isPost()) {
            $expected = $signedIn !== null ? $signedIn->session->csrfToken : $anonymousExpected;
            if ($expected === null || !Csrf::matches($expected, $request->post['_csrf'] ?? null)) {
                return $this->errorPage(403, 'error.csrf.title', 'error.csrf.body');
            }
        }

        // Step 10.
        return $this->callController($handler, $context, $request, $params);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, string>             $params
     */
    private function callController(array $handler, Context $context, Request $request, array $params): Response
    {
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            throw new LogicException("Route handler class not found: {$class}");
        }
        $controller = new $class($context);
        if (!is_callable([$controller, $method])) {
            throw new LogicException("Route handler method not callable: {$class}::{$method}");
        }

        $response = $controller->$method($request, $params);
        if (!$response instanceof Response) {
            throw new LogicException("Route handler did not return a Response: {$class}::{$method}");
        }

        return $response;
    }

    private function appearance(Request $request, ?SignedIn $signedIn): Appearance
    {
        if ($this->config['appearance_preview'] === true) {
            return Appearance::fromQuery($request->query);
        }
        if ($signedIn !== null) {
            return Appearance::fromSettings($this->services->userSettings($signedIn->actor->userId));
        }

        return Appearance::default();
    }

    private function buildView(Request $request, Strings $strings, UrlGenerator $urls, Appearance $appearance): View
    {
        $timezone = $this->config['timezone'] ?? null;
        $format = new Format(
            $strings,
            new DateTimeZone(is_string($timezone) ? $timezone : date_default_timezone_get()),
            $this->services->now(...),
        );

        return (new View($this->appRoot . '/templates', $strings, $urls, $appearance, $this->config))->withFormat($format);
    }

    private function secureCookies(Request $request): bool
    {
        $configured = $this->config['secure_cookies'] ?? null;

        return is_bool($configured) ? $configured : $request->secure;
    }

    private function errorPage(int $status, ?string $titleKey = null, ?string $bodyKey = null): Response
    {
        $titleKey ??= "error.{$status}.title";
        $bodyKey ??= "error.{$status}.body";
        if ($this->context !== null) {
            return $this->context->errorPage($status, $titleKey, $bodyKey);
        }
        $view = $this->view;
        if ($view === null) {
            throw new LogicException('No view to render an error page.');
        }

        return $view->page('error', [
            'status' => $status,
            'title' => $view->text($titleKey),
            'titleKey' => $titleKey,
            'bodyKey' => $bodyKey,
            'shell' => null,
        ], $status);
    }

    /** The 500 page, or a fixed plain-text 500 if that cannot be rendered either. */
    private function serverError(): Response
    {
        if ($this->view !== null) {
            try {
                return $this->errorPage(500);
            } catch (Throwable $e) {
                Fallback::log($e);
            }
            if ($this->context !== null) {
                // The signed-in shell failed; try the plain one.
                $this->context = null;
                try {
                    return $this->errorPage(500);
                } catch (Throwable $e) {
                    Fallback::log($e);
                }
            }
        }

        // Fallback::response() already carries the security headers; apply()
        // in handle() sets the same values again, which changes nothing.
        return Fallback::response();
    }

    /**
     * Adds the kernel's cookies (stale session deletion, anonymous CSRF) to
     * the response, except where the controller already set a cookie of
     * the same name: the controller's decision wins.
     *
     * @param list<Cookie> $cookies
     */
    private static function addPendingCookies(Response $response, array $cookies): Response
    {
        foreach ($cookies as $cookie) {
            if ($response->cookie($cookie->name) === null) {
                $response = $response->withCookie($cookie);
            }
        }

        return $response;
    }

    private static function wellFormedCsrf(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9a-f]{64}\z/', $value) === 1 ? $value : null;
    }

    private function version(): string
    {
        $version = $this->config['version'];
        if (!is_string($version)) {
            throw new LogicException('Configuration not checked.');
        }

        return $version;
    }

    private function checkConfig(): void
    {
        $version = $this->config['version'] ?? null;
        $sourceUrl = $this->config['source_url'] ?? null;
        $preview = $this->config['appearance_preview'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new UnexpectedValueException('Configuration: version must be a non-empty string.');
        }
        if (!is_string($sourceUrl) || preg_match('#^https://[^\s"<>\\\\]+\z#', $sourceUrl) !== 1) {
            throw new UnexpectedValueException('Configuration: source_url must be an https URL.');
        }
        if (!is_bool($preview)) {
            throw new UnexpectedValueException('Configuration: appearance_preview must be a boolean.');
        }
        self::checkLocalConfig($this->config);
    }

    /** @param array<string, mixed> $config */
    private static function checkLocalConfig(array $config): void
    {
        $dataDir = $config['data_dir'] ?? null;
        if (
            $dataDir !== null
            && (!is_string($dataDir) || $dataDir === '' || str_contains($dataDir, "\0")
                || preg_match('#^(/|[A-Za-z]:[\\\\/])#', $dataDir) !== 1)
        ) {
            throw new UnexpectedValueException('Configuration: data_dir must be an absolute path.');
        }
        $timezone = $config['timezone'] ?? null;
        if ($timezone !== null) {
            if (!is_string($timezone) || $timezone === '') {
                throw new UnexpectedValueException('Configuration: timezone must be a time zone identifier.');
            }
            try {
                new DateTimeZone($timezone);
            } catch (Exception $e) {
                throw new UnexpectedValueException('Configuration: timezone must be a time zone identifier.', 0, $e);
            }
        }
        $secure = $config['secure_cookies'] ?? null;
        if ($secure !== null && !is_bool($secure)) {
            throw new UnexpectedValueException('Configuration: secure_cookies must be true or false.');
        }
    }
}
