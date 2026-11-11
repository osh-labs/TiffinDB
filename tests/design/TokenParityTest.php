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
use RuntimeException;
use TiffinDB\Tests\Support\CssTokens;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-DES-1: the application token stylesheet keeps every colour of the
 * design reference. Every selector block of docs/design/tokens.css exists in
 * public/assets/css/tokens.css, every hex-colour custom property has the same
 * value in both, and neither file has a colour property the other lacks.
 *
 * Extended to the non-colour tokens (M0 spec section 8.1 and 8.2): every
 * other custom property of the design file has, in the application file, the
 * value given by rules R2 to R4 (design px divided by 18, as rem to four
 * decimals; R3 lengths unchanged; R4 floors as max(); non-px values
 * unchanged), and everything the application file has beyond the design file
 * is exactly the sanctioned additions of section 8.2.
 */
#[CoversNothing]
final class TokenParityTest extends TestCase
{
    private const DESIGN = 'docs/design/tokens.css';
    private const APP = 'public/assets/css/tokens.css';

    /** R3: lengths that stay in px (border widths, focus outline width and offset, pill radius). */
    private const R3_TOKENS = ['--border-control', '--border-card', '--focus-ring', '--focus-offset', '--radius-pill'];

    /** R4: target floors, written as max(<px>, <rem>). */
    private const R4_TOKENS = ['--target-min', '--control-height'];

    /**
     * Section 8.2 change 3: added tokens (all in :root) with their design px.
     */
    private const ADDED_TOKENS = [
        '--space-1' => '4px',
        '--space-2' => '8px',
        '--space-3' => '12px',
        '--space-4' => '16px',
        '--space-5' => '20px',
        '--space-6' => '24px',
        '--space-7' => '32px',
        '--space-8' => '48px',
        '--border-card' => '1px',
        '--focus-offset' => '2px',
        '--width-form' => '800px',
        '--width-wide' => '1120px',
        '--bar-min' => '68px',
    ];

