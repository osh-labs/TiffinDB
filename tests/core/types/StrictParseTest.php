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
 * T-TYP-1: strict parse (form input), at least six accepted and six refused
 * inputs per type, with the canonical value or the problem key. check()
 * gives the same answer for the accepted canonical values.
 */
#[CoversNamespace('TiffinDB\\Core\\Schema\\Type')]
final class StrictParseTest extends TestCase
{
    private const CHOICES = ['choices' => [
        ['key' => 'aaaaaaaaaaaa', 'label' => 'Red'],
        ['key' => 'bbbbbbbbbbbb', 'label' => 'Green'],
        ['key' => 'cccccccccccc', 'label' => 'Blue'],
        ['key' => 'dddddddddddd', 'label' => 'Straße'],
    ]];

    private const NUMBER = ['decimals' => 0, 'min' => null, 'max' => null];
    private const NUMBER2 = ['decimals' => 2, 'min' => null, 'max' => null];
    private const USD = ['code' => 'USD', 'minor_digits' => 2];

    /** @return iterable<string, array{string, array<string, mixed>, string, mixed}> */
    public static function accepted(): iterable
    {
        $rows = [
            ['text', ['max_length' => 10], 'Hello', 'Hello'],
            ['text', ['max_length' => 10], '  Hi  ', 'Hi'],
            ['text', ['max_length' => 10], "a\tb", "a\tb"],
            ['text', ['max_length' => 10], '', null],
            ['text', ['max_length' => 10], "  \t ", null],
            ['text', ['max_length' => 10], 'Ünïcödé', 'Ünïcödé'],
            ['text', ['max_length' => 10], "x\u{200D}y", "x\u{200D}y"],
            ['text', ['max_length' => 10], '0123456789', '0123456789'],
            ['text', ['max_length' => 10], "\u{00A0}nbsp\u{3000}", 'nbsp'],

            ['long_text', ['max_length' => 20], "a\r\nb", "a\nb"],
            ['long_text', ['max_length' => 20], "a\rb", "a\nb"],
            ['long_text', ['max_length' => 20], "\n\nx\n", 'x'],
            ['long_text', ['max_length' => 20], "a\tb", "a\tb"],
            ['long_text', ['max_length' => 20], '', null],
            ['long_text', ['max_length' => 20], "line 1\nline 2", "line 1\nline 2"],
            ['long_text', ['max_length' => 20], "a\n\n\nb", "a\n\n\nb"],

            ['number', self::NUMBER, '12', 12],
            ['number', self::NUMBER, '-3', -3],
            ['number', self::NUMBER, '+4', 4],
            ['number', self::NUMBER, '1,234,567', 1234567],
            ['number', self::NUMBER, '007', 7],
            ['number', self::NUMBER, ' 5 ', 5],
            ['number', self::NUMBER, '1000000000000000', 1000000000000000],
            ['number', self::NUMBER, '-1000000000000000', -1000000000000000],
            ['number', self::NUMBER, '-0', 0],
            ['number', self::NUMBER, '', null],
            ['number', self::NUMBER2, '1.5', 1.5],
            ['number', self::NUMBER2, '1.25', 1.25],
            ['number', self::NUMBER2, '12', 12.0],
            ['number', self::NUMBER2, '-0.00', 0.0],
            ['number', self::NUMBER2, '1,000.01', 1000.01],
            ['number', ['decimals' => 0, 'min' => 0, 'max' => 10], '10', 10],
            ['number', ['decimals' => 0, 'min' => 0, 'max' => 10], '0', 0],

            ['currency', self::USD, '12', 1200],
            ['currency', self::USD, '$12.50', 1250],
            ['currency', self::USD, '€3', 300],
            ['currency', self::USD, '£0.99', 99],
            ['currency', self::USD, '¥1,000', 100000],
            ['currency', self::USD, 'USD 5', 500],
            ['currency', self::USD, '5 USD', 500],
            ['currency', self::USD, 'usd 5.1', 510],
            ['currency', self::USD, '$-5', -500],
            ['currency', self::USD, '-0.05', -5],
            ['currency', self::USD, '', null],
            ['currency', ['code' => 'JPY', 'minor_digits' => 0], 'JPY 1,500', 1500],
            ['currency', ['code' => 'BHD', 'minor_digits' => 3], '1.234 BHD', 1234],
            ['currency', self::USD, '90000000000000.00', 9000000000000000],

            ['date', [], '2026-03-14', '2026-03-14'],
            ['date', [], '0001-01-01', '0001-01-01'],
            ['date', [], '9999-12-31', '9999-12-31'],
            ['date', [], '2024-02-29', '2024-02-29'],
            ['date', [], '2000-02-29', '2000-02-29'],
            ['date', [], ' 2026-01-01 ', '2026-01-01'],
            ['date', [], '', null],

            ['time', ['seconds' => false], '14:30', '14:30:00'],
            ['time', ['seconds' => false], '00:00', '00:00:00'],
            ['time', ['seconds' => false], '23:59:59', '23:59:59'],
            ['time', ['seconds' => false], '09:05:07', '09:05:07'],
            ['time', ['seconds' => false], ' 07:15 ', '07:15:00'],
            ['time', ['seconds' => true], '12:00', '12:00:00'],
            ['time', ['seconds' => false], '', null],

            ['date_time', ['seconds' => false], '2026-03-14T14:30', '2026-03-14T14:30:00'],
            ['date_time', ['seconds' => false], '2026-03-14 14:30:15', '2026-03-14T14:30:15'],
            ['date_time', ['seconds' => false], '2024-02-29T00:00:00', '2024-02-29T00:00:00'],
            ['date_time', ['seconds' => false], '9999-12-31T23:59:59', '9999-12-31T23:59:59'],
            ['date_time', ['seconds' => false], ' 2026-01-01T01:02 ', '2026-01-01T01:02:00'],
            ['date_time', ['seconds' => false], '0001-01-01 00:00', '0001-01-01T00:00:00'],
            ['date_time', ['seconds' => false], '', null],

            ['duration', ['seconds' => false], '1:30', 5400],
            ['duration', ['seconds' => false], '0:05', 300],
            ['duration', ['seconds' => false], '1:30:15', 5415],
            ['duration', ['seconds' => false], '90', 5400],
            ['duration', ['seconds' => false], '0', 0],
            ['duration', ['seconds' => false], '876000:00', 3153600000],
            ['duration', ['seconds' => false], '100:00:01', 360001],
            ['duration', ['seconds' => false], '', null],

            ['checkbox', [], '1', true],
            ['checkbox', [], 'on', true],
            ['checkbox', [], 'TRUE', true],
            ['checkbox', [], 'Yes', true],
            ['checkbox', [], ' no ', false],
            ['checkbox', [], '', false],
            ['checkbox', [], '0', false],
            ['checkbox', [], 'OFF', false],
            ['checkbox', [], 'False', false],

            ['choice', self::CHOICES, 'Red', 'Red'],
            ['choice', self::CHOICES, 'red', 'Red'],
            ['choice', self::CHOICES, ' GREEN ', 'Green'],
            ['choice', self::CHOICES, 'bLuE', 'Blue'],
            ['choice', self::CHOICES, 'Straße', 'Straße'],
            ['choice', self::CHOICES, 'STRAße', 'Straße'],
            ['choice', self::CHOICES, '', null],

            ['rating', ['max' => 5], '1', 1],
            ['rating', ['max' => 5], '5', 5],
            ['rating', ['max' => 5], ' 3 ', 3],
            ['rating', ['max' => 5], '0', null],
            ['rating', ['max' => 5], '05', 5],
            ['rating', ['max' => 10], '10', 10],
            ['rating', ['max' => 5], '', null],

            ['url', [], 'https://example.com', 'https://example.com'],
            ['url', [], 'http://example.com/path?q=1', 'http://example.com/path?q=1'],
            ['url', [], 'HTTPS://Example.com/a?b=1#c', 'https://Example.com/a?b=1#c'],
            ['url', [], 'HtTp://example.org/', 'http://example.org/'],
            ['url', [], 'https://xn--r8jz45g.jp/', 'https://xn--r8jz45g.jp/'],
            ['url', [], 'https://192.0.2.1:8080/x', 'https://192.0.2.1:8080/x'],
            ['url', [], ' https://example.org/@name ', 'https://example.org/@name'],
            ['url', [], 'https://example.com/a%20b', 'https://example.com/a%20b'],
            ['url', [], '', null],

            ['email', [], 'user@example.com', 'user@example.com'],
            ['email', [], 'first.last+tag@example.co.uk', 'first.last+tag@example.co.uk'],
            ['email', [], ' a@example.org ', 'a@example.org'],
            ['email', [], 'x_y-z@sub.example.com', 'x_y-z@sub.example.com'],
            ['email', [], 'USER@EXAMPLE.COM', 'USER@EXAMPLE.COM'],
            ['email', [], 'user@[192.0.2.1]', 'user@[192.0.2.1]'],
            ['email', [], '', null],

            ['phone', [], '555-0100', '555-0100'],
            ['phone', [], '+1 (555) 010-0123', '+1 (555) 010-0123'],
            ['phone', [], '555.0100', '555.0100'],
            ['phone', [], '555-0100 x12', '555-0100 x12'],
            ['phone', [], '555-0100 ext. 12', '555-0100 ext. 12'],
            ['phone', [], '555-0100 ext 12', '555-0100 ext 12'],
            ['phone', [], '555-0100 EXT.12', '555-0100 EXT.12'],
            ['phone', [], "555  0100", '555 0100'],
            ['phone', [], "555\t \u{00A0}0100", '555 0100'],
            ['phone', [], '123', '123'],
            ['phone', [], '', null],
        ];
        foreach (['attachment', 'relation', 'auto_number'] as $type) {
            foreach (['', ' ', "\t", "\n", "  \t ", "\u{00A0}"] as $i => $blank) {
                $rows[] = [$type, [], $blank, null];
            }
        }
        foreach ($rows as $i => $row) {
            yield $row[0] . ' #' . $i . ' ' . json_encode($row[2], JSON_INVALID_UTF8_SUBSTITUTE) => $row;
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>, string, string, array<string, string|int>}> */
    public static function refused(): iterable
    {
        $rows = [
            ['text', ['max_length' => 10], "a\nb", 'problem.text.line_break'],
            ['text', ['max_length' => 10], "a\rb", 'problem.text.line_break'],
            ['text', ['max_length' => 10], "a\r\nb", 'problem.text.line_break'],
            ['text', ['max_length' => 10], "a\x01b", 'problem.text.control'],
            ['text', ['max_length' => 10], "a\u{200B}b", 'problem.text.control'],
            ['text', ['max_length' => 10], "\xFF", 'problem.text.control'],
            ['text', ['max_length' => 10], "a\u{2028}b", 'problem.text.control'],
            ['text', ['max_length' => 10], "a\x7Fb", 'problem.text.control'],
            ['text', ['max_length' => 10], '01234567890', 'problem.text.too_long', ['max' => 10]],
            ['text', ['max_length' => 10], 'ÄÄÄÄÄÄÄÄÄÄÄ', 'problem.text.too_long', ['max' => 10]],

            ['long_text', ['max_length' => 20], "a\x00b", 'problem.text.control'],
            ['long_text', ['max_length' => 20], "a\u{202E}b", 'problem.text.control'],
            ['long_text', ['max_length' => 20], "\xC3", 'problem.text.control'],
            ['long_text', ['max_length' => 20], "a\x0Bb", 'problem.text.control'],
            ['long_text', ['max_length' => 20], "a\u{2029}b", 'problem.text.control'],
            ['long_text', ['max_length' => 20], "a\u{FEFF}b", 'problem.text.control'],
            ['long_text', ['max_length' => 20], str_repeat('x', 21), 'problem.text.too_long', ['max' => 20]],

            ['number', self::NUMBER, 'abc', 'problem.number.invalid'],
            ['number', self::NUMBER, '1.5', 'problem.number.whole'],
            ['number', self::NUMBER, '1,23', 'problem.number.invalid'],
            ['number', self::NUMBER, '12,3456', 'problem.number.invalid'],
            ['number', self::NUMBER, '1e3', 'problem.number.invalid'],
            ['number', self::NUMBER, '.5', 'problem.number.invalid'],
            ['number', self::NUMBER, '5.', 'problem.number.invalid'],
            ['number', self::NUMBER, '1 000', 'problem.number.invalid'],
            ['number', self::NUMBER, '١٢', 'problem.number.invalid'],
            ['number', self::NUMBER, '--1', 'problem.number.invalid'],
            ['number', self::NUMBER, 'NaN', 'problem.number.invalid'],
            ['number', self::NUMBER, 'INF', 'problem.number.invalid'],
            ['number', self::NUMBER, '0x1A', 'problem.number.invalid'],
            ['number', self::NUMBER, '1000000000000001', 'problem.number.range'],
            ['number', self::NUMBER, '99999999999999999999', 'problem.number.range'],
            ['number', self::NUMBER2, '1.255', 'problem.number.decimals', ['decimals' => 2]],
            ['number', ['decimals' => 0, 'min' => 0, 'max' => 10], '11', 'problem.number.too_large', ['max' => 10]],
            ['number', ['decimals' => 0, 'min' => 0, 'max' => 10], '-1', 'problem.number.too_small', ['min' => 0]],
            ['number', ['decimals' => 2, 'min' => 0.5, 'max' => null], '0.49', 'problem.number.too_small', ['min' => '0.5']],

            ['currency', self::USD, '12.345', 'problem.currency.decimals', ['digits' => 2]],
            ['currency', self::USD, 'EUR 5', 'problem.currency.invalid'],
            ['currency', self::USD, '$$5', 'problem.currency.invalid'],
            ['currency', self::USD, '5$', 'problem.currency.invalid'],
            ['currency', self::USD, 'abc', 'problem.currency.invalid'],
            ['currency', self::USD, '$ 5', 'problem.currency.invalid'],
            ['currency', self::USD, 'USD5', 'problem.currency.invalid'],
            ['currency', self::USD, 'USD  5', 'problem.currency.invalid'],
            ['currency', self::USD, '$5 USD', 'problem.currency.invalid'],
            ['currency', self::USD, '90000000000000.01', 'problem.number.range'],
            ['currency', ['code' => 'JPY', 'minor_digits' => 0], '12.5', 'problem.currency.decimals', ['digits' => 0]],

            ['date', [], '2026-02-29', 'problem.date.invalid'],
            ['date', [], '2026-13-01', 'problem.date.invalid'],
            ['date', [], '0000-01-01', 'problem.date.invalid'],
            ['date', [], '2026-3-14', 'problem.date.invalid'],
            ['date', [], '14/03/2026', 'problem.date.invalid'],
            ['date', [], '2026-03-14T10:00', 'problem.date.invalid'],
            ['date', [], '２０２６-03-14', 'problem.date.invalid'],
            ['date', [], '2026-04-31', 'problem.date.invalid'],
            ['date', [], '1900-02-29', 'problem.date.invalid'],

            ['time', ['seconds' => false], '24:00', 'problem.time.invalid'],
            ['time', ['seconds' => false], '7:30', 'problem.time.invalid'],
            ['time', ['seconds' => false], '12:60', 'problem.time.invalid'],
            ['time', ['seconds' => false], '12:30:60', 'problem.time.invalid'],
            ['time', ['seconds' => false], '1230', 'problem.time.invalid'],
            ['time', ['seconds' => false], '12:30 PM', 'problem.time.invalid'],
            ['time', ['seconds' => false], '12:30:00Z', 'problem.time.invalid'],

            ['date_time', ['seconds' => false], '2026-03-14', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14T14:30Z', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14T14:30:00+01:00', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-02-30T10:00', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14T25:00', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14  14:30', 'problem.date_time.invalid'],
            ['date_time', ['seconds' => false], '2026-03-14t14:30', 'problem.date_time.invalid'],

            ['duration', ['seconds' => false], '1:60', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '1:30:60', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '-5', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '1.5', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '876000:01', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '1h', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '52560001', 'problem.duration.invalid'],
            ['duration', ['seconds' => false], '1:5', 'problem.duration.invalid'],

            ['checkbox', [], '2', 'problem.checkbox.invalid'],
            ['checkbox', [], 'y', 'problem.checkbox.invalid'],
            ['checkbox', [], 'n', 'problem.checkbox.invalid'],
            ['checkbox', [], 'checked', 'problem.checkbox.invalid'],
            ['checkbox', [], 'ja', 'problem.checkbox.invalid'],
            ['checkbox', [], "1\x00", 'problem.checkbox.invalid'],
            ['checkbox', [], 'ＹＥＳ', 'problem.checkbox.invalid'],

            ['choice', self::CHOICES, 'Purple', 'problem.choice.invalid'],
            ['choice', self::CHOICES, 'Re', 'problem.choice.invalid'],
            ['choice', self::CHOICES, 'Red Green', 'problem.choice.invalid'],
            ['choice', self::CHOICES, 'Ŕed', 'problem.choice.invalid'],
            ['choice', self::CHOICES, "Red\x00", 'problem.choice.invalid'],
            ['choice', self::CHOICES, 'STRASSE', 'problem.choice.invalid'],
            ['choice', self::CHOICES, 'STRAẞE', 'problem.choice.invalid'],

            ['rating', ['max' => 5], '6', 'problem.rating.invalid', ['max' => 5]],
            ['rating', ['max' => 5], '-1', 'problem.rating.invalid', ['max' => 5]],
            ['rating', ['max' => 5], '2.5', 'problem.rating.invalid', ['max' => 5]],
            ['rating', ['max' => 5], 'abc', 'problem.rating.invalid', ['max' => 5]],
            ['rating', ['max' => 5], '10000', 'problem.rating.invalid', ['max' => 5]],
            ['rating', ['max' => 5], '٣', 'problem.rating.invalid', ['max' => 5]],
            ['rating', ['max' => 3], '4', 'problem.rating.invalid', ['max' => 3]],

            ['url', [], 'example.com', 'problem.url.invalid'],
            ['url', [], 'ftp://example.com/', 'problem.url.invalid'],
            ['url', [], 'javascript:alert(1)', 'problem.url.invalid'],
            ['url', [], 'https://', 'problem.url.invalid'],
            ['url', [], 'https://exa mple.com', 'problem.url.invalid'],
            ['url', [], 'https://例え.jp/', 'problem.url.invalid'],
            ['url', [], 'https://user:secret@example.com/', 'problem.url.invalid'],

            ['email', [], 'user', 'problem.email.invalid'],
            ['email', [], 'user@', 'problem.email.invalid'],
            ['email', [], '@example.com', 'problem.email.invalid'],
            ['email', [], 'a b@example.com', 'problem.email.invalid'],
            ['email', [], 'ü@example.com', 'problem.email.invalid'],
            ['email', [], 'user@example', 'problem.email.invalid'],
            ['email', [], "user@example.com\x00", 'problem.email.invalid'],
            ['email', [], 'user@exam_ple.com', 'problem.email.invalid'],
            ['email', [], 'user@@example.com', 'problem.email.invalid'],

            ['phone', [], '12', 'problem.phone.invalid'],
            ['phone', [], 'abc', 'problem.phone.invalid'],
            ['phone', [], '555-0100 x', 'problem.phone.invalid'],
            ['phone', [], '555/0100', 'problem.phone.invalid'],
            ['phone', [], '(+)', 'problem.phone.invalid'],
            ['phone', [], '+-() 12', 'problem.phone.invalid'],
            ['phone', [], str_repeat('5', 41), 'problem.phone.invalid'],
            ['phone', [], '555-0100 #12', 'problem.phone.invalid'],
            ['phone', [], '５５５-0100', 'problem.phone.invalid'],
            ['phone', [], '555-0100 ext', 'problem.phone.invalid'],
        ];
        foreach (['attachment', 'relation'] as $type) {
            foreach (['1', '1,2', 'x', '[]', 'file.png', '5'] as $text) {
                $rows[] = [$type, [], $text, 'problem.value.unsupported'];
            }
        }
        foreach (['1', '0', '42', ' 7', 'abc', '-1'] as $text) {
            $rows[] = ['auto_number', ['start' => 1, 'next' => 1], $text, 'problem.auto_number.read_only'];
        }
        foreach ($rows as $i => $row) {
            yield $row[0] . ' #' . $i . ' ' . json_encode($row[2], JSON_INVALID_UTF8_SUBSTITUTE) => [$row[0], $row[1], $row[2], $row[3], $row[4] ?? []];
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('accepted')]
    public function testAccepted(string $type, array $options, string $text, mixed $expected): void
    {
        $fieldType = self::types()->get($type);
        $checked = $fieldType->parse($text, $options);
        self::assertTrue($checked->isOk(), 'Refused with ' . $checked->problemKey());
        self::assertSame($expected, $checked->value());
        $again = $fieldType->check($expected, $options);
        self::assertTrue($again->isOk(), 'check() refused the canonical value with ' . $again->problemKey());
        self::assertSame($expected, $again->value());
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string|int> $params
     */
    #[DataProvider('refused')]
    public function testRefused(string $type, array $options, string $text, string $key, array $params): void
    {
        $checked = self::types()->get($type)->parse($text, $options);
        self::assertFalse($checked->isOk(), 'Accepted as ' . var_export($checked->isOk() ? $checked->value() : null, true));
        self::assertSame($key, $checked->problemKey());
        self::assertSame($params, $checked->problemParams());
    }

    public function testEveryTypeHasAtLeastSixOfEach(): void
    {
        $accepted = [];
        foreach (self::accepted() as $row) {
            $accepted[$row[0]] = ($accepted[$row[0]] ?? 0) + 1;
        }
        $refused = [];
        foreach (self::refused() as $row) {
            $refused[$row[0]] = ($refused[$row[0]] ?? 0) + 1;
        }
        foreach (self::types()->ids() as $id) {
            self::assertGreaterThanOrEqual(6, $accepted[$id] ?? 0, $id . ' accepted');
            self::assertGreaterThanOrEqual(6, $refused[$id] ?? 0, $id . ' refused');
        }
    }

    private static function types(): FieldTypes
    {
        return FieldTypes::standard(new Keys(new SeededRandom('strict-parse')));
    }
}
