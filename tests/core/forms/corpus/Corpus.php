<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms\Corpus;

/**
 * The sanitizer corpus of M5 spec section 12: helpers for the group files
 * and the loader. Each group file returns a list of cases:
 *
 *     array{id: string, text: string, verdict: 'accept'|'refuse',
 *           reason?: string, line?: int|null, element?: string|null,
 *           attribute?: string|null, canonical?: string,
 *           stage?: 'reader', parse?: string}
 *
 * `line`, `element`, and `attribute` are asserted when the key is present
 * (null included). A case with `stage` 'reader' is given to the parser's
 * reader stage alone (after the byte pre-scan), for a reason the pre-scan
 * makes unreachable; `parse` is then the reason parse() itself gives.
 * `canonical` is the exact stored text of an accepted case. All keys and text are synthetic; every hostile case holds MARKER
 * where its text allows, for the privacy test.
 */
final class Corpus
{
    public const MARKER = 'ZQX-MARKER';
    public const KEY = 'm4pz7k2qsw3n';
    public const KEY2 = 'w2qn6kz7tb4x';

    /** The group files, in the order of the catalogue. */
    public const GROUPS = ['active', 'attributes', 'values', 'links', 'text', 'encoding', 'declarations', 'differential', 'structure', 'limits'];

    private function __construct()
    {
    }

    /** F: the frame, with the content on line 2 (root start tag on line 1). */
    public static function f(string $inner): string
    {
        return '<div data-tiffin-form="1">' . "\n" . $inner . "\n" . '</div>' . "\n";
    }

    /** S: a section with a legend, content after the legend on the same line. */
    public static function s(string $inner): string
    {
        return '<fieldset><legend>Details</legend>' . $inner . '</fieldset>';
    }

    /** The canonical text of a form whose root holds the given block lines (each indented two spaces). */
    public static function canonical(string ...$lines): string
    {
        $out = '<div data-tiffin-form="1">' . "\n";
        foreach ($lines as $line) {
            $out .= '  ' . $line . "\n";
        }

        return $out . '</div>' . "\n";
    }

    /** A field slot for the first synthetic key. */
    public static function slot(string $attributes = ''): string
    {
        return '<div data-tiffin-field="' . self::KEY . '"' . $attributes . '></div>';
    }

    /**
     * Every case of every group, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $cases = [];
        foreach (self::GROUPS as $group) {
            /** @var list<array<string, mixed>> $list */
            $list = (static fn (string $file): array => require $file)(__DIR__ . '/' . $group . '.php');
            foreach ($list as $case) {
                $id = $case['id'];
                if (isset($cases[$id])) {
                    throw new \LogicException('Duplicate corpus id ' . $id);
                }
                $case['group'] = $group;
                $cases[$id] = $case;
            }
        }

        return $cases;
    }
}
