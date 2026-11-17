<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Web\AccessLevel;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\RouteMatch;
use TiffinDB\Web\Router;

/** T-WEB-1, T-WEB-15: route table validation and matching; the real M1 table. */
#[CoversClass(Router::class)]
#[CoversClass(RouteMatch::class)]
final class RouterTest extends TestCase
{
    private const H = [HomeController::class, 'show'];

    private static function router(): Router
    {
        return new Router([
            ['GET', '/', self::H, 'user'],
            ['GET', '/projects', self::H, 'user'],
            ['POST', '/projects', self::H, 'user'],
            ['GET', '/projects/{project}', self::H, 'user'],
            ['GET', '/projects/{project}/tables/{table}', self::H, 'user'],
            ['POST', '/sign-out', self::H, 'user'],
            ['GET', '/.well-known/thing', self::H, 'user'],
        ]);
    }

    public function testExactMatch(): void
    {
        $match = self::router()->match('GET', '/projects');

        self::assertTrue($match->isFound());
        self::assertSame(self::H, $match->handler);
        self::assertSame([], $match->params);
    }

    public function testRootMatches(): void
    {
        self::assertTrue(self::router()->match('GET', '/')->isFound());
    }

    public function testHeadMatchesGet(): void
    {
        self::assertTrue(self::router()->match('HEAD', '/')->isFound());
        self::assertTrue(self::router()->match('HEAD', '/projects/abc')->isFound());
    }

    public function testHeadDoesNotMatchPostOnlyRoute(): void
    {
        $match = self::router()->match('HEAD', '/sign-out');

        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(['POST'], $match->allowedMethods);
    }

    /** @return array<string, array{string}> */
    public static function notFoundPaths(): array
    {
        return [
            'unknown' => ['/nope'],
            'trailing slash' => ['/projects/'],
            'double slash' => ['//projects'],
            'inner double slash' => ['/projects//x'],
            'upper case' => ['/Projects'],
            'dot segment' => ['/projects/../projects'],
            'single dot' => ['/projects/./x'],
            'empty' => [''],
            'no leading slash' => ['projects'],
            'too many segments' => ['/projects/a/b'],
            'path info' => ['/index.php/projects'],
            'front controller' => ['/index.php'],
            'NUL' => ["/projects\0"],
            'trailing newline' => ["/projects\n"],
            'root with newline' => ["/\n"],
        ];
    }

    #[DataProvider('notFoundPaths')]
    public function testUnknownPathsAreNotFound(string $path): void
    {
        self::assertTrue(self::router()->match('GET', $path)->isNotFound());
    }

    public function testKnownPathWithOtherMethodReportsAllowedMethods(): void
    {
        $match = self::router()->match('DELETE', '/projects');
        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(['GET', 'HEAD', 'POST'], $match->allowedMethods);

        $match = self::router()->match('GET', '/sign-out');
        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(['POST'], $match->allowedMethods);
    }

    public function testPlaceholdersCaptureOneSegment(): void
    {
        $match = self::router()->match('GET', '/projects/p-1_A/tables/t9');

        self::assertTrue($match->isFound());
        self::assertSame(['project' => 'p-1_A', 'table' => 't9'], $match->params);
    }

    /** @return array<string, array{string}> */
    public static function badSegments(): array
    {
        return [
            'dot' => ['/projects/a.b'],
            'space' => ['/projects/a b'],
            'percent' => ['/projects/a%20b'],
            'empty' => ['/projects/'],
            'non-ASCII' => ['/projects/café'],
            'quote' => ['/projects/a"b'],
            'angle' => ['/projects/<x>'],
            'dot-dot' => ['/projects/..'],
            'trailing newline' => ["/projects/abc\n"],
            'trailing CRLF' => ["/projects/abc\r\n"],
        ];
    }

    #[DataProvider('badSegments')]
    public function testPlaceholdersRejectBadSegments(string $path): void
    {
        self::assertTrue(self::router()->match('GET', $path)->isNotFound());
    }

    public function testFirstMatchWins(): void
    {
        $router = new Router([
            ['GET', '/projects/{project}', [HomeController::class, 'first'], 'user'],
            ['GET', '/projects/new', [HomeController::class, 'second'], 'user'],
        ]);

        self::assertSame([HomeController::class, 'first'], $router->match('GET', '/projects/new')->handler);
    }

