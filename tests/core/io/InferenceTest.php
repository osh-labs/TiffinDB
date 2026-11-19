<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\ColumnStats;
use TiffinDB\Core\Io\Inference;
use TiffinDB\Core\Io\LinkTarget;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Support\IoFixture;

/** T-IO-5: the inference table of M3 spec section 6.3, row by row. */
#[CoversClass(Inference::class)]
#[CoversClass(ColumnStats::class)]
final class InferenceTest extends TestCase
{
    private const ROOMS = 'p6wz2kq7nm3v';

    /** @param list<?string> $cells */
    private static function stats(array $cells): ColumnStats
    {
        $types = FieldTypes::standard();
        $stats = new ColumnStats();
        foreach ($cells as $cell) {
            $stats->add($cell, $types);
        }

        return $stats;
    }

    /** @return list<LinkTarget> */
    private static function targets(): array
    {
        return [new LinkTarget(self::ROOMS, 'Rooms', 'room', new FieldDef('k7n2qz4mxp3w', self::ROOMS, 'Name', 'text', ['max_length' => 500], false, '', 1, true))];
    }

    /** @return iterable<string, array{string, list<?string>, string, array<string, mixed>}> heading, cells, type, options */
    public static function rules(): iterable
    {
        yield '1 no non-empty cell' => ['Notes', [null, '', '  '], 'text', ['max_length' => 500]];
        yield '2 link by table name' => ['Rooms', ['Kitchen', 'Hall'], 'link:' . self::ROOMS, []];
        yield '2 link by item name, normalised' => [' ROOM_ ', ['Kitchen'], 'link:' . self::ROOMS, []];
        yield '3 checkbox words' => ['Done', ['yes', 'No', 'TRUE', 'false'], 'checkbox', []];
        yield '3 not for 1 and 0' => ['Flag', ['1', '0', '1', '0'], 'number', ['decimals' => 0]];
        yield '3 not for on and off' => ['Flag', ['on', 'off', 'on', 'off'], 'choice', ['choices' => ['on', 'off']]];
        yield '4 date' => ['Purchased', ['2025-01-02', '2024-12-31'], 'date', []];
        yield '5 date and time' => ['Seen', ['2025-01-02T03:04', '2025-01-02 03:04:05'], 'date_time', []];
        yield '5 a date-time column is not a date' => ['Seen', ['2025-01-02T03:04', '2025-01-03'], 'date_time', []];
        yield '6 time' => ['Starts', ['09:30', '1:30'], 'time', []];
        yield '7 currency by symbol' => ['Paid', ['$5.00', '$1,234.5', '12'], 'currency', ['code' => 'USD', 'minor_digits' => 2]];
        yield '7 euro' => ['Spent', ['€1,234.50', '€3'], 'currency', ['code' => 'EUR', 'minor_digits' => 2]];
        yield '7 pound with three decimals' => ['Cost', ['£1.125', '£2'], 'currency', ['code' => 'GBP', 'minor_digits' => 3]];
        yield '7 yen' => ['Cost', ['¥500', '¥1,200'], 'currency', ['code' => 'JPY', 'minor_digits' => 0]];
        yield '7 two decimals and a money heading' => ['Unit price', ['5.00', '12.50'], 'currency', ['code' => 'USD', 'minor_digits' => 2]];
        yield '7 two decimals alone is a number' => ['Weight', ['5.00', '12.50'], 'number', ['decimals' => 2]];
        yield '7 money heading without two decimals (P138)' => ['Total', ['5', '12.5'], 'currency', ['code' => 'USD', 'minor_digits' => 1]];
        yield '7 not for an amount the currency field cannot hold' => ['Paid', ['$1', '$12345678901234567890'], 'text', ['max_length' => 500]];
        yield '7 mixed symbols' => ['Paid', ['$5', '€6', '$7'], 'text', ['max_length' => 500]];
        yield '8 number' => ['Weight', ['1', '2.5', '-3.125', '1,000'], 'number', ['decimals' => 3]];
        yield '9 duration' => ['Length', ['1:30', '26:15'], 'duration', []];
        yield '10 email' => ['Contact', ['user@example.com', 'other@example.org'], 'email', []];
        yield '11 url' => ['Link', ['https://example.com/a', 'http://example.org'], 'url', []];
        yield '12 long text by line break' => ['Notes', ["Line one\nline two", 'Short'], 'long_text', ['max_length' => 20000]];
        yield '12 long text by length' => ['Notes', [str_repeat('a', 501)], 'long_text', ['max_length' => 20000]];
        yield '13 choice' => ['Colour', ['Red', 'Blue', 'Red', 'Blue', 'red', 'Blue'], 'choice', ['choices' => ['Red', 'Blue']]];
        yield '13 not with 21 distinct values' => ['Code', array_merge(...array_fill(0, 2, array_map(static fn (int $i): string => 'C' . $i, range(1, 21)))), 'text', ['max_length' => 500]];
        yield '13 not when values rarely repeat' => ['Code', ['A', 'B', 'C', 'A'], 'text', ['max_length' => 500]];
        yield '13 not for long values' => ['Code', [str_repeat('x', 61), str_repeat('x', 61)], 'text', ['max_length' => 500]];
        yield '14 text' => ['Name', ['Spare Lamp', 'Old Kettle'], 'text', ['max_length' => 500]];
    }

