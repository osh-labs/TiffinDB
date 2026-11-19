<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\Type\ChoiceType;
use TiffinDB\Core\Schema\Type\LongTextType;
use TiffinDB\Core\Schema\Type\TextType;

/**
 * Type inference for import columns (M3 spec sections 6.3 and 6.4, P117,
 * P138): the first rule of the section 6.3 table that holds proposes the
 * type; the type menu, field names, and options for new fields follow
 * sections 6.3 and 6.4. Pure: reads only the statistics, the heading, and
 * the link targets it is given.
 */
final class Inference
{
    /** Types a column can be imported as (every editable type except auto-number, relation, and attachment). */
    public const IMPORTABLE = ['text', 'long_text', 'number', 'currency', 'rating', 'duration', 'date', 'time', 'date_time', 'checkbox', 'choice', 'url', 'email', 'phone'];
    public const LINK_PREFIX = 'link:';
    /** P138: a heading holding one of these whole words looks like money. */
    public const MONEY_WORDS = '/(?:^| )(?:price|cost|value|amount|total|paid|spent|fee|budget|balance|money|salary|wage)(?: |\z)/';
    public const MAX_FIELD_NAME = 60;
    public const COLUMN_PREFIX = 'Column ';
    /** Order of the "every cell would be read" part of the type menu. */
    private const MENU_ORDER = ['checkbox', 'date', 'date_time', 'time', 'currency', 'number', 'duration', 'email', 'url', 'rating', 'choice'];
    private const SYMBOL_CODES = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY'];

    private function __construct()
    {
    }

    /**
     * Guesses for every column, with field names made unique (ASCII
     * case-insensitive) by " 2", " 3".
     *
     * @param list<string> $headings
     * @param list<ColumnStats> $stats
     * @param list<LinkTarget> $targets
     * @return list<TypeGuess>
     */
    public static function guessAll(array $headings, array $stats, array $targets): array
    {
        $guesses = [];
        $used = [];
        foreach ($headings as $index => $heading) {
            $guess = self::guess($heading, $stats[$index] ?? new ColumnStats(), $targets, $index);
            $name = self::unique($guess->fieldName, $used);
            $used[strtolower($name)] = true;
            $guesses[] = new TypeGuess($name, $guess->type, $guess->options, $guess->typeChoices, $guess->include);
        }

        return $guesses;
    }

    /**
     * The guess for one column ($index is 0-based, used for "Column n").
     *
     * @param list<LinkTarget> $targets
     */
    public static function guess(string $heading, ColumnStats $s, array $targets, int $index = 0): TypeGuess
    {
        $type = self::propose($heading, $s, $targets);
        $menu = [$type];
        foreach (self::offered($s) as $other) {
            $menu[] = $other;
        }
        foreach ($targets as $target) {
            $menu[] = self::LINK_PREFIX . $target->tableKey;
        }
        $menu = array_values(array_unique($menu));

        return new TypeGuess(self::fieldName($heading, $index), $type, self::optionsFor($type, $s), $menu, !self::isIdLike($heading, $s));
    }

    /** @param list<LinkTarget> $targets */
    private static function propose(string $heading, ColumnStats $s, array $targets): string
    {
        $count = $s->count;
        if ($count === 0) {
            return 'text';
        }
        $normal = self::normaliseHeading($heading);
        foreach ($targets as $target) {
            if ($normal !== '' && ($normal === strtolower(Text::trim($target->name)) || $normal === strtolower(Text::trim($target->itemName)))) {
                return self::LINK_PREFIX . $target->tableKey;
            }
        }
        foreach (['checkbox', 'date', 'date_time', 'time'] as $type) {
            if ($s->counter($type) === $count) {
                return $type;
            }
        }
        if (self::currencyProposed($heading, $s)) {
            return 'currency';
        }
        foreach (['number', 'duration', 'email', 'url'] as $type) {
            if ($s->counter($type) === $count) {
                return $type;
            }
        }
        if ($s->multiline || $s->maxLength > 500) {
            return 'long_text';
        }
        if (!$s->moreDistinct && $s->distinct() <= 20 && $s->distinct() * 2 <= $count && $s->maxLength <= 60) {
            return 'choice';
        }

        return 'text';
    }

    /** Rule 7 with section 6.3.1 and P138. */
    private static function currencyProposed(string $heading, ColumnStats $s): bool
    {
        if ($s->count === 0 || $s->counter('currency') !== $s->count || count($s->symbols) > 1) {
            return false;
        }
        if (count($s->symbols) === 1) {
            return true;
        }

        // P138: a money word in the heading proposes currency when every cell reads as an amount; two decimals
        // alone propose a number with two decimals (rule 8).
        return self::isMoneyHeading($heading);
    }

