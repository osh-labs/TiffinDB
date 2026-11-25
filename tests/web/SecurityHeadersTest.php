<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;

require_once __DIR__ . '/fixtures/Fixtures.php';

/** T-WEB-3: the exact header set and CSP, on every kind of response. */
#[CoversClass(SecurityHeaders::class)]
#[CoversClass(Kernel::class)]
final class SecurityHeadersTest extends TestCase
{
    private const CSP = "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; "
        . "manifest-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

    private const EXPECTED = [
        'Content-Security-Policy' => self::CSP,
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
        'Cache-Control' => 'no-store',
    ];

    public function testExactPolicyString(): void
    {
        self::assertSame(self::CSP, SecurityHeaders::contentSecurityPolicy());
    }

    /** T-WEB-81, M7 screens spec section 2.1: the page may load its own manifest, and only that is added. */
    public function testManifestSourceFollowsTheImageSource(): void
    {
        $names = array_column(SecurityHeaders::CSP_DIRECTIVES, 0);
        $at = array_search('manifest-src', $names, true);

        self::assertIsInt($at);
        self::assertSame('img-src', $names[$at - 1]);
        self::assertSame(['manifest-src', "'self'"], SecurityHeaders::CSP_DIRECTIVES[$at]);
        self::assertCount(10, SecurityHeaders::CSP_DIRECTIVES);
    }

    public function testExactHeaderSet(): void
    {
        self::assertSame(self::EXPECTED, SecurityHeaders::headers());
    }

    public function testPolicyAllowsNothingInlineOrExternal(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();
        foreach (['unsafe-inline', 'unsafe-eval', 'data:', 'http:', 'https:', '*', 'nonce-', 'sha256-'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $csp);
        }
        self::assertStringStartsWith("default-src 'none'", $csp);
    }

    public function testNoHstsOrObsoleteHeaders(): void
    {
        $names = array_map('strtolower', array_keys(SecurityHeaders::headers()));
        self::assertNotContains('strict-transport-security', $names);
        self::assertNotContains('x-xss-protection', $names);
    }

    public function testApplyReplacesConflictingHeadersAndKeepsOthers(): void
    {
        $response = new Response(200, [
            'content-security-policy' => 'default-src *',
            'x-frame-options' => 'ALLOWALL',
            'Content-Type' => 'text/plain; charset=utf-8',
        ], 'x');

        $applied = (new SecurityHeaders())->apply($response);

        self::assertSame(self::CSP, $applied->header('Content-Security-Policy'));
        self::assertSame('DENY', $applied->header('X-Frame-Options'));
        self::assertSame('text/plain; charset=utf-8', $applied->header('Content-Type'));
        self::assertCount(count(self::EXPECTED) + 1, $applied->headers);
        self::assertSame('x', $applied->body);
        self::assertSame(200, $applied->status);
    }

    /** T-WEB-55 (M4 spec part 2 section 2, P150): a stored file gets the sandboxed policy and every other header unchanged. */
    public function testSandboxedResponsesGetTheFilePolicy(): void
    {
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", SecurityHeaders::FILE_CSP);
        $expected = ['Content-Security-Policy' => SecurityHeaders::FILE_CSP] + self::EXPECTED;
        self::assertSame($expected, SecurityHeaders::headers(true));
        self::assertSame(self::EXPECTED, SecurityHeaders::headers(false));
        self::assertSame(self::EXPECTED, SecurityHeaders::headers());

        $path = tempnam(sys_get_temp_dir(), 'tiffindb-file-');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'GIF89a');
            $file = Response::file($path, 'image/gif', 'a.gif', true)
                ->withHeader('Content-Security-Policy', 'default-src *')
                ->withHeader('X-Content-Type-Options', 'sniff');
            $applied = (new SecurityHeaders())->apply($file);
            self::assertSame(SecurityHeaders::FILE_CSP, $applied->header('Content-Security-Policy'));
            foreach (self::EXPECTED as $name => $value) {
                if ($name !== 'Content-Security-Policy') {
                    self::assertSame($value, $applied->header($name), $name);
                }
            }
            self::assertSame('image/gif', $applied->header('Content-Type'));
            self::assertSame('inline; filename="a.gif"; filename*=UTF-8\'\'a.gif', $applied->header('Content-Disposition'));
            self::assertCount(count(self::EXPECTED) + 3, $applied->headers, 'Content-Type, Content-Length, and Content-Disposition besides the security set.');

            $download = (new SecurityHeaders())->apply(Response::download($path, 'application/zip', 'a.zip', false));
            self::assertSame(self::CSP, $download->header('Content-Security-Policy'), 'A download keeps the page policy.');
            self::assertSame(self::CSP, (new SecurityHeaders())->apply(new Response(200, [], 'x'))->header('Content-Security-Policy'));
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, array{string, string, int}> */
    public static function responses(): array
    {
        return [
            '200' => ['GET', '/', 200],
            '200 HEAD' => ['HEAD', '/', 200],
            '400' => ['GET', '/%00', 400],
            '404' => ['GET', '/nope', 404],
            '405 method' => ['POST', '/', 405],
            '405 route' => ['GET', '/post-only', 405],
            '500' => ['GET', '/throws', 500],
            '500 template' => ['GET', '/partial-failure', 500],
            'controller headers' => ['GET', '/sets-headers', 200],
        ];
    }

    #[DataProvider('responses')]
    public function testAppliedToEveryResponse(string $method, string $uri, int $status): void
    {
        [$response] = Fixtures::handle(Fixtures::kernel(null, Fixtures::routes()), Fixtures::request($method, $uri));

        self::assertSame($status, $response->status);
        foreach (self::EXPECTED as $name => $value) {
            self::assertSame($value, $response->header($name), $name);
        }
        $names = array_map('strtolower', array_keys($response->headers));
        self::assertSame(count($names), count(array_unique($names)), 'No header is sent twice.');
    }

    public function testAppliedToTheFallbackResponse(): void
    {
        // A kernel whose project root has no templates cannot render even
        // the 500 page, so it falls back to the fixed plain-text response.
        $kernel = new Kernel(Fixtures::config(), sys_get_temp_dir() . '/tiffindb-no-such-root');
        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        foreach (self::EXPECTED as $name => $value) {
            self::assertSame($value, $response->header($name), $name);
        }
    }
}