    /**
     * @param list<?string> $cells
     * @param array<string, mixed> $options
     */
    #[DataProvider('rules')]
    public function testRule(string $heading, array $cells, string $type, array $options): void
    {
        $guess = Inference::guess($heading, self::stats($cells), self::targets());
        self::assertSame($type, $guess->type);
        self::assertSame($options, $guess->options);
        self::assertSame($type, $guess->typeChoices[0], 'The proposed type comes first in the menu.');
        self::assertTrue($guess->include);
    }

    public function testTheCurrencyCounterUsesTheLenientParse(): void
    {
        $stats = self::stats(['$1', '$12345678901234567890', '$899,999,999,999.99']);
        self::assertSame(2, $stats->counter('currency'), 'Twenty digits are past the currency limit; twelve are not.');
        self::assertSame(1, self::stats(['$900,000,000,000'])->counter('currency'));
        self::assertSame(0, self::stats(['$900,000,000,000.5'])->counter('currency'), 'Counted under the tightest limit any proposed options give.');
        self::assertSame('text', Inference::guess('Paid', self::stats(['$1', '$12345678901234567890']), [])->type);
    }

    public function testLinksNeedATextLikeTitleAndANonEmptyColumn(): void
    {
        self::assertSame('text', Inference::guess('Rooms', self::stats([]), self::targets())->type);
        self::assertSame('text', Inference::guess('Rooms', self::stats(['Kitchen']), [])->type);
        self::assertContains('link:' . self::ROOMS, Inference::guess('Name', self::stats(['Kitchen']), self::targets())->typeChoices);
    }

    /**
     * @param list<string> $first
     * @return list<string> $first, then every importable type not in it (the rest of the menu)
     */
    private static function menu(array $first): array
    {
        return array_values(array_unique([...$first, ...Inference::IMPORTABLE]));
    }

    public function testTheTypeMenu(): void
    {
        $rating = Inference::guess('Stars', self::stats(['1', '5', '3', '2', '4', '5']), self::targets());
        self::assertSame('number', $rating->type, 'Rating is offered, never proposed (P117).');
        self::assertSame([...self::menu(['number', 'currency', 'rating', 'choice', 'text', 'long_text']), 'link:' . self::ROOMS], $rating->typeChoices);

        $date = Inference::guess('When', self::stats(['2025-01-02', '2025-02-03', '2025-03-04']), []);
        self::assertSame(self::menu(['date', 'date_time', 'choice', 'text', 'long_text']), $date->typeChoices);

        $two = Inference::guess('Weight', self::stats(['5.00', '12.50']), []);
        self::assertSame('number', $two->type);
        self::assertContains('currency', $two->typeChoices, 'Currency is offered for two decimals.');

        $empty = Inference::guess('Blank', self::stats([]), []);
        self::assertSame(self::menu(['text', 'checkbox', 'date', 'date_time', 'time', 'currency', 'number', 'duration', 'email', 'url', 'rating', 'choice', 'long_text']), $empty->typeChoices);

        // More than 200 values: choice is only in the rest of the menu (MappingCheck refuses it with too_many_choices).
        $many = Inference::guess('Code', self::stats(array_map(static fn (int $i): string => 'C' . $i, range(1, 201))), []);
        self::assertSame(self::menu(['text', 'long_text']), $many->typeChoices);
        self::assertTrue(self::stats(array_map(static fn (int $i): string => 'C' . $i, range(1, 202)))->moreDistinct);
    }

