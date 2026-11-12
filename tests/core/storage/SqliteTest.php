<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-STO-1 (connection settings), T-STO-2 (transactions), T-STO-3 (busy).
 */
#[CoversClass(Sqlite::class)]
#[CoversClass(Database::class)]
final class SqliteTest extends TestCase
{
    private TempDir $temp;
    /** @var list<Database> */
    private array $open = [];

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
    }

    protected function tearDown(): void
    {
        foreach ($this->open as $db) {
            $db->close();
        }
        $this->open = [];
        $this->temp->remove();
    }

    private function track(Database $db): Database
    {
        $this->open[] = $db;

        return $db;
    }

    // ------------------------------------------------------------ T-STO-1

    public function testMinimumVersionIsTheChosenFloor(): void
    {
        self::assertSame('3.35.5', Sqlite::MIN_VERSION);
        self::assertSame(5000, Sqlite::BUSY_TIMEOUT_MS);
        $version = Sqlite::libraryVersion();
        self::assertIsString($version);
        self::assertTrue(Sqlite::isSupported($version), 'The test environment needs SQLite 3.35.5 or later.');
        self::assertFalse(Sqlite::isSupported('3.35.4'));
        self::assertTrue(Sqlite::isSupported('3.35.5'));
    }

    public function testCreateAppliesTheConnectionSettings(): void
    {
        $path = $this->temp->path('new.sqlite');
        $db = $this->track(Sqlite::create($path));

        self::assertFileExists($path);
        self::assertSame($path, $db->path());
        self::assertSame('wal', $db->journalMode());
        self::assertSame('wal', $db->value('PRAGMA journal_mode'));
        self::assertSame(1, $db->value('PRAGMA foreign_keys'));
        self::assertSame(5000, $db->value('PRAGMA busy_timeout'));
        self::assertSame(1, $db->value('PRAGMA synchronous'), 'synchronous = NORMAL');
    }

    public function testOpenAppliesTheSameSettings(): void
    {
        $path = $this->temp->path('existing.sqlite');
        Sqlite::create($path)->close();

        $db = $this->track(Sqlite::open($path, 1234));
        self::assertSame('wal', $db->journalMode());
        self::assertSame(1, $db->value('PRAGMA foreign_keys'));
        self::assertSame(1234, $db->value('PRAGMA busy_timeout'));
    }

    public function testCreateSetsMode0640(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This file system does not keep POSIX permission bits (non-POSIX platform).');
        }
        $probe = $this->temp->path('mode-probe');
        file_put_contents($probe, '');
        chmod($probe, 0604);
        clearstatcache(true, $probe);
        if ((fileperms($probe) & 0777) !== 0604) {
            self::markTestSkipped('The temporary file system ignores permission bits set with chmod.');
        }

        $path = $this->temp->path('mode.sqlite');
        $old = umask(0022);
        try {
            $db = $this->track(Sqlite::create($path));
            $db->run('CREATE TABLE t (v TEXT)');
            $db->run('INSERT INTO t (v) VALUES (?)', ['x']);
        } finally {
            umask($old);
        }
        clearstatcache();
        self::assertSame(0640, fileperms($path) & 0777);
        self::assertFileExists($path . '-wal');
        self::assertSame(0640, fileperms($path . '-wal') & 0777, 'SQLite gives the WAL file the mode of the database.');
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPaths(): iterable
    {
        yield 'relative' => ['relative.sqlite'];
        yield 'dot relative' => ['./relative.sqlite'];
        yield 'file URI' => ['file:/tmp/x.sqlite'];
        yield 'file URI, upper case' => ['FILE:///tmp/x.sqlite?mode=memory'];
        yield 'memory' => [':memory:'];
        yield 'empty' => [''];
        yield 'NUL byte' => ["/tmp/x\0.sqlite"];
    }

    #[DataProvider('refusedPaths')]
    public function testCreateRefusesPathsThatAreNotAbsoluteFiles(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sqlite::create($path);
    }

    #[DataProvider('refusedPaths')]
    public function testOpenRefusesPathsThatAreNotAbsoluteFiles(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sqlite::open($path);
    }

    public function testCreateRefusesAnExistingPath(): void
    {
        $path = $this->temp->path('taken.sqlite');
        file_put_contents($path, '');

        $this->expectException(RuntimeException::class);
        Sqlite::create($path);
    }

    public function testCreateRefusesAnExistingDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        Sqlite::create($this->temp->path());
    }

    public function testOpenOfAMissingPathIsUnavailable(): void
    {
        $path = $this->temp->path('missing.sqlite');
        try {
            Sqlite::open($path);
            self::fail('Opening a missing file must fail.');
        } catch (Unavailable $e) {
            self::assertSame('missing', $e->reason);
        }
        self::assertFileDoesNotExist($path, 'open() must never create a file.');
    }

    public function testMemoryDatabase(): void
    {
        $db = $this->track(Sqlite::memory());
        self::assertSame(':memory:', $db->path());
        self::assertSame('memory', $db->journalMode());
        self::assertSame(1, $db->value('PRAGMA foreign_keys'));
    }

    public function testQueryHelpers(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, amount NUMERIC)');
        self::assertSame(1, $db->run('INSERT INTO t (name, amount) VALUES (?, ?)', ['Example one', 3]));
        self::assertSame(1, $db->lastInsertId());
        $db->run('INSERT INTO t (name, amount) VALUES (:name, :amount)', ['name' => 'Example two', 'amount' => 2.5]);
        self::assertSame(2, $db->lastInsertId());
        $db->run('INSERT INTO t (name, amount) VALUES (?, ?)', [null, null]);

        self::assertSame(
            [['id' => 1, 'name' => 'Example one', 'amount' => 3], ['id' => 2, 'name' => 'Example two', 'amount' => 2.5]],
            $db->all('SELECT * FROM t WHERE name IS NOT NULL ORDER BY id'),
        );
        self::assertSame(['id' => 2, 'name' => 'Example two', 'amount' => 2.5], $db->one('SELECT * FROM t WHERE id = ?', [2]));
        self::assertNull($db->one('SELECT * FROM t WHERE id = ?', [99]));
        self::assertSame(3, $db->value('SELECT COUNT(*) FROM t'));
        self::assertNull($db->value('SELECT name FROM t WHERE id = ?', [99]));
        self::assertNull($db->value('SELECT name FROM t WHERE id = ?', [3]));
        self::assertSame([], $db->all('SELECT * FROM t WHERE id > ?', [10]));
        self::assertSame(2, $db->run('UPDATE t SET amount = 0 WHERE id IN (' . Database::placeholders(2) . ')', [1, 2]));
    }

    public function testFloatsAreStoredAsReal(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE t (id INTEGER PRIMARY KEY, r REAL, n NUMERIC)');
        foreach ([0.1 + 0.2, 2.5, -1.25e-300, 1.0e100, 123456789.125] as $value) {
            $db->run('INSERT INTO t (r, n) VALUES (?, ?)', [$value, $value]);
            $row = $db->one('SELECT typeof(r) AS tr, typeof(n) AS tn, r, n FROM t WHERE id = ?', [$db->lastInsertId()]);
            self::assertSame(['tr' => 'real', 'tn' => 'real', 'r' => $value, 'n' => $value], $row, "Value {$value}");
            self::assertSame(1, $db->value('SELECT COUNT(*) FROM t WHERE r = ?', [$value]), 'Comparison against a REAL column.');
        }
        // NUMERIC keeps a whole value as an integer, as SQLite does for REAL input.
        $db->run('INSERT INTO t (r, n) VALUES (?, ?)', [3.0, 3.0]);
        self::assertSame(['real', 'integer'], array_values($db->one('SELECT typeof(r), typeof(n) FROM t WHERE id = ?', [$db->lastInsertId()]) ?? []));
    }

    public function testFloatTextDoesNotDependOnLocaleOrPrecision(): void
    {
        $oldLocale = setlocale(LC_NUMERIC, '0');
        $set = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR', 'nl_NL.UTF-8');
        if ($set === false || (localeconv()['decimal_point'] ?? '.') !== ',') {
            setlocale(LC_NUMERIC, (string) $oldLocale);
            self::markTestSkipped('No locale with a comma as decimal point is installed, so locale independence cannot be shown here.');
        }
        $oldPrecision = ini_set('precision', '3');
        try {
            self::assertSame('0,5', sprintf('%.1f', 0.5), 'The comma locale is active.');
            $db = $this->track(Sqlite::memory());
            self::assertSame(0.30000000000000004, $db->value('SELECT CAST(? AS REAL)', [0.1 + 0.2]));
        } finally {
            ini_set('precision', (string) $oldPrecision);
            setlocale(LC_NUMERIC, (string) $oldLocale);
        }
    }

    public function testNonFiniteFloatsAreRefused(): void
    {
        $db = $this->track(Sqlite::memory());
        $this->expectException(InvalidArgumentException::class);
        $db->value('SELECT ?', [NAN]);
    }

    /** @return iterable<string, array{string}> */
    public static function transactionControl(): iterable
    {
        yield 'begin' => ['BEGIN'];
        yield 'begin immediate' => ['begin immediate'];
        yield 'commit' => ['COMMIT'];
        yield 'end' => ['END TRANSACTION'];
        yield 'rollback' => ['  ROLLBACK'];
        yield 'savepoint' => ['SAVEPOINT a'];
        yield 'release' => ['RELEASE a'];
        yield 'after a semicolon' => [';BEGIN'];
        yield 'after a block comment' => ['/* c */ BEGIN'];
        yield 'after a line comment' => ["-- c\nBEGIN"];
        yield 'after "nested" comments' => ['/* a /* b */ COMMIT'];
        yield 'after several comments' => ["  -- one\n/* two */;\n/*\nthree\n*/ROLLBACK"];
        yield 'after a comment, lower case' => ['/**/end'];
    }

    public function testCommentsThatHoldTheOnlyKeywordAreNotRefused(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE t (v TEXT)');
        // An unterminated comment runs to the end, and a line comment hides the rest of its line:
        // SQLite runs nothing, so no transaction may start.
        foreach (['/* c BEGIN', '-- BEGIN', 'SELECT 1 /* BEGIN */'] as $sql) {
            $db->value($sql);
        }
        $db->write(fn (Database $tx): int => $tx->run('INSERT INTO t (v) VALUES (?)', ['ok']));
        self::assertSame(1, $db->value('SELECT COUNT(*) FROM t'));
    }

    public function testCommentedCommitCannotEndAWriteEarly(): void
    {
        $path = $this->temp->path('early-commit.sqlite');
        $db = $this->track(Sqlite::create($path));
        $db->run('CREATE TABLE t (v TEXT)');

        foreach (['/* c */ COMMIT', "-- c\nCOMMIT", 'COMMIT'] as $commit) {
            try {
                $db->write(function (Database $tx) use ($commit): void {
                    $tx->run('INSERT INTO t (v) VALUES (?)', ['one']);
                    $tx->run($commit);
                    $tx->run('INSERT INTO t (v) VALUES (?)', ['two']);
                });
                self::fail('The commit inside write() must be refused.');
            } catch (LogicException) {
                self::assertFalse($db->inTransaction());
            }
        }

        $other = $this->track(Sqlite::open($path));
        self::assertSame(0, $other->value('SELECT COUNT(*) FROM t'), 'Every failed write is rolled back completely.');
    }

    #[DataProvider('transactionControl')]
    public function testTransactionControlIsRefused(string $sql): void
    {
        $db = $this->track(Sqlite::memory());
        try {
            $db->run($sql);
            self::fail('Transaction control must go through write().');
        } catch (LogicException) {
            self::assertFalse($db->inTransaction());
        }
        $this->expectException(LogicException::class);
        $db->value($sql);
    }

    public function testPlaceholders(): void
    {
        self::assertSame('?', Database::placeholders(1));
        self::assertSame('?, ?, ?', Database::placeholders(3));
        $this->expectException(InvalidArgumentException::class);
        Database::placeholders(0);
    }

    public function testClosedConnectionRefusesWork(): void
    {
        $db = Sqlite::memory();
        $db->close();
        $db->close();
        $this->expectException(LogicException::class);
        $db->value('SELECT 1');
    }

    public function testForeignKeysAreEnforced(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $db->run('CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent (id))');

        $this->expectException(\PDOException::class);
        $db->run('INSERT INTO child (parent_id) VALUES (?)', [42]);
    }

    // ------------------------------------------------------------ T-STO-2

    public function testWriteCommitsAndReturnsTheResult(): void
    {
        $path = $this->temp->path('write.sqlite');
        $db = $this->track(Sqlite::create($path));
        $db->run('CREATE TABLE t (v TEXT)');

        $result = $db->write(function (Database $tx) use ($db): string {
            self::assertSame($db, $tx);
            self::assertTrue($tx->inTransaction());
            $tx->run('INSERT INTO t (v) VALUES (?)', ['kept']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertFalse($db->inTransaction());
        $other = $this->track(Sqlite::open($path));
        self::assertSame(['kept'], array_column($other->all('SELECT v FROM t'), 'v'));
    }

    public function testWriteRollsBackOnAnExceptionAndRethrows(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE t (v TEXT)');
        $thrown = new RuntimeException('synthetic failure');

        try {
            $db->write(function (Database $tx) use ($thrown): void {
                $tx->run('INSERT INTO t (v) VALUES (?)', ['discarded']);

                throw $thrown;
            });
            self::fail('The exception must be rethrown.');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e);
        }

        self::assertFalse($db->inTransaction());
        self::assertSame(0, $db->value('SELECT COUNT(*) FROM t'));
        // The connection is usable again.
        $db->write(fn (Database $tx): int => $tx->run('INSERT INTO t (v) VALUES (?)', ['after']));
        self::assertSame(1, $db->value('SELECT COUNT(*) FROM t'));
    }

    public function testWriteRollsBackOnAnError(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE t (v TEXT)');

        try {
            $db->write(function (Database $tx): void {
                $tx->run('INSERT INTO t (v) VALUES (?)', ['discarded']);
                throw new \Error('synthetic error');
            });
            self::fail('The error must be rethrown.');
        } catch (\Error $e) {
            self::assertSame('synthetic error', $e->getMessage());
        }
        self::assertSame(0, $db->value('SELECT COUNT(*) FROM t'));
    }

    public function testNestedWriteThrowsLogicException(): void
    {
        $db = $this->track(Sqlite::memory());
        $db->run('CREATE TABLE t (v TEXT)');

        try {
            $db->write(function (Database $tx): void {
                $tx->run('INSERT INTO t (v) VALUES (?)', ['outer']);
                $tx->write(fn (Database $inner): int => $inner->run('INSERT INTO t (v) VALUES (?)', ['inner']));
            });
            self::fail('A nested write must be refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('nested', $e->getMessage());
        }
        self::assertFalse($db->inTransaction());
        self::assertSame(0, $db->value('SELECT COUNT(*) FROM t'), 'The outer write is rolled back too.');
    }

    // ------------------------------------------------------------ T-STO-3

    public function testSecondWriterIsBusy(): void
    {
        $path = $this->temp->path('busy.sqlite');
        $first = $this->track(Sqlite::create($path, 50));
        $first->run('CREATE TABLE t (v TEXT)');
        $second = $this->track(Sqlite::open($path, 50));

        $first->write(function (Database $tx) use ($second): void {
            $tx->run('INSERT INTO t (v) VALUES (?)', ['first']);
            try {
                $second->write(fn (Database $other): int => $other->run('INSERT INTO t (v) VALUES (?)', ['second']));
                self::fail('The second writer must be refused while the first holds BEGIN IMMEDIATE.');
            } catch (Busy $e) {
                self::assertInstanceOf(\PDOException::class, $e->getPrevious());
            }
            self::assertFalse($second->inTransaction());
        });

        self::assertSame(['first'], array_column($second->all('SELECT v FROM t'), 'v'));
        $second->write(fn (Database $other): int => $other->run('INSERT INTO t (v) VALUES (?)', ['second']));
        self::assertSame(2, $first->value('SELECT COUNT(*) FROM t'));
    }

    public function testOtherPdoExceptionsPropagateUnchanged(): void
    {
        $db = $this->track(Sqlite::memory());
        try {
            $db->run('SELECT * FROM no_such_table');
            self::fail('A bad statement must fail.');
        } catch (\PDOException $e) {
            self::assertNotInstanceOf(Busy::class, $e);
            self::assertSame(1, $e->errorInfo[1] ?? null);
        }
    }
}
