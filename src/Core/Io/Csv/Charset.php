<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Csv;

/**
 * Character set detection and streaming conversion to UTF-8 (M3 spec
 * section 8.1, P121). Needs no mbstring or iconv.
 *
 * Decoders take bytes in pieces of any size and return UTF-8; a sequence
 * split between two pieces is held back until the next piece (or replaced
 * when the input ends there). Every byte sequence that cannot be read
 * becomes U+FFFD and is counted.
 */
final class Charset
{
    public const UTF8 = 'utf-8';
    public const UTF8_BOM = 'utf-8-bom';
    public const UTF16LE = 'utf-16le';
    public const UTF16BE = 'utf-16be';
    public const WINDOWS_1252 = 'windows-1252';

    /** Every name decoder() accepts. */
    public const ALL = [self::UTF8, self::UTF8_BOM, self::UTF16LE, self::UTF16BE, self::WINDOWS_1252];

    /** Bytes detect() reads. */
    public const SAMPLE_BYTES = 65536;

    public const REPLACEMENT = "\u{FFFD}";

    /** Windows-1252 bytes 0x80 to 0x9F (0xA0 to 0xFF are the same code points as Latin 1). Null: undefined. */
    private const CP1252_HIGH = [
        0x20AC, null, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, null, 0x017D, null,
        null, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, null, 0x017E, 0x0178,
    ];

    private function __construct()
    {
    }

    /** The character set of a file from its first bytes (at most SAMPLE_BYTES are looked at). */
    public static function detect(string $head): string
    {
        $head = substr($head, 0, self::SAMPLE_BYTES);
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            return self::UTF8_BOM;
        }
        if (str_starts_with($head, "\xFF\xFE")) {
            return self::UTF16LE;
        }
        if (str_starts_with($head, "\xFE\xFF")) {
            return self::UTF16BE;
        }
        // A multi-byte sequence cut by the end of the sample is allowed.
        $body = substr($head, 0, strlen($head) - self::incompleteTail($head));

