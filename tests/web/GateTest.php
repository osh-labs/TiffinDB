<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\FixtureController;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\Controller\AccountController;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\Controller\SignInController;
use TiffinDB\Web\Cookie;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-16: every step of the kernel's gate (M1 spec part 2 section 2.4)
 * with FakeServices, the real templates and strings, and fixture
 * controllers. Synthetic data only.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(Context::class)]
#[CoversClass(HomeController::class)]
final class GateTest extends TestCase
{
    private const ANON = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const UNKNOWN_TOKEN = 'unknownXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';

    /** @return list<mixed> */
    private static function routes(): array
    {
        $f = FixtureController::class;

        return [
            ['GET', '/', [HomeController::class, 'show'], 'any'],
            ['GET', '/setup', [$f, 'show'], 'setup'],
            ['POST', '/setup', [$f, 'show'], 'setup'],
            ['GET', '/sign-in', [$f, 'show'], 'guest'],
            ['POST', '/sign-in', [$f, 'show'], 'guest'],
            ['POST', '/new-session', [$f, 'newSession'], 'guest'],
            ['POST', '/sign-out', [SignInController::class, 'signOut'], 'user'],
            ['GET', '/account/password', [AccountController::class, 'passwordForm'], 'user'],
            ['POST', '/account/password', [AccountController::class, 'password'], 'user'],
            ['GET', '/projects', [$f, 'show'], 'user'],
            ['POST', '/projects', [$f, 'show'], 'user'],
            ['GET', '/page', [$f, 'page'], 'user'],
            ['POST', '/flash', [$f, 'flashAndRedirect'], 'user'],
            ['POST', '/fixture-sign-out', [$f, 'signOut'], 'user'],
            ['GET', '/not-found', [$f, 'notFound'], 'user'],
            ['GET', '/access-denied', [$f, 'accessDenied'], 'user'],
            ['GET', '/busy', [$f, 'busy'], 'user'],
            ['GET', '/unavailable/{reason}', [$f, 'unavailable'], 'user'],
            ['GET', '/validation', [$f, 'validationFailed'], 'user'],
            ['GET', '/throws', [$f, 'throws'], 'user'],
            ['GET', '/anon-throws', [$f, 'throws'], 'guest'],
        ];
    }

    /** @param array<string, mixed> $config */
    private static function kernel(FakeServices $services, array $config = []): Kernel
    {
        return new Kernel($config + Fixtures::config(false), Fixtures::appRoot(), self::routes(), $services);
    }

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $cookies
     * @param array<string, mixed>    $server
     * @param array<string, mixed>    $config
     * @return array{0: Response, 1: string}
     */
    private static function send(
        FakeServices $services,
        string $method,
        string $uri,
        array $post = [],
        array $cookies = [],
        array $server = [],
        array $config = [],
    ): array {
        return Fixtures::handle(self::kernel($services, $config), Fixtures::fullRequest($method, $uri, $post, $cookies, $server));
    }

    private static function member(bool $mustChange = false): FakeServices
    {
        return (new FakeServices())->withSession(FakeServices::signedIn(false, $mustChange));
    }

    /** @return array<string, string> */
    private static function sessionCookie(): array
    {
        return ['tdb_session' => FakeServices::TOKEN];
    }

    private static function assertRedirect(Response $response, string $location): void
    {
        self::assertSame(303, $response->status, 'status');
        self::assertSame($location, $response->header('Location'));
        self::assertSame('', $response->body);
    }

    private static function assertHandled(Response $response, string $method = 'GET'): void
    {
        self::assertSame(200, $response->status, $response->body);
        self::assertStringStartsWith("handled {$method}\n", $response->body);
    }

