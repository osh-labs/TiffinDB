<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * An immutable HTTP request (M0 spec section 6.2; M1 spec part 2 section
 * 2.1): the method, the route path, the detected base path, the query and
 * POST arrays, the cookies, and the few server values the pipeline needs.
 *
 * Built by fromGlobals(), which is only ever called from public/index.php;
 * tests use the constructor, fromServer(), or fromGlobals() with synthetic
 * arrays. Nothing here reads a superglobal. Forwarding headers
 * (X-Forwarded-*, Forwarded) are never read. The Origin header is kept as
 * sent, for the kernel's origin check (T-CF-1).
 */
final class Request
{
    /** Longest request path accepted, in bytes; longer ones are malformed. */
    public const MAX_PATH_BYTES = 2048;

    /** Values of Sec-Fetch-Site the request keeps; anything else becomes null. */
    public const FETCH_SITES = ['cross-site', 'same-site', 'same-origin', 'none'];

    /** Longest Origin header kept, in characters (T-CF-1). */
    public const MAX_ORIGIN = 2048;

    /** Longest REMOTE_ADDR kept, in characters. */
    public const MAX_CLIENT_ADDRESS = 64;

    private const HOST_NAME = '/^[A-Za-z0-9.-]+(:\d{1,5})?\z/';
    private const HOST_IPV6 = '/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?\z/';

    /**
     * @param array<array-key, mixed> $query   The query array as given; values
     *                                         may be strings or arrays.
     * @param array<array-key, mixed> $post    The POST array as given.
     * @param array<array-key, mixed> $cookies The cookie array as given.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath = '',
        public readonly array $query = [],
        public readonly bool $malformed = false,
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly bool $secure = false,
        public readonly string $clientAddress = '',
        public readonly ?string $secFetchSite = null,
        public readonly ?string $host = null,
        public readonly ?string $serverAddress = null,
        public readonly ?int $serverPort = null,
        public readonly ?string $documentRoot = null,
        public readonly ?string $origin = null,
    ) {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('A request path must start with "/".');
        }
        if (!self::isSafeBasePath($basePath)) {
            throw new InvalidArgumentException('Invalid base path.');
        }
        if ($secFetchSite !== null && !in_array($secFetchSite, self::FETCH_SITES, true)) {
            throw new InvalidArgumentException('Invalid Sec-Fetch-Site value.');
        }
        if ($host !== null && self::host($host) === null) {
            throw new InvalidArgumentException('Invalid host.');
        }
        if ($serverAddress !== null && filter_var($serverAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Invalid server address.');
        }
        if ($serverPort !== null && ($serverPort < 1 || $serverPort > 65535)) {
            throw new InvalidArgumentException('Invalid server port.');
        }
        if (strlen($clientAddress) > self::MAX_CLIENT_ADDRESS) {
            throw new InvalidArgumentException('Invalid client address.');
        }
        if ($origin !== null && self::origin($origin) === null) {
            throw new InvalidArgumentException('Invalid origin.');
        }
    }

    /**
     * The request as PHP presents it. Called only from public/index.php.
     *
     * @param array<array-key, mixed> $server  The server array ($_SERVER shape).
     * @param array<array-key, mixed> $get     The query array ($_GET shape).
     * @param array<array-key, mixed> $post    The POST array ($_POST shape).
     * @param array<array-key, mixed> $cookies The cookie array ($_COOKIE shape).
     */
    public static function fromGlobals(array $server, array $get, array $post, array $cookies): self
    {
        $base = self::fromServer($server, $get);

        return new self(
            $base->method,
            $base->path,
            $base->basePath,
            $get,
            $base->malformed,
            $post,
            $cookies,
            self::secure($server),
            self::clientAddress($server['REMOTE_ADDR'] ?? null),
            self::fetchSite($server['HTTP_SEC_FETCH_SITE'] ?? null),
            self::host($server['HTTP_HOST'] ?? null),
            self::serverAddress($server['SERVER_ADDR'] ?? null),
            self::serverPort($server['SERVER_PORT'] ?? null),
            self::documentRoot($server['DOCUMENT_ROOT'] ?? null),
            self::origin($server['HTTP_ORIGIN'] ?? null),
        );
    }

    /**
     * The method, path, base path, and query only (the M0 form); every other
     * property takes its default.
     *
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

    /** The POST value when it is a string, else ''. Never trimmed. */
    public function input(string $name): string
    {
        $value = $this->post[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The string elements of a POST list, in order; [] when the value is
     * not an array.
     *
     * @return list<string>
     */
    public function inputList(string $name): array
    {
        $value = $this->post[$name] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /** A cookie value when it is a string, else null. */
    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * HTTPS is set and not `off` (ASCII case-insensitive), or REQUEST_SCHEME
     * is `https`.
     *
     * @param array<array-key, mixed> $server
     */
    private static function secure(array $server): bool
    {
        $https = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strcasecmp($https, 'off') !== 0) {
            return true;
        }
        $scheme = $server['REQUEST_SCHEME'] ?? null;

        return is_string($scheme) && strcasecmp($scheme, 'https') === 0;
    }

    private static function clientAddress(mixed $value): string
    {
        return is_string($value) && strlen($value) <= self::MAX_CLIENT_ADDRESS ? $value : '';
    }

    private static function fetchSite(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::FETCH_SITES, true) ? $value : null;
    }

    private static function host(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return preg_match(self::HOST_NAME, $value) === 1 || preg_match(self::HOST_IPV6, $value) === 1 ? $value : null;
    }

    /**
     * The Origin header as sent (T-CF-1): at most MAX_ORIGIN printable
     * ASCII characters (U+0020 to U+007E), else null. An empty value is
     * kept, so that the kernel refuses it as a foreign origin.
     */
    private static function origin(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[\x20-\x7E]{0,' . self::MAX_ORIGIN . '}\z/', $value) === 1 ? $value : null;
    }

    private static function serverAddress(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private static function serverPort(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,5}\z/', $value) !== 1) {
            return null;
        }
        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private static function documentRoot(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            return null;
        }
        $real = realpath($value);

        return $real === false ? null : $real;
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
