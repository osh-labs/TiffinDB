<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Schema\Ddl;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-5 and T-ARC-12: the SQLite corpus. Each file is the source project's copy
 * changed with plain SQL (or, for the header and damage cases, by
 * writing bytes), wrapped in a zip with a matching manifest. Every case is
 * refused with its reason, a database error while checking is `damaged`
 * and never escapes as another exception, and a file the checks accept
 * is rebuilt from TiffinDB's own DDL only: no schema text of the source,
 * not even a harmless column default, reaches the new file.
 */
#[CoversClass(UntrustedProject::class)]
#[CoversClass(ProjectRebuilder::class)]
final class SqliteCorpusTest extends TestCase
{
    use ArchiveFixture;

    private const MARKER = 'ATTACH DATABASE marker';

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-sqlite-corpus-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    public function testNotSqliteIsRefused(): void
    {
        foreach (["synthetic text that is not a database\n", str_repeat("\0", 4096), 'SQLite format 3'] as $bytes) {
            $path = $this->jobFolder() . '/plain.tiffin';
            file_put_contents($path, $bytes);
            self::assertSame(ArchiveRefused::NOT_SQLITE, $this->refusal($this->zipFor($path)));
        }
        $copy = $this->copyOfSource();
        $this->writeBytes($copy, 18, "\x03\x03");
        self::assertSame(ArchiveRefused::NOT_SQLITE, $this->refusal($this->zipFor($copy)));
    }

    public function testWalHeaderIsAcceptedAfterTheRewrite(): void
    {
        $copy = $this->copyOfSource();
        $this->writeBytes($copy, 18, "\x02\x02");
        $zip = $this->zipFor($copy);
        [$reader] = $this->check($zip);
        $bytes = (string) file_get_contents($reader->projectPath());
        self::assertSame("\x01\x01", substr($bytes, 18, 2));
        self::assertFileDoesNotExist($reader->projectPath() . '-wal');
        [$report] = $this->import($zip);
        self::assertSame(5, $report->records);
    }

    public function testDamageIsRefused(): void
    {
        $unknownCollation = $this->hostileArchive(
            'PRAGMA writable_schema = ON',
            "UPDATE sqlite_master SET sql = replace(sql, '(\"_trashed_at\")', '(\"_trashed_at\" COLLATE no_such_collation)') WHERE name = 'x_" . Identifier::tableName($this->items->key) . "_trash'",
        );
        self::assertSame(ArchiveRefused::DAMAGED, $this->refusal($unknownCollation));

        $copy = $this->copyOfSource();
        $root = (int) (new \PDO('sqlite:' . $copy))->query("SELECT rootpage FROM sqlite_master WHERE name = '" . Identifier::tableName($this->items->key) . "'")->fetchColumn();
        $pageSize = (int) (new \PDO('sqlite:' . $copy))->query('PRAGMA page_size')->fetchColumn();
        $this->writeBytes($copy, ($root - 1) * $pageSize + 8, str_repeat("\xff", 64));
        self::assertSame(ArchiveRefused::DAMAGED, $this->refusal($this->zipFor($copy)));

        $copy = $this->copyOfSource();
        $bytes = (string) file_get_contents($copy);
        file_put_contents($copy, substr($bytes, 0, intdiv(strlen($bytes), 2)));
        self::assertContains($this->refusal($this->zipFor($copy)), [ArchiveRefused::DAMAGED]);
    }

    public function testIdentityIsChecked(): void
    {
        self::assertSame(ArchiveRefused::NOT_PROJECT, $this->refusal($this->hostileArchive('PRAGMA application_id = 1')));
        $newer = $this->copyOfSource();
        self::rawEdit($newer, 'PRAGMA user_version = 5');
        self::assertSame(ArchiveRefused::NEWER, $this->refusal($this->zipFor($newer, ['project_format_version' => 4])));
        self::assertSame(ArchiveRefused::NEWER, $this->refusal($this->zipFor($newer)));
        $older = $this->copyOfSource();
        self::rawEdit($older, 'PRAGMA user_version = 2');
        self::assertSame(ArchiveRefused::NOT_PROJECT, $this->refusal($this->zipFor($older, ['project_format_version' => 4])));
        self::assertSame(ArchiveRefused::NOT_PROJECT, $this->refusal($this->hostileArchive("UPDATE _tiffin_meta SET value = 'other' WHERE key = 'format'")));
        self::assertSame(ArchiveRefused::NOT_PROJECT, $this->refusal($this->hostileArchive("UPDATE _tiffin_meta SET value = '2' WHERE key = 'format_version'")));
        self::assertSame(ArchiveRefused::NOT_PROJECT, $this->refusal($this->hostileArchive('DROP TABLE _tiffin_import_log')));
    }