    /** @return array<string, array{list<mixed>}> */
    public static function malformedTables(): array
    {
        return [
            'not a list' => [['a' => ['GET', '/', self::H]]],
            'route not a list' => [[['method' => 'GET', 'pattern' => '/', 'handler' => self::H]]],
            'too few parts' => [[['GET', '/']]],
            'access missing' => [[['GET', '/', self::H]]],
            'unknown access' => [[['GET', '/', self::H, 'admin']]],
            'access upper case' => [[['GET', '/', self::H, 'User']]],
            'access not a string' => [[['GET', '/', self::H, 1]]],
            'access enum case' => [[['GET', '/', self::H, AccessLevel::User]]],
            'access with trailing newline' => [[['GET', '/', self::H, "user\n"]]],
            'too many parts' => [[['GET', '/', self::H, 'user', 'x']]],
            'unknown method' => [[['FETCH', '/', self::H, 'user']]],
            'head is implied' => [[['HEAD', '/', self::H, 'user']]],
            'lower-case method' => [[['get', '/', self::H, 'user']]],
            'pattern without slash' => [[['GET', 'projects', self::H, 'user']]],
            'empty pattern' => [[['GET', '', self::H, 'user']]],
            'trailing slash pattern' => [[['GET', '/projects/', self::H, 'user']]],
            'upper-case pattern' => [[['GET', '/Projects', self::H, 'user']]],
            'regex in pattern' => [[['GET', '/projects/(.*)', self::H, 'user']]],
            'dot-dot pattern' => [[['GET', '/a/..', self::H, 'user']]],
            'bad placeholder' => [[['GET', '/projects/{1x}', self::H, 'user']]],
            'duplicate placeholder' => [[['GET', '/a/{x}/b/{x}', self::H, 'user']]],
            'pattern not a string' => [[['GET', 1, self::H, 'user']]],
            'duplicate route' => [[['GET', '/', self::H, 'user'], ['GET', '/', self::H, 'user']]],
            'handler a string' => [[['GET', '/', 'HomeController::show', 'user']]],
            'handler a closure' => [[['GET', '/', static fn () => null, 'user']]],
            'handler one part' => [[['GET', '/', [HomeController::class], 'user']]],
            'handler three parts' => [[['GET', '/', [HomeController::class, 'show', 'x'], 'user']]],
            'handler bad method' => [[['GET', '/', [HomeController::class, 'show-it'], 'user']]],
            'handler bad class' => [[['GET', '/', ['Not A Class', 'show'], 'user']]],
            'literal with trailing newline' => [[['GET', "/projects\n", self::H, 'user']]],
            'placeholder with trailing newline' => [[['GET', "/projects/{id}\n", self::H, 'user']]],
            'handler method with trailing newline' => [[['GET', '/', [HomeController::class, "show\n"], 'user']]],
            'handler class with trailing newline' => [[['GET', '/', [HomeController::class . "\n", 'show'], 'user']]],
            'handler keyed' => [[['GET', '/', ['class' => HomeController::class, 'method' => 'show'], 'user']]],
        ];
    }

