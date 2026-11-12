<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-13 and the read model: Schema refuses files it cannot trust; lookups check keys first. */
#[CoversClass(Schema::class)]
final class SchemaReadTest extends TestCase
{
    use ProjectFixture;

    private string $tableKey;
    private string $fieldKey;
    private string $relationKey;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-schema-read-test-1');
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $this->tableKey = $items->key;
        $this->fieldKey = $editor->addField($items->key, 'Price', 'currency', [], false, '')->key;
        $link = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $this->relationKey = $link->options['relation'];
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function corrupt(string $sql, array $params = []): void
    {
        // Foreign keys are off on this connection, as in a tool that does not enable them.
        $raw = Sqlite::open($this->path());
        $raw->run('PRAGMA foreign_keys = OFF');
        $raw->write(static fn (Database $db) => $db->run($sql, $params));
        $raw->close();
    }

    public function testReadsWhatTheEditorWrote(): void
    {
        $schema = $this->schema();
        self::assertSame(['Items', 'Rooms'], array_map(static fn ($t) => $t->name, $schema->tables()));
        self::assertSame(['Name', 'Price', 'Room'], array_map(static fn ($f) => $f->name, $schema->fields($this->tableKey)));
        self::assertSame(['code' => 'USD', 'minor_digits' => 2], $schema->field($this->fieldKey)->options);
        self::assertSame($this->relationKey, $schema->relation($this->relationKey)->key);
        self::assertNull($schema->relationOf($schema->field($this->fieldKey)));
        self::assertSame('Example Project', $schema->projectName());
        self::assertSame(self::PROJECT_KEY, $schema->meta('project_key'));
        self::assertNull($schema->meta('absent'));
    }

    /** @return iterable<string, array{string}> */
    public static function badLookupKeys(): iterable
    {
        yield 'unknown' => ['aaaaaaaaaaaa'];
        yield 'malformed' => ['x"; DROP TABLE t; --'];
        yield 'upper case' => ['AAAAAAAAAAAA'];
    }

    #[DataProvider('badLookupKeys')]
    public function testLookupsGiveNotFound(string $key): void
    {
        $schema = $this->schema();
        foreach (['table', 'field', 'relation', 'fields'] as $method) {
            try {
                $schema->$method($key);
                self::fail($method . ' found ' . $key);
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function corruptions(): iterable
    {
        yield 'unknown type' => ['UPDATE "_tiffin_fields" SET "type" = \'colour\' WHERE "name" = \'Price\'', []];
        yield 'malformed table key' => ['UPDATE "_tiffin_tables" SET "key" = \'Bad"Key\', "sql_name" = \'t_Bad"Key\' WHERE "name" = \'Rooms\'', []];
        yield 'malformed field key' => ['UPDATE "_tiffin_fields" SET "key" = \'x"; DROP TABLE t; --\' WHERE "name" = \'Price\'', []];
        yield 'sql name not from key' => ['UPDATE "_tiffin_tables" SET "sql_name" = \'items; DROP TABLE x\' WHERE "name" = \'Items\'', []];
        yield 'column name not from key' => ['UPDATE "_tiffin_fields" SET "sql_name" = \'f_aaaaaaaaaaaa\' WHERE "name" = \'Price\'', []];
        yield 'options not an object' => ['UPDATE "_tiffin_fields" SET "options" = \'[]\' WHERE "name" = \'Price\'', []];
        yield 'options not JSON' => ['UPDATE "_tiffin_fields" SET "options" = \'{code\' WHERE "name" = \'Price\'', []];
        yield 'bad cardinality' => ['UPDATE "_tiffin_relations" SET "left_max" = \'two\'', []];
        yield 'join table not from key' => ['UPDATE "_tiffin_relations" SET "join_table" = \'links\'', []];
        yield 'relation field without relation' => ['DELETE FROM "_tiffin_relations"', []];
        yield 'title field elsewhere' => ['UPDATE "_tiffin_tables" SET "title_field" = (SELECT "key" FROM "_tiffin_fields" WHERE "name" = \'Price\') WHERE "name" = \'Rooms\'', []];
        yield 'required not 0 or 1' => ['UPDATE "_tiffin_fields" SET "required" = 2 WHERE "name" = \'Price\'', []];
        yield 'field of missing table' => ['UPDATE "_tiffin_fields" SET "table_key" = \'aaaaaaaaaaaa\' WHERE "name" = \'Price\'', []];
        yield 'metadata table missing' => ['DROP TABLE "_tiffin_relations"', []];
    }

    #[DataProvider('corruptions')]
    public function testRefusesAFileItCannotTrust(string $sql, array $params): void
    {
        $this->corrupt($sql, $params);
        try {
            $this->schema();
            self::fail('A corrupt file was read.');
        } catch (Unavailable $e) {
            self::assertSame('not-project', $e->reason);
        }
    }
}
