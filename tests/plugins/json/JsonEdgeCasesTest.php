<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Json;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\Identifier;

require_once __DIR__ . '/JsonFixture.php';

/**
 * Cases the seeded inventory fixture does not hold (M3 exit audit): table
 * and field names outside ASCII, an empty table between tables with
 * records, stored control characters other than the line break, and number
 * values stored before the field's `decimals` was lowered. Each is checked
 * against docs/formats/json-export.md. Synthetic data only.
 */
#[CoversNothing]
final class JsonEdgeCasesTest extends TestCase
{
    use JsonFixture;

    private string $json;
    /** @var array<string, mixed> */
    private array $doc;
    private string $nameKey;
    private string $numberKey;
    private string $noteKey;

    private const NAME_CONTROLS = "Tab\there \x01 end";
    private const NOTE_CONTROLS = "bell\x07 esc\x1B del\x7F cr\r\nline \u{2028} sep";

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-json-edge-1');
        $editor = $this->editor();
        $sizes = $editor->createTable('Größen 表', 'größe', true);
        $empty = $editor->createTable('Leer ∅', 'leer', true);
        $third = $editor->createTable('Dritte', 'dritte', true);
        $this->nameKey = $this->schema()->fields($sizes->key)[0]->key;
        $this->numberKey = $editor->addField($sizes->key, 'Maß µ', 'number', ['decimals' => 4], false, 'Höhe in m')->key;
        $this->noteKey = $editor->addField($sizes->key, 'Notiz', 'long_text', [], false, '')->key;
        $this->insert($sizes->key, [$this->nameKey => self::NAME_CONTROLS, $this->numberKey => 27.3186, $this->noteKey => self::NOTE_CONTROLS]);
        $this->insert($sizes->key, [$this->nameKey => 'Zweite', $this->numberKey => -0.0001]);
        $this->insert($sizes->key, [$this->nameKey => 'Dritte', $this->numberKey => 12.5]);
        $this->insert($third->key, [$this->schema()->fields($third->key)[0]->key => 'Eins']);
        // Lower decimals after the values were stored; the values themselves are kept.
        $this->editor()->updateField($this->numberKey, 'Maß µ', ['decimals' => 1], false, 'Höhe in m', 99);
        self::assertSame(['Größen 表', 'Leer ∅', 'Dritte'], array_map(static fn ($t): string => $t->name, $this->schema()->tables()));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM ' . Identifier::table($empty->key)));

        $this->json = $this->export($this->source());
        $this->doc = self::decode($this->json);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    public function testNamesOutsideAsciiAreWrittenAsUtf8(): void
    {
        $tables = $this->doc['tables'];
        self::assertSame(['Größen 表', 'Leer ∅', 'Dritte'], array_column($tables, 'name'));
        self::assertSame(['größe', 'leer', 'dritte'], array_column($tables, 'item_name'));
        self::assertSame(['Name', 'Maß µ', 'Notiz'], array_column($tables[0]['fields'], 'name'));
        self::assertSame('Höhe in m', $tables[0]['fields'][1]['help']);
        self::assertStringContainsString('"name":"Größen 表"', $this->json, 'Not escaped as \\u sequences.');
        self::assertStringContainsString('"name":"Maß µ"', $this->json);
        self::assertSame(1, preg_match('//u', $this->json));
    }

    public function testAnEmptyTableHasAnEmptyRecordsArrayBetweenTablesWithRecords(): void
    {
        $tables = $this->doc['tables'];
        self::assertCount(3, $tables[0]['records']);
        self::assertSame([], $tables[1]['records']);
        self::assertCount(1, $tables[2]['records']);
        self::assertStringContainsString('"records":[]},' . "\n" . '{"key":"' . $tables[2]['key'] . '"', $this->json);
    }

    public function testControlCharactersAreEscapedAndReadBackUnchanged(): void
    {
        $values = $this->doc['tables'][0]['records'][0]['values'];
        self::assertSame(self::NAME_CONTROLS, $values[$this->nameKey]);
        self::assertSame(self::NOTE_CONTROLS, $values[$this->noteKey]);
        self::assertStringContainsString('"Tab\\there \\u0001 end"', $this->json);
        self::assertStringContainsString('"bell\\u0007 esc\\u001b del' . "\x7F" . ' cr\\r\\nline \\u2028 sep"', $this->json, 'DEL is allowed raw by RFC 8259.');
        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F]/', $this->json, 'No raw control characters.');
        self::assertDoesNotMatchRegularExpression('/[^}\]{\[,]\n/', $this->json, 'Raw line breaks only between members and records.');
    }

    public function testNumbersStoredBeforeDecimalsWasLoweredKeepEveryDigit(): void
    {
        $fields = $this->doc['tables'][0]['fields'];
        self::assertSame(1, $fields[1]['options']['decimals']);
        $records = $this->doc['tables'][0]['records'];
        $key = $this->numberKey;
        self::assertSame([27.3186, -0.0001, 12.5], array_map(static fn (array $r): float => $r['values'][$key], $records));
        self::assertStringContainsString('"' . $key . '":27.3186,', $this->json);
        self::assertStringContainsString('"' . $key . '":-0.0001,', $this->json, 'Plain decimal, no exponent.');
        self::assertStringContainsString('"' . $key . '":12.5,', $this->json);
        self::assertDoesNotMatchRegularExpression('/"' . $key . '":-?[0-9.]+[eE]/', $this->json);
    }
}