    /**
     * Section 8.2 change 4: added rules, as selector => declared properties
     * (colour-scheme per theme, the R1 root font-size steps, the line-spacing
     * steps). ':root' lists only what it gains besides the added tokens.
     */
    private const ADDED_RULES = [
        ':root' => ['color-scheme', 'font-size'],
        '.t-dark, .t-hcd' => ['color-scheme'],
        ':root.s-small' => ['font-size'],
        ':root.s-large' => ['font-size'],
        ':root.s-larger' => ['font-size'],
        ':root.s-largest' => ['font-size'],
        '.l-roomy' => ['--leading'],
        '.l-very-roomy' => ['--leading'],
    ];

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
            $this->tempDir = null;
        }
    }

    /**
     * Every difference between the two files, as readable messages.
     *
     * @return list<string>
     */
    public static function differences(string $designCss, string $appCss): array
    {
        $designBlocks = CssTokens::customProperties($designCss);
        $appBlocks = CssTokens::customProperties($appCss);
        $designColours = CssTokens::colourProperties($designCss);
        $appColours = CssTokens::colourProperties($appCss);

        $problems = [];
        foreach (array_keys($designBlocks) as $selector) {
            if (!array_key_exists($selector, $appBlocks)) {
                $problems[] = "Selector block '{$selector}' of the design file is missing from the application file.";
            }
        }
        foreach ($designColours as $selector => $properties) {
            foreach ($properties as $property => $value) {
                $other = $appColours[$selector][$property] ?? null;
                if ($other === null) {
                    $problems[] = "'{$selector}' {$property} is a colour in the design file only.";
                } elseif ($other !== $value) {
                    $problems[] = "'{$selector}' {$property} is {$value} in the design file but {$other} in the application file.";
                }
            }
        }
        foreach ($appColours as $selector => $properties) {
            foreach ($properties as $property => $value) {
                if (!isset($designColours[$selector][$property])) {
                    $problems[] = "'{$selector}' {$property} is a colour in the application file only.";
                }
            }
        }

        return $problems;
    }

    /**
     * Non-colour custom properties of the design file whose application
     * value is missing or not the one rules R2 to R4 give.
     *
     * @return list<string>
     */
    public static function nonColourDifferences(string $designCss, string $appCss): array
    {
        $appBlocks = CssTokens::customProperties($appCss);
        $problems = [];
        foreach (CssTokens::customProperties($designCss) as $selector => $properties) {
            foreach ($properties as $property => $value) {
                if (CssTokens::isHexColour($value)) {
                    continue;
                }
                $expected = self::convertedValue($property, $value);
                $actual = $appBlocks[$selector][$property] ?? null;
                if ($actual === null) {
                    $problems[] = "'{$selector}' {$property} is missing from the application file.";
                } elseif ($actual !== $expected) {
                    $problems[] = "'{$selector}' {$property} is '{$value}' in the design file, so '{$expected}' is expected, but the application file has '{$actual}'.";
                }
            }
        }

        return $problems;
    }

    /**
     * Differences between what the application file adds to the design file
     * and the sanctioned additions of section 8.2.
     *
     * @return list<string>
     */
    public static function additionDifferences(string $designCss, string $appCss): array
    {
        $designPairs = self::declaredPairs($designCss);
        $actual = array_values(array_diff(self::declaredPairs($appCss), $designPairs));

        $expected = [];
        foreach (array_keys(self::ADDED_TOKENS) as $name) {
            $expected[] = ":root | {$name}";
        }
        foreach (self::ADDED_RULES as $selector => $properties) {
            foreach ($properties as $property) {
                $expected[] = "{$selector} | {$property}";
            }
        }
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        $problems = [];
        foreach (array_diff($actual, $expected) as $pair) {
            $problems[] = "Unsanctioned addition in the application file: {$pair}.";
        }
        foreach (array_diff($expected, $actual) as $pair) {
            $problems[] = "Sanctioned addition missing from the application file: {$pair}.";
        }

        $designSelectors = array_keys(CssTokens::customProperties($designCss));
        $appSelectors = array_unique(array_map(static fn (array $rule): string => $rule['selector'], CssTokens::rules($appCss)));
        $newSelectors = array_values(array_diff($appSelectors, $designSelectors));
        $sanctionedSelectors = array_values(array_diff(array_keys(self::ADDED_RULES), $designSelectors));
        sort($newSelectors, SORT_STRING);
        sort($sanctionedSelectors, SORT_STRING);
        if ($newSelectors !== $sanctionedSelectors) {
            $problems[] = 'Added selector blocks are [' . implode('; ', $newSelectors) . '], sanctioned are [' . implode('; ', $sanctionedSelectors) . '].';
        }

        $root = CssTokens::customProperties($appCss)[':root'] ?? [];
        foreach (self::ADDED_TOKENS as $name => $designPx) {
            $expectedValue = self::convertedValue($name, $designPx);
            if (isset($root[$name]) && $root[$name] !== $expectedValue) {
                $problems[] = "Added token {$name} ({$designPx}) should be '{$expectedValue}', found '{$root[$name]}'.";
            }
        }

        return $problems;
    }

    /** The application value rules R2 to R4 give for a design value. */
    public static function convertedValue(string $property, string $designValue): string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)px$/D', $designValue, $m) !== 1 || in_array($property, self::R3_TOKENS, true)) {
            return $designValue;
        }
        $rem = rtrim(rtrim(number_format(round((float) $m[1] / 18, 4, PHP_ROUND_HALF_UP), 4, '.', ''), '0'), '.') . 'rem';

        return in_array($property, self::R4_TOKENS, true) ? "max({$designValue}, {$rem})" : $rem;
    }

    /**
     * Every (selector, property) declared in the top-level rule blocks.
     *
     * @return list<string> "selector | property", unique
     */
    private static function declaredPairs(string $css): array
    {
        $pairs = [];
        foreach (CssTokens::rules($css) as $rule) {
            if ($rule['context'] !== []) {
                continue;
            }
            foreach ($rule['declarations'] as [$property]) {
                $pairs["{$rule['selector']} | {$property}"] = true;
            }
        }

        return array_keys($pairs);
    }

    public function testNonColourTokensFollowTheConversionRules(): void
    {
        self::assertSame([], self::nonColourDifferences(RepoFiles::read(self::DESIGN), RepoFiles::read(self::APP)));
    }

    public function testApplicationAdditionsAreExactlyTheSanctionedOnes(): void
    {
        self::assertSame([], self::additionDifferences(RepoFiles::read(self::DESIGN), RepoFiles::read(self::APP)));
    }

    public function testDesignFileHasNonColourTokensToCompare(): void
    {
        $sizes = array_filter(
            CssTokens::customProperties(RepoFiles::read(self::DESIGN))[':root'] ?? [],
            static fn (string $value): bool => str_ends_with($value, 'px'),
        );

        // Not vacuous: the design file's size and shape tokens are compared.
        self::assertGreaterThan(5, count($sizes));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function conversions(): iterable
    {
        yield '18px is 1rem' => ['--text-base', '18px', '1rem'];
        yield '36px is 2rem' => ['--x', '36px', '2rem'];
        yield '34px rounds half up to four decimals' => ['--text-h1', '34px', '1.8889rem'];
        yield '8px' => ['--radius-control', '8px', '0.4444rem'];
        yield 'R3 border stays px' => ['--border-control', '2px', '2px'];
        yield 'R3 pill radius stays px' => ['--radius-pill', '999px', '999px'];
        yield 'R4 floor' => ['--target-min', '44px', 'max(44px, 2.4444rem)'];
        yield 'R4 control height' => ['--control-height', '48px', 'max(48px, 2.6667rem)'];
        yield 'unitless unchanged' => ['--leading', '1.5', '1.5'];
        yield 'font list unchanged' => ['--font-ui', "'Inter', system-ui, sans-serif", "'Inter', system-ui, sans-serif"];
    }

    #[DataProvider('conversions')]
    public function testConversionRule(string $property, string $designValue, string $expected): void
    {
        self::assertSame($expected, self::convertedValue($property, $designValue));
    }

    public function testDesignFileHasColourTokensToCompare(): void
    {
        $colours = CssTokens::colourProperties(RepoFiles::read(self::DESIGN));

        // Guards against a parser regression that would make the parity check vacuous.
        self::assertArrayHasKey(':root', $colours);
        self::assertArrayHasKey('.t-dark', $colours);
        self::assertGreaterThan(10, count($colours[':root']));
    }

    public function testColoursAreIdenticalInBothFiles(): void
    {
        self::assertSame(
            [],
            self::differences(RepoFiles::read(self::DESIGN), RepoFiles::read(self::APP)),
            'A colour token change must be made in both ' . self::DESIGN . ' and ' . self::APP . '.',
        );
    }

    /** @return iterable<string, array{string, callable(string): string}> */
    public static function mutations(): iterable
    {
        $changeFirstColour = static fn (string $css): string => self::replaceOnce(
            '/(--[\w-]+:\s*#[0-9A-Fa-f]{5})([0-9A-Fa-f])/',
            static fn (array $m): string => $m[1] . (strtoupper($m[2]) === '0' ? '1' : '0'),
            $css,
        );
        $removeFirstColour = static fn (string $css): string => self::replaceOnce(
            '/--[\w-]+:\s*#[0-9A-Fa-f]{3,6}\s*;/',
            static fn (array $m): string => '',
            $css,
        );
        $addColour = static fn (string $css): string => self::replaceOnce(
            '/\{/',
            static fn (array $m): string => '{ --parity-extra: #123456;',
            $css,
        );
        $removeLastBlock = static fn (string $css): string => self::replaceOnce(
            '/\.t-hcd\s*\{[^}]*\}/',
            static fn (array $m): string => '',
            $css,
        );

        $wrongRem = static fn (string $css): string => self::replaceOnce(
            '/(--text-small:\s*)[0-9.]+rem/',
            static fn (array $m): string => $m[1] . '0.889rem',
            $css,
        );
        $designPxChanged = static fn (string $css): string => self::replaceOnce(
            '/(--radius-card:\s*)\d+px/',
            static fn (array $m): string => $m[1] . '14px',
            $css,
        );
        $r4AsPlainRem = static fn (string $css): string => self::replaceOnce(
            '/(--target-min:\s*)max\([^;]*\)/',
            static fn (array $m): string => $m[1] . '2.4444rem',
            $css,
        );
        $removeShapeToken = static fn (string $css): string => self::replaceOnce(
            '/--radius-card:[^;]*;/',
            static fn (array $m): string => '',
            $css,
        );
        $extraToken = static fn (string $css): string => self::replaceOnce(
            '/(--bar-min:[^;]*;)/',
            static fn (array $m): string => $m[1] . ' --space-9: 3.5556rem;',
            $css,
        );
        $removeAddedToken = static fn (string $css): string => self::replaceOnce(
            '/--bar-min:[^;]*;/',
            static fn (array $m): string => '',
            $css,
        );
        $wrongAddedValue = static fn (string $css): string => self::replaceOnce(
            '/(--space-8:\s*)[0-9.]+rem/',
            static fn (array $m): string => $m[1] . '2.5rem',
            $css,
        );
        $extraBlock = static fn (string $css): string => $css . "\n.l-tight { --leading: 1.25; }\n";
        $extraDeclaration = static fn (string $css): string => $css . "\n.t-hcl { color-scheme: light; }\n";

        yield 'one colour changed in the design copy' => [self::DESIGN, $changeFirstColour, 'colour'];
        yield 'one colour changed in the application copy' => [self::APP, $changeFirstColour, 'colour'];
        yield 'one colour removed from the design copy' => [self::DESIGN, $removeFirstColour, 'colour'];
        yield 'one colour removed from the application copy' => [self::APP, $removeFirstColour, 'colour'];
        yield 'one colour added to the application copy' => [self::APP, $addColour, 'colour'];
        yield 'one selector block removed from the application copy' => [self::APP, $removeLastBlock, 'colour'];
        yield 'wrong rem value in the application copy' => [self::APP, $wrongRem, 'size'];
        yield 'design px changed without the application copy' => [self::DESIGN, $designPxChanged, 'size'];
        yield 'R4 floor written as plain rem' => [self::APP, $r4AsPlainRem, 'size'];
        yield 'shape token missing from the application copy' => [self::APP, $removeShapeToken, 'size'];
        yield 'unsanctioned extra token' => [self::APP, $extraToken, 'additions'];
        yield 'sanctioned token missing' => [self::APP, $removeAddedToken, 'additions'];
        yield 'sanctioned token with the wrong value' => [self::APP, $wrongAddedValue, 'additions'];
        yield 'unsanctioned extra selector block' => [self::APP, $extraBlock, 'additions'];
        yield 'unsanctioned extra declaration' => [self::APP, $extraDeclaration, 'additions'];
    }

    /** @param callable(string): string $mutate */
    #[DataProvider('mutations')]
    public function testDifferenceInATemporaryCopyIsDetected(string $mutated, callable $mutate, string $check): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tiffindb-parity-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tempDir, 0700)) {
            throw new RuntimeException('Cannot create a temporary directory.');
        }

        $copies = [];
        foreach ([self::DESIGN, self::APP] as $file) {
            $contents = RepoFiles::read($file);
            if ($file === $mutated) {
                $changed = $mutate($contents);
                self::assertNotSame($contents, $changed, 'The mutation must change the copy.');
                $contents = $changed;
            }
            $copy = $this->tempDir . '/' . basename(dirname($file)) . '-' . basename($file);
            file_put_contents($copy, $contents);
            $copies[$file] = $copy;
        }

        $design = (string) file_get_contents($copies[self::DESIGN]);
        $app = (string) file_get_contents($copies[self::APP]);
        $problems = match ($check) {
            'colour' => self::differences($design, $app),
            'size' => self::nonColourDifferences($design, $app),
            'additions' => self::additionDifferences($design, $app),
        };
        self::assertNotSame([], $problems, "The {$check} parity check must report the difference.");
    }

    /** @param callable(array<int, string>): string $replace */
    private static function replaceOnce(string $pattern, callable $replace, string $subject): string
    {
        return (string) preg_replace_callback($pattern, $replace, $subject, 1);
    }
}
