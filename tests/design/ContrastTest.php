<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Design;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\Contrast;
use TiffinDB\Tests\Support\CssTokens;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-DES-2 (skeleton) and T-DES-3 (calculator).
 *
 * Token values are read from public/assets/css/tokens.css and resolved by
 * cascade for each (theme, accent) combination; no colour value is copied
 * into this test. The expected figures are the published ones from
 * docs/ui-design-plan.md section 4.2, rounded to two decimals, compared
 * exactly. M2 extends the pairs() provider with the remaining themes and
 * accents and adds the 7:1 threshold; neither is asserted in M0.
 */
#[CoversNothing]
final class ContrastTest extends TestCase
{
    private const TOKENS = 'public/assets/css/tokens.css';

    /** Root-element classes per theme; null means the default (no class). */
    private const THEMES = ['light' => null, 'dark' => 't-dark', 'hcl' => 't-hcl', 'hcd' => 't-hcd'];

    /** Root-element classes per accent; null means the default (Teal, no class). */
    private const ACCENTS = ['teal' => null, 'indigo' => 'a-indigo', 'plum' => 'a-plum', 'graphite' => 'a-graphite'];

    private const KNOWN_SHORTFALL_WARNING = 'Known shortfall against the 7:1 target (ADR 0009 row A4); '
        . 'to be fixed before M2 exit, in both token files. Asserted here as the measured value.';

    /**
     * (theme, accent, foreground token, background token, expected ratio, note).
     * Light rows follow the row order of ui-design-plan section 4.2.
     *
     * @return iterable<string, array{string, string, string, string, float, ?string}>
     */
    public static function pairs(): iterable
    {
        $light = [
            ['--ink', '--bg', 16.19, null],
            ['--ink2', '--bg', 8.86, null],
            ['--ink2', '--sunk', 8.05, null],
            ['--accent', '--surface', 9.43, null],
            ['--accent', '--soft', 7.84, null],
            ['--on-accent', '--accent', 9.43, null],
            ['--warn', '--warn-soft', 6.81, self::KNOWN_SHORTFALL_WARNING],
            ['--side-ink', '--side', 9.43, null],
            ['--side-ink2', '--side', 7.43, null],
            ['--ctl', '--surface', 4.36, null],
        ];
        foreach ($light as [$foreground, $background, $expected, $note]) {
            yield "light/teal: {$foreground} on {$background}" => ['light', 'teal', $foreground, $background, $expected, $note];
        }
    }

    #[DataProvider('pairs')]
    public function testTokenPairHasThePublishedRatio(
        string $theme,
        string $accent,
        string $foreground,
        string $background,
        float $expected,
        ?string $note,
    ): void {
        $tokens = CssTokens::resolve(RepoFiles::read(self::TOKENS), self::classesFor($theme, $accent));

        foreach ([$foreground, $background] as $name) {
            self::assertArrayHasKey($name, $tokens, "Token {$name} is not defined for {$theme}/{$accent}.");
            self::assertTrue(CssTokens::isHexColour($tokens[$name]), "Token {$name} is not a hex colour for {$theme}/{$accent}.");
        }

        self::assertSame(
            $expected,
            Contrast::rounded($tokens[$foreground], $tokens[$background]),
            "{$foreground} on {$background} ({$theme}/{$accent})" . ($note === null ? '' : '. ' . $note),
        );
    }

    /**
     * The expected figures above are the published "Light" column, in its
     * row order, so the test cannot drift from the design plan unnoticed.
     */
    public function testExpectedFiguresAreThePublishedLightColumn(): void
    {
        $expected = [];
        foreach (self::pairs() as [$theme, $accent, , , $ratio]) {
            if ($theme === 'light' && $accent === 'teal') {
                $expected[] = number_format($ratio, 2, '.', '');
            }
        }

        self::assertSame(self::publishedColumn(RepoFiles::read('docs/ui-design-plan.md'), 'Light'), $expected);
    }

    public function testThemeAndAccentClassesChangeTheResolvedTokens(): void
    {
        $css = RepoFiles::read(self::TOKENS);
        $light = CssTokens::resolve($css, []);
        $dark = CssTokens::resolve($css, ['t-dark']);
        $indigo = CssTokens::resolve($css, ['a-indigo']);
        $darkIndigo = CssTokens::resolve($css, ['t-dark', 'a-indigo']);

        // Not vacuous: each class, and the compound dark-accent block, takes effect.
        self::assertNotSame($light['--bg'], $dark['--bg']);
        self::assertNotSame($light['--accent'], $indigo['--accent']);
        self::assertNotSame($indigo['--accent'], $darkIndigo['--accent']);
        self::assertNotSame($dark['--accent'], $darkIndigo['--accent']);
        self::assertSame($dark['--bg'], $darkIndigo['--bg']);
    }

    public function testCascadeUsesSpecificityThenSourceOrder(): void
    {
        $css = '.a-x.t-y { --c: #000000; } .a-x { --c: #111111; --d: #222222; } :root { --d: #333333; --e: #444444; } :root { --e: #555555; }';

        $resolved = CssTokens::resolve($css, ['a-x', 't-y']);

        self::assertSame('#000000', $resolved['--c'], 'The more specific compound selector wins over a later one.');
        self::assertSame('#333333', $resolved['--d'], 'Equal specificity: the later block wins.');
        self::assertSame('#555555', $resolved['--e']);
        self::assertSame('#111111', CssTokens::resolve($css, ['a-x'])['--c']);
    }