    public function testObjectsOutsideTheFormatAreRefused(): void
    {
        $items = Identifier::tableName($this->items->key);
        $rooms = Identifier::tableName($this->rooms->key);
        $cases = [
            'trigger' => ['CREATE TRIGGER evil AFTER INSERT ON "' . $items . '" BEGIN DELETE FROM _tiffin_meta; END'],
            'trigger on metadata' => ['CREATE TRIGGER evil BEFORE UPDATE ON _tiffin_meta BEGIN SELECT 1; END'],
            'view' => ['CREATE VIEW "t_aaaaaaaaaaaa" AS SELECT 1'],
            'view with another name' => ['CREATE VIEW evil AS SELECT * FROM _tiffin_meta'],
            'virtual table' => [
                'PRAGMA writable_schema = ON',
                "INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql) VALUES ('table', 't_aaaaaaaaaaaa', 't_aaaaaaaaaaaa', 0, 'CREATE VIRTUAL TABLE t_aaaaaaaaaaaa USING no_such_module(x)')",
            ],
            'extra table' => ['CREATE TABLE evil (x)'],
            'table with a reserved prefix' => ['CREATE TABLE _tiffin_saved (x)'],
            'other sqlite table' => [
                'PRAGMA writable_schema = ON',
                "INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql) VALUES ('table', 'sqlite_stat9', 'sqlite_stat9', 0, 'CREATE TABLE sqlite_stat9(x)')",
            ],
            'index with a foreign name' => ['CREATE INDEX evil_index ON "' . $items . '" ("_created_at")'],
            'index on an expression' => ['DROP INDEX "x_' . $items . '_trash"', 'CREATE INDEX "x_' . $items . '_trash" ON "' . $items . '" (abs("id"))'],
            'partial index' => ['DROP INDEX "x_' . $items . '_trash"', 'CREATE INDEX "x_' . $items . '_trash" ON "' . $items . '" ("_trashed_at") WHERE "id" > 0'],
            'index named for another table' => ['CREATE INDEX "x_' . $rooms . '_trash2" ON "' . $items . '" ("_trashed_at")'],
            'trash index on the wrong table' => ['DROP INDEX "x_' . $rooms . '_trash"', 'CREATE INDEX "x_' . $rooms . '_trash" ON "' . $items . '" ("_trashed_at")'],
            'field index on another table' => ['CREATE UNIQUE INDEX "x_f_' . $this->f['Name']->key . '_u" ON "' . $rooms . '" ("id")'],
            'trash purpose on a field' => ['CREATE INDEX "x_f_' . $this->f['Name']->key . '_trash" ON "' . $items . '" ("id")'],
            'check constraint on a user table' => [
                'PRAGMA writable_schema = ON',
                "UPDATE sqlite_master SET sql = replace(sql, '\"_trashed_by\" TEXT', '\"_trashed_by\" TEXT CHECK (length(randomblob(16)) > 0)') WHERE name = '" . $items . "'",
            ],
            'check constraint on a metadata table' => [
                'PRAGMA writable_schema = ON',
                "UPDATE sqlite_master SET sql = replace(sql, 'value TEXT NOT NULL', 'value TEXT NOT NULL CHECK (abs(1) = 1)') WHERE name = '_tiffin_meta'",
            ],
            'attach text in a metadata default' => [
                'PRAGMA writable_schema = ON',
                "UPDATE sqlite_master SET sql = replace(sql, 'help       TEXT NOT NULL DEFAULT ''''', 'help TEXT NOT NULL DEFAULT ''ATTACH DATABASE ''''/tmp/x'''' AS y''') WHERE name = '_tiffin_fields'",
            ],
            'metadata index changed' => ['DROP INDEX _tiffin_forms_default', 'CREATE UNIQUE INDEX _tiffin_forms_default ON _tiffin_forms (table_key) WHERE abs(is_default) = 1'],
            'sqlite_sequence changed' => [
                'PRAGMA writable_schema = ON',
                "UPDATE sqlite_master SET sql = 'CREATE TABLE sqlite_sequence(name,seq,extra)' WHERE name = 'sqlite_sequence'",
            ],
        ];
        foreach ($cases as $label => $statements) {
            self::assertSame(ArchiveRefused::UNEXPECTED_OBJECT, $this->refusal($this->hostileArchive(...$statements)), $label);
        }
    }

