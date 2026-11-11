<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

/**
 * The complete set of security headers (ADR 0008 decision 9; M0 spec
 * section 14.1). The kernel applies it to every response, error pages
 * included. Nothing else sets a security header.
 *
 * public/index.php also loads this file directly (without the autoloader)
 * to report missing dependencies, so it must depend on Response only.
 */
final class SecurityHeaders
{
    /**
     * Content-Security-Policy directives, in order. Nothing inline and no
     * external origin is allowed; anything unlisted falls to default-src.
     */
    public const CSP_DIRECTIVES = [
        ['default-src', "'none'"],
        ['style-src', "'self'"],
        ['script-src', "'self'"],
        ['img-src', "'self'"],
        ['font-src', "'self'"],
        ['connect-src', "'self'"],
        ['form-action', "'self'"],
        ['base-uri', "'none'"],
        ['frame-ancestors', "'none'"],
    ];

    /** Every other header, in the order sent. */
    private const OTHER_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
        'Cache-Control' => 'no-store',
    ];

    public static function contentSecurityPolicy(): string
    {
        $parts = [];
        foreach (self::CSP_DIRECTIVES as [$directive, $value]) {
            $parts[] = $directive . ' ' . $value;
        }

        return implode('; ', $parts);
    }

    /** @return array<string, string> The full header set, CSP first. */
    public static function headers(): array
    {
        return ['Content-Security-Policy' => self::contentSecurityPolicy()] + self::OTHER_HEADERS;
    }

    /** A copy of the response with every security header set, replacing any existing value. */
    public function apply(Response $response): Response
    {
        foreach (self::headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