    public function testChangedTokenInAStylesheetChangesTheRatio(): void
    {
        $css = RepoFiles::read(self::TOKENS);
        $tokens = CssTokens::resolve($css, []);
        $mutated = (string) preg_replace('/(:root\s*\{[^}]*?--ink:\s*)#[0-9A-Fa-f]{6}/', '${1}#777777', $css, 1);
        self::assertNotSame($css, $mutated);
        $mutatedTokens = CssTokens::resolve($mutated, []);

        self::assertNotSame(
            Contrast::rounded($tokens['--ink'], $tokens['--bg']),
            Contrast::rounded($mutatedTokens['--ink'], $mutatedTokens['--bg']),
            'A changed token value must change the computed figure, so a wrong token would be detected.',
        );
    }

    public function testBlackOnWhiteIs21(): void
    {
        self::assertSame(21.0, Contrast::rounded('#000000', '#FFFFFF'));
        self::assertSame(21.0, Contrast::rounded('#000', '#fff'));
    }

    /** @return iterable<string, array{string}> */
    public static function colours(): iterable
    {
        foreach (['#000000', '#FFFFFF', '#777777', '#3366AA', '#C0FFEE', '#ABC'] as $colour) {
            yield $colour => [$colour];
        }
    }

    #[DataProvider('colours')]
    public function testIdenticalColoursGive1(string $colour): void
    {
        self::assertSame(1.0, Contrast::rounded($colour, $colour));
    }

    /** @return iterable<string, array{string, string}> */
    public static function colourPairs(): iterable
    {
        yield 'grey on white' => ['#777777', '#FFFFFF'];
        yield 'blue on pale' => ['#3366AA', '#EEDDCC'];
        yield 'red on blue' => ['#FF0000', '#0000FF'];
        yield 'near colours' => ['#101010', '#111111'];
    }

    #[DataProvider('colourPairs')]
    public function testRatioIsSymmetric(string $first, string $second): void
    {
        self::assertSame(Contrast::ratio($first, $second), Contrast::ratio($second, $first));
        self::assertGreaterThan(1.0, Contrast::ratio($first, $second), 'Different colours give a ratio above 1.');
    }

    public function testLuminanceFollowsTheWcagDefinition(): void
    {
        self::assertSame(0.0, Contrast::relativeLuminance('#000000'));
        self::assertEqualsWithDelta(1.0, Contrast::relativeLuminance('#FFFFFF'), 1e-12);
        // Channel weights: pure primaries give the three coefficients.
        self::assertEqualsWithDelta(0.2126, Contrast::relativeLuminance('#FF0000'), 1e-12);
        self::assertEqualsWithDelta(0.7152, Contrast::relativeLuminance('#00FF00'), 1e-12);
        self::assertEqualsWithDelta(0.0722, Contrast::relativeLuminance('#0000FF'), 1e-12);
        // Linear segment below the threshold: 10/255 / 12.92.
        self::assertEqualsWithDelta(10 / 255 / 12.92, Contrast::relativeLuminance('#0A0A0A'), 1e-12);
        // Power segment above it.
        self::assertEqualsWithDelta(((128 / 255 + 0.055) / 1.055) ** 2.4, Contrast::relativeLuminance('#808080'), 1e-12);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidColours(): iterable
    {
        foreach (['', 'FFFFFF', '#FFFF', '#GGGGGG', '#FFFFFFFF', 'red', 'var(--ink)', "#FFFFFF\n"] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    public function testHexColourCheckRejectsATrailingNewline(): void
    {
        self::assertTrue(CssTokens::isHexColour('#FFFFFF'));
        self::assertFalse(CssTokens::isHexColour("#FFFFFF\n"));
    }

    #[DataProvider('invalidColours')]
    public function testInvalidColourIsRejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Contrast::ratio($value, '#FFFFFF');
    }

    /** @return list<string> */
    private static function classesFor(string $theme, string $accent): array
    {
        self::assertArrayHasKey($theme, self::THEMES);
        self::assertArrayHasKey($accent, self::ACCENTS);

        return array_values(array_filter([self::THEMES[$theme], self::ACCENTS[$accent]], static fn (?string $c): bool => $c !== null));
    }

    /**
     * One column of the table in ui-design-plan section 4.2, in row order.
     *
     * @return list<string>
     */
    private static function publishedColumn(string $markdown, string $column): array
    {
        $start = strpos($markdown, "\n### 4.2 ");
        self::assertNotFalse($start, 'docs/ui-design-plan.md has no section 4.2.');
        $end = strpos($markdown, "\n### ", $start + 1);
        $section = substr($markdown, $start, $end === false ? null : $end - $start);

        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $section) ?: [] as $line) {
            if (str_starts_with(trim($line), '|')) {
                $rows[] = array_map('trim', explode('|', trim(trim($line), '|')));
            }
        }
        self::assertGreaterThan(2, count($rows), 'Section 4.2 has no table.');
        $index = array_search($column, $rows[0], true);
        self::assertIsInt($index, "Section 4.2 table has no '{$column}' column.");

        $values = [];
        foreach (array_slice($rows, 2) as $row) {
            $values[] = $row[$index];
        }

        return $values;
    }
}
