<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Design;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * The "no colour literal" rule for app.css (M7 screens spec section 8,
 * rules of M0 to M5): colours come from tokens.css only. Added by the
 * M7-WP3 review, where a mutation of `.link-danger` to a hex colour passed
 * every existing test. Comments are stripped first.
 */
#[CoversNothing]
final class AppColourLiteralTest extends TestCase
{
    private const PATTERN = '/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(/';

    public function testAppStylesheetHoldsNoColourLiteral(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', RepoFiles::read('public/assets/css/app.css'));
        self::assertSame(0, preg_match_all(self::PATTERN, $css, $m), 'Colour literals in app.css: ' . implode(', ', $m[0]));
    }

    /** @return array<string, array{string}> */
    public static function literals(): array
    {
        return [
            'hex' => ['a { color: #c00; }'],
            'long hex' => ['a { color: #084F4A; }'],
            'rgb' => ['a { color: rgb(0 0 0); }'],
            'hsla' => ['a { color: hsla(0, 0%, 0%, 1); }'],
            'oklch' => ['a { color: oklch(50% 0.1 200); }'],
        ];
    }

    #[DataProvider('literals')]
    public function testLiteralIsDetected(string $css): void
    {
        self::assertSame(1, preg_match(self::PATTERN, $css));
    }

    public function testTokensPass(): void
    {
        self::assertSame(0, preg_match(self::PATTERN, 'a { color: var(--warn); border: var(--border-card) solid var(--line); }'));
    }
}
