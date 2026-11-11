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
use TiffinDB\Tests\Web\Fixtures\FixtureController;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * The kernel driven end to end against the fixture project root: status
 * codes, error pages, and what error responses must never reveal.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(HomeController::class)]
#[CoversClass(Response::class)]
final class KernelTest extends TestCase
{
    /** @return array{0: Response, 1: string} */
    private static function get(string $uri, string $method = 'GET', array $query = []): array
    {
        return Fixtures::handle(Fixtures::kernel(null, Fixtures::routes()), Fixtures::request($method, $uri, $query));
    }

    private static function assertNoLeak(Response $response, string $log = ''): void
    {
        foreach (
            [
                Fixtures::root(), dirname(__DIR__, 2), '/fixture/secret', 'secret', '.php', 'RuntimeException',
                'ErrorException', 'Exception', 'Stack trace', '#0 ', 'Controller failure', 'Template failure',
                'Undefined', 'source_url', 'appearance_preview',
            ] as $needle
        ) {
            self::assertStringNotContainsString($needle, $response->body, "Body must not contain {$needle}");
        }
    }

    public function testHomePage(): void
    {
        [$response] = self::get('/');

        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith('<!DOCTYPE html>', $response->body);
        self::assertStringContainsString('<title>TiffinDB</title>', $response->body);
        self::assertStringContainsString('href="' . Fixtures::SOURCE_URL . '"', $response->body);
        self::assertStringContainsString('aria-label="Preview a theme"', $response->body);
        self::assertStringContainsString('<a href="/" aria-current="true">Light</a>', $response->body);
    }

    public function testHeadGivesTheSameResponse(): void
    {
        [$get] = self::get('/');
        [$head] = self::get('/', 'HEAD');

        self::assertSame(200, $head->status);
        self::assertSame($get->headers, $head->headers);
    }

