<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

/**
 * What the number format of each cell format (`cellXfs`) of the styles
 * part says about a numeric value: a plain number, a date (with or without
 * a time), a time of day, or a duration (an elapsed-time format such as
 * `[h]:mm:ss`). Only `numFmts` and `cellXfs` are read.
 *
 * Built-in formats: 14 to 17, 22, 27 to 36, 50 to 58 are dates; 18 to 21,
 * 45 and 47 times; 46 a duration. A custom format code is classified by its
 * first section, after removing quoted text, escaped characters, fill and
 * padding characters, and bracketed parts other than elapsed `[h]`, `[m]`,
 * `[s]` (colours, conditions, locales): elapsed parts make a duration, `d`
 * or `y` a date, `h` or `s` a time, a remaining `m` a date (months).
 *
 * @internal
 */
final class Styles
{
    public const NUMBER = 0;
    public const DATE = 1;
    public const TIME = 2;
    public const DURATION = 3;

    /** @var array<int, int> built-in format id => kind */
    private const BUILT_IN = [
        14 => self::DATE, 15 => self::DATE, 16 => self::DATE, 17 => self::DATE, 22 => self::DATE,
        18 => self::TIME, 19 => self::TIME, 20 => self::TIME, 21 => self::TIME, 45 => self::TIME, 47 => self::TIME,
        46 => self::DURATION,
        27 => self::DATE, 28 => self::DATE, 29 => self::DATE, 30 => self::DATE, 31 => self::DATE, 32 => self::DATE,
        33 => self::DATE, 34 => self::DATE, 35 => self::DATE, 36 => self::DATE,
        50 => self::DATE, 51 => self::DATE, 52 => self::DATE, 53 => self::DATE, 54 => self::DATE, 55 => self::DATE,
        56 => self::DATE, 57 => self::DATE, 58 => self::DATE,
    ];

    /** @param list<int> $kinds cell format index => kind */
    private function __construct(private readonly array $kinds)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** Reads the styles part $part of the checked workbook at $zipPath; call inside Xml::run(). */
    public static function read(string $zipPath, string $part): self
    {
        $reader = Xml::open($zipPath, $part);
        $custom = [];
        $kinds = [];
        $inCellXfs = false;
        $cellXfsDepth = 0;
        try {
            $more = $reader->read();
            while ($more) {
                $type = $reader->nodeType;
                $name = $reader->localName;
                if ($type === \XMLReader::END_ELEMENT && $inCellXfs && $name === 'cellXfs' && $reader->depth === $cellXfsDepth) {
                    $inCellXfs = false;
                } elseif ($type === \XMLReader::ELEMENT && $name === 'numFmt' && count($custom) < Limits::MAX_FORMATS) {
                    $id = self::id($reader->getAttribute('numFmtId'));
                    $code = $reader->getAttribute('formatCode');
                    if ($id !== null && $code !== null) {
                        $custom[$id] = self::classify($code);
                    }
                } elseif ($type === \XMLReader::ELEMENT && $name === 'cellXfs' && !$reader->isEmptyElement) {
                    $inCellXfs = true;
                    $cellXfsDepth = $reader->depth;
                } elseif ($type === \XMLReader::ELEMENT && $name === 'xf' && $inCellXfs && $reader->depth === $cellXfsDepth + 1) {
                    if (count($kinds) < Limits::MAX_FORMATS) {
                        $id = self::id($reader->getAttribute('numFmtId')) ?? 0;
                        $kinds[] = $custom[$id] ?? self::BUILT_IN[$id] ?? self::NUMBER;
                    }
                    // Skip the format's children (alignment, protection).
                    $more = $reader->next();
                    continue;
                }
                $more = $reader->read();
            }
        } finally {
            $reader->close();
        }

        return new self($kinds);
    }

    /** A format id (decimal digits), or null. */
    private static function id(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d{1,9}\z/', $value) === 1 ? (int) $value : null;
    }

    /** The kind of cell format $index (the `s` attribute of a cell); NUMBER when unknown. */
    public function kind(int $index): int
    {
        return $this->kinds[$index] ?? self::NUMBER;
    }

    /** The kind of number format code $code. */
    public static function classify(string $code): int
    {
        // The first section (sections are separated by ';' outside quotes).
        $section = '';
        $quoted = false;
        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            } elseif ($char === ';' && !$quoted) {
                break;
            }
            $section .= $char;
        }
        $section = (string) preg_replace('/"[^"]*"?|\\\\.|[_*]./s', '', $section);
        $elapsed = false;
        $section = (string) preg_replace_callback('/\[([^\]]*)\]?/', static function (array $m) use (&$elapsed): string {
            if (preg_match('/^(h+|m+|s+)\z/i', $m[1]) === 1) {
                $elapsed = true;
            }

            return '';
        }, $section);
        if ($elapsed) {
            return self::DURATION;
        }
        $lower = strtolower($section);
        if (str_contains($lower, 'general')) {
            return self::NUMBER;
        }
        $lower = str_replace(['am/pm', 'a/p'], '', $lower);
        if (preg_match('/[dy]/', $lower) === 1) {
            return self::DATE;
        }
        if (preg_match('/[hs]/', $lower) === 1) {
            return self::TIME;
        }

        return str_contains($lower, 'm') ? self::DATE : self::NUMBER;
    }
}
