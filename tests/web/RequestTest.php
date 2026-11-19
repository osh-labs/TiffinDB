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

/** T-WEB-2, T-WEB-13: path and base-path detection, decoding, malformed input; POST, cookies, and server values. */
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
            'script dir with trailing newline ignored' => ["/a\n/index.php", "/a\n/x", '', "/a\n/x", false],
            'script dir with space' => ['/my app/index.php', '/my%20app/projects', '/my app', '/projects', false],
            'script dir with percent and query mark' => ['/100%/a?b/index.php', '/100%25/a%3Fb/x', '/100%/a?b', '/x', false],
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
            'trailing newline' => ["GET\n", 'INVALID'],
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

    public function testConstructorRejectsBasePathWithTrailingNewline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', '/', "/a\n");
    }

    // ------------------------------------------------- fromGlobals (T-WEB-13)

    /** @param array<string, mixed> $server */
    private static function globals(array $server = [], array $get = [], array $post = [], array $cookies = []): Request
    {
        return Request::fromGlobals(
            $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/sign-in?x=1', 'SCRIPT_NAME' => '/index.php'],
            $get,
            $post,
            $cookies,
        );
    }

    public function testFromGlobalsKeepsPathQueryPostAndCookies(): void
    {
        $post = ['username' => 'example', 'list' => ['a', 1, 'b'], 'nested' => ['x' => ['y']]];
        $cookies = ['tdb_session' => 'abc', 'odd' => ['x']];
        $request = self::globals([], ['x' => '1'], $post, $cookies);

        self::assertSame('POST', $request->method);
        self::assertSame('/sign-in', $request->path);
        self::assertSame(['x' => '1'], $request->query);
        self::assertSame($post, $request->post);
        self::assertSame($cookies, $request->cookies);
        self::assertTrue($request->isPost());
        self::assertFalse(self::globals(['REQUEST_METHOD' => 'GET'])->isPost());
    }

    public function testFromServerKeepsTheM0Form(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.1'], []);

        self::assertSame([], $request->post);
        self::assertSame([], $request->cookies);
        self::assertFalse($request->secure);
        self::assertSame('', $request->clientAddress);
    }

    public function testDefaultsWhenServerValuesAreAbsent(): void
    {
        $request = Request::fromGlobals([], [], [], []);

        self::assertFalse($request->secure);
        self::assertSame('', $request->clientAddress);
        self::assertNull($request->secFetchSite);
        self::assertNull($request->host);
        self::assertNull($request->serverAddress);
        self::assertNull($request->serverPort);
        self::assertNull($request->documentRoot);
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function secureCases(): array
    {
        return [
            'HTTPS on' => [['HTTPS' => 'on'], true],
            'HTTPS 1' => [['HTTPS' => '1'], true],
            'HTTPS off' => [['HTTPS' => 'off'], false],
            'HTTPS OFF' => [['HTTPS' => 'OFF'], false],
            'HTTPS Off' => [['HTTPS' => 'Off'], false],
            'HTTPS empty' => [['HTTPS' => ''], false],
            'HTTPS not a string' => [['HTTPS' => true], false],
            'scheme https' => [['REQUEST_SCHEME' => 'https'], true],
            'scheme HTTPS' => [['REQUEST_SCHEME' => 'HTTPS'], true],
            'scheme http' => [['REQUEST_SCHEME' => 'http'], false],
            'HTTPS off but scheme https' => [['HTTPS' => 'off', 'REQUEST_SCHEME' => 'https'], true],
            'forwarded proto ignored' => [['HTTP_X_FORWARDED_PROTO' => 'https'], false],
            'forwarded ssl ignored' => [['HTTP_X_FORWARDED_SSL' => 'on'], false],
            'forwarded header ignored' => [['HTTP_FORWARDED' => 'proto=https'], false],
            'nothing' => [[], false],
        ];
    }

    /** @param array<string, mixed> $server */
    #[DataProvider('secureCases')]
    public function testSecure(array $server, bool $secure): void
    {
        self::assertSame($secure, self::globals($server)->secure);
    }

    /** @return array<string, array{mixed, string}> */
    public static function clientAddressCases(): array
    {
        return [
            'IPv4' => ['192.0.2.10', '192.0.2.10'],
            'IPv6' => ['2001:db8::1', '2001:db8::1'],
            'unparseable kept' => ['unknown', 'unknown'],
            '64 characters' => [str_repeat('a', 64), str_repeat('a', 64)],
            '65 characters' => [str_repeat('a', 65), ''],
            'not a string' => [['192.0.2.1'], ''],
            'missing' => [null, ''],
        ];
    }

    #[DataProvider('clientAddressCases')]
    public function testClientAddress(mixed $value, string $expected): void
    {
        $server = $value === null ? [] : ['REMOTE_ADDR' => $value];
        self::assertSame($expected, self::globals($server)->clientAddress);
    }

    public function testForwardedForIsIgnored(): void
    {
        $request = self::globals(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_REAL_IP' => '198.51.100.8']);

        self::assertSame('192.0.2.10', $request->clientAddress);
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function fetchSiteCases(): array
    {
        return [
            'cross-site' => ['cross-site', 'cross-site'],
            'same-site' => ['same-site', 'same-site'],
            'same-origin' => ['same-origin', 'same-origin'],
            'none' => ['none', 'none'],
            'upper case' => ['Cross-Site', null],
            'trailing newline' => ["cross-site\n", null],
            'other' => ['evil', null],
            'not a string' => [['cross-site'], null],
        ];
    }

    #[DataProvider('fetchSiteCases')]
    public function testSecFetchSite(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, self::globals(['HTTP_SEC_FETCH_SITE' => $value])->secFetchSite);
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function originCases(): array
    {
        $longest = 'https://' . str_repeat('a', Request::MAX_ORIGIN - 8);

        return [
            'absent' => [null, null],
            'origin' => ['https://example.test', 'https://example.test'],
            'with port' => ['http://example.test:8728', 'http://example.test:8728'],
            'null' => ['null', 'null'],
            'empty is kept' => ['', ''],
            'space is printable' => ['http://a b', 'http://a b'],
            'longest' => [$longest, $longest],
            'too long' => [$longest . 'a', null],
            'newline' => ["https://example.test\n", null],
            'tab' => ["https://example.test\t", null],
            'NUL' => ["https://example.test\0", null],
            'DEL' => ["https://example.test\x7F", null],
            'non-ASCII' => ["https://\xC3\xA9.example", null],
            'not a string' => [['https://example.test'], null],
        ];
    }

    #[DataProvider('originCases')]
    public function testOrigin(mixed $value, ?string $expected): void
    {
        $server = $value === null ? [] : ['HTTP_ORIGIN' => $value];
        self::assertSame($expected, self::globals($server)->origin);
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function hostCases(): array
    {
        return [
            'name' => ['example.com', 'example.com'],
            'name and port' => ['example.com:8080', 'example.com:8080'],
            'IPv4' => ['192.0.2.1', '192.0.2.1'],
            'IPv6' => ['[2001:db8::1]', '[2001:db8::1]'],
            'IPv6 and port' => ['[2001:db8::1]:443', '[2001:db8::1]:443'],
            'userinfo' => ['user@example.com', null],
            'path' => ['example.com/x', null],
            'space' => ['example .com', null],
            'six-digit port' => ['example.com:123456', null],
            'empty port' => ['example.com:', null],
            'trailing newline' => ["example.com\n", null],
            'CRLF' => ["example.com\r\nX: y", null],
            'quote' => ['example.com"', null],
            'underscore' => ['ex_ample.com', null],
            'unclosed bracket' => ['[2001:db8::1', null],
            'letters in brackets' => ['[example]', null],
            'empty' => ['', null],
            'not a string' => [['example.com'], null],
        ];
    }

    #[DataProvider('hostCases')]
    public function testHost(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, self::globals(['HTTP_HOST' => $value])->host);
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function serverAddressCases(): array
    {
        return [
            'IPv4' => ['192.0.2.1', '192.0.2.1'],
            'IPv6' => ['2001:db8::1', '2001:db8::1'],
            'loopback' => ['127.0.0.1', '127.0.0.1'],
            'name' => ['localhost', null],
            'bracketed' => ['[::1]', null],
            'trailing newline' => ["192.0.2.1\n", null],
            'with port' => ['192.0.2.1:80', null],
            'empty' => ['', null],
            'not a string' => [123, null],
        ];
    }

    #[DataProvider('serverAddressCases')]
    public function testServerAddress(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, self::globals(['SERVER_ADDR' => $value])->serverAddress);
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function serverPortCases(): array
    {
        return [
            '80' => ['80', 80],
            '1' => ['1', 1],
            '65535' => ['65535', 65535],
            'integer' => [8080, 8080],
            '0' => ['0', null],
            '65536' => ['65536', null],
            'negative' => ['-1', null],
            'sign' => ['+80', null],
            'six digits' => ['000080', null],
            'space' => [' 80', null],
            'trailing newline' => ["80\n", null],
            'letters' => ['http', null],
            'empty' => ['', null],
            'float' => [80.0, null],
        ];
    }

    #[DataProvider('serverPortCases')]
    public function testServerPort(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, self::globals(['SERVER_PORT' => $value])->serverPort);
    }

    public function testDocumentRootIsResolved(): void
    {
        $dir = sys_get_temp_dir();
        $real = realpath($dir);

        self::assertSame($real, self::globals(['DOCUMENT_ROOT' => $dir . '/.'])->documentRoot);
        self::assertNull(self::globals(['DOCUMENT_ROOT' => $dir . '/tiffindb-no-such-' . bin2hex(random_bytes(4))])->documentRoot);
        self::assertNull(self::globals(['DOCUMENT_ROOT' => "{$dir}\0x"])->documentRoot);
        self::assertNull(self::globals(['DOCUMENT_ROOT' => ''])->documentRoot);
        self::assertNull(self::globals(['DOCUMENT_ROOT' => ['x']])->documentRoot);
    }

    public function testInputReturnsStringsOnly(): void
    {
        $request = self::globals([], [], ['name' => '  Example  ', 'list' => ['a'], 'number' => 5, 'empty' => '']);

        self::assertSame('  Example  ', $request->input('name'), 'Never trimmed.');
        self::assertSame('', $request->input('list'));
        self::assertSame('', $request->input('number'));
        self::assertSame('', $request->input('empty'));
        self::assertSame('', $request->input('missing'));
    }

    public function testInputListKeepsStringElementsOnly(): void
    {
        $request = self::globals([], [], [
            'people' => ['a', 1, ['nested'], 'b', null, true],
            'keyed' => ['x' => 'one', 'y' => 'two'],
            'single' => 'a',
        ]);

        self::assertSame(['a', 'b'], $request->inputList('people'));
        self::assertSame(['one', 'two'], $request->inputList('keyed'));
        self::assertSame([], $request->inputList('single'));
        self::assertSame([], $request->inputList('missing'));
    }

    public function testCookieReturnsStringsOnly(): void
    {
        $request = self::globals([], [], [], ['tdb_session' => 'abc', 'tdb_csrf' => ['x'], 'n' => 3]);

        self::assertSame('abc', $request->cookie('tdb_session'));
        self::assertNull($request->cookie('tdb_csrf'));
        self::assertNull($request->cookie('n'));
        self::assertNull($request->cookie('missing'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidConstructorValues(): array
    {
        return [
            'fetch site' => [['secFetchSite' => 'evil']],
            'host' => [['host' => "example.com\n"]],
            'server address' => [['serverAddress' => 'localhost']],
            'port zero' => [['serverPort' => 0]],
            'port too large' => [['serverPort' => 65536]],
            'client address too long' => [['clientAddress' => str_repeat('a', 65)]],
            'origin with a newline' => [['origin' => "https://example.test\n"]],
            'origin too long' => [['origin' => str_repeat('a', Request::MAX_ORIGIN + 1)]],
        ];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidConstructorValues')]
    public function testConstructorRejectsInvalidServerValues(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', '/', ...$values);
    }

    public function testConstructorAcceptsNamedTestValues(): void
    {
        $request = new Request('POST', '/sign-in', post: ['_csrf' => 'x'], cookies: ['tdb_csrf' => 'y'], secure: true, secFetchSite: 'same-origin');

        self::assertSame('x', $request->input('_csrf'));
        self::assertSame('y', $request->cookie('tdb_csrf'));
        self::assertTrue($request->secure);
        self::assertSame('same-origin', $request->secFetchSite);
    }

    public function testConstructorAcceptsAnOrigin(): void
    {
        self::assertSame('https://example.test', (new Request('POST', '/', origin: 'https://example.test'))->origin);
        self::assertNull((new Request('POST', '/'))->origin);
    }
}
