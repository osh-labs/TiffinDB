<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

/**
 * The rows of the appearance matrix (V-30; M5 spec part 2 section 10.1),
 * built deterministically with no randomness:
 *
 * 1. Candidates: every combination of the five settings (theme, accent,
 *    typeface, text size, line spacing, in the order of VALUES), except
 *    that the two high-contrast themes take only the default accent, in
 *    lexicographic order of the value lists (900 candidates).
 * 2. Required pairs: every pair of values of two different settings,
 *    except a high-contrast theme with a non-default accent.
 * 3. Until every required pair is covered: the first candidate, in order,
 *    that covers the most uncovered pairs is added.
 * 4. The five extremes of EXTREMES are added (unless already a row).
 *
 * The values are the `user_settings` values (Appearance::SETTING_COLUMNS);
 * a high-contrast row carries the default accent, which the server omits.
 */
final class AppearanceMatrix
{
    /** Setting (user_settings column) => values, the default where the plan lists it. */
    public const VALUES = [
        'theme' => ['light', 'dark', 'hc-light', 'hc-dark'],
        'accent' => ['teal', 'indigo', 'plum', 'graphite'],
        'typeface' => ['inter', 'atkinson', 'lexend', 'source-serif', 'device-sans', 'device-serif'],
        'text_size' => ['small', 'standard', 'large', 'larger', 'largest'],
        'line_spacing' => ['standard', 'roomy', 'very-roomy'],
    ];

    public const HIGH_CONTRAST = ['hc-light', 'hc-dark'];

    public const DEFAULT_ACCENT = 'teal';

    /** The five extremes of section 10.1 step 4. */
    public const EXTREMES = [
        ['theme' => 'light', 'accent' => 'teal', 'typeface' => 'inter', 'text_size' => 'largest', 'line_spacing' => 'very-roomy'],
        ['theme' => 'hc-dark', 'accent' => 'teal', 'typeface' => 'source-serif', 'text_size' => 'largest', 'line_spacing' => 'very-roomy'],
        ['theme' => 'dark', 'accent' => 'plum', 'typeface' => 'lexend', 'text_size' => 'small', 'line_spacing' => 'standard'],
        ['theme' => 'hc-light', 'accent' => 'teal', 'typeface' => 'atkinson', 'text_size' => 'larger', 'line_spacing' => 'roomy'],
        ['theme' => 'light', 'accent' => 'graphite', 'typeface' => 'device-serif', 'text_size' => 'largest', 'line_spacing' => 'standard'],
    ];

    /** @return list<array<string, string>> every candidate, in lexicographic order */
    public static function candidates(): array
    {
        $rows = [[]];
        foreach (self::VALUES as $setting => $values) {
            $next = [];
            foreach ($rows as $row) {
                foreach ($values as $value) {
                    if ($setting === 'accent' && in_array($row['theme'], self::HIGH_CONTRAST, true) && $value !== self::DEFAULT_ACCENT) {
                        continue;
                    }
                    $next[] = $row + [$setting => $value];
                }
            }
            $rows = $next;
        }

        return $rows;
    }

    /** @return array<string, true> every required pair, as "setting=value|setting=value" (settings in VALUES order) */
    public static function requiredPairs(): array
    {
        $settings = array_keys(self::VALUES);
        $pairs = [];
        foreach ($settings as $i => $a) {
            foreach (array_slice($settings, $i + 1) as $b) {
                foreach (self::VALUES[$a] as $va) {
                    foreach (self::VALUES[$b] as $vb) {
                        if ($a === 'theme' && $b === 'accent' && in_array($va, self::HIGH_CONTRAST, true) && $vb !== self::DEFAULT_ACCENT) {
                            continue;
                        }
                        $pairs[$a . '=' . $va . '|' . $b . '=' . $vb] = true;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * The pairs a row covers.
     *
     * @param array<string, string> $row
     * @return list<string>
     */
    public static function pairsOf(array $row): array
    {
        $settings = array_keys(self::VALUES);
        $pairs = [];
        foreach ($settings as $i => $a) {
            foreach (array_slice($settings, $i + 1) as $b) {
                $pairs[] = $a . '=' . $row[$a] . '|' . $b . '=' . $row[$b];
            }
        }

        return $pairs;
    }

    /** @return list<array<string, string>> the rows of the matrix, greedy rows first, then the extremes */
    public static function rows(): array
    {
        $uncovered = self::requiredPairs();
        $candidates = self::candidates();
        $rows = [];
        while ($uncovered !== []) {
            $best = null;
            $bestCount = 0;
            foreach ($candidates as $index => $candidate) {
                $count = 0;
                foreach (self::pairsOf($candidate) as $pair) {
                    $count += isset($uncovered[$pair]) ? 1 : 0;
                }
                if ($count > $bestCount) {
                    $best = $index;
                    $bestCount = $count;
                }
            }
            if ($best === null) {
                throw new \LogicException('A required pair no candidate covers.');
            }
            $rows[] = $candidates[$best];
            foreach (self::pairsOf($candidates[$best]) as $pair) {
                unset($uncovered[$pair]);
            }
        }
        foreach (self::EXTREMES as $extreme) {
            if (!in_array($extreme, $rows, true)) {
                $rows[] = $extreme;
            }
        }

        return $rows;
    }
}
