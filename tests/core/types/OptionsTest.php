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
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-TYP-5: normaliseOptions defaults, every problem of the options table in
 * M1 spec section 8.2, unknown options dropped, choice keys kept or
 * generated. Option names and defaults as `docs/formats/tiffin.md` section 6.
 */
#[CoversNamespace('TiffinDB\\Core\\Schema\\Type')]
final class OptionsTest extends TestCase
{
    private const SEED = 'tiffindb-options-test-1';

    public function testDefaults(): void
    {
        $expected = [
            'text' => ['max_length' => 500],
            'long_text' => ['max_length' => 20000],
            'number' => ['decimals' => 0, 'min' => null, 'max' => null],
            'currency' => ['code' => 'USD', 'minor_digits' => 2],
            'date' => [],
            'time' => ['seconds' => false],
            'date_time' => ['seconds' => false],
            'duration' => ['seconds' => false],
            'checkbox' => [],
            'choice' => ['choices' => []],
            'rating' => ['max' => 5],
            'url' => [],
            'email' => [],
            'phone' => [],
            'attachment' => ['images_only' => false],
            'relation' => [],
            'auto_number' => ['start' => 1, 'next' => 1],
        ];
        $types = self::types();
        self::assertSame(array_keys($expected), $types->ids());
        foreach ($expected as $id => $defaults) {
            self::assertSame($defaults, $types->get($id)->defaultOptions(), $id);
            if ($id !== 'choice') {
                self::assertSame($defaults, $types->get($id)->normaliseOptions([]), $id . ' from no options');
                self::assertSame($defaults, $types->get($id)->normaliseOptions(array_map(static fn () => null, $defaults)), $id . ' from nulls');
            }
        }
    }

    public function testUnknownOptionsAreDropped(): void
    {
        $types = self::types();
        $extra = ['colour' => 'red', 'max_lenght' => 3, '' => 1, 'relation' => 'abcdefghijkl', 'side' => 'left'];
        foreach ($types->ids() as $id) {
            $given = $id === 'choice' ? $extra + ['choices' => ['A']] : $extra;
            $normalised = $types->get($id)->normaliseOptions($given);
            self::assertSame(array_keys($types->get($id)->defaultOptions()), array_keys($normalised), $id);
        }
        self::assertSame([], $types->get('relation')->normaliseOptions(['relation' => 'abcdefghijkl', 'side' => 'left', 'links' => 'one']));
    }

