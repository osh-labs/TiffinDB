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
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\RouteMatch;
use TiffinDB\Web\Router;

/** T-WEB-1: route table validation and matching. */
#[CoversClass(Router::class)]
#[CoversClass(RouteMatch::class)]
final class RouterTest extends TestCase
{
    private const H = [HomeController::class, 'show'];

    private static function router(): Router
    {
        return new Router([
            ['GET', '/', self::H],
            ['GET', '/projects', self::H],
            ['POST', '/projects', self::H],
            ['GET', '/projects/{project}', self::H],
            ['GET', '/projects/{project}/tables/{table}', self::H],
            ['POST', '/sign-out', self::H],
            ['GET', '/.well-known/thing', self::H],
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
            ['GET', '/projects/{project}', [HomeController::class, 'first']],
            ['GET', '/projects/new', [HomeController::class, 'second']],
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
            'unknown method' => [[['FETCH', '/', self::H]]],
            'head is implied' => [[['HEAD', '/', self::H]]],
            'lower-case method' => [[['get', '/', self::H]]],
            'pattern without slash' => [[['GET', 'projects', self::H]]],
            'empty pattern' => [[['GET', '', self::H]]],
            'trailing slash pattern' => [[['GET', '/projects/', self::H]]],
            'upper-case pattern' => [[['GET', '/Projects', self::H]]],
            'regex in pattern' => [[['GET', '/projects/(.*)', self::H]]],
            'dot-dot pattern' => [[['GET', '/a/..', self::H]]],
            'bad placeholder' => [[['GET', '/projects/{1x}', self::H]]],
            'duplicate placeholder' => [[['GET', '/a/{x}/b/{x}', self::H]]],
            'pattern not a string' => [[['GET', 1, self::H]]],
            'duplicate route' => [[['GET', '/', self::H], ['GET', '/', self::H]]],
            'handler a string' => [[['GET', '/', 'HomeController::show']]],
            'handler a closure' => [[['GET', '/', static fn () => null]]],
            'handler one part' => [[['GET', '/', [HomeController::class]]]],
            'handler three parts' => [[['GET', '/', [HomeController::class, 'show', 'x']]]],
            'handler bad method' => [[['GET', '/', [HomeController::class, 'show-it']]]],
            'handler bad class' => [[['GET', '/', ['Not A Class', 'show']]]],
            'handler keyed' => [[['GET', '/', ['class' => HomeController::class, 'method' => 'show']]]],
        ];
    }

    /** @param list<mixed> $table */
    #[DataProvider('malformedTables')]
    public function testMalformedTablesAreRejected(array $table): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Router($table);
    }

    public function testRealRouteTableLoads(): void
    {
        $router = new Router(Router::applicationRoutes());
        $match = $router->match('GET', '/');

        self::assertTrue($match->isFound());
        self::assertSame([HomeController::class, 'show'], $match->handler);
        self::assertTrue(method_exists(HomeController::class, 'show'));
    }

    public function testRealRouteTableHandlersExist(): void
    {
        foreach (Router::applicationRoutes() as $route) {
            [, , [$class, $method]] = $route;
            self::assertTrue(method_exists($class, $method), "{$class}::{$method}");
        }
    }
}
