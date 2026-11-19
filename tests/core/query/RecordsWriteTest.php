<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\Condition;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\LinkedRecord;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\RecordVersion;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;

require_once __DIR__ . '/RecordsFixture.php';

/** T-REC-1 to T-REC-7: creating, updating, version tokens, and links. */
#[CoversClass(Records::class)]
#[CoversClass(RecordVersion::class)]
#[CoversClass(Conflict::class)]
final class RecordsWriteTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-records-write-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    // ------------------------------------------------------------ T-REC-1

    public function testCreateStoresCanonicalValuesOfEveryEditableType(): void
    {
        $this->clock->advance(60);
        $id = $this->item([
            'name' => 'Example lamp',
            'text' => '  Shelf  ',
            'long_text' => "Line 1\nLine 2",
            'url' => 'https://example.com/lamp',
            'email' => 'user@example.com',
            'phone' => '555-0100',
            'number' => '3.5',
            'currency' => '12.34',
            'rating' => '4',
            'duration' => '1:30',
            'date' => '2026-11-12',
            'time' => '09:30',
            'date_time' => '2026-11-12 09:30',
            'checkbox' => '1',
            'choice' => 'Green',
        ]);
        self::assertSame(1, $id);
        $row = $this->rowOf($this->items, $id);
        $stored = static fn (string $key): mixed => $row[Identifier::columnName($key)];
        self::assertSame('Example lamp', $stored($this->f['name']->key));
        self::assertSame('Shelf', $stored($this->f['text']->key));
        self::assertSame("Line 1\nLine 2", $stored($this->f['long_text']->key));
        self::assertSame('https://example.com/lamp', $stored($this->f['url']->key));
        self::assertSame('user@example.com', $stored($this->f['email']->key));
        self::assertSame('555-0100', $stored($this->f['phone']->key));
        self::assertSame(3.5, $stored($this->f['number']->key));
        self::assertSame(1234, $stored($this->f['currency']->key));
        self::assertSame(4, $stored($this->f['rating']->key));
        self::assertSame(5400, $stored($this->f['duration']->key));
        self::assertSame('2026-11-12', $stored($this->f['date']->key));
        self::assertSame('09:30:00', $stored($this->f['time']->key));
        self::assertSame('2026-11-12T09:30:00', $stored($this->f['date_time']->key));
        self::assertSame(1, $stored($this->f['checkbox']->key));
        self::assertSame('Green', $stored($this->f['choice']->key));
        self::assertSame(1, $stored($this->f['auto']->key));
        self::assertSame('2026-11-12T03:16:00Z', $row['_created_at']);
        self::assertSame(self::USER_KEY, $row['_created_by']);
        self::assertSame('2026-11-12T03:16:00Z', $row['_updated_at']);
        self::assertSame(self::USER_KEY, $row['_updated_by']);
        self::assertNull($row['_trashed_at']);
        self::assertSame('2026-11-12T03:16:00Z', $this->meta('updated_at'));

        // Fields not given: null, checkbox 0.
        $this->clock->advance(60);
        $empty = $this->records()->create($this->items->key, [], InputMode::Form, self::OTHER_USER_KEY);
        $row = $this->rowOf($this->items, $empty);
        foreach (['name', 'text', 'number', 'date', 'choice'] as $short) {
            self::assertNull($row[Identifier::columnName($this->f[$short]->key)], $short);
        }
        self::assertSame(0, $row[Identifier::columnName($this->f['checkbox']->key)]);
        self::assertSame(2, $row[Identifier::columnName($this->f['auto']->key)]);
        self::assertSame(self::OTHER_USER_KEY, $this->meta('updated_by'));

        // Reading back gives the canonical values.
        $record = $this->records()->get($this->items->key, $id);
        self::assertNotNull($record);
        self::assertSame(3.5, $record->values[$this->f['number']->key]);
        self::assertTrue($record->values[$this->f['checkbox']->key]);
        self::assertSame([], $record->links[$this->f['room']->key]);
        self::assertSame([], $record->attachments[$this->f['files']->key]);
        self::assertArrayNotHasKey($this->f['room']->key, $record->values);
    }

    // ------------------------------------------------------------ T-REC-2

    public function testValidationCollectsEveryProblemAndWritesNothing(): void
    {
        $editor = $this->editor();
        $required = $editor->addField($this->items->key, 'Serial', 'text', [], true, '');
        $requiredDate = $editor->addField($this->items->key, 'Bought', 'date', [], true, '');
        $before = $this->dump();

        $problems = self::problems(fn (): int => $this->records()->create($this->items->key, [
            'zzzzzzzzzzzz' => 'x',
            $this->f['room']->key => '1',
            $this->f['files']->key => 'a.txt',
            $this->f['number']->key => 'many',
            $this->f['email']->key => 'not an address',
            $this->f['choice']->key => 'Purple',
            $this->f['auto']->key => '7',
            $required->key => '   ',
        ], InputMode::Form, self::USER_KEY));
        self::assertSame([
            ['zzzzzzzzzzzz', 'problem.value.unknown_field', []],
            [$this->f['room']->key, 'problem.value.unsupported', []],
            [$this->f['files']->key, 'problem.value.unsupported', []],
            [$this->f['number']->key, 'problem.number.invalid', []],
            [$this->f['email']->key, 'problem.email.invalid', []],
            [$this->f['choice']->key, 'problem.choice.invalid', []],
            [$this->f['auto']->key, 'problem.auto_number.read_only', []],
            [$required->key, 'problem.value.required', []],
            [$requiredDate->key, 'problem.value.required', []],
        ], $problems);
        self::assertEquals($before, $this->dump());

        // On update only required fields that are given are checked.
        $id = $this->records()->create($this->items->key, [$required->key => 'A-1', $requiredDate->key => '2026-01-02'], InputMode::Form, self::USER_KEY);
        $record = $this->records()->get($this->items->key, $id);
        self::assertNotNull($record);
        $this->records()->update($this->items->key, $id, [$this->f['text']->key => 'ok'], InputMode::Form, $record->version, self::USER_KEY);
        $record = $this->records()->get($this->items->key, $id);
        self::assertNotNull($record);
        $before = $this->dump();
        $problems = self::problems(fn (): mixed => $this->records()->update(
            $this->items->key,
            $id,
            [$required->key => '', $this->f['rating']->key => '9'],
            InputMode::Form,
            $record->version,
            self::USER_KEY,
        ));
        self::assertSame([
            [$required->key, 'problem.value.required', []],
            [$this->f['rating']->key, 'problem.rating.invalid', ['max' => 5]],
        ], $problems);
        self::assertEquals($before, $this->dump());
    }

    // ------------------------------------------------------------ T-REC-3

    public function testImportIsLenientAndCanonicalUsesCheck(): void
    {
        $records = $this->records();
        $problems = self::problems(fn (): int => $records->create($this->items->key, [
            $this->f['number']->key => '3.555',
            $this->f['date_time']->key => '2026-11-12',
        ], InputMode::Form, self::USER_KEY));
        self::assertSame([$this->f['number']->key, $this->f['date_time']->key], array_column($problems, 0));

        // M3 spec section 6.6: Import mode refuses an auto-number value.
        self::assertSame([[$this->f['auto']->key, 'problem.value.unsupported', []]], self::problems(fn (): int => $records->create($this->items->key, [
            $this->f['auto']->key => '40',
        ], InputMode::Import, self::USER_KEY)));
        $id = $records->create($this->items->key, [
            $this->f['number']->key => '3.555',
            $this->f['date_time']->key => '2026-11-12',
        ], InputMode::Import, self::USER_KEY);
        $record = $records->get($this->items->key, $id);
        self::assertNotNull($record);
        self::assertSame('2026-11-12T00:00:00', $record->values[$this->f['date_time']->key]);
        self::assertIsFloat($record->values[$this->f['number']->key]);
        self::assertEqualsWithDelta(3.56, $record->values[$this->f['number']->key], 0.0001);
        self::assertSame(1, $record->values[$this->f['auto']->key]);
        $records->create($this->items->key, [$this->f['auto']->key => 40], InputMode::Canonical, self::USER_KEY);

        $canonical = $records->create($this->items->key, [
            $this->f['checkbox']->key => true,
            $this->f['rating']->key => 2,
            $this->f['date']->key => '2026-02-03',
            $this->f['name']->key => null,
        ], InputMode::Canonical, self::USER_KEY);
        $record = $records->get($this->items->key, $canonical);
        self::assertNotNull($record);
        self::assertTrue($record->values[$this->f['checkbox']->key]);
        self::assertSame(2, $record->values[$this->f['rating']->key]);
        self::assertSame(41, $record->values[$this->f['auto']->key], 'One more than the highest stored number.');

        $problems = self::problems(fn (): int => $records->create($this->items->key, [
            $this->f['checkbox']->key => 'yes',
            $this->f['rating']->key => '2',
        ], InputMode::Canonical, self::USER_KEY));
        self::assertSame([$this->f['checkbox']->key, $this->f['rating']->key], array_column($problems, 0));

        $this->expectException(\InvalidArgumentException::class);
        $records->create($this->items->key, [$this->f['rating']->key => 2], InputMode::Form, self::USER_KEY);
    }

    // ------------------------------------------------------------ T-REC-4

    public function testAutoNumbers(): void
    {
        self::assertSame(['start' => 1, 'next' => 1], $this->f['auto']->options);
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $id = $this->item();
            $numbers[] = $this->rowOf($this->items, $id)[Identifier::columnName($this->f['auto']->key)];
        }
        self::assertSame([1, 2, 3], $numbers);
        self::assertSame(4, json_decode((string) $this->fieldRow($this->f['auto']->key)['options'], true)['next']);
        $updatedAt = $this->fieldRow($this->f['auto']->key)['updated_at'];

        $this->insert($this->items->key, [$this->f['auto']->key => 10]);
        $id = $this->item();
        self::assertSame(11, $this->rowOf($this->items, $id)[Identifier::columnName($this->f['auto']->key)]);
        $row = $this->fieldRow($this->f['auto']->key);
        self::assertSame(['start' => 1, 'next' => 12], json_decode((string) $row['options'], true));
        self::assertSame($updatedAt, $row['updated_at']);

        // A field starting at 100 numbers from 100 even with no records.
        $other = $this->editor()->addField($this->rooms->key, 'Code', 'auto_number', ['start' => 100], false, '');
        $room = $this->room('Example hall');
        self::assertSame(100, $this->rowOf($this->rooms, $room)[Identifier::columnName($other->key)]);

        // Two connections: writes are serialised, so numbers are distinct or the second is Busy.
        $second = Sqlite::open($this->path(), 50);
        $this->extraConnections[] = $second;
        $otherRecords = new Records($second, $this->schema(), $this->types, $this->clock);
        $outcome = $this->db->write(function (Database $db) use ($otherRecords): string {
            try {
                $otherRecords->create($this->items->key, [], InputMode::Form, self::USER_KEY);

                return 'created';
            } catch (Busy) {
                return 'busy';
            }
        });
        self::assertSame('busy', $outcome);
        $a = $otherRecords->create($this->items->key, [], InputMode::Form, self::USER_KEY);
        $b = $this->item();
        self::assertSame(
            [12, 13],
            [$this->rowOf($this->items, $a)[Identifier::columnName($this->f['auto']->key)], $this->rowOf($this->items, $b)[Identifier::columnName($this->f['auto']->key)]],
        );
    }

    // ------------------------------------------------------------ T-REC-5

    public function testUpdateIsPartialGuardedAndIdempotent(): void
    {
        $records = $this->records();
        $id = $this->item(['name' => 'Kettle', 'text' => 'Kitchen', 'number' => '2']);
        $record = $records->get($this->items->key, $id);
        self::assertNotNull($record);

        $this->clock->advance(30);
        $after = $records->update($this->items->key, $id, [$this->f['text']->key => 'Pantry'], InputMode::Form, $record->version, self::OTHER_USER_KEY);
        self::assertSame('Pantry', $after->values[$this->f['text']->key]);
        self::assertSame('Kettle', $after->values[$this->f['name']->key]);
        self::assertSame(2.0, $after->values[$this->f['number']->key]);
        self::assertSame(1, $after->values[$this->f['auto']->key]);
        self::assertSame('2026-11-12T03:15:30Z', $after->updatedAt);
        self::assertSame(self::OTHER_USER_KEY, $after->updatedBy);
        self::assertSame(self::OTHER_USER_KEY, $this->meta('updated_by'));
        self::assertNotSame($record->version, $after->version);

        // Equal values (after toStorage) change nothing; an empty auto-number keeps its number.
        $this->clock->advance(30);
        $before = $this->dump();
        $same = $records->update(
            $this->items->key,
            $id,
            [$this->f['text']->key => ' Pantry ', $this->f['number']->key => '2.00', $this->f['auto']->key => ''],
            InputMode::Form,
            $after->version,
            self::USER_KEY,
        );
        self::assertSame($after->version, $same->version);
        self::assertSame('2026-11-12T03:15:30Z', $same->updatedAt);
        self::assertEquals($before, $this->dump());

        // A stale version throws Conflict with the current version and changes nothing.
        try {
            $records->update($this->items->key, $id, [$this->f['text']->key => 'Hall'], InputMode::Form, $record->version, self::USER_KEY);
            self::fail('Conflict expected.');
        } catch (Conflict $conflict) {
            self::assertSame($after->version, $conflict->currentVersion);
        }
        self::assertEquals($before, $this->dump());

        // Trashed or absent records are not found.
        $this->trashRecord($this->items, $id);
        $trashed = $records->get($this->items->key, $id);
        self::assertNotNull($trashed);
        self::assertSame(self::START, $trashed->trashedAt);
        foreach ([[$id, $trashed->version], [99, $trashed->version]] as [$target, $version]) {
            try {
                $records->update($this->items->key, $target, [$this->f['text']->key => 'Hall'], InputMode::Form, $version, self::USER_KEY);
                self::fail('NotFound expected.');
            } catch (NotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertNull($records->get($this->items->key, 99));
    }

    public function testKeysAndIdsAreGuarded(): void
    {
        $records = $this->records();
        $guards = [
            'unknown table' => fn (): mixed => $records->get('zzzzzzzzzzzz', 1),
            'invalid table key' => fn (): mixed => $records->count('"; DROP TABLE x; --'),
            'create in unknown table' => fn (): mixed => $records->create('zzzzzzzzzzzz', [], InputMode::Form, self::USER_KEY),
            'links on a non-relation field' => fn (): mixed => $records->setLinks($this->f['text']->key, 1, [], self::USER_KEY),
            'links on an unknown field' => fn (): mixed => $records->setLinks('zzzzzzzzzzzz', 1, [], self::USER_KEY),
            'sort by a field of another table' => fn (): mixed => $records->page($this->rooms->key, new ListQuery(new Sort($this->f['text']->key, false), null, null, 1)),
            'filter on a field of another table' => fn (): mixed => $records->page(
                $this->rooms->key,
                new ListQuery(new Sort('added', false), new Condition($this->f['checkbox']->key, Operator::Is, ['1']), null, 1),
            ),
            'titles of an unknown table' => fn (): mixed => $records->titles('zzzzzzzzzzzz', [1]),
        ];
        foreach ($guards as $name => $guard) {
            try {
                $guard();
                self::fail($name);
            } catch (NotFound) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([0, -1] as $bad) {
            try {
                $records->get($this->items->key, $bad);
                self::fail('Invalid id accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $records->create($this->items->key, [], InputMode::Form, 'not a key');
    }

    // ------------------------------------------------------------ T-REC-6

    public function testVersionToken(): void
    {
        $records = $this->records();
        $id = $this->item(['name' => 'Lamp']);
        $room = $this->room('Example room');
        $v1 = $records->get($this->items->key, $id)?->version;
        self::assertIsString($v1);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\z/', $v1);
        self::assertSame($v1, $records->get($this->items->key, $id)?->version);
        self::assertSame($v1, $this->records()->get($this->items->key, $id)?->version);

        // The documented construction, reproduced.
        $row = $this->rowOf($this->items, $id);
        $pairs = [];
        foreach ($row as $name => $value) {
            $pairs[] = [$name, $value];
        }
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $links = [[$this->f['parts']->key, []], [$this->f['part_of']->key, []], [$this->f['room']->key, []], [$this->f['stored']->key, []]];
        usort($links, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $json = json_encode([$pairs, $links], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame(substr(hash('sha256', $json), 0, 16), $v1);

        // Any column change, made outside the record layer.
        $this->db->run('UPDATE ' . $this->items->sql() . ' SET "_created_by" = ? WHERE "id" = ?', [self::OTHER_USER_KEY, $id]);
        $v2 = $records->get($this->items->key, $id)?->version;
        self::assertNotSame($v1, $v2);

        // A link added, then removed (also directly in the join table).
        $records->setLinks($this->f['stored']->key, $id, [$room], self::USER_KEY);
        $v3 = $records->get($this->items->key, $id)?->version;
        self::assertNotSame($v2, $v3);
        $this->db->run('DELETE FROM ' . Identifier::joinTable($this->relationKey($this->f['stored'])) . ' WHERE "left_id" = ?', [$id]);
        $v4 = $records->get($this->items->key, $id)?->version;
        self::assertNotSame($v3, $v4);

        // Two updates within the same second (clock not advanced).
        $a = $records->update($this->items->key, $id, [$this->f['text']->key => 'one'], InputMode::Form, (string) $v4, self::USER_KEY);
        $b = $records->update($this->items->key, $id, [$this->f['text']->key => 'two'], InputMode::Form, $a->version, self::USER_KEY);
        self::assertSame($a->updatedAt, $b->updatedAt);
        self::assertNotSame($a->version, $b->version);
        $this->expectException(Conflict::class);
        $records->update($this->items->key, $id, [$this->f['text']->key => 'three'], InputMode::Form, $a->version, self::USER_KEY);
    }

    public function testVersionTokenSortsColumnsAndLinkIds(): void
    {
        self::assertSame(
            RecordVersion::of(['b' => 2, 'a' => 1.0], ['k2' => [3, 1], 'k1' => []]),
            RecordVersion::of(['a' => 1.0, 'b' => 2], ['k1' => [], 'k2' => [1, 3]]),
        );
        self::assertNotSame(RecordVersion::of(['a' => 1.0], []), RecordVersion::of(['a' => 1], []));
        self::assertNotSame(RecordVersion::of(['a' => '1'], []), RecordVersion::of(['a' => 1], []));
    }

    // ------------------------------------------------------------ T-REC-7

    public function testSetLinksFromBothSidesAndOnASelfRelation(): void
    {
        $records = $this->records();
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $cellar = $this->room('Cellar');
        $stored = $this->relationKey($this->f['stored']);

        $this->clock->advance(10);
        $records->setLinks($this->f['stored']->key, $item, [$cellar, $hall, $cellar], self::OTHER_USER_KEY);
        self::assertSame(
            [[$item, $cellar, 1], [$item, $hall, 2]],
            array_map('array_values', $this->db->all('SELECT "left_id", "right_id", "position" FROM ' . Identifier::joinTable($stored) . ' ORDER BY "position"')),
        );
        $record = $records->get($this->items->key, $item);
        self::assertNotNull($record);
        self::assertEquals([new LinkedRecord($this->rooms->key, $cellar, 'Cellar'), new LinkedRecord($this->rooms->key, $hall, 'Hall')], $record->links[$this->f['stored']->key]);
        self::assertSame('2026-11-12T03:15:10Z', $record->updatedAt);
        self::assertSame(self::OTHER_USER_KEY, $record->updatedBy);
        self::assertSame('2026-11-12T03:15:10Z', $this->meta('updated_at'));

        // Reorder and replace.
        $records->setLinks($this->f['stored']->key, $item, [$attic, $cellar], self::USER_KEY);
        $record = $records->get($this->items->key, $item);
        self::assertSame([$attic, $cellar], array_map(static fn (LinkedRecord $l): int => $l->id, $record?->links[$this->f['stored']->key] ?? []));

        // From the right side: the room's reverse field; rows keep left = item.
        $lamp2 = $this->item(['name' => 'Desk lamp']);
        $records->setLinks($this->f['stores']->key, $hall, [$lamp2, $item], self::USER_KEY);
        $hallRecord = $records->get($this->rooms->key, $hall);
        self::assertSame([$lamp2, $item], array_map(static fn (LinkedRecord $l): int => $l->id, $hallRecord?->links[$this->f['stores']->key] ?? []));
        self::assertSame(['Desk lamp', 'Lamp'], array_map(static fn (LinkedRecord $l): ?string => $l->title, $hallRecord?->links[$this->f['stores']->key] ?? []));
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM ' . Identifier::joinTable($stored) . ' WHERE "right_id" = ? AND "left_id" IN (?, ?)', [$hall, $item, $lamp2]));
        // A join row has one position, shared by both sides: the hall's order (item second) now places
        // the hall at position 2 on the item too, tied with the cellar and so ordered by id.
        $record = $records->get($this->items->key, $item);
        self::assertSame([$attic, $hall, $cellar], array_map(static fn (LinkedRecord $l): int => $l->id, $record?->links[$this->f['stored']->key] ?? []));

        // Self-relation: a record may link to itself; the other side sees it.
        $records->setLinks($this->f['parts']->key, $item, [$item, $lamp2], self::USER_KEY);
        $record = $records->get($this->items->key, $item);
        self::assertSame([$item, $lamp2], array_map(static fn (LinkedRecord $l): int => $l->id, $record?->links[$this->f['parts']->key] ?? []));
        self::assertSame([$item], array_map(static fn (LinkedRecord $l): int => $l->id, $record?->links[$this->f['part_of']->key] ?? []));
        $lamp2Record = $records->get($this->items->key, $lamp2);
        self::assertSame([$item], array_map(static fn (LinkedRecord $l): int => $l->id, $lamp2Record?->links[$this->f['part_of']->key] ?? []));

        // Clearing.
        $records->setLinks($this->f['parts']->key, $item, [], self::USER_KEY);
        self::assertSame([], $records->get($this->items->key, $item)?->links[$this->f['parts']->key]);
    }

    public function testSetLinksProblems(): void
    {
        $records = $this->records();
        $item = $this->item(['name' => 'Lamp']);
        $other = $this->item(['name' => 'Kettle']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $gone = $this->room('Gone');
        $this->trashRecord($this->rooms, $gone);
        $before = $this->dump();

        // This side may link to one room only.
        self::assertSame([[$this->f['room']->key, 'problem.link.one_only', []]], self::problems(
            fn (): mixed => $records->setLinks($this->f['room']->key, $item, [$hall, $attic], self::USER_KEY),
        ));
        // Absent and trashed targets.
        self::assertSame([[$this->f['stored']->key, 'problem.link.target_missing', []]], self::problems(
            fn (): mixed => $records->setLinks($this->f['stored']->key, $item, [$hall, 999], self::USER_KEY),
        ));
        self::assertSame([[$this->f['stored']->key, 'problem.link.target_missing', []]], self::problems(
            fn (): mixed => $records->setLinks($this->f['stored']->key, $item, [$gone], self::USER_KEY),
        ));
        self::assertEquals($before, $this->dump());

        // The other side links to one record only: a room's "Items in room" target (an item) already in another room.
        $records->setLinks($this->f['room']->key, $item, [$hall], self::USER_KEY);
        self::assertSame([[$this->f['room_items']->key, 'problem.link.target_taken', ['title' => 'Lamp']]], self::problems(
            fn (): mixed => $records->setLinks($this->f['room_items']->key, $attic, [$other, $item], self::USER_KEY),
        ));
        $records->setLinks($this->f['room_items']->key, $hall, [$other, $item], self::USER_KEY);
        self::assertSame([$other, $item], array_map(static fn (LinkedRecord $l): int => $l->id, $records->get($this->rooms->key, $hall)?->links[$this->f['room_items']->key] ?? []));

        // The record must be live; ids must be positive integers, at most MAX_LINK_IDS.
        $this->trashRecord($this->items, $other);
        try {
            $records->setLinks($this->f['stored']->key, $other, [$hall], self::USER_KEY);
            self::fail('NotFound expected.');
        } catch (NotFound) {
            self::addToAssertionCount(1);
        }
        foreach ([[0], ['1'], [1.0], range(1, Records::MAX_LINK_IDS + 1)] as $bad) {
            try {
                $records->setLinks($this->f['stored']->key, $item, $bad, self::USER_KEY);
                self::fail('Invalid ids accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLinksToTrashedRecordsAreKeptAndHidden(): void
    {
        $records = $this->records();
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $cellar = $this->room('Cellar');
        $records->setLinks($this->f['stored']->key, $item, [$hall, $attic], self::USER_KEY);
        $this->trashRecord($this->rooms, $hall);

        $record = $records->get($this->items->key, $item);
        self::assertSame([$attic], array_map(static fn (LinkedRecord $l): int => $l->id, $record?->links[$this->f['stored']->key] ?? []));
        $records->setLinks($this->f['stored']->key, $item, [$cellar], self::USER_KEY);
        $join = Identifier::joinTable($this->relationKey($this->f['stored']));
        self::assertSame([$hall, $cellar], array_map('intval', array_column($this->db->all('SELECT "right_id" FROM ' . $join . ' WHERE "left_id" = ? ORDER BY "right_id"', [$item]), 'right_id')));

        // Restored, the hidden link reappears.
        $this->db->run('UPDATE ' . $this->rooms->sql() . ' SET "_trashed_at" = NULL WHERE "id" = ?', [$hall]);
        $record = $records->get($this->items->key, $item);
        self::assertSame([$hall, $cellar], array_map(static fn (LinkedRecord $l): int => $l->id, $record?->links[$this->f['stored']->key] ?? []));

        // A one-link field holding a link to a trashed record cannot take another.
        $records->setLinks($this->f['room']->key, $item, [$attic], self::USER_KEY);
        $this->trashRecord($this->rooms, $attic);
        self::assertSame([[$this->f['room']->key, 'problem.link.one_only', []]], self::problems(
            fn (): mixed => $records->setLinks($this->f['room']->key, $item, [$cellar], self::USER_KEY),
        ));
        $records->setLinks($this->f['room']->key, $item, [], self::USER_KEY);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM ' . Identifier::joinTable($this->relationKey($this->f['room'])) . ' WHERE "left_id" = ?', [$item]));
    }

    public function testAttachmentsAreListedInLinkOrder(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        foreach ([['b.pdf', 'application/pdf', 300], ['a.png', 'image/png', 200]] as $i => [$name, $type, $size]) {
            $this->db->run(
                'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)',
                [str_repeat((string) $i, 64), $size, $type, $name, self::START],
            );
            $this->db->run(
                'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
                [$this->f['files']->key, $item, $this->db->lastInsertId(), 2 - $i],
            );
        }
        $files = $this->records()->get($this->items->key, $item)?->attachments[$this->f['files']->key] ?? [];
        self::assertSame(['a.png', 'b.pdf'], array_map(static fn ($a): string => $a->originalName, $files));
        self::assertSame([2, 1], array_map(static fn ($a): int => $a->id, $files));
        self::assertSame(['image/png', 200], [$files[0]->mediaType, $files[0]->size]);
    }

    public function testConflictCarriesAValidToken(): void
    {
        self::assertSame('0123456789abcdef', (new Conflict('0123456789abcdef'))->currentVersion);
        $this->expectException(\InvalidArgumentException::class);
        new Conflict('not a token');
    }

    public function testCardinalityFixtureIsAsDescribed(): void
    {
        $relation = $this->schema()->relationOf($this->f['room']);
        self::assertNotNull($relation);
        self::assertSame(Cardinality::One, $relation->maxFor($this->f['room']->key));
        self::assertSame(Cardinality::Many, $relation->maxFor($this->f['room_items']->key));
    }
}
