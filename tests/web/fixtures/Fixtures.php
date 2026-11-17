<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: builds kernels and requests against the fixture project
 * root (this directory) or the real one. Loaded with require_once by the
 * tests.
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web\Fixtures;

use RuntimeException;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\Services;

require_once __DIR__ . '/Controllers.php';
require_once __DIR__ . '/FakeServices.php';

final class Fixtures
{
    public const SOURCE_URL = 'https://example.com/tiffindb-source';
    public const VERSION = '9.9.9-test';

    private function __construct()
    {
    }

    public static function root(): string
    {
        return __DIR__;
    }

    /** The repository root (real templates and strings). */
    public static function appRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    public static function config(bool $preview = true): array
    {
        return ['version' => self::VERSION, 'source_url' => self::SOURCE_URL, 'appearance_preview' => $preview];
    }

    /**
     * A kernel over the fixture root. $routes null gives routes(); $services
     * null gives a FakeServices (installed, nobody signed in).
     *
     * @param array<string, mixed>|null $config
     * @param list<mixed>|null          $routes
     */
    public static function kernel(?array $config = null, ?array $routes = null, ?Services $services = null): Kernel
    {
        return new Kernel($config ?? self::config(), self::root(), $routes ?? self::routes(), $services ?? new FakeServices());
    }

    /** The real route table with its controllers, unchanged. */
    public static function applicationRoutes(): array
    {
        $routes = require self::appRoot() . '/src/Web/routes.php';
        if (!is_array($routes)) {
            throw new RuntimeException('routes.php must return an array.');
        }

        return $routes;
    }

    /**
     * The real route table, with `/` served by the fixture start page (the
     * M0 page with theme preview links, for the development-only
     * appearance preview), plus fixture routes open to anyone.
     *
     * @return list<mixed>
     */
    public static function routes(): array
    {
        $routes = [];
        foreach (self::applicationRoutes() as $route) {
            if (is_array($route) && ($route[2] ?? null) === [HomeController::class, 'show']) {
                $route = ['GET', '/', [FixtureController::class, 'home'], 'any'];
            }
            $routes[] = $route;
        }
        foreach (
            [
                ['GET', '/throws', 'throws'],
                ['GET', '/partial-failure', 'partialFailure'],
                ['GET', '/warning', 'warning'],
                ['GET', '/not-a-response', 'notAResponse'],
                ['GET', '/bad-template-name', 'badTemplateName'],
                ['GET', '/items/{id}', 'params'],
                ['GET', '/sets-headers', 'setsHeaders'],
                ['POST', '/post-only', 'params'],
            ] as [$method, $pattern, $action]
        ) {
            $routes[] = [$method, $pattern, [FixtureController::class, $action], 'any'];
        }

        return $routes;
    }

    /** @param array<array-key, mixed> $query */
    public static function request(string $method, string $uri, array $query = [], string $scriptName = '/index.php'): Request
    {
        return Request::fromServer(
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => $scriptName],
            $query,
        );
    }

    /**
     * A request as PHP presents it, with POST fields, cookies, and server
     * values.
     *
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $cookies
     * @param array<string, mixed>    $server Extra server values.
     */
    public static function fullRequest(string $method, string $uri, array $post = [], array $cookies = [], array $server = []): Request
    {
        $query = [];
        $queryString = parse_url('http://example.com' . $uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return Request::fromGlobals(
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php'] + $server,
            $query,
            $post,
            $cookies,
        );
    }

    /**
     * Handles a request with the PHP error log redirected to a temporary
     * file, so expected log lines do not reach the test output.
     *
     * @return array{0: Response, 1: string} The response and the log text.
     */
    public static function handle(Kernel $kernel, Request $request): array
    {
        $log = tempnam(sys_get_temp_dir(), 'tiffindb-log-');
        if ($log === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        $previous = ini_set('error_log', $log);
        try {
            $response = $kernel->handle($request);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            $text = (string) file_get_contents($log);
            unlink($log);
        }

        return [$response, $text];
    }
}
