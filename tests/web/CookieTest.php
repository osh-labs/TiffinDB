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
use TiffinDB\Web\Cookie;

/** T-WEB-14: cookie header text and validation (M1 spec part 2 sections 2.2 and 2.6). */
#[CoversClass(Cookie::class)]
final class CookieTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ-_01234';
    private const CSRF = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testSessionCookieHeader(): void
    {
        $cookie = new Cookie('tdb_session', self::TOKEN, '/', true, 'Lax', 2592000);

        self::assertSame(
            'tdb_session=' . self::TOKEN . '; Path=/; Max-Age=2592000; Secure; HttpOnly; SameSite=Lax',
            $cookie->header(),
        );
    }

    public function testCsrfCookieHeader(): void
    {
        $cookie = new Cookie('tdb_csrf', self::CSRF, '/my%20dir/', false, 'Strict', null);

        self::assertSame('tdb_csrf=' . self::CSRF . '; Path=/my%20dir/; HttpOnly; SameSite=Strict', $cookie->header());
    }

    public function testDeletingCookie(): void
    {
        $cookie = Cookie::deleting('tdb_session', '/sub/', true, 'Lax');

        self::assertSame('', $cookie->value);
        self::assertSame(0, $cookie->maxAge);
        self::assertSame('tdb_session=; Path=/sub/; Max-Age=0; Secure; HttpOnly; SameSite=Lax', $cookie->header());
    }

    public function testEveryCookieIsHttpOnly(): void
    {
        foreach ([[true, 'Lax', 1], [false, 'Strict', null], [false, 'Lax', 0]] as [$secure, $sameSite, $maxAge]) {
            $cookie = new Cookie('x', $maxAge === 0 ? '' : 'v', '/', $secure, $sameSite, $maxAge);
            self::assertStringContainsString('; HttpOnly;', $cookie->header());
        }
    }

    public function testDebugOutputHidesTheValue(): void
    {
        $dump = print_r(new Cookie('tdb_session', self::TOKEN, '/', true, 'Lax', 1), true);

        self::assertStringNotContainsString(self::TOKEN, $dump);
    }

    /** @return array<string, array{string, string, string, string, int|null}> */
    public static function invalidCookies(): array
    {
        $ok = 'value';

        return [
            'empty name' => ['', $ok, '/', 'Lax', null],
            'upper-case name' => ['TDB', $ok, '/', 'Lax', null],
            'name with hyphen' => ['tdb-session', $ok, '/', 'Lax', null],
            'name with equals' => ['a=b', $ok, '/', 'Lax', null],
            'name too long' => [str_repeat('a', 33), $ok, '/', 'Lax', null],
            'name with newline' => ["tdb\n", $ok, '/', 'Lax', null],
            'value with semicolon' => ['a', 'x; Path=/evil', '/', 'Lax', null],
            'value with space' => ['a', 'x y', '/', 'Lax', null],
            'value with comma' => ['a', 'x,y', '/', 'Lax', null],
            'value with quote' => ['a', 'x"', '/', 'Lax', null],
            'value with CRLF' => ['a', "x\r\nSet-Cookie: y=z", '/', 'Lax', null],
            'value with equals' => ['a', 'x=y', '/', 'Lax', null],
            'value with trailing newline' => ['a', "x\n", '/', 'Lax', null],
            'value too long' => ['a', str_repeat('v', 129), '/', 'Lax', null],
            'empty path' => ['a', $ok, '', 'Lax', null],
            'path without trailing slash' => ['a', $ok, '/sub', 'Lax', null],
            'path with double slash' => ['a', $ok, '//', 'Lax', null],
            'path protocol-relative' => ['a', $ok, '//evil.example/', 'Lax', null],
            'path with semicolon' => ['a', $ok, '/a;Domain=evil/', 'Lax', null],
            'path with space' => ['a', $ok, '/my dir/', 'Lax', null],
            'path with dot-dot' => ['a', $ok, '/../', 'Lax', null],
            'path with dot' => ['a', $ok, '/a/./', 'Lax', null],
            'path with back-slash' => ['a', $ok, '/a\\b/', 'Lax', null],
            'path with newline' => ['a', $ok, "/\n", 'Lax', null],
            'relative path' => ['a', $ok, 'sub/', 'Lax', null],
            'SameSite None' => ['a', $ok, '/', 'None', null],
            'SameSite lower case' => ['a', $ok, '/', 'lax', null],
            'negative lifetime' => ['a', $ok, '/', 'Lax', -1],
            'deleting with a value' => ['a', $ok, '/', 'Lax', 0],
        ];
    }

    #[DataProvider('invalidCookies')]
    public function testInvalidCookiesAreRefused(string $name, string $value, string $path, string $sameSite, ?int $maxAge): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cookie($name, $value, $path, true, $sameSite, $maxAge);
    }

    public function testLongestValueIsAccepted(): void
    {
        self::assertSame(128, strlen((new Cookie('a', str_repeat('v', 128), '/', true, 'Lax', null))->value));
    }
}