    /** @param list<mixed> $table */
    #[DataProvider('malformedTables')]
    public function testMalformedTablesAreRejected(array $table): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Router($table);
    }

    public function testFoundMatchCarriesTheAccessLevel(): void
    {
        $router = new Router([
            ['GET', '/setup', self::H, 'setup'],
            ['GET', '/sign-in', self::H, 'guest'],
            ['GET', '/projects', self::H, 'user'],
            ['GET', '/', self::H, 'any'],
        ]);

        self::assertSame(AccessLevel::Setup, $router->match('GET', '/setup')->access);
        self::assertSame(AccessLevel::Guest, $router->match('HEAD', '/sign-in')->access);
        self::assertSame(AccessLevel::User, $router->match('GET', '/projects')->access);
        self::assertSame(AccessLevel::Any, $router->match('GET', '/')->access);
        self::assertNull($router->match('GET', '/nope')->access);
        self::assertNull($router->match('POST', '/')->access);
    }

    public function testRealRouteTableLoads(): void
    {
        $router = new Router(Router::applicationRoutes());
        $match = $router->match('GET', '/');

        self::assertTrue($match->isFound());
        self::assertSame([HomeController::class, 'show'], $match->handler);
        self::assertSame(AccessLevel::Any, $match->access);
        self::assertTrue(method_exists(HomeController::class, 'show'));
    }

    /**
     * The complete M1 table (spec part 2 section 2.3), in order.
     *
     * @return list<array{string, string, string, string}>
     */
    private static function specifiedTable(): array
    {
        $f = '/projects/{project}/tables/{table}/fields/{field}';

        return [
            ['GET', '/', 'HomeController::show', 'any'],
            ['GET', '/setup', 'SetupController::show', 'setup'],
            ['POST', '/setup/data-folder', 'SetupController::dataFolder', 'setup'],
            ['POST', '/setup/admin', 'SetupController::admin', 'setup'],
            ['GET', '/sign-in', 'SignInController::form', 'guest'],
            ['POST', '/sign-in', 'SignInController::submit', 'guest'],
            ['POST', '/sign-out', 'SignInController::signOut', 'user'],
            ['GET', '/account/password', 'AccountController::passwordForm', 'user'],
            ['POST', '/account/password', 'AccountController::password', 'user'],
            ['GET', '/projects', 'ProjectController::index', 'user'],
            ['GET', '/projects/new', 'ProjectController::newForm', 'user'],
            ['POST', '/projects', 'ProjectController::create', 'user'],
            ['GET', '/projects/{project}', 'ProjectController::open', 'user'],
            ['GET', '/projects/{project}/settings', 'ProjectController::settingsForm', 'user'],
            ['POST', '/projects/{project}/settings', 'ProjectController::settings', 'user'],
            ['GET', '/projects/{project}/sharing', 'SharingController::form', 'user'],
            ['POST', '/projects/{project}/sharing', 'SharingController::save', 'user'],
            ['GET', '/projects/{project}/tables', 'TableController::index', 'user'],
            ['GET', '/projects/{project}/tables/new', 'TableController::newForm', 'user'],
            ['POST', '/projects/{project}/tables', 'TableController::create', 'user'],
            ['GET', '/projects/{project}/tables/{table}/fields', 'TableController::fields', 'user'],
            ['POST', '/projects/{project}/tables/{table}/settings', 'TableController::update', 'user'],
            ['GET', '/projects/{project}/tables/{table}/delete', 'TableController::deleteForm', 'user'],
            ['POST', '/projects/{project}/tables/{table}/delete', 'TableController::delete', 'user'],
            ['GET', '/projects/{project}/tables/{table}/fields/new', 'FieldController::newForm', 'user'],
            ['POST', '/projects/{project}/tables/{table}/fields', 'FieldController::create', 'user'],
            ['GET', $f, 'FieldController::editForm', 'user'],
            ['POST', $f, 'FieldController::update', 'user'],
            ['GET', $f . '/type', 'FieldController::typeForm', 'user'],
            ['POST', $f . '/type', 'FieldController::changeType', 'user'],
            ['POST', $f . '/move', 'FieldController::move', 'user'],
            ['GET', $f . '/delete', 'FieldController::deleteForm', 'user'],
            ['POST', $f . '/delete', 'FieldController::delete', 'user'],
            ['GET', '/admin/people', 'PeopleController::index', 'user'],
            ['GET', '/admin/people/new', 'PeopleController::newForm', 'user'],
            ['POST', '/admin/people', 'PeopleController::create', 'user'],
            ['GET', '/admin/people/{person}', 'PeopleController::show', 'user'],
            ['POST', '/admin/people/{person}', 'PeopleController::update', 'user'],
            ['POST', '/admin/people/{person}/password', 'PeopleController::password', 'user'],
            ['POST', '/admin/people/{person}/projects', 'PeopleController::access', 'user'],
            ['GET', '/admin/people/{person}/deactivate', 'PeopleController::deactivateForm', 'user'],
            ['POST', '/admin/people/{person}/deactivate', 'PeopleController::deactivate', 'user'],
            ['POST', '/admin/people/{person}/reactivate', 'PeopleController::reactivate', 'user'],
        ];
    }

    public function testRealRouteTableIsTheSpecifiedOne(): void
    {
        $actual = [];
        foreach (Router::applicationRoutes() as $route) {
            self::assertIsArray($route);
            [$method, $pattern, [$class, $action], $access] = $route;
            self::assertStringStartsWith('TiffinDB\\Web\\Controller\\', $class);
            $actual[] = [$method, $pattern, substr($class, strlen('TiffinDB\\Web\\Controller\\')) . '::' . $action, $access];
        }

        self::assertSame(self::specifiedTable(), $actual);
    }

    /**
     * Every route of the real table is reached by a concrete path built
     * from its own pattern, so no earlier placeholder route shadows a
     * literal one (`/projects/new` before `/projects/{project}`).
     */
    public function testEveryRealRouteIsReachable(): void
    {
        $router = new Router(Router::applicationRoutes());
        foreach (Router::applicationRoutes() as $route) {
            [$method, $pattern, $handler, $access] = $route;
            $path = (string) preg_replace('/\{[a-z]+\}/', 'ABCDEFGHJKMN', $pattern);
            $match = $router->match($method, $path);

            self::assertTrue($match->isFound(), "{$method} {$pattern}");
            self::assertSame($handler, $match->handler, "{$method} {$pattern}");
            self::assertSame(AccessLevel::from($access), $match->access, "{$method} {$pattern}");
        }
    }

    public function testM2ListRouteIsNotRouted(): void
    {
        $router = new Router(Router::applicationRoutes());

        self::assertTrue($router->match('GET', '/projects/ABCDEFGHJKMN/tables/ABCDEFGHJKMN')->isNotFound());
    }

    /**
     * Controllers of later work packages (WP9 to WP11) may not exist yet;
     * once a class exists, every method the table names must exist on it.
     */
    public function testRealRouteTableHandlersExist(): void
    {
        $pending = [
            'SetupController', 'SignInController', 'AccountController', 'ProjectController',
            'SharingController', 'TableController', 'FieldController', 'PeopleController',
        ];
        foreach (Router::applicationRoutes() as $route) {
            [, , [$class, $method]] = $route;
            $short = substr($class, strlen('TiffinDB\\Web\\Controller\\'));
            if (!class_exists($class)) {
                self::assertContains($short, $pending, "{$class} must exist");
                continue;
            }
            self::assertTrue(method_exists($class, $method), "{$class}::{$method}");
        }
    }
}
