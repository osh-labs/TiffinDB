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
use TiffinDB\Tests\Support\CssTokens;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-DES-4: the px and rem rule (M0 spec section 8.1, R1 to R6) and the
 * no-network rule for the stylesheets. Comments are stripped before every
 * check.
 *
 * The size rules apply to tokens.css and app.css. app.css is written by
 * another work package; until it exists, its data sets are reported in the
 * test name as not present and check only that absence, and they apply the
 * full rules automatically once the file is added.
 */
#[CoversNothing]
final class StylesheetRulesTest extends TestCase
{
    private const CSS_DIR = 'public/assets/css/';
    private const TOKENS = 'tokens.css';
    private const APP = 'app.css';
    private const FONTS = 'fonts.css';

    /** R3 and R4: the only px lengths allowed. */
    private const ALLOWED_PX = ['1', '2', '3', '999', '44', '48'];

    /** R4: px lengths allowed only as the floor inside max(). */
    private const PX_ONLY_IN_MAX = ['44', '48'];

    /** T-DES-4: absolute and viewport units that must not occur. */
    private const FORBIDDEN_UNITS = ['pt', 'cm', 'mm', 'in', 'pc', 'vw', 'vmin'];

    /** R1: root font-size per text-size class ('' is Standard, no class). */
    private const ROOT_STEPS = [
        '' => '112.5%',
        's-small' => '100%',
        's-large' => '131.25%',
        's-larger' => '150%',
        's-largest' => '175%',
    ];

    /**
     * Violations of the size rules in tokens.css and app.css.
     *
     * @return list<string>
     */
    public static function sizeViolations(string $css): array
    {
        $problems = [];
        foreach (self::segments($css) as $segment) {
            if ($segment['kind'] === 'declaration') {
                $where = "'{$segment['selector']}' {$segment['property']}: {$segment['text']}";
                if ($segment['property'] === 'zoom') {
                    $problems[] = "zoom is not allowed ({$where}).";
                }
                if ($segment['property'] === 'font-size') {
                    $problem = self::fontSizeProblem($segment['selector'], $segment['text']);
                    if ($problem !== null) {
                        $problems[] = "{$problem} ({$where}).";
                    }
                }
            } else {
                $where = $segment['kind'] === 'selector' ? "selector '{$segment['text']}'" : "'@{$segment['name']} {$segment['text']}'";
            }
            if ($segment['kind'] === 'at' && $segment['name'] === 'import') {
                $problems[] = '@import is not allowed.';
            }

            foreach (self::lengths($segment['text']) as [$number, $unit, $functions]) {
                if ($unit === 'px') {
                    if (!in_array($number, self::ALLOWED_PX, true)) {
                        $problems[] = "{$number}px is not an allowed px length ({$where}).";
                    } elseif (in_array($number, self::PX_ONLY_IN_MAX, true) && !in_array('max', $functions, true)) {
                        $problems[] = "{$number}px may occur only inside max() ({$where}).";
                    }
                } elseif ($unit === 'em') {
                    $allowed = ($segment['kind'] === 'declaration' && $segment['property'] === 'letter-spacing')
                        || ($segment['kind'] === 'at' && $segment['name'] === 'media');
                    if (!$allowed) {
                        $problems[] = "em lengths are allowed only in letter-spacing and media queries ({$where}).";
                    }
                } elseif (in_array($unit, self::FORBIDDEN_UNITS, true)) {
                    $problems[] = "The unit {$unit} is not allowed ({$where}).";
                }
            }
        }

        $text = CssTokens::stripComments($css);
        if (preg_match('/@import\b/i', $text) === 1 && !in_array('@import is not allowed.', $problems, true)) {
            $problems[] = '@import is not allowed.';
        }
        if (preg_match('/url\s*\(/i', $text) === 1) {
            $problems[] = 'url( is not allowed in this stylesheet.';
        }

        return $problems;
    }

