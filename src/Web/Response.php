<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * An immutable HTTP response: status, headers, cookies, and body. send() is
 * the only code in the application that calls header(), apart from the
 * last-resort Fallback.
 *
 * Cookies are kept apart from the headers and added only through
 * withCookie(); a `Set-Cookie` header cannot be set with withHeader().
 *
 * public/index.php also loads this file directly (without the autoloader)
 * to report missing dependencies, so it must not need other classes to be
 * loaded: Cookie is only named in type checks and `instanceof`, which load
 * nothing.
 */
final class Response
{
    /** @var array<string, string> Header name to value; names unique ignoring case. */
    public readonly array $headers;

    /** @var list<Cookie> In the order added; names unique. */
    public readonly array $cookies;

    /**
     * @param array<string, string> $headers
     * @param list<Cookie>          $cookies
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
        array $cookies = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        $checked = [];
        foreach ($headers as $name => $value) {
            $checked = self::put($checked, (string) $name, $value);
        }
        $this->headers = $checked;

        $list = [];
        foreach ($cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                throw new InvalidArgumentException('Cookies must be Cookie objects.');
            }
            $list = self::putCookie($list, $cookie);
        }
        $this->cookies = $list;
    }

    /**
     * A 303 See Other to an application address built with UrlGenerator::to
     * (path-absolute, never `//`, no back-slash or control character).
     */
    public static function redirect(string $location): self
    {
        if (
            !str_starts_with($location, '/')
            || str_starts_with($location, '//')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
        ) {
            throw new InvalidArgumentException('Invalid redirect location.');
        }

        return new self(303, ['Location' => $location]);
    }

    /** A copy with the header set, replacing any header of the same name (ignoring case). */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, self::put($this->headers, $name, $value), $this->body, $this->cookies);
    }

    /** A copy with the cookie added; a cookie of the same name added earlier is replaced. */
    public function withCookie(Cookie $cookie): self
    {
        return new self($this->status, $this->headers, $this->body, self::putCookie($this->cookies, $cookie));
    }

    /** The cookie of that name, or null. */
    public function cookie(string $name): ?Cookie
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie->name === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** The value of a header, looked up ignoring case, or null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Emits status, headers, and (unless the request was HEAD) the body.
     *
     * $emit is a test seam: when given, it receives each step instead of
     * PHP's header functions, as `('remove', header name)`, `('status',
     * code)`, and `('header', full header line)`. The command-line SAPI
     * that runs the tests does not record headers, so this is the only way
     * to observe them in process. Production code never passes it.
     * Each cookie is one more `('cookie', 'Set-Cookie: ...')` step, sent
     * with header($line, false) so that cookies do not replace each other.
     *
     * @param (callable(string, string|int): void)|null $emit
     */
    public function send(bool $headRequest = false, ?callable $emit = null): void
    {
        $emit ??= static function (string $step, string|int $value): void {
            match ($step) {
                'remove' => header_remove((string) $value),
                'status' => http_response_code((int) $value),
                'header' => header((string) $value, true),
                'cookie' => header((string) $value, false),
            };
        };

        $emit('remove', 'X-Powered-By');
        $emit('status', $this->status);
        foreach ($this->headers as $name => $value) {
            $emit('header', $name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            $emit('cookie', 'Set-Cookie: ' . $cookie->header());
        }
        if (!$headRequest) {
            echo $this->body;
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function put(array $headers, string $name, mixed $value): array
    {
        // Header names are RFC 9110 tokens; values may not break the line.
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid header name.');
        }
        if (strcasecmp($name, 'Set-Cookie') === 0) {
            throw new InvalidArgumentException('Cookies are added with withCookie().');
        }
        if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid header value.');
        }
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;

        return $headers;
    }

    /**
     * @param list<Cookie> $cookies
     * @return list<Cookie>
     */
    private static function putCookie(array $cookies, Cookie $cookie): array
    {
        $kept = [];
        foreach ($cookies as $existing) {
            if ($existing->name !== $cookie->name) {
                $kept[] = $existing;
            }
        }
        $kept[] = $cookie;

        return $kept;
    }
}
