<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\FormatMigrations;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-FMT-1 to T-FMT-3: the upgrade from project format version 1 to 2
 * (`docs/formats/tiffin.md` section 11.1, M2 spec section 6). The version
 * 1 file is built from the first SQL block of section 4 and the user-table
 * statements of sections 5 and 7, with the document's synthetic keys.
 */
#[CoversClass(FormatMigrations::class)]
#[CoversClass(ProjectFile::class)]
final class FormatUpgradeTest extends TestCase
{
    private const PROJECT = 'q7mzk2wbx4ae';
    private const USER = 'm2kq7vn3xbpa';
    private const ITEMS = 'h3kq7vn2xbma';
    private const ITEM_NAME = 'm4pz7k2qsw3n';
    private const ITEM_VALUE = 'b7xk2mq4nz3p';
    private const ITEM_ROOM = 'r5vk3pn7qx2m';
    private const ROOMS = 'p6wz2kq7nm3v';
    private const ROOM_NAME = 'k7n2qz4mxp3w';
    private const ROOM_ITEMS = 'n3xq7mz2kw4p';
    private const RELATION = 'c2vb6nxq4tkm';
    private const TIME = '2026-11-12T03:15:00Z';

    private TempDir $temp;
    private FixedClock $clock;
    private Snapshots $snapshots;
    private string $folder;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock(self::TIME);
        $this->snapshots = new Snapshots($this->clock, new Keys(new SeededRandom('tiffindb-format-upgrade-test-1')));
        $this->folder = $this->temp->path('projects/' . self::PROJECT);
        mkdir($this->folder, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->db?->close();
        $this->temp->remove();
    }

    private function path(): string
    {
        return $this->folder . '/project.tiffin';
    }