    /**
     * Declarations in app.css of a colour token defined in tokens.css.
     *
     * @return list<string>
     */
    public static function colourTokenRedefinitions(string $appCss, string $tokensCss): array
    {
        $colourTokens = [];
        foreach (CssTokens::colourProperties($tokensCss) as $properties) {
            foreach (array_keys($properties) as $name) {
                $colourTokens[$name] = true;
            }
        }

        $problems = [];
        foreach (CssTokens::rules($appCss) as $rule) {
            foreach ($rule['declarations'] as [$property]) {
                if (isset($colourTokens[$property])) {
                    $problems[] = "'{$rule['selector']}' redefines the colour token {$property}.";
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    public static function networkViolations(string $css): array
    {
        $count = preg_match_all('/https?:/i', CssTokens::stripComments($css));

        return $count > 0 ? ["{$count} http: or https: reference(s) found."] : [];
    }

    /**
     * Root font-size declarations, as [text-size class, value] pairs.
     *
     * @return list<array{string, string}>
     */
    public static function rootSteps(string $css): array
    {
        $steps = [];
        foreach (CssTokens::rules($css) as $rule) {
            foreach (explode(',', $rule['selector']) as $compound) {
                $class = self::rootStepClass(trim($compound));
                if ($class === null) {
                    continue;
                }
                foreach ($rule['declarations'] as [$property, $value]) {
                    if ($property === 'font-size') {
                        $steps[] = [$class, $value];
                    }
                }
            }
        }

        return $steps;
    }

    /** @return iterable<string, array{string}> */
    public static function sizeRuleFiles(): iterable
    {
        foreach ([self::TOKENS, self::APP] as $file) {
            yield self::label($file) => [$file];
        }
    }

    #[DataProvider('sizeRuleFiles')]
    public function testSizeRules(string $file): void
    {
        if (!self::exists($file)) {
            self::assertFileDoesNotExist(RepoFiles::path(self::CSS_DIR . $file));

            return;
        }

        self::assertSame([], self::sizeViolations(RepoFiles::read(self::CSS_DIR . $file)), $file);
    }

    /** @return iterable<string, array{string}> */
    public static function allStylesheets(): iterable
    {
        foreach ([self::FONTS, self::TOKENS, self::APP] as $file) {
            yield self::label($file) => [$file];
        }
    }

    #[DataProvider('allStylesheets')]
    public function testNoNetworkReferences(string $file): void
    {
        if (!self::exists($file)) {
            self::assertFileDoesNotExist(RepoFiles::path(self::CSS_DIR . $file));

            return;
        }

        self::assertSame([], self::networkViolations(RepoFiles::read(self::CSS_DIR . $file)), $file);
    }

    /** @return iterable<string, array{string}> */
    public static function applicationStylesheet(): iterable
    {
        yield self::label(self::APP) => [self::APP];
    }

    #[DataProvider('applicationStylesheet')]
    public function testAppDoesNotRedefineColourTokens(string $file): void
    {
        if (!self::exists($file)) {
            self::assertFileDoesNotExist(RepoFiles::path(self::CSS_DIR . $file));

            return;
        }

        self::assertSame(
            [],
            self::colourTokenRedefinitions(RepoFiles::read(self::CSS_DIR . $file), RepoFiles::read(self::CSS_DIR . self::TOKENS)),
        );
    }

    public function testFontsAndTokensStylesheetsArePresent(): void
    {
        // The two stylesheets the rules must always cover.
        self::assertTrue(self::exists(self::TOKENS));
        self::assertTrue(self::exists(self::FONTS));
    }

    public function testRootFontSizeStepsAreExactlyTheFiveOfRuleR1(): void
    {
        $steps = [];
        foreach ([self::TOKENS, self::APP] as $file) {
            if (self::exists($file)) {
                array_push($steps, ...self::rootSteps(RepoFiles::read(self::CSS_DIR . $file)));
            }
        }

        self::assertSame(self::expectedRootSteps(), self::sortedSteps($steps));
    }

    /** @return iterable<string, array{string}> */
    public static function sizeViolationCases(): iterable
    {
        yield 'px font size' => ['a { font-size: 16px; }'];
        yield 'em font size' => ['a { font-size: 1.2em; }'];
        yield 'keyword font size' => ['a { font-size: larger; }'];
        yield 'viewport font size' => ['a { font-size: 3vh; }'];
        yield 'percentage font size off the root' => ['a { font-size: 120%; }'];
        yield 'root percentage outside rule R1' => [':root { font-size: 120%; }'];
        yield 'R1 percentage on a non-root selector' => ['.s-large { font-size: 131.25%; }'];
        yield 'calc font size' => ['a { font-size: calc(1rem + 1px); }'];
        yield 'zoom' => ['a { zoom: 1.25; }'];
        yield 'px length outside the allowed set' => ['a { margin: 5px; }'];
        yield 'fractional px' => ['a { border-width: 1.5px; }'];
        yield '44px outside max()' => ['a { min-height: 44px; }'];
        yield '48px inside min()' => ['a { min-height: min(48px, 2.6667rem); }'];
        yield 'em padding' => ['a { padding: 0.5em; }'];
        yield 'em inside a media block declaration' => ['@media (min-width: 40em) { a { padding: 1em; } }'];
        yield 'px media query' => ['@media (min-width: 640px) { a { color: red; } }'];
        foreach (self::FORBIDDEN_UNITS as $unit) {
            yield "{$unit} length" => ["a { width: 2{$unit}; }"];
        }
        yield 'upper-case unit' => ['a { width: 2PT; }'];
        yield '@import' => ["@import 'other.css';"];
        yield 'url()' => ['a { background: url(picture.png); }'];
        yield 'url() in a custom property' => [':root { --picture: URL("picture.png"); }'];
    }

    #[DataProvider('sizeViolationCases')]
    public function testSizeViolationIsDetected(string $css): void
    {
        self::assertNotSame([], self::sizeViolations($css));
    }

    public function testAllowedConstructsPass(): void
    {
        $css = <<<'CSS'
            /* font-size: 16px; zoom: 2; @import 'x.css'; url(x) 5px 1em 2pt */
            :root { font-size: 112.5%; --text-base: 1rem; --pill: 999px; --target: max(44px, 2.4444rem); }
            :root.s-small { font-size: 100%; }
            html.s-largest { font-size: 175%; }
            a { font-size: var(--text-base); border: 1px solid; outline: 3px solid; outline-offset: 2px; margin: 0; }
            b { font-size: 0.8889rem; letter-spacing: -0.025em; min-height: max(48px, 2.6667rem); width: 100%; }
            .page { min-height: 100vh; min-height: 100dvh; grid-template-columns: 1fr 2fr; font-family: 'Source Serif 4', serif; }
            @media (min-width: 40em) { a { border-width: 2px; } }
            CSS;

        self::assertSame([], self::sizeViolations($css));
    }

    public function testColourTokenRedefinitionIsDetected(): void
    {
        $tokens = ':root { --bg: #FFFFFF; --leading: 1.5; } .t-dark { --side: #000000; }';

        self::assertNotSame([], self::colourTokenRedefinitions('a { --bg: #000000; }', $tokens));
        self::assertNotSame([], self::colourTokenRedefinitions('@media (min-width: 40em) { .x { --side: red; } }', $tokens));
        self::assertSame([], self::colourTokenRedefinitions('a { --leading: 2; color: var(--bg); }', $tokens));
    }

    /** @return iterable<string, array{string}> */
    public static function networkCases(): iterable
    {
        yield 'https url' => ["@font-face { src: url('https://fonts.example.com/a.woff2'); }"];
        yield 'http url' => ['a { background: url(http://example.com/x.png); }'];
        yield 'upper case scheme' => ['a { --x: "HTTPS://example.com"; }'];
    }

    #[DataProvider('networkCases')]
    public function testNetworkReferenceIsDetected(string $css): void
    {
        self::assertNotSame([], self::networkViolations($css));
    }

    public function testNetworkReferenceInACommentIsIgnored(): void
    {
        self::assertSame([], self::networkViolations("/* see https://example.com */ a { color: red; }"));
    }

    /** @return iterable<string, array{string}> */
    public static function wrongRootSteps(): iterable
    {
        $all = ':root { font-size: 112.5%; } :root.s-small { font-size: 100%; } :root.s-large { font-size: 131.25%; } '
            . ':root.s-larger { font-size: 150%; } :root.s-largest { font-size: 175%; }';

        yield 'one step missing' => [str_replace(':root.s-larger { font-size: 150%; } ', '', $all)];
        yield 'one step with another value' => [str_replace('175%', '180%', $all)];
        yield 'standard step in px' => [str_replace('112.5%', '18px', $all)];
        yield 'one step declared twice' => [$all . ' :root.s-small { font-size: 100%; }'];
        yield 'an extra step' => [$all . ' :root.s-huge { font-size: 200%; }'];
    }

    #[DataProvider('wrongRootSteps')]
    public function testWrongRootStepsAreDetected(string $css): void
    {
        self::assertNotSame(self::expectedRootSteps(), self::sortedSteps(self::rootSteps($css)));
    }

    /** @return list<array{string, string}> */
    private static function expectedRootSteps(): array
    {
        $expected = [];
        foreach (self::ROOT_STEPS as $class => $value) {
            $expected[] = [(string) $class, $value];
        }

        return self::sortedSteps($expected);
    }

    /**
     * @param list<array{string, string}> $steps
     * @return list<array{string, string}>
     */
    private static function sortedSteps(array $steps): array
    {
        usort($steps, static fn (array $a, array $b): int => $a <=> $b);

        return $steps;
    }

    private static function fontSizeProblem(string $selector, string $value): ?string
    {
        if (preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)rem$/', $value) === 1) {
            return null;
        }
        if (preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*\)$/', $value) === 1) {
            return null;
        }
        if (str_ends_with($value, '%')) {
            foreach (explode(',', $selector) as $compound) {
                $class = self::rootStepClass(trim($compound));
                if ($class !== null && (self::ROOT_STEPS[$class] ?? null) === $value) {
                    continue;
                }

                return 'A percentage font-size is allowed only as a rule R1 step on the root element';
            }

            return null;
        }

        return 'font-size must be a rem length or a var()';
    }

    /**
     * The text-size class of a root-element compound selector (`:root` or
     * `html`, optionally with one `.s-*` class; '' for none), or null if the
     * selector is not of that form.
     */
    private static function rootStepClass(string $compound): ?string
    {
        if (preg_match('/^(?::root|html)(?:\.(s-[a-z-]+))?$/', $compound, $m) !== 1) {
            return null;
        }

        return $m[1] ?? '';
    }

    /**
     * Every length in a piece of CSS text, with the functions it is nested in.
     *
     * @return list<array{string, string, list<string>}> number, lower-case unit, enclosing function names
     */
    private static function lengths(string $text): array
    {
        $lengths = [];
        $count = preg_match_all('/(?<![\w.#-])-?((?:\d+(?:\.\d+)?|\.\d+))([A-Za-z]+|%)?/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return [];
        }
        foreach ($matches as $match) {
            $unit = isset($match[2]) ? strtolower($match[2][0]) : '';
            if ($unit === '' || $unit === '%') {
                continue;
            }
            $lengths[] = [$match[1][0], $unit, self::enclosingFunctions($text, $match[0][1])];
        }

        return $lengths;
    }

    /** @return list<string> lower-case names of the functions open at $offset */
    private static function enclosingFunctions(string $text, int $offset): array
    {
        $stack = [];
        for ($i = 0; $i < $offset; $i++) {
            if ($text[$i] === '(') {
                preg_match('/([A-Za-z-]*)$/', substr($text, 0, $i), $m);
                $stack[] = strtolower($m[1] ?? '');
            } elseif ($text[$i] === ')') {
                array_pop($stack);
            }
        }

        return $stack;
    }

    /**
     * The parsed stylesheet as a flat list of checkable pieces of text.
     *
     * @return list<array{kind: string, text: string, name?: string, selector?: string, property?: string}>
     */
    private static function segments(string $css): array
    {
        $segments = [];
        self::collectSegments(CssTokens::parse($css), $segments);

        return $segments;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array{kind: string, text: string, name?: string, selector?: string, property?: string}> $segments
     */
    private static function collectSegments(array $nodes, array &$segments): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] === 'rule') {
                $segments[] = ['kind' => 'selector', 'text' => $node['selector']];
                $selector = $node['selector'];
                $declarations = $node['declarations'];
            } else {
                $segments[] = ['kind' => 'at', 'name' => $node['name'], 'text' => $node['prelude']];
                $selector = '@' . $node['name'];
                $declarations = $node['declarations'] ?? [];
                if ($node['children'] !== null) {
                    self::collectSegments($node['children'], $segments);
                }
            }
            foreach ($declarations as [$property, $value]) {
                $segments[] = ['kind' => 'declaration', 'selector' => $selector, 'property' => $property, 'text' => $value];
            }
        }
    }

    private static function exists(string $file): bool
    {
        return is_file(RepoFiles::path(self::CSS_DIR . $file));
    }

    private static function label(string $file): string
    {
        return self::exists($file) ? $file : "{$file} (not present yet; nothing checked)";
    }
}
