<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Csv;

/**
 * How a CSV file is read (M3 spec section 8.1, P121): its character set,
 * its delimiter, and whether the first record is a heading row. The names
 * are the plain English text the CSV plugin shows (P133).
 */
final class CsvDialect
{
    /** Character set => name, in the order the reading options offer them. */
    public const CHARSETS = [
        Charset::UTF8 => 'UTF-8',
        Charset::UTF16LE => 'UTF-16 little-endian',
        Charset::UTF16BE => 'UTF-16 big-endian',
        Charset::WINDOWS_1252 => 'Windows Latin 1',
    ];

    /** Setting value => [delimiter, name of one, name of several], in detection order. */
    public const DELIMITERS = [
        'comma' => [',', 'Comma', 'commas'],
        'semicolon' => [';', 'Semicolon', 'semicolons'],
        'tab' => ["\t", 'Tab', 'tabs'],
        'bar' => ['|', 'Vertical bar', 'vertical bars'],
    ];

    public readonly string $charset;

    public function __construct(string $charset, public readonly string $delimiter, public readonly bool $heading = true)
    {
        $charset = $charset === Charset::UTF8_BOM ? Charset::UTF8 : $charset;
        if (!isset(self::CHARSETS[$charset])) {
            throw new \InvalidArgumentException('Unknown character set.');
        }
        if (self::delimiterValue($delimiter) === null) {
            throw new \InvalidArgumentException('Unknown delimiter.');
        }
        $this->charset = $charset;
    }

    /** The setting value of a delimiter character, or null when it is not one of the four. */
    public static function delimiterValue(string $delimiter): ?string
    {
        foreach (self::DELIMITERS as $value => [$char]) {
            if ($char === $delimiter) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The dialect for setting values (`encoding`, `delimiter`, `heading`);
     * missing or unknown values fall back to UTF-8, comma, and a heading row.
     *
     * @param array<string, string> $values
     */
    public static function fromValues(array $values): self
    {
        $charset = $values['encoding'] ?? Charset::UTF8;
        $delimiter = self::DELIMITERS[$values['delimiter'] ?? 'comma'][0] ?? ',';

        return new self(isset(self::CHARSETS[$charset]) ? $charset : Charset::UTF8, $delimiter, ($values['heading'] ?? '1') !== '0');
    }

    /** @return array{encoding: string, delimiter: string, heading: string} */
    public function values(): array
    {
        return ['encoding' => $this->charset, 'delimiter' => (string) self::delimiterValue($this->delimiter), 'heading' => $this->heading ? '1' : '0'];
    }

    /** For example "CSV, commas, UTF-8". */
    public function readAs(): string
    {
        return 'CSV, ' . self::DELIMITERS[(string) self::delimiterValue($this->delimiter)][2] . ', ' . self::CHARSETS[$this->charset];
    }
}
