<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Web\Cookie;
use TiffinDB\Web\Fallback;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;

/**
 * T-WEB-14: response values, header and cookie validation, redirects, send(), and the fallback reply.
 * T-WEB-40 (M3 spec part 2 section 2.2): downloads, their headers, streaming, HEAD, and the after-send callback.
 */
#[CoversClass(Response::class)]
#[CoversClass(Fallback::class)]
final class ResponseTest extends TestCase
{
    /** @return list<array{string, string|int}> */
    private static function sendAndRecord(Response $response, bool $head): array
    {
        $steps = [];
        $response->send($head, static function (string $step, string|int $value) use (&$steps): void {
            $steps[] = [$step, $value];
        });

        return $steps;
    }

    public function testSendEmitsRemovalStatusHeadersAndBody(): void
    {
        $response = new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Test' => 'a'], 'body text');

        $this->expectOutputString('body text');
        self::assertSame(
            [
                ['remove', 'X-Powered-By'],
                ['status', 404],
                ['header', 'Content-Type: text/plain; charset=utf-8'],
                ['header', 'X-Test: a'],
            ],
            self::sendAndRecord($response, false),
        );
    }

    public function testXPoweredByIsRemovedFirst(): void
    {
        $this->expectOutputString('');
        $steps = self::sendAndRecord(new Response(200), false);

        self::assertSame(['remove', 'X-Powered-By'], $steps[0]);
    }

    public function testHeadSuppressesTheBodyButNotTheHeaders(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<!DOCTYPE html>');

        $this->expectOutputString('');
        $steps = self::sendAndRecord($response, true);

        self::assertContains(['status', 200], $steps);
        self::assertContains(['header', 'Content-Type: text/html; charset=utf-8'], $steps);
        self::assertContains(['remove', 'X-Powered-By'], $steps);
    }

    public function testRealSendWritesTheBody(): void
    {
        // Under the command-line SAPI the header functions are no-ops, so
        // only the body is observable here.
        $this->expectOutputString('real');
        (new Response(200, [], 'real'))->send();
    }

    public function testWithHeaderReplacesIgnoringCase(): void
    {
        $response = (new Response(200, ['content-type' => 'a']))->withHeader('Content-Type', 'b');

        self::assertSame(['Content-Type' => 'b'], $response->headers);
        self::assertSame('b', $response->header('CONTENT-TYPE'));
        self::assertNull($response->header('X-None'));
    }

    /** @return array<string, array{string, string}> */
    public static function badHeaders(): array
    {
        return [
            'name with trailing newline' => ["X-Test\n", 'v'],
            'name with colon' => ['X-Test:', 'v'],
            'name with space' => ['X Test', 'v'],
            'empty name' => ['', 'v'],
            'value with newline' => ['X-Test', "a\nb"],
            'value with trailing newline' => ['X-Test', "a\n"],
            'value with CR' => ['X-Test', "a\rSet-Cookie: x"],
            'value with NUL' => ['X-Test', "a\0"],
        ];
    }