    public function testBasePathWithSpecialCharactersIsEncodedInLinks(): void
    {
        [$response] = Fixtures::handle(
            Fixtures::kernel(),
            Fixtures::request('GET', '/my%20dir/100%25/', [], '/my dir/100%/public/index.php'),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/my%20dir/100%25/assets/css/app.css?v=', $response->body);
        self::assertStringContainsString('href="/my%20dir/100%25/?theme=dark"', $response->body);
        self::assertStringNotContainsString('/my dir', $response->body);
    }

    public function testBasePathIsUsedForLinks(): void
    {
        [$response] = Fixtures::handle(
            Fixtures::kernel(),
            Fixtures::request('GET', '/sub/', [], '/sub/public/index.php'),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/sub/assets/css/app.css?v=' . Fixtures::VERSION . '"', $response->body);
        self::assertStringContainsString('href="/sub/?theme=dark"', $response->body);
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function errorCases(): array
    {
        return [
            'not found' => ['GET', '/nope', 404, 'Page not found'],
            'trailing slash' => ['GET', '/throws/', 404, 'Page not found'],
            'double slash' => ['GET', '//', 404, 'Page not found'],
            'dot segments' => ['GET', '/a/../', 404, 'Page not found'],
            'encoded traversal' => ['GET', '/%2e%2e/%2e%2e/config/app.php', 404, 'Page not found'],
            'path info' => ['GET', '/index.php/extra', 404, 'Page not found'],
            'front controller' => ['GET', '/index.php', 404, 'Page not found'],
            'directory' => ['GET', '/assets/', 404, 'Page not found'],
            'NUL' => ['GET', '/a%00b', 400, 'That address is not valid'],
            'invalid UTF-8' => ['GET', '/%C3%28', 400, 'That address is not valid'],
            'overlong' => ['GET', '/' . str_repeat('x', 5000), 400, 'That address is not valid'],
            'POST' => ['POST', '/', 405, 'That action is not available here'],
            'DELETE unknown path' => ['DELETE', '/nope', 405, 'That action is not available here'],
            'bad method token' => ["GE T", '/', 405, 'That action is not available here'],
            'route method' => ['GET', '/post-only', 405, 'That action is not available here'],
            'throws' => ['GET', '/throws', 500, 'Something went wrong'],
            'template throws' => ['GET', '/partial-failure', 500, 'Something went wrong'],
            'template warning' => ['GET', '/warning', 500, 'Something went wrong'],
            'not a response' => ['GET', '/not-a-response', 500, 'Something went wrong'],
            'bad template name' => ['GET', '/bad-template-name', 500, 'Something went wrong'],
        ];
    }

    #[DataProvider('errorCases')]
    public function testErrorPagesUseTheShell(string $method, string $uri, int $status, string $title): void
    {
        [$response] = self::get($uri, $method);

        self::assertSame($status, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith('<!DOCTYPE html>', $response->body);
        self::assertStringContainsString("<title>{$title} - TiffinDB</title>", $response->body);
        self::assertStringContainsString("<h1>{$title}</h1>", $response->body);
        self::assertStringContainsString('<a href="/">Go to the start page</a>', $response->body);
        self::assertStringContainsString('href="' . Fixtures::SOURCE_URL . '"', $response->body);
        self::assertNoLeak($response);
    }

    public function testRequestPathIsNotReflected(): void
    {
        $marker = 'reflected-marker-' . str_repeat('z', 8);
        foreach (['/' . $marker, '/%3Cscript%3E' . $marker] as $uri) {
            [$response] = self::get($uri);
            self::assertStringNotContainsString($marker, $response->body);
            self::assertStringNotContainsString('<script', $response->body);
        }
    }

    public function testAllowHeaders(): void
    {
        [$response] = self::get('/', 'POST');
        self::assertSame('GET, HEAD', $response->header('Allow'));

        [$response] = self::get('/post-only');
        self::assertSame('POST', $response->header('Allow'));

        [$response] = self::get('/');
        self::assertNull($response->header('Allow'));
    }

    public function testRouteParametersReachTheController(): void
    {
        [$response] = self::get('/items/abc-1');

        self::assertSame(200, $response->status);
        self::assertSame('id=abc-1', $response->body);
    }

    public function testFailureIsLoggedWithoutRequestData(): void
    {
        [$response, $log] = self::get('/throws', 'GET', ['q' => 'query-marker']);

        self::assertSame(500, $response->status);
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString(FixtureController::SECRET_MESSAGE, $log);
        self::assertStringNotContainsString('query-marker', $log);
        self::assertSame(1, substr_count(trim($log), "\n") + 1, 'One log line.');
    }

    public function testTemplateFailureDiscardsPartialOutput(): void
    {
        $level = ob_get_level();
        [$response] = self::get('/partial-failure');

        self::assertSame($level, ob_get_level());
        self::assertStringNotContainsString('PARTIAL-OUTPUT-MARKER', $response->body);
        self::assertStringNotContainsString('NESTED-PARTIAL-MARKER', $response->body);
        self::assertStringNotContainsString('WARNING-OUTPUT-MARKER', self::get('/warning')[0]->body);
    }

    public function testErrorHandlerIsRestored(): void
    {
        $before = set_error_handler(null);
        restore_error_handler();

        self::get('/warning');

        $after = set_error_handler(null);
        restore_error_handler();
        self::assertSame($before, $after);
    }

    public function testFallbackWhenTheErrorPageCannotRender(): void
    {
        $kernel = new Kernel(Fixtures::config(), sys_get_temp_dir() . '/tiffindb-no-such-root');
        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        self::assertStringNotContainsString('tiffindb-no-such-root', $response->body);
        self::assertNoLeak($response);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badConfigs(): array
    {
        $good = Fixtures::config();

        return [
            'empty' => [[]],
            'version missing' => [array_diff_key($good, ['version' => 1])],
            'source not https' => [['source_url' => 'javascript:alert(1)'] + $good],
            'source with quote' => [['source_url' => 'https://example.com/"x'] + $good],
            'source with trailing newline' => [['source_url' => "https://example.com/x\n"] + $good],
            'preview not bool' => [['appearance_preview' => 'yes'] + $good],
        ];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('badConfigs')]
    public function testInvalidConfigurationGivesThePlainFallback(array $config): void
    {
        [$response] = Fixtures::handle(Fixtures::kernel($config), Fixtures::request('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        self::assertStringNotContainsString('alert', $response->body);
        self::assertNoLeak($response);
    }
}
