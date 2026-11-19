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
use RuntimeException;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Schema\FormatMigrations;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\RepoFiles;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-1 (format version 2), T-SCH-2, T-SCH-3: creating, opening, and upgrading project files. */
#[CoversClass(ProjectFile::class)]
#[CoversClass(FormatMigrations::class)]
final class ProjectFileTest extends TestCase
{
    use ProjectFixture;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-project-file-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /**
     * The SQL blocks of section 4 of the format document that make up the
     * current format version, in order: the version 1 block, then the
     * "Version N adds" block of each version up to ProjectFile::VERSION.
     * Blocks for later, planned versions are not read, so the document may
     * describe them before the code writes them.
     *
     * @return list<string> one entry per version
     */
    private static function documentedBlocks(): array
    {
        $doc = file_get_contents(RepoFiles::path('docs/formats/tiffin.md'));
        self::assertIsString($doc);
        $start = (int) strpos($doc, '## 4. Metadata tables');
        $section = substr($doc, $start, (int) strpos($doc, '## 5. User tables') - $start);
        self::assertGreaterThanOrEqual(ProjectFile::VERSION, preg_match_all('/```sql\n(.*?)```/s', $section, $blocks, PREG_OFFSET_CAPTURE));
        $result = [];
        $previousEnd = 0;
        for ($version = 1; $version <= ProjectFile::VERSION; $version++) {
            [$sql, $offset] = $blocks[1][$version - 1];
            $intro = substr($section, $previousEnd, $offset - $previousEnd);
            if ($version > 1) {
                self::assertStringContainsString('Version ' . $version . ' adds', $intro, 'Block ' . $version . ' of section 4 is not the version ' . $version . ' block.');
            }
            $result[] = $sql;
            $previousEnd = $offset + strlen($sql);
        }

        return $result;
    }

    /** @return list<string> the statements of a block, white space collapsed */
    private static function statementsOf(string $block): array
    {
        $statements = array_values(array_filter(array_map('trim', explode(';', $block)), static fn (string $s): bool => $s !== ''));

        return array_map([self::class, 'collapse'], $statements);
    }

    /** @return list<string> the documented DDL of the current version, in order */
    private static function documentedDdl(): array
    {
        $statements = [];
        foreach (self::documentedBlocks() as $block) {
            $statements = [...$statements, ...self::statementsOf($block)];
        }

        return $statements;
    }

    private static function collapse(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    // ------------------------------------------------------------ T-SCH-1

    public function testCreateWritesIdentityAndVersion(): void
    {
        self::assertSame(ProjectFile::APPLICATION_ID, $this->db->value('PRAGMA application_id'));
        self::assertSame(1413891138, ProjectFile::APPLICATION_ID);
        self::assertSame(0x54464442, ProjectFile::APPLICATION_ID);
        self::assertSame(2, $this->db->value('PRAGMA user_version'));
        self::assertSame(2, ProjectFile::VERSION);
        self::assertSame(1, $this->db->value('PRAGMA foreign_keys'));
    }

    public function testCreateWritesTheNineMetaRows(): void
    {
        $meta = [];
        foreach ($this->db->all('SELECT "key", "value" FROM "_tiffin_meta" ORDER BY "key"') as $row) {
            $meta[$row['key']] = $row['value'];
        }
        self::assertSame([
            'created_at' => self::START,
            'created_by' => self::USER_KEY,
            'created_with' => '0.2.0-dev',
            'format' => 'tiffindb-project',
            'format_version' => '2',
            'project_key' => self::PROJECT_KEY,
            'project_name' => 'Example Project',
            'updated_at' => self::START,
            'updated_by' => self::USER_KEY,
        ], $meta);
    }

    public function testMetadataDdlEqualsTheFormatDocument(): void
    {
        $documented = self::documentedDdl();
        $blocks = self::documentedBlocks();
        // Versions 1 and 2 hold 10 and 3 statements; later versions only add.
        self::assertCount(10, self::statementsOf($blocks[0]));
        self::assertCount(3, self::statementsOf($blocks[1]));
        self::assertSame($documented, array_map([self::class, 'collapse'], ProjectFile::DDL));

        $stored = [];
        foreach ($this->db->all('SELECT "sql" FROM "sqlite_master" WHERE "name" LIKE \'\\_tiffin\\_%\' ESCAPE \'\\\' AND "sql" IS NOT NULL ORDER BY "rowid"') as $row) {
            $stored[] = self::collapse($row['sql']);
        }
        self::assertSame($documented, $stored);
    }

    public function testNoOtherObjectsAreCreated(): void
    {
        $names = array_keys($this->masterNames());
        sort($names);
        $expected = [
            '_tiffin_attachment_links', '_tiffin_attachments', '_tiffin_attachments_sha256', '_tiffin_fields',
            '_tiffin_fields_name', '_tiffin_fields_order', '_tiffin_forms', '_tiffin_forms_default', '_tiffin_forms_table',
            '_tiffin_meta', '_tiffin_relations', '_tiffin_tables',
            '_tiffin_tables_name', 'sqlite_sequence',
        ];
        $names = array_values(array_filter($names, static fn (string $n): bool => !str_starts_with($n, 'sqlite_autoindex_')));
        self::assertSame($expected, $names);
    }

    public function testCreateRefusesAnExistingPathAndBadKeys(): void
    {
        try {
            ProjectFile::create($this->path(), self::PROJECT_KEY, 'Example Project', self::USER_KEY, '0.2.0-dev', $this->clock);
            self::fail('An existing file was overwritten.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        foreach ([['BAD', self::USER_KEY], [self::PROJECT_KEY, 'x"; --']] as [$project, $user]) {
            try {
                ProjectFile::create($this->temp->path('other.tiffin'), $project, 'Example', $user, '0.2.0-dev', $this->clock);
                self::fail('A bad key was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertFileDoesNotExist($this->temp->path('other.tiffin'));
            }
        }
    }

    public function testTouchAndSetName(): void
    {
        $this->clock->advance(60);
        ProjectFile::touch($this->db, self::OTHER_USER_KEY, $this->clock);
        ProjectFile::setName($this->db, 'Example Renamed');
        self::assertSame('2026-11-12T03:16:00Z', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_at\''));
        self::assertSame(self::OTHER_USER_KEY, $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_by\''));
        self::assertSame('Example Renamed', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'project_name\''));
        $this->expectException(\InvalidArgumentException::class);
        ProjectFile::touch($this->db, 'not a key', $this->clock);
    }

