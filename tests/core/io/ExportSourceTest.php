<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Io\ExportSourceBuilder;
use TiffinDB\Core\Io\ProjectExportSource;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-14: the export source of an open project. */
#[CoversClass(ExportSourceBuilder::class)]
#[CoversClass(ProjectExportSource::class)]
final class ExportSourceTest extends TestCase
{
    use ImportFixture;

    private const TYPES = ['text', 'long_text', 'number', 'currency', 'rating', 'duration', 'date', 'time', 'date_time', 'checkbox', 'choice', 'url', 'email', 'phone', 'auto_number', 'attachment'];

    private TableDef $items;
    private TableDef $rooms;
    private TableDef $bins;
    /** @var array<string, FieldDef> */
    private array $f = [];

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-export-source-test-1');
        $editor = $this->editor();
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->bins = $editor->createTable('Bins', 'bin', false);
        $this->items = $editor->createTable('Items', 'item', true);
        $this->f['name'] = $this->schema()->fields($this->items->key)[0];
        $options = ['number' => ['decimals' => 2], 'choice' => ['choices' => ['Red', 'Green']], 'currency' => ['code' => 'EUR', 'minor_digits' => 2]];
        foreach (self::TYPES as $type) {
            $this->f[$type] = $editor->addField($this->items->key, 'F ' . $type, $type, $options[$type] ?? [], $type === 'text', 'Help ' . $type);
        }
        $this->f['room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $this->f['bins'] = $editor->addRelation($this->items->key, 'Bins', $this->bins->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    private function records(): Records
    {
        return new Records($this->db, $this->schema(), $this->types, $this->clock);
    }

    public function testScopeTablesFieldsAndProject(): void
    {
        $this->clock->advance(60);
        $source = ExportSourceBuilder::build($this->db, $this->schema(), $this->types, null, $this->clock, '0.3.0-dev');
        self::assertSame(['Rooms', 'Bins', 'Items'], array_map(static fn ($t): string => $t->name, $source->tables()));
        $project = $source->project();
        self::assertSame([self::PROJECT_KEY, 'Example Project', self::START, 3, '2026-11-12T03:16:00Z', '0.3.0-dev'], [$project->key, $project->name, $project->createdAt, $project->fileFormatVersion, $project->exportedAt, $project->exportedWith]);
        self::assertSame(2, count($source->relations()));

        $one = ExportSourceBuilder::build($this->db, $this->schema(), $this->types, $this->items->key, $this->clock, '0.3.0-dev');
        self::assertSame(['Items'], array_map(static fn ($t): string => $t->name, $one->tables()));
        self::assertSame([], $one->relations(), 'Relations need both tables in scope.');
        $table = $one->tables()[0];
        self::assertSame(['item', $this->f['name']->key, 0], [$table->itemName, $table->titleField, $table->recordCount]);
        $fields = array_map(static fn (ExportField $f): array => [$f->name, $f->type, $f->required, $f->linkedTable], $table->fields);
        self::assertSame(['Name', 'text', false, null], $fields[0]);
        self::assertSame(['F text', 'text', true, null], $fields[1]);
        self::assertSame(['Room', 'relation', false, $this->rooms->key], $fields[count($fields) - 2]);
        self::assertSame(['code' => 'EUR', 'minor_digits' => 2], $table->fields[4]->options);
        self::assertSame('Help text', $table->fields[1]->help);
        try {
            iterator_to_array($one->records($this->rooms->key));
            self::fail('A table outside the scope was read.');
        } catch (NotFound) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(NotFound::class);
        ExportSourceBuilder::build($this->db, $this->schema(), $this->types, 'zzzzzzzzzzzz', $this->clock, '0.3.0-dev');
    }

    public function testValuesAndTextsPerType(): void
    {
        $records = $this->records();
        $roomName = $this->schema()->fields($this->rooms->key)[0];
        $kitchen = $records->create($this->rooms->key, [$roomName->key => 'Kitchen'], InputMode::Form, self::USER_KEY);
        $untitled = $records->create($this->rooms->key, [], InputMode::Form, self::USER_KEY);
        $bin1 = $records->create($this->bins->key, [], InputMode::Form, self::USER_KEY);
        $bin2 = $records->create($this->bins->key, [], InputMode::Form, self::USER_KEY);
        $trashedBin = $records->create($this->bins->key, [], InputMode::Form, self::USER_KEY);
        $id = $records->create($this->items->key, [
            $this->f['name']->key => 'Lamp', $this->f['text']->key => 'Desk lamp', $this->f['long_text']->key => "Line one\nline two",
            $this->f['number']->key => '1.5', $this->f['currency']->key => '12.30', $this->f['rating']->key => '4', $this->f['duration']->key => '1:30',
            $this->f['date']->key => '2025-02-03', $this->f['time']->key => '09:05', $this->f['date_time']->key => '2025-02-03T04:05',
            $this->f['checkbox']->key => 'yes', $this->f['choice']->key => 'Green', $this->f['url']->key => 'https://example.com/a',
            $this->f['email']->key => 'user@example.com', $this->f['phone']->key => '555-0100',
        ], InputMode::Form, self::USER_KEY);
        $records->setLinks($this->f['room']->key, $id, [$kitchen], self::USER_KEY);
        $records->setLinks($this->f['bins']->key, $id, [$bin2, $trashedBin, $bin1], self::USER_KEY);
        $this->db->run('UPDATE ' . $this->bins->sql() . ' SET "_trashed_at" = ? WHERE "id" = ?', [self::START, $trashedBin]);
        $this->db->write(function (Database $db) use ($id): void {
            foreach ([['a.txt', 2], ['b.png', 1]] as $i => [$name, $position]) {
                $db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)', [str_repeat((string) $i, 64), 10 + $i, 'text/plain', $name, self::START]);
                $db->run('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)', [$this->f['attachment']->key, $id, $db->lastInsertId(), $position]);
            }
        });
        $empty = $records->create($this->items->key, [$this->f['text']->key => 'x'], InputMode::Form, self::USER_KEY);
        $records->setLinks($this->f['room']->key, $empty, [$untitled], self::USER_KEY);

        $source = ExportSourceBuilder::build($this->db, $this->schema(), $this->types, null, $this->clock, '0.3.0-dev');
        $items = iterator_to_array($source->records($this->items->key), false);
        self::assertCount(2, $items);
        $values = array_map(static fn (ExportValue $v): array => [$v->value, $v->text, $v->ids], $items[0]->values);
        $expect = [
            'name' => ['Lamp', 'Lamp', []], 'text' => ['Desk lamp', 'Desk lamp', []], 'long_text' => ["Line one\nline two", "Line one\nline two", []],
            'number' => [1.5, '1.50', []], 'currency' => [1230, '12.30', []], 'rating' => [4, '4', []], 'duration' => [5400, '1:30', []],
            'date' => ['2025-02-03', '2025-02-03', []], 'time' => ['09:05:00', '09:05', []], 'date_time' => ['2025-02-03T04:05:00', '2025-02-03T04:05', []],
            'checkbox' => [true, 'yes', []], 'choice' => ['Green', 'Green', []], 'url' => ['https://example.com/a', 'https://example.com/a', []],
            'email' => ['user@example.com', 'user@example.com', []], 'phone' => ['555-0100', '555-0100', []], 'auto_number' => [1, '1', []],
            'attachment' => [[2, 1], 'b.png; a.txt', [2, 1]],
            'room' => [[$kitchen], 'Kitchen', [$kitchen]],
            'bins' => [[$bin2, $bin1], 'bin ' . $bin2 . '; bin ' . $bin1, [$bin2, $bin1]],
        ];
        foreach ($expect as $short => $value) {
            $type = $this->types->get($this->f[$short]->type);
            if (!in_array($short, ['attachment', 'room', 'bins'], true)) {
                $value[1] = $type->toText($value[0], $this->f[$short]->options);
            }
            self::assertSame($value, $values[$this->f[$short]->key], $short);
        }
        self::assertSame(self::USER_KEY, $items[0]->createdBy);
        $second = array_map(static fn (ExportValue $v): array => [$v->value, $v->text, $v->ids], $items[1]->values);
        self::assertSame([null, '', []], $second[$this->f['name']->key]);
        self::assertSame([false, $this->types->get('checkbox')->toText(false, []), []], $second[$this->f['checkbox']->key]);
        self::assertSame([[$untitled], 'room ' . $untitled, [$untitled]], $second[$this->f['room']->key], 'An untitled record is "{item} {id}".');
        self::assertSame([null, '', []], $second[$this->f['attachment']->key]);

        $attachments = iterator_to_array($source->attachments(), false);
        self::assertSame([[1, 'a.txt', str_repeat('0', 64), 10], [2, 'b.png', str_repeat('1', 64), 11]], array_map(static fn ($a): array => [$a->id, $a->originalName, $a->sha256, $a->size], $attachments));
        // Attachments of trashed records are not exported.
        $this->db->run('UPDATE ' . $this->items->sql() . ' SET "_trashed_at" = ? WHERE "id" = ?', [self::START, $id]);
        self::assertSame([], iterator_to_array($source->attachments(), false));
        self::assertCount(1, iterator_to_array($source->records($this->items->key), false));
    }