    public function testEveryImportableTypeIsOfferedForEveryColumn(): void
    {
        // M3-WP11 X-9 and X-14: Date for a column with some unreadable dates, and Phone, are always in the menu.
        $columns = [
            'Purchased' => ['2025-01-02', 'last spring', '2025-03-04', 'n/a'],
            'Phone' => ['555-0100', '555-0123 ext 4'],
            'Hostile' => ["\x00\x07", '"; DROP TABLE t; --', "\u{202E}gnp.exe", str_repeat('x', 600)],
            'Empty' => [null, ''],
        ];
        foreach ($columns as $heading => $cells) {
            $guess = Inference::guess($heading, self::stats($cells), self::targets());
            foreach (Inference::IMPORTABLE as $type) {
                self::assertContains($type, $guess->typeChoices, $heading . ': ' . $type);
            }
            self::assertSame($guess->typeChoices, array_values(array_unique($guess->typeChoices)), $heading . ': no repeats');
            self::assertSame('link:' . self::ROOMS, $guess->typeChoices[count($guess->typeChoices) - 1]);
        }
        self::assertSame('text', Inference::guess('Purchased', self::stats($columns['Purchased']), [])->type, 'Not every cell is a date: Date is offered, not proposed.');
        self::assertSame('long_text', Inference::guess('Hostile', self::stats($columns['Hostile']), [])->type);
    }

    public function testWebAddressesNeedAnExplicitScheme(): void
    {
        foreach ([['stand-mixer.jpg', 'a.jpg'], ['report.pdf', 'notes.txt'], ['example.com/x', 'example.org'], ['javascript:alert(1)', 'ftp://example.com/f']] as $cells) {
            $stats = self::stats($cells);
            self::assertSame(0, $stats->counter('url'), implode(', ', $cells));
            self::assertNotSame('url', Inference::guess('Picture', $stats, [])->type, implode(', ', $cells));
        }
        foreach ([['https://example.com/a', 'http://example.org'], ['www.example.com', 'WWW.example.org/x'], ['HTTPS://EXAMPLE.COM']] as $cells) {
            self::assertSame('url', Inference::guess('Link', self::stats($cells), [])->type, implode(', ', $cells));
        }
        self::assertSame('text', Inference::guess('Picture', self::stats(['https://example.com/a', 'b.jpg']), [])->type);
    }

    public function testMoneyHeadingsProposeCurrencyAndCheckboxNeedsWords(): void
    {
        // P138: a money word with plain amounts (an XLSX number cell "240") proposes currency.
        self::assertSame('currency', Inference::guess('Price', self::stats(['240', '15', '9.99']), [])->type);
        self::assertSame(['code' => 'USD', 'minor_digits' => 2], Inference::guess('Value', self::stats(['240', '15']), [])->options);
        self::assertSame('number', Inference::guess('Count', self::stats(['240', '15']), [])->type);
        // P117: 1 and 0 are numbers; only yes, no, true, false propose a checkbox (checkbox stays in the menu).
        $flags = Inference::guess('In use', self::stats(['1', '0', '1']), []);
        self::assertSame('number', $flags->type);
        self::assertContains('checkbox', $flags->typeChoices);
        self::assertSame('checkbox', Inference::guess('In use', self::stats(['yes', 'no', 'YES']), [])->type);
    }

    public function testFirstValuesAreCutWithAnEllipsis(): void
    {
        $stats = self::stats([str_repeat("\u{00e9}", 70), str_repeat('a', 60), 'short']);
        self::assertSame([str_repeat("\u{00e9}", 59) . "\u{2026}", str_repeat('a', 60)], $stats->firstValues);
        foreach ($stats->firstValues as $value) {
            self::assertSame(1, preg_match('//u', $value));
            self::assertLessThanOrEqual(60, preg_match_all('/./su', $value));
        }
        self::assertSame("ab\u{2026}", ColumnStats::sample('abcdef', 3));
        self::assertSame('abc', ColumnStats::sample('abc', 3));
        self::assertSame('', ColumnStats::sample("\xFF\xFE", 3));
        self::assertSame("\u{1F600}\u{2026}", ColumnStats::sample(str_repeat("\u{1F600}", 100), 2));
    }

