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
     * The complete table, in order: M1 (spec part 2 section 2.3), M2 (spec
     * part 2 section 2), M3 (spec part 2 section 3), M4 (spec part 2
     * section 3), M5 (spec part 2 section 2), and M7 (screens spec section
     * 4, without the two project deletion routes M7-WP4 adds).
     *
     * @return list<array{string, string, string, string}>
     */
    private static function specifiedTable(): array
    {
        $f = '/projects/{project}/tables/{table}/fields/{field}';
        $t = '/projects/{project}/tables/{table}';
        $i = '/projects/{project}/import';
        $p = '/admin/plugins/{kind}/{plugin}';
        $r = $t . '/records/{record}';
        $c = '/projects/{project}/collections/{collection}';
        $x = '/projects/{project}/trash';
        $s = '/projects/{project}/snapshots';

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
            ['GET', '/settings', 'SettingsController::form', 'user'],
            ['POST', '/settings', 'SettingsController::save', 'user'],
            ['POST', '/settings/reset', 'SettingsController::reset', 'user'],
            ['GET', '/about', 'AboutController::show', 'user'],
            ['GET', '/about/licences/{licence}', 'AboutController::licence', 'user'],
            ['GET', '/recover', 'RecoveryController::form', 'guest'],
            ['POST', '/recover/start', 'RecoveryController::start', 'guest'],
            ['POST', '/recover', 'RecoveryController::recover', 'guest'],
            ['GET', '/projects', 'ProjectController::index', 'user'],
            ['GET', '/projects/new', 'ProjectController::newForm', 'user'],
            ['POST', '/projects', 'ProjectController::create', 'user'],
            ['GET', '/projects/import', 'ArchiveController::form', 'user'],
            ['POST', '/projects/import', 'ArchiveController::import', 'user'],
            ['POST', '/projects/import/{job}', 'ArchiveController::rename', 'user'],
            ['GET', '/projects/{project}', 'ProjectController::open', 'user'],
            ['GET', '/projects/{project}/settings', 'ProjectController::settingsForm', 'user'],
            ['POST', '/projects/{project}/settings', 'ProjectController::settings', 'user'],
            ['GET', '/projects/{project}/sharing', 'SharingController::form', 'user'],
            ['POST', '/projects/{project}/sharing', 'SharingController::save', 'user'],
            ['GET', '/projects/{project}/delete', 'ProjectController::deleteForm', 'user'],
            ['POST', '/projects/{project}/delete', 'ProjectController::delete', 'user'],
            ['GET', $s, 'SnapshotController::index', 'user'],
            ['POST', $s, 'SnapshotController::take', 'user'],
            ['GET', $s . '/{snapshot}/restore', 'SnapshotController::restoreForm', 'user'],
            ['POST', $s . '/{snapshot}/restore', 'SnapshotController::restore', 'user'],
            ['GET', $i, 'ImportController::start', 'user'],
            ['POST', $i, 'ImportController::upload', 'user'],
            ['GET', $i . '/{job}', 'ImportController::show', 'user'],
            ['POST', $i . '/{job}', 'ImportController::saveMapping', 'user'],
            ['GET', $i . '/{job}/reading', 'ImportController::readingForm', 'user'],
            ['POST', $i . '/{job}/reading', 'ImportController::reading', 'user'],
            ['POST', $i . '/{job}/continue', 'ImportController::continue', 'user'],
            ['POST', $i . '/{job}/cancel', 'ImportController::cancel', 'user'],
            ['GET', $i . '/{job}/report', 'ImportController::report', 'user'],
            ['GET', '/projects/{project}/export', 'ExportController::form', 'user'],
            ['POST', '/projects/{project}/export', 'ExportController::export', 'user'],
            ['POST', '/projects/{project}/export/archive', 'ExportController::archive', 'user'],
            ['GET', '/projects/{project}/files/{attachment}', 'FileController::serve', 'user'],
            ['GET', $c, 'CollectionController::show', 'user'],
            ['GET', $c . '/edit', 'CollectionController::editForm', 'user'],
            ['POST', $c . '/edit', 'CollectionController::update', 'user'],
            ['GET', $c . '/delete', 'CollectionController::deleteForm', 'user'],
            ['POST', $c . '/delete', 'CollectionController::delete', 'user'],
            ['GET', $x, 'TrashController::index', 'user'],
            ['POST', $x . '/restore', 'TrashController::restore', 'user'],
            ['POST', $x . '/restore-all', 'TrashController::restoreAll', 'user'],
            ['GET', $x . '/empty', 'TrashController::emptyForm', 'user'],
            ['POST', $x . '/empty', 'TrashController::empty', 'user'],
            ['GET', '/projects/{project}/search', 'SearchController::index', 'user'],
            ['GET', '/projects/{project}/tables', 'TableController::index', 'user'],
            ['GET', '/projects/{project}/tables/new', 'TableController::newForm', 'user'],
            ['POST', '/projects/{project}/tables', 'TableController::create', 'user'],
            ['GET', '/projects/{project}/tables/{table}/fields', 'TableController::fields', 'user'],
            ['POST', '/projects/{project}/tables/{table}/settings', 'TableController::update', 'user'],
            ['GET', '/projects/{project}/tables/{table}/delete', 'TableController::deleteForm', 'user'],
            ['POST', '/projects/{project}/tables/{table}/delete', 'TableController::delete', 'user'],
            ['GET', $t . '/form', 'FormController::edit', 'user'],
            ['POST', $t . '/form', 'FormController::change', 'user'],
            ['GET', $t . '/form/reset', 'FormController::resetForm', 'user'],
            ['POST', $t . '/form/reset', 'FormController::reset', 'user'],
            ['GET', $t . '/form/source', 'FormSourceController::show', 'user'],
            ['POST', $t . '/form/source', 'FormSourceController::submit', 'user'],
            ['GET', $t . '/form/download', 'FormSourceController::download', 'user'],
            ['GET', $t . '/form/upload', 'FormSourceController::uploadForm', 'user'],
            ['POST', $t . '/form/upload', 'FormSourceController::upload', 'user'],
            ['GET', $t, 'RecordListController::index', 'user'],
            ['GET', $t . '/filter', 'RecordListController::filter', 'user'],
            ['GET', $t . '/collections/new', 'CollectionController::newForm', 'user'],
            ['POST', $t . '/collections', 'CollectionController::create', 'user'],
            ['GET', $t . '/records/new', 'RecordController::newForm', 'user'],
            ['POST', $t . '/records', 'RecordController::create', 'user'],
            ['GET', $t . '/records/{record}', 'RecordController::show', 'user'],
            ['POST', $t . '/records/{record}', 'RecordController::update', 'user'],
            ['POST', $t . '/records/{record}/trash', 'RecordController::trash', 'user'],
            ['POST', $t . '/records/{record}/restore', 'RecordController::restore', 'user'],
            ['GET', $r . '/links/{field}', 'LinkController::picker', 'user'],
            ['POST', $r . '/links/{field}/add', 'LinkController::add', 'user'],
            ['POST', $r . '/links/{field}/remove', 'LinkController::remove', 'user'],
            ['GET', $r . '/files/{field}/new', 'FileController::uploadForm', 'user'],
            ['POST', $r . '/files/{field}', 'FileController::upload', 'user'],
            ['GET', $r . '/files/{field}/{attachment}', 'FileController::show', 'user'],
            ['GET', $r . '/files/{field}/{attachment}/remove', 'FileController::removeForm', 'user'],
            ['POST', $r . '/files/{field}/{attachment}/remove', 'FileController::remove', 'user'],
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
            ['GET', '/admin/plugins', 'PluginController::index', 'user'],
            ['GET', $p, 'PluginController::show', 'user'],
            ['GET', $p . '/enable', 'PluginController::enableForm', 'user'],
            ['POST', $p . '/enable', 'PluginController::enable', 'user'],
            ['POST', $p . '/disable', 'PluginController::disable', 'user'],
            ['GET', '/admin/activity', 'AuditController::index', 'user'],
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

    public function testM2RecordRoutesComeInTheSpecifiedOrder(): void
    {
        $router = new Router(Router::applicationRoutes());
        $base = '/projects/ABCDEFGHJKMN/tables/ABCDEFGHJKMN';

        self::assertSame('index', $router->match('GET', $base)->handler[1] ?? null);
        self::assertSame('newForm', $router->match('GET', $base . '/records/new')->handler[1] ?? null, 'records/new comes before records/{record}.');
        self::assertSame('show', $router->match('GET', $base . '/records/12')->handler[1] ?? null);
        self::assertSame('fields', $router->match('GET', $base . '/fields')->handler[1] ?? null);
        self::assertSame('form', $router->match('GET', '/settings')->handler[1] ?? null);
        self::assertTrue($router->match('GET', $base . '/records/12/extra')->isNotFound());
    }

    /**
     * M3 spec part 2 section 3: the archive import literal comes before
     * the project placeholder, and the project import and export routes
     * reach their own handlers; an M3 job key never shadows a project.
     */
    public function testM3RoutesComeInTheSpecifiedOrder(): void
    {
        $router = new Router(Router::applicationRoutes());
        $project = '/projects/abcdefghijkl';
        $handler = static fn (string $method, string $path): string => implode('::', array_map(
            static fn (string $part): string => str_replace('TiffinDB\\Web\\Controller\\', '', $part),
            $router->match($method, $path)->handler ?? ['none', 'none'],
        ));

        self::assertSame('ArchiveController::form', $handler('GET', '/projects/import'));
        self::assertSame('ArchiveController::import', $handler('POST', '/projects/import'));
        self::assertSame('ArchiveController::rename', $handler('POST', '/projects/import/mnopqrstuvwx'));
        self::assertSame('ProjectController::open', $handler('GET', $project));
        self::assertSame('ImportController::start', $handler('GET', $project . '/import'));
        self::assertSame('ImportController::upload', $handler('POST', $project . '/import'));
        self::assertSame('ImportController::show', $handler('GET', $project . '/import/mnopqrstuvwx'));
        self::assertSame('ImportController::saveMapping', $handler('POST', $project . '/import/mnopqrstuvwx'));
        self::assertSame('ImportController::readingForm', $handler('GET', $project . '/import/mnopqrstuvwx/reading'));
        self::assertSame('ImportController::continue', $handler('POST', $project . '/import/mnopqrstuvwx/continue'));
        self::assertSame('ImportController::cancel', $handler('POST', $project . '/import/mnopqrstuvwx/cancel'));
        self::assertSame('ImportController::report', $handler('GET', $project . '/import/mnopqrstuvwx/report'));
        self::assertSame('ExportController::archive', $handler('POST', $project . '/export/archive'));
        self::assertSame('PluginController::enableForm', $handler('GET', '/admin/plugins/import/csv/enable'));
        self::assertSame('PeopleController::show', $handler('GET', '/admin/people/abcdefghijkl'));
        self::assertTrue($router->match('GET', $project . '/import/mnopqrstuvwx/continue')->isMethodNotAllowed());
        self::assertTrue($router->match('GET', $project . '/export/archive')->isMethodNotAllowed());
        self::assertTrue($router->match('GET', '/admin/plugins/import')->isNotFound());
        self::assertTrue($router->match('GET', $project . '/import/../x')->isNotFound());
    }

    /**
     * M4 spec part 2 section 3: the 21 routes, exactly as the table lists
     * them, all for signed-in people, in the specified places: the
     * project-level ones between the archive export and the search, the
     * table-level ones after the filter, the record-level ones after the
     * restore, and `/files/{field}/new` before `/files/{field}/{attachment}`.
     */
    public function testM4RoutesAreTheSpecification(): void
    {
        $table = '/projects/{project}/tables/{table}';
        $record = $table . '/records/{record}';
        $expected = [
            ['GET', '/projects/{project}/files/{attachment}', 'FileController::serve'],
            ['GET', '/projects/{project}/collections/{collection}', 'CollectionController::show'],
            ['GET', '/projects/{project}/collections/{collection}/edit', 'CollectionController::editForm'],
            ['POST', '/projects/{project}/collections/{collection}/edit', 'CollectionController::update'],
            ['GET', '/projects/{project}/collections/{collection}/delete', 'CollectionController::deleteForm'],
            ['POST', '/projects/{project}/collections/{collection}/delete', 'CollectionController::delete'],
            ['GET', '/projects/{project}/trash', 'TrashController::index'],
            ['POST', '/projects/{project}/trash/restore', 'TrashController::restore'],
            ['POST', '/projects/{project}/trash/restore-all', 'TrashController::restoreAll'],
            ['GET', '/projects/{project}/trash/empty', 'TrashController::emptyForm'],
            ['POST', '/projects/{project}/trash/empty', 'TrashController::empty'],
            ['GET', $table . '/collections/new', 'CollectionController::newForm'],
            ['POST', $table . '/collections', 'CollectionController::create'],
            ['GET', $record . '/links/{field}', 'LinkController::picker'],
            ['POST', $record . '/links/{field}/add', 'LinkController::add'],
            ['POST', $record . '/links/{field}/remove', 'LinkController::remove'],
            ['GET', $record . '/files/{field}/new', 'FileController::uploadForm'],
            ['POST', $record . '/files/{field}', 'FileController::upload'],
            ['GET', $record . '/files/{field}/{attachment}', 'FileController::show'],
            ['GET', $record . '/files/{field}/{attachment}/remove', 'FileController::removeForm'],
            ['POST', $record . '/files/{field}/{attachment}/remove', 'FileController::remove'],
        ];
        $m4Controllers = ['LinkController', 'FileController', 'CollectionController', 'TrashController'];

        $actual = [];
        $positions = [];
        foreach (Router::applicationRoutes() as $n => $route) {
            [$method, $pattern, [$class, $name], $access] = $route;
            $short = substr($class, strlen('TiffinDB\\Web\\Controller\\'));
            $positions["{$method} {$pattern}"] = $n;
            if (in_array($short, $m4Controllers, true)) {
                self::assertSame('user', $access, "{$method} {$pattern}");
                $actual[] = [$method, $pattern, $short . '::' . $name];
            }
        }
        self::assertCount(21, $expected);
        self::assertEqualsCanonicalizing($expected, $actual);

        $at = static fn (string $route): int => $positions[$route] ?? -1;
        $after = static function (string $route, string $anchor) use ($at): void {
            self::assertGreaterThan($at($anchor), $at($route), "{$route} comes after {$anchor}");
        };
        foreach (array_slice($expected, 0, 11) as [$method, $pattern]) {
            $after("{$method} {$pattern}", 'POST /projects/{project}/export/archive');
            self::assertLessThan($at('GET /projects/{project}/search'), $at("{$method} {$pattern}"), "{$method} {$pattern} comes before the search");
        }
        foreach (array_slice($expected, 11, 2) as [$method, $pattern]) {
            $after("{$method} {$pattern}", "GET {$table}/filter");
        }
        foreach (array_slice($expected, 13) as [$method, $pattern]) {
            $after("{$method} {$pattern}", "POST {$record}/restore");
        }
        $after("GET {$record}/files/{field}/{attachment}", "GET {$record}/files/{field}/new");

        $router = new Router(Router::applicationRoutes());
        $base = '/projects/abcdefghijkl/tables/mnopqrstuvwx/records/12';
        self::assertSame('uploadForm', $router->match('GET', $base . '/files/abcdefghijkl/new')->handler[1] ?? null, 'files/{field}/new comes before files/{field}/{attachment}.');
        self::assertSame('show', $router->match('GET', $base . '/files/abcdefghijkl/7')->handler[1] ?? null);
        self::assertSame('serve', $router->match('GET', '/projects/abcdefghijkl/files/7')->handler[1] ?? null);
        self::assertSame('show', $router->match('GET', '/projects/abcdefghijkl/collections/mnopqrstuvwx')->handler[1] ?? null);
        self::assertSame('newForm', $router->match('GET', '/projects/abcdefghijkl/tables/mnopqrstuvwx/collections/new')->handler[1] ?? null);
        self::assertTrue($router->match('GET', '/projects/abcdefghijkl/trash/restore')->isMethodNotAllowed());
        self::assertTrue($router->match('GET', $base . '/links/abcdefghijkl/add')->isMethodNotAllowed());
        self::assertTrue($router->match('GET', '/projects/abcdefghijkl/files/7/extra')->isNotFound());
    }

    /**
     * T-WEB-71, M5 spec part 2 section 2: the nine form routes, exactly as
     * the table lists them, all for signed-in people, after the table
     * routes and before the record list.
     */
    public function testM5RoutesAreTheSpecification(): void
    {
        $table = '/projects/{project}/tables/{table}';
        $expected = [
            ['GET', $table . '/form', 'FormController::edit'],
            ['POST', $table . '/form', 'FormController::change'],
            ['GET', $table . '/form/reset', 'FormController::resetForm'],
            ['POST', $table . '/form/reset', 'FormController::reset'],
            ['GET', $table . '/form/source', 'FormSourceController::show'],
            ['POST', $table . '/form/source', 'FormSourceController::submit'],
            ['GET', $table . '/form/download', 'FormSourceController::download'],
            ['GET', $table . '/form/upload', 'FormSourceController::uploadForm'],
            ['POST', $table . '/form/upload', 'FormSourceController::upload'],
        ];

        $actual = [];
        $positions = [];
        foreach (Router::applicationRoutes() as $n => $route) {
            [$method, $pattern, [$class, $name], $access] = $route;
            $short = substr($class, strlen('TiffinDB\\Web\\Controller\\'));
            $positions["{$method} {$pattern}"] = $n;
            if (in_array($short, ['FormController', 'FormSourceController'], true)) {
                self::assertSame('user', $access, "{$method} {$pattern}");
                $actual[] = [$method, $pattern, $short . '::' . $name];
            }
        }
        self::assertSame($expected, $actual);
        foreach ($expected as [$method, $pattern]) {
            self::assertGreaterThan($positions["POST {$table}/delete"], $positions["{$method} {$pattern}"]);
            self::assertLessThan($positions["GET {$table}"], $positions["{$method} {$pattern}"]);
        }

        $router = new Router(Router::applicationRoutes());
        $base = '/projects/abcdefghijkl/tables/mnopqrstuvwx';
        foreach (
            [
                ['GET', '/form', 'edit'], ['POST', '/form', 'change'], ['GET', '/form/reset', 'resetForm'], ['POST', '/form/reset', 'reset'],
                ['GET', '/form/source', 'show'], ['POST', '/form/source', 'submit'], ['GET', '/form/download', 'download'],
                ['GET', '/form/upload', 'uploadForm'], ['POST', '/form/upload', 'upload'],
            ] as [$method, $path, $handler]
        ) {
            self::assertSame($handler, $router->match($method, $base . $path)->handler[1] ?? null, "{$method} {$path}");
        }
        self::assertTrue($router->match('POST', $base . '/form/download')->isMethodNotAllowed());
        self::assertTrue($router->match('GET', $base . '/form/other')->isNotFound());
        self::assertSame('index', $router->match('GET', $base)->handler[1] ?? null, 'The record list is unchanged.');
    }

    /**
     * T-WEB-80, M7 screens spec section 4: the ten routes M7-WP3 adds,
     * exactly as the table lists them, with their access levels, in the
     * specified places: the snapshot routes after the sharing save and
     * before the import routes, the activity route after the plugin
     * routes, and the About and recovery routes after the settings reset.
     * The two project deletion routes are M7-WP4's and join this list with
     * their controller methods.
     */
    public function testM7RoutesAreTheSpecification(): void
    {
        $snapshots = '/projects/{project}/snapshots';
        $expected = [
            ['GET', '/about', 'AboutController::show', 'user'],
            ['GET', '/about/licences/{licence}', 'AboutController::licence', 'user'],
            ['GET', '/recover', 'RecoveryController::form', 'guest'],
            ['POST', '/recover/start', 'RecoveryController::start', 'guest'],
            ['POST', '/recover', 'RecoveryController::recover', 'guest'],
            ['GET', $snapshots, 'SnapshotController::index', 'user'],
            ['POST', $snapshots, 'SnapshotController::take', 'user'],
            ['GET', $snapshots . '/{snapshot}/restore', 'SnapshotController::restoreForm', 'user'],
            ['POST', $snapshots . '/{snapshot}/restore', 'SnapshotController::restore', 'user'],
            ['GET', '/admin/activity', 'AuditController::index', 'user'],
        ];
        $m7Controllers = ['SnapshotController', 'AuditController', 'AboutController', 'RecoveryController'];

        $actual = [];
        $positions = [];
        foreach (Router::applicationRoutes() as $n => $route) {
            [$method, $pattern, [$class, $name], $access] = $route;
            $short = substr($class, strlen('TiffinDB\\Web\\Controller\\'));
            $positions["{$method} {$pattern}"] = $n;
            if (in_array($short, $m7Controllers, true)) {
                $actual[] = [$method, $pattern, $short . '::' . $name, $access];
            }
        }
        self::assertCount(10, $expected);
        self::assertSame($expected, $actual);

        $at = static fn (string $route): int => $positions[$route] ?? -1;
        foreach (array_slice($expected, 0, 5) as [$method, $pattern]) {
            self::assertGreaterThan($at('POST /settings/reset'), $at("{$method} {$pattern}"), "{$method} {$pattern}");
            self::assertLessThan($at('GET /projects'), $at("{$method} {$pattern}"), "{$method} {$pattern}");
        }
        foreach (array_slice($expected, 5, 4) as [$method, $pattern]) {
            self::assertGreaterThan($at('POST /projects/{project}/sharing'), $at("{$method} {$pattern}"), "{$method} {$pattern}");
            self::assertLessThan($at('GET /projects/{project}/import'), $at("{$method} {$pattern}"), "{$method} {$pattern}");
        }
        self::assertGreaterThan($at('POST /admin/plugins/{kind}/{plugin}/disable'), $at('GET /admin/activity'));

        // The two project deletion routes (M7-WP4), on the existing ProjectController.
        $delete = [
            ['GET', '/projects/{project}/delete', 'ProjectController::deleteForm', 'user'],
            ['POST', '/projects/{project}/delete', 'ProjectController::delete', 'user'],
        ];
        $deleteActual = [];
        foreach (Router::applicationRoutes() as $route) {
            [$method, $pattern, [$class, $name], $access] = $route;
            if ($class === \TiffinDB\Web\Controller\ProjectController::class && in_array($name, ['deleteForm', 'delete'], true)) {
                $deleteActual[] = [$method, $pattern, 'ProjectController::' . $name, $access];
            }
        }
        self::assertSame($delete, $deleteActual);
        foreach ($delete as [$method, $pattern]) {
            self::assertGreaterThan($at('POST /projects/{project}/sharing'), $at("{$method} {$pattern}"), "{$method} {$pattern}");
            self::assertLessThan($at('GET /projects/{project}/import'), $at("{$method} {$pattern}"), "{$method} {$pattern}");
        }

        $router = new Router(Router::applicationRoutes());
        $project = '/projects/abcdefghijkl';
        $snapshot = '20261112T031500Z-manual-k3m7';
        self::assertSame('index', $router->match('GET', $project . '/snapshots')->handler[1] ?? null);
        self::assertSame('take', $router->match('POST', $project . '/snapshots')->handler[1] ?? null);
        self::assertSame('restoreForm', $router->match('GET', $project . '/snapshots/' . $snapshot . '/restore')->handler[1] ?? null);
        self::assertSame('restore', $router->match('POST', $project . '/snapshots/' . $snapshot . '/restore')->handler[1] ?? null);
        self::assertSame('licence', $router->match('GET', '/about/licences/tiffindb')->handler[1] ?? null);
        self::assertSame(AccessLevel::Guest, $router->match('POST', '/recover/start')->access);
        self::assertSame(AccessLevel::User, $router->match('GET', '/admin/activity')->access);
        self::assertTrue($router->match('GET', '/recover/start')->isMethodNotAllowed());
        self::assertTrue($router->match('GET', $project . '/snapshots/' . $snapshot)->isNotFound());
        self::assertTrue($router->match('GET', $project . '/snapshots/a.tiffin/restore')->isNotFound());
        self::assertTrue($router->match('POST', '/admin/activity')->isMethodNotAllowed());
        self::assertSame('deleteForm', $router->match('GET', $project . '/delete')->handler[1] ?? null);
        self::assertSame('delete', $router->match('POST', $project . '/delete')->handler[1] ?? null);
        self::assertSame(AccessLevel::User, $router->match('POST', $project . '/delete')->access);
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
            // M2 spec part 2 section 2: created by M2-WP6 to WP8.
            'RecordListController', 'SearchController', 'RecordController', 'SettingsController',
            // M3 spec part 2 section 3: created by M3-WP9 and WP10.
            'ImportController', 'ExportController', 'ArchiveController', 'PluginController',
            // M4 spec part 2 section 3: created by M4-WP6 to WP8.
            'LinkController', 'FileController', 'CollectionController', 'TrashController',
            // M5 spec part 2 section 2: created by M5-WP5 and WP6.
            'FormController', 'FormSourceController',
            // M7 screens spec section 4: created by M7-WP4 and WP5.
            'SnapshotController', 'AuditController', 'AboutController', 'RecoveryController',
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