    public function testGivenValuesAreKeptAndNormalised(): void
    {
        $types = self::types();
        self::assertSame(['max_length' => 1], $types->get('text')->normaliseOptions(['max_length' => 1]));
        self::assertSame(['max_length' => 10000], $types->get('text')->normaliseOptions(['max_length' => '10000']));
        self::assertSame(['max_length' => 1000000], $types->get('long_text')->normaliseOptions(['max_length' => 1000000]));
        self::assertSame(['decimals' => 6, 'min' => -2, 'max' => 1000.5], $types->get('number')->normaliseOptions(['decimals' => 6, 'min' => '-2', 'max' => '1,000.5']));
        self::assertSame(['decimals' => 0, 'min' => 2, 'max' => null], $types->get('number')->normaliseOptions(['min' => 2.0, 'max' => '']));
        self::assertSame(['decimals' => 0, 'min' => 0, 'max' => 0.5], $types->get('number')->normaliseOptions(['min' => -0.0, 'max' => 0.5]));
        self::assertSame(['code' => 'EUR', 'minor_digits' => 0], $types->get('currency')->normaliseOptions(['code' => 'eur', 'minor_digits' => 0]));
        self::assertSame(['code' => 'XAU', 'minor_digits' => 4], $types->get('currency')->normaliseOptions(['code' => 'XaU', 'minor_digits' => '4']));
        foreach (['time', 'date_time', 'duration'] as $id) {
            self::assertSame(['seconds' => true], $types->get($id)->normaliseOptions(['seconds' => true]), $id);
            self::assertSame(['seconds' => true], $types->get($id)->normaliseOptions(['seconds' => '1']), $id);
            self::assertSame(['seconds' => false], $types->get($id)->normaliseOptions(['seconds' => 0]), $id);
        }
        self::assertSame(['max' => 3], $types->get('rating')->normaliseOptions(['max' => 3]));
        self::assertSame(['max' => 10], $types->get('rating')->normaliseOptions(['max' => 10]));
        self::assertSame(['images_only' => true], $types->get('attachment')->normaliseOptions(['images_only' => true]));
        self::assertSame(['start' => 0, 'next' => 0], $types->get('auto_number')->normaliseOptions(['start' => 0]));
        self::assertSame(['start' => 1000000000, 'next' => 1000000000], $types->get('auto_number')->normaliseOptions(['start' => 1000000000]));
        self::assertSame(['start' => 1, 'next' => 4], $types->get('auto_number')->normaliseOptions(['start' => 1, 'next' => 4]));
        self::assertSame(['start' => 5, 'next' => 5], $types->get('auto_number')->normaliseOptions(['start' => 5, 'next' => 2]), 'next below start');
        self::assertSame(['start' => 1, 'next' => 1], $types->get('auto_number')->normaliseOptions(['next' => 'x']), 'next not a number');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<array{string, string, array<string, string|int>}>}>
     */
    public static function problems(): iterable
    {
        $range = static fn (string $field, int $min, int $max): array => [$field, 'problem.options.range', ['min' => $min, 'max' => $max]];
        yield 'text max_length 0' => ['text', ['max_length' => 0], [$range('options.max_length', 1, 10000)]];
        yield 'text max_length 10001' => ['text', ['max_length' => 10001], [$range('options.max_length', 1, 10000)]];
        yield 'text max_length text' => ['text', ['max_length' => 'abc'], [$range('options.max_length', 1, 10000)]];
        yield 'text max_length float' => ['text', ['max_length' => 1.5], [$range('options.max_length', 1, 10000)]];
        yield 'text max_length bool' => ['text', ['max_length' => true], [$range('options.max_length', 1, 10000)]];
        yield 'long_text max_length' => ['long_text', ['max_length' => 1000001], [$range('options.max_length', 1, 1000000)]];
        yield 'number decimals' => ['number', ['decimals' => 7], [$range('options.decimals', 0, 6)]];
        yield 'number decimals negative' => ['number', ['decimals' => -1], [$range('options.decimals', 0, 6)]];
        yield 'number min text' => ['number', ['min' => 'abc'], [['options.min', 'problem.number.invalid', []]]];
        yield 'number max array' => ['number', ['max' => []], [['options.max', 'problem.number.invalid', []]]];
        yield 'number max too large' => ['number', ['max' => 1e16], [['options.max', 'problem.number.invalid', []]]];
        yield 'number min not finite' => ['number', ['min' => NAN], [['options.min', 'problem.number.invalid', []]]];
        yield 'number min exponent' => ['number', ['min' => '1e3'], [['options.min', 'problem.number.invalid', []]]];
        yield 'number min equals max' => ['number', ['min' => 5, 'max' => 5], [['options.min', 'problem.options.min_max', []]]];
        yield 'number min above max' => ['number', ['min' => '6', 'max' => 5.5], [['options.min', 'problem.options.min_max', []]]];
        yield 'currency code short' => ['currency', ['code' => 'US'], [['options.code', 'problem.options.currency_code', []]]];
        yield 'currency code long' => ['currency', ['code' => 'USDX'], [['options.code', 'problem.options.currency_code', []]]];
        yield 'currency code digit' => ['currency', ['code' => 'U5D'], [['options.code', 'problem.options.currency_code', []]]];
        yield 'currency code non-ASCII' => ['currency', ['code' => 'ÜSD'], [['options.code', 'problem.options.currency_code', []]]];
        yield 'currency code newline' => ['currency', ['code' => "USD\n"], [['options.code', 'problem.options.currency_code', []]]];
        yield 'currency code int' => ['currency', ['code' => 840], [['options.code', 'problem.options.currency_code', []]]];
        yield 'currency minor_digits' => ['currency', ['minor_digits' => 5], [$range('options.minor_digits', 0, 4)]];
        yield 'time seconds' => ['time', ['seconds' => 'yes'], [['options.seconds', 'problem.options.invalid', []]]];
        yield 'date_time seconds' => ['date_time', ['seconds' => 2], [['options.seconds', 'problem.options.invalid', []]]];
        yield 'duration seconds' => ['duration', ['seconds' => []], [['options.seconds', 'problem.options.invalid', []]]];
        yield 'choice none' => ['choice', ['choices' => []], [['options.choices', 'problem.options.choices_empty', []]]];
        yield 'choice missing' => ['choice', [], [['options.choices', 'problem.options.choices_empty', []]]];
        yield 'choice blank lines only' => ['choice', ['choices' => ['', '  ', "\t"]], [['options.choices', 'problem.options.choices_empty', []]]];
        yield 'choice not a list' => ['choice', ['choices' => 'Red'], [['options.choices', 'problem.options.invalid', []], ['options.choices', 'problem.options.choices_empty', []]]];
        yield 'choice entry of the wrong kind' => ['choice', ['choices' => ['Red', 5]], [['options.choices', 'problem.options.invalid', []]]];
        yield 'choice too many' => ['choice', ['choices' => array_map(static fn (int $i): string => 'Choice ' . $i, range(1, 201))], [['options.choices', 'problem.options.choices_too_many', ['max' => 200]]]];
        yield 'choice too long' => ['choice', ['choices' => [str_repeat('é', 101), 'B']], [['options.choices', 'problem.options.choice_too_long', ['max' => 100]]]];
        yield 'choice duplicate ignoring case' => ['choice', ['choices' => ['Red', 'Blue', 'red', 'RED']], [['options.choices', 'problem.options.choices_duplicate', ['label' => 'red']]]];
        yield 'choice duplicate after trimming' => ['choice', ['choices' => ['Red', ' Red ']], [['options.choices', 'problem.options.choices_duplicate', ['label' => 'Red']]]];
        yield 'choice control' => ['choice', ['choices' => ["Re\x00d", "\u{200B}", 'Blue']], [['options.choices', 'problem.text.control', []]]];
        yield 'choice invalid UTF-8' => ['choice', ['choices' => ["\xC3"]], [['options.choices', 'problem.text.control', []]]];
        yield 'choice tab' => ['choice', ['choices' => ["A\tB"]], [['options.choices', 'problem.text.control', []]]];
        yield 'choice several problems' => ['choice', ['choices' => ['A', 'a', str_repeat('x', 101), "B\x01"]], [
            ['options.choices', 'problem.options.choices_duplicate', ['label' => 'a']],
            ['options.choices', 'problem.options.choice_too_long', ['max' => 100]],
            ['options.choices', 'problem.text.control', []],
        ]];
        yield 'rating max 2' => ['rating', ['max' => 2], [$range('options.max', 3, 10)]];
        yield 'rating max 11' => ['rating', ['max' => 11], [$range('options.max', 3, 10)]];
        yield 'auto_number start negative' => ['auto_number', ['start' => -1], [$range('options.start', 0, 1000000000)]];
        yield 'auto_number start too large' => ['auto_number', ['start' => 1000000001], [$range('options.start', 0, 1000000000)]];
        yield 'attachment images_only' => ['attachment', ['images_only' => 'x'], [['options.images_only', 'problem.options.invalid', []]]];
        yield 'every number problem together' => ['number', ['decimals' => 9, 'min' => 'x', 'max' => 'y'], [
            $range('options.decimals', 0, 6),
            ['options.min', 'problem.number.invalid', []],
            ['options.max', 'problem.number.invalid', []],
        ]];
        yield 'every currency problem together' => ['currency', ['code' => 'x', 'minor_digits' => 9], [
            ['options.code', 'problem.options.currency_code', []],
            $range('options.minor_digits', 0, 4),
        ]];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array{string, string, array<string, string|int>}> $expected
     */
    #[DataProvider('problems')]
    public function testProblems(string $type, array $options, array $expected): void
    {
        try {
            self::types()->get($type)->normaliseOptions($options);
            self::fail('normaliseOptions accepted the options.');
        } catch (ValidationFailed $e) {
            self::assertSame($expected, array_map(
                static fn (Problem $p): array => [$p->field, $p->key, $p->params],
                $e->problems,
            ));
        }
    }

    public function testChoiceLabelsAreTrimmedAndBlankEntriesDropped(): void
    {
        $normalised = self::types()->get('choice')->normaliseOptions(['choices' => [' Red ', '', ['label' => "Blue\u{00A0}"], '   ', str_repeat('é', 100)]]);
        self::assertSame(['Red', 'Blue', str_repeat('é', 100)], array_column($normalised['choices'], 'label'));
    }

    public function testChoiceKeysAreKeptOrGenerated(): void
    {
        $choice = FieldTypes::standard(new Keys(new SeededRandom(self::SEED)))->get('choice');
        $normalised = $choice->normaliseOptions(['choices' => [
            ['key' => 'a3kq7vn2xbmz', 'label' => 'Appliance'],
            ['key' => 'c6zt2mq4wn7k', 'label' => 'Tool'],
            ['label' => 'Furniture'],
            ['key' => 'NOT-A-KEY', 'label' => 'Book'],
            ['key' => 'a3kq7vn2xbmz', 'label' => 'Duplicate key'],
            'Plain label',
        ]]);
        $choices = $normalised['choices'];
        self::assertSame(['Appliance', 'Tool', 'Furniture', 'Book', 'Duplicate key', 'Plain label'], array_column($choices, 'label'));
        self::assertSame('a3kq7vn2xbmz', $choices[0]['key']);
        self::assertSame('c6zt2mq4wn7k', $choices[1]['key']);
        foreach ($choices as $entry) {
            self::assertSame(['key', 'label'], array_keys($entry));
            self::assertTrue(Keys::isValid($entry['key']));
        }
        $keys = array_column($choices, 'key');
        self::assertSame($keys, array_values(array_unique($keys)), 'Keys are unique within the field.');

        // The same seed generates the same keys; normalising again keeps them all.
        $again = FieldTypes::standard(new Keys(new SeededRandom(self::SEED)))->get('choice')->normaliseOptions(['choices' => [
            ['key' => 'a3kq7vn2xbmz', 'label' => 'Appliance'],
            ['key' => 'c6zt2mq4wn7k', 'label' => 'Tool'],
            ['label' => 'Furniture'],
            ['key' => 'NOT-A-KEY', 'label' => 'Book'],
            ['key' => 'a3kq7vn2xbmz', 'label' => 'Duplicate key'],
            'Plain label',
        ]]);
        self::assertSame($normalised, $again);
        self::assertSame($normalised, $choice->normaliseOptions($normalised));
    }

    public function testNormalisedOptionsSurviveJson(): void
    {
        $types = self::types();
        foreach ($types->ids() as $id) {
            $given = $id === 'choice' ? ['choices' => ['A', 'B']] : ($id === 'number' ? ['decimals' => 2, 'min' => 0.5, 'max' => 100] : []);
            $normalised = $types->get($id)->normaliseOptions($given);
            $json = json_encode((object) $normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($normalised, $types->get($id)->normaliseOptions($decoded), $id);
        }
        self::assertSame(
            '{"choices":[{"key":"a3kq7vn2xbmz","label":"Appliance"},{"key":"c6zt2mq4wn7k","label":"Tool"}]}',
            json_encode($types->get('choice')->normaliseOptions(['choices' => [
                ['key' => 'a3kq7vn2xbmz', 'label' => 'Appliance'],
                ['key' => 'c6zt2mq4wn7k', 'label' => 'Tool'],
            ]]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'The stored form of format document section 6.',
        );
        self::assertSame('{"code":"USD","minor_digits":2}', json_encode($types->get('currency')->normaliseOptions([])));
        self::assertSame('{"max_length":500}', json_encode($types->get('text')->normaliseOptions([])));
    }

    private static function types(): FieldTypes
    {
        return FieldTypes::standard(new Keys(new SeededRandom(self::SEED)));
    }
}
