<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * An immutable HTTP request, reduced to what the M0 pipeline reads: the
 * method, the route path, the detected base path, and the query array.
 *
 * Built from the server array by fromServer(), which is only ever called
 * from public/index.php; tests use the constructor or fromServer() with a
 * synthetic array. Nothing here reads a superglobal.
 */
final class Request
{
    /** Longest request path accepted, in bytes; longer ones are malformed. */
    public const MAX_PATH_BYTES = 2048;

    /**
     * @param array<array-key, mixed> $query The query array as given; values
     *                                        may be strings or arrays.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath = '',
        public readonly array $query = [],
        public readonly bool $malformed = false,
    ) {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('A request path must start with "/".');
        }
        if (!self::isSafeBasePath($basePath)) {
            throw new InvalidArgumentException('Invalid base path.');
        }
    }

    /**
     * @param array<array-key, mixed> $server The server array ($_SERVER shape).
     * @param array<array-key, mixed> $query  The query array ($_GET shape).
     */
    public static function fromServer(array $server, array $query): self
    {
        $method = self::method($server['REQUEST_METHOD'] ?? null);

        $uri = $server['REQUEST_URI'] ?? null;
        $uriPath = is_string($uri) && $uri !== '' ? $uri : '/';
        $queryStart = strpos($uriPath, '?');
        if ($queryStart !== false) {
            $uriPath = substr($uriPath, 0, $queryStart);
        }
        // A fragment is never sent by a browser; treat one as part of the
        // path so that it simply fails to match a route.

        $decoded = rawurldecode($uriPath);
        if (
            !str_starts_with($decoded, '/')
            || strlen($decoded) > self::MAX_PATH_BYTES
            || str_contains($decoded, "\0")
            || preg_match('//u', $decoded) !== 1
        ) {
            return new self($method, '/', '', $query, true);
        }

        $basePath = self::basePath(self::scriptDir($server['SCRIPT_NAME'] ?? null), $decoded);
        $path = substr($decoded, strlen($basePath));
        if ($path === '') {
            $path = '/';
        }

        return new self($method, $path, $basePath, $query, false);
    }

    /**
     * Upper-cased method, or `GET` when absent. A value that is not an HTTP
     * token is replaced by a fixed placeholder, which no route accepts.
     */
    private static function method(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return 'GET';
        }
        if (preg_match('/^[A-Za-z]{1,32}\z/', $value) !== 1) {
            return 'INVALID';
        }

        return strtoupper($value);
    }

    /** Directory part of SCRIPT_NAME, `/`-separated, with no trailing `/`. */
    private static function scriptDir(mixed $scriptName): string
    {
        if (!is_string($scriptName)) {
            return '';
        }
        $normalised = str_replace('\\', '/', $scriptName);
        $slash = strrpos($normalised, '/');
        if ($slash === false) {
            return '';
        }
        $dir = rtrim(substr($normalised, 0, $slash), '/');

        // SCRIPT_NAME comes from the server, but the base path ends up in
        // every generated link, so anything unusual is ignored.
        return self::isSafeBasePath($dir) ? $dir : '';
    }

    private static function basePath(string $scriptDir, string $uriPath): string
    {
        if ($scriptDir === '') {
            return '';
        }
        if (self::isUnder($uriPath, $scriptDir)) {
            return $scriptDir;
        }
        if (str_ends_with($scriptDir, '/public')) {
            $parent = substr($scriptDir, 0, -strlen('/public'));
            if ($parent === '' || self::isUnder($uriPath, $parent)) {
                return $parent;
            }
        }

        return '';
    }

    private static function isUnder(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /**
     * Empty, or one or more `/segment` parts with no empty, `.` or `..`
     * segment, no back-slash, and no control character.
     */
    private static function isSafeBasePath(string $basePath): bool
    {
        if ($basePath === '') {
            return true;
        }
        if (preg_match('#^(/[^/\\\\\x00-\x1F\x7F]+)+\z#', $basePath) !== 1 || preg_match('//u', $basePath) !== 1) {
            return false;
        }
        foreach (explode('/', substr($basePath, 1)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
