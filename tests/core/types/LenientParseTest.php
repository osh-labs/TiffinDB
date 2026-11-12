<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Types;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-TYP-2: what lenient parsing (import, type conversion) adds to strict
 * parsing, per type, and what it still refuses. Every row also states what
 * strict parsing does with the same text.
 */
#[CoversNamespace('TiffinDB\\Core\\Schema\\Type')]
final class LenientParseTest extends TestCase
{
    private const NUMBER = ['decimals' => 0, 'min' => null, 'max' => null];
    private const NUMBER2 = ['decimals' => 2, 'min' => null, 'max' => null];
    private const USD = ['code' => 'USD', 'minor_digits' => 2];

    /**
     * Accepted only in lenient mode: type, options, text, canonical value.
     *
     * @return iterable<string, array{string, array<string, mixed>, string, mixed}>
     */
    public static function additions(): iterable
    {
        $rows = [
            ['text', ['max_length' => 20], "a\nb", 'a b'],
            ['text', ['max_length' => 20], "a\r\nb", 'a b'],
            ['text', ['max_length' => 20], "a\rb", 'a b'],
            ['text', ['max_length' => 20], "a\n\nb", 'a  b'],
            ['text', ['max_length' => 20], "line 1\nline 2\n", 'line 1 line 2'],

            ['number', self::NUMBER, '1.5', 2],
            ['number', self::NUMBER, '-1.5', -2],
            ['number', self::NUMBER, '2.4', 2],
            ['number', self::NUMBER, '-0.4', 0],
            ['number', self::NUMBER, '2.4999999', 2],
            ['number', self::NUMBER, '999999999999998.5', 999999999999999],
            ['number', self::NUMBER2, '1.005', 1.01],
            ['number', self::NUMBER2, '-1.005', -1.01],
            ['number', self::NUMBER2, '1.2345', 1.23],
            ['number', self::NUMBER2, '9.995', 10.0],
            ['number', self::NUMBER2, '-0.001', 0.0],

            ['currency', self::USD, '1.005', 101],
            ['currency', self::USD, '-1.005', -101],
            ['currency', self::USD, '$0.004', 0],
            ['currency', self::USD, '$19.999', 2000],
            ['currency', ['code' => 'JPY', 'minor_digits' => 0], '12.5 JPY', 13],

            ['date', [], '2026-03-14T10:30', '2026-03-14'],
            ['date', [], '2026-03-14T10:30:15', '2026-03-14'],
            ['date', [], '2024-02-29T00:00', '2024-02-29'],

            ['time', ['seconds' => false], '7:30', '07:30:00'],
            ['time', ['seconds' => false], '0:00', '00:00:00'],
            ['time', ['seconds' => false], ' 9:59 ', '09:59:00'],

            ['date_time', ['seconds' => false], '2026-03-14', '2026-03-14T00:00:00'],
            ['date_time', ['seconds' => false], ' 2024-02-29 ', '2024-02-29T00:00:00'],

            ['url', [], 'example.com', 'https://example.com'],
            ['url', [], 'www.example.com/path?q=1', 'https://www.example.com/path?q=1'],
            ['url', [], '192.0.2.1/x', 'https://192.0.2.1/x'],
            ['url', [], 'example.com:8080/x', 'https://example.com:8080/x'],
            ['url', [], 'sub.example.org', 'https://sub.example.org'],
            ['url', [], '1x.example.net', 'https://1x.example.net'],

            ['auto_number', ['start' => 1, 'next' => 1], '0', 0],
            ['auto_number', ['start' => 1, 'next' => 1], '42', 42],
            ['auto_number', ['start' => 1, 'next' => 1], ' 7 ', 7],
            ['auto_number', ['start' => 1, 'next' => 1], '0012', 12],
            ['auto_number', ['start' => 1, 'next' => 1], '9007199254740991', 9007199254740991],
        ];
        foreach ($rows as $i => $row) {
            yield $row[0] . ' #' . $i . ' ' . json_encode($row[2]) => $row;
        }
    }