    public function testRealVirtualTableIsRefusedWhenTheModuleExists(): void
    {
        $copy = $this->copyOfSource();
        try {
            self::rawEdit($copy, 'CREATE VIRTUAL TABLE "t_aaaaaaaaaaaa" USING fts5(x)');
        } catch (\PDOException) {
            self::markTestSkipped('This SQLite has no fts5 module.');
        }
        self::assertSame(ArchiveRefused::UNEXPECTED_OBJECT, $this->refusal($this->zipFor($copy)));
    }

    public function testTablesThatDifferFromTheirFieldsAreRefused(): void
    {
        $items = Identifier::tableName($this->items->key);
        $rooms = Identifier::tableName($this->rooms->key);
        $roomName = Identifier::columnName($this->roomName->key);
        $cases = [
            'generated column' => ['ALTER TABLE "' . $items . '" ADD COLUMN "g" TEXT GENERATED ALWAYS AS (upper("_created_by")) VIRTUAL'],
            'stored generated column' => [
                'PRAGMA writable_schema = ON',
                "UPDATE sqlite_master SET sql = replace(sql, '\"_trashed_by\" TEXT', '\"_trashed_by\" TEXT GENERATED ALWAYS AS (\"_created_by\") VIRTUAL') WHERE name = '" . $items . "'",
            ],
            'extra column' => ['ALTER TABLE "' . $items . '" ADD COLUMN "extra" TEXT'],
            'missing column' => ['ALTER TABLE "' . $items . '" DROP COLUMN "' . Identifier::columnName($this->f['Notes']->key) . '"'],
            'join table extra column' => ['ALTER TABLE "' . Identifier::joinName($this->room->key) . '" ADD COLUMN "extra" INTEGER'],
            'without rowid' => [
                'DROP TABLE "' . $rooms . '"',
                'CREATE TABLE "' . $rooms . '" ("id" INTEGER NOT NULL PRIMARY KEY, "_created_at" TEXT NOT NULL, "_created_by" TEXT, "_updated_at" TEXT NOT NULL, "_updated_by" TEXT, "_trashed_at" TEXT, "_trashed_by" TEXT, "' . $roomName . '" TEXT) WITHOUT ROWID',
            ],
            'ids that are not the rowid' => [
                'DROP TABLE "' . $rooms . '"',
                'CREATE TABLE "' . $rooms . '" ("id" INTEGER PRIMARY KEY DESC, "_created_at" TEXT NOT NULL, "_created_by" TEXT, "_updated_at" TEXT NOT NULL, "_updated_by" TEXT, "_trashed_at" TEXT, "_trashed_by" TEXT, "' . $roomName . '" TEXT)',
                'INSERT INTO "' . $rooms . "\" VALUES ('x', '" . self::START . "', NULL, '" . self::START . "', NULL, NULL, NULL, 'Kitchen')",
            ],
        ];
        foreach ($cases as $label => $statements) {
            self::assertSame(ArchiveRefused::BAD_TABLE, $this->refusal($this->hostileArchive(...$statements)), $label);
        }
    }

    /** One `_tiffin_collections` row as plain SQL (synthetic values). */
    private static function collectionRow(string $key, string $tableKey, string $name, string $rules = "'[]'"): string
    {
        return "INSERT INTO _tiffin_collections (key, table_key, name, rules, position, created_at, updated_at) VALUES ('"
            . $key . "', '" . $tableKey . "', '" . $name . "', " . $rules . ", 1, '" . self::START . "', '" . self::START . "')";
    }

