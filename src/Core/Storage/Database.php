<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

use TiffinDB\Core\Error\Busy;

/**
 * One SQLite connection (M1 spec section 4.1). Built by Sqlite::open,
 * Sqlite::create, and Sqlite::memory, which apply the connection settings.
 *
 * Every method turns a PDOException whose driver code is SQLITE_BUSY (5)
 * or SQLITE_LOCKED (6) into Busy; other PDOExceptions propagate unchanged.
 */
final class Database
{
    private const SQLITE_BUSY = 5;
    private const SQLITE_LOCKED = 6;

    private ?\PDO $pdo;
    private bool $inWrite = false;

    public function __construct(\PDO $pdo, private readonly string $path, private readonly string $journalMode)
    {
        $this->pdo = $pdo;
    }

    /**
     * Runs one statement and returns the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    public function run(string $sql, array $params = []): int
    {
        return $this->guard(fn (): int => $this->execute($sql, $params)->rowCount());
    }

    /**
     * Every row, as associative arrays.
     *
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->guard(function () use ($sql, $params): array {
            $rows = $this->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

            return array_values($rows);
        });
    }

    /**
     * The first row, or null when there is none.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        return $this->guard(function () use ($sql, $params): ?array {
            $statement = $this->execute($sql, $params);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $statement->closeCursor();

            return is_array($row) ? $row : null;
        });
    }

    /**
     * The first column of the first row, or null when there is no row.
     *
     * @param array<int|string, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        return $this->guard(function () use ($sql, $params): mixed {
            $statement = $this->execute($sql, $params);
            $row = $statement->fetch(\PDO::FETCH_NUM);
            $statement->closeCursor();

            return is_array($row) ? $row[0] : null;
        });
    }

    public function lastInsertId(): int
    {
        return $this->guard(fn (): int => (int) $this->pdo()->lastInsertId());
    }

    /**
     * Runs $work inside BEGIN IMMEDIATE ... COMMIT and returns its result.
     * Any Throwable rolls back and is rethrown. Must not be nested.
     *
     * @template T
     * @param callable(Database): T $work
     * @return T
     */
    public function write(callable $work): mixed
    {
        if ($this->inTransaction()) {
            throw new \LogicException('Database::write() must not be nested.');
        }
        $this->guard(fn (): int|false => $this->pdo()->exec('BEGIN IMMEDIATE'));
        $this->inWrite = true;
        try {
            $result = $work($this);
            $this->guard(fn (): int|false => $this->pdo()->exec('COMMIT'));
            $this->inWrite = false;

            return $result;
        } catch (\Throwable $error) {
            $this->rollBack();

            throw $error;
        }
    }

    public function inTransaction(): bool
    {
        return $this->inWrite || $this->pdo()->inTransaction();
    }

    /** 'wal', or the journal mode SQLite reported when WAL was not available. */
    public function journalMode(): string
    {
        return $this->journalMode;
    }

    /** The file path, or ':memory:' for a memory database. */
    public function path(): string
    {
        return $this->path;
    }

    /** '?, ?, ?' for an IN (...) list; $count must be 1 or more. */
    public static function placeholders(int $count): string
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('At least one placeholder is needed.');
        }

        return implode(', ', array_fill(0, $count, '?'));
    }

    /** Closes the connection. Any later call other than close() throws \LogicException. */
    public function close(): void
    {
        if ($this->pdo !== null && $this->inTransaction()) {
            $this->rollBack();
        }
        $this->pdo = null;
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \LogicException('The database connection is closed.');
        }

        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params */
    private function execute(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(is_int($name) ? $name + 1 : $name, $value, self::type($value));
        }
        $statement->execute();

        return $statement;
    }

    private static function type(mixed $value): int
    {
        return match (true) {
            $value === null => \PDO::PARAM_NULL,
            is_int($value) => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            default => \PDO::PARAM_STR,
        };
    }

    private function rollBack(): void
    {
        $this->inWrite = false;
        try {
            $this->pdo()->exec('ROLLBACK');
        } catch (\PDOException) {
            // SQLite may already have rolled back (for example after a busy COMMIT); nothing is left to undo.
        }
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (\PDOException $error) {
            $code = $error->errorInfo[1] ?? null;
            if ($code === self::SQLITE_BUSY || $code === self::SQLITE_LOCKED) {
                throw new Busy('SQLite was busy or locked.', 0, $error);
            }

            throw $error;
        }
    }
}