    /**
     * The type menu after the proposed type: types every cell would be read
     * as (in MENU_ORDER), then text and long text, then every other
     * importable type, phone included.
     *
     * @return list<string>
     */
    private static function offered(ColumnStats $s): array
    {
        $count = $s->count;
        $types = [];
        foreach (self::MENU_ORDER as $type) {
            $ok = match ($type) {
                'currency' => $s->counter('currency') === $count && count($s->symbols) <= 1,
                'rating' => $s->counter('integer1to5') === $count,
                'choice' => !$s->moreDistinct && $s->distinct() <= ChoiceType::MAX_CHOICES && $s->maxLength <= ChoiceType::MAX_LABEL,
                default => $s->counter($type) === $count,
            };
            if ($ok) {
                $types[] = $type;
            }
        }
        $types[] = 'text';
        $types[] = 'long_text';
        // Every other importable type follows (M3-WP11 X-9, X-14): the Admin may choose a type some cells do not
        // fit, and the review warns how many values will be left empty.
        foreach (self::IMPORTABLE as $type) {
            $types[] = $type;
        }

        return array_values(array_unique($types));
    }

    /** Options for a new field of $type built from the column (section 6.4); [] for a link. */
    public static function optionsFor(string $type, ColumnStats $s): array
    {
        return match ($type) {
            'text' => ['max_length' => min(TextType::MAX_LENGTH_MAX, max(TextType::MAX_LENGTH_DEFAULT, $s->maxLength))],
            'long_text' => ['max_length' => min(LongTextType::MAX_LENGTH_MAX, max(LongTextType::MAX_LENGTH_DEFAULT, $s->maxLength))],
            'number' => ['decimals' => min(6, $s->decimals)],
            'currency' => self::currencyOptions($s),
            'rating' => ['max' => 5],
            'choice' => ['choices' => self::choiceLabels($s)],
            default => [],
        };
    }

    /** @return array{code: string, minor_digits: int} */
    public static function currencyOptions(ColumnStats $s): array
    {
        $code = count($s->symbols) === 1 ? (self::SYMBOL_CODES[$s->symbols[0]] ?? 'USD') : 'USD';
        $digits = $code === 'JPY' ? 0 : ($s->currencyDecimals === 0 ? 2 : min(4, $s->currencyDecimals));

        return ['code' => $code, 'minor_digits' => $digits];
    }

    /** @return list<string> the distinct values, first seen first, without ASCII case-insensitive repeats */
    public static function choiceLabels(ColumnStats $s): array
    {
        $labels = [];
        $seen = [];
        foreach ($s->distinctValues as $value) {
            $folded = strtolower($value);
            if (!isset($seen[$folded])) {
                $seen[$folded] = true;
                $labels[] = $value;
            }
        }

        return $labels;
    }

    /** Whether the choice labels of the column fit a choice field (at most 200 distinct values). */
    public static function choicesFit(ColumnStats $s): bool
    {
        return !$s->moreDistinct && count(self::choiceLabels($s)) <= ChoiceType::MAX_CHOICES;
    }

    /** Trim, `_` and `-` to spaces, runs of white space to one, ASCII lower case (rule 2). */
    public static function normaliseHeading(string $heading): string
    {
        if (!Text::isUtf8($heading)) {
            return '';
        }
        $text = (string) preg_replace('/[_\-]/', ' ', $heading);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return strtolower(Text::trim($text));
    }

    public static function isMoneyHeading(string $heading): bool
    {
        $words = trim((string) preg_replace('/[^a-z0-9]+/', ' ', self::normaliseHeading($heading)));

        return preg_match(self::MONEY_WORDS, $words) === 1;
    }

    /** An id-like column starts with "Bring in" unticked. */
    public static function isIdLike(string $heading, ColumnStats $s): bool
    {
        $normal = self::normaliseHeading($heading);
        $named = $normal === 'id' || str_ends_with($normal, ' id');

        return $named && $s->count > 0 && $s->counter('intDistinctIncreasing') === $s->count;
    }

    /**
     * The proposed field name: `_` and `-` to spaces, runs of white space
     * collapsed, control characters removed, the first letter upper-cased,
     * cut to 60 characters; "Column {n}" when nothing is left.
     */
    public static function fieldName(string $heading, int $index): string
    {
        $text = Text::isUtf8($heading) ? $heading : '';
        $text = (string) preg_replace('/(?![\x{200C}\x{200D}])[\p{Cc}\p{Zl}\p{Zp}\p{Cf}]/u', ' ', $text);
        $text = (string) preg_replace('/[_\-]/', ' ', $text);
        $text = Text::trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = Text::trim(ColumnStats::cut($text, self::MAX_FIELD_NAME));
        if ($text === '') {
            return self::COLUMN_PREFIX . ($index + 1);
        }

        return ucfirst($text);
    }

    /** @param array<string, true> $used lower-cased names already taken */
    private static function unique(string $name, array $used): string
    {
        if (!isset($used[strtolower($name)])) {
            return $name;
        }
        for ($n = 2; ; $n++) {
            $suffix = ' ' . $n;
            $candidate = Text::trim(ColumnStats::cut($name, self::MAX_FIELD_NAME - strlen($suffix))) . $suffix;
            if (!isset($used[strtolower($candidate)])) {
                return $candidate;
            }
        }
    }
}