    /** T-ARC-12: the collections table of a version 4 file, and its absence before version 4. */
    public function testCollectionsTableIsCheckedByVersion(): void
    {
        $items = $this->items->key;
        $refused = [
            'extra index on the collections table' => [ArchiveRefused::UNEXPECTED_OBJECT, ['CREATE INDEX _tiffin_collections_key ON _tiffin_collections (key)']],
            'collections index changed' => [ArchiveRefused::UNEXPECTED_OBJECT, [
                'DROP INDEX _tiffin_collections_table',
                'CREATE INDEX _tiffin_collections_table ON _tiffin_collections (table_key, position) WHERE abs(position) > 0',
            ]],
            'check constraint on the collections table' => [ArchiveRefused::UNEXPECTED_OBJECT, [
                'PRAGMA writable_schema = ON',
                "UPDATE sqlite_master SET sql = replace(sql, 'position   INTEGER NOT NULL', 'position INTEGER NOT NULL CHECK (abs(position) >= 0)') WHERE name = '_tiffin_collections'",
            ]],
            'trigger on the collections table' => [ArchiveRefused::UNEXPECTED_OBJECT, ['CREATE TRIGGER x AFTER INSERT ON _tiffin_collections BEGIN SELECT 1; END']],
            'collections table missing' => [ArchiveRefused::NOT_PROJECT, ['DROP TABLE _tiffin_collections']],
            'rules text over 1 MiB in total' => [ArchiveRefused::BAD_METADATA, [
                self::collectionRow(self::corpusKey('c', 1), $items, 'A', "'[' || replace(hex(zeroblob(600000)), '00', ' ') || ']'"),
                self::collectionRow(self::corpusKey('c', 2), $items, 'B', "'[' || replace(hex(zeroblob(600000)), '00', ' ') || ']'"),
            ]],
            'more than 1,000 collection rows' => [ArchiveRefused::BAD_METADATA, [
                'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 1001)'
                . " INSERT INTO _tiffin_collections (key, table_key, name, position, created_at, updated_at) SELECT 'k' || i, '" . $items . "', 'N' || i, i, '" . self::START . "', '" . self::START . "' FROM n",
            ]],
            'collection key equal to a table key' => [ArchiveRefused::BAD_METADATA, [self::collectionRow($this->rooms->key, $items, 'A')]],
            'collection key equal to a field key' => [ArchiveRefused::BAD_METADATA, [self::collectionRow($this->f['Notes']->key, $items, 'A')]],
            'collection key equal to a relation key' => [ArchiveRefused::BAD_METADATA, [self::collectionRow($this->room->key, $items, 'A')]],
        ];
        $form = (string) $this->db->value('SELECT "key" FROM "_tiffin_forms" ORDER BY "key" LIMIT 1');
        $refused['collection key equal to a form key'] = [ArchiveRefused::BAD_METADATA, [self::collectionRow($form, $items, 'A')]];
        foreach ($refused as $label => [$reason, $statements]) {
            self::assertSame($reason, $this->refusal($this->hostileArchive(...$statements)), $label);
        }

        // An automatic index on the collections table, which SQLite never makes for it (the table is WITHOUT ROWID and has no UNIQUE column).
        $copy = $this->copyOfSource();
        $root = (int) (new \PDO('sqlite:' . $copy))->query("SELECT rootpage FROM sqlite_master WHERE name = '_tiffin_collections_name'")->fetchColumn();
        self::rawEdit(
            $copy,
            'PRAGMA writable_schema = ON',
            "INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql) VALUES ('index', 'sqlite_autoindex__tiffin_collections_1', '_tiffin_collections', " . $root . ', NULL)',
        );
        // SQLite itself treats an automatic index without its UNIQUE constraint as a damaged schema; UntrustedProject would refuse it too.
        self::assertContains($this->refusal($this->zipFor($copy)), [ArchiveRefused::UNEXPECTED_OBJECT, ArchiveRefused::DAMAGED]);

        // A version 3 file that has the table is refused; versions 1 to 3 without it are accepted.
        self::assertSame(ArchiveRefused::UNEXPECTED_OBJECT, $this->refusal($this->hostileArchive(
            'PRAGMA user_version = 3',
            "UPDATE _tiffin_meta SET value = '3' WHERE key = 'format_version'",
        )));
        $drops = [3 => ['DROP TABLE _tiffin_collections'], 2 => ['DROP TABLE _tiffin_import_log'], 1 => ['DROP TABLE _tiffin_forms']];
        $statements = [];
        foreach ($drops as $version => $drop) {
            $statements = [...$statements, ...$drop];
            [, $checked] = $this->check($this->hostileArchive(...$statements, ...[
                'PRAGMA user_version = ' . $version,
                "UPDATE _tiffin_meta SET value = '" . $version . "' WHERE key = 'format_version'",
            ]));
            self::assertSame($version, $checked->formatVersion);
            self::assertSame([], $checked->collections);
        }

        // A version 4 file with valid collections is accepted and its rows are read.
        [, $checked] = $this->check($this->hostileArchive(self::collectionRow(self::corpusKey('c', 1), $items, 'Example')));
        self::assertSame(4, $checked->formatVersion);
        self::assertSame([self::corpusKey('c', 1)], array_column($checked->collections, 'key'));
    }

