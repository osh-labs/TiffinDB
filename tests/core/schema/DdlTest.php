<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Ddl;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;

require_once __DIR__ . '/ProjectFixture.php';

/**
 * T-SCH-20: the DDL that Ddl writes equals the text M2's SchemaEditor wrote
 * before the refactor (the templates below are that text, copied from the
 * M2 code), for every type with a column, every relation cardinality, and
 * a self-relation; sqlite_master is compared statement by statement. The
 * T-SCH-5 name rule is checked on what Ddl alone writes.
 */
#[CoversClass(Ddl::class)]
#[CoversClass(SchemaEditor::class)]
final class DdlTest extends TestCase
{
    use ProjectFixture;

    /** M2's SchemaEditor::createTable text, with {t} for the table key. */
    private const M2_TABLE = 'CREATE TABLE "t_{t}" (
    "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
    "_created_at" TEXT NOT NULL,
    "_created_by" TEXT,
    "_updated_at" TEXT NOT NULL,
    "_updated_by" TEXT,
    "_trashed_at" TEXT,
    "_trashed_by" TEXT
)';
    private const M2_TRASH = 'CREATE INDEX "x_t_{t}_trash" ON "t_{t}" ("_trashed_at")';
    private const M2_UNIQUE = 'CREATE UNIQUE INDEX "x_f_{f}_u" ON "t_{t}" ("f_{f}")';
    private const M2_JOIN = 'CREATE TABLE "j_{r}" (
    "left_id"  INTEGER NOT NULL REFERENCES "t_{l}" ("id") ON DELETE CASCADE,
    "right_id" INTEGER NOT NULL REFERENCES "t_{o}" ("id") ON DELETE CASCADE,
    "position" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("left_id", "right_id")
) WITHOUT ROWID';
    private const M2_R = 'CREATE INDEX "x_j_{r}_r" ON "j_{r}" ("right_id")';
    private const M2_L1 = 'CREATE UNIQUE INDEX "x_j_{r}_l1" ON "j_{r}" ("left_id")';
    private const M2_R1 = 'CREATE UNIQUE INDEX "x_j_{r}_r1" ON "j_{r}" ("right_id")';
    /** Column definitions by type, as M2's ColumnDefinition gives them. */
    private const M2_COLUMNS = [
        'text' => 'TEXT', 'long_text' => 'TEXT', 'number' => 'NUMERIC', 'currency' => 'INTEGER', 'rating' => 'INTEGER',
        'checkbox' => 'INTEGER NOT NULL DEFAULT 0', 'date' => 'TEXT', 'time' => 'TEXT', 'date_time' => 'TEXT', 'duration' => 'INTEGER',
        'choice' => 'TEXT', 'url' => 'TEXT', 'email' => 'TEXT', 'phone' => 'TEXT', 'auto_number' => 'INTEGER',
    ];

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-ddl-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return array{0: array<string, string>, 1: array<string, ?string>} name => SQL in sqlite_master for user objects, and the expected text */
    private function buildWithTheEditor(): array
    {
        $editor = $this->editor();
        $a = $editor->createTable('Things', 'thing', true);
        $b = $editor->createTable('Places', 'place', false);
        $expected = [];
        foreach ([$a->key, $b->key] as $t) {
            $expected['t_' . $t] = strtr(self::M2_TABLE, ['{t}' => $t]);
            $expected['x_t_' . $t . '_trash'] = strtr(self::M2_TRASH, ['{t}' => $t]);
        }
        foreach ($this->types->ids() as $type) {
            if (in_array($type, ['relation', 'attachment'], true)) {
                continue;
            }
            $field = $editor->addField($a->key, 'F ' . $type, $type, $type === 'choice' ? ['choices' => ['X']] : [], false, '');
            if ($type === 'auto_number') {
                $expected['x_f_' . $field->key . '_u'] = strtr(self::M2_UNIQUE, ['{f}' => $field->key, '{t}' => $a->key]);
            }
        }
        $editor->addField($a->key, 'F attachment', 'attachment', [], false, '');
        $cases = [[Cardinality::One, Cardinality::One, $b->key], [Cardinality::One, Cardinality::Many, $b->key],
            [Cardinality::Many, Cardinality::One, $b->key], [Cardinality::Many, Cardinality::Many, $b->key], [Cardinality::One, Cardinality::One, $a->key]];
        foreach ($cases as $i => [$left, $right, $target]) {
            $field = $editor->addRelation($a->key, 'R ' . $i, $target, $left, $right, 'Back ' . $i, false, '');
            $r = (string) $field->options['relation'];
            $expected['j_' . $r] = strtr(self::M2_JOIN, ['{r}' => $r, '{l}' => $a->key, '{o}' => $target]);
            $expected['x_j_' . $r . '_r'] = strtr(self::M2_R, ['{r}' => $r]);
            if ($left === Cardinality::One) {
                $expected['x_j_' . $r . '_l1'] = strtr(self::M2_L1, ['{r}' => $r]);
            }
            if ($right === Cardinality::One) {
                $expected['x_j_' . $r . '_r1'] = strtr(self::M2_R1, ['{r}' => $r]);
            }
        }
        // ALTER TABLE ADD COLUMN appends ', <column>' before the closing parenthesis of the stored text.
        $table = substr(strtr(self::M2_TABLE, ['{t}' => $a->key]), 0, -1);
        foreach ($this->schema()->fields($a->key) as $field) {
            if ($field->hasColumn) {
                $table .= ', "f_' . $field->key . '" ' . self::M2_COLUMNS[$field->type];
            }
        }
        $expected['t_' . $a->key] = $table . ')';

        return [$this->userObjects($this->db), $expected];
    }

    /** @return array<string, ?string> */
    private function userObjects(Database $db): array
    {
        $objects = [];
        foreach ($db->all('SELECT "name", "sql" FROM "sqlite_master" WHERE "name" NOT LIKE \'\\_tiffin\\_%\' ESCAPE \'\\\' AND "name" NOT LIKE \'sqlite\\_%\' ESCAPE \'\\\' ORDER BY "name"') as $row) {
            $objects[(string) $row['name']] = $row['sql'] === null ? null : (string) $row['sql'];
        }

        return $objects;
    }

    public function testTheEditorWritesTheM2Text(): void
    {
        [$actual, $expected] = $this->buildWithTheEditor();
        ksort($expected);
        self::assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $name => $sql) {
            self::assertSame(self::collapse($sql), self::collapse((string) $actual[$name]), $name);
        }
    }

    public function testDdlAloneWritesWhatTheEditorWrote(): void
    {
        [$fromEditor] = $this->buildWithTheEditor();
        $schema = $this->schema();
        $plain = Sqlite::create($this->temp->path('plain.sqlite'));
        try {
            $plain->write(function (Database $db) use ($schema): void {
                foreach ($schema->tables() as $table) {
                    Ddl::createTable($db, $table->key);
                }
                foreach ($schema->tables() as $table) {
                    foreach ($schema->fields($table->key) as $field) {
                        if ($field->type === 'relation') {
                            continue;
                        }
                        Ddl::addColumn($db, $field, $this->types->get($field->type));
                        if ($field->type === 'auto_number') {
                            Ddl::uniqueIndex($db, $field);
                        }
                    }
                }
                $seen = [];
                foreach ($schema->tables() as $table) {
                    foreach ($schema->relationsTouching($table->key) as $relation) {
                        if (!isset($seen[$relation->key])) {
                            $seen[$relation->key] = true;
                            Ddl::createJoinTable($db, $relation);
                        }
                    }
                }
            });
            $fromDdl = $this->userObjects($plain);
            // T-SCH-5 for Ddl: it takes keys, never names, so every object name is a generated one.
            foreach ($plain->all('SELECT "name" FROM "sqlite_master"') as $row) {
                self::assertMatchesRegularExpression('/^(_tiffin_\w+|sqlite_\w+|[tfj]_[a-z2-7]{12}|x_[tfj]_[a-z2-7]{12}_(trash|u|r|l1|r1))$/', (string) $row['name']);
            }
        } finally {
            $plain->close();
        }
        self::assertSame(array_keys($fromEditor), array_keys($fromDdl));
        foreach ($fromEditor as $name => $sql) {
            self::assertSame($sql, $fromDdl[$name], $name);
        }
    }

    public function testTheTypeChangeColumnUsesTheSameDefinition(): void
    {
        $editor = $this->editor();
        $table = $editor->createTable('Things', 'thing', false);
        $field = $editor->addField($table->key, 'Count', 'text', [], false, '');
        $editor->changeType($field->key, 'number', ['decimals' => 0], 0);
        $sql = (string) $this->db->value('SELECT "sql" FROM "sqlite_master" WHERE "name" = ?', ['t_' . $table->key]);
        self::assertStringEndsWith("\n" . ', "f_' . $field->key . '" NUMERIC)', $sql);
    }

    public function testRefusals(): void
    {
        $text = new FieldDef('m4pz7k2qsw3n', 'h3kq7vn2xbma', 'Name', 'text', [], false, '', 1, true);
        $relation = new RelationDef('c2vb6nxq4tkm', 'h3kq7vn2xbma', 'r5vk3pn7qx2m', Cardinality::One, 'p6wz2kq7nm3v', 'n3xq7mz2kw4p', Cardinality::Many);
        $cases = [
            'type not the field type' => fn () => Ddl::addColumn($this->db, $text, $this->types->get('number')),
            'unique index of a text field' => fn () => Ddl::uniqueIndex($this->db, $text),
            'unknown side' => fn () => Ddl::oneIndex($this->db, $relation, 'middle'),
            'bad table key' => fn () => Ddl::createTable($this->db, 'x"; DROP TABLE _tiffin_meta; --'),
            'bad field key' => fn () => Ddl::addColumn($this->db, new FieldDef('BAD KEY', 'h3kq7vn2xbma', 'Name', 'text', [], false, '', 1, true), $this->types->get('text')),
            'bad relation table' => fn () => Ddl::createJoinTable($this->db, new RelationDef('c2vb6nxq4tkm', 't" (x)', 'r5vk3pn7qx2m', Cardinality::One, 'p6wz2kq7nm3v', 'n3xq7mz2kw4p', Cardinality::Many)),
        ];
        $before = $this->dump();
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail($name . ' was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertEquals($before, $this->dump());
        // A field without a column adds nothing.
        Ddl::addColumn($this->db, new FieldDef('m4pz7k2qsw3n', 'h3kq7vn2xbma', 'Files', 'attachment', [], false, '', 1, false), $this->types->get('attachment'));
        self::assertEquals($before, $this->dump());
    }

    private static function collapse(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
