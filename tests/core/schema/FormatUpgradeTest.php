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
 * (`docs/formats/tiffin.md` section 11.1, M2 spec section 6), and T-FMT-5:
 * the upgrade from version 2 to 3 (section 11.2, M3 spec section 12.1), and
 * T-FMT-7: the upgrade from version 3 to 4 (section 11.3, M4 spec section
 * 12.4), alone and after the earlier steps. The version
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

    /**
     * The SQL blocks between the heading $from and $to, or the next heading
     * of any level when $to is null (so later sections, such as those of
     * planned format versions, are never read).
     *
     * @return list<string>
     */
    private static function sqlBlocks(string $from, ?string $to = null): array
    {
        $doc = RepoFiles::read('docs/formats/tiffin.md');
        $start = strpos($doc, $from);
        self::assertIsInt($start, 'Heading not found: ' . $from);
        $end = $to === null ? strpos($doc, "\n#", $start + strlen($from)) : strpos($doc, $to, $start);
        self::assertIsInt($end);
        $section = substr($doc, $start, $end - $start);
        preg_match_all('/```sql\n(.*?)```/s', $section, $blocks);

        return $blocks[1];
    }

    // ------------------------------------------------------------ T-FMT-1

    public function testStepTwoEqualsTheDocument(): void
    {
        $blocks = self::sqlBlocks('### 11.1 Upgrade from version 1 to version 2');
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
        self::assertGreaterThanOrEqual(2, count($section4));
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

        self::assertSame(4, $this->db->value('PRAGMA user_version'));
        self::assertSame('4', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_forms"'));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_import_log"'));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_collections"'));
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
        self::assertSame(4, $this->db->value('PRAGMA user_version'));
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
        }, 3 => static function (Database $db): void {
        }, 4 => static function (Database $db): void {
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
        self::assertSame(4, $this->db->value('PRAGMA user_version'));
    }

    // ------------------------------------------------------------ T-FMT-5

    public function testStepThreeEqualsTheDocument(): void
    {
        $blocks = self::sqlBlocks('### 11.2 Upgrade from version 2 to version 3');
        self::assertCount(1, $blocks);
        $documented = self::statements($blocks[0]);
        self::assertCount(4, $documented);
        self::assertSame(array_map([self::class, 'collapse'], array_slice($documented, 0, 2)), array_map([self::class, 'collapse'], FormatMigrations::STEPS[3]));
        self::assertSame($documented[2], FormatMigrations::VERSION_PRAGMAS[3]);
        self::assertSame(
            self::normalise('UPDATE _tiffin_meta SET value = \'3\' WHERE key = \'format_version\''),
            self::normalise($documented[3]),
        );

        // Section 4's third block is the same two statements.
        $section4 = self::sqlBlocks('## 4. Metadata tables', '## 5. User tables');
        self::assertGreaterThanOrEqual(3, count($section4));
        self::assertSame(array_map([self::class, 'collapse'], self::statements($section4[2])), array_map([self::class, 'collapse'], FormatMigrations::STEPS[3]));
    }

    /** Builds a version 2 file: the version 1 file of T-FMT-2 plus the statements of section 4's second block. */
    private function buildVersionTwo(): void
    {
        $this->buildVersionOne();
        $db = Sqlite::open($this->path());
        $db->run('PRAGMA user_version = 2');
        $block = self::statements(self::sqlBlocks('## 4. Metadata tables', '## 5. User tables')[1]);
        $db->write(static function (Database $db) use ($block): void {
            foreach ($block as $statement) {
                $db->run($statement);
            }
            $db->run('UPDATE "_tiffin_meta" SET "value" = \'2\' WHERE "key" = \'format_version\'');
            $db->run(
                'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "is_default", "generated", "created_at", "updated_at")'
                . ' VALUES (\'w3kq7vn2xbma\', ?, \'Standard form\', 1, \'<form></form>\', 1, 1, ?, ?)',
                [self::ITEMS, self::TIME, self::TIME],
            );
        });
        $db->close();
    }

    public function testAVersionTwoFileIsUpgradedOnOpen(): void
    {
        $this->buildVersionTwo();
        $plain = Sqlite::open($this->path());
        $before = self::userRows($plain);
        $forms = $plain->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"');
        $plain->close();

        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);

        $snapshots = $this->snapshots->list($this->folder);
        self::assertCount(1, $snapshots);
        self::assertSame(SnapshotReason::FormatUpgrade, $snapshots[0]->reason);
        $copy = Sqlite::open($this->folder . '/snapshots/' . $snapshots[0]->file);
        self::assertSame(2, $copy->value('PRAGMA user_version'));
        self::assertNull($copy->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_import_log\''));
        $copy->close();

        self::assertSame(4, $this->db->value('PRAGMA user_version'));
        self::assertSame('4', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_import_log"'));
        self::assertSame($before, self::userRows($this->db));
        self::assertSame($forms, $this->db->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"'));
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        self::assertSame([], $this->db->all('PRAGMA foreign_key_check'));
        $stored = [];
        foreach ($this->db->all('SELECT "sql" FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_import\\_log%\' ESCAPE \'\\\' ORDER BY "rowid"') as $row) {
            $stored[] = self::collapse($row['sql']);
        }
        self::assertSame(array_map([self::class, 'collapse'], FormatMigrations::STEPS[3]), $stored);

        // A minimal row takes the default report.
        $this->db->write(static fn (Database $db) => $db->run(
            'INSERT INTO "_tiffin_import_log" ("key", "imported_at", "source", "file_name", "file_size", "file_sha256", "rows_read", "rows_imported", "rows_skipped", "values_emptied")'
            . ' VALUES (\'k2mq7vn3xbpa\', ?, \'file\', \'example.csv\', 0, ?, 0, 0, 0, 0)',
            [self::TIME, str_repeat('0', 64)],
        ));
        self::assertSame('{}', $this->db->value('SELECT "report" FROM "_tiffin_import_log"'));

        // A second open takes no snapshot.
        $this->db->close();
        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);
        self::assertCount(1, $this->snapshots->list($this->folder));
    }

    public function testAFailingStepThreeLeavesTheFileAtVersionTwo(): void
    {
        $this->buildVersionOne();
        $failing = new FormatMigrations([
            2 => static function (Database $db): void {
                foreach (FormatMigrations::STEPS[2] as $statement) {
                    $db->run($statement);
                }
            },
            3 => static function (Database $db): void {
                foreach (FormatMigrations::STEPS[3] as $statement) {
                    $db->run($statement);
                }
                $db->run('INSERT INTO "_tiffin_missing" VALUES (1)');
            },
            4 => static function (Database $db): void {
            },
        ]);
        try {
            ProjectFile::open($this->path(), self::PROJECT, $this->snapshots, $failing);
            self::fail('The failing step was not reported.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->db = Sqlite::open($this->path());
        self::assertSame(2, $this->db->value('PRAGMA user_version'));
        self::assertSame('2', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertNull($this->db->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_import_log\''));
        self::assertCount(1, $this->snapshots->list($this->folder));
    }

    // ------------------------------------------------------------ T-FMT-7

    public function testStepFourEqualsTheDocument(): void
    {
        $blocks = self::sqlBlocks('### 11.3 Upgrade from version 3 to version 4');
        self::assertCount(1, $blocks);
        $documented = self::statements($blocks[0]);
        self::assertCount(5, $documented);
        self::assertSame(array_map([self::class, 'collapse'], array_slice($documented, 0, 3)), array_map([self::class, 'collapse'], FormatMigrations::STEPS[4]));
        self::assertSame($documented[3], FormatMigrations::VERSION_PRAGMAS[4]);
        self::assertSame(
            self::normalise('UPDATE _tiffin_meta SET value = \'4\' WHERE key = \'format_version\''),
            self::normalise($documented[4]),
        );

        // Section 4's fourth block is the same three statements.
        $section4 = self::sqlBlocks('## 4. Metadata tables', '## 5. User tables');
        self::assertGreaterThanOrEqual(4, count($section4));
        self::assertSame(array_map([self::class, 'collapse'], self::statements($section4[3])), array_map([self::class, 'collapse'], FormatMigrations::STEPS[4]));
    }

    /** Builds a version 3 file: the version 2 file plus section 4's third block and one import log row. */
    private function buildVersionThree(): void
    {
        $this->buildVersionTwo();
        $db = Sqlite::open($this->path());
        $db->run('PRAGMA user_version = 3');
        $block = self::statements(self::sqlBlocks('## 4. Metadata tables', '## 5. User tables')[2]);
        $db->write(static function (Database $db) use ($block): void {
            foreach ($block as $statement) {
                $db->run($statement);
            }
            $db->run('UPDATE "_tiffin_meta" SET "value" = \'3\' WHERE "key" = \'format_version\'');
            $db->run(
                'INSERT INTO "_tiffin_import_log" ("key", "imported_at", "source", "file_name", "file_size", "file_sha256", "rows_read", "rows_imported", "rows_skipped", "values_emptied")'
                . ' VALUES (\'k2mq7vn3xbpa\', ?, \'file\', \'example.csv\', 0, ?, 0, 0, 0, 0)',
                [self::TIME, str_repeat('0', 64)],
            );
        });
        $db->close();
    }

    /** @return list<string> every schema object of a file as "type name", sorted */
    private static function objects(Database $db): array
    {
        $names = [];
        foreach ($db->all('SELECT "type", "name" FROM "sqlite_master" ORDER BY "type", "name"') as $row) {
            $names[] = $row['type'] . ' ' . $row['name'];
        }

        return $names;
    }

    public function testAVersionThreeFileIsUpgradedOnOpen(): void
    {
        $this->buildVersionThree();
        $plain = Sqlite::open($this->path());
        $before = self::userRows($plain);
        $forms = $plain->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"');
        $log = $plain->all('SELECT * FROM "_tiffin_import_log" ORDER BY "key"');
        $objectsBefore = self::objects($plain);
        $plain->close();

        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);

        $snapshots = $this->snapshots->list($this->folder);
        self::assertCount(1, $snapshots);
        self::assertSame(SnapshotReason::FormatUpgrade, $snapshots[0]->reason);
        $copy = Sqlite::open($this->folder . '/snapshots/' . $snapshots[0]->file);
        self::assertSame(3, $copy->value('PRAGMA user_version'));
        self::assertSame('3', $copy->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertNull($copy->value('SELECT 1 FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_collections%\' ESCAPE \'\\\''));
        self::assertSame($before, self::userRows($copy));
        $copy->close();

        self::assertSame(4, $this->db->value('PRAGMA user_version'));
        self::assertSame('4', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_collections"'));
        self::assertSame($before, self::userRows($this->db));
        self::assertSame($forms, $this->db->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"'));
        self::assertSame($log, $this->db->all('SELECT * FROM "_tiffin_import_log" ORDER BY "key"'));
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        self::assertSame([], $this->db->all('PRAGMA foreign_key_check'));
        $stored = [];
        foreach ($this->db->all('SELECT "sql" FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_collections%\' ESCAPE \'\\\' ORDER BY "rowid"') as $row) {
            $stored[] = self::collapse($row['sql']);
        }
        self::assertSame(array_map([self::class, 'collapse'], FormatMigrations::STEPS[4]), $stored);
        // Exactly three objects are added: no other table, index, or automatic index.
        $added = array_values(array_diff(self::objects($this->db), $objectsBefore));
        self::assertSame(['index _tiffin_collections_name', 'index _tiffin_collections_table', 'table _tiffin_collections'], $added);
        self::assertSame([], array_values(array_diff($objectsBefore, self::objects($this->db))));

        // The defaults of the documented table.
        $this->db->write(static fn (Database $db) => $db->run(
            'INSERT INTO "_tiffin_collections" ("key", "table_key", "name", "position", "created_at", "updated_at") VALUES (\'w4kz7mq2nx3b\', ?, \'Example\', 1, ?, ?)',
            [self::ITEMS, self::TIME, self::TIME],
        ));
        self::assertSame(['all', '[]', null], array_values((array) $this->db->one('SELECT "match_mode", "rules", "sort" FROM "_tiffin_collections"')));

        // A second open takes no snapshot and changes nothing.
        $this->db->close();
        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);
        self::assertCount(1, $this->snapshots->list($this->folder));
        self::assertSame(4, $this->db->value('PRAGMA user_version'));
    }

    public function testTheUpgradedFileOpensWithThePlainSqliteDriver(): void
    {
        $this->buildVersionThree();
        ProjectFile::open($this->path(), self::PROJECT, $this->snapshots)->close();

        // No TiffinDB connection settings: a plain PDO connection, as any SQLite tool would open it.
        $pdo = new \PDO('sqlite:' . $this->path(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame(4, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $tables = $pdo->query('SELECT "name" FROM "sqlite_master" WHERE "type" = \'table\' AND "name" LIKE \'\\_tiffin\\_%\' ESCAPE \'\\\' ORDER BY "name"')->fetchAll(\PDO::FETCH_COLUMN);
        // The metadata tables the format document lists in section 4, each from a CREATE TABLE of that section.
        $documented = [];
        foreach (self::sqlBlocks('## 4. Metadata tables', '## 5. User tables') as $block) {
            preg_match_all('/CREATE TABLE (_tiffin_[a-z_]+)/', $block, $m);
            $documented = [...$documented, ...$m[1]];
        }
        sort($documented);
        self::assertSame($documented, $tables);
        self::assertSame(['j_c2vb6nxq4tkm', 't_h3kq7vn2xbma', 't_p6wz2kq7nm3v'], $pdo->query('SELECT "name" FROM "sqlite_master" WHERE "type" = \'table\' AND "name" GLOB \'[tj]_*\' ORDER BY "name"')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM "_tiffin_collections"')->fetchColumn());
        $pdo = null;
    }

    public function testAFailingStepFourLeavesTheFileAtVersionThree(): void
    {
        $this->buildVersionThree();
        $failing = new FormatMigrations([
            4 => static function (Database $db): void {
                foreach (FormatMigrations::STEPS[4] as $statement) {
                    $db->run($statement);
                }
                $db->run('INSERT INTO "_tiffin_missing" VALUES (1)');
            },
        ]);
        try {
            ProjectFile::open($this->path(), self::PROJECT, $this->snapshots, $failing);
            self::fail('The failing step was not reported.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->db = Sqlite::open($this->path());
        self::assertSame(3, $this->db->value('PRAGMA user_version'));
        self::assertSame('3', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertNull($this->db->value('SELECT 1 FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_collections%\' ESCAPE \'\\\''));
        self::assertCount(1, $this->snapshots->list($this->folder));

        // Opened again normally, the step runs once more and completes; the earlier
        // format-upgrade snapshot already holds this state, so no new one is taken (P183).
        $this->db->close();
        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);
        self::assertSame(4, $this->db->value('PRAGMA user_version'));
        self::assertCount(1, $this->snapshots->list($this->folder));
    }

    // ------------------------------------------------------------ T-ADM-21 (P183, M7 spec section 8)

    /** A step 4 that always fails after doing its work. */
    private static function failingStepFour(): FormatMigrations
    {
        return new FormatMigrations([
            4 => static function (Database $db): void {
                foreach (FormatMigrations::STEPS[4] as $statement) {
                    $db->run($statement);
                }
                $db->run('INSERT INTO "_tiffin_missing" VALUES (1)');
            },
        ]);
    }

    private function openFailing(): void
    {
        try {
            ProjectFile::open($this->path(), self::PROJECT, $this->snapshots, self::failingStepFour());
            self::fail('The failing step was not reported.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, int> snapshots by reason */
    private function reasons(): array
    {
        $count = [];
        foreach ($this->snapshots->list($this->folder) as $snapshot) {
            $count[$snapshot->reason->value] = ($count[$snapshot->reason->value] ?? 0) + 1;
        }
        ksort($count);

        return $count;
    }

    public function testRepeatedFailedUpgradesTakeOneSnapshotAndPruneNothing(): void
    {
        $this->buildVersionThree();
        $db = Sqlite::open($this->path());
        $manual = $this->snapshots->take($db, $this->folder, SnapshotReason::Manual);
        $older = [];
        for ($i = 0; $i < 19; $i++) {
            $this->clock->advance(60);
            $older[] = $this->snapshots->take($db, $this->folder, SnapshotReason::FieldDelete)->file;
        }
        $db->close();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->clock->advance(3600);
            $this->openFailing();
        }

        self::assertSame(['field-delete' => 19, 'format-upgrade' => 1, 'manual' => 1], $this->reasons());
        $names = array_map(static fn ($s): string => $s->file, $this->snapshots->list($this->folder));
        foreach ([$manual->file, ...$older] as $kept) {
            self::assertContains($kept, $names, 'Every older snapshot survives.');
        }
        $upgrade = $this->snapshots->list($this->folder)[0];
        self::assertSame(SnapshotReason::FormatUpgrade, $upgrade->reason);
        $copy = Sqlite::openUntrusted($this->folder . '/snapshots/' . $upgrade->file);
        self::assertSame(3, $copy->value('PRAGMA user_version'), 'The one snapshot holds the version 3 state.');
        $copy->close();

        // A normal open finally upgrades, still without another snapshot.
        $this->db = ProjectFile::open($this->path(), self::PROJECT, $this->snapshots);
        self::assertSame(4, $this->db->value('PRAGMA user_version'));
        self::assertSame(['field-delete' => 19, 'format-upgrade' => 1, 'manual' => 1], $this->reasons());
    }

    public function testAnUpgradeSnapshotOfAnotherVersionKeyOrADamagedOneDoesNotCount(): void
    {
        $this->buildVersionThree();
        $this->openFailing();
        $first = $this->snapshots->list($this->folder)[0];
        self::assertSame(SnapshotReason::FormatUpgrade, $first->reason);
        $path = $this->folder . '/snapshots/' . $first->file;

        // Another starting version.
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA user_version = 2');
        $pdo = null;
        $this->clock->advance(60);
        $this->openFailing();
        self::assertSame(['format-upgrade' => 2], $this->reasons());

        // Another project's key.
        $newest = $this->folder . '/snapshots/' . $this->snapshots->list($this->folder)[0]->file;
        $pdo = new \PDO('sqlite:' . $newest);
        $pdo->exec('UPDATE "_tiffin_meta" SET "value" = \'b7xk2mq4nz3p\' WHERE "key" = \'project_key\'');
        $pdo = null;
        $this->clock->advance(60);
        $this->openFailing();
        self::assertSame(['format-upgrade' => 3], $this->reasons());

        // A damaged file.
        $newest = $this->folder . '/snapshots/' . $this->snapshots->list($this->folder)[0]->file;
        file_put_contents($newest, 'synthetic damage');
        $this->clock->advance(60);
        $this->openFailing();
        self::assertSame(['format-upgrade' => 4], $this->reasons());

        // A newer snapshot of another reason: the next attempt takes its own.
        $db = Sqlite::open($this->path());
        $this->clock->advance(60);
        $this->snapshots->take($db, $this->folder, SnapshotReason::Manual);
        $db->close();
        $this->clock->advance(60);
        $this->openFailing();
        self::assertSame(['format-upgrade' => 5, 'manual' => 1], $this->reasons());
        $this->clock->advance(60);
        $this->openFailing();
        self::assertSame(['format-upgrade' => 5, 'manual' => 1], $this->reasons(), 'And then no more.');
    }

    public function testTheSeamTargetIsTheHighestInjectedVersionAndProductionIsUnchanged(): void
    {
        self::assertSame(ProjectFile::VERSION, (new FormatMigrations())->target());
        self::assertSame(ProjectFile::VERSION, (new FormatMigrations([2 => static function (Database $db): void {
        }]))->target());
        self::assertSame(7, (new FormatMigrations([5 => static function (Database $db): void {
        }, 7 => static function (Database $db): void {
        }]))->target());
        self::assertSame([5, 6, 7, 8], array_keys(FormatMigrations::SEAM_VERSION_PRAGMAS));
        foreach (FormatMigrations::SEAM_VERSION_PRAGMAS as $version => $pragma) {
            self::assertSame('PRAGMA user_version = ' . $version, $pragma);
        }
        $this->expectException(\InvalidArgumentException::class);
        new FormatMigrations([9 => static function (Database $db): void {
        }]);
    }
}