    private static function collapse(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** Collapsed white space, no identifier quotes, no final semicolon. */
    private static function normalise(string $sql): string
    {
        return rtrim(self::collapse(str_replace('"', '', $sql)), ';');
    }

    /** @return list<string> the statements of an SQL block, without their semicolons */
    private static function statements(string $block): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $block)), static fn (string $s): bool => $s !== ''));
    }

    /** @return list<string> */
    private static function sqlBlocks(string $from, string $to): array
    {
        $doc = RepoFiles::read('docs/formats/tiffin.md');
        $start = (int) strpos($doc, $from);
        $section = substr($doc, $start, (int) strpos($doc, $to, $start) - $start);
        preg_match_all('/```sql\n(.*?)```/s', $section, $blocks);

        return $blocks[1];
    }

    // ------------------------------------------------------------ T-FMT-1

    public function testStepTwoEqualsTheDocument(): void
    {
        $blocks = self::sqlBlocks('### 11.1 Upgrade from version 1 to version 2', '## 12. Forms');
        self::assertCount(1, $blocks);
        $documented = self::statements($blocks[0]);
        self::assertCount(5, $documented);

        self::assertSame(array_map([self::class, 'collapse'], array_slice($documented, 0, 3)), array_map([self::class, 'collapse'], FormatMigrations::STEPS[2]));
        self::assertSame($documented[3], FormatMigrations::VERSION_PRAGMAS[2]);

        // The fifth is the meta update the upgrade mechanism runs, with its bound version written out.
        $source = RepoFiles::read('src/Core/Schema/FormatMigrations.php');
        self::assertSame(1, preg_match('/\$db->run\(\'(UPDATE "_tiffin_meta" SET "value" = \? WHERE "key" = \\\\\'format_version\\\\\')\', \[\(string\) \$version\]\)/', $source, $match));
        $mechanism = str_replace(['\\\'', '?'], ['\'', '\'2\''], $match[1]);
        self::assertSame(self::normalise($documented[4]), self::normalise($mechanism));

        // Section 4's second block is the same three statements (one source for both).
        $section4 = self::sqlBlocks('## 4. Metadata tables', '## 5. User tables');
        self::assertSame(array_map([self::class, 'collapse'], self::statements($section4[1])), array_map([self::class, 'collapse'], FormatMigrations::STEPS[2]));
    }

    // ------------------------------------------------------------ T-FMT-2

    /** A version 1 project file with two tables, fields, records (two trashed), and a relation with links. */
    private function buildVersionOne(): void
    {
        $db = Sqlite::create($this->path());
        $db->run('PRAGMA application_id = 1413891138');
        $db->run('PRAGMA user_version = 1');
        $ddl = self::statements(self::sqlBlocks('## 4. Metadata tables', '## 5. User tables')[0]);
        self::assertCount(10, $ddl);
        $userDdl = self::statements(self::sqlBlocks('## 5. User tables', '## 6. Field types')[0]);
        self::assertCount(2, $userDdl);
        $db->write(function (Database $db) use ($ddl, $userDdl): void {
            foreach ($ddl as $statement) {
                $db->run($statement);
            }
            $meta = ['format' => 'tiffindb-project', 'format_version' => '1', 'project_key' => self::PROJECT, 'project_name' => 'Example Project',
                'created_at' => self::TIME, 'created_by' => self::USER, 'created_with' => '0.1.0-dev', 'updated_at' => self::TIME, 'updated_by' => self::USER];
            foreach ($meta as $key => $value) {
                $db->run('INSERT INTO "_tiffin_meta" ("key", "value") VALUES (?, ?)', [$key, $value]);
            }
            // Items: the documented user table, then Rooms with the same shape.
            foreach ($userDdl as $statement) {
                $db->run($statement);
                $db->run(strtr($statement, [self::ITEMS => self::ROOMS]));
            }
            $db->run('ALTER TABLE "t_h3kq7vn2xbma" ADD COLUMN "f_m4pz7k2qsw3n" TEXT');
            $db->run('ALTER TABLE "t_h3kq7vn2xbma" ADD COLUMN "f_b7xk2mq4nz3p" INTEGER');
            $db->run('ALTER TABLE "t_p6wz2kq7nm3v" ADD COLUMN "f_k7n2qz4mxp3w" TEXT');
            foreach (self::statements(self::sqlBlocks('## 7. Relations', '## 8. Attachments')[0]) as $statement) {
                $db->run($statement);
            }
            $db->run('CREATE UNIQUE INDEX "x_j_c2vb6nxq4tkm_l1" ON "j_c2vb6nxq4tkm" ("left_id")');
            $tables = [[self::ITEMS, 'Items', 'item', 1, self::ITEM_NAME], [self::ROOMS, 'Rooms', 'room', 2, self::ROOM_NAME]];
            foreach ($tables as [$key, $name, $itemName, $position, $title]) {
                $db->run('INSERT INTO "_tiffin_tables" VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$key, 't_' . $key, $name, $itemName, $position, $title, self::TIME, self::TIME]);
            }
            $fields = [
                [self::ITEM_NAME, self::ITEMS, 'f_' . self::ITEM_NAME, 'Name', 'text', '{"max_length":500}', 1],
                [self::ITEM_VALUE, self::ITEMS, 'f_' . self::ITEM_VALUE, 'Value', 'currency', '{"code":"USD","minor_digits":2}', 2],
                [self::ITEM_ROOM, self::ITEMS, null, 'Room', 'relation', '{"relation":"c2vb6nxq4tkm","side":"left"}', 3],
                [self::ROOM_NAME, self::ROOMS, 'f_' . self::ROOM_NAME, 'Name', 'text', '{"max_length":500}', 1],
                [self::ROOM_ITEMS, self::ROOMS, null, 'Items', 'relation', '{"relation":"c2vb6nxq4tkm","side":"right"}', 2],
            ];
            foreach ($fields as [$key, $table, $sqlName, $name, $type, $options, $position]) {
                $db->run(
                    'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "position", "created_at", "updated_at") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$key, $table, $sqlName, $name, $type, $options, $position, self::TIME, self::TIME],
                );
            }
            $db->run(
                'INSERT INTO "_tiffin_relations" VALUES (?, ?, ?, ?, \'one\', ?, ?, \'many\', ?)',
                [self::RELATION, 'j_' . self::RELATION, self::ITEMS, self::ITEM_ROOM, self::ROOMS, self::ROOM_ITEMS, self::TIME],
            );
            $rows = [
                ['t_p6wz2kq7nm3v', 'f_k7n2qz4mxp3w', 'Kitchen', null, null],
                ['t_p6wz2kq7nm3v', 'f_k7n2qz4mxp3w', 'Shed', null, self::TIME],
                ['t_h3kq7vn2xbma', 'f_m4pz7k2qsw3n', 'Toaster', 2999, null],
                ['t_h3kq7vn2xbma', 'f_m4pz7k2qsw3n', 'Mower', 32900, null],
                ['t_h3kq7vn2xbma', 'f_m4pz7k2qsw3n', 'Old kettle', null, self::TIME],
            ];
            foreach ($rows as [$table, $column, $name, $value, $trashed]) {
                if ($table === 't_h3kq7vn2xbma') {
                    $db->run('INSERT INTO "t_h3kq7vn2xbma" ("_created_at", "_updated_at", "_trashed_at", "f_m4pz7k2qsw3n", "f_b7xk2mq4nz3p") VALUES (?, ?, ?, ?, ?)', [self::TIME, self::TIME, $trashed, $name, $value]);
                } else {
                    $db->run('INSERT INTO "t_p6wz2kq7nm3v" ("_created_at", "_updated_at", "_trashed_at", "f_k7n2qz4mxp3w") VALUES (?, ?, ?, ?)', [self::TIME, self::TIME, $trashed, $name]);
                }
            }
            $db->run('INSERT INTO "j_c2vb6nxq4tkm" ("left_id", "right_id", "position") VALUES (1, 1, 0), (2, 2, 0), (3, 1, 0)');
        });
        $db->close();
    }

    /** @return array<string, list<array<string, mixed>>> every user table and join table, in a fixed order */
    private static function userRows(Database $db): array
    {
        return [
            'items' => $db->all('SELECT * FROM "t_h3kq7vn2xbma" ORDER BY "id"'),
            'rooms' => $db->all('SELECT * FROM "t_p6wz2kq7nm3v" ORDER BY "id"'),
            'links' => $db->all('SELECT * FROM "j_c2vb6nxq4tkm" ORDER BY "left_id", "right_id"'),
            'tables' => $db->all('SELECT * FROM "_tiffin_tables" ORDER BY "key"'),
            'fields' => $db->all('SELECT * FROM "_tiffin_fields" ORDER BY "key"'),
            'relations' => $db->all('SELECT * FROM "_tiffin_relations" ORDER BY "key"'),
        ];
    }

    public function testAVersionOneFileIsUpgradedOnOpen(): void
    {
        $this->buildVersionOne();
        $plain = Sqlite::open($this->path());
        $before = self::userRows($plain);
        new Schema($plain, FieldTypes::standard());
        $plain->close();

        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);

        $snapshots = $this->snapshots->list($this->folder);
        self::assertCount(1, $snapshots);
        self::assertSame(SnapshotReason::FormatUpgrade, $snapshots[0]->reason);
        $copy = Sqlite::open($this->folder . '/snapshots/' . $snapshots[0]->file);
        self::assertSame(1, $copy->value('PRAGMA user_version'));
        self::assertSame('1', $copy->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertNull($copy->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_forms\''));
        self::assertSame($before, self::userRows($copy));
        $copy->close();

        self::assertSame(2, $this->db->value('PRAGMA user_version'));
        self::assertSame('2', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_forms"'));
        self::assertSame($before, self::userRows($this->db));
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        self::assertSame([], $this->db->all('PRAGMA foreign_key_check'));
        $stored = [];
        foreach ($this->db->all('SELECT "sql" FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_forms%\' ESCAPE \'\\\' ORDER BY "rowid"') as $row) {
            $stored[] = self::collapse($row['sql']);
        }
        self::assertSame(array_map([self::class, 'collapse'], FormatMigrations::STEPS[2]), $stored);
        $schema = new Schema($this->db, FieldTypes::standard());
        self::assertCount(2, $schema->tables());

        // A second open takes no snapshot.
        $this->db->close();
        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);
        self::assertCount(1, $this->snapshots->list($this->folder));
        self::assertSame(2, $this->db->value('PRAGMA user_version'));
    }

    // ------------------------------------------------------------ T-FMT-3

    public function testAFailingStepTwoLeavesTheFileAtVersionOne(): void
    {
        $this->buildVersionOne();
        $failing = new FormatMigrations([2 => static function (Database $db): void {
            foreach (FormatMigrations::STEPS[2] as $statement) {
                $db->run($statement);
            }
            $db->run('INSERT INTO "_tiffin_missing" VALUES (1)');
        }]);
        try {
            ProjectFile::open($this->path(), self::PROJECT, $this->snapshots, $failing);
            self::fail('The failing step was not reported.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->db = Sqlite::open($this->path());
        self::assertSame(1, $this->db->value('PRAGMA user_version'));
        self::assertSame('1', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertNull($this->db->value('SELECT 1 FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_forms%\' ESCAPE \'\\\''));
        self::assertCount(1, $this->snapshots->list($this->folder));

        // The file still opens (and upgrades) normally afterwards.
        $this->db->close();
        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);
        self::assertSame(2, $this->db->value('PRAGMA user_version'));
    }
}
