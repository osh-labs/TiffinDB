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
use TiffinDB\Web\Fallback;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;

/** T-WEB-14: response values, header and cookie validation, redirects, send(), and the fallback reply. */
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
}
