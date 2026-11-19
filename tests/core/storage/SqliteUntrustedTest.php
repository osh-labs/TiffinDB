<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\TempDir;

/**
 * Sqlite::openUntrusted (M3 spec section 11.4 step 2): read only, no
 * schema functions, no writes of any kind, never a link. Each file is
 * built with plain SQL in the test.
 */
#[CoversClass(Sqlite::class)]
final class SqliteUntrustedTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** @param list<string> $statements */
    private function build(string $name, array $statements): string
    {
        $path = $this->temp->path($name);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $pdo = null;

        return $path;
    }

    public function testOpensReadOnlyWithTheSafetyPragmas(): void
    {
        $path = $this->build('a.sqlite', ['CREATE TABLE t (x TEXT)', "INSERT INTO t VALUES ('synthetic')"]);
        $before = hash_file('sha256', $path);
        $db = Sqlite::openUntrusted($path);
        try {
            self::assertSame('synthetic', $db->value('SELECT x FROM t'));
            self::assertSame(0, $db->value('PRAGMA trusted_schema'));
            self::assertSame(1, $db->value('PRAGMA query_only'));
            self::assertSame(1, $db->value('PRAGMA cell_size_check'));
            self::assertSame(0, $db->value('PRAGMA mmap_size'));
            self::assertSame(0, $db->value('PRAGMA foreign_keys'));
            self::assertSame('delete', $db->value('PRAGMA journal_mode'));
            foreach (["INSERT INTO t VALUES ('x')", 'CREATE TABLE u (y)', 'PRAGMA user_version = 9'] as $write) {
                try {
                    $db->run($write);
                    self::fail('A write was accepted: ' . $write);
                } catch (\PDOException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $db->close();
        }
        self::assertSame($before, hash_file('sha256', $path));
        self::assertFileDoesNotExist($path . '-wal');
        self::assertFileDoesNotExist($path . '-journal');
    }

    public function testAHotJournalIsNeverRolledBackIntoTheFile(): void
    {
        // A copy taken mid-transaction: the file holds spilled pages and a hot rollback journal sits beside it.
        $source = $this->temp->path('live.sqlite');
        $pdo = new \PDO('sqlite:' . $source);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('CREATE TABLE t (x)');
        $pdo->exec('INSERT INTO t VALUES (1)');
        $pdo->exec('PRAGMA cache_size = 1');
        $pdo->beginTransaction();
        for ($i = 0; $i < 2000; $i++) {
            $pdo->exec('INSERT INTO t VALUES (randomblob(500))');
        }
        $path = $this->temp->path('hot.sqlite');
        copy($source, $path);
        copy($source . '-journal', $path . '-journal');
        $pdo->rollBack();
        $pdo = null;
        $file = hash_file('sha256', $path);
        $journal = hash_file('sha256', $path . '-journal');

        $db = Sqlite::openUntrusted($path);
        try {
            $db->value('SELECT COUNT(*) FROM t');
            self::fail('The hot journal was rolled back to answer the read.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        } finally {
            $db->close();
        }
        clearstatcache();
        self::assertSame($file, hash_file('sha256', $path), 'The file is as it arrived.');
        self::assertFileExists($path . '-journal');
        self::assertSame($journal, hash_file('sha256', $path . '-journal'));
    }

    public function testSchemaFunctionsDoNotRun(): void
    {
        // A view using a function: with trusted_schema off, SQLite refuses functions not marked innocuous in schema
        // objects; the read errors rather than run it.
        $path = $this->build('b.sqlite', ['CREATE TABLE t (x TEXT)', "INSERT INTO t VALUES ('a')", 'CREATE VIEW v AS SELECT load_extension(x) FROM t']);
        $db = Sqlite::openUntrusted($path);
        try {
            $db->value('SELECT * FROM v');
            self::fail('The view ran a function.');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase('unsafe use of', $e->getMessage());
        } finally {
            $db->close();
        }
    }

    public function testAnUnknownCollationIsAPdoException(): void
    {
        $path = $this->build('c.sqlite', ['CREATE TABLE t (x TEXT)', 'CREATE INDEX i ON t (x)']);
        // Rewrite the schema text so that an index names an unknown collation (as a hostile file would).
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA writable_schema = ON');
        $pdo->exec("UPDATE sqlite_master SET sql = 'CREATE INDEX i ON t (x COLLATE nosuch)' WHERE name = 'i'");
        $pdo = null;
        $db = Sqlite::openUntrusted($path);
        try {
            $db->value('PRAGMA integrity_check(1)');
            self::fail('The bad schema was read.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        } finally {
            $db->close();
        }
    }

    public function testRefusesLinksMissingFilesAndBadPaths(): void
    {
        $path = $this->build('d.sqlite', ['CREATE TABLE t (x)']);
        symlink($path, $this->temp->path('link.sqlite'));
        foreach ([$this->temp->path('link.sqlite'), $this->temp->path('absent.sqlite'), $this->temp->path('')] as $bad) {
            try {
                Sqlite::openUntrusted($bad);
                self::fail('Opened ' . $bad);
            } catch (Unavailable $e) {
                self::assertSame(Unavailable::MISSING, $e->reason);
            }
        }
        foreach (['relative.sqlite', 'file:' . $path . '?mode=rw', ':memory:', $path . "\0x"] as $bad) {
            try {
                Sqlite::openUntrusted($bad);
                self::fail('Opened ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
