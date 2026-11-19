<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\ColumnPlan;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Io\Preparer;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-7: preparing rows for the commit, and the review preview. */
#[CoversClass(Preparer::class)]
#[CoversClass(ColumnPlan::class)]
final class PreparerTest extends TestCase
{
    use ImportFixture;

    private const TYPES = ['text', 'long_text', 'number', 'currency', 'rating', 'duration', 'date', 'time', 'date_time', 'checkbox', 'choice', 'url', 'email', 'phone'];

    private TableDef $items;
    private TableDef $rooms;
    /** @var array<string, FieldDef> */
    private array $f = [];

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-preparer-test-1');
        $editor = $this->editor();
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->items = $editor->createTable('Items', 'item', false);
        $options = ['number' => ['decimals' => 2], 'choice' => ['choices' => ['Red', 'Green']], 'text' => ['max_length' => 10]];
        foreach (self::TYPES as $type) {
            $this->f[$type] = $editor->addField($this->items->key, 'F ' . $type, $type, $options[$type] ?? [], false, '');
        }
        $this->f['room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $this->f['rooms'] = $editor->addRelation($this->items->key, 'Rooms', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Stored items', false, '');
        $records = new Records($this->db, $this->schema(), $this->types, $this->clock);
        $records->create($this->rooms->key, [$this->schema()->fields($this->rooms->key)[0]->key => 'Kitchen'], InputMode::Form, self::USER_KEY);
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [...self::TYPES, 'room', 'rooms'];
    }

    private function existingMapping(): Mapping
    {
        $columns = [];
        foreach ($this->columns() as $i => $short) {
            $columns[] = new ColumnMapping($i, true, null, null, $this->f[$short]->key);
        }

        return new Mapping(Mapping::EXISTING, null, $this->items->key, $columns);
    }

    /** @param list<array<string, ?string>> $rows short name => cell */
    private function importer(array $rows): FakeImporter
    {
        $cells = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($this->columns() as $short) {
                $line[] = $row[$short] ?? null;
            }
            $cells[] = $line;
        }

        return new FakeImporter($this->columns(), $cells);
    }

    /** @return array<int, array<string, mixed>|null> n => record, by field short name */
    private function prepared(ImportJob $job): array
    {
        $byKey = array_flip(array_map(static fn (FieldDef $f): string => $f->key, $this->f));
        $result = [];
        foreach ($job->db()->all('SELECT n, record, skip FROM prepared ORDER BY n') as $row) {
            if ($row['record'] === null) {
                $result[(int) $row['n']] = ['skip' => $row['skip']];

                continue;
            }
            $named = [];
            foreach (json_decode((string) $row['record'], true) as $key => $value) {
                $named[$byKey[$key]] = $value;
            }
            $result[(int) $row['n']] = $named;
        }

        return $result;
    }

    public function testEveryTypeInImportModeIntoAnExistingTable(): void
    {
        $importer = $this->importer([
            [
                'text' => ' Lamp ', 'long_text' => "Line one\nline two", 'number' => '1,234.567', 'currency' => '$1,234.50', 'rating' => '4',
                'duration' => '1:30', 'date' => '2025-02-03', 'time' => '9:05', 'date_time' => '2025-02-03 04:05', 'checkbox' => 'Yes',
                'choice' => 'red', 'url' => 'https://example.com/a', 'email' => 'user@example.com', 'phone' => '555-0100',
                'room' => 'Kitchen; Hall', 'rooms' => 'Kitchen; Hall ;; ',
            ],
            [
                'text' => 'Much too long for ten', 'number' => 'many', 'currency' => 'free', 'rating' => '9', 'duration' => 'soon',
                'date' => '3/14/2025', 'time' => '25:00', 'date_time' => 'later', 'checkbox' => 'maybe', 'choice' => 'Purple',
                'url' => 'not a url', 'email' => 'not an address', 'phone' => "\x07", 'rooms' => 'Hall',
            ],
            ['text' => '   ', 'checkbox' => ''],
            ['number' => 'many'],
            ['checkbox' => 'no'],
        ]);
        $job = $this->newJob();
        $this->readAll($job, $importer);
        self::assertSame(4, $job->rowCount(), 'Row 3 is empty when read.');
        $this->accept($job, $this->existingMapping());
        $this->prepareAll($job);
        self::assertSame(JobState::Ready, $job->state());
        self::assertSame(4, $job->metaInt('prepared_rows'));

        $prepared = $this->prepared($job);
        self::assertSame([
            'text' => 'Lamp', 'long_text' => "Line one\nline two", 'number' => 1234.57, 'currency' => 123450, 'rating' => 4, 'duration' => 5400,
            'date' => '2025-02-03', 'time' => '09:05:00', 'date_time' => '2025-02-03T04:05:00', 'checkbox' => 1, 'choice' => 'Red',
            'url' => 'https://example.com/a', 'email' => 'user@example.com', 'phone' => '555-0100',
            'room' => ['titles' => ['Kitchen; Hall']], 'rooms' => ['titles' => ['Kitchen', 'Hall']],
        ], $prepared[1]);
        $emptied = $prepared[2];
        foreach (self::TYPES as $type) {
            self::assertSame($type === 'checkbox' ? 0 : null, $emptied[$type], $type);
        }
        self::assertSame(['skip' => 'empty'], $prepared[4], 'A row whose only value is unreadable is empty after conversion.');
        self::assertSame(0, $prepared[5]['checkbox']);
        self::assertSame(null, $prepared[5]['text']);

        $issues = $job->db()->all('SELECT n, col, kind FROM issues ORDER BY n, col');
        $expected = [];
        foreach (self::TYPES as $i => $type) {
            if ($type !== 'long_text') {
                $expected[] = ['n' => 2, 'col' => $i, 'kind' => $type === 'choice' ? 'not-a-choice' : 'unreadable'];
            }
        }
        self::assertSame($expected, $issues, 'Issues of skipped rows are not recorded.');
    }

    public function testNewTableColumnsAndLinkSplitting(): void
    {
        $importer = new FakeImporter(['Name', 'Price', 'Rooms', 'Seen'], [
            ['Lamp', '$5.00', 'Kitchen;Hall', '2025-01-02'],
            ['Kettle', '$1,250', 'kitchen', ''],
            ['', '', ' ; ', ''],
        ]);
        $job = $this->newJob();
        $this->readAll($job, $importer);
        $mapping = $this->defaultMapping($job, $importer);
        self::assertSame(['text', 'currency', 'link:' . $this->rooms->key, 'date'], array_map(static fn (ColumnMapping $c): ?string => $c->type, $mapping->columns));
        $this->accept($job, $mapping);
        $this->prepareAll($job);
        $rows = $job->db()->all('SELECT n, record, skip FROM prepared ORDER BY n');
        self::assertSame(['col-0' => 'Lamp', 'col-1' => 500, 'col-2' => ['titles' => ['Kitchen', 'Hall']], 'col-3' => '2025-01-02'], json_decode((string) $rows[0]['record'], true));
        self::assertSame(['col-0' => 'Kettle', 'col-1' => 125000, 'col-2' => ['titles' => ['kitchen']], 'col-3' => null], json_decode((string) $rows[1]['record'], true));
        self::assertSame([null, 'empty'], [$rows[2]['record'], $rows[2]['skip']]);
    }

    public function testChunksResumeAfterTheLastPreparedRow(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['text' => 'Item ' . $i];
        }
        $job = $this->newJob();
        $this->readAll($job, $this->importer($rows));
        $this->accept($job, $this->existingMapping());
        $chunks = $this->prepareAll($job, new Preparer($this->types, null, 4));
        self::assertSame(7, $chunks);
        self::assertSame(range(1, 25), array_map(static fn (array $r): int => (int) $r['n'], $job->db()->all('SELECT n FROM prepared ORDER BY n')));

        // The time limit (seam) ends a chunk early without losing rows.
        $job2 = $this->newJob();
        $this->readAll($job2, $this->importer($rows));
        $this->accept($job2, $this->existingMapping());
        $ticks = 0;
        $chunks = $this->prepareAll($job2, new Preparer($this->types, static function () use (&$ticks): int {
            return ($ticks++) * 2000000000;
        }));
        self::assertGreaterThan(1, $chunks);
        self::assertSame(25, (int) $job2->db()->value('SELECT COUNT(*) FROM prepared'));
        self::assertSame(25, $job2->metaInt('prepared_rows'));
    }

    public function testThePreviewEqualsTheChunkCounts(): void
    {
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = [
                'text' => $i % 5 === 0 ? 'Much too long here' : 'Item ' . $i,
                'number' => $i % 3 === 0 ? 'many' : (string) $i,
                'choice' => $i % 4 === 0 ? 'Purple' : 'Green',
                'rooms' => $i % 2 === 0 ? 'Kitchen; Room ' . ($i % 6) : 'room ' . ($i % 6) . ';' . str_repeat('w', 501),
            ];
        }
        $job = $this->newJob();
        $this->readAll($job, $this->importer($rows));
        $mapping = $this->existingMapping();
        $this->accept($job, $mapping);
        $preview = (new Preparer($this->types))->preview($job, $mapping, $this->schema(), $this->db, 30);
        self::assertSame(30, $preview->rows);
        self::assertTrue($preview->partial);
        self::assertSame(30, $preview->importCount);

        $this->prepareAll($job);
        $counts = [];
        foreach ($job->db()->all('SELECT col, COUNT(*) AS c FROM issues WHERE n <= 30 GROUP BY col ORDER BY col') as $row) {
            $counts[(int) $row['col']] = (int) $row['c'];
        }
        self::assertSame($counts, $preview->emptied);
        self::assertSame([0 => 6, 2 => 10, 10 => 7], $preview->emptied);
        // Distinct titles not found in Rooms (ASCII case-insensitive): Room 0 to Room 5; the 501-character title is refused.
        $col = count(self::TYPES) + 1;
        self::assertSame([$col => 6], $preview->linksAdded);
        self::assertSame([$col => 1], $preview->linksRefused);

        $whole = (new Preparer($this->types))->preview($job, $mapping, $this->schema(), $this->db);
        self::assertFalse($whole->partial);
        self::assertSame(40, $whole->rows);
    }

    public function testAWebAddressIsNeverMadeFromPlainText(): void
    {
        // M3-WP11 X-13: a file name is not turned into https://<file name>; an explicit scheme or www. is kept.
        $cells = ['stand-mixer.jpg', 'example.com/x', 'javascript:alert(1)', 'ftp://example.com/f', 'https://example.com/a', 'www.example.com', 'HTTP://EXAMPLE.ORG'];
        $rows = [];
        foreach ($cells as $cell) {
            $rows[] = ['text' => 'Row', 'url' => $cell];
        }
        $job = $this->newJob();
        $this->readAll($job, $this->importer($rows));
        $this->accept($job, $this->existingMapping());
        $this->prepareAll($job);
        $prepared = $this->prepared($job);
        self::assertSame([null, null, null, null, 'https://example.com/a', 'https://www.example.com', 'http://EXAMPLE.ORG'], array_map(static fn (array $r): mixed => $r['url'], array_values($prepared)));
        $col = array_search('url', self::TYPES, true);
        self::assertSame([1, 2, 3, 4], array_map(static fn (array $r): int => (int) $r['n'], $job->db()->all('SELECT n FROM issues WHERE col = ? AND kind = \'unreadable\' ORDER BY n', [$col])));
    }
}
