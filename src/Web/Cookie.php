<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * One Set-Cookie line (M1 spec part 2 sections 2.2 and 2.6). Every cookie
 * is HttpOnly. Names, values, and paths are restricted to characters that
 * need no quoting, so a header line can never be broken or extended.
 *
 * public/index.php may load Response.php before the autoloader; Response
 * only checks `instanceof Cookie`, which loads nothing, so this class is
 * needed only once a cookie is actually made.
 */
final class Cookie
{
    public const MAX_VALUE_LENGTH = 128;

    private const NAME = '/^[a-z_]{1,32}\z/';
    private const VALUE = '/^[A-Za-z0-9_-]*\z/';

    /**
     * `/`, or a percent-encoded base path followed by `/` (UrlGenerator::to('/')
     * gives this form). Segments use unreserved characters and `%` only.
     */
    private const PATH = '#^(/[A-Za-z0-9._~%-]+)*/\z#';

    private const SAME_SITE = ['Lax', 'Strict'];

    /**
     * @param string   $name     `^[a-z_]{1,32}$`
     * @param string   $value    `^[A-Za-z0-9_-]*$`, at most 128 characters; empty when $maxAge is 0
     * @param string   $path     the encoded base path followed by `/`
     * @param bool     $secure   adds the Secure attribute
     * @param string   $sameSite `Lax` or `Strict`
     * @param int|null $maxAge   seconds; null for a browser-session cookie; 0 deletes
     */
    public function __construct(
        public readonly string $name,
        #[\SensitiveParameter] public readonly string $value,
        public readonly string $path,
        public readonly bool $secure,
        public readonly string $sameSite,
        public readonly ?int $maxAge,
    ) {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException('Invalid cookie name.');
        }
        if (strlen($value) > self::MAX_VALUE_LENGTH || preg_match(self::VALUE, $value) !== 1) {
            throw new InvalidArgumentException('Invalid cookie value.');
        }
        if (preg_match(self::PATH, $path) !== 1 || self::hasDotSegment($path)) {
            throw new InvalidArgumentException('Invalid cookie path.');
        }
        if (!in_array($sameSite, self::SAME_SITE, true)) {
            throw new InvalidArgumentException('Invalid SameSite value.');
        }
        if ($maxAge !== null && $maxAge < 0) {
            throw new InvalidArgumentException('Invalid cookie lifetime.');
        }
        if ($maxAge === 0 && $value !== '') {
            throw new InvalidArgumentException('A deleting cookie has an empty value.');
        }
    }

    /** A cookie that deletes the named one (same path and attributes, Max-Age=0). */
    public static function deleting(string $name, string $path, bool $secure, string $sameSite): self
    {
        return new self($name, '', $path, $secure, $sameSite, 0);
    }

    /** The Set-Cookie value: `name=value; Path=/; Max-Age=N; Secure; HttpOnly; SameSite=Lax`. */
    public function header(): string
    {
        $parts = [$this->name . '=' . $this->value, 'Path=' . $this->path];
        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        $parts[] = 'HttpOnly';
        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }

    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'value' => '(hidden)', 'path' => $this->path];
    }

    private static function hasDotSegment(string $path): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }
}