    #[DataProvider('badHeaders')]
    public function testHeaderInjectionIsRejected(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Response(200))->withHeader($name, $value);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response(99);
    }

    public function testFallbackCarriesTheFullHeaderSet(): void
    {
        $response = Fallback::response();

        self::assertSame(500, $response->status);
        self::assertSame(Fallback::BODY, $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        foreach (SecurityHeaders::headers() as $name => $value) {
            self::assertSame($value, $response->header($name), $name);
        }
        self::assertSame(Fallback::DEPENDENCIES_BODY, Fallback::response(Fallback::DEPENDENCIES_BODY)->body);
    }

    // ------------------------------------------------- M1 (T-WEB-14)

    private static function cookie(string $name, string $value = 'v1'): Cookie
    {
        return new Cookie($name, $value, '/', true, 'Lax', 60);
    }

    public function testSeveralCookiesAreSentWithoutReplacing(): void
    {
        $response = (new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'ok'))
            ->withCookie(self::cookie('tdb_session', 'abc'))
            ->withCookie(Cookie::deleting('tdb_csrf', '/', false, 'Strict'));

        $this->expectOutputString('ok');
        self::assertSame(
            [
                ['remove', 'X-Powered-By'],
                ['status', 200],
                ['header', 'Content-Type: text/plain; charset=utf-8'],
                ['cookie', 'Set-Cookie: tdb_session=abc; Path=/; Max-Age=60; Secure; HttpOnly; SameSite=Lax'],
                ['cookie', 'Set-Cookie: tdb_csrf=; Path=/; Max-Age=0; HttpOnly; SameSite=Strict'],
            ],
            self::sendAndRecord($response, false),
        );
    }

    public function testHeadStillSendsCookies(): void
    {
        $response = (new Response(200, [], 'body'))->withCookie(self::cookie('tdb_session'));

        $this->expectOutputString('');
        $steps = self::sendAndRecord($response, true);
        self::assertContains(['cookie', 'Set-Cookie: tdb_session=v1; Path=/; Max-Age=60; Secure; HttpOnly; SameSite=Lax'], $steps);
    }

    public function testALaterCookieReplacesOneOfTheSameName(): void
    {
        $response = (new Response(200))
            ->withCookie(self::cookie('tdb_session', 'first'))
            ->withCookie(self::cookie('tdb_csrf', 'x'))
            ->withCookie(self::cookie('tdb_session', 'second'));

        self::assertCount(2, $response->cookies);
        self::assertSame('tdb_csrf', $response->cookies[0]->name);
        self::assertSame('second', $response->cookie('tdb_session')?->value);
        self::assertNull($response->cookie('other'));
    }

    public function testHeadersAndCookiesSurviveEachOther(): void
    {
        $response = (new Response(200))->withCookie(self::cookie('tdb_session'))->withHeader('X-Test', 'a');

        self::assertSame('a', $response->header('X-Test'));
        self::assertNotNull($response->cookie('tdb_session'));
        self::assertNotNull((new SecurityHeaders())->apply($response)->cookie('tdb_session'), 'Security headers keep cookies.');
    }

    public function testSetCookieCannotBeSetAsAHeader(): void
    {
        foreach (['Set-Cookie', 'set-cookie', 'SET-COOKIE'] as $name) {
            try {
                (new Response(200))->withHeader($name, 'tdb_session=x');
                self::fail("{$name} accepted");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new Response(200, ['Set-Cookie' => 'x=y']);
    }

    public function testConstructorRefusesNonCookies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response(200, [], '', ['tdb_session=x']);
    }

    public function testRedirect(): void
    {
        $response = Response::redirect('/sub/projects?x=1');

        self::assertSame(303, $response->status);
        self::assertSame('/sub/projects?x=1', $response->header('Location'));
        self::assertSame('', $response->body);
    }

    /** @return array<string, array{string}> */
    public static function badLocations(): array
    {
        return [
            'empty' => [''],
            'relative' => ['projects'],
            'absolute URL' => ['https://example.com/'],
            'protocol-relative' => ['//example.com/'],
            'back-slash after slash' => ['/\\example.com/'],
            'back-slash inside' => ['/a\\b'],
            'CR' => ["/a\rLocation: //evil"],
            'LF' => ["/a\nX: y"],
            'NUL' => ["/a\0"],
            'tab' => ["/a\tb"],
            'DEL' => ["/a\x7F"],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('badLocations')]
    public function testRedirectRefusesUnsafeLocations(string $location): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::redirect($location);
    }

    /**
     * Runs PHP code in a child process (the fallback discards output
     * buffers and checks headers_sent(), which PHPUnit's own process cannot
     * provide) and returns its standard output.
     */
    private static function runChild(string $code): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', "require {$autoload}; {$code}"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start PHP.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
    }

    public function testFallbackSendsNoBodyForHead(): void
    {
        self::assertSame(Fallback::BODY, self::runChild('TiffinDB\Web\Fallback::send();'));
        self::assertSame('', self::runChild('TiffinDB\Web\Fallback::send(TiffinDB\Web\Fallback::BODY, true);'));
    }

    public function testShutdownFallbackSendsNoBodyForHead(): void
    {
        $fatal = 'register_shutdown_function([TiffinDB\Web\Fallback::class, "onShutdown"]);'
            . ' ini_set("memory_limit", "16M"); $a = str_repeat("x", 64 * 1024 * 1024);';

        self::assertSame(Fallback::BODY, self::runChild('$_SERVER["REQUEST_METHOD"] = "GET"; ' . $fatal));
        self::assertSame('', self::runChild('$_SERVER["REQUEST_METHOD"] = "HEAD"; ' . $fatal));
    }

    // T-WEB-40: downloads (M3 spec part 2 section 2.2).

    /** @var list<string> Temporary files and folders, removed in tearDown(). */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->made) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->made = [];
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tiffindb-download-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->made[] = $path;

        return $path;
    }

    public function testDownloadHeadersForAnAsciiName(): void
    {
        $path = $this->file("name,room\nExample lamp,Hall\n");
        $response = Response::download($path, 'text/csv', 'Items.csv', true);

        self::assertSame(200, $response->status);
        self::assertSame([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Length' => '28',
            'Content-Disposition' => 'attachment; filename="Items.csv"; filename*=UTF-8\'\'Items.csv',
            'Cache-Control' => 'no-store',
        ], $response->headers);
        self::assertSame('', $response->body);
        self::assertSame("name,room\nExample lamp,Hall\n", $response->body());
        self::assertTrue($response->isDownload());
        self::assertTrue($response->removesAfterSend());
        self::assertFalse(Response::download($path, 'text/csv', 'Items.csv', false)->removesAfterSend());
        self::assertFalse((new Response(200))->isDownload());
    }

    /** @return iterable<string, array{string, string}> */
    public static function mediaTypes(): iterable
    {
        yield 'csv' => ['text/csv', 'text/csv; charset=utf-8'];
        yield 'json' => ['application/json', 'application/json; charset=utf-8'];
        yield 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield 'zip' => ['application/zip', 'application/zip'];
    }

    #[DataProvider('mediaTypes')]
    public function testDownloadContentTypes(string $mediaType, string $header): void
    {
        self::assertSame($header, Response::download($this->file('x'), $mediaType, 'a', false)->header('Content-Type'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function downloadNames(): iterable
    {
        yield 'non-ASCII' => ['Küche – Möbel.csv', 'K__che ___ M__bel.csv', 'K%C3%BCche%20%E2%80%93%20M%C3%B6bel.csv'];
        yield 'quotes' => ['say "hi".csv', 'say _hi_.csv', 'say%20%22hi%22.csv'];
        yield 'semicolons' => ['a; filename=evil.exe', 'a_ filename=evil.exe', 'a%3B%20filename%3Devil.exe'];
        yield 'slashes' => ['../../etc/passwd', '.._.._etc_passwd', '..%2F..%2Fetc%2Fpasswd'];
        yield 'back-slashes' => ['C:\\x\\y.csv', 'C:_x_y.csv', 'C%3A%5Cx%5Cy.csv'];
        yield 'line break' => ["a\r\nSet-Cookie: x=1.csv", 'a__Set-Cookie: x=1.csv', 'a%0D%0ASet-Cookie%3A%20x%3D1.csv'];
        yield 'NUL and DEL' => ["a\0b\x7Fc", 'a_b_c', 'a%00b%7Fc'];
        yield 'percent' => ['100%.csv', '100%.csv', '100%25.csv'];
        yield 'markup' => ['<b>.zip', '<b>.zip', '%3Cb%3E.zip'];
        yield 'emoji' => ["\u{1F4E6}.zip", '____.zip', '%F0%9F%93%A6.zip'];
    }

    #[DataProvider('downloadNames')]
    public function testContentDispositionIsBuiltSafely(string $name, string $ascii, string $encoded): void
    {
        $expected = 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded;
        self::assertSame($expected, Response::contentDisposition($name));

        $header = Response::download($this->file('x'), 'application/zip', $name, false)->header('Content-Disposition');
        self::assertSame($expected, $header);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', (string) $header, 'Printable ASCII only.');
        // Exactly the two parameters: the quoted name holds no quote, back-slash, or semicolon.
        self::assertMatchesRegularExpression('/^attachment; filename="[\x20\x21\x23-\x2E\x30-\x3A\x3C-\x5B\x5D-\x7E]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+$/', (string) $header);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'invalid UTF-8' => ["a\xFF.csv"];
        yield 'overlong encoding' => ["\xC0\xAF.csv"];
    }

    #[DataProvider('badNames')]
    public function testBadDownloadNamesAreRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::download($this->file('x'), 'text/csv', $name, false);
    }

    /** @return iterable<string, array{string}> */
    public static function badMediaTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'no subtype' => ['text'];
        yield 'upper case' => ['Text/CSV'];
        yield 'parameter' => ['text/csv; charset=utf-8'];
        yield 'line break' => ["text/csv\r\nX-Evil: 1"];
        yield 'html sniffing bait with space' => ['text/html ;'];
        yield 'two slashes' => ['text/csv/x'];
        yield 'wildcard' => ['*/*'];
        yield 'trailing newline' => ["text/csv\n"];
    }

    #[DataProvider('badMediaTypes')]
    public function testBadMediaTypesAreRefused(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::download($this->file('x'), $type, 'a.csv', false);
    }

    public function testDownloadPathMustBeAnAbsoluteRegularFile(): void
    {
        $file = $this->file('x');
        $dir = sys_get_temp_dir() . '/tiffindb-download-dir-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->made[] = $dir;
        $link = $dir . '/link.csv';
        symlink($file, $link);
        $this->made[] = $link;
        $relative = basename($file);

        foreach (['', $relative, 'tmp/x.csv', $dir, $dir . '/missing.csv', $link, $file . "\0.csv", '../' . $relative] as $path) {
            try {
                Response::download($path, 'text/csv', 'a.csv', false);
                self::fail('Accepted ' . json_encode($path));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDownloadStreamsInChunks(): void
    {
        $contents = str_repeat('0123456789abcdef', 8192) . 'tail';   // 131,076 bytes: two whole chunks and a piece
        $response = Response::download($this->file($contents), 'application/zip', 'Home.tiffindb.zip', false);
        $response = (new SecurityHeaders())->apply($response)->withHeader('X-Test', 'kept');

        $this->expectOutputString('');
        $steps = self::sendAndRecord($response, false);
        $chunks = array_values(array_filter($steps, static fn (array $s): bool => $s[0] === 'chunk'));
        self::assertSame([Response::CHUNK_BYTES, Response::CHUNK_BYTES, 4], array_map(static fn (array $s): int => strlen((string) $s[1]), $chunks));
        self::assertSame($contents, implode('', array_map(static fn (array $s): string => (string) $s[1], $chunks)));
        self::assertSame(['remove', 'X-Powered-By'], $steps[0]);
        self::assertSame(['status', 200], $steps[1]);
        $headers = array_column(array_filter($steps, static fn (array $s): bool => $s[0] === 'header'), 1);
        self::assertContains('Content-Length: 131076', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers, 'The security headers survive on a download.');
        self::assertContains('Cache-Control: no-store', $headers);
        self::assertContains('X-Test: kept', $headers);
        self::assertSame('chunk', end($steps)[0], 'The body comes after every header.');
    }

    public function testAnEmptyFileSendsNoChunk(): void
    {
        $response = Response::download($this->file(''), 'text/csv', 'empty.csv', false);

        self::assertSame('0', $response->header('Content-Length'));
        self::assertSame([], array_filter(self::sendAndRecord($response, false), static fn (array $s): bool => $s[0] === 'chunk'));
    }

    public function testHeadSendsHeadersButNoBody(): void
    {
        $calls = 0;
        $response = Response::download($this->file('secret'), 'text/csv', 'a.csv', true)
            ->withAfterSend(static function () use (&$calls): void {
                $calls++;
            });

        $this->expectOutputString('');
        $steps = self::sendAndRecord($response, true);
        self::assertSame([], array_filter($steps, static fn (array $s): bool => $s[0] === 'chunk'));
        self::assertContains(['header', 'Content-Length: 6'], $steps);
        self::assertSame(1, $calls, 'The after-send callback runs after the headers of a HEAD.');
    }

    public function testAfterSendRunsOnceAfterTheBody(): void
    {
        $order = [];
        $response = Response::download($this->file('abc'), 'text/csv', 'a.csv', true)
            ->withAfterSend(static function () use (&$order): void {
                $order[] = 'done';
            });
        // Copies made by the kernel (headers, cookies) share the one callback.
        $copy = (new SecurityHeaders())->apply($response);

        $copy->send(false, static function (string $step) use (&$order): void {
            $order[] = $step;
        });
        $copy->send(false, static function (string $step): void {
        });
        $response->send(true, static function (string $step): void {
        });

        self::assertSame('done', end($order));
        self::assertSame(1, count(array_keys($order, 'done', true)), 'The callback ran once.');
        self::assertContains('chunk', $order);
        self::assertLessThan(array_search('done', $order, true), array_search('chunk', $order, true));
    }

    /** @return iterable<string, array{bool}> */
    public static function getAndHead(): iterable
    {
        yield 'GET' => [false];
        yield 'HEAD' => [true];
    }

    /**
     * Review follow-up: a download whose file has gone by the time it is
     * sent gets the fixed 500, with none of the download's headers, no
     * PHP message, and the after-send callback still run once.
     */
    #[DataProvider('getAndHead')]
    public function testAGoneFileSendsTheFallbackBeforeAnyDownloadHeader(bool $head): void
    {
        $path = $this->file('abc');
        $calls = 0;
        $response = Response::download($path, 'text/csv', 'a.csv', true)
            ->withAfterSend(static function () use (&$calls): void {
                $calls++;
            });
        $response = (new SecurityHeaders())->apply($response);
        unlink($path);

        $log = tempnam(sys_get_temp_dir(), 'tiffindb-log-');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            $this->expectOutputString($head ? '' : Fallback::BODY);
            $steps = self::sendAndRecord($response, $head);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            $logged = (string) file_get_contents($log);
            unlink($log);
        }

        self::assertSame(['status', 500], $steps[1]);
        $headers = array_column(array_filter($steps, static fn (array $s): bool => $s[0] === 'header'), 1);
        self::assertContains('Content-Type: text/plain; charset=utf-8', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
        foreach ($headers as $line) {
            self::assertDoesNotMatchRegularExpression('/^(Content-Disposition|Content-Length):/i', (string) $line);
        }
        self::assertSame([], array_filter($steps, static fn (array $s): bool => $s[0] === 'chunk'));
        self::assertSame(1, $calls);
        self::assertStringContainsString('The download file cannot be opened.', $logged);
        self::assertStringNotContainsString($path, $logged, 'The log line names no path.');
    }

    public function testAFileReplacedByALinkIsNotSent(): void
    {
        $path = $this->file('abc');
        $response = Response::download($path, 'text/csv', 'a.csv', false);
        unlink($path);
        symlink('/etc/hostname', $path);

        $log = tempnam(sys_get_temp_dir(), 'tiffindb-log-');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            $this->expectOutputString(Fallback::BODY);
            $steps = self::sendAndRecord($response, false);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($log);
        }
        self::assertSame(['status', 500], $steps[1]);
    }

    public function testOnlyADownloadTakesAnAfterSendCallback(): void
    {
        $this->expectException(LogicException::class);
        (new Response(200, [], 'x'))->withAfterSend(static function (): void {
        });
    }

    public function testAStringBodyIsUnchangedByTheDownloadCode(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'plain');

        self::assertSame('plain', $response->body());
        self::assertFalse($response->removesAfterSend());
        $this->expectOutputString('plain');
        self::assertSame([], array_filter(self::sendAndRecord($response, false), static fn (array $s): bool => $s[0] === 'chunk'));
    }

    // T-WEB-55: Response::file() (M4 spec part 2 section 2).

    /** @return iterable<string, array{string}> */
    public static function inlineTypes(): iterable
    {
        foreach (Response::INLINE_TYPES as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('inlineTypes')]
    public function testFileSendsTheFourImageTypesInline(string $type): void
    {
        $path = $this->file("\x89PNG\r\n\x1A\nbytes");
        $response = Response::file($path, $type, 'stand-mixer.png', true);

        self::assertSame(200, $response->status);
        self::assertSame([
            'Content-Type' => $type,
            'Content-Length' => '13',
            'Content-Disposition' => 'inline; filename="stand-mixer.png"; filename*=UTF-8\'\'stand-mixer.png',
            'Cache-Control' => 'no-store',
        ], $response->headers);
        self::assertTrue($response->isSandboxed());
        self::assertTrue($response->isDownload());
        self::assertFalse($response->removesAfterSend());
        self::assertSame("\x89PNG\r\n\x1A\nbytes", $response->body());

        $attachment = Response::file($path, $type, 'stand-mixer.png', false);
        self::assertSame('attachment; filename="stand-mixer.png"; filename*=UTF-8\'\'stand-mixer.png', $attachment->header('Content-Disposition'));
        self::assertTrue($attachment->isSandboxed());
    }

    /** @return iterable<string, array{string}> */
    public static function attachmentOnlyTypes(): iterable
    {
        yield 'octet-stream' => ['application/octet-stream'];
        yield 'pdf' => ['application/pdf'];
        yield 'html' => ['text/html'];
        yield 'svg' => ['image/svg+xml'];
        yield 'bmp' => ['image/bmp'];
        yield 'tiff' => ['image/tiff'];
        yield 'xml' => ['text/xml'];
        yield 'plain' => ['text/plain'];
    }

    #[DataProvider('attachmentOnlyTypes')]
    public function testFileRefusesInlineForAnyOtherType(string $type): void
    {
        $path = $this->file('<html><script>alert(1)</script></html>');
        $response = Response::file($path, $type, 'a.bin', false);
        self::assertSame('application/octet-stream', $response->header('Content-Type'), 'Every type but the four images is sent as octet-stream (P181).');
        self::assertStringStartsWith('attachment; ', (string) $response->header('Content-Disposition'));

        $this->expectException(InvalidArgumentException::class);
        Response::file($path, $type, 'a.bin', true);
    }

    #[DataProvider('downloadNames')]
    public function testFileDispositionIsBuiltSafely(string $name, string $ascii, string $encoded): void
    {
        foreach ([true => 'inline', false => 'attachment'] as $inline => $word) {
            $expected = $word . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded;
            self::assertSame($expected, Response::contentDisposition($name, (bool) $inline));
            $header = (string) Response::file($this->file('x'), 'image/png', $name, (bool) $inline)->header('Content-Disposition');
            self::assertSame($expected, $header);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $header, 'Printable ASCII only.');
            self::assertMatchesRegularExpression('/^' . $word . '; filename="[\x20\x21\x23-\x2E\x30-\x3A\x3C-\x5B\x5D-\x7E]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+$/', $header);
        }
        self::assertSame(Response::contentDisposition($name), Response::contentDisposition($name, false), 'The default stays attachment.');
    }

    public function testFileNamesWithBidiAndControlCharactersStayInTheirParameter(): void
    {
        $name = "receipt\u{202E}fdp.exe";
        $header = (string) Response::file($this->file('x'), 'application/octet-stream', $name, false)->header('Content-Disposition');
        self::assertSame('attachment; filename="receiptfdp.exe"; filename*=UTF-8\'\'receiptfdp.exe', $header, 'Format characters are removed (P181).');

        $hidden = "a\u{202E}\u{2066}\u{200B}\u{FEFF}\u{00AD}\u{2028}\u{2029}\u{E0041}b.png";
        self::assertSame('inline; filename="ab.png"; filename*=UTF-8\'\'ab.png', Response::contentDisposition($hidden, true));
        self::assertSame("attachment; filename=\"a___b\"; filename*=UTF-8''a%E2%80%8Db", Response::contentDisposition("a\u{200D}b"), 'The joiners stay (P61).');
        self::assertSame('attachment; filename="file"; filename*=UTF-8\'\'file', Response::contentDisposition("\u{202E}\u{200B}"), 'A name left empty becomes "file".');
    }

    public function testDownloadNamesAreCutTo255CodePoints(): void
    {
        $long = str_repeat("\u{1F4E6}", 5000) . '.png';
        $header = Response::contentDisposition($long, true);
        self::assertSame('inline; filename="' . str_repeat('_', 4 * 255) . '"; filename*=UTF-8\'\'' . str_repeat('%F0%9F%93%A6', 255), $header);
        self::assertLessThan(5000, strlen($header));
        $ascii = str_repeat('x', 1000);
        self::assertSame('attachment; filename="' . str_repeat('x', 255) . '"; filename*=UTF-8\'\'' . str_repeat('x', 255), Response::contentDisposition($ascii));
    }

    #[DataProvider('badNames')]
    public function testFileRefusesBadNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::file($this->file('x'), 'image/png', $name, true);
    }

    #[DataProvider('badMediaTypes')]
    public function testFileRefusesBadMediaTypes(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::file($this->file('x'), $type, 'a.png', false);
    }

    public function testFilePathMustBeAnAbsoluteRegularFile(): void
    {
        $file = $this->file('x');
        $dir = sys_get_temp_dir() . '/tiffindb-file-dir-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->made[] = $dir;
        $link = $dir . '/link.png';
        symlink($file, $link);
        $this->made[] = $link;
        $relative = basename($file);

        foreach (['', $relative, 'tmp/x.png', $dir, $dir . '/missing.png', $link, $file . "\0.png", '../' . $relative] as $path) {
            try {
                Response::file($path, 'image/png', 'a.png', true);
                self::fail('Accepted ' . json_encode($path));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFileStreamsItsBytesAndKeepsTheFile(): void
    {
        $contents = str_repeat("\x00\xFFbinary", 9000);
        $path = $this->file($contents);
        $response = (new SecurityHeaders())->apply(Response::file($path, 'image/webp', 'a.webp', true));

        $this->expectOutputString('');
        $steps = self::sendAndRecord($response, false);
        $chunks = array_values(array_filter($steps, static fn (array $s): bool => $s[0] === 'chunk'));
        self::assertSame($contents, implode('', array_map(static fn (array $s): string => (string) $s[1], $chunks)));
        $headers = array_column(array_filter($steps, static fn (array $s): bool => $s[0] === 'header'), 1);
        self::assertContains('Content-Length: ' . strlen($contents), $headers);
        self::assertContains('Content-Security-Policy: ' . SecurityHeaders::FILE_CSP, $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
        self::assertFileExists($path, 'A stored file is never removed after sending.');

        $head = self::sendAndRecord($response, true);
        self::assertSame([], array_filter($head, static fn (array $s): bool => $s[0] === 'chunk'), 'HEAD sends the headers only.');
        self::assertContains(['header', 'Content-Length: ' . strlen($contents)], $head);
        self::assertFileExists($path);
    }

    public function testAFileTakesNoAfterSendCallback(): void
    {
        $this->expectException(LogicException::class);
        Response::file($this->file('x'), 'image/png', 'a.png', true)->withAfterSend(static function (): void {
        });
    }

    public function testTheSandboxedFlagSurvivesCopies(): void
    {
        $response = Response::file($this->file('x'), 'image/gif', 'a.gif', true);
        self::assertTrue($response->withHeader('X-Test', 'kept')->isSandboxed());
        self::assertTrue($response->withCookie(self::cookie('tdb_x'))->isSandboxed());
        self::assertTrue((new SecurityHeaders())->apply($response)->isSandboxed());
        self::assertFalse(Response::download($this->file('x'), 'text/csv', 'a.csv', false)->isSandboxed());
        self::assertFalse((new Response(200, [], 'x'))->isSandboxed());
        self::assertFalse(Response::redirect('/x')->isSandboxed());
    }
}