    private static function corpusKey(string $prefix, int $i): string
    {
        return $prefix . strtr(sprintf('%011d', $i), '0123456789', 'abcdefghij');
    }

    public function testMetadataIsValidated(): void
    {
        $notes = $this->f['Notes']->key;
        $cases = [
            'unknown type' => ["UPDATE _tiffin_fields SET type = 'evil' WHERE key = '" . $notes . "'"],
            'type without its column' => ["UPDATE _tiffin_fields SET type = 'attachment' WHERE key = '" . $notes . "'"],
            'bad options' => ["UPDATE _tiffin_fields SET options = '{\"choices\":\"x\"}' WHERE key = '" . $this->f['Kind']->key . "'"],
            'options not an object' => ["UPDATE _tiffin_fields SET options = '[1]' WHERE key = '" . $notes . "'"],
            'options not json' => ["UPDATE _tiffin_fields SET options = 'nope' WHERE key = '" . $notes . "'"],
            'out-of-range option' => ["UPDATE _tiffin_fields SET options = '{\"max_length\":0}' WHERE key = '" . $notes . "'"],
            'relation to a missing field' => ["UPDATE _tiffin_relations SET left_field = 'zzzzzzzzzzzz' WHERE key = '" . $this->room->key . "'"],
            'relation field of the wrong side' => ["UPDATE _tiffin_fields SET options = '{\"relation\":\"" . $this->room->key . "\",\"side\":\"right\"}' WHERE key = '" . $this->f['Room']->key . "'"],
            'relation field without a relation' => ["UPDATE _tiffin_fields SET options = '{\"relation\":\"zzzzzzzzzzzz\",\"side\":\"left\"}' WHERE key = '" . $this->f['Room']->key . "'"],
            'bad maximum' => ["UPDATE _tiffin_relations SET left_max = 'several' WHERE key = '" . $this->room->key . "'"],
            'title field of a non-title type' => ["UPDATE _tiffin_tables SET title_field = '" . $this->f['Photo']->key . "' WHERE key = '" . $this->items->key . "'"],
            'title field of another table' => ["UPDATE _tiffin_tables SET title_field = '" . $this->roomName->key . "' WHERE key = '" . $this->items->key . "'"],
            'bad table key' => ["UPDATE _tiffin_tables SET key = 'BAD' WHERE key = '" . $this->rooms->key . "'"],
            'sql name differs' => ["UPDATE _tiffin_tables SET sql_name = '_tiffin_meta' WHERE key = '" . $this->rooms->key . "'"],
            'empty table name' => ["UPDATE _tiffin_tables SET name = '  ' WHERE key = '" . $this->rooms->key . "'"],
            'name with control character' => ["UPDATE _tiffin_fields SET name = 'Na' || char(8238) || 'me' WHERE key = '" . $notes . "'"],
            'position not positive' => ["UPDATE _tiffin_fields SET position = 0 WHERE key = '" . $notes . "'"],
            'required not a flag' => ["UPDATE _tiffin_fields SET required = 2 WHERE key = '" . $notes . "'"],
            'field of a missing table' => ["UPDATE _tiffin_fields SET table_key = 'zzzzzzzzzzzz' WHERE key = '" . $notes . "'"],
            'key used twice' => ["UPDATE _tiffin_forms SET key = '" . $this->items->key . "' WHERE table_key = '" . $this->rooms->key . "'"],
            'metadata without its table' => ["INSERT INTO _tiffin_tables VALUES ('aaaaaaaaaaaa', 't_aaaaaaaaaaaa', 'Ghosts', 'ghost', 3, NULL, '" . self::START . "', '" . self::START . "')"],
            'table without metadata' => ['CREATE TABLE "t_aaaaaaaaaaaa" ("id" INTEGER PRIMARY KEY AUTOINCREMENT)'],
        ];
        foreach ($cases as $label => $statements) {
            self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusal($this->hostileArchive(...$statements)), $label);
        }
    }

    public function testRebuildNeverRunsSchemaTextFromTheSource(): void
    {
        $items = Identifier::tableName($this->items->key);
        $notes = Identifier::columnName($this->f['Notes']->key);
        // Accepted by every check (the columns match and nothing runs on read), so it reaches the rebuild.
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            'PRAGMA writable_schema = ON',
            "UPDATE sqlite_master SET sql = replace(sql, '\"" . $notes . "\" TEXT', '\"" . $notes . "\" BLOB DEFAULT ''" . self::MARKER . "'' COLLATE NOCASE') WHERE name = '" . $items . "'",
            "UPDATE sqlite_master SET sql = replace(sql, '\"_created_by\" TEXT', '\"_created_by\" TEXT DEFAULT (''" . self::MARKER . "'')') WHERE name = '" . $items . "'",
            'ANALYZE',
        );
        self::assertSame(2, substr_count((string) file_get_contents($copy), self::MARKER), 'The source carries the text twice.');
        [$report, $db, $folder] = $this->import($this->zipFor($copy));
        self::assertSame(5, $report->records);
        $db->close();

        $bytes = (string) file_get_contents($folder . '/project.tiffin');
        self::assertStringNotContainsString(self::MARKER, $bytes);
        $pdo = new \PDO('sqlite:' . $folder . '/project.tiffin', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $objects = $pdo->query('SELECT type, name, sql FROM sqlite_master ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        $pdo = null;
        self::assertSame($this->expectedObjects(), $objects);
    }

    public function testStatisticsTableIsAccepted(): void
    {
        [$report] = $this->import($this->hostileArchive('ANALYZE'));
        self::assertSame(5, $report->records);
        self::assertSame(0, $report->valuesEmptiedTotal() + $report->linksDropped + $report->formsDropped + $report->timesReplaced);
        self::assertSame(2, $report->filesMissing, 'The archive holds no attachment files.');
    }

    /**
     * Every sqlite_master row of a project built the TiffinDB way from the
     * same metadata: ProjectFile::create and Ddl on an empty file.
     *
     * @return list<array<string, mixed>>
     */
    private function expectedObjects(): array
    {
        $path = $this->jobFolder() . '/expected.tiffin';
        $db = ProjectFile::create($path, 'aaaaaaaaaaaa', 'Expected', self::USER_KEY, self::VERSION_TEXT, $this->clock);
        $schema = $this->schema();
        $db->write(function () use ($db, $schema): void {
            foreach ($schema->tables() as $table) {
                Ddl::createTable($db, $table->key);
                // AUTOINCREMENT tables put a row in sqlite_sequence only once used; that changes no schema row.
            }
            foreach ($schema->tables() as $table) {
                foreach ($schema->fields($table->key) as $field) {
                    Ddl::addColumn($db, $field, $this->types->get($field->type));
                    if ($field->type === 'auto_number') {
                        Ddl::uniqueIndex($db, $field);
                    }
                }
            }
            Ddl::createJoinTable($db, $schema->relation($this->room->key));
        });
        $objects = $db->all('SELECT "type", "name", "sql" FROM "sqlite_master" ORDER BY "name"');
        $db->close();

        return $objects;
    }

    private function writeBytes(string $path, int $offset, string $bytes): void
    {
        $handle = fopen($path, 'r+b');
        self::assertIsResource($handle);
        fseek($handle, $offset);
        fwrite($handle, $bytes);
        fclose($handle);
    }
}
