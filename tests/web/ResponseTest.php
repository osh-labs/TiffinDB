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
use TiffinDB\Web\Fallback;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;

/** Response values, header validation, send(), and the fallback reply. */
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
}