    private static function assertErrorPage(Response $response, int $status, string $title): void
    {
        self::assertSame($status, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString("<title>{$title} - TiffinDB</title>", $response->body);
        self::assertStringContainsString("<h1>{$title}</h1>", $response->body);
    }

    private static function assertCsrfRefused(Response $response): void
    {
        self::assertErrorPage($response, 403, 'This form has expired');
        self::assertStringContainsString('Nothing was saved.', $response->body);
    }

    // ------------------------------------------------ steps 1 to 4

    public function testMethodOutsideGetHeadPostIsRefused(): void
    {
        [$response] = self::send(new FakeServices(), 'DELETE', '/projects');

        self::assertErrorPage($response, 405, 'That action is not available here');
        self::assertSame('GET, HEAD, POST', $response->header('Allow'));
    }

    public function testRouteMethodMismatchGivesTheRoutesAllow(): void
    {
        [$response] = self::send(new FakeServices(), 'POST', '/page');

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function testUnroutedRequestsTouchNoCoreService(): void
    {
        $services = self::member();
        foreach ([['GET', '/nope', 404], ['GET', '/a%00', 400], ['PUT', '/', 405], ['POST', '/', 405]] as [$method, $uri, $status]) {
            [$response] = self::send($services, $method, $uri, [], self::sessionCookie());
            self::assertSame($status, $response->status, "{$method} {$uri}");
            self::assertSame([], $response->cookies);
        }
        self::assertNotContains('installState', $services->calls);
        self::assertNotContains('resume', $services->calls);
    }

    /** @return array<string, array{string|null, bool}> */
    public static function fetchSites(): array
    {
        return [
            'cross-site' => ['cross-site', false],
            'same-site' => ['same-site', true],
            'same-origin' => ['same-origin', true],
            'none' => ['none', true],
            'absent' => [null, true],
        ];
    }

    #[DataProvider('fetchSites')]
    public function testCrossSitePostIsRefused(?string $site, bool $allowed): void
    {
        $server = $site === null ? [] : ['HTTP_SEC_FETCH_SITE' => $site];
        $services = self::member();
        [$response] = self::send($services, 'POST', '/projects', ['_csrf' => FakeServices::SESSION_CSRF], self::sessionCookie(), $server);

        if ($allowed) {
            self::assertHandled($response, 'POST');
        } else {
            self::assertErrorPage($response, 403, 'You cannot do that here');
            self::assertNotContains('resume', $services->calls);
        }
    }

    public function testCrossSiteGetIsAllowed(): void
    {
        [$response] = self::send(self::member(), 'GET', '/projects', [], self::sessionCookie(), ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertHandled($response);
    }

    // ------------------------------------------------ step 5

    /** @return array<string, array{InstallState}> */
    public static function notInstalled(): array
    {
        return ['needs data folder' => [InstallState::NeedsDataFolder], 'needs admin' => [InstallState::NeedsAdmin]];
    }

    #[DataProvider('notInstalled')]
    public function testEverythingButSetupRedirectsToSetupBeforeInstallation(InstallState $state): void
    {
        foreach ([['GET', '/'], ['GET', '/sign-in'], ['POST', '/sign-in'], ['GET', '/projects'], ['POST', '/sign-out'], ['GET', '/not-found']] as [$method, $uri]) {
            $services = new FakeServices($state);
            $services->withSession(FakeServices::signedIn());
            [$response] = self::send($services, $method, $uri, [], self::sessionCookie());

            self::assertRedirect($response, '/setup');
            self::assertNotContains('resume', $services->calls, 'No session before installation.');
            self::assertSame([], $response->cookies, 'The session cookie is left alone.');
        }
    }

    #[DataProvider('notInstalled')]
    public function testSetupRoutesRunBeforeInstallation(InstallState $state): void
    {
        [$response] = self::send(new FakeServices($state), 'GET', '/setup');

        self::assertHandled($response);
        self::assertStringContainsString("user=none\ncsrf=" . FakeServices::NEW_CSRF, $response->body);
    }

    public function testSetupRoutesAreNotFoundOnceInstalled(): void
    {
        foreach (['GET', 'POST', 'HEAD'] as $method) {
            $services = self::member();
            [$response] = self::send($services, $method, '/setup', ['_csrf' => self::ANON], ['tdb_csrf' => self::ANON] + self::sessionCookie());

            self::assertSame(404, $response->status, $method);
            self::assertNotContains('resume', $services->calls);
        }
    }

    public function testRedirectsCarryTheBasePath(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/my%20dir/projects', 'SCRIPT_NAME' => '/my dir/index.php'],
            [],
            [],
            [],
        );
        [$response] = Fixtures::handle(self::kernel(new FakeServices(InstallState::NeedsAdmin)), $request);

        self::assertRedirect($response, '/my%20dir/setup');
    }

    // ------------------------------------------------ step 6

    public function testStaleSessionCookieIsDeleted(): void
    {
        foreach (['/sign-in', '/projects', '/'] as $uri) {
            $services = self::member();
            [$response] = self::send($services, 'GET', $uri, [], ['tdb_session' => self::UNKNOWN_TOKEN]);

            $cookie = $response->cookie('tdb_session');
            self::assertNotNull($cookie, $uri);
            self::assertSame('tdb_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $cookie->header(), $uri);
        }
    }

    public function testMalformedSessionCookieIsDeleted(): void
    {
        [$response] = self::send(self::member(), 'GET', '/sign-in', [], ['tdb_session' => 'x;y']);

        self::assertSame(0, $response->cookie('tdb_session')?->maxAge);
    }

    public function testLiveSessionCookieIsKept(): void
    {
        [$response] = self::send(self::member(), 'GET', '/projects', [], self::sessionCookie());

        self::assertHandled($response);
        self::assertStringContainsString("user=MEMBERKEY001\ncsrf=" . FakeServices::SESSION_CSRF, $response->body);
        self::assertSame([], $response->cookies);
    }

    public function testNoSessionCookieMeansNoResume(): void
    {
        $services = self::member();
        self::send($services, 'GET', '/sign-in');

        self::assertNotContains('resume', $services->calls);
    }

    // ------------------------------------------------ step 7

    public function testUserRouteWithoutSessionRedirectsToSignIn(): void
    {
        foreach ([['GET', '/projects'], ['POST', '/projects'], ['POST', '/sign-out'], ['GET', '/throws']] as [$method, $uri]) {
            [$response] = self::send(new FakeServices(), $method, $uri, ['_csrf' => 'x']);
            self::assertRedirect($response, '/sign-in');
        }
    }

    public function testGuestRouteWithSessionRedirectsToProjects(): void
    {
        foreach ([['GET', '/sign-in'], ['POST', '/sign-in'], ['POST', '/new-session']] as [$method, $uri]) {
            $services = self::member();
            [$response] = self::send($services, $method, $uri, ['_csrf' => self::ANON], ['tdb_csrf' => self::ANON] + self::sessionCookie());

            self::assertRedirect($response, '/projects');
            self::assertNull($response->cookie('tdb_csrf'), 'No anonymous token for a signed-in person.');
            self::assertNotContains('newCsrfToken', $services->calls);
        }
    }

    public function testHomeRedirects(): void
    {
        [$response] = self::send(new FakeServices(InstallState::NeedsDataFolder), 'GET', '/');
        self::assertRedirect($response, '/setup');

        [$response] = self::send(new FakeServices(), 'GET', '/');
        self::assertRedirect($response, '/sign-in');

        [$response] = self::send(self::member(), 'GET', '/', [], self::sessionCookie());
        self::assertRedirect($response, '/projects');

        [$response] = self::send(self::member(true), 'GET', '/', [], self::sessionCookie());
        self::assertRedirect($response, '/projects');
    }

    public function testHomeSetsNoCsrfCookie(): void
    {
        [$response] = self::send(new FakeServices(), 'GET', '/');

        self::assertNull($response->cookie('tdb_csrf'));
    }

    // ------------------------------------------------ step 8

    public function testMustChangeRedirectsEveryOtherUserRoute(): void
    {
        foreach (
            [
                ['GET', '/projects', []],
                ['POST', '/projects', ['_csrf' => FakeServices::SESSION_CSRF]],
                ['POST', '/projects', []],
                ['GET', '/page', []],
                ['GET', '/not-found', []],
                ['POST', '/fixture-sign-out', ['_csrf' => FakeServices::SESSION_CSRF]],
            ] as [$method, $uri, $post]
        ) {
            [$response] = self::send(self::member(true), $method, $uri, $post, self::sessionCookie());
            self::assertRedirect($response, '/account/password');
        }
    }

    /**
     * The three exceptions pass the gate. Their controllers belong to WP10;
     * until they exist the request ends in the 500 page, which still shows
     * that no redirect happened.
     */
    public function testMustChangeExceptionsPassTheGate(): void
    {
        foreach (
            [
                ['GET', '/account/password', [], AccountController::class],
                ['POST', '/account/password', ['_csrf' => FakeServices::SESSION_CSRF], AccountController::class],
                ['POST', '/sign-out', ['_csrf' => FakeServices::SESSION_CSRF], SignInController::class],
            ] as [$method, $uri, $post, $class]
        ) {
            [$response, $log] = self::send(self::member(true), $method, $uri, $post, self::sessionCookie());

            self::assertNotSame(303, $response->status, "{$method} {$uri}");
            if (!class_exists($class)) {
                self::assertSame(500, $response->status);
                self::assertStringContainsString("Route handler class not found: {$class}", $log);
            }
        }
    }

    public function testMustChangeExceptionsStillNeedCsrf(): void
    {
        foreach (['/account/password', '/sign-out'] as $uri) {
            [$response] = self::send(self::member(true), 'POST', $uri, [], self::sessionCookie());
            self::assertCsrfRefused($response);
        }
    }

    // ------------------------------------------------ step 9: signed in

    /** @return array<string, array{array<string, mixed>}> */
    public static function badSessionPosts(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['_csrf' => '']],
            'wrong' => [['_csrf' => str_repeat('b', 64)]],
            'upper case' => [['_csrf' => strtoupper(FakeServices::SESSION_CSRF)]],
            'prefix' => [['_csrf' => substr(FakeServices::SESSION_CSRF, 0, 63)]],
            'with newline' => [['_csrf' => FakeServices::SESSION_CSRF . "\n"]],
            'array' => [['_csrf' => [FakeServices::SESSION_CSRF]]],
            'the session token' => [['_csrf' => FakeServices::TOKEN]],
            'the anonymous token' => [['_csrf' => self::ANON]],
        ];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('badSessionPosts')]
    public function testSignedInPostNeedsTheSessionToken(array $post): void
    {
        // The anonymous cookie is present and equals ANON, but a signed-in
        // POST is checked against the session's token only.
        [$response] = self::send(self::member(), 'POST', '/projects', $post, ['tdb_csrf' => self::ANON] + self::sessionCookie());

        self::assertCsrfRefused($response);
        self::assertStringContainsString('class="sign-out"', $response->body, 'Signed-in shell on the error page.');
    }

    public function testSignedInPostWithTheSessionTokenIsHandled(): void
    {
        [$response] = self::send(self::member(), 'POST', '/projects', ['_csrf' => FakeServices::SESSION_CSRF, 'name' => 'Example'], self::sessionCookie());

        self::assertHandled($response, 'POST');
        self::assertStringEndsWith("input=Example", $response->body);
    }

    // ------------------------------------------------ step 9 and 12: anonymous

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function badAnonymousPosts(): array
    {
        $other = str_repeat('e', 64);

        return [
            'no cookie, no field' => [[], []],
            'no cookie, a field' => [['_csrf' => self::ANON], []],
            'cookie, no field' => [[], ['tdb_csrf' => self::ANON]],
            'mismatch' => [['_csrf' => $other], ['tdb_csrf' => self::ANON]],
            'both empty' => [['_csrf' => ''], ['tdb_csrf' => '']],
            'malformed cookie echoed' => [['_csrf' => 'abc'], ['tdb_csrf' => 'abc']],
            'upper-case cookie echoed' => [['_csrf' => strtoupper(self::ANON)], ['tdb_csrf' => strtoupper(self::ANON)]],
            'array field' => [['_csrf' => [self::ANON]], ['tdb_csrf' => self::ANON]],
            'array cookie' => [['_csrf' => self::ANON], ['tdb_csrf' => [self::ANON]]],
            'the newly issued token' => [['_csrf' => FakeServices::NEW_CSRF], []],
            'the session token' => [['_csrf' => FakeServices::SESSION_CSRF], ['tdb_csrf' => self::ANON]],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     */
    #[DataProvider('badAnonymousPosts')]
    public function testAnonymousPostNeedsTheCookieToken(array $post, array $cookies): void
    {
        foreach ([[new FakeServices(), '/sign-in'], [new FakeServices(InstallState::NeedsAdmin), '/setup']] as [$services, $uri]) {
            [$response] = self::send($services, 'POST', $uri, $post, $cookies);

            self::assertCsrfRefused($response);
            self::assertStringNotContainsString('class="sign-out"', $response->body, 'Plain shell.');
        }
    }

    public function testAnonymousPostWithTheCookieTokenIsHandled(): void
    {
        foreach ([[new FakeServices(), '/sign-in'], [new FakeServices(InstallState::NeedsDataFolder), '/setup']] as [$services, $uri]) {
            [$response] = self::send($services, 'POST', $uri, ['_csrf' => self::ANON], ['tdb_csrf' => self::ANON]);

            self::assertHandled($response, 'POST');
            self::assertStringContainsString('csrf=' . self::ANON, $response->body);
            self::assertNull($response->cookie('tdb_csrf'), 'A valid cookie is kept, not reissued.');
        }
    }

    public function testAnonymousCsrfCookieIsCreatedWhenMissing(): void
    {
        $services = new FakeServices();
        [$response] = self::send($services, 'GET', '/sign-in');

        self::assertHandled($response);
        self::assertStringContainsString('csrf=' . FakeServices::NEW_CSRF, $response->body, 'The page can print it.');
        self::assertSame(
            'tdb_csrf=' . FakeServices::NEW_CSRF . '; Path=/; HttpOnly; SameSite=Strict',
            $response->cookie('tdb_csrf')?->header(),
        );
    }

    public function testAnonymousCsrfCookieIsReplacedWhenMalformed(): void
    {
        foreach (['short', strtoupper(self::ANON), self::ANON . 'a', ''] as $bad) {
            [$response] = self::send(new FakeServices(), 'GET', '/sign-in', [], ['tdb_csrf' => $bad]);
            self::assertSame(FakeServices::NEW_CSRF, $response->cookie('tdb_csrf')?->value);
        }
    }

    public function testAnonymousCsrfCookieRidesOnEveryResponse(): void
    {
        // A refused POST, a controller failure, and a core exception page.
        [$response] = self::send(new FakeServices(), 'POST', '/sign-in');
        self::assertSame(403, $response->status);
        self::assertSame(FakeServices::NEW_CSRF, $response->cookie('tdb_csrf')?->value);

        [$response] = self::send(new FakeServices(), 'GET', '/anon-throws');
        self::assertSame(500, $response->status);
        self::assertSame(FakeServices::NEW_CSRF, $response->cookie('tdb_csrf')?->value);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function secureCases(): array
    {
        return [
            'http' => [[], [], false],
            'https' => [['HTTPS' => 'on'], [], true],
            'https, configured off' => [['HTTPS' => 'on'], ['secure_cookies' => false], false],
            'http, configured on' => [[], ['secure_cookies' => true], true],
        ];
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $config
     */
    #[DataProvider('secureCases')]
    public function testCookiesAreSecureOnHttpsOrWhenConfigured(array $server, array $config, bool $secure): void
    {
        [$response] = self::send(new FakeServices(), 'GET', '/sign-in', [], ['tdb_session' => self::UNKNOWN_TOKEN], $server, $config);

        foreach (['tdb_csrf', 'tdb_session'] as $name) {
            $cookie = $response->cookie($name);
            self::assertNotNull($cookie, $name);
            self::assertSame($secure, $cookie->secure, $name);
            self::assertSame($secure, str_contains($cookie->header(), '; Secure;'), $name);
        }
    }

    public function testCookiePathIsTheEncodedBasePath(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/my%20dir/sign-in', 'SCRIPT_NAME' => '/my dir/public/index.php'],
            [],
            [],
            [],
        );
        [$response] = Fixtures::handle(self::kernel(new FakeServices()), $request);

        self::assertSame('/my%20dir/', $response->cookie('tdb_csrf')?->path);
    }

    // ------------------------------------------------ step 10: context

    public function testSessionStartedByAControllerReplacesTheOldOne(): void
    {
        $services = new FakeServices();
        [$response] = self::send(
            $services,
            'POST',
            '/new-session',
            ['_csrf' => self::ANON],
            ['tdb_csrf' => self::ANON, 'tdb_session' => self::UNKNOWN_TOKEN],
        );

        self::assertRedirect($response, '/projects');
        self::assertSame(
            'tdb_session=' . FixtureController::STARTED_TOKEN . '; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax',
            $response->cookie('tdb_session')?->header(),
            "The controller's cookie wins over the kernel's deletion.",
        );
        self::assertSame(0, $response->cookie('tdb_csrf')?->maxAge, 'The anonymous token is deleted.');
        self::assertContains('endSession:' . self::UNKNOWN_TOKEN, $services->calls, 'The old cookie session is ended.');
        self::assertSame(['key' => 'notice.password_changed', 'params' => []], $services->flash[FixtureController::STARTED_TOKEN] ?? null);
        self::assertCount(2, $response->cookies);
    }

    public function testFlashGoesToTheCurrentSession(): void
    {
        $services = self::member();
        [$response] = self::send($services, 'POST', '/flash', ['_csrf' => FakeServices::SESSION_CSRF], self::sessionCookie());

        self::assertRedirect($response, '/done');
        self::assertSame('notice.password_changed', $services->flash[FakeServices::TOKEN]['key'] ?? null);
        self::assertNotContains('takeFlash', $services->calls, 'A redirect takes no notice.');
    }

    public function testSignedInPageShowsAndClearsTheFlashNotice(): void
    {
        $services = self::member();
        $services->flash[FakeServices::TOKEN] = ['key' => 'notice.password_changed', 'params' => []];

        [$head] = self::send($services, 'HEAD', '/page', [], self::sessionCookie());
        self::assertSame(200, $head->status);
        self::assertArrayHasKey(FakeServices::TOKEN, $services->flash, 'HEAD takes no notice.');

        [$response] = self::send($services, 'GET', '/page', [], self::sessionCookie());
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Your password has been changed.', $response->body);
        self::assertStringContainsString('class="sign-out"', $response->body);
        self::assertStringContainsString('value="' . FakeServices::SESSION_CSRF . '"', $response->body);
        self::assertStringContainsString('aria-current="page"', $response->body);
        self::assertArrayNotHasKey(FakeServices::TOKEN, $services->flash);

        [$again] = self::send($services, 'GET', '/page', [], self::sessionCookie());
        self::assertStringNotContainsString('Your password has been changed.', $again->body);
    }

    public function testSignOutCookieDeletesTheSession(): void
    {
        [$response] = self::send(self::member(), 'POST', '/fixture-sign-out', ['_csrf' => FakeServices::SESSION_CSRF], self::sessionCookie());

        self::assertRedirect($response, '/sign-in?signed-out=1');
        self::assertSame('tdb_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $response->cookie('tdb_session')?->header());
    }

    public function testStoredAppearanceAppliesWhenSignedIn(): void
    {
        $services = self::member();
        $services->settings[2] = ['theme' => 'dark', 'accent' => 'plum'];
        [$response] = self::send($services, 'GET', '/page', [], self::sessionCookie());
        self::assertStringContainsString('<html lang="en" class="t-dark a-plum">', $response->body);

        [$response] = self::send(new FakeServices(), 'POST', '/sign-in', [], [], [], []);
        self::assertStringContainsString('<html lang="en">', $response->body, 'Defaults before sign-in.');
    }

    public function testAppearanceQueryIsIgnoredWithPreviewOff(): void
    {
        [$response] = self::send(self::member(), 'GET', '/page?theme=dark', [], self::sessionCookie());

        self::assertStringContainsString('<html lang="en">', $response->body);
    }

    // ------------------------------------------------ step 11

    public function testNotFoundFromTheCore(): void
    {
        [$response] = self::send(self::member(), 'GET', '/not-found', [], self::sessionCookie());

        self::assertErrorPage($response, 404, 'Page not found');
        self::assertStringContainsString('class="sign-out"', $response->body, 'Signed-in shell.');
    }

    public function testAccessDeniedFromTheCore(): void
    {
        [$response] = self::send(self::member(), 'GET', '/access-denied', [], self::sessionCookie());

        self::assertErrorPage($response, 403, 'You cannot do that here');
    }

    public function testBusyFromTheCore(): void
    {
        [$response] = self::send(self::member(), 'GET', '/busy', [], self::sessionCookie());

        self::assertErrorPage($response, 503, 'Please try again in a moment');
        self::assertSame('5', $response->header('Retry-After'));
    }

    /** @return array<string, array{string, string}> */
    public static function unavailableReasons(): array
    {
        return [
            'missing' => ['missing', 'This project&apos;s file cannot be found'],
            'not-project' => ['not-project', 'This project&apos;s file cannot be read'],
            'newer' => ['newer', 'This project needs a newer TiffinDB'],
            'mismatch' => ['mismatch', 'This project&apos;s file does not match'],
        ];
    }

    #[DataProvider('unavailableReasons')]
    public function testUnavailableFromTheCore(string $reason, string $title): void
    {
        [$response] = self::send(self::member(), 'GET', "/unavailable/{$reason}", [], self::sessionCookie());

        self::assertErrorPage($response, 503, $title);
        self::assertNull($response->header('Retry-After'));
    }

    public function testUnavailableSystemDatabaseBeforeTheController(): void
    {
        $services = new FakeServices();
        $services->installStateThrows = new Unavailable(Unavailable::NEWER);
        [$response] = self::send($services, 'GET', '/sign-in');

        self::assertErrorPage($response, 503, 'This project needs a newer TiffinDB');
    }

    public function testValidationReachingTheKernelIsA500(): void
    {
        [$response, $log] = self::send(self::member(), 'GET', '/validation', [], self::sessionCookie());

        self::assertErrorPage($response, 500, 'Something went wrong');
        self::assertStringContainsString('ValidationFailed', $log);
        self::assertStringContainsString('class="sign-out"', $response->body);
    }

    public function testOtherFailuresAreA500(): void
    {
        [$response, $log] = self::send(self::member(), 'GET', '/throws', [], self::sessionCookie());

        self::assertErrorPage($response, 500, 'Something went wrong');
        self::assertStringContainsString(FixtureController::SECRET_MESSAGE, $log);
        self::assertStringNotContainsString('secret', $response->body);
    }

    // ------------------------------------------------ step 13

    public function testServicesAreClosedOnceAndHeadersAppliedOnEveryResponse(): void
    {
        foreach (
            [
                ['GET', '/nope', []],
                ['GET', '/', []],
                ['GET', '/projects', self::sessionCookie()],
                ['POST', '/projects', self::sessionCookie()],
                ['GET', '/throws', self::sessionCookie()],
                ['GET', '/busy', self::sessionCookie()],
                ['GET', '/sign-in', ['tdb_session' => self::UNKNOWN_TOKEN]],
            ] as [$method, $uri, $cookies]
        ) {
            $services = self::member();
            [$response] = self::send($services, $method, $uri, [], $cookies);

            self::assertSame(1, $services->closed, "{$method} {$uri}");
            self::assertSame('close', $services->calls[count($services->calls) - 1], 'Closed last.');
            foreach (SecurityHeaders::headers() as $name => $value) {
                self::assertSame($value, $response->header($name), "{$method} {$uri}: {$name}");
            }
        }
    }

    public function testCloseFailureKeepsTheResponse(): void
    {
        $services = new FakeServices();
        $services->closeThrows = new \RuntimeException('close failed');
        [$response, $log] = self::send($services, 'GET', '/sign-in');

        self::assertHandled($response);
        self::assertStringContainsString('close failed', $log);
    }

    public function testCookieObjectsAreTheOnlyCookieChannel(): void
    {
        [$response] = self::send(new FakeServices(), 'GET', '/sign-in');

        self::assertNull($response->header('Set-Cookie'));
        self::assertContainsOnlyInstancesOf(Cookie::class, $response->cookies);
    }
}
