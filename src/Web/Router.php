<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * Matches a method and path against the static route table (ADR 0008
 * decision 2). Patterns are literal paths with optional `{name}` segments;
 * a placeholder matches one segment of `[A-Za-z0-9_-]+`. Matching is exact
 * and case-sensitive, and the first match wins. HEAD matches GET routes.
 */
final class Router
{
    /** Methods a route may declare. HEAD is implied by GET. */
    public const METHODS = ['GET', 'POST'];

    private const LITERAL_SEGMENT = '/^[a-z0-9._-]+$/';
    private const PLACEHOLDER_SEGMENT = '/^\{([a-z][A-Za-z0-9_]*)\}$/';
    private const PARAMETER_VALUE = '/^[A-Za-z0-9_-]+$/';
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @var list<array{method: string, segments: list<array{0: bool, 1: string}>|null, handler: array{0: class-string, 1: string}}>
     *      Each segment is [isPlaceholder, literal text or parameter name];
     *      `segments` is null for the root pattern `/`.
     */
    private readonly array $routes;

    /** @param list<mixed> $table Route table in the routes.php format. */
    public function __construct(array $table)
    {
        if (!array_is_list($table)) {
            throw new InvalidArgumentException('The route table must be a list.');
        }

        $routes = [];
        $seen = [];
        foreach ($table as $index => $route) {
            if (!is_array($route) || !array_is_list($route) || count($route) !== 3) {
                throw new InvalidArgumentException("Route {$index}: expected [method, pattern, handler].");
            }
            [$method, $pattern, $handler] = $route;

            if (!is_string($method) || !in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException("Route {$index}: unknown method.");
            }
            if (!is_string($pattern)) {
                throw new InvalidArgumentException("Route {$index}: the pattern must be a string.");
            }
            $segments = self::parsePattern($pattern, $index);

            if (
                !is_array($handler) || !array_is_list($handler) || count($handler) !== 2
                || !is_string($handler[0]) || !is_string($handler[1])
                || !self::isClassName($handler[0])
                || preg_match(self::IDENTIFIER, $handler[1]) !== 1
            ) {
                throw new InvalidArgumentException("Route {$index}: the handler must be [ControllerClass, 'method'].");
            }

            $key = $method . ' ' . $pattern;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Route {$index}: duplicate method and pattern.");
            }
            $seen[$key] = true;

            /** @var array{0: class-string, 1: string} $handler */
            $routes[] = ['method' => $method, 'segments' => $segments, 'handler' => $handler];
        }

        $this->routes = $routes;
    }

    /** Loads the application's route table from src/Web/routes.php. */
    public static function applicationRoutes(): array
    {
        $table = require __DIR__ . '/routes.php';
        if (!is_array($table)) {
            throw new InvalidArgumentException('routes.php must return an array.');
        }

        return $table;
    }

    public function match(string $method, string $path): RouteMatch
    {
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = self::matchPath($route['segments'], $path);
            if ($params === null) {
                continue;
            }
            if ($route['method'] === $lookup) {
                return RouteMatch::found($route['handler'], $params);
            }
            $allowed[$route['method']] = true;
        }

        if ($allowed === []) {
            return RouteMatch::notFound();
        }
        if (isset($allowed['GET'])) {
            $allowed['HEAD'] = true;
        }
        $methods = array_keys($allowed);
        sort($methods);

        return RouteMatch::methodNotAllowed($methods);
    }

    /**
     * @param list<array{0: bool, 1: string}>|null $segments
     * @return array<string, string>|null Parameters on a match, otherwise null.
     */
    private static function matchPath(?array $segments, string $path): ?array
    {
        if ($segments === null) {
            return $path === '/' ? [] : null;
        }
        if (!str_starts_with($path, '/')) {
            return null;
        }

        $parts = explode('/', substr($path, 1));
        if (count($parts) !== count($segments)) {
            return null;
        }

        $params = [];
        foreach ($segments as $i => [$isPlaceholder, $text]) {
            if ($isPlaceholder) {
                if (preg_match(self::PARAMETER_VALUE, $parts[$i]) !== 1) {
                    return null;
                }
                $params[$text] = $parts[$i];
            } elseif ($parts[$i] !== $text) {
                return null;
            }
        }

        return $params;
    }

    /** @return list<array{0: bool, 1: string}>|null */
    private static function parsePattern(string $pattern, int $index): ?array
    {
        if ($pattern === '/') {
            return null;
        }
        if (!str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException("Route {$index}: the pattern must start with \"/\".");
        }

        $segments = [];
        $names = [];
        foreach (explode('/', substr($pattern, 1)) as $segment) {
            if (preg_match(self::PLACEHOLDER_SEGMENT, $segment, $m) === 1) {
                if (isset($names[$m[1]])) {
                    throw new InvalidArgumentException("Route {$index}: duplicate placeholder name.");
                }
                $names[$m[1]] = true;
                $segments[] = [true, $m[1]];
            } elseif (preg_match(self::LITERAL_SEGMENT, $segment) === 1 && $segment !== '.' && $segment !== '..') {
                $segments[] = [false, $segment];
            } else {
                throw new InvalidArgumentException("Route {$index}: invalid pattern segment.");
            }
        }

        return $segments;
    }

    private static function isClassName(string $name): bool
    {
        foreach (explode('\\', ltrim($name, '\\')) as $part) {
            if (preg_match(self::IDENTIFIER, $part) !== 1) {
                return false;
            }
        }

        return true;
    }
}
