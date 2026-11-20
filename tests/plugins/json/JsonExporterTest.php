<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Json;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\ExportCollection;
use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportRule;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/JsonFixture.php';
require_once __DIR__ . '/FakeExportSource.php';

/**
 * T-JSON-2 (M3 spec section 15.5) and the plugin's behaviour around it:
 * currency strings for large and negative amounts and every `minor_digits`,
 * numbers with 15 significant digits written as the field type's text,
 * memory flat over 20,000 streamed records, options floats without an
 * exponent, values in field order, and failures. The plugin is loaded
 * through the scanner and loader from the repository's plugins/ folder.
 */
#[CoversNothing]
final class JsonExporterTest extends TestCase
{
    use JsonFixture;

    private const TABLE = 'h3kq7vn2xbma';

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** @param list<ExportField> $fields */
    private static function table(array $fields, int $count = 1): ExportTable
    {
        return new ExportTable(self::TABLE, 'Items', 'item', $fields[0]->key ?? null, $fields, $count);
    }

    private static function field(string $key, string $type, array $options = []): ExportField
    {
        return new ExportField($key, 'Field ' . $key, $type, $options, false, '', null);
    }

    /** @param array<string, ExportValue> $values */
    private static function record(int $id, array $values): ExportRecord
    {
        return new ExportRecord($id, '2026-11-12T03:16:00Z', 'a2kq7vn3xbmz', '2026-11-18T14:32:00Z', null, $values);
    }

    /**
     * Exports one table whose records are given and returns the raw text and the record lines.
     *
     * @param list<ExportField> $fields
     * @param list<ExportRecord> $records
     * @return array{string, list<string>}
     */
    private function exportRecords(array $fields, array $records): array
    {
        $source = new FakeExportSource([self::table($fields, count($records))], static fn (string $key): array => $records);
        $json = $this->export($source);
        $lines = array_values(array_filter(explode("\n", $json), static fn (string $line): bool => str_starts_with($line, '{"id":')));
        self::assertCount(count($records), $lines, 'One line per record.');

        return [$json, $lines];
    }

    public function testCurrencyIsExactForLargeAndNegativeAmountsAndEveryMinorDigits(): void
    {
        $cases = [
            0 => [0 => '0', 1200 => '1200', -5 => '-5', 9000000000000000 => '9000000000000000', -9000000000000000 => '-9000000000000000'],
            1 => [0 => '0.0', 5 => '0.5', -5 => '-0.5', 12345 => '1234.5', -9000000000000000 => '-900000000000000.0'],
            2 => [32900 => '329.00', -550 => '-5.50', 1 => '0.01', -1 => '-0.01', 120000 => '1200.00', 9000000000000000 => '90000000000000.00', -9000000000000000 => '-90000000000000.00'],
            3 => [1 => '0.001', -1000 => '-1.000', 1234567890123456 => '1234567890123.456', -999 => '-0.999'],
            4 => [5 => '0.0005', -123456789 => '-12345.6789', 9000000000000000 => '900000000000.0000'],
        ];
        $types = FieldTypes::standard();
        $fields = [];
        $values = [];
        $expected = [];
        $n = 0;
        foreach ($cases as $digits => $amounts) {
            foreach ($amounts as $minor => $text) {
                $key = sprintf('c%011d', $n++);
                $options = ['code' => 'EUR', 'minor_digits' => $digits];
                $fields[] = self::field($key, 'currency', $options);
                $values[$key] = new ExportValue($minor, $types->get('currency')->toText($minor, $options), []);
                $expected[$key] = $text;
                self::assertSame($text, $types->get('currency')->toText($minor, $options), 'The case agrees with the field type.');
            }
        }
        $fields[] = self::field('cempty000000', 'currency', ['code' => 'USD', 'minor_digits' => 2]);
        $values['cempty000000'] = new ExportValue(null, '', []);
        $expected['cempty000000'] = null;

        [$json] = $this->exportRecords($fields, [self::record(1, $values)]);
        self::assertSame($expected, self::decode($json)['tables'][0]['records'][0]['values']);
    }