    // ------------------------------------------------------------ T-SCH-2

    public function testOpenAcceptsTheFile(): void
    {
        $this->db->close();
        $this->db = ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots);
        self::assertSame('tiffindb-project', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format\''));
        self::assertSame([], $this->snapshotFiles());
    }

    private function assertUnavailable(string $reason, callable $open): void
    {
        try {
            $open();
            self::fail('The file was opened.');
        } catch (Unavailable $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testOpenRefusesAPlainSqliteFile(): void
    {
        $plain = Sqlite::create($this->temp->path('plain.sqlite'));
        $plain->run('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $plain->close();
        $this->assertUnavailable('not-project', fn () => ProjectFile::open($this->temp->path('plain.sqlite'), self::PROJECT_KEY, $this->snapshots));
    }

    public function testOpenRefusesAFileThatIsNotADatabase(): void
    {
        file_put_contents($this->temp->path('junk.tiffin'), str_repeat('not a database ', 100));
        $this->assertUnavailable('not-project', fn () => ProjectFile::open($this->temp->path('junk.tiffin'), self::PROJECT_KEY, $this->snapshots));
    }

    public function testOpenRefusesAWrongApplicationIdOrFormat(): void
    {
        $this->db->run('PRAGMA application_id = 1');
        $this->assertUnavailable('not-project', fn () => ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots));
        $this->db->run('PRAGMA application_id = 1413891138');
        $this->db->write(static fn (Database $db) => $db->run('UPDATE "_tiffin_meta" SET "value" = \'other-format\' WHERE "key" = \'format\''));
        $this->assertUnavailable('not-project', fn () => ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots));
    }

    public function testOpenRefusesANewerVersion(): void
    {
        $this->db->run('PRAGMA user_version = 3');
        $this->assertUnavailable('newer', fn () => ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots));
        self::assertSame([], $this->snapshotFiles());
    }

    public function testOpenRefusesADifferentProjectKey(): void
    {
        $this->assertUnavailable('mismatch', fn () => ProjectFile::open($this->path(), 'b7xk2mq4nz3p', $this->snapshots));
    }

    public function testOpenRefusesAMissingFile(): void
    {
        $this->assertUnavailable('missing', fn () => ProjectFile::open($this->temp->path('absent.tiffin'), self::PROJECT_KEY, $this->snapshots));
    }

    // ------------------------------------------------------------ T-SCH-3

    /** Turns the new file back into a version 1 file (no `_tiffin_forms`). */
    private function makeVersionOne(): void
    {
        $this->db->run('PRAGMA user_version = 1');
        $this->db->write(static function (Database $db): void {
            $db->run('DROP TABLE "_tiffin_forms"');
            $db->run('UPDATE "_tiffin_meta" SET "value" = \'1\' WHERE "key" = \'format_version\'');
        });
        $this->db->close();
    }

    private function makeVersionZero(): void
    {
        $this->db->run('PRAGMA user_version = 0');
        $this->db->write(static fn (Database $db) => $db->run('UPDATE "_tiffin_meta" SET "value" = \'0\' WHERE "key" = \'format_version\''));
        $this->db->close();
    }

    public function testInjectedStepRunsAfterAFormatUpgradeSnapshot(): void
    {
        $this->makeVersionOne();
        $steps = new FormatMigrations([2 => static function (Database $db): void {
            $db->run('CREATE TABLE "_tiffin_example" ("x" TEXT)');
            $db->run('INSERT INTO "_tiffin_example" ("x") VALUES (\'synthetic\')');
        }]);
        $this->db = ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots, $steps);

        self::assertSame(2, $this->db->value('PRAGMA user_version'));
        self::assertSame('2', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertSame('synthetic', $this->db->value('SELECT "x" FROM "_tiffin_example"'));

        $snapshots = $this->snapshots->list($this->folder);
        self::assertCount(1, $snapshots);
        self::assertSame(SnapshotReason::FormatUpgrade, $snapshots[0]->reason);
        // The snapshot was taken before the step: it still has version 1 and no new table.
        $copy = Sqlite::open($this->folder . '/snapshots/' . $snapshots[0]->file);
        self::assertSame(1, $copy->value('PRAGMA user_version'));
        self::assertNull($copy->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_example\''));
        $copy->close();
    }

    public function testAFailingStepRollsBack(): void
    {
        $this->makeVersionOne();
        $steps = new FormatMigrations([2 => static function (Database $db): void {
            $db->run('CREATE TABLE "_tiffin_example" ("x" TEXT)');
            $db->run('INSERT INTO "_tiffin_missing" VALUES (1)');
        }]);
        try {
            ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots, $steps);
            self::fail('The failing step was not reported.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->db = Sqlite::open($this->path());
        self::assertSame(1, $this->db->value('PRAGMA user_version'));
        self::assertSame('1', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertNull($this->db->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_example\''));
        self::assertCount(1, $this->snapshotFiles());
    }

    public function testEachStepRunsInItsOwnTransaction(): void
    {
        $this->makeVersionZero();
        $steps = new FormatMigrations([
            1 => static fn (Database $db) => $db->run('CREATE TABLE "_tiffin_example" ("x" TEXT)'),
            2 => static fn (Database $db) => $db->run('INSERT INTO "_tiffin_missing" VALUES (1)'),
        ]);
        try {
            ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots, $steps);
            self::fail('The failing step was not reported.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
        // Step 1 stays applied: the file is left at the last completed version.
        $this->db = Sqlite::open($this->path());
        self::assertSame(1, $this->db->value('PRAGMA user_version'));
        self::assertSame('1', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
        self::assertSame(1, $this->db->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_example\''));
        self::assertCount(1, $this->snapshotFiles());
    }

    public function testAMissingStepRefusesWithoutASnapshot(): void
    {
        // Version 0 files do not exist: no step 1 is defined.
        $this->makeVersionZero();
        $this->assertUnavailable('not-project', fn () => ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots));
        self::assertSame([], $this->snapshotFiles());
        $this->db = Sqlite::open($this->path());
    }

    public function testEveryVersionHasItsPragmaAndStepsStartAtVersionTwo(): void
    {
        self::assertSame([2], array_keys(FormatMigrations::STEPS));
        self::assertCount(3, FormatMigrations::STEPS[2]);
        self::assertSame(range(1, ProjectFile::VERSION), array_keys(FormatMigrations::VERSION_PRAGMAS));
        for ($version = 1; $version <= ProjectFile::VERSION; $version++) {
            self::assertSame('PRAGMA user_version = ' . $version, FormatMigrations::VERSION_PRAGMAS[$version]);
        }
        foreach ([[0 => static function (Database $db): void {}], [1 => ['SELECT 1']], ['1' => 'SELECT 1']] as $steps) {
            try {
                new FormatMigrations($steps);
                self::fail('Bad format steps were accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyTestsGiveFormatMigrationsSteps(): void
    {
        // The constructor's steps argument is a test seam: code under src/ never passes it.
        $plain = 0;
        foreach (RepoFiles::withExtension(['php'], 'src') as $file) {
            $tokens = array_values(array_filter(token_get_all(RepoFiles::read($file)), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            foreach ($tokens as $k => $token) {
                $name = $tokens[$k + 1] ?? null;
                if (!is_array($token) || $token[0] !== T_NEW || !is_array($name) || !str_ends_with(strtolower('\\' . $name[1]), '\\formatmigrations')) {
                    continue;
                }
                self::assertSame('(', $tokens[$k + 2] ?? null, $file);
                self::assertSame(')', $tokens[$k + 3] ?? null, $file . ' passes steps to FormatMigrations');
                $plain++;
            }
        }
        self::assertGreaterThan(0, $plain, 'ProjectFile::open should construct FormatMigrations.');
    }
}
