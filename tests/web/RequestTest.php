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
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\UploadLimits;

/**
 * T-WEB-2, T-WEB-13: path and base-path detection, decoding, malformed input; POST, cookies, and server values.
 * T-WEB-40 (M3 spec part 2 section 2.1): uploaded files, the declared length, and the upload limits.
 */
#[CoversClass(Request::class)]
#[CoversClass(UploadedFile::class)]
#[CoversClass(UploadLimits::class)]
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

    // T-WEB-40: uploads (M3 spec part 2 section 2.1).

    /** A temporary file standing in for one PHP received; removed in tearDown(). */
    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null && is_file($this->temp)) {
            unlink($this->temp);
        }
        $this->temp = null;
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tiffindb-upload-');
        self::assertIsString($path);
        file_put_contents($path, "name,room\nExample lamp,Hall\n");
        $this->temp = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private static function entry(string $name, string $tmp, int|string $size = 28, int|string $error = UPLOAD_ERR_OK): array
    {
        return ['name' => $name, 'type' => 'text/csv', 'tmp_name' => $tmp, 'size' => $size, 'error' => $error, 'full_path' => $name];
    }

    public function testWellFormedSingleFilesAreBuilt(): void
    {
        $tmp = $this->tempFile();
        $files = Request::uploadedFiles(
            ['file' => self::entry('inventory.csv', $tmp), 'other_file' => self::entry('b.csv', $tmp, '12', '0')],
            static fn (string $path): bool => $path === $tmp,
        );

        self::assertSame(['file', 'other_file'], array_keys($files));
        $file = $files['file'];
        self::assertSame('file', $file->field);
        self::assertSame('inventory.csv', $file->clientName);
        self::assertSame(28, $file->size);
        self::assertSame(UPLOAD_ERR_OK, $file->error);
        self::assertSame($tmp, $file->tmpPath);
        self::assertNull($file->problem());
        self::assertSame(12, $files['other_file']->size);
    }

    /** @return iterable<string, array{array-key, mixed}> */
    public static function droppedEntries(): iterable
    {
        $ok = ['name' => 'a.csv', 'type' => 'text/csv', 'tmp_name' => '/tmp/phpUPLOAD', 'size' => 1, 'error' => 0];
        yield 'array-shaped entry (name="file[]")' => ['file', ['name' => ['a.csv'], 'type' => ['text/csv'], 'tmp_name' => ['/tmp/phpUPLOAD'], 'size' => [1], 'error' => [0]]];
        yield 'nested array name' => ['file', ['name' => ['x' => 'a.csv']] + $ok];
        yield 'array tmp_name' => ['file', ['tmp_name' => ['/tmp/phpUPLOAD']] + $ok];
        yield 'array size' => ['file', ['size' => [1]] + $ok];
        yield 'array error' => ['file', ['error' => [0]] + $ok];
        yield 'missing name' => ['file', array_diff_key($ok, ['name' => true])];
        yield 'missing tmp_name' => ['file', array_diff_key($ok, ['tmp_name' => true])];
        yield 'missing size' => ['file', array_diff_key($ok, ['size' => true])];
        yield 'missing error' => ['file', array_diff_key($ok, ['error' => true])];
        yield 'negative size' => ['file', ['size' => -1] + $ok];
        yield 'size as text' => ['file', ['size' => '1kB'] + $ok];
        yield 'float size' => ['file', ['size' => 1.5] + $ok];
        yield 'unknown error code' => ['file', ['error' => 9] + $ok];
        yield 'error as text' => ['file', ['error' => 'none'] + $ok];
        yield 'not an array' => ['file', 'a.csv'];
        yield 'upper-case field' => ['File', $ok];
        yield 'field starting with a digit' => ['2file', $ok];
        yield 'field starting with an underscore' => ['_file', $ok];
        yield 'field with a dash' => ['the-file', $ok];
        yield 'field with brackets' => ['file[0]', $ok];
        yield 'field with markup' => ['<b>', $ok];
        yield 'empty field' => ['', $ok];
        yield 'long field' => [str_repeat('f', 33), $ok];
        yield 'field with a space' => ['my file', $ok];
        yield 'numeric field' => [0, $ok];
    }

    #[DataProvider('droppedEntries')]
    public function testMalformedEntriesAreDropped(int|string $field, mixed $entry): void
    {
        self::assertSame([], Request::uploadedFiles([$field => $entry], static fn (string $path): bool => true));
    }

    public function testFieldNamesWithDigitsAndUnderscoresAreKept(): void
    {
        $files = Request::uploadedFiles(
            ['file2' => self::entry('a.csv', '/tmp/phpA'), 'data_file_3' => self::entry('b.csv', '/tmp/phpB'), str_repeat('f', 32) => self::entry('c.csv', '/tmp/phpC')],
            static fn (string $path): bool => true,
        );

        self::assertSame(['file2', 'data_file_3', str_repeat('f', 32)], array_keys($files));
        self::assertSame('/tmp/phpA', $files['file2']->tmpPath);
    }

    public function testATemporaryPathPhpDidNotReceiveIsNotUsed(): void
    {
        $tmp = $this->tempFile();
        $refused = Request::uploadedFiles(['file' => self::entry('a.csv', $tmp)], static fn (string $path): bool => false);
        self::assertNull($refused['file']->tmpPath);
        self::assertSame('problem.upload.failed', $refused['file']->problem());

        // fromGlobals() asks is_uploaded_file(), which no file made by a
        // test (or by an attacker naming a path) passes.
        $request = Request::fromGlobals(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'], [], [], [], [
            'file' => self::entry('a.csv', $tmp),
            'passwd' => self::entry('passwd', '/etc/passwd'),
        ]);
        self::assertNull($request->file('file')?->tmpPath);
        self::assertSame('problem.upload.failed', $request->file('file')?->problem());
        self::assertNull($request->file('passwd')?->tmpPath);
        self::assertNull($request->file('missing'));
    }

    public function testTheCheckerIsNotAskedWhenPhpReportsAnError(): void
    {
        $asked = [];
        $files = Request::uploadedFiles(
            ['file' => self::entry('a.csv', '/tmp/phpUPLOAD', 0, UPLOAD_ERR_PARTIAL), 'none' => self::entry('', '', 0, UPLOAD_ERR_NO_FILE)],
            static function (string $path) use (&$asked): bool {
                $asked[] = $path;

                return true;
            },
        );

        self::assertSame([], $asked);
        self::assertNull($files['file']->tmpPath);
        self::assertSame('problem.upload.failed', $files['file']->problem());
        self::assertSame('problem.upload.missing', $files['none']->problem());
    }

    /** @return iterable<string, array{int, string}> */
    public static function uploadErrors(): iterable
    {
        yield 'no file' => [UPLOAD_ERR_NO_FILE, 'problem.upload.missing'];
        yield 'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 'problem.upload.too_large'];
        yield 'over the form limit' => [UPLOAD_ERR_FORM_SIZE, 'problem.upload.too_large'];
        yield 'partial' => [UPLOAD_ERR_PARTIAL, 'problem.upload.failed'];
        yield 'no temporary folder' => [UPLOAD_ERR_NO_TMP_DIR, 'problem.upload.failed'];
        yield 'cannot write' => [UPLOAD_ERR_CANT_WRITE, 'problem.upload.failed'];
        yield 'stopped by an extension' => [UPLOAD_ERR_EXTENSION, 'problem.upload.failed'];
    }

    #[DataProvider('uploadErrors')]
    public function testEachUploadErrorHasItsProblem(int $error, string $key): void
    {
        $file = new UploadedFile('file', 'a.csv', 0, $error, '/tmp/phpUPLOAD');

        self::assertSame($key, $file->problem());
        self::assertNull($file->tmpPath, 'A failed upload never carries a path.');
    }

    public function testASuccessWithoutAPathHasFailed(): void
    {
        self::assertSame('problem.upload.failed', (new UploadedFile('file', 'a.csv', 3, UPLOAD_ERR_OK, null))->problem());
    }

    /** @return iterable<string, array{string, string}> */
    public static function clientNames(): iterable
    {
        yield 'plain' => ['inventory.csv', 'inventory.csv'];
        yield 'unix path' => ['../../etc/passwd', 'passwd'];
        yield 'windows path' => ['C:\\Users\\example\\Desktop\\inventory.csv', 'inventory.csv'];
        yield 'mixed separators' => ['a/b\\c/d.csv', 'd.csv'];
        yield 'trailing slash' => ['folder/', ''];
        yield 'markup kept as text' => ['<img src=x onerror=alert(1)>.csv', '<img src=x onerror=alert(1)>.csv'];
        yield 'quotes and semicolons kept as text' => ['"a";b.csv', '"a";b.csv'];
        yield 'dot dot' => ['..', '..'];
        yield 'invalid UTF-8 kept for the core to clean' => ["a\xFF.csv", "a\xFF.csv"];
    }

    #[DataProvider('clientNames')]
    public function testTheClientNameIsTheLastPathPartAsText(string $sent, string $kept): void
    {
        $files = Request::uploadedFiles(['file' => self::entry($sent, '/tmp/phpUPLOAD')], static fn (string $path): bool => true);

        self::assertSame($kept, $files['file']->clientName);
        self::assertSame($kept, UploadedFile::baseName($sent));
    }

    /** @return iterable<string, array{string, string, int, int, ?string}> */
    public static function badUploadedFiles(): iterable
    {
        yield 'bad field' => ['File', 'a', 0, 0, null];
        yield 'field starting with a digit' => ['1file', 'a', 0, 0, null];
        yield 'negative size' => ['file', 'a', -1, 0, null];
        yield 'negative error' => ['file', 'a', 0, -1, null];
        yield 'unknown error' => ['file', 'a', 0, 9, null];
        yield 'empty path' => ['file', 'a', 0, 0, ''];
        yield 'path with NUL' => ['file', 'a', 0, 0, "/tmp/a\0b"];
    }

    #[DataProvider('badUploadedFiles')]
    public function testUploadedFileRefusesBadValues(string $field, string $name, int $size, int $error, ?string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UploadedFile($field, $name, $size, $error, $path);
    }

    public function testTheConstructorTakesFilesMappedByField(): void
    {
        $tmp = $this->tempFile();
        $file = new UploadedFile('file', 'a.csv', 28, UPLOAD_ERR_OK, $tmp);
        $request = new Request('POST', '/', files: ['file' => $file], contentLength: 400);

        self::assertSame($file, $request->file('file'));
        self::assertSame($tmp, $request->file('file')?->tmpPath, 'The test seam keeps any temporary path.');
        self::assertSame(400, $request->contentLength);
        self::assertSame([], (new Request('GET', '/'))->files);
        self::assertNull((new Request('GET', '/'))->contentLength);
    }

    public function testTheConstructorRefusesAFileUnderAnotherField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('POST', '/', files: ['other' => new UploadedFile('file', 'a.csv', 0, UPLOAD_ERR_NO_FILE, null)]);
    }

    public function testTheConstructorRefusesANegativeLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('POST', '/', contentLength: -1);
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function contentLengths(): iterable
    {
        yield 'digits' => ['1048576', 1048576];
        yield 'zero' => ['0', 0];
        yield 'int' => [42, 42];
        yield 'absent' => [null, null];
        yield 'empty' => ['', null];
        yield 'negative' => ['-1', null];
        yield 'negative int' => [-1, null];
        yield 'sign' => ['+5', null];
        yield 'spaces' => [' 5', null];
        yield 'hex' => ['0x10', null];
        yield 'decimal' => ['1.5', null];
        yield 'too long' => [str_repeat('9', 19), null];
        yield 'list' => [['5'], null];
    }

    #[DataProvider('contentLengths')]
    public function testContentLengthIsDigitsOnly(mixed $value, ?int $expected): void
    {
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        if ($value !== null) {
            $server['CONTENT_LENGTH'] = $value;
        }

        self::assertSame($expected, Request::fromGlobals($server, [], [], [])->contentLength);
    }

    // Upload limits (sections 2.1 and 2.3).

    /** @return iterable<string, array{string|false, ?int}> */
    public static function iniQuantities(): iterable
    {
        yield 'megabytes' => ['2M', 2097152];
        yield 'lower-case suffix' => ['8m', 8388608];
        yield 'kilobytes' => ['512K', 524288];
        yield 'gigabytes' => ['1G', 1073741824];
        yield 'bytes' => ['1000', 1000];
        yield 'spaces' => [' 64M ', 67108864];
        yield 'plus sign' => ['+5M', 5242880];
        // PHP's own reading of unusual values (ini_parse_quantity).
        yield 'decimal point: PHP reads 1.5M as 1M' => ['1.5M', 1048576];
        yield 'hexadecimal' => ['0x10', 16];
        yield 'octal' => ['010', 8];
        yield 'binary' => ['0b11', 3];
        yield 'MB: PHP reads the B as no suffix' => ['1MB', 1];
        yield 'unknown suffix: PHP keeps the digits' => ['5T', 5];
        yield 'two suffixes: PHP uses the last' => ['1MK', 1024];
        yield 'invalid octal digits read as 0' => ['008M', null];
        yield 'zero means no limit' => ['0', null];
        yield 'minus one means no limit' => ['-1', null];
        yield 'negative means no limit' => ['-5M', null];
        yield 'empty' => ['', null];
        yield 'blank' => [' ', null];
        yield 'absent setting' => [false, null];
        yield 'words' => ['lots', null];
        yield 'huge' => ['99999999999999999999G', PHP_INT_MAX];
        yield 'overflow on the factor' => ['9223372036854775807K', PHP_INT_MAX];
    }

    #[DataProvider('iniQuantities')]
    public function testIniQuantitiesAreParsed(string|false $value, ?int $expected): void
    {
        self::assertSame($expected, UploadLimits::parse($value));
    }

    /** The parse agrees with what PHP itself applies, and raises no warning (the kernel turns warnings into 500s). */
    public function testParseAgreesWithPhpAndRaisesNothing(): void
    {
        $raised = 0;
        set_error_handler(static function () use (&$raised): bool {
            $raised++;

            return true;
        });
        try {
            foreach (['1.5M', '0x10', '1MB', '010', 'lots', '99999999999999999999G'] as $value) {
                UploadLimits::parse($value);
            }
        } finally {
            restore_error_handler();
        }
        self::assertSame(0, $raised);
        self::assertSame(ini_parse_quantity('0x10'), UploadLimits::parse('0x10'));
    }

    public function testTheEffectiveLimitIsTheSmallest(): void
    {
        $limits = new UploadLimits(2097152, 8388608);
        self::assertSame(2097152, $limits->effective());
        self::assertSame(2097152, $limits->effective(67108864));
        self::assertSame(1000, $limits->effective(1000));
        self::assertSame(8388608, $limits->postMaxBytes());

        self::assertSame(1048576, (new UploadLimits(null, 1048576))->effective(67108864));
        self::assertSame(67108864, (new UploadLimits(null, null))->effective(67108864));
        self::assertSame(PHP_INT_MAX, (new UploadLimits(null, null))->effective());
        self::assertNull((new UploadLimits(5, null))->postMaxBytes());
    }

    public function testTheRunningPhpLimitsAreRead(): void
    {
        $limits = UploadLimits::fromIni();
        self::assertSame(UploadLimits::parse(ini_get('upload_max_filesize')), $limits->uploadMaxFilesize);
        self::assertSame(UploadLimits::parse(ini_get('post_max_size')), $limits->postMaxSize);
    }

    public function testLimitsRefuseNonPositiveFigures(): void
    {
        foreach ([[0, null], [null, 0], [-5, 5]] as [$upload, $post]) {
            try {
                new UploadLimits($upload, $post);
                self::fail('Accepted a non-positive limit.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        (new UploadLimits(null, null))->effective(0);
    }
}