    public function testNumbersAreTheFieldTypesTextWithFifteenSignificantDigits(): void
    {
        $cases = [
            [0, 123456789012345, '123456789012345'],
            [0, -999999999999999, '-999999999999999'],
            [0, 12, '12'],
            [0, 0, '0'],
            [2, 1234567890123.45, '1234567890123.45'],
            [2, -9999999999999.99, '-9999999999999.99'],
            [6, 123456789.123456, '123456789.123456'],
            [6, 0.000001, '0.000001'],
            [6, -0.000001, '-0.000001'],
            [2, 12.0, '12.00'],
            [3, -3.25, '-3.250'],
            [1, 0.0, '0.0'],
        ];
        $type = FieldTypes::standard()->get('number');
        $fields = [];
        $values = [];
        foreach ($cases as $i => [$decimals, $value, $text]) {
            $key = sprintf('n%011d', $i);
            $options = ['decimals' => $decimals, 'min' => null, 'max' => null];
            self::assertTrue($type->check($value, $options)->isOk(), 'Case ' . $i . ' is a valid number.');
            self::assertSame($text, $type->toText($value, $options), 'Case ' . $i . ' agrees with the field type.');
            $fields[] = self::field($key, 'number', $options);
            $values[$key] = new ExportValue($value, $text, []);
        }
        [$json, $lines] = $this->exportRecords($fields, [self::record(1, $values)]);
        $decoded = self::decode($json)['tables'][0]['records'][0]['values'];
        foreach ($cases as $i => [, $value, $text]) {
            $key = sprintf('n%011d', $i);
            self::assertMatchesRegularExpression('/"' . $key . '":' . preg_quote($text, '/') . '[,}]/', $lines[0], 'Raw token of case ' . $i);
            self::assertEquals($value, $decoded[$key], 'Case ' . $i . ' reads back as the same number.');
            self::assertLessThanOrEqual(15, strlen(ltrim(str_replace(['-', '.'], '', $text), '0')) ?: 1);
        }
        self::assertDoesNotMatchRegularExpression('/[0-9][eE][-+]?[0-9]/', $lines[0], 'No exponent.');
        self::assertStringNotContainsString('12.0,', $lines[0]);
    }

    public function testANumberTextThatIsNotAPlainDecimalIsRefused(): void
    {
        $fields = [self::field('nbad00000000', 'number', ['decimals' => 0])];
        $source = new FakeExportSource([self::table($fields)], static fn (string $key): array => [self::record(1, ['nbad00000000' => new ExportValue(1e20, '1.0e+20', [])])]);
        $path = $this->temp->path('bad.json');
        try {
            $this->exporter()->write($source, $this->exporter()->settings('project'), $path);
            self::fail('An exponent was written.');
        } catch (PluginFailure $failure) {
            self::assertSame(PluginFailure::REASON_FAILED, $failure->reason);
        }
        self::assertFileDoesNotExist($path, 'The partly written file is removed.');
    }

    public function testStreamingKeepsMemoryFlatOver20000Records(): void
    {
        $fields = [
            self::field('m4pz7k2qsw3n', 'text'), self::field('b7xk2mq4nz3p', 'currency', ['code' => 'USD', 'minor_digits' => 2]),
            self::field('r5vk3pn7qx2m', 'relation', ['relation' => 'c2vb6nxq4tkm', 'side' => 'left']), self::field('w2qn6kz7tb4x', 'number', ['decimals' => 2]),
        ];
        $source = static fn (int $count): FakeExportSource => new FakeExportSource([self::table($fields, $count)], static function (string $key) use ($count): \Generator {
            for ($id = 1; $id <= $count; $id++) {
                yield self::record($id, [
                    'm4pz7k2qsw3n' => new ExportValue('Synthetic item ' . $id, 'Synthetic item ' . $id, []),
                    'b7xk2mq4nz3p' => new ExportValue($id * 101, '', []),
                    'r5vk3pn7qx2m' => new ExportValue([$id % 50 + 1], '', [$id % 50 + 1]),
                    'w2qn6kz7tb4x' => new ExportValue($id / 4, sprintf('%.2F', $id / 4), []),
                ]);
            }
        });
        $exporter = $this->exporter();
        $exporter->write($source(10), $exporter->settings('project'), $this->temp->path('warm.json'));

        $peaks = [];
        foreach ([2000, 20000] as $count) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $exporter->write($source($count), $exporter->settings('project'), $this->temp->path($count . '.json'));
            $peaks[$count] = memory_get_peak_usage() - $before;
        }
        self::assertLessThan(256 * 1024, $peaks[20000] - $peaks[2000], 'Memory grows with the number of records: ' . json_encode($peaks));
        self::assertLessThan(2 * 1024 * 1024, $peaks[20000]);

