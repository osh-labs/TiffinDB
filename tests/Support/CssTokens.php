<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use LogicException;

/**
 * A small parser for the project's hand-written stylesheets, used by the
 * design tests (token parity, contrast, stylesheet rules, font checks).
 *
 * It understands exactly what those files use: comments, quoted strings,
 * flat rule blocks, statement at-rules (`@import ...;`), at-rules with a
 * declaration block (`@font-face`), and grouping at-rules (`@media`,
 * `@supports`, `@container`, `@layer`) containing rule blocks. Anything it
 * cannot read (unbalanced braces, a declaration without a colon, nested
 * rules inside a rule) throws, so a test never passes on input it did not
 * understand.
 *
 * Node shapes returned by parse():
 *   ['kind' => 'rule', 'selector' => string, 'declarations' => list<array{string, string}>]
 *   ['kind' => 'at', 'name' => string, 'prelude' => string,
 *    'declarations' => list<array{string, string}>|null, 'children' => list<node>|null]
 */
final class CssTokens
{
    /** At-rules whose block holds rules rather than declarations. */
    private const GROUPING_AT_RULES = ['media', 'supports', 'container', 'layer', 'document'];

    private function __construct()
    {
    }

    /** Removes every comment, leaving quoted strings intact. */
    public static function stripComments(string $css): string
    {
        $out = '';
        $length = strlen($css);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $css[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    throw new LogicException('Unterminated comment in stylesheet.');
                }
                $out .= ' ';
                $i = $end + 1;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            }
            $out .= $char;
        }
        if ($quote !== null) {
            throw new LogicException('Unterminated string in stylesheet.');
        }

        return $out;
    }

    /**
     * Parses a stylesheet (comments are stripped first).
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $css): array
    {
        $text = self::stripComments($css);
        $i = 0;

        return self::parseBlockList($text, $i, false);
    }

    /**
     * Every rule block with its at-rule context, nested ones included.
     *
     * @return list<array{selector: string, declarations: list<array{string, string}>, context: list<string>}>
     */
    public static function rules(string $css): array
    {
        $rules = [];
        self::collectRules(self::parse($css), [], $rules);

        return $rules;
    }

    /**
     * Custom properties of the top-level rule blocks (not inside any at-rule),
     * keyed by normalised selector, merged in source order when a selector
     * occurs more than once.
     *
     * @return array<string, array<string, string>>
     */
    public static function customProperties(string $css): array
    {
        $blocks = [];
        foreach (self::rules($css) as $rule) {
            if ($rule['context'] !== []) {
                continue;
            }
            $selector = $rule['selector'];
            $blocks[$selector] ??= [];
            foreach ($rule['declarations'] as [$property, $value]) {
                if (str_starts_with($property, '--')) {
                    $blocks[$selector][$property] = $value;
                }
            }
        }

        return $blocks;
    }

    /**
     * Custom properties whose value is a hex colour, keyed by selector.
     *
     * @return array<string, array<string, string>>
     */
    public static function colourProperties(string $css): array
    {
        $blocks = [];
        foreach (self::customProperties($css) as $selector => $properties) {
            $colours = array_filter($properties, self::isHexColour(...));
            if ($colours !== []) {
                $blocks[$selector] = $colours;
            }
        }

        return $blocks;
    }

    public static function isHexColour(string $value): bool
    {
        return preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/D', $value) === 1;
    }

    /**
     * The custom properties in effect on the root element when it carries
     * the given classes, resolved by the cascade (specificity, then source
     * order) over the top-level rule blocks.
     *
     * Only selectors made of `:root` or `html` and classes are understood;
     * a block that declares custom properties under any other selector
     * throws, because its effect on the root element cannot be decided here.
     *
     * @param list<string> $classes
     * @return array<string, string>
     */
    public static function resolve(string $css, array $classes): array
    {
        $applicable = [];
        $order = 0;
        foreach (self::rules($css) as $rule) {
            $order++;
            if ($rule['context'] !== []) {
                continue;
            }
            $custom = array_values(array_filter(
                $rule['declarations'],
                static fn (array $declaration): bool => str_starts_with($declaration[0], '--'),
            ));
            if ($custom === []) {
                continue;
            }
            $best = null;
            foreach (explode(',', $rule['selector']) as $compound) {
                $specificity = self::matchRootCompound(trim($compound), $classes);
                if ($specificity !== null && ($best === null || $specificity > $best)) {
                    $best = $specificity;
                }
            }
            if ($best !== null) {
                $applicable[] = [$best, $order, $custom];
            }
        }

        usort($applicable, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $resolved = [];
        foreach ($applicable as [, , $declarations]) {
            foreach ($declarations as [$property, $value]) {
                $resolved[$property] = $value;
            }
        }

        return $resolved;
    }

    /** Collapses whitespace and normalises the separators of a selector list. */
    public static function normaliseSelector(string $selector): string
    {
        $selector = trim((string) preg_replace('/\s+/', ' ', $selector));

        return (string) preg_replace('/\s*,\s*/', ', ', $selector);
    }

    /**
     * Specificity (scaled as classes and pseudo-classes * 100 + elements) if
     * the compound selector matches the root element with these classes,
     * otherwise null.
     *
     * @param list<string> $classes
     */
    private static function matchRootCompound(string $compound, array $classes): ?int
    {
        if (preg_match('/^(:root|html)?((?:\.[A-Za-z0-9_-]+)*)$/D', $compound, $m) !== 1 || $compound === '') {
            throw new LogicException("Unsupported selector for token resolution: '{$compound}'.");
        }
        $required = $m[2] === '' ? [] : explode('.', ltrim($m[2], '.'));
        foreach ($required as $class) {
            if (!in_array($class, $classes, true)) {
                return null;
            }
        }
        $specificity = 100 * count($required);
        if ($m[1] === ':root') {
            $specificity += 100;
        } elseif ($m[1] === 'html') {
            $specificity += 1;
        }

        return $specificity;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $context
     * @param list<array{selector: string, declarations: list<array{string, string}>, context: list<string>}> $rules
     */
    private static function collectRules(array $nodes, array $context, array &$rules): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] === 'rule') {
                $rules[] = ['selector' => $node['selector'], 'declarations' => $node['declarations'], 'context' => $context];
            } elseif ($node['children'] !== null) {
                self::collectRules($node['children'], [...$context, '@' . $node['name'] . ' ' . $node['prelude']], $rules);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private static function parseBlockList(string $text, int &$i, bool $nested): array
    {
        $nodes = [];
        $buffer = '';
        $length = strlen($text);
        while ($i < $length) {
            $char = $text[$i];
            if ($char === '"' || $char === "'") {
                $buffer .= self::readString($text, $i);
                continue;
            }
            if ($char === '{') {
                $i++;
                $prelude = trim($buffer);
                $buffer = '';
                if (str_starts_with($prelude, '@')) {
                    [$name, $rest] = self::splitAtRule($prelude);
                    if (in_array($name, self::GROUPING_AT_RULES, true)) {
                        $children = self::parseBlockList($text, $i, true);
                        $nodes[] = ['kind' => 'at', 'name' => $name, 'prelude' => $rest, 'declarations' => null, 'children' => $children];
                    } else {
                        $nodes[] = ['kind' => 'at', 'name' => $name, 'prelude' => $rest, 'declarations' => self::parseDeclarations(self::readBody($text, $i)), 'children' => null];
                    }
                } else {
                    if ($prelude === '') {
                        throw new LogicException('Rule block without a selector.');
                    }
                    $nodes[] = ['kind' => 'rule', 'selector' => self::normaliseSelector($prelude), 'declarations' => self::parseDeclarations(self::readBody($text, $i))];
                }
                continue;
            }
            if ($char === ';') {
                $i++;
                $statement = trim($buffer);
                $buffer = '';
                if (!str_starts_with($statement, '@')) {
                    throw new LogicException("Stray text outside a block: '{$statement}'.");
                }
                [$name, $rest] = self::splitAtRule($statement);
                $nodes[] = ['kind' => 'at', 'name' => $name, 'prelude' => $rest, 'declarations' => null, 'children' => null];
                continue;
            }
            if ($char === '}') {
                if (!$nested || trim($buffer) !== '') {
                    throw new LogicException('Unbalanced closing brace in stylesheet.');
                }
                $i++;

                return $nodes;
            }
            $buffer .= $char;
            $i++;
        }
        if ($nested) {
            throw new LogicException('Unclosed block in stylesheet.');
        }
        if (trim($buffer) !== '') {
            throw new LogicException('Unterminated text at the end of the stylesheet.');
        }

        return $nodes;
    }

    /** Reads a declaration block body up to its closing brace (consumed). */
    private static function readBody(string $text, int &$i): string
    {
        $body = '';
        $length = strlen($text);
        while ($i < $length) {
            $char = $text[$i];
            if ($char === '"' || $char === "'") {
                $body .= self::readString($text, $i);
                continue;
            }
            if ($char === '{') {
                throw new LogicException('Nested block inside a declaration block.');
            }
            if ($char === '}') {
                $i++;

                return $body;
            }
            $body .= $char;
            $i++;
        }
        throw new LogicException('Unclosed declaration block in stylesheet.');
    }

    /** Reads a quoted string starting at $i, returning it with its quotes. */
    private static function readString(string $text, int &$i): string
    {
        $quote = $text[$i];
        $out = $quote;
        $i++;
        $length = strlen($text);
        while ($i < $length) {
            $char = $text[$i];
            $out .= $char;
            $i++;
            if ($char === '\\' && $i < $length) {
                $out .= $text[$i];
                $i++;
            } elseif ($char === $quote) {
                return $out;
            }
        }
        throw new LogicException('Unterminated string in stylesheet.');
    }

    /** @return array{string, string} lower-case at-rule name and the rest of its prelude */
    private static function splitAtRule(string $prelude): array
    {
        if (preg_match('/^@([A-Za-z-]+)\s*(.*)$/sD', $prelude, $m) !== 1) {
            throw new LogicException("Malformed at-rule: '{$prelude}'.");
        }

        return [strtolower($m[1]), trim($m[2])];
    }

    /** @return list<array{string, string}> */
    private static function parseDeclarations(string $body): array
    {
        $declarations = [];
        foreach (self::splitTopLevel($body, ';') as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $colon = strpos($part, ':');
            if ($colon === false) {
                throw new LogicException("Declaration without a colon: '{$part}'.");
            }
            $property = trim(substr($part, 0, $colon));
            if (!str_starts_with($property, '--')) {
                $property = strtolower($property);
            }
            $declarations[] = [$property, trim(substr($part, $colon + 1))];
        }

        return $declarations;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $text, string $separator): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $char = $text[$i];
            if ($char === '"' || $char === "'") {
                $current .= self::readString($text, $i);
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';
                $i++;
                continue;
            }
            $current .= $char;
            $i++;
        }
        $parts[] = $current;

        return $parts;
    }
}
