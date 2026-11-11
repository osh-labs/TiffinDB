<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: builds kernels and requests against the fixture project
 * root (this directory). Loaded with require_once by the tests.
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web\Fixtures;

use RuntimeException;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

require_once __DIR__ . '/Controllers.php';

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

    /** @return array<string, mixed> */
    public static function config(bool $preview = true): array
    {
        return ['version' => self::VERSION, 'source_url' => self::SOURCE_URL, 'appearance_preview' => $preview];
    }

    /**
     * @param array<string, mixed>|null $config
     * @param list<mixed>|null          $routes
     */
    public static function kernel(?array $config = null, ?array $routes = null): Kernel
    {
        return new Kernel($config ?? self::config(), self::root(), $routes);
    }

    /** @return list<mixed> The real route table plus fixture routes. */
    public static function routes(): array
    {
        $routes = require dirname(__DIR__, 3) . '/src/Web/routes.php';
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
            $routes[] = [$method, $pattern, [FixtureController::class, $action]];
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