    public function testPagingOver1234RecordsInIdOrderLiveOnly(): void
    {
        $roomName = $this->schema()->fields($this->rooms->key)[0];
        $relation = (string) $this->f['room']->options['relation'];
        $this->db->write(function (Database $db) use ($roomName, $relation): void {
            for ($i = 1; $i <= 1234; $i++) {
                $this->insert($this->items->key, [$this->f['name']->key => 'Item ' . $i, $this->f['auto_number']->key => $i], $i % 100 === 0);
            }
            $this->insert($this->rooms->key, [$roomName->key => 'Kitchen']);
            $this->insert($this->rooms->key, [$roomName->key => 'Shed'], true);
            for ($i = 1; $i <= 1234; $i += 7) {
                $this->link($relation, $i, $i % 2 === 0 ? 1 : 2);
            }
        });
        $source = ExportSourceBuilder::build($this->db, $this->schema(), $this->types, $this->items->key, $this->clock, '0.3.0-dev');
        self::assertSame(1234 - 12, $source->tables()[0]->recordCount);
        $ids = [];
        $linked = 0;
        foreach ($source->records($this->items->key) as $record) {
            self::assertInstanceOf(ExportRecord::class, $record);
            $ids[] = $record->id;
            self::assertSame('Item ' . $record->id, $record->values[$this->f['name']->key]->value);
            $room = $record->values[$this->f['room']->key];
            if ($room->ids !== []) {
                $linked++;
                self::assertSame([1], $room->ids, 'Links to the trashed Shed are left out.');
                self::assertSame('Kitchen', $room->text);
            }
        }
        $expected = array_values(array_filter(range(1, 1234), static fn (int $i): bool => $i % 100 !== 0));
        self::assertSame($expected, $ids);
        self::assertGreaterThan(50, $linked);
        self::assertSame(500, ProjectExportSource::PAGE);
    }
}