        return self::isUtf8($body) ? self::UTF8 : self::WINDOWS_1252;
    }

    /** The byte order mark that starts a file in $charset ('' for none). */
    public static function bom(string $charset): string
    {
        return match ($charset) {
            self::UTF8, self::UTF8_BOM => "\xEF\xBB\xBF",
            self::UTF16LE => "\xFF\xFE",
            self::UTF16BE => "\xFE\xFF",
            default => '',
        };
    }

    public static function isUtf8(string $bytes): bool
    {
        return preg_match('//u', $bytes) === 1;
    }

    /**
     * A streaming decoder: `$decode($bytes, $final)` returns the UTF-8 text
     * of $bytes (after anything held back from earlier calls). With $final
     * false, an incomplete sequence at the end is held back; with $final
     * true it is replaced. $replaced counts the U+FFFD written for input
     * that could not be read; $held is the number of input bytes held back
     * after the last call (so the caller can tell which input offset the
     * next output starts at).
     *
     * @return \Closure(string, bool): string
     */
    public static function decoder(string $charset, ?int &$replaced = null, ?int &$held = null): \Closure
    {
        $replaced = 0;
        $held = 0;

        return match ($charset) {
            self::UTF8, self::UTF8_BOM => self::utf8Decoder($replaced, $held),
            self::UTF16LE => self::utf16Decoder(false, $replaced, $held),
            self::UTF16BE => self::utf16Decoder(true, $replaced, $held),
            self::WINDOWS_1252 => self::cp1252Decoder($replaced),
            default => throw new \InvalidArgumentException('Unknown character set.'),
        };
    }

    /** $bytes with every sequence that is not UTF-8 replaced by U+FFFD. */
    public static function scrub(string $bytes, ?int &$replaced = null): string
    {
        $replaced = 0;

        return self::isUtf8($bytes) ? $bytes : self::replaceInvalid($bytes, $replaced);
    }

    /** The longest prefix of UTF-8 text $text that has at most $maxBytes bytes. */
    public static function cut(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $head = substr($text, 0, max(0, $maxBytes));

        return substr($head, 0, strlen($head) - self::incompleteTail($head));
    }

    /**
     * @return \Closure(string, bool): string
     */
    private static function utf8Decoder(?int &$replaced, ?int &$held): \Closure
    {
        $pending = '';

        return static function (string $bytes, bool $final) use (&$pending, &$replaced, &$held): string {
            $data = $pending . $bytes;
            $pending = '';
            if (!$final) {
                $tail = self::incompleteTail($data);
                if ($tail > 0) {
                    $pending = substr($data, -$tail);
                    $data = substr($data, 0, -$tail);
                }
            }
            $held = strlen($pending);
            if (self::isUtf8($data)) {
                return $data;
            }
            $count = 0;
            $out = self::replaceInvalid($data, $count);
            $replaced += $count;

            return $out;
        };
    }

    /**
     * @return \Closure(string, bool): string
     */
    private static function utf16Decoder(bool $bigEndian, ?int &$replaced, ?int &$held): \Closure
    {
        $pending = '';
        // Whole code units only, matched from the start, so the alignment never slips:
        // a run of ASCII units, a surrogate pair, or any one unit.
        $pattern = $bigEndian
            ? '/((?:\x00[\x00-\x7F])++)|([\xD8-\xDB][\s\S][\xDC-\xDF][\s\S])|([\s\S]{2})/'
            : '/((?:[\x00-\x7F]\x00)++)|([\s\S][\xD8-\xDB][\s\S][\xDC-\xDF])|([\s\S]{2})/';
        $ascii = $bigEndian ? '/\x00([\s\S])/' : '/([\s\S])\x00/';

        return static function (string $bytes, bool $final) use (&$pending, &$replaced, &$held, $pattern, $ascii, $bigEndian): string {
            $data = $pending . $bytes;
            $pending = '';
            $odd = strlen($data) % 2;
            $whole = $odd === 1 ? substr($data, 0, -1) : $data;
            $tail = $odd === 1 ? substr($data, -1) : '';
            $length = strlen($whole);
            if (!$final && $length >= 2) {
                // A high surrogate at the very end waits for its pair.
                $high = ord($whole[$bigEndian ? $length - 2 : $length - 1]);
                if ($high >= 0xD8 && $high <= 0xDB) {
                    $tail = substr($whole, -2) . $tail;
                    $whole = substr($whole, 0, -2);
                }
            }
            if (!$final) {
                $pending = $tail;
            }
            $held = strlen($pending);
            $count = 0;
            $out = preg_replace_callback($pattern, static function (array $m) use ($ascii, $bigEndian, &$count): string {
                if (($m[1] ?? '') !== '') {
                    return (string) preg_replace($ascii, '$1', $m[1]);
                }
                if (($m[2] ?? '') !== '') {
                    $pair = (array) unpack($bigEndian ? 'n2' : 'v2', $m[2]);

                    return self::encode(0x10000 + (((int) $pair[1] - 0xD800) << 10) + ((int) $pair[2] - 0xDC00));
                }
                $unit = (int) ((array) unpack($bigEndian ? 'n' : 'v', $m[0]))[1];
                if ($unit >= 0xD800 && $unit <= 0xDFFF) {
                    $count++;

                    return self::REPLACEMENT;
                }

                return self::encode($unit);
            }, $whole);
            if (!is_string($out)) {
                throw new \RuntimeException('UTF-16 text could not be converted.');
            }
            if ($final && $tail !== '') {
                // An unpaired high surrogate or a lone byte where the input ends.
                $out = $out . self::REPLACEMENT;
                $count++;
            }
            $replaced += $count;

            return $out;
        };
    }

    /**
     * @return \Closure(string, bool): string
     */
    private static function cp1252Decoder(?int &$replaced): \Closure
    {
        $map = self::cp1252Map();

        return static function (string $bytes, bool $final) use ($map, &$replaced): string {
            unset($final);
            $count = 0;
            $out = preg_replace_callback('/[\x80-\xFF]/', static function (array $m) use ($map, &$count): string {
                $text = $map[$m[0]];
                if ($text === self::REPLACEMENT) {
                    $count++;
                }

                return $text;
            }, $bytes);
            if (!is_string($out)) {
                throw new \RuntimeException('Windows-1252 text could not be converted.');
            }
            $replaced += $count;

            return $out;
        };
    }

    /** @return array<string, string> byte => UTF-8, for 0x80 to 0xFF */
    private static function cp1252Map(): array
    {
        $map = [];
        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            $point = $byte < 0xA0 ? self::CP1252_HIGH[$byte - 0x80] : $byte;
            $map[chr($byte)] = $point === null ? self::REPLACEMENT : self::encode($point);
        }

        return $map;
    }

    /** UTF-8 bytes of one code point (not a surrogate). */
    private static function encode(int $point): string
    {
        if ($point < 0x80) {
            return chr($point);
        }
        if ($point < 0x800) {
            return chr(0xC0 | ($point >> 6)) . chr(0x80 | ($point & 0x3F));
        }
        if ($point < 0x10000) {
            return chr(0xE0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
        }

        return chr(0xF0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3F)) . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
    }

    /**
     * Replaces each ill-formed sequence (its maximal part that could start
     * a well-formed one, or a single byte) with U+FFFD.
     */
    private static function replaceInvalid(string $data, int &$replaced): string
    {
        $count = 0;
        $out = preg_replace_callback(self::VALID_OR_NOT, static function (array $m) use (&$count): string {
            if (($m[1] ?? '') !== '') {
                return $m[1];
            }
            $count++;

            return self::REPLACEMENT;
        }, $data);
        if (!is_string($out)) {
            throw new \RuntimeException('Text could not be converted.');
        }
        $replaced += $count;

        return $out;
    }

    /**
     * A run of well-formed UTF-8 (group 1), or else one ill-formed
     * sequence: the longest start of a well-formed sequence, or one byte.
     */
    private const VALID_OR_NOT = '/((?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
        . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})++)'
        . '|\xE0[\xA0-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]|\xED[\x80-\x9F]|\xF0[\x90-\xBF][\x80-\xBF]?|[\xF1-\xF3][\x80-\xBF]{1,2}'
        . '|\xF4[\x80-\x8F][\x80-\xBF]?|[\s\S]/';

    /**
     * How many bytes from $at form the start of a well-formed sequence
     * (0 when the byte at $at cannot start one).
     */
    private static function prefixLength(string $data, int $at, int $length): int
    {
        $lead = ord($data[$at]);
        [$need, $low, $high] = self::leadInfo($lead);
        if ($need === 0) {
            return 0;
        }
        $have = 1;
        while ($have < $need && $at + $have < $length) {
            $byte = ord($data[$at + $have]);
            $min = $have === 1 ? $low : 0x80;
            $max = $have === 1 ? $high : 0xBF;
            if ($byte < $min || $byte > $max) {
                break;
            }
            $have++;
        }

        return $have;
    }

    /**
     * For a lead byte: the sequence length (0 when it is not a lead byte of
     * a multi-byte sequence) and the allowed range of the second byte.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function leadInfo(int $lead): array
    {
        return match (true) {
            $lead >= 0xC2 && $lead <= 0xDF => [2, 0x80, 0xBF],
            $lead === 0xE0 => [3, 0xA0, 0xBF],
            $lead === 0xED => [3, 0x80, 0x9F],
            $lead >= 0xE1 && $lead <= 0xEF => [3, 0x80, 0xBF],
            $lead === 0xF0 => [4, 0x90, 0xBF],
            $lead >= 0xF1 && $lead <= 0xF3 => [4, 0x80, 0xBF],
            $lead === 0xF4 => [4, 0x80, 0x8F],
            default => [0, 0, 0],
        };
    }

    /**
     * The number of bytes at the end of $data that start a well-formed
     * multi-byte sequence but do not complete it (0 to 3).
     */
    private static function incompleteTail(string $data): int
    {
        $length = strlen($data);
        for ($back = 1; $back <= 3 && $back <= $length; $back++) {
            $at = $length - $back;
            $byte = ord($data[$at]);
            if ($byte < 0x80) {
                return 0;
            }
            if ($byte >= 0xC0) {
                $need = self::leadInfo($byte)[0];

                return $need > $back && self::prefixLength($data, $at, $length) === $back ? $back : 0;
            }
        }

        return 0;
    }
}
