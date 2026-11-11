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
use TiffinDB\Web\Request;

/** T-WEB-2: path and base-path detection, decoding, malformed input. */
#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    /** @return array<string, array{string, string, string, string, bool}> */
    public static function basePathCases(): array
    {
        // M0 spec section 15.2 table, then further robustness cases.
        return [
            'root' => ['/index.php', '/', '', '/', false],
            'query stripped' => ['/index.php', '/projects?x=1', '', '/projects', false],
            'subdirectory root' => ['/tiffindb/index.php', '/tiffindb/', '/tiffindb', '/', false],
            'subdirectory path' => ['/tiffindb/index.php', '/tiffindb/projects', '/tiffindb', '/projects', false],
            'public in URL' => ['/tiffindb/public/index.php', '/tiffindb/public/projects', '/tiffindb/public', '/projects', false],
            'root guard' => ['/tiffindb/public/index.php', '/tiffindb/projects', '/tiffindb', '/projects', false],
            'root guard bare' => ['/tiffindb/public/index.php', '/tiffindb', '/tiffindb', '/', false],
            'root guard at top' => ['/public/index.php', '/projects', '', '/projects', false],
            'public at top in URL' => ['/public/index.php', '/public/projects', '/public', '/projects', false],
            'outside base' => ['/tiffindb/index.php', '/elsewhere', '', '/elsewhere', false],
            'encoded slash' => ['/index.php', '/a%2Fb', '', '/a/b', false],
            'NUL byte' => ['/index.php', '/a%00b', '', '/', true],
            'invalid UTF-8' => ['/index.php', '/%FF', '', '/', true],
            // Further cases.
            'prefix is not a segment' => ['/tiffindb/index.php', '/tiffindbx/projects', '', '/tiffindbx/projects', false],
            'decoded once only' => ['/index.php', '/a%2541', '', '/a%41', false],
            'valid UTF-8' => ['/index.php', '/caf%C3%A9', '', '/café', false],
            'overlong UTF-8' => ['/index.php', '/%C0%AF', '', '/', true],
            'raw NUL' => ['/index.php', "/a\0b", '', '/', true],
            'double slash kept' => ['/index.php', '//projects', '', '//projects', false],
            'dot segments kept' => ['/index.php', '/a/../b', '', '/a/../b', false],
            'encoded dot segments kept' => ['/index.php', '/%2e%2e/%2e%2e/etc', '', '/../../etc', false],
            'path info kept' => ['/index.php', '/index.php/extra', '', '/index.php/extra', false],
            'absolute form' => ['/index.php', 'http://example.com/', '', '/', true],
            'no leading slash' => ['/index.php', 'projects', '', '/', true],
            'back-slash script name' => ['\\tiffindb\\index.php', '/tiffindb/x', '/tiffindb', '/x', false],
            'unsafe script dir ignored' => ['//example.com/index.php', '//example.com/x', '', '//example.com/x', false],
            'dot script dir ignored' => ['/../index.php', '/../x', '', '/../x', false],
            'script name without slash' => ['index.php', '/x', '', '/x', false],
        ];
    }

    #[DataProvider('basePathCases')]
    public function testBasePathAndPath(string $scriptName, string $uri, string $basePath, string $path, bool $malformed): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => $scriptName], []);

        self::assertSame($basePath, $request->basePath);
        self::assertSame($path, $request->path);
        self::assertSame($malformed, $request->malformed);
    }

    public function testOverlongPathIsMalformed(): void
    {
        $long = '/' . str_repeat('a', Request::MAX_PATH_BYTES);
        $request = Request::fromServer(['REQUEST_URI' => $long, 'SCRIPT_NAME' => '/index.php'], []);
        self::assertTrue($request->malformed);
        self::assertSame('/', $request->path);

        $limit = '/' . str_repeat('a', Request::MAX_PATH_BYTES - 1);
        self::assertFalse(Request::fromServer(['REQUEST_URI' => $limit, 'SCRIPT_NAME' => '/index.php'], [])->malformed);
    }

    public function testMissingServerValuesGiveDefaults(): void
    {
        $request = Request::fromServer([], []);

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
        self::assertSame('', $request->basePath);
        self::assertFalse($request->malformed);
    }

    public function testNonStringServerValuesAreIgnored(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => ['GET'], 'REQUEST_URI' => 42, 'SCRIPT_NAME' => null], []);

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
    }

    /** @return array<string, array{mixed, string}> */
    public static function methodCases(): array
    {
        return [
            'upper' => ['GET', 'GET'],
            'lower' => ['head', 'HEAD'],
            'other' => ['post', 'POST'],
            'not a token' => ["GET\r\nX-Evil: 1", 'INVALID'],
            'too long' => [str_repeat('A', 33), 'INVALID'],
            'empty' => ['', 'GET'],
        ];
    }

    #[DataProvider('methodCases')]
    public function testMethodIsNormalised(mixed $method, string $expected): void
    {
        self::assertSame($expected, Request::fromServer(['REQUEST_METHOD' => $method], [])->method);
    }

    public function testQueryIsKeptAsGiven(): void
    {
        $query = ['theme' => 'dark', 'list' => ['a', 'b']];
        self::assertSame($query, Request::fromServer(['REQUEST_URI' => '/?theme=dark'], $query)->query);
    }

    public function testConstructorRejectsInvalidValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', 'relative');
    }

    public function testConstructorRejectsInvalidBasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', '/', '/trailing/');
    }
}