    public function testTheIdRule(): void
    {
        foreach (['ID', 'id', 'Item id', 'item_id', 'item-ID'] as $heading) {
            self::assertFalse(Inference::guess($heading, self::stats(['1', '2', '10']), [])->include, $heading);
        }
        self::assertTrue(Inference::guess('ID', self::stats(['3', '2', '1']), [])->include, 'Not increasing.');
        self::assertTrue(Inference::guess('ID', self::stats(['1', '1', '2']), [])->include, 'Not distinct.');
        self::assertTrue(Inference::guess('ID', self::stats(['1', 'x2']), [])->include, 'Not integers.');
        self::assertTrue(Inference::guess('Paid', self::stats(['1', '2', '3']), [])->include, 'Not an id heading.');
        self::assertTrue(Inference::guess('Idea', self::stats(['1', '2', '3']), [])->include);
        self::assertTrue(Inference::guess('ID', self::stats([]), [])->include);
    }

    public function testFieldNames(): void
    {
        self::assertSame('Row id', Inference::fieldName('row_id', 0));
        self::assertSame('First name', Inference::fieldName("  first-name\t ", 0));
        self::assertSame('A b c', Inference::fieldName('a  _-_ b   c', 0));
        self::assertSame('Column 3', Inference::fieldName('', 2));
        self::assertSame('Column 1', Inference::fieldName(" \x01 ", 0));
        self::assertSame('Column 4', Inference::fieldName("\xFF\xFE", 3));
        self::assertSame("\u{00e9}t\u{00e9}", Inference::fieldName("\u{00e9}t\u{00e9}", 0), 'Only an ASCII first letter is upper-cased.');
        self::assertSame(str_repeat('A', 60), Inference::fieldName(str_repeat('A', 70), 0));

        $guesses = Inference::guessAll(['Name', 'name', 'NAME', '', 'Column 4', str_repeat('Long', 20), str_repeat('Long', 20)], array_fill(0, 7, new ColumnStats()), []);
        self::assertSame(['Name', 'Name 2', 'NAME 3', 'Column 4', 'Column 4 2', str_repeat('Long', 15), str_repeat('Long', 14) . 'Lo 2'], array_map(static fn ($g): string => $g->fieldName, $guesses));
        foreach ($guesses as $guess) {
            self::assertLessThanOrEqual(60, strlen($guess->fieldName));
        }
    }

    public function testStatisticsAndOptions(): void
    {
        $stats = self::stats(['  Spare Lamp  ', 'Spare Lamp', str_repeat('b', 70), null, '']);
        self::assertSame(3, $stats->count);
        self::assertSame(2, $stats->distinct());
        self::assertSame(['Spare Lamp', str_repeat('b', 59) . "\u{2026}"], $stats->firstValues);
        self::assertSame(70, $stats->maxLength);
        $restored = ColumnStats::fromArray(json_decode(json_encode($stats->toArray(), JSON_THROW_ON_ERROR), true));
        self::assertSame($stats->toArray(), $restored->toArray());

        self::assertSame(['max_length' => 10000], Inference::optionsFor('text', self::stats([str_repeat('x', 12000)])));
        self::assertSame(['max_length' => 800], Inference::optionsFor('text', self::stats([str_repeat('x', 800)])));
        self::assertSame(['max' => 5], Inference::optionsFor('rating', self::stats(['1'])));
        self::assertSame([], Inference::optionsFor('link:' . self::ROOMS, self::stats(['1'])));
        self::assertSame(['decimals' => 6], Inference::optionsFor('number', self::stats(['1.12345678'])));

        foreach ([['count' => -1], ['distinct' => 'x'], ['distinct' => [1]], ['counters' => ['number' => '3']]] as $bad) {
            try {
                ColumnStats::fromArray($bad);
                self::fail('Bad statistics were read.');
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSeededItemsGuessAsExpected(): void
    {
        $rows = (new IoFixture())->itemRows(200);
        $types = FieldTypes::standard();
        $stats = array_map(static fn (): ColumnStats => new ColumnStats(), IoFixture::HEADINGS);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $stats[$i]->add($cell, $types);
            }
        }
        $guesses = Inference::guessAll(IoFixture::HEADINGS, $stats, self::targets());
        self::assertSame(['text', 'link:' . self::ROOMS, 'currency', 'date', 'number', 'checkbox', 'text'], array_map(static fn ($g): string => $g->type, $guesses));
        self::assertSame(['code' => 'USD', 'minor_digits' => 2], $guesses[2]->options);
    }
}