    /**
     * Refused in lenient mode too: type, options, text, problem key.
     *
     * @return iterable<string, array{string, array<string, mixed>, string, string}>
     */
    public static function stillRefused(): iterable
    {
        $rows = [
            ['text', ['max_length' => 5], "abc\ndef", 'problem.text.too_long'],
            ['text', ['max_length' => 5], 'abcdef', 'problem.text.too_long'],
            ['text', ['max_length' => 20], "a\x01b", 'problem.text.control'],
            ['text', ['max_length' => 20], "a\u{2028}b", 'problem.text.control'],
            ['long_text', ['max_length' => 5], 'abcdef', 'problem.text.too_long'],
            ['long_text', ['max_length' => 20], "a\x00b", 'problem.text.control'],

            ['number', self::NUMBER, '1000000000000000.5', 'problem.number.range'],
            ['number', self::NUMBER, '999999999999999.5', 'problem.number.range'],
            ['number', self::NUMBER2, '9999999999999.995', 'problem.number.range'],
            ['number', self::NUMBER, '1,5', 'problem.number.invalid'],
            ['number', self::NUMBER, '1e3', 'problem.number.invalid'],
            ['number', ['decimals' => 0, 'min' => 0, 'max' => 10], '10.5', 'problem.number.too_large'],

            ['currency', self::USD, 'EUR 1.005', 'problem.currency.invalid'],
            ['currency', self::USD, '90000000000000.005', 'problem.number.range'],
            ['currency', self::USD, '1,5', 'problem.currency.invalid'],

            ['date', [], '2026-03-14 10:30', 'problem.date.invalid'],
            ['date', [], '2026-03-14T25:00', 'problem.date.invalid'],
            ['date', [], '2026-03-14T10:30Z', 'problem.date.invalid'],
            ['date', [], '2026-02-30T10:30', 'problem.date.invalid'],

            ['time', ['seconds' => false], '7:3', 'problem.time.invalid'],
            ['time', ['seconds' => false], '24:00', 'problem.time.invalid'],
            ['time', ['seconds' => false], '7:60', 'problem.time.invalid'],

            ['date_time', ['seconds' => false], '2026-03-14Z', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14T10:00+02:00', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14T10:00Z', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-02-30', 'problem.date_time.invalid'],

            ['url', [], 'javascript:alert(document.cookie)', 'problem.url.invalid'],
            ['url', [], 'JaVaScRiPt:alert(1).x', 'problem.url.invalid'],
            ['url', [], 'data:text/html,a.b', 'problem.url.invalid'],
            ['url', [], 'vbscript:msgbox.x', 'problem.url.invalid'],
            ['url', [], 'mailto:user@example.com', 'problem.url.invalid'],
            ['url', [], 'localhost', 'problem.url.invalid'],
            ['url', [], '/path.html', 'problem.url.invalid'],
            ['url', [], '.example.com', 'problem.url.invalid'],
            ['url', [], '//example.com', 'problem.url.invalid'],
            ['url', [], 'path/file.html', 'problem.url.invalid'],
            ['url', [], 'user:pw@example.com', 'problem.url.invalid'],
            ['url', [], 'ftp://example.com', 'problem.url.invalid'],
            ['url', [], 'example.com\\@evil.example', 'problem.url.invalid'],

            ['auto_number', ['start' => 1, 'next' => 1], '-1', 'problem.auto_number.read_only'],
            ['auto_number', ['start' => 1, 'next' => 1], '1.5', 'problem.auto_number.read_only'],
            ['auto_number', ['start' => 1, 'next' => 1], 'abc', 'problem.auto_number.read_only'],
            ['auto_number', ['start' => 1, 'next' => 1], '9007199254740992', 'problem.auto_number.read_only'],
            ['auto_number', ['start' => 1, 'next' => 1], '1,000', 'problem.auto_number.read_only'],

            ['attachment', ['images_only' => false], '1', 'problem.value.unsupported'],
            ['relation', [], '1,2', 'problem.value.unsupported'],
        ];
        foreach ($rows as $i => $row) {
            yield $row[0] . ' #' . $i . ' ' . json_encode($row[2]) => $row;
        }
    }

    /**
     * Types whose lenient mode equals strict: the same answer for the same text.
     *
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function sameAsStrict(): iterable
    {
        $samples = [
            'long_text' => [['max_length' => 10], ["a\r\nb", "a\rb", ' x ', "a\x00", str_repeat('y', 11), '']],
            'duration' => [['seconds' => false], ['1:30', '90', '1:60', '-1', '1.5', '']],
            'checkbox' => [[], ['yes', 'No', '', '2', 'maybe', ' on ']],
            'choice' => [['choices' => [['key' => 'aaaaaaaaaaaa', 'label' => 'Red'], ['key' => 'bbbbbbbbbbbb', 'label' => 'Blue']]], ['red', 'Red', 'Purple', '', ' blue ', 'R']],
            'rating' => [['max' => 5], ['0', '3', '6', '2.5', '', '-1']],
            'email' => [[], ['user@example.com', ' a@example.org ', 'user', 'a b@example.com', '', 'ü@example.com']],
            'phone' => [[], ['555-0100', '555  0100', '12', 'abc', '', '555-0100 x9']],
            'attachment' => [['images_only' => false], ['', '1', 'x', ' ', '1,2', 'a.png']],
            'relation' => [[], ['', '1', 'x', ' ', '1,2', '[1]']],
        ];
        foreach ($samples as $type => [$options, $texts]) {
            foreach ($texts as $i => $text) {
                yield $type . ' #' . $i . ' ' . json_encode($text) => [$type, $options, $text];
            }
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('additions')]
    public function testLenientAddition(string $type, array $options, string $text, mixed $expected): void
    {
        $fieldType = self::types()->get($type);
        $lenient = $fieldType->parse($text, $options, lenient: true);
        self::assertTrue($lenient->isOk(), 'Refused with ' . $lenient->problemKey());
        self::assertSame($expected, $lenient->value());
        self::assertFalse($fieldType->parse($text, $options)->isOk(), 'Strict parsing should refuse this text.');
        self::assertSame($expected, $fieldType->check($expected, $options)->value(), 'The result is canonical.');
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('stillRefused')]
    public function testStillRefused(string $type, array $options, string $text, string $key): void
    {
        $fieldType = self::types()->get($type);
        $lenient = $fieldType->parse($text, $options, lenient: true);
        self::assertFalse($lenient->isOk(), 'Accepted as ' . var_export($lenient->isOk() ? $lenient->value() : null, true));
        self::assertSame($key, $lenient->problemKey());
        self::assertFalse($fieldType->parse($text, $options)->isOk());
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('sameAsStrict')]
    public function testSameAsStrict(string $type, array $options, string $text): void
    {
        $fieldType = self::types()->get($type);
        self::assertEquals($fieldType->parse($text, $options), $fieldType->parse($text, $options, lenient: true));
    }

    private static function types(): FieldTypes
    {
        return FieldTypes::standard(new Keys(new SeededRandom('lenient-parse')));
    }
}