        $handle = fopen($this->temp->path('20000.json'), 'rb');
        self::assertIsResource($handle);
        $records = 0;
        $last = '';
        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, '{"id":')) {
                $records++;
                $last = $line;
            }
        }
        fclose($handle);
        self::assertSame(20000, $records);
        self::assertStringStartsWith('{"id":20000,', $last);
        self::assertStringContainsString('"b7xk2mq4nz3p":"20200.00"', $last);
        $decoded = self::decode((string) file_get_contents($this->temp->path('20000.json')));
        self::assertCount(20000, $decoded['tables'][0]['records']);
    }

    public function testValuesFollowFieldOrderAndMissingValuesAreEmpty(): void
    {
        $fields = [
            self::field('aaaaaaaaaaaa', 'text'), self::field('bbbbbbbbbbbb', 'checkbox'), self::field('cccccccccccc', 'relation', ['relation' => 'c2vb6nxq4tkm', 'side' => 'right']),
            self::field('dddddddddddd', 'attachment'), self::field('eeeeeeeeeeee', 'rating', ['max' => 5]), self::field('ffffffffffff', 'date', []),
        ];
        $record = self::record(3, [
            'eeeeeeeeeeee' => new ExportValue(4, '4', []),
            'cccccccccccc' => new ExportValue([9, 2], 'x; y', [9, 2]),
            'aaaaaaaaaaaa' => new ExportValue('Lamp', 'Lamp', []),
        ]);
        [$json, $lines] = $this->exportRecords($fields, [$record]);
        self::assertSame(
            '{"id":3,"created_at":"2026-11-12T03:16:00Z","created_by":"a2kq7vn3xbmz","updated_at":"2026-11-18T14:32:00Z","updated_by":null,'
            . '"values":{"aaaaaaaaaaaa":"Lamp","bbbbbbbbbbbb":false,"cccccccccccc":[9,2],"dddddddddddd":[],"eeeeeeeeeeee":4,"ffffffffffff":null}}',
            $lines[0],
        );
        self::assertStringContainsString('"options":{}', $json, 'Empty options are an empty object.');
    }

    public function testOptionFloatsArePlainDecimals(): void
    {
        $fields = [self::field('aaaaaaaaaaaa', 'number', ['decimals' => 6, 'min' => 0.000001, 'max' => 12.5, 'note' => ['a/b', true, null, -0.0]])];
        [$json] = $this->exportRecords($fields, []);
        self::assertStringContainsString('"options":{"decimals":6,"min":0.000001,"max":12.5,"note":["a/b",true,null,0]}', $json);
    }

    public function testAnUnknownFieldTypeIsUnsupportedAndTheFileIsRemoved(): void
    {
        $fields = [self::field('aaaaaaaaaaaa', 'hologram')];
        $source = new FakeExportSource([self::table($fields)], static fn (string $key): array => [self::record(1, [])]);
        $path = $this->temp->path('unknown.json');
        try {
            $this->exporter()->write($source, $this->exporter()->settings('project'), $path);
            self::fail('An unknown type was written.');
        } catch (PluginFailure $failure) {
            self::assertSame(PluginFailure::REASON_UNSUPPORTED, $failure->reason);
        }
        self::assertFileDoesNotExist($path);
    }

    public function testAFailingSourceRemovesThePartFileAndPassesTheErrorOn(): void
    {
        $fields = [self::field('aaaaaaaaaaaa', 'text')];
        $source = new FakeExportSource([self::table($fields)], static function (string $key): \Generator {
            yield self::record(1, []);

            throw new \RuntimeException('The source failed.');
        });
        $path = $this->temp->path('part.json');
        try {
            $this->exporter()->write($source, $this->exporter()->settings('project'), $path);
            self::fail('No error.');
        } catch (\RuntimeException $error) {
            self::assertSame('The source failed.', $error->getMessage());
        }
        self::assertFileDoesNotExist($path);
    }

    public function testAnUnwritableTargetIsAPluginFailure(): void
    {
        $source = new FakeExportSource([], static fn (string $key): array => []);
        $this->expectException(PluginFailure::class);
        $this->exporter()->write($source, $this->exporter()->settings('project'), $this->temp->path('missing/folder/export.json'));
    }

    public function testCollectionsAreTheLastMemberOnePerLine(): void
    {
        $collections = [
            new ExportCollection('w4kz7mq2nx3b', 'h3kq7vn2xbma', 'Under "warranty" / ok', 'all', [
                new ExportRule('q2mz7k4wnx3b', 'is', ['1']),
                new ExportRule('t5kq2zm7wn4x', 'after', ['2023-01-01']),
            ], 't5kq2zm7wn4x.desc', '2026-11-20T10:00:00Z', '2026-11-20T10:05:00Z'),
            new ExportCollection('b3kz7mq2nx3c', 'h3kq7vn2xbma', 'Empty ones', 'any', [new ExportRule('z7zz7zz7zz7z', 'empty', [])], null, '2026-11-20T10:00:00Z', '2026-11-20T10:00:00Z'),
        ];
        $json = $this->export(new FakeExportSource([], static fn (string $key): array => [], [], [], $collections));
        self::assertStringEndsWith(
            "\"tables\":[],\n\"collections\":[\n"
            . '{"key":"w4kz7mq2nx3b","table":"h3kq7vn2xbma","name":"Under \\"warranty\\" / ok","match":"all",'
            . '"rules":[{"field":"q2mz7k4wnx3b","op":"is","values":["1"]},{"field":"t5kq2zm7wn4x","op":"after","values":["2023-01-01"]}],'
            . '"sort":"t5kq2zm7wn4x.desc","created_at":"2026-11-20T10:00:00Z","updated_at":"2026-11-20T10:05:00Z"},' . "\n"
            . '{"key":"b3kz7mq2nx3c","table":"h3kq7vn2xbma","name":"Empty ones","match":"any","rules":[{"field":"z7zz7zz7zz7z","op":"empty","values":[]}],'
            . '"sort":null,"created_at":"2026-11-20T10:00:00Z","updated_at":"2026-11-20T10:00:00Z"}' . "\n]}\n",
            $json,
        );
        $doc = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('collections', array_key_last($doc));
    }

    public function testNoSettingsAndAnEmptyProject(): void
    {
        $exporter = $this->exporter();
        self::assertSame([], $exporter->settings('project')->items);
        self::assertSame([], $exporter->settings('table')->items);
        $json = $this->export(new FakeExportSource([], static fn (string $key): array => []));
        self::assertSame(
            "{\"format\":\"tiffindb-json\",\n\"format_version\":1,\n\"exported_at\":\"2026-11-19T10:15:00Z\",\n\"exported_with\":\"0.3.0-dev\",\n"
            . "\"project\":{\"key\":\"q7mzk2wbx4ae\",\"name\":\"Example Project\",\"created_at\":\"2026-11-12T03:15:00Z\",\"updated_at\":\"2026-11-19T09:58:12Z\",\"file_format_version\":3},\n"
            . "\"relations\":[],\n\"attachments\":[],\n\"tables\":[],\n\"collections\":[]}\n",
            $json,
        );
    }
}
