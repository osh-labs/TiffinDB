<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Csv;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * Guesses how a CSV file is written (M3 spec section 8.1): the character
 * set from the first SAMPLE_BYTES, the delimiter from the first SAMPLE_RECORDS
 * records of that sample. Reads at most SAMPLE_BYTES of the file.
 */
final class CsvDetector
{
    public const SAMPLE_RECORDS = 50;

    /** A quote, the line breaks, and the four candidate delimiters. */
    private const STOPS = "\"\r\n,;\t|";

    private function __construct()
    {
    }

    public static function detect(string $path): CsvDialect
    {
        try {
            $head = CsvReader::quietly(static fn () => file_get_contents($path, false, null, 0, Charset::SAMPLE_BYTES), 'The file could not be read.');
        } catch (\RuntimeException $error) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file could not be read.', $error);
        }
        if (!is_string($head)) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file could not be read.');
        }
        $charset = Charset::detect($head);
        $bom = Charset::bom($charset);
        if ($bom !== '' && str_starts_with($head, $bom)) {
            $head = substr($head, strlen($bom));
        }
        $decode = Charset::decoder($charset);
        $sample = $decode($head, false);

        return new CsvDialect($charset, self::delimiter($sample), true);
    }

    /**
     * The delimiter of a decoded sample: for each of comma, semicolon, tab,
     * and vertical bar, its count outside quotes in each of the first
     * SAMPLE_RECORDS records; the one with the most records sharing its
     * most common non-zero count wins, ties in that order; none: comma.
     */
    public static function delimiter(string $sample): string
    {
        $candidates = [];
        foreach (CsvDialect::DELIMITERS as [$char]) {
            $candidates[] = $char;
        }
        /** @var array<string, array<int, int>> $histogram delimiter => count => records */
        $histogram = array_fill_keys($candidates, []);
        $counts = array_fill_keys($candidates, 0);
        $length = strlen($sample);
        $stops = self::STOPS;
        $records = 0;
        $inQuotes = false;
        $pos = 0;
        $any = false;
        $fieldStart = true;
        while ($pos < $length && $records < self::SAMPLE_RECORDS) {
            if ($inQuotes) {
                $quote = strpos($sample, '"', $pos);
                if ($quote === false) {
                    break; // the sample ends inside a quoted field
                }
                $pos = $quote + 1;
                if ($pos < $length && $sample[$pos] === '"') {
                    $pos++;
                } else {
                    $inQuotes = false;
                }
                continue;
            }
            $span = strcspn($sample, $stops, $pos);
            if ($span > 0) {
                $pos += $span;
                $any = true;
                $fieldStart = false;
            }
            if ($pos >= $length) {
                break;
            }
            $char = $sample[$pos];
            $pos++;
            $any = true;
            if ($char === '"') {
                // A quote opens a quoted field only at the start of a field; elsewhere it is literal.
                $inQuotes = $fieldStart;
                $fieldStart = false;
            } elseif ($char === "\r" || $char === "\n") {
                if ($char === "\r" && $pos < $length && $sample[$pos] === "\n") {
                    $pos++;
                }
                self::endRecord($histogram, $counts);
                $records++;
                $any = false;
                $fieldStart = true;
            } else {
                $counts[$char]++;
                $fieldStart = true;
            }
        }
        if ($any && $records < self::SAMPLE_RECORDS) {
            self::endRecord($histogram, $counts);
        }

        $best = ',';
        $bestScore = 0;
        foreach ($candidates as $char) {
            $score = $histogram[$char] === [] ? 0 : max($histogram[$char]);
            if ($score > $bestScore) {
                $best = $char;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param array<string, array<int, int>> $histogram
     * @param array<string, int> $counts
     */
    private static function endRecord(array &$histogram, array &$counts): void
    {
        foreach ($counts as $char => $count) {
            if ($count > 0) {
                $histogram[$char][$count] = ($histogram[$char][$count] ?? 0) + 1;
            }
            $counts[$char] = 0;
        }
    }
}
